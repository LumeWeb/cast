<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\PageBuilder;

use LumeWeb\Cast\PageBuilder\PageBuilderCandidate;
use LumeWeb\Cast\PageBuilder\PageBuilderCatalog;
use LumeWeb\Cast\PageBuilder\PageBuilderInstaller;
use LumeWeb\Cast\PageBuilder\PageBuilderKind;
use LumeWeb\Cast\PageBuilder\PageBuilderSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The server-side allowlist policy for the no-refresh installer.
 *
 * The onboarding UI may only ever request/display install controls for the
 * catalog's installableCandidates(). Any other slug — native core entries,
 * comparison/alternative metadata, or arbitrary request input — must resolve
 * to no installable payload. The payload that the frontend is allowed to act
 * on is built only from installableCandidates(), and the button state
 * (Install / Activate / Active) is derived from the real plugin filesystem.
 */
final class PageBuilderInstallerTest extends TestCase
{
    private FakePluginStateProvider $plugins;
    private PageBuilderInstaller $installer;

    protected function setUp(): void
    {
        $this->plugins = new FakePluginStateProvider();
        $this->installer = new PageBuilderInstaller(new PageBuilderCatalog(), $this->plugins);
    }

    public function testInstallPayloadOnlyEverContainsCatalogInstallables(): void
    {
        $rows = $this->installer->installPayload();

        // Every selectable free-version builder flows into the allowlist
        // payload, so the frontend may act on it through the generic installer.
        self::assertSame(
            ['brizy', 'generateblocks', 'elementor', 'beaver-builder-lite-version'],
            array_column($rows, 'slug'),
        );
        // The allowlist is the whole payload: native/alternative/excluded
        // candidates must never leak an installable row.
        self::assertNotContains(
            'kadence-blocks',
            array_column($rows, 'slug'),
            'Alternative metadata must never become an installable row.',
        );
    }

    public function testPayloadRowsCarryAllowlistedCatalogMetadata(): void
    {
        $row = $this->payload('brizy');

        self::assertSame('brizy', $row['slug']);
        self::assertSame('Brizy (Free)', $row['label']);
        self::assertSame('Brizy (Free)', $row['name']);
    }

    public function testActiveSlugIsDisabledAndLabelledActive(): void
    {
        $this->plugins->installed['brizy'] = 'brizy/brizy.php';
        $this->plugins->active[] = 'brizy/brizy.php';

        $row = $this->payload('brizy');

        self::assertSame('brizy/brizy.php', $row['basename']);
        self::assertTrue($row['alreadyInstalled']);
        self::assertTrue($row['alreadyActive']);
        self::assertSame('Active', $row['buttonLabel']);
        self::assertTrue($row['buttonDisabled']);
    }

    public function testInstalledInactiveSlugIsLabelledActivate(): void
    {
        $this->plugins->installed['brizy'] = 'brizy/brizy.php';

        $row = $this->payload('brizy');

        self::assertSame('brizy/brizy.php', $row['basename']);
        self::assertTrue($row['alreadyInstalled']);
        self::assertFalse($row['alreadyActive']);
        self::assertSame('Activate', $row['buttonLabel']);
        self::assertFalse($row['buttonDisabled']);
    }

    public function testNotInstalledSlugIsLabelledInstall(): void
    {
        $row = $this->payload('generateblocks');

        self::assertNull($row['basename']);
        self::assertFalse($row['alreadyInstalled']);
        self::assertFalse($row['alreadyActive']);
        self::assertSame('Install', $row['buttonLabel']);
        self::assertFalse($row['buttonDisabled']);
    }

    #[DataProvider('nonInstallableSlugs')]
    public function testPayloadForNonInstallableSlugIsNull(string $slug): void
    {
        self::assertNull(
            $this->installer->payloadForSlug($slug),
            "{$slug} must be refused by the install allowlist.",
        );
    }

    public function testArbitrarySlugIsNeverInventedAsInstallable(): void
    {
        self::assertNull($this->installer->payloadForSlug('brizy-premium'));
        self::assertNull($this->installer->payloadForSlug('totally-made-up-plugin'));
        self::assertNull($this->installer->payloadForSlug('elementor-pro'));
    }

    /**
     * An installable candidate whose source is NOT the one this installer can
     * drive (WordPress.org) is an invalid source for THIS installer, not a
     * missing one: it must be refused, never installed from a place core's
     * wp.updates.installPlugin flow cannot reach.
     */
    public function testRejectsInstallableCandidateWithUnsupportedSource(): void
    {
        $premiumOnly = new PageBuilderCandidate(
            slug: 'premium-builder',
            label: 'Premium Builder',
            kind: PageBuilderKind::VisualBuilder,
            isCore: false,
            isFree: false,
            installable: true,
            recommended: false,
            lockInNote: 'Commercial only.',
            performanceNote: 'No speed claims.',
            costNote: 'Paid; not distributed via WordPress.org.',
            editorTarget: null,
            source: PageBuilderSource::Premium,
        );
        $installer = new PageBuilderInstaller(new PageBuilderCatalog([$premiumOnly]), $this->plugins);

        self::assertNull($installer->payloadForSlug('premium-builder'));
        self::assertSame([], $installer->installPayload());
    }

    public function testInstallAndActivateLabelsFlowFromFilesystemState(): void
    {
        // Both allowlisted candidates: one active, one installed/inactive.
        $this->plugins->installed['brizy'] = 'brizy/brizy.php';
        $this->plugins->active[] = 'brizy/brizy.php';
        $this->plugins->installed['generateblocks'] = 'generateblocks/generateblocks.php';

        foreach ($this->installer->installPayload() as $row) {
            if ($row['slug'] === 'brizy') {
                self::assertSame('Active', $row['buttonLabel']);
            }
            if ($row['slug'] === 'generateblocks') {
                self::assertSame('Activate', $row['buttonLabel']);
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonInstallableSlugs(): iterable
    {
        yield 'native core has no plugin slug' => [''];
        yield 'excluded builder never catalogued' => ['bricks'];
        yield 'excluded builder never catalogued either' => ['spectra'];
        yield 'kadence alternative metadata' => ['kadence-blocks'];
    }

    /**
     * The allowlist payload for a slug, narrowed to the non-null row shape.
     * payloadForSlug() returns null for anything outside the install
     * allowlist; a null here means the slug under test is not installable.
     *
     * @return array{
     *     slug: string,
     *     label: string,
     *     name: string,
     *     basename: ?string,
     *     alreadyInstalled: bool,
     *     alreadyActive: bool,
     *     buttonLabel: string,
     *     buttonDisabled: bool
     * }
     */
    private function payload(string $slug): array
    {
        $row = $this->installer->payloadForSlug($slug);
        self::assertNotNull($row, 'Expected an installable payload for ' . $slug);

        return $row;
    }
}
