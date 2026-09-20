<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use LumeWeb\Cast\Jobs\PublishIdentity;
use LumeWeb\Cast\Jobs\WordPressIdentityGateway;
use LumeWeb\Cast\Publish\Artifact;
use LumeWeb\Cast\Publish\PublishService;
use LumeWeb\Cast\Publish\UploadResult;
use LumeWeb\Cast\Publish\UploadRouter;
use LumeWeb\Cast\Publish\UploadStatus;
use LumeWeb\Cast\Publish\UploadWaiter;
use LumeWeb\Cast\Publish\WebsiteReadinessWaiter;
use LumeWeb\Cast\Publish\WordPressPublishRegistry;
use LumeWeb\Cast\Tests\Unit\Persistence\FakeOptionGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The concrete WordPress publish registry: a PublishRegistry backed by the
 * option gateway that persists the site's portal identity (IPNS key + website)
 * into the same non-autoloaded `cast_publish_identity` option the identity
 * gateway reads, following the PublishIdentity schema/value conventions. It is
 * written incrementally (IPNS key name + id first, website id + name second)
 * so a crash or failure between the two never loses what already exists; a
 * partial record with EITHER half surfaces through current(), while the stored
 * identity only reports ready — and only becomes visible through hasIdentity() —
 * once both halves are complete.
 */
final class WordPressPublishRegistryTest extends TestCase
{
    private FakeOptionGateway $gateway;

    private WordPressPublishRegistry $registry;

    private WordPressIdentityGateway $identity;

    protected function setUp(): void
    {
        $this->gateway = new FakeOptionGateway();
        $this->registry = new WordPressPublishRegistry($this->gateway);
        $this->identity = new WordPressIdentityGateway($this->gateway);
    }

    public function testUsesTheSameOptionKeyAsTheIdentityGateway(): void
    {
        self::assertSame(WordPressIdentityGateway::OPTION_KEY, WordPressPublishRegistry::OPTION_KEY);
    }

    public function testMissingOptionReadsAsNoOwnedState(): void
    {
        self::assertNull($this->registry->current());
    }

    public function testRecordWebsiteAlonePersistsAPartialIdentity(): void
    {
        $this->registry->recordWebsite('website-1', 'Example');

        $state = $this->registry->current();
        self::assertNotNull($state);
        self::assertSame('website-1', $state->websiteId);
        self::assertNull($state->ipnsKey);
        self::assertNull($state->ipnsKeyId);

        $saved = $this->gateway->options[WordPressPublishRegistry::OPTION_KEY];
        self::assertIsArray($saved);
        self::assertSame(PublishIdentity::SCHEMA_VERSION, $saved['schema_version']);
        self::assertFalse($saved['ready']);
        self::assertSame(['id' => 'website-1', 'name' => 'Example'], $saved['website']);

        // A partial identity is never available for auto-exports.
        self::assertFalse($this->identity->hasIdentity());
    }

    public function testIpnsKeyCompletesTheIdentityAndBecomesVisibleThroughTheIdentityGateway(): void
    {
        $this->registry->recordWebsite('website-1', 'Example');
        $this->registry->recordIpnsKey('Key One', 'key-1');

        $state = $this->registry->current();
        self::assertNotNull($state);
        self::assertSame('website-1', $state->websiteId);
        self::assertSame('Key One', $state->ipnsKey);
        // The numeric key id recorded with the key is preserved through the
        // SitePublishState so a real adapter can resolve name -> id at publish.
        self::assertSame('key-1', $state->ipnsKeyId);

        self::assertTrue($this->identity->hasIdentity());
        $current = $this->identity->current();
        self::assertNotNull($current);
        self::assertTrue($current->isReady());
        self::assertSame('website-1', $current->websiteId);
        self::assertSame('Example', $current->websiteName);
        self::assertSame('key-1', $current->ipnsKeyId);
        self::assertSame('Key One', $current->ipnsKeyName);
    }

    public function testReadyIdentityRoundTripsLosslesslyThroughPublishIdentityConventions(): void
    {
        $this->registry->recordWebsite('website-1', 'Example');
        $this->registry->recordIpnsKey('Key One', 'key-1');

        $identity = PublishIdentity::fromOptionValue($this->gateway->options[WordPressPublishRegistry::OPTION_KEY]);

        self::assertNotNull($identity);
        self::assertTrue($identity->isReady());
        self::assertSame('website-1', $identity->websiteId);
        self::assertSame('Example', $identity->websiteName);
        self::assertSame('key-1', $identity->ipnsKeyId);
        self::assertSame('Key One', $identity->ipnsKeyName);
        self::assertSame(
            $identity->toOptionValue(),
            PublishIdentity::fromOptionValue($identity->toOptionValue())?->toOptionValue(),
        );
    }

    public function testIdentityIsNotReadyUntilBothHalvesAreComplete(): void
    {
        $this->registry->recordWebsite('website-1', 'Example');
        self::assertFalse($this->identity->hasIdentity());

        // The IPNS half needs both a non-empty name and a non-empty id.
        $this->registry->recordIpnsKey('Key One', null);
        self::assertFalse($this->identity->hasIdentity());

        $this->registry->recordIpnsKey('Key One', 'key-1');
        self::assertTrue($this->identity->hasIdentity());
    }

