<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Profile;

use Manuxi\SuluPdfBundle\Model\PdfOptions;
use Manuxi\SuluPdfBundle\Model\PdfRules;

/**
 * Describes one kind of content (articles, events, pages, ...) for the PDF download. Register an implementation with
 * the service tag "sulu_pdf.profile", or use the config (sulu_pdf.profiles) for profiles without code.
 */
interface PdfProfileInterface
{
    /** key in sulu_pdf.profiles under which the project can override this profile's rules, e.g. "articles" */
    public function getName(): string;

    /** the route resource key of the content (ro_routes.resource_key), also the "{resourceKey}" of the download URL */
    public function getResourceKey(): string;

    /** the selectors that find the parts of this content's public page */
    public function getDefaultRules(): PdfRules;

    /**
     * The switches for one resource; null when there is no PDF for it (download switched off).
     */
    public function getOptions(string $id, string $locale): ?PdfOptions;

    /** date of the last change, for "last modified"; null if unknown */
    public function getModified(string $id, string $locale): ?\DateTimeInterface;
}
