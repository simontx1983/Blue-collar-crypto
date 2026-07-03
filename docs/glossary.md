# BCC — Code-Truth Glossary

**Purpose:** a dictionary of the terms that actually exist in the Blue Collar Crypto
codebase. Every entry names the real code that backs it — a table, REST route, class,
constant, field, or rendered string — so the vocabulary can be trusted against the
implementation rather than against prototypes.

**Rules for this document:**

- **No term without code.** If a concept has no implementation, it is not in this glossary.
  Design vocabulary that was discussed but never built (e.g. Shift Log, Union Card, Patches,
  Letters from the Floor, The Beat, Live Signals ticker, Wanted Poster, Badge Nº, Backer,
  Pack) has been removed. Re-add a term only when the code lands.
- **Code names are canonical.** Where a branded UI label exists, it is noted as a secondary
  detail and only when it is actually rendered in `bcc-frontend/`.
- **Drift is documented, not hidden.** Where the frontend and backend disagree, both states
  are described.

Paths are relative to the repo root. The trust/onchain/disputes backend lives in
`app/public/wp-content/plugins/bcc-trust/` (abbreviated below as `bcc-trust/`); the frontend
lives in `bcc-frontend/`.

---

## 1. People & roles

| Term | What it is in code | Backing code |
|---|---|---|
| **Member** | A WordPress user with a PeepSo profile. The baseline actor; every other role is a member with additional proven state. | WP `users`; PeepSo profile. |
| **Operator** | A member who has a **verified claim** over a validator entity page (proven by wallet signature). | `bcc_onchain_claims`, `ClaimStatus` = `verified` (`bcc-trust/app/Domain/Onchain/ValueObjects/ClaimStatus.php`). Signal `role` = `operator` (`bcc-trust/app/Domain/Onchain/Repositories/SignalRepository.php`). |
| **Creator** | A member with a verified claim over an NFT-creator (`nft`) entity page. Same claim mechanism as Operator. | `bcc_onchain_claims`; canonical page type `nft` (`bcc-core/src/Repositories/PeepSoPageRepository.php`). |
| **Delegator** | A wallet that has staked with a validator, surfaced from indexed on-chain signals. | `bcc_onchain_signals`, signal `role` = `delegator`. |
| **Collector** | A wallet that holds NFTs from a creator, surfaced from indexed on-chain signals. | `bcc_onchain_signals`, signal `role` = `holder`/`collector`. |
| **Rank** | An earned capability label derived from a member's feature-access **level**. Three rungs: **Apprentice → Journeyman → Master**. Surfaced as a pre-rendered `rank_label` on the member card. | `RankCatalog.php` (`RANK_APPRENTICE`/`RANK_JOURNEYMAN`/`RANK_MASTER`); `RankService::LEVEL_TO_RANK` maps `new→apprentice`, `active→journeyman`, `veteran→master` (`bcc-trust/app/Domain/Core/Services/RankService.php`). Rendered uppercase in `bcc-frontend/src/components/profile/RankChip.tsx`. |
| **Foreman** | A **conferred role** (moderator authority), *not* a rank. Reaching Master does not confer it. Emitted as a boolean `foreman_insignia` in the viewer block. | `RankCatalog::ROLES` (`foreman => 'Foreman'`); `foreman_insignia` set in `RankService.php`. |

> **Rank vs Trust Tier vs Role** are three independent axes. Rank = earned capability
> (level). Trust Tier = reputation quality (see §3). Foreman = an assigned role. A member
> holds one value on each, in any combination.

---

## 2. Entities & cards

