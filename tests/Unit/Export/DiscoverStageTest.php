<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\DiscoverStage;
use LumeWeb\Cast\Export\InMemoryWorkItemRepository;
use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\PipelineStageKey;
use LumeWeb\Cast\Export\PipelineState;
use LumeWeb\Cast\Export\ProbeResult;
use LumeWeb\Cast\Export\SitemapLimits;
use LumeWeb\Cast\Export\StageResult;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use LumeWeb\Cast\Export\WorkItemFactory;
use LumeWeb\Cast\Export\WorkItemStatus;
use LumeWeb\Cast\Export\WpRemoteResponse;
use PHPUnit\Framework\TestCase;

/**
 * The discovery stage: several finite producer cursors (static seeders, a
 * keyset over published content, and a bounded origin-only sitemap crawl) feed
 * one deduplicated {@see WorkItemRepository}. Every producer runs one bounded
 * unit per tick and resumes from an opaque cursor; the stage is only done when
 * all producer cursors have exhausted.
 */
final class DiscoverStageTest extends TestCase
{
    private const ORIGIN = 'https://blog.example.test/';

    private InMemoryWorkItemRepository $repo;
    private PipelineState $state;

    protected function setUp(): void
    {
        $this->repo = new InMemoryWorkItemRepository();
        $this->state = $this->probeState();
    }

    public function testPipelineStateDeclaresTheDiscoverSlot(): void
    {
        $reflection = new \ReflectionClass(PipelineState::class);

        self::assertTrue($reflection->hasProperty('discover'));
        self::assertNull($reflection->getProperty('discover')->getDefaultValue());
    }

    public function testKeyIsDiscover(): void
    {
        $stage = $this->stage(new FakeDiscoverEnvironment(), new FakeCaptureHttp());

        self::assertSame(PipelineStageKey::Discover, $stage->key());
    }

    public function testRequiresSuccessfulProbeBeforeSeeding(): void
    {
        $stage = $this->stage(new FakeDiscoverEnvironment(), new FakeCaptureHttp(), new PipelineState());

        $result = $stage->execute('');

        self::assertNotNull($result->failure);
        self::assertStringContainsString('probe', strtolower($result->failure));
        self::assertSame(0, $this->repo->countByStatus(WorkItemStatus::Queued));
    }

    public function testSeedsStaticSeedersThroughGateAndRepository(): void
    {
        $env = new FakeDiscoverEnvironment(staticSeeds: [
            self::ORIGIN,
            'https://blog.example.test/robots.txt',
            'https://blog.example.test/favicon.ico',
        ]);

        $result = $this->drainAll($env, new FakeCaptureHttp());

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame(3, $this->repo->countByStatus(WorkItemStatus::Queued));
        self::assertSame(3, $this->state->discover?->enqueued);
    }

