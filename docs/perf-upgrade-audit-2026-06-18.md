# BCC Performance & Upgrade-Path Audit — 2026-06-18

> **Dated audit. Do not edit in place** (per the dated-audit convention used by
> `operational-audit-2026-05-13.md`). Supersede with a new dated file when a
> fresh pass is run.

## Context

A from-scratch performance and upgrade-path audit of Blue Collar Crypto,
conducted under strict rules: every finding cites exact code; no
DAU/traffic/architecture assumptions; no reuse of prior conclusions unless
re-verified from code; no speculative scale limits. Facts are separated from
recommendations. Anything not provable from code is labeled **unknown**.

Evidence was gathered by reading the bcc-* WordPress plugins
(`app/public/wp-content/plugins/bcc-{core,trust,search}`) and the Next.js
frontend (`bcc-frontend/`); the most load-bearing claims (Redis drop-in,
polling cadence, per-request PeepSo calls) were then verified by hand.

---

## 1. What is proven by code

### Caching / object cache
- **Redis object-cache drop-in is present and active in the LOCAL env.**
  `app/public/wp-content/object-cache.php` exists (105 KB — the Redis Object
  Cache drop-in), `wp-config.php:2` defines `WP_CACHE = true`, and
  `wp-config.php:83-88` defines `WP_REDIS_HOST/PORT/PREFIX/DATABASE/TIMEOUT`.
  `WP_REDIS_TIMEOUT = 1` and `WP_REDIS_READ_TIMEOUT = 1` (wp-config.php:87-88).
  Source plugin: `wp-content/plugins/redis-cache/includes/object-cache.php`.
  Confidence: Confirmed (for local).
- **Generation-counter cache invalidation is real, not aspirational.**
  `bcc-trust/app/Domain/Core/Repositories/ScoreRepository.php:97-206` keys
  cache by a generation counter and bumps it atomically on write (orphan-old,
  never-delete → no stampede). Helper:
  `bcc-trust/app/Domain/Core/Support/CacheManager.php:33-104`. The badge
  endpoint uses the same pattern (`MeBadgesEndpoint.php`, 15s server cache).
  Confidence: Confirmed.
- **REST responses set explicit Cache-Control headers.** Auth endpoints emit
  `no-store`; data endpoints emit `private/public, max-age=30` with
  `Vary: Authorization, Cookie` (e.g. `CardDisputesEndpoint`,
  `BlogChainOptionsEndpoint` `public, max-age=3600`). All `/bcc/v1/` and
  `/bcc-trust/v1/` responses are wrapped by `Envelope.php` on
  `rest_post_dispatch` priority 999. Confidence: Confirmed.

### Query discipline
- **All list endpoints clamp page size.** `CardsListEndpoint.php:74-76`
  (`PER_PAGE_MAX=50`, `PAGE_MAX=20`); `FeedEndpoint.php:52-53`
  (`DEFAULT_LIMIT=20`, `MAX_LIMIT=50`); `DisputeController.php:318/350/385`
  (`min(100, …)`); `AdminReportsEndpoint.php:37-38` (`MAX_PER_PAGE=50`);
  `bcc-search SearchController.php:17` (`LIMIT=12`). No unbounded LIMIT found.
  Confidence: Confirmed.
- **Repositories batch instead of N+1.** Batch `IN()` + in-memory assembly in
  `AdminDashboardRepository.php:61-93` and `EndorsementRepository.php:437-438`.
  Member rosters collapse 11 per-user lookups into 11 bounded batch queries
  via `MemberSummaryPrefetcher` (used by `GroupMembersService.php:124-150`).
  Card lists use `PageCardPrefetcher`. Confidence: Confirmed.
- **wp_options hygiene is good.** Every BCC option write is explicitly
  non-autoload (`false`) and lives in cron/migration paths, not request paths
  (cursors in `CronService.php:211/219/423/587/666`, `bcc-core.php:105`).
  No per-request option writes observed. Confidence: Confirmed.

### Background work
- **A real async layer exists.** `bcc-core/src/Cron/AsyncDispatcher.php`
  prefers Action Scheduler (`as_enqueue_async_action`) and falls back to
  `wp_schedule_single_event`. Heavy work is offloaded: vote post-processing
  fans out 4+ jobs per vote (`VoteJobDispatcher.php`), push is debounced 5 min
  via transient queue (`PushDispatcher.php`), NFT enrichment is DB-driven with
  per-run budgets (100 validators / 200 API calls, `EnrichmentScheduler.php`).
  Concurrency guarded by MySQL advisory locks (`AdvisoryLock`,
  `CronService::acquireLock`). Confidence: Confirmed.
