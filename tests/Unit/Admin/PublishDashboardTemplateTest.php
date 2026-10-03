<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Admin\ConnectionView;
use LumeWeb\Cast\Admin\DomainDashboardView;
use LumeWeb\Cast\Admin\DomainDnsResult;
use LumeWeb\Cast\Admin\DomainListResult;
use LumeWeb\Cast\Admin\DomainSslResult;
use LumeWeb\Cast\Admin\PublishDashboardView;
use LumeWeb\Cast\Admin\PublishDestinationView;
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
 * The publish admin page is the escaped output boundary for the approved
 * publishing-destination layout (plans/publishing-destination-redesign.md):
 *
 *   1. Your site address  — the durable address summary, or the "choose an
 *      address" prompt + the inline address wizard (three radio/card choices,
 *      branch-specific fields, plain review + confirm).
 *   2. Your publish       — the operational center: state chip, progress,
 *      last published, Publish changes / Cancel / Finish publishing.
 *   3. Connect your domain — ONLY for a custom domain: the selected domain's
 *      DNS steps with copy controls, check-again, certificate status.
 *   4. When to publish    — native publish-trigger radios.
 *   5. Your Pinner account — supporting account/workspace information.
 *
 * Every dynamic value is escaped by the template itself, so a hostile status
 * (a crafted CID, website name, last error, env problem or destination field)
 * can never reach the admin page as executable markup. The parked
 * post-upload website card and the diagnostics-only Domain/Actions panel are
 * gone: platform and existing destinations never receive registrar/DNS setup
 * copy, and the DNS block is scoped to the destination's own domain — never an
 * implicit list pick.
 */
final class PublishDashboardTemplateTest extends TestCase
{
    /* --------------------------- the approved layout ---------------------- */

    public function testRendersTheApprovedFiveSectionLayoutForAPublishedPlatformSite(): void
    {
        $output = $this->render($this->view([
            'runStatus' => RunStatus::Completed,
            'runStage' => RunStage::Finished,
            'identity' => new PublishIdentity(
                websiteId: 'w-1',
                websiteName: 'Example Site',
                ipnsKeyId: 'k-1',
                ipnsKeyName: 'key-one',
                ready: true,
            ),
            'connection' => $this->resolvedConnection('Ada Lovelace', 'ada@example.test', 'main', 'main.example.test'),
        ]));

        // The page leads with the durable address, then the publish workflow.
        self::assertStringContainsString('Your site address', $output);
        self::assertStringContainsString('Your publish', $output);
        self::assertStringContainsString('When to publish', $output);
        self::assertStringContainsString('Your Pinner account', $output);

        // A platform destination never receives a custom-domain surface.
        self::assertStringNotContainsString('Connect your domain', $output);
        self::assertStringNotContainsString('nameservers at your registrar', $output);

        // The removed surfaces are gone from the page entirely.
        self::assertStringNotContainsString('cast-website-card', $output);
        self::assertStringNotContainsString('cast-domain-panel', $output);
        self::assertStringNotContainsString('cast-domain-actions', $output);
        self::assertStringNotContainsString('Publish to Pinner needs a website.', $output);

        // The old free-standing Readiness card is folded into "Your publish".
        self::assertStringNotContainsString('cast-publish-readiness"', $output);
    }

    public function testHeaderLeadsWithThePlainPageIntro(): void
    {
        $output = $this->render($this->view());

        self::assertStringContainsString('>Publish<', $output);
        self::assertStringContainsString('Put your latest changes online.', $output);
    }

    /* ---------------------------- your site address ----------------------- */

    public function testAddressCardOffersChooseAddressWhenNoDestinationIsSet(): void
    {
        $output = $this->render($this->view(['destination' => null]));

        self::assertStringContainsString('cast-address-card', $output);
        self::assertStringContainsString('Choose an address for your site.', $output);
        // The prompt is a real button (semantic, keyboard-reachable).
        self::assertMatchesRegularExpression(
            '/<button[^>]*data-cast-address-action="choose"[^>]*>Choose address<\/button>/',
            $output,
            'the choose-address prompt must be a button with the choose action',
        );
        // No address summary is implied while nothing is set.
        self::assertStringNotContainsString('cast-address-value', $output);
        self::assertStringNotContainsString('Your address stays the same', $output);
    }

    public function testAddressCardShowsSourceLabelAddressAndDurableNoteWhenSet(): void
    {
        $output = $this->render($this->view([
            'destination' => new PublishDestinationView('draft', [
                'source' => 'platform', 'domain' => null, 'namespace' => null,
                'dns_hosting_enabled' => true, 'platform_domain' => 'pinner.xyz',
                'platform_namespace' => 'icann', 'generate' => false, 'label' => 'my-site',
                'website_id' => null,
            ]),
        ]));

        self::assertStringContainsString('cast-address-source-platform', $output);
        self::assertStringContainsString('Pinner address', $output);
        self::assertStringContainsString('my-site.pinner.xyz', $output);
        self::assertStringContainsString('Your address stays the same after your first publish.', $output);
        // While the choice is still a draft the address may be reviewed.
        self::assertStringContainsString('data-cast-address-action="review"', $output);
        self::assertStringContainsString('Review address', $output);
    }

