# BCC Capacity Model

Quantitative capacity model for the Blue Collar Crypto platform, anchored to
the system's **actual** behavior as of 2026-06-18 (post-F1 polling cadence,
≤50-query boot floor, Redis-backed read-models, Vercel→WP-PHP split).

Every number traces to a stated assumption and an explicit formula. The
single biggest swing is **peak concurrent share of DAU** — it is carried
through every metric. Practical limits use a **30% safety margin** (size to
70% utilization of the binding resource).

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
private, max-age=15`; PHP 8.3.30 origin). With a 15s TTL, PHP regenerated each
endpoint at most ~4×/min regardless of VUs — so this run validates the
**production anon architecture** (edge absorbs anon load; it will not dent at
any realistic anon RPS), NOT origin PHP/MySQL capacity.

**Still unmeasured** (the remaining capacity questions):
1. **Origin capacity** — cache-miss traffic. Measurable anon-side with
   cache-busting query params (deliberately adversarial; operator sign-off
   required) or, more honestly, via the authed path.
2. **Authed traffic** — bypasses the anon edge cache entirely; the harness's
   Mailpit auto-auth is local-only, so staging authed runs need a JWT minted
   server-side (SSH + wp-cli) or a staging test account + real mailbox.
3. **With/without-Redis comparison** — needs SSH to flip the drop-in.
4. Box spec (vCPU / FPM workers) — unconfirmed; readable over SSH.

---

## Related

- Deploy/ops gates: [testnet-deploy-checklist.md](testnet-deploy-checklist.md)
  (§1.5 object cache, §1.6 LiteSpeed anon edge cache).
- Boot-floor probe + load-check commands: [GOLDEN_PATHS.md](GOLDEN_PATHS.md) §5.6.
- Hosting/Redis strategy: see the project memory `project_hosting_redis_strategy`.
