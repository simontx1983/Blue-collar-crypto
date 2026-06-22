# Trust Attestation Layer

**Status:** Foundational product architecture. Locked 2026-05-13.
**Owners:** All future feature work in this domain conforms here, or amends this doc.
**Companion:** `docs/api-contract-v1.md` §J encodes the wire-level
contracts that follow from this design.

---

## TL;DR — what BCC actually is

Blue Collar Crypto is an **operator intelligence network**.

Operators (validators, builders, creators, traders, contributors) do
work in crypto. Other operators back them, dispute them, or stay
silent. The platform synthesizes those signals into a defensible,
hard-to-fake reputation graph that counter-parties consult before
choosing who to trust with capital, code, or governance weight.

The card is the entity. The feed is evidence. The graph is the product.

If a brand-new user can't understand this in 60 seconds, the design has
failed. Every primitive, every surface, every label in this document
exists in service of that comprehension test.

### Anti-viral-by-design — constitutional principle

The platform optimizes for **judgment quality, operator
intelligence, and signal density.** It does NOT optimize for
engagement velocity, virality, or attention capture. This
distinction is constitutional, not stylistic — future feature work
that reintroduces engagement-economy mechanics (vanity metrics,
streak-style retention hooks beyond §O1, viral-share incentives,
controversy-as-feed-ranking, real-time leaderboards, attention-
chasing notifications) violates the architecture even if it
performs better against a short-term engagement KPI.

Concrete invariants this principle enforces (each already encoded
elsewhere in this doc; named here so they can be checked as a set):

- No leaderboards anywhere
- No real-time "who's casting now" stream
- No prediction-market UX language
- The Floor ranks by recency + actor-reliability, never by
  controversy or engagement velocity
- Negative signals are surfaced as intelligence (pull) not as
  virality drivers (push)
- Synthesis mechanics that shape the graph (caps, multipliers,
  weight rules) are invisible to users

If a future PR is hard to reconcile with this principle, the PR
needs an explicit constitutional amendment to this document
before it can land. The principle precedes any individual feature.

---

## §J.0 The three-layer architecture

Three layers, strict separation, single direction of dependency.
Layers cannot reach across to each other except via the documented
seams.

### Layer 0 — Evidence substrate

- **Surfaces:** feed posts, comments, reactions (solid / fire), blog
  entries, group activity, status updates
- **UX register:** social, conversational, casual
- **Purpose:** operators *demonstrate* their work. Layer 0 is the raw
  material the trust graph consumes.
- **Does NOT directly grade humans.** Reactions on posts are content
  signals that drive feed ranking, conversation, virality. They do
  not contribute weight to the trust graph.
- **Retention function:** Layer 0 is the platform's emotional moat.
  Conversation, memes, belonging, operator culture — these are what
  keep users on-platform long enough to participate in the graph.
  Without Layer 0 the platform is LinkedIn for crypto, which is a
  dead product. **Layer 0 is the retention engine; Layers 1 + 2 are
  the moat.**

### Layer 1 — Identity attestations

- **Surfaces:** profile (`/u/[handle]`), entity cards (`/v/`, `/p/`,
  `/c/`)
- **UX register:** deliberate, accountable, weighted
- **Primitives (exactly three at V1 — see §J.1):**
  - **Vouch** — abundant, low/medium-conviction positive signal
  - **Stand Behind** — scarce, high-conviction positive signal
    with reputation-bandwidth cost
  - **Dispute** — formal adversarial challenge, panel-adjudicated
- **Purpose:** users express deliberate, accountable opinions about
  identities. Every Layer 1 action is a public reputation
  attestation, weighted by the attestor's own standing.
- **Single source of trust-graph weight.** No Layer 0 action
  contributes to the trust graph. Only Layer 1 attestations do.

### Layer 2 — Derived intelligence

- **Surfaces:** Reputation Score, Operator Reliability,
  Confidence gauge (validator-specific), Builder Reputation
  (creator/project-specific), Reliability Standing, dispute-history
  rollups, attestation-divergence indicators
- **UX register:** computed, declarative, never user-castable
- **Purpose:** synthesis of Layer 0 + Layer 1 + on-chain signals
  into navigable intelligence. The headline numbers and badges
  counter-parties consult.
- **Not user-actionable.** Users cannot directly cast a Confidence
  score or a Reliability rating. They cast Layer 1 attestations,
  and the synthesis layer produces the readouts.

### Direction of dependency

```
        Layer 2 (derived intelligence)
              ▲
              │ synthesizes
              │
     Layer 0  │  Layer 1
   (evidence) │  (attestations)
              │
              └── both feed Layer 2
```

Layer 0 → Layer 2: low-weight contextual signals (post velocity,
conversation density, repo activity proxies if pulled in).
Layer 1 → Layer 2: high-weight direct attestations.
**Layer 0 NEVER reaches Layer 1.** No reaction on a post produces an
attestation. The two are deliberately decoupled.

---

## §J.1 The three primitives

### Vouch

**Semantic:** "I think this entity is competent."

**Conviction tier:** low to medium.

**Economic cost:**
- Throttle-gated (per-user daily cap)
- Tier-gated (must be ≥ neutral standing to vouch)
- Fingerprint-deduplicated (one vouch per device per target per
  cooldown window)
- Weighted by the attestor's own Reputation Score and Operator
  Reliability at attestation time

**Bandwidth:** unlimited. A user can vouch for as many entities as
they wish, subject to throttles. The abundance is the point.

**Targets:** `target_kind ∈ {user_profile, validator_card,
project_card, creator_card}`. Same verb, same mechanic, four
target shapes.

**Revocation:** revocable at any time. Revocation lands an audit
row. The attestor's Reputation Score is not punished for revoking;
revocation is a healthy signal of changing assessment.

**Endorse replaces by Vouch.** The existing `EndorsementService`
generalizes into the attestation pipeline. Existing endorsement rows
migrate as `kind=vouch, target_kind=*_card`. The wire-level rename
is cosmetic; the conceptual unification is what matters.

### Stand Behind

**Semantic:** "I'm putting my reputation on this entity's work."

**Conviction tier:** high.

**Economic cost — the bandwidth model:**

Each operator has a **Stand Behind bandwidth** — a small, scarce
allocation of high-conviction slots. Adding a new Stand Behind when
your slots are full forces a choice: either revoke an existing one,
or accept a diluted weight across all of them.

Bandwidth scales with the attestor's Trust Tier:

| Trust Tier | Stand Behind slots |
|---|---|
| Elite | 7 |
| Trusted | 5 |
| Neutral | 3 |
| Caution / Risky | 0 (cannot stand behind) |

**Why bandwidth, not clawback:**

The earlier draft of this layer proposed *clawing back* reputation
when an entity you stood behind was later disputed. That model was
rejected because it would chill attestation supply — operators who
might back someone would stay silent rather than risk being punished
for an honest call.

The bandwidth model achieves the same accountability without the
chilling effect. Standing behind everyone means standing behind no
one — your weight per stand-behind is finite and you must allocate
it deliberately. The act of *choosing* who to stand behind is itself
the high-signal moment, not the post-hoc punishment if they fail.

**Slot reclamation:** decayed stand-behinds (>50% of decay curve
elapsed) automatically free their slot. Old stand-behinds fade
naturally; new ones get the freed allocation. Operators don't have
to manually revoke just to make room. Time recycles bandwidth.

