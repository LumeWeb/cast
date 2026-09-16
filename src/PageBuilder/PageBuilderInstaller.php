<?php

declare(strict_types=1);

namespace LumeWeb\Cast\PageBuilder;

/**
 * Server-side install allowlist payload for the guided no-refresh installer.
 *
 * The payload the frontend is allowed to act on is derived ONLY from the
 * catalog's installableCandidates(); native core entries, alternative
 * metadata, and arbitrary request-supplied slugs resolve to no payload.
 * Button presentation (Install / Activate / Active + disabled state) is
 * derived from the real plugin filesystem via PluginStateProvider, so the UI
 * never guesses at what is installed.
 *
 * This class composes no WordPress hooks and performs no installs; it only
 * produces the allowlist the Getting Started screen may render/localize.
 */
final class PageBuilderInstaller
{
    /**
     * The only source this installer can drive. It installs via WordPress core
     * wp.updates.installPlugin, which resolves WordPress.org slugs; any other
     * source is a deliberate seam and is refused rather than mis-installed.
     */
    private const SUPPORTED_SOURCE = PageBuilderSource::WordPressOrg;

    public function __construct(
        private readonly PageBuilderCatalog $catalog,
        private readonly PluginStateProvider $plugins,
    ) {
    }

    /**
     * The complete allowlist payload, in catalog order.
     *
     * @return list<array{
     *     slug: string,
     *     label: string,
     *     name: string,
     *     basename: ?string,
     *     alreadyInstalled: bool,
     *     alreadyActive: bool,
     *     buttonLabel: string,
     *     buttonDisabled: bool
     * }>
     */
    public function installPayload(): array
    {
        $payload = [];

        foreach ($this->catalog->installableCandidates() as $candidate) {
            $row = $this->payloadForSlug((string) $candidate->slug);

            if ($row !== null) {
                $payload[] = $row;
            }
        }

        return $payload;
    }

    /**
     * The allowlist payload for one slug, or null when the slug is not an
     * installable catalog candidate. Unknown slugs and known-but-not-installable
     * candidates (native, alternative) are refused.
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
     * }|null
     */
    public function payloadForSlug(string $slug): ?array
    {
        $candidate = $this->catalog->findInstallable($slug);

        if ($candidate === null) {
            return null;
        }

        // Only candidates we can actually install from (WordPress.org) are
        // offered. An installable candidate from any other source is invalid
        // for this installer's wp.updates-driven flow and is refused.
        if ($candidate->source !== self::SUPPORTED_SOURCE) {
            return null;
        }

        $basename = $this->plugins->basename($slug);
        $installed = $basename !== null;
        $active = $installed && $this->plugins->isActive($slug);

        return [
            'slug' => $slug,
            'label' => $candidate->label,
            'name' => $candidate->label,
            'basename' => $basename,
            'alreadyInstalled' => $installed,
            'alreadyActive' => $active,
            'buttonLabel' => $active ? 'Active' : ($installed ? 'Activate' : 'Install'),
            'buttonDisabled' => $active,
        ];
    }
}
