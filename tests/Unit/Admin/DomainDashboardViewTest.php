<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Unit\Admin;

use LumeWeb\Cast\Admin\DomainDashboardView;
use LumeWeb\Cast\Admin\DomainDnsResult;
use LumeWeb\Cast\Admin\DomainListResult;
use LumeWeb\Cast\Admin\DomainRefusal;
use LumeWeb\Cast\Admin\DomainSslResult;
use LumeWeb\Cast\Ipfs\CheckInfo;
use LumeWeb\Cast\Ipfs\DelegationInfo;
use LumeWeb\Cast\Publish\Domain;
use PHPUnit\Framework\TestCase;

/**
 * The domain dashboard view model maps the typed, JSON-safe domain result DTOs
 * (list / SSL / DNS) plus the panel preconditions onto the display/action state
 * the choose-a-domain panel renders. These tests pin that mapping — panel
 * readiness, which actions may be offered, the list/SSL/DNS display states and
 * the human-safe label + level for every typed refusal — so the template never
 * has to re-derive a decision from raw result fields, and so a refusal reason
 * or credential can never reach the payload.
 */
final class DomainDashboardViewTest extends TestCase
{
    public function testDomainDashboardViewClassExists(): void
    {
        self::assertTrue(class_exists(DomainDashboardView::class));
    }

    /* ----------------------------- panel state ---------------------------- */

    public function testReadyStateWhenEnvironmentAndWebsiteIdentityAreComplete(): void
    {
        $view = $this->view();

        self::assertSame(DomainDashboardView::STATE_READY, $view->state);
        self::assertSame(DomainDashboardView::LEVEL_OK, $view->stateLevel);
        self::assertSame('Choose and manage your domain', $view->stateLabel);
        self::assertTrue($view->hasWebsite);
    }

    public function testConfigStateWhenEnvironmentIdentityIsMissing(): void
    {
        $view = $this->view(['envComplete' => false]);

        self::assertSame(DomainDashboardView::STATE_CONFIG, $view->state);
        self::assertSame(DomainDashboardView::LEVEL_ERROR, $view->stateLevel);
        self::assertSame('Environment configuration required', $view->stateLabel);
    }

    public function testSetupStateWhenWebsiteIdentityIsMissingButOnboardingComplete(): void
    {
        // Onboarding done + no published site yet: the honest next step is a
        // first publish, so the message must not keep saying "complete
        // onboarding".
        $view = $this->view(['hasWebsite' => false]);

        self::assertSame(DomainDashboardView::STATE_SETUP, $view->state);
        self::assertSame(DomainDashboardView::LEVEL_NOTE, $view->stateLevel);
        self::assertSame('Publish your site to begin managing domains.', $view->stateLabel);
        self::assertFalse($view->hasWebsite);
    }

    public function testSetupStateWhenOnboardingIncompleteKeepsOnboardingCopy(): void
    {
        // The wizard genuinely is not finished here, so the original copy is
        // still the accurate one.
        $view = $this->view(['onboardingComplete' => false, 'hasWebsite' => false]);

        self::assertSame(DomainDashboardView::STATE_SETUP, $view->state);
        self::assertSame('Complete onboarding to manage domains', $view->stateLabel);
        self::assertFalse($view->hasWebsite);
    }

    /* ------------------------- action availability ------------------------ */

    public function testReadyStateOffersEveryActionWhenADomainIsSelected(): void
    {
        $view = $this->view();

        self::assertTrue($view->canBind);
        self::assertTrue($view->canVerify);
        self::assertTrue($view->canValidate);
        self::assertTrue($view->canDelete);
        self::assertTrue($view->canReadPlatform);
        self::assertTrue($view->canCheckAvailability);
        self::assertTrue($view->canReadDns);
        self::assertTrue($view->canReadSsl);
    }

