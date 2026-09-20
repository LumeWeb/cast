<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Ipfs;

use LumeWeb\Cast\Http\HttpTransport;
use LumeWeb\Cast\Http\ResponseDecodingException;
use LumeWeb\Cast\Publish\CreateWebsiteRequest;

/**
 * Real websites adapter on the shared PSR-18 transport, mapping the ipfs-sdk
 * Websites.Create (POST /api/websites, with target_hash/target_type/label and
 * an optional domain), Websites.Get (GET /api/websites/{id}),
 * Websites.Update (PUT /api/websites/{id}, target-only) and Websites.List
 * (GET /api/websites, paginated {data, total}) calls exactly. The
 * update verb is PUT because that is the only update operation the local
 * ipfs-sdk swagger defines on /api/websites/{id}; the generated contract has no
 * PATCH. The API key is a bearer credential that only ever appears on the wire;
 * the typed HttpException family is preserved.
 */
final class IpfsWebsitesClient implements WebsiteList
{
    /**
     * The window size each list page asks for (the sdk's exclusive _end is
     * start + this). The server default window is 10, so the registry is read
     * whole through explicit _start/_end paging instead of silently
     * truncating the account list.
     */
    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly HttpTransport $transport,
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {
    }

    /**
     * POST /api/websites — create a website. Only the fields the caller
     * explicitly set are serialized: label/domain/namespace are omitted when
     * null (so an auto-generated platform domain never carries a made-up
     * label), generate is only present when true (never on the custom-domain
     * path), and dns_hosting_enabled is only present when true.
     *
     * @throws \LumeWeb\Cast\Http\HttpException transport/status/decoding failures (typed family preserved)
     */
    public function create(CreateWebsiteRequest $request): Website
    {
        $body = [
            'target_hash' => $request->targetHash,
            'target_type' => $this->wireTargetType($request->targetType),
        ];
        if ($request->label !== null) {
            $body['label'] = $request->label;
        }
        if ($request->domain !== null) {
            $body['domain'] = $request->domain;
        }
        if ($request->namespace !== null) {
            $body['namespace'] = $request->namespace;
        }
        if ($request->generate) {
            $body['generate'] = true;
        }
        if ($request->dnsHostingEnabled) {
            $body['dns_hosting_enabled'] = true;
        }

        $response = $this->transport->send('POST', $this->baseUrl . '/api/websites', $this->headers(), $this->encode($body));
        $response->requireSuccess();

        return Website::fromArray($response->json());
    }

    /**
     * PUT /api/websites/{id} — re-point an existing website's target. Only the
     * target fields are sent; all other WebsiteUpdateRequest fields stay
     * omitted so the server leaves them unchanged.
     *
     * @throws \LumeWeb\Cast\Http\HttpException transport/status/decoding failures (typed family preserved)
     */
    public function update(string $websiteId, string $targetHash, string $targetType): Website
    {
        $uri = $this->baseUrl . '/api/websites/' . rawurlencode($websiteId);

        $response = $this->transport->send(
            'PUT',
            $uri,
            $this->headers(),
            $this->encode([
                'target_hash' => $targetHash,
                'target_type' => $this->wireTargetType($targetType),
            ]),
        );
        $response->requireSuccess();

        return Website::fromArray($response->json());
    }

    /**
     * GET /api/websites/{id} — fetch the current lifecycle status of a website
     * so readiness polling can confirm the site is live and serving a specific
     * CID (the response carries status and active_cid).
     *
     * @throws \LumeWeb\Cast\Http\HttpException transport/status/decoding failures (typed family preserved)
     */
    public function get(string $websiteId): Website
    {
        $uri = $this->baseUrl . '/api/websites/' . rawurlencode($websiteId);

        $response = $this->transport->send('GET', $uri, $this->readHeaders());
        $response->requireSuccess();

        return Website::fromArray($response->json());
    }

    /**
     * POST /api/websites/{id}/validate — trigger a DNS validation check for a
     * website and return the server-computed per-record checks.
     *
     * The response is the WebsiteValidateResponse shape ({domain, id, valid,
     * message, reason, checks}); every check value is computed by the server
     * and rendered verbatim by the caller. The endpoint carries no body.
     *
     * @throws \LumeWeb\Cast\Http\HttpException transport/status/decoding failures (typed family preserved)
     */
    public function validate(string $websiteId): WebsiteValidation
    {
        $uri = $this->baseUrl . '/api/websites/' . rawurlencode($websiteId) . '/validate';

        $response = $this->transport->send('POST', $uri, $this->headers());
        $response->requireSuccess();

        return WebsiteValidation::fromArray($response->json());
    }

    /**
     * GET /api/websites — every website the authenticated account owns.
     *
     * The envelope mirrors the ipfs-sdk Websites.List shape ({data, total}),
     * account-scoped and paginated with the sdk's _start/_end window (start
     * inclusive, end exclusive). Pages are walked until a short page (the
     * server returned fewer than the window) or the server-declared total is
     * reached, so the account registry is read whole for the registry-first
     * awaiting-website check.
     *
     * @return list<Website>
     *
     * @throws \LumeWeb\Cast\Http\HttpException transport/status/decoding failures (typed family preserved)
     */
    public function list(): array
    {
        $websites = [];
        $start = 0;

        while (true) {
            $uri = $this->baseUrl . '/api/websites?_start=' . $start . '&_end=' . ($start + self::PAGE_SIZE);

            $response = $this->transport->send('GET', $uri, $this->readHeaders());
            $response->requireSuccess();

            $json = $response->json();
            $rows = $json['data'] ?? null;
            if (!is_array($rows)) {
                throw new ResponseDecodingException('Website list response is missing the "data" array.');
            }

            foreach ($rows as $row) {
                $websites[] = Website::fromArray($row);
            }

            // A short page is the last window: nothing further to ask for.
            if (count($rows) < self::PAGE_SIZE) {
                break;
            }

            // Belt-and-braces bound on a server that ignores the window: stop
            // once the declared total is collected, never loop forever.
            $total = $json['total'] ?? null;
            if (is_int($total) && count($websites) >= $total) {
                break;
            }

            $start += self::PAGE_SIZE;
        }

        return $websites;
    }

    /**
     * The wire value for WebsiteRequest.target_type.
     *
     * The portal's WebsiteRequest enum only accepts ipfs|ipns (the CLI wizard's
     * "IPFS default / IPNS" step). RunSettings now defaults new runs to the
     * internal token 'ipns' (passes through unchanged); only the legacy
     * 'website' token — the pre-ipns default, still reachable from old runs
     * and defensive call-sites — is remapped to 'ipfs'. Only the serialized
     * form is remapped so the persisted run and the in-memory artifact keep
     * their own representation; any other value passes through untouched.
     */
    private function wireTargetType(string $targetType): string
    {
        return $targetType === 'website' ? 'ipfs' : $targetType;
    }

    /**
     * @param array<string, string|bool> $body
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
