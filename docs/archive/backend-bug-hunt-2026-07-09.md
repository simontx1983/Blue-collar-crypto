> **ARCHIVED 2026-07-19** — dated bug-hunt record (2026-07-09); every finding fixed or refuted (L-B2 = intentional no-op per its own remediation log). Retained for the refutation reasoning (ERC-1155 fail-safe freeze, Helius live re-derivation) and the high-signal clean-list. Not current guidance.

# BCC Backend — Bug-Hunt Addendum (2026-07-09)

Follow-up to `backend-implementation-audit-2026-07-08.md`. Three read-only agents hunted
for **NEW** correctness/security bugs in the subsystems the main audit scrutinized least
(PeepSo data-access wrappers, the feed/cache/cron pipeline, and state-machine/scoring
logic), deliberately steering clear of every open finding from the main report.

**Verification status:** each agent traced the defect path in code and, where relevant,
verified against the **live dev DB** and against PeepSo's own source. Findings carry
clean-lists (what was checked and found sound). These are **single-pass** — they have NOT
yet gone through an adversarial-refutation round like the main audit's R5, so treat
HIGH/MEDIUM items as **high-confidence, pending refutation** before shipping a fix.
None of these overlap the main report's findings.

## Remediation log — 2026-07-09

Every finding here is now shipped except the one intentional no-op (**L-B2**). Each fix
was re-read in code before applying; verified php -l + arch-guardrails + unit tests +
PHPStan L8. The feed-pipeline findings (originally deferred to avoid colliding with parallel
feed work) landed once that lane cleared.

- ✅ **M-B3** (score tier) — bcc-trust PR #60
- ✅ **M-B2** (recalc retries in one tick) — PR #60
- ✅ **M-B4** (dispute reconcile backfill) — PR #60
- ✅ **L-B5** (force_resolve re-claim) — PR #60
- ✅ **L-B4** (holder-community double-bell) — PR #60
- ✅ **L-B3** (block-writer race) — bcc-core PR #18
- ✅ **L-B1** (inbox count overcount) — bcc-core PR #18
- ✅ **H-B1** (feed group-context keyed on `act_id`) — bcc-trust PR #62
- ✅ **M-B1** (200-follow feed cap) — bcc-core PR #19 (`filterFollowed` + 5000 whitelist cap)
- ✅ **L-B6** (cold-start hot-feed cache-key miss) — bcc-trust PR #63 (shared `HOT_WARM_LIMIT`)
- ➖ **L-B2** (muted-unread inconsistency) — left as-is (likely intentional)

---

## HIGH

### H-B1 — Feed group-context hydration keys on `act_id` instead of the backing wp_post → verification badge missing on every feed surface + gated-post comment-count leak to non-members
`FeedHydrationPipeline.php:137,150` (bcc-trust). `hydrateGroupContexts` parses `act_id`
from the `feed_<id>` field and calls `get_post_meta($actId, 'peepso_group_id')` — but the
group meta lives on the **`external_id`** post, not `act_id`. **DB-verified:** in
`wp_peepso_activities`, `act_id ∈ {141–159}` while `act_external_id ∈ {2098, 4909–4994}`;
they never coincide, and `peepso_group_id` meta exists only on the `external_id` post. Every
*other* method in the file correctly uses `$item['external_id']` — this is the lone outlier.
- **Effect 1 (functional):** no `group` block is ever attached, so the "On-Chain Verified"/
  group-type chip never renders on `/feed`, `/feed/hot`, `/feed/tag`, user walls, or
  `/groups/{id}/feed`.
- **Effect 2 (privacy):** `hydrateCommentCounts` gates comment-count visibility on
  `$item['group']['id']`; with the block never present, the non-member teaser path
  (`getGroupFeed publicOnly=true`) shows comment counts on gated group posts to
  non-members — the exact leak that code exists to prevent.
- **Latent:** a low-numbered wp_post that happens to share an integer with some `act_id` and
  carries `peepso_group_id` would attach a *wrong* group/verification block.
- **Fix:** key the lookup on `$item['external_id']`, as the rest of the pipeline does.

---

## MEDIUM

### M-B1 — "Following" feed & follow-state silently cap at the 200 most-recently-followed accounts
`PeepSoFollowerRepository.php:39,47` → `ActivityFeedService.php:285,348` /
`PeepSoGraphService.php:119` (bcc-core). `getFollowing` defaults to `LIMIT 200` newest-first;
the feed author-whitelist and `isFollowingBulk` consume that as the *complete* follow set
(no pagination loop). A viewer following >200 accounts loses every post from accounts outside
their newest-200, and those authors render a "Follow" button instead of "Following"
(the truncated set also poisons the per-request `followingCache`). Contrast
`SuggestionService`'s explicit `CANDIDATE_POOL_CAP` — a deliberate cap; this one leaks a page
size into a completeness assumption. Scale-gated (bites past 200 follows).

