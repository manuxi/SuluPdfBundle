<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Tests\Service;

use Manuxi\SuluPdfBundle\Model\PdfDocument;
use Manuxi\SuluPdfBundle\Model\PdfOptions;
use Manuxi\SuluPdfBundle\Model\PdfRules;
use Manuxi\SuluPdfBundle\Service\ContentExtractor;
use PHPUnit\Framework\TestCase;

class ContentExtractorTest extends TestCase
{
    private const HTML = <<<'HTML'
        <!doctype html>
        <html><head><title>Fallback title</title><style>.x{}</style><script>alert(1)</script></head>
        <body>
        <nav>Menu</nav>
        <article class="post">
            <header>
                <span class="overline">Guide</span>
                <h1>The <em>real</em> title</h1>
                <h2 class="sub">A subtitle</h2>
                <span class="badge">New</span><span class="badge">Featured</span>
                <span class="meta-item">Jane Doe</span><span class="meta-item">01.02.2026</span>
            </header>
            <div class="hero"><img class="lazy" src="/placeholder.png" data-original="/uploads/a/640x/hero.webp?v=1" data-srcset="/uploads/a/640x/hero.webp?v=1 640w, /uploads/a/1280x/hero.webp?v=1 1280w" alt="Hero caption" style="aspect-ratio: 3 / 2"></div>
            <div class="post-main">
                <p class="lead">Lead paragraph</p>
                <div class="hero"><img src="/hero-again.png"></div>
                <h2>Chapter</h2>
                <p style="color:red">Text <a href="https://example.org">link</a></p>
                <figure class="pic"><a data-fancybox="g" href="/big.jpg"><img class="lazy" src="/p.png" data-original="/uploads/b/360x/one.webp?v=2" alt="One"><figcaption>Caption one</figcaption></a></figure>
                <figure class="quote"><img src="/avatar.png"><blockquote>Kept as figure</blockquote></figure>
                <div class="grid">
                    <figure><img src="/g1.jpg"><figcaption>G1</figcaption></figure>
                    <figure><img src="/g2.jpg"><figcaption>G2</figcaption></figure>
                    <figure><img src="/g3.jpg"><figcaption>G3</figcaption></figure>
                </div>
                <ul><li><figure><img src="/l1.jpg"><figcaption>L1</figcaption></figure></li></ul>
                <div class="share">Share buttons</div>
                <section class="author">Written by Jane</section>
                <button>Print</button>
                <span class="d-none">hidden</span>
            </div>
        </article>
        </body></html>
        HTML;

    private function rules(): PdfRules
    {
        return new PdfRules(
            root: 'article.post',
            overline: '.overline',
            subtitle: '.sub',
            badges: '.badge',
            meta: '.meta-item',
            lead: 'p.lead',
            hero: '.hero',
            main: '.post-main',
            gallery: '.grid',
            author: '.author',
            remove: ['.share'],
            excludeFigures: ['.quote'],
        );
    }

    private function extract(?PdfOptions $options = null): \Manuxi\SuluPdfBundle\Model\PdfDocument
    {
        return (new ContentExtractor())->extract(self::HTML, 'https://example.org', $this->rules(), $options ?? new PdfOptions());
    }

    public function testReadsTheHeaderParts(): void
    {
        $doc = $this->extract();

        $this->assertSame('The real title', $doc->title);
        $this->assertSame('Guide', $doc->overline);
        $this->assertSame('A subtitle', $doc->subtitle);
        $this->assertSame(['New', 'Featured'], $doc->badges);
        $this->assertSame(['Jane Doe', '01.02.2026'], $doc->meta);
        $this->assertSame('Lead paragraph', $doc->lead);
    }

    public function testFallsBackToTheTitleTag(): void
    {
        $doc = (new ContentExtractor())->extract('<html><head><title>Only title</title></head><body><main><p>x</p></main></body></html>', 'https://example.org', new PdfRules(), new PdfOptions());

        $this->assertSame('Only title', $doc->title);
        $this->assertStringContainsString('<p>x</p>', $doc->body);
    }

    public function testHeroImageIsResolvedAndPrintedOnce(): void
    {
        $doc = $this->extract();

        // largest srcset candidate up to 1280px, webp -> jpg, root-relative -> absolute
        $this->assertSame('https://example.org/uploads/a/1280x/hero.jpg?v=1', $doc->heroSrc);
        $this->assertSame('Hero caption', $doc->heroCaption);
        $this->assertStringNotContainsString('hero-again', $doc->body);
    }

