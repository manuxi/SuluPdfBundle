# SuluPdfBundle
![php workflow](https://github.com/manuxi/SuluPdfBundle/actions/workflows/php.yml/badge.svg)
![symfony workflow](https://github.com/manuxi/SuluPdfBundle/actions/workflows/symfony.yml/badge.svg)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://github.com/manuxi/SuluPdfBundle/blob/main/LICENSE)
![GitHub Tag](https://img.shields.io/github/v/tag/manuxi/SuluPdfBundle)
![Supports Sulu 3.0 or later](https://img.shields.io/badge/%20Sulu->=3.0-0088cc?color=00b2df)

[🇩🇪 Deutsche Version](README.de.md)

PDF download for Sulu 3 content. The bundle renders the **public page** of an article, event or page, reduces it to its content and sets that into a designed PDF layout (logo, brand color, font, page numbers, QR code to the online version, optional company data). Nothing has to be built twice: every block template you already have keeps working, because the PDF is made from the rendered HTML.

## How it works

1. `GET /pdf/{resourceKey}/{id}?locale=de` looks up the profile for the resource key (`articles`, `events`, `pages`, ...). No profile or PDF switched off: 404.
2. The public page is rendered through a sub-request.
3. The **rules** of the profile (CSS selectors) pick title, header data, hero image, lead and main content. Scripts, forms, buttons, icons and carousel chrome are dropped, responsive images (`srcset`, `<picture>`) and lazy images get one real URL (the largest step up to 1280 px), figures and galleries are rebuilt as tables (dompdf cannot lay out block images inside `figure` reliably).
4. The content is set into `@SuluPdf/document.html.twig` and converted with [dompdf](https://github.com/dompdf/dompdf). Logo, rule and footer with the page count are drawn on every page.

## Requirements

- PHP 8.2+, Sulu 3.x
- `ext-intl`, `ext-dom`

## Installation

```bash
composer require manuxi/sulu-pdf-bundle
```

Register the bundle in `config/bundles.php` (Symfony Flex does this for you):

```php
Manuxi\SuluPdfBundle\SuluPdfBundle::class => ['all' => true],
```

Import the route, `config/routes/sulu_pdf.yaml`:

```yaml
sulu_pdf:
    resource: '@SuluPdfBundle/Resources/config/routes.yaml'
```

Link to the PDF in a template:

```twig
<a href="{{ sulu_pdf_url('events', event.id, app.request.locale) }}">Download as PDF</a>
```

## Configuration

`config/packages/sulu_pdf.yaml`, all keys are optional:

```yaml
sulu_pdf:
    paper: A4
    logo: 'public/images/logo.{locale}.png'   # PNG or JPG; "{locale}" is replaced; without a logo the site name is printed
    logo_width: 50                            # mm
    site_name: 'Example Inc.'
    colors:
        primary: '#2f6fed'                    # rule, accents, boxes; darker/lighter shades are derived
        text: '#2d2d2d'
        muted: '#777777'
    font:                                     # without files the built-in "DejaVu Sans" is used
        family: 'Open Sans'
        regular: 'assets/fonts/open-sans-regular.ttf'
        italic: 'assets/fonts/open-sans-italic.ttf'
        bold: 'assets/fonts/open-sans-700.ttf'
        bold_italic: 'assets/fonts/open-sans-700italic.ttf'
    excerpt:                                    # PDF switches in the excerpt tab
        pages: true
        articles: true
        events: true
    company_data_provider: App\Pdf\CompanyDataProvider
    profiles:
        offers:
            resource_key: offers                # a profile of its own
            rules:
                main: 'main > .container'
            options:
                company_data: end               # none | footer | end
        events:                                 # a bundled profile (event bundle installed): rules and options are tuned here
            rules:
                hero: '.event .card > img'
                remove: ['.event .location']
            options:
                company_data: end
        articles:
            rules:
                main: '.post-body'
```

Paths are absolute or relative to the project directory.

## Profiles

A profile says for one kind of content whether a PDF exists, with which options, and where the parts of its page are.

### Articles (automatic)

**Recommended:** the switches in the excerpt tab (`excerpt.articles: true`, see below). Their default rules match the article templates of the reference theme (`.article-main`, `.article-header`, ...), other themes override them as shown above.

*Legacy:* with [manuxi/sulu-article-configuration-bundle](https://github.com/manuxi/SuluArticleConfigurationBundle) **1.4.x** (and without `excerpt.articles`) the profile `articles` reads that bundle's own switches (tab "Configuration"). Version 2.0 of the article configuration bundle no longer has these fields, so with 2.x use `excerpt.articles`.

### Switch in the excerpt tab (pages, articles, events)

```yaml
sulu_pdf:
    excerpt:
        pages: true
        articles: true
        events: true
```

adds a **PDF section to the excerpt tab** of that content, as its last section (download on/off, image captions, last-modified date, link/QR code, company data; articles also have the author box), and registers the profile (`pages`, `articles`, `events`) that reads these values. The switches use Sulu's own hook for extra excerpt fields (`sulu_content.content_excerpt_form`), so they are saved in the content's excerpt data: **per language, and with the draft/publish workflow** like any other content. Only the published version decides whether the download exists.

- **Pages** have no author box. Without `excerpt.pages` define a profile with `resource_key: pages` in the config - then every page has a PDF.
- **Articles:** with `excerpt.articles` the excerpt switches replace the PDF switches of the article configuration bundle (its profile is then not registered). Without it that bundle's tab "Configuration" decides (see above).
- **Events:** with `excerpt.events` the excerpt switches decide per event; without it on/off and the options come from `sulu_pdf.profiles.events` for all events. Date and venue are printed as header lines from the event's own data (not scraped from the page), the event's last change is used for "last modified".

To show a link only where a PDF exists:

```twig
{% if sulu_pdf_available('pages', uuid, app.request.locale) %}
    <a href="{{ sulu_pdf_url('pages', uuid, app.request.locale) }}">Download as PDF</a>
{% endif %}
```

### Profiles from config

Every profile with a `resource_key` in `sulu_pdf.profiles` offers a PDF for all resources of that key, with the `options` given there (`show_captions`, `show_author`, `show_modified`, `show_online_link`, `company_data`).

### Profiles in PHP

Implement `Manuxi\SuluPdfBundle\Profile\PdfProfileInterface` and tag the service `sulu_pdf.profile`:

```php
final class ProductProfile implements PdfProfileInterface
{
    public function getName(): string { return 'products'; }
    public function getResourceKey(): string { return 'products'; }
    public function getDefaultRules(): PdfRules { return new PdfRules(main: '.product-detail'); }
    public function getOptions(string $id, string $locale): ?PdfOptions { return new PdfOptions(showAuthor: false); } // null = no PDF
    public function getModified(string $id, string $locale): ?\DateTimeInterface { return null; }
}
```

A profile can additionally implement `Manuxi\SuluPdfBundle\Profile\PdfMetaProviderInterface` (`getMeta(string $id, string $locale): array`) to print header lines from the content's own data, such as a date or a venue, in front of the entries the `meta` rule finds.

### Rules

CSS selectors; single-part rules are searched inside `root`, the first match wins.

| Rule | Default | Meaning |
|---|---|---|
| `root` | `body` | container that holds the header data |
| `title` | `h1` | title (also removed from the content, the layout prints it) |
| `overline`, `subtitle`, `lead` | - | optional header texts (removed from the content when found inside it) |
| `badges`, `meta` | - | every match becomes one badge / one meta entry (removed from the content when found inside it) |
| `hero` | - | container of the hero image (printed once at the top) |
| `main` | `main` | the content; falls back to `<main>`, then `<body>` |
| `gallery` | - | container of a gallery: its pictures are printed in two columns |
| `author` | - | author box, removed when "author" is switched off |
| `remove` | - | more elements to drop; adds to the built-in list (nav, footer, scripts, ...) |
| `exclude_figures` | - | figures that keep their markup |

## Company data

The bundle has no company data of its own. Implement `Manuxi\SuluPdfBundle\Service\CompanyDataProviderInterface` (name, street, zip, city, phone, email, url), for example reading your organisation snippet, and set it as `company_data_provider`. The option `company_data` puts it into the footer of every page or into the closing box.

## Customizing the layout

Override `@SuluPdf/document.html.twig` with `templates/bundles/SuluPdfBundle/document.html.twig`. dompdf understands CSS 2.1 only (no flexbox, grid, custom properties), so the layout is plain on purpose.

## Good to know

- The selectors depend on your theme's markup - set the rules per project.
- Images are fetched over HTTP; on local hosts (`localhost`, `local.*`, `*.test`, `*.local`) certificate verification is switched off for that.
- `.webp` and `.avif` images are requested as `.jpg` (dompdf reads neither WebP nor AVIF; Sulu picks the format by URL extension). Of a responsive image the largest step up to 1280 px is used, the `<source>` elements of a `<picture>` are dropped.
- Lightbox links around images are removed, the image stays: Fancybox (`data-fancybox*`) and GLightbox (`data-lightbox*`, `data-glightbox`, class `glightbox`).
- The logo must be PNG or JPG.
- Fonts are registered in `%kernel.cache_dir%/sulu_pdf` on the first request after a cache clear.

## Tests

```bash
composer install && vendor/bin/phpunit
```

## License

MIT
