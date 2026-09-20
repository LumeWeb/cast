<?php

declare(strict_types=1);

namespace LumeWeb\Cast;

use ComposePress\Core\Plugin;
use ComposePress\Core\PluginContext;
use GuzzleHttp\Psr7\HttpFactory;
use LumeWeb\Cast\Admin\DomainRestHandler;
use LumeWeb\Cast\Admin\DomainRestRouteRegistrar;
use LumeWeb\Cast\Admin\DomainSetupService;
use LumeWeb\Cast\Admin\OnboardingAdminSubscriber;
use LumeWeb\Cast\Admin\OnboardingRequestHandler;
use LumeWeb\Cast\Admin\PermalinkGuard;
use LumeWeb\Cast\Admin\PortalConnectionResolver;
use LumeWeb\Cast\Admin\PublishAdminSubscriber;
use LumeWeb\Cast\Admin\PublishRestHandler;
use LumeWeb\Cast\Admin\PublishRestRouteRegistrar;
use LumeWeb\Cast\Admin\PublishSetupService;
use LumeWeb\Cast\Admin\WordPressNoticeDismissalStore;
use LumeWeb\Cast\Admin\WordPressPermalinkNoticeDismissalStore;
use LumeWeb\Cast\Admin\WordPressPermalinkSettings;
use LumeWeb\Cast\Admin\WordPressPublishedContentProbe;
use LumeWeb\Cast\Admin\WordPressRequestContext;
use LumeWeb\Cast\Admin\WordPressRestAuth;
use LumeWeb\Cast\Environment\EnvIdentity;
use LumeWeb\Cast\Export\CaptureStage;
use LumeWeb\Cast\Export\DiscoverStage;
use LumeWeb\Cast\Export\GuardedPublishStage;
use LumeWeb\Cast\Export\PackStage;
use LumeWeb\Cast\Export\PipelineContext;
use LumeWeb\Cast\Export\PipelineStageKey;
use LumeWeb\Cast\Export\PipelineState;
use LumeWeb\Cast\Export\ArtifactRetentionService;
use LumeWeb\Cast\Export\ProbeStage;
use LumeWeb\Cast\Export\PublishStage;
use LumeWeb\Cast\Export\RepositoryWorkItemStateProvider;
use LumeWeb\Cast\Export\RetentionPolicy;
use LumeWeb\Cast\Export\RewriteStage;
use LumeWeb\Cast\Export\RunSettings;
use LumeWeb\Cast\Export\WordPressArtifactStore;
use LumeWeb\Cast\Export\SetupStage;
use LumeWeb\Cast\Export\WordPressCaptureEnvironment;
use LumeWeb\Cast\Export\WordPressCaptureHttp;
use LumeWeb\Cast\Export\WordPressDiscoverEnvironment;
use LumeWeb\Cast\Export\WordPressPackEnvironment;
use LumeWeb\Cast\Export\WordPressProbeEnvironment;
use LumeWeb\Cast\Export\WordPressRewriteEnvironment;
use LumeWeb\Cast\Export\WordPressSetupEnvironment;
use LumeWeb\Cast\Export\WordPressWrapupEnvironment;
use LumeWeb\Cast\Export\WrapupStage;
use LumeWeb\Cast\Http\GuzzleHttpClientFactory;
use LumeWeb\Cast\Http\HttpTransport;
use LumeWeb\Cast\Jobs\ContentPublishScheduler;
use LumeWeb\Cast\Jobs\ExportPipelineTick;
use LumeWeb\Cast\Jobs\ExportTickRunner;
use LumeWeb\Cast\Jobs\JobsHookSubscriber;
use LumeWeb\Cast\Jobs\RetentionRunner;
use LumeWeb\Cast\Jobs\RetentionScheduler;
use LumeWeb\Cast\Jobs\SystemClock;
use LumeWeb\Cast\Jobs\TickConfig;
use LumeWeb\Cast\Jobs\WordPressActionScheduler;
use LumeWeb\Cast\Jobs\WordPressActionSchedulerGateway;
use LumeWeb\Cast\Jobs\WordPressIdentityGateway;
use LumeWeb\Cast\Jobs\WordPressLock;
use LumeWeb\Cast\Jobs\WordPressPublishModeStore;
use LumeWeb\Cast\Onboarding\WizardService;
use LumeWeb\Cast\PageBuilder\PageBuilderCatalog;
use LumeWeb\Cast\PageBuilder\PageBuilderInstaller;
use LumeWeb\Cast\PageBuilder\WordPressPluginStateProvider;
use LumeWeb\Cast\Persistence\CastExportItemsTable;
use LumeWeb\Cast\Persistence\SqlWorkItemRepository;
use LumeWeb\Cast\Persistence\WordPressOptionGateway;
use LumeWeb\Cast\Persistence\WordPressRunRepository;
use LumeWeb\Cast\Persistence\WordPressWizardStore;
use LumeWeb\Cast\Persistence\WordPressWpDbGateway;
use LumeWeb\Cast\Publish\IpfsDomainClient;
use LumeWeb\Cast\Publish\IpfsIpnsClient;
use LumeWeb\Cast\Publish\IpfsUploadClient;
use LumeWeb\Cast\Publish\IpfsWebsiteClient;
use LumeWeb\Cast\Publish\PublishService;
use LumeWeb\Cast\Publish\SystemPublishClock;
use LumeWeb\Cast\Publish\UploadRouter;
use LumeWeb\Cast\Publish\UploadWaiter;
use LumeWeb\Cast\Publish\WebsiteReadinessWaiter;
use LumeWeb\Cast\Publish\WordPressPublishRegistry;
use LumeWeb\Cast\Upload\PostUploader;
use LumeWeb\Cast\Upload\TusUploader;
use LumeWeb\Cast\Upload\UploadResultClient;
use TusPhp\Cache\FileStore;

