<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Service;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Manuxi\SuluPdfBundle\Model\PdfDocument;
use Manuxi\SuluPdfBundle\Model\PdfOptions;
use Manuxi\SuluPdfBundle\Model\PdfResult;
use Manuxi\SuluPdfBundle\Profile\PdfMetaProviderInterface;
use Manuxi\SuluPdfBundle\Profile\ProfileRegistry;
use Sulu\Route\Domain\Repository\RouteRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Renders the public page of a piece of content through a sub-request (reusing every template as-is), reduces it to the
 * content (ContentExtractor), sets that into the layout @SuluPdf/document.html.twig and converts it with dompdf.
 */
final class PdfRenderer
{
    /** width of the text column of an A4 page with 18 mm margins, in points */
    private const TEXT_WIDTH = 493;

    /** @var array<string, string> weight/style => config key of the font file */
    private const FONT_STYLES = [
        'normal normal' => 'regular',
        'normal italic' => 'italic',
        'bold normal' => 'bold',
        'bold italic' => 'bold_italic',
    ];

    /**
     * @param array<string, mixed> $config the "sulu_pdf" config: paper, logo, logo_width, site_name, colors, font
     */
    public function __construct(
        private readonly HttpKernelInterface $httpKernel,
        private readonly ContentExtractor $extractor,
        private readonly ProfileRegistry $profiles,
        private readonly RouteRepositoryInterface $routes,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly CompanyDataProviderInterface $companyData,
        private readonly array $config,
        private readonly string $projectDir,
        private readonly string $cacheDir,
    ) {
    }

    /**
     * @throws NotFoundHttpException if there is no profile, no PDF for this resource, or no public page
     */
    public function render(string $resourceKey, string $id, Request $request): PdfResult
    {
        $locale = (string) $request->query->get('locale', $request->getLocale());

        $profile = $this->profiles->get($resourceKey) ?? throw new NotFoundHttpException();
        $options = $profile->getOptions($id, $locale) ?? throw new NotFoundHttpException();

        $slug = null;
        foreach ($this->routes->findBy(['resourceKey' => $resourceKey, 'resourceId' => $id, 'locale' => $locale]) as $route) {
            if (!$route->isHistory()) {
                $slug = $route->getSlug();
                break;
            }
        }
        if (!$slug) {
            throw new NotFoundHttpException();
        }

        $origin = $request->getSchemeAndHttpHost();
        $pageUrl = $origin . $slug;

        // a bare path (no host) makes Request::create() default to "localhost", which matches no configured
        // webspace domain and 404s inside the sub-request - build the same absolute URL the browser would use
        $subRequest = Request::create($pageUrl, 'GET', [], $request->cookies->all(), [], $request->server->all());
        $subRequest->setLocale($locale);
        // page layouts often read the flash bag off the session - a bare Request::create() has none
        if ($request->hasSession()) {
            $subRequest->setSession($request->getSession());
        }
        $response = $this->httpKernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
        if (!$response->isSuccessful()) {
            throw new NotFoundHttpException();
        }

        $document = $this->extractor->extract((string) $response->getContent(), $origin, $this->profiles->rules($profile), $options);
        $isLocal = $this->isLocalHost($request->getHost());
        [$heroWidth, $heroHeight] = $this->heroSize($document->heroSrc, $isLocal);
        $document = $document->withHeroSize($heroWidth, $heroHeight);
        if ($profile instanceof PdfMetaProviderInterface) {
            $document = $document->withMeta([...$profile->getMeta($id, $locale), ...$document->meta]);
        }

        $dateFormat = new \IntlDateFormatter($locale, \IntlDateFormatter::LONG, \IntlDateFormatter::NONE);
        $printed = $dateFormat->format(new \DateTimeImmutable());
        $modified = $options->showModified ? $profile->getModified($id, $locale) : null;

        $company = PdfOptions::COMPANY_NONE !== $options->companyData ? $this->companyData->getCompanyData($locale) : null;

        $html = $this->twig->render('@SuluPdf/document.html.twig', [
            'doc' => $document,
            'style' => $this->style(),
            'url' => $pageUrl,
            'printed' => $printed,
            'modified' => $modified ? $dateFormat->format($modified) : null,
            'showOnlineLink' => $options->showOnlineLink,
            'qrCode' => $options->showOnlineLink ? $this->qrCode($pageUrl) : null,
            'company' => PdfOptions::COMPANY_END === $options->companyData ? $company : null,
            'locale' => $locale,
        ]);

        $dompdf = $this->createDompdf($isLocal);
        $dompdf->addInfo('Title', $document->title);
        $dompdf->addInfo('Creator', $request->getHost());
        $dompdf->loadHtml($html);
        $dompdf->setPaper((string) ($this->config['paper'] ?? 'A4'));
        $dompdf->render();

        $this->drawPageFrame(
            $dompdf,
            $document,
            $locale,
            $options->showOnlineLink ? $pageUrl . '  ·  ' . $printed : $printed,
            PdfOptions::COMPANY_FOOTER === $options->companyData && $company ? $this->companyLine($company) : null,
        );

        $filename = (new AsciiSlugger($locale))->slug($document->title)->lower()->toString() ?: $id;

        return new PdfResult($dompdf->output(), $filename . '.pdf');
    }

