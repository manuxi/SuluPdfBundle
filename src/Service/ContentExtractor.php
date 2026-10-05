<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Service;

use Manuxi\SuluPdfBundle\Model\PdfDocument;
use Manuxi\SuluPdfBundle\Model\PdfOptions;
use Manuxi\SuluPdfBundle\Model\PdfRules;
use Symfony\Component\CssSelector\CssSelectorConverter;

/**
 * Reduces the rendered public page of a piece of content to what the PDF prints: header data, hero image, lead and
 * the main content - without scripts, icons, sliders and inline styles (aspect-ratio boxes wreck dompdf's layout),
 * with responsive and lazy-loaded images resolved to one real URL, and figures rebuilt as tables (see figuresToTables()).
 */
final class ContentExtractor
{
    /** widest step of a responsive image taken into the PDF (px): sharp on paper without blowing up the file */
    private const MAX_IMAGE_WIDTH = 1280;

    private readonly CssSelectorConverter $css;

    public function __construct()
    {
        $this->css = new CssSelectorConverter();
    }

    /**
     * @param string $origin scheme and host of the site ("https://example.org"), to make root-relative image URLs absolute
     */
    public function extract(string $html, string $origin, PdfRules $rules, PdfOptions $options): PdfDocument
    {
        $dom = new \DOMDocument();
        \libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        \libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $remove = [...PdfRules::BUILT_IN_REMOVE, ...$rules->remove];
        if (!$options->showAuthor && $rules->author) {
            $remove[] = $rules->author;
        }
        foreach ($remove as $selector) {
            foreach ($this->all($xpath, $selector) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $this->resolveImages($xpath, $origin);

        // lightbox links around images: nothing to click in a PDF, and dompdf underlines the linked image's edge
        foreach ($xpath->query('//a[@*[starts-with(name(), "data-fancybox")]]') as $link) {
            while ($link->firstChild) {
                $link->parentNode->insertBefore($link->firstChild, $link);
            }
            $link->parentNode->removeChild($link);
        }

        $this->figuresToTables($dom, $xpath, $rules, $options->showCaptions);

        $root = $this->first($xpath, $rules->root) ?? $dom->documentElement;

        // the title is printed by the layout - a copy inside the main content would appear twice
        $titleNode = $this->first($xpath, $rules->title, $root);
        $title = $titleNode ? $this->plain($titleNode->textContent) : '';

        $hero = $rules->hero ? $this->first($xpath, $rules->hero, $root) : null;
        $heroImage = $hero instanceof \DOMElement && 'img' === $hero->tagName ? $hero : ($hero ? $this->first($xpath, 'img', $hero) : null);
        $heroSrc = $heroImage instanceof \DOMElement ? ($heroImage->getAttribute('src') ?: null) : null;
        $heroCaption = $heroImage instanceof \DOMElement && $options->showCaptions ? \trim($heroImage->getAttribute('alt')) : '';

        $leadNode = $rules->lead ? $this->first($xpath, $rules->lead, $root) : null;
        $lead = $leadNode ? \trim($leadNode->textContent) : '';
        $leadNode?->parentNode?->removeChild($leadNode);

        // a theme without the main selector falls back to <main>, then to the whole body
        $main = $this->first($xpath, $rules->main, $root) ?? $this->first($xpath, 'main') ?? $this->first($xpath, 'body');

        // header parts are read first; those that sit inside the main content are taken out of it (the layout prints them)
        $overline = $rules->overline ? $this->text($xpath, $rules->overline, $root) : '';
        $subtitle = $rules->subtitle ? $this->text($xpath, $rules->subtitle, $root) : '';
        $badges = $rules->badges ? $this->texts($xpath, $rules->badges, $root) : [];
        $meta = $rules->meta ? $this->texts($xpath, $rules->meta, $root) : [];

        if ($main) {
            $headerNodes = $titleNode ? [$titleNode] : [];
            foreach ([$rules->overline, $rules->subtitle, $rules->badges, $rules->meta] as $selector) {
                if ($selector) {
                    \array_push($headerNodes, ...$this->all($xpath, $selector, $root));
                }
            }
            foreach ($headerNodes as $node) {
                if ($node->parentNode && $this->contains($main, $node)) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        $body = '';
        if ($main) {
            // the hero image is printed once, at the top
            if ($rules->hero) {
                foreach ($this->all($xpath, $rules->hero, $main) as $node) {
                    $node->parentNode?->removeChild($node);
                }
            }

            // presentational leftovers: inline styles and sizing attributes
            foreach ($xpath->query('.//*', $main) as $node) {
                foreach (['style', 'width', 'height', 'loading', 'sizes', 'srcset'] as $attribute) {
                    $node->removeAttribute($attribute);
                }
            }
            foreach ($main->childNodes as $child) {
                $body .= $dom->saveHTML($child);
            }
        }

        return new PdfDocument(
            title: $title ?: $this->text($xpath, 'title'),
            overline: $overline,
            subtitle: $subtitle,
            badges: $badges,
            meta: $meta,
            lead: $lead,
            heroSrc: $heroSrc,
            heroCaption: $heroCaption,
            body: (string) \preg_replace('/^<\?xml[^>]*>/', '', $body),
        );
    }

    /**
     * dompdf mis-measures block images inside <figure> (following content is drawn over them, margins and captions
     * get lost); a one-column table is the one layout it always gets right, so every figure becomes one. Galleries
     * become a two-column table.
     */
    private function figuresToTables(\DOMDocument $dom, \DOMXPath $xpath, PdfRules $rules, bool $showCaptions): void
    {
        $excluded = [];
        foreach ($rules->excludeFigures as $selector) {
            foreach ($this->all($xpath, $selector) as $node) {
                $excluded[\spl_object_id($node)] = true;
            }
        }

        if ($rules->gallery) {
            foreach ($this->all($xpath, $rules->gallery) as $container) {
                $cells = [];
                foreach ($xpath->query('.//figure[.//img]', $container) as $figure) {
                    $cells[] = [
                        $xpath->query('.//img', $figure)->item(0),
                        $this->plain($xpath->query('.//figcaption', $figure)->item(0)?->textContent ?? ''),
                    ];
                }
                if ([] === $cells) {
                    continue;
                }

                $table = $dom->createElement('table');
                $table->setAttribute('class', 'gallery');
                foreach (\array_chunk($cells, 2) as $pair) {
                    $row = $dom->createElement('tr');
                    foreach ($pair as [$img, $captionText]) {
                        $cell = $dom->createElement('td');
                        $cell->setAttribute('class', 'gallery-cell');
                        $cell->appendChild($img->cloneNode(true));
                        if ($showCaptions && '' !== $captionText) {
                            $caption = $dom->createElement('div', \htmlspecialchars($captionText));
                            $caption->setAttribute('class', 'gallery-caption');
                            $cell->appendChild($caption);
                        }
                        $row->appendChild($cell);
                    }
                    // an odd last picture keeps its half width instead of stretching over the row
                    if (1 === \count($pair)) {
                        $row->appendChild($dom->createElement('td'));
                    }
                    $table->appendChild($row);
                }

                $container->parentNode->replaceChild($table, $container);
            }
        }

        foreach (\iterator_to_array($xpath->query('//figure[.//img]')) as $figure) {
            if (isset($excluded[\spl_object_id($figure)]) || !$figure->parentNode) {
                continue;
            }

            $img = $xpath->query('.//img', $figure)->item(0);
            $captionText = $this->plain($xpath->query('.//figcaption', $figure)->item(0)?->textContent ?? '');

            $table = $dom->createElement('table');
            $table->setAttribute('class', 'fig');
            $cell = $dom->createElement('td');
            $cell->setAttribute('class', 'fig-img');
            $cell->appendChild($img->cloneNode(true));
            $row = $dom->createElement('tr');
            $row->appendChild($cell);
            $table->appendChild($row);

            if ($showCaptions && '' !== $captionText) {
                $captionCell = $dom->createElement('td', \htmlspecialchars($captionText));
                $captionCell->setAttribute('class', 'fig-caption');
                $captionRow = $dom->createElement('tr');
                $captionRow->appendChild($captionCell);
                $table->appendChild($captionRow);
            }

            $figure->parentNode->replaceChild($table, $figure);
        }

        // a gallery rendered as <ul><li><figure> would print a bullet beside every picture
        foreach ($xpath->query('//li[.//table[@class="fig"]]') as $item) {
            $item->setAttribute('class', 'nolist');
            $item->parentNode?->setAttribute('class', 'nolist');
        }
    }

    /**
     * The file of every image for dompdf, which runs no JavaScript and picks nothing:
     * - responsive images carry their steps in srcset (the <source> elements of a <picture>, for other types or screens,
     *   are dropped: the <img> inside carries everything dompdf needs),
     * - lazy images of older templates carry a placeholder in src and the real file in data-original / data-srcset.
     * Of the steps the largest up to MAX_IMAGE_WIDTH is taken.
     */
    private function resolveImages(\DOMXPath $xpath, string $origin): void
    {
        // libxml parses HTML 4 and does not know <source> as an empty element: it nests the <img> after it inside, so
        // that is moved out before the <source> goes
        foreach (\iterator_to_array($xpath->query('//picture//source'), false) as $source) {
            while ($source->firstChild) {
                $source->parentNode?->insertBefore($source->firstChild, $source);
            }
            $source->parentNode?->removeChild($source);
        }

        /** @var \DOMElement $img */
        foreach ($xpath->query('//img') as $img) {
            $real = $this->pickCandidate($img->getAttribute('data-srcset'))
                ?? $this->pickCandidate($img->getAttribute('srcset'));
            $real ??= $img->getAttribute('data-original') ?: $img->getAttribute('data-src') ?: $img->getAttribute('src');

            if (!$real) {
                continue;
            }

            // the image format is picked by the URL's extension, and dompdf reads neither WebP nor AVIF
            $real = (string) \preg_replace('/\.(webp|avif)(\?|$)/i', '.jpg$2', $real);

            // dompdf has no page origin to resolve root-relative paths against
            if (\str_starts_with($real, '/') && !\str_starts_with($real, '//')) {
                $real = \rtrim($origin, '/') . $real;
            }

            $img->setAttribute('src', $real);
            foreach (['data-original', 'data-srcset', 'data-sizes', 'data-src', 'srcset', 'sizes'] as $attribute) {
                $img->removeAttribute($attribute);
            }
        }
    }

    /**
     * The largest candidate of a srcset ("a.webp 640w, b.webp 1280w") up to MAX_IMAGE_WIDTH; if every one is wider, the
     * narrowest. Null without candidates.
     */
    private function pickCandidate(string $srcset): ?string
    {
        $fitting = null;
        $fittingWidth = 0;
        $narrowest = null;
        $narrowestWidth = \PHP_INT_MAX;
        foreach (\explode(',', $srcset) as $candidate) {
            if (!\preg_match('/^\s*(\S+)\s+(\d+)w\s*$/', $candidate, $c)) {
                continue;
            }
            $width = (int) $c[2];
            if ($width <= self::MAX_IMAGE_WIDTH && $width > $fittingWidth) {
                $fitting = $c[1];
                $fittingWidth = $width;
            }
            if ($width < $narrowestWidth) {
                $narrowest = $c[1];
                $narrowestWidth = $width;
            }
        }

        return $fitting ?? $narrowest;
    }

    /**
     * @return list<\DOMNode>
     */
    private function all(\DOMXPath $xpath, string $selector, ?\DOMNode $context = null): array
    {
        $nodes = $xpath->query($this->css->toXPath($selector, $context ? './/' : '//'), $context);

        return $nodes ? \iterator_to_array($nodes, false) : [];
    }

    private function first(\DOMXPath $xpath, string $selector, ?\DOMNode $context = null): ?\DOMNode
    {
        return $this->all($xpath, $selector, $context)[0] ?? null;
    }

    private function text(\DOMXPath $xpath, string $selector, ?\DOMNode $context = null): string
    {
        $node = $this->first($xpath, $selector, $context);

        return $node ? $this->plain($node->textContent) : '';
    }

    /**
     * @return list<string>
     */
    private function texts(\DOMXPath $xpath, string $selector, ?\DOMNode $context = null): array
    {
        $out = [];
        foreach ($this->all($xpath, $selector, $context) as $node) {
            $value = $this->plain($node->textContent);
            if ('' !== $value) {
                $out[] = $value;
            }
        }

        return $out;
    }

    private function contains(\DOMNode $ancestor, \DOMNode $node): bool
    {
        for ($parent = $node->parentNode; $parent; $parent = $parent->parentNode) {
            if ($parent->isSameNode($ancestor)) {
                return true;
            }
        }

        return false;
    }

    private function plain(string $text): string
    {
        return \trim((string) \preg_replace('/\s+/u', ' ', $text));
    }
}
