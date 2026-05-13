# Trust Attestation Layer — Phase 1 Scope-Freeze Implementation Plan

**Status:** Scope-frozen 2026-05-13. Implementation may begin.
**Owner:** Phase 1 build follows this plan; deviations require
plan amendment, not silent reinterpretation.

**Reads from:**
- `docs/trust-attestation-layer.md` (constitution)
- `docs/api-contract-v1.md` §4.20 (wire contract)
- `docs/trust-attestation-risk-assessment.md` (threat model)

**Scope assumption:** ~4 weeks at single-engineer pace. If team
capacity changes, sequence reshuffles per §11.

---

## 1. What Phase 1 is

Phase 1 delivers the foundational layer of the Trust Attestation
Layer: the three V1 primitives (Vouch / Stand Behind / Dispute),
the core attestation table + service, the synthesis math (decay,
reliability, divergence-state classification), the entity card +
profile action cluster + attestation roster + reputation summary
panel, the self-mirror reliability view, the four-card onboarding
flow, the critical-priority hardening from the risk assessment
§5 items 1–10, and the closed-network testing instrumentation
hooks.

**Phase 1 ships the *philosophy* in code, not the full system.**
Phase 1.5 + Phase 2 + Phase 3 extend the surfaces and harden the
edges. The acceptance criterion for "Phase 1 done" is the
constitution's §J.13 — a brand-new user, given only the four-card
onboarding, understands the system in 60 seconds; an operator
landing on a card decides whether to trust the entity in 60
seconds, citing concrete signals from the card.

## 2. Pre-work before Phase 1 starts

Before any Phase 1 code lands:

- [ ] **Constitution + risk assessment read.** Engineer touching
      this work confirms they've read both. Not optional — the
      anti-viral-by-design principle and the §J.4.1 synthesis
      invisibility invariant are easy to violate by accident.
- [ ] **Closed-network testing protocol drafted.** Separate doc
      (`docs/trust-attestation-closed-network-protocol.md`)
      specifying cohort selection, dashboards, interview cadence,
      kill-switch criteria. Plan to draft alongside Phase 1 build,
      complete before public release.
- [ ] **Phase 1 implementation tracking issue created** on the
      bcc-trust repo, linking to this plan, the constitution,
      and the risk assessment. All Phase 1 PRs reference the
      tracking issue.

## 3. Phase 1 scope freeze

### In scope

**Backend (bcc-trust):**

- `bcc_trust_attestations` table (new schema)
- `bcc_attestor_reliability_cache` table (read-model)
- `AttestationService` (generalized from existing
  `EndorsementService`)
- Synthesis layer extensions: decay function, Reputation Score
  composite, Operator Reliability computation, divergence-state
  classification, Stand Behind bandwidth tracking
- Critical-risk-mitigation services:
  - `WalletAgeWeighter`
  - `NewAccountReputationVelocityCap`
  - `ReciprocityPenaltyResolver`
  - `CohortOverlapDampener`
  - `MetaDisputeFilerEligibility`
- REST endpoints per `api-contract-v1.md` §4.20:
  - `POST /me/attestations`
  - `DELETE /me/attestations/:id`
  - `POST /me/attestations/:id/reaffirm`
  - `GET /entities/:target_kind/:target_id/attestations`
  - `GET /me/reliability`
- Entity + profile view-model extensions per §4.20
- Migration of existing `bcc_endorsements` rows
- `ContentReportService.TARGET_KINDS` extension
- Notification taxonomy extensions per §J.7 (subset: the
  attestation-related events; dispute-related events ship with
  the dispute extension to user_profile target_kind in Phase 1.5)

**Frontend (bcc-frontend):**

- Action cluster component (Vouch / Stand Behind / Dispute /
  Report)
