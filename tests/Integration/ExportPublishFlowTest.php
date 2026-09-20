<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Integration;

use GuzzleHttp\Psr7\Response;
use LumeWeb\Cast\Environment\EnvIdentity;
use LumeWeb\Cast\Export\RunStatus;
use LumeWeb\Cast\Export\WorkItemStatus;
use LumeWeb\Cast\Jobs\ContentPublishScheduler;
use LumeWeb\Cast\Jobs\JobsHookSubscriber;
use LumeWeb\Cast\Jobs\PublishIdentity;
use LumeWeb\Cast\Jobs\WordPressActionScheduler;
use LumeWeb\Cast\Jobs\WordPressIdentityGateway;
use LumeWeb\Cast\Jobs\WordPressLock;
use LumeWeb\Cast\Persistence\CastExportItemsTable;
use LumeWeb\Cast\Persistence\WordPressCastExportItemsTable;
use LumeWeb\Cast\Persistence\WordPressOptionGateway;
use LumeWeb\Cast\Persistence\WordPressRunRepository;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Real WordPress + real Action Scheduler export/publish flow.
 *
 * These tests boot the plugin against the pinned WordPress 7.1 + MariaDB
 * harness. A tiny published fixture (a real row in the wptests_posts table,
 * inserted through the real post factory) travels the REAL save path: the
 * `transition_post_status` hook fires the plugin's dirty-marking scheduler,
 * which creates the pending export run in the single `cast_export_run`
 * option. A real tick on the `cast/export/auto-tick` hook then consumes it
 * and the test asserts concrete transitions on the persisted run — exactly
 * the storage the boot composition reads after every tick.
 *
 * The exported flow is deterministic because the only untrusted boundary — the
 * loopback HTTP probe the probe stage performs against the site home — is
 * short-circuited through the real WordPress `pre_http_request` filter
 * (the same boundary production uses to swap HTTP backends). Nothing else is
 * stubbed: the WordPress option stores, the Action Scheduler adapter
 * (WordPressActionScheduler, which CastPlugin composes), the lease lock, the
 * run aggregate and the pipeline orchestrator all run as composed by
 * CastPlugin::boot().
 */
final class ExportPublishFlowTest extends TestCase
{
    public const RUN_LOCK_OPTION = WordPressLock::OPTION_PREFIX . 'cast:export-tick';

    /**
     * The bounded upper bound on real ticks this class will fire before
     * declaring the run stuck. The pipeline advances exactly one bounded unit
     * per tick, so a small cap is far more than a clean run needs while still
     * halting a silently stalled pipeline.
     */
    private const SUCCESS_TICK_CAP = 40;

    /**
     * The CID the injected publish transport echoes back from the upload and
     * readiness responses. The SDK decodes it as the canonical
     * PostUploadResponse and the readiness wait verifies the site serves
     * exactly this CID.
     */
    private const PUBLISH_CID = 'QmIntegrationPublishCid';

    /**
     * The re-pointed website id and the IPNS key name the pre-seeded identity
     * owns. The injected transport's update-path assertions and the persisted
     * ExportRun identifiers must all agree on these.
     */
    private const WEBSITE_ID = 'website-integration-1';
    private const IPNS_KEY_NAME = 'integration-blog-key';

    /**
     * The same run repository the plugin's own composition reads.
     */
    private function repository(): WordPressRunRepository
    {
        return new WordPressRunRepository(new WordPressOptionGateway());
    }