final class CastPlugin
{
    private const SLUG = 'cast';
    private const VERSION = '0.1.0';

    /**
     * Process-local boot guard: boot is idempotent because the WordPress hook
     * registrations it performs must happen exactly once per request, even if
     * the plugin entry point ends up being included (and booted) twice.
     */
    private static bool $booted = false;

    public static function boot(string $pluginFile, ?HttpTransport $publishTransport = null): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;


        // First-run onboarding: one global wizard aggregate persisted in the
        // non-autoloaded `cast_onboarding` option (no per-user user-meta) and
        // an admin-only "Getting Started" entrypoint.
        $store = new WordPressWizardStore();
        $wizard = new WizardService(
            $store,
            new PageBuilderCatalog(),
            plugins: new WordPressPluginStateProvider(),
        );
        $context = new WordPressRequestContext();
        $handler = new OnboardingRequestHandler($wizard, $context);
        $catalog = new PageBuilderCatalog();
        $plugins = new WordPressPluginStateProvider();
        $installer = new PageBuilderInstaller($catalog, $plugins);

        // Background publish jobs: WordPress adapters over the pure Jobs
        // mechanics. One shared option gateway backs the run repository, the
        // lease lock and the publish identity reader; the scheduler adapter rides
        // Action Scheduler (the WordPressActionScheduler adapter over the
        // guarded as_* gateway — never the vanilla WP-Cron single-event
        // functions); the debounce/squash scheduler and the tick runner are
        // composed once and shared by the hook subscriber. Action Scheduler's
        // own WP-Cron/loopback queue runner is left untouched so the actions
        // Cast schedules keep firing on the site's normal cadence.
        //
        // The tick is the real pipeline orchestrator over ONE shared
        // PipelineState (the same instance the stages record into and the
        // orchestrator re-hydrates from the persisted run on every tick, so a
        // fresh scheduled request resumes instead of re-running probe/setup).
        //
        // Probe is wired as the real loopback probe: it speaks through the
        // WordPress HTTP stack (WordPressCaptureHttp), reads live WordPress
        // runtime facts (WordPressProbeEnvironment) and records its ProbeResult
        // into the shared state. The probe is strictly anonymous: it never
        // sources, stores or attaches credentials — the deployment's Caddy
        // bypasses Basic Auth on the plugin's own loopback requests, so a
        // guarded origin fails the probe as a safe loopback access failure
        // without any credential advice.
        //
        // Setup is wired as the real setup stage, but constructed per run AT
        // TICK TIME through a factory because it needs the current run id for
        // its jailed work directory; it reads the uploads root from WordPress
        // (WordPressSetupEnvironment) and records its SetupResult into the same
        // shared state.
        //
        // Discover is wired as the real discovery stage: it reads live WordPress
        // runtime facts (WordPressDiscoverEnvironment) for the static seeders,
        // the published-content keyset over the legacy posts table and the
        // detected sitemap candidates, enqueues candidates through the durable
        // SqlWorkItemRepository over the shared `cast_export_items` table
        // (WordPressWpDbGateway) and records its DiscoverResult into the shared
        // state.
        //
        // Capture is wired as the real capture stage: it claims each queued row
        // from the SAME SqlWorkItemRepository discovery filled, captures it
        // through the WordPress runtime (WordPressCaptureEnvironment =
        // WordPressCaptureTransport over WordPressCaptureHttp with
        // WordPressAssetFileSystem/roots/paths for local asset snapshots and
        // LocalOutputFileSystem for atomic output), transitions the row to its
        // terminal status and records the CaptureSummary onto the shared state.
        // Capture is strictly anonymous exactly like the probe: no credentials
        // are ever sourced or attached. Rewrite is wired as the real rewrite
        // stage, but constructed per
        // run AT TICK TIME through a factory because its WordPress environment
        // must be jailed to the run's work directory, which the persisted
        // SetupResult rehydrated into the shared state reveals (read/write body
        // adapters carry no work-directory argument of their own); it claims each
        // rewritable Done row from the same SqlWorkItemRepository, runs the
        // pure per-run RewriteService over the captured body, writes the
        // rewritten body back atomically and records the RewriteSummary onto
        // the shared state.
        //
        // Pack is wired as the real pack stage, but constructed per run AT TICK
        // TIME through a factory because its artifacts are keyed by the
        // current run id: WordPressPackEnvironment resolves the ZIP into the
        // uploads-root `cast-exports` sibling (never inside the work jail),
        // the pre-pack validation check runs over the completed artifact tree
        // with the same SqlWorkItemRepository and the ZipPackager writes the
        // artifact ZIP + adjacent manifest, recording the PackResult onto the
        // shared state. Wrapup is wired as the real wrap-up stage: it resolves the
        // uploads jail root from WordPress (WordPressWrapupEnvironment), validates
        // the artifact the pack stage left behind, jail-checked recursively
        // deletes the run's jailed work directory while preserving the ZIP +
        // manifest, and records its WrapupResult onto the shared state. Publish
        // is the last boundary: a separate, final pipeline stage after wrapup.
        // Unlike every earlier boundary its real wiring depends on the portal
        // deployment identity resolved from the process environment at boot —
        // the SDK clients need a base URL and a bearer API key with nothing to
        // fall back to. When the identity is complete the boundary is a
        // per-run {@see PublishStage} factory over the full Publish stack (the
        // real uploaders, website/IPNS SDK clients and the shared
        // PublishRegistry); when it is not, the boundary is a
        // {@see GuardedPublishStage} that fails loudly with an actionable
        // message instead of parking an UnwiredStage. Only after this final
        // boundary does the orchestrator move the run to a terminal status.
        $options = new WordPressOptionGateway();
        $clock = new SystemClock();
        $repository = new WordPressRunRepository($options);
        $lock = new WordPressLock($options, $clock);
        // Scheduler adapter: ALL of Cast's one-shot scheduling (debounce/squash,
        // tick rearm, follow-up, retention) rides Action Scheduler through the
        // guarded as_* gateway. Cast never calls the vanilla WP-Cron single
        // event functions; Action Scheduler itself may be driven by its own
        // WP-Cron/loopback runner, which Cast must NOT disable or bypass.
        $scheduler = new WordPressActionScheduler(new WordPressActionSchedulerGateway());
        $identity = new WordPressIdentityGateway($options);
        // The persisted publish mode controls auto-scheduling (Manual default);
        // the same store backs the admin publish setup service below.
        $publishMode = new WordPressPublishModeStore($options);
        // The publish registry and the identity gateway share the same option
        // store: write through the registry, read readiness through the
        // gateway. PublishService orchestration consumes the registry (wired
        // below, only when the portal identity is present) to decide first vs
        // subsequent publish and to resume a stalled publish from exactly
        // where it stopped.
        $registry = new WordPressPublishRegistry($options);
        $state = new PipelineState();
        $setupEnvironment = new WordPressSetupEnvironment();
        $items = new SqlWorkItemRepository(
            new WordPressWpDbGateway(),
            CastExportItemsTable::name(self::tablePrefix()),
        );

