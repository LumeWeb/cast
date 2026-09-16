<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Onboarding;

use Finite\StateMachine;
use Finite\Transition\Transition;
use LumeWeb\Cast\Onboarding\Wizard;
use LumeWeb\Cast\Onboarding\WizardState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WizardStateTest extends TestCase
{
    #[DataProvider('knownStateProvider')]
    public function testFromStoredMapsKnownValue(string $value, WizardState $expected): void
    {
        self::assertSame($expected, WizardState::fromStored($value));
    }

    /**
     * @return iterable<string, array{string, WizardState}>
     */
    public static function knownStateProvider(): iterable
    {
        yield 'not_started' => ['not_started', WizardState::NotStarted];
        yield 'building' => ['building', WizardState::Building];
        yield 'skipped' => ['skipped', WizardState::Skipped];
        yield 'completed' => ['completed', WizardState::Completed];
    }

    #[DataProvider('unrecognizedProvider')]
    public function testFromStoredDefaultsToNotStartedForUnrecognizedValue(mixed $value): void
    {
        self::assertSame(WizardState::NotStarted, WizardState::fromStored($value));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unrecognizedProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'null' => [null];
        yield 'legacy in_progress' => ['in_progress'];
        yield 'random text' => ['bogus'];
        yield 'non-string' => [42];
    }

    public function testGraphDeclaresEveryTransitionOnce(): void
    {
        $names = array_map(
            static fn (Transition $transition): string => $transition->getName(),
            WizardState::getTransitions(),
        );

        self::assertSame([
            'start',
            'skip',
            'select_builder',
            'reset_builder',
            'install_success',
            'install_failure',
            'activate_success',
            'activate_failure',
            'complete',
            'reopen',
        ], $names);
    }

    /** @return list<string> */
    private function availableTransitions(WizardState $state): array
    {
        $machine = new StateMachine();
        $wizard = new Wizard($state);

        return array_values(array_map(
            static fn (Transition $transition): string => $transition->getName(),
            $machine->getReachablesTransitions($wizard),
        ));
    }

    public function testNotStartedCanStartOrSkipButNotComplete(): void
    {
        self::assertSame(['start', 'skip'], $this->availableTransitions(WizardState::NotStarted));
    }

    public function testBuildingCanSelectResetInstallActivateCompleteAndSkip(): void
    {
        self::assertSame([
            'skip',
            'select_builder',
            'reset_builder',
            'install_success',
            'install_failure',
            'activate_success',
            'activate_failure',
            'complete',
        ], $this->availableTransitions(WizardState::Building));
    }

    public function testSkippedCanOnlyReopen(): void
    {
        self::assertSame(['reopen'], $this->availableTransitions(WizardState::Skipped));
    }

    public function testCompletedCanOnlyReopen(): void
    {
        self::assertSame(['reopen'], $this->availableTransitions(WizardState::Completed));
    }

    public function testSelectBuilderIsASelfLoopThatKeepsBuildingState(): void
    {
        $machine = new StateMachine();
        $wizard = new Wizard(WizardState::Building);

        self::assertTrue($machine->can($wizard, 'select_builder'));
        $machine->apply($wizard, 'select_builder');

        self::assertSame(WizardState::Building, $wizard->state);
    }

    public function testCompleteTransitionFromBuildingReachesTerminalCompleted(): void
    {
        $machine = new StateMachine();
        $wizard = new Wizard(WizardState::Building);

        $machine->apply($wizard, 'complete');

        self::assertSame(WizardState::Completed, $wizard->state);
        self::assertFalse($machine->can($wizard, 'complete'));
    }
}
