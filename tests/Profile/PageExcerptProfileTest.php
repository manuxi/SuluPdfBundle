<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Tests\Profile;

use Doctrine\DBAL\Connection;
use Manuxi\SuluPdfBundle\Model\PdfOptions;
use Manuxi\SuluPdfBundle\Model\PdfRules;
use Manuxi\SuluPdfBundle\Profile\ConfigProfile;
use Manuxi\SuluPdfBundle\Profile\PageExcerptProfile;
use Manuxi\SuluPdfBundle\Profile\ProfileRegistry;
use Manuxi\SuluPdfBundle\Twig\PdfExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PageExcerptProfileTest extends TestCase
{
    private function profile(string|false $excerptJson): PageExcerptProfile
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn($excerptJson);

        return new PageExcerptProfile($connection);
    }

    public function testNoSwitchNoPdf(): void
    {
        $this->assertNull($this->profile('{"title": null}')->getOptions('uuid', 'de'));
        $this->assertNull($this->profile(false)->getOptions('uuid', 'de'), 'no published version');
        $this->assertNull($this->profile('not json')->getOptions('uuid', 'de'));
    }

    public function testSwitchedOnUsesTheExcerptValues(): void
    {
        $options = $this->profile('{"pdf_enabled": true, "pdf_show_captions": false, "pdf_company_data": "footer"}')->getOptions('uuid', 'de');

        $this->assertNotNull($options);
        $this->assertFalse($options->showCaptions);
        $this->assertFalse($options->showAuthor, 'pages have no author box');
        $this->assertTrue($options->showModified, 'a missing switch keeps its default');
        $this->assertTrue($options->showOnlineLink);
        $this->assertSame('footer', $options->companyData);
    }

    public function testModifiedIsReadFromTheLiveVersion(): void
    {
        $profile = $this->profile('2026-10-01 10:00:00');

        $this->assertSame('2026-10-01', $profile->getModified('uuid', 'de')?->format('Y-m-d'));
    }

    public function testTwigKnowsWhetherAPdfExists(): void
    {
        $registry = new ProfileRegistry([
            new ConfigProfile('on', 'on', true, new PdfOptions(), new PdfRules()),
            new ConfigProfile('off', 'off', false, new PdfOptions(), new PdfRules()),
        ]);
        $extension = new PdfExtension($this->createMock(UrlGeneratorInterface::class), $registry);

        $this->assertTrue($extension->available('on', '1', 'de'));
        $this->assertFalse($extension->available('off', '1', 'de'));
        $this->assertFalse($extension->available('unknown', '1', 'de'));
    }
}
