<?php

declare(strict_types=1);

namespace CarnetEquidad\Api;

use CarnetEquidad\Api\Exception\ConfigException;

/**
 * Runtime configuration for {@see ApiDataCarnetClient}.
 *
 * Values come from wp-config.php constants in production (see {@see self::fromConstants()});
 * tests construct the object directly. Credential getters throw a clear
 * {@see ConfigException} when the value is missing so misconfiguration fails loudly.
 */
final class ClientConfig
{
    /**
     * Default upstream base URL (private IP, VPN-only) per the working document.
     */
    public const DEFAULT_BASE_URL = 'http://192.168.243.194:9050';

    public function __construct(
        private readonly ?string $baseUrl,
        private readonly ?string $user,
        private readonly ?string $password,
    ) {
    }

    /**
     * Build from wp-config.php constants:
     *   - CARNET_API_BASE_URL (optional, defaults to {@see self::DEFAULT_BASE_URL})
     *   - CARNET_API_USER     (required)
     *   - CARNET_API_PASSWORD (required)
     */
    public static function fromConstants(): self
    {
        return new self(
            \defined('CARNET_API_BASE_URL') ? (string) \constant('CARNET_API_BASE_URL') : null,
            \defined('CARNET_API_USER') ? (string) \constant('CARNET_API_USER') : null,
            \defined('CARNET_API_PASSWORD') ? (string) \constant('CARNET_API_PASSWORD') : null,
        );
    }

    public function getBaseUrl(): string
    {
        $value = trim((string) $this->baseUrl);

        return rtrim($value !== '' ? $value : self::DEFAULT_BASE_URL, '/');
    }

    public function getUser(): string
    {
        return $this->require($this->user, 'CARNET_API_USER');
    }

    public function getPassword(): string
    {
        return $this->require($this->password, 'CARNET_API_PASSWORD');
    }

    private function require(?string $value, string $constant): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            throw new ConfigException(sprintf('Missing required configuration constant "%s".', $constant));
        }

        return $value;
    }
}
