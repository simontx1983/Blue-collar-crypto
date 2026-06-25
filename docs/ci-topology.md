# CI Topology — Blue Collar Crypto

The project is **five separate GitHub repos** (all under `github.com/simontx1983/`):
`Blue-collar-crypto` (umbrella), `bcc-trust`, `bcc-core`, `bcc-search`, `bcc-frontend`. CI is
split into two tiers — per-repo correctness gates, plus umbrella cross-repo static guards —
wired together by a `repository_dispatch` notify shim. This doc explains where each check lives
and what (cannot be confirmed to) block merge.

## Per-location workflows

| Location | Workflow(s) | Triggers (`on:`) | Runs | Gates |
|---|---|---|---|---|
| **umbrella** (root) | `.github/workflows/ci.yml` | `push:[main]`, `pull_request`, `workflow_dispatch`, `repository_dispatch:[sibling-push]` | Reassembles the WP layout (checks out all 4 siblings into their dev paths), PHP 8.2, then **4 static guards**: `contract-parity-guard.php`, `subsystem-count-guard.php`, `cadence-pressure-guard.sh`, `dead-file-scan.php` | the cross-repo static guards |
| **bcc-trust** | `ci.yml` + `notify-root.yml` | `push:[main]`, `pull_request` | job `php`: checks out bcc-core as sibling, `composer update`, **PHPStan L8**, **PHPUnit**, **arch-guardrails**. job `integration`: **MySQL 8.0 service** + integration tests vs real `$wpdb` | bcc-trust's PHPStan/PHPUnit/integration/guardrails |
| **bcc-core** | `ci.yml` + `notify-root.yml` | `push:[main]`, `pull_request` | `composer install`, **PHPStan L8** (`--memory-limit=4G`), **PHPUnit**. No integration/guardrails job. | bcc-core's PHPStan/PHPUnit |
| **bcc-search** | `notify-root.yml` **only** | `push:[main]` | only the cross-repo notify shim — **no PHPStan/PHPUnit/lint of its own** | nothing locally (only triggers umbrella guards) |
| **bcc-frontend** | `ci.yml` + `notify-root.yml` | `push:[main]`, `pull_request` | `npm ci`, **tsc** (`--noEmit`), **ESLint** (`next lint`), **Vitest**. `next build` is **not** run in CI (validated by Vercel preview deploys). | frontend typecheck/lint/unit |

## How it fits together

1. **Per-repo "real" gates live where the code lives.** Each kind of correctness check runs in
   its own repo's CI — `bcc-trust` is the heaviest (static analysis + unit + a live-MySQL
   integration job, plus it checks out `bcc-core` so cross-plugin types resolve); `bcc-core` is
   static-analysis + unit; `bcc-frontend` is typecheck + lint + unit; `bcc-search` has none.
2. **The umbrella owns the cross-repo static guards** that span the *contract docs* (which live
   in the umbrella) and the *code* (which lives in the siblings): contract-vs-code parity,
   subsystem-count parity, cadence-pressure policy, dead-file scan. The umbrella workflow
   reassembles the WordPress layout in the runner so these path-spanning scripts can see both.
   It runs **only static guards** — no PHPStan/PHPUnit/tsc.
3. **Cross-repo trigger.** Because the repos are separate, a route/contract change in a plugin
   wouldn't otherwise re-check the contract docs in the umbrella. Each sibling has an identical
   `notify-root.yml` that, on push to `main`, calls
   `POST repos/simontx1983/Blue-collar-crypto/dispatches` with `event_type=sibling-push`. The
   umbrella's `ci.yml` listens via `repository_dispatch:[sibling-push]` and re-runs the 4 guards
   against the freshly-pushed sibling. Requires a `DISPATCH_TOKEN` secret in each sibling; if
   unset, the notify job emits a `::warning::` and no-ops (non-breaking).

## Known gaps / cannot-be-determined

- **What blocks merge (GitHub branch protection on `main`, armed in Phase 2c 2026-06-25 — this
  is server-side config, not in the repo files; query via `gh api .../branches/main/protection`):**
  - **bcc-trust** — required: `PHP — PHPStan L8 · PHPUnit · guardrails`, `PHP integration (MySQL)`.
  - **bcc-core** — required: `PHP — PHPStan L8 · PHPUnit`.
  - **bcc-frontend** — required: `Frontend — tsc · lint · vitest`.
  - **Blue-collar-crypto (umbrella)** — required: `Cross-repo guards — contract · subsystem ·
    cadence · dead-file` (so contract-parity/subsystem/cadence/dead-file now block umbrella merges).
  - `enforce_admins = true` on all four — required checks block **everyone**, including the two
    admin engineers (no override escape hatch; chosen 2026-06-25). `strict = false` (PRs need not
    be up to date with main before merge).
  - **bcc-search** — intentionally **not protected**: it has no CI checks of its own to require.
  Once the Phase-5 `schema-drift-guard.php` is armed, add it to the umbrella required-checks list.
- **`bcc-search` has no test/type CI** — only the notify shim. Any quality gate for it is not
  expressed as a workflow.
- **Cross-repo trigger is push-to-`main` only.** A plugin *PR* does not re-trigger the umbrella
  guards — only a push to the sibling's `main` (or a manual umbrella `workflow_dispatch`) does.
- **`next build` is not gated in GitHub Actions** — delegated to Vercel preview deploys; whether
  Vercel success is a required check is Vercel/GitHub-side config, not in these files.
- **`wallet-case-preservation-check.php`** exists in `scripts/` and is referenced in the umbrella
  `ci.yml` only by an **exclusion comment** — it is deliberately **not run** as a static guard
  (it boots WordPress + needs a DB, so it's a live-site check, not a CI gate).
