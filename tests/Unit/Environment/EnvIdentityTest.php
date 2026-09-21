<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Environment;

use LumeWeb\Cast\Environment\EnvIdentity;
use LumeWeb\Cast\Environment\EnvProblemKind;
use LumeWeb\Cast\Environment\EnvReader;
use LumeWeb\Cast\Environment\PortalIdentity;
use LumeWeb\Cast\Environment\WorkspaceAuth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnvIdentityTest extends TestCase
{
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

    /**
     * @return array<string, string>
     */
    private function completeVars(): array
    {
        return [
            EnvIdentity::PORTAL_API_URL => 'https://pinner.xyz:8443',
            EnvIdentity::PORTAL_API_KEY => 'secret-account-key',
            EnvIdentity::WORKSPACE_AUTH_USERNAME => 'operator',
            EnvIdentity::WORKSPACE_AUTH_PASSWORD => 'workspace-pass',
            EnvIdentity::PORTAL_WORKSPACE_URL => 'https://cast.example.test',
            EnvIdentity::COOLIFY_RESOURCE_UUID => 'res-uuid-abc-123',
        ];
    }

    public function testSignatureIsStableForTheSameEnvironment(): void
    {
        self::assertNotNull($this->identity($this->completeVars())->signature());
        self::assertSame(
            $this->identity($this->completeVars())->signature(),
            $this->identity($this->completeVars())->signature(),
        );
    }

    public function testSignatureChangesWhenAnIdentityVariableChanges(): void
    {
        $base = $this->identity($this->completeVars())->signature();

        $rotatedUrl = $this->completeVars();
        $rotatedUrl[EnvIdentity::PORTAL_API_URL] = 'https://other.pinner.test';

        self::assertNotSame($base, $this->identity($rotatedUrl)->signature());
    }

    public function testSignatureChangesWhenAnOptionalIdentityVariableChanges(): void
    {
        $base = $this->identity($this->completeVars())->signature();

        $changedUuid = $this->completeVars();
        $changedUuid[EnvIdentity::COOLIFY_RESOURCE_UUID] = 'res-uuid-other-456';

        self::assertNotSame($base, $this->identity($changedUuid)->signature());
    }

    public function testSignatureExcludesCredentialValues(): void
    {
        // The signature is persisted in the memo transient, so it must never
        // carry a hashed credential: a DB reader could otherwise brute-force
        // a low-entropy key offline. Credential rotation on the same
        // workspace deliberately keeps the memo (the self-identification it
        // holds is workspace-scoped and TTL-bounded, and the digest carries
        // no secret to betray anything).
        $base = $this->identity($this->completeVars())->signature();
        self::assertNotNull($base);

        $rotatedKey = $this->completeVars();
        $rotatedKey[EnvIdentity::PORTAL_API_KEY] = 'rotated-account-key';
        $rotatedPassword = $this->completeVars();
        $rotatedPassword[EnvIdentity::WORKSPACE_AUTH_PASSWORD] = 'other-workspace-pass';

        self::assertSame($base, $this->identity($rotatedKey)->signature());
        self::assertSame($base, $this->identity($rotatedPassword)->signature());
    }

    public function testSignatureStillTracksAccountDistinguishingVariables(): void
    {
        $base = $this->identity($this->completeVars())->signature();

        $changedUsername = $this->completeVars();
        $changedUsername[EnvIdentity::WORKSPACE_AUTH_USERNAME] = 'other-operator';
        $changedWorkspaceUrl = $this->completeVars();
        $changedWorkspaceUrl[EnvIdentity::PORTAL_WORKSPACE_URL] = 'https://elsewhere.example.test';

        self::assertNotSame($base, $this->identity($changedUsername)->signature());
        self::assertNotSame($base, $this->identity($changedWorkspaceUrl)->signature());
    }

    public function testSignatureIsValueFree(): void
    {
        $signature = (string) $this->identity($this->completeVars())->signature();

        self::assertSame(64, strlen($signature));
        self::assertStringNotContainsString('secret-account-key', $signature);
        self::assertStringNotContainsString('pinner.xyz', $signature);
        self::assertStringNotContainsString('operator', $signature);
    }

    public function testSignatureIsNullWhenTheIdentityIsIncomplete(): void
    {
        self::assertNull($this->identity([EnvIdentity::PORTAL_API_URL => 'https://pinner.xyz'])->signature());
    }

    public function testReadsCompleteIdentityFromEnvironment(): void
    {
        $identity = $this->identity([
            EnvIdentity::PORTAL_API_URL => 'https://pinner.xyz:8443',
            EnvIdentity::PORTAL_API_KEY => 'secret-account-key',
            EnvIdentity::WORKSPACE_AUTH_USERNAME => 'operator',
            EnvIdentity::WORKSPACE_AUTH_PASSWORD => 'workspace-pass',
            EnvIdentity::PORTAL_WORKSPACE_URL => 'https://cast.example.test',
            EnvIdentity::COOLIFY_RESOURCE_UUID => 'res-uuid-abc-123',
        ]);

        self::assertTrue($identity->isComplete());
        self::assertSame([], $identity->problems());

        $config = $identity->resolve();
        self::assertNotNull($config);
        self::assertSame('https://pinner.xyz:8443', $config->portalBaseUrl());
        self::assertSame('https://account.pinner.xyz:8443', $config->accountBaseUrl());
        self::assertSame('https://ipfs.pinner.xyz:8443', $config->ipfsBaseUrl());
        self::assertSame('secret-account-key', $config->accountKey());

        $workspaceAuth = $config->workspaceAuth();
        self::assertInstanceOf(WorkspaceAuth::class, $workspaceAuth);
        self::assertSame('operator', $workspaceAuth->username());
        self::assertSame('workspace-pass', $workspaceAuth->password());
        self::assertSame('https://cast.example.test', $config->workspaceUrl());
        self::assertSame('res-uuid-abc-123', $config->resourceUuid());
    }

    public function testTrimsWhitespaceButPreservesCanonicalBase(): void
    {
        $identity = $this->identity([
            EnvIdentity::PORTAL_API_URL => '  https://pinner.xyz:8443/  ',
            EnvIdentity::PORTAL_API_KEY => "  \tkey-with-padding \n",
        ]);

        self::assertTrue($identity->isComplete());
        $config = $identity->resolve();
        self::assertNotNull($config);
        self::assertSame('https://pinner.xyz:8443', $config->portalBaseUrl());
        self::assertSame('https://account.pinner.xyz:8443', $config->accountBaseUrl());
        self::assertSame('https://ipfs.pinner.xyz:8443', $config->ipfsBaseUrl());
        self::assertSame('key-with-padding', $config->accountKey());
    }

    public function testPortalBaseUrlKeepsTrailingSlashOnlyWhenPathPresent(): void
    {
        $identity = $this->identity([
            EnvIdentity::PORTAL_API_URL => 'https://pinner.xyz/api/v1/',
            EnvIdentity::PORTAL_API_KEY => 'k',
        ]);

        $config = $identity->resolve();
        self::assertNotNull($config);
        self::assertSame('https://pinner.xyz/api/v1', $config->portalBaseUrl());
        self::assertSame('https://account.pinner.xyz/api/v1', $config->accountBaseUrl());
        self::assertSame('https://ipfs.pinner.xyz/api/v1', $config->ipfsBaseUrl());
    }

    /**
     * @param array<string, string|false> $vars
     */
    #[DataProvider('missingOrEmptyCases')]
    public function testReportsMissingOrEmptyWithoutValues(array $vars, string $variable, EnvProblemKind $kind): void
    {
        $identity = $this->identity($vars);

        self::assertFalse($identity->isComplete());
        self::assertNull($identity->resolve());

        $problems = $identity->problems();
        self::assertNotEmpty($problems);

        $problem = $problems[0];
        self::assertSame($variable, $problem->variable());
        self::assertSame($kind, $problem->kind());
        // Safe message names the variable but never exposes any value.
        self::assertStringContainsString($variable, $problem->message());
    }

    /**
     * @return array<string, array{0: array<string, string|false>, 1: string, 2: EnvProblemKind}>
     */
    public static function missingOrEmptyCases(): array
    {
        return [
            'portal url missing' => [
                ['PORTAL_API_KEY' => 'k'],
                EnvIdentity::PORTAL_API_URL,
                EnvProblemKind::Missing,
            ],
            'portal url empty' => [
                ['PORTAL_API_URL' => '', 'PORTAL_API_KEY' => 'k'],
                EnvIdentity::PORTAL_API_URL,
                EnvProblemKind::Empty,
            ],
            'account key missing' => [
                ['PORTAL_API_URL' => 'https://account.example.test'],
                EnvIdentity::PORTAL_API_KEY,
                EnvProblemKind::Missing,
            ],
            'account key empty' => [
                ['PORTAL_API_URL' => 'https://account.example.test', 'PORTAL_API_KEY' => ''],
                EnvIdentity::PORTAL_API_KEY,
                EnvProblemKind::Empty,
            ],
        ];
    }

    #[DataProvider('malformedPortalUrlCases')]
    public function testReportsMalformedPortalUrlWithoutValues(string $url): void
    {
        $identity = $this->identity([
            EnvIdentity::PORTAL_API_URL => $url,
            EnvIdentity::PORTAL_API_KEY => 'k',
        ]);

        self::assertFalse($identity->isComplete());
        self::assertNull($identity->resolve());

        $problems = $identity->problems();
        self::assertNotEmpty($problems);
        $problem = $problems[0];
        self::assertSame(EnvIdentity::PORTAL_API_URL, $problem->variable());
        self::assertSame(EnvProblemKind::Malformed, $problem->kind());
        // The malformed *value* must never appear in the safe message.
        self::assertStringNotContainsString($url, $problem->message());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedPortalUrlCases(): array
    {
        return [
            'ftp scheme' => ['ftp://account.example.test'],
            'no scheme' => ['account.example.test'],
            'malformed' => ['not a url'],
            'empty host' => ['https://'],
            'contains credentials' => ['https://user:pass@account.example.test'],
            'relative path' => ['//example.test/path'],
        ];
    }

    public function testWorkspaceAuthOptionalAbsent(): void
    {
        $identity = $this->identity([
            EnvIdentity::PORTAL_API_URL => 'https://account.example.test',
            EnvIdentity::PORTAL_API_KEY => 'k',
        ]);

        self::assertTrue($identity->isComplete());
        $config = $identity->resolve();
        self::assertNotNull($config);
        self::assertNull($config->workspaceAuth());
    }

    public function testWorkspaceAuthOptionalPartialIsInconsistent(): void
    {
        $identity = $this->identity([
            EnvIdentity::PORTAL_API_URL => 'https://account.example.test',
            EnvIdentity::PORTAL_API_KEY => 'k',
            EnvIdentity::WORKSPACE_AUTH_USERNAME => 'operator',
        ]);

        self::assertFalse($identity->isComplete());
        $problems = $identity->problems();
        self::assertNotEmpty($problems);
        self::assertSame(EnvProblemKind::Inconsistent, $problems[0]->kind());
        self::assertStringContainsString('WORKSPACE_AUTH', $problems[0]->message());
        self::assertStringNotContainsString('operator', $problems[0]->message());
    }

    public function testWorkspaceUrlAndResourceUuidOptionalAbsent(): void
    {
        $identity = $this->identity([
            EnvIdentity::PORTAL_API_URL => 'https://account.example.test',
            EnvIdentity::PORTAL_API_KEY => 'k',
        ]);

        // Neither optional value is available, but the publish identity is
        // still complete (PORTAL_API_URL + PORTAL_API_KEY only).
        self::assertTrue($identity->isComplete());
        $config = $identity->resolve();
        self::assertNotNull($config);
        self::assertNull($config->workspaceUrl());
        self::assertNull($config->resourceUuid());
    }

    public function testResourceUuidMissingReportsSafeProblemWithoutBreakingPublishIdentity(): void
    {
        $identity = $this->identity([
            EnvIdentity::PORTAL_API_URL => 'https://account.example.test',
            EnvIdentity::PORTAL_API_KEY => 'k',
        ]);

        // The publish identity remains complete without the resource UUID.
        self::assertTrue($identity->isComplete());

        $problem = $identity->resourceUuidProblem();
        self::assertNotNull($problem);
        self::assertSame(EnvIdentity::COOLIFY_RESOURCE_UUID, $problem->variable());
        self::assertSame(EnvProblemKind::Missing, $problem->kind());
        // Value-free message names the variable, never any value.
        self::assertStringContainsString(EnvIdentity::COOLIFY_RESOURCE_UUID, $problem->message());
        self::assertStringNotContainsString('k', $problem->message());
    }

    public function testResourceUuidEmptyReportsSafeProblem(): void
    {
        $identity = $this->identity([
            EnvIdentity::PORTAL_API_URL => 'https://account.example.test',
            EnvIdentity::PORTAL_API_KEY => 'k',
            EnvIdentity::COOLIFY_RESOURCE_UUID => '',
        ]);

        self::assertTrue($identity->isComplete());

        $problem = $identity->resourceUuidProblem();
        self::assertNotNull($problem);
        self::assertSame(EnvIdentity::COOLIFY_RESOURCE_UUID, $problem->variable());
        self::assertSame(EnvProblemKind::Empty, $problem->kind());
    }

    public function testResourceUuidPresentHasNoProblem(): void
    {
        $identity = $this->identity([
            EnvIdentity::PORTAL_API_URL => 'https://account.example.test',
            EnvIdentity::PORTAL_API_KEY => 'k',
            EnvIdentity::COOLIFY_RESOURCE_UUID => 'res-xyz',
        ]);

        self::assertNull($identity->resourceUuidProblem());
        self::assertSame('res-xyz', $identity->resolve()?->resourceUuid());
    }

    public function testSecretValuesNeverAppearInAnyProblem(): void
    {
        $secretKey = 'top-secret-account-key';
        $secretPass = 'top-secret-workspace-pass';
        $identity = $this->identity([
            EnvIdentity::PORTAL_API_URL => 'https://user:pass@account.example.test',
            EnvIdentity::PORTAL_API_KEY => $secretKey,
            EnvIdentity::WORKSPACE_AUTH_USERNAME => 'operator',
            EnvIdentity::WORKSPACE_AUTH_PASSWORD => $secretPass,
        ]);

        self::assertFalse($identity->isComplete());
        foreach ($identity->problems() as $problem) {
            self::assertStringNotContainsString($secretKey, $problem->message());
            self::assertStringNotContainsString($secretPass, $problem->message());
        }
    }

    public function testResolveIsDeterministicForIdenticalEnvironment(): void
    {
        $vars = [
            EnvIdentity::PORTAL_API_URL => 'https://account.example.test:443',
            EnvIdentity::PORTAL_API_KEY => 'k',
            EnvIdentity::WORKSPACE_AUTH_USERNAME => 'operator',
            EnvIdentity::WORKSPACE_AUTH_PASSWORD => 'pass',
            EnvIdentity::PORTAL_WORKSPACE_URL => 'https://cast.example.test',
            EnvIdentity::COOLIFY_RESOURCE_UUID => 'res-uuid-1',
        ];

        $first = $this->identity($vars)->resolve();
        $second = $this->identity($vars)->resolve();

        // Deterministic: identical env produces value-equal configurations.
        self::assertEquals($first, $second);
        self::assertSame($vars, $vars);
    }

    public function testRequiredVariableNamesAreExact(): void
    {
        self::assertSame(
            ['PORTAL_API_URL', 'PORTAL_API_KEY'],
            EnvIdentity::requiredVariableNames()
        );
    }

    public function testOptionalVariableNamesAreExact(): void
    {
        self::assertSame(
            ['WORKSPACE_AUTH_USERNAME', 'WORKSPACE_AUTH_PASSWORD', 'PORTAL_WORKSPACE_URL'],
            EnvIdentity::optionalVariableNames()
        );
    }
}
