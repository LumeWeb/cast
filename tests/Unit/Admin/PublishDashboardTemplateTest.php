<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Admin\ConnectionView;
use LumeWeb\Cast\Admin\DomainDashboardView;
use LumeWeb\Cast\Admin\DomainDnsResult;
use LumeWeb\Cast\Admin\DomainListResult;
use LumeWeb\Cast\Admin\DomainSslResult;
use LumeWeb\Cast\Admin\PublishDashboardView;
use LumeWeb\Cast\Admin\PublishStatus;
use LumeWeb\Cast\Ipfs\CheckInfo;
use LumeWeb\Cast\Ipfs\DelegationInfo;
use LumeWeb\Cast\Publish\Domain;
use LumeWeb\Cast\Admin\ViewRenderer;
use LumeWeb\Cast\Environment\EnvProblem;
use LumeWeb\Cast\Environment\EnvProblemKind;
use LumeWeb\Cast\Environment\PortalIdentity;
use LumeWeb\Cast\Export\RunStage;
use LumeWeb\Cast\Export\RunStatus;
use LumeWeb\Cast\Ipfs\Workspace;
use LumeWeb\Cast\Jobs\PublishIdentity;
use LumeWeb\Cast\Jobs\PublishMode;
use LumeWeb\Cast\Jobs\TickConfig;
use LumeWeb\Cast\Portal\Account;
use LumeWeb\Cast\Portal\SelfIdentification;
use PHPUnit\Framework\TestCase;

/**
 * The publish dashboard template is the escaped output boundary for the
 * Publish admin page: it renders the {@see PublishDashboardView} state — run
 * label, stage, progress, published identity, drift, readiness, environment
 * problems and mode — through the shared {@see ViewRenderer}. Every dynamic
 * value is escaped by the template itself, so a hostile status (a crafted CI
 * D, website name, last error or env problem) can never reach the admin page
 * as executable markup. These tests pin that escaped surface.
 */
final class PublishDashboardTemplateTest extends TestCase
{
    public function testRendersPublishedStateEscapingCidSiteNameAndProgress(): void
    {
        $output = $this->render($this->view([
            'runStatus' => RunStatus::Completed,
            'runStage' => RunStage::Finished,
            'progressCount' => 123,
            'publishCid' => 'QmExample<Cid>',
            'identity' => new PublishIdentity(
                websiteId: 'w-1',
                websiteName: '<Example> & Co',
                ipnsKeyId: 'k-1',
                ipnsKeyName: 'key-one',
                ready: true,
            ),
        ]));

        // The WP-admin shell + menu title render through the shared loader.
        self::assertStringContainsString('cast-publish-wrap', $output);
        self::assertStringContainsString('>Publish<', $output);

        // The run label and stage-derived progress appear escaped.
        self::assertStringContainsString('Published', $output);

        // A finished run maps to 100%; the item count is the separate count.
        self::assertStringContainsString('>100%<', $output);
        self::assertStringContainsString('123 items processed', $output);

        // Hostile identity data the template must escape, never echo raw.
        self::assertStringContainsString('QmExample&lt;Cid&gt;', $output);
        // The connected website renders as a labelled "Website: <name>" line
        // (the clear display the refresh path must always show), escaped too.
        self::assertStringContainsString('Website: &lt;Example&gt; &amp; Co', $output);
        self::assertStringNotContainsString('<Cid>', $output);
        self::assertStringNotContainsString('<Example>', $output);
        self::assertStringNotContainsString('<script>', $output);
    }

    public function testQueuedStateRendersWaitingContextNotAMisleadingItemCount(): void
    {
        $queued = $this->render($this->view([
            'runStatus' => RunStatus::NotStarted,
            'runActive' => true,
            'queuedAt' => 5000,
        ]));

        // A queued (not-started) run never advertises "0 items processed": the
        // honest secondary progress line leads with the tick-cadence ETA and
        // lands on the waiting copy.
        $cadence = TickConfig::DEFAULT_TICK_INTERVAL_SECONDS;
        self::assertStringContainsString('Starting within ' . $cadence . ' seconds — waiting to begin', $queued);
        self::assertStringContainsString('waiting to begin', $queued);
        self::assertStringNotContainsString('0 items processed', $queued);

        // The queued ETA anchor the client countdown reconciles against:
        // queuedAt + the tick delivery cadence, rendered as a data attribute
        // on the progress line (escaped).
        self::assertStringContainsString('data-cast-queued-eta="' . (5000 + $cadence) . '"', $queued);

        // The plain-language queue chip and expected-behavior copy render.
        self::assertStringContainsString('Queued — starting shortly', $queued);
        self::assertStringContainsString(
            'Your publish is queued and will start automatically. Keep working — it runs in the background and this page tracks the progress.',
            $queued,
        );

        // The recovery/start-now escape is rendered (hidden until the client's
        // wait threshold passes) and funnels through the existing first-publish
        // start route while no identity exists.
        self::assertStringContainsString('data-cast-escape', $queued);
        self::assertStringContainsString('Start now', $queued);
        self::assertStringContainsString('data-cast-publish-action="start"', $queued);
        self::assertStringContainsString('hidden', $queued);

        // The queued-run trio, exactly as the view model pins it: Cancel
        // publish is offered while the run is active, and the prominent Publish
        // button is disabled (empty action) — the escape is the only kick.
        self::assertStringContainsString('Cancel publish', $queued);
        self::assertMatchesRegularExpression(
            '/class="[^"]*cast-publish-primary[^"]*"[^>]*data-cast-publish-action=""[^>]*disabled/',
            $queued,
            'a queued run must render the primary disabled with an empty action',
        );
    }