- Stand Behind allocation indicator (`Stand Behind · 2/5`)
- Attestation roster component
- Reputation summary panel
- Self-mirror reliability view (operator's own profile)
- Pre-publication notification UX (24-hour heads-up before
  `polarizing` or `disputed` transitions)
- Empty-state copy on profiles + cards (no "0 attestations"
  numeric)
- Four-card onboarding flow integrated into the existing
  onboarding step sequence
- Notification surfaces for the new event types

**Contract (docs):**

- Already locked in `api-contract-v1.md` §4.20 — no further
  contract work expected; if implementation surfaces a needed
  change, amend the contract first per §A4.

### Out of Phase 1 scope (parked)

- **Profile-scoped disputes.** Phase 1 disputes on user_profile
  target_kind ship as a follow-up (Phase 1.5). Existing entity-
  card disputes continue to work; new user_profile disputes
  require panel-mechanics extension.
- **Floor reframe.** The trust-event stream + Layer 0 culture
  rail is Phase 2. Phase 1's `/` page remains the existing feed
  with Layer 1 events added as a new event class (subtle
  surfacing, not full reframe).
- **Public Reliability Standing badges.** V1 is self-only.
  Public badge surfacing ships in V2 expansion (~6 months after
  Phase 1 launch, gated on attestation density).
- **Validator Confidence + Builder Reputation + Creator
  Reputation full gauges.** Phase 3 delivers the full synthesis
  with all signal inputs. Phase 1 ships a simpler placeholder:
  Reputation Score + Reliability Standing carry the entity
  surface; the dedicated gauges land later.
- **Meta-dispute as a separate flow.** Phase 1 enforces the
  meta-dispute filer eligibility constraint (item 5 in §5
  Critical) as a static gate. The full meta-dispute filing flow
  is Phase 3.
- **Floor first-mover event class.** Phase 2.
- **Directory "Polarizing this week" + "Early Read" filters.**
  Phase 2.
- **Confirmed Trade primitive.** Phase 3+ (requires evidence flow
  design).

### Phase 1.5 backlog (recommended to ship within 60 days of
Phase 1 launch)

Items from §5 Important that did not make Phase 1:

11. Account-age visibility on entity cards
12. Reputation-damage cap from frivolous disputes
13. Visible filer track record on disputes
14. Group cooldown on dispute filing
15. Sub-threshold dormancy detection
16. Tighter dormancy threshold for slot-counting
17. Polarizing-stickiness bidirectional rule
18. Cluster-aware polarization detection
19. Revocation cooldown on Stand Behind slots
20. First-mover credit single-use per target
21. Tier-mobility visibility in self-mirror
22. Directory "Recently emerging" filter
23. Dispute education in onboarding
24. Panel-adjudication transparency
25. Dispute private until panel acceptance
26. Visual de-emphasis of badges
27. No badge-progress public surface
28. Self-mirror weekly-cadence design (lifted to Critical inside
    Phase 1 — included in scope)
29. Spike-detection weight dampener
30. Vouch confirmation step
31. Onboarding-funnel attestation prompt

## 4. Implementation sequencing — 4 weeks at single-engineer pace

### Week 1 — Foundation

**Goal:** Data layer + core service + migration.

- Day 1–2: `bcc_trust_attestations` schema. Migration script.
  Cache table.
- Day 3–4: `AttestationService` skeleton. Generalizes
  `EndorsementService` — same fraud orchestrator + weight
  calculator + audit logger + throttle. Implements `cast()`,
  `revoke()`, `reaffirm()`.
- Day 5: Migration of existing `bcc_endorsements` rows. Dual-
  emission shim: API responses emit BOTH `trust_score` (legacy)
  AND `reputation_score` (canonical) for one release cycle.

**End-of-week acceptance:**
- Migration runs cleanly on a local snapshot of production data
- AttestationService unit tests cover happy path + revoke +
  reaffirm + idempotency + ineligibility errors
- All existing endorsement-touching tests still pass against the
  refactored pipeline
- PHPStan level 8 clean, arch-guardrails clean

### Week 2 — Synthesis + REST surfaces

**Goal:** Reputation Score / Reliability Standing / divergence-
state synthesis math wired; REST endpoints reachable.

- Day 1–2: Decay function (read-time). Reputation Score composite
  computation. Operator Reliability computation.
- Day 3: Divergence-state classification (the five-state synthesis
  per §J.2). Polarizing-detection requires high-reliability
  attestor divergence per the substantive-divergence rule.
- Day 4–5: REST endpoints per §4.20:
  - `POST /me/attestations`
  - `DELETE /me/attestations/:id`
  - `POST /me/attestations/:id/reaffirm`
  - `GET /entities/:target_kind/:target_id/attestations`
  - `GET /me/reliability`
  Each endpoint wires Envelope + Throttle + audit; attestor_
  summary surfaces self-only vs third-party correctly.

**End-of-week acceptance:**
- Live endpoints reachable; manual `curl` exercises happy + error
  paths per the contract
