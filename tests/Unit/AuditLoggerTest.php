<?php

declare(strict_types=1);

namespace CarnetEquidad\Tests\Unit;

use CarnetEquidad\Audit\AuditEvent;
use CarnetEquidad\Audit\AuditLogger;
use CarnetEquidad\Tests\Fakes\FakeAuditStore;
use PHPUnit\Framework\TestCase;

final class AuditLoggerTest extends TestCase
{
    private FakeAuditStore $store;

    /** Frozen clock for the logger. */
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->store = new FakeAuditStore();
        $this->now = new \DateTimeImmutable('2026-09-09 12:00:00', new \DateTimeZone('UTC'));
    }

    private function logger(): AuditLogger
    {
        return new AuditLogger($this->store, fn (): \DateTimeImmutable => $this->now);
    }

    public function testRecordMapsAQueryEventToAFullRow(): void
    {
        $this->logger()->record(
            AuditEvent::query('req-1', '10.0.0.1', '1094290592', '1821', AuditEvent::RESULT_FOUND, 200)
        );

        self::assertSame(1, $this->store->insertCalls);
        self::assertCount(1, $this->store->rows);

        self::assertSame(
            [
                'request_id' => 'req-1',
                'created_at' => '2026-09-09 12:00:00',
                'ip' => '10.0.0.1',
                'cedula' => '1094290592',
                'cod_pla' => '1821',
                'result' => 'found',
                'api_http' => 200,
                'event_type' => 'query',
                'detail' => null,
            ],
            $this->store->rows[0],
        );
    }

    public function testRecordMapsTokenRefreshAndAuthErrorEvents(): void
    {
        $logger = $this->logger();
        $logger->record(AuditEvent::tokenRefresh('req-2', '10.0.0.2', '123', '1821', 'token refreshed after 401'));
        $logger->record(AuditEvent::authError('req-3', '10.0.0.3', '456', '1821', 'AuthException after retry'));

        self::assertSame('token_refresh', $this->store->rows[0]['event_type']);
        self::assertNull($this->store->rows[0]['result']);
        self::assertNull($this->store->rows[0]['api_http']);
        self::assertSame('token refreshed after 401', $this->store->rows[0]['detail']);

        self::assertSame('auth_error', $this->store->rows[1]['event_type']);
        self::assertSame('AuthException after retry', $this->store->rows[1]['detail']);
    }

    public function testRecordedRowExposesOnlyTheAllowedColumnsAndNeverRedactedKeys(): void
    {
        $this->logger()->record(
            AuditEvent::query('r', 'ip', 'ced', '1821', AuditEvent::RESULT_ERROR, null, 'UpstreamException')
        );

        $row = $this->store->rows[0];

        self::assertSame(
            ['request_id', 'created_at', 'ip', 'cedula', 'cod_pla', 'result', 'api_http', 'event_type', 'detail'],
            array_keys($row),
        );

        foreach (['nombre', 'name', 'nombre_asegurado', 'asegurado', 'beneficiario', 'token', 'bearer', 'payload', 'body', 'password', 'user', 'authorization', 'poliza', 'orden', 'file_hash'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $row);
        }
    }

    public function testPruneDeletesOnlyRowsOlderThanTheCutoffAndReturnsTheCount(): void
    {
        $this->seed('2026-01-01 00:00:00');
        $this->seed('2026-02-01 00:00:00');
        $this->seed('2026-09-09 11:00:00');

        $removed = $this->logger()->prune(30); // cutoff = 2026-08-10 12:00:00

        self::assertSame(2, $removed);
        self::assertCount(1, $this->store->rows);
        self::assertSame('2026-09-09 11:00:00', $this->store->rows[0]['created_at']);
    }

    public function testPruneCoercesZeroRetentionToAtLeastOneDayAndKeepsRecentRows(): void
    {
        $this->seed('2026-09-09 11:59:00'); // 1 min ago -> must survive
        $this->seed('2026-09-05 00:00:00'); // > 1 day old -> removed

        $removed = $this->logger()->prune(0); // coerced to 1 day, cutoff = 2026-09-08 12:00:00

        self::assertSame(1, $removed);
        self::assertCount(1, $this->store->rows);
        self::assertSame('2026-09-09 11:59:00', $this->store->rows[0]['created_at']);
    }

    public function testPruneWithNegativeRetentionNeverWipesRecentRows(): void
    {
        $this->seed('2026-09-09 11:59:59');

        $removed = $this->logger()->prune(-999);

        self::assertSame(0, $removed);
        self::assertCount(1, $this->store->rows);
    }

    public function testPruneReturnsWhateverTheStoreReports(): void
    {
        $this->seed('2000-01-01 00:00:00');
        $this->seed('2000-01-02 00:00:00');

        self::assertSame(2, $this->logger()->prune(180));
        self::assertCount(0, $this->store->rows);
    }

    private function seed(string $createdAt): void
    {
        $this->store->rows[] = ['created_at' => $createdAt];
    }
}
