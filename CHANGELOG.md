# Change Log

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.3.1] - 2026-08-19

### Fixed
- `appinfo/info.xml` `<summary>` shortened from 154 to 126 characters. The App Store validator caps `<summary>` at 128 chars via `apps/info.xsd`; the v1.3.0 upload was rejected with `Element 'summary': [facet 'maxLength'] The value has a length of '154'; this exceeds the allowed maximum length of '128'.`. New form drops the "pull requests" long-form for `PRs` and merges the trailing `and a contribution heatmap` into the enumerated list. No functional change; App Store metadata only.

## [1.3.0] - 2026-08-14

Full security-hardening pass driven by the OWASP Top 10 (2021) + adjacent-concerns audit. Ten findings, no Highs, all landed. See `SECURITY.md` for the full mapping; per-finding notes below.

### Security — Medium

- **F1 — `ConfigController::setConfig` now allowlists keys.** Previously any authenticated user could POST arbitrary user-scoped keys (including overwriting their own OAuth `token` / `refresh_token` with plaintext, or bloating `oc_preferences` with unbounded blobs). New `ALLOWED_USER_KEYS` exact list + `ALLOWED_USER_KEY_SUFFIXES` widget-key suffix list; unknown keys are silently dropped so older Vue bundles that still POST retired keys after an upgrade don't 400.
- **F2 — TOCTOU race in the OAuth-refresh path fixed.** The dashboard fires 8+ widget requests in parallel; if the access token had expired, every one 401'd and raced to POST `/login/oauth/access_token` with the same `refresh_token`. Forgejo rotates refresh tokens on use → only the winner succeeded and the losers marked `refresh_failed` and disconnected the user. `retryAfterRefresh()` and `tryRefresh()` now take the stale access token, and if the stored token has changed since the 401 (another thread already refreshed) the request retries with the fresh token instead of re-refreshing.
- **F3 — Admin URL/client-id rotation now invalidates all user tokens.** If an admin changed `oauth_instance_url` (or `client_id`) without clearing stored tokens, the next widget refresh sent every user's still-valid bearer to the new host. `setAdminConfig` now detects the change, clears `token` / `refresh_token` / `user_name` / `user_id` for every affected user, and returns a `users_reconnect_required` warning the frontend surfaces to the admin.

### Security — Low

- **F4 — `#[BruteForceProtection]` added to OAuth endpoints.** `oauthStart` and `oauthRedirect` now carry the attribute and `->throttle()` on invalid-state / token-exchange-failure branches, so retry storms against the callback path are rate-limited by Nextcloud's built-in bruteforce infrastructure.
- **F5 — `safeHref()` helper wraps every `:href` bound to upstream data.** Vue 3 does NOT sanitize the scheme of a `:href` attribute; a compromised or malicious Forgejo returning `html_url: "javascript:…"` would otherwise execute on click. New `src/utils.js::safeHref()` returns the URL only when it parses as http(s), null otherwise. Wired into all 7 `:href="…html_url"` sites across `IssuesWidget`, `NotificationsWidget`, `MilestonesWidget`, `RecentCommitsWidget`, `PendingReviewsWidget`, `RepoStatsWidget`.
- **F6 — Dead `/avatar` route removed.** `ForgejoGiteaAPIController::getForgejoGiteaAvatar()` echoed any `?url=` parameter and had no frontend consumer. Deleted along with its route entry so a future accidental "let's proxy avatars" implementation cannot silently turn into an SSRF / open-redirect.
- **F7 — `client_secret` no longer re-serialised into the admin DOM.** `Admin::getForm` now provides `client_secret_set: bool` instead of the raw value; the admin field shows a "Leave empty to keep the stored secret" placeholder when set. Save only sends `client_secret` when the admin actually typed a new value.

### Security — Info / hygiene

- **F8 — CI regression guard against the v1.0.0 A09 pattern.** A new `security-grep` job in `.github/workflows/lint.yml` fails the build if `['exception' => $e]` reappears in a `logger->…` call under `lib/`. Prevents accidental reintroduction of the bearer-token-in-log leak that shipped in v0.0.x.
- **F9 — `.DS_Store` added to the `make appstore` exclude list** so a stray macOS metadata file cannot ship in a release tarball.
- **F10 — Bug fix: `catch { }` with unbound `e` in `PersonalSettings.vue:135`.** Would throw `ReferenceError` and mask the real OAuth-start failure. Now `catch (e) {`.

### Changed

