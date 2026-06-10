# REST Route Audit — 2026-06-10

**Dated record. Do not edit in place** (matches the `operational-audit-*`
convention). Supersedes nothing; captures the state at audit time.

## Why

`scripts/contract-parity-guard.php` reported **88 documented endpoints vs 233
in-scope registered routes**, WARNing on **128 routes registered in PHP but absent
from `docs/api-contract-v1.md` §4**. The WARN was noise: it lumped genuinely
internal routes (admin, OAuth callbacks, webhooks, health) with genuinely-public,
frontend-consumed routes never added to §4. A future accidentally-public route
would not have stood out.

This audit classifies all 128 into four buckets, verifies the permission posture
of every one, records the dead-route deletions, and defines the internal-exemption
allowlist — so that, once §4 is backfilled, the guard WARN lands at ~0 and becomes
a live signal again.

## Method

Four parallel classification passes (auth+me, users+disputes, content,
internal/admin/legacy). For each route: located its `register_rest_route()` call
(file:line), read the `permission_callback` **and any in-handler gate**, and
grepped `bcc-frontend/src` (esp. `src/lib/api/*`) for a typed-client consumer.

## Buckets

- **A** — Public/authenticated, frontend-consumed → **document in §4**.
- **B** — Internal-admin (`manage_options` / `admin_permission_check`) → **allowlist**.
- **C** — Internal-machine (shared-secret / webhook / OAuth callback / liveness) → **allowlist**.
- **D** — Dead/legacy (no consumer, superseded or never wired) → **DELETE**.

## Security findings

**No P0/P1 accidental exposure.** Every `__return_true` route that mutates or
returns sensitive data has an adequate in-handler gate:

| Route | In-handler gate | Verdict |
|---|---|---|
| `GET /bcc/v1/system/ping` | none — returns only `{status,timestamp}`, no mutation | OK (public by design) |
| `GET /bcc-trust/v1/github/callback` | IP rate-limit + CSRF `validateState` + authed-session-matches-state | OK |
| `GET /bcc-trust/v1/x/callback` | IP rate-limit + CSRF `validateState` | OK |
| `POST /bcc/v1/admin/digest/run-now` | `adminGate()` `manage_options` + cooldown | OK |
| `GET /bcc/v1/admin/reports` | `adminGate()` `manage_options` | OK |
| `POST /bcc/v1/admin/reports/:id/resolve` | `adminGate()` `manage_options` | OK |
| `POST /bcc/v1/admin/reports/undo` | `adminGate()` + server-issued 30s token | OK |
| `POST /bcc/v1/onchain/helius/webhook` | `hash_equals` Authorization header | OK |
| `POST /bcc/v1/internal/indexer/tick` | secret-defined check + `hash_equals` `X-Bcc-Internal` | OK |
| `GET /bcc/v1/digest/unsubscribe` | HMAC-SHA256 `uid\|exp` token, 90-day TTL, `hash_equals` | OK |
| `GET /bcc-trust/v1` (namespace root) | none; returns route listing incl. handler-class names | **LOW** info-disclosure → deleted (bucket D) |

The bcc/v1 `/me/*`, `/posts`, `/reactions`, etc. routes also use `__return_true`
with a handler-level `get_current_user_id() <= 0 → 401` check — the project's
uniform auth pattern, not an exposure.

## Deletions (bucket D) — approved 2026-06-10: delete all unconsumed

### Tier 1 — unambiguous dead
| Route | Location | Why dead |
|---|---|---|
| `GET /me/binder` | `Onchain/REST/WatchingEndpoint.php:149` | Deprecation alias of `/me/watching`; FE uses watching-endpoints.ts only |
| `GET /me/binder/summary` | `WatchingEndpoint.php:160` | alias of `/me/watching/summary` |
| `POST /me/binder/pull` | `WatchingEndpoint.php:170` | alias of `/me/watching/watch` |
| `DELETE /me/binder/:follow_id` | `WatchingEndpoint.php:181` | alias of `DELETE /me/watching/:follow_id` |
| `GET /bcc/v1/page/:id` | `Core/REST/PageEndpoint.php:42` | FSE-block-era PageDataLoader; superseded by `GET /cards/:type/:id`; no consumer |
| `GET /bcc-trust/v1` (root) | `bcc-trust Plugin.php:1480` (`apiIndex`) | discovery index duplicating WP core; no consumer; info-disclosure |
| `POST /bcc-trust/v1/vote` | `Core/Controllers/TrustRestController.php:74` | legacy trust-engine writer; superseded by `bcc/v1/disputes`; no consumer |
| `POST /bcc-trust/v1/remove-vote` | `TrustRestController.php:89` | counterpart to dead `/vote` |
| `POST /bcc-trust/v1/report-vote` | `TrustRestController.php:141` | superseded by disputes panel; no consumer |
| `GET /bcc-trust/v1/user/:id/pages/scores` | `TrustRestController.php:123` | scores now via bcc/v1 card/page VMs; no consumer |
| `GET /bcc-trust/v1/user/status` | `TrustRestController.php:135` | legacy status read; no consumer |

