# Pattern Registry

Canonical locations for shared logic. **Search this file before writing
new code** — see §11 of CLAUDE.md (Cross-Codebase Reuse Rule).

Append to this registry whenever a new piece of shared logic is
promoted to a Support/, ValueObject/, or library location. The entry
should point at exactly one source-of-truth class or method per concept.

## Reputation

- **Trust-score formula (canonical PHP entry point)** →
  `BCC\Trust\Core\Services\TrustScoreService::compute` (pure function; clamps
  to [0, 100]) and `::formulaSql` (the same expression as a SQL fragment).
  Per §A4 — single source of trust logic.
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/TrustScoreService.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/TrustScoreService.php))
- **Trust-score read facade** →
  `BCC\Trust\Core\Services\TrustScoreService::getForPage` /
  `::getForPages`. Prefers `bcc_page_read_model`; falls back to live
  computation via `ScoreRepository`. Use this from anywhere that needs
  a page's trust score — never re-compute the formula at the call site.
- **Tier mapping** (`reputation_tier` ↔ `card_tier` ↔ display label) →
  `BCC\Trust\Core\Support\ReputationTierMap`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Support/ReputationTierMap.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Support/ReputationTierMap.php))
- **Score calculation** (page-level expected total) →
  `BCC\Trust\Core\ValueObjects\PageScore::computeExpectedTotal` (delegates
  to `TrustScoreService::compute`)
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Core/ValueObjects/PageScore.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/ValueObjects/PageScore.php))
- **Trust graph + ring detection** →
  `BCC\Trust\Core\Security\TrustGraph` — PageRank trust propagation,
  vote-ring detection (`detectVoteRings`), and endorsement-ring detection
  (`detectEndorsementRings`). Cron-driven via `bcc_trust_daily_graph_update`
  ([CronService.php:584](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/CronService.php#L584)).
  Tunables: `bcc_trust_graph_edge_chunk`, `bcc_trust_graph_max_chunks`
  filters; `BCC_TRUST_GRAPH_*` / `BCC_TRUST_RING_*` defines.
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Security/TrustGraph.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Security/TrustGraph.php))
- **Wallet-verification read facade** →
  `BCC\Trust\Core\Application\WalletVerificationReadService`. Routes wallet
  identity lookups through `WalletLinkReadInterface` (cross-plugin
  contract) and non-wallet verifications (GitHub, X) through
  `VerificationRepository`. Concrete-only — no public interface; consumers
  are `FeatureAccessService` and `VoteEligibilityChecker`.
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Application/WalletVerificationReadService.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Application/WalletVerificationReadService.php))

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

## Sidecar tables for third-party-owned data

When BCC needs to attach metadata to a row owned by another plugin
(PeepSo, primarily) and that plugin's table has no native column for
the field, the canonical answer is a **BCC-owned sidecar table**
keyed by the third-party row's id. PeepSo updates can't clobber it;
the sidecar repository owns its own `$wpdb` access; the source-of-
truth ownership check still goes through the third-party reader.

**Pattern shape:**

- New table `bcc_<thing>_<noun>` declared in
  `BCC\Trust\Core\Database\TableRegistry` (one accessor per table) and
  installed via `wp-content/plugins/bcc-trust/includes/database/schema-*.php`
  (one file per table; `bcc_trust_create_<thing>_table()` function;
  `dbDelta`; logger).
- PK = the third-party row id (no autoincrement) — 1:1 mapping; cascading
  deletes on the parent row remove exactly one sidecar row.
- BCC-side denormalised fields (e.g. cached `owner_id`) are advisory;
  the write endpoint always re-checks against the third-party source-of-
  truth before upsert.
- Repository at `app/Domain/<Domain>/Repositories/<Thing><Noun>Repository`:
  `findManyBy<Pk>Ids(int[]): array<int, T>` (bounded `IN()` + `LIMIT`),
  `upsert()` (`INSERT … ON DUPLICATE KEY UPDATE`), `delete()`. Generation-
  counter cache invalidation on every write per §5.

**Canonical instances:**

- `bcc_pull_meta` — sidecar for `peepso_follower` (BCC card pulls per §C2)
  → [PullMetaRepository](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Repositories/PullMetaRepository.php)
- `bcc_photo_alts` — sidecar for `peepso_photos` (author-supplied alt
  text per §3.3.9 / §4.18, v1.5 a11y)
  → [PhotoAltRepository](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Repositories/PhotoAltRepository.php),
  [PhotoAltEndpoint](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/REST/PhotoAltEndpoint.php),
  [schema-photo-alts.php](../app/public/wp-content/plugins/bcc-trust/includes/database/schema-photo-alts.php)

**When to use vs not:**

- Use a sidecar when the metadata is **BCC-owned semantics** that
  PeepSo doesn't model (alt text, pull-tier-at-time, batch ids) AND
  the third-party row id is stable.
- Don't use a sidecar to denormalise data the third-party already
  owns — read it through the third-party reader. The single-graph
  rule still applies: PeepSo rows go through PeepSo readers/writers.

## Cross-plugin contracts

- **ServiceLocator** (11 interfaces in `bcc-core/src/Contracts/*`,
  cross-plugin AND intra-plugin DI seam) →
  `BCC\Core\ServiceLocator`
  ([app/public/wp-content/plugins/bcc-core/src/ServiceLocator.php](../app/public/wp-content/plugins/bcc-core/src/ServiceLocator.php))
- **Domain-seam map** (which Domain owns what; canonical interface
  seams; known intra-plugin direct-call shortcuts; maintenance rule
  for new cross-Domain calls) → [docs/domain-seams.md](domain-seams.md).
  When the audit's "alarm bell" question fires (*which Domain actually
  owns this behavior?*), the answer lives there. Append to §3 of that
  doc whenever a new cross-Domain call lands.

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

### V1 accepted debt — "Local-ness" is a title-prefix convention, not a column

**What's load-bearing.** The only thing that distinguishes a Local from
any other PeepSo group is `post_title LIKE 'Local %'`
(see [docs/api-contract-v1.md §4.7](api-contract-v1.md), line ~1804).
There is no `is_local` column, no `bcc_locals` lookup table, no FK.
`/locals/:slug` and `/groups/:slug` resolve to the same underlying
`peepso-page` row by shared slug (`post_name`); the Locals detail page
composes both view-models in parallel (verified 2026-05-11 Locals feed
wire-up — see [§4.7 composition note](api-contract-v1.md)).

**Why composition over normalization (don't relitigate without cause).**
A Local *is* a PeepSo group. Introducing a sidecar discriminator
(`is_local` column or `bcc_locals` lookup) creates a second source of
truth that must be kept in sync forever — a strictly worse problem
than the current convention. At today's creation cadence (manual,
admin-only, low-frequency) the convention's failure mode is
recoverable: an admin renaming a group to drop the "Local " prefix
silently removes it from the `/locals` listing while `/groups/:slug`
keeps working. The user hits a 404, files a bug, the prefix gets
re-added. Acceptable.

