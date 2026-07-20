# Developer Setup — Blue Collar Crypto

The single starting point for a new engineer: clone → install → run → first request,
plus the repository model and where the rest of the docs live. If a step here is wrong or
out of date, fix it in the same PR — this doc is load-bearing for onboarding.

> **Canonical local hostname: `http://blue-collar-crypto-custom.local`.**
> Note: the `local-site.json` in the repo root is a **stale Local-by-Flywheel descriptor**
> copied from a parent site (it says `blue-collar-crypto.local`, no `-custom`). Ignore it;
> the running site is `…-custom.local`. We deliberately don't edit that file (it's Local's,
> not ours).

---

## 1. The repository model (read this first)

This is **not one repo.** What looks like a monorepo on disk is **five separate Git repos**
(all under `github.com/simontx1983/`) checked out into a WordPress + Next.js layout:

| Repo | Checked out at | What it is |
|---|---|---|
| `Blue-collar-crypto` (umbrella) | repo root | Tracks **only** `docs/`, `.claude/`, `scripts/`, `CLAUDE.md`, `.mcp.json`. No application code. |
| `bcc-core` | `app/public/wp-content/plugins/bcc-core/` | Infra plugin — ServiceLocator (DI), DB helpers, logging, crypto verifiers, observability. **Required sibling; install/activate first.** |
| `bcc-trust` | `app/public/wp-content/plugins/bcc-trust/` | The main plugin — reputation/voting/scoring, disputes, on-chain signals (`app/Domain/{Core,Disputes,Onchain}/`). |
| `bcc-search` | `app/public/wp-content/plugins/bcc-search/` | Search plugin (vertical search endpoints). |
| `bcc-frontend` | `bcc-frontend/` | Next.js 15 / React 19 / TypeScript headless frontend. The **only** user-facing renderer. |

The umbrella's `.gitignore` is deny-all-then-allowlist, so the sibling repos and the
WordPress install (`app/public/wp-content/...`) are invisible to the umbrella's `git status`.
Each sibling is its own repo with its own history, CI, and `composer.json`/`package.json`.

