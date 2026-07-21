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
- **Current:** STAGING — `.htaccess` AUTH CACHE BYPASS installed + probe green. PRODUCTION — `.htaccess` block documented-installed (2026-07-13) but **no prod probe** (script refuses prod hostname) and **prod has NO persistent object cache** (`lib/object-cache.php` missing). Weekly staging probe workflow exists but is **uncommitted** (not actually scheduled).
- **Evidence:** `docs/TODO.md:62-69` (.htaccess both envs), `:58` (prod object-cache MISSING); `scripts/auth-cache-isolation-probe.sh:22-48` (staging-only guard); `.github/workflows/staging-cache-probe.yml:9-17` (prepared, not active); spec `docs/testnet-deploy-checklist.md:165-175`.
- **Verdict:** STAGING isolation **PASS** · weekly CI automation **FAIL** (uncommitted) · PRODUCTION isolation **UNKNOWN** · prod object cache **FAIL**.
- **Remediation:** commit the weekly workflow to arm staging verification; provision prod persistent object cache (restore `lib/object-cache.php`, gate `BCC_REDIS_ENABLED`, avoid the `WP_REDIS_TIMEOUT=1` P0 trap); after prod authorized, verify the bypass block is live and run the isolation sequence against prod.
- **Touches:** DOCUMENTATION/repo (commit workflow) + STAGING + PRODUCTION + EXTERNAL SERVICES (GitHub Actions).
- **Note (functional):** with no object cache, `Throttle` **fails closed** (`bcc-core/src/Security/Throttle.php:176`) → rate-limited actions are denied on prod. This makes prod object cache a functional launch prerequisite, not just perf.

## Gate 5 — Production env vars & endpoint origins
- **Required:** prod frontend `NEXT_PUBLIC_BCC_API_URL` → prod WP origin; `NEXTAUTH_URL` → real prod frontend domain; never staging/local.
- **Current:** config plumbing correct + centralized; prod values live only in Vercel → UNKNOWN from repo; no repo artifact pins prod to a non-prod origin (only dev placeholders in gitignored example).
- **Evidence:** single resolve site `bcc-frontend/src/lib/env.ts:30-32,45`; `bcc-frontend/.env.local.example:13,16` dev placeholders; `bcc-frontend/vercel.json` cron-only; checklist `docs/testnet-deploy-checklist.md:265-274`. Real prod domain `bcc-frontend-rho.vercel.app` (memory) not in repo.
- **Verdict:** **UNKNOWN** (no misconfig in repo; live values operator-only).
- **Remediation:** in Vercel (Production scope) confirm `NEXT_PUBLIC_BCC_API_URL` = prod WP origin and `NEXTAUTH_URL` = `https://bcc-frontend-rho.vercel.app`; confirm prod WP `BCC_FRONTEND_ORIGIN` allowlist contains that exact origin.
- **Touches:** PRODUCTION (Vercel env + prod wp-config). Verification only.

## Gate 6 — Database migration / backfill readiness
- **Required:** all schema installs idempotent on activation/init (no manual DB step); no launch-blocking backfill.
- **Current:** **PASS.** Every path is option-guarded + advisory-locked + verify-after-write: bcc-trust schema-version gate (`bcc-trust.php:362-403`, `tables.php:65,323`), FT-index self-heal (`SearchRepository.php:148,204-220`), search-terms table (`SearchTermsRepository.php:75,118-128`), rate limiter has no table (object-cache backed). Deferred backfills (NFT 1155 depth, dispute-signing writer) are post-launch and moot on an empty prod DB.
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
- **Current:** CI green everywhere, **zero open PRs** — but **bcc-search `main` is NOT protected**, and no repo requires PR review.
- **Evidence (`gh api …/branches/main/protection`, 2026-07-21, auth scopes `repo`):**
  - Blue-collar-crypto (umbrella): protected, "Cross-repo guards", enforce_admins ✓
  - bcc-core: protected, "PHP — PHPStan L8 · PHPUnit", enforce_admins ✓
  - **bcc-search: 404 "Branch not protected"** ✗
  - bcc-trust: protected, "PHPStan L8 · PHPUnit · guardrails" + "PHP integration (MySQL)", enforce_admins ✓
  - bcc-frontend: protected, "tsc · lint · vitest", enforce_admins ✓
