# Guided Website Creation During Publish — Design Proposal (Cast) — REVISION 2

Status: draft for review — design only, no implementation
Date: 2026-09-20 (rev 2, product-owner feedback)
Supersedes: the earlier auto-create draft of this file (rev 1). Rev 1 recommended
**silently auto-minting a platform subdomain during the publish tick**; the product
owner has revoked that. This revision makes first publish **guided, never silent**.
No code changes; this document proposes the user journey, REST surface, publish-flow
change, resume semantics, security gates, edge cases, and effort slices.

Scope: unblock publish when the Cast workspace has **no website** set up, using the
pinner CLI's website-create / workspaces-attach flows as the mature reference, and the
resumable publish boundary as the timing anchor.

---

## 0. TL;DR / product-owner decisions (binding)

1. **No default/auto-create of a platform domain.** Website setup is costly to redo;
   the first publish must be a guided UX, not silent automation. Cast never strikes
   `POST /api/websites` with `generate: true` or a platform claim without an explicit
   human confirmation in clear copy.
2. **Allowed paths on first publish (workspace has no website):**
   - **(a) Create a new website** — hostname optional. When empty, Cast derives the
     proposed label from the probed origin host, renders it as the prospective platform
     subdomain (availability-checked via the existing domain catalog), and **requires the
     operator to confirm it explicitly** before creating.
   - **(b) Link the workspace to an existing website** the account owns that is **not
     already linked to a workspace**. Feasible today (see §3): the ipfs-sdk ships
     `Workspaces.Attach` → `POST /api/workspaces/{id}/attach` with `{website_id}` and the
     pinner CLI's `workspaces attach <id> <website-id>` already consumes it. Cast needs
     two thin wire methods plus a picker; **not** blocked on an API addition (one
     server-side semantic caveat, see §3/§9 Q8).
   - ~~**(c) Manual route** — the operator does it outside Cast (CLI/API). Cast shows the
     parked state ("sent — waiting for a website") with instructions and resumes as soon
     as a website appears on the workspace.~~ **REMOVED (rev 3, product owner): the card
     offers only create+link.** The operator-facing choice is explicitly (a) or (b); a
     website created outside Cast is still adopted through the registry-first
     write-through (§§3.3, 6.4) once it appears on the workspace, but it is no longer
     offered as a card path.
3. **Timing — park, don't fail.** The run's existing `resumable` state is the anchor: the
   CID is minted after upload, then `POST /api/websites` 422s when the workspace has no
   website. Instead of the bounded-retry → terminal-fail tail, the run **parks** as
   "sent — waiting for a website" — a **UI-derived** state from `publish.status ==
   resumable` + `website_id == null` (no new run enum). The publish page shows a guided
   choice card. Completing a choice **resumes via publishExisting/publish semantics with
   the CID preserved** (§6).

Net effect: first publish never silently creates a website; it either walks the operator
through a clear choice or parks with an actionable card until one is resolved.

---

## 0.2 Slice A — Cast defaults to IPNS targeting (deliberate divergence, pinned)

Verified against the pinner CLI and the ipfs-sdk (`go.lumeweb.com/ipfs-sdk@v0.1.98`
`WebsiteRequest` / `IPNSResolve`; the pinner core `websites_enable_ipns` sequence).

**The divergence.** The pinner CLI defaults new websites to `target_type: "ipfs"`.
Cast **intentionally diverges and defaults every new run to `target_type: "ipns"`**:
the existing publish flow always maintains an IPNS publication (it already creates one
key per site and republishes each CID), so an IPNS-targeted website is the honest shape
— the site serves the newest published CID through a stable mutable name (`k51…`)
instead of needing a re-point to a fresh CID on every release. The `'website'` → `'ipfs'`
wire mapping — the legacy pre-ipns default — still exists so an already-IPFS-published
run re-publishes exactly as it always did.

**The reorder rationale (security/contract requirement).** An IPNS-targeted website
requires the IPNS key to exist **and** hold a live publication **before** the website is
created or re-pointed — otherwise the portal rejects with `IPNS_KEY_NOT_FOUND`. The
publish boundary therefore reconciles the IPNS half first:

1. create the IPNS key once (if the registry does not yet hold it),
2. publish the uploaded CID to that key,
3. create (first publish) or re-point (every later publish) the website with
   `target_hash = <ipns name>` + `target_type: ipns`.

The same order makes resume end-to-end correct: a parked run that stalled at the website
step already owns a published key + name, so the resume simply creates/re-points the
website at that name.

**Internal token plumbing.** `RunSettings.targetType` stays a pure internal token — new
runs default to `'ipns'`; `RunSettings::fromArray()` back-compat-maps a legacy persisted
`'website'` to `'ipfs'` so the stored value is kept stable for already-IPFS runs. The
`IpfsWebsitesClient::wireTargetType()` mapping stays `'website' → 'ipfs'`, passes `'ipns'`
through, and any other value through untouched. The awaiting-website registry match
compares **immutable** values: an IPNS-targeted listed website's target_hash (a name) is
resolved via `GET /api/ipns/resolve/{name}` to the CID it currently serves before it is
compared against the run's preserved CID; an unresolved (not-yet-published) name is
treated as no-match (still awaiting).

**On the registry match:** `availableWebsites()`/`awaitingWebsiteFor()` compare the run's
preserved CID against the listed website's *resolved* immutable CID, never the raw IPNS
name (a name can never equal a CID).

---

## 1. Current behavior and the failing call (verified)

Engine order today for a run with an empty publish slot:

1. Full export completes (probe → setup → discover → capture → rewrite → pack → wrapup),
   then `PipelineStageKey::Publish` (`src/Export/PipelineStageKey.php`, `order() == 7`).
2. `PublishStage::execute()` (`src/Export/PublishStage.php`) builds the `Artifact` from
   the packed ZIP and calls `PublishService::publish()`.
