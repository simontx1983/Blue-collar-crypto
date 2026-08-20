# Environment & wp-config Constants — Blue Collar Crypto

Every `wp-config.php` constant (and env var) the BCC plugins actually read, grouped by how
required it is. Each row cites the `file:line` where the code reads it. Secrets go in
`wp-config.php` / env / secret storage **only** — never the DB or admin UI (see
[the secrets policy](operator-runbook.md)).

> **Two fail-closed facts to know up front:**
> - **`BCC_ENCRYPTION_KEY` missing ⇒ the whole Trust plugin is inert** — all non-admin users
>   get 403 on every BCC REST call and cron is cleared (`bcc-trust.php:135`).
> - The internal/webhook secrets (`BCC_OAUTH_BRIDGE_SECRET`, `BCC_INTERNAL_CRON_SECRET`,
>   `BCC_HELIUS_WEBHOOK_SECRET`, `BCC_INTERNAL_VERIFY_SECRET`) **fail closed**: undefined ⇒ the
>   corresponding endpoint refuses all requests (500/401).

The large `BCC_TRUST_*` scoring/tier/weight family is **shipped pre-defined** in
`bcc-trust/includes/config/*.php` and is **not** operator setup — treat those as expert-only
knobs, not config. They are omitted here.

---

## Minimum to boot a working local site

```php
define('BCC_ENCRYPTION_KEY', '<random 32+ char secret>');   // else bcc-trust is inert
define('BCC_FRONTEND_ORIGIN', 'http://localhost:3000');     // CORS + JWT aud + login redirects
```

Install/activate **bcc-core first**, then bcc-trust, then bcc-search. Everything below is
feature-gated and silent-degrades when absent — add only what you use.

---

## (A) Hard-required for boot / core function

| Constant | Purpose | Default | Secret | Read at |
|---|---|---|---|---|
| `BCC_ENCRYPTION_KEY` | AES-256-GCM key for wallet/PII encryption + fingerprint salt. Missing ⇒ **bcc-trust inert** (all non-admin 403, cron cleared). | none (fail-closed) | **yes** | `bcc-trust.php:150` (inert-guard; further reads 166/185/646/1849); `Core/Support/Encryption.php:16,32`; `Core/Security/DeviceFingerprinter.php:52` |
| `BCC_FRONTEND_ORIGIN` | Canonical Next.js origin (comma-separated allowlist OK). Drives CORS, JWT audience, login redirects, Polkadot verify-route URL. Empty ⇒ frontend can't talk to backend. | `''` ⇒ disabled | no | `Core/Support/CorsHandler.php:177`; `JwtToken.php:326`; `FrontendRedirect.php:113,127`; `bcc-core/src/Crypto/PolkadotSignatureVerifier.php:202,205` |

`BCC_CORE_VERSION` is defined by bcc-core itself; bcc-trust/bcc-search gate activation on it
(`bcc-trust.php:114`, `bcc-search.php:49`). Don't set it — just activate bcc-core first.

## (B) Required only for a specific feature

| Constant | Feature | Default | Secret | Read at |
|---|---|---|---|---|
| `BCC_OAUTH_BRIDGE_SECRET` | NextAuth SSO bridge (`POST /auth/oauth`). Fail-closed. | `''`⇒off | **yes** | `Core/REST/Auth/OAuthController.php:159-160` (moved out of AuthEndpoint; its logger prefix still says `[AuthEndpoint]`) |
| `BCC_INTERNAL_CRON_SECRET` | `X-Bcc-Internal` for `POST /internal/indexer/tick` (Vercel cron → ETH indexer). Fail-closed. | `''`⇒off | **yes** | `Onchain/REST/IndexerTickEndpoint.php:150-151` |
| `BCC_HELIUS_WEBHOOK_SECRET` | `Authorization` for the Helius (Solana) webhook. Fail-closed (rejects all). | `''`⇒reject | **yes** | `Onchain/REST/HeliusWebhookEndpoint.php:257` |
| `BCC_INTERNAL_VERIFY_SECRET` | Internal Polkadot signature-verify route (backend→frontend). | `''` | **yes** | `bcc-core/src/Crypto/PolkadotSignatureVerifier.php:186` |
| `BCC_ETHERSCAN_API_KEY` | ETH on-chain signal fetch. Missing ⇒ ETH signals empty (scores understated) + admin notice. | `''` | **yes** | `bcc-trust.php:1793`; `Onchain/Services/SignalFetcher.php:183` |
| `BCC_ALCHEMY_API_KEY` | Alchemy wallet NFT/balance discovery (V1). Missing ⇒ on-chain signals off + admin notice. | `''` | **yes** | `bcc-trust.php:1796`; `Core/Services/wallet/BlockchainQueryService.php:82,162` |
| `BCC_HELIUS_API_KEY` | Helius (Solana RPC + subscriptions). | unset | **yes** | `Onchain/Services/HeliusSubscriptionManager.php:392`; `Fetchers/SolanaFetcher.php:342` |
| `BCC_SUBSCAN_API_KEY` | Subscan (Polkadot validator fetch). | `''` | **yes** | `Onchain/Fetchers/PolkadotFetcher.php:255` |
| `BCC_X_CLIENT_ID` / `BCC_X_CLIENT_SECRET` | X (Twitter) OAuth — X account verification. | `''`/`''` | secret=**yes** | `Core/Services/x/XOAuthService.php:31,32` |
| `BCC_GITHUB_CLIENT_ID` / `BCC_GITHUB_CLIENT_SECRET` | GitHub OAuth — GitHub verification. | `''`/`''` | secret=**yes** | `Core/Services/github/GitHubOAuthService.php:35,36` |
| `BCC_PUSH_VAPID_PUBLIC_KEY` / `_PRIVATE_KEY` / `_SUBJECT` | Web push (VAPID). Generate via `wp bcc push generate-vapid` (prints all three `define()` lines). | none⇒push off | private=**yes** | `Core/Services/PushDispatcher.php:307-315` |
| `BCC_REPAIR_ENABLED` | Gates the wp-admin Repair tab + destructive `RepairService` ops. Must be `=== true`. | off | no | `includes/admin/tabs/tab-repair.php:19`; `Core/Services/Admin/RepairService.php:74` |

