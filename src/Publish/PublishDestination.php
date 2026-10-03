<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * Immutable first-publish address intent. It is deliberately independent from
 * the WordPress origin host: only an explicit user choice can define a
 * website address — a platform generation request, a platform label, a custom
 * domain, or an existing website id. No factory or reader accepts, derives,
 * or defaults from an origin/build hostname.
 *
 * Wire-contract authority: pinner-cli `internal/cli/websites_wizard.go`:
 * - platform: managed DNS (`dns_hosting_enabled: true`), selected
 *   `platform_domain`/`platform_namespace`, and exactly one of
 *   `generate: true` or `label`; never a custom `domain`/`namespace`.
 * - custom: explicit `domain`, `namespace` (icann|hns), and an explicit
 *   `dns_hosting_enabled: true|false`; never platform/generate/label fields.
 * - existing: a `website_id` only; no create, the site is attached.
 *
 * Impossible field combinations are rejected (factory throws / fromArray
 * returns null) instead of being silently normalized.
 */
final class PublishDestination
{
    /** @var list<string> */
    public const CUSTOM_NAMESPACES = ['icann', 'hns'];

    private function __construct(
        public readonly PublishDestinationSource $source,
        public readonly ?string $domain = null,
        public readonly ?string $namespace = null,
        public readonly bool $dnsHostingEnabled = true,
        public readonly ?string $platformDomain = null,
        public readonly ?string $platformNamespace = null,
        public readonly bool $generate = false,
        public readonly ?string $label = null,
        public readonly ?string $websiteId = null,
    ) {
    }

    /**
     * Platform (free) subdomain where the platform mints the label.
     */
    public static function platformGenerated(?string $platformDomain = null, ?string $platformNamespace = null): self
    {
        return new self(
            source: PublishDestinationSource::Platform,
            platformDomain: self::nullableTrim($platformDomain),
            platformNamespace: self::nullableTrim($platformNamespace),
            generate: true,
        );
    }

    /**
     * Platform (free) subdomain with a user-chosen label. Exactly one of
     * generate/label is legal; this factory owns the label variant.
     */
    public static function platformLabelled(string $label, ?string $platformDomain = null, ?string $platformNamespace = null): self
    {
        $label = trim($label);
        if ($label === '') {
            throw new \InvalidArgumentException('Platform label destinations require a non-empty label.');
        }

        return new self(
            source: PublishDestinationSource::Platform,
            platformDomain: self::nullableTrim($platformDomain),
            platformNamespace: self::nullableTrim($platformNamespace),
            label: $label,
        );
    }

    public static function custom(string $domain, string $namespace, bool $dnsHostingEnabled): self
    {
        $domain = trim($domain);
        $namespace = trim($namespace);
        if ($domain === '' || $namespace === '') {
            throw new \InvalidArgumentException('Custom publish destinations require a domain and namespace.');
        }

        if (!in_array($namespace, self::CUSTOM_NAMESPACES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Custom publish destinations require an icann or hns namespace, got "%s".',
                $namespace,
            ));
        }