3. `PublishService::publish()` (`src/Publish/PublishService.php`):
   - upload → CID (`:33-56`);
   - `$websiteId = $state?->websiteId` — **null on first publish** (`:62`);
   - `$this->websites->create(new CreateWebsiteRequest($cid, $artifact->targetType, $artifact->label))`
     (`:66-67`) → **this is the call that returns HTTP 422** when the workspace has no
     website configured;
   - on `WebsiteClientException` → `return $this->resumable(...)` (`:70`) with the CID
     preserved; `PublishStage` records `PublishBoundaryResult::resumable(cid, null, null,
     $message)` on `PipelineState::$publish` and returns `StageResult::fail`,
     and `ExportTickRunner::recordFailure` (`src/Jobs/ExportTickRunner.php:106-122`)
     retries within a bounded budget, then **fails the run terminally**.

The concrete wire request today is `POST /api/websites` with body
`{target_hash, target_type, label}` and **no `domain`** (`src/Ipfs/IpfsWebsitesClient.php:38-43`).
`IpfsWebsitesClient::create()` (`:34-52`) never sends `generate` / `platform_domain` /
`dns_hosting_enabled`, so a label-only create has no destination to mint or attach to.

Why it 422s with an empty workspace publish slot: the API key is workspace-scoped (the
docs name `PORTAL_API_KEY` a workspace JWT with `aud=api`,
`docs/plans/export-publish.md` §2), so the server resolves THE workspace from the bearer
and reads its publish slot (`website_id`). `Workspaces.Resolve` already returns this
optional attached website to Cast (`src/Portal/WorkspaceResolve.php`, surfaced via
`WorkspaceResolve::publishState()` — `none` when `website === null`), and
`SelfIdentification::publishState()` → `ConnectionView.website_state`
(`src/Admin/ConnectionView.php`) already carries it into `PublishStatus.connection`
(`src/Admin/PublishStatus.php:82,127`). With no website on the workspace and a create
carrying only `label` — no `generate`, no `platform_domain`, no `domain` — the create has
no destination and no workspace slot to attach to, so the backend rejects with 422.

### What the status surface reports today

- `PublishStatus` (`src/Admin/PublishStatus.php`) reports `publishCid`, `lastError`,
  `identity` (from option `cast_publish_identity` via
  `src/Jobs/WordPressIdentityGateway.php`) — `identity` is `null` on a frozen first
  publish — and `connection.website_state` (`none | pending | published`).
- `PublishDashboardView::readinessFor()` (`src/Admin/PublishDashboardView.php:179-193`)
  has exactly four readiness levels — `config | setup | no_content | ready` — there is
  **no "needs a website / waiting for a website" readiness**, so the UI cannot currently
  explain the frozen state beyond `lastError`.
- `publishExisting()` (the retry path, `src/Admin/PublishSetupService.php:257-292`)
  **refuses with `IdentityMissing`** whenever `!$this->identity->hasIdentity()`
  (`:280-282`) — so "re-publish the already-uploaded artifact" is also blocked while the
  workspace has no website (nothing has been recorded in `cast_publish_identity` yet).

---

## 2. Two code-level bugs the design must keep in view

1. **`target_type` mismatch — RESOLVED (Slice A).** `RunSettings::$targetType` used to
   default to `'website'` while the SDK/CLI `WebsiteRequest.target_type` expects
   `ipfs|ipns`. Slice A corrected the default to `'ipns'` (new runs), back-compat-mapped
   legacy persisted `'website'` rows to `'ipfs'` on read, and kept the
   `'website' → 'ipfs'` wire mapping as the defensive legacy path — so no create/update
   ever carries a raw `'website'` on the wire.
2. **`cast_publish_identity` and the parked first publish.** `WordPressIdentityGateway`
   reads option `cast_publish_identity` (OPTION_KEY line 21) and `WordPressPublishRegistry`
   (`src/Publish/WordPressPublishRegistry.php`) **does** write it incrementally
   (`recordWebsite` first, `recordIpnsKey` second), and `PublishIdentity`/`hasIdentity()`
   only report ready once **both** halves exist. The consequence for this design, not a
   missed writer: on a parked first publish nothing has been recorded (create failed
   before `recordWebsite`), so `hasIdentity() == false` and `publishExisting()`
   (`PublishSetupService.php:280-282`) refuses with `IdentityMissing` — there is **no
   current replay path for a parked no-website run**. The doc's earlier framing
   ("identity never written") should be read as "nothing is ever written until a website
   exists" — i.e. the option is *conditionally* written, and the parked case has no
   replay conduit. This revision's resume flow (§6) is that missing conduit. (The
   `WordPressIdentityGateway` docblock still claims the option is "written by a later
   configuration/REST slice" — stale comment to fix alongside.)

---

## 3. Research folded in (feasibility + robust discriminators)

### 3.1 Link-existing feasibility — **FEASIBLE, endpoint exists (path b is not blocked)**

Sources: the ipfs-sdk that the pinner CLI vendors (`go.lumeweb.com/ipfs-sdk@v0.1.98`,
module cache) and the pinner CLI (`~/projects/pinner-cli`).

**List websites for the account (picker for (b)):**
- `GET /api/websites` — `WebsitesService.List(ctx, opts...)` (sdk `websites.go`),
  account-scoped ("all websites for the authenticated user"), response
  `{data: WebsiteItem[], total}`, server-side filters `filters[domain][contains]`,
  `filters[status][eq]`, `filters[target_type][eq]`, pagination `_start`/`_end`
  (`GetApiWebsitesParams` in `internal/client/client.gen.go`).
- **Gap:** `WebsiteItem`/`WebsiteResponse` carry **no `workspace_id` / attached-workspace
  field** — the list cannot tell which websites are already linked to a workspace.
  "Already linked" must come from attempting the link (server conflict) or from the
  workspace's own `website_id`, not from the list shape. See Q8.