**Long-term graph health — three additional refinements** (locked
2026-05-13 after a calcification/ghost-graph pressure test):

1. **Activity-gated display.** When an attestor is dormant (no
   platform activity in 60+ days), their attestations are visually
   dimmed in the roster AND deducted from the aggregate
   `stand_behind_count` shown on the target. Slots remain allocated
   in the underlying table — when the attestor returns their
   support resumes intact — but the *display layer and aggregate
   count* treat them as inactive. Ghost backers stop inflating
   present-day reputation without forcing manual cleanup.

2. **Soft renewal nudge.** Every 6 months, an attestor receives a
   notification: *"Your Stand Behind on @target is 6 months old.
   Still backing them?"* One-tap reaffirm (resets decay, refreshes
   timestamp), one-tap revoke (frees the slot), or ignore
   (continues to decay naturally). Hard expiry is explicitly
   rejected — it forces operators into portfolio-manager mode.
   Soft renewal preserves intent without making maintenance a chore.

3. **Slot graduation.** Operators who consistently reaffirm AND
   maintain high Operator Reliability earn additional slots over
   time, capped at +3 above their tier baseline. An Elite operator
   with 100+ accurate attestations might unlock 8th–10th slots.
   This is the key anti-calcification valve: high-reliability
   operators get more *supply* to back new entrants without needing
   to revoke existing picks. New blood gets backed by trusted
   voices; trusted voices don't have to choose between old and new
   commitments.

The combined effect: **slots are tied to ongoing engagement, not
one-time allocation.** "Old money" reputation calcifies into ghosts
(dimmed); active reputation re-affirms (refreshed); high-reliability
operators earn *more* room to back new operators (supply unlocks).
Calcification, ghost backers, and insider entrenchment all
addressed without introducing a new primitive.

**Targets:** same as Vouch.

**Visible affordance:** the Stand Behind button on a card shows
the attestor's current allocation state — e.g. `Stand Behind · 2/3`.
This is the load-bearing UX cue. Users instantly see that stand-
behinds are scarce; that scarcity teaches the meaning of the action
without copy.

### Dispute

**Semantic:** "This entity has done something I want a panel to
review."

**Conviction tier:** formal adversarial challenge.

**Economic cost:**
- Higher tier-gating than Vouch (must be ≥ trusted standing to file)
- Process cost: requires written claim + evidence references
- Cooldown: cannot file repeat disputes on the same target without
  new evidence
- Stake: the filer commits some reputation; if the panel finds the
  dispute frivolous, the stake is forfeited

**Outcome path:**
- Panel of qualifying operators reviews
- Decision: upheld / dismissed / partially upheld
- Audited outcome feeds Layer 2 derived intelligence
- Repeat unfounded disputes from the same filer reduce that filer's
  Operator Reliability

**Targets:** `target_kind ∈ {user_profile, validator_card,
project_card, creator_card}`. Profile-scoped disputes are V2 (panel
mechanics extend to operator-level conduct adjudication).

**This is the ONLY formal negative primitive at V1.** There is no
"Doubt" / "Concerns" / "Withhold" verb to mirror Vouch. The absence
of vouches is itself a signal in a reputation network; the only
formal negative path is the slow, evidenced, panel-adjudicated one.

---

## §J.2 The negative-signal surface (without downvotes)

A reputation graph can't be all-positive — that's inflation by design.
But generic negative voting (Reddit-style downvotes) is rejected
because it's brigadable, low-evidence, and turns reputation into a
single-axis popularity metric.

Negative signals at V1 are **derived patterns** the system surfaces
automatically. No human casts them; the synthesis layer detects them.

### Why derived signals, not human-cast downvotes

The principle that drives the rest of this section:

- The presence of disputes carries process cost (filing requires
  evidence + tier-gate + stake)
- The *absence* of consensus among high-reliability operators is
  itself a signal
- The variance of attestation weight surfaces opinion divergence
- The volatility of the derived Reputation Score surfaces
  instability

No brigading vector — you cannot coordinate to trigger a
detected-pattern. No personal attack surface — the system, not a
human, surfaces the issue. Evidence-driven — every signal traces
to real disputes, real attestations, real time-series.

Supplemental entity-level signals that compose with the five-state
classification — `under_review` (real-time flag for an open
dispute), `reputation_volatile` (rapid score swing in a rolling
window), `unresolved_claims_count` (open dispute + open
content-report total) — are specified in
`docs/api-contract-v1.md` §J.8. The five-state classifier below is
the headline; the supplemental fields are additional context the
synthesis layer surfaces alongside it.

### Polarization-as-intelligence — the five-state synthesis

Locked 2026-05-13 after a controversy-as-signal pressure test.

A single "Contested" badge collapses two phenomena that are
actually distinct: a *bad* operator that everyone agrees about, and
a *polarizing* operator that smart operators genuinely disagree
about. These are different intelligence shapes and deserve
different surfaces.

Each entity is classified by the synthesis layer into exactly one
of five derived states (replacing the single contested boolean):

| State | Reputation Score | Engagement | Consensus | Meaning |
|---|---|---|---|---|
| `untested` | Low | Low | n/a | Insufficient signal — not enough attestations to grade |
| `well_regarded` | High | Adequate | High | Safe pick, broad agreement |
| `poorly_regarded` | Low | Adequate | High | Genuinely bad — operators agree this entity is weak |
| `polarizing` | High | High | Low | Smart operators disagree — examine the camps |
| `disputed` | Low | High | Low | Actively contested, multiple open claims, examine evidence |

`polarizing` is the load-bearing addition. It distinguishes
"controversial but credible" from "broadly bad." A polarizing
entity isn't punished; it's *surfaced*. Counter-parties get the
signal that smart operators disagree here and can read the camps.

**Substantive divergence trigger.** The Polarizing classification
requires divergence among **high-reliability** attestors only
(reliability standing ≥ `consistent`). Cheap dispute-bombing from
low-reliability accounts does NOT trigger Polarizing — those
disputes still register in `unresolved_claims_count` but they do
not move the entity into the controversial bucket. This is the
antibody against brigading-via-disagreement: only substantive
disagreement among reliable operators counts.

**Controversy surfaces as INTELLIGENCE, not VIRALITY.** Polarizing
entities can be discovered through Directory sort modes
("Polarizing this week") and may be surfaced in the Floor's
trust-event stream as a *category* of high-signal event — but
the Floor's primary ranking remains recency + actor-reliability,
NOT controversy. The platform does not develop attention-economy
dynamics around controversial entities. Surfacing controversy is
a *user-pull* capability (filter and explore), not a *platform-push*
default.

The platform optimizes for **truth**, not safety. Truth includes
"experts disagree here — you need to think for yourself." A
platform that hides disagreement to seem trustworthy is making the
opposite bet.

---

## §J.3 Soft accountability — three mechanics

Layer 1 attestations must have *some* accountability or they're
costless and therefore meaningless. The three mechanics that produce
accountability without chilling attestation supply:

### J.3.1 Confidence decay

Every attestation has a time-weighted value computed at read-time.

```
weight(now) = weight_at_attestation × decay_curve(age_days)

decay_curve:
  0 days     → 1.00
  90 days    → 0.90
  365 days   → 0.70
  3 years    → 0.40
  asymptote  → 0.20  (never reaches zero — historical signal lingers)
```

**The attestor isn't punished by their old vouches turning out
badly.** The vouches just fade. This is the load-bearing mechanic
for natural reputation freshness.

