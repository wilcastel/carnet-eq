<?php

declare(strict_types=1);

namespace CarnetEquidad\Api;

/**
 * {@see TokenStore} backed by WordPress transients. Not exercised by unit tests.
 */
final class WpTransientTokenStore implements TokenStore
{
    public function get(string $key): ?string
    {
        $value = \get_transient($key);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        \set_transient($key, $value, $ttlSeconds);
    }

    public function delete(string $key): void
    {
        \delete_transient($key);
    }
}
