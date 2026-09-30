<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Model;

/**
 * Where the parts of a rendered page are found. Every value is a CSS selector; the ones for single parts are looked up
 * inside "root" (first match wins), "remove" and "exclude_figures" apply to the whole page.
 *
 * A profile brings default rules for its theme, the project can override single keys in config/packages/sulu_pdf.yaml.
 */
final class PdfRules
{
    /** removed from every page, whatever the profile: scripts, controls, icons and carousel chrome */
    public const BUILT_IN_REMOVE = [
        'script', 'style', 'noscript', 'svg', 'button', 'form', 'iframe', 'video', 'audio', 'nav', 'footer',
        'i[class^="fa"]',
        '.d-none', '.d-md-none', '.visually-hidden',
        '.swiper-pagination', '.swiper-button-next', '.swiper-button-prev',
    ];

    /**
     * @param list<string> $remove
     * @param list<string> $excludeFigures figures matching one of these keep their markup (e.g. quotes with an avatar)
     */
    public function __construct(
        public readonly string $root = 'body',
        public readonly string $title = 'h1',
        public readonly ?string $overline = null,
        public readonly ?string $subtitle = null,
        public readonly ?string $badges = null,
        public readonly ?string $meta = null,
        public readonly ?string $lead = null,
        public readonly ?string $hero = null,
        public readonly string $main = 'main',
        public readonly ?string $gallery = null,
        public readonly ?string $author = null,
        public readonly array $remove = [],
        public readonly array $excludeFigures = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data see with()
     */
    public static function fromArray(array $data): self
    {
        return (new self())->with($data);
    }

    /**
     * @param array<string, mixed> $data keys as the constructor arguments, snake_case; null/missing = keep this rule's value
     */
    public function with(array $data): self
    {
        $merged = $this->toArray();
        foreach ($data as $key => $value) {
            $key = \lcfirst(\str_replace('_', '', \ucwords((string) $key, '_')));
            if (null === $value || !\array_key_exists($key, $merged)) {
                continue;
            }
            // list rules add to the defaults instead of replacing them
            $merged[$key] = \is_array($value) ? \array_values(\array_unique([...$merged[$key], ...$value])) : $value;
        }

        return new self(...$merged);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'root' => $this->root,
            'title' => $this->title,
            'overline' => $this->overline,
            'subtitle' => $this->subtitle,
            'badges' => $this->badges,
            'meta' => $this->meta,
            'lead' => $this->lead,
            'hero' => $this->hero,
            'main' => $this->main,
            'gallery' => $this->gallery,
            'author' => $this->author,
            'remove' => $this->remove,
            'excludeFigures' => $this->excludeFigures,
        ];
    }
}
