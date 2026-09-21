<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use GuzzleHttp\Psr7\Response;
use LumeWeb\Cast\Admin\ConnectionResolver;
use LumeWeb\Cast\Admin\PublishCancelRefusal;
use LumeWeb\Cast\Admin\PublishExistingRefusal;
use LumeWeb\Cast\Admin\PublishModeRefusal;
use LumeWeb\Cast\Admin\PublishSetupService;
use LumeWeb\Cast\Admin\PublishStartRefusal;
use LumeWeb\Cast\Admin\PublishWebsiteRefusal;
use LumeWeb\Cast\Environment\PortalIdentity;
use LumeWeb\Cast\Http\HttpResponse;
use LumeWeb\Cast\Http\UnexpectedStatusCodeException;
use LumeWeb\Cast\Ipfs\IpnsResolver;
use LumeWeb\Cast\Ipfs\Website;
use LumeWeb\Cast\Ipfs\WebsiteRegistry;
use LumeWeb\Cast\Ipfs\Workspace;
use LumeWeb\Cast\Ipfs\WorkspaceLinker;
use LumeWeb\Cast\Portal\Account;
use LumeWeb\Cast\Portal\SelfIdentification;
use LumeWeb\Cast\Environment\EnvIdentity;
use LumeWeb\Cast\Environment\EnvReader;
use LumeWeb\Cast\Export\CaptureSummary;
use LumeWeb\Cast\Export\DiscoverResult;
use LumeWeb\Cast\Export\ExportRun;
use LumeWeb\Cast\Export\InMemoryRunRepository;
use LumeWeb\Cast\Export\InMemoryWorkItemRepository;
use LumeWeb\Cast\Export\RepositoryWorkItemStateProvider;
use LumeWeb\Cast\Export\WorkItemFactory;
use LumeWeb\Cast\Export\PackResult;
use LumeWeb\Cast\Export\PackStatus;
use LumeWeb\Cast\Export\PublishBoundaryResult;
use LumeWeb\Cast\Export\RewriteSummary;
use LumeWeb\Cast\Export\RunSettings;
use LumeWeb\Cast\Export\RunStage;
use LumeWeb\Cast\Export\RunStatus;
use LumeWeb\Cast\Export\WorkItemStateProvider;
use LumeWeb\Cast\Export\WorkItemStatus;
use LumeWeb\Cast\Jobs\ContentPublishScheduler;
use LumeWeb\Cast\Jobs\FixedClock;
use LumeWeb\Cast\Jobs\IdentityGateway;
use LumeWeb\Cast\Jobs\InMemoryIdentityGateway;
use LumeWeb\Cast\Jobs\InMemoryPublishModeStore;
use LumeWeb\Cast\Jobs\InMemoryScheduler;
use LumeWeb\Cast\Jobs\PublishIdentity;
use LumeWeb\Cast\Jobs\PublishMode;
use LumeWeb\Cast\Jobs\PublishModeStore;
use LumeWeb\Cast\Jobs\WordPressIdentityGateway;
use LumeWeb\Cast\Onboarding\Wizard;
use LumeWeb\Cast\Onboarding\WizardState;
use LumeWeb\Cast\Publish\PublishRegistry;
use LumeWeb\Cast\Publish\WordPressPublishRegistry;
use LumeWeb\Cast\Tests\Unit\Onboarding\FakeWizardStore;
use LumeWeb\Cast\Tests\Unit\Persistence\FakeOptionGateway;
use LumeWeb\Cast\Tests\Unit\Publish\FakePublishRegistry;
use PHPUnit\Framework\TestCase;

final class PublishSetupServiceTest extends TestCase
{
    private FixedClock $clock;

    private InMemoryRunRepository $repository;

    private InMemoryScheduler $scheduler;

    private InMemoryIdentityGateway $identity;

    private FakeWizardStore $wizardStore;

