# BCC Database Schema

> Generated from live `information_schema` on 2026-06-25 (dev DB). Supersedes the
> prior hand-maintained partial. Regenerate when schema changes; a declared-vs-live
> drift guard is being added in Phase 2/5.
>
> The watch tables (`wp_bcc_watch_meta` / `wp_bcc_watch_batches`) and the
> `page_follows.tier_at_watch` column were reconciled to live on 2026-06-28 after the
> pull→watch rename.
>
> **2026-07-09 reconciliation:** the 17 legacy orphan tables were dropped from the dev
> DB (they never had owning code; `includes/database/drop-legacy-orphans.php` already
> auto-drops them on fresh/prod installs). The retired `wp_bcc_trust_endorsements`
> (folded into `trust_attestations`) and `wp_bcc_trust_flags` (disputes reconciliation)
> were removed from this inventory, and three live-but-undocumented tables
> (`attestor_reliability_cache`, `collection_signals`, `trust_stokes`) were added. The
> inventory now matches code-declared == live. Row counts below remain the 2026-06-25
> snapshot unless noted.

Prefix: `wp_`. Engine: InnoDB. **48** `wp_bcc_*` tables live — all active; no orphans.

Row counts are exact (`SELECT COUNT(*)`) for orphan candidates and ambiguous
tables; the rest are the InnoDB `information_schema` estimate (≈), adequate for a
reference doc. Ownership is the bcc-trust plugin unless noted; "Owning code" cites
the table-name accessor (`TableRegistry::*`), the creating schema file, or the
Repository/Service that reads it.

---

## Master inventory

| Table | ~rows | Purpose | Owning code | Status |
|---|---|---|---|---|
| wp_bcc_trust_votes | ≈2 | Per-(voter,page,category) trust votes; weight + vesting | TableRegistry::votes / VoteRepository | Active |
| wp_bcc_trust_page_scores | 403 | Per-(page,category) aggregate score row (self-page tier lives here) | TableRegistry::scores / PageScoreRepository | Active |
| wp_bcc_trust_score_events | 3969 | Audit trail of score/tier transitions per page | TableRegistry::scoreEvents | Active |
| wp_bcc_trust_page_scores_velocity | 1 | Daily score-delta track per page | TableRegistry::scoreVelocity | Active |
| wp_bcc_trust_attestations | 9 | §J attestation layer (Vouch / Stand Behind); successor to the retired endorsements table | TableRegistry::trustAttestations / schema-trust-attestations.php | Active |
| wp_bcc_attestor_reliability_cache | 4 | Nightly recompute cache of AttestationOutcomeClassifier per attestor (PK user_id); cron owns writes, reads fall back to live compute | TableRegistry::attestorReliabilityCache / schema-attestor-reliability-cache.php | Active |
| wp_bcc_trust_stokes | 7 | One row per (act_id,user_id) stoke; feeds feed heat_stage + public stoke_count (never scores) | TableRegistry::stokes / schema-stokes.php | Active |
| wp_bcc_trust_activity | 571 | Recent trust-action activity log (rate-limit + fraud signal) | TableRegistry::activity | Active |
| wp_bcc_trust_activity_archive | 115 | Aged-out rows from trust_activity | TableRegistry::activityArchive | Active |
| wp_bcc_trust_page_flags | 0 | Public page flags (signal only, no score impact) | TableRegistry::pageFlags / schema-page-flags.php | Active |
| wp_bcc_trust_edges | 1 | Directed trust graph edges (PageRank inputs) | TableRegistry::edges | Active |
| wp_bcc_trust_patterns | 0 | Detected behavioral patterns per user (fraud) | TableRegistry::patterns | Active |
| wp_bcc_trust_device_fingerprints | 0 | Device fingerprints + automation scoring per user | TableRegistry::fingerprints | Active |
| wp_bcc_trust_fraud_analysis | 120 | Cached fraud-score analysis per user (TTL) | TableRegistry::fraudAnalysis | Active |
| wp_bcc_trust_suspensions | 0 | User suspension ledger | TableRegistry::suspensions | Active |
| wp_bcc_trust_user_info | 119 | Denormalized per-user trust/fraud summary (source of truth) | TableRegistry::userInfo / schema-user-info.php | Active |
| wp_bcc_trust_user_verifications | 2 | Per-user external verifications (GitHub/X/domain/wallet) | TableRegistry::userVerifications | Active |
| wp_bcc_trust_quest_log | 9 | Onboarding quest completions per user | TableRegistry::questLog | Active |
| wp_bcc_post_shortcodes | 133 | Sidecar act_id → 8-letter public shortcode for post permalinks (`/u/{handle}/post/{code}`) | PostShortcodeRepository / schema-post-shortcodes.php | Active |
| wp_bcc_user_ranks | 0 | Awarded ranks per user — backed the never-built conferred-Foreman role | none (repo + schema deleted 2026-07-09) | **RETIRED** — dropped by `includes/database/drop-user-ranks-table.php` |
| wp_bcc_page_read_model | 2777 | Denormalized page read model for discovery/search | TableRegistry::pageReadModel / schema-project.php | Active |
| wp_bcc_rm_dirty_queue | 0 | Dirty-page queue driving read-model recompute | TableRegistry::dirtyQueue | Active |
| wp_bcc_page_follows | 12 | User→page follow edges | TableRegistry::pageFollows | Active |
| wp_bcc_watch_meta | 24 | Per-follow watch bookkeeping | TableRegistry::watchMeta | Active |
| wp_bcc_watch_batches | 13 | Watch batch emission log | TableRegistry::watchBatches | Active |
| wp_bcc_photo_alts | 1 | a11y alt-text sidecar 1:1 with peepso_photos.pho_id | TableRegistry::photoAlts | Active |
| wp_bcc_blog_chain_tags | 3 | Many-to-many blog post ↔ chain tags | schema-blog-chain-tags.php | Active |
| wp_bcc_content_reports | 0 | User content reports (moderation queue) | TableRegistry::contentReports | Active |
| wp_bcc_user_reports | 2 | Member-on-member reports | UserReportRepository | Active |
| wp_bcc_hidden_activities | 0 | Moderator-hidden activity ids | TableRegistry::hiddenActivities | Active |
| wp_bcc_target_divergence_state | 5 | §J.8 divergence-state notifier sidecar (prior-state memory) | TableRegistry::targetDivergenceState | Active |
| wp_bcc_disputes | 1 | Vote disputes (status, panel tally, adjudication) | Disputes domain (DisputeRepository) | Active |
| wp_bcc_dispute_panel | 5 | Panelist assignments + decisions per dispute | Disputes domain | Active |
| wp_bcc_dispute_participations | 1 | Per-(user,dispute) participation + credit outcome | TableRegistry::disputeParticipations | Active |
| wp_bcc_push_subscriptions | 3 | Web-push VAPID subscriptions per user/device | TableRegistry::pushSubscriptions | Active |
| wp_bcc_chains | 21 | Supported chains registry (RPC/REST/explorer config) | schema-chains.php (Onchain) | Active |
| wp_bcc_chain_checkpoints | 7 | Per-chain indexer checkpoint + CU budget | schema-chain-checkpoints.php | Active |
| wp_bcc_wallet_links | 9 | User↔wallet links per chain | schema-wallets.php / WalletRepository | Active |
| wp_bcc_onchain_signals | 3 | Unified on-chain trust signals (wallet age/tx/role boost) | schema-core.php / OnchainSignalRepository | Active |
| wp_bcc_onchain_claims | 0 | Entity claims (incl. page claims via entity_type='page') | schema-claims.php | Active |
| wp_bcc_onchain_collections | 314 | NFT collection metadata cache (TTL) | schema-collections.php | Active |
| wp_bcc_collection_signals | 2 | Per-(user,collection) demand/verify stance signals (airdrop-proof demand queue) | schema-collection-signals.php / CollectionSignalRepository | Active |
| wp_bcc_onchain_collection_pieces | 0 | Individual NFT pieces within a collection (TTL) | schema-collection-pieces.php | Active |
| wp_bcc_onchain_validators | 3060 | Validator registry + enrichment (Cosmos/etc.) | schema-validators.php | Active |
| wp_bcc_onchain_delegations | 0 | Per-wallet validator delegations (TTL) | schema-delegations.php | Active |
| wp_bcc_nft_holdings | 0 | Confirmed NFT holdings per wallet link | schema-nft-holdings.php | Active |
| wp_bcc_nft_spam_contracts | 0 | NFT spam contract allow/deny rules | schema-nft-spam-contracts.php | Active |
| wp_bcc_user_nft_selections | 2 | User-curated NFT showcase selections | schema-nft-selections.php | Active |
| wp_bcc_helius_seen_signatures | 0 | Solana Helius webhook dedup (seen signatures) | schema-helius-seen-signatures.php | Active |
| wp_bcc_search_terms | 5 | Search-analytics aggregate per (norm_term, vertical, day); self-heal installed, daily-pruned (bcc-search) | SearchTermsRepository (bcc-search) | Active |

