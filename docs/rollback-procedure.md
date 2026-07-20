# Rollback & Recovery Procedure — Blue Collar Crypto

How to recover when a deploy goes wrong: take a recoverable snapshot, deploy, smoke-test,
and — if needed — roll the backend, frontend, and database back to the last known-good state.
Companion to [testnet-deploy-checklist.md](testnet-deploy-checklist.md) (the deploy steps) and
[operator-runbook.md](operator-runbook.md) (live incident response).

> **Status (2026-07-19):** staging is stood up and load-tested; production exists but is
> **frozen pre-launch** (intentionally runs an older plugin set — do not touch without explicit
> authorization). Backend deploys by the **commit-driven CI pipeline**
> ([deploy-runbook.md](deploy-runbook.md) — staging auto on CI-green merge, production manual
> dispatch; the "git pull on the server" model this doc originally assumed is retired).
> Frontend is **Vercel**. **There are no automated DB backups yet** — see the DR gap below.

---

## 0. DR posture — the gap to close before testnet

**There is currently no automated/offsite database backup.** Until a host backup add-on or a
scheduled `mysqldump` exists, the **pre-deploy `mysqldump` in §2 is the only rollback point** for
data. Treat taking it as a non-skippable step. This is the single highest-risk operational gap
at launch — establish at least a scheduled daily dump (offsite copy) before real users exist.

---

## 1. Before you touch production: pin the known-good state

Capture two things so "roll back" has a concrete target:

1. **Code (per repo).** Each of the 5 repos deploys independently (see
   [ci-topology.md](ci-topology.md)). Record the currently-deployed commit of each so you can
   return to it: the deployed SHA is the head of the latest **successful Deploy workflow run**
   in each plugin repo (Actions → Deploy), or `git rev-parse origin/main` captured at deploy
   time. Tag it locally if helpful:
   ```bash
   git -C <repo> tag -f last-good-<date> <sha> && git -C <repo> push origin last-good-<date>
   ```
   The repos: `bcc-core`, `bcc-trust`, `bcc-search` (under `app/public/wp-content/plugins/`),
   `bcc-frontend`, and the umbrella. Roll back is a **pipeline re-deploy at that SHA** (§4b) —
   not a git operation on the server.
2. **Database.** Take the pre-deploy snapshot in §2.

---

## 2. Pre-deploy backup (the rollback point) — MANDATORY

Take a `mysqldump` of the BCC tables (and ideally the whole WP DB) immediately before deploying.
**Validated by the 2026-06-25 restore drill** (§6) — these exact commands round-trip cleanly.

```bash
# --- production / testnet (use the host's DB creds) ---
mysqldump -h <DB_HOST> -P <DB_PORT> -u <USER> -p <DB_NAME> \
  --no-tablespaces --single-transaction \
  > backup-$(date +%Y%m%d-%H%M%S).sql        # whole DB (preferred for a real rollback point)

# BCC-only variant (faster; sufficient to roll back a BCC-only change):
TABLES=$(mysql -h <DB_HOST> -P <DB_PORT> -u <USER> -p -N -e \
  "SELECT table_name FROM information_schema.tables \
   WHERE table_schema='<DB_NAME>' AND table_name LIKE 'wp_bcc%';" | tr -d '\r')
mysqldump -h <DB_HOST> ... <DB_NAME> $TABLES --no-tablespaces --single-transaction > bcc-backup.sql
```
> Windows/Local note: `mysql.exe` emits CRLF — pipe table lists through `tr -d '\r'` or the names
> carry a trailing `\r` and `mysqldump` silently skips them (a real failure mode hit in the drill).

Store the dump **off the server** (download it). On a host with no backup add-on, this dump is
your only restore source.

---

## 3. Deploy & smoke

1. Deploy per [deploy-runbook.md](deploy-runbook.md) (plugin-scoped rsync via Actions — staging
   auto, production manual dispatch). First-time provisioning of a fresh box:
   [testnet-deploy-checklist.md](testnet-deploy-checklist.md) (activate bcc-core first;
   `wp cache flush`).
2. Run the **post-deploy health gates** (checklist §5) and the smoke walk in
   [GOLDEN_PATHS.md](GOLDEN_PATHS.md): `/system/ping` → auth critical path → `/system/health` all
   GREEN → DegradationMetric noise floor → guard scripts exit 0 → boot-floor probe.
3. If a gate fails and you can't explain it → **roll back (§4), don't push forward.**

---

## 4. Rollback

Roll back the layer that broke; you rarely need all three.

### 4a. Frontend (Vercel) — easiest, instant
Vercel keeps every deployment. **Promote the previous good deployment** (Vercel dashboard →
Deployments → the last-good build → "Promote to Production"), or `vercel rollback`. No DB impact.

