# docs/archive — historical, point-in-time records

These documents are **dated snapshots and completed plans**. They are kept for
provenance, not as current guidance. They follow the project's **"dated filename +
do-not-edit-in-place"** convention: when a fresh pass is run, write a *new* dated file
rather than editing one of these.

**A new engineer should read `docs/` (current), not this folder.** Nothing here describes
how the system works *today* — for that, start from `docs/GOLDEN_PATHS.md`,
`docs/glossary.md`, `docs/domain-seams.md`, and `docs/api-contract-v1.md`.

| File | What it is | Superseded / status |
|---|---|---|
| `operational-audit-2026-05-13.md` | End-of-day platform-integrity sweep (SPOFs, silent-failure paths) | Historical snapshot |
| `stabilization-plan-2026-05-13.md` | Mobile/public-client stabilization plan (companion to the 05-13 audit) | All items shipped; frozen |
| `route-audit-2026-06-10.md` | REST route inventory + permission-posture audit | Historical; still referenced by `contract-parity-guard.php` and `api-contract-v1.md` for provenance |
| `perf-upgrade-audit-2026-06-18.md` | Performance & upgrade-path audit | Historical snapshot |
| `hardening-plan-2026-06-18.md` | Engineering-hardening plan (clean-code → production-grade) | Historical plan |
| `trust-attestation-phase-1-plan.md` | Trust Attestation Layer Phase 1 scope-freeze implementation plan (frozen 2026-05-13) | Shipped (`AttestationService` + attestations schema live; Slice E merged); frozen |
| `v2-phase-1-push-notifications.md` | V2 Phase 1 push-notifications spec (VAPID/web-push; frozen 2026-04-30) | Shipped (`PushSubscriptionRepository` + `minishlink/web-push` + `NotificationDispatcher` live); frozen |
| `build-plan-2026-06-28.md` | "Path to launch" status map (archived 2026-07-19) | Every item shipped or tracked elsewhere; superseded by `TODO.md` (active work) + `testnet-deploy-checklist.md` §7 (launch gates) |
| `backend-implementation-audit-2026-07-08.md` | 10-phase backend implementation audit + remediation log (archived 2026-07-19) | All HIGH/medium fixes shipped 2026-07-09; §5 residue items ported to `TODO.md`; kept for refutation methodology + clean-list |
| `backend-bug-hunt-2026-07-09.md` | Bug-hunt addendum to the 07-08 audit (archived 2026-07-19) | All findings fixed or refuted; kept for refutation reasoning |
| `backend-security-pass-2026-07-09.md` | Security/concurrency pass — 3 fixes + A1–A9 verdict table (archived 2026-07-19) | Fixes shipped; open observability items mirrored in `TODO.md`; kept for verdicts + live exploit proof |
| `docs-audit-report-2026-07-19.md` | Full documentation audit (pre-cleanup inventory, classifications, evidence) | Executed 2026-07-19/20; result recorded in `docs-cleanup-final-report-2026-07-19.md` |
| `docs-cleanup-final-report-2026-07-19.md` | Execution record of the documentation cleanup (what changed, validation results) | Final record of the 2026-07-19 docs pass |

> Still live and intentionally **not** here: `cadence-pressure-policy.md` (referenced by
> `cadence-pressure-guard.sh`), the dated deploy checklists, and `TODO.md` (the living
> debt registry).
