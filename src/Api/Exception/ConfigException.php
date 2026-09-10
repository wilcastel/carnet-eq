<?php

declare(strict_types=1);

namespace CarnetEquidad\Api\Exception;

/**
 * A required configuration constant (credentials, base URL) is missing or empty.
 */
final class ConfigException extends \RuntimeException
{
}