**Practical consequence:** "where do I commit?" depends on which files you touched.
A REST change is a `bcc-trust` commit; a contract-doc change is an umbrella commit; a
component is a `bcc-frontend` commit. See [§5 CI topology](#5-ci-topology) for what gets
checked where.

---

## 2. Prerequisites

- **[Local by Flywheel](https://localwp.com/)** running this site (`Blue Collar Crypto`,
  host `blue-collar-crypto-custom.local`). Bundled services on this install:
  **PHP 8.2.30**, **MySQL 8.0.35**, nginx, Mailpit (catches outbound mail).
- **Composer** (for the PHP plugins) and **Node 20+** / npm (for the frontend).
- **wp-cli** — Local ships `wp-cli.phar` under its data dir. The portable invocation (and the
  Windows/Local path-hash gotcha) is documented in
  [GOLDEN_PATHS.md §Prerequisites](GOLDEN_PATHS.md). Use that recipe rather than assuming `wp`
  is on PATH.
- Active WordPress plugins this stack assumes: `bcc-core`, `bcc-trust`, `bcc-search`, and the
  PeepSo family (`peepso` core plugin + `peepso-friends`/`-groups`/`-messages`/`-pages`/`-photos`).
  (The old `blue-collar-crypto-peepso-integration` plugin is retired — its PeepSo-writer surface
  lives in `bcc-core/src/PeepSo/`.)

---

## 3. Install & run

### 3a. WordPress side (the three plugins)

Each plugin has its own `composer.json` — install dependencies **per plugin**:

```bash
cd app/public/wp-content/plugins/bcc-core   && composer install
cd ../bcc-trust                             && composer install
cd ../bcc-search                            && composer install
```

**Activate in dependency order:** `bcc-core` **first** (the others refuse to activate without
`BCC_CORE_VERSION`, which bcc-core defines), then `bcc-trust`, then `bcc-search`. Ensure
the PeepSo plugins (`peepso` + the `peepso-*` modules) are active too.

Set the minimum config in `wp-config.php` (full reference: [environment.md](environment.md)):

```php
// REQUIRED — without this, bcc-trust is fully inert: every non-admin user gets 403 on
// every BCC REST call and cron is cleared (bcc-trust.php:135).
define('BCC_ENCRYPTION_KEY', '<random 32+ char secret>');

// REQUIRED for the frontend — drives CORS allow-origin + JWT audience + login redirects.
define('BCC_FRONTEND_ORIGIN', 'http://localhost:3000');
```

Everything else is feature-gated and silently degrades when absent (on-chain API keys, OAuth
SSO, web-push VAPID, etc.) — add only what you're working on. See [environment.md](environment.md).

Also ensure your web server **forwards the `Authorization` header** to PHP (Bearer auth). The
exact rewrite is pinned in `bcc-trust/app/Domain/Core/Support/BearerAuth.php`.

### 3b. Frontend side (Next.js)

```bash
cd bcc-frontend
cp .env.local.example .env.local
# fill in NEXT_PUBLIC_BCC_API_URL (e.g. http://blue-collar-crypto-custom.local) and NEXTAUTH_SECRET
npm install
npm run dev          # → http://localhost:3000
```

> The committed dev script sets `NODE_TLS_REJECT_UNAUTHORIZED=0` (local-only, to accept
> Local's self-signed cert). It is `NODE_ENV=development`-scoped; never carry it to a
> deployed env. Keep `package.json` out of commits if you tweak this locally.

### 3c. Seed data

The onboarding wizard expects at least one validator + builder + creator PeepSo page so
`/onboarding/suggestions` returns something. See [GOLDEN_PATHS.md](GOLDEN_PATHS.md) for the
fixtures and verification recipes.

---

## 4. Verify the install (first request)

```bash
# Liveness probe — public, returns only {status} (200 healthy / 503 degraded).
curl -s http://blue-collar-crypto-custom.local/wp-json/bcc/v1/system/ping

# A real public read — discovery cards.
curl -s http://blue-collar-crypto-custom.local/wp-json/bcc/v1/cards | head -c 400
```

Then open `http://localhost:3000` (frontend) and `…/wp-admin` (WordPress). For the full
end-to-end verification of every load-bearing seam, follow [GOLDEN_PATHS.md](GOLDEN_PATHS.md).

---

## 5. CI topology

CI is **two-tier**, because the repos are separate. Full detail: [ci-topology.md](ci-topology.md).

- **Per-repo "real" gates live in each code repo's own CI:** `bcc-trust` runs PHPStan L8 +
  PHPUnit + arch-guardrails + a MySQL-backed integration job; `bcc-core` runs PHPStan L8 +
  PHPUnit; `bcc-frontend` runs `tsc` + `next lint` + Vitest; `bcc-search` has **no** test/type
  CI of its own (only the notify shim).
- **The umbrella repo owns the cross-repo static guards** (contract-parity, subsystem-count,
  cadence-pressure, dead-file). It reassembles the WP layout in the runner so these
  path-spanning scripts work.
- **Cross-repo trigger:** each sibling's `notify-root.yml` fires a `repository_dispatch`
  (`event_type=sibling-push`) on push to `main`, which re-runs the umbrella guards against the
  new sibling code. Whether any check is **required to merge** is GitHub branch-protection
  state and **cannot be determined from the repo files** (see ci-topology.md).

---

## 6. Where to go next (doc map)

This repo is documentation-rich; these are the authoritative, current docs (don't duplicate
them — link them):

| You want… | Read |
|---|---|
| Verify any subsystem / diagnose a failure | [GOLDEN_PATHS.md](GOLDEN_PATHS.md) (evergreen runbook) |
| Architecture / which Domain owns what | [domain-seams.md](domain-seams.md) |
| Canonical implementation patterns | [pattern-registry.md](pattern-registry.md) |
| Domain language (trust tier, rank, vouch, …) | [glossary.md](glossary.md) |
| REST API contract (envelope, errors, view-models) | [api-contract-v1.md](api-contract-v1.md) |
| **Coding standards** (§1–§11 guardrails) | [root CLAUDE.md](../CLAUDE.md) + [bcc-trust CLAUDE.md](../app/public/wp-content/plugins/bcc-trust/CLAUDE.md) + the `scripts/*guard*` checks |
| wp-config constants / env vars | [environment.md](environment.md) |
| CI / what blocks merge where | [ci-topology.md](ci-topology.md) |
| Cron jobs | [cron-registry.md](cron-registry.md) |
| Operating in prod | [operator-runbook.md](operator-runbook.md), [testnet-deploy-checklist.md](testnet-deploy-checklist.md) |
| Current debt / what's parked | [TODO.md](TODO.md) |
| Historical audits & shipped plans | [archive/](archive/) |

> There is intentionally **no** separate "architecture map" or "coding standards" doc — those
> already live in `domain-seams.md`/`pattern-registry.md` and the two `CLAUDE.md` files
> respectively. Per §11 (cross-codebase reuse), we link the source of truth rather than fork it.
