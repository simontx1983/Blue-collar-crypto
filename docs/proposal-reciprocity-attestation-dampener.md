# Proposal — `ReciprocityPenaltyResolver` (the ×0.50 attestation dampener)

**Status: PROPOSAL. Nothing implemented. No `weight_at_time` rewrite, no score reset.**

Found during the A1/A2/B/C scoring-separation work (bcc-trust #314). That change removed the
four reciprocity contributions to `fraud_score`. **This mechanism was not in its scope and was
not touched** — it adds no fraud points. It does, however, produce an **adverse ranking
effect from a reciprocal relationship alone**, which is what makes it a policy question.

Related: [`trust-attestation-risk-assessment.md`](trust-attestation-risk-assessment.md)
§"Cartel formation" mitigation #3, which is this mechanism's spec.
Sibling: [`proposal-cohort-overlap-dampener.md`](proposal-cohort-overlap-dampener.md).

---

## 1. The trigger, exactly

`ReciprocityPenaltyResolver::weightFor($attestorUserId, $targetKind, $targetId)`, called from
`AttestationService` inside the same transaction-locked region as the attestation insert.

```
if targetKind !== 'user_profile'                 -> 1.00   (cards cannot cast back)
if attestor <= 0 or target <= 0 or self          -> 1.00
if the TARGET has ANY active vouch OR stand_behind
   on the ATTESTOR's user_profile                -> 0.50
otherwise                                        -> 1.00
```

**A single active reverse attestation is the whole rule.** From the class's own docblock:
*"any mutual pair, no minimum threshold … No grace period, no minimum reputation gap, no
temporal window — keep the rule legible."* Revoked rows do not count.

The result is multiplied into `weight_at_time` at cast time:

```php
$weightAtTime = self::DEFAULT_WEIGHT * $walletAgeMult * $reciprocityMult * $cohortOverlapMult;
```

## 2. ⚠⚠ The implementation does not match its own documented spec

This is the central finding, and it means a forward correction has an **already-approved
target** rather than needing new policy.

The spec, `trust-attestation-risk-assessment.md`:

> **Reciprocity penalty.** If A attests for B AND B attests for A, both attestations get
> **baseline weight only (no diversity bonus)**. Detects reciprocal backing **without
> forbidding it** — the attestations still count, they just **don't compound**.

Two divergences, both verified in code:

| | Spec says | Code does |
|---|---|---|
| **Kind** | withhold the **bonus** → effectively ×1.0 | **halve the base weight** → ×0.50 |
| **Target** | suppress the **diversity multiplier** so it does not compound | ⚠ **does not touch the diversity multiplier at all** |

On the second row: the 1.3× signal-source diversity multiplier lives in
`AttestationScoreSynthesis`, applied at synthesis, and
`AttestationScoreSynthesis:206` gates it **purely on the count of distinct sources** —
`count($sources) >= $diversityMinSources ? $diversityMultiplier : 1.0`. There is no
reciprocity or cohort input to it; the file's own docblock confirms the cast-time multipliers
are "NOT re-applied here". **So a reciprocal pair with enough distinct sources still receives
the diversity bonus**, while separately being halved at cast.

The code's docblock is candid that 0.50 came from elsewhere: *"Picked at 0.50 to mirror the
wallet-age floor and to neutralize a hypothetical 2× cartel-stacking effect."* That is a
cartel-deterrence rationale, not the independence correction the spec describes.

⚠ **Why this distinction is the whole question.** "We will not award an independence bonus to
a non-independent pair" is a sound correction — independence genuinely does not hold.
"We will halve the weight of your judgment because someone vouched for you back" is a penalty
for having a reciprocal relationship. The first is defensible on its own terms; the second is
the thing the policy direction rules out.

## 3. Legitimate counterexamples

In this product's population — tradespeople, site crews, local halls — mutual backing is the
**expected** shape of a real working relationship, not a deviation from it:

- **A sparky and a plumber who sub-contract to each other.** Each has first-hand knowledge of
  the other's work. They are the two best-qualified attestors in the pair, and the rule
  halves both.
- **Two operators of the same hall.** Each has watched the other run it for years.
- **A mentor and a former apprentice**, now both qualified. The apprentice's vouch for the
  mentor and the mentor's for the apprentice are *both* unusually well-founded.
- **A supplier and a builder** with a decade of invoices between them.
- ⚠ **The reciprocity is often the evidence of the relationship**, so the rule is sharpest
  exactly where the testimony is best-informed.

And note the asymmetry it creates: **order of casting decides who gets halved.** If A vouches
for B on Monday at ×1.00 and B reciprocates on Tuesday, B's cast is halved and A's is not,
because A's was snapshotted before the reverse edge existed. The same pair, cast in the other
order, penalises the other person. ⚠ Nothing about the relationship differs — only the
sequence — so the dampener is not even consistent within a pair.

## 4. Downstream effects

`weight_at_time` → `AttestationScoreSynthesis` → `attestation_bonus` → the canonical trust
score → rank and every surface that reads it.

- **Halved contribution to the target's attestation bonus.** The *target* of the reciprocal
  cast loses score, which is worth stating plainly: the penalty for A and B having a mutual
  relationship is paid by **whoever each of them vouched for**.
- **No fraud score, no risk level, no rate limit, no suspension, no allegation.** Nothing in
  the fraud subsystem reads it. It is not visible as a flag; it is visible only as a smaller
  number.
- ⚠ **It is recorded in the audit log** (`reciprocity_mult` in
  `attestation_vouch_created` / `attestation_stand_behind_created`), so the historical values
  are retrievable per cast even though the rows are immutable. That is the one piece of good
  news for reconstruction, and it is better than anything `fraud_score` has.
- Not user-visible as a reason. An operator whose vouch was halved is not told.

## 5. Forward correction

**Options, in order of how much they assume.**

| # | Change | Effect |
|---|---|---|
| **A** | `RECIPROCITY_MULTIPLIER` 0.50 → **1.00** | Stops the penalty. Brings the code to the "baseline weight only" half of its spec. Smallest possible diff: one constant. |
| **B** | A, **plus** gate the diversity multiplier on reciprocity in `AttestationScoreSynthesis` | Implements what the spec actually asked for: a reciprocal pair counts, but does not *compound*. Requires synthesis-side work and changes scores at the next synthesis. |
| **C** | Keep a dampener but make it evidence-led | Needs the corroboration standard that does not exist yet. Not recommended now. |

**Recommended: A now, B as a separate decision.** A is a one-constant change that removes a
penalty the spec never authorised. B is the genuine independence correction and deserves its
own review, because it *lowers* scores for some targets and that is a real consequence to
decide deliberately.

⚠ Whichever is chosen, keep `weightFor()` and its call site. A dead multiplier that always
returns 1.00 is cheap, keeps the audit field populated, and leaves one place to re-enable from
— which is what the separation architecture asks for: detectors emit, a separate decision
applies consequence.

⚠ **Sequencing.** Do **not** combine with the cohort dampener. They have different
rationales, different populations and different correct answers.

## 6. Historical limitations — what can and cannot be undone

**Can be established:**

- ⭐ **Exactly which casts were dampened, and by how much.** The audit log records
  `reciprocity_mult` per cast alongside `weight_at_time`, `wallet_age_mult` and
  `cohort_overlap_mult`. Unlike the fraud-score case, **this is a real audit trail.**
- Current `weight_at_time` per row, and which rows sit at a dampened value.
- The reverse-attestation state now, from the attestations table.

**Cannot be undone, and must not be faked:**

- ⚠⚠ **`weight_at_time` is a deliberate immutability guarantee**, not an oversight. From the
  class docblock: *"A future revoke of the reverse attestation does NOT retroactively reweight
  this cast — that's the snapshot guarantee."* `AttestationScoreSynthesis` explicitly does not
  re-apply the multipliers "that would double-penalise". **Rewriting those rows would destroy
  the property the ledger exists to provide**, and is ruled out.
- So **every historical reciprocal cast stays halved**, and the only honest forward position
  is that new casts are not dampened while old ones remain as cast.
- ⚠ A "re-cast to refresh the weight" workaround would also change
  `attestation_order_in_target` and `created_at`, which drives the decay clock. It is not a
  correction; it is a different attestation. The `uq_active_attestation` unique key
  (`attestor, target_kind, target_id, kind, revoked_at`) means it would additionally require
  revoking the original first.
- Audit retention bounds how far back the `reciprocity_mult` history reaches; the attestation
  rows themselves are not time-limited, but reconstructing *why* a given weight was chosen
  depends on the audit row surviving.

**Therefore:** this proposal is forward-only by construction. That is a stronger constraint
than the fraud-score case, where the obstacle is missing data; here the obstacle is a
guarantee we are choosing to keep.

## 7. Scoped read-only measurement needed first

Not run. Aggregate only.

```sql
-- How many attestations carry a reciprocity dampening, and what it is worth.
SELECT COUNT(*)                                        AS attestation_rows,
       SUM(weight_at_time = 0.50)                      AS at_exactly_half,
       SUM(weight_at_time < 1.00)                      AS below_baseline_any_cause,
       ROUND(AVG(weight_at_time), 4)                   AS avg_weight,
       COUNT(DISTINCT attestor_user_id)                AS distinct_attestors
FROM wp_bcc_trust_attestations
WHERE revoked_at IS NULL AND target_kind = 'user_profile';

-- How many ACTIVE mutual pairs exist at all — the population a change would affect.
SELECT COUNT(*) AS mutual_pairs
FROM wp_bcc_trust_attestations a
JOIN wp_bcc_trust_attestations b
  ON b.attestor_user_id = a.target_id
 AND b.target_id        = a.attestor_user_id
 AND b.target_kind      = 'user_profile'
 AND b.revoked_at IS NULL
WHERE a.target_kind = 'user_profile' AND a.revoked_at IS NULL
  AND a.attestor_user_id < b.attestor_user_id;
```

⚠ `weight_at_time = 0.50` is **not** a clean indicator on its own: the wallet-age weighter and
the cohort dampener also multiply in, so 0.50 could be reached other ways and a dampened cast
could sit at some other value. The audit log's `reciprocity_mult` is the authoritative source
and should be the one read if the question matters.

## 8. Decisions needed

1. **Is withholding a bonus acceptable where a penalty is not?** The whole proposal turns on
   this, and option B depends on the answer being yes.
2. **Option A now (one constant), or A+B together?** A stops the penalty immediately; B is the
   correction the spec intended but lowers some scores.
3. **Confirm forward-only.** The immutability guarantee means historical casts stay halved.
   That needs to be an accepted outcome, not a deferred one.
4. Does the **order-dependence** within a pair (§3) change the urgency? It is arguably the
   clearest evidence that the current rule is not measuring anything real.
