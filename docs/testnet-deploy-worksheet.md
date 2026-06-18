# Testnet Deploy Worksheet (fill-in-the-blank)

The **why** for every line lives in [testnet-deploy-checklist.md](testnet-deploy-checklist.md).
This is the **do-it** companion: generate, paste, check off. Work straight down.

> Two pairs of secrets MUST be byte-identical on both ends:
> `BCC_INTERNAL_VERIFY_SECRET` and `BCC_OAUTH_BRIDGE_SECRET`
> (wp-config ↔ Vercel). Generate once below, paste the SAME value in both places.

---

## Step 0 — Generate every secret at once

Run this on any machine with `openssl` + `npx`. Copy the output; paste values into Steps 1 & 3 below.

```bash
echo "BCC_ENCRYPTION_KEY        = $(openssl rand -base64 48)"
echo "BCC_INTERNAL_VERIFY_SECRET= $(openssl rand -base64 32)   # SAME value in Vercel"
echo "BCC_INTERNAL_CRON_SECRET  = $(openssl rand -base64 32)"
echo "BCC_OAUTH_BRIDGE_SECRET   = $(openssl rand -hex 32)      # SAME value in Vercel"
echo "BCC_HELIUS_WEBHOOK_SECRET = $(openssl rand -hex 32)      # SAME value in Helius dashboard"
echo "NEXTAUTH_SECRET           = $(openssl rand -base64 32)   # Vercel only"
echo "--- VAPID (web-push) ---"
npx web-push generate-vapid-keys
```

Paste the generated values here as you go (this file is gitignored territory — do NOT commit filled-in secrets):

```
BCC_ENCRYPTION_KEY         = ____________________________________________
BCC_INTERNAL_VERIFY_SECRET = ____________________________________________
BCC_INTERNAL_CRON_SECRET   = ____________________________________________
BCC_OAUTH_BRIDGE_SECRET    = ____________________________________________
BCC_HELIUS_WEBHOOK_SECRET  = ____________________________________________
BCC_PUSH_VAPID_PUBLIC_KEY  = ____________________________________________
BCC_PUSH_VAPID_PRIVATE_KEY = ____________________________________________
NEXTAUTH_SECRET            = ____________________________________________
```

---

## Step 1 — wp-config.php on the staging server

### 1a. Generated secrets (paste from Step 0)
- [ ] `define('BCC_ENCRYPTION_KEY',         '____');`  ← **site 503s for non-admins if missing**
- [ ] `define('BCC_INTERNAL_VERIFY_SECRET', '____');`  ← must equal Vercel (Step 3)
- [ ] `define('BCC_INTERNAL_CRON_SECRET',   '____');`
- [ ] `define('BCC_OAUTH_BRIDGE_SECRET',    '____');`  ← must equal Vercel; **SSO dead until both set**
- [ ] `define('BCC_HELIUS_WEBHOOK_SECRET',  '____');`  ← must equal Helius dashboard (Step 4)
- [ ] `define('BCC_PUSH_VAPID_PUBLIC_KEY',  '____');`
- [ ] `define('BCC_PUSH_VAPID_PRIVATE_KEY', '____');`
- [ ] `define('BCC_PUSH_VAPID_SUBJECT',     'https://<staging-wp-host>');`  ← canonical https URL

### 1b. Third-party API keys (get from each provider's dashboard)
- [ ] `BCC_ALCHEMY_API_KEY`     = ____  (Alchemy)
- [ ] `BCC_HELIUS_API_KEY`      = ____  (Helius)
- [ ] `BCC_ETHERSCAN_API_KEY`   = ____  (Etherscan)
- [ ] `BCC_SUBSCAN_API_KEY`     = ____  (Subscan)
- [ ] `BCC_GITHUB_CLIENT_ID`    = ____  ┐ new GitHub OAuth app, callback → staging WP host (Step 4)
- [ ] `BCC_GITHUB_CLIENT_SECRET`= ____  ┘
- [ ] `BCC_X_CLIENT_ID`         = ____  ┐ X OAuth app (Step 4)
- [ ] `BCC_X_CLIENT_SECRET`     = ____  ┘
- Optional RPC overrides only if not using provider defaults: `BCC_ETH_RPC_URL`,
  `BCC_SOL_RPC_URL`/`BCC_SOLANA_RPC_URL`, `BCC_HELIUS_RPC_URL`, `BCC_ETH_DAILY_RPC_BUDGET`.

