<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Admin;

use LumeWeb\Cast\Ipfs\CheckInfo;
use LumeWeb\Cast\Ipfs\DelegationInfo;
use LumeWeb\Cast\Ipfs\DnsRecord;
use LumeWeb\Cast\Publish\Domain;

/**
 * The domain dashboard view model — a pure mapping from the typed, JSON-safe
 * domain result DTOs ({@see DomainListResult}, {@see DomainSslResult},
 * {@see DomainDnsResult}) plus the panel preconditions to the display/action
 * state the choose-a-domain panel renders.
 *
 * Every decision the template or scripts would otherwise re-derive from raw
 * result fields — panel readiness, which actions may be offered, the
 * list/SSL/DNS display states, and the human-safe label + level for a typed
 * {@see DomainRefusal} — is pinned here as a deliberate, tested mapping. The
 * view model holds no WordPress state and performs no side effects; the
 * template only iterates and escapes the JSON-safe arrays it produces. Refusal
 * reasons and credentials are never echoed: each refusal maps to a fixed code,
 * a fixed operator label and a fixed level.
 */
final class DomainDashboardView
{
    public const STATE_READY = 'ready';
    public const STATE_CONFIG = 'config';
    public const STATE_SETUP = 'setup';

    public const LEVEL_OK = 'ok';
    public const LEVEL_NOTE = 'note';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    public const LIST_OK = 'ok';
    public const LIST_EMPTY = 'empty';
    public const LIST_REFUSED = 'refused';

    public const SSL_READY = 'ready';
    public const SSL_PENDING = 'pending';
    public const SSL_FAILED = 'failed';
    public const SSL_NONE = 'none';
    public const SSL_REFUSED = 'refused';

    public const DNS_OK = 'ok';
    public const DNS_NONE = 'none';
    public const DNS_REFUSED = 'refused';

    public function __construct(
        public readonly string $state,
        public readonly string $stateLabel,
        public readonly string $stateLevel,
        /** Whether a website identity exists (a site has been published at least once). */
        public readonly bool $hasWebsite,
        public readonly bool $canBind,
        public readonly bool $canVerify,
        public readonly bool $canValidate,
        public readonly bool $canDelete,
        public readonly bool $canReadPlatform,
        public readonly bool $canCheckAvailability,
        public readonly bool $canReadDns,
        public readonly bool $canReadSsl,
        public readonly string $listStatus,
        /** @var list<array<string, mixed>> */
        public readonly array $domains,
        /** @var array{code: string, label: string, level: string}|null */
        public readonly ?array $listRefusal,
        public readonly string $sslState,
        public readonly string $sslLabel,
        public readonly string $sslLevel,
        /** @var array<string, mixed>|null */
        public readonly ?array $ssl,
        public readonly string $dnsState,
        public readonly string $dnsLabel,
        public readonly string $dnsLevel,
        /** @var array<string, mixed>|null */
        public readonly ?array $dnsDomain,
    ) {
    }

