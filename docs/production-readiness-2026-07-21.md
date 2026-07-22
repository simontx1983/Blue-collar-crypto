# Production-readiness walk — 2026-07-21 (read-only)

**Status of the platform:** operating on **STAGING**. This walk does **not**
authorize a production release. Every gate separates STAGING from PRODUCTION;
"UNKNOWN" means the live prod/Vercel/host state cannot be read from the repo and
needs an operator to confirm in the named system.

**Method:** read-only evidence gathering (repo, docs, `gh api`, live DB reads).
No files in bcc-core/trust/search/frontend modified; no deploy; no secrets
touched; no load tests.

Verdict fields per gate: **Required · Current · Evidence · PASS/FAIL/UNKNOWN ·
Remediation · Touches**.

> **⚠ Amendment (2026-07-22) — this is a point-in-time snapshot, not a live checklist.**
> This walk recorded GitHub/branch state as of **2026-07-21**. Several facts have
> since changed; they are **corrected inline below, each prefixed
> `↻ Corrected 2026-07-22`**. The **ongoing execution tracker** is
> [`docs/audit-remediation-checklist-2026-07-21.md`](audit-remediation-checklist-2026-07-21.md) —
> this document remains a historical readiness snapshot, not a competing checklist.
>
> **Read every gate through three distinct states that the word "fixed" conflates:**
> **(a) code merged to `main`** · **(b) staging deployed & verified** ·
> **(c) production provisioned / deployed / verified**. A merged fix is **not** on
> production until (c) is done and evidenced. This snapshot establishes only
> (a)/(b) plus repo/GitHub facts — it never establishes (c), and "code merged"
> must never be read as "production updated."

---

## Gate 1 — Production `ttl_rest = 60`
- **Required:** prod LiteSpeed `litespeed.conf.cache-ttl_rest = 60` (anon/edge REST expires in 60s, not the 1-week default).
- **Current:** STAGING = 60, re-verified live 2026-07-19. PRODUCTION = still 604800 default, not applied (frozen).
- **Evidence:** `docs/TODO.md:57`; `docs/performance-review-2026-07-19.md:335,379`; root cause `docs/capacity-model.md:378,639-643`.
- **Verdict:** STAGING **PASS** · PRODUCTION **UNKNOWN/OPEN**.
- **Remediation:** on prod LiteSpeed box `wp option update litespeed.conf.cache-ttl_rest 60`, purge edge once, re-read to confirm.
- **Touches:** PRODUCTION (LSCWP config).

## Gate 2 — External `/system/ping` monitoring + alert delivery
- **Required:** external uptime monitor hits a prod health endpoint and delivers alerts.
- **Current:** endpoint exists in code (`GET /wp-json/bcc/v1/system/ping`, public, 5 liveness checks, 200/503, 30s cache). No external monitor confirmed wired; audit says "nothing watches it."
- **Evidence:** `bcc-core/bcc-core.php:886-984` (endpoint), `:878-883` (monitor intent); NOT wired: `docs/performance-review-2026-07-19.md:179,194,221,339`; runbook assumes one `docs/operator-runbook.md:14` (older, superseded).
- **Verdict:** endpoint **PASS** · external monitor+alerting **FAIL/UNKNOWN**.
- **Remediation:** point Better Stack/UptimeRobot/Pingdom at prod `/system/ping`, alert on 503/non-ok, wire a channel; set `BCC_DEGRADATION_ALERT_EMAIL` (+webhook) in prod wp-config for the in-app `DegradationAlerter`.
- **Touches:** EXTERNAL SERVICES + PRODUCTION (wp-config). No code change.

