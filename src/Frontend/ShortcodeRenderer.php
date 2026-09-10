<?php

declare(strict_types=1);

namespace CarnetEquidad\Frontend;

use CarnetEquidad\Rest\ConsultaController;

/**
 * Registers the [carnet_equidad_form] shortcode and, only on pages that use it,
 * enqueues a small vanilla-JS script + minimal CSS.
 */
final class ShortcodeRenderer
{
    public const SHORTCODE = 'carnet_equidad_form';
    public const HANDLE = 'carnet-equidad';

    private string $assetsUrl;
    private string $assetsPath;
    private string $version;

    public function __construct(string $pluginFile)
    {
        $this->assetsUrl = \plugin_dir_url($pluginFile) . 'assets/';
        $this->assetsPath = \plugin_dir_path($pluginFile) . 'assets/';
        $this->version = \defined('CARNET_EQUIDAD_VERSION') ? (string) \constant('CARNET_EQUIDAD_VERSION') : '0.1.0';
    }

    public function register(): void
    {
        \add_shortcode(self::SHORTCODE, [$this, 'render']);
        \add_action('wp_enqueue_scripts', [$this, 'registerAssets']);
    }

    /**
     * Register (but do not enqueue) the assets so the shortcode can enqueue them
     * on demand.
     */
    public function registerAssets(): void
    {
        \wp_register_style(
            self::HANDLE,
            $this->assetsUrl . 'css/carnet-equidad.css',
            [],
            $this->assetVersion('css/carnet-equidad.css')
        );

        \wp_register_script(
            self::HANDLE,
            $this->assetsUrl . 'js/carnet-equidad.js',
            [],
            $this->assetVersion('js/carnet-equidad.js'),
            true
        );

        \wp_localize_script(
            self::HANDLE,
            'carnetEquidadConfig',
            [
                'restUrl' => \esc_url_raw(\rest_url(ConsultaController::NAMESPACE . ConsultaController::ROUTE)),
                'nonce' => \wp_create_nonce('wp_rest'),
                'i18n' => [
                    'loading' => \__('Consultando…', 'carnet-equidad'),
                    'notFound' => \__('No se encontró información para el documento ingresado.', 'carnet-equidad'),
                    'error' => \__('No fue posible completar la consulta en este momento. Intenta nuevamente más tarde.', 'carnet-equidad'),
                    'rateLimited' => \__('Has realizado demasiadas consultas. Espera unos minutos e inténtalo de nuevo.', 'carnet-equidad'),
                    'invalid' => \__('Ingresa un número de documento válido (6 a 11 dígitos).', 'carnet-equidad'),
                    'consentRequired' => \__('Debes aceptar el tratamiento de datos personales para realizar la consulta.', 'carnet-equidad'),
                    'selectPrompt' => \__('Selecciona la póliza que deseas consultar:', 'carnet-equidad'),
                    'continue' => \__('Continuar', 'carnet-equidad'),
                    'branch' => \__('Sucursal', 'carnet-equidad'),
                    'certificate' => \__('Certificado', 'carnet-equidad'),
                    'policy' => \__('Póliza', 'carnet-equidad'),
                    'order' => \__('Orden', 'carnet-equidad'),
                    'validity' => \__('Vigencia', 'carnet-equidad'),
                    'chosen' => \__('Opción seleccionada', 'carnet-equidad'),
                ],
            ]
        );
    }

    public function render(mixed $atts = [], ?string $content = null): string
    {
        \wp_enqueue_style(self::HANDLE);
        \wp_enqueue_script(self::HANDLE);

        $fieldId = \esc_attr(\wp_unique_id('carnet-equidad-cedula-'));
        $consentId = \esc_attr(\wp_unique_id('carnet-equidad-consent-'));

        ob_start();
        ?>
        <div class="carnet-equidad" data-carnet-equidad>
            <form class="carnet-equidad__form" data-carnet-form novalidate>
                <label class="carnet-equidad__label" for="<?php echo $fieldId; ?>">
                    <?php echo \esc_html__('Número de documento (cédula)', 'carnet-equidad'); ?>
                </label>
                <input
                    class="carnet-equidad__input"
                    type="text"
                    inputmode="numeric"
                    autocomplete="off"
                    id="<?php echo $fieldId; ?>"
                    name="cedula"
                    data-carnet-cedula
                    minlength="6"
                    maxlength="20"
                    required
                />
                <div class="carnet-equidad__consent">
                    <input
                        type="checkbox"
                        id="<?php echo $consentId; ?>"
                        name="privacy_consent"
                        value="1"
                        data-carnet-consent
                        required
                    />
                    <label for="<?php echo $consentId; ?>">
                        <?php echo \esc_html__('Autorizo el tratamiento de mis datos personales conforme a la', 'carnet-equidad'); ?>
                        <a href="https://sftp.laequidadseguros.coop/2024/POLITICA%20DE%20TRATAMIENTO%20DE%20DATOS.pdf" target="_blank" rel="noopener noreferrer">
                            <?php echo \esc_html__('Política de Tratamiento de Datos Personales', 'carnet-equidad'); ?>
                        </a>.
                    </label>
                </div>
                <button type="submit" class="carnet-equidad__submit" data-carnet-submit>
                    <?php echo \esc_html__('Consultar', 'carnet-equidad'); ?>
                </button>
            </form>
            <div class="carnet-equidad__status" role="status" aria-live="polite" data-carnet-status hidden></div>
            <div class="carnet-equidad__results" data-carnet-results hidden></div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function assetVersion(string $relativePath): string
    {
        $file = $this->assetsPath . $relativePath;

        return \is_readable($file) ? (string) \filemtime($file) : $this->version;
    }
}
