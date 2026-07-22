# Content Search — privacy-filtering design (F3)

**Status:** design only, not built. Prerequisite investigation complete (2026-07-21).
**Scope of this doc:** the privacy-filtering architecture — the load-bearing risk.
Surface/UX details (FE tab, ranking polish) are secondary and sketched at the end.

---

## The problem in one sentence

Search covers entities (pages) and people (users) but not feed **content**
(posts), even though the box says "Search the floor." Content carries
**per-viewer visibility** that the pages vertical never had, so a naive
`MATCH … WHERE post_type='peepso-post'` is a data-leak surface — the same class
as the `/search/users` bug we already fixed. The whole design is about routing
content search through the **exact** visibility rules the feed already enforces,
without duplicating them (§11).

---

## What the feed actually enforces (verified against source + live DB)

Feed visibility is **not** a single `visibleTo()` service. `FeedRankingService`
(bcc-trust, the "§F3 single brain") precomputes exclusion/allow lists in PHP and
passes them into `bcc-core`'s `PeepSoActivityRepository::getActivities()`, which
turns them into SQL. The pieces:

| Rule | Mechanism | Where |
|---|---|---|
| **Top-level vs comment** | `act_comment_object_id = 0` (comments have parent id ≠ 0) | `PeepSoActivityRepository.php:127` |
| **Trash / draft / pending** | `p.post_status = 'publish'` | `PeepSoActivityRepository.php:126` |
| **Group post visibility** | non-group post → always; group post → only if `_bcc_post_visibility='public_all'`; absent meta ⇒ dropped | `PeepSoActivityRepository.php:264-274` (raw SQL, inlined) |
| **Closed/secret groups** | privacy = postmeta `peepso_group_privacy` int (0 open / 1 closed / 2 secret); membership = `peepso_group_members` `gm_user_status LIKE 'member%'` | `PeepSoGroupRepository.php` |
| **Blocks (both directions)** | `p.post_author NOT IN (…)` from `peepso_blocks` `getBlockedIds` + `getBlockerIds` (≤500 each) | `PeepSoActivityRepository.php:146-152` |
| **Reputation shadow-limit** | authors from `ReputationRepository::getCautionAndRiskyUserIds()` merged into the same NOT IN | `FeedRankingService.php:248-257` |
| **Moderation hide** | `a.act_id NOT IN (…)` from `HiddenActivityRepository::getAllHiddenIds()` (≤5000, cached) | `PeepSoActivityRepository.php:154-160` |

**Reusable public seams (clean, bounded, mostly cached):** the *list-producers* —
`PeepSoBlockRepository::getBlockedIds/getBlockerIds`,
`HiddenActivityRepository::getAllHiddenIds`,
`ReputationRepository::getCautionAndRiskyUserIds`,
`PeepSoGroupRepository::getNonOpenGroupIds/getUserMemberGroupIds`.

**NOT reusable:** the *combining logic* (which lists apply to which surface) lives
inside `FeedRankingService`'s method bodies, and the per-post `public_all` gate is
**inlined SQL** inside `getActivities()`. There is no method that takes a set of
candidate ids and returns the visible subset. Re-assembling those predicates
inside a new search query is exactly the §11 duplicate-logic violation we must
avoid — and the highest-risk way to get privacy wrong.

**Verified premises (live DB, 2026-07-21):**
- FT index `bcc_ft_post_search` = FULLTEXT `(post_title, post_content)`.
- Post body is in `wp_posts.post_content` (peepso-post: 60/73 rows have body) → the
  existing index covers content search. No new index needed for v1.
- `peepso-post` (`act_comment_object_id=0`) vs `peepso-comment` (parent id ≠ 0) is a
  clean SQL discriminator.

---

## Decision 1 — v1 is **top-level posts only**. Comments are deferred.

Comments (`peepso-comment`) have **no authoritative visibility model today**. The
feed *excludes* them (`act_comment_object_id = 0`); `CommentRepository` reads them
only under `post_status='publish'` and applies **none** of block / moderation-hide
/ reputation / group visibility — those are enforced at the *parent-post* feed
level, never per comment. Returning comment rows from search would mean inventing
comment-visibility from scratch: the single biggest leak risk in the whole
feature.