    /**
     * Builds the domain panel view from the service results and the current
     * panel preconditions. The preconditions mirror what the setup service
     * checks — a complete env identity, then an existing website identity —
     * and the booleans below are the same checks the service refuses on, so the
     * view and the service can never drift apart about which actions exist.
     */
    public static function fromState(
        bool $envComplete,
        bool $onboardingComplete,
        bool $hasWebsite,
        bool $selected,
        DomainListResult $list,
        ?DomainSslResult $ssl = null,
        ?DomainDnsResult $dns = null,
    ): self {
        [$state, $stateLabel, $stateLevel] = self::panelStateFor($envComplete, $onboardingComplete, $hasWebsite);

        return new self(
            state: $state,
            stateLabel: $stateLabel,
            stateLevel: $stateLevel,
            hasWebsite: $hasWebsite,
            canBind: $envComplete && $hasWebsite,
            canVerify: self::canActOnSelectedDomain($envComplete, $hasWebsite, $selected),
            canValidate: self::canActOnSelectedDomain($envComplete, $hasWebsite, $selected),
            canDelete: self::canActOnSelectedDomain($envComplete, $hasWebsite, $selected),
            canReadPlatform: $envComplete,
            canCheckAvailability: $envComplete,
            canReadDns: self::canActOnSelectedDomain($envComplete, $hasWebsite, $selected),
            canReadSsl: self::canActOnSelectedDomain($envComplete, $hasWebsite, $selected),
            listStatus: self::listStatusFor($list),
            domains: array_map(self::serializeDomain(...), $list->domains),
            listRefusal: $list->refusal === null ? null : self::refusalInfo($list->refusal),
            sslState: self::sslStateFor($selected, $ssl),
            sslLabel: self::sslLabelFor($hasWebsite, $selected, $ssl),
            sslLevel: self::sslLevelFor($hasWebsite, $selected, $ssl),
            ssl: $ssl?->ok === true ? $ssl->ssl : null,
            dnsState: self::dnsStateFor($selected, $dns),
            dnsLabel: self::dnsLabelFor($hasWebsite, $selected, $dns),
            dnsLevel: self::dnsLevelFor($hasWebsite, $selected, $dns),
            dnsDomain: $dns?->ok === true && $dns->domain !== null ? self::serializeDomain($dns->domain) : null,
        );
    }

    /**
     * The human-safe display block for a typed refusal: the fixed JSON-safe
     * code, a fixed operator label and a fixed level. The label never echoes a
     * wrapped exception message or a credential.
     *
     * @return array{code: string, label: string, level: string}
     */
    public static function refusalInfo(DomainRefusal $refusal): array
    {
        return match ($refusal) {
            DomainRefusal::EnvIdentityMissing => [
                'code' => $refusal->value,
                'label' => 'Environment configuration is incomplete.',
                'level' => self::LEVEL_ERROR,
            ],
            DomainRefusal::IdentityMissing => [
                'code' => $refusal->value,
                'label' => 'Publish your site to begin managing domains.',
                'level' => self::LEVEL_NOTE,
            ],
            DomainRefusal::InvalidDomain => [
                'code' => $refusal->value,
                'label' => 'Enter a valid domain name.',
                'level' => self::LEVEL_ERROR,
            ],
            DomainRefusal::InvalidNamespace => [
                'code' => $refusal->value,
                'label' => 'Choose ICANN or HNS.',
                'level' => self::LEVEL_ERROR,
            ],
            DomainRefusal::InvalidDomainId => [
                'code' => $refusal->value,
                'label' => 'Select a bound domain.',
                'level' => self::LEVEL_WARNING,
            ],
            DomainRefusal::InvalidLabel => [
                'code' => $refusal->value,
                'label' => 'Enter a label for the platform subdomain.',
                'level' => self::LEVEL_ERROR,
            ],
            DomainRefusal::RequestFailed => [
                'code' => $refusal->value,
                'label' => 'Pinner could not be reached. Please try again.',
                'level' => self::LEVEL_ERROR,
            ],
        };
    }

