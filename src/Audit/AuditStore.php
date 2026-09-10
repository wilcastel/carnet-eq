<?php

declare(strict_types=1);

namespace CarnetEquidad\Audit;

/**
 * Persistence seam for audit rows so {@see AuditLogger} stays WordPress-free and
 * unit-testable. Production uses {@see WpdbAuditStore} (a custom {@code $wpdb}
 * table); tests use an in-memory fake.
 */
interface AuditStore
{
    /**
     * Append one audit row. Keys are the physical column names produced by
     * {@see AuditLogger::record()}.
     *
     * @param array<string, scalar|null> $row
     */
    public function insert(array $row): void;

    /**
     * Delete every row whose {@code created_at} is strictly before {@code $cutoff}.
     *
     * @return int number of rows removed
     */
    public function deleteOlderThan(\DateTimeImmutable $cutoff): int;
}
