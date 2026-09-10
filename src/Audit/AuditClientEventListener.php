<?php

declare(strict_types=1);

namespace CarnetEquidad\Audit;

use CarnetEquidad\Api\ClientEventListener;

/**
 * Binds {@see \CarnetEquidad\Api\ApiDataCarnetClient} auth side-effects to the
 * audit trail, tagged with the current request's context so a token refresh /
 * auth error shares the same {@code request_id} as the consulta that triggered it.
 *
 * Pure PHP (no WordPress). A store failure is swallowed with {@code error_log()}
 * so audit never breaks the user-facing request.
 */
final class AuditClientEventListener implements ClientEventListener
{
    public function __construct(
        private readonly AuditLogger $logger,
        private readonly string $requestId,
        private readonly string $ip,
        private readonly string $cedula,
        private readonly string $codPla,
    ) {
    }

    public function tokenRefreshed(): void
    {
        $this->safeRecord(
            AuditEvent::tokenRefresh($this->requestId, $this->ip, $this->cedula, $this->codPla, 'token refreshed after 401')
        );
    }

    public function authRetryFailed(): void
    {
        $this->safeRecord(
            AuditEvent::authError($this->requestId, $this->ip, $this->cedula, $this->codPla, 'AuthException after retry')
        );
    }

    private function safeRecord(AuditEvent $event): void
    {
        try {
            $this->logger->record($event);
        } catch (\Throwable $e) {
            \error_log('[carnet-equidad] audit listener failed: ' . $e->getMessage());
        }
    }
}