Decay is also the slot-reclamation mechanic for Stand Behind
(§J.1) — once an attestation's decayed weight crosses 50%, its
bandwidth slot is freed.

### J.3.2 Operator Reliability — the attestor's track record

Per-attestor read-model metric: count of their attestations × the
subsequent outcome of the targets they attested for.

```
operator_reliability =
  (vouches whose targets later disputed-and-upheld → negative weight)
+ (vouches whose targets stayed clean              → positive weight)
+ (vouches whose targets received further vouches  → positive weight)
+ (stand-behinds with same calculus, higher weight)
÷ total attestations
```

Result: a 0.0–1.0 reliability score per operator.

**Future attestation weight is multiplied by reliability.** **Past
attestations are not retroactively debited.** This is the key
distinction from clawback: bad-judgment attestors gradually lose
*future* influence; they're not punished for *past* calls.

**Three protections against death-spiral and stigma** (locked
2026-05-13 after a reliability-visibility pressure test):

1. **First-call protection.** A new operator's first 5–10
   attestations don't count against reliability. The Reliability
   Standing badge stays at `newly_active` until the operator has
   made enough calls for the metric to be statistically
   meaningful. Eliminates the "one bad early call permanently
   brands me" death spiral and removes the pressure to delay one's
   first attestation indefinitely.

2. **Gradual change, not binary transitions.** No single
   attestation outcome can move the operator across a Reliability
   Standing boundary. Transitions require a sustained pattern
   (10+ attestations crossing threshold over a rolling window).
   Prevents the "one bad call ruins my badge" terror; preserves
   the metric's responsiveness for sustained drift in either
   direction.

3. **Asymmetric public display — the load-bearing principle.**
   - The numeric reliability score (`0.73`) is **never** rendered
     to other viewers. Ever. It is a weighting input only.
   - The Reliability Standing badge on other operators' profiles is
     **positive-only.** Public badges: `highly_reliable`,
     `consistent`, `newly_active`. There is **no public `volatile`
     or `unreliable` badge.** Operators whose reliability softens
     simply lose their positive badge; they don't gain a negative
     one.
   - The full numeric reliability, trend direction, and recent
     attestation outcomes are visible **only to the operator
     themselves** — the self-mirror for self-correction, not
     public reckoning.
   - The asymmetry is structurally important: **rewarding good
     judgment > punishing bad judgment.** Same math, asymmetric
     social weight.

   This collapses two failure modes at once: it removes the
   public stigma that would chill attestation supply, AND it
   removes "@phillip's only got 0.62, why should I listen to him?"
   as a debate-shutdown tactic — the number is simply not visible
   for anyone to weaponize.

Surfaced as **Operator Reliability** on the attestor's own profile
in V1 (private to them, visible to no one else, so reliability is a
mirror not a stigma). V2 expansion surfaces the *positive badges*
publicly once the metric has matured enough to be meaningful (~6
months of attestation density); the numeric score remains
self-only forever.

### J.3.2.1 Early Read — the independent-discovery sub-track

Locked 2026-05-13 after an elite-attestor centralization pressure
test. The earlier soft-accountability mechanics all reward "be
right" — but "be right by waiting for consensus" is structurally
easier than "be right by independent discovery." Without an
offsetting reward, game theory pushes everyone toward consensus
alignment and the graph centralizes around Elite opinion. This
sub-track is the antibody.

**Early-conviction multiplier on Stand Behind reliability.** When
the synthesis layer computes reliability credit for a stand-behind
attestation, the credit is multiplied by how early the attestation
was cast relative to consensus emergence on that target:

| Stand-behind order | Reliability-credit multiplier |
|---|---|
| 1st on a target | 2.5× |
| 2nd–5th | 1.5× |
| 6th–20th | 1.0× (baseline) |
| 21st+ | 0.5× (consensus-following) |