    public function testRunningExportNamesTheStageWithTheDiscoverTotal(): void
    {
        $output = $this->render($this->view([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'runActive' => true,
            'progressCount' => 42,
            'pipelineStage' => 'capture',
            'progressTotal' => 100,
        ]));

        // A live run names its fine stage with the honest discover denominator —
        // never the misleading cumulative "items processed" that counts a URL
        // once per pipeline pass.
        self::assertStringContainsString('Capturing content… (100 URLs)', $output);
        self::assertStringNotContainsString('42 items processed', $output);
        self::assertStringNotContainsString('Waiting to begin', $output);
        // The discover total is rendered as a data hook for the client/tests.
        self::assertStringContainsString('data-cast-progress-total="100"', $output);
        // No queued run means no escape affordance at all.
        self::assertStringNotContainsString('cast-publish-escape', $output);
        self::assertStringNotContainsString('Start now', $output);
    }

    public function testNoDiscoverTotalRendersNoProgressTotalHook(): void
    {
        // Before discovery drains (or for an idle surface) there is no
        // denominator, so the data hook must be absent rather than lie.
        $idle = $this->render($this->view([
            'runStatus' => RunStatus::NotStarted,
            'runStage' => RunStage::Idle,
        ]));
        self::assertStringNotContainsString('data-cast-progress-total', $idle);

        $runningNoTotal = $this->render($this->view([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'runActive' => true,
            'pipelineStage' => 'discover',
        ]));
        self::assertStringNotContainsString('data-cast-progress-total', $runningNoTotal);
        self::assertStringContainsString('Discovering content…', $runningNoTotal);
    }

    public function testTerminalCancelledFirstPublishHidesEscapeAndCancelAndEnablesPrimaryRetry(): void
    {
        $output = $this->render($this->view([
            'runStatus' => RunStatus::Cancelled,
            'runStage' => RunStage::Finished,
            'runActive' => false,
            'identity' => null,
        ]));

        // The status + "why" copy name the cancelled retry honestly.
        self::assertStringContainsString('Cancelled — retry available', $output);
        self::assertStringContainsString('Your last publish was cancelled. Publish to Pinner to start again.', $output);

        // The queued recovery/start-now escape and its line are NEVER offered
        // for a terminal run — the escape only makes sense while a run queues.
        self::assertStringNotContainsString('cast-publish-escape', $output);
        self::assertStringNotContainsString('Start now', $output);
        self::assertStringNotContainsString('Queued longer than expected', $output);

        // Nothing is active or queued, so Cancel publish is not offered either.
        self::assertStringNotContainsString('cast-publish-cancel', $output);
        self::assertStringNotContainsString('Cancel publish', $output);

        // The prominent action is the re-armed first-publish retry, enabled.
        self::assertMatchesRegularExpression(
            '/class="[^"]*cast-publish-primary[^"]*"[^>]*data-cast-publish-action="start"[^>]*>/',
            $output,
            'a terminal cancelled first publish must re-arm the primary start retry',
        );
        self::assertDoesNotMatchRegularExpression(
            '/class="[^"]*cast-publish-primary[^"]*"[^>]*disabled[^>]*>/',
            $output,
            'a terminal retry must not leave the primary disabled',
        );
    }

    public function testCancelButtonRendersOnlyWhileRunIsActive(): void
    {
        $cases = [
            'idle' => ['runStatus' => RunStatus::NotStarted, 'runActive' => false],
            'queued' => ['runStatus' => RunStatus::NotStarted, 'runActive' => true],
            'running' => ['runStatus' => RunStatus::Running, 'runStage' => RunStage::Exporting, 'runActive' => true],
            'paused' => ['runStatus' => RunStatus::Paused, 'runActive' => true],
            'completed' => ['runStatus' => RunStatus::Completed, 'runStage' => RunStage::Finished, 'runActive' => false],
            'failed' => ['runStatus' => RunStatus::Failed, 'runStage' => RunStage::Finished, 'runActive' => false],
            'cancelled' => ['runStatus' => RunStatus::Cancelled, 'runStage' => RunStage::Finished, 'runActive' => false],
        ];

        foreach ($cases as $label => $overrides) {
            $output = $this->render($this->view($overrides));

            if (\in_array($label, ['queued', 'running', 'paused'], true)) {
                self::assertStringContainsString(
                    'Cancel publish',
                    $output,
                    \sprintf('Cancel must render while the run is %s', $label),
                );
            } else {
                self::assertStringNotContainsString(
                    'Cancel publish',
                    $output,
                    \sprintf('Cancel must never render for the %s state', $label),
                );
            }
        }
    }

