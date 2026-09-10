<?php

declare(strict_types=1);

namespace CarnetEquidad\Rest;

use CarnetEquidad\Api\ApiClientFactory;
use CarnetEquidad\Api\ApiDataCarnetClient;
use CarnetEquidad\Api\Exception\AuthException;
use CarnetEquidad\Api\Exception\ConfigException;
use CarnetEquidad\Api\Exception\NotFoundException;
use CarnetEquidad\Api\Exception\UpstreamException;
use CarnetEquidad\Api\PolicyOptionMapper;
use CarnetEquidad\Audit\AuditClientEventListener;
use CarnetEquidad\Audit\AuditEvent;
use CarnetEquidad\Audit\AuditLogger;
use CarnetEquidad\Audit\WpdbAuditStore;
use CarnetEquidad\RateLimit\RateLimiter;
use CarnetEquidad\RateLimit\WpTransientRateStore;
use CarnetEquidad\Validation\CedulaValidator;

/**
 * REST route: POST /wp-json/carnet/v1/consulta
 *
 * Body: { "cedula": "...", "privacy_consent": true }
 *
 * Every response carries a {@code "ref"} field: the per-request UUID v4 that also
 * keys the audit row(s) written for the call (see {@see AuditLogger}).
 *
 * Responses:
 *   - 200 { "status": "found", "asegurado": "...", "opciones": [ ... ], "ref": "..." }
 *   - 200 { "status": "not_found", "ref": "..." }
 *   - 400 { "status": "invalid", "message": "...", "ref": "..." }     (bad cedula format)
 *   - 429 { "status": "rate_limited", "message": "...", "ref": "..." } (per-IP fixed-window limit hit)
 *   - 502 { "status": "error", "message": "<generic>", "ref": "..." } (upstream/auth/config failure)
 *
 * The endpoint is intentionally public for this increment. Abuse is contained by
 * a per-IP fixed-window rate limiter (see {@see RateLimiter}) applied after the
 * required privacy-consent check and before any document validation or upstream
 * call.
 * TODO: CAPTCHA before production.
 */
final class ConsultaController
{
    public const NAMESPACE = 'carnet/v1';
    public const ROUTE = '/consulta';

    private const RATE_LIMIT_DEFAULTS = ['limit' => 10, 'window' => 600];
    private const CEDULA_LENGTH_DEFAULTS = ['min' => 6, 'max' => 11];

    /** Longest raw cedula we will ever store when the submitted value is invalid. */
    private const RAW_CEDULA_AUDIT_MAX = 40;

    private ?ApiDataCarnetClient $client;
    private ?CedulaValidator $validator;
    private ?RateLimiter $rateLimiter;
    private ?AuditLogger $auditLogger;

    public function __construct(
        ?ApiDataCarnetClient $client = null,
        ?CedulaValidator $validator = null,
        private readonly PolicyOptionMapper $mapper = new PolicyOptionMapper(),
        ?RateLimiter $rateLimiter = null,
        ?AuditLogger $auditLogger = null,
    ) {
        $this->client = $client;
        $this->validator = $validator;
        $this->rateLimiter = $rateLimiter;
        $this->auditLogger = $auditLogger;
    }

