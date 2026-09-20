<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Portal;

use GuzzleHttp\Psr7\Response;
use LumeWeb\Cast\Environment\EnvIdentity;
use LumeWeb\Cast\Environment\EnvProblem;
use LumeWeb\Cast\Environment\EnvProblemKind;
use LumeWeb\Cast\Environment\EnvReader;
use LumeWeb\Cast\Http\HttpException;
use LumeWeb\Cast\Http\ResponseDecodingException;
use LumeWeb\Cast\Http\UnexpectedStatusCodeException;
use LumeWeb\Cast\Portal\Account;
use LumeWeb\Cast\Portal\PortalFacade;
use LumeWeb\Cast\Portal\SelfIdentification;
use LumeWeb\Cast\Portal\WorkspaceResolve;
use LumeWeb\Cast\Tests\Unit\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PortalFacade is the runtime self-identification facade. PORTAL_API_URL is the
 * canonical portal base; the auth-key exchange + GetAccount are routed to the
 * derived account base (account.pinner.xyz) and Workspaces.Resolve is routed to
 * the derived IPFS/workspace API base (ipfs.pinner.xyz). account.pinner.xyz only
 * accepts a login-purpose auth key on /api/account, so selfIdentify first
 * exchanges the workspace API key (PORTAL_API_KEY, aud=api) for an auth key via
 * POST /api/auth/key, then resolves the account via GetAccount and THE
 * workspace via Workspaces.Resolve (GET /api/workspaces/resolve?resource_uuid=...)
 * — resolved with the PORTAL_API_KEY bearer that the ipfs endpoint accepts
 * either way; never the fragile exactly-one Workspaces.List behavior. All
 * operator-facing errors are value-free — they name env vars and failure
 * classes, never credentials, never the exchanged auth key.
 */
final class PortalFacadeTest extends TestCase
{
    private const BASE_URL = 'https://pinner.xyz:8443';
    private const ACCOUNT_BASE_URL = 'https://account.pinner.xyz:8443';
    private const IPFS_BASE_URL = 'https://ipfs.pinner.xyz:8443';
    private const SECRET_KEY = 'super-secret-account-key-abc123';
    private const AUTH_KEY = 'exchanged-auth-key-7t9x';
    private const RESOURCE_UUID = 'res-uuid-facade-001';

    /**
     * @param array<string, string|false> $vars
     */
    private function identity(array $vars): EnvIdentity
    {
        $reader = new class ($vars) implements EnvReader {
            /** @var array<string, string|false> */
            private array $vars;

            /**
             * @param array<string, string|false> $vars
             */
            public function __construct(array $vars)
            {
                $this->vars = $vars;
            }

            public function get(string $name): string|false
            {
                return $this->vars[$name] ?? false;
            }
        };

        return new EnvIdentity($reader);
    }

    private function completeIdentity(): EnvIdentity
    {
        return $this->identity([
            EnvIdentity::PORTAL_API_URL => self::BASE_URL,
            EnvIdentity::PORTAL_API_KEY => self::SECRET_KEY,
            EnvIdentity::COOLIFY_RESOURCE_UUID => self::RESOURCE_UUID,
        ]);
    }

