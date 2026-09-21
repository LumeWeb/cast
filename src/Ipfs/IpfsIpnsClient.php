<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\HttpTransport;

/**
 * Real IPNS adapter on the shared PSR-18 transport, mapping the ipfs-sdk
 * IPNS.CreateKey (POST /api/ipns/keys, body {"name": ...}), IPNS.Publish
 * (POST /api/ipns/publish, body {"key_id": int, "cid": ...}) and IPNS.Resolve
 * (GET /api/ipns/resolve/{name}) calls exactly. The Resolve read is what the
 * awaiting-website registry match uses to compare an IPNS-targeted website's
 * mutable target_hash (the IPNS name) against the run's immutable CID. The API
 * key is a bearer credential that only ever appears on the wire; the typed
 * HttpException family is preserved. Creating a key or publishing never
 * triggers an extra call: each operation is exactly one request.
 */
final class IpfsIpnsClient implements IpnsResolver
{
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {
    }

    /**
     * POST /api/ipns/keys — create a fresh IPNS key for a site.
     *
     * @throws \LumeWeb\Cast\Http\HttpException transport/status/decoding failures (typed family preserved)
     */
    public function createKey(string $name): IpnsKey
    {
        $response = $this->transport->send(
            'POST',
            $this->baseUrl . '/api/ipns/keys',
            $this->headers(),
            $this->encode(['name' => $name]),
        );
        $response->requireSuccess();

        return IpnsKey::fromArray($response->json());
    }

    /**
     * POST /api/ipns/publish — publish a CID to an existing key by its numeric id.
     *
     * @throws \LumeWeb\Cast\Http\HttpException transport/status/decoding failures (typed family preserved)
     */
    public function publish(int $keyId, string $cid): IpnsPublication
    {
        $response = $this->transport->send(
            'POST',
            $this->baseUrl . '/api/ipns/publish',
            $this->headers(),
            $this->encode([
                'key_id' => $keyId,
                'cid' => $cid,
            ]),
        );
        $response->requireSuccess();

        return IpnsPublication::fromArray($response->json());
    }

    /**
     * GET /api/ipns/resolve/{name} — the CID an IPNS name currently points at.
     *
     * A read call that mirrors the get()/list() read convention: no body, no
     * Content-Type, Accept application/json. The resolved value is returned as
     * an IpnsResolution carrying the immutable root CID.
     *
     * @throws \LumeWeb\Cast\Http\HttpException transport/status/decoding failures (typed family preserved)
     */
    public function resolve(string $name): IpnsResolution
    {
        $uri = $this->baseUrl . '/api/ipns/resolve/' . rawurlencode($name);

        $response = $this->transport->send('GET', $uri, $this->readHeaders());
        $response->requireSuccess();

        return IpnsResolution::fromArray($response->json());
    }

    /**
     * @param array<string, int|string> $body
     */
    private function encode(array $body): string
    {
        return json_encode($body, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Headers for requests with no body (GET): no Content-Type, matching the
     * read convention used across the ipfs-sdk adapters.
     *
     * @return array<string, string>
     */
    private function readHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
        ];
    }
}
