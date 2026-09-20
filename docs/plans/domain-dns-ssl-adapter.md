# Domain / DNS / SSL Adapter — Report

Status: implemented, unit-tested, static checks green
Scope: `LumeWeb\Cast\Ipfs\IpfsDomainsClient` + its typed DTOs on the shared
PSR-18 transport, mapping the `ipfs-sdk` WebsitesService domain-binding surface.

---

## 1. Exact endpoints

`IpfsDomainsClient` is constructed with `(HttpTransport $transport, string $baseUrl, string $apiKey)`.
Base URL is the portal root (e.g. `https://portal.pinner.xyz`); every path segment is `rawurlencode`d.

| ipfs-sdk method         | HTTP   | Endpoint                                                            | Local method                     |
|-------------------------|--------|---------------------------------------------------------------------|----------------------------------|
| ListDomains             | GET    | `/api/websites/{websiteId}/domains`                                 | `listDomains`                    |
| BindDomain              | POST   | `/api/websites/{websiteId}/domains`                                 | `addDomain`                      |
| UpdateDomain            | PATCH  | `/api/websites/{websiteId}/domains/{domainId}`                      | `updateDomain`                   |
| UnbindDomain            | DELETE | `/api/websites/{websiteId}/domains/{domainId}`                      | `deleteDomain`                   |
| VerifyDomain            | POST   | `/api/websites/{websiteId}/domains/{domainId}/verify`               | `verifyDomain`                   |
| GetDomainDNSRequirements| GET    | `/api/websites/{websiteId}/domains/{domainId}/dns-requirements`     | `dnsRequirements`                |
| RepublishDANE           | POST   | `/api/websites/{websiteId}/domains/{domainId}/dane/republish`       | `republishDane`                  |
| ConvertDomainToOnChain  | POST   | `/api/websites/{websiteId}/domains/{domainId}/onchain`              | `convertToOnchain`               |
| ListPlatformDomains     | GET    | `/api/websites/platform-domains`                                    | `listPlatformDomains`            |
| CheckPlatformDomainAvailability | GET | `/api/websites/platform-domains/availability?label={label}`  | `checkPlatformDomainAvailability` |
| GetSSLStatus            | GET    | `/api/websites/{domain}/ssl-status`                                 | `sslStatus`                      |

Wire conventions (locked by tests):

- Read verbs (GET/DELETE) send `Authorization: Bearer {apiKey}` + `Accept: application/json` only — no `Content-Type`.
- Write verbs (POST/PATCH) additionally send `Content-Type: application/json`.
- The API key only ever appears on the wire; exceptions never include it.
- JSON body encoding uses `JSON_UNESCAPED_SLASHES` (HNS domains like `name/` stay unescaped) and `JSON_FORCE_OBJECT` (empty PATCH encodes as `{}`).
- Non-2xx → `UnexpectedStatusCodeException` (status preserved, no secret). Malformed/missing payload → `ResponseDecodingException`. Transport failure → `TransportException` (typed `HttpException` family).

Exact request bodies:

- `addDomain` — `WebsiteDomainRequest`:
  - ICANN minimal: `{"domain":"example.com","namespace":"icann"}`
  - HNS portal-managed: `{"domain":"name/","namespace":"hns","dns_hosting_enabled":true}`
  - HNS self-managed: `{"domain":"name/","namespace":"hns","dns_hosting_enabled":false}`
  - HNS, DNS-hosting flag unspecified (omitted): `{"domain":"name/","namespace":"hns"}`
  - One-click platform subdomain: `{"domain":"label.pinner.xyz","namespace":"icann","label":"label","platform_domain":"pinner.xyz","platform_namespace":"icann"}`
  - Server-generated label: `{"domain":"name/","namespace":"hns","generate":true}`
  - Namespaces validated client-side: `icann` | `hns` (anything else → `InvalidArgumentException`).
- `updateDomain` — `DomainUpdateRequest` (`dnsHostingEnabled`, `primary`): unset fields omitted; both unset → `{}`. Example: `{"dns_hosting_enabled":false,"primary":true}`.
- `verifyDomain` / `republishDane` / `convertToOnchain`: POST with empty body.
- `deleteDomain`: expects 204 No Content.
- `checkPlatformDomainAvailability('')`: query parameter omitted entirely; non-empty labels are percent-encoded (`my site/ü` → `label=my%20site%2F%C3%BC`).
- `sslStatus`: parses the `ssl` block of the website domain response; returns `null` when absent.

## 2. Response DTOs