    public function testTerminalStatesNeverRenderTheEscapeLine(): void
    {
        $terminal = [
            RunStatus::Completed,
            RunStatus::CompletedWithWarnings,
            RunStatus::Failed,
            RunStatus::Cancelled,
        ];

        foreach ($terminal as $runStatus) {
            $output = $this->render($this->view([
                'runStatus' => $runStatus,
                'runStage' => RunStage::Finished,
                'runActive' => false,
            ]));

            self::assertStringNotContainsString('cast-publish-escape', $output);
            self::assertStringNotContainsString('Queued longer than expected', $output);
            self::assertStringNotContainsString('Cancel publish', $output);
        }
    }

    public function testRendersConfigurationProblemsEscaped(): void
    {
        $output = $this->render($this->view([
            'bootstrapIdentityComplete' => false,
            'envProblems' => [
                new EnvProblem('PORTAL_API_URL', EnvProblemKind::Missing),
                new EnvProblem('MALICIOUS<VAR>', EnvProblemKind::Empty),
            ],
            'lastError' => 'Failed to reach <Pinner> & upload',
        ]));

        // The operator-facing readiness card names the variables and message.
        self::assertStringContainsString('Configuration required', $output);
        self::assertStringContainsString('PORTAL_API_URL', $output);
        self::assertStringContainsString('MALICIOUS&lt;VAR&gt;', $output);

        // The actionable last error is escaped, never echoed raw.
        self::assertStringContainsString('Failed to reach &lt;Pinner&gt; &amp; upload', $output);
        self::assertStringNotContainsString('<Pinner>', $output);
        self::assertStringNotContainsString('<script>', $output);

        // A missing environment never offers a publish action as markup.
        self::assertStringNotContainsString('<form', $output);
    }

    public function testRendersModeLabelsForEachAvailableMode(): void
    {
        self::assertStringContainsString(
            'Manual publishing',
            $this->render($this->view(['mode' => PublishMode::Manual])),
        );
        self::assertStringContainsString(
            'Publishes automatically when you update content',
            $this->render($this->view(['mode' => PublishMode::OnUpdate])),
        );
    }

    public function testActiveModeButtonIsSelectedNonActionableWithPerOptionHelp(): void
    {
        $output = $this->render($this->view(['mode' => PublishMode::Manual]));

        // The active option is pressed + disabled — visibly selected and
        // non-actionable — and every option carries its own describedby help.
        self::assertMatchesRegularExpression(
            '/data-cast-publish-value="manual"[^>]*aria-pressed="true"[^>]*disabled[^>]*>/',
            $output,
            'the active (manual) option must be pressed and disabled',
        );
        self::assertMatchesRegularExpression(
            '/data-cast-publish-value="on_update"[^>]*aria-pressed="false"/',
            $output,
        );
        self::assertStringContainsString('aria-describedby="cast-publish-mode-help-manual"', $output);
        self::assertStringContainsString('aria-describedby="cast-publish-mode-help-on_update"', $output);

        // The active mode's full end-user help renders as the visible guidance
        // line, and per-option help spans carry the exact scheduler semantics:
        // a click queues a background publish (never synchronous), and eligible
        // content changes queue a debounced/coalesced background publish.
        self::assertStringContainsString(
            'Publish only when you choose. Clicking Publish to Pinner queues a background publish — content edits wait until then and nothing publishes on its own.',
            $output,
        );
        self::assertStringContainsString(
            'Publish automatically. Eligible content changes queue a background publish for you — rapid edits are combined into one debounced publish instead of one per save.',
            $output,
        );
        // The retired Scheduled mode is not offered anywhere in the selector.
        self::assertStringNotContainsString('scheduled', $output);
    }

    public function testRendersExplicitStateChipLastCheckedAndRefreshControl(): void
    {
        $queued = $this->render($this->view([
            'runStatus' => RunStatus::NotStarted,
            'runActive' => true,
        ]));

        // The explicit queue/run state chip leads the status card, carries the
        // run-state class for styling and is an accessible live status
        // (role=status) so screen readers hear each transition.
        self::assertMatchesRegularExpression(
            '/class="cast-publish-state-chip cast-publish-state-queued"[^>]*role="status"[^>]*>/',
            $queued,
            'the queued state must render as an accessible chip',
        );
        self::assertStringContainsString('Queued — starting shortly', $queued);
        self::assertStringNotContainsString('Publishing', $queued);

        // A live data-refresh timestamp line is always present after the error
        // block, labelled accessibly.
        self::assertStringContainsString('cast-publish-last-checked', $queued);
        self::assertStringContainsString('Last checked', $queued);

        // The manual Refresh control is always rendered and is a read-only
        // client control (data-cast-publish-refresh, never a REST action).
        self::assertStringContainsString('Refresh status', $queued);
        self::assertMatchesRegularExpression(
            '/class="[^"]*cast-publish-refresh[^"]*"[^>]*data-cast-publish-refresh/',
            $queued,
        );
        self::assertStringNotContainsString('data-cast-publish-action="refresh"', $queued);

        // A failed terminal run names the retry honestly on the chip.
        $failed = $this->render($this->view([
            'runStatus' => RunStatus::Failed,
            'runStage' => RunStage::Finished,
        ]));
        self::assertStringContainsString('Failed — retry available', $failed);
        self::assertMatchesRegularExpression(
            '/class="cast-publish-state-chip cast-publish-state-failed"/',
            $failed,
        );

        // A completed run names the success honestly on the chip.
        $completed = $this->render($this->view([
            'runStatus' => RunStatus::Completed,
            'runStage' => RunStage::Finished,
        ]));
        self::assertStringContainsString('Published', $completed);
        self::assertMatchesRegularExpression(
            '/class="cast-publish-state-chip cast-publish-state-completed"/',
            $completed,
        );
    }

