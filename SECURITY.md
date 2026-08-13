# Security review — integration_forgejo_gitea

Full code audit against the **OWASP Top 10 (2021)** and adjacent hardening guidance. Covers every code path landed at commit `df0583e`. Fixes for the findings called out below are in commit `<this-commit>`.

## Executive summary

| Severity | Finding | Status |
|---|---|---|
| **High** | Bearer tokens written to Nextcloud log through the exception context | **Fixed** — logger now records a redacted summary only |
| **Medium** | Raw upstream error strings leaked to the browser | **Fixed** — client now receives short codes (`upstream_500`, `request_failed`, …); details logged server-side |
| **Medium** | No validation on admin-entered instance URL — bad scheme, plain HTTP against a non-loopback host, or garbage all accepted | **Fixed** — validated at write, warning returned when HTTP used against a non-loopback host |
| **Low** | `TokenStorage::readSecret` swallows all `Exception`s silently | Kept — behaviour is intentional (empty token → user re-connects); noted for future PHP 8.2 `#[SensitiveParameter]` pass |
| **Low** | SSRF surface via admin-controlled `oauth_instance_url` | Accepted — admin-only, plus scheme/URL validation now applied |
| **Info** | No PKCE on OAuth flow | Accepted — 256-bit `state` + session-bound verification provides equivalent CSRF protection for the current threat model |

Everything else in the OWASP Top 10 either doesn't apply or was already handled by prior design; the mapping below spells that out per category.

---

## OWASP Top 10 mapping

### A01: Broken Access Control

**Findings**: none.

- Every user-scoped endpoint (`getRepos`, `getItems`, `getStats`, `getHeatmap`, `getNotifications`, `markNotificationRead`, …) carries `@NoAdminRequired` **and** relies on Nextcloud's DI-injected `?string $userId` for the current session. User data cannot cross sessions.
- Admin-scoped endpoint (`setAdminConfig`) has no annotation, so Nextcloud enforces admin.
- OAuth callback (`oauthRedirect`) requires a valid session (`?string $userId` is populated) and a session-bound `state`. External hits without a session hit an empty `$expected` and are rejected before token exchange.
- No horizontal/vertical privilege paths were found — every widget config key is namespaced under the app id and scoped to the calling user via `getUserValue($this->userId, ...)`.

### A02: Cryptographic Failures

**Findings**: none new; one item worth noting.

- Access + refresh tokens stored via `TokenStorage`, which wraps `OCP\Security\ICrypto` (AES-256-CBC with per-instance secret).
- OAuth `state` uses 32 bytes from `random_bytes()` — CSPRNG.
- State comparison via `hash_equals()` — timing-safe.
- Client secret stored via `IConfig::setAppValue` (encrypted on disk on typical NC setups).

Noted: PHP 8.2's `#[\SensitiveParameter]` attribute would be a nice future belt-and-braces layer on the ~10 methods that accept `$accessToken` — currently held back to keep PHP 8.1 compatibility.

### A03: Injection

**Findings**: none.

- **SQL**: no direct DB access. All persistence goes through `IConfig` which uses prepared statements internally.
- **Command**: `eval / exec / shell_exec / system / proc_open / popen` — none. Verified.
- **Header**: `setcookie / header()` — never called from app code. Response headers set only through NC's `DataResponse`/`RedirectResponse`.
- **Path traversal**: Every user-controllable segment used in a Forgejo API URL passes through `rawurlencode()`:
  - `getRepoIssues`: `rawurlencode($owner) . '/' . rawurlencode($repo)`
  - `getRepoCommits`, `getRepoMilestones`, `getRepoDetails`, `getLatestRelease`: same.
  - `markNotificationRead`: `rawurlencode($threadId)`.
- **NoSQL / LDAP / XML / SSRF-via-URL-in-body**: N/A — no dynamic template rendering, no XML parsing, no user-supplied URLs sent to `newClient()->post/get`.

### A04: Insecure Design

**Findings**: none actionable now; one noted item.

The OAuth flow uses server-generated `state` in the session with `hash_equals()` verification. This is sufficient CSRF protection against confused-deputy attacks. Adding **PKCE** would tighten defence against an attacker who somehow reads the state (browser extension, TLS-terminating proxy). Consider adding when Forgejo/Gitea support in the app broadens beyond trusted networks.

### A05: Security Misconfiguration

**Findings**: 1 (fixed).

- **Fixed — instance URL validation**: `ConfigController::setAdminConfig` did no shape check on `oauth_instance_url`. An empty scheme, a `file://` URL, or a typo was accepted silently. Now:
  - Reject if `parse_url` doesn't yield both scheme and host.
  - Reject any scheme except `http` and `https`.
  - Return an `http_url_not_recommended` warning when HTTP is used against a non-loopback host — dev setups on `localhost` / `127.0.0.1` / `*.localhost` still allowed silently.

### A06: Vulnerable and Outdated Components

**Findings**: none blocking; run `npm audit` / `composer audit` as part of release process.

- Vue 3.5+, @nextcloud/vue 9.8+, axios via @nextcloud/axios 2.6+ — all current at time of audit.
- No abandoned packages in the dependency tree.
- Nextcloud target range 30–34 is currently-supported.

Recommend running `npm audit` and `composer audit` in CI (both are already available as scripts).

### A07: Identification and Authentication Failures

**Findings**: none.

