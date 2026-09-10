<?php

declare(strict_types=1);

namespace CarnetEquidad\Tests\Fakes;

use CarnetEquidad\Api\TokenStore;

/**
 * In-memory {@see TokenStore} for unit tests. TTL is recorded but not enforced.
 */
final class FakeTokenStore implements TokenStore
{
    /** @var array<string, string> */
    private array $values = [];

    public int $setCalls = 0;

    public int $deleteCalls = 0;

    /** @var list<int> */
    public array $ttls = [];

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $this->values[$key] = $value;
        $this->ttls[] = $ttlSeconds;
        $this->setCalls++;
    }

    public function delete(string $key): void
    {
        unset($this->values[$key]);
        $this->deleteCalls++;
    }
}
