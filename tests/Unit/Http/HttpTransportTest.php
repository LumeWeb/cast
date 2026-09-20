<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use LumeWeb\Cast\Http\HttpResponse;
use LumeWeb\Cast\Http\HttpTransport;
use LumeWeb\Cast\Http\ResponseDecodingException;
use LumeWeb\Cast\Http\TransportException;
use LumeWeb\Cast\Http\UnexpectedStatusCodeException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The PSR-18 transport boundary every SDK client runs on: request construction
 * via PSR-17 factories, PSR-18 send, and typed status/JSON/transport errors.
 * A MockHandler core plus a request-recording middleware exercise the real
 * HttpTransport without any network.
 */
final class HttpTransportTest extends TestCase
{
    /**
     * @var list<RequestInterface> Requests in the order the client received them.
     */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->requests = [];
    }

    /**
     * @param list<ResponseInterface|ConnectException> $queue MockHandler queue.
     */
    private function transportFor(array $queue): HttpTransport
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                $this->requests[] = $request;

                return $handler($request, $options);
            };
        });

        $psr17 = new HttpFactory();

        return new HttpTransport(new Client(['handler' => $stack]), $psr17, $psr17);
    }

    private function lastRequest(): RequestInterface
    {
        self::assertNotEmpty($this->requests);

        return $this->requests[count($this->requests) - 1];
    }

    public function testSendsMethodHeadersAndBody(): void
    {
        $transport = $this->transportFor([
            new Response(201, ['Content-Type' => 'application/json'], '{"id":"u1"}'),
        ]);

        $response = $transport->send(
            'POST',
            'https://portal.test/api/upload/result',
            ['X-Trace' => 'abc', 'Content-Type' => 'application/json'],
            '{"a":1}',
        );

        $request = $this->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://portal.test/api/upload/result', (string) $request->getUri());
        self::assertSame('abc', $request->getHeaderLine('X-Trace'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('{"a":1}', (string) $request->getBody());
        self::assertSame(201, $response->status());
        self::assertInstanceOf(HttpResponse::class, $response);
        self::assertTrue($response->isSuccess());
    }

    public function testSendsStreamInterfaceBody(): void
    {
        $transport = $this->transportFor([
            new Response(201, ['Content-Type' => 'application/json'], '{"ok":true}'),
        ]);

        $body = Utils::streamFor('streamed-bytes');

        $response = $transport->send('POST', 'https://portal.test/api/upload', [], $body);

        self::assertSame(201, $response->status());
        self::assertSame('streamed-bytes', (string) $this->lastRequest()->getBody());
    }

    public function testDecodesJsonResponse(): void
    {
        $transport = $this->transportFor([
            new Response(200, ['Content-Type' => 'application/json'], '{"status":"completed","cid":"QmX"}'),
        ]);

        $response = $transport->send('GET', 'https://portal.test/api/upload/result/u1');

        self::assertSame(200, $response->status());
        self::assertTrue($response->isSuccess());
        self::assertSame('completed', $response->json()['status']);
        self::assertSame('QmX', $response->json()['cid']);
        self::assertSame('{"status":"completed","cid":"QmX"}', $response->body());
    }

    public function testRequireSuccessReturnsResponseFor2xx(): void
    {
        $transport = $this->transportFor([new Response(200, [], '{"ok":true}')]);

        $response = $transport->send('GET', 'https://portal.test/api/status');

        self::assertSame($response, $response->requireSuccess());
    }

    public function testNon2xxSurfacesTypedStatusException(): void
    {
        $transport = $this->transportFor([
            new Response(503, ['Content-Type' => 'application/json'], '{"error":"busy"}'),
        ]);

        $response = $transport->send('GET', 'https://portal.test/api/upload/result/u1');

        self::assertFalse($response->isSuccess());
        self::assertSame(503, $response->status());

        try {
            $response->requireSuccess();
            self::fail('Expected UnexpectedStatusCodeException');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(503, $e->status());
            self::assertSame('busy', $e->response()->json()['error']);
            self::assertStringContainsString('503', $e->getMessage());
        }
    }

    public function testMalformedJsonThrowsResponseDecodingException(): void
    {
        $transport = $this->transportFor([new Response(200, [], '<html>not json</html>')]);

        $response = $transport->send('GET', 'https://portal.test/api/status');

        $this->expectException(ResponseDecodingException::class);
        $response->json();
    }

    public function testEmptyBodyThrowsResponseDecodingException(): void
    {
        $transport = $this->transportFor([new Response(200, [], '')]);

        $response = $transport->send('GET', 'https://portal.test/api/status');

        $this->expectException(ResponseDecodingException::class);
        $response->json();
    }

    public function testScalarJsonBodyThrowsResponseDecodingException(): void
    {
        $transport = $this->transportFor([new Response(200, [], '"just a string"')]);

        $response = $transport->send('GET', 'https://portal.test/api/status');

        $this->expectException(ResponseDecodingException::class);
        $response->json();
    }

    public function testTransportFailureWrapsPsr18ClientException(): void
    {
        $connect = new ConnectException('Connection refused', new Request('GET', 'https://portal.test/api/upload/result/u1'));
        $transport = $this->transportFor([$connect]);

        try {
            $transport->send('GET', 'https://portal.test/api/upload/result/u1');
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            self::assertInstanceOf(ClientExceptionInterface::class, $e->getPrevious());
            self::assertSame('Connection refused', $e->getPrevious()->getMessage());
            self::assertStringContainsString('GET', $e->getMessage());
        }
    }

    public function testBearerHeaderIsSentAndNeverLeakedInTransportException(): void
    {
        $secret = 's3cret-bearer-token-9f8d';
        $connect = new ConnectException('Connection refused', new Request('GET', 'https://portal.test/api/sites'));
        $transport = $this->transportFor([$connect]);

        try {
            $transport->send('GET', 'https://portal.test/api/sites', ['Authorization' => 'Bearer ' . $secret]);
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            self::assertStringNotContainsString($secret, $e->getMessage());
        }

        self::assertSame('Bearer ' . $secret, $this->lastRequest()->getHeaderLine('Authorization'));
    }

    public function testBearerHeaderNeverLeakedInStatusException(): void
    {
        $secret = 's3cret-bearer-token-9f8d';
        $transport = $this->transportFor([new Response(401, [], '{"error":"unauthorized"}')]);

        $response = $transport->send('GET', 'https://portal.test/api/sites', ['Authorization' => 'Bearer ' . $secret]);

        try {
            $response->requireSuccess();
            self::fail('Expected UnexpectedStatusCodeException');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(401, $e->status());
            self::assertStringNotContainsString($secret, $e->getMessage());
            self::assertStringNotContainsString($secret, $e->response()->body());
        }
    }
}
