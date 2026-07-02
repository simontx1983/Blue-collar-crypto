# Active TODO

Living checklist of work that is a real candidate for the next few weeks.
Check items off as they ship. New items append to the appropriate section
with a one-line title + a source link or `file:line` ref.

**Scope discipline:** this file is *active candidates only*. Demand-gated
items (public API, mobile, i18n), post-MVP structural debt, and mid-bake
forbidden items live elsewhere — see the [Parked / elsewhere](#parked--elsewhere)
section at the bottom. Don't pad the active list.

---

## Frontend

- [ ] **Remove the orphaned `level_up` celebration preset** — the 2026-06-22 identity slice made Rank mirror level 1:1, so level crossings celebrate as **rank-ups**; the backend no longer stashes a `level_up` kind (retired `LevelProgressionListener` + `bcc_feature_level_unlocked`). The frontend `level_up` preset is now unreachable: drop it from [`types.ts`](../bcc-frontend/src/lib/api/types.ts), [`CelebrationGate.tsx`](../bcc-frontend/src/components/celebration/CelebrationGate.tsx), [`CelebrationToast.tsx`](../bcc-frontend/src/components/celebration/CelebrationToast.tsx), [`celebrations-endpoints.ts`](../bcc-frontend/src/lib/api/celebrations-endpoints.ts), and the `level-up` keyframes in [`globals.css`](../bcc-frontend/src/app/globals.css). Keep `rank_up`.

## Backend / Contract

Real drift surfaced by the 2026-05-26 first run of `scripts/contract-parity-guard.php`. Each needs a one-way decision: fix the contract OR fix the code.

- [ ] **Post-launch doc fix** — correct the stale "Used for: dispute evidence, ... user-facing score history" claim in [`schema-score-events.php:6`](../app/public/wp-content/plugins/bcc-trust/includes/database/schema-score-events.php#L6). The 2026-06-18 read-path trace proved no dispute flow reads `trust_score_events` (disputes read the votes table) and the "score history" readers are now deleted. Reword to reflect the one real consumer (the 24h `findForPagesSince` highlights slot) + write-time audit log.

- [ ] **Endorse→Attestation cutover — final cleanup (NOT STARTED — the `feat/retire-endorse` branch referenced here previously does not exist; verified against `git branch -a` 2026-07-02)** — the Slice-E obligation landed: `AttestationService` now backs every "I back this subject" write, `EndorsementService` write paths are retired (read/eligibility/vesting only), and `TrustScoreService::formulaSql()` already excludes `endorsement_bonus`. What remains: delete the dead `EndorsementWeightCalculator`, drop the zeroed `endorsement_bonus` column (`includes/database/drop-endorsement-bonus.php` written, guarded/idempotent), retire `EndorsementVestingProcessor` + its cron branch, and repoint the `can_endorse` gate to vouch-tier eligibility. Verify with arch-guardrails + golden recapture before merge. Refs: `EndorsementService.php`, `EndorsementWeightCalculator.php`, `ScoreRepository.php`, `PageScore.php`, `VoteService.php`.

<!-- §1 raw-$wpdb remediation shipped 2026-06-28 (bcc-trust #21) — see Recently shipped below -->


## Observability

<!-- subsystem-count guard shipped 2026-05-26 — see Recently shipped below -->

<!-- degradation-alert end-to-end proof shipped 2026-07-02 — see Recently shipped below -->

## Performance / Ops

<!-- /feed/hot warm shipped 2026-07-02 (bcc-trust feat/feed-hot-warm) + the "20s cold" RE-ATTRIBUTED to the Local FPM cliff — see Recently shipped below -->
- [ ] **Re-run the load test on a provisioned staging box** — the Local k6 run is dominated by a dev-box FPM cliff (~2–4 workers + Xdebug), so the per-tier DAU numbers in `capacity-model.md` stay modeled, not measured. Re-run [`scripts/perf/load-test.js`](../scripts/perf/load-test.js) on a 4 vCPU + Redis + tuned-FPM box, including the with/without-Redis comparison.

## Docs


---

## Recently shipped

Once an item ships, move it here with a date + commit hash so the
file documents recent flow. Trim entries older than ~30 days.

- 2026-07-02 — `/feed/hot` anon first-page payload cache + minutely warming cron shipped (bcc-trust `feat/feed-hot-warm`): warm latency ~1.2s → ~0.1s; cache key folds in the §K1-C hidden-activity generation (instant moderation invalidation), new-post staleness cron/TTL-bounded (≤~60s), matching the endpoint's declared `max-age=60` posture. **The "~20s cold rebuild" was RE-ATTRIBUTED** during this work: HTTP-probe instrumentation proved it was never the feed build (~0.2s cold) — it's the Local FPM cliff (a post-flush wp-cron chain-probe sweep of ~28 RPC calls occupies one of ~2 workers; the next request queues ~17s in FPM). See `capacity-model.md` Measured-baseline finding 1. Cron self-heals via the `plugins_loaded` guard (no version bump needed); registered in `cron-registry.md`.
- 2026-07-02 — Degradation alert proven end-to-end on Local. Seeded `DegradationMetrics::record('throttle')` ×5 → `wp cron event run bcc_core_degradation_alert_check` → `[BCC][P2] DEGRADED: throttle` landed in Mailpit; cleared the buckets → re-ran the cron → `[BCC][P2] RECOVERED: throttle` landed; alert-state option de-dup verified (steady-state ticks don't re-alert). Bonus finding: the proof surfaced a REAL sustained degradation already alerting locally — `nft_indexer` / `dense_block_stall` (10 events across two hours). Prod config row added to `testnet-deploy-checklist.md` §1.3 (`BCC_DEGRADATION_ALERT_EMAIL` + optional `_WEBHOOK`/`_THRESHOLD`).
- 2026-06-28 — §1 raw-`$wpdb` remediation complete (bcc-trust #21). All 6 `WPDB_DEBT_ALLOWLIST` files moved into Repositories/Infrastructure; the allowlist in `arch-guardrails.sh` is now EMPTY, so the guard enforces §1 on every file. (TODO line retired 2026-07-02 — it had gone stale.)
- 2026-06-28 — TODO triage: confirmed two Frontend items already shipped (below) and refreshed the endorse-cutover line to its real in-progress state (`feat/retire-endorse`). The Slice-E score/write obligation is done; only the dead-code + column-drop + `can_endorse`-gate cleanup remains. (2026-07-02 correction: no `feat/retire-endorse` branch actually exists — the cleanup is not started.)
- 2026-06-11 — RightSidebar widgets wired to real query hooks (`9021d1f` + `aa306d9`, bcc-frontend). Newest Members (`useMembers`) replaced the fabricated "Top Directories" stub; Trending (`useTrendingHashtags`) + Suggested (`useSuggestedMembers`) render live data, hiding gracefully on empty/error. Closes the RightSidebar Phase-2-integration item.
- 2026-06-04 — Blog-post edit hydration shipped (`df140b3`, bcc-frontend). URL-driven `?edit=<id>`: BlogPanel owns the URL contract, hydrates from the `useUserBlog` cache (fast) or `GET /posts/{id}` (cold/draft), and passes `editingPostId` + `initialValues` into the now presentation-only BlogComposer (PATCH on submit). Closes the `BlogComposer.tsx` V1.5 item.
- 2026-06-18 — Deleted 3 dead `ScoreEventRepository` readers (`getForPage`/`getForActor`/`getTierChanges`) — zero callers confirmed by grep across all repos; only live read path is `findForPagesSince` (24h §O2.1 highlights slot). PHPStan L8 + arch-guardrails green. Stale `schema-score-events.php:6` "dispute evidence / score history" doc claim remains (see Backend section).
- 2026-06-12 — Frontend-coverage docs refreshed for v1.19/v1.20 surfaces. `trust-engine-coverage.md` re-audited (header now 2026-06-12): 15 new auth/recovery rows, all ✅ grep-verified against the typed client + UI surfaces; one honest finding — `auth-endpoints.walletLogin()` is dead code (live wallet login goes through the NextAuth Credentials provider). `v1-smoke-test-checklist.md` gained §2.10–2.14 (wallet signup/login, recovery-email banner, forgot/reset, oauth-complete) + §6.9 (Phase γ err.code-only copy check); §7.1 TODO-verify resolved (owner entry = `DisputeCallout` in EntityProfile's IdentityBlock stack). NEW `testnet-deploy-checklist.md` consolidates secrets/SMTP/cron/Vercel/health-gates.
- 2026-06-12 — Boot-floor root cause fixed (`bcc-trust a3f27ee`). The ~250-query floor was the schema-migration gate misfiring per-request (stale-object-cache option mismatch re-ran the ~200-query installer, including the chains×26 seeds). Gate now logs loudly + verify-after-write + GET_LOCK; `TableRegistry::exists()` caches table-existence probes (positive-only persist); `ChainRepository::getById()` request-memoized. Live before/after counts pending; probe at `scripts/bcc-query-floor-probe.php`.

- 2026-05-26 — contract-parity-guard.php extended with cross-file class-constant resolution. One-pass walk of every plugin PHP file builds a `ClassName => [CONST => value]` map; `resolve_string_arg` now distinguishes `self::` / `static::` (same-file) from `OtherClass::CONST` (cross-file). Closed the parser-limitation finding (`UserGroupsEndpoint` using `UsersEndpoint::HANDLE_PATTERN`) plus four other previously-unresolved sites in `UserAlbumsEndpoint` / `UserAlbumPhotosEndpoint` / `UserFollowsEndpoint`. Guard exit code is now 0 — safe to wire into a blocking CI check. 21 subsystem-count subsystems + 223 in-scope REST routes covered by the two guard pair.
- 2026-05-26 — admin/ranks REST surface retracted (contract v1.23). Investigation found half-built state: read-side `is_admin_conferred` field renders in `GET /ranks` (always false in V1; no path sets a non-auto rank), `RankCatalog::isAutoAssigned` flags certain ranks as non-auto — but zero `register_rest_route` for the documented `POST /admin/ranks/award` + `DELETE /admin/ranks/:rank/:user_id`, zero frontend callers. V1 ships pure auto-derivation via `RankProgressionListener::run`. Two `####` headers replaced with a §4.8 deferral note. Read-side artifacts kept (contract field stable; admin-override is a future feature build). Parity guard down to 1 remaining finding (parser limitation).
- 2026-05-26 — Locals endpoint drift retracted (contract v1.22). Three §4.7 entries corrected to match code (`POST/DELETE /me/locals/:id/membership` for join+leave, `POST /me/locals/:id/primary` for set-primary, `bcc_forbidden` not `bcc_not_found` for "not a member"). One new entry added: `DELETE /me/locals/primary` (clear-primary; existed in code but undocumented). No server-side behavior change. Parity guard re-run dropped from 6 → 3 missing endpoints. `c:/.../docs/api-contract-v1.md`.
- 2026-05-26 — API-contract-vs-code parity guard shipped (`scripts/contract-parity-guard.php`). Sibling to subsystem-count-guard: token-walks PHP for every `register_rest_route()` call (resolves same-file constants + string concatenation + reserved-word const names like `NAMESPACE`), regex-scans api-contract-v1.md for `#### \`METHOD /path\`` declarations, diffs both directions. First run on 218 in-scope routes vs 82 contract endpoints surfaced **5 real drift items** the manual audits had missed (locals endpoint names + methods drift, admin/ranks endpoints documented but not built) plus 1 parser limitation (cross-file constant in `UserGroupsEndpoint`). Drift items captured under Active → Backend / Contract for one-way decisions.
- 2026-05-26 — Holder-group provisioning sweep observability wired. New `gated_group_provision` DegradationMetric subsystem (3 events: `peepso_absent`, `no_admin_owner`, `group_create_failed`) records the three operationally-distinct failure modes inside `GatedGroupProvisioningService::provisionAll`. Daily cron sweep retries unprovisioned collections on each tick; sustained activation = retry path not catching up. Closes the last "Pending wirings" item in pattern-registry. Subsystem-count-guard now reports 21 subsystems.
- 2026-05-26 — Helius webhook dedup-skipped observability wired. New `helius_dedup` DegradationMetric subsystem (1 event: `replay_skipped`) records inside `HeliusWebhookEndpoint::handle` every time `HeliusSeenSignaturesRepository::markSeen` returns false. The existing `bcc_helius_signature_seen_total` wp_options counter (admin panel) stays untouched — different surface, different consumer. Subsystem-count-guard now reports 20 subsystems. One item closed from pattern-registry's "Pending wirings"; holder-group sweep retries remains.
- 2026-05-26 — Cross-plugin §γ stable-code migration complete (3 commits). **bcc-search** (`aaedf42`): 11 sites in SearchController + UserSearchController + GroupSearchController — `rate_limit_exceeded` / `categories_unavailable` / `rebuild_in_progress` / `score_enrichment_failed` / `temporarily_overloaded` / `user_search_unavailable` / `group_search_unavailable` → standard §1.4.6 codes (`bcc_rate_limited`, `bcc_upstream_unavailable`, `bcc_internal`). **bcc-trust REST surfaces** (`98a26da`): 11 sites in XController, GitHubController, PageEndpoint, EntityClaimEndpoint, FlagEndpoint — `x_error`, `github_error`, `rate_limited`, `not_found` → standard codes. Bonus: EntityClaimEndpoint and FlagEndpoint weren't in the original audit. **bcc-trust legacy `error()` helpers** (`657f212`): AdminStatsController (8 sites, helper deleted) + UserStatusController (4 sites, helper deleted) migrated to `errorWithCode()` pattern. Bonus: IndexerTickEndpoint `bcc_misconfigured`/`bcc_indexer_failed` → `bcc_internal`. **Out of scope** (documented as service-layer infrastructure, not REST drift): bcc-core SafeHttpClient (6 SSRF codes) + bcc-trust BlockchainQueryService (5 wallet SSRF codes). No new contract codes needed; every drift mapped to existing §1.4.6 entries. All four guardrails green throughout.
- 2026-05-26 — Full TrustRestController migration to `errorWithCode()` complete. All 9 remaining `self::error()` sites mapped to §1.4.6 stable codes (`bcc_internal`, `bcc_invalid_request`, `bcc_rate_limited`, `bcc_forbidden`). The legacy `error()` helper deleted entirely (no callers left). Dead 503 branch in `safeExceptionError` deleted under fresh-install policy (no exception in bcc-trust throws with code 503; the docblock literally said "reserved"). Controller is now 100 % §γ-compliant.
- 2026-05-26 — Envelope drift "sweep" turned out to be a doc-only correction (contract v1.21). Live curl of `/chains` proved both endpoints were ALWAYS enveloped by `Envelope::wrap()`; the v1.19 "raw-array drift flagged" notes were authored from a wrong reading of the controller code. No server-side change. Bonus discovery: contract claims aren't verified against runtime — added a sibling parity-probe to Active.
- 2026-05-26 — Subsystem-count guard (`scripts/subsystem-count-guard.php`) — diffs canonical map in `bcc-core.php` against `pattern-registry.md` + `GOLDEN_PATHS.md`. First run caught two undetected drifts: `audit_log_swallow` docs claimed 4 events but canonical had 3 (my earlier "fix" had aligned to the wrong source); `account_security_mail` docs lagged behind the 2026-05-16 Tier D sixth-event addition. Both fixed.
- 2026-05-26 — Endorse error-code Phase γ stabilization (contract v1.20). `bcc-trust 3e27fa5`, `bcc-frontend e9b4caf`, contract `921ee0f`.
- 2026-05-26 — `cron_dispatch` DegradationMetric subsystem closes the fraud-analyzer enqueue silent-failure gap. `bcc-core 4f3e686`, `bcc-trust a9b07f9`, docs `469fdda`.
- 2026-05-26 — Drift-audit mechanical fixes (audit_log_swallow count, api-contract header, cron-registry cross-refs + hook names). `c4f44bd` + `2ce719a`.
- 2026-05-25 — Contract v1.19 (wallet-auth endpoints + §4.24 Wallets) + `legacy_ajax 9→3` retirement. `b1984f3` + `67aed16`.

---

## Parked / elsewhere

The active list deliberately excludes the following. Look here when one
becomes a candidate.

- **Demand-gated** (public API, native mobile, i18n, Backer concept, Injective/NEAR/Thorchain NFT support, gallery list endpoints) → [`v2-roadmap.md`](v2-roadmap.md). Don't start without external evidence of need.
- **V2 engagement polish** (sound on Heavy celebrations, streak-freeze mechanic, network percentile on others' profiles, per-category highlight muting) → [`v2-roadmap.md`](v2-roadmap.md). Open, but ranks below the items above.
- **Trust attestation Phase 1.5+** (profile-scoped disputes, reliability badges, validator/builder gauges, meta-dispute flow) → [`trust-attestation-phase-1-plan.md`](archive/trust-attestation-phase-1-plan.md). Design-gated.
- **Acceptable post-MVP debt** (V-07 dual-namespace REST shim collapse, `bcc_project_*` table-prefix rename, ERROR_COPY centralization across 19 components) → [`stabilization-plan-2026-05-13.md`](archive/stabilization-plan-2026-05-13.md) (frozen).
- **Mid-bake forbidden** (NFT observability X1–X5 / F2 / F6 expansion, Stabilization Phase D, frontend gap-audit D2-full / D3 / D4) → tracked in Claude memory; do not start without explicit approval.

---

## Rules for editing this file

- One-line items only. If detail is needed, link the source doc / code.
- Items leave by being checked off + moved to **Recently shipped**, not by being deleted in-place. Audit trail.
- The **Parked** section is a *pointer*, not a copy — each entry links to where the real list lives. Don't duplicate scope here.
- Forbidden items are NEVER in **Active**, even with `- [ ]` discipline. They go in **Parked** or stay out entirely.
- File ordering inside a section is rough-priority. Reorder freely; don't add severity labels (they drift).
