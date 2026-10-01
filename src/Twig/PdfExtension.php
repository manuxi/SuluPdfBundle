<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Twig;

use Manuxi\SuluPdfBundle\Profile\ProfileRegistry;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PdfExtension extends AbstractExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ProfileRegistry $profiles,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            // {{ sulu_pdf_url('pages', uuid, app.request.locale) }}
            new TwigFunction('sulu_pdf_url', $this->url(...)),
            // {% if sulu_pdf_available('pages', uuid, app.request.locale) %} ... {% endif %}
            new TwigFunction('sulu_pdf_available', $this->available(...)),
        ];
    }

    public function url(string $resourceKey, string $id, ?string $locale = null): string
    {
        return $this->urlGenerator->generate(
            'sulu_pdf_download',
            ['resourceKey' => $resourceKey, 'id' => $id] + ($locale ? ['locale' => $locale] : []),
        );
    }

    /** whether the profile of the resource key offers a PDF for this resource (the download would not be a 404) */
    public function available(string $resourceKey, string $id, string $locale): bool
    {
        return null !== $this->profiles->get($resourceKey)?->getOptions($id, $locale);
    }
}