    public function testIpnsKeyCanBeRecordedBeforeTheWebsite(): void
    {
        $this->registry->recordIpnsKey('Key One', 'key-1');
        $state = $this->registry->current();
        self::assertNotNull($state);
        self::assertSame('Key One', $state->ipnsKey);
        self::assertSame('key-1', $state->ipnsKeyId);
        self::assertNull($state->websiteId);

        $this->registry->recordWebsite('website-1', 'Example');

        self::assertTrue($this->identity->hasIdentity());
        $state = $this->registry->current();
        self::assertNotNull($state);
        self::assertSame('website-1', $state->websiteId);
        self::assertSame('Key One', $state->ipnsKey);
    }

    public function testIpnsKeyAloneSurfacesAPartialOwnedState(): void
    {
        // Regression (real Kody finding on WordPressPublishRegistry::current()):
        // an IPNS-only partial record (website half not yet written) must still
        // surface through current() so a resumed publish never re-creates the
        // existing key — the write order records the IPNS key before the website.
        $this->registry->recordIpnsKey('Key One', 'key-1');

        $state = $this->registry->current();
        self::assertNotNull($state);
        self::assertSame('Key One', $state->ipnsKey);
        self::assertSame('key-1', $state->ipnsKeyId);
        self::assertNull($state->websiteId);
        self::assertFalse($state->isNew());

        // Still not a ready identity through the gateway until both halves exist.
        self::assertFalse($this->identity->hasIdentity());
    }

    /**
     * @param mixed $corrupt
     */
    #[DataProvider('corruptOptionValues')]
    public function testCorruptOrMalformedPayloadReadsAsNoOwnedState(mixed $corrupt): void
    {
        $this->gateway->options[WordPressPublishRegistry::OPTION_KEY] = $corrupt;

        self::assertNull($this->registry->current());
        self::assertFalse($this->identity->hasIdentity());
    }

    public function testWritingAfterCorruptStateOverwritesWithAHealthyValue(): void
    {
        $this->gateway->options[WordPressPublishRegistry::OPTION_KEY] = ['schema_version' => 99, 'ready' => true];

        $this->registry->recordWebsite('website-1', 'Example');
        $this->registry->recordIpnsKey('Key One', 'key-1');

        self::assertTrue($this->identity->hasIdentity());
        $saved = $this->gateway->options[WordPressPublishRegistry::OPTION_KEY];
        self::assertIsArray($saved);
        self::assertSame(PublishIdentity::SCHEMA_VERSION, $saved['schema_version']);
        self::assertTrue($saved['ready']);
    }

    /**
     * Full first-publish through the real orchestration: once PublishService
     * records the created website and IPNS key into the concrete registry, the
     * full ready identity becomes visible through the identity gateway reading
     * the same option store — website name retained from the created website,
     * IPNS key id retained from the fake client.
     */
    public function testFirstPublishThroughTheServicePersistsAReadyIdentityVisibleThroughTheIdentityGateway(): void
    {
        $uploads = new FakeUploadClient([new UploadResult(UploadStatus::Completed, cid: 'QmHash')]);
        $websites = new FakeWebsiteClient();
        $service = new PublishService(
            new UploadRouter(),
            new UploadWaiter($uploads, new FakePublishClock()),
            $websites,
            new FakeIpnsClient(),
            $this->registry,
            readiness: new WebsiteReadinessWaiter($websites, new FakePublishClock()),
        );

        $service->publish(new Artifact('/tmp/cast-export/run-abc-123.zip', 'run-abc-123.zip', 'ipfs-dir', 'example.com', 2048));

        self::assertTrue($this->identity->hasIdentity());
        $current = $this->identity->current();
        self::assertNotNull($current);
        self::assertTrue($current->isReady());
        self::assertSame('website-1', $current->websiteId);
        self::assertSame('example.com', $current->websiteName);
        self::assertSame('key-1', $current->ipnsKeyId);
        self::assertSame('k1-example.com', $current->ipnsKeyName);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function corruptOptionValues(): array
    {
        return [
            'non array' => ['not-an-identity'],
            'unknown schema' => [['schema_version' => 99, 'ready' => true]],
            'website not array and no ipns' => [['schema_version' => 1, 'website' => 'website-1']],
            'incomplete website and missing ipns' => [['schema_version' => 1, 'website' => ['name' => 'Example']]],
            'incomplete website and nameless ipns' => [['schema_version' => 1, 'website' => ['id' => ''], 'ipns_key' => ['id' => 'key-1']]],
        ];
    }

    public function testACompleteIpnsKeyWithAnUnreadableWebsiteHalfStillSurfacesPartialState(): void
    {
        // A broken/unreadable website half must not hide a complete IPNS key:
        // the resumed publish still owns the key and must not re-create it.
        $this->gateway->options[WordPressPublishRegistry::OPTION_KEY] = [
            'schema_version' => 1,
            'website' => 'not-an-array',
            'ipns_key' => ['id' => 'key-1', 'name' => 'Key One'],
        ];

        $state = $this->registry->current();
        self::assertNotNull($state);
        self::assertSame('Key One', $state->ipnsKey);
        self::assertSame('key-1', $state->ipnsKeyId);
        self::assertNull($state->websiteId);
    }
}
