<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LumeWeb\Cast\Http\GuzzleHttpClientFactory;
use LumeWeb\Cast\Http\HttpClientFactory;
use LumeWeb\Cast\Http\HttpTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;

/**
 * The Guzzle concrete factory: portal-safe defaults (bounded timeouts, TLS
 * verification on, no cookies, non-2xx returned instead of thrown, no debug
 * logging) while still letting a test inject a MockHandler to run offline.
 */
final class GuzzleHttpClientFactoryTest extends TestCase
{
    private GuzzleHttpClientFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new GuzzleHttpClientFactory();
    }

    public function testCreatesPsr18Client(): void
    {
        self::assertInstanceOf(ClientInterface::class, $this->factory->create());
        self::assertInstanceOf(HttpClientFactory::class, $this->factory);
    }

    public function testSafeDefaultsApplied(): void
    {
        /** @var Client $client */
        $client = $this->factory->create();
        self::assertInstanceOf(Client::class, $client);
        self::assertSame(30.0, $client->getConfig('timeout'));
        self::assertSame(10.0, $client->getConfig('connect_timeout'));
        self::assertTrue($client->getConfig('verify'));
        self::assertFalse($client->getConfig('cookies'));
        self::assertFalse($client->getConfig('http_errors'));
        self::assertNull($client->getConfig('debug'));
        // Redirects surface as raw responses so the upload adapter implements
        // its own 307/308 policy (hop budget, loop detection, body preservation).
        self::assertFalse($client->getConfig('allow_redirects'));
    }

    public function testExplicitOptionsOverrideDefaults(): void
    {
        /** @var Client $client */
        $client = $this->factory->create(['timeout' => 5.0, 'connect_timeout' => 2.0]);
        self::assertSame(5.0, $client->getConfig('timeout'));
        self::assertSame(2.0, $client->getConfig('connect_timeout'));
        self::assertTrue($client->getConfig('verify'));
    }

    public function testMockHandlerInjectionRunsWithoutNetwork(): void
    {
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}')]);
        $client = $this->factory->create(['handler' => HandlerStack::create($mock)]);

        $response = $client->sendRequest(new Request('GET', 'https://portal.test/api/account'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true], json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testHttpTransportOverGuzzleFactoryEndToEnd(): void
    {
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '{"cid":"QmX"}')]);
        $client = $this->factory->create(['handler' => HandlerStack::create($mock)]);
        $psr17 = new HttpFactory();
        $transport = new HttpTransport($client, $psr17, $psr17);

        $response = $transport->send('GET', 'https://portal.test/api/upload/result/u1');

        self::assertSame(['cid' => 'QmX'], $response->json());
    }
}