### Tier 2 — implemented but never wired
| Route | Location | Why dead |
|---|---|---|
| `POST /bcc/v1/claim` | `Core/REST/EntityClaimEndpoint.php:30` | superseded by `POST /pages/:id/claim`; no consumer |
| `POST /bcc/v1/flag` | `Core/REST/FlagEndpoint.php:30` | no consumer; FE moderation uses dispute/report surfaces |
| `GET /bcc/v1/nft/collections` | `Onchain/Controllers/CollectionController.php:24` | only smoke-test references it; no FE consumer |
| `POST /bcc/v1/auth/token` | `Core/REST/AuthEndpoint.php:262` | forward-looking re-mint stub; no consumer (login/refresh cover the flow) |
| `GET /bcc/v1/onchain/:page_id` | `Onchain/Controllers/SignalController.php:26` | dormant; onchain signals delivered inline in card VMs; no consumer |

`POST /bcc/v1/report-user` was initially in this tier but **reverted after review** — see "Member-report subsystem" below. Net deletions: **15**.

**Keep — live legacy routes (do NOT delete):** `POST /bcc-trust/v1/endorse`,
`POST /bcc-trust/v1/revoke-endorsement`, `POST /bcc-trust/v1/device-fingerprint`
are bucket A (consumed by `endorse-endpoints.ts`, `fingerprint-endpoints.ts`).

### Deletion outcome (executed 2026-06-10)

**15** routes removed (4 single-route classes deleted outright: `PageEndpoint`,
`FlagEndpoint`, `EntityClaimEndpoint`, `CollectionController`). `POST /report-user`
was reverted after review (`DisputeController.php` restored to HEAD) — see
"Member-report subsystem" below.
Dead helpers/consts/imports orphaned by the deletions were removed in the same
pass; `composer dump-autoload` regenerated. `php -l`, arch-guardrails, and PHPStan
level-8 all pass (2 remaining PHPStan errors — `AuthMailer::LOGO_URL`/`SITE_URL`
unused — predate this work). Guard WARN: **128 → 111** (−17, incl. the namespace
root).

One live non-route consumer was correctly re-pointed: `PageEndpoint::bustCache*()`
were thin passthroughs to `PageDataLoader::bust()`/`bustForUser()`, called by
`CronService` cache invalidation in 7 places — those call sites now call
`PageDataLoader` directly (1:1, no behavior change; PHPStan confirms the methods
resolve).

### Member-report subsystem — reviewed 2026-06-10, KEPT

`POST /report-user` was first classified bucket-D (no FE consumer) and deleted, then
**restored after a closer review** found it is the write entry-point to a *complete,
wired* feature — not dead code:

- Member reports a member → row in `bcc_user_reports` (`DisputeRepository::createReport`
  + the `hasActiveReport` / `countRecentReportsByReporter` / `countActiveReportsAgainst`
  guards) → **live wp-admin "User Reports" tab** (`DisputeAdmin` + `ReportListTable`)
  → admin "penalize" fires `bcc.trust.admin_report_penalty` (live listener at
  `bcc-trust.php:808` — applies a **trust-score deduction** + audit log) → cron sweep
  releases stuck notification claims (`DisputeScheduler`) → `delete_user` cleanup.
- This is a BCC-specific trust mechanic PeepSo cannot do: PeepSo's native profile/
  activity report (`wp_peepso_report`) is **flag-only, no trust consequence**, and
  `bcc_user_reports` never integrated with it (parallel stores). The content-report
  system (`ContentReportService`, `feed_item`) is a third, independent system and is
  the only report path currently wired into the headless frontend.
- Deleting `/report-user` only removed the *write* door; the admin/cron/penalty back
  office stayed live, leaving an admin tab reading an unfillable table. **Decision
  (Phillip): restore the endpoint, keep the feature.** It is now "implemented +
  admin-wired, awaiting a frontend report-member button" (V1.5). Documented at §4.27.
- Residual cosmetic debt (harmless): the inert "Report User" button injected into
  PeepSo profiles (`bcc-trust.php:~1237`) has no JS handler — the eventual frontend
  button supersedes it.
- **Stale narrative comments** referencing deleted surfaces remain (descriptive
  prose, not commented-out code): `Plugin.php:1174/1650`, `WatchingService.php:320`
  (mention `/me/binder/*`), `VoteService.php:322-325`, `PagesEndpoint.php:53`,
  `FlagsRepository.php:14`. Cosmetic; left to keep the deletion diff scoped.

## Allowlist (buckets B + C) — `EXEMPT_INTERNAL` in the guard

