<?php

/**
 * Plugin Name:       Carnet Equidad
 * Plugin URI:        https://equidad.example/carnet-equidad
 * Description:        Public form to look up La Equidad insurance policy records by national ID (cédula) via the "Api Data Carnet" service. PDF carnet generation arrives in a later increment.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Equidad
 * License:           Proprietary
 * Text Domain:       carnet-equidad
 * Domain Path:       /languages
 *
 * @package CarnetEquidad
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('CARNET_EQUIDAD_VERSION', '0.1.0');
define('CARNET_EQUIDAD_PLUGIN_FILE', __FILE__);

$carnetEquidadAutoload = __DIR__ . '/vendor/autoload.php';
if (!is_readable($carnetEquidadAutoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Carnet Equidad: run "composer install" inside the plugin folder to load its dependencies.', 'carnet-equidad');
        echo '</p></div>';
    });

    return;
}

require $carnetEquidadAutoload;

register_activation_hook(__FILE__, [CarnetEquidad\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [CarnetEquidad\Plugin::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    (new CarnetEquidad\Plugin(CARNET_EQUIDAD_PLUGIN_FILE))->boot();
});
