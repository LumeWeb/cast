<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Portal;

use LumeWeb\Cast\Http\ResponseDecodingException;
use LumeWeb\Cast\Ipfs\Workspace;
use LumeWeb\Cast\Portal\WorkspaceResolve;
use LumeWeb\Cast\Portal\WorkspaceResolveWebsite;
use PHPUnit\Framework\TestCase;

/**
 * WorkspaceResolve is the safe runtime-identity value object mapped from the
 * portal Workspaces.Resolve response: the resolved workspace plus the optional
 * attached Website publish relationship, with derived publish state. It holds
 * identifiers and statuses only — never credentials — and a server-side error
 * field maps to an explicit not-resolved state instead of a decoding crash.
 */
final class WorkspaceResolveTest extends TestCase
{
    /**
     * @param array<string, mixed>|null $website
     * @return array<string, mixed>
     */
    private function resolvedArray(?array $website = null): array
    {
        $data = [
            'id' => 11,
            'label' => 'main',
            'domain' => 'main.example.test',
            'status' => 'active',
            'created' => '2026-01-01T00:00:00Z',
            'updated' => '2026-01-02T00:00:00Z',
        ];
        if ($website !== null) {
            $data['website_id'] = $website['id'];
            $data['website'] = $website;
        }

        return $data;
    }

    public function testMapsResolvedWorkspaceAndWebsite(): void
    {
        $result = WorkspaceResolve::fromArray($this->resolvedArray([
            'id' => 42,
            'status' => 'active',
            'target_hash' => 'QmAbc',
            'target_type' => 'car',
        ]));

        self::assertTrue($result->isResolved());
        self::assertNull($result->error());

        $workspace = $result->workspace();
        self::assertInstanceOf(Workspace::class, $workspace);
        self::assertSame(11, $workspace->id());
        self::assertSame('main', $workspace->label());
        self::assertSame('main.example.test', $workspace->domain());
        self::assertSame('active', $workspace->status());
        self::assertInstanceOf(WorkspaceResolveWebsite::class, $result->website());
        self::assertSame(42, $result->website()->id());
        self::assertSame('QmAbc', $result->website()->targetHash());
        self::assertSame('car', $result->website()->targetType());
        self::assertSame(WorkspaceResolve::PUBLISH_STATE_PUBLISHED, $result->publishState());
    }

    public function testPublishStateNoneWhenNoWebsiteAttached(): void
    {
        $result = WorkspaceResolve::fromArray($this->resolvedArray());

        self::assertTrue($result->isResolved());
        self::assertNull($result->website());
        self::assertSame(WorkspaceResolve::PUBLISH_STATE_NONE, $result->publishState());
    }

    public function testPublishStatePendingWhenWebsiteNotYetLive(): void
    {
        $result = WorkspaceResolve::fromArray($this->resolvedArray([
            'id' => 42,
            'status' => 'pending',
            'target_hash' => 'QmAbc',
            'target_type' => 'car',
        ]));

        self::assertSame(WorkspaceResolve::PUBLISH_STATE_PENDING, $result->publishState());
    }

    public function testPublishStatePublishedWhenWebsiteLive(): void
    {
        $result = WorkspaceResolve::fromArray($this->resolvedArray([
            'id' => 42,
            'status' => WorkspaceResolveWebsite::STATUS_LIVE,
            'target_hash' => 'QmAbc',
            'target_type' => 'car',
        ]));

        self::assertSame(WorkspaceResolve::PUBLISH_STATE_PUBLISHED, $result->publishState());
    }

    public function testServerErrorMapsToNotResolvedState(): void
    {
        $result = WorkspaceResolve::fromArray(['error' => 'workspace not found for resource uuid']);

        self::assertFalse($result->isResolved());
        self::assertNull($result->workspace());
        self::assertNull($result->website());
        self::assertNotSame('', (string) $result->error());
        self::assertSame(WorkspaceResolve::PUBLISH_STATE_NONE, $result->publishState());
    }

    public function testBlankErrorStringIsTreatedAsResolvedPath(): void
    {
        $data = $this->resolvedArray();
        $data['error'] = '';

        $result = WorkspaceResolve::fromArray($data);

        self::assertTrue($result->isResolved());
        self::assertNull($result->error());

        $workspace = $result->workspace();
        self::assertInstanceOf(Workspace::class, $workspace);
        self::assertSame(11, $workspace->id());
    }

    public function testMissingRequiredWorkspaceFieldThrowsResponseDecodingException(): void
    {
        $data = $this->resolvedArray();
        unset($data['id']);

        $this->expectException(ResponseDecodingException::class);
        WorkspaceResolve::fromArray($data);
    }

    public function testMalformedWebsiteObjectThrowsResponseDecodingException(): void
    {
        $data = $this->resolvedArray();
        $data['website'] = ['id' => 'not-an-int'];

        $this->expectException(ResponseDecodingException::class);
        WorkspaceResolve::fromArray($data);
    }
}
