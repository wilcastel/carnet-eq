<?php

declare(strict_types=1);

namespace CarnetEquidad\Api;

use CarnetEquidad\Api\Exception\AuthException;
use CarnetEquidad\Api\Exception\NotFoundException;
use CarnetEquidad\Api\Exception\UpstreamException;
use CarnetEquidad\Http\HttpResponse;
use CarnetEquidad\Http\HttpTransport;
use CarnetEquidad\Http\TransportException;

/**
 * Server-side client for La Equidad's "Api Data Carnet".
 *
 * WordPress-free: HTTP and token caching are injected, so the whole class is
 * unit-testable with in-memory fakes.
 *
 * Not-found handling: a 404 from /dataAsegurado throws {@see NotFoundException}
 * (a sentinel), never a null return, so callers must handle the case explicitly.
 */
final class ApiDataCarnetClient
{
    /**
     * Fixed values from the working document. Marked there as still pending
     * formal confirmation (questions #8 and #10).
     */
    public const CONSUMER = 'Linktic';
    public const COD_PLA = '1821';

    public const TOKEN_CACHE_KEY = 'carnet_equidad_api_token';

    /**
     * Conservative token TTL. The real lifetime is unconfirmed — question #5 in
     * the working document ("¿Cuánto dura el token de /validarToken?").
     */
    public const TOKEN_TTL_SECONDS = 600;

    private const PATH_VALIDAR_TOKEN = '/api/v1/validarToken';
    private const PATH_DATA_ASEGURADO = '/api/v1/dataAsegurado';

    public function __construct(
        private readonly HttpTransport $transport,
        private readonly TokenStore $tokens,
        private readonly ClientConfig $config,
    ) {
    }

    /**
     * Return a valid bearer token, using the cached one when present and
     * requesting a fresh one from /validarToken otherwise. A freshly obtained
     * token is written back to the cache.
     *
     * @throws UpstreamException when /validarToken fails or omits the token.
     */
    public function getToken(): string
    {
        $cached = $this->tokens->get(self::TOKEN_CACHE_KEY);
        if ($cached !== null && $cached !== '') {
            return $cached;
        }

        return $this->requestFreshToken();
    }

    /**
     * Fetch the raw list of insurance records for a (already validated) national ID.
     *
     * @return list<array<string, mixed>> decoded upstream array (still contains
     *                                    every raw field — mapping/redaction is
     *                                    the caller's job, see {@see PolicyOptionMapper}).
     *
     * @throws NotFoundException when the upstream responds 404.
     * @throws AuthException     when authentication still fails after one retry.
     * @throws UpstreamException on any other non-2xx status or transport failure.
     */
    public function getDataAsegurado(string $cedula): array
    {
        $response = $this->sendDataAsegurado($cedula, $this->getToken());

        if ($response->status() === 401) {
            // Token rejected: drop it, re-authenticate and retry exactly once.
            $this->tokens->delete(self::TOKEN_CACHE_KEY);
            $response = $this->sendDataAsegurado($cedula, $this->requestFreshToken());

            if ($response->status() === 401) {
                throw new AuthException('Upstream rejected the credentials after a token refresh.');
            }
        }

        if ($response->status() === 404) {
            throw new NotFoundException('No insurance records found for the given document.');
        }

        if (!$response->isSuccessful()) {
            throw new UpstreamException(sprintf('Unexpected upstream status %d from dataAsegurado.', $response->status()));
        }

        try {
            $decoded = $response->json();
        } catch (\JsonException $e) {
            throw new UpstreamException('Unable to decode the dataAsegurado response body.', 0, $e);
        }

        return array_values($decoded);
    }

    private function requestFreshToken(): string
    {
        $body = json_encode(
            [
                'user' => $this->config->getUser(),
                'password' => $this->config->getPassword(),
            ],
            JSON_THROW_ON_ERROR
        );

        $response = $this->post(self::PATH_VALIDAR_TOKEN, $this->baseHeaders(), $body);

        if (!$response->isSuccessful()) {
            throw new UpstreamException(sprintf('Unexpected upstream status %d from validarToken.', $response->status()));
        }

        try {
            $payload = $response->json();
        } catch (\JsonException $e) {
            throw new UpstreamException('Unable to decode the validarToken response body.', 0, $e);
        }

        $token = $this->extractToken($payload);
        if ($token === null) {
            throw new UpstreamException('validarToken response did not contain a token.');
        }

        $this->tokens->set(self::TOKEN_CACHE_KEY, $token, self::TOKEN_TTL_SECONDS);

        return $token;
    }

    /**
     * The upstream returns the token under the literal key "token: "
     * (trailing space + colon). Accept a clean "token" key as a fallback.
     *
     * @param array<int|string, mixed> $payload
     */
    private function extractToken(array $payload): ?string
    {
        foreach (['token: ', 'token'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && trim($payload[$key]) !== '') {
                return trim($payload[$key]);
            }
        }

        return null;
    }

    private function sendDataAsegurado(string $cedula, string $token): HttpResponse
    {
        $body = json_encode(
            [
                'consumer' => self::CONSUMER,
                'doc_aseg' => $cedula,
                'cod_pla' => self::COD_PLA,
            ],
            JSON_THROW_ON_ERROR
        );

        $headers = $this->baseHeaders();
        $headers['Authorization'] = 'Bearer ' . $token;

        return $this->post(self::PATH_DATA_ASEGURADO, $headers, $body);
    }

    /**
     * @param array<string, string> $headers
     */
    private function post(string $path, array $headers, string $body): HttpResponse
    {
        try {
            return $this->transport->post($this->config->getBaseUrl() . $path, $headers, $body);
        } catch (TransportException $e) {
            throw new UpstreamException('Transport failure while contacting the upstream.', 0, $e);
        }
    }

    /**
     * @return array<string, string>
     */
    private function baseHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }
}
