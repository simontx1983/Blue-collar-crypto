# Pattern Registry

Canonical locations for shared logic. **Search this file before writing
new code** — see §11 of CLAUDE.md (Cross-Codebase Reuse Rule).

Append to this registry whenever a new piece of shared logic is
promoted to a Support/, ValueObject/, or library location. The entry
should point at exactly one source-of-truth class or method per concept.

## Reputation

- **Tier mapping** (`reputation_tier` ↔ `card_tier` ↔ display label) →
  `BCC\Trust\Core\Support\ReputationTierMap`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Support/ReputationTierMap.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Support/ReputationTierMap.php))
- **Score calculation** (page-level expected total) →
  `BCC\Trust\Core\ValueObjects\PageScore::computeExpectedTotal`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Core/ValueObjects/PageScore.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/ValueObjects/PageScore.php))

## Wallets

- **Address shortening** (display form `cosmos…q3kf`) →
  `BCC\Trust\Core\Support\WalletAddressValidator::shorten`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Support/WalletAddressValidator.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Support/WalletAddressValidator.php))
- **Privacy rules** (full `address` is own-profile only; `address_short`
  always present) → `BCC\Trust\Core\Services\UserViewService::resolveWallets`
  with the egress safety net `enforceWalletPrivacyAtEgress`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/UserViewService.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/UserViewService.php))

## Profile + account (V2 Phase 2 / 2.5)

- **Self-edit endpoint** (bio + avatar + cover + cover position) →
  `BCC\Trust\Core\REST\MyProfileEndpoint`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Core/REST/MyProfileEndpoint.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/REST/MyProfileEndpoint.php))
- **Profile-fields catalogue** (admin-configured PeepSo profile fields,
  per-field value + visibility) →
  `BCC\Trust\Core\REST\MyProfileFieldsEndpoint`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Core/REST/MyProfileFieldsEndpoint.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/REST/MyProfileFieldsEndpoint.php)).
  Delegates to `PeepSoField::save` / `save_acc` so PeepSo's search index
  and profile-completeness counter stay coherent. Do NOT bypass
  PeepSoField for direct user_meta writes — you'll desync those surfaces.
- **Profile-wide visibility** (`usr_profile_acc` in `peepso_users`) +
  post-on-wall default + hide-birthday-year →
  `BCC\Trust\Core\REST\MyProfilePrefsEndpoint`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Core/REST/MyProfilePrefsEndpoint.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/REST/MyProfilePrefsEndpoint.php)).
  Reads/writes through `PeepSoUser::get_profile_accessibility` /
  `update_peepso_user(['usr_profile_acc'])` — PeepSo's user-search joins
  on this column.
- **Account changes** (email + password + delete) →
  `BCC\Trust\Core\REST\MyAccountEndpoint`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Core/REST/MyAccountEndpoint.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/REST/MyAccountEndpoint.php)).
  Every route re-verifies `current_password`; no session-elevation flag.
  Account deletion is gated by PeepSo's `site_registration_allowdelete`
  option.
- **Avatar / cover storage** is owned by PeepSo; we wrap the public
  methods on `\PeepSoUser` (`move_avatar_file` + `finalize_move_avatar_file`,
  `move_cover_file`, `delete_avatar`, `delete_cover_photo`). Do NOT
  reimplement image processing — PeepSo handles resize, multi-size,
  hash-named storage.
- **Cover photo URL resolver** →
  `BCC\Trust\Core\Services\UserViewService::resolveCoverPhotoUrl`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/UserViewService.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/UserViewService.php))
- **Cover position** (crop x/y) reads/writes
  `peepso_cover_position_x` + `peepso_cover_position_y` user_meta;
  resolver `UserViewService::resolveCoverPhotoPosition` defaults to
  `{x: 50, y: 50}` when not set.