    public function testConfirmedDestinationShowsAFrozenSummaryWithoutReviewEntry(): void
    {
        $output = $this->render($this->view([
            'destination' => new PublishDestinationView('confirmed', [
                'source' => 'custom', 'domain' => 'shop.example.com', 'namespace' => 'icann',
                'dns_hosting_enabled' => true, 'platform_domain' => null,
                'platform_namespace' => null, 'generate' => null, 'label' => null,
                'website_id' => null,
            ]),
        ]));

        // The concise frozen summary keeps the source label and the address.
        self::assertStringContainsString('Your own domain', $output);
        self::assertStringContainsString('shop.example.com', $output);
        // …and says the choice is frozen.
        self::assertStringContainsString('Your address is confirmed and can no longer be changed.', $output);
        // …with NO review/edit wizard entry.
        self::assertStringNotContainsString('Review address', $output);
        self::assertStringNotContainsString('data-cast-address-action="review"', $output);
    }

    public function testSetupReadinessStatesTheOnboardingInstructionOnce(): void
    {
        $output = $this->render($this->view(['onboardingComplete' => false]));

        // One clear instruction: the context line. The readiness line must
        // not repeat it verbatim beside it.
        self::assertSame(1, substr_count($output, 'Finish onboarding to publish your site.'));
        self::assertStringNotContainsString('>Finish onboarding to publish<', $output);
        self::assertMatchesRegularExpression(
            '/cast-publish-readiness-level[^>]*hidden/',
            $output,
            'the readiness line is hidden while onboarding is outstanding',
        );
    }

    public function testSkippedOnboardingNeverInstructsFinishingIt(): void
    {
        $output = $this->render($this->view([
            'onboardingComplete' => false,
            'onboardingSkipped' => true,
        ]));

        // The user deliberately skipped onboarding: the page must never
        // instruct them to finish it, and says the truthful next step.
        self::assertStringNotContainsString('Finish onboarding to publish your site.', $output);
        self::assertStringNotContainsString('>Finish onboarding to publish<', $output);
        self::assertStringContainsString(
            'You skipped onboarding, so there is nothing to finish — publish your site whenever you are ready.',
            $output,
        );
    }

    public function testAddressCardNamesTheCustomDomainSource(): void
    {
        $output = $this->render($this->view([
            'destination' => new PublishDestinationView('confirmed', [
                'source' => 'custom', 'domain' => 'shop.example.com', 'namespace' => 'icann',
                'dns_hosting_enabled' => true, 'platform_domain' => null,
                'platform_namespace' => null, 'generate' => null, 'label' => null,
                'website_id' => null,
            ]),
        ]));

        self::assertStringContainsString('cast-address-source-custom', $output);
        self::assertStringContainsString('Your own domain', $output);
        self::assertStringContainsString('shop.example.com', $output);
    }

    public function testAddressCardNamesTheExistingSiteSource(): void
    {
        $output = $this->render($this->view([
            'destination' => new PublishDestinationView('created_or_attached', [
                'source' => 'existing', 'domain' => 'sub.example.com', 'namespace' => null,
                'dns_hosting_enabled' => null, 'platform_domain' => null,
                'platform_namespace' => null, 'generate' => null, 'label' => null,
                'website_id' => '66',
            ]),
        ]));

        self::assertStringContainsString('cast-address-source-existing', $output);
        self::assertStringContainsString('Existing Pinner site', $output);
        self::assertStringContainsString('sub.example.com', $output);
    }

    public function testReviewAddressIsWithheldOnceTheSiteIsPublished(): void
    {
        $output = $this->render($this->view([
            'runStatus' => RunStatus::Completed,
            'runStage' => RunStage::Finished,
            'identity' => new PublishIdentity(
                websiteId: 'w-1',
                websiteName: 'Example Site',
                ipnsKeyId: 'k-1',
                ipnsKeyName: 'key-one',
                ready: true,
            ),
        ]));

        // Once the address is live there is nothing left to review.
        self::assertStringNotContainsString('Review address', $output);
        self::assertStringNotContainsString('data-cast-address-action="review"', $output);
    }

    public function testEscapesHostileDestinationValuesInTheAddressCard(): void
    {
        $output = $this->render($this->view([
            'destination' => new PublishDestinationView('confirmed', [
                'source' => 'custom', 'domain' => 'shop<ex>.com', 'namespace' => 'icann',
                'dns_hosting_enabled' => true, 'platform_domain' => null,
                'platform_namespace' => null, 'generate' => null, 'label' => null,
                'website_id' => null,
            ]),
        ]));

        self::assertStringContainsString('shop&lt;ex&gt;.com', $output);
        self::assertStringNotContainsString('shop<ex>.com', $output);
        self::assertStringNotContainsString('<script>', $output);
    }

    /* -------------------------------- the wizard -------------------------- */