### M-B2 — `processRecalculations` burns all 3 retries inside one cron tick → a 1–3s transient quarantines a page's trust score for ~1 hour
`CronService.php:710-762` (bcc-trust). The `do…while` re-fetches `getFlaggedPageIds` in-loop;
on a thrown recalc the transaction rolls back leaving `recalculate_required=1` and
`last_calculated_at` unchanged, so the same page sorts first and retries again immediately.
A brief transient (row-lock wait, replication hiccup) lands all 3 retries in the same window →
`recalc_failures` hits 3 → `clearRecalcFlag` quarantines it until the hourly safety net
(~1h stale). Spreading retries across ticks (the documented design) would let the transient
clear.

### M-B3 — Onchain bonus write updates `total_score` but not `reputation_tier` → stale tier denorm on zero-vote pages
`ScoreRepository.php:2173-2182` (`applyBonusColumn`, bcc-trust). Its sibling writers
(`applyAttestationBonus` :1042, `applyPenalty` :1121) both set `reputation_tier = {tierSql}`
and mirror to `user_info`; `applyBonusColumn` recomputes `total_score` inline but omits the
tier — violating the invariant documented on `TrustScoreService::tierSql:129`. An entity page
with onchain signals but zero reviews gets its score lifted past `trusted`/`elite` while
`reputation_tier` stays `neutral`; the zero-vote fast path in `recalculateScore`
(`VoteService.php:1315`) returns without recomputing tier, so the stale tier persists to disk
and into the read model until an unrelated vote/attestation/penalty event recomputes it.

