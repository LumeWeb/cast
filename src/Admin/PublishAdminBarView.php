<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use LumeWeb\Cast\Export\RunStage;
use LumeWeb\Cast\Export\RunStatus;

/**
 * The compact admin-bar publish node view model.
 *
 * A pure mapping from the JSON-safe {@see PublishStatus} report to the small,
 * unobtrusive admin-bar indicator: one display state, a short label, whether
 * the node offers an action and which protected action that is (`now` for a
 * re-publish, `start` for the first publish, or null when no action may be
 * offered). The mapping is the same deliberate, test-pinned decision the
 * dashboard card uses, so the admin bar never re-derives a policy from raw
 * status fields.
 */
final class PublishAdminBarView
{
    private const STATE_WORKING = 'working';
    private const STATE_QUIET = 'quiet';
    private const STATE_CONFIG = 'config';
    private const STATE_FAILED = 'failed';
    private const STATE_DIRTY = 'dirty';
    private const STATE_READY = 'ready';

    public const ACTION_NOW = 'now';
    public const ACTION_START = 'start';

    public function __construct(
        public readonly string $state,
        public readonly string $label,
        public readonly bool $canAct,
        public readonly ?string $action,
    ) {
    }

    public static function fromStatus(PublishStatus $status): self
    {
        $runState = self::runState($status);

        if (in_array($runState, ['queued', 'running', 'paused'], true)) {
            return new self(
                self::STATE_WORKING,
                self::workingLabel($status, $runState),
                false,
                null,
            );
        }

        if (!$status->hasEligibleContent) {
            return new self(self::STATE_QUIET, 'Nothing to publish yet', false, null);
        }

        if (!$status->bootstrapIdentityComplete || $status->envProblems !== [] || !$status->onboardingComplete) {
            return new self(self::STATE_CONFIG, 'Publish setup required', false, null);
        }

        if ($status->runStatus === RunStatus::Failed) {
            $hasIdentity = $status->identity !== null;

            return new self(
                self::STATE_FAILED,
                'Publish failed',
                true,
                $hasIdentity ? self::ACTION_NOW : self::ACTION_START,
            );
        }

        if ($status->dirty) {
            $hasIdentity = $status->identity !== null;

            return new self(
                self::STATE_DIRTY,
                'Unpublished changes',
                true,
                $hasIdentity ? self::ACTION_NOW : self::ACTION_START,
            );
        }

        $hasIdentity = $status->identity !== null;

        return new self(
            self::STATE_READY,
            $hasIdentity ? 'Published' : 'Not published yet',
            true,
            $hasIdentity ? self::ACTION_NOW : self::ACTION_START,
        );
    }

    /**
     * The actionable controls the admin bar node may expose as its submenu.
     *
     * The mapping is the same capability/state decision the dashboard card and
     * the notice make, so the toolbar never offers a publish/cancel action that
     * the current status would refuse. Currently actioning a run yields a
     * cancel control; an actionable publish state yields the Publish to Pinner
     * shortcut (now for a re-publish, start for the first). Quiet/config states
     * (no usable control) yield an empty list, so no dead toolbar items are
     * rendered and no localize-able action is exposed.
     *
     * @return list<array{action: string, label: string}>
     */
    public function controls(): array
    {
        if ($this->state === self::STATE_WORKING) {
            return [['action' => 'cancel', 'label' => 'Cancel publish']];
        }

        if ($this->canAct && $this->action !== null) {
            return [['action' => $this->action, 'label' => 'Publish to Pinner']];
        }

        return [];
    }

    private static function runState(PublishStatus $status): string
    {
        return match ($status->runStatus) {
            RunStatus::NotStarted => $status->runActive ? 'queued' : 'idle',
            RunStatus::Running => 'running',
            RunStatus::Paused => 'paused',
            RunStatus::Completed => 'completed',
            RunStatus::CompletedWithWarnings => 'completed_with_warnings',
            RunStatus::Failed => 'failed',
            RunStatus::Cancelled => 'cancelled',
        };
    }

    private static function workingLabel(PublishStatus $status, string $runState): string
    {
        return match ($runState) {
            'queued' => 'Queued to publish',
            'paused' => 'Paused',
            default => match ($status->runStage) {
                RunStage::Exporting => 'Exporting content',
                RunStage::Uploading => 'Uploading to Pinner',
                RunStage::Publishing => 'Publishing',
                default => 'Publishing…',
            },
        };
    }
}