- `GET /entities/...` correctly surfaces self-only fields only on
  self-queries
- `reputation_score` numeric is visible; `trust_score` legacy
  field also emitted; contract guard clean

### Week 3 — Frontend surfaces

**Goal:** Card + profile action cluster, attestation roster,
reputation summary panel, self-mirror view.

- Day 1: Action cluster component (Vouch + Stand Behind +
  Dispute + Report). Stand Behind shows `2/5` allocation
  indicator — the load-bearing UX cue per §J.7 heuristic 5.
- Day 2: Attestation roster component. Renders avatars + handles
  + reliability standing badges; supports the divergence-
  rendering split (high-reliability supporters / detractors) for
  polarizing-state cards.
- Day 3: Reputation summary panel. Identity + score + standing +
  tier + standing chip + negative-state badge if triggered.
- Day 4: Self-mirror reliability view on the operator's own
  profile. Shows full numeric reliability + Consensus Reliability
  + Early Read Accuracy (sub-tracks) + trend direction. Weekly-
  cadence update (not real-time) per §2.7 / §5 item 28.
- Day 5: Empty-state copy on profiles + cards (no "0 attestations"
  numeric display). Pre-publication notification UX scaffolding
  (the actual transition-detection backend ships separately —
  see Week 4).

**End-of-week acceptance:**
- Card + profile surfaces render the new components correctly
- Stand Behind action shows allocation; clicking when full opens
  the slot-management modal
- Self-mirror surface visible on own profile only; numeric
  reliability does not leak to other viewers
- Empty-state copy follows the heuristic 9 / risk §2.9 pattern
- All FE rules pass: no business logic in components, no `as any`,
  reduced-motion respect, memoized feed cards

### Week 4 — Hardening + critical-risk mitigation + closed-network prep

**Goal:** Items 1–10 from risk-assessment §5 Critical all
shipped. Closed-network testing instrumentation in place.

- Day 1: `WalletAgeWeighter` service. Plumbed into the synthesis
  layer's weight calculation. Wallet-age sourced from existing
  `bcc_wallet_links.linked_at`.
- Day 1: `NewAccountReputationVelocityCap`. Applied at read-time
  in the Reputation Score synthesis — clamps any entity's score
  to ≤ 50 for the first 60 days of its existence.
- Day 2: `ReciprocityPenaltyResolver`. Detects A→B + B→A patterns
  in attestation data; applies baseline weight (no diversity
  bonus) to mutual attestations.
- Day 2: `CohortOverlapDampener`. Refines the diversity
  multiplier with a graduated dampener — 0.8× when attestor's
  prior attestation set overlaps >50% with target's existing
  attestor set.
- Day 3: `MetaDisputeFilerEligibility`. Static eligibility gate
  preventing cohort members from filing meta-disputes against
  their own cohort. Computed cohort membership via the same
  network-cluster analysis the diversity multiplier uses.
- Day 3: `PolarizationTransitionNotifier`. Worker that detects
  divergence-state transitions approaching `polarizing` or
  `disputed`; fires the 24-hour heads-up notification to the
  target operator.
- Day 4: `ContestedStateExplainer`. Self-only "why am I in this
  state" view linked from operator's own card view.
- Day 4: Four-card onboarding flow integrated into existing
  onboarding sequence. **The §2.9 explicit teaching card is
  load-bearing** — "absence of attestation is not a negative
  signal" must read verbatim.
- Day 5: Closed-network testing dashboards instrumented.
  Telemetry hooks for the §4 watch signals: tier-mobility,
  attestation timing distribution, network clustering, dispute
  volume, divergence-state transitions, retention by reliability
  standing, attestation rate per operator over tenure, etc.

**End-of-week acceptance:**
- All 10 Critical items from risk-assessment §5 are in place and
  manually exercisable
- Closed-network dashboards display the §4 quantitative signals
- 60-second comprehension test passes against the four-card
  onboarding flow (run with 3+ fresh users per §J.13.1)
- Counter-party comprehension test passes (a viewer unfamiliar
  with the platform can decide whether to trust an entity from
  its card within 60 seconds, citing concrete signals — §J.13.3)
- Phase 1 release readiness review complete

## 5. Data layer

### 5.1 `bcc_trust_attestations` schema

