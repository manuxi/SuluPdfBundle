<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Model;

final class PdfResult
{
    public function __construct(
        public readonly string $content,
        public readonly string $filename,
    ) {
    }
}
