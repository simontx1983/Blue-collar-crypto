# BCC Backend — Implementation-State Audit (Full 10-Phase)

**Method:** reverse-engineering from executable code. Docs/tests/comments treated as claims; every conclusion is anchored to `file:line`. Read-only throughout (MySQL SELECT-only). Every negative/high finding was **adversarially refuted** (R5) before inclusion.
**Date:** 2026-07-08 · **Scope:** `app/public/wp-content/plugins/{bcc-trust,bcc-core,bcc-search}` (frontend excluded).

---

## 0. How this audit ran (and how much to trust it)

A 6-phase agent pipeline over the code + live dev DB, **all phases completed**:

| Phase | What ran | Result |
|---|---|---|
| P0 | Mechanical inventory: guards, ledgers, MySQL row counts, PHPUnit | 193 routes, 65 physical tables, baselines green |
| R1 | 12 architecture-subsystem reconstructions | 60 findings |
| R2 | 26 agents traced **every** REST route end-to-end | 200 route-variants — **194 Fully + 6 Mostly Implemented, 0 broken** |
| R3 | 12 table + 15 feature + 6 residue agents | 48 tables, 15 features, 74 residue items |
| R4 | 11 security + 6 bug-hunt sweeps | 38 findings + **219 checked-and-clean surfaces** |
| R5 | 13 adversarial refuters on 89 negative/high findings | **77 CONFIRMED · 6 REFUTED · 6 DOWNGRADED** |

**Why R5 matters:** this repo's history includes an audit that *misdiagnosed* a "missing dispute writer" that existed. R5 caught the same class of error in **this** audit — it refuted 6 findings and downgraded 6, including two I had initially flagged as HIGH. The findings below are the survivors. Total findings DB: **360** (post-refutation).

---

## Remediation log — 2026-07-09

First "high-payoff / low-effort" batch **shipped** (working tree; lint + arch-guardrails + 295 unit tests + PHPStan L8 + contract-parity all green):

