> **ARCHIVED 2026-07-19** — this is the **pre-cleanup** documentation audit (inventory, classifications, and proposed actions as of 2026-07-19, before any change was executed). The cleanup's final result — what was actually updated, relocated, and deleted, with validation output — is documented separately in [docs-cleanup-final-report-2026-07-19.md](docs-cleanup-final-report-2026-07-19.md). Line numbers cited here reference pre-cleanup file states.

# Documentation Audit Report — Blue Collar Crypto Workspace

**Date:** 2026-07-19 · **Auditor:** Claude (read-only pass) · **Status:** AWAITING APPROVAL — no documentation has been edited, moved, or deleted. This file is the only artifact created by the audit.

---

## 1. Executive summary

The documentation set is in better shape than a three-month feature sprint usually leaves it: the evergreen core (API contract, glossary, GOLDEN_PATHS, pattern registry, domain seams, trust-attestation pair) is accurate and guard-enforced — both CI documentation guards (`contract-parity-guard.php`, `subsystem-count-guard.php`) **PASS** as of this audit. There is no doc that falsely claims the platform is in production; staging-vs-production framing is consistently correct.

The real problems cluster in five places:

1. **One ghost plugin haunts eight live files.** `blue-collar-crypto-peepso-integration` no longer exists (its surface moved to `bcc-core/src/PeepSo/`), but 8 live docs/instruction files still reference it as active — including a cron-registry entry pointing at a nonexistent file and prerequisite lists in dev-setup, GOLDEN_PATHS, and the smoke checklist.
2. **The deploy/rollback docs lag the 2026-07-15 pipeline change.** `rollback-procedure.md` still describes git-pull-on-server deploys; `ci-topology.md` predates `deploy.yml` entirely; `deploy-runbook.md` omits the `stage/` docroot split.
3. **Six completed point-in-time documents sit outside the archive**, three of them (`build-plan.md`, the two bcc-frontend briefs) actively misleading — the post-URL brief records the **opposite** of the decision that shipped.
4. **Two `.claude/skills` files are inert and broken** (`code-cleanup.md`, `ux-review.md`): wrong location so they never load as skills, every relative link 404s, and `ux-review` uses retired Endorse/binder vocabulary — yet both contain genuinely unique, valuable review procedure worth converting, not deleting.
5. **`capacity-model.md` still asserts Redis-era analytics as current truth** in unlabeled sections (§6 entirely, header line 5, the §9 formula), contradicting its own 2026-07-16 reconciliation note that staging runs LSMCD with no Redis.

**Proposed outcome: 63 documents reviewed → KEEP 31 · UPDATE 25 · HISTORICAL (relocate) 6 · DELETE 1 · MERGE 0 · UNCERTAIN 0.** The single deletion is `.claude/agents/holder-groups-reviewer.md`, which instructs its own retirement once Holder Groups ships — verified fully shipped in backend, cron, frontend, and contract §4.7.1.

---

## 2. Scope and counting methodology

### 2.1 Reconciliation: "~65" estimate → 63 final

The planning-phase estimate of ~65 informally mixed classified documents with configuration surfaces. The final count of **63 classified documents** is exact:

| Bucket | Count |
|---|---|
| Umbrella repo tracked `.md`/`.txt` (docs/ 36 + docs/archive/ 8 + docs/prompts/ 1 — wait, see row detail below; plus `.claude/` 14 + root CLAUDE.md + root README + bcc-global-library/README) | 53 |
| `docs/performance-review-2026-07-19.md` (tracked on un-merged branch `docs/staging-readiness-2026-07-19`, not on main — see §3) | 1 |
| bcc-search: README.md | 1 |
| bcc-trust: MIGRATED-FROM.md, scripts/golden/manifest.txt, CLAUDE.md (local-only) | 3 |
| bcc-frontend: README.md, docs/frontend-doctrine.md, docs/comment-features-brief.md, docs/post-url-shortcode-brief.md, CLAUDE.md (local-only) | 5 |
| **Total** | **63** |

### 2.2 Reviewed but excluded from classification totals (and why)

These were audited as **evidence and configuration surfaces**, not classified as documents:

- `.claude/settings.json` (dirty/modified) and `.claude/settings.local.json` — executable harness config. Findings recorded in §15 (configuration gaps).
- `.claude/hooks/*.sh` (6 wired hooks incl. untracked `color-token-check.sh`) — executable; header comments verified against standalone docs (no contradictions found).
- `scripts/*` in the umbrella and `bcc-trust/scripts/*` — guard/probe scripts; headers verified. Two carry self-declared lifecycles (`bcc-query-floor-probe.php` "delete after verification pass"; `wallet-case-preservation-check.php` "throwaway") — script cleanup is out of scope for a docs pass, noted in §15.
- `.github/workflows/*` in all repos (`ci.yml`, `deploy.yml` ×3 plugins, `notify-root.yml` ×4, `staging-cache-probe.yml` on branch) — CI/deploy config used as source of truth.
- `bcc-frontend/vercel.json`, `.mcp.json`, `local-site.json` — machine config.
- `vendor/**` markdown (~37 files in bcc-trust) — third-party, excluded per safety rules.
- `bcc-trust/scripts/golden/*.json` — golden-master test fixtures (the human-readable `manifest.txt` IS classified).
- Local-by-Flywheel `conf/`, `logs/` — hosting machinery.

### 2.3 Method

- Tracked-file enumeration per repo (`git ls-files`), so no dependency/vendor docs leak in.
- Every factual claim class verified against code: REST routes (guard run + direct grep of `register_rest_route`), DB tables (migration/drop shims in `bcc-trust/includes/database/`), cron hooks, environment constants (spot-checked read-sites), CI wiring, caching state, feature-shipped status (contract changelog + code presence).
- Reference sweep for every non-KEEP file across all repos' tracked files, `.claude/`, `scripts/`, `.github/` (map in §12).
- Live DB was **not** needed: every factual question (incl. `bcc_user_ranks`) was settled statically.

---

## 3. Repository and branch inventory (verified at final write, 2026-07-19)

**Five git repositories** (not six — `bcc-global-library/` has no own `.git`; it is tracked by the umbrella):

