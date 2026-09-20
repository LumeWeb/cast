<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Http\HttpException;
use LumeWeb\Cast\Ipfs\IpnsResolution;
use LumeWeb\Cast\Ipfs\IpnsResolver;

/**
 * In-memory IpnsResolver fake so the awaiting-website registry match for an
 * IPNS-targeted website (target_hash = IPNS name) is testable without a
 * transport. Maps an IPNS name to the CID it currently points at, records
 * every called name, and can be programmed to fail resolution exactly like an
 * unpublished name (an HttpException) would.
 */
final class FakeIpnsResolver implements IpnsResolver
{
    /**
     * @var array<string, string> name => currently served CID
     */
    public array $resolutions = [];

    /**
     * @var list<string> Names resolve() was called with, in order.
     */
    public array $resolved = [];

    public ?string $resolveError = null;

    public function resolve(string $name): IpnsResolution
    {
        $this->resolved[] = $name;

        if ($this->resolveError !== null) {
            throw new HttpException($this->resolveError);
        }

        if (!isset($this->resolutions[$name])) {
            // An unpublished/unresolvable name behaves like the portal's 404.
            throw new HttpException('IPNS name is not published yet.');
        }

        return IpnsResolution::fromArray([
            'name' => $name,
            'value' => '/ipfs/' . $this->resolutions[$name],
            'path' => '/ipfs/' . $this->resolutions[$name],
            'sequence' => 1,
            'expired' => false,
            'expires' => '2099-01-01T00:00:00Z',
        ]);
    }
}