## (C) Optional tuning / flags (have defaults — silent-degrade)

| Constant | Purpose | Default |
|---|---|---|
| `BCC_ENV` | Env label for the wp-admin banner. | `''` (no banner) |
| `BCC_LOG_DIR` | Log directory override. | `dirname(ABSPATH)/bcc-logs` |
| `BCC_ETH_RPC_URL` / `BCC_SOL_RPC_URL` / `BCC_SOLANA_RPC_URL` / `BCC_HELIUS_RPC_URL` | RPC endpoint overrides. | public defaults (llamarpc / mainnet-beta) |
| `BCC_ETH_DAILY_RPC_BUDGET` / `BCC_ONCHAIN_MAX_API_CALLS` / `BCC_ONCHAIN_CACHE_HOURS` | On-chain indexer/test caps + cache TTL. | code defaults (e.g. 200 / 24h) |
| `BCC_COSMOS_HOLDINGS_CONTRACT_CAP`, `BCC_TALIS_WHITELIST_CONTRACT`, `BCC_TALIS_WHITELIST_PAGE_CAP` | Cosmos fetch caps/whitelist. | unset / code default |
| `BCC_REQUIRE_TRUSTED_PROXY_CONFIG` | Deny proxy-header requests unless a trusted proxy is declared (**fail-closed when enabled** — only set once `BCC_TRUSTED_PROXY_IPS` is set). | off |
| `BCC_TRUSTED_PROXY_IPS` / `BCC_BEHIND_CLOUDFLARE` | Trust `X-Forwarded-For` from declared proxies / `CF-Connecting-IP` from CF ranges. | `''` / off |
| `BCC_DEGRADATION_ALERT_THRESHOLD` / `_EMAIL` / `_WEBHOOK` | DegradationMetric alerting threshold + sinks. | `1` / `''` / `''` |
| `BCC_TRUST_TEST_MODE`, `BCC_HIGHLIGHTS_DEMO` | Test/demo bypasses — **never enable in prod.** | off |

## (D) Secrets (consolidated — wp-config / env / secret storage only)

`BCC_ENCRYPTION_KEY`, `BCC_OAUTH_BRIDGE_SECRET`, `BCC_INTERNAL_CRON_SECRET`,
`BCC_HELIUS_WEBHOOK_SECRET`, `BCC_INTERNAL_VERIFY_SECRET`, `BCC_ETHERSCAN_API_KEY`,
`BCC_ALCHEMY_API_KEY`, `BCC_HELIUS_API_KEY`, `BCC_SUBSCAN_API_KEY`, `BCC_X_CLIENT_SECRET`,
`BCC_GITHUB_CLIENT_SECRET`, `BCC_PUSH_VAPID_PRIVATE_KEY`.

---

## Non-BCC constants the plugins react to

- **`DISABLE_WP_CRON`** — if cron is disabled and the daily signal refresh hasn't fired in 24h,
  bcc-trust shows a wp-admin warning to wire a system cron (`bcc-trust.php:~1782`).
- **`WP_REDIS_*`** — consumed only by the `redis-cache` drop-in, **not** by any bcc-* plugin.
  Redis is optional (the DB-based RateLimiter is the no-Redis backend). **Gotcha:** don't ship
  the active drop-in with `WP_REDIS_TIMEOUT=1` to a Redis-less host.
