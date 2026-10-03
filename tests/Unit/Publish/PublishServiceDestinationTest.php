<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use GuzzleHttp\Psr7\Response;
use LumeWeb\Cast\Http\HttpResponse;
use LumeWeb\Cast\Http\UnexpectedStatusCodeException;
use LumeWeb\Cast\Publish\Artifact;
use LumeWeb\Cast\Publish\PublishDestination;
use LumeWeb\Cast\Publish\PublishOutcome;
use LumeWeb\Cast\Publish\PublishService;
use LumeWeb\Cast\Publish\UploadResult;
use LumeWeb\Cast\Publish\UploadRouter;
use LumeWeb\Cast\Publish\UploadStatus;
use LumeWeb\Cast\Publish\UploadWaiter;
use LumeWeb\Cast\Publish\Website;
use LumeWeb\Cast\Publish\WebsiteReadinessWaiter;
use PHPUnit\Framework\TestCase;

/**
 * The destination-driven website provisioning: the website a first publish
 * creates (or an existing website it attaches) comes from the run's CONFIRMED
 * destination snapshot — never from the artifact's build-host label. The
 * three wire branches are exact: platform (generate/label + platform
 * root/namespace + managed DNS, no custom fields), custom (only the entered
 * domain, namespace, and explicit DNS-hosting choice), and existing (attach,
 * never create; a 409 conflict is a friendly typed refusal, not an exception).
 */
final class PublishServiceDestinationTest extends TestCase
{
    private FakeUploadClient $uploads;
    private FakeWebsiteClient $websites;
    private FakeIpnsClient $ipns;
    private FakePublishRegistry $registry;
    private RecordingPublishListener $listener;
    private FakePublishClock $clock;

    protected function setUp(): void
    {
        $this->uploads = new FakeUploadClient([new UploadResult(UploadStatus::Completed, cid: 'QmHash')]);
        $this->websites = new FakeWebsiteClient();
        $this->ipns = new FakeIpnsClient();
        $this->registry = new FakePublishRegistry();
        $this->listener = new RecordingPublishListener();
        $this->clock = new FakePublishClock();
    }

    public function testPlatformGeneratedDestinationSendsPlatformFieldsAndNeverTheBuildHostLabel(): void
    {
        // The artifact's label is the WordPress/build hostname — it must stay
        // out of the create request; only the confirmed destination fields
        // may define the platform claim.
        $destination = PublishDestination::platformGenerated('pinned.site', 'icann');

        $this->servesCid('website-1');
        $result = $this->makeService()->publish($this->artifact(), $destination);

        self::assertSame(PublishOutcome::Completed, $result->outcome);
        self::assertCount(1, $this->websites->created);
        $create = $this->websites->created[0];
        self::assertTrue($create->generate, 'platform generation must set generate');
        self::assertTrue($create->dnsHostingEnabled, 'platform subdomains are DNS-managed by the platform');
        self::assertSame('pinned.site', $create->platformDomain);
        self::assertSame('icann', $create->platformNamespace);
        self::assertNull($create->label, 'the build host must never become the website label');
        self::assertNull($create->domain, 'a platform claim must not carry a custom domain');
        self::assertNull($create->namespace, 'a platform claim must not carry a custom namespace');
        // The IPNS key still names the run; only the website claim is destination-driven.
        self::assertSame(['aovvgar1.build.pinned.site'], $this->ipns->createdKeys);
    }

    public function testPlatformLabelledDestinationSendsTheChosenLabel(): void
    {
        $destination = PublishDestination::platformLabelled('my-site', 'pinned.site');

        $this->servesCid('website-1');
        $result = $this->makeService()->publish($this->artifact(), $destination);

        self::assertSame(PublishOutcome::Completed, $result->outcome);
        self::assertCount(1, $this->websites->created);
        $create = $this->websites->created[0];
        self::assertSame('my-site', $create->label, 'the chosen platform label, not the build host');
        self::assertFalse($create->generate);
        self::assertTrue($create->dnsHostingEnabled);
        self::assertSame('pinned.site', $create->platformDomain);
        self::assertNull($create->platformNamespace);
        self::assertNull($create->domain);
        self::assertNull($create->namespace);
    }

    public function testCustomDestinationSendsOnlyEnteredDomainNamespaceAndExplicitDnsFalse(): void
    {
        // The self-managed custom-domain branch: ONLY the explicitly entered
        // domain, namespace, and DNS-hosting choice — no label, no generate,
        // no platform fields, and the false must be explicit on the DTO.
        // Since the custom-domain pause, the first publish creates the website
        // with those exact fields and then PAUSES awaiting the domain's DNS
        // (preserving the CID/IPNS/website) instead of polling readiness.
        $destination = PublishDestination::custom('shop.example.com', 'hns', false);

        $result = $this->makeService()->publish($this->artifact(), $destination);

        self::assertSame(PublishOutcome::AwaitingDns, $result->outcome);
        self::assertSame('QmHash', $result->cid, 'the uploaded CID is preserved for the resume');
        self::assertNotNull($result->websiteId, 'the created website id is preserved for the resume');
        self::assertCount(1, $this->websites->created);
        $create = $this->websites->created[0];
        self::assertSame('shop.example.com', $create->domain);
        self::assertSame('hns', $create->namespace);
        self::assertFalse($create->dnsHostingEnabled, 'the explicit self-managed choice must ride the request');
        self::assertNull($create->label);
        self::assertFalse($create->generate);
        self::assertNull($create->platformDomain);
        self::assertNull($create->platformNamespace);
    }

