<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Environment;

/**
 * Derivation of the two portal API service endpoints from ONE canonical base URL.
 *
 * The deployment contract sets `PORTAL_API_URL` to the canonical portal root
 * (e.g. `https://pinner.xyz`) and Cast derives the service subdomains instead of
 * configuring an account host and an IPFS host separately:
 *
 *   - account/auth base: `https://account.pinner.xyz` — GetAccount (/api/account)
 *     and the /api/auth/* exchange endpoints, which only accept a login-purpose
 *     auth key (see PortalAuthKeyClient / PortalAccountClient);
 *   - IPFS/workspace/publish API base: `https://ipfs.pinner.xyz` — websites,
 *     uploads, IPNS, domain and workspace-resolve endpoints (the portal-plugin-ipfs
 *     surface).
 *
 * The canonical base is parsed — never blindly string-replaced — and validated
 * with the same conventions as the existing URL handling: absolute http(s),
 * non-empty host, no userinfo credentials. Non-default ports and any path are
 * preserved on every derived endpoint; a query/fragment are dropped because a
 * canonical base URL must never carry them (the clients append `/api/...` paths).
 * IP-literal hosts (IPv4/IPv6) cannot take a service subdomain, so both derived
 * endpoints fall back to the canonical base and IP-based local stacks keep both
 * services on the one host.
 */
final class PortalEndpoints
{
    private const ACCOUNT_LABEL = 'account';
    private const IPFS_LABEL = 'ipfs';

    private function __construct(
        private readonly string $scheme,
        private readonly string $host,
        private readonly ?int $port,
        private readonly string $path,
    ) {
    }

    /**
     * Parse and validate a canonical portal base URL into the two derived
     * service endpoints.
     *
     * Returns null when the value is not an absolute http(s) URL with a host,
     * or when it embeds credentials in the userinfo component (a base URL must
     * never carry credentials to the HTTP client).
     */
    public static function parse(string $value): ?self
    {
        $url = trim($value);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = $parts['host'] ?? null;
        if (!is_string($host) || $host === '') {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        // Trailing slash trimmed; query/fragment deliberately ignored.
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return new self($scheme, $host, $port, $path);
    }

    /**
     * The canonical portal base URL, normalized (trailing slash stripped,
     * query/fragment dropped).
     */
    public function canonicalBaseUrl(): string
    {
        return $this->scheme . '://' . $this->authority() . $this->path;
    }

    /**
     * The account/auth service base URL derived from the canonical base
     * (e.g. `https://account.pinner.xyz`).
     */
    public function accountBaseUrl(): string
    {
        return $this->withSubdomain(self::ACCOUNT_LABEL);
    }

    /**
     * The IPFS/workspace/publish API service base URL derived from the
     * canonical base (e.g. `https://ipfs.pinner.xyz`).
     */
    public function ipfsBaseUrl(): string
    {
        return $this->withSubdomain(self::IPFS_LABEL);
    }

    private function withSubdomain(string $label): string
    {
        if ($this->isIpLiteralHost()) {
            // A service subdomain cannot be prepended to an IP-literal host
            // ([::1], 127.0.0.1, ...); fall back to the canonical base so
            // IP-based local/dev stacks keep both services on one host.
            return $this->canonicalBaseUrl();
        }

        return $this->scheme . '://' . $label . '.' . $this->host . $this->portSuffix() . $this->path;
    }

    private function authority(): string
    {
        return $this->host . $this->portSuffix();
    }

    private function portSuffix(): string
    {
        return $this->port === null ? '' : ':' . $this->port;
    }

    private function isIpLiteralHost(): bool
    {
        // parse_url keeps the bracketed form for IPv6 literals, e.g. '[::1]'.
        return str_starts_with($this->host, '[')
            || preg_match('/^\d{1,3}(?:\.\d{1,3}){3}$/', $this->host) === 1;
    }
}
