<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Admin\ConnectionView;
use LumeWeb\Cast\Environment\EnvIdentity;
use LumeWeb\Cast\Environment\EnvProblem;
use LumeWeb\Cast\Environment\EnvProblemKind;
use LumeWeb\Cast\Environment\EnvReader;
use LumeWeb\Cast\Portal\Account;
use LumeWeb\Cast\Portal\PortalFacade;
use LumeWeb\Cast\Portal\SelfIdentification;
use LumeWeb\Cast\Portal\WorkspaceResolve;
use LumeWeb\Cast\Tests\Unit\Support\RecordingTransport;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * ConnectionView is the safe, display-only mapping of the resolved
 * account/workspace (+ attached Website publish relationship) — or the
 * missing/rejected/error state — for the dashboard Connection card. It holds
 * only identifiers and statuses, and a value-free error; credentials never
 * reach the view, its serialization or the template.
 */
final class ConnectionViewTest extends TestCase
{
    private const BASE_URL = 'https://pinner.xyz:8443';
    private const SECRET_KEY = 'super-secret-account-key-abc123';
    private const AUTH_KEY = 'exchanged-auth-key-view-1a2b';
    private const RESOURCE_UUID = 'res-uuid-view-001';

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
     * The POST /api/auth/key success response that exchanges the workspace API
     * key for the login-purpose auth key account.pinner.xyz accepts.
     */
    private function exchangeResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], '{"token":"' . self::AUTH_KEY . '"}');
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

    /**
     * @param list<ResponseInterface|ClientExceptionInterface> $queue MockHandler response queue.
     */
    private function resolve(array $queue): SelfIdentification
    {
        $facade = new PortalFacade($this->completeIdentity(), RecordingTransport::withResponses($queue)->transport());

        return $facade->selfIdentify();
    }

    public function testResolvedIdentityMapsToSafeAccountWorkspaceAndWebsite(): void
    {
        $self = $this->resolve([
            $this->exchangeResponse(),
            new Response(200, [], $this->accountJson()),
            new Response(200, [], $this->resolveJson(true)),
        ]);

        $view = ConnectionView::fromSelfIdentification($self);

        self::assertSame(ConnectionView::STATE_RESOLVED, $view->state());
        self::assertTrue($view->isResolved());
        self::assertSame('Ada Lovelace', $view->accountName());
        self::assertSame('a@b.test', $view->accountEmail());
        self::assertTrue($view->verified());
        self::assertSame('main', $view->workspaceLabel());
        self::assertSame('main.example.test', $view->workspaceDomain());
        self::assertSame('active', $view->workspaceStatus());
        self::assertSame(WorkspaceResolve::PUBLISH_STATE_PUBLISHED, $view->websiteState());
        self::assertNull($view->error());
    }

    public function testResolvedIdentityWithoutWebsiteMapsToNoPublishState(): void
    {
        $self = $this->resolve([
            $this->exchangeResponse(),
            new Response(200, [], $this->accountJson()),
            new Response(200, [], $this->resolveJson(false)),
        ]);

        $view = ConnectionView::fromSelfIdentification($self);

        self::assertSame(ConnectionView::STATE_RESOLVED, $view->state());
        self::assertSame(WorkspaceResolve::PUBLISH_STATE_NONE, $view->websiteState());
    }

    public function testResolvedIdentitySerializesOnlyIdentifiers(): void
    {
        $self = $this->resolve([
            $this->exchangeResponse(),
            new Response(200, [], $this->accountJson()),
            new Response(200, [], $this->resolveJson(true)),
        ]);

        $array = ConnectionView::fromSelfIdentification($self)->toArray();

        self::assertSame('resolved', $array['state']);
        self::assertSame('Ada Lovelace', $array['account_name']);
        self::assertSame('a@b.test', $array['account_email']);
        self::assertSame('main', $array['workspace_label']);
        self::assertSame('main.example.test', $array['workspace_domain']);
        self::assertSame('active', $array['workspace_status']);
        self::assertSame(WorkspaceResolve::PUBLISH_STATE_PUBLISHED, $array['website_state']);
        // Credential values never appear in the serialized form.
        $json = json_encode($array, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::SECRET_KEY, $json);
    }

    public function testMissingEnvMapsToConfigStateWithSafeError(): void
    {
        $identity = $this->identity([EnvIdentity::PORTAL_API_URL => self::BASE_URL]);
        $facade = new PortalFacade($identity, RecordingTransport::withResponses([])->transport());
        $self = $facade->selfIdentify();

        $view = ConnectionView::fromSelfIdentification($self);

        self::assertSame(ConnectionView::STATE_CONFIG, $view->state());
        self::assertFalse($view->isResolved());
        self::assertNull($view->accountName());
        self::assertNull($view->workspaceLabel());
        self::assertNotNull($view->error());
        self::assertStringContainsString(EnvIdentity::PORTAL_API_KEY, (string) $view->error());
        self::assertStringNotContainsString(self::SECRET_KEY, (string) $view->error());
    }

    public function testMissingResourceUuidMapsToConfigStateWithSafeError(): void
    {
        $identity = $this->identity([
            EnvIdentity::PORTAL_API_URL => self::BASE_URL,
            EnvIdentity::PORTAL_API_KEY => self::SECRET_KEY,
        ]);
        $facade = new PortalFacade($identity, RecordingTransport::withResponses([])->transport());
        $self = $facade->selfIdentify();

        $view = ConnectionView::fromSelfIdentification($self);

        self::assertSame(ConnectionView::STATE_CONFIG, $view->state());
        self::assertNotNull($view->error());
        self::assertStringContainsString(EnvIdentity::COOLIFY_RESOURCE_UUID, (string) $view->error());
        self::assertStringNotContainsString(self::SECRET_KEY, (string) $view->error());
    }

    public function testResolveRejectionMapsToErrorStateWithSafeMessage(): void
    {
        $self = $this->resolve([
            $this->exchangeResponse(),
            new Response(200, [], $this->accountJson()),
            new Response(200, [], '{"error":"workspace not found for resource uuid"}'),
        ]);

        $view = ConnectionView::fromSelfIdentification($self);

        self::assertSame(ConnectionView::STATE_ERROR, $view->state());
        self::assertNotNull($view->error());
        self::assertStringNotContainsString('workspace not found for resource uuid', (string) $view->error());
        self::assertStringNotContainsString(self::SECRET_KEY, (string) $view->error());
    }

    public function testResolveHttpErrorMapsToErrorState(): void
    {
        $self = $this->resolve([
            $this->exchangeResponse(),
            new Response(200, [], $this->accountJson()),
            new Response(404, [], '{"error":"not found"}'),
        ]);

        $view = ConnectionView::fromSelfIdentification($self);

        self::assertSame(ConnectionView::STATE_ERROR, $view->state());
        self::assertNotNull($view->error());
        self::assertStringNotContainsString(self::SECRET_KEY, (string) $view->error());
    }

    public function testAuthExchangeHttpErrorMapsToErrorStateWithSafeMessage(): void
    {
        // A rejected API-key exchange (invalid/expired key) yields a safe error.
        $self = $this->resolve([
            new Response(401, [], '{"error":"invalid API key"}'),
        ]);

        $view = ConnectionView::fromSelfIdentification($self);

        self::assertSame(ConnectionView::STATE_ERROR, $view->state());
        self::assertNotNull($view->error());
        self::assertStringNotContainsString(self::SECRET_KEY, (string) $view->error());
    }

    public function testAccountHttpErrorAfterExchangeMapsToErrorStateWithSafeMessage(): void
    {
        // Exchange succeeds, but the account endpoint still rejects the bearer.
        $self = $this->resolve([
            $this->exchangeResponse(),
            new Response(401, [], '{"error":"invalid token"}'),
        ]);

        $view = ConnectionView::fromSelfIdentification($self);

        self::assertSame(ConnectionView::STATE_ERROR, $view->state());
        self::assertNotNull($view->error());
        self::assertStringNotContainsString(self::SECRET_KEY, (string) $view->error());
        self::assertStringNotContainsString(self::AUTH_KEY, (string) $view->error());
    }
}
