# Validator Announcements — Backend Readiness & Frontend Integration Handoff

As of 2026-10-15. Durable copy of the handoff; supersedes the private artifact
version, which contained three incorrect claims corrected in §4.

| | |
|---|---|
| bcc-trust `main` | `ebb5aab8e04812a7756f271442d88e87296b804c` |
| umbrella `main` | `3b174e41360a65759717576215d549df448a9b20` |
| Feature switch on staging | **absent** (not `0`) — every route answers `404 bcc_not_found` |
| Production | untouched; last `workflow_dispatch` deploy 2026-10-13 (pre-dates this work) |

---

## 1. Merge inventory

### The six PRs landed in the authorized sequence

| # | Repo | PR | Title | Branch | Head | Merge commit | Merged (UTC) |
|---|---|---|---|---|---|---|---|
| 1 | umbrella | #186 | docs(§4.32): resolve two contract decisions — editable summary, bare numeric path ids | `docs/announcement-summary-edit-and-comment-ids` | `59b6702e618a5fda6c9298a7d92ac2fcfca074fd` | `a9c54099ecf296051cf66228e4dbeada671955a2` | 2026-10-15T05:19:00Z |
| 2 | bcc-trust | #333 | A4: project a published announcement into the feed (§4.32.8) | `feat/a4-announcement-feed-projection` | `799f6fb42721f6fca07b927e51933575ed6008a7` | `52db854b023898f0a5e18bfa6e743a74dba2e72a` | 2026-10-15T05:19:51Z |
| 3 | bcc-trust | #334 | A5a: announcement comment policy + storage, and the tombstone decision (§4.32.7) | `feat/a5-announcement-comments` | `b7d636f62d2642d27f06cad359437fb9dcf5aac1` | `5a0303839ec72c6bba39cfa71d39a613334ab820` | 2026-10-15T05:29:16Z |
| 4 | bcc-trust | #335 | A5b prereq: accept `summary` and `comments_enabled` on the write routes | `feat/a5b-prereq-summary-comments-enabled` | `8826f8251ad1aa0e7eaefdbc0c4e3d388d8a523b` | `b323c573dea042562fe5eadd7ca96616e3455a2c` | 2026-10-15T05:39:37Z |
| 5 | bcc-trust | #336 | A5b: the five §4.32.4 announcement comment routes, and the standing gap they exposed | `feat/a5b-announcement-comment-routes` | `e76fc66e49aaa455026e1b52f48193c79c7bb142` | `c83fe4a6e96e597c7d9ada51c0c5059189793f4d` | 2026-10-15T05:50:21Z |
| 6 | umbrella | #187 | docs(announcements): canonical sections for the five §4.32.4 comment endpoints | `docs/announcement-comment-endpoint-sections` | `c63125e17f124658907386157b69456fec2e7c92` | `3b174e41360a65759717576215d549df448a9b20` | 2026-10-15T05:51:50Z |

Plus, authorized separately as a narrow fix:

| Repo | PR | Title | Head | Merge commit |
|---|---|---|---|---|
| bcc-trust | #337 | fix(announcements): §4.32.10 — the two `/admin/` comment routes leaked a 401 while the feature was off | `d772681d08454b8e1c6da71672bc271fe8628e39` | `ebb5aab8e04812a7756f271442d88e87296b804c` |

Every merge tree matched a tree predicted before the merge: `dc1af6fc` → `01628677` →
`a2497db9` → `fb810094` → `3823e407`. Each post-merge `main` CI run reproduced its PR's
own run exactly.

### Two distinct authorization bases — do not conflate them