    public function testVerifyDeleteDnsAndSslNeedASelectedDomain(): void
    {
        $view = $this->view(['selected' => false]);

        self::assertTrue($view->canBind);
        self::assertTrue($view->canReadPlatform);
        self::assertTrue($view->canCheckAvailability);
        self::assertFalse($view->canVerify);
        self::assertFalse($view->canValidate);
        self::assertFalse($view->canDelete);
        self::assertFalse($view->canReadDns);
        self::assertFalse($view->canReadSsl);
    }

    public function testOnlyPlatformReadsRemainWithoutAWebsiteIdentity(): void
    {
        $view = $this->view(['hasWebsite' => false]);

        self::assertTrue($view->canReadPlatform);
        self::assertTrue($view->canCheckAvailability);
        self::assertFalse($view->canBind);
        self::assertFalse($view->canVerify);
        self::assertFalse($view->canValidate);
        self::assertFalse($view->canDelete);
        self::assertFalse($view->canReadDns);
        self::assertFalse($view->canReadSsl);
    }

    public function testConfigStateOffersNoActionAtAll(): void
    {
        $view = $this->view(['envComplete' => false]);

        self::assertFalse($view->canBind);
        self::assertFalse($view->canVerify);
        self::assertFalse($view->canValidate);
        self::assertFalse($view->canDelete);
        self::assertFalse($view->canReadPlatform);
        self::assertFalse($view->canCheckAvailability);
        self::assertFalse($view->canReadDns);
        self::assertFalse($view->canReadSsl);
    }

    /* ------------------------------ domain list ---------------------------- */

    public function testListMapsBoundDomainsToJsonSafeRows(): void
    {
        $view = $this->view([
            'list' => new DomainListResult(true, null, [
                new Domain('99', 'site.example.test', 'icann', false, 'pending'),
                new Domain('7', 'name/', 'hns', true, 'waiting_delegation', 'gw.example.com'),
            ]),
        ]);

        self::assertSame(DomainDashboardView::LIST_OK, $view->listStatus);
        self::assertNull($view->listRefusal);
        self::assertSame('99', $view->domains[0]['id']);
        self::assertSame('site.example.test', $view->domains[0]['domain']);
        self::assertSame('icann', $view->domains[0]['namespace']);
        self::assertFalse($view->domains[0]['dns_hosting_enabled']);
        self::assertSame('pending', $view->domains[0]['status']);
        self::assertNull($view->domains[0]['gateway_host']);
        self::assertSame('7', $view->domains[1]['id']);
        self::assertSame('hns', $view->domains[1]['namespace']);
        self::assertTrue($view->domains[1]['dns_hosting_enabled']);
        self::assertSame('gw.example.com', $view->domains[1]['gateway_host']);
    }

    public function testEmptyListIsExplicitlyFarFromOk(): void
    {
        $view = $this->view(['list' => new DomainListResult(true, null, [])]);

        self::assertSame(DomainDashboardView::LIST_EMPTY, $view->listStatus);
        self::assertSame([], $view->domains);
        self::assertNull($view->listRefusal);
    }

    public function testListRefusalSurfacesAJsonSafeDisplayBlock(): void
    {
        $view = $this->view([
            'list' => new DomainListResult(false, DomainRefusal::RequestFailed),
        ]);

        self::assertSame(DomainDashboardView::LIST_REFUSED, $view->listStatus);
        self::assertSame([], $view->domains);
        self::assertNotNull($view->listRefusal);
        self::assertSame('request_failed', $view->listRefusal['code']);
        self::assertSame('Pinner could not be reached. Please try again.', $view->listRefusal['label']);
        self::assertSame(DomainDashboardView::LEVEL_ERROR, $view->listRefusal['level']);
    }

    /* ------------------------------- SSL state ----------------------------- */

    public function testSslReadyMapsToActiveDisplayState(): void
    {
        $view = $this->view([
            'ssl' => new DomainSslResult(true, null, [
                'status' => 'ready',
                'issued_at' => '2026-01-02T00:00:00Z',
                'last_updated_at' => '2026-01-02T00:00:00Z',
                'error' => null,
            ]),
        ]);

        self::assertSame(DomainDashboardView::SSL_READY, $view->sslState);
        self::assertSame('SSL active', $view->sslLabel);
        self::assertSame(DomainDashboardView::LEVEL_OK, $view->sslLevel);
        self::assertNotNull($view->ssl);
        self::assertSame('2026-01-02T00:00:00Z', $view->ssl['issued_at']);
        self::assertNull($view->ssl['error']);
    }