    public function testWizardRendersThreeSourceChoicesWithBranchSpecificFields(): void
    {
        $output = $this->render($this->view(['destination' => null]));

        // The wizard is present in the server render (hidden) so the no-JS
        // prompt and the JS flow share one markup.
        self::assertStringContainsString('data-cast-address-wizard', $output);
        self::assertMatchesRegularExpression(
            '/data-cast-address-wizard[^>]*hidden|hidden[^>]*data-cast-address-wizard/',
            $output,
            'the wizard renders hidden until opened',
        );

        // Three native radio choices — semantic, keyboard-traversable.
        self::assertSame(3, substr_count($output, 'name="cast-address-source"'), 'exactly three source radios');
        self::assertStringContainsString('value="platform"', $output);
        self::assertStringContainsString('value="custom"', $output);
        self::assertStringContainsString('value="existing"', $output);
        self::assertStringContainsString('Get a free Pinner address', $output);
        self::assertStringContainsString('Use a domain you own', $output);
        self::assertStringContainsString('Use a Pinner site you already have', $output);

        // Branch (platform): NO fields at all — the address is generated,
        // so there is no ambiguous optional name to fill in.
        self::assertStringNotContainsString('cast-address-platform-label', $output);
        self::assertStringNotContainsString('Name for your address', $output);

        // The branches are keyed by the data-cast-address-branch attribute the
        // orchestrator reveals per selected source.
        self::assertStringContainsString('data-cast-address-branch="platform"', $output);
        self::assertStringContainsString('data-cast-address-branch="custom"', $output);
        self::assertStringContainsString('data-cast-address-branch="existing"', $output);

        // Branch (custom): the domain, the namespace choice and the DNS mode.
        self::assertStringContainsString('id="cast-address-custom-domain"', $output);
        self::assertStringContainsString('id="cast-address-custom-namespace"', $output);
        self::assertStringContainsString('value="icann"', $output);
        self::assertStringContainsString('value="hns"', $output);
        self::assertStringContainsString('Let Pinner handle DNS', $output);
        self::assertStringContainsString('I will handle DNS', $output);

        // Branch (existing): a native select of the account's sites, with
        // distinct loading / error / empty states (never a silent no-op).
        self::assertStringContainsString('id="cast-address-existing-website"', $output);
        self::assertStringContainsString('cast-address-existing-loading', $output);
        self::assertStringContainsString('cast-address-existing-error', $output);

        // The plain final review — a single read-out line the orchestrator
        // keeps in step: no re-typing of the domain, and a generated platform
        // address is never phrased as "available at …".
        self::assertStringContainsString('cast-address-review-copy', $output);
        self::assertStringNotContainsString('cast-address-review-address', $output);
        self::assertStringNotContainsString('Your site will be available at', $output);
        // The confirm is a real button (its label is readiness-dependent and
        // pinned by testConfirmButtonClaimsPublishOnlyWhenFirstPublishIsReady).
        self::assertMatchesRegularExpression(
            '/<button[^>]*data-cast-address-action="confirm"[^>]*>/',
            $output,
            'the confirm is a real button',
        );
    }

    public function testConfirmButtonClaimsPublishOnlyWhenFirstPublishIsReady(): void
    {
        // Publish-ready (confirmed destination, eligible content, no run,
        // no identity): the confirm names the publish its click will start.
        $ready = $this->render($this->view());

        self::assertMatchesRegularExpression(
            '/<button[^>]*data-cast-address-action="confirm"[^>]*>Create address and publish<\/button>/',
            $ready,
            'a publish-ready site promises create AND publish',
        );

        // Not publish-ready (fresh local site, no eligible content): the
        // server's first-publish gate will refuse a start, so the button must
        // only name what the click does — create the address.
        $blocked = $this->render($this->view([
            'destination' => null,
            'hasEligibleContent' => false,
        ]));

        self::assertMatchesRegularExpression(
            '/<button[^>]*data-cast-address-action="confirm"[^>]*>Create address<\/button>/',
            $blocked,
            'a not-ready site promises only the address',
        );
        self::assertStringNotContainsString('Create address and publish', $blocked);
    }

    public function testPlatformWizardBranchHasNoNameFieldAndTheReviewPromisesACreatedFreeAddress(): void
    {
        $output = $this->render($this->view(['destination' => null]));

        // The generated platform branch carries no optional name field…
        self::assertStringNotContainsString('cast-address-platform-label', $output);
        self::assertStringNotContainsString('Name for your address', $output);

        // …and the review confirmation says Pinner will CREATE a free
        // address — never "available at A free Pinner address".
        self::assertStringContainsString('Pinner will create a free address for your site.', $output);
        self::assertStringNotContainsString('available at A free Pinner address', $output);
    }

    public function testWizardIsPrefilledFromAPersistedDraft(): void
    {
        $output = $this->render($this->view([
            'destination' => new PublishDestinationView('draft', [
                'source' => 'custom', 'domain' => 'shop.example.com', 'namespace' => 'hns',
                'dns_hosting_enabled' => false, 'platform_domain' => null,
                'platform_namespace' => null, 'generate' => null, 'label' => null,
                'website_id' => null,
            ]),
        ]));

        // A refresh never loses the unconfirmed choice: the wizard carries it.
        self::assertStringContainsString('value="shop.example.com"', $output);
        self::assertMatchesRegularExpression(
            '/<option[^>]*value="hns"[^>]*selected/',
            $output,
            'the draft namespace is preselected',
        );
    }