        // The publish boundary's guard: resolve the portal deployment identity
        // once at boot. A complete identity (PORTAL_API_URL + PORTAL_API_KEY)
        // lets the composition build the real publish stack; an incomplete one
        // leaves the boundary as a GuardedPublishStage whose failure message
        // names exactly what is missing.
        //
        // The shared HttpTransport is composed ONCE here (an injected recording
        // transport in the boot acceptance suite, the Guzzle-backed default
        // with portal-safe timeouts, no redirect following and no cookies in
        // production). The publish/domain/upload clients and the dashboard
        // Connection resolver all reuse it, so composition always shares the
        // injected test transport when one is provided.
        $deploymentEnv = EnvIdentity::fromEnvironment();
        $publishIdentity = $deploymentEnv->resolve();
        $guardedPublish = $publishIdentity === null ? new GuardedPublishStage() : null;
        $publishFactory = null;
        $http = new HttpFactory();
        $transport = $publishTransport ?? new HttpTransport(
            (new GuzzleHttpClientFactory())->create(),
            $http,
            $http,
        );
        // The dashboard Connection card resolves its account/workspace
        // self-identification LAZILY through PortalConnectionResolver — never
        // during this boot (a plain boot performs no portal request). With an
        // incomplete deployment env the resolver short-circuits to a safe
        // value-free problem state without touching the transport.
        $connectionResolver = new PortalConnectionResolver($deploymentEnv, $transport);
        // The domain-setup REST surface also needs the portal identity (its SDK
        // domain client requires a base URL + bearer API key), so it is only
        // composed inside the same guard and stays null — leaving the routes
        // unregistered — when the identity is incomplete. The SAME service
        // instance is handed to the publish admin subscriber so the dashboard
        // panel and the REST routes always agree about one domain client.
        $domainSetup = null;
        $domainRestRegistrar = null;
        // The raw SDK websites client doubles as the PublishSetupService
        // awaiting-website registry adapter (wrapped in the WebsiteRegistry
        // adapter so the guided card can also create), and the raw workspace
        // client doubles as the guided link adapter (wrapped in the
        // WorkspaceLinker adapter); both default null and are only populated
        // inside the portal-identity guard below.
        $rawWebsites = null;
        $websiteRegistry = null;
        $workspaceLinker = null;
        $rawIpns = null;
        if ($publishIdentity !== null) {
            // Publish/upload/website/IPNS/domain clients are all
            // portal-plugin-ipfs endpoints, so they are routed to the derived
            // IPFS/workspace API base (ipfs.pinner.xyz) — never the canonical
            // or account base (see PortalEndpoints / PortalIdentity).
            $baseUrl = $publishIdentity->ipfsBaseUrl();
            $bearer = $publishIdentity->accountKey();
            // The shared HttpTransport was composed above (the injected
            // recording transport in the boot acceptance suite, the
            // Guzzle-backed default in production); every publish/domain/upload
            // client below reuses it.
            // TUS sessions persist their upload keys in a tus-php FileStore
            // under the WordPress uploads root so a later request can resume
            // an interrupted large upload without re-creating it. The path is
            // side-effect-free at construction; the store only writes when a
            // real TUS upload happens.
            $tusCache = new FileStore(
                $setupEnvironment->uploadsDirectory() . DIRECTORY_SEPARATOR . 'cast-tus-cache' . DIRECTORY_SEPARATOR,
                'client.cache',
            );
            // The website surface is shared between publishing and the
            // readiness wait so the same client instance polls the portal for
            // the site that the publish just created/re-pointed. The raw SDK
            // websites client is also handed to the publish setup service
            // below as the awaiting-website registry-first adapter.
            $rawWebsites = new \LumeWeb\Cast\Ipfs\IpfsWebsitesClient($transport, $baseUrl, $bearer);
            $websiteRegistry = new \LumeWeb\Cast\Ipfs\IpfsWebsiteRegistry($rawWebsites);
            $workspaceLinker = new \LumeWeb\Cast\Ipfs\IpfsWorkspaceLinker(
                new \LumeWeb\Cast\Ipfs\IpfsWorkspaceClient($transport, $baseUrl, $bearer),
            );
            $websites = new IpfsWebsiteClient($rawWebsites);
            // The raw SDK IPNS client is shared by the publish orchestration
            // (createKey/publish) and the awaiting-website registry match
            // (resolve an IPNS-targeted website's name back to the immutable
            // CID), so both paths always see the same portal. It stays null
            // outside the guard and the registry match then treats every listed
            // website by its raw target_hash (no IPNS resolution).
            $rawIpns = new \LumeWeb\Cast\Ipfs\IpfsIpnsClient($transport, $baseUrl, $bearer);
            $publishService = new PublishService(
                router: new UploadRouter(),
                uploads: new UploadWaiter(
                    new IpfsUploadClient(
                        new PostUploader($transport, $baseUrl, $bearer),
                        new TusUploader(baseUrl: $baseUrl, bearerToken: $bearer, cache: $tusCache),
                        new UploadResultClient($transport, $baseUrl, $bearer),
                    ),
                    new SystemPublishClock(),
                ),
                websites: $websites,
                ipns: new IpfsIpnsClient($rawIpns, $registry),
                registry: $registry,
                readiness: new WebsiteReadinessWaiter($websites, new SystemPublishClock()),
            );
            // The real publish boundary is a per-run factory like Setup/Rewrite/
            // Pack: it derives the artifact's target type from the run's
            // persisted settings snapshot and the publish label from the probed
            // origin the orchestrator re-hydrated into the shared state just
            // before the factory runs.
            $publishFactory = static fn (string $runId, ?RunSettings $settings): PublishStage => new PublishStage(
                publish: $publishService,
                state: $state,
                targetType: $settings->targetType ?? 'ipns',
                label: $state->probe?->origin?->host() ?? 'site',
            );
            // Admin domain-setup surface: the same transport/baseUrl/bearer
            // the publish stack uses drive the ipfs-sdk domains client, and
            // the service shares the same identity gateway so the website
            // binding always targets the registered website id. The registrar
            // only registers rest_api_init — no front-end hooks; the same
            // service backs the publish dashboard panel via the subscriber.
            $domainSetup = new DomainSetupService(
                env: EnvIdentity::fromEnvironment(),
                identity: $identity,
                // The raw websites client is handed to the DomainClient adapter
                // so the same shared transport serves the website-level DNS
                // validation (POST /api/websites/{id}/validate) that the panel's
                // Validate action issues — the adapter stays the only interface the
                // admin layer talks to for domain work.
                domains: new IpfsDomainClient(
                    new \LumeWeb\Cast\Ipfs\IpfsDomainsClient($transport, $baseUrl, $bearer),
                    $rawWebsites,
                ),
            );
            $domainRestRegistrar = new DomainRestRouteRegistrar(
                new DomainRestHandler($domainSetup),
                new WordPressRestAuth(),
            );
        }