    protected function setUp(): void
    {
        // Boot the plugin once per PHPUnit process. require_once keeps it
        // idempotent: if another integration test already included cast.php
        // the hooks are already registered and this is a no-op.
        require_once dirname(__DIR__, 2) . '/cast.php';

        // The wptests_ database is shared across suite runs, so deterministically
        // reset every option and Action Scheduler event this test class writes
        // before firing anything.
        delete_option(WordPressRunRepository::OPTION_KEY);
        delete_option(WordPressIdentityGateway::OPTION_KEY);
        delete_option(self::RUN_LOCK_OPTION);
        as_unschedule_all_actions(ContentPublishScheduler::AUTO_HOOK, [], WordPressActionScheduler::DEFAULT_GROUP);
        as_unschedule_all_actions(ContentPublishScheduler::FOLLOW_UP_HOOK, [], WordPressActionScheduler::DEFAULT_GROUP);

        // The probe requires pretty permalinks; the test install defaults to
        // plain permalinks, so enable the structure a production site uses.
        update_option('permalink_structure', '/%postname%/', false);

        // The durable work-item queue table is installed by the plugin's
        // activation path (CastActivator -> WordPressCastExportItemsTable),
        // which the integration harness never runs. Provision it here so real
        // discover/capture ticks can persist rows against the live prefix...
        $itemsTable = new WordPressCastExportItemsTable();
        $itemsTable->install();

        // ...then empty it. The wptests_ database is shared across suite runs,
        // so rows left by a previous run would make discovery treat every URL
        // as already-processed (insertCanonical is first-seen-wins and never
        // re-queues done/rewritten rows), starving the fresh per-run workdir
        // and failing pack. The queue gets the same deterministic clean state
        // setUp already applies to the run option, identity option, lock and
        // cron events.
        $this->clearWorkItemQueue();

        self::assertNotFalse(has_action(ContentPublishScheduler::AUTO_HOOK));
    }

    protected function tearDown(): void
    {
        remove_all_filters('pre_http_request');
        as_unschedule_all_actions(ContentPublishScheduler::AUTO_HOOK, [], WordPressActionScheduler::DEFAULT_GROUP);
        as_unschedule_all_actions(ContentPublishScheduler::FOLLOW_UP_HOOK, [], WordPressActionScheduler::DEFAULT_GROUP);
        delete_option(self::RUN_LOCK_OPTION);
    }

    public function testPublishFixtureIsDiscoveredAndFirstRealTickStartsTheRun(): void
    {
        // A real publish-relevant save: the transition hook must create the
        // pending dirty run BEFORE any tick fires.
        $postId = $this->createPublishedFixture();

        $run = $this->repository()->latest();
        self::assertNotNull($run, 'The save path must create a pending run for published content.');
        self::assertSame(RunStatus::NotStarted, $run->status);
        self::assertTrue($run->dirty, 'Published content must mark the pending run dirty.');
        self::assertSame('publish', get_post_status($postId));

        // A ready website + IPNS identity lets the tick auto-start the run.
        $this->storeReadyIdentity();
        $this->stubLoopbackHttpToFail();

        $this->driveOneRealTick();

        $after = $this->repository()->latest();
        self::assertNotNull($after, 'The tick must leave a persisted run behind.');
        self::assertSame(
            RunStatus::Running,
            $after->status,
            'A dirty not-started run must leave NotStarted once a real tick starts it.',
        );
        self::assertSame(1, $after->retryCount, 'The failed probe must record exactly one retry.');
        self::assertNotNull($after->lastError, 'The probe failure must persist an actionable last error.');
        self::assertStringContainsString(
            'Probe cannot reach',
            (string) $after->lastError,
            'The probe failure message must name a network-level cause.',
        );

        // The lease is released even on the retry path, so a later scheduler
        // request can acquire it again instead of parking a stale lock.
        self::assertFalse(
            get_option(self::RUN_LOCK_OPTION),
            'The tick must release its lease after running.',
        );

        // A Retried outcome rearms exactly one bounded next tick through the
        // real Action Scheduler adapter.
        self::assertNotFalse(
            as_next_scheduled_action(
                ContentPublishScheduler::AUTO_HOOK,
                [],
                WordPressActionScheduler::DEFAULT_GROUP,
            ),
            'A Retried outcome must rearm exactly one bounded next auto-tick.',
        );
    }