    /**
     * Logo (or the site name) and rule at the top and URL/date + "Page 2 / 5" at the bottom of EVERY page, drawn on the
     * canvas after layout: only then the page count is known, and both footer texts share one baseline.
     */
    private function drawPageFrame(Dompdf $dompdf, PdfDocument $document, string $locale, string $footerLeft, ?string $footerCompany): void
    {
        $pageLabel = $this->translator->trans('pdf.page', [], 'sulu_pdf', $locale);
        $logo = $this->logoPath($locale);
        $logoWidth = (float) ($this->config['logo_width'] ?? 50);
        $siteName = (string) ($this->config['site_name'] ?? '');
        $primary = $this->rgb((string) $this->config['colors']['primary']);

        $dompdf->getCanvas()->page_script(function (int $page, int $pages, $canvas, $fontMetrics) use ($logo, $logoWidth, $siteName, $primary, $pageLabel, $footerLeft, $footerCompany): void {
            $mm = 72 / 25.4;
            $left = 18 * $mm;
            $right = $canvas->get_width() - 18 * $mm;
            $font = $fontMetrics->getFont((string) ($this->config['font']['family'] ?? 'DejaVu Sans'));
            $bold = $fontMetrics->getFont((string) ($this->config['font']['family'] ?? 'DejaVu Sans'), 'bold');
            $grey = [0.53, 0.53, 0.53];

            if ($logo) {
                [$w, $h] = \getimagesize($logo) ?: [0, 0];
                if ($w > 0) {
                    $canvas->image($logo, $left, 10 * $mm, $logoWidth * $mm, $logoWidth * $mm * $h / $w);
                }
            } elseif ('' !== $siteName) {
                $canvas->text($left, 18 * $mm, $siteName, $bold, 14, $primary);
            }
            // filled rectangles instead of line(): the first page kept the default (black) stroke color with line()
            $canvas->filled_rectangle($left, 22 * $mm - 0.75, $right - $left, 1.5, $primary);

            $baseline = $canvas->get_height() - ($footerCompany ? 15 : 12) * $mm;
            $canvas->filled_rectangle($left, $baseline - 12.4, $right - $left, 0.75, [0.85, 0.85, 0.85]);
            $canvas->text($left, $baseline, $footerLeft, $font, 7.5, $grey);
            $counter = $pageLabel . ' ' . $page . ' / ' . $pages;
            $canvas->text($right - $fontMetrics->getTextWidth($counter, $font, 7.5), $baseline, $counter, $font, 7.5, $grey);

            if ($footerCompany) {
                $canvas->text($left, $baseline + 10, $footerCompany, $font, 7, $grey);
            }
        });
    }

    /**
     * @return array<string, string> colors for the layout: the brand color, a darker shade for links and a light tint for
     *                               boxes are derived from it
     */
    private function style(): array
    {
        $colors = (array) $this->config['colors'];
        $primary = (string) $colors['primary'];

        return [
            'font' => (string) ($this->config['font']['family'] ?? 'DejaVu Sans'),
            'primary' => $primary,
            'primaryDark' => $this->mix($primary, '#000000', 0.25),
            'primaryTint' => $this->mix($primary, '#ffffff', 0.88),
            'text' => (string) $colors['text'],
            'muted' => (string) $colors['muted'],
        ];
    }