    public function testModeSelectorIsHiddenWhenModeCannotChange(): void
    {
        // Mid-run the mode group (and its help) is not rendered at all, so no
        // active-mode control is offered while the mode is locked.
        $output = $this->render($this->view([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'runActive' => true,
        ]));

        self::assertStringNotContainsString('cast-publish-mode-options', $output);
        self::assertStringNotContainsString('aria-pressed=', $output);
    }

    public function testRendersNoContentReadiness(): void
    {
        $output = $this->render($this->view(['hasEligibleContent' => false]));

        self::assertStringContainsString('No publishable content yet', $output);
    }

    public function testRendersResolvedConnectionCardEscaped(): void
    {
        $output = $this->render($this->view([
            'connection' => $this->resolvedConnection('Ada Lovelace', 'ada<@>example.test', 'main', 'main.example.test'),
        ]));

        self::assertStringContainsString('cast-connection-card', $output);
        self::assertStringContainsString('>Connection<', $output);
        self::assertStringContainsString('Connected', $output);
        // Identifiers escape hostile characters; never echoed raw.
        self::assertStringContainsString('ada&lt;@&gt;example.test', $output);
        self::assertStringNotContainsString('ada<@>example.test', $output);
        self::assertStringContainsString('main.example.test', $output);
        self::assertStringContainsString('Ada Lovelace', $output);
        self::assertStringNotContainsString('<script>', $output);
    }

    public function testRendersConnectionErrorStateEscapedWithoutCredentials(): void
    {
        $output = $this->render($this->view([
            'connection' => $this->errorConnection(),
        ]));

        // The value-free operator message renders escaped; the account key or
        // any credential never reaches the page.
        self::assertStringContainsString('cast-connection-card', $output);
        self::assertStringNotContainsString('Connected', $output);
        self::assertStringContainsString('cast-connection-error', $output);
        self::assertStringNotContainsString('account-key', $output);
        self::assertStringNotContainsString('<script>', $output);
    }

    public function testRendersNoConnectionCardWhenConnectionUnresolvedOrAbsent(): void
    {
        $output = $this->render($this->view());

        self::assertStringNotContainsString('cast-connection-card', $output);
    }

    public function testRendersDomainPanelMarkerWhenProvidedDomainDashboardView(): void
    {
        $domainView = DomainDashboardView::fromState(
            envComplete: true,
            onboardingComplete: true,
            hasWebsite: true,
            selected: false,
            list: new DomainListResult(true, null, []),
        );

        $output = $this->render($this->view(), $domainView);

        // As soon as the subscriber hands the choose-a-domain panel its
        // DomainDashboardView, the publish template must render the panel.
        self::assertStringContainsString('cast-domain-panel', $output);
    }

    public function testRendersHonestRetryCopyWithEnabledPrimaryForFailedFirstPublish(): void
    {
        $output = $this->render($this->view([
            'runStatus' => RunStatus::Failed,
            'runStage' => RunStage::Finished,
            'lastError' => 'The upload timed out',
        ]));

        // The status names the failure and the context makes the retry honest.
        self::assertStringContainsString('Publish failed', $output);
        self::assertStringContainsString('Your last publish failed. Publish to Pinner to retry.', $output);
        self::assertStringNotContainsString('publish it to Pinner for the first time', $output);

        // The prominent action is the restarted first publish (start), enabled.
        self::assertMatchesRegularExpression(
            '/class="[^"]*cast-publish-primary[^"]*"[^>]*data-cast-publish-action="start"[^>]*>/',
            $output,
            'the primary action must be the start retry',
        );
        self::assertDoesNotMatchRegularExpression(
            '/class="[^"]*cast-publish-primary[^"]*"[^>]*disabled[^>]*>/',
            $output,
            'a terminal retry must not leave the primary disabled',
        );
    }

    public function testRendersDisabledPrimaryWhenRetryEnvironmentUnavailable(): void
    {
        $output = $this->render($this->view([
            'bootstrapIdentityComplete' => false,
            'envProblems' => [new EnvProblem('PORTAL_API_URL', EnvProblemKind::Missing)],
            'runStatus' => RunStatus::Failed,
            'runStage' => RunStage::Finished,
        ]));

        // Config readiness disables the primary (data action empty + disabled),
        // and the context explains why rather than offering a retry.
        self::assertStringContainsString('data-cast-publish-action=""', $output);
        self::assertMatchesRegularExpression(
            '/class="[^"]*cast-publish-primary[^"]*"[^>]*disabled[^>]*>/',
            $output,
            'an unavailable environment must leave the primary disabled',
        );
        self::assertStringContainsString('Publishing is unavailable until the configuration below is resolved.', $output);
    }

