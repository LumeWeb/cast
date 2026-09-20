<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\HttpException;

/**
 * Read wrapper for resolving an IPNS name to the CID it currently points at
 * (GET /api/ipns/resolve/{name}).
 *
 * The awaiting-website registry match consults this ONLY when a listed
 * website is IPNS-targeted (its target_hash is an IPNS name, not a CID) so an
 * immutable CID can be compared against the run's preserved CID. Implementations
 * follow the typed HttpException contract of the shared PSR-18 adapters.
 */
interface IpnsResolver
{
    /**
     * The current value of an IPNS name.
     *
     * @throws HttpException transport/status/decoding failures (typed family preserved)
     */
    public function resolve(string $name): IpnsResolution;
}
