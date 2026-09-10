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

// Nothing else is persisted by this increment: no options, no custom tables,
// no user meta. Credentials live in wp-config.php and are never touched here.