```sql
CREATE TABLE bcc_trust_attestations (
  id                            BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  attestor_user_id              BIGINT UNSIGNED NOT NULL,
  target_kind                   VARCHAR(20) NOT NULL,
  target_id                     BIGINT UNSIGNED NOT NULL,
  kind                          VARCHAR(20) NOT NULL,
  weight_at_time                DECIMAL(8, 4) NOT NULL,
  context_note                  TEXT NULL,
  attestation_order_in_target   INT UNSIGNED NOT NULL,
  created_at                    DATETIME NOT NULL,
  reaffirmed_at                 DATETIME NULL,
  revoked_at                    DATETIME NULL,

  UNIQUE KEY uq_active_attestation
    (attestor_user_id, target_kind, target_id, kind, revoked_at),
  KEY idx_target_active
    (target_kind, target_id, kind, revoked_at, created_at DESC),
  KEY idx_attestor_active
    (attestor_user_id, kind, revoked_at, created_at DESC),
  KEY idx_attestor_target
    (attestor_user_id, target_kind, target_id)
);
```

Notes:
- `target_kind` ∈ `{user_profile, validator_card, project_card,
  creator_card}` — enforced in application code, not as a DB
  enum (allows future extension without ALTER TABLE)
- `kind` ∈ `{vouch, stand_behind}` for V1. Dispute is a separate
  table — it has stake + panel mechanics that don't fit this
  shape.
- `weight_at_time` stores the weight as computed at the time of
  attestation; decay is applied at read-time. Storing the snapshot
  avoids re-deriving with shifting reliability scores.
- `attestation_order_in_target` records the position in the
  sequence — the first stand-behind on a target is order=1,
  the second is order=2, etc. Used for first-mover credit.
- `revoked_at` is part of the unique key — operators can revoke
  and re-attest; only one active row per (attestor, target, kind)
  exists at a time.
- `idx_target_active` is the hot read path (rendering attestation
  rosters); compound key on (target_kind, target_id, kind,
  revoked_at) supports the filter + order by created_at desc.

### 5.2 `bcc_attestor_reliability_cache` schema

```sql
CREATE TABLE bcc_attestor_reliability_cache (
  attestor_user_id                  BIGINT UNSIGNED PRIMARY KEY,
  operator_reliability              DECIMAL(5, 4) NULL,
  consensus_reliability             DECIMAL(5, 4) NULL,
  early_read_accuracy               DECIMAL(5, 4) NULL,
  reliability_standing              VARCHAR(20) NOT NULL DEFAULT 'newly_active',
  total_attestations                INT UNSIGNED NOT NULL DEFAULT 0,
  stand_behind_slots_total          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  stand_behind_slots_used           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  stand_behind_slots_graduated      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  is_dormant                        TINYINT(1) NOT NULL DEFAULT 0,
  last_activity_at                  DATETIME NULL,
  computed_at                       DATETIME NOT NULL,

  KEY idx_standing (reliability_standing),
  KEY idx_dormant  (is_dormant)
);
```

Notes:
- Read-model cache; recomputed nightly + on attestation events.
  Synthesis layer queries this for the operator's own reliability;
  the source-of-truth computation runs against
  `bcc_trust_attestations`.
- `operator_reliability` and the two sub-tracks are numeric only
  to power the self-mirror; per the asymmetric-display rule, they
  never expose to third-party API responses.
- `reliability_standing` is the only field exposed to public API
  surfaces. V1 ships with public surface SELF-ONLY (per §J.10
  open question 14); V2 expansion opens the positive standings
  publicly.

### 5.3 Migration of existing `bcc_endorsements` rows

```sql
INSERT INTO bcc_trust_attestations (
  attestor_user_id, target_kind, target_id, kind,
  weight_at_time, attestation_order_in_target, created_at, revoked_at
)
SELECT
  e.user_id,
  CONCAT(
    CASE
      WHEN p.post_type = 'peepso-validator' THEN 'validator_card'
      WHEN p.post_type = 'peepso-project'   THEN 'project_card'
      WHEN p.post_type = 'peepso-creator'   THEN 'creator_card'
      ELSE NULL
    END
  ) AS target_kind,
  e.page_id AS target_id,
  'vouch' AS kind,
  e.weight AS weight_at_time,
  -- order computed per target in a follow-up update
  0 AS attestation_order_in_target,
  e.created_at,
  e.revoked_at
FROM wp_bcc_endorsements e
JOIN wp_posts p ON p.ID = e.page_id
WHERE
  p.post_type IN ('peepso-validator', 'peepso-project', 'peepso-creator')
  AND e.revoked_at IS NULL OR e.revoked_at IS NOT NULL;
```

