<?php

declare(strict_types=1);

namespace CarnetEquidad\Http;

/**
 * Server-side HTTP transport abstraction.
 *
 * Injecting this interface is what makes {@see \CarnetEquidad\Api\ApiDataCarnetClient}
 * unit-testable without a running WordPress: production uses {@see WpHttpTransport},
 * tests use an in-memory fake.
 */
interface HttpTransport
{
    /**
     * Perform a POST request.
     *
     * @param array<string, string> $headers
     *
     * @throws TransportException on a network/transport level failure (DNS, timeout, connection refused).
     */
    public function post(string $url, array $headers, string $body): HttpResponse;
}