→ **v1 indexes only `peepso-post` with `act_comment_object_id=0`.** This is the
content type that already has an authoritative visibility path we can reuse.
Comments become a v2 that first builds parent-post-visibility resolution. This
removes ~half the corpus and ~all of the novel-privacy risk from v1.

## Decision 2 — Two-phase: FULLTEXT candidates → **authoritative** feed filter.

```
Phase 1 (cheap, ranked, NON-authoritative):
  SELECT a.act_id, p.ID,
         MATCH(p.post_title,p.post_content) AGAINST (%s IN BOOLEAN MODE) AS relevance
    FROM wp_posts p
    INNER JOIN wp_peepso_activities a ON a.act_external_id = p.ID
   WHERE MATCH(p.post_title,p.post_content) AGAINST (%s IN BOOLEAN MODE)
     AND p.post_status = 'publish'
     AND p.post_type   = 'peepso-post'
     AND (a.act_comment_object_id = 0 OR a.act_comment_object_id IS NULL)
   ORDER BY relevance DESC
   LIMIT 200                      -- bounded over-fetch

Phase 2 (authoritative visibility — reuse the feed's brain):
  $visible = FeedRankingService::filterVisibleActIds($viewerId, $candidateActIds);
  // returns the subset of candidate act_ids this viewer may see,
  // computed with the EXACT lists+gates getFeed() uses.

Phase 3 (rank + project):
  keep phase-1 relevance order over the $visible set; hydrate; project safe fields.
```

Phase 1 is deliberately **not** trusted for privacy — it only finds text matches
and narrows to a bounded candidate set (≤200). All visibility lives in Phase 2.

## Decision 3 — Build the `visibleTo()` seam by **extracting**, not duplicating (§11).

The missing `filterVisibleActIds($viewerId, array $candidateActIds): array` is
built by lifting `getFeed()`'s own exclusion-list assembly into a shared method,
and by extending `getActivities()` with an **optional** `?array $restrictToActIds`
param (append `AND a.act_id IN (…)`, drop the time-keyset ordering when present).
Then:

- **The feed** keeps calling `getFeed()` — unchanged behavior when the new param is
  null (purely additive).
- **Content search** calls `filterVisibleActIds()`, which runs the *same* lists
  (blocks/hidden/reputation/group) through the *same* `getActivities()` SQL (same
  `public_all` gate, same NOT INs) restricted to the candidate ids, and returns the
  visible id set. Search re-orders that set by phase-1 relevance.

This makes privacy **identical-by-construction** between feed and search, and
keeps a single source of truth. It is the §11-correct alternative to re-writing
the visibility predicates inside a search query.

**Cost / risk (this is why F3 is "the big one"):** extracting shared assembly out
of `getFeed()` touches the most load-bearing query path in the app. It is gated
by:
- `scripts/verify-golden.sh` golden-master net (byte-compare the pinned read
  endpoints before/after),
- `scripts/arch-guardrails.sh` (no raw `$wpdb` outside repos, bounded queries),
- a dedicated **adversarial security review** of the extracted seam (the review we
  do for every privacy-touching batch),
- a focused test that feeds `filterVisibleActIds()` a candidate set spanning
  {non-group public, group public_all, group members_only, secret-group,
  blocked-author, hidden-act, trashed} and asserts the exact visible subset for
  member / non-member / anonymous viewers.

## Decision 4 — Cache is **viewer-scoped, short-TTL, no generation counter**.

Content visibility is genuinely per-viewer (my blocks ≠ yours; my group
memberships ≠ yours), so cache must be keyed like the **users vertical**, not the
login-bucketed pages vertical:

```php
$viewerId  = get_current_user_id();
$cache_key = 'content_search_' . md5(mb_strtolower($q) . '|' . $limit . '|v' . $viewerId);
```

No separate generation counter (mirroring the users vertical): privacy is enforced
**live** in Phase 2 every rebuild, so the cache is only a hot-path optimization
within its TTL. Use the same short TTL the other verticals use (~45s).

**Documented bounded-staleness tolerance:** a viewer removed from a group could
still see that group's post in *cached* search results for up to one TTL — the
same tolerance the feed itself accepts, and why the TTL stays short. Critically,
group **membership** is read from `getUserMemberGroupIds()` which is
**intentionally uncached** (load-bearing comment at `PeepSoGroupRepository.php:574-588`);
we reuse that uncached read in Phase 2 and only cache the *output* set, so we never
widen the membership-leak window beyond the result TTL.

