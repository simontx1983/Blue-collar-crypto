# BCC Cron Registry

Single greppable inventory of every `bcc_*` cron hook and custom
interval registered by BCC plugins. Generated from the codebase on
2026-05-09.

This is **documentation only** — no automation reads this file. Its
purpose is to answer "what runs hourly?" / "where is hook X
registered?" without a multi-file grep, because today the registration
sites are spread across activation, runtime constructors, and service
classes (Stabilization Cleanup Plan §3 — V-10).

> **Constitutional reference**: Platform Constitution §VIII.29 — cron
> events under `bcc-trust.php` activation are the canonical schedule
> registry. New events must register there or in their service-class
> `__construct`. No ad-hoc scheduling.

When a new hook is added, append to the appropriate table below in the
same commit. When a hook is retired (cleared via
`wp_clear_scheduled_hook`), move it to the "Retired hooks" section.

---

## Recurring hooks

| Hook | Interval | Registered in | Handler |
|---|---|---|---|
| `bcc_core_rl_cleanup` | `bcc_thirty_minutes` | [bcc-core/bcc-core.php:288](../app/public/wp-content/plugins/bcc-core/bcc-core.php#L288) | bcc-core/bcc-core.php:226 — `OptionCleanupRepository::deleteExpiredRange` for `_bcc_rl_*` and `_transient_bcc_rl_*` rows. |
| `bcc_search_ensure_ft_index` | `hourly` | [bcc-search/bcc-search.php:96](../app/public/wp-content/plugins/bcc-search/bcc-search.php#L96) | bcc-search/bcc-search.php:105 — `SearchRepository::ensureFulltextIndex`. Self-deschedules once `bcc_ft_index_v2_installed` option is set. |
| `bcc_trust_daily_cleanup` | `daily` | [bcc-trust/app/Domain/Core/Services/CronService.php:581](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/CronService.php#L581) | bcc-trust/bcc-trust.php:311 — `cronService()->dailyCleanup()`. |
| `bcc_trust_hourly_recalc` | `hourly` | CronService.php:582 | bcc-trust/bcc-trust.php:314 — `cronService()->hourlyRecalc()`. |
| `bcc_trust_daily_ml_update` | `daily` | CronService.php:583 | bcc-trust/bcc-trust.php:317 — `cronService()->dailyFraudRefresh()`. |
| `bcc_trust_daily_graph_update` | `daily` | CronService.php:584 | bcc-trust/bcc-trust.php:320 — `cronService()->dailyGraphUpdate()`. The actual graph + ring computation is performed by `BCC\Trust\Core\Security\TrustGraph::batchCalculateAllRanks` + `::detectVoteRings` + `::detectEndorsementRings` (Phase B V-18 classification). |
| ~~`bcc_trust_daily_vesting`~~ | — | RETIRED 2026-07-31 | Rank Phase 2 (audit #10): the graduation recompute erased velocity-capped `vested_weight` corrections. Handler deleted; hook moved to `cleanup_only` in cron-hooks.php. Vote-TIME vesting stays live until the Phase 6 weight cutover. |
| `bcc_trust_process_recalculations` | `bcc_five_minutes` | CronService.php:586 | bcc-trust/bcc-trust.php:326 — `cronService()->processRecalculations()`. |
| `bcc_trust_feed_hot_warm` | `bcc_one_minute` | CronService.php `scheduleAll()` jobs map | bcc-trust/bcc-trust.php — `cronService()->warmHotFeed()`; rebuilds the anonymous `/feed/hot` first-page payload cache (`FeedRankingService::warmHotFeed`, TTL 300s, key includes the §K1-C hidden-activity generation). Failure is benign: requests fall back to inline build. |
| `bcc_trust_daily_maintenance` | `daily` | CronService.php:587 | bcc-trust read-model sync safety net. |
| `bcc_trust_weekly_digest` | `bcc_weekly` | CronService.php:588 | bcc-trust/bcc-trust.php:329 — `digestService()->sendWeeklyDigest()`. |
| `bcc_rank_confirmation_sweep` (`RankScheduler::EVENT_CONFIRMATION_SWEEP`) | `bcc_five_minutes` | [bcc-trust/app/Domain/Rank/Services/RankScheduler.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Rank/Services/RankScheduler.php) (self-heal `boot()` on every request; DisputeScheduler pattern) | `ApprenticeReadinessService::runConfirmationSweep` — Rank Phase 5 R1 24h Apprentice-confirmation resolver (awards at `due_at` iff the six R1 conditions hold). Writes `bcc_rank_confirmation_sweep_last_run`/`_last_success` heartbeat options. |
| `bcc_rank_daily_evaluate` (`RankScheduler::EVENT_DAILY_EVALUATE`) | `daily` | [RankScheduler.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Rank/Services/RankScheduler.php) (same self-heal boot) | `RankPromotionEngine::runDailyEvaluate` — Rank Phase 5 promotion/demotion sweep over ranked members (rank-events assessment + trust windows + decay + recovery deadline; Phase 8 folds capped active misconduct-finding penalties + the lowest active rank ceiling into the same assessment). Since Rank Phase 8 (2026-08-04) the recovery + decay notices also ride this sweep — **no separate cron**: `bcc_rank_recovery_reminder` fires at exactly 30 and 7 whole days before the §14.1 recovery deadline, and `bcc_rank_decay_warning` fires while §12.3 inactivity decay is active (30-day per-user usermeta re-notify throttle). All bell-only self-notifications, deadline-framed per §2.7. Writes `bcc_rank_daily_evaluate_last_run`/`_last_success` heartbeat options. |
| `bcc_rank_poll_close_sweep` (`RankScheduler::EVENT_POLL_CLOSE_SWEEP`) | `hourly` | [RankScheduler.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Rank/Services/RankScheduler.php) (same self-heal boot) | `PollService::closeDuePolls` — Rank Phase 6 meaningful-voting close sweep: evaluates open polls past their day-7 binding window (dual quorum 10 voters & 7.5 counted weight + 60% effective-weight majority → `passed`/`failed`) and closes polls past day-90 expiry that never bound as `inconclusive`. Decisive dispute outcomes route via `bcc_trust_poll_closed` → `DisputeVoteService::onPollClosed` → the existing async dispute resolution. Failure records `rank_scoring`/`poll_close_failed`; writes `bcc_rank_poll_close_sweep_last_run`/`_last_success` heartbeat options. |
| `bcc_onchain_daily_refresh` | `daily` | [bcc-trust/bcc-trust.php:1190](../app/public/wp-content/plugins/bcc-trust/bcc-trust.php#L1190) | bcc-trust onchain — daily holdings refresh sweep. |
| `bcc_onchain_retry_bonus` | `hourly` | [bcc-trust/bcc-trust.php:1193](../app/public/wp-content/plugins/bcc-trust/bcc-trust.php#L1193) | bcc-trust onchain — bonus-application retry. |
| `bcc_gated_group_provision` | `daily` | [bcc-trust/bcc-trust.php:1196](../app/public/wp-content/plugins/bcc-trust/bcc-trust.php#L1196) | bcc-trust/bcc-trust.php:337 — `gatedGroupProvisioningService()->provisionAll()`. **Holder-group write surface — see §II.7 + `peepso-write-guard` skill.** |
| `bcc_gated_group_reconcile_sweep` | `twicedaily` | [bcc-trust/bcc-trust.php:1203](../app/public/wp-content/plugins/bcc-trust/bcc-trust.php#L1203) | bcc-trust holder-group reconcile sweep. **Same write-surface caveat as `bcc_gated_group_provision`.** |
| `bcc_nft_eth_indexer_tick` | `bcc_one_minute` | [bcc-trust/bcc-trust.php:1209](../app/public/wp-content/plugins/bcc-trust/bcc-trust.php#L1209) | bcc-trust/bcc-trust.php:364 — `NftEthIndexerWorker::runAllChains`. Hook-name constant: `\BCC\Trust\Onchain\Workers\NftEthIndexerWorker::CRON_HOOK`. |
| `bcc_helius_dedupe_sweep` | `bcc_five_minutes` | [bcc-trust/bcc-trust.php:1218](../app/public/wp-content/plugins/bcc-trust/bcc-trust.php#L1218) | bcc-trust onchain — Helius signature replay-protection LRU eviction. |
| `bcc_nft_enrichment_tick` | `bcc_five_minutes` | [bcc-trust/bcc-trust.php:1229](../app/public/wp-content/plugins/bcc-trust/bcc-trust.php#L1229) | `\BCC\Trust\Onchain\Services\NftEnrichmentService::CRON_HOOK`. Backfills name + image_url on freshly-indexed rows. |
| `bcc_watch_batch_sweep` (`WatchBatchAggregator::SWEEP_HOOK`; legacy hook name `bcc_pull_batch_sweep` retired 2026-05-13 — self-heal `wp_clear_scheduled_hook` on `plugins_loaded`) | `bcc_pull_batch_sweep_minute` (every minute; interval slug retained for back-compat) | [bcc-trust/app/Domain/Core/Plugin.php:1320](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Plugin.php#L1320) | bcc-trust/app/Domain/Core/Plugin.php:1327 — `watchBatchAggregator()->sweep()`. |
| `bcc_disputes_auto_resolve` (`DisputeScheduler::EVENT_AUTO_RESOLVE`) | `daily` | [bcc-trust/app/Domain/Disputes/Services/DisputeScheduler.php:23](../app/public/wp-content/plugins/bcc-trust/app/Domain/Disputes/Services/DisputeScheduler.php#L23) | DisputeScheduler — daily poll→dispute reconciliation backstop (Rank Phase 6; advisory-locked, bounded batch of 50). For each dispute stuck in `reviewing` >1h, `DisputeVoteService::reconcileReviewingDispute` self-heals a missing poll (submit-time open failed), skips an open one (the hourly `bcc_rank_poll_close_sweep` owns closing), and re-drives the outcome of a closed poll whose close hook or async job was lost. Writes `bcc_disputes_auto_resolve_last_run`/`_last_success`/`_last_failure`. |
| `bcc_disputes_reconcile_orphans` (`DisputeScheduler::EVENT_RECONCILE`) | `bcc_five_minutes` | [DisputeScheduler.php:20](../app/public/wp-content/plugins/bcc-trust/app/Domain/Disputes/Services/DisputeScheduler.php#L20) | DisputeScheduler reconcile-sweep — covers cron lag + Action-Scheduler silent-enqueue failures. Writes `bcc_disputes_reconcile_last_run`/`_last_success` heartbeat options. (Monitors watched the wrong name `bcc_disputes_reconcile` until 2026-07-06 — false MISSING alarm.) |
| `bcc_trust_deferred_rm_sync` | `bcc_thirty_seconds` | [bcc-trust/app/Domain/Core/Services/PageReadModelSync.php:109](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/PageReadModelSync.php#L109) | PageReadModelSync — deferred read-model rebuild for staleness recovery. |
| `bcc_trust_divergence_state_sweep` | `daily` | [bcc-trust/bcc-trust.php](../app/public/wp-content/plugins/bcc-trust/bcc-trust.php) (activation + `plugins_loaded` self-heal) | `PolarizationTransitionNotifier::sweep()` — daily walk of attestation-touched + dispute-touched candidates over a 48h window, classifies each via `DivergenceStateClassifier`, persists to `bcc_target_divergence_state` sidecar, fires §J.7 `divergence_state_warning` notifications on transitions INTO `polarizing`/`disputed`. Per-(recipient, target, state) 24h coalescing via `last_notified_at`. Fire-and-forget posture: per-target failures contained, sweep degrades silently. PR-8b. |

Per-chain `bcc_chain_refresh_*` hooks are registered dynamically via
`\BCC\Trust\Onchain\Services\ChainRefreshService::schedule_crons()`
([bcc-trust/app/Domain/Onchain/Services/ChainRefreshService.php:83](../app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Services/ChainRefreshService.php#L83))
during activation and runtime. Hook names are derived from the
chain row at registration time; see ChainRefreshService for the
naming pattern.

## Single-event hooks (transactional async)

These use `wp_schedule_single_event` for fire-once-soon jobs;
deduplication is via WordPress's `(hook, serialized args)` keying.

| Hook | Scheduled in | Use |
|---|---|---|
| `bcc_trust_initial_user_sync` | [bcc-trust/bcc-trust.php:1181](../app/public/wp-content/plugins/bcc-trust/bcc-trust.php#L1181) (T+60s on activation) | One-shot post-activation user sync. |
| `bcc_trust_initial_read_model_sync` | [bcc-trust/bcc-trust.php:1185](../app/public/wp-content/plugins/bcc-trust/bcc-trust.php#L1185) (T+120s on activation) | One-shot post-activation read-model rebuild. |
| `bcc_trust_async_suspension_fanout` | [UserInfoRepository.php:634](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Repositories/UserInfoRepository.php#L634) | Async suspension cache invalidation per user. |
| `bcc_trust_async_user_recalc` (and friends) | [UserInfoRepository.php:325](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Repositories/UserInfoRepository.php#L325) | Async per-user score recalc fan-out. |
| ~~`bcc_trust_async_endorsement_fraud_analysis` (`EndorsementFraudAnalyzer::HOOK`)~~ | **REMOVED in the 2026-07-02 Endorse→Attestation cutover** (bcc-trust #31 deleted `EndorsementFraudAnalyzer`; no equivalent hook — post-vote fraud analysis rides `bcc_trust_async_post_vote` below). The `endorsement_fraud_analyzer` DegradationMetric event name remains registered in the bcc-core canonical map. Historical row kept per append-only convention; not an active hook. | Was: async endorsement fraud analysis after the endorsement transaction commits. |
| `\BCC\Trust\Core\REST\CreatorGalleryEndpoint::REFRESH_HOOK` | [CreatorGalleryEndpoint.php:239](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/REST/CreatorGalleryEndpoint.php#L239) | Single-event refresh of a creator's gallery (5-minute throttle). |
| `bcc_trust_async_post_vote` (composite) | [VoteJobDispatcher.php:130](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/Vote/VoteJobDispatcher.php#L130) | Composite post-vote job. Fans out via `do_action()` to `VoteFraudAnalyzer::HOOK` (`bcc_trust_async_fraud_analysis`) + trust-graph-update + reputation-recalculate + stats-refresh — all run synchronously in the same worker invocation. The fan-out hooks are registered as `add_action`s in [Plugin.php:1473+](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Plugin.php#L1473) but are NOT independently scheduled (formerly `bcc_trust_vote_fraud_analyze` row, retired 2026-05-26 with `VoteFraudAnalyzer::schedule` dead-code removal). Action Scheduler primary, `wp_schedule_single_event` fallback. |
| ~~PeepSo shadow-page user-cleanup (`ShadowPageSyncService::USER_CLEANUP_HOOK`)~~ | **REMOVED with the retired `blue-collar-crypto-peepso-integration` plugin** — the file no longer exists and no current equivalent hook was found in `bcc-core`/`bcc-trust` (verified 2026-07-19). Historical row kept per this registry's append-only convention; not an active hook. | Was: cleanup of orphaned shadow-CPT posts after user delete (T+30s). |

**Observability cross-reference**: enqueue failures on the unrecoverable
single-event paths surface as `cron_dispatch` DegradationMetric events
(canonical registry in [bcc-core/bcc-core.php](../app/public/wp-content/plugins/bcc-core/bcc-core.php),
documented in [pattern-registry.md §observability](pattern-registry.md)).
The instrumented sites are `EndorsementFraudAnalyzer::schedule` (event
`endorsement_fraud_analyzer`) and `VoteJobDispatcher::enqueue` wp-cron
fallback (event `vote_job_dispatcher`). Recoverable surfaces with a
reconciliation sweep (e.g. `bcc_disputes_reconcile`) intentionally
stay out of the subsystem — see pattern-registry for the policy.

## Custom cron intervals

Registered via the WordPress `cron_schedules` filter. New intervals
should join an existing service's `registerIntervals` / `addCronIntervals`
method rather than registering a fresh filter — multiple filters work
but make `wp cron schedule list` harder to read.

| Interval | Seconds | Display | Registered in |
|---|---|---|---|
| `bcc_thirty_seconds` | 30 | "BCC: Every 30 Seconds" | [PageReadModelSync::registerIntervals](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/PageReadModelSync.php#L45) |
| `bcc_one_minute` | 60 | "BCC: Every Minute" | [CronService::addCronIntervals](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/CronService.php) |
| `bcc_pull_batch_sweep_minute` (legacy interval slug retained per §4.5.1) | 60 | "BCC: Every Minute" | [Plugin.php:1309](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Plugin.php#L1309) (`WatchBatchAggregator::SWEEP_INTERVAL`) |
| `bcc_five_minutes` | 300 | (CronService) | [CronService::addCronIntervals](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/CronService.php) |
| `bcc_thirty_minutes` | 1800 | "Every 30 Minutes (BCC RL Cleanup)" | [bcc-core/bcc-core.php:275](../app/public/wp-content/plugins/bcc-core/bcc-core.php#L275) |
| `bcc_weekly` | 604800 | (CronService) | [CronService::addCronIntervals](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/CronService.php) |

`ChainRefreshService::add_cron_intervals` and
`DisputeScheduler::registerIntervals` may register additional intervals
internally; see the service classes for the current set.

## Retired hooks (cleared via `wp_clear_scheduled_hook`)

Cleared in [CronService::register_cron_events](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/CronService.php#L592)
on every run so orphaned schedules from prior deploys are evicted.

| Hook | Cleared in | Note |
|---|---|---|
| `bcc_trust_hourly_graph_update` | CronService.php:593 | **Legacy alias for `bcc_trust_daily_graph_update`.** `@retire-after`: production confirms zero scheduled instances for 30+ days. (Stabilization Plan V-24.) |
| `bcc_trust_hourly_ring_detection` | CronService.php:594 | Merged into `bcc_trust_daily_graph_update`. |
| `bcc_trust_hourly_risk_refresh` | CronService.php:595 | `user_risk` table merged into `user_info`. |
| `bcc_trust_backfill_edges` | CronService.php:596 | Fresh system, no history to backfill. |
| `bcc_trust_archive_activity_event` | CronService.php:597 | Merged into `bcc_trust_daily_cleanup`. |

---

## Phase A V-19 scoping note (2026-05-09)

The Stabilization Cleanup Plan §1.1 + §5.5 listed ~25 V-19 "legacy"
markers as candidates for `@migration-status` / `@retire-after`
tagging during Phase A. After file-level triage on 2026-05-09,
Phase A actually shipped **only one** inline retire-after marker:

- [CronService.php:593](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/CronService.php#L593)
  — `bcc_trust_hourly_graph_update` (V-24, listed above in "Retired
  hooks").

The other ~24 V-19 sites stay deferred to Phase D (the post-MVP
consolidation phase) for the following reasons:

- Most legacy markers describe **localized internal semantics** (e.g.,
  "fail-soft … one corrupt legacy row" in `DisputeRepository`) that do
  not represent a file-level migration posture. A class-level marker
  would mislabel an otherwise canonical file.
- Several V-19 sites are **already self-documenting at the class
  header** (`PageTypeMap`, `AuthEndpoint`, `CardsListEndpoint`) —
  adding another marker is duplication.
- One site is **explicitly LOCKED** (`WatchingService` `is_legacy`
  field, "LOCKED — do not violate in UI/feed"; class renamed
  from `BinderService` on 2026-05-13 per `api-contract-v1.md §4.5.1`)
  — re-tagging risks weakening the existing lock signal.
- The Onchain `CircuitBreaker` `:323` / `:357` "legacy keys" markers
  are already covered by the V-04 class-header annotation work
  shipped in Phase A (see
  [docs/pattern-registry.md](pattern-registry.md) "Same-name-different-class
  index").

When Phase D opens, the V-19 collective is the right unit of work
— review every legacy marker against its actual retirement trigger
in the same pass, rather than tagging them piecemeal.

## Phase B classification note (2026-05-09)

`bcc_trust_daily_graph_update` (above) is now formally linked to its
implementation class: `BCC\Trust\Core\Security\TrustGraph`. Prior to
the Phase B V-18 read-only sweep, that class was UNKNOWN-status per
the canonical inventory; it has since been classified **alive** and
listed in [docs/pattern-registry.md](pattern-registry.md) under
"Reputation" alongside `TrustScoreService` (the canonical formula).
The trust-graph implementation has not changed — only its
classification status.

The hook merge history captured in `CronService::register_cron_events`
remains: `bcc_trust_hourly_graph_update` and
`bcc_trust_hourly_ring_detection` both fold into
`bcc_trust_daily_graph_update`. Do not reinstate either alias.

---

This file will be updated as new cron hooks are registered or retired.
