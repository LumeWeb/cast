<?php
/**
 * Accessible live-region installer for the curated install allowlist.
 *
 * Presentation/iteration/escaping only. The install rows (allowlisted slugs,
 * display names, derived filesystem state, button labels) are pre-computed by
 * the business layer (PageBuilderInstaller) and passed in as explicit view
 * data; only installableCandidates() ever appear here. Buttons are inert
 * server-side HTML: the no-refresh install/activate orchestration is delegated
 * to the WordPress core `updates` script via the capability-gated custom asset,
 * and the live region announces progress and results to assistive tech.
 *
 * @var array<string, mixed> $view
 * @var list<array{
 *     slug: string,
 *     label: string,
 *     name: string,
 *     basename: ?string,
 *     alreadyInstalled: bool,
 *     alreadyActive: bool,
 *     buttonLabel: string,
 *     buttonDisabled: bool
 * }> $installRows
 */
$installRows = $view['installRows'] ?? [];
?>
<section aria-labelledby="cast-install-heading">
    <h2 id="cast-install-heading"><?php echo esc_html('One-click page builder install'); ?></h2>
    <p><?php echo esc_html('The curated candidates below can be installed and activated without leaving this page.'); ?></p>
    <ul class="cast-install-list">
        <?php foreach ($installRows as $row) : ?>
            <li>
                <strong><?php echo esc_html($row['label']); ?></strong>
                <?php if ($row['basename'] !== null) : ?>
                    — <code><?php echo esc_html($row['basename']); ?></code>
                <?php endif; ?>
                <button
                    type="button"
                    class="button cast-install-button"
                    data-cast-slug="<?php echo esc_attr($row['slug']); ?>"
                    <?php if ($row['buttonDisabled']) : ?>disabled="disabled"<?php endif; ?>
                ><?php echo esc_html($row['buttonLabel']); ?></button>
            </li>
        <?php endforeach; ?>
    </ul>
    <p id="cast-install-live" aria-live="polite" role="status"></p>
</section>