**Trigger conditions that promote this to a real discriminator.** Hit
any one and normalize (real `is_local TINYINT` on a BCC sidecar OR
`bcc_locals` lookup keyed on `group_id`; enforce on write):

- A second creator of Locals enters the system (automation, batch
  admin tool, NFT-collection-seeded Locals, region-seeded Locals,
  user-initiated Local creation).
- The "Local " title prefix is dropped from product copy elsewhere
  (e.g., a "Communities" rename pass) and admin tooling no longer
  surfaces the convention as a visible hint.
- A second product surface starts branching on "is this a Local?" as
  a runtime decision (today only the `/locals` listing filter and the
  `/locals/[slug]` detail page composition depend on it).

**Until then: do not pre-emptively normalize.** The title-prefix
convention is correct V1 debt, not silently wrong. A future agent
reading this section who is considering a `bcc_locals` table or an
`is_local` flag should re-read the trigger conditions first.

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
- **API contract** (as of 2026-05-13): `follow_id`, `followers`,
  `following`, `can_follow`, `followed_by_in_network`,
  `follower_count_hidden`, and the `following` feed scope value are
  stable per §9. The Watch / Watching set (`watch_batch`, `watched_at`,
  `card_tier_at_watch`, `bcc_card_watched`) replaced the previous
  `pull_batch` / `pulled_at` / `card_tier_at_pull` / `bcc_card_pulled`
  names under the §1.1.1 additive-deprecation runway — the legacy names
  remain emitted in parallel for one release and then retire.

### Unified Watching vocabulary (post-Tier-1 cohesion, post-2026-05-13 retirement)

**2026-05-13 retirement note:** the previous version of this section
preserved "Binder" as a forever-stable place-noun while the verb
("Keep Tabs" / "Watching") moved to the new vocabulary. That split
left the product with mixed naming — backend / classes / page route
saying "Binder," UI state copy saying "Watching." Per the
2026-05-13 rename, the vocabulary is now fully unified on Watching.
"Binder" is retired as a product noun.

Canonical:

- **Verb axis** = "Watch" (CTA), "Watching" (state), "Watchers" (audience).
- **Place noun** = "Watchlist" — the page where a viewer's watched cards
  live. The previous "Binder" place noun is retired; user-facing copy now
  reads "Your watchlist," "EMPTY WATCHLIST," "Start watching."
- **Past-tense narration** = "Started watching N cards." Category badge
  for the `watch_batch` feed kind is `WATCHED` (unchanged — it already
  used the new vocabulary).
- **"Pull" survives only as legacy storage names** that aren't worth
  migrating today: the `bcc_pull_meta` table, the `bcc_pull_batches`
  table, and column names like `pulled_at` inside those tables. None
  of these names surface in user-facing copy or in API view-model
  field names anymore. They're documented as legacy physical names
  in [`docs/api-contract-v1.md` §4.5.1](api-contract-v1.md) and
  [`docs/database-schema.md`](database-schema.md).

Migration trace:

- The legacy `/me/binder/*` routes, `counts.binder_size`,
  `privacy.binder_hidden`, and the `bcc_card_pulled` event remain
  emitted in parallel during the §1.1.1 additive-deprecation window
  (release N), then removed in release N+1. Do not introduce new
  usages of these legacy names in code.

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

## Observability

The platform is intentionally resilient — fail-closed throttles, fail-open caches, queue fallbacks, NullService default-deny shims, LKG cache serving, swallowed audit-log writes. Each is a deliberate engineering decision; collectively they create a class of bug where the platform becomes partially incorrect while appearing healthy. The two canonical observability primitives both bucket counters per UTC hour with `wp_cache_add` + `wp_cache_incr` (atomic) and a transient fallback for sites without persistent caching:

