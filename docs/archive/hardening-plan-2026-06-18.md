# Engineering Hardening Plan — 2026-06-18

**Goal:** Close the gap between *clean code* (which this codebase has) and a
*production-grade engineering system* (which it doesn't yet). The 2026-06-18
audit verified correctness by reading code; nothing currently prevents a future
commit from silently breaking a security- or data-critical invariant. This plan
adds the verification infrastructure that catches that: automated tests on the
load-bearing invariants, CI that actually gates merges, proven observability,
and validated capacity — plus the security cleanup items the audit flagged.

This is a multi-week effort, sequenced so each phase unlocks the next. Effort
estimates are engineer-days for one focused engineer.

---

## Current state (grounded, not assumed)

- **No CI anywhere.** Zero `.github/workflows` in any of the 5 repos (root, bcc-trust, bcc-core, bcc-search, bcc-frontend). Every guard runs manually.
- **PHP tests are vestigial and non-runnable.** PHPUnit is **not** a `require-dev` dependency in bcc-trust (only phpstan + stubs are). `phpunit.xml.dist` still references pre-M1-merge paths (`app/Repositories/DisputeRepository.php`, deleted) and a `tests/bootstrap.php` that doesn't exist. Tests are split across two trees (`tests/` = 2 files, `tests-disputes/` = bootstrap + 2 Unit tests + Stubs). bcc-core has 1 orphan test (`CosmosVerifierTest.php`), no config. bcc-search has none.
- **The established test pattern is good and worth scaling:** pure-unit, no WordPress core, no DB — a minimal bootstrap (`ABSPATH` + plugin constants + Composer autoload) and DB-dependent code exercised via in-namespace function stubs (see `tests-disputes/bootstrap.php`, `ComputeVerdictTest.php`). Fast, hermetic, no WP test scaffold needed for most invariants.
- **Frontend has zero test tooling.** No vitest/jest/playwright/@testing-library in `bcc-frontend/package.json`.
- **Guards that exist but don't gate:** `arch-guardrails.sh`, `phpstan-all.sh`, `intent-guard.sh`, `api-contract-check.sh` (in `bcc-trust/scripts/`); `contract-parity-guard.php`, `subsystem-count-guard.php`, `cadence-pressure-guard.sh`, `dead-file-scan.php`, `wallet-case-preservation-check.php` (in root `scripts/`).
- **Known defect:** `arch-guardrails.sh`'s raw-`$wpdb` check scans `app/Services`/`app/Controllers`/`app/Admin` — **none of which exist**; real code is under `app/Domain/**`. The §1 rule is effectively **unenforced** today (audit P2-3).

---

## Phase 0 — Make testing runnable (prerequisite) · ~1 day

Nothing else in Phase 1 can land until the harness runs. No new test logic here — just plumbing.

**bcc-trust**
- Add `phpunit/phpunit ^11` to `composer require-dev`; `composer update`.
- Rewrite `phpunit.xml.dist`: correct `bootstrap`, drop the stale `<source>` includes (deleted `app/Repositories/*` paths), point `<testsuite>` at the consolidated test dir.
- **Consolidate** `tests/` + `tests-disputes/` into one `tests/` tree with one `tests/bootstrap.php` (merge the two minimal bootstraps; keep the namespace-stub convention). Move `EnvelopeRecognitionTest` + `UserViewServiceFlagsTest` + the two dispute tests under it.
- Add `"test": "phpunit"` to composer `scripts`.
- Confirm the 4 existing tests pass: `composer test`.

**bcc-core** — same: add phpunit dev-dep, a `phpunit.xml.dist`, a bootstrap, fold in `CosmosVerifierTest`, `composer test`.

**bcc-frontend** — add **Vitest** + `@testing-library/react` + `jsdom` as devDeps (lighter/faster than Jest, native ESM/TS). Add `vitest.config.ts` and `"test": "vitest run"` / `"test:watch": "vitest"`. Smoke it with one trivial test.

**Deliverable:** `composer test` (bcc-trust, bcc-core) and `npm test` (bcc-frontend) all run green with the *existing* tests. No coverage gates yet.

---

## Phase 1 — Critical-invariant test suites · ~4–5 days

Not coverage theater. Target the ~15 invariants where a regression = **data leak, data corruption, or auth bypass**. Most are pure-unit (Phase-0 pattern). DB-backed ones get an integration tier.

### 1a. Pure-unit (no WP, no DB) — do first, highest value/effort ratio

| Invariant | Under test | File |
|---|---|---|
| JWT `alg` rejection (no `none`/RS confusion), exp/iat skew, audience allowlist, `tv` revocation | `JwtToken` (bcc-trust) | `tests/Unit/Security/JwtTokenTest.php` |
| EVM sig: EIP-2 low-S rejection, EIP-191 prefix, r/s≠0, address recovery | `EthSignatureVerifier` | `tests/Unit/Onchain/EthSignatureVerifierTest.php` |
| Open-redirect guard table (`//evil`, `/\evil`, `https://…`, `/ok`) | `safeCallbackPath` (frontend) | `bcc-frontend/src/lib/auth/safe-callback.test.ts` |
| Error contract: `BccApiError` maps on `err.code`, `humanizeCode` never falls back to `err.message` | `lib/api/client`, `lib/api/errors` (frontend) | `…/lib/api/errors.test.ts` |
| Envelope recognition (extend existing) | `Envelope::isAlreadyEnveloped` | already in `tests/` — extend |
| IP→subnet /48 normalization (after Phase 4 unifies it) | canonical `normalizeIpToSubnet` | `tests/Unit/Security/SubnetTest.php` |

### 1b. Integration tier (needs a disposable MySQL — use the Local DB or a CI service container)

| Invariant | Under test | Why it needs a DB |
|---|---|---|
| `cleanupResolved` **never** deletes `status = 0`; batched loop terminates; horizon math | `ContentReportRepository` | the guard is in SQL `WHERE` |
| `cleanupOld` deletes only past-horizon rows; cap honored | `ReputationEventRepository`, `ScoreEventRepository` | SQL |
| Vote upsert: concurrent `FOR UPDATE` + UNIQUE produces one row, correct tally | `VoteRepository::executeUpsert` | locking semantics |
| Challenge one-time consumption (replay rejected), authed/anon keyspace disjoint | `ChallengeRepository` | `SELECT … FOR UPDATE` + delete |
| Rate limiter fails **closed** when backend absent; window math | `RateLimiter`/`Throttle` | atomic upsert |

> Integration tests run against a throwaway schema (the installer's `dbDelta`). Seed → exercise → assert → drop. Tag them `@group integration` so the pure-unit suite stays instant and CI can run them in a separate job with a MySQL service container.

### 1c. Frontend component/behavior (Vitest + Testing Library)

- Token-expiry: `bccFetchAsClient` pre-emptive + reactive refresh path (mock NextAuth `update`/`signOut`).
- The two "evidence views" that swallow errors (audit P3) — assert they surface a failure affordance once fixed.

**Deliverable:** ~15 invariant tests green locally; a `@group integration` split; documented "how to add a test" in `tests/README.md`.

---

## Phase 2 — CI that gates + fix the lying guard · ~1.5 days

Guards that don't block are decoration. Wire everything into **GitHub Actions per repo** and make them **required status checks** (branch protection on `main`).

**Fix first (audit P2-3):** point `arch-guardrails.sh` `forbidden_dirs` at `app/Domain/*/{Services,REST,Application,Support,Controllers}` (exclude `Repositories/`, `Infrastructure/`). Without this, CI green-lights a §1 rule that checks nothing.

**Workflows:**
- `bcc-trust/.github/workflows/ci.yml` — matrix: (1) `php -l` all of `app/`, (2) `composer phpstan` (L8), (3) `bash scripts/arch-guardrails.sh bcc-trust`, (4) `bash scripts/intent-guard.sh`, (5) `composer test` (unit), (6) integration job with a `mysql:8` service container.
- `bcc-core/.github/workflows/ci.yml` — php-lint, phpstan, `composer test`.
- root `.github/workflows/ci.yml` — `contract-parity-guard.php`, `subsystem-count-guard.php`, `cadence-pressure-guard.sh`, `dead-file-scan.php`, `wallet-case-preservation-check.php`.
- `bcc-frontend/.github/workflows/ci.yml` — `tsc --noEmit`, `next lint`, `vitest run`, `next build`. (This also closes the "build only validated by Vercel" gap.)

**Branch protection:** require the relevant checks to pass before merge to `main` in each repo. This is what actually changes behavior — it makes the guards load-bearing.

**Deliverable:** a red CI on a deliberately-broken PR (e.g. a raw `$wpdb` in a Service, a deleted `permission_callback`, a contract-doc drift) for each guard class — proof the gate bites.

---

## Phase 3 — Prove observability + validate capacity · ~2–3 days

Models and metrics are hypotheses until exercised.

**3a. Observability proof.** `DegradationMetrics` is wired throughout, but prod alerting is *unproven*. For each subsystem: inject a synthetic failure and confirm it reaches a place a human sees. If the only sink is the wp-admin dashboard (pull, not push), the real deliverable is **wiring one push sink** (email/Slack/webhook) on RED escalation — an alert that never fires is decoration. Document the alert contract in `docs/operator-runbook.md`.

**3b. Load validation.** You have `docs/capacity-model.md` but no evidence it was measured. Write a **k6** (or Locust) script hitting the three hot paths the audit flagged — `GET /bcc/v1/cards`, `/feed`, `/users` — at the model's target RPS, **with and without Redis** (to exercise the `WP_REDIS_TIMEOUT=1` trap). Capture p50/p95/p99 + DB query counts (Query Monitor / `SAVEQUERIES`). Compare to the model; update `capacity-model.md` with *measured* numbers and flag any endpoint that misses.

**Deliverable:** one fired synthetic alert per subsystem (or a new push sink), and a measured-vs-modeled capacity table.

---

## Phase 4 — Security "should-fix-soon" cleanup · ~1.5 days

Each lands with Phase-1 test coverage and Phase-2 CI.

- **Unify `normalizeIpToSubnet()` on /48.** The two copies diverge — `bcc-core/Throttle.php` masks IPv6 to /64 (the exact weakness `bcc-trust/RateLimiter.php`'s /48 was written to fix). Extract one canonical normalizer in bcc-core; both call it; add the SubnetTest from 1a. (Audit P1.)
- **Route `HeliusSubscriptionManager` through `SafeHttpClient`** — the one on-chain caller outside the SSRF chokepoint (latent if its base URL ever becomes config-derived). (Audit P2.)
- **Decide the orphaned endpoints** (`/ranks`, `/endorsements/mine[/stats]`, `/nft-selections/refresh`, `/wallets/project`, `/chains`) — **delete per the fresh-install "no unused code" policy** unless a UI is imminently planned. Run `contract-parity-guard.php` after to confirm clean. (Audit P2/P3.)
- **Introduce `DisputeReadInterface`** to cut the bidirectional Core↔Disputes static coupling — *optional, higher-risk* (the delete-cascade calls are GDPR-load-bearing; the Null fallback must fail-loud). Defer unless the coupling actually bites. (Audit P1.)

---

## Sequencing & critical path

```
Phase 0 (harness) ──► Phase 1 (tests) ──► Phase 2 (CI gates)
                                  │
                                  └──► Phase 4 (security cleanup, each test-covered)
Phase 3 (observability + load) ── runs in parallel after Phase 2 exists
```

**Total:** ~10–13 engineer-days. The first two phases (~5–6 days) deliver the bulk of the value: a runnable test harness, the critical-invariant suite, and CI that gates. **Start with Phase 0** — it's a hard prerequisite and small.

## What this explicitly is NOT
- Not a coverage-percentage chase. Target invariants, not lines.
- Not a WordPress integration-test scaffold for everything — pure-unit covers most; only the 5 DB-backed invariants get an integration tier.
- Not new features or architectural refactors (the code is already strong). The `Plugin.php` god-object split and other post-MVP debt stay parked.

## Verification of the plan itself
Each phase has a falsifiable deliverable: green `composer test`/`npm test` (0); ~15 invariant tests + a deliberately-broken PR going red per guard (1, 2); one fired synthetic alert + a measured capacity table (3); `contract-parity-guard` clean after endpoint deletion + the SubnetTest passing on the unified normalizer (4).