**Claim/link a website to this workspace:**
- `POST /api/workspaces/{id}/attach` with body `{website_id: <int>}` — SDK
  `WorkspacesService.Attach(ctx, id, websiteID int)` (`sdk/workspace.go:353`;
  request/response: `WorkspaceRequest{WebsiteId *int}` and
  `PostApiWorkspacesIdAttachJSONRequestBody = WorkspaceRequest` in
  `client.gen.go:1123/5741`) → returns `WorkspaceResponse{WebsiteId *int}`.
- `POST /api/workspaces` (`Workspaces.Create`, body `WorkspaceRequest`) can also seed the
  link at workspace creation, but Cast's workspace already exists — irrelevant here.
- Workspace resolve confirms current linkage: `WorkspaceResolveResponse{WebsiteId *int,
  Website *WorkspaceResolveWebsite{id,status,target_hash,target_type}}`
  (`client.gen.go`), which is exactly what Cast already maps
  (`src/Portal/WorkspaceResolve.php` + `WorkspaceResolveWebsite.php`).

**CLI evidence (the mature adopt/attach flow to mirror):**
- `pinner workspaces` tree: list/create/get/**attach**/suspend/resume/access/delete
  (`internal/cli/workspaces.go:23`, `internal/cli/catalog_workspaces_wiring.go`,
  `internal/cli/workspaces_test.go:28`). The attach subcommand resolves the label-slug
  workspace id then calls `labelResolvingWorkspaces.Attach(ctx, id, websiteID int)`
  (`internal/cli/workspaces_label.go:112-117`) → `POST /api/workspaces/{id}/attach`,
  and renders back "Website ID" on the workspace (`renderWorkspaceHuman`,
  `catalog_workspaces_wiring.go`).
- `pinner websites list` (`internal/cli/websites.go` "websites" description) is the
  companion surface the human uses to discover a website id to attach.
- There is **no** separate "adopt/import" command — the flow is `websites list` →
  `workspaces attach <workspace> <website-id>`. That two-step is the exact contract
  Cast's (b) picker should mirror.

**Cast-side delta for (b):**
- New `IpfsWebsitesClient::list()` (`GET /api/websites`, envelope `{data,total}`) —
  mirrors `IpfsWorkspaceClient::list()`'s `{data,total}` handling.
- New `IpfsWorkspaceClient::attach(string $workspaceId, int $websiteId)`
  (`POST /api/workspaces/{id}/attach`, body `{website_id}`) — mirrors the existing
  `access()` path-encoding style (`src/Ipfs/IpfsWorkspaceClient.php`).
- `Workspace` value object currently **drops `website_id`** (`src/Ipfs/Workspace.php` only
  keeps id/label/domain/status) — add it so the link picker can grey out already-linked
  rows and so resolve-consistency checks can compare list linkage vs
  `WorkspaceResolve`'s attached website.
- `Publish / WebsiteClient` (`src/Publish/WebsiteClient.php`) gains a `list()` (or the
  Admin layer talks to `IpfsWebsitesClient` directly) and the picker is a new dashboard
  card — see §5.

**Verdict:** path (b) rides an existing API + an existing CLI flow — **no server API
addition required**. The one semantic unknown is the server-side "already linked to
another workspace" conflict signal on `POST /api/workspaces/{id}/attach` (status code /
reason body) and whether the workspace-scoped token can read the account-wide website
list — see §9 Q8; both are confirmations, not blockers, and the Cast contract is defined
anyway.

### 3.2 The 422 body + a **robust** "no website" discriminator

**What the 422 body contains (server contract):** errors are
`{"error": {"reason": <machine-code>, "details": <human string>}}`:
- The pinner CLI's `internal/cli/error_formatter.go:86-107` parses exactly this shape
  (returns `details`, falls back to `reason`).
- The ipfs-sdk `errors.go` `withReason(...)` unmarshals
  `client.ErrorResponse{Error{Reason, Details}}` onto `APIError{Reason, Details}` and
  `handleResponse` attaches it to every non-success response; the SDK's only documented
  reason codes are `CID_NOT_PINNED`, `IPNS_KEY_NOT_FOUND`, `DNS_VALIDATION_FAILED`
  (`errors.go`).
- Cast already preserves the body: `HttpResponse` (`src/Http/HttpResponse.php`) keeps the
  raw body + `json()`, and `UnexpectedStatusCodeException` (`src/Http/`) exposes
  `status()` and `response()`; `IpfsWebsitesClient::create()`'s `requireSuccess()` throws
  exactly this family, carried into `WebsiteClientException` with the previous
  exception (`src/Publish/IpfsWebsiteClient.php`).

**Why message matching is brittle and what to do instead:** the *specific* "workspace has
no website" reason code is not among the SDK's known codes and is not documented in the
SDK — matching `details` text would be the brittle option. Recommended discriminator
(order of preference):

1. **Fail-fast pre-publish (primary):** before/at publish start, workspace resolve shows
   `website == null` (`SelfIdentification::publishState() == 'none'`,
   `ConnectionView.website_state`). This is **already wired into `PublishStatus`** — the
   card can appear at the START of the run. No create needed to know.
2. **Website list-empty check (mid-run backstop):** if we still hit a create 422, call
   `GET /api/websites`; `total == 0` (or empty `data`) ⇒ the 422 is necessarily
   "no website to attach to" ⇒ park. If `total > 0`, the 422 is a different validation
   error (e.g. `target_type` mismatch from §2.1, `CID_NOT_PINNED`) ⇒ keep the existing
   retry→terminal tail and translate with the CLI's known-code mapping
   (`TranslateErrorWithCID`, `core/websites/errors.go`).
3. **Reason-code read (evidence only, not primary):** read `error.reason` when present.
   Once the backend confirms the exact code for this case (Q8), reason matching can
   become primary; today it is a supplement to 1/2, never a sole signal.

