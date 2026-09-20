# ComposePress Onboarding Plugin — High-Level Plan (DRAFT FOR APPROVAL)

**Status:** Draft for user approval · **Method:** TDD (red–green–refactor per feature) · **Scope:** WordPress onboarding only
**Working title:** `composepress-starter` (onboarding extension). Built on **ComposePress/core** + **starter** — but these are **clean-slate rewrites still on local develop/master lines, NOT released tags**. Tags `0.3.4` (core) and `0.2.0` (starter) are the **legacy** releases and must **not** be used as the build basis. We verified the rewrite surfaces at `~/projects/composepress/core` and `~/projects/composepress/starter` **read-only** (inspected via `git show` from the worktree; sibling repos were never checked out/modified). Current worktree is empty scaffolding (`LICENSE`, `.gitignore`, branch `aider-desk/task/we-need-a-high-level`); no code exists yet.

> **Version-tracking rule (change vs. any release-tagged plan):** because the rewrites are unreleased, the implementation **must track and pin exact develop commits** until the rewrites are released to Packagist/composer. We therefore reference the develop state by **commit SHA (inspected reference, not a stable version)**. Pinned refs at time of writing:
> - **ComposePress/core** develop line → `origin/master` @ `2f485a4` (local `master` `79f1029` is 6 commits behind; both unreleased). Clean-slate rewrite; `CHANGELOG.md` lists it under **Unreleased** with the historical runtime retired.
> - **Re-pinned at M1 implementation (this is the authoritative builder ref):** `composer.lock` resolves `composepress/core: dev-master` to the GitHub `master` HEAD **`1821ba9555c7e39be2a0e302690b95df89849293`** (`Merge pull request #12 from ComposePress/feat/testing-hook-engine`, 2026-09-13) — a descendant of the earlier inspected `2f485a4`. This SHA is what the build reproduces and installs; the installed footprint is the rewrite with `src/Testing/` doubles.
> - **ComposePress/starter** develop line → `origin/master` @ `8bbd1cd` (merge of `feature/rewrite-on-composepress-core` @ `259a5d4`). Starter's `composer.json` depends on **`composepress/core: dev-master`** via a vcs repository, i.e. it tracks core's master branch, not a tag.
>
> Before implementation, re-inspect and **re-pin** these SHAs from the sibling repos (or the then-current develop HEAD) and record the pinned SHAs in the build/`composer.lock`. Until a real `composer` release of core/starter exists, treat any rewrite feature surface as moving; the plan below describes the contracts **as inspected on develop**.

---

## 0. Problem & Goal

Users land in an HTTP-Basic-Auth-protected WordPress workspace (target/workspace **WordPress 7.1**) facing a cluttered, intimidating admin. We build an onboarding plugin that:

1. **De-clutters** the admin surface (cleanup policy, not just hiding).
2. **Guides** the user through the essentials, including a **guided, no-refresh page-builder install/activate** using WordPress core's `wp.updates` JS and admin-ajax actions.
3. **Recommends** a page-builder from an evaluated shortlist.
4. Ships with a **distribution/update strategy** that fits an auth-protected, offline-friendly workspace.

**Hard constraints (as inspected on develop):** PHP ≥ 8.2 (platform pinned `8.2.0`; CI matrix runs 8.2/8.3/8.4), PSR-12 (PHPCS `^3.13`), PHPStan **level 8** (`^2.2`), PHPUnit `^11.5`, PHP-Scoper `^0.18` artifact conventions (see §11), plus `yoast/phpunit-polyfills ^4.0` and `php-stubs/wordpress-stubs ^6.4` (note: **static-analysis stubs are 6.4 on develop**, not 7.1), and the HTTP-auth/loopback security posture below.

**Dev/test baseline (on approval):** the framework already provides the WP reference path on develop — core dev-deps vendor **`roots/wordpress-no-content ^7.1`** (installed to `var/wordpress`, backing the real `WP_Hook` engine used by the `ComposePress\Core\Testing` doubles) and core **integration tests require `WP_TESTS_DIR`** (the real WordPress test suite, see §11). Because the workspace image/package baseline targets **WP 7.1**, we pin **WP 7.1** as our integration reference rather than relying on remote-only access (develop CI currently installs the test suite at `latest`, so pinning 7.1 locally is a deliberate deviation we keep). No full WP checkout currently exists in `~/projects`; obtaining/pinning the WP 7.1 reference and its test suite is milestone **MB**.