    public function testHidesSslAndDnsSectionsWhenNoWebsiteIdentityExists(): void
    {
        $domainView = DomainDashboardView::fromState(
            envComplete: true,
            onboardingComplete: true,
            hasWebsite: false,
            selected: false,
            list: new DomainListResult(false, \LumeWeb\Cast\Admin\DomainRefusal::IdentityMissing),
        );

        $output = $this->render($this->view(), $domainView);

        // The panel explores the publish-first next step, and the SSL/DNS
        // sections are NOT rendered — no implied selectable domain.
        self::assertStringContainsString('Publish your site to begin managing domains.', $output);
        // The publish-first message appears exactly ONCE: the identity-missing
        // list refusal resolves to the same string as the panel state line, so
        // the template must not echo the duplicate paragraph.
        self::assertSame(1, substr_count($output, 'Publish your site to begin managing domains.'));
        self::assertStringNotContainsString('cast-domain-ssl-label', $output);
        self::assertStringNotContainsString('cast-domain-dns-label', $output);
        self::assertStringNotContainsString('Select a domain to view SSL status', $output);
    }

    public function testKeepsTheListRefusalParagraphWhenItDiffersFromTheStateLabel(): void
    {
        // A refusal that says something the state line has not already said
        // (here the reachability error behind READY state) must KEEP its own
        // paragraph — the dedupe only drops an identical echo.
        $domainView = DomainDashboardView::fromState(
            envComplete: true,
            onboardingComplete: true,
            hasWebsite: true,
            selected: false,
            list: new DomainListResult(false, \LumeWeb\Cast\Admin\DomainRefusal::RequestFailed),
        );

        $output = $this->render($this->view(), $domainView);

        self::assertStringContainsString('Choose and manage your domain', $output);
        self::assertStringContainsString('Pinner could not be reached. Please try again.', $output);
        self::assertStringContainsString('cast-domain-list-refusal', $output);
        // The distinct refusal is a different string from the state line, so no
        // identical duplicate is rendered.
        self::assertSame(1, substr_count($output, 'Pinner could not be reached. Please try again.'));
    }

    public function testRendersSslAndDnsSectionsWhenWebsiteIdentityExists(): void
    {
        $domainView = DomainDashboardView::fromState(
            envComplete: true,
            onboardingComplete: true,
            hasWebsite: true,
            selected: false,
            list: new DomainListResult(true, null, []),
        );

        $output = $this->render($this->view(), $domainView);

        // A registered website keeps its SSL/DNS sections (with bind-first
        // guidance when no domain is bound yet), so valid controls stay live.
        self::assertStringContainsString('cast-domain-ssl-label', $output);
        self::assertStringContainsString('cast-domain-dns-label', $output);
        self::assertStringContainsString('Bind a domain to view SSL status', $output);
    }

    /* ------------------------- DNS delegation guidance --------------------- */

    public function testRendersManagedIcannDnsGuidanceWithNameTypeValueTable(): void
    {
        // Managed ICANN: point the registrar at Pinner's nameserver NAME/TYPE/
        // VALUE table, then publish the parent records; the authoritative side
        // is handled for the operator.
        $domain = new Domain(
            '9',
            'site.example.test',
            'icann',
            true,
            'waiting_delegation',
            'gw.example.com',
            DelegationInfo::fromArray([
                'mode' => null,
                'nameservers' => ['ns1.pinner.xyz', 'ns2.pinner.xyz'],
                'dnssec' => 'secure',
                'dnssec_error' => null,
                'parent_records' => [
                    ['type' => 'NS', 'value' => 'ns1.pinner.xyz,ns2.pinner.xyz'],
                    ['type' => 'DS', 'value' => '12345 8 2 ABCDEF'],
                ],
                'authoritative_records' => [],
            ]),
        );

        $output = $this->render($this->view(), $this->dnsDomainView($domain));

        // The managed ICANN guidance copy lands verbatim (apostrophes escaped).
        self::assertStringContainsString('DNS delegation', $output);
        self::assertStringContainsString('Update your domain&#039;s nameservers at your registrar.', $output);
        self::assertStringContainsString('Point your registrar&#039;s nameservers to the records below.', $output);
        self::assertStringContainsString('Pinner manages your DNS, so the authoritative side is handled for you.', $output);
        self::assertStringContainsString('Parent records (configure at your registrar)', $output);

        // The NAME/TYPE/VALUE nameserver table with each Pinner nameserver.
        self::assertStringContainsString('>Name<', $output);
        self::assertStringContainsString('>Type<', $output);
        self::assertStringContainsString('>Value<', $output);
        self::assertStringContainsString('>ns1.pinner.xyz<', $output);
        self::assertStringContainsString('>ns2.pinner.xyz<', $output);
        // The parent records DS value renders verbatim.
        self::assertStringContainsString('>12345 8 2 ABCDEF<', $output);
        // DNSSEC state renders verbatim, never computed.
        self::assertStringContainsString('DNSSEC: secure', $output);
        self::assertStringNotContainsString('DNSSEC error', $output);
        // Managed DNS never asks the operator to add/validate the records.
        self::assertStringNotContainsString('Add the DNS records shown above at your registrar, then validate.', $output);
    }

