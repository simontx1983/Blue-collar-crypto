# BCC Capacity Model

Quantitative capacity model for the Blue Collar Crypto platform, anchored to
the system's **actual** behavior as of 2026-06-18 (post-F1 polling cadence,
≤50-query boot floor, Redis-backed read-models, Vercel→WP-PHP split).

Every number traces to a stated assumption and an explicit formula. The
single biggest swing is **peak concurrent share of DAU** — it is carried
through every metric. Practical limits use a **30% safety margin** (size to
70% utilization of the binding resource).

> **Reconciliation note (2026-07-16):** the analytic model below (§1–§10,
> "First bottleneck", "Practical DAU limits") predates the staging
> measurement campaign and several of its inputs are now known to be wrong:
> per-worker RAM is ~100–105MB (not 60MB), staging now runs LSMCD (the
> "no persistent object cache" penalty rows describe **production** today,
> not staging), and the measured binding resource on this tier is the
> **5-core LVE CPU cap** — not PHP-worker exhaustion, not memory, not
> MySQL. The authoritative current numbers are the dated "Measured
> staging" sections, newest last ("Ceiling hunt", 2026-07-16). Sections
> below are labeled **SUPERSEDED** where a measurement replaced them; the
> model is kept for its formulas and for sizing the future VPS.

> **Architecture scope:** "Vercel" hosts only the Next.js frontend. **All
> data/queries hit the WordPress/PHP backend** (`bcc-frontend/src/lib/api/client.ts`:
> Vercel→WP, Bearer-only). PHP-FPM + MySQL are the real ceiling regardless of
> Vercel. Hidden browser tabs **do not** poll (`useBadges.tsx`,
> `refetchIntervalInBackground:false`), so only *visible* sessions generate load.

---

## Global assumptions

| Parameter | Best | Expected | Worst | Basis |
|---|---|---|---|---|
| Peak concurrent *visible* sessions (% of DAU) | 5% | 10% | 15% | Engaged-social peak-hour concurrency |
| → Concurrent sessions `C` (at 10k DAU) | 500 | 1,000 | 1,500 | `DAU × %` |
| Badge poll cadence (weighted avg) | 28s | 28s | 20s | F1: 10% chat@10s, 30% unread@25s, 60% idle@45s |
| Feed/nav/action reqs per session | 0.02/s | 0.03/s | 0.05/s | 1 req per ~20–50s of activity |
| Avg queries/request (Redis warm) | 12 | 18 | 28 | Read-model + prefetcher; boot floor caps at 50 |
| Avg request latency | 80ms | 120ms | 220ms | Warm-cache REST; SSR data heavier |
| Cold-cache penalty (no Redis) | — | ×2–4 queries & latency | — | Per-request caches don't persist |

**Concurrency cross-check (independent):** 10k DAU × 3 sessions × 10 min =
5,000 user-hours/day; if 15% lands in the peak hour → ~750 concurrent. The
500–1,500 range brackets it; expected 1,000 is mildly conservative-high.

---

## 1. Requests per second (to the WP backend)

`RPS = C × (badge_rate + feed_rate + other_rate)`, badge_rate ≈ 1/28s = 0.036/s.

| | Best | Expected | Worst |
|---|---|---|---|
| Badge | 18 | 36 | 75 |
| Feed+other | 10 | 30 | 75 |
| **Total RPS** | **~28** | **~70** | **~150** (bursts ~250) |

## 2. Badge polling traffic

`badge_RPS = C × Σ(fraction_i / interval_i)` = 0.036/s per session.

| | Best | Expected | Worst |
|---|---|---|---|
| Badge RPS | 18 | 36 | 75 |
| Badge DB q/s | 45 | 90 | 188 |

**Tuning applied 2026-06-18:** `BADGE_CACHE_TTL_SECONDS` raised 15→30s. F1
moved the dominant poll interval to 25s; with a 15s TTL most single-tab polls
*missed* the per-user cache (poll interval > TTL) and recomputed ~2.5 queries
each. A 30s TTL ≥ poll interval, so most polls now hit cache → badge DB load
drops ~2–3×. The generation-counter still forces an immediate refresh on any
real badge event, so only the *missed-bump safety window* widens 15→30s.

## 3. Feed traffic

No polling (no `refetchInterval`); load on nav + re-stale after 60s; 20
items/page; page hydrate ≈ 25 queries (prefetcher-batched, ≤50 boot floor).

| | Best | Expected | Worst |
|---|---|---|---|
| Feed RPS | ~6 | ~18 | ~45 |
| Feed q/s | 150 | 450 | 1,350 |

## 4. Notification traffic

Reads folded into badges (no separate poll). Writes ≈ 5 received/DAU/day.
`notif_creates/s = DAU × 5 / 86,400` (avg), ×5 peak.

| | Best | Expected | Worst |
|---|---|---|---|
| Notif writes/s | 0.3 | 0.6 (peak ~3) | ~6 |

Negligible vs badge/feed.

## 5. Database queries per second

`DB_QPS = Σ(endpoint_RPS × queries_per_endpoint)`

| | Best | Expected | Worst |
|---|---|---|---|
| Badges | 45 | 90 | 188 |
| Feed | 150 | 450 | 1,350 |
| Other/profile/mutations | 200 | 600 | 1,500 |
| **Total (Redis warm)** | **~400** | **~1,150** | **~3,000** |
| **Total (NO Redis, ×2.5)** | ~1,000 | ~2,900 | ~7,500 |

The cold-cache row is what kills shared hosting.

## 6. Redis operations per second

`redis_ops = RPS × 30` (cache ops/request).

| | Best | Expected | Worst |
|---|---|---|---|
| Redis ops/s | ~840 | ~2,100 | ~4,500 |

