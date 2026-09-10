<?php

declare(strict_types=1);

namespace CarnetEquidad\Api;

/**
 * Small abstraction over the token cache so the API client stays
 * unit-testable. Production uses {@see WpTransientTokenStore}
 * (WordPress transients); tests use an in-memory fake.
 */
interface TokenStore
{
    public function get(string $key): ?string;

    public function set(string $key, string $value, int $ttlSeconds): void;

    public function delete(string $key): void;
}