---

## 1. Security Posture (non-negotiable)

> Loopback/localhost **bypasses** HTTP Basic Auth, so:
> **Loopback is a *transport* convenience for server→local-service calls only — it is NOT an authorization mechanism and must NEVER replace nonce/capability checks.**

- Every admin-ajax action (install/activate) uses WordPress core's existing checks: `check_ajax_referer('updates')` (nonce) + `install_plugins` / `activate_plugin` capability checks.
- **Loopback applies only to server→local-service traffic** (e.g. the server calling its own self-hosted update endpoint). It does **not** apply to normal outbound downloads (GitHub releases, WordPress.org, etc.) and is **never** a basis for browser/`admin-ajax` authorization.
- **Clarification (anti-misconception):** loopback has **no bearing on outbound downloads** (GitHub releases, WordPress.org, etc.) — those are normal outbound HTTP from the server and are unaffected by Basic Auth on the site. We do not layer loopback logic onto outbound fetches or onto admin-ajax requests.

---

## 2. Architecture

Layered on ComposePress contracts with **no dependency injection container**.

| Layer | Contents | ComposePress contract (as inspected on develop @ `2f485a4`) |
|---|---|---|
| Composition root | `OnboardingPlugin` bootstraps lifecycle, registers subscribers | `Plugin::__construct(context, subscribers, activator, deactivator, hooks, uninstaller, requirements)` + `PluginContext(file, slug, version)` + `Plugin::boot()`; `Plugin::isBooted()` |
| Lifecycle | activate/deactivate/uninstall, requirement checks — **opt-in/independent capabilities** | `PluginActivator::activate(bool $networkWide)`, `PluginDeactivator::deactivate(bool $networkWide)`, static-class `PluginUninstall::uninstall()`, `PluginRequirement::check(): RequirementResult`; unmet requirements are collected and **`RequirementsNotMet` is thrown, aborting boot** before subscribers register |
| Hooks | All WP action/filter wiring via subtype subscribers | `HookSubscriber::subscribe(Hooks $hooks)`, `Hooks`, `WordPressHooks` |
| Features | De-clutter, guided onboarding, page-builder install, recommendations, (later) update/auto-update | feature-specific `HookSubscriber`s |

- Module layout (mirrors how the **starter** rewrite is structured and would extend as our onboarding plugin):
  - `composepress-starter.php` — plugin header (Requires PHP 8.2) + `ABSPATH` guard + vendor-autoload existence guard (shows an `admin_notices` error and returns if `vendor/autoload.php` is missing) + `StarterPlugin::boot(__FILE__)`.
  - `src/StarterPlugin.php` — `final class` with private `SLUG`/`VERSION` consts and a `static boot()` composition root that constructs `Plugin(...)` then calls `$plugin->boot()`. We replace its sample `ExampleSubscriber`/`StarterActivator`/`StarterDeactivator`/`Uninstall` set with our own feature subscribers:
  - `src/Onboarding/` — de-clutter & guided flow, page-builder install/activate (admin JS + AJAX), recommendation engine.
  - `src/Admin/` — capability-aware controllers, nonce handling, asset enqueues.
  - `src/Update/` — (later) artifact update mechanism.
- Asset management: enqueue only the core `plugin-install` + `updates` (`wp-updates`) script handles; **no refresh**, no iframe overlay.
- All behavior behind interfaces so unit tests run under the framework's test doubles in **`ComposePress\Core\Testing`** (public, versioned with core): `RecordingHooks`, `RecordingSubscriber`, `RecordingActivator`, `RecordingDeactivator`, `SpyingUninstall`, `HookRegistration`. (There is **no `RecordingLifecycle`** on develop — lifecycle assertions use `RecordingActivator`/`RecordingDeactivator`/`SpyingUninstall`.) `RecordingHooks` delegates registration state to the **real `WP_Hook` engine**, so WordPress must be loadable in the test process (the running install for integration tests, or vendored `roots/wordpress-no-content ^7.1` at `var/wordpress` for unit tests); the unit bootstrap (`tests/bootstrap.php`) preloads `class-wp-hook.php` from `var/wordpress` when present.

---

## 3. Onboarding UX & Clutter Policy