A modest Redis does 100k+ ops/s → **Redis is never the bottleneck**; it is a
relief valve, not a constraint.

## 7. PHP-FPM worker requirements

Little's Law: `busy = RPS × latency`; provision `busy / 0.7`.

| | Best | Expected | Worst |
|---|---|---|---|
| Busy (warm) | 2 | 8 | 33 |
| **Provision (÷0.7)** | ~3 | ~12 | ~47 (spikes → ~70) |
| Cold-cache busy (×2.5) | ~6 | ~20 | ~80 → provision ~115 |
| Worker RAM @60MB | 0.2GB | 0.7–1.2GB | 3–7GB |

> **SUPERSEDED input (2026-07-16, MEASURED):** per-worker RSS on staging is
> **~100–105MB** (Wordfence + Rank Math load in every worker), not 60MB —
> scale this row and §9's PHP-FPM row by ~1.7× when reusing the model.

## 8. MySQL CPU requirements

~2,000 q/s per vCPU (mixed read-model/join). `vCPU = QPS / 2,000`, provision `÷0.7`.

| | Best | Expected | Worst |
|---|---|---|---|
| Warm vCPU busy | 0.2 | 0.6 | 1.5 |
| **Provision** | ~0.3 | ~1 | ~2 |
| Cold (no Redis) | ~0.7 | ~1.5 | ~3.8 → ~5–6 |
| Connections (= busy workers) | ~6 | ~20 | ~80–115 |

## 9. Memory requirements (backend box)

`PHP-FPM×60MB + InnoDB buffer pool + Redis + OS`

| Component | Best | Expected | Worst |
|---|---|---|---|
| PHP-FPM | 0.5GB | 1.2GB | 6GB |
| InnoDB buffer pool (hot set) | 1GB | 3GB | 8GB |
| Redis | 0.2GB | 0.5GB | 1GB |
| OS/overhead | 0.5GB | 1GB | 1.5GB |
| **Total** | **~2GB** | **~6GB** | **~16GB** |

## 10. Storage growth per month (10k DAU)

`rows/mo = DAU × events/day × 30`; `bytes ≈ rows × (row + index)`

| Table | Events/DAU/day | Rows/mo | Size/mo | Notes |
|---|---|---|---|---|
| activity log | 20 | 6M | ~2.4GB | plateaus (90-day retention + archive) |
| score_events | ~4 | ~1.2M | ~0.5GB | persistent |
| votes | 2 | 0.6M | ~0.2GB | persistent |
| notifications (PeepSo) | 5 | 1.5M | ~0.5GB | PeepSo-pruned |
| messages/endorse/misc | — | — | ~0.5GB | mixed |
| **Gross** | | | **~4–5GB/mo** | |
| **Net persistent** (after retention) | | | **~1.5GB/mo** | votes+score_events+endorsements |

| | Best | Expected | Worst |
|---|---|---|---|
| Gross/mo | ~2GB | ~4.5GB | ~10GB |

---

## First bottleneck that actually fails

> **SUPERSEDED (2026-07-16, MEASURED):** the ceiling hunt found the actual
> first bottleneck on the current shared tier is the **LVE 5-core CPU
> burst cap** (pins at exactly 5.0 cores from 60 VUs; ~20–21 authed origin
> req/s). Worker count and memory both grew far past this section's limits
> without failing (102 workers / 9.96GB RSS), and MySQL stayed essentially
> idle (`Threads_running` ≈ 1) in every measured regime — the cold-cache
> MySQL leg of the thesis below never materialized. Kept for the VPS-era
> reasoning only.

**Shared hosting: PHP-FPM worker exhaustion, compounded by cold-cache MySQL
CPU.** Without a persistent object cache, every request pays ~2.5× queries
*and* ~2.5× latency → workers stay busy longer → the ~25–40 worker pool
saturates, requests queue, latency cascades. Matches the `BadgesService`
author's note ("~30–80 concurrent before COUNT contention saturates a worker,"
pre-coalesce).

**VPS (with Redis): MySQL CPU is the next wall**, then single-primary
connection/write contention past ~20–40k DAU (→ needs a read replica, F3).

---

## Practical DAU limits (70% utilization = 30% safety margin)

Binding resource at each tier, expected-load column.

> **Status (2026-07-16):** row 1 is superseded by measurement — the shared
> tier's measured band is **~1,900–5,700 DAU (expected-case ~2,800)** with
> the binding resource being the **LVE CPU cap**, not workers/MySQL (see
> "Ceiling hunt"). Rows 2–4 are **UNVERIFIED projections**: they were built
> on the 60MB-worker and Redis assumptions above and have never been
> benchmarked — do not treat the 4-vCPU "7,000–8,000" or "10k DAU box"
> claims as fact until the same k6 protocol runs on that hardware. The
> VPS's case is now "CPU cores," not "memory."

| Platform | Binding resource | Practical safe DAU | Notes |
|---|---|---|---|
| **1. Hostinger Business (shared)** | PHP-FPM (~30 workers) + cold-cache MySQL (often no real Redis) | **~1,500–2,500** | True Redis + steady CPU → top of range; otherwise bottom. Don't plan past ~2k. |
| **2. 4 vCPU VPS (Redis, tuned, 8GB)** | MySQL CPU / worker RAM | **~7,000–8,000** | Handles expected 10k *load* at ~70–85%, thin spike headroom → safe target ~8k. |
| **3. 8 vCPU VPS (Redis, 16GB)** | MySQL CPU, then connections | **~18,000–22,000** | ~2× the 4-vCPU box; single-primary writes bind near the top. |
| **4. Managed DB + Vercel + scaled WP + Redis + CDN** | WP app-tier scale; DB **writes** | **~50,000** (→ **100k+ with F3 read-replica + F5 queue**) | Vercel removes the *frontend* wall only; managed DB + replicas remove MySQL CPU; writes + cron drain are the last limits. |

