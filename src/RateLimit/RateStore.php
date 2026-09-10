<?php

declare(strict_types=1);

namespace CarnetEquidad\RateLimit;

/**
 * Small abstraction over the counter cache so {@see RateLimiter} stays
 * WordPress-free and unit-testable. Production uses {@see WpTransientRateStore}
 * (WordPress transients); tests use an in-memory fake.
 *
 * Stored values are fixed-window state: {@code ['count' => int, 'start' => int]}.
 */
interface RateStore
{
    /**
     * @return array<string, mixed>|null the stored state, or null when absent.
     */
    public function get(string $key): ?array;

    /**
     * @param array<string, mixed> $value
     */
    public function set(string $key, array $value, int $ttlSeconds): void;
}