- **One cron runs every 60s.** `CronService.php:605-609` registers
  `bcc_one_minute` for the NFT Eth indexer worker; the plugin shows a blocking
  red admin notice if `DISABLE_WP_CRON` is set and cron drifts >5 min
  (`CronService.php:752-767`). Confidence: Confirmed.

### Frontend / runtime
- **React Query defaults are conservative.** `bcc-frontend/src/app/providers.tsx:57-69`:
  `staleTime: 30s`, `gcTime: 2min`, `retry: 1`, `refetchOnWindowFocus: false`.
  Confidence: Confirmed.
- **Badges is the single sustained poll, and it is adaptive + coalesced.**
  `bcc-frontend/src/hooks/useBadges.tsx:54-60+` — `POLL_THREAD_MS=10s`,
  `POLL_UNREAD_MS=25s`, idle `30→60s` geometric backoff, `POLL_HIDDEN_MS=90s`.
  One `/bcc/v1/me/badges` payload replaces three former polls incl. the old
  per-thread 5s message poll (`useConversation.ts` carries no
  `refetchInterval`; it is badge-hint-driven). Server cache 15s + browser
  `private, max-age=10`. Confidence: Confirmed.
- **Only other poll is celebrations at 60s** (`CelebrationGate.tsx:71`, rare
  events). Confidence: Confirmed.
- **Pagination on the client is bounded.** Feed/notifications use
  `useInfiniteQuery` at `PAGE_SIZE=20` (`useFeed.ts:45-78`,
  `useNotifications.ts:49-65`), cursor-based, no background refetch.
  Confidence: Confirmed.
- **Web push infrastructure exists end to end on the client + server keys.**
  VAPID keys defined (`wp-config.php:140-142`), client register/revoke in
  `bcc-frontend/src/lib/push/register.ts`, server dispatch in
  `PushDispatcher.php` (minishlink/web-push). Confidence: Confirmed that the
  path exists.
- **Avatars avoid per-request generation.** Name-based avatars are disabled
  server-side; `bcc-frontend/src/components/identity/Avatar.tsx:94-116`
  detects PeepSo placeholders (`/peepso/avatars-svg/`, `/assets/images/avatar/user-`)
  and renders initials instead of fetching. Confidence: Confirmed.

### Auth
- **Three auth modes, all real.** Session + `current_user_can()`
  (`TrustRestController.php:124`), HS256 bearer JWT keyed on `wp_salt('auth')`
  (`AuthEndpoint.php:34-37`), and wallet-signature challenge/response
  (`WalletIdentityService::verifySignature`). Public routes use
  `permission_callback => '__return_true'` but gate inside the handler and
  return the canonical error envelope. Confidence: Confirmed.

---

## 2. What is NOT proven and must not be assumed

- **Production Redis.** Redis is proven active *locally only*. Whether the
  testnet/production host runs the drop-in is **unknown** — it depends on the
  deploy target, which is not in the repo. Do not assume persistent object
  cache exists in production.
- **DAU / traffic / concurrent users.** No load figures exist in code. Any
  req/s or query/s number would be invented. **Unknown.**
- **Full-page / anonymous caching (LiteSpeed, Varnish, CDN).** No page-cache
  drop-in (`advanced-cache.php`) is present in `wp-content/`. The frontend
  sets `credentials: "omit"` cross-origin specifically to keep a LiteSpeed
  cache key clean (`lib/api/client.ts`), implying intent — but no active
  page-cache config is in the repo. **Unknown / not proven active.**
- **ISR / static generation.** `next.config.ts` sets no `output` and no global
  `revalidate`; only OG-image routes carry `revalidate = 3600`. Regular pages
  are SSR/on-demand. No proof of ISR for content pages. Confidence: Confirmed
  *that ISR is absent*; scale impact is **unknown**.
- **SSE / websockets.** None found. Live updates are polling-based. Confirmed
  absent.
- **Read replicas / managed DB.** `DB_HOST=localhost`, single connection
  (`wp-config.php:39`). No replica config. Confirmed absent locally; prod
  **unknown**.
- **Whether web push is actually used in place of polling.** The path exists
  but badges still polls unconditionally; delivery activation is **unknown**.