    public function testRendersManagedHnsDnsGuidanceOnChainWithNameserversAndDnssecError(): void
    {
        // Managed HNS (inline): the records live on-chain in the HNS wallet and
        // the authoritative side is served via Pinner's synthetic nameservers.
        $domain = new Domain(
            '9',
            'name/',
            'hns',
            true,
            'waiting_delegation',
            'gw.example.com',
            DelegationInfo::fromArray([
                'mode' => 'inline',
                'nameservers' => ['ns1.pinner.xyz', 'ns2.pinner.xyz'],
                'dnssec' => 'secure',
                'dnssec_error' => 'dnssec-broken',
                'parent_records' => [
                    ['type' => 'NS', 'value' => 'ns1.pinner.xyz,ns2.pinner.xyz'],
                    ['type' => 'DS', 'value' => '12345 8 2 ABCDEF'],
                ],
                'authoritative_records' => [],
            ]),
        );

        $output = $this->render($this->view(), $this->dnsDomainView($domain));

        self::assertStringContainsString('DNS delegation', $output);
        self::assertStringContainsString(
            'Publish the records below in the DNS/records area of your HNS wallet (on-chain).',
            $output,
        );
        self::assertStringContainsString('The authoritative side is served via Pinner&#039;s synthetic nameservers.', $output);
        self::assertStringContainsString('Parent records (publish in your HNS wallet)', $output);

        // Comma-joined nameserver values split onto their own rows.
        self::assertStringContainsString('>ns1.pinner.xyz<', $output);
        self::assertStringContainsString('>ns2.pinner.xyz<', $output);

        // The HNS nameservers list renders (the NAME/TYPE/VALUE table was not).
        self::assertStringContainsString('Nameservers', $output);
        // DNSSEC state AND error render verbatim, never computed.
        self::assertStringContainsString('DNSSEC: secure', $output);
        self::assertStringContainsString('DNSSEC error: dnssec-broken', $output);
    }

    public function testRendersSelfManagedDnsGuidanceWithChecksRows(): void
    {
        // Self-managed: the operator configures the records at the registrar,
        // points their own DNS server at the authoritative records, then
        // validates. The per-record values are the server-computed checks.
        $domain = new Domain(
            '9',
            'site.example.test',
            'icann',
            false,
            'waiting_delegation',
            'gw.example.com',
            DelegationInfo::fromArray([
                'mode' => null,
                'nameservers' => [],
                'dnssec' => '',
                'dnssec_error' => null,
                'parent_records' => [],
                'authoritative_records' => [
                    ['type' => 'TXT', 'value' => 'pinner-verify=AbCdEf123456', 'ns' => null],
                ],
            ]),
            [
                CheckInfo::fromArray([
                    'name' => 'pinner-verify',
                    'ok' => false,
                    'message' => '',
                    'expected' => 'pinner-verify=AbCdEf123456',
                    'found' => '',
                ]),
                CheckInfo::fromArray([
                    'name' => 'dnslink',
                    'ok' => false,
                    'message' => '',
                    'expected' => 'dnslink=/ipns/k-ipns-7',
                    'found' => 'dnslink=/ipfs/QmWrong',
                ]),
            ],
        );

        $output = $this->render($this->view(), $this->dnsDomainView($domain));

        self::assertStringContainsString('Configure the parent records at your registrar, then point your DNS server at the authoritative records below.', $output);
        self::assertStringContainsString('Authoritative records (configure on your DNS server)', $output);
        self::assertStringContainsString('>pinner-verify=AbCdEf123456<', $output);
        self::assertStringContainsString('Add the DNS records shown above at your registrar, then validate.', $output);

        // The server-computed per-record checks with the exact publish/found rows.
        self::assertStringContainsString('Validation checks', $output);
        self::assertStringContainsString('Publish this record:', $output);
        self::assertStringContainsString('>pinner-verify=AbCdEf123456<', $output);
        self::assertStringContainsString('Found instead:', $output);
        self::assertStringContainsString('>dnslink=/ipns/k-ipns-7<', $output);
        self::assertStringContainsString('>dnslink=/ipfs/QmWrong<', $output);
    }

    public function testRendersNoDelegationMessageWhenTheBundleIsAbsent(): void
    {
        // A bound domain without a delegation block yet (the portal has not
        // produced records) says so plainly instead of implying records exist.
        $domain = new Domain('9', 'name/', 'hns', true, 'waiting_delegation', 'gw.example.com');

        $output = $this->render($this->view(), $this->dnsDomainView($domain));

        self::assertStringContainsString('No delegation records are available for name/.', $output);
        self::assertStringContainsString('Publish the delegation records below', $output);
    }

    public function testRendersTheValidateDnsActionMarker(): void
    {
        $domainView = $this->dnsDomainView(new Domain('9', 'site.example.test', 'icann', true, 'waiting_delegation'));

        $output = $this->render($this->view(), $domainView);

        self::assertStringContainsString('data-domain-action="validate"', $output);
        self::assertStringContainsString('Validate DNS', $output);
    }

