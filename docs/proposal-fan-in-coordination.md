# Proposal — `fan_in_coordination` (+15 per voter, and a ≥65 risk record)

**Status: PROPOSAL. Nothing implemented. No score reset, no historical repair.**

Found during the A1/A2/B/C scoring-separation work (bcc-trust #314), which did **not** change
it. ⚠⚠ **Unlike the two attestation dampeners, this mechanism DOES add fraud points** — it was
excluded from #314 only because it is a **fan-in** rule, not a reciprocity rule: it keys on
many accounts converging on **one page**, not on mutual exchange.

⚠ This corrects a statement in an earlier draft of the #314 report that grouped all three
mechanisms as adding "no fraud points". That was wrong, and wrong in the direction that
understated this one.

⚠ `fan_in_coordination` is documented **nowhere** in `docs/` today. This is its first
documentation.

Siblings: [`proposal-reciprocity-attestation-dampener.md`](proposal-reciprocity-attestation-dampener.md) ·
[`proposal-cohort-overlap-dampener.md`](proposal-cohort-overlap-dampener.md).

---

## 1. The trigger, exactly

`FraudDetector::detectFanInCoordination($pageId, $voterId)`, called from
`VoteFraudAnalyzer:95` during vote processing.

```
per-page dedup: if transient bcc_trust_fan_in_fired_<pageId> is set -> return false
                (1 hour TTL)

recentVoters = distinct voters on THIS page in the last 1 day
if count(recentVoters) < 5                              -> false
registrationDates = their user_registered values
if count(registrationDates) < 5                         -> false
spreadDays = (max - min) / 1 day
if spreadDays > 7                                       -> false
otherPageVotes = total distinct OTHER pages those voters voted on
if otherPageVotes >= 3                                  -> false

otherwise FIRES:
  1. fraud_analysis row for the PAGE OWNER, score = min(100, 40 + 5 * voterCount)
  2. audit log  fan_in_coordination_detected
  3. incrementFraudScore(+15, 'fan_in_coordination') for EVERY voter
  4. set transient (1 hour)
```

All three thresholds are **hard-coded literals**, not config constants: `5` voters, `7` days
of registration spread, `< 3` other pages.

### ⚠⚠ Two things about who is affected

**The +15 goes to the voters. The ≥65 risk record goes to the PAGE OWNER.**

```php
$pageOwnerId = PeepSoPageResolver::getOwnerId($pageId);
$fraudRepo->storeAnalysisWithAutoRisk(
    $pageOwnerId > 0 ? $pageOwnerId : $voterId,
    min(100, 40 + count($recentVoters) * 5),
    ['fan_in_coordination'], [...]
);
```

At the rule's own threshold of 5 voters that score is **65**, which
`FraudClassification::level()` classifies as **HIGH**; at 12+ voters it is **100, CRITICAL**.

⚠⚠ **So the person who was voted on — the target of the suspected coordination — receives the
HIGH-risk record.** If five accounts coordinate against a tradesperson's page, the
tradesperson gets the fraud-analysis row. That is the opposite of the intent as the surrounding
comment states it ("Store fraud signal for all participants").

**Precision that matters, and that an earlier draft got loose:** `storeAnalysisWithAutoRisk()`
writes **only** a `fraud_analysis` row. It does **not** touch `user_info.fraud_score`.
`FraudAnalysisRepository` has **no read method at all** — its public surface is store, delete,
count, orphan-cleanup and `tableExists()` — and the only readers of the table are direct SQL in
`AdminDashboardRepository` and the `moderation-user.php` history panel, both display. So:

| | Effect | Gated on? |
|---|---|---|
| **Voters** | `+15` live `fraud_score`, with `peak_fraud_score` bumped atomically, and the HIGH-crossing fan-out if it crosses 60 | ⚠ **YES** — every fraud gate reads `user_info.fraud_score` |
| **Page owner** | a `fraud_analysis` row at ≥65, risk_level HIGH/CRITICAL | **No** — record and display only |

The page owner's row is therefore an **allegation on an operator-facing screen**, not a
restriction. The voters' +15 is a real, compounding, permanently-floored penalty.

## 2. Legitimate counterexamples

⚠ The conjunction the rule requires — several new accounts, voting on one page, not much else
yet — is **precisely the signature of successful word-of-mouth onboarding**, which is how this
product is supposed to grow.

- ⭐ **A crew joins after one site visit.** A sparky tells four colleagues about BCC. They sign
  up over the same week (spread ≤ 7 days), all vouch for the person who told them, and have
  not got round to anything else (< 3 other pages). **Fires.** Five honest new members and the
  person who introduced them.
- **A hall's founding cohort.** An organiser sets up a local hall and the first members join
  together and back the organiser first. **Fires.**
- **A trade-show or toolbox-talk signup push.** Everyone registers the same afternoon.
  **Fires.**
- **A family business** adding its staff.
- **A supplier onboarding its regular trades.**

The `< 3 other pages` clause is the one that makes this sharp: it does not merely require new
accounts, it requires **accounts that have not done much yet** — i.e. the rule is most likely
to fire on a cohort's *first week*, before anyone has had a chance to look like a normal user.
A genuine ring, by contrast, can defeat it trivially by voting on three unrelated pages.

⚠ So the rule is **easy for a motivated attacker to evade and hard for an honest newcomer to
avoid**, which is the same inversion found in the ring detector during #314: most lenient
toward the accounts that warrant most scrutiny.

The 1-hour dedup transient bounds repetition but does not prevent recurrence: the comment notes
it expires "so a re-clustered attack within the same day still triggers" — which also means an
honest page can fire again later the same day.

## 3. Downstream effects

**For each voter**, the +15 enters `user_info.fraud_score` through
`incrementFraudScore()`, and from there:

- **Vote weight**: at `fraud_score >= 40`, a penalty of `(score/100) × 0.50` discounts every
  vote they cast.
- **Rate limits**: ×0.8 / ×0.6 / ×0.4 / ×0.2 by band, combined as
  `min(riskMultiplier, trustMultiplier)` so trust bonuses cannot offset it.
- **At 60+**: `AttestationService::assertFraudClear()` returns 403 *"Your account has been
  flagged for unusual activity"*, and `CredibilityAtoms::fraudClear()` blocks rank
  progression.
- **`peak_fraud_score` is bumped atomically**, so the penalty is permanently floored at
  `floor(peak × 0.5)` — and because the live write path is monotonic, an **active** member's
  score does not decay at all.
- **Crossing fan-out**: if the +15 crosses 60, every page they voted on or endorsed is
  re-scored.
- ⚠ **No notification.** The voter is never told, at any score.

**For the page owner**: an operator looking at their moderation screen sees a HIGH or CRITICAL
fraud-analysis row. No restriction follows, but the record is the thing a human would act on.

⚠ **It stacks.** Five new members each already carry `brand_new_account` (+20) or
`new_account` (+10) and likely `unverified_email` (+15) and `very_low_trust` (+30) — the signals
a legitimate newcomer naturally has. Add +15 and a cohort member can reach the 60 gate from
ordinary newness plus one fan-in event.

## 4. Forward correction

| # | Change | Effect |
|---|---|---|
| **A** | Stop the `+15` per voter; keep detection, the audit row and the record | Removes the only *restrictive* effect. Mirrors exactly what #314 did to B and C: detectors observe, they do not score. |
| **B** | Address the page owner. Either record the observation against the **page** rather than its owner, or stop writing a HIGH risk level for the owner | ⚠ Fixes the "victim gets the record" problem, which is hard to defend on any reading. |
| **C** | Add the corroboration the rule lacks — e.g. shared device/IP, or identical timing — before any consequence | ⚠ But per the agreed evidence standard, **shared devices, shared IPs and account proximity are explicitly NOT sufficient corroboration on their own**, so this cannot be the whole answer. |
| **D** | Promote the three hard-coded literals to config constants and re-calibrate | Minimum hygiene; makes the rule tunable and testable at all. |
| **E** | Route to the Tier 2 expiring review queue with no penalty | The separation architecture's answer. Needs the queue, which does not exist. |

**Recommended: A and B now, D alongside.** A and B remove the two effects that are hardest to
defend — a penalty on honest newcomers and a HIGH record against the person targeted — without
needing the queue. C is not viable alone under the agreed evidence standard. E is the
destination.

⚠ **Pre-work, as in #314: pin the behaviour first.** `detectFanInCoordination()` has **no test
coverage**, and the #314 work showed that writing the regression suite *before* editing is what
makes "nothing else changed" provable. The stub harness added in #314
(`tests/Stubs/fraud-signal-stubs.php`) already doubles the repositories this needs; it would
require `getRecentVotersWithRegistration()`, `countOtherPagesVotedByUsers()` and
`PeepSoPageResolver`.

## 5. Historical limitations

**Can be established:**

- ⭐ **Which pages fired, when, and with which voters** — `AuditLogger::log('fan_in_coordination_detected', $pageId, ['voter_count','spread_days','voter_ids'])`.
  **This is a genuine audit trail and is better than anything the ring increments left.**
- The `fraud_analysis` rows carrying the `fan_in_coordination` trigger, with their scores, and
  the `details` payload (`page_id`, `voter_count`, `spread_days`, `other_pages`, `voter_ids`).

**Cannot be reconstructed:**

- ⚠⚠ **A voter's +15 is not separable from their current score.** `incrementFraudScore()`
  accepts a `$reason` and **never uses it** — no audit row, no log line, no per-reason column.
  So although the *event* is audited against the page, the **arithmetic share of any given
  member's score** is not recoverable. Identical to the B/C limitation found in #314.
- ⚠ Each event's `+15` was then folded into later `round(analysis × 0.7 + existing × 0.3)`
  passes and into a monotonic `peak_fraud_score`, so it is **order-dependent** and cannot be
  unwound arithmetically.
- `fraud_analysis` rows expire (`BCC_TRUST_CLEANUP_FRAUD_ANALYSIS`, 90 days) and audit
  retention bounds the event history, so older events may be gone entirely.
- ⚠ The voters' registration spread and other-page counts **as they were at the time** are not
  recoverable; both move continuously.

**Therefore:** forward-only, with the same honest conclusion as #314 — **do not subtract
guessed contributions and do not reset accounts.** The audit trail is good enough to tell an
operator *which pages and cohorts were affected*, which is the useful thing; it is not good
enough to compute a correction.

## 6. Scoped read-only measurement needed first

Not run. Aggregate only, no identifiers.

```sql
-- How many fan-in events are on record, and what scores they wrote.
SELECT COUNT(*)                        AS fan_in_analysis_rows,
       MIN(fraud_score)                AS min_score,
       MAX(fraud_score)                AS max_score,
       SUM(fraud_score >= 60)          AS at_or_above_high,
       SUM(fraud_score >= 80)          AS at_or_above_critical,
       MIN(analyzed_at)                AS earliest,
       MAX(analyzed_at)                AS latest
FROM wp_bcc_trust_fraud_analysis
WHERE triggers LIKE '%"fan_in_coordination"%';

-- How many DISTINCT page owners carry such a record (the "victim" count).
SELECT COUNT(DISTINCT user_id) AS distinct_subjects
FROM wp_bcc_trust_fraud_analysis
WHERE triggers LIKE '%"fan_in_coordination"%';

-- Live transients, i.e. pages that fired within the last hour.
SELECT COUNT(*) AS pages_fired_recently
FROM wp_options
WHERE option_name LIKE '\_transient\_bcc\_trust\_fan\_in\_fired\_%';
```

⚠ There is **no equivalent query for the voters' +15**, and that absence is the finding: the
increment leaves no row of its own. The only route to the voter population is the `voter_ids`
list inside the audit payload, which is per-event and retention-bounded.

## 7. Decisions needed

1. ⚠⚠ **Should the page owner receive a HIGH-risk record for being the target of suspected
   coordination?** This looks like a defect rather than a policy choice, and is the most urgent
   item here.
2. **Stop the +15 (option A)?** It is the only restrictive effect, and it lands on exactly the
   cohort word-of-mouth onboarding produces.
3. **Is "several new accounts, one page, little other activity" ever sufficient for a
   consequence?** Under the agreed evidence standard, account proximity alone is not — and
   proximity is most of what this rule measures.
4. **Promote the three hard-coded literals to config (option D)?**
5. Confirm forward-only: the per-voter arithmetic share is **not recoverable**.