- Bumped `@nextcloud/dialogs` (^7.4.1), `@nextcloud/vue` (^9.9.0), `@nextcloud/webpack-vue-config` (^7.0.4) — clears the runtime-reachable Dependabot alerts (dompurify, nanoid) reported against the older versions. Remaining audit findings are all build-time only (postcss transitive) and do not ship in the compiled bundle.

## [1.2.1] - 2026-08-14

### Changed
- Empty-state and status blocks (loading / not-connected / error / empty) across all six list widgets now render centered vertically inside the card with a 40px `CheckCircleOutlineIcon` above the message at 0.5 opacity. Mirrors integration_suitecrm's `.scw-status` treatment so "No pending reviews. Nice." and its siblings land visually consistent between the two apps. Previously status text sat left-aligned at the top of the widget, which read as an error more than a positive empty state.

## [1.2.0] - 2026-08-14

### Added
- **"Records to show" per-widget setting.** All six list widgets — Open Issues, Closed Issues, Open PR, Closed PR (all four via `IssuesWidget`), Reviews, Commits, Milestones, Repo stats, Notifications — now expose a `[5, 10, 15, 20, 25, 50]` picker in the ⋯ settings modal (default 20). Persists per user, per widget under `<prefix>_max_items`. Reused shared `MaxItemsPicker.vue` component so the seven modals stay in lockstep. Matches the equivalent setting on the sibling `integration_suitecrm` widgets.
- **Internally scrollable lists.** `.fgw-list { max-height: 320px; overflow-y: auto }` on every list widget so 20+ items fit inside the dashboard-card silhouette instead of stretching the card taller than its neighbours. Wheel/trackpad scroll inside the widget; the surrounding dashboard row stays fixed. Same treatment `integration_suitecrm`'s `.scw-list` uses.

### Changed
- Frontend now reads `config.max_items` from every list-widget response (backend plumbing landed in v1.1.3), removes the hard-coded `MAX_VISIBLE(_ITEMS)` constants.

## [1.1.4] - 2026-08-14

### Fixed
- Brand-icon `background-size: 20px 20px` rule from v1.1.3 was being outweighed by Nextcloud's own dashboard-widget-header CSS which pins `background-size: contain` on the icon slot with an equal-specificity, later-cascade origin. Added `!important` on our declaration so the intended optical size wins. Verified live in a private Firefox window post-deploy.

## [1.1.3] - 2026-08-14

### Changed
- Brand icons (Forgejo horn, Gitea teacup) now pin to `background-size: 20px 20px` so the widget-header icon renders at the same visual weight as `integration_suitecrm`'s badge. Previously the SVG artwork stretched to `contain` inside the header slot and read as noticeably larger than the sibling app.

### Added (server-side plumbing; no UI change yet)
- Controller exposes a new per-widget `max_items` field on every list-widget response (issues, pulls, notifications, commits, milestones, repo_stats, reviews), validated against `ALLOWED_MAX_ITEMS = [5, 10, 15, 20, 25, 50]` with a default of 20. Vue widgets will start reading this in the next minor release to add a "Records to show" picker to the settings modal — this release lays the config plumbing without changing any user-visible behaviour.
- `MAX_ITEMS_PER_WIDGET` and per-endpoint fetch limits raised to 50 so a future user-picked value of 50 is not clipped by the backend cap.

## [1.1.2] - 2026-08-14