## Gate 3 — §7 production secrets & OAuth
- **Required:** every fail-closed secret set in prod wp-config; every Vercel env var set; X OAuth callback registered.
- **Current:** full required set enumerable from code/docs; **no prod values observable from repo** → each UNKNOWN. Two items explicitly OPEN/MANUAL by the project's own docs.
- **Evidence:** WP side `docs/environment.md:74-79` + reads (`BCC_ENCRYPTION_KEY` missing ⇒ whole trust plugin 403s `bcc-trust.php:150`; `BCC_OAUTH_BRIDGE_SECRET` fail-closed `OAuthController.php:159-160`; `BCC_INTERNAL_CRON/VERIFY_SECRET`, `BCC_HELIUS_WEBHOOK_SECRET`, VAPID trio). Frontend `bcc-frontend/src/lib/env.ts:31-98` (all `required()`). OPEN manual: `docs/testnet-deploy-checklist.md:273,343-346` (OAuth bridge secret both ends; X portal callback; rotate burned secrets).
- **Verdict:** enumeration **PASS** · per-secret prod state **UNKNOWN** · two OAuth items **FAIL/OPEN**.
- **Doc gap:** `env.ts` hard-requires `BCC_INTERNAL_CRON_SECRET` (:62) and `CRON_SECRET` (:73) but neither is in `.env.local.example` nor the checklist §4 Vercel table → prod deploy missing either throws in the cron relay route.
- **Remediation:** operator confirm every wp-config secret + Vercel twin is set (esp. fail-closed set); close the two OAuth manual items; add the two cron vars to example + checklist.
- **Touches:** PRODUCTION (wp-config) + EXTERNAL SERVICES (Vercel, X portal) + DOCUMENTATION.

## Gate 4 — CDN / cache posture, esp. Authorization-header isolation
- **Required:** cache never serves an anon-cached REST body to an `Authorization`-scoped request; a repeatable probe backs it.
- **Current:** STAGING — `.htaccess` AUTH CACHE BYPASS installed + probe green. PRODUCTION — `.htaccess` block documented-installed (2026-07-13) but **no prod probe** (script refuses prod hostname) and **prod has NO persistent object cache** (`lib/object-cache.php` missing). Weekly staging probe workflow is **committed and ACTIVE** on GitHub (verified 2026-07-21 via `gh workflow list` → `staging-cache-probe active`; committed in `e5d25b6`, 2026-07-19) — its own header comment still says "intentionally uncommitted" and is **stale**.
- **Evidence:** `docs/TODO.md:62-69` (.htaccess both envs), `:58` (prod object-cache MISSING); `scripts/auth-cache-isolation-probe.sh:22-48` (staging-only guard); `.github/workflows/staging-cache-probe.yml:9-17` (prepared, not active); spec `docs/testnet-deploy-checklist.md:165-175`.
- **Verdict:** STAGING isolation **PASS** · weekly CI automation **PASS** (armed/active on GitHub; only the workflow header comment is stale) · PRODUCTION isolation **UNKNOWN** · prod object cache **FAIL**.
- **Remediation:** *↻ Corrected 2026-07-22: the weekly staging cache-probe workflow is already committed + armed (`e5d25b6`); there is **no "commit to arm" action left** — only its stale in-file "intentionally uncommitted" header comment needs a trivial fix (tracked in the master checklist).* Provision prod persistent object cache (restore `lib/object-cache.php`, gate `BCC_REDIS_ENABLED`, avoid the `WP_REDIS_TIMEOUT=1` P0 trap); after prod authorized, verify the bypass block is live and run the isolation sequence against prod.
- **Touches:** STAGING + PRODUCTION + EXTERNAL SERVICES (GitHub Actions).
- **Note (functional) — *↻ Corrected 2026-07-22*:** without a persistent object cache, throttling does **not** simply "fail closed." `Throttle::isReady()` (`bcc-core/src/Security/Throttle.php:176`) is an **OR-gate** — `class_exists('\BCC\Trust\Core\Security\RateLimiter') || wp_using_ext_object_cache()` — so with bcc-trust active (the shipped topology) it falls back to bcc-trust's **`wp_options`-backed `RateLimiter`**: slower but **functional**. Deny-all applies **only** if that fallback is unavailable (bcc-trust deactivated/absent) **or** its DB write fails. So the prod object cache is a **performance/scale + cache-consistency** prerequisite, **not** a rate-limiting-functionality one.

