<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export\Rewrite;

use LumeWeb\Cast\Export\Rewrite\SrcsetSplitter;
use PHPUnit\Framework\TestCase;

final class SrcsetSplitterTest extends TestCase
{
    private SrcsetSplitter $splitter;

    protected function setUp(): void
    {
        $this->splitter = new SrcsetSplitter();
    }

    private function rewrite(string $srcset, string $local = './local/img.jpg'): string
    {
        return $this->splitter->rewrite($srcset, static fn (string $candidate): string => match ($candidate) {
            'https://example.com/a.jpg' => $local,
            'https://example.com/b.jpg' => './local/b.jpg',
            default => $candidate,
        });
    }

    public function testK5CloudinarySrcsetKeepsCommaAndDescriptors(): void
    {
        $srcset = 'https://example.com/a.jpg 800w,https://res.cloudinary.com/demo/f_auto,q_auto/b.jpg 1600w';

        self::assertSame(
            './local/img.jpg 800w, https://res.cloudinary.com/demo/f_auto,q_auto/b.jpg 1600w',
            $this->rewrite($srcset)
        );
    }

    public function testCloudinaryCommaInsideTransformDoesNotSplit(): void
    {
        // The transform comma is followed by a path char, not a URL start or
        // whitespace, so it must not become a candidate boundary.
        $srcset = 'https://res.cloudinary.com/demo/f_auto,q_auto/b.jpg 1600w, https://example.com/b.jpg 800w';

        self::assertSame(
            'https://res.cloudinary.com/demo/f_auto,q_auto/b.jpg 1600w, ./local/b.jpg 800w',
            $this->rewrite($srcset)
        );
    }

    public function testWhitespaceSeparatedCandidatesBothRewrite(): void
    {
        self::assertSame(
            './local/img.jpg 2x, ./local/b.jpg 1x',
            $this->rewrite('https://example.com/a.jpg 2x, https://example.com/b.jpg 1x')
        );
    }

    public function testDescriptorVariantsArePeeledAndPreserved(): void
    {
        self::assertSame(
            './local/img.jpg 1.5x, ./local/b.jpg 480w',
            $this->rewrite('https://example.com/a.jpg 1.5x, https://example.com/b.jpg 480w')
        );
    }

    public function testNoSpaceAfterCommaBoundaryStillSplits(): void
    {
        // Comma followed by a URL start (h) must split even without whitespace.
        self::assertSame(
            './local/img.jpg 1x, https://res.cloudinary.com/x.jpg 2x',
            $this->splitter->rewrite(
                'https://example.com/a.jpg 1x,https://res.cloudinary.com/x.jpg 2x',
                static fn (string $candidate): string => match ($candidate) {
                    'https://example.com/a.jpg' => './local/img.jpg',
                    default => $candidate,
                }
            )
        );
    }

    public function testSingleCandidateRewrites(): void
    {
        self::assertSame(
            './local/img.jpg 1920w',
            $this->rewrite('https://example.com/a.jpg 1920w')
        );
    }

    public function testDataUriCandidateIsLeftUntouched(): void
    {
        // A data URI with an internal comma must not be split.
        $srcset = 'data:image/svg+xml;utf8,<svg viewBox="0 0 1 1"></svg> 1x, https://example.com/a.jpg 2x';

        self::assertSame(
            'data:image/svg+xml;utf8,<svg viewBox="0 0 1 1"></svg> 1x, ./local/img.jpg 2x',
            $this->rewrite($srcset)
        );
    }

    public function testEmptyAndDescriptorOnlyTokensAreSafe(): void
    {
        // The descriptor-only "2x" candidate is not a URL and is left alone.
        self::assertSame(
            './local/img.jpg 1x, 2x',
            $this->rewrite('https://example.com/a.jpg 1x, 2x')
        );
    }

    public function testDescriptorOnlySetIsLeftEntirelyUntouched(): void
    {
        // A descriptor-only srcset has no URL to rewrite; it survives verbatim
        // (leading whitespace is trimmed as part of candidate normalization).
        self::assertSame(
            '2x',
            $this->rewrite(' 2x')
        );
    }

    public function testTrailingCommaDoesNotProduceEmptyToken(): void
    {
        self::assertSame(
            './local/img.jpg 1x,',
            $this->rewrite('https://example.com/a.jpg 1x,')
        );
    }
}
