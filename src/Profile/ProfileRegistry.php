<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Profile;

use Manuxi\SuluPdfBundle\Model\PdfRules;

/**
 * Finds the profile for a resource key and applies the project's rule overrides (sulu_pdf.profiles.<name>.rules).
 */
final class ProfileRegistry
{
    /**
     * @param iterable<PdfProfileInterface>      $profiles
     * @param array<string, array<string, mixed>> $ruleOverrides rules from the config, by profile name
     */
    public function __construct(
        private readonly iterable $profiles,
        private readonly array $ruleOverrides = [],
    ) {
    }

    public function get(string $resourceKey): ?PdfProfileInterface
    {
        foreach ($this->profiles as $profile) {
            if ($profile->getResourceKey() === $resourceKey) {
                return $profile;
            }
        }

        return null;
    }

    public function rules(PdfProfileInterface $profile): PdfRules
    {
        return $profile->getDefaultRules()->with($this->ruleOverrides[$profile->getName()] ?? []);
    }
}
