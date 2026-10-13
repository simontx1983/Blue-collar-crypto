# Release handoff — bcc-trust production release `f403233f`, 2026-10-12

Production moved `9b748b03` → **`f403233f7d40d928b0f14f2da57f228637133614`** by one
`workflow_dispatch` deploy (`environment=production`, run `38259928264`, success).

---

## ⭐ Account status — operator-confirmed

**The accounts in both staging and production are fake/test accounts. Confirmed by the
operator, 2026-10-13.**

⚠⚠ **This is NOT inferred from email domains, and must not be.** An earlier draft of the
reciprocity analysis classified accounts by email-domain composition and called them
"internal/operator accounts". That was inference dressed as finding and it was removed. A domain
cannot establish who owns an account. The record here rests on the operator's statement about
their own system, which is the only evidence that could settle it.

**Consequence: no real member has ever been exposed to the scoring mechanisms this release
changes** — and that holds for two independent reasons:

1. the accounts are fake; **and**
2. the mechanisms never fired. Zero `vote_ring`, `in_vote_ring` and `fan_in_coordination`
   triggers have ever been recorded in production, and there are **zero active mutual
   attestation pairs**.

⛔ **No historical-remediation programme, score reset or data deletion is needed, and none has
been performed.** Production row counts are byte-identical before and after the deploy. Fake
data is still data; removing it would be a separate decision nobody has asked for.

### Production data as released (unchanged by the deploy)

| | |
|---|---|
| `wp_users` / `bcc_trust_user_info` | 43 / 54 |
| `bcc_trust_fraud_analysis` | 411 rows across 9 distinct users |
| `bcc_trust_attestations` | 11, all active, all `user_profile`, all `weight_at_time = 0.5000` |
| `SUM(weight_at_time)` | **5.5000** — immutable ledger held |
| accounts with `fraud_score > 0` | **2** (5/peak 10 and 7/peak 14, both `minimal`, 0 votes, 0 endorsements) |
| suspended / pattern rows / ring transients | 0 / 0 / 0 |
| schema stamp | `db054e2c71` → **`7fbb591807`** |

⚠ The 11 attestations sit at `0.5000`, but with **zero active mutual pairs** that dampening
cannot be reciprocity. The documented 0.50 wallet-age floor is the likely cause — stated as
inference, not measurement. Either way **#315 changed none of those rows**, and the two fraud
scores (0 votes, 0 endorsements between them) cannot be reciprocity-derived either. Both sit far
below the LOW band of 20, so no gate or restriction applies to them.

---

## Release inventory — reconciled

⚠⚠ **An earlier report said "23 PRs, #297–#316". Both halves were wrong.**

- **23 was the merge-commit count.** Seven of those are branch-update merges
  (`Merge remote-tracking branch 'origin/main' into HEAD`, and one
  `Merge origin/main into fix/fan-in-observational`), not pull requests.
- **The range is not contiguous.** Four numbers in `#297–#316` are absent.

**Actual release inventory: 16 PRs.**

```
#297 #298 #299 #300 #301 #302 #303 #304
#305 #306 #307 #310 #311 #314 #315 #316
```

Absent, and correctly so:

| PR | State | Why absent |
|---|---|---|
| #308 | OPEN | ring snapshot storage measurements, superseded in substance |
| #309 | CLOSED | superseded by the three-way split into #310/#311/#312 |
| #312 | OPEN | ⛔ **blocked**, and to be **rebuilt** on the scoring separation rather than conflict-resolved |
| #313 | OPEN | scoring trace / policy docs |