Followup: a second pass computes `attestation_order_in_target`
per (target_kind, target_id) via `ROW_NUMBER() OVER (PARTITION BY
target_kind, target_id ORDER BY created_at)`.

Migration is idempotent — re-runs are safe; existing rows are
skipped via the unique key.

## 6. Service layer

### 6.1 `AttestationService` (refactor from `EndorsementService`)

Methods:
- `cast(int $attestorUserId, string $targetKind, int $targetId,
       string $kind, ?string $contextNote): array`
  — full eligibility gate (tier, throttle, fingerprint, fraud
  orchestrator, bandwidth for stand_behind), insert, audit, side
  effects (notification, read-model invalidation)
- `revoke(int $attestorUserId, int $attestationId): array`
  — owner check, idempotent, audit on real transition only
- `reaffirm(int $attestorUserId, int $attestationId): array`
  — owner check, resets decay-curve baseline (updates
  reaffirmed_at), audit, notification to target
- `getForTarget(string $targetKind, int $targetId,
                array $options): array`
  — read-model query; supports pagination + sort modes
- `getReliabilityFor(int $attestorUserId): array`
  — self-only; surfaces full numeric + sub-tracks

### 6.2 Synthesis layer extensions

The existing `EndorsementWeightCalculator` extends to the new
attestation model. The synthesis math runs at read-time:

- `DecayResolver::decayedWeight(float $weight, DateTime
  $createdAt, DateTime $now): float` — pure function applied at
  read time
- `ReputationScoreSynthesis::compute(string $targetKind,
  int $targetId): int` — composes attestation weights + on-chain
  bonuses + dispute history, applies §J.4 caps and multipliers,
  returns 0–100
- `DivergenceStateClassifier::classify(string $targetKind,
  int $targetId): string` — returns one of `untested /
  well_regarded / poorly_regarded / polarizing / disputed`
- `OperatorReliabilityComputer::compute(int $userId): array`
  — returns sub-track breakdown for self-mirror

### 6.3 Hardening services

Each is a focused single-responsibility service:

- `WalletAgeWeighter::weightFor(int $attestorUserId): float`
  — returns a [0.5, 1.0] multiplier based on the attestor's
  oldest wallet-link age. Plumbed into the synthesis layer.
- `NewAccountReputationVelocityCap::cap(int $targetId,
  int $rawScore): int` — read-time clamp; first 60 days of
  entity existence, score ≤ 50.
- `ReciprocityPenaltyResolver::weightFor(int $attestorUserId,
  string $targetKind, int $targetId): float` — detects mutual
  attestations and returns the baseline-only multiplier.
- `CohortOverlapDampener::weightFor(int $attestorUserId,
  string $targetKind, int $targetId): float` — graduated dampener
  based on overlap percentage; pure read-side computation.
- `MetaDisputeFilerEligibility::canFile(int $filerUserId,
  int $targetUserId): bool` — static eligibility gate; cohort
  members can't file meta-disputes against cohort members.

Each service composes into a single `AttestationWeightPipeline`
that runs at read time. The pipeline produces the final weight
that feeds Reputation Score synthesis.

## 7. REST surfaces

Already locked in `api-contract-v1.md` §4.20. Phase 1
implementation conforms exactly; deviations require contract
amendment first per §A4.

## 8. Frontend surfaces

### 8.1 Components

- `AttestationActionCluster.tsx` — the four primary actions +
  utility cluster on cards and profiles
- `StandBehindAllocationIndicator.tsx` — the `2/5` cue + the
  drop-one-to-add-one modal when slots are full
- `AttestationRoster.tsx` — the roster surface, supports the
  divergence-rendering split mode
- `ReputationSummaryPanel.tsx` — score + standing + tier chip +
  standing chip + negative-state badges
- `SelfMirrorReliabilityView.tsx` — operator-only; numeric +
  sub-tracks + trend lines
- `PrePublicationStateNotification.tsx` — the 24-hour heads-up UX
- `ContestedStateExplainer.tsx` — the self-only "why am I in this
  state" view
- `OnboardingTrustLayerSteps.tsx` — the four onboarding cards

### 8.2 Onboarding four-card flow (load-bearing per §2.9)

