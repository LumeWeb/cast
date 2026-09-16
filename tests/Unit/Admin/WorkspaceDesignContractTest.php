<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Admin\OnboardingAdminSubscriber;
use PHPUnit\Framework\TestCase;

/**
 * Repo-level design/slug contracts for the dashboard + workspace wizard
 * overhaul.
 *
 * These assert the explicit, approved outcomes of this slice:
 *   - the admin page slug is renamed to the workspace scheme;
 *   - no stale old-slug reference survives anywhere in source/templates;
 *   - the wizard stylesheet relies on the shipped WP 7.1 admin theme tokens
 *     (`wp-base-styles`: --wp-admin-theme-color + focus-border token) with
 *     robust fallbacks instead of a hardcoded palette;
 *   - both dashboard welcome panel and wizard welcome screen reuse one shared
 *     welcome partial (no duplicated onboarding HTML).
 */
final class WorkspaceDesignContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    public function testPageSlugUsesWorkspaceScheme(): void
    {
        self::assertSame('workspace-getting-started', OnboardingAdminSubscriber::PAGE_SLUG);
    }

    public function testNoStaleOldSlugInSourceOrTemplates(): void
    {
        // The rename must be complete: the legacy `cast-getting-started` slug
        // must not survive anywhere in production or template source. This is
        // the explicit stale-route regression guard for the rename requirement.
        foreach ($this->phpSourceFiles(['src', 'templates']) as $file) {
            $content = (string) file_get_contents($file);
            self::assertStringNotContainsString(
                'cast-getting-started',
                $content,
                "Stale old admin slug found in {$file}",
            );
        }
    }

    public function testStylesheetUsesShippedWordPressThemeTokensWithFallbacks(): void
    {
        $css = (string) file_get_contents($this->root . '/assets/css/cast-admin.css');

        // The modern admin shell relies on the shipped `wp-base-styles` tokens
        // (--wp-admin-theme-color, --wp-admin-theme-color-darker-10 and the
        // focus-border width token) rather than a brand palette.
        self::assertStringContainsString('var(--wp-admin-theme-color,', $css);
        self::assertStringContainsString('var(--wp-admin-theme-color-darker-10,', $css);
        self::assertStringContainsString('var(--wp-admin-border-width-focus,', $css);
    }

    public function testWelcomeCopyRenderedBySingleSharedPartial(): void
    {
        // The onboarding copy + primary/secondary controls live in exactly one
        // welcome partial that both the wizard page and the dashboard panel
        // include — never duplicated inline.
        $welcomePattern = '/<section[^>]*class="[^"]*cast-welcome-card[^"]*"/';
        $wizardPage = (string) file_get_contents($this->root . '/templates/admin/page.php');
        $welcomePartial = (string) file_get_contents($this->root . '/templates/admin/welcome.php');

        // The shared partial is the single source of the welcome card.
        self::assertSame(1, preg_match_all($welcomePattern, $welcomePartial));
        // The wizard page delegates the welcome screen to the shared partial
        // instead of re-declaring its own card markup.
        self::assertStringContainsString("include __DIR__ . '/welcome.php'", $wizardPage);
        // The wizard page never inlines its own copy of the card — exactly one
        // definition lives here (in welcome.php), reused by both surfaces.
        self::assertSame(0, preg_match_all($welcomePattern, $wizardPage));
    }

    public function testWelcomeTitleAndSubtitleAreSeparateSemanticElements(): void
    {
        // The title and subtitle are distinct semantic elements: the title is
        // a real heading, the subtitle is its own paragraph that directly
        // follows it — never merged into one truncating inline line.
        $welcomePartial = (string) file_get_contents($this->root . '/templates/admin/welcome.php');
        self::assertSame(
            1,
            preg_match('/<h2[^>]*id="cast-welcome-heading"[^>]*class="cast-welcome-title"/', $welcomePartial),
            'Welcome title must be a real heading with the cast-welcome-title class.',
        );
        self::assertSame(
            1,
            preg_match('/<p[^>]*class="cast-welcome-subtitle"[^>]*>/', $welcomePartial),
            'Welcome subtitle must be its own paragraph with the cast-welcome-subtitle class.',
        );
        self::assertSame(
            1,
            preg_match('/<\/h2>\s*<p[^>]*class="cast-welcome-subtitle"[^>]*>/', $welcomePartial),
            'The welcome subtitle must directly follow the welcome heading as a separate block.',
        );

        // Exact user-facing copy, hand-checked against the approved strings:
        // the title and the subtitle are distinct lines, and the old merged
        // single-line subtitle must not regress back into the template.
        self::assertStringContainsString("esc_html('Set up your workspace')", $welcomePartial);
        self::assertStringContainsString("esc_html('Finish setting up your site in a couple of minutes.')", $welcomePartial);
        self::assertStringNotContainsString('Pick how you want to build', $welcomePartial);
    }

    public function testWizardShellIsStableCenteredWrapper(): void
    {
        // The wizard shell is `<div class="wrap cast-wizard-wrap">`. Core
        // common.css applies `.wrap { margin: 10px 20px 0 2px }` — an
        // asymmetric, LEFT-aligned margin at specificity (0,1,0). A plain
        // `.cast-wizard-wrap` would tie that specificity, so on a warm cache
        // the shell could paint left and then jump centered. The sheet must
        // target `.wrap.cast-wizard-wrap` (0,2,0) so its 640px centered shell
        // wins the cascade over core `.wrap` and the geometry stays stable.
        $css = (string) file_get_contents($this->root . '/assets/css/cast-admin.css');
        self::assertMatchesRegularExpression(
            '/\.wrap\.cast-wizard-wrap\s*\{[^}]*max-width\s*:\s*var\(--cast-max-width\)[^}]*margin\s*:\s*0 auto[^}]*\}/s',
            $css,
            'The wizard wrapper must be a 640px margin:0 auto shell targeted with the .wrap.cast-wizard-wrap specificity.',
        );
        // The content frame inside the shell is centered to the same width, so
        // every wizard screen shares one stable horizontal geometry.
        self::assertMatchesRegularExpression(
            '/\.cast-screen-frame\s*\{[^}]*max-width\s*:\s*var\(--cast-max-width\)[^}]*margin\s*:\s*0 auto[^}]*\}/s',
            $css,
            'The wizard content frame must be centered to the same shell width.',
        );
    }

    public function testWelcomeCardIsFullWidthCenteredOnEverySurface(): void
    {
        // The shared welcome card must be a TRUE full-width, centered block on
        // BOTH surfaces it renders on. On the dashboard the card appears with no
        // `.cast-screen-frame` wrapper, so WP core's `.card` rule (max-width:
        // 520px, margin-top, no auto margins) would otherwise shrink and
        // left-align it. A `.cast-welcome-card` rule overrides that to the same
        // 640px centered shell the wizard uses — the two surfaces render the
        // shared partial identically. The center must survive any core `.card`
        // conflict but the card must never rely on the old ellipsis/overflow
        // workaround.
        $css = (string) file_get_contents($this->root . '/assets/css/cast-admin.css');
        self::assertMatchesRegularExpression(
            '/\.cast-welcome-card\s*\{[^}]*max-width\s*:\s*var\(--cast-max-width[^}]*margin\s*:\s*0 auto[^}]*\}/s',
            $css,
            'cast-welcome-card must be a full-width (640px) centered block, overriding WP core .card.',
        );
        self::assertStringNotContainsString('text-overflow: ellipsis', $css);
        self::assertStringNotContainsString('overflow: hidden', $css);
    }

    public function testWelcomeTitleIsFullWidthCenteredAndNeverEllipsised(): void
    {
        // The shared stylesheet centers the welcome title as a full-width block
        // (never a narrow inline item) and keeps it on a single line, with the
        // ellipsis/overflow workaround gone entirely. The title must not show
        // an ellipsis as substitute copy on any viewport.
        $css = (string) file_get_contents($this->root . '/assets/css/cast-admin.css');
        self::assertMatchesRegularExpression(
            '/\.cast-welcome-card \.cast-welcome-title\s*\{[^}]*display\s*:\s*block[^}]*width\s*:\s*100%[^}]*text-align\s*:\s*center[^}]*white-space\s*:\s*nowrap[^}]*\}/s',
            $css,
            'cast-welcome-title must be a full-width centered block kept on one line.',
        );
        self::assertStringNotContainsString('text-overflow: ellipsis', $css);
        self::assertStringNotContainsString('overflow: hidden', $css);
    }

    public function testWelcomeSubtitleIsCenteredAndWrapsNormally(): void
    {
        // The subtitle is centered independently of the title and keeps normal
        // wrapping — never nowrap/ellipsis like the old title workaround.
        $css = (string) file_get_contents($this->root . '/assets/css/cast-admin.css');
        self::assertMatchesRegularExpression(
            '/\.cast-welcome-card \.cast-welcome-subtitle\s*\{[^}]*display\s*:\s*block[^}]*width\s*:\s*100%[^}]*text-align\s*:\s*center[^}]*white-space\s*:\s*normal[^}]*\}/s',
            $css,
            'cast-welcome-subtitle must be a full-width centered block that wraps normally.',
        );
    }

    public function testWelcomeTitleReducesSizeOnNarrowViewportsInsteadOfClipping(): void
    {
        // On narrow viewports the no-wrap title scales down to a smaller,
        // readable size (an intentional responsive choice) instead of relying
        // on ellipsis/hidden overflow to fake a solution.
        $css = (string) file_get_contents($this->root . '/assets/css/cast-admin.css');
        self::assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*600px\)[\s\S]*?\.cast-welcome-card \.cast-welcome-title\s*\{\s*font-size:\s*16px;\s*\}/',
            $css,
            'On narrow viewports the welcome title must reduce size (18px to 16px), not clip with an ellipsis.',
        );
    }

    public function testActionGroupsShareFlexColumnLayoutWithExplicitGap(): void
    {
        // The reported regression: primary and secondary action groups stacked
        // controls almost adjacent (4px/2px) with no shared spacing model. The
        // contract is ONE shared action-group layout across both groups: a flex
        // column with an explicit, real `gap` so controls are always separated.
        $css = (string) file_get_contents($this->root . '/assets/css/cast-admin.css');
        foreach (['cast-actions', 'cast-secondary-actions'] as $group) {
            self::assertMatchesRegularExpression(
                '/\.' . preg_quote($group, '/') . '\s*\{[^}]*display\s*:\s*flex[^}]*flex-direction\s*:\s*column[^}]*gap\s*:\s*12px[^}]*\}/s',
                $css,
                "{$group} must be a shared flex-column action group with an explicit 12px gap.",
            );
        }
    }

    public function testActionGroupsNeverRelyOnMarginCollapseForSeparation(): void
    {
        // Separation must come from the shared flex `gap`, never from implicit
        // sibling margins that collapse into near-adjacent controls (the
        // 4px/2px gaps measured on the live completion screen before the fix).
        $css = (string) file_get_contents($this->root . '/assets/css/cast-admin.css');
        self::assertMatchesRegularExpression(
            '/\.cast-actions\s*\{[^}]*gap\s*:\s*12px/s',
            $css,
            'Primary action group must carry the explicit 12px gap.',
        );
        self::assertMatchesRegularExpression(
            '/\.cast-secondary-actions\s*\{[^}]*gap\s*:\s*12px/s',
            $css,
            'Secondary action group must carry the explicit 12px gap.',
        );
    }

    public function testFullWidthActionIsOptInViaSharedClassOnly(): void
    {
        // Full-width is an intentional opt-in through the shared
        // `.cast-action-full` modifier ONLY. The groups or their plain buttons
        // must never become accidentally full-width — that is what turned both
        // completion CTAs into two identical full-width primary buttons.
        $css = (string) file_get_contents($this->root . '/assets/css/cast-admin.css');
        self::assertMatchesRegularExpression(
            '/\.cast-action-full\s*\{[^}]*width\s*:\s*100%[^}]*\}/s',
            $css,
            'The shared cast-action-full modifier must be the full-width opt-in.',
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.cast-actions\s*\{[^}]*width\s*:\s*100%/s',
            $css,
            'The primary action group itself must never be full-width.',
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.cast-actions \.button\s*\{[^}]*width\s*:\s*100%/s',
            $css,
            'Buttons inside the primary action group must not be blanket full-width.',
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.cast-secondary-actions \.button\s*\{[^}]*width\s*:\s*100%/s',
            $css,
            'Buttons inside the secondary action group must not be blanket full-width.',
        );
    }

    public function testNarrowViewportPreservesSharedActionGroupSpacing(): void
    {
        // At narrow widths the shared flex-column layout already stacks with
        // the explicit gap; the responsive block must never re-declare the
        // action groups with collapsed margins/gaps that would push the
        // controls back together.
        $css = (string) file_get_contents($this->root . '/assets/css/cast-admin.css');
        $narrow = $this->mediaBlock($css, 600);

        self::assertNotSame('', $narrow, 'Expected a max-width:600px responsive block in cast-admin.css.');
        self::assertStringNotContainsString(
            '.cast-actions',
            $narrow,
            'The narrow responsive block must not override the primary action group spacing.',
        );
        self::assertStringNotContainsString(
            '.cast-secondary-actions',
            $narrow,
            'The narrow responsive block must not override the secondary action group spacing.',
        );
    }

    /**
     * Extract the body of the `@media (max-width: <px>)` block (balanced braces).
     */
    private function mediaBlock(string $css, int $maxWidth): string
    {
        $needle = "@media (max-width: {$maxWidth}px)";
        $start = strpos($css, $needle);
        if ($start === false) {
            return '';
        }

        $braceStart = strpos($css, '{', $start);
        if ($braceStart === false) {
            return '';
        }

        $depth = 0;
        $length = strlen($css);
        for ($i = $braceStart; $i < $length; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($css, $braceStart + 1, $i - $braceStart - 1);
                }
            }
        }

        return '';
    }

    public function testSecondaryLinkActionUsesWordPressButtonLinkClass(): void
    {
        // The "Return to dashboard" escape hatch is a styled WordPress-native
        // link action (.button-link), so the stylesheet must not be forced to
        // restyle bare anchors with an inline-looking text treatment.
        $css = (string) file_get_contents($this->root . '/assets/css/cast-admin.css');
        self::assertStringNotContainsString(
            '.cast-secondary-actions a',
            $css,
            'No bare-anchor hack styling should remain in the secondary actions group; the WP .button-link core class styles the link action.',
        );
    }

    /**
     * @param list<string> $dirs
     * @return list<string>
     */
    private function phpSourceFiles(array $dirs): array
    {
        $files = [];
        foreach ($dirs as $dir) {
            $path = $this->root . '/' . $dir;
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $fileInfo) {
                if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                    $files[] = $fileInfo->getPathname();
                }
            }
        }

        return $files;
    }
}
