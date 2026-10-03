<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Integration;

use LumeWeb\Cast\Export\CaptureStage;
use LumeWeb\Cast\Export\InMemoryWorkItemRepository;
use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\PipelineStage;
use LumeWeb\Cast\Export\PipelineState;
use LumeWeb\Cast\Export\ProbeResult;
use LumeWeb\Cast\Export\RewriteStage;
use LumeWeb\Cast\Export\SetupResult;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use LumeWeb\Cast\Export\WordPressCaptureEnvironment;
use LumeWeb\Cast\Export\WordPressRewriteEnvironment;
use LumeWeb\Cast\Export\WorkItemStatus;
use LumeWeb\Cast\Export\WorkItemFactory;
use Masterminds\HTML5;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic integration corpus for the capture→rewrite pipeline.
 *
 * The corpus page is a fixed representative full HTML document (tests/
 * Fixtures/Integration/corpus-page.html) served, together with its theme
 * assets and the wlwmanifest core file, through WordPress' own
 * pre_http_request loopback. Everything else is real: the CaptureStage +
 * RewriteStage run over a real work directory with the real WordPress
 * capture/rewrite environments and the real RewriteService. The assertions
 * target functional semantics — entity-encoded in-origin URL rewrite/queue,
 * srcset, CSS/JS/JSON delegation, canonical/OG preservation, WordPress
 * fingerprint removal, external/non-web pass-through — not output bytes.
 */
final class ExportCorpusFixtureTest extends TestCase
{
    private const RUN = 'run-1';
    private const PAGE_URL = 'http://example.org/corpus-page/';
    private const THEME_URL_ROOT = 'http://example.org/wp-content/themes/corpus-fixture/';
    private const THEME_PATH_ROOT = '/wp-content/themes/corpus-fixture/';
    private const FIXTURE_PATH = __DIR__ . '/../Fixtures/Integration/corpus-page.html';
    private const TICK_CAP = 30;

    /** A 1x1 transparent PNG for the three binary corpus assets. */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mN8/j+F9wH0fwdqVQAAAABJRU5ErkJggg==';