The 422 is explicitly the **backstop**, not the designed path — §6 makes pre-publish
detection the first line and reserve 422 classification purely for races
(website created elsewhere mid-run, stale resolve, etc.).

### 3.3 Pre-publish detection — confirmed available (recommendation: fail-fast first)

Cast **can** detect "first publish, no website configured" before calling create:

- `PortalFacade::selfIdentify()` (`src/Portal/PortalFacade.php:46-98`) →
  `WorkspaceResolveClient::resolve(resourceUuid)` (`src/Portal/WorkspaceResolveClient.php`)
  → `WorkspaceResolve` carrying the optional attached website; `publishState()` is `none`
  when `website === null` (`src/Portal/WorkspaceResolve.php:91-100`).
- Wired into the dashboard status already: `PublishSetupService::status()` →
  `ConnectionView::fromSelfIdentification(...)` → `connection.website_state`
  (`src/Admin/PublishSetupService.php`, `src/Admin/ConnectionView.php`).
- `IdentityGateway::hasIdentity()` (`cast_publish_identity`, both halves ready) is the
  "this is a first publish" complement — false while no website exists.

**Recommendation:** make the guided card **fail-fast at the start of the run**
(status-driven, pre-publish) the primary UX — the operator sees "Publish to Pinner needs
a website" with the create/link/manual choices before or while the upload runs, and
every choice is explicit. Keep the 422 classification (§3.2.2) as the backstop so a
mid-flight race parks the run instead of failing it.

---

## 4. User journey

### 4.1 States

| State | Workspace (`website`) | Run publish slot / `PipelineState::$publish` | What the operator sees |
|---|---|---|---|
| **S0 No website (pre-publish)** | `website = null` (`publishState() == none`) | none (no run or run queued) | Publish page shows the **guided choice card** ("Publish to Pinner needs a website") with (a) create-new, (b) link-existing, (c) manual — at the START of the run, before any 422. |
| **S1 Sent — waiting for a website (parked)** | `website = null` | `PublishBoundaryResult::resumable(cid, websiteId=null, …)` (CID preserved) | Resulting from 422 backstop only (races). Run parks (mechanics §6.2); UI derives "sent — waiting for a website" from `publish.status == resumable` + `website_id == null`; the same guided card renders with the preserved CID shown. |
| **S2 Live on a platform subdomain** | `website` attached, `status = active` | completed | Publish complete; dashboard shows the website and the subdomain; Domain panel offers "Bind a domain". |
| **S3 Custom domain bound** | same website, extra domains | completed | Site reachable on the custom domain after DNS delegation + validation (`/cast/v1/domains/bind\|dns\|verify`); the free subdomain can keep serving as a fallback. |
| **S4 Manual (CLI/API handled)** | website attached outside Cast | parked → resumed automatically | Cast notices the workspace now has a website, records identity, resumes the parked run; operator sees live site. |

### 4.2 Paths from S0 (first publish, workspace has no website)

| Path | What the operator does | Wire behavior |
|---|---|---|
| **(a) Create new website** | Optional hostname. If empty, Cast shows the **proposed** platform subdomain derived from the probed origin host, availability-checked, and asks "Create `example-com.pinned.site`?" — an explicit button/confirm. | On confirm: `POST /api/websites` `{target_hash: <CID>, target_type: ipfs, label: <hostname>, generate: true, dns_hosting_enabled: true}`. No confirm ⇒ nothing is created. |
| **(b) Link existing website** | Pick from the account's unlinked websites (picker). | `POST /api/workspaces/{id}/attach` `{website_id}`; then re-point that website at the new CID via the normal publish (`WebsiteClient::update`). Already-linked rows are greyed out (list linkage vs resolve `website_id`). |
| ~~(c) Manual (CLI/API)~~ **removed (rev 3)** | — | No longer offered on the card (product decision: the card is create+link only). A website created outside Cast is still adopted via the registry-first write-through when resolve shows it (§§3.3, 6.4), it is just not a selectable path. |
| (d) Cancel | Operator abandons. | Existing `cancelRun()` drives a parked run to `Cancelled` and clears its events; card clears (§7). Note: if the operator created/linked a website first, the site stays — next publish just re-points it. |

### 4.3 Copy conventions (product voice: "Publish to Pinner", never "portal", no duplicate headers)

Match the shipped voice in `templates/admin/publish.php` / `publish-notice.php`
("Publish your site to Pinner and keep it live.", "Ready to publish", "Publish to
Pinner", "Bind a domain"). The **duplicate-header rule** already used by the Domain panel
(`templates/admin/publish.php:277-292` — when the list-refusal label equals the panel
state label it must not be echoed twice) applies to the new Website card verbatim: one
`stateLabel` chip plus one optional distinct sub-line, never a repeated heading.

Suggested strings:

- S0 chip: **"Publish to Pinner needs a website."**
- S0 sub-line: **"A website tells Pinner where to serve your upload. Create one, or
  link one you already own."**
- Path (a) proposal: **"Create `<subdomain>` — we'll use `<label>` as the suggested
  address."** / confirmation button: **"Create `<subdomain>`"** (explicit, echoes the
  proposed subdomain), secondary: **"Change address"** (opens the Domain/platform
  catalog).
- Path (b): **"Link a website you already have"**; picker rows show domain + status;
  disabled rows: **"already linked"**.
- Path (c): **"I'll handle it in Pinner"** → parked copy: **"Your upload was sent. The
  run is waiting for a website — open Pinner and create one or attach one to this
  workspace, then come back."**
- S1 (parked): stage label **"Sent — waiting for a website"**; CID line stays visible.
- S2 success: **"Your site is live at `<subdomain>`."** CTA: **"Want a different
  address? Bind a domain."** (replaces the plain website-name line,
  `templates/admin/publish.php:117-119`).

