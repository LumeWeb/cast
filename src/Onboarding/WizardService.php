<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Onboarding;

use Finite\Exception\TransitionNotReachableException;
use Finite\StateMachine;
use LumeWeb\Cast\PageBuilder\PageBuilderCatalog;
use LumeWeb\Cast\PageBuilder\PluginStateProvider;

/**
 * Orchestrates wizard mutations through the Finite 2.x state machine.
 *
 * Every mutation loads the aggregate, validates the requested transition is
 * legal for the current state (Finite), records the operation's fields, and
 * persists the aggregate. None of the auxiliary fields are trusted from the
 * request — builder selection is validated against the catalog allowlist and
 * install/activation results map to structured codes chosen server-side.
 *
 * WordPress option persistence stays with WizardStore; this service only talks
 * to that adapter and the transition graph.
 */
final class WizardService
{
    /**
     * The form value that selects the native WordPress editor (the catalog's
     * default recommendation, which has no plugin slug). It resolves to a null
     * selected-builder on the aggregate and steps straight to the install
     * screen's ready state, because nothing needs installing.
     */
    public const NATIVE_BUILDER = 'native';

    public function __construct(
        private readonly WizardStore $store,
        private readonly ?PageBuilderCatalog $catalog = null,
        private readonly StateMachine $machine = new StateMachine(),
        private readonly ?PluginStateProvider $plugins = null,
    ) {
    }

    public function current(): Wizard
    {
        return $this->store->load();
    }

    public function start(): Wizard
    {
        // "Get Started" is shown to a user who has not started yet AND on the
        // dashboard welcome panel for a wizard already underway (Building). A
        // repeated start while building must not attempt the invalid `start`
        // transition (legal only from NotStarted) and fatal the request; it
        // continues the in-progress flow, so it is a no-op.
        if ($this->store->load()->state === WizardState::Building) {
            return $this->current();
        }

        return $this->mutate('start');
    }

    public function skip(): Wizard
    {
        return $this->mutate('skip');
    }

    public function complete(): Wizard
    {
        return $this->mutate('complete');
    }

    public function selectBuilder(string $value): Wizard
    {
        if ($value === self::NATIVE_BUILDER) {
            return $this->selectNativeBuilder();
        }

        $candidate = $this->catalog?->findInstallable($value);

        if ($candidate === null) {
            throw new InvalidBuilder(sprintf(
                'Builder "%s" is not on the catalog allowlist.',
                $value,
            ));
        }

        return $this->mutate('select_builder', static function (Wizard $wizard) use ($value): void {
            $wizard->selectedBuilder = $value;
            $wizard->step = 'builder_selected';
        });
    }

    public function resetBuilder(): Wizard
    {
        return $this->mutate('reset_builder', static function (Wizard $wizard): void {
            $wizard->selectedBuilder = null;
            $wizard->installStatus = InstallStatus::Idle;
            $wizard->resultCode = null;
            $wizard->step = null;
        });
    }

    public function reopen(): Wizard
    {
        return $this->mutate('reopen', static function (Wizard $wizard): void {
            $wizard->selectedBuilder = null;
            $wizard->installStatus = InstallStatus::Idle;
            $wizard->resultCode = null;
            $wizard->step = null;
        });
    }

    public function recordInstall(bool $success): Wizard
    {
        return $this->mutate(
            $success ? 'install_success' : 'install_failure',
            static function (Wizard $wizard) use ($success): void {
                $wizard->installStatus = $success ? InstallStatus::Installed : InstallStatus::Failed;
                $wizard->resultCode = $success ? ResultCode::InstallSucceeded : ResultCode::InstallFailed;
                $wizard->step = $success ? 'installed' : 'install_failed';
            },
        );
    }

    public function recordActivate(bool $claimed): Wizard
    {
        $wizard = $this->store->load();

        // Authoritative outcome: the server-side plugin active state is the
        // source of truth, not the client's parse of the wp.updates response.
        // Some plugins (e.g. Brizy) force a 3xx redirect on activation
        // (`redirectAfterActivation`), which corrupts core's JSON parse and
        // makes the client report a failure even though the plugin actually
        // activated. Recording the real state prevents a false permanent
        // "activate_failed" that would wedge the wizard. The client's claim is
        // only trusted when no provider is available (unit tests / bare wiring).
        $success = ($this->plugins === null || $wizard->selectedBuilder === null)
            ? $claimed
            : $this->plugins->isActive((string) $wizard->selectedBuilder);

        return $this->mutate(
            $success ? 'activate_success' : 'activate_failure',
            static function (Wizard $wizard) use ($success): void {
                $wizard->installStatus = $success ? InstallStatus::Active : InstallStatus::Failed;
                $wizard->resultCode = $success ? ResultCode::ActivateSucceeded : ResultCode::ActivateFailed;
                // A confirmed-active builder advances the wizard progress to the
                // complete screen state — but the aggregate stays Building until
                // the user accepts the Finish action, which runs the `complete`
                // transition to the terminal Completed state. Recording step
                // 'complete' shows the Step 3 screen without silently finishing.
                $wizard->step = $success ? 'complete' : 'activate_failed';
            },
        );
    }

    /**
     * Native selection is still validated against the catalog: the token only
     * resolves when the catalog's default recommendation is the core (non
     * installable) native editor. With no catalog available, or when the
     * default recommendation is unexpectedly an installable plugin, the token
     * is refused rather than trusted as free-form input.
     */
    private function selectNativeBuilder(): Wizard
    {
        $native = $this->catalog?->defaultRecommendation();

        if ($native === null || $native->installable || !$native->isCore) {
            throw new InvalidBuilder('The native editor selection is not available.');
        }

        return $this->mutate('select_builder', static function (Wizard $wizard): void {
            $wizard->selectedBuilder = null;
            $wizard->step = 'ready';
        });
    }

    /**
     * @param callable(Wizard): void|null $record
     */
    private function mutate(string $transition, ?callable $record = null): Wizard
    {
        $wizard = $this->store->load();

        try {
            $this->machine->apply($wizard, $transition);
        } catch (TransitionNotReachableException $e) {
            throw new InvalidTransition(sprintf(
                'Cannot apply transition "%s" from state "%s".',
                $transition,
                $wizard->state->value,
            ), 0, $e);
        }

        if ($record !== null) {
            $record($wizard);
        }

        $wizard->touch();
        $this->store->save($wizard);

        return $wizard;
    }
}
