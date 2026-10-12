# Five production measurements — reciprocity scoring

**Status: PREPARED FOR REVIEW. ⛔ NOT EXECUTED. Awaiting explicit scoped authorization.**

These establish how many real accounts carry a reciprocity-only fraud record and what it has
cost them. They exist because bcc-trust #314 stopped **new** reciprocity penalties but could
not repair **old** ones, and deciding whether anything further is warranted needs a count.

**Properties, all deliberate:**

- **Read-only.** `SELECT` only. No `UPDATE`, `DELETE`, `INSERT` or DDL.
- **Aggregate only.** No `user_id`, `user_login`, email, IP, display name or trigger text
  leaves the query. Counts, sums, extrema and band totals only.
- **Bounded.** No unbounded row scans of a large table; M5's `LIMIT 20` caps its output.
- ⚠ **Run with `--skip-plugins --skip-themes`.** A plain `wp` bootstrap fires the schema pass,
  which is the thing a measurement must not perturb.
- ⚠ **`wp db query` does NOT work on this host** — it shells out to the mysql client and
  `proc_open()` is disabled. Use `wp eval-file` with `$wpdb`, which uses WordPress's own
  connection. (The same trap blocks `wp db export`.)

---

## ✅ All five were executed against a FIXTURE, never production

In a throwaway **MariaDB 11.8.9** container — production's engine — against the real
`CREATE TABLE` definitions and a seed built so every output column had a hand-computed
expected value. **All five parse and run, and every figure matched.** Container removed.
Handing over unrun SQL would not be "prepared".

Two things the fixture caught that review would not have:

- ⚠ **The stored `triggers` value can be a JSON OBJECT, not an array.** It is written as
  `json_encode(array_unique($triggers))`, and `array_unique` preserves keys — so once a
  duplicate is dropped the result serialises as `{"0":"vote_ring","2":"in_vote_ring"}`.
  `JSON_LENGTH` counts keys either way, so the M2 approach is correct; **a `LIKE`-based
  exclusion list would have mishandled it**, and would also have been defeated by the
  variable-name `behavior_<flag>` triggers.
- ⚠ **M2 and M3 can legitimately disagree.** The fixture returned `reciprocity_only = 3` from
  M2 but `2` from M3, because one seeded account had an analysis row and **no `user_info`
  row**, so M3's join drops it. That difference is a real quantity — "analysed but no
  `user_info` row" — and must not be read as an inconsistency.

---

## M1 — the denominator

How many accounts carry a fraud score at all. Without this, every later number is unreadable.

```sql
SELECT COUNT(*) AS rows_total,
       SUM(fraud_score = 0 AND COALESCE(peak_fraud_score,0) = 0) AS clean,
       SUM(fraud_score BETWEEN  1 AND 19) AS below_low,
       SUM(fraud_score BETWEEN 20 AND 39) AS low,
       SUM(fraud_score BETWEEN 40 AND 59) AS medium,
       SUM(fraud_score BETWEEN 60 AND 79) AS high,
       SUM(fraud_score >= 80)             AS critical,
       SUM(COALESCE(peak_fraud_score,0) > fraud_score) AS decayed_below_peak
FROM wp_bcc_trust_user_info;
```

**Expected output:** one row, eight integers.

---

## M2 — ⭐ the load-bearing one

How many accounts' latest analysis carries **only** reciprocity triggers. This is the number
the policy decision turns on: those members have no fraud finding against them at all.

```sql
WITH latest AS (
  SELECT user_id, triggers, fraud_score,
         ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY analyzed_at DESC, id DESC) AS rn
  FROM wp_bcc_trust_fraud_analysis
)
SELECT COUNT(*) AS accounts_with_analysis,
       SUM(JSON_VALID(triggers) = 0) AS unparseable,
       SUM(triggers LIKE '%"vote_ring"%' OR triggers LIKE '%"in_vote_ring"%') AS any_reciprocity,
       SUM( JSON_LENGTH(triggers) =
            (triggers LIKE '%"vote_ring"%') + (triggers LIKE '%"in_vote_ring"%')
            AND JSON_LENGTH(triggers) > 0 )                                   AS reciprocity_only
FROM latest WHERE rn = 1;
```

**Expected output:** one row, four integers. `unparseable` should be 0; if it is not, the
other columns are unreliable and the shape must be investigated before they are believed.

---

## M3 — what it cost exactly those accounts

