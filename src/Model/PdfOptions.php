<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Model;

/**
 * What goes into the PDF besides the content itself. A profile decides per resource (e.g. from the article's
 * configuration tab), the defaults below apply to profiles without switches of their own.
 */
final class PdfOptions
{
    public const COMPANY_NONE = 'none';
    public const COMPANY_FOOTER = 'footer';
    public const COMPANY_END = 'end';

    public function __construct(
        public readonly bool $showCaptions = true,
        public readonly bool $showAuthor = true,
        public readonly bool $showModified = true,
        public readonly bool $showOnlineLink = true,
        public readonly string $companyData = self::COMPANY_NONE,
    ) {
    }

    /**
     * @param array<string, mixed> $data camelCase or snake_case keys of the constructor arguments
     */
    public static function fromArray(array $data): self
    {
        $get = static function (string $camel, mixed $default) use ($data): mixed {
            $snake = \strtolower((string) \preg_replace('/(?<!^)[A-Z]/', '_$0', $camel));

            return $data[$camel] ?? $data[$snake] ?? $default;
        };

        $company = (string) $get('companyData', self::COMPANY_NONE);

        return new self(
            (bool) $get('showCaptions', true),
            (bool) $get('showAuthor', true),
            (bool) $get('showModified', true),
            (bool) $get('showOnlineLink', true),
            \in_array($company, [self::COMPANY_NONE, self::COMPANY_FOOTER, self::COMPANY_END], true) ? $company : self::COMPANY_NONE,
        );
    }
}