| Repo | Path | Branch at final check | Notes |
|---|---|---|---|
| umbrella (`Blue-collar-crypto`) | `blue-collar-crypto-custom/` | **`main`** @ 5c27c14 | Branch `docs/staging-readiness-2026-07-19` exists locally + on origin, **1 commit ahead (e5d25b6), un-merged.** It carries: `docs/performance-review-2026-07-19.md`, `scripts/auth-cache-isolation-probe.sh`, `.github/workflows/staging-cache-probe.yml`, and updates to `TODO.md`, `capacity-model.md`, `testnet-deploy-checklist.md`. Main does **not** have these. |
| bcc-core | `app/public/wp-content/plugins/bcc-core/` | `main` | |
| bcc-search | `app/public/wp-content/plugins/bcc-search/` | `main` | |
| bcc-trust | `app/public/wp-content/plugins/bcc-trust/` | **`fix/reply-notify-comment-author`** | 1 commit ahead of main (7f67172 — reply notifications routed to comment author), clean tree. **Active feature work by another session — do not touch.** |
| bcc-frontend | `bcc-frontend/` | `main` | |

**Branch-drift caution:** during this audit the umbrella worktree switched branches twice (main → staging-readiness → main) — this is the known shared-worktree environment. Consequences for this report:
- Line numbers cited for `capacity-model.md`, `TODO.md`, `testnet-deploy-checklist.md`, and all content claims about `performance-review-2026-07-19.md` reference the **branch tip e5d25b6**, which is the newest version of those docs.
- **Execution of this audit's changes should happen only after `docs/staging-readiness-2026-07-19` merges to main**, otherwise edits to TODO/capacity-model/testnet-checklist will conflict with or regress that branch.

## 4. Dirty-worktree preservation list (read-only — must survive execution untouched)

| Repo | File | State | Why it must be preserved |
|---|---|---|---|
| umbrella | `.claude/settings.json` | modified | Wires the 6 live hooks incl. color-token guard (uncommitted work) |
| umbrella | `.claude/hooks/color-token-check.sh` | untracked | The color-token guard itself — exists only in this worktree (§15) |
| bcc-core | `vendor/composer/*` (5 files) | modified/untracked | Vendor — out of scope by rule |
| bcc-search | `vendor/composer/*` (7 files) | modified/untracked | Vendor — out of scope by rule |
| bcc-frontend | `package.json` | modified | Known local-only TLS tweak — never commit, never revert |
| bcc-trust | (clean tree, non-main branch) | — | Un-merged feature branch — leave checked out as found |

Also preserved by branch (not worktree): everything on `docs/staging-readiness-2026-07-19` (see §3).

---

## 5. Complete documentation inventory (63 files)

Classification codes: **K**=KEEP, **U**=UPDATE, **H**=HISTORICAL (relocate to archive), **D**=DELETE. Last-change dates from `git log -1` per file. Non-KEEP files get full detail in §9–§11.

### Umbrella — root + bcc-global-library (3)

| # | Path | Last change | Purpose | Class |
|---|---|---|---|---|
| 1 | `README.md` | 2026-06-25 | Workspace entry point; five-repo model + doc map | **U** |
| 2 | `CLAUDE.md` | 2026-05-27 | Root agent instructions: §11 scan rule, automation catalog | **U** |
| 3 | `bcc-global-library/README.md` | 2026-05-03 | Placeholder reserving the cross-project library location (§11 search target) | K |

### Umbrella — docs/ evergreen reference (16)

| # | Path | Last change | Purpose | Class |
|---|---|---|---|---|
| 4 | `docs/api-contract-v1.md` | 2026-07-17 | THE WP↔Next.js REST contract, v1.44, 7251 lines; parity-guard-enforced | K |
| 5 | `docs/glossary.md` | 2026-07-09 | Code-truth dictionary; canonical record of retirements (Foreman, stargaze, endorse-write) | K |
| 6 | `docs/pattern-registry.md` | 2026-07-10 | Canonical-locations registry for §11; subsystem-guard-parsed | **U** |
| 7 | `docs/GOLDEN_PATHS.md` | 2026-07-10 | Operational verification runbook, 1070 lines; subsystem-guard-parsed | **U** |
| 8 | `docs/domain-seams.md` | 2026-06-25 | Domain ownership + 11(+1) Contracts seams — verified matches `app/Domain/*` + `bcc-core/src/Contracts/` | K |
| 9 | `docs/database-schema.md` | 2026-07-09 | 48-table schema inventory; schema-drift-guard target | **U** |
| 10 | `docs/environment.md` | 2026-06-25 | Every wp-config constant with read-site file:line | **U** |
| 11 | `docs/cron-registry.md` | 2026-07-07 | Greppable `bcc_*` cron hook inventory | **U** |
| 12 | `docs/dev-setup.md` | 2026-06-25 | Onboarding: clone→run, five-repo model, doc-map hub | **U** |
| 13 | `docs/ci-topology.md` | 2026-06-25 | What CI runs where across 5 repos | **U** |
| 14 | `docs/cadence-pressure-policy.md` | 2026-05-16 | No-nudge copy policy; guard-enforced | **U** |
| 15 | `docs/prompts/duplicate-scan.md` | 2026-05-03 | §11 scan-report shape contract (skill/agent/hook all depend on it) | K |
| 16 | `docs/operator-runbook.md` | 2026-07-07 | Incident triage table — spot-checked accurate, zero retired-vocab leaks | K |
| 17 | `docs/deploy-runbook.md` | 2026-07-15 | Commit-driven deploy pipeline (staging auto / prod manual) | **U** |
| 18 | `docs/rollback-procedure.md` | 2026-06-25 | Rollback/recovery — mechanism now stale (predates rsync pipeline) | **U** |
| 19 | `docs/trust-engine-coverage.md` | 2026-06-28 | Backend-verb ↔ frontend-exposure map — spot-checked accurate | K |

### Umbrella — docs/ checklists, capacity, trust architecture (9)

