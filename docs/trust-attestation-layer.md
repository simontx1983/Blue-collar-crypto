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

### Disputed Status

**Trigger:** active dispute filed against the entity (state =
`open` or `in_panel`).

**Surface:** prominent badge on the card — `⚠ Under Review`.

**Lifecycle:** clears when the dispute resolves. A resolved dispute
that found in the entity's favor leaves no badge. A resolved dispute
that found against them feeds Reliability Volatility (below).

### Contested Reputation

**Trigger:** high variance in attestation weight on a target.

When 10 high-reliability operators vouch + 5 file disputes (or vice
versa), the synthesis layer detects opinion divergence and surfaces
`⚠ Contested`. The threshold is variance-based, not count-based, so
small entities can be Contested too.

**Surface:** secondary badge on the card; clickable to see the
attestation roster sorted by polarity.

### Unresolved Claims

**Trigger:** open dispute count > 0 OR open complaint count > 0.

**Surface:** numeric badge — `2 unresolved claims`. Quantitative
honesty without editorializing.

### Reliability Volatility

**Trigger:** the entity's Reputation Score has swung by > N points
in a rolling window (e.g. 20-point swing in 90 days).

**Surface:** `⚠ Volatile` badge. Means: this entity's reputation
isn't stable. Counter-parties may want to wait.

### Attestation Divergence

**Trigger:** high-reliability operators and low-reliability
operators are voting differently on the same target.

Specifically: if attestors with reliability > 0.8 mostly vouch and
attestors with reliability < 0.3 also mostly vouch, that's consensus
(low divergence). If the high-reliability group is split or
divergent from the low-reliability group, that's signal.

**Surface:** subtle indicator on the attestation roster — the high-
reliability and low-reliability sub-rosters render separately so
the divergence is *seeable*, not just computed.

### Why this works

Nobody cast a downvote. The system detected the negative pattern
from:
- The presence of disputes (which carry process cost)
- The *absence* of consensus among high-reliability operators
- The variance of attestation weight
- The volatility of the derived Reputation Score

No brigading vector (you can't coordinate detected-patterns).
No personal attack surface (the system, not a human, surfaces the
issue). Evidence-driven (signals come from real disputes + real
attestations + real time-series).

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

Surfaced as **Operator Reliability** on the attestor's own profile
in V1 (private to them, visible to no one else, so reliability is a
mirror not a stigma). V2 expansion surfaces to public viewers once
the metric has matured enough to be meaningful (~6 months of attest-
ation density).

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

A categorical badge derived from Operator Reliability:

- **Highly Reliable** — reliability > 0.85 with > 20 attestations
- **Consistent** — reliability 0.65–0.85 with > 20 attestations
- **Newly Active** — < 20 attestations (insufficient data)
- **Volatile** — reliability 0.4–0.65 with > 20 attestations
- **Untested** — 0 attestations

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
   - Negative-signal badges (Contested, Volatile, Under Review,
     Unresolved Claims — only when triggered)
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

### Notifications taxonomy

Trust events are first-class push:

- `attestation_vouch_received` — "@phillip vouched for you"
- `attestation_stand_behind_received` — "@marcus stood behind you"
- `attestation_revoked` — "@phillip revoked their vouch"
- `dispute_filed_against_you` — formal challenge incoming
- `dispute_resolved` — outcome, in your favor or against
- `reliability_threshold_crossed` — "Your Reliability Standing
  changed: Newly Active → Consistent"

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

The onboarding flow accomplishes this in four cards:

1. **"This is an operator intelligence network."** — One sentence
   product framing. Single visual: a card with a Reputation Score
   and attestation roster.
2. **"Three things you can do."** — Vouch / Stand Behind / Dispute,
   icons + one-line explanations + the scarcity badge on Stand
   Behind.
3. **"Your reputation grows from what others say about you. Your
   reliability is your own track record."** — Plain English split
   between the two metrics.
4. **"Cast your first vouch."** — Walks the user through vouching
   for a sample card. The feeling of the action is the lesson.

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
| Categorical reliability badge | `reliability_standing` | "Highly Reliable" / "Consistent" / "Volatile" / "Newly Active" / "Untested" |
| Existing tier enum | `trust_tier` | "Elite" / "Trusted" / "Neutral" / "Caution" / "Risky" |
| Validator-card synthesis | `validator_confidence` | "Confidence" |
| Project-card synthesis | `builder_reputation` | "Builder Reputation" |
| Creator-card synthesis | `creator_reputation` | "Creator Reputation" |
| Layer 0 reactions on posts | `reaction.kind=*` | "Solid" / "Fire" — unchanged |

**No algebra leaks into the UI.** Users see labels and badges, not
formulas or weight calculations. The math lives in the synthesis
layer, hidden behind plain English.

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
  'user_profile', 'card']`
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
- **Rank system** — apprentice/journeyman/foreman auto-derivation
  from participation is unchanged. Ranks are participation badges,
  not reputation labels.
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

## Cross-references

- API wire-level contracts: `docs/api-contract-v1.md` §J
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
