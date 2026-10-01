<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Profile;

use Doctrine\DBAL\Connection;
use Manuxi\SuluPageConfigurationBundle\Repository\PageConfigurationRepository;
use Manuxi\SuluPdfBundle\Model\PdfOptions;
use Manuxi\SuluPdfBundle\Model\PdfRules;

/**
 * Sulu pages, switched per page in the tab "Configuration" of manuxi/sulu-page-configuration-bundle (PDF download and
 * the pdf* switches). Registered automatically when that bundle is installed. Pages have no author box.
 */
final class PageProfile implements PdfProfileInterface
{
    public function __construct(
        private readonly PageConfigurationRepository $repository,
        private readonly Connection $connection,
    ) {
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
        $config = $this->repository->findByPageId($id);
        if (!$config || !$config->isEnableDownloadPdf()) {
            return null;
        }

        return PdfOptions::fromArray([
            'showCaptions' => $config->isPdfShowCaptions(),
            'showAuthor' => false,
            'showModified' => $config->isPdfShowModified(),
            'showOnlineLink' => $config->isPdfShowOnlineLink(),
            'companyData' => $config->getPdfCompanyData(),
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
}
