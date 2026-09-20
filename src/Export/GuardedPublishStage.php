<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Export;

/**
 * The publish pipeline boundary when the deployment identity cannot be
 * resolved at boot: the portal credentials the real publish stack depends on
 * are absent from this process environment, so the boot composition has no
 * base URL / API key to hand the SDK clients.
 *
 * Instead of parking an {@see UnwiredStage} — whose message would falsely
 * claim publish is merely "not wired yet" — this stage fails loudly with an
 * actionable reason naming the missing prerequisite, exactly like any other
 * publish failure: the tick runner records the retry/lastError and the run
 * never silently wedges nor falsely completes at the final boundary. The
 * failure reason is fixed at boot time so the composition boundary stays explicit
 * and testable.
 */
final class GuardedPublishStage implements PipelineStage
{
    public const DEFAULT_REASON = 'the Pinner deployment environment (PORTAL_API_URL and PORTAL_API_KEY) is not configured';

    public function __construct(private readonly string $reason = self::DEFAULT_REASON)
    {
    }

    public function key(): PipelineStageKey
    {
        return PipelineStageKey::Publish;
    }

    public function execute(string $cursor): StageResult
    {
        return StageResult::fail(sprintf(
            'Publish cannot run: %s. Configure the Pinner environment so the real publish stage can execute.',
            $this->reason,
        ));
    }
}