> The 17 legacy orphan tables previously listed here (`onchain_dao_stats`,
> `onchain_treasury`, `user_locals`, `page_claims`, `wallet_signals`,
> `trust_eligibility`, `trust_user_risk`, `endorsement_types`,
> `trust_endorsement_types`, and the six `project_*` / `trust_page_*` pre-self-page
> tables) were dropped 2026-07-09. They had no owning code; see
> `includes/database/drop-legacy-orphans.php`.

---

## Active tables — per-table detail

Legend: column rows are `name · type · null · key` (key: PK / UQ unique / K indexed; FK·generated·auto noted inline).
Indexes listed as `name (cols) [unique]`.

### Core / trust engine

#### wp_bcc_trust_votes
Per-(voter,page,category) trust vote with weight + vesting lifecycle.
- id · bigint unsigned · NO · PK auto
- voter_user_id · bigint unsigned · NO · K
- page_id · bigint unsigned · NO · K
- category_id · bigint unsigned · NO
- vote_type · tinyint · NO
- weight · decimal(8,4) · NO
- vested_weight · decimal(8,4) · NO
- fraud_score_at_vote · tinyint unsigned · YES
- vesting_stage · tinyint unsigned · NO · K
- vesting_started_at / fully_vested_at · datetime · YES
- weight_corrected_at · datetime · YES · K
- reason · varchar(100) · YES
- explanation · text · YES
- status · tinyint · NO
- ip_address · varbinary(16) · YES · K
- created_at · datetime · NO · K
- updated_at · datetime · NO
- Indexes: PRIMARY (id) [uq]; uq_voter_page_cat (voter_user_id,page_id,category_id) [uq]; idx_page_recent (page_id,status,created_at); idx_page_score (page_id,status,weight); idx_page_voter (page_id,voter_user_id,status); idx_page_votes (page_id,vote_type,status); idx_votes_page_cat_status (page_id,category_id,status); idx_vote_lookup (voter_user_id,page_id,category_id,status); idx_voter_history (voter_user_id,created_at); idx_voter_status_date (voter_user_id,status,created_at); idx_vesting (vesting_stage,fully_vested_at); idx_correction (weight_corrected_at,fraud_score_at_vote); idx_created (created_at); idx_ip_lookup (ip_address,created_at) — dup `idx_voter_created` dropped by drop-legacy-indexes v2 (2026-07-23)

#### wp_bcc_trust_page_scores
Per-(page,category) aggregate score row. Self-page tier is the page row here.
- page_id · bigint unsigned · NO · PK
- category_id · bigint unsigned · NO · PK
- page_owner_id · bigint unsigned · NO · K
- total_score · decimal(5,2) · NO
- onchain_bonus / endorsement_bonus · decimal(10,2) · NO
- positive_score / negative_score · decimal(5,2) · NO
- vote_count / unique_voters · int unsigned · NO
- confidence_score · decimal(3,2) · NO · K
- reputation_tier · varchar(20) · NO · K
- endorsement_count · int unsigned · NO
- last_vote_at · datetime · YES
- last_calculated_at · datetime · NO
- fraud_metadata · text · YES
- recalculate_required · tinyint(1) · NO · K
- recalc_failures · int unsigned · NO · K
- contribution_bonus / penalty_adjustment · decimal(10,2) · NO
- Indexes: PRIMARY (page_id,category_id) [uq]; idx_cat_score (category_id,positive_score,total_score); idx_owner_scores (page_owner_id,total_score); idx_tier_lookup (reputation_tier,total_score); idx_confidence (confidence_score); idx_recalculate (recalculate_required,last_calculated_at); idx_recalc_failures (recalc_failures,recalculate_required)

