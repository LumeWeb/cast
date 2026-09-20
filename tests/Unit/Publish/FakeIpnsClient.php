<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use LumeWeb\Cast\Publish\IpnsClient;
use LumeWeb\Cast\Publish\IpnsClientException;
use LumeWeb\Cast\Publish\IpnsKey;
use LumeWeb\Cast\Publish\IpnsPublication;

/**
 * Records every createKey()/publish() call and can be scripted to throw, so
 * tests assert a key is created/republished exactly as often as the contract
 * demands and that a mid-flow failure never loses the website/CID.
 */
final class FakeIpnsClient implements IpnsClient
{
    /**
     * @var list<string> Key names createKey() was called with, in order.
     */
    public array $createdKeys = [];

    /**
     * @var list<array{0: string, 1: string}> [keyName, cid] publish() was called with, in order.
     */
    public array $published = [];

    public ?string $createError = null;

    public ?string $publishError = null;

    public function createKey(string $name): IpnsKey
    {
        if ($this->createError !== null) {
            throw new IpnsClientException($this->createError);
        }

        $this->createdKeys[] = $name;

        return new IpnsKey('k' . count($this->createdKeys) . '-' . $name, 'key-' . count($this->createdKeys));
    }

    public function publish(string $keyName, string $cid): IpnsPublication
    {
        if ($this->publishError !== null) {
            throw new IpnsClientException($this->publishError);
        }

        $this->published[] = [$keyName, $cid];

        // The mutable IPNS name the publication lives under, stable per key —
        // what an ipns-targeted website's target_hash points at. The ipns
        // mode uses this as the new website target; the legacy ipfs mode
        // stamps the immutable cid instead.
        return new IpnsPublication($keyName, $cid, 'k51-' . $keyName);
    }
}