| Term | What it is in code | Backing code |
|---|---|---|
| **Card** | The view-model render unit for any entity. The frontend `CardKind` union is the source of truth for what can be a card. | `CardKind = "validator" \| "project" \| "creator" \| "member" \| "community"` (`bcc-frontend/src/lib/api/types.ts`). |
| **Validator** | An on-chain validator entity page. Canonical page type `validator`. | `PeepSoPageRepository.php` (`validators → validator`). |
| **Project** | A crypto project/protocol entity page. Canonical page type `project`. | `PeepSoPageRepository.php`. |
| **NFT Creator** | An NFT-minting entity page. Canonical page type `nft`; rendered as `creator` card kind. | `PeepSoPageRepository.php` (`nft-creators → nft`); `CardKind` `creator`. |
| **DAO** | A DAO entity page. Canonical page type `dao`. | `PeepSoPageRepository.php` (`daos → dao`). |
| **Builder** | **Not a distinct entity.** Builders are aliased to the `project` page type; there is no separate builder card or block. | `PeepSoPageRepository.php` (`builders → project`). |
| **Community / Local** | A PeepSo Group rendered as a `community` card. A member can join multiple and designate one as **primary** (`primary_local`), shown on their card. Browsable at `/locals`. | `CardKind` `community`; `primary_local` + `PrimaryLocalChip` (`bcc-frontend/src/components/cards/MemberDossier.tsx`); routes `bcc-frontend/src/app/(main)/locals/page.tsx`, `.../locals/[slug]/page.tsx`. |
| **Piece** | A single NFT, served as a view-model. | `NftPieceViewModelBuilder` (`bcc-trust/app/Domain/Onchain/Services/`); `NftPieceEndpoint` (`bcc-trust/app/Domain/Onchain/REST/`). |
| **Collection** | A group of NFTs by one creator. | `bcc_onchain_collections`; `CollectionRepository`, `CollectionService` (`bcc-trust/app/Domain/Onchain/`). |

> **Removed:** *Edition* and *Series* — no table, field, or service exists for either. (The
> prior claim that "Series = `bcc_onchain_collections.title`" was incorrect; `title` is just
> the collection name.)

---

## 3. Reputation & scoring

| Term | What it is in code | Backing code |
|---|---|---|
| **Trust score** | A page's numeric reputation, stored as `total_score` (with `positive_score` / `negative_score` components). There is **no** field named `reputation_score`. | `bcc_trust_page_scores` (`bcc-trust/includes/database/schema-core.php`). |
| **Reputation tier** | A categorical key derived from the score: `elite` / `trusted` / `neutral` / `caution` / `risky`. | Stored as `reputation_tier` on `bcc_trust_page_scores`. |
| **Trust Tier label** | The honest member-facing label for the reputation tier: **Risky / Caution / Neutral / Trusted / Proven** (`elite → "Proven"`). **Derived at read time**, not stored. | `ReputationTierMap.php` (`bcc-trust/app/Domain/Core/Support/`), emitted as `reputation_tier_label`. |
| **Card-rarity tier** | An entity card's rarity classification: `legendary` / `rare` / `uncommon` / `common`, with display fields `card_tier` and `tier_label`. Distinct from the member Trust Tier above. | `ReputationTierMap.php`; rendered in `bcc-frontend/src/components/cards/CardFrontFace.tsx` (TierStrip). |
| **Fraud score** | An anti-abuse signal from behavioral + device analysis. | `BehavioralAnalyzer.php`, `DeviceFingerprinter.php` (`bcc-trust/app/Domain/Core/Security/`). |
| **Read model** | The denormalized fast-query table the API reads from. | `bcc_page_read_model`; `PageReadModelRepository` (`bcc-trust/app/Domain/Core/Repositories/`). |

---

## 4. Trust actions

| Term | What it is in code | Backing code |
|---|---|---|
| **Vote** | The quantitative reputation action backing a Review. | `bcc_trust_votes`; `VoteService`, `VoteRepository` (`bcc-trust/app/Domain/Core/`). |
| **Attestation** | The current first-class trust statement layer. Kinds: `vouch` and `stand_behind`. Carries `weight_at_time`, `context_note`, `target_kind`/`target_id`. | `bcc_trust_attestations` (`bcc-trust/includes/database/schema-trust-attestations.php`); `AttestationService`, `AttestationRepository`. |
| **Vouch** | A per-author credibility toggle (one vouch / one weight per person), rendered next to the author's name — **not** a post reaction. Writes an attestation with `kind=vouch`. | `AuthorVouchButton` (`bcc-frontend/`); `bcc_trust_attestations`. |
| **Endorse** | **Legacy.** The `bcc_trust_endorsements` table still exists, but **writes are retired**; legacy rows were migrated to attestations (`kind=vouch`) and the `/endorse` endpoint now writes through the attestation layer. | `bcc_trust_endorsements` (`schema-trust-attestations.php`), migration in same file. |
| **Reactions** | BCC seeds exactly two reaction types: `solid` (trust grammar — "agree" / drives the solids-received stat) and `fire` (social grammar). Other social kinds (like/love/haha/wow) are PeepSo defaults, not BCC-seeded. | `ReactionTypeRegistry.php` (`KIND_SOLID`, `KIND_FIRE`; `TRUST_KINDS = [solid]`, `ALL_KINDS = [solid, fire]`); seeded into option `bcc_reaction_ids`. |

