# Operational Audit — 2026-05-13

**Scope.** End-of-day platform-integrity sweep across PHP plugins (`bcc-core`, `bcc-trust`, `bcc-search`, `blue-collar-crypto-peepso-integration`) and Next.js frontend (`bcc-frontend/`).

**Purpose.** Convert operational tribal knowledge into operator-grade record. Identify single-points-of-failure, silent-failure paths, hidden coupling, and "works because we know the system" assumptions.

**Method.** Three parallel Explore agents (silent-failure audit, frontend↔backend coupling audit, architecture-invariant verification) + manual verification of every dangerous finding before classification. Companion procedures live in `docs/GOLDEN_PATHS.md` (evergreen runbook).

**This is a snapshot.** Re-run quarterly OR after any architectural commit. Do not edit retroactively — add a new dated audit if findings change.

---

## Section A — Architecture Invariants

All 9 architectural invariants from the BCC Constitution were verified on 2026-05-13. Representative evidence:

| # | Invariant | Status | Representative evidence |
|---|---|---|---|
| 1 | Single authoritative trust read model | ✅ VERIFIED | `ScoreReadService` is the single cross-plugin entry; `bcc_page_read_model` is canonical; `bcc_trust_page_scores` is fallback only, locked behind `ScoreRepository::saveRecalculated()` (transaction + `lockForUpdate()`). |
| 2 | Repository-only DB access | ✅ VERIFIED | No raw `$wpdb` outside `Repositories/` paths surfaced by grep, with documented exceptions for infrastructure (`AdvisoryLock`), schema, and admin notices. |
| 3 | PeepSo infra-only posture | ✅ VERIFIED | All `peepso_*` mutations through `bcc-core/src/PeepSo/*Writer.php`. No direct `PeepSoGroupUser::member_join` outside the writer. |
| 4 | Headless-only rendering | ✅ VERIFIED | Every REST handler returns `WP_REST_Response` via `ApiResponse::ok/error`. No shortcodes/blocks/templates outside `*/Admin/`. |
| 5 | Envelope contract consistency | ✅ VERIFIED | `Envelope::wrap` at `rest_post_dispatch` priority 999, covering both `/bcc/v1/*` and `/bcc-trust/v1/*`. |
| 6 | Permissions gating discipline | ✅ VERIFIED | Every `permission_callback => '__return_true'` mutation endpoint enforces auth inside the handler. Throttle gate precedes credential gate on all credential-rotation routes (per commit `6c78b87`). |
| 7 | Audit / event taxonomy integrity | ✅ VERIFIED **with refinement** | The canonical map in `bcc-core/bcc-core.php` registers every active subsystem (`throttle`, `peepso_absence` × 18, `audit_log_swallow` × 4, `legacy_ajax` × 9, `search_lkg`, `read_model_fallback`, `account_security_mail` × 5, 10 `null_*` activations). Agent C initially read this as "free-form keys" — the map IS the registry; the misread is itself a documentation gap worth surfacing. |
| 8 | No duplicate trust-score authorities | ✅ VERIFIED | Only `ScoreRepository::saveRecalculated()` (full recalc) and `VoteWriter` (atomic vote-and-score) write to `bcc_trust_page_scores`. |
| 9 | No UI-only authorization assumptions | ✅ VERIFIED | Every server-side endpoint re-validates eligibility regardless of frontend posture. Specifically holder-group join (`NftGroupGateService`), plain group join (`MyGroupsEndpoint::postJoin`), wallet link (`AuthEndpoint::walletLink`), and the three account-credential routes (`MyAccountEndpoint`) all gate independently of frontend hidden-CTA discipline. |

**Net.** The constitutional substrate is intact. No invariant violations found.

---

## Section B — Silent-Failure Audit

Read-only sweep across 503 PHP files. 41 total findings:

| Pattern | SAFE | QUESTIONABLE | DANGEROUS |
|---|---|---|---|
| Empty catch blocks | 0 | 0 | 0 |
| Logger without escalation | 3 | 0 | 0 |
| `class_exists('PeepSo*')` fail-open | 18 | 0 | 0 |
| NullObject masking | 4 | 0 | 0 |
| `WP_Error` ignored | 0 | 0 | 0 |
| `return [] / null` on critical paths | 8 | 4 | 0 |
| Async without observability | 1 | 3 | 0 |
| Stale TODO/FIXME in trust/auth/wallet/disputes/moderation | 0 | 0 | 0 |

