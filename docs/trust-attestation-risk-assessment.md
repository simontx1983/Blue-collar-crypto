# Trust Attestation Layer — Threat Model & Behavioral Risk Assessment

**Status:** Pre-implementation analytical foundation. Locked 2026-05-13.
**Companion to:** `docs/trust-attestation-layer.md` (constitution),
`docs/api-contract-v1.md` §4.20 (wire contract).
**Owners:** Phase 1 implementation planning reads from this doc.
Closed-network testing instruments against this doc's watch signals.

---

## Purpose

The constitution locks the *what* and *why* of the Trust Attestation
Layer. This document locks the *failure modes* — both adversarial
(intentional misuse by attackers) and behavioral (emergent human
dynamics that produce unhealthy culture without anyone acting in bad
faith). The two are intentionally treated with equal weight. A
graph that's technically Sybil-resistant but socially produces a
chilling effect on participation has failed.

This is not a code-level threat-model. It is an emergent-systems
analysis: how do real humans, in real numbers, interacting with
real incentives, drift toward outcomes the design did not intend?

Four deliverables, captured in §3, §4, §5, §6:

- **Threat model** — adversarial profiles + residual risks
- **Behavioral risk map** — emergent dynamics + cultural drift vectors
- **Implementation-priority hardening recommendations** — what
  Phase 1 must land to address the highest-priority risks
- **Closed-network testing watch signals** — what to instrument
  and observe during the small-cohort pilot before public release

---

## Methodology

Each item is analyzed in five dimensions:

1. **Profile** — what the threat or dynamic looks like in practice
2. **Constitutional defenses** — which existing mechanics address it
3. **Residual risk** — what the existing mechanics fail to fully
   prevent
4. **Watch signals** — what to instrument; what observed pattern
   indicates the risk is materializing
5. **Hardening priority** — Critical (must land in Phase 1),
   Important (should land in Phase 1), Watch-only (instrument and
   observe; harden if signals trigger)

Items are not ranked within their category — risks compound and
interact, and prioritization must be considered holistically.
Critical items are surfaced in the §5 implementation-priority
synthesis regardless of where they appear.

---

## Part 1 — Adversarial threat model

Ten named attacker profiles. Each represents intentional misuse;
the analyst posture is "an adversary is trying to game the graph
for personal benefit or to damage another operator."

### 1.1 — Sybil farms

**Profile.** An attacker creates many accounts to vouch for a
target, inflating its Reputation Score. The accounts may share
infrastructure (devices, IPs, wallets, fingerprints) or may be
distributed across infrastructure to evade dedup.

**Constitutional defenses:**
- Tier-gating (must be ≥ neutral standing to vouch; new accounts
  default to lower tier with no automatic positives)
- Wallet-link requirement for attestation eligibility
- Fingerprint dedup (one vouch per device per target per cooldown)
- Throttle (per-user daily vouching cap)
- Stand Behind requires tier ≥ neutral plus bandwidth caps
- Diversity multiplier (1.3× for cross-network attestations) —
  homogeneous attestor pools get only baseline weight
- Reliability weighting (new accounts have no track record, low
  attestation weight)
- First-call protection (new operators don't get reliability
  credit on their first 5–10 attestations either way)

**Residual risk.** Long-burn Sybil cultivation — the attacker
creates accounts early, lets them age, participates organically
to earn ≥ neutral standing, accumulates baseline reputation, then
deploys the cohort to vouch for a target. The mitigations are
strongest against fresh-account flood attacks; weakest against
aged, distributed, methodically cultivated cohorts.

**Watch signals.**
- Cluster of accounts created within similar windows that
  subsequently exhibit similar attestation patterns
- Sudden burst of attestations on a target from accounts that have
  never previously attested
- Network-proximity anomalies surfacing through the diversity
  multiplier (the math is doing the work — instrument it as a
  visible analytics metric, not just a synthesis input)
- Wallet-age distributions on attestor cohorts for a target —
  homogeneous "all wallets created within 30 days of each other"
  is a flag

**Hardening priority: Critical.** Sybil resistance is the
foundational defensibility of the graph; failure here invalidates
the whole product.

**Specific implementation guidance:**
- **Wallet-age weighting.** Attestation weight scales by wallet
  age — a wallet linked 30 days ago carries less weight than one
  linked 18 months ago. Pure function applied at read-time; no
  schema change beyond surfacing wallet-link age in the
  attestation weight calculation.
- **New-account Reputation Score cap.** No entity can exceed
  Reputation Score 50 in its first 60 days, regardless of
  attestations received. Forces aged-account cultivation patterns
  to additionally pay time — making them more visible to
  observation.
- **Cohort-cluster surfacing as a Phase 1 admin signal.** The
  diversity-multiplier math already detects cohort overlap; expose
  it as an analytics view so moderators can investigate suspicious
  attestor pools.

### 1.2 — Cartel backing rings

**Profile.** A tight group of Elite operators mutually back each
other to maintain reputation hegemony. May be explicit (private
coordination) or emergent (friend-group dynamics). Often invisible
in single-attestation analysis; visible only as a graph-topology
pattern.

**Constitutional defenses:**
- 40% Elite-tier weight cap on aggregate contribution to a single
  entity's Reputation Score
- Diversity multiplier (cross-network attestations weighted higher)
- Slot graduation requires demonstrated high reliability over
  time; can't be allocated rationally to cartel members
- Anti-viral-by-design rules limiting Elite-only feed dominance

**Residual risk.**
- Cartel adapts: Elite operators back each other AND back a curated
  set of neutral-tier "pets" to satisfy the 40% cap
- Specialty-cartel formation: in a small specialty (e.g., a single
  chain) the Elite operators may genuinely be the only qualified
  voices, making cartel detection statistically difficult
- Meta-dispute system weaponized by the cartel against external
  operators who challenge it

**Watch signals.**
- High clustering coefficient among Elite operators (graph-theory
  metric — instrument this)
- Reciprocal attestation patterns at unusual rates (A→B + B→A +
  A→C + C→A repeating across an Elite cohort)
- Cartel-pet patterns: Elite operators uniformly backing the same
  neutral-tier accounts
- Disproportionate dispute-filing rate from a specific cohort
  against operators outside that cohort