    public function testExistingDestinationAttachesAndRepointsWithoutCreating(): void
    {
        $destination = PublishDestination::existing('42');
        $linker = new FakeWorkspaceLinker();

        $this->servesCid('42');
        $result = $this->makeService($linker, static fn (): string => '7')->publish($this->artifact(), $destination);

        self::assertSame(PublishOutcome::Completed, $result->outcome);
        // An existing account website is NEVER created — it is attached to the
        // workspace and re-pointed at the new upload.
        self::assertSame([], $this->websites->created, 'existing destinations must never strike a create');
        self::assertSame(1, $linker->calls);
        self::assertSame('7', $linker->workspaceId);
        self::assertSame(42, $linker->websiteId);
        self::assertSame([['42', 'k51-k1-aovvgar1.build.pinned.site', 'ipns']], $this->websites->updated);
        self::assertSame('42', $result->websiteId);
        $state = $this->registry->current();
        self::assertNotNull($state);
        self::assertSame('42', $state->websiteId);
    }

    public function testExistingDestinationAttachConflict409IsAFriendlyRefusal(): void
    {
        // The attach API answers 409 when the chosen website already belongs
        // to a workspace: the publish must surface a fixed, friendly refusal —
        // never a raw exception, never a create.
        $linker = new FakeWorkspaceLinker();
        $linker->attachException = new UnexpectedStatusCodeException(new HttpResponse(new Response(409, [], 'conflict')));

        $result = $this->makeService($linker, static fn (): string => '7')
            ->publish($this->artifact(), PublishDestination::existing('42'));

        self::assertSame(PublishOutcome::Failed, $result->outcome);
        self::assertStringContainsString('already in use', (string) $result->message);
        self::assertStringNotContainsString('409', (string) $result->message);
        self::assertSame([], $this->websites->created);
        self::assertNull($this->registry->current()?->websiteId);
    }

    public function testExistingDestinationWithoutAWorkspaceLinkerFailsFriendly(): void
    {
        // An incomplete composition (no attach adapter) cannot attach: the
        // publish refuses with a fixed message instead of creating a website.
        $result = $this->makeService()->publish($this->artifact(), PublishDestination::existing('42'));

        self::assertSame(PublishOutcome::Failed, $result->outcome);
        self::assertStringContainsString('attached', (string) $result->message);
        self::assertSame([], $this->websites->created);
        self::assertNull($this->registry->current()?->websiteId);
    }

    public function testFirstPublishWithoutAConfirmedDestinationFailsWithoutCreating(): void
    {
        // With no destination snapshot and no recorded identity there is
        // nothing legal to create: the run fails closed instead of minting a
        // website from the build hostname.
        $result = $this->makeService()->publish($this->artifact(), null);

        self::assertSame(PublishOutcome::Failed, $result->outcome);
        self::assertStringContainsString('no confirmed site address', (string) $result->message);
        self::assertSame([], $this->websites->created);
        self::assertNull($this->registry->current()?->websiteId);
    }

    public function testExistingDestinationWithAnUnresolvableWorkspaceFailsFriendly(): void
    {
        $linker = new FakeWorkspaceLinker();

        $result = $this->makeService($linker, static fn (): ?string => null)
            ->publish($this->artifact(), PublishDestination::existing('42'));

        self::assertSame(PublishOutcome::Failed, $result->outcome);
        self::assertStringContainsString('workspace', (string) $result->message);
        self::assertSame(0, $linker->calls, 'no workspace id means no attach attempt');
        self::assertSame([], $this->websites->created);
    }

    /**
     * Seed the readiness waiter with a website that is live and serving the
     * upload's CID (the ipns target is a name, so the fake's get() fallback
     * would otherwise report the name as the served CID and time out).
     */
    private function servesCid(string $websiteId): void
    {
        $this->websites->loopResponse = new Website($websiteId, '', 'QmHash', 'car', null, Website::STATUS_LIVE, 'QmHash');
    }

    /**
     * The ipns-default artifact whose LABEL IS the build host — the value the
     * pre-destination create paths leaked into the wire body.
     */
    private function artifact(): Artifact
    {
        return new Artifact(
            '/tmp/cast-export/run-abc-123.zip',
            'run-abc-123.zip',
            'ipns',
            'aovvgar1.build.pinned.site',
            2048,
        );
    }

    private function makeService(?FakeWorkspaceLinker $workspaces = null, ?\Closure $workspaceIdProvider = null): PublishService
    {
        return new PublishService(
            router: new UploadRouter(),
            uploads: new UploadWaiter($this->uploads, $this->clock),
            websites: $this->websites,
            ipns: $this->ipns,
            registry: $this->registry,
            readiness: new WebsiteReadinessWaiter($this->websites, $this->clock),
            listener: $this->listener,
            workspaces: $workspaces,
            workspaceIdProvider: $workspaceIdProvider,
        );
    }
}