        // The capture environment is constructed ONCE and shared by the real
        // capture stage and the rewrite stage's asset reconciliation:
        // Rewrite re-captures the assets its rewriters collected through the
        // SAME WordPress machinery Capture uses (WordPressCaptureHttp over the
        // live HTTP stack + WordPressAssetFileSystem/local snapshots
        // + LocalOutputFileSystem over the run's work directory), so retry
        // policy and terminal Failed marking are never duplicated. It is
        // stateless at construction and built well before either stage map.
        $captureEnvironment = new WordPressCaptureEnvironment();
        $pipelineStages = [
            PipelineStageKey::Probe->value => new ProbeStage(
                http: new WordPressCaptureHttp(),
                environment: new WordPressProbeEnvironment(),
                state: $state,
            ),
            PipelineStageKey::Discover->value => new DiscoverStage(
                environment: new WordPressDiscoverEnvironment(),
                state: $state,
                repository: $items,
                http: new WordPressCaptureHttp(),
            ),
            PipelineStageKey::Capture->value => new CaptureStage(
                environment: $captureEnvironment,
                state: $state,
                repository: $items,
            ),
            PipelineStageKey::Wrapup->value => new WrapupStage(
                environment: new WordPressWrapupEnvironment(),
                state: $state,
            ),
        ];
        if ($guardedPublish !== null) {
            // Portal environment absent: the publish boundary fails loudly with
            // an actionable message the moment a tick reaches it, rather than
            // silently spinning or falsely completing the run.
            $pipelineStages[PipelineStageKey::Publish->value] = $guardedPublish;
        }

