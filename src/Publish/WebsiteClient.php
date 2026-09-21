<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * The portal websites surface the orchestration needs: create the site from a
 * CID (first publish), later re-point its target (subsequent publish), and read
 * the current lifecycle state (status + active CID) so readiness polling can
 * confirm a deploy actually went live. A fake implements it in tests; a future
 * adapter maps it onto the ipfs-sdk websites API. Throwing WebsiteClientException
 * signals a failure the orchestration turns into a resumable state.
 */
interface WebsiteClient
{
    public function create(CreateWebsiteRequest $request): Website;

    public function update(string $websiteId, string $targetHash, string $targetType): Website;

    public function get(string $websiteId): Website;
}