- **Bio storage** is `wp_users.description`; sanitize with
  `sanitize_textarea_field`, cap at 500 chars (matches PeepSo's typical).

## Messaging preferences (V2 Phase 2)

- **Self-edit endpoint** →
  `BCC\Trust\Core\REST\MyMessagesPrefsEndpoint`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Core/REST/MyMessagesPrefsEndpoint.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/REST/MyMessagesPrefsEndpoint.php))
- **Storage**: PeepSo's `peepso_chat_enabled` + `peepso_chat_friends_only`
  user_meta keys, read by
  [peepso-messages/classes/chatmodel.php](../app/public/wp-content/plugins/peepso-messages/classes/chatmodel.php)
  to gate direct-message delivery. Do NOT invent parallel keys —
  PeepSo's chat surfaces won't read them.

## PeepSo email pipeline (silenced)

- **PeepSo's mail queue cron** (`peepso_mailqueue_send_event`) is
  unscheduled at runtime by [bcc-trust.php](../app/public/wp-content/plugins/bcc-trust/bcc-trust.php) §"PeepSo email
  pipeline — silenced". BCC's `DigestService` + `NotificationDispatcher`
  are the canonical email surfaces; PeepSo's parallel pipeline reads
  `peepso_email_intensity` / `peepso_notifications` keys our settings
  don't write, which would have caused double emails / opt-out drift.
- If a future requirement reinstates PeepSo emails, also wire our
  `/settings/notifications` writes to mirror to those keys.

## Cards

- **Crest building** (`background_kind` / `background_value` / `image_url`
  per spec §2.9) → `BCC\Trust\Core\Services\CardViewService::buildCrest`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/CardViewService.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/CardViewService.php))
- **Permission shape** (`{allowed, unlock_hint, reason_code}`) →
  `CardViewService::allow()` / `CardViewService::deny()` (same file)

## Feed

- **Feed composition** (ranking, scope filtering, hydration) →
  `BCC\Trust\Core\Services\Feed\FeedRankingService`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/Feed/FeedRankingService.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/Feed/FeedRankingService.php))
- **Activity row → contract item normalizer** (PeepSo activity →
  `FeedItem` per spec §3.3) → `BCC\Core\Feed\FeedItemNormalizer`
  ([app/public/wp-content/plugins/bcc-core/src/Feed/FeedItemNormalizer.php](../app/public/wp-content/plugins/bcc-core/src/Feed/FeedItemNormalizer.php))
- **Read-side activity query** (paginated batch fetch) →
  `BCC\Core\Feed\ActivityFeedService`
  ([app/public/wp-content/plugins/bcc-core/src/Feed/ActivityFeedService.php](../app/public/wp-content/plugins/bcc-core/src/Feed/ActivityFeedService.php))

## Search

Search is split into a **canonical engine** (the `bcc-search` plugin) and a
**§A2 view-model adapter** (in `bcc-trust`). The frontend speaks only to
the adapter; nothing in `bcc-frontend/` calls `bcc-search` routes directly.
This keeps ranking / throttling / caching / query-quality / LKG / circuit-
breaker centralized in one plugin while honoring §A2 (no reputation-tier
or permalink semantics on the wire to the headless app).

- **Canonical search engine** (FULLTEXT + trust enrichment + cache + rate
  limit + LKG + circuit breaker + query-quality gate) →
  `BCC\Search\Controllers\SearchController` at `GET /bcc/v1/search`
  ([app/public/wp-content/plugins/bcc-search/app/Controllers/SearchController.php](../app/public/wp-content/plugins/bcc-search/app/Controllers/SearchController.php)).
  Returns flat `category_slug` (legacy page_type) + `tier` (reputation
  tier) + WordPress permalinks. Not safe for the headless frontend on its
  own — those identifiers are ineligible per §A2 / §C1.