    public function register(): void
    {
        \register_rest_route(
            self::NAMESPACE,
            self::ROUTE,
            [
                'methods' => 'POST',
                'callback' => [$this, 'handle'],
                // TODO: CAPTCHA before production. Public on purpose for now; a
                // per-IP rate limiter runs inside handle() and the JS client
                // still sends the wp_rest nonce so we can tighten this later.
                'permission_callback' => '__return_true',
                'args' => [
                    'cedula' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                    'privacy_consent' => [
                        'required' => true,
                        'type' => 'boolean',
                    ],
                ],
            ]
        );
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        if (! $this->hasPrivacyConsent($request)) {
            return new \WP_REST_Response(
                [
                    'status' => 'consent_required',
                    'message' => \__('Debes aceptar el tratamiento de datos personales para realizar la consulta.', 'carnet-equidad'),
                ],
                400
            );
        }

        $requestId = \wp_generate_uuid4();
        $ip = $this->clientIp();
        $codPla = ApiDataCarnetClient::COD_PLA;

        $result = $this->rateLimiter()->attempt('ip:' . $ip);

        if (! $result->allowed) {
            \error_log('[carnet-equidad] rate limited: ' . substr(hash('sha256', $ip), 0, 12));

            $this->audit($requestId, $ip, $this->rawCedula($request), $codPla, AuditEvent::RESULT_RATE_LIMITED, null, 'per-IP fixed window');

            $response = new \WP_REST_Response(
                [
                    'status' => 'rate_limited',
                    'message' => \__('Has realizado demasiadas consultas. Espera unos minutos e inténtalo de nuevo.', 'carnet-equidad'),
                    'ref' => $requestId,
                ],
                429
            );
            $response->header('Retry-After', (string) $result->retryAfter);

            return $response;
        }

        $rawCedula = (string) $request->get_param('cedula');
        $cedula = $this->validator()->normalize($rawCedula);

        if ($cedula === null) {
            // Keep a truncated copy of the raw submission so the audit trail still
            // has something; never store more than RAW_CEDULA_AUDIT_MAX chars.
            $this->audit($requestId, $ip, $this->truncateRawCedula($rawCedula), $codPla, AuditEvent::RESULT_INVALID, null, 'invalid cedula format');

            return new \WP_REST_Response(
                [
                    'status' => 'invalid',
                    'message' => \__('El documento ingresado no tiene un formato válido.', 'carnet-equidad'),
                    'ref' => $requestId,
                ],
                400
            );
        }

        try {
            $records = $this->client($requestId, $ip, $cedula)->getDataAsegurado($cedula);
        } catch (NotFoundException) {
            $this->audit($requestId, $ip, $cedula, $codPla, AuditEvent::RESULT_NOT_FOUND, 404);

            return new \WP_REST_Response(['status' => 'not_found', 'ref' => $requestId], 200);
        } catch (AuthException | UpstreamException | ConfigException $e) {
            // Log server-side only; never leak upstream detail to the browser.
            \error_log('[carnet-equidad] consulta failed: ' . $e->getMessage());

            $this->audit($requestId, $ip, $cedula, $codPla, AuditEvent::RESULT_ERROR, null, $this->shortClassName($e));

            return new \WP_REST_Response(
                [
                    'status' => 'error',
                    'message' => \__('No fue posible completar la consulta en este momento. Intenta nuevamente más tarde.', 'carnet-equidad'),
                    'ref' => $requestId,
                ],
                502
            );
        }

        if ($records === []) {
            $this->audit($requestId, $ip, $cedula, $codPla, AuditEvent::RESULT_NOT_FOUND, 404);

            return new \WP_REST_Response(['status' => 'not_found', 'ref' => $requestId], 200);
        }

        $this->audit($requestId, $ip, $cedula, $codPla, AuditEvent::RESULT_FOUND, 200);

        return new \WP_REST_Response(
            [
                'status' => 'found',
                'asegurado' => $this->mapper->aseguradoName($records),
                'opciones' => $this->mapper->mapAll($records),
                'ref' => $requestId,
            ],
            200
        );
    }

    /**
     * Record one {@code query} audit event. A logging failure must never break
     * the user response.
     */
    private function audit(
        string $requestId,
        string $ip,
        string $cedula,
        string $codPla,
        string $result,
        ?int $apiHttp = null,
        ?string $detail = null,
    ): void {
        try {
            $this->auditLogger()->record(
                AuditEvent::query($requestId, $ip, $cedula, $codPla, $result, $apiHttp, $detail)
            );
        } catch (\Throwable $e) {
            \error_log('[carnet-equidad] audit record failed: ' . $e->getMessage());
        }
    }

    private function client(string $requestId, string $ip, string $cedula): ApiDataCarnetClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $listener = new AuditClientEventListener(
            $this->auditLogger(),
            $requestId,
            $ip,
            $cedula,
            ApiDataCarnetClient::COD_PLA,
        );