## Gate 5 — Production env vars & endpoint origins
- **Required:** prod frontend `NEXT_PUBLIC_BCC_API_URL` → prod WP origin; `NEXTAUTH_URL` → real prod frontend domain; never staging/local.
- **Current:** config plumbing correct + centralized; prod values live only in Vercel → UNKNOWN from repo; no repo artifact pins prod to a non-prod origin (only dev placeholders in gitignored example).
- **Evidence:** single resolve site `bcc-frontend/src/lib/env.ts:30-32,45`; `bcc-frontend/.env.local.example:13,16` dev placeholders; `bcc-frontend/vercel.json` cron-only; checklist `docs/testnet-deploy-checklist.md:265-274`. Real prod domain `bcc-frontend-rho.vercel.app` (memory) not in repo.
- **Verdict:** **UNKNOWN** (no misconfig in repo; live values operator-only).
- **Remediation:** in Vercel (Production scope) confirm `NEXT_PUBLIC_BCC_API_URL` = prod WP origin and `NEXTAUTH_URL` = `https://bcc-frontend-rho.vercel.app`; confirm prod WP `BCC_FRONTEND_ORIGIN` allowlist contains that exact origin.
- **Touches:** PRODUCTION (Vercel env + prod wp-config). Verification only.

## Gate 6 — Database migration / backfill readiness
- **Required:** all schema installs idempotent on activation/init (no manual DB step); no launch-blocking backfill.
- **Current:** **PASS.** Every path is option-guarded + advisory-locked + verify-after-write: bcc-trust schema-version gate (`bcc-trust.php:362-403`, `tables.php:65,323`), FT-index self-heal (`SearchRepository.php:148,204-220`), search-terms table (`SearchTermsRepository.php:75,118-128`), rate limiter needs no dedicated table (*↻ Corrected 2026-07-22:* bcc-trust's `RateLimiter` is **`wp_options`-backed**, not object-cache-backed — see Gate 4). Deferred backfills (NFT 1155 depth, dispute-signing writer) are post-launch and moot on an empty prod DB.
- **Evidence:** cited files above; `docs/TODO.md:34` (NFT backfill), `docs/admin-audit-2026-07-21.md:143-146` (dispute writer read-only); `scripts/schema-drift-guard.php:1-49` (informational, not CI-armed).
- **Verdict:** **PASS** (with the object-cache prerequisite from Gate 4).
- **Remediation:** none for idempotency; ensure prod object cache live before launch.
- **Touches:** PRODUCTION (object cache) + DOCUMENTATION. No code change.

## Gate 7 — Deployment & rollback procedure
- **Required:** documented env-separated pipeline (auto-staging / manual-prod) + written rollback.
- **Current:** **PASS.** Identical `deploy.yml` in all three plugins: staging auto on CI-green `workflow_run` push to main; prod = `workflow_dispatch` manual with environment choice; plugin-scoped `rsync --delete` over SSH; version-header confirm. Rollback documented (Vercel promote; backend re-deploy last-good SHA; DB restore from pre-deploy dump; drill validated 2026-06-25).
- **Evidence:** `.../bcc-*/.github/workflows/deploy.yml:16-26,39-42,53-61,80-94`; `docs/deploy-runbook.md:57-72`; `docs/rollback-procedure.md` §4a/4b/4c, §6.
- **Verdict:** **PASS**, with one gap ↓.
- **Gap (highest operational risk):** `docs/rollback-procedure.md:17-22` — **no automated/offsite DB backup**; the pre-deploy `mysqldump` is the *only* restore point.
- **Remediation:** establish a scheduled daily offsite DB dump before real users.
- **Touches:** PRODUCTION + EXTERNAL SERVICES (host/backup) + DOCUMENTATION.

## Gate 8 — CI & branch protection across all repos
- **Required:** CI on each repo; `main` protected requiring those checks.
- **Current — *↻ Corrected 2026-07-22*:** CI green everywhere, **zero open code PRs**; **all five repos' `main` branches are now protected** (bcc-search protection was armed after the 2026-07-21 snapshot). No repo requires PR review (deliberate policy).
- **Evidence (`gh api …/branches/main/protection`, re-verified 2026-07-22, auth scopes `repo`):**
  - Blue-collar-crypto (umbrella): protected, "Cross-repo guards", enforce_admins ✓
  - bcc-core: protected, "PHP — PHPStan L8 · PHPUnit", enforce_admins ✓
  - **bcc-search: protected, "PHP — syntax · PHPStan L8 · PHPUnit", enforce_admins ✓** *(was "404 Branch not protected" on 2026-07-21; armed since)*
  - bcc-trust: protected, "PHPStan L8 · PHPUnit · guardrails" + "PHP integration (MySQL)", enforce_admins ✓
  - bcc-frontend: protected, "tsc · lint · vitest", enforce_admins ✓
- **Verdict:** all five repos **PASS** · PR-review requirement **FAIL (policy, all repos — intentional for a 2-engineer shop unless changed)**.
- **Remediation:** none for branch protection (**bcc-search resolved 2026-07-22**). Optionally confirm whether "no required PR review" stays intentional.
- **Touches:** GitHub settings only. No code/staging/prod. **(bcc-search protection: DONE 2026-07-22.)**

## Gate 9 — Health metrics & logs immediately post-deploy
- **Required:** on deploy, operators can immediately watch health + errors.
- **Current:** **PASS (code).** Public `/system/ping`; admin `/system/health` (folds the canonical DegradationMetrics subsystem map) + `/bcc-trust/v1/health/read-model`; wp-admin "BCC System → Health" page; `DegradationAlerter` (email/webhook on sustained-degraded transition, de-duped) *if constants set*; `Logger`; Vercel logs frontend-side.
- **Evidence:** `bcc-core/bcc-core.php:434-545` (map), `:116-119` (admin page), `DegradationAlerter.php:218-264`; `ReadModelHealthEndpoint.php:88-124`.
- **Verdict:** visibility **PASS** · automatic paging depends on prod `BCC_DEGRADATION_ALERT_EMAIL`/webhook (ties to Gate 2).
- **Remediation:** set alert constants in prod wp-config; confirm `bcc_core_degradation_alert_check` cron scheduled; confirm Vercel log access. (Noted gap: no CPU/error-rate alert.)
- **Touches:** PRODUCTION (wp-config, cron) + EXTERNAL SERVICES (Vercel, webhook).

## Gate 10 — Smoke coverage: anonymous AND authenticated
- **Required:** documented smoke coverage for both anon and authed critical paths.
- **Current:** **PARTIAL.** `docs/v1-smoke-test-checklist.md` covers both (anon §1; authed §2-§11; quality §12; backend health §13) but is a **manual one-shot** checklist targeting **local**, not an automated regime, with no recorded green run against current `main` on staging/prod. Notification smokes need HTTP (PeepSo doesn't load under wp-cli).
- **Evidence:** `docs/v1-smoke-test-checklist.md:11-13,24-27,62-98,99-226,437-471`.
- **Verdict:** **PARTIAL/PASS-with-caveats.**
- **Remediation:** execute end-to-end against STAGING over HTTP (esp. §8/§11 notifications via live app), record pass/fail+date; re-run against prod post-deploy; optionally promote `~/bcc-smoke` Playwright harness to a repeatable run.
- **Touches:** DOCUMENTATION (execution record) + STAGING/PRODUCTION (running it). No code.

## Gate 11 — Remaining launch blockers from earlier audits
- **Required:** no open audit item that is a genuine prod blocker remains unaddressed; anything anon/first-user-facing must be fixed.
- **Current — *↻ Corrected 2026-07-22* (this gate's 2026-07-21 text was stale-on-arrival):** the admin-audit fixes **are now on `main`**. Every PR the snapshot listed as "open/unmerged" **merged 2026-07-21**, plus follow-ups. **Code portion: RESOLVED** (verified on the default branches). What remains is the **production deploy** of that merged code — an operator step, distinct from the merge.
- **Evidence (merged, verified 2026-07-22 via `gh pr view` + `git grep` on `origin/main`):** **trust#98** MERGED (gen-counter tolerance — `HiddenActivityRepository.php:202` now reads `if (is_numeric($gen))`, not `is_int`), **core#32** MERGED (non-open-groups gen-counter string-cache), **fe#51** MERGED (moderation nav + target links + honest errors + `/admin` middleware + undo timing), **umbrella#76** MERGED (admin-audit report + sponsorship deferral). Follow-ups also MERGED: **trust#99** (dead fraud/stats endpoint cleanup) and **fe#53** (`ViewerMenu` deletion) — so the snapshot's "only follow-up NOT yet started is dead-endpoint cleanup + ViewerMenu deletion" is **also done**.
- **Deploy portion (still open — corrected):** the snapshot's "prod runs bcc-trust **1.1.0** vs staging **1.2.26**; months of fixes missing" is **stale/incorrect**. A manual prod deploy (`workflow_dispatch`) ran **2026-07-21**, and `bcc-trust` `main` is now **1.2.30**. The **live prod version and its SHA-parity with current `main` are operator-verifiable, not repo-observable → UNKNOWN here.** Deploying the current plugin set to prod at SHA-parity remains a deliberate operator step (§A2) behind the prod freeze. Remaining prod §A items (`performance-review-2026-07-19.md:215-224`): A1 `ttl_rest`, A2 prod plugin deploy, A3 monitor, A4 auth-cache guard, A5 CDN decision, A6 security manual items.
- **Verdict:** admin P1/P2 fixes **MERGED / on `main`** (code **RESOLVED**) · **staging** carries them (auto-deployed on merge) · **production** deploy-to-SHA-parity **OPEN/UNKNOWN** (operator) · prod §A items **OPEN/UNKNOWN**.
- **Anon-facing note:** the gen-counter bug (admin Hide not propagating to the anon hot feed under a string-returning object cache) is **fixed on `main`/staging** via trust#98 + core#32. Whether it ever manifested on staging depended on the LSMCD int-type round-trip (unverified), but the tolerant `is_numeric` read now covers it regardless.
- **Remediation:** code — none (merged). Production — deploy the current merged plugin set to prod at SHA-parity + execute prod §A, during the authorized (frozen) prod phase.
- **Touches:** code → already on STAGING (auto-deployed on merge); prod deploy + §A → PRODUCTION + EXTERNAL SERVICES.

## Gate 12 (added) — Content-search product policy: `public_all` in a secret group
- **Required:** platform intent for whether a `public_all` post inside a **secret** group syndicates globally must be a *decision*, not an accident — it is **live feed behavior today**, and it blocks F3.
- **Current:** **AMBIGUOUS.** Behavior proven live (global gate is group-privacy-independent, `PeepSoActivityRepository.php:273`; old group-exclusion inert); unblocked upstream (a secret-group member can pick `public_all` — `PostsService.php:1902-1928,194-199`, `PeepSoStatusWriter.php:174`, all composer routes); intent undocumented, and the one doc that speaks to it (`api-contract-v1.md:2082-2084`) asserts the **opposite** and is **stale**.
- **Verdict:** **DECISION REQUIRED** (recorded as a build-blocking decision in `docs/content-search-privacy-design.md` Note B; option A "mirror feed" vs B "search is stricter").
- **Remediation:** product decides A or B and records it; separately correct the stale `api-contract-v1.md:2082-2084` text (doc-drift bug).
- **Touches:** DOCUMENTATION (+ CODE only if option B is chosen, later, inside F3). No prod action.
- ***↻ Note 2026-07-22 — this gate is CORRECT as written; do not mark it resolved.*** The A/B intent is **genuinely unresolved**: `content-search-privacy-design.md` Note B (verified 2026-07-22) explicitly calls `secret × public_all` an "**explicit BUILD-BLOCKING DECISION (do not choose it silently)**" with intent "**unresolved**" — no Option-B (or A) decision is recorded anywhere in this PR. F3 content search is **design-only / not built** on `main`, so this decision blocks the **future F3 build**, not the V1 launch. *(The separately-tracked stale `api-contract-v1.md:2082-2084` text is corrected by this same PR.)*

---

## GO / NO-GO

**NO-GO for production today** — and that is the expected state, not a regression.
The **code** is essentially launch-ready (migrations self-install, the earlier
audit P1s including the one anon-facing bug are fixed, CI is green, staging is
current and healthy). What is not done is **production-environment provisioning**
plus a few **operator/security/ops** items and one **product decision** — almost
all of which are deliberately gated behind your production authorization.

## Blockers, ordered by risk

1. **No automated offsite DB backup** (Gate 7) — highest operational risk; only restore point is a manual pre-deploy dump. *Fix before real users.*
2. **Prod secrets unconfirmed / fail-closed** (Gate 3) — `BCC_ENCRYPTION_KEY` missing ⇒ whole trust plugin 403s; `BCC_OAUTH_BRIDGE_SECRET` both-ends + X callback ⇒ SSO fail-closed; rotate burned secrets.
3. **Prod has no persistent object cache** (Gate 4) — *↻ Corrected 2026-07-22:* throttling stays **functional** via bcc-trust's `wp_options`-backed `RateLimiter` (see Gate 4); this is a **performance/scale + cache-consistency** prerequisite, **not** a rate-limiting-functionality one (still avoid the `WP_REDIS_TIMEOUT=1` P0 trap).
4. **Prod plugin deploy** (Gate 11/A2) — *↻ Corrected 2026-07-22:* deploy the current merged plugin set to prod at SHA-parity. *(The old "1.1.0 vs 1.2.26 / months missing" figures are stale — a manual prod deploy ran 2026-07-21; `main` is now 1.2.30; the live prod SHA is operator-verifiable/UNKNOWN.)*
5. **Prod `cache-ttl_rest=60`** (Gate 1) — else anon REST stale up to a week.
6. **Prod auth-cache isolation confidence** (Gate 4) — verify bypass block live + run the probe against prod.
7. **External uptime monitor + alerts** (Gate 2) — nothing watches prod health today.
8. ~~**bcc-search `main` unprotected** (Gate 8)~~ — *↻ RESOLVED 2026-07-22:* bcc-search `main` branch protection is armed (enforce_admins + "PHP — syntax · PHPStan L8 · PHPUnit").
9. **Content-search `public_all`-in-secret-group policy** (Gate 12) — decide A/B; it's live feed behavior. *(Decision now; no prod action.)*
10. **Full anon+authed smoke against staging** (Gate 10) — no recorded green run vs current `main`.
11. **Doc fixes** — cron vars into checklist §4 (this PR) + `.env.local.example` (fe#52); correct stale `api-contract-v1.md:2082-2084` (this PR). *↻ 2026-07-22: "commit admin-audit report" is **done** — merged via umbrella#76.*

## Minimal clearing sequence

**Phase 0 — safe NOW (staging / repo / GitHub; no prod authorization needed):**
- ~~Enable bcc-search `main` branch protection~~ — *DONE 2026-07-22* (armed: enforce_admins + "PHP — syntax · PHPStan L8 · PHPUnit").
- Decide the `public_all`-in-secret-group policy (A/B) and record it (still open — blocks the future F3 build, not V1).
- Doc fixes: add `BCC_INTERNAL_CRON_SECRET` + `CRON_SECRET` to `.env.local.example` (fe#52) and checklist §4 (this PR); correct `api-contract-v1.md:2082-2084` (this PR). *(The admin-audit report is already committed — umbrella#76.)*
- Run the full v1 smoke checklist against **staging** over HTTP (anon + authed, incl. §8/§11 notifications); record results.
- (Weekly staging auth-cache probe is already armed/active — only its stale "intentionally uncommitted" header comment needs a trivial fix.)
- Stand up an automated offsite DB backup for the prod DB (provisioning; no app change).

**Phase 1 — requires your EXPLICIT production authorization:**
- Provision prod persistent object cache (§1.5).
- Set/rotate all prod wp-config secrets + Vercel env (fail-closed set; OAuth bridge secret both ends; X callback).
- Deploy the current plugin set to prod (A2).
- Apply prod `cache-ttl_rest=60`; verify AUTH CACHE BYPASS; run the isolation probe against prod (A1/A4).
- Configure external uptime monitor + alert channel; set `BCC_DEGRADATION_ALERT_EMAIL` (A3).
- Run the §5 post-deploy health gates + full smoke against prod.

## The exact authorization point

Everything in **Phase 0** is safe to do now. **Your explicit production
authorization is required at the boundary into Phase 1 — the first action that
mutates production or its external config:** provisioning the prod object cache,
setting/rotating prod secrets, deploying plugins to prod, changing prod LiteSpeed
`cache-ttl_rest`, editing prod `.htaccess`, or pointing a monitor at prod. None of
those may proceed without your go-ahead. **This walk does not authorize any of
them; we remain on staging.**
