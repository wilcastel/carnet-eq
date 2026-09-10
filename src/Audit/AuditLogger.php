<?php

declare(strict_types=1);

namespace CarnetEquidad\Audit;

/**
 * Turns {@see AuditEvent}s into audit rows and prunes old ones.
 *
 * Pure class: no WordPress dependency. The row sink is injected via
 * {@see AuditStore} and the clock via an optional callable, so the whole class is
 * unit-testable with an in-memory fake and a frozen clock.
 */
final class AuditLogger
{
    /** Never prune with a window shorter than this — a bad config must not wipe the table. */
    private const MIN_RETENTION_DAYS = 1;

    /** @var callable(): \DateTimeImmutable */
    private $now;

    /**
     * @param callable(): \DateTimeImmutable|null $now defaults to "now" in UTC.
     */
    public function __construct(
        private readonly AuditStore $store,
        ?callable $now = null,
    ) {
        $this->now = $now ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function record(AuditEvent $event): void
    {
        $this->store->insert([
            'request_id' => $event->requestId,
            'created_at' => ($this->now)()->format('Y-m-d H:i:s'),
            'ip' => $event->ip,
            'cedula' => $event->cedula,
            'cod_pla' => $event->codPla,
            'result' => $event->result,
            'api_http' => $event->apiHttp,
            'event_type' => $event->eventType,
            'detail' => $event->detail,
        ]);
    }

    /**
     * Delete audit rows older than {@code $retentionDays} days before "now".
     *
     * A non-positive retention is coerced to {@see self::MIN_RETENTION_DAYS} so a
     * misconfigured filter can never delete every row.
     *
     * @return int rows removed
     */
    public function prune(int $retentionDays): int
    {
        $days = max(self::MIN_RETENTION_DAYS, $retentionDays);
        $cutoff = ($this->now)()->sub(new \DateInterval('P' . $days . 'D'));

        return $this->store->deleteOlderThan($cutoff);
    }
}