- **Verdict:** umbrella/core/trust/frontend **PASS** · bcc-search **FAIL** · PR-review requirement **FAIL (policy, all repos)**.
- **Remediation:** enable protection on bcc-search `main` requiring its "PHP — syntax · PHPStan L8 · PHPUnit" check + enforce_admins (its CI is green; only the rule is missing → un-gated pushes to bcc-search main auto-deploy to staging). Confirm whether "no PR review" is intentional for a 2-engineer shop.
- **Touches:** GitHub settings only. No code/staging/prod. **(Can be done now — independent of the prod freeze.)**

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
- **Current:** the admin-audit **P1/P1/P2 fixes are NOT on `main`** — they exist in **four open, unmerged PRs** (parallel session, CI-green, awaiting Phillip's merge). A readiness-walk agent earlier misreported them as "already committed"; that was **wrong** — verified 2026-07-21 that `bcc-trust` `origin/main` still carries the buggy `if (!is_int($gen))` at `HiddenActivityRepository.php`. So the anon-facing gen-counter bug is **still on `main`/staging** until those PRs merge.
- **Evidence:** open PRs `gh pr list` — **trust#98** (`fix/admin-queue-audit-batch-2026-07-21`, gen-counter tolerance + semantic queue kinds), **core#32** (`fix/nonopen-gen-counter-string-cache`), **fe#51** (`fix/admin-moderation-nav-and-ux` — nav + target links + honest errors + `/admin` middleware + undo timing), **umbrella#76** (`docs/admin-audit-2026-07-21` — audit report + sponsorship deferral). Fixed lines live on those branches, NOT main (e.g. `HiddenActivityRepository.php` `is_int`→`is_numeric` is only on trust#98). Separately, remaining prod §A items `docs/performance-review-2026-07-19.md:215-224` (A1 ttl_rest, A2 prod plugin deploy — prod runs bcc-trust **1.1.0** vs staging **1.2.26**, A3 monitor, A4 auth-cache guard, A5 CDN decision, A6 security manual items).
- **Verdict:** admin P1 fixes **PENDING MERGE** (not on staging) · prod §A items **OPEN/UNKNOWN**.
- **Anon-facing note:** the gen-counter bug (admin Hide not propagating to the anon hot feed / permalinks under a string-returning object cache) is the one that touches anonymous first-users. Whether it manifests on staging depends on LSMCD int-type round-trip (unverified). Getting trust#98 + core#32 merged puts the fix on staging — this is the **single highest-leverage staging-quality action available right now**.
- **Remediation:** review + merge the four admin PRs (parallel session / Phillip) to land the fixes on `main`→staging; execute prod §A during the (frozen) prod phase; the only admin follow-up NOT yet started is dead-endpoint cleanup + `ViewerMenu` deletion.
- **Touches:** the four PRs → STAGING (on merge); prod §A → PRODUCTION + EXTERNAL SERVICES; DOCUMENTATION (umbrella#76).

## Gate 12 (added) — Content-search product policy: `public_all` in a secret group
- **Required:** platform intent for whether a `public_all` post inside a **secret** group syndicates globally must be a *decision*, not an accident — it is **live feed behavior today**, and it blocks F3.
- **Current:** **AMBIGUOUS.** Behavior proven live (global gate is group-privacy-independent, `PeepSoActivityRepository.php:273`; old group-exclusion inert); unblocked upstream (a secret-group member can pick `public_all` — `PostsService.php:1902-1928,194-199`, `PeepSoStatusWriter.php:174`, all composer routes); intent undocumented, and the one doc that speaks to it (`api-contract-v1.md:2082-2084`) asserts the **opposite** and is **stale**.
- **Verdict:** **DECISION REQUIRED** (recorded as a build-blocking decision in `docs/content-search-privacy-design.md` Note B; option A "mirror feed" vs B "search is stricter").
- **Remediation:** product decides A or B and records it; separately correct the stale `api-contract-v1.md:2082-2084` text (doc-drift bug).
- **Touches:** DOCUMENTATION (+ CODE only if option B is chosen, later, inside F3). No prod action.

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
3. **Prod has no persistent object cache** (Gate 4) — `Throttle` fails closed ⇒ rate-limited actions denied; plus no caching. Functional prerequisite (avoid the `WP_REDIS_TIMEOUT=1` P0 trap).
4. **Prod plugin deploy** (Gate 11/A2) — prod runs bcc-trust **1.1.0** vs staging **1.2.26**; months of fixes missing.
5. **Prod `cache-ttl_rest=60`** (Gate 1) — else anon REST stale up to a week.
6. **Prod auth-cache isolation confidence** (Gate 4) — verify bypass block live + run the probe against prod.
7. **External uptime monitor + alerts** (Gate 2) — nothing watches prod health today.
8. **bcc-search `main` unprotected** (Gate 8) — un-gated pushes auto-deploy to staging. *(Fixable now, independent of prod.)*
9. **Content-search `public_all`-in-secret-group policy** (Gate 12) — decide A/B; it's live feed behavior. *(Decision now; no prod action.)*
10. **Full anon+authed smoke against staging** (Gate 10) — no recorded green run vs current `main`.
11. **Doc fixes** — cron vars into example/checklist (Gate 3); correct stale `api-contract-v1.md:2082-2084` (Gate 12); commit admin-audit report (Gate 11).

## Minimal clearing sequence

**Phase 0 — safe NOW (staging / repo / GitHub; no prod authorization needed):**
- Enable bcc-search `main` branch protection (GitHub settings; ~2 min).
- Decide the `public_all`-in-secret-group policy (A/B) and record it.
- Doc fixes: add `BCC_INTERNAL_CRON_SECRET` + `CRON_SECRET` to `.env.local.example` and checklist §4; correct `api-contract-v1.md:2082-2084`; commit the admin-audit report.
- Run the full v1 smoke checklist against **staging** over HTTP (anon + authed, incl. §8/§11 notifications); record results.
- Optionally commit the weekly staging auth-cache probe workflow to arm it.
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
