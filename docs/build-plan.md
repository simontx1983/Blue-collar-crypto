# Build Plan — Path to Launch

**Status date:** 2026-06-28 · **Scope:** the road to testnet / closed-beta launch.

This is the at-a-glance "where are we" map. It summarises what's built and
what's left **before launch**, grouped by feature system. It deliberately
leaves out the demand-gated V2 wishlist — that lives in
[`v2-roadmap.md`](v2-roadmap.md). For the detailed near-term tracker see
[`TODO.md`](TODO.md); for how to verify a live deploy see
[`GOLDEN_PATHS.md`](GOLDEN_PATHS.md).

**Legend:** ✅ shipped · 🔄 in progress · ⬜ remaining (pre-launch) ·
⏭ deferred (V2 / out of scope here).

**Versions for orientation:** bcc-trust `1.2.19` · bcc-core `1.2.14` ·
bcc-search `1.0.6` · bcc-frontend `0.2.0`.

---

## Status at a glance

V1, V1.5, and V2-Phase-1 are **feature-complete and running locally**. The
full-stack reputation platform — trust scoring, disputes, multi-chain
on-chain/NFT, social feed, messaging, notifications (bell + web push +
digest), auth/2FA, admin moderation, and search — is built and wired
end-to-end.

The remaining path to launch is **not new features**. It's a short list of
cleanup / observability / perf items (see [`TODO.md`](TODO.md)), then the
[deploy checklist](testnet-deploy-checklist.md) and a final
[beta smoke pass](v1-smoke-test-checklist.md). The 2026-06-18 perf/upgrade
audit and the testnet-readiness review found **no architectural blockers** —
what's left is finishing, hardening, and verifying.

---

## By feature system — built vs. remaining

### Trust scoring & reputation
*Multi-factor score (votes + attestations + on-chain signals) → tiers,
ranks, fraud + ring detection, denormalised read model.*
- ✅ Score synthesis, tier/rank derivation, vote pipeline, fraud + ring
  detection, contribution recovery, `bcc_page_read_model` read path.
- 🔄 **Endorse→Attestation final cleanup** (`feat/retire-endorse`) — Slice-E
  score/write obligation is done; what remains is dead-code removal: delete
  `EndorsementWeightCalculator`, drop the zeroed `endorsement_bonus` column,
  retire `EndorsementVestingProcessor` + its cron branch, repoint the
  `can_endorse` gate to vouch-tier eligibility. Verify with arch-guardrails +
  golden recapture before merge.
- ⬜ **Doc fix** — correct the stale "dispute evidence / score history" claim
  in `schema-score-events.php:6` to reflect the one real consumer (24h
  highlights slot + write-time audit).

### Disputes
*Panel adjudication with scheduler / auto-resolve, participation tracking,
audit trail.*
- ✅ Resolver/adjudicator, daily auto-resolve + reconcile sweep, panelist
  participation, verdict audit logging.
- Nothing outstanding pre-launch.

### On-chain / NFT
*Multi-chain wallet linking, signal scoring, NFT holdings indexing,
validator pages, NFT-gated holder groups, entity claims.*
- ✅ Wallet linking + challenge-response across 6 chain families; signal
  scoring; NFT holdings indexing (ETH + SOL + Cosmos CW-721, Helius webhook
  dedup); validator page minting + logos; NFT-gated holder-group
  provisioning; entity claims.
- ⏭ Indexing edge-case iteration is ongoing but **not a launch blocker** —
  tracked under NFT scaling in [`v2-roadmap.md`](v2-roadmap.md).

### Social / feed
*Ranked feed, composer, reactions, mentions, watching, messaging,
profiles/cards.*
- ✅ For You / Watching / Signals feed, status + blog composer, reactions,
  @mentions, watching with batch tracking, direct + group messaging,
  CardFactory profiles across all surfaces.
- ⬜ **Remove the orphaned `level_up` celebration preset** — level crossings
  now celebrate as rank-ups; drop the unreachable preset from `types.ts`,
  `CelebrationGate.tsx`, `CelebrationToast.tsx`, `celebrations-endpoints.ts`,
  and the `level-up` keyframes in `globals.css` (keep `rank_up`).
- ⬜ **Warm the `/feed/hot` cold path** — ~20s cold view-model rebuild on
  first hit after cache expiry; add a warming cron or
  stale-while-revalidate so no user pays it.

### Notifications
*In-app bell, web push (V2-P1), email digest, per-event toggles, @mention
dispatch.*
- ✅ Bell badge + dropdown, web push (dispatcher + 9-event taxonomy), weekly
  email digest + signed unsubscribe, per-event toggles, @mention → bell +
  push.
