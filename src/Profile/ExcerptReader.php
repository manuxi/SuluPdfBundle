<?php

declare(strict_types=1);

namespace Manuxi\SuluPdfBundle\Profile;

use Doctrine\DBAL\Connection;

/**
 * Reads the PDF switches that sulu_pdf.excerpt.* adds to the excerpt tab of a kind of content: they are stored in the
 * dimension content's excerpt data, so the PUBLISHED version (stage "live") of the requested language decides.
 */
final class ExcerptReader
{
    /**
     * @param string $table          dimension content table, e.g. pa_page_dimension_contents
     * @param string $idColumn       column with the uuid of the content
     * @param string $excerptColumn  JSON column with the excerpt data
     * @param string $modifiedColumn column with the editor-set modification date (may be empty)
     * @param string $changedColumn  column with the technical change date
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
        private readonly string $idColumn,
        private readonly string $excerptColumn,
        private readonly string $modifiedColumn,
        private readonly string $changedColumn,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function read(string $id, string $locale): array
    {
        $json = $this->connection->fetchOne(
            \sprintf('SELECT %s FROM %s WHERE %s = :id AND locale = :locale AND stage = :stage ORDER BY version DESC', $this->excerptColumn, $this->table, $this->idColumn),
            ['id' => $id, 'locale' => $locale, 'stage' => 'live'],
        );

        $data = \is_string($json) ? \json_decode($json, true) : null;

        return \is_array($data) ? $data : [];
    }

    public function modified(string $id, string $locale): ?\DateTimeInterface
    {
        $value = $this->connection->fetchOne(
            \sprintf('SELECT COALESCE(%s, %s) FROM %s WHERE %s = :id AND locale = :locale AND stage = :stage ORDER BY version DESC', $this->modifiedColumn, $this->changedColumn, $this->table, $this->idColumn),
            ['id' => $id, 'locale' => $locale, 'stage' => 'live'],
        );

        return $value ? new \DateTimeImmutable((string) $value) : null;
    }
}