- **SMTP** — no BCC constant; mail goes through `wp_mail()` (host SMTP; locally, Mailpit).

## What changes per environment (local / staging / production)

> **Read this first: production is a headless split and the other two are not.**
> This table used to have one merged "stage / prod" column, which made the most
> consequential difference in the stack structurally undescribable. It is now three
> columns for exactly that reason.

### The split, and why it dominates everything below

| | `WP_HOME` (front end) | `WP_SITEURL` (WordPress) | Split? |
|---|---|---|---|
| **local** | `blue-collar-crypto-custom.local` | same | **no** |
| **staging** | `stage.bluecollarcrypto.io` | same | **no** |
| **production** | `bluecollarcrypto.io` — Vercel / Next.js | `cms.bluecollarcrypto.io` — WordPress | **YES** |

On production the apex serves the Next.js frontend and WordPress lives on `cms.`.
`home_url()` therefore addresses a host that serves **no `/wp-json` and no
`/wp-content`**. Anything deriving a URL from `home_url()` is wrong on production and
right everywhere else.

Consequences that have already cost real incidents:

- avatar/media URLs 403ing, because PeepSo **freezes** absolute URLs into usermeta
- `rest_url()` pointing at the frontend host
- CORS origin authority, since the browser origin is no longer the WordPress origin

Reuse `BCC\Core\Support\HeadlessOrigin` (bcc-core) for any origin comparison or
WordPress-host derivation — it boundary-checks the prefix, so
`example.com.evil.test` cannot pass as ours. Do not write another origin parser.
`FrontendOrigin` (bcc-trust) is the sole parser of `BCC_FRONTEND_ORIGIN`.

> ⚠️ **A staging pass does not validate anything split-sensitive.** Staging is not
> split, so it cannot exercise that path at all. Until staging is split, treat
> "works on staging" as silent on every behaviour in the list above. Note also that
> `cms.stage.bluecollarcrypto.io` does not currently resolve — closing this gap is
> DNS + host + `wp-config` work, not a toggle.

### Configuration

| Concern | local | staging | production |
|---|---|---|---|
| `BCC_FRONTEND_ORIGIN` | `http://localhost:3000` (1 exact entry) | the deployed frontend origin(s) | the deployed frontend origin(s); **no `regex:` entry** — so the regex path is untested in situ |
| `BCC_ENV` | unset / `local` | `staging` | `production` (drives the admin banner) |
| Secrets (A/B/D) | dev/test keys | real keys, separate from production | real keys, rotated out-of-band |
| RPC URLs (C) | public defaults fine | paid/owned endpoints | paid/owned endpoints |
| Cron | WP-cron OK | system cron + `DISABLE_WP_CRON` if wired | system cron + `DISABLE_WP_CRON` if wired |
| `BCC_HIGHLIGHTS_DEMO`, `BCC_TRUST_TEST_MODE` | fine to enable | **never** | **never** |

### Platform

| Concern | local | staging | production |
|---|---|---|---|
| Stack | Local by Flywheel — nginx, MySQL 8.0.35 | LiteSpeed on Hostinger | LiteSpeed on Hostinger |
| PHP | **8.2.30** | **8.3.30** | **8.3.30** |
| Object cache | Redis drop-in present | — | — |
| Redis | optional | recommended; mind the `WP_REDIS_TIMEOUT` gotcha | recommended; same gotcha |

> **Local is a PHP minor version behind both remotes.** A behaviour that depends on
> 8.3 semantics will not reproduce locally. Local by Flywheel must download 8.3
> through its UI; it is not a config edit.

### Edge cache (LiteSpeed)

| Path | staging | production |
|---|---|---|
| `/wp-json/` and other REST | `max-age=60` | `max-age=60` |
| `/wp-json/bcc/v1/*` | `no-cache` | `no-cache` — panel exclusion `^/wp-json/bcc/v1/` **plus** the `EdgeCache` code guard, deliberately belt-and-braces |
| Default Front Page | — | `604800` — **deliberate, do not "fix" for symmetry.** The `cms.` root only 302s to the apex landing page. |

The 1-week REST TTL was the root cause of the July `/members` edge-cache incident.
The fix (`ttl_rest=60`) reached staging at the time; production was frozen and did
not receive it until the 2026-08 parity audit. **Do not let these drift again** — the
edge cache keys REST entries by URL and does **not** vary on `Origin`, so a shared
entry can serve one caller's negotiated headers to another.

Test-only env vars (`BCC_TEST_DB_*` in `bcc-trust/tests/Integration/bootstrap.php`) are for the
integration suite, not operator setup.
