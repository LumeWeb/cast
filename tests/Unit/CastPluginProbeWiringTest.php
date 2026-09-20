<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit;

use LumeWeb\Cast\CastPlugin;
use LumeWeb\Cast\Export\ExportRun;
use LumeWeb\Cast\Export\PackStatus;
use LumeWeb\Cast\Export\RunSettings;
use LumeWeb\Cast\Export\RunStage;
use LumeWeb\Cast\Export\RunStatus;
use LumeWeb\Cast\Jobs\ContentPublishScheduler;
use LumeWeb\Cast\Jobs\PublishIdentity;
use LumeWeb\Cast\Jobs\WordPressActionScheduler;
use PHPUnit\Framework\TestCase;

/**
 * Production-runtime wiring acceptance: after a real {@see CastPlugin} boot the
 * export tick runs the REAL probe (WordPressCaptureHttp over the scripted
 * WordPress HTTP stack plus WordPressProbeEnvironment over the scripted
 * runtime facts, sharing one PipelineState), mirrors the probe onto the run,
 * then runs the REAL Setup stage (WordPressSetupEnvironment over the scripted
 * uploads root) constructed per-run at tick time, creating the jailed work
 * directory, then the REAL Discover stage (WordPressDiscoverEnvironment over
 * the scripted runtime facts and SqlWorkItemRepository over the shimmed wpdb)
 * persisting its queued items, the REAL Capture stage
 * (WordPressCaptureEnvironment over the scripted HTTP stack) claiming every
 * discovered item to a terminal queue state and a persisted CaptureSummary,
 * and finally the REAL Rewrite stage (WordPressRewriteEnvironment jailed to
 * the run work directory over the same SqlWorkItemRepository) rewriting each
 * rewritable done row's captured body back into the work directory and
 * recording a persisted RewriteSummary before the run advances to the pack
 * boundary — all while RunStatus stays Running at that boundary and Pack
 * through Wrapup remain {@see UnwiredStage}. This guards the CastPlugin
 * composition so Probe/Setup/Discover/Capture/Rewrite can never silently
 * regress to UnwiredStage while Pack through Wrapup stay unwired.
 */
