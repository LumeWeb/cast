<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\PipelineStageKey;
use LumeWeb\Cast\Export\PipelineState;
use LumeWeb\Cast\Export\ProbeStage;
use LumeWeb\Cast\Export\StageResult;
use LumeWeb\Cast\Export\WpRemoteResponse;
use PHPUnit\Framework\TestCase;

/**
 * The loopback probe: a hard check before the queue. Prechecks pretty
 * permalinks/extensions/writable uploads, then GETs home anonymously with the
 * probe TLS policy, accepting only a 200 HTML body of plausible size or a
 * canonical slash/http→https same-origin redirect, and aborting with a human
 * sentence for every other outcome. Success records probe metrics plus the
 * canonical origin into the shared {@see PipelineState} — never credentials.
 */
final class ProbeStageTest extends TestCase
{
    public function testKeyIsProbe(): void
    {
        $stage = $this->stage(new FakeProbeEnvironment(), new FakeCaptureHttp());

        self::assertSame(PipelineStageKey::Probe, $stage->key());
    }

    public function testSendsK2ProbeRequestArguments(): void
    {
        $http = new FakeCaptureHttp([
            WpRemoteResponse::success(200, [], $this->htmlBody()),
        ]);
        $stage = $this->stage(new FakeProbeEnvironment(homeUrl: 'https://blog.example.test/'), $http);

        $result = $stage->execute('');

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertCount(1, $http->calls);
        self::assertSame('https://blog.example.test/', $http->calls[0]['url']);

        $args = $http->calls[0]['args'];
        self::assertSame(ProbeStage::PROBE_TIMEOUT, $args['timeout']);
        self::assertSame(0, $args['redirection']);
        self::assertTrue($args['blocking']);
        self::assertTrue($args['decompress']);
        self::assertFalse($args['stream']);
        self::assertSame('identity', $args['headers']['Accept-Encoding']);
        self::assertSame(ProbeStage::PROBE_UA, $args['headers']['User-Agent']);
        self::assertArrayNotHasKey('Authorization', $args['headers']);
        self::assertArrayNotHasKey('cookies', $args);
        self::assertTrue($args['sslverify']);
    }

    public function testSendsNoCookiesAndTlsDisabledForLocalOrigins(): void
    {
        foreach (['https://localhost/', 'https://127.0.0.1/', 'https://[::1]/', 'https://site.test/', 'https://site.localhost/'] as $url) {
            $http = new FakeCaptureHttp([WpRemoteResponse::success(200, [], $this->htmlBody())]);
            $this->stage(new FakeProbeEnvironment(homeUrl: $url), $http)->execute('');

            self::assertFalse($http->calls[0]['args']['sslverify'], $url);
            self::assertArrayNotHasKey('cookies', $http->calls[0]['args']);
        }
    }

    public function testTlsDisabledWhenWordPressEnvironmentIsLocal(): void
    {
        $http = new FakeCaptureHttp([WpRemoteResponse::success(200, [], $this->htmlBody())]);
        $http->localEnvironment = true;

        $this->stage(new FakeProbeEnvironment(homeUrl: 'https://blog.example.test/'), $http)->execute('');

        self::assertFalse($http->calls[0]['args']['sslverify']);
    }

    public function testPrecheckRefusesPlainPermalinksWithoutHttp(): void
    {
        $http = new FakeCaptureHttp();
        $stage = $this->stage(new FakeProbeEnvironment(permalinkStructure: ''), $http);

        $result = $stage->execute('');

        self::assertFalse($result->done);
        self::assertNotNull($result->failure);
        self::assertStringContainsString('permalink', strtolower($result->failure));
        self::assertCount(0, $http->calls);
    }

    public function testPrecheckRefusesMissingPhpExtensionsWithoutHttp(): void
    {
        foreach (['xml' => false, 'dom' => false, 'zip' => false] as $field => $_) {
            $env = new FakeProbeEnvironment();
            if ($field === 'xml') {
                $env->xml = false;
            } elseif ($field === 'dom') {
                $env->dom = false;
            } else {
                $env->zip = false;
            }
            $http = new FakeCaptureHttp();
            $result = $this->stage($env, $http)->execute('');

            self::assertNotNull($result->failure);
            self::assertCount(0, $http->calls, $field);
        }
    }

