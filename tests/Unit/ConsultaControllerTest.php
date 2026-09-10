<?php

declare(strict_types=1);

namespace {
    if (! class_exists('WP_REST_Request')) {
        final class WP_REST_Request
        {
            /** @param array<string, mixed> $params */
            public function __construct(private array $params = []) {}

            public function get_param(string $key): mixed
            {
                return $this->params[$key] ?? null;
            }
        }
    }

    if (! class_exists('WP_REST_Response')) {
        final class WP_REST_Response
        {
            /** @param array<string, mixed> $data */
            public function __construct(private array $data, private int $status) {}

            /** @return array<string, mixed> */
            public function get_data(): array { return $this->data; }
            public function get_status(): int { return $this->status; }
            public function header(string $key, string $value): void {}
        }
    }

    if (! function_exists('wp_generate_uuid4')) {
        function wp_generate_uuid4(): string { return 'request-id'; }
    }
    if (! function_exists('apply_filters')) {
        function apply_filters(string $hook, mixed $value): mixed { return $value; }
    }
    if (! function_exists('sanitize_text_field')) {
        function sanitize_text_field(string $value): string { return $value; }
    }
    if (! function_exists('__')) {
        function __(string $text, string $domain): string { return $text; }
    }
}

namespace CarnetEquidad\Tests\Unit {
    use CarnetEquidad\Audit\AuditLogger;
    use CarnetEquidad\RateLimit\RateLimiter;
    use CarnetEquidad\Rest\ConsultaController;
    use CarnetEquidad\Tests\Fakes\FakeAuditStore;
    use CarnetEquidad\Tests\Fakes\FakeRateStore;
    use PHPUnit\Framework\TestCase;

    final class ConsultaControllerTest extends TestCase
    {
        public function testMissingPrivacyConsentIsRejectedBeforeRateLimitingOrAuditLogging(): void
        {
            $rateStore = new FakeRateStore();
            $auditStore = new FakeAuditStore();
            $controller = new ConsultaController(
                rateLimiter: new RateLimiter($rateStore, 10, 600, static fn (): int => 1000),
                auditLogger: new AuditLogger($auditStore),
            );

            $response = $controller->handle(new \WP_REST_Request(['cedula' => '1094290592']));

            self::assertSame(400, $response->get_status());
            self::assertSame('consent_required', $response->get_data()['status']);
            self::assertSame(0, $rateStore->setCalls);
            self::assertSame(0, $auditStore->insertCalls);
        }

        public function testNonAffirmativePrivacyConsentIsRejectedBeforeRateLimiting(): void
        {
            $rateStore = new FakeRateStore();
            $controller = new ConsultaController(
                rateLimiter: new RateLimiter($rateStore, 10, 600, static fn (): int => 1000),
            );

            $response = $controller->handle(new \WP_REST_Request([
                'cedula' => '1094290592',
                'privacy_consent' => false,
            ]));

            self::assertSame(400, $response->get_status());
            self::assertSame('consent_required', $response->get_data()['status']);
            self::assertSame(0, $rateStore->setCalls);
        }
    }
}
