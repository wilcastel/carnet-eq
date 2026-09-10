<?php

declare(strict_types=1);

namespace CarnetEquidad\Tests\Fakes;

use CarnetEquidad\Http\HttpResponse;
use CarnetEquidad\Http\HttpTransport;

/**
 * In-memory HTTP transport for unit tests.
 *
 * Enqueue the responses (or throwables) the client should receive, in order.
 * Every call is recorded in {@see FakeHttpTransport::$calls}.
 */
final class FakeHttpTransport implements HttpTransport
{
    /** @var list<array{url: string, headers: array<string, string>, body: string}> */
    public array $calls = [];

    /** @var list<HttpResponse|\Throwable> */
    private array $queue = [];

    public function enqueue(HttpResponse|\Throwable $response): void
    {
        $this->queue[] = $response;
    }

    public function post(string $url, array $headers, string $body): HttpResponse
    {
        $this->calls[] = ['url' => $url, 'headers' => $headers, 'body' => $body];

        if ($this->queue === []) {
            throw new \LogicException('FakeHttpTransport: no response enqueued for ' . $url);
        }

        $next = array_shift($this->queue);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function callCount(string $urlNeedle): int
    {
        $count = 0;
        foreach ($this->calls as $call) {
            if (str_contains($call['url'], $urlNeedle)) {
                $count++;
            }
        }

        return $count;
    }

    /** @return array{url: string, headers: array<string, string>, body: string} */
    public function callAt(int $index): array
    {
        return $this->calls[$index];
    }

    /** @return array<string, mixed> */
    public function decodedBodyAt(int $index): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->calls[$index]['body'], true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
