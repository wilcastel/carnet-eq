<?php

declare(strict_types=1);

namespace CarnetEquidad\Api\Exception;

/**
 * Sentinel for the upstream 404: no insurance records exist for the given ID.
 *
 * Chosen over a null return so callers cannot silently ignore the "not found"
 * outcome; the REST layer maps it to `{ "status": "not_found" }`.
 */
final class NotFoundException extends \RuntimeException
{
}
