<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Export;

use LumeWeb\Cast\Export\LocalReferenceValidator;
use LumeWeb\Cast\Export\Origin;
use LumeWeb\Cast\Export\ValidationSeverity;
use LumeWeb\Cast\Export\UrlCanonicalizer;
use PHPUnit\Framework\TestCase;

final class LocalReferenceValidatorTest extends TestCase
{
    private Origin $origin;

    protected function setUp(): void
    {
        $this->origin = Origin::fromUrl((new UrlCanonicalizer())->canonicalize('https://example.com/'));
    }

    public function testBrokenLocalReferenceBecomesWarning(): void
    {
        $tree = new ArrayArtifactTree([
            'index.html' => '<a href="about/index.html">about</a>',
            'about/index.html' => '<html>about</html>',
            'news/index.html' => '<img src="../wp-content/uploads/photo.jpg">',
        ]);

        $findings = (new LocalReferenceValidator($tree, $this->origin))->brokenReferences();

        self::assertCount(1, $findings);
        self::assertSame('broken_local_reference', $findings[0]->category);
        self::assertSame(ValidationSeverity::Warning, $findings[0]->severity);
        self::assertSame('news/index.html', $findings[0]->path);
        self::assertSame('../wp-content/uploads/photo.jpg', $findings[0]->reference);
    }

    public function testResolvableReferencesProduceNoFindings(): void
    {
        $tree = new ArrayArtifactTree([
            'index.html' => '<a href="about/index.html">about</a>',
            'about/index.html' => '<img src="../wp-content/uploads/photo.jpg">',
            'wp-content/uploads/photo.jpg' => 'JPG',
        ]);

        $findings = (new LocalReferenceValidator($tree, $this->origin))->brokenReferences();

        self::assertSame([], $findings);
    }

    public function testCssUrlReferencesAreValidated(): void
    {
        $tree = new ArrayArtifactTree([
            'css/style.css' => '.a { background: url("../wp-content/uploads/bg.png"); }',
        ]);

        $findings = (new LocalReferenceValidator($tree, $this->origin))->brokenReferences();

        self::assertCount(1, $findings);
        self::assertSame('css/style.css', $findings[0]->path);
    }

    public function testExternalAndNonWebReferencesAreIgnored(): void
    {
        $tree = new ArrayArtifactTree([
            'index.html' => '<a href="https://other.test/x">x</a><a href="mailto:a@b.test">m</a><a href="#top">t</a>',
        ]);

        $findings = (new LocalReferenceValidator($tree, $this->origin))->brokenReferences();

        self::assertSame([], $findings);
    }

    public function testIntentionalSkippedOrRedirectPathsAreNotBroken(): void
    {
        $tree = new ArrayArtifactTree([
            'index.html' => '<a href="about/index.html">about</a>',
        ]);

        $findings = (new LocalReferenceValidator($tree, $this->origin, null, ['about/index.html']))->brokenReferences();

        self::assertSame([], $findings);
    }

    public function testBinaryFilesAreNotScannedForReferences(): void
    {
        $tree = new ArrayArtifactTree([
            'index.html' => '<a href="photo.jpg">p</a>',
            'photo.jpg' => 'JPG',
            // A non-text blob that happens to look like markup must not be
            // scanned as a text document.
            'assets/app.bin' => '<a href="missing/x.html">x</a>',
        ]);

        $findings = (new LocalReferenceValidator($tree, $this->origin))->brokenReferences();

        self::assertSame([], $findings);
    }
}