    public function testNewCustomDraftDefaultsToPinnerManagedDns(): void
    {
        // A custom draft with no explicit dns_hosting_enabled yet: the portal
        // default is managed, so the wizard must default to it too.
        $output = $this->render($this->view([
            'destination' => new PublishDestinationView('draft', [
                'source' => 'custom', 'domain' => 'shop.example.com', 'namespace' => 'icann',
                'dns_hosting_enabled' => null, 'platform_domain' => null,
                'platform_namespace' => null, 'generate' => null, 'label' => null,
                'website_id' => null,
            ]),
        ]));

        self::assertMatchesRegularExpression(
            '/<input[^>]*name="cast-address-dns"[^>]*value="managed"[^>]*checked/',
            $output,
            'a first-time custom choice defaults to Pinner-managed DNS (dns_hosting_enabled=true)',
        );
        self::assertMatchesRegularExpression(
            '/<input[^>]*name="cast-address-dns"[^>]*value="self"(?![^>]*checked)/',
            $output,
            'self-managed DNS is not the default for a first-time custom choice',
        );
    }

    public function testSelfManagedDnsIsTuckedUnderAnAdvancedDisclosure(): void
    {
        $output = $this->render($this->view(['destination' => null]));

        self::assertMatchesRegularExpression(
            '/<details[^>]*cast-address-advanced.*?<summary[^>]*>Advanced<\/summary>.*?value="self".*?<\/details>/s',
            $output,
            'the self-managed choice sits behind an explicit Advanced disclosure',
        );
        self::assertMatchesRegularExpression(
            '/<details[^>]*cast-address-advanced(?![^>]*open)/',
            $output,
            'the disclosure is closed by default so the advanced choice is hidden',
        );
    }

    public function testWizardRendersNamespaceSpecificDnsCopy(): void
    {
        $icann = $this->render($this->view(['destination' => null]));

        self::assertStringContainsString('data-cast-namespace-note="icann"', $icann);
        self::assertStringContainsString('data-cast-namespace-note="hns"', $icann);
        self::assertMatchesRegularExpression(
            '/data-cast-namespace-note="icann"(?![^>]*hidden)/',
            $icann,
            'the ICANN note is the one shown for the default namespace',
        );
        self::assertMatchesRegularExpression(
            '/data-cast-namespace-note="hns"[^>]*hidden/',
            $icann,
            'the HNS note is hidden until the namespace is HNS',
        );
        self::assertStringContainsString('on-chain', $icann, 'the HNS copy explains the on-chain parent records');
        self::assertMatchesRegularExpression('/nameserver/i', $icann, 'the HNS copy mentions the nameserver guidance');

        $hns = $this->render($this->view([
            'destination' => new PublishDestinationView('draft', [
                'source' => 'custom', 'domain' => 'acme.example', 'namespace' => 'hns',
                'dns_hosting_enabled' => true, 'platform_domain' => null,
                'platform_namespace' => null, 'generate' => null, 'label' => null,
                'website_id' => null,
            ]),
        ]));

        self::assertMatchesRegularExpression(
            '/data-cast-namespace-note="hns"(?![^>]*hidden)/',
            $hns,
            'the HNS note is shown for an HNS draft',
        );
        self::assertMatchesRegularExpression(
            '/data-cast-namespace-note="icann"[^>]*hidden/',
            $hns,
            'the ICANN note hides for an HNS draft',
        );
    }

    public function testWizardOffersNoHip5AsAPreCreateChoice(): void
    {
        $output = $this->render($this->view(['destination' => null]));

        self::assertStringNotContainsString(
            'HIP-5',
            strtoupper($output),
            'HIP-5 is a post-binding server state — never a pre-create choice',
        );
    }

    public function testWizardHasNoClickBoundListItemControls(): void
    {
        $output = $this->render($this->view(['destination' => null]));

        // No clickable list items anywhere in the address surface: every
        // control is a native button, radio, input or select.
        self::assertStringNotContainsString('data-website-action', $output);
        self::assertStringNotContainsString('cast-domain-row', $output);
        self::assertStringNotContainsString('<li', $output);
    }

    /* -------------------------- connect your domain ----------------------- */