    public function testRendersGuidedWebsiteCardWhileAwaitingWithTwoPaths(): void
    {
        // A parked first publish awaiting a website renders the guided choice
        // card server-side: exactly the two paths (create/link), the preserved
        // CID to match in Pinner, and no fabricated progress anywhere.
        $output = $this->render($this->view([
            'runStatus' => RunStatus::Paused,
            'runActive' => true,
            'awaitingWebsite' => true,
            'awaitingCid' => 'QmParkedCid',
        ]));

        // The root placeholder always exists; the card itself only while
        // awaiting, with exactly one heading (dup-header rule: no second echo
        // of "needs a website" beyond the card heading).
        self::assertStringContainsString('data-cast-website-root', $output);
        self::assertStringContainsString('class="card cast-website-card"', $output);
        self::assertSame(1, substr_count($output, 'Publish to Pinner needs a website.'));

        // The card copy, pinned verbatim from the design-doc S1 strings.
        self::assertStringContainsString('A website tells Pinner where to serve your upload. Create one, or link one you already own.', $output);
        self::assertStringContainsString('Preserved CID: QmParkedCid', $output);

        // Path (a) create + the explicit auto-generate confirmation.
        self::assertStringContainsString('Create a new website', $output);
        self::assertStringContainsString('Web address (optional)', $output);
        self::assertStringContainsString('placeholder="e.g. mysite.com"', $output);
        self::assertStringContainsString('data-website-action="create"', $output);
        self::assertStringContainsString('Platform domain will be auto-generated — continue?', $output);
        self::assertStringContainsString('data-website-action="create-confirm"', $output);
        // The empty-hostname confirm is hidden by default in the rendered
        // template — the orchestrator only reveals it when the operator clicks
        // Create website with no hostname. (The CSS re-pins the attribute so
        // WP core's .button display rule cannot override it.)
        self::assertStringContainsString('class="cast-website-create-confirm" hidden', $output);
        self::assertStringContainsString('class="button cast-website-create-confirm-btn" data-website-action="create-confirm" hidden', $output);

        // Path (b) link.
        self::assertStringContainsString('Link a website you already have', $output);
        self::assertStringContainsString('No websites available to link. Create one, or handle it in Pinner.', $output);

        // Exactly two paths, no more: the manual "handle it in Pinner / Dismiss"
        // path is gone by design, so its marker and copy must never render.
        self::assertSame(2, substr_count($output, 'class="cast-website-path'));
        self::assertStringNotContainsString('cast-website-path-manual', $output);
        self::assertStringNotContainsString('cast-website-manual-dismiss', $output);
        self::assertStringNotContainsString('data-website-action="dismiss"', $output);
        self::assertStringNotContainsString('Dismiss', $output);

        // The parked state carries no fabricated progress: the bar and the
        // percentage value are omitted while awaiting, and the only progress
        // line names the wait.
        self::assertStringNotContainsString('cast-publish-progress-track', $output);
        self::assertStringNotContainsString('cast-publish-progress-value', $output);
        self::assertStringContainsString('Waiting for a website — the run is paused.', $output);

        // The chip reads as deliberately sent (exactly once) and the context
        // leads with the parked wait copy.
        self::assertSame(1, substr_count($output, 'Sent — waiting for a website'));
        self::assertStringContainsString('Your upload was sent. The run is waiting for a website — open Pinner and create one or attach one to this workspace, then come back.', $output);

        // The resume-with-same-CID affordance lives in the action card, NOT
        // inside the website card: it appears once, after the website card's
        // closing tag.
        self::assertSame(1, substr_count($output, 'Resume with this CID'));
        $websiteCardOpen = strpos($output, 'class="card cast-website-card"');
        self::assertNotFalse($websiteCardOpen, 'the website card opens');
        $websiteCardClose = strpos($output, '</section>', $websiteCardOpen);
        self::assertNotFalse($websiteCardClose, 'the website card closes');
        self::assertStringNotContainsString('Resume with this CID', substr($output, 0, $websiteCardClose));

        // A parked run has no prominent publish action: the primary button
        // renders disabled.
        self::assertStringContainsString('disabled', $output);
    }

    public function testOmitsWebsiteCardAndResumeWhenNotAwaiting(): void
    {
        $output = $this->render($this->view([
            'runStatus' => RunStatus::Paused,
            'runActive' => true,
        ]));

        // A plain paused run (not awaiting a website) renders no website card,
        // no awaiting copy and no resume affordance.
        self::assertStringNotContainsString('cast-website-card', $output);
        self::assertStringNotContainsString('Publish to Pinner needs a website.', $output);
        self::assertStringNotContainsString('Resume with this CID', $output);
        self::assertStringNotContainsString('Sent — waiting for a website', $output);
        self::assertStringContainsString('Paused', $output);
        // The regular progress bar is back for a non-awaiting paused run.
        self::assertStringContainsString('cast-publish-progress-track', $output);
    }