The cards land in the existing onboarding sequence. Copy is
locked here — implementation matches verbatim:

**Card 1 — "What this is."**
> Blue Collar Crypto is an operator intelligence network. Operators
> back, dispute, or stay silent about other operators. The platform
> synthesizes those signals into a reputation graph counter-parties
> consult before trusting someone with capital, code, or governance.

**Card 2 — "Three things you can do."**
> **Vouch** — "I think this operator is competent." Abundant — back
> as many as you want.
>
> **Stand Behind** — "I'm putting my reputation on this operator's
> work." Scarce. You only have a few high-conviction slots; spend
> them deliberately.
>
> **Dispute** — "This needs panel review." Formal. Requires evidence
> and panel adjudication.

**Card 3 — "How reputation works."**
> Your **reputation** grows from what others say about you.
> Your **reliability** is your own track record as a judge of
> others.
>
> Both grow slowly. Both are durable.
>
> **Absence of attestation is not a negative signal.** Most
> operators are silent — that's normal and acceptable. The graph
> doesn't expect you to attest on any schedule. Cast attestations
> only when you have genuine judgment to offer.

**Card 4 — "Cast your first vouch."**
> Walks the user through vouching for a sample operator card. The
> feeling of the action is the lesson. Card-3 teaching prevents
> the user from interpreting their own initial low Reputation
> Score as a negative signal — silence is normal.

### 8.3 Empty-state copy

Profile with zero attestations:
> *"This operator hasn't been backed yet. Their reputation will
> form as they participate."*

Card with zero attestations:
> *"No attestations on file yet. Be the first to vouch."*

**Never** `Vouched by 0` or `0 stand-behinds` — the numeric zero
invites the "no vouches = bad" reading that the constitution
explicitly rejects (§2.9).

## 9. Critical-risk mitigation checklist

The 10 Critical items from `trust-attestation-risk-assessment.md`
§5 are non-negotiable Phase 1 deliverables. Status tracked here:

| # | Item | Phase 1 owner | Status |
|---|---|---|---|
| 1 | Wallet-age weighting | `WalletAgeWeighter` (Wk 4) | TBD |
| 2 | New-account Reputation Score velocity cap | `NewAccountReputationVelocityCap` (Wk 4) | TBD |
| 3 | Reciprocity penalty | `ReciprocityPenaltyResolver` (Wk 4) | TBD |
| 4 | Cohort-cluster overlap dampener | `CohortOverlapDampener` (Wk 4) | TBD |
| 5 | Meta-dispute filer eligibility | `MetaDisputeFilerEligibility` (Wk 4) | TBD |
| 6 | Pre-publication notification for polarizing/disputed | `PolarizationTransitionNotifier` (Wk 4) | TBD |
| 7 | Self-only "why am I in this state" view | `ContestedStateExplainer` (Wk 4) | TBD |
| 8 | Onboarding "absence is not negative" teaching | Onboarding Card 3 (Wk 3–4) | TBD |
| 9 | No "0 attestations" numeric display | Empty-state copy (Wk 3) | TBD |
| 10 | Empty-state copy on profiles | Empty-state copy (Wk 3) | TBD |

A Phase 1 PR cannot land without explicit verification of the
items it touches. The Phase 1 final review checks all 10.

## 10. Acceptance criteria

Phase 1 is "done" when **all** of the following are true:

1. PHPStan level 8 clean on `bcc-trust`
2. Architecture guardrails clean
3. Contract guard clean (`scripts/arch-guardrails.sh
   --with-contract`)
4. TypeScript strict check clean on `bcc-frontend`
5. All 10 Critical items above marked Done with verified test
   coverage
6. Migration of existing `bcc_endorsements` rows runs cleanly on
   a production-data snapshot
7. **60-second comprehension test passes.** Three fresh users
   complete the four-card onboarding and answer correctly in
   their own words: "what's the difference between Vouch and
   Stand Behind?" The answer mentions scarcity or limited slots
   without prompting.
8. **Counter-party trust-decision test passes.** A viewer
   unfamiliar with the platform lands on an entity card and
   decides within 60 seconds whether to trust the entity, citing
   concrete signals from the card.
9. **No formula / synthesis-mechanic leaks.** Manual review of
   every visible UI surface confirms no synthesis mechanic
   surfaces in copy, tooltip, hover, or admin view.