**Reaction grammars** (`bcc-frontend/src/components/feed/ReactionRail.tsx`): `trust`
(restrained, signs your name), `social` (expressive, emoji-forward, includes Fire), and
`tribal` (reserved for V2 — renders nothing).

> **FE/BE drift — "Stand behind":** the backend retired the `stand_behind` reaction (Slice 3,
> hard-deleted — no longer seeded by `ReactionTypeRegistry`). The frontend trust rail still
> renders a "Stand behind" button (`ReactionRail.tsx:124`). As a *post reaction* it is dead on
> the backend; `stand_behind` survives only as an **attestation kind** (§ Attestation above).
> This drift is current as of this writing.

---

## 5. Watch / follow

| Term | What it is in code | Backing code |
|---|---|---|
| **Watch** | The canonical action for adding an entity to your watchlist. Routes under `/bcc/v1/me/watching`. | `WatchingEndpoint.php` (`bcc-trust/app/Domain/Core/REST/`): `GET /me/watching`, `GET /me/watching/summary`, `POST /me/watching/watch`, `DELETE /me/watching/{follow_id}`. |
| **Follow (storage)** | The underlying relationship lives in PeepSo's follow graph; a watch is an active follow row. | `peepso_user_followers` (`uf_follow = 1`), via `WatchingRepository`. |
| **Watch sidecar tables** | BCC metadata attached to watches. | `bcc_watch_meta`, `bcc_watch_batches` — renamed from `bcc_pull_meta` / `bcc_pull_batches` on 2026-06-26 by `bcc-trust/includes/database/rename-pull-to-watch.php`. |

**Rendered UI copy** (`bcc-frontend/src/lib/copy.ts`, `FOLLOW_COPY`): CTA **"Keep Tabs"**,
active state **"Watching"** (mobile **"Watching ✓"**), group noun **"Watchers"**. The
collection surface is labelled **"Watchlist"** (`bcc-frontend/src/components/onboarding/DopamineStep.tsx`).

> The legacy "Pull"/"Binder" vocabulary and the `/me/binder/*` routes were removed (routes
> on 2026-06-10; physical table rename 2026-06-26). No `pull`/`binder` route or table remains.

---

## 6. Claim & wallet

| Term | What it is in code | Backing code |
|---|---|---|
| **Claim** | The process by which a member proves ownership of an entity page via wallet signature, flipping the claim to `verified`. | `ClaimService`, `ClaimRepository`, `ClaimStatus` (`pending` / `verified` / `revoked`) — `bcc-trust/app/Domain/Onchain/`; `bcc_onchain_claims`. |
| **Sign** | The wallet challenge/response that authenticates a member or backs a claim. | `WalletAuthController` (`bcc-trust/app/Domain/Core/REST/Auth/`). |
| **Wallet link** | A verified wallet ↔ user ↔ entity association. | `bcc_wallet_links` (`DB::table('wallet_links')`); `WalletRepository`. |

---

## 7. On-chain layer

| Term | What it is in code | Backing code |
|---|---|---|
| **Signal** | An indexed on-chain fact (rows carry `role`, `chain`, `score_contribution`, `trust_boost`, `fraud_reduction`, etc.). Surfaced in the feed as `post_kind: "signal"`. | `bcc_onchain_signals`; `SignalRepository` (`bcc-trust/app/Domain/Onchain/Repositories/`). |
| **Fetcher** | A per-chain indexer class pulling data on a schedule. | `bcc-trust/app/Domain/Onchain/Fetchers/`: `EvmFetcher`, `SolanaFetcher`, `CosmosFetcher`, `ThorchainFetcher`, `PolkadotFetcher`, `NearFetcher`. |
| **Chain registry** | Normalized lookup of supported chains (RPC/explorer/token metadata). | `bcc_chains` (`DB::table('chains')`); `ChainRepository`; seeded in `bcc-trust/includes/database/schema-chains.php`. |

---

## 8. Content & feed