    public function testPrecheckRefusesUnwritableUploadsWithoutHttp(): void
    {
        $http = new FakeCaptureHttp();
        $result = $this->stage(new FakeProbeEnvironment(uploadsWritable: false), $http)->execute('');

        self::assertNotNull($result->failure);
        self::assertCount(0, $http->calls);
    }

    public function testAcceptsCanonicalTrailingSlashRedirectAndRecordsOrigin(): void
    {
        $http = new FakeCaptureHttp([
            WpRemoteResponse::success(301, ['location' => 'https://blog.example.test/'], '<html>redirect</html>'),
        ]);
        $state = new PipelineState();
        $stage = $this->stage(new FakeProbeEnvironment(homeUrl: 'https://blog.example.test/'), $http, state: $state);

        $result = $stage->execute('');

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertNotNull($state->probe);
        self::assertSame('blog.example.test', $state->probe->origin->host());
        self::assertSame('https', $state->probe->origin->scheme());
        self::assertSame('https://blog.example.test/', $state->probe->finalUrl);
    }

    public function testAcceptsHttpToHttpsTwinRedirectAndRecordsCanonicalOrigin(): void
    {
        $http = new FakeCaptureHttp([
            WpRemoteResponse::success(301, ['location' => 'https://blog.example.test/'], '<html>redirect</html>'),
        ]);
        $state = new PipelineState();
        $stage = $this->stage(new FakeProbeEnvironment(homeUrl: 'http://blog.example.test/'), $http, state: $state);

        $result = $stage->execute('');

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertNotNull($state->probe);
        self::assertSame('https', $state->probe->origin->scheme());
        self::assertSame('blog.example.test', $state->probe->origin->host());
        self::assertSame('https://blog.example.test/', $state->probe->finalUrl);
    }

    public function testRejectsOffOriginRedirect(): void
    {
        $http = new FakeCaptureHttp([
            WpRemoteResponse::success(302, ['location' => 'https://evil.example.net/'], '<html>redirect</html>'),
        ]);
        $stage = $this->stage(new FakeProbeEnvironment(homeUrl: 'https://blog.example.test/'), $http);

        $result = $stage->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('off-site', $result->failure);
        self::assertStringContainsString('evil.example.net', $result->failure);
    }

    public function testRejectsRedirectToSameOriginNonHomePath(): void
    {
        $http = new FakeCaptureHttp([
            WpRemoteResponse::success(301, ['location' => 'https://blog.example.test/welcome'], '<html>redirect</html>'),
        ]);
        $stage = $this->stage(new FakeProbeEnvironment(homeUrl: 'https://blog.example.test/'), $http);

        $result = $stage->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('welcome', $result->failure);
    }

    public function testRejectsNon200Statuses(): void
    {
        foreach ([404, 500, 503] as $status) {
            $http = new FakeCaptureHttp([WpRemoteResponse::success($status, [], 'oops')]);
            $result = $this->stage(new FakeProbeEnvironment(homeUrl: 'https://blog.example.test/'), $http)->execute('');

            self::assertNotNull($result->failure, (string) $status);
            self::assertStringContainsString((string) $status, $result->failure);
        }
    }

    public function testRejects401And403AsLoopbackAccessFailuresWithoutCredentialAdvice(): void
    {
        foreach ([401, 403] as $status) {
            $http = new FakeCaptureHttp([WpRemoteResponse::success($status, [], 'denied')]);
            $result = $this->stage(new FakeProbeEnvironment(homeUrl: 'https://blog.example.test/'), $http)->execute('');

            self::assertNotNull($result->failure, (string) $status);
            self::assertStringContainsString((string) $status, $result->failure);
            // A 401/403 loopback access failure must be safe and never coach
            // credentials: probe and capture are anonymous by contract (the
            // deployment's Caddy bypasses Basic Auth on the plugin's own
            // loopback requests), so there is no credential advice to give.
            self::assertStringNotContainsString('Basic Auth', $result->failure);
            self::assertStringNotContainsString('credential', strtolower($result->failure));
        }
    }

