<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\StagedReferenceResolver;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use PHPUnit\Framework\TestCase;

final class StagedReferenceResolverTest extends TestCase
{
    private Origin $origin;
    private ArrayArtifactTree $tree;
    private StagedReferenceResolver $resolver;

    protected function setUp(): void
    {
        $this->origin = Origin::fromUrl((new UrlCanonicalizer())->canonicalize('https://example.com/'));
        $this->tree = new ArrayArtifactTree([
            'about/index.html' => '<html>about</html>',
            'wp-content/uploads/photo.jpg' => 'JPG',
            '2023/01/post/index.html' => '<html>x</html>',
        ]);
        $this->resolver = new StagedReferenceResolver($this->tree, $this->origin);
    }

    public function testRelativeSiblingReferenceResolvesToStagedFile(): void
    {
        self::assertSame(
            'wp-content/uploads/photo.jpg',
            $this->resolver->resolve('../wp-content/uploads/photo.jpg', 'about/index.html')
        );
    }

    public function testDeepUpReferenceResolvesAcrossRoot(): void
    {
        self::assertSame(
            'about/index.html',
            $this->resolver->resolve('../../../about/index.html', '2023/01/post/index.html')
        );
    }

    public function testRootRelativeReferenceResolvesToStagedFile(): void
    {
        self::assertSame('wp-content/uploads/photo.jpg', $this->resolver->resolve('/wp-content/uploads/photo.jpg', 'about/index.html'));
    }

    public function testTrailingSlashDirectoryMapsToIndexHtml(): void
    {
        self::assertSame('about/index.html', $this->resolver->resolve('/about/', 'index.html'));
    }

    public function testExtensionlessRootPathMapsToIndexHtmlWhenPresent(): void
    {
        self::assertSame('about/index.html', $this->resolver->resolve('/about', 'index.html'));
    }

    public function testSameOriginAbsoluteUrlMapsToOutputPath(): void
    {
        self::assertSame(
            'wp-content/uploads/photo.jpg',
            $this->resolver->resolve('https://example.com/wp-content/uploads/photo.jpg', 'about/index.html')
        );
    }

    public function testOffOriginAbsoluteUrlIsNotLocal(): void
    {
        self::assertNull($this->resolver->resolve('https://cdn.example.com/x.jpg', 'about/index.html'));
    }

    public function testProtocolRelativeOffOriginIsNotLocal(): void
    {
        self::assertNull($this->resolver->resolve('//other.test/x.jpg', 'about/index.html'));
    }

    public function testProtocolRelativeSameOriginIsLocal(): void
    {
        self::assertSame('wp-content/uploads/photo.jpg', $this->resolver->resolve('//example.com/wp-content/uploads/photo.jpg', 'about/index.html'));
    }

    public function testFragmentQueryAndNonWebSchemesAreSkipped(): void
    {
        self::assertNull($this->resolver->resolve('#section', 'about/index.html'));
        self::assertNull($this->resolver->resolve('?s=1', 'about/index.html'));
        self::assertNull($this->resolver->resolve('data:image/svg+xml;base64,AAA', 'about/index.html'));
        self::assertNull($this->resolver->resolve('mailto:a@b.test', 'about/index.html'));
        self::assertNull($this->resolver->resolve('', 'about/index.html'));
    }

    public function testQueryStringIsStrippedBeforeExistenceCheck(): void
    {
        self::assertSame(
            'wp-content/uploads/photo.jpg',
            $this->resolver->resolve('/wp-content/uploads/photo.jpg?ver=1.2', 'about/index.html')
        );
    }

    public function testMissingTargetStillReturnsCandidateForBrokenDetection(): void
    {
        self::assertSame('missing/page.html', $this->resolver->resolve('/missing/page.html', 'about/index.html'));
    }
}