        $pipelineFactories = [
            // Setup is constructed per run AT TICK TIME because it needs the
            // current run id for its jailed work directory; it records its
            // SetupResult into the shared state. Rewrite is constructed per
            // run the same way because its WordPress environment is jailed to
            // that run's work directory — read from the SetupResult the
            // orchestrator re-hydrated into the shared state just before the
            // factory runs (the Setup work-dir is the single source of truth
            // Capture wrote into and Rewrite reads/writes the same bodies).
            // Pack is constructed per run the same way because its artifact
            // ZIP is keyed by the current run id: WordPressPackEnvironment
            // resolves it into the uploads-root `cast-exports` sibling and the
            // pre-pack validation check shares the same SqlWorkItemRepository
            // discovered/captured/rewrote through.
            PipelineStageKey::Setup->value => static fn (string $runId): SetupStage => new SetupStage(
                environment: $setupEnvironment,
                state: $state,
                runId: $runId,
            ),
            PipelineStageKey::Rewrite->value => static fn (string $runId): RewriteStage => new RewriteStage(
                environment: new WordPressRewriteEnvironment($state->setup->workDir ?? ''),
                state: $state,
                repository: $items,
                // Asset reconciliation: after the rewritable-Done fixed point,
                // the booted RewriteStage re-captures the assets its rewriters
                // collected through the SAME capture machinery Capture uses
                // ($captureEnvironment, composed above), so collected
                // assets/media join the export instead of being silently
                // dropped. Without this, the stage keeps the legacy inert
                // behavior — rewrite, collect, finish.
                captureEnvironment: $captureEnvironment,
            ),
            PipelineStageKey::Pack->value => static fn (string $runId): PackStage => new PackStage(
                environment: new WordPressPackEnvironment(),
                state: $state,
                repository: $items,
                runId: $runId,
            ),
        ];
        if ($publishFactory !== null) {
            $pipelineFactories[PipelineStageKey::Publish->value] = $publishFactory;
        }