**Hardening priority: Critical.** Cartel formation is the
single failure mode most likely to undermine the platform's
defensibility — if counter-parties believe the graph is captured,
they won't consult it.

**Specific implementation guidance:**
- **Reciprocity penalty.** If A attests for B AND B attests for
  A, both attestations get baseline weight only (no diversity
  bonus). Detects reciprocal backing without forbidding it — the
  attestations still count, they just don't compound.
- **Cohort-cluster overlap dampener.** The diversity multiplier
  is currently binary (cross-network = 1.3×, in-network =
  baseline). Refine to a graduated dampener: if the attestor's
  prior attestation set overlaps significantly with the target's
  existing attestor set (>50% overlap), apply a 0.8× weight
  multiplier. Cartel members backing cartel targets get *less*
  weight, not just non-bonus weight.
- **Meta-dispute filer eligibility constraint.** Filers in active
  high-clustering cohorts cannot file meta-disputes against
  cohort members. Prevents the cartel from using the
  hard-accountability backstop to silence dissent within its
  ranks.

### 1.3 — Coordinated disputes

**Profile.** A group coordinates to file multiple disputes against
a target, triggering the `Under Review` badge and damaging
reputation regardless of dispute merit.

**Constitutional defenses:**
- Dispute requires evidence + stake + tier-gate (≥ trusted)
- Cooldown between disputes on the same target
- Frivolous-dispute outcome reduces filer reliability
- Polarizing classification requires divergence among
  HIGH-reliability attestors only — low-reliability dispute bombs
  don't trigger it
- Substantive-divergence trigger filters out brigading

**Residual risk.**
- `Under Review` badge persists for dispute lifetime even when
  dispute is frivolous — temporary reputation damage is real
- High-reliability coordinated dispute filers can manufacture
  Polarizing classification
- Serial frivolous disputes (one resolves, next files) can keep
  the badge persistent

**Watch signals.**
- Disputes against the same target from accounts with overlapping
  prior behavior
- Time-clustered dispute filings (multiple within 24h)
- Filer accounts with low attestation history but suddenly active
  in disputing

**Hardening priority: Important.**

**Specific implementation guidance:**
- **Reputation-damage cap from frivolous disputes.** If N
  consecutive disputes against a target are dismissed, subsequent
  disputes from those filers require an elevated evidence
  threshold to trigger the `Under Review` badge.
- **Visible filer track record on disputes.** The dispute page
  surfaces the filer's dispute history (filed N, dismissed M,
  upheld K). Self-disciplining: a filer with a poor track record
  has visible credibility cost.
- **Group cooldown.** Cooldown applies to the network cluster of
  filers, not just individual filers. If accounts in the same
  cluster file repeatedly against the same target, cooldown
  extends across the cluster.

### 1.4 — Dormant-slot abuse

**Profile.** An attacker farms accounts to elite tier, uses their
Stand Behind slots, then allows the accounts to go dormant — gaming
the dormancy-dimming rules.

**Constitutional defenses:**
- Activity-gated display: dormant attestors' contributions dim in
  rosters AND deduct from aggregate counts on targets
- 60-day default dormancy threshold

**Residual risk.**
- Sub-threshold activity: attacker keeps accounts JUST active
  enough to avoid dormancy (e.g., one post every 55 days)
- Slot reservation: even dimmed-display slots stay allocated in
  the data layer; the attacker reserves bandwidth supply across
  many accounts

**Watch signals.**
- Accounts with very thin activity but full Stand Behind slot
  allocations
- Activity patterns clustered just inside dormancy threshold
  boundaries

**Hardening priority: Important.**

**Specific implementation guidance:**
- **Sub-threshold detection.** Accounts with activity occurring
  only near the dormancy boundary get extra scrutiny — flagged
  for moderator review, not auto-actioned.
- **Tighter dormancy threshold for attestation purposes.** Use
  60 days for display dimming (a user-friendly cadence for casual
  operators) but 30 days of *attestation-related* activity for
  slot-counting purposes. A user who's casually active gets the
  display benefit; a user who never engages with the
  attestation graph loses slot supply quickly.

### 1.5 — Reputation laundering

**Profile.** A known-bad operator creates a new identity, gets
backed by sock puppets or naïve early adopters, achieves clean
reputation. Cross-platform identity arbitrage is the hardest case.

**Constitutional defenses:**
- First-call protection prevents new accounts from instant
  high-reliability
- Wallet-link tracking (if attacker reuses wallet, link surfaces)
- Fingerprint dedup
- Tier-gating prevents fresh accounts from immediately attesting

**Residual risk.**
- Fresh wallet, fresh device, fresh handle — no immediate
  technical link to the prior identity
- Cross-chain identity arbitrage (a wallet on one chain may not
  link to a prior wallet on another chain)
- Co-option of weak operators (genuine new accounts that the
  laundering attacker exploits)

**Watch signals.**
- New accounts achieving significant attestations rapidly after a
  similar account departed or was disgraced
- Account creation patterns following major dispute resolutions
- Network reconstruction patterns: new account's first attestations
  coming from the same operators who backed a recently-departed
  account

**Hardening priority: Critical.** Reputation laundering directly
undermines the dispute system's accountability function — if
disgraced operators can re-enter cleanly, dispute outcomes have no
permanence.

**Specific implementation guidance:**
- **Account-age visibility on entity cards.** New accounts read
  as new accounts — surface `Account created N days ago` on cards.
  Prevents the rapid-build laundering pattern from appearing
  visually equivalent to established operators.
- **Reputation Score velocity cap.** No entity can reach >50
  Reputation Score in <60 days regardless of attestations. Forces
  laundered identities to additionally pay time, making them
  observable to moderation.
- **Cross-platform identity correlation deferred to V2.** Hard
  problem; out of V1 scope but flagged as known residual risk.
  Watch signal: anecdotal reports of laundering should be tracked
  for post-V1 priority assessment.

### 1.6 — Selective-vouch manipulation

**Profile.** An attestor strategically vouches only for entities
likely to succeed (already-vouched targets, established
operators), building reliability through bandwagoning while
avoiding genuine judgment calls.

**Constitutional defenses:**
- Early-conviction multiplier (21st+ vouches get 0.5× reliability
  credit)
