<?php

declare(strict_types=1);

namespace CarnetEquidad\Validation;

/**
 * Validates and normalizes a Colombian "cedula" (national ID) as required by
 * the Api Data Carnet upstream: digits only, within a configurable length range
 * (6 to 11 digits by default) after cleaning.
 *
 * Pure class: no WordPress dependency, fully unit-testable.
 */
final class CedulaValidator
{
    /**
     * Longest raw input we will even run the regex over. Anything longer is
     * treated as pathological and rejected outright.
     */
    private const MAX_INPUT_LENGTH = 128;

    public function __construct(
        private int $minDigits = 6,
        private int $maxDigits = 11,
    ) {
        if ($this->minDigits < 1) {
            $this->minDigits = 1;
        }

        if ($this->maxDigits < $this->minDigits) {
            $this->maxDigits = $this->minDigits;
        }
    }

    /**
     * Remove every non-digit character (dots, spaces, dashes, underscores, letters).
     *
     * Pathologically long input (> 128 characters) is rejected before the regex
     * runs.
     */
    public function clean(string $input): string
    {
        if (strlen($input) > self::MAX_INPUT_LENGTH) {
            return '';
        }

        return preg_replace('/\D+/', '', $input) ?? '';
    }

    /**
     * True when the cleaned value has a digit count within the configured range.
     */
    public function isValid(string $input): bool
    {
        $digits = $this->clean($input);
        $length = strlen($digits);

        return $length >= $this->minDigits && $length <= $this->maxDigits;
    }

    /**
     * Cleaned value when valid, null otherwise. Handy for the REST layer.
     */
    public function normalize(string $input): ?string
    {
        return $this->isValid($input) ? $this->clean($input) : null;
    }
}