Intentionally out of the public contract; verified gated.

**B — admin:** `GET /bcc/v1/system/health` · `GET /bcc-trust/v1/health/read-model`
· `GET /fraud/stats` · `GET /users/high-risk` · `GET /activity/fraud` ·
`GET /stats/trust-trend` · `GET /stats/risk-distribution` · `GET /stats/fraud-trend`
· `GET /stats/devices` · `POST /analyze-user/:id` · `POST /bcc/v1/admin/digest/run-now`
· `GET /bcc/v1/admin/reports` · `POST /bcc/v1/admin/reports/:id/resolve` ·
`POST /bcc/v1/admin/reports/undo` · `GET /bcc/v1/disputes/health` ·
`POST /bcc/v1/disputes/:id/resolve` · `POST /bcc/v1/onchain/:page_id/refresh`

**C — machine:** `GET /bcc/v1/system/ping` · `GET /bcc/v1/digest/unsubscribe` ·
`GET /bcc-trust/v1/github/callback` · `GET /bcc-trust/v1/x/callback` ·
`POST /bcc/v1/onchain/helius/webhook` · `POST /bcc/v1/internal/indexer/tick`

## §4 documentation backlog (bucket A)

To be written in namespace-cluster batches. Notable: `GET /creators/:slug/gallery`
is **fully wired** — the §8 "deferred" note is stale and must be promoted, not left
deferred. `/auth/*` (signup, refresh, login, verify-email, resend-verification) are
narrated in §2/§β but lack the canonical `` #### `METHOD /path` `` header the guard
keys on — they need header normalization, not from-scratch documentation.

- **auth/**: `POST /auth/signup` · `POST /auth/refresh` · `POST /auth/login` ·
  `POST /auth/verify-email` · `POST /auth/resend-verification`
- **me/**: highlights (GET + `:id/dismiss`) · attestations (POST, DELETE `:id`,
  POST `:id/reaffirm`) · badges · reliability · blocks (GET, POST, DELETE
  `:user_id`) · groups (POST) · messages-prefs (GET/EDITABLE) · privacy
  (GET/EDITABLE) · profile (EDITABLE; avatar POST/DELETE; cover POST/DELETE;
  cover/position EDITABLE) · reports (POST) · handle (EDITABLE) ·
  onboarding (GET suggestions, POST complete, GET status) · reviews DELETE `:id`
- **users/:slug/**: albums · albums/`:album_id`/photos · followers · following ·
  blog · reviews · disputes · activity
- **endorsements/**: mine · mine/stats · (live legacy) `bcc-trust/v1/endorse`,
  `/revoke-endorsement`, `/device-fingerprint`
- **disputes/**: POST · votes/`:page_id` · mine · panel · `:id`/vote ·
  participation/me
- **entities/`:kind`/`:id`/**: disputes · reviews · watchers · attestations
- **groups + locals**: groups/`:slug` · groups/`:id`/members · groups/`:id`/feed ·
  locals/`:slug`
- **posts + reactions + feed**: posts (POST) · posts/`:id` (GET, EDITABLE) ·
  reactions (POST) · reactions/`:feed_id` (DELETE) · feed/cold-start ·
  blog/chain-options · blog/cover-image
- **onchain/oauth**: creators/`:slug`/gallery · OAuth connect set
  (`github/auth|status|disconnect|refresh`, `x/auth|status|disconnect|verify-share`)

## Final state — achieved

`php scripts/contract-parity-guard.php` → exit 0:
- Contract endpoints parsed: **165** (was 88).
- In-scope registered routes: **217** (was 233; net −16 after 15 deletions, with
  `/report-user` restored).
- Exempt internal (allowlisted admin/machine): **23**.
- **Undocumented WARN: 0** — "every undocumented in-scope route is accounted for
  by the EXEMPT_INTERNAL allowlist." The guard is a live signal again.

§4 documents bucket A (new §4.25–§4.30 + auth headers) · allowlist exempts B + C ·
bucket D deleted. Contract changelog: v1.25.

**Two-repo split (for committing):**
- **main repo** — `docs/api-contract-v1.md`, `scripts/contract-parity-guard.php`,
  `docs/route-audit-2026-06-10.md` (this file).
- **bcc-trust repo** (`app/public/wp-content/plugins/bcc-trust`, separate origin) —
  4 deleted endpoint classes + 8 modified files (the route deletions).

Verification: php -l clean on all touched PHP + the guard; `arch-guardrails.sh
bcc-trust` PASS; PHPStan level 8 clean except 2 pre-existing `AuthMailer`
unused-constant errors (not in this change set); independent arch-guardrails review
found no dangling references and confirmed the `CronService → PageDataLoader::bust*`
re-point resolves. (`api-contract-check.sh` live-HTTP tier not run — no local server.)
