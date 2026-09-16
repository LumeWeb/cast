<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Onboarding;

use Finite\State;
use Finite\Transition\Transition;

/**
 * The lifecycle states of the wizard aggregate, driven by the Finite 2.x
 * state machine.
 *
 * This enum is both the persisted state identifier and the Finite State graph
 * node: each case declares the transitions legal from it via
 * Finite\State::getTransitions(). NotStarted and Building are live; Skipped
 * and Completed settle the flow, but both may be restarted through the
 * `reopen` transition (returning to Building with the selection cleared) so the
 * wizard can be run again.
 *
 * Invalid or missing persisted values normalize to NotStarted so unknown or
 * malformed data cannot wedge the flow.
 */
enum WizardState: string implements State
{
    case NotStarted = 'not_started';
    case Building = 'building';
    case Skipped = 'skipped';
    case Completed = 'completed';

    /**
     * The complete transition graph. Finite 2.x declares the graph here (once,
     * on the enum) and resolves which transition applies from the object's
     * current state via each transition's source states.
     *
     * @return list<Transition>
     */
    public static function getTransitions(): array
    {
        return [
            new Transition('start', [self::NotStarted], self::Building),
            new Transition('skip', [self::NotStarted, self::Building], self::Skipped),
            new Transition('select_builder', [self::Building], self::Building),
            new Transition('reset_builder', [self::Building], self::Building),
            new Transition('install_success', [self::Building], self::Building),
            new Transition('install_failure', [self::Building], self::Building),
            new Transition('activate_success', [self::Building], self::Building),
            new Transition('activate_failure', [self::Building], self::Building),
            new Transition('complete', [self::Building], self::Completed),
            new Transition('reopen', [self::Skipped, self::Completed], self::Building),
        ];
    }

    /**
     * Normalize a persisted value to a known state, defaulting to NotStarted.
     */
    public static function fromStored(mixed $value): self
    {
        if (is_string($value)) {
            foreach (self::cases() as $state) {
                if ($state->value === $value) {
                    return $state;
                }
            }
        }

        return self::NotStarted;
    }
}
