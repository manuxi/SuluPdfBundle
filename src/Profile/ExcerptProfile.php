<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Profile;

use Manuxi\SuluPdfBundle\Model\PdfOptions;
use Manuxi\SuluPdfBundle\Model\PdfRules;

/**
 * A kind of content (pages, articles, ...) switched per item in the PDF section of its excerpt tab
 * (sulu_pdf.excerpt.<kind>: true). The values live in the excerpt data: per language and with the draft/publish
 * workflow; only the published version decides whether the download exists.
 */
final class ExcerptProfile implements PdfProfileInterface
{
    /**
     * @param bool $authorSwitch whether the excerpt form has the switch "author box" (otherwise the PDF has none)
     */
    public function __construct(
        private readonly string $name,
        private readonly ExcerptReader $reader,
        private readonly PdfRules $rules,
        private readonly bool $authorSwitch = false,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getResourceKey(): string
    {
        return $this->name;
    }

    public function getDefaultRules(): PdfRules
    {
        return $this->rules;
    }

    public function getOptions(string $id, string $locale): ?PdfOptions
    {
        return self::optionsFromExcerpt($this->reader->read($id, $locale), $this->authorSwitch);
    }

    public function getModified(string $id, string $locale): ?\DateTimeInterface
    {
        return $this->reader->modified($id, $locale);
    }

    /**
     * @param array<string, mixed> $excerpt
     *
     * @return PdfOptions|null null when the switch "PDF download" is off
     */
    public static function optionsFromExcerpt(array $excerpt, bool $authorSwitch = false): ?PdfOptions
    {
        if (!($excerpt['pdf_enabled'] ?? false)) {
            return null;
        }

        return PdfOptions::fromArray([
            'showCaptions' => $excerpt['pdf_show_captions'] ?? true,
            'showAuthor' => $authorSwitch ? ($excerpt['pdf_show_author'] ?? true) : false,
            'showModified' => $excerpt['pdf_show_modified'] ?? true,
            'showOnlineLink' => $excerpt['pdf_show_online_link'] ?? true,
            'companyData' => $excerpt['pdf_company_data'] ?? PdfOptions::COMPANY_NONE,
        ]);
    }
}
