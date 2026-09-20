<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Portal;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LumeWeb\Cast\Http\ResponseDecodingException;
use LumeWeb\Cast\Http\TransportException;
use LumeWeb\Cast\Http\UnexpectedStatusCodeException;
use LumeWeb\Cast\Ipfs\Workspace;
use LumeWeb\Cast\Portal\WorkspaceResolve;
use LumeWeb\Cast\Portal\WorkspaceResolveClient;
use LumeWeb\Cast\Portal\WorkspaceResolveWebsite;
use LumeWeb\Cast\Tests\Unit\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * WorkspaceResolveClient is the runtime workspace-identity resolver over the
 * shared bearer HttpTransport: GET /api/workspaces/resolve?resource_uuid=...
 * authenticated with the workspace PORTAL_API_KEY bearer. It maps the response
 * to workspace + the attached Website publish relationship. The portal's
 * Workspaces.Access proxy Basic Auth credentials are NEVER involved here — the
 * single credential on the wire is the PORTAL_API_KEY bearer.
 */
final class WorkspaceResolveClientTest extends TestCase
{
    private const BASE_URL = 'https://ipfs.pinner.xyz:8443';
    private const API_KEY = 's3cret-account-key-9f8d';
    private const RESOURCE_UUID = 'res-uuid-8f3a21c0';

    /**
     * @param list<ResponseInterface|ClientExceptionInterface> $queue
     */
    private function client(array $queue): WorkspaceResolveClient
    {
        $recording = RecordingTransport::withResponses($queue);

        return new WorkspaceResolveClient($recording->transport(), self::BASE_URL, self::API_KEY);
    }

    /**
     * @param array{id: int, status: string, target_hash: string, target_type: string}|null $website
     */
    private function resolveJson(string $status = 'active', ?array $website = null): string
    {
        $json = '{'
            . '"id":11,'
            . '"label":"main",'
            . '"domain":"main.example.test",'
            . '"status":"' . $status . '",'
            . '"created":"2026-01-01T00:00:00Z",'
            . '"updated":"2026-01-02T00:00:00Z"';
        if ($website !== null) {
            $json .= ',"website_id":' . $website['id'] . ',"website":{'
                . '"id":' . $website['id'] . ','
                . '"status":"' . $website['status'] . '",'
                . '"target_hash":"' . $website['target_hash'] . '",'
                . '"target_type":"' . $website['target_type'] . '"'
                . '}';
        }

        return $json . '}';
    }

    public function testResolveSendsGetWithResourceUuidQueryAndBearer(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], $this->resolveJson()),
        ]);
        $client = new WorkspaceResolveClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->resolve(self::RESOURCE_UUID);

        $request = $recording->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(
            self::BASE_URL . '/api/workspaces/resolve?resource_uuid=' . self::RESOURCE_UUID,
            (string) $request->getUri(),
        );
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('', (string) $request->getBody());
    }

    public function testResolveOmitsQueryWhenResourceUuidEmpty(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], $this->resolveJson()),
        ]);
        $client = new WorkspaceResolveClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->resolve('');

        $request = $recording->lastRequest();
        self::assertSame(self::BASE_URL . '/api/workspaces/resolve', (string) $request->getUri());
    }

    public function testResolveMapsWorkspaceAndAttachedWebsite(): void
    {
        $client = $this->client([
            new Response(200, [], $this->resolveJson('active', [
                'id' => 42,
                'status' => 'active',
                'target_hash' => 'QmAbc',
                'target_type' => 'car',
            ])),
        ]);

        $result = $client->resolve(self::RESOURCE_UUID);

        self::assertInstanceOf(WorkspaceResolve::class, $result);
        self::assertTrue($result->isResolved());
        $workspace = $result->workspace();
        self::assertInstanceOf(Workspace::class, $workspace);
        self::assertSame(11, $workspace->id());
        self::assertSame('main', $workspace->label());
        self::assertSame('main.example.test', $workspace->domain());
        self::assertSame('active', $workspace->status());

        $website = $result->website();
        self::assertInstanceOf(WorkspaceResolveWebsite::class, $website);
        self::assertSame(42, $website->id());
        self::assertSame('active', $website->status());
        self::assertSame('QmAbc', $website->targetHash());
        self::assertSame('car', $website->targetType());
        self::assertSame(WorkspaceResolve::PUBLISH_STATE_PUBLISHED, $result->publishState());
    }

    public function testResolveMapsWorkspaceWithoutAttachedWebsite(): void
    {
        $client = $this->client([
            new Response(200, [], $this->resolveJson()),
        ]);

        $result = $client->resolve(self::RESOURCE_UUID);

        self::assertTrue($result->isResolved());
        self::assertNull($result->website());
        self::assertSame(WorkspaceResolve::PUBLISH_STATE_NONE, $result->publishState());
    }

    public function testResolveServerErrorFieldMarksNotResolvedWithoutThrowing(): void
    {
        $client = $this->client([
            new Response(200, [], '{"error":"workspace not found for resource uuid"}'),
        ]);

        $result = $client->resolve(self::RESOURCE_UUID);

        self::assertFalse($result->isResolved());
        self::assertNull($result->workspace());
        self::assertNull($result->website());
        // The raw server error is held internally; operator-facing surfaces map
        // it to a value-free message and must never echo it raw.
        self::assertNotNull($result->error());
    }

    public function testResolve401PreservesTypedStatusExceptionWithoutLeakingKey(): void
    {
        $client = $this->client([
            new Response(401, [], '{"error":"invalid token"}'),
        ]);

        try {
            $client->resolve(self::RESOURCE_UUID);
            self::fail('Expected UnexpectedStatusCodeException');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(401, $e->status());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $e->response()->body());
        }
    }

    public function testResolveMalformedBodyThrowsResponseDecodingException(): void
    {
        $client = $this->client([
            new Response(200, [], '<html>not json</html>'),
        ]);

        $this->expectException(ResponseDecodingException::class);
        $client->resolve(self::RESOURCE_UUID);
    }

    public function testResolveTransportFailureWrapsWithoutLeakingKey(): void
    {
        $connect = new ConnectException(
            'Connection refused',
            new Request('GET', self::BASE_URL . '/api/workspaces/resolve'),
        );
        $client = $this->client([$connect]);

        try {
            $client->resolve(self::RESOURCE_UUID);
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            self::assertStringContainsString('GET', $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }
    }
}