**Planning takeaway:** Hostinger Business covers early testnet (~2k DAU). A
**4 vCPU VPS + Redis is the real "10k DAU box"** — and it's exactly the upgrade
that unlocks the deferred wins (Redis flip, LiteSpeed anon cache §1.6,
badge-TTL bump). 8 vCPU buys ~20k. Past that → F3 (read replica) / F5 (worker
queue) and the managed-DB architecture, which is why those are deferred until
the hosting upgrade.

---

## Measured baseline — local dev (2026-06-19, k6)

First real measurement of the hot read paths via
[`scripts/perf/load-test.js`](../scripts/perf/load-test.js), against the Local
(Flywheel) dev site. **Heavily caveated:** Local runs a tiny PHP-FPM pool,
`WP_DEBUG=true`, and likely Xdebug on a single box — so the *absolute* numbers
below are a dev-box stress signature, **not** production capacity. The
*relative* findings and the cold-cache result are the takeaways.

**Single-request, warm cache** (the useful latency baseline):

| Endpoint | Warm | Cold (first hit) |
|---|---|---|
| `GET /bcc/v1/cards?per_page=20` | ~0.36s | ~0.4s |
| `GET /bcc/v1/members?per_page=20` | ~0.22s | ~0.2s |
| `GET /bcc/v1/feed/hot?per_page=20` | ~1.2s | **~20s** |

**Under 10 concurrent VUs** (k6, 15s): latency collapsed — `/cards` p95 ~1.8s,
`/members` p50 ~10s / p95 ~30s, `/feed/hot` p95 ~24s, throughput falling to a
handful of req/s. This is **FPM-worker serialization on an untuned single box**
(Local ships ~2–4 workers + Xdebug), not an endpoint defect — it's the exact
thing the per-tier model assumes proper FPM pool sizing removes.

**Actionable findings (survive the caveats):**
1. **`/feed/hot` "~20s cold" — RE-ATTRIBUTED 2026-07-02.** Instrumenting every
   outbound WP HTTP call proved the stall is NOT the feed rebuild (the anon
   first-page build measures ~0.15–0.2s even fully cold). The real mechanism:
   after a cache flush the next request spawns wp-cron, whose tick re-probes
   the chain providers (~28 sequential RPC calls ≈ 15–18s) and occupies one of
   Local's **~2 PHP-FPM workers**; the *second* concurrent request then queues
   in FPM for the sweep's remaining duration (reproduced: 0.25s / 17.5s / 0.12s
   across three requests post-flush). This is the same dev-box FPM cliff as
   finding 2 — on a tuned pool it's cron-context work, not user latency.
   Independently useful: the anon first-page payload cache + minutely warming
   cron (bcc-trust `feat/feed-hot-warm`) cut the *warm* `/feed/hot` from ~1.2s
   to ~0.1s (single cache get), which matters at beta scale since this is the
   highest-QPS anonymous endpoint.
2. **The per-tier DAU numbers above cannot be validated on Local** — the FPM
   cliff dominates. Re-run this k6 script against a provisioned staging box
   (4 vCPU + Redis + tuned FPM, no Xdebug) to turn the modeled tiers into
   measured ones. The script is parameterized (`-e URL=… -e VUS=… -e DURATION=…`).
3. Endpoint ordering by cost: **feed ≫ cards > members** — matches the audit's
   "feed is the heaviest hot path" call.

> Not done: a with-vs-without-Redis comparison. On this box the FPM cliff swamps
> any cache delta, so that comparison is only meaningful on the staging box above.

### Re-running the harness

`scripts/perf/load-test.js` grew into a scenario harness on 2026-07-12 (same
file, same baseline methodology). It is **LOCAL-ONLY by default** — any host
other than `blue-collar-crypto-custom.local`/`localhost`/`127.0.0.1` aborts
(exit 108) unless `-e ALLOW_NON_LOCAL=1` is passed. Local runs are capped at
25 VUs / 5 min (`-e ALLOW_UNCAPPED=1` lifts). Run from repo root:

```bash
k6 run scripts/perf/load-test.js                                        # smoke: 1 VU, one pass over 8 hot reads, checks
k6 run -e SCENARIO=baseline -e DURATION=60s scripts/perf/load-test.js   # 1 VU constant, per-endpoint bcc_* trends
k6 run -e SCENARIO=ramp scripts/perf/load-test.js                       # staged ramp 0→3→10→10→0, weighted mix
k6 run -e SCENARIO=authed -e AUTH_IDENTIFIER=<email> -e AUTH_PASSWORD=<pw> scripts/perf/load-test.js
```

- **Comparability:** `baseline` is the 1-VU sequential warm-cache methodology of
  the table above (same `summaryTrendStats`); `ramp` reproduces the 10-VU
  signature. Hard thresholds are error-rate/checks only — latency has NO
  thresholds locally because the 2-worker FPM pool and the ~10s cron/indexer
  ticks own the tails (compare `bcc_*` p50s against the baseline by hand, and
  don't flush caches right before measuring: the post-flush wp-cron chain
  sweep occupies an FPM worker for ~17s).
- **Legacy mode preserved:** `-e URL=<full url> -e VUS=… -e DURATION=…` still
  runs the original single-endpoint shape — that is the with/without-Redis
  staging methodology; do not replace it with the scenario runs.
