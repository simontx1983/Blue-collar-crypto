# Open findings handoff — six items, none fixed

**Status: NOTHING HERE HAS BEEN FIXED.** Each item was observed while doing
other work and is recorded here so it can be scheduled on its own terms.
Deliberately **not** fixed as a side effect, because each has a blast radius
that deserves its own review.

All evidence below is a **fresh measurement** taken 2026-10-09 unless it says
otherwise. Where something is inferred rather than measured, it says so.

---

## 1. Stale `.git` directories in the deployed plugin trees

**Tracking:** bcc-trust issue **#286**

### Observed

Measured on staging, read-only:

| tree | `.git` | files | size | `HEAD` |
|---|---|---|---|---|
| `stage/wp-content/plugins/bcc-trust` | **present** | 603 | 6.4 MB | `ref: refs/heads/main` |
| `stage/wp-content/plugins/bcc-core` | **present** | 519 | 5.2 MB | `ref: refs/heads/main` |
| `stage/wp-content/plugins/bcc-search` | absent | — | — | — |

**Cause.** The deploy rsync uses `--exclude='.git' --exclude='.github'`. Those
patterns are **unanchored**, so they match at any depth — and `--delete`
without `--delete-excluded` **never removes an excluded path that already
exists at the destination**. So once a `.git` directory got there, every
subsequent deploy both refuses to update it and refuses to remove it.

### Impact — lower than it looks, and worth stating accurately

- ⚠ **Not web-reachable.** `HEAD` and `config` both return **HTTP 403** over
  https. This is **not** a source-disclosure hole, and should not be reported
  as one.
- It is ~11.6 MB of dead weight, and it makes the deployed tree *look* like a
  git checkout that could be inspected or updated. It cannot: the objects are
  frozen at whenever they first landed, so `git log` there would describe a
  state that has nothing to do with the deployed files.
- The real risk is **a person trusting it.** Someone debugging on the host
  could run `git status` or `git describe` in that directory and get a
  confident, wrong answer about what is deployed. The content-and-path
  manifest is the only trustworthy answer.
- It is also why the deploy reports ~600 skipped files, which makes a genuine
  skip harder to notice.

### Proposed next action

Two separate changes, in this order, and **neither is authorized here**:

1. Stop it recurring: add `--delete-excluded`, or switch the exclusions to
   anchored forms (`/.git`, `/.github`) so they only apply at the tree root.
   ⚠ `--delete-excluded` is the blunter instrument — it would remove *any*
   excluded destination path, so its full exclusion list needs reviewing before
   it is turned on.
2. Remove what is already there, as a one-off, deliberate deletion on the host
   — not as a deploy side effect.

⛔ Explicitly out of scope tonight: no deployed files were deleted and no rsync
exclusion was changed.

---

## 2. `bcc_trust_flags` in the expected-tables inventory

**Tracking:** recorded 2026-10-06; no issue filed yet

### Observed

`includes/database/tables.php` still lists `'bcc_trust_flags'` (line ~338) in
the set that `bcc_trust_verify_all_tables()` checks. The table was **retired
2026-07-08** by `drop-trust-flags-table.php`, so the check cannot pass, and
`bcc_trust_install_database()` logs

```
[bcc-trust] BCC Trust ERROR: Missing tables:  bcc_trust_flags
```

on **every schema pass**. Previously counted 32 occurrences.

### Impact

- A permanent, self-inflicted `ERROR` in the application log that is not a
  real fault. Its cost is that it **trains the reader to ignore `ERROR` lines**
  from the schema pass — which is exactly where a genuine missing table would
  appear.
- ⚠ It does **not** block schema stamping. Verified during S8/S9a: the stamp
  is written regardless, so this has never prevented a deploy.
- ⚠ **This is not an S9b problem.** Checked explicitly: the three tables S9b
  drops are **not** in `tables.php`, so S9b adds no new line of this kind.

### Proposed next action

