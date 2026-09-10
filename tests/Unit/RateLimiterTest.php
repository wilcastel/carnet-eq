<?php

declare(strict_types=1);

namespace CarnetEquidad\Tests\Unit;

use CarnetEquidad\RateLimit\RateLimiter;
use CarnetEquidad\RateLimit\RateLimitResult;
use CarnetEquidad\Tests\Fakes\FakeRateStore;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private FakeRateStore $store;

    /** Mutable fake clock, advanced by the tests. */
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->store = new FakeRateStore();
    }

    private function limiter(int $limit = 3, int $window = 60): RateLimiter
    {
        return new RateLimiter(
            $this->store,
            $limit,
            $window,
            fn (): int => $this->now,
        );
    }

    public function testFirstLimitAttemptsAreAllowedAndTheNextOneIsBlocked(): void
    {
        $limiter = $this->limiter(limit: 3, window: 60);

        self::assertTrue($limiter->attempt('ip:a')->allowed);
        self::assertTrue($limiter->attempt('ip:a')->allowed);
        self::assertTrue($limiter->attempt('ip:a')->allowed);

        self::assertFalse($limiter->attempt('ip:a')->allowed);
    }

    public function testRemainingCountsDownToZeroAndStaysZeroWhenBlocked(): void
    {
        $limiter = $this->limiter(limit: 3, window: 60);

        self::assertSame(2, $limiter->attempt('ip:a')->remaining);
        self::assertSame(1, $limiter->attempt('ip:a')->remaining);
        self::assertSame(0, $limiter->attempt('ip:a')->remaining);

        $blocked = $limiter->attempt('ip:a');
        self::assertFalse($blocked->allowed);
        self::assertSame(0, $blocked->remaining);

        // Further blocked attempts keep remaining pinned at zero (never negative).
        self::assertSame(0, $limiter->attempt('ip:a')->remaining);
    }

    public function testAllowedResultCarriesNoRetryAfter(): void
    {
        $result = $this->limiter(limit: 3, window: 60)->attempt('ip:a');

        self::assertTrue($result->allowed);
        self::assertSame(0, $result->retryAfter);
    }

    public function testBlockedResultRetryAfterIsWithinTheWindow(): void
    {
        $limiter = $this->limiter(limit: 1, window: 60);

        $limiter->attempt('ip:a');

        $this->now += 10;
        $blocked = $limiter->attempt('ip:a');

        self::assertFalse($blocked->allowed);
        self::assertGreaterThan(0, $blocked->retryAfter);
        self::assertLessThanOrEqual(60, $blocked->retryAfter);
        // 60s window, opened 10s ago -> ~50s until it resets.
        self::assertSame(50, $blocked->retryAfter);
    }

    public function testAdvancingTheClockPastTheWindowResetsTheCounter(): void
    {
        $limiter = $this->limiter(limit: 2, window: 60);

        $limiter->attempt('ip:a');
        $limiter->attempt('ip:a');
        self::assertFalse($limiter->attempt('ip:a')->allowed);

        // Jump beyond the window: a brand-new window starts.
        $this->now += 60;

        $fresh = $limiter->attempt('ip:a');
        self::assertTrue($fresh->allowed);
        self::assertSame(1, $fresh->remaining);
        self::assertSame(0, $fresh->retryAfter);

        $state = $this->store->get('ip:a');
        self::assertIsArray($state);
        self::assertSame(1, $state['count']);
        self::assertSame($this->now, $state['start']);
    }

    public function testFreshWindowIsPersistedWithTheFullWindowTtl(): void
    {
        $this->limiter(limit: 5, window: 120)->attempt('ip:a');

        self::assertSame([120], $this->store->ttls);
    }

    public function testTwoDifferentKeysAreCountedIndependently(): void
    {
        $limiter = $this->limiter(limit: 2, window: 60);

        self::assertTrue($limiter->attempt('ip:a')->allowed);
        self::assertTrue($limiter->attempt('ip:a')->allowed);
        self::assertFalse($limiter->attempt('ip:a')->allowed);

        // 'ip:b' has its own untouched budget.
        self::assertTrue($limiter->attempt('ip:b')->allowed);
        self::assertTrue($limiter->attempt('ip:b')->allowed);
        self::assertFalse($limiter->attempt('ip:b')->allowed);
    }

    public function testDefaultClockUsesWallTime(): void
    {
        // No clock injected -> defaults to time(); just make sure it works.
        $limiter = new RateLimiter($this->store, 1, 60);

        $result = $limiter->attempt('ip:a');

        self::assertInstanceOf(RateLimitResult::class, $result);
        self::assertTrue($result->allowed);
        self::assertFalse($limiter->attempt('ip:a')->allowed);
    }
}