The initial agent classified one finding as **DANGEROUS** (`VoteJobDispatcher::enqueueWithLock` lock-timeout silently returning) — manual verification at `bcc-trust/app/Domain/Core/Services/Vote/VoteJobDispatcher.php:149-172` reclassified it as **SAFE by design**: the advisory lock is keyed on `md5($hook . wp_json_encode($args))` so two dispatchers with identical args contend for the same lock. Bail-on-timeout means "another worker is already enqueuing this job" — the correct dedup behavior, not lost work. The inner `as_has_scheduled_action` check is the second layer of dedup.

**Remaining real concerns (QUESTIONABLE):**

1. **`wp_schedule_single_event` dispatched without observability.**
   - `bcc-trust/app/Domain/Core/Services/EndorsementFraudAnalyzer.php:42` and
   - `bcc-trust/app/Domain/Core/Services/Vote/VoteFraudAnalyzer.php:51`

   WordPress's `wp_schedule_single_event` returns `bool|WP_Error|null`. Both call sites use the return value but do not record a DegradationMetric on failure. Under autoload bloat or a corrupt `wp_options` table, the dispatch silently fails and fraud analysis never runs. Audit log records the vote; the missing async job is invisible.

   **Risk:** medium. Failure surfaces only when a downstream forensic check looks for the missing analysis row.

   **Recommended action:** wrap each call in try/catch + `DegradationMetric::record('cron_dispatch', '<event>_schedule_failed')`. New subsystem registration required in `bcc-core/bcc-core.php`.

