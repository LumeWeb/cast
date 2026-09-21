<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Jobs\PublishIdentity;
use LumeWeb\Cast\Jobs\WordPressIdentityGateway;
use LumeWeb\Cast\Tests\Unit\Persistence\FakeOptionGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WordPressIdentityGatewayTest extends TestCase
{
    private FakeOptionGateway $gateway;

    private WordPressIdentityGateway $identity;

    protected function setUp(): void
    {
        $this->gateway = new FakeOptionGateway();
        $this->identity = new WordPressIdentityGateway($this->gateway);
    }

    public function testReadsFromTheTypedNonAutoloadedPublishIdentityOption(): void
    {
        self::assertSame('cast_publish_identity', WordPressIdentityGateway::OPTION_KEY);
    }

    public function testMissingOptionMeansNoIdentity(): void
    {
        self::assertFalse($this->identity->hasIdentity());
    }

    public function testReadyIdentityOptionIsAccepted(): void
    {
        $this->gateway->options[WordPressIdentityGateway::OPTION_KEY] = $this->readyOption();

        self::assertTrue($this->identity->hasIdentity());
    }

    public function testNotReadyIdentityIsNotAvailableForPublishing(): void
    {
        $option = $this->readyOption();
        $option['ready'] = false;
        $this->gateway->options[WordPressIdentityGateway::OPTION_KEY] = $option;

        self::assertFalse($this->identity->hasIdentity());
    }

    /**
     * @param mixed $corrupt
     */
    #[DataProvider('corruptOptionValues')]
    public function testCorruptOrIncompleteOptionMeansNoIdentity(mixed $corrupt): void
    {
        $this->gateway->options[WordPressIdentityGateway::OPTION_KEY] = $corrupt;

        self::assertFalse($this->identity->hasIdentity());
    }

    public function testReadingAnIdentityNeverWrites(): void
    {
        $this->gateway->options[WordPressIdentityGateway::OPTION_KEY] = $this->readyOption();

        $this->identity->hasIdentity();

        self::assertSame(0, $this->gateway->addCalls);
        self::assertSame(0, $this->gateway->updateCalls);
        self::assertSame(0, $this->gateway->deleteCalls);
    }

    /**
     * @return array<string, mixed>
     */
    private function readyOption(): array
    {
        return [
            'schema_version' => PublishIdentity::SCHEMA_VERSION,
            'ready' => true,
            'website' => ['id' => 'web-42', 'name' => 'My Blog'],
            'ipns_key' => ['id' => 'k-ipns-7', 'name' => 'cast-live'],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function corruptOptionValues(): array
    {
        return [
            'non array' => ['not-a-identity'],
            'unknown schema' => [['schema_version' => 99, 'ready' => true]],
            'missing website' => [['schema_version' => 1, 'ready' => true]],
            'missing ipns key' => [['schema_version' => 1, 'ready' => true, 'website' => ['id' => 'web-42', 'name' => 'My Blog']]],
            'empty website id' => [['schema_version' => 1, 'ready' => true, 'website' => ['id' => '', 'name' => 'My Blog']]],
            'empty ipns name' => [['schema_version' => 1, 'ready' => true, 'website' => ['id' => 'web-42', 'name' => 'My Blog'], 'ipns_key' => ['id' => 'k-1', 'name' => '']]],
        ];
    }
}
