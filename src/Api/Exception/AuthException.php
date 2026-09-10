<?php

declare(strict_types=1);

namespace CarnetEquidad\Api\Exception;

/**
 * Authentication with the upstream failed even after a fresh token + one retry.
 */
final class AuthException extends \RuntimeException
{
}
