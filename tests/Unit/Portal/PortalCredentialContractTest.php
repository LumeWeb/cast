<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Portal;

use LumeWeb\Cast\Portal\PortalClient;
use LumeWeb\Cast\Portal\WorkspaceToken;
use LumeWeb\Cast\Portal\WorkspaceTokenResult;
use PHPUnit\Framework\TestCase;

/**
 * Regression contract for the corrected portal credential model.
 *
 * PORTAL_API_KEY is the workspace API key (aud=api, PurposeAPI) the portal
 * injects; every publish/domain/upload/IPNS/runtime client authenticates with
 * that single bearer (the ipfs-plugin endpoints accept either an API key or a
 * login-purpose auth key). Only the account identity call (/api/account at
 * account.pinner.xyz) needs a login-purpose auth key, which Cast obtains by a
 * POST /api/auth/key exchange of PORTAL_API_KEY (PortalAuthKeyClient) — the
 * account client carries that exchanged bearer, never a proxy credential. The
 * old capability-only scaffolding (PortalClient, WorkspaceToken,
 * WorkspaceTokenResult) derived a fake "workspace token" from the
 * Workspaces.Access proxy Basic Auth username/password pair — that derivation is
 * removed and must never return. These tests prove structurally that:
 *
 *  1. the scaffolding classes no longer exist and no code instantiates/locates them;
 *  2. no production source places a Workspaces.Access proxy credential on the
 *     Authorization header (never username:password as a portal bearer);
 *  3. no production source calls Workspaces.Access to build an API credential;
 *  4. every production Authorization header on the ipfs/publish/workspace layer
 *     stays a PORTAL_API_KEY bearer, and every bearer is a plain JWT bearer
 *     (never a Basic-Auth username:password).
 */
final class PortalCredentialContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    public function testMisleadingWorkspaceTokenScaffoldingClassesAreGone(): void
    {
        self::assertFalse(
            class_exists(WorkspaceToken::class),
            'WorkspaceToken must no longer exist (proxy Basic Auth must never become a portal bearer).',
        );
        self::assertFalse(
            class_exists(WorkspaceTokenResult::class),
            'WorkspaceTokenResult must no longer exist.',
        );
        self::assertFalse(
            class_exists(PortalClient::class),
            'PortalClient must no longer exist (its workspace-token capability was misleading).',
        );
    }

    public function testNoCodeInstantiatesOrDrivesTheRemovedScaffolding(): void
    {
        $offenders = [];
        foreach ($this->phpFiles(['src', 'templates', 'tests'], $this->self()) as $file) {
            $content = (string) file_get_contents($file);
            $needles = [
                'new WorkspaceToken(',
                'new WorkspaceTokenResult(',
                'new PortalClient(',
                '->workspaceToken(',
                'fromAccess',
            ];
            foreach ($needles as $needle) {
                if (str_contains($content, $needle)) {
                    $offenders[] = "{$file} contains {$needle}";
                }
            }
        }

        self::assertSame([], $offenders, 'No instantiation/usage of the removed workspace-token scaffolding may survive.');
    }

    public function testAuthorizationHeadersAreAlwaysPortalApiKeyBearerNeverUsernamePassword(): void
    {
        // The portal bearer client layers: every actual request header must be
        // `Authorization => Bearer <PORTAL_API_KEY>`. The export probe/capture
        // transports are deliberately excluded — they are strictly anonymous
        // (never Basic Auth, never any Authorization header) and never touch
        // the portal API.
        $offenders = [];
        foreach ($this->phpFiles(['src/Portal', 'src/Ipfs', 'src/Publish', 'src/Upload', 'src/Jobs']) as $file) {
            $lines = file($file);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $lineNo => $line) {
                if (!str_contains($line, "'Authorization'")) {
                    continue;
                }
                if (!str_contains($line, "'Bearer '")) {
                    $offenders[] = sprintf(
                        '%s:%d — Authorization header not sourced from the PORTAL_API_KEY bearer.',
                        $file,
                        $lineNo + 1,
                    );
                }
                if (preg_match('/username|password/i', $line) === 1) {
                    $offenders[] = sprintf(
                        '%s:%d — Authorization header references a proxy username/password (forbidden).',
                        $file,
                        $lineNo + 1,
                    );
                }
            }
        }

        self::assertSame([], $offenders);
    }

    public function testWorkspacesAccessIsNeverUsedToBuildAnApiCredential(): void
    {
        $offenders = [];
        foreach ($this->phpFiles(['src']) as $file) {
            $lines = file($file);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $lineNo => $line) {
                if (preg_match('/->access\s*\(/', $line) === 1) {
                    $offenders[] = sprintf('%s:%d — Workspaces.Access call (proxy Basic Auth) used in production.', $file, $lineNo + 1);
                }
            }
        }

        self::assertSame([], $offenders, 'Workspaces.Access proxy credentials must never be consumed to authenticate API clients.');
    }

    public function testWorkspaceAuthPairIsNeverPlacedOnAnApiRequest(): void
    {
        // The WorkspaceAuth value object exists only as a memory-held
        // deployment reference; it must never appear on an Authorization header.
        $offenders = [];
        foreach ($this->phpFiles(['src']) as $file) {
            $lines = file($file);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $lineNo => $line) {
                if (
                    str_contains($line, 'Authorization')
                    && (str_contains($line, 'username()') || str_contains($line, 'password()'))
                ) {
                    $offenders[] = sprintf('%s:%d — WorkspaceAuth credential placed on an Authorization header.', $file, $lineNo + 1);
                }
            }
        }

        self::assertSame([], $offenders);
    }

    /**
     * @param list<string> $dirs
     * @param list<string> $exclude optional basenames to skip (e.g. this file itself)
     * @return list<string>
     */
    private function phpFiles(array $dirs, array $exclude = []): array
    {
        $files = [];
        foreach ($dirs as $dir) {
            $path = $this->root . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $fileInfo) {
                if (
                    $fileInfo->isFile()
                    && $fileInfo->getExtension() === 'php'
                    && !in_array($fileInfo->getBasename(), $exclude, true)
                ) {
                    $files[] = $fileInfo->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * This test's own basename, so the scan cannot flag its guard strings.
     *
     * @return list<string>
     */
    private function self(): array
    {
        return [basename(__FILE__)];
    }
}
