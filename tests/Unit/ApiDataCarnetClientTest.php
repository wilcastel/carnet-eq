<?php

declare(strict_types=1);

namespace CarnetEquidad\Tests\Unit;

use CarnetEquidad\Api\ApiDataCarnetClient;
use CarnetEquidad\Api\ClientConfig;
use CarnetEquidad\Api\Exception\AuthException;
use CarnetEquidad\Api\Exception\ConfigException;
use CarnetEquidad\Api\Exception\NotFoundException;
use CarnetEquidad\Api\Exception\UpstreamException;
use CarnetEquidad\Http\HttpResponse;
use CarnetEquidad\Http\TransportException;
use CarnetEquidad\Tests\Fakes\FakeHttpTransport;
use CarnetEquidad\Tests\Fakes\FakeTokenStore;
use PHPUnit\Framework\TestCase;

final class ApiDataCarnetClientTest extends TestCase
{
    private const TOKEN_KEY = 'carnet_equidad_api_token';
    private const CEDULA = '1094290592';

    private FakeHttpTransport $http;
    private FakeTokenStore $tokens;

    protected function setUp(): void
    {
        $this->http = new FakeHttpTransport();
        $this->tokens = new FakeTokenStore();
    }

    private function client(): ApiDataCarnetClient
    {
        return new ApiDataCarnetClient(
            $this->http,
            $this->tokens,
            new ClientConfig('http://api.test:9050/', 'user0017-LIN-0033', 's3cr3t')
        );
    }

    private static function validarTokenBody(string $token): string
    {
        // The real API returns the key literally as "token: " (trailing space + colon).
        return json_encode(['Message' => 'Credenciales validas', 'token: ' => $token], JSON_THROW_ON_ERROR);
    }

    /** @param list<array<string, mixed>> $records */
    private static function dataAseguradoBody(array $records): string
    {
        return json_encode($records, JSON_THROW_ON_ERROR);
    }

    private static function oneRecord(): array
    {
        return [
            'POLIZA' => 'AAA011951',
            'ORDEN' => '1',
            'NOMBRE_ASEGURADO' => 'VILLAMIZAR ACEVEDO EMILIANO',
            'FECHA_INICIO' => '2026-02-01T00:00:00',
            'FECHA_FIN' => '2027-02-01T00:00:00',
        ];
    }

    public function testHappyPathAuthenticatesOnceCachesTokenAndReturnsDecodedArray(): void
    {
        $this->http->enqueue(new HttpResponse(200, self::validarTokenBody('tok-abc')));
        $this->http->enqueue(new HttpResponse(200, self::dataAseguradoBody([self::oneRecord()])));

        $result = $this->client()->getDataAsegurado(self::CEDULA);

        self::assertSame('AAA011951', $result[0]['POLIZA']);

        self::assertSame(1, $this->http->callCount('/api/v1/validarToken'));
        self::assertSame(1, $this->http->callCount('/api/v1/dataAsegurado'));

        // validarToken call: no bearer, carries user + password.
        $auth = $this->http->decodedBodyAt(0);
        self::assertSame('user0017-LIN-0033', $auth['user']);
        self::assertSame('s3cr3t', $auth['password']);
        self::assertArrayNotHasKey('Authorization', $this->http->callAt(0)['headers']);

        // dataAsegurado call: bearer token + fixed payload.
        self::assertSame('Bearer tok-abc', $this->http->callAt(1)['headers']['Authorization']);
        $payload = $this->http->decodedBodyAt(1);
        self::assertSame('Linktic', $payload['consumer']);
        self::assertSame(self::CEDULA, $payload['doc_aseg']);
        self::assertSame('1821', $payload['cod_pla']);

        // token cached in the transient store.
        self::assertSame('tok-abc', $this->tokens->get(self::TOKEN_KEY));
        self::assertSame(1, $this->tokens->setCalls);
    }

    public function testCachedTokenIsReusedAndValidarTokenIsNotCalledAgain(): void
    {
        $this->tokens->set(self::TOKEN_KEY, 'cached-tok', 600);
        $this->tokens->setCalls = 0;

        $this->http->enqueue(new HttpResponse(200, self::dataAseguradoBody([self::oneRecord()])));

        $result = $this->client()->getDataAsegurado(self::CEDULA);

        self::assertSame('AAA011951', $result[0]['POLIZA']);
        self::assertSame(0, $this->http->callCount('/api/v1/validarToken'));
        self::assertSame(1, $this->http->callCount('/api/v1/dataAsegurado'));
        self::assertSame('Bearer cached-tok', $this->http->callAt(0)['headers']['Authorization']);
        self::assertSame(0, $this->tokens->setCalls);
    }