    /**
     * The full JSON-safe snapshot the template/scripts render: every display
     * and action decision plus the raw-but-JSON-safe DTO blocks, with no
     * secret ever added by this mapping.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'state_label' => $this->stateLabel,
            'state_level' => $this->stateLevel,
            'can_bind' => $this->canBind,
            'can_verify' => $this->canVerify,
            'can_validate' => $this->canValidate,
            'can_delete' => $this->canDelete,
            'can_read_platform' => $this->canReadPlatform,
            'can_check_availability' => $this->canCheckAvailability,
            'can_read_dns' => $this->canReadDns,
            'can_read_ssl' => $this->canReadSsl,
            'list_status' => $this->listStatus,
            'domains' => $this->domains,
            'list_refusal' => $this->listRefusal,
            'ssl_state' => $this->sslState,
            'ssl_label' => $this->sslLabel,
            'ssl_level' => $this->sslLevel,
            'ssl' => $this->ssl,
            'dns_state' => $this->dnsState,
            'dns_label' => $this->dnsLabel,
            'dns_level' => $this->dnsLevel,
            'dns_domain' => $this->dnsDomain,
        ];
    }

    /**
     * The panel header state. The setup branch distinguishes onboarding from a
     * published site: with onboarding unfinished the copy still points at the
     * wizard, while onboarding-complete-but-never-published points at the
     * first publish (the website identity is only ever created on publish).
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function panelStateFor(bool $envComplete, bool $onboardingComplete, bool $hasWebsite): array
    {
        if (!$envComplete) {
            return [self::STATE_CONFIG, 'Environment configuration required', self::LEVEL_ERROR];
        }

        if (!$onboardingComplete) {
            return [self::STATE_SETUP, 'Complete onboarding to manage domains', self::LEVEL_NOTE];
        }

        if (!$hasWebsite) {
            return [self::STATE_SETUP, 'Publish your site to begin managing domains.', self::LEVEL_NOTE];
        }

        return [self::STATE_READY, 'Choose and manage your domain', self::LEVEL_OK];
    }

    private static function canActOnSelectedDomain(bool $envComplete, bool $hasWebsite, bool $selected): bool
    {
        return $envComplete && $hasWebsite && $selected;
    }

    private static function listStatusFor(DomainListResult $list): string
    {
        if (!$list->listed) {
            return self::LIST_REFUSED;
        }

        if ($list->domains === []) {
            return self::LIST_EMPTY;
        }

        return self::LIST_OK;
    }

    private static function sslStateFor(bool $selected, ?DomainSslResult $ssl): string
    {
        if (!$selected || $ssl === null) {
            return self::SSL_NONE;
        }

        if (!$ssl->ok) {
            return self::SSL_REFUSED;
        }

        if ($ssl->ssl === null) {
            return self::SSL_NONE;
        }

        return match ($ssl->ssl['status'] ?? '') {
            'ready' => self::SSL_READY,
            default => self::sslPendingOrFailed($ssl->ssl['status'] ?? ''),
        };
    }

    private static function sslPendingOrFailed(string $status): string
    {
        if (str_contains($status, 'fail') || str_contains($status, 'error')) {
            return self::SSL_FAILED;
        }

        if (str_contains($status, 'pending') || str_contains($status, 'issuing') || str_contains($status, 'issu')) {
            return self::SSL_PENDING;
        }

        return self::SSL_NONE;
    }

    private static function sslLabelFor(bool $hasWebsite, bool $selected, ?DomainSslResult $ssl): string
    {
        if (!$hasWebsite) {
            return 'Publish your site to begin managing domains.';
        }

        if (!$selected || $ssl === null) {
            // No bound domain exists to select yet — say so rather than imply
            // a domain can be picked.
            return 'Bind a domain to view SSL status';
        }

        if (!$ssl->ok) {
            return self::refusalInfo($ssl->refusal ?? DomainRefusal::RequestFailed)['label'];
        }

        if ($ssl->ssl === null) {
            return 'No SSL certificate issued yet';
        }

        return match ($ssl->ssl['status'] ?? '') {
            'ready' => 'SSL active',
            default => match (self::sslPendingOrFailed($ssl->ssl['status'] ?? '')) {
                self::SSL_FAILED => 'SSL certificate could not be issued',
                self::SSL_PENDING => 'SSL certificate is being issued',
                default => 'SSL status unknown',
            },
        };
    }

    private static function sslLevelFor(bool $hasWebsite, bool $selected, ?DomainSslResult $ssl): string
    {
        if (!$hasWebsite || !$selected || $ssl === null) {
            return self::LEVEL_NOTE;
        }

        if (!$ssl->ok) {
            return self::refusalInfo($ssl->refusal ?? DomainRefusal::RequestFailed)['level'];
        }

        if ($ssl->ssl === null) {
            return self::LEVEL_NOTE;
        }

        return match ($ssl->ssl['status'] ?? '') {
            'ready' => self::LEVEL_OK,
            default => match (self::sslPendingOrFailed($ssl->ssl['status'] ?? '')) {
                self::SSL_FAILED => self::LEVEL_ERROR,
                self::SSL_PENDING => self::LEVEL_WARNING,
                default => self::LEVEL_NOTE,
            },
        };
    }

    private static function dnsStateFor(bool $selected, ?DomainDnsResult $dns): string
    {
        if (!$selected || $dns === null) {
            return self::DNS_NONE;
        }

        if (!$dns->ok) {
            return self::DNS_REFUSED;
        }

        return self::DNS_OK;
    }

    private static function dnsLabelFor(bool $hasWebsite, bool $selected, ?DomainDnsResult $dns): string
    {
        if (!$hasWebsite) {
            return 'Publish your site to begin managing domains.';
        }

        if (!$selected || $dns === null) {
            // No bound domain exists to select yet — say so rather than imply
            // a domain can be picked.
            return 'Bind a domain to view DNS delegation';
        }

        if (!$dns->ok) {
            return self::refusalInfo($dns->refusal ?? DomainRefusal::RequestFailed)['label'];
        }

        if ($dns->domain === null) {
            return 'DNS delegation records';
        }

        // Both active and onchain_managed count as fully validated bindings
        // (on-chain managed HNS is verified via its namespace token, needing no
        // delegation wait), mirroring the CLI's domainStatusIsValid.
        return match ($dns->domain->status) {
            'active', 'onchain_managed' => 'DNS delegation confirmed',
            'waiting_delegation' => 'Publish the delegation records below',
            default => 'DNS delegation records',
        };
    }

    private static function dnsLevelFor(bool $hasWebsite, bool $selected, ?DomainDnsResult $dns): string
    {
        if (!$hasWebsite || !$selected || $dns === null) {
            return self::LEVEL_NOTE;
        }

        if (!$dns->ok) {
            return self::refusalInfo($dns->refusal ?? DomainRefusal::RequestFailed)['level'];
        }

        if ($dns->domain === null) {
            return self::LEVEL_NOTE;
        }

        return match ($dns->domain->status) {
            'active', 'onchain_managed' => self::LEVEL_OK,
            'waiting_delegation' => self::LEVEL_WARNING,
            default => self::LEVEL_NOTE,
        };
    }

    /**
     * The full JSON-safe serialization of a bound domain, shared by the panel
     * domain rows and the DNS/validation blocks so every surface renders the
     * same nested delegation bundle (mode, nameservers, DNSSEC state/error,
     * parent and authoritative records) and per-record checks. Only the fields
     * the DTO carries are emitted; a null/empty block serializes to null/[] so
     * the client can tell "not present" apart from a real value. No secret is
     * ever added by this mapping.
     *
     * @return array<string, mixed>
     */
    public static function serializeDomain(Domain $domain): array
    {
        return [
            'id' => $domain->id,
            'domain' => $domain->domain,
            'namespace' => $domain->namespace,
            'dns_hosting_enabled' => $domain->dnsHostingEnabled,
            'status' => $domain->status,
            'gateway_host' => $domain->gatewayHost,
            'delegation' => self::serializeDelegation($domain->delegation),
            'checks' => array_map(self::serializeCheck(...), $domain->checks),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function serializeDelegation(?DelegationInfo $delegation): ?array
    {
        if ($delegation === null) {
            return null;
        }

        return [
            'mode' => $delegation->mode(),
            'nameservers' => $delegation->nameservers(),
            'dnssec' => $delegation->dnssec(),
            'dnssec_error' => $delegation->dnssecError(),
            'parent_records' => array_map(self::serializeDnsRecord(...), $delegation->parentRecords()),
            'authoritative_records' => array_map(self::serializeDnsRecord(...), $delegation->authoritativeRecords()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeDnsRecord(DnsRecord $record): array
    {
        return [
            'type' => $record->type(),
            'value' => $record->value(),
            'address' => $record->address(),
            'ns' => $record->ns(),
        ];
    }

    /**
     * The JSON-safe serialization of one server-computed validation check,
     * shared by the domain DNS bundle and the website validate result so both
     * render the same "Publish this record" / "Found instead" rows.
     *
     * @return array<string, mixed>
     */
    public static function serializeCheck(CheckInfo $check): array
    {
        return [
            'name' => $check->name(),
            'ok' => $check->ok(),
            'message' => $check->message(),
            'expected' => $check->expected(),
            'found' => $check->found(),
        ];
    }
}