#### wp_bcc_trust_score_events
Append-only audit of score/tier transitions per page.
- id · bigint unsigned · NO · PK auto
- page_id · bigint unsigned · NO · K
- event_type · varchar(50) · NO · K
- score_before / score_after / delta · decimal(5,2) · YES
- tier_before / tier_after · varchar(20) · YES
- reason · varchar(255) · YES
- actor_user_id · bigint unsigned · YES
- meta · json · YES
- created_at · datetime · YES · K
- Indexes: PRIMARY (id) [uq]; idx_page_created (page_id,created_at); idx_page_id (page_id); idx_event_type (event_type); idx_created_at (created_at)

#### wp_bcc_trust_page_scores_velocity
Daily score-delta per page (velocity tracking).
- page_id · bigint unsigned · NO · PK
- track_date · date · NO · PK
- score_delta · decimal(8,4) · NO
- Indexes: PRIMARY (page_id,track_date) [uq]; idx_date (track_date)

#### wp_bcc_trust_endorsements — RETIRED (2026-07-02)
Folded into `wp_bcc_trust_attestations` (kind=`vouch`); dropped by
`includes/database/drop-endorsements-table.php`. Column detail removed.

#### wp_bcc_trust_attestations
§J attestation layer (Vouch / Stand Behind); generalized successor to endorsements.
- id · bigint unsigned · NO · PK auto
- attestor_user_id · bigint unsigned · NO · K
- target_kind · varchar(20) · NO · K
- target_id · bigint unsigned · NO
- kind · varchar(20) · NO
- weight_at_time · decimal(8,4) · NO
- context_note · text · YES
- attestation_order_in_target · int unsigned · NO
- created_at · datetime · NO · K
- reaffirmed_at / revoked_at · datetime · YES
- Indexes: PRIMARY (id) [uq]; uq_active_attestation (attestor_user_id,target_kind,target_id,kind,revoked_at) [uq]; idx_attestor_active (attestor_user_id,kind,revoked_at,created_at); idx_attestor_target (attestor_user_id,target_kind,target_id); idx_target_active (target_kind,target_id,kind,revoked_at,created_at); idx_created (created_at)

#### wp_bcc_trust_activity / wp_bcc_trust_activity_archive
Recent trust-action log (rate-limit + fraud signal); archive holds aged-out rows. Identical column shape.
- id · bigint unsigned · NO · PK auto
- user_id · bigint unsigned · NO · K
- action · varchar(50) · NO · K
- target_type · varchar(50) · NO · K
- target_id · bigint unsigned · NO
- ip_address · varbinary(16) · YES · K
- created_at · datetime · NO · K
- Indexes (activity): PRIMARY (id) [uq]; idx_user (user_id); idx_action (action); idx_action_created (action,created_at); idx_user_action_date (user_id,action,created_at); idx_target (target_type,target_id); idx_ip_lookup (ip_address,created_at); idx_created (created_at). Dup `idx_ip_created` dropped by drop-legacy-indexes v2 (2026-07-23).
- Indexes (archive): PRIMARY (id); idx_user; idx_action; idx_target; idx_ip_lookup (ip_address,created_at); idx_created (created_at); idx_archive_created (created_at).

#### wp_bcc_trust_flags — RETIRED (2026-07-08)
Write-dead vote-flag table; disputes now live in `wp_bcc_disputes` (Domain/Disputes).
Dropped by `includes/database/drop-trust-flags-table.php`. Column detail removed.

#### wp_bcc_trust_page_flags
Public page flags (signal only, no score impact).
- id · bigint unsigned · NO · PK auto
- page_id · bigint unsigned · NO · K
- user_id · bigint unsigned · NO · K
- reason · varchar(255) · YES
- created_at · datetime · YES · K
- Indexes: PRIMARY (id) [uq]; uq_page_user (page_id,user_id) [uq]; idx_page_id; idx_user_id; idx_created_at

#### wp_bcc_trust_edges
Directed trust graph edges (PageRank inputs).
- id · bigint unsigned · NO · PK auto
- source_user_id · bigint unsigned · NO · K
- target_user_id · bigint unsigned · NO · K
- weight · decimal(10,4) · NO
- vote_count · int unsigned · NO
- edge_type · varchar(20) · NO · K
- created_at · datetime · NO
- updated_at · datetime · NO
- last_updated · datetime · YES
- Indexes: PRIMARY (id) [uq]; uniq_edge (source_user_id,target_user_id,edge_type) [uq]; idx_source (source_user_id); idx_target (target_user_id); idx_target_source (target_user_id,source_user_id); idx_type_weight (edge_type,weight); idx_pagerank (edge_type,source_user_id,target_user_id,weight)

#### wp_bcc_trust_patterns
Detected behavioral patterns per user (fraud).
- id · bigint unsigned · NO · PK auto
- user_id · bigint unsigned · NO · K
- pattern_type · varchar(50) · NO · K
- pattern_data · text · NO
- confidence · decimal(3,2) · YES
- detected_at · datetime · NO · K
- expires_at · datetime · YES · K
- Indexes: PRIMARY (id) [uq]; idx_user (user_id); idx_user_type (user_id,pattern_type); idx_type (pattern_type); idx_detected (detected_at); idx_expires (expires_at)

#### wp_bcc_trust_device_fingerprints
Device fingerprints + automation scoring per user.
- id · bigint unsigned · NO · PK auto
- user_id · bigint unsigned · NO · K
- fingerprint · varchar(64) · NO · K
- automation_score · tinyint unsigned · YES · K
- automation_signals · text · YES
- first_seen / last_seen · datetime · NO
- ip_address · varbinary(16) · YES · K
- user_agent · text · YES
- risk_level · varchar(20) · YES
- screen_resolution · varchar(20) · YES
- Indexes: PRIMARY (id) [uq]; idx_fingerprint (fingerprint); idx_user (user_id); idx_user_fingerprint (user_id,fingerprint); idx_user_fingerprint_lastseen (user_id,fingerprint,last_seen); idx_ip_fingerprint (ip_address,fingerprint); idx_automation (automation_score); idx_automation_risk (automation_score,risk_level)

