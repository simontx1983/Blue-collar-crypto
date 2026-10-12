# Proposal — `CohortOverlapDampener` (the ×0.80 attestation dampener)

**Status: PROPOSAL. ⛔ DELIBERATELY UNCHANGED — presented, not altered.**

> Left as-is on purpose. §4 now records its interaction with reciprocity **after**
> bcc-trust #315, which removes the compounding this document originally led with.
> Concrete alternatives are in §5. No `weight_at_time` rewrite, no score reset.

Found during the A1/A2/B/C scoring-separation work (bcc-trust #314), which did not touch it —
it adds no fraud points. Like its sibling it produces an **adverse ranking effect from
relationship structure alone**.

⚠ **This is NOT a reciprocity mechanism.** No mutual edge is required; A can be dampened for
backing B having never received anything from B. It is a *cluster* rule. It is proposed
separately for exactly that reason: the legitimate counterexamples and the correct answer are
different from the pairwise case.

Related: [`trust-attestation-risk-assessment.md`](trust-attestation-risk-assessment.md)
§"Cartel formation" mitigation #4, which is this mechanism's spec.
Sibling: [`proposal-reciprocity-attestation-dampener.md`](proposal-reciprocity-attestation-dampener.md).

---

## 1. The trigger, exactly

`CohortOverlapDampener::weightFor($attestorUserId, $targetKind, $targetId)`, called from
`AttestationService` in the same locked region as the insert.

```
attestor_cohort_size = COUNT(DISTINCT targets A has actively cast on, kind user_profile)
intersection_size    = COUNT(DISTINCT U where A -> U is active AND U -> target is active)
overlap_ratio        = intersection_size / attestor_cohort_size

if targetKind !== 'user_profile'      -> 1.00
if self / non-positive ids            -> 1.00
if attestor_cohort_size == 0          -> 1.00   (first-ever cast)
if overlap_ratio >= 0.50              -> 0.80
otherwise                             -> 1.00
```

In words, from the class docblock: *"what fraction of MY past attestation pool also attests to
THIS target?"* At ≥50%, *"my judgment is too aligned with the target's existing supporters to
count as independent signal."*

⚠ **The denominator is the attestor's own cohort size, which makes the rule most aggressive
against the least active attestors.** With a cohort of 2, a single shared backer is 50% and
trips it. With a cohort of 40, you need 20. So the operator who has vouched for two people in
their life is held to the strictest independence standard on the platform, and the prolific
one to the loosest — the inverse of the risk profile.

Unlike the reciprocity rule, the threshold and multiplier are **not filterable** — both are
hard `public const`.

## 2. Divergence from its spec — milder than its sibling, but present

The spec:

> **Cohort-cluster overlap dampener.** The diversity multiplier is currently binary
> (cross-network = 1.3×, in-network = baseline). **Refine to a graduated dampener**: if the
> attestor's prior attestation set overlaps significantly with the target's existing attestor
> set (>50% overlap), apply a 0.8× weight multiplier. Cartel members backing cartel targets
> get *less* weight, not just non-bonus weight.

Here the spec **does** authorise a penalty ("*less* weight, not just non-bonus weight"), so
unlike the reciprocity dampener the 0.8× is intended. Two gaps remain:

- ⚠ The spec says **graduated**; the implementation is a **single step**. Its docblock is
  explicit and honest about this: *"Single-step rather than graduated because closed-network
  testing needs a clean A/B signal before refining the curve. The §6.3 plan says 'graduated' —
  promote to a 2-tier or interpolated curve here when real-data calibration justifies it."*
  **The calibration that was supposed to justify it has not happened**, and the step is a
  cliff: 49.9% overlap is ×1.00, 50.0% is ×0.80.
- The spec frames it as refining the diversity multiplier. As with the reciprocity dampener,
  it instead multiplies the base weight at cast time and leaves
  `AttestationScoreSynthesis`'s source-count-gated 1.3× untouched.

## 3. Legitimate counterexamples

Overlap is what a **real local community** looks like. A trade network in one town is a dense
graph by nature, and this rule reads density as capture:

- **A hall with twelve members** who have all worked with most of the others. Any new vouch
  inside it will overlap heavily with the target's existing backers — because they are the
  same town.
- **A single trade in a small market.** Every roofer in the area knows every other roofer.
  Overlap approaches 100% and says nothing about independence of judgment.
- ⚠ **A low-volume honest attestor.** Cohort of 2, one shared backer → 50% → dampened on
  their *second ever* vouch. This is the cliff plus the small-denominator problem combining
  on the most ordinary user there is.
- **A specialist.** Someone who only vouches within their discipline will overlap with that
  discipline's existing attestors by construction. The platform *wants* domain-specific
  testimony; this rule discounts it.
- **A hall admin or organiser** whose role is to know everyone.

⚠ The rule cannot distinguish "these people all know each other because they work together"
from "these accounts were created to back each other", because **it has no input that could** —
the same structural defect found in the ring detector during the #314 work.

## 4. Downstream effects

Identical path to the reciprocity dampener: `weight_at_time` →
`AttestationScoreSynthesis` → `attestation_bonus` → trust score → rank and the surfaces
reading it.

- 20% off the cast's contribution to **the target's** score. Again the cost lands on the
  person vouched for.
- **No fraud score, no risk level, no restriction, no allegation, no operator-visible flag.**
### ⚠ Interaction with reciprocity — CORRECTED, because bcc-trust #315 changes it

An earlier draft of this document argued that the strongest reason to act here was the
compounding: `0.50 × 0.80 = 0.40`, a 60% reduction for a mutual pair inside a dense local
community. **bcc-trust #315 removes that**, by restoring the reciprocity multiplier to
baseline. The compounding argument was real but is now spent, and it would be wrong to keep
citing it.

| Scenario | Before #315 | After #315 |
|---|---|---|
| independent attestor, low overlap | 1.00 | **1.00** |
| independent attestor, overlap ≥50% | 0.80 | **0.80** — unchanged |
| mutual pair, low overlap | 0.50 | **1.00** |
| ⚠ mutual pair, overlap ≥50% | **0.40** | **0.80** |

So after #315 this dampener becomes a **standalone ×0.80**, and the two mechanisms no longer
multiply. Wallet age still multiplies in independently, so the floor is lower than 0.80 for a
new wallet — that is unchanged and is a separate mechanism again.

**What this does to the case for changing it:** it weakens the urgency considerably. The
remaining objections stand on their own merits and are narrower:

1. the **small-denominator** unfairness (§3) — an honest second-ever vouch trips at one shared
   backer;
2. the **cliff** — 49.9% is ×1.00 and 50.0% is ×0.80, where the spec asked for graduated;
3. the rule **penalises community density**, which is what a real local trade network is.

⚠ (1) is a defect on any reading. (2) is a documented deviation from the spec whose own
justification — pending calibration — never happened. (3) is the actual policy question, and it
is the one the direction reaches.

**No change is proposed here.** This section exists so the interaction is on record before any
decision, as asked.
- With wallet age also multiplying in, the floor is lower still.
- ⭐ Recorded per cast as `cohort_overlap_mult` in the audit log, so the history is
  retrievable.

## 5. Forward correction

| # | Change | Effect |
|---|---|---|
| **A** | `DAMPENED_MULTIPLIER` 0.80 → **1.00** | Stops it outright. One constant. ⚠ But unlike the reciprocity case this contradicts the spec, which did authorise a penalty — so it needs a policy decision, not just a bug fix. |
| **B** | Make it **graduated**, as the spec always said — e.g. interpolate 1.00 → 0.80 between 50% and 100% overlap | Removes the cliff. Still penalises density. |
| **C** | Add a **minimum cohort size** (e.g. ignore attestors with a cohort under ~8) | Removes the small-denominator unfairness, which is the clearest defect. Cheap and well-targeted. |
| **D** | Replace the ratio with an **absolute** independent-source count | Measures the thing actually wanted ("is there independent testimony?") instead of a proportion. Larger change. |
| **E** | Convert to an **observation** feeding the Tier 2 queue, with no weight effect | Matches the separation architecture. Needs the queue, which does not exist. |

**Recommended: C now, then decide between B and E.** C fixes a defect nobody would defend —
penalising an honest second-ever vouch — without reopening the policy question. B and E are
the real choice and should be taken deliberately.

⚠ **Do not bundle with the reciprocity proposal**, despite §4's compounding. Decide both, but
change them in separate reviewable steps so the effect of each is attributable.

## 6. Historical limitations

**Can be established:** which casts were dampened and by how much, from the audit log's
`cohort_overlap_mult`; current weights; and the overlap ratios *as they are now*.

**Cannot be undone:**

- ⚠⚠ Same immutability guarantee as its sibling. `weight_at_time` is a cast-time snapshot by
  design; the docblock states *"A later revocation that would change the overlap calculation
  does NOT retroactively reweight this cast — that's the snapshot guarantee."* **Rewriting is
  ruled out.**
- ⚠ **The overlap ratio at cast time is not recoverable from current data**, and this is worse
  than the reciprocity case. Reciprocity depends on one reverse edge that either exists or
  does not; overlap depends on the *entire* shape of two cohorts at a past instant. Every
  attestation cast or revoked since then changes it. So for a historical cast we can read
  *that* it was dampened (from the audit row) but **cannot recompute whether it should have
  been** — the inputs are gone.
- Audit retention bounds how far back even the recorded multiplier reaches.

**Therefore:** forward-only, and with less ability to review past decisions than the
reciprocity case.

## 7. Scoped read-only measurement needed first

Not run. Aggregate only. ⚠ The honest reading of weight alone is limited — `0.80` could be
reached by other factors, and a dampened cast may sit elsewhere once wallet age multiplies in.
The audit log's `cohort_overlap_mult` is authoritative.

```sql
-- Weight distribution, to see whether the dampener is reaching anyone.
SELECT COUNT(*)                     AS active_user_profile_rows,
       SUM(weight_at_time = 1.00)   AS at_baseline,
       SUM(weight_at_time = 0.80)   AS at_exactly_0_80,
       SUM(weight_at_time = 0.50)   AS at_exactly_0_50,
       SUM(weight_at_time = 0.40)   AS at_exactly_0_40_both_fired,
       SUM(weight_at_time < 0.40)   AS below_0_40,
       ROUND(AVG(weight_at_time),4) AS avg_weight
FROM wp_bcc_trust_attestations
WHERE revoked_at IS NULL AND target_kind = 'user_profile';

-- The small-denominator exposure: how many attestors have a tiny cohort,
-- i.e. how many people the 50% threshold is strictest against.
SELECT cohort_size, COUNT(*) AS attestors
FROM (
  SELECT attestor_user_id, COUNT(DISTINCT target_id) AS cohort_size
  FROM wp_bcc_trust_attestations
  WHERE revoked_at IS NULL AND target_kind = 'user_profile'
  GROUP BY attestor_user_id
) t
GROUP BY cohort_size ORDER BY cohort_size LIMIT 20;
```

⚠ `at_exactly_0_40_both_fired` was the compounding case. **After bcc-trust #315 no NEW row can
reach 0.40 by that route**, so a non-zero count there is now a count of rows cast *before* that
change — useful as a historical marker, no longer a live exposure.

⚠ Staging reconciliation found **0 attestation rows**, so on current data every one of these
returns zero. They are written for whenever there is something to measure.

## 8. Decisions needed

1. **Is penalising community density acceptable at all?** The spec says yes; the policy
   direction points the other way. This is the question.
2. **Fix the small-denominator defect (option C) immediately?** It is hard to defend on any
   reading and does not pre-empt (1).
3. **Graduated (B) or observation-only (E)?** E is the separation architecture; it needs the
   Tier 2 queue first.
4. ⚠ **Superseded by bcc-trust #315.** This asked whether to accept the `0.40` compounding or
   cap it. #315 restores reciprocity to baseline, so the combined case becomes `0.80` and the
   two mechanisms no longer multiply. No decision is needed on the compounding itself — only
   on whether a standalone ×0.80 for community density is wanted.
5. Confirm forward-only, and accept that past overlap ratios are **not recomputable**.
   ⚠ Lower stakes than first written: staging reconciliation found **0 attestation rows**, so
   there is no historical population here to reason about yet.