**Principle:** only *hide/show* what the product controls is not enough — we also *remove and guide*. Define an explicit, reversible policy:

- **Clutter policy tiers** (each independently toggleable, defaults per tier):
  1. **Declutter (visual):** collapse/hide distracting admin menu items, dashboard widgets, metaboxes, and "At a Glance"-style vanity modules; keep everything one-click *restorable* (an "undo / show everything" switch) so nothing is destructively removed.
  2. **Guidance (onboarding funnel):** a single-purpose, minimal first-run wizard: welcome → (skip-able) essentials → page-builder selection.
  3. **Cleanup (opt-in, guarded):** disable feature-blob plugins and remove demo content **only** with explicit user confirmation.
- **Always-preserved surfaces (never collapsed/scoped away):** core/plugin/theme **update notifications**, **Site Health** and security-related surfaces, and **full-admin access** (declutter must not hide or gate anything an administrator normally needs to see or act on). Reopening/returning users always see the full admin unless they explicitly opted back into declutter.
- **Reopen/restore behavior:** the restore ("show everything") switch and the decision state persist across sessions; a user who reopens the workspace can re-confirm or revert declutter choices at any time, and nothing is permanently hidden without consent.
- **Non-clobber rule:** never disable/delete user content or plugins outside clearly-labeled, confirmed cleanup actions.
- Admin density: respect `screen_options`, editor prefs, and user capability; no style overrides that break a11y (focus states, contrast).
- Accepts criteria: functional on WordPress 7.1 classic + block (Site Editor) screens; works for an admin and (later) editor roles.

---

## 4. Page-Builder Evaluation & Recommendation

Presentation to the user: **native first**, then one visual option, then one lightweight block toolkit — with paid tiers / lock-in clearly flagged. Evaluation matrix (score each: ease-of-use, paid-vs-free/does Pinner need to pay, HTML/perf, lock-in, update compatibility, a11y).

### Proposed shortlist (concrete)

| Candidate | Role | Notes |
|---|---|---|
| **Native Gutenberg / Site Editor (WP 7.1)** | **Default** | First-party, free, no lock-in, no update-compat risk. Always offered first. |
| **Brizy (Free)** | Visual-builder candidate to validate | wp.org free edition; premium/lock-in flagged. Taken forward to M0 benchmark for validation, not assumed. |
| **GenerateBlocks** | Lightweight recommendation | Adds blocks onto the native editor; **Kadence Blocks** as fallback if GenerateBlocks fails usability/benchmark criteria. |
| Elementor / Beaver Builder | Comparison choices only (not defaults) | Retained for benchmarking/comparison; not installed by default. |
| WP FAIR | — | **Not recommended as primary** (pre-release; deprioritized). |

**Sourcing & cost (Pinner pays $0):** Pinner pays **$0** for the **free wp.org editions** we install. **Premium tiers are not auto-installed or bundled**: we never fetch, install, or bundle paid/upsell packages; only the free wp.org editions ship. Any premium features are surfaced as a cost flag, never silently enabled.

**Excluded from initial installer:** Spectra Legacy, and paid / non-wp.org **Bricks** and **Breakdance**. They are out of scope for the first installer (licensing, non-wp.org distribution).

**Avoid overclaiming:** this is a *proposed* shortlist with candidate roles, **not a final selection**. The final recommendation is **gated by the M0 benchmark/usability spike** (§7); we do not lock vendors or assert superiority before that data exists.

**Performance:** **no unsupported precise performance claims.** We do not assert numeric speedups. Instead we **mandate a prototype benchmark** (see §7 milestone M0) with our own before/after measurements (LCP, page weight, block-count) scoped to the evaluated builders for an honest comparison. 

---

## 5. Distribution & Update Recommendation

> **Critical distinction:** *plugin artifact delivery* (how a build gets onto the image/site) is separate from *WordPress in-app update channels* (how WP notices/installs a new version). We call both out explicitly.

### 5a. Artifact delivery (how binaries reach the workspace) — **RECOMMENDED: pinned/baked baseline with managed persistence**

Because `wp-content/plugins` is a **persistent volume** (survives redeploys), delivery must handle both *seeding new volumes* and *safely upgrading existing volumes*:

