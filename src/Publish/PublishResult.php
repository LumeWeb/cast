<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * The user-facing result of a publish run: the outcome, the CID/IPNS/website
 * identity when produced, a plain message, and — on a resumable failure — the
 * exact ResumeState the caller can resume from. Route and artifact name ride
 * along so dashboards can report which transport ran and for which archive.
 * A publish is only Completed after the readiness wait confirmed the site is
 * live and serving the new CID; `readiness` carries that verdict (and on a
 * readiness timeout the last observed site) so callers can report why a run
 * stalled instead of falsely advertising completion.
 */
final class PublishResult
{
    public function __construct(
        public readonly PublishOutcome $outcome,
        public readonly ?string $cid = null,
        public readonly ?string $ipnsKey = null,
        public readonly ?string $websiteId = null,
        public readonly ?string $message = null,
        public readonly UploadRoute $route = UploadRoute::Post,
        public readonly string $artifactName = '',
        public readonly ?ResumeState $resume = null,
        public readonly ?WebsiteReadiness $readiness = null,
    ) {
    }

    public static function completed(
        string $cid,
        string $websiteId,
        string $ipnsKey,
        UploadRoute $route,
        string $artifactName,
        ?WebsiteReadiness $readiness = null,
    ): self {
        return new self(PublishOutcome::Completed, $cid, $ipnsKey, $websiteId, null, $route, $artifactName, null, $readiness);
    }

    public static function failed(string $message, UploadRoute $route, string $artifactName): self
    {
        return new self(PublishOutcome::Failed, null, null, null, $message, $route, $artifactName);
    }

    public static function resumable(
        ResumeState $resume,
        string $message,
        ?string $websiteId,
        ?string $ipnsKey,
        UploadRoute $route,
        string $artifactName,
        ?WebsiteReadiness $readiness = null,
    ): self {
        return new self(
            PublishOutcome::Resumable,
            $resume->cid,
            $ipnsKey,
            $websiteId,
            $message,
            $route,
            $artifactName,
            $resume,
            $readiness,
        );
    }
}
