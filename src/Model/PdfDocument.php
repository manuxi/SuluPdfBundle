<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Model;

/**
 * The content of a page, reduced to what the PDF layout (Resources/views/document.html.twig) prints.
 */
final class PdfDocument
{
    /**
     * @param list<string> $badges
     * @param list<string> $meta
     * @param string       $body   cleaned HTML of the main content
     */
    public function __construct(
        public readonly string $title,
        public readonly string $overline = '',
        public readonly string $subtitle = '',
        public readonly array $badges = [],
        public readonly array $meta = [],
        public readonly string $lead = '',
        public readonly ?string $heroSrc = null,
        public readonly string $heroCaption = '',
        public readonly string $body = '',
        public readonly ?float $heroWidth = null,
        public readonly ?float $heroHeight = null,
    ) {
    }

    /** the hero image fitted into a box (points), set by the renderer once it knows the image's size */
    public function withHeroSize(?float $width, ?float $height): self
    {
        return new self(
            $this->title, $this->overline, $this->subtitle, $this->badges, $this->meta, $this->lead,
            $this->heroSrc, $this->heroCaption, $this->body, $width, $height,
        );
    }
}
