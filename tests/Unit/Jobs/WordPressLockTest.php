<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Jobs;

use LumeWeb\Cast\Jobs\FixedClock;
use LumeWeb\Cast\Jobs\WordPressLock;
use LumeWeb\Cast\Persistence\OptionGateway;
use LumeWeb\Cast\Tests\Unit\Persistence\FakeOptionGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WordPressLockTest extends TestCase
{
    private FakeOptionGateway $gateway;

    private FixedClock $clock;

    private WordPressLock $lock;

    protected function setUp(): void
    {
        $this->gateway = new FakeOptionGateway();
        $this->clock = new FixedClock(1000);
        $this->lock = new WordPressLock($this->gateway, $this->clock);
    }

    public function testUsesADeterministicNonReservedOptionPrefix(): void
    {
        self::assertSame('cast_lease_', WordPressLock::OPTION_PREFIX);
    }

    public function testAcquireTakesAFreshLease(): void
    {
        self::assertTrue($this->lock->acquire('run', 60));

        self::assertTrue($this->lock->isHeld('run'));
        self::assertSame(1060, $this->lock->leaseExpiresAt('run'));
        self::assertCount(1, $this->gateway->options);
    }

    public function testFreshLeaseIsClaimedThroughTheAtomicCreatePath(): void
    {
        // A free (absent) slot is claimed through the create-if-absent add(),
        // never a blind update() (or a compare-and-set on a value that was
        // never read), so a concurrent claim cannot overwrite it.
        self::assertTrue($this->lock->acquire('run', 60));

        self::assertSame(1, $this->gateway->addCalls);
        self::assertSame(0, $this->gateway->updateCalls);
        self::assertSame(0, $this->gateway->updateIfEqualsCalls);
        self::assertTrue($this->lock->isHeld('run'));
    }

    public function testTwoWorkersReadingTheSlotAsFreeOnlyOneAtomicClaimWins(): void
    {
        // Simulates two workers that BOTH observe the slot as free (absent)
        // before either claims it: get() always reports the default, while
        // add() still enforces add_option() create-if-absent semantics. Exactly
        // one claim must land — the loser's add() no-ops instead of
        // overwriting the winner's fresh lease (the old read-check-write let
        // both win).
        $racing = new class implements OptionGateway {
            /**
             * @var array<string, mixed>
             */
            public array $options = [];

            public int $addCalls = 0;

            public int $updateCalls = 0;

            public int $updateIfEqualsCalls = 0;

            public function get(string $option, mixed $default): mixed
            {
                return $default;
            }

            public function add(string $option, mixed $value, bool $autoload): bool
            {
                ++$this->addCalls;
                if (array_key_exists($option, $this->options)) {
                    return false;
                }
                $this->options[$option] = $value;

                return true;
            }

            public function update(string $option, mixed $value, bool $autoload): bool
            {
                ++$this->updateCalls;
                $this->options[$option] = $value;

                return true;
            }

            public function updateIfEquals(string $option, mixed $value, mixed $expected): bool
            {
                ++$this->updateIfEqualsCalls;

                if (!array_key_exists($option, $this->options) || $this->options[$option] !== $expected) {
                    return false;
                }

                $this->options[$option] = $value;

                return true;
            }

            public function delete(string $option): bool
            {
                unset($this->options[$option]);

                return true;
            }
        };

        $first = new WordPressLock($racing, $this->clock);
        $second = new WordPressLock($racing, $this->clock);

        self::assertTrue($first->acquire('run', 60));
        self::assertFalse($second->acquire('run', 60));
        // Both workers raced the atomic add(); exactly one won (the second's
        // add() was refused) and neither fell back to the non-atomic update
        // nor to a compare-and-set on a value neither worker had read.
        self::assertSame(2, $racing->addCalls);
        self::assertSame(0, $racing->updateCalls);
        self::assertSame(0, $racing->updateIfEqualsCalls);

        $lease = $racing->options[WordPressLock::OPTION_PREFIX . 'run'];
        self::assertIsArray($lease);
        self::assertIsString($lease['token'] ?? null);
        self::assertSame(1060, $lease['expires_at'] ?? null);
    }

    public function testTwoWorkersRacingAnExpiredLeaseExactlyOneReclaims(): void
    {
        // Both workers observe the SAME expired lease (get() pins the stale
        // snapshot they both read), then both race the reclaim. The
        // compare-and-set is serialized against the actually stored value: the
        // first CAS lands and the second sees the stored value no longer equals
        // what it read, so exactly one worker can ever own the reclaimed lease.
        $racing = new class implements OptionGateway {
            /**
             * @var array<string, mixed>
             */
            public array $options = [];

            /**
             * @var array<string, mixed>|null
             */
            public ?array $observed = null;

            public int $updateIfEqualsCalls = 0;

            public int $updateCalls = 0;

            public function get(string $option, mixed $default): mixed
            {
                return $this->observed ?? $default;
            }

            public function add(string $option, mixed $value, bool $autoload): bool
            {
                if (array_key_exists($option, $this->options)) {
                    return false;
                }
                $this->options[$option] = $value;

                return true;
            }

            public function update(string $option, mixed $value, bool $autoload): bool
            {
                ++$this->updateCalls;
                $this->options[$option] = $value;

                return true;
            }

            public function updateIfEquals(string $option, mixed $value, mixed $expected): bool
            {
                ++$this->updateIfEqualsCalls;

                if (!array_key_exists($option, $this->options) || $this->options[$option] !== $expected) {
                    return false;
                }

                $this->options[$option] = $value;

                return true;
            }

            public function delete(string $option): bool
            {
                unset($this->options[$option]);

                return true;
            }
        };

        /** @var array<string, mixed> $stale */
        $stale = ['token' => 'stale-token', 'expires_at' => 900, 'acquired_at' => 800];
        $racing->observed = $stale;
        $racing->options[WordPressLock::OPTION_PREFIX . 'run'] = $stale;

        $first = new WordPressLock($racing, $this->clock);
        $second = new WordPressLock($racing, $this->clock);

        // Both workers raced the same expired snapshot; the first reclaim lands.
        self::assertTrue($first->acquire('run', 60));
        self::assertFalse($second->acquire('run', 60));

        // Both workers attempted a compare-and-set; exactly one succeeded and
        // the loser never fell back to a blind update() that could clobber the
        // winner's fresh lease.
        self::assertSame(2, $racing->updateIfEqualsCalls);
        self::assertSame(0, $racing->updateCalls);

        $lease = $racing->options[WordPressLock::OPTION_PREFIX . 'run'];
        self::assertIsArray($lease);
        self::assertIsString($lease['token'] ?? null);
        self::assertNotSame('stale-token', $lease['token'] ?? null);
        self::assertSame(1060, $lease['expires_at'] ?? null);
    }

    public function testLiveLeaseIsRefusedWithoutAnyWrite(): void
    {
        $this->lock->acquire('run', 60);

        $other = new WordPressLock($this->gateway, $this->clock);
        self::assertFalse($other->acquire('run', 60));

        // A live lease is rejected on the read alone: no compare-and-set, no
        // blind update and no delete can touch another worker's active lease.
        self::assertSame(1, $this->gateway->addCalls);
        self::assertSame(0, $this->gateway->updateCalls);
        self::assertSame(0, $this->gateway->updateIfEqualsCalls);
        self::assertSame(0, $this->gateway->deleteCalls);
        self::assertTrue($this->lock->isHeld('run'));
    }

    public function testReclaimWinnerOwnsItsFreshTokenWhileTheRacingLoserGetsFalse(): void
    {
        $this->gateway->options[WordPressLock::OPTION_PREFIX . 'run'] = [
            'token' => 'expired-token',
            'expires_at' => 900,
            'acquired_at' => 800,
        ];

        self::assertTrue($this->lock->acquire('run', 60));

        $stored = $this->gateway->options[WordPressLock::OPTION_PREFIX . 'run'];
        self::assertIsArray($stored);
        self::assertNotSame('expired-token', $stored['token'] ?? null);
        self::assertIsString($stored['token'] ?? null);
        self::assertSame(1060, $stored['expires_at'] ?? null);
        self::assertTrue($this->lock->isHeld('run'));

        // A worker that reads the fresh live lease after the reclaim only
        // loses: the live guard returns first, so no further CAS or other
        // write reaches the store.
        $other = new WordPressLock($this->gateway, $this->clock);
        self::assertFalse($other->acquire('run', 60));
        self::assertSame(1, $this->gateway->updateIfEqualsCalls);
        self::assertSame(0, $this->gateway->updateCalls);
        self::assertSame(0, $this->gateway->deleteCalls);
    }

    public function testAcquireStoresANonAutoloadedOwnedLease(): void
    {
        $this->lock->acquire('run', 60);

        $stored = $this->gateway->options[WordPressLock::OPTION_PREFIX . 'run'];
        self::assertIsArray($stored);
        self::assertFalse($this->gateway->autoload[WordPressLock::OPTION_PREFIX . 'run']);
        self::assertIsString($stored['token']);
        self::assertSame(32, strlen($stored['token']));
        self::assertSame(1060, $stored['expires_at']);
        self::assertSame(1000, $stored['acquired_at']);
    }

    public function testSecondAcquireFailsWhileHeld(): void
    {
        $this->lock->acquire('run', 60);

        self::assertFalse($this->lock->acquire('run', 60));
    }

    public function testLeaseExpiresAfterTheTtlElapses(): void
    {
        $this->lock->acquire('run', 60);

        $this->clock->advance(60);

        self::assertFalse($this->lock->isHeld('run'));
    }

    public function testStaleLeaseStillReportsItsPastExpiryForDetection(): void
    {
        $this->lock->acquire('run', 60);
        $this->clock->advance(61);

        self::assertSame(1060, $this->lock->leaseExpiresAt('run'));
    }

    public function testExpiredLeaseCanBeAcquiredAgain(): void
    {
        $this->lock->acquire('run', 60);
        $this->clock->advance(61);

        self::assertTrue($this->lock->acquire('run', 60));
        self::assertSame(1121, $this->lock->leaseExpiresAt('run'));
    }

    public function testReleaseFreesTheLeaseImmediately(): void
    {
        $this->lock->acquire('run', 60);

        $this->lock->release('run');

        self::assertFalse($this->lock->isHeld('run'));
        self::assertNull($this->lock->leaseExpiresAt('run'));
        self::assertArrayNotHasKey(WordPressLock::OPTION_PREFIX . 'run', $this->gateway->options);
    }

    public function testReleaseIsSafeWhenNothingIsHeld(): void
    {
        $this->lock->release('run');
        $this->lock->release('run');

        self::assertFalse($this->lock->isHeld('run'));
        self::assertSame(0, $this->gateway->deleteCalls);
    }

    public function testAWorkerThatNeverAcquiredCannotDeleteAnotherWorkersLiveLease(): void
    {
        $other = new WordPressLock($this->gateway, $this->clock);
        $this->lock->acquire('run', 60);
        $stored = $this->gateway->options[WordPressLock::OPTION_PREFIX . 'run'];

        $other->release('run');

        self::assertTrue($this->lock->isHeld('run'));
        self::assertSame($stored, $this->gateway->options[WordPressLock::OPTION_PREFIX . 'run']);
        self::assertSame(0, $this->gateway->deleteCalls);
    }

    public function testReleaseOnlyDeletesWhenTheCallerOwnsTheLease(): void
    {
        $other = new WordPressLock($this->gateway, $this->clock);
        $this->lock->acquire('run', 60);

        // The non-owner fails to acquire and must not be able to release either.
        self::assertFalse($other->acquire('run', 60));
        $other->release('run');

        self::assertTrue($this->lock->isHeld('run'));
        self::assertSame(0, $this->gateway->deleteCalls);

        // The owner's release removes the lease.
        $this->lock->release('run');
        self::assertFalse($this->lock->isHeld('run'));
        self::assertSame(1, $this->gateway->deleteCalls);
    }

    public function testUntruncatedReleaseLeavesAStaleReclaimedLeaseIntact(): void
    {
        $other = new WordPressLock($this->gateway, $this->clock);
        $this->lock->acquire('run', 60);

        // The lease goes stale; a different worker reclaims it with its own token.
        $this->clock->advance(61);
        self::assertTrue($other->acquire('run', 60));
        self::assertTrue($other->isHeld('run'));

        // The original owner's late release must not delete the new owner's lease.
        $this->lock->release('run');

        self::assertTrue($other->isHeld('run'));
        self::assertArrayHasKey(WordPressLock::OPTION_PREFIX . 'run', $this->gateway->options);

        // The new owner can still release normally.
        $other->release('run');
        self::assertFalse($other->isHeld('run'));
    }

    public function testLeaseExpiryIsScopedPerKey(): void
    {
        $this->lock->acquire('a', 60);
        self::assertTrue($this->lock->acquire('b', 60));

        $this->lock->release('a');

        self::assertFalse($this->lock->isHeld('a'));
        self::assertTrue($this->lock->isHeld('b'));
    }

    public function testLeaseExpiresAtIsNullBeforeAnyAcquire(): void
    {
        self::assertNull($this->lock->leaseExpiresAt('run'));
    }

    public function testAcquireRestartsTheTtlFromNowWhenReacquiring(): void
    {
        $this->lock->acquire('run', 60);
        $this->clock->advance(61);
        $this->lock->acquire('run', 60);

        self::assertSame(1121, $this->lock->leaseExpiresAt('run'));
        $this->clock->advance(59);
        self::assertTrue($this->lock->isHeld('run'));
    }

    /**
     * @param mixed $corrupt
     */
    #[DataProvider('corruptLeaseValues')]
    public function testCorruptStoredLeaseIsTreatedAsFreeAndReacquirable(mixed $corrupt): void
    {
        $this->gateway->options[WordPressLock::OPTION_PREFIX . 'run'] = $corrupt;

        self::assertFalse($this->lock->isHeld('run'));
        self::assertNull($this->lock->leaseExpiresAt('run'));
        self::assertTrue($this->lock->acquire('run', 60));
        self::assertTrue($this->lock->isHeld('run'));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function corruptLeaseValues(): array
    {
        return [
            'string' => ['not-a-lease'],
            'array missing token' => [['expires_at' => 9999]],
            'non string token' => [['token' => 42, 'expires_at' => 9999]],
            'missing expires_at' => [['token' => 'abc', 'expires_at' => 'soon']],
        ];
    }
}
