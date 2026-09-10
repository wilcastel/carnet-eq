<?php

declare(strict_types=1);

namespace CarnetEquidad\Http;

/**
 * {@see HttpTransport} implementation backed by the WordPress HTTP API
 * (`wp_remote_post`). Not exercised by unit tests.
 */
final class WpHttpTransport implements HttpTransport
{
    public function __construct(
        private readonly int $timeoutSeconds = 15,
    ) {
    }

    public function post(string $url, array $headers, string $body): HttpResponse
    {
        $response = \wp_remote_post(
            $url,
            [
                'timeout' => $this->timeoutSeconds,
                'headers' => $headers,
                'body' => $body,
                // The upstream lives on a private IP reachable only through the VPN.
                'redirection' => 0,
            ]
        );

        if (\is_wp_error($response)) {
            throw new TransportException($response->get_error_message());
        }

        $status = (int) \wp_remote_retrieve_response_code($response);
        $payload = (string) \wp_remote_retrieve_body($response);

        return new HttpResponse($status, $payload);
    }
}