Remove the `'bcc_trust_flags'` entry from the inventory. One line. ⚠ Do **not**
recreate the table, and do not treat the inventory edit as licence to touch any
other row — the inventory is the thing that would hide a genuine absence.

⛔ Not done here. Nothing was authorized, and it is unrelated to the scanner
retirement.

---

## 3. The role-boost migration logs a WARNING claiming work it did not do

**Tracking:** newly observed 2026-10-09; no issue filed

### Observed

`includes/database/drop-onchain-role-boost-columns.php` emits

```php
Logger::warning('[bcc-trust] dropped retired onchain role-boost columns', ['table' => $table]);
```

**outside** the `if ((int) $hasColumn > 0)` block that does the dropping. So it
fires whether or not anything was dropped.

Measured on staging:

- `bcc_trust_onchain_role_boost_cols_dropped` = `1791531722` — **already set**
- `trust_boost` / `fraud_reduction` columns on `wp_bcc_onchain_signals` —
  **0 present**, so the loop is a guaranteed no-op

And the fast path cannot suppress it: the guard is
`get_option(...) && get_transient('bcc_trust_onchain_role_boost_drop_recheck')`,
and the transient is set for `HOUR_IN_SECONDS`. So **once an hour, forever**,
the body re-runs two `information_schema` probes, drops nothing, and logs a
warning asserting that it dropped something.

### Impact

- A recurring WARNING that is **affirmatively false** — worse than noise,
  because a reader auditing the log for schema activity will conclude a drop
  happened at that timestamp.
- Two needless `information_schema` queries per hour. Negligible on its own.
- Same corrosive effect as item 2: it is a log line that must be learned and
  ignored.

### Proposed next action

Move the `Logger::warning` inside the branch, and have it report **what** it
dropped rather than asserting a drop unconditionally — e.g. accumulate the
dropped column names and log only when the list is non-empty. Consider
downgrading to `info`: a successful, expected, idempotent cleanup is not a
warning.

⛔ Not fixed here. It is a one-line move with a behavioural claim attached, and
it belongs in a change that can be reviewed on its own.

---

## 4. `scripts/tests/pr77-mutation-controls.sh` is broken

**Tracking:** newly observed; no issue filed

### Observed

The script's `cw_last_error` control mutates a write that no longer exists.
Measured at bcc-trust `main` `c1ecd1d9`:

```
occurrences of "cw_last_error" in ChainCheckpointRepository.php : 0
```

**S9a** removed the `cw_*` writers, so the needle the control replaces cannot
match and the mutation never applies. The control is therefore `broken`, not
`killed` — it proves nothing.

⚠ **Not caused by S9b**, and confirmed by checking `main` rather than the
branch. Also:

- The script is **not CI-wired.** Only `endpoint-redaction-mutation-controls.sh`
  and `alchemy-credential-mutation-controls.sh` run in `ci.yml`, and both are
  green (`killed 19/0/0` and `killed 20/0/0`). So nothing is red, and nothing
  is silently passing in CI.
- `pr76-mutation-controls.sh` (line 169) and `pr72-mutation-controls.sh`
  (line 113) also reference `cw_last_error` / `cw_backfill_completed_at` and
  should be checked for the same breakage.

### Impact

- These are **historical per-PR mutation scripts**, kept as a record of what
  was proven at the time. A broken one is a misleading record, not a live gap.
- The real risk is someone running one during a later investigation, seeing
  `broken`, and not knowing whether that is expected.

### Proposed next action

Decide the policy rather than patching: either (a) mark the per-PR scripts as
historical and non-runnable once their subject is deleted, with a header saying
so, or (b) delete them when the code they mutate goes, treating the merged PR
as the record. ⚠ Do **not** silently repoint the needle at a different line to
make it green again — that would convert a record into a fiction.

⛔ Not fixed here. Out of S9b's scope.

---

## 5. Two dirty vendor worktrees

**Tracking:** recorded 2026-10-01

