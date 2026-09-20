<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export\Rewrite;

use LumeWeb\Cast\Export\Rewrite\OfflinePathCalculator;
use PHPUnit\Framework\TestCase;

final class OfflinePathCalculatorTest extends TestCase
{
    private OfflinePathCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new OfflinePathCalculator();
    }

    /**
     * The offline `../` depth is computed from the page's
     * output file to the target output file. Each supplied vector must match
     * exactly.
     */
    public function testSiblingPageDepthMatchesK5Vector(): void
    {
        self::assertSame(
            './../../10/page-b/index.html',
            $this->calculator->relativeTo(
                '2013/01/11/page-a/index.html',
                '2013/01/10/page-b/index.html'
            )
        );
    }

    public function testHomeDepthMatchesReferenceDepth(): void
    {
        // The catalog vector offline-home prints ./../../../index.html, but
        // four levels are correct: from 2013/01/11/page-a/index.html to the
        // root index.html requires ../../../../. The sibling (2) and root-css
        // (4) vectors agree with this depth math; the printed home row is a
        // catalog typo. We pin the correct value.
        self::assertSame(
            './../../../../index.html',
            $this->calculator->relativeTo('2013/01/11/page-a/index.html', 'index.html')
        );
    }

    public function testRootCssDepthMatchesK5Vector(): void
    {
        self::assertSame(
            './../../../../wp-content/themes/x/style.css',
            $this->calculator->relativeTo(
                '2013/01/11/page-a/index.html',
                'wp-content/themes/x/style.css'
            )
        );
    }

    public function testDeepAssetDepthMatchesK5Vector(): void
    {
        self::assertSame(
            './../../../../wp-content/uploads/2024/01/photo.jpg',
            $this->calculator->relativeTo(
                '2013/01/11/page-a/index.html',
                'wp-content/uploads/2024/01/photo.jpg'
            )
        );
    }

    public function testSameDirectoryKeepsRelativeSlash(): void
    {
        self::assertSame(
            './style.css',
            $this->calculator->relativeTo('2013/01/11/page-a/index.html', '2013/01/11/page-a/style.css')
        );
    }

    /**
     * An extensionless target is already resolved to {path}/index.html by the
     * OutputPathResolver before the calculator sees it, so a plain path here
     * is treated verbatim (no append — that is the resolver's job).
     */
    public function testAssetTargetKeepsExtension(): void
    {
        self::assertSame(
            './photo.jpg',
            $this->calculator->relativeTo('about/index.html', 'about/photo.jpg')
        );
    }

    public function testFromRootPage(): void
    {
        self::assertSame(
            './about/index.html',
            $this->calculator->relativeTo('index.html', 'about/index.html')
        );
    }

    public function testFromRootPageToAsset(): void
    {
        self::assertSame(
            './wp-content/themes/x/style.css',
            $this->calculator->relativeTo('index.html', 'wp-content/themes/x/style.css')
        );
    }

    public function testFromRootPageToRootAssetStaysRelative(): void
    {
        self::assertSame(
            './style.css',
            $this->calculator->relativeTo('index.html', 'style.css')
        );
    }
}