---

## 5. REST surface

All under `LumeWeb\Cast\Admin`, gated exactly like the publish/domain registrars
(manage_options + `wp_rest` nonce via `RestAuth`,
`src/Admin/PublishRestRouteRegistrar.php`, `src/Admin/DomainRestRouteRegistrar.php`).
No route trusts a client-supplied website id beyond the picker's own listing (§8).

| Route | Kind | Body/params | Behavior | Backing SDK call |
|---|---|---|---|---|
| `GET /cast/v1/publish/status` | **extend** (already polled) | — | Add a `website` block `{ id, name, domain, status }` (mirror of the connection slot) + `websiteState` enum `none\|parked\|live\|domain_pending`. Add `awaitingWebsite: bool` derived as `publish.status == resumable && website_id == null` — this drives the S1 card with **no new poll**. | (`Workspaces.Resolve` already in `connection`) |
| `POST /cast/v1/website/propose` | new | `{ hostname?: string }` | Resolve label + platform roots + availability for the proposed subdomain (hostname-derived when omitted). Pure read — never creates. | `GET /api/websites/platform-domains`, `GET …/availability?label=` (already wrapped in `DomainSetupService::listPlatformDomains()/checkPlatformAvailability()`, `src/Ipfs/IpfsDomainsClient.php`) |
| `POST /cast/v1/website` | new | `{ hostname?: string }` | **Explicit create (path a).** Server derives the label from the resolved hostname (site-locked, never a raw client label — mirrors `DomainSetupService::websiteIdForSiteLocked`); sends `POST /api/websites` `{target_hash, target_type: ipfs, label, generate: true, dns_hosting_enabled: true}`; records the website identity half; returns `{ website_id, website_name, domain, status }`. Idempotent: if the workspace already has a website, returns it unchanged (no-op). | `POST /api/websites` `WebsiteRequest` (`Websites.CreateWithOptions`) |
| `GET /cast/v1/website/available` | new | — | Account's websites for the picker (path b), excluding the workspace's own already-attached one. Returns `[{website_id, domain, status, target_hash, target_type}]`. | `GET /api/websites` `WebsitesService.List` |
| `POST /cast/v1/website/link` | new | `{ website_id: int }` | **Link existing (path b).** `POST /api/workspaces/{id}/attach` `{website_id}`; on success records the website identity half. Conflicts (already linked) come back as a typed refusal; the picker refreshes. | `POST /api/workspaces/{id}/attach` (`Workspaces.Attach`) |
| `POST /cast/v1/publish/resume-website` | new | — | **Resume after a choice.** Completes the identity (IPNS key via `POST /api/ipns/keys` when missing, records both halves), then deploys the preserved CID to the now-attached website and resumes the parked run (§6.3/6.4). Returns the new run id + status. | `POST /api/ipns/keys`; publish-only replay over the intact pack |
| existing `/cast/v1/domains/platform` , `/cast/v1/domains/availability` | **unchanged, reused** | `?label=` | Pre-confirmation availability + later label choice. | as above |

All mutating routes re-check, inside the handler, that the workspace still has no website
and (for link) that the target website is not already linked — idempotent, no-op or
typed-refusal otherwise (§7).

---

## 6. Publish-flow change

### 6.1 Pre-publish guided card (fail-fast, primary)

The card renders from `PublishStatus.connection.website_state == 'none'` +
`!identity->hasIdentity()` — i.e. before any run is started (and while a run is queued or
uploading). The operator can make the (a)/(b)/(c) choice up front; the choice is stored
locally (an intent marker) so a later upload completion can pick it up. This is
**read-driven, not a hard gate**: a user may still click "Publish to Pinner" with no choice
(one-button semantics per `docs/plans/export-publish.md` W2) and the upload proceeds —
the design never blocks the upload on a website (a website cannot exist before the CID
anyway). The card is the primary UX; it removes the surprise at the create step.

### 6.2 Backstop: classify the create 422 and PARK instead of fail

In `PublishService::publish()` (create failure branch, `src/Publish/PublishService.php:68-71`)
and the tick boundary (`src/Export/PublishStage.php`, `src/Jobs/ExportTickRunner.php`):

1. When `WebsiteClientException` wraps HTTP 422, run the §3.2.2 discriminator
   (pre-checked in-flight: most likely the workspace resolve already said `none`; confirm
   with `GET /api/websites` empty when the resolve is stale).
2. **"No website configured" ⇒ PARK:** stop retrying. Mechanically reuse the existing
   `Paused` state (`ExportRun::pause()` is already skipped by the tick runner
   — `src/Jobs/ExportTickRunner.php:75` skips `RunStatus::Paused`), or, if the team
   prefers no status change, leave the run non-terminal with retries suppressed. Set a
   clear `lastError`-adjacent marker. **The UI derived state `awaitingWebsite` is
   `publish.status == resumable` (from `PipelineState::$publish`,
   `PublishBoundaryResult::resumable`) + `website_id == null`** — no new run enum.
   The CID stays preserved (`ExportRun::recordPublishBoundary`), and `publishCid` is
   surfaced for the parked copy.
3. Any **other** 422 (e.g. `target_type` bug §2.1, `CID_NOT_PINNED`) keeps the existing
   retry → terminal-fail tail with readable translation (mirror
   `TranslateErrorWithCID` known codes), never parking.

This is a strict degradation of the current bounded-retry→fail behavior, only for the
specific confirmed no-website case.

### 6.3 Resume semantics after the operator chooses

1. **A website now exists** on the workspace (created via (a), linked via (b), or noticed
   via (c)/elsewhere).
2. The resume action completes the identity: `registry->recordWebsite(id, label)` and —
   when missing — create the IPNS key (`POST /api/ipns/keys`) + `recordIpnsKey` so
   `hasIdentity()` becomes true.