- ✅ **[H-2] JWT revocation on credential change** — `reset_password` now bumps the token-version (revokes stolen tokens; user isn't logged in there); password *change* revokes-then-mints a fresh token and returns it (`token`/`expires_in`/`token_type`) so the legitimate session survives. Contract §`PATCH /me/account/password` updated. Files: `PasswordAuthController.php`, `MyAccountEndpoint.php`.
- ✅ **[M] Group-rejoin gate** — `MyGroupsEndpoint::postJoin` now rejects suspended/banned accounts (`Permissions::is_not_suspended($userId, false)`, admin-bypass off) before the PeepSoGroupWriter door.
- ✅ **[M] Creator-gallery dead branch** — `Core/Plugin.php` gate fixed `supports_feature('nft')` → `'collection'`; the async gallery refresh runs again.
- ✅ **[M] Legacy `/endorse` throttle** — `TrustRestController::endorse` + `revoke_endorsement` now share the `attestation_cast:` 10/60 bucket with `/me/attestations` (can't dodge the limit by switching endpoints).

Remaining HIGH still open: [H-1] blog-draft lifecycle, [H-3] web-push SSRF, [H-4] OAuth bridge SPOF.

---

## 1. Executive summary

The BCC backend is a **genuinely-implemented, coherently-architected system** — not a scaffold. Every one of ~200 REST routes executes to a real service and repository; 44 of 48 tables are fully wired with readers and writers; 13 of 15 core features trace cleanly from entry point to persistence; the cross-plugin DI, JWT+2FA auth, wallet-signature login, reputation transactions, and multi-chain indexer are all real. R4 logged **219 distinct surfaces checked and found safe** (SQLi, JWT decode, most authz gates).

The problems are **specific and fixable**, concentrated in four confirmed HIGH issues and a band of hardening-grade mediums:

- **Blog draft lifecycle is broken** — drafts can never be edited or published (`_bcc_activity_module` marker only written on publish).
- **Password reset/change does not revoke JWTs** — a stolen 7-day token survives the standard recovery action and refreshes indefinitely.
- **Authenticated SSRF** via the web-push subscription URL (attacker-controlled, no host allowlist).
- **OAuth SSO bridge is a single-shared-secret account-takeover SPOF** — mints a session for any email if the secret leaks.

R5 **cleared** several scary-sounding items: the 17 orphan tables are a **dev-DB-only artifact** (a shipped `init`-hook migration drops them on prod/fresh installs → downgraded to low); the "ungated Locals join" was wrong (it *does* gate on open-privacy); the inert-mode "lockout" is intentional fail-closed design.

---

## 2. Production-readiness scores

Scores are computed from the classified inventory + confirmed-only findings; formulas shown.

| Category | Score | Basis |
|---|---|---|
| **Architecture** | **8.5** / 10 | Clean DDD bounded contexts, 12-contract frozen DI, PHPStan L8, arch-guardrails; minus the never-completed M1.4 `app/Infrastructure/` collapse |
| **Endpoints (API)** | **9.0** / 10 | 194/200 Fully + 6 Mostly; envelope-consistent; only the blog-draft cluster breaks a documented flow |
| **Data layer** | **8.5** / 10 | 44/48 tables Fully wired; repository pattern, bounded queries, generation-counter caching |
| **Security** | **4.2** / 10 | `10 − (3 high×1.2 + 6 med×0.35 + 3 low×0.05)`; 3 confirmed HIGH (auth-revocation, web-push SSRF, OAuth SPOF); **baseline sound** (219 clean, no SQLi/RCE) |
| **Reliability** | **6.0** / 10 | AdvisoryLock discipline + fail-closed throttle + self-heal crons, but no-retry async, silent swallows, pseudo-cron |
| **Scalability** | **7.0** / 10 | Read-model projection, batch HTTP, bounded queries; validator table 3k rows fine |
| **Performance** | **8.0** / 10 | Generation-counter cache, N+1 fixes, read-model, batched same-host HTTP |
| **Data integrity** | **6.0** / 10 | Core transaction discipline strong, but non-atomic suspend, onchain raw-txn depth risk, one stale denorm |
| **Observability** | **7.5** / 10 | DegradationMetrics registry, `/system/health`, Logger; minus a few silent swallows |
| **Maintainability** | **7.5** / 10 | Consistent typed code, guardrail scripts; minus residue |
| **Testing** | **6.0** / 10 | 372 tests green (trust 290 / core 53 / search 29) but thin vs ~170k LOC |
| **Documentation** | **7.0** / 10 | Strong contract + guards; drift (stale "DORMANT" label, schema-doc lists 64 tables incl. dead ones) |
| **Overall readiness** | **6.0** / 10 | **Beta-ready, not prod-ready.** No critical (no RCE/SQLi/unauthenticated-bypass), but 4 confirmed HIGH gate launch. Min-dominated: any confirmed HIGH caps overall ≤6 until fixed |

---

## 3. Verified-working systems (proven by execution tracing)

- **REST layer** — all 200 route-variants Fully/Mostly Implemented (R2, 0 errors).
- **Cross-plugin DI** — 12 `bcc.resolve.*` contracts, all bound to real classes, prewarmed + frozen.
- **Auth** — HS256 JWT (`wp_salt('auth')`, `alg` pinned, `hash_equals`, `tv` revocation), **mandatory email 2FA** on password login, wallet-signature login with **atomic single-use nonce** (`SELECT…FOR UPDATE`), real secp256k1 ecrecover. R4 clean list confirms no alg-confusion / SQLi / decode bypass.
- **Dispute lifecycle** — Fully Implemented (conf 0.97), open→panel→participation→resolution, live table writes — **definitively refuting the historical "missing writer" misdiagnosis**.
- **Trust attestation, moderation, feed+cold-start, search (FULLTEXT+LKG+breaker), watching, notifications/push/digest, onboarding, holder-group→PeepSo gating** — all Fully Implemented with evidence.
- **Multi-chain indexer** — EVM transfer-walk + Solana Helius-push + Cosmos read-time, all wired.
- **48 tables** — 44 Fully Implemented with readers+writers; heavy use confirmed live (`score_events` 7737, `validators` 3000, `read_model` 2870).

---

## 4. Confirmed defects (survived adversarial verification)

### HIGH (4)

- **[H-1] Blog draft lifecycle broken** — a blog post created as `status=draft` is orphaned: the `_bcc_activity_module='blog'` marker is written **only on publish** (by `ActivityStreamWriter`), but `getBlogForEdit`/`updateBlog` gate reads on that marker — so a never-published draft cannot be read-for-edit or published, and the documented "drafts included" edit path is dead. *(R2-B4; CONFIRMED)* → write the marker at draft-create time.
- **[H-2] Password reset/change does not revoke JWTs** — only `/auth/logout-everywhere` bumps `bcc_token_version` (`SessionController.php:111`, sole caller). `reset_password` (`PasswordAuthController.php:485`) and `wp_set_password` (`MyAccountEndpoint.php:223`) don't, and `/auth/refresh` accepts tokens up to `REFRESH_GRACE_SECONDS` past `exp` — so a stolen 7-day token survives the victim's password reset and can be renewed indefinitely. *(R1-A3-1 + R4-S1; CONFIRMED)* → bump `bcc_token_version` in both handlers.
- **[H-3] Authenticated SSRF via web-push subscription URL** — the push endpoint URL is fully attacker-controlled and validated only with `FILTER_VALIDATE_URL` (no scheme/host allowlist), then fetched. *(R4-S4; CONFIRMED)* → route through `SafeHttpClient` or allowlist push-service hosts.
- **[H-4] OAuth SSO bridge = single-secret account-takeover SPOF** — `/auth/oauth` mints a full session JWT from body-supplied `provider`/`email` with no external-token verification; the *only* gate is a static `BCC_OAUTH_BRIDGE_SECRET` (fail-closed, constant-time — correctly built). If that secret leaks, it is pre-auth takeover of **every** account by email, bypassing password + 2FA. *(R1-A3-2 + R4-S1; CONFIRMED)* → add request signing/nonce/timestamp binding; treat the secret as tier-0.

### MEDIUM (confirmed — 15 distinct)

| Issue | Evidence / detail | Fix |
|---|---|---|
| Onchain txns no depth-guard | 7 onchain repos + `ChallengeRepository` issue raw `START TRANSACTION` (unlike Disputes' checked helper); under DB failover the nonce `FOR UPDATE` degrades to a plain read → **nonce-replay window** | route through a checked `beginTx` helper |
| Group rejoin by banned user | **Banned/pending user can rejoin** an open/trust-passing group via `POST /me/groups/{id}/join` — no suspension check | gate join on not-suspended |
| Rank writer missing | `bcc_user_ranks` has **no writer** — `UserRankRepository::award/revoke` never called; read-only scaffold (0 rows) | wire the writer or cut the feature |
| Helius never ran e2e | Webhook feature fully coded but **never executed end-to-end** (0 rows — subscription registration gap) | verify subscription provisioning on live keys |
| Indexer no backfill | V2 EVM walker forward-only; first run anchors checkpoint at head−12 so **pre-existing holdings never backfilled** | one-shot backfill pass |
| X refresh_token discarded | X OAuth requests `offline.access` but `connect()` **discards the refresh_token** → reconnection breaks | persist + use refresh_token |
| Creator-gallery dead branch | `supports_feature('nft')` gate matches no fetcher → refresh silently no-ops | fix key to `'collection'` |
| Rate-limit IP-only | auth throttles **IP-subnet-keyed only**, no per-account lockout → distributed brute-force | add per-account bucket |
| `/endorse` unthrottled | legacy `POST /bcc-trust/v1/endorse` casts attestations with **no throttle** (sibling has one) | add `Throttle::allow` |
| Upload decode DoS | avatar/cover validate MIME+size but **not pixel dimensions** before GD/Imagick | bound dimensions pre-decode |
| Suspend non-atomic | `ModerationService::suspendUser` writes `suspensions` + `user_info.is_suspended` as **two non-atomic statements** | wrap in a transaction |
| Async subscribe loss | Solana Helius-subscribe async job has **no retry / no reconcile cron** | add retry + reconcile |
| Schema hash gap | `BCC_TRUST_SCHEMA_VERSION` hashes only `schema-*.php`, **misses 2 self-installer repos** | include self-installers in hash |
| Moderation silent swallow | bulk suspend/unsuspend **swallows per-user exceptions**, increments `$processed` on failure → false success | log + don't count failures |
| CU accounting silent | `ChainCheckpointRepository::addCuUsage` **returns 0 on DB error, no log** | log the rollback |

*(Plus contract drift on `POST /auth/wallet-signup` + wallet-link responses vs §4.)*

### DOWNGRADED by R5 (real but low)

- **17 orphan physical tables → LOW** — `drop-legacy-orphans.php` (`add_action('init',…,26)`) auto-drops them on production/fresh DBs; they persist only in this **dev DB**. Dev-hygiene, not a prod risk.
- **Helius `patchAddresses` SSRF → LOW** — target host hardcoded (`HELIUS_API_BASE`) + `rawurlencode`; not attacker-influenceable.
- **WP pseudo-cron → LOW** — `DISABLE_WP_CRON` is a documented local-dev toggle, re-enabled in prod.
- **Self-page tier denorm stale cache → LOW** — narrow, self-corrects on next recalc.

### REFUTED by R5 (removed — were wrong)

- ~~LocalsService::joinLocal ungated~~ — it **does** gate on `PeepSoPrivacy::Open` via `GroupContextResolver::forGroup`.
- ~~Inert-mode NullService lockout~~ — intentional, documented **fail-closed** design with admin bypass.
- ~~bcc-trust `DTOAssert`/`RowAssert` dead~~ — **load-bearing** (same-namespace resolution, referenced by X-integration DTOs).
- ~~`bcc_trust_user_verified` / `bcc_trust_quest_revoked` orphan emitters~~ — intentional extension points / command-event separation.

---

## 5. Dead architecture & residue (Phase 5A) — cleanup candidates

R3 catalogued 74 items; after R5 the **safe-to-delete** set (fresh-install policy = delete, no shims):

- **17 orphan tables** (drop migration already exists for prod; drop from dev too).
- **Dead code:** `includes/block-helpers.php` (FSE render helpers), several `VoteService` methods (`getPageVoteStats`, `getUserVotes`, `getUserVoteSummary`, `getSuspiciousVotesForPage`), `AttestationService::{getViewerCanDisputeProfile,viewerIsTrustedPlus}`, `AsyncDispatcher::registerRecurring()`, `bcc_onchain_refresh_page` handler (never scheduled), `bcc.resolve.table_name` stale docblock.
- **Orphan event emitters** (no listeners, not extension points): `bcc_stoke_added/removed`, `bcc_reaction_removed`, `bcc_comment_deleted`, `bcc_content_auto_hidden`, `bcc.domain.dispute_resolved`, `bcc_dispute_status_changed`, `bcc_user_blocked/unblocked` — delete or wire.
- **Incomplete migration:** M1.4 `app/Infrastructure/` collapse was **never done** (still `.gitkeep`).
- **Retain (intentional):** the 12 resolver filters, `bcc-trust/v1` legacy namespace, dormant search controllers, ~50 tuning-knob config filters, migration scripts, `bcc_trust_repair_tab_extra_tools`.

---

## 6. Roadmap

**Before production (High):** fix blog-draft marker [H-1]; revoke JWTs on password reset/change [H-2]; allowlist/route the web-push URL [H-3]; harden the OAuth bridge (signing/nonce) + treat secret as tier-0 [H-4]; add the suspension check to group-join.

**Short-term (high-value Mediums):** onchain transaction depth-guard; per-account rate-limit; throttle legacy `/endorse`; image-dimension bound; atomic suspend; async retry/reconcile for Helius; wire or cut `bcc_user_ranks`; persist X refresh_token; fix creator-gallery `nft`→`collection`.

**Cleanup:** delete the §5 residue set; finish or abandon the M1.4 Infrastructure collapse; regenerate `database-schema.md`; drop the stale "DORMANT" search label.

**Long-term:** raise test coverage on score/attestation/dispute write paths; decide pseudo-cron vs system cron for prod; add e2e verification that Helius + indexer backfill actually populate on live keys.

---

## Appendix — audit trail

Findings DB (360, post-R5): `scratchpad/results/findings-final.jsonl` · verdicts: `verdicts.json` · P0 ledgers + MySQL counts: `scratchpad/p0/` · workflow transcripts: R1 `wf_c6c9a7a7`, R2 `wf_16faed03`, R3 `wf_deb62a2f`, R4 `wf_017bf8fb`, R5 `wf_2200801f`. Guards: parity PASS, dead-file-scan 0 whole-file dead, subsystem-count PASS, schema-drift 22 (17 orphan tables — prod-migrated). Test baselines green (372 tests). **github/sentry MCP connectors were unauthenticated this session** — git-history/Sentry cross-referencing not included.