        $pipeline = new PipelineContext(
            runs: $repository,
            state: $state,
            stages: $pipelineStages,
            stageFactories: $pipelineFactories,
        );
        // A crashed worker's lease expires after the TTL; the next tick must be
        // able to reclaim stale locks, or an expired lease would strand a
        // pending dirty queued run behind a SkippedStaleLock that never re-arms.
        $tickRunner = new ExportTickRunner(
            clock: $clock,
            lock: $lock,
            repository: $repository,
            tick: new ExportPipelineTick($pipeline),
            identity: $identity,
            config: new TickConfig(reclaimStaleLocks: true),
        );
        // The scheduler clears the shared work-item queue exactly when a new
        // run id is persisted (ensurePendingRun), so the fresh per-run work
        // directory never inherits the prior run's done/rewritten rows — the
        // same SqlWorkItemRepository the discover/capture/rewrite stages drive.
        $contentScheduler = new ContentPublishScheduler($clock, $repository, $scheduler, $identity, $publishMode, $items);

        // Artifact retention GC: one shared WordPressArtifactStore over the
        // uploads-root `cast-exports` jail (the same directory Pack writes the
        // per-run ZIPs and the shared manifest.json into). The pure policy
        // reads `cast_publish_retention_days` live at each sweep (default 7
        // days, 0 disables, invalid values fall back to the default), the pure
        // service protects the shared manifest, the latest artifact and every
        // non-terminal/resumable run artifact, and the RetentionScheduler arms
        // one deduplicated retention sweep after every terminal run plus a
        // daily periodic rearm — never a tick storm, exactly the single-event
        // contract the export loop already relies on.
        $retentionService = new ArtifactRetentionService(
            new WordPressArtifactStore(),
            $repository,
        );
        $retentionScheduler = new RetentionScheduler($scheduler, $clock);
        $retentionRunner = new RetentionRunner(
            $retentionService,
            $retentionScheduler,
            $clock,
            static fn (): RetentionPolicy => RetentionPolicy::fromOption(get_option(RetentionPolicy::OPTION, null)),
        );

