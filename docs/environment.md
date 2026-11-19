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
| `BCC_ENV` | Env label for the wp-admin banner **and** the value this host reports to the daily `site-url-guard`. Closed, **case-sensitive** vocabulary — see below. | unset (banner reads `ENV UNKNOWN`) |
| `BCC_LOG_DIR` | Log directory override. | `dirname(ABSPATH)/bcc-logs` |
| `BCC_ETH_RPC_URL` / `BCC_SOL_RPC_URL` / `BCC_SOLANA_RPC_URL` / `BCC_HELIUS_RPC_URL` | RPC endpoint overrides. | public defaults (llamarpc / mainnet-beta) |
| `BCC_ETH_DAILY_RPC_BUDGET` / `BCC_ONCHAIN_MAX_API_CALLS` / `BCC_ONCHAIN_CACHE_HOURS` | On-chain indexer/test caps + cache TTL. | code defaults (e.g. 200 / 24h) |
| `BCC_COSMOS_HOLDINGS_CONTRACT_CAP`, `BCC_TALIS_WHITELIST_CONTRACT`, `BCC_TALIS_WHITELIST_PAGE_CAP` | Cosmos fetch caps/whitelist. | unset / code default |
| `BCC_REQUIRE_TRUSTED_PROXY_CONFIG` | Deny requests that carry proxy headers (`X-Forwarded-For` / `X-Real-IP` / `CF-Connecting-IP`) unless a trusted proxy or the Cloudflare opt-in is declared — it checks that config **exists**, it does not resolve anything. ⚠ The Cloudflare opt-in satisfies it **only when `REMOTE_ADDR` is itself a Cloudflare address**; a request reaching the origin directly with proxy headers is still denied (**fail-closed when enabled** — only set once `BCC_TRUSTED_PROXY_IPS` or `BCC_BEHIND_CLOUDFLARE` is set). | off |
| `BCC_TRUSTED_PROXY_IPS` | Comma-separated **exact** IPs of reverse proxies in front of WordPress (no CIDR yet). For a request whose `REMOTE_ADDR` is one of them, the client IP is the **rightmost `X-Forwarded-For` hop that is not itself a listed proxy** (a malformed hop there → `REMOTE_ADDR`; `X-Real-IP` is never used). Applies to **every** IP use — rate-limit buckets, vote IPs, fingerprints, audit rows (bcc-core `Throttle::clientIp()`, which bcc-trust's `IpResolver` delegates to). The `bcc_trusted_proxy_ips` filter (array of IP literals) is the alternative when the constant is unset. **Precedence:** when a trusted-proxy list is set (constant or filter), `CF-Connecting-IP` is **never** consulted, even with `BCC_BEHIND_CLOUDFLARE`. ⚠ Without it, every client behind a proxy resolves to the proxy's IP. | `''` |
| `BCC_BEHIND_CLOUDFLARE` | Opt in to trusting `CF-Connecting-IP` — only when `REMOTE_ADDR` is also in a published Cloudflare range (filter alternative: `bcc_behind_cloudflare`). Ignored whenever `BCC_TRUSTED_PROXY_IPS` / `bcc_trusted_proxy_ips` is set — the trusted-proxy list wins. Production is **not** behind Cloudflare; define it only if Cloudflare is ever put in front of the site. | off |
| `BCC_DEGRADATION_ALERT_THRESHOLD` / `_EMAIL` / `_WEBHOOK` | DegradationMetric alerting threshold + sinks. | `1` / `''` / `''` |
| `BCC_TRUST_TEST_MODE`, `BCC_HIGHLIGHTS_DEMO` | Test/demo bypasses — **never enable in prod.** | off |

### `BCC_ENV` — the canonical tokens

Exact, lower-case, **case-sensitive** string literals. `define()` in
`wp-config.php`; it is a PHP constant, never an OS environment variable.

| Token | Use | Banner |
|---|---|---|
| `production` | **canonical** for deployed production | red `PROD` |
| `staging` | deployed staging (including testnet-flavoured deployments) | yellow `STAGING` |
| `local` | a developer machine | blue `LOCAL` |
| `dev` | a shared development box | blue `DEV` |
| `prod` | **legacy alias — do not use for new configuration** | red `PROD` |

Two components read this constant and they must agree:

- `bcc-core/src/Admin/EnvBanner.php` renders the wp-admin banner.
- `bcc-core/src/Rest/IdentityEndpoint.php` reports it **verbatim** to
  `scripts/site-url-probe.sh`, which the daily `site-url-guard` workflow runs
  against both hosts and which compares it to the literal `production` /
  `staging`.

> **Why `prod` must not be used on a deployed host.** The banner accepts it,
> but the guard does not: a production host configured `prod` reports `prod`,
> the probe expects `production`, and the daily 05:23 UTC job fails — with no
> `continue-on-error` to soften it. The alias exists **only** so an
> un-migrated `wp-config.php` does not regress to `ENV UNKNOWN` mid-rollout.
> The endpoint deliberately does not canonicalise it: the guard's whole job is
> to notice a host whose configuration disagrees with what the caller
> believes, and a value normalised on the way out is that disagreement made
> invisible.

Anything else — `Production`, `Live Site`, `testnet`, a padded `' production '`,
an empty string — is **unknown**: the banner says
`ENV UNKNOWN — set BCC_ENV in wp-config.php` and the guard fails its identity
cross-check. Nothing guesses; nothing is rewritten at runtime.

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

## What changes per environment (local / CI / staging / production)

> **Read this first: production is a headless split and the other two are not.**
> This table used to have one merged "stage / prod" column, which made the most
> consequential difference in the stack structurally undescribable. It is now split
> for exactly that reason.
>
> **CI counts as an environment.** It runs no site, so it is absent from the
> configuration and split tables below — but it is the environment that certifies
> work "production-ready", so it appears under [Platform](#platform), where it is
> currently the *only* environment not matching production.

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
| `BCC_ENV` | `local` | `staging` | `production` (banner + `site-url-guard` identity) |
| Secrets (A/B/D) | dev/test keys | real keys, separate from production | real keys, rotated out-of-band |
| RPC URLs (C) | public defaults fine | paid/owned endpoints | paid/owned endpoints |
| Cron | WP-cron OK | system cron + `DISABLE_WP_CRON` if wired | system cron + `DISABLE_WP_CRON` if wired |
| `BCC_HIGHLIGHTS_DEMO`, `BCC_TRUST_TEST_MODE` | fine to enable | **never** | **never** |

### Platform

**CI is an environment too**, and it is the one that signs off "production-ready".
It gets a column here for that reason.

| Concern | local | **CI** | staging | production |
|---|---|---|---|---|
| Stack | Local by Flywheel — nginx 1.26.1, MySQL 8.0.35 | `ubuntu-latest` + `shivammathur/setup-php` | LiteSpeed on Hostinger | LiteSpeed on Hostinger |
| PHP | site **8.3.29**; ⚠ the `php` on PATH is **8.2.30** | **varies by repo** — `bcc-core` 8.3, `bcc-trust` + `bcc-search` 8.2 | **8.3.30** | **8.3.30** |
| Object cache | Redis drop-in present | none | — | — |
| Redis | optional | none | recommended; mind the `WP_REDIS_TIMEOUT` gotcha | recommended; same gotcha |

> ⚠ **"local" is two PHPs, and the one that gates your work is the older.** The
> *site* runs 8.3.29, but the `php` on PATH — which runs PHPUnit, PHPStan and the
> guard scripts — is **8.2.30**. So a local green bar is an 8.2 result even though
> the site next to it is 8.3. Local by Flywheel ships an 8.3.29 CLI at
> `lightning-services/php-8.3.29+1/bin/win64/php.exe`; it loads no `php.ini`, so it
> needs `PHPRC` pointing at one that enables `gmp`, `mbstring`, `intl` and `mysqli`
> before it can run the suite.
>
> **Local's PHP minor version now matches the remotes** (local was 8.2.30 until the
> 2026-08 parity audit; the 8.2-vs-8.3 gap meant 8.3-only behaviour could not
> reproduce locally at all). A patch gap remains — 8.3.29 local vs 8.3.30 remote —
> close enough for language semantics but not for a bug traced to a specific patch
> release. Local by Flywheel offers whichever 8.3 build it ships; matching the patch
> exactly requires its UI, not a config edit.

### ⚠️ CI runs PHP 8.2 while both remotes run 8.3.30 — partly closed

⚠ **This section was audited in 2026-08 and three of its findings had gone stale
by 2026-11-19.** They are corrected below; the reasoning that follows them was
sound and is kept. If you are acting on this section, act on the table, not on
memory of it.

| Repo | PHP pin | Where | Composer | `require.php` | `config.platform.php` |
|---|---|---|---|---|---|
| `bcc-core` | **8.3** — already bumped | `ci.yml:27` | `install` | `>=8.0` | unset |
| `bcc-trust` | **8.2** in **FIVE** jobs | `ci.yml:42`, `:285`, `:335`, `:390`, `:456` | `install` *(since #191)* | `>=8.1` | **`8.2.0`** |
| `bcc-search` | **8.2** | `ci.yml:42` | `install` | `>=8.1` | unset |

All three commit `composer.lock`.

**What had rotted:**

1. **`bcc-trust` pins 8.2 in five jobs, not two.** The two this section named
   (`:39`, `:187`) are gone; the file has since grown the verified-operator
   resolver controls, the NFT revocation controls and the MariaDB engine job.
   Bumping only two would leave three jobs behind *and* report a pass it had
   not earned.
2. **`bcc-trust` runs `composer install`, not `composer update`.** Changed in
   bcc-trust **#191**, which committed the lock for exactly this reason: `update`
   had CI testing guzzle 7.16.1 and brick/math 1.0.0 while production ran 7.10.0
   and 0.17.1. The workflow comment forbids reverting it. **There is no longer a
   repo whose transitive versions can shift with the runner.**
3. **`config.platform.php` is not unset everywhere.** `bcc-trust` sets it to
   **`8.2.0`**, and its lock carries a matching `platform-overrides`. That single
   line makes `bcc-trust` the most insulated of the three, not the most exposed.

Classified **ACCIDENTAL** — nothing depends on 8.2 and no comment defends the
choice; it is a pin that was correct when written and never revisited.

This is the drift that matters most, and it is not because the gap is large. Local
drift produces *confusion* — a developer sees odd behaviour and investigates. **CI
drift produces false confidence**: a green check is the artefact the team trusts to
mean "this is safe for production", and it is issued by the only environment that
does not match production. A behaviour that differs between 8.2 and 8.3 is, by
construction, invisible to the gate designed to catch it.

**Nothing blocks the bump**, and two of the three things this section said to
expect do not happen at all — measured on a real PHP 8.3.29 binary against
`bcc-trust`, not inferred:

- **Composer resolves nothing differently.** On the 8.3 runner Composer reports
  `php 8.2.0 — Package overridden via config.platform, actual: 8.3.29`. A dry-run
  installs all 36 dev packages at their locked versions (the 21 production
  packages are already in the committed `vendor/`), and `composer.lock` is
  byte-identical. With `install` + a committed lock + a platform override, the
  runner's PHP cannot reach resolution.
- **PHPStan's analysis target does not follow the runner** — this section's
  expectation that it would is wrong for `bcc-trust`. `phpstan diagnose` reports
  *"PHP version for analysis: 8.2 (from `config.platform.php` in composer.json)"*
  identically under 8.2.30 and 8.3.29. Its suggestion to set `phpVersion`
  explicitly is already satisfied in effect. For `bcc-core` and `bcc-search`,
  which set no platform, the target *does* follow the runner.
- **No locked dependency carries a PHP upper bound,** and the dev toolchain is
  exact-pinned (`phpstan/phpstan 2.1.51`, `phpunit/phpunit 11.5.55`), so a runner
  bump will not silently float it.

⭐ **The runner still changes what PHPStan can see, in the safe direction.** At
the same 8.2 analysis target, the 8.3 runner flags an 8.3-only function
(`json_validate()`) as unavailable, while the 8.2 runner accepts it silently. Both
runners flag a function that never exists — that is the control, so this is added
reach, not added noise. The 8.3 runner catches "reached for an 8.3-only symbol
while the declared platform is 8.2"; the 8.2 runner is blind to it.

⚠ **Bumping `config.platform.php` is not a one-line change.** It is part of
`composer.lock`'s content hash, so editing it immediately puts the committed lock
out of date — measured: `composer install` then warns *"The lock file is not up to
date with the latest changes in composer.json"* and recommends `composer update`.
Since `vendor/` is committed and is what the deploy rsyncs to production, a
platform bump necessarily re-resolves dependencies and ships a new `vendor/` — the
very thing #191 exists to prevent. It also moves PHPStan's analysis target to 8.3,
which at level 8 can surface new findings. It therefore belongs in its own PR with
its own review, never bundled with a runner bump.

**Remaining work:** `bcc-search` is still on 8.2 and sets no platform, so the bump
there moves both its runtime *and* its PHPStan target in one step — expect level-8
findings and treat it as the less trivial of the two. `bcc-trust` is covered by
#771.

> GMP is a hard requirement, not a nicety — CI already installs it explicitly
> (`extensions: gmp, mbstring, intl`) with the note that it *"is required by
> simplito/elliptic-php (on-chain crypto)"*, and local Composer installs have always
> needed `--ignore-platform-req=ext-gmp`. See the `php.ini` trap below for what
> happens when it goes missing at runtime.

### ⚠️ Switching PHP version in Local silently resets the site's `php.ini`

Local writes a fresh `php.ini` template from its stock defaults when you change PHP
version, and per-site customisations are **not** carried across. The 8.2.30 → 8.3.29
switch dropped `extension=php_gmp.dll`, and the failure was silent.

**Edit `conf/php/php.ini.hbs`. That is the active template.** The
`conf/php-<version>/` directories look authoritative and are **inert** — editing them
changes nothing. Verified by matching the rendered
`…/Local/run/<siteid>/conf/php/php.ini` against each candidate: it matches
`conf/php/php.ini.hbs` exactly, differing only in the three `{{else}}`-branch `.so`
lines that handlebars strips on Windows. Against `conf/php-8.3.29/` it differs by
six extension lines. The `extension=php_gmp.dll` that sat in `conf/php-8.2.30/` was
never doing anything either.

**Why GMP is the one that matters:** `bcc-core` hard-requires it (it backs
`simplito/elliptic-php`, the on-chain crypto path) and `return`s early without it, so
`BCC_CORE_VERSION` is never defined — and `bcc-search` and `bcc-trust` both gate on
that constant, so they bail in turn. One missing extension takes the whole
application layer down.

It is dangerous because **nothing about it looks broken**:

- `wp plugin list` reports all three bcc-* plugins **`active`**.
- WordPress boots, PeepSo works, wp-admin is normal.
- `/wp-json/` simply omits every `bcc/*` namespace, and bcc routes return
  **`404`, not `500`** — indistinguishable from a routing or permalink problem.

**It also damages persistent state.** With `bcc-core` inert its `cron_schedules`
filter never registers, so every custom BCC interval (`bcc_one_minute`,
`bcc_five_minutes`, `bcc_thirty_minutes`, `bcc_weekly`, …) becomes unknown to
WP-Cron. Within 15 seconds of one such boot, **15 recurring events failed to
reschedule** with `invalid_schedule` — `bcc_trust_feed_hot_warm`,
`bcc_nft_eth_indexer_tick`, `bcc_trust_process_recalculations`,
`bcc_core_degradation_alert_check`, `bcc_disputes_reconcile_orphans` and others.
They do not come back on their own. So this is not merely "the site is inert while
misconfigured" — fixing the extension does not necessarily restore the schedule.

### This trap recurs — it is not a one-time fix

The repair lives in a Local by Flywheel config file **outside this repository**, so
it is not version-controlled, not code-reviewed, and **not carried across the next
PHP version bump**. Expect to redo it every time.

After **any** Local PHP version change, re-check `conf/php/php.ini.hbs` and then
confirm the stack is really up:

```bash
curl -s http://blue-collar-crypto-custom.local/wp-json/ | grep -o 'bcc[^"]*'
```

Empty output means the plugins are loaded-but-inert, not missing. If it is empty,
also check `wp cron event list` for BCC hooks before assuming the fix is complete.

> **Do not trust Local's registry for the current version.** `AppData/Roaming/Local/
> sites.json` can be read mid-write and report a version that is neither the old nor
> the new one — during this audit it returned `8.3.17`, a build that was never
> installed. The authoritative source is the running process:
>
> ```powershell
> Get-CimInstance Win32_Process -Filter "Name='php-cgi.exe'" |
>   Select-Object -ExpandProperty ExecutablePath
> ```
>
> The repo-root `local-site.json` is also stale — it describes a different site
> (`blue-collar-crypto`, environment `flywheel`) than the live one
> (`blue-collar-crypto-custom`, environment `custom`).

### Local `wp-content/debug.log` grows unbounded

`WP_DEBUG_LOG` is on locally with no rotation. It reached **113 MB / 354,276 lines**
over roughly two months (18 Jun – 20 Aug 2026) before being truncated in place.

Measured composition of that file — useful because it says which noise is worth
silencing and which is a real defect:

| Source | Lines | Share of bytes |
|---|---:|---:|
| LiteSpeed crawler `simplexml_load_string()` parse warnings (`crawler-map.cls.php:676`) | 257,796 | **64.9%** |
| `_load_textdomain_just_in_time` too early — `peepso-core` domain | 26,759 | 15.7% |
| `wpdb::prepare` placeholder-count notice — **14 placeholders, 13 arguments** | 24,624 | 11.1% |
| `Duplicate entry` on `wp_bcc_onchain_collections.uq_chain_contract` | 5,556 | 5.3% |
| other / db / stack traces | 39,524 | 3.1% |
| **imagick startup warning** | **0** | **0%** |

> **Correction to an earlier revision of this document**, which attributed the log's
> size to the imagick startup warning. That was wrong: imagick does not appear in the
> file at all. The warning reproduces on the **CLI** binary but the CGI/web SAPI never
> wrote it. Disabling imagick remains correct — the extension genuinely cannot load —
> but it was not the cause, and the LiteSpeed crawler is.

Two of these are live BCC defects rather than noise, both still accruing at the time
of writing (~1,200–2,400/day and ~60–124/day respectively):

- the `wpdb::prepare` mismatch — exactly one placeholder more than arguments, the
  signature of an unescaped literal `%` in a `LIKE`;
- the repeated unique-key collision on `wp_bcc_onchain_collections`, which suggests
  an insert path that should be upsert-shaped.

Neither is an environment-parity issue; they are recorded here only because this is
where the evidence surfaced. To truncate without breaking the open file handle, keep
the inode:

```bash
tail -n 5000 wp-content/debug.log > ../logs/debug.log.keepsake-$(date +%F).txt
: > wp-content/debug.log      # truncate in place; do NOT rm
```

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
