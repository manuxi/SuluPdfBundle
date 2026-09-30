<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Profile;

use Manuxi\SuluPdfBundle\Model\PdfOptions;
use Manuxi\SuluPdfBundle\Model\PdfRules;

/**
 * A profile defined in config/packages/sulu_pdf.yaml, for content without a switch of its own (events, pages, ...):
 * the PDF is available for every resource of the resource key, with the same options.
 */
final class ConfigProfile implements PdfProfileInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $resourceKey,
        private readonly bool $enabled,
        private readonly PdfOptions $options,
        private readonly PdfRules $rules,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getResourceKey(): string
    {
        return $this->resourceKey;
    }

    public function getDefaultRules(): PdfRules
    {
        return $this->rules;
    }

    public function getOptions(string $id, string $locale): ?PdfOptions
    {
        return $this->enabled ? $this->options : null;
    }

    public function getModified(string $id, string $locale): ?\DateTimeInterface
    {
        return null;
    }
}
