<?php

/**
 * Uninstall cleanup for Carnet Equidad.
 *
 * @package CarnetEquidad
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Cached upstream token (see CarnetEquidad\Api\ApiDataCarnetClient::TOKEN_CACHE_KEY).
delete_transient('carnet_equidad_api_token');

// Daily audit-prune cron (see CarnetEquidad\Plugin::PRUNE_AUDIT_HOOK).
wp_clear_scheduled_hook('carnet_equidad_prune_audit');

// Schema revision marker (see CarnetEquidad\Plugin::DB_VERSION_OPTION).
delete_option('carnet_equidad_db_version');

// Structured audit table (see CarnetEquidad\Audit\WpdbAuditStore). Dropped on
// uninstall only — deactivation keeps it for Habeas Data continuity.
global $wpdb;
$carnetEquidadAuditTable = $wpdb->prefix . 'carnet_audit';
$wpdb->query("DROP TABLE IF EXISTS {$carnetEquidadAuditTable}");

// Credentials live in wp-config.php and are never touched here.
