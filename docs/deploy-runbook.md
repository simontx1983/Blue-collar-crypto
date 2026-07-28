# Deploy Runbook — WordPress plugins (bcc-trust / bcc-core / bcc-search)

How the BCC WordPress plugins reach the staging and production servers.
The Next.js frontend (`bcc-frontend`) is **not** covered here — Vercel
auto-deploys it from GitHub.

## TL;DR

- **Staging is automatic.** Merge to a plugin's `main` → CI passes → the
  plugin rsyncs to the staging server. No clicks.
- **Production is manual.** Actions → **Deploy** → *Run workflow* →
  `environment: production`. A deliberate button, per the staging-first
  validation cadence.
- **Git Updater is retired** (deactivated on staging; it was never on prod).

## Why this replaced Git Updater

Git Updater is **version-header-driven**: it only offers an update when a
plugin's `Version:` header (or a release tag) changes. BCC batches releases
(no per-merge version bump — see the `feedback_batch_releases` convention),
so un-bumped merges were **invisible** to it. Concretely: `bcc-search`
shipped six PRs — including four privacy/security fixes (anonymous
`user_login` leak, secret group/page exclusion, user-search privacy filter)
— while the header stayed `1.0.6`, so staging silently ran a months-old
snapshot. A **commit-driven** pipeline deploys the exact SHA that passed CI,
regardless of the version header.

## How it works

Each plugin repo has `.github/workflows/deploy.yml`:

- **`workflow_run`** trigger — after the repo's `CI` workflow completes
  **successfully** on a **push to `main`** (i.e. a merge), it deploys that
  commit to **staging**.
- **`workflow_dispatch`** trigger — manual; pick `staging` or `production`.
- The deploy step is a **plugin-scoped `rsync -az --delete`** of the checked-
  out tree (minus `.git`/`.github`) into that plugin's directory on the
  server. `vendor/` is committed, so there is **no build step** — the
  checkout *is* the artifact. A final step greps the deployed `Version:`
  header back over SSH as a confirmation.
- **The environment selects the docroot** (`deploy.yml:57-61`): staging
  targets `…/stage/wp-content/plugins/<plugin>/`, production targets
  `…/wp-content/plugins/<plugin>/` — same account, different tree.

### Why plugin-scoped rsync is safe

The target is exactly the one plugin dir — `…/stage/wp-content/plugins/<plugin>/`
for staging, `…/wp-content/plugins/<plugin>/` for production. That means the
**webroot `.htaccess` is never in range** — critically, it holds the
hand-added LSCache Authorization-bypass block (see
[testnet-deploy-checklist.md](testnet-deploy-checklist.md) §1.6 and the
closed bug in [TODO.md](TODO.md)). Likewise `uploads/`, `wp-config.php`, and
every other plugin are untouched. `--delete` only removes files that were
deleted from the repo, within the one plugin dir.

## Secrets (per repo)

Five Actions secrets, identical across `bcc-trust`, `bcc-core`, `bcc-search`:

| Secret | Value / meaning |
|---|---|
| `DEPLOY_SSH_KEY` | Private half of the dedicated deploy keypair. |
| `DEPLOY_HOST` | Server IP. |
| `DEPLOY_PORT` | SSH port. |
| `DEPLOY_USER` | Server (account) user. |
| `DEPLOY_BASE` | **Home-relative** path to the webroot, e.g. `domains/<site>/public_html` — **no leading slash**. |

> **`DEPLOY_BASE` gotcha:** set it as a *relative* path. Under Git Bash on
> Windows, `gh secret set --body "/home/…"` triggers MSYS path conversion and
> mangles a leading-slash value; a relative path sidesteps it and resolves
> against the SSH login home. (Set from a POSIX shell, or with
> `MSYS2_ARG_CONV_EXCL='*'`.)

The deploy key is **dedicated** (separate from any personal access key) so it
can be rotated independently. On shared hosting it grants account-level SSH,
so treat it accordingly: to rotate, generate a new keypair, replace the
public half in the server's `~/.ssh/authorized_keys`, and update
`DEPLOY_SSH_KEY` in all three repos.

## Promoting to production

1. Validate on staging first (the whole point of the cadence).
2. In the plugin's GitHub repo: **Actions → Deploy → Run workflow → Branch
   `main` → `environment: production`**.
3. The same plugin-scoped rsync targets the production plugin dir. Confirm the
   version step at the end.
4. Repeat per plugin as needed.

Production has **no** auto-deploy and **no** Git Updater — it only changes when
someone runs the production dispatch (or, historically, a manual copy).

> **Feature flags are separate from deploys.** Deploying the code does not enable
> a dark-shipped feature. For validator messaging (kill-switch
> `bcc_validator_messaging_enabled`, default OFF), enablement follows its own
> gated, staging-first procedure — see
> [validator-messaging-rollout.md](validator-messaging-rollout.md). Production
> enablement is separately approved.

## Troubleshooting

- **Deploy didn't fire after a merge** — check the `CI` run went green; the
  `workflow_run` deploy only triggers on CI **success** for a push to `main`.
- **`rsync: mkdir … failed: No such file or directory`** — `DEPLOY_BASE` is
  wrong (often the MSYS mangling above). Verify the resolved path exists over
  SSH; re-set the secret relative.
- **Verify staging == GitHub** — blob-hash manifest: for each tracked file,
  compare `git cat-file blob origin/main:<f> | git hash-object --stdin`
  against `ssh … git hash-object <server>/<f>`. Zero mismatches = in sync.
  (Line-ending-safe, unlike a raw byte diff.)
