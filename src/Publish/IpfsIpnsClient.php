<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

use LumeWeb\Cast\Http\HttpException;
use LumeWeb\Cast\Ipfs\IpfsIpnsClient as SdkIpnsClient;

/**
 * Publish IpnsClient adapter over the ipfs-sdk IPNS client. createKey()
 * delegates to the SDK's POST /api/ipns/keys and maps the numeric portal key
 * id onto the Publish IpnsKey the registry persists; publish() resolves the
 * friendly key name back to that numeric id through the PublishRegistry's
 * SitePublishState, delegates the POST /api/ipns/publish with the id intact,
 * and maps the response onto the Publish IpnsPublication. Every typed
 * HttpException is rethrown as an IpnsClientException carrying the original as
 * $previous; the message is always secret-safe because the bearer API key only
 * ever appears on the wire and never in the typed error text being wrapped.
 */
final class IpfsIpnsClient implements IpnsClient
{
    public function __construct(
        private readonly SdkIpnsClient $ipns,
        private readonly PublishRegistry $registry,
    ) {
    }

    public function createKey(string $name): IpnsKey
    {
        try {
            $key = $this->ipns->createKey($name);
        } catch (HttpException $exception) {
            throw new IpnsClientException($exception->getMessage(), 0, $exception);
        }

        return new IpnsKey($key->name(), (string) $key->id());
    }

    public function publish(string $keyName, string $cid): IpnsPublication
    {
        $keyId = $this->resolveKeyId($keyName);

        try {
            $publication = $this->ipns->publish($keyId, $cid);
        } catch (HttpException $exception) {
            throw new IpnsClientException($exception->getMessage(), 0, $exception);
        }

        return new IpnsPublication($keyName, $cid, $publication->name());
    }

    /**
     * The publish endpoint is keyed by the numeric portal key id, so the key's
     * friendly name is resolved back to that id from the durable registry
     * state. A key that was never recorded, a name that does not match the
     * recorded one, or an id that was never persisted is a boundary failure:
     * without the id the adapter cannot publish and must not guess.
     */
    private function resolveKeyId(string $keyName): int
    {
        $state = $this->registry->current();
        if ($state === null || $state->ipnsKey !== $keyName || $state->ipnsKeyId === null) {
            throw new IpnsClientException(sprintf('No recorded IPNS key id for "%s".', $keyName));
        }

        return (int) $state->ipnsKeyId;
    }
}
