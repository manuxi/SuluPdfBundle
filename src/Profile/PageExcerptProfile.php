<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Profile;

use Doctrine\DBAL\Connection;
use Manuxi\SuluPdfBundle\Model\PdfOptions;
use Manuxi\SuluPdfBundle\Model\PdfRules;

/**
 * Sulu pages, switched per page in the excerpt tab (section "PDF"): sulu_pdf.excerpt.pages must be on, which adds the
 * fields to the excerpt form. The values live in the page's excerptData, so they are per language and follow
 * the page's draft/publish workflow. Pages have no author box.
 */
final class PageExcerptProfile implements PdfProfileInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getName(): string
    {
        return 'pages';
    }

    public function getResourceKey(): string
    {
        return 'pages';
    }

    public function getDefaultRules(): PdfRules
    {
        return new PdfRules();
    }

    public function getOptions(string $id, string $locale): ?PdfOptions
    {
        $excerpt = $this->excerpt($id, $locale);
        if (!($excerpt['pdf_enabled'] ?? false)) {
            return null;
        }

        return PdfOptions::fromArray([
            'showCaptions' => $excerpt['pdf_show_captions'] ?? true,
            'showAuthor' => false,
            'showModified' => $excerpt['pdf_show_modified'] ?? true,
            'showOnlineLink' => $excerpt['pdf_show_online_link'] ?? true,
            'companyData' => $excerpt['pdf_company_data'] ?? PdfOptions::COMPANY_NONE,
        ]);
    }

    public function getModified(string $id, string $locale): ?\DateTimeInterface
    {
        $value = $this->connection->fetchOne(
            'SELECT COALESCE(lastModified, changed) FROM pa_page_dimension_contents WHERE pageUuid = :id AND locale = :locale AND stage = :stage ORDER BY version DESC',
            ['id' => $id, 'locale' => $locale, 'stage' => 'live'],
        );

        return $value ? new \DateTimeImmutable((string) $value) : null;
    }

    /**
     * @return array<string, mixed> the live version's excerpt data (the published state decides, not the draft)
     */
    private function excerpt(string $id, string $locale): array
    {
        $json = $this->connection->fetchOne(
            'SELECT excerptData FROM pa_page_dimension_contents WHERE pageUuid = :id AND locale = :locale AND stage = :stage ORDER BY version DESC',
            ['id' => $id, 'locale' => $locale, 'stage' => 'live'],
        );

        $data = \is_string($json) ? \json_decode($json, true) : null;

        return \is_array($data) ? $data : [];
    }
}
