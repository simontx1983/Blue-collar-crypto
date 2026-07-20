# Performance Review — 2026-07-19 (evidence audit, read-only)

Independent review of the BCC performance/capacity campaign: what the evidence proves,
which conclusions hold, and what work remains before launch. No tests were re-run and
nothing was changed. All claims below trace to a cited file or raw result.

---

# Performance Review — Executive Verdict

**What the completed tests genuinely prove.** The anonymous read tier is a solved
problem at beta scale: LiteSpeed edge served **243 req/s with 0% failures and no
origin stampede** (capacity-model.md:418–422). The authenticated origin has a
**measured throughput ceiling of ~19–21 req/s**, flat from 25→80 VUs while p50 rose
linearly — classic queueing, not collapse (capacity-model.md:659–665, raw:
`scripts/perf/results/ceiling-80vu-20260716.out` — 21.28 req/s, p50 2.88s, p95 3.51s,
0% failed, verified this review). The binding resource is **per-request PHP CPU
against a 4-core plan cap** (hPanel-confirmed; capacity-model.md:727–738); MySQL was
essentially idle in every regime. Cost per authed request is ~195ms of CPU, of which
a fixed **~160–180ms is WordPress/plugin boot + Wordfence WAF** — application code
adds little on top.

**Current bottleneck:** fixed per-request boot cost × 4 CPU cores. Not memory
(the 3GB ceiling was refuted — 9.96GB summed RSS ran without failure), not MySQL,
not BCC code. The hard wall at 100 VUs was the **PHP-Workers=100 plan cap
compounded by the Hostinger CDN per-IP ban layer** — an infrastructure protection
response, not application failure (capacity-model.md:683–699, raw:
`ceiling-100vu-20260716.out` — 97.03% http_req_failed via TCP resets, zero 4xx/5xx).

**Ready for a controlled beta?** Yes for the **5,000-registered-member launch
target** — registered accounts are database rows, not load; the population that
costs capacity is DAU. **~2,000 DAU is the conservative current planning
estimate** (a planning level, not a measured cap; the calculated band is
1,900–5,700 and **5,000 DAU has not been proven**). Caveat: **every measurement
is staging; production has never served a single measured request** and
currently runs an old plugin version, a week-long REST edge TTL, and no object
cache — production readiness cannot be claimed from staging measurements alone.
The remaining work is configuration, monitoring, and one regression guard — not
more load testing.

**Most important remaining task:** close the production-parity config block
(`cache-ttl_rest`=60 both boxes, prod plugin deploy, prod cron/object-cache
decisions), wire an external uptime monitor to `/system/ping`, and give the
LSCache Authorization-bypass rule durable regression coverage (today it is
protected only by manual curls and rsync path-scoping — a wipe would be silent).

**Another load test now?** No. The capacity questions that matter are answered or
are blocked/trigger-gated. The only justified pre-launch "test" is a tiny
cache-isolation verification probe (a few sequential curls, not load).

---

# Evidence Inventory

