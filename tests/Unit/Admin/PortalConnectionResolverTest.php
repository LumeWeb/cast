<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use GuzzleHttp\Psr7\Response;
use LumeWeb\Cast\Admin\PortalConnectionResolver;
use LumeWeb\Cast\Environment\EnvIdentity;
use LumeWeb\Cast\Environment\EnvReader;
use LumeWeb\Cast\Persistence\TransientGateway;
use LumeWeb\Cast\Portal\SelfIdentification;
use LumeWeb\Cast\Tests\Unit\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

/**
 * PortalConnectionResolver is the lazy, never-throwing connection abstraction behind
 * the dashboard Connection card: it resolves self-identification only when
 * asked (never during boot/activation), caches per instance (so a request
 * resolves at most once), and turns any resolution failure — including an
 * unexpected transport blow-up — into a safe SelfIdentification error state
 * rather than an exception. Runtime identity flows through
 * {@see PortalFacade} -> Workspaces.Resolve (never the fragile exactly-one
 * Workspaces.List behavior).
 */
final class PortalConnectionResolverTest extends TestCase
{
    private const BASE_URL = 'https://pinner.xyz:8443';
    private const SECRET_KEY = 'super-secret-account-key-abc123';
    private const RESOURCE_UUID = 'res-uuid-resolver-001';

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

    private const AUTH_KEY = 'exchanged-auth-key-resolver-99c8';

    private const CACHE_KEY = 'cast_self_identification';

    /**
     * The POST /api/auth/key success response that exchanges the workspace API
     * key for the login-purpose auth key account.pinner.xyz accepts.
     */
    public function testSignatureChangeTreatsTheMemoAsAMiss(): void
    {
        // A memo written under one deployment identity must never serve a
        // request whose identity changed (credential rotation, re-pointed
        // portal base): the wrong identity would silently answer the
        // Connection card and every state-gated publish action.
        $gateway = new FakeTransientGateway();
        $booted = new PortalConnectionResolver(
            $this->completeIdentity(),
            RecordingTransport::withResponses([
                $this->exchangeResponse(),
                new Response(200, [], $this->accountJson()),
                new Response(200, [], $this->resolveJson()),
            ])->transport(),
            $gateway,
            'site-pepper',
        );
        $before = $booted->current();
        self::assertNotNull($before);
        self::assertSame('a@b.test', $before->account()?->email());

        // A key rotation under the same site pepper is exactly the change
        // the keyed digest must catch: the memo written under the old key is
        // a miss, and the fresh resolve answers with the new account's data.
        $rotatedRecording = RecordingTransport::withResponses([
            $this->exchangeResponse(),
            new Response(200, [], $this->accountJson()),
            new Response(200, [], $this->resolveJson()),
        ]);
        $afterRotation = new PortalConnectionResolver(
            $this->identity([
                EnvIdentity::PORTAL_API_URL => self::BASE_URL,
                EnvIdentity::PORTAL_API_KEY => 'rotated-account-key-abc',
                EnvIdentity::COOLIFY_RESOURCE_UUID => self::RESOURCE_UUID,
            ]),
            $rotatedRecording->transport(),
            $gateway,
            'site-pepper',
        );

        $self = $afterRotation->current();

        self::assertNotNull($self);
        self::assertTrue($self->isResolved());
        self::assertCount(
            3,
            $rotatedRecording->requests(),
            'A memo written under a rotated credential must be a miss, never a silent stale answer.',
        );
    }

    public function testUnresolvedStateIsNotCachedAcrossRequests(): void
    {
        // Empty MockHandler queue: the resolution fails into the safe error
        // state, which must NOT be written to the transient gateway — an error
        // pinned for the whole TTL would mask a portal recovery.
        $recording = RecordingTransport::withResponses([]);
        $gateway = new FakeTransientGateway();
        $resolver = new PortalConnectionResolver($this->completeIdentity(), $recording->transport(), $gateway);

        $resolver->current();

        self::assertNull($gateway->get(self::CACHE_KEY, null));
    }

