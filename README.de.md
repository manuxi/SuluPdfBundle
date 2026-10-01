# SuluPdfBundle
![php workflow](https://github.com/manuxi/SuluPdfBundle/actions/workflows/php.yml/badge.svg)
![symfony workflow](https://github.com/manuxi/SuluPdfBundle/actions/workflows/symfony.yml/badge.svg)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://github.com/manuxi/SuluPdfBundle/blob/main/LICENSE)
![GitHub Tag](https://img.shields.io/github/v/tag/manuxi/SuluPdfBundle)
![Supports Sulu 3.0 or later](https://img.shields.io/badge/%20Sulu->=3.0-0088cc?color=00b2df)

[🇬🇧 English version](README.md)

PDF-Download für Sulu-3-Inhalte. Das Bundle rendert die **öffentliche Seite** eines Artikels, Events oder einer Seite, reduziert sie auf den Inhalt und setzt diesen in ein gestaltetes PDF-Layout (Logo, Markenfarbe, Schrift, Seitenzahlen, QR-Code zur Online-Version, optional Firmendaten). Nichts muss doppelt gebaut werden: Alle vorhandenen Block-Templates funktionieren weiter, weil das PDF aus dem gerenderten HTML entsteht.

## So funktioniert es

1. `GET /pdf/{resourceKey}/{id}?locale=de` sucht das Profil zum Resource-Key (`articles`, `events`, `pages`, ...). Kein Profil oder PDF ausgeschaltet: 404.
2. Die öffentliche Seite wird über einen Sub-Request gerendert.
3. Die **Regeln** des Profils (CSS-Selektoren) finden Titel, Kopfdaten, Hero-Bild, Lead und Hauptinhalt. Skripte, Formulare, Buttons, Icons und Slider-Bedienelemente entfallen, Lazy-Load-Bilder bekommen ihre echte URL, Figures und Galerien werden als Tabellen neu aufgebaut (dompdf kann Block-Bilder in `figure` nicht zuverlässig setzen).
4. Der Inhalt wird in `@SuluPdf/document.html.twig` gesetzt und mit [dompdf](https://github.com/dompdf/dompdf) umgewandelt. Logo, Linie und Fußzeile mit Seitenzahl werden auf jeder Seite gezeichnet.

## Voraussetzungen

- PHP 8.2+, Sulu 3.x
- `ext-intl`, `ext-dom`

## Installation

```bash
composer require manuxi/sulu-pdf-bundle
```

Bundle in `config/bundles.php` eintragen (Symfony Flex macht das automatisch):

```php
Manuxi\SuluPdfBundle\SuluPdfBundle::class => ['all' => true],
```

Route einbinden, `config/routes/sulu_pdf.yaml`:

```yaml
sulu_pdf:
    resource: '@SuluPdfBundle/Resources/config/routes.yaml'
```

Link zum PDF im Template:

```twig
<a href="{{ sulu_pdf_url('events', event.id, app.request.locale) }}">Als PDF herunterladen</a>
```

## Konfiguration

`config/packages/sulu_pdf.yaml`, alle Schlüssel sind optional:

```yaml
sulu_pdf:
    paper: A4
    logo: 'public/images/logo.{locale}.png'   # PNG oder JPG; "{locale}" wird ersetzt; ohne Logo steht der Seitenname
    logo_width: 50                            # mm
    site_name: 'Beispiel GmbH'
    colors:
        primary: '#2f6fed'                    # Linie, Akzente, Kästen; dunklere/hellere Töne werden abgeleitet
        text: '#2d2d2d'
        muted: '#777777'
    font:                                     # ohne Dateien wird die eingebaute "DejaVu Sans" genutzt
        family: 'Open Sans'
        regular: 'assets/fonts/open-sans-regular.ttf'
        italic: 'assets/fonts/open-sans-italic.ttf'
        bold: 'assets/fonts/open-sans-700.ttf'
        bold_italic: 'assets/fonts/open-sans-700italic.ttf'
    company_data_provider: App\Pdf\CompanyDataProvider
    profiles:
        offers:
            resource_key: offers                # ein eigenes Profil
            rules:
                main: 'main > .container'
            options:
                company_data: end               # none | footer | end
        events:                                 # mitgeliefertes Profil (Event-Bundle installiert): Regeln und Optionen werden hier angepasst
            rules:
                hero: '.event .card > img'
                remove: ['.event .location']
            options:
                company_data: end
        articles:
            rules:
                main: '.post-body'
```

Pfade sind absolut oder relativ zum Projektverzeichnis.

## Profile

Ein Profil sagt für eine Art von Inhalt, ob es ein PDF gibt, mit welchen Optionen und wo die Teile der Seite stehen.

### Artikel (automatisch)

Mit [manuxi/sulu-article-configuration-bundle](https://github.com/manuxi/SuluArticleConfigurationBundle) (1.4+) wird das Profil `articles` automatisch registriert. Es liest die Schalter des Artikels selbst (Tab "Konfiguration": PDF-Download, Bildunterschriften, Autorenkasten, Datum der letzten Änderung, Link/QR-Code, Firmendaten). Die Standard-Regeln passen zu den Artikel-Templates des Referenz-Themes dieses Bundles (`.article-main`, `.article-header`, ...), andere Themes überschreiben sie wie oben gezeigt.

### Seiten (automatisch)

Mit [manuxi/sulu-page-configuration-bundle](https://github.com/manuxi/SuluPageConfigurationBundle) wird das Profil `pages` automatisch registriert. Jede Seite bekommt eigene Schalter im Abschnitt PDF des Tabs "Konfiguration" (Download an/aus, Bildunterschriften, Datum der letzten Änderung, Link/QR-Code, Firmendaten). Ohne dieses Bundle legen Sie in der Konfiguration ein Profil mit `resource_key: pages` an - dann hat jede Seite ein PDF.

### Events (automatisch)

Mit [manuxi/sulu-event-bundle](https://github.com/manuxi/SuluEventBundle) wird das Profil `events` automatisch registriert. Datum und Veranstaltungsort werden als Kopfzeilen aus den eigenen Daten des Events gedruckt (nicht von der Seite gelesen), die letzte Änderung des Events dient als "Zuletzt geändert". Ein Event hat keine eigenen Schalter: An/Aus (`enabled`) und die Optionen kommen aus `sulu_pdf.profiles.events`.

### Profile aus der Konfiguration

Jedes Profil mit `resource_key` in `sulu_pdf.profiles` bietet ein PDF für alle Ressourcen dieses Keys an, mit den dort angegebenen `options` (`show_captions`, `show_author`, `show_modified`, `show_online_link`, `company_data`).

### Profile in PHP

`Manuxi\SuluPdfBundle\Profile\PdfProfileInterface` implementieren und den Service mit `sulu_pdf.profile` taggen:

```php
final class ProductProfile implements PdfProfileInterface
{
    public function getName(): string { return 'products'; }
    public function getResourceKey(): string { return 'products'; }
    public function getDefaultRules(): PdfRules { return new PdfRules(main: '.product-detail'); }
    public function getOptions(string $id, string $locale): ?PdfOptions { return new PdfOptions(showAuthor: false); } // null = kein PDF
    public function getModified(string $id, string $locale): ?\DateTimeInterface { return null; }
}
```

Ein Profil kann zusätzlich `Manuxi\SuluPdfBundle\Profile\PdfMetaProviderInterface` (`getMeta(string $id, string $locale): array`) implementieren, um Kopfzeilen aus den eigenen Daten des Inhalts zu drucken, etwa ein Datum oder einen Ort. Sie stehen vor den Einträgen, die die Regel `meta` findet.

### Regeln

CSS-Selektoren; Regeln für einzelne Teile werden innerhalb von `root` gesucht, der erste Treffer gilt.

| Regel | Standard | Bedeutung |
|---|---|---|
| `root` | `body` | Container mit den Kopfdaten |
| `title` | `h1` | Titel (wird auch aus dem Inhalt entfernt, das Layout druckt ihn) |
| `overline`, `subtitle`, `lead` | - | optionale Kopftexte (werden aus dem Inhalt entfernt, wenn sie darin stehen) |
| `badges`, `meta` | - | jeder Treffer wird ein Badge / ein Meta-Eintrag (werden aus dem Inhalt entfernt, wenn sie darin stehen) |
| `hero` | - | Container des Hero-Bildes (einmal oben gedruckt) |
| `main` | `main` | der Inhalt; Ersatz ist `<main>`, dann `<body>` |
| `gallery` | - | Container einer Galerie: die Bilder stehen in zwei Spalten |
| `author` | - | Autorenkasten, entfällt bei ausgeschaltetem "Autor" |
| `remove` | - | weitere zu entfernende Elemente; ergänzt die eingebaute Liste (nav, footer, Skripte, ...) |
| `exclude_figures` | - | Figures, die ihr Markup behalten |

## Firmendaten

Das Bundle hat keine eigenen Firmendaten. `Manuxi\SuluPdfBundle\Service\CompanyDataProviderInterface` implementieren (Name, Straße, PLZ, Ort, Telefon, E-Mail, URL), zum Beispiel aus Ihrem Organisations-Snippet, und als `company_data_provider` eintragen. Die Option `company_data` setzt die Daten in die Fußzeile jeder Seite oder in den Schlusskasten.

## Layout anpassen

`@SuluPdf/document.html.twig` mit `templates/bundles/SuluPdfBundle/document.html.twig` überschreiben. dompdf versteht nur CSS 2.1 (kein Flexbox, Grid, Custom Properties), das Layout ist deshalb bewusst schlicht.

## Gut zu wissen

- Die Selektoren hängen am Markup Ihres Themes - Regeln pro Projekt setzen.
- Bilder werden per HTTP geladen; auf lokalen Hosts (`localhost`, `local.*`, `*.test`, `*.local`) ist dafür die Zertifikatsprüfung abgeschaltet.
- `.webp`-Bilder werden als `.jpg` angefordert (dompdf kann kein WebP; Sulu wählt das Format über die URL-Endung).
- Das Logo muss PNG oder JPG sein.
- Schriften werden in `%kernel.cache_dir%/sulu_pdf` registriert, beim ersten Aufruf nach einem Cache-Clear.

## Tests

```bash
composer install && vendor/bin/phpunit
```

## Lizenz

MIT
