# Validator Messaging — Staging Rollout Runbook

**Audience:** Phillip + Tialuxe (operators).
**Scope:** enabling the validator-messaging feature on **staging**. Production is a
separate, explicitly-approved rollout — see [Production](#production-separate-and-held).
**Related:** [operator-runbook.md](operator-runbook.md) · [deploy-runbook.md](deploy-runbook.md) ·
[GOLDEN_PATHS.md](GOLDEN_PATHS.md) · [wallet-privacy-policy.md](wallet-privacy-policy.md).

---

## What is already true

- **The code is merged and deployed, but shipped DARK.** The validator-messaging
  chain (operator DMs + pre-claim queue) is on `main` across bcc-core / bcc-trust /
  bcc-frontend and auto-deployed to staging. It is gated behind a kill-switch and
  does nothing until that switch is on.
- **The kill-switch defaults OFF.** Option `bcc_validator_messaging_enabled`
  (bcc-trust) is absent/false by default → the send endpoints, the card/profile
  messaging affordances, and the delivery worker all no-op. Enabling is a
  deliberate, gated operator action; this runbook is that action, **on staging
  first.**

## Two facts that shape the gates

1. **Applying the per-entity DB constraint does NOT eliminate page-level
   ambiguity.** `scripts/claims-verified-operator-constraint.php apply` adds a
   UNIQUE key that stops *one validator entity* from having two verified
   operators. But a *page* wired to two validators with two different operators is
   still ambiguous — and that is fine.
2. **Runtime operator resolution is the authoritative fail-closed guard.**
   `ClaimRepository::resolveVerifiedOperatorForPages` returns UNCLAIMED / RESOLVED
   / **AMBIGUOUS**, and every non-RESOLVED case collapses to a single generic
   `bcc_messaging_unavailable` 409 (no operator identity/count leak). The
   constraint is a backstop for the single-validator duplicate case only. **Never
   read "constraint applied" as "ambiguity is now impossible."** Do not gate the
   kill-switch on the constraint alone; the resolver is what makes go-live safe.

## Preconditions

- You are on **staging**, not production. Confirm the target before every
  mutating command (see gate A note).
- The validator-messaging code is deployed to staging (it is, via `main`).
- `wp` below is the staging WP-CLI invocation (substitute the full PHP+phar form
  per [GOLDEN_PATHS.md](GOLDEN_PATHS.md) ▸ Prerequisites if needed).

---

## Ordered gates

Run in order. Do not skip. A failure at any gate stops the rollout.

### A. Audit for pre-existing constraint violations

```bash
wp eval-file wp-content/plugins/bcc-trust/scripts/claims-verified-operator-constraint.php
```

- **Clean** (`Duplicate audit: CLEAN`) → go to B.
- **Dirty** (`DUPLICATES (n) …`) → **STOP.** Report each offending validator /
  page / claim / user ID, status, and timestamps. **Never** auto-delete, merge,
  supersede, or select a winner — duplicate remediation is an operator decision
  (prefer flipping losers to `status='superseded'`; never DELETE evidence). Re-run
  the audit until clean, then continue.

The audit counts verified-operator claim **rows** per entity (`COUNT(*)`), which
is exactly what the `apply` constraint rejects — so a clean audit guarantees the
ALTER in B will not fail on a duplicate key.

### B. Apply the constraint (only if A is clean)

```bash
wp eval-file wp-content/plugins/bcc-trust/scripts/claims-verified-operator-constraint.php apply
```

Adds the generated `verified_operator_slot` column + `uq_verified_operator` UNIQUE
key. Safe by construction: MySQL ≥ 5.7 gated, refuses on a dirty audit, idempotent
(`SKIP` if already present), `ADD COLUMN` only (no data loss). Expect `APPLIED:`.

### C. Re-audit and verify the DB now rejects a second verified operator

```bash
wp eval-file wp-content/plugins/bcc-trust/scripts/claims-verified-operator-constraint.php
```

Expect `Constraint column present: YES (nothing to do)` and `Duplicate audit:
CLEAN`. Optionally confirm rejection directly: attempt to insert a second verified
operator claim for an entity that already has one (in a scratch/throwaway context)
and confirm the DB returns a duplicate-key error.

### D. Disabled-state validation (switch still OFF)

Do all of this **before** flipping the switch:

- **Golden masters** — recapture / re-pin the affected golden masters (validator
  cards + a validator operator's profile). With the switch OFF, validators must
  expose `destination: none` for the messaging affordance.
- **Query-floor probe** — run the query-floor probe; expect **no more than +7
  flat** queries versus the pre-feature floor.
- **Authenticated smoke matrix** — with the switch flipped ON in a **controlled
  staging check** (you may enable, run the matrix, and disable again, or run the
  matrix immediately after gate E — operator's choice, but every case below must
  pass before the rollout is considered complete):

  1. claimed validator → direct message delivered;
  2. unclaimed validator → message queued;
  3. first claim → one-time backlog release;
  4. release bypasses **only** `chat_enabled` and `friends_only` (nothing else);
  5. block before delivery → item `suppressed` (never delivered);
  6. suspended sender → denied;
  7. banned sender → denied;
  8. self-send → denied;
  9. ambiguous operator resolution → fails closed (generic 409);
  10. previously-claimed / transferred mismatch → fails closed (generic 409);
  11. no duplicate delivery (at-most-once holds under a re-run / concurrent sweep);
  12. operator inbox reply works.

### E. Enable on staging (only after every gate passes)

```bash
wp option update bcc_validator_messaging_enabled 1
```

Then run a focused post-flip smoke test (at minimum cases 1, 2, 3, 5, 11 from the
matrix) and confirm the option value:

```bash
wp option get bcc_validator_messaging_enabled   # expect: 1
```

### F. Rollback (staging)

```bash
wp option update bcc_validator_messaging_enabled 0
```

Rollback is instant and **non-destructive**: with the switch OFF the delivery
worker no-ops and **all queued rows are preserved** (nothing is dropped or
delivered). Re-enabling later resumes cleanly from the existing queue.

---

## Production (separate and held)

Production rollout is **out of scope** for this runbook and is **held** pending
explicit approval. When authorized, it is its own pass of gates A–F against
production, plus the additional pre-existing gate recorded in
[wallet-privacy-policy.md](wallet-privacy-policy.md): the PeepSo-Gravatar config
check must be confirmed before any production enablement. Do not enable
`bcc_validator_messaging_enabled` in production as part of a staging rollout.