    public function testResolvedIdentityIsMemoizedInTransientAndReusedAcrossInstances(): void
    {
        $recording = RecordingTransport::withResponses([
            $this->exchangeResponse(),
            new Response(200, [], $this->accountJson()),
            new Response(200, [], $this->resolveJson()),
        ]);
        $gateway = new FakeTransientGateway();
        $first = new PortalConnectionResolver($this->completeIdentity(), $recording->transport(), $gateway);

        $self = $first->current();
        self::assertNotNull($self);
        self::assertTrue($self->isResolved());
        self::assertCount(3, $recording->requests());
        $memo = $gateway->get(self::CACHE_KEY, null);
        self::assertIsArray($memo);
        self::assertInstanceOf(SelfIdentification::class, $memo['self'] ?? null);

        // A second resolver instance (the next request) reads the cached
        // identification instead of paying the portal exchange again.
        $emptyRecording = RecordingTransport::withResponses([]);
        $second = new PortalConnectionResolver($this->completeIdentity(), $emptyRecording->transport(), $gateway);

        $again = $second->current();

        self::assertNotNull($again);
        self::assertSame('a@b.test', $again->account()?->email());
        self::assertSame([], $emptyRecording->requests(), 'a cached resolution must not hit the transport');
    }

    private function exchangeResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], '{"token":"' . self::AUTH_KEY . '"}');
    }

    private function accountJson(): string
    {
        return '{"id":7,"email":"a@b.test","first_name":"Ada","last_name":"Lovelace","verified":true,"otp":false}';
    }

    private function resolveJson(): string
    {
        return '{"id":11,"label":"main","domain":"main.example.test","status":"active",'
            . '"created":"2026-01-01T00:00:00Z","updated":"2026-01-02T00:00:00Z"}';
    }

    public function testResolvesLazilyThroughSelfIdentification(): void
    {
        $recording = RecordingTransport::withResponses([
            $this->exchangeResponse(),
            new Response(200, [], $this->accountJson()),
            new Response(200, [], $this->resolveJson()),
        ]);
        $resolver = new PortalConnectionResolver($this->completeIdentity(), $recording->transport());

        $self = $resolver->current();

        self::assertNotNull($self);
        self::assertTrue($self->isResolved());
        self::assertSame('a@b.test', $self->account()?->email());
        self::assertCount(3, $recording->requests());
        self::assertStringNotContainsString(self::SECRET_KEY, (string) ($self->error() ?? ''));
    }

    public function testCachesAfterFirstResolution(): void
    {
        $recording = RecordingTransport::withResponses([
            $this->exchangeResponse(),
            new Response(200, [], $this->accountJson()),
            new Response(200, [], $this->resolveJson()),
        ]);
        $resolver = new PortalConnectionResolver($this->completeIdentity(), $recording->transport());

        $resolver->current();
        $again = $resolver->current();

        self::assertNotNull($again);
        self::assertCount(3, $recording->requests(), 'A request must resolve the connection at most once.');
    }

    public function testIncompleteEnvNeverTouchesTheTransport(): void
    {
        $identity = $this->identity([EnvIdentity::PORTAL_API_URL => self::BASE_URL]);
        $recording = RecordingTransport::withResponses([]);
        $resolver = new PortalConnectionResolver($identity, $recording->transport());

        $self = $resolver->current();

        self::assertNotNull($self);
        self::assertFalse($self->isResolved());
        self::assertSame([], $recording->requests(), 'An incomplete env must never send a request.');
        self::assertStringContainsString(EnvIdentity::PORTAL_API_KEY, (string) $self->error());
        self::assertStringNotContainsString(self::SECRET_KEY, (string) $self->error());
    }

    public function testUnexpectedTransportFailureBecomesSafeErrorStateNeverThrows(): void
    {
        // Empty MockHandler queue: the wrapped transport would blow up with a
        // non-HttpException; the resolver must absorb it into a safe failure.
        $recording = RecordingTransport::withResponses([]);
        $resolver = new PortalConnectionResolver($this->completeIdentity(), $recording->transport());

        $self = $resolver->current();

        self::assertNotNull($self);
        self::assertFalse($self->isResolved());
        self::assertNotNull($self->error());
        self::assertStringNotContainsString(self::SECRET_KEY, (string) $self->error());
    }
}
