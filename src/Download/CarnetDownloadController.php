<?php

declare(strict_types=1);

namespace CarnetEquidad\Download;

use CarnetEquidad\Api\ApiClientFactory;
use CarnetEquidad\Api\ApiDataCarnetClient;
use CarnetEquidad\Api\PolicyOptionMapper;
use CarnetEquidad\Api\Exception\AuthException;
use CarnetEquidad\Api\Exception\ConfigException;
use CarnetEquidad\Api\Exception\NotFoundException;
use CarnetEquidad\Api\Exception\UpstreamException;
use CarnetEquidad\Audit\AuditClientEventListener;
use CarnetEquidad\Audit\AuditEvent;
use CarnetEquidad\Audit\AuditLogger;
use CarnetEquidad\Audit\WpdbAuditStore;
use CarnetEquidad\Pdf\CarnetPdfGenerator;
use CarnetEquidad\Validation\CedulaValidator;

/** Public admin-post endpoint which streams an on-demand carnet PDF. */
final class CarnetDownloadController
{
    public const ACTION = 'carnet_equidad_download_pdf';
    public const NONCE_ACTION = 'carnet_equidad_download_pdf';

    public function __construct(
        private readonly string $templatePath,
        private ?ApiDataCarnetClient $client = null,
        private ?CedulaValidator $validator = null,
        private ?AuditLogger $auditLogger = null,
        private readonly PolicyOptionMapper $mapper = new PolicyOptionMapper(),
    ) {
    }

    public function register(): void
    {
        \add_action('admin_post_' . self::ACTION, [$this, 'handle']);
        \add_action('admin_post_nopriv_' . self::ACTION, [$this, 'handle']);
    }

    /**
     * Revalidates every browser input and fetches the policy list again before
     * creating bytes. A browser never provides policy details to the renderer.
     */
    public function handle(): never
    {
        if (! $this->hasConsent() || ! \wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), self::NONCE_ACTION)) {
            \wp_die(\esc_html__('No fue posible validar la solicitud del carnet.', 'carnet-equidad'), '', ['response' => 403]);
        }

        $cedula = $this->validator()->normalize((string) ($_POST['cedula'] ?? ''));
        $selected = filter_var($_POST['seleccion'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $selectionKey = (string) ($_POST['selection_key'] ?? '');
        if ($cedula === null || $selected === false || preg_match('/^[a-f0-9]{64}$/', $selectionKey) !== 1) {
            \wp_die(\esc_html__('La solicitud del carnet no es válida.', 'carnet-equidad'), '', ['response' => 400]);
        }

        $requestId = \wp_generate_uuid4();
        $ip = $this->clientIp();
        try {
            $records = $this->client($requestId, $ip, $cedula)->getDataAsegurado($cedula);
            $record = array_values($records)[$selected] ?? null;
            $freshOption = is_array($record) ? $this->mapper->mapRecord($record, $selected) : null;
            if (! is_array($record) || ! is_array($freshOption) || ! hash_equals($freshOption['key'], $selectionKey)) {
                throw new \InvalidArgumentException('Selected policy is not present in the fresh response.');
            }

            $pdf = (new CarnetPdfGenerator($this->templatePath))->generate($cedula, $record);
            $this->audit($requestId, $ip, $cedula, AuditEvent::RESULT_FOUND, 'pdf_generated');

            \nocache_headers();
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="carnet-equidad.pdf"');
            header('Content-Length: ' . strlen($pdf));
            header('X-Content-Type-Options: nosniff');
            echo $pdf;
            exit;
        } catch (NotFoundException | AuthException | UpstreamException | ConfigException | \InvalidArgumentException | \RuntimeException $e) {
            \error_log('[carnet-equidad] pdf generation failed: ' . $e->getMessage());
            $this->audit($requestId, $ip, $cedula, AuditEvent::RESULT_ERROR, 'pdf_generation_failed');
            \wp_die(\esc_html__('No fue posible generar el carnet en este momento. Intenta nuevamente más tarde.', 'carnet-equidad'), '', ['response' => 502]);
        }
    }

    private function hasConsent(): bool
    {
        return in_array($_POST['privacy_consent'] ?? null, [true, 1, '1', 'true'], true);
    }

    private function validator(): CedulaValidator
    {
        return $this->validator ??= new CedulaValidator(6, 11);
    }

    private function client(string $requestId, string $ip, string $cedula): ApiDataCarnetClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        return $this->client = ApiClientFactory::fromConstants(new AuditClientEventListener(
            $this->auditLogger ??= new AuditLogger(new WpdbAuditStore()),
            $requestId,
            $ip,
            $cedula,
            ApiDataCarnetClient::COD_PLA,
        ));
    }

    private function audit(string $requestId, string $ip, string $cedula, string $result, string $detail): void
    {
        try {
            ($this->auditLogger ??= new AuditLogger(new WpdbAuditStore()))->record(
                AuditEvent::carnetGenerated($requestId, $ip, $cedula, ApiDataCarnetClient::COD_PLA, $result, $detail)
            );
        } catch (\Throwable $e) {
            \error_log('[carnet-equidad] pdf audit record failed: ' . $e->getMessage());
        }
    }

    private function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
    }
}
