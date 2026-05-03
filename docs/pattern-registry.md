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

---

This file will be updated over time as new canonical logic is introduced.