        return $this->client = ApiClientFactory::fromConstants($listener);
    }

    private function auditLogger(): AuditLogger
    {
        return $this->auditLogger ??= new AuditLogger(new WpdbAuditStore());
    }

    private function rawCedula(\WP_REST_Request $request): string
    {
        return $this->truncateRawCedula((string) $request->get_param('cedula'));
    }

    /**
     * Accept only explicit affirmative boolean representations. This is kept
     * separate from route argument validation so direct controller calls cannot
     * bypass the privacy requirement.
     */
    private function hasPrivacyConsent(\WP_REST_Request $request): bool
    {
        return \in_array($request->get_param('privacy_consent'), [true, 1, '1', 'true'], true);
    }

    private function truncateRawCedula(string $raw): string
    {
        return substr($raw, 0, self::RAW_CEDULA_AUDIT_MAX);
    }

    private function shortClassName(\Throwable $e): string
    {
        $parts = explode('\\', $e::class);

        return (string) end($parts);
    }

    private function validator(): CedulaValidator
    {
        if ($this->validator === null) {
            [$min, $max] = $this->cedulaLengthBounds();
            $this->validator = new CedulaValidator($min, $max);
        }

        return $this->validator;
    }

    private function rateLimiter(): RateLimiter
    {
        if ($this->rateLimiter === null) {
            [$limit, $window] = $this->rateLimitConfig();
            $this->rateLimiter = new RateLimiter(new WpTransientRateStore(), $limit, $window);
        }

        return $this->rateLimiter;
    }

    /**
     * Fixed-window limit / window (seconds), from the `carnet_equidad_rate_limit`
     * filter. Read defensively: cast to int, clamp both to >= 1, fall back to the
     * defaults on a malformed filter return.
     *
     * @return array{0: int, 1: int}
     */
    private function rateLimitConfig(): array
    {
        $filtered = \apply_filters('carnet_equidad_rate_limit', self::RATE_LIMIT_DEFAULTS);

        if (! is_array($filtered)) {
            return [self::RATE_LIMIT_DEFAULTS['limit'], self::RATE_LIMIT_DEFAULTS['window']];
        }

        $limit = isset($filtered['limit']) ? (int) $filtered['limit'] : self::RATE_LIMIT_DEFAULTS['limit'];
        $window = isset($filtered['window']) ? (int) $filtered['window'] : self::RATE_LIMIT_DEFAULTS['window'];

        return [max(1, $limit), max(1, $window)];
    }

    /**
     * Cedula digit-count bounds, from the `carnet_equidad_cedula_length` filter.
     * Read defensively; {@see CedulaValidator} coerces any leftover degenerate
     * pair (min < 1, or max < min) internally.
     *
     * @return array{0: int, 1: int}
     */
    private function cedulaLengthBounds(): array
    {
        $filtered = \apply_filters('carnet_equidad_cedula_length', self::CEDULA_LENGTH_DEFAULTS);

        if (! is_array($filtered)) {
            return [self::CEDULA_LENGTH_DEFAULTS['min'], self::CEDULA_LENGTH_DEFAULTS['max']];
        }

        $min = isset($filtered['min']) ? (int) $filtered['min'] : self::CEDULA_LENGTH_DEFAULTS['min'];
        $max = isset($filtered['max']) ? (int) $filtered['max'] : self::CEDULA_LENGTH_DEFAULTS['max'];

        return [$min, $max];
    }

    /**
     * Best-effort client IP for rate limiting.
     *
     * X-Forwarded-For is deliberately NOT trusted here: on a direct connection it
     * is attacker-controlled and would let a caller mint unlimited buckets.
     * Proxy / CDN deployments that terminate upstream should override the value
     * via the `carnet_equidad_client_ip` filter.
     */
    private function clientIp(): string
    {
        $ip = \apply_filters('carnet_equidad_client_ip', $_SERVER['REMOTE_ADDR'] ?? '');
        $ip = \sanitize_text_field((string) $ip);

        return $ip === '' ? 'unknown' : $ip;
    }
}
