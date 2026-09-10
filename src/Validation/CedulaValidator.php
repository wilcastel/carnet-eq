<?php

declare(strict_types=1);

namespace CarnetEquidad\Validation;

/**
 * Validates and normalizes a Colombian "cedula" (national ID) as required by
 * the Api Data Carnet upstream: digits only, 6 to 11 characters after cleaning.
 *
 * Pure class: no WordPress dependency, fully unit-testable.
 */
final class CedulaValidator
{
    private const MIN_DIGITS = 6;
    private const MAX_DIGITS = 11;

    /**
     * Remove every non-digit character (dots, spaces, dashes, underscores, letters).
     */
    public function clean(string $input): string
    {
        return preg_replace('/\D+/', '', $input) ?? '';
    }

    /**
     * True when the cleaned value has between 6 and 11 digits.
     */
    public function isValid(string $input): bool
    {
        $digits = $this->clean($input);
        $length = strlen($digits);

        return $length >= self::MIN_DIGITS && $length <= self::MAX_DIGITS;
    }

    /**
     * Cleaned value when valid, null otherwise. Handy for the REST layer.
     */
    public function normalize(string $input): ?string
    {
        return $this->isValid($input) ? $this->clean($input) : null;
    }
}