    public function testResponsiveImagesTakeTheLargestStepUpToTheLimit(): void
    {
        $html = <<<'HTML'
            <html><body><article class="post"><header><h1>Title</h1></header>
            <div class="hero"><picture><source type="image/avif" srcset="/u/640x/h.avif?v=1 640w, /u/1280x/h.avif?v=1 1280w"><img src="/u/800x/h.webp?v=1" srcset="/u/640x/h.webp?v=1 640w, /u/1280x/h.webp?v=1 1280w, /u/2000x/h.webp?v=1 2000w" sizes="auto, 100vw" alt="Hero"></picture></div>
            <div class="post-main">
                <p><img src="/u/a.avif?v=2" alt="A"></p>
                <p><img src="/u/1600x/w.webp" srcset="/u/1600x/w.webp 1600w, /u/2000x/w.webp 2000w" alt="W"></p>
            </div>
            </article></body></html>
            HTML;
        $doc = (new ContentExtractor())->extract($html, 'https://example.org', $this->rules(), new PdfOptions());

        // the <img> of a <picture>: largest step up to 1280px, WebP -> JPEG
        $this->assertSame('https://example.org/u/1280x/h.jpg?v=1', $doc->heroSrc);
        // AVIF -> JPEG as well; no <source>, srcset or sizes left for dompdf
        $this->assertStringContainsString('https://example.org/u/a.jpg?v=2', $doc->body);
        $this->assertStringNotContainsString('<source', $doc->body);
        $this->assertStringNotContainsString('srcset', $doc->body);
        // every step wider than the limit: the narrowest
        $this->assertStringContainsString('https://example.org/u/1600x/w.jpg', $doc->body);
    }

    public function testBodyIsCleaned(): void
    {
        $body = $this->extract()->body;

        $this->assertStringContainsString('<h2>Chapter</h2>', $body);
        $this->assertStringContainsString('href="https://example.org"', $body);
        foreach (['style=', 'alert(1)', 'Share buttons', 'Print', 'hidden', 'Menu'] as $gone) {
            $this->assertStringNotContainsString($gone, $body, $gone);
        }
    }

    public function testFiguresBecomeTablesWithCaptions(): void
    {
        $body = $this->extract()->body;

        $this->assertStringContainsString('<table class="fig">', $body);
        $this->assertStringContainsString('Caption one', $body);
        $this->assertStringContainsString('https://example.org/uploads/b/360x/one.jpg?v=2', $body);
        // lightbox link is unwrapped
        $this->assertStringNotContainsString('data-fancybox', $body);
        // excluded figure keeps its markup
        $this->assertStringContainsString('<figure class="quote">', $body);
    }

    public function testCaptionsCanBeSwitchedOff(): void
    {
        $doc = $this->extract(new PdfOptions(showCaptions: false));

        $this->assertStringNotContainsString('Caption one', $doc->body);
        $this->assertStringNotContainsString('G1', $doc->body);
        $this->assertSame('', $doc->heroCaption);
    }

    public function testGalleryHasTwoColumns(): void
    {
        $body = $this->extract()->body;

        $start = \strpos($body, '<table class="gallery">');
        $this->assertNotFalse($start);
        $gallery = \substr($body, $start, \strpos($body, '</table>', $start) - $start);

        // three pictures: one row of two, one row with a picture and an empty cell
        $this->assertSame(2, \substr_count($gallery, '<tr>'));
        $this->assertSame(4, \substr_count($gallery, '<td'));
        $this->assertStringContainsString('G3', $gallery);
    }

    public function testListOfFiguresLosesItsBullets(): void
    {
        $body = $this->extract()->body;

        $this->assertStringContainsString('<ul class="nolist"><li class="nolist">', $body);
    }

    public function testAuthorBoxCanBeSwitchedOff(): void
    {
        $this->assertStringContainsString('Written by Jane', $this->extract()->body);
        $this->assertStringNotContainsString('Written by Jane', $this->extract(new PdfOptions(showAuthor: false))->body);
    }

    public function testHeaderPartsInsideTheMainContentAreMovedToTheHeader(): void
    {
        $html = '<html><body><main><h1>Event</h1><h3 class="when">12 November</h3><h3 class="where">Hall</h3><p>Text</p></main></body></html>';

        $doc = (new ContentExtractor())->extract($html, 'https://example.org', new PdfRules(meta: '.when, .where'), new PdfOptions());

        $this->assertSame('Event', $doc->title);
        $this->assertSame(['12 November', 'Hall'], $doc->meta);
        $this->assertStringNotContainsString('12 November', $doc->body);
        $this->assertStringNotContainsString('Event', $doc->body);
        $this->assertStringContainsString('<p>Text</p>', $doc->body);
    }

    public function testMetaFromAProfileComesFirst(): void
    {
        $doc = (new PdfDocument('T', meta: ['b']))->withMeta(['a', 'b']);

        $this->assertSame(['a', 'b'], $doc->meta);
    }
}
