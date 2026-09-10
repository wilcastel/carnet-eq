<?php

declare(strict_types=1);

namespace CarnetEquidad\RateLimit;

/**
 * Outcome of a single {@see RateLimiter::attempt()} call.
 */
final class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        /** Attempts still available in the current window; never negative. */
        public readonly int $remaining,
        /** Seconds until the window resets; 0 when the attempt was allowed. */
        public readonly int $retryAfter,
    ) {
    }
}
