<?php

declare(strict_types=1);

namespace CarnetEquidad\Audit;

/**
 * {@see AuditStore} backed by the custom {@code {$wpdb->prefix}carnet_audit}
 * table. Not exercised by unit tests (same rationale as {@see \CarnetEquidad\Api\WpTransientTokenStore}).
 */
final class WpdbAuditStore implements AuditStore
{
    private const TABLE_SUFFIX = 'carnet_audit';

    /**
     * Prefix-aware physical table name. Exposed so {@see AuditSchema} and the
     * admin viewer reference exactly one source of truth.
     */
    public static function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * @param array<string, scalar|null> $row
     */
    public function insert(array $row): void
    {
        global $wpdb;

        $wpdb->insert(self::tableName(), $row, self::formatsFor($row));
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        global $wpdb;

        $table = self::tableName();
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s",
                $cutoff->format('Y-m-d H:i:s')
            )
        );

        return \is_numeric($deleted) ? (int) $deleted : 0;
    }

    /**
     * $wpdb->insert() format specifiers, one per column, in the row's key order.
     *
     * @param array<string, scalar|null> $row
     *
     * @return list<string>
     */
    private static function formatsFor(array $row): array
    {
        $intColumns = ['api_http'];

        $formats = [];
        foreach (array_keys($row) as $column) {
            $formats[] = \in_array($column, $intColumns, true) ? '%d' : '%s';
        }

        return $formats;
    }
}
