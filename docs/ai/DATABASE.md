# Database

The canonical table inventory is [`docs/database-schema.md`](../database-schema.md). Every `CREATE TABLE` has a row there, and the CI guard `scripts/schema-drift-guard.php` blocks a table without one.

This page covers the **rules** for touching data and the reasons behind them. Don't copy row counts from `database-schema.md`, or from anywhere else, into public text.

## Engines and environments

| Where | Engine |
|---|---|
| Production | MariaDB 11.8 |
| Local (Local by Flywheel) | MySQL 8.0 |
| CI | Integration tests run on MySQL 8.0 (a required check) and on MariaDB 11.8 (`--group mariadb`, not required) |

[DOC, `docs/environment.md`]

The two engines really differ, for example on `DISTINCT` combined with `ORDER BY`. A query that passes on one can fail on the other.

## Table families

| Prefix | Owner | Rule |
|---|---|---|
| `wp_bcc_*` | BCC plugins | Name a table with `BCC\Core\DB\DB::table('name')`, which returns `$wpdb->prefix . 'bcc_' . name` and validates the segment. **The old name-redirect filter was removed on purpose**: a co-installed plugin could otherwise point BCC queries at `wp_users` |
| `peepso_*` | PeepSo | BCC writes only through `bcc-core:src/PeepSo/*Writer.php`. Reads go through repositories. See [ARCHITECTURE.md](ARCHITECTURE.md) §3.4 |
| WordPress core (`wp_posts`, `wp_users`, `*meta`, `wp_options`) | WordPress | Meta reads that decide access must be probe-verified (below) |

Main BCC tables by domain. Names come from code and the glossary; `database-schema.md` is authoritative.
- **Wallets and on-chain:** `bcc_wallet_links`, `bcc_onchain_claims`, `bcc_onchain_collections`, `bcc_onchain_validators`, `bcc_onchain_signals`, `bcc_helius_seen_signatures`.
- **Trust:** `bcc_trust_votes`, `bcc_trust_attestations`, `bcc_page_read_model`, `bcc_trust_rank_state`, `bcc_trust_polls`, `bcc_trust_ballots`.
- **Disputes:** the `bcc_disputes_*` tables.
- **Watching:** the `bcc_watch_*` sidecars on top of PeepSo follows.

## Access rules (backend §1–§5)

These are the backend architecture rules. Until this page they were written down only in an untracked file. [CONVENTIONS.md](CONVENTIONS.md) has the full §1–§9 list.

1. **`$wpdb` only in repositories.** All `$wpdb` access lives in `app/Domain/*/Repositories/` or `app/Infrastructure/`. Each documented exception carries a comment, for example `TransactionManager`, because transaction-control statements can't hide behind a repository.
2. **No `SELECT *`.** Use explicit column lists, usually a `private const COLUMNS`.
3. **Bounded queries.** Every `SELECT` has a `LIMIT`, a unique-key filter, a bounded `IN ()`, or is an aggregate.
4. **No queries in templates.** There are no templates anyway: PHP renders no UI.
5. **Generation-counter cache invalidation.** Write paths bump a generation counter, and read paths key their cache by it. Never "delete the cache key and hope".

`bash scripts/arch-guardrails.sh bcc-trust` enforces these in bcc-trust CI. Use the `new-repository` skill (Claude) or copy an existing repository (Codex) when you add a repository class.

## Fail-closed reads (the most important rule)

**The problem.** `$wpdb->get_results()` returns `null` on a SQL error, and the idiom `?: []` turns "the query broke" into "there are no rows". When that answer decides access, privacy, moderation or a sitemap, the result is a silent leak or a silent lockout.

**The building blocks:**
- **`BCC\Core\Repositories\ProbesReads`** (a trait in bcc-core):
  - runs `query()` explicitly and checks `=== false`;
  - clears `last_error` first;
  - reports whether the result can be trusted, with `trustedRows()` as the helper that returns rows.
  - *Why:* `wpdb::query()` can bail out *before* running SQL. It returns false without setting `last_error`, and the previous query's rows are still in `last_result`.
- **`GuardsReadFailures`** (bcc-trust, `app/Domain/Onchain/Repositories/`). Choose by **who consumes the answer**, not by table:
  - `guardRead()` is fail-safe: it logs and returns empty. Use it for workers.
  - `guardReadOrThrow()` and the `runRead*()` family are fail-closed: they throw `RepositoryReadFailure`. Use them for anything user- or operator-facing.
- **`MetaCacheGuard`** (bcc-core) verifies post and user meta reads before caching them. An untrusted read caches nothing, and `MetaReadHealth` lets gates refuse.

**What callers do with an untrusted read:**

