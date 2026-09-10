<?php

declare(strict_types=1);

namespace CarnetEquidad\Http;

/**
 * Minimal, transport-agnostic HTTP response value object.
 */
final class HttpResponse
{
    public function __construct(
        private readonly int $status,
        private readonly string $body,
    ) {
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Decode the JSON body to an associative array.
     *
     * @return array<int|string, mixed>
     * @throws \JsonException when the body is not valid JSON.
     */
    public function json(): array
    {
        /** @var array<int|string, mixed> $decoded */
        $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
