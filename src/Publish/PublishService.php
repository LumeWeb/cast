<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * Pure publish orchestration: turns one artifact into a live website + IPNS
 * publication. It routes the upload, delegates upload + result waiting to the
 * UploadWaiter, then reconciles the portal identity against the PublishRegistry.
 * The IPNS half is reconciled FIRST (the key is created once and the CID
 * published to it) because an IPNS-targeted website requires its key to exist
 * and hold a publication BEFORE the website exists (else IPNS_KEY_NOT_FOUND);
 * then the website target is created on first publish (domain omitted) or
 * re-pointed on every later publish. With the ipns default the website's
 * target_hash is the mutable IPNS name (so the site always serves the newest
 * published CID); a legacy ipfs-token run stamps the CID directly. Because
 * identity is decided from durable registry state rather than a per-run flag,
 * a resumed run picks up exactly where it stalled and never invites a second
 * website or key. Failure boundaries are deliberate: an upload failure
 * produces nothing; a website/IPNS failure preserves the CID (and any identity
 * already owned) and returns a resumable state.
 */
final class PublishService
{
    public function __construct(
        private readonly UploadRouter $router,
        private readonly UploadWaiter $uploads,
        private readonly WebsiteClient $websites,
        private readonly IpnsClient $ipns,
        private readonly PublishRegistry $registry,
        private readonly WebsiteReadinessWaiter $readiness,
        private readonly ?PublishListener $listener = null,
    ) {
    }

    public function publish(Artifact $artifact): PublishResult
    {
        $route = $this->router->route($artifact->sizeBytes);

        $this->emit(PublishStage::Uploading, 'Uploading artifact');
        $spec = new UploadSpec($artifact->path, $artifact->name, archive: true, sizeBytes: $artifact->sizeBytes, route: $route);
        try {
            $identifier = $this->uploads->upload($spec);
        } catch (UploadClientException $exception) {
            return $this->failed($route, $artifact->name, $exception->getMessage());
        }

        $this->emit(PublishStage::Polling, 'Waiting for upload to finish');
        $result = $this->uploads->wait($identifier);
        if (!$result->isSuccess()) {
            $detail = $result->status === UploadStatus::Failed
                ? ($result->message ?? 'Upload failed')
                : 'Upload did not reach a terminal state within the polling budget';

            return $this->failed($route, $artifact->name, $detail);
        }

        $cid = $result->cid;
        if ($cid === null) {
            return $this->failed($route, $artifact->name, 'Upload completed without a CID');
        }

        $state = $this->registry->current();

        // IPNS first: an IPNS-targeted website requires the key to exist and
        // the current CID to be published BEFORE the website is created (else
        // IPNS_KEY_NOT_FOUND), so the key step and the publish step always run
        // ahead of the website create/update.
        $ipnsKey = $state?->ipnsKey;
        if ($ipnsKey === null) {
            $this->emit(PublishStage::CreatingIpnsKey, 'Creating IPNS key');
            try {
                $key = $this->ipns->createKey($artifact->label);
            } catch (IpnsClientException $exception) {
                return $this->resumable($cid, $identifier, PublishStage::CreatingIpnsKey, $exception->getMessage(), $state?->websiteId, null, $route, $artifact->name);
            }
            $ipnsKey = $key->name;
            $this->registry->recordIpnsKey($key->name, $key->id);
        }

        $this->emit(PublishStage::PublishingIpns, 'Publishing CID to IPNS');
        try {
            $publication = $this->ipns->publish($ipnsKey, $cid);
        } catch (IpnsClientException $exception) {
            return $this->resumable($cid, $identifier, PublishStage::PublishingIpns, $exception->getMessage(), $state?->websiteId, $ipnsKey, $route, $artifact->name);
        }

        // Website second. In ipns mode the website points at the mutable IPNS
        // name (just published), so the site always serves the newest CID; a
        // legacy ipfs-token run stamps the immutable CID directly.
        $targetHash = $this->websiteTargetHash($artifact, $cid, $publication->ipnsName);

        $websiteId = $state?->websiteId;
        if ($websiteId === null) {
            $this->emit(PublishStage::CreatingWebsite, 'Creating website from upload');
            try {
                $website = $this->websites->create(
                    new CreateWebsiteRequest($targetHash, $artifact->targetType, $artifact->label)
                );
            } catch (WebsiteClientException $exception) {
                return $this->resumable($cid, $identifier, PublishStage::CreatingWebsite, $exception->getMessage(), null, $ipnsKey, $route, $artifact->name);
            }
            $websiteId = $website->id;
            $this->registry->recordWebsite($website->id, $website->label);
        } else {
            $this->emit(PublishStage::UpdatingWebsite, 'Re-pointing website target at new upload');
            try {
                $this->websites->update($websiteId, $targetHash, $artifact->targetType);
            } catch (WebsiteClientException $exception) {
                return $this->resumable($cid, $identifier, PublishStage::UpdatingWebsite, $exception->getMessage(), $websiteId, $ipnsKey, $route, $artifact->name);
            }
        }

        // The portal has accepted the deploy, but the publish is only complete
        // once the website is actually live and serving the new CID. Poll the
        // readiness boundary; a timeout (or a provisioning read failure) keeps
        // the run resumable so it is never falsely advertised as done.
        $this->emit(PublishStage::CheckingReadiness, 'Confirming website is live and serving the new CID');
        try {
            $readiness = $this->readiness->waitForCid($websiteId, $cid);
        } catch (WebsiteClientException $exception) {
            return $this->resumable($cid, $identifier, PublishStage::CheckingReadiness, $exception->getMessage(), $websiteId, $ipnsKey, $route, $artifact->name);
        }

        if (!$readiness->isReady()) {
            $observed = $readiness->site->status !== '' ? $readiness->site->status : 'unknown';

            return $this->resumable(
                $cid,
                $identifier,
                PublishStage::CheckingReadiness,
                sprintf('Website did not confirm serving the new CID before the readiness budget expired (status: %s).', $observed),
                $websiteId,
                $ipnsKey,
                $route,
                $artifact->name,
                $readiness,
            );
        }

        $this->emit(PublishStage::Completed, 'Publish completed');

        return PublishResult::completed($cid, $websiteId, $ipnsKey, $route, $artifact->name, $readiness);
    }

