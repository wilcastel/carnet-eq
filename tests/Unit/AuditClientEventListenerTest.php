<?php

declare(strict_types=1);

namespace CarnetEquidad\Tests\Unit;

use CarnetEquidad\Audit\AuditClientEventListener;
use CarnetEquidad\Audit\AuditLogger;
use CarnetEquidad\Tests\Fakes\FakeAuditStore;
use PHPUnit\Framework\TestCase;

final class AuditClientEventListenerTest extends TestCase
{
    private FakeAuditStore $store;

    protected function setUp(): void
    {
        $this->store = new FakeAuditStore();
    }

    private function listener(): AuditClientEventListener
    {
        return new AuditClientEventListener(
            new AuditLogger($this->store, fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-09-09 12:00:00', new \DateTimeZone('UTC'))),
            'req-42',
            '10.0.0.9',
            '1094290592',
            '1821',
        );
    }

    public function testTokenRefreshedRecordsATokenRefreshEventBoundToTheRequestContext(): void
    {
        $this->listener()->tokenRefreshed();

        self::assertCount(1, $this->store->rows);
        $row = $this->store->rows[0];
        self::assertSame('token_refresh', $row['event_type']);
        self::assertSame('req-42', $row['request_id']);
        self::assertSame('10.0.0.9', $row['ip']);
        self::assertSame('1094290592', $row['cedula']);
        self::assertSame('1821', $row['cod_pla']);
        self::assertNull($row['result']);
    }

    public function testAuthRetryFailedRecordsAnAuthErrorEventWithASafeDetail(): void
    {
        $this->listener()->authRetryFailed();

        $row = $this->store->rows[0];
        self::assertSame('auth_error', $row['event_type']);
        self::assertSame('req-42', $row['request_id']);
        self::assertNotNull($row['detail']);
        self::assertLessThanOrEqual(255, \strlen($row['detail']));
    }

    public function testAStoreFailureNeverBubblesOutOfTheListener(): void
    {
        $logger = new AuditLogger(
            new class implements \CarnetEquidad\Audit\AuditStore {
                public function insert(array $row): void
                {
                    throw new \RuntimeException('db down');
                }

                public function deleteOlderThan(\DateTimeImmutable $cutoff): int
                {
                    return 0;
                }
            },
        );

        $listener = new AuditClientEventListener($logger, 'r', 'ip', 'c', '1821');

        // Keep the swallowed-error notice out of the test output.
        $previous = ini_set('error_log', tempnam(sys_get_temp_dir(), 'carnet-audit-test'));

        try {
            $listener->tokenRefreshed();
            $listener->authRetryFailed();
        } finally {
            if ($previous !== false) {
                ini_set('error_log', $previous);
            }
        }

        $this->expectNotToPerformAssertions();
    }
}
