<?php
/**
 * Dashboard notice/entrypoint for admins who have not finished onboarding.
 *
 * Presentation/iteration/escaping only. Capability and state gating, the
 * Getting Started URL, the protected Skip action, and the nonce field are all
 * resolved by the subscriber and passed in as explicit view data.
 *
 * The copy speaks to the user's own workspace setup, and the actions follow
 * WordPress admin button conventions: Get Started is the primary action, Skip
 * is a deliberate secondary action, and both sit in one aligned action group
 * (`.cast-notice-actions`) so they never run together with the text.
 *
 * @var array<string, mixed> $view
 */
?>
<div class="notice notice-info cast-onboarding-notice">
    <p class="cast-notice-copy">
        <strong><?php echo esc_html('Set up your workspace'); ?></strong> —
        <?php echo esc_html('Finish setting up your site in a couple of minutes.'); ?>
    </p>

    <div class="cast-notice-actions">
        <a class="button button-primary" href="<?php echo esc_url($view['gettingStartedUrl']); ?>"><?php echo esc_html('Get Started'); ?></a>

        <form method="post" action="<?php echo esc_url($view['adminPostUrl']); ?>">
            <?php echo $view['nonceField']; ?>
            <input type="hidden" name="action" value="<?php echo esc_attr($view['skipAction']); ?>">
            <button type="submit" class="button" name="cast_onboarding" value="skip"><?php echo esc_html('Skip for now'); ?></button>
        </form>
    </div>
</div>