- **The write foundation (A1–A3) was pre-authorized** by its own instruction ("require
  final CI, then merge #331/#332 normally and complete the coordinated #185 parity
  window"). It landed earlier: **#331** `871eacf6215c571a896ba01cde38fe71a8dbcbdd`
  (retained the combined-tree interaction probe) and **#332**
  `808e9d07e66910ee2bdeb0f184d7bb91674259c0` (A3 write layer). **These are not among the
  six above.**
- **A4 and A5 (#333–#336) were explicitly requested as _unmerged_ PRs** across two earlier
  instructions, and were authorized to merge only by the later instruction's named
  sequence. Umbrella #186/#187 rode that same authorization as the contract halves.

### Still open, deliberately

| Repo | PR | State | Why |
|---|---|---|---|
| bcc-trust | #338 | OPEN | inverts a stale probe pin; outside the authorized sequence |
| bcc-trust | #330 | CLOSED unmerged | verification-only candidate; its probe was preserved by #331 |
| bcc-frontend | #167, #168, #188 | OPEN | the frontend stack — see §4 |

### The parity window

bcc-trust and the umbrella contract are one change split across two repositories, and
either half alone is red. Window open **05:50:17Z** (on #336's merge) → closed
**05:51:57Z** (on #187's) = **99 seconds**. It left a genuine recorded failure: umbrella
run `38688969559` at 05:50:28Z, `FAIL: 5 in-scope route(s) registered in PHP,
undocumented in §4, and NOT allowlisted`, naming all five routes, contract parsed 197.
Post-merge umbrella `main` run `38689099013`: parsed **202**, routes 214 / in-scope 254,
both directions PASS. No route was ever added to `EXEMPT_INTERNAL`.

---

## 2. Staging verification (measured, not inferred)

Four automatic staging deploys fired on merge (`workflow_run` after CI success on `main`),
all succeeded. None was dispatched by hand; no production deploy ran.

### Content-and-path identity

Built from the merge commit's **blob bytes** (the repo has no `.gitattributes`: blobs and
deployed files are LF, a local Windows checkout is CRLF, so a working-tree manifest gives a
false mismatch), with the deploy's own rsync excludes applied at any depth.

| | |
|---|---|
| merge tree entries | 1980, all mode `100644`, **0 symlinks, 0 gitlinks** |
| manifest paths after exclusion | **1971** |
| expected digest (from blobs) | `7597f34cf2cd0847c72e82d2f36525fc9402830e375696c287cf8d2a0fcb7e3d` |
| deployed digest (on staging) | `7597f34cf2cd0847c72e82d2f36525fc9402830e375696c287cf8d2a0fcb7e3d` |
| verdict | **IDENTICAL** — 0 content mismatches, 0 missing, 0 extra |

⚠ A first measurement reported 1970 paths and a mismatched digest. That was a
measurement error: the remote `find` carried `-path './.git*'`, which also pruned
`.gitignore` (deployed, 1417 bytes). The corrected run is identical. The 606 excluded
files are 603 inside the stale deployed `.git` (pre-existing, filed as issue #286) and 3
nested `vendor/php-stubs/wordpress-stubs/.github/workflows/*.yml` orphans that rsync
cannot delete because the exclude matches at any depth.

### Schema stamp

`BCC_TRUST_SCHEMA_VERSION` is `substr(md5(Σ "basename:md5_file(...)\n"), 0, 10)` over
path-sorted `includes/database/schema-*.php` plus two self-installers
(`DisputeRepository.php`, `SignalRepository.php`).

| Ref | Computed from blobs | Inputs |
|---|---|---|
| before the batch (`808e9d07`) | `7fbb591807` | 52 |
| after #336 (`c83fe4a6`) | `7fbb591807` | 52 |
| final main (`ebb5aab8`) | `7fbb591807` | 52 |
| **stored on staging** | **`7fbb591807`** | — |

Unchanged, so **no dbDelta pass is triggered** — which is the load-bearing fact, because
the batch touched zero `schema-*.php` and neither self-installer. `bcc_trust_schema_version_cache`
correctly absent.

### Retained data — byte-identical to the pre-deploy baseline

Baseline taken 2026-10-15T13:43:46Z, before #337's deploy.

| Counter | Baseline | After deploy |
|---|---|---|
| `bcc_` tables / total rows | 62 / 5259 | **62 / 5259** |
| `wp_posts` | 713 | **713** |
| `wp_users` | 24 | **24** |
| `wp_comments` | 1 | **1** |
| announcement posts | 0 | **0** |
| announcement comments | 0 | **0** |
| module-205 activity rows | 0 | **0** |
| switch option rows | 0 | **0** |

### Application log — the actual comparison

The BCC application log is not the PHP `error_log`; it resolves to
`dirname(ABSPATH) . '/bcc-logs/bcc-<secret>.log'`, outside the webroot. Counted with
whole-prefix matches (`^\[...\] \[LEVEL\]`), not an unanchored grep.

| | Baseline 13:43:46Z | After deploy |
|---|---|---|
| lines | 7663 | 7665 |
| `[ERROR]` | 954 | **954** |
| `[WARNING]` | 4267 | **4267** |
| `[INFO]` | 2438 | **2438** |
| entries at/after the baseline | — | **0 from the deploy** |
| fatal / parse / Uncaught (whole file) | — | **0** |
| `announcement` mentions (whole file) | — | **0** |
| mentions of the new classes | — | **0** |

The only two new lines in the window are `[AUDIT] jwt_verify_failed {"error":"jwt_malformed"}`
at 13:55:41–42, caused by a verification probe that deliberately sent an invalid bearer.
That probe violated a standing rule against sending even a fake `Authorization` to shared
staging; it is recorded here rather than omitted. ERROR/WARNING/INFO are unaffected.

Health after deploy: `/` 200, `/wp-json/` 200, `/wp-json/bcc/v1` 200.

---

## 3. Scheduled publication — measured behaviour on final main

Arm C is the publication-time authority guard. It runs at priority 1 on the transition,
**before** core's publisher at 10, which is what gives it a true veto —
`wp_publish_post()` writes `post_status` with a direct `$wpdb->update`, so no filter can
stop it after the fact.

When authority no longer holds at due time, `AnnouncementPublicationGuard::block()` does,
in this order (the order is load-bearing):

1. deletes `_bcc_announcement_publish_authorized_at` / `_user_id` — visibility predicate 3
2. forces the row out of `publish` via the repository's `forceStatus()`
3. stamps `_bcc_announcement_blocked_at` + `_bcc_announcement_blocked_reason`
4. clears the pending `publish_future_post` cron event
5. logs a warning

The marker is removed **first**, so even if the status write fails the row cannot be
public — it fails closed in the right direction.

### `announcement-cron-publication-safety.php` — 71 pass / 0 fail, 100 output lines

| Case | Covers |
|---|---|
| §0 | the hook chain is real: guard at 1, core at 10 |
| §1 | **control — authority INTACT: the real cron event DOES publish** |
| §2 | **REVOKED claim at due time — cron must NOT publish** |
| §3 | public surfaces exclude it — list, detail, rotator |
| §4 | feed exclusion |
| §5 | **AMBIGUOUS claim at due time — same refusal** |
| §6 | **ABSENT claim — the claim row is gone entirely** |
| §7 | generic WP transitions, not just our routes |
| §8 | **the permitted recovery path (demotion → republication)** |
| §9 | recovery is not a bypass — a stranger cannot clear a block |
| §10 | **feature off must not OPEN a publication path** |
| §11 | failure during publication — marker first, so a failed status write still fails closed |

⚠ The reason is **collapsed on purpose**: revoked, removed and ambiguous all report
`authority_lost`. Which one it was is private vocabulary.

### `announcement-publication-interval.php` — 71 pass / 0 fail, 97 output lines

Cases A–I, including **E: an OVERDUE scheduled row** (future status, past date, marker),
F: the same shape via the filterable `wp_update_post()` path, G: A3's own
`create(schedule)` leaves **no** marker, H: the cron path writes the marker **at
promotion**, and **I: leaving `publish` RETIRES the marker**.

### Blocked owner filtering

`AnnouncementBlockedFilterIntegrationTest` — **16 tests / 117 assertions, passing on
MariaDB 11.8.9 and MySQL 8.0**:

- blocked and ordinary drafts are separated **despite sharing a `post_status`**
- asking for `blocked` never returns ordinary drafts
- an empty blocked marker is not blocked
- a scheduled row is neither draft nor blocked
- asking for all three returns the union exactly once
- a published row is never returned by the owner list
- an unknown status is dropped, not honoured

The repository uses **per-status predicates, not an `IN` list**, and that is forced: a
blocked row *is* a `draft` carrying arm C's marker, so `post_status IN (...)` could not
tell them apart and `?status=blocked` would have returned every ordinary draft.

### Pin concurrency

`announcement-pin-concurrency.php` is a **multi-process** probe (`BCC_PIN_ROLE=seed|pin|check`);
run without a role it prints usage and exits, which looks like an empty green run — count
output lines, not just failures. Driven properly with 6 concurrent `pin` children on a
shared start barrier: 4 × `200`, 2 × `409`, and **exactly one pin survives**, with an
explicit anti-vacuity assertion that at least one child really pinned.

### #332's own suites on final main

`AnnouncementPublicationGuardTest` + `AnnouncementTransitionTest` +
`AnnouncementWriteGuaranteesTest` = **83 tests / 368 assertions**, green.

### Compared with the accepted checkpoint

| | at #332's merge (`808e9d07`) | final main (`ebb5aab8`) |
|---|---|---|
| unit | OK (4488, 31444) | **OK (4671, 32166)** |
| integration MySQL | OK (788, 8108) | **OK (823, 8392)** |
| integration MariaDB | OK (404, 4132) | **OK (439, 4416)** |
| classmap first-party | 552 | **557** |

Monotonic growth in every dimension; nothing shrank.

---

## 4. Corrections to the earlier handoff

Three claims in the artifact version were **wrong**. They were carried forward from
A2.4-era planning notes written *before* #332 landed, and not re-checked against #332's
diff. No code was lost — the error was documentation drift.

| Earlier claim | Reality on final main |
|---|---|
| "`blocked` status — **no writer exists**" | **False.** `AnnouncementPublicationGuard::block()` writes `_bcc_announcement_blocked_at` and `_bcc_announcement_blocked_reason`. |
| "the owner-list enum **refuses** `blocked`" | **False.** The enum is `['draft','scheduled','blocked']` with an explicit validator. The code's own comment records the history: `blocked` *was* refused while arm C did not exist, because nothing then distinguished a blocked row from an ordinary draft. |
| "**Reconciler arm C: not built**" | **False.** Arm C is implemented inside the publication guard; `block()`'s step 3 comment reads "Record that arm C did this, which is what makes the state distinguishable from an ordinary draft." |

`published` remains correctly **absent** from the owner enum — a published announcement is
served by the public list.

### Loss audit: #332 reviewed head → final main

Every file #332 touched, compared blob-for-blob between its reviewed head
`cd1f683a5321f21b28975b133c53c3a321d12d66` and `ebb5aab8`:

- **Nothing is gone.** 0 files missing from main.
- **Byte-identical on main (9):** `AnnouncementTransition.php`,
  `AnnouncementRepository.php`, `AnnouncementViewService.php`, `bcc-trust.php`,
  `announcement-pin-concurrency.php`, `AnnouncementBlockedFilterIntegrationTest`,
  `AnnouncementPinnedIdsIntegrationTest`, `AnnouncementPublicationGuardTest`,
  `AnnouncementTransitionTest`, `AnnouncementWriteGuaranteesTest`,
  `AnnouncementsEndpointTest` — i.e. **every one of #332's test files is unchanged**.
- **Changed, additive:** `AnnouncementPublicationGuard.php` **+18/−0** (arm C untouched);
  `Plugin.php` **+38/−0**.
- **Changed with removals, all accounted:** `AnnouncementWriteService.php` +177/−7 —
  removals are the `MAX_TITLE_LEN`/`MAX_BODY_LEN` declarations (re-declared alongside
  `MAX_SUMMARY_LEN = 300`, and still enforced on both create and edit),
  the superseded "nothing to change" message, and the 3-arg `fail()` replaced by a 4-arg
  one carrying `reason_code`. `AnnouncementWriteEndpoint.php` +82/−10 — the arg
  declarations and `$input` allowlist, replaced by a helper that also forwards `summary`
  and `comments_enabled`. `AnnouncementsEndpoint.php` +24/−1 — the single removed line is
  exactly the partial standing array that A5b replaced.

### Frontend status — implemented but unreleased, not absent

The earlier handoff said frontend suppression was "by design absent". That is wrong.

| PR | Title | Base | Head | Size | State |
|---|---|---|---|---|---|
| bcc-frontend #167 | `[HANDOFF ONLY]` F1 validator announcement surfaces — capability-gated | `main` | `71005e6c7c75df180171baaeeccc1410aa81a2e3` | 30 files, +5249/−12 | OPEN |
| bcc-frontend #168 | `[HANDOFF ONLY]` F2 announcement bar — stacked on F1 | `feat/validator-announcements-f1` | `683505d44d6dc865ba4ad41531e86e44f0120742` | 7 files, +1364/−4 | OPEN |
| bcc-frontend #188 | make the billboard legible and say what it is showing | `feat/validator-announcements-f2` | `4b59fab26b1a81579b0827f6b133aad7d0d187be` | 4 files, +446/−14 | OPEN |

A stack: `main` ← #167 ← #168 ← #188, ~41 files and +7059 lines. Components present on
F1 include `AnnouncementsPanel`, `AnnouncementListItem`, `AnnouncementDetailView`,
`AnnouncementComposer`, `AnnouncementComments`, `AnnouncementOwnerActions`, with
capability-gate, comment-capability-scope, a11y/token and composer test suites.

The suppression behaviour lives in `src/app/announcement-route-fails-closed.test.tsx`,
whose cases are: *404s instead of rendering a blank or half-built page* · *never requests
an announcement the backend cannot serve* · *404s when the validator itself is missing* ·
*fetches the announcement once the server advertises the feature* · *404s — never 403 —
when the announcement is not visible to this viewer* · *also 404s a 403, so a draft*.

So the correct status is **implemented, reviewed, unmerged and unreleased** — gated on the
backend advertising the feature, and failing closed. It is not absent, and nothing in it
is enabled today.

⚠ Observation for whoever reviews #167, not verified further here:
`AnnouncementsPanel.tsx:103` uses a blanket `active.isError ?` branch. This repository has
a recorded defect class where a blanket `isError` erases an already-loaded list; worth
checking against the three-way `isLoadingError`/`isRefetchError`/`isFetchNextPageError`
split that is the house pattern.

---

## 5. The 14 routes

All under `/wp-json/bcc/v1/`. Path ids are **bare numeric** (`\d+`); the `ann_` / `acmt_`
prefixes in payloads are view-model values — strip them before building a URL.

| Method | Path | Auth | Capabilities | Cache |
|---|---|---|---|---|
| GET | `validators/:pageId/announcements` | optional | no | `no-store`, no `Vary` |
| GET | `validators/:pageId/announcements/rotator` | optional | no | `no-store`, no `Vary` |
| GET | `validators/:pageId/announcements/:id` | optional | yes | `private, no-store` + `Vary` |
| GET | `me/validators/:pageId/announcements` | operator | yes | `private, no-store` + `Vary` |
| POST | `validators/:pageId/announcements` | operator | — | — |
| PATCH | `validators/:pageId/announcements/:id` | operator | — | — |
| POST | `validators/:pageId/announcements/:id/publish` | operator | — | — |
| POST | `validators/:pageId/announcements/:id/archive` | operator | — | — |
| POST | `validators/:pageId/announcements/:id/pin` | operator | — | — |
| GET | `announcements/:id/comments` | optional | `can_comment` on the envelope | `private, no-store` + `Vary` |
| POST | `announcements/:id/comments` | member in good standing | — | `private, no-store` + `Vary` |
| DELETE | `announcements/:id/comments/:commentId` | author **or** operator | — | `private, no-store` + `Vary` |
| GET | `admin/announcement-comments/:commentId` | administrator | — | `private, no-store` + `Vary` |
| POST | `admin/announcement-comments/:commentId/restore` | administrator | — | `private, no-store` + `Vary` |

`Vary` is always `Authorization, Cookie`. The two public lists send **no** `Vary` because
nothing about them varies by viewer. Comment routes take `:id` = the **announcement**.

## 6. Envelopes, errors, rate limits

Success: `{ data, _meta }`; lists put rows under `data.items` with
`data.pagination = { next_cursor, has_more }`. An empty page is `items: []`,
`next_cursor: null`, `has_more: false` — never a bare `[]`.

Failure: `{ error: { code, message, status }, _meta }`, with `error.data.reason_code`
where the refusal has one.

| Code | HTTP | When |
|---|---|---|
| `bcc_not_found` | 404 | feature off, or the row is not publicly readable |
| `bcc_forbidden` | 403 | carries a `reason_code` |
| `bcc_invalid_request` | 400 | `summary_required`, `replies_not_supported`, `publish_at_not_future`, `backdating_not_allowed` |
| `bcc_conflict` | 409 | already removed / not removed / archived |
| `bcc_rate_limited` | 429 | over the window cap |
| `bcc_unavailable` | 503 | repository fault — **never** a 200 with an empty list |

⚠ Render a retry on **503**, not "no announcements": an errored read returns 503 precisely
because an empty bar looks normal and the failure would be invisible.

⚠ Ownership denial is always `reason_code: not_claimer`. `claim_ambiguous` and
`claim_absent` are internal audit reasons, removed from the wire — do not branch on them.

Rate limits, applied **before** any ownership gate: list 20/60s per viewer · writes
10/60s per user · comment create 20/300s per user. So a 429 can arrive on a route the
viewer was never entitled to.

## 7. Ordering, cursors, paging

`limit` is 1–50, default 20, and **out of range is a 400, not a clamp**.

| Surface | Order | Cursor tag |
|---|---|---|
| active list | pinned first (≤1), then newest `published_at` | `ann_active` |
| archived list | newest `archived_at` | `ann_archived` |
| owner list | newest effective date | `ann_owner` |
| rotator | pinned first, then newest — capped at 5, **mints no cursor** | none |
| comment thread | **oldest first**, then `comment_ID` | `ann_comments` |

⚠ The comment thread is the **only ascending surface**. A paging helper shared across
surfaces will break there.

Cursors are opaque and **surface-tagged**: one minted by one surface is refused by
another. Pass back exactly what `next_cursor` gave you. The rotator takes no paging
parameters at all. The list takes `state` = `active` (default) | `archived`; the owner list
takes `status` = `draft` | `scheduled` | `blocked`.

## 8. Capabilities — three scopes

Every capability is `{ allowed, reason_code, unlock_hint }`. **An allowed entry still
carries `reason_code: null`** — the key is always present, so test `allowed`.

1. **Public lists** carry **no `capabilities`, no `status`, no `body`** — for any viewer,
   operator included. Enforced by two distinct producers, not a flag.
2. **Detail** carries row capabilities + `status` + `body`.
3. **Owner list** carries row capabilities + `status`.

An operator reading the public list gets **byte-identical** output to an anonymous reader.

Keys: `can_create`, `can_edit`, `can_pin`, `can_archive`, `can_comment`,
`can_manage_comments`, `can_report`, `can_inspect_drafts`, `can_inspect_removed`,
`can_restore_comment`.

`can_comment`'s ladder — the **first** failing rung is the `reason_code`:
`feature_disabled` → `announcement_unavailable` (not published) → `announcement_archived`
→ `comments_disabled` → `auth_required` → `suspended` → `fraud_locked` → good standing,
**also reported as `suspended`**.

⚠⚠ Rung 8 shares rung 6's code deliberately: §4.32.11's reason inventory is closed and the
composer closes on an unrecognised reason, so a new code would mean a disabled control
with no copy.

⚠ Good standing means reputation tier ≥ `neutral`, and **no score row defaults to
`neutral`** — a brand-new member can comment. Only explicit `risky`/`caution` denies.

## 9. Write verbs

States: `draft`, `scheduled`, `published`, `blocked`. `status` appears on detail and the
owner list only.

**Create** takes `title`, `status`, `summary`, `body`, `comments_enabled`, `publish_at`.
Bounds: title ≤ 200, body ≤ 20000, summary 1–300 — enforced on create **and** edit.

⚠ **`summary` is required and first-class**: stored separately and **never inferred from
the body**. Omit it → `400` + `reason_code: summary_required`. Do not auto-fill it
client-side from the first line of the body.

**Edit** accepts `title`, `summary`, `body`, `comments_enabled`. Allowed while published,
and it does **not** bump the feed.

**Publish/schedule** is one route: a future `publish_at` schedules, omitting it publishes
now. Refusals: `publish_at_not_future`, `backdating_not_allowed`. Promotion writes the
authorization marker; the scheduler promotes a `scheduled` row at its time. Nothing the
frontend does promotes a row.

**Archive is one-way.** Afterwards edits, pins and new comments all refuse with
`announcement_archived`, which is **non-retryable** — do not offer a retry. The row keeps
its permalink, stays publicly readable, and withdraws from the feed.

**Pin** allows **at most one per page**, on a published non-archived row. Verified under
6-way concurrency: exactly one survives. Do not manage exclusivity client-side.

Every ownership denial on every write is `403` + `not_claimer`.

## 10. Comments, tombstones, counts

Flat, plain text, 1–2000 chars after normalisation, oldest first.

⚠ Sending `parent_id` **at all** is `400 replies_not_supported`, including `0` — presence
is tested, not truthiness. There is no reply UI to build.

Visible row: `id`, `is_removed: false`, `body`, `author { id, handle, display_name,
avatar_url }`, `posted_at`, `permissions.can_remove`.

Tombstone — **exactly four keys, nothing else**: `id`, `is_removed: true`, `removed_at`,
`label`. No body, no author, no `posted_at`, no `permissions`. `label` is one of exactly
two strings (`Comment removed by author` | `Comment removed by validator`); map the closed
set client-side if you need localisation, and fall back rather than printing an unexpected
value.

⚠ Tombstones **stay in position and count toward `comment_count`**, so the rendered count
matches what the reader sees. Do not filter them out or subtract them.

Removal: the comment author or the resolved operator. Every denial is `403` +
`not_comment_author`, whoever asks — so the response cannot reveal who did write it.

⚠⚠ **An administrator cannot remove.** Administrator power is **recovery, not
moderation**; the two `/admin/` routes read the private original and restore it, and
**authors cannot restore**. Already-removed is `409`, not an idempotent `200`.

`comment_count` and `comments_enabled` appear on **every** row shape, including public
lists.

## 11. Feed, reporting, no engagement

Module **205**, kind `announcement`, cold-start verb `POSTED an announcement`. **Exactly
one** activity per published announcement; editing does not bump; archival withdraws;
projection is idempotent on the announcement id.

⚠ The feed projection deliberately carries **no `comment_count`** while the announcement
view-model carries a real one. Both are correct — do not "fix" either to match.

⚠ While the switch is off the feed hydrator **suppresses** existing module-205 activities,
so no card can link to a 404. Re-enabling restores them; suppression is a read-time
filter, not a delete.

**Reporting.** Reportable through the existing moderation queue; `can_report` gates the
control. Duplicates are idempotent at the storage layer (UNIQUE constraint →
`status: existing`), so a double-submit needs no client guard. ⚠ Reports do **not**
auto-hide an announcement — a reported row stays listed and readable however many reports
it has. Do not render a "hidden pending review" state.

**No engagement — structural.** Reaction grammar for kind `announcement` is `none`, the
counts map is empty, module 205 is registered as taking no reactions, and the activity row
is written with comments disabled at the PeepSo layer. Render no stoke, no helpful-mark, no
reward affordance, no reaction summary. The guarantee was in place *before* any
announcement could exist, so there is no legacy state.

## 12. Genuinely not implemented

| Thing | State |
|---|---|
| `published` in the owner-list enum | correctly absent — the public list serves published rows |
| `already_reported` in the standing producer | never supplied; absence means "not yet reported", so the control shows and the duplicate is idempotent server-side |
| Frontend release | #167/#168/#188 implemented and unmerged (§4) |
| Probe CI wiring | `scripts/tests/*` are hand-run; CI runs unit + two integration engines + guards |
| Wallet-privacy **positive** gate | untested — needs a fixture user with a wallet link; all three negative assertions pass |

⚠ **The forged / stale marker limitation.** Public visibility depends on an authorization
marker matching the row's author. That protects against the *application* publishing
something unauthorized. It does **not** protect against arbitrary direct database writes —
anyone who can write `wp_postmeta` can forge a marker, and nothing detects a stale one.
There is no revocation policy for markers and none was added. Do not present announcement
visibility to users as tamper-proof; it is an application-level authorization record.

## 13. Switching on and testing

The option is `bcc_announcements_enabled`, **absent** by default, with a **closed truthy
allowlist** — `'off'`, `'false'`, `'0'` do not enable it. While off every route answers
`404 bcc_not_found` — the handler's own refusal, **not** `rest_no_route`. If you see
`rest_no_route`, the route is not deployed, which is a different problem.

**Do not enable it on shared staging.** Use a disposable stack (`wordpress:6.7-php8.2` +
`mariadb:11.8.9`, plugin bind-mounted) where the switch can be flipped freely. Note a
throwaway stack needs pretty permalinks (`wp rewrite structure '/%postname%/'`), or REST
paths canonical-redirect with a trailing slash.

| Probe | Assertions | Covers |
|---|---|---|
| `announcement-comment-routes.php` | 145 | five comment routes, tombstones, admin surfaces, feature-off incl. anonymous |
| `announcement-write-journeys.php` | 116 | create, edit, publish, schedule, archive, pin |
| `announcement-feed-projection.php` | 76 | module-205 projection and withdrawal |
| `announcement-cron-publication-safety.php` | 71 | arm C: authority intact/revoked/ambiguous/absent, recovery, feature-off |
| `announcement-publication-interval.php` | 71 | scheduling windows, overdue rows, marker retirement |
| `announcement-summary-comments-prereq.php` | 65 | summary + comments_enabled on writes |
| `announcement-combined-interactions.php` | 42 | reports-do-not-hide, no-engagement, feature-off |
| `announcement-pin-concurrency.php` | 3 + N children | at-most-one pin under a real race; needs `BCC_PIN_ROLE` |

Run with `wp eval-file wp-content/plugins/bcc-trust/scripts/tests/<name>.php`. Each seeds
and tears down its own fixture.

⚠ **Two traps worth inheriting.** A probe that aborts mid-run skips its teardown, and the
next run then fails on leftover rows colliding with its own seed — sweep probe users and
announcement posts first if you get a wall of unrelated failures. And **count output lines
beside the pass total**: a run that fatals early reports fewer passes and *zero* failures,
which reads as a smaller green run rather than a crash. A probe needing an env var prints
usage and exits with no assertions at all.

**Open question for the frontend, not the backend:** whether the bar renders the rotator
(fixed top 5, no paging) or the active list (paginated). Both are live; the rotator exists
so the bar need not page.

---

## 14. Integration findings handed to the frontend agent

Measured 2026-10-15 by the backend session against released frontend main
`ddf5d6cc1245d295e2b4c4da54bed1a5face00e9` (GitHub deployment `Production`,
2026-10-14T16:33:30). Recorded here because they were found while looking, and
they belong to whoever does the integration — **the backend session did not and
must not implement them.** All four frontend heads are untouched: #167
`71005e6c7c75df180171baaeeccc1410aa81a2e3`, #168
`683505d44d6dc865ba4ad41531e86e44f0120742`, #188
`4b59fab26b1a81579b0827f6b133aad7d0d187be`.

### 14.1 EntityTabs — one conflict, and the shape of its resolution

#167 is **46 commits behind** released main. Merging main into it produces
**exactly one conflict**, in `src/components/entity/EntityTabs.tsx`. Nothing else
conflicts.

The two sides:

- **#167** builds `tabs` as a mutable array and inserts with
  `tabs.push({ key: "announcements", label: "Announcements" })` after Reviews,
  guarded on `announcementsPanel !== undefined && announcementsPanel !== null`.
- **Released main** (from #169, shipped to production in #193 `c17d54be`) replaced
  that with a `useMemo` array. Its own comment says why: *"Memoized because
  `tabKeys` below feeds useRovingTabs, whose onKeyDown is itself memoized on the
  key list — rebuilding the array every render would rebuild the handler every
  render for no reason."*

So the tab must be re-expressed **inside** the memo, with a `hasAnnouncements`
boolean in the dependency array, exactly as `hasOnchain` / `hasChains` already are:

```ts
const hasAnnouncements =
  announcementsPanel !== undefined && announcementsPanel !== null;

const tabs: EntityTabDef[] = useMemo(() => {
  const list: EntityTabDef[] = [
    { key: "backing",  label: ATTESTATION_COPY.supporters_tab },
    { key: "reviews",  label: "Reviews"  },
  ];
  if (hasAnnouncements) list.push({ key: "announcements", label: "Announcements" });
  list.push(
    { key: "activity", label: "Activity" },
    { key: "watchers", label: "Watchers" },
  );
  if (hasOnchain) list.push({ key: "onchain", label: "On-chain" });
  if (hasChains)  list.push({ key: "chains",  label: "Chains"  });
  return list;
}, [hasAnnouncements, hasOnchain, hasChains]);
```

⚠ Two things that are easy to get wrong here. **Order matters** — #167 placed
Announcements between Reviews and Activity on purpose ("operator's own voice next
to what others said about them, both ahead of raw activity"), and main's memo
lists `activity`/`watchers` in its initial literal, so a naive `push` after the
literal puts Announcements *after* Watchers instead. And **the dependency array
is load-bearing**: omit `hasAnnouncements` and the tab list goes stale when the
capability block arrives, which also staleness-freezes the memoized `onKeyDown`
and breaks roving focus. Activation stays **manual** (Arrow/Home/End move focus,
Enter/Space selects) — automatic activation would fire a request for every tab
arrowed past, which is why both profile strips do it this way.

### 14.2 AnnouncementsPanel — five gaps against the shipped denial policy

The relevant policy shipped **after** #167 was written: frontend PR **#194**
(`ddf5d6cc`, *"last blanket-isError branches, bcc_blocked copy, and the composer
permission boundary"*). Its primitives live in `src/lib/api/errors.ts`
(`isCode`, `humanizeCode`, `isPermissionDenial`) and
`src/lib/api/resource-denial.ts` (`shouldWithdrawOnDenial`, `useDenialGate`,
`useReportDenial`), and **13 shipped components** now follow it.

The canonical shape, from `CardReviewsPanel` / `CardWatchersPanel`:

```ts
const gate = useDenialGate(queryKey);
if (gate.withdrawn) return <withdrawn copy />;
useReportDenial(
  shouldWithdrawOnDenial(query.error, query.isRefetchError, query.data !== undefined),
  gate.withdraw,
);
const retryProps = isPermissionDenial(query.error) ? {} : { onRetry: retry };
if (query.isError && items.length === 0) return <whole-panel failure />;
```

`AnnouncementsPanel.tsx` on #167 has two infinite queries —
`active` and `archived`, both `useAnnouncements(pageId, { state })` with
`data.pages.flatMap(...)` — and diverges five ways:

| # | Gap | Consequence |
|---|---|---|
| 1 | `{active.isError ? <LoadFailure/> : …}` is **blanket** | fires with rows already loaded, so a failed LOAD MORE **erases the loaded list** — the recorded defect class |
| 2 | `onRetry={() => void active.refetch()}` is **unconditional** | a `bcc_forbidden`/`bcc_not_found` denial gets a Retry that fails identically every press |
| 3 | no `useDenialGate` / `useReportDenial` | a denied **refetch** keeps painting content the server has just refused, and its cached payload is never dropped |
| 4 | archived section is `{archivedItems.length > 0 && …}` | an archived read failure renders **nothing at all** — no message, no retry, silently invisible |
| 5 | no foot-of-list failure on either list | a failed `fetchNextPage` shows nothing; LOAD MORE just stops working |

Gap 1 is the one originally flagged; 2–5 were found while checking it.

### 14.3 ⚠⚠ The constraint that shapes the fix: the composer holds no draft

`AnnouncementComposer.tsx` keeps `title`, `summary`, `body`, `mode`,
`publishAtLocal` and `commentsEnabled` in plain `useState` with **no persistence
of any kind** — no `localStorage`, no draft key, nothing. It is rendered as a
sibling inside the panel's returned tree:

```tsx
{composerOpen && <AnnouncementComposer … />}
```

Every sibling panel implements the gate with an **early `return` on
`gate.withdrawn`**. Doing that here would unmount the composer and **destroy a
typed draft outright**. The shipped doctrine is explicit that withdrawal
*"never touches drafts or any other storage"*, so the early return is the wrong
shape for this panel specifically.

So the gate must wrap the **list article**, leaving the composer mounted as a
sibling — a deliberate divergence from the other 13 call sites, justified because
none of them holds unsaved user input, and worth a comment in the code saying so.
Two queries means two gates, or one gate scoped to whichever read the panel
treats as its subject.

⚠ The alternative — persisting announcement drafts the way `BlogComposer` does —
is a **larger product decision**, not a bug fix: it introduces viewer-scoped
storage with its own scope and sign-out rules. Do not fold it into this fix
without deciding it separately.
