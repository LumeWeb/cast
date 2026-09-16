<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\PageBuilder;

use LumeWeb\Cast\PageBuilder\PageBuilderCandidate;
use LumeWeb\Cast\PageBuilder\PageBuilderCatalog;
use LumeWeb\Cast\PageBuilder\PageBuilderKind;
use LumeWeb\Cast\PageBuilder\PageBuilderSource;
use PHPUnit\Framework\TestCase;

final class PageBuilderCatalogTest extends TestCase
{
    private PageBuilderCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new PageBuilderCatalog();
    }

    /**
     * @param list<PageBuilderCandidate> $candidates
     *
     * @return list<?string>
     */
    private function slugs(array $candidates): array
    {
        return array_map(
            static fn (PageBuilderCandidate $candidate): ?string => $candidate->slug,
            $candidates,
        );
    }

    public function testCatalogExposesCuratedEntriesWithNativeFirst(): void
    {
        self::assertSame(
            [null, 'brizy', 'generateblocks', 'kadence-blocks', 'elementor', 'beaver-builder-lite-version'],
            $this->slugs($this->catalog->all()),
        );
    }

    public function testInstallableAllowlistCoversEverySelectableBuilderEdition(): void
    {
        self::assertSame(
            ['brizy', 'generateblocks', 'elementor', 'beaver-builder-lite-version'],
            $this->slugs($this->catalog->installableCandidates()),
        );
    }

    public function testNativeIsCoreFreeRecommendedAndNotInstallable(): void
    {
        $native = $this->catalog->defaultRecommendation();

        self::assertTrue($native->isCore);
        self::assertTrue($native->isFree);
        self::assertTrue($native->recommended);
        self::assertFalse($native->installable);
        self::assertNull($native->slug);
        self::assertSame(PageBuilderKind::Native, $native->kind);

        self::assertNotContains(
            $native,
            $this->catalog->installableCandidates(),
            'The native editor is never an installer candidate.',
        );
    }

    public function testInstallableCandidatesAreFreeEditionsWithInstallSlugs(): void
    {
        foreach ($this->catalog->installableCandidates() as $candidate) {
            self::assertTrue($candidate->isFree, "{$candidate->label} must be the free edition.");
            self::assertNotSame('', $candidate->costNote, "{$candidate->label}: cost note must be present.");
            self::assertNotNull($candidate->slug);
            self::assertNotSame('', $candidate->slug);
        }
    }

    public function testExcludedSlugsAreNotResolvable(): void
    {
        foreach (['spectra', 'spectra-legacy', 'bricks', 'breakdance'] as $slug) {
            self::assertNull($this->catalog->find($slug), "{$slug} must be excluded from the catalog.");
            self::assertNull($this->catalog->findInstallable($slug));
        }
    }

    public function testExcludedSlugsAreNotInInstallAllowlist(): void
    {
        $allowlist = $this->slugs($this->catalog->installableCandidates());

        self::assertNotContains('spectra', $allowlist);
        self::assertNotContains('bricks', $allowlist);
        self::assertNotContains('breakdance', $allowlist);
    }

    public function testElementorAndBeaverBuilderLiteAreSelectableFreeEditions(): void
    {
        $elementor = $this->catalog->find('elementor');
        $beaver = $this->catalog->find('beaver-builder-lite-version');

        self::assertInstanceOf(PageBuilderCandidate::class, $elementor);
        self::assertInstanceOf(PageBuilderCandidate::class, $beaver);
        self::assertSame(PageBuilderKind::VisualBuilder, $elementor->kind);
        self::assertSame(PageBuilderKind::VisualBuilder, $beaver->kind);
        self::assertTrue($elementor->installable);
        self::assertTrue($beaver->installable);
        self::assertFalse($elementor->recommended);
        self::assertFalse($beaver->recommended);
        self::assertSame('Elementor (Free)', $elementor->label);
        self::assertSame('Beaver Builder Lite (Free)', $beaver->label);
        self::assertSame('elementor', $this->catalog->findInstallable('elementor')?->slug);
        self::assertSame('beaver-builder-lite-version', $this->catalog->findInstallable('beaver-builder-lite-version')?->slug);
    }

    public function testKadenceBlocksIsAlternativeMetadataNotAFourthInstallerChoice(): void
    {
        $kadence = $this->catalog->find('kadence-blocks');

        self::assertInstanceOf(PageBuilderCandidate::class, $kadence);
        self::assertSame(PageBuilderKind::Alternative, $kadence->kind);
        self::assertFalse($kadence->installable);
        self::assertNull($this->catalog->findInstallable('kadence-blocks'));
    }

    public function testDefaultRecommendationIsNativeGutenberg(): void
    {
        $recommendation = $this->catalog->defaultRecommendation();

        self::assertSame('Native Gutenberg / Site Editor', $recommendation->label);
        self::assertTrue($recommendation->recommended);
        self::assertNull($recommendation->slug);
    }

    public function testExactlyOneCandidateIsRecommended(): void
    {
        $recommended = array_filter(
            $this->catalog->all(),
            static fn (PageBuilderCandidate $candidate): bool => $candidate->recommended,
        );

        self::assertCount(1, $recommended);
    }

    public function testRecommendationIsDeterministic(): void
    {
        self::assertSame(
            $this->catalog->defaultRecommendation(),
            $this->catalog->defaultRecommendation(),
        );
    }

    public function testFindResolvesKnownInstallableSlug(): void
    {
        $brizy = $this->catalog->find('brizy');

        self::assertInstanceOf(PageBuilderCandidate::class, $brizy);
        self::assertSame('Brizy (Free)', $brizy->label);
        self::assertTrue($brizy->installable);
        self::assertSame('brizy', $this->catalog->findInstallable('brizy')?->slug);
    }

    public function testArbitraryUnknownSlugResolvesToNull(): void
    {
        self::assertNull($this->catalog->find('arbitrary-slug'));
        self::assertNull($this->catalog->find('very-evil-slug'));
        self::assertNull($this->catalog->findInstallable('completely-made-up'));
    }

    public function testPerformanceNotesAvoidNumericClaims(): void
    {
        foreach ($this->catalog->all() as $candidate) {
            self::assertNotSame('', $candidate->performanceNote, "{$candidate->label}: performance note must be present.");
            self::assertSame(
                0,
                preg_match('/[0-9]/', $candidate->performanceNote),
                "{$candidate->label}: performance note must not assert a numeric claim.",
            );
        }
    }

    public function testNativeEditorCopyExplainsCostAndNoExtraPluginNeeded(): void
    {
        $native = $this->catalog->defaultRecommendation();

        self::assertStringContainsString('Free', $native->costNote, 'Native cost note must call out that it is free.');
        self::assertStringContainsString('WordPress core', $native->costNote, 'Native cost note must say it ships with WordPress core.');
        self::assertStringContainsString('No extra plugin needed', $native->lockInNote, 'Native lock-in note must tell users no plugin is required.');
        self::assertStringContainsString('blocks', $native->performanceNote, 'Native note must describe building with blocks.');
        self::assertStringContainsString('Site Editor', $native->performanceNote, 'Native note must name the Site Editor as the build surface.');
    }

    public function testInstallableBuildersAreExplainedWithCostAndEditingRequirement(): void
    {
        $brizy = $this->catalog->find('brizy');
        $generateblocks = $this->catalog->find('generateblocks');

        self::assertNotNull($brizy);
        self::assertNotNull($generateblocks);

        // Cost, in user terms: a free version first, paid features optional.
        foreach ([$brizy, $generateblocks] as $candidate) {
            self::assertStringContainsString(
                'Free version available',
                $candidate->costNote,
                "{$candidate->label}: cost note must mention a free version.",
            );
            self::assertStringContainsString(
                'paid features optional',
                $candidate->costNote,
                "{$candidate->label}: cost note must say paid features are optional.",
            );
        }

        // What each choice does, in user terms.
        self::assertStringContainsString('drag-and-drop', $brizy->performanceNote, 'Brizy note must describe drag-and-drop design.');
        self::assertStringContainsString('blocks', $generateblocks->performanceNote, 'GenerateBlocks note must describe blocks.');
        self::assertStringContainsString('WordPress editor', $generateblocks->performanceNote, 'GenerateBlocks note must place its blocks in the WordPress editor.');

        // What the user needs: the plugin stays involved when editing that content.
        foreach ([$brizy, $generateblocks] as $candidate) {
            self::assertStringContainsString(
                'when editing',
                $candidate->lockInNote,
                "{$candidate->label}: lock-in note must say the plugin is needed when editing.",
            );
        }
    }

    public function testEditorTargetOnlySetForVerifiedCoreCandidate(): void
    {
        foreach ($this->catalog->all() as $candidate) {
            if ($candidate->isCore) {
                self::assertNotNull($candidate->editorTarget, "{$candidate->label}: verified editor target expected.");
            } else {
                self::assertNull($candidate->editorTarget, "{$candidate->label}: editor target must stay null until verified.");
            }
        }
    }

    /**
     * Every candidate that is a plugin (has a slug) must carry a unique,
     * non-empty slug. A duplicated slug would make lookup derivation ambiguous
     * and let one candidate shadow another in install/installable lookups.
     */
    public function testEveryPluginCandidateHasAUniqueNonEmptySlug(): void
    {
        $slugs = array_values(array_filter(
            array_map(
                static fn (PageBuilderCandidate $candidate): ?string => $candidate->slug,
                $this->catalog->all(),
            ),
            static fn (?string $slug): bool => $slug !== null,
        ));

        self::assertNotSame([], $slugs, 'The curated catalog must contain plugin candidates.');
        self::assertCount(count(array_unique($slugs)), $slugs, 'Plugin slugs must be unique.');
        foreach ($slugs as $slug) {
            self::assertNotSame('', $slug);
        }
    }

    /**
     * An installable candidate is installable precisely because it comes from a
     * source this installer can drive (WordPress.org). Any installable entry
     * must therefore declare that source, so the installer's invalid-source
     * gate has real metadata to enforce.
     */
    public function testInstallableCandidatesCarryAValidInstallSource(): void
    {
        foreach ($this->catalog->installableCandidates() as $candidate) {
            self::assertSame(
                PageBuilderSource::WordPressOrg,
                $candidate->source,
                "{$candidate->label}: an installable candidate must resolve to the WordPress.org install source.",
            );
        }
    }

    /**
     * The source is authoritative catalog data: a plugin candidate declares its
     * source (WordPress.org), while the native editor — not a plugin — has none.
     */
    public function testCandidateExposesItsInstallSourceAsData(): void
    {
        $brizy = $this->catalog->find('brizy');
        self::assertInstanceOf(PageBuilderCandidate::class, $brizy);
        self::assertSame(PageBuilderSource::WordPressOrg, $brizy->source);

        $native = $this->catalog->defaultRecommendation();
        self::assertNull($native->source, 'The native editor is not a plugin and has no install source.');
    }
}