- **Authed scenario:** performs the full login → Mailpit OTP → 2FA verify → JWT
  chain in `setup()` (login is throttled 5/60s per IP — the harness authenticates
  exactly once). Credentials come from env only, never committed. Local test
  account: `k6-harness@bcc.local` (subscriber, handle `k6-harness`; reset its
  password via wp-cli if lost). Mailpit's port drifts on Local re-provision —
  `-e MAILPIT_URL=` overrides the `127.0.0.1:10006` default (see GOLDEN_PATHS §6.2).
- **Staging:** the WP REST base is `https://stage.bluecollarcrypto.io`
  (`app.stage.` is the Next.js frontend). Example:
  `k6 run -e ALLOW_NON_LOCAL=1 -e BASE_URL=https://stage.bluecollarcrypto.io -e SCENARIO=baseline scripts/perf/load-test.js`.
- **Results:** every run writes `scripts/perf/results/<timestamp>-<scenario>.{json,md}`
  (gitignored) plus the normal console summary.

## Measured staging — 2026-07-12 (k6 harness, anon reads)

First staging run, per the operator-approved envelope (hard ceiling 10 VUs;
stop rules: sustained failures >1%, p95 >2s, climbing 429/5xx — none tripped).
Load generator: dev machine over residential WAN, so every number below
INCLUDES client RTT; server-side time is lower than shown.

| run | VUs | duration | reqs | req/s | failures | p50 | p95 | p99 | max |
|---|---|---|---|---|---|---|---|---|---|
| baseline | 3 | 5m | 1,585 | 5.3 | 0% | 67ms | 90ms | 129ms | 339ms |
| ramp step 1 | 1 | 3m | 321 | 1.8 | 0% | 67ms | 93ms | 139ms | 239ms |
| ramp step 2 | 5 | 3m | 1,601 | 8.7 | 0% | 69ms | 98ms | 144ms | 197ms |
| ramp step 3 | 10 | 4m | 4,241 | 17.4 | 0% | 70ms | 101ms | 141ms | 211ms |

**Zero failures across ~7,750 requests; latency flat from 1→10 VUs** (p50
67→70ms). The local ~10s cron-stall artifact does not exist on staging.

**Read this with the cache tier in mind:** staging serves anon REST responses
from **LiteSpeed edge cache** (`x-litespeed-cache: hit`, `Cache-Control:
private, max-age=15`; PHP 8.3.30 origin). ~~With a 15s TTL, PHP regenerated
each endpoint at most ~4×/min regardless of VUs~~ — **corrected 2026-07-16:
the `max-age=15` header only governs browsers. The real edge TTL is LSCWP's
`cache-ttl_rest`, verified at 604800s (one week) on both staging and prod**,
so PHP regenerated each endpoint essentially once. Either way the point
stands: this run exercised the **production anon cache tier**, NOT origin
PHP/MySQL capacity. It performed
flawlessly at the 17.4 req/s tested, which is encouraging but does NOT prove
immunity to traffic spikes, cache stampedes, attacks, or edge-cache-miss
storms — none of those regimes were exercised. The question that matters for
capacity is the origin: how many logged-in users the box supports before
performance degrades.

### Measured staging — authed origin (2026-07-12, k6 authed session mix)

Authenticated run per the operator-approved protocol (SSH-minted short-lived
JWT for a throwaway subscriber, deleted+revoked after; LiteSpeed/Redis/config
untouched). Load = the weighted logged-in session mix (feed 40 / me-*
30 / rotating profiles 20 / edge-served cards-members-groups tail 10); the
Bearer-required routes reach origin on every request (verified via
`x-litespeed-cache`). Client over residential WAN — numbers include RTT.

| step | VUs | duration | reqs | failures | p50 | p95 | p99 | max |
|---|---|---|---|---|---|---|---|---|
| verify | 1 | 3m | 145 | 0% | 270ms | 339ms | 397ms | 414ms |
| 2 | 3 | 3m | 435 | 0% | 263ms | 331ms | 399ms | 560ms |
| 3 | 5 | 3m | 725 | 0% | 264ms | 325ms | 374ms | 413ms |
| 4 | 10 | 4m | 1,911 | 0% | 273ms | 344ms | 538ms | 913ms |

**Zero failures / zero 429/5xx across ~4,200 authed requests; latency flat
from 1→10 VUs.** Origin routes sit at p50 ~250–295ms (incl. WAN RTT); the
edge-served tail at ~80–95ms. Envelope checks passed 6,424/6,424.

**Server telemetry** (5s sampling over SSH; account-scoped): idle = 2 lsphp
workers / 117MB RSS. At 10 VUs: 8–11 lsphp workers, ~1.0–1.8 cores of CPU,
0.8–1.17GB RSS, ≤4 DB connections, MySQL `Threads_running` pinned at 1 the
entire session — **origin cost is PHP-time-dominated; MySQL is essentially
idle** (consistent with the local query audit: reads are batched and cheap).
No resource-saturation signal, no LVE entry-process (508) events. Shared-host
note: Hostinger reaped the long-running monitor process after ~15 min —
background daemons don't survive there; the 10-VU window was re-measured with
a fresh monitor + 2-min burst (identical latency: p50 268ms / p95 332ms).

**Bounded conclusion:** no degradation up to ~8 req/s of sustained
authenticated origin traffic (10 paced VUs) on the current shared-hosting
tier; the saturation knee was NOT located — it lies above the approved
ceiling. Finding the knee needs a higher operator-approved VU ceiling.

### Measured staging — knee ramp + edge-miss regimes (2026-07-15, k6)

