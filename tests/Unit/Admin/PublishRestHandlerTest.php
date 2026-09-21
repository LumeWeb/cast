<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Admin\PublishRestHandler;
use LumeWeb\Cast\Admin\PublishSetupService;
use LumeWeb\Cast\Environment\EnvIdentity;
use LumeWeb\Cast\Environment\EnvReader;
use LumeWeb\Cast\Export\ExportRun;
use LumeWeb\Cast\Export\InMemoryRunRepository;
use LumeWeb\Cast\Export\InMemoryWorkItemRepository;
use LumeWeb\Cast\Export\PackResult;
use LumeWeb\Cast\Export\PackStatus;
use LumeWeb\Cast\Export\RunSettings;
use LumeWeb\Cast\Export\RunStatus;
use LumeWeb\Cast\Jobs\ContentPublishScheduler;
use LumeWeb\Cast\Jobs\PublishIdentity;
use LumeWeb\Cast\Jobs\FixedClock;
use LumeWeb\Cast\Jobs\InMemoryIdentityGateway;
use LumeWeb\Cast\Jobs\InMemoryPublishModeStore;
use LumeWeb\Cast\Jobs\InMemoryScheduler;
use LumeWeb\Cast\Jobs\PublishModeStore;
use LumeWeb\Cast\Onboarding\Wizard;
use LumeWeb\Cast\Onboarding\WizardState;
use LumeWeb\Cast\Tests\Unit\Onboarding\FakeWizardStore;
use PHPUnit\Framework\TestCase;

/**
 * The REST handler is a thin adapter that serializes the setup service's typed
 * DTOs to JSON-safe arrays (WordPress' REST server JSON-encodes plain arrays).
 * These tests pin the exact request-surface contract: status() never starts or
 * schedules, and start() only queues/schedules — never long-running work.
 */
final class PublishRestHandlerTest extends TestCase
{
    private FixedClock $clock;

    private InMemoryRunRepository $repository;

    private InMemoryScheduler $scheduler;

    private FakeWizardStore $wizardStore;

    private FakePublishedContentProbe $content;

    private InMemoryIdentityGateway $identity;

    private PublishRestHandler $handler;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(1_700_000_000);
        $this->repository = new InMemoryRunRepository();
        $this->scheduler = new InMemoryScheduler();
        $this->wizardStore = new FakeWizardStore(new Wizard(state: WizardState::Completed));
        $this->content = new FakePublishedContentProbe(true);
        $this->identity = new InMemoryIdentityGateway();

        $this->handler = new PublishRestHandler($this->service());
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

    private function service(): PublishSetupService
    {
        $modeStore = new InMemoryPublishModeStore();

        return new PublishSetupService(
            env: $this->env([
                EnvIdentity::PORTAL_API_URL => 'https://account.example.test',
                EnvIdentity::PORTAL_API_KEY => 'api-key',
            ]),
            wizardStore: $this->wizardStore,
            content: $this->content,
            repository: $this->repository,
            identity: $this->identity,
            contentScheduler: new ContentPublishScheduler(
                clock: $this->clock,
                repository: $this->repository,
                scheduler: $this->scheduler,
                identity: $this->identity,
                modeStore: $modeStore,
                workItems: new InMemoryWorkItemRepository(),
            ),
            modeStore: $modeStore,
            clock: $this->clock,
        );
    }

    public function testStatusReturnsTypedJsonSafePayload(): void
    {
        $payload = $this->handler->status();

        self::assertTrue($payload['bootstrap_identity_complete']);
        self::assertTrue($payload['onboarding_complete']);
        self::assertTrue($payload['has_eligible_content']);
        self::assertSame('manual', $payload['mode']);
        self::assertFalse($payload['auto_active']);
        self::assertFalse($payload['run_active']);
        self::assertSame('not_started', $payload['run_status']);
        self::assertNull($payload['identity']);
        self::assertIsArray($payload['env_problems']);
    }

    public function testStatusNeverStartsOrSchedules(): void
    {
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->markDirty(at: 1000);
        $this->repository->save($run);

        $payload = $this->handler->status();

        self::assertTrue($payload['dirty']);
        // A pending (NotStarted) run still occupies the single run slot, so the
        // status surface reports it as live without being running — matching
        // the service's refusal of a second start while a pending run exists.
        self::assertTrue($payload['run_active']);
        self::assertSame('not_started', $payload['run_status']);
        // Reading status must never create a new run or schedule a tick.
        self::assertCount(1, $this->repository->list());
        self::assertSame('run-1', $this->repository->latest()?->runId);
        self::assertSame(0, $this->scheduler->count());
    }