    public function testSslPendingMapsToPendingDisplayState(): void
    {
        $view = $this->view([
            'ssl' => new DomainSslResult(true, null, ['status' => 'pending']),
        ]);

        self::assertSame(DomainDashboardView::SSL_PENDING, $view->sslState);
        self::assertSame('SSL certificate is being issued', $view->sslLabel);
        self::assertSame(DomainDashboardView::LEVEL_WARNING, $view->sslLevel);
    }

    public function testSslFailureKeepsTheLabelGenericAndStartsWithAProblem(): void
    {
        $view = $this->view([
            'ssl' => new DomainSslResult(true, null, [
                'status' => 'failed',
                'error' => 'the bearer super-secret-account-key rejected this',
            ]),
        ]);

        self::assertSame(DomainDashboardView::SSL_FAILED, $view->sslState);
        // The operator-facing label never echoes the raw portal error verbatim.
        self::assertSame('SSL certificate could not be issued', $view->sslLabel);
        self::assertSame(DomainDashboardView::LEVEL_ERROR, $view->sslLevel);
        self::assertStringNotContainsString('super-secret-account-key', $view->sslLabel);
    }

    public function testSslNoneWhenThePortalHasNoCertificateBlock(): void
    {
        $view = $this->view(['ssl' => new DomainSslResult(true, null, null)]);

        self::assertSame(DomainDashboardView::SSL_NONE, $view->sslState);
        self::assertSame('No SSL certificate issued yet', $view->sslLabel);
        self::assertSame(DomainDashboardView::LEVEL_NOTE, $view->sslLevel);
        self::assertNull($view->ssl);
    }

    public function testSslRefusedMapsToTheTypedRefusalBlock(): void
    {
        $view = $this->view([
            'ssl' => new DomainSslResult(false, DomainRefusal::IdentityMissing),
        ]);

        self::assertSame(DomainDashboardView::SSL_REFUSED, $view->sslState);
        self::assertSame('Publish your site to begin managing domains.', $view->sslLabel);
        self::assertSame(DomainDashboardView::LEVEL_NOTE, $view->sslLevel);
        self::assertNull($view->ssl);
    }

    public function testSslLabelDoesNotImplySelectableDomainBeforeOneExists(): void
    {
        // No website identity: the section is hidden by the template, but the
        // serialized label must not claim a domain can be selected.
        $noWebsite = $this->view(['hasWebsite' => false, 'selected' => false]);
        self::assertSame('Publish your site to begin managing domains.', $noWebsite->sslLabel);
        self::assertSame('Publish your site to begin managing domains.', $noWebsite->dnsLabel);

        // Website exists but no domain is bound yet: say "bind a domain",
        // never "select a domain".
        $websiteNoDomains = $this->view([
            'list' => new DomainListResult(true, null, []),
            'selected' => false,
            'ssl' => null,
            'dns' => null,
        ]);
        self::assertSame('Bind a domain to view SSL status', $websiteNoDomains->sslLabel);
        self::assertSame('Bind a domain to view DNS delegation', $websiteNoDomains->dnsLabel);
        self::assertTrue($websiteNoDomains->hasWebsite);
    }

    public function testConfigStateStillReflectsARegisteredWebsiteIdentity(): void
    {
        // hasWebsite is the raw "a site has been published once" fact — it
        // stays true even when the environment is now incomplete, so an
        // existing site's SSL/DNS sections keep rendering (with refusal copy).
        $view = $this->view(['envComplete' => false]);

        self::assertSame(DomainDashboardView::STATE_CONFIG, $view->state);
        self::assertTrue($view->hasWebsite);
    }