| Phase | Mechanism | Why / when |
|---|---|---|
| **1 (baseline, default)** | **CI builds a signed, versioned plugin zip** → the **workspace image pins and embeds that artifact**. An **init/reconciler mounts/seeds `wp-content/plugins`** from the embedded artifact. | Offline, reproducible, rollback = redeploy; simplest and safest for auth-protected workspaces |
| **1b (managed upgrade of existing volumes)** | Reconciler **upgrades only this managed plugin** on existing volumes, using a **backup → atomic replace → version-consistency policy** (never clobber user data; only touch our own plugin directory). | Keeps persistent volumes in sync with the image without destructive overwrites |
| **2 (optional, faster-cadence — NOT default)** | Runtime (**boot-time**) download of a pinned GitHub release with **SHA-256 + signature verification (cosign/gpg)** using CI-secret tokens | Only when a faster release cadence than image rebuild is genuinely required; not the default mode |
| **3 (later, optional)** | Composer package + optional **self-hosted wp-update-server** served over loopback and/or Composer plugin | Only if in-app push updates are required |

- **Version/backup policy:** each managed version is immutable (signed + versioned); upgrades take an atomic backup of the previous managed plugin before replacement and can roll back on failure. This process is **separate from, and never conflated with, WordPress in-app update channels** (§5b).
- **WP FAIR:** pre-release and deprioritized — **not the primary recommendation.**
- **Private GitHub in-app updaters (plugin-update-checker, github-updater):** flagged risk — they store **repo/DB tokens in the WP DB**, exposing credentials. Prefer image-embedded/boot-time verification using CI secrets over DB-stored tokens.

### 5b. In-app update channels (how WP shows/installs newer versions)

- Baseline: no in-app channel; version fixed at baked baseline.
- Deliberate update path (Phase 2/3): feed WP's standard update machinery (transient `update_plugins`) from the pinned/verified source, so users see updates inside the normal Plugins screen without side-channel tokens in the DB.
- WordPress.org channel **only if** the plugin becomes a public GPL product.

---

## 6. Guided No-Refresh Install/Activate (wp.updates)

Grounding: WordPress core supports no-refresh `install-plugin` and `activate-plugin` admin-ajax actions:

- Client: enqueue `wp-admin/js/updates.js` (`updates`/`wp-updates` handle); call `wp.updates.installPlugin({slug})` then `wp.updates.activatePlugin({slug, plugin, name})` — no full-page refresh.
- Server: `install-plugin` → `wp_ajax_install_plugin` (nonce via `check_ajax_referer('updates')`, cap `install_plugins`, `WP_Ajax_Upgrader_Skin` + `Plugin_Upgrader::install` → returns `activateUrl`); `activate-plugin` → `wp_ajax_activate_plugin` (cap `activate_plugin`). Both POST `action` + `_ajax_nonce='updates'` to `admin-ajax.php`.
- Our plugin **reuses these core endpoints** — we add the curated list, the guidance, and the failure/success UX; we do **not** re-implement install/update internals or weaken checks.
- Loopback note: not applicable to these installs (same-origin admin-ajax, normal auth path). Loopback only matters for server→local-service calls (e.g. Phase 3 endpoint).
- **Prototype risk (validate early):** `wp.updates.activatePlugin` requires the **plugin basename** (`plugin/plugin.php`), which our custom UI must reliably **derive or obtain** (not assume) for each shortlist candidate — e.g. from the plugin slug, from a fetched plugin metadata/`Plugin_Upgrader` result, or from the installed plugin path. Verify this derivation against **WP 7.1** in the M0 prototype **before locking the implementation** (see §9 risk 9).

---

## 7. TDD Workstreams (red–green–refactor), Milestones & Acceptance

### Milestones