- **Frontend-safe semantic adapter** (maps reputation_tier → `card_tier`,
  category_slug → `card_kind`, WP permalink → headless route prefix
  `/v/`/`/p/`/`/c/`) → `BCC\Trust\Core\REST\CardsSearchEndpoint` at
  `GET /bcc/v1/cards/search`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Core/REST/CardsSearchEndpoint.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/REST/CardsSearchEndpoint.php)).
  Calls the engine via `rest_do_request('/bcc/v1/search')` — in-process,
  no HTTP round-trip, the engine's throttle/cache/breaker still run.
- **Frontend consumer** → `getSearchSuggestions` →
  [bcc-frontend/src/lib/api/cards-search-endpoints.ts](../bcc-frontend/src/lib/api/cards-search-endpoints.ts).
  Returns `SearchSuggestionsResponse` (a `SearchSuggestion[]` shape that's
  intentionally smaller than the full Card view-model). The frontend never
  imports anything that knows the wrapper exists — it just sees one
  contract and one endpoint.

**Dormant collaborators** (built, not yet wired):

- `BCC\Search\Controllers\UserSearchController` at `GET /bcc/v1/search/users`
- `BCC\Search\Controllers\GroupSearchController` at `GET /bcc/v1/search/groups`

These verticals exist for the future multi-vertical autocomplete (People /
Groups / Pages sections) but are not consumed by any current frontend
surface. **When that UX lands, the orchestration MUST happen inside the
canonical `/bcc/v1/search` path** (let it fan out to user/group services
internally and return one normalized envelope) — do NOT have the frontend
call all three endpoints in parallel. Frontend search is a single
contract, regardless of how many backends back it.

## REST envelope

- **Response wrapping** (`{data, _meta}` success / `{error: {code, message,
  status, data?}}` error) → `BCC\Trust\Core\REST\Envelope`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Core/REST/Envelope.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/REST/Envelope.php))
- **Endpoint helpers** (`ApiResponse::ok()` / `ApiResponse::error()`) →
  `BCC\Trust\Core\Support\ApiResponse`

## Maps & lookups

- **Card-kind ↔ peepso-page type** → `BCC\Trust\Core\Support\PageTypeMap`
- **Card-kind → URL prefix** → `BCC\Trust\Core\Support\CardUrlMap`
- **Rank slug → display label** → `BCC\Trust\Core\Support\RankCatalog`

## Cross-plugin contracts

- **ServiceLocator** (12 contracts; cross-plugin DI seam) →
  `BCC\Core\ServiceLocator`
  ([app/public/wp-content/plugins/bcc-core/src/ServiceLocator.php](../app/public/wp-content/plugins/bcc-core/src/ServiceLocator.php))

## Groups (cross-kind: NFT / Local / system / user)

- **Group identity** (type / source / verification / privacy / gate)
  → `BCC\Trust\Core\ValueObjects\GroupContext` resolved via
  `BCC\Trust\Core\Services\GroupContextResolver`. **Canonical shape
  for any BCC code that reasons about a group; do not read
  `peepso-group` post meta directly.**
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Core/ValueObjects/GroupContext.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/ValueObjects/GroupContext.php),
  [app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/GroupContextResolver.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/GroupContextResolver.php))
- **Verification badge copy** (server-authoritative — frontend renders
  `label` verbatim) → `BCC\Trust\Core\ValueObjects\GroupVerification::onChain()`
  emits `{kind: 'on_chain', label: 'On-Chain Verified'}`. The label
  must NOT be abbreviated to "Verified" alone.
- **Activity heat** (post counts → `cold` / `warm` / `hot` bucket) →
  `BCC\Trust\Core\Services\GroupActivityHeatService`. Thresholds
  filterable via `bcc_group_heat_thresholds`.
- **PeepSo group reads** (membership ledger, member counts, listing,
  activity heat raw counts) →
  `BCC\Core\Repositories\PeepSoGroupRepository`. **All BCC code that
  needs PeepSo group data goes through this repository — do NOT read
  `peepso_group_members` or `peepso_activities` directly.**
  ([app/public/wp-content/plugins/bcc-core/src/Repositories/PeepSoGroupRepository.php](../app/public/wp-content/plugins/bcc-core/src/Repositories/PeepSoGroupRepository.php))
