<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Profile;

use Doctrine\DBAL\Connection;
use Manuxi\SuluPdfBundle\Model\PdfOptions;
use Manuxi\SuluPdfBundle\Model\PdfRules;

/**
 * Events of manuxi/sulu-event-bundle. Registered automatically when that bundle is installed; the options and the
 * on/off switch come from sulu_pdf.profiles.events (an event has no switches of its own). Date and venue are printed
 * as header lines from the event's own data (PdfMetaProviderInterface).
 */
final class EventProfile implements PdfProfileInterface, PdfMetaProviderInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PdfOptions $options,
        private readonly bool $enabled = true,
    ) {
    }

    public function getName(): string
    {
        return 'events';
    }

    public function getResourceKey(): string
    {
        return 'events';
    }

    public function getDefaultRules(): PdfRules
    {
        return new PdfRules();
    }

    public function getOptions(string $id, string $locale): ?PdfOptions
    {
        return $this->enabled ? $this->options : null;
    }

    public function getModified(string $id, string $locale): ?\DateTimeInterface
    {
        $row = $this->liveRow($id, $locale);
        $value = $row['last_modified'] ?? $row['changed'] ?? null;

        return $value ? new \DateTimeImmutable((string) $value) : null;
    }

    public function getMeta(string $id, string $locale): array
    {
        $row = $this->liveRow($id, $locale);
        if (!$row) {
            return [];
        }

        $meta = [];
        if (!empty($row['start_date'])) {
            $meta[] = $this->period(new \DateTimeImmutable((string) $row['start_date']), !empty($row['end_date']) ? new \DateTimeImmutable((string) $row['end_date']) : null, $locale);
        }

        if (!empty($row['location_id'])) {
            $location = $this->connection->fetchAssociative(
                'SELECT name, street, number, postal_code, city FROM app_location WHERE id = :id',
                ['id' => $row['location_id']],
            );
            if ($location) {
                $line = \implode(', ', \array_filter([
                    \trim((string) $location['name']),
                    \trim($location['street'] . ' ' . $location['number']),
                    \trim($location['postal_code'] . ' ' . $location['city']),
                ]));
                if ('' !== $line) {
                    $meta[] = $line;
                }
            }
        }

        return $meta;
    }

    /**
     * "12. November 2026, 09:00 - 17:00" on one day, "12. November 2026, 09:00 - 13. November 2026, 17:00" otherwise.
     */
    private function period(\DateTimeInterface $start, ?\DateTimeInterface $end, string $locale): string
    {
        $dateTime = new \IntlDateFormatter($locale, \IntlDateFormatter::LONG, \IntlDateFormatter::SHORT);
        $time = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::SHORT);

        if (!$end || $end == $start) {
            return $dateTime->format($start);
        }

        return $start->format('Y-m-d') === $end->format('Y-m-d')
            ? $dateTime->format($start) . ' - ' . $time->format($end)
            : $dateTime->format($start) . ' - ' . $dateTime->format($end);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function liveRow(string $id, string $locale): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT start_date, end_date, location_id, last_modified, changed FROM app_event_dimension_content WHERE event_uuid = :id AND locale = :locale AND stage = :stage ORDER BY version DESC',
            ['id' => $id, 'locale' => $locale, 'stage' => 'live'],
        );

        return $row ?: null;
    }
}