## Decision 5 — Anonymous viewers.

`$viewerId = 0` → Phase 2 uses the anonymous composition (public_all + non-group,
no blocks, no memberships), mirroring `getHotFeed()`. Anonymous results are shared
(`|v0` bucket) — safe because there is no per-viewer variance when logged out.

## Decision 6 — Output projection: safe fields only.

Reuse the users vertical's safe-projection discipline:
- author identity = **`bcc_handle` only** (never `user_login`, never email);
- post URL via the **single** composer `CardUrlMap::postUrl` / `hydrateLinks`
  (the `/u/{handle}/post/{code}` shortcode path already locked in), never a raw
  permalink;
- snippet = sanitized excerpt of the **already-visible** body (strip
  shortcodes/HTML); it only ever reflects content the viewer passed visibility for;
- group context only when the group is itself public.

---

## Surface (secondary — after the privacy core is settled)

- **Backend:** `ContentSearchController` + `ContentSearchRepository` mirroring the
  groups vertical (self-contained controller + service + repo). Route
  `GET /bcc/v1/search/content` in the `bcc-search.php` `rest_api_init` closure.
  Throttle bucket `'search_content'`, `RATE_LIMIT=10 / RATE_WINDOW=5`.
  `QueryQualityGate::isSearchable()` at the same point as the other verticals
  (after limit-clamp, before any DB/cache work). Record analytics on the
  rebuild path (`vertical='content'`) via the existing `SearchTermsRepository`.
- **Frontend:** a "Posts" tab in `SearchResultsPage`, a `useSearchContent` hook
  (same typed-client + React-Query shape as the other three), a content row
  component. Renders server-provided relative routes as-is (§A2).
- **Contract:** new `#### GET /bcc/v1/search/content` entry in
  `docs/api-contract-v1.md` §4 (raw shape, like `/search/users`).
- **Ranking (v1):** FULLTEXT BOOLEAN-MODE relevance, recency tie-break.
  Author-trust weighting deferred.

## Build sequencing (when green-lit)

1. **bcc-core + bcc-trust** first: extract `filterVisibleActIds()` +
   `getActivities()` optional `restrictToActIds`; golden-master + arch-guardrails +
   adversarial visibility test. **Nothing user-facing ships in this step** — it's
   pure reuse plumbing, mergeable on its own.
2. **bcc-search**: `ContentSearchController`/`Repository`/route + analytics +
   tests, consuming the step-1 seam.
3. **bcc-frontend**: Posts tab + hook + row.
4. **contract** bump.

Steps are separate PRs (shared-worktree hygiene: check HEAD, stage explicit
paths, never `git add -A`, exclude `bcc-frontend/package.json`).

## Implementation notes (added 2026-07-21)

### Note A — `filterVisibleActIds()` must evaluate the **complete** candidate set

The seam consumes a **bounded** candidate set (Phase 1's `LIMIT 200`), so when it
reuses `getActivities()` it must run in a **"filter this exact id set"** mode, not
the feed's normal "page through recent activity" mode. Concretely, the extended
`getActivities()` / `filterVisibleActIds()` path, when `restrictToActIds` is
present, MUST:

- **not** apply the feed's time-keyset cursor (`a.act_id < $beforeId` / date
  cursor) — every candidate id is evaluated regardless of age;
- **not** apply any date/time window;
- **not** apply a `LIMIT` that could truncate the visible subset (the candidate
  set is already bounded to ≤200 upstream; the `IN (…)` list *is* the bound);
- **not** apply the feed's recency `ORDER BY` as a truncation gate — ordering is
  irrelevant here because search re-sorts survivors by Phase-1 relevance.

Rationale: if any of the feed's pagination/window/limit mechanics leaked into the
filter, search would **silently drop visible results** (a genuinely-visible,
older, or lower-in-the-keyset post would vanish from results) — a correctness bug,
not a security one, but exactly the kind the extraction refactor could introduce
by accident. The visibility test in the build-gate must assert that a candidate
set spanning a wide `act_id` / date range returns *every* visible member, not just
the recent ones.

### Note B — exact group visibility matrix (global content-search surface, v1)

