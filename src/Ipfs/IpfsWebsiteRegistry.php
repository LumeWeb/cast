<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Publish\CreateWebsiteRequest;

/**
 * WebsiteRegistry adapter over the raw ipfs-sdk websites client.
 *
 * The {@see IpfsWebsitesClient} already implements the full surface
 * (get/update/create/list); this adapter exists solely to expose it behind the
 * {@see WebsiteRegistry} adapter without altering that client, so the admin
 * guided-card actions and the status derivation never re-derive a wire call.
 */
final class IpfsWebsiteRegistry implements WebsiteRegistry
{
    public function __construct(private readonly IpfsWebsitesClient $websites)
    {
    }

    public function list(): array
    {
        return $this->websites->list();
    }

    public function create(CreateWebsiteRequest $request): Website
    {
        return $this->websites->create($request);
    }
}
