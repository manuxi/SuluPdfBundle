<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Tests\Profile;

use Doctrine\DBAL\Connection;
use Manuxi\SuluPdfBundle\Model\PdfOptions;
use Manuxi\SuluPdfBundle\Profile\EventProfile;
use PHPUnit\Framework\TestCase;

class EventProfileTest extends TestCase
{
    /**
     * @param array<string, mixed>|null      $event
     * @param array<string, mixed>|null      $location
     */
    private function profile(?array $event, ?array $location = null, bool $enabled = true): EventProfile
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(
            // like DBAL: false when there is no row
            static fn (string $sql) => (\str_contains($sql, 'app_location') ? $location : $event) ?? false,
        );

        return new EventProfile($connection, new PdfOptions(showAuthor: false), $enabled);
    }

    public function testDateAndVenueBecomeMetaLines(): void
    {
        $profile = $this->profile(
            ['start_date' => '2026-11-12 09:00:00', 'end_date' => '2026-11-13 17:00:00', 'location_id' => 10, 'last_modified' => null, 'changed' => '2026-09-27 03:59:52'],
            ['name' => 'Test Hall', 'street' => 'Sample Street', 'number' => '1', 'postal_code' => '52062', 'city' => 'Aachen'],
        );

        $meta = $profile->getMeta('uuid', 'en');

        $this->assertCount(2, $meta);
        $this->assertStringContainsString('November 12, 2026', $meta[0]);
        $this->assertStringContainsString('November 13, 2026', $meta[0]);
        $this->assertSame('Test Hall, Sample Street 1, 52062 Aachen', $meta[1]);
    }

    public function testOneDayEventNamesTheDateOnce(): void
    {
        $profile = $this->profile(['start_date' => '2026-11-12 09:00:00', 'end_date' => '2026-11-12 17:30:00', 'location_id' => null, 'last_modified' => null, 'changed' => null]);

        $meta = $profile->getMeta('uuid', 'en');

        $this->assertCount(1, $meta);
        $this->assertSame(1, \substr_count($meta[0], '2026'));
        $this->assertStringContainsString('5:30', $meta[0]);
    }

    public function testNoEventNoMeta(): void
    {
        $this->assertSame([], $this->profile(null)->getMeta('uuid', 'de'));
    }

    public function testModifiedPrefersLastModified(): void
    {
        $profile = $this->profile(['start_date' => null, 'end_date' => null, 'location_id' => null, 'last_modified' => '2026-10-01 10:00:00', 'changed' => '2026-09-01 10:00:00']);

        $this->assertSame('2026-10-01', $profile->getModified('uuid', 'de')?->format('Y-m-d'));
    }

    public function testSwitchedOffHasNoPdf(): void
    {
        $this->assertNull($this->profile(null, null, false)->getOptions('uuid', 'de'));
        $this->assertNotNull($this->profile(null)->getOptions('uuid', 'de'));
    }
}