| # | Milestone | Scope | Acceptance (tests green) |
|---|---|---|---|
| MB | **Dev/test baseline** | On approval, pin local **WP 7.1 reference** (`roots/wordpress-no-content ^7.1` at `var/wordpress`) + the **real WP test suite** (`WP_TESTS_DIR`) for integration tests. Core develop already vendors WP 7.1 and ships the `tests/Integration` harness; we pin the WP version to 7.1 (develop CI uses `latest`) and record the pinned core/starter develop SHAs | `WP_TESTS_DIR` integration harness runs against pinned WP 7.1 |
| M0 | **Prototype benchmark** | Prototype (explicitly non-production) measuring native vs candidate builders; validates shortlist and plugin-basename derivation | Records LCP/page-weight/block-count; no perf claims without this benchmark |
| M1 | Scaffold + lifecycle | ComposePress wiring, boot, **pinned develop refs**, opt-in activate/deactivate, static uninstall, requirement checks (unmet → `RequirementsNotMet`) | Unit: lifecycle + requirement tests (RecordingHooks / RecordingActivator / RecordingDeactivator / SpyingUninstall) |
| M2 | Declutter | Menu/widget/metabox collapse policy, restore toggle | Unit: policy + restore; integration: admin screen |
| M3 | Guided onboarding funnel | Wizard + essentials + skip paths | Unit + integration: wizard state machine |
| M4 | Page-builder install (no-refresh) | Curated list, `wp.updates` flow, server reuse, error/success UX | Unit: AJAX flow + nonce/cap tests; integration: fake install |
| M5 | Recommendation engine | Matrix-driven default + criteria surfaced to user | Unit: scoring + paid/lock-in flags |
| M6 | Distribution baseline | Baked pinned artifact + build/verify (SHA-256 + signature) | CI: artifact builds & verifies |
| M7 | Deliberate update | Boot pin/verify + update_plugins feeding (Phase 2) | Integration: pinned update path |
| M8 | Observability & rollback | Logging, health/status checks, redeploy-rollback docs | Acceptance: rollback = redeploy documented |

### TDD discipline (per feature)
- **RED:** one minimal failing test, real code, no mocks unless unavoidable; verify it fails for the *right* reason.
- **GREEN:** simplest code to pass; YAGNI.
- **REFACTOR:** only after green.
- Run checks from **package dir only**, using the scripts as defined on develop: core — `composer test` (unit, `phpunit.xml.dist`), `test:coverage`, `test:integration` (`phpunit.integration.xml.dist` needing `WP_TESTS_DIR`), `analyse` (PHPStan level 8), `style` (PSR-12 via PHPCS `^3.13`), `scope` / `scope:check` / `scope:compat` (PHP-Scoper `^0.18`); starter — `test`, `test:coverage`, `analyse`, `style`. Unit runs use the `$GLOBALS` WP-function shim bootstrap; integration uses the real WP test suite and `docker-compose.integration.yml`. **Never run from repo root.**

---

## 8. Observability, Rollback & Security

- **Observability:** structured logs for boot, install/activate outcomes, update verification; admin notices on success/failure; a simple status endpoint/flag for health checks.
- **Rollback:** immutable baked baseline → rollback is **redeploy of prior image**; keep prior image tags; document the procedure.
- **Security:** reuse core nonce/capability checks everywhere; never store repo/DB tokens in WP DB (use CI secrets); verify artifacts with SHA-256 + signature; container signing via cosign/Sigstore; no code that weakens auth; release artifacts PHP-Scoper-scoped into plugin-unique prefix.

---

## 9. Risks & Open Decisions

| # | Risk / decision | Mitigation / ask |
|---|---|---|
| 1 | Exact page-builder shortlist ("one visual + one lightweight toolkit") | Confirm which vendors; evaluate on M0 benchmark before locking |
| 2 | Paid tiers / lock-in sensitivity (does Pinner need to pay?) | Recommendation surfaces cost & lock-in flags explicitly |
| 3 | In-app update channel needed at all? | Default = baked baseline; only add Phase 3 if live push required |
| 4 | De-clutter aggressiveness vs user trust | Tiers + one-click restore; confirm defaults |
| 5 | WordPress 7.1 specifics (Site Editor screens) | Verify against workspace screenshot/instance during M2–M5 |
| 6 | Autoload/perf of onboarding vs overhead | Keep plugin lean; no heavy runtime deps beyond framework |
| 7 | Build/CI for scoped artifacts | Pin PHP-Scoper config; validate on M6 |
| 8 | Which signatures (cosign/gpg) preferred | Open — recommend cosign/Sigstore baseline |
| 9 | Custom UI must derive/obtain each plugin's **basename** for `wp.updates.activatePlugin` | Validate basename derivation against WP 7.1 in the M0 prototype before locking implementation |

---

## 10. Explicit Out-of-Scope (this phase)