### M-B4 (low-medium) — Panelist accuracy (`outcome_match`) never backfilled for disputes resolved via the reconciliation path
`DisputeScheduler.php:379-403` vs `DisputeResolver.php:172-193` (bcc-trust). `backfillOutcomeMatch`
runs only inside `DisputeResolver::handle`; reconcile Phase B calls `executeAdjudication`
directly + `setAdjudicationStatus('completed')`, never `handle`. For any dispute that reaches
`completed` through reconcile, every credited participation row keeps `outcome_match = NULL`
forever → `countCorrect` undercounts → those panelists silently lose the accuracy trust term
and their `/me/participation` "correct" count is wrong. (The resolver's own comment: *"if we
add one"* — the sweep was never added.)

---

## LOW

- **L-B1** — Message inbox `total` overcounts vs the list it paginates: the count `EXISTS`
  (`PeepSoMessageRepository.php:152`) lacks the `post_status='publish'` + post_type filter the
  list has (`:101`) → empty trailing page when the newest visible message isn't a published post.
- **L-B2** — Per-conversation unread counts ignore muted conversations
  (`PeepSoMessageRepository.php:205`) while the global badge (delegating to PeepSo) excludes
  them → the inbox-row badge and header total disagree. (Possibly intentional.)
- **L-B3** — `PeepSoBlockWriter::block` check-then-insert race (`PeepSoBlockWriter.php:73-91`):
  no unique key + no lock → concurrent blocks insert duplicate rows and fire
  `bcc_user_blocked` twice. `unblock` cleans rows, so only the double-event lingers.
- **L-B4** — `onHolderCommunityProvisioned` stamps `notified_at` only after the whole loop
  (`NotificationDispatcher.php:1124-1146`); a mid-loop throw skips `markNotified`, so the daily
  sweep re-bells the already-notified subset (double go-live bell). `markNotified` return
  unchecked.
- **L-B5** — Admin `force_resolve` becomes un-retryable for up to the full dispute TTL if the
  async enqueue fails (`DisputeController.php:606-653`): `claimResolutionEnqueue` sets the flag
  before enqueue; a failed enqueue returns 503 but leaves the flag set, so retry 409s and
  reconciliation won't pick it up (sub-majority) — only `auto_resolve` at TTL recovers it.
- **L-B6** (perf) — cold-start hot-posts requests `getHotFeed(null, 2)` keyed `hot:v1:2`, but the
  warm cron only warms `hot:v1:20` (`FeedColdStartService.php:399` vs `CronService.php:801`) →
  always misses the warmed entry, rebuilds inline every ~5 min. Any `?limit=N≠20` is unwarmed too.

---

## What was checked and found clean (high-signal negatives)

SQL injection across all PeepSo repos (every `IN()` placeholder-bound; table names from
`$wpdb->prefix`); the global-feed `_bcc_post_visibility` privacy gate (group posts excluded
unless `public_all`, absent-meta ⇒ hidden); keyset/cursor pagination (strict tiebreak, no
boundary skip/repeat); `WatchBatchAggregator` idempotency (deterministic batch_id +
`WHERE batch_id IS NULL`); the shared `bcc_recalc_lock` (hourly vs 5-min can't double-process);
`rm_dirty_queue` race handling; generation-counter cache invalidation (§5 contract intact);
`AttestationScoreSynthesis`/`AttestationOutcomeClassifier`/`DecayResolver` math (no
overflow/div-by-zero/negative); `DisputeResolver` double-penalty prevention (claim-gated
transaction); mention dedup (structural, multi-dimension keys); `UserLifecycleService` FK-order
deletes; `AccountRecoveryService`/mailer send-wrapper.

---

## Suggested triage

1. **H-B1** first — it's a live privacy leak (comment counts on gated posts) *and* a
   visible functional regression, and it's a one-line key change (`act_id` → `external_id`)
   in a file outside the main-report fix set.
2. **M-B3** (stale tier) and **M-B1** (200-follow cap) next — both silently corrupt
   user-facing state at scale.
3. The rest are edge-triggered or cosmetic. Adversarially refute HIGH/MEDIUM before shipping,
   per the main audit's discipline.

---

## Round 2 (2026-07-09) — least-covered subsystems + adversarial verification

A second, independent bug hunt (4 read-only finders) over the areas round 1 and the main audit
scrutinized least — the onchain indexer + chain fetchers, the crypto signature verifiers, the
bcc-search engine, and onchain signals/gating — followed by **4 R5-style adversarial refuters**.
This round *did* apply the refutation discipline the triage above recommends, and it paid off:
**3 of the 4 HIGH/MED candidates were overturned.** The crypto verifiers came back clean
(EVM low-S enforcement, constant-time compares, chain-type from a trusted DB row).

- ✅ **H3 — Secret-page search leak** (bcc-search) · CONFIRMED → **FIXED (bcc-search #6)**.
  Page search + trending filtered only `post_status='publish'`, but a PeepSo page's privacy is a
  `peepso_page_privacy=2` post-meta on a *published* page, and raw `$wpdb` bypasses `WP_Query`'s
  `posts_clauses` privacy filter — so SECRET pages (name / url / score) leaked to anonymous
  callers. Added the `pm_priv` LEFT JOIN + `<>2` exclusion at all three page-ID sources
  (`searchCandidates` FT + title-prefix, `getFallbackPageIds`, `hydratePages`), mirroring the
  existing secret-*group* exclusion, plus `SearchRepositoryPrivacySqlTest`. CLOSED pages stay findable.
- ✅ **M1 — Claim-bonus mis-attribution** (bcc-trust) · DOWNGRADED→LOW but **FIXED (bcc-trust #70)**.
  `ClaimRepository::computePageClaimBonus` summed every verified claim on any entity wallet-linked
  to a page, with no `cl.user_id` filter — so a holder's verified claim on a creator-linked
  collection credited the **creator's** page (the holder got nothing). Farming was cap-gated (real
  on-chain holding + verified wallet + `UNIQUE(user,entity)` + hard +20 cap), hence LOW, but the
  attribution was a category error. Rewrote it claimant-centric (each claim credits the claimant's
  own page); pinned by `ClaimBonusAttributionSqlTest`.
- ❌ **H1 — ERC-1155 revoke-on-provider-outage** · REFUTED. Provider degradation *freezes* the
  persistent 1155 index positive (it never zeroes holdings), so a holder is retained, not revoked.
  721-live-vs-1155-frozen both fail **safe**.
- ❌ **H2 — Helius `markSeen` before ingest** · REFUTED (HIGH→LOW). Solana ownership is never read
  from the webhook index — the gate and gallery re-derive it live via `getAssetsByOwner`; the
  persistent index is 1155/EVM only. A dropped index row has no correctness impact, and the
  always-200 endpoint means Helius never re-delivers anyway. Left as-is by design.

**Lesson (again):** the "1155 outage" and "Helius permanent loss" HIGHs both rested on the
unchecked assumption that the persistent index is the authoritative liveness source for Solana
ownership — which the refuters disproved from code. Only the two survivors (H3, M1) were fixed.
