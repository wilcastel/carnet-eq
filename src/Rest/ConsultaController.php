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
use CarnetEquidad\RateLimit\RateLimiter;
use CarnetEquidad\RateLimit\WpTransientRateStore;
use CarnetEquidad\Validation\CedulaValidator;

/**
 * REST route: POST /wp-json/carnet/v1/consulta
 *
 * Body: { "cedula": "..." }
 *
 * Responses:
 *   - 200 { "status": "found", "asegurado": "...", "opciones": [ ... ] }
 *   - 200 { "status": "not_found" }
 *   - 400 { "status": "invalid", "message": "..." }         (bad cedula format)
 *   - 429 { "status": "rate_limited", "message": "..." }     (per-IP fixed-window limit hit)
 *   - 502 { "status": "error", "message": "<generic>" }      (upstream/auth/config failure)
 *
 * The endpoint is intentionally public for this increment. Abuse is contained by
 * a per-IP fixed-window rate limiter (see {@see RateLimiter}) applied before any
 * validation or upstream call.
 * TODO: CAPTCHA before production.
 */
final class ConsultaController
{
    public const NAMESPACE = 'carnet/v1';
    public const ROUTE = '/consulta';

    private const RATE_LIMIT_DEFAULTS = ['limit' => 10, 'window' => 600];
    private const CEDULA_LENGTH_DEFAULTS = ['min' => 6, 'max' => 11];

    private ?ApiDataCarnetClient $client;
    private ?CedulaValidator $validator;
    private ?RateLimiter $rateLimiter;

    public function __construct(
        ?ApiDataCarnetClient $client = null,
        ?CedulaValidator $validator = null,
        private readonly PolicyOptionMapper $mapper = new PolicyOptionMapper(),
        ?RateLimiter $rateLimiter = null,
    ) {
        $this->client = $client;
        $this->validator = $validator;
        $this->rateLimiter = $rateLimiter;
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
                ],
            ]
        );
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $result = $this->rateLimiter()->attempt('ip:' . $this->clientIp());

        if (! $result->allowed) {
            \error_log('[carnet-equidad] rate limited: ' . substr(hash('sha256', $this->clientIp()), 0, 12));

            $response = new \WP_REST_Response(
                [
                    'status' => 'rate_limited',
                    'message' => \__('Has realizado demasiadas consultas. Espera unos minutos e inténtalo de nuevo.', 'carnet-equidad'),
                ],
                429
            );
            $response->header('Retry-After', (string) $result->retryAfter);

            return $response;
        }

        $cedula = $this->validator()->normalize((string) $request->get_param('cedula'));

        if ($cedula === null) {
            return new \WP_REST_Response(
                [
                    'status' => 'invalid',
                    'message' => \__('El documento ingresado no tiene un formato válido.', 'carnet-equidad'),
                ],
                400
            );
        }

        try {
            $records = $this->client()->getDataAsegurado($cedula);
        } catch (NotFoundException) {
            return new \WP_REST_Response(['status' => 'not_found'], 200);
        } catch (AuthException | UpstreamException | ConfigException $e) {
            // Log server-side only; never leak upstream detail to the browser.
            \error_log('[carnet-equidad] consulta failed: ' . $e->getMessage());

            return new \WP_REST_Response(
                [
                    'status' => 'error',
                    'message' => \__('No fue posible completar la consulta en este momento. Intenta nuevamente más tarde.', 'carnet-equidad'),
                ],
                502
            );
        }

        if ($records === []) {
            return new \WP_REST_Response(['status' => 'not_found'], 200);
        }

        return new \WP_REST_Response(
            [
                'status' => 'found',
                'asegurado' => $this->mapper->aseguradoName($records),
                'opciones' => $this->mapper->mapAll($records),
            ],
            200
        );
    }

    private function client(): ApiDataCarnetClient
    {
        return $this->client ??= ApiClientFactory::fromConstants();
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
