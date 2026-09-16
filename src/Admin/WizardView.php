<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use LumeWeb\Cast\Onboarding\InstallStatus;
use LumeWeb\Cast\Onboarding\ResultCode;
use LumeWeb\Cast\Onboarding\Wizard;
use LumeWeb\Cast\Onboarding\WizardState;
use LumeWeb\Cast\PageBuilder\PageBuilderCatalog;
use LumeWeb\Cast\PageBuilder\PluginStateProvider;

/**
 * The wizard view model: a pure, explicit mapping from the persisted aggregate
 * to the screen the Getting Started templates should render.
 *
 * Templates never inspect raw aggregate fields to decide what to show. This
 * object (and only this object) turns the persisted `cast_onboarding` option —
 * via the Wizard aggregate — into one screen identifier plus the minimal
 * presentation booleans and labels a screen needs. It is deliberately free of
 * WordPress/request primitives so the state-to-screen mapping is unit-testable.
 */
final class WizardView
{
    public const SCREEN_WELCOME = 'welcome';
    public const SCREEN_CHOOSE = 'choose';
    public const SCREEN_INSTALL = 'install';
    public const SCREEN_COMPLETE = 'complete';
    public const SCREEN_SKIPPED = 'skipped';

    /** @var list<array{label: string, current: bool}> */
    public const PROGRESS_STEPS = [
        ['label' => 'Choose', 'current' => false],
        ['label' => 'Install', 'current' => false],
        ['label' => 'Complete', 'current' => false],
    ];

    public function __construct(
        public readonly string $screen,
        public readonly WizardState $state,
        public readonly ?string $selectedBuilderSlug,
        public readonly ?string $selectedBuilderLabel,
        public readonly InstallStatus $installStatus,
        public readonly ?ResultCode $resultCode,
        public readonly bool $readyToComplete,
        public readonly bool $canSkip,
        public readonly bool $canReopen,
        public readonly bool $canStart,
        public readonly bool $canFinish,
        public readonly int $progressStep,
    ) {
    }

    /**
     * State-to-screen mapping. The rules are the single point of truth for
     * which screen a persisted aggregate produces; every screen transition in
     * the product path is exercised here.
     *
     * When a live server-side PluginStateProvider is supplied (production
     * wiring), actual runtime plugin state is authoritative for readiness: a
     * selected installable catalog builder that IS active at runtime reaches
     * the Complete screen even when the persisted install step/result is stale
     * from a prior redirect/error. Without a provider (bare wiring / unit
     * tests) the persisted aggregate remains the only signal and the historical
     * progress steps are honoured exactly as before.
     */
    public static function fromWizard(
        Wizard $wizard,
        PageBuilderCatalog $catalog,
        ?PluginStateProvider $plugins = null,
    ): self {
        $screen = self::SCREEN_WELCOME;
        $readyToComplete = false;
        $canSkip = false;
        $canReopen = false;
        // The fresh `start` transition is only reachable when nothing is
        // underway; a running (Building) wizard must not be offered the start
        // action again — it continues instead.
        $canStart = $wizard->state === WizardState::NotStarted;
        // The Finish action posts the backend `complete` transition. It is only
        // offered while the wizard is still Building on the complete screen —
        // once the aggregate reaches terminal Completed the flow is settled.
        $canFinish = false;
        $progressStep = 0;

        switch ($wizard->state) {
            case WizardState::NotStarted:
                $screen = self::SCREEN_WELCOME;
                $canSkip = true;
                break;

            case WizardState::Skipped:
                $screen = self::SCREEN_SKIPPED;
                $canReopen = true;
                break;

            case WizardState::Completed:
                $screen = self::SCREEN_COMPLETE;
                $canReopen = true;
                $progressStep = 3;
                break;

            case WizardState::Building:
                if ($wizard->step === null) {
                    $screen = self::SCREEN_CHOOSE;
                    $progressStep = 1;
                    $canSkip = true;
                    break;
                }

                // A server-confirmed active builder advances to the Complete
                // screen (Step 3). The aggregate is still Building here — the
                // screen is shown first, and only the user-accepted Finish
                // action runs the backend `complete` transition to the terminal
                // Completed state.
                //
                // "Active" is derived from the server-side plugin state
                // provider (the single source of truth for readiness): any
                // non-started step with an actually-active selected catalog
                // plugin lands on the Complete screen, even when the persisted
                // install step/result is stale from a prior redirect/error
                // (e.g. a wizard still persisted as builder_selected/idle while
                // the plugin activated). Without a live provider the persisted
                // status is the only signal, and the historical progress steps
                // ('complete'/'active') are honoured exactly as before.
                $active = $plugins === null
                    ? ($wizard->installStatus === InstallStatus::Active
                        && in_array($wizard->step, ['complete', 'active'], true))
                    : self::isSelectedBuilderActive($wizard, $catalog, $plugins);

                if ($active) {
                    $screen = self::SCREEN_COMPLETE;
                    $progressStep = 3;
                    $readyToComplete = true;
                    $canFinish = true;
                    break;
                }

                $screen = self::SCREEN_INSTALL;
                $progressStep = 2;
                $canSkip = true;

                // The native editor has nothing to install; an installable
                // builder must actually be active (already advanced to the
                // Complete screen above) before the wizard may be completed.
                // Completion is only ever reached through the backend
                // `complete` action — never by rendering.
                $readyToComplete = $wizard->selectedBuilder === null;
                break;
        }

        return new self(
            screen: $screen,
            state: $wizard->state,
            selectedBuilderSlug: $wizard->selectedBuilder,
            selectedBuilderLabel: self::labelFor($wizard, $catalog),
            installStatus: $wizard->installStatus,
            resultCode: $wizard->resultCode,
            readyToComplete: $readyToComplete,
            canSkip: $canSkip,
            canReopen: $canReopen,
            canStart: $canStart,
            canFinish: $canFinish,
            progressStep: $progressStep,
        );
    }