Operator-approved envelope: authed ramp ceiling **25 VUs**, stop rules
fail >1% / p95 >2s / any 429/508 — none tripped. Same methodology as the
2026-07-12 authed run (SSH-minted short-lived JWT for a throwaway subscriber
— user 69, deleted after; 5s SSH telemetry sampling; residential-WAN client,
so latencies include RTT). Box context this night: 64 cores, host load ~40
(other tenants), idle account = 3–5 lsphp / ~150–370MB RSS.

**Authed knee ramp** (weighted session mix, 2-min stages):

| step | VUs | reqs | req/s | failures | p50 | p95 | p99 | max |
|---|---|---|---|---|---|---|---|---|
| 1 | 10 | 932 | 7.8 | 0% | 279ms | 424ms | 641ms | 744ms |
| 2 | 15 | 1,383 | 11.4 | 0% | 289ms | 434ms | 791ms | 939ms |
| 3 | 20 | 1,793 | 14.8 | 0% | 297ms | 642ms | 1.30s | 1.65s |
| 4 | 25 | 2,110 | 17.4 | 0% | 334ms | 949ms | 1.59s | 1.78s |

Per-stage telemetry maxima: lsphp workers 18 → 23 → **29 → 29** (pool
plateaus between 20→25 VUs); account CPU burst 2.7 → 3.0 → 4.4 → **4.6
cores**; RSS 1.55 → 2.47 → 2.96 → **3.01GB**; MySQL `Threads_running` ≤9
server-wide (shared instance; deltas small — still not the bottleneck).

**Interpretation:** p50 stays flat (~280–335ms) while tails grow smoothly —
graceful queueing/CPU-throttle onset, not collapse. The **SLA-knee (p95
crossing ~500ms) sits between 15 and 20 VUs ≈ 12–15 req/s authed origin.**
The worker plateau at ~29 plus ~3GB RSS says the first HARD limit above the
tested ceiling is most likely account memory, then the LVE CPU cap (~5-core
burst observed). Comfortable sustained envelope on this tier: **≤~11 req/s
authed origin (15 paced VUs) at p95 ≤450ms**; degraded-but-stable to at
least 17.4 req/s (25 VUs, p95 ~950ms). The hard failure point was NOT
reached inside the approved ceiling.

**Anon edge-miss regimes** (`/feed/hot?per_page=20`):

- **TTL-roll stampede** — 20 unpaced VUs on the fixed URL, 75s: 18,292 reqs
  at **243 req/s, 0% failures, p50 78ms / p95 95ms**; 99.9% edge hits with
  exactly 1 origin miss (+19 no-header responses) across ~5 TTL windows.
  **LiteSpeed does not stampede origin at TTL expiry** — one regeneration
  per roll while everyone else keeps getting served.