F3 v1 is a **global** search surface (not group-scoped). The global gate is
`(peepso_group_id IS NULL) OR (_bcc_post_visibility = 'public_all')`
(`PeepSoActivityRepository.php:264-274`), which is **viewer-independent and
group-privacy-independent**. The full matrix — "does this post appear in global
content search?":

| Group privacy | `_bcc_post_visibility` | Anonymous | Non-member | Member |
|---|---|---|---|---|
| _(no group)_ | _(n/a)_ | ✅ | ✅ | ✅ |
| open (0) | `public_all` | ✅ | ✅ | ✅ |
| open (0) | `public_group` | ❌ | ❌ | ❌ |
| open (0) | `members_only` (or absent) | ❌ | ❌ | ❌ |
| closed (1) | `public_all` | ✅ | ✅ | ✅ |
| closed (1) | `public_group` | ❌ | ❌ | ❌ |
| closed (1) | `members_only` (or absent) | ❌ | ❌ | ❌ |
| secret (2) | `public_all` | ✅ | ✅ | ✅ |
| secret (2) | `public_group` | ❌ | ❌ | ❌ |
| secret (2) | `members_only` (or absent) | ❌ | ❌ | ❌ |

All ✅ cells are still subject to the viewer-independent gates (`post_status =
'publish'`, moderation-hide, reputation shadow-limit) **and** the one
viewer-**dependent** gate: author **blocks** (both directions).

**Two consequences that refine the design:**

1. **The group-privacy dimension collapses.** open/closed/secret does not change
   global-search visibility — only `_bcc_post_visibility` does, and only
   `public_all` passes. So **v1 global content search does not consult group
   membership at all** (`getUserMemberGroupIds()` is not needed). This *simplifies*
   `filterVisibleActIds()` for the global case and means the
   membership-staleness/uncached concern raised in **Decision 4 does not apply to
   v1** — it becomes relevant only for a **v2 group-scoped** content search that
   surfaces `members_only`/`public_group` posts to members via the teaser
   INNER-JOIN gate (`PeepSoActivityRepository.php:225-235`). The only per-viewer
   variance in v1 is the block set.

