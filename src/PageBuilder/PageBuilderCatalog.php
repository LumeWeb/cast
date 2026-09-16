<?php

declare(strict_types=1);

namespace LumeWeb\Cast\PageBuilder;

use LogicException;

/**
 * Curated, immutable catalog of page-builder candidates plus deterministic
 * recommendation queries.
 *
 * The catalog owns the allowlist: only candidates flagged installable may ever
 * be offered to the installer, and every lookup starts from the known slug
 * list, so arbitrary request-supplied slugs resolve to null instead of
 * inventing a candidate. Recommendation does not assert unsupported
 * performance claims; it surfaces honest, non-numeric notes.
 */
final class PageBuilderCatalog
{
    /** @var list<PageBuilderCandidate> */
    private array $candidates;

    /**
     * @param list<PageBuilderCandidate>|null $candidates Optional curated list;
     *        defaults to the single authoritative definition (defaultCandidates()).
     *        Injection exists so the installer's allowlist/source policy can be
     *        exercised against additional candidates without forking this class.
     */
    public function __construct(?array $candidates = null)
    {
        $this->candidates = $candidates ?? self::defaultCandidates();
    }

    /**
     * The authoritative catalog definition: candidate metadata, installability,
     * and source all live here. Derived views — installableCandidates(),
     * findInstallable(), defaultRecommendation() — read only from this list.
     *
     * @return list<PageBuilderCandidate>
     */
    private static function defaultCandidates(): array
    {
        return [
            new PageBuilderCandidate(
                slug: null,
                label: 'Native Gutenberg / Site Editor',
                kind: PageBuilderKind::Native,
                isCore: true,
                isFree: true,
                installable: false,
                recommended: true,
                lockInNote: 'No extra plugin needed.',
                performanceNote: 'Build pages with blocks and the Site Editor.',
                costNote: 'Free, part of WordPress core.',
                editorTarget: 'block',
            ),
            new PageBuilderCandidate(
                slug: 'brizy',
                label: 'Brizy (Free)',
                kind: PageBuilderKind::VisualBuilder,
                isCore: false,
                isFree: true,
                installable: true,
                recommended: false,
                lockInNote: 'Pages made with Brizy need Brizy available when editing them.',
                performanceNote: 'Visual drag-and-drop page design.',
                costNote: 'Free version available; paid features optional.',
                editorTarget: null,
                source: PageBuilderSource::WordPressOrg,
            ),
            new PageBuilderCandidate(
                slug: 'generateblocks',
                label: 'GenerateBlocks (Free)',
                kind: PageBuilderKind::BlockToolkit,
                isCore: false,
                isFree: true,
                installable: true,
                recommended: false,
                lockInNote: 'Content using its blocks needs the plugin available when editing.',
                performanceNote: 'Focused layout and content blocks inside the WordPress editor.',
                costNote: 'Free version available; paid features optional.',
                editorTarget: null,
                source: PageBuilderSource::WordPressOrg,
            ),
            new PageBuilderCandidate(
                slug: 'kadence-blocks',
                label: 'Kadence Blocks (Alternative)',
                kind: PageBuilderKind::Alternative,
                isCore: false,
                isFree: true,
                installable: false,
                recommended: false,
                lockInNote: 'Content using its blocks needs the plugin available when editing.',
                performanceNote: 'Layout and content blocks for the WordPress editor.',
                costNote: 'Free version available; paid features optional.',
                editorTarget: null,
                source: PageBuilderSource::WordPressOrg,
            ),
            new PageBuilderCandidate(
                slug: 'elementor',
                label: 'Elementor (Free)',
                kind: PageBuilderKind::VisualBuilder,
                isCore: false,
                isFree: true,
                installable: true,
                recommended: false,
                lockInNote: 'Pages made with Elementor need Elementor available when editing them.',
                performanceNote: 'Visual drag-and-drop page design.',
                costNote: 'Free version available; paid features optional.',
                editorTarget: null,
                source: PageBuilderSource::WordPressOrg,
            ),
            new PageBuilderCandidate(
                slug: 'beaver-builder-lite-version',
                label: 'Beaver Builder Lite (Free)',
                kind: PageBuilderKind::VisualBuilder,
                isCore: false,
                isFree: true,
                installable: true,
                recommended: false,
                lockInNote: 'Pages made with Beaver Builder need Beaver Builder available when editing them.',
                performanceNote: 'Drag-and-drop page design.',
                costNote: 'Free version available; paid features optional.',
                editorTarget: null,
                source: PageBuilderSource::WordPressOrg,
            ),
        ];
    }

    /**
     * Every curated candidate, in presentation order: native first, then the
     * selectable free-version choices, and the fallback alternative metadata.
     *
     * @return list<PageBuilderCandidate>
     */
    public function all(): array
    {
        return $this->candidates;
    }

    /**
     * The install allowlist: only candidates explicitly flagged installable.
     * Nothing outside this list may reach an installer.
     *
     * @return list<PageBuilderCandidate>
     */
    public function installableCandidates(): array
    {
        return array_values(array_filter(
            $this->candidates,
            static fn (PageBuilderCandidate $candidate): bool => $candidate->installable,
        ));
    }

    /**
     * The single deterministic default recommendation (the native editor).
     */
    public function defaultRecommendation(): PageBuilderCandidate
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->recommended) {
                return $candidate;
            }
        }

        throw new LogicException('The catalog must mark exactly one candidate as recommended.');
    }

    /**
     * Resolve a slug against the known catalog only. Unknown slugs — including
     * arbitrary request input — resolve to null; nothing is ever created on
     * demand.
     */
    public function find(string $slug): ?PageBuilderCandidate
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->slug === $slug) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Resolve a slug to an installable candidate. A known catalog entry that is
     * not flagged installable (e.g. alternative metadata) resolves to null, so
     * the allowlist cannot be bypassed with a lookup.
     */
    public function findInstallable(string $slug): ?PageBuilderCandidate
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->slug === $slug && $candidate->installable) {
                return $candidate;
            }
        }

        return null;
    }
}