    private string $workDir = '';
    private string $siteDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = $this->createTempDir('cast-corpus-work-');
        $this->siteDir = $this->createTempDir('cast-corpus-site-');
        $this->seedThemeAssets();
        $this->installLoopbackServer();
    }

    protected function tearDown(): void
    {
        remove_all_filters('pre_http_request');
        $this->removeDirectory($this->workDir);
        $this->removeDirectory($this->siteDir);

        parent::tearDown();
    }

    public function testCaptureAndRewriteApplyTheCorpusSemantics(): void
    {
        [$repository, $state] = $this->queuePageItem();

        $this->driveStageToDone(new CaptureStage(new WordPressCaptureEnvironment(), $state, $repository));
        self::assertNotNull($state->capture, 'Capture must record its summary on completion.');
        self::assertSame(1, $state->capture->done);
        self::assertSame(0, $state->capture->failed);
        self::assertSame(
            (string) file_get_contents(self::FIXTURE_PATH),
            (string) file_get_contents($this->workDir . '/corpus-page/index.html'),
            'Capture must store the fetched document byte-faithfully.',
        );

        $stage = new RewriteStage(new WordPressRewriteEnvironment($this->workDir), $state, $repository, new WordPressCaptureEnvironment());
        $warnings = $this->driveStageToDone($stage);

        self::assertSame([], $warnings, 'The corpus run must surface no rewrite warnings.');

        // The five theme assets (plus the wlwmanifest) are collected at the
        // urgent rewrite-derived priority; identity ignores cache-busting queries.
        foreach (['style.css', 'query.css', 'img-800.png', 'bg.png', 'og.png'] as $asset) {
            self::assertSame(1, $repository->priorityOf(self::RUN, hash('md5', self::THEME_URL_ROOT . $asset)), sprintf('The rewrite must collect %s.', $asset));
        }
        self::assertSame(1, $repository->priorityOf(self::RUN, hash('md5', 'http://example.org/wp-includes/wlwmanifest.xml')), 'The rewrite must collect the wlwmanifest.');

        self::assertSame(4, $repository->countByStatus(self::RUN, WorkItemStatus::Rewritten), 'Page, both stylesheets and the wlwmanifest must be rewritten.');
        self::assertSame(3, $repository->countByStatus(self::RUN, WorkItemStatus::Done), 'The three images must pass through Done.');
        self::assertSame(0, $repository->pendingCount(self::RUN));
        self::assertNotNull($state->rewrite, 'Rewrite must record its summary at the fixed point.');
        self::assertSame(4, $state->rewrite->rewritten);
        self::assertSame(3, $state->rewrite->passedThrough);
        self::assertSame(6, $state->rewrite->assetsCollected);

        $rewritten = (string) file_get_contents($this->workDir . '/corpus-page/index.html');

        // Rewritten attribute values: entity-encoded in-origin query asset and
        // plain in-origin link to local paths, non-web and external untouched,
        // srcset candidates split without destroying the external comma, CSS
        // delegation (style attribute + <style>), JS delegation, and the
        // canonical / Open Graph / enqueued-stylesheet head survivors.
        $expectedReferences = [
            'href="./../wp-content/themes/corpus-fixture/query.css"',
            'href="./../wp-content/themes/corpus-fixture/style.css"',
            'href="mailto:hello@example.org"',
            'src="https://cdn.other.com/external.png"',
            'srcset="./../wp-content/themes/corpus-fixture/img-800.png 800w, https://res.cloudinary.com/demo/f_auto,q_auto/distant.jpg 1600w"',
            "style=\"background-image:url('./../wp-content/themes/corpus-fixture/bg.png')\"",
            '.corpus-inline { background: url(./../wp-content/themes/corpus-fixture/bg.png); }',
            'var corpusLogo = "./../wp-content/themes/corpus-fixture/img-800.png";',
            'rel="canonical" href="./index.html"',
            'property="og:image" content="./../wp-content/themes/corpus-fixture/og.png"',
            'id="corpus-fixture-style-css" href="./../wp-content/themes/corpus-fixture/style.css"',
        ];
        foreach ($expectedReferences as $expected) {
            self::assertStringContainsString($expected, $rewritten, sprintf('The rewritten page must contain: %s', $expected));
        }

        // JSON delegation: data-settings stays valid JSON with the in-origin
        // value rewritten and the external value untouched.
        $settings = null;
        foreach ((new HTML5())->loadHTML($rewritten)->getElementsByTagName('div') as $div) {
            if ($div->hasAttribute('data-settings')) {
                $settings = json_decode($div->getAttribute('data-settings'), true, 512, JSON_THROW_ON_ERROR);
            }
        }
        self::assertSame(
            ['bg' => './../wp-content/themes/corpus-fixture/bg.png', 'external' => 'https://cdn.other.com/keep.png'],
            $settings,
            'The data-settings attribute must remain valid JSON with the in-origin value rewritten.',
        );

        // The head strip removed the WordPress fingerprints.
        foreach (['name="generator"', 'application/rsd+xml', 'wlwmanifest', 'api.w.org', 'application/json+oembed', 'text/xml+oembed', 'application/rss+xml'] as $fingerprint) {
            self::assertStringNotContainsString($fingerprint, $rewritten, sprintf('The head strip must remove the %s fingerprint.', $fingerprint));
        }

        // No in-origin absolute URL survives anywhere in the rewritten page.
        self::assertStringNotContainsString('http://example.org', $rewritten, 'Every in-origin reference must be rewritten to a local path.');

        // The captured stylesheet was rewritten: the in-origin reference is local.
        $css = (string) file_get_contents($this->workDir . self::THEME_PATH_ROOT . 'style.css');
        self::assertStringNotContainsString('http://example.org', $css);
        self::assertStringContainsString('url(./bg.png)', $css);

        // Binary assets pass through the pipeline byte-identical.
        self::assertFileEquals(
            $this->siteDir . self::THEME_PATH_ROOT . 'img-800.png',
            $this->workDir . self::THEME_PATH_ROOT . 'img-800.png',
            'Binary assets must pass through the pipeline byte-identical.',
        );
    }

    private function state(): PipelineState
    {
        $state = new PipelineState();
        $state->runId = self::RUN;

        return $state;
    }

    /**
     * @return array{0: InMemoryWorkItemRepository, 1: PipelineState}
     */
    private function queuePageItem(): array
    {
        $origin = Origin::fromUrl((new UrlCanonicalizer())->canonicalize('http://example.org/'));
        $state = $this->state();
        $state->probe = new ProbeResult($origin, self::PAGE_URL, 0, 0);
        $state->setup = new SetupResult($this->workDir);
        $repository = new InMemoryWorkItemRepository();
        $repository->insertCanonical(self::RUN, (new WorkItemFactory())->fromString(self::PAGE_URL), 10);

        return [$repository, $state];
    }

    /**
     * Drive a stage one bounded unit per tick until done, collecting warnings.
     *
     * @return list<string>
     */
    private function driveStageToDone(PipelineStage $stage): array
    {
        $cursor = '';
        $warnings = [];
        for ($tick = 0; $tick < self::TICK_CAP; ++$tick) {
            $result = $stage->execute($cursor);
            self::assertNull($result->failure, 'The stage must not fail; it reported: ' . (string) $result->failure);
            $warnings = array_merge($warnings, $result->warnings);
            if ($result->done) {
                return $warnings;
            }
            $cursor = $result->cursor;
        }

        self::fail(sprintf('The stage did not reach its fixed point within %d ticks.', self::TICK_CAP));
    }

    /**
     * The loopback "server": the real wp_remote_get() boundary short-circuited
     * through pre_http_request — the fixture page, the scratch files, 404 else.
     */
    private function installLoopbackServer(): void
    {
        $fixture = (string) file_get_contents(self::FIXTURE_PATH);
        $siteDir = $this->siteDir;
        add_filter(
            'pre_http_request',
            function ($preempt, array $args, string $url) use ($fixture, $siteDir) {
                if (!str_starts_with($url, 'http://example.org')) {
                    return $preempt;
                }

                $path = (string) parse_url($url, PHP_URL_PATH);
                if ($path === '/corpus-page/') {
                    return $this->loopbackResponse($args, $fixture, 200, 'OK', ['content-type' => 'text/html; charset=UTF-8']);
                }

                if (is_file($siteDir . $path)) {
                    return $this->loopbackResponse($args, (string) file_get_contents($siteDir . $path), 200, 'OK', ['content-type' => 'application/octet-stream']);
                }

                return $this->loopbackResponse($args, '404 Not Found', 404, 'Not Found', ['content-type' => 'text/html']);
            },
            10,
            3,
        );
    }

    /**
     * A loopback response honouring the capture transport's streaming
     * contract: a streamed request gets the body written to its filename.
     *
     * @param array<string, mixed> $args
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function loopbackResponse(array $args, string $body, int $code, string $message, array $headers): array
    {
        $response = [
            'headers' => $headers,
            'body' => $body,
            'response' => ['code' => $code, 'message' => $message],
            'cookies' => [],
            'filename' => null,
        ];

        if (isset($args['filename']) && is_string($args['filename'])) {
            $response['filename'] = $args['filename'];
            file_put_contents($args['filename'], $body);
        }

        return $response;
    }

    /** The corpus files the loopback serves: five theme assets plus wlwmanifest. */
    private function seedThemeAssets(): void
    {
        $theme = $this->siteDir . self::THEME_PATH_ROOT;
        if (!mkdir($theme, 0777, true)) {
            self::fail('Unable to create the corpus theme directory: ' . $theme);
        }

        file_put_contents($theme . '/style.css', "body { background: url(" . self::THEME_URL_ROOT . "bg.png); }\n");
        file_put_contents($theme . '/query.css', ".query-corpus { color: #246; }\n");

        $png = base64_decode(self::PNG_BASE64, true);
        self::assertIsString($png, 'The corpus PNG fixture must decode.');
        foreach (['img-800.png', 'bg.png', 'og.png'] as $name) {
            file_put_contents($theme . '/' . $name, $png);
        }

        $includes = $this->siteDir . '/wp-includes';
        if (!mkdir($includes, 0777, true)) {
            self::fail('Unable to create the corpus wp-includes directory: ' . $includes);
        }
        file_put_contents($includes . '/wlwmanifest.xml', "<?xml version=\"1.0\"?>\n<manifest>\n</manifest>\n");
    }

    private function createTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . uniqid('', true);
        if (!mkdir($dir, 0777, true)) {
            self::fail('Unable to create the directory: ' . $dir);
        }

        return $dir;
    }

    /** Recursively remove a directory tree (test scratch on disk only). */
    private function removeDirectory(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        exec('rm -rf ' . escapeshellarg($dir) . ' 2>/dev/null');
    }
}
