<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Service;

final class NullCompanyDataProvider implements CompanyDataProviderInterface
{
    public function getCompanyData(string $locale): ?array
    {
        return null;
    }
}