- **Action Scheduler presence in production.** Code prefers it but falls back
  to wp-cron. Whether the Action Scheduler plugin is installed on the target
  host is **unknown** (changes background-throughput characteristics).

---

## 3. Current bottlenecks (observed in code, not theorized)

> Ranked by how clearly the code shows sustained or per-request cost.

1. **Per-request PeepSo group resolution on every authenticated feed load.**
   `FeedRankingService.php:1249-1262` (`resolveRestrictedGroupIds`) calls
   `PeepSoGroupRepository::getNonOpenGroupIds()` + `getUserMemberGroupIds($viewer, 1000)`
   on each feed request to build the gated-group exclusion list. Runs on the
   hottest authenticated path. Severity: High · Confidence: Confirmed · Fix
   size: Medium.
2. **Per-user PeepSo avatar instantiation in card view-models.**
   `CardViewService.php:1213-1218` and `UserViewService.php:753-788` call
   `PeepSoUser::get_instance($id)->get_avatar('full')` per user per response.
   On feed/search/notification payloads this is per-item object instantiation.
   Severity: High · Confidence: Confirmed · Fix size: Medium.
3. **The entire performance story leans on Redis being present.** Generation-
   counter caches, 15s badge cache, score cache, and degradation counters
   degrade to non-persistent per-request cache without the drop-in
   (`DegradationMetrics` has an explicit transient fallback). If production has
   no Redis, every cached read becomes a cold DB read. Severity: High *if prod
   lacks Redis* · Confidence: Confirmed mechanism, Unknown prod state · Fix
   size: Small (install drop-in) / config.
4. **Badges poll is the dominant sustained backend request source.** Even
   well-coalesced, the 25s "unread, visible" cohort is one `/me/badges` hit per
   active tab per 25s. It is the floor on authenticated req/s. Severity:
   Medium · Confidence: Confirmed · Fix size: N/A (already optimized; measure
   before changing).
5. **60s NFT-indexer cron requires reliable system cron.** A missed minute
   stalls chain indexing; on a shared host without real cron this drifts.
   Severity: Medium · Confidence: Confirmed · Fix size: Small (ops).
6. **No proven anonymous full-page cache.** Anonymous card/profile/feed views
   are SSR per request with no page cache shown in repo. Severity: Medium ·
   Confidence: Confirmed-absent · Fix size: Medium (ops/config).

---

## 4. Safe upgrade path (each step justified by an observed bottleneck)

