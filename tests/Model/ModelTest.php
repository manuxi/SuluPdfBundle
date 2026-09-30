<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Tests\Model;

use Manuxi\SuluPdfBundle\Model\PdfOptions;
use Manuxi\SuluPdfBundle\Model\PdfRules;
use Manuxi\SuluPdfBundle\Profile\ConfigProfile;
use Manuxi\SuluPdfBundle\Profile\ProfileRegistry;
use PHPUnit\Framework\TestCase;

class ModelTest extends TestCase
{
    public function testOptionsFromCamelOrSnakeCase(): void
    {
        $options = PdfOptions::fromArray(['show_captions' => false, 'showAuthor' => false, 'company_data' => 'footer']);

        $this->assertFalse($options->showCaptions);
        $this->assertFalse($options->showAuthor);
        $this->assertTrue($options->showModified);
        $this->assertTrue($options->showOnlineLink);
        $this->assertSame('footer', $options->companyData);
    }

    public function testOptionsIgnoreAnUnknownCompanyMode(): void
    {
        $this->assertSame('none', PdfOptions::fromArray(['companyData' => 'everywhere'])->companyData);
    }

    public function testRulesOverrideSingleKeysAndAddToLists(): void
    {
        $rules = new PdfRules(main: '.a', remove: ['.x'], excludeFigures: ['.q']);

        $merged = $rules->with(['main' => '.b', 'title' => null, 'remove' => ['.y', '.x'], 'exclude_figures' => ['.r'], 'unknown' => 'ignored']);

        $this->assertSame('.b', $merged->main);
        $this->assertSame('h1', $merged->title, 'null keeps the default');
        $this->assertSame(['.x', '.y'], $merged->remove);
        $this->assertSame(['.q', '.r'], $merged->excludeFigures);
    }

    public function testRegistryFindsProfilesAndAppliesOverrides(): void
    {
        $events = new ConfigProfile('events', 'events', true, new PdfOptions(), new PdfRules(main: '.a'));
        $off = new ConfigProfile('pages', 'pages', false, new PdfOptions(), new PdfRules());
        $registry = new ProfileRegistry([$events, $off], ['events' => ['main' => '.event-main']]);

        $this->assertSame($events, $registry->get('events'));
        $this->assertNull($registry->get('unknown'));
        $this->assertSame('.event-main', $registry->rules($events)->main);
        $this->assertSame('main', $registry->rules($off)->main);

        $this->assertNotNull($events->getOptions('1', 'de'));
        $this->assertNull($off->getOptions('1', 'de'), 'a disabled profile has no PDF');
    }
}
