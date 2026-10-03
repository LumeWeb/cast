<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

use LumeWeb\Cast\Http\HttpException;
use LumeWeb\Cast\Http\UnexpectedStatusCodeException;
use LumeWeb\Cast\Ipfs\WorkspaceLinker;

/**
 * Pure publish orchestration: turns one artifact into a live website + IPNS
 * publication. It routes the upload, delegates upload + result waiting to the
 * UploadWaiter, then reconciles the portal identity against the PublishRegistry.
 * The IPNS half is reconciled FIRST (the key is created once and the CID
 * published to it) because an IPNS-targeted website requires its key to exist
 * and hold a publication BEFORE the website exists (else IPNS_KEY_NOT_FOUND);
 * then the website is provisioned on first publish from the run's CONFIRMED
 * destination snapshot — never from the artifact's build-host label — and
 * re-pointed on every later publish. With the ipns default the website's
 * target_hash is the mutable IPNS name (so the site always serves the newest
 * published CID); a legacy ipfs-token run stamps the CID directly. Because
 * identity is decided from durable registry state rather than a per-run flag,
 * a resumed run picks up exactly where it stalled and never invites a second
 * website or key. Failure boundaries are deliberate: an upload failure
 * produces nothing; a website/IPNS failure preserves the CID (and any identity
 * already owned) and returns a resumable state.
 *
 * The first-publish provisioning branches mirror the pinner-cli website
 * wizard contract, driven by the destination's source:
 *   - platform: create with the platform claim fields (generate OR label, the
 *     selected platform root/namespace, managed DNS) — no custom
 *     domain/namespace, no label fallback;
 *   - custom: create with ONLY the explicitly entered domain, namespace, and
 *     explicit dns_hosting_enabled true|false; a first publish then PAUSES at
 *     the explicit awaiting-DNS boundary (CID/IPNS/website preserved) instead
 *     of polling readiness — a domain cannot serve before its DNS connects;
 *   - existing: NEVER create — attach the chosen account website to the
 *     workspace and re-point it; an attach 409 (the website already belongs
 *     to a workspace) is a friendly typed refusal, and the identity is
 *     recorded only after a successful attach.
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
        // The workspace attach half of an EXISTING destination: the workspace
        // linker (POST /api/workspaces/{id}/attach) and the lazy workspace-id
        // provider (resolved from the portal connection, never client input).
        // Both optional: without them an existing destination refuses with a
        // fixed message instead of creating a website.
        private readonly ?WorkspaceLinker $workspaces = null,
        private readonly ?\Closure $workspaceIdProvider = null,
    ) {
    }

    /**
     * @param ?PublishDestination $destination The run's confirmed destination
     *   snapshot (from the run settings). Only consulted when the registry
     *   holds no website id yet (a first publish): a later publish always
     *   re-points the recorded website and needs no destination.
     */
    public function publish(Artifact $artifact, ?PublishDestination $destination = null): PublishResult
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
            // First publish: provision the website from the run's confirmed
            // destination — the build-host label is deliberately NOT a claim.
            $provisionStage = $destination?->source === PublishDestinationSource::Existing
                ? PublishStage::UpdatingWebsite
                : PublishStage::CreatingWebsite;
            $this->emit($provisionStage, $destination?->source === PublishDestinationSource::Existing
                ? 'Connecting your chosen website to the new upload'
                : 'Creating website from upload');
            try {
                $website = $this->provisionWebsite($destination, $targetHash, $artifact->targetType);
            } catch (WebsiteClientException $exception) {
                return $this->resumable($cid, $identifier, $provisionStage, $exception->getMessage(), null, $ipnsKey, $route, $artifact->name);
            } catch (WebsiteProvisioningException $exception) {
                // A fixed, friendly refusal (no destination, attach 409,
                // attach unavailable): nothing is recorded, nothing retries.
                return $this->failed($route, $artifact->name, $exception->getMessage());
            }
            $websiteId = $website->id;
            // The persisted identity never carries an empty name: a created
            // site keeps its label, an attached/re-pointed one falls back to
            // its id (the dashboard resolves the bound domain on refresh).
            $this->registry->recordWebsite($website->id, $website->label !== '' ? $website->label : $website->id);

            // A custom domain cannot serve until its DNS points at the
            // website: the first publish PAUSES here at an explicit
            // awaiting-DNS boundary (never a generic resumable failure) with
            // the CID, the IPNS key and the just-recorded website id all
            // preserved. The readiness poll is never started; the operator
            // connects the domain's DNS and resumes through the existing
            // artifact — no re-export, no re-upload, no second website.
            if ($destination?->source === PublishDestinationSource::Custom) {
                $this->emit(PublishStage::AwaitingDns, 'Waiting for your domain DNS to connect');

                return PublishResult::awaitingDns(
                    $cid,
                    $websiteId,
                    $ipnsKey,
                    $route,
                    $artifact->name,
                    sprintf(
                        'Your site was uploaded and the website for %s was created. Connect the domain\'s DNS, then resume — the upload will not be repeated.',
                        $destination->domain,
                    ),
                );
            }
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
     * The first-publish website provisioning, branched on the CONFIRMED
     * destination source (the pinner-cli wizard contract — see the class
     * docblock). Never derives any claim field from the artifact label.
     *
     * @throws WebsiteClientException portal rejection (resumable by the caller)
     * @throws WebsiteProvisioningException fixed friendly refusal (fatal)
     */
    private function provisionWebsite(?PublishDestination $destination, string $targetHash, string $targetType): Website
    {
        if ($destination === null) {
            throw new WebsiteProvisioningException(
                'This run has no confirmed site address, so a website cannot be created. Choose a site address and start the first publish again.',
            );
        }

        return match ($destination->source) {
            // Platform and custom both create — with the exact, mutually
            // exclusive field sets the destination aggregate already
            // validated.
            PublishDestinationSource::Platform, PublishDestinationSource::Custom => $this->websites->create(
                CreateWebsiteRequest::forDestination($destination, $targetHash, $targetType),
            ),
            PublishDestinationSource::Existing => $this->attachExistingWebsite($destination, $targetHash, $targetType),
        };
    }

    /**
     * An existing account website is attached to the workspace and re-pointed
     * at the new upload — it is never created. The 409 attach conflict
     * ("already belongs to a workspace") becomes a fixed, friendly refusal;
     * any other portal rejection stays resumable like every other website
     * failure. The identity is only returned (and thus recorded) after a
     * successful attach.
     *
     * @throws WebsiteClientException transport/portal rejection (resumable)
     * @throws WebsiteProvisioningException fixed friendly refusal (fatal)
     */
    private function attachExistingWebsite(PublishDestination $destination, string $targetHash, string $targetType): Website
    {
        $websiteId = (string) $destination->websiteId;

        if ($this->workspaces === null) {
            throw new WebsiteProvisioningException(
                'The workspace connection is not available, so your chosen website cannot be attached to this site.',
            );
        }

        $workspaceId = $this->workspaceIdProvider !== null
            ? (string) ($this->workspaceIdProvider)()
            : '';
        if ($workspaceId === '') {
            throw new WebsiteProvisioningException(
                'The workspace for this site could not be resolved, so your chosen website cannot be attached.',
            );
        }

        try {
            $this->workspaces->attach($workspaceId, (int) $websiteId);
        } catch (UnexpectedStatusCodeException $exception) {
            if ($exception->status() === 409) {
                throw new WebsiteProvisioningException(
                    'This website is already in use by another site. Choose a different website and try again.',
                );
            }

            throw new WebsiteClientException($exception->getMessage(), 0, $exception);
        } catch (HttpException $exception) {
            throw new WebsiteClientException($exception->getMessage(), 0, $exception);
        }

        // Attached: re-point the existing website at this upload so it serves
        // the new content (the same update a later publish performs).
        return $this->websites->update($websiteId, $targetHash, $targetType);
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