Same outcome math (target later proves out or doesn't); but
reliability *credit* scales with how early the attestor saw it.
First movers who are right gain reliability faster than
bandwagoners who are right. First movers who are wrong are still
protected by first-call protection (§J.3.2). The result:
independent discovery becomes a viable path to reliability
standing, distinct from consensus alignment.

**Scope:** the early-conviction multiplier applies to **Stand
Behind only.** Vouches are abundant and low-cost; multiplying
their reliability impact would incentivize vouch-spamming obscure
targets to game discovery. Stand Behind is already scarce via the
bandwidth model — composing the two scarcity layers (limited slots
+ early-credit multiplier) makes the discovery reward expensive to
fake and meaningful to earn.

**Per-attestor split into two sub-tracks** (self-mirror only —
public surface is unified through Reliability Standing):

- **Consensus Reliability** — how often you correctly back
  operators that subsequent attestation flow confirms
- **Early Read Accuracy** — how often your pre-consensus calls
  prove out (subject to the multiplier table above)

The mirror surfaces both so operators can see their own judgment
shape. *"You're a strong Early Read with moderate Consensus
Reliability"* is different from *"You're a strong Consensus
Validator."* Both are legitimate forms of operator intelligence;
the platform celebrates both styles.

**Public surface — the `Early Read` badge** (asymmetric-display
rule preserved). An operator who has consistently identified
operators before consensus formed earns the `Early Read` badge,
visible to other viewers on their profile. There is no negative
counterpart — operators who don't make first-mover calls are simply
consensus-track operators, which is a legitimate style. The
platform doesn't pressure everyone into being a discoverer.

**First-mover protection.** The early-conviction multiplier does
NOT apply to the operator's first 5 stand-behinds. Lottery-ticket
attestations don't shortcut the path; the operator earns into the
Early Read reward path the same way they earn into reliability.

**Retrospective surfacing only.** First-mover events surface on the
Floor *after* subsequent attestations validate the call (typically
days or weeks later — see §J.6). There is no real-time "who's
casting now" stream. The recognition is retrospective, never
race-to-attest.

**No leaderboard, ever.** Both Consensus Reliability and Early
Read Accuracy surface to the operator themselves and as
celebratory badges on profiles. Neither metric appears as a ranked
list. There is no "Top 10 Early Read Operators" page. The metrics
inform self-knowledge and platform-side surfacing; they don't fuel
competitive optimization. This is a constitutional invariant per
the anti-viral-by-design principle above.

### J.3.3 Meta-dispute — the malice backstop

Deliberate malice (coordinated stand-behind rings, vouch-bombing a
friend's card to inflate, vouching for a known scammer) is
addressable via the dispute system pointed at *the attestor's
pattern* rather than an individual entity.

- Filer submits evidence of the pattern
- Panel reviews
- Upheld → the malicious attestor loses Reputation Score AND
  loses Operator Reliability sharply
- Dismissed → no change

This keeps the hard-accountability door open without making it the
default. Deliberate malice can't game the soft mechanics
indefinitely. Honest mistakes carry no penalty.

### The combined posture

**Attesting is cheap to try, slow to lose influence, hard to abuse
deliberately.** Supply stays high, signal stays clean.

---

## §J.4 Anti-inflation mechanics

A trust graph in which everyone is trusted is information-free. The
anti-inflation stack:

1. **No automatic positives.** Joining the platform doesn't earn
   you Reputation Score; doing nothing keeps you at the floor. Most
   accounts are silent and at zero.
2. **Cost-to-attest.** Vouching has a fingerprint dedup, a daily
   throttle, and a tier gate. Stand Behind has a bandwidth cap.
   Dispute has a stake + tier gate + evidence requirement.
3. **Bandwidth caps on stand-behind.** Even an Elite operator can
   only stand behind 7 things at once. The supply of high-
   conviction attestation is finite by design.
4. **Reliability-weighted influence.** Operators whose attestations
   don't bear out gradually lose weight on future attestations.
5. **Decay.** Attestations fade. Old reputation is worth less than
   recent.
6. **Negative signal surface.** Disputes, divergence, volatility,
   unresolved claims — all surface automatically. Inflation is
   self-revealing.
7. **Tier-gating attestation eligibility.** Caution and risky tier
   operators cannot attest at all; their would-be attestations
   produce no weight. The graph defends itself against low-tier
   actors trying to seed weight by attesting.
8. **Elite-tier weight cap — anti-cartelization.** The aggregate
   Elite-tier contribution to any single entity's Reputation Score
   is capped at **40%.** An entity backed exclusively by Elite
   operators mathematically cannot exceed Reputation Score 70 —
   reaching the highest scores requires cross-tier validation. This
   is the structural antibody against the Elite cohort becoming a
   de facto editorial board with sole gatekeeping power over the
   top of the graph. Elite attestations still carry the most weight
   *per attestation*; the cap is on the aggregate contribution to a
   single target, not on individual Elite influence.
9. **Signal-source diversity multiplier.** A vouch from an attestor
   who has never previously attested for anything in the target's
   immediate network gets a **1.3×** diversity multiplier in
   synthesis. Vouches from attestors with significant overlap with
   the target's existing roster get baseline weight. Same number of
   backers, different intelligence value — entities backed by a
   diverse cohort signal broader applicability. Specialization is
   not penalized (a Cosmos validator vouching for other Cosmos
   validators is baseline-weighted, not debited); reaching across
   the graph is additionally rewarded.

### J.4.1 Synthesis invisibility — load-bearing invariant

Locked 2026-05-13. The Elite-weight cap (§J.4 item 8), the
diversity multiplier (item 9), the early-conviction multiplier
(§J.3.2.1), and every other synthesis-shaping mechanic in this
document are **invisible to users.** They never surface in:

- UI copy, tooltips, hover states, or onboarding cards
- Admin dashboards visible to operators
- API response fields exposed to clients (the math runs server-
  side; only the *outputs* — Reputation Score, Reliability
  Standing, divergence state — are exposed)
- Warnings like "this entity's score is suppressed because…" or
  "your attestation weight is capped because…"
- Marketing copy or platform messaging

The synthesis layer **quietly shapes the graph.** Users perceive
outcomes (a Reputation Score number, a divergence-state badge, a
trust-event in their feed). They do not perceive — and the
platform does not advertise — the rules that produce those
outcomes. This is an extension of the §J.7 "no formulas in user
copy" heuristic, generalized to synthesis *mechanics*, not just
formulas.

Why: the moment users perceive active intervention mechanics, two
failure modes emerge. (1) Users start optimizing against the
rules instead of demonstrating judgment — the rules become the
game. (2) Public debate about the rules eclipses public debate
about the entities being graded. The platform's authority degrades
into rules-lawyering. The synthesis must be evidently fair (the
graph produces sensible results) without being visibly mechanical
(no one is looking at the formula).

This invariant survives implementation. If a future PR adds a
synthesis mechanic AND surfaces it to users, the PR is rejected
even if the surfacing is well-intentioned (e.g., "in the spirit of
transparency"). Transparency at the mechanism level is the wrong
trade — the platform earns trust by producing accurate, useful
intelligence, not by exposing its own arithmetic.

---

## §J.5 Naming conventions

### Reputation Score

The composite headline number on every entity card and profile.
0–100 range. Synthesizes:
- Decayed Layer 1 attestations (vouches + stand-behinds)
- Dispute history (upheld disputes against the entity)
- Layer 0 contextual signals (low weight)
- On-chain bonuses (validator-specific or wallet-link bonuses
  where applicable)

Replaces the existing `trust_score` field cosmetically. Same
mathematical engine; new label. The rename signals that this is
*reputation*, not generic trust.

### Operator Reliability

The per-attestor track record (§J.3.2). 0.0–1.0. Specific to a
user's behavior as an attestor — how often their judgments bear out.

**Distinct from Reputation Score.** Reputation is what people
attest about *you*; Reliability is how often *you* turn out to be
right when you attest about others. They're related (both fold
into the synthesis) but conceptually separate.

### Reliability Standing

A categorical badge derived from Operator Reliability. **Public
badges are positive-only per the asymmetric-display rule (§J.3.2).**
Operators whose reliability softens lose their positive badge; they
never gain a negative one.

- **Highly Reliable** — reliability > 0.85 with > 20 attestations
- **Consistent** — reliability 0.65–0.85 with > 20 attestations
- **Newly Active** — < 20 attestations (insufficient data)

(Operators whose reliability falls below the Consistent floor with
> 20 attestations simply have no public badge. The numeric
reliability is visible only to the operator themselves via the
self-mirror.)

The badge format keeps the metric human. Nobody reads
"reliability 0.73" and reacts; everyone reads "Consistent" and
calibrates.

### Trust Tier

Existing enum, unchanged: `elite / trusted / neutral / caution /
risky`. Trust Tier is the step-function stratification used for
attestation eligibility, fraud-detection thresholds, and platform
permissions. It's the system's *granular* layer; Reputation Score
is the continuous one; Reliability Standing is the *categorical
attestor-behavior* layer. Three distinct axes, three distinct names.

### Confidence (gauge, card-specific)

Validator-specific synthesis surfaced on `/v/[slug]`. Composes:
on-chain bonuses + Reputation Score + governance participation
proxies + uptime proxies + dispute history. Renders as a 0–100
gauge with a calibrated label ("High Confidence", "Moderate",
"Low", "Insufficient Data").

Builder-card and creator-card analogues are **Builder Reputation**
and **Creator Reputation** respectively (separate gauges with
domain-specific synthesis inputs).

---

## §J.6 Surfaces and UI hierarchy

### The card as living passport

The entity card (validator / project / creator) is the primary
destination. Visiting a card is what counter-parties do before
deciding to trust the entity with capital, code, or governance
weight. Layout, top to bottom:

1. **Identity strip** — name, handle, avatar, joined date, primary
   chain/category
2. **Reputation summary panel**
   - Reputation Score (large headline number)
   - Reliability Standing badge (if attestor; otherwise omitted)
   - Trust Tier chip
   - Standing chip (`✓ GOOD STANDING` / `⚠ UNDER REVIEW`)
   - Negative-state badge (only when triggered): the five-state
     `divergence_state` from §J.2 renders one of `Polarizing` /
     `Disputed` / `Poorly Regarded` when applicable (the positive
     and untested states surface without a badge)
   - Supplemental signals (when triggered): `Under Review` (active
     dispute), `Reputation Volatile` (rapid score swing),
     `Unresolved Claims` (numeric count). These compose with the
     divergence_state classification per §J.2.
3. **Derived intelligence panel** — Confidence gauge (validator) or
   Builder/Creator Reputation gauge (others), with subcomponent
   breakdown for inspection
4. **Action cluster**
   - **Vouch** (always shown; eligibility gates render the button as
     disabled with an unlock_hint tooltip when ineligible)
   - **Stand Behind** with allocation indicator: `Stand Behind ·
     2/3`
   - **Dispute** (tier-gated; renders only for eligible viewers)
   - **Report** (always available to authed users)
   - Utility row (de-emphasized): Follow · Message · Block
5. **Attestation roster** — who has vouched, who is standing
   behind. Sorted by decayed weight desc. Shows attestor handle,
   Reliability Standing badge, date, optional context note,
   revoked status. **This is the primary content of a card.**
6. **Evidence tab** — Layer 0 surfaces: recent posts, blog entries,
   conversation. Demoted to sub-tab because reputation is the
   headline, not the chronological activity stream.

### The profile as operator passport

`/u/[handle]` is the *operator* equivalent of an entity card.
Same layout shape. Same action cluster (Vouch, Stand Behind,
Dispute, Report). Profile-scoped attestations land against
`target_kind=user_profile`.

The difference from entity cards:
- Profile shows the attestor's own behavior surface (Operator
  Reliability) when self-viewing — the "your judgment track record"
  mirror
- Profile shows the operator's *owned entity cards* in a strip (if
  any) — links to the validator/project/creator pages they operate

