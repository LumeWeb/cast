<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

use LumeWeb\Cast\Http\HttpException;
use LumeWeb\Cast\Ipfs\IpfsWebsitesClient;
use LumeWeb\Cast\Ipfs\Website as IpfsWebsite;

/**
 * Publish WebsiteClient adapter over the ipfs-sdk websites client. create(),
 * get() and update() delegate to IpfsWebsitesClient unchanged, then translate
 * its Website response onto the Publish Website value the orchestration
 * consumes: the id becomes the string identity the registry persists, the label
 * rides along from the create request (a get()/update() re-points or reads an
 * existing site whose name is already recorded, so no label is produced), an
 * empty domain maps to null so a first publish stays "no domain yet", and the
 * status + active CID pass through for readiness polling. Every typed
 * HttpException is rethrown as a WebsiteClientException carrying the original
 * as $previous; the message is always secret-safe because the bearer API key
 * only ever appears on the wire and never in the typed error text being wrapped.
 */
final class IpfsWebsiteClient implements WebsiteClient
{
    public function __construct(private readonly IpfsWebsitesClient $websites)
    {
    }

    public function create(CreateWebsiteRequest $request): Website
    {
        try {
            $website = $this->websites->create($request);
        } catch (HttpException $exception) {
            throw new WebsiteClientException($exception->getMessage(), 0, $exception);
        }

        return $this->map($website, $request->label ?? '');
    }

    public function update(string $websiteId, string $targetHash, string $targetType): Website
    {
        try {
            $website = $this->websites->update($websiteId, $targetHash, $targetType);
        } catch (HttpException $exception) {
            throw new WebsiteClientException($exception->getMessage(), 0, $exception);
        }

        return $this->map($website, '');
    }

    public function get(string $websiteId): Website
    {
        try {
            $website = $this->websites->get($websiteId);
        } catch (HttpException $exception) {
            throw new WebsiteClientException($exception->getMessage(), 0, $exception);
        }

        return $this->map($website, '');
    }

    private function map(IpfsWebsite $website, string $label): Website
    {
        $domain = $website->domain();

        return new Website(
            (string) $website->id(),
            $label,
            $website->targetHash(),
            $website->targetType(),
            $domain === '' ? null : $domain,
            $website->status(),
            $website->activeCid(),
        );
    }
}
