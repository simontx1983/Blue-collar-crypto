# BCC Master Audit Remediation Checklist — 2026-07-21

**Authoritative tracker** for the full-workspace audit of 2026-07-21. Every substantiated finding,
deferral, decision, operator action, refuted item, and the post-audit finding **FN-01** is
represented exactly once (as a primary item or an attached related F-ID). This supersedes ad-hoc
tracking of the audit; `docs/TODO.md` remains the curated near-term operator list.

- **Source dataset:** the audit's `findings-final.json` — 291 rows (F001–F291); +FN-01..FN-05 (post-audit findings).
- **Status:** `OPEN` · `BLOCKED` (cannot proceed — usually PROD FROZEN) · `DECISION` (needs Phillip) · `DEFERRED` (valid future work, not now) · `DONE` (merged code / verified operator evidence) · `REFUTED` / `EXCLUDED`.
- **Priority:** P0/P1/P2/P3 (**no P0** in this audit). Shown per item as the **operational** (governing) priority; where it differs from the finding's **source** classification, both are shown (this happens only for CL-01/F058 — see the priority reconciliation).
- **Timing:** staging / before-production / post-launch. **Type:** code / test / docs / operator / external / decision.
- **DONE requires evidence** — merged PR + SHA, or verified operator evidence. Code merely existing is not DONE.

> ### ⚠ PROD IS FROZEN
> No production action executes until Phillip authorizes production. Every Group-3 / Batch-K item is `BLOCKED — PROD FROZEN` by default, even when fully specified.

### Checklist-ID scheme
IDs are **stable and assigned once** (never renumbered). Format `CL-⟨group⟩⟨seq⟩`: the leading
character maps to the group — `0x`→G1, `1x`→G2, `3x`–`9x`→G3–G9 (G3+ match the group number; G1/G2
are the two lowest sequences). Special entries use a **separate mnemonic namespace** so they can't
be mistaken for active work: **`CL-FN##`** = post-audit findings (`CL-FN01`..`CL-FN05`, IDs
`FN-01`..`FN-05`); **`CL-REF`** = refuted findings; **`CL-EXC`** = the excluded non-finding.
(There is no `CL-2x`; Group 2 uses `CL-10/11`.)

---

## 🧭 Current Command Center — 2026-07-22

