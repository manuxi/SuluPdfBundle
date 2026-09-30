# SuluPdfBundle

[🇩🇪 Deutsche Version](README.de.md)

PDF download for Sulu 3 content. The bundle renders the **public page** of an article, event or page, reduces it to its content and sets that into a designed PDF layout (logo, brand color, font, page numbers, QR code to the online version, optional company data). Nothing has to be built twice: every block template you already have keeps working, because the PDF is made from the rendered HTML.

## How it works

1. `GET /pdf/{resourceKey}/{id}?locale=de` looks up the profile for the resource key (`articles`, `events`, `pages`, ...). No profile or PDF switched off: 404.
2. The public page is rendered through a sub-request.
3. The **rules** of the profile (CSS selectors) pick title, header data, hero image, lead and main content. Scripts, forms, buttons, icons and carousel chrome are dropped, lazy images get their real URL, figures and galleries are rebuilt as tables (dompdf cannot lay out block images inside `figure` reliably).
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
    company_data_provider: App\Pdf\CompanyDataProvider
    profiles:
        events:
            resource_key: events                # a profile of its own
            rules:
                main: 'main > .container'
            options:
                company_data: end               # none | footer | end
        articles:                               # only overrides rules of the bundled profile
            rules:
                main: '.post-body'
```

Paths are absolute or relative to the project directory.

## Profiles

A profile says for one kind of content whether a PDF exists, with which options, and where the parts of its page are.

### Articles (automatic)

With [manuxi/sulu-article-configuration-bundle](https://github.com/manuxi/SuluArticleConfigurationBundle) (1.4+) the profile `articles` is registered automatically. It reads the article's own switches (tab "Configuration": PDF download, image captions, author box, last-modified date, link/QR code, company data). Its default rules match the article templates of that bundle's reference theme (`.article-main`, `.article-header`, ...), other themes override them as shown above.

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

### Rules

CSS selectors; single-part rules are searched inside `root`, the first match wins.

| Rule | Default | Meaning |
|---|---|---|
| `root` | `body` | container that holds the header data |
| `title` | `h1` | title (also removed from the content, the layout prints it) |
| `overline`, `subtitle`, `lead` | - | optional header texts (`lead` is removed from the content) |
| `badges`, `meta` | - | every match becomes one badge / one meta entry |
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
- `.webp` images are requested as `.jpg` (dompdf cannot read WebP; Sulu picks the format by URL extension).
- The logo must be PNG or JPG.
- Fonts are registered in `%kernel.cache_dir%/sulu_pdf` on the first request after a cache clear.

## Tests

```bash
composer install && vendor/bin/phpunit
```

## License

MIT