3. **Resume = publish-only replay with the CID preserved** (the product-owner contract):
   - The parked run has an intact pack and `PipelineState::$publish.cid`.
   - Reuse the `publishExisting` / `ContentPublishScheduler::publishExisting` shape
     (`src/Jobs/ContentPublishScheduler.php:179-206`): seed a fresh run with the same
     settings + pack + `RESUME_PUBLISH_ONLY` cursor (`ExportRun::RESUME_PUBLISH_ONLY`,
     `src/Export/ExportRun.php:41`) + **the preserved CID forwarded into
     `recordPublishIdentifiers`** so the status surface keeps reporting it during replay,
     and a single immediate tick.
   - On the replay tick, `PublishService::publish()` re-uploads the intact artifact and,
     because `registry->current()` now returns a `websiteId`, takes the **`update()`
     path** (`PublishService.php:80-89`) — it re-points the (now existing) website at the
     CID instead of trying to create again. IPNS re-publishes the new CID. The run
     completes live.
   - **Seam to change:** `PublishSetupService::publishExisting()`
     (`src/Admin/PublishSetupService.php:257-292`) currently requires a **terminal** source
     and `hasIdentity()` (refusals `RunActive`, `IdentityMissing`). A parked run is neither
     terminal nor identity-bearing, so the "complete choice" flow needs a dedicated
     entry (e.g. `completeWebsiteChoice()` / a relaxed
     `publishExisting`-for-parked) that first records the identity (§6.3.2) and then seeds
     the replay. Whether the parked record is first transitioned to Cancelled or marked
     terminal-with-cid is a mechanics decision (recommend a `Paused → Cancelled` grace,
     preserving the CID, before seeding) — see §9 Q7.

### 6.4 Automatic resume (path c / website created elsewhere)

The parked run, while awaiting, keeps polling the existing status path; when
`WorkspaceResolve` shows `website != null` and `hasIdentity()` becomes satisfiable, Cast
may auto-resume (records identity + seeds the replay) or surface a "website detected —
finish publish" button. v1 keeps this **manual-confirm** to avoid surprising a CLI/API
user; auto-resume is a v2 nicety (Q7).

---

## 7. Edge cases (cmd-click races and friends)

- **Two publishes raced (start + now double-fire):** the existing manual-run-wins
  primitive (`ContentPublishScheduler::startNow` absorbs a queued NotStarted run, refuses
  actively-running/superseded) already serializes runs. The choice actions must be
  guarded the same way: if a run is already live when the choice completes, refuse with
  `RunActive` instead of stacking; the operator retries once it settles.
- **Website created elsewhere meanwhile (CLI/web):** at choice-completion time the
  handler re-resolves the workspace. If a website is now attached, (a) `create` would
  409/double-create — instead **adopt**: record the existing website identity and resume
  (effectively (b)). If the chosen link target is already linked, refresh the picker and
  surface "already linked" rather than failing the card.
- **Parked run cancelled:** existing `cancelRun()` handles a `Paused` run
  (`PublishSetupService::cancelRun`, transition to `Cancelled`, clears pending events).
  The card must treat `Cancelled` as terminal: clear `awaitingWebsite`. If the operator
  had already created/linked a website, the identity half persists and the next publish
  just re-points — no orphan.
- **Resolve staleness / poll races:** the card state derives from persisted run data
  + one resolve read; a website created *during* the create call is caught by the
  re-resolve in §6.3/§7 rather than by predicting the backend.
- **Repeated card submits:** each mutating route is idempotent (no-op when the workspace
  already has a website; typed refusal on conflict) and re-checks the parked run's state
  inside the handler.
- **Upload re-dedup on replay:** replay re-uploads the intact artifact; whether the
  server returns the same CID is the §9 Q4 open question — if not deduped, the preserved
  CID only matches the pack, not the second upload, so the replay must trust its own new
  upload result for the final `publishCid` (the preserved CID is for the *parked* copy
  only). Q4 decides the guarantee.

---

## 8. Security / capability gates

- All new + extended routes reuse `RestAuth` (`src/Admin/RestAuth.php`): `manage_options`
  capability **and** a valid `wp_rest` REST nonce — identical to
  `PublishRestRouteRegistrar`/`DomainRestRouteRegistrar` permission callbacks.
- Admin-post (non-REST) actions, if any, reuse `OnboardingRequestHandler::gated`
  (capability + per-action nonce, deny 403 / redirect) as the page-builder wizard does
  (`src/Admin/OnboardingRequestHandler.php:137-169`).
- **Who can set the hostname/label / website id:** nobody through the create route —
  `POST /cast/v1/website` derives `label` server-side from the probed origin host
  (`$state->probe?->origin?->host() ?? 'site'`, `src/CastPlugin.php:320`), never a raw
  client string, mirroring `websiteIdForSiteLocked`. `POST /cast/v1/website/link` accepts
  only a website id returned by the same account's `GET /api/websites` (the picker), so
  an operator can only link what they own; a server-side conflict re-check (§7) is the
  second gate.
- Credentials never cross the REST surface: responses carry identifiers and domains only
  (`website_id`, `name`, `domain`, `status`), consistent with `PublishStatus`/`ConnectionView`.
- The proposed subdomain is echo-confirmed in copy (§4.3) — the create route is only
  reachable via that explicit confirmation UI; there is no silent `generate: true` from a
  dashboard auto-tick or from the status endpoint.

---

## 9. Open questions for the product owner

1. **Workspace auth / proxy (WORKSPACE_AUTH_USERNAME/PASSWORD):** the portal uses it for
   workspace public routes; is website create / workspace attach affected by workspace
   access type (open vs credentials)? Current Cast design holds it only in memory and
   never uses it for publish (`docs/plans/export-publish.md` §2) — confirm the
   create/link paths need nothing more.