- Composition/builder features beyond onboarding (the plugin is "multiple things," but we ship onboarding first).
- Building/replacing a page builder; shipping our own editor.
- Published WordPress.org plugin/GPL product activities.
- WP FAIR as the primary distribution path.
- Real end-user multi-role SaaS flows beyond admin (deferred, later milestone).
- Production code now — this plan is approval-stage only.

---

## 11. Key Authoritative Sources & Paths

- Framework (rewrites, **unreleased — pin exact develop commits, not tags**; inspected with `git show`, read-only):
  - `~/projects/composepress/core` — develop line `origin/master` @ `2f485a4` (local `master` `79f1029` behind 6). Clean-slate rewrite, `CHANGELOG.md` "Unreleased". Contracts as in §2; testing doubles in `src/Testing/` (`ComposePress\Core\Testing`).
  - `~/projects/composepress/starter` — develop line `origin/master` @ `8bbd1cd` (rewrite branch `feature/rewrite-on-composepress-core` @ `259a5d4`). `composer.json` requires **`composepress/core: dev-master`** (vcs repo `git@github.com:ComposePress/core.git`), so it tracks core's master, not a tag.
  - Autoload PSR-4: `ComposePress\Core\ -> src/` + `ComposePress\Core\Tests\ -> tests/` (core); `ComposePress\Starter\ -> src/` + `ComposePress\Starter\Tests\ -> tests/` (starter).
  - Tooling (from composer dev deps + configs): PHPUnit `^11.5`, PHPStan `^2.2` (level 8, `scanFiles` WP stubs, `bootstrapFiles tests/bootstrap.php`), PHPCS `^3.13` (PSR-12), PHP-Scoper `^0.18` (`scope`/`scope:check`/`scope:compat` + dual-prefix compat verifier), `php-stubs/wordpress-stubs ^6.4`, `yoast/phpunit-polyfills ^4.0`, `roots/wordpress-no-content ^7.1` (→ `var/wordpress`), `roots/wordpress-core-installer ^2.0`, platform `php 8.2.0`; CI matrix PHP 8.2/8.3/8.4.
  - Integration: core `tests/Integration/bootstrap.php` requires `WP_TESTS_DIR` (real WP test suite, `install-wp-tests.sh`); `phpunit.integration.xml.dist` + `docker-compose.integration.yml` (CI uses WP `latest`; we pin 7.1).
  - Scoping notes: core `scoper.inc.php` uses `finders` on `src/`, `exclude-functions` include `add_action`/`add_filter`/`remove_action`/`remove_filter`/`_wp_filter_build_unique_id`/`register_*_hook`/`plugin_dir_path`/`plugins_url`, `exclude-classes` `WP_Error`/`WP_Hook`/`WP_Post`, `exclude-constants` `ABSPATH`/`WP_PLUGIN_DIR`/`WP_CONTENT_DIR`/`WP_DEBUG`.
- WordPress core (target WP 7.1): `wp-admin/js/updates.js` (`wp.updates.installPlugin/activatePlugin`, 6.5+), `wp-admin/includes/ajax-actions.php` (`wp_ajax_install_plugin`, `wp_ajax_activate_plugin`, `WP_Ajax_Upgrader_Skin`, `Plugin_Upgrader::install`), security via `check_ajax_referer('updates')` + `install_plugins`/`activate_plugin` caps.
- Dev/test baseline: pinned **local WordPress 7.1 reference** (`roots/wordpress-no-content ^7.1`) + **real WP test suite** (`WP_TESTS_DIR`) for integration tests — core already vendors WP 7.1 and ships the harness; pin the WP version to 7.1 and record the pinned develop SHAs at approval.
- Standards/tooling: PHP ≥ 8.2 (platform 8.2.0; CI 8.2/8.3/8.4), PHPUnit 11.5, PHPStan level 8, PSR-12 (PHPCS), PHP-Scoper 0.18 artifact convention.
- Distribution: bake/pinned baseline → boot-time verified pull → (optional) Composer + self-hosted wp-update-server; cosign/Sigstore provenance; FAIR deprioritized (pre-release; project focus shifted to TYPO3, Feb 2026); private-GitHub updaters noted for DB-token risk.

---

---

## 12. Implementation Log (concrete facts, appended as slices land)