        // Publish admin UX: the REST surface reads the deployment identity
        // (EnvIdentity from the process environment) and composes the same
        // run repository, wizard store, scheduler and identity gateway the
        // background jobs use, so everything agrees on a single run slot. The
        // registrar only registers rest_api_init — no front-end hooks.
        $publishSetup = new PublishSetupService(
            env: $deploymentEnv,
            wizardStore: $store,
            content: new WordPressPublishedContentProbe(),
            repository: $repository,
            identity: $identity,
            contentScheduler: $contentScheduler,
            modeStore: $publishMode,
            clock: $clock,
            // The dashboard Connection card: lazily resolves the portal
            // account/workspace self-identification only when a status/dashboard
            // is read — never during this boot.
            connection: $connectionResolver,
            // The dismissible "publish to Pinner" notice's per-user persistence;
            // re-armed whenever a publish is initiated (start/now/artifact).
            noticeDismissals: new WordPressNoticeDismissalStore(),
            // The live per-stage numerator adapter: the same item table the
            // discovery/capture/rewrite stages drive, read through the shared
            // validation-check state provider. Lets the status report advance
            // "Captured X of M URLs" while capture/rewrite are still running.
            workItems: new RepositoryWorkItemStateProvider($items),
            // The awaiting-website registry-first adapters: the shared account
            // website registry (the adapter over the raw SDK client, so the
            // guided card can both list and create), the publish registry for
            // the cache write-through, and the workspace linker for the guided
            // link action. All null-safe — an incomplete portal identity leaves
            // them unset, the derivation degrades to the run's own park signal
            // without touching the network, and the guided actions refuse
            // with Unavailable.
            websites: $websiteRegistry,
            registry: $registry,
            workspaces: $workspaceLinker,
            // The IPNS resolver drives the registry-first match for IPNS-
            // targeted websites (default Cast mode); null without a complete
            // portal identity, exactly like the other optional adapters.
            ipns: $rawIpns,
        );
        $restRegistrar = new PublishRestRouteRegistrar(
            new PublishRestHandler($publishSetup),
            new WordPressRestAuth(),
        );

