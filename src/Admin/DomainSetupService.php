<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use LumeWeb\Cast\Environment\EnvIdentity;
use LumeWeb\Cast\Jobs\IdentityGateway;
use LumeWeb\Cast\Publish\Domain;
use LumeWeb\Cast\Publish\DomainClient;
use LumeWeb\Cast\Publish\DomainClientException;

/**
 * Admin domain-setup service backing the choose-a-domain step.
 *
 * Every operation first requires a complete deployment identity ({@see
 * EnvIdentity}), then — for the site-locked operations (list/bind/DNS/verify/
 * delete/SSL) — an existing website identity whose website id is ALWAYS
 * derived from {@see IdentityGateway::current()?->websiteId}, never from a
 * client-supplied parameter. The pre-bind catalog reads
 * ({@see listPlatformDomains()}, {@see checkPlatformAvailability()}) need only
 * the env identity: they answer before any binding exists.
 *
 * Refusals are strictly side-effect free (the DomainClient is never called) and
 * a {@see DomainClientException} maps to the typed request_failed refusal
 * without ever echoing the wrapped message, so credentials cannot leak through
 * a payload. Successful operations return JSON-safe DTOs.
 */
final class DomainSetupService
{
    /**
     * @var array<string, true>
     */
    private const NAMESPACES = [
        'icann' => true,
        'hns' => true,
    ];

    public function __construct(
        private readonly EnvIdentity $env,
        private readonly IdentityGateway $identity,
        private readonly DomainClient $domains,
    ) {
    }

    public function list(): DomainListResult
    {
        $websiteId = $this->websiteIdForSiteLocked();
        if ($websiteId === null) {
            return new DomainListResult(false, $this->siteRefusal());
        }

        try {
            $domains = $this->domains->list($websiteId);
        } catch (DomainClientException) {
            return new DomainListResult(false, DomainRefusal::RequestFailed);
        }

        return new DomainListResult(true, null, $domains);
    }

    public function bind(string $domain, string $namespace): DomainBindResult
    {
        $websiteId = $this->websiteIdForSiteLocked();
        if ($websiteId === null) {
            return new DomainBindResult(false, $this->siteRefusal());
        }

        if (trim($domain) === '') {
            return new DomainBindResult(false, DomainRefusal::InvalidDomain);
        }

        if (!isset(self::NAMESPACES[$namespace])) {
            return new DomainBindResult(false, DomainRefusal::InvalidNamespace);
        }

        try {
            $bound = $this->domains->bind($websiteId, $domain, $namespace);
        } catch (DomainClientException) {
            return new DomainBindResult(false, DomainRefusal::RequestFailed);
        }

        return new DomainBindResult(true, null, $bound);
    }

    public function dnsRequirements(string $domainId): DomainDnsResult
    {
        $websiteId = $this->websiteIdForSiteLocked();
        if ($websiteId === null) {
            return new DomainDnsResult(false, $this->siteRefusal());
        }

        if (trim($domainId) === '') {
            return new DomainDnsResult(false, DomainRefusal::InvalidDomainId);
        }

        try {
            $domain = $this->domains->dnsRequirements($websiteId, $domainId);
        } catch (DomainClientException) {
            return new DomainDnsResult(false, DomainRefusal::RequestFailed);
        }

        return new DomainDnsResult(true, null, $domain);
    }

    public function verify(string $domainId): DomainVerifyResult
    {
        $websiteId = $this->websiteIdForSiteLocked();
        if ($websiteId === null) {
            return new DomainVerifyResult(false, $this->siteRefusal());
        }

        if (trim($domainId) === '') {
            return new DomainVerifyResult(false, DomainRefusal::InvalidDomainId);
        }

        try {
            $domain = $this->domains->verify($websiteId, $domainId);
        } catch (DomainClientException) {
            return new DomainVerifyResult(false, DomainRefusal::RequestFailed);
        }

        return new DomainVerifyResult(true, null, $domain);
    }

    /**
     * Runs a DNS validation check against the registered website
     * (POST /api/websites/{id}/validate) and returns the server-computed
     * per-record checks. The website id is always derived from the identity
     * gateway — never from a client-supplied parameter — and the rules are
     * identical to the other site-locked operations (env identity, then an
     * existing website identity). A {@see DomainClientException} maps to the
     * typed request_failed refusal without ever echoing the wrapped message,
     * so credentials cannot leak through a payload.
     */
    public function validate(): DomainValidateResult
    {
        $websiteId = $this->websiteIdForSiteLocked();
        if ($websiteId === null) {
            return new DomainValidateResult(false, $this->siteRefusal());
        }

        try {
            $validation = $this->domains->validate($websiteId);
        } catch (DomainClientException) {
            return new DomainValidateResult(false, DomainRefusal::RequestFailed);
        }

        return new DomainValidateResult(true, null, $validation);
    }

    public function delete(string $domainId): DomainDeleteResult
    {
        $websiteId = $this->websiteIdForSiteLocked();
        if ($websiteId === null) {
            return new DomainDeleteResult(false, $this->siteRefusal());
        }

        if (trim($domainId) === '') {
            return new DomainDeleteResult(false, DomainRefusal::InvalidDomainId);
        }

        try {
            $this->domains->delete($websiteId, $domainId);
        } catch (DomainClientException) {
            return new DomainDeleteResult(false, DomainRefusal::RequestFailed);
        }

        return new DomainDeleteResult(true, null, $domainId);
    }