- **M1 (scaffold + lifecycle)** — landed: `CastActivator` (single + network `cast_version` option), `CastDeactivator` (no-op), `Uninstall`, `CastPlugin::boot()` composition root wiring the core `Plugin` with lifecycle handlers. Pinned dev ref recorded in `composer.lock`: `composepress/core` `dev-master` → `1821ba9555c7e39be2a0e302690b95df89849293`. Bootstrap shims `$GLOBALS['lumeweb_cast_*']` for the WP option/lifecycle functions. No front-end hooks.
- **First-run state + minimal admin entrypoint (this slice)** — landed: `Onboarding\{OnboardingState, OnboardingAction, OnboardingStateMachine, InvalidTransition, OnboardingStateService, UserMetaStore}` (states `not_started|in_progress|skipped|completed`, deterministic transitions, completed is terminal, invalid/missing stored value → `not_started`); `Persistence\WordPressUserMetaStore` (isolates `get_user_meta`/`update_user_meta`/`get_current_user_id`); `Admin\{RequestContext, WordPressRequestContext, OnboardingRequestHandler, OnboardingAdminSubscriber}`. `OnboardingAdminSubscriber` registers `admin_menu` (Getting Started, cap `manage_options`) + `admin_post_cast_onboarding_start|skip` handlers, each nonce (`wp_verify_nonce`/`wp_nonce_field`) + capability gated; renders an accessible page with heading, current state, and Start/Skip controls. `CastPlugin::boot()` now composes the onboarding subscribers. No front-end/content hooks; no builder install, decluttering, or page-builder UI in this slice. User-meta was used (not an option) because onboarding state is inherently per-user and WordPress user-meta is the native per-user persistence — no existing ComposePress constraint made user meta impossible.

- **Admin dashboard notice/entrypoint (this slice)** — landed in `Admin\\OnboardingAdminSubscriber`: registers `admin_notices` (alongside the existing `admin_menu` + `admin_post_cast_onboarding_start|skip`); new `renderNotice()` renders a capability-gated (`manage_options`, same gate as the Getting Started page) dashboard notice only for the current user when state is `not_started` or `in_progress`. It links to the Getting Started page (`admin.php?page=cast-getting-started`) and exposes a Skip control that posts to the existing nonce (`wp_nonce_field`/`wp_verify_nonce` `cast_onboarding`) + capability protected `admin_post_cast_onboarding_skip` path (handled by `OnboardingRequestHandler`). Hidden for `skipped`/`completed` and for users lacking the capability; it never suppresses other plugins' notices. No Mark Complete action, no decluttering, no plugin installation added in this slice; still no front-end hooks (all boot hooks remain admin-only). Tests: 50 unit green (was 44).

- **Curated page-builder catalog + recommendation data (this slice)** — landed: `PageBuilder\\{PageBuilderKind, PageBuilderCandidate, PageBuilderCatalog}` — a small, pure, immutable domain service plus its deterministic recommendation queries. Concrete entries: Native Gutenberg / Site Editor (isCore, isFree, `recommended=true`, `installable=false`, no plugin slug, verified `editorTarget='block'`), Brizy (Free) (`brizy`, VisualBuilder), GenerateBlocks (Free) (`generateblocks`, BlockToolkit) — the only two in the `installableCandidates()` allowlist; Kadence Blocks as Alternative metadata (`kadence-blocks`, **not** a fourth installer choice); Elementor + Beaver Builder as Comparison-only metadata (`elementor`/`beaver-builder`, never installable). Spectra Legacy / Bricks / Breakdance are absent from the catalog and explicitly excluded from the install allowlist. `find()`/`findInstallable()` resolve only known catalog slugs, so arbitrary request-slugs resolve to null (never invented on demand); `defaultRecommendation()` deterministically returns native. Metadata is non-numeric: performance notes assert no speed claims, `editorTarget` stays null until verified. `OnboardingAdminSubscriber` gained a read-only, accessible (`<section aria-labelledby>` + `<ul>`) recommendations list on the admin-only Getting Started page; `CastPlugin::boot()` composes the catalog. No mutations, no install, no decluttering, no front-end hooks. Tests: 66 unit green (was 50; +15 catalog +1 render).