- **Cross-plugin degradation counters** — `BCC\Core\Observability\DegradationMetrics`
  ([app/public/wp-content/plugins/bcc-core/src/Observability/DegradationMetrics.php](../app/public/wp-content/plugins/bcc-core/src/Observability/DegradationMetrics.php)).
  Subsystem-keyed counters for silent fallback paths. **Use this from any plugin's fallback / fail-closed / silent-continuation branches** so operators can detect "we were degraded for N minutes last hour" before users notice. API:
  ```php
  DegradationMetrics::record(string $subsystem, string $event = 'activation'): void;
  DegradationMetrics::readEvent(string $subsystem, string $event, int $timestamp): int;
  DegradationMetrics::readSnapshot(string $subsystem, list<string> $events, int $timestamp): array;
  ```
  Subsystem and event names are sanitized to `[a-z0-9_]+` for cache-key portability. Stable string identifiers — renaming resets the rolling counters.

  **Currently wired** (Phases 1 + 1.5 + 1.6 observability initiative, 2026-05-09):

  - `throttle` / `activation` — every transition into rate-limit fail-closed mode (bcc-core `Throttle::markSharedDegraded`).
  - **NullService activations** — every fallback NullObject in `bcc-core/src/NullServices/*` records when bcc-trust isn't bound. 10 of 11 NullServices instrumented (the 11th, `NullRecalcQueueRead`, returns null which the inline health endpoint already detects as `recalcSource: 'unavailable'`):
    - `null_trust_read` / `activation` (4 read methods) + `is_suspended` (fail-closed) + `lock_active_vote_for_dispute` (fail-closed) + `eligible_panelists` (fail-open). Per-method events because security postures differ.
    - `null_dispute_adjudication`, `null_score_read`, `null_score_contributor`, `null_page_owner`, `null_wallet_link_read`, `null_wallet_link_write`, `null_wallet_signal_write`, `null_onchain_data_read`, `null_trending_data` — all use a single `activation` event per service. The "is this NullService active?" signal is operationally useful; per-method breakdown is recoverable from logs.
  - `peepso_absence` / 18 events — every BCC writer/repo on the PeepSo boundary contributes a unique event name. Phase 1.5 (2026-05-09) expanded coverage to all 18 V-11 guards across `bcc-core/src/PeepSo/*` + `bcc-core/src/Repositories/PeepSoMessageRepository`. Events: `status_writer_create`, `comment_writer_add`, `gif_writer_create`, `photo_writer_create`, `follow_writer_follow`, `follow_writer_unfollow`, `group_writer_join`, `group_writer_leave`, `notification_writer_send`, `reaction_writer_set`, `reaction_writer_remove`, `message_writer_send_new`, `message_writer_send_in_conversation`, `message_repo_unread_count`, `message_repo_is_participant`, `message_repo_root_conversation_id`, `message_repo_participants`, `message_repo_mark_viewed`. Counter is per-call (not dedup'd) so `/system/health` shows "the comment writer silently no-opped 1247 times in the last hour" not "we logged it once."
  - `search_lkg` / `served`, `search_lkg` / `unavailable_503` — bcc-search breaker-tripped responses (`SearchController::breakerTrippedResponse`).
  - `read_model_fallback` / `legacy_aggregation` — bcc-trust `PageDiscoveryService` taking the legacy-aggregation path because the read model has no data.
  - `audit_log_swallow` / 4 events (3 read-path + 1 write-path) — silent-catch read paths that are supposed to be reliable, **plus** the WRITE path inside `AuditLogger::log()` itself (the §VIII.30 swallow that the constitution deliberately requires). Sustained activation on any event = the audit subsystem is unhealthy; admins see it via `/system/health` before incident review discovers the gap. Events:
    - `score_mutation_before_snapshot` (Phase 1) — bcc-trust `ScoreMutationLogger::readCurrentScore` silent-catch on the score-mutation hot path (Constitution §VIII.30 alignment: audit-log writes must never break mutations).
    - `discovery_owner_verified_status` (Phase 1.8, 2026-05-11) — bcc-trust `PageDiscoveryService` silent-catch on the verified-badge owner lookup. Degrades to `verified=false`; sustained activation = `UserRepository::getByUserId` is unhealthy on a discovery-page hot path.
    - `log_write_failed` (2026-05-13) — bcc-trust `AuditLogger::log` insert returned false. The §VIII.30 swallow itself: mutation has committed but the audit row was lost. Distinct from the read-path sources so dashboards can segment "audit reads unhealthy" from "audit writes unhealthy" (different remediation: read model drift vs. DB connectivity / table missing).
  - **`legacy_ajax` / 3 events** — Phase 1.7 (2026-05-09) instrumentation of suspected-dead AJAX handlers (V-08 candidates that the in-repo audit found no caller for):
    - From [`UserLifecycleService.php`](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/UserLifecycleService.php) (3): `trust_sync_user`, `trust_bulk_sync_users`, `trust_init_page_score`.
    - **2026-05-25:** the 6 wallet/collection AJAX handlers (`wallet_challenge`, `wallet_verify`, `wallet_disconnect`, `wallet_set_primary`, `wallet_list`, `collection_toggle_profile`) previously instrumented here were retired under the fresh-install policy (SPA + claim flows always used the REST surface; 30-day window not needed pre-launch). Subsystem count dropped from 9 → 3.
    - **Decision rule**: 30-day zero-hit window in production = safe to retire per Stabilization Plan V-08 Phase D. Counter is recorded *before* the nonce check so failed-nonce hits are also captured (catches attackers / cached pre-deploy pages / external scripts that the audit can't see).
    - **Sustained nonzero activation** = an external consumer exists that the in-repo audit missed (cron / wp-cli / partner script / cached browser tab) — investigate before retire. The hit pattern (which specific event fires) tells you which surface still has a consumer.
    - **NOT instrumented** here: the 4 *live* admin-only AJAX handlers (`bcc_chain_refresh`, `bcc_collection_refresh`, `bcc_trust_debug_fraud`, `bcc_trust_debug_votes`) — they have confirmed inline-JS callers in admin pages (ChainsPage.php + debug.php) and are not V-08 candidates. They were originally bundled into V-08 by the Stabilization Plan but the Phase 1.7 audit reclassified them as healthy admin tooling.
  - **`cron_dispatch` / 2 events** (2026-05-26) — soft enqueue failures on the trust async surface. `wp_schedule_single_event` returning `false` (or AS returning 0) means the worker never fires. Most post-mutation paths recover via a reconciliation sweep (e.g. `DisputeScheduler::doReconcile`, the recurring graph/recalc/stats sweeps). The two events below are the surfaces where loss is **unrecoverable** without manual replay:
    - `endorsement_fraud_analyzer` — bcc-trust `EndorsementFraudAnalyzer::schedule` enqueue failure. No reconciliation: the endorser keeps a trust bonus they should not.
    - `vote_job_dispatcher` — bcc-trust `VoteJobDispatcher::enqueue` wp-cron-fallback failure. Strands the composite `bcc_trust_async_post_vote` job, which means **all four** sub-tasks (fraud analysis, trust graph, recalc, stats refresh) silently drop for that vote.
    - **Sustained nonzero activation** = `wp_options` is unhealthy on the hot mutation path. Treat as incident, not warning.
    - **NOT instrumented** here: the Action Scheduler arm of `VoteJobDispatcher::enqueueWithLock` (AS surfaces its own visibility via the admin UI) and `DisputeNotificationService::enqueueAsync` (covered by its existing reconciliation sweep + Logger::error in `DisputeController`).

  **Pending wirings** (subsequent batches):
  - Holder-group provisioning sweep retries / dispute reconcile sweep catch-up activations.
  - Helius webhook dedup-skipped events.

- **Push-event-specific observability** — `BCC\Trust\Core\Support\PushMetrics`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Support/PushMetrics.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Support/PushMetrics.php)).
  `(outcome, event_type)` × UTC-hour buckets with fixed outcome set: `attempt` / `success` / `tombstone` / `failure`. Reads from `NotificationPrefs::PUSH_TYPES`. **Push-domain-specific by design** — push delivery has unique outcome shapes (a 410 Gone is qualitatively different from a 5xx) that don't generalize. Lives in bcc-trust because push is bcc-trust-owned; cross-plugin observability uses `DegradationMetrics` instead.

