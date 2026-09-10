<?php

declare(strict_types=1);

namespace CarnetEquidad\Http;

/**
 * Thrown when the HTTP request never produced a response
 * (DNS failure, timeout, connection refused, ...).
 */
final class TransportException extends \RuntimeException
{
}