2. **Workspace-scoped token ↔ account website list:** can `GET /api/websites` be called
   with the workspace-scoped `PORTAL_API_KEY` (aud=api) to enumerate the account's
   websites (needed for the (b) picker and the list-empty discriminator)? If not, the
   (b) picker falls back to "paste the website id the CLI listed" (a manual affordance),
   and the 422 backstop relies on resolve-not-null + attach-attempt semantics instead of
   list-empty. Confirm at the backend. — **ANSWERED (rev 2, product owner):** YES. The
   workspace-scoped key (existing `PORTAL_API_KEY` / workspaces auth) CAN call
   `GET /api/websites` and read the whole account's website list; the endpoint is
   paginated (`{data, total}`). No new key infrastructure or permission scoping is
   required — the (b) picker and the registry-first discriminator both work as designed.
3. **"Already linked to another workspace" conflict signal:** exact status/reason body
   from `POST /api/workspaces/{id}/attach` when the target website belongs to another
   workspace (409 + reason?). Needed to grey out rows and translate the refusal. If there
   is no per-website "linked" marker readable from `GET /api/websites`, this is the only
   authoritative signal — confirm it exists.
4. **Re-upload dedup on resume:** when the publish-only replay re-uploads the same intact
   artifact, does the server return the same CID (so the preserved `publishCid` survives)?
   Determines §6.3/§7 replay guarantees.
5. **The "no website" 422 reason code:** what is the exact `error.reason` the backend
   returns for "workspace has no website on a label-only create"? Confirm so reason
   matching can eventually be the primary backstop signal (§3.2/§9 Q2). — **ANSWERED
   (rev 2, product owner):** reason matching is no longer pursued. Cast never uses a
   blind create/attach 422 as an "already exists" probe — the existence question is
   **registry-first** (`GET /api/websites`, match this workspace's target_hash against
   listed websites' target_hash; a match = the workspace has a website even when the
   local id cache is empty, and is written through as linked). A 422 on an actual
   publish is a *disagreement surface* (the backend said the workspace is not
   websiteless when the resolver/list suggested it was): the guided card renders with a
   retry affordance and no further probing is performed.
6. **`target_type` normalization** (§2.1) — **ANSWERED (rev 3, Slice A):** Cast keeps the
   internal token and defaults new runs to `'ipns'`, back-compat-maps legacy persisted
   `'website'` rows to `'ipfs'` semantics, and keeps `'website' → 'ipfs'` as the defensive
   wire mapping; `'ipns'` passes through. The existing `update()` re-point path and the
   `WebsiteReadiness` polling are unaffected.
7. **Parked-record mechanics on resume:** seed a fresh publish-only run after a
   `Paused → Cancelled` grace (preserving the CID), or transition the parked record to a
   terminal-with-cid state and ride `publishExisting`? And should v1 auto-resume when a
   website is detected via path (c), or keep it manual-confirm?
8. **Identity incrementality vs replay:** after (a)/(b) the registry holds the website
   half but not the IPNS half; confirm the resume action creating the IPNS key is the
   right place to complete `hasIdentity()` (vs. deferring IPNS to the replay tick which
   already handles a missing key via `PublishService.php:85-96`).
9. **Link existing while a website is attached to ANOTHER workspace:** out of scope for
   Cast to unlink — confirm "not already linked to a workspace" means "not linked to any
   workspace" and that Cast should never detach a website from another workspace.

---

## 10. v1 vs v2 slice

**v1 — guided first publish, no silent creation (smallest correct slice):**

1. Pre-publish guided card surfaced from `connection.website_state == 'none'` +
   `!hasIdentity()` (§6.1) with (a) create, (b) link, (c) manual paths.
2. Path (a): `POST /cast/v1/website/propose` (reuses domain availability) +
   `POST /cast/v1/website` explicit create (`generate:true` + `dns_hosting_enabled:true`
   + site-locked label, `target_type` normalized per Q6); explicit confirmation copy.
3. Path (b): `IpfsWebsitesClient::list()` + `IpfsWorkspaceClient::attach()` +
   `GET /cast/v1/website/available` + `POST /cast/v1/website/link` picker
   (gated on Q2/Q3 answers; fallback = manual website-id affordance).
4. Backstop: classify create 422 (list-empty/resolve) → **park** (Paused, retries
   suppressed) instead of terminal-fail; `awaitingWebsite` derived UI state on the
   already-polled `/cast/v1/publish/status`.
5. Resume: `POST /cast/v1/publish/resume-website` — record identity (website + IPNS)
   then seed the publish-only replay with the preserved CID (§6.3).
6. Copy (S0/S1/S2, "Publish to Pinner", dedup rule), status `website` block, cancel
   handling (§7).
7. Fixes alongside: `target_type` normalization (§2.1), stale `WordPressIdentityGateway`
   docblock, `Workspace` value object gains `website_id`.

**v2 — polish:**

8. Auto-resume on path (c) website detection (Q7).
9. Friendly-label choice before create (platform-domain catalog UI), custom-domain
   guidance at create, richer picker (status filters, search).
10. Lifecycle management (rename/delete) + showing "already linked" authoritative state if
    the backend exposes it via the websites list.

---

## 11. Test plan

Follows existing seams (unit fakes in `tests/Unit/Publish/*`, recording transport in
`tests/Unit/Ipfs/*`, js tests in `tests/js`). Never run from repo root — run the package
suite.

1. **PublishService unit** (`PublishServiceFirstPublishTest` family): first publish with
   a 422 create → returns resumable with CID preserved; the classifier distinguishes
   "no website" (list-empty) 422 from other 422s (non-empty list) — only the former parks.
2. **Ticket runner / park semantics** (`ExportTickRunnerTest`): no-website 422 parks
   (Paused, retries suppressed, `awaitingWebsite` derivable); other failures keep the
   bounded-retry → terminal tail; parked runs are skipped on subsequent ticks.