| # | Path | Last change | Purpose | Class |
|---|---|---|---|---|
| 20 | `docs/testnet-deploy-checklist.md` | 2026-07-19 (branch) | Provision-a-fresh-env checklist; §1.6 = current caching truth | **U** |
| 21 | `docs/testnet-deploy-worksheet.md` | 2026-06-18 | Fill-in secrets companion — names match environment.md 1:1 | K |
| 22 | `docs/v1-smoke-test-checklist.md` | 2026-07-02 | Manual E2E ship-readiness walkthrough — runnable with one stale prereq | **U** |
| 23 | `docs/capacity-model.md` | 2026-07-19 (branch) | Capacity model + measured staging campaign, 813+ lines | **U** |
| 24 | `docs/performance-review-2026-07-19.md` | 2026-07-19 (branch only) | Independent evidence audit of the perf campaign; pre-launch gates A1–A6 | K |
| 25 | `docs/trust-attestation-layer.md` | 2026-07-09 | Locked product architecture (1446 lines) | K |
| 26 | `docs/trust-attestation-risk-assessment.md` | 2026-06-28 | Locked threat model (1349 lines) | K |
| 27 | `docs/glossary.md` — *(row intentionally not duplicated; see #5)* | | | |
| 27 | `docs/TODO.md` | 2026-07-19 (branch) | Canonical active-work registry | **U** |
| 28 | `docs/v2-roadmap.md` | 2026-07-02 | Demand-gated V2 wishlist (distinct from TODO by design) | **U** |

### Umbrella — docs/ point-in-time reports outside archive (4)

| # | Path | Last change | Purpose | Class |
|---|---|---|---|---|
| 29 | `docs/build-plan.md` | 2026-06-28 | Path-to-launch status map, dated 2026-06-28 — fully superseded | **H** |
| 30 | `docs/backend-implementation-audit-2026-07-08.md` | 2026-07-09 | 10-phase implementation audit + remediation log | **H** |
| 31 | `docs/backend-bug-hunt-2026-07-09.md` | 2026-07-09 | Round-1/2 bug hunt addendum; all items closed | **H** |
| 32 | `docs/backend-security-pass-2026-07-09.md` | 2026-07-10 | Security/concurrency pass; fixes shipped, open items mirrored in TODO | **H** |

### Umbrella — docs/archive/ (8)

| # | Path | Last change | Purpose | Class |
|---|---|---|---|---|
| 33 | `docs/archive/README.md` | 2026-06-28 | Archive convention + index table | **U** |
| 34 | `docs/archive/operational-audit-2026-05-13.md` | 2026-06-25 | Platform-integrity sweep snapshot | K |
| 35 | `docs/archive/stabilization-plan-2026-05-13.md` | 2026-06-25 | Post-MVP structural-debt source (TODO Parked cites it) | K |
| 36 | `docs/archive/route-audit-2026-06-10.md` | 2026-06-25 | Route inventory; **cited by contract-parity-guard.php:77** | K |
| 37 | `docs/archive/hardening-plan-2026-06-18.md` | 2026-06-25 | Engineering-hardening plan snapshot | K |
| 38 | `docs/archive/perf-upgrade-audit-2026-06-18.md` | 2026-06-25 | Pre-measurement code perf audit; **cited by performance-review:67,78** | K |
| 39 | `docs/archive/trust-attestation-phase-1-plan.md` | 2026-06-28 | Phase-1 scope freeze; 4 inbound refs | K |
| 40 | `docs/archive/v2-phase-1-push-notifications.md` | 2026-06-28 | Push spec, shipped | K |

### Umbrella — .claude/ instruction surface (14)

| # | Path | Last change | Purpose | Class |
|---|---|---|---|---|
| 41 | `.claude/AGENTS.md` | 2026-05-10 | Multi-agent dispatch guide | **U** |
| 42 | `.claude/agents/arch-guardrails-reviewer.md` | 2026-05-10 | §1–§9 PHP reviewer | K |
| 43 | `.claude/agents/backend-implementer.md` | 2026-05-10 | PHP implementer | K |
| 44 | `.claude/agents/duplicate-scanner.md` | 2026-05-06 | §11 scan engine | K |
| 45 | `.claude/agents/frontend-implementer.md` | 2026-05-10 | TS implementer | K |
| 46 | `.claude/agents/frontend-reviewer.md` | 2026-05-10 | FE rules reviewer | K |
| 47 | `.claude/agents/holder-groups-reviewer.md` | 2026-05-06 | Feature-scoped reviewer, self-expiring | **D** |
| 48 | `.claude/skills/api-contract-guard/SKILL.md` | 2026-05-06 | §9 contract check skill | K |
| 49 | `.claude/skills/duplicate-scan/SKILL.md` | 2026-05-06 | §11 skill | K |
| 50 | `.claude/skills/frontend-feature/SKILL.md` | 2026-05-06 | FE scaffold skill | K |
| 51 | `.claude/skills/new-repository/SKILL.md` | 2026-05-06 | Repository scaffold skill | K |
| 52 | `.claude/skills/peepso-write-guard/SKILL.md` | 2026-05-07 | PeepSo-writer gate check | **U** |
| 53 | `.claude/skills/code-cleanup.md` | 2026-05-06 | Cleanup review procedure — inert (wrong location), links broken | **U** |
| 54 | `.claude/skills/ux-review.md` | 2026-05-16 | UX review procedure + V1 surface vocabulary — inert, links broken | **U** |

### bcc-search (1) · bcc-trust (3) · bcc-frontend (5)

| # | Path | Last change | Purpose | Class |
|---|---|---|---|---|
| 55 | `bcc-search/README.md` | 2026-05-03 | Plugin README — shortcode section documents removed feature; 1 of 3 routes documented | **U** |
| 56 | `bcc-trust/MIGRATED-FROM.md` | 2026-04-23 | Frozen M1 merge audit trail (hashes, namespace map) | K |
| 57 | `bcc-trust/scripts/golden/manifest.txt` | 2026-06-25 | Pins 5 golden read endpoints — **all 5 verified live** (`/ranks` = RanksEndpoint.php, `/locals` = LocalsEndpoint.php; the v1.22/v1.23 retractions were different endpoints: `/admin/ranks/*` mutations and never-registered locals variants) | K |
| 58 | `bcc-trust/CLAUDE.md` (local-only, untracked by convention) | content ~2026-07-15 | Plugin agent instructions §1–§9 + M1 history | **U** |
| 59 | `bcc-frontend/README.md` | 2026-07-15 | FE structure overview — current | K |
| 60 | `bcc-frontend/docs/frontend-doctrine.md` | 2026-07-12 | FE "why" layer; links (not copies) umbrella docs | K |
| 61 | `bcc-frontend/docs/comment-features-brief.md` | 2026-07-12 | Comment features decision brief — all 3 features shipped | **H** |
| 62 | `bcc-frontend/docs/post-url-shortcode-brief.md` | 2026-07-12 | Post-URL brief — shipped with the **opposite** decision (short_id, not Hashids) | **H** |
| 63 | `bcc-frontend/CLAUDE.md` (local-only, untracked by convention) | content ~2026-07-15 | FE agent instructions: color rule, token catalog (all tokens verified in globals.css) | **U** |

*(Numbering note: row 27 appears once as TODO.md; glossary is row 5. 63 unique files total.)*

---

## 6. Classification totals

| Classification | Count |
|---|---|
| KEEP | **31** |
| UPDATE | **25** |
| HISTORICAL (relocate to archive) | **6** |
| DELETE | **1** |
| MERGE | 0 |
| UNCERTAIN | 0 |
| **Total** | **63** |

No MERGE class was needed: the two candidate consolidations (loose skill files) are conversions-in-place with content trimming, classified UPDATE. No UNCERTAIN remains — the golden-manifest question (the last open one) was settled definitively in code (§5 row 57).

---

## 7. Verified contradictions

Each verified against code/config; all are fixed by the §9–§11 actions.

1. **`database-schema.md` vs code** — lists `wp_bcc_user_ranks` as *Active* ("Awarded ranks per user (Foreman role etc.)", lines 52/307/719) while `bcc-trust/includes/database/drop-user-ranks-table.php` actively **drops** that table on `init` (resurrection-guarded) and `glossary.md` records it as gone (v1.36). Current code does not read, write, create, or migrate it — it deletes it. Physical existence on any box is leftover data, not activity.
2. **`capacity-model.md` vs its own reconciliation** — header L5 asserts "Redis-backed read-models" as actual behavior; §6 (L111–120) presupposes live Redis; L40/L106–107 present "Redis warm" as an operating mode; L150/156 keep Redis + 60MB-worker in the §9 formula. The doc's own 2026-07-16 note: staging runs LSMCD, Redis not offered on the shared plan, workers measured ~100–105MB.
3. **`rollback-procedure.md` vs deploy pipeline** — claims backend deploys by "git pull on the server" and rollback by `git checkout` on-server (L8–10, L27–34, L85–91); since 2026-07-15 deploys are CI rsync (`deploy.yml`), and the deployed tree is an rsync target, not a git checkout.
4. **`ci-topology.md` vs workflows** — per-repo table omits `deploy.yml` (exists in all 3 plugin repos) and the whole staging-auto/prod-manual deploy layer; also predates `staging-cache-probe.yml` (branch).
5. **`deploy-runbook.md` vs deploy.yml** — L44 claims target is exactly `…/wp-content/plugins/<plugin>/`; true only for prod — staging targets `…/stage/wp-content/plugins/<plugin>/` (`deploy.yml:57-61`).
6. **Ghost plugin** — `blue-collar-crypto-peepso-integration` referenced as live in: `docs/cron-registry.md:73` (hook + file path that don't exist), `docs/dev-setup.md:50,68`, `docs/GOLDEN_PATHS.md:20,315`, `docs/v1-smoke-test-checklist.md:37`, `docs/pattern-registry.md:345,923,958`, `.claude/skills/peepso-write-guard/SKILL.md:37`, `.claude/skills/code-cleanup.md:7`, `.claude/agents/holder-groups-reviewer.md:18,39,59`. The plugin dir does not exist; the surface lives in `bcc-core/src/PeepSo/`. (Also named in bcc-trust `arch-guardrails.sh`/`phpstan-all.sh` ALL_PLUGINS arrays and `settings.local.json` `bcc-trust-engine` allow-rule — script/config gaps, §15.)
7. **`build-plan.md` vs TODO.md** — shows "Endorse→Attestation final cleanup (`feat/retire-endorse`)" in progress; TODO records it fully shipped 2026-07-02 and the branch never existed. Every build-plan work item verified DONE or tracked elsewhere (itemized in §10).
8. **`post-url-shortcode-brief.md` vs shipped contract** — brief recommends Hashids with stored short_id as "the documented upgrade"; contract v1.39 changelog (api-contract L6135–6136) records stored 8-letter `short_id` **chosen over** Hashids.
9. **Root `CLAUDE.md` vs `.claude/settings.json` + `ci.yml`** — documents 4 of 6 wired hooks (omits `eslint-frontend.sh`, `color-token-check.sh`) and omits CI-blocking `dead-file-scan.php` (ci.yml:71) from the guard-script list.
10. **`cadence-pressure-policy.md` vs its guard** — policy enumerates 6 forbidden phrases; `cadence-pressure-guard.sh` enforces 8 (adds `in the last N day/week/month`, `remind… to attest/vouch/…`). Also "full register lands in PR-11c" — PR-11c never landed; the stub is the de-facto final policy.
11. **`environment.md` line-refs drifted** — `BCC_OAUTH_BRIDGE_SECRET` documented at `Core/REST/AuthEndpoint.php:1200`, actually read in `Core/REST/Auth/OAuthController.php:159-160`; `BCC_ENCRYPTION_KEY` line numbers (135/1511) no longer land on the reads (actual: bcc-trust.php:150 ff.).
12. **`bcc-search/README.md` vs plugin code** — documents a `[bcc_search]` shortcode; zero `add_shortcode` calls exist (removed 2026-05-03 headless pivot, README's own last commit). REST section documents 1 of 3 registered routes (misses `/search/users`, `/search/groups`).
13. **Cross-doc cron-name drift** — GOLDEN_PATHS §13.1 says `bcc_trust_hourly_safety_recalc`; cron-registry canonicalizes `bcc_trust_hourly_recalc`.
14. **GOLDEN_PATHS §14.4 test path** — cites `tests/EnvelopeRecognitionTest.php`; file lives at `tests/Unit/EnvelopeRecognitionTest.php`.
15. **`bcc-frontend/CLAUDE.md` layout dependency** — advertises `.claude/hooks/color-token-check.sh` as an enforcing auto-block; the hook exists only in the umbrella layout **and is currently untracked** with its `settings.json` wiring uncommitted — the guarantee holds only in this one worktree (§15).
16. **Not a contradiction (verified deliberately):** "endorse" mentions in trust-engine-coverage/glossary/smoke-checklist are correctly framed — the endorse **write** path is retired/aliased to vouch, yet `POST /bcc-trust/v1/endorse` routes remain registered and contract-declared, and `EndorseButton.tsx` still renders in a documented transitional window. Docs that frame it this way are accurate. Only `ux-review.md` uses Endorse as the live primary verb (stale).

---

## 8. Proposed canonical documentation structure

No structural upheaval needed; the existing shape is right. The proposal only enforces its own rules:

- **`docs/` (umbrella)** = evergreen truth only: contract, glossary, registries, runbooks, checklists, capacity model, trust-architecture pair, TODO (active work), v2-roadmap (deferred wishlist).
- **`docs/archive/`** = every dated point-in-time record, per the convention `docs/archive/README.md` already states. The 4 dated umbrella files currently violating this move in (§10).
- **`bcc-frontend/docs/`** = FE doctrine (evergreen) + `bcc-frontend/docs/archive/` (new dir, created at execution) for the two completed briefs.
- **`.claude/`** = instructions only; the two loose files become real `<name>/SKILL.md` skills (§13).
- **Canonical-source rules reaffirmed:** active work → `TODO.md` only; deploy mechanics → `deploy-runbook.md` (rollback-procedure defers to it); caching truth → `testnet-deploy-checklist.md §1.6`; retirements → `glossary.md`.

---

## 9. Exact UPDATE list (25 files)

Grouped; every entry: action · evidence · refs to repair · validation. "After merge" = after `docs/staging-readiness-2026-07-19` lands on main (§3).

### 9.1 Ghost-plugin sweep (repairs contradiction #6; 6 docs here, 2 covered in §9.4/§13)

| File | Exact action | Validation |
|---|---|---|
| `docs/cron-registry.md` | L73: retire the `ShadowPageSyncService::USER_CLEANUP_HOOK` entry — mark hook removed-with-plugin, or repoint if an equivalent exists in `bcc-core/src/PeepSo/` (verify at execution; none found in this audit). Optionally add a cross-ref note that the Vercel minutely `indexer-tick` cron (vercel.json) is outside this registry's WP-hook scope. | grep `blue-collar-crypto-peepso-integration` → 0 hits in file |
| `docs/dev-setup.md` | L50, L68: drop `blue-collar-crypto-peepso-integration` from active-plugin lists; correct `peepso-core` → the actual `peepso` folder set present on disk | same grep; new-engineer read-through |
| `docs/GOLDEN_PATHS.md` | L20 (prereq list), L315 (grep path → `bcc-core/src/PeepSo/`); plus §13.1 `bcc_trust_hourly_safety_recalc` → `bcc_trust_hourly_recalc` (contradiction #13); §14.4 test path → `tests/Unit/…` (#14); remove holder-groups-reviewer invocations at L339, L620 (→ §11 repair map) | `subsystem-count-guard.php` still PASS; greps → 0 |
| `docs/v1-smoke-test-checklist.md` | L37: drop ghost plugin from pre-flight actives | grep → 0 |
| `docs/pattern-registry.md` | L345, 923, 958: repoint PeepSo-writer canonical locations to `bcc-core/src/PeepSo/PeepSoGroupWriter.php` (verified: `join()` at line 60, `leave()`; tested by `PeepSoGroupWriterJoinGuardTest`) | `subsystem-count-guard.php` still PASS |
| `.claude/skills/peepso-write-guard/SKILL.md` | L37: fix grep target path to `bcc-core/src/PeepSo/` (this is an ACTIVE skill — highest-priority ghost ref) | invoke skill; confirm grep finds real call sites |

### 9.2 Deploy/CI truth (contradictions #3, #4, #5)

| File | Exact action | Validation |
|---|---|---|
| `docs/deploy-runbook.md` | L37–44: document the docroot split — staging target `…/stage/wp-content/plugins/<plugin>/`, prod `…/wp-content/plugins/<plugin>/` (evidence: `bcc-core/.github/workflows/deploy.yml:57-61`, same in trust/search) | read-through against deploy.yml |
| `docs/rollback-procedure.md` | Rewrite §1/§4b for the rsync pipeline: backend rollback = re-dispatch `deploy.yml` at last-good SHA (or workflow_dispatch from a pinned ref), not on-server `git checkout`; refresh the L8–10 status header (staging stood up + load-tested; prod frozen 2026-07-19). Keep DB restore + Vercel §4a as-is (still valid). | walk-through vs deploy-runbook; no contradiction between the two |
| `docs/ci-topology.md` | Add the deploy layer: `deploy.yml` per plugin repo (staging auto on CI-green push→main via `workflow_run`; prod manual `workflow_dispatch`), and (after merge) `staging-cache-probe.yml`. Keep the guard table (verified accurate). | cross-check every workflow file listed exists |

### 9.3 Capacity + planning docs (contradictions #2, #7-adjacent, #10)

| File | Exact action | Validation |
|---|---|---|
| `docs/capacity-model.md` (after merge) | Add inline **SUPERSEDED (see 2026-07-16 reconciliation)** labels at: header L5 ("Redis-backed read-models" clause), L40 ("Redis warm" row), L106–107 (Redis-warm totals), §6 entire (L111–120), §9 formula L150 + Redis memory row L156. Measured-staging sections stay the unmistakable current truth. Fix the dangling `GOLDEN_PATHS §5.6` cite at ~L812 (flagged by performance-review L182). | read top-to-bottom: no unlabeled Redis-as-current claim remains |
| `docs/TODO.md` (after merge) | (a) Port from backend-implementation-audit §5 before its relocation (§10): dead `VoteService`/`AttestationService` methods, orphan event emitters, `bcc_onchain_refresh_page` handler, M1.4 `app/Infrastructure/` collapse (note: partially overtaken — `EdgeCache.php` now lives there), "regenerate database-schema.md", "drop stale DORMANT search label", indexer one-shot-backfill medium. (b) Fix L63 stale item (flagged by performance-review L181/L66, post-fe#45). (c) Add a link to `performance-review-2026-07-19.md` (currently zero inbound refs). (d) Repair the two `backend-security-pass` links (L35, L78) → `archive/` path when §10 executes. | all links resolve; no orphaned open item from the three reports |
| `docs/v2-roadmap.md` | Update header "Status as of 2026-05-13" → current date at execution; body verified internally consistent | header matches newest body stamp |
| `docs/cadence-pressure-policy.md` | Sync the phrase table (L23–33) to the guard's 8 patterns (`cadence-pressure-guard.sh:51-68`); convert "coming in PR-11b/PR-11c" (L81–94) into an explicit "stub is final unless reopened" note | `cadence-pressure-guard.sh` exit 0; doc lists all 8 |
| `docs/testnet-deploy-checklist.md` (after merge) | L3: "Last updated 2026-06-12" → actual (content updated through 2026-07-19); §1.5: lead with LSMCD-on-shared, keep Redis as the VPS/Agency path | header vs newest §1.6 stamps |
| `docs/database-schema.md` | Reclassify `wp_bcc_user_ranks` (L52, L307, L719) from Active → **RETIRED/DROPPED** (evidence: `drop-user-ranks-table.php` init-hook drop, glossary v1.36). Match the existing `wp_bcc_trust_flags` RETIRED precedent in the same doc. | `schema-drift-guard.php` run (static declared-vs-documented legs) |
| `docs/environment.md` | L47: `BCC_OAUTH_BRIDGE_SECRET` read-site → `Core/REST/Auth/OAuthController.php:159-160`; L37: `BCC_ENCRYPTION_KEY` line-refs → bcc-trust.php:150 (+646/1849); IndexerTick line 133 → 150-151 | spot-grep each corrected read-site |

### 9.4 Root docs + instruction files

| File | Exact action | Validation |
|---|---|---|
| `README.md` (root) | Correct "tracks … three WordPress plugins and the frontend" → plugins + frontend are separate gitignored repos reassembled in CI | read-through |
| `CLAUDE.md` (root) | (a) Hooks section: add `eslint-frontend.sh` + `color-token-check.sh` (6 total, per settings.json). (b) Guard list: add CI-blocking `dead-file-scan.php`; one summary line for not-load-bearing scripts (`schema-drift-guard.php` unarmed, `wallet-case-preservation-check.php` CI-excluded throwaway, `bcc-query-floor-probe.php` temporary, `auth-cache-isolation-probe.sh` staging probe, `scripts/perf/`). (c) Remove the `holder-groups-reviewer` bullet (L~130) per §11. | every named path exists; hooks list == settings.json |
| `.claude/AGENTS.md` | Remove L20 `holder-groups-reviewer` bullet (only stale item; rest verified accurate) | agent list == `.claude/agents/` contents minus deleted |
| `docs/archive/README.md` | When §10 executes: add index rows for the 4 relocated umbrella files (superseded-by pointers: build-plan → TODO + testnet-deploy-checklist; the three reports → their remediation records in TODO/glossary/contract) | archive convention self-consistent |
| `.claude/skills/code-cleanup.md`, `.claude/skills/ux-review.md` | Convert to valid skills — full spec in §13 | see §13 |

### 9.5 Sub-repo docs (each needs its own branch/PR at execution)

| File | Exact action | Validation |
|---|---|---|
| `bcc-search/README.md` | Delete the Shortcodes section (L9–29, `[bcc_search]` — zero `add_shortcode` in plugin); document all 3 live routes: `GET /bcc/v1/search` (SearchController `ROUTE='/search'`), `GET /bcc/v1/search/users` (UserSearchController, security-hardened 2026-07-09), `GET /bcc/v1/search/groups` (GroupSearchController) | README routes == registered routes; contract-parity-guard unaffected |
| `bcc-trust/CLAUDE.md` **(local-only — scratchpad backup REQUIRED first)** | Collapse M1.0–M1.5 narrative (L38–57) to a two-line pointer at `MIGRATED-FROM.md`; optionally document `scripts/verify-golden.sh` (exists, currently unreferenced). All script refs verified — no other changes needed. | referenced commands still resolve |
| `bcc-frontend/CLAUDE.md` **(local-only — scratchpad backup REQUIRED first)** | L19: reword the color-rule enforcement note — hook lives in the **umbrella** `.claude/hooks/` (absent in standalone checkouts) and is not yet committed (§15); keep the rule itself (token catalog verified 100% present in `globals.css`) | wording matches actual enforcement scope |

---

## 10. Exact HISTORICAL relocation list (6 files)

All six: unique content is retained (relocation, never deletion); each move gets a header banner `> ARCHIVED <date> — point-in-time record; superseded by <canonical>` so nothing masquerades as current guidance. Historical references *inside* archived docs stay untouched unless they read as current instructions.

| # | Current path | Destination | Evidence it is complete | Refs to repair (inbound) | Pre-move obligation |
|---|---|---|---|---|---|
| 1 | `docs/build-plan.md` | `docs/archive/build-plan-2026-06-28.md` | Every work item verified DONE (all in TODO "Recently shipped": trust#21/#31/#32/#37, fe#21, feat/feed-hot-warm, DegradationAlerter) or tracked in testnet-deploy-checklist §7 / v1-smoke (deploy gates, which TODO L7-10 deliberately excludes). Its own footer already names TODO "the source of truth". Versions stale (says 1.2.19; staging 1.2.26+). | **None** — zero inbound references anywhere (verified grep) | none |
| 2 | `docs/backend-implementation-audit-2026-07-08.md` | `docs/archive/` (same name) | All 4 HIGH + mediums remediated (TODO L76, 2026-07-09). Unique retained value: R5 refutation methodology, 219-surface clean-list, readiness scores, dispute-writer-misdiagnosis lesson. | `docs/backend-bug-hunt-2026-07-09.md:3` (co-moving — becomes sibling link) | **Port §5 residue-cleanup set + backfill medium + doc chores into TODO first** (§9.3) — sole location of those open items |
| 3 | `docs/backend-bug-hunt-2026-07-09.md` | `docs/archive/` (same name) | All findings shipped or refuted; only L-B2 intentional no-op. Self-contained. Unique: ERC-1155 fail-safe + Helius refutation reasoning, clean-list. | `docs/backend-implementation-audit-2026-07-08.md:38` (co-moving) | none |
| 4 | `docs/backend-security-pass-2026-07-09.md` | `docs/archive/` (same name) | 3 fixes shipped (trust#54/#55, search#4); 6 deferred observability items mirrored into TODO L37-42; A1 repro tracked TODO L78. Unique: A1–A9 verdict/refutation table, group-2043 live proof. | **`docs/TODO.md:35` and `docs/TODO.md:78`** — both links must repoint to `archive/` | none |
| 5 | `bcc-frontend/docs/comment-features-brief.md` | `bcc-frontend/docs/archive/` (new dir) | All 3 features shipped: attachments v1.41 + media-only v1.44, threading v1.42, sort v1.40. Banner must note: Top-sort shipped as **most-stoked**, not the brief's recommended reply_count signal. | **None** — zero inbound refs in bcc-frontend (verified grep) | none |
| 6 | `bcc-frontend/docs/post-url-shortcode-brief.md` | `bcc-frontend/docs/archive/` (new dir) | Shipped as contract v1.39. Banner must state the **inversion**: stored 8-letter `short_id` shipped, Hashids (the brief's recommendation) rejected — launch-empty backfill argument moot (contract L6135-6136). Highest masquerade risk of the six. | **None** — zero inbound refs (verified grep) | none |

Validation after all moves: repo-wide grep for each old path → only archive-internal/self hits; `docs/archive/README.md` index updated (§9.4); TODO links resolve.

---

## 11. Exact DELETE list (1 file)

### `.claude/agents/holder-groups-reviewer.md`

- **Why no remaining value:** It is a feature-gated review checklist whose own text sets the expiry: *"Retire this agent when the feature ships."* The feature is fully shipped and stabilized — verified: backend (`NftGroupGateService.php`, `NftGroupRevokeService.php`, `HolderGroupsEndpoint.php`, `GatedGroupRepository.php`, `Admin/HolderGroupsPage.php`), reconcile cron (`bcc_gated_group_reconcile_sweep`, twicedaily, `bcc-trust.php:695/845/918`), writer + test (`bcc-core/src/PeepSo/PeepSoGroupWriter.php` + `PeepSoGroupWriterJoinGuardTest`), frontend (17 files incl. `useHolderGroups.ts`, `GroupActionButton.tsx`), contract §4.7.1 (5 endpoints + cron, lines 2797–2896, coverage recap 7137–7165), and TODO lists its observability under "Recently shipped". Its content is additionally stale: targets the ghost plugin path (L18/39/59) and a method name (`member_join`) that isn't the shipped API (`join`).
- **What supersedes it:** The durable footgun protection lives on: `.claude/skills/peepso-write-guard/SKILL.md` (active skill guarding the same PeepSoGroupWriter bypass, post-§9.1 path fix) + `PeepSoGroupWriterJoinGuardTest` + GOLDEN_PATHS §8 gate documentation.
- **References:** `CLAUDE.md:~130` (bullet — removed in §9.4), `.claude/AGENTS.md:20` (removed in §9.4), `docs/GOLDEN_PATHS.md:339,620` (invocation steps — removed in §9.1), `docs/archive/operational-audit-2026-05-13.md:182,188` (**left as-is** — historical record, does not masquerade as instruction). No hits in settings.json or any workflow.
- **Unique information lost:** None that outlives the feature's in-flight phase; the three durable footguns it names are covered by the superseding trio above.
- **Git recovery:** Tracked in the umbrella repo since 2026-05-06 — recoverable via `git log -- .claude/agents/holder-groups-reviewer.md` / checkout at any prior SHA.
- **Validation:** post-delete grep `holder-groups-reviewer` → hits only in `docs/archive/`; session restart confirms the agent no longer loads; remaining agent list matches AGENTS.md.

---

## 12. Complete reference-repair map

Every reference that must change, by cause. (Inside archived docs, references stay unless they masquerade as current instructions.)

| Cause | Reference site | Repair |
|---|---|---|
| DELETE holder-groups-reviewer | `CLAUDE.md:~130` | remove bullet |
| | `.claude/AGENTS.md:20` | remove bullet |
| | `docs/GOLDEN_PATHS.md:339,620` | remove/replace invocation steps (point §8 checks at peepso-write-guard skill) |
| RELOCATE backend-security-pass | `docs/TODO.md:35,78` | repoint to `archive/backend-security-pass-2026-07-09.md` |
| RELOCATE implementation-audit ↔ bug-hunt | each cites the other (L38 / L3) | both co-move to `archive/` — links become sibling-relative; verify resolve |
| RELOCATE build-plan / fe briefs | none (zero inbound) | archive/README index row (build-plan) |
| CONVERT code-cleanup.md → `code-cleanup/SKILL.md` | `.claude/agents/frontend-implementer.md:9` | update path `../skills/code-cleanup.md` → `../skills/code-cleanup/SKILL.md` |
| | `.claude/agents/frontend-reviewer.md:9` | same |
| | `.claude/skills/api-contract-guard/SKILL.md:71` | `../code-cleanup.md` → `../code-cleanup/SKILL.md` |
| | `.claude/skills/frontend-feature/SKILL.md:8,71` | same ×2 |
| CONVERT ux-review.md | none inbound (verified) | — |
| Ghost plugin (8 live files) | listed in §7.6 / §9.1 | per-file fixes |
| NEW inbound link (recommended) | `docs/TODO.md` → `performance-review-2026-07-19.md` | add (currently zero inbound refs to the perf review) |

---

## 13. Claude instruction & skill cleanup (execution-phase spec)

**Conversions (UPDATE class — no unique material may be lost):**

1. `.claude/skills/code-cleanup.md` → `.claude/skills/code-cleanup/SKILL.md` (+ YAML frontmatter matching the 5 working skills). Preserve verbatim: Step 2 (dead-code + duplicate heuristics — unique), Step 3 (scale performance audit — unique), the two unique Step 5 bullets (Logger-not-error_log; WP_Error-not-wp_die), Output-format + Severity scaffold. Trim to cross-references: Steps 0/1 (→ bcc-trust/CLAUDE.md §1–§9 + Commands), Step 4 (→ bcc-frontend/CLAUDE.md + frontend-reviewer). Fix: L7 ghost plugin; all `../` links re-depthed to `../../`.
2. `.claude/skills/ux-review.md` → `.claude/skills/ux-review/SKILL.md`. Preserve verbatim: the **V1 surface vocabulary** (L28–91 — the single highest-value block, exists nowhere else), Steps 2–6, Output/Severity scaffold. Fix: Endorse→vouch at L66/128/130 (`permissions.can_endorse` → `can_vouch`); drop "3-ring binder iconography preserved" clause L75 (keep "watchlist icon"); re-depth links L13/90/98/159/198. Trim Step 1 to a cross-reference.
3. Post-conversion: session restart required for skill registration (known harness behavior); verify both appear in the available-skills list; repair the 4 inbound `code-cleanup.md` links (§12).

**Local-only CLAUDE.md protocol (per approval instruction #10):** before ANY approved edit to `bcc-trust/CLAUDE.md` or `bcc-frontend/CLAUDE.md`, copy the originals to the session scratchpad (they are gitignored — **not git-recoverable**). Proposed edits are specified in §9.5; nothing was touched this phase.

---

## 14. Do Not Delete (operationally critical / historically important)

Future cleanup passes must preserve these regardless of age:

- **Parsed by tooling (breaking if moved/renamed):** `docs/api-contract-v1.md` (contract-parity-guard + 7 agents/skills), `docs/pattern-registry.md` + `docs/GOLDEN_PATHS.md` (subsystem-count-guard), `docs/database-schema.md` (schema-drift-guard), `docs/cadence-pressure-policy.md` (cadence guard scans it as part of SCOPE_PATHS), `docs/prompts/duplicate-scan.md` (§11 skill/agent/hook), `docs/archive/route-audit-2026-06-10.md` (**cited by contract-parity-guard.php:77** — the one archive file with a live tooling reference), `bcc-trust/scripts/golden/manifest.txt` (verify-golden harness).
- **Security / recovery / retention:** `docs/rollback-procedure.md` (recovery procedure — update, never delete; documents the no-automated-DB-backups gap), `docs/backend-security-pass-2026-07-09.md` (security findings + live exploit proof — archive only), `docs/trust-attestation-risk-assessment.md` (locked threat model), `docs/environment.md` (fail-closed secrets posture), `docs/testnet-deploy-worksheet.md` (secret-generation procedure), account-security/caching sections of `testnet-deploy-checklist.md` §1.6 (Authorization-cache isolation — a security control, not a perf nicety).
- **Performance/capacity evidence:** `docs/capacity-model.md`, `docs/performance-review-2026-07-19.md`, `docs/archive/perf-upgrade-audit-2026-06-18.md`, `scripts/perf/results/` (data, not docs, but the perf-review cites it).
- **Decision rationale with no code trace:** `docs/trust-attestation-layer.md` (locked constitution), `docs/glossary.md` (retirement authority), `bcc-trust/MIGRATED-FROM.md` (M1 hashes/namespace map), all `docs/archive/*` (each verified to retain unique rationale), `docs/domain-seams.md`.
- **Agent-instruction load-bearing:** all three CLAUDE.md files, `.claude/AGENTS.md`, the 5 KEEP agents + 5 skills.

---

## 15. Configuration gaps requiring separate implementation work (NOT part of the docs pass)

Found during the audit; each needs its own decision/PR — they change behavior, which this task must not:

1. **Color-token guard is worktree-only.** `.claude/hooks/color-token-check.sh` is untracked; its `settings.json` wiring is modified-uncommitted. `bcc-frontend/CLAUDE.md` advertises it as an enforced auto-block — currently true only on this machine. Fix = commit hook + settings (umbrella repo). The §9.5 doc edit rewords the claim until then; treat the guarantee as **not yet shared**.
2. **`settings.local.json` dead allow-rule** referencing retired `plugins/bcc-trust-engine`.
3. **`bcc-trust/scripts/arch-guardrails.sh` + `phpstan-all.sh`** still carry `blue-collar-crypto-peepso-integration` in ALL_PLUGINS arrays (harmless no-op globs, but the ghost's last executable habitat).
4. **`schema-drift-guard.php` not CI-armed** (header says so; confirmed absent from workflows). Arming is a CI decision.
5. **Self-expiring scripts awaiting their cleanup:** `bcc-query-floor-probe.php` ("delete after the boot-floor verification pass" — that pass completed 2026-06-12), `wallet-case-preservation-check.php` (throwaway, CI-excluded by comment).
6. **`staging-cache-probe.yml` exists only on the un-merged branch** — the weekly auth-cache-isolation guard (performance-review gate A4) is not active until that branch merges.
7. **`cadence-pressure-guard.sh` enforces 2 patterns the policy doc doesn't list** — §9.3 fixes the doc; alternatively the guard could cite the doc as source. Doc-fix chosen; no script change.

---

## 16. Per-repository validation plan (after approved execution)

| Repo | Steps |
|---|---|
| umbrella | 1) `php scripts/contract-parity-guard.php` → PASS · 2) `php scripts/subsystem-count-guard.php` → PASS · 3) `bash scripts/cadence-pressure-guard.sh` → exit 0 · 4) `php scripts/dead-file-scan.php` → unchanged · 5) repo-wide greps: `blue-collar-crypto-peepso-integration` → 0 live-doc hits (archive-only); `holder-groups-reviewer` → archive-only; old paths of 4 relocated docs → archive-only · 6) every md link in touched files resolves · 7) `git status` shows only the approved doc changes + this report; settings.json/hook untouched |
| bcc-search | README routes cross-checked against the 3 controllers' ROUTE consts; no code touched; branch+PR per repo convention |
| bcc-trust | CLAUDE.md edit only after scratchpad backup; `php -l` not needed (no PHP touched); confirm branch situation first (currently on `fix/reply-notify-comment-author` — coordinate before any repo op) |
| bcc-frontend | new `docs/archive/` + 2 moves + CLAUDE.md reword (after backup); `git status` shows only those; package.json untouched; no tsc needed (no TS touched) |
| all | No pushes/merges/deploys without separate approval; sub-repo changes go via branch+PR (main is protected in bcc-frontend) |

## 17. Unresolved decisions (none blocking)

All classifications are settled; three execution-time preferences remain open for the operator:

1. **`rollback-procedure.md` rewrite depth** — minimal (fix the git-pull claims + status header) vs full re-walk of the restore drill. Recommendation: minimal now; full drill re-validation belongs with the next deploy exercise.
2. **`cron-registry.md` ghost entry** — delete the row vs annotate "removed with plugin 2026-05". Recommendation: annotate (the registry self-describes as append-only).
3. **Timing** — execution should wait until `docs/staging-readiness-2026-07-19` merges (three UPDATE targets live on it). If that branch stalls, the non-conflicting subset (everything except TODO/capacity-model/testnet-checklist edits) can proceed first.

## 18. Approval checklist for the execution phase

- [ ] Approve **UPDATE** set (25 files, §9) — or name exclusions
- [ ] Approve **HISTORICAL** relocations (6 files, §10) incl. banner texts and the TODO-porting precondition for the implementation-audit
- [ ] Approve **DELETE** of `.claude/agents/holder-groups-reviewer.md` (§11)
- [ ] Approve skill conversions (§13) incl. the 4 inbound-link repairs
- [ ] Approve local-only CLAUDE.md edit protocol (scratchpad backup first, §13)
- [ ] Decide the three §17 preferences
- [ ] Confirm execution ordering: after `docs/staging-readiness-2026-07-19` merges (or approve the non-conflicting subset now)
- [ ] Confirm §15 configuration gaps are handled separately (not in the docs pass)

*Report generated 2026-07-19. No documentation was modified during this audit. This file (`docs-audit-report.md`, umbrella repo root, untracked) is the audit's only artifact.*