- **System-health snapshot extension seam** — `apply_filters('bcc_system_health', [])`
  ([app/public/wp-content/plugins/bcc-core/bcc-core.php](../app/public/wp-content/plugins/bcc-core/bcc-core.php) line 373; producer).
  The single canonical filter for runtime health surfacing. Plugins extend by `add_filter('bcc_system_health', ...)` returning their subsystem's health block. **Do not invent parallel /health endpoints.**

  **Currently registered contributors** (Phase 3 observability initiative, 2026-05-09):

  | Plugin | Block key | Source | Surfaces |
  |---|---|---|---|
  | bcc-core | `throttle` | `Throttle::health()` | `rate_limiter_ready`, `backend` (trust_engine / object_cache / none), `degraded`, `last_success_ts` |
  | bcc-core | `degradation_metrics` | `DegradationMetrics::healthSnapshot()` | `any_active` triage flag + per-subsystem current/previous-hour counts. Currently wired subsystems: `throttle`, `null_trust_read`, the 10 `null_*` NullService activations, `peepso_absence` (18 events), `search_lkg`, `read_model_fallback`, `audit_log_swallow` (4 events — 3 read-path + 1 write-path as of 2026-05-13), `legacy_ajax` (3 events — was 9; 6 wallet/collection AJAX handlers retired 2026-05-25), `account_security_mail` (5 events — AccountSecurityMailer wp_mail failures on credential / wallet change side-channel emails; treat sustained activation as P1), `cron_dispatch` (2 events — `endorsement_fraud_analyzer` + `vote_job_dispatcher` soft enqueue failures on the unrecoverable trust async surface; sustained activation = wp_options sick, treat as incident). The canonical-subsystem map lives in bcc-core/bcc-core.php — new subsystems wired into `DegradationMetrics::record()` should add their `(subsystem, events)` tuple there. |
  | bcc-search | `search` | bcc-search/bcc-search.php | `ft_index_installed` flag + `persistent_cache` prerequisite |
  | bcc-trust | `trust_engine` | `Plugin.php:1743` | `recalc_queue_depth`, `read_model_drift`, `read_model_coverage` |
  | bcc-trust | `cron_status` | `Plugin.php:1743` | per-canonical-cron `next_run_ts` + `in_seconds`. `null` means "not currently scheduled" — alarm signal that a hook fell out of the activation registry. Covers: `bcc_disputes_reconcile`, `bcc_gated_group_provision`, `bcc_gated_group_reconcile_sweep`, `bcc_nft_eth_indexer_tick`, `bcc_nft_enrichment_tick`, `bcc_helius_dedupe_sweep`, `bcc_trust_daily_graph_update`, `bcc_trust_process_recalculations`. |
  | bcc-trust onchain | `nft_indexer` | `NftIndexerHealthSnapshot::contribute` | NFT indexer state, last successful run, breaker state |

  **Adding a new contributor**: `add_filter('bcc_system_health', function (array $health): array { $health['my_subsystem'] = [...]; return $health; })`. Add the subsystem's row to the table above when the contribution lands. Use a top-level key unique to your subsystem; nested blocks within a key are fine.

The two-counter-class split is intentional and not a §V.17–§V.21 violation — `PushMetrics` and `DegradationMetrics` operate at different layers (push-event taxonomy vs cross-plugin subsystem fallback) and recording into one would not satisfy the other's consumer (admin "Push delivery stats" tab vs unified health envelope).

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

## Moderation recovery affordances (durable architectural principle)

> **Undo is a moderation recovery affordance, not a historical
> correctness mechanism.**

That one sentence is load-bearing. It explains every constraint of
the moderation-undo design:

- **Short TTL (~30 s)** — the window is for fat-fingered misclicks,
  not for "I changed my mind yesterday". Anything longer pretends to
  be a rollback system without being one.
- **No state snapshots** — capturing pre-mutation state would imply
  the system can return to it, which it cannot if downstream
  consumers have already read the post-mutation state.
- **No event-replay engine** — the platform does not have temporal
  semantics, and adding them for an undo affordance is the largest
  possible scope blowout for the smallest possible UX win.
- **Stale-state validation is mandatory** — before reversing, the
  server compares current state to the expected post-action state.
  Divergence (another moderator acted; cache flipped; race won by
  someone else) → refuse with `bcc_undo_stale_state`. Reversing
  without this check turns recovery into race-condition stomping.
- **Some actions never support undo** — the test is *"have downstream
  systems already consumed the effect?"*. If yes (trust-score deltas,
  fraud-fingerprint snapshots, dispute verdicts, on-chain writes,
  reputation history, permission caches), the operation only
  *appears* reversible. The inverse action would produce a different
  downstream state, not the prior one. These are permanently outside
  the undo affordance regardless of how minor the action looks.
- **Audit-log durability matters more than rollback perfection** —
  every forward AND reversal lands in `bcc_trust_audit_log`. A loud,
  traceable, slightly-imperfect record beats a clever silent one
  every time.
- **Fail closed under uncertainty** — missing transient, expired
  token, mismatched current state, conflicting concurrent moderation
  → all reject. Failing closed is the correct moderation posture.

Concrete V1 implementation (admin queue: hide / dismiss / restore):
server-issued single-use 30 s transient tokens; explicit inverse
action on undo; one toast at a time on the frontend (replaces, never
stacks); no persistence across page reloads. See
`bcc-trust/app/Domain/Core/Services/ModerationQueueService.php::resolve / ::undo`
and `bcc-frontend/src/components/admin/UndoToast.tsx`.

Explicitly forbidden later evolutions:
- generalised `/admin/undo` endpoint
- undo stack / multi-step recovery
- token persistence across reloads
- automatic retry workers on stale-state rejections
- snapshot or event-sourcing tables
- exposing undo for suspend / unsuspend / score / fraud / dispute /
  on-chain actions

Pressure to grow this surface WILL appear. Resist unless the
moderation model itself fundamentally changes (e.g. a real time-travel
debug surface for incident review). The current model is a recovery
affordance — it is not, and must not become, a generalised rollback
framework.

## Destructive mutation hardening (pattern)

Every destructive Next.js REST mutation (POST / PATCH / DELETE that
changes state) must satisfy four invariants. The set was derived
empirically on 2026-05-13 across the Tier 1A–1D sweep
(commits `08be805` / `1feff92` / `eed5d4f` / `6c78b87` / `25b54d9`
on `bcc-trust` main) plus the observability close-out
(`8106e55` / `f8110eb`) and frontend copy fix (`13deb24`).

**1. Audit-log only on real state transitions.** Call
`BCC\Trust\Core\Security\AuditLogger::log($action, $targetId, $meta,
$targetType, $userId)` AFTER the underlying write commits, but
gate the call on whether the write actually changed state. Idempotent
re-attempts, no-op duplicates, and "writer returned `true` but the
row already existed" cases must not generate audit rows — they
inflate the trail with non-events and pollute admin forensic
queries.

- If the writer's return value distinguishes inserted-vs-no-op
  (e.g. `PeepSoBlockWriter::block` returns `'created' | 'existing' |
  'invalid' | 'error'`), gate on it.
- If the writer collapses both cases to `true` (e.g.
  `PeepSoGroupWriter::join` / `::leave` per its docblock at
  `bcc-core/src/PeepSo/PeepSoGroupWriter.php:46–50` and `:97–104`),
  **pre-check the prior state** via the relevant repository (e.g.
  `PeepSoGroupRepository::getMembershipStatus`) and audit only on
  the transition direction.
- If the operation is gated by a domain `JoinResult` / equivalent
  value object that returns `success=true` for both "did the work"
  AND "would have been a no-op" cases (`JoinResult::CODE_OK` vs
  `JoinResult::CODE_ALREADY_MEMBER` in
  `bcc-trust/app/Domain/Onchain/ValueObjects/JoinResult.php`), gate
  on the specific transition code.