final class CastPluginProbeWiringTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['lumeweb_cast_hooks'] = [];
        $GLOBALS['lumeweb_cast_lifecycle'] = [];
        $GLOBALS['lumeweb_cast_options'] = [];
        $GLOBALS['lumeweb_cast_network_options'] = [];
        // Each test boots as if a fresh WordPress request arrived; boot is
        // idempotent within a process, so re-arm the guard between tests.
        CastPlugin::resetBootIdempotence();
        // Fresh Action Scheduler shim store for the booted composition (the
        // same global the unit bootstrap defines over the as_* API).
        $GLOBALS['lumeweb_cast_actions'] = [
            'actions' => [],
            'running' => [],
            'last_id' => 0,
            'available' => true,
        ];

        // WordPress runtime facts the WordPressProbeEnvironment reads.
        $GLOBALS['lumeweb_cast_home_url'] = 'https://blog.example.test/';
        $GLOBALS['lumeweb_cast_options']['permalink_structure'] = '/%postname%/';
        $GLOBALS['lumeweb_cast_upload_dir'] = [
            'basedir' => sys_get_temp_dir(),
            'error' => false,
        ];
        $GLOBALS['lumeweb_cast_environment_type'] = 'production';
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

        // One pending dirty run plus a ready publish identity: the tick's
        // readiness check auto-starts exactly like WP-Cron would.
        $run = ExportRun::create('run-accept-1', new RunSettings(), at: 1_000);
        $run->markDirty(at: 1_000);
        $GLOBALS['lumeweb_cast_options']['cast_export_run'] = $run->toArray();
        $identity = new PublishIdentity(
            websiteId: 'w-1',
            websiteName: 'Example',
            ipnsKeyId: 'k-1',
            ipnsKeyName: 'Key One',
            ready: true,
        );
        $GLOBALS['lumeweb_cast_options']['cast_publish_identity'] = $identity->toOptionValue();

        // The scripted home page the booted WordPressCaptureHttp will read.
        $GLOBALS['lumeweb_cast_wp_remote_calls'] = [];
        $GLOBALS['lumeweb_cast_wp_remote_response'] = [
            'headers' => ['content-type' => 'text/html; charset=UTF-8'],
            'body' => $this->htmlBody(),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
        $GLOBALS['lumeweb_cast_wp_remote_responses'] = [];

        // Deterministic discover runtime facts: no SEO plugins, no public
        // custom post types, empty keyset pages and a clean items-insert
        // bucket so the booted acceptance drives real persisted rows.
        $GLOBALS['lumeweb_cast_active_plugins'] = [];
        $GLOBALS['lumeweb_cast_public_post_types'] = [];
        $GLOBALS['lumeweb_cast_permalinks'] = [];
        $GLOBALS['lumeweb_cast_wpdb_results'] = [];
        $GLOBALS['lumeweb_cast_wpdb_inserts'] = [];
        $GLOBALS['lumeweb_cast_wpdb_rows'] = [];
    }

    protected function tearDown(): void
    {
        // The real Setup stage jailed under the scripted uploads root, plus the
        // accepted artifacts the real Capture stage wrote into the run dir and
        // the artifact ZIP + manifest the real Pack stage writes next to the
        // uploads root; remove them all so repeated runs start clean.
        $work = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'cast-work';
        $this->removeTree($work . DIRECTORY_SEPARATOR . 'run-accept-1');
        $this->removeTree($work);
        $this->removeTree(realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'cast-exports');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function persistedRows(): array
    {
        return $GLOBALS['lumeweb_cast_wpdb_rows'];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }

    public function testBootedProbeRunsRecordsStateAndReachesTheSetupBoundary(): void
    {
        CastPlugin::boot('/plugins/cast/cast.php');

        $this->runTick();

        $run = $this->currentRun();

        // The probe ran through the real WordPressCaptureHttp exactly once,
        // against the configured home URL — not a fake stage.
        self::assertCount(1, $GLOBALS['lumeweb_cast_wp_remote_calls']);
        self::assertSame('https://blog.example.test/', $GLOBALS['lumeweb_cast_wp_remote_calls'][0]['url']);

        // The probe succeeded (only a successful probe records its ProbeResult
        // into the shared PipelineState before reporting done), so the run
        // advanced past probe to the setup boundary inside the Exporting
        // bucket, still running without error — and the orchestrator mirrored
        // the probe onto the persisted run for the next WP-Cron request.
        self::assertSame(RunStatus::Running, $run->status);
        self::assertSame(RunStage::Exporting, $run->stage);
        self::assertSame('setup|', $run->resumeCursor);
        self::assertNull($run->lastError);
        self::assertNotNull($run->probe);
        self::assertSame('blog.example.test', $run->probe->origin->host());
        self::assertSame('https://blog.example.test/', $run->probe->finalUrl);
    }

    /**
     * A real admin request that arrives carrying HTTP Basic Auth headers (for
     * WordPress secure-admin/login surfaces, unrelated to export) must not
     * block the plugin's own loopback probe: the probe is strictly anonymous,
     * reaches the site home through the deployment's Caddy loopback bypass,
     * and never echoes the incoming admin credential onto the wire.
     */
    public function testIncomingAdminBasicAuthContextDoesNotBlockTheBootedAnonymousLoopbackProbe(): void
    {
        // An admin request context carrying Basic Auth for unrelated admin
        // surfaces; the export loopback probe must ignore it entirely.
        $_SERVER['PHP_AUTH_USER'] = 'alice';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('alice:s3cret');
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('alice:s3cret');

        CastPlugin::boot('/plugins/cast/cast.php');

        $this->runTick();

        $run = $this->currentRun();

        // The probe ran through the real WordPressCaptureHttp exactly once,
        // against the configured home URL — the incoming admin Basic Auth
        // context did not block it.
        self::assertCount(1, $GLOBALS['lumeweb_cast_wp_remote_calls']);
        self::assertSame('https://blog.example.test/', $GLOBALS['lumeweb_cast_wp_remote_calls'][0]['url']);

        // The probe succeeded (only a successful probe records its ProbeResult
        // into the shared PipelineState before reporting done), so the run
        // advanced past probe to the setup boundary, still running without
        // error — the admin auth context never leaked into probe outcomes.
        self::assertSame(RunStatus::Running, $run->status);
        self::assertSame(RunStage::Exporting, $run->stage);
        self::assertSame('setup|', $run->resumeCursor);
        self::assertNull($run->lastError);
        self::assertNotNull($run->probe);
        self::assertSame('blog.example.test', $run->probe->origin->host());

        // ...and the probe request was strictly anonymous: no Authorization
        // header was attached, so the admin credential never reaches the
        // loopback wire.
        $headers = $GLOBALS['lumeweb_cast_wp_remote_calls'][0]['args']['headers'] ?? [];
        self::assertIsArray($headers);
        self::assertArrayNotHasKey('Authorization', $headers);
    }

    public function testBootedSetupRunsCreatesJailedWorkDirAndAdvancesToDiscoverBoundary(): void
    {
        CastPlugin::boot('/plugins/cast/cast.php');

        $this->runTick();
        $this->runTick();

        $run = $this->currentRun();

        // The real Setup stage ran against the scripted WordPress uploads root
        // under the current run id, created the jailed work directory, and the
        // orchestrator mirrored its SetupResult onto the persisted run.
        self::assertSame(RunStatus::Running, $run->status);
        self::assertSame(RunStage::Exporting, $run->stage);
        self::assertSame('discover|', $run->resumeCursor);
        self::assertNull($run->lastError);
        self::assertNotNull($run->setup);
        self::assertStringContainsString('cast-work', $run->setup->workDir);
        self::assertStringContainsString('run-accept-1', $run->setup->workDir);
        self::assertSame(
            realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'cast-work' . DIRECTORY_SEPARATOR . 'run-accept-1',
            $run->setup->workDir,
        );
    }

    public function testBootedDiscoverRunsPersistsItemsAndResultAndAdvancesToCapture(): void
    {
        // The booted DiscoverStage fetches the detected core sitemap through
        // the real WordPressCaptureHttp; answer that one URL with a small
        // urlset while the home-page probe keeps its HTML body.
        $GLOBALS['lumeweb_cast_wp_remote_responses']['https://blog.example.test/wp-sitemap.xml'] = [
            'headers' => ['content-type' => 'application/xml; charset=UTF-8'],
            'body' => '<?xml version="1.0" encoding="UTF-8"?>'
                . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                . '<url><loc>https://blog.example.test/sitemap-page/</loc></url>'
                . '</urlset>',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];

        CastPlugin::boot('/plugins/cast/cast.php');

        // probe + setup + six seeders + one (empty) keyset page + one sitemap
        // document = ten bounded units to drain discovery.
        for ($i = 0; $i < 10; ++$i) {
            $this->runTick();
        }

        $run = $this->currentRun();

        // The real DiscoverStage ran to completion — not an UnwiredStage — so
        // the run advanced cleanly past discover to the capture boundary
        // inside the Exporting bucket, still running without error.
        self::assertSame(RunStatus::Running, $run->status);
        self::assertSame(RunStage::Exporting, $run->stage);
        self::assertSame('capture|', $run->resumeCursor);
        self::assertNull($run->lastError);

        // ...and its DiscoverResult was mirrored onto the persisted run: a
        // truthful count of the distinct queued items.
        self::assertNotNull($run->discover);
        self::assertGreaterThan(0, $run->discover->enqueued);

        // The SqlWorkItemRepository persisted the enqueued candidates into the
        // WordPress items table (probe and setup emit no inserts), seeded by
        // the static seeders and the sitemap document together.
        self::assertNotEmpty($GLOBALS['lumeweb_cast_wpdb_inserts']);
        $inserts = implode("\n", $GLOBALS['lumeweb_cast_wpdb_inserts']);
        self::assertStringContainsString('https://blog.example.test/', $inserts);
        self::assertStringContainsString('https://blog.example.test/robots.txt', $inserts);
        self::assertStringContainsString('https://blog.example.test/wp-sitemap.xml', $inserts);
    }

    /**
     * Full boot/tick acceptance for the real Capture stage: after the booted
     * probe/setup/discover drain, the booted CaptureStage (wired with the
     * WordPress runtime environment and the durable SqlWorkItemRepository the
     * discovery stage filled) claims every discovered queued row one per tick,
     * captures it through the scripted WordPress HTTP stack, transitions each
     * row to its terminal status, records the terminal CaptureSummary onto the
     * persisted run and only then crosses the rewrite boundary — while the run
     * stays Running and active at that boundary before the now-wired Rewrite
     * stage takes over. This proves capture can never silently regress to
     * UnwiredStage (the old unwired boot behaviour) without this test failing.
     */
    public function testBootedCaptureClaimsEveryDiscoveredItemReachesTerminalQueueStateAndAdvancesToRewrite(): void
    {
        // Discovery fetches the detected core sitemap through the real
        // WordPressCaptureHttp; answer that one URL with a small urlset naming
        // one local page while the other runtime responses keep their bodies.
        $GLOBALS['lumeweb_cast_wp_remote_responses']['https://blog.example.test/wp-sitemap.xml'] = [
            'headers' => ['content-type' => 'application/xml; charset=UTF-8'],
            'body' => '<?xml version="1.0" encoding="UTF-8"?>'
                . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                . '<url><loc>https://blog.example.test/sitemap-page/</loc></url>'
                . '</urlset>',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
        // The sitemap page is only ever fetched by CAPTURE, where the page
        // ghost guard demands at least 1024 bytes containing <html>; give it a
        // real page body so its capture succeeds terminally.
        $GLOBALS['lumeweb_cast_wp_remote_responses']['https://blog.example.test/sitemap-page/'] = [
            'headers' => ['content-type' => 'text/html; charset=UTF-8'],
            'body' => $this->htmlBody(),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];

        CastPlugin::boot('/plugins/cast/cast.php');

        // The whole real pipeline through capture: probe + setup + six seeders
        // + an empty keyset page + one sitemap document drain discovery across
        // ten ticks, then capture claims the eight queued rows one per tick
        // and a final fixed-point tick records the terminal summary and
        // crosses the rewrite boundary — nineteen bounded units in total.
        for ($i = 0; $i < 19; ++$i) {
            $this->runTick();
        }

        $run = $this->currentRun();

        // The real CaptureStage ran to completion — not an UnwiredStage — so
        // the run advanced cleanly from capture to the rewrite boundary inside
        // the Exporting bucket, still running and active without error.
        self::assertSame(RunStatus::Running, $run->status);
        self::assertSame(RunStage::Exporting, $run->stage);
        self::assertSame('rewrite|', $run->resumeCursor);
        self::assertNull($run->lastError);

        // Capture drained the queue discovery filled: every discovered item was
        // claimed and terminally recorded, and the summary was mirrored onto
        // the persisted run for the next WP-Cron request.
        self::assertNotNull($run->capture);
        self::assertSame(8, $run->capture->done);
        self::assertSame(0, $run->capture->failed);
        self::assertSame(0, $run->capture->skipped);

        // The durable SQL item rows reached their terminal queue state — all
        // done, none still queued or in flight — and each was claimed and
        // processed exactly once through the booted SqlWorkItemRepository.
        self::assertCount(8, $GLOBALS['lumeweb_cast_wpdb_inserts']);
        $rows = $this->persistedRows();
        self::assertCount(8, $rows);
        foreach ($rows as $row) {
            self::assertSame('done', $row['status']);
            self::assertSame(1, $row['fetch_attempts']);
        }
    }

    /**
     * Full boot/tick acceptance for the real Rewrite stage: after the booted
     * probe/setup/discover/capture drain, the booted RewriteStage (wired with
     * the WordPressRewriteEnvironment jailed to the run work directory over the
     * durable SqlWorkItemRepository the discovery stage filled) claims each
     * rewritable done row one per tick, re-runs the real per-run
     * RewriteService over the captured body, writes the rewritten body back
     * into the run work directory, transitions each row to its rewritten
     * status, records the terminal RewriteSummary onto the persisted run and
     * only then crosses the pack boundary — while the run stays Running and
     * active at that boundary and Pack/Wrapup remain UnwiredStage. This proves
     * rewrite can never silently regress to UnwiredStage (the old unwired boot
     * behaviour) without this test failing.
     */
    public function testBootedRewriteClaimsEveryRewritableItemReachesTerminalQueueStateAndAdvancesToPack(): void
    {
        // Discovery fetches the detected core sitemap through the real
        // WordPressCaptureHttp; answer that one URL with a small urlset naming
        // one local page while the other runtime responses keep their bodies.
        $GLOBALS['lumeweb_cast_wp_remote_responses']['https://blog.example.test/wp-sitemap.xml'] = [
            'headers' => ['content-type' => 'application/xml; charset=UTF-8'],
            'body' => '<?xml version="1.0" encoding="UTF-8"?>'
                . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                . '<url><loc>https://blog.example.test/sitemap-page/</loc></url>'
                . '</urlset>',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
        // The sitemap page is only ever fetched by CAPTURE, where the page
        // ghost guard demands at least 1024 bytes containing <html>; give it a
        // real page body so its capture succeeds terminally.
        $GLOBALS['lumeweb_cast_wp_remote_responses']['https://blog.example.test/sitemap-page/'] = [
            'headers' => ['content-type' => 'text/html; charset=UTF-8'],
            'body' => $this->htmlBody(),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];

        CastPlugin::boot('/plugins/cast/cast.php');

        // The whole real pipeline through rewrite: probe + setup + six seeders
        // + an empty keyset page + one sitemap document drain discovery across
        // ten ticks, capture claims the eight queued rows one per tick and
        // crosses the rewrite boundary on the nineteenth, then rewrite claims
        // the seven rewritable done rows one per tick and a final fixed-point
        // tick records the terminal summary and crosses the pack boundary —
        // twenty-seven bounded units in total.
        for ($i = 0; $i < 27; ++$i) {
            $this->runTick();
        }

        $run = $this->currentRun();

        // The real RewriteStage ran to completion — not an UnwiredStage — so
        // the run advanced cleanly from rewrite to the pack boundary inside
        // the Uploading bucket, still running and active without error.
        self::assertSame(RunStatus::Running, $run->status);
        self::assertSame(RunStage::Uploading, $run->stage);
        self::assertSame('pack|', $run->resumeCursor);
        self::assertNull($run->lastError);

        // Rewrite drained the done queue into its terminal state: every
        // rewritable text-like row was rewritten and exactly one fixed asset
        // (the favicon) passed through, and the summary was mirrored onto the
        // persisted run for the next WP-Cron request.
        self::assertNotNull($run->rewrite);
        self::assertSame(7, $run->rewrite->rewritten);
        self::assertSame(1, $run->rewrite->passedThrough);

        // The durable SQL item rows reached their terminal queue state — the
        // seven rewritable rows are status rewritten while the single fixed
        // asset stays done — each claimed and rewritten exactly once through
        // the booted SqlWorkItemRepository.
        self::assertCount(8, $GLOBALS['lumeweb_cast_wpdb_inserts']);
        $rewritten = 0;
        $done = 0;
        foreach ($this->persistedRows() as $row) {
            if ($row['status'] === 'rewritten') {
                ++$rewritten;
            } elseif ($row['status'] === 'done') {
                ++$done;
            }
        }
        self::assertSame(7, $rewritten);
        self::assertSame(1, $done);
    }

    /**
     * Boot/tick acceptance pin for the production-wired CaptureEnvironment on
     * the rewrite stage: after the booted probe/setup/discover/capture
     * drain, a captured body embeds an in-origin asset reference, so the
     * booted RewriteStage's rewriters collect that asset into the queue.
     * Reaching the rewritable-Done fixed point with the collected asset still
     * queued, the stage — wired with the SAME WordPressCaptureEnvironment the
     * capture stage uses — reconciles it in a bounded second pass: claims the
     * collected asset, captures it through the real WordPress HTTP stack (a
     * fresh remote fetch for the asset URL), lands it Done and only then
     * crosses the pack boundary with zero pending work. Without a wired
     * capture environment the stage keeps the legacy inert behavior and the
     * collected asset would stay queued, failing the pack check — so this test
     * pins the production RewriteStage to a non-null CaptureEnvironment.
     */
    public function testBootedRewriteReconcilesCollectedAssetsThroughTheWiredCaptureEnvironment(): void
    {
        // Discovery fetches the detected core sitemap through the real
        // WordPressCaptureHttp; answer that one URL with a small urlset naming
        // one local page while the other runtime responses keep their bodies.
        $GLOBALS['lumeweb_cast_wp_remote_responses']['https://blog.example.test/wp-sitemap.xml'] = [
            'headers' => ['content-type' => 'application/xml; charset=UTF-8'],
            'body' => '<?xml version="1.0" encoding="UTF-8"?>'
                . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                . '<url><loc>https://blog.example.test/sitemap-page/</loc></url>'
                . '</urlset>',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
        // The sitemap page is only ever fetched by CAPTURE, where the page
        // ghost guard demands at least 1024 bytes containing <html>; it embeds
        // an in-origin asset reference so the rewrite pass collects it for
        // reconciliation.
        $GLOBALS['lumeweb_cast_wp_remote_responses']['https://blog.example.test/sitemap-page/'] = [
            'headers' => ['content-type' => 'text/html; charset=UTF-8'],
            'body' => $this->htmlBody('<img src="/wp-content/uploads/logo.png">'),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
        // The collected asset is captured in the reconciliation pass through
        // the booted WordPressCaptureEnvironment: a fresh remote fetch for the
        // asset URL returns a binary image body.
        $GLOBALS['lumeweb_cast_wp_remote_responses']['https://blog.example.test/wp-content/uploads/logo.png'] = [
            'headers' => ['content-type' => 'image/png'],
            'body' => 'logo-bytes',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];

        CastPlugin::boot('/plugins/cast/cast.php');

        // The whole real pipeline through rewrite AND its reconciliation:
        // probe + setup + six seeders + an empty keyset page + one sitemap
        // document drain discovery across ten ticks, capture claims the eight
        // queued rows one per tick and a fixed-point tick crosses the rewrite
        // boundary on the nineteenth, rewrite drains the seven rewritable rows
        // one per tick (collecting the embedded asset), the reconciliation
        // pass claims and captures that collected asset on a further tick and
        // only on the final fixed-point tick crosses the pack boundary —
        // twenty-eight bounded units in total.
        for ($i = 0; $i < 28; ++$i) {
            $this->runTick();
        }

        $run = $this->currentRun();

        // The real RewriteStage ran to completion INCLUDING its reconciliation
        // pass — not an UnwiredStage and not the legacy inert
        // rewrite — so the run advanced cleanly from rewrite to the pack
        // boundary inside the Uploading bucket, still running and active
        // without error. The pack check would have failed on a pending
        // collected asset had reconciliation not drained it.
        self::assertSame(RunStatus::Running, $run->status);
        self::assertSame(RunStage::Uploading, $run->stage);
        self::assertSame('pack|', $run->resumeCursor);
        self::assertNull($run->lastError);

        self::assertNotNull($run->rewrite);
        self::assertSame(7, $run->rewrite->rewritten);
        // The favicon fixed asset from discovery plus the logo the
        // reconciliation captured both pass through as Done.
        self::assertSame(2, $run->rewrite->passedThrough);

        // The collected asset was actually captured through the booted
        // WordPress HTTP stack during reconciliation: the asset URL was
        // fetched by the real WordPressCaptureEnvironment. This is only
        // possible because the production RewriteStage received a non-null
        // CaptureEnvironment.
        $calls = array_column($GLOBALS['lumeweb_cast_wp_remote_calls'], 'url');
        self::assertContains('https://blog.example.test/wp-content/uploads/logo.png', $calls);

        // The durable SQL item rows reached their terminal queue state: the
        // seven rewritable rows are status rewritten while the two fixed
        // assets (the favicon from discovery and the reconciled logo) are done
        // — nothing is left queued or processing for the pack check.
        $rewritten = 0;
        $done = 0;
        $logoRows = 0;
        foreach ($this->persistedRows() as $row) {
            if ($row['status'] === 'rewritten') {
                ++$rewritten;
            } elseif ($row['status'] === 'done') {
                ++$done;
            }
            if (($row['url'] ?? '') === 'https://blog.example.test/wp-content/uploads/logo.png') {
                ++$logoRows;
            }
        }
        self::assertSame(7, $rewritten);
        self::assertSame(2, $done);
        self::assertSame(1, $logoRows, 'the reconciled asset was persisted to the items table');
    }

    /**
     * Full boot/tick acceptance for the real Pack stage: after the booted
     * probe/setup/discover/capture/rewrite drain, the booted PackStage (wired
     * with the WordPressPackEnvironment resolving the artifact ZIP into the
     * uploads-root `cast-exports` directory, the shared PipelineState and the
     * durable SqlWorkItemRepository) runs the real pre-pack validation check
     * exactly once over the completed work tree, drives the real ZipPackager
     * to write the artifact ZIP plus its adjacent manifest, records the
     * resulting PackResult onto the persisted run and only then advances to
     * the wrapup boundary — while the run stays Running and active at that
     * boundary and Wrapup remains an UnwiredStage. This proves the old unwired
     * pack boot behaviour is gone: artifact validation runs, the ZIP and
     * manifest are produced, the PackResult persists, and the run advances to
     * `wrapup|`.
     */
    public function testBootedPackValidatesArtifactsProducesZipAndManifestPersistsResultAndAdvancesToWrapup(): void
    {
        // Discovery fetches the detected core sitemap through the real
        // WordPressCaptureHttp; answer that one URL with a small urlset naming
        // one local page while the other runtime responses keep their bodies.
        $GLOBALS['lumeweb_cast_wp_remote_responses']['https://blog.example.test/wp-sitemap.xml'] = [
            'headers' => ['content-type' => 'application/xml; charset=UTF-8'],
            'body' => '<?xml version="1.0" encoding="UTF-8"?>'
                . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                . '<url><loc>https://blog.example.test/sitemap-page/</loc></url>'
                . '</urlset>',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
        // The sitemap page is only ever fetched by CAPTURE, where the page
        // ghost guard demands at least 1024 bytes containing <html>; give it a
        // real page body so its capture succeeds terminally.
        $GLOBALS['lumeweb_cast_wp_remote_responses']['https://blog.example.test/sitemap-page/'] = [
            'headers' => ['content-type' => 'text/html; charset=UTF-8'],
            'body' => $this->htmlBody(),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];

        CastPlugin::boot('/plugins/cast/cast.php');

        // The whole real pipeline through pack: probe + setup + six seeders +
        // an empty keyset page + one sitemap document drain discovery across
        // ten ticks, capture claims the eight queued rows one per tick and a
        // fixed-point tick crosses the rewrite boundary on the nineteenth,
        // rewrite drains the seven rewritable rows and a fixed-point tick
        // crosses the pack boundary on the twenty-seventh, then a single
        // bounded pack tick runs the validation check and the ZipPackager and
        // advances to the wrapup boundary — twenty-eight bounded units in
        // total.
        for ($i = 0; $i < 28; ++$i) {
            $this->runTick();
        }

        $run = $this->currentRun();

        // The real PackStage ran to completion — not an UnwiredStage — so the
        // run advanced cleanly from pack to the wrapup boundary inside the
        // Publishing bucket, still running and active without error.
        self::assertSame(RunStatus::Running, $run->status);
        self::assertSame(RunStage::Publishing, $run->stage);
        self::assertSame('wrapup|', $run->resumeCursor);
        self::assertNull($run->lastError);

        // The pre-pack validation check ran over the completed artifact tree
        // (no pending items, a real non-ghost root index.html, clean output
        // paths) and the ZipPackager produced the artifact ZIP plus its
        // adjacent manifest on disk, with the resulting PackResult mirrored
        // onto the persisted run for the next WP-Cron request.
        self::assertNotNull($run->pack);
        self::assertNotSame(PackStatus::Failed, $run->pack->status);
        self::assertGreaterThan(0, $run->pack->filesAdded);
        self::assertStringContainsString('cast-exports', $run->pack->zipPath);
        self::assertStringContainsString('run-accept-1.zip', $run->pack->zipPath);
        self::assertFileExists($run->pack->zipPath);
        self::assertNotNull($run->pack->manifestPath);
        self::assertFileExists($run->pack->manifestPath);

        // Pack is a read-only consumer of the queue: the durable SQL item rows
        // are untouched — the same seven rewritten rows and one done fixed
        // asset persist at the wrapup boundary.
        $rewritten = 0;
        $done = 0;
        foreach ($this->persistedRows() as $row) {
            if ($row['status'] === 'rewritten') {
                ++$rewritten;
            } elseif ($row['status'] === 'done') {
                ++$done;
            }
        }
        self::assertSame(7, $rewritten);
        self::assertSame(1, $done);
    }

    /**
     * Full boot/tick acceptance for the real Wrapup stage: after the booted
     * probe/setup/discover/capture/rewrite/pack drain, the booted WrapupStage
     * (wired with the WordPressWrapupEnvironment resolving the uploads jail
     * root) validates the artifact integrity the pack stage left behind,
     * jail-checked recursively deletes the run's work directory, preserves the
     * ZIP + manifest for retention, records the resulting WrapupResult onto
     * the persisted run — and because publish is now the final, separate
     * pipeline boundary, the orchestrator parks the run at the `publish|`
     * boundary (still Running, Publishing) instead of declaring it terminal.
     * The follow-up tick then reaches the booted guarded publish stage — the
     * portal deployment identity is absent from the test process environment,
     * so CastPlugin wired a {@see GuardedPublishStage} — and fails loudly with
     * the actionable missing-environment message rather than silently
     * spinning. This proves wrapup can never silently regress to UnwiredStage
     * (the old unwired boot behaviour) without this test failing, and that the
     * publish boundary fails loudly with an actionable reason instead of
     * parking an UnwiredStage.
     */
    public function testBootedWrapupValidatesArtifactDeletesJailedWorkDirRetainsZipAndManifestPersistsResultAndParksOnPublishThenGuardedPublishFailsLoudly(): void
    {
        // Discovery fetches the detected core sitemap through the real
        // WordPressCaptureHttp; answer that one URL with a small urlset naming
        // one local page while the other runtime responses keep their bodies.
        $GLOBALS['lumeweb_cast_wp_remote_responses']['https://blog.example.test/wp-sitemap.xml'] = [
            'headers' => ['content-type' => 'application/xml; charset=UTF-8'],
            'body' => '<?xml version="1.0" encoding="UTF-8"?>'
                . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                . '<url><loc>https://blog.example.test/sitemap-page/</loc></url>'
                . '</urlset>',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
        // The sitemap page is only ever fetched by CAPTURE, where the page
        // ghost guard demands at least 1024 bytes containing <html>; give it a
        // real page body so its capture succeeds terminally.
        $GLOBALS['lumeweb_cast_wp_remote_responses']['https://blog.example.test/sitemap-page/'] = [
            'headers' => ['content-type' => 'text/html; charset=UTF-8'],
            'body' => $this->htmlBody(),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];

        CastPlugin::boot('/plugins/cast/cast.php');

        // The whole real pipeline through wrap-up: probe + setup + six seeders
        // + an empty keyset page + one sitemap document drain discovery across
        // ten ticks, capture claims the eight queued rows one per tick and a
        // fixed-point tick crosses the rewrite boundary on the nineteenth,
        // rewrite drains the seven rewritable rows and a fixed-point tick
        // crosses the pack boundary on the twenty-seventh, the bounded pack
        // tick writes the artifact and advances to the wrapup boundary on the
        // twenty-eighth, then a single bounded wrap-up tick validates +
        // deletes and — because publish is a separate final boundary — parks
        // the run at `publish|` on the twenty-ninth.
        for ($i = 0; $i < 29; ++$i) {
            $this->runTick();
        }

        $run = $this->currentRun();

        // The real WrapupStage ran to completion — not an UnwiredStage — but
        // the run is NOT terminal: the orchestrator parked it at the publish
        // boundary inside the Publishing bucket, still Running and with no
        // error.
        self::assertFalse($run->isTerminal());
        self::assertNull($run->lastError);
        self::assertSame(RunStatus::Running, $run->status);
        self::assertSame(RunStage::Publishing, $run->stage);
        self::assertSame('publish|', $run->resumeCursor);

        // The wrap-up result was mirrored onto the persisted run: the artifact
        // passed its integrity check and the jailed work directory was
        // deleted, with the pack warnings carried forward.
        self::assertNotNull($run->wrapup);
        self::assertTrue($run->wrapup->success);
        self::assertTrue($run->wrapup->workDirDeleted);
        self::assertFalse($run->wrapup->hasIntegrityFailure());
        self::assertNull($run->wrapup->deletionFailure);
        self::assertNotNull($run->pack);
        self::assertSame($run->pack->warnings, $run->wrapup->warnings);

        // The jailed work directory the setup stage created is gone, deleted
        // by wrap-up inside the uploads jail...
        self::assertDirectoryDoesNotExist(
            realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'cast-work' . DIRECTORY_SEPARATOR . 'run-accept-1',
        );

        // ...while the artifact ZIP and its adjacent manifest survive for
        // retention, exactly where the pack stage wrote them.
        self::assertFileExists($run->pack->zipPath);
        self::assertNotNull($run->pack->manifestPath);
        self::assertFileExists($run->pack->manifestPath);

        // The publish boundary has not run yet: the run parked on it with no
        // persisted publish outcome.
        self::assertNull($run->publish);

        // The persisted run survives the journey: round-tripping the saved
        // option value is lossless, including the wrap-up result.
        self::assertSame($run->toArray(), ExportRun::fromArray($run->toArray())->toArray());

        // A further tick reaches the guarded publish boundary and fails loudly
        // with the actionable missing-environment reason instead of silently
        // spinning: the portal deployment identity (PORTAL_API_URL and
        // PORTAL_API_KEY) is absent from the test process environment, so the
        // boot composition wired a {@see GuardedPublishStage} that names the
        // missing prerequisite and the tick runner records the retry rather
        // than leaving the run wedged or falsely completing it.
        $this->runTick();

        $run = $this->currentRun();
        self::assertStringContainsString(
            'the Pinner deployment environment (PORTAL_API_URL and PORTAL_API_KEY) is not configured',
            (string) $run->lastError,
        );
        self::assertSame(RunStatus::Running, $run->status);
        self::assertSame(1, $run->retryCount);
    }

    /**
     * Full boot/tick acceptance for the self-rearm path: after a real booted
     * tick leaves more work behind (the probe and setup stages ran, discover
     * is still pending), the rearm wiring arms exactly one next
     * `cast/export/auto-tick` on Action Scheduler at the configured short
     * cadence. This proves the production composition
     * (JobsHookSubscriber → WordPressActionScheduler →
     * WordPressActionSchedulerGateway → as_* API) rearms end to end into the
     * Action Scheduler store — never the vanilla WP-Cron single-event engine.
     */
    public function testBootedTickRearmsExactlyOneNextAutoTick(): void
    {
        CastPlugin::boot('/plugins/cast/cast.php');

        $before = time();

        $this->runTick();

        $actions = $GLOBALS['lumeweb_cast_actions']['actions'];
        self::assertCount(1, $actions);
        $event = reset($actions);
        self::assertSame(ContentPublishScheduler::AUTO_HOOK, $event['hook']);
        self::assertSame([], $event['args']);
        self::assertSame(WordPressActionScheduler::DEFAULT_GROUP, $event['group']);
        self::assertGreaterThanOrEqual($before + 2, $event['timestamp']);
        self::assertLessThanOrEqual(time() + 2, $event['timestamp']);
        self::assertSame(
            $event['timestamp'],
            as_next_scheduled_action(
                ContentPublishScheduler::AUTO_HOOK,
                [],
                WordPressActionScheduler::DEFAULT_GROUP,
            ),
        );
    }

    /**
     * Full boot/tick dedup acceptance: firing the tick again while the rearmed
     * event is still pending must not stack a second auto-tick. Deduplication
     * is shared with Action Scheduler's unique-slot semantics ((hook, args,
     * group) identity), so exactly one event survives — no zombie auto-ticks,
     * no tick storm.
     */
    public function testBootedConsecutiveTicksDoNotStackDuplicateAutoTicks(): void
    {
        CastPlugin::boot('/plugins/cast/cast.php');

        $this->runTick();
        $first = $GLOBALS['lumeweb_cast_actions']['actions'];

        $this->runTick();

        self::assertSame(1, count($GLOBALS['lumeweb_cast_actions']['actions']));
        self::assertSame($first, $GLOBALS['lumeweb_cast_actions']['actions']);
    }

    /**
     * Drive the tick exactly as WP-Cron fires it: through the AUTO_HOOK action
     * the booted {@see JobsHookSubscriber} registered with the shared tick
     * runner.
     */
    private function runTick(): void
    {
        foreach ($GLOBALS['lumeweb_cast_hooks'] as $entry) {
            if (($entry[0] ?? null) === 'action' && ($entry[1] ?? null) === ContentPublishScheduler::AUTO_HOOK) {
                $callback = $entry[4] ?? null;
                self::assertIsCallable($callback);
                $callback();

                return;
            }
        }

        self::fail(sprintf('The %s action was not registered by boot.', ContentPublishScheduler::AUTO_HOOK));
    }

    private function currentRun(): ExportRun
    {
        return ExportRun::fromArray($GLOBALS['lumeweb_cast_options']['cast_export_run']);
    }

    private function htmlBody(string $extra = ''): string
    {
        return '<!DOCTYPE html><html><head><title>Home</title></head><body><p>' . str_repeat('welcome', 200) . $extra . '</p></body></html>';
    }
}