    public function testSslStaysQuietWithoutASelectedDomain(): void
    {
        $view = $this->view(['selected' => false, 'ssl' => null]);

        self::assertSame(DomainDashboardView::SSL_NONE, $view->sslState);
        self::assertSame('Bind a domain to view SSL status', $view->sslLabel);
        self::assertSame(DomainDashboardView::LEVEL_NOTE, $view->sslLevel);
        self::assertNull($view->ssl);
    }

    /* -------------------------- DNS / delegation --------------------------- */

    public function testDnsOkMapsTheRequirementsDomainToJsonSafeRows(): void
    {
        $view = $this->view([
            'dns' => new DomainDnsResult(true, null, new Domain('9', 'name/', 'hns', true, 'waiting_delegation', 'gw.example.com')),
        ]);

        self::assertSame(DomainDashboardView::DNS_OK, $view->dnsState);
        self::assertNotNull($view->dnsDomain);
        self::assertSame('9', $view->dnsDomain['id']);
        self::assertSame('name/', $view->dnsDomain['domain']);
        self::assertSame('hns', $view->dnsDomain['namespace']);
        self::assertTrue($view->dnsDomain['dns_hosting_enabled']);
        self::assertSame('waiting_delegation', $view->dnsDomain['status']);
        self::assertSame('gw.example.com', $view->dnsDomain['gateway_host']);
        self::assertSame('Publish the delegation records below', $view->dnsLabel);
        self::assertSame(DomainDashboardView::LEVEL_WARNING, $view->dnsLevel);
    }

    public function testDnsConfirmedWhenTheBoundDomainIsActive(): void
    {
        $view = $this->view([
            'dns' => new DomainDnsResult(true, null, new Domain('9', 'name/', 'hns', true, 'active', 'gw.example.com')),
        ]);

        self::assertSame(DomainDashboardView::DNS_OK, $view->dnsState);
        self::assertSame('DNS delegation confirmed', $view->dnsLabel);
        self::assertSame(DomainDashboardView::LEVEL_OK, $view->dnsLevel);
    }

    public function testOnchainManagedCountsAsDnsConfirmed(): void
    {
        // On-chain managed HNS is verified via its namespace token, needing no
        // delegation wait — so the label/level mirror the CLI's
        // domainStatusIsValid (both 'active' and 'onchain_managed' are valid).
        foreach (['active', 'onchain_managed'] as $status) {
            $view = $this->view([
                'dns' => new DomainDnsResult(true, null, new Domain('9', 'name/', 'hns', true, $status, 'gw.example.com')),
            ]);

            self::assertSame('DNS delegation confirmed', $view->dnsLabel, $status);
            self::assertSame(DomainDashboardView::LEVEL_OK, $view->dnsLevel, $status);
        }
    }