**2. Throttle BEFORE any credential gate.** Call
`BCC\Core\Security\Throttle::allow($action . ':' . $userId, $limit, 60)`
**immediately** after the `get_current_user_id()` zero-check and
**before** any further work — including any current-password check.
The credential gate itself is brute-force-vulnerable without an
upstream rate limit: a session-hijacked attacker could attempt
`verifyCurrentPassword` at machine speed.

Standard buckets (per-user, 60s window):

| Class of operation | `$limit` |
|---|---|
| Common mutation (block, group join/leave, plain settings update) | 10–20 |
| Credential-gated (account email / password change) | 5 |
| Irreversible (account delete) | 3 |

Pattern is established at
`WalletController::rest_unlink_wallet`, `AuthEndpoint::walletLink`,
and across all 10 endpoints touched in commit `6c78b87`.

**3. Storage idempotency is the canonical replay defense.** Prefer
storage-layer UNIQUE keys + pre-checks (`WalletRepository::exists`,
`hasUserFlagged`, `hasActiveDisputeForVote`, etc.) that return 409
on duplicate state, OR writer return values that surface
`'existing' | 'created'`. The vote pipeline is the exception that
needs `X-Idempotency-Key` because it carries a time-bounded fraud
signal + an async fan-out; everything else relies on storage
guarantees. Do not add per-route Idempotency-Key handling without
a clear reason that storage-layer guarantees can't satisfy.

**4. Retention belongs to `archiveBatch()`, not standalone DELETE.**
The bcc_trust_activity / bcc_trust_audit_log table is bounded by
`AuditLogRepository::archiveBatch(5000)` invoked from
`UserLifecycleService::archiveActivity` inside
`CronService::dailyCleanup` (line 74). **Do not add a standalone
`AuditLogger::cleanOldLogs()` call to the cron path** — it will
preempt the archive INSERT and the archive table will silently
stay empty (see commit `eed5d4f`'s message for the historical
regression). The retained method on `AuditLogger` exists for any
future direct-purge caller; if you need one, audit your assumption
first.

### Observability hook

The §VIII.30 swallow inside `AuditLogger::log()` itself emits
`DegradationMetrics::record('audit_log_swallow', 'log_write_failed')`
when its insert returns false. Sustained activation on the
`/system/health` surface = the audit subsystem is dropping rows on
the write path; investigate DB connectivity, table existence, or
write contention before forensic queries surface the gap on incident
review.

### Side-channel security email (credential + identity-bearing changes only)

A fifth invariant applies to a narrower set of routes: any mutation
that changes a credential OR an identity-bearing artifact (email,
password, account deletion, wallet link / unlink) must send a
plain-text security email out-of-band, regardless of whether
in-app or push notifications also fire.

The threat model: an attacker with a live session can suppress
in-app + push signals because they read the same auth context.
The user's email inbox is a separate trust anchor.

Helper:
[`BCC\Trust\Core\Services\AccountSecurityMailer`](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/AccountSecurityMailer.php)
exposes five static methods (`emailChanged`, `passwordChanged`,
`accountDeleted`, `walletLinked`, `walletUnlinked`). Call AFTER
the audit-log row lands. Constraints (load-bearing):

- **Never throws.** Caller's mutation has committed.
- **Never retries.** Security emails are time-sensitive; a delayed
  retry from a sweep cron creates a worse confusion than no
  email at all.
- **Plain text only.** No HTML rendering surface.
- **No secrets in body.** No password, no full wallet address.
  Truncate wallets first-6/last-4; mask new email when echoed in
  the old-address canary.
- **Email-change special case.** Send to BOTH old and new
  addresses (independent sends).
- **Account deletion special case.** Capture email + display_name
  BEFORE `wp_delete_user` — caller passes them to the mailer
  explicitly, since by the time the mailer runs `get_userdata`
  returns false.

Failures record
`DegradationMetrics::record('account_security_mail', <event>)` per
the taxonomy registered in bcc-core/bcc-core.php. Sustained
activation here = P1 alert, not warning: the user who was supposed
to receive the canary did not, and the audit_log row is the only
remaining trail.

### What the recipe does NOT cover

- Reads / GET endpoints — those don't change state, so audit + rate
  limit + retention are not required.
- Bulk-admin actions surfaced through wp-admin (separate hardening
  track; see `Admin/ModerationService` patterns).
- The frontend's per-surface `bcc_rate_limited` copy — that's UX
  polish on top of the backend hardening. Components that already
  have a `code → copy` map gain a `bcc_rate_limited` key when their
  route picks up a Throttle (commit `13deb24` is the
  AccountSection example). Other components fall back to
  `err.message` ("Too many requests.") — usable, not broken.

### Explicitly forbidden additions

- Per-route Idempotency-Key handling without a documented
  storage-guarantee gap.
- New rate-limit primitives. `BCC\Core\Security\Throttle::allow` is
  the canonical gate.
- A standalone audit-log purge cron alongside `archiveBatch`.
- Auditing every read path. Audit is for **state transitions**, not
  request volume.

## Confirmation-gated chain indexing (V2 Phase 1)

- **Read facade** — `HoldingsService::getForUser` /
  `HoldingsService::ownsAny` keep V1 signatures; persistent-first
  read with transient fallback is internal. Never expose the
  persistent table to a service that bypasses the facade.
- **Resilience** — reuse `Onchain\Support\OnchainCircuitBreaker`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Support/OnchainCircuitBreaker.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Support/OnchainCircuitBreaker.php))
  per chain — do not invent per-indexer breakers. (Renamed from
  `CircuitBreaker` in V-04 to disambiguate from the generic
  `BCC\Trust\Core\Support\CircuitBreaker`.)
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

### V2 NFT Scaling Phase 2 — Cosmos CW-721 (read-time, 2026-05-07)

The Cosmos NFT path is INTENTIONALLY ASYMMETRIC with the ETH/SOL
continuous-indexed persistence model. No public WebSocket / event-
stream feed exists for any Cosmos chain in our scope, so the Phase
1a/1b architecture doesn't translate. Cosmos runs read-time +
transient (24h TTL) — same UX as V1 ETH/SOL pre-Phase-1. Asymmetric
internals, symmetric public API: `HoldingsService::getForUser` /
`ownsAny` consumers don't see the difference. **Don't try to force
symmetry by building a Cosmos persistence layer prematurely.**

Active NFT-chain set (CW-721 contracts deployed + public CosmWasm
exposed): **Stargaze, Injective, Kujira, Dungeon**. Other Cosmos
chains seeded for validator/delegation paths but excluded from NFT
work — curated-only posture self-cleans them (no `is_verified`
collections → zero LCD calls per refresh).

Crypto.org is permanently out-of-scope at the protocol level until
a future chain upgrade enables CosmWasm (`/cosmwasm/wasm/v1/code`
returns 501 Not Implemented as of 2026-05-07).

