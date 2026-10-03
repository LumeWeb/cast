<?php
/**
 * Publish admin page — the approved publishing-destination layout
 * (plans/publishing-destination-redesign.md):
 *
 *   1. Your site address   — the durable address summary (source label +
 *      address + the "stays the same" note), or the "choose an address"
 *      prompt. The inline address wizard (three native radio choices with
 *      branch-specific fields, a plain review and one confirm button) renders
 *      hidden in the same card and the orchestrator reveals it.
 *   2. Your publish        — the operational center: state chip, run label,
 *      stage, progress, last published, the "why" copy, Publish changes /
 *      Cancel publish / Finish publishing, the queued start-now escape, the
 *      readiness line and environment problems.
 *   3. Connect your domain — ONLY when the address is a custom domain: the
 *      selected domain's DNS steps (values with copy controls), the
 *      check-again action and the security-certificate status. Platform and
 *      existing-site destinations never receive registrar/DNS setup copy.
 *   4. When to publish     — native publish-trigger radios.
 *   5. Your Pinner account — supporting account/workspace information.
 *
 * Presentation / escaping only: every display/action decision is resolved by
 * PublishDashboardView (and DomainDashboardView for the domain block) and
 * handed in as explicit view data; the template never re-derives a policy
 * from raw status fields. No output buffering or string-built HTML: the
 * template emits static markup and escapes every dynamic value. Dedicated
 * styling lives in assets/css/cast-admin.css (enqueued only on this screen).
 *
 * The parked post-upload website card and the diagnostics-only Domain panel
 * (with its generic Actions list and implicit first-domain pick) are gone:
 * the DNS block below renders only when the panel's domain bundle is the
 * destination's own domain — an explicit match, never `domains[0]`.
 *
 * Available view keys:
 *   - menuTitle: string
 *   - view: PublishDashboardView  (the publish status view model)
 *   - domainView: DomainDashboardView|null  (the domain setup view model;
 *     its DNS/SSL blocks are only used inside the custom-domain card)
 *
 * @var array<string, mixed> $view
 */
$publish = $view['view'];

// Presentational labels derived from the already-pinned view-model state. The
// values (readiness/mode) are decisions PublishDashboardView made; this table
// only maps them to copy, and it is always escaped before it is echoed.
$readinessLabels = [
    'ready' => 'Ready to publish',
    'config' => 'Configuration required',
    // The setup slot is the one onboarding instruction and it is
    // skipped-aware: an incomplete onboarding says "finish onboarding", while
    // a deliberately skipped one never instructs finishing it — the truthful
    // next step is publishing. (The value is the view model's copy-only
    // onboardingSkipped passthrough; the template only maps it to copy.)
    'setup' => $publish->onboardingSkipped
        ? 'Onboarding skipped — publish when ready'
        : 'Finish onboarding to publish',
    'no_content' => 'No publishable content yet',
    'choose_address' => 'Choose an address to publish',
];
$readinessLabel = $readinessLabels[$publish->readiness] ?? 'Ready to publish';

// The "when to publish" trigger: native radios with first-time-owner labels.
// The per-option help pins the exact ContentPublishScheduler semantics:
// Manual is drift-only (a click queues a background publish), On-update
// auto-schedules a debounced/coalesced background publish. No wording may
// imply synchronous publishing. Mirrored client-side by cast-publish.js.
$modeOptions = [
    ['value' => 'manual', 'label' => 'Only when I choose'],
    ['value' => 'on_update', 'label' => 'When I update my site'],
];
$modeHelp = [
    'manual' => 'Publish only when you choose. Clicking Publish to Pinner queues a background publish — content edits wait until then and nothing publishes on its own.',
    'on_update' => 'Publish automatically. Eligible content changes queue a background publish for you — rapid edits are combined into one debounced publish instead of one per save.',
];
$activeModeHelp = $modeHelp[$publish->mode] ?? $modeHelp['manual'];
$primaryAction = $publish->primaryAction;

// When the trigger selector is withheld (a non-ready surface, or a live
// run), the card carries ONE concise informative line — never an empty
// heading. The values (canChangeMode/runState) are the pinned view-model
// decisions; this table only maps them to copy.
$modeLockedMessage = $publish->canChangeMode ? null : (in_array($publish->runState, [
    \LumeWeb\Cast\Admin\PublishDashboardView::RUN_STATE_QUEUED,
    \LumeWeb\Cast\Admin\PublishDashboardView::RUN_STATE_RUNNING,
    \LumeWeb\Cast\Admin\PublishDashboardView::RUN_STATE_PAUSED,
], true)
    ? 'The publish trigger is locked while a publish is in progress.'
    : 'You can choose a publish trigger once your site is ready to publish.');

// ------------------------- your site address -------------------------
//
// The durable address summary is a passthrough of the persisted destination
// setup (PublishDashboardView::$destination / $destinationLifecycle): the
// template only maps source → label and composes the display address, never
// re-derives a policy. A generated platform address has no concrete name
// until the first publish creates it, so it reads as a plain promise.
$destination = $publish->destination;
$destinationLifecycle = $publish->destinationLifecycle;
$addressSource = $destination !== null && isset($destination['source']) ? (string) $destination['source'] : null;
$addressSourceLabel = match ($addressSource) {
    'platform' => 'Pinner address',
    'custom' => 'Your own domain',
    'existing' => 'Existing Pinner site',
    default => null,
};
$platformDomain = $destination !== null && !empty($destination['platform_domain']) ? (string) $destination['platform_domain'] : '';
$platformLabel = $destination !== null && !empty($destination['label']) ? (string) $destination['label'] : '';
$addressValue = match ($addressSource) {
    'platform' => $platformLabel !== ''
        ? ($platformDomain !== '' ? $platformLabel . '.' . $platformDomain : $platformLabel)
        : 'A free Pinner address',
    'custom', 'existing' => $destination !== null && !empty($destination['domain']) ? (string) $destination['domain'] : '',
    default => '',
};
$addressDomain = $addressSource === 'custom' || $addressSource === 'existing' ? $addressValue : '';
// The summary's closing note follows the destination lifecycle: a draft
// promises durability, a confirmed (not-yet-created) choice is a concise
// FROZEN summary, and a created/attached address is final.
$addressDurableNote = match ($destinationLifecycle) {
    'confirmed' => 'Your address is confirmed and can no longer be changed.',
    'created_or_attached' => 'Your address is live and can no longer be changed.',
    default => 'Your address stays the same after your first publish.',
};
// The address may be reviewed only while the choice is still a DRAFT: a
// confirmed (frozen) or created/attached choice shows a concise summary with
// no review/edit entry, and once the site is live (a website identity
// exists) the choice is final.
$isFirstPublish = $publish->websiteName === null;
$canReviewAddress = $addressSource !== null
    && $isFirstPublish
    && $destinationLifecycle === 'draft';

