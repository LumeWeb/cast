<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Jobs\InMemoryPublishModeStore;
use LumeWeb\Cast\Jobs\PublishMode;
use LumeWeb\Cast\Jobs\WordPressPublishModeStore;
use LumeWeb\Cast\Tests\Unit\Persistence\FakeOptionGateway;
use PHPUnit\Framework\TestCase;

final class PublishModeStoreTest extends TestCase
{
    /* ------------------------- in-memory store ------------------------- */

    public function testInMemoryStoreDefaultsToManual(): void
    {
        $store = new InMemoryPublishModeStore();

        self::assertSame(PublishMode::Manual, $store->mode());
    }

    public function testInMemoryStoreAcceptsAnExplicitInitialMode(): void
    {
        $store = new InMemoryPublishModeStore(PublishMode::OnUpdate);

        self::assertSame(PublishMode::OnUpdate, $store->mode());
    }

    public function testInMemoryStorePersistsSetMode(): void
    {
        $store = new InMemoryPublishModeStore();

        $store->setMode(PublishMode::OnUpdate);

        self::assertSame(PublishMode::OnUpdate, $store->mode());
    }

    /* ----------------------- wordpress option store ----------------------- */

    public function testWordPressStoreReadsThePersistedModeOption(): void
    {
        self::assertSame('cast_publish_mode', WordPressPublishModeStore::OPTION_KEY);

        $options = new FakeOptionGateway();
        $store = new WordPressPublishModeStore($options);

        $options->options[WordPressPublishModeStore::OPTION_KEY] = 'on_update';

        self::assertSame(PublishMode::OnUpdate, $store->mode());
    }

    public function testWordPressStoreDefaultsToManualWhenOptionIsMissing(): void
    {
        $store = new WordPressPublishModeStore(new FakeOptionGateway());

        self::assertSame(PublishMode::Manual, $store->mode());
    }

    /**
     * @param mixed $corrupt
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('corruptOptionValues')]
    public function testWordPressStoreFallsBackToManualOnCorruptOption(mixed $corrupt): void
    {
        $options = new FakeOptionGateway();
        $options->options[WordPressPublishModeStore::OPTION_KEY] = $corrupt;

        self::assertSame(PublishMode::Manual, (new WordPressPublishModeStore($options))->mode());
    }

    public function testWordPressStoreSetModeWritesNonAutoloadedOption(): void
    {
        $options = new FakeOptionGateway();
        $store = new WordPressPublishModeStore($options);

        $store->setMode(PublishMode::OnUpdate);

        self::assertSame(1, $options->updateCalls);
        self::assertSame('on_update', $options->options[WordPressPublishModeStore::OPTION_KEY]);
        self::assertFalse($options->autoload[WordPressPublishModeStore::OPTION_KEY]);
    }

    public function testWordPressStoreMigratesLegacyScheduledOptionToOnUpdate(): void
    {
        // A site that had the retired Scheduled mode configured (auto-scheduling
        // like On-update) keeps that behavior: the stored 'scheduled' value
        // reads back as On-update, never as Manual.
        $options = new FakeOptionGateway();
        $options->options[WordPressPublishModeStore::OPTION_KEY] = 'scheduled';

        self::assertSame(PublishMode::OnUpdate, (new WordPressPublishModeStore($options))->mode());
    }

    public function testWordPressStoreSetModeIsReadableBack(): void
    {
        $options = new FakeOptionGateway();
        $store = new WordPressPublishModeStore($options);

        $store->setMode(PublishMode::OnUpdate);

        self::assertSame(PublishMode::OnUpdate, $store->mode());
    }

    public function testReadingAModeNeverWrites(): void
    {
        $options = new FakeOptionGateway();
        $store = new WordPressPublishModeStore($options);

        $store->mode();

        self::assertSame(0, $options->addCalls);
        self::assertSame(0, $options->updateCalls);
        self::assertSame(0, $options->deleteCalls);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function corruptOptionValues(): array
    {
        return [
            'non string' => [42],
            'unknown value' => ['nightly'],
            'array' => [['mode' => 'manual']],
        ];
    }
}
