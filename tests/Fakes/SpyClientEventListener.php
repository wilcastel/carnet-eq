<?php

declare(strict_types=1);

namespace CarnetEquidad\Tests\Fakes;

use CarnetEquidad\Api\ClientEventListener;

/**
 * Counting {@see ClientEventListener} spy for unit tests.
 */
final class SpyClientEventListener implements ClientEventListener
{
    public int $tokenRefreshedCalls = 0;

    public int $authRetryFailedCalls = 0;

    public function tokenRefreshed(): void
    {
        $this->tokenRefreshedCalls++;
    }

    public function authRetryFailed(): void
    {
        $this->authRetryFailedCalls++;
    }
}
