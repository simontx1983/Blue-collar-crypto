> **ARCHIVED 2026-07-19** — dated security/concurrency pass record (2026-07-09); the 3 confirmed fixes shipped (bcc-trust #54/#55, bcc-search #4) and the deferred observability items are mirrored in [TODO.md](../TODO.md) ▸ Observability. Retained for the A1–A9 verdict/refutation table and the live exploit proof (group 2043). Not current guidance.

# Backend Security + Bug-Hunt Pass — 2026-07-09

**Scope.** Slim, targeted security + concurrency/data-integrity review of the 3
backend plugins (`bcc-trust`, `bcc-core`, `bcc-search`) — not a full
implementation audit. Method: 3 parallel read-only surface-map agents
(auth/IDOR · concurrency/data-integrity · SSRF/PeepSo-gate/secrets), then one
adversarial **refuter** per finding cluster (refute-first, per the dispute-writer
misdiagnosis lesson — exhaust resolver filters / locator wiring / cron writers /
legacy namespace before confirming), corroborated with read-only MySQL + the
code. Fix scope (Phillip's call): **confirmed security/data-integrity only**;
observability polish deferred to backlog.

**This is a snapshot.** Not committed by default — working-tree record for review.

---

## Verdicts

| ID | Finding | Verdict | Disposition |
|----|---------|---------|-------------|
| **A1** | `POST /me/locals/{id}/membership` → `LocalsService::joinLocal` calls `PeepSoGroupWriter::join` with no server-side gate. The writer writes `gm_user_status='member'` unconditionally, so any authed user could join **any** closed / secret / NFT-gated holder group by posting its id — bypassing `NftGroupGateService` and the trust-tier gate. | **CONFIRMED** — live proof: group **2043** (`_bcc_group_kind=holders`, `peepso_group_privacy=1`, closed NFT-gated). §9 + `peepso-write-guard` violation the 2026-05-13 audit missed (it checked `MyGroupsEndpoint`, not this door). | **FIXED** — bcc-trust PR #54 |
| **A3** | `LocalsEndpoint::join` has no rate limit (contrast `MyGroupsEndpoint:143`). | **CONFIRMED** | **FIXED** — folded into PR #54 |
| **A5** | Anonymous `GET /bcc/v1/search/users` ran a raw `wp_users` LIKE filtered only by `user_status=0`, bypassing the PeepSo/BCC privacy filter set — hidden/discovery-opted-out, banned/suspended, and blocked-viewer users all enumerable by prefix iteration. | **CONFIRMED** (3 of 4 leaks; `user_login`/email boundary safe + test-pinned since 2026-07-06). | **FIXED** — bcc-search PR #4 |
| **A2′** | `WalletLinkWriteService::linkWallet` calls `verify()` only on the `inserted` branch; a crash after `insertOrFind` but before `verify()` leaves the row `verified_at=NULL` forever (retry hits ODKU → `inserted=false` → verify skipped). | **DOWNGRADED** (fail-safe: verified reads filter `verified_at IS NOT NULL`, so it under-counts, never over-counts) — real self-heal defect. | **FIXED** — bcc-trust PR #55 |
| A2 | Wallet-link **double-primary TOCTOU** (originally suspected). | **REFUTED** — `setPrimary` is an atomic self-correcting `CASE` UPDATE inside a `FOR UPDATE` transaction (`WalletRepository.php:124-156`). Double-primary is structurally impossible; the count-gate can't fire twice. | No change |
| A4 | 7 delete/mutate-by-row-id endpoints (reviews, watching, push-subs, blocks, comments, locals, profile fields). | **7/7 SAFE** — every mutation pins to `get_current_user_id()`; path `{id}` names the target object, not the row owner. | No change (2 cosmetic 403-vs-404 existence oracles noted only) |
| A6 | Group detail / feed / members visibility for closed/secret/NFT groups. | **fully SAFE** — all 6 combinations gate correctly (secret→404, closed/NFT→metadata-only slice by design; rosters/members-only content withheld). | No change |
| A7 | Config-SSRF via `chain.rpc_url`/`rest_url`. | **DOWNGRADED (accepted)** — only the schema seeder + hand-edited DB write these (`manage_options`/filesystem); containment holds (SafeHttpClient blocks private/loopback/metadata even for operator hosts). | No change |
| A8 | `NftEnrichmentService` server-fetching raw on-chain `token_uri`/`ipfs://`. | **REFUTED** — it consumes provider-resolved gateway URLs as *data*; no server-side fetch of on-chain URIs. | No change |
| A9 | Giphy key in REST body · Helius PUT via generic `ApiRetry::request`. | **CONFIRMED (accepted)** — Giphy is a client-safe browser-SDK key; Helius host is operator-pinned (`HELIUS_API_BASE`), no user/on-chain input → no SSRF. | Document only |

**Good-news baseline (recorded):** no unprepared/interpolated SQL anywhere in BCC
code; no `UPDATE…JOIN…LIMIT` no-op antipattern; SafeHttpClient centralization
holds (3 outbound bypasses, all operator-pinned); every webhook/token gate
(Helius, OAuth bridge, GitHub/X state+nonce, digest unsubscribe, indexer tick)
verifies **before** side effects.

---

## Shipped (all PRs green: php -l · PHPStan L8 · arch-guardrails · full suites)

- **bcc-trust #54** — `fix(locals)`: `joinLocal` resolves `GroupContextResolver::forGroup`,
  rejects non-`Local` (→ `bcc_not_found`) and non-`Open` privacy (→ `bcc_forbidden`)
  before the writer; `local_join` throttle; new `LocalsJoinGateTest` (5 cases).
  Suite 295/295.
- **bcc-search #4** — `fix(search)`: `UserSearchRepository` routes through
  `PeepSoUserSearch` (reuses the `/users/mention-search` privacy wrapper), fails
  closed without PeepSo, threads viewer id (block filter) + folds it into the
  cache key; new `UserSearchRepositoryPrivacyTest`. Suite 32/32.
- **bcc-trust #55** — `fix(wallet)`: idempotent `verify()` (`verified_at IS NULL`
  guard) called on both link paths so a post-crash retry heals. Suite 290/290.

## Remaining verification (post-merge)

- **A1 live HTTP repro** (recommended before/after merge): `POST /me/locals/2043/membership`
  with a real JWT → expect 403/404 + assert no `peepso_group_members` row appears
  (MySQL). PeepSo doesn't load under wp-cli, so use the HTTP path
  (`wp_schedule_single_event` + curl `wp-cron.php`).

---

## Deferred to backlog — observability (real, lower severity; NOT fixed this pass)

Per the fix-scope decision. All swallow errors without a `DegradationMetric`:

- `ChainCheckpointRepository::addCuUsage:358` — `catch { ROLLBACK; return 0 }` silently reports "0 CU used" → can corrupt the daily-budget circuit breaker.
- `VoteJobDispatcher::handlePostVote:67-79` — post-vote sub-tasks (fraud, trust-graph, stats) log-only on failure, no retry, no metric.
- `VoteJobDispatcher:180` — `as_enqueue_async_action` return value discarded (soft AS enqueue failure loses all post-vote async work silently; the wp-cron fallback path *does* record `cron_dispatch`).
- `DisputeResolver` participation backfill `:182-192` — panelist accuracy marks dropped log-only.
- `FeedRankingService.php:152` — hot-feed cache key folds only the moderation generation, not score/vote generation → ranking can serve stale order for TTL 60–300s. **Design decision**, not a bug — confirm the contract or add a generation fold.
- `VoteService.php:1285-1293` — `recalculateScore` proceeds without the lock on >10s timeout, reopening the new-category INSERT race under sustained contention. Deliberate degradation — review.

Also cosmetic (optional): normalize the 403-vs-404/ok existence oracles on
`DELETE /me/push-subscriptions/{id}` and `DELETE /posts/{feed_id}/comments/{comment_id}`.