- **Curated no-refresh install/activate via WordPress core `wp.updates` (this slice)** — **WP 7.1 API source checked first** (pinned `roots/wordpress-no-content` `7.1` in `vendor/`): `wp-admin/includes/ajax-actions.php` `wp_ajax_install_plugin` (L4486) returns `{install, slug, pluginName, activateUrl?}` with **no standalone basename** — the basename surfaces only inside `activateUrl` as the `plugin` query param; `wp_ajax_activate_plugin` (L4591) requires POST `name`+`slug`+`plugin` (basename), both `check_ajax_referer('updates')` and capability-gated (`install_plugins`, `activate_plugin`). `wp-admin/js/updates.js` `wp.updates.installPlugin` (L746) / `activatePlugin` (L1081) drive core admin-ajax and attach `_ajax_nonce` from `wp.updates.ajaxNonce` (L133) set from `_wpUpdatesSettings.ajax_nonce` — the `updates` script locals in `wp-includes/script-loader.php` (L1493–1499: `ajax_nonce => wp_create_nonce('updates')`). Implementation: **no page reload, no custom `Plugin_Upgrader`, no parallel installer endpoint** — the browser calls core `wp.updates.installPlugin({slug})` then derives the basename from `activateUrl` (`/(?:^|[?&])plugin=([^&]+)/` + `decodeURIComponent`) and calls `wp.updates.activatePlugin({slug, name, plugin})`, so core's nonce/capabilities are fully reused. **Server-side allowlist policy** in new `PageBuilder\\{PluginStateProvider, WordPressPluginStateProvider, PageBuilderInstaller}`: only `PageBuilderCatalog::installableCandidates()` (brizy, generateblocks) ever produce an install payload; native/comparison/alternative slugs (``, `elementor`, `beaver-builder`, `kadence-blocks`) and arbitrary request slugs (`brizy-premium`, etc.) resolve to `null`; button label/disabled state (`Install`/`Activate`/`Active`) derives from real filesystem state via get_plugins()/is_plugin_active() (exact first-segment directory match, so `brizy` never matches `brizy-premium`). Custom asset `assets/js/cast-install.js` is a browser IIFE that only acts on slugs the server localized into `castInstall.rows` (forged non-allowlisted DOM buttons are inert) and announces each install/activate/success/error into the accessible `aria-live="polite"` region. Enqueue gating: `admin_enqueue_scripts` → `manage_options` cap → current screen id `toplevel_page_cast-getting-started` → `wp_enqueue_script('updates')` + `cast-install` (dep `['updates']`, footer) + `wp_localize_script('castInstall', rows)`. New `templates/admin/page-builder-install.php` renders real PHP-template install controls (no output buffering/string-built HTML/fixtures), included from `templates/admin/page.php`. `OnboardingAdminSubscriber` gained a 5th hook + `registerAssets()`; `RequestContext`/`WordPressRequestContext` gained `currentScreenId()`. Render never persists onboarding state — state only changes through the existing start/skip admin-post flow. Tests: **92 → 95 PHPUnit green** (installer policy, state-provider basename derivation, template controls, enqueue/capability/screen gating, no-premature-state-persist) + **7/7 dependency-free Node tests** (`tests/js/cast-install.test.js`, `node:test` + injected window/document/wp doubles eval __against the real_ asset; zero deps). PHPStan 0, PHPCS 0, `composer lint` 0, `composer validate --strict` valid. `.phpcs.xml.dist` excludes `tests/js/*` (JS is node-tested, not PHP). No premium installs, no boot auto-install, no decluttering, no perf benchmark. **Integration blocker:** real install/activate needs a live WP admin-ajax + writable filesystem; unit/Node harnesses cannot verify against MySQL (`tests/Integration/README.md`).

**Next step:** Next smallest slice — a request-scoped, admin-only **install/activate handler** for the curated install allowlist: nonce (`updates`) + `install_plugins`/`activate_plugin` capability checks, catalog-allowlist validation of the requested slug (never trusting arbitrary slugs), reusing WordPress core `wp.updates`/admin-ajax instead of re-implementing install internals, and success/failure UX surfaced on the Getting Started page. **Status: landed in this slice** — the nonce/capability checks and allowlist validation live in the core admin-ajax handlers this frontend drives (core `updates` nonce + `install_plugins`/`activate_plugin` caps), and the success/failure UX is the live region + button-state transitions; no custom request-scoped handler was needed because inspection proved core admin-ajax already enforces exactly that policy. Still no auto-install on boot and no decluttering. The dev/test baseline (MB) and M0 benchmark remain prerequisites before locking the visual/lightweight candidate (Brizy/GenerateBlocks) as final.