### 1c. Mode flags — CHANGE these from the local values
- [ ] `define('BCC_ENV', 'testnet');`                  (was `'local'`)
- [ ] `define('WP_ENVIRONMENT_TYPE', 'staging');`      (was `'local'`)
- [ ] `define('BCC_FRONTEND_ORIGIN', 'https://<vercel-url>');`  no trailing slash
- [ ] **Remove** `BCC_HIGHLIGHTS_DEMO` (or set `false`)  ← demo data must not render
- [ ] `define('BCC_REPAIR_ENABLED', false);`
- [ ] **Confirm `BCC_TRUST_TEST_MODE` is NOT defined**  ← relaxes trust gates if on

### 1d. If behind Cloudflare / a proxy (skip if direct)
- [ ] `BCC_BEHIND_CLOUDFLARE` (true) **or** `BCC_TRUSTED_PROXY_IPS` (CSV)
- [ ] `BCC_REQUIRE_TRUSTED_PROXY_CONFIG` (fail loud on misconfig)

### 1e. Object cache
- [ ] Redis drop-in configured (`WP_REDIS_HOST/PORT/PREFIX/DATABASE`) + `WP_CACHE`
- [ ] `wp cache flush` after any DB import/restore

---

## Step 2 — Server infrastructure

- [ ] **SMTP**: real transactional provider wired to `wp_mail`
- [ ] Send ONE test: change a test account's password → confirm the email arrives
- [ ] **Cron**: `define('DISABLE_WP_CRON', true);` + system cron hitting `wp-cron.php` (or `wp cron event run --due-now` each minute)
- [ ] `wp cron event list` → all 19 recurring hooks present (they self-heal on load)
- [ ] **PeepSo → Configuration → Profiles → Avatars → uncheck "Name-based avatars"**, then `wp cache flush`  ← perf (see checklist §6)

---

## Step 3 — Vercel project env vars

- [ ] `NEXT_PUBLIC_BCC_API_URL`     = `https://<staging-wp-host>`
- [ ] `NEXTAUTH_URL`                = `https://<vercel-url>`
- [ ] `NEXTAUTH_SECRET`             = ____  (from Step 0 — NOT the BCC JWT secret)
- [ ] `BCC_INTERNAL_VERIFY_SECRET`  = ____  ← **same value as wp-config 1a**
- [ ] `BCC_OAUTH_BRIDGE_SECRET`     = ____  ← **same value as wp-config 1a**
- [ ] `NEXT_PUBLIC_SENTRY_DSN`      = ____  (from Sentry project)
- [ ] Confirm the build command does NOT carry the local `package.json` TLS tweak (`NODE_TLS_REJECT_UNAUTHORIZED=0`)

---

## Step 4 — External dashboards (callbacks must point at staging)

- [ ] **X developer portal** → add NextAuth callback URL: `https://<vercel-url>/api/auth/callback/twitter`
- [ ] **GitHub OAuth app** → new app, Authorization callback URL → staging WP host (matches the route the backend expects)
- [ ] **Helius dashboard** → webhook secret = `BCC_HELIUS_WEBHOOK_SECRET` from Step 0

---

## Step 5 — Post-deploy health gates (run in order; commands in GOLDEN_PATHS.md)

- [ ] **Schema**: `[bcc-trust] schema migration firing` appears in the log exactly ONCE, then never (more = stale cache → `wp cache flush`)
- [ ] **Auth path**: signup → login → `GET /wp-json/bcc/v1/me` returns the canonical envelope
- [ ] **System health endpoint**: all subsystems GREEN
- [ ] **DegradationMetric**: no unexplained events in the first hour
- [ ] **Guards** (against deployed checkout): `contract-parity-guard.php` exit 0, `subsystem-count-guard.php` exit 0, `cadence-pressure-guard.sh` clean
- [ ] **Boot floor**: drop `scripts/bcc-query-floor-probe.php` into `wp-content/mu-plugins/` (+ `SAVEQUERIES`), confirm representative routes ≤ ~50 queries, then **remove the probe**
- [ ] **E2E smoke**: walk [v1-smoke-test-checklist.md](v1-smoke-test-checklist.md)

---

### The three that hard-break if missed
1. `BCC_ENCRYPTION_KEY` absent → **site 503s for everyone but admins**
2. `BCC_OAUTH_BRIDGE_SECRET` mismatched/absent on either end → **Google/X login silently dead** (email + wallet still work)
3. `BCC_HIGHLIGHTS_DEMO` left on → **seeded demo data renders publicly**

Everything else degrades gracefully.
