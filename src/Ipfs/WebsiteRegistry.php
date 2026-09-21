<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\HttpException;
use LumeWeb\Cast\Publish\CreateWebsiteRequest;

/**
 * Lazy wrapper for the guided website-card actions over the account website
 * registry (GET /api/websites + POST /api/websites).
 *
 * Extends the {@see WebsiteList} read with the explicit create the
 * guided "create a new website" path needs. The status derivation still only
 * ever calls {@see list()}; {@see create()} is reached exclusively from the
 * explicit create action, never from an ordinary poll or a tick, so the
 * "no silent auto-create" contract holds by construction. Implementations
 * follow the typed HttpException contract of the shared PSR-18 adapters.
 */
interface WebsiteRegistry extends WebsiteList
{
    /**
     * Explicitly create a website. The request carries the explicit destination
     * intent (empty hostname → generate + managed dns hosting, no domain/label;
     * named hostname → domain + icann namespace + managed dns hosting) and the
     * create normalizes target_type on the wire (only ipfs|ipns ever sent).
     *
     * @throws HttpException transport/status/decoding failures (typed family preserved)
     */
    public function create(CreateWebsiteRequest $request): Website;
}
