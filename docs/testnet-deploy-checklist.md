# Testnet Deploy Checklist

Last updated: 2026-07-19 (§1.6 caching content current through the 2026-07-13/15/19 fixes)

One pass through this document takes a fresh server + Vercel project to a
testnet-ready BCC deployment. It consolidates requirements that were
previously scattered across `GOLDEN_PATHS.md`, `cron-registry.md`,
`operator-runbook.md`, and the smoke-test pre-flight.

> **Deploying plugin code?** See [deploy-runbook.md](deploy-runbook.md) — the
> commit-driven GitHub Actions pipeline (staging auto on merge, production
> manual) that replaced Git Updater. This checklist is for *provisioning* a
> fresh environment; the runbook is for *shipping code* to an existing one.

**No secret VALUES belong in this file** — only the names, where they live,
and how to generate them. Actual values go in wp-config.php / Vercel env
only (see `feedback_secrets_admin_visibility` doctrine).

---

## 1. WordPress: wp-config.php constants

### 1.1 Required secrets — generate FRESH for testnet (do not reuse local dev values)

Every value currently in the local wp-config is a dev value and is
considered burned. Generate new ones:

| Constant | Purpose | Generate with |
|---|---|---|
| `BCC_ENCRYPTION_KEY` | At-rest encryption for stored tokens. **If missing, all trust/dispute/onchain API calls return 403 for non-admins — BCC features go offline (the WP site itself still loads).** | `openssl rand -base64 48` |
| `BCC_INTERNAL_VERIFY_SECRET` | `X-Bcc-Internal` header for wallet-signature verify bridge. Must match the Vercel env var of the same name. | `openssl rand -base64 32` |
| `BCC_INTERNAL_CRON_SECRET` | Internal cron-trigger auth. Deliberately separate from VERIFY so leaking one doesn't widen the other. | `openssl rand -base64 32` |
| `BCC_OAUTH_BRIDGE_SECRET` | `X-Bcc-Oauth-Secret` header from the NextAuth OAuth bridge. Must match Vercel. **Fail-closed: SSO is disabled until set on BOTH ends.** | `openssl rand -hex 32` |
| `BCC_HELIUS_WEBHOOK_SECRET` | Authenticates inbound Helius webhooks. Must match the webhook config in the Helius dashboard. | `openssl rand -hex 32` |
| `BCC_PUSH_VAPID_PUBLIC_KEY` / `BCC_PUSH_VAPID_PRIVATE_KEY` / `BCC_PUSH_VAPID_SUBJECT` | Web-push. Subject = the site's canonical https URL. | `npx web-push generate-vapid-keys` |

### 1.2 Third-party API credentials (provision per environment)

| Constant | Provider |
|---|---|
| `BCC_ALCHEMY_API_KEY` | Alchemy (EVM NFT + RPC). Watch app-level CU cap — provider-wide exhaustion is a known distinct failure class. |
| `BCC_HELIUS_API_KEY` | Helius (Solana). |
| `BCC_ETHERSCAN_API_KEY` | Etherscan. |
| `BCC_SUBSCAN_API_KEY` | Subscan (Polkadot validator path). |
| `BCC_GITHUB_CLIENT_ID` / `BCC_GITHUB_CLIENT_SECRET` | GitHub OAuth app — create a NEW app whose callback points at the testnet WP host. |
| `BCC_X_CLIENT_ID` / `BCC_X_CLIENT_SECRET` | X (Twitter) OAuth app — **MANUAL, still open**: the X developer-portal callback URL must be added for the NextAuth flow (SSO-hardening leftover, 2026-06-11). |

Optional RPC overrides if not using provider defaults:
`BCC_ETH_RPC_URL`, `BCC_SOL_RPC_URL` / `BCC_SOLANA_RPC_URL`, `BCC_HELIUS_RPC_URL`,
`BCC_ETH_DAILY_RPC_BUDGET`.

### 1.3 Environment & mode flags — values that MUST CHANGE from local dev

