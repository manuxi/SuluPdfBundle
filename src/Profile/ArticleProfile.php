<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Profile;

use Doctrine\DBAL\Connection;
use Manuxi\SuluArticleConfigurationBundle\Service\ArticleConfigurationResolver;
use Manuxi\SuluPdfBundle\Model\PdfOptions;
use Manuxi\SuluPdfBundle\Model\PdfRules;

/**
 * Sulu articles, switched per article in the tab "Konfiguration" of manuxi/sulu-article-configuration-bundle
 * (enableDownloadPdf and the pdf* switches). Registered automatically when that bundle is installed.
 *
 * The default rules match the article templates of that bundle's reference theme (".article-main", ".article-header", ...);
 * other themes override them in sulu_pdf.profiles.articles.rules.
 */
final class ArticleProfile implements PdfProfileInterface
{
    public function __construct(
        private readonly ArticleConfigurationResolver $resolver,
        private readonly Connection $connection,
    ) {
    }

    public function getName(): string
    {
        return 'articles';
    }

    public function getResourceKey(): string
    {
        return 'articles';
    }

    public function getDefaultRules(): PdfRules
    {
        return new PdfRules(
            root: 'article.article',
            title: 'h1',
            overline: '.overline',
            subtitle: '.article-header__row--subtitle h2, .article-header__row--subtitle h3',
            badges: '.article-badge',
            meta: '.article-meta__item',
            lead: 'p.article-lead',
            hero: '.article-hero',
            main: '.article-main',
            gallery: '.image-container',
            author: '.article-author',
            remove: ['.article-share', '.article-taxonomy', '.article-author__avatar', '.article-icon', '.article-code__copy', '.article-quote__image', '.article-callout__icon'],
            excludeFigures: ['.article-quote'],
        );
    }

    public function getOptions(string $id, string $locale): ?PdfOptions
    {
        $config = $this->resolver->resolve($id);
        if (!($config['enableDownloadPdf'] ?? false)) {
            return null;
        }

        return PdfOptions::fromArray([
            'showCaptions' => $config['pdfShowCaptions'] ?? true,
            'showAuthor' => $config['pdfShowAuthor'] ?? true,
            'showModified' => $config['pdfShowModified'] ?? true,
            'showOnlineLink' => $config['pdfShowOnlineLink'] ?? true,
            'companyData' => $config['pdfCompanyData'] ?? PdfOptions::COMPANY_NONE,
        ]);
    }

    public function getModified(string $id, string $locale): ?\DateTimeInterface
    {
        // the live version's own timestamp; content created by scripts may lack lastModified
        $value = $this->connection->fetchOne(
            'SELECT COALESCE(lastModified, changed) FROM ar_article_dimension_contents WHERE articleUuid = :id AND locale = :locale AND stage = :stage ORDER BY version DESC',
            ['id' => $id, 'locale' => $locale, 'stage' => 'live'],
        );

        return $value ? new \DateTimeImmutable((string) $value) : null;
    }
}