- Stand Behind bandwidth cap (forces deliberate allocation)
- Early Read sub-track surfaces independent-discovery operators

**Residual risk.**
- Late-vouch operators still build SOME reliability — slower path
  but viable
- Bandwagon detection is statistical, not absolute

**Watch signals.**
- Operators whose attestations consistently fall in the 6th–20th+
  position
- Operators with very late attestation patterns who maintain
  high Consensus Reliability

**Hardening priority: Watch-only.** Bandwagoning is mostly a
behavioral risk rather than a technical exploit; the
constitutional mechanics already significantly reduce its reward.

**Self-mirror surfacing.** Operators should see "your attestations
tend to follow consensus rather than lead" in their own mirror —
not as stigma, but as self-knowledge. Pairs with the Early Read
sub-track UX.

### 1.7 — Disagreement brigading

**Profile.** Attackers manufacture controversy on a target by
coordinating disputes or counter-vouches to force `polarizing`
classification.

**Constitutional defenses:**
- Polarizing requires divergence among HIGH-reliability attestors
  only — low-reliability brigades don't trigger
- Dispute process cost
- Substantive-divergence trigger

**Residual risk.**
- A coordinated cohort of high-reliability operators can
  manufacture polarization
- A single bad-faith high-reliability actor can poison consensus

**Watch signals.**
- Polarizing classifications appearing on entities that had
  consensus 30 days prior
- High-reliability disputes filed in temporal proximity from
  network-adjacent accounts

**Hardening priority: Important.**

**Specific implementation guidance:**
- **Polarizing stickiness in both directions.** Once an entity is
  Polarizing, it requires sustained consensus to clear; transient
  manufactured divergence doesn't immediately reclassify. But
  conversely, manufactured polarization that resolves as
  frivolous disputes should cause faster un-polarizing — the
  classification reverts when the divergence proves not
  substantive.
- **Cluster-aware polarization detection.** If the high-reliability
  divergence is from a single tight cohort (not actually
  independent opinion), reduce its weight in the polarizing
  computation. Independence of the diverging opinions is the
  point; a cohort's disagreement is not independent.

### 1.8 — Discovery-gaming attempts

**Profile.** An operator deliberately attempts to be "first" on
many Stand Behind targets to game the Early Read badge.

**Constitutional defenses:**
- First-mover protection (first 5 stand-behinds don't get the
  multiplier)
- Retrospective surfacing (no real-time race-to-be-first)
- Early Read badge requires sustained pattern
- Stand Behind bandwidth cap (limited slots to be "first" with)

**Residual risk.**
- An attacker with high tier and full bandwidth can still
  distribute first stand-behinds widely
- Sticky-first calls on declining trajectories: attestor goes
  early on many targets, some pan out, some don't

**Watch signals.**
- Operators with high Stand Behind churn (frequent revocations
  to free slots for new "first" picks)
- Stand Behind patterns concentrated in pre-consensus targets
  with subsequent low validation rate

**Hardening priority: Important.**

**Specific implementation guidance:**
- **Revocation cooldown on slot availability.** When a Stand
  Behind is revoked, the slot doesn't free for 7 days — preventing
  rapid churn for discovery-gaming.
- **First-mover credit single-use per target.** If an operator
  revokes a first-mover stand-behind and re-attests later, they
  lose the first-mover credit. The "first" position credits
  exactly once per target lifetime.

### 1.9 — Elite capture dynamics

**Profile.** Not coordinated cartel — emergent. Elite operators
naturally concentrate attestations among themselves and ignore
non-Elite operators. Not malicious; just self-reinforcing.

**Constitutional defenses:**
- 40% Elite-weight cap
- Slot graduation requires reliability + reaffirmation, not
  incumbency
- Diversity multiplier rewards cross-network attestations
- Tier-mobility paths exist (operators can rise from neutral to
  trusted to elite via participation + reliability)

**Residual risk.**
- Even with 40% cap, Elite per-attestation weight is high
- Status anxiety: non-Elite operators may not even try to attest
  for entities Elite hasn't validated
- Elite tier self-reinforcing (only Elite operators get vouched
  by Elite operators)

**Watch signals.**
- Concentration index on Elite-attestor target distribution
- Tier-mobility rates over time (should be positive)
- Neutral-tier attestation rates (should grow as Layer 1 grows)

**Hardening priority: Critical.** This is the most dangerous
*emergent* failure mode because no individual actor is doing
anything wrong, yet the graph centralizes.

**Specific implementation guidance:**
- **Tier-promotion criteria audit.** Tier promotion must NOT
  require Elite backing as a sufficient condition. Should be
  participation + accuracy + reliability — verify Phase 1
  implementation enforces this.
- **Tier-mobility visibility in self-mirror.** Operators see their
  own progress toward next-tier-promotion: "X more attestations
  with current accuracy to qualify for Trusted." Gives a clear,
  actionable path that doesn't require Elite gatekeeping.
- **Cross-tier attestation discoverability.** The Directory's
  default sort should not surface Elite operators
  disproportionately. Audit during Phase 1 design.

### 1.10 — Reputation farming behavior

**Profile.** An operator's primary platform behavior IS
attestation — they exist to game the metrics. Not necessarily
malicious; just degenerate culture.

**Constitutional defenses:**
- Layer 0 substrate is separately weighted (mostly invisible to
  the trust graph)
- Reliability gates future weight
- Bandwidth caps + first-call protection

**Residual risk.**
- Reputation farmers produce noise in the graph and set bad
  cultural example
- "Attestation cadence" expectations may form among the community

**Watch signals.**
- Operators whose activity is overwhelmingly attestation-related
  (90%+ vouches/stands/disputes; <10% Layer 0 posts)
- Operators with extreme attestation volumes

**Hardening priority: Watch-only.**

**Self-mirror nudge.** Surface as a self-mirror dimension: "your
activity is heavily attestation-focused — consider engaging Layer
0 culture." Not a stigma; just visible self-knowledge.

---

## Part 2 — Behavioral risk map

Eleven named emergent dynamics. No bad actors required; these are
the natural human patterns the design must survive at scale.

### 2.1 — Conformity

**Profile.** Operators default to existing consensus opinions
rather than forming their own. The graph becomes high-resolution
echo chamber.

