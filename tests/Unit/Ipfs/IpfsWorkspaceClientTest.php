<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Ipfs;

use GuzzleHttp\Psr7\Response;
use LumeWeb\Cast\Http\ResponseDecodingException;
use LumeWeb\Cast\Http\UnexpectedStatusCodeException;
use LumeWeb\Cast\Ipfs\IpfsWorkspaceClient;
use LumeWeb\Cast\Ipfs\Workspace;
use LumeWeb\Cast\Ipfs\WorkspaceAccess;
use LumeWeb\Cast\Tests\Unit\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * IpfsWorkspaceClient maps the ipfs-sdk Workspaces.List (/api/workspaces) and
 * Workspaces.Access (/api/workspaces/{id}/access) responses on the shared
 * PSR-18 transport, with exact query/path behaviour and bearer auth.
 */
final class IpfsWorkspaceClientTest extends TestCase
{
    private const BASE_URL = 'https://ipfs.pinner.xyz:8443';
    private const API_KEY = 's3cret-account-key-9f8d';

    /**
     * @param list<ResponseInterface|ClientExceptionInterface> $queue
     */
    private function client(array $queue): IpfsWorkspaceClient
    {
        $recording = RecordingTransport::withResponses($queue);

        return new IpfsWorkspaceClient($recording->transport(), self::BASE_URL, self::API_KEY);
    }