- **Forced-miss** (new `-e CACHE_BUST=1` harness mode; per-request unique
  param so every request reaches origin PHP): 5 VUs = 996 reqs, all misses,
  16.5 req/s at p50 296ms / p95 350ms, 0% failures; 10 VUs = 35.2 req/s of
  which **18.7 true-miss req/s at p95 560ms, 0% failures**. Anon origin
  (feed/hot's server-side warm payload cache) comfortably absorbs ~17–19
  pure-miss req/s at 5–10 concurrent — an edge-cache wipe is survivable at
  these traffic levels.
- Honest observation: in the 10-VU run, `_cb` URLs repeated from the
  previous run returned edge HITs minutes later — the anon edge TTL for
  `/feed/hot` is currently **longer than the 15s recorded on 2026-07-12**
  (entries survived ≥2–3min). Conclusions above use the miss-only
  population and are unaffected; re-verify the TTL if edge freshness ever
  matters for product behavior.

**Redis A/B: not runnable on this tier.** No Redis or memcached server is
reachable from the account (TCP 6379 refused, no sockets under the account,
`redis-cli` absent; the PHP extensions exist but no service). Confirmed
2026-07-15 against [Hostinger's support doc](https://www.hostinger.com/support/9581774-is-redis-supported-at-hostinger/):
**Redis is NOT offered on web/cloud (shared) plans at all** — VPS
(self-managed) or Agency hosting only, so the A/B arrives with the VPS
upgrade, matching the tier table above. Possible interim substitute: if
hPanel shows an "Object Cache" toggle for the site, that's **LSMCD
(LiteSpeed Memcached)** — the LSCWP plugin supports it as a backend and an
A/B against it would test the same hypothesis (relieves PHP-time
options/meta lookups, not DB).

### Measured staging — write path, throttle seatbelts, short soak (2026-07-15, k6)

Write + auth-limit + soak regimes, same SSH-minted-throwaway methodology
(5 subscribers users 70–74 + one throwaway `peepso-post`, all deleted after
— verified zero residue in wp_posts / peepso_activities / activity_ranking).
Residential-WAN client, so latencies include RTT.

**Comment-write burst** (4 concurrent users POSTing to one post, 25s): the
heaviest write path (PeepSo synchronous wp_post + activity). Successful-write
latency **p50 303ms / p90 345ms / p95 384ms / p99 522ms / max 590ms — on par
with reads**, not the multiple typical of a synchronous social write. 80
writes succeeded, then the per-author throttle took over; zero 5xx. Telemetry
peak: 9 lsphp / 924MB RSS — writes are not materially heavier on the box than
reads (consistent with MySQL staying idle; the cost is PHP time either way).

**Throttle seatbelts — verified live:**
- **Comment** (`BCC_TRUST_RATE_LIMIT_COMMENT` 20 / 300s per author): one user
  posting rapidly got **20 clean 200s then a hard 429 wall from request #21**.
  Under the 4-way concurrent burst the same limit held exactly (4 × 20 = 80
  admitted, remainder 429) — the limiter is correct under concurrency, not
  just sequentially.
- **Login** (5 / 60s per IP): sequentially, **5 × 401 then 429**. The race
  test — 20 *simultaneous* attempts against a fresh bucket — admitted **only
  4, blocked 16**: no over-admission, so the counter is atomic (no TOCTOU
  flood bypass). This is the security-relevant result; a concurrent
  credential-stuffing burst cannot slip past the per-IP cap.

**Short soak** (2 authed VUs, 30 min, origin read mix): 2,892 iterations,
**0 failures, 100% checks, p50 235ms / p95 300ms / p99 481ms**, flat for the
full window. RSS across 5-min telemetry snapshots oscillated
453 → 671 → 672 → 461 → 453MB — **no monotonic climb, so no memory-leak
signal at 30 min** (the rise-and-fall tracks lsphp worker recycling). Bounded:
this is a 30-minute soak, not the multi-hour test that would catch a slow leak
or a once-a-few-hours cron interaction.

**Still unmeasured** (updated 2026-07-15):
1. The HARD failure point (above 25 VUs / 17.4 req/s authed origin —
   expected to be account memory first; needs a raised operator ceiling and
   close RSS watch).
2. **With/without-Redis comparison** — BLOCKED on hosting (no Redis service
   on the account; Hostinger shared plans don't offer it), in addition to the
   standing operator postponement.
3. Other write paths (votes, reviews, photo/gif posts) — only comment-create
   was load-tested. And a **multi-hour soak** — only the 30-min short soak
   above has run.

### Measured staging — post-prune knee re-run (2026-07-16, k6)

Same protocol as 2026-07-15 (authed weighted mix, SSH-minted throwaway
subscriber user 75 — revoked+deleted after; 2-min stages, residential-WAN
client, 5s SSH telemetry). **What changed between runs (memory-headroom plan
step 2/4):** four staging plugins deactivated (`redis-cache` — active with no
Redis service, `debug-log-manager`, `akismet`, `all-in-one-wp-migration`),
`stage/.user.ini` capping `memory_limit` 3072M→512M, and the bcc-trust #91 /
bcc-search #8 deploys. **NOT yet changed:** no persistent object cache (LSMCD
toggle pending), WP-Cron still loopback, LSCache config untouched.

| step | VUs | reqs | req/s | fail | p50 | p95 | p99 | max | lsphp | RSS | CPU |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | 10 | 954 | 7.9 | 0% | 249ms | 348ms | 570ms | 1.01s | 15 | 1.49GB | 1.5 |
| 2 | 15 | 1,432 | 11.8 | 0% | 254ms | 348ms | 504ms | 785ms | 17 | 1.64GB | 3.7 |
| 3 | 20 | 1,884 | 15.5 | 0% | 258ms | 409ms | 797ms | 989ms | 22 | 1.58GB | 3.6 |
| 4 | 25 | 2,359 | 19.4 | 0% | 254ms | 382ms | 884ms | 1.38s | 26 | 2.60GB | 3.8 |

Versus the 2026-07-15 baseline at the same stages: **p95 at 25 VUs 949ms →
382ms; p50 flat ~254ms at every stage (was climbing 279→334ms); throughput at
25 VUs 17.4 → 19.4 req/s; peak RSS 3.01GB → 2.60GB; worker plateau 29 → 26.**
The **SLA-knee (p95 > 500ms) is no longer crossed anywhere in the tested
0–25 VU range** — it moved above ~19 req/s authed origin. Per-worker RSS is
~unchanged (~100MB — Wordfence and Rank Math still load); the win is fewer,
faster workers: less per-request PHP time → less concurrency at the same
offered load.

Honest caveats: single run vs single run on a multi-tenant box (the 07-15
baseline noted host load ~40 from other tenants; tonight's tenancy is
unknown), so treat the deltas as strong-but-not-controlled evidence. The
plugin prune is the dominant suspect for the latency drop; the `memory_limit`
cap changes no steady-state behavior (it bounds runaways). LSMCD A/B and the
cron flip remain the next measured steps; the "Still unmeasured" list above
is unchanged.

### Measured staging — LSMCD object cache, first live A/B (2026-07-16, k6)

**Wiring story (matters for reproducing on prod):** the hPanel "Object
Cache" toggle DID start LSMCD (memcached 1.6.42) — but on **IPv6 `[::1]:11211`
only** (an IPv4 `127.0.0.1` probe reports closed), and LSCWP set
`litespeed.conf.object=1` without ever installing the `object-cache.php`
drop-in because the staging plugin install was **missing its own
`lib/object-cache.php` source file** (LSCWP 7.8.1's installer `copy()` failed
silently on settings-save). Healed by restoring that file from the official
7.8.1 release zip and re-running `wp litespeed-option set object true`.
Verified live: `wp_using_ext_object_cache()` true, cross-process
`wp cache set/get` persists, `wp_cache_incr` works (generation counters
safe). LSMCD runs as a system service — its memory does NOT count against
the account RSS allowance. Staging only; prod untouched.

Three 25-VU × 2-min authed stages (same protocol; throwaway user 76,
revoked+deleted after):

| regime | req/s | fail* | p50 | p95 | p99 | lsphp† | RSS† | CPU† |
|---|---|---|---|---|---|---|---|---|
| cold-fill (first-ever) | 19.3 | 2.5%* | 239ms | 539ms | 1.25s | ≤28 | ≤2.9GB | ≤4.4 |
| rebuild (post-flush stampede) | 19.4 | 2.2%* | 234ms | 563ms | 1.16s | 27 | 2.37GB | 3.8 |
| **warm** | **19.5** | 1.8%* | **234ms** | 538ms | 1.04s | 14 mean / 27 max | 1.39GB mean | **2.63 mean / 3.77 max** |

† **CORRECTION (2026-07-16, forensic re-read):** the originally published
warm row (“4 lsphp / 0.32GB / 0.5 cores”) was a **post-run idle sample** —
k6 result-file timestamps mark run *end*, and the telemetry windows were
shifted one run-length late. Corrected values above come from the true load
windows (`telemetry-lsmcd-warm-20260716.log` 13:09:49–13:11:49). The same
misattribution affected the cold-fill row (window peaked ~28/2.9GB/4.4, not
the last-sample 11/1.10GB/2.6). Telemetry columns are 5s `ps` samples
(lifetime-averaged pcpu) — treat as approximate.

\* Every failure across all three stages was one endpoint (`profile_rotate`)
404ing on a **test artifact**: the deleted throwaway user from the earlier
ramp lingered in the cached anon `/members` payload, and k6's setup() kept
re-discovering the dead handle. All other endpoints were 100% 200s in every
stage. Cache-busted origin fetches confirmed the fresh list was clean.
(Corrected 2026-07-16: the staleness was the LiteSpeed **edge** entry —
which `wp cache flush` does not touch — full root cause below. The original
"object cache first, then edge" attribution was an inference; the code trace
shows `/members` has no object-cache layer and `wp user delete` bumps WP
core's `users:last_changed`, so the origin self-corrects immediately.)

**Headline (REWRITTEN after the telemetry correction): LSMCD's measured
win at 25 VU is latency-side only** — best p50 of any run (234ms) — while
box cost (workers/RSS/CPU) is **roughly equal to the post-prune run at the
same load** (~2.6 cores warm vs the post-prune table's 3.8; within tenancy
noise). The originally claimed "~10× less peak memory" was an idle-sample
artifact and is retracted. Warm vs cold-started 25 VU are near-identical
(2.63 vs ~2.8 mean cores at equal req/s): the object cache does not remove
the dominant per-request CPU cost, which is a fixed ~160–180ms server-side
floor per authed request (~35–45ms lsphp+Wordfence-WAF pre-WP + ~120–140ms
WP+plugin boot/REST — measured by server-side TTFB partition probes,
2026-07-16). The p95 tail (~540ms vs the prune-only run's 382ms) is
unattributed single-run variance.

**New findings surfaced by cache persistence (previously unobservable) —
root causes CONFIRMED 2026-07-16 (code trace + read-only SSH inspection):**
1. **User deletion does not invalidate cached member lists** — CONFIRMED,
   and the stale layer is the **LiteSpeed edge only**: `/members`
   (`UsersEndpoint::members()`) is a live `WP_User_Query` with no BCC cache
   layer, so the origin is correct the moment the user row is gone; but no
   bcc-* code ever emitted `X-LiteSpeed-*` headers or fired a
   `litespeed_purge*` action, so the edge entry lived out its full TTL.
   **Fixed** (bcc-trust `fix/members-edge-cache-correctness`): `/members`
   responses now pin a 15s edge TTL + `bcc_members` tag via LSCWP's API
   (`EdgeCache`), and user delete (`deleted_user`) / suspend / unsuspend
   purge the tag (+ the deleted user's profile REST URL).
2. The anon REST edge TTL mystery is solved: **LSCWP `cache-ttl_rest` was
   still 604800s (one week) on BOTH staging and prod** — the 07-15 tuning
   changed `cache-ttl_pub` to 60 but REST responses use the separate REST
   TTL. The declared `max-age=15` never governed the edge. Operator fix:
   set `cache-ttl_rest` to 60 on both boxes (see TODO.md).
3. Prod's LSCWP install **has the same defect and worse** (verified
   read-only over SSH 2026-07-16): `lib/object-cache.php` MISSING, **no
   `wp-content/object-cache.php` drop-in at all**, and a stale 120-byte
   `.litespeed_conf.dat` from Apr 29 — prod has NO persistent object cache
   today, and the hPanel Object Cache toggle will silently fail exactly as
   staging's did until the lib file is restored.

### Staging reaches full target state — cron flip + final stage (2026-07-16)

`DISABLE_WP_CRON=true` is live on staging (mu-plugin define uncommented on
the server) with **two out-of-band drivers, both verified firing**:

- **hPanel system cron at a measured 60-second cadence** hitting
  `wp-cron.php?doing_wp_cron` — so the "Hostinger Business caps cron at
  5–15 min" claim carried by the api-contract and the relay docblocks is
  **wrong for this plan**; hPanel accepts `* * * * *`. Gotcha for the
  record: hPanel executes cron commands WITHOUT a shell — `>` redirection
  and quoted URLs are passed as literal argv (first attempt failed with
  `curl: (6) Could not resolve host: >`); the working form is
  `curl -s -o /dev/null <url>` (program flags only, no quotes).
- **Vercel Cron minutely** → `/api/internal/cron/indexer-tick` relay →
  staging tick endpoint (indexer + hot-feed warm). This had NEVER worked:
  `CRON_SECRET` and `BCC_INTERNAL_CRON_SECRET` were absent from the
  Vercel project env (set 2026-07-16, sensitive, production) and the WP
  side constant was also newly defined. Verified live via
  `bcc_nft_eth_indexer_tick_last_success` advancing every minute. The
  deployed frontend's `BCC_API_URL` targets **staging** (proven
  empirically — sensitive env vars pull as empty).

**Final 25-VU × 2-min stage** (warm cache + real cron, throwaway user 77
revoked+deleted after): 18.4 req/s, **0% failures, checks 4,476/4,476**
(the earlier profile_rotate artifact gone after purging both cache layers).
Client-observed p50 284ms / p95 684ms — noisier than the earlier warm
stage. **CORRECTION (2026-07-16):** the originally published "≤5 lsphp /
0.40GB / 0.5 cores, first ~30s" figures were **pre-run idle samples** (the
same window-misattribution as the LSMCD table above); the sampler in fact
covered the full run, showing **10–26 lsphp / ≤2.6GB / 2.0–3.7 cores** —
in line with every other 25-VU run. The tail movement is therefore
**unattributed** (tenancy/WAN/cron all candidates; single runs cannot
separate them). The account-memory conclusion no longer rests on these
figures — it rests solely on the ceiling ramp below (9.96GB with no
memory failure).

### Measured staging — ceiling hunt to 100 VUs (2026-07-16, operator-approved)

First ramp above the old 25-VU ceiling (authed weighted mix, 2-min stages,
throwaway user 78 revoked+deleted after; single client IP over residential
WAN). Both cache layers had been purged minutes before the ramp, but
**cache temperature turned out immaterial**: stage-1 per-endpoint p50s
matched the warm 25-VU run within 2–4%, and LSMCD refilled within the
first seconds — do NOT read this ramp as a conservative cold bound.

Telemetry columns below are **per-stage 5s-sample maxima** (means are
lower: ~4.0 cores at 60 VU, ~4.3 at 80).

| VUs | req/s | fail | p50 | p95 | lsphp | RSS | CPU |
|---|---|---|---|---|---|---|---|
| 25 | 19.2 | 0% | 240ms | 653ms | 28 | 2.68GB | 4.5 |
| 40 | 19.9 | 0% | 1.11s | 1.56s | 43 | 4.08GB | 4.8 |
| 60 | 20.6 | 0% | 2.17s | 2.64s | 65 | 6.50GB | **5.0** |
| 80 | 21.3 | 0% | 2.88s | 3.51s | 82 | 8.24GB | **5.0** |
| 100 | collapse | **97%** | 0ms* | — | 102 | **9.96GB** | 5.0 |

\* p50 0ms = instant TCP read resets, not served responses.

**Findings (several prior beliefs corrected):**

1. **The ~3GB account-memory ceiling is REFUTED.** RSS grew linearly with
   admitted concurrency to **9.96GB at 102 workers with no memory failure**.
   The 07-15 "3.01GB plateau ⇒ nearest hard limit is memory" inference was
   a concurrency artifact (29 workers × ~104MB), not a wall.
2. **The binding resource is CPU: sampled account CPU plateaus at ~4–5
   cores from 40–60 VUs** (means 4.0–4.3, single samples touching 5.04) —
   **consistent with a ~5-core LVE cap but not directly confirmed**
   (`ps pcpu` is lifetime-averaged; `lveinfo`/CloudLinux fault counters or
   the hPanel resource panel would prove the configured limit). Throughput
   caps at **~20–21 req/s** — every VU beyond ~25 only deepens the queue
   (p50 rises ~linearly with VUs while req/s stays flat; per-VU rate falls
   0.77→0.27, proving the server, not the harness, is the limiter).
3. **The 100-VU wall is a protection/limit layer, not application
   failure**: the stage died by TCP connection resets (9,279 RSTs, zero
   4xx/5xx bodies) at lsphp ≈ 100–102, workers collapsed to idle within 5s,
   a server-side probe of the same URL returned 200, debug.log stayed
   clean, and the client IP remained TLS-refused for ≥1h afterward. The
   exact layer is **unidentified** (per-source-IP firewall and an
   entry-process cap ≈100 both fit). Multi-IP traffic is expected not to
   trip the per-IP part, but this is untested; the account CPU cap binds
   regardless of IP distribution.
4. Worker count ≈ admitted concurrency (~1 lsphp per in-flight request,
   ~100MB each). No 508/LVE-EP events; no memory kill.

**DAU translation (capacity-model §1 formulas, expected-case):** sustained
authed origin under the 500ms-p95 target ≈ **~19–20 req/s** — MEASURED for
**warm-cache steady state** (warm 25-VU runs: p95 382–538ms); a fully cold
start exceeds the target at the same load (this ramp's cold 25-VU p95 was
653ms), so "SLA-grade" applies to warm operation only. The translation from
there is **CALCULATED, not measured**: at an *assumed* ~0.07 req/s per
concurrent session → ~285 concurrent sessions, ÷ an *assumed* 10%
peak-concurrency → **~2,800 DAU expected-case** (best 5% → ~5,700; worst
15% → ~1,900) — the anon/edge tier (243 req/s proven) rides on top of this.
Net: the origin **req/s ceiling is measured**; the DAU figures inherit the
two behavioral assumptions and move linearly with them. The standing "don't
plan past ~2k DAU on this tier" guidance sits at the conservative end of
that modeled band. The VPS remains the answer past that; its case is now
"CPU cores," not "memory."

Still unexercised: multi-hour soak, write-heavy + login-storm regimes
(**comment-create was tested at read-parity on 2026-07-15 — do NOT
generalize that to other write paths**; attestation cast does a synchronous
score recompute and has never been load-tested), and any multi-IP
distributed load (single-IP bans at ~100 concurrent are a harness limit).
The **plugin-floor hypothesis** — that deactivating Wordfence + Rank Math
recovers part of the ~160–180ms fixed floor (~20–45% capacity) — is
**untested**; a controlled staging A/B is queued.

---

## Related

- Deploy/ops gates: [testnet-deploy-checklist.md](testnet-deploy-checklist.md)
  (§1.5 object cache, §1.6 LiteSpeed anon edge cache).
- Boot-floor probe + load-check commands: [GOLDEN_PATHS.md](GOLDEN_PATHS.md) §5.6.
- Hosting/Redis strategy: see the project memory `project_hosting_redis_strategy`.
