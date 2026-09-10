<?php

declare(strict_types=1);

namespace CarnetEquidad;

use CarnetEquidad\Api\ApiDataCarnetClient;
use CarnetEquidad\Frontend\ShortcodeRenderer;
use CarnetEquidad\Rest\ConsultaController;

/**
 * Plugin composition root. Wires WordPress hooks to plugin services.
 */
final class Plugin
{
    public function __construct(
        private readonly string $pluginFile,
    ) {
    }

    public function boot(): void
    {
        \add_action('init', [new ShortcodeRenderer($this->pluginFile), 'register']);
        \add_action('rest_api_init', [new ConsultaController(), 'register']);
    }

    /**
     * Activation hook. Minimal: nothing to provision for this increment.
     */
    public static function activate(): void
    {
        // Intentionally empty. No custom tables or options are needed yet.
    }

    /**
     * Deactivation hook. Drop the cached upstream token so a disabled plugin
     * leaves nothing stale behind.
     */
    public static function deactivate(): void
    {
        \delete_transient(ApiDataCarnetClient::TOKEN_CACHE_KEY);
    }
}
