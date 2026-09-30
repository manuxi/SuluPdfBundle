<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Service;

/**
 * Supplies the company data for the PDF ("company data" switch: none / footer / end). The bundle has no company data of
 * its own - a project implements this (e.g. reading its organisation snippet or an account) and sets the service as
 * sulu_pdf.company_data_provider.
 */
interface CompanyDataProviderInterface
{
    /**
     * @return array{name?: string, street?: string, zip?: string, city?: string, phone?: string, email?: string, url?: string}|null
     */
    public function getCompanyData(string $locale): ?array;
}
