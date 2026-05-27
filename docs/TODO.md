# Active TODO

Living checklist of work that is a real candidate for the next few weeks.
Check items off as they ship. New items append to the appropriate section
with a one-line title + a source link or `file:line` ref.

**Scope discipline:** this file is *active candidates only*. Demand-gated
items (public API, mobile, i18n), post-MVP structural debt, and mid-bake
forbidden items live elsewhere — see the [Parked / elsewhere](#parked--elsewhere)
section at the bottom. Don't pad the active list.

---

## Frontend

- [ ] [`BlogComposer.tsx:16`](../bcc-frontend/src/components/blog/BlogComposer.tsx#L16) — wire `?edit=<id>` initialValues fetch for blog-post-edit hydration (tagged V1.5).
- [ ] [`RightSidebar.tsx:12`](../bcc-frontend/src/components/layout/RightSidebar.tsx#L12) — replace static placeholders (Top Directories / Trending / Suggested Members) with TanStack Query hooks (tagged Phase 2 API integration).

## Observability

<!-- subsystem-count guard shipped 2026-05-26 — see Recently shipped below -->
- [ ] **API-contract-vs-code parity probe** — for each endpoint claimed in `api-contract-v1.md` §4, hit the live site and verify the response shape matches the documented envelope/raw-array/field-set. The v1.21 envelope-drift retraction (2026-05-26) showed the contract can drift away from code in *both* directions — claiming drift that doesn't exist, OR claiming code that doesn't ship. A live-probe guard catches both. Sibling to `subsystem-count-guard.php` in shape.


## Docs

- [ ] Refresh frontend-coverage docs for v1.19 + v1.20 surfaces — [`trust-engine-coverage.md`](trust-engine-coverage.md) (last audit 2026-04-30) and [`v1-smoke-test-checklist.md`](v1-smoke-test-checklist.md) (2026-04-30) both predate the wallet-auth endpoints (§4.1 + §4.24) and the endorse error-code Phase γ stabilization (§1.4.6). Needs real coverage entries, not metadata fixes.

---

## Recently shipped

Once an item ships, move it here with a date + commit hash so the
file documents recent flow. Trim entries older than ~30 days.

- 2026-05-26 — Holder-group provisioning sweep observability wired. New `gated_group_provision` DegradationMetric subsystem (3 events: `peepso_absent`, `no_admin_owner`, `group_create_failed`) records the three operationally-distinct failure modes inside `GatedGroupProvisioningService::provisionAll`. Daily cron sweep retries unprovisioned collections on each tick; sustained activation = retry path not catching up. Closes the last "Pending wirings" item in pattern-registry. Subsystem-count-guard now reports 21 subsystems.
- 2026-05-26 — Helius webhook dedup-skipped observability wired. New `helius_dedup` DegradationMetric subsystem (1 event: `replay_skipped`) records inside `HeliusWebhookEndpoint::handle` every time `HeliusSeenSignaturesRepository::markSeen` returns false. The existing `bcc_helius_signature_seen_total` wp_options counter (admin panel) stays untouched — different surface, different consumer. Subsystem-count-guard now reports 20 subsystems. One item closed from pattern-registry's "Pending wirings"; holder-group sweep retries remains.
- 2026-05-26 — Cross-plugin §γ stable-code migration complete (3 commits). **bcc-search** (`aaedf42`): 11 sites in SearchController + UserSearchController + GroupSearchController — `rate_limit_exceeded` / `categories_unavailable` / `rebuild_in_progress` / `score_enrichment_failed` / `temporarily_overloaded` / `user_search_unavailable` / `group_search_unavailable` → standard §1.4.6 codes (`bcc_rate_limited`, `bcc_upstream_unavailable`, `bcc_internal`). **bcc-trust REST surfaces** (`98a26da`): 11 sites in XController, GitHubController, PageEndpoint, EntityClaimEndpoint, FlagEndpoint — `x_error`, `github_error`, `rate_limited`, `not_found` → standard codes. Bonus: EntityClaimEndpoint and FlagEndpoint weren't in the original audit. **bcc-trust legacy `error()` helpers** (`657f212`): AdminStatsController (8 sites, helper deleted) + UserStatusController (4 sites, helper deleted) migrated to `errorWithCode()` pattern. Bonus: IndexerTickEndpoint `bcc_misconfigured`/`bcc_indexer_failed` → `bcc_internal`. **Out of scope** (documented as service-layer infrastructure, not REST drift): bcc-core SafeHttpClient (6 SSRF codes) + bcc-trust BlockchainQueryService (5 wallet SSRF codes). No new contract codes needed; every drift mapped to existing §1.4.6 entries. All four guardrails green throughout.
- 2026-05-26 — Full TrustRestController migration to `errorWithCode()` complete. All 9 remaining `self::error()` sites mapped to §1.4.6 stable codes (`bcc_internal`, `bcc_invalid_request`, `bcc_rate_limited`, `bcc_forbidden`). The legacy `error()` helper deleted entirely (no callers left). Dead 503 branch in `safeExceptionError` deleted under fresh-install policy (no exception in bcc-trust throws with code 503; the docblock literally said "reserved"). Controller is now 100 % §γ-compliant.
- 2026-05-26 — Envelope drift "sweep" turned out to be a doc-only correction (contract v1.21). Live curl of `/chains` proved both endpoints were ALWAYS enveloped by `Envelope::wrap()`; the v1.19 "raw-array drift flagged" notes were authored from a wrong reading of the controller code. No server-side change. Bonus discovery: contract claims aren't verified against runtime — added a sibling parity-probe to Active.
- 2026-05-26 — Subsystem-count guard (`scripts/subsystem-count-guard.php`) — diffs canonical map in `bcc-core.php` against `pattern-registry.md` + `GOLDEN_PATHS.md`. First run caught two undetected drifts: `audit_log_swallow` docs claimed 4 events but canonical had 3 (my earlier "fix" had aligned to the wrong source); `account_security_mail` docs lagged behind the 2026-05-16 Tier D sixth-event addition. Both fixed.
- 2026-05-26 — Endorse error-code Phase γ stabilization (contract v1.20). `bcc-trust 3e27fa5`, `bcc-frontend e9b4caf`, contract `921ee0f`.
- 2026-05-26 — `cron_dispatch` DegradationMetric subsystem closes the fraud-analyzer enqueue silent-failure gap. `bcc-core 4f3e686`, `bcc-trust a9b07f9`, docs `469fdda`.
- 2026-05-26 — Drift-audit mechanical fixes (audit_log_swallow count, api-contract header, cron-registry cross-refs + hook names). `c4f44bd` + `2ce719a`.
- 2026-05-25 — Contract v1.19 (wallet-auth endpoints + §4.24 Wallets) + `legacy_ajax 9→3` retirement. `b1984f3` + `67aed16`.

---

## Parked / elsewhere

The active list deliberately excludes the following. Look here when one
becomes a candidate.

- **Demand-gated** (public API, native mobile, i18n, Backer concept, Injective/NEAR/Thorchain NFT support, gallery list endpoints) → [`v2-roadmap.md`](v2-roadmap.md). Don't start without external evidence of need.
- **V2 engagement polish** (sound on Heavy celebrations, streak-freeze mechanic, network percentile on others' profiles, per-category highlight muting) → [`v2-roadmap.md`](v2-roadmap.md). Open, but ranks below the items above.
- **Trust attestation Phase 1.5+** (profile-scoped disputes, reliability badges, validator/builder gauges, meta-dispute flow) → [`trust-attestation-phase-1-plan.md`](trust-attestation-phase-1-plan.md). Design-gated.
- **Acceptable post-MVP debt** (V-07 dual-namespace REST shim collapse, `bcc_project_*` table-prefix rename, ERROR_COPY centralization across 19 components) → [`stabilization-plan-2026-05-13.md`](stabilization-plan-2026-05-13.md) (frozen).
- **Mid-bake forbidden** (NFT observability X1–X5 / F2 / F6 expansion, Stabilization Phase D, frontend gap-audit D2-full / D3 / D4) → tracked in Claude memory; do not start without explicit approval.

---

## Rules for editing this file

- One-line items only. If detail is needed, link the source doc / code.
- Items leave by being checked off + moved to **Recently shipped**, not by being deleted in-place. Audit trail.
- The **Parked** section is a *pointer*, not a copy — each entry links to where the real list lives. Don't duplicate scope here.
- Forbidden items are NEVER in **Active**, even with `- [ ]` discipline. They go in **Parked** or stay out entirely.
- File ordering inside a section is rough-priority. Reorder freely; don't add severity labels (they drift).