### The Floor (`/`) — reframed

The current home page is a chronological social feed. Reframe to a
**trust event stream** with a Layer 0 culture rail beside it.

Layout (desktop):

```
┌───────────────────────────────────────────────┬────────────────┐
│  TRUST EVENTS (primary column)                │  LAYER 0       │
│                                                │  CULTURE RAIL  │
│  • @phillip stood behind Aera Labs · 2h       │                │
│    [linked context: 3 supporting posts]       │  • new posts   │
│                                                │  • trending    │
│  • 5 operators vouched for Builder-X this week │    discussions │
│    [trending in cosmos validators]            │  • memes       │
│                                                │  • shoutouts   │
│  • Dispute resolved: ProjectY upheld          │                │
│    [3 stand-behinds reaffirmed]               │  • events      │
│                                                │                │
│  • @marcus revoked vouch on Validator-7       │                │
│    [trailing reasoning thread]                │                │
└───────────────────────────────────────────────┴────────────────┘
```

**Headlines are trust events.** **Context is Layer 0 evidence.**
Clicking a trust event opens both the attestation detail AND the
surrounding social conversation, so the social layer keeps users on
the page and engaged in discussion.

Layer 0 keeps its own home — the culture rail surfaces the trad-
social activity that doesn't tie to a specific trust event. Memes,
operator banter, group activity, casual conversation. This is the
emotional retention engine; without it, the platform is dry.

Mobile layout collapses to alternating bands: trust events / layer
0 culture / trust events / layer 0 culture. Same hierarchy,
different rhythm.

### Surfacing controversy — intelligence, not virality

(Locked 2026-05-13 alongside the polarization-state synthesis in
§J.2.)

The platform must surface high-divergence entities without
developing attention-economy "controversy = engagement" dynamics.
The rules:

1. **Divergence rendering on entity surfaces.** Clicking the
   `polarizing` or `disputed` state on any card opens the
   attestation roster split visually: high-reliability supporters
   render in one column, high-reliability detractors in the other.
   The counter-party reads the actual humans on each side, not a
   computed badge. This is more valuable than any aggregate
   metric — it's the evidence of disagreement.

2. **Directory `Polarizing this week` sort.** The Directory gains
   an optional sort that surfaces entities currently in the
   `polarizing` derived state, ranked by recency of the divergence.
   This is a *pull* capability for counter-parties who want to
   examine where experts disagree — explicitly not the default
   sort.

3. **Floor surfaces controversy as a category, not a ranking
   signal.** Polarizing trust events may appear in the Floor's
   trust-event stream (as one event class among many), but the
   Floor's primary ranking remains recency + actor-reliability.
   Controversy never moves an event up the feed for being
   controversial. This is the structural antibody against the
   controversy-as-virality loop.

### Surfacing Early Read — retrospective independent discovery

(Locked 2026-05-13 alongside the Early Read sub-track in §J.3.2.1.)

The platform celebrates operators whose pre-consensus calls
validate. The mechanics:

1. **Floor first-mover events as a distinct event class.** The
   trust-event stream surfaces events of shape
   *"@operator was the first to back @target, which has now reached
   N backers"* — but only **retrospectively**, after subsequent
   attestations validate the original call (typically days or
   weeks later). There is no real-time "who's casting now" feed.
   These events have no comment thread, no like-count, no
   engagement-economy hooks — they are informational broadcasts
   per the anti-viral-by-design constitutional principle.

2. **Directory `Early Read` filter.** Operators with the public
   Early Read badge can be discovered via a Directory filter,
   alongside the existing kind / tier / good-standing axes. The
   filter is *pull*, not *push*: counter-parties who want to
   examine operators with strong independent-discovery track
   records can find them. The Directory's default sort remains
   trust + reliability; Early Read is an optional axis.

3. **Profile badge placement.** The `Early Read` badge renders in
   the same row as Reliability Standing on a profile — a peer-level
   credential, not a special-status decoration. Operators see it
   as one of multiple recognized judgment styles, not as a
   gamified achievement.

### Notifications taxonomy

Trust events are first-class push. Seven event types in the V1
taxonomy (full schema + recipient rules + Phase 1 vs Phase 1.5
sequencing locked in `docs/api-contract-v1.md` §J.7):

- `attestation_vouch_received` — "@phillip vouched for you"
- `attestation_stand_behind_received` — "@marcus stood behind you"
- `attestation_revoked` — "@phillip revoked their vouch"
- `attestation_reaffirmed` — "@marcus reaffirmed their stand behind"
- `stand_behind_renewal_nudge` — self-only soft renewal nudge driven
  by §J.1 long-term graph health refinements
- `dispute_filed_against_you` — formal challenge incoming
- `reliability_threshold_crossed` — "Your Reliability Standing
  changed: Newly Active → Consistent"
  (asymmetric per §J.3.2 — push on cross-into-positive, bell-only
  on cross-out-of-positive)

Each opt-toggleable on `/me/notification-prefs` per the existing
§I1 contract.

---

## §J.7 Onboarding & the 60-second comprehension test

A brand-new user must understand the system in under 60 seconds.
Specifically, they must understand:
- **What the platform is for** — vetting operators in crypto
- **What the card is** — the operator's reputation passport
- **What actions exist** — Vouch (cheap, abundant), Stand Behind
  (scarce, weighty), Dispute (formal, evidenced)
- **What carries weight** — attestations from high-reliability
  operators
- **What's scarce** — Stand Behind slots
- **What creates reputation** — receiving attestations + clean
  dispute history
- **What damages credibility** — open disputes, contested patterns,
  reliability volatility
- **What the platform values** — accuracy, accountability, evidence

The onboarding flow accomplishes this in four cards. Copy locked
here; implementation matches verbatim per
`docs/trust-attestation-phase-1-plan.md` §8.2.

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
> **Dispute** — "This needs panel review." Formal. Requires
> evidence and panel adjudication.

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
> feeling of the action is the lesson. Card 3's teaching prevents
> the user from interpreting their own initial low Reputation
> Score as a negative signal — silence is normal.

Card 3's "absence is not a negative signal" teaching is
**load-bearing** per the risk-assessment §2.9 mitigation for the
"no vouch = bad" interpretation drift — the most likely path to
existential cultural failure. The exact wording must ship verbatim;
softening or rephrasing reopens the failure mode.

