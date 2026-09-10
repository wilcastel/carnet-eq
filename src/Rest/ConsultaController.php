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
use CarnetEquidad\Validation\CedulaValidator;

/**
 * REST route: POST /wp-json/carnet/v1/consulta
 *
 * Body: { "cedula": "..." }
 *
 * Responses:
 *   - 200 { "status": "found", "asegurado": "...", "opciones": [ ... ] }
 *   - 200 { "status": "not_found" }
 *   - 400 { "status": "invalid", "message": "..." }        (bad cedula format)
 *   - 502 { "status": "error", "message": "<generic>" }    (upstream/auth/config failure)
 *
 * The endpoint is intentionally public for this increment.
 * TODO: rate-limit + CAPTCHA before this goes to production.
 */
final class ConsultaController
{
    public const NAMESPACE = 'carnet/v1';
    public const ROUTE = '/consulta';

    private ?ApiDataCarnetClient $client;

    public function __construct(
        ?ApiDataCarnetClient $client = null,
        private readonly CedulaValidator $validator = new CedulaValidator(),
        private readonly PolicyOptionMapper $mapper = new PolicyOptionMapper(),
    ) {
        $this->client = $client;
    }

    public function register(): void
    {
        \register_rest_route(
            self::NAMESPACE,
            self::ROUTE,
            [
                'methods' => 'POST',
                'callback' => [$this, 'handle'],
                // TODO: rate-limit + CAPTCHA. Public on purpose for now; the JS
                // client still sends the wp_rest nonce so we can tighten this later.
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
        $cedula = $this->validator->normalize((string) $request->get_param('cedula'));

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
}