3. **Resume** (`PublishSetupService`/`ContentPublishScheduler`): after identity is
   recorded, the replay seeds a publish-only run with the preserved CID forwarded and
   completes via the `update()` path (fake registry). Both (a) and (b) reach live; a
   double choice race is refused (`RunActive`).
4. **Ipfs clients** (`tests/Unit/Ipfs/*`): `IpfsWebsitesClient::list()`
   (`{data,total}`), new `create` body carries `generate: true` + `dns_hosting_enabled:
   true` + normalized `target_type` and omits `domain`; `IpfsWorkspaceClient::attach()`
   sends `{website_id}`.
5. **Status/DTO** (`tests/Unit/Admin/*`): `PublishStatus` gains the `website` block +
   `websiteState` + `awaitingWebsite`; serialization stays credential-free;
   `fromArray` rejects malformed.
6. **Dashboard view** (`PublishDashboardView`): S0/S1/S2 readiness derivation without
   disturbing `config|setup|no_content|ready`; copy dedup rule (refusal vs state label)
   for the Website card.
7. **REST** (registrar test patterns): new routes registered, permission callbacks return
   false without capability/nonce; ids rejected unless from the picker's own listing.
8. **Integration/recording transport** (`boot(__FILE__, $recordingTransport)`): full
   Probe→Publish with a pre-chosen (a)/(b) choice reaches S2 deterministically; with no
   choice the run parks at `POST /api/websites` and the recorded requests are
   `upload → POST /api/websites → park` (gated on the pipeline being drive-able
   end-to-end, per the boot idempotency caveat in `CastPlugin::boot`).
9. **JS** (`tests/js/cast-publish.test.js`): Website card render, create link vs manual
   confirmations, no duplicate heading, nonce/endpoint allowlist respected.

---

## 12. References (files cited)

- pinner CLI (`~/projects/pinner-cli`):
  `internal/cli/websites.go`; `internal/cli/websites_wizard.go`; `internal/cli/websites_wizard_pterm.go`;
  `internal/cli/websites_service.go`; `internal/cli/websites_domains.go`; `internal/cli/workspaces.go`;
  `internal/cli/workspaces_label.go` (`Attach` at 112-117); `internal/cli/catalog_workspaces_wiring.go`
  (attach op, human render, list row incl. Website ID); `internal/cli/workspaces_test.go:28`;
  `internal/cli/error_formatter.go:86-107` (`{"error":{reason,details}}` parsing).
- pinner core module (`go.lumeweb.com/pinner@…`): `catalogops/websites.go` (`websitesCreate`,
  platform discovery 437-536, platform ops 843-906); `core/websites/service.go`;
  `core/websites/errors.go` (`TranslateErrorWithCID`: `CID_NOT_PINNED`,
  `IPNS_KEY_NOT_FOUND`, `DNS_VALIDATION_FAILED`); `core/ipns/service.go`.
- ipfs-sdk (`go.lumeweb.com/ipfs-sdk@v0.1.98`): `websites.go`
  (`List`/`CreateWithOptions`/`ListPlatformDomains`/`CheckPlatformDomainAvailability`);
  `workspace.go` (`List`/`Create`/`Attach` at 353/`Resolve` at 440);
  `internal/client/client.gen.go` (`WebsiteRequest` 717, `WebsiteResponse` 730,
  `WorkspaceRequest` 753 (`WebsiteId *int`), `WorkspaceResolveResponse` 789,
  `WorkspaceResolveWebsite`, `GetApiWebsitesParams` 913, `PostApiWebsitesIdAttachJSONRequestBody` 1123);
  `errors.go` (`handleResponse`,`withReason`,`APIError{Reason,Details}`).
- Cast (`/root/projects/cast/.aider-desk/tasks/66fbf473/worktree`):
  `src/Publish/PublishService.php:62-91`; `src/Publish/CreateWebsiteRequest.php`;
  `src/Publish/WebsiteClient.php`; `src/Ipfs/IpfsWebsitesClient.php:34-76`;
  `src/Ipfs/IpfsWorkspaceClient.php`; `src/Ipfs/IpfsDomainsClient.php`;
  `src/Ipfs/Workspace.php` (drops `website_id` today); `src/Portal/PortalFacade.php:46-98`;
  `src/Portal/WorkspaceResolve.php:91-100`; `src/Portal/WorkspaceResolveClient.php`;
  `src/Portal/SelfIdentification.php`; `src/Admin/ConnectionView.php`;
  `src/Admin/PublishSetupService.php:257-292` (`publishExisting`, `IdentityMissing`);
  `src/Admin/PublishRestRouteRegistrar.php`; `src/Admin/DomainRestRouteRegistrar.php`;
  `src/Admin/DomainSetupService.php` (`listPlatformDomains`/`checkPlatformAvailability`,
  `websiteIdForSiteLocked`); `src/Admin/PublishDashboardView.php` (readiness 179-193);
  `src/Admin/PublishStatus.php` (`connection` 82/127); `src/Jobs/WordPressIdentityGateway.php`;
  `src/Publish/WordPressPublishRegistry.php` (incremental identity writer);
  `src/Jobs/ExportTickRunner.php:75,106-122`; `src/Export/PublishStage.php`;
  `src/Export/PipelineStageKey.php`; `src/Export/ExportRun.php:41,264-269,561` ;
  `src/Export/RunSettings.php:24` (`targetType 'website'`);
  `src/Jobs/ContentPublishScheduler.php:179-206` (`publishExisting`); `src/Jobs/ExportPipelineTick.php`;
  `src/CastPlugin.php:316-334` (origin host label); `templates/admin/publish.php`
  (esp. 117-119 website-name line, 277-292 dedup rule); `assets/js/cast-publish.js`;
  `docs/plans/export-publish.md` (§2 identity, §6.1 W2 "one button", §6.2).