| Artifact | Path | Measures | Env / Date | Raw or prose | Trustworthy? | Limitations |
|---|---|---|---|---|---|---|
| Master capacity doc | `docs/capacity-model.md` | Entire campaign: analytic model + all dated staging measurements | Staging + local; 2026-06-18→07-17 | Prose + result tables | **High** — unusually self-correcting (3 published retractions) | Analytic §1–§10 superseded; latencies include WAN RTT; single-run deltas on multi-tenant box |
| Raw k6 summaries | `scripts/perf/results/*.{json,md}` (43 pairs) | Per-endpoint p50/p90/p95/p99 per run | Staging; 07-13→07-16 (UTC stamps) | **Raw** | High | Client-observed latency (incl. RTT) |
| Ceiling ramp raw | `results/ceiling-{25,40,60,80,100}vu-20260716.out` | The 25→100 VU capacity ramp | Staging 07-16 | **Raw** (console) | High — spot-verified 80 & 100 VU this review | Single client IP |
| Server telemetry | `results/telemetry-*.log` (15 files) | 5s samples: lsphp count, RSS, CPU | Staging 07-16 | **Raw** | Medium-high | `ps pcpu` lifetime-averaged → smear; caused 2 (corrected) misattributions |
| Plugin A/B k6 legs | `results/ab-{A..E}-s{1,2}-*.out` | Load per plugin regime | Staging 07-16 | Raw | **VOID** | CDN burst sensor reset 100% of sustained load; telemetry ≤6 lsphp confirms near-idle |
| Plugin A/B floor probes | capacity-model.md:768–782 (table only) | Server-side TTFB, pre-WP vs full boot, 6 samples/regime | Staging 07-16 | Prose table (no raw log kept) | **Valid** — E-vs-A drift used as yardstick | n=6/regime; ±5ms/±20ms noise bands |
| k6 harness | `scripts/perf/load-test.js` | Traffic model: smoke/baseline/ramp/authed/legacy, CACHE_BUST | — | Source | High | Local-only guard; no latency thresholds; never in CI |
| Boot-floor probe | `scripts/bcc-query-floor-probe.php` | Per-request query counts | Any | Source | High | Manual drop-in, manual removal |
| Auth-bypass rule + verify | `docs/testnet-deploy-checklist.md:162–178`; `docs/TODO.md:53–62` | LSCache Authorization isolation | Staging+prod, 07-13 | Prose (rule verbatim + manual 4-curl protocol) | High for "was applied"; **no automated guard** | Server-only; not in repo `.htaccess` |
| Perf changelog / open items | `docs/TODO.md:44–64` | Fix history + open operator steps | — | Prose | High | Line 63 stale (fe#45 merged 07-17) |
| Pre-measurement code audit | `docs/archive/perf-upgrade-audit-2026-06-18.md` | Bounded queries, batch prefetchers, cache invalidation | Local code | Prose, code-cited | High for code claims | Predates all measurement |
| Observability | `bcc-core/bcc-core.php:380–933`, `src/Observability/DegradationAlerter.php` | /system/health, /system/ping, threshold alerter | Code | Source | High | No CPU/error-rate alert; external monitor not confirmed wired |
| Git history | umbrella #44–#69; trust #83/#88/#91/#93 | All perf work merged to main; nothing on branches | — | Commits | High | "61→51 queries" exists only as commit message (trust `98c3cb1`) |

---

# Reconstructed Test History

1. **2026-06-11→18 — Code-level groundwork.** PageCardPrefetcher N+1 fix (trust
   `3b4b3f6`), avatar caching, `/members` 1492→143 queries, boot-floor fix (~250→≤50
   queries, probe script + checklist gate §5.6). Code-cited audit
   (`docs/archive/perf-upgrade-audit-2026-06-18.md`): bounded pages, batch
   prefetchers, generation-counter invalidation. **Valid (code claims); no traffic numbers.**
2. **2026-06-19 — Local k6 baseline.** Dev-box stress signature only; the "~20s cold
   feed" was re-attributed to Local's 2-worker FPM + wp-cron chain sweep
   (capacity-model.md:232–277). **Useful-but-limited; absolute numbers void for capacity.**
3. **2026-07-12 — Staging anon reads (edge tier).** 7,748 reqs (1,585+321+1,601+4,241),
   0% failures, p50 67→70ms, worst p95 101ms at 17.4 req/s (capacity-model.md:323–331;
   raw pairs in `results/2026-07-13T03-*`). Exercised the **cache tier, not origin**. **Valid.**
4. **2026-07-12 — Staging authed origin baseline.** 1→10 VUs, 0% failures/429/5xx,
   origin p50 ~250–295ms incl. WAN, worst p95 344ms; telemetry: 8–11 workers,
   ~1–1.8 cores, Threads_running=1 (capacity-model.md:348–381; raw
   `results/2026-07-13T04-58-…-authed.md` spot-verified). Note: table sums 3,216 reqs
   vs prose "~4,200" — extra untabulated verify runs exist in the results dir. **Valid.**
5. **2026-07-13 — LSCache Authorization leak found & fixed.** Bearer requests could
   receive cached anon variants; `.htaccess` bypass block applied staging+prod,
   manually verified 4/4, survived plugin rewrite activity (TODO.md:53–62,
   checklist §1.6). **Valid fix; manual verification only.**
6. **2026-07-15 — Knee ramp + edge-miss regimes (#53).** 25-VU ceiling: SLA-knee (p95
   >500ms) at ~12–15 req/s; workers plateau 29, RSS 3.01GB. Edge: 243 req/s no-stampede;
   forced-miss origin absorbs ~18.7 true-miss req/s (capacity-model.md:383–447). Redis
   A/B confirmed impossible on this plan. **Valid; hard limit not reached.**
7. **2026-07-15 — Write path, throttles, soak (#56).** Comment-write burst at
   read-parity (p50 303ms); comment throttle exact under 4-way concurrency (80
   admitted); login limiter atomic under a 20-simultaneous race (4 admitted); 30-min
   soak flat, no leak signal (capacity-model.md:449–493). **Valid; comment-create only.**
8. **2026-07-16 — Plugin prune re-run.** Knee moved above tested range: 19.4 req/s at
   25 VU, p95 949→382ms (capacity-model.md:495–528). **Useful-but-limited: single run
   vs single run, tenancy uncontrolled.**
9. **2026-07-16 — LSMCD object-cache A/B.** Win is **latency-side only** (best p50
   234ms); "10× memory win" **retracted** as idle-sample artifact
   (capacity-model.md:530–585). Surfaced the `/members` stale-edge bug (→ trust #93
   EdgeCache fix), the `cache-ttl_rest=604800` root cause, and prod's missing
   object-cache lib. **Valid after correction.**
10. **2026-07-16 — Cron flip + final stage.** `DISABLE_WP_CRON` live on staging with
    two verified out-of-band drivers; final 25-VU stage clean (0%, 4,476/4,476 checks)
    (capacity-model.md:611–645). **Valid; prod not flipped.**
11. **2026-07-16 — Ceiling hunt to 100 VUs.** 25/40/60/80 VU all 0% failures, req/s
    flat 19.2→21.3, p50 240ms→2.88s; 100 VU collapse: 97.03% failed via 9,279 TCP
    RSTs at 102 workers / 9.96GB summed RSS (capacity-model.md:647–722; raw verified).
    Memory ceiling refuted; CPU wall confirmed; ban layer later identified as
    **Hostinger CDN** (operator-confirmed). **Valid.**
12. **2026-07-16 — hPanel plan limits.** 4 cores / 4096MB / 100 workers / 200 procs.
    Corrects "~5 cores" to sampling smear; 4 cores ÷ 20.5 req/s ≈ 195ms CPU/req
    (capacity-model.md:727–750). **Valid (operator-provided).**
13. **2026-07-16 — Plugin-floor A/B + decision.** Floor probes valid (table below);
    **k6 legs VOID** (burst sensor). Wordfence MATERIAL (~75ms ≈ 40% of floor); Rank
    Math INCONCLUSIVE. **Decision (Phillip): keep both pre-launch**; WAF REST-scope
    early-return documented as a future lever with trigger ~15–20 req/s
    (capacity-model.md:761–804). **Floor leg valid; load leg void.**
14. **2026-07-17 — CDN ban layer confirmed** (commit `e215615`); media-CDN work merged
    on the frontend (fe#45), making TODO.md:63 stale.

---

# Verified Conclusions

| # | Conclusion | Verdict | Evidence | Confidence | Caveat |
|---|---|---|---|---|---|
| 1 | CPU is the capacity bottleneck | **Confirmed** | req/s flat 19.2→21.3 at 25→80 VU while CPU pinned; hPanel 4-core cap; 195ms CPU/req arithmetic (capacity-model.md:659–738) | High | Cap is **4 cores** (plan), not 5; "5.0" samples were pcpu smear |
| 2 | DB is not the bottleneck | **Confirmed** | `Threads_running`≈1 in every regime; ≤9 server-wide at knee; ≤4 conns at 10 VU (capacity-model.md:370–372, 403–404) | High | True for read-dominant mixes tested; untested at future data volumes |
| 3 | Custom BCC code is reasonably efficient | **Partially confirmed** | 195ms CPU/req vs ~172ms boot floor → ~25ms median app work; batch prefetchers code-verified; N+1 fixes in commit history | Medium-high | "61→51 queries" = commit message only; "0.0–0.3 q/item" and "12.5→8.2ms SQL" have **no repo artifact** |
| 4 | WP/plugin boot is the dominant per-request fixed cost | **Confirmed** | Floor probes: 172ms full-boot vs endpoint deltas; warm-vs-cold 25 VU near-identical CPU (capacity-model.md:574–585, 770–776) | High | Floor sample n=6/regime |
| 5 | Wordfence contributes substantially to the floor | **Confirmed** | −75ms in regimes C & D (3.5× the ±20ms drift yardstick); ~45ms pre-WP WAF + ~30ms boot ≈ 40% of ~170ms | High | Server-side TTFB only; **capacity gain (~28–33 req/s) is a calculated hypothesis, unmeasured** (k6 leg void) |
| 6 | Keeping Wordfence enabled pre-launch is right | **Confirmed (as decision)** | Documented decision + defined revisit trigger (~15–20 req/s sustained) + reversible 4-line lever in the drawer (capacity-model.md:796–804) | High | Decision, not measurement; this audit does not relitigate it |
| 7 | Degradation is queueing-first, not failure-first | **Confirmed (within tested envelope)** | 0% failures to 80 VU with p50 rising linearly; per-VU rate 0.77→0.27 | High | Wall at 100 VU is plan-cap + CDN bans, **single-IP client**; multi-IP behavior untested |
| 8 | Current host supports a limited beta | **Partially confirmed** | ~19–21 req/s authed origin + 243 req/s edge covers ~2k-DAU planning band | Medium | **Staging-only**; prod runs old plugins, week-long REST TTL, no object cache — parity items must close first; CDN launch-day config unresolved |
| 9 | Measured capacity → active-user estimate | **Not proven as measurement** | req/s ceiling measured; DAU (~2,800; band 1,900–5,700) is **calculated** from two assumed constants: 0.07 req/s per session, 10% peak concurrency (capacity-model.md:703–716) | Medium (as model) | Moves linearly with both assumptions; doc labels it CALCULATED — ~2,000 DAU stays the conservative planning estimate (not a cap); 5,000 **registered** members ≠ 5,000 DAU |
| 10 | Further query optimization < boot/caching/CPU levers | **Confirmed** | MySQL idle everywhere; floor is 85–90% of request CPU; LSMCD showed latency-only win | High | Revisit only if DB growth changes the picture |

---

# Invalid, Weak, or Missing Evidence

**Void tests (never cite as capacity evidence):**
- All ten plugin-A/B k6 legs (`results/ab-*-20260716T180806.out`) — CDN burst sensor
  TCP-reset 100% of sustained load; telemetry confirms box near-idle. The "~28–33
  req/s with Wordfence bypassed" figure is a hypothesis, not a result.
- Local 2026-06-19 absolute numbers — dev-box FPM cliff artifact.

**Published then retracted (already corrected in the doc — do not resurrect):**
- LSMCD "10× less peak memory" (idle-sample artifact; capacity-model.md:554–561).
- Final-stage "0.5 cores / 0.40GB" (pre-run idle samples).
- "~3GB account memory ceiling" (refuted at 9.96GB summed).
- "~5-core LVE cap" (4-core plan limit confirmed).

**Claims without repo artifacts (plausible, unverifiable here):**
- Profile 61→51 queries — exists only as bcc-trust commit `98c3cb1` subject (#88).
- Read-surface audit "0.0–0.3 queries/item" — not found in any doc.
- "SQL 12.5ms→8.2ms" — not found in any doc.
- Plugin-floor TTFB raw probe logs — table survives only in capacity-model.md:770–776.

**Weak/limited:**
- Post-prune improvement (p95 949→382ms) — single run vs single run, multi-tenant
  tenancy uncontrolled; the doc itself says "strong but not controlled".
- All latencies include residential WAN RTT; telemetry is 5s lifetime-averaged pcpu.
- Authed-baseline internal inconsistency: table sums 3,216 reqs, prose says ~4,200.
- p95 tail differences between 07-16 runs are explicitly unattributed.

**Missing durable guards / stale docs:**
- **No automated regression coverage for the Authorization cache-bypass rule** — no
  CI check, no smoke assertion, no scheduled probe. Protection is structural only.
- No external uptime monitor confirmed pointed at `/system/ping`; no CPU-saturation
  or HTTP-error-rate alerting (DegradationAlerter covers app-level events only).
- `docs/TODO.md:63` (media CDN) stale — fe#45 merged 2026-07-17.
- capacity-model.md:812 cites "GOLDEN_PATHS.md §5.6" — that section no longer resolves.

---

# Remaining Coverage Gaps (ranked by launch-decision value)

1. **Auth-cache isolation has no durable guard** — a silent `.htaccess` wipe would
   re-open a data-leak class. Decision enabled: "is the #1 cache-security control
   continuously verified?"
2. **Production parity untested** — every number is staging; prod differs in plugin
   version, REST TTL, object cache, cron. Decision: "is prod actually the system we
   measured?"
3. **Detection of serious failure** — no external monitor wired, no CPU/error-rate
   alert. Decision: "will we know within minutes if launch goes bad?"
4. **Hostinger CDN launch posture** — it banned benign IPs (including the operator's);
   CGNAT/shared-IP users could be banned on launch day. Decision: CDN on or off at launch.
5. **Non-comment write paths** — attestation cast does a synchronous score recompute;
   never load-tested. Decision: whether a write-heavy moment needs a seatbelt check
   (throttles already bound the blast radius).
6. **Multi-hour soak / slow leak** — only 30 min tested. Best answered by launch-week
   telemetry, not synthetic soak.
7. **Multi-IP load & recovery after saturation** — single-IP harness limit; app
   recovered in ~5s but the CDN ban persisted ≥1h. Real traffic answers this.
8. **Frontend/Vercel, browser UX, mobile RTT** — never measured; low risk
   (static/edge-hosted), post-launch RUM territory.
9. **Messaging/notifications/uploads under load** — PeepSo-owned surfaces, not in the
   mix; badges polling is bounded by design. Low value pre-launch.
10. **DB growth effects** — content volumes are tiny pre-launch; monitor after.

---

# Remaining Work

### A. Required before launch

| Item | Why | Component |
|---|---|---|
| A1. Set `cache-ttl_rest` 604800→60 + purge, **both boxes** | Correctness: anon REST content stale up to a week (deleted users, moderation) | LSCWP config (TODO.md:48) |
| A2. Production plugin deploy (trust 1.1.0 → current batch) as a conscious step | Prod lacks months of fixes incl. EdgeCache purge (#93); the measured system is not what prod runs | bcc-trust/core/search via deploy.yml (TODO.md:64) |
| A3. Wire an external uptime monitor to `GET /bcc/v1/system/ping`; set `BCC_DEGRADATION_ALERT_EMAIL` (+webhook for P1) in prod | Ability to detect serious failure; endpoint exists, nothing watches it | bcc-core (bcc-core.php:846+) + operator |
| A4. Durable auth-cache-isolation guard (see Next Recommended Test) | Only manual curls protect the #1 cache-security control | checklist gate + small probe script (needs approval to implement) |
| A5. Decide + record Hostinger CDN on/off for launch; if on, document unban path | Per-IP bans hit real clients; launch-day risk operator-confirmed | hPanel decision (capacity-model.md:688–699) |
| A6. Close deploy-checklist §7 security manual items (Vercel `BCC_OAUTH_BRIDGE_SECRET`, X callback URL, rotate burned §1.1 secrets) | Pre-existing launch blockers, security | testnet-deploy-checklist.md:327–331 |

### B. Recommended before launch

| Item | Why |
|---|---|
| B1. Prod object cache: restore LSCWP `lib/object-cache.php` from official 7.8.1 zip → toggle → verify `wp_using_ext_object_cache()` (TODO.md:49) | Latency-side win (best p50 234ms); staging-proven recipe incl. IPv6 gotcha |
| B2. Prod `DISABLE_WP_CRON` + hPanel minutely cron + Vercel relay secrets (after A2) | Staging-verified pattern; removes cron work from user requests |
| B3. Memory-headroom follow-up ⑦: §1.6 edge-scope widening (LSCWP config) | Cheap capacity on the anon tier (TODO.md:50) |
| B4. Doc hygiene: close TODO.md:63 (fe#45 merged); fix capacity-model.md:812 GOLDEN_PATHS §5.6 dangling ref; reconcile the 3,216-vs-4,200 authed count | Keeps the evidence base trustworthy |
| B5. Define the operator telemetry recipe for launch week (the existing SSH 5s sampler: lsphp count / RSS / CPU) as a runbook snippet | Turns launch monitoring into a 2-minute routine |

### C. After-launch monitoring (real traffic beats synthetic)

| Metric | Source |
|---|---|
| Sustained authed origin req/s and p95 (warm) | access logs / k6-free sampler |
| lsphp worker count + account CPU during peaks | SSH sampler (B5) |
| `/system/ping` uptime + DegradationAlerter events | external monitor (A3) |
| Memory over days (slow leak; supersedes multi-hour soak) | sampler RSS trend |
| 429 rates (comment/login throttles) & error rates | logs / Wordfence + LSCWP stats |
| Edge hit ratio & REST TTL behavior post-A1 | `X-Litespeed-Cache` sampling |
| Real user behavior constants (req/s per session, peak concurrency) to re-base the DAU model | analytics |

### D. Trigger-based scaling work

| Action | Trigger (measurable) |
|---|---|
| Pull the Wordfence WAF REST-scope lever (4-line early-return in `wordfence-waf.php`, scoping WAF off `/wp-json/` only; wp-login/wp-admin/xmlrpc keep WAF; requires the security-risk review below) | Sustained authed origin ≥ **12–15 req/s** during daily peaks, or warm p95 > 500ms sustained ≥ 30 min |
| VPS migration (4 vCPU dedicated + Redis) | CPU ≥ **~70% of 4 cores** sustained ≥ 30 min on multiple days, or workers ≥ 40 sustained, or lever above already pulled and p95 still > 500ms |
| Knee re-ramp >25 VU (memory-headroom ⑧) — only meaningful **after** a system change (lever pulled, VPS, or CDN change) | Blocked anyway on Hostinger burst-exemption; run on the changed system, not this one |
| Redis A/B | VPS-only (plan offers no Redis) |
| Multi-hour soak | Before first paid-marketing spike, or on any RSS-trend alert |
| Attestation-cast write burst test | If launch telemetry shows attestation writes clustering (score recompute is synchronous) |

### E. No further action — close these

- Anon edge capacity (243 req/s, no TTL stampede) — proven; stop retesting.
- Capacity ceiling ramp on this tier — answered (CPU wall, worker cap, CDN layer); a repeat re-tests Hostinger's anti-abuse, not the app.
- Comment throttle exactness + login-limiter atomicity — verified under concurrency.
- 30-min soak — done; longer soak is trigger-based (D).
- Query-count/N+1 work — diminishing returns confirmed; stop here.
- `/members` stale-edge fix (#93) — shipped and live-verified.
- Plugin-floor A/B — decision made; do **not** re-run its k6 leg until a burst exemption exists.
- Media CDN (fe#45) — merged; close TODO.md:63.

---

# Next Recommended Test

**No additional pre-launch synthetic load test is justified.** The origin ceiling,
knee, write seatbelts, and edge tier are measured; the two open capacity questions
(>25-VU hard-fail shape excluding the CDN, Wordfence-lever gain) are blocked on a
Hostinger burst exemption and are only decision-relevant at traffic levels the
triggers in section D already cover.

The one justified pre-launch verification is **not a load test**:

**Auth-cache isolation probe (staging + prod)**
- **Question:** does the LSCache Authorization-bypass rule still hold, continuously?
- **Environment:** staging and production, over normal HTTPS.
- **Traffic model:** 4 sequential curls per box per run — bare `GET /wp-json/bcc/v1/cards`
  ×2 (expect `X-Litespeed-Cache: hit` on the 2nd), then the same URL with
  `Authorization: Bearer <dummy>` ×2 (expect **no** `hit` on either). Exactly the
  checklist §1.6 protocol, scripted.
- **Safety limits:** ≤8 requests/run, ≥1s spacing — sequential single requests pass
  the CDN/burst layer by design (single requests were unaffected even during the A/B);
  no VUs, no sustained connections, so Hostinger anti-abuse is never in play.
- **Success:** anon second request HIT **and** both Bearer requests non-HIT, both boxes.
- **Abort/alarm:** any Bearer HIT → treat as P0 cache-isolation regression; any
  non-200 → investigate before rerun.
- **Cadence/placement (proposal, needs approval to implement):** a ~30-line script in
  `scripts/`, run (a) as a required gate in the deploy checklist and (b) weekly via
  any scheduler; optionally an assertion in the bcc-smoke Playwright suite.
- **Decision from results:** pass → guard closed durably; fail → restore the
  `.htaccess` block from the timestamped backup and investigate what wiped it.

**Wordfence-lever test (future, defined but NOT approved to run):** after pulling the
WAF REST-scope early-return on staging, repeat the standard 25-VU authed stage and
compare req/s and p95 to the 07-16 warm baseline. **Security risks to review first:**
REST endpoints lose WAF request inspection (SQLi/RCE pattern filtering) — compensating
controls are BCC's own input validation, auth, and rate limits, and WAF retention on
wp-login/wp-admin/xmlrpc; Wordfence scanning/2FA unaffected. Requires the Hostinger
burst exemption to produce a valid load leg. Not a current recommendation.

---

# Launch and Scaling Thresholds

Using only collectible measurements (SSH 5s sampler, hPanel, `/system/ping`, logs):

| Signal | 🟢 Green | 🟡 Yellow (act within days) | 🔴 Red (act now) |
|---|---|---|---|
| Account CPU (of 4 cores) | < 2.0 sustained | 2.0–3.0 sustained ≥ 30 min | > 3.0 sustained ≥ 30 min |
| lsphp workers | < 25 | 25–50 sustained | > 60, or approaching 100 |
| Authed origin p95 (warm, server-side or low-RTT client) | < 500ms | 500ms–1s sustained | > 1s sustained or climbing |
| HTTP error rate (5xx + unexpected 429) | < 0.5% | 0.5–2% | > 2%, or any TCP-reset pattern |
| `/system/ping` | 200 | intermittent 503 | sustained 503 |
| Authed origin req/s | < 8 | 8–15 (approaching lever trigger) | ≥ 15–20 → pull Wordfence lever, then VPS |
| DegradationAlerter | quiet | single subsystem alert | P1 subsystem alert (account_security_mail / auth_mail) |

Yellow actions: pull the Wordfence REST lever (documented, reversible), schedule VPS.
Red actions: lever immediately if not pulled + start VPS migration; check CDN ban
symptoms (TCP resets with clean debug.log = infrastructure layer, not app).

---

# Final Prioritized Checklist

| # | P | Blocker | Task | Done when |
|---|---|---|---|---|
| 1 | P0 | Yes | A1: `cache-ttl_rest`=60 + purge on staging **and** prod | `wp option get litespeed.conf.cache-ttl_rest` = 60 both boxes; fresh anon REST entry expires ≤60s |
| 2 | P0 | Yes | A4: script + gate the auth-cache isolation probe | Probe passes on both boxes; step added to deploy checklist; scheduled run exists |
| 3 | P1 | Yes | A6: §7 secrets/OAuth manual items | All three checklist boxes at testnet-deploy-checklist.md:327–331 checked |
| 4 | P1 | Yes | A2: conscious prod plugin deploy (trust/core/search current batch) | Prod plugin versions == staging; post-deploy health gates (§5) green |
| 5 | P1 | Yes | A3: external monitor on `/system/ping` + alert email/webhook set in prod | Test alert received; monitor dashboards show the endpoint |
| 6 | P1 | Yes | A5: CDN launch posture decided + recorded | Decision + unban procedure written into deploy checklist |
| 7 | P1 | No | B1: prod object-cache restore + LSMCD toggle | `wp_using_ext_object_cache()` true on prod; cross-process cache get verified |
| 8 | P1 | No | B2: prod cron flip (after #4) | `DISABLE_WP_CRON` true on prod; hPanel minutely cron + Vercel relay both verified firing |
| 9 | P2 | No | B3: edge-scope widening (⑦) | LSCWP config change applied; anon hit-ratio sampled before/after |
| 10 | P2 | No | B5: launch-week telemetry runbook snippet | Sampler one-liner + thresholds table added to runbook |
| 11 | P2 | No | B4: doc hygiene (TODO:63, GOLDEN_PATHS ref, 3,216-vs-4,200 note) | All three corrections merged |
| 12 | P3 | No | D-items live in the drawer with triggers | No action until a trigger fires |

---

# Bottom Line

1. **What is actually left to do?** Configuration and detection, not testing: fix the
   week-long REST edge TTL on both boxes, deploy current plugins to prod, point a
   monitor at `/system/ping` with alert email set, make the auth-cache check durable,
   decide the CDN posture, and close the three old secrets/OAuth checklist items.
2. **What can wait?** Prod object cache and cron flip (recommended, staging-proven,
   not blocking), the edge-scope widening, every remaining synthetic test (>25-VU
   re-ramp, multi-hour soak, attestation write burst, Wordfence-lever measurement) —
   all trigger-gated.
3. **What should we stop spending time on?** Re-running load tests on this tier
   (the ceiling is known and re-tests mostly probe Hostinger's anti-abuse layer),
   further query-count optimization (MySQL is idle; the floor is boot cost), and
   re-litigating the Wordfence decision before traffic approaches ~12–15 req/s.
4. **What metric says upgrade the server?** Sustained account CPU > ~70% of 4 cores
   (≈2.8–3.0) during daily peaks for 30+ minutes across multiple days — with the
   Wordfence REST lever pulled first (it's the cheaper 40% recovery). Secondary
   confirmations: workers ≥ 40 sustained, warm authed p95 > 500ms sustained.
5. **Single next action:** run the two `wp option` commands to set
   `cache-ttl_rest=60` on staging and prod (60 seconds of operator work, closes the
   only active correctness defect), then script the auth-cache isolation probe.

---

# Addendum — 2026-07-19 staging-readiness phase (executed)

Scope: **staging only**; production frozen until explicitly authorized.

1. **REST-TTL defect — already fixed on staging, now live-verified.**
   `litespeed.conf.cache-ttl_rest` read back as **60** (set + purged
   2026-07-16); fresh anon REST entry measured **miss → hit at +4s →
   expired by +76s**, edge advertising `x-litespeed-cache-control:
   public,max-age=60`. No write or purge was needed on 2026-07-19.
   Production remains at 604800 and remains untouched. TODO.md:48 updated.
2. **Auth-cache isolation probe built + passing:**
   `scripts/auth-cache-isolation-probe.sh` (staging-only; refuses the
   production hostname, exit 3). Live staging run: anon miss→**hit**;
   both dummy-Bearer requests on the same primed URL **miss** → PASS
   (exit 0). Bearer HIT = exit 2 = P0.
3. **Checklist §1.6** now carries the probe as a **required pre-production
   gate** plus the production-freeze note. A weekly GitHub Actions check
   (`.github/workflows/staging-cache-probe.yml`) is **prepared but
   deliberately uncommitted** — inert until committed/pushed with approval.
4. **Capacity wording corrected** in capacity-model.md ("Population
   terminology" section): registered members ≠ DAU ≠ peak sessions ≠
   in-flight requests; 5,000 registered is the launch target; ~2,000 DAU
   is the conservative planning estimate; 5,000 DAU unproven; measured
   facts (~19–21 authed origin req/s, ~243 edge req/s) preserved; all
   evidence staging-only.

Supersedes "Bottom Line #5" above: staging's half of that action is done;
the production half awaits the explicitly authorized production phase.