    public function testDnsSerializationCarriesTheNestedDelegationBundleAndChecks(): void
    {
        $domain = new Domain(
            '9',
            'name/',
            'hns',
            true,
            'waiting_delegation',
            'gw.example.com',
            // The delegation guidance the DNS route carries: nameservers,
            // DNSSEC state and the parent/authoritative records the operator
            // must publish.
            DelegationInfo::fromArray([
                'mode' => 'inline',
                'nameservers' => ['ns1.pinner.xyz', 'ns2.pinner.xyz'],
                'dnssec' => 'secure',
                'dnssec_error' => null,
                'parent_records' => [
                    ['type' => 'NS', 'value' => 'ns1.pinner.xyz,ns2.pinner.xyz'],
                    ['type' => 'DS', 'value' => '12345 8 2 ABCDEF', 'address' => '1.2.3.4'],
                ],
                'authoritative_records' => [
                    ['type' => 'TLSA', 'value' => '3 1 1 xyz', 'ns' => 'name/'],
                ],
            ]),
            // The server-computed per-record checks.
            [
                CheckInfo::fromArray([
                    'name' => 'dnslink',
                    'ok' => false,
                    'message' => '',
                    'expected' => 'dnslink=/ipns/k-ipns-7',
                    'found' => '',
                ]),
            ],
        );

        $view = $this->view(['dns' => new DomainDnsResult(true, null, $domain)]);

        self::assertNotNull($view->dnsDomain);
        $delegation = $view->dnsDomain['delegation'];
        self::assertIsArray($delegation);
        self::assertSame('inline', $delegation['mode']);
        self::assertSame(['ns1.pinner.xyz', 'ns2.pinner.xyz'], $delegation['nameservers']);
        self::assertSame('secure', $delegation['dnssec']);
        self::assertNull($delegation['dnssec_error']);
        self::assertCount(2, $delegation['parent_records']);
        self::assertSame('NS', $delegation['parent_records'][0]['type']);
        self::assertSame('ns1.pinner.xyz,ns2.pinner.xyz', $delegation['parent_records'][0]['value']);
        self::assertNull($delegation['parent_records'][0]['address']);
        self::assertSame('DS', $delegation['parent_records'][1]['type']);
        self::assertSame('12345 8 2 ABCDEF', $delegation['parent_records'][1]['value']);
        self::assertSame('1.2.3.4', $delegation['parent_records'][1]['address']);
        self::assertCount(1, $delegation['authoritative_records']);
        self::assertSame('TLSA', $delegation['authoritative_records'][0]['type']);
        self::assertSame('name/', $delegation['authoritative_records'][0]['ns']);

        self::assertCount(1, $view->dnsDomain['checks']);
        self::assertSame('dnslink', $view->dnsDomain['checks'][0]['name']);
        self::assertFalse($view->dnsDomain['checks'][0]['ok']);
        self::assertSame('dnslink=/ipns/k-ipns-7', $view->dnsDomain['checks'][0]['expected']);
        self::assertSame('', $view->dnsDomain['checks'][0]['found']);

        // The whole view stays JSON-safe even with the nested bundle.
        self::assertJson((string) json_encode($view->toArray()));
    }

    public function testDnsDelegationIsNullAndChecksEmptyWhenAbsent(): void
    {
        $view = $this->view([
            'dns' => new DomainDnsResult(true, null, new Domain('9', 'name/', 'hns', true, 'waiting_delegation', 'gw.example.com')),
        ]);

        // The serialized domain always carries the keys so the client can tell
        // "not present" apart from a real (empty) delegation bundle.
        self::assertNotNull($view->dnsDomain);
        self::assertNull($view->dnsDomain['delegation']);
        self::assertSame([], $view->dnsDomain['checks']);
    }

    public function testValidateActionAvailabilityTracksTheSelectedDomain(): void
    {
        self::assertTrue($this->view()->canValidate);
        self::assertFalse($this->view(['selected' => false])->canValidate);
        self::assertFalse($this->view(['hasWebsite' => false])->canValidate);
        self::assertFalse($this->view(['envComplete' => false])->canValidate);
    }

    public function testDnsRefusedMapsToTheTypedRefusalBlock(): void
    {
        $view = $this->view([
            'dns' => new DomainDnsResult(false, DomainRefusal::InvalidDomainId),
        ]);

        self::assertSame(DomainDashboardView::DNS_REFUSED, $view->dnsState);
        self::assertSame('Select a bound domain.', $view->dnsLabel);
        self::assertSame(DomainDashboardView::LEVEL_WARNING, $view->dnsLevel);
        self::assertNull($view->dnsDomain);
    }

    public function testDnsStaysQuietWithoutASelectedDomain(): void
    {
        $view = $this->view(['selected' => false, 'dns' => null]);

        self::assertSame(DomainDashboardView::DNS_NONE, $view->dnsState);
        self::assertSame('Bind a domain to view DNS delegation', $view->dnsLabel);
        self::assertSame(DomainDashboardView::LEVEL_NOTE, $view->dnsLevel);
        self::assertNull($view->dnsDomain);
    }

    /* ------------------------ malformed / refusal states ------------------- */