| Constant | Local dev | Testnet |
|---|---|---|
| `BCC_ENV` | `'local'` | `'testnet'` (drives the env banner) |
| `WP_ENVIRONMENT_TYPE` | `'local'` | `'staging'` |
| `BCC_FRONTEND_ORIGIN` | `http://localhost:3000` | The Vercel deployment URL (no trailing slash) — drives CORS + redirects |
| `BCC_HIGHLIGHTS_DEMO` | `true` | **remove / `false`** — demo data must not render on testnet |
| `BCC_REPAIR_ENABLED` | `true` | **`false`** — repair surface is dev-only |
| `BCC_TRUST_TEST_MODE` | unset | **must be unset** — relaxes trust-engine gates when on |
| `BCC_DEGRADATION_ALERT_EMAIL` | unset (falls back to `admin_email`) | A monitored operator inbox — the DegradationAlerter push sink (proven end-to-end on Local 2026-07-02). Optional: `BCC_DEGRADATION_ALERT_WEBHOOK` (out-of-band channel; carries P1 ahead of mail), `BCC_DEGRADATION_ALERT_THRESHOLD` (default 5 summed events across current+previous hour) |

> **Remove the demo seeder from the deploy artifact.**
> `wp-content/mu-plugins/bcc-demo-seeder.php` auto-loads and, on the
> first `manage_options` login, fabricates elite/trusted trust scores
> into `wp_bcc_trust_page_scores`. It now self-guards — it bails unless
> `wp_get_environment_type()` is `local`/`development` (testnet is
> `staging`, so it no-ops there) — but delete the file from the deployed
> `wp-content` anyway: defence in depth against a mis-set
> `WP_ENVIRONMENT_TYPE`. Confirm no `blacksmith-node` / `foundry` /
> `welder-studio` peepso-pages exist post-deploy.

### 1.4 Reverse-proxy / IP correctness (fraud signals depend on real IPs)

If testnet sits behind Cloudflare or any proxy:
`BCC_BEHIND_CLOUDFLARE` (true) or `BCC_TRUSTED_PROXY_IPS` (CSV), and set
`BCC_REQUIRE_TRUSTED_PROXY_CONFIG` so misconfiguration fails loudly instead
of silently attributing the proxy IP to every user.

### 1.5 Object cache

**On the current shared tier: LSMCD** (LiteSpeed Memcached via LSCWP → Cache
→ Object — live on staging since 2026-07-16; Redis is **not offered** on
shared plans, see §1.6). The Redis path below applies to a **VPS/Agency
tier** move: Redis drop-in (`WP_REDIS_HOST/PORT/PREFIX/DATABASE`) + `WP_CACHE`.
After ANY restore/clone of wp_options: `wp cache flush`. A stale persistent
cache serving an old `bcc_trust_schema_version` re-runs the ~200-query
schema installer on every request (see boot-floor fix, 2026-06-12 — the
gate now logs `[bcc-trust] schema migration firing` when it triggers;
seeing that more than once per deploy = stale cache).

- [ ] **`WP_REDIS_TIMEOUT` trap (P0 — biggest deploy footgun).** Local dev
      runs the active `object-cache.php` drop-in with `WP_REDIS_TIMEOUT=1` +
      `WP_REDIS_READ_TIMEOUT=1`. If that active drop-in lands on a host where
      Redis is **absent or unreachable**, every cache op blocks up to 1s
      before failing over — the persistent cache makes the site ~1s/op
      *slower*, not faster. The decision is binary: **Redis reachable →
      activate the drop-in; no Redis → do NOT ship the active drop-in** (let
      WP use its in-memory per-request cache; BCC degrades cleanly — see
      `DegradationMetrics` transient fallback).

  **Don't hardcode the `WP_REDIS_*` defines per environment** — gate them on a
  host env var so the same `wp-config.php` is safe whether or not the host has
  Redis. Replace any unconditional `WP_REDIS_*` block with:

  ```php
  // Only activate Redis when the host actually has it reachable. Set
  // BCC_REDIS_ENABLED=1 in the host environment ONLY AFTER `wp redis status`
  // reports Connected. Absent/0 → leave the drop-in disabled (rename the
  // active object-cache.php to object-cache.php.disabled) and BCC falls back
  // to the DB-backed RateLimiter + transient cache. Never ship the active
  // drop-in to a Redis-less host (WP_REDIS_TIMEOUT=1 → 1s stall per cache op).
  if (getenv('BCC_REDIS_ENABLED') === '1') {
      define('WP_REDIS_HOST',         getenv('WP_REDIS_HOST') ?: '127.0.0.1');
      define('WP_REDIS_PORT',  (int) (getenv('WP_REDIS_PORT') ?: 6379));
      define('WP_REDIS_PREFIX',       getenv('WP_REDIS_PREFIX') ?: 'bcc_');
      define('WP_REDIS_DATABASE', (int) (getenv('WP_REDIS_DATABASE') ?: 0));
      define('WP_REDIS_TIMEOUT', 1);
      define('WP_REDIS_READ_TIMEOUT', 1);
      define('WP_CACHE', true);
  }
  ```