If a user completes onboarding and can answer "what's the difference
between Vouch and Stand Behind?" correctly, the comprehension test
passes. If they can't, the labels and UX are wrong and we iterate.

### Plain-English labels that the design system enforces

| Mechanic | Internal name | User-facing label |
|---|---|---|
| Layer 1 abundant positive | `vouch` | "Vouch" |
| Layer 1 scarce positive | `stand_behind` | "Stand Behind" |
| Layer 1 formal negative | `dispute` | "Dispute" |
| Composite entity score | `reputation_score` | "Reputation Score" |
| Per-attestor track record | `operator_reliability` | "Operator Reliability" |
| Categorical reliability badge (public; positive-only per §J.3.2) | `reliability_standing` | "Highly Reliable" / "Consistent" / "Newly Active" |
| Existing tier enum | `trust_tier` | "Elite" / "Trusted" / "Neutral" / "Caution" / "Risky" |
| Validator-card synthesis | `validator_confidence` | "Confidence" |
| Project-card synthesis | `builder_reputation` | "Builder Reputation" |
| Creator-card synthesis | `creator_reputation` | "Creator Reputation" |
| Independent-discovery sub-track (self-mirror) | `early_read_accuracy` | "Early Read Accuracy" |
| Consensus-alignment sub-track (self-mirror) | `consensus_reliability` | "Consensus Reliability" |
| Independent-discovery public badge | `early_read` | "Early Read" |
| Layer 0 reactions on posts | `reaction.kind=*` | "Solid" / "Fire" — unchanged |

**No algebra leaks into the UI.** Users see labels and badges, not
formulas or weight calculations. The math lives in the synthesis
layer, hidden behind plain English.

### Ten anti-complexity heuristics — design-system enforced

Locked 2026-05-13 after a 60-second-comprehension pressure test.
Each is an enforced rule. Violations are bugs, not style choices.

1. **No naked numbers.** Every numeric display has a verbal label
   or badge attached. Operator Reliability never renders as `0.73`;
   it renders as `Consistent`. Reputation Score 78 renders
   alongside `Well Regarded`. If a value can't be translated to a
   phrase, it can't be shown.
2. **No formulas — and no synthesis mechanics — in user copy.**
   Phrases like "weighted attestations × decay × reliability
   multiplier" never appear in any visible UI surface — marketing
   copy, tooltips, onboarding cards, hover states. Neither do
   descriptions of the *mechanics* themselves: no "Elite-tier
   contribution capped at 40%" tooltip, no "your attestation is
   getting a diversity bonus" hint, no "this score is suppressed
   because…" warning. The user sees outcomes, not algebra and not
   the rules that produce the algebra. See §J.4.1 (synthesis
   invisibility) for the full invariant.
3. **Show humans first, aggregates second.** Before any aggregate
   metric, the user sees specific people. The attestation roster
   (avatars + handles + reliability standing badges) renders
   ABOVE the Reputation Score on every card. Aggregates summarize;
   humans are evidence.
4. **One action per primitive, no settings panel.** Vouch is one
   button. Stand Behind is one button. Dispute is one button.
   There is no "configure your vouch weight" or "set vouch type"
   — the system handles weighting; the user casts.
5. **Show what's scarce, hide what's abundant.** Vouch button has
   no counter (abundance). Stand Behind button always shows
   allocation: `Stand Behind · 2/5` (scarcity). The presence of a
   counter teaches scarcity without explanation; absence teaches
   abundance.
6. **Plain English for state transitions.** State change
   notifications read as sentences: *"Your Reliability Standing
   changed: Newly Active → Highly Reliable."* Never *"Your
   reliability metric crossed threshold 0.85."*
7. **Negative signals are visible BUT calibrated.** `Under Review`
   (active dispute) is loud — safety-orange strap, top of card.
   `Volatile` (historical) is subtle — secondary badge, neutral
   tone. UI weight tracks the actionability of the signal: loud
   = present-tense problem, subtle = historical context.
8. **The action label IS the explanation.** `Stand Behind 2/5`
   tells the user the action exists, that it's scarce, and that
   they've used some of their allotment. No tooltip required. No
   onboarding card needed. The action documents itself.
9. **Empty states are designed states.** A profile with zero
   attestations doesn't read `No attestations`. It reads *"This
   operator hasn't been backed yet. Be the first to vouch."*
   Empty state is an invitation, not a void.
10. **Asymmetric positive-only public badges.** Reliability Standing
    badges visible to other viewers are exclusively the
    celebratory ones (`highly_reliable`, `consistent`,
    `newly_active`). There is no public negative badge. Operators
    whose reliability softens lose positive badges; they don't
    gain negative ones.

### First-impression rule

A new user landing on any card or profile must SEE, above the
fold on a 13-inch laptop at 100% zoom, no scrolling required, in
this order:

1. **Identity** — avatar + handle + display name
2. **Reputation summary panel** — score + reliability standing
   badge + trust tier chip + standing chip + negative-state
   badge (only if triggered: `Under Review` / `Polarizing` /
   `Disputed`)
3. **Attestation roster** — top 5 backers visible + "+N more"
4. **Action cluster** — Vouch / Stand Behind / Dispute / Report +
   utility row (Follow / Message / Block)

If any of these falls below the fold, the design has failed and
iterates before the surface ships.

### Edge-case heuristics

- **Heavily-revoked profile.** A profile where many stand-behinds
  have been revoked recently does NOT hide the revocations. The
  roster shows revoked entries dimmed behind a `Show revoked`
  toggle. Historical visibility is preserved: "no hiding the past"
  is a core platform value.
- **Own-profile view is additionally informative.** Signed-in
  operator viewing their own profile sees the Operator
  Reliability mirror, the Stand Behind slot-allocation breakdown,
  the "recyclable slots about to free" hint, and the
  Discovery/Consensus reliability breakdown (when that lands —
  see §J.10 open questions). The operator sees their own
  reflection in more detail than anyone else.
- **Anonymous viewer.** Same surface as authed-but-non-owner
  viewer, minus the action cluster. The reputation graph is
  *readable* by anyone; *writable* requires authentication. This
  is intentional — the platform's defensibility is the readability
  of the graph.

---

## §J.7.5 Architectural freeze — Phase 1 begins from this lock

Locked 2026-05-13. The Trust Attestation Layer's core graph
mechanics are now **frozen** at the level of philosophy and
incentive architecture. Further mechanics-level expansion is
deferred until **implementation or live testing reveals existential
failures** — not because we've thought of more refinements, not
because additional sophistication looks possible.

The next stage of work is operational, not architectural:

- Implementation sequencing (Phase 1 scope-freeze plan, then code)
- Abuse testing (adversarial walkthrough of each primitive +
  synthesis mechanic against named attacker profiles)
- Closed-network simulation (small-cohort behavioral pilot before
  open-network release)
- Onboarding validation (the 60-second comprehension test run
  against real new users, with playback / interview)
- UX readability passes (each surface checked against the ten
  anti-complexity heuristics + first-impression rule)
- Emotional / social behavior analysis (post-launch observation of
  whether the dynamics this design intends actually emerge)

Specifically deferred until existential failure surfaces:

- New attestation primitives beyond Vouch / Stand Behind / Dispute
- New derived intelligence axes beyond Reputation Score /
  Reliability Standing / Confidence / Builder Reputation / Creator
  Reputation / Early Read