`WebsiteDomain` (id, domain, namespace, dnsHostingEnabled, status, gatewayHost, ownerName, tlsaRdata, zoneName, delegation, checks, ssl)
— with nested `DelegationInfo` (mode, nameservers, dnssec, dnssecError, parentRecords, authoritativeRecords), `DnsRecord` (type, value, address, ns),
`CheckInfo` (name, ok, message, expected, found), `SslStatusInfo` (status, issuedAt, lastUpdatedAt, error);
`DaneRepublish` (id, domain, namespace, publishedToManagedZone, tlsaRdata, status);
`PlatformDomain` (id, domain, namespace, zoneId, enabled);
`PlatformAvailability` (label, results) with `PlatformAvailabilityResult` (platformDomain, namespace, available).
Request payloads: `WebsiteDomainRequest`, `DomainUpdateRequest`. All immutable, `fromArray()` typed-decode with exact error messages.

## 3. Tests

- New: `tests/Unit/Ipfs/IpfsDomainsClientTest.php` — **48 tests / 212 assertions**, all pass. Uses `RecordingTransport`
  (Guzzle `MockHandler` + a recording middleware) to assert exact method, path, query, headers and bodies with no network.
- Full unit suite: **835 tests / 2888 assertions, 0 failures, 2 skipped** (pre-existing skips) — `vendor/bin/phpunit`.

Coverage: exact method/path/query/header/body per endpoint; percent-encoding of path segments (`w/1` → `w%2F1`, `d.2` → `d.2`);
query encoding; `{data,total}` envelope; missing-`data` and malformed-JSON decoding errors; typed `TransportException` /
`UnexpectedStatusCodeException` that never leak the bearer key; ICANN + HNS namespaces; portal-managed / self-managed /
one-click / generate bind variants; full `DomainResponse` mapping (inline + non-inline delegation, DS/NS/glue/TLSA/TXT
records, DNSSEC state + error, checks, SSL block); DANE republish; on-chain conversion; platform-domain availability
(with and without label); SSL-status null path.

## 4. Static checks

- `php -l` over all 188 files under `src/`: clean (exit 0).
- PHPStan 2.2, **level 8** (`src`, `tests`, `cast.php`): **no errors**.
- PHPCS PSR-12 (`src tests` via `.phpcs.xml.dist`): **clean** (exit 0).
- Note: the `composer lint` script itself refuses to run as root in this sandbox; the equivalent
  `find src -name '*.php' | xargs php -l` ran clean.

## 5. Unsupported local SDK operations

The local SDK is deliberately minimal. Within the ipfs-sdk WebsitesService domain/DNS/SSL area these are **not** ported:

1. `Websites.ValidateDNS` (`POST /api/websites/{id}/validate`) — not needed by Cast flows; per-binding `verifyDomain` covers revalidation.
2. `Websites.GetConfig` (`GET /api/websites/config`) — platform config catalog, unused.
3. Internal/admin endpoints (not public portal API): `UpdateSSLStatusInternal`
   (`POST /internal/websites/{domain}/ssl-status`), `GetGatewayWebsite` (`GET /internal/websites/{domain}`),
   `GetGatewayWebsiteStatus` (`GET /internal/websites/{domain}/status`), `ReconcileWebsiteChanges`
   (`GET /internal/websites/changes`).
4. Go client-side polling conveniences `WaitForSSLStatusReady` / `WaitForWebsiteStatus` / `WaitForDNSValidation` —
   local `UploadResultPoller` exists for uploads only; domain/SSL health is polled by callers via `sslStatus`/`verifyDomain`.
5. Broader ipfs-sdk services not ported at all: DNS admin service (`dns.go` zone/record/TLSA-cert ops — the portal manages
   zones for users through the domain endpoints), pinning service (`internal/pinning`), IPNS lifecycle beyond
   `CreateKey`/`Publish` (`ListKeys`/`GetKey`/`DeleteKey`/`Republish`/`Resolve`/`WaitForIPNSResolution`), websites event
   streaming (`websites_events.go`), and website `List`/`Get`/`Delete` (only `Create`/`Update` are ported in
   `IpfsWebsitesClient`).

Notable behavior differences vs the Go SDK:

- Go's `ConvertDomainToOnChain` tolerates a 422 "already on-chain" and returns current state; the local adapter surfaces
  422 as `UnexpectedStatusCodeException` (tests lock this in; callers decide).
- Go's `encoding/json` does not escape `/`; PHP mirrors this with `JSON_UNESCAPED_SLASHES`, and `JSON_FORCE_OBJECT` so an
  empty PATCH body is `{}`.
- Go packs a retry/poll layer; the local transport is single-shot PSR-18 — callers own retries.
