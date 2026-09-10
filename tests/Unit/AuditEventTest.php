<?php

declare(strict_types=1);

namespace CarnetEquidad\Tests\Unit;

use CarnetEquidad\Audit\AuditEvent;
use PHPUnit\Framework\TestCase;

final class AuditEventTest extends TestCase
{
    public function testQueryFactoryBuildsAQueryEvent(): void
    {
        $event = AuditEvent::query('req-1', '10.0.0.1', '1094290592', '1821', AuditEvent::RESULT_FOUND, 200);

        self::assertSame('req-1', $event->requestId);
        self::assertSame('10.0.0.1', $event->ip);
        self::assertSame('1094290592', $event->cedula);
        self::assertSame('1821', $event->codPla);
        self::assertSame('query', $event->eventType);
        self::assertSame('found', $event->result);
        self::assertSame(200, $event->apiHttp);
        self::assertNull($event->detail);
    }

    public function testTokenRefreshAndAuthErrorFactoriesCarryNoResultOrHttpStatus(): void
    {
        $refresh = AuditEvent::tokenRefresh('req-2', '10.0.0.2', '123', '1821');
        $authErr = AuditEvent::authError('req-3', '10.0.0.3', '456', '1821', 'AuthException after retry');

        self::assertSame('token_refresh', $refresh->eventType);
        self::assertNull($refresh->result);
        self::assertNull($refresh->apiHttp);

        self::assertSame('auth_error', $authErr->eventType);
        self::assertNull($authErr->result);
        self::assertNull($authErr->apiHttp);
        self::assertSame('AuthException after retry', $authErr->detail);
    }

    public function testCarnetGeneratedFactoryRecordsOnlyTheEventMetadata(): void
    {
        $event = AuditEvent::carnetGenerated('req-pdf', '10.0.0.4', '123', '1821', AuditEvent::RESULT_FOUND, 'pdf_generated');

        self::assertSame('carnet_generated', $event->eventType);
        self::assertSame(200, $event->apiHttp);
        self::assertSame('pdf_generated', $event->detail);
    }

    public function testAnUnknownResultIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AuditEvent::query('r', 'ip', 'c', '1821', 'weird');
    }

    public function testAnUnknownEventTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AuditEvent('r', 'ip', 'c', '1821', 'nope');
    }

    public function testAnOverlongDetailIsClampedToASafeLength(): void
    {
        $event = AuditEvent::query('r', 'ip', 'c', '1821', AuditEvent::RESULT_ERROR, null, str_repeat('x', 5000));

        self::assertNotNull($event->detail);
        self::assertLessThanOrEqual(255, \strlen($event->detail));
    }

    public function testTheValueObjectExposesNoRedactedFields(): void
    {
        $event = AuditEvent::query('r', 'ip', 'c', '1821', AuditEvent::RESULT_FOUND, 200);

        $props = array_keys(get_object_vars($event));
        sort($props);

        self::assertSame(
            ['apiHttp', 'cedula', 'codPla', 'detail', 'eventType', 'ip', 'requestId', 'result'],
            $props,
        );
    }
}