**Constitutional defenses:**
- Early Read sub-track rewards independent calls
- Early-conviction multiplier on Stand Behind reliability
- Diversity multiplier rewards cross-network attestation

**Residual risk.**
- Most operators may stay in consensus mode regardless of
  incentive structure (basic conformity is a deep human trait)
- Consensus mode is the rationally safe choice for risk-averse
  operators

**Watch signals.**
- Distribution of stand-behinds — heavy concentration on a small
  set of operators = high conformity
- Long-tail of less-attested operators getting near-zero
  attention

**Hardening priority: Important.**

**Specific implementation guidance:**
- **Directory "Recently emerging" filter.** A sort that surfaces
  operators with fewer attestations but recent activity — gives
  counter-parties a path to find under-attested operators without
  the platform algorithmically pushing them.

### 2.2 — Passive consensus-following

**Profile.** Operators wait for Elite signal before forming
opinions. Different from conformity in that the operator *would*
form an opinion if they didn't have a perceived authority to
defer to.

**Constitutional defenses:**
- Early Read multiplier
- Retrospective surfacing of first-movers
- 40% Elite-weight cap

**Residual risk.**
- Late-mover behavior is a stable equilibrium even with the
  multiplier

**Watch signals.**
- Distribution of attestation timing — if median attestation
  comes after the 20th, the platform has converged on late-mover
  behavior
- Time-to-first-attestation distribution on new entities

**Hardening priority: Watch-only.**

**Self-mirror nudge.** Surface "your last 10 vouches were all
post-consensus" in the operator's own mirror. Gentle nudge toward
self-awareness; not stigma.

### 2.3 — Conflict avoidance

**Profile.** Operators avoid filing disputes even when warranted,
preferring silent disengagement.

**Constitutional defenses:**
- Dispute requires tier ≥ trusted; intentional high bar
- Panel adjudication protects honest filers
- Reliability impact only on patterns of dismissed disputes, not
  isolated cases

**Residual risk.**
- Dispute is socially expensive — filers expose themselves
- Bad behavior may go unsignaled even when many operators perceive it

**Watch signals.**
- Total dispute volume on the platform — should be non-zero
- Drop-to-zero or near-zero dispute rate over time = bad signal
- Disputes filed in private conversations but not on-platform
  (qualitative, harder to instrument)

**Hardening priority: Important.**

**Specific implementation guidance:**
- **Dispute education in onboarding.** The four-card onboarding
  flow should include concrete examples of when disputes are
  appropriate, not just the mechanics. Lower the perceived social
  cost of filing.
- **Visible "this is what panel adjudication looked like" record.**
  After dispute resolution, the outcome + reasoning is visible
  (anonymized as appropriate). Makes the process feel less
  arbitrary and more accountable.

### 2.4 — Prestige chasing

**Profile.** Operators chase badge status rather than accurate
judgment. The badges become the game.

**Constitutional defenses:**
- Asymmetric-display rule (positive-only badges)
- No leaderboards anywhere
- Early Read badge requires retrospective validation, not
  real-time activity
- Anti-viral-by-design constitutional principle

**Residual risk.**
- Badge mention in social conversation creates implicit ranking
- Operators may strategize around badge thresholds

