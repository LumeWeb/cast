<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Publish;

use LumeWeb\Cast\Publish\Contract;
use LumeWeb\Cast\Publish\UploadRoute;
use LumeWeb\Cast\Publish\UploadRouter;
use PHPUnit\Framework\TestCase;

/**
 * Route selection purely by artifact size: at or under the 100 MiB upload
 * limit the archive goes through multipart POST, above it through TUS.
 */
final class UploadRouterTest extends TestCase
{
    private UploadRouter $router;

    protected function setUp(): void
    {
        $this->router = new UploadRouter();
    }

    public function testZeroAndSmallArtifactsRouteToMultipartPost(): void
    {
        self::assertSame(UploadRoute::Post, $this->router->route(0));
        self::assertSame(UploadRoute::Post, $this->router->route(1));
        self::assertSame(UploadRoute::Post, $this->router->route(10 * 1024 * 1024));
    }

    public function testArtifactAtTheLimitRoutesToMultipartPost(): void
    {
        self::assertSame(UploadRoute::Post, $this->router->route(Contract::UPLOAD_LIMIT_BYTES));
    }

    public function testArtifactAboveTheLimitRoutesToTus(): void
    {
        self::assertSame(UploadRoute::Tus, $this->router->route(Contract::UPLOAD_LIMIT_BYTES + 1));
        self::assertSame(UploadRoute::Tus, $this->router->route(300 * 1024 * 1024));
    }

    public function testUploadLimitIsOneHundredMebibytes(): void
    {
        self::assertSame(100 * 1024 * 1024, Contract::UPLOAD_LIMIT_BYTES);
    }
}