    public function testListSendsGetWithBearerToWorkspacesPath(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"data":[],"total":0}'),
        ]);
        $client = new IpfsWorkspaceClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->list();

        $request = $recording->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/workspaces', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    public function testListMapsWorkspaceIdLabelDomainAndStatus(): void
    {
        $client = $this->client([
            new Response(200, [], '{"data":[{"id":11,"label":"main","domain":"main.example.test","status":"active","created":"2026-01-01T00:00:00Z","updated":"2026-01-02T00:00:00Z"}],"total":1}'),
        ]);

        $workspaces = $client->list();

        self::assertCount(1, $workspaces);
        $workspace = $workspaces[0];
        self::assertInstanceOf(Workspace::class, $workspace);
        self::assertSame(11, $workspace->id());
        self::assertSame('main', $workspace->label());
        self::assertSame('main.example.test', $workspace->domain());
        self::assertSame('active', $workspace->status());
    }

    public function testListMapsMultipleWorkspaces(): void
    {
        $client = $this->client([
            new Response(200, [], '{"data":[{"id":1,"label":"a","domain":"a.test","status":"active","created":"c","updated":"u"},{"id":2,"label":"b","domain":"b.test","status":"suspended","created":"c","updated":"u"}],"total":2}'),
        ]);

        $workspaces = $client->list();

        self::assertCount(2, $workspaces);
        self::assertSame(1, $workspaces[0]->id());
        self::assertSame('a', $workspaces[0]->label());
        self::assertSame(2, $workspaces[1]->id());
        self::assertSame('suspended', $workspaces[1]->status());
    }

    public function testListReturnsEmptyArrayForEmptyData(): void
    {
        $client = $this->client([
            new Response(200, [], '{"data":[],"total":0}'),
        ]);

        self::assertSame([], $client->list());
    }

    public function testList401PreservesTypedStatusExceptionWithoutLeakingKey(): void
    {
        $client = $this->client([
            new Response(401, [], '{"error":"unauthorized"}'),
        ]);

        try {
            $client->list();
            self::fail('Expected UnexpectedStatusCodeException');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(401, $e->status());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $e->response()->body());
        }
    }

    public function testListMalformedBodyThrowsResponseDecodingException(): void
    {
        $client = $this->client([
            new Response(200, [], '{"total":1}'),
        ]);

        $this->expectException(ResponseDecodingException::class);
        $client->list();
    }

    public function testListNonJsonBodyThrowsResponseDecodingException(): void
    {
        $client = $this->client([
            new Response(200, [], 'not json'),
        ]);

        $this->expectException(ResponseDecodingException::class);
        $client->list();
    }

    public function testListWorkspaceRowMissingRequiredFieldThrowsResponseDecodingException(): void
    {
        $client = $this->client([
            new Response(200, [], '{"data":[{"id":1,"domain":"a.test","status":"active","created":"c","updated":"u"}],"total":1}'),
        ]);

        $this->expectException(ResponseDecodingException::class);
        $client->list();
    }

    public function testAccessSendsGetToAccessPathWithoutRotateQuery(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"username":"proxy-user","password":"proxy-pass"}'),
        ]);
        $client = new IpfsWorkspaceClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->access('ws-42');

        $request = $recording->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/workspaces/ws-42/access', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('', (string) $request->getBody());
    }

    public function testAccessAddsRotateQueryWhenRequested(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"username":"proxy-user","password":"proxy-pass"}'),
        ]);
        $client = new IpfsWorkspaceClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->access('ws-42', true);

        $request = $recording->lastRequest();
        self::assertSame(self::BASE_URL . '/api/workspaces/ws-42/access?rotate=true', (string) $request->getUri());
    }

    public function testAccessOmitsRotateQueryWhenFalse(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"username":"proxy-user","password":"proxy-pass"}'),
        ]);
        $client = new IpfsWorkspaceClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->access('ws-42', false);

        $request = $recording->lastRequest();
        self::assertSame(self::BASE_URL . '/api/workspaces/ws-42/access', (string) $request->getUri());
    }

    public function testAccessUrlEncodesWorkspaceIdInPath(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"username":"u","password":"p"}'),
        ]);
        $client = new IpfsWorkspaceClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->access('ws/42 1');

        $request = $recording->lastRequest();
        self::assertStringContainsString('/api/workspaces/ws%2F42%201/access', (string) $request->getUri());
    }

    public function testAccessMapsUsernameAndPassword(): void
    {
        $client = $this->client([
            new Response(200, [], '{"username":"proxy-user","password":"proxy-pass"}'),
        ]);

        $access = $client->access('ws-42');

        self::assertInstanceOf(WorkspaceAccess::class, $access);
        self::assertSame('proxy-user', $access->username());
        self::assertSame('proxy-pass', $access->password());
    }

    public function testAccessNon2xxPreservesTypedExceptionWithoutLeakingCredentials(): void
    {
        $client = $this->client([
            new Response(403, [], '{"error":"forbidden"}'),
        ]);

        try {
            $client->access('ws-42');
            self::fail('Expected UnexpectedStatusCodeException');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(403, $e->status());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $e->response()->body());
        }
    }

    public function testAccessMalformedBodyThrowsResponseDecodingException(): void
    {
        $client = $this->client([
            new Response(200, [], '{"username":"u"}'),
        ]);

        $this->expectException(ResponseDecodingException::class);
        $client->access('ws-42');
    }

    /* ------------------------------ attach ------------------------------ */

    public function testAttachSendsPostWithWebsiteIdBodyAndMapsWorkspace(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"id":11,"label":"main","domain":"main.example.test","status":"active","website_id":7,"created":"c","updated":"u"}'),
        ]);
        $client = new IpfsWorkspaceClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $workspace = $client->attach('11', 7);

        $request = $recording->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/workspaces/11/attach', (string) $request->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        // The attach body is exactly the sdk WorkspaceRequest {website_id}.
        self::assertSame('{"website_id":7}', (string) $request->getBody());

        self::assertInstanceOf(Workspace::class, $workspace);
        self::assertSame(11, $workspace->id());
        self::assertSame('main', $workspace->label());
        self::assertSame('active', $workspace->status());
    }

    public function testAttachUrlEncodesWorkspaceIdInPath(): void
    {
        $recording = RecordingTransport::withResponses([
            new Response(200, [], '{"id":11,"label":"main","domain":"","status":"active","created":"c","updated":"u"}'),
        ]);
        $client = new IpfsWorkspaceClient($recording->transport(), self::BASE_URL, self::API_KEY);

        $client->attach('ws/42 1', 7);

        self::assertSame(
            self::BASE_URL . '/api/workspaces/ws%2F42%201/attach',
            (string) $recording->lastRequest()->getUri(),
        );
    }

    public function testAttachNon2xxPreservesTypedExceptionWithoutLeakingKey(): void
    {
        $client = $this->client([
            new Response(409, [], '{"error":"already linked"}'),
        ]);

        try {
            $client->attach('11', 7);
            self::fail('Expected UnexpectedStatusCodeException');
        } catch (UnexpectedStatusCodeException $e) {
            self::assertSame(409, $e->status());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $e->response()->body());
        }
    }

    public function testAttachMalformedResponseThrowsResponseDecodingException(): void
    {
        $client = $this->client([
            new Response(200, [], '{"id":11}'),
        ]);

        $this->expectException(ResponseDecodingException::class);
        $client->attach('11', 7);
    }
}
