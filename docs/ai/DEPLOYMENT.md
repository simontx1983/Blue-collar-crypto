# Deployment, environments and CI

The canonical procedures are:
- [`docs/deploy-runbook.md`](../deploy-runbook.md) for plugin deploys;
- [`docs/rollback-procedure.md`](../rollback-procedure.md) for snapshot, rollback and recovery;
- [`docs/ci-topology.md`](../ci-topology.md) for what CI runs where;
- [`docs/hosting.md`](../hosting.md) and [`docs/environment.md`](../environment.md) for hosts and configuration.

This page connects them and records what was verified on 2026-11-25. Where those docs have drifted, the list is in [CURRENT_STATE.md](CURRENT_STATE.md).

> **Approval rule.** Nothing reaches production without the project owner's explicit approval *for that action at that time*. This covers a plugin deploy, a production data operation (backfill, cleanup, migration apply) and a production configuration change. Only the designated ops role executes them. An approval given for one action does not carry over to the next.
>
> **Frontend merges.** Merging to `bcc-frontend` `main` *is* a production deploy (Vercel).
> - **A normal frontend PR** needs two independent reviews plus the coordinator's agreement, and must be safe against the backend currently in production ([CONVENTIONS.md](CONVENTIONS.md) §6).
> - **A high-risk frontend PR also needs the owner's explicit approval before it merges**, because merging deploys. High-risk means it touches authentication, authorisation, wallet verification, anything financial, sensitive member data or privacy, security controls, or other high-risk production behaviour.
> - **Other gates** in [HANDOFF.md](HANDOFF.md), such as new analytics events or product decisions, apply as well.

## Environments

| Environment | Frontend | WordPress / REST | How code arrives |
|---|---|---|---|
| Local | `next dev` on `localhost:3000` | Local by Flywheel site (nginx, MySQL 8.0, Mailpit) | your working tree |
| Staging | `app.stage.bluecollarcrypto.io` on Vercel [UNKNOWN: which branch it serves today] | `stage.bluecollarcrypto.io` (Hostinger, LiteSpeed). **Not split**: one host | plugins deploy automatically when CI on `main` is green |
| Production | `bluecollarcrypto.io` on Vercel | `cms.bluecollarcrypto.io` (Hostinger, LiteSpeed, MariaDB 11.8) | plugins: manual dispatch. Frontend: every merge to `main` |

**Runtime.** Production and staging run PHP 8.3. CI pins PHP 8.3 for all three plugins. The umbrella guards run on PHP 8.2.

**Environment identity.** `BCC_ENV` uses a closed set of values ([`environment.md`](../environment.md)). `/bcc/v1/internal/identity` and the scheduled `site-url-guard` workflow catch a database restored into the wrong environment.

**Frontend API host.**
- `NEXT_PUBLIC_BCC_API_URL` is `cms.` for production and `stage.` for previews.
- `src/lib/env.ts` rejects the apex or staging as a production API base.
- Indexing is on only for production on the canonical host.

## CI

What each repo runs [VERIFIED, workflow files, 2026-11-25]:

| Repo | Required check (branch protection) | Also runs (not required) |
|---|---|---|
| umbrella | "Cross-repo guards: contract · subsystem · cadence · dead-file". It checks out all four sibling repos at their default-branch tip and runs the five guards plus their regression tests | scheduled `site-url-guard` (daily) and `staging-cache-probe` (weekly) |
| bcc-core | "PHP — PHPStan L8 · PHPUnit", plus a classmap guard (the committed `vendor/` is the deploy artifact) | — |
| bcc-search | "PHP — syntax · PHPStan L8 · PHPUnit" (checks out bcc-core) | deploy health-base guard |
| bcc-trust | "PHP — PHPStan L8 · PHPUnit · guardrails" (unit tests, arch-guardrails, endpoint-exposure guard, mutation controls, golden-staleness, intent-guard harness) and "PHP integration (MySQL)" | MariaDB 11.8 integration, DB mutation controls |
| bcc-frontend | "Frontend — tsc · lint · vitest" (plus knip for dead files) | the Vercel build status (not required, but read it: `next build` runs only there) |

**How the repos interact:**
- On every push to `main`, each sibling repo notifies the umbrella, which re-runs its cross-repo guards.
- **A staging deploy fires only when the whole CI workflow succeeds**, including the jobs that aren't required for merge.
- Sibling checkouts in CI use the **current default-branch tip**, not a pinned SHA. So a trust PR that needs an unmerged core change fails until core merges. Re-running a job reuses the original merge ref; update the branch instead.

## Plugins: staging to production

### Staging (automatic) [VERIFIED]

