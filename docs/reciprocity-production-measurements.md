# Five production measurements — reciprocity scoring

**Status: PREPARED FOR REVIEW. ⛔ NOT EXECUTED. Awaiting explicit scoped authorization.**

## ⚠⚠ Read this first — the staging reconciliation reframes why these exist

**There are no real users yet.** Measured read-only on staging, 2026-10-12:

| | |
|---|---|
| `wp_users` | **24** — ⚠ no ownership claim made; see the uncertainty note |
| `bcc_trust_user_info` | **24**, 1:1 with `wp_users`, **0 orphaned** |
| ⭐ `votes_cast > 0` | **0 of 24** |
| ⭐ `endorsements_given > 0` | **0 of 24** |
| `fraud_score > 0` | **0 of 24**; max score 0, max peak 0 |
| `is_suspended` | **0** |
| `bcc_trust_fraud_analysis` | **98 rows — but only 3 distinct users**, across 39 days, **every row scoring 0** |
| its trigger sets | **95 × `[]`**, 3 × `["behavior_ultra_fast_sessions"]` — no reciprocity, no fan-in |
| `bcc_trust_attestations` | **0** |
| `bcc_nft_holdings` | **0** |
| `bcc_wallet_links` | 2 |
| ring transients / pattern rows | **0 / 0** |

**So the 98 rows are repeated analysis of three accounts, not a member population.** ⚠ And
because `votes_cast` and `endorsements_given` are zero across the board, **no reciprocity or
fan-in rule has ever had an input** — which is the direct explanation for the zero transients,
zero patterns and zero attestations, rather than merely an observation alongside them.

⚠⚠ **What is NOT claimed: that these are internal accounts.** An earlier draft of this
section called them "internal/operator accounts". **That was an inference from email domains
and login presence, and an email domain is not proof of who owns an account.** It is dropped.

The load-bearing fact needs no such claim and is directly measured: **none of the 24 has cast a
vote or an endorsement.** Whoever they belong to, **none of them can have been affected by any
of these mechanisms**, because none of the mechanisms has had an input. That is sufficient for
every decision in these documents, and it does not require identifying anyone.

⭐ A useful side-effect: those 3 `behavior_ultra_fast_sessions` rows all score **0**, which is
live confirmation of a #314 assertion — `behavior_<flag>` triggers add no points.

**What this changes about these measurements.** Their purpose is **not** to size a remediation
programme; there is nothing to remediate. They are a **pre-launch correctness check**: run them
once there is real activity, to confirm the forward fixes behave as intended on real data. The
"historical limitations" sections in the sibling proposals remain accurate as analysis but are
**not prerequisites** for any fix, and no reversal ledger, appeal path or ledger migration is
proposed or needed.

### Uncertainty, stated rather than smoothed over

- ⚠⚠ **Account ownership is NOT established and is not needed.** Registrations span **21
  distinct days** (so not a seeded burst) and **9 of 24 have never logged in** — both facts
  about *rows*, carrying no claim about *people*. The only conclusion drawn is the measured one:
  **0 votes and 0 endorsements**, therefore no exposure to any of these mechanisms.
- ⚠ Specifically **not** relied on: email-domain composition. It was in an earlier draft as a
  classification signal and has been removed, because a domain cannot establish who owns an
  account and nothing here needs it to.
- `bcc_trust_tier_days` holds **1435** rows against 24 users (~60 each), which suggests a
  backfill or long accrual. Unrelated to scoring; noted so it is not mistaken for member
  activity.
- ⚠ My first probe named `bcc_onchain_holdings` and reported it ABSENT. That was **my naming
  error** — the real table is **`bcc_nft_holdings`**, which exists and is empty.

---

## The measurements themselves

These establish how many accounts carry a reciprocity-only fraud record and what it has cost
them, for whenever that question becomes live.

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