### Observed

| worktree | branch | dirty paths | of which `vendor/` |
|---|---|---|---|
| `.claude/worktrees/bcc-trust-heliuspost` | `fix/helius-admin-post-enforcement` | **699** | 675 |
| `.claude/worktrees/bcc-trust-s1` | `fix/cosmos-probe-501-fidelity` | **699** | 675 |

Both are **unchanged by tonight's work** — verified before and after.

### Impact

- `vendor/` is **tracked** in this repo, so a `git add -A` in either worktree
  would commit 675 modified vendor paths. That is the live hazard: a commit
  made from one of these by habit would carry a dependency change nobody
  reviewed.
- They are also unusable as a base for a new branch without first deciding
  what the vendor diff is.

### Proposed next action

Review the vendor diff **before** doing anything else with them — it needs to
be established whether those 675 paths are a legitimate `composer update` or
line-ending churn, because the answer changes the remedy. Then either commit it
deliberately as a dependency bump, or restore it.

⛔ **Not discarded.** No vendor change was reverted, and no branch was prepared
from either worktree. Everything tonight used a fresh worktree
(`bcc-trust-s9b`) and pushed with `git push origin HEAD:refs/heads/<branch>`.

---

## 6. The memory index has no recovery path, and it is getting large

**Tracking:** recorded 2026-10-06 after an incident

### Observed

- `MEMORY.md` was **truncated to 0 bytes** on 2026-10-06 by a mid-write
  `UnicodeEncodeError` in a Python heredoc — a lone surrogate (a literal `📌`
  instead of an escape) aborted the write after the file had been opened for
  truncation. It was recovered from a copy that happened to exist in a
  scratchpad: `MEMORY.md.restored-backup-20261006T054735Z`.
- **`~/.claude` is not a git repository.** Confirmed again tonight. There is no
  history, so the recovery was luck rather than process.
- Current size: `MEMORY.md` is **20,221 bytes across 46 lines**, indexing
  **237 topic files** totalling **2.0 MB**.

### Impact

- **A single bad write destroys the index**, and with it the pointers to 237
  files that then have no table of contents. The files survive; the map does
  not.
- The 46 lines are extremely dense — many carry several links plus multiple
  ⚠-prefixed warnings. That is efficient to load and **hostile to edit
  safely**: the longest lines are the ones a partial write is most likely to
  corrupt.

### Proposed next action

Three independent things, smallest first:

1. **Never truncate in place.** Write to a temp file and `mv`, or use an editor
   tool that writes atomically. (Already adopted as practice; worth making it a
   rule in the memory instructions rather than a habit.)
2. **Give the store a history** — `git init` in `~/.claude`, or a scheduled
   copy. Either turns a corrupting write from a loss into an inconvenience.
3. **Consider splitting the index** once it stops fitting comfortably — e.g.
   per-area indexes with a short root index pointing at them — so no single
   file is both the only map and the most-edited file.

⛔ **`MEMORY.md` and its topic files were not edited tonight.** Confirmed: last
modified 2026-10-06, untouched by this session.

---

## Summary

| # | Item | Severity | Blocks S9b? | Fixed? |
|---|---|---|---|---|
| 1 | Stale deployed `.git` (#286) | low — 403, not disclosure | no | no |
| 2 | `bcc_trust_flags` inventory warning | low, corrosive | no | no |
| 3 | Role-boost warning claims false work | low, **actively misleading** | no | no |
| 4 | `pr77-mutation-controls.sh` broken | informational | no | no |
| 5 | Dirty vendor worktrees | ⚠ medium — a careless commit ships unreviewed deps | no | no |
| 6 | Memory index has no recovery path | ⚠ medium — one bad write loses the map | no | no |

None of the six blocks S9b. Items 3 and 5 are the two worth scheduling
soonest: 3 because a false log line is worse than a noisy one, and 5 because
the failure mode is a commit nobody meant to make.