### Fixed
- Overview tile counts capped at 50 (Forgejo's max page size for `/repos/issues/search`). The old code counted rows in a single page, so a repo with hundreds of closed issues rendered as `50` instead of the real total. Added `ForgejoGiteaAPIService::countIssueSearch()` which reads the `X-Total-Count` response header from a `limit=1` request — one small HTTP call per tile, exact count regardless of size. All eight Overview tiles now use this path, so personal counts (assigned / created / mentioned / review-requested) also render the real value if they ever exceed 50.

## [1.1.1] - 2026-08-14

### Fixed
- Overview widget: KPI tiles no longer cap the display at `50+`. The cap was fine for single-digit personal counts but hid the actual value on the new instance-wide total tiles (Open issues / Closed issues), where the number IS the point. Tiles now render the exact count.

## [1.1.0] - 2026-08-14

### Added
- **Overview widget: two instance-wide total tiles.** "Open issues (total)" and "Closed issues (total)" join the existing six user-scoped KPIs, expanding the widget to an 8-tile 4×2 grid. Both are counted via `/repos/issues/search` with no user filter, so they reflect every issue in every repo the connected user's token can read — useful as a project-health snapshot alongside the personal work-queue counts. Tiles link to `/issues?state=open&type=your_repositories` (and the closed equivalent) on the configured instance.

## [1.0.3] - 2026-08-13

### Fixed
- Widgets showing stale data after the machine woke from sleep or the browser tab regained focus. The `useAutoRefresh` composable relied on `visibilitychange` alone, which does not fire on laptop wake when the tab was already the frontmost tab before sleep — leaving widgets frozen on the last pre-sleep snapshot until a manual reload. The composable now also listens for `window.focus` and `pageshow`, and tracks `lastFetchAt` so any wake signal triggers an immediate refetch when the data is older than a minute.

## [1.0.2] - 2026-08-13

### Fixed
- CI PHPStan install: pin `nextcloud/ocp` to `dev-stable30`. The previous `dev-master` constraint pulled in a PHP `~8.3 || ~8.4 || ~8.5` requirement that conflicted with the app's declared PHP 8.1 floor, blocking every CI run.
- PHPStan analysis: point `scanDirectories` at `vendor/nextcloud/ocp/OCP` so PHPStan can resolve `OCP\*` classes (338 → 0 errors at level 5). Added ignore patterns for the `DataResponse<T>` generic checks (out of scope for this integration) and two PHPDoc-inferred unreachable-ternary false positives.
- `ForgejoGiteaAPIService::getUserRepos()`: relaxed the `@return` shape to `list<array<string, mixed>>` so it reflects the real Forgejo response (many more fields than the four the controller ends up projecting).
- `ConfigController::setAdminConfig()`: dropped the redundant `?? ''` on `parse_url()['host']` — the outer `isset()` already guarantees it.

### Changed
- `js/` bundles rebuilt so the shipped assets match the `1.0.2` manifest version.

## [1.0.1] - 2026-08-13

### Changed
- Lint hygiene pass: zero errors, zero warnings.
    - `eslint --fix` + `stylelint --fix` cleaned up 470 style issues.
    - Global sed dropped unused `catch (e)` bindings and now-redundant `console.error(e)` calls that duplicated the `showError` toast.
    - Perl expansion split compound `this.loading = true; …` openers and expanded collapsed 401-branch `if/else` blocks.
    - Restored `catch (e)` where the block references `e`.
    - Two `eslint.config.mjs` project overrides: `max-statements-per-line=2` (allow single-line guard clauses) and `jsdoc/require-param-type` + `require-param-description` off (no TypeScript in the tree).
    - `@license` values use the SPDX identifier `AGPL-3.0-or-later`.
- `appinfo/info.xml` aligned with `integration_suitecrm`: XSD schema on the root element, rich CDATA description grouped by intent (Overview / Work queue / Activity / Repositories / Notifications), single-author entry with homepage, `<repository type="git">`, `<screenshot>` with `small-thumbnail`, explicit `<php min-version="8.1"/>`.

## [1.0.0] - 2026-08-13

First Nextcloud App Store release. Eleven configurable dashboard widgets, OAuth 2.0 authorization-code flow, dual Forgejo/Gitea support from a single codepath.

### Added
- **Dashboard widgets (eleven total)** grouped by intent:
    - **Overview.** Six KPI tiles in a compact grid — open issues assigned, open issues I opened, open PRs where my review is requested, open PRs I opened, open issues mentioning me, contributions in the last 7 days. Each tile deep-links to the equivalent Forgejo/Gitea filter.
    - **Work queue.** Open Issues, Closed Issues, Open PR, Closed PR, Reviews.
    - **Activity.** Commits (with an "only mine" toggle) and a GitHub-style contribution Heatmap with a stats grid (total, this week, this month, current streak, longest streak, best day).
    - **Repositories.** Milestones (progress bars) and Repo stats (per-repo card with stars, forks, open issues, open PRs, latest release tag).
    - **Notifications.** Unread inbox with type icons and per-row mark-as-read.
- **Per-widget settings modal** (⋯ menu) with a searchable multi-select repository picker, filter radio (assigned / created / mentioning / all), and a Refresh frequency picker (Never / 30s / 1m / 5m default / 15m / 30m / 1h). Settings persist per user, per widget.
- **Auto-refresh with tab-hidden pause** — widgets stop polling while the browser tab is hidden and re-fetch once when it regains focus.
- **Heatmap time-window toggle** (13 or 26 weeks) with adaptive cell sizing.
- **Brand-aware UX** — admin picks the instance type (Forgejo or Gitea); every widget's title and icon switches accordingly. Ships real logos: Forgejo horn (CC-BY-SA-4.0, Caesar Schinas), Gitea teacup (CC-BY-4.0).
- **Personal Settings override** for the Forgejo/Gitea username used in filter queries when it differs from the Nextcloud user id.
- **OAuth 2.0 authorization-code flow** end-to-end.
    - 32-byte session-bound `state` parameter verified with `hash_equals()`.
    - Access and refresh tokens encrypted at rest via Nextcloud's `ICrypto`.
    - Automatic refresh on 401 via the stored refresh token.
- **Admin OAuth settings form** — client id, client secret, instance URL, default instance type. Redirect URI shown with a copy button so it can be pasted byte-for-byte into the Forgejo/Gitea OAuth application.

### Security
- OWASP Top 10 review documented in [`SECURITY.md`](SECURITY.md).
- **A05 — Instance URL validation.** Scheme restricted to `http` / `https`; non-loopback HTTP triggers an admin warning.
- **A09 — Bearer tokens redacted from log messages.** Structured summary logging with a `redactSecrets()` helper replaces prior Guzzle-exception serialisation that could write access tokens into `nextcloud.log`.
- **A09 — Upstream error detail** kept in the server log; the browser response carries only a short code (`request_failed`, `upstream_500`, `not_connected`).

### Fixed
- **CSRF-blocked OAuth callback.** The external Forgejo redirect had no CSRF token; added `@NoCSRFRequired` with the `state` parameter as protection.
- **Vue 3 reactivity in widget settings modal.** Replaced `.sync` (Vue 2 pattern) with `v-model` so radio buttons update correctly.
- **Overview stats counts.** `/repos/issues/search` silently ignores `assigned_by=username` / `created_by=username`; switched to the boolean `assigned=true` / `created=true` / `review_requested=true` filters that respect the bearer identity.
- **Expired-token refresh path.** Added `http_errors => false` to the Guzzle options so 4xx responses don't throw before the 401-retry branch runs.
- **"Failed to load items" runtime error.** Widget `setup()` functions now expose `setIntervalMs` from the `useAutoRefresh` composable via `Object.assign(bridge, refresh)`.
- **"Show all" link** now centres inside the widget card via `align-self: center` + `margin: auto` (belt-and-braces), and sizes to content instead of stretching to the widget width.
- **Heatmap "Show all" link** points at `/{username}?tab=activity` (where the heatmap actually lives) instead of the empty profile default.
- **Overview stats tile links** use `/issues?type=assigned` (or the corresponding filter) instead of `type=your_repositories`.
- Widget titles shortened to survive narrower dashboard columns: `Recent commits` → `Commits`, `Pending reviews` → `Reviews`, `Repository stats` → `Repo stats`, `Pull Requests` → `PR`.
- Avatar contrast, widget overflow / max-height defensive caps, widget settings menu visibility, avatar fallback, "N more unread" collapse in Notifications.

### Changed
- App bumped through several pre-release versions (`0.0.1` → `0.0.2` → `1.0.0`) during scaffold, brand-icon swap, and screenshot rollout — the `1.0.0` tag is the first Nextcloud App Store submission.

## 0.0.1 - 2026-08-13

Initial scaffold — fork of [`njordium/integration_suitecrm`](https://github.com/njordium/integration_suitecrm) with a full rename pass (`SuiteCRM` → `ForgejoGitea`, namespace `OCA\ForgejoGitea`, app id `integration_forgejo_gitea`). No user-facing functionality yet — placeholder widget stubs, settings-page skeleton, webpack build pipeline in place.

[Unreleased]: https://github.com/njordium/integration_forgejo_gitea/compare/v1.3.1...HEAD
[1.3.1]: https://github.com/njordium/integration_forgejo_gitea/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/njordium/integration_forgejo_gitea/compare/v1.2.1...v1.3.0
[1.2.1]: https://github.com/njordium/integration_forgejo_gitea/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/njordium/integration_forgejo_gitea/compare/v1.1.4...v1.2.0
[1.1.4]: https://github.com/njordium/integration_forgejo_gitea/compare/v1.1.3...v1.1.4
[1.1.3]: https://github.com/njordium/integration_forgejo_gitea/compare/v1.1.2...v1.1.3
[1.1.2]: https://github.com/njordium/integration_forgejo_gitea/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/njordium/integration_forgejo_gitea/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/njordium/integration_forgejo_gitea/compare/v1.0.3...v1.1.0
[1.0.3]: https://github.com/njordium/integration_forgejo_gitea/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/njordium/integration_forgejo_gitea/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/njordium/integration_forgejo_gitea/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/njordium/integration_forgejo_gitea/releases/tag/v1.0.0
