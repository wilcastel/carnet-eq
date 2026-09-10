<?php

declare(strict_types=1);

namespace CarnetEquidad\Tests\Fakes;

use CarnetEquidad\RateLimit\RateStore;

/**
 * In-memory {@see RateStore} for unit tests.
 *
 * TTL is recorded but never enforced here: window expiry is driven purely by the
 * clock injected into {@see \CarnetEquidad\RateLimit\RateLimiter}.
 */
final class FakeRateStore implements RateStore
{
    /** @var array<string, array<string, mixed>> */
    private array $values = [];

    public int $setCalls = 0;

    /** @var list<int> */
    public array $ttls = [];

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        return $this->values[$key] ?? null;
    }

    /**
     * @param array<string, mixed> $value
     */
    public function set(string $key, array $value, int $ttlSeconds): void
    {
        $this->values[$key] = $value;
        $this->ttls[] = $ttlSeconds;
        $this->setCalls++;
    }
}
