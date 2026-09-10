<?php

declare(strict_types=1);

namespace CarnetEquidad\RateLimit;

/**
 * {@see RateStore} backed by WordPress transients. Not exercised by unit tests.
 *
 * The caller-supplied key (which contains a client IP) is never stored verbatim:
 * it is hashed and truncated so the options table only ever holds an opaque
 * digest.
 */
final class WpTransientRateStore implements RateStore
{
    private const KEY_PREFIX = 'carnet_equidad_rl_';

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        $value = \get_transient($this->transientKey($key));

        return \is_array($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $value
     */
    public function set(string $key, array $value, int $ttlSeconds): void
    {
        \set_transient($this->transientKey($key), $value, $ttlSeconds);
    }

    private function transientKey(string $key): string
    {
        return self::KEY_PREFIX . substr(hash('sha256', $key), 0, 40);
    }
}