10. **No engagement-economy hooks.** Manual review confirms no
    leaderboard, no "you haven't attested in N days" prompt, no
    badge-progress public surface, no real-time discovery feed.
11. Closed-network testing dashboards render the §4 quantitative
    signals with live data
12. Closed-network protocol document complete

## 11. Adjustments if scope shifts

The 4-week timeline assumes single-engineer pace. If team
capacity changes:

- **Two engineers in parallel:** ~2.5 weeks. Backend (weeks 1–2)
  and frontend (weeks 2–3) can substantially parallelize once the
  REST surfaces are stable.
- **Three engineers:** ~2 weeks. Hardening services parallelize
  with frontend builds.
- **Half-time engineer or interruptions:** ~6 weeks. Sequence
  unchanged; gates between weeks become explicit milestones.

If the deadline forces scope reduction:

- **Cut last:** items 1–10 Critical from §9 — these are
  non-negotiable per the risk assessment.
- **Cut first (in order):** self-mirror reliability view (Wk 3
  Day 4 — can ship as basic standing badge in Phase 1, full
  sub-track breakdown in Phase 1.5); pre-publication notification
  UX (can ship as bell-only in Phase 1, full 24-hour heads-up
  in Phase 1.5); attestation-roster divergence-rendering split
  (can ship simple sorted list in Phase 1, split view in Phase
  1.5).

Phase 1 must not ship without items 1–10 from §9. Cutting any
of them creates either a security gap (Sybil, cartel, laundering)
or a cultural gap ("no vouch = bad" interpretation drift, contested-
state retention) that the architecture relies on.

## 12. Open questions to resolve during planning

These are tunable parameters that need final values before
implementation begins. Some are inherited from the constitution's
§J.10; some surface in this plan.

1. **Decay curve exact shape.** 0d=1.00, 90d=0.90, 365d=0.70,
   3yr=0.40, asymptote=0.20 is the design intent. Lock the
   functional form (linear / piecewise / exponential) — recommend
   piecewise linear for read-time performance.
2. **Operator Reliability formula weights.** Phase 1 implements
   the formula structure; the weights tune in closed-network
   testing.
3. **Stand Behind slot ladder.** Elite 7 / Trusted 5 / Neutral 3
   is the design intent. Confirm before implementation; values
   stored in a config constant for tuning.
4. **First-call protection count.** 5 vs. 10 — recommend 5 for
   Phase 1, can extend in Phase 1.5 if early data shows
   death-spiral risk persisting.
5. **Wallet-age weight multiplier curve.** Bottom 0.5×, top 1.0×;
   piecewise based on wallet-age in months. Concrete breakpoints
   TBD in Phase 1 design pass.
6. **Reciprocity-penalty exact threshold.** When does A→B + B→A
   trigger baseline-weight? Recommend any mutual pair, no
   minimum threshold.
7. **Cohort-overlap dampener threshold.** 50% overlap is the
   design intent; tune in Phase 1 against actual graph density.
8. **Polarizing-state divergence cutoff.** What variance among
   high-reliability attestors triggers `polarizing`? TBD in
   Phase 1; closed-network testing tunes.
9. **Dormancy threshold for display dimming.** 60 days is the
   design intent; tighter 30-day threshold for slot-counting
   purposes per §1.4 hardening.
10. **Notification cooldown for trust events.** 5-minute
    coalescing window per the §I1 pattern; specific cooldowns
    per event type TBD.

These resolve in the Phase 1 design pass at the start of Week 1.
The values are stored in named constants so closed-network testing
can tune them without schema changes.

---

## Cross-references

- Constitution: `docs/trust-attestation-layer.md`
- Wire contract: `docs/api-contract-v1.md` §4.20
- Threat model: `docs/trust-attestation-risk-assessment.md`
- Closed-network testing protocol: `docs/trust-attestation-closed-network-protocol.md`
  (to be drafted alongside Phase 1 build)
- Existing endorsement service:
  `app/Domain/Core/Services/EndorsementService.php`
- Existing fraud orchestrator:
  `app/Domain/Core/Services/EndorsementFraudOrchestrator.php`
- Existing throttle: `bcc-core/src/Security/Throttle.php`
- Existing audit log: `app/Domain/Core/Security/AuditLogger.php`
- Pattern registry: `docs/pattern-registry.md` Trust Engine

---

**This document is the scope-frozen Phase 1 plan. Deviations
during implementation require plan amendment, not silent
reinterpretation.**