| Term | What it is in code | Backing code |
|---|---|---|
| **Feed** | The activity stream. Two endpoints back it. | `GET /bcc/v1/feed`, `GET /bcc/v1/feed/hot` (`FeedEndpoint`, `bcc-trust/app/Domain/Core/REST/`); rendered by `bcc-frontend/src/components/feed/FeedView.tsx`. |
| **post_kind** | The discriminator on a feed item. The implemented kinds and their UI labels are fixed in `POST_KIND_LABELS`. | `bcc-frontend/src/components/feed/FeedItemCard.tsx`: `status`/`photo`/`gif` → POSTED, `watch_batch` → WATCHED, `page_claim` → CLAIMED, `review` → REVIEWED, `dispute` → DISPUTED, `drop` → DROPPED, `release` → RELEASED, `signal` → SIGNAL, `blog_excerpt` → PUBLISHED. |
| **Review** | A structured opinion (a vote plus body) rendered as a `review` feed item. | `CardReviewsEndpoint`, `CardReviewsService` (`bcc-trust/app/Domain/Core/REST/`); `post_kind: "review"`. |
| **Dispute** | A formal complaint adjudicated by a panel of **5** Trusted/Elite members selected with soft-IP diversity. | `DisputeController` (`bcc-trust/app/Domain/Disputes/Controllers/`); `BCC_DISPUTES_PANEL_SIZE = 5` (`bcc-trust/bcc-trust.php`); `bcc_dispute_participations`. |
| **Drop / Release** | NFT-release and project-update feed items. | `post_kind: "drop"` / `"release"` (`FeedItemCard.tsx`). |

> **Removed:** *Announcement*, *Post-mortem*, *Pin* — no BCC `post_kind`, table, or service.
> (Post pinning, where it exists, is a PeepSo-native feature, not a BCC concept.)

---

## 9. Design tokens (in code)

These are the named visual tokens defined as CSS custom properties in
`bcc-frontend/src/app/globals.css` and referenced by `.bcc-*` classes. Listed because they
recur in component class names; purely descriptive prototype "flavor" words with no token or
class behind them are not included.

| Token | CSS var | Use |
|---|---|---|
| **Cardstock** | `--cardstock` (+ `-deep`, `-edge`) | Cream paper texture for card faces / sheets (`.bcc-card-face`, `.bcc-paper`). |
| **Concrete** | `--concrete` (+ `-hi`) | Near-black warehouse-floor background. |
| **Safety** | `--safety` | Safety-orange accent (rails, active states, the `bcc-rail-dot`). |
| **Phosphor** | `--phosphor` | CRT-green "live" / on-chain readout (`.bcc-phosphor-text`, `.bcc-phosphor-dot`). |
| **Ink** | `--ink` (+ `-soft`) | Type color on cardstock. |
| **Crest** | `.bcc-hex-*` classes | Hexagonal entity emblem (nested cardstock/chain-color rings). |

---

## 10. Supported chains

Seeded into `bcc_chains` by `bcc-trust/includes/database/schema-chains.php`. The
`native_token` column is the chain's token symbol; `chain_type` selects the fetcher.

| Slug | Name | Token | Type |
|---|---|---|---|
| ethereum | Ethereum | ETH | evm |
| polygon | Polygon | MATIC | evm |
| arbitrum | Arbitrum One | ETH | evm |
| optimism | Optimism | ETH | evm |
| base | Base | ETH | evm |
| avalanche | Avalanche C-Chain | AVAX | evm |
| bsc | BNB Smart Chain | BNB | evm |
| cosmos | Cosmos Hub | ATOM | cosmos |
| osmosis | Osmosis | OSMO | cosmos |
| akash | Akash | AKT | cosmos |
| juno | Juno | JUNO | cosmos |
| injective | Injective | INJ | cosmos |
| cryptoorgchain | Cronos POS | CRO | cosmos |
| jackal | Jackal | JKL | cosmos |
| kujira | Kujira | KUJI | cosmos |
| dungeon | Dungeon Chain | DGN | cosmos |
| thorchain | THORChain | RUNE | thorchain |
| polkadot | Polkadot | DOT | polkadot |
| solana | Solana | SOL | solana |
| near | NEAR Protocol | NEAR | near |

Retired: **stargaze** (STARS) — the stargaze-1 L1 halted June 2026 after the
Prop-1017 migration to the Cosmos Hub; its CW-721 collections now live on the
`cosmos` chain as re-instantiated `cosmos1…` contracts
(`bcc-trust/includes/database/retire-stargaze-chain.php` removed the row).
