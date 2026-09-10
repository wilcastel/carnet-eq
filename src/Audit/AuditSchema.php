<?php

declare(strict_types=1);

namespace CarnetEquidad\Audit;

/**
 * Creates / migrates the {@code {$wpdb->prefix}carnet_audit} table via
 * {@see dbDelta()}. Called from the plugin activation hook. Not unit-tested
 * (WordPress + schema only).
 *
 * Columns {@code poliza}, {@code orden} and {@code file_hash} are provisioned now
 * for a later increment and stay NULL until then.
 */
final class AuditSchema
{
    public static function create(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = WpdbAuditStore::tableName();
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id CHAR(36) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
            ip VARCHAR(45) NOT NULL DEFAULT '',
            cedula VARCHAR(40) NOT NULL DEFAULT '',
            cod_pla VARCHAR(12) NOT NULL DEFAULT '',
            result VARCHAR(20) DEFAULT NULL,
            api_http SMALLINT UNSIGNED DEFAULT NULL,
            event_type VARCHAR(20) NOT NULL DEFAULT 'query',
            detail VARCHAR(255) DEFAULT NULL,
            poliza VARCHAR(40) DEFAULT NULL,
            orden VARCHAR(20) DEFAULT NULL,
            file_hash CHAR(64) DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY request_id (request_id)
        ) {$charsetCollate};";

        \dbDelta($sql);
    }
}
