<?php
/**
 * Dismissible "Cast needs Day and name permalinks" admin notice.
 *
 * Presentation/iteration/escaping only. Capability and state gating, the
 * admin-post action names and the nonce field are all resolved by
 * PermalinkGuard::renderNotice() and passed in as explicit view data.
 *
 * The notice leads with the one-click fix (Use Day and name — an admin-post
 * form that applies the canonical structure and flushes rewrite rules) and
 * offers a persistent Dismiss link behind the same nonce, mirroring the
 * publish-prompt notice pattern.
 *
 * @var array<string, mixed> $view
 */
?>
<div class="notice notice-warning is-dismissible cast-permalink-notice">
    <p class="cast-notice-copy">
        <strong><?php echo esc_html('Cast needs "Day and name" permalinks'); ?></strong> —
        <?php echo esc_html('Your current permalink structure may break publishing. Cast publishes best with the "Day and name" structure.'); ?>
    </p>

    <div class="cast-notice-actions">
        <form method="post" action="<?php echo esc_url($view['adminPostUrl']); ?>">
            <?php echo $view['nonceField']; ?>
            <input type="hidden" name="action" value="<?php echo esc_attr($view['action']); ?>">
            <button type="submit" class="button button-primary" name="cast_permalink_fix" value="canonical"><?php echo esc_html('Use Day and name'); ?></button>
        </form>

        <form method="post" action="<?php echo esc_url($view['adminPostUrl']); ?>">
            <?php echo $view['nonceField']; ?>
            <input type="hidden" name="action" value="<?php echo esc_attr($view['dismissAction']); ?>">
            <button type="submit" class="button-link" name="cast_permalink_dismiss" value="dismiss"><?php echo esc_html('Dismiss'); ?></button>
        </form>
    </div>
</div>