#### wp_bcc_trust_fraud_analysis
Cached fraud-score analysis per user (TTL via expires_at).
- id · bigint unsigned · NO · PK auto
- user_id · bigint unsigned · NO · K
- fraud_score · tinyint unsigned · NO · K
- risk_level · varchar(20) · NO · K
- confidence · decimal(3,2) · NO
- triggers · text · NO
- details · text · YES
- analyzed_at · datetime · NO
- expires_at · datetime · YES · K
- Indexes: PRIMARY (id) [uq]; idx_user (user_id); idx_user_recent (user_id,analyzed_at); idx_score (fraud_score); idx_risk (risk_level); idx_expires (expires_at)

#### wp_bcc_trust_suspensions
User suspension ledger.
- id · bigint unsigned · NO · PK auto
- user_id · bigint unsigned · NO · K
- suspended_by · bigint unsigned · NO
- reason · varchar(100) · NO
- fraud_score_at_time · tinyint unsigned · YES
- notes · text · YES
- suspended_at · datetime · NO · K
- expires_at · datetime · YES · K
- unsuspended_at · datetime · YES · K
- unsuspended_by · bigint unsigned · YES
- Indexes: PRIMARY (id) [uq]; idx_user (user_id); idx_active (unsuspended_at,expires_at); idx_status (suspended_at,unsuspended_at); idx_expires (expires_at)