    /**
     * The POST /api/auth/key success response: exchanges the API key for a
     * login-purpose auth key carried in the JSON "token" field (dto.LoginResponse).
     */
    private function exchangeResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], '{"token":"' . self::AUTH_KEY . '"}');
    }

    /**
     * @param list<ResponseInterface|ClientExceptionInterface> $queue
     */
    private function facade(EnvIdentity $identity, array $queue): PortalFacade
    {
        return new PortalFacade($identity, RecordingTransport::withResponses($queue)->transport());
    }

    private function accountJson(): string
    {
        return '{"id":7,"email":"a@b.test","first_name":"Ada","last_name":"Lovelace","verified":true,"otp":false}';
    }

    private function resolveJson(bool $withWebsite = false): string
    {
        $json = '{"id":11,"label":"main","domain":"main.example.test","status":"active",'
            . '"created":"2026-01-01T00:00:00Z","updated":"2026-01-02T00:00:00Z"';
        if ($withWebsite) {
            $json .= ',"website_id":42,"website":{"id":42,"status":"active","target_hash":"QmAbc","target_type":"car"}';
        }

        return $json . '}';
    }

    public function testResolvesIdentityViaWorkspaceResolve(): void
    {
        $facade = $this->facade($this->completeIdentity(), [
            $this->exchangeResponse(),
            new Response(200, [], $this->accountJson()),
            new Response(200, [], $this->resolveJson(true)),
        ]);

        $result = $facade->selfIdentify();

        self::assertTrue($result->isResolved());
        self::assertInstanceOf(SelfIdentification::class, $result);
        self::assertNull($result->error());
        self::assertSame([], $result->problems());

        $account = $result->account();
        self::assertInstanceOf(Account::class, $account);
        self::assertSame(7, $account->id());
        self::assertSame('a@b.test', $account->email());

        $workspace = $result->workspace();
        self::assertNotNull($workspace);
        self::assertSame(11, $workspace->id());
        self::assertSame('main', $workspace->label());
        self::assertSame('main.example.test', $workspace->domain());
        self::assertSame('active', $workspace->status());

        // The attached Website publish relationship is carried as a safe
        // runtime identity/status value.
        self::assertNotNull($result->website());
        self::assertSame(42, $result->website()->id());
        self::assertSame(WorkspaceResolve::PUBLISH_STATE_PUBLISHED, $result->publishState());

        $identity = $result->portalIdentity();
        self::assertNotNull($identity);
        self::assertSame(self::BASE_URL, $identity->portalBaseUrl());
        self::assertSame(self::ACCOUNT_BASE_URL, $identity->accountBaseUrl());
        self::assertSame(self::IPFS_BASE_URL, $identity->ipfsBaseUrl());
        self::assertSame(self::RESOURCE_UUID, $identity->resourceUuid());
    }

    public function testSelfIdentifyExchangesApiKeyThenSendsAccountThenResolveRequests(): void
    {
        $recording = RecordingTransport::withResponses([
            $this->exchangeResponse(),
            new Response(200, [], $this->accountJson()),
            new Response(200, [], $this->resolveJson()),
        ]);
        $facade = new PortalFacade($this->completeIdentity(), $recording->transport());

        $facade->selfIdentify();

        // Exactly three requests: auth-key exchange, GetAccount, then
        // Workspaces.Resolve — never the exactly-one Workspaces.List call, and
        // never Workspaces.Access.
        self::assertCount(3, $recording->requests());
        $exchangeRequest = $recording->requests()[0];
        $accountRequest = $recording->requests()[1];
        $resolveRequest = $recording->requests()[2];

        // The exchange carries the workspace API key (PORTAL_API_KEY) bearer
        // and is routed to the ACCOUNT base (account.pinner.xyz), like every
        // /api/auth/* and /api/account call.
        self::assertSame('POST', $exchangeRequest->getMethod());
        self::assertSame(self::ACCOUNT_BASE_URL . '/api/auth/key', (string) $exchangeRequest->getUri());
        self::assertSame('Bearer ' . self::SECRET_KEY, $exchangeRequest->getHeaderLine('Authorization'));
        self::assertSame('', (string) $exchangeRequest->getBody());

        // GetAccount carries the exchanged login-purpose auth key (not the API
        // key) and is routed to the ACCOUNT base.
        self::assertSame('GET', $accountRequest->getMethod());
        self::assertSame(self::ACCOUNT_BASE_URL . '/api/account', (string) $accountRequest->getUri());
        self::assertSame('Bearer ' . self::AUTH_KEY, $accountRequest->getHeaderLine('Authorization'));

        // Workspaces.Resolve is an ipfs-plugin endpoint accepting either key
        // kind, so it keeps the PORTAL_API_KEY bearer and is routed to the
        // IPFS/workspace API base (ipfs.pinner.xyz).
        self::assertSame('GET', $resolveRequest->getMethod());
        self::assertSame(
            self::IPFS_BASE_URL . '/api/workspaces/resolve?resource_uuid=' . self::RESOURCE_UUID,
            (string) $resolveRequest->getUri(),
        );
        self::assertSame('Bearer ' . self::SECRET_KEY, $resolveRequest->getHeaderLine('Authorization'));
        self::assertStringNotContainsString('/api/workspaces?', (string) $resolveRequest->getUri());
        self::assertStringNotContainsString('/access', (string) $resolveRequest->getUri());
    }

    public function testResolvedWithoutWebsiteExposesNonePublishState(): void
    {
        $facade = $this->facade($this->completeIdentity(), [
            $this->exchangeResponse(),
            new Response(200, [], $this->accountJson()),
            new Response(200, [], $this->resolveJson(false)),
        ]);

        $result = $facade->selfIdentify();

        self::assertTrue($result->isResolved());
        self::assertNull($result->website());
        self::assertSame(WorkspaceResolve::PUBLISH_STATE_NONE, $result->publishState());
    }

    public function testMissingResourceUuidReturnsSafeErrorNamingVariableWithoutNetwork(): void
    {
        $identity = $this->identity([
            EnvIdentity::PORTAL_API_URL => self::BASE_URL,
            EnvIdentity::PORTAL_API_KEY => self::SECRET_KEY,
        ]);
        $recording = RecordingTransport::withResponses([]);
        $facade = new PortalFacade($identity, $recording->transport());

        $result = $facade->selfIdentify();

        self::assertFalse($result->isResolved());
        self::assertNotNull($result->error());
        self::assertStringContainsString(EnvIdentity::COOLIFY_RESOURCE_UUID, (string) $result->error());
        self::assertStringNotContainsString(self::SECRET_KEY, (string) $result->error());
        self::assertSame([], $recording->requests(), 'A missing resource UUID must never send a request.');

        $problems = $result->problems();
        self::assertNotEmpty($problems);
        $resourceProblem = null;
        foreach ($problems as $problem) {
            if ($problem->variable() === EnvIdentity::COOLIFY_RESOURCE_UUID) {
                $resourceProblem = $problem;
            }
        }
        self::assertNotNull($resourceProblem, 'Expected a COOLIFY_RESOURCE_UUID problem.');
        self::assertSame(EnvProblemKind::Missing, $resourceProblem->kind());
    }

    public function testMissingKeyReturnsSafeErrorNamingVariable(): void
    {
        $identity = $this->identity([
            EnvIdentity::PORTAL_API_URL => self::BASE_URL,
        ]);
        $facade = $this->facade($identity, []);

        $result = $facade->selfIdentify();

        self::assertFalse($result->isResolved());
        self::assertNull($result->account());
        self::assertNull($result->workspace());
        self::assertNotNull($result->error());
        self::assertStringContainsString(EnvIdentity::PORTAL_API_KEY, (string) $result->error());
        self::assertStringNotContainsString(self::SECRET_KEY, (string) $result->error());

        $problems = $result->problems();
        self::assertNotEmpty($problems);
        self::assertContainsOnlyInstancesOf(EnvProblem::class, $problems);
        self::assertSame(EnvProblemKind::Missing, $problems[0]->kind());
    }

    public function testEmptyKeyReturnsSafeError(): void
    {
        $identity = $this->identity([
            EnvIdentity::PORTAL_API_URL => self::BASE_URL,
            EnvIdentity::PORTAL_API_KEY => '',
        ]);
        $facade = $this->facade($identity, []);

        $result = $facade->selfIdentify();

        self::assertFalse($result->isResolved());
        self::assertStringContainsString(EnvProblemKind::Empty->value, (string) $result->error());
        self::assertSame(EnvProblemKind::Empty, $result->problems()[0]->kind());
    }

    public function testMalformedUrlReturnsSafeError(): void
    {
        $identity = $this->identity([
            EnvIdentity::PORTAL_API_URL => 'not a url',
            EnvIdentity::PORTAL_API_KEY => self::SECRET_KEY,
        ]);
        $facade = $this->facade($identity, []);

        $result = $facade->selfIdentify();

        self::assertFalse($result->isResolved());
        self::assertStringContainsString(EnvIdentity::PORTAL_API_URL, (string) $result->error());
        self::assertStringNotContainsString('not a url', (string) $result->error());
        self::assertSame(EnvProblemKind::Malformed, $result->problems()[0]->kind());
    }

    public function testResolveRejectedReturnsSafeActionableError(): void
    {
        // 200 with a server-side error field: the portal could not resolve a
        // workspace for this resource UUID/key.
        $facade = $this->facade($this->completeIdentity(), [
            $this->exchangeResponse(),
            new Response(200, [], $this->accountJson()),
            new Response(200, [], '{"error":"workspace not found for resource uuid"}'),
        ]);

        $result = $facade->selfIdentify();

        self::assertFalse($result->isResolved());
        self::assertNull($result->workspace());
        self::assertNotNull($result->error());
        self::assertStringNotContainsString('workspace not found for resource uuid', (string) $result->error());
        self::assertStringNotContainsString(self::SECRET_KEY, (string) $result->error());
    }

    public function testResolveNotFoundReturnsSafeErrorPreservingTypedCause(): void
    {
        // A mismatch returns 404; the safe error must name the resource UUID
        // config and preserve the typed cause without leaking values.
        $facade = $this->facade($this->completeIdentity(), [
            $this->exchangeResponse(),
            new Response(200, [], $this->accountJson()),
            new Response(404, [], '{"error":"not found"}'),
        ]);

        $result = $facade->selfIdentify();

        self::assertFalse($result->isResolved());
        self::assertNull($result->workspace());
        self::assertNotNull($result->error());
        self::assertStringContainsString(EnvIdentity::COOLIFY_RESOURCE_UUID, (string) $result->error());
        self::assertStringContainsString('404', (string) $result->error());
        self::assertStringNotContainsString(self::SECRET_KEY, (string) $result->error());
        self::assertInstanceOf(UnexpectedStatusCodeException::class, $result->cause());
        self::assertSame(404, $result->cause()->status());
    }

    public function testExchange401ReturnsSafeErrorPreservingTypedCause(): void
    {
        // An invalid/expired API key makes the POST /api/auth/key exchange
        // reject with 401 — the reported account identity failure.
        $facade = $this->facade($this->completeIdentity(), [
            new Response(401, [], '{"error":"invalid API key"}'),
        ]);

        $result = $facade->selfIdentify();

        self::assertFalse($result->isResolved());
        self::assertNotNull($result->error());
        self::assertStringNotContainsString(self::SECRET_KEY, (string) $result->error());
        self::assertStringContainsString('401', (string) $result->error());
        self::assertInstanceOf(UnexpectedStatusCodeException::class, $result->cause());
        self::assertSame(401, $result->cause()->status());
    }

    public function testAccount401AfterSuccessfulExchangeReturnsSafeErrorPreservingTypedCause(): void
    {
        // Exchange succeeds, but the account endpoint still rejects the bearer.
        $facade = $this->facade($this->completeIdentity(), [
            $this->exchangeResponse(),
            new Response(401, [], '{"error":"invalid token"}'),
        ]);

        $result = $facade->selfIdentify();

        self::assertFalse($result->isResolved());
        self::assertNotNull($result->error());
        self::assertStringNotContainsString(self::SECRET_KEY, (string) $result->error());
        self::assertStringNotContainsString(self::AUTH_KEY, (string) $result->error());
        self::assertInstanceOf(UnexpectedStatusCodeException::class, $result->cause());
        self::assertSame(401, $result->cause()->status());
    }

    public function testMalformedExchangeResponseReturnsSafeErrorPreservingTypedCause(): void
    {
        $facade = $this->facade($this->completeIdentity(), [
            new Response(200, [], '<html>nope</html>'),
        ]);

        $result = $facade->selfIdentify();

        self::assertFalse($result->isResolved());
        self::assertNotNull($result->error());
        self::assertInstanceOf(ResponseDecodingException::class, $result->cause());
    }

    public function testMalformedAccountResponseReturnsSafeErrorPreservingTypedCause(): void
    {
        $facade = $this->facade($this->completeIdentity(), [
            $this->exchangeResponse(),
            new Response(200, [], '<html>nope</html>'),
        ]);

        $result = $facade->selfIdentify();

        self::assertFalse($result->isResolved());
        self::assertNotNull($result->error());
        self::assertInstanceOf(ResponseDecodingException::class, $result->cause());
    }

    public function testResolveHttpFailureReturnsSafeError(): void
    {
        $facade = $this->facade($this->completeIdentity(), [
            $this->exchangeResponse(),
            new Response(200, [], $this->accountJson()),
            new Response(500, [], '{"error":"boom"}'),
        ]);

        $result = $facade->selfIdentify();

        self::assertFalse($result->isResolved());
        self::assertNotNull($result->error());
        self::assertInstanceOf(HttpException::class, $result->cause());
        self::assertStringNotContainsString(self::SECRET_KEY, (string) $result->error());
    }

    public function testFacadeErrorsNeverLeakSecretKey(): void
    {
        $identity = $this->identity([
            EnvIdentity::PORTAL_API_URL => self::BASE_URL,
            EnvIdentity::PORTAL_API_KEY => self::SECRET_KEY,
            EnvIdentity::WORKSPACE_AUTH_USERNAME => 'operator',
        ]);
        $facade = $this->facade($identity, [
            new Response(401, [], '{"error":"bad"}'),
        ]);

        $result = $facade->selfIdentify();

        $allText = ($result->error() ?? '') . implode(' ', array_map(
            static fn (EnvProblem $p): string => $p->message(),
            $result->problems(),
        ));
        self::assertStringNotContainsString(self::SECRET_KEY, $allText);
        // Problem messages must not echo the offending value either.
        self::assertStringNotContainsString('operator', $allText);
    }
}
