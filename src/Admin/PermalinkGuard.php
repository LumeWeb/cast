<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use ComposePress\Core\HookSubscriber;
use ComposePress\Core\Hooks;
use LumeWeb\Cast\Export\ProbeStage;

/**
 * Permalink-structure guard: Cast's export probe fails when permalinks are
 * 'Plain', so the structure is never allowed to stay plain on a live site.
 *
 * Two complementary behaviours, both admin-side:
 *
 *  1. Runtime enforcement (`admin_init`): when `permalink_structure` is '' or
 *     missing (plain `?p=` permalinks), it is set to the canonical 'Day and
 *     name' structure ({@see ProbeStage::CANONICAL_PERMALINK_STRUCTURE}) and
 *     the rewrite rules are hard-flushed exactly once — the persisted flush
 *     flag ({@see PermalinkSettings::hasHardFlushed()}) stops later requests
 *     from flushing on every page load. Activation performs the same flush so
 *     front-end routing is covered the moment the plugin is enabled.
 *
 *  2. Custom-structure notice (`admin_notices`): a NON-empty structure that is
 *     not the canonical one is deliberately never overwritten (the site owner
 *     chose it), but a dismissible notice on the relevant admin screens
 *     explains that Cast expects 'Day and name' and that the custom structure
 *     may break publishing, with a 'Use Day and name' action
 *     (`admin_post_cast_use_day_and_name_permalinks`) that applies the
 *     canonical structure — and flushes — when clicked.
 *
 * Only action hooks are registered (no front-end filters); every mutation is
 * gated by the admin-level capability and, for the post actions, a nonce —
 * exactly the pattern of the other admin subscribers.
 */
final class PermalinkGuard implements HookSubscriber
{
    /** The admin-post action that applies the canonical 'Day and name' structure. */
    public const ACTION_USE_CANONICAL = 'cast_use_day_and_name_permalinks';

    /** The admin-post action that persists the current user's notice dismissal. */
    public const NOTICE_DISMISS_ACTION = 'cast_permalink_dismiss_notice';

    /** The nonce action shared by both admin-post forms (one nonce, two uses). */
    public const NONCE_ACTION = 'cast_permalink_guard';

    private const CAPABILITY = 'manage_options';

    public function __construct(
        private readonly RequestContext $context,
        private readonly PermalinkSettings $settings,
        private readonly PermalinkNoticeDismissalStore $dismissals,
        private readonly ViewRenderer $views = new ViewRenderer(),
    ) {
    }

    public function subscribe(Hooks $hooks): void
    {
        // Runtime enforcement only ever fires inside admin (admin_init), so a
        // plain-permalink front-end request is untouched, and it is capability-
        // gated inside the handler itself for safety.
        $hooks->action('admin_init', [$this, 'enforceCanonicalStructure']);
        $hooks->action('admin_notices', [$this, 'renderNotice']);
        $hooks->action('admin_post_' . self::ACTION_USE_CANONICAL, [$this, 'applyCanonical']);
        $hooks->action('admin_post_' . self::NOTICE_DISMISS_ACTION, [$this, 'dismissNotice']);
    }

    /**
     * The runtime permalink guard.
     *
     * A plain (empty) structure is replaced by the canonical 'Day and name'
     * structure, and the rewrite rules are hard-flushed exactly once (the
     * persisted flag prevents per-request churn). A non-empty custom structure
     * is NEVER overwritten here — that path is owned by the notice + explicit
     * action below.
     */
    public function enforceCanonicalStructure(): void
    {
        if (!$this->context->isCurrentUserAllowed(self::CAPABILITY)) {
            return;
        }

        if ($this->settings->structure() !== '') {
            return;
        }

        $this->settings->setStructure(ProbeStage::CANONICAL_PERMALINK_STRUCTURE);

        if (!$this->settings->hasHardFlushed()) {
            $this->settings->flushRewriteRules();
            $this->settings->markHardFlushed();
        }
    }

    /**
     * The dismissible custom-permalink admin notice, capability/state-gated.
     *
     * Only a NON-empty structure that is not the canonical one triggers it —
     * plain permalinks are already being enforced to canonical on admin_init,
     * and a canonical/custom-equal structure needs no warning.
     */
    public function renderNotice(): void
    {
        if (!$this->context->isCurrentUserAllowed(self::CAPABILITY)) {
            return;
        }

        $structure = $this->settings->structure();
        if ($structure === '' || $structure === ProbeStage::CANONICAL_PERMALINK_STRUCTURE) {
            return;
        }

        if ($this->dismissals->currentUserDismissed()) {
            return;
        }

        $this->views->render('permalink-notice.php', [
            'adminPostUrl' => admin_url('admin-post.php'),
            'action' => self::ACTION_USE_CANONICAL,
            'dismissAction' => self::NOTICE_DISMISS_ACTION,
            'nonceField' => $this->context->nonceField(self::NONCE_ACTION),
        ]);
    }

    /**
     * The admin-post handler behind the notice's 'Use Day and name' action:
     * apply the canonical structure and flush the rewrite rules immediately,
     * then redirect back to where the notice came from.
     */
    public function applyCanonical(): void
    {
        $nonce = $this->context->requestNonce();

        if (
            !$this->context->isCurrentUserAllowed(self::CAPABILITY)
            || !$this->context->verifyNonce($nonce, self::NONCE_ACTION)
        ) {
            $this->context->deny();

            return;
        }

        $this->settings->setStructure(ProbeStage::CANONICAL_PERMALINK_STRUCTURE);
        // The user explicitly asked to switch structure, so the rewrite rules
        // must match it immediately — this flush is unconditional.
        $this->settings->flushRewriteRules();
        $this->settings->markHardFlushed();

        $this->context->redirectBack(admin_url('options-permalink.php'));
    }

    /**
     * The admin-post handler for the notice's persistent per-user dismissal.
     */
    public function dismissNotice(): void
    {
        $nonce = $this->context->requestNonce();

        if (
            !$this->context->isCurrentUserAllowed(self::CAPABILITY)
            || !$this->context->verifyNonce($nonce, self::NONCE_ACTION)
        ) {
            $this->context->deny();

            return;
        }

        $this->dismissals->dismiss();
        $this->context->redirectBack(admin_url('options-permalink.php'));
    }
}
