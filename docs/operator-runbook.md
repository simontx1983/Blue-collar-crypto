# Operator Runbook

**Audience:** Phillip + Tialuxe. Both full superuser. Two-engineer team.
**Format:** Lookup table — "I see X, what do I do?" Each row points at the admin surface that owns the signal and the next concrete action.
**This is NOT:** A verification doctrine. For "prove the platform works end-to-end," use [GOLDEN_PATHS.md](GOLDEN_PATHS.md) — it walks every load-bearing seam systematically. This runbook is for incident triage at 2am, not periodic verification.
**Last updated:** 2026-05-27 (two-engineer audit MEDIUM #7).

---

## First place to look when something feels off

Open these in order:

1. **External uptime probe** — Better Stack monitor on `/wp-json/bcc/v1/system/ping`. If RED here, the site is unreachable or `/system/health` itself is reporting degraded. Skip to §1.
2. **BCC System → Health.** Color-coded section badges. Any red row → §2 (find the matching scenario below).
3. **BCC System → Cron.** Any "MISSING" rows in the canonical-hooks table → §3.
4. **External monitoring (provider-side)** — Alchemy / Helius / Better Stack dashboards. If a third-party provider is degraded, no amount of admin clicking helps; switch to comms mode (alert users, defer launches).
5. **DegradationMetrics current+previous hour** (bottom of Health page) — any nonzero subsystem highlighted in pink? → §4.

If nothing is red anywhere but a user reports a problem: go to the **Trust Engine → Activity** cluster and search for the user.

---

## §1 — Site is unreachable / `/system/ping` returns 503

| Step | Action |
|---|---|
| 1 | Confirm the wp-admin login page loads at all. If not, hosting issue — check Local-by-Flywheel / Hostinger panel. |
| 2 | If wp-admin loads but `/system/ping` is 503: open **BCC System → Health**. The 503 means a critical subsystem is on a NullObject fallback. Look at the Trust subsystem row — `BCC_ENCRYPTION_KEY` missing locks out all non-admin users. |
| 3 | Confirm `BCC_ENCRYPTION_KEY` is defined in wp-config.php. If missing, restore from secret storage and reload PHP. |
| 4 | If recalc cron is >15 min overdue: jump to §3 (cron). |
| 5 | If read-model dirty queue is stuck >10 min: BCC System → Developer → Read Model panel will show `Pending recalculations` count. If high (>1000), the recalc worker isn't draining. Investigate `bcc_trust_hourly_recalc` cron. |

---

## §2 — Health page is RED somewhere

The Health page is structured the same way every time. Match the failing section to one of:

### Cache section red

- **`Persistent object cache: NOT ACTIVE`** — Redis / Memcached not connected. Throttle::isReady() fails-closed → all rate-limited actions deny by default. Restore Redis before anything else.
- **`Rate-limiter degraded: DEGRADED — fail-closed`** — rate-limiter backend offline. Same root cause as above; restoring Redis fixes both.
- **`Rate-limit rows: > 50000`** — wp_options bloat. The `bcc_core_rl_cleanup` cron sweeps these every 30 min. If high, check Cron page (next section) for a stalled cleanup hook.

### Read model section red

- **`Dirty pages: > 500`** — recalc worker isn't draining. The `bcc_trust_hourly_recalc` cron should be processing these. If it's been stalled for hours and Cron page shows the hook is scheduled but not running → host-level cron issue (DISABLE_WP_CRON might be on without an external trigger). On Hostinger / prod, an external system-cron should hit `wp-cron.php`.
- **`Oldest age (s): > 1800`** — the dirty queue is taking >30 minutes to drain. Same cron-stall investigation as above.

### Recalculation queue red

- **`Source: error`** — the trust-engine's RecalcQueueRead returned a query error. Database issue likely. Check the database section below.
- **`Source: unavailable`** — service-locator binding for `RecalcQueueReadInterface` is on NullObject. Trust engine plugin is not active or boot order is wrong. Reactivate bcc-trust.

### Database red

- **`Utilization > 80%`** — MySQL connection pool nearing exhaustion. Look at `Threads_running` — if also high, queries are slow / stuck. Identify long-running queries via Adminer → Processes.
- **`Threads connected vs max`** at the limit → connection leak somewhere. Restart PHP-FPM as the safety move.

### Services red

- **`TrustReadService: NullObject`** — the trust engine is inert. `BCC_ENCRYPTION_KEY` is the most common cause (see §1 step 3).
- **`DisputeAdjudicator: NullObject`** — bcc-trust isn't active or its boot failed. Check the WP plugins page for plugin errors.

### Trust subsystem red

- **`encryption_key_defined: false`** — see §1 step 3.
- Any other false → the binding above is broken; usually plugin-activation order issue.

### wp_cron red

- **`disabled: NO`** — on prod, internal wp-cron is firing on every page load. That's expected on dev, but on prod with the cron volume BCC has, it tanks request latency. Confirm `DISABLE_WP_CRON` is true in prod wp-config and an external cron is hitting `wp-cron.php` every minute.

---

## §3 — Cron drift detected

**BCC System → Cron** shows a red banner with N canonical hooks not currently scheduled.

| Step | Action |
|---|---|
| 1 | Identify which hooks are missing. Sort by severity — anything in `bcc_trust_hourly_recalc` / `bcc_trust_process_recalculations` / `bcc_helius_dedupe_sweep` is operationally hot. |
| 2 | The plugins_loaded self-heal in bcc-trust.php should re-register on the next page load. Refresh the Cron page after ~30 seconds — if drift persists, the self-heal itself is broken. |
| 3 | Force re-register: deactivate then reactivate bcc-trust from the WP plugins page. This fires activation hooks which re-schedule everything. (Confirm this is the path forward in non-prod first.) |
| 4 | If individual hooks won't stay scheduled, check `wp_options` for any rogue `wp_clear_scheduled_hook` calls — the V-08 retired-hooks list in CronService::register_cron_events is the only sanctioned clear path. |
| 5 | For one-time recovery: WP-CLI `wp cron event schedule <hook> now hourly` works as a manual repair. |

**See also:** GOLDEN_PATHS.md §11 (cron verification).

---

## §4 — DegradationMetric subsystem firing

Bottom of Health page shows the 18-subsystem table. Hot rows (nonzero current OR previous hour) get a pink background.

| Subsystem | What nonzero means | First action |
|---|---|---|
| `throttle.activation` | Rate limiter fail-closed (Redis down) | Restore Redis |
| `null_trust_read.*` | Trust engine on NullObject (encryption key missing) | §1 step 3 |
| `peepso_absence.*` | PeepSo class or method missing for a specific writer | Check PeepSo plugin is active + at expected version |
| `search_lkg.unavailable_503` | Search returned 503 (LKG cache also empty) | Force FT-index rebuild: BCC System → Developer → Search Index → Rebuild |
| `read_model_fallback.legacy_aggregation` | RM read fell back to live aggregation | Recalc cron stalled — see §3 |
| `audit_log_swallow.log_write_failed` | Audit log insert returned false | DB write-failure on audit table. Check disk space + table schema |
| `account_security_mail.*_send_failed` | wp_mail failure on credential-rotation canary email | **P1 alert posture** — the user who was supposed to receive a canary notification did NOT. Investigate mail backend immediately. The audit_log row is the only remaining trail. |
| `legacy_ajax.*` | A deprecated AJAX endpoint was hit by an actual caller | Investigate WHICH endpoint; the legacy_ajax cluster was retired with no expected callers |
| `cron_dispatch.endorsement_fraud_analyzer` | wp_schedule_single_event returned false for fraud analysis | DB write-failure on wp_options. Endorsement bonus may stick on a fraudulent endorser. Manual replay needed. |
| `cron_dispatch.vote_job_dispatcher` | Same, for the composite post-vote job | All four sub-tasks for that vote stranded. Manual replay needed. |
| `gated_group_provision.peepso_absent` | PeepSo Groups missing | Same as `peepso_absence` — check plugin state |
| `gated_group_provision.no_admin_owner` | No administrator user exists to own auto-provisioned groups | Restore at least one admin |
| `gated_group_provision.group_create_failed` | `new PeepSoGroup` returned 0-id | PeepSo Groups subsystem unhealthy even though class loaded — investigate PeepSo |
| `helius_dedup.replay_skipped` | Helius webhook replayed | Either legitimate Helius double-send, or attacker replaying with stolen auth header. Check Webhooks page for delivery patterns + source IPs. |
| `polkadot_verify.secret_missing` | `BCC_INTERNAL_VERIFY_SECRET` not defined | Add to wp-config.php |
| `polkadot_verify.frontend_url_missing` | `BCC_FRONTEND_ORIGIN` not defined | Add to wp-config.php (canonical-mint value — first entry of the comma-separated allowlist) |
| `polkadot_verify.route_unreachable` | Next.js verify route not reachable | Frontend down or wrong URL pinned in wp-config |
| `polkadot_verify.route_error_status` | Verify route returned non-200 | Check Next.js logs |
| `polkadot_verify.route_malformed_body` | Response shape mismatch | Next.js verify route changed contract — sync with frontend |

---

## §5 — Webhook deliveries dropping (Helius / Solana)

**BCC System → Webhooks** is the diagnostic surface.

| Step | Action |
|---|---|
| 1 | Look at "Last delivery" badge. If >4h (red), Helius isn't reaching us. If >1h (amber), monitor; could be quiet period. |
| 2 | Look at "Recent deliveries" table for the outcome distribution. Lots of `auth_failed` = probe storm. Same source IP repeated = targeted; different IPs = scanner. |
| 3 | If lots of `processed` rows with 0 events: Helius is sending pings (empty payloads) which is normal. Real activity should show non-zero `events` column. |
| 4 | If "Webhook secret: NOT CONFIGURED" — `BCC_HELIUS_WEBHOOK_SECRET` is missing in wp-config. Add it (and configure the same value on Helius side). |
| 5 | Cross-reference with the `helius_dedup.replay_skipped` DegradationMetric. Sustained nonzero = either Helius is double-sending (their side) or attacker replays (our side). |
| 6 | Confirm the Helius webhook is configured to forward `confirmed` or `finalized` commitment level (not `processed`). Pre-finalized commitments can be reorged; an attacker who can briefly produce a reorged tx could land a transient holdings row. One-time provider-side config — verify on `https://dashboard.helius.dev/webhooks` if drift is suspected. |

---

## §6 — Disputes queue stuck

**Trust Engine → Disputes**. List shows dispute status. Use the "Reviewing" filter.

| Step | Action |
|---|---|
| 1 | If many disputes are in "Reviewing" with the panel quorum incomplete, the auto-resolve sweep handles timeouts. Check Cron page for `bcc_disputes_auto_resolve` (daily). |
| 2 | For an individual stuck dispute, open its detail page. Force-resolve buttons accept-or-reject with the per-dispute nonce. **Document why** in the dispute note before force-resolving. |
| 3 | If a panelist's votes aren't being counted, check `bcc_disputes_reconcile_orphans` cron — it sweeps every 5 minutes and fixes silent-enqueue failures. |

---

## §7 — Holder-group provisioning miss

User says "I hold the NFT but I'm not in the group."

| Step | Action |
|---|---|
| 1 | Confirm the collection is verified: **BCC System → Verify Collections** — collection should have `is_verified=1` and a backing PeepSo group. |
| 2 | Confirm the user has opted into auto-join: their user_meta `bcc_auto_join_eligible_groups` should be `'1'`. If not, they need to enable auto-join in the frontend settings — manual reconcile won't help. |
| 3 | If they HAVE opted in: **BCC System → Holder Groups → Reconcile specific user** → enter their user ID. |
| 4 | If reconcile returns "already up-to-date" but they still aren't in the group: the eligibility filter doesn't see their NFT holdings. Check the indexer (BCC System → Chains → Validators for their chain, or NFT Indexer status for collections). |
| 5 | Last resort: WP-CLI `wp eval` call to `NftGroupGateService::reconcileForUser(int)` with explicit chain refresh first. |

---

## §8 — Repair operations on prod

**Default posture: Repair is DISABLED on prod.** `BCC_REPAIR_ENABLED` constant must be set in wp-config.php for any repair action to run. The constant is intentionally absent on prod wp-config.

To run a repair on prod:

| Step | Action |
|---|---|
| 1 | Document WHY in writing (Slack / shared doc / commit message). Two engineers means a paper trail — Tialuxe wakes up next morning, has to know what Phillip did at 2am. |
| 2 | SSH into prod, edit wp-config.php, add `define('BCC_REPAIR_ENABLED', true);`. |
| 3 | Open BCC System → Repair. Confirm the page renders the action buttons (gate notice gone). |
| 4 | Run the specific repair. Logger::info captures the operator + action + counts to the activity log. |
| 5 | **Remove the constant** from prod wp-config.php immediately. Don't leave the prod gate open. |

If Tialuxe sees `BCC_REPAIR_ENABLED` accidentally left on prod overnight, treat as a P1 — remove and confirm with Phillip what was run.

---

## §9 — API key suspected compromised

| Step | Action |
|---|---|
| 1 | Open **BCC System → API Keys** to confirm which key (status only, never the value). |
| 2 | Rotate the key on the provider's portal (Alchemy / Helius / Subscan / GitHub / etc.). |
| 3 | Edit wp-config.php with the new value. Reload PHP. |
| 4 | Confirm the affected subsystem recovers — for Helius, watch BCC System → Webhooks for next delivery. For Alchemy, watch the NFT indexer. |
| 5 | **Do not announce** which key was rotated in any public channel. |
| 6 | If `BCC_ENCRYPTION_KEY` is the suspected leak: that's a P0 — ALL user-encrypted blobs use it. Rotation requires migration plan; do not rotate casually. |

---

## §10 — Things NOT to do at 2am

- **Do not run Repair → Recalculate Scores on prod casually.** It rewrites every page score. If a stale read-model is the symptom, fix the cron first. Recalc is a last-resort, "cron has been dead for days" tool.
- **Do not "Refresh all chains" while triaging.** Burns Alchemy / Helius CUs and can mask the actual incident with rate-limit fallout.
- **Do not deactivate bcc-trust on prod casually.** All non-admin users get locked out as soon as it deactivates (NullTrustReadService::isSuspended returns true). If reactivating is the goal, do it directly via the plugins page, not deactivate+reactivate.
- **Do not change `BCC_ENCRYPTION_KEY` without a migration plan.** Existing encrypted blobs become unrecoverable.
- **Do not commit + push fixes from the prod box.** Fix locally, test, push. Prod-box edits are how diffs go missing.

---

## Escalation

Two-engineer team — no formal escalation matrix beyond "ping the other one." If both are unreachable and it's a P0, the platform's DegradationMetric system + external uptime probe will continue to fire, and any user-facing failure cascade is bounded by NullTrustReadService refusing all non-admin requests (the platform fails closed by design).

For incidents involving wallet linking, on-chain fraud, or holder-group abuse, capture before/after state with BCC System → Developer → Read Model snapshot + the relevant Activity Log filter, even if you don't have time to file a full incident report.

---

## See also

- [GOLDEN_PATHS.md](GOLDEN_PATHS.md) — full verification doctrine, 14 sections, copy-paste commands.
- [pattern-registry.md](pattern-registry.md) — what canonical implementations live where (Search, Trust, Onchain, Disputes…).
- [cron-registry.md](cron-registry.md) — all scheduled hooks + intervals.
- [api-contract-v1.md](api-contract-v1.md) — REST envelope + error code reference.