- ⬜ **Prove `DegradationAlerter` end-to-end** — seed a subsystem, run
  `bcc_core_degradation_alert_check`, confirm the alert lands in Mailpit;
  then set `BCC_DEGRADATION_ALERT_EMAIL` (+ optional `_WEBHOOK`,
  `_THRESHOLD`) before deploy.

### Auth & account security
*Email/password + 2FA, OAuth (GitHub/X), wallet auth, email verification,
recovery, session mgmt.*
- ✅ All flows built: 2FA, OAuth, wallet sign-in, email verification,
  recovery email + password reset, session management, audit + canary
  security emails.
- ⬜ **Launch config only** — fresh secrets rotation, Vercel OAuth bridge
  secret, X callback URL (see [Launch gates](#launch-gates)).

### Admin & operations
*Moderation queue, fraud dashboard, health endpoint, degradation
observability, guard scripts.*
- ✅ Next.js moderation queue (hide/dismiss/restore with 30s undo), wp-admin
  fraud dashboard, system health endpoint, DegradationMetrics across 20+
  subsystems, parity / cadence / subsystem-count guard scripts.
- ⏭ Fraud dashboard intentionally stays in wp-admin, not Next.js (smoke
  §14.8). Nothing else outstanding.

### Search
*FULLTEXT engine + headless adapter, per-vertical endpoints, circuit breaker
/ LKG cache.*
- ✅ bcc-search engine, `CardsSearchEndpoint` adapter, per-vertical
  endpoints, circuit breaker + rate limiting + last-known-good cache.
- Nothing outstanding pre-launch.

### Engineering debt (cross-cutting)
- ⬜ **§1 raw-`$wpdb` remediation** — move 6 allowlisted files into
  Repositories (real but safe-today §1 violations): `TrustReadService`,
  `GroupsDiscoveryEndpoint`, `ReactionSeeder`, `WatchingService`,
  `NotificationPrefs`, `OnchainCircuitBreaker`. Remove each from
  `arch-guardrails.sh`'s `WPDB_DEBT_ALLOWLIST` as it lands.

---

## Launch gates

These aren't feature-shaped, but they block going live.

### Deploy config — see [`testnet-deploy-checklist.md`](testnet-deploy-checklist.md)
- ⬜ Rotate **all** §1.1 secrets fresh (local values are burned).
- ⬜ SMTP reachable end-to-end (security email is the trust anchor).
- ⬜ Real system cron + `DISABLE_WP_CRON=true`; confirm plugin self-heal +
  Action Scheduler queue.
- ⬜ Vercel `BCC_OAUTH_BRIDGE_SECRET` (matching wp-config) — SSO is
  fail-closed until set.
- ⬜ X developer portal: add the NextAuth callback URL.
- ⬜ `WP_REDIS_TIMEOUT` **P0 trap** — only enable `BCC_REDIS_ENABLED=1` after
  Redis is reachable; never ship an active drop-in to a Redis-less host.
- ⬜ Disable PeepSo name-based avatars (perf: `/members` 1492 → 143 queries).

### Post-deploy health gates (run in order)
1. Schema · 2. Auth · 3. Health endpoint · 4. Degradation noise floor ·
5. Guard scripts green · 6. Boot-floor query count · 7. E2E smoke.

### Performance
- ⬜ **Re-run the k6 load test on a provisioned staging box** (4 vCPU + Redis
  + tuned FPM). The Local run is dominated by a dev-box FPM cliff, so the
  per-tier DAU numbers in [`capacity-model.md`](capacity-model.md) stay
  modeled, not measured. Include the with/without-Redis comparison.

### Beta smoke — see [`v1-smoke-test-checklist.md`](v1-smoke-test-checklist.md)
- ⬜ Final manual walkthrough before opening the beta. The 8 intentional V2
  deferrals in §14 (chain-tab filtering, live signals ticker, NFT showcase,
  on-chain panel, composer embeds, per-event email filter, fraud dashboard)
  are **documented gaps, not blockers**.

---

## Explicitly out of scope here

Pointers only — these are real, but not on the path to launch:

- **Deferred V2 / demand-gated** (public API, native mobile, i18n, Backer
  concept, Injective / long-tail NFT chains, engagement polish) →
  [`v2-roadmap.md`](v2-roadmap.md).
- **Trust attestation Phase 1.5+** (profile-scoped disputes, reliability
  badges, validator/builder gauges, meta-dispute) →
  [`archive/trust-attestation-phase-1-plan.md`](archive/trust-attestation-phase-1-plan.md).
- **Post-MVP structural debt** (dual-namespace REST collapse,
  `bcc_project_*` rename, ERROR_COPY centralization) →
  [`archive/stabilization-plan-2026-05-13.md`](archive/stabilization-plan-2026-05-13.md).

---

*This file is a summary map. When a ⬜ item lands, check it off in
[`TODO.md`](TODO.md) (the source of truth) and refresh the matching line
here.*
