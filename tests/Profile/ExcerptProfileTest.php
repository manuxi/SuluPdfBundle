<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Tests\Profile;

use Doctrine\DBAL\Connection;
use Manuxi\SuluPdfBundle\Model\PdfOptions;
use Manuxi\SuluPdfBundle\Model\PdfRules;
use Manuxi\SuluPdfBundle\Profile\ConfigProfile;
use Manuxi\SuluPdfBundle\Profile\EventProfile;
use Manuxi\SuluPdfBundle\Profile\ExcerptProfile;
use Manuxi\SuluPdfBundle\Profile\ExcerptReader;
use Manuxi\SuluPdfBundle\Profile\ProfileRegistry;
use Manuxi\SuluPdfBundle\Twig\PdfExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ExcerptProfileTest extends TestCase
{
    private function reader(string|false $value): ExcerptReader
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn($value);

        return new ExcerptReader($connection, 'pa_page_dimension_contents', 'pageUuid', 'excerptData', 'lastModified', 'changed');
    }

    private function profile(string|false $value, bool $authorSwitch = false): ExcerptProfile
    {
        return new ExcerptProfile('pages', $this->reader($value), new PdfRules(), $authorSwitch);
    }

    public function testNoSwitchNoPdf(): void
    {
        $this->assertNull($this->profile('{"title": null}')->getOptions('uuid', 'de'));
        $this->assertNull($this->profile('[]')->getOptions('uuid', 'de'), 'Sulu stores an empty excerpt as []');
        $this->assertNull($this->profile(false)->getOptions('uuid', 'de'), 'no published version');
        $this->assertNull($this->profile('not json')->getOptions('uuid', 'de'));
    }

    public function testSwitchedOnUsesTheExcerptValues(): void
    {
        $options = $this->profile('{"pdf_enabled": true, "pdf_show_captions": false, "pdf_company_data": "footer"}')->getOptions('uuid', 'de');

        $this->assertNotNull($options);
        $this->assertFalse($options->showCaptions);
        $this->assertFalse($options->showAuthor, 'no author switch in this form');
        $this->assertTrue($options->showModified, 'a missing switch keeps its default');
        $this->assertTrue($options->showOnlineLink);
        $this->assertSame('footer', $options->companyData);
    }

    public function testAuthorBoxFollowsItsSwitchWhereTheFormHasOne(): void
    {
        $on = $this->profile('{"pdf_enabled": true}', true)->getOptions('uuid', 'de');
        $off = $this->profile('{"pdf_enabled": true, "pdf_show_author": false}', true)->getOptions('uuid', 'de');

        $this->assertTrue($on?->showAuthor);
        $this->assertFalse($off?->showAuthor);
    }

    public function testModifiedIsReadFromTheLiveVersion(): void
    {
        $this->assertSame('2026-10-01', $this->profile('2026-10-01 10:00:00')->getModified('uuid', 'de')?->format('Y-m-d'));
    }

    public function testEventsFollowTheirExcerptSwitchWhenAReaderIsGiven(): void
    {
        $connection = $this->createMock(Connection::class);
        $configured = new PdfOptions(showCaptions: false);

        $global = new EventProfile($connection, $configured, true);
        $this->assertSame($configured, $global->getOptions('uuid', 'de'), 'without excerpt switches the config decides for all events');

        $perEvent = new EventProfile($connection, $configured, true, $this->reader('{"pdf_enabled": true}'));
        $this->assertTrue($perEvent->getOptions('uuid', 'de')?->showCaptions, 'the excerpt values replace the config');

        $off = new EventProfile($connection, $configured, true, $this->reader('[]'));
        $this->assertNull($off->getOptions('uuid', 'de'));
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
