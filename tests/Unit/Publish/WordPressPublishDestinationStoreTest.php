<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use LumeWeb\Cast\Publish\PublishDestination;
use LumeWeb\Cast\Publish\PublishDestinationLifecycle;
use LumeWeb\Cast\Publish\WordPressPublishDestinationStore;
use LumeWeb\Cast\Tests\Unit\Persistence\FakeOptionGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WordPressPublishDestinationStoreTest extends TestCase
{
    private FakeOptionGateway $options;

    protected function setUp(): void
    {
        $this->options = new FakeOptionGateway();
    }

    public function testAbsentOptionReadsAsNoSetup(): void
    {
        $store = new WordPressPublishDestinationStore($this->options);

        self::assertNull($store->read());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function corruptStoredValues(): iterable
    {
        yield 'non-array value' => ['not-an-array'];
        yield 'missing destination' => [['lifecycle' => 'draft']];
        yield 'unknown destination source' => [
            ['destination' => ['source' => 'build-host'], 'lifecycle' => 'draft'],
        ];
        yield 'unknown lifecycle' => [
            [
                'destination' => ['source' => 'existing', 'website_id' => '42'],
                'lifecycle' => 'deployed',
            ],
        ];
    }

    /**
     * @param mixed $value
     */
    #[DataProvider('corruptStoredValues')]
    public function testCorruptStoredValueReadsAsNoSetup(mixed $value): void
    {
        $this->options->add(WordPressPublishDestinationStore::OPTION_KEY, $value, false);
        $store = new WordPressPublishDestinationStore($this->options);

        self::assertNull($store->read());
    }

    public function testSaveDraftPersistsAReadableRoundTripInANonAutoloadedOption(): void
    {
        $store = new WordPressPublishDestinationStore($this->options);
        $destination = PublishDestination::custom('example.com', 'icann', false);

        $store->saveDraft($destination);

        $state = $store->read();

        self::assertNotNull($state);
        self::assertEquals($destination, $state->destination);
        self::assertSame(PublishDestinationLifecycle::Draft, $state->lifecycle);
        self::assertFalse($this->options->autoload[WordPressPublishDestinationStore::OPTION_KEY] ?? true);
    }

    public function testSaveDraftIsRejectedOnceConfirmedWithADifferentDestination(): void
    {
        $store = new WordPressPublishDestinationStore($this->options);
        $original = PublishDestination::custom('example.com', 'icann', true);
        $store->saveDraft($original);
        $store->confirm($original);

        $this->expectException(\InvalidArgumentException::class);

        $store->saveDraft(PublishDestination::platformGenerated());
    }

    public function testConfirmMovesDraftToConfirmed(): void
    {
        $store = new WordPressPublishDestinationStore($this->options);
        $destination = PublishDestination::platformLabelled('my-shop', 'pinned.site', 'icann');
        $store->saveDraft($destination);

        $store->confirm($destination);

        $state = $store->read();
        self::assertNotNull($state);
        self::assertSame(PublishDestinationLifecycle::Confirmed, $state->lifecycle);
        self::assertEquals($destination, $state->destination);
    }

    public function testConfirmIsIdempotentForAnUnchangedConfirmedDestination(): void
    {
        $store = new WordPressPublishDestinationStore($this->options);
        $destination = PublishDestination::existing('42');
        $store->saveDraft($destination);
        $store->confirm($destination);

        $store->confirm($destination);

        $state = $store->read();
        self::assertNotNull($state);
        self::assertSame(PublishDestinationLifecycle::Confirmed, $state->lifecycle);
        self::assertEquals($destination, $state->destination);
    }

    public function testConfirmIsRejectedForAChangedConfirmedDestination(): void
    {
        $store = new WordPressPublishDestinationStore($this->options);
        $original = PublishDestination::custom('example.com', 'icann', true);
        $store->saveDraft($original);
        $store->confirm($original);

        $this->expectException(\InvalidArgumentException::class);

        $store->confirm(PublishDestination::custom('shop.example.com', 'icann', false));
    }

    public function testMarkCreatedOrAttachedMovesConfirmedToTheTerminalLifecycle(): void
    {
        $store = new WordPressPublishDestinationStore($this->options);
        $destination = PublishDestination::existing('42');
        $store->saveDraft($destination);
        $store->confirm($destination);

        $store->markCreatedOrAttached();

        $state = $store->read();
        self::assertNotNull($state);
        self::assertSame(PublishDestinationLifecycle::CreatedOrAttached, $state->lifecycle);
        self::assertEquals($destination, $state->destination);
    }

    public function testDestinationCannotBeOverwrittenOnceCreatedOrAttached(): void
    {
        $store = new WordPressPublishDestinationStore($this->options);
        $original = PublishDestination::custom('example.com', 'hns', true);
        $store->saveDraft($original);
        $store->confirm($original);
        $store->markCreatedOrAttached();

        $this->expectException(\InvalidArgumentException::class);

        $store->saveDraft(PublishDestination::platformGenerated());
    }

    public function testConfirmCannotChangeAOnceCreatedOrAttachedDestination(): void
    {
        $store = new WordPressPublishDestinationStore($this->options);
        $original = PublishDestination::custom('example.com', 'hns', true);
        $store->saveDraft($original);
        $store->confirm($original);
        $store->markCreatedOrAttached();

        $this->expectException(\InvalidArgumentException::class);

        $store->confirm(PublishDestination::existing('42'));
    }

    public function testMarkCreatedOrAttachedIsIdempotent(): void
    {
        $store = new WordPressPublishDestinationStore($this->options);
        $destination = PublishDestination::platformGenerated();
        $store->saveDraft($destination);
        $store->confirm($destination);
        $store->markCreatedOrAttached();

        $store->markCreatedOrAttached();

        $state = $store->read();
        self::assertNotNull($state);
        self::assertSame(PublishDestinationLifecycle::CreatedOrAttached, $state->lifecycle);
    }

    public function testMarkCreatedOrAttachedIsRejectedWithoutAConfirmedDestination(): void
    {
        $store = new WordPressPublishDestinationStore($this->options);
        $store->saveDraft(PublishDestination::existing('42'));

        $this->expectException(\InvalidArgumentException::class);

        $store->markCreatedOrAttached();
    }
}