    public function testSuccessfulPublishCompletesWithInjectedTransport(): void
    {
        // The integration harness (tests/Integration/bootstrap.php) already
        // boots the real plugin once against a test-only portal deployment
        // identity with an injected recording publish transport, so the publish
        // boundary composes the real PublishStage — never the GuardedPublishStage.
        // This test only has to make every untrusted HTTP boundary answer success:
        // the loopback probe (and sibling export fetches) through WordPress'
        // pre_http_request filter, and the publish boundary through the
        // harness's injected transport.
        $this->createPublishedFixture();
        $this->storeReadyIdentity();
        $this->stubMinimalHttpSuccess();

        // Drive real ticks on the auto-tick hook until the run reaches a
        // terminal status, exactly as production advances one bounded pipeline
        // unit per Action Scheduler queue turn.
        $ticks = 0;
        $run = $this->repository()->latest();
        while ($run !== null && !$run->isTerminal() && $ticks < self::SUCCESS_TICK_CAP) {
            $this->driveOneRealTick();
            $ticks++;
            $run = $this->repository()->latest();
        }

        self::assertNotNull($run, 'The save path must leave a persisted run behind.');
        self::assertTrue(
            $run->isTerminal(),
            sprintf(
                'A success-injected run must reach a terminal status within %d ticks; it stopped at %s with lastError %s.',
                self::SUCCESS_TICK_CAP,
                $run->status->value,
                $run->lastError === null ? 'null' : (string) $run->lastError,
            ),
        );
        self::assertSame(
            RunStatus::Completed,
            $run->status,
            sprintf(
                'A success-injected publish must complete the run cleanly; got %s with lastError %s.',
                $run->status->value,
                $run->lastError === null ? 'null' : (string) $run->lastError,
            ),
        );
        self::assertSame(0, $run->retryCount, 'A clean success path must never record a retry.');
        self::assertNull($run->lastError, 'A clean success path must persist no last error.');

        // The lease is released even on the terminal path, so a later scheduler
        // request can acquire it again.
        self::assertFalse(
            get_option(self::RUN_LOCK_OPTION),
            'The final tick must release its lease.',
        );

        // ------------------------------------------------------------------
        // Approved acceptance assertions: the terminal run must have produced
        // durable evidence at every boundary, and the portal credential must
        // be provably absent from every non-wire surface.
        // ------------------------------------------------------------------

        // Pack: the produced ZIP and its adjacent manifest must exist on
        // disk. The artifact evidence is written into the uploads jail's
        // cast-exports sibling, never inside the run's work directory, so
        // neither is removed by the wrapup's work-directory deletion.
        self::assertNotNull($run->pack, 'A completed publish must have mirrored the pack result.');
        self::assertNotSame('', $run->pack->zipPath, 'A completed publish must persist a non-empty ZIP path.');
        self::assertFileExists($run->pack->zipPath, 'The persisted pack ZIP path must point at a real file.');
        self::assertNotNull($run->pack->manifestPath, 'A completed publish must persist a manifest path.');
        self::assertFileExists($run->pack->manifestPath, 'The persisted manifest path must point at a real file.');

        // Wrapup: the persisted setup records the jailed work directory the
        // pipeline actually used, so the deletion is observable and must hold
        // — the directory itself is gone while the evidence survives.
        self::assertNotNull($run->setup, 'A completed publish must have mirrored the setup work-directory result.');
        self::assertNotNull($run->wrapup, 'A completed publish must have mirrored the wrapup result.');
        self::assertTrue($run->wrapup->workDirDeleted, 'A clean wrapup must report the work directory was deleted.');
        clearstatcache(true, $run->setup->workDir);
        self::assertDirectoryDoesNotExist(
            $run->setup->workDir,
            'The jailed work directory persisted by setup must no longer exist after wrapup.',
        );

        // Durable work-item queue: every row the pipeline enqueued must have
        // reached a terminal status; a completed run never leaves pending work.
        self::assertGreaterThan(
            0,
            $this->workItemCount(),
            'The fixture must have enqueued durable work-item rows before a completed publish.',
        );
        self::assertSame(
            0,
            $this->pendingWorkItemCount(),
            'A completed publish must leave no queued or processing work-item rows behind.',
        );

        // Publish boundary: the top-level publish identifiers mirror what
        // the recording transport produced — the upload CID, the re-pointed
        // website id and the IPNS key name — and survive option persistence +
        // re-hydration through the repository.
        self::assertSame(self::PUBLISH_CID, $run->publishCid, 'The persisted CID must be the uploaded CID.');
        self::assertSame(self::WEBSITE_ID, $run->websiteId, 'The persisted website id must be the re-pointed website.');
        self::assertSame(self::IPNS_KEY_NAME, $run->ipnsKey, 'The persisted IPNS key must be the published key name.');

        // Recording transport: the injected publish boundary must have sent
        // the update-path calls — upload, website re-point, IPNS publish and
        // website readiness — with the expected methods and paths.
        $requests = $this->recordedRequests();
        self::assertNotSame([], $requests, 'A completed publish must have sent requests through the injected transport.');
        $observedPaths = [];
        foreach ($requests as $request) {
            $observedPaths[] = strtoupper($request->getMethod()) . ' ' . $request->getUri()->getPath();
        }
        self::assertContains('POST /api/upload', $observedPaths, 'The publish must upload the artifact.');
        self::assertContains(
            'PUT /api/websites/' . self::WEBSITE_ID,
            $observedPaths,
            'The publish must re-point the existing website target.',
        );
        self::assertContains('POST /api/ipns/publish', $observedPaths, 'The publish must publish the CID to IPNS.');
        self::assertContains(
            'GET /api/websites/' . self::WEBSITE_ID,
            $observedPaths,
            'The publish must poll website readiness for the live CID.',
        );

        // Portal credential: the bearer API key is a wire-only credential. It
        // must never be persisted (run option, identity option, work-item
        // rows), never appear in a persisted error message, and recorder
        // inspection must confirm it shows up solely inside an Authorization
        // request header — never in a body or a non-Authorization header. The
        // assertion messages intentionally never echo the credential value.
        $portalIdentity = EnvIdentity::fromEnvironment()->resolve();
        self::assertNotNull($portalIdentity, 'The harness must provide the test portal identity.');
        $apiKey = $portalIdentity->accountKey();
        self::assertNotSame('', $apiKey, 'The harness portal identity must carry a non-empty account key.');

        $persistedBlob = implode("\n", [
            (string) wp_json_encode(get_option(WordPressRunRepository::OPTION_KEY)),
            (string) wp_json_encode(get_option(WordPressIdentityGateway::OPTION_KEY)),
            $this->workItemRowsAsString(),
        ]);
        self::assertStringNotContainsString(
            $apiKey,
            $persistedBlob,
            'The portal API key must never be written into persisted run, identity or work-item state.',
        );
        self::assertStringNotContainsString(
            'Bearer ',
            $persistedBlob,
            'An Authorization header value must never be persisted into run, identity or work-item state.',
        );

        $errorsBlob = $run->lastError ?? '';
        self::assertStringNotContainsString(
            $apiKey,
            $errorsBlob,
            'The portal API key must never appear in a persisted error message.',
        );
        self::assertStringNotContainsString(
            'Bearer ',
            $errorsBlob,
            'An Authorization header value must never appear in a persisted error message.',
        );

        $nonAuthHeaders = '';
        $bodies = '';
        $authorizationHits = 0;
        foreach ($requests as $request) {
            foreach ($request->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    if (!is_string($value)) {
                        continue;
                    }
                    if (str_contains($value, $apiKey)) {
                        self::assertSame(
                            0,
                            strcasecmp((string) $name, 'Authorization'),
                            'The portal API key must only ever appear in an Authorization request header.',
                        );
                        self::assertStringStartsWith(
                            'Bearer ',
                            $value,
                            'The Authorization header must carry the key in its Bearer form.',
                        );
                        ++$authorizationHits;
                        continue;
                    }
                    $nonAuthHeaders .= $name . ': ' . $value . "\n";
                }
            }
            $bodies .= (string) $request->getBody() . "\n";
        }
        self::assertGreaterThan(0, $authorizationHits, 'Every recorded publish request must authenticate with the bearer key.');
        self::assertStringNotContainsString(
            $apiKey,
            $nonAuthHeaders,
            'The portal API key must never appear in a non-Authorization request header.',
        );
        self::assertStringNotContainsString(
            'Bearer ',
            $nonAuthHeaders,
            'An Authorization header value must never appear in a non-Authorization request header.',
        );
        self::assertStringNotContainsString(
            $apiKey,
            $bodies,
            'The portal API key must never be sent inside a request body.',
        );
        self::assertStringNotContainsString(
            'Bearer ',
            $bodies,
            'An Authorization header value must never be sent inside a request body.',
        );
    }

    // -----------------------------------------------------------------------
    // The published fixture (a real row through the WP post factory).
    // -----------------------------------------------------------------------

    private function createPublishedFixture(): int
    {
        return (int) wp_insert_post([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => 'Integration publish fixture',
            'post_name' => 'integration-publish-fixture',
            'post_content' => '<p>The real flow has content to discover.</p>',
        ], false);
    }

    private function storeReadyIdentity(): void
    {
        $identity = new PublishIdentity(
            websiteId: self::WEBSITE_ID,
            websiteName: 'integration-blog.example.org',
            ipnsKeyId: 'ipns-key-integration-1',
            ipnsKeyName: self::IPNS_KEY_NAME,
            ready: true,
        );
        update_option(WordPressIdentityGateway::OPTION_KEY, $identity->toOptionValue(), false);
    }

    /**
     * Empty the durable cast_export_items queue so discovery starts from a
     * fresh state. The live prefix is resolved exactly like the rest of the
     * harness; the table is provisioned by setUp() before this runs.
     */
    private function clearWorkItemQueue(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $table = CastExportItemsTable::name($wpdb->prefix);
        $sql = $wpdb->prepare('DELETE FROM %i', $table);
        if (is_string($sql) && $sql !== '') {
            $wpdb->query($sql);
        }
    }

    /**
     * Total durable work-item rows in the live-prefix queue table.
     */
    private function workItemCount(): int
    {
        $wpdb = $GLOBALS['wpdb'];
        $table = CastExportItemsTable::name($wpdb->prefix);
        $sql = $wpdb->prepare('SELECT COUNT(*) FROM %i', $table);

        return (int) $wpdb->get_var($sql);
    }

    /**
     * Work-item rows still queued or processing — the non-terminal tail a
     * completed run must never leave behind.
     */
    private function pendingWorkItemCount(): int
    {
        $wpdb = $GLOBALS['wpdb'];
        $table = CastExportItemsTable::name($wpdb->prefix);
        $sql = $wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE status IN (%s, %s)',
            $table,
            WorkItemStatus::Queued->value,
            WorkItemStatus::Processing->value,
        );

        return (int) $wpdb->get_var($sql);
    }

    /**
     * Every durable work-item row, JSON-encoded and joined, so secret-leak
     * assertions can search the full queue contents without echoing anything.
     */
    private function workItemRowsAsString(): string
    {
        $wpdb = $GLOBALS['wpdb'];
        $table = CastExportItemsTable::name($wpdb->prefix);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i', $table), 'ARRAY_A');
        if (!is_array($rows)) {
            return '';
        }

        $encoded = [];
        foreach ($rows as $row) {
            $encoded[] = (string) wp_json_encode($row);
        }

        return implode("\n", $encoded);
    }

    /**
     * The requests the injected publish transport recorded, in send order.
     *
     * @return list<\Psr\Http\Message\RequestInterface>
     */
    private function recordedRequests(): array
    {
        $recorder = IntegrationHarness::instance()->recorder();
        self::assertNotNull($recorder, 'The harness must provide its recording publish transport.');

        return $recorder->requests();
    }

    // -----------------------------------------------------------------------
    // The required HTTP boundary: short-circuit the real loopback probe through
    // WordPress' own pre_http_request filter so it deterministically fails as
    // a network-level connect error instead of touching the network.
    // -----------------------------------------------------------------------

    private function stubLoopbackHttpToFail(): void
    {
        add_filter(
            'pre_http_request',
            static function ($preempt, array $args, string $url) {
                $home = home_url('/');
                if (str_starts_with($url, $home)) {
                    return new WP_Error(
                        'http_request_failed',
                        'Connection refused for the integration loopback probe.',
                    );
                }

                return $preempt;
            },
            10,
            3,
        );
    }

    // -----------------------------------------------------------------------
    // The minimal success HTTP helper: every untrusted boundary answers success so
    // the full Probe → … → Publish pipeline can reach a terminal status. Two
    // distinct boundaries exist — the export half (probe/discover/capture) rides the
    // real WordPress HTTP stack, and the publish half rides the harness's
    // injected recording transport.
    // -----------------------------------------------------------------------

    private function stubMinimalHttpSuccess(): void
    {
        // A valid portal deployment identity is already provided by the
        // harness's single boot; without it the publish boundary would be the
        // GuardedPublishStage, which no amount of HTTP stubbing can complete.
        self::assertNotNull(
            EnvIdentity::fromEnvironment()->resolve(),
            'The integration harness must provide a valid portal deployment identity.',
        );

        $this->stubLoopbackHttpToSucceed();

        // The publish boundary speaks through the harness's injected recording
        // transport: enqueue the REAL portal response shapes the SDK adapters
        // decode, in the exact order the update/reuse publish path sends them.
        // A ready identity is pre-seeded above, so there is no create-website
        // or create-key call — just re-point, publish and read back:
        //   1. POST /api/upload            -> PostUploadResponse  {CID: string}
        //   2. PUT /api/websites/{id}      -> WebsiteResponse (id/status/domain/
        //                                      target_hash/target_type)
        //   3. POST /api/ipns/publish      -> IPNSPublishResponse (name/value/
        //                                      sequence/published/validity)
        //   4. GET /api/websites/{id}      -> WebsiteResponse, live (active) and
        //                                      serving exactly the uploaded CID.
        $recorder = IntegrationHarness::instance()->recorder();
        self::assertNotNull($recorder, 'The harness must inject its recording publish transport.');
        $recorder->appendResponses([
            new Response(200, ['content-type' => 'application/json'], $this->jsonBody(['CID' => self::PUBLISH_CID])),
            new Response(200, ['content-type' => 'application/json'], $this->jsonBody([
                'id' => 1001,
                'status' => 'active',
                'domain' => 'integration-blog.example.org',
                'target_hash' => self::PUBLISH_CID,
                'target_type' => 'website',
                'active_cid' => self::PUBLISH_CID,
                'ipns_key_id' => 9001,
            ])),
            new Response(200, ['content-type' => 'application/json'], $this->jsonBody([
                'name' => 'k51qzi5uqu5dgk6f7',
                'value' => '/ipfs/' . self::PUBLISH_CID,
                'sequence' => 1,
                'published' => '2024-01-01T00:00:00Z',
                'validity' => '2024-01-01T00:00:00Z',
            ])),
            new Response(200, ['content-type' => 'application/json'], $this->jsonBody([
                'id' => 1001,
                'status' => 'active',
                'domain' => 'integration-blog.example.org',
                'target_hash' => self::PUBLISH_CID,
                'target_type' => 'website',
                'active_cid' => self::PUBLISH_CID,
                'ipns_key_id' => 9001,
            ])),
        ]);
    }

    /**
     * JSON-encode a test payload into the string body a Response needs.
     * json_encode returns string|false and False would fail the PSR-7
     * Response body type-check; JSON_THROW_ON_ERROR turns a broken fixture
     * into a loud JsonException instead of silently shrinking the assertion
     * surface. Every fixture here is a plain encodable array, so the helper
     * is type-safe without casting away a hypothetical false.
     *
     * @param array<string, mixed> $payload
     */
    private function jsonBody(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    private function stubLoopbackHttpToSucceed(): void
    {
        // The core sitemap the discover stage probes: a valid, flat urlset
        // (no DOCTYPE/entity, no nested sitemap documents) so discovery parses
        // it and drains without further sub-sitemap fetches.
        $sitemapXml = '<?xml version="1.0" encoding="UTF-8"?>'
            . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';

        add_filter(
            'pre_http_request',
            static function ($preempt, array $args, string $url) use ($sitemapXml) {
                $home = home_url('/');
                if (str_starts_with($url, $home)) {
                    // The sitemap document must answer as valid XML for the
                    // discover stage's sitemap parser; every other home-URL get
                    // serves a plausible home page: at least 1024 bytes
                    // containing an HTML marker, exactly what ProbeStage's
                    // plausible-page check requires. Serving both offline-keeps
                    // discover (sitemap probes) and capture (content fetches)
                    // deterministic.
                    if (str_ends_with(rtrim($url, '/'), '/wp-sitemap.xml')) {
                        return [
                            'headers' => ['content-type' => 'application/xml; charset=UTF-8'],
                            'body' => $sitemapXml,
                            'response' => ['code' => 200, 'message' => 'OK'],
                            'cookies' => [],
                            'filename' => null,
                        ];
                    }

                    return [
                        'headers' => ['content-type' => 'text/html; charset=UTF-8'],
                        'body' => str_repeat('p', 1024) . '<html><body>Integration probe page</body></html>',
                        'response' => ['code' => 200, 'message' => 'OK'],
                        'cookies' => [],
                        'filename' => null,
                    ];
                }

                return $preempt;
            },
            10,
            3,
        );
    }

    private function driveOneRealTick(): void
    {
        do_action(ContentPublishScheduler::AUTO_HOOK);
    }
}