- Reworking the synthesis math at the constitutional level (Phase
  1 plan will tune the numbers within the locked envelope; that's
  not constitutional change)
- New surfaces beyond the locked card / profile / Floor / Directory
  / notification taxonomy

If a future contributor proposes constitutional expansion before
live testing has surfaced a failure, the burden of proof is on the
proposal to demonstrate the failure mode it addresses is real and
present, not theoretical. **The default answer is no.** This is
the same §P "scope discipline" rule the V1 plan operated under,
applied to the trust-attestation domain.

---

## §J.8 V1 shippable slice

Foundational architecture, locked. Implementation phases listed for
orientation only — each phase requires its own scope-frozen plan
before code touches anything.

### Phase 1 — Identity attestation foundation (~2 weeks)

- `bcc_trust_attestations` table (generic shape)
- `AttestationService` (generalizes `EndorsementService`)
- Migration: existing endorsement rows → `kind=vouch,
  target_kind=*_card` attestations
- Confidence decay function applied at read time
- `OperatorReliabilityRepository` read-model
- `ContentReportService.TARGET_KINDS` extends to `['feed_item',
  'user_profile', 'validator_card', 'project_card', 'creator_card']`
- Profile + card action cluster (Vouch / Stand Behind / Dispute /
  Report + utility cluster)
- Attestation roster surface on profile/card
- Reputation Score cosmetic rename of `trust_score`
- Operator Reliability surfaced on own profile only

### Phase 2 — Floor reframe (~2 weeks)

- Trust event stream as `/` primary feed
- Layer 0 culture rail beside it
- Notifications taxonomy extension for trust events
- Reliability Standing surfaced publicly (V1 had it self-only)

### Phase 3 — Card-specific intelligence + meta-dispute (~3 weeks)

- Validator Confidence gauge fully wired
- Builder Reputation and Creator Reputation gauges
- Negative-signal badges (Contested, Volatile) computed and surfaced
- Meta-dispute path for malicious attestors
- Confirmed Trade primitive (requires evidence-flow design — own
  scope-freeze)

### Out of V1 scope, parked

- Confirmed Trade as a launch primitive (Phase 3+ with evidence flow)
- Public-facing Operator Reliability on other users' profiles
  (Phase 2)
- Cross-chain reputation portability (V3)
- Open API for external counter-parties to query the graph (V3)
- Operator-to-operator messaging tied to specific attestations
  ("DM me about why you vouched") (V2 or later)

---

## §J.9 Migration of existing data

Existing primitives and how they migrate:

| Existing entity | Migration |
|---|---|
| `EndorsementService::endorsePage` records | Materialize as `kind=vouch, target_kind=*_card` attestation rows. Preserve original timestamps. |
| `ReactionTypeRegistry::KIND_VOUCH` (post reactions) | **Freeze at Layer 0.** Existing reaction rows remain — they keep working as content-layer +1 signals on posts. They stop contributing to the trust graph (no weight in Layer 2 synthesis). The verb name overlap is acceptable because the surfaces are visually distinct (a post reaction vs. a card action). UI copy on the post-reaction surface stays "Vouch" but de-emphasized; the card-action surface is the canonical Vouch. |
| `ReactionTypeRegistry::KIND_STAND_BEHIND` (post reactions) | Same treatment as above. |
| `ReactionTypeRegistry::KIND_SOLID` / `KIND_FIRE` | Unchanged. Pure Layer 0 reactions; never contributed to the trust graph anyway. |
| `bcc_trust_activity` audit rows | Existing entries preserved. New attestation actions register new action types per `pattern-registry.md`'s Destructive Mutation Hardening recipe. |
| `trust_score` field on cards/profiles | Cosmetic rename to `reputation_score` in API responses. Backwards-compatible alias retained for one release cycle. |

The migration is **additive** — no existing data is destroyed, no
existing surface stops working. The new attestation layer runs in
parallel to existing reactions; the synthesis layer treats the
post-reaction kinds as legacy with zero weight.

---

## §J.10 Open questions — to resolve before Phase 1 plan

These are deliberately deferred to scope-freeze planning rather than
hard-locked here:

1. **Stand Behind slot counts** — the 3/5/7 ladder by tier is a
   plausible-feeling default. Real numbers should fall out of
   product playtesting once Phase 1 ships. Lock at "TBD by tier"
   in the contract; pick the V1 numbers in the Phase 1 plan.
2. **Decay curve shape** — linear vs. exponential vs. piecewise.
   The values given above are illustrative. Decide in Phase 1 once
   read-model performance characteristics are clearer.
3. **Operator Reliability formula weights** — how much does a
   vouch-that-later-disputed cost vs. a vouch-that-later-vouched
   pay? Calibrate once the data is dense enough to be
   meaningful.
4. **Negative-badge thresholds** — Volatile = how many points in
   how many days? Contested = what variance number? Tune in
   Phase 3 against real-world data.
5. **Floor culture-rail width** — fixed % of viewport, or
   dynamically resizable? Decide in Phase 2 design pass.
6. **The "context note" on attestations** — optional free-text
   field. Character cap? Sanitization? Decide in Phase 1.
7. **Revocation cooldown** — can you revoke and re-attest the same
   target immediately, or is there a cooling window to prevent
   flip-flopping? Decide in Phase 1.
8. **Activity-gating dormancy threshold** (§J.1 refinement) — 60
   days is a plausible default for when an attestor's roster
   contribution dims. Tune in Phase 1 against early-cohort
   activity data.
9. **Soft-renewal nudge cadence** (§J.1 refinement) — 6 months is
   a default. Possibly tier-dependent or activity-dependent.
   Decide in Phase 2 when the nudge ships.
10. **Slot-graduation thresholds** (§J.1 refinement) — how many
    accurate attestations to earn +1 slot? Cap at +3 above tier
    baseline. Exact ladder TBD in Phase 1.
11. **First-call protection count** (§J.3.2 refinement) — 5 vs. 10
    vs. variable-by-tier. Decide in Phase 1.
12. **Gradual-change momentum window** (§J.3.2 refinement) — 10
    attestations crossing threshold over what time window? Decide
    in Phase 1.
13. **Polarizing-state divergence cutoff** (§J.2 refinement) — what
    variance among high-reliability attestors triggers the
    Polarizing classification? Tune in Phase 3.
14. **Public surfacing of positive Reliability Standing badges**
    (§J.3.2 refinement) — V1 is self-only for numeric *and*
    badges. V2 expansion opens the positive badges to public
    viewers; the timing depends on attestation density (~6 months
    minimum).
15. **Early-conviction multiplier gradient** (§J.3.2.1) — the
    2.5×/1.5×/1.0×/0.5× ladder is plausible-feeling default. Tune
    in Phase 1 against simulation data; the gradient *shape*
    (graduated, not binary) is the locked architectural commitment;
    the exact numbers are not.
16. **Elite-tier weight cap exact percentage** (§J.4 item 8) — 40%
    is the design intent. Tune within ±10% in Phase 1 against
    simulation outcomes; do not change the *existence* of the cap.
17. **Diversity multiplier strength** (§J.4 item 9) — 1.3× is the
    design intent. Tune within ±0.1× in Phase 1.
18. **Early Read badge threshold** (§J.3.2.1) — how many validated
    pre-consensus calls qualify an operator for the public badge?
    Lock in Phase 1; the badge is asymmetric-positive-only either
    way.