**Watch signals.**
- Badge mentions in Layer 0 posts (operator referencing other
  operators' badges)
- Sudden change in attestation behavior approaching badge
  thresholds

**Hardening priority: Important.**

**Specific implementation guidance:**
- **Visual de-emphasis of badges.** Badges are subtle UI elements,
  not flexing surfaces. Design-system enforced limit on badge
  visual prominence relative to identity (avatar + handle).
- **No badge-progress public surface.** "How close is X to
  Highly Reliable" is NEVER publicly visible. Only the standing
  itself appears; progress toward the next standing is self-only.

### 2.5 — Fear of disputes

**Profile.** Operators don't file disputes for fear of meta-dispute
retaliation or social blowback.

**Constitutional defenses:**
- Filer protected by panel adjudication
- Reliability impact only on UPHELD frivolous-dispute pattern
- Meta-dispute is the malice backstop, not a default consequence

**Residual risk.**
- Chilling effect from a single high-profile meta-dispute outcome
- Social retaliation outside the platform (DMs, off-platform
  pressure)

**Watch signals.**
- Dispute initiation rates by operator over time
- Precipitous drop after a high-profile meta-dispute outcome
- Disputes withdrawn shortly after filing

**Hardening priority: Important.**

**Specific implementation guidance:**
- **Panel-adjudication transparency.** Outcomes visible with
  reasoning so it's clear what counts as frivolous. Operators
  understand the actual standard, not the imagined one.
- **Dispute private until panel acceptance.** A filed dispute
  doesn't surface publicly to the target or to any other operator
  until the panel accepts it for review. Reduces fear of public
  exposure for honest filings that may turn out to be
  miscalibrated.

### 2.6 — Social intimidation

**Profile.** High-reliability operators implicitly intimidate
lower-reliability operators into deference, regardless of
intentional behavior.

**Constitutional defenses:**
- Numeric reliability isn't visible to other viewers
- Standing badges are positive-only
- No leaderboards
- Asymmetric-display rule generally

**Residual risk.**
- Badge visibility still creates implicit hierarchy
- High-reliability operators carry inherent social weight

**Watch signals.**
- Attestation rates by reliability standing — if `newly_active`
  operators attest at much lower rates than `highly_reliable`,
  deference dynamic is forming

**Hardening priority: Watch-only.**

**Onboarding messaging.** Emphasize that Newly Active is the
starting state and there's no shame in it. Surface examples of
high-quality attestations from Newly Active operators.

### 2.7 — Status anxiety

**Profile.** Operators experience anxiety about their own
reliability standing, lose confidence in attesting at all.

**Constitutional defenses:**
- Numeric reliability is self-only
- Gradual change rule prevents one-shot demotion
- First-call protection prevents early death-spiral
- Positive-only public badges prevent public stigma

**Residual risk.**
- Self-mirror shows the numbers; operators may obsess
- Trend-direction anxiety even when current state is healthy

**Watch signals.**
- Community discussion about reliability anxiety
- Operators reducing attestation activity after viewing their
  self-mirror

**Hardening priority: Important.**

**Specific implementation guidance:**
- **Self-mirror design for self-knowledge, not obsession.** Show
  trend direction (improving / steady / softening) rather than
  high-frequency numeric updates. Update cadence: weekly at most,
  not real-time. Operators get a calibrated view, not a stock
  ticker.
- **Reliability standing changes via notification, not via
  surprise.** When an operator's standing is about to change,
  they receive notice before the change publishes. Asymmetric
  push rules (per the contract) handle the up-direction; the
  down-direction is bell-only with explanatory copy.

### 2.8 — Lazy attestation patterns

**Profile.** Operators batch-vouch carelessly (e.g., vouching for
everyone they've met without thinking).

**Constitutional defenses:**
- Throttle (per-day vouching cap)
- Reliability weighting
- Diversity multiplier rewards thought-out attestations

**Residual risk.**
- Low-effort vouches still count for baseline weight
- Bulk-batching within the throttle window is still possible

**Watch signals.**
- Spike-attestation patterns (e.g., 10 vouches in 5 minutes from
  a single operator)
- Bulk attestations on accounts the operator has never previously
  interacted with on Layer 0

**Hardening priority: Important.**

**Specific implementation guidance:**
- **Spike-detection weight dampener.** If a user casts >5 vouches
  in <1 minute, those vouches get baseline weight only (no
  reliability multiplier). Doesn't forbid the behavior, just
  doesn't reward it.
- **Vouch confirmation step.** The Vouch button requires a brief
  confirmation gesture (a hold-to-confirm, or a 1-tap modal).
  Adds enough friction to prevent reflex-vouching without making
  the action heavy.

### 2.9 — "No vouch = bad" interpretation drift

**Profile.** Users start interpreting the *absence* of vouches as
a negative signal. Creates implicit negative voting that the
design explicitly rejected.

**Constitutional defenses:**
- None at the mechanics level (this is purely interpretive drift)

**Residual risk.**
- High. Cultural drift over time is the most likely path to this
  failure mode, and the constitution has no direct mechanic to
  prevent it.

**Watch signals.**
- Attestation patterns concentrating on already-attested operators
  (new operators getting zero attestations beyond initial sweep)
- Community discussion treating "no vouches" as a negative
- Operators retiring profiles after extended periods of zero
  attestations

**Hardening priority: Critical.** This is the most dangerous
*non-adversarial* failure mode because it could re-introduce
Reddit-style implicit negative voting through the back door of
interpretation.

**Specific implementation guidance:**
- **Onboarding explicit teaching.** The four-card onboarding flow
  must include a card explicitly stating: *"Absence of attestation
  is not a negative signal. Most operators are silent and at the
  floor — that is the resting state."*
- **Empty-state copy on profiles.** A profile with zero
  attestations renders: *"This operator hasn't been backed yet.
  Their reputation will form as they participate."* Inviting,
  not stigmatizing. (Already in heuristic 9 of the constitution;
  this assessment elevates it from heuristic to risk-mitigation.)
- **Onboarding-funnel attestation prompt.** New operators get a
  prompt to receive their first attestation from someone in their
  network during onboarding — not automatic, but a "request a
  vouch from someone you know" affordance that gets them off
  zero quickly. Reduces the visible zero-state.
- **No "0 attestations" numeric display on profiles.** Use empty
  state copy instead of `Vouched by 0` or `0 stand-behinds`.
  Numeric zeros invite the "no vouches = bad" reading; empty
  state copy redirects it.

### 2.10 — Overreliance on Elite signaling

**Profile.** Counter-parties consulting cards skip past
attestation rosters and check only "do Elite operators back this."
Cognitive shortcut, not bad intent.

**Constitutional defenses:**
- Divergence rendering (high-reliability supporters AND detractors
  rendered)
- 40% Elite-weight cap forces cross-tier validation for high
  scores
- Reputation Score is composite, not Elite-only

**Residual risk.**
- Humans default to authority signals regardless of platform
  design
- Hard to instrument directly

**Watch signals.**
- A/B testing card layouts during closed-network testing may
  surface engagement patterns
- Qualitative interview: do counter-parties report relying on
  Elite signals primarily?

**Hardening priority: Important.**

**Specific implementation guidance:**
- **Card UX deliberately surfaces the full roster.** Elite
  backers aren't visually amplified relative to other tiers.
  Cross-tier roster diversity is a visible quality of the card,
  not just a math quality.
- **Polarizing state surfaces when Elite consensus diverges from
  neutral consensus.** A specific divergence-pattern visible on
  the card prompts counter-parties to read the camps, not just
  the Elite verdict.

### 2.12 — Judgment fatigue / emotional exhaustion (long-horizon)

**Profile.** The platform asks operators to evaluate people,
publicly back people, interpret trust states, navigate contested
entities, and make reputation decisions on an ongoing basis. Over
long time horizons, even high-quality operators may experience the
*cumulative* emotional cost of constant judgment — leading to
over-cautious behavior, passive disengagement, hesitancy to attest
even when warranted, emotional fatigue from social consequences,
and eventual withdrawal from active participation.

This is distinct from the individual behavioral risks (status
anxiety §2.7, fear of disputes §2.5, conflict avoidance §2.3,
prestige chasing §2.4) because it is the *compound effect* of all
of them together, over months or years, on the operators the
platform most needs to retain. A platform that wears out its best
operators has a graph-quality decay problem that no individual
mechanic prevents.

**Constitutional defenses:**
- Asymmetric-display rule reduces public stigma
- Soft accountability (no clawback, no death spiral) reduces per-
  attestation emotional cost
- Bandwidth model on Stand Behind forces deliberate-not-frequent
  high-conviction decisions
- Vouch is low-cost; not every interaction requires conviction
- Layer 0 retention engine gives operators non-judgment surfaces
  to engage on

**Residual risk.**
- The cumulative emotional cost is real regardless of per-
  attestation cost being low
- Long-tenure operators may experience "reputation work" as labor
- High-quality operators may withdraw before low-quality operators,
  causing graph quality to degrade asymmetrically

**Watch signals.**
- Attestation rate per operator over their tenure on the platform
  — declining trend for long-tenure high-reliability operators is
  the headline signal
- Retention rates by reliability standing — if `highly_reliable`
  operators churn at higher rates than `consistent`, fatigue is
  driving it
- Self-reported emotional load in interviews — direct qualitative
  measurement matters here
- Layer 0 engagement vs. Layer 1 attestation ratio over time per
  operator — if active Layer 1 operators are quietly migrating to
  Layer-0-only behavior, that's fatigue
- Sudden disengagement clusters — multiple high-reliability
  operators going quiet around the same time

**Hardening priority: Important.**

**Specific implementation guidance:**
- **Attestation is never required.** No mechanic, anywhere, ever,
  pressures operators to attest at any cadence. Silence is a
  legitimate state. This is already encoded as a principle but
  must be explicitly enforced in design reviews.
- **Self-mirror surfaces fatigue dimensions.** The self-mirror
  should include trend lines showing the operator their own
  engagement pattern over time — not as judgment, but as
  self-awareness. "Your attestation cadence has been roughly the
  same for 6 months — that's healthy" is the kind of language to
  reach for.
- **No "you haven't attested in N days" reminder notifications.**
  Ever. The notification taxonomy explicitly excludes prompts to
  attest. If a future PR proposes one, it's rejected by reference
  to this risk profile.
- **Tenure-aware onboarding messaging.** New operators are taught
  that long-term silence is normal and acceptable; not every
  operator participates in attestation at all, and that's fine.
  Reduces the implicit pressure that "good operators attest."

### 2.11 — Emotional reactions to contested states

**Profile.** Operators see their own card go `polarizing` or
`disputed` and react defensively (filing meta-disputes,
deactivating, retaliating, leaving platform).

**Constitutional defenses:**
- Polarizing framed as intelligence, not condemnation
- Substantive-divergence trigger filters out brigading
- Asymmetric public-display rule generally

**Residual risk.**
- Regardless of framing, having your card show "smart operators
  disagree about you" is emotionally hard
- Retention risk for operators in transition states

**Watch signals.**
- Operator retention rates after a card transitions into
  `polarizing` or `disputed` state
- Account deactivations clustered around state transitions
- Increased meta-dispute filings from operators whose cards
  recently transitioned

**Hardening priority: Critical.** Retention loss after legitimate
state classifications would create both a moral and a product
problem — operators with contested reputations leaving means the
graph loses signal density at exactly the moments it matters most.

**Specific implementation guidance:**
- **Pre-publication notification.** 24-hour heads-up before a
  card transitions into `polarizing` or `disputed`: *"Your card
  is approaching a Polarizing classification. Here's the
  divergence pattern. Here's what it means. Here's what you can
  do."* Gives the operator time to prepare emotionally and engage
  constructively, not react defensively.
- **Self-only `why am I in this state` view.** A self-mirror
  surface that explains the classification in plain English with
  the underlying signals visible: *"Five high-reliability
  operators have stood behind you. Two have filed disputes. The
  reliability of the dispute filers is high. This pattern
  triggers Polarizing classification."* Helps the operator
  contextualize rather than catastrophize.
- **In-product guidance for contested operators.** Link to
  "what does Polarizing mean and what should I do?" guidance
  directly from the operator's own card view. Treats the state
  as a moment to engage, not a verdict to suffer.

---

## Part 3 — Existential vulnerabilities

Risks that, if materialized, would invalidate the platform's core
defensibility. Distinguished from "important but addressable"
risks by their potential to undermine the entire trust graph.

### 3.1 — Reciprocal Elite cartel formation (residual)

Even with the 40% cap, slot graduation, diversity multiplier, and
meta-dispute backstop, a sufficiently disciplined Elite cohort
could form a cartel that pets the math (curating "pet" neutral-tier
accounts to satisfy the 40% requirement). The hardening
recommendations under §1.2 reduce this risk significantly but do
not eliminate it. If cartel formation is detected post-launch,
moderation intervention is required — there is no purely
algorithmic defense at the limit.

### 3.2 — Gradual reputation laundering via cross-platform identity churn (V2+)

A bad-faith operator who maintains discipline can launder their
reputation across V1 by simply ageing a new account, attesting
casually for 60+ days, and gradually receiving attestations from
naïve operators. Cross-platform identity correlation would
significantly raise this attack's cost but is out of V1 scope.
This is a known residual risk; closed-network testing should
specifically monitor for it.

### 3.3 — "No vouch = bad" interpretation drift

The most likely failure mode for a healthy graph to develop an
unhealthy culture. The constitution has no direct mechanic to
prevent the drift; the mitigations are educational and presentational
(onboarding messaging, empty-state copy, no numeric-zero display).
If the drift is observed in closed-network testing, more
aggressive intervention may be needed — possibly including a
public statement of platform philosophy that explicitly addresses
the interpretation.

### 3.4 — Cultural emergence of an attestation-cadence norm

If a community norm develops that operators are expected to
vouch/stand-behind/dispute at certain rates, the platform
inherits an engagement-cadence pressure that contradicts the
anti-viral-by-design constitution. The pressure would re-create
engagement-economy dynamics through *culture* rather than
*algorithms* — which is harder to detect and harder to revert
than algorithmic engagement-economy because the failure mode is
distributed across the user base, not centralized in a recommender
that can be retuned.

Five named cultural emergences to specifically watch for during
closed-network testing:

1. **Attestation guilt culture.** Operators feel bad for not
   attesting "enough." Language pattern: "I really should vouch
   for X" — where the "should" is felt as moral obligation, not
   genuine judgment. Signals: support tickets / community
   conversation expressing guilt about attestation cadence;
   self-reported emotional pressure in interview rounds.

2. **Silence-shaming.** Operators are socially criticized for not
   participating in the attestation graph. Language patterns:
   "X never vouches for anyone — what does that say about them?"
   or "good operators should be visible in the roster." Signals:
   Layer 0 post content referring critically to other operators'
   attestation silence; community discussion treating non-
   attestation as a character flaw.

3. **Pressure to constantly weigh in.** A norm forms that
   operators are expected to have a public opinion on every
   notable entity. Language patterns: "where do you stand on
   ProjectX?" treated as a demanding social question rather than
   a polite one. Signals: rising attestation rates correlating
   with rising platform-attention events (e.g., dispute filings,
   major Layer 0 conversations); operators attesting under
   apparent social pressure rather than independent judgment.

4. **Prestige-maintenance behavior.** High-reliability operators
   attest to maintain their visibility / public standing rather
   than from genuine judgment. Language patterns: "I haven't
   been seen on the graph lately, I should put my name on
   something." Signals: attestation activity correlated with
   *time-since-last-attestation* (operators attesting to break
   a silence rather than because something happened); attestation
   volume rising as a function of social-visibility decay.

5. **"Good operators should always be active" norms.** A norm
   forms that high-quality participation requires sustained
   activity, conflating *engagement* with *judgment quality*.
   Language patterns: "X used to be a great judge of operators
   but they're not active anymore." Signals: operators expressing
   self-doubt about their continued legitimacy when they reduce
   attestation activity; community treating dormant
   high-reliability operators as "fallen."

**Why this matters.** Each of these cultural emergences would
silently re-introduce engagement-economy pressure through user
expectation rather than platform mechanic. Because no mechanic is
causing the pressure, no mechanic can directly relieve it. The
mitigations are:

- Editorial / community-management posture (the platform's voice
  explicitly affirms silence as legitimate)
- Onboarding content (taught: no cadence expectation exists)
- Interview-cycle vigilance (catch the norm forming in
  conversation before it spreads)
- Public platform statement of principle if drift is detected

**Hardening priority: Critical** (elevated from Watch-only after
the 2026-05-13 expansion). The constitution names anti-viral-by-
design as a principle; this cultural-emergence risk is the most
likely path for the principle to be violated by emergent
behavior rather than by feature work.

### 3.5 — Asymmetric attrition of high-quality operators (judgment fatigue compound)

The compound effect of §2.12 judgment fatigue plus §3.4 cadence
pressure plus §2.11 contested-state emotional reactions, over
months and years, may produce asymmetric attrition: the operators
the graph most needs (high-reliability, high-conviction, long-
tenure judges) leave at higher rates than low-quality operators.
Because each individual mechanism has been mitigated as much as
the constitution can mitigate it, the residual is *cumulative
exhaustion* that no single feature change addresses.

The mitigation is *operational* rather than *architectural*:
ongoing community management, editorial voice that affirms
legitimate disengagement, interview-cycle vigilance, and product
willingness to scale back surfaces if attrition signals trigger.

Closed-network testing should include explicit retention cohorts
tracking long-tenure high-reliability operators specifically.

---

## Part 4 — Closed-network testing watch list

Signals to instrument before the small-cohort pilot. Each pairs
to one or more of the threats / behaviors above.

### Quantitative signals

- **Tier-mobility rates over time.** % of operators moving
  neutral → trusted, trusted → elite per quarter. Healthy
  baseline TBD; precipitous drop signals tier-promotion gatekeeping.
- **Attestation timing distribution per target.** Histogram of
  "what position was each stand-behind in the sequence." A
  long-tail toward late positions (median > 20th) signals
  consensus-following behavior dominating.
- **Network clustering coefficient among Elite operators.**
  Standard graph-theory metric. Rising trend = cartel formation
  risk.
- **Concentration index for Elite-attestor target distribution.**
  How many distinct entities do Elite operators back? Falling
  trend = Elite capture dynamics.
- **Total dispute volume per week.** Should be non-zero;
  precipitous decline signals conflict avoidance has taken over.
- **Polarizing classification transitions.** How often do
  entities move into and out of `polarizing`? High transition
  rate signals brigading; very low rate signals the
  classification is undertuned.
- **Attestation rate by reliability standing.** Newly Active
  vs. Consistent vs. Highly Reliable. If Newly Active rate is
  much lower, social intimidation is forming.
- **Reputation Score velocity for new entities.** How fast do
  entities go from 0 → 30 → 50 → 70? Anomalously fast trajectories
  flag laundering or Sybil attack.
- **Stand Behind slot churn rate.** Average revocations per
  operator per month. Anomalously high churn flags
  discovery-gaming.
- **Operator retention after state transitions.** Specifically
  retention rates for operators whose cards entered `polarizing`
  or `disputed` in the prior 30 days.
- **Attestation:Layer-0 activity ratio per operator.** Healthy
  baseline TBD; >90% attestation activity flags reputation
  farming.
- **Attestation rate per operator over tenure** (§2.12). Track
  per-operator monthly attestation cadence over their full
  tenure. Declining trend among long-tenure high-reliability
  operators is the judgment-fatigue headline signal.
- **Retention by reliability standing** (§2.12). If
  `highly_reliable` operators churn at higher rates than
  `consistent`, fatigue is driving asymmetric loss of the
  operators the graph most needs.
- **Sudden disengagement clusters** (§2.12). Multiple
  high-reliability operators going quiet around the same time
  signals either cohort fatigue or culture-pressure event.
- **Attestation-rate correlation with social-visibility decay**
  (§3.4 cadence-pressure). If attestation activity rises as a
  function of time-since-last-attestation, prestige-maintenance
  behavior is forming.
- **Attestation-rate correlation with platform-attention events**
  (§3.4 cadence-pressure). If attestation rates spike around
  dispute filings or trending Layer 0 conversations, operators
  are attesting under social pressure rather than independent
  judgment.

### Qualitative signals

- **Community discussion of badges.** Operators referencing
  other operators' badges in posts. Especially watch for
  competitive framing ("X has Early Read but Y doesn't").
- **Community discussion of "no vouch = bad" interpretation.**
  Listen for phrases that treat absence of attestation as
  pejorative. The single most important qualitative signal in
  this list.
- **Attestation-cadence norm emergence — five-pattern watch
  list** (§3.4 expanded 2026-05-13):
  - **Attestation guilt culture** — "I really should vouch for X"
    framed as moral obligation
  - **Silence-shaming** — "good operators should be visible in
    the roster"; criticism of non-participating operators
  - **Pressure to constantly weigh in** — "where do you stand on
    ProjectX?" as demanding social question
  - **Prestige-maintenance behavior** — "I haven't been seen on
    the graph lately, I should put my name on something"
  - **"Good operators should always be active" norms** —
    conflating engagement with judgment quality
  Any of these in community conversation is a P1 signal — the
  anti-viral-by-design constitutional principle is being
  challenged by emergent culture rather than by feature work.
- **Judgment fatigue indicators** (§2.12). Self-reported
  emotional load in interview rounds; operators describing
  attestation as feeling like work; long-tenure high-reliability
  operators describing wanting to "step back from the
  reputation stuff."
- **Reliability-anxiety reports.** Support tickets, community
  posts about anxiety over own reliability standing.
- **Dispute-fear reports.** Operators describing fear of filing
  disputes even when warranted.
- **Meta-dispute outcomes' chilling effect.** After a
  high-profile meta-dispute, watch for dispute-rate drops from
  operators outside the meta-dispute's cohort.

### Behavioral playback

The closed-network testing protocol (a separate doc — TBD)
should include scheduled interview rounds with operators in
distinct positions (newly active, consistent, highly reliable,
recently disputed, recently polarizing). The interviews surface
emotional UX outcomes the quantitative signals cannot.

---

## Part 5 — Implementation-priority synthesis

Recommendations grouped by Phase 1 priority. Critical items
should land in Phase 1 build; Important items should land in
Phase 1 or Phase 1.5; Watch-only items are deferred unless their
watch signals trigger.

### Critical — must land in Phase 1

1. **Wallet-age weighting** (§1.1 Sybil farms) — attestation
   weight scales by wallet-link age.
2. **New-account Reputation Score velocity cap** (§1.1, §1.5) —
   max 50 in 60 days.
3. **Reciprocity penalty** (§1.2 cartels) — back-and-forth
   attestations get baseline weight only.
4. **Cohort-cluster overlap dampener** (§1.2 cartels) — refines
   the diversity multiplier with a graduated dampener for
   high-overlap pairs.
5. **Meta-dispute filer eligibility constraint** (§1.2 cartels) —
   cohort members can't meta-dispute their own cohort.
6. **Pre-publication notification for `polarizing` / `disputed`
   transitions** (§2.11) — 24-hour heads-up.
7. **Self-only `why am I in this state` view** (§2.11) —
   plain-English explanation for the operator.
8. **Onboarding "absence of attestation is not negative"
   explicit teaching** (§2.9) — load-bearing for cultural
   integrity.
9. **No "0 attestations" numeric display** (§2.9) — empty-state
   copy only.
10. **Empty-state copy on profiles** (§2.9) — heuristic 9
    elevated to risk-mitigation status.

### Important — should land in Phase 1 or 1.5

11. **Account-age visibility on entity cards** (§1.5) — surface
    "created N days ago."
12. **Reputation-damage cap from frivolous disputes** (§1.3) —
    threshold escalation after dismissed-dispute pattern.
13. **Visible filer track record on disputes** (§1.3) — surface
    on dispute page.
14. **Group cooldown on dispute filing** (§1.3) — cohort-level
    cooldown.
15. **Sub-threshold dormancy detection** (§1.4) — flag for
    moderator review.
16. **Tighter dormancy threshold for slot-counting** (§1.4) —
    30-day attestation-activity threshold.
17. **Polarizing-stickiness bidirectional rule** (§1.7) —
    sustained-consensus required to clear; faster un-polarizing
    on frivolous resolution.
18. **Cluster-aware polarization detection** (§1.7) — independence
    of diverging opinions matters.
19. **Revocation cooldown on Stand Behind slots** (§1.8) — 7-day
    delay before slot frees on revocation.
20. **First-mover credit single-use per target** (§1.8) —
    revocation forfeits the first-mover position.
21. **Tier-mobility visibility in self-mirror** (§1.9) — operators
    see their own promotion path.
22. **Directory "Recently emerging" filter** (§2.1) —
    discoverability for under-attested operators.
23. **Dispute education in onboarding** (§2.3) — lower perceived
    social cost.
24. **Panel-adjudication transparency** (§2.3, §2.5) — outcomes
    + reasoning visible.
25. **Dispute private until panel acceptance** (§2.5) — reduces
    fear of public exposure.
26. **Visual de-emphasis of badges** (§2.4) — design-system
    enforcement on badge prominence.
27. **No badge-progress public surface** (§2.4) — progress is
    self-only.
28. **Self-mirror weekly-cadence design** (§2.7) — trend
    direction, not real-time ticker.
29. **Spike-detection weight dampener** (§2.8) — bulk-vouching
    gets baseline weight only.
30. **Vouch confirmation step** (§2.8) — light friction to
    prevent reflex-vouching.
31. **Onboarding-funnel attestation prompt** (§2.9) — request a
    vouch from someone you know to escape zero-state.

### Watch-only — instrument and observe

32. **Selective-vouch self-mirror surfacing** (§1.6)
33. **Reputation farming self-mirror dimension** (§1.10)
34. **Late-mover self-mirror nudge** (§2.2)
35. **Newly Active visibility examples** (§2.6)
36. **Card UX for cross-tier roster diversity** (§2.10) — A/B
    candidate during closed-network testing

---

## Part 6 — Phase 1 plan integration

The Phase 1 scope-freeze plan (separate doc, to be written next)
must explicitly incorporate items 1–10 from §5 as required
deliverables. Items 11–31 are strongly recommended for Phase 1 but
acceptable to defer to Phase 1.5 with explicit cause. Items 32–36
are post-launch tunings; no Phase 1 work required beyond
instrumenting their watch signals.

The closed-network testing protocol (also separate doc, to be
written before code lands) must build dashboards for every signal
in §4.

---

## Cross-references

- Constitution: `docs/trust-attestation-layer.md`
- Wire contract: `docs/api-contract-v1.md` §4.20
- Existing trust-engine patterns: `pattern-registry.md` Trust Engine
- Existing dispute mechanics: `app/Domain/Disputes/`
- Existing anti-fraud orchestrator:
  `app/Domain/Core/Services/EndorsementFraudOrchestrator.php`
- Existing throttle: `bcc-core/src/Security/Throttle.php`
- Existing audit log: `bcc-trust/app/Domain/Core/Security/AuditLogger.php`

---

**This document is the operational risk surface for the Trust
Attestation Layer. Phase 1 implementation planning conforms to
items 1–10 of §5, or amends this doc with explicit justification.**