2. **`secret (2) × public_all` — TRACED, verdict AMBIGUOUS → this is now an
   explicit BUILD-BLOCKING DECISION (do not choose it silently).**

   A read-only trace of the current system (2026-07-21) resolved the *behavior*
   but not the *intent*:
   - **Behavior (proven):** the global gate is group-privacy-independent
     (`PeepSoActivityRepository.php:273`, `(gx_pm.meta_value IS NULL OR
     vis_pm.meta_value = 'public_all')`); the old `excludedGroupIds` NOT-IN
     exclusion is **inert/superseded** (docblock `:257-263`;
     `FeedRankingService.php:293-297`). So a `public_all` post in a secret group
     **does** appear on the global feed to non-members and anonymous users *today*.
   - **Unblocked upstream (proven):** nothing constrains visibility by group
     privacy. `gateGroupPost()` checks membership only
     (`PostsService.php:1902-1928`); `normalizeVisibility()` just clamps to the enum
     (`:194-199`); the writer stores whatever it's given
     (`PeepSoStatusWriter.php:174`); all three status/photo/gif composer routes
     accept the `public_all` enum (`PostsEndpoint.php:148-153,255-260,435-440`). A
     secret-group *member* can therefore post `public_all` and syndicate globally.
   - **Intent (unresolved):** `public_all` is documented to mean "syndicate
     globally" in general (`PostsService.php:53`), but **no document decides the
     secret-group case.** The one doc that speaks to it —
     `docs/api-contract-v1.md` §2082-2084 — asserts the *opposite* (claims non-open
     group posts are dropped for non-members) and is **stale/drifted** relative to
     the live code.

   > ### ⚠ SUPERSEDED — see the 2026-07-22 decision below
   >
   > **DECISION (2026-07-22, Phillip) — Gate 12 = "public_all wins" (authoritative).**
   > An explicit, valid `public_all` post **may appear on ALL public surfaces —
   > global/hot/tag feed, public permalink, PUBLIC CONTENT-SEARCH RESULTS, and
   > public group-discovery — even inside a closed or secret group**, exposing only
   > minimum discovery context (public body/media, author public identity, public
   > timestamp, public engagement, group name/avatar/URL, and a join/request/follow
   > action). Private-by-default, fail-closed on unknown/legacy visibility,
   > explicit-choice-only, server-side authorization, and moderator/admin removal
   > all remain in force (policy points 4–9).
   >
   > **Business rationale:** BCC communities — including NFT and private-membership
   > communities — need a controlled way to demonstrate activity publicly and
   > attract new followers/prospective members; group privacy protects membership
   > and private-by-default discussion, but must not prevent an author from
   > deliberately publishing an individual post publicly.
   >
   > **⚠ Label-collision + reversal note.** Phillip's "Option B (public_all wins)"
   > is the **Gate-12** label; it means content search **MIRRORS the feed for
   > `public_all`** — the *opposite* of this file's earlier "Option B (content
   > search STRICTER)". The earlier decision below is therefore **reversed**:
   > content search must **not** blanket-exclude secret/closed-group `public_all`
   > posts; it mirrors the feed's `public_all` gate (subject to the no-private-leak
   > boundaries above). *(By substance this equals this file's original "Option A —
   > mirror feed"; recorded by Phillip's Gate-12 "B" label.)*
   >
   > **Content search is NOT BUILT** (this file remains "design only, not built"),
   > so this is a **forward** policy: its exact enforcement is **OPEN** and must be
   > confirmed against this decision when F3 is built. The "stricter" text, matrix,
   > and `filterVisibleActIds()` consequence below are retained as **superseded
   > history**.

   **~~DECISION (2026-07-21, Phillip): OPTION B — content search is STRICTER than the
   feed.~~ [SUPERSEDED 2026-07-22 — see banner above]** Content search excludes posts belonging to closed/secret groups
   (`peepso_group_privacy ∈ {1,2}`) from the global surface **regardless of
   `public_all`**. Rationale: search should not surface content from private
   groups even when an author flagged an individual post `public_all`; the feed's
   existing syndication behavior is deliberately NOT mirrored here.

   **Implementation consequence for `filterVisibleActIds()` / the content
   vertical.** Policy B is an *additional* exclusion layered on top of the feed's
   authoritative visible set (Decision 3), not a change to the feed:
   - A group post appears in content search **only if** its group is **open
     (`peepso_group_privacy = 0`)** *and* `_bcc_post_visibility = 'public_all'`.
   - Non-group posts appear as before (subject to blocks/status/hidden).
   - So the content vertical DOES need the non-open group set —
     `PeepSoGroupRepository::getNonOpenGroupIds()` (generation-cached) — and drops
     any candidate whose post's `peepso_group_id` is in that set. This is the one
     visibility input the *global* feed path does not use (per Note B consequence
     1); Policy B reintroduces it **for search only**. It stays viewer-independent
     (group privacy is not per-viewer), so it does not change the cache-scope
     reasoning in Decision 4 (blocks remain the only per-viewer variance).

   **~~Content-search visibility matrix under Policy B (STRICTER)~~ [SUPERSEDED
   2026-07-22]** — under the "public_all wins" decision, content search **mirrors
   the feed** for `public_all`, so the closed/secret × `public_all` rows below flip
   from ❌ to ✅ when F3 is built. Retained as superseded history:

   | Group privacy | `_bcc_post_visibility` | In content search? |
   |---|---|---|
   | _(no group)_ | _(n/a)_ | ✅ |
   | open (0) | `public_all` | ✅ |
   | open (0) | `public_group` / `members_only` / absent | ❌ |
   | closed (1) | **any** (incl. `public_all`) | ❌ |
   | secret (2) | **any** (incl. `public_all`) | ❌ |

   (All ✅ still subject to `post_status='publish'`, moderation-hide, reputation
   shadow-limit, and viewer author-blocks.) The build-gate visibility test must
   assert the `closed/secret × public_all` cells return **empty** for content
   search even though the same post IS visible in the feed.

   **Separately (independent of F3):** the stale `api-contract-v1.md` §2082-2084
   text misdescribes current global-*feed* visibility (claims secret-group posts
   are blocked upstream — they are not) and is corrected on its own track.

## Honest caveat

The corpus is currently ~73 posts (no real users yet), and analytics can't yet
tell us whether people will search content. This design de-risks the *hard* part
(privacy) so it's ready to execute, but the data argument still favors shipping to
production and letting real search traffic justify the build before we spend the
bcc-core refactor risk.
