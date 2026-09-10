<?php

declare(strict_types=1);

namespace CarnetEquidad\Api;

use CarnetEquidad\Http\WpHttpTransport;

/**
 * Wires an {@see ApiDataCarnetClient} with its WordPress-backed collaborators.
 *
 * Kept separate from the client so the client itself stays WordPress-free and
 * unit-testable. Called lazily from the REST handler, never at plugin load, so a
 * missing credential constant only breaks the one request instead of the whole
 * REST API.
 */
final class ApiClientFactory
{
    /**
     * @param ClientEventListener|null $events optional listener bound to the
     *        current request (audit trail for token refresh / auth errors).
     *        Defaults to a no-op so the no-argument call keeps working.
     */
    public static function fromConstants(?ClientEventListener $events = null): ApiDataCarnetClient
    {
        return new ApiDataCarnetClient(
            new WpHttpTransport(),
            new WpTransientTokenStore(),
            ClientConfig::fromConstants(),
            $events ?? new NullClientEventListener(),
        );
    }
}