| Question | Answer |
|---|---|
| **What blocks staging completion?** | Only **CL-02**: the known-module-6 (DM) staging permalink probe — BLOCKED, needs staging DB/SSH lookup. F058 code, deploy, and anon-feed smoke are all done. |
| **What must happen before production?** | ~9 operator/external actions (CL-30–38) + the CDN decision (CL-7B). All `BLOCKED — PROD FROZEN`. Zero prod **code** blockers. |
| **What needs my (Phillip's) decision?** | **11 decisions** (CL-70…CL-7B **except CL-73**) + legal approval (CL-38). *(Gate 12 / CL-7C **RESOLVED** — Option B. CL-73 cache-invalidation and CL-FN04 group-discovery are now **OPEN before-prod impl** items, not decisions.)* |
| **What's blocked by the prod freeze?** | Object cache, monitoring, DB backups, cron flip, prod redeploy, OAuth+secret rotation, cron secrets, auth-cache prod probe, legal, CDN. |
| **What can wait until after launch?** | Post-launch tech debt (CL-90–95), Group-8 deferrals, and post-audit FN-01 (CL-FN01) + FN-03 (CL-FN03). *(**Before-production**, not after-launch: the Option-B follow-ups CL-FN04 group-discovery, CL-FN06 `public_all` authz, CL-73 cache-invalidation, CL-FN05 composer verify.)* |
| **What should Claude do next?** | **Batch B** — correct PR #77's stale readiness gates, then merge PR #77 + fe#50. Then **C** (FN-01 liveness), **D** (moderation hardening), **E** (small fixes). |
| **Are all 291 rows accounted for?** | Yes — machine-validated: 291/291 mapped exactly once; +FN-01..FN-05 (see Validation). |

- **Recently completed / resolved:** CL-01 (F058 feed-module privacy) — bcc-core **PR #33**, merge **f9553f6**, staging-deployed 2026-07-22; **CL-7C — Gate 12 RESOLVED (Option B, "public_all wins", Phillip 2026-07-22)** — feed/permalink verified compliant; content-search enforcement OPEN (CL-87) + trace gaps FN-02..FN-05.
- **Item tally (70 primary):** Active **55** (OPEN 35 · BLOCKED 9 · DECISION 11) · Deferred **11** · Done **1** · Resolved **1** · Refuted **1** · Excluded **1**.

### Priority reconciliation — source vs operational
The audit **executive summary** counted F058 as **P1** (operational — a verified privacy leak);
**findings-final.json** preserved F058's earlier discovery/verification classification of
**ACTIONABLE/P2**. That single row is the entire difference between the two count sets. The source
dataset is **not** rewritten.

| Priority | Original dataset (findings-final.json `final_priority`) | Current operational (F058 reclassified P2→P1) |
|---|---|---|
| P1 | **29** | **30** |
| P2 | **74** | **73** |
| P3 | **185** | **185** |
| **total substantiated** | **288** | **288** |

**F058 reclassification record:** source **P2** → operational **P1**; reason: **verified anonymous
metadata/existence leakage of a private DM** (author, timestamp, reaction counts) via the feed and
`/feed/{id}`; evidence: local activity **150 returned HTTP 200 before the fix**, HTTP **404 after**
(PR #33 closed both the feed candidate query and the permalink path); date **2026-07-22**; status
**DONE** (code), with staging known-DM verification still **BLOCKED** (CL-02). *(Verification also
ADJUSTED 46 other findings' priority/classification; those adjustments are already baked into the
`final_priority` above and do not change the headline totals — the per-finding source values live in
the dataset.)*

---

## GROUP 1 — STAGING COMPLETION

### ☑ CL-01 — Gate public feed + permalink to a post-module allowlist — **DONE**
- **Priority:** P2 (source) → **P1 (operational)** · **Timing:** staging · **Type:** code · **Surface:** bcc-core
- **F-IDs:** F058
- **Location:** `src/Feed/FeedItemNormalizer.php`, `src/Repositories/PeepSoActivityRepository.php`, `src/Feed/ActivityFeedService.php`, `tests/FeedModuleAllowlistTest.php`
- **Current:** merged; allowlist `{1,4,200–204}` enforced at the candidate query + permalink; DMs/polls/unknown modules excluded.
- **Outcome:** private DMs and unsupported modules can no longer reach any public feed or permalink.
- **Acceptance:** ✅ PHPUnit 110/110, PHPStan L8 clean, local DM 404 + status/photo/blog 200.
- **Deps:** — · **Risk:** low (read-path filter) · **Size:** small · **Tracker:** this item · **Evidence:** bcc-core **PR #33**, merge **f9553f6** (commit 0b9aabd), 2026-07-22.

### ☐ CL-02 — Post-deploy staging verification of F058 — **OPEN (one substep BLOCKED)**
- **Priority:** P1 · **Timing:** staging · **Type:** test · **Surface:** stage.bluecollarcrypto.io
- **F-IDs:** — (verification activity; verifies CL-01)
- **Location:** `GET /bcc/v1/feed/{id}` + `/system/ping` on staging; staging DB (`wp_peepso_activities`)
- **Current:** — status ledger:
  - F058 code implementation — **DONE**
  - Local exploit regression (DM 200→404) — **VERIFIED**
  - Staging deployment (f9553f6) — **VERIFIED** (Deploy run 29925999834; `/system/ping` = ok)
  - Staging anonymous feed smoke — **VERIFIED** (`/feed/hot` returns only allowed kinds; allowed permalinks 244/242/240/239/125 → 200)
  - Known module-6 staging permalink test — **BLOCKED — NEEDS STAGING DB/SSH LOOKUP** (no authorized staging DB access this session; `/feed/150` 404 there is inconclusive — 150 likely absent)
  - Production verification — **NOT AUTHORIZED / PROD FROZEN**
- **Outcome:** a confirmed staging DM `act_id` returns 404 with no author/timestamp/reaction/message metadata.
- **Acceptance:** operator or a session with staging DB access identifies one module-6 `act_id` and confirms 404.
- **Deps:** staging DB/SSH access · **Risk:** low · **Size:** tiny · **Tracker:** this item · **Evidence:** — (partial; see ledger).

---

## GROUP 2 — BEFORE PRODUCTION · CODE

### ☐ CL-10 — Moderation launch hardening (hidden-content admin view + auto-hide audit log) — **OPEN**
- **Priority:** P2 · **Timing:** before-production · **Type:** code · **Surface:** bcc-trust
- **F-IDs:** F218, F219
- **Location:** `app/Domain/Core/Services/Feed/FeedRankingService.php:309`; `app/Domain/Core/Services/AutoHideService.php:8`
- **Current:** moderation queue "VIEW POST" 404s on hidden content (no admin bypass); auto-hide docblock claims a non-existent AuditLogger subscriber.
- **Outcome:** manage_options viewers preview the hidden content they adjudicate; auto-hide writes an audit row.
- **Acceptance:** admin permalink of a hidden act returns the item; auto-hide emits an audit row; unit tests added.
- **Deps:** decisions CL-71, CL-73 (adjacent, independent) · **Risk:** medium (feed-visibility code) · **Size:** small · **Tracker:** this item + admin-audit-2026-07-21.md · **Evidence:** —.

### ☐ CL-11 — Small correctness / security / copy fixes — **OPEN**
- **Priority:** P2 · **Timing:** before-production · **Type:** code · **Surface:** bcc-trust + bcc-frontend
- **F-IDs:** F066, F250, F025
- **Location:** `bcc-trust app/Domain/Disputes/Repositories/DisputeRepository.php:793`; `bcc-trust bcc-trust.php:643`; `bcc-frontend src/components/search/GlobalSearch.tsx:83` (fe#50)
- **Current:** panel insert omits `assigned_at`; secret-inventory filter lists 11 of 13 BCC secret constants; search placeholder says "Search the floor".
- **Outcome:** `assigned_at` set; inventory lists all 13; honest search copy.
- **Acceptance:** unit coverage for the insert; inventory complete; fe#50 merged.
- **Deps:** F025 tracked by open **fe#50** · **Risk:** low · **Size:** tiny · **Tracker:** this item · **Evidence:** —.

---

## GROUP 3 — BEFORE PRODUCTION · OPERATOR / EXTERNAL — **ALL `BLOCKED — PROD FROZEN`**

Authoritative gate list: `docs/performance-review-2026-07-19.md §A/§B`. `docs/TODO.md` Perf/Ops and
the testnet checklist/worksheet are duplicate references. Each item names its blocker (prod freeze)
and, where relevant, its dependency ordering.

### ☐ CL-30 — Prod object cache + `cache-ttl_rest`=60 + LSMCD — **BLOCKED — PROD FROZEN**
- **Priority:** P1 · **Timing:** before-production · **Type:** operator · **Surface:** Hostinger LSCWP / hPanel
- **F-IDs:** F004, F108, F109, F110, F187, F266, F267 (operator) · F157, F158, F159, F276, F283 (doc references corrected in the same pass)
- **Location:** prod `wp-config`/hPanel LSCWP; `docs/testnet-deploy-worksheet.md:79`, `testnet-deploy-checklist.md:195`, `environment.md:100`
- **Current:** staging done/verified 2026-07-19; **prod** has no persistent object cache and `ttl_rest`=604800.
- **Outcome:** `cache-ttl_rest`=60 + purge; restore `lib/object-cache.php`; `wp_using_ext_object_cache()` true; reconcile the worksheet Redis→LSMCD rows.
- **Acceptance:** anon REST TTL 60 verified on prod; ext object cache active; docs updated.
- **Deps:** prod authorization; precedes CL-33 · **Risk:** medium (cache correctness) · **Size:** small · **Tracker:** perf-review §A1/B1; TODO.md:56-58 · **Evidence:** —.

### ☐ CL-31 — External uptime monitor + degradation alerts — **BLOCKED — PROD FROZEN**
- **Priority:** P1 · **Timing:** before-production · **Type:** external · **Surface:** Better Stack/UptimeRobot + wp-config
- **F-IDs:** F189, F265, F270
- **Location:** monitor on `GET /bcc/v1/system/ping`; `BCC_DEGRADATION_ALERT_EMAIL` in prod wp-config; `operator-runbook.md:14`
- **Current:** endpoint live; no external monitor wired; runbook names a phantom monitor.
- **Outcome:** monitor + alert channel live; one test alert received; runbook updated to the real monitor.
- **Acceptance:** test alert received; `bcc_core_degradation_alert_check` cron scheduled.
- **Deps:** prod authorization · **Risk:** low · **Size:** small · **Tracker:** perf-review §A3 · **Evidence:** —.

### ☐ CL-32 — Automated offsite DB backups + restore drill — **BLOCKED — PROD FROZEN**
- **Priority:** P1 · **Timing:** before-production · **Type:** operator · **Surface:** host cron / GH Actions
- **F-IDs:** F155, F264
- **Location:** `docs/rollback-procedure.md:19` (DR gap: no automated backup exists in-repo)
- **Current:** no automated/offsite DB backup anywhere in the five repos.
- **Outcome:** scheduled `mysqldump` with offsite copy; one verified restore per the §6 drill; rollback-procedure §0 updated.
- **Acceptance:** a scheduled backup runs and one restore is verified against a scratch DB.
- **Deps:** prod authorization / host access · **Risk:** medium (data durability) · **Size:** small · **Tracker:** rollback-procedure.md:19 · **Evidence:** —.

### ☐ CL-33 — Prod cron flip (`DISABLE_WP_CRON` + hPanel + Vercel relay) — **BLOCKED — PROD FROZEN**
- **Priority:** P1 · **Timing:** before-production · **Type:** operator · **Surface:** wp-config + hPanel + Vercel
- **F-IDs:** F088, F161, F200, F279
- **Location:** prod `wp-config`; hPanel minutely cron; Vercel relay; `api-contract-v1.md:6102` (cadence note)
- **Current:** staging flipped 2026-07-16 (server-side mu-plugin define); prod not flipped.
- **Outcome:** `DISABLE_WP_CRON`=true on prod + system/relay cron; both 1-min jobs at cadence.
- **Acceptance:** both jobs observed at 60s cadence on prod.
- **Deps:** **after CL-30** · **Risk:** medium (async pipeline) · **Size:** small · **Tracker:** perf-review §B2; TODO.md:59 · **Evidence:** —.

### ☐ CL-34 — Prod plugin redeploy to SHA parity — **BLOCKED — PROD FROZEN**
- **Priority:** P1 · **Timing:** before-production · **Type:** operator · **Surface:** deploy.yml (manual prod dispatch)
- **F-IDs:** F115, F188, F268, F277
- **Location:** `deploy.yml` prod dispatch; `deploy-runbook.md:80`
- **Current:** prod on 2026-07-17 SHAs (trust 1.2.29 / core 5b8d345); **bcc-search never prod-deployed**; done-when must be SHA parity, not the version header.
- **Outcome:** prod Deploy `head_sha == origin/main` for trust/core/search.
- **Acceptance:** three prod Deploy runs succeed with head_sha == origin/main; health gates green.
- **Deps:** prod authorization; deploy CL-30 sequencing · **Risk:** medium · **Size:** small · **Tracker:** perf-review §A2; TODO.md:73 · **Evidence:** —.

### ☐ CL-35 — OAuth bridge secret + secret rotation + worksheet trap — **BLOCKED — PROD FROZEN**
- **Priority:** P1 · **Timing:** before-production · **Type:** external + docs · **Surface:** Vercel + wp-config + X portal + docs
- **F-IDs:** F003, F092, F160, F192, F248 (provisioning) · F156 (worksheet "gitignored" trap)
- **Location:** Vercel + wp-config `BCC_OAUTH_BRIDGE_SECRET`; X developer portal callback; `testnet-deploy-worksheet.md:27`; local `wp-config.php:117`
- **Current:** SSO fail-closed until the bridge secret is set both ends; local secret `470c…f7d0` is burned; worksheet:27 falsely says it is gitignored while tracked.
- **Outcome:** set `BCC_OAUTH_BRIDGE_SECRET` (≠ burned value) both ends; add X callback URL; rotate all §1.1 secrets; fix the worksheet warning.
- **Acceptance:** SSO round-trips on prod; prod secret differs from local; boxes checked; worksheet corrected.
- **Deps:** prod authorization · **Risk:** high (credential exposure) · **Size:** small · **Tracker:** testnet-checklist §7; SSO-hardening memory · **Evidence:** —.

### ☐ CL-36 — Cron secrets provisioning + env docs — **BLOCKED — PROD FROZEN**
- **Priority:** P1 · **Timing:** before-production · **Type:** external + docs · **Surface:** Vercel + wp-config + docs
- **F-IDs:** F269, F024, F154
- **Location:** Vercel/wp-config `CRON_SECRET` + `BCC_INTERNAL_CRON_SECRET`; `bcc-frontend/src/lib/env.ts:62`, `.env.local.example:28`; `environment.md:60`
- **Current:** the cron relay route throws / 500s every minute until both vars are set; env docs miss 3 rows (fe#52/#77).
- **Outcome:** set both secrets (Vercel Production + prod wp-config); verify one end-to-end relay tick; add the 3 env.md rows.
- **Acceptance:** one relay tick succeeds on prod; env docs complete.
- **Deps:** prod authorization; fe#52 / PR #77 (docs) · **Risk:** medium · **Size:** tiny · **Tracker:** fe#52; PR #77 Gate 3 · **Evidence:** —.

### ☐ CL-37 — Verify prod auth-header cache isolation — **BLOCKED — PROD FROZEN**
- **Priority:** P1 · **Timing:** before-production · **Type:** operator · **Surface:** `scripts/auth-cache-isolation-probe.sh` on prod host
- **F-IDs:** F190
- **Location:** the probe (refuses non-staging today, exit 3); prod edge config
- **Current:** staging isolation verified weekly; prod leg unverified since 2026-07-13.
- **Outcome:** run the probe against prod; confirm Bearer requests bypass the edge cache.
- **Acceptance:** probe passes on prod.
- **Deps:** prod authorization; CL-30 (cache active) · **Risk:** medium (auth cache bleed) · **Size:** small · **Tracker:** perf-review §A4; checklist §1.6 · **Evidence:** —.

### ☐ CL-38 — Legal / policy counsel approval — **BLOCKED (external approval)**
- **Priority:** P1 · **Timing:** before-production · **Type:** external · **Surface:** external counsel + bcc-frontend
- **F-IDs:** F023
- **Location:** `bcc-frontend/src/lib/legal/config.ts:5` (live Terms/Privacy/Cookies carry a "REVIEW REQUIRED — not counsel-reviewed" banner)
- **Current:** legal pages are live and fully populated but never counsel-reviewed; tracked nowhere else.
- **Outcome:** counsel signs off; then soften/remove the REVIEW REQUIRED block in the same commit recording the date.
- **Acceptance:** counsel sign-off recorded; banner removed.
- **Deps:** external counsel · **Risk:** medium (legal exposure) · **Size:** medium · **Tracker:** this item · **Evidence:** —.

---

## GROUP 4 — DOCUMENTATION & CONTRACT TRUTH

These are **documentation cleanup, not unfinished features** — the code is correct; the records
trail it. Land contract-file edits **after PR #77 merges** to avoid touching the same files.

### ☐ CL-40 — Feed group-privacy: correct contract + stale §4.7.x comments + delete inert code — **OPEN**
- **Priority:** P1/P2 · **Timing:** before-production · **Type:** docs + small code · **Surface:** umbrella + bcc-core + bcc-trust
- **F-IDs:** F001, F130, F132, F133, F230, F231, F240
- **Location:** `api-contract-v1.md:2082/2340/2346`; `bcc-trust FeedRankingService.php:264/350`; `bcc-core PeepSoActivityRepository.php` inert `$excludedGroupIds`
- **Current:** global feed uses an *intentional* per-post `public_all` gate (not a bug); contract + 6 comments claim a superseded exclude-list is live; the inert chain is still computed per request.
- **Outcome:** correct the 3 contract passages (PR #77 fixes line 2082 — **also fix its false "no longer recomputes" clause**); rewrite the comments; delete the inert exclude-list chain (keep `getNonOpenGroupIds` for F3).
- **Acceptance:** contract matches code; no inert list computed; comments accurate; feed tests green.
- **Deps:** PR #77 (contract line) · **Risk:** low-medium (touches hot-feed ranker) · **Size:** small · **Tracker:** this item; PR #77 · **Evidence:** —.

### ☐ CL-41 — PR #77 pre-merge corrections (readiness reconciliation) — **OPEN (blocks PR #77 merge)**
- **Priority:** P2 · **Timing:** before-staging · **Type:** docs · **Surface:** umbrella (branch `docs/staging-hardening-2026-07-21`)
- **F-IDs:** F002, F179, F220, F272, F186, F242, F273
- **Location:** `docs/production-readiness-2026-07-21.md` (Gates 8/11/12/4, throttle note) — on the PR #77 branch
- **Current:** the readiness doc was stale on arrival — Gate 8 (bcc-search unprotected→armed), Gate 11 (admin P1s merged), Gate 12 (**RESOLVED 2026-07-22: Option B — public_all wins**; see CL-7C), Gate 4 (workflow already armed), throttle "fails closed" note wrong. **All corrected on the PR #77 branch (2026-07-22 commits); awaiting PR #77 merge.**
- **Outcome:** amend the branch so the canonical readiness record is accurate (**done**), then merge PR #77 (+ fe#50).
- **Acceptance:** gates 8/11/12/4 + throttle note corrected (**done on branch**); PR #77 merged (pending).
- **Deps:** feeds Batch B; pairs with CL-46 · **Risk:** low · **Size:** small · **Tracker:** PR #77 · **Evidence:** —.

### ☐ CL-42 — `api-contract-v1.md` truth sweep — **OPEN**
- **Priority:** P2/P3 · **Timing:** before-production · **Type:** docs · **Surface:** umbrella
- **F-IDs:** F006, F131, F134, F135, F136, F014, F015, F016, F257, F139, F140
- **Location:** `api-contract-v1.md` header:3, §4 search:3222/3236, producer:3616, aliases:3316, NftSelection:4543, §8:5859
- **Current:** header still v1.44 while changelog reaches v1.47; several §4/§8 rows describe shipped features as pending or use retired shapes; F139/F140 are accurate deferral notes (no change).
- **Outcome:** bump header; fix §4 search error/auth lines; tier-upgrade producer row; delete legacy-alias + NftSelection-debt paragraphs; refresh §8 shipped rows.
- **Acceptance:** header v1.47; each cited passage matches code; deferral notes retained.
- **Deps:** **after PR #77** (same file) · **Risk:** low · **Size:** small · **Tracker:** this item · **Evidence:** —.

### ☐ CL-43 — Registry / schema / operator-runbook reconciliation — **OPEN**
- **Priority:** P2/P3 · **Timing:** before-production · **Type:** docs · **Surface:** umbrella
- **F-IDs:** F008, F147, F141, F142, F144, F145, F146, F148, F149, F150, F151, F152, F163, F249, F164
- **Location:** `ci-topology.md:57`, `dev-setup.md:132`, `cron-registry.md`, `database-schema.md`, `pattern-registry.md:254`, `domain-seams.md`, `glossary.md`, `GOLDEN_PATHS.md:3/842`, `operator-runbook.md:95/113`
- **Current:** bcc-search shown unprotected (now armed); cron-registry missing 8 hooks; schema missing 2 tables; interface count 11→12; glossary Endorse/Stoke drift; operator-runbook "18-subsystem" (actual 27) + missing helius_dedup rows; GOLDEN_PATHS "verified 2026-05-13".
- **Outcome:** one umbrella docs reconciliation PR fixing all rows; schedule a GOLDEN_PATHS end-to-end walk (F152) before bumping its date.
- **Acceptance:** each cited claim matches code; subsystem-count guard green.
- **Deps:** — · **Risk:** low · **Size:** medium · **Tracker:** this item · **Evidence:** —.

### ☐ CL-44 — Perf/capacity doc hygiene — **OPEN**
- **Priority:** P2/P3 · **Timing:** before-production / after-launch · **Type:** docs · **Surface:** umbrella
- **F-IDs:** F201, F194, F113, F195, F196, F197, F198, F199, F021
- **Location:** `performance-review-2026-07-19.md:12/177/252/344`, `capacity-model.md:21/404/525/844`, `TODO.md:61`
- **Current:** 07-16 measurement day overtook several perf surfaces; knee figures (12–15 vs 15–20 req/s), 5-core vs 4-core, 4200-vs-3216 requests unreconciled; B5 telemetry snippet not in runbook.
- **Outcome:** close perf-review B4/B5; reconcile figures; add SSH sampler + thresholds to operator-runbook.
- **Acceptance:** figures consistent across docs; sampler in runbook.
- **Deps:** — · **Risk:** low (docs) · **Size:** small · **Tracker:** performance-review-2026-07-19.md · **Evidence:** —.

### ☐ CL-45 — Probe-workflow "inactive" staleness — **OPEN**
- **Priority:** P2/P3 · **Timing:** before-production · **Type:** docs · **Surface:** umbrella
- **F-IDs:** F007, F165, F184, F232, F274, F185, F233, F275
- **Location:** `.github/workflows/staging-cache-probe.yml:9`; `performance-review-2026-07-19.md:177/391`
- **Current:** header says "PREPARED, NOT ACTIVE / intentionally uncommitted"; it is committed (e5d25b6) and ran Mon 2026-07-20.
- **Outcome:** rewrite the header to "ACTIVE since 2026-07-19"; fix the doc echoes.
- **Acceptance:** header + echoes state the workflow is active.
- **Deps:** — · **Risk:** none · **Size:** tiny · **Tracker:** the workflow header · **Evidence:** —.

### ☐ CL-46 — Throttle fail-closed doc correction — **OPEN**
- **Priority:** P2 · **Timing:** before-production · **Type:** docs · **Surface:** umbrella
- **F-IDs:** F162, F271, F193
- **Location:** `operator-runbook.md:42`; `performance-review-2026-07-19.md:341` (B1 severity); pairs with PR #77 readiness note (CL-41)
- **Current:** docs claim Throttle "fails closed" without object cache; code truth: it degrades to bcc-trust's `wp_options`-backed RateLimiter (deny-all only if bcc-trust is also absent).
- **Outcome:** correct the wording; downgrade object cache from "functional prerequisite" to "perf/scale prerequisite."
- **Acceptance:** runbook + perf-review + PR #77 note match code.
- **Deps:** pairs with CL-41 · **Risk:** low · **Size:** tiny · **Tracker:** this item · **Evidence:** —.

### ☐ CL-47 — `TODO.md` hygiene (check off shipped, trim meta) — **OPEN**
- **Priority:** P2/P3 · **Timing:** n/a · **Type:** docs · **Surface:** umbrella
- **F-IDs:** F103, F104, F105, F107, F100, F111, F114, F116, F117, F118
- **Location:** `docs/TODO.md:20/32/44-49/59/62/83`
- **Current:** observability items (shipped 2026-07-10) + orphan-table drop still unchecked; stale intro; >30-day "Recently shipped" entries.
- **Outcome:** tick shipped items with PR refs; rewrite intro; trim old entries.
- **Acceptance:** no shipped item shown open; intro current.
- **Deps:** — · **Risk:** none · **Size:** small · **Tracker:** docs/TODO.md · **Evidence:** —.

### ☐ CL-48 — `v2-roadmap.md` staleness refresh — **OPEN**
- **Priority:** P2/P3 · **Timing:** after-launch · **Type:** docs · **Surface:** umbrella
- **F-IDs:** F120, F122, F123, F124, F125, F126, F127
- **Location:** `docs/v2-roadmap.md:3/45/70/73/86/123/133`
- **Current:** Injective shown OPEN (shipped); stale syncing-chip/endorse rows; "9-event" push list (actual 13); stale subtotal + date.
- **Outcome:** flip stale rows to SHIPPED; correct subtotals; point push list at `NotificationPrefs::PUSH_TYPES`.
- **Acceptance:** rows match code; subtotals correct.
- **Deps:** — · **Risk:** none · **Size:** small · **Tracker:** docs/v2-roadmap.md · **Evidence:** —.

### ☐ CL-49 — bcc-search repo docs (README + plugin header + docstring) — **OPEN**
- **Priority:** P2/P3 · **Timing:** before-staging → after-launch · **Type:** docs · **Surface:** bcc-search
- **F-IDs:** F010, F062, F119, F138, F182, F203, F239, F285, F291, F097, F153, F166, F263 (README) · F061, F245 (response example) · F063, F246 (plugin Description) · F244 (UserSearch docstring) · F064, F065 (accurate deferral comments — no action)
- **Location:** `bcc-search/README.md`, `bcc-search.php:4`, `app/Repositories/UserSearchRepository.php:36`
- **Current:** committed README documents the dead `[bcc_search]` shortcode; an accurate-but-flawed rewrite sits uncommitted (**its `/search/groups` row wrongly says "secret/closed excluded" — code excludes only secret**, F138); stale response example + plugin Description.
- **Outcome:** one bcc-search docs PR: commit the corrected rewrite (fix groups row + response example), update the plugin Description, fix the UserSearch docstring.
- **Acceptance:** README matches the 3 live verticals + real response shape; header accurate.
- **Deps:** ⚠ shared-worktree — check HEAD, stage `README.md` explicitly · **Risk:** low · **Size:** small · **Tracker:** this item · **Evidence:** —.

### ☐ CL-4F — Pattern-registry Search section (split from CL-49) — **OPEN**
- **Priority:** P2 · **Timing:** before-production · **Type:** docs · **Surface:** umbrella
- **F-IDs:** F009, F143
- **Location:** `docs/pattern-registry.md:152-153/179-190`
- **Current:** teaches the §11 audience that the live search verticals are "dormant" and that frontend fan-out is forbidden — the shipped, contract-locked design violates both sentences.
- **Outcome:** rewrite the Search section to describe the live verticals + the CardsSearchEndpoint adapter.
- **Acceptance:** section matches the shipped search architecture.
- **Deps:** — (separate repo/action from CL-49) · **Risk:** low · **Size:** small · **Tracker:** docs/pattern-registry.md · **Evidence:** —.

### ☐ CL-4A — CLAUDE / glossary / smoke-checklist / trust-engine doc-sync — **OPEN**
- **Priority:** P2/P3 · **Timing:** before-production / n/a · **Type:** docs · **Surface:** umbrella + local CLAUDE.md
- **F-IDs:** F213, F214, F207, F208, F209, F210, F211, F212, F217, F205, F206
- **Location:** `CLAUDE.md:67/163`, `docs/trust-engine-coverage.md:117/189`, `docs/v1-smoke-test-checklist.md:90/503/512`, `docs/glossary.md:12/91`, `docs/trust-attestation-layer.md:1199`
- **Current:** CLAUDE.md missing skills/MCP; trust-engine rows stale; smoke 14.10 partial-ship / 14.12 shipped; glossary drift; §J.9 vouch/stand_behind removal unrecorded; F205/F206 are re-runnable checklist boxes (no code change).
- **Outcome:** doc-sync PR across the five files; note the smoke boxes stay unchecked in git by design.
- **Acceptance:** each cited claim matches code; smoke rows corrected.
- **Deps:** — · **Risk:** low · **Size:** small · **Tracker:** this item · **Evidence:** —.

### ☐ CL-4B — bcc-frontend stale header comments — **OPEN**
- **Priority:** P3 · **Timing:** n/a / after-launch · **Type:** docs (1 behavior-adjacent) · **Surface:** bcc-frontend
- **F-IDs:** F026, F027, F028, F029, F030, F031, F032, F033, F044, F236, F034, F048
- **Location:** `src/lib/api/{locals-endpoints.ts:10,types.ts:2838/5131}`, `components/feed/{FeedPostBody.tsx:208,postBody.ts:22}`, `components/profile/{ProfileTabs.tsx:22/60,ProfilePanel.tsx:213}`, `components/directory/DirectoryFilters.tsx:19`, etc.
- **Current:** header comments describe deferred surfaces that shipped (F048 chain filter is live → STALE); F034 EDIT PROFILE link mis-targets.
- **Outcome:** one comment-only sync PR + the single `/settings/profile` link retarget (the only rendered-copy change).
- **Acceptance:** comments match shipped surfaces; link retargeted; tsc clean.
- **Deps:** — · **Risk:** low · **Size:** small · **Tracker:** this item · **Evidence:** —.

### ☐ CL-4C — bcc-core stale comments — **OPEN**
- **Priority:** P3 · **Timing:** n/a / after-launch · **Type:** docs · **Surface:** bcc-core + umbrella
- **F-IDs:** F050, F280, F051, F059
- **Location:** `src/Feed/ActivityFeedService.php:406`, `src/Security/Throttle.php:485`; `api-contract-v1.md` §1.5 (rs field)
- **Current:** duplicate placeholder comment (F050/F280), orphaned docblock (F051), contract references a never-shipped `rs` cursor field (F059).
- **Outcome:** rewrite the sentinel comment; relocate the orphan docblock; reword the contract `rs` note.
- **Acceptance:** comments accurate; contract note corrected.
- **Deps:** F059 after PR #77 (contract file) · **Risk:** none · **Size:** tiny · **Tracker:** this item · **Evidence:** —.

### ☐ CL-4D — bcc-trust stale comments (ghost slugs, deferred-list prose) — **OPEN**
- **Priority:** P3 · **Timing:** n/a / after-launch · **Type:** docs · **Surface:** bcc-trust
- **F-IDs:** F068, F069, F070, F072, F073, F074, F075, F076, F077, F086, F222, F223, F227, F228, F237, F238
- **Location:** `includes/enqueue.php:20`, `app/Domain/Core/Plugin.php:1122`, various `Services/*` + `Repositories/*` docblocks, `scripts/arch-guardrails.sh:50`, `bcc-trust.php:523`
- **Current:** ~16 stale comment sites — ghost `trust-frontend.js` refs, "remediation must be paid down" prose (shipped), reconcile-rotation "deferred" (implemented), HighlightsService "all three stubs" (2/3 live).
- **Outcome:** one bcc-trust comment-only sweep correcting all sites.
- **Acceptance:** comments match shipped code; no behavior change; php -l clean.
- **Deps:** — · **Risk:** low · **Size:** medium (~16 sites) · **Tracker:** this item · **Evidence:** —.

### ☐ CL-4E — OAuth/webhook stale header + secret descriptions — **OPEN**
- **Priority:** P3 · **Timing:** before-production / after-launch · **Type:** docs · **Surface:** umbrella + bcc-trust + bcc-frontend
- **F-IDs:** F251, F253, F252, F254, F255, F261
- **Location:** `bcc-frontend/src/lib/env.ts:89`, `scripts/contract-parity-guard.php:110`, `testnet-deploy-checklist.md:33`, `environment.md:49`, `bcc-trust AuthEndpoint`/`NftGroupGateService.php:11`
- **Current:** stale `X-Bcc-Oauth-Secret` bearer-header descriptions (actual = HMAC signing); Helius webhook line ref off; `[AuthEndpoint]` log prefix; "deferred to PR 4".
- **Outcome:** rewrite the descriptions to the HMAC design; fix the line ref; delete the "PR 4" note.
- **Acceptance:** each description matches the shipped auth/webhook design.
- **Deps:** — · **Risk:** none · **Size:** tiny · **Tracker:** this item · **Evidence:** —.

---

## GROUP 5 — ADMIN & MODERATION

### ☐ CL-50 — admin-audit-2026-07-21.md resolution addendum — **OPEN**
- **Priority:** P2/P3 · **Timing:** before-staging → after-launch · **Type:** docs · **Surface:** umbrella + trust-engine-coverage + v1-smoke-checklist
- **F-IDs:** F167, F011, F168, F169, F170, F171, F172, F173, F174, F175, F180, F181, F281
- **Location:** `docs/admin-audit-2026-07-21.md` (all findings present-tense, no resolution note); `trust-engine-coverage.md:189`; `v1-smoke-test-checklist.md:313/370/448` (stale ViewerMenu)
- **Current:** every code finding is fixed on main (fe#51/#53, trust#98/#99, core#32) but the doc is frozen; stale ViewerMenu refs persist.
- **Outcome:** append a "Resolution (2026-07-21)" table citing the PRs; retarget ViewerMenu refs to the live AVATAR_MENU.
- **Acceptance:** doc marks each finding resolved with a PR ref; no stale ViewerMenu refs remain.
- **Deps:** — · **Risk:** low · **Size:** small · **Tracker:** admin-audit-2026-07-21.md · **Evidence:** —.
- *(Moderation hardening code = CL-10; moderation decisions = CL-71/73/74; moderation-unification deferral = CL-81.)*

---

## GROUP 6 — CI, GUARDRAILS, TEST RELIABILITY

### ☐ CL-60 — Arm + fix `schema-drift-guard.php` — **OPEN**
- **Priority:** P2 · **Timing:** before-production · **Type:** test/docs · **Surface:** umbrella
- **F-IDs:** F013, F282, F204
- **Location:** `scripts/schema-drift-guard.php:26/454/766`; `.github/workflows/ci.yml`; `CLAUDE.md:127`
- **Current:** the guard **exits 1 today** on two real static drifts (`wp_bcc_post_shortcodes` undocumented; RETIRED-status misparse); not wired to CI.
- **Outcome:** document the table, teach the RETIRED parser, refresh the header, arm static mode in CI + required checks (live-DB mode stays a staging step).
- **Acceptance:** guard exits 0 in static mode; CI runs it as a required check.
- **Deps:** database-schema fix overlaps CL-43 · **Risk:** low-medium · **Size:** medium · **Tracker:** this item; CLAUDE.md guard section · **Evidence:** —.

### ☐ CL-61 — Golden fixtures recapture + Windows swallow fix — **OPEN**
- **Priority:** P2/P3 · **Timing:** n/a · **Type:** test · **Surface:** bcc-trust
- **F-IDs:** F288, F289, F290
- **Location:** `bcc-trust/scripts/golden/manifest.txt`, `scripts/verify-golden.sh:52`
- **Current:** golden net red on 3/5 fixtures; `verify-golden.sh` swallows a cp1252 read error on Windows (silent exit 1); CI-exclusion + authed-feed gap are documented deferrals.
- **Outcome:** recapture the 5 fixtures; force UTF-8 read; add the rolling-date manifest note.
- **Acceptance:** `verify-golden.sh` green on 5/5 and fails loudly on a real diff.
- **Deps:** — · **Risk:** low · **Size:** small · **Tracker:** scripts/golden/manifest.txt · **Evidence:** —.

### ☐ CL-62 — Declare `sodium,openssl` in bcc-core CI — **OPEN**
- **Priority:** P3 · **Timing:** before-production · **Type:** test · **Surface:** bcc-core
- **F-IDs:** F287
- **Location:** `bcc-core/.github/workflows/ci.yml:26`
- **Current:** crypto suites rely on setup-php default builds for sodium/openssl rather than declaring them.
- **Outcome:** add `sodium, openssl` to the extensions line.
- **Acceptance:** CI declares the extensions; crypto suites run.
- **Deps:** — · **Risk:** none · **Size:** tiny · **Tracker:** this item · **Evidence:** —.

### ☐ CL-63 — Throwaway guard scripts (no action) — **DEFERRED**
- **Priority:** P3 · **Timing:** after-launch · **Type:** docs · **Surface:** umbrella
- **F-IDs:** F017, F018, F215
- **Location:** `scripts/wallet-case-preservation-check.php:13`, `scripts/bcc-query-floor-probe.php:3`; `CLAUDE.md:129`
- **Current:** self-declared throwaway/temporary diagnostics, deliberately out of CI; comments accurately mirror the scripts.
- **Outcome:** none now; delete when their backlogs (wallet-case check, boot-floor) close.
- **Acceptance:** n/a (deferral) — revisit at backlog close.
- **Deps:** — · **Risk:** none · **Size:** tiny · **Tracker:** CLAUDE.md guard section · **Evidence:** —.

### ☐ CL-64 — Infrastructure under version control — **OPEN**
- **Priority:** P2/P3 · **Timing:** before-production / n/a · **Type:** operator + code · **Surface:** umbrella + local
- **F-IDs:** F005, F039, F202, F090, F278, F089, F095, F096
- **Location:** `.claude/hooks/color-token-check.sh` (untracked) + `.claude/settings.json`; `app/public/wp-content/mu-plugins/bcc-disable-wp-cron.php:26`; `.gitignore:19`; `wp-content/.claude/`; `~/bcc-smoke`
- **Current:** the color guard + mu-plugins exist only per-machine under a deny-all `.gitignore`; the committed CLAUDE.md documents the guard as active; a wp-content `.claude/` holds a plaintext credential.
- **Outcome:** commit the hook + settings; allowlist the BCC mu-plugins (guard the false admin notice, F089); `git init` the smoke harness; delete/rotate the leaked credential.
- **Acceptance:** guard fires on other checkouts; mu-plugins tracked; credential rotated.
- **Deps:** — · **Risk:** low · **Size:** small · **Tracker:** this item · **Evidence:** —.

---

## GROUP 7 — PRODUCT DECISIONS (Phillip) — all **DECISION**

Each: **Question → Recommendation → Launch consequence → Options → F-IDs.** (Type: decision. Fields
Deps/Risk/Size/Tracker apply to the *resulting* work once decided.)

### ◆ CL-70 — Fake "Sponsored" ad slides — F022 · P2 · before-prod · bcc-frontend
- **Type:** decision · **Risk (resulting work):** low
- **Location:** `src/components/layout/AdCarousel.tsx:4` (3 fake "Sponsored" slides, dead CTAs, live on RightSidebar/PostRightRail).
- **Q:** Ship, hide, or remove the fake sponsored slides while sponsorship is deferred?
- **Rec:** **Remove for launch.** **Consequence if shipped:** a fake sponsored placement on a trust platform is a credibility risk. **Options:** remove / hide behind a flag / keep. **Deps:** CL-80. **Risk of building:** low. **Size:** tiny. **Tracker:** this item.

### ◆ CL-71 — Dead "Report / Copy link" comment actions — F035, F221 · P2 · before-prod · bcc-frontend
- **Type:** decision · **Risk (resulting work):** low
- **Location:** `src/components/feed/CommentDrawer.tsx:1112/1170` (aria-disabled "coming soon" stubs).
- **Q:** Build the comment-report backend, or hide the stubs?
- **Rec:** **Hide the stubs for launch** (report is a real backend slice). **Consequence:** dead affordances erode trust. **Options:** hide / build report / build copy-link only. **Deps:** gates CL-10. **Size:** small (hide) / medium (build). **Tracker:** this item.

### ◆ CL-72 — Hardcoded "Operational" footer dot — F036 · P2 · before-prod · bcc-frontend
- **Type:** decision · **Risk (resulting work):** low
- **Location:** `src/components/layout/SiteFooter.tsx:192` (hardcoded green, never fetches).
- **Q:** Wire to health, or remove?
- **Rec:** **Remove for launch** — the F6 health endpoint is admin-gated, so wiring is costly; an always-green dot misleads. **Options:** remove / wire (needs a public health field) / keep. **Size:** tiny. **Tracker:** this item.

### ☐ CL-73 — Public-cache invalidation on hide / delete / restore / downgrade (all layers) — **OPEN**
- **Priority:** P2 · **Timing:** before-production · **Type:** code/test · **Surface:** bcc-trust + bcc-core + LiteSpeed edge
- **F-IDs:** **F234** (primary) · **FN-02** (post-audit trace evidence) — *consolidated: one root problem = one item; this **supersedes/absorbs** the former separate CL-FN02 so there are not two competing root items.*
- **Location:** `ModerationQueueService.php:366` (hide); `HiddenActivityRepository` generation (`FeedRankingService.php:168-171`); delete/trash via `post_status`; `EdgeCache` (wired for `TAG_MEMBERS` only — no feed/permalink tag); LiteSpeed `ttl_rest`.
- **Current — two cache layers, described separately (corrected):**
  - **Origin hot-feed object cache:** moderation **hide** bumps `HiddenActivityRepository`'s generation → the anon hot-feed object cache re-keys (code-verified). **Delete/trash does NOT** bump that generation (it drops from live queries via `post_status`, but a cached anon hot-feed copy persists until `HOT_CACHE_TTL=300s` or the 1-min warm-cron rebuild — both **code-verified origin** figures). A future `public_all→private` **downgrade** has no implementation and no purge path (CL-FN03).
  - **LiteSpeed / edge REST cache:** feed/permalink/hot/tag endpoints have **no verified targeted purge or tag**. **An origin generation bump does NOT invalidate an already-cached edge response.** So moderation **hide is NOT universally "instant"** — it is instant at the *origin object cache* only; the *edge* copy persists until the REST TTL. **Edge staleness is bounded by `ttl_rest`, which is env-specific config, not a code constant:** staging `ttl_rest=60` (**verified** live 2026-07-19); prod `ttl_rest=604800` (a **documented** value, **not** a live-verified prod runtime). **No single "max stale" figure is claimed for the edge until the active per-env REST TTL is verified.**
- **Outcome:** a targeted purge/tag (or generation path) for the **feed lists, hot feed, permalink, tag feed, and any future content-search cache** on **hide, delete/trash, restore, and any future visibility downgrade**, at **both** the origin object-cache and the LiteSpeed-edge layers.
- **Acceptance:** for each of {hide, delete/trash, restore, downgrade}, the affected post disappears from {feed, hot, permalink, tag, future search} at **both** the origin object cache **and** the LiteSpeed edge within the expected window — **verified at both layers** (not assumed); tests cover each transition. *(Downgrade implementation stays deferred — CL-FN03 — but its invalidation is required as part of that feature when built.)*
- **Deps:** CL-10 (moderation), CL-30 (prod `ttl_rest=60` shrinks the edge window), CL-FN03 (downgrade) · **Risk:** medium (removed content lingering on public surfaces) · **Size:** medium · **Tracker:** this item — **authoritative for public-cache invalidation** · **Evidence:** 2026-07-22 read-only trace (origin verified; **edge purge UNVERIFIED / REQUIRES TEST**).

### ◆ CL-74 — Additional report target types — F137 · P3 · before-prod · bcc-trust
- **Type:** decision · **Risk (resulting work):** low
- **Location:** `api-contract-v1.md:4512` / `ContentReportService.php:61` (`TARGET_KINDS=['feed_item']`).
- **Q:** Extend reports to `user_profile` + card kinds, or leave feed-item only?
- **Rec:** **Leave for launch** — `/report-user` already covers profiles; card-report is a small P3. **Options:** build card-report / restamp §J.9 as deferred-to-cards-only. **Size:** small. **Tracker:** this item.

### ◆ CL-75 — Private-group hashtag counts in trending — F247 · P3 · after-launch · bcc-trust
- **Type:** decision · **Risk (resulting work):** medium (privacy)
- **Location:** `app/Domain/Core/REST/Hashtag*` over `peepso_hashtags` (no access predicate).
- **Q:** Accept-and-document, or filter private-group tags out of anon trending?
- **Rec:** **Document-and-accept for launch;** revisit when F3 builds a visibility-filtered corpus. **Options:** accept / derive from filtered corpus. **Deps:** CL-87 (F3). **Size:** medium. **Tracker:** this item.

### ◆ CL-76 — Legacy AJAX retirement — F052 · P3 · after-launch · bcc-core
- **Type:** decision · **Risk (resulting work):** low
- **Location:** `bcc-core.php:564` (`legacy_ajax`, 30-day zero-hit).
- **Q:** Retire it (Stabilization Phase D)?
- **Rec:** **Retire** after a counter check + sign-off (safe). **Options:** retire / keep one cycle. **Note:** Phase D forbidden without approval (memory). **Size:** small. **Tracker:** this item.

### ◆ CL-77 — Onchain/refresh + disputes/resolve admin endpoints — F177, F176 · P3 · after-launch · bcc-trust
- **Type:** decision · **Risk (resulting work):** low
- **Location:** `app/Domain/Onchain/…/refresh` (200-for-missing-page wart); `POST /disputes/:id/resolve` + `GET /disputes/health`.
- **Q:** Delete or explicitly reserve these unused admin endpoints?
- **Rec:** decide during the CL-50 addendum PR; if kept, fix the 200-for-missing wart. **Options:** delete / reserve (documented). **Deps:** CL-50. **Size:** small. **Tracker:** this item.

### ◆ CL-78 — Streak-freeze mechanic — F121 · P2 · after-launch · roadmap
- **Type:** decision · **Risk (resulting work):** low (cleanup if dropped)
- **Location:** `docs/v2-roadmap.md:35`; stale streak docblocks in `LivingHeader.tsx`/`ActivityPanel.tsx`.
- **Q:** Build a streak-freeze in V2, or drop it?
- **Rec:** **WON'T SHIP** — contradicts the LivingHeader constitutional removal + cadence-pressure policy. **Options:** WON'T SHIP (recommended) / defer to V2. **Size:** n/a (decision) → cleanup if dropped. **Tracker:** this item.

### ◆ CL-79 — `app/Infrastructure/` (M1.4) collapse marker — F101 · P3 · n/a · bcc-trust
- **Type:** decision · **Risk (resulting work):** low
- **Location:** `docs/TODO.md:33`; `app/Infrastructure/`.
- **Q:** Finish the collapse, or retire the marker (partially overtaken)?
- **Rec:** **Retire the marker** (record the decision). **Options:** retire / complete. **Size:** tiny (retire). **Tracker:** this item.

### ◆ CL-7A — FeedRankingService hot-feed cache key — F106 · P3 · after-launch · bcc-trust
- **Type:** decision · **Risk (resulting work):** low
- **Location:** `FeedRankingService.php:152` (cache-key fold — a design decision, not a bug).
- **Q:** Keep the current fold, or change it?
- **Rec:** **Record the current design as intended** (needs only a nod). **Options:** keep / re-scope. **Size:** n/a. **Tracker:** this item.

### ◆ CL-7B — CDN launch posture — F191, F112 · P1 · before-prod · Hostinger (external)
- **Type:** decision · **Risk (resulting work):** medium (launch posture)
- **Location:** `performance-review-2026-07-19.md:340`; `TODO.md:60`; deploy checklist (no CDN section).
- **Q:** Hostinger CDN on or off for launch (it was the load-test ban layer)?
- **Rec:** **Decide, then record** the on/off choice + hPanel deactivate/unban procedure in the deploy checklist; if on, get per-IP burst thresholds first. **Consequence:** unmade → launch-week risk. **Options:** on (tuned) / off. **Deps:** produces a Group-3 operator step. **Size:** small (doc) + operator. **Tracker:** this item.

### ☑ CL-7C — Gate 12: `public_all`-in-secret-group policy — **RESOLVED (Option B)**
- **Priority:** P2 · **Timing:** before-staging · **Type:** decision · **Surface:** product policy → feed/permalink (shipped) + future F3 content search
- **F-IDs:** — (Gate-12 product decision; the underlying audit findings are owned by CL-87 and CL-41)
- **Location:** `production-readiness-2026-07-21.md` Gate 12; `content-search-privacy-design.md` (2026-07-22 banner); `api-contract-v1.md` §feed
- **Q:** Should an explicit `public_all` post syndicate to public surfaces (incl. content search + group discovery) even inside a closed/secret group?
- **Decision:** **RESOLVED — Option B, "public_all wins"** · **owner:** Phillip · **date:** 2026-07-22.
- **Rationale:** communities (incl. NFT / private-membership) need a controlled way to show selected public activity to attract followers/members; group privacy protects membership + private-by-default discussion but must not block an author from deliberately publishing an individual post publicly.
- **Current shipped behavior (verified policy-compliant, 2026-07-22 read-only trace):** feed/hot/tag/cold-start/permalink enforce the `public_all` gate + F058 module allowlist + `publish` + moderation-hide + fail-closed; comments, member roster, and private group metadata stay private; no anonymous enumeration of non-`public_all` secret-group posts.
- **Still OPEN / do NOT mark implemented:** content search **NOT BUILT** → enforcement OPEN (CL-87); public group-discovery/preview under-delivered + secret-group public preview not built (CL-FN04, before-prod); composer public-visibility disclosure unverified (CL-FN05); public-cache invalidation on hide/delete/restore/downgrade at both origin+edge (CL-73, absorbing FN-02) + future downgrade purge (CL-FN03); `public_all` **authorization** policy (owner/mod-controlled) not built (CL-FN06, before-prod). **Naming note:** Phillip's "Option B" = content search **mirrors** the feed for `public_all` — this **reverses** the design doc's earlier "Option B = search stricter."
- **Outcome:** decision recorded across the three PR-#77 docs. **Acceptance:** recorded (**done on branch**); verification/build items tracked separately (below).
- **Deps:** — · **Risk:** low (documentation) · **Size:** small · **Tracker:** this item + `content-search-privacy-design.md` · **Evidence:** Phillip's 2026-07-22 decision; read-only privacy trace 2026-07-22.

---

## GROUP 8 — INTENTIONAL DEFERRALS (by product area) — all **DEFERRED**

Valid future work; **not defects.** Do not implement now. Misleading deferral *comments* are routed
to their Batch-H doc item (not built here).

### ☐ CL-80 — Sponsorship management — **DEFERRED**
- **Priority:** P3 · **Timing:** post-launch · **Type:** decision/deferral · **Surface:** wp-admin (planned)
- **F-IDs:** F128, F183 · **Unlock:** Phillip, post-launch; placement = wp-admin cockpit.
- **Current:** zero backend code exists (verified). **Outcome:** none now. **Acceptance:** n/a until scheduled. **Deps:** CL-70 (fake slides) · **Risk:** none · **Size:** medium (future) · **Tracker:** v2-roadmap.md:113.

### ☐ CL-81 — Unify daily moderation into `/admin/*` — **DEFERRED**
- **Priority:** P3 · **Timing:** post-launch · **Type:** deferral · **Surface:** bcc-frontend + bcc-trust
- **F-IDs:** F129, F225 · **Unlock:** V2 operator-tooling. **Current:** logged 2026-07-21; some daily moderation still requires wp-admin. **Outcome:** none now. **Acceptance:** n/a. **Deps:** — · **Risk:** none · **Size:** large (future) · **Tracker:** v2-roadmap.md:101.

### ☐ CL-82 — NFT indexer one-shot backfill — **DEFERRED**
- **Priority:** P3 · **Timing:** post-launch · **Type:** deferral · **Surface:** bcc-trust Onchain
- **F-IDs:** F102, F256 · **Unlock:** V2 (EVM walker is forward-only). **Current:** first-link history not walked (audit MEDIUM). **Outcome:** none now. **Acceptance:** n/a. **Deps:** — · **Risk:** low · **Size:** medium (future) · **Tracker:** TODO.md:34.

### ☐ CL-83 — Onchain / highlights / signal stubs — **DEFERRED**
- **Priority:** P3 · **Timing:** post-launch · **Type:** deferral · **Surface:** bcc-trust
- **F-IDs:** F079, F080, F081, F082, F083, F084, F087, F258, F259, F260, F262 · **Unlock:** demand-gated per chain / V1.5. **Current:** documented stubs (cNFT, delegation, Metaplex PDA, per-attempt breaker charge, negative highlight slot). **Note:** ThorchainFetcher is *real*, and HighlightsService:22's "all three stubs" is itself stale (2/3 live) → corrected in **CL-4D**. **Outcome:** none now. **Acceptance:** n/a. **Deps:** — · **Risk:** none · **Size:** medium (future) · **Tracker:** this item.

### ☐ CL-84 — Core admin / locals / wall V1 stubs — **DEFERRED**
- **Priority:** P3 · **Timing:** post-launch · **Type:** deferral · **Surface:** bcc-core
- **F-IDs:** F053, F054, F055, F056, F057 · **Unlock:** V2. **Current:** ApiKeys health probes, Locals meta marker, wall-of-other, Developer raw-data page — intentionally not in V1. **Outcome:** none now. **Acceptance:** n/a. **Deps:** — · **Risk:** none · **Size:** medium (future) · **Tracker:** this item.

### ☐ CL-85 — Frontend V1-deferred surfaces — **DEFERRED**
- **Priority:** P3 · **Timing:** post-launch · **Type:** deferral · **Surface:** bcc-frontend
- **F-IDs:** F037, F038, F040, F041, F042, F045, F046, F047, F049 · **Unlock:** named per comment (PR-3.1, Phase 2, Sprint-1). **Current:** accurate scope control (composer destination stub, chain pills, roster route, SSR-prefetch, alt-text, network tab). **Note:** F045's impact premise is wrong (streak *is* read by HighlightsService) → note in CL-4B. **Outcome:** none now. **Acceptance:** n/a. **Deps:** — · **Risk:** none · **Size:** varies · **Tracker:** this item.

### ☐ CL-86 — Comment / moderation V1.5 deferrals — **DEFERRED**
- **Priority:** P3 · **Timing:** post-launch · **Type:** deferral · **Surface:** bcc-trust
- **F-IDs:** F085, F226, F229 · **Unlock:** V1.5. **Current:** richer comment blend, multi-id read swap, elite-tier mod role. **Outcome:** none now. **Acceptance:** n/a. **Deps:** — · **Risk:** none · **Size:** medium (future) · **Tracker:** this item.

### ☐ CL-87 — F3 content search (design only) — **DEFERRED**
- **Priority:** P3 · **Timing:** post-launch · **Type:** deferral · **Surface:** bcc-search (future)
- **F-IDs:** F241 · **Unlock:** post-launch. **Current:** **NOT BUILT** — no content vertical on `main` (3 controllers = page search only, no visibility seam). Privacy **policy is now decided** (Gate 12 / CL-7C: **Option B — public_all wins**, 2026-07-22), but **enforcement is OPEN** — when built, the content vertical must **mirror the feed's `public_all` gate** (this supersedes the design doc's earlier "content search is stricter" note; see the 2026-07-22 banner in `content-search-privacy-design.md`). **Outcome:** none now (deferred feature). **Acceptance:** n/a until build; at build, verify the search gate mirrors the feed + all no-private-leak boundaries. **Deps:** CL-7C (policy) · **Risk:** none (unbuilt) · **Size:** large (future) · **Tracker:** content-search-privacy-design.md.

### ☐ CL-88 — Misc parked / dev-flag / test-skip deferrals — **DEFERRED**
- **Priority:** P3 · **Timing:** n/a · **Type:** deferral · **Surface:** umbrella + local + bcc-core
- **F-IDs:** F019, F020, F091, F093, F094, F286 · **Unlock:** various. **Current:** cadence PR-11b/c parked; closed-network protocol TBD; demo-seeder kill-switch; `BCC_HIGHLIGHTS_DEMO`/`BCC_REPAIR_ENABLED` dev flags; 7 crypto-ext test skips (never fire in CI). **Outcome:** none. **Acceptance:** n/a. **Deps:** — · **Risk:** none · **Size:** tiny · **Tracker:** this item.

---

## GROUP 9 — POST-LAUNCH TECHNICAL DEBT

### ☐ CL-90 — Dead-code clusters — **OPEN**
- **Priority:** P2 · **Timing:** post-launch · **Type:** code · **Surface:** bcc-trust + bcc-core
- **F-IDs:** F098 · **Location:** `includes/block-helpers.php`; `VoteService::{getPageVoteBreakdown,…}` dead methods.
- **Current:** 6 dead-code clusters re-verified dead. **Outcome:** delete with consumers (fresh-install policy). **Acceptance:** classes removed; suites green. **Deps:** launch · **Risk:** low · **Size:** medium · **Tracker:** TODO.md:30.

### ☐ CL-91 — Orphan event emitters — **OPEN**
- **Priority:** P3 · **Timing:** post-launch · **Type:** code · **Surface:** bcc-trust
- **F-IDs:** F099 · **Location:** `bcc_stoke_added/removed` etc. (no listeners). **Current:** 9 orphan emitters. **Outcome:** delete or wire. **Acceptance:** no orphan `do_action` remains. **Deps:** launch · **Risk:** low · **Size:** small · **Tracker:** TODO.md:31.

### ☐ CL-92 — bcc-search uninstall hygiene — **OPEN**
- **Priority:** P3 · **Timing:** post-launch · **Type:** code · **Surface:** bcc-search
- **F-IDs:** F060, F243 · **Location:** `uninstall.php:21-24`. **Current:** promises a `wp_bcc_search_terms` drop never written + 2 gen-counter options not cleaned. **Outcome:** add the guarded DROP + option deletes; remove/reword the dead `dropTable()`. **Acceptance:** uninstall removes all search artifacts. **Deps:** — · **Risk:** low · **Size:** tiny · **Tracker:** this item.

### ☐ CL-93 — bcc-trust dead-chrome + low-risk cleanup — **OPEN**
- **Priority:** P3 · **Timing:** post-launch · **Type:** code · **Surface:** bcc-trust (2 items contract-touching)
- **F-IDs:** F067, F071, F078, F224, F235 · **Location:** `assets/js/admin.js:28` (dead selectors); ENDORSE pref (`NotificationCatalog`); expired version-cache shim (`bcc-trust.php:100`); post-kind allowlist:189; VERB_BY_MODULE:79 (200-range keys).
- **Current:** dead admin.js selectors; ENDORSE pref removal needs a contract bump + FE union sync; expired shim; cadence-guard run needed for the verb map.
- **Outcome:** trim admin.js; remove ENDORSE pref (contract bump); delete the shim; add 200-range verb keys.
- **Acceptance:** no dead selectors; contract/FE in sync; cadence guard green. **Deps:** launch; F071 needs contract bump · **Risk:** low · **Size:** small · **Tracker:** this item.

### ☐ CL-94 — Frontend dead param — **OPEN**
- **Priority:** P3 · **Timing:** post-launch · **Type:** code · **Surface:** bcc-frontend
- **F-IDs:** F043 · **Location:** `src/hooks/useNotifications.ts:78` (`_options` unused + 4 call sites). **Current:** eslint-disabled dead param kept "for source-compat". **Outcome:** delete the param + update call sites. **Acceptance:** no unused param; tsc/eslint clean. **Deps:** — · **Risk:** low · **Size:** tiny · **Tracker:** this item.

### ☐ CL-95 — Media-CDN quota-glance follow-up — **OPEN**
- **Priority:** P3 · **Timing:** post-launch · **Type:** operator · **Surface:** Vercel dashboard
- **F-IDs:** F284 · **Location:** `docs/TODO.md:85` (media CDN shipped pre-launch; only the quota-glance follow-up is untracked). **Current:** one-time image-optimization quota check unrecorded. **Outcome:** glance the Vercel image-optimization quota post-launch. **Acceptance:** quota checked + recorded. **Deps:** launch · **Risk:** low · **Size:** tiny · **Tracker:** this item; media memory.

### ☐ CL-FN01 — **FN-01** · explicit-string module filters coerce to `0` — **OPEN (post-audit finding)**
- **Priority:** **P2 if a surface is live, else P3** · **Timing:** post-launch · **Type:** code · **Surface:** bcc-trust
- **F-IDs:** **FN-01** (post-audit finding; assigned its own stable ID; the original audit findings are not renumbered)
- **Location:** `bcc-trust BlogService.php:94` (`['blog']`); the signals scope (`['signal']`); `PeepSoActivityRepository::getActivities()` explicit branch
- **Current:** `act_module_id` is `smallint unsigned NOT NULL`; MySQL coerces `'blog'→0`, `'signal'→0`, so `IN('blog')` matches the **2 legacy module-0 rows**, not the **3 real module-204** blog rows. Pre-existing; **untouched by F058** (its explicit branch is byte-identical).
- **Outcome:** determine surface liveness, then fix (pass the numeric id — `getNumericId('blog')=204`; give signals a real numeric module) **or** remove the dead path.
- **Acceptance:** `/u/{handle}` blog tab + `/feed?scope=signals` liveness determined; fix-or-remove decision recorded; if fixed, a test proves `['blog']` returns module-204 rows.
- **Deps:** **Batch C** (liveness investigation) precedes any fix · **Risk:** low · **Size:** small · **Tracker:** this item · **Evidence:** EXPLAIN/coercion verified during PR #33 review (`'blog'+0=0`; `IN('blog')`→module 0).

*(FN-02 — delete/trash public-cache lag — has been **consolidated into CL-73** (the authoritative public-cache-invalidation item) per the 2026-07-22 direction; it is **not** a separate primary item, to avoid two competing root-problem entries. Its evidence is preserved on CL-73.)*

### ☐ CL-FN03 — **FN-03** · no `public_all→private` downgrade + no purge event — **DEFERRED (pre-emptive)**
- **Priority:** P3 · **Timing:** post-launch · **Type:** code · **Surface:** bcc-trust/bcc-core
- **F-IDs:** **FN-03**
- **Location:** `PeepSoStatusWriter.php:174` (the only `_bcc_post_visibility` write is at post creation).
- **Current:** there is **no** operation to change a post's visibility after creation, so no live downgrade exists today; live gates read the meta per request. If a downgrade is ever added, cached/edge public copies would lag with no targeted invalidation.
- **Outcome:** if/when a visibility-edit path is built, wire a cache purge / generation bump on downgrade (Option B point 9).
- **Acceptance:** any future downgrade op ships with a purge + test.
- **Deps:** — · **Risk:** low (absent today) · **Size:** small (future) · **Tracker:** this item · **Evidence:** 2026-07-22 trace (gap G2). **Do not implement now.**

### ☐ CL-FN04 — **FN-04** · public group-stream discovery + secret-group public preview — **OPEN (before-production impl)**
- **Priority:** P2 · **Timing:** before-production · **Type:** code (backend + frontend) · **Surface:** bcc-trust + bcc-core + bcc-frontend
- **F-IDs:** **FN-04** (subsumes Phillip's 2026-07-22 group-stream discovery policy — one item, not a competing duplicate)
- **Location:** `FeedHydrationPipeline.php:251-255` (group block = `{id,type,verification}` only); `GroupsService::resolveGroupAccess` (secret + non-member → 404); the group detail route.
- **Current behavior (verified 2026-07-22 trace):** open/closed non-member **teaser already shows `public_group` + `public_all`** (INNER-JOIN on `['public_group','public_all']`); **secret-group non-member = 404** → **no public secret-group preview**; members get the unrestricted group stream (subject to moderation/access gates); a public post's group block exposes only `{id,type,verification}` — **no** name/avatar/URL/join affordance.
- **Approved target (RESOLVED policy — Phillip 2026-07-22; implementation OPEN / NOT BUILT):** every group has a safe public-facing stream/preview; a `public_all` post must give a public viewer a safe path to identify + discover the originating community. **Required discovery outcome:** public group **name** · public **avatar/banner** (where approved) · public **description** · public verification/type badges · **safe public landing URL** · a `public_group`/`public_all` stream per the canonical matrix · a **join / request-to-join / follow** action appropriate to group config · sign-in prompt only when a protected action is attempted. **Must NOT expose:** member roster · private (`members_only`) posts · private comments · invitations · membership state · private group metadata.
- **Secret-group note:** the current **blanket 404** for secret-group non-members must eventually be **replaced/supplemented by a safe public-preview route** returning ONLY `public_all` posts + the safe discovery context — while protected member routes / private API responses **keep** returning 404/denial (do **not** weaken them to build discovery).
- **Canonical visibility matrix** (documented in `content-search-privacy-design.md` **Note C**): `members_only` = member stream only; `public_group` = member stream + non-member open/closed stream (**not** global/permalink/search); `public_all` = every stream incl. non-member **secret preview** + global feed/permalink/search.
- **Backend-first security (required when built):** visibility filtering in the **backend** (not FE hiding); unknown/absent/malformed visibility ⇒ `members_only`; a guessed activity id must not reveal a non-public post; group-preview queries use an explicit visibility allowlist keyed to viewer + group-privacy; public preview must **not** reuse a member-authorized response or cache entry (cache keys vary by public/member auth state; authorization-bearing responses never served from anon cache); `members_only` posts must not appear in counts / pagination / "load more" / trending / previews / empty-state visible to non-members; public comment counts follow the approved comment policy (no private-volume leak); public media belongs to a publicly-visible post; hide/delete removes content from **both** member + public streams; downgrade invalidates all caches (**CL-73**).
- **Required future tests:** anon open/closed/secret matrices; authed-non-member + active-member matrices for all group types; suspended/removed member; hidden/deleted exclusion; guessed permalink ids per visibility value; pagination/total-count privacy; cache isolation across anon/non-member/member/moderator/admin; `public_group` excluded from global feed + content search; `public_all` included in global + future search; `members_only` excluded from every public surface; unknown/missing visibility fails closed; join/request transitions to auth; secret-group private metadata + roster remain undiscoverable.
- **Acceptance:** a public viewer of a `public_all` post (incl. secret-group) gets the safe discovery context + join path with **zero** private-group data leak, **backend-enforced**, test matrix green — **or** the minimal `{id,type,verification}` is explicitly accepted for launch. **Do NOT mark DONE merely because `public_all` posts currently syndicate.**
- **Deps:** CL-7C (policy), CL-73 (cache), CL-FN06 (authz) · **Risk:** medium (privacy surface) · **Size:** large · **Tracker:** this item — authoritative for public group-discovery · **Evidence:** 2026-07-22 trace (gap G3) + Phillip's 2026-07-22 group-stream policy. **Behavioral implementation is separate from this PR-#77 doc update; do not implement here.**

### ☐ CL-FN05 — **FN-05** · composer public-visibility disclosure unverified — **OPEN (verify, post-audit)**
- **Priority:** P2 · **Timing:** before-production · **Type:** test · **Surface:** bcc-frontend composer
- **F-IDs:** **FN-05**
- **Location:** bcc-frontend composer visibility selector + confirmation UI — **not covered by the backend trace**.
- **Current:** Option B points 6/7 require the authoring UI to clearly disclose "This post will be visible publicly, including to people who are not members of this group," require an explicit selection/confirmation, show the selected visibility before publishing, and warn on editing a private post to `public_all`. Backend enforces explicit-choice + default-private (verified); the **frontend disclosure/confirmation UI is UNVERIFIED / UNKNOWN**.
- **Outcome:** verify the composer shows the public-visibility disclosure + explicit confirmation; implement only in a **future behavioral PR** if absent.
- **Acceptance:** composer disclosure + confirmation present (or a follow-up PR opened); documented.
- **Deps:** CL-7C · **Risk:** medium (user awareness) · **Size:** small (verify) · **Tracker:** this item · **Evidence:** Option B policy point 6/7; **not** covered by the 2026-07-22 backend trace (UNKNOWN). **Verify only; do not implement here.**

### ☐ CL-FN06 — **FN-06** · `public_all` authorization is owner/moderator-controlled — **OPEN (before-production impl)**
- **Priority:** P2 · **Timing:** before-production · **Type:** code (backend + frontend) + test · **Surface:** bcc-trust + bcc-frontend
- **F-IDs:** **FN-06** (Phillip's 2026-07-22 authorization decision — resolved policy, OPEN implementation)
- **Location:** `PostsService::gateGroupPost` (`:1902-1928`), `normalizeVisibility` (`:194-199`), `PostsEndpoint` visibility enum; a group-level "who may post `public_all`" setting (**does not exist yet**).
- **Current behavior (verified 2026-07-22 trace):** **non-members cannot post at all** (membership required — `gateGroupPost`/`resolveGroupAccess`); and **any active member authorized to post may currently select `public_all`** for their own post — there is no owner/moderator-level restriction on who may choose `public_all`.
- **Approved target policy (RESOLVED — Phillip 2026-07-22; implementation NOT BUILT):** group **owners/admins control** whether ordinary members may mark their own posts `public_all`. **Closed/secret groups default to owners + moderators only.** A group owner/admin **may enable** `public_all` for all authorized posting members. Open-group behavior must be **explicitly defined** and regression-tested.
- **Required backend enforcement:** a **direct REST request cannot bypass** the group-level permission (server is the boundary — the FE gate is not sufficient).
- **Required frontend behavior:** **hide or disable** the `public_all` option for an ineligible author, and explain why.
- **Required tests:** anonymous denied · non-member denied · suspended member denied · ordinary member denied under the default closed/secret policy · moderator allowed · owner/admin allowed · ordinary member allowed **after** the group setting is enabled · open-group behavior explicitly defined + regression-tested · direct-API bypass denied · unknown/missing setting **fails to the restrictive default**.
- **Outcome:** implement the group-level `public_all` permission with backend enforcement + FE eligibility gating + the test matrix.
- **Acceptance:** the test matrix above is green; a direct API call by an ineligible author is denied; unknown/missing setting defaults restrictive. **This is approved policy — an OPEN before-production implementation item, not a remaining Phillip decision.**
- **Deps:** CL-7C (policy) · **Risk:** medium (authorization) · **Size:** medium · **Tracker:** this item · **Evidence:** Phillip's 2026-07-22 authorization decision + 2026-07-22 trace (current = any member may set `public_all`). **Do not implement here.**

---

## GROUP 10 — CLOSED / SHIPPED / REFUTED (not active backlog)

### ☑ CL-REF — Refuted findings — **REFUTED**
- **Priority:** P3 · **Timing:** n/a · **Type:** — · **Surface:** —
- **F-IDs:** F012, F216
- **Current:** two discovery findings disproved during adversarial verification (below); no repo work.
- **F012** — claimed the sponsorship deferral is untracked; **false** — tracked at `v2-roadmap.md:113` (via umbrella #76). Disproved by F183.
- **F216** — claimed CLAUDE.md's `wallet-case-preservation-check.php` label is stale; **false** — the wording mirrors the script's own header (ordinary-prose false positive).
- **Outcome:** none (recorded for history). **Acceptance:** n/a. **Deps:** — · **Risk:** none · **Size:** — · **Tracker:** this item · **Evidence:** verification workflow verdicts (REFUTED).

### ☑ CL-EXC — Excluded non-finding — **EXCLUDED**
- **Priority:** — · **Timing:** n/a · **Type:** — · **Surface:** —
- **F-IDs:** F178
- **Current:** a discovery hit pointing at the audit's own **memory file** (`…/memory/project_admin_audit_2026_07_21.md`), flagging it stale. Self-referential; excluded from verification (no verdict). The memory system maintains that file.
- **Outcome:** none (no repo action). **Acceptance:** n/a. **Deps:** — · **Risk:** none · **Size:** — · **Tracker:** this item · **Evidence:** excluded from the 33 verification batches by design.

**Shipped work whose records were stale** (code shipped; the *records* are cleaned in Group 4 —
this is documentation cleanup, not backlog): admin-audit fixes (fe#51/#53, trust#98/#99, core#32) →
CL-50; observability items (2026-07-10) → CL-47; probe workflow armed → CL-45; Injective NFT → CL-48;
search hygiene → CL-49/CL-4F.

---

## EXECUTION BATCHES (dependency-ordered)

| Batch | Objective | CL items | Repo order | Depends on | Tests/verification | Risk | Phillip gate | Definition of done |
|---|---|---|---|---|---|---|---|---|
| **A** | F058 — DONE | CL-01 | bcc-core | — | done | — | — | ✅ merged f9553f6; CL-02 staging DM probe still open |
| **B** | Correct PR #77 before merge | CL-41 (+ contract line of CL-40, note CL-46) | umbrella | A | docs render; guard scripts | low | review PR #77 | gates 8/11/12/4 + throttle note fixed; PR #77 + fe#50 merged |
| **C** | Investigate FN-01 live surfaces | CL-FN01 | bcc-trust (read-only) | — | surface-liveness probe | low | classify P2/P3 | liveness determined; fix-or-remove decided |
| **D** | Moderation launch hardening | CL-10 | bcc-core→bcc-trust | CL-71, CL-73 | PHPUnit + admin permalink probe | medium | CL-71, CL-73 | hidden preview works; audit log emits; purge decision applied |
| **E** | Small correctness/security fixes | CL-11 | bcc-trust; bcc-frontend (fe#50) | — | unit tests; contract guard | low | — | assigned_at set; inventory complete; fe#50 merged |
| **F** | CI + schema guardrails | CL-60, CL-61, CL-62 (CL-63 no-op) | umbrella; bcc-trust; bcc-core | — | guard exits 0; golden green; CI | low-med | — | schema guard armed; fixtures green; CI extensions declared |
| **G** | Contract + registry reconciliation | CL-40(docs), CL-42, CL-43, CL-44, CL-45, CL-46, CL-47, CL-48, CL-4F | umbrella (after PR #77) | B | doc render; contract-parity/subsystem guards | low | — | docs match code; guards green |
| **H** | Repo-specific stale-doc cleanup | CL-49, CL-4A, CL-4B, CL-4C, CL-4D, CL-4E, CL-50 | each repo | — | php -l / tsc where touched | low | — | comments match shipped code; no behavior change |
| **I** | Infrastructure under version control | CL-64 | umbrella + local | — | hook fires; mu-plugin loads | low | — | color hook + mu-plugins committed; smoke harness init'd; leak rotated |
| **J** | Post-launch dead-code removal | CL-90–95 + deferrals CL-80–88 (no-build) | per repo | launch | full suites per repo | low-med | fresh-install delete policy | dead code deleted with consumers; deferrals left DEFERRED |
| **K** | Production operator actions | CL-30–38 + CL-7B | operator/external | **Phillip authorizes prod**; CL-34 after CL-30 | probes/health per item | med | **PROD AUTH REQUIRED** | all §A/§B gates green; monitor + backups + secrets live |

---

## RECONCILIATION

- **Original audit rows (findings-final.json):** **291** (F001–F291), 291 unique IDs, no gaps/dupes.
- **Verdicts:** CONFIRMED 241 · ADJUSTED 47 · REFUTED 2 = 290; **+1 no-verdict** (F178, excluded non-finding).
- **Unique substantiated findings:** **288** (291 − 2 refuted − 1 excluded).
- **Refuted:** 2 → F012, F216 (CL-REF). **Excluded non-finding:** 1 → F178 (CL-EXC).
- **Additional post-audit findings:** 6 → **FN-01** (CL-FN01, explicit-string module coercion) + **FN-02..FN-06** from the 2026-07-22 Option-B trace/decision: **FN-02** = public-cache-invalidation evidence, **consolidated into CL-73** (F234) so it is not a competing primary; **FN-03** (CL-FN03, downgrade purge); **FN-04** (CL-FN04, group-discovery/secret-preview); **FN-05** (CL-FN05, composer disclosure); **FN-06** (CL-FN06, `public_all` authorization). Each has its own stable `FN-##` ID; the original F001–F291 are not renumbered.

**Counts by priority — source (dataset) vs operational:**

| | P0 | P1 | P2 | P3 | total |
|---|---|---|---|---|---|
| Source (findings-final.json `final_priority`) | 0 | 29 | 74 | 185 | 288 |
| Operational (F058 P2→P1) | 0 | **30** | **73** | 185 | 288 |

**Counts by classification (288):** STALE 145 · INTENTIONAL_DEFERRAL 66 · ACTIONABLE 55 · NEEDS_DECISION 16 · RISKY_SECURITY 6. *(Operational view moves F058 from ACTIONABLE to a privacy/RISKY posture: ACTIONABLE 54 · RISKY_SECURITY 7 — the one-row shift mirroring the priority reclassification.)*

**Counts by timing (288):** before-staging 14 · before-production 88 · after-launch 84 · n/a 102.

**Counts by status (70 primary items):** OPEN 35 · BLOCKED 9 · DECISION 11 · DEFERRED 11 · DONE 1 · RESOLVED 1 · REFUTED 1 · EXCLUDED 1. → **Active 55 · Deferred 11 · Done/Resolved/Refuted/Excluded 4.** *(vs the 65-item merge: +CL-7C RESOLVED decision + CL-FN03/04/05/06 (FN-02 consolidated into CL-73); CL-73 & CL-FN04 moved decision→OPEN before-prod; validated by the checklist validator, PASS.)*

**Counts by type (primary items, approximate — mixed-type items counted by lead type):** code 16 · docs 20 · operator 6 · external 4 · test 4 · decision 12 · deferral 8. *(= 70; decision = 11 OPEN-status decisions CL-70…CL-7B-minus-CL-73 + CL-7C RESOLVED-decision; CL-73/CL-FN04/CL-FN06 count as code.)*

**Counts by execution batch (CL items):** A 1 · B 2 (adds CL-7C — Gate-12 decision recorded in the PR-#77 docs) · C 1 · D 1 · E 1 · F 4 · G 9 · H 7 · I 1 · J 16 (6 debt + 9 deferral + FN-03) · K 10; plus the **before-production Option-B implementation** items **CL-FN04** (group-discovery/secret-preview), **CL-FN06** (`public_all` authz), **CL-73** (public-cache invalidation), and **CL-FN05** (composer-disclosure verify) — behavioral work that sits alongside Batches D/E, tracked but **not** implemented in this PR.

**Machine-checkable mapping:** every F-ID F001–F291 appears **exactly once** as a primary or
attached/related ID across CL-01…CL-EXC (validated: 291/291 covered, 0 duplicates, 0 missing,
0 unknown; +FN-01..FN-05). The per-CL F-ID lists above are the authoritative assignment.

**Discrepancies found:** one, fully explained — the **29/74 (dataset) vs 30/73 (operational)** P1/P2
split is entirely F058's source→operational reclassification (P2→P1). The dataset is preserved
unchanged. No other discrepancy between the dataset and the audit's reported totals.

---

## MAINTENANCE RULE

Future PRs that touch any item here **must**:
1. Reference the **checklist ID** (e.g. `CL-43`) and its related **F-IDs** in the PR description.
2. Update `Status` **only after merge/verification** — never on open.
3. Attach the **PR number + merge SHA** (and, for operator items, the verification evidence — probe output, dashboard link, or run URL).
4. Record the **testing or operator evidence** satisfying the item's acceptance criteria.
5. **Recalculate the summary counts** in the Reconciliation section (source and operational).
6. **Never delete history** — move a completed item to `DONE`/`REFUTED` in place; do not remove it. New post-audit findings get a fresh `FN-##` ID and never renumber F001–F291.