**Phase 0 — must fix/verify before testnet**
- P0-A: **Verify the object-cache drop-in is installed on the testnet host
  *or* not shipped at all.** `WP_REDIS_TIMEOUT=1` with the active drop-in on a
  Redis-less host adds a 1s timeout per cache op — a self-inflicted slowdown.
  Decision is binary: Redis present → ship drop-in; Redis absent → do NOT ship
  it and confirm the non-persistent fallback path. (Bottleneck #3.)
- P0-B: **Confirm real system cron** (not wp-cron) given `bcc_one_minute`. The
  blocking admin notice (`CronService.php:752-767`) already enforces awareness;
  verify the deploy satisfies it. (Bottleneck #5.)

**Phase 1 — before real users**
- P1-A: **Cache `resolveRestrictedGroupIds` per viewer for the request (and
  short TTL across requests).** It is recomputed on every feed load from two
  PeepSo queries. (Bottleneck #1.)
- P1-B: **Precompute the resolved avatar URL into the card/user view-model
  cache** so `PeepSoUser::get_instance()` isn't instantiated per item per
  response. (Bottleneck #2.)

**Phase 2 — before growth**
- P2-A: **Add anonymous full-page caching** (LiteSpeed/Varnish/CDN) for anon
  card/profile/feed SSR responses. The frontend already emits cache-friendly
  headers + `credentials: "omit"`; the cache layer is the missing half.
  (Bottleneck #6.)
- P2-B: **Confirm Action Scheduler is installed in production** so vote fan-out
  (4+ jobs/vote) and push flush don't fall back to wp-cron under load.

**Phase 3 — later scale work**
- P3-A: Consider replacing the badges poll with web push for the unread/bell
  path (infrastructure already exists) — *only after measuring* badge req/s.
- P3-B: Read replica / managed DB — only if measured DB CPU proves it.

---

## 5. Do NOT do yet (no code evidence of need)

- Do **not** add SSE/websockets — polling is coalesced and adaptive; no
  evidence the poll floor is a problem yet.
- Do **not** add read replicas or managed DB — single `localhost` connection,
  zero measured DB pressure.
- Do **not** introduce ISR/static export for content pages — no evidence SSR
  per-request is a bottleneck; correctness/freshness trade-offs unjustified.
- Do **not** widen the Redis/object cache into a page cache by hand — use a
  purpose-built page cache (Phase 2) instead.
- Do **not** shorten badge polling intervals — they are already adaptive.
- Do **not** expand observability/degradation signals — out of scope, and
  prior bake-discipline applies.

---

## 6. Small wins (low risk, code-evidenced)

- **S1:** Memoize `resolveRestrictedGroupIds` within a single request (static
  per-viewer cache) — pure win, no behavior change. (`FeedRankingService.php:1249`)
- **S2:** Confirm `BCC_HIGHLIGHTS_DEMO` (`wp-config.php:132`) is NOT defined on
  testnet/prod — it forces placeholder highlight slots; harmless locally but
  must be off in shipped envs.
- **S3:** Ensure the drop-in/Redis decision (P0-A) is documented in the deploy
  checklist so `WP_REDIS_TIMEOUT=1` never lands on a Redis-less host.
- **S4:** Verify Action Scheduler is present (one plugin check) to keep async
  fan-out off wp-cron.

---

## 7. Risks that need measurement, not guessing

- **R1: Badge req/s at N active tabs.** Floor is one `/me/badges`/25s/visible
  tab. Needs a real concurrent-tab number before any push migration is justified.
- **R2: Feed p95 with the per-request PeepSo group + avatar calls.** Needs DB
  query timing under realistic group counts, not estimation.
- **R3: Whether production has Redis + system cron + Action Scheduler.** Three
  binary facts that change the entire profile; all currently **unknown** and
  must be observed on the actual host.
- **R4: Vote fan-out throughput.** 4+ jobs/vote — needs a measured vote rate
  before deciding if wp-cron fallback is acceptable.
- **R5: Push transient queue size** under burst (`PushDispatcher` stores
  aggregated payload arrays in transients) — measure, don't assume.

---

## Ranked next-10 upgrades (verified code behavior only)

1. **P0-A** — Resolve the Redis drop-in vs `WP_REDIS_TIMEOUT=1` decision for
   the testnet host (binary; biggest single profile-mover). *Small.*
2. **P0-B** — Confirm real system cron for the 60s NFT indexer. *Small (ops).*
3. **S1 / P1-A** — Memoize then short-TTL-cache `resolveRestrictedGroupIds`
   (per-request PeepSo group resolution on the hot feed path). *Medium.*
4. **P1-B** — Bake resolved avatar URL into the view-model cache to kill
   per-item `PeepSoUser::get_instance()`. *Medium.*
5. **S4 / P2-B** — Verify/ensure Action Scheduler in production so vote fan-out
   + push flush don't use wp-cron. *Small.*
6. **S2** — Confirm `BCC_HIGHLIGHTS_DEMO` is off in shipped envs. *Small.*
7. **R3** — Instrument the host to capture Redis/cron/Action-Scheduler presence
   + cache hit ratio (turns three unknowns into facts). *Small.*
8. **R2** — Measure feed p95 and the two PeepSo queries under realistic group
   counts (gates whether #3/#4 are urgent). *Small (measurement).*
9. **P2-A** — Add anonymous full-page caching for anon SSR card/profile/feed
   (headers already cache-friendly). *Medium.*
10. **R1 → P3-A** — Measure badge req/s; only then evaluate push-over-poll for
    the bell path (infra already exists). *Medium, deferred.*

---

## Verification of this audit

- Re-run the route/permission claims: `grep -rn "register_rest_route" app/public/wp-content/plugins/bcc-*`.
- Confirm the drop-in: `ls -la app/public/wp-content/object-cache.php` (present, 105 KB).
- Confirm polling cadence: read `bcc-frontend/src/hooks/useBadges.tsx:54-90`.
- Confirm the hot-path PeepSo call: `bcc-frontend` → none; backend
  `FeedRankingService.php:1249-1262` and `CardViewService.php:1213-1218`.
- For prod facts (R3): on the host run `wp redis status` (or check Settings →
  Redis), `wp cron event list`, and `wp plugin list | grep action-scheduler`.