19. **First-mover protection count on Early Read** (§J.3.2.1) — 5
    stand-behinds is plausible default; reconcile with the §J.3.2
    first-call protection count.

---

## §J.11 What this kills

For unambiguous closure:

- **Endorse as a separate verb** — collapses into Vouch with
  target_kind=card.
- **Hard clawback for failed attestations** — replaced by decay +
  reliability scoring + meta-dispute backstop.
- **Confirmed Trade as a V1 launch primitive** — parked.
- **Validator Confidence / Builder Reputation as direct user
  actions** — collapsed into derived intelligence gauges.
- **Vouch / Stand Behind as trust-graph-contributing post
  reactions** — frozen at Layer 0; new attestation layer takes
  over Layer 1.
- **Reddit-style downvote on profiles or cards** — explicitly
  rejected. Negative discoverability is derived from disputes +
  divergence + volatility, not human-cast negative votes.
- **"Trust Score" as the user-facing composite label** — replaced
  by "Reputation Score." Internal `trust_score` field renamed
  cosmetically in API responses.
- **Implicit Layer 0 → trust graph leakage** — solid and fire
  reactions are Layer 0 only, with explicit zero weight in Layer 2
  synthesis.

---

## §J.12 What this preserves

For equal unambiguity:

- **Tier system** — elite/trusted/neutral/caution/risky enum is
  unchanged. Reputation Score is the continuous axis; Trust Tier
  is the step function for eligibility gating.
- **Rank system** — apprentice/journeyman/**master** auto-derivation
  from the feature-access **level** (participation, not reputation
  tier). Ranks are capability badges, not reputation labels. **Foreman**
  is a conferred **Role**, orthogonal to the Rank ladder — not its top
  rung (see glossary §1, api-contract §4.8).
- **Good Standing** — `is_in_good_standing` flag, sourced from
  `UserViewService::GOOD_STANDING_TIERS`, is unchanged.
- **Existing dispute mechanics** — disputed entity cards work the
  same way. Profile-scoped disputes are an additive extension to
  the existing panel mechanic.
- **PeepSo as Layer 0 substrate** — posts, comments, reactions,
  group activity continue to flow through PeepSo. The integration
  doesn't change.
- **§A2 server-renders-everything posture** — every label,
  message, link in the new attestation surfaces is server-built.
  Frontend renders verbatim per the constitution.
- **§E1 good-standing tier list** — `[neutral, trusted, elite]`
  remains the single source of truth.
- **§I1 notification contract** — extends with trust-event types
  per §J.6; the underlying envelope and prefs grammar is unchanged.
- **Onboarding bonus loop** — exists pre-attestation-layer;
  preserved.

---

## §J.13 Acceptance criteria

This document is "right" when:

1. A brand-new user, given only the four-card onboarding flow, can
   answer in their own words: "what does Stand Behind mean and how
   is it different from Vouch?" — and the answer mentions scarcity
   or limited slots without prompting.
2. An operator with > 6 months of platform use can name their own
   Operator Reliability standing and explain what would change it.
3. A counter-party who has never used the platform can land on an
   entity card and decide within 60 seconds whether to trust the
   entity, citing specific signals from the card (Reputation Score,
   attestation roster, dispute history, negative badges if present).
4. No surface in the product asks the user to interpret a number
   without a plain-English label or badge attached.
5. The phrase "downvote this operator" never appears in the
   product, documentation, or design conversation.

If any of these fail in playtesting, the design is wrong and
iterates before code lands.

---

## §J.14 Trust Recovery Through Contribution (Contribution + Consistency signals)

A user must never be permanently trapped in Risky. Sustained positive
participation earns a **gradual** recovery — but popularity must never
create trust. Trust is still primarily earned through trustworthy
behavior (votes, endorsements, dispute outcomes, scam reports,
moderation); contribution + consistency are **minor, capped, internal**
inputs that *assist* recovery.

**Two internal signals (no public score is exposed):**

- **Contribution (~15% band, capped)** — useful posts/guides, helpful
  comments, reviews written, upheld scam reports. Computed by
  `ContributionScoreService` over rolling windows.
- **Consistency (~5% band, capped)** — account age + sustained presence
  across the rolling windows + a clean record.

These feed a persisted `bcc_trust_reputation.contribution_bonus` (the
INPUT, refreshed daily by `ContributionRecoveryEvaluator` over the
caution/risky cohort) which `ReputationCalculatorService::recalculateUserReputation`
blends into `reputation_score` (the OUTPUT) — the same derived-column
pattern as `endorsement_bonus`, so a vote-recalc never clobbers it.

**The model:**

```
genuine   = vote-ratio reputation (+ dispute/fraud penalties)   // primary trust
bonus     = min(contribution, BCC_CONTRIB_MAX) + min(consistency, BCC_CONSIST_MAX)
effective = genuine + bonus
if genuine < Trusted(65):  effective = min(effective, BCC_CONTRIB_CEILING≈60)
reputation_score = clamp(effective, 0, 100)   // tier re-derives from this
```

**Anti-abuse rules (config/contribution.php):**

1. **Reactions never directly create trust.** Engagement is only a
   *multiplier* on real contribution — a user with zero qualifying
   contributions earns zero bonus no matter how many reactions they farm
   (`usefulness × 0 = 0`).
2. **Contribution has a ceiling.** `BCC_CONTRIB_CEILING` (< Trusted) means
   contribution alone lifts Risky → Caution → toward Neutral but never
   into Trusted/Proven; the genuine score must independently clear those.
3. **Rolling windows (30/90/180 d) reward consistency, not spikes** —
   non-overlapping buckets, each capped.
4. **Trust-weighted engagement** — a contribution's usefulness is weighted
   by the *engager's* reputation tier (reusing `BCC_TRUST_WEIGHT_*`), so
   reaction farming from new/low-tier accounts is near-worthless.

Clean-record gate: a recent fraud/violation signal dampens contribution
and zeroes consistency. Risky users stay visible but feed-demoted +
feature-gated as today (unchanged) — recovery is the path back, not
re-admission. Dispute participation keeps its existing §D5 read-time
credit and is intentionally not folded in here (avoids double-counting);
promoting it to a tier-affecting recovery signal is a deliberate follow-up.

The bonus is observable to operators via the `contribution_recovery`
DegradationMetric subsystem; no `Contribution Score` is ever rendered to
users (identity stays Rank · Trust Tier · Role — see glossary §1).

---

## Cross-references

- API wire-level contracts: `docs/api-contract-v1.md` §J
- Threat model & behavioral risk assessment:
  `docs/trust-attestation-risk-assessment.md` (Phase 1 implementation
  conforms to its §5 Critical items; closed-network testing
  instruments its §4 watch signals)
- Phase 1 implementation plan:
  `docs/trust-attestation-phase-1-plan.md` (scope-frozen
  implementation plan, 4-week sequencing, acceptance criteria)
- Existing trust engine pattern: `pattern-registry.md` "Trust Engine"
- Existing dispute mechanics: `app/Domain/Disputes/`
- Existing endorsement pipeline: `app/Domain/Core/Services/EndorsementService.php`
- Server-renders-everything constitution: `api-contract-v1.md` §A2
- Good-standing tier source: `app/Domain/Core/Services/UserViewService.php` `GOOD_STANDING_TIERS`
- Destructive-mutation hardening recipe: `pattern-registry.md`

---

**This document is the constitution for trust attestation in BCC. All
future feature work in this domain conforms here, or amends this doc
first.**
