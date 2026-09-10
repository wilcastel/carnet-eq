<?php

declare(strict_types=1);

namespace CarnetEquidad\Tests\Fakes;

use CarnetEquidad\Audit\AuditStore;

/**
 * In-memory {@see AuditStore} for unit tests.
 *
 * Inserted rows are kept verbatim in {@see self::$rows}. {@see self::deleteOlderThan()}
 * compares the stored {@code created_at} string ("Y-m-d H:i:s", lexicographically
 * ordered) against the cutoff.
 */
final class FakeAuditStore implements AuditStore
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public int $insertCalls = 0;

    public function insert(array $row): void
    {
        $this->rows[] = $row;
        $this->insertCalls++;
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        $cut = $cutoff->format('Y-m-d H:i:s');
        $before = \count($this->rows);

        $this->rows = array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => (string) ($row['created_at'] ?? '') >= $cut,
        ));

        return $before - \count($this->rows);
    }
}