- **Curated-collection scope reader** —
  `BCC\Trust\Onchain\Repositories\CollectionRepository::listVerifiedByChain(int $chainId, int $limit)`
  ([app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Repositories/CollectionRepository.php](../app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/Repositories/CollectionRepository.php)).
  Sibling of `listVerified` — same JOIN + columns, scoped to one
  chain, ordered by `unique_holders DESC` so the cap (default 30
  via `BCC_COSMOS_HOLDINGS_CONTRACT_CAP` env) prefers popular
  collections.
- **Generic CosmWasm smart-query helper** —
  `CosmosFetcher::wasmSmartQuery(string $contract, array $queryArr)`.
  Threads through the existing `lcdGet` so it inherits `ApiRetry` +
  per-chain `CircuitBreaker`. Wire format mirrors
  `BlockchainQueryService::isCosmosNftHolder` (different domain;
  do NOT call from the fetcher — that path bypasses the breaker).
- **CW-721-specific helpers** —
  `CosmosFetcher::cw721Tokens(contract, owner, startAfter, limit)`,
  `cw721NftInfo(contract, tokenId)`. Prefixed so future
  `cw20Balance` / `cw404Tokens` read clearly alongside.
- **Public CW-721 admin probe** —
  `CosmosFetcher::testCw721ContractInfo(string $contract)`. Used by
  the admin "Test CW-721" button on `VerifyCollectionsPage`
  (extends the existing `handlePost` multi-action dispatcher with a
  `testquery_<id>` action — no new admin page).
- **Cache-key readability rule (locked)** — Cosmos transient cache
  keys are explicit + readable for debugging:
  - `cw721_tokens_<chain_id>_<contract>_<wallet>_<startAfterOrEmpty>` (24h)
  - `cw721_nft_info_<chain_id>_<contract>_<token_id>` (7 days; metadata is static)
  Reuses the existing wp_cache group `bcc_onchain` — do NOT register
  parallel groups (`bcc_nft`, `nft_metadata`, etc.).
- **Read-time + transient cache** — Phase 2 plugs into
  `HoldingsService` at the per-wallet `count_holdings` /
  `list_holdings` seam; inherits the V1 24h transient + truncation +
  per-wallet-page cap. `HoldingsService` itself is contract-stable —
  Phase 2 does not edit it.

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

## Same-name-different-class index (pattern-registry stabilization)

The platform intentionally ships two-with-the-same-name across plugin
boundaries in a few load-bearing places. They compile cleanly because
namespaces differ; the IDE-autocomplete trap is the real risk. This
table records each pair so future agents see the collision *before*
landing on the wrong import. **Do not collapse these pairs without an
explicit decision** — see the Stabilization Cleanup Plan §6.1 (renaming
duplicate-class pairs is a Phase D / post-MVP item, not stabilization
work).

| Short name | FQCN A | FQCN B | Relationship |
|---|---|---|---|
| ~~`LockRepository`~~ | ~~`BCC\Trust\Onchain\Repositories\LockRepository`~~ (was a no-op extension-point alias; deleted) | `BCC\PeepSo\Repositories\LockRepository` (advisory-lock primary, `wp_options` UNIQUE-KEY fallback when AdvisoryLock unavailable) | **RESOLVED.** V-02 of the Constitutional Violation Scan. The Onchain alias was deleted; its 7 callers now call `BCC\Core\DB\AdvisoryLock::` (which they were always ultimately calling through the alias). The short name `LockRepository` now belongs solely to peepso-integration. |
| ~~`PeepSoPageRepository`~~ | `BCC\Core\Repositories\PeepSoPageRepository` (page ownership + categorization; reads `peepso_page_members` + `peepso_page_categories`) | `BCC\PeepSo\Repositories\PeepSoCategoryRepository` (shadow-CPT category-relation reads on `peepso_page_categories` only — *renamed from PeepSoPageRepository*) | **RESOLVED.** V-03 of the Constitutional Violation Scan. peepso-integration's class was renamed to `PeepSoCategoryRepository` to match its single-table responsibility. The short name `PeepSoPageRepository` now belongs solely to bcc-core. No autocomplete collision remains. |
| ~~`CircuitBreaker`~~ | `BCC\Trust\Core\Support\CircuitBreaker` (transient-backed, named-key, generic external dependencies — keeps the short name) | `BCC\Trust\Onchain\Support\OnchainCircuitBreaker` (`wp_cache`-backed with transient fallback, per-chain int-keyed, 6h TTL, HALF-OPEN probe-lock state machine, atomic DB counter, stale-chain detection, rate-limited corruption logging — *renamed from CircuitBreaker*) | **RESOLVED.** V-04 of the Constitutional Violation Scan. The Onchain class was renamed to `OnchainCircuitBreaker` to remove the autocomplete trap. The two breakers stay separate by design — they are NOT API-compatible (Core: string-keyed `allow/recordSuccess/recordFailure`; Onchain: int-keyed `isOpen/recordSuccess/recordFailure/releaseProbe/getAllStatus/getStaleChains`) and Onchain's hardening must NOT be ported into Core's HTTP-endpoint path. |

## REST namespace file-pattern rule

Endpoint registration follows a deterministic file-pattern split. New
work should keep the rule even though the dual-namespace situation is
itself transitional debt (see Stabilization Cleanup Plan §3 — V-07 +
V-29).

- `*Endpoint.php` files register under `/bcc/v1/*`.
- `*Controller.php` files register under `/bcc-trust/v1/*`.

The frontend mirrors this: `bcc-frontend/src/lib/api/client.ts` for
`/bcc/v1`, `bcc-trust-client.ts` for `/bcc-trust/v1`. The same rule is
codified from the backend side in
[NAMING.md](../app/public/wp-content/plugins/blue-collar-crypto-peepso-integration/NAMING.md).
Collapsing the namespaces is a Phase D / post-MVP candidate; do not
attempt during active migration.

## Scan-correction notes (recorded for future stabilization passes)

- **`FeedRankingService` is not duplicated.** Only one file exists, at
  `BCC\Trust\Core\Services\Feed\FeedRankingService` (above, "Feed"
  section). The bcc-core Feed directory contains
  `ActivityFeedService`, `FeedItemNormalizer`, and `ReactionGrammarMap`
  — not a second `FeedRankingService`. Earlier audits flagged this as
  a duplicate pair (V-05); verification on 2026-05-09 found only the
  bcc-trust copy. No annotation required.
- **`LockRepository` collision shape** (RESOLVED 2026-05-10 — V-02):
  the Onchain alias was a no-op `extends \BCC\Core\DB\AdvisoryLock`
  with no added behaviour. It has been deleted; all 7 Onchain callers
  now call `\BCC\Core\DB\AdvisoryLock::` directly. The short name
  `LockRepository` now belongs solely to
  `BCC\PeepSo\Repositories\LockRepository` (the real implementation
  with `tryAcquire` + `wp_options` fallback). Recorded here so a
  future agent reading the older audit verbatim does not act on the
  out-of-date "two LockRepositories" framing.
