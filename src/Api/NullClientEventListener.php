<?php

declare(strict_types=1);

namespace CarnetEquidad\Api;

/**
 * No-op {@see ClientEventListener}. It is the default fourth constructor argument
 * of {@see ApiDataCarnetClient}, so existing three-argument construction (and its
 * tests) keeps working unchanged.
 */
final class NullClientEventListener implements ClientEventListener
{
    public function tokenRefreshed(): void
    {
    }

    public function authRetryFailed(): void
    {
    }
}
