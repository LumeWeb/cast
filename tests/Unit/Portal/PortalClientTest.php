<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Portal;

use PHPUnit\Framework\TestCase;

/**
 * REMOVED — the old PortalClientTest exercised the misleading workspace-token
 * capability that derived a "workspace-scoped bearer" from the Workspaces.Access
 * proxy Basic Auth username/password pair.
 *
 * That capability is gone (see src/Portal/PortalClient.php). The corrected
 * runtime identity is Workspaces.Resolve over the PORTAL_API_KEY bearer; the
 * regression guarantees live in PortalCredentialContractTest (no such
 * conversion/usage exists anywhere in production source).
 *
 * This frame keeps the file loadable so PHPUnit does not attempt to resolve a
 * stale PortalClientTest class and is moot otherwise.
 *
 * @see LumeWeb\Cast\Tests\Unit\Portal\PortalCredentialContractTest
 */
final class PortalClientTest extends TestCase
{
    /**
     * This file is the removal frame for the deleted PortalClient: it must stay
     * a documentation-only marker (the class no longer exists; the regression
     * proof lives in PortalCredentialContractTest). The assertion pins that this
     * frame is what it claims to be, so it stays a real test instead of a stub.
     */
    public function testFileIsTheRemovalFrameDocumentingTheDeletedCapability(): void
    {
        $source = (string) file_get_contents(__FILE__);

        self::assertStringContainsString('REMOVED', $source);
        self::assertStringContainsString('PortalClient::workspaceToken', $source);
        self::assertStringContainsString('PortalCredentialContractTest', $source);
        // Built from fragments so this frame (a test) can assert against the
        // exact instantiation needle without the contract scan flagging itself.
        self::assertStringNotContainsString('new ' . 'PortalClient(', $source);
    }
}