Merging to a plugin's `main` triggers CI. On success, the `Deploy` workflow rsyncs that commit to staging.
- There is no build step: the committed `vendor/` ships.
- Dev files are excluded: tests, composer files, PHPStan and PHPUnit config, `.git*`.
- A post-deploy health probe must see HTTP 200 on one public route per plugin.
- A final guard fails the job if dev files remain on the target.

### Production (manual) [VERIFIED]

`Deploy` is run with `workflow_dispatch`, `environment=production`, from `main`, with an `expected_sha` input.
- The workflow refuses unless `expected_sha` equals the checked-out `main` tip. It is an assertion, not a selector: **a release is always `main`'s tip**, and you cannot deploy an older SHA.
- Consequence: **anything merged to a plugin's `main` ships with the next production dispatch**, whatever that dispatch was meant for. Merge with that in mind, and freeze plugin merges while a release is being cut.

### Release order: core → search → trust [DOC]

Nothing in CI enforces this order; the person running the release does.
- **Why:** bcc-trust and bcc-search refuse to boot without bcc-core, and new trust or search features often call new core APIs. Those answer 503 until core is live, and old core silently ignores new arguments.
- **Paired changes.** When a trust change depends on a search change (or the reverse), the PR descriptions and the contract changelog say "deploy together" or "X first". Collect these when cutting a release.
- **Mixed-tree window.** `--delay-updates` on the rsync narrows the moment when half a plugin is new, but it does not remove it.

### Release checklist

The specific gates for each release are tracked privately by the maintainers. The general shape:
1. **Agree the release cut.** Decide which `main` tips ship, collect every "deploy order" and "deploy together" note from the merged PRs, and freeze plugin merges.
2. **Staging is green.** The same SHAs are on staging, and staging checks pass. For releases touching wallets, disputes or sign-in, a human runs the relevant end-to-end smoke test on staging with test accounts.
3. **Back up.** A pre-deploy database dump is mandatory and is the rollback point. The deploy workflow takes no backup.
4. **Get the owner's approval** for this release.
5. **Dispatch** core, then search, then trust. Each dispatch passes its `expected_sha`, and each health probe must pass before the next plugin.
6. **Schema.** If any trust schema file's normalised hash changed, `dbDelta` runs on the first request ([DATABASE.md](DATABASE.md)). Watch for the version being stamped.
7. **Caches.** Purge where the release notes say to: an object-cache group or tag, or the LiteSpeed cache on `cms.`. A stale persistent object cache can make the schema installer re-run on every request, so flush it on upgrade ([`testnet-deploy-checklist.md`](../testnet-deploy-checklist.md), caching section).
8. **Read-only post-checks.** Health endpoint, probes, and the specific checks named in the release notes.
9. **Then merge held frontend PRs.** These are frontend PRs that were waiting for this backend to be live.

### Rollback [DOC, `rollback-procedure.md`]

- **Code:** revert on `main`, which redeploys staging, then dispatch production at the new tip. Any other route follows `rollback-procedure.md`, with the owner's approval.
- **Data:** restore the pre-deploy dump, then flush the object cache.
- **Frontend:** use Vercel "Promote" on the previous deployment, or `vercel rollback`.

## Frontend [VERIFIED]

- **Deploys** come from the Vercel Git integration, not GitHub Actions. Every merge to `main` becomes a **Production** deployment. Every PR or branch push gets a **Preview** deployment, pointed at the staging API.
- **Required checks:** CI runs tsc, lint, knip and vitest. `next build` runs only on Vercel, so the Vercel status is the only build signal. It is not a required check, so **read it before merging**.
- **Vercel Cron** calls `/api/internal/cron/indexer-tick` every minute. The route forwards to WordPress with a shared secret.
- **Order against the backend:** a frontend change that needs a new backend field must either tolerate the field being absent, or wait until the backend release is live.

## Local development

See [`docs/dev-setup.md`](../dev-setup.md): the repo model, clone, install, run, minimum configuration and first-request verification.

Points that catch people out:
- **Five repos, one tree.** The umbrella tracks only `CLAUDE.md`, `AGENTS.md`, `README.md`, `docs/`, `.claude/`, `scripts/`, `.github/`, `.mcp.json` and `bcc-global-library/`. It does this through a deny-all `.gitignore` with explicit re-allows. **A new top-level file is invisible to git until you allowlist it.**
- **Plugin checkouts.** The plugins and the frontend are separate repos inside the same tree.
- **Throwaway work goes in worktrees.** Use `.claude/worktrees/`, never `%TEMP%` (see [CONVENTIONS.md](CONVENTIONS.md) §8).
