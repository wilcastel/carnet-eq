<?php

declare(strict_types=1);

namespace CarnetEquidad\Api;

/**
 * Observer for the authentication side-effects of {@see ApiDataCarnetClient}.
 *
 * §6 of the working document requires an audit trail for "errores 401 y refrescos
 * de token". The client stays WordPress-free; a listener bound to the current
 * request is what turns these hooks into audit rows (see
 * {@see \CarnetEquidad\Audit\AuditClientEventListener}).
 *
 * The default no-op implementation is {@see NullClientEventListener} (its own file
 * so PSR-4 autoloading resolves it).
 */
interface ClientEventListener
{
    /**
     * A fresh token was obtained on the 401-retry path (NOT the first cold-cache
     * fetch).
     */
    public function tokenRefreshed(): void;

    /**
     * A second 401 was received after the token refresh; the client is about to
     * throw {@see \CarnetEquidad\Api\Exception\AuthException}.
     */
    public function authRetryFailed(): void;
}