    public function testConnectYourDomainCardRendersOnlyForCustomDestinations(): void
    {
        $custom = $this->render($this->view([
            'destination' => new PublishDestinationView('created_or_attached', [
                'source' => 'custom', 'domain' => 'shop.example.com', 'namespace' => 'icann',
                'dns_hosting_enabled' => true, 'platform_domain' => null,
                'platform_namespace' => null, 'generate' => null, 'label' => null,
                'website_id' => null,
            ]),
        ]));

        self::assertStringContainsString('cast-domain-setup-card', $custom);
        self::assertStringContainsString('Connect your domain', $custom);
        // The card is scoped to the chosen domain, named verbatim.
        self::assertStringContainsString('shop.example.com', $custom);
        self::assertStringContainsString('I made the changes — check again', $custom);
        self::assertStringContainsString('Security certificate', $custom);

        foreach (['platform', 'existing'] as $source) {
            $output = $this->render($this->view([
                'destination' => new PublishDestinationView('created_or_attached', [
                    'source' => $source, 'domain' => $source === 'existing' ? 'sub.example.com' : null,
                    'namespace' => null, 'dns_hosting_enabled' => null,
                    'platform_domain' => 'pinner.xyz', 'platform_namespace' => 'icann',
                    'generate' => true, 'label' => null,
                    'website_id' => $source === 'existing' ? '66' : null,
                ]),
            ]));

            self::assertStringNotContainsString('Connect your domain', $output, $source . ' must not show the domain card');
            self::assertStringNotContainsString('cast-domain-setup-card', $output);
            self::assertStringNotContainsString('nameservers at your registrar', $output);
            self::assertStringNotContainsString('Security certificate', $output);
        }
    }

    public function testConnectCardShowsDnsGuidanceOnlyForTheSelectedDomain(): void
    {
        $guidance = $this->dnsDomainView(
            new Domain(
                '9',
                'shop.example.com',
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
            ),
        );

        // A matching delegation bundle renders inside the connect card…
        $output = $this->render($this->view([
            'destination' => new PublishDestinationView('created_or_attached', [
                'source' => 'custom', 'domain' => 'shop.example.com', 'namespace' => 'icann',
                'dns_hosting_enabled' => true, 'platform_domain' => null,
                'platform_namespace' => null, 'generate' => null, 'label' => null,
                'website_id' => null,
            ]),
        ]), $guidance);
        self::assertStringContainsString('Parent records (configure at your registrar)', $output);

        // …and a bundle for a DIFFERENT domain never leaks in — the card shows
        // only the destination's own domain, never an implicit list pick.
        $other = $this->render($this->view([
            'destination' => new PublishDestinationView('created_or_attached', [
                'source' => 'custom', 'domain' => 'shop.example.com', 'namespace' => 'icann',
                'dns_hosting_enabled' => true, 'platform_domain' => null,
                'platform_namespace' => null, 'generate' => null, 'label' => null,
                'website_id' => null,
            ]),
        ]), $this->dnsDomainView(
            new Domain('10', 'other.example.com', 'icann', true, 'waiting_delegation', 'gw.example.com'),
        ));
        self::assertStringNotContainsString('Parent records (configure at your registrar)', $other);
        self::assertStringNotContainsString('other.example.com', $other);
    }

    public function testConnectCardCarriesTheValidateActionForTheSelectedDomain(): void
    {
        $domain = new Domain('9', 'shop.example.com', 'icann', true, 'waiting_delegation', 'gw.example.com');

        $output = $this->render($this->view([
            'destination' => new PublishDestinationView('created_or_attached', [
                'source' => 'custom', 'domain' => 'shop.example.com', 'namespace' => 'icann',
                'dns_hosting_enabled' => false, 'platform_domain' => null,
                'platform_namespace' => null, 'generate' => null, 'label' => null,
                'website_id' => null,
            ]),
        ]), $this->dnsDomainView($domain));

        // The check-again action is a real button carrying the selected
        // domain's id (the matched list row), never an implicit pick.
        self::assertMatchesRegularExpression(
            '/<button[^>]*data-domain-action="validate"[^>]*data-domain-id="9"/',
            $output,
        );
        self::assertStringContainsString('I made the changes — check again', $output);
    }