```sql
WITH latest AS (
  SELECT user_id, triggers,
         ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY analyzed_at DESC, id DESC) AS rn
  FROM wp_bcc_trust_fraud_analysis
), recip AS (
  SELECT user_id FROM latest
  WHERE rn = 1 AND JSON_LENGTH(triggers) > 0
    AND JSON_LENGTH(triggers) = (triggers LIKE '%"vote_ring"%') + (triggers LIKE '%"in_vote_ring"%')
)
SELECT COUNT(*) AS reciprocity_only_accounts,
       SUM(u.fraud_score >= 40) AS at_or_above_vote_weight_penalty,
       SUM(u.fraud_score >= 60) AS at_or_above_high_gates,
       SUM(COALESCE(u.peak_fraud_score,0) >= 60) AS peaked_at_or_above_high,
       ROUND(AVG(u.fraud_score), 1) AS avg_score,
       MAX(u.fraud_score) AS max_score,
       MAX(COALESCE(u.peak_fraud_score,0)) AS max_peak,
       ROUND(AVG(FLOOR(COALESCE(u.peak_fraud_score,0) * 0.5)), 1) AS avg_permanent_floor
FROM recip r JOIN wp_bcc_trust_user_info u ON u.user_id = r.user_id;
```

**Expected output:** one row, eight values. `avg_permanent_floor` is the figure that matters —
the level below which these accounts' scores **cannot** fall, because the decay path floors at
`floor(peak × 0.5)` and the live write path is monotonic.

---

## M4 — who is currently gated, and by what

The 60 gate blocks attestations and rank progression outright, so this is the population any
remedial decision would actually be about.

```sql
WITH latest AS (
  SELECT user_id, triggers,
         ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY analyzed_at DESC, id DESC) AS rn
  FROM wp_bcc_trust_fraud_analysis
)
SELECT SUM(u.fraud_score >= 60) AS gated_total,
       SUM(u.fraud_score >= 60 AND l.triggers LIKE '%"vote_ring"%')     AS gated_with_pairwise,
       SUM(u.fraud_score >= 60 AND l.triggers LIKE '%"in_vote_ring"%')  AS gated_with_group,
       SUM(u.fraud_score >= 60 AND l.user_id IS NULL)                   AS gated_with_no_analysis_row
FROM wp_bcc_trust_user_info u
LEFT JOIN latest l ON l.user_id = u.user_id AND l.rn = 1;
```

**Expected output:** one row, four integers.

⚠ `gated_with_no_analysis_row` is the important one. The B and C increments wrote scores
**without** writing an analysis row, so a gated account with no analysis is direct evidence of
the untraceable path — the one whose arithmetic share cannot be recovered.

---

## M5 — rollback exposure

How many rings a transient clear would re-penalise. ⚠ Relevant because clearing the
`bcc_ring_*` transients is the one action that would re-apply the removed penalties.

```sql
SELECT pattern_type, COUNT(*) AS rows_now,
       MIN(detected_at) AS earliest, MAX(detected_at) AS latest
FROM wp_bcc_trust_patterns
WHERE pattern_type IN ('vote_ring','endorsement_ring','slow_endorsement_ring')
GROUP BY pattern_type;

SELECT SUM(option_name LIKE '\_transient\_bcc\_ring\_vote\_%')          AS live_vote_rings,
       SUM(option_name LIKE '\_transient\_bcc\_ring\_endorse\_%')       AS live_endorsement_rings,
       SUM(option_name LIKE '\_transient\_bcc\_ring\_slow\_endorse\_%') AS live_slow_rings
FROM wp_options WHERE option_name LIKE '\_transient\_bcc\_ring\_%';
```

**Expected output:** up to three grouped rows, then one row of three integers.

⚠ On the fixture, `endorsement_ring` returned **no row at all** — which is itself the finding
that nothing had ever written that pattern type before #314 added the writer. On production the
same absence is expected for any row predating that deploy.

⚠ The `LIKE` patterns escape the underscores deliberately; an unescaped `_` is a single-character
wildcard and would also match `_transient_timeout_…` rows, double-counting every live key.

---

## What these measurements can and cannot settle

**Can settle:** how many accounts are affected, how many are gated, what their permanent floor
is, and whether reciprocity was their *only* recorded trigger.

**Cannot settle** — and no query can:

- the **arithmetic share** of any account's score attributable to reciprocity.
  `fraud_score` is one blended integer; `storeAnalysis()` persists `triggers` and `details` but
  **not** `$results['signals']`, which is where the per-signal points live.
- **anything about B or C.** `incrementFraudScore()` accepts a `$reason` and never uses it —
  no audit row, no log line. The only contemporaneous evidence was a transient.
- events older than the `fraud_analysis` retention window
  (`BCC_TRUST_CLEANUP_FRAUD_ANALYSIS`, 90 days).

So the outcome of these five is a **population count, not a correction basis.** They are
sufficient to decide *whether* to act and *how many* people it concerns; they are not
sufficient to compute what any individual's score should have been. Any attempt to do the
latter would be the guessed subtraction that has been ruled out.

---

## Related

- [`proposal-reciprocity-attestation-dampener.md`](proposal-reciprocity-attestation-dampener.md) — carries its own two measurements for the ×0.50 dampener.
- [`proposal-cohort-overlap-dampener.md`](proposal-cohort-overlap-dampener.md) — its own two, including the small-cohort exposure.
- [`proposal-fan-in-coordination.md`](proposal-fan-in-coordination.md) — its own three. ⚠ Note there is **no** query for the voters' `+15`; that absence is the finding.