    public function listPlatformDomains(): DomainPlatformListResult
    {
        if (!$this->env->isComplete()) {
            return new DomainPlatformListResult(false, DomainRefusal::EnvIdentityMissing);
        }

        try {
            $platformDomains = $this->domains->listPlatformDomains();
        } catch (DomainClientException) {
            return new DomainPlatformListResult(false, DomainRefusal::RequestFailed);
        }

        return new DomainPlatformListResult(true, null, $platformDomains);
    }

    public function checkPlatformAvailability(string $label): DomainAvailabilityResult
    {
        if (!$this->env->isComplete()) {
            return new DomainAvailabilityResult(false, DomainRefusal::EnvIdentityMissing);
        }

        if (trim($label) === '') {
            return new DomainAvailabilityResult(false, DomainRefusal::InvalidLabel);
        }

        try {
            $availability = $this->domains->checkPlatformDomainAvailability($label);
        } catch (DomainClientException) {
            return new DomainAvailabilityResult(false, DomainRefusal::RequestFailed);
        }

        return new DomainAvailabilityResult(true, null, $availability);
    }

    /**
     * Assembles the choose-a-domain panel view in a single call for the
     * publish dashboard render.
     *
     * Lists the website's domains (refusing side-effect-free when the env or
     * website identity is missing), then — only when the explicitly selected
     * destination domain is among the bound domains — reads the SSL status and
     * the DNS delegation requirements scoped to THAT domain so the panel can
     * show the delegation and certificate copy without a second round trip.
     * The first listed domain is never adopted implicitly: without a
     * destination domain (or when none of the bound domains matches it) the
     * panel carries no selected domain, no SSL/DNS read and no domain-scoped
     * actions — the destination/created binding, not `domains[0]`, identifies
     * the domain the workflow manages.
     *
     * The onboarding terminal flag lets the panel tell "finish onboarding"
     * apart from "your site is not published yet" — the two setup states have
     * different next steps, and the service is fed the fact by the caller (the
     * publish subscriber already resolves onboarding from the wizard store).
     */
    public function dashboard(bool $onboardingComplete = true, ?string $destinationDomain = null): DomainDashboardView
    {
        $envComplete = $this->env->isComplete();
        $hasWebsite = $this->identity->current() !== null;

        $list = $this->list();

        $ssl = null;
        $dns = null;
        $selected = false;

        $selectedDomain = $this->selectDestinationDomain($list->domains, $destinationDomain);
        if ($selectedDomain !== null) {
            $selected = true;
            $ssl = $this->sslStatus($selectedDomain->domain);
            $dns = $this->dnsRequirements($selectedDomain->id);
        }

        return DomainDashboardView::fromState(
            envComplete: $envComplete,
            onboardingComplete: $onboardingComplete,
            hasWebsite: $hasWebsite,
            selected: $selected,
            list: $list,
            ssl: $ssl,
            dns: $dns,
        );
    }

    /**
     * The bound domain the panel scopes its SSL/DNS reads to: the one whose
     * name matches the explicitly selected destination domain (domain names
     * compare case-insensitively). No destination domain, or no match among
     * the bound domains, yields null — there is deliberately NO fall-back to
     * the first listed domain, so an unrelated bound domain can never pose as
     * the workflow's domain.
     *
     * @param list<Domain> $domains
     */
    private function selectDestinationDomain(array $domains, ?string $destinationDomain): ?Domain
    {
        $destinationDomain = trim((string) $destinationDomain);
        if ($destinationDomain === '') {
            return null;
        }

        foreach ($domains as $domain) {
            if (strcasecmp($domain->domain, $destinationDomain) === 0) {
                return $domain;
            }
        }

        return null;
    }

    public function sslStatus(string $domain): DomainSslResult
    {
        $websiteId = $this->websiteIdForSiteLocked();
        if ($websiteId === null) {
            return new DomainSslResult(false, $this->siteRefusal());
        }

        if (trim($domain) === '') {
            return new DomainSslResult(false, DomainRefusal::InvalidDomain);
        }

        try {
            $ssl = $this->domains->sslStatus($domain);
        } catch (DomainClientException) {
            return new DomainSslResult(false, DomainRefusal::RequestFailed);
        }

        return new DomainSslResult(true, null, $ssl === null ? null : [
            'status' => $ssl->status(),
            'issued_at' => $ssl->issuedAt(),
            'last_updated_at' => $ssl->lastUpdatedAt(),
            'error' => $ssl->error(),
        ]);
    }

    /**
     * The site-locked operations need the registered website identity; the
     * website id is always derived from IdentityGateway::current()?->websiteId.
     * Returns null when the env identity is incomplete or no website identity
     * exists, so the caller can map to the matching refusal.
     */
    private function websiteIdForSiteLocked(): ?string
    {
        if (!$this->env->isComplete()) {
            return null;
        }

        return $this->identity->current()?->websiteId;
    }

    /**
     * The outer precondition failure for a site-locked operation: which
     * refusal the caller should surface depends on whether the env identity is
     * the missing check (checked first) or the website identity.
     */
    private function siteRefusal(): DomainRefusal
    {
        if (!$this->env->isComplete()) {
            return DomainRefusal::EnvIdentityMissing;
        }

        return DomainRefusal::IdentityMissing;
    }
}