    public function testConnectCardOffersCopyControlsBesideTheDnsValues(): void
    {
        $domain = new Domain(
            '9',
            'shop.example.com',
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

        $output = $this->render($this->view([
            'destination' => new PublishDestinationView('created_or_attached', [
                'source' => 'custom', 'domain' => 'shop.example.com', 'namespace' => 'icann',
                'dns_hosting_enabled' => true, 'platform_domain' => null,
                'platform_namespace' => null, 'generate' => null, 'label' => null,
                'website_id' => null,
            ]),
        ]), $this->dnsDomainView($domain));

        // Every rendered DNS value gets a copy control beside it.
        self::assertStringContainsString('data-cast-copy="ns1.pinner.xyz"', $output);
        self::assertStringContainsString('data-cast-copy="12345 8 2 ABCDEF"', $output);
        self::assertMatchesRegularExpression(
            '/<button[^>]*class="[^"]*cast-domain-copy[^"]*"[^>]*data-cast-copy="ns1\.pinner\.xyz"/',
            $output,
            'copy controls are real buttons',
        );
    }

    /* ------------------------- DNS guidance in the card ------------------- */

    public function testRendersManagedIcannDnsGuidanceWithNameTypeValueTable(): void
    {
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

        $output = $this->render($this->customDestinationView('site.example.test'), $this->dnsDomainView($domain));

        // The managed ICANN guidance copy lands verbatim (apostrophes escaped).
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
        // No generic actions list survives in the card.
        self::assertStringNotContainsString('cast-domain-actions', $output);
    }

    public function testRendersManagedHnsDnsGuidanceOnChainWithNameserversAndDnssecError(): void
    {
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

        $output = $this->render($this->customDestinationView('name/'), $this->dnsDomainView($domain));

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

    public function testRendersOnchainManagedHnsDnsGuidanceWithoutPinnerManagesClaim(): void
    {
        // An HNS binding the server reports as onchain_managed: its DNS is
        // served by an external on-chain contract, so there is no Pinner-managed
        // zone to point at. The card must never claim Pinner manages the DNS and
        // must show only the server-returned DNSLink/TLSA guidance.
        $domain = new Domain(
            '9',
            'name/',
            'hns',
            true,
            'onchain_managed',
            'gw.example.com',
            null,
            [
                CheckInfo::fromArray([
                    'name' => 'dnslink',
                    'ok' => false,
                    'message' => '',
                    'expected' => 'dnslink=/ipns/k-ipns-7',
                    'found' => '',
                ]),
                CheckInfo::fromArray([
                    'name' => 'tlsa',
                    'ok' => false,
                    'message' => '',
                    'expected' => '_443._tcp.name/ TLSA 3 1 1 abcdef',
                    'found' => '',
                ]),
            ],
        );

        $output = $this->render($this->customDestinationView('name/'), $this->dnsDomainView($domain));

        // The on-chain-managed explanation renders…
        self::assertStringContainsString('This domain is managed on-chain, so its DNS records are set on-chain, not by Pinner.', $output);
        // …and the binding is never claimed to be Pinner-managed.
        self::assertStringNotContainsString('Pinner manages your DNS', $output);
        self::assertStringNotContainsString('the authoritative side is handled for you', $output);
        // The managed-HNS / self-managed delegation framing is absent.
        self::assertStringNotContainsString('Publish the records below in the DNS/records area of your HNS wallet', $output);
        // The server-returned DNSLink and TLSA guidance is shown verbatim.
        self::assertStringContainsString('dnslink=/ipns/k-ipns-7', $output);
        self::assertStringContainsString('_443._tcp.name/ TLSA 3 1 1 abcdef', $output);
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

        $output = $this->render(
            $this->customDestinationView('site.example.test', dnsHostingEnabled: false),
            $this->dnsDomainView($domain),
        );

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

        $output = $this->render($this->customDestinationView('name/'), $this->dnsDomainView($domain));

        self::assertStringContainsString('No delegation records are available for name/.', $output);
        self::assertStringContainsString('Publish the delegation records below', $output);
    }

    /* ------------------------------ your publish -------------------------- */

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
        self::assertStringContainsString('Website: &lt;Example&gt; &amp; Co', $output);
        self::assertStringNotContainsString('<Cid>', $output);
        self::assertStringNotContainsString('<Example>', $output);
        self::assertStringNotContainsString('<script>', $output);
    }

    public function testPublishCardOffersPublishChangesCancelAndFinishActions(): void
    {
        $output = $this->render($this->view([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'runActive' => true,
            'identity' => new PublishIdentity(
                websiteId: 'w-1',
                websiteName: 'Example Site',
                ipnsKeyId: 'k-1',
                ipnsKeyName: 'key-one',
                ready: true,
            ),
        ]));

        self::assertStringContainsString('Publish changes', $output);
        self::assertStringContainsString('Cancel publish', $output);

        // Finish publishing (the artifact resume) is offered for a terminal
        // run with an identity — the custom-DNS "finish" path.
        $finished = $this->render($this->view([
            'runStatus' => RunStatus::Failed,
            'runStage' => RunStage::Finished,
            'identity' => new PublishIdentity(
                websiteId: 'w-1',
                websiteName: 'Example Site',
                ipnsKeyId: 'k-1',
                ipnsKeyName: 'key-one',
                ready: true,
            ),
        ]));
        self::assertStringContainsString('Finish publishing', $finished);
    }

    public function testQueuedStateRendersWaitingContextNotAMisleadingItemCount(): void
    {
        $queued = $this->render($this->view([
            'runStatus' => RunStatus::NotStarted,
            'runActive' => true,
            'queuedAt' => 5000,
        ]));

        $cadence = TickConfig::DEFAULT_TICK_INTERVAL_SECONDS;
        self::assertStringContainsString('Starting within ' . $cadence . ' seconds — waiting to begin', $queued);
        self::assertStringContainsString('waiting to begin', $queued);
        self::assertStringNotContainsString('0 items processed', $queued);

        self::assertStringContainsString('data-cast-queued-eta="' . (5000 + $cadence) . '"', $queued);

        self::assertStringContainsString('Queued — starting shortly', $queued);
        self::assertStringContainsString(
            'Your publish is queued and will start automatically. Keep working — it runs in the background and this page tracks the progress.',
            $queued,
        );

        self::assertStringContainsString('data-cast-escape', $queued);
        self::assertStringContainsString('Start now', $queued);
        self::assertStringContainsString('data-cast-publish-action="start"', $queued);
        self::assertStringContainsString('hidden', $queued);

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

        self::assertStringContainsString('Capturing content… (100 URLs)', $output);
        self::assertStringNotContainsString('42 items processed', $output);
        self::assertStringNotContainsString('Waiting to begin', $output);
        self::assertStringContainsString('data-cast-progress-total="100"', $output);
        self::assertStringNotContainsString('cast-publish-escape', $output);
        self::assertStringNotContainsString('Start now', $output);
    }

    public function testNoDiscoverTotalRendersNoProgressTotalHook(): void
    {
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

        self::assertStringContainsString('Cancelled — retry available', $output);
        self::assertStringContainsString('Your last publish was cancelled. Publish to Pinner to start again.', $output);

        self::assertStringNotContainsString('cast-publish-escape', $output);
        self::assertStringNotContainsString('Start now', $output);
        self::assertStringNotContainsString('Queued longer than expected', $output);
        self::assertStringNotContainsString('cast-publish-cancel', $output);
        self::assertStringNotContainsString('Cancel publish', $output);

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

        // The operator-facing readiness line names the variables and message.
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

    public function testRendersExplicitStateChipLastCheckedAndRefreshControl(): void
    {
        $queued = $this->render($this->view([
            'runStatus' => RunStatus::NotStarted,
            'runActive' => true,
        ]));

        self::assertMatchesRegularExpression(
            '/class="cast-publish-state-chip cast-publish-state-queued"[^>]*role="status"[^>]*>/',
            $queued,
            'the queued state must render as an accessible chip',
        );
        self::assertStringContainsString('Queued — starting shortly', $queued);
        self::assertStringNotContainsString('Publishing', $queued);

        self::assertStringContainsString('cast-publish-last-checked', $queued);
        self::assertStringContainsString('Last checked', $queued);

        self::assertStringContainsString('Refresh status', $queued);
        self::assertMatchesRegularExpression(
            '/class="[^"]*cast-publish-refresh[^"]*"[^>]*data-cast-publish-refresh/',
            $queued,
        );
        self::assertStringNotContainsString('data-cast-publish-action="refresh"', $queued);

        $failed = $this->render($this->view([
            'runStatus' => RunStatus::Failed,
            'runStage' => RunStage::Finished,
        ]));
        self::assertStringContainsString('Failed — retry available', $failed);
        self::assertMatchesRegularExpression(
            '/class="cast-publish-state-chip cast-publish-state-failed"/',
            $failed,
        );

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

    public function testRendersHonestRetryCopyWithEnabledPrimaryForFailedFirstPublish(): void
    {
        $output = $this->render($this->view([
            'runStatus' => RunStatus::Failed,
            'runStage' => RunStage::Finished,
            'lastError' => 'The upload timed out',
        ]));

        self::assertStringContainsString('Publish failed', $output);
        self::assertStringContainsString('Your last publish failed. Publish to Pinner to retry.', $output);
        self::assertStringNotContainsString('publish it to Pinner for the first time', $output);

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

        self::assertStringContainsString('data-cast-publish-action=""', $output);
        self::assertMatchesRegularExpression(
            '/class="[^"]*cast-publish-primary[^"]*"[^>]*disabled[^>]*>/',
            $output,
            'an unavailable environment must leave the primary disabled',
        );
        self::assertStringContainsString('Publishing is unavailable until the configuration below is resolved.', $output);
    }

    public function testAwaitingWebsiteNeverRendersTheParkedWebsiteCard(): void
    {
        $output = $this->render($this->view([
            'runStatus' => RunStatus::Paused,
            'runActive' => true,
            'awaitingWebsite' => true,
            'awaitingCid' => 'QmParkedCid',
        ]));

        // The parked post-upload website card is removed: the address is a
        // pre-first-publish decision, so no post-upload choice surface.
        self::assertStringNotContainsString('cast-website-card', $output);
        self::assertStringNotContainsString('Publish to Pinner needs a website.', $output);
        self::assertStringNotContainsString('Resume with this CID', $output);
        self::assertStringNotContainsString('data-website-action', $output);
        // The plain paused chip is intact; the progress track stays hidden
        // while the run is parked (no percentage is implied).
        self::assertStringContainsString('Paused', $output);
        self::assertStringNotContainsString('cast-publish-progress-track', $output);
    }

    /* ----------------------------- when to publish ------------------------ */

    public function testWhenToPublishRendersNativeRadiosWithPerOptionHelp(): void
    {
        $output = $this->render($this->view(['mode' => PublishMode::Manual]));

        // Native radios (semantic, keyboard-traversable), friendly labels.
        self::assertSame(2, substr_count($output, 'name="cast-publish-mode"'), 'exactly two trigger radios');
        self::assertStringContainsString('Only when I choose', $output);
        self::assertStringContainsString('When I update my site', $output);

        // The active option is checked + non-actionable.
        self::assertMatchesRegularExpression(
            '/<input[^>]*type="radio"[^>]*name="cast-publish-mode"[^>]*value="manual"[^>]*checked[^>]*disabled[^>]*>/',
            $output,
            'the active (manual) radio must be checked and disabled',
        );
        self::assertMatchesRegularExpression(
            '/<input[^>]*type="radio"[^>]*name="cast-publish-mode"[^>]*value="on_update"(?![^>]*checked)[^>]*>/',
            $output,
            'the inactive radio is not checked',
        );

        // The active mode's full end-user help renders as the guidance line.
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

    public function testModeRadiosAreHiddenWhenModeCannotChange(): void
    {
        // Mid-run the trigger group (and its help) is not rendered at all, so
        // no mode control is offered while the mode is locked.
        $output = $this->render($this->view([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'runActive' => true,
        ]));

        self::assertStringNotContainsString('name="cast-publish-mode"', $output);
        self::assertStringNotContainsString('cast-publish-mode-help', $output);
    }

    public function testWhenToPublishShowsAnInformativeFallbackWhenModeCannotChange(): void
    {
        // Not publish-ready (onboarding incomplete): the card carries a
        // concise informative line — never an empty heading.
        $output = $this->render($this->view(['onboardingComplete' => false]));

        self::assertStringContainsString('When to publish', $output);
        self::assertStringContainsString('cast-publish-mode-locked', $output);
        self::assertStringContainsString(
            'You can choose a publish trigger once your site is ready to publish.',
            $output,
        );
        self::assertStringNotContainsString('name="cast-publish-mode"', $output);

        // Mid-run: the copy names the lock, not a generic "not available".
        $running = $this->render($this->view([
            'runStatus' => RunStatus::Running,
            'runStage' => RunStage::Exporting,
            'runActive' => true,
        ]));
        self::assertStringContainsString('cast-publish-mode-locked', $running);
        self::assertStringContainsString(
            'The publish trigger is locked while a publish is in progress.',
            $running,
        );
        self::assertStringNotContainsString('name="cast-publish-mode"', $running);
    }

    /* --------------------------- your pinner account ---------------------- */

    public function testRendersResolvedAccountCardEscaped(): void
    {
        $output = $this->render($this->view([
            'connection' => $this->resolvedConnection('Ada Lovelace', 'ada<@>example.test', 'main', 'main.example.test'),
        ]));

        self::assertStringContainsString('cast-connection-card', $output);
        self::assertStringContainsString('Your Pinner account', $output);
        self::assertStringContainsString('Connected', $output);
        // Identifiers escape hostile characters; never echoed raw.
        self::assertStringContainsString('ada&lt;@&gt;example.test', $output);
        self::assertStringNotContainsString('ada<@>example.test', $output);
        self::assertStringContainsString('main.example.test', $output);
        self::assertStringContainsString('Ada Lovelace', $output);
        self::assertStringNotContainsString('<script>', $output);
    }

    public function testRendersAccountErrorStateEscapedWithoutCredentials(): void
    {
        $output = $this->render($this->view([
            'connection' => $this->errorConnection(),
        ]));

        self::assertStringContainsString('cast-connection-card', $output);
        self::assertStringNotContainsString('Connected', $output);
        self::assertStringContainsString('cast-connection-error', $output);
        self::assertStringNotContainsString('account-key', $output);
        self::assertStringNotContainsString('<script>', $output);
    }

    public function testRendersNoAccountCardWhenConnectionUnresolvedOrAbsent(): void
    {
        $output = $this->render($this->view());

        self::assertStringNotContainsString('cast-connection-card', $output);
    }

    public function testRendersNoContentReadiness(): void
    {
        $output = $this->render($this->view(['hasEligibleContent' => false]));

        self::assertStringContainsString('No publishable content yet', $output);
    }

    /* -------------------------------- helpers ------------------------------ */

    /**
     * A confirmed-or-later custom destination for the connect-card fixtures.
     */
    private function customDestinationView(string $domain, bool $dnsHostingEnabled = true): PublishDashboardView
    {
        return $this->view([
            'destination' => new PublishDestinationView('created_or_attached', [
                'source' => 'custom', 'domain' => $domain, 'namespace' => 'icann',
                'dns_hosting_enabled' => $dnsHostingEnabled, 'platform_domain' => null,
                'platform_namespace' => null, 'generate' => null, 'label' => null,
                'website_id' => null,
            ]),
        ]);
    }

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

    /**
     * Render the production publish template through the same public boundary
     * the subscriber uses (the default-path ViewRenderer) with the exact view
     * keys PublishAdminSubscriber::render() provides.
     */
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
            'onboardingSkipped' => false,
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
            // A confirmed destination keeps the choose-address gate out of
            // these rendering fixtures (the gate only applies to unconfigured
            // first-time sites).
            'destination' => new PublishDestinationView('confirmed', [
                'source' => 'platform', 'domain' => null, 'namespace' => null,
                'dns_hosting_enabled' => true, 'platform_domain' => null,
                'platform_namespace' => null, 'generate' => true, 'label' => null,
                'website_id' => null,
            ]),
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
            onboardingSkipped: (bool) $merged['onboardingSkipped'],
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
            destination: $merged['destination'],
        );
    }
}