#### wp_bcc_trust_user_info
Denormalized per-user trust/fraud summary (source of truth; ~40 cols). Key columns:
- id · bigint unsigned · NO · PK auto
- user_id · bigint unsigned · NO · UQ
- user_login · varchar(60) / user_email · varchar(100) / display_name · varchar(250)
- registered · datetime · YES; usr_last_activity · datetime · YES · K
- usr_views / usr_likes · int · YES; usr_role · varchar(50) · YES
- fraud_score · int · YES · K; trust_rank · float · YES · K; risk_level · varchar(20) · YES · K
- is_suspended · tinyint(1) · YES · K; is_verified · tinyint(1) · YES · K
- votes_cast / endorsements_given / automation_score / behavior_score(K) · int · YES
- device_fraud_probability · float · YES; signals_updated_at · datetime · YES
- pages_owned / groups_owned / posts_created / comments_made · int · YES
- last_login · datetime · YES; last_ip_address · varchar(45) · YES; device_fingerprint · varchar(255) · YES
- created_at · datetime · YES; updated_at · datetime · YES · K
- peak_fraud_score · int · YES; fraud_triggers · text · YES; page_ids_owned · text · YES
- risk_label · varchar(20) · NO; risk_color · varchar(10) · NO; reputation_tier · varchar(20) · NO · K
- Indexes: PRIMARY (id) [uq]; user_id [uq]; idx_fraud_risk (fraud_score,risk_level); risk_level; idx_reputation_tier; idx_suspended; idx_verification (is_verified,fraud_score); idx_trust_rank; idx_behavior_score; usr_last_activity; idx_updated_at. The single-col `fraud_score` index (covered by idx_fraud_risk's left prefix) was dropped by drop-legacy-indexes v2 (2026-07-23).

#### wp_bcc_trust_user_verifications
Per-user external verifications (GitHub/X/domain/wallet).
- id · bigint unsigned · NO · PK auto
- user_id · bigint unsigned · NO · K
- type · varchar(50) · NO · K
- provider_id / provider_username / provider_avatar · varchar(255) · YES
- meta · text · YES; access_token · text · YES
- trust_boost · float · YES · K; fraud_reduction · int · YES
- status · varchar(20) · NO · K
- verified_at · datetime · YES · K; last_synced · datetime · YES
- created_at / updated_at · datetime · YES
- Indexes: PRIMARY (id) [uq]; unique_provider (type,provider_id) [uq]; unique_user_type (user_id,type) [uq]; idx_user (user_id); idx_user_status (user_id,status); idx_status; idx_type; idx_trust_boost; idx_verified

#### wp_bcc_trust_quest_log
Onboarding quest completions per user.
- id · bigint unsigned · NO · PK auto
- user_id · bigint unsigned · NO · K
- quest_slug · varchar(50) · NO
- completed_at · datetime · NO
- Indexes: PRIMARY (id) [uq]; uq_user_quest (user_id,quest_slug) [uq]; idx_user (user_id)

#### wp_bcc_post_shortcodes
Sidecar mapping `peepso_activities.act_id` → the 8-letter public shortcode used in post
permalinks (`/u/{handle}/post/{code}`). Codes mint lazily on first feed emission
(`PostShortcodeRepository::ensureForActIds`); append-only, one code per activity forever.
Sidecar (not a column) because PeepSo owns `peepso_activities`.
- act_id · bigint(20) unsigned · NO · PK (peepso_activities.act_id)
- short_id · char(8) · NO · UQ (letters-only — disjoint from the numeric /feed/{id} route)
- created_at · datetime · NO (UTC via gmdate(); never a DB default — clock-split norm)
- Indexes: PRIMARY (act_id) [uq]; short_id (short_id) [uq]

#### wp_bcc_search_terms (bcc-search)
Search-analytics aggregate: one row per (norm_term, vertical, day), UPSERT-collided on the
unique key by `SearchTermsRepository::record()`. Self-heal installed (option-guarded,
advisory-locked); rows past the 120-day retention window pruned daily
(`bcc_search_terms_prune` cron). The only bcc-search-owned table.
- id · bigint unsigned · NO · PK auto
- norm_term · varchar(100) · NO
- vertical · varchar(16) · NO
- day · date · NO
- result_count · int · NO · default 0
- hits · int · NO · default 0
- updated_at · datetime · NO
- Indexes: PRIMARY (id) [uq]; uq_term_vertical_day (norm_term,vertical,day) [uq]; day_idx (day); vertical_day_hits (vertical,day,hits)

#### wp_bcc_user_ranks — RETIRED (2026-07-09)
Awarded ranks per user (e.g. Foreman role). **Retired with the never-built conferred-Foreman
role** (contract v1.36, Option B ranking slice): `UserRankRepository` + schema deleted, and
`includes/database/drop-user-ranks-table.php` drops the table on `init` (resurrection-guarded).
Ranks are a 1:1 relabel of the feature-access LEVEL — no table backs them. If this table
physically exists on a box, it is leftover data, not an active surface. Column shape below kept
for historical reference:
- id · bigint unsigned · NO · PK auto
- user_id · bigint unsigned · NO · K
- rank_key · varchar(32) · NO · K
- awarded_by · bigint unsigned · YES · K
- awarded_at · datetime · NO
- revoked_at · datetime · YES; revoke_reason · varchar(255) · YES
- Indexes: PRIMARY (id) [uq]; idx_user_active (user_id,revoked_at); idx_rank_key (rank_key); idx_awarded_by (awarded_by)

### Read-model / feed

#### wp_bcc_page_read_model
Denormalized page read model for discovery/search reads (updated via score/vote/endorse hooks).
- page_id · bigint unsigned · NO · PK
- owner_id · bigint unsigned · NO · K
- trust_score · decimal(5,2) · NO · K
- reputation_tier · varchar(20) · NO · K
- confidence_score · decimal(3,2) · NO · K
- vote_count / unique_voters · int unsigned · NO
- endorsement_count · int unsigned · NO · K
- follower_count · int unsigned · NO · K
- page_type · varchar(50) · NO · K
- is_verified · tinyint(1) · NO · K
- last_updated · datetime · NO
- positive_score / negative_score / onchain_bonus · decimal(5,2) · NO
- github_username · varchar(100) · YES; github_followers · int unsigned · NO
- x_username · varchar(100) · YES; x_followers · int unsigned · NO
- has_wallet · tinyint(1) · NO
- last_vote_at / last_endorsement_at · datetime · YES
- updated_at · datetime · NO; endorsement_bonus · decimal(10,2) · NO
- Indexes: PRIMARY (page_id) [uq]; idx_trust_sort (trust_score); idx_tier_trust (reputation_tier,trust_score); idx_type_trust (page_type,trust_score); idx_verified_trust (is_verified,trust_score); idx_endorsement_sort (endorsement_count); idx_follower_sort (follower_count); idx_owner (owner_id); idx_confidence (confidence_score)

#### wp_bcc_rm_dirty_queue
Dirty-page queue driving read-model recompute.
- page_id · bigint unsigned · NO · PK
- created_at · datetime(6) · NO
- Indexes: PRIMARY (page_id) [uq]

#### wp_bcc_page_follows
User→page follow edges.
- id · bigint unsigned · NO · PK auto
- user_id · bigint unsigned · NO · K
- page_id · bigint unsigned · NO · K
- card_kind · varchar(32) · NO · K
- tier_at_watch · varchar(20) · YES
- created_at · datetime · NO · K
- Indexes: PRIMARY (id) [uq]; uq_user_page (user_id,page_id) [uq]; idx_user_id; idx_page_id; idx_card_kind; idx_created_at

#### wp_bcc_watch_meta
Per-follow watch bookkeeping.
- follow_id · bigint unsigned · NO · PK
- tier_at_watch · varchar(20) · YES · K
- batch_id · varchar(64) · YES · K
- visibility · varchar(20) · NO
- watched_at · datetime · NO · K
- Indexes: PRIMARY (follow_id) [uq]; idx_batch_id; idx_tier_at_watch; idx_watched_at

#### wp_bcc_watch_batches
Watch batch emission log.
- id · bigint unsigned · NO · PK auto
- user_id · bigint unsigned · NO · K
- batch_id · varchar(64) · NO · UQ
- card_count / more_count · int unsigned · NO
- emitted_at · datetime · NO
- Indexes: PRIMARY (id) [uq]; uk_batch_id (batch_id) [uq]; idx_user_emitted (user_id,emitted_at)

#### wp_bcc_photo_alts
a11y alt-text sidecar, 1:1 with peepso_photos.pho_id.
- pho_id · bigint unsigned · NO · PK
- owner_id · bigint unsigned · NO · K
- alt_text · varchar(500) · NO
- updated_at · datetime · NO · K
- Indexes: PRIMARY (pho_id) [uq]; idx_owner_id; idx_updated_at

#### wp_bcc_blog_chain_tags
Many-to-many blog post ↔ chain tags.
- post_id · bigint unsigned · NO · PK
- chain_id · bigint unsigned · NO · PK
- created_at · datetime · NO
- Indexes: PRIMARY (post_id,chain_id) [uq]; idx_chain (chain_id)

### Moderation / reports

#### wp_bcc_content_reports
User content reports (moderation queue).
- id · bigint unsigned · NO · PK auto
- target_kind · varchar(20) · NO · K
- target_id · bigint unsigned · NO
- reporter_user_id · bigint unsigned · NO · K
- reason_code · varchar(40) · NO
- comment · text · YES
- status · tinyint · NO · K
- resolved_by · bigint unsigned · YES; resolved_at · datetime · YES
- created_at · datetime · NO
- Indexes: PRIMARY (id) [uq]; unique_reporter_target (reporter_user_id,target_kind,target_id) [uq]; idx_target_status (target_kind,target_id,status); idx_status_created (status,created_at); idx_reporter (reporter_user_id,created_at)

#### wp_bcc_user_reports
Member-on-member reports.
- id · bigint unsigned · NO · PK auto
- reported_id · bigint unsigned · NO · K
- reporter_id · bigint unsigned · NO · K
- reason_key · varchar(100) · NO
- reason_detail · varchar(1000) · NO
- status · varchar(20) · NO · K
- created_at · datetime · NO
- reviewed_at / notified_at / admin_notified_at · datetime · YES
- Indexes: PRIMARY (id) [uq]; idx_reported; idx_reporter; idx_reporter_reported (reporter_id,reported_id); idx_status. The pre-M1 status-blind UNIQUEs `uq_reporter_reported` / `uq_reporter_reported_reason` were dropped by drop-legacy-indexes v2 (2026-07-23) — they blocked legitimate re-reports after resolution (createReport's dupe check is deliberately `status IN ('open','reviewing')`-filtered).

#### wp_bcc_hidden_activities
Moderator-hidden activity ids.
- act_id · bigint unsigned · NO · PK
- hidden_at · datetime · NO · K
- hidden_by_user_id · bigint unsigned · NO
- reason_code · varchar(40) · NO · K
- related_report_id · bigint unsigned · YES
- Indexes: PRIMARY (act_id) [uq]; idx_hidden_at; idx_reason (reason_code)

#### wp_bcc_target_divergence_state
§J.8 divergence-state notifier sidecar (prior-state memory + cooldown).
- id · bigint unsigned · NO · PK auto
- target_kind · varchar(20) · NO · K
- target_id · bigint unsigned · NO
- current_state · varchar(20) · NO · K
- computed_at · datetime · NO · K
- last_notified_at · datetime · YES · K
- Indexes: PRIMARY (id) [uq]; uq_target (target_kind,target_id) [uq]; idx_state; idx_computed_at; idx_last_notified_at

### Disputes

#### wp_bcc_disputes
Vote disputes (status, panel tally, adjudication, reopen).
- id · bigint unsigned · NO · PK auto
- vote_id · bigint unsigned · NO · K
- page_id · bigint unsigned · NO · K
- reporter_id · bigint unsigned · NO · K
- voter_id · bigint unsigned · NO
- reason · varchar(1000) · NO
- evidence_url · varchar(2083) · YES
- status · varchar(20) · NO · K
- panel_accepts / panel_rejects / panel_size · tinyint unsigned · NO
- created_at · datetime · NO; resolved_at · datetime · YES
- adjudication_status · varchar(20) · NO · K
- reopen_count · tinyint unsigned · NO
- resolved_notified_at · datetime · YES
- active_vote_lock · bigint unsigned · YES · UQ (STORED GENERATED)
- resolution_enqueued_at · datetime · YES
- Indexes: PRIMARY (id) [uq]; uq_active_vote (active_vote_lock) [uq]; idx_vote; idx_page; idx_reporter; idx_reporter_created; idx_status; idx_status_created; idx_adjudication; idx_reconcile (status,adjudication_status,resolved_at)

#### wp_bcc_dispute_panel
Panelist assignments + decisions per dispute.
- id · bigint unsigned · NO · PK auto
- dispute_id · bigint unsigned · NO · K
- panelist_user_id · bigint unsigned · NO · K
- decision · varchar(20) · YES · K
- note · varchar(500) · YES
- assigned_at · datetime · NO
- voted_at / notified_at · datetime · YES
- Indexes: PRIMARY (id) [uq]; uq_panelist_dispute (dispute_id,panelist_user_id) [uq]; idx_dispute; idx_panelist; idx_panelist_decision (panelist_user_id,decision); idx_undecided (decision)

#### wp_bcc_dispute_participations
Per-(user,dispute) participation + credit outcome.
- id · bigint unsigned · NO · PK auto
- user_id · bigint unsigned · NO · K
- dispute_id · bigint unsigned · NO · K
- decision · enum('accept','reject') · NO
- was_credited · tinyint(1) · NO
- credit_skipped_reason · varchar(32) · YES
- outcome_match · tinyint(1) · YES
- created_at · datetime · NO
- Indexes: PRIMARY (id) [uq]; uq_user_dispute (user_id,dispute_id) [uq]; idx_dispute; idx_user_created (user_id,created_at); idx_user_outcome (user_id,outcome_match). The retired was_credited composites `idx_user_credited_created` / `idx_user_credited_outcome` (deliberately replaced per the schema docblock) were dropped by drop-legacy-indexes v2 (2026-07-23).

### Onchain

#### wp_bcc_chains
Supported chains registry (RPC/REST/explorer config).
- id · bigint unsigned · NO · PK auto
- slug · varchar(50) · NO · UQ
- name · varchar(100) · NO
- chain_type · varchar(20) · NO · K
- chain_id_hex · varchar(20) · YES
- rpc_url / rest_url / explorer_url / icon_url · varchar(500) · YES
- native_token · varchar(20) · YES; color · char(7) · YES
- is_testnet · tinyint(1) · NO
- is_active · tinyint(1) · NO · K
- created_at · datetime · NO
- decimals · tinyint unsigned · NO; bech32_prefix · varchar(20) · YES
- marketplace_template · text · YES
- Indexes: PRIMARY (id) [uq]; slug [uq]; chain_type; is_active

#### wp_bcc_chain_checkpoints
Per-chain indexer checkpoint + compute-unit budget.
- chain_id · bigint unsigned · NO · PK
- last_processed_block / head_block · bigint unsigned · NO
- state · varchar(20) · NO
- cu_used_today · int unsigned · NO
- cu_budget_reset_at · date · NO
- last_run_at · datetime · YES; last_error · varchar(255) · YES
- block_progression_history · varchar(500) · YES
- Indexes: PRIMARY (chain_id) [uq]

#### wp_bcc_wallet_links
User↔wallet links per chain.
- id · bigint unsigned · NO · PK auto
- user_id · bigint unsigned · NO · K
- post_id · bigint unsigned · NO · K
- wallet_address · varchar(128) · NO · K
- chain_id · bigint unsigned · NO · K
- wallet_type · varchar(20) · NO · K
- label · varchar(100) · YES
- verified_at · datetime · YES
- is_primary · tinyint(1) · NO
- created_at · datetime · NO
- last_holdings_refresh_at · datetime · YES
- helius_managed · tinyint(1) · NO
- Indexes: PRIMARY (id) [uq]; uq_chain_address (chain_id,wallet_address) [uq]; user_chain_wallet (user_id,chain_id,wallet_address) [uq]; user_id; post_id; chain_id; wallet_address; wallet_type

#### wp_bcc_onchain_signals
Unified on-chain trust signals (wallet age/tx/role boost). Supersedes the orphan bcc_wallet_signals.
- id · bigint unsigned · NO · PK auto
- user_id · bigint unsigned · NO · K
- wallet_address · varchar(255) · NO · K
- chain · varchar(20) · NO
- wallet_age_days · int unsigned · NO
- first_tx_at · datetime · YES
- tx_count / contract_count · int unsigned · NO
- score_contribution · decimal(6,2) · NO
- raw_data · longtext · YES
- fetched_at · datetime · NO
- role · varchar(20) · NO
- trust_boost · decimal(6,2) · NO · K
- fraud_reduction · int · NO
- contract_address · varchar(128) · YES
- meta · text · YES; last_synced · datetime · YES
- Indexes: PRIMARY (id) [uq]; uq_wallet_chain (wallet_address,chain) [uq]; idx_user; idx_trust_boost

#### wp_bcc_onchain_claims
Entity claims (incl. page claims via entity_type='page' — absorbed bcc_page_claims).
- id · bigint unsigned · NO · PK auto
- user_id · bigint unsigned · NO · K
- entity_type · varchar(20) · NO · K
- entity_id · bigint unsigned · NO
- wallet_address · varchar(128) · NO
- chain_id · bigint unsigned · NO
- claim_role · varchar(20) · NO
- status · varchar(20) · NO · K
- verified_at · datetime · YES
- created_at · datetime · NO
- recovery_pending · tinyint(1) · NO · K
- Indexes: PRIMARY (id) [uq]; uq_user_entity (user_id,entity_type,entity_id) [uq]; idx_entity (entity_type,entity_id); idx_user; idx_status; idx_recovery_pending

#### wp_bcc_onchain_collections
NFT collection metadata cache (TTL via expires_at). Distinct from the dropped legacy bcc_collections.
- id · bigint unsigned · NO · PK auto
- wallet_link_id · bigint unsigned · YES · K
- contract_address · varchar(128) · NO · K
- chain_id · bigint unsigned · NO · K
- collection_name · varchar(200) · YES
- token_standard · varchar(20) · YES
- total_supply · int unsigned · YES
- floor_price · decimal(20,8) · YES · K
- floor_currency · varchar(20) · YES
- unique_holders · int unsigned · YES
- total_volume · decimal(20,8) · YES · K
- listed_percentage / royalty_percentage · decimal(5,2) · YES
- metadata_storage · varchar(30) · YES
- fetched_at · datetime · NO; expires_at · datetime · NO · K
- show_on_profile · tinyint(1) · NO
- image_url · varchar(500) · YES
- is_verified · tinyint(1) · NO · K
- source · varchar(20) · NO
- Indexes: PRIMARY (id) [uq]; uq_chain_contract (chain_id,contract_address) [uq]; chain_id; contract_address; wallet_link_id; expires_at; idx_floor; idx_volume; idx_verified. The redundant pre-"collections are global" UNIQUE `wallet_chain_contract` was dropped by drop-legacy-indexes v2 (2026-07-23) — uq_chain_contract is strictly tighter, so it could never be the deciding upsert collision.

#### wp_bcc_onchain_collection_pieces
Individual NFT pieces within a collection (TTL).
- id · bigint unsigned · NO · PK auto
- chain_id · bigint unsigned · NO · K
- contract_address · varchar(128) · NO
- token_id · varchar(255) · NO
- name · varchar(255) · YES; description · text · YES
- image_url / image_url_thumb · text · YES
- attributes_json · longtext · YES
- fetched_at · datetime · NO; expires_at · datetime · NO
- Indexes: PRIMARY (id) [uq]; uniq_chain_contract_token (chain_id,contract_address,token_id) [uq]; idx_chain_expires (chain_id,expires_at)

#### wp_bcc_onchain_validators
Validator registry + enrichment (Cosmos/etc.).
- id · bigint unsigned · NO · PK auto
- wallet_link_id · bigint unsigned · YES · K
- operator_address · varchar(128) · NO · K
- chain_id · bigint unsigned · NO · K
- moniker · varchar(200) · YES
- status · varchar(20) · NO · K
- commission_rate · decimal(5,2) · YES
- total_stake / self_stake · decimal(30,8) · YES
- delegator_count · int unsigned · YES
- uptime_30d / governance_participation · decimal(5,2) · YES
- jailed_count · int unsigned · YES
- voting_power_rank · int unsigned · YES · K
- fetched_at · datetime · NO; expires_at · datetime · NO
- last_enriched_at / next_enrichment_at(K) / retry_after · datetime · YES
- enrichment_attempts · tinyint unsigned · NO
- identity · varchar(64) · YES
- logo_url / logo_source_ref · varchar(2048) · YES
- logo_checked_at · datetime · YES
- Indexes: PRIMARY (id) [uq]; uq_chain_operator (chain_id,operator_address) [uq]; wallet_link_id; operator_address; chain_id; status; voting_power_rank; expires_at; idx_enrichment_schedule (next_enrichment_at,retry_after)

#### wp_bcc_onchain_delegations
Per-wallet validator delegations (TTL).
- id · bigint unsigned · NO · PK auto
- wallet_link_id · bigint unsigned · NO · K
- chain_id · bigint unsigned · NO · K
- validator_address · varchar(128) · NO
- shares · decimal(40,18) · YES
- amount · decimal(30,8) · YES
- fetched_at · datetime · NO; expires_at · datetime · NO · K
- Indexes: PRIMARY (id) [uq]; uq_wallet_validator (wallet_link_id,validator_address) [uq]; chain_validator (chain_id,validator_address); wallet_link_id; expires_at

#### wp_bcc_nft_holdings
Confirmed NFT holdings per wallet link.
- id · bigint unsigned · NO · PK auto
- wallet_link_id · bigint unsigned · NO · K
- chain_id · bigint unsigned · NO · K
- contract_address · varchar(64) · NO
- token_id · varchar(128) · NO
- token_standard · varchar(16) · YES
- balance · int unsigned · NO
- metadata_status · tinyint unsigned · NO · K
- name · varchar(255) · YES
- image_url / metadata_uri · varchar(500) · YES
- collection_name · varchar(255) · YES
- last_seen_block · bigint unsigned · NO
- confirmed_at · datetime · NO; indexed_at · datetime · NO
- enriched_at · datetime · YES · K
- Indexes: PRIMARY (id) [uq]; uk_wallet_token (wallet_link_id,contract_address,token_id) [uq]; idx_wallet_chain (wallet_link_id,chain_id); idx_chain_contract (chain_id,contract_address); idx_metadata_status; idx_enriched_at

#### wp_bcc_nft_spam_contracts
NFT spam contract allow/deny rules.
- id · bigint unsigned · NO · PK auto
- chain_id · bigint unsigned · NO · K
- contract_address · varchar(64) · NO
- rule · varchar(10) · NO · K
- reason · varchar(255) · YES
- created_at · datetime · NO
- Indexes: PRIMARY (id) [uq]; uk_chain_contract (chain_id,contract_address) [uq]; idx_rule (rule)

#### wp_bcc_user_nft_selections
User-curated NFT showcase selections.
- id · bigint unsigned · NO · PK auto
- user_id · bigint unsigned · NO · K
- wallet_link_id · bigint unsigned · NO · K
- chain_id · bigint unsigned · NO · K
- contract_address · varchar(128) · NO
- token_id · varchar(128) · NO
- collection_name / name · varchar(200) · YES
- image_url / metadata_uri · varchar(500) · YES
- token_standard · varchar(30) · YES
- display_order · int unsigned · NO
- added_at · datetime · NO
- Indexes: PRIMARY (id) [uq]; uq_user_token (user_id,chain_id,contract_address,token_id) [uq]; user_order (user_id,display_order); user_id; wallet_link_id; chain_id

#### wp_bcc_helius_seen_signatures
Solana Helius webhook dedup (seen signatures).
- signature · varchar(96) · NO · PK
- seen_at · datetime · NO · K
- Indexes: PRIMARY (signature) [uq]; idx_seen_at

### Infra / notifications

#### wp_bcc_push_subscriptions
Web-push VAPID subscriptions per user/device.
- id · bigint unsigned · NO · PK auto
- user_id · bigint unsigned · NO · K
- endpoint · varchar(500) · NO
- endpoint_hash · char(64) · NO
- p256dh_key · varchar(128) · NO
- auth_key · varchar(48) · NO
- user_agent · varchar(255) · YES
- created_at · datetime · NO; last_used_at · datetime · YES
- Indexes: PRIMARY (id) [uq]; uniq_user_endpoint (user_id,endpoint_hash) [uq]; idx_user_id

---

## Orphan tables — DROPPED 2026-07-09 (historical record)

All 17 had **zero reads/writes in current plugin code**. `drop-legacy-orphans.php`
(`add_action('init', …, 26)`) drops them on fresh/prod installs; they were removed
from the dev DB manually on 2026-07-09. The rationale table below is kept as a record
of what each table was and why it was retired — dev row counts are pre-drop.

| Table | rows (dev) | Why orphaned |
|---|---|---|
| wp_bcc_user_locals | 0 | Removed in code; Locals membership moved to PeepSo `peepso_group_members`, primary-Local pointer is `wp_usermeta.bcc_primary_local_group_id` (single-graph rule). Removal comments in tables.php / TableRegistry / LocalsService; no schema file, no accessor. |
| wp_bcc_page_claims | 0 | Merged into `wp_bcc_onchain_claims` (entity_type='page'; recovery_pending lives there). Removal comments in tables.php + TableRegistry; no accessor. |
| wp_bcc_wallet_signals | 0 | Superseded by `wp_bcc_onchain_signals` (unified). WalletSignalRepository docblock: "former bcc_trust_wallet_signals table is no longer written to." No active write/read. |
| wp_bcc_trust_eligibility | 0 | Replaced by the in-process cache in VoteEligibilityChecker (120s TTL); docblock: "Replaces the previous DB-backed bcc_trust_eligibility table — no INSERT/DELETE." No accessor. |
| wp_bcc_trust_user_risk | 0 | Merged into `wp_bcc_trust_user_info`. CronService comment: "user_risk table merged into user_info" (hourly_risk_refresh cron retired). No accessor. |
| wp_bcc_endorsement_types | 5 | Endorsement type catalog from an old design; the active path uses `wp_bcc_trust_endorsements` directly (its endorsement_type_id FK is unused/nullable). Zero code references. |
| wp_bcc_trust_endorsement_types | 5 | Duplicate of the above (same shape). Zero code references. Both type-catalog tables are dead. |
| wp_bcc_project_identities | 0 | Pre-self-page "projects as separate entities" design; superseded by member self-page + `wp_bcc_trust_user_verifications`. Zero references; not created by current installer (the stale `bcc_trust_create_page_tables` comment is misleading — schema-project.php only creates the read model). |
| wp_bcc_project_scores | 0 | Same pre-self-page design; scores now live in `wp_bcc_trust_page_scores`. Zero references; stale-installer leftover. |
| wp_bcc_project_metrics_history | 0 | Same pre-self-page design; no metric-history feature ships. Zero references; stale-installer leftover. |
| wp_bcc_project_verifications | 0 | Same pre-self-page design; verifications are per-user in `wp_bcc_trust_user_verifications`. Zero references; stale-installer leftover. |
| wp_bcc_trust_page_composites | 0 | Page-scoped twin of project_scores from the same abandoned design; superseded by `wp_bcc_trust_page_scores`. Zero references; stale-installer leftover. |
| wp_bcc_trust_page_identities | 0 | Page-scoped twin of project_identities; superseded by user verifications on the self-page. Zero references; stale-installer leftover. |
| wp_bcc_trust_page_metrics | 0 | Page-scoped twin of project_metrics_history. Zero references; stale-installer leftover. |
| wp_bcc_trust_page_verifications | 0 | Page-scoped twin of project_verifications; superseded by `wp_bcc_trust_user_verifications`. Zero references; stale-installer leftover. |
| wp_bcc_onchain_dao_stats | 0 | DAO governance-stats feature never wired: no `CREATE TABLE`/accessor anywhere in code, zero reads/writes. Live-only leftover (surfaced by `schema-drift-guard.php`, 2026-06-25). |
| wp_bcc_onchain_treasury | 0 | DAO-treasury twin of dao_stats; same — no creator/reader in code. Live-only leftover (surfaced by `schema-drift-guard.php`, 2026-06-25). |

### Notes / prior-cleanup confirmations
- `wp_bcc_collections`, `wp_bcc_collection_images`, `wp_bcc_onchain_contracts` (dropped in the 2026-06-04 legacy cleanup) are **absent from live** — confirmed gone. Active `wp_bcc_onchain_collections` (314 rows) is the live NFT collection cache and is unrelated to the dead `bcc_collections`.
- Known-orphan candidates that turned out **ACTIVE** (kept off the drop list): `wp_bcc_trust_patterns`, `wp_bcc_trust_page_flags` (registered in `TableRegistry::all()` / created by active schema files), and `wp_bcc_trust_edges` (1 row, registered). (`wp_bcc_user_ranks` was on this list until 2026-07-09 — since RETIRED and actively dropped; see its section above.)