45 commits in total. No `DROP TABLE`, no `DROP COLUMN`, no `register_rest_route` change — the
REST contract is unchanged across the release. One `schema-*.php` change (#303) which removes a
blank line that was emitting `ALTER TABLE wp_bcc_chains ADD \`\` (\`\`)` plus a DB error on every
schema pass; same index set, strictly fewer errors.

---

## Production evidence

**Content-and-path manifest vs the merge commit: 1896 entries, digest
`58c156fdb9d79a3d0f497ed1b18c4547604edf37577b5a1d9e5858cd31a27feb` — identical**, 0 symlinks, 0
unreadable, 62 `bcc_*` tables. Built from merge-commit blob bytes, because the repo has no
`.gitattributes` and a CRLF working tree would otherwise produce a false mismatch.

**Deploy scope is plugin-only**, verified by mtime: webroot `.htaccess` 2026-08-19,
`wp-config.php` 2026-10-01, plugin dir **2026-10-12T14:02:01Z**.

### Read-only health checks — `cms.bluecollarcrypto.io`

| Check | Result |
|---|---|
| `/` | **200** |
| `/wp-json/` | **200**, 278,121 bytes |
| namespaces | `bcc/v1` and `bcc-trust/v1` both advertised |
| routes | **173** `/bcc/v1` + **15** `/bcc-trust/v1` |
| `/wp-json/bcc-trust/v1` | **200** |
| `/wp-json/bcc-trust/v1/health/read-model` | **401 `rest_forbidden`** — registered, permission callback enforcing (the correct answer to an unauthenticated probe) |
| leakage of `vote_ring｜fraud_score｜reciproc｜fan_in` | **0** |

⛔ **No provider-fetching endpoint was probed.** `nft-pieces` calls a provider and `/bcc/v1/groups`
masks a missing column; both were deliberately skipped. No `Authorization` header, real or fake,
was ever sent.

⚠ **Use `cms.bluecollarcrypto.io` for CMS health, not the apex.** An earlier check hit
`bluecollarcrypto.io/wp-json` and got `403` with `server: Vercel`, `x-vercel-mitigated: deny` —
the apex is fronted by the Next.js app and never reaches WordPress. That reading was topology,
not a regression, and "0 bcc routes" from it was meaningless.

### Application log

⚠ **The BCC application log is NOT the PHP `error_log`.** It resolves to
`dirname(ABSPATH) . '/bcc-logs/bcc-<secret>.log'`, i.e. **outside** the webroot — production at
`bluecollarcrypto.io/bcc-logs/`, staging at `public_html/bcc-logs/`. An earlier check used
`error_log` and reported "no new errors"; that file is not where the Logger writes, so the
reading established nothing.

Measured on the right file: **+98 lines, ERROR 1781 → 1787**. All six new ERRORs accounted for —
**five provider-side `Cosmos Fetcher LCD 500`** and **one known pre-existing
`Missing tables: bcc_trust_flags`**, which has fired exactly four times, all four being
production deploys (2026-09-30, 10-07, 10-11, 10-12). Pre-existing since 2026-07-09, and it does
not block stamping. **Zero `PHP Fatal`, `Uncaught`, `Undefined array key`. No new error class.**

⚠ The deploy also produced **54 "table created" log lines**. These are **not** creation — they
include `User info` and `Trust attestations`, which already hold data. It is the installer's
unconditional per-pass logging during the dbDelta run. No table was created or dropped.

---

## What shipped, behaviourally

| Mechanism | Before | After |
|---|---|---|
| `in_vote_ring` (group, blended) | +40 | **0** |
| `vote_ring` (pairwise, blended) | +25 | **0** |
| vote-ring detector (direct) | +15/member | **0** |
| endorsement-ring detector (direct) | +20/member | **0** |
| reciprocal attestation weight | ×0.50 | **×1.00** |
| fan-in: per voter | +15 | **0** |
| fan-in: page owner | `fraud_analysis` at **65 (HIGH)** | **no record** |

In every case the **observation is retained** — trigger, `details`, pattern row or audit field —
so nothing was deleted, only de-fanged. Confidence is unchanged throughout, because the
`details` entries that feed it were kept.

---

## Still open

| | |
|---|---|
| **#317** | `uniform_voting` observational — unmerged, 3/3 CI green |
| **#318** | cohort density observational — unmerged, 3/3 CI green |
| **#312** | ⛔ blocked; **rebuild, do not conflict-resolve** |
| **#313**, **#308** | docs, unmerged |
| umbrella **#174** | proposals + measurements, unmerged |

⚠ #317 and #318 have **zero file overlap and zero conflicts** with each other, so they may merge
in either order — unlike #315/#316, which shared a test stub.

## Remaining pre-launch scoring decisions

1. **Merge #317 and #318?** Both remove penalties on ordinary behaviour; neither introduces a new
   threshold.
2. **Diversity-bonus suppression for reciprocal sources** — the half of the old attestation spec
   never built. Deliberately not taken; it *lowers* some scores.
3. **`fan_in_coordination` thresholds** — still three hard-coded literals (5 voters / 7 days / <3
   pages), uncalibrated and trivially evadable.
4. **Tier 2 review queue** — observations are now recorded in several places but route nowhere an
   operator acts on.
5. **The five production measurements** remain prepared and unexecuted; their purpose is a
   post-launch correctness check, not remediation.

## Related

- [`reciprocity-production-measurements.md`](reciprocity-production-measurements.md)
- [`proposal-reciprocity-attestation-dampener.md`](proposal-reciprocity-attestation-dampener.md)
- [`proposal-cohort-overlap-dampener.md`](proposal-cohort-overlap-dampener.md)
- [`proposal-fan-in-coordination.md`](proposal-fan-in-coordination.md)