// The wizard's prefill: a refresh must never lose an unconfirmed choice, so
// the server render carries the persisted draft into the branch inputs.
$wizardSource = $addressSource ?? 'platform';

// The wizard's confirm button promises the publish ONLY when the server's own
// first-publish start decision (PublishDashboardView::$canStart) says a run
// may start right now — a fresh site with no eligible content is not
// publish-ready, so its button truthfully says "Create address". Mirrored
// client-side by addressConfirmLabelFor() in cast-publish.js.
$addressConfirmLabel = $publish->canStart ? 'Create address and publish' : 'Create address';
$wizardCustomDomain = $wizardSource === 'custom' ? $addressValue : '';
$wizardNamespace = $wizardSource === 'custom' && !empty($destination['namespace']) ? (string) $destination['namespace'] : 'icann';
$wizardDnsManaged = ! ( $wizardSource === 'custom'
    && $destination !== null
    && isset( $destination['dns_hosting_enabled'] )
    && $destination['dns_hosting_enabled'] === false );
$wizardExistingId = $wizardSource === 'existing' && !empty($destination['website_id']) ? (string) $destination['website_id'] : '';
// The wizard's plain review read-out, prefilled from the persisted draft so a
// refresh never loses the unconfirmed choice's review. A generated platform
// address is phrased as Pinner CREATING a free address — never "available
// at …", which no generated address is.
$wizardReviewCopy = match (true) {
    $wizardSource === 'platform' => 'Pinner will create a free address for your site. You cannot change this address after your first publish.',
    $wizardSource === 'custom' && $wizardCustomDomain !== '' => 'Your site will be available at ' . $wizardCustomDomain . '. You cannot change this address after your first publish.',
    $wizardSource === 'existing' && $wizardExistingId !== '' => 'Your site will be available at ' . $addressValue . '. You cannot change this address after your first publish.',
    default => '',
};
?>
<div class="wrap cast-publish-wrap">

    <header class="cast-publish-header">
        <h1><?php echo esc_html($view['menuTitle'] ?? ''); ?></h1>
        <p class="cast-publish-subtitle"><?php echo esc_html('Put your latest changes online.'); ?></p>
    </header>

    <?php // 1 — YOUR SITE ADDRESS ?>
    <section class="card cast-address-card" aria-labelledby="cast-address-heading">
        <h2 id="cast-address-heading"><?php echo esc_html('Your site address'); ?></h2>

        <?php if ($addressSource === null) : ?>
            <p class="cast-address-prompt"><?php echo esc_html('Choose an address for your site.'); ?></p>
            <button type="button" class="button button-primary cast-address-choose"
                    data-cast-address-action="choose"><?php echo esc_html('Choose address'); ?></button>
        <?php else : ?>
            <p class="cast-address-state">
                <span class="cast-address-source cast-address-source-<?php echo esc_attr($addressSource); ?>">
                    <?php echo esc_html($addressSourceLabel); ?>
                </span>
            </p>
            <p class="cast-address-value"><?php echo esc_html($addressValue); ?></p>
            <p class="cast-address-durable"><?php echo esc_html($addressDurableNote); ?></p>
            <?php if ($canReviewAddress) : ?>
                <button type="button" class="button cast-address-review"
                        data-cast-address-action="review"><?php echo esc_html('Review address'); ?></button>
            <?php endif; ?>
        <?php endif; ?>

        <?php
        // The inline address wizard: a focused guide, not a second dashboard.
        // It renders hidden in the server paint (the no-JS prompt above stays
        // the entry point) and the orchestrator reveals it on "Choose
        // address" / "Review address". Exactly three native radio choices;
        // each branch exposes only its own fields. The final review is a
        // plain read-out — the user never re-types the domain to confirm.
        ?>
        <div class="cast-address-wizard" data-cast-address-wizard hidden>
            <h3 id="cast-address-wizard-heading"><?php echo esc_html('Choose an address for your site'); ?></h3>

            <fieldset class="cast-address-wizard-sources" aria-labelledby="cast-address-wizard-heading">
                <label class="cast-address-source-option">
                    <input type="radio" name="cast-address-source" value="platform"
                           class="cast-address-source-input"
                           <?php echo $wizardSource === 'platform' ? 'checked' : ''; ?>>
                    <span class="cast-address-source-name"><?php echo esc_html('Get a free Pinner address'); ?></span>
                    <span class="cast-address-source-help"><?php echo esc_html('Pinner sets it up and looks after it.'); ?></span>
                </label>
                <label class="cast-address-source-option">
                    <input type="radio" name="cast-address-source" value="custom"
                           class="cast-address-source-input"
                           <?php echo $wizardSource === 'custom' ? 'checked' : ''; ?>>
                    <span class="cast-address-source-name"><?php echo esc_html('Use a domain you own'); ?></span>
                    <span class="cast-address-source-help"><?php echo esc_html('A domain you already own, like shop.example.com.'); ?></span>
                </label>
                <label class="cast-address-source-option">
                    <input type="radio" name="cast-address-source" value="existing"
                           class="cast-address-source-input"
                           <?php echo $wizardSource === 'existing' ? 'checked' : ''; ?>>
                    <span class="cast-address-source-name"><?php echo esc_html('Use a Pinner site you already have'); ?></span>
                    <span class="cast-address-source-help"><?php echo esc_html('Pick a site from your Pinner account.'); ?></span>
                </label>
            </fieldset>

            <div class="cast-address-branch" data-cast-address-branch="platform"
                 <?php echo $wizardSource === 'platform' ? '' : 'hidden'; ?>>
                <p class="cast-address-branch-note"><?php echo esc_html('There is nothing to fill in — Pinner creates your free address when you first publish.'); ?></p>
            </div>

            <div class="cast-address-branch" data-cast-address-branch="custom"
                 <?php echo $wizardSource === 'custom' ? '' : 'hidden'; ?>>
                <label class="cast-address-field-label" for="cast-address-custom-domain"><?php echo esc_html('Your domain'); ?></label>
                <input type="text" id="cast-address-custom-domain" class="cast-address-custom-domain"
                       placeholder="e.g. shop.example.com" autocomplete="off"
                       value="<?php echo esc_attr($wizardCustomDomain); ?>">
                <label class="cast-address-field-label" for="cast-address-custom-namespace"><?php echo esc_html('Domain type'); ?></label>
                <select id="cast-address-custom-namespace" class="cast-address-custom-namespace">
                    <option value="icann" <?php echo $wizardNamespace === 'icann' ? 'selected' : ''; ?>><?php echo esc_html('A standard domain (e.g. .com)'); ?></option>
                    <option value="hns" <?php echo $wizardNamespace === 'hns' ? 'selected' : ''; ?>><?php echo esc_html('A Handshake (HNS) name'); ?></option>
                </select>
                <fieldset class="cast-address-dns-options">
                    <legend class="cast-address-field-label"><?php echo esc_html('Who handles the DNS setup?'); ?></legend>
                    <label class="cast-address-dns-option">
                        <input type="radio" name="cast-address-dns" value="managed"
                               <?php echo $wizardDnsManaged ? 'checked' : ''; ?>>
                        <?php echo esc_html('Let Pinner handle DNS'); ?>
                    </label>
                    <details class="cast-address-advanced"
                             <?php echo $wizardDnsManaged ? '' : 'open'; ?>>
                        <summary class="cast-address-advanced-summary"><?php echo esc_html('Advanced'); ?></summary>
                        <label class="cast-address-dns-option">
                            <input type="radio" name="cast-address-dns" value="self"
                                   <?php echo $wizardDnsManaged ? '' : 'checked'; ?>>
                            <?php echo esc_html('I will handle DNS'); ?>
                        </label>
                    </details>
                </fieldset>
                <?php
                // Namespace-specific DNS copy: ICANN and HNS names work
                // differently, so the note follows the chosen domain type.
                // The HNS note stays plain (no "HIP-5"/contract jargon):
                // the records are held on-chain at the name's parent and
                // Pinner manages them — the exact nameserver values may
                // appear here later.
                ?>
                <p class="cast-address-namespace-note" data-cast-namespace-note="icann"
                   <?php echo $wizardNamespace === 'hns' ? 'hidden' : ''; ?>>
                    <?php echo esc_html('Pinner creates and manages the DNS records for this domain.'); ?>
                </p>
                <p class="cast-address-namespace-note" data-cast-namespace-note="hns"
                   <?php echo $wizardNamespace === 'hns' ? '' : 'hidden'; ?>>
                    <?php echo esc_html('For Handshake (HNS) names, the DNS records are held on-chain at the name’s parent. Pinner manages this for you, and the exact nameserver values may appear here later.'); ?>
                </p>
            </div>

            <div class="cast-address-branch" data-cast-address-branch="existing"
                 <?php echo $wizardSource === 'existing' ? '' : 'hidden'; ?>>
                <label class="cast-address-field-label" for="cast-address-existing-website"><?php echo esc_html('Choose a site'); ?></label>
                <select id="cast-address-existing-website" class="cast-address-existing-website">
                    <?php if ($wizardExistingId !== '') : ?>
                        <option value="<?php echo esc_attr($wizardExistingId); ?>" selected><?php echo esc_html($addressValue); ?></option>
                    <?php endif; ?>
                </select>
                <p class="cast-address-existing-loading" role="status" aria-live="polite" hidden><?php echo esc_html('Loading your Pinner sites…'); ?></p>
                <p class="cast-address-existing-error notice notice-error" aria-live="polite" hidden></p>
                <p class="cast-address-existing-empty" <?php echo $wizardExistingId !== '' ? 'hidden' : ''; ?>><?php echo esc_html('No Pinner sites available to use. Create one in Pinner first.'); ?></p>
            </div>

            <div class="cast-address-review-box" data-cast-address-review hidden>
                <p class="cast-address-review-copy"><?php echo esc_html($wizardReviewCopy); ?></p>
            </div>

            <p class="cast-address-wizard-error notice notice-error" aria-live="polite" hidden></p>
            <p class="cast-address-wizard-result" aria-live="polite"></p>

            <button type="button" class="button button-primary cast-address-confirm"
                    data-cast-address-action="confirm" disabled><?php echo esc_html($addressConfirmLabel); ?></button>
        </div>
    </section>

    <?php // 2 — YOUR PUBLISH ?>
    <section class="card cast-publish-card" aria-labelledby="cast-publish-state-heading">
        <h2 id="cast-publish-state-heading"><?php echo esc_html('Your publish'); ?></h2>

        <p class="cast-publish-state">
            <span class="cast-publish-state-chip cast-publish-state-<?php echo esc_attr($publish->runState); ?>" role="status">
                <?php echo esc_html($publish->stateLabel); ?>
            </span>
        </p>

        <p class="cast-publish-run-label"><?php echo esc_html($publish->runLabel); ?></p>

        <?php if ($publish->stageLabel !== null) : ?>
            <p class="cast-publish-stage"><?php echo esc_html($publish->stageLabel); ?></p>
        <?php endif; ?>

        <div class="cast-publish-progress">
            <?php if (!$publish->awaitingWebsite) : ?>
                <div class="cast-publish-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr((string) $publish->progressPercent); ?>">
                    <div class="cast-publish-progress-fill" style="width: <?php echo esc_attr((string) $publish->progressPercent); ?>%"></div>
                </div>
            <?php endif; ?>
            <p class="cast-publish-progress-text">
                <?php if (!$publish->awaitingWebsite) : ?>
                    <span class="cast-publish-progress-value"><?php echo esc_html((string) $publish->progressPercent); ?>%</span>
                <?php endif; ?>
                <?php
                // The queued ETA anchor: for a queued run the server pins the
                // expected start (queuedAt + tick cadence) as a data attribute
                // so a no-refresh client countdown always reconciles against
                // the same anchor the server-rendered label came from.
                $queuedEtaStart = $publish->queuedEtaStartAt;
                ?>
                <span class="cast-publish-progress-count"
                      <?php echo $queuedEtaStart === null ? '' : 'data-cast-queued-eta="' . esc_attr((string) $queuedEtaStart) . '"'; ?>
                      <?php echo $publish->progressTotal === null ? '' : 'data-cast-progress-total="' . esc_attr((string) $publish->progressTotal) . '"'; ?>>
                    <?php echo esc_html($publish->progressCountLabel); ?>
                </span>
            </p>
        </div>

        <?php if ($publish->websiteName !== null) : ?>
            <p class="cast-publish-site"><?php echo esc_html('Website'); ?>: <?php echo esc_html($publish->websiteName); ?></p>
        <?php endif; ?>

        <?php if ($publish->publishCid !== null) : ?>
            <p class="cast-publish-cid"><?php echo esc_html('Published CID'); ?>: <?php echo esc_html($publish->publishCid); ?></p>
        <?php endif; ?>

        <?php if ($publish->dirty) : ?>
            <p class="cast-publish-dirty"><?php echo esc_html('You have unpublished changes.'); ?></p>
        <?php endif; ?>

        <?php if ($publish->superseded) : ?>
            <p class="cast-publish-superseded"><?php echo esc_html('A newer publish is queued after the current one.'); ?></p>
        <?php endif; ?>

        <?php if ($publish->lastError !== null) : ?>
            <p class="cast-publish-error notice notice-error"><?php echo esc_html($publish->lastError); ?></p>
        <?php endif; ?>

        <p class="cast-publish-last-checked">
            <span class="cast-publish-last-checked-label"><?php echo esc_html('Last checked'); ?></span>:
            <span class="cast-publish-last-checked-time"><?php echo esc_html('Just now'); ?></span>
        </p>

        <?php
        // The readiness line is HIDDEN while onboarding is outstanding:
        // its label would repeat the context line's instruction verbatim
        // ("Finish onboarding to publish" / "…publish your site."). The
        // context line is the one clear instruction. The element stays in
        // the DOM (empty) so the orchestrator can repopulate it when a
        // later poll reports a different readiness.
        ?>
        <?php if ($publish->readiness === 'setup') : ?>
            <p class="cast-publish-readiness-level cast-publish-level-setup" hidden></p>
        <?php else : ?>
            <p class="cast-publish-readiness-level cast-publish-level-<?php echo esc_attr($publish->readiness); ?>">
                <?php echo esc_html($readinessLabel); ?>
            </p>
        <?php endif; ?>

        <?php if ($publish->envProblems !== []) : ?>
            <ul class="cast-publish-env-problems">
                <?php foreach ($publish->envProblems as $problem) : ?>
                    <li class="cast-publish-env-problem">
                        <code><?php echo esc_html($problem['variable']); ?></code>
                        <?php echo esc_html($problem['message']); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <p class="cast-publish-context" id="cast-publish-context"><?php echo esc_html($publish->contextMessage); ?></p>

        <p class="cast-publish-actions">
            <button type="button" class="button button-primary button-hero cast-publish-primary"
                    data-cast-publish-action="<?php echo esc_attr($primaryAction ?? ''); ?>"
                    <?php echo $primaryAction === null ? 'disabled' : ''; ?>
                    aria-describedby="cast-publish-context">
                <?php echo esc_html('Publish changes'); ?>
            </button>
        </p>

        <p class="cast-publish-result" aria-live="polite"></p>

        <?php if ($publish->escapeAction !== null) : ?>
            <div class="cast-publish-escape" data-cast-escape hidden>
                <p class="cast-publish-escape-help"><?php echo esc_html('Queued longer than expected? You can start it now.'); ?></p>
                <button type="button" class="button cast-publish-start-now"
                        data-cast-publish-action="<?php echo esc_attr($publish->escapeAction); ?>">
                    <?php echo esc_html('Start now'); ?>
                </button>
            </div>
        <?php endif; ?>

        <?php if ($publish->canCancel || $publish->canPublishArtifact) : ?>
            <div class="cast-publish-secondary-actions">
                <?php if ($publish->canCancel) : ?>
                    <button type="button" class="button cast-publish-cancel" data-cast-publish-action="cancel"><?php echo esc_html('Cancel publish'); ?></button>
                <?php endif; ?>
                <?php if ($publish->canPublishArtifact) : ?>
                    <button type="button" class="button cast-publish-republish" data-cast-publish-action="artifact"><?php echo esc_html('Finish publishing'); ?></button>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="cast-publish-secondary-actions cast-publish-refresh-actions">
            <button type="button" class="button cast-publish-refresh" data-cast-publish-refresh><?php echo esc_html('Refresh status'); ?></button>
        </div>
    </section>

    <?php
    // 3 — CONNECT YOUR DOMAIN (custom destinations only).
    //
    // The card is scoped to the destination's own domain: the DNS/SSL blocks
    // from the domain panel render only when the panel's domain bundle IS the
    // destination domain (an explicit name match). A bundle for any other
    // domain — including an implicit first-list pick — never leaks in, and
    // platform/existing destinations never reach this card at all.
    $domainPanel = $view['domainView'] ?? null;
    $domainCardMatch = $domainPanel !== null
        && $domainPanel->dnsDomain !== null
        && $addressDomain !== ''
        && ($domainPanel->dnsDomain['domain'] ?? '') === $addressDomain;
    ?>
    <?php if ($addressSource === 'custom' && $addressDomain !== '') : ?>
        <section class="card cast-domain-setup-card" aria-labelledby="cast-domain-setup-heading">
            <h2 id="cast-domain-setup-heading"><?php echo esc_html('Connect your domain'); ?></h2>

            <p class="cast-domain-setup-domain"><?php echo esc_html($addressDomain); ?></p>
            <p class="cast-domain-setup-dns-mode">
                <?php echo esc_html(!empty($destination['dns_hosting_enabled'])
                    ? 'Pinner is handling the DNS for you.'
                    : 'You are handling the DNS yourself.'); ?>
            </p>

            <h3 class="cast-domain-section"><?php echo esc_html('What to change'); ?></h3>
            <p class="cast-publish-readiness-level cast-domain-dns-label cast-publish-level-<?php echo esc_attr($domainPanel?->dnsLevel ?? 'note'); ?>">
                <?php echo esc_html($domainPanel?->dnsLabel ?? 'The DNS steps will appear here once your site is published.'); ?>
            </p>

            <?php if ($domainCardMatch) : ?>
                <?php
                // The DNS delegation guidance block. The data is the same
                // JSON-safe serialization the JS renderDomainDns() rebuilds on
                // the "check again" action, so the initial server paint (and
                // no-JS users) see the same managed / HNS / self-managed
                // records the client re-fetch shows. Every value below is a
                // server echo of the delegation/check DTOs — DNSSEC state and
                // per-record checks are rendered verbatim, never derived here.
                // Comments explain intent; this template never computes a
                // record. Each value carries a copy control beside it.
                $dnsBlock = $domainPanel->dnsDomain;
                if ($dnsBlock !== null) {
                    $dnsDelegation = isset($dnsBlock['delegation']) && is_array($dnsBlock['delegation']) ? $dnsBlock['delegation'] : null;
                    $dnsChecks = isset($dnsBlock['checks']) && is_array($dnsBlock['checks']) ? $dnsBlock['checks'] : [];
                    $dnsHosted = !empty($dnsBlock['dns_hosting_enabled']);
                    $dnsNamespace = isset($dnsBlock['namespace']) ? (string) $dnsBlock['namespace'] : '';
                    $dnsName = isset($dnsBlock['domain']) ? (string) $dnsBlock['domain'] : '';
                    $dnsMode = $dnsDelegation !== null && isset($dnsDelegation['mode']) ? (string) $dnsDelegation['mode'] : '';
                    $dnsNameservers = $dnsDelegation !== null && isset($dnsDelegation['nameservers']) && is_array($dnsDelegation['nameservers'])
                        ? $dnsDelegation['nameservers']
                        : [];
                    $dnsParent = $dnsDelegation !== null && isset($dnsDelegation['parent_records']) && is_array($dnsDelegation['parent_records'])
                        ? $dnsDelegation['parent_records']
                        : [];
                    $dnsAuthoritative = $dnsDelegation !== null && isset($dnsDelegation['authoritative_records']) && is_array($dnsDelegation['authoritative_records'])
                        ? $dnsDelegation['authoritative_records']
                        : [];
                    $dnsDnssec = $dnsDelegation !== null && isset($dnsDelegation['dnssec']) ? (string) $dnsDelegation['dnssec'] : '';
                    $dnsDnssecError = $dnsDelegation !== null && isset($dnsDelegation['dnssec_error']) ? (string) $dnsDelegation['dnssec_error'] : '';
                    $dnsIcann = $dnsNamespace === 'icann';
                    $dnsIsHns = $dnsNamespace === 'hns';
                    // On-chain managed (an HNS binding whose DNS is served by
                    // an external on-chain contract): a distinct state checked
                    // BEFORE the managed / self-managed delegation branches,
                    // so it never inherits their copy.
                    $dnsOnchain = isset($dnsBlock['status']) && $dnsBlock['status'] === 'onchain_managed';
                }
                // A copy control beside one DNS value: a real button carrying
                // the exact value to copy (escaped) for the orchestrator.
                $dnsCopyButton = static function (string $value): string {
                    return '<button type="button" class="button cast-domain-copy" data-cast-copy="' . esc_attr($value) . '">' . esc_html('Copy') . '</button>';
                };
                // A copyable value: the exact value in its own span (so the
                // value stays a clean, selectable, copyable unit) with the
                // copy control beside it.
                $dnsCopyValue = static function (string $value) use ($dnsCopyButton): string {
                    return '<span class="cast-domain-copy-value">' . esc_html($value) . '</span> ' . $dnsCopyButton($value);
                };
                ?>
                <?php if ($dnsBlock !== null) : ?>
                    <div class="cast-domain-records cast-domain-dns-records cast-domain-dns-copy">
                        <dl class="cast-domain-dns-summary">
                            <dt><?php echo esc_html('Domain'); ?></dt>
                            <dd><?php echo esc_html($dnsBlock['domain']); ?></dd>
                            <?php if ($dnsNamespace !== '') : ?>
                                <dt><?php echo esc_html('Namespace'); ?></dt>
                                <dd><?php echo esc_html($dnsNamespace); ?></dd>
                            <?php endif; ?>
                            <?php if (isset($dnsBlock['status']) && $dnsBlock['status'] !== '') : ?>
                                <dt><?php echo esc_html('Status'); ?></dt>
                                <dd><?php echo esc_html((string) $dnsBlock['status']); ?></dd>
                            <?php endif; ?>
                            <?php if (isset($dnsBlock['gateway_host']) && $dnsBlock['gateway_host'] !== null && $dnsBlock['gateway_host'] !== '') : ?>
                                <dt><?php echo esc_html('Gateway'); ?></dt>
                                <dd><?php echo $dnsCopyValue((string) $dnsBlock['gateway_host']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside ?>
                            <?php endif; ?>
                        </dl>

                        <?php if ($dnsOnchain) : ?>
                            <?php
                            // On-chain managed: the domain is held on-chain and
                            // its DNS records are set on-chain, not in a
                            // Pinner-managed zone. Show only the server-
                            // returned DNSLink/TLSA guidance (the delegation
                            // bundle's records, when present, plus the per-record
                            // checks below) and never claim Pinner manages the
                            // DNS — that framing belongs to the delegated
                            // managed / self-managed cases only.
                            ?>
                            <p class="cast-domain-dns-instruction"><?php echo esc_html('This domain is managed on-chain, so its DNS records are set on-chain, not by Pinner.'); ?></p>
                            <p class="cast-domain-dns-instruction"><?php echo esc_html('Publish the DNSLink and TLSA records shown below on-chain, wherever you manage this domain\'s DNS.'); ?></p>
                            <?php if ($dnsDelegation !== null && ($dnsParent !== [] || $dnsAuthoritative !== [])) : ?>
                                <h4 class="cast-domain-dns-subheading"><?php echo esc_html('Records to publish on-chain'); ?></h4>
                                <table class="cast-domain-dns-table">
                                    <thead>
                                        <tr><th><?php echo esc_html('Type'); ?></th><th><?php echo esc_html('Value'); ?></th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_merge($dnsParent, $dnsAuthoritative) as $dnsRecord) : ?>
                                            <?php
                                            $dnsValue = isset($dnsRecord['value']) ? (string) $dnsRecord['value'] : '';
                                            $dnsType = isset($dnsRecord['type']) ? (string) $dnsRecord['type'] : '';
                                            $dnsValues = $dnsType === 'NS' && str_contains($dnsValue, ',')
                                                ? array_map('trim', explode(',', $dnsValue))
                                                : [$dnsValue];
                                            ?>
                                            <?php foreach ($dnsValues as $dnsSingle) : ?>
                                                <tr><td><?php echo esc_html($dnsType); ?></td><td><?php echo $dnsCopyValue($dnsSingle); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside ?></td></tr>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        <?php elseif ($dnsDelegation === null) : ?>
                            <p class="cast-domain-dns-instruction">
                                <?php echo esc_html('No delegation records are available for ' . $dnsName . '.'); ?>
                            </p>
                        <?php else : ?>
                            <?php if ($dnsHosted && $dnsIcann) : ?>
                                <?php
                                // Managed ICANN: the operator points the
                                // registrar's nameservers at Pinner's DNS
                                // servers (the delegation nameservers), then
                                // publishes the parent records (NS + DS) at the
                                // registrar. The authoritative side is handled
                                // for them.
                                ?>
                                <p class="cast-domain-dns-instruction"><?php echo esc_html('Update your domain\'s nameservers at your registrar.'); ?></p>
                                <?php if ($dnsNameservers !== []) : ?>
                                    <table class="cast-domain-dns-table">
                                        <thead>
                                            <tr><th><?php echo esc_html('Name'); ?></th><th><?php echo esc_html('Type'); ?></th><th><?php echo esc_html('Value'); ?></th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($dnsNameservers as $dnsNs) : ?>
                                                <tr><td><?php echo esc_html($dnsName); ?></td><td><?php echo esc_html('NS'); ?></td><td><?php echo $dnsCopyValue((string) $dnsNs); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside ?></td></tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                                <p class="cast-domain-dns-instruction"><?php echo esc_html('Point your registrar\'s nameservers to the records below.'); ?></p>
                                <p class="cast-domain-dns-instruction"><?php echo esc_html('Pinner manages your DNS, so the authoritative side is handled for you.'); ?></p>
                                <?php $dnsParentTitle = 'Parent records (configure at your registrar)'; ?>
                            <?php elseif ($dnsHosted && $dnsIsHns) : ?>
                                <?php
                                // Managed HNS (incl. inline): the records live
                                // on-chain in the HNS wallet. Inline mode serves
                                // the authoritative side via Pinner's synthetic
                                // nameservers; managed mode handles it for the
                                // operator.
                                ?>
                                <p class="cast-domain-dns-instruction"><?php echo esc_html('Publish the records below in the DNS/records area of your HNS wallet (on-chain).'); ?></p>
                                <?php if ($dnsMode === 'inline') : ?>
                                    <p class="cast-domain-dns-instruction"><?php echo esc_html('The authoritative side is served via Pinner\'s synthetic nameservers.'); ?></p>
                                <?php else : ?>
                                    <p class="cast-domain-dns-instruction"><?php echo esc_html('Pinner manages your DNS, so the authoritative side is handled for you.'); ?></p>
                                <?php endif; ?>
                                <?php $dnsParentTitle = 'Parent records (publish in your HNS wallet)'; ?>
                            <?php else : ?>
                                <?php
                                // Self-managed: the operator configures the
                                // records at the registrar (ICANN) or in the HNS
                                // wallet (HNS) and points their own DNS server at
                                // the authoritative records.
                                ?>
                                <?php if ($dnsIcann) : ?>
                                    <p class="cast-domain-dns-instruction"><?php echo esc_html('Configure the parent records at your registrar, then point your DNS server at the authoritative records below.'); ?></p>
                                <?php else : ?>
                                    <p class="cast-domain-dns-instruction"><?php echo esc_html('Publish the parent records in the DNS/records area of your HNS wallet (on-chain), then point your own DNS server at the authoritative records below.'); ?></p>
                                <?php endif; ?>
                                <?php $dnsParentTitle = $dnsIcann ? 'Parent records (configure at your registrar)' : 'Parent records (publish in your HNS wallet)'; ?>
                            <?php endif; ?>

                            <?php if ($dnsParent !== []) : ?>
                                <h4 class="cast-domain-dns-subheading"><?php echo esc_html($dnsParentTitle); ?></h4>
                                <table class="cast-domain-dns-table">
                                    <thead>
                                        <tr><th><?php echo esc_html('Type'); ?></th><th><?php echo esc_html('Value'); ?></th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dnsParent as $dnsRecord) : ?>
                                            <?php
                                            // A nameserver record may carry several
                                            // nameservers comma-joined in a single
                                            // value; render each on its own row so
                                            // every value is visible and copyable.
                                            $dnsValue = isset($dnsRecord['value']) ? (string) $dnsRecord['value'] : '';
                                            $dnsType = isset($dnsRecord['type']) ? (string) $dnsRecord['type'] : '';
                                            $dnsValues = $dnsType === 'NS' && str_contains($dnsValue, ',')
                                                ? array_map('trim', explode(',', $dnsValue))
                                                : [$dnsValue];
                                            ?>
                                            <?php foreach ($dnsValues as $dnsSingle) : ?>
                                                <tr><td><?php echo esc_html($dnsType); ?></td><td><?php echo $dnsCopyValue($dnsSingle); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside ?></td></tr>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>

                            <?php if (!$dnsHosted && $dnsAuthoritative !== []) : ?>
                                <h4 class="cast-domain-dns-subheading"><?php echo esc_html('Authoritative records (configure on your DNS server)'); ?></h4>
                                <table class="cast-domain-dns-table">
                                    <thead>
                                        <tr><th><?php echo esc_html('Type'); ?></th><th><?php echo esc_html('Value'); ?></th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dnsAuthoritative as $dnsRecord) : ?>
                                            <?php
                                            $dnsValue = isset($dnsRecord['value']) ? (string) $dnsRecord['value'] : '';
                                            $dnsType = isset($dnsRecord['type']) ? (string) $dnsRecord['type'] : '';
                                            $dnsValues = $dnsType === 'NS' && str_contains($dnsValue, ',')
                                                ? array_map('trim', explode(',', $dnsValue))
                                                : [$dnsValue];
                                            ?>
                                            <?php foreach ($dnsValues as $dnsSingle) : ?>
                                                <tr><td><?php echo esc_html($dnsType); ?></td><td><?php echo $dnsCopyValue($dnsSingle); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside ?></td></tr>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>

                            <?php
                            // The nameservers list: managed ICANN already
                            // presented each nameserver in its NAME/TYPE/VALUE
                            // table, so the list is only emitted where the
                            // table was not (HNS and self-managed bindings).
                            $dnsShowNameservers = $dnsNameservers !== [] && !($dnsHosted && $dnsIcann);
                            ?>
                            <?php if ($dnsShowNameservers) : ?>
                                <h4 class="cast-domain-dns-subheading"><?php echo esc_html('Nameservers'); ?></h4>
                                <ul class="cast-domain-nameservers">
                                    <?php foreach ($dnsNameservers as $dnsNs) : ?>
                                        <li><?php echo $dnsCopyValue((string) $dnsNs); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <?php if ($dnsDnssec !== '') : ?>
                                <p class="cast-domain-dnssec"><?php echo esc_html('DNSSEC: ' . $dnsDnssec); ?></p>
                            <?php endif; ?>
                            <?php if ($dnsDnssecError !== '') : ?>
                                <p class="cast-domain-dnssec-error"><?php echo esc_html('DNSSEC error: ' . $dnsDnssecError); ?></p>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if (!$dnsOnchain && !$dnsHosted) : ?>
                            <?php
                            // Self-managed DNS: the records the operator must
                            // add are shown above (the delegation/validation
                            // rows), then validated. The per-record values are
                            // the server-computed checks below.
                            ?>
                            <p class="cast-domain-dns-instruction"><?php echo esc_html('Add the DNS records shown above at your registrar, then validate.'); ?></p>
                        <?php endif; ?>

                        <?php if ($dnsChecks !== []) : ?>
                            <h4 class="cast-domain-dns-subheading"><?php echo esc_html('Validation checks'); ?></h4>
                            <dl class="cast-domain-checks">
                                <?php foreach ($dnsChecks as $dnsCheck) : ?>
                                    <?php
                                    $dnsCheckName = isset($dnsCheck['name']) ? (string) $dnsCheck['name'] : '';
                                    $dnsCheckMessage = isset($dnsCheck['message']) ? (string) $dnsCheck['message'] : '';
                                    $dnsCheckExpected = isset($dnsCheck['expected']) ? (string) $dnsCheck['expected'] : '';
                                    $dnsCheckFound = isset($dnsCheck['found']) ? (string) $dnsCheck['found'] : '';
                                    ?>
                                    <dt><?php echo esc_html($dnsCheckName); ?></dt>
                                    <?php if ($dnsCheckMessage !== '') : ?>
                                        <dd><?php echo esc_html($dnsCheckMessage); ?></dd>
                                    <?php endif; ?>
                                    <?php if ($dnsCheckExpected !== '') : ?>
                                        <dt><?php echo esc_html('Publish this record:'); ?></dt>
                                        <dd><?php echo $dnsCopyValue($dnsCheckExpected); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside ?></dd>
                                    <?php endif; ?>
                                    <?php if ($dnsCheckFound !== '') : ?>
                                        <dt><?php echo esc_html('Found instead:'); ?></dt>
                                        <dd><?php echo esc_html($dnsCheckFound); ?></dd>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </dl>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <button type="button" class="button cast-domain-setup-check"
                    data-domain-action="validate"
                    data-domain-id="<?php echo esc_attr($domainCardMatch ? (string) ($domainPanel->dnsDomain['id'] ?? '') : ''); ?>">
                <?php echo esc_html('I made the changes — check again'); ?>
            </button>

            <h3 class="cast-domain-section"><?php echo esc_html('Security certificate'); ?></h3>
            <p class="cast-publish-readiness-level cast-domain-ssl-label cast-publish-level-<?php echo esc_attr($domainPanel?->sslLevel ?? 'note'); ?>">
                <?php echo esc_html($domainPanel?->sslLabel ?? 'The certificate status will appear here once your site is published.'); ?>
            </p>
            <?php if ($domainCardMatch && $domainPanel->ssl !== null && $domainPanel->ssl !== []) : ?>
                <dl class="cast-domain-records cast-domain-ssl-records">
                    <?php if (isset($domainPanel->ssl['status']) && $domainPanel->ssl['status'] !== null && $domainPanel->ssl['status'] !== '') : ?>
                        <dt><?php echo esc_html('Status'); ?></dt>
                        <dd><?php echo esc_html((string) $domainPanel->ssl['status']); ?></dd>
                    <?php endif; ?>
                    <?php if (isset($domainPanel->ssl['issued_at']) && $domainPanel->ssl['issued_at'] !== null && $domainPanel->ssl['issued_at'] !== '') : ?>
                        <dt><?php echo esc_html('Issued'); ?></dt>
                        <dd><?php echo esc_html((string) $domainPanel->ssl['issued_at']); ?></dd>
                    <?php endif; ?>
                </dl>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php // 4 — WHEN TO PUBLISH ?>
    <section class="card cast-publish-when-card" aria-labelledby="cast-publish-when-heading">
        <h2 id="cast-publish-when-heading"><?php echo esc_html('When to publish'); ?></h2>

        <?php if ($publish->canChangeMode) : ?>
            <div class="cast-publish-mode-options" role="radiogroup" aria-labelledby="cast-publish-mode-label">
                <span class="cast-publish-mode-label" id="cast-publish-mode-label"><?php echo esc_html('Publish trigger'); ?></span>
                <?php foreach ($modeOptions as $modeOption) : ?>
                    <?php $modeSelected = $publish->mode === $modeOption['value']; ?>
                    <label class="cast-publish-mode-option">
                        <input type="radio"
                               name="cast-publish-mode"
                               value="<?php echo esc_attr($modeOption['value']); ?>"
                               data-cast-publish-action="mode"
                               data-cast-publish-value="<?php echo esc_attr($modeOption['value']); ?>"
                               aria-describedby="cast-publish-mode-help"
                               <?php echo $modeSelected ? 'checked disabled' : ''; ?>>
                        <?php echo esc_html($modeOption['label']); ?>
                    </label>
                    <span class="cast-publish-mode-help-sr" id="cast-publish-mode-help-<?php echo esc_attr($modeOption['value']); ?>"><?php echo esc_html($modeHelp[$modeOption['value']]); ?></span>
                <?php endforeach; ?>
            </div>
            <p class="cast-publish-mode-help" id="cast-publish-mode-help" aria-live="polite">
                <?php echo esc_html($activeModeHelp); ?>
            </p>
        <?php else : ?>
            <p class="cast-publish-mode-locked">
                <?php echo esc_html($modeLockedMessage); ?>
            </p>
        <?php endif; ?>
    </section>

    <?php // 5 — YOUR PINNER ACCOUNT ?>
    <?php if ($publish->connection instanceof \LumeWeb\Cast\Admin\ConnectionView) : ?>
        <section class="card cast-connection-card" aria-labelledby="cast-connection-heading">
            <h2 id="cast-connection-heading"><?php echo esc_html('Your Pinner account'); ?></h2>

            <?php if ($publish->connection->isResolved()) : ?>
                <p class="cast-connection-state cast-connection-state-resolved"><?php echo esc_html('Connected'); ?></p>
                <dl class="cast-connection-details">
                    <?php if ($publish->connection->accountName() !== null && $publish->connection->accountName() !== '') : ?>
                        <dt><?php echo esc_html('Account'); ?></dt>
                        <dd><?php echo esc_html($publish->connection->accountName()); ?></dd>
                    <?php endif; ?>
                    <?php if ($publish->connection->accountEmail() !== null && $publish->connection->accountEmail() !== '') : ?>
                        <dt><?php echo esc_html('Email'); ?></dt>
                        <dd><?php echo esc_html($publish->connection->accountEmail()); ?></dd>
                    <?php endif; ?>
                    <?php if ($publish->connection->workspaceLabel() !== null && $publish->connection->workspaceLabel() !== '') : ?>
                        <dt><?php echo esc_html('Workspace'); ?></dt>
                        <dd><?php echo esc_html($publish->connection->workspaceLabel()); ?></dd>
                    <?php endif; ?>
                    <?php if ($publish->connection->workspaceDomain() !== null && $publish->connection->workspaceDomain() !== '') : ?>
                        <dt><?php echo esc_html('Domain'); ?></dt>
                        <dd><?php echo esc_html($publish->connection->workspaceDomain()); ?></dd>
                    <?php endif; ?>
                </dl>
            <?php else : ?>
                <p class="cast-connection-state cast-connection-state-<?php echo esc_attr($publish->connection->state()); ?>">
                    <?php echo esc_html('Connection unavailable'); ?>
                </p>
                <?php if ($publish->connection->error() !== null) : ?>
                    <p class="cast-connection-error notice notice-error"><?php echo esc_html($publish->connection->error()); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