    public function testOneProducerCursorUnitPerTick(): void
    {
        $env = new FakeDiscoverEnvironment(staticSeeds: [
            self::ORIGIN,
            'https://blog.example.test/robots.txt',
        ]);
        $stage = $this->stage($env, new FakeCaptureHttp());

        $first = $stage->execute('');

        self::assertFalse($first->done);
        self::assertSame('seed:1', $first->cursor);
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Queued));

        $result = $this->drainAll($env, new FakeCaptureHttp());

        self::assertTrue($result->done);
        self::assertSame(2, $this->repo->countByStatus(WorkItemStatus::Queued));
    }

    public function testExclusionPolicyFiltersSeeds(): void
    {
        $env = new FakeDiscoverEnvironment(staticSeeds: [
            self::ORIGIN,
            'https://blog.example.test/wp-admin/',
            'https://blog.example.test/?rest_route=/wp/v2/posts',
        ]);

        $result = $this->drainAll($env, new FakeCaptureHttp());

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Queued));
    }

    public function testWildcardPseudoUrlsAreDroppedWithoutCount(): void
    {
        // A glob pattern never names one real resource: the enqueue check
        // refuses each with InvalidUrl, so the seed unit reports zero created
        // progress and nothing is queued (mirroring the OffOrigin/invalid
        // skip path — dropped, not a failure, not counted).
        $env = new FakeDiscoverEnvironment(staticSeeds: [
            self::ORIGIN,
            'https://blog.example.test/wp-admin/*',
            'https://blog.example.test/wp-*.php',
            'https://blog.example.test/?s=*',
        ]);

        $result = $this->drainAll($env, new FakeCaptureHttp());

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Queued), 'only the real root page is queued');
        self::assertSame(1, $this->state->discover?->enqueued);
        foreach (['https://blog.example.test/wp-admin/*', 'https://blog.example.test/wp-*.php', 'https://blog.example.test/?s=*'] as $glob) {
            $hash = (new WorkItemFactory())->fromString($glob)->urlHash();
            self::assertNull($this->repo->priorityOf($hash), "glob {$glob} never reaches the queue");
        }
    }

    public function testOffOriginAndInvalidSeedsAreSkippedNotFailed(): void
    {
        $env = new FakeDiscoverEnvironment(staticSeeds: [
            self::ORIGIN,
            'https://evil.example.net/away',
            'this is not a url',
        ]);

        $result = $this->drainAll($env, new FakeCaptureHttp());

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Queued));
    }

    public function testHardCapStopsSeedingEarlyWithWarning(): void
    {
        $env = new FakeDiscoverEnvironment(staticSeeds: [
            'https://blog.example.test/a/',
            'https://blog.example.test/b/',
            'https://blog.example.test/c/',
            'https://blog.example.test/d/',
        ]);
        $stage = $this->stage($env, new FakeCaptureHttp(), maxItems: 2);

        $first = $stage->execute('');
        self::assertSame('seed:1', $first->cursor);
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Queued));

        $second = $this->stage($env, new FakeCaptureHttp(), maxItems: 2)->execute('seed:1');

        self::assertTrue($second->done);
        self::assertNull($second->failure);
        self::assertNotEmpty($second->warnings);
        self::assertStringContainsString('cap', strtolower(implode(' ', $second->warnings)));
        self::assertSame(2, $this->repo->countByStatus(WorkItemStatus::Queued));
        self::assertSame(2, $this->state->discover?->enqueued);
    }

    public function testPostsKeysetDrainsASingleShortPage(): void
    {
        $env = new FakeDiscoverEnvironment(postPages: [
            ['ids' => [11, 12], 'urls' => ['https://blog.example.test/hello/', 'https://blog.example.test/about/']],
        ]);

        $result = $this->drainAll($env, new FakeCaptureHttp());

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame(2, $this->repo->countByStatus(WorkItemStatus::Queued));
    }

    public function testPostsKeysetContinuesAcrossPages(): void
    {
        $urls = [];
        $ids = [];
        for ($id = 1; $id <= 50; ++$id) {
            $ids[] = $id;
            $urls[] = sprintf('https://blog.example.test/post-%d/', $id);
        }
        $env = new FakeDiscoverEnvironment(postPages: [
            ['ids' => $ids, 'urls' => $urls],
            ['ids' => [51, 52], 'urls' => ['https://blog.example.test/post-51/', 'https://blog.example.test/post-52/']],
        ]);
        $stage = $this->stage($env, new FakeCaptureHttp());

        $first = $stage->execute('');

        self::assertFalse($first->done);
        self::assertStringStartsWith('posts:post:', $first->cursor);
        self::assertSame(50, $this->repo->countByStatus(WorkItemStatus::Queued));

        $second = $this->stage($env, new FakeCaptureHttp())->execute($first->cursor);

        self::assertTrue($second->done);
        self::assertNull($second->failure);
        self::assertSame(52, $this->repo->countByStatus(WorkItemStatus::Queued));
    }

    public function testResumeContinuesFromSeedCursorWithAFreshStage(): void
    {
        $env = new FakeDiscoverEnvironment(staticSeeds: [
            'https://blog.example.test/a/',
            'https://blog.example.test/b/',
        ]);

        $first = $this->stage($env, new FakeCaptureHttp())->execute('');

        self::assertSame('seed:1', $first->cursor);
        self::assertSame(1, $this->repo->countByStatus(WorkItemStatus::Queued));

        $resumed = $this->stage($env, new FakeCaptureHttp())->execute('seed:1');

        self::assertFalse($resumed->done);
        self::assertSame('seed:2', $resumed->cursor);
        self::assertSame(2, $this->repo->countByStatus(WorkItemStatus::Queued));
    }

    public function testDoneOnlyAfterAllProducerCursorsExhaust(): void
    {
        $env = new FakeDiscoverEnvironment(
            staticSeeds: [
                self::ORIGIN,
                'https://blog.example.test/robots.txt',
            ],
            postPages: [
                ['ids' => [7, 8], 'urls' => ['https://blog.example.test/seven/', 'https://blog.example.test/eight/']],
            ],
        );

        $result = $this->drainAll($env, new FakeCaptureHttp());

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame('', $result->cursor);
        self::assertSame(4, $this->repo->countByStatus(WorkItemStatus::Queued));
        self::assertSame(4, $this->state->discover?->enqueued);
    }

    public function testEmptySiteDiscoversNothingAndCompletes(): void
    {
        $http = new FakeCaptureHttp();
        $env = new FakeDiscoverEnvironment();

        $result = $this->drainAll($env, $http);

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame(0, $this->repo->countByStatus(WorkItemStatus::Queued));
        self::assertSame(0, $this->state->discover?->enqueued);
        self::assertCount(0, $http->calls);
    }

    public function testSitemapFetchUsesOriginOnlyIdentityRequestArguments(): void
    {
        $http = new FakeCaptureHttp([
            WpRemoteResponse::success(200, [], $this->urlsetXml(['https://blog.example.test/page/'])),
        ]);
        $env = new FakeDiscoverEnvironment(sitemapCandidates: ['https://blog.example.test/sitemap_index.xml']);

        $result = $this->drainAll($env, $http);

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertCount(1, $http->calls);
        self::assertSame('https://blog.example.test/sitemap_index.xml', $http->calls[0]['url']);

        $args = $http->calls[0]['args'];
        self::assertSame(DiscoverStage::SITEMAP_TIMEOUT, $args['timeout']);
        self::assertSame(0, $args['redirection']);
        self::assertTrue($args['blocking']);
        self::assertTrue($args['decompress']);
        self::assertFalse($args['stream']);
        self::assertSame('identity', $args['headers']['Accept-Encoding']);
        self::assertSame(DiscoverStage::SITEMAP_UA, $args['headers']['User-Agent']);
        self::assertArrayNotHasKey('Authorization', $args['headers']);
        self::assertArrayNotHasKey('cookies', $args);
        self::assertTrue($args['sslverify']);
    }

    public function testSitemapTlsDisabledForLocalOrigins(): void
    {
        foreach (['https://localhost/', 'https://127.0.0.1/', 'https://[::1]/', 'https://site.test/', 'https://site.localhost/'] as $originUrl) {
            $http = new FakeCaptureHttp([WpRemoteResponse::success(200, [], $this->urlsetXml([]))]);
            $state = $this->probeStateForOrigin($originUrl);
            $env = new FakeDiscoverEnvironment(sitemapCandidates: [rtrim($originUrl, '/') . '/sitemap_index.xml']);

            $cursor = '';
            $result = StageResult::more('');
            for ($tick = 0; $tick < 10 && count($http->calls) === 0 && $result->failure === null; ++$tick) {
                $result = $this->stage($env, $http, $state)->execute($cursor);
                $cursor = $result->cursor;
            }

            self::assertCount(1, $http->calls, $originUrl);
            self::assertArrayNotHasKey('cookies', $http->calls[0]['args']);
            self::assertFalse($http->calls[0]['args']['sslverify'], $originUrl);
        }
    }

    public function testSitemapTlsDisabledInLocalWordPressEnvironment(): void
    {
        $http = new FakeCaptureHttp([WpRemoteResponse::success(200, [], $this->urlsetXml([]))]);
        $http->localEnvironment = true;
        $env = new FakeDiscoverEnvironment(sitemapCandidates: ['https://blog.example.test/sitemap_index.xml']);

        $result = $this->drainAll($env, $http);

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertFalse($http->calls[0]['args']['sslverify']);
    }

    public function testEnqueuesSitemapDocumentsAndTheirLocalLocs(): void
    {
        $http = new FakeCaptureHttp([
            WpRemoteResponse::success(200, [], $this->sitemapindexXml(['https://blog.example.test/sitemap-1.xml'])),
            WpRemoteResponse::success(200, [], $this->urlsetXml(['https://blog.example.test/page/'])),
        ]);
        $env = new FakeDiscoverEnvironment(sitemapCandidates: ['https://blog.example.test/sitemap_index.xml']);

        $result = $this->drainAll($env, $http);

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertCount(2, $http->calls);
        self::assertSame(3, $this->repo->countByStatus(WorkItemStatus::Queued));
        self::assertSame(3, $this->state->discover?->enqueued);
        self::assertTrue($this->isQueued('https://blog.example.test/sitemap_index.xml'));
        self::assertTrue($this->isQueued('https://blog.example.test/sitemap-1.xml'));
        self::assertTrue($this->isQueued('https://blog.example.test/page/'));
    }

    public function testRejectsOffOriginSitemapLocsAndEnqueuesOnlyLocalLocs(): void
    {
        $http = new FakeCaptureHttp([
            WpRemoteResponse::success(200, [], $this->urlsetXml([
                'https://blog.example.test/page/',
                'https://evil.example.net/steal',
            ])),
        ]);
        $env = new FakeDiscoverEnvironment(sitemapCandidates: ['https://blog.example.test/sitemap_index.xml']);

        $result = $this->drainAll($env, $http);

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame(2, $this->repo->countByStatus(WorkItemStatus::Queued));
        self::assertFalse($this->isQueued('https://evil.example.net/steal'));
    }

    public function testRejectsDoctypeAndEntitySitemapBodies(): void
    {
        foreach (
            [
                '<!DOCTYPE urlset [<!ELEMENT urlset ANY>]><urlset/>',
                '<!DOCTYPE urlset [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><urlset>&xxe;</urlset>',
            ] as $body
        ) {
            $http = new FakeCaptureHttp([WpRemoteResponse::success(200, [], $body)]);
            $env = new FakeDiscoverEnvironment(sitemapCandidates: ['https://blog.example.test/sitemap_index.xml']);

            $result = $this->drainAll($env, $http);

            self::assertNotNull($result->failure);
            self::assertStringContainsStringIgnoringCase('DOCTYPE', $result->failure);
        }
    }

    public function testRejectsMalformedHttpErrorAndOversizedSitemapBodies(): void
    {
        $http = new FakeCaptureHttp([WpRemoteResponse::success(200, [], '<urlset><url><loc>oops')]);
        $env = new FakeDiscoverEnvironment(sitemapCandidates: ['https://blog.example.test/sitemap_index.xml']);
        $result = $this->drainAll($env, $http);
        self::assertNotNull($result->failure);
        self::assertStringContainsStringIgnoringCase('malformed', $result->failure);

        $http = new FakeCaptureHttp([WpRemoteResponse::success(404, [], 'not found')]);
        $env = new FakeDiscoverEnvironment(sitemapCandidates: ['https://blog.example.test/sitemap_index.xml']);
        $result = $this->drainAll($env, $http);
        self::assertNotNull($result->failure);
        self::assertStringContainsString('404', $result->failure);

        $http = new FakeCaptureHttp([WpRemoteResponse::error('http_request_failed', 'Could not resolve host')]);
        $env = new FakeDiscoverEnvironment(sitemapCandidates: ['https://blog.example.test/sitemap_index.xml']);
        $result = $this->drainAll($env, $http);
        self::assertNotNull($result->failure);
        self::assertStringContainsStringIgnoringCase('sitemap', $result->failure);

        $http = new FakeCaptureHttp([WpRemoteResponse::success(200, [], str_repeat('x', 64))]);
        $env = new FakeDiscoverEnvironment(sitemapCandidates: ['https://blog.example.test/sitemap_index.xml']);
        $result = $this->drainAll($env, $http, sitemapLimits: new SitemapLimits(maxBodyBytes: 16));
        self::assertNotNull($result->failure);
        self::assertStringContainsStringIgnoringCase('limit', $result->failure);
    }

    public function testSitemapResumesAcrossTicksWithAFreshStage(): void
    {
        $http = new FakeCaptureHttp([
            WpRemoteResponse::success(200, [], $this->urlsetXml(['https://blog.example.test/a/'])),
            WpRemoteResponse::success(200, [], $this->urlsetXml(['https://blog.example.test/b/'])),
        ]);
        $env = new FakeDiscoverEnvironment(sitemapCandidates: [
            'https://blog.example.test/sitemap-a.xml',
            'https://blog.example.test/sitemap-b.xml',
        ]);

        $cursor = '';
        $result = null;
        for ($tick = 0; $tick < 50 && count($http->calls) === 0; ++$tick) {
            $result = $this->stage($env, $http)->execute($cursor);
            $cursor = $result->cursor;
        }

        self::assertNotNull($result);
        self::assertCount(1, $http->calls);
        self::assertFalse($result->done);
        self::assertStringStartsWith('sitemap:', $cursor);
        self::assertSame(2, $this->repo->countByStatus(WorkItemStatus::Queued));

        $resumed = $this->stage($env, $http)->execute($cursor);

        self::assertTrue($resumed->done);
        self::assertNull($resumed->failure);
        self::assertCount(2, $http->calls);
        self::assertSame(4, $this->repo->countByStatus(WorkItemStatus::Queued));
    }

    public function testEnforcesSitemapDepthCap(): void
    {
        $http = new FakeCaptureHttp([
            WpRemoteResponse::success(200, [], $this->sitemapindexXml(['https://blog.example.test/sitemap-child.xml'])),
            WpRemoteResponse::success(200, [], $this->sitemapindexXml(['https://blog.example.test/sitemap-grandchild.xml'])),
        ]);
        $env = new FakeDiscoverEnvironment(sitemapCandidates: ['https://blog.example.test/sitemap_index.xml']);

        $result = $this->drainAll($env, $http, sitemapLimits: new SitemapLimits(maxDepth: 1));

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertCount(2, $http->calls);
        self::assertSame(2, $this->repo->countByStatus(WorkItemStatus::Queued));
        self::assertTrue($this->isQueued('https://blog.example.test/sitemap_index.xml'));
        self::assertTrue($this->isQueued('https://blog.example.test/sitemap-child.xml'));
        self::assertFalse($this->isQueued('https://blog.example.test/sitemap-grandchild.xml'));
    }

    public function testEnforcesSitemapLocCountCap(): void
    {
        $http = new FakeCaptureHttp([
            WpRemoteResponse::success(200, [], $this->urlsetXml([
                'https://blog.example.test/1/',
                'https://blog.example.test/2/',
                'https://blog.example.test/3/',
            ])),
        ]);
        $env = new FakeDiscoverEnvironment(sitemapCandidates: ['https://blog.example.test/sitemap_index.xml']);

        $result = $this->drainAll($env, $http, sitemapLimits: new SitemapLimits(maxLocs: 2));

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertSame(3, $this->repo->countByStatus(WorkItemStatus::Queued));
        self::assertFalse($this->isQueued('https://blog.example.test/3/'));
    }

    public function testHardCapAppliesDuringSitemapIngestion(): void
    {
        $http = new FakeCaptureHttp([
            WpRemoteResponse::success(200, [], $this->urlsetXml([
                'https://blog.example.test/a/',
                'https://blog.example.test/b/',
                'https://blog.example.test/c/',
            ])),
        ]);
        $env = new FakeDiscoverEnvironment(sitemapCandidates: ['https://blog.example.test/sitemap_index.xml']);

        $result = $this->drainAll($env, $http, maxItems: 2);

        self::assertTrue($result->done);
        self::assertNull($result->failure);
        self::assertNotEmpty($result->warnings);
        self::assertStringContainsString('cap', strtolower(implode(' ', $result->warnings)));
    }

    private function probeStateForOrigin(string $originUrl): PipelineState
    {
        $state = new PipelineState();
        $origin = Origin::fromUrl((new UrlCanonicalizer())->canonicalize($originUrl));
        $state->probe = new ProbeResult($origin, $originUrl, 2048, 5);

        return $state;
    }

    /**
     * @param list<string> $locs
     */
    private function urlsetXml(array $locs): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($locs as $loc) {
            $xml .= '<url><loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc></url>';
        }

        return $xml . '</urlset>';
    }

    /**
     * @param list<string> $locs
     */
    private function sitemapindexXml(array $locs): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($locs as $loc) {
            $xml .= '<sitemap><loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc></sitemap>';
        }

        return $xml . '</sitemapindex>';
    }

    private function isQueued(string $url): bool
    {
        $canonical = (new UrlCanonicalizer())->canonicalize($url);

        return $this->repo->priorityOf(md5($canonical->base())) !== null;
    }

    private function probeState(): PipelineState
    {
        $state = new PipelineState();
        $origin = Origin::fromUrl((new UrlCanonicalizer())->canonicalize(self::ORIGIN));
        $state->probe = new ProbeResult($origin, self::ORIGIN, 2048, 5);

        return $state;
    }

    private function stage(
        FakeDiscoverEnvironment $env,
        FakeCaptureHttp $http,
        ?PipelineState $state = null,
        int $maxItems = DiscoverStage::DEFAULT_MAX_ITEMS,
        SitemapLimits $sitemapLimits = new SitemapLimits(),
    ): DiscoverStage {
        return new DiscoverStage(
            environment: $env,
            state: $state ?? $this->state,
            repository: $this->repo,
            http: $http,
            sitemapLimits: $sitemapLimits,
            maxItems: $maxItems,
        );
    }

    private function drainAll(
        FakeDiscoverEnvironment $env,
        FakeCaptureHttp $http,
        int $maxItems = DiscoverStage::DEFAULT_MAX_ITEMS,
        SitemapLimits $sitemapLimits = new SitemapLimits(),
    ): StageResult {
        $cursor = '';
        $result = StageResult::more('');
        for ($tick = 0; $tick < 100; ++$tick) {
            $result = $this->stage($env, $http, maxItems: $maxItems, sitemapLimits: $sitemapLimits)->execute($cursor);
            $cursor = $result->cursor;
            if ($result->done || $result->failure !== null) {
                break;
            }
        }

        return $result;
    }
}