- **PeepSo group writes** (join / leave + counter sync) →
  `BCC\Core\PeepSo\PeepSoGroupWriter`. Auto-fires
  `peepso_action_group_user_join` / `_delete` and recomputes
  `peepso_group_members_count` so PeepSo's frontend stays in sync.
  Single-graph rule (§E3): never write to `peepso_group_members`
  directly.

## Follow terminology overrides ("Keep Tabs" rebrand)

The Floor uses a three-axis vocabulary for what PeepSo calls follow:
**Keep Tabs** (verb / CTA), **Watching** (state), **Watcher / Watchers**
(noun). The two surfaces below are co-maintained — when you change one,
update the other in the same commit, or the legacy WP screens drift away
from the Next.js app.

- **PHP — gettext / ngettext overrides** (PeepSo + peepso-pages +
  peepso-groups text domains) →
  `BCC\PeepSo\Services\PeepSoLabelOverrides`
  ([app/public/wp-content/plugins/blue-collar-crypto-peepso-integration/app/Services/PeepSoLabelOverrides.php](../app/public/wp-content/plugins/blue-collar-crypto-peepso-integration/app/Services/PeepSoLabelOverrides.php)).
  Exact-string match on purpose — substring replacement would corrupt
  generic English uses ("the following services", "they will follow
  their default order"). Filters all four flavors: `gettext`,
  `gettext_with_context`, `ngettext`, `ngettext_with_context`.
- **Frontend — display constants** →
  `FOLLOW_COPY` in
  [bcc-frontend/src/lib/copy.ts](../bcc-frontend/src/lib/copy.ts).
  Exposes the desktop / mobile pair for the active CTA
  (`Keeping Tabs ✓` desktop, `Watching ✓` <sm) because the verb form
  overflows the CardFactory `grid-cols-3` button at 320–375px viewports.
- **API contract is untouched.** `follow_id`, `followers`, `following`,
  `can_follow`, `followed_by_in_network`, `follower_count_hidden`,
  `pull_batch`, `pulled_at`, `card_tier_at_pull`, `bcc_card_pulled` and
  the `following` feed scope value are stable per §9. Only display
  labels changed.

### Verb axis vs. place noun (post-Tier-1 cohesion)

A lifecycle moment that used to be split between two verbs is now
unified:

- **Verb axis** = "Keep Tabs" / "Watching" / "Watchers". Used for every
  CTA, state, and narrative invitation across both PeepSo and the
  frontend (e.g., `BinderGrid` empty-state invite, `FeedView` empty
  state, `OnboardingWizard` step 2, profile counts, feed tabs).
- **Place noun** = `Binder`. Preserve forever. Cards live in the
  Binder. Onboarding step 2 is "Start your binder."
- **Lore-only "pull"** survives in three narrow seams that earned their
  keep — do **not** broaden them, but don't migrate them either:
  1. `LivingHeader` activity counter — `pluralize(n, "pull", "pulls")`
     for `today.pulls` (domain-specific count noun).
  2. `Composer.tsx` and `ReviewCallout.tsx` one-line motivational unlock
     hints ("keep pulling and posting") — idiomatic flavor, not action
     vocabulary.
  3. Cultural / brand-voice writing outside the product chrome (marketing,
     announcements). The Binder ritual still exists — it's just not the
     primary product verb anymore.

Feed item past-tense narration uses the binder noun:
`Added ${n} cards to their binder.` Category badge for `pull_batch` is
`WATCHED` (single-word, parallel to `POSTED`/`REVIEWED`/`DISPUTED`).

## NFT-gated holder groups

- **Gate config storage** (post-meta on `peepso-group`: kind / chain
  / contract / min_balance / collection_id) →
  `BCC\Trust\Onchain\Repositories\GatedGroupRepository`
- **Gate enforcement & opt-out** (TTL'd voluntary opt-out, permanent
  on mod removal) →
  `BCC\Trust\Onchain\Services\NftGroupGateService`
- **Provisioning** (admin-approved auto-creation of closed PeepSo
  groups for verified collections) →
  `BCC\Trust\Onchain\Services\GatedGroupProvisioningService`
- **Holdings** (single + batched multi-pair) →
  `BCC\Trust\Onchain\Services\HoldingsService::ownsAny` /
  `HoldingsService::ownsAnyMany`

## Operational patterns

- **Production-cron staleness detection** (admin notice + degraded
  banner when `DISABLE_WP_CRON !== true` AND a critical hook is
  overdue) →
  `BCC\Trust\Core\Services\CronService::admin_notices` lines 690–705
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/CronService.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/CronService.php#L690-L705)).
  Mirrored at [`bcc-trust.php#L1175-L1180`](../app/public/wp-content/plugins/bcc-trust/bcc-trust.php#L1175)
  and [`DisputeScheduler.php#L857-L870`](../app/public/wp-content/plugins/bcc-trust/app/Domain/Disputes/Services/DisputeScheduler.php#L857).
  Pattern: detect `DISABLE_WP_CRON`, check `wp_next_scheduled($hook)
  < time() - $threshold`, render notice with `*/N * * * * curl -s
  {site_url}/wp-cron.php?doing_wp_cron >/dev/null 2>&1`. Threshold
  matches the criticality of the cron (5 min for chain indexing,
  15 min for score recalc, 24 h for daily refresh).
- **System-health endpoint** (cron disabled flag + per-subsystem
  health surfaced to a single response) →
  `bcc-core/bcc-core.php` lines 385–402
  ([app/public/wp-content/plugins/bcc-core/bcc-core.php](../app/public/wp-content/plugins/bcc-core/bcc-core.php#L385-L402)).
  New subsystems extend the response object — do not invent
  parallel health endpoints.

## Confirmation-gated chain indexing (V2 Phase 1)

- **Read facade** — `HoldingsService::getForUser` /
  `HoldingsService::ownsAny` keep V1 signatures; persistent-first
  read with transient fallback is internal. Never expose the
  persistent table to a service that bypasses the facade.
- **Resilience** — reuse `Onchain\Support\CircuitBreaker`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Support/CircuitBreaker.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Support/CircuitBreaker.php))
  per chain — do not invent per-indexer breakers.
- **Alchemy CU budgeting** — `alchemy_getAssetTransfers` = 120 CU
  flat (verified May 2026). Track per-chain CU/day in
  `wp_bcc_chain_checkpoints.cu_used_today`; circuit-break at
  `BCC_ETH_DAILY_RPC_BUDGET` (default 50 000 CU/day).
- **Helius webhook auth** — Helius does NOT use HMAC. Customer-set
  `Authorization` header is echoed back per delivery; verify with
  `hash_equals($_SERVER['HTTP_AUTHORIZATION'], BCC_HELIUS_WEBHOOK_SECRET)`.
  Replay protection via `tx_signature` LRU dedupe, not timestamp
  drift (no in-payload timestamp to verify).
- **Helius address ceiling** — single shared webhook handles up to
  100 000 addresses. Manage inclusion via `PATCH /v0/webhooks/:id`
  + `helius_managed BOOLEAN` on `wp_bcc_wallet_links`. Do not
  provision per-wallet webhooks.
- **`metadata_status` filter rule (structural)** — `NftHoldingsRepository`
  splits the API: visible reads (`findVisibleForWallet`,
  `countVisibleByContract`, `walletHasAnyForChain`) hard-code
  `metadata_status IN (0, 1)`. Admin/recovery reads
  (`findAllIncludingSpam`, `findByStatus`) are separate methods.
  There is no `?$includeSpam` flag to forget. Bypassing the split
  in a public read path is a P1 bug.

### V2 Phase 1c — landed canonical classes (2026-05-06)

- **NFT enrichment scheduler** →
  `BCC\Trust\Onchain\Services\NftEnrichmentService`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Services/NftEnrichmentService.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Services/NftEnrichmentService.php)).
  Cron-driven (`bcc_nft_enrichment_tick`, every 5min). Per-chain
  dispatch via `FetcherFactory`. Per-row failures retry next tick.
- **Per-chain metadata fetchers** →
  `EvmFetcher::fetchMetadataForToken` (Alchemy `getNFTMetadata`),
  `SolanaFetcher::fetchMetadataForMint` (Helius `getAsset` DAS).
- **Generation-counter cache invalidation** →
  `NftHoldingsRepository::bumpWalletGeneration` /
  `getWalletGeneration`. Every write path
  (`upsertMany`, `deleteByWalletAndToken`, `markStatus`,
  `applyEnrichment`) bumps; read-side consumers in `HoldingsService`
  key per-request transients on the post-bump value.
- **Per-chain advisory lock on the indexer worker** →
  `BCC\Core\DB\AdvisoryLock::acquire('bcc_nft_indexer_chain_<id>', 0)`
  wrapped around `NftEthIndexerWorker::runForChain`. Non-blocking;
  contended ticks silently skip. Prevents CU double-spend across
  wp-cron + admin "Run now" + future Helius-triggered runs.
- **Read-path swap (HoldingsService)** —
  per-wallet-per-chain decision: persistent path requires
  (a) chain checkpoint state == `healthy`,
  (b) `walletHasAnyEnriched` returns true (non-NULL `enriched_at`
  on at least one visible row),
  (c) caller did not pass `$force = true`.
  Any failure → V1 transient fallback. The `meta.indexer_state` +
  server-pre-formatted `meta.indexer_state_label` block reports
  per-chain status to the frontend (per §S, label rendered verbatim).
- **`IndexerState` shared embed type** — documented in
  `docs/api-contract-v1.md` §3.6. New endpoints that surface
  user-scoped holdings reads MUST forward this block from
  `HoldingsService` rather than reconstruct it locally.

### V2 Phase 1a — landed canonical classes (2026-05-06)

- **Persistent NFT-holdings repository** →
  `BCC\Trust\Onchain\Repositories\NftHoldingsRepository`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Repositories/NftHoldingsRepository.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Repositories/NftHoldingsRepository.php))