### 4b. Backend (CI pipeline) — code only
The deployed tree is an **rsync target, not a git checkout** — never `git checkout` on the
server. Redeploy the last-good commit through the pipeline, per affected plugin repo:
1. Point a ref at the last-good SHA from §1 (use the tag, or `git branch rollback/<date> <sha>`
   pushed to origin).
2. **Actions → Deploy → Run workflow** → select that ref → pick the environment. The
   plugin-scoped rsync (with `--delete`) makes the server tree match that commit exactly.
3. After the run's version-confirm step passes: `wp cache flush` on the box (over SSH).

Re-run the §3 health gates. If the bad deploy ran a schema migration, also do §4c.

### 4c. Database — restore the pre-deploy dump
Only when a deploy corrupted/migrated data and a code rollback isn't enough:
```bash
# Restore into the live DB (DESTRUCTIVE — overwrites current state with the snapshot):
mysql -h <DB_HOST> -P <DB_PORT> -u <USER> -p <DB_NAME> < backup-<timestamp>.sql
wp cache flush
```
Prefer restoring into a **scratch DB first** to inspect, then swap, if downtime allows. After any
restore: `wp cache flush` (object cache + read-model caches must be re-warmed).

### When to roll back vs. forward-fix
- Roll back: auth broken, `/system/ping` 503, data integrity at risk, or cause unknown.
- Forward-fix: a single isolated subsystem is degraded (DegradationMetric firing) but the critical
  paths are green — triage via [operator-runbook.md](operator-runbook.md) instead.

---

## 5. DB migration safety (lessons the drill made concrete)

Schema migrations are the riskiest deploys. Rules, all validated in §6:
- **Migrations are `.sql` files (or the `drop-legacy-*.php` pattern), never inline shell.** Inline
  multi-statement strings through `mysql.exe` silently drop statements (Git Bash quoting + CRLF).
- **`mysql < file` halts on the first error** (good — it's atomic-ish), so the migration must be
  **correctly ordered**. Do **not** paper over errors with `--force` or `SET FOREIGN_KEY_CHECKS=0`
  (the latter leaves dangling FKs).
- **Foreign keys exist on a few BCC tables** — check before dropping/altering. The schema-version
  gate (`bcc-trust.php`) runs migrations once on version bump; pair every migration with a tested
  rollback path (the pre-deploy dump).
- Always rehearse a destructive migration against a **restored copy** first (§6).

### Phase-5 orphan-drop migration (validated, FK-aware)
The Phase-5 cleanup must drop one foreign key **before** the table drops, or it fails on the live
DB (the active `wp_bcc_trust_endorsements` holds `fk_endorsement_type` into the orphan
`wp_bcc_endorsement_types`). Proven ordering:
```sql
ALTER TABLE `wp_bcc_trust_endorsements` DROP FOREIGN KEY `fk_endorsement_type`;
-- then DROP TABLE IF EXISTS the 17 verified orphans (list: database-schema.md orphan section).
-- (project_* orphans carry outbound FKs to wp_posts that drop with the child table — fine.)
```

---

## 6. The restore drill (re-run this before any scary deploy)

Proves backup → restore → migration end-to-end without touching the live DB. Last run
**2026-06-25** (local dev DB, Local-by-Flywheel MySQL 8.0.35 on `127.0.0.1:10014`, the port
rotates — check `local-site.json` / `netstat`). Local `mysql`/`mysqldump` live under
`…/AppData/Roaming/Local/lightning-services/mysql-8.0.35+4/bin/win64/bin/`.

1. `mysqldump` the `wp_bcc%` tables from the live DB (§2, CRLF-stripped table list).
2. `CREATE DATABASE bcc_drill;` and restore the dump into it (isolated — live DB untouched).
3. Apply the candidate migration `.sql` to `bcc_drill`.
4. Verify the expected end-state (e.g. table count, no orphans left, active tables intact, no
   dangling FKs), then `DROP DATABASE bcc_drill;`.

**2026-06-25 result:** 64 → restore 64 → FK-aware Phase-5 migration → **47** active tables;
`wp_bcc_trust_endorsements` intact with 0 dangling FKs; live DB unchanged (64). The drill also
**caught the `fk_endorsement_type` blocker** that a naive `DROP TABLE` migration would have hit in
production — which is the whole point of rehearsing.

---

## 7. Emergency levers & escalation

See [operator-runbook.md](operator-runbook.md): §9 (suspected key compromise — rotate out-of-band),
§10 (things NOT to do at 2am), and Escalation. Secrets are never in the DB/admin UI
([environment.md](environment.md) §D), so a DB restore never re-exposes a rotated secret.