- No custom auth logic — Nextcloud session is the source of truth for `$userId`.
- Forgejo OAuth follows the authorization-code grant per RFC 6749.
- `code` + `state` are checked, `state` is single-use (removed from session on entry to `oauthRedirect`), refresh tokens are stored encrypted.
- No brute-forceable endpoints exposed.

### A08: Software and Data Integrity Failures

**Findings**: none.

- App is fetched via `git pull` from a repo the operator controls.
- No dynamic code loading (`eval`, `include $var`).
- Autoloading via composer's PSR-4 — no arbitrary class instantiation.

### A09: Security Logging and Monitoring Failures

**Findings**: 1 (fixed) — **the important one this pass**.

- **Fixed — Bearer tokens in logs**: `ForgejoGiteaAPIService::request` was previously calling:
  ```php
  $this->logger->warning('... ' . $e->getMessage(), ['exception' => $e]);
  ```
  Nextcloud's logger, when handed the exception object in the context, serialises the entire Guzzle request context — including the `Authorization: Bearer …` header. Real token strings were visible in `nextcloud.log`. **This is the finding you already spotted yourself in a shared log line during earlier debugging.**

  **Fix**: log a structured summary with the access token redacted, never the exception object:
  ```php
  $this->logger->warning('Forgejo/Gitea request failed', [
      'endpoint' => $endpoint,
      'method' => $method,
      'reason' => $this->redactSecrets($e->getMessage(), $accessToken),
  ]);
  ```
  Same treatment applied to the OAuth token-exchange path, redacting `client_secret` and `refresh_token` from the log message.

- **Fixed — leaked upstream detail**: The service used to return `['error' => 'HTTP 500: ' . substr($body, 0, 200)]` to the client, i.e. the first 200 bytes of the upstream response body were forwarded verbatim. That could reveal internal endpoint paths or stack fragments from a misbehaving Forgejo. Client responses are now generic (`upstream_500`, `request_failed`, `token_exchange_failed`), and the detail (still redacted) goes to `logger->info`/`warning` for operator inspection.

### A10: Server-Side Request Forgery (SSRF)

**Findings**: 1 residual, accepted with mitigations.

- All outbound HTTP goes to a URL prefixed with `oauth_instance_url` — which is **admin-configured**.
- An admin who has already compromised the Nextcloud admin session could, in principle, point the URL at an internal service and use the app to probe it. Impact is limited (only `/api/v1/*`, `/login/oauth/access_token` paths get called) and no response body is exposed to a non-admin caller (see A09 fix above).
- Nextcloud's `IClient` runs the calls with `allow_local_address: true` — that's Nextcloud's default and can't be overridden per call in the current NC API. If tighter isolation is needed, run Nextcloud in a network segment that cannot reach the internal targets.
- **Mitigation added**: URL validation on admin write (see A05) rejects garbage schemes and warns on non-loopback HTTP.

Ranking: **Low** — requires an already-compromised admin.

---

## Frontend audit (short)

**XSS surface**:
- `v-html` — searched, zero occurrences. All user content flows through `{{ }}` interpolation which Vue auto-escapes.
- `:href` from Forgejo API — bindings pass through Vue's URL sanitiser; browsers won't execute `javascript:` URLs from `<a href>` in Chrome/Firefox regardless.

**CSRF**:
- `@nextcloud/axios` auto-injects the requesttoken header on `POST`/`PUT`/`PATCH`/`DELETE`. All state-changing endpoints check it.
- The only endpoint that skips the check is `oauthRedirect` (`@NoCSRFRequired`), which is intentional (external Forgejo callback with no NC session token). The OAuth `state` parameter provides equivalent protection.

**Session fixation / cookie handling**:
- Not applicable — the app doesn't set cookies. NC session cookies are `HttpOnly` and `SameSite=Lax` by default.

**Third-party asset loading**:
- The build bundles everything with webpack — no runtime CDN. Avatars are the one exception: `<img :src="user.avatar_url">` loads from the Forgejo instance. If a compromised Forgejo returned a data-exfiltration URL, only the Nextcloud user's IP address leaks. Nextcloud's CSP `img-src` policy already restricts to `self` + a curated allow-list; the org can extend it to the Forgejo instance for a green-tick UX.

---

## Recommendations for the operator

Not code changes — operational advice for a Njordium deployment:

1. **Rotate secrets** after any admin-log inspection between now and the deploy of these fixes. The log entries from before the A09 fix carried real Bearer tokens.
2. **Prefer HTTPS** to your Forgejo/Gitea. The URL validator warns on non-loopback HTTP but doesn't hard-refuse — some dev setups need HTTP.
3. **Restrict Nextcloud's outbound network** if you handle high-sensitivity data. The `allow_local_address: true` inside Nextcloud's own HTTP client is a general concern beyond this app.
4. **Enable `occ log:tail` monitoring** with a redactor if you don't have one, so that a future logger change (in any app) doesn't quietly resume writing tokens.

---

## Follow-up items (deliberately out of scope)

Each of these is a real hardening opportunity, none is a live vulnerability today:

- Add PKCE to the OAuth flow.
- Bump `composer.json` to `php: ^8.2` and apply `#[\SensitiveParameter]` to every method that takes `$accessToken`, `$refreshToken`, `$clientSecret`.
- Add automated `composer audit` + `npm audit` to CI.
- Add a rate limit on `/apps/integration_forgejo_gitea/config` PUT (per user) — currently unlimited; not exploitable in practice because writes are user-scoped and small, but hardening.
