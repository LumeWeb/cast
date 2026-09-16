<?php
/**
 * Shared workspace welcome panel — the single source of the onboarding
 * welcome card.
 *
 * Included by BOTH the Getting Started wizard page (templates/admin/page.php,
 * inside its content frame) and the dashboard welcome panel (rendered straight
 * into the core `welcome_panel` region on wp-admin/index.php). There is exactly
 * one copy of this card; neither surface re-declares it.
 *
 * No output buffering or string-built HTML: static markup + escaped values.
 * The primary "Get Started" and secondary "Skip for now" actions post to
 * admin-post through protected, nonce-guarded actions supplied as view data.
 *
 * Available view keys:
 *   - adminPostUrl, nonceField: string
 *   - startAction, skipAction: string
 *   - canSkip: bool
 *   - canStart: bool
 *   - gettingStartedUrl: string
 *
 * @var array<string, mixed> $view
 */
?>
<section class="card cast-welcome-card" aria-labelledby="cast-welcome-heading">
    <h2 id="cast-welcome-heading" class="cast-welcome-title"><?php echo esc_html('Set up your workspace'); ?></h2>
    <p class="cast-welcome-subtitle"><?php echo esc_html('Finish setting up your site in a couple of minutes.'); ?></p>

    <div class="cast-actions">
        <?php if ($view['canStart']) : ?>
            <form method="post" action="<?php echo esc_url($view['adminPostUrl']); ?>">
                <?php echo $view['nonceField']; ?>
                <input type="hidden" name="action" value="<?php echo esc_attr($view['startAction']); ?>">
                <button type="submit" class="button button-primary cast-action-full"><?php echo esc_html('Get Started'); ?></button>
            </form>
        <?php else : ?>
            <a class="button button-primary cast-action-full" href="<?php echo esc_url($view['gettingStartedUrl']); ?>"><?php echo esc_html('Continue setup'); ?></a>
        <?php endif; ?>
    </div>

    <?php if ($view['canSkip']) : ?>
        <div class="cast-secondary-actions">
            <form method="post" action="<?php echo esc_url($view['adminPostUrl']); ?>">
                <?php echo $view['nonceField']; ?>
                <input type="hidden" name="action" value="<?php echo esc_attr($view['skipAction']); ?>">
                <button type="submit" class="button cast-action-full"><?php echo esc_html('Skip for now'); ?></button>
            </form>
        </div>
    <?php endif; ?>
</section>