    public function testEscapesThePreservedCidInTheWebsiteCard(): void
    {
        // The preserved CID is a server-supplied string; it must be escaped in
        // the website card just like the main CID line.
        $output = $this->render($this->view([
            'runStatus' => RunStatus::Paused,
            'runActive' => true,
            'awaitingWebsite' => true,
            'awaitingCid' => 'Qm<Parked>&Co',
        ]));

        self::assertStringContainsString('Preserved CID: Qm&lt;Parked&gt;&amp;Co', $output);
        self::assertStringNotContainsString('Qm<Parked>&Co', $output);
    }

    /**
     * Render the production publish template through the same public boundary
     * the subscriber uses (the default-path ViewRenderer) with the exact view
     * keys PublishAdminSubscriber::render() provides.
     */
    /**
     * A ready domain panel with one bound (and selected) domain and its DNS
     * delegation result, so the DNS guidance block renders server-side.
     */
    private function dnsDomainView(Domain $domain): DomainDashboardView
    {
        return DomainDashboardView::fromState(
            envComplete: true,
            onboardingComplete: true,
            hasWebsite: true,
            selected: true,
            list: new DomainListResult(true, null, [$domain]),
            ssl: new DomainSslResult(true, null, null),
            dns: new DomainDnsResult(true, null, $domain),
        );
    }

    private function render(PublishDashboardView $view, ?DomainDashboardView $domainView = null): string
    {
        $renderer = new ViewRenderer();

        ob_start();
        $renderer->render('publish.php', [
            'menuTitle' => 'Publish',
            'view' => $view,
            'domainView' => $domainView,
        ]);

        return (string) ob_get_clean();
    }

    private function resolvedConnection(string $name, string $email, string $label, string $domain): ConnectionView
    {
        $identity = new PortalIdentity('https://portal.example.test', 'account-key');
        $account = Account::fromArray([
            'id' => 7,
            'email' => $email,
            'first_name' => $name,
            'last_name' => '',
            'verified' => true,
        ]);
        $workspace = Workspace::fromArray([
            'id' => 11,
            'label' => $label,
            'domain' => $domain,
            'status' => 'active',
            'created' => '2026-01-01T00:00:00Z',
            'updated' => '2026-01-02T00:00:00Z',
        ]);

        return ConnectionView::fromSelfIdentification(SelfIdentification::resolved($identity, $account, $workspace));
    }

    private function errorConnection(): ConnectionView
    {
        $identity = new PortalIdentity('https://portal.example.test', 'account-key');

        return ConnectionView::fromSelfIdentification(SelfIdentification::resolveRejected($identity, Account::fromArray([
            'id' => 7,
            'email' => 'ada@example.test',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'verified' => true,
        ])));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function view(array $overrides = []): PublishDashboardView
    {
        return PublishDashboardView::fromStatus($this->makeStatus($overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeStatus(array $overrides = []): PublishStatus
    {
        $defaults = [
            'bootstrapIdentityComplete' => true,
            'envProblems' => [],
            'onboardingComplete' => true,
            'hasEligibleContent' => true,
            'mode' => PublishMode::Manual,
            'autoActive' => false,
            'runStatus' => RunStatus::NotStarted,
            'runStage' => RunStage::Idle,
            'progressCount' => 0,
            'pipelineStage' => null,
            'progressTotal' => null,
            'captureDone' => null,
            'rewriteDone' => null,
            'queuedAt' => null,
            'runActive' => false,
            'dirty' => false,
            'superseded' => false,
            'publishCid' => null,
            'lastError' => null,
            'identity' => null,
            'connection' => null,
            'awaitingWebsite' => false,
            'awaitingCid' => null,
        ];
        $merged = array_replace($defaults, $overrides);

        $connection = $merged['connection'];
        if ($connection !== null && !$connection instanceof ConnectionView) {
            throw new \InvalidArgumentException('connection override must be a ConnectionView or null.');
        }

        return new PublishStatus(
            bootstrapIdentityComplete: (bool) $merged['bootstrapIdentityComplete'],
            envProblems: $merged['envProblems'],
            onboardingComplete: (bool) $merged['onboardingComplete'],
            hasEligibleContent: (bool) $merged['hasEligibleContent'],
            mode: $merged['mode'],
            autoActive: (bool) $merged['autoActive'],
            runStatus: $merged['runStatus'],
            runStage: $merged['runStage'],
            progressCount: (int) $merged['progressCount'],
            pipelineStage: $merged['pipelineStage'],
            progressTotal: $merged['progressTotal'] === null ? null : (int) $merged['progressTotal'],
            captureDone: $merged['captureDone'] === null ? null : (int) $merged['captureDone'],
            rewriteDone: $merged['rewriteDone'] === null ? null : (int) $merged['rewriteDone'],
            queuedAt: $merged['queuedAt'] === null ? null : (int) $merged['queuedAt'],
            runActive: (bool) $merged['runActive'],
            dirty: (bool) $merged['dirty'],
            superseded: (bool) $merged['superseded'],
            publishCid: $merged['publishCid'],
            lastError: $merged['lastError'],
            identity: $merged['identity'],
            connection: $connection,
            awaitingWebsite: (bool) $merged['awaitingWebsite'],
            awaitingCid: $merged['awaitingCid'] === null ? null : (string) $merged['awaitingCid'],
        );
    }
}
