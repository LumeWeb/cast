# Cast Export & Publish — Design and Plan

Status: draft for approval
Date: 2026-09-16
Scope: static export of the WordPress site to a ZIP, publishing that ZIP to the Lume portal (IPFS upload via the `ipfs-sdk` HTTP contract), the user-facing wizard that binds a domain (ICANN + Handshake namespaces, managed/self-managed/inline/on-chain modes), and the publish-trigger model — all backend mechanics, no CLI dependency for any user-facing flow.

---

## 1. Sources and non-goals

Input sources for this design:

- The uploaded disassembly (`disassembly/kernel/K0–K9`, `disassembly/catalogs/*`) — an algorithm spec distilled from five WordPress static exporters. It is a spec, not code. Licenses: nothing may be copied from the GPLv2+/GPLv3 donor plugins; the spec itself is written to be reimplemented.
- `~/projects/ipfs-sdk` (Go) — **both** the upload contract (`swagger.yaml`, `upload.go`) **and** the website/domain binding surface (`websites.go`, `dns.go`): create/update websites, add domains, `dns-requirements`, verify, platform-domain availability, SSL status. Module `go.lumeweb.com/ipfs-sdk`.
- `~/projects/portal-sdk` (Go) — auth/workspace surface only. Module `go.lumeweb.com/portal-sdk`.
- `~/projects/pinner-cli` (Go) — the UX reference for the domains wizard: `internal/cli/websites_domains_wizard_pterm.go`, `internal/cli/websites_domains_delegation_{hns,icann,generic,common}.go`, `dns_guidance.go`. Its interactive flows are ported here as REST-driven wizard steps, not as CLI.
- `~/projects/php-portal-sdk` — **ignored**, per decision. It is an empty scaffold.
- Cast itself: PHP 8.2, PSR-4 (`LumeWeb\Cast\`), PSR-12, ComposePress + `yohang/finite`, PHPUnit 11.5, PHPStan 2.2. Onboarding already has a wizard built on one global option + finite state machine; the setup wizard below extends that pattern rather than inventing a second one.

Non-goals, explicitly deferred:

- Behavioral cloning of any donor plugin. v1 **does** implement the K0–K9 crawler/export invariants and must pass every supplied catalog test vector; optional destination modes, plugin-specific convenience crawlers, and non-portal deployers may land later.
- Deployers other than the portal (S3, GitHub, directory targets).
- Search, form handling, JS execution, logins, cart/checkout flows. v1 is public anonymous GET only.
- A full port of the Go SDKs. Only the API surface Cast calls is ported.
- WP-CLI as a user-facing flow. It remains a power-user/debug convenience, kept thin, never required.

## 2. Architecture

Three bounded components, each independently testable:

```
Admin UI (wizard, status, buttons)  ──▶  REST endpoints  ──▶  Export\JobRunner (WP-Cron ticks)
                                        │                        │
                                        ▼                        ▼
                              Export\Pipeline (probe/setup/discover/capture/rewrite/pack)
                                        │
                                        ▼
                              Publish\Publisher ──▶ Ipfs\(Uploader, TusUploader, Websites) ──▶ portal
                                        │
                                        ▼
                              Portal\PortalClient (workspace access token only)
```

Namespaces under `src/`, all PSR-4:

- `LumeWeb\Cast\Export\` — pipeline, stages, work items, job runner, packager.
- `LumeWeb\Cast\Publish\` — publish orchestration: route POST vs TUS, poll result, record CID, website target update.
- `LumeWeb\Cast\Ipfs\` — upload client (multipart + TUS + result polling) and the minimal websites/domains surface used by the wizard.
- `LumeWeb\Cast\Portal\` — minimal portal client: workspace access token retrieval only.
- `LumeWeb\Cast\Admin\` — wizard steps, status dashboard, REST handlers (extends the existing onboarding admin layer).

Boundaries:

- Stage logic never knows about WP-Cron or REST; runners drive stages. The SDKs never touch WordPress functions. `Publish\Publisher` is the only place the two worlds meet.
- All SDK calls go through small interfaces (`UploaderInterface`, `WebsitesClientInterface`, `PortalClientInterface`) so tests inject fakes and a transport swap is local.
- Wizard steps are stateless services behind a finite state machine (same pattern as onboarding); WordPress REST is only transport.

### SDK minimality

| SDK | What Cast needs | What is ported |
|---|---|---|
| ipfs-sdk | Upload: `POST /api/upload` (multipart, `?archive=&name=`), `POST /api/upload/tus` + HEAD/PATCH, `GET /api/upload/result/{id}`, bearer auth, 307/308 redirect handling, status enum `completed\|duplicate\|failed\|pending\|processing`. Websites/domains: list/create/update website — **created only after a CID exists** (`domain` is optional at creation: `WebsiteRequest{TargetHash, TargetType, Label, Generate}`), list/add/delete domain, `dns-requirements`, `verify`, platform-domain availability, SSL status. IPNS: `CreateKey` + `Publish(key, cid)` for the mutable pointer | Two clients, one shared HTTP core. No pinning-list/DNS-admin/workspace-admin/event streams. |
| portal-sdk | Workspaces.Resolve (`GET /api/workspaces/resolve?resource_uuid=...`) over the `PORTAL_API_KEY` bearer returning the workspace + attached Website publish relationship | One `WorkspaceResolveClient::resolve()` call in `PortalFacade`. Nothing else. |

**Identity is environment-provided, not user-entered.** The plugin is deployed (Coolify) with the portal API key, portal URL and the portal-injected resource UUID in env vars. At bootstrap the plugin reads those, calls `GetAccount` (portal-sdk) and then `Workspaces.Resolve?resource_uuid=<COOLIFY_RESOURCE_UUID>` (over the SAME `PORTAL_API_KEY` bearer) to self-identify the account and THE attached workspace — never the fragile exactly-one `Workspaces.List` behavior. `PORTAL_API_KEY` is already the workspace-scoped JWT bearer the portal injects; `Workspaces.Access` proxy Basic Auth (`WORKSPACE_AUTH_USERNAME`/`PASSWORD`) is a memory-held deployment reference for workspace public routes and must NEVER be converted into a portal API bearer. No connection screen asks the user for a URL or key unless the environment is missing — and then the wizard only surfaces a plain-language error telling the operator which env var is absent. Credential handling in WordPress is deliberately zero: nothing to enter, nothing to store, nothing to leak through the WP database.

Constants mirrored from the Go SDK, kept in one place (`Ipfs\Contract`):

- `UPLOAD_LIMIT = 100 MiB` — size at or under it uses multipart POST; above it uses TUS.
- `MAX_REDIRECT_HOPS = 5` on the POST path.
- Domain namespaces: `icann`, `hns`; website target types as in `WebsiteRequest`.

## 3. Dependencies

Added to `composer.json` require:

| Package | Version | Why | License |
|---|---|---|---|
| `psr/http-client` | ^1.0 | SDK boundary typing | MIT |
| `psr/http-message` | ^2.0 (or 1.1) | PSR-7 | MIT |
| `psr/http-factory` | ^1.1 | Request/Stream factories | MIT |
| `guzzlehttp/guzzle` | ^7.15 | PSR-18 transport; pinned 7.x because tus-php requires `^7.2` | MIT |
| `guzzlehttp/psr7` | ^2.13 | PSR-7/17 impl | MIT |
| `ankitpokhrel/tus-php` | ^2.4 | The only maintained, established PHP TUS client | MIT |

All MIT. The vendor tree ships unscoped: a PHP-Scoper/scoped release build is a deliberate non-goal (scope decision, §15), so cross-plugin collision risk is contained with the `LumeWeb\Cast\` PSR-4 prefix and pinned dependency versions rather than prefixing, and is tracked in §17.

Zero new runtime deps for the Export side: `ZipArchive` (ext-zip) and the WP HTTP API cover it.

## 4. The export pipeline

Stage list is immutable per run, snapshotted at start (K0): `probe, setup, discover, capture, rewrite, pack, wrapup`.

State machine via `yohang/finite` on the run record (same library the plugin already uses for onboarding):

```
not_started → running → completed | completed_with_warnings | failed
running → paused → running
running → cancelled
```

Stage transitions are a second, internal axis: each stage `pending → in_progress → done | failed`, recorded on the run record, so a resume never redoes a `done` stage and never skips the zip (post-setup stages cannot be skipped by changed settings — settings are snapshotted).

### 4.1 Work item model (K1)

Custom table `{$wpdb->prefix}cast_export_items` (uniqueness + conditional claims cannot be done with options):

```sql
id BIGINT UNSIGNED PK AUTO_INCREMENT,
url_hash CHAR(32) NOT NULL UNIQUE,      -- md5(canonical url)
url TEXT NOT NULL,
path VARCHAR(500) NOT NULL,
filename TEXT NULL,                     -- abs disk path when copied from disk
kind ENUM('page','asset','redirect','text') NOT NULL,
priority TINYINT NOT NULL DEFAULT 10,   -- feeds 1, pages 10, assets 11
status ENUM('queued','processing','done','failed','skipped') NOT NULL DEFAULT 'queued',
http_status SMALLINT NULL,
redirect_to TEXT NULL,
content_hash CHAR(32) NULL,
file_path VARCHAR(500) NULL,            -- relative path in work dir
fetch_attempts TINYINT NOT NULL DEFAULT 0,
last_checked_at DATETIME NULL,
found_on BIGINT UNSIGNED NULL,
error_message TEXT NULL
```

Canonicalization rules (K1, verbatim in intent):

- Strip fragment, lowercase scheme/host, decode over-encoded `%25`.
- Asset extensions: query dropped for identity (`?ver=` is a cache-buster). Kept on the GET if needed; stored without it.
- Pages with query strings are first-class: identity keeps the query; storage path is `__qs/{md5(query)[0:12]}/index.html` so `?s=foo` never overwrites the pretty URL.
- Reject before insert: non-http(s), `javascript:`, `data:`, `mailto:`, `tel:`, empty, NUL/backslash/C0, userinfo in URL. Non-WP-origin hosts are never inserted.

### 4.2 Probe (K2)

Probe is a hard gate before queue construction; it exercises the same public-anonymous route the crawler will use.

Preflight without HTTP:

- Pretty permalinks required (`permalink_structure` non-empty).
- PHP XML/DOM and ext-zip present; uploads/work-dir parent writable.
- Loopback access is anonymous by contract: the deployment's Caddy reverse proxy bypasses HTTP Basic Auth on the plugin's own loopback requests to the export origin, so probe and capture never source, store or attach credentials. A guarded origin surfaces as a safe 401/403 loopback access failure, never credential advice.

Request: `wp_remote_get(home_url('/'))` anonymously — an `Authorization` header is never attached, no credentials are sourced from deployment config or the incoming admin request. Timeout 15s, redirects 0, no cookies, `Accept-Encoding: identity`, dedicated probe UA. TLS verification is disabled only for an exact local origin (`localhost`, loopback, `*.test`, `*.localhost`, or WP local environment); never globally.

Decision handling:

- DNS/cURL/TLS errors: abort with a specific operator action.
- 401/403: abort as a safe loopback access failure naming the status — no credential advice (probe and capture are anonymous by contract).
- Canonical slash or HTTP→HTTPS origin redirect: accept and snapshot canonical origin. Any off-origin redirect: abort.
- Any other non-200: abort.
- Body under 1 KiB or missing `<html`: abort as an empty/maintenance/cache shell (Wrangler ghost guard applied before the queue).

Store probe duration, bytes, canonical origin, and status on the run. A failed probe inserts zero work items.

### 4.3 Discover (K3): three routes into one deduplicated queue

The crawler solves coverage by combining three sources rather than trusting any one plugin's preferred route:

1. **WordPress-aware seeders** find content HTML cannot reliably expose.
2. **HTML/CSS/JSON extraction** follows what the rendered public site actually references.
3. **Bounded disk discovery** captures generated builder/theme/plugin assets that pages may not link directly.

All sources call one `WorkItemRepository::insertCanonical()` function, deduped by `url_hash`; first-seen wins except that lower numeric priority may replace priority. Same-origin and exclusion policy runs before insertion. Queue data is scalars only — never serialized PHP task objects.

#### Seeders

- Home, static front page, posts page, `robots.txt`, sitemap candidates, and existing well-known files (`llms.txt`, `_redirects`, `_headers`, `favicon.ico`).
- Published posts/pages/public CPTs via keyset query `ID > cursor ORDER BY ID LIMIT 50`; never `posts_per_page=-1`. Attachments and builder templates are opt-in.
- Public CPT archives and blog/archive pagination using `$wp_rewrite->pagination_base` and computed `max_num_pages` (not translated labels or scraped "next" links).
- Public terms (default on for full-site), authors with published posts (opt-in), date archives (off by default). Pagination joins use `trailingslashit`.
- Selected-ID mode runs only selected permalinks + optional home; following their links is explicit (`follow_selected_links`) so the scope is predictable.
- Extra URL literals/regexes (same-origin, capped) and extra filesystem paths.

#### Sitemaps

Detect Yoast → Rank Math → SEOPress → AIOSEO → core/default candidates, plus `Sitemap:` entries in `robots.txt`. Fetch origin-only with redirects 0 and the probe TLS policy. Reject DOCTYPE/entities, parse with `LIBXML_NONET`, cap body at 5 MiB, nesting at 5, and URL count at 250,000. Enqueue **both** every local `<loc>` and each sitemap document; this explicitly fixes StaticWeb's parse-then-discard gap.

#### Bounded disk discovery

- Actual stylesheet + template directories, active plugin directories, uploads, and only safe `wp-includes/{css,js,fonts,images,blocks}` subsets.
- Builder-generated locations are registered as bounded paths: Elementor `uploads/elementor` + plugin assets, Beaver Builder cache, Divi `et-cache`. They use the same extra-path machinery instead of bespoke mutation of builder settings.
- Directory walks process at most 500 entries or 10 seconds per cursor, then persist the cursor. Asset extension allowlist only; skip PHP/phtml, `.git`, `node_modules`, executable vendor paths, and the export work directory.
- Every path is raw-decoded once, rejects encoded separators/dot segments/NUL/control/backslash, then passes the most-specific `realpath` jail. Never walk all of `ABSPATH` (Static Snap failure mode).

#### Fixed-point crawler route

After seeding begins, each worker tick repeats:

```text
resume seeder/directory cursors
→ atomically claim queued items by priority
→ disk-copy local assets or HTTP-capture public documents
→ rewrite captured text inline
→ extract and canonicalize newly found local URLs
→ insert-or-ignore queue rows
→ repeat until seeders exhausted AND no queued/retryable rows remain
```

HTML follows pages **and** assets by default. URL extraction uses the K5 tag map, not a narrow `a/img/script` regex; CSS `url()`/`@import`, srcset, inline styles, script/JSON URL values, iframes, objects, media and SVG references participate. Relative URLs resolve with RFC 3986 semantics against the document URL (CSS against the CSS file URL). Hard URL cap is 200,000 by default (filterable); reaching it finishes `completed_with_warnings`, not an infinite crawl.

Default enqueue exclusions: admin/login/XML-RPC, feeds unless enabled, REST unless enabled, previews/customizer/nonces, logout/add-to-cart/WooCommerce action URLs. Prefix/exact/regex rules are typed — no loose substring matching such as `feed` matching `feedback`.

### 4.4 Capture (K4)

Every item is atomically claimed with an attempt increment; competing ticks cannot own it. Maximum 3 attempts, exponential delay `min(120s, 5s × 2^(attempt-1))`, watchdog reclaim for dead workers.

Capture path:

1. Reject non-origin HTTP before making a request (SSRF gate).
2. For a local asset, resolve via the K8 path jail and copy disk→temporary file; MIME from `finfo`, `wp_check_filetype`, then static map. Missing local file falls back to HTTP.
3. For rendered content, `wp_remote_get` the public URL anonymously — never Basic Auth, no `Authorization` header (same Caddy loopback bypass the probe uses): 30s timeout (clamped 5–60), redirects 0, stream to a temporary file, no cookies, verified TLS except local, `Accept-Encoding: identity`.
4. A 200/zero-byte response retries local copy, then one non-streamed GET; still empty fails. HTML under 1 KiB or without `<html` fails the ghost guard and is never packed.
5. 301/302/303/307/308: resolve Location against request URL. Queue local target. Canonical slash/scheme twins write no stub; true page moves write a tiny meta-refresh+link page for offline ZIP. Asset redirects queue the target without HTML. Off-origin targets are recorded but not fetched.
6. Random 404: fail and delete stale incremental output; a dedicated 404 item may become `404.html`. Never save 403/500 bodies (StaticWeb failure mode).
7. Write to deterministic path only after validation: extensionless pages → `{path}/index.html`; query pages → `__qs/{hash12}/index.html`; fixed files retain names. Atomic temp rename/copy fallback. Store content hash for future incremental skip.

Rewrite/extraction runs inline in bounded groups (up to 200 captured text files) so newly discovered URLs enter the queue while the crawl is active. Bodies live on disk, not in the database or a run-sized array. A sequential worker is correct; an optional HTTP window of four is a later optimization, never required for correctness.

### 4.5 Rewrite (K5): offline artifact correctness

`offline-zip` is the v1 destination mode. The portal extracts the ZIP and may mount it under any path, so root-relative URLs are insufficient.

Core conversion:

- Resolve relative/protocol-relative URL against its source document; preserve fragment-only and non-web schemes.
- Same-origin URLs are queued, then converted. External URLs remain unchanged and unqueued.
- Pretty URLs map to path-preserving `{path}/index.html`.
- Compute `./`/`../` from current output file to target output file; append `index.html` to extensionless targets. Assets retain the WordPress path tree. Never flatten or basename assets (Wrangler/Recorp collision and CSS-depth failure).
- Page query strings retain identity through `__qs/{hash12}`; asset cache-buster queries do not create files.

Content handlers:

- **HTML:** preserve scripts, comments, `<xmp>`, SVG data URIs, conditional comments and attribute entities before DOM parsing; repair HTML5 container issues around DOMDocument. Rewrite the full filterable tag map (`href/src/srcset/imagesrcset/poster/action/formaction/data-*`, iframe/embed/object/media/SVG/use/image, inline style). Restore preserved blocks after serialization.
- **srcset:** descriptor-aware splitter that does not split Cloudinary transform commas; rewrite each local candidate while preserving `800w`/`2x`.
- **CSS:** rewrite `url()` and quoted/URL `@import`, skip data URIs, resolve against the CSS file URL; preserve quote style. Convert Elementor numeric HTML-entity icon content to CSS escapes.
- **JS / JSON-in-script:** rewrite absolute, protocol-relative, root-relative known WP asset paths, JSON-escaped URLs, import-map keys/values, sourceMappingURL/sourceURL. Decode valid JSON, walk URL-looking values/keys where applicable, re-encode valid JSON; regex is fallback. HTML and JS use the same destination mode.
- **XML/JSON/text:** rewrite sitemap/feed URL fields, escaped and percent-encoded origin forms, and `Sitemap:` lines without global host smashing.
- **Binary:** never rewritten.

Final encoded pass handles JSON-escaped and percent-encoded origin URLs at URL boundaries. Remaining origin occurrences are recorded with file/sample in `manifest.leftover_origin`; offline mode never uses a raw host `str_replace`, because it cannot calculate per-file depth and may corrupt scripts/JSON.

K9 runs on the rewritten copy only: strip WordPress generator/RSD/WLW/shortlink/REST/oEmbed/emoji/embed fingerprint tags and local resource hints. Preserve canonical, Open Graph, Twitter, description, robots, hreflang, JSON-LD, prev/next, stylesheets/preloads/icons. The live WordPress response is never mutated.

### 4.6 Pack (K6)

Pack runs only after the crawler reaches its fixed point and the §4.7 pre-pack validation gate passes.

1. Snapshot file list from the work dir. Path jail: every file's `realpath` must be under the work-dir realpath; violations are skipped with warnings.
2. Optional export sitemap `sitemap.xml` generated from files that actually exist (homepage 1.0, depth 0 → 0.8, depth 1 → 0.6, else 0.4; `lastmod` = mtime).
3. `ZipArchive` opened at `{uploads}/cast-exports/{run_id}.zip` (a denied sibling dir, never inside the work dir). `CREATE|OVERWRITE`, ZIP64 on.
4. Files added in batches of `ZIP_BATCH = 2500` (filterable, clamped 1–10000). Every 1000 additions: progress update on the run row.
5. `manifest.json` written next to the zip (and inside it): `run_id`, `origin`, destination mode, started/finished, counts (`queued, fetched, copied, redirected, skipped, failed`), `failed[] {url, error, attempts}`, `leftover_origin[]`, probe stats, settings snapshot. Written **after** every other file exists — zip is always last.
6. `leftover_origin` non-empty ⇒ run ends `completed_with_warnings`, never "clean".
7. Zero files found ⇒ write the 22-byte EOCD empty zip **and** a manifest with all items failed — still a valid, inspectable artifact, not a crash.
8. Missing ext-zip ⇒ hard fail with "PHP zip extension required". No silent PclZip fallback on large sites.
9. `{uploads}/cast-exports/` gets `index.php` + `.htaccess` deny files like the work dir.

### 4.7 Artifact validation gate and wrap-up

Before §4.6 opens the ZIP, run a deterministic validation pass over the completed work tree:

- Root `index.html` exists and is non-ghost HTML.
- Every queue item is terminal (`done|failed|skipped`); seed and directory cursors are exhausted; no retry is due. This is the crawler's fixed-point completion test.
- Every rewritten local HTML/CSS reference resolves to a file in the work tree, a known redirect page, or an intentional skipped/failed record. Broken-reference samples go into the manifest with source item IDs.
- No two canonical work items wrote the same output path; query-page hashing and asset-query dropping are checked for collisions.
- Archive paths are relative, normalized `/`, contain no `..`, and remain inside the work-dir jail.
- Scan textual output for the origin forms (plain, protocol-relative, JSON-escaped, percent-encoded). Leftovers produce `completed_with_warnings`; strict mode may block publish while still producing a diagnostic ZIP.

Then delete the jailed work directory (jail-checked delete) after ZIP+manifest close and integrity checks (`ZipArchive::open`, minimum 22 bytes, expected entry count). Keep the ZIP + manifest per retention (§12). Release locks. Publish (if requested) proceeds only after validation; a failed validation never creates/updates a website target.

### 4.8 Crawler-route design verification

The plan was checked line-by-line against K0–K9, the comparison catalog, rejection catalog, and all provided test vectors. This verifies the **design**, not yet an implementation. The crawler route has no known missing architecture step:

| Combined lesson | Adopted | Explicitly rejected |
|---|---|---|
| Simply Static | Public HTTP pages + jailed disk assets; broad tag map; query identity; encoding-aware/offline rewrite; ZIP64 batches; bounded named tasks | Queue objects; default force-host replacement; URL truncation |
| StaticWeb Deploy | Named stages, streaming/cursor iterators, first-seen dedupe, content hashes | `posts_per_page=-1`, sitemap `<loc>` discard, query ban, TLS-off, host swap as the only rewrite |
| Static Cache Wrangler | `<1 KiB`/missing-HTML ghost guard; safe fingerprint stripping in the copy | Organic-traffic/output-buffer capture, public cache tree, basename flattening, live `wp_head` mutation |
| Static Snap | Hybrid idea: rendered pages over HTTP, existing assets from disk | Full `ABSPATH` walk, TLS-off, capture cookie, zip-before-final-files, root-relative-only rewrite |
| Recorp | Probe before queue; keyset seeding; short ticks, retries, watchdog, admin progress | Theme-less fallback, minted users/cookies, public export directory, three racing runners |

#### Why the crawler converges

- **Coverage:** WP seeders + sitemap entries + rendered-link extraction + bounded local asset walks cover routes missed by any single source. Builder-generated asset directories enter through the same jailed extra-path mechanism.
- **Identity:** one canonical URL function strips fragments, keeps page queries, drops asset cache-busters, and hashes the full canonical URL; all discovery paths dedupe through it.
- **Safety:** only exact WordPress origins are fetched; all file reads/writes are jailed; no crawler cookies, users, JS execution, or blanket TLS disabling.
- **Termination:** keyset and directory cursors are finite; attempts and backoff are bounded; sitemap depth/body/count and total URL count are capped; completion requires exhausted producers plus an empty retryable queue.
- **Portable output:** all local references use per-file offline path math, source-tree paths are preserved, redirects get offline stubs, and local-link validation runs before packaging.
- **Resume:** queue rows, stage/seeder/directory cursors, item claims, hashes, attempts, and next-attempt timestamps are persisted. A dead tick can be reclaimed without restarting the crawl.

#### Test-vector closure required before calling implementation solved

All cases in `catalogs/test-vectors.md` become named PHPUnit datasets. Release is blocked until these groups pass:

- K1 identity: long/page queries, asset versions, fragments, schemes/userinfo.
- K2 probe: TLS prod/local, auth, canonical/off-site redirects, ghost body, plain permalinks.
- K3 discovery: sitemap `<loc>` enqueue, 100k-post keyset behavior, author/page joins, localized pagination base, selected-link policy, builder CSS extra paths.
- K4 capture: disk-copy/fallback, redirect variants, 404/403/empty/ghost responses, pretty/fixed paths, SSRF denial.
- K5 rewrite: sibling/home/deep asset path math, Cloudinary srcset commas, Elementor JSON/icon CSS, CSS-relative/data SVG, protocol-relative/hash/percent forms, JS/HTML mode agreement, leftover warning.
- K6/K8: empty ZIP, archive jail, leftovers, dot-dot/encoded separators/NUL/symlink/auth-cross-origin.

Known product limitations are now bounded rather than accidental: client-side-only routes/assets injected after JavaScript execution, authenticated pages, forms, search, carts, and infinite user-generated query graphs are not exported in v1. Their absence must appear in docs/manifest settings, not as crawler failures.

## 5. Publish workflow

The publish stage turns `{run_id}.zip` into a portal website upload and records the CID on the run record.

### 5.1 What the server expects

From the ipfs-sdk contract:

- `POST /api/upload?archive=<bool>&name=<name>` — multipart field `file`, bearer auth, follows 307/308 up to 5 hops. `archive=true` means the **server unpacks/processes the upload** into stored content; `archive=false` stores the raw bytes.
- `POST/HEAD/PATCH /api/upload/tus[/{id}]` — TUS creation (+ creation-with-upload), offset probe, chunk PATCH. `Upload-Metadata` carries `archive` and `name`.
- `GET /api/upload/result/{identifier}` — status: `completed|duplicate|failed|pending|processing`. Terminal: `completed` and `duplicate`; `failed` fails the publish; `pending|processing` poll with backoff.

**Decision — `archive` flag:** the ZIP is uploaded with `archive=true`, so the server extracts it and wraps it as a website directory; the result resolves to the site's directory CID. Uploading the ZIP as an opaque blob (`archive=false`) is out of v1 scope. Rationale: mirrors the Go SDK's archive example and the portal's website serving model.

**Ordering rule — websites come from uploads, never before.** A website is a pointer to pinned content; it cannot exist without a CID. The publish job therefore:

1. Uploads the ZIP (`archive=true`) → result CID.
2. **First publish only:** creates the website (`Create`/`CreateWithOptions`) with `Label` = site hostname, `TargetHash` = CID, `TargetType` = the upload type — **without a domain** (`domain` is optional on `WebsiteRequest`).
3. **IPNS:** on first publish, `IPNS.CreateKey` for the site and `Publish(key, cid)`; on every publish, re-`Publish` the new CID so the mutable name follows the site. (The website model itself carries `ipns_key_id`, so key-to-site association persists.)
4. **Subsequent publishes:** `Update(id, …, newCid, …)` so the bound domain serves the new content.
5. Polls website/SSL status (`WaitForWebsiteStatus`, `WaitForSSLStatusReady` semantics) with a deadline. The publish is not "done" until the target points at the new content — otherwise the user believes a deploy happened that didn't.

Domain binding (wizard, §6) always happens *after* the first publish, because it needs the website to exist.

### 5.2 Routing POST vs TUS

```
size <= 100 MiB  →  Uploader\PostUploader   (multipart, single request)
size  > 100 MiB  →  Uploader\TusUploader    (resumable, chunked)
```

### 5.3 Post path

- Multipart body built with `guzzlehttp/psr7` `MultipartStream`; file opened via `fopen` stream so PHP streams the file through the request.
- Query: `archive=true&name={run_id}.zip`. Header: `Authorization: Bearer …`.
- On 307/308: re-send same body to `Location`, max 5 hops, loop-detect on host+path.
- Success response JSON identifier → result polling (§5.5).

### 5.4 TUS path

`ankitpokhrel/tus-php` client, with two deviations that matter:

1. **Bounded memory.** Never `upload(-1)`. `createWithUpload()` for the first chunk, then a loop of `seek($offset)` + `upload($chunkBytes)` with an explicit chunk constant (**default 16 MiB**, filterable `cast_publish_tus_chunk_bytes`). Each PATCH reads only its window — peak memory ≈ chunk size + Guzzle overhead, not file size.
2. **Resume.** Persist `{run_id, key, upload_url, offset}` on the run row after every chunk. A crashed tick calls `getOffset()` (HEAD) before `create()`; if the server still knows the upload, it resumes; else it restarts. `Cancel` deletes the upload (`DELETE`).

Metadata on creation: `archive=true`, `name={run_id}.zip`. Bearer token on every request.

Failure policy: per-chunk retry with exponential backoff (3 attempts, 2s base), then fail the run `failed` with the offset preserved for resume.

### 5.5 Result polling

```
identifier = response identifier (or TUS upload id)
loop, status = GET /api/upload/result/{identifier}:
  completed | duplicate → success (record cid, size, dag_size, location)
  failed                → publish failed (message recorded)
  pending | processing  → sleep backoff (2s, ×1.5, cap 30s), re-poll
deadline: 15 min wall clock, filterable → publish failed as "processing timeout"
```

Polling runs inside the publish tick budget, yielding between polls, so a slow server never blocks a worker for 15 minutes in one go.

### 5.6 Publish job execution

Publish is a job with the same tick/lock model as export (§8) but simpler — no URL queue:

- Stages: `auth → route → upload (chunked) → poll → ensure-website (first run only) → ipns-publish → target update → record`.
- A single publish tick does: acquire lock → push next chunk(s) / next poll within budget → persist offset or status → yield if out of budget.
- Idempotency: the run row (`publish_status`, `publish_offset`, `publish_identifier`, `publish_cid`) makes any tick safe to repeat.

### 5.7 Auth: token lifecycle

Identity comes from the deployment environment, never from user input:

The deployment env (Coolify-injected, exact names as provisioned) carries:

| Var | Role |
|---|---|
| `PORTAL_API_URL` | Portal API base URL (e.g. `https://account.pinner.xyz:443`) |
| `PORTAL_API_KEY` | Account API key — the bearer credential for account/workspace identification (already the workspace-scoped JWT bearer the portal injects) |
| `COOLIFY_RESOURCE_UUID` | Portal-injected resource UUID identifying THE runtime container/workspace for `Workspaces.Resolve` |
| `PORTAL_WORKSPACE_URL` | This deployment's workspace URL (provided for loopback/reference context; replaces the unused `COOLIFY_URL`) |
| `WORKSPACE_AUTH_USERNAME` / `WORKSPACE_AUTH_PASSWORD` | Workspace-scoped proxy Basic Auth credentials for workspace public routes, never a portal API bearer (memory-held reference only) |

`PORTAL_API_KEY` authenticates the account call (`GetAccount`) and the runtime identity call (`Workspaces.Resolve?resource_uuid=...`, the SAME bearer). The workspace is pinned unambiguously by the resource UUID — no `Workspaces.List` auto-selection, no exactly-one fragility. `WORKSPACE_AUTH_USERNAME`/`PASSWORD` are an optional memory-held deployment reference for workspace public routes and are never converted into an API bearer (regression-pinned by the credential-contract tests).
- `Portal\PortalFacade::selfIdentify()` runs `GetAccount` + `Workspaces.Resolve` lazily (only when the dashboard Connection card is read, never at boot) and maps the workspace + attached Website publish relationship into safe runtime identity/status values.
- The env key is read on demand and held **in memory for the duration of the run only** — never written to options, transients, or logs. The WP database stores zero credentials.
- Neither key nor bearer appear in debug output, logs, or the manifest. PHPStan/PHPCS forbid `getenv` results flowing into anything but the HTTP client.

## 6. User-first workflow: progressive setup, not a publish-first wizard

Everything user-facing is progressive onboarding + a dashboard. No terminal. Cast must not ask for an export or publish while the user is still building the site. The existing builder onboarding finishes first; then Cast gets out of the way until public content exists. Only then does the first-publish flow appear. Where a finite flow is needed, it reuses the existing pattern (one global option aggregate, capability + nonce on every mutation, server-rendered steps, never trust JS state).

### 6.0 What already exists, and setup ordering

Cast ships an onboarding wizard (`src/Onboarding/*`, `Onboarding/*` state machine on one global option `cast_onboarding`): `NotStarted → Building → Completed | Skipped` (with `reopen`), installing then activating a page builder from a catalog, `InstallStatus` tracking idle→installing→installed→activating→active→failed.

Evaluation against the export/publish design:

| Concern | Existing onboarding | Export/publish wizard | Call |
|---|---|---|---|
| Flow shape | One-time, completable, reopenable | One-time, completable, reopenable | Same shape; compose, don't merge |
| Persistence | One global option aggregate | New dedicated aggregate (`cast_publish_setup`), same patterns | Two aggregates, one concept each — keeps the "one option per aggregate, no user-meta" decision intact without re-litigating `cast_onboarding` |
| Machine | Finite, transitions validated server-side | Finite, same validation | Reuse |
| External deps | Local plugin catalog | Env identity + portal APIs | Different failure universes: operator error (env) vs user error (DNS) |
| Mutable after setup | No (content-neutral installer) | Yes: settings, domain, mode are living config | Export state needs a **config surface** the onboarding aggregate doesn't model — it lives on the setup aggregate's post-`completed` view, editable from the dashboard |

**Setup-time ordering (what must be set up when):**

| When | What | Why |
|---|---|---|
| Plugin activation | Register nothing visible; validate env identity presence cheaply (vars set, format sane). Queue an admin notice if not | Identity is a deployment fact, not a wizard step; failing loudly before the wizard render is cheaper than inside it. No API call at activation |
| First admin visit | Existing page-builder onboarding wizard, unchanged | Content decisions (theme/section output) must settle before a meaningful export. Env-identity precheck renders as a status row on this same screen |
| Onboarding terminal (`Completed` **or** `Skipped`) | Return the user to normal WordPress/page-builder content creation. **Do not show a publish step.** | A builder choice is not a website. The user still has to create or import something worth publishing |
| First eligible public content exists | Set `cast_publish_dirty=true`; reveal a passive "Ready to publish" card/admin-bar state | Triggered by a public `transition_post_status`, or detected for a pre-existing site when the dashboard scans public posts/pages/CPTs. It does **not** enqueue a job |
| W1 (first-publish review) | User intentionally opens the ready card; sees what will be included and can adjust export settings | Settings are presented at the moment they matter, not during unrelated onboarding |
| W2 (first publish) | Requires: env OK, onboarding terminal, publishable content, probe pass. Produces CID → website → IPNS | The first artifact is the identity anchor; everything in W3/W4 hangs off it |
| W3 (domain) | Requires W2's website | Domain binds to CID-backed content only — the ordering correction §5.1 established |
| W4 (behavior) | Any time after first publish; default manual until chosen | Automatic publishing is meaningless before a live identity exists |
| Ongoing | Dashboard settings + status; reopening builder onboarding blocks publish until terminal again | A builder swap marks the site dirty, but no run starts while setup is incomplete |

**Build-on-top decisions:**

1. **Two aggregates, progressive entry points.** `cast_onboarding` ends after the builder decision. `cast_publish_setup` stays dormant until content readiness; the dashboard is the durable surface after that. No fake "one journey" that asks the user to deploy an empty site.
2. **Readiness before probe.** Cheap local readiness detection runs first. K2 origin probe runs only when the Ready card is opened (and again at job start), so Cast does not show deployment diagnostics while the user is still designing.
3. **Manual first publish, always.** The first public-content transition reveals the CTA but never auto-publishes. Automatic mode becomes selectable only after website + IPNS exist.
4. **Uninstall** deletes `cast_onboarding` + `cast_publish_setup` + run record + items table — same outcome rule as the existing lifecycle tests enforce.

### 6.1 Wizard steps

| Step | What the user does | Backend |
|---|---|---|
| **W0 Bootstrap** (automatic, not a screen) | Nothing — the plugin identifies itself. | Read `PORTAL_API_URL` + `PORTAL_API_KEY` (env, Coolify-injected). `GetAccount` + `Workspaces.List` with the key; workspace auto-selected when the key resolves exactly one. Missing/ambiguous env = plain-language failure naming the env var; this is an operator fix, not a wizard step. |
| **Content creation** (not a Cast step) | Build/import pages and preview normally in WordPress. Cast stays quiet. | The first eligible public-content transition sets a dirty/readiness flag only. For pre-existing sites, a local content scan sets readiness. No export and no API call. |
| **W1 First-publish review** (appears only when ready) | Open "Ready to publish"; review discovered public content and optionally adjust scope/excludes. | Readiness policy requires at least one eligible published post/page/public CPT (attachments/revisions/nav items excluded) or an explicit existing-site override after a successful public-homepage probe. Settings snapshot prepared but not frozen until W2. |
| **W2 First publish** | One button. The site's first upload: pin the exported ZIP, and the website is **created from that CID** (label = site hostname, no domain yet) plus an IPNS key that starts following the site. Copy of the resulting CID and IPNS name shown. | Publish job (§8): upload → `result` → `Websites.Create(targetHash=cid, domain omitted)` → `IPNS.CreateKey/Publish`. A website cannot exist before pinned content, so there is no "pick a website" step. |
| **W3 Domain** | Choose how the site is reached (see §6.2) — now possible, because the website exists. Shows exact records to publish and verifies them. Platform-domain one-click offered here. | `Websites.AddDomain`, `GetDnsRequirements`, `VerifyDomain`, platform-domain availability. |
| **W4 Publishing behavior** | Choose trigger mode: manual / on-update (§7). Shown late, with plain-language consequences. | Option + post-status hook registration. |

Wizard rules carried over from pinner-cli's domains wizard + the existing onboarding code:

- Bootstrap (W0) is not a screen: it runs before the wizard renders. If the env identity fails, the wizard shows one clear operator-facing card ("`PORTAL_API_KEY` not set in the deployment environment") instead of a form.
- Each step is independently re-runnable; back navigation never mutates — completed steps are visible read-only.
- Verification steps have **bounded retries** with honest copy ("DNS may take time; you can retry later from the dashboard") — never an infinite spinner.
- Guidance on DNS failure is actionable per-record (`dns_guidance` spirit): which record, at which provider, what to paste.
- Every step that could be affected by outside change re-verifies server-side before advancing (e.g., W3 re-checks the website still exists; W2 only offers "publish" again if no website exists yet).

### 6.2 Domain/DNS handling (ported from the pinner-cli delegation model)

The ipfs-sdk domains API exposes per-domain: namespace, delegation bundle, status (`active`, `onchainManaged`, …), and DNS requirements. The wizard renders four modes, exactly as pinner-cli does, as REST steps instead of terminal output:

**ICANN namespace:**

- *Platform-managed*: Pinner holds and serves the authoritative records. If a platform domain (the portal's own supported subdomain) is available, offer **one-click**: check `GetApiWebsitesPlatformDomainsAvailability`, bind it, done — no DNS work for the user at all.
- *Managed DNS (user's own domain)*: show **parent records (NS + DS)** with "point your registrar's nameservers to the records below"; authoritative side handled by the portal.
- *Self-managed*: show parent records (registrar) **and** the authoritative record set for the user's own DNS server.

**HNS namespace:**

- *On-chain managed*: records live in the user's HNS wallet. Show `_dnslink.{domain} TXT dnslink=/…/{targetHash}` and the **TLSA** record (without it the site will not load over HTTPS) with "publish this on-chain wherever you manage the name".
- *Inline*: parent (SYNTH) records published on-chain; authoritative side served by the portal's synthetic nameservers.
- *Managed*: parent records on-chain; authoritative handled by the portal.
- *Self-managed*: parent records on-chain + authoritative records for the user's own DNS server.

Delegation records always render as a copyable table (`NAME | TYPE | VALUE`) with per-row copy buttons in the UI, plus a DNSSEC/DS note where relevant. Verification status polling is the same bounded-retry loop as the CLI wizard.

**Alt-root support:** HNS is the v1 alt-root namespace. The wizard's namespace chooser frames it as "ICANN (traditional) vs Handshake (decentralized)" — the same framing pinner-cli uses — and every mode above applies unchanged. No custom resolver machinery lives in Cast; Cast only surfaces what the portals server returns. Additional alt-roots, if the API grows a third namespace value, plug into the same driver pattern (namespace-scoped renderers), not a new wizard.

### 6.3 Status dashboard (post-setup)

One admin screen, capability-gated, no page-builder coupling:

- **Connection card:** resolved identity (portal URL from env, account + workspace via API) — read-only status, not a form; website + bound domain(s) with per-domain status and SSL state. When env is missing, this card is the operator error surface.
- **Publish state card:** last published CID + time, last export run state, and a **drift indicator** — "site has unpublished changes" whenever the dirty flag (§7) is set. This is the direct answer to "failed builds need visible notifications": the user can always see whether what they published in WP matches what is live.
- **Actions:** Publish now (primary button, always present), export only (secondary), cancel a running job, re-run wizard steps.
- **Activity:** recent runs with per-stage timings, counts, failure lists (drill-down = manifest data), publish results (CID, size, TUS endpoint used, resumable-offset on failure).
- **Autonomous jobs:** all actions enqueue work (§7, §8); the dashboard never blocks on a request finishing an export. Buttons respond immediately with "queued", and the page polls job status.

### 6.4 Admin bar

A small unobtrusive node (capability-checked) with publish state + "Publish now" so editors never need to find the settings screen. It enqueues the same job; it never runs inline.

## 7. Publish-trigger semantics

**Decision: publish is a separate job from saving a post, triggered by publish-relevant content events, queued in WordPress, debounced and squashed — never inline in the request that saved the post.** Mode is user-selected (W4), manual is the default.

Why (research-backed):

- Netlify's build-hook behavior is the industry pattern: while a build runs, additional requests queue; when it finishes, **all superseded requests are skipped except the most recent**. Build-per-keystroke is the canonical failure; guidance is explicit: trigger on *publish events*, not save events, and debounce busy sites (Netlify developer guide, support forum, third-party write-ups).
- Simply Static Pro's "Auto Push" exists and is liked ("automatically push when content is published or updated… editors work inside WordPress while [the] push happens in the background"), but it is a *Pro* feature wired to manual/scheduled/cron runs — the free-tier UX gap this plugin fills.
- The export kernel itself (K7) forbids "one AJAX request finishes the site": a real site's export outlives one request. So even a manual "Publish now" cannot be synchronous; everything is queued ticks. This pushes the design to one mechanism for all triggers, which is simpler than three.

### 7.1 Modes

| Mode | Behavior | Default |
|---|---|---|
| **Manual** | Only explicit actions publish. Save/update never triggers anything. Dirty flag still accumulates so the dashboard can show drift. | ✅ |
| **On-update** | `transition_post_status` (publish / update-of-already-published / trash of published content) sets `cast_publish_dirty` **in the same request but without running anything** — pure `update_option`, one atomic write, no export machinery inline. A debounced enqueuer schedules a single-event cron tick `cast_publish_tick` after a **quiet period (default 10 min**, filterable). Rapid successive saves collapse into one scheduled run. | |
| **Scheduled** (removed — was never live) | A reserved placeholder for a planned WP-Cron recurrence that auto-scheduled exactly like On-update. No time-based recurrence was ever built, so the option is no longer exposed in the trigger selector — only **Manual** and **On-update** are offered, and no dead-end "scheduled" copy reaches the user. A persisted `scheduled` value is migrated on read to On-update, preserving the auto-publish behavior a site already relied on. | |

Revisions, autosaves, drafts, and ACF/meta-only saves either do not fire `transition_post_status` or resolve to the same already-published transition — they produce one dirty flag, one enqueue, zero export work.

### 7.2 Queue semantics (squash + supersede)

- Dirty flag is a single boolean option, not a queue: N saves in the window = one pending publish. This is the debounce-collapse the Netlify model uses.
- If a publish job is running when new dirtying saves land, the run record is marked `superseded_after_complete` (a persistent flag on the run row): when the current run finishes, the cron handler immediately schedules one new run. Intermediate states are never published as stale content (see 7.3).
- Concurrent manual "Publish now" while auto-queue pending: the manual run wins; the dirty flag is consumed by it; no duplicate run is scheduled.
- Fenced double-start: option-based atomic start gate + `GET_LOCK` per §8, so cron re-entry and manual starts cannot race.

### 7.3 Staleness rule

A run snapshots at start, so any content saved mid-run is newer than the artifact being published. The job therefore never advertises a completed run as fully current unless `cast_publish_dirty` was cleared atomically *after* the target update succeeded, with save-time marker comparison (`last_content_change` option vs run start time). If a change landed mid-run, the run completes but the dashboard re-raises the drift indicator and the debounced re-publish fires — matching "a build that finished knowing it was already outdated".

### 7.4 What this rules out (and why)

- **Instant (inline on save):** long PHP request inside save; violates K7; times out on real sites; blocks editorial workflow.
- **WP-Cron immediate (no debounce):** a burst of saves = a burst of full exports; wasted uploads of near-identical 100+ MiB archives; TUS churn.
- **Fully decoupled service outside WP:** operationally heavier (second deploy target); nothing here needs it — WP-Cron ticks plus locks, the pattern already proven in the job engine, suffice.

## 8. Job runtime and data model

Backend-first: **WP-Cron single-event ticks are the primary runner; REST start/status for the UI** (start/status/cancel only). WP-CLI is not a roadmap surface.

- **Restarts without cron:** every scheduled tick is `wp_schedule_single_event` + a best-effort non-blocking `wp_remote_post(wp-cron.php)` self-kick (Recorp's loopback reliability trick), so `DISABLE_WP_CRON` hosts still progress; the dashboard also nudges a kick whenever it polls and finds no next-scheduled event.
- **Locks:** `GET_LOCK('cast_export_' . run scope, 45)` + site-transient mirror. Stale locks (>300s) reclaimed by the next tick.
- **Claims:** conditional `UPDATE … SET status='processing' WHERE … AND status='queued'` — two ticks cannot capture the same URL.
- **Watchdog:** rows stuck in `processing` beyond `WATCHDOG_TTL_S` (120s URLs / 180s assets) return to `queued` with an attempt increment.
- **Snapshot immutability:** settings and stage list are written onto the run record at start; later settings edits do not affect an in-flight run.
- **Shutdown handler:** `register_shutdown_function` releases the lock and clears `processing` rows on fatal.
- **Pause/Resume/Cancel:** state transitions on the run record; cancel runs wrap-up (delete work dir, release locks, best-effort TUS cancel).
- **Budgets:** `TICK_BUDGET_S` 18 (CLI 50), inner batch 10–50 adaptive, per the K7 constants.

Run record is a single option (`cast_export_run`) + the items table. Publish state lives on the same run record. Uninstall deletes all of it.

## 9. Publish vs re-publish

Export and publish are separable:

- **Export only** (dashboard secondary action): produce zip + manifest.
- **Publish existing** (retry path): publish an existing artifact without re-export. Automatic when an upload fails mid-TUS but the zip is intact.
- **Export & Publish** (primary action + on-update mode): one composed job; publish reads `{run_id}.zip` off disk.

Consequences: a publish failure never invalidates the export; a re-export invalidates prior publish state on the run record only after a new pack succeeds.

## 10. Security invariants (K8, non-negotiable)

1. Anonymous public GET only — no cookies, no minted users, no logged-in fetch.
2. Origin-only fetch: SSRF refused unless scheme+host+port matches `home_url`/`site_url`.
3. TLS always verified except obvious local origins (`localhost`, `127.0.0.1`, `::1`, `*.test`, `*.localhost`, local env). Never `sslverify => false` in committed paths.
4. Disk access only inside `realpath`-jails (work dir, uploads, `ABSPATH` content dirs). All deletes jail-checked.
5. Work dir + exports dir under uploads, web-denied (`index.php` + `.htaccess`), deleted on wrap-up.
6. Credentials live only in env vars (`PORTAL_API_KEY`, `WORKSPACE_AUTH_USERNAME/PASSWORD`), read per run and held in memory only — the WP DB stores zero credentials; tokens never logged, never persisted beyond a run's lifetime, never in the manifest.
7. Every REST wizard endpoint: capability check + nonce + finite-state transition validation (same rule as onboarding mutations) + catalog allowlist of transitions.
8. No `ABSPATH` walk for assets; disk-copy only after jailing.
9. Upload body/manifest never include WP secrets or config paths — enforced by discovery rejections.

## 11. Error taxonomy

| Class | Example | Behavior |
|---|---|---|
| Config error | Env identity missing (`PORTAL_API_URL`/`PORTAL_API_KEY`/workspace auth vars), key invalid (GetAccount 401), ambiguous workspace, probe fails (bad permalinks, loopback, SSL), domain verify never passes | Operator-facing card naming the env var / API response; wizard blocks with actionable guidance; job fails at probe/auth; nothing enqueued |
| Environment error | ext-zip missing, uploads not writable | Run fails at setup or pack with named extension/dir |
| Item error | 404/timeout/TLS on one URL | Item `failed` after backoff (3 attempts); run continues; item in manifest `failed[]` |
| Publish error | 4xx/5xx on POST, TUS 460, poll `failed`, target update fails | Publish stage fails; zip retained; resume offset recorded for TUS; **drift indicator stays on** |
| Integrity error | zip < 22 bytes, jail escape, leftover-origin > 0 | Run `completed_with_warnings` at minimum; jail escape surfaces as a stage failure |

All errors recorded on the run row (URLs truncated to 500 chars), then reflected in the manifest. No silent swallowing. Failed builds are visible in three places: dashboard card, admin-bar indicator, and REST status polling — the Netlify lesson that publish-vs-live divergence must be surfaced somewhere the editor looks. No email/webhook notification channel is planned (§16).

## 12. Retention and cleanup

| Artifact | Lifetime |
|---|---|
| Work dir | Deleted at wrap-up, success or failure |
| ZIP + manifest | Collected after `cast_publish_retention_days` (default 7) passes — **except** the shared `manifest.json` and the artifacts the single run slot still references (the latest run's ZIP, every non-terminal run's ZIP and any resumable/partial-upload run's ZIP), which are always kept |
| TUS upload (incomplete) | Deleted on cancel/fail of a run that owns it (best effort) |
| Options/table | Deleted on uninstall |

### 12.1 Implemented GC slice (P5 retention GC)

The artifact retrieval garbage collector is implemented as a pure, jail-checked
slice:

- **`RetentionPolicy`** — pure value object over the `cast_publish_retention_days`
  option. Default **7 days**; an explicit `0` disables GC (every artifact is
  kept); a missing/unparseable/negative value falls back to the safe 7-day
  default and can never shorten or silently disable retention. Expiry is pure
  clock arithmetic (`cutoff = now − days·86400`).
- **`ArtifactStore` seam + `WordPressArtifactStore`** — enumerates and
  stat'ed only the `*.zip` artifacts under the uploads-root `cast-exports`
  directory (the same jail `WordPressPackEnvironment` writes into). Deletion
  is **jail-checked twice**: only a realpath that stays inside the resolved
  `cast-exports` realpath and is named `*.zip` may be unlinked. The shared
  `manifest.json`, any non-zip entry, a missing file, a path outside the jail
  and a symlink whose target escapes the jail are all refused — a traversal or
  symlink plant can never delete outside the jail.
- **`ArtifactRetentionService`** — pure service over the store + run
  repository. Collects expired, unprotected ZIPs and returns a typed
  `RetentionSummary` (scanned/deleted/deletedNames/protected/
  skippedProtected/skippedNotDue/deletionFailures + warnings). Never collects:
  the latest run's artifact, any non-terminal run's artifact, or any
  resumable (still-owns-a-partial-upload) run's artifact.
- **Scheduling (`RetentionScheduler` + `RetentionRunner`)** — the
  `cast/export/retention` WP-Cron hook is armed via the same deduplicated
  single-event `Scheduler` contract as the export loop (hook + args identity):
  once after every terminal run (Completed/Failed/Cancelled outcome) and then
  re-armed periodically (once a day) by the handler itself, so old artifacts
  age out even on a quiet site and a pending sweep can never stack. The policy
  option is re-read live on every sweep. JobsHookSubscriber composes
  retention only when a scheduler is wired; the un-wired subscriber keeps the
  exact pre-retention registration contract.

Protection matrix (a `*.zip` is never collected when any row applies):

| Reason | Example |
|---|---|
| Latest run | The single run slot's current artifact, even when the run is terminal |
| Non-terminal run | A NotStarted/Running/Paused run mid-pipeline still needs its artifact |
| Resumable run | A run that still owns a partial TUS upload identifier |
| Shared manifest | `manifest.json` is never enumerated (only `*.zip`) and never deletable by the jail |


## 13. Observability

- Run row carries: stage, per-stage timings, counts from SQL per status, probe stats, publish progress (`offset/total`, `identifier`), error summary, `superseded_after_complete` flag.
- Dashboard + REST status read the same row; every 1000 zip additions and every publish chunk updates it, so polls never wait on a lock.
- `cast_publish_dirty` + `last_content_change` options power the drift indicator.

## 14. Test strategy

- **Unit (no WP):** URL canonicalization/rejection (from `catalogs/test-vectors.md`), rewrite math (`../` depth, `index.html` append, srcset, CSS `url()`, escaped-host JSON), ZIP64 batching, manifest schema, publish routing (size threshold), TUS chunk loop (offset math, resume), poll backoff (fake clock), debounce/squash logic (`transition_post_status` matrix × windows × running-job states), wizard state-machine transitions.
- **SDK contract tests:** PSR-18 fake HandlerStack asserting exact requests against `swagger.yaml` (multipart field name, `?archive=true&name=`, bearer, 307/308 re-send, TUS create-with-upload, metadata encoding, HEAD/PATCH offsets, result polling, `dns-requirements`/verify/platform-availability payloads).
- **Integration (docker-compose, existing):** WP-Cron tick loop against live WP; capture of a small fixture site; publish against a stub TUS/upload/websites server; lock/claim race with two runners; save-burst → exactly one scheduled publish.
- **Acceptance:** save burst of 5 posts in 1 min → one publish run, no stale publish advertised; >100 MiB artifact → TUS with working resume after killed process; first publish creates a website from the CID (no domain at creation) + IPNS key, and domain bind verifies end-to-end on ICANN platform-domain (one-click) and HNS-inline paths; publish failure via stub 500 → zip retained, drift visible, retry succeeds.

## 15. Phased implementation

| Phase | Content | Milestone |
|---|---|---|
| **P1 — SDKs + bootstrap** | `Env\Identity` (env read, `GetAccount` + `Workspaces.Resolve` self-identification over the `PORTAL_API_KEY` bearer, `COOLIFY_RESOURCE_UUID`/`PORTAL_WORKSPACE_URL` alignment), `Ipfs\Contract`, upload core, `PostUploader`, `TusUploader` (tus-php), websites/domains client, PSR-18 typing, contract tests | Identity self-resolves from env in tests; upload CID resolvable; websites surface testable behind fakes |
| **P2 — Export spine** | Job runner (WP-Cron ticks) + run row + items table, probe, discover, capture, inline rewrite (offline-zip), pack + manifest, wrap-up | A full export completes for a fixture site, driven entirely by cron ticks |
| **P3 — Progressive publish UX** | Content-readiness policy + passive dirty state; W1/W2 REST+UI (first-publish review appears only after content exists; then website-from-CID + IPNS), followed by W3 domain steps: ICANN/HNS delegation renderers, platform-domain one-click, bounded verify retries | Cast stays quiet while the user builds; once ready, one intentional first publish creates the identity, then the user can bind a domain |
| **P4 — Publish + triggers** | W4, publish job (auth→route→upload→poll→ensure-website→ipns→target), POST/TUS routing, target+SSL wait, trigger modes + debounce/squash + drift indicator, dashboard + admin bar | Export & Publish creates a website from the first CID and re-points it thereafter; auto mode survives a save burst correctly |
| **P5 — Production hardening** | ~~retention GC~~ **implemented (§12.1)** — jail-checked collection under `cast_exports`/`cast_publish_retention_days` with terminal-run + periodic arming — then K1 encodings gap-closure from test vectors (incl. over-encoded `%25` collapse). No PHP-Scoper/release build, no notification channel, no CLI wrappers (scope decisions) | Ship |

P1 first as before; P3 moved ahead of publish wiring because the user's first indispensable moment is "domain bound and verified".

## 16. Open questions (decisions requested)

1. **On-update quiet period** — 10 min proposed; longer = fewer exports, staler content. Confirm or adjust.
2. **Platform domain as the default path in W3** — offer one-click subdomain *before* custom domain? (Recommended: yes.)
3. **Failure notification channel** — resolved: **dashboard + admin-bar indicator + REST status polling only** for v1. No email/webhook notification channel; the email/webhook roadmap language and this question are closed with the P5 K1 closure. Revisit only as a follow-up if ops reports that editors never open the dashboard.
4. **Scheduled mode** — include the WP-Cron recurring snapshot option at all in v1?
5. **Retention default** — resolved: **7 days of kept zips**, implemented as `cast_publish_retention_days` (default 7, `0` disables, invalid values fall back to 7) in the P5 retention GC slice (§12.1). Revisit only if ops reports disk pressure.
6. **Single site vs network-activated** — v1 single-site?

## 17. Risk register

| Risk | Impact | Mitigation |
|---|---|---|
| Tus-php buffers per-PATCH payloads | Memory blowout on >100 MiB files | Explicit 16 MiB chunk loop (§5.4), never `upload(-1)`; documented `memory_limit` expectation |
| Only one maintained PHP TUS client | Supply-chain exposure | MIT, 8M+ downloads; isolated in `TusUploader` only, so a swap is one adapter |
| `rmccue/requests` duplicate if `art4` adapter is ever added | Fatal class conflict | Not added in v1; if ever added, `"replace": {"rmccue/requests": "*"}` |
| Vendor tree un-scoped collides with other WP plugins | Site breakage | Scope decision: **no PHP-Scoper / scoped release build** — collisions are contained by the `LumeWeb\Cast\` PSR-4 prefix and pinned dependency versions; a reported collision is treated as a compatibility bug, not a scoping trigger |
| GPL donor code bleed | License violation | Spec-only porting; donor plugin code never opened for copy/paste; per-file provenance comment references the cast export spec only |
| WP-Cron unreliable on cheap hosts | Runs stall | Self-kick loopback on schedule + dashboard nudge; watchdog reclaims; documented `DISABLE_WP_CRON` note |
| Editor confusion: saved ≠ published | Support load | Persistent drift indicator (§7.3, §6.3) + explicit mode copy in W4; publish state in admin bar |
| Large-site memory during capture | Fatal mid-run | Write-through per item; body never held in DB; inline rewrite per 200-item batch |
| Redirect loops | Infinite queue growth | Remaining-hop count per item; loops stop at cap |
