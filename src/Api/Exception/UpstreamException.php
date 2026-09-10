<?php

declare(strict_types=1);

namespace CarnetEquidad\Api\Exception;

/**
 * The upstream returned an unexpected non-2xx status, an unparseable payload,
 * or the request failed at the transport level.
 */
final class UpstreamException extends \RuntimeException
{
}
