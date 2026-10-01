<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Profile;

/**
 * Optional for a profile: header lines built from the content's own data (e.g. an event's date and venue) instead of
 * being scraped from the page. They are printed in front of the meta entries the rules find.
 */
interface PdfMetaProviderInterface
{
    /**
     * @return list<string>
     */
    public function getMeta(string $id, string $locale): array;
}
