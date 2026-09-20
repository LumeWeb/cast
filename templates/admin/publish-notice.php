<?php
/**
 * Dismissible "publish to Pinner" admin notice.
 *
 * Presentation/iteration/escaping only. Capability and state gating, the
 * publish page URL, the dismiss action and the nonce field are all resolved by
 * PublishAdminSubscriber::renderNotice() and passed in as explicit view data.
 *
 * The notice leads with the same primary action as the Publish page and offers
 * a persistent Dismiss link (a nonce + capability-gated admin-post form). The
 * standard `.notice-dismiss` button (from core's `is-dismissible` class) hides
 * the notice for the session; the Dismiss form persists the choice so the
 * prompt does not return until a fresh publish re-arms it.
 *
 * @var array<string, mixed> $view
 */
?>
<div class="notice notice-info is-dismissible cast-publish-notice">
    <p class="cast-notice-copy">
        <strong><?php echo esc_html('Publish your changes to Pinner'); ?></strong> —
        <?php echo esc_html('You have changes ready to go live on your published site.'); ?>
    </p>

    <div class="cast-notice-actions">
        <a class="button button-primary" href="<?php echo esc_url($view['publishPageUrl']); ?>"><?php echo esc_html('Publish to Pinner'); ?></a>

        <form method="post" action="<?php echo esc_url($view['adminPostUrl']); ?>">
            <?php echo $view['nonceField']; ?>
            <input type="hidden" name="action" value="<?php echo esc_attr($view['dismissAction']); ?>">
            <button type="submit" class="button-link" name="cast_publish_dismiss" value="dismiss"><?php echo esc_html('Dismiss'); ?></button>
        </form>
    </div>
</div>