- [ ] **Blocking gate:** `BCC_REDIS_ENABLED=1` is set **only** after `wp redis
      status` (or Settings → Redis) shows **Connected**. If Redis is not
      reachable, `BCC_REDIS_ENABLED` is unset/`0` **and** the active
      `object-cache.php` drop-in is renamed `object-cache.php.disabled`. Do not
      go live with an active drop-in and an unreachable Redis.

### 1.6 Edge cache for anonymous public reads (LiteSpeed / CDN)

**Optional scaling step — apply when traffic warrants, not required for a
correct deploy.** This is "F2 Tier 3": the real origin-offload that pairs
with the frontend's anon Data-Cache seam (the frontend caches the Vercel→WP
round-trip for anon views; this caches the WP→DB view-model rebuild).

The frontend is already built for this. The API client sends anonymous reads
**cookie-less and Bearer-only** *specifically* so an edge cache can key on
them (`bcc-frontend/src/lib/api/client.ts` — `credentials: 'omit'`;
Authorization is deliberately kept out of the cache key). So a public GET
from a logged-out viewer is byte-identical and safe to serve from cache.

Configure LiteSpeed (LSCWP) — or a CDN/edge in front of the WP origin — to
cache **anonymous** GETs of the public read endpoints:

- [ ] Cache `GET /wp-json/bcc/v1/users/*`, `/cards/*`, `/groups/*` (the
      public profile / entity / group view-models) for **30–60s** (match the
      backend's per-viewer view-model cache; see `user-endpoints.ts`).
- [ ] **Hard exclusions (correctness-critical — a leak here serves one
      viewer's personalized data to another):**
  - Bypass cache whenever the `Authorization` header is present (authed =
    personalized, must always hit origin).

    **This is not automatic — LSCWP only varies on login cookies, and JWT
    auth sets none.** The 2026-07 staging/prod environments shipped LSCache
    without this exclusion and served cached anonymous payloads to logged-in
    API calls for the full TTL (found + fixed 2026-07-13, see the closed bug
    in [TODO.md](TODO.md)). Concrete implementation — add this marked block
    to the environment's `.htaccess`, ABOVE the plugin-managed
    `# BEGIN LSCACHE` section (the plugin rewrites only its own marker
    blocks, so a custom block placed outside survives settings saves —
    verified against two live plugin rewrites):

    ```apache
    # BEGIN BCC AUTH CACHE BYPASS
    <IfModule LiteSpeed>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} ^/wp-json/
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=Cache-Control:no-cache]
    </IfModule>
    # END BCC AUTH CACHE BYPASS
    ```
  - Bypass whenever any `wordpress_logged_in_*` / session cookie is present.
  - Never cache non-GET methods, `/auth/*`, `/me/*`, `/admin/*`, or any
    write/mutation route.
- [ ] **REQUIRED PRE-PRODUCTION GATE — run
      [`scripts/auth-cache-isolation-probe.sh`](../scripts/auth-cache-isolation-probe.sh)
      against staging and require exit 0.** It codifies the manual check:
      a fresh anon REST URL twice (second must be an edge HIT), then the
      same primed URL twice with a dummy `Authorization: Bearer` header
      (neither may be a HIT). Exit 2 = a Bearer request was served the
      cached anonymous variant — a **P0 isolation regression**: restore the
      AUTH CACHE BYPASS block from the timestamped `.htaccess` backup before
      anything else. Exit 1 = inconclusive (cache not exercised, or
      client-IP ban) — re-run from the server over SSH before trusting a
      pass. Manual fallback (same protocol): bare `GET …/cards` twice →
      second call HIT; same URL **with** `Authorization: Bearer <any value>`
      → must never HIT and must return the viewer-aware body.
      **Production freeze (2026-07-19): do not access, modify, deploy to,
      purge, test, or configure production until Phillip explicitly
      authorizes the production phase. The probe refuses the production
      hostname by design; probing prod belongs to that later phase.**
- [ ] **Public TTL trap (P1 — silent staleness).** LSCWP's default
      `cache-ttl_pub` is **604800 (1 week)**, and it overrides the endpoints'
      own `Cache-Control: max-age` (15–60s), so anon feed/profile/cards can be
      served up to a week stale. Set it to **60s** — still ~99% hit at any real
      traffic (origin regenerates each URL ≤once/min; load-tested), staleness
      bounded. `wp litespeed-option set cache-ttl_pub 60`. Both staging + prod
      were found at the 604800 default and fixed to 60 on 2026-07-15.
- [ ] **Headless hygiene:** WP serves only REST + wp-admin here, so turn OFF
      the entire **Page Optimization** menu — especially CSS/JS **Minify**
      (`optm-css_min`/`optm-js_min`), the top cause of wp-admin breakage and
      pure overhead for pages no human loads. `wp litespeed-option set
      optm-css_min 0; wp litespeed-option set optm-js_min 0`. After any option
      change: `wp litespeed-purge all`.
- [ ] **Object cache:** Redis is NOT offered on Hostinger shared plans (VPS/
      Agency only — see `capacity-model.md`); the shared-tier substitute is
      **LSMCD** (LiteSpeed Memcached) via LSCWP → Cache → Object → Test. If
      enabled, turn on **Purge All on Upgrade** (or purge in the deploy step),
      else a stale cached `bcc_trust_schema_version` re-runs the ~200-query
      schema installer every request (see §1.5 + boot-floor fix).

Bundle this with the Redis/object-cache upgrade — both are the same
"persistent cache shows up when traffic does" milestone (see
`project_hosting_redis_strategy` in memory). Without LiteSpeed/CDN this step
is a no-op; the frontend seam still works on its own.

---

## 2. Mail (SMTP)

Account-security emails are the **trust anchor** against hijacked-session
attackers (they can suppress in-app + push, not email). `wp_mail` must
actually deliver on testnet:

- [ ] Configure an SMTP plugin or transactional provider (local dev uses Mailpit).
- [ ] Trigger one credential-change email end-to-end (change password on a
      test account) and confirm delivery.
- [ ] Confirm failures surface: a failed send must emit the
      `account_security_mail.*_send_failed` DegradationMetric (P1 posture).

---

## 3. Cron

The recurring hooks + single-event dispatches listed in
`docs/cron-registry.md` (the canonical, self-updating list — don't
hard-count them here; verify against `wp cron event list` below).

- [ ] Real system cron hitting `wp-cron.php` (or `wp cron event run --due-now`
      every minute) + `define('DISABLE_WP_CRON', true)` — testnet must not
      depend on traffic-triggered cron.
- [ ] Verify all hooks scheduled: `wp cron event list` — the plugins
      self-heal missing schedules on `plugins_loaded` (do NOT remove the
      apparent activation/self-heal redundancy; it exists because
      activation-only scheduling drifted in 2026-05).
- [ ] Validator-logo enrichment: confirm the auto-import populates on the
      first enrichment run (never yet observed live as of 2026-06-04).
- [ ] **Action Scheduler.** BCC's async layer (`bcc-core` `AsyncDispatcher`)
      prefers `as_enqueue_async_action()` for vote fan-out (4+ jobs/vote) and
      the debounced push flush, falling back to `wp_schedule_single_event`
      only when Action Scheduler is absent. Confirm whether it's installed
      (`wp plugin list | grep action-scheduler`, or that the `as_*` functions
      exist) and, if so, that its queue runner is draining
      (`wp action-scheduler run`; no growing `wp_actionscheduler_actions`
      backlog). If it's **absent**, the real-system-cron requirement above is
      load-bearing — the fallback single-events run on wp-cron.

---

## 4. Frontend (Vercel)

From `.env.local.example` — all must be set in the Vercel project:

| Var | Notes |
|---|---|
| `NEXT_PUBLIC_BCC_API_URL` | The testnet WP origin. |
| `NEXTAUTH_URL` | The Vercel deployment's canonical URL. |
| `NEXTAUTH_SECRET` | `openssl rand -base64 32`. NOT the BCC JWT secret. |
| `BCC_INTERNAL_VERIFY_SECRET` | Must equal the wp-config value (§1.1). |
| `BCC_OAUTH_BRIDGE_SECRET` | Must equal the wp-config value. **MANUAL, still open** (SSO-hardening leftover, 2026-06-11). |
| `NEXT_PUBLIC_SENTRY_DSN` | Public by Sentry's threat model; set for testnet error reporting. |

Also:
- [ ] `package.json` dev-script TLS tweak (`NODE_TLS_REJECT_UNAUTHORIZED=0`)
      is local-only — confirm it is not in the deployed build command.
- [ ] JWT TTL chain: 7-day token + `REFRESH_GRACE_SECONDS=86400` silent
      refresh is by-design V1; no changes needed at deploy time.

---

## 5. Post-deploy health gates

Run in order; each must pass before the next matters
(commands: `docs/GOLDEN_PATHS.md`):

1. [ ] **Schema**: `[bcc-trust] schema migration firing` appears exactly
       once in the log after deploy, then never again.
2. [ ] **Auth critical path**: signup → login → `/wp-json/bcc/v1/me`
       returns the canonical envelope.
3. [ ] **System health endpoint**: all subsystems GREEN (or explained).
4. [ ] **DegradationMetric noise floor**: no unexplained events in the
       first hour.
5. [ ] **Guard scripts** (run against the deployed checkout):
       `contract-parity-guard.php` exit 0, `subsystem-count-guard.php`
       exit 0, `cadence-pressure-guard.sh` clean.
6. [ ] **Boot floor**: copy `scripts/bcc-query-floor-probe.php` into
       `wp-content/mu-plugins/` (+ `SAVEQUERIES` in wp-config), confirm
       ≤ ~50 queries on representative REST routes, `show_tables=0`,
       `bcc_chains<=1`. **Remove the probe from mu-plugins afterwards**
       (mu-plugins are always-on; the canonical copy stays in scripts/).
7. [ ] **E2E smoke**: full walk of `docs/v1-smoke-test-checklist.md`.

---

## 6. PeepSo configuration (performance)

PeepSo settings live in the `peepso_config` wp_option (per-install — they do
NOT travel with the codebase, so they must be set in each environment's
**PeepSo → Configuration → Profiles** admin).

- [ ] **Disable name-based avatars** (PeepSo → Configuration → Profiles →
      Avatars → uncheck "Name-based avatars"; equivalently
      `avatars_name_based = 0` in `peepso_config`).

      **Why:** PeepSo's name-based avatar generator runs a
      `SELECT * wp_peepso_users` + gender-field lookup + config reads on
      **every member avatar, every request** — and BCC's frontend never
      shows PeepSo avatars (it renders an initials monogram via
      `Avatar.tsx`, which treats both the generated SVG and the static
      `user-neutral` default as "no photo"). Measured locally: `/members`
      at `per_page=24` dropped **1492 → 143 queries** with this off; the
      same per-render PeepSo lookups are eliminated on every avatar-heavy
      route (`/feed/hot`, `/users/:handle`, sidebars). The frontend
      change that makes this swap visually invisible
      (`isPeepSoPlaceholder` covering the default PNG) is already shipped.

      After flipping: `wp cache flush` (the setting is object-cached), and
      optionally clear the now-unused generated meta:
      `DELETE FROM wp_usermeta WHERE meta_key IN
      ('wp_peepso_name_based_avatar_url',
       'wp_peepso_name_based_avatar_path',
       'wp_peepso_name_based_avatars_config');`

      Real uploaded avatars (`/peepso/users/{id}/…`) are unaffected.

---

## 7. Known-open manual items (as of 2026-06-12)

- [ ] Vercel: set `BCC_OAUTH_BRIDGE_SECRET` (matching wp-config) — SSO is
      fail-closed until done.
- [ ] X developer portal: add the NextAuth callback URL.
- [ ] Rotate ALL §1.1 secrets fresh for testnet (local values are burned —
      several have appeared in dev configs/chats).