- **Per-chain progress checkpoints** →
  `BCC\Trust\Onchain\Repositories\ChainCheckpointRepository`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Repositories/ChainCheckpointRepository.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Repositories/ChainCheckpointRepository.php))
- **NFT spam allow/deny rules** →
  `BCC\Trust\Onchain\Repositories\NftSpamContractRepository`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Repositories/NftSpamContractRepository.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Repositories/NftSpamContractRepository.php))
- **Helius signature replay-protection LRU** →
  `BCC\Trust\Onchain\Repositories\HeliusSeenSignaturesRepository`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Repositories/HeliusSeenSignaturesRepository.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Repositories/HeliusSeenSignaturesRepository.php))
- **Spam filter (write-path)** →
  `BCC\Trust\Onchain\Services\NftSpamFilter`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Services/NftSpamFilter.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Services/NftSpamFilter.php))
- **Indexer write-side orchestrator** →
  `BCC\Trust\Onchain\Services\NftHoldingsIndexer`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Services/NftHoldingsIndexer.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Services/NftHoldingsIndexer.php))
- **Cron worker (every-minute, N=12 confs)** →
  `BCC\Trust\Onchain\Workers\NftEthIndexerWorker`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Workers/NftEthIndexerWorker.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Workers/NftEthIndexerWorker.php))
- **Health endpoint contributor** →
  `BCC\Trust\Onchain\Services\NftIndexerHealthSnapshot`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Services/NftIndexerHealthSnapshot.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Services/NftIndexerHealthSnapshot.php))
  — hooks `bcc_system_health` filter; do not invent
  `/health/indexer`.

---

This file will be updated over time as new canonical logic is introduced.
