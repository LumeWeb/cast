<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\HttpException;

/**
 * Lazy wrapper for reading the account-wide website registry (GET /api/websites).
 *
 * The awaiting-website status derivation consults this ONLY when a run is
 * parked at the publish boundary with no local website id, so a regular
 * dashboard poll never reads the network through it. Implementations follow
 * the typed HttpException contract of the shared PSR-18 adapters.
 */
interface WebsiteList
{
    /**
     * Every website the authenticated account owns, in server order.
     *
     * @return list<Website>
     *
     * @throws HttpException transport/status/decoding failures (typed family preserved)
     */
    public function list(): array;
}