        $subscribers = [
            // Permalink guard: the export probe fails when permalinks are
            // 'Plain', so plain structures are forced to 'Day and name' on
            // admin_init (one hard flush, flagged) and a custom non-empty
            // structure triggers a dismissible notice with a one-click
            // 'Use Day and name' action instead of silently being overwritten.
            new PermalinkGuard(
                $context,
                new WordPressPermalinkSettings(),
                new WordPressPermalinkNoticeDismissalStore(),
            ),
            // The same server-side provider is shared everywhere so the wizard
            // install rows and the view-model reconciliation always agree about
            // the actual runtime plugin state.
            new OnboardingAdminSubscriber($handler, $wizard, $context, $catalog, $installer, $plugins),
            // Background job hooks: WP-Cron tick + follow-up actions, the
            // transition_post_status dirty-marking and the retention sweep
            // (no front-end filters). Retention is armed once after any
            // terminal run and re-armed periodically by its own handler.
            new JobsHookSubscriber(
                $tickRunner,
                $contentScheduler,
                $scheduler,
                $clock,
                retentionScheduler: $retentionScheduler,
                retentionRunner: $retentionRunner,
            ),
            // Publish REST routes: GET status + POST start under cast/v1,
            // capability + wp_rest nonce gated (rest_api_init only).
            $restRegistrar,
            // Publish admin surface: menu page, per-screen capability-gated
            // styles and the admin-bar node. Action-only hooks — nothing on
            // the front end — over the same status service + request context
            // the REST surface uses, so the dashboard and the admin bar agree
            // on one run slot. Its registerAssets() narrows to
            // toplevel_page_workspace-publish for manage_options users. The
            // same domain-setup service the REST surface uses (null without a
            // complete portal identity) feeds the choose-a-domain panel.
            new PublishAdminSubscriber($publishSetup, $context, $domainSetup),
        ];
        if ($domainRestRegistrar !== null) {
            // Domain-setup REST routes (only with a complete portal identity):
            // list/bind/DNS/verify/delete/platform/availability/SSL under
            // cast/v1, capability + wp_rest nonce gated (rest_api_init only).
            $subscribers[] = $domainRestRegistrar;
        }

        $plugin = new Plugin(
            context: new PluginContext($pluginFile, self::SLUG, self::VERSION),
            subscribers: $subscribers,
            activator: new CastActivator(self::VERSION),
            deactivator: new CastDeactivator(),
            uninstaller: Uninstall::class,
        );

        $plugin->boot();
    }

    /**
     * Test-only helper: clears the process-local boot guard so a fresh PHPUnit
     * test can boot again as if a new WordPress request had arrived. Real
     * WordPress never calls this — one boot per request is the production
     * contract; the unit harness (where an entire suite shares one process)
     * needs it to re-arm {@see self::boot()} between tests.
     *
     * @internal
     */
    public static function resetBootIdempotence(): void
    {
        self::$booted = false;
    }

    /**
     * The live `$wpdb->prefix` naming the items table, read the same way the
     * WordPress persistence adapters do; the plugin only boots inside
     * WordPress, so the global is always present.
     */
    private static function tablePrefix(): string
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;
        if (!is_object($wpdb)) {
            throw new \RuntimeException('CastPlugin requires the WordPress $wpdb global.');
        }

        /** @var \wpdb $wpdb */
        return $wpdb->prefix;
    }
}