    public function testRejectsGhostBodyShorterThanOneKib(): void
    {
        $http = new FakeCaptureHttp([WpRemoteResponse::success(200, [], '<p>tiny</p>')]);
        $result = $this->stage(new FakeProbeEnvironment(homeUrl: 'https://blog.example.test/'), $http)->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('did not return HTML', $result->failure);
        self::assertStringContainsString('bytes', $result->failure);
    }

    public function testRejectsGhostBodyWithoutHtmlMarker(): void
    {
        $body = str_repeat('x', 2048);
        $http = new FakeCaptureHttp([WpRemoteResponse::success(200, [], $body)]);
        $result = $this->stage(new FakeProbeEnvironment(homeUrl: 'https://blog.example.test/'), $http)->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('html', strtolower($result->failure));
    }

    public function testWpDnsErrorGivesActionableSentence(): void
    {
        $http = new FakeCaptureHttp([
            WpRemoteResponse::error('http_request_failed', 'cURL error 7: Failed to connect to blog.example.test port 443'),
        ]);
        $result = $this->stage(new FakeProbeEnvironment(homeUrl: 'https://blog.example.test/'), $http)->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('cannot reach', $result->failure);
        self::assertStringContainsString('blog.example.test', $result->failure);
        self::assertStringContainsString('DNS', $result->failure);
    }

    public function testWpTlsErrorGivesTlsSentence(): void
    {
        $http = new FakeCaptureHttp([
            WpRemoteResponse::error('http_request_failed', 'cURL error 60: SSL certificate problem: self-signed certificate'),
        ]);
        $result = $this->stage(new FakeProbeEnvironment(homeUrl: 'https://blog.example.test/'), $http)->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('TLS', $result->failure);
        self::assertStringContainsString('certificate', $result->failure);
    }

    public function testRecordsProbeMetricsAndCanonicalOriginInStateWithoutSecrets(): void
    {
        $state = new PipelineState();
        $http = new FakeCaptureHttp([
            WpRemoteResponse::success(200, [], $this->htmlBody()),
        ]);
        $stage = $this->stage(
            new FakeProbeEnvironment(homeUrl: 'https://blog.example.test/'),
            $http,
            $state,
        );

        $result = $stage->execute('');

        self::assertTrue($result->done);
        self::assertNotNull($state->probe);
        self::assertSame('blog.example.test', $state->probe->origin->host());
        self::assertSame('https', $state->probe->origin->scheme());
        self::assertSame('https://blog.example.test/', $state->probe->finalUrl);
        self::assertSame(strlen($this->htmlBody()), $state->probe->bytes);
        self::assertGreaterThanOrEqual(0, $state->probe->durationMs);

        // No credential material anywhere in the persisted result surface.
        $serialized = json_encode($result) . $state->probe->origin . $state->probe->finalUrl;
        self::assertStringNotContainsString('s3cret', $serialized);
        self::assertNull($result->failure);
    }

    public function testProbeFailureNeverReachesTheQueue(): void
    {
        $state = new PipelineState();
        $http = new FakeCaptureHttp([WpRemoteResponse::success(500, [], 'boom')]);
        $result = $this->stage(new FakeProbeEnvironment(homeUrl: 'https://blog.example.test/'), $http, state: $state)->execute('');

        self::assertNotNull($result->failure);
        self::assertNull($state->probe);
    }

    private function htmlBody(): string
    {
        return '<!DOCTYPE html><html><head><title>Home</title></head><body><p>' . str_repeat('welcome', 200) . '</p></body></html>';
    }

    private function stage(
        FakeProbeEnvironment $env,
        FakeCaptureHttp $http,
        ?PipelineState $state = null,
    ): ProbeStage {
        return new ProbeStage(
            http: $http,
            environment: $env,
            state: $state ?? new PipelineState(),
        );
    }
}