    public function testStartQueuesExactlyOneRunAndOneTick(): void
    {
        $payload = $this->handler->start();

        self::assertTrue($payload['queued']);
        self::assertSame('queued', $payload['status']);
        self::assertNotNull($payload['run_id']);

        $run = $this->repository->latest();
        self::assertNotNull($run);
        self::assertSame($payload['run_id'], $run->runId);
        self::assertTrue($run->dirty);

        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
        self::assertSame($this->clock->now(), $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testStartReturnsTypedRefusalWhenNotReady(): void
    {
        $this->content->eligible = false;

        $payload = $this->handler->start();

        self::assertFalse($payload['queued']);
        self::assertSame('refused', $payload['status']);
        self::assertSame('no_eligible_content', $payload['refusal']);
        self::assertNull($this->repository->latest());
        self::assertSame(0, $this->scheduler->count());
    }

    public function testNowQueuesExactlyOneRunAndOneTick(): void
    {
        $payload = $this->handler->now();

        self::assertTrue($payload['queued']);
        self::assertSame('queued', $payload['status']);
        self::assertNotNull($payload['run_id']);
        self::assertNull($payload['refusal']);

        $run = $this->repository->latest();
        self::assertNotNull($run);
        self::assertSame($payload['run_id'], $run->runId);
        self::assertTrue($run->dirty);

        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
        self::assertSame($this->clock->now(), $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testNowRefusesAnActivelyExecutingRun(): void
    {
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $this->repository->save($run);

        $payload = $this->handler->now();

        self::assertFalse($payload['queued']);
        self::assertSame('refused', $payload['status']);
        self::assertSame('run_active', $payload['refusal']);
        self::assertSame(0, $this->scheduler->count());
    }

    public function testSetModePersistsAndReturnsTypedPayload(): void
    {
        $payload = $this->handler->setMode('on_update');

        self::assertTrue($payload['updated']);
        self::assertSame('updated', $payload['status']);
        self::assertSame('on_update', $payload['mode']);
        self::assertTrue($payload['auto_active']);

        // The persisted mode is visible through the status surface.
        self::assertSame('on_update', $this->handler->status()['mode']);
        self::assertTrue($this->handler->status()['auto_active']);
    }

    public function testSetModeRefusesAnUnknownModeLeavingCurrentUntouched(): void
    {
        $payload = $this->handler->setMode('automatic');

        self::assertFalse($payload['updated']);
        self::assertSame('refused', $payload['status']);
        self::assertSame('invalid_mode', $payload['refusal']);
        self::assertSame('manual', $payload['mode']);
        self::assertSame('manual', $this->handler->status()['mode']);
    }

    public function testCancelCancelsTheActiveRunAndClearsPendingEvents(): void
    {
        $run = ExportRun::create('run-1', new RunSettings(), at: 1000);
        $run->start(at: 1000);
        $this->repository->save($run);
        $this->scheduler->scheduleSingle(ContentPublishScheduler::AUTO_HOOK, 3600);
        $this->scheduler->scheduleSingle(ContentPublishScheduler::FOLLOW_UP_HOOK, 3605);

        $payload = $this->handler->cancel();

        self::assertTrue($payload['cancelled']);
        self::assertSame('cancelled', $payload['status']);
        self::assertSame('run-1', $payload['run_id']);
        self::assertNull($payload['refusal']);
        self::assertSame(RunStatus::Cancelled, $this->repository->find('run-1')?->status);
        self::assertSame(0, $this->scheduler->count());
    }

    public function testCancelReturnsTypedRefusalPayloadWhenNothingToCancel(): void
    {
        $payload = $this->handler->cancel();

        self::assertFalse($payload['cancelled']);
        self::assertSame('refused', $payload['status']);
        self::assertSame('no_run', $payload['refusal']);
        self::assertNull($this->repository->latest());
        self::assertSame(0, $this->scheduler->count());
    }

    public function testPublishExistingQueuesAPublishOnlyRunAndOneTick(): void
    {
        $this->identity->setCurrentIdentity(new PublishIdentity('web-42', 'My Blog', 'k-ipns-7', 'cast-live', true));
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

        $payload = $this->handler->publishExisting();

        self::assertTrue($payload['queued']);
        self::assertSame('queued', $payload['status']);
        self::assertNotNull($payload['run_id']);
        self::assertNull($payload['refusal']);

        $fresh = $this->repository->find($payload['run_id']);
        self::assertNotNull($fresh);
        self::assertSame('publish|', $fresh->resumeCursor);
        self::assertNotNull($fresh->pack);
        self::assertSame('/tmp/exports/run-1.zip', $fresh->pack->zipPath);
        self::assertCount(1, $this->repository->list());

        self::assertSame(1, $this->scheduler->count());
        self::assertTrue($this->scheduler->isScheduled(ContentPublishScheduler::AUTO_HOOK));
        self::assertSame($this->clock->now(), $this->scheduler->nextAt(ContentPublishScheduler::AUTO_HOOK));
    }

    public function testPublishExistingReturnsTypedRefusalPayloadWithoutArtifact(): void
    {
        $payload = $this->handler->publishExisting();

        self::assertFalse($payload['queued']);
        self::assertSame('refused', $payload['status']);
        self::assertSame('no_artifact', $payload['refusal']);
        self::assertNull($this->repository->latest());
        self::assertSame(0, $this->scheduler->count());
    }

    public function testCancelAndPublishExistingPayloadsNeverLeakCredentials(): void
    {
        // Poison a live run and an intact terminal artifact, then verify both
        // response payloads stay JSON-safe with no credential echo.
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
        $this->repository->save($run);

        $cancelJson = (string) json_encode($this->handler->cancel());
        $existingJson = (string) json_encode($this->handler->publishExisting());

        self::assertStringNotContainsString('super-secret-account-key', $cancelJson);
        self::assertStringNotContainsString('workspace-pass', $cancelJson);
        self::assertStringNotContainsString('super-secret-account-key', $existingJson);
        self::assertStringNotContainsString('workspace-pass', $existingJson);
    }
}
