<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\CaptureRequest;
use LumeWeb\Cast\Export\CaptureTransportException;
use LumeWeb\Cast\Export\WordPressCaptureTransport;
use LumeWeb\Cast\Export\WpRemoteResponse;
use PHPUnit\Framework\TestCase;

final class WordPressCaptureTransportTest extends TestCase
{
    public function testSendsExactK4RequestArguments(): void
    {
        $http = new FakeCaptureHttp([
            WpRemoteResponse::success(200, ['content-type' => 'text/html'], '<html>ok</html>'),
        ]);

        $this->transport($http)->fetch(new CaptureRequest('https://example.com/about'));

        self::assertCount(1, $http->calls);
        self::assertSame('https://example.com/about', $http->calls[0]['url']);

        $args = $http->calls[0]['args'];
        self::assertSame(30.0, $args['timeout']);
        self::assertSame(0, $args['redirection']);
        self::assertTrue($args['blocking']);
        self::assertTrue($args['decompress']);
        self::assertTrue($args['sslverify']);
        self::assertTrue($args['stream']);
        self::assertSame('identity', $args['headers']['Accept-Encoding']);
        self::assertSame(WordPressCaptureTransport::USER_AGENT, $args['headers']['User-Agent']);
        self::assertArrayNotHasKey('Authorization', $args['headers']);
        self::assertArrayNotHasKey('cookies', $args);
        self::assertTrue(str_starts_with((string) $args['filename'], sys_get_temp_dir()));
        self::assertTrue(is_string($args['filename']));
    }

    public function testClampsTimeoutToSaneRange(): void
    {
        $this->clampedTimeout(2.0, 5.0);
        $this->clampedTimeout(999.0, 60.0);
    }

    public function testMapsStatusHeadersAndBody(): void
    {
        $http = new FakeCaptureHttp([
            WpRemoteResponse::success(
                200,
                ['content-type' => 'text/html; charset=utf-8', 'x-custom' => 'v1'],
                '<html>ok</html>',
            ),
        ]);

        $response = $this->transport($http)->fetch(new CaptureRequest('https://example.com/'));

        self::assertSame(200, $response->status());
        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        self::assertSame('v1', $response->header('X-Custom'));
        self::assertSame('<html>ok</html>', $response->body()?->contents());
    }

    public function testWpErrorBecomesTransportException(): void
    {
        $http = new FakeCaptureHttp([
            WpRemoteResponse::error('http_request_failed', 'cURL error 7: Failed to connect'),
        ]);

        $this->expectException(CaptureTransportException::class);
        $this->expectExceptionMessage('cURL error 7');

        $this->transport($http)->fetch(new CaptureRequest('https://example.com/'));
    }

    public function testStreamedTemporaryFileBecomesBody(): void
    {
        $http = new FakeCaptureHttp([
            static function (string $url, array $args): WpRemoteResponse {
                file_put_contents($args['filename'], 'STREAMED-BYTES');

                return WpRemoteResponse::success(200, [], '', $args['filename']);
            },
        ]);

        $response = $this->transport($http)->fetch(new CaptureRequest('https://example.com/file.bin'));

        self::assertSame('STREAMED-BYTES', $response->body()?->contents());
    }

    public function testNeverSendsBasicAuthForAnyRequest(): void
    {
        $http = new FakeCaptureHttp();
        $transport = $this->transport($http);

        $transport->fetch(new CaptureRequest('https://example.com/'));
        $transport->fetch(new CaptureRequest('https://example.com/wp-content/uploads/a.jpg'));

        foreach ($http->calls as $call) {
            $headers = $call['args']['headers'] ?? [];
            self::assertIsArray($headers);
            self::assertArrayNotHasKey('Authorization', $headers);
            self::assertArrayNotHasKey('cookies', $call['args']);
        }
    }

    public function testTlsIsVerifiedForPublicHosts(): void
    {
        $http = new FakeCaptureHttp();
        $this->transport($http)->fetch(new CaptureRequest('https://example.com/'));

        self::assertTrue($http->calls[0]['args']['sslverify']);
    }

    public function testTlsIsDisabledForLocalHosts(): void
    {
        foreach (
            [
                'https://localhost/',
                'https://127.0.0.1/x',
                'https://[::1]/x',
                'https://site.test/',
                'https://site.localhost/',
            ] as $url
        ) {
            $http = new FakeCaptureHttp();
            $this->transport($http)->fetch(new CaptureRequest($url));

            self::assertFalse($http->calls[0]['args']['sslverify'], $url);
        }
    }

    public function testTlsIsDisabledInTheWordPressLocalEnvironment(): void
    {
        $http = new FakeCaptureHttp();
        $http->localEnvironment = true;

        $this->transport($http)->fetch(new CaptureRequest('https://example.com/'));

        self::assertFalse($http->calls[0]['args']['sslverify']);
    }

    public function testCustomUserAgentIsSent(): void
    {
        $http = new FakeCaptureHttp();
        $this->transport($http, userAgent: 'CastProbe/2.0')->fetch(new CaptureRequest('https://example.com/'));

        self::assertSame('CastProbe/2.0', $http->calls[0]['args']['headers']['User-Agent']);
    }

    private function clampedTimeout(float $configured, float $expected): void
    {
        $http = new FakeCaptureHttp();
        $this->transport($http, timeout: $configured)->fetch(new CaptureRequest('https://example.com/'));

        self::assertSame($expected, $http->calls[0]['args']['timeout']);
    }

    private function transport(
        FakeCaptureHttp $http,
        float $timeout = 30.0,
        ?string $userAgent = null,
    ): WordPressCaptureTransport {
        return new WordPressCaptureTransport($http, $timeout, $userAgent);
    }
}