    /**
     * The target_hash the website should be created/pointed at. With the ipns
     * default the target is the mutable IPNS name just published (so the site
     * follows the newest CID forever); a legacy ipfs run stamps the immutable
     * CID. The portal rejects an IPNS-targeted website whose name has no
     * publication (IPNS_KEY_NOT_FOUND), which is exactly why the publish step
     * always precedes this decision.
     */
    private function websiteTargetHash(Artifact $artifact, string $cid, ?string $ipnsName): string
    {
        if ($artifact->targetType !== 'ipns') {
            return $cid;
        }

        return $ipnsName ?? $cid;
    }

    private function failed(UploadRoute $route, string $artifactName, string $message): PublishResult
    {
        $this->emit(PublishStage::Failed, $message);

        return PublishResult::failed($message, $route, $artifactName);
    }

    private function resumable(
        string $cid,
        UploadIdentifier $identifier,
        PublishStage $stage,
        string $message,
        ?string $websiteId,
        ?string $ipnsKey,
        UploadRoute $route,
        string $artifactName,
        ?WebsiteReadiness $readiness = null,
    ): PublishResult {
        $this->emit(PublishStage::Resumable, $message);

        return PublishResult::resumable(
            new ResumeState($cid, $identifier, $stage),
            $message,
            $websiteId,
            $ipnsKey,
            $route,
            $artifactName,
            $readiness,
        );
    }

    private function emit(PublishStage $stage, string $message): void
    {
        $this->listener?->onProgress(new PublishProgress($stage, $message));
    }
}