    private function createDompdf(bool $isLocal): Dompdf
    {
        $cacheDir = $this->cacheDir . '/sulu_pdf';
        if (!\is_dir($cacheDir)) {
            @\mkdir($cacheDir, 0775, true);
        }

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('chroot', [$this->projectDir]);
        $options->set('fontDir', $cacheDir);
        $options->set('fontCache', $cacheDir);
        $options->set('defaultFont', (string) ($this->config['font']['family'] ?? 'DejaVu Sans'));
        // the images are fetched from this very site - on a local dev host its certificate is self-signed, which
        // would make every image silently fail to load
        $options->setHttpContext(['ssl' => ['verify_peer' => !$isLocal, 'verify_peer_name' => false]]);

        $dompdf = new Dompdf($options);

        $font = (array) ($this->config['font'] ?? []);
        if (!empty($font['regular'])) {
            foreach (self::FONT_STYLES as $style => $key) {
                if (empty($font[$key])) {
                    continue;
                }
                [$weight, $fontStyle] = \explode(' ', $style);
                $dompdf->getFontMetrics()->registerFont(
                    ['family' => (string) $font['family'], 'weight' => $weight, 'style' => $fontStyle],
                    $this->absolute((string) $font[$key]),
                );
            }
        }

        return $dompdf;
    }

    /**
     * The hero image is fitted into a box (full text width, at most 250pt tall) by hand: dompdf can't cap the height of
     * an image whose width is set, and a portrait photo would otherwise fill a whole page.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function heroSize(?string $src, bool $isLocal): array
    {
        if (!$src) {
            return [null, null];
        }

        $context = \stream_context_create(['ssl' => ['verify_peer' => !$isLocal, 'verify_peer_name' => false], 'http' => ['timeout' => 5]]);
        $head = @\file_get_contents($src, false, $context, 0, 262144);
        $size = $head ? @\getimagesizefromstring($head) : false;
        if (!$size || $size[0] < 1 || $size[1] < 1) {
            return [null, null];
        }

        $scale = \min(self::TEXT_WIDTH / $size[0], 250 / $size[1]);

        return [\round($size[0] * $scale, 1), \round($size[1] * $scale, 1)];
    }

    /** QR code to the online version, as an SVG data URI (dompdf renders SVG images natively, no GD/imagick needed) */
    private function qrCode(string $url): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(240, 0), new SvgImageBackEnd()));

        return 'data:image/svg+xml;base64,' . \base64_encode($writer->writeString($url));
    }

    /** one line for the page footer: "Name · Street 1 · 12345 City · phone · mail" */
    private function companyLine(array $company): string
    {
        return \implode('  ·  ', \array_filter([
            $company['name'] ?? '',
            $company['street'] ?? '',
            \trim(($company['zip'] ?? '') . ' ' . ($company['city'] ?? '')),
            $company['phone'] ?? '',
            $company['email'] ?? '',
        ]));
    }

    /** "{locale}" in the configured path is replaced (logo.de.png / logo.en.png) */
    private function logoPath(string $locale): ?string
    {
        $logo = $this->config['logo'] ?? null;
        if (!$logo) {
            return null;
        }

        $path = $this->absolute(str_replace('{locale}', $locale, (string) $logo));

        return \is_file($path) ? $path : null;
    }

    private function absolute(string $path): string
    {
        return \preg_match('~^([A-Za-z]:[\\\\/]|/)~', $path) ? $path : $this->projectDir . '/' . \ltrim($path, '/\\');
    }

    private function isLocalHost(string $host): bool
    {
        return \in_array($host, ['localhost', '127.0.0.1'], true)
            || \str_starts_with($host, 'local.')
            || \preg_match('/\.(localhost|test|local)$/', $host);
    }

    /**
     * @return array{0: float, 1: float, 2: float} 0..1 per channel, as dompdf's canvas wants it
     */
    private function rgb(string $hex): array
    {
        $hex = \ltrim($hex, '#');
        if (3 === \strlen($hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return [\hexdec(\substr($hex, 0, 2)) / 255, \hexdec(\substr($hex, 2, 2)) / 255, \hexdec(\substr($hex, 4, 2)) / 255];
    }

    /** $weight of the second color mixed into the first */
    private function mix(string $from, string $to, float $weight): string
    {
        $a = $this->rgb($from);
        $b = $this->rgb($to);
        $out = '#';
        foreach ([0, 1, 2] as $i) {
            $out .= \str_pad(\dechex((int) \round(($a[$i] * (1 - $weight) + $b[$i] * $weight) * 255)), 2, '0', \STR_PAD_LEFT);
        }

        return $out;
    }
}