    /**
     * The selectable builder cards, generated from the catalog: the default
     * (native) recommendation first, then every installable candidate, with no
     * candidate-specific render branch. The $nativeValue token is the form
     * value that maps back to the core editor (which has no plugin slug).
     *
     * @return list<array{
     *     value: string,
     *     label: string,
     *     isRecommended: bool,
     *     isCore: bool,
     *     isFree: bool,
     *     costNote: string,
     *     lockInNote: string,
     *     performanceNote: string
     * }>
     */
    public static function choiceRows(PageBuilderCatalog $catalog, string $nativeValue): array
    {
        $candidates = [$catalog->defaultRecommendation(), ...$catalog->installableCandidates()];
        $rows = [];

        foreach ($candidates as $candidate) {
            $rows[] = [
                'value' => (string) ($candidate->slug ?? $nativeValue),
                'label' => $candidate->label,
                'isRecommended' => $candidate->recommended,
                'isCore' => $candidate->isCore,
                'isFree' => $candidate->isFree,
                'costNote' => $candidate->costNote,
                'lockInNote' => $candidate->lockInNote,
                'performanceNote' => $candidate->performanceNote,
            ];
        }

        return $rows;
    }

    /**
     * A human, non-colour status message for a recorded result code, or null
     * when the code carries no inline alert (successes are shown by status,
     * not by an error panel). Used to surface backend result codes as an
     * inline error panel with a retry affordance.
     */
    public static function resultMessage(?ResultCode $code): ?string
    {
        return match ($code) {
            ResultCode::InstallFailed => 'The page builder could not be installed. Check the error above and retry.',
            ResultCode::ActivateFailed => 'The page builder was installed but could not be activated. Check the error above and retry.',
            default => null,
        };
    }

    /**
     * Runtime readiness of the selected builder, per the server-side provider.
     *
     * Only an installable catalog candidate is ever asked: a stale or unknown
     * slug, and non-installable catalog entries (e.g. the native editor or an
     * alternative), can never be reported "active" just because some plugin
     * directory happens to match. This is the generic catalog-driven path used
     * uniformly for every installable builder (Elementor, Beaver, GenerateBlocks,
     * Brizy, ...) — there is no builder-name branching.
     */
    private static function isSelectedBuilderActive(Wizard $wizard, PageBuilderCatalog $catalog, PluginStateProvider $plugins): bool
    {
        $slug = $wizard->selectedBuilder;

        if ($slug === null || $catalog->findInstallable($slug) === null) {
            return false;
        }

        return $plugins->isActive($slug);
    }

    private static function labelFor(Wizard $wizard, PageBuilderCatalog $catalog): ?string
    {
        if ($wizard->selectedBuilder !== null) {
            return $catalog->find($wizard->selectedBuilder)?->label;
        }

        // No plugin slug means the native editor was chosen — but only when a
        // step exists; a welcome/skipped/choice state has no selection at all.
        if ($wizard->step !== null) {
            return $catalog->defaultRecommendation()->label;
        }

        return null;
    }
}
