# CI Topology — Blue Collar Crypto

The project is **five separate GitHub repos** (all under `github.com/simontx1983/`):
`Blue-collar-crypto` (umbrella), `bcc-trust`, `bcc-core`, `bcc-search`, `bcc-frontend`. CI is
split into two tiers — per-repo correctness gates, plus umbrella cross-repo static guards —
wired together by a `repository_dispatch` notify shim. Since 2026-07-15 the three plugin repos
also carry a **deploy layer** (`deploy.yml` — staging auto / production manual; mechanics in
[deploy-runbook.md](deploy-runbook.md)). This doc explains where each check lives
and what (cannot be confirmed to) block merge.

## Per-location workflows

| Location | Workflow(s) | Triggers (`on:`) | Runs | Gates |
|---|---|---|---|---|
| **umbrella** (root) | `.github/workflows/ci.yml` + `staging-cache-probe.yml` + `site-url-guard.yml` | ci: `push:[main]`, `pull_request`, `workflow_dispatch`, `repository_dispatch:[sibling-push]`; probe: schedule (weekly) + `workflow_dispatch`; site-url-guard: schedule (daily) + `workflow_dispatch` | ci: reassembles the WP layout (checks out all 4 siblings into their dev paths), PHP 8.2, then **5 static guards** plus their regression-test steps (guards-fail-closed, schema-drift, column-parity, site-url-probe parser, auth-cache probe): `contract-parity-guard.php`, `subsystem-count-guard.php`, `cadence-pressure-guard.sh`, `dead-file-scan.php`, `schema-drift-guard.php` (static mode — live-DB checks self-skip; armed 2026-07-23). probe: runs `scripts/auth-cache-isolation-probe.sh` against staging (Authorization cache-isolation regression watch, 2026-07-19); exit 4 SKIPPED (no `x-litespeed-cache` marker: staging serves REST uncached) is green with a notice, not red (F-418). site-url-guard: jobs `production` + `staging` run `scripts/site-url-probe.sh` (siteurl/home identity drift) | the cross-repo static guards |
| **bcc-trust** | `ci.yml` + `deploy.yml` + `notify-root.yml` | `push:[main]`, `pull_request`; deploy: `workflow_run` (CI success on main) + `workflow_dispatch` | job `php`: checks out bcc-core as sibling, `composer install`, PHPStan L8, PHPUnit, arch-guardrails (incl. PHP syntax), endpoint-exposure and mutation-control guards, golden-fixture staleness and intent-guard harness. `integration`: MySQL 8.0 service + integration tests vs real `$wpdb`. `mutation-controls-db` and `mutation-controls-revocation`: MySQL mutation-control jobs. `integration-mariadb`: MariaDB 11.8 compatibility group | bcc-trust static analysis, tests, guardrails, and integration/mutation controls |
| **bcc-core** | `ci.yml` + `deploy.yml` + `notify-root.yml` | `push:[main]`, `pull_request`; deploy: as above | `composer install`, Composer classmap parity guard, PHPStan L8 (`--memory-limit=4G`), PHPUnit. No integration/guardrails job. | bcc-core classmap/PHPStan/PHPUnit |
| **bcc-search** | `ci.yml` + `deploy.yml` + `notify-root.yml` | `push:[main]`, `pull_request`; deploy: as above | **PHP syntax · PHPStan L8 · PHPUnit** (test harness landed 2026-07-08). job `workflow-guard`: "Deploy health-base guard" (`.github/scripts/check-deploy-health-base.py`) | bcc-search's PHP syntax/PHPStan/PHPUnit and deploy-workflow guard |
| **bcc-frontend** | `ci.yml` + `notify-root.yml` | `push:[main]`, `pull_request` | `npm ci`, `tsc` (`--noEmit`), ESLint (`next lint`), knip dead-file guard (`--include files`), Vitest. `next build` is **not** run in CI (validated by Vercel preview deploys). | frontend typecheck/lint/dead-file/unit |

## How it fits together

1. **Per-repo "real" gates live where the code lives.** Each kind of correctness check runs in
   its own repo's CI — `bcc-trust` is the heaviest (static analysis + unit + a live-MySQL
   integration job, plus it checks out `bcc-core` so cross-plugin types resolve); `bcc-core` is classmap parity + static analysis + unit; `bcc-frontend` is typecheck + lint + dead-file guard + unit; `bcc-search` is syntax + static analysis + unit + a deploy-workflow guard.
2. **The umbrella owns the cross-repo static guards** that span the *contract docs* (which live
   in the umbrella) and the *code* (which lives in the siblings): contract-vs-code parity,
   subsystem-count parity, cadence-pressure policy, dead-file scan, schema-drift parity
   (static mode). The umbrella workflow
   reassembles the WordPress layout in the runner so these path-spanning scripts can see both.
   It runs **only static guards** — no PHPStan/PHPUnit/tsc.
3. **Deploy layer (2026-07-15).** Each plugin repo's `deploy.yml` rsyncs the CI-green merge
   commit to **staging automatically** (`workflow_run` on CI success for a push to `main`) and to
   **production only via manual `workflow_dispatch`**. Staging and production use different
   docroots (`stage/…` vs `public_html/…`). Production refuses any commit but the dispatched
   `expected_sha`; every deploy ends with a health probe, and bcc-core / bcc-trust also with a
   stranded-path guard (refuses a target that still holds `.git`/`.github`). Full mechanics,
   secrets, and the `DEPLOY_BASE` gotcha: [deploy-runbook.md](deploy-runbook.md). `bcc-frontend` deploys via Vercel, not Actions.
4. **Cross-repo trigger.** Because the repos are separate, a route/contract change in a plugin
   wouldn't otherwise re-check the contract docs in the umbrella. Each sibling has a `notify-root.yml` that, on push to `main`, calls
   `POST repos/simontx1983/Blue-collar-crypto/dispatches` with `event_type=sibling-push`. The
   umbrella's `ci.yml` listens via `repository_dispatch:[sibling-push]` and re-runs the guards
   against the freshly-pushed sibling. The three plugin repos fail their notify job when
   `DISPATCH_TOKEN` is missing; the frontend shim warns and skips instead.

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
  - **bcc-search** — whether its workflow checks are required is GitHub-side branch-protection
    state and cannot be inferred from this workflow file. This reconciliation does not change or
    re-check branch protection.
  `schema-drift-guard.php` was armed in the umbrella `ci.yml` on 2026-07-23 (static mode). It
  runs as a step inside the same required `Cross-repo guards` job, so it already blocks
  umbrella merges — the required-check *name* string is unchanged.
- **Cross-repo trigger is push-to-`main` only.** A plugin *PR* does not re-trigger the umbrella
  guards — only a push to the sibling's `main` (or a manual umbrella `workflow_dispatch`) does.
- **`next build` is not gated in GitHub Actions** — delegated to Vercel preview deploys; whether
  Vercel success is a required check is Vercel/GitHub-side config, not in these files.