    public function testGetTokenParsesTheWeirdTokenKeyWithTrailingSpaceAndColon(): void
    {
        $this->http->enqueue(new HttpResponse(200, self::validarTokenBody('weird-key-token')));

        self::assertSame('weird-key-token', $this->client()->getToken());
        self::assertSame('weird-key-token', $this->tokens->get(self::TOKEN_KEY));
    }

    public function testGetTokenToleratesACleanTokenKeyAsFallback(): void
    {
        $this->http->enqueue(new HttpResponse(200, json_encode(['token' => 'clean-token'], JSON_THROW_ON_ERROR)));

        self::assertSame('clean-token', $this->client()->getToken());
    }

    public function testGetTokenThrowsUpstreamWhenNoTokenKeyIsPresent(): void
    {
        $this->http->enqueue(new HttpResponse(200, json_encode(['Message' => 'ok'], JSON_THROW_ON_ERROR)));

        $this->expectException(UpstreamException::class);
        $this->client()->getToken();
    }

    public function testGetTokenThrowsUpstreamOnNonSuccessfulValidarToken(): void
    {
        $this->http->enqueue(new HttpResponse(500, 'boom'));

        $this->expectException(UpstreamException::class);
        $this->client()->getToken();
    }

    public function testNotFoundResponseThrowsNotFoundException(): void
    {
        $this->http->enqueue(new HttpResponse(200, self::validarTokenBody('tok')));
        $this->http->enqueue(new HttpResponse(404, json_encode(['detail' => 'No se encontraron resultados con el documento ingresado.'], JSON_THROW_ON_ERROR)));

        $this->expectException(NotFoundException::class);
        $this->client()->getDataAsegurado(self::CEDULA);
    }

    public function testSingle401TriggersReauthRetryOnceAndSucceeds(): void
    {
        $this->http->enqueue(new HttpResponse(200, self::validarTokenBody('stale-tok')));
        $this->http->enqueue(new HttpResponse(401, json_encode(['detail' => 'Token invalido'], JSON_THROW_ON_ERROR)));
        $this->http->enqueue(new HttpResponse(200, self::validarTokenBody('fresh-tok')));
        $this->http->enqueue(new HttpResponse(200, self::dataAseguradoBody([self::oneRecord()])));

        $result = $this->client()->getDataAsegurado(self::CEDULA);

        self::assertSame('AAA011951', $result[0]['POLIZA']);
        self::assertSame(2, $this->http->callCount('/api/v1/validarToken'));
        self::assertSame(2, $this->http->callCount('/api/v1/dataAsegurado'));
        self::assertGreaterThanOrEqual(1, $this->tokens->deleteCalls);
        self::assertSame('Bearer fresh-tok', $this->http->callAt(3)['headers']['Authorization']);
        self::assertSame('fresh-tok', $this->tokens->get(self::TOKEN_KEY));
    }

    public function testRepeated401ThrowsAuthException(): void
    {
        $this->http->enqueue(new HttpResponse(200, self::validarTokenBody('tok-1')));
        $this->http->enqueue(new HttpResponse(401, 'nope'));
        $this->http->enqueue(new HttpResponse(200, self::validarTokenBody('tok-2')));
        $this->http->enqueue(new HttpResponse(401, 'nope'));

        $this->expectException(AuthException::class);
        $this->client()->getDataAsegurado(self::CEDULA);
    }

    public function testServerErrorThrowsUpstreamException(): void
    {
        $this->http->enqueue(new HttpResponse(200, self::validarTokenBody('tok')));
        $this->http->enqueue(new HttpResponse(503, 'service unavailable'));

        $this->expectException(UpstreamException::class);
        $this->client()->getDataAsegurado(self::CEDULA);
    }

    public function testTransportFailureThrowsUpstreamException(): void
    {
        $this->http->enqueue(new HttpResponse(200, self::validarTokenBody('tok')));
        $this->http->enqueue(new TransportException('connection refused'));

        $this->expectException(UpstreamException::class);
        $this->client()->getDataAsegurado(self::CEDULA);
    }

    public function testConfigThrowsWhenCredentialsAreMissing(): void
    {
        $config = new ClientConfig(null, null, null);

        self::assertSame('http://192.168.243.194:9050', $config->getBaseUrl());

        $this->expectException(ConfigException::class);
        $config->getUser();
    }
}