    private FakePublishedContentProbe $content;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(1_700_000_000);
        $this->repository = new InMemoryRunRepository();
        $this->scheduler = new InMemoryScheduler();
        $this->identity = new InMemoryIdentityGateway();
        $this->wizardStore = new FakeWizardStore(new Wizard(state: WizardState::Completed));
        $this->content = new FakePublishedContentProbe(true);
    }

    /**
     * @param array<string, string|false> $vars
     */
    private function env(array $vars): EnvIdentity
    {
        $reader = new class ($vars) implements EnvReader {
            /** @var array<string, string|false> */
            private array $vars;

            /**
             * @param array<string, string|false> $vars
             */
            public function __construct(array $vars)
            {
                $this->vars = $vars;
            }

            public function get(string $name): string|false
            {
                return $this->vars[$name] ?? false;
            }
        };

        return new EnvIdentity($reader);
    }

    private function completeEnv(): EnvIdentity
    {
        return $this->env([
            EnvIdentity::PORTAL_API_URL => 'https://account.example.test',
            EnvIdentity::PORTAL_API_KEY => 'api-key',
        ]);
    }

    /**
     * The service and its scheduler share one mode store, mirroring the
     * production CastPlugin composition.
     */
    private function service(
        ?EnvIdentity $env = null,
        ?PublishModeStore $modeStore = null,
        ?ConnectionResolver $connection = null,
        ?WorkItemStateProvider $workItems = null,
        ?WebsiteRegistry $websites = null,
        ?PublishRegistry $registry = null,
        ?IpnsResolver $ipns = null,
        ?WorkspaceLinker $workspaces = null,
        ?IdentityGateway $identity = null,
    ): PublishSetupService {
        $modeStore ??= new InMemoryPublishModeStore();
        $identity ??= $this->identity;

        return new PublishSetupService(
            env: $env ?? $this->completeEnv(),
            wizardStore: $this->wizardStore,
            content: $this->content,
            repository: $this->repository,
            identity: $identity,
            contentScheduler: new ContentPublishScheduler(
                clock: $this->clock,
                repository: $this->repository,
                scheduler: $this->scheduler,
                identity: $identity,
                modeStore: $modeStore,
                workItems: new InMemoryWorkItemRepository(),
            ),
            modeStore: $modeStore,
            clock: $this->clock,
            connection: $connection,
            workItems: $workItems,
            websites: $websites,
            registry: $registry,
            workspaces: $workspaces,
            ipns: $ipns,
        );
    }

    /**
     * A live {@see WorkItemStateProvider} over a fake item table with the
     * given terminal tallies, keyed by status value (e.g. ['done' => 110,
     * 'failed' => 5, 'skipped' => 5]). Each row is inserted as queued and
     * flipped to its requested status — mirroring the real table once capture/
     * rewrite processed it — so countByStatus reflects exactly the requested
     * vocabulary.
     *
     * @param array<string, int> $tallies
     */
    private function workItems(array $tallies): WorkItemStateProvider
    {
        $repo = new InMemoryWorkItemRepository();
        $factory = new WorkItemFactory();
        $n = 0;

        foreach ($tallies as $status => $count) {
            for ($i = 0; $i < $count; ++$i) {
                $item = $factory->fromString(sprintf('https://example.com/item-%s-%d', $status, $n++));
                $repo->insertCanonical($item);
                $repo->transition($item->urlHash(), WorkItemStatus::from($status));
            }
        }

        return new RepositoryWorkItemStateProvider($repo);
    }

    private function resolvedConnectionResolver(): ConnectionResolver
    {
        return new class () implements ConnectionResolver {
            public function current(): SelfIdentification
            {
                $identity = new PortalIdentity('https://account.example.test', 'account-key');
                $account = Account::fromArray([
                    'id' => 7,
                    'email' => 'ada@example.test',
                    'first_name' => 'Ada',
                    'last_name' => 'Lovelace',
                    'verified' => true,
                ]);
                $workspace = Workspace::fromArray([
                    'id' => 11,
                    'label' => 'main',
                    'domain' => 'main.example.test',
                    'status' => 'active',
                    'created' => '2026-01-01T00:00:00Z',
                    'updated' => '2026-01-02T00:00:00Z',
                ]);

                return SelfIdentification::resolved($identity, $account, $workspace);
            }
        };
    }

    private function readyIdentity(): PublishIdentity
    {
        return new PublishIdentity('web-42', 'My Blog', 'k-ipns-7', 'cast-live', true);
    }

    /**
     * The persisted website name in the shared option store, or null when the
     * record is absent/corrupt. Pins that the refresh-path backfill wrote the
     * real domain through to the same option the identity gateway reads.
     */
    private function storedWebsiteName(FakeOptionGateway $gateway): ?string
    {
        $value = $gateway->options[WordPressPublishRegistry::OPTION_KEY] ?? null;
        if (!is_array($value) || !is_array($value['website'] ?? null)) {
            return null;
        }

        return isset($value['website']['name']) && is_string($value['website']['name'])
            ? $value['website']['name']
            : null;
    }

    /* ------------------------------ status ------------------------------ */

    public function testStatusReportsCompleteBootstrapIdentityState(): void
    {
        $status = $this->service()->status();

        self::assertTrue($status->bootstrapIdentityComplete);
        self::assertSame([], $status->envProblems);
    }

    public function testStatusReportsMissingBootstrapIdentityWithSafeProblems(): void
    {
        $status = $this->service($this->env([EnvIdentity::PORTAL_API_URL => 'https://account.example.test']))
            ->status();

        self::assertFalse($status->bootstrapIdentityComplete);
        self::assertNotEmpty($status->envProblems);

        // The problem message names the variable but never the read value.
        $json = (string) json_encode($status->toArray());
        self::assertStringNotContainsString('https://account.example.test', $json);
    }

    public function testStatusReportsOnboardingTerminalState(): void
    {
        self::assertTrue($this->service()->status()->onboardingComplete);

        $this->wizardStore->stored = new Wizard(state: WizardState::Building);
        self::assertFalse($this->service()->status()->onboardingComplete);
    }

    public function testStatusReportsPublishableContentReadiness(): void
    {
        self::assertTrue($this->service()->status()->hasEligibleContent);

        $this->content->eligible = false;
        self::assertFalse($this->service()->status()->hasEligibleContent);
    }

    public function testStatusReportsModeAndAutoActive(): void
    {
        // Manual is the default: never auto-publishes.
        $status = $this->service()->status();

        self::assertSame(PublishMode::Manual, $status->mode);
        self::assertFalse($status->autoActive);

        // An opted-in automatic mode is reported with its auto flag.
        $optedIn = $this->service(null, new InMemoryPublishModeStore(PublishMode::OnUpdate))->status();

        self::assertSame(PublishMode::OnUpdate, $optedIn->mode);
        self::assertTrue($optedIn->autoActive);
        self::assertSame('on_update', $optedIn->toArray()['mode']);
        self::assertTrue($optedIn->toArray()['auto_active']);
    }

    public function testStatusReportsCurrentRunStatusStageAndProgressCount(): void
    {
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->progressCount = 42;
        $this->repository->save($run);

        $status = $this->service()->status();

        self::assertSame(RunStatus::Running, $status->runStatus);
        self::assertSame(RunStage::Idle, $status->runStage);
        self::assertSame(42, $status->progressCount);
        self::assertTrue($status->runActive);
    }

    public function testStatusReportsQueuedAtWhileARunIsWaitingAndNullOtherwise(): void
    {
        // A pending (NotStarted) run carries its queue-entry time so the UI can
        // show how long a publish has been waiting instead of a misleading
        // item count. The running would-be status has no waiting time.
        $pending = ExportRun::create('run-1', new RunSettings(), at: 1234);
        $pending->dirty = true;
        $this->repository->save($pending);

        $status = $this->service()->status();
        self::assertSame(RunStatus::NotStarted, $status->runStatus);
        self::assertSame(1234, $status->queuedAt);
        self::assertSame(1234, $status->toArray()['queued_at']);

        // Once the run starts, there is nothing queued to time.
        $pending->start(at: 1300);
        $this->repository->save($pending);
        self::assertNull($this->service()->status()->queuedAt);

        // No run present at all: no queued time either.
        $this->repository->delete('run-1');
        $top = $this->service()->status();
        self::assertNull($top->queuedAt);
        self::assertArrayHasKey('queued_at', $top->toArray());
    }

    public function testStatusMapsProgressTotalAndPipelineStageFromTheRunCursor(): void
    {
        // Once discovery drains, its enqueued count is the honest denominator
        // for the whole pipeline; the fine stage comes from the cursor prefix.
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->resumeCursor = 'capture|';
        $run->discover = new DiscoverResult(120);
        $this->repository->save($run);

        $status = $this->service()->status();

        self::assertSame('capture', $status->pipelineStage);
        self::assertSame(120, $status->progressTotal);
        self::assertSame('capture', $status->toArray()['pipeline_stage']);
        self::assertSame(120, $status->toArray()['progress_total']);
    }

    public function testStatusMapsNullPipelineFieldsBeforeDiscoveryOrWithoutARun(): void
    {
        // A run that has not pinned a cursor yet (freshly started) has no fine
        // stage; before discovery drains there is no total. Both surface as
        // null, not a fabricated value.
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $this->repository->save($run);

        $status = $this->service()->status();
        self::assertNull($status->pipelineStage);
        self::assertNull($status->progressTotal);
        self::assertNull($status->toArray()['pipeline_stage']);
        self::assertNull($status->toArray()['progress_total']);

        $this->repository->delete('run-1');
        $top = $this->service()->status();
        self::assertNull($top->pipelineStage);
        self::assertNull($top->progressTotal);
    }

    public function testStatusMapsTheTerminalCaptureAndRewriteNumerators(): void
    {
        // The stage summaries only land at each stage's fixed point; when they
        // exist the status carries the summed numerators the x-of-M copy reads.
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->resumeCursor = 'pack|';
        $run->discover = new DiscoverResult(120);
        $run->capture = new CaptureSummary(done: 110, failed: 5, skipped: 5);
        $run->rewrite = new RewriteSummary(rewritten: 90, passedThrough: 20);
        $this->repository->save($run);

        $status = $this->service()->status();

        // captureDone = done + failed + skipped = 110 + 5 + 5 = 120.
        self::assertSame(120, $status->captureDone);
        self::assertSame(120, $status->toArray()['capture_done']);
        // rewriteDone = rewritten + passedThrough = 90 + 20 = 110.
        self::assertSame(110, $status->rewriteDone);
        self::assertSame(110, $status->toArray()['rewrite_done']);

        // While capture/rewrite run their summaries are absent and — with NO
        // live state provider wired into this service — the in-flight fields
        // stay null so the copy falls back to the stage verb, never a
        // fabricated count. The live state provider (covered below) is what fills them.
        $run->resumeCursor = 'capture|';
        $run->capture = null;
        $run->rewrite = null;
        $this->repository->save($run);

        $running = $this->service()->status();
        self::assertNull($running->captureDone);
        self::assertNull($running->rewriteDone);
    }

    public function testStatusMapsLiveCaptureNumeratorFromTheItemTableWhileCaptureRuns(): void
    {
        // No persisted capture summary exists while capture is in flight, but
        // the shared item table carries the authoritative per-row statuses: the
        // live numerator is every row capture terminally recorded so far (done
        // + failed + skipped), so "Captured X of M URLs" moves every tick.
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->resumeCursor = 'capture|';
        $run->discover = new DiscoverResult(120);
        $this->repository->save($run);

        $status = $this->service(workItems: $this->workItems([
            'done' => 110,
            'failed' => 5,
            'skipped' => 5,
        ]))->status();

        self::assertSame(120, $status->captureDone);
        self::assertSame(120, $status->toArray()['capture_done']);

        // A live zero is still a number, never null: the copy renders the
        // honest "Captured 0 of M URLs" instead of freezing on the stage verb.
        $noneProcessed = $this->service(workItems: $this->workItems([]))->status();
        self::assertSame(0, $noneProcessed->captureDone);
        self::assertSame(0, $noneProcessed->toArray()['capture_done']);
    }

    public function testStatusMapsLiveRewriteNumeratorFromTheItemTableWhileRewriteRuns(): void
    {
        // Capture finished (its persisted summary is the immutable capture
        // numerator) while rewrite is in flight: the only per-row mark rewrite
        // leaves as it goes is the Rewritten flip, so the live rewrite count is
        // exactly those rows — done pass-through rows cannot be told apart from
        // not-yet-rewritten ones and are deliberately not pre-counted.
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->resumeCursor = 'rewrite|';
        $run->discover = new DiscoverResult(120);
        $run->capture = new CaptureSummary(done: 118, failed: 1, skipped: 1);
        $this->repository->save($run);

        $status = $this->service(workItems: $this->workItems([
            'done' => 110,
            'rewritten' => 8,
        ]))->status();

        // captureDone prefers the persisted summary over the live table (once
        // rewrite flipped rows, the live table can no longer distinguish
        // capture terminals from rewrite pass-throughs).
        self::assertSame(120, $status->captureDone);
        // rewriteDone is live: exactly the rows rewritten so far.
        self::assertSame(8, $status->rewriteDone);
        self::assertSame(8, $status->toArray()['rewrite_done']);

        // No rewritten rows yet is an honest live 0, not null.
        $noneRewritten = $this->service(workItems: $this->workItems(['done' => 118]))->status();
        self::assertSame(0, $noneRewritten->rewriteDone);
    }

    public function testStatusIncludesCollectedAssetsInTheDenominatorWhileRewriteRuns(): void
    {
        // The denominator is the run's FULL work, not the discover-only list:
        // discover 15 + the 2 assets the rewrite cursor has collected = 17.
        // Before the fix, asset reconciliation could rewrite past the discover
        // total and surface a phoney "Rewrote 17 of 15"; now the total grows
        // with every collected asset (the cursor count) so x can never exceed M.
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->resumeCursor = 'rewrite|2';
        $run->discover = new DiscoverResult(15);
        $this->repository->save($run);

        $status = $this->service(workItems: $this->workItems([
            'rewritten' => 17,
        ]))->status();

        self::assertSame('rewrite', $status->pipelineStage);
        self::assertSame(17, $status->progressTotal);
        self::assertSame(17, $status->toArray()['progress_total']);
        // The live numerator (every rewritten row, pages + reconciled assets)
        // may legitimately reach the full-work total — never exceed it.
        self::assertSame(17, $status->rewriteDone);
        self::assertLessThanOrEqual($status->progressTotal, $status->rewriteDone);
    }

    public function testStatusIncludesCollectedAssetsInTheDenominatorFromTheRewriteSummary(): void
    {
        // Once rewrite drains, the cursor advances to pack and no longer
        // carries the collected count — the summary pins it (assetsCollected),
        // so the total still reflects the collected half of the work for the
        // "Building the export (M URLs)" copy and any later x-of-M render.
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->resumeCursor = 'pack|';
        $run->discover = new DiscoverResult(15);
        $run->rewrite = new RewriteSummary(rewritten: 15, passedThrough: 2, assetsCollected: 2);
        $this->repository->save($run);

        $status = $this->service()->status();

        self::assertSame('pack', $status->pipelineStage);
        self::assertSame(17, $status->progressTotal);
        self::assertSame(17, $status->toArray()['progress_total']);
        // The terminal rewrite numerator reflects the collected rows too and
        // stays within the full-work denominator (impossible-state pin).
        self::assertSame(17, $status->rewriteDone);
        self::assertLessThanOrEqual($status->progressTotal, $status->rewriteDone);
    }

    public function testStatusDenominatorStaysAtDiscoverTotalDuringCapture(): void
    {
        // Rewrite has not run yet (it only starts after capture drains), so its
        // cursor carries no collected count: the denominator is exactly the
        // discover total, and the capture numerator (live done+failed+skipped,
        // or the terminal summary) can never exceed it.
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->resumeCursor = 'capture|';
        $run->discover = new DiscoverResult(15);
        $this->repository->save($run);

        $status = $this->service(workItems: $this->workItems([
            'done' => 15,
            'failed' => 0,
            'skipped' => 0,
        ]))->status();

        self::assertSame(15, $status->progressTotal);
        self::assertSame(15, $status->captureDone);
        self::assertLessThanOrEqual($status->progressTotal, $status->captureDone);
    }

    public function testStatusDenominatorIgnoresNonRewriteCursorPayloads(): void
    {
        // The collected count is a REWRITE cursor concept: a digit payload on
        // another stage (here capture) must not be mistaken for collected
        // assets, keeping the denominator honest even for a freshly transitioned
        // run whose next-stage cursor is empty.
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->resumeCursor = 'capture|';
        $run->discover = new DiscoverResult(15);
        $this->repository->save($run);

        $status = $this->service()->status();
        self::assertSame(15, $status->progressTotal);

        // The same guard holds for an empty rewrite cursor (nothing collected).
        $run->resumeCursor = 'rewrite|';
        $this->repository->save($run);
        self::assertSame(15, $this->service()->status()->progressTotal);
    }

    public function testStatusPrefersPersistedStageSummariesOverLiveCounts(): void
    {
        // Once both summaries exist they are immutable terminal truth; the live
        // table read must never override them, even when it would disagree.
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->resumeCursor = 'pack|';
        $run->discover = new DiscoverResult(120);
        $run->capture = new CaptureSummary(done: 110, failed: 5, skipped: 5);
        $run->rewrite = new RewriteSummary(rewritten: 90, passedThrough: 20);
        $this->repository->save($run);

        $status = $this->service(workItems: $this->workItems([
            'done' => 1,
            'rewritten' => 0,
        ]))->status();

        self::assertSame(120, $status->captureDone);
        self::assertSame(110, $status->rewriteDone);
    }

    public function testStatusKeepsLiveNumeratorsNullWithoutAStateProviderOrOnOtherStages(): void
    {
        // A run mid-capture with NO live state provider wired must not fabricate a count:
        // the fields stay null so the copy keeps the stage verb.
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->resumeCursor = 'capture|';
        $run->discover = new DiscoverResult(120);
        $this->repository->save($run);

        $unwired = $this->service()->status();
        self::assertNull($unwired->captureDone);
        self::assertNull($unwired->rewriteDone);

        // A wired state provider only fills the stage it is actually reading: on a
        // discover cursor there is no in-flight capture/rewrite to count.
        $run->resumeCursor = 'discover|';
        $this->repository->save($run);
        $wiredElsewhere = $this->service(workItems: $this->workItems(['done' => 100]))->status();
        self::assertNull($wiredElsewhere->captureDone);
        self::assertNull($wiredElsewhere->rewriteDone);
    }

    public function testStatusMapsNullProgressTotalForAPublishOnlyRerun(): void
    {
        // A publish-only re-run resumes at 'publish|' with no discover total:
        // the UI must know there is no denominator so it says "republishing".
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->resumeCursor = ExportRun::RESUME_PUBLISH_ONLY;
        $this->repository->save($run);

        $status = $this->service()->status();

        self::assertSame('publish', $status->pipelineStage);
        self::assertNull($status->progressTotal);
    }

    public function testStatusReportsWebsiteAndIpnsIdentityWhenPresent(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $status = $this->service()->status();

        $identity = $status->identity;
        self::assertNotNull($identity);
        self::assertSame('web-42', $identity->websiteId);
        self::assertSame('My Blog', $identity->websiteName);
        self::assertSame('k-ipns-7', $identity->ipnsKeyId);
        self::assertSame('cast-live', $identity->ipnsKeyName);
    }

    public function testStatusBackfillsAnEmptyWebsiteNameFromTheRegistryOnRefresh(): void
    {
        // The refresh-path root cause: a guided auto-generate whose create
        // response carried no minted domain left the persisted identity with
        // an EMPTY website name. The stored option still holds a structurally
        // complete pair (website id + IPNS key), so the identity gateway alone
        // returns null (PublishIdentity refuses empty names) — and the old
        // status() read it unchanged, showing no connected website at all.
        // Production couples the registry and identity gateway over ONE option;
        // this test wires the real WordPress adapters over a shared store.
        $gateway = new FakeOptionGateway();
        $registry = new WordPressPublishRegistry($gateway);
        $registry->recordWebsite('42', '');
        $registry->recordIpnsKey('cast-live', '9');
        $identity = new WordPressIdentityGateway($gateway);

        // GET /api/websites knows the minted platform domain for website 42.
        $websites = new FakeWebsiteList([
            Website::fromArray([
                'id' => 42,
                'status' => 'created',
                'domain' => 'my-blog.pinned.site',
                'target_hash' => 'k51-bafy-parked',
                'target_type' => 'ipns',
            ]),
        ]);

        $status = $this->service(identity: $identity, registry: $registry, websites: $websites)->status();

        // status hydrates BOTH id and name even though the link was recorded
        // in an earlier request with an empty stored name.
        $hydrated = $status->identity;
        self::assertNotNull($hydrated);
        self::assertSame('42', $hydrated->websiteId);
        self::assertSame('my-blog.pinned.site', $hydrated->websiteName);

        // The write-through backfilled the real domain into the shared store,
        // so the next poll reads a full identity without another round-trip.
        self::assertSame('my-blog.pinned.site', $registry->current() !== null
            ? $this->storedWebsiteName($gateway)
            : null);
    }

    public function testStatusKeepAReadyStatusFromConsultingTheRegistryList(): void
    {
        // Cheapness pin: an ordinary status() with an already-named identity
        // must NEVER read the account website list — the backfill is strictly
        // for the empty-name case.
        $this->identity->setCurrentIdentity($this->readyIdentity());
        $websites = new FakeWebsiteList();
        $status = $this->service(websites: $websites)->status();

        self::assertNotNull($status->identity);
        self::assertSame(0, $websites->calls, 'a ready identity never consults the account registry');
    }

    public function testCreateWebsiteHydratesTheMintedDomainWhenTheCreateResponseOmitsIt(): void
    {
        // Auto-generate: the create response's domain is empty (Pinner mints
        // the platform subdomain asynchronously), but the account list knows
        // the real domain for the minted website. The write-through must
        // backfill the domain by id (cheap — only the empty branch), never
        // persist a bare id or an empty name for the guided create.
        $this->parkedResumableRun('bafy-parked-cid', 'k51-ipns-parked');
        $websites = new FakeWebsiteList([
            Website::fromArray([
                'id' => 99,
                'status' => 'created',
                'domain' => 'auto-gen.pinned.site',
                'target_hash' => 'k51-ipns-parked',
                'target_type' => 'ipns',
            ]),
        ]);
        $registry = new FakePublishRegistry();

        $result = $this->service(websites: $websites, registry: $registry)->createWebsite('');

        self::assertTrue($result->created);
        self::assertSame('99', $result->websiteId);
        self::assertSame('auto-gen.pinned.site', $result->websiteName);
    }

    public function testStatusConnectionIsNullWithoutResolver(): void
    {
        self::assertNull($this->service()->status()->connection);
    }

    public function testStatusCarriesLazilyResolvedConnectionWhenResolverProvided(): void
    {
        $status = $this->service(null, null, $this->resolvedConnectionResolver())->status();

        $connection = $status->connection;
        self::assertNotNull($connection);
        self::assertSame('resolved', $connection->state());
        self::assertSame('Ada Lovelace', $connection->accountName());
        self::assertSame('ada@example.test', $connection->accountEmail());
        self::assertSame('main', $connection->workspaceLabel());
        self::assertSame('main.example.test', $connection->workspaceDomain());
    }

    public function testStatusSerializesResolvedConnectionAdditivelyWithoutCredentials(): void
    {
        $array = $this->service(null, null, $this->resolvedConnectionResolver())->status()->toArray();

        self::assertArrayHasKey('connection', $array);
        self::assertSame('resolved', $array['connection']['state']);
        self::assertSame('main.example.test', $array['connection']['workspace_domain']);

        $json = (string) json_encode($array);
        self::assertStringNotContainsString('account-key', $json);
        // The status serialization must never carry the workspace access pair
        // either — only identifiers.
        self::assertArrayNotHasKey('username', $array['connection']);
        self::assertArrayNotHasKey('password', $array['connection']);
    }

    public function testStatusReportsDriftAndDirtyState(): void
    {
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->notifyContentChanged(at: 1000);
        $this->repository->save($run);

        $status = $this->service()->status();

        self::assertTrue($status->dirty);
        self::assertTrue($status->superseded);
    }

    public function testStatusReportsCidWhenAPublishAlreadyRecordedIdentifiers(): void
    {
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->recordPublishIdentifiers('bafy-test-cid', 'web-1', 'k-1', at: 1000);
        $this->repository->save($run);

        $status = $this->service()->status();

        self::assertSame('bafy-test-cid', $status->publishCid);
    }

    public function testStatusReportsActionableLastErrorWhenRunFailed(): void
    {
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->fail('The IPFS upload timed out', at: 1000);
        $this->repository->save($run);

        $status = $this->service()->status();

        self::assertSame(RunStatus::Failed, $status->runStatus);
        self::assertSame('The IPFS upload timed out', $status->lastError);
        self::assertContains('The IPFS upload timed out', $status->toArray()['errors']);
    }

    public function testStatusDtoNeverLeaksCredentials(): void
    {
        $env = $this->env([
            EnvIdentity::PORTAL_API_URL => 'https://account.example.test',
            EnvIdentity::PORTAL_API_KEY => 'super-secret-account-key',
            EnvIdentity::WORKSPACE_AUTH_USERNAME => 'operator',
            EnvIdentity::WORKSPACE_AUTH_PASSWORD => 'workspace-pass',
            EnvIdentity::PORTAL_WORKSPACE_URL => 'https://cast.example.test',
        ]);
        $this->identity->setCurrentIdentity($this->readyIdentity());

        $json = (string) json_encode($this->service($env)->status()->toArray());

        self::assertStringNotContainsString('super-secret-account-key', $json);
        self::assertStringNotContainsString('workspace-pass', $json);
        self::assertStringNotContainsString('operator', $json);
        self::assertStringNotContainsString('https://cast.example.test', $json);
    }

    /* ------------------------- first publish start ------------------------- */

    public function testStartRefusesWhenEnvIdentityMissing(): void
    {
        $result = $this->service($this->env([EnvIdentity::PORTAL_API_URL => 'https://account.example.test']))
            ->startFirstPublish();

        self::assertFalse($result->queued);
        self::assertSame(PublishStartRefusal::EnvIdentityMissing, $result->refusal);
        self::assertNull($this->repository->latest());
        self::assertSame(0, $this->scheduler->count());
    }

    public function testStartRefusesWhenOnboardingNotTerminal(): void
    {
        $this->wizardStore->stored = new Wizard(state: WizardState::Building);

        $result = $this->service()->startFirstPublish();

        self::assertFalse($result->queued);
        self::assertSame(PublishStartRefusal::OnboardingNotComplete, $result->refusal);
        self::assertNull($this->repository->latest());
        self::assertSame(0, $this->scheduler->count());
    }

    public function testStartRefusesWhenNoEligibleContent(): void
    {
        $this->content->eligible = false;

        $result = $this->service()->startFirstPublish();

        self::assertFalse($result->queued);
        self::assertSame(PublishStartRefusal::NoEligibleContent, $result->refusal);
        self::assertNull($this->repository->latest());
        self::assertSame(0, $this->scheduler->count());
    }

    public function testStartRefusesWhenARunIsActive(): void
    {
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $this->repository->save($run);

        $result = $this->service()->startFirstPublish();

        self::assertFalse($result->queued);
        self::assertSame(PublishStartRefusal::RunActive, $result->refusal);
        self::assertSame('run-1', $this->repository->latest()?->runId);
        self::assertSame(0, $this->scheduler->count());
    }

    public function testStartAbsorbsWhileAPendingRunExists(): void
    {
        // A queued NotStarted dirty run is the pending first-publish work: it
        // is absorbed and ticked now rather than refused or stacked.
        $pending = ExportRun::create('run-pending', new RunSettings(), at: 1000);
        $pending->markDirty(at: 1000);
        $this->repository->save($pending);

        $result = $this->service()->startFirstPublish();

        self::assertTrue($result->queued);
        self::assertSame('run-pending', $result->runId);
        self::assertNull($result->refusal);
        // The pending run is the only stored run and ticks immediately.
        self::assertSame(1, count($this->repository->list()));
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
        self::assertSame($this->clock->now(), $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testStartRetriesOverATerminalFailedFirstPublish(): void
    {
        // The first-publish deadlock regression: a terminal failed record with
        // no identity must be RESTARTABLE. startFirstPublish() replaces the
        // terminal slot with a fresh pending dirty run and ticks it now.
        $run = ExportRun::create('run-failed', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->fail('The upload timed out', at: 1000);
        $this->repository->save($run);

        $result = $this->service()->startFirstPublish();

        self::assertTrue($result->queued);
        self::assertNull($result->refusal);
        self::assertNotNull($result->runId);
        self::assertNotSame('run-failed', $result->runId);
        // The terminal slot is replaced by exactly one fresh pending run.
        self::assertCount(1, $this->repository->list());
        $latest = $this->repository->latest();
        self::assertNotNull($latest);
        self::assertSame(RunStatus::NotStarted, $latest->status);
        self::assertTrue($latest->dirty);
        // Exactly one immediate auto-tick is scheduled.
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
        self::assertSame($this->clock->now(), $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testStartRetriesOverATerminalCancelledFirstPublish(): void
    {
        // Same restart guarantee for a cancelled first publish (e.g. the
        // operator cancelled a queued run before it ever produced a CID).
        $run = ExportRun::create('run-cancelled', new RunSettings(), at: 1000);
        $run->markDirty(at: 1000);
        $run->cancel(at: 1000);
        $this->repository->save($run);

        $result = $this->service()->startFirstPublish();

        self::assertTrue($result->queued);
        self::assertNull($result->refusal);
        self::assertNotSame('run-cancelled', $result->runId);
        self::assertCount(1, $this->repository->list());
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testStartRetryIsBlockedWhileAPreviousRunIsLive(): void
    {
        // Regression: the terminal-restart path must never mask a live run —
        // an active (non-terminal) first-publish run is still refused.
        $run = ExportRun::create('run-active', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $this->repository->save($run);

        $result = $this->service()->startFirstPublish();

        self::assertFalse($result->queued);
        self::assertSame(PublishStartRefusal::RunActive, $result->refusal);
        self::assertSame('run-active', $this->repository->latest()?->runId);
        self::assertSame(0, $this->scheduler->count());
    }

    public function testStartSnapshotsSettingsCreatesRunAndSchedulesOneTick(): void
    {
        $result = $this->service()->startFirstPublish();

        self::assertTrue($result->queued);
        self::assertNotNull($result->runId);
        self::assertNull($result->refusal);

        $run = $this->repository->latest();
        self::assertNotNull($run);
        self::assertSame($result->runId, $run->runId);
        self::assertSame(RunStatus::NotStarted, $run->status);
        self::assertTrue($run->dirty);
        // The settings snapshot is immutable; a fresh run defaults to the
        // ipns mode.
        self::assertSame('ipns', $run->settingsSnapshot()['target_type']);

        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
        self::assertSame($this->clock->now(), $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testStartReturnsQueuedDto(): void
    {
        $payload = $this->service()->startFirstPublish()->toArray();

        self::assertTrue($payload['queued']);
        self::assertSame('queued', $payload['status']);
        self::assertArrayHasKey('run_id', $payload);
        self::assertNotNull($payload['run_id']);
    }

    public function testStartNeverCreatesAWebsiteOrMutatesIdentity(): void
    {
        $before = $this->identity->current();
        $result = $this->service()->startFirstPublish();

        self::assertTrue($result->queued);
        // Only a fresh run + one tick: no website/identity write anywhere.
        self::assertSame($before, $this->identity->current());
        self::assertNotNull($this->repository->latest());
        self::assertSame(1, $this->scheduler->count());
    }

    public function testDirtyTransitionNeverStartsAJobInline(): void
    {
        // Content became dirty (a pending run) through the public-content
        // transition path; reading status must never start the run or schedule
        // a tick. First publish stays the only explicit starter.
        $run = ExportRun::create('run-pending', new RunSettings(), at: 1000);
        $run->markDirty(at: 1000);
        $this->repository->save($run);

        $this->service()->status();

        $latest = $this->repository->latest();
        self::assertNotNull($latest);
        self::assertSame(RunStatus::NotStarted, $latest->status);
        self::assertSame(0, $this->scheduler->count());
    }

    /* ------------------------------- mode ------------------------------- */

    public function testModeReadsTheCurrentPersistedMode(): void
    {
        $service = $this->service();

        // Manual is the default before any mode was ever chosen.
        self::assertSame(PublishMode::Manual, $service->mode());

        $service->setMode('on_update');

        self::assertSame(PublishMode::OnUpdate, $service->mode());
    }

    public function testSetModePersistsAKnownMode(): void
    {
        $service = $this->service();

        $result = $service->setMode('on_update');

        self::assertTrue($result->updated);
        self::assertSame(PublishMode::OnUpdate, $result->mode);
        self::assertSame(PublishMode::OnUpdate, $service->mode());
        self::assertSame('updated', $result->toArray()['status']);
        self::assertTrue($result->toArray()['auto_active']);
    }

    public function testSetModeRefusesAnUnknownModeAndLeavesCurrentUntouched(): void
    {
        $service = $this->service();

        $result = $service->setMode('automatic');

        self::assertFalse($result->updated);
        self::assertSame(PublishModeRefusal::InvalidMode, $result->refusal);
        self::assertSame('refused', $result->toArray()['status']);
        self::assertSame('invalid_mode', $result->toArray()['refusal']);
        self::assertSame(PublishMode::Manual, $service->mode());
    }

    /* --------------------------- publish now ---------------------------- */

    public function testPublishNowQueuesAFreshDirtyRunScheduledImmediately(): void
    {
        $result = $this->service()->startPublishNow();

        self::assertTrue($result->queued);
        self::assertNotNull($result->runId);
        self::assertNull($result->refusal);

        $run = $this->repository->latest();
        self::assertNotNull($run);
        self::assertSame($result->runId, $run->runId);
        self::assertSame(RunStatus::NotStarted, $run->status);
        self::assertTrue($run->dirty);

        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
        self::assertSame($this->clock->now(), $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testPublishNowAbsorbsAPendingRun(): void
    {
        // A queued NotStarted run is the work: publish-now absorbs and ticks it.
        $pending = ExportRun::create('run-pending', new RunSettings(), at: 1000);
        $this->repository->save($pending);

        $result = $this->service()->startPublishNow();

        self::assertTrue($result->queued);
        self::assertSame('run-pending', $result->runId);
        self::assertSame(1, count($this->repository->list()));
        self::assertSame(1, $this->scheduler->count());
        self::assertSame($this->clock->now(), $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testPublishNowRefusesAnActivelyExecutingRun(): void
    {
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $this->repository->save($run);

        $result = $this->service()->startPublishNow();

        self::assertFalse($result->queued);
        self::assertSame(PublishStartRefusal::RunActive, $result->refusal);
        self::assertSame('run-1', $this->repository->latest()?->runId);
        self::assertSame(0, $this->scheduler->count());
    }

    public function testPublishNowRefusesOnBrokenEnvironment(): void
    {
        $result = $this->service($this->env([EnvIdentity::PORTAL_API_URL => 'https://account.example.test']))
            ->startPublishNow();

        self::assertFalse($result->queued);
        self::assertSame(PublishStartRefusal::EnvIdentityMissing, $result->refusal);
        self::assertNull($this->repository->latest());
        self::assertSame(0, $this->scheduler->count());
    }

    public function testPublishNowRefusesWhenOnboardingNotTerminal(): void
    {
        $this->wizardStore->stored = new Wizard(state: WizardState::Building);

        $result = $this->service()->startPublishNow();

        self::assertFalse($result->queued);
        self::assertSame(PublishStartRefusal::OnboardingNotComplete, $result->refusal);
        self::assertSame(0, $this->scheduler->count());
    }

    public function testPublishNowWorksWithoutEligibleContentDiscovered(): void
    {
        // The "publish now" button is always present; it is a manual push of
        // current state, not a first-publish eligibility check.
        $this->content->eligible = false;

        $result = $this->service()->startPublishNow();

        self::assertTrue($result->queued);
        self::assertNotNull($result->runId);
    }

    /* ----------------------------- cancel run ----------------------------- */

    public function testCancelCancelsARunningRunAndClearsPendingAutoWork(): void
    {
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $this->repository->save($run);

        // A quiet-period debounce and a coalesced follow-up are both queued.
        $this->scheduler->scheduleSingle(ContentPublishScheduler::AUTO_HOOK, 3600);
        $this->scheduler->scheduleSingle(ContentPublishScheduler::FOLLOW_UP_HOOK, 3605);

        $result = $this->service()->cancelRun();

        self::assertTrue($result->cancelled);
        self::assertSame('run-1', $result->runId);
        self::assertNull($result->refusal);

        $cancelled = $this->repository->find('run-1');
        self::assertNotNull($cancelled);
        self::assertSame(RunStatus::Cancelled, $cancelled->status);
        self::assertSame(RunStage::Finished, $cancelled->stage);

        // Every pending publish event for the cancelled run is cleared.
        self::assertSame(0, $this->scheduler->count());
        self::assertFalse($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
        self::assertFalse($this->scheduler->isScheduled(ContentPublishScheduler::FOLLOW_UP_HOOK));
    }

    public function testCancelCancelsAQueuedNotStartedRunAndClearsItsDebounce(): void
    {
        $pending = ExportRun::create('run-pending', new RunSettings(), at: 1000);
        $pending->markDirty(at: 1000);
        $this->repository->save($pending);
        $this->scheduler->scheduleSingle(ContentPublishScheduler::AUTO_HOOK, 1800);

        $result = $this->service()->cancelRun();

        self::assertTrue($result->cancelled);
        self::assertSame('run-pending', $result->runId);
        self::assertSame(RunStatus::Cancelled, $this->repository->find('run-pending')?->status);
        self::assertSame(0, $this->scheduler->count());
    }

    public function testCancelCancelsAPausedRun(): void
    {
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->pause(at: 1001);
        $this->repository->save($run);

        $result = $this->service()->cancelRun();

        self::assertTrue($result->cancelled);
        self::assertSame('run-1', $result->runId);
        self::assertSame(RunStatus::Cancelled, $this->repository->find('run-1')?->status);
    }

    public function testCancelCancelsASupersededButStillLiveRun(): void
    {
        // An overtaken run may only reach Cancelled; the explicit cancel is
        // the lawful way to stop it (and its follow-up) now.
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->supersede(at: 1001);
        $this->repository->save($run);
        $this->scheduler->scheduleSingle(ContentPublishScheduler::FOLLOW_UP_HOOK, 3605);

        $result = $this->service()->cancelRun();

        self::assertTrue($result->cancelled);
        self::assertSame(RunStatus::Cancelled, $this->repository->find('run-1')?->status);
        self::assertSame(0, $this->scheduler->count());
    }

    public function testCancelRefusesWhenNoRunExistsWithoutSideEffects(): void
    {
        $result = $this->service()->cancelRun();

        self::assertFalse($result->cancelled);
        self::assertSame(PublishCancelRefusal::NoRun, $result->refusal);
        self::assertNull($this->repository->latest());
        self::assertSame(0, $this->scheduler->count());
    }

    public function testCancelRefusesATerminalRunLeavingItAndPendingWorkUntouched(): void
    {
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->complete(at: 1001);
        $this->repository->save($run);
        $this->scheduler->scheduleSingle(ContentPublishScheduler::AUTO_HOOK, 3600);

        $result = $this->service()->cancelRun();

        self::assertFalse($result->cancelled);
        self::assertSame(PublishCancelRefusal::RunTerminal, $result->refusal);
        // A refusal is side-effect free: the finished record is untouched and
        // the queued event is not cleared.
        self::assertSame(RunStatus::Completed, $this->repository->find('run-1')?->status);
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testCancelReturnsJsonSafeDto(): void
    {
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $this->repository->save($run);

        $payload = $this->service()->cancelRun()->toArray();

        self::assertTrue($payload['cancelled']);
        self::assertSame('cancelled', $payload['status']);
        self::assertSame('run-1', $payload['run_id']);
        self::assertNull($payload['refusal']);
    }

    public function testCancelDtoNeverLeaksCredentials(): void
    {
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $this->repository->save($run);

        $env = $this->env([
            EnvIdentity::PORTAL_API_URL => 'https://account.example.test',
            EnvIdentity::PORTAL_API_KEY => 'super-secret-account-key',
            EnvIdentity::WORKSPACE_AUTH_USERNAME => 'operator',
            EnvIdentity::WORKSPACE_AUTH_PASSWORD => 'workspace-pass',
        ]);

        $json = (string) json_encode($this->service($env)->cancelRun()->toArray());

        self::assertStringNotContainsString('super-secret-account-key', $json);
        self::assertStringNotContainsString('workspace-pass', $json);
        self::assertStringNotContainsString('operator', $json);
    }

    /* -------------------------- publish existing -------------------------- */

    /**
     * A terminal run that still owns an intact, packed artifact: the retry
     * source publish-existing reuses ({@see PackResult} zip path + manifest).
     */
    private function terminalPackedRun(): ExportRun
    {
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->recordPack(new PackResult(
            PackStatus::Completed,
            42,
            0,
            12345,
            '/tmp/exports/run-1.zip',
            '/tmp/exports/manifest.json',
        ), at: 1001);
        $run->fail('The IPFS upload timed out', at: 1002);
        $this->repository->save($run);

        return $run;
    }

    public function testPublishExistingRefusesWhenEnvIdentityMissing(): void
    {
        $result = $this->service($this->env([EnvIdentity::PORTAL_API_URL => 'https://account.example.test']))
            ->publishExisting();

        self::assertFalse($result->queued);
        self::assertSame(PublishExistingRefusal::EnvIdentityMissing, $result->refusal);
        self::assertNull($this->repository->latest());
        self::assertSame(0, $this->scheduler->count());
    }

    public function testPublishExistingRefusesWhenOnboardingNotTerminal(): void
    {
        $this->wizardStore->stored = new Wizard(state: WizardState::Building);

        $result = $this->service()->publishExisting();

        self::assertFalse($result->queued);
        self::assertSame(PublishExistingRefusal::OnboardingNotComplete, $result->refusal);
        self::assertSame(0, $this->scheduler->count());
    }

    public function testPublishExistingRefusesWhenNoRunOrArtifactExists(): void
    {
        $result = $this->service()->publishExisting();

        self::assertFalse($result->queued);
        self::assertSame(PublishExistingRefusal::NoArtifact, $result->refusal);
        self::assertNull($this->repository->latest());
        self::assertSame(0, $this->scheduler->count());
    }

    public function testPublishExistingRefusesWhileARunIsActive(): void
    {
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $this->repository->save($run);

        $result = $this->service()->publishExisting();

        self::assertFalse($result->queued);
        self::assertSame(PublishExistingRefusal::RunActive, $result->refusal);
        self::assertSame('run-1', $this->repository->latest()?->runId);
        self::assertSame(0, $this->scheduler->count());
    }

    public function testPublishExistingRefusesATerminalRunWithoutAnIntactArtifact(): void
    {
        // Finished before packing: no artifact to reuse.
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->fail('Export failed before packing', at: 1001);
        $this->repository->save($run);

        $result = $this->service()->publishExisting();

        self::assertFalse($result->queued);
        self::assertSame(PublishExistingRefusal::NoArtifact, $result->refusal);
        self::assertSame(0, $this->scheduler->count());
    }

    public function testPublishExistingRefusesATerminalRunWithAnEmptyArtifactPath(): void
    {
        // A "pack" record whose zip path is empty is not an intact artifact.
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->recordPack(new PackResult(PackStatus::Failed, 0, 0, 0, '', null), at: 1001);
        $run->fail('Gateway refused the pack', at: 1002);
        $this->repository->save($run);

        $result = $this->service()->publishExisting();

        self::assertFalse($result->queued);
        self::assertSame(PublishExistingRefusal::NoArtifact, $result->refusal);
        self::assertSame(0, $this->scheduler->count());
    }

    public function testPublishExistingRefusesWhenNoIdentityExists(): void
    {
        // No website/IPNS identity yet: a re-publish cannot target anything.
        $this->terminalPackedRun();

        $result = $this->service()->publishExisting();

        self::assertFalse($result->queued);
        self::assertSame(PublishExistingRefusal::IdentityMissing, $result->refusal);
        self::assertSame('run-1', $this->repository->latest()?->runId);
        self::assertSame(0, $this->scheduler->count());
    }

    public function testPublishExistingResumesAParkedFirstPublishWithoutIdentity(): void
    {
        // A first publish parked before any website existed has no stored
        // identity (cast_publish_identity is only written once a website
        // exists). Resuming it must seed the publish-only replay of the intact
        // pack — not refuse with IdentityMissing.
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->recordPack(new PackResult(
            PackStatus::Completed,
            42,
            0,
            12345,
            '/tmp/exports/run-1.zip',
            '/tmp/exports/manifest.json',
        ), at: 1001);
        $run->recordPublishBoundary(PublishBoundaryResult::resumable(
            'bafy-parked-cid',
            null,
            null,
            'The workspace has no website yet.',
        ), at: 1002);
        $run->pause(at: 1002);
        $this->repository->save($run);

        $result = $this->service()->publishExisting();

        self::assertTrue($result->queued);
        self::assertNull($result->refusal);
        self::assertNotNull($result->runId);

        // The parked slot is replaced by exactly one fresh publish-only run.
        self::assertCount(1, $this->repository->list());

        $fresh = $this->repository->find($result->runId);
        self::assertNotNull($fresh);
        self::assertSame(RunStatus::NotStarted, $fresh->status);
        self::assertTrue($fresh->dirty);
        self::assertSame('publish|', $fresh->resumeCursor);
        // The intact artifact is reused for the replay.
        self::assertSame('/tmp/exports/run-1.zip', $fresh->pack?->zipPath);
        self::assertNull($fresh->websiteId);

        // Exactly one scheduled tick, immediately, with no follow-up.
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
        self::assertFalse($this->scheduler->isScheduled(ContentPublishScheduler::FOLLOW_UP_HOOK));
    }

    public function testPublishExistingReusesIntactArtifactAndResumesAtPublishWithOneTick(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());
        $source = $this->terminalPackedRun();

        $result = $this->service()->publishExisting();

        self::assertTrue($result->queued);
        self::assertNotNull($result->runId);
        self::assertNull($result->refusal);
        self::assertNotSame($source->runId, $result->runId);

        // The terminal source slot is replaced by exactly one fresh run.
        self::assertCount(1, $this->repository->list());

        $fresh = $this->repository->find($result->runId);
        self::assertNotNull($fresh);
        self::assertSame(RunStatus::NotStarted, $fresh->status);
        self::assertTrue($fresh->dirty);
        // Jump straight to the publish boundary without re-exporting.
        self::assertSame('publish|', $fresh->resumeCursor);
        // The intact artifact is reused, and the settings snapshot is carried.
        self::assertNotNull($fresh->pack);
        self::assertSame('/tmp/exports/run-1.zip', $fresh->pack->zipPath);
        self::assertSame($source->settingsSnapshot(), $fresh->settingsSnapshot());

        // Exactly one scheduled tick, immediately, with no follow-up.
        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
        self::assertSame($this->clock->now(), $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
        self::assertFalse($this->scheduler->isScheduled(ContentPublishScheduler::FOLLOW_UP_HOOK));
    }

    public function testPublishExistingCarriesLastPublishedIdentifiersOntoTheSeededRun(): void
    {
        // A prior publish already persisted the destination identity; the
        // publish-only replay must not lose it while the re-publish is queued.
        $this->identity->setCurrentIdentity($this->readyIdentity());
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->recordPack(new PackResult(
            PackStatus::Completed,
            42,
            0,
            12345,
            '/tmp/exports/run-1.zip',
            '/tmp/exports/manifest.json',
        ), at: 1001);
        $run->recordPublishIdentifiers('bafy-live-cid', 'web-42', 'k-ipns-7', at: 1002);
        $run->fail('The IPFS upload timed out', at: 1003);
        $this->repository->save($run);

        $result = $this->service()->publishExisting();

        $seededRunId = $result->runId;
        self::assertNotNull($seededRunId);
        $fresh = $this->repository->find($seededRunId);
        self::assertNotNull($fresh);
        self::assertSame('bafy-live-cid', $fresh->publishCid);
        self::assertSame('web-42', $fresh->websiteId);
        self::assertSame('k-ipns-7', $fresh->ipnsKey);
        // The status surface keeps reporting the last published CID.
        self::assertSame('bafy-live-cid', $this->service()->status()->publishCid);
    }

    public function testPublishExistingReturnsJsonSafeDto(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());
        $this->terminalPackedRun();

        $payload = $this->service()->publishExisting()->toArray();

        self::assertTrue($payload['queued']);
        self::assertSame('queued', $payload['status']);
        self::assertArrayHasKey('run_id', $payload);
        self::assertNotNull($payload['run_id']);
        self::assertNull($payload['refusal']);
    }

    public function testPublishExistingDtoNeverLeaksCredentials(): void
    {
        $this->identity->setCurrentIdentity($this->readyIdentity());
        $this->terminalPackedRun();

        $env = $this->env([
            EnvIdentity::PORTAL_API_URL => 'https://account.example.test',
            EnvIdentity::PORTAL_API_KEY => 'super-secret-account-key',
            EnvIdentity::WORKSPACE_AUTH_USERNAME => 'operator',
            EnvIdentity::WORKSPACE_AUTH_PASSWORD => 'workspace-pass',
        ]);

        $json = (string) json_encode($this->service($env)->publishExisting()->toArray());

        self::assertStringNotContainsString('super-secret-account-key', $json);
        self::assertStringNotContainsString('workspace-pass', $json);
        self::assertStringNotContainsString('operator', $json);
    }

    /* -------------------- awaitingWebsite derivation -------------------- */

    /**
     * A parked first publish: started, intact pack recorded, then the publish
     * boundary parked resumable with the CID preserved and no website — the
     * exact state a resilient first publish lands in while the workspace has
     * no website yet.
     */
    private function parkedResumableRun(string $cid = 'bafy-parked-cid', ?string $ipnsName = null, ?RunSettings $settings = null): ExportRun
    {
        $run = ExportRun::create('run-parked', $settings ?? new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->recordPack(new PackResult(
            PackStatus::Completed,
            42,
            0,
            12345,
            '/tmp/exports/run-parked.zip',
            '/tmp/exports/manifest.json',
        ), at: 1001);
        $run->recordPublishBoundary(PublishBoundaryResult::resumable(
            $cid,
            null,
            $ipnsName,
            'The workspace has no website yet.',
        ), at: 1002);
        $run->pause(at: 1002);
        $this->repository->save($run);

        return $run;
    }

    private function website(int $id, string $targetHash, string $targetType = 'ipfs'): Website
    {
        return Website::fromArray([
            'id' => $id,
            'status' => 'active',
            'domain' => 'site-' . $id . '.example.test',
            'target_hash' => $targetHash,
            'target_type' => $targetType,
        ]);
    }

    public function testAwaitingWebsiteIsNeutralFalseWithoutAResumableRun(): void
    {
        $websites = new FakeWebsiteList();
        $status = $this->service(websites: $websites, registry: new FakePublishRegistry())->status();

        self::assertFalse($status->awaitingWebsite);
        self::assertFalse($status->toArray()['awaiting_website']);
        // A plain poll (no run to await for) never reads the account registry.
        self::assertSame(0, $websites->calls);
    }

    public function testAwaitingWebsiteSkipsRegistryWhenWebsiteIdAlreadySet(): void
    {
        // A known identity means the workspace is linked: the account registry
        // must not be consulted, and the field stays false.
        $this->identity->setCurrentIdentity($this->readyIdentity());
        $this->parkedResumableRun();
        $websites = new FakeWebsiteList();

        $status = $this->service(
            websites: $websites,
            registry: new FakePublishRegistry(),
        )->status();

        self::assertFalse($status->awaitingWebsite);
        self::assertSame(0, $websites->calls);
    }

    public function testAwaitingWebsiteSkipsRegistryWhenTheRunAlreadyHasAWebsite(): void
    {
        // A prior publish recorded identifiers onto the run itself — the local
        // website cache is populated, so no registry read is warranted.
        $run = ExportRun::create('run-live', new RunSettings(), at: 1000);
        $run->recordPublishIdentifiers('bafy-live-cid', 'web-42', 'k-ipns-7', at: 1001);
        $this->repository->save($run);
        $websites = new FakeWebsiteList();

        $status = $this->service(
            websites: $websites,
            registry: new FakePublishRegistry(),
        )->status();

        self::assertFalse($status->awaitingWebsite);
        self::assertSame(0, $websites->calls);
    }

    public function testAwaitingWebsiteSkipsRegistryForANonResumableBoundary(): void
    {
        // A run that never reached the resumable park (here: no publish
        // boundary at all) is not awaiting and never reads the registry.
        $run = ExportRun::create('run-uploading', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $this->repository->save($run);
        $websites = new FakeWebsiteList();

        $status = $this->service(
            websites: $websites,
            registry: new FakePublishRegistry(),
        )->status();

        self::assertFalse($status->awaitingWebsite);
        self::assertSame(0, $websites->calls);
    }

    public function testAwaitingWebsiteSkipsRegistryForAFailedBoundary(): void
    {
        // A non-resumable verdict (failed) is not the awaiting park either; the
        // registry is only the no-website existence check for a resumable run.
        $run = ExportRun::create('run-failed', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $run->recordPublishBoundary(PublishBoundaryResult::failed('Gateway refused.'), at: 1002);
        $run->fail('Gateway refused.', at: 1003);
        $this->repository->save($run);
        $websites = new FakeWebsiteList();

        $status = $this->service(
            websites: $websites,
            registry: new FakePublishRegistry(),
        )->status();

        self::assertFalse($status->awaitingWebsite);
        self::assertSame(0, $websites->calls);
    }

    public function testAwaitingWebsiteMatchesRegistryTargetAndWritesIdThrough(): void
    {
        // REGISTRY-FIRST: the account already owns a website whose target_hash
        // is exactly the preserved CID — even though nothing was recorded
        // locally (empty identity cache) the workspace HAS its website, so the
        // matched id is written through to the persisted identity and the run
        // is not awaiting.
        $this->parkedResumableRun('bafy-parked-cid');
        $websites = new FakeWebsiteList([
            $this->website(1, 'bafy-other'),
            $this->website(2, 'bafy-parked-cid'),
        ]);
        $registry = new FakePublishRegistry();

        $status = $this->service(websites: $websites, registry: $registry)->status();

        self::assertFalse($status->awaitingWebsite);
        self::assertFalse($status->toArray()['awaiting_website']);
        self::assertSame(1, $websites->calls);
        // The matched website's id landed in the persisted identity (cache
        // write-through) so subsequent runs behave as linked.
        $written = $registry->current();
        self::assertNotNull($written);
        self::assertSame('2', $written->websiteId);
    }

    public function testAwaitingWebsiteHoldsWhenRegistryHasNoMatchingTarget(): void
    {
        // No website in the account matches the preserved CID and no local id
        // exists: this is exactly the "sent — waiting for a website" park.
        $this->parkedResumableRun('bafy-parked-cid');
        $websites = new FakeWebsiteList([
            $this->website(1, 'bafy-unrelated'),
        ]);
        $registry = new FakePublishRegistry();

        $status = $this->service(websites: $websites, registry: $registry)->status();

        self::assertTrue($status->awaitingWebsite);
        self::assertTrue($status->toArray()['awaiting_website']);
        self::assertSame(1, $websites->calls);
        // No match means nothing is written through.
        self::assertNull($registry->current());
    }

    public function testAwaitingWebsiteFallsBackToParkSignalWithoutRegistrySeams(): void
    {
        // Without the registry backends (an incomplete portal identity composition)
        // the run's own park signal still reports the wait — no network read.
        $this->parkedResumableRun();

        $status = $this->service()->status();

        self::assertTrue($status->awaitingWebsite);
    }

    public function testAwaitingWebsiteResolvesIpnsTargetToCidBeforeMatching(): void
    {
        // Cast's ipns default makes the workspace website's target_hash a
        // mutable IPNS name, so the registry-first match must resolve it to
        // the immutable CID it serves before comparing with the preserved CID.
        $this->parkedResumableRun('bafy-parked-cid');
        $resolver = new FakeIpnsResolver();
        $resolver->resolutions['k51-ipns-name-2'] = 'bafy-parked-cid';
        $websites = new FakeWebsiteList([
            $this->website(1, 'k51-ipns-name-1', 'ipns'),
            $this->website(2, 'k51-ipns-name-2', 'ipns'),
        ]);
        $registry = new FakePublishRegistry();

        $status = $this->service(websites: $websites, registry: $registry, ipns: $resolver)->status();

        self::assertFalse($status->awaitingWebsite);
        // Only the IPNS-targeted website rows are resolved.
        self::assertSame(['k51-ipns-name-1', 'k51-ipns-name-2'], $resolver->resolved);
        $written = $registry->current();
        self::assertNotNull($written);
        self::assertSame('2', $written->websiteId);
    }

    public function testAwaitingWebsiteTreatsUnpublishedIpnsTargetAsNoMatch(): void
    {
        // An IPNS name with no resolvable publication yet cannot be proven to
        // serve the preserved CID, so the run stays awaiting (no write-through).
        $this->parkedResumableRun('bafy-parked-cid');
        $resolver = new FakeIpnsResolver();
        $resolver->resolutions['k51-ipns-name-1'] = 'bafy-other'; // published but different cid
        $websites = new FakeWebsiteList([
            $this->website(1, 'k51-ipns-name-1', 'ipns'),
            // website 2's name is NOT resolvable at all (never published).
            $this->website(2, 'k51-ipns-name-2', 'ipns'),
        ]);
        $registry = new FakePublishRegistry();

        $status = $this->service(websites: $websites, registry: $registry, ipns: $resolver)->status();

        self::assertTrue($status->awaitingWebsite);
        self::assertNull($registry->current());
    }

    public function testAvailableWebsitesResolvesIpnsTargetAndExcludesTheWorkspaceWebsite(): void
    {
        // The link picker excludes the workspace's own website, compared by
        // the immutable CID: an IPNS-targeted row whose name resolves to the
        // preserved CID is excluded even though its raw target_hash is a name.
        $this->parkedResumableRun('bafy-parked-cid');
        $resolver = new FakeIpnsResolver();
        $resolver->resolutions['k51-ipns-name-1'] = 'bafy-parked-cid';
        $resolver->resolutions['k51-ipns-name-2'] = 'bafy-other';
        $websites = new FakeWebsiteList([
            $this->website(1, 'k51-ipns-name-1', 'ipns'),
            $this->website(2, 'k51-ipns-name-2', 'ipns'),
            $this->website(3, 'QmPlain', 'ipfs'),
        ]);

        $result = $this->service(
            websites: $websites,
            registry: new FakePublishRegistry(),
            ipns: $resolver,
        )->availableWebsites();

        self::assertTrue($result->listed);
        $ids = array_column($result->websites, 'website_id');
        self::assertSame(['2', '3'], $ids);
    }

    /* ------------------------- createWebsite (guided a) ------------------ */

    public function testCreateWebsiteEmptyHostnameSendsAutoGenerateWithIpnsTargetAndNoDomainLabel(): void
    {
        // An EMPTY hostname is the confirmed auto-generate path: the portal must
        // mint the platform domain. Nobody typed a domain, so the create must
        // NOT send a domain — and it must not send the old empty-hostname 'site'
        // label either, because Pinner minted site.pinned.site from exactly that
        // made-up label. The target is the run's published IPNS name (Slate A
        // ipns default, created+published before the run parked).
        $this->parkedResumableRun('bafy-parked-cid', 'k51-ipns-parked');
        $websites = new FakeWebsiteList();
        $registry = new FakePublishRegistry();

        $result = $this->service(websites: $websites, registry: $registry)->createWebsite('');

        self::assertTrue($result->created);
        self::assertSame('99', $result->websiteId);
        // The identity half is written through (registry write-through → the
        // resume path re-points rather than double-creates).
        self::assertSame('99', $registry->current()?->websiteId);

        $request = $websites->lastCreate;
        self::assertNotNull($request);
        self::assertSame('k51-ipns-parked', $request->targetHash);
        self::assertSame('ipns', $request->targetType);
        self::assertNull($request->label, 'empty hostname must not send a made-up label');
        self::assertNull($request->domain, 'auto-generate must not send any domain');
        self::assertNull($request->namespace);
        self::assertTrue($request->generate, 'auto-generate must set generate');
        self::assertTrue($request->dnsHostingEnabled, 'auto-generate must set managed dns hosting');
    }

    public function testCreateWebsiteEmptyHostnameLegacyIpfsRunFallsBackToCidAndIpfs(): void
    {
        // A legacy ipfs-mode run has no published IPNS name to target, so the
        // create must fall back to the preserved CID + legacy ipfs targeting.
        $this->parkedResumableRun('bafy-parked-cid', null, new RunSettings(targetType: 'ipfs'));
        $websites = new FakeWebsiteList();

        $result = $this->service(websites: $websites, registry: new FakePublishRegistry())->createWebsite('');

        self::assertTrue($result->created);
        $request = $websites->lastCreate;
        self::assertNotNull($request);
        self::assertSame('bafy-parked-cid', $request->targetHash);
        self::assertSame('ipfs', $request->targetType);
        self::assertTrue($request->generate);
        self::assertNull($request->domain);
    }

    public function testCreateWebsiteNamedHostnameSendsCustomDomainIcannManagedNotGenerate(): void
    {
        // A NAMED hostname is an explicit custom domain — the pinner CLI's
        // custom-domain contract: domain + icann namespace + managed dns
        // hosting, and NO generate flag (generate would re-mint instead). The
        // target stays the run's published IPNS name.
        $this->parkedResumableRun('bafy-parked-cid', 'k51-ipns-parked');
        $websites = new FakeWebsiteList();

        $result = $this->service(websites: $websites, registry: new FakePublishRegistry())
            ->createWebsite('shop.example.com');

        self::assertTrue($result->created);
        $request = $websites->lastCreate;
        self::assertNotNull($request);
        self::assertSame('k51-ipns-parked', $request->targetHash);
        self::assertSame('ipns', $request->targetType);
        self::assertSame('shop.example.com', $request->domain);
        self::assertSame('icann', $request->namespace);
        self::assertSame('shop.example.com', $request->label);
        self::assertFalse($request->generate, 'a named hostname must not generate');
        self::assertTrue($request->dnsHostingEnabled, 'a named hostname stays managed');
    }

    public function testCreateWebsiteLegacyPathSkipsAutoAttachWithoutALinker(): void
    {
        // No workspace linker/connection wired (an incomplete composition): the
        // create records the website identity exactly as before — no attach is
        // attempted and the legacy behavior is unchanged.
        $this->parkedResumableRun('bafy-parked-cid', 'k51-ipns-parked');
        $websites = new FakeWebsiteList();
        $registry = new FakePublishRegistry();

        $result = $this->service(websites: $websites, registry: $registry)->createWebsite('');

        self::assertTrue($result->created);
        self::assertSame('99', $result->websiteId);
        self::assertSame('99', $registry->current()?->websiteId);
    }

    public function testCreateWebsiteAutoAttachesTheNewWebsiteBeforeRecordingIt(): void
    {
        // Auto-attach: the freshly created website is attached to the workspace
        // and ONLY then is the identity recorded — the ordering proof is that
        // at attach time nothing has been recorded yet, so a failed attach can
        // never fake a linked state.
        $this->parkedResumableRun('bafy-parked-cid', 'k51-ipns-parked');
        $websites = new FakeWebsiteList();
        $registry = new FakePublishRegistry();
        $linker = new FakeWorkspaceLinker();
        $linker->onAttach = function () use ($registry): void {
            self::assertNull($registry->current(), 'the identity must not be recorded before the attach');
        };

        $result = $this->service(
            websites: $websites,
            registry: $registry,
            connection: $this->resolvedConnectionResolver(),
            workspaces: $linker,
        )->createWebsite('');

        self::assertTrue($result->created);
        self::assertSame('99', $result->websiteId);
        // The attach targeted the freshly created website on this workspace.
        self::assertSame('11', $linker->workspaceId);
        self::assertSame(99, $linker->websiteId);
        // The write-through lands AFTER the attach.
        self::assertSame('99', $registry->current()?->websiteId);
    }

    public function testCreateWebsiteToleratesAttach409WhenTheWorkspaceAlreadyHoldsThisWebsite(): void
    {
        // A create-retry 409: the workspace already holds THIS run's website
        // (a prior attempt attached it) — the created row serves the preserved
        // CID through the IPNS name, so the auto-attach is tolerated, the run
        // reports success and the identity records that website; no raw
        // exception and no double-attach.
        $this->parkedResumableRun('bafy-parked-cid', 'k51-ipns-parked');
        $resolver = new FakeIpnsResolver();
        $resolver->resolutions['k51-ipns-parked'] = 'bafy-parked-cid';
        $websites = new FakeWebsiteList();
        $registry = new FakePublishRegistry();
        $linker = new FakeWorkspaceLinker();
        $linker->attachException = $this->attachConflict();

        $result = $this->service(
            websites: $websites,
            registry: $registry,
            connection: $this->resolvedConnectionResolver(),
            workspaces: $linker,
            ipns: $resolver,
        )->createWebsite('');

        self::assertTrue($result->created);
        self::assertSame('99', $result->websiteId);
        self::assertSame('99', $registry->current()?->websiteId);
        self::assertSame(99, $linker->websiteId, 'the created website was the one the attach attempted');
    }

    public function testCreateWebsiteSurfacesWorkspaceConflictWhenAttach409CannotMatchThisWebsite(): void
    {
        // A create 409 with no registry row provable to serve the preserved CID
        // (here the freshly created website's IPNS name is not published yet) is
        // a REAL conflict: the workspace already holds a DIFFERENT website. It
        // must surface as the typed workspace conflict — clean, never a raw
        // exception — and nothing is recorded, so no fake-linked state.
        $this->parkedResumableRun('bafy-parked-cid', 'k51-ipns-parked');
        $websites = new FakeWebsiteList();
        $registry = new FakePublishRegistry();
        $linker = new FakeWorkspaceLinker();
        $linker->attachException = $this->attachConflict();

        $result = $this->service(
            websites: $websites,
            registry: $registry,
            connection: $this->resolvedConnectionResolver(),
            workspaces: $linker,
            ipns: new FakeIpnsResolver(),
        )->createWebsite('');

        self::assertFalse($result->created);
        self::assertSame(PublishWebsiteRefusal::WorkspaceAlreadyLinked, $result->refusal);
        self::assertSame('workspace_already_linked', $result->toArray()['refusal']);
        self::assertNull($registry->current(), 'a conflicted create must not record an identity');
        self::assertSame(99, $linker->websiteId);
    }

    public function testCreateWebsiteReturnsNoWorkspaceWhenTheWorkspaceCannotBeResolved(): void
    {
        // The attach path is wired but the workspace is unresolvable: the
        // create must refuse side-effect free rather than record a website that
        // was never attached to a real workspace.
        $this->parkedResumableRun('bafy-parked-cid', 'k51-ipns-parked');
        $unresolved = new class () implements ConnectionResolver {
            public function current(): SelfIdentification
            {
                return SelfIdentification::unreachable(new PortalIdentity('https://account.example.test', 'key'));
            }
        };

        $result = $this->service(
            websites: new FakeWebsiteList(),
            registry: new FakePublishRegistry(),
            connection: $unresolved,
            workspaces: new FakeWorkspaceLinker(),
        )->createWebsite('');

        self::assertFalse($result->created);
        self::assertSame(PublishWebsiteRefusal::NoWorkspace, $result->refusal);
    }

    public function testLinkWebsiteSurfacesAttach409ConflictAsAlreadyLinkedRefusal(): void
    {
        // Picker link 409: the chosen website is already attached to a
        // workspace (the one conflict the list payload cannot express). It must
        // surface as the typed already-linked refusal — friendly actionable
        // copy on the card — and NOTHING is recorded, so no fake-linked state.
        $this->parkedResumableRun('bafy-parked-cid');
        $websites = new FakeWebsiteList([
            $this->website(66, 'bafy-linked-elsewhere'),
        ]);
        $registry = new FakePublishRegistry();
        $linker = new FakeWorkspaceLinker();
        $linker->attachException = $this->attachConflict();

        $result = $this->service(
            connection: $this->resolvedConnectionResolver(),
            websites: $websites,
            registry: $registry,
            workspaces: $linker,
        )->linkWebsite(66);

        self::assertFalse($result->linked);
        self::assertSame(PublishWebsiteRefusal::WebsiteAlreadyLinked, $result->refusal);
        self::assertSame('website_already_linked', $result->toArray()['refusal']);
        self::assertNull($registry->current(), 'a conflicted link must not record an identity');
        self::assertSame(66, $linker->websiteId);
        self::assertSame('11', $linker->workspaceId);
    }

    public function testLinkWebsiteKeepsLinkFailedForANonConflictAttachRejection(): void
    {
        // Only a 409 maps to the friendly already-linked refusal; any other
        // attach failure stays the generic LinkFailed.
        $this->parkedResumableRun('bafy-parked-cid');
        $linker = new FakeWorkspaceLinker();
        $linker->attachException = new \LumeWeb\Cast\Http\UnexpectedStatusCodeException(
            new HttpResponse(new Response(422, [], '{"error":"invalid"}')),
        );

        $result = $this->service(
            connection: $this->resolvedConnectionResolver(),
            websites: new FakeWebsiteList([$this->website(66, 'bafy-any')]),
            registry: new FakePublishRegistry(),
            workspaces: $linker,
        )->linkWebsite(66);

        self::assertFalse($result->linked);
        self::assertSame(PublishWebsiteRefusal::LinkFailed, $result->refusal);
    }

    /**
     * The workspace-duplicate attach conflict the pinner returns as a 409
     * (workspace already attached, or the website already has a workspace).
     */
    private function attachConflict(): UnexpectedStatusCodeException
    {
        return new UnexpectedStatusCodeException(
            new HttpResponse(new Response(409, [], '{"error":"workspace already has a website"}')),
        );
    }
}