    public function testEveryRefusalCasePinsAFixedLabelAndLevel(): void
    {
        $cases = [
            [DomainRefusal::EnvIdentityMissing, 'Environment configuration is incomplete.', DomainDashboardView::LEVEL_ERROR],
            [DomainRefusal::IdentityMissing, 'Publish your site to begin managing domains.', DomainDashboardView::LEVEL_NOTE],
            [DomainRefusal::InvalidDomain, 'Enter a valid domain name.', DomainDashboardView::LEVEL_ERROR],
            [DomainRefusal::InvalidNamespace, 'Choose ICANN or HNS.', DomainDashboardView::LEVEL_ERROR],
            [DomainRefusal::InvalidDomainId, 'Select a bound domain.', DomainDashboardView::LEVEL_WARNING],
            [DomainRefusal::InvalidLabel, 'Enter a label for the platform subdomain.', DomainDashboardView::LEVEL_ERROR],
            [DomainRefusal::RequestFailed, 'Pinner could not be reached. Please try again.', DomainDashboardView::LEVEL_ERROR],
        ];

        foreach ($cases as [$refusal, $label, $level]) {
            $block = DomainDashboardView::refusalInfo($refusal);
            self::assertSame($refusal->value, $block['code'], (string) $refusal->value);
            self::assertSame($label, $block['label'], (string) $refusal->value);
            self::assertSame($level, $block['level'], (string) $refusal->value);
        }
    }

    public function testRefusalBlocksAreJsonSafeAndNeverEchoInternalDetails(): void
    {
        $view = $this->view([
            'list' => new DomainListResult(false, DomainRefusal::RequestFailed),
            'ssl' => new DomainSslResult(false, DomainRefusal::RequestFailed),
            'dns' => new DomainDnsResult(false, DomainRefusal::RequestFailed),
        ]);

        $json = (string) json_encode($view->toArray());

        // The typed refusal codes are the fixed JSON-safe values; the labels are
        // fixed operator-facing text that never carries a wrapped exception
        // message or credential.
        self::assertStringContainsString('request_failed', $json);
        self::assertStringNotContainsString('Exception', $json);
        self::assertStringNotContainsString('super-secret', $json);
        self::assertStringNotContainsString('api-key', $json);
    }

    public function testToArrayIsACompleteJsonSafeSnapshot(): void
    {
        $view = $this->view();

        $payload = $view->toArray();

        self::assertSame(DomainDashboardView::STATE_READY, $payload['state']);
        self::assertSame('Choose and manage your domain', $payload['state_label']);
        self::assertTrue($payload['can_bind']);
        self::assertTrue($payload['can_verify']);
        self::assertTrue($payload['can_validate']);
        self::assertTrue($payload['can_delete']);
        self::assertSame(DomainDashboardView::LIST_OK, $payload['list_status']);
        self::assertCount(1, $payload['domains']);
        self::assertNull($payload['list_refusal']);
        self::assertSame(DomainDashboardView::SSL_READY, $payload['ssl_state']);
        self::assertSame(['status' => 'ready'], $payload['ssl']);
        self::assertSame(DomainDashboardView::DNS_OK, $payload['dns_state']);
        self::assertSame('99', $payload['dns_domain']['id']);
        self::assertJson((string) json_encode($payload));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function view(array $overrides = []): DomainDashboardView
    {
        $defaults = [
            'envComplete' => true,
            'onboardingComplete' => true,
            'hasWebsite' => true,
            'selected' => true,
            'list' => new DomainListResult(true, null, [$this->icannDomain()]),
            'ssl' => new DomainSslResult(true, null, ['status' => 'ready']),
            'dns' => new DomainDnsResult(true, null, $this->icannDomain()),
        ];
        $merged = array_replace($defaults, $overrides);

        return DomainDashboardView::fromState(
            envComplete: (bool) $merged['envComplete'],
            onboardingComplete: (bool) $merged['onboardingComplete'],
            hasWebsite: (bool) $merged['hasWebsite'],
            selected: (bool) $merged['selected'],
            list: $merged['list'],
            ssl: $merged['ssl'],
            dns: $merged['dns'],
        );
    }

    private function icannDomain(): Domain
    {
        return new Domain('99', 'site.example.test', 'icann', false, 'pending', null);
    }
}
