<?php
/**
 * Publish admin page — the publish-to-Pinner workflow card.
 *
 * Presentation / iteration / escaping only. Every display/action decision is
 * resolved by PublishAdminSubscriber via PublishDashboardView and handed in as
 * explicit view data; the template never re-derives a policy from raw status
 * fields. The page is organised as one clear workflow:
 *
 *   1. Status — what is happening right now (run label, stage, progress, the
 *      published identity/CID, drift/errors).
 *   2. Action — why publishing is needed (or why it isn't available) plus the
 *      prominent "Publish to Pinner" button and the no-refresh result feedback.
 *   3. Secondary actions — the publish trigger mode selector, cancel and
 *      artifact republish, each rendered only when the view model allows it.
 *   4. Readiness, Connection, Domain — the supporting configuration surfaces.
 *
 * The page's orchestrator script (status polling against the cast/v1 REST
 * surface) lands with the admin template / JS slice, so this file renders the
 * full server-side workflow and the screen is fully readable before any JS
 * runs.
 *
 * No output buffering or string-built HTML: the template emits static markup
 * and escapes every dynamic value. Dedicated styling lives in
 * assets/css/cast-admin.css (enqueued only on this screen).
 *
 * Available view keys:
 *   - menuTitle: string
 *   - view: PublishDashboardView  (the publish status view model)
 *   - domainView: DomainDashboardView|null  (the W3 "choose a domain" panel view
 *     model; when provided the domain panel is rendered below the publish card)
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
    'setup' => 'Finish onboarding to publish',
    'no_content' => 'No publishable content yet',
];
$readinessLabel = $readinessLabels[$publish->readiness] ?? 'Ready to publish';

$modeDescriptions = [
    'manual' => 'Manual publishing',
    'on_update' => 'Publishes automatically when you update content',
];
$modeDescription = $modeDescriptions[$publish->mode] ?? 'Manual publishing';

$modeOptions = [
    ['value' => 'manual', 'label' => 'Manual'],
    ['value' => 'on_update', 'label' => 'On update'],
];

// End-user copy for each trigger mode, pinned to the exact current semantics
// in ContentPublishScheduler: Manual is drift-only (never schedules, a click
// only queues a background publish), On-update auto-schedules a debounced/
// coalesced background publish on publish-relevant content transitions. No
// wording may imply synchronous publishing — every action queues, the worker
// runs it. Only these two modes exist — a legacy stored 'scheduled' value is
// migrated server-side to On-update and never surfaces here. This copy is
// mirrored client-side by cast-publish.js.
$modeHelp = [
    'manual' => 'Publish only when you choose. Clicking Publish to Pinner queues a background publish — content edits wait until then and nothing publishes on its own.',
    'on_update' => 'Publish automatically. Eligible content changes queue a background publish for you — rapid edits are combined into one debounced publish instead of one per save.',
];
$activeModeHelp = $modeHelp[$publish->mode] ?? $modeHelp['manual'];
$primaryAction = $publish->primaryAction;
?>
<div class="wrap cast-publish-wrap">

    <header class="cast-publish-header">
        <h1><?php echo esc_html($view['menuTitle'] ?? ''); ?></h1>
        <p class="cast-publish-subtitle"><?php echo esc_html('Publish your site to Pinner and keep it live.'); ?></p>
    </header>

    <section class="card cast-publish-card" aria-labelledby="cast-publish-state-heading">
        <h2 id="cast-publish-state-heading"><?php echo esc_html('Status'); ?></h2>

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
    </section>

    <?php
    // The guided website choice card for a parked first publish ("sent —
    // waiting for a website"). The status chip and context line up top already
    // name the wait; this card is the actionable choice surface. The server
    // only renders it while the view model reports awaitingWebsite — an
    // ordinary poll never carries it — and the no-refresh orchestrator mirrors
    // that by building the same card into the root placeholder below when a
    // poll catches a park the server had not rendered.
    $awaitingWebsite = $publish->awaitingWebsite;
    ?>
    <div class="cast-website-root" data-cast-website-root>
        <?php if ($awaitingWebsite) : ?>
            <section class="card cast-website-card" aria-labelledby="cast-website-heading">
                <h2 id="cast-website-heading"><?php echo esc_html('Publish to Pinner needs a website.'); ?></h2>

                <p class="cast-website-subline"><?php echo esc_html('A website tells Pinner where to serve your upload. Create one, or link one you already own.'); ?></p>

                <p class="cast-website-cid"><?php echo esc_html('Preserved CID'); ?>: <?php echo esc_html((string) $publish->websiteCid); ?></p>

                <div class="cast-website-path cast-website-path-create">
                    <h3><?php echo esc_html('Create a new website'); ?></h3>
                    <label class="cast-website-hostname-label" for="cast-website-hostname"><?php echo esc_html('Web address (optional)'); ?></label>
                    <input type="text" id="cast-website-hostname" class="cast-website-hostname" autocomplete="off" placeholder="e.g. mysite.com" />
                    <button type="button" class="button button-primary cast-website-create" data-website-action="create"><?php echo esc_html('Create website'); ?></button>
                    <p class="cast-website-create-confirm" hidden><?php echo esc_html('Platform domain will be auto-generated — continue?'); ?></p>
                    <button type="button" class="button cast-website-create-confirm-btn" data-website-action="create-confirm" hidden><?php echo esc_html('Yes, auto-generate'); ?></button>
                </div>

                <div class="cast-website-path cast-website-path-link">
                    <h3><?php echo esc_html('Link a website you already have'); ?></h3>
                    <ul class="cast-website-picker"></ul>
                    <p class="cast-website-link-empty" hidden><?php echo esc_html('No websites available to link. Create one, or handle it in Pinner.'); ?></p>
                </div>

                <p class="cast-website-error notice notice-error" aria-live="polite" hidden></p>
                <p class="cast-website-result" aria-live="polite"></p>
            </section>
        <?php endif; ?>
    </div>

    <section class="card cast-publish-action-card" aria-labelledby="cast-publish-action-heading">
        <h2 id="cast-publish-action-heading"><?php echo esc_html('Publish'); ?></h2>

        <p class="cast-publish-context" id="cast-publish-context"><?php echo esc_html($publish->contextMessage); ?></p>

        <p class="cast-publish-actions">
            <button type="button" class="button button-primary button-hero cast-publish-primary"
                    data-cast-publish-action="<?php echo esc_attr($primaryAction ?? ''); ?>"
                    <?php echo $primaryAction === null ? 'disabled' : ''; ?>
                    aria-describedby="cast-publish-context">
                <?php echo esc_html('Publish to Pinner'); ?>
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

        <?php if ($publish->canChangeMode) : ?>
            <div class="cast-publish-secondary-actions">
                <span class="cast-publish-mode-label" id="cast-publish-mode-label"><?php echo esc_html('Publish trigger'); ?></span>
                <div class="cast-publish-mode-options" role="group" aria-labelledby="cast-publish-mode-label">
                    <?php foreach ($modeOptions as $modeOption) : ?>
                        <?php $modeSelected = $publish->mode === $modeOption['value']; ?>
                        <button type="button"
                                class="cast-publish-mode-option<?php echo $modeSelected ? ' is-active' : ''; ?>"
                                data-cast-publish-action="mode"
                                data-cast-publish-value="<?php echo esc_attr($modeOption['value']); ?>"
                                aria-pressed="<?php echo $modeSelected ? 'true' : 'false'; ?>"
                                <?php echo $modeSelected ? 'disabled' : ''; ?>
                                aria-describedby="cast-publish-mode-help-<?php echo esc_attr($modeOption['value']); ?>">
                            <?php echo esc_html($modeOption['label']); ?>
                        </button>
                        <span class="cast-publish-mode-help-sr" id="cast-publish-mode-help-<?php echo esc_attr($modeOption['value']); ?>"><?php echo esc_html($modeHelp[$modeOption['value']]); ?></span>
                    <?php endforeach; ?>
                </div>
                <p class="cast-publish-mode-help" id="cast-publish-mode-help" aria-live="polite">
                    <?php echo esc_html($activeModeHelp); ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if ($publish->canCancel || $publish->canPublishArtifact) : ?>
            <div class="cast-publish-secondary-actions">
                <?php if ($publish->canCancel) : ?>
                    <button type="button" class="button cast-publish-cancel" data-cast-publish-action="cancel"><?php echo esc_html('Cancel publish'); ?></button>
                <?php endif; ?>
                <?php if ($publish->canPublishArtifact) : ?>
                    <button type="button" class="button cast-publish-republish" data-cast-publish-action="artifact"><?php echo esc_html('Republish the latest build'); ?></button>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($publish->awaitingWebsite) : ?>
            <?php
            // The resume-with-same-CID affordance for a parked first publish:
            // re-points/retries the preserved CID through the existing
            // publishExisting route without re-uploading the pack. Deliberately
            // NOT inside the website card so it stays reachable while awaiting.
            ?>
            <div class="cast-publish-secondary-actions">
                <button type="button" class="button cast-website-resume" data-cast-publish-action="artifact"><?php echo esc_html('Resume with this CID'); ?></button>
            </div>
        <?php endif; ?>

        <div class="cast-publish-secondary-actions cast-publish-refresh-actions">
            <button type="button" class="button cast-publish-refresh" data-cast-publish-refresh><?php echo esc_html('Refresh status'); ?></button>
        </div>
    </section>

    <section class="card cast-publish-readiness" aria-labelledby="cast-publish-readiness-heading">
        <h2 id="cast-publish-readiness-heading"><?php echo esc_html('Readiness'); ?></h2>

        <p class="cast-publish-readiness-level cast-publish-level-<?php echo esc_attr($publish->readiness); ?>">
            <?php echo esc_html($readinessLabel); ?>
        </p>

        <p class="cast-publish-mode"><?php echo esc_html($modeDescription); ?></p>

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
    </section>

    <?php if ($publish->connection instanceof \LumeWeb\Cast\Admin\ConnectionView) : ?>
        <section class="card cast-connection-card" aria-labelledby="cast-connection-heading">
            <h2 id="cast-connection-heading"><?php echo esc_html('Connection'); ?></h2>

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

    <?php $domainPanel = $view['domainView'] ?? null; ?>
    <?php if ($domainPanel instanceof \LumeWeb\Cast\Admin\DomainDashboardView) : ?>
        <section class="card cast-domain-panel" aria-labelledby="cast-domain-heading">
            <h2 id="cast-domain-heading"><?php echo esc_html('Domain'); ?></h2>

            <p class="cast-publish-readiness-level cast-domain-state cast-publish-level-<?php echo esc_attr($domainPanel->stateLevel); ?>">
                <?php echo esc_html($domainPanel->stateLabel); ?>
            </p>

            <?php
            // The list-refusal line only earns its own paragraph when it says
            // something the panel state line has not already said: in the
            // identity-missing state both resolve to "Publish your site to
            // begin managing domains." and echoing it twice is a visible
            // duplicate line, so the refusal is only kept when its label is
            // genuinely distinct (e.g. a reachability error). The refusal
            // still suppresses the empty/list branches below it either way.
            $domainRefusalLabel = $domainPanel->listRefusal['label'] ?? null;
            ?>
            <?php if ($domainPanel->listRefusal !== null) : ?>
                <?php if ($domainRefusalLabel !== $domainPanel->stateLabel) : ?>
                    <p class="cast-domain-list-refusal cast-publish-level-<?php echo esc_attr($domainPanel->listRefusal['level']); ?>">
                        <?php echo esc_html($domainRefusalLabel); ?>
                    </p>
                <?php endif; ?>
            <?php elseif ($domainPanel->domains === []) : ?>
                <p class="cast-domain-list-empty"><?php echo esc_html('No domains bound yet.'); ?></p>
            <?php else : ?>
                <ul class="cast-domain-list">
                    <?php foreach ($domainPanel->domains as $row) : ?>
                        <li class="cast-domain-row">
                            <span class="cast-domain-name"><?php echo esc_html($row['domain']); ?></span>
                            <?php if (isset($row['namespace']) && $row['namespace'] !== '') : ?>
                                <span class="cast-domain-namespace"><?php echo esc_html($row['namespace']); ?></span>
                            <?php endif; ?>
                            <?php if (isset($row['status']) && $row['status'] !== '') : ?>
                                <span class="cast-domain-status"><?php echo esc_html($row['status']); ?></span>
                            <?php endif; ?>
                            <?php if (isset($row['dns_hosting_enabled']) && (bool) $row['dns_hosting_enabled']) : ?>
                                <span class="cast-domain-hosted"><?php echo esc_html('DNS hosted'); ?></span>
                            <?php endif; ?>
                            <?php if (isset($row['gateway_host']) && $row['gateway_host'] !== null && $row['gateway_host'] !== '') : ?>
                                <span class="cast-domain-gateway"><?php echo esc_html($row['gateway_host']); ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($domainPanel->hasWebsite) : ?>
                <h3 class="cast-domain-section"><?php echo esc_html('SSL'); ?></h3>
                <p class="cast-publish-readiness-level cast-domain-ssl-label cast-publish-level-<?php echo esc_attr($domainPanel->sslLevel); ?>">
                    <?php echo esc_html($domainPanel->sslLabel); ?>
                </p>
                <?php if ($domainPanel->ssl !== null && $domainPanel->ssl !== []) : ?>
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

                <h3 class="cast-domain-section"><?php echo esc_html('DNS delegation'); ?></h3>
                <p class="cast-publish-readiness-level cast-domain-dns-label cast-publish-level-<?php echo esc_attr($domainPanel->dnsLevel); ?>">
                    <?php echo esc_html($domainPanel->dnsLabel); ?>
                </p>
                <?php
                // The DNS delegation guidance block. The data is the same
                // JSON-safe serialization the JS renderDomainDns() rebuilds on
                // the "View DNS delegation records" action, so the initial
                // server paint (and no-JS users) see the same managed / HNS /
                // self-managed records the client re-fetch shows. Every value
                // below is a server echo of the delegation/check DTOs — DNSSEC
                // state and per-record checks are rendered verbatim, never
                // derived here. Comments explain intent; this template never
                // computes a record.
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
                }
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
                                <dd><?php echo esc_html($dnsBlock['status']); ?></dd>
                            <?php endif; ?>
                            <?php if (isset($dnsBlock['gateway_host']) && $dnsBlock['gateway_host'] !== null && $dnsBlock['gateway_host'] !== '') : ?>
                                <dt><?php echo esc_html('Gateway'); ?></dt>
                                <dd><?php echo esc_html($dnsBlock['gateway_host']); ?></dd>
                            <?php endif; ?>
                        </dl>

                        <?php if ($dnsDelegation === null) : ?>
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
                                                <tr><td><?php echo esc_html($dnsName); ?></td><td><?php echo esc_html('NS'); ?></td><td><?php echo esc_html((string) $dnsNs); ?></td></tr>
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
                                                <tr><td><?php echo esc_html($dnsType); ?></td><td><?php echo esc_html($dnsSingle); ?></td></tr>
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
                                                <tr><td><?php echo esc_html($dnsType); ?></td><td><?php echo esc_html($dnsSingle); ?></td></tr>
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
                                        <li><?php echo esc_html((string) $dnsNs); ?></li>
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

                        <?php if (!$dnsHosted) : ?>
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
                                        <dd><?php echo esc_html($dnsCheckExpected); ?></dd>
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

            <?php
            $domainActions = [
                'bind' => $domainPanel->canBind,
                'verify' => $domainPanel->canVerify,
                'validate' => $domainPanel->canValidate,
                'delete' => $domainPanel->canDelete,
                'dns' => $domainPanel->canReadDns,
                'ssl' => $domainPanel->canReadSsl,
            ];
            ?>
            <?php if (in_array(true, $domainActions, true)) : ?>
                <h3 class="cast-domain-section"><?php echo esc_html('Actions'); ?></h3>
                <ul class="cast-domain-actions" aria-label="<?php echo esc_attr('Available domain actions'); ?>">
                    <?php if ($domainActions['bind']) : ?>
                        <li class="cast-domain-action cast-domain-action-bind" data-domain-action="bind"><?php echo esc_html('Bind a domain'); ?></li>
                    <?php endif; ?>
                    <?php if ($domainActions['verify']) : ?>
                        <li class="cast-domain-action cast-domain-action-verify" data-domain-action="verify"><?php echo esc_html('Verify DNS delegation'); ?></li>
                    <?php endif; ?>
                    <?php if ($domainActions['validate']) : ?>
                        <li class="cast-domain-action cast-domain-action-validate" data-domain-action="validate"><?php echo esc_html('Validate DNS'); ?></li>
                    <?php endif; ?>
                    <?php if ($domainActions['delete']) : ?>
                        <li class="cast-domain-action cast-domain-action-delete" data-domain-action="delete"><?php echo esc_html('Delete the domain'); ?></li>
                    <?php endif; ?>
                    <?php if ($domainActions['dns']) : ?>
                        <li class="cast-domain-action cast-domain-action-dns" data-domain-action="dns"><?php echo esc_html('View DNS delegation records'); ?></li>
                    <?php endif; ?>
                    <?php if ($domainActions['ssl']) : ?>
                        <li class="cast-domain-action cast-domain-action-ssl" data-domain-action="ssl"><?php echo esc_html('View SSL status'); ?></li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