| Answer that depends on it | What the caller does |
|---|---|
| Access or visibility | deny, or exclude the item |
| A list endpoint | `503 bcc_unavailable`, never a partial list |
| A moderation or caution list | use the last-known-good list |
| A suspension check | "unknown", which is never cached or acted on |

**wpdb and WordPress traps behind this doctrine** (full list in [CONVENTIONS.md](CONVENTIONS.md) "Traps"):
- `get_col()` never returns `null`.
- `get_row(null)` ignores `last_result`.
- `query()` returns `0` for successful DDL.
- A failed `get_users()` lookup is cached.
- `wp_cache_add` in a fallback can make a poisoned entry permanent.
- The Redis drop-in returns its in-memory copy on a same-request re-read.

## Transactions and locks [VERIFIED]

- **`BCC\Trust\Core\Security\TransactionManager::run(callable)`** handles every multi-statement write:
  - deadlock and lock-timeout retry with backoff;
  - nested savepoints;
  - recovery from a stale depth counter.

  A body that hits a lock conflict must **throw**, so the manager can roll back and retry the whole body.
- **`BCC\Core\DB\TransactionState`** asks the *connection* whether a transaction is open, with a SAVEPOINT/RELEASE probe where error 1305 means "outside". It answers INSIDE, OUTSIDE or UNKNOWN, and callers refuse on UNKNOWN.
  - *Why not `@@in_transaction` or `INNODB_TRX`:* the first exists only on MariaDB, and MySQL 8 caches the second.
- **Advisory locks** use `BCC\Core\DB\AdvisoryLock` (`GET_LOCK`). `GET_LOCK` is **server-wide**, so integration suites sharing one DB server collide; run them sequentially.

## Schema install and migrations

**The schema stamp** [VERIFIED, `bcc-trust:bcc-trust.php`]. `BCC_TRUST_SCHEMA_VERSION` is a content hash of `includes/database/schema-*.php` plus a few repositories. Comments and whitespace are stripped before hashing.

**Install on change.**
- On `plugins_loaded`, if the stored option differs from the hash, trust takes a lock, runs the installers (`dbDelta`), and stamps the version only when every migration reports completion.
- A failed pass backs off for an hour.
- So any deploy that changes a schema file fires `dbDelta` on the first request; there is no manual migration step.

**Why a hash.** The earlier mtime-based stamp misfired under rsync and atomic-symlink deploys.

**Comment-only edits** [VERIFIED, `bcc-trust:includes/database/compute-schema-version.php`]. These no longer fire the installer. Since F-151a (private tracker), the hash covers each file's PHP token stream with comments and whitespace removed. String and heredoc bodies, where the SQL lives, are kept byte for byte, so any real DDL change still moves the version.
- *Why a normalised hash instead of a hand-bumped constant:* a forgotten bump silently never installs. A hash can only err toward an extra, idempotent pass.
- [HISTORICAL] Older notes say any byte change re-runs the installer, and the docblock above `BCC_TRUST_SCHEMA_VERSION` in `bcc-trust.php` still says `md5_file()`. Both predate the change.

**Data migrations and backfills.** These run through `bcc_trust_run_pending_migrations`, independent of the schema hash.

**bcc-search** ensures its FULLTEXT index and its search-terms table itself, and self-heals hourly. **bcc-core** declares no tables.

### Checklist for a schema change

1. Edit the schema file, and add or extend a repository with `COLUMNS` and bounded queries.
2. Add or update the table's row in [`docs/database-schema.md`](../database-schema.md) in the umbrella repo, in the format `| wp_bcc_… | ~rows | Purpose | Owning code | Status |`. CI turns red without it. Don't put real production row counts in the `~rows` column of a public doc; use an order of magnitude or leave it blank.
3. **Destructive change** (drop, column removal, data rewrite):
   - use a guarded one-shot migration that fails closed;
   - treat "could not inspect" as different from "absent";
   - prove the postcondition;
   - rehearse on a restored copy;
   - write a runbook if it needs one (precedent: `bcc-trust:docs/scanner-schema-drop-runbook.md`).

   Write-based checks run only locally or on staging.
4. Note in the PR that the deploy will run `dbDelta`, and whether the object cache needs a flush.
5. **Production data operations** (backfills, cleanups, dry-run then apply) need the project owner's explicit approval, and the ops role runs them. See [HANDOFF.md](HANDOFF.md).

## Tooling

- **Local database:** the Local by Flywheel site. The `mysql` MCP server is configured **read-only**. Never widen it to write access casually.
- **Integration tests** use a throwaway `bcc_test` database through a mysqli `$wpdb` shim, not a WordPress boot (`bcc-trust:tests/Integration/bootstrap.php`). They skip locally without a DB, and CI fails on skips.