        return new self(
            source: PublishDestinationSource::Custom,
            domain: $domain,
            namespace: $namespace,
            dnsHostingEnabled: $dnsHostingEnabled,
        );
    }

    public static function existing(string $websiteId): self
    {
        $websiteId = trim($websiteId);
        if ($websiteId === '') {
            throw new \InvalidArgumentException('Existing publish destinations require a website id.');
        }

        return new self(source: PublishDestinationSource::Existing, websiteId: $websiteId);
    }

    /** @return array<string, scalar|null> */
    public function toArray(): array
    {
        return [
            'source' => $this->source->value,
            'domain' => $this->domain,
            'namespace' => $this->namespace,
            'dns_hosting_enabled' => $this->dnsHostingEnabled,
            'platform_domain' => $this->platformDomain,
            'platform_namespace' => $this->platformNamespace,
            'generate' => $this->generate,
            'label' => $this->label,
            'website_id' => $this->websiteId,
        ];
    }

    /**
     * Rebuild a destination from stored data. Returns null for absent or
     * corrupt input (unknown source, missing required fields, or an
     * impossible combination such as a platform source carrying a custom
     * domain), so a caller can treat "cannot read" as "no destination".
     *
     * @param mixed $value
     */
    public static function fromArray(mixed $value): ?self
    {
        if (!is_array($value) || !is_string($value['source'] ?? null)) {
            return null;
        }

        return match (PublishDestinationSource::tryFrom($value['source'])) {
            PublishDestinationSource::Platform => self::platformFrom($value),
            PublishDestinationSource::Custom => self::customFrom($value),
            PublishDestinationSource::Existing => self::existingFrom($value),
            null => null,
        };
    }

    /** @param array<string, mixed> $value */
    private static function platformFrom(array $value): ?self
    {
        // Platform subdomains are DNS-managed by the platform, always.
        if (($value['dns_hosting_enabled'] ?? null) !== true) {
            return null;
        }
        // A platform source never carries a custom domain/namespace.
        if (($value['domain'] ?? null) !== null || ($value['namespace'] ?? null) !== null) {
            return null;
        }

        $generate = $value['generate'] ?? null;
        $label = self::nullableTrim(self::stringOrNull($value['label'] ?? null));

        // Exactly one of generate/label is a legal claim.
        if ($generate === true) {
            return $label === null ? new self(
                source: PublishDestinationSource::Platform,
                platformDomain: self::nullableTrim(self::stringOrNull($value['platform_domain'] ?? null)),
                platformNamespace: self::nullableTrim(self::stringOrNull($value['platform_namespace'] ?? null)),
                generate: true,
            ) : null;
        }

        if ($label !== null) {
            return new self(
                source: PublishDestinationSource::Platform,
                platformDomain: self::nullableTrim(self::stringOrNull($value['platform_domain'] ?? null)),
                platformNamespace: self::nullableTrim(self::stringOrNull($value['platform_namespace'] ?? null)),
                label: $label,
            );
        }

        return null;
    }

    /** @param array<string, mixed> $value */
    private static function customFrom(array $value): ?self
    {
        // A custom source never carries platform/label/website fields. A
        // stored `generate: false` is the absent value, not a platform claim.
        if (
            ($value['platform_domain'] ?? null) !== null
            || ($value['platform_namespace'] ?? null) !== null
            || (($value['generate'] ?? null) !== null && ($value['generate'] ?? null) !== false)
            || self::presentString($value['label'] ?? null)
            || self::presentString($value['website_id'] ?? null)
        ) {
            return null;
        }

        $domain = self::stringOrNull($value['domain'] ?? null);
        $namespace = self::stringOrNull($value['namespace'] ?? null);
        $dnsHostingEnabled = $value['dns_hosting_enabled'] ?? null;

        if ($domain === null || trim($domain) === '' || $namespace === null || $dnsHostingEnabled === null) {
            return null;
        }

        try {
            return self::custom($domain, $namespace, $dnsHostingEnabled === true);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @param array<string, mixed> $value */
    private static function existingFrom(array $value): ?self
    {
        // An existing site is referenced by id only: no address fields.
        if (
            self::presentString($value['domain'] ?? null)
            || self::presentString($value['namespace'] ?? null)
            || self::presentString($value['platform_domain'] ?? null)
            || self::presentString($value['platform_namespace'] ?? null)
            || (($value['generate'] ?? null) !== null && ($value['generate'] ?? null) !== false)
            || self::presentString($value['label'] ?? null)
        ) {
            return null;
        }

        $websiteId = self::stringOrNull($value['website_id'] ?? null);
        if ($websiteId === null || trim($websiteId) === '') {
            return null;
        }

        return self::existing($websiteId);
    }

    private static function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @param mixed $value */
    private static function presentString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /** @param mixed $value */
    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
