<?php

declare(strict_types=1);

namespace CarnetEquidad\RateLimit;

/**
 * Fixed-window rate limiter.
 *
 * Pure class: no WordPress dependency. The counter cache is injected via
 * {@see RateStore} and the clock via an optional callable, so the whole class is
 * unit-testable with an in-memory fake and a scripted clock.
 *
 * Window state is {@code ['count' => int, 'start' => int]}. When a window is
 * open, every attempt increments the counter; attempts beyond {@see $limit} are
 * rejected until {@code now - start >= windowSeconds}, at which point the next
 * attempt opens a fresh window.
 */
final class RateLimiter
{
    /** @var callable(): int */
    private $now;

    /**
     * @param callable(): int|null $now defaults to {@see time()}.
     */
    public function __construct(
        private readonly RateStore $store,
        private readonly int $limit,
        private readonly int $windowSeconds,
        ?callable $now = null,
    ) {
        $this->now = $now ?? static fn (): int => time();
    }

    public function attempt(string $key): RateLimitResult
    {
        $now = ($this->now)();
        $state = $this->store->get($key);

        if (!$this->isOpenWindow($state, $now)) {
            $this->store->set($key, ['count' => 1, 'start' => $now], $this->windowSeconds);

            return new RateLimitResult(
                allowed: true,
                remaining: max(0, $this->limit - 1),
                retryAfter: 0,
            );
        }

        /** @var array{count: int, start: int} $state */
        $start = (int) $state['start'];
        $count = (int) $state['count'] + 1;
        $secondsUntilReset = max(1, $this->windowSeconds - ($now - $start));

        $this->store->set($key, ['count' => $count, 'start' => $start], $secondsUntilReset);

        $allowed = $count <= $this->limit;

        return new RateLimitResult(
            allowed: $allowed,
            remaining: max(0, $this->limit - $count),
            retryAfter: $allowed ? 0 : $secondsUntilReset,
        );
    }

    /**
     * @param array<string, mixed>|null $state
     */
    private function isOpenWindow(?array $state, int $now): bool
    {
        if ($state === null || !isset($state['count'], $state['start'])) {
            return false;
        }

        return ($now - (int) $state['start']) < $this->windowSeconds;
    }
}