2. **`TrustScoreService::getForPage` null return.**
   - `bcc-trust/app/Domain/Core/Services/TrustScoreService.php:114-129`

   Returns `null` when neither read-model nor live repo has score data. Callers default to `BCC_TRUST_NEUTRAL_SCORE` (50). Pages with genuinely low scores during cache-refresh windows get a false-neutral display.

   **Risk:** low. Pattern-registry already documents this as the legacy aggregation fallback. The frontend's caching staleness ≤ 30s mitigates user-visible drift.

   **Recommended action:** emit `read_model_fallback.legacy_aggregation` when both sources miss (currently emitted only on legacy-aggregation path; null returns aren't counted).

3. **`VoteEligibilityChecker::check` returns null on ambiguous voter history.**
   - `bcc-trust/app/Domain/Core/Services/Vote/VoteEligibilityChecker.php:173`

   Documented contract: null means "voter not yet seen on this page." Callers must distinguish from "ineligible." No DegradationMetric.

   **Risk:** low. The contract is documented; the null is intentional.

   **Recommended action:** none for V1. If V2 introduces eligibility tiers, replace with an enum.

4. **`DisputeRepository::getDisputedVoteIds([])` returns `[]` for empty input.**
   - `bcc-trust/app/Domain/Disputes/Repositories/DisputeRepository.php:566`

   Semantically correct; no signal if the caller's list-building logic broke and passed `[]` silently.

   **Risk:** very low. Defensive callers handle this.

   **Recommended action:** none.

**Net.** The platform has zero genuinely-dangerous silent-failure paths. Three QUESTIONABLE items, all addressable inside a single observability sprint, none release-blocking.

---

## Section C — Coupling Audit (Frontend ↔ Backend)

7 findings. 2 HIGH, 3 MEDIUM, 2 LOW.

### HIGH

**C1. Dual-client envelope shape mismatch (V-07 / V-29 manifestation).**

- `bcc-frontend/src/lib/api/client.ts:41-142` — parses `{ data, _meta }` (the `/bcc/v1/*` envelope).
- `bcc-frontend/src/lib/api/bcc-trust-client.ts:1-131` — parses `{ success: true, data }` (the `/bcc-trust/v1/*` envelope).

The two parsers are incompatible. A developer adding a new endpoint adapter who picks the wrong client gets `bcc_invalid_envelope` thrown on first contact. TypeScript's generic `<T>` doesn't enforce the correct binding.

**Operational risk:** medium-high. A new adapter shipped to production with the wrong client breaks every call to that endpoint silently from the SPA's perspective — the SPA sees errors, the user sees blank states, no DegradationMetric fires because the parser-failure is treated as a client-side problem.

**Mitigation today:** Section 14.4 of GOLDEN_PATHS.md (`grep` audit on every adapter). Manual.

**Permanent fix:** V-07 namespace consolidation (long-term project on the deferred list).

**C2. Permission-block null-safety assumed but not enforced.**

- `bcc-frontend/src/components/groups/GroupFeedSection.tsx:47` accesses `group.permissions.can_read_feed.unlock_hint` with no optional chaining.
- `bcc-frontend/src/components/groups/GroupMembershipStrip.tsx:106-117` accesses `permissions.can_leave.allowed`, `permissions.can_join.allowed`, `permissions.can_join.unlock_hint` directly.
- Contract pins these as required (`bcc-frontend/src/lib/api/types.ts:3254-3257`).

If the backend ever omits `permissions` on a group response (e.g. for an unsupported group type), the SPA crashes with `Cannot read property 'X' of undefined` in client React.

**Operational risk:** low *today* (contract is pinned), medium *over time* (any future endpoint that returns a partial response sets off a runtime crash).

**Permanent fix:** Make the contract enforcement automated. Run `scripts/api-contract-check.sh --with-contract` against staging before every deploy. Already exists; needs to be required in CI before merge.

### MEDIUM

**C3. `bcc_rate_limited` copy duplicated across 6+ components.**

After commits `13deb24`, `4a69ef2`, `49a408c`, the following components each maintain their own per-surface "Slow down…" string:

- `AccountSection.tsx`
- `BlockToggle.tsx`
- `JoinPlainGroupButton.tsx`
- `EligibleCommunitiesModal.tsx`
- `CommunitiesList.tsx` (×3 sites)
- `GroupMembershipStrip.tsx` (×4 sub-components)

Wording varies subtly across sites. The codebase's established pattern is per-component, which is fine for now — but if a future PR adds a 7th surface without checking the others, the wording will drift further.

**Recommended action:** none required for V1. Consolidate into a `lib/api/error-copy.ts` constants module if/when the count exceeds 10 components OR if i18n is introduced.

**C4. `EndorseButton.tsx:163-180` branches on `err.message` rather than `err.code`.**

```ts
if (err.status === 400 && err.message !== "") {
  return err.message;
}
```

The frontend assumes "400 errors carry quest/age/eligibility messages." Per the API contract, `err.code` is stable and `err.message` is humanizable text that may be localized later. Branching on message presence is implicit and uncontracted.

**Operational risk:** medium. The day the backend localizes endorsement errors, the SPA branch falls through unexpectedly.

**Recommended action:** switch the branch to `err.code`-based, with explicit codes per quest/age/eligibility class.

**C5. Push CTA renders without server-side capability check.**

- `bcc-frontend/src/components/settings/NotificationPrefsForm.tsx:285-336` renders the "Enable push" toggle based on browser support only.
- `bcc-frontend/src/hooks/usePushSubscription.ts:88-120` only discovers `bcc_push_not_configured` (503) after the user clicks Enable.

The server's VAPID configuration health isn't surfaced before render. Users on instances without VAPID keys configured see an enable-then-fail UX.

**Operational risk:** low (mostly cosmetic), but creates a real first-impression bug on a fresh production deploy.

**Recommended action:** add `push_available: boolean` to `/me/notification-prefs` response. Frontend conditional-renders on it.

### LOW

**C6.** `GroupsPanel.tsx:166-176` permission field access without optional chaining — TypeScript already enforces required, but defensive coding would prevent runtime crashes if the contract ever softens. Same shape as C2 but lower-traffic.

**C7.** `viewer_membership` three-state rule documented in TypeScript comments but not in `docs/api-contract-v1.md`. Mild documentation gap.

---

## Section D — Final Categorized Report

### D.1 VERIFIED SAFE

These are operationally-load-bearing behaviors that have been verified working today AND have observability hooks that catch regressions.

- Auth critical path (NextAuth ↔ BCC bearer JWT) — 3-layer defense-in-depth on token expiry. Verified end-to-end 2026-05-13 (see `project_nextauth_expiry_verified.md`).
- Account credential rotation (email/password/delete) — Throttle gate, audit log, AccountSecurityMailer, all observable via `account_security_mail` DegradationMetric.
- PeepSo write boundary — single-graph rule intact, holder-groups-reviewer agent re-verified post-edits.
- Trust-score read pipeline — read-model is canonical, legacy aggregation is fallback only, both observable via `read_model_fallback` DegradationMetric.
- Audit-log retention — `archiveBatch(5000)` is the sole retention writer post commit `eed5d4f`. Archive table will populate as data ages past 90 days.
- Rate-limit gate — verified via in-process smoke (`allow, allow, allow, allow, allow, deny, deny`) and live HTTP browser-eval drain (4×422 + 4×429 in 657ms).
- Envelope contract — `rest_post_dispatch` priority 999 wraps every endpoint; no raw `wp_send_json_*` in the bcc-* plugins outside legacy AJAX shims.
- DegradationMetric taxonomy — every registered subsystem appears in `/system/health` output with all its registered events.
- Holder-group gating — verified via `holder-groups-reviewer` agent run on commits `08be805 + 6c78b87 + 25b54d9`.
- §VIII.30 audit-log swallow — failure surfaces via `audit_log_swallow.log_write_failed` (commit `8106e55`).

### D.2 SAFE TECH DEBT

Intentional shape-of-codebase items that are fine for V1 but should be tracked for future cleanup.

- **Dual REST namespace** (`/bcc/v1/*` + `/bcc-trust/v1/*`) — migration shim. The dual HTTP client on the frontend is its mirror. Will collapse when V-07 ships.
- **Legacy AJAX co-existing with REST** — `wp_ajax_bcc_wallet_*`, `wp_ajax_bcc_trust_*` instrumented via `legacy_ajax` DegradationMetric (9 events). 30-day zero-hit window per audit doc V-08 is the retirement gate.
- **Six profile components scaffolded but unimported** — `GoodStandingRibbon`, `LiveShift`, `MemberBio`, `SectionHead`, `ShiftLog`, `StatsStrip`. Document or archive. Removing would lose the design intent for the profile redesign.
- **Empty `account_security_mail` events in steady state** — by design. Sustained nonzero is the P1 alert; steady-zero is the success signal.
- **Per-component `ERROR_COPY` maps** (C3 above). Established pattern, no consolidation required at current scale.
- **`bcc_project_*` table prefix unmigrated** — naming drift only, no operational risk.
- **`/settings/identity` legacy redirect** — UX-only cosmetic.

### D.3 OPERATIONAL RISKS

Items that could bite production but are not release-blocking.

- **`wp_schedule_single_event` calls without observability** (`EndorsementFraudAnalyzer:42`, `VoteFraudAnalyzer:51`). Fraud analysis silently fails to schedule on a sick `wp_options` table. Wrap in try/catch + DegradationMetric. ~2-hour fix, new `cron_dispatch` subsystem in the taxonomy.
- **Cron drift on plugin in-place upgrade** — already shipped self-heal on `plugins_loaded` (see memory note `project_v2_nft_cron_drift_incident.md`), do not remove the apparent activation/self-heal redundancy.
- **Throttle backend fail-closed posture** — by design, but means a Redis outage takes the entire mutation surface offline. Operational: monitor `throttle.degraded` flag in `/system/health`.
- **AccountSecurityMailer assumes `wp_mail` works** — no retry path. A misconfigured SMTP loses the canary email; the audit log remains the only forensic trail. Surfaceable via `account_security_mail.*_send_failed` DegradationMetric.
- **Frontend `permissions.X.Y` access without optional chaining** (C2). One contract softening triggers a runtime crash. Mitigated today by `types.ts` enforcement; would warrant defensive coding before public-API gate.
- **Dual-client envelope mismatch** (C1). Manual audit is the only guard.

### D.4 FUTURE SCALE RISKS

Items that work today but will not survive 10×–100× user growth.

- **Sliding-window rate limiter using `wp_options` transients.** Per `RateLimiter::slidingWindowCheck`, every Throttle::allow call is a transient INSERT/UPDATE. At 100× current write volume, this is contention on the most-contended WP table. The fix exists (Redis backend) but isn't required for V1.
- **`bcc_trust_activity` table growth.** Now bounded by `archiveBatch(5000)` per cron tick. At >10K audit writes/day, the daily cron may not keep up; tune `archiveBatch` batch size or move retention to `bcc_thirty_minutes` interval.
- **Helius dedup table capped at 10k.** Adequate for V1 traffic. At >100k events/day, the 10k cap clips legitimate dedup keys and replay-protection degrades.
- **`PageReadModelRepository` legacy aggregation fallback.** Acceptable while the read model is current; with 10× pages, the legacy aggregation becomes catastrophic during cold-cache windows.
- **NextAuth session JWT = 7-day TTL.** Acceptable for V1. Public-API access requires a refresh-flow gate (see `AuthEndpoint::JWT_TTL_SECONDS` docblock).
- **No global mutation-error toast surface.** Each component handles its own errors. At >50 mutation surfaces this becomes maintenance burden; consolidate via `mutationCache.onError` in `providers.tsx` before then.

### D.5 Gate: "Fix before mobile / public API"

The mobile app + a public API both expose the platform to clients that cannot be assumed to follow contract-update discipline. These items should be hardened before either.

- **C1 — dual-client envelope mismatch.** Public clients picking the wrong envelope shape get cryptic errors. Either consolidate the namespaces or document the envelope contract per-endpoint in `docs/api-contract-v1.md`.
- **C2, C6 — permission-block null-safety.** Defensive coding. Public clients on older versions will not always send up-to-date contract assumptions.
- **C4 — `err.message` branching in EndorseButton.** Localization breaks this. Mobile clients that ship with localized strings make it worse.
- **C5 — Push CTA without capability flag.** Mobile apps will hit this on every cold start; the server flag is the right design.
- **Refresh-flow on auth.** 7-day TTL plus no refresh ≈ a forced re-login every week. Acceptable on web SPA; unacceptable on mobile.
- **Document every error code per endpoint** in `docs/api-contract-v1.md`. Today the codes are stable in code but enumerated by reading the source. Mobile contributors will not have that habit.

### D.6 Gate: "Fix before large user growth"

Distinct from the public-API gate. These items have user-traffic-scaling implications.

- **Rate-limiter on transients.** Move to Redis or accept wp_options contention.
- **`bcc_trust_activity` retention cadence.** Tune the daily cron, or move retention into the 30-minute cron family.
- **No mutation-error toast surface.** Component-by-component error rendering breaks down past ~50 mutation surfaces.
- **`bcc_helius_seen_signatures` 10k cap.** Becomes inadequate at high webhook throughput.
- **PeepSo notification fan-out under load.** `PeepSoNotificationWriter` writes synchronously. At 1000 mentions per minute, the synchronous write becomes the bottleneck.
- **Search FT index rebuild stampede.** `bcc-search` already has stampede-lock + LKG cache. Verify under load before betting on it for production-scale.

### D.7 Acceptable Indefinitely

These are intentional design decisions, not debt.

- §VIII.30 audit-log swallow on the mutation path. Mutation correctness must not be conditional on telemetry. Failures observable via `audit_log_swallow.log_write_failed`.
- NullObject services failing closed on bcc-trust absence (e.g., `NullTrustReadService::isSuspended() → true`). Correct fail-closed posture for a suspension gate.
- AccountSecurityMailer never retrying. Security emails are time-sensitive; a delayed retry is operationally useless.
- Dual REST namespace as a migration shim. Lives until V-07 closes; the shape is documented in the constitution.
- WP-admin moderation tabs alongside Next.js admin moderation. Two surfaces, partially overlapping. Decision is product-side, not architectural — defer until SPA admin reaches feature parity for fraud/rings/devices/ML.

---

## Section E — How Future Contributors Use This Audit

1. **Run the GOLDEN_PATHS checks first.** If any fail on the current branch, the audit's "VERIFIED SAFE" section is stale — re-verify the affected sections before relying on this document.
2. **Treat "QUESTIONABLE" silent-failure findings as next-sprint candidates.** Not release-blocking, but they accumulate operational dark debt.
3. **Treat "OPERATIONAL RISKS" as scoping inputs for the next observability sprint.** Each has a concrete fix shape (try/catch + DegradationMetric) and a registration step in `bcc-core/bcc-core.php`.
4. **Treat "Mobile / Public API gate" items as required pre-work before either of those ships.** They are not optional.
5. **Re-run this audit quarterly OR after any architecture-level commit.** Create a new dated audit file; do not edit this one. The git log + dated files form the canonical record of platform integrity over time.

---

## Section F — What This Audit Did Not Cover

For transparency:

- Dispute panel quorum + auto-resolve under load (requires multi-user test fixtures).
- OAuth verification (X / GitHub) — requires live credentials.
- Helius webhook signature verification — requires Helius secret.
- Search index rebuild under stampede — requires load simulator.
- Frontend bundle size / Sentry replay redaction policy — out of scope for an integrity audit.
- 22 unverified hooks from earlier UNKNOWN-tagging pass — high-confidence-clean per pattern adherence, not directly re-verified.

These are candidates for the next audit cycle.

---

**End of audit.**

Companion documents:
- `docs/GOLDEN_PATHS.md` — evergreen operational verification runbook.
- `docs/pattern-registry.md` — durable architectural principles + canonical implementations.
- `docs/api-contract-v1.md` — the REST envelope and per-endpoint contract.
- `bcc-trust/CLAUDE.md` — bcc-trust plugin conventions §1–§9 + §11.
