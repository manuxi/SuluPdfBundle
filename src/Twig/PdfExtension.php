<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Twig;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PdfExtension extends AbstractExtension
{
    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    public function getFunctions(): array
    {
        return [
            // {{ sulu_pdf_url('articles', uuid, app.request.locale) }}
            new TwigFunction('sulu_pdf_url', $this->url(...)),
        ];
    }

    public function url(string $resourceKey, string $id, ?string $locale = null): string
    {
        return $this->urlGenerator->generate(
            'sulu_pdf_download',
            ['resourceKey' => $resourceKey, 'id' => $id] + ($locale ? ['locale' => $locale] : []),
        );
    }
}