- **V-27 (`UserStatusController` vs `bcc-trust/v1/user/status` route)
  is a false positive.** The controller is not a duplicate handler —
  it is the *extracted-handler class* wired by `TrustRestController`
  (`POST /bcc-trust/v1/device-fingerprint` and
  `GET /bcc-trust/v1/user/status` register at
  [TrustRestController.php:135](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Controllers/TrustRestController.php#L135)
  + :141 with callbacks `[UserStatusController::class, 'store_fingerprint']`
  and `[UserStatusController::class, 'get_user_status']`). Standard
  `*Controller.php` → `/bcc-trust/v1/*` mapping per the file-pattern
  rule above.
- **V-26 retired in Phase C.** `OptionsHelper::parse_options_string`
  was removed on 2026-05-09 after grep across all bcc-* plugins and
  bcc-frontend confirmed zero callers. The class shell at
  [OptionsHelper.php](../app/public/wp-content/plugins/blue-collar-crypto-peepso-integration/app/Helpers/OptionsHelper.php)
  remains so cached classmaps resolve through deploys. Reintroduce
  methods here only when a current consumer materializes — don't
  reinstate the old `key1:value1,key2:value2` parser without one.

## V-30 frontend inventory addendum (Phase C §5.7 — 2026-05-09)

**Result: §IV.12 verification gap closed.** A read-only sweep across
all 50 hooks (`bcc-frontend/src/hooks/*.ts`) and all 99 components
(`bcc-frontend/src/components/**/*.tsx`) — 149 files total — found
**zero violations** of the four §IV.12 anti-patterns:

| Anti-pattern | Tool | Result | Notes |
|---|---|---|---|
| `as any` cast | `rg "\bas\s+any\b"` across hooks + components | **0 hits** | The codebase is cleanly typed at the boundary. |
| Raw `fetch()` outside the API client | `rg "\bfetch\s*\("` across hooks + components | **0 hits** | All HTTP traffic flows through `lib/api/client.ts` + `bcc-trust-client.ts` adapters. |
| Hooks not using React Query | `rg "useQuery\|useMutation\|useInfiniteQuery"` | **49/50 hooks** use TanStack Query | The 1 outlier is `usePrefersReducedMotion.ts`, which deliberately uses `useState`/`useEffect` to listen to `matchMedia('(prefers-reduced-motion: reduce)')` — a media-query helper, not a server-state hook. Constitutionally correct. |
| Business logic in components | sampling of components flagged for `Math.` / date math | **0 violations sampled** | `Math.max/min/round` usage clamps display values for CSS (e.g., `LivingHeader.tsx:182` `Math.max(0, Math.min(100, Math.round(raw)))` for a progress-bar `width: %`, with the inline comment "The percent number is presentation-only (CSS width)"). No trust-score / tier / fraud-signal recomputation observed. |

### Hook return-shape verification

All hooks fall into one of three canonical patterns, each `{data,
isLoading, error}`-compatible (a superset is fine):

1. **`useQuery` wrappers** — return the React Query result unchanged
   (e.g., [useFeed.ts](../bcc-frontend/src/hooks/useFeed.ts) returns
   `useInfiniteQuery(...)` directly; [useEndorse.ts](../bcc-frontend/src/hooks/useEndorse.ts)
   returns `useMutation(...)` directly). 47 of 50 hooks.
2. **Composed multi-mutation results** — explicitly typed return
   objects that bundle React Query mutations with browser-API state
   (e.g., [usePushSubscription.ts](../bcc-frontend/src/hooks/usePushSubscription.ts)
   returns `{isSupported, isReady, browserSubscribed, enable, disable}`
   where `enable`/`disable` are full `UseMutationResult` objects). 2
   hooks (`usePushSubscription`, plus mutation-triple bundles in
   `useComments.ts` / `useHighlights.ts` / etc. that follow the same
   composition principle).
3. **Pure browser helper** — 1 hook (`usePrefersReducedMotion`).

### Hook inventory by area

| Area | Hooks |
|---|---|
| Auth / Account | `useAccount`, `useUpdateHandle`, `useUpdateProfile`, `useOAuthConnections` |
| Feed / Posts | `useFeed`, `useGroupFeed`, `useCreatePost`, `useReactions`, `useComments`, `useHighlights`, `useReportContent`, `useSetPhotoAlt`, `useUserActivity` |
| Watching | `useWatching`, `useWatch` (legacy `useBinder`, `useBinderPull` retired 2026-05-13) |
| Communities / Groups | `useGroup`, `useGroupMembers`, `useMyGroups`, `useHolderGroups`, `useLocalsPrimary` |
| Disputes / Endorsements | `useDisputes`, `useEndorse` |
| Messaging | `useConversations`, `useConversation`, `useStartConversation`, `useReplyInConversation`, `useMarkConversationRead`, `useUnreadMessageCount` |
| Notifications / Push | `useNotifications`, `useNotificationPrefs`, `usePushSubscription` |
| Onboarding / Discovery | `useOnboardingSuggestions`, `useCompleteOnboarding`, `useDirectory`, `useMembers`, `useMentionSearch`, `useGlobalSearch` |
| Settings / Privacy | `useMyPrivacy`, `useMessagesPrefs`, `useProfileFields`, `useProfilePrefs`, `useBlocks` |
| NFT / Wallets / Galleries | `useNftSelections`, `useWallets`, `useCreatorGallery`, `useUserBlog` |
| Admin | `useAdminReports` |
| Integrations | `useGiphyIntegration`, `useGiphySearch` |
| Browser preferences | `usePrefersReducedMotion` |

### Component inventory by area

| Area | Component count | Notes |
|---|---|---|
| Admin | 1 | `ModerationQueue` — §K1 Phase C admin moderation surface. |
| Auth / Wallet | 3 | `WalletAuthButton`, `WalletSignupPrompt`, `EligibleCommunitiesModal`. |
| Watching | 3 | `WatchingGrid`, `WatchingHeader`, `WatchingTile` (renamed from `BinderGrid`/`BinderHeader`/`BinderTile` 2026-05-13). |
| Blog | 1 | `UserBlogList`. |
| Cards | 1 | `CardFactory` (presentation only). |
| Celebration | 2 | `CelebrationGate`, `CelebrationToast`. |
| Claim | 2 | `ClaimCallout`, `ClaimFlow`. |
| Communities | 3 | `CommunityCover`, `FlippableNftCard`, `JoinPlainGroupButton`. |
| Composer | 3 | `Composer`, `GifPicker`, `MentionPopover`. |
| Creator / NFT | 4 | `CreatorGallery`, `NftPieceDetail`, `NftPickerModal`, `NftShowcaseSettings`. |
| Directory | 2 | `DirectoryGrid`, `DirectoryFilters`. |
| Disputes | 6 | `DisputeCallout`, `DisputeDetail`, `DisputesRoom`, `MyDisputesList`, `OpenDisputeModal`, `PanelQueue`, `PanelVoteModal`. |
| Endorse | 1 | `EndorseButton`. |
| Entity | 3 | `EntityProfile`, `ChainTabs`, `LockedStreamPanel`. |
| Feed | 7 | `FeedView`, `FeedItemCard`, `FeedTabs`, `CommentDrawer`, `HighlightStrip`, `ReactionRail`, `ReportButton`. |
| Groups | 11 | `GroupActionButton`, `GroupAboutPanel`, `GroupCard`, `GroupDetailShell`, `GroupFeedSection`, `GroupGatedNotice`, `GroupMembersStrip`, `GroupMembershipStrip`, `GroupTabs`, `HeatBadge`, `VerificationBadge`. |
| Landing | 2 | `FloorBriefing`, `FloorIntro`. |
| Layout | 4 | `FileRail`, `PageHero`, `SiteHeader`, `SiteFooter` (+ `UnderConstructionPage`). |
| Locals / Members | 3 | `LocalMembershipControls`, `FlippableMemberCard`, `MembersGrid`. |
| Messages | 4 | `ConversationList`, `MessageComposer`, `MessagesBadge`, `ThreadView`. |
| Notifications | 1 | `NotificationBell`. |
| Onboarding | 1 | `OnboardingWizard`. |
| Profile | 12 | `LivingHeader`, `LiveShift`, `ShiftLog`, `MemberHeroCard`, `MemberIdentity`, `MemberBio`, `ProfileTabs`, `RankChip`, `SectionHead`, `StatsStrip`, `BlockToggle`, `GoodStandingRibbon` + 5 panels under `panels/` (`ActivityPanel`, `ComingSoonPanel`, `DisputesPanel`, `GroupsPanel`, `ReviewsPanel`). |
| Review | 1 | `ReviewCallout`. |
| Search | 1 | `GlobalSearch`. |
| Settings | 13 | `SettingsNav`, `BlocksList`, `CommunitiesList`, `ConnectionsSection`, `IdentitySettingsForm`, `MessagesPrefsForm`, `NotificationPrefsForm`, `PrivacySettingsForm`, `WalletsSection` + 4 under `settings/profile/` (`AccountSection`, `ProfileFieldsList`, `ProfileHero`, `ProfilePrefsSection`). |
| System | 1 | `FingerprintReporter`. |
| UI primitives | 1 | `FlipCard`. |

### Conclusion

The Stabilization Plan §V-30 listed 57 UNKNOWN components + 41 UNKNOWN
hooks because the original inventory ran out of read budget before
classifying them. Phase C verified all 149 files are constitutionally
clean against §IV.12. **No Phase D candidates emerged from this
sweep** — the frontend boundary is structurally sound. The Stabilization
Plan §1.6 / §5.7 are **closed**.

If a future feature lands a hook returning a non-canonical shape, an
`as any` cast, a raw `fetch()`, or trust-score recomputation in a
component, those become Phase D follow-ups at that point — but none
exist today.

## NextAuth token-refresh path (V-30 §5.6 verification — 2026-05-09)

The `bcc-frontend/src/lib/auth.ts` `jwt` callback at
[auth.ts:196–207](../bcc-frontend/src/lib/auth.ts) is the
authoritative refresh path. There is no auto-refresh — the strategy is
**fail-fast on expiry**:

1. On every NextAuth session check, the `jwt` callback fires.
2. If `Date.now() >= token.bccTokenExpiresAt`, `token.bccToken` is
   blanked (set to `""`).
3. `bccFetchAsClient` consumes `session.bccToken`; an empty token
   means subsequent fetches send unauth'd.
4. Protected endpoints `401` with a typed `BccApiError`; route
   boundaries / auth-gated layouts redirect the user back to `/login`
   (which initiates a fresh NextAuth credential flow).

Implications for stabilization: this is **structurally sound**. Adding
a silent-refresh path would re-introduce the security tradeoff of
holding refresh tokens client-side, which the current architecture
deliberately avoids (the BCC bearer JWT lives only inside the
NextAuth-signed session JWT — not in localStorage). Stabilization Plan
§5.6 is **closed**: no monitoring action remains.

## Phase B inventory addendum (V-18 / V-25 / V-27 — 2026-05-09)

Phase B of the Stabilization Cleanup Plan (§5.8) classified the five
V-18 UNKNOWN-purpose PHP files plus the V-25 integrations endpoint pair.
**All six are alive.** No transitional or dead files in the set. The
earlier inventory's UNKNOWN status was a time-budget artifact, not
actual ambiguity.

| File | Status | Role |
|---|---|---|
| `BCC\Trust\Core\Services\TrustScoreService` | **alive (locked-canonical)** | THE single canonical entry point for the trust-score formula. §A4 anchor. PageScore::computeExpectedTotal delegates here. Do **not** add a parallel compute path. Listed above under "Reputation". |
| `BCC\Trust\Core\Security\TrustGraph` | alive | Canonical PageRank + ring detector behind `bcc_trust_daily_graph_update` cron. Listed above under "Reputation". |
| `BCC\Trust\Core\Application\WalletVerificationReadService` | alive | Wallet-verification read facade. Concrete-only (no interface). Consumers: `FeatureAccessService`, `VoteEligibilityChecker`. Listed above under "Reputation". |
| `BCC\Trust\Core\Controllers\UserStatusController` | alive | Extracted-handler class for `POST /bcc-trust/v1/device-fingerprint` and `GET /bcc-trust/v1/user/status`, wired by `TrustRestController` (V-27 false positive — see scan-correction note above). |
| `BCC\Trust\Core\Support\PushMetrics` | alive | V2 Phase 1 §P1.F push-delivery observability counters (per-(outcome, event_type) hourly buckets). Consumers: `PushDispatcher`, admin "Push delivery stats" tab. |
| `BCC\Trust\Core\REST\IntegrationsEndpoint` | alive | `GET /bcc/v1/integrations/giphy` — surfaces PeepSo Giphy admin config to FE. Consumed by `bcc-frontend/src/lib/api/integrations-endpoints.ts::getGiphyIntegration` → `useGiphyIntegration` hook. v1.5 GIF picker (`Phase 1c`). |

Each file received a `@status` PHPDoc tag in its class header on
2026-05-09. The tag is informational only — no automation reads it.

---

This file will be updated over time as new canonical logic is introduced.
