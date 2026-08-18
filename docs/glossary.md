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
| **Rank** | An earned capability label derived from a member's feature-access **level**. Three rungs: **Apprentice → Journeyman → Veteran**. A tenure signal, not a competence one — the gates are 5 pulls (own following count), 3 vote rows, and 30 days of account age. Surfaced as a pre-rendered `rank_label` on the member card. Renamed from **Master** in contract v1.58; "Master" is reserved for a future merit rung and must not be reused as a label until it is earned from outcome data. | `RankCatalog.php` (`RANK_APPRENTICE`/`RANK_JOURNEYMAN`/`RANK_VETERAN`); `RankService::LEVEL_TO_RANK` maps `new→apprentice`, `active→journeyman`, `veteran→veteran` (`bcc-trust/app/Domain/Core/Services/RankService.php`). Rendered uppercase in `bcc-frontend/src/components/profile/RankChip.tsx`. |
| **Foreman** | A conferred moderator-authority **role** that was scoped but **never built** — no conferral path ever existed. **Retired for V1** (contract v1.36): the `foreman_insignia` / `is_admin_conferred` placeholders and the `bcc_user_ranks` table are gone. Not emitted anywhere. **Frontend remnants removed 2026-07-28** — the `isForeman` prop, the `--bcc-trust-foreman` token, the rank-explainer callout, the onboarding demo card, and the `GET /members?rank=` filter UI all outlived the contract by 19 days; the filter was sending a param the server had already stopped reading, so every chip returned identical unfiltered lists. Docs and code now agree: there is no fourth rank. | Retired — see api-contract-v1.md §4.8 / v1.36 changelog. Guarded by `bcc-frontend/src/components/identity/rank-ladder.test.ts`. |

> **Rank vs Trust Tier** are the two live independent axes. Rank = earned capability
> (a relabel of the feature-access **level**). Trust Tier = reputation quality (see §3).
> A member holds one value on each. (A third **Foreman** role axis was scoped on
> 2026-06-22 but never built and is retired for V1 — see api-contract-v1.md v1.36.)

> **Dot vs. word, product-wide convention:** a dot on a chip means it's telling you
> something about trust; no dot means it's stating a plain fact. `RankChip` renders
> the rank as text and the trust tier as the accompanying dot — ranks carry no color
> of their own. (`bcc-frontend/src/components/profile/RankChip.tsx`)

---

## 2. Entities & cards

| Term | What it is in code | Backing code |
|---|---|---|
| **Card** | The view-model render unit for any entity. The frontend `CardKind` union is the source of truth for what can be a card. | `CardKind = "validator" \| "project" \| "creator" \| "member" \| "community"` (`bcc-frontend/src/lib/api/types.ts`). |
| **Validator** | An on-chain validator entity page. Canonical page type `validator`. | `PeepSoPageRepository.php` (`validators → validator`). |
| **Project** | A crypto project/protocol entity page. Canonical page type `project`. | `PeepSoPageRepository.php`. |
| **NFT Creator** | An NFT-minting entity page. Canonical page type `nft`; rendered as `creator` card kind. | `PeepSoPageRepository.php` (`nft-creators → nft`); `CardKind` `creator`. |
| **DAO** | A DAO entity page. Canonical page type `dao`. **Orphaned on the frontend** — `CardKind` (`"validator" \| "project" \| "creator" \| "member" \| "community"`) has no `dao` value, so the badge on a member's dossier renders but there is no DAO card, directory filter, or page to link to. | `PeepSoPageRepository.php` (`daos → dao`); `CardKind` (`bcc-frontend/src/lib/api/types.ts`). |
| **Builder** | **Not a distinct entity.** Builders are aliased to the `project` page type; there is no separate builder card or block. | `PeepSoPageRepository.php` (`builders → project`). |
| **Community / Hall** | A PeepSo Group rendered as a `community` card. **Halls** are auto-provisioned one per active chain (system-created, open); a member can join multiple and designate one as **primary** (`primary_hall`), shown on their card. Browsable at `/halls`. | `CardKind` `community`; `primary_hall` + `PrimaryHallChip` (`bcc-frontend/src/components/cards/MemberDossier.tsx`); routes `bcc-frontend/src/app/(main)/(app)/halls/page.tsx`, `.../halls/[slug]/page.tsx`. |
| **Piece** | A single NFT, served as a view-model. | `NftPieceViewModelBuilder` (`bcc-trust/app/Domain/Onchain/Services/`); `NftPieceEndpoint` (`bcc-trust/app/Domain/Onchain/REST/`). |
| **Collection** | A group of NFTs by one creator. | `bcc_onchain_collections`; `CollectionRepository`, `CollectionService` (`bcc-trust/app/Domain/Onchain/`). |

> **Removed:** *Edition* and *Series* — no table, field, or service exists for either. (The
> prior claim that "Series = `bcc_onchain_collections.title`" was incorrect; `title` is just
> the collection name.)

> **Community gating.** `GroupDiscoveryType` is `nft \| hall \| system \| user` — nft-gated
> holder groups, trade-hall communities, platform-created, and member-created respectively.
> `trust_min` is `25 \| 50 \| 75 \| null`, a real reputation-score threshold to join, locked
> at creation for `privacy="trust"` groups. Communities carry no trust axis of their own — no
> rank, tier, or vouch — which is why their card's second action button is **Join**, not
> **Vouch**. (`GroupDiscoveryType`, `trust_min` — `bcc-frontend/src/lib/api/types.ts`.)

---

## 3. Reputation & scoring

| Term | What it is in code | Backing code |
|---|---|---|
| **Trust score** | A page's numeric reputation, stored as `total_score` (with `positive_score` / `negative_score` components). There is **no** field named `reputation_score`. | `bcc_trust_page_scores` (`bcc-trust/includes/database/schema-core.php`). |
| **Reputation tier** | A categorical key derived from the score: `elite` / `trusted` / `neutral` / `caution` / `risky`. | Stored as `reputation_tier` on `bcc_trust_page_scores`. |
| **Trust Tier label** | The honest member-facing label for the reputation tier: **Risky / Caution / Neutral / Trusted / Elite** (`elite → "Elite"`). **Derived at read time**, not stored. | `ReputationTierMap.php` (`bcc-trust/app/Domain/Core/Support/`), emitted as `reputation_tier_label`. |
| **Card-rarity tier** | ~~An entity card's rarity classification.~~ **RETIRED (contract v1.57).** The `legendary`/`rare`/`uncommon`/`common` vocabulary and its `card_tier`/`tier_label` fields are gone. Cards carry the Trust Tier above, on every surface, for all five bands. The retirement is documented in `ReputationTierMap.php`'s class docblock; the short version is that the ordinal was inverted (`neutral` is the *starting* tier, so by population it was the common one), the labels made a claim about a denominator we are deliberately moving, they were positive-coded on a warning band, and `risky` had no slot at all — so the most safety-relevant state in the system rendered as nothing. Do not reintroduce. | Retirement rationale: `ReputationTierMap.php`; [api-contract-v1.md](api-contract-v1.md) §10 v1.57. See also [reputation-tokenization-policy.md](reputation-tokenization-policy.md) on why scarcity language and earned tiers should not be mixed. |
| **Divergence state** | A five-state synthesis classifier over an entity's attestation pattern: `untested`, `well_regarded`, `poorly_regarded`, `polarizing`, `disputed`. Mutually exclusive. **Mostly theoretical today** — `polarizing` needs a reliability cache that hasn't shipped, so it can never fire; `poorly_regarded` needs revocations to exceed active attestations by 1.5×. In practice it resolves to `untested` (new accounts) or `well_regarded` (everyone else); don't build UI assuming all five states occur. | `DivergenceState` (`bcc-frontend/src/lib/api/types.ts`), §J.2/§J.4/§J.5/§J.10. |
| **Fraud score** | An anti-abuse signal from behavioral + device analysis. | `BehavioralAnalyzer.php`, `DeviceFingerprinter.php` (`bcc-trust/app/Domain/Core/Security/`). |
| **Read model** | The denormalized fast-query table the API reads from. | `bcc_page_read_model`; `PageReadModelRepository` (`bcc-trust/app/Domain/Core/Repositories/`). |

---

## 4. Trust actions

| Term | What it is in code | Backing code |
|---|---|---|
| **Vote** | The quantitative reputation action backing a Review. | `bcc_trust_votes`; `VoteService`, `VoteRepository` (`bcc-trust/app/Domain/Core/`). |
| **Attestation** | The current first-class trust statement layer. Kinds: `vouch` (labelled "Vouch") and `stand_behind` (labelled "Back" / "Backing" — §J.7). Carries `weight_at_time`, `context_note`, `target_kind`/`target_id`. | `bcc_trust_attestations` (`bcc-trust/includes/database/schema-trust-attestations.php`); `AttestationService`, `AttestationRepository`. |
| **Vouch** | A per-author credibility toggle (one vouch / one weight per person), rendered next to the author's name — **not** a post reaction. Writes an attestation with `kind=vouch`. | `AuthorVouchButton` (`bcc-frontend/`); `bcc_trust_attestations`. |
| **Endorse** | **Legacy wire vocabulary only (v1.50).** An "endorsement" is a `kind=vouch` attestation on an entity page — the `/endorse` endpoint writes through the attestation layer, and all display labels say **Vouch** per the §J.7 label table. The name survives on the wire (`/endorse` routes, `endorsement_count`, `viewer_has_endorsed`) but in no user-facing copy. The legacy `bcc_trust_endorsements` table is **dropped** (`drop-endorsements-table.php`); rows were migrated to attestations (`kind=vouch`). | `EndorsementService` (vouch-aligned adapter), `TrustRestController::endorse`, `drop-endorsements-table.php` (`bcc-trust/includes/database/`). |
| **Reactions** | BCC seeds exactly two reaction types: `solid` (trust grammar — "agree" / drives the solids-received stat) and `fire` (social grammar). Other social kinds (like/love/haha/wow) are PeepSo defaults, not BCC-seeded. | `ReactionTypeRegistry.php` (`KIND_SOLID`, `KIND_FIRE`; `TRUST_KINDS = [solid]`, `ALL_KINDS = [solid, fire]`); seeded into option `bcc_reaction_ids`. |
| **Stoke** | The post-level reaction UI brand name for the seeded `fire` reaction kind — the flame. `heat_stage` (1–5, velocity-weighted + time-decayed) grades the flame's appearance; `viewer_has_stoked` drives its fill; `stoke_count` is the public count. All three fields are optional on the wire — absent means Stoke hasn't shipped yet for that surface. Comments carry a separate, simpler Stoke pair with no `heat_stage`. | `heat_stage`/`viewer_has_stoked`/`stoke_count` (`bcc-frontend/src/lib/api/types.ts`); rendered via `ReactionRail`. |

**Reaction grammars** (`bcc-frontend/src/components/feed/ReactionRail.tsx`): `trust`
(restrained, signs your name), `social` (expressive, emoji-forward, includes Fire), and
`tribal` (reserved for V2 — renders nothing).

> **Wire name ≠ label — `stand_behind`:** the `stand_behind` *reaction* was retired in Slice 3
> (hard-deleted, no longer seeded by `ReactionTypeRegistry`); the identifier survives only as an
> **attestation kind** (§ Attestation above). Its user-facing label was "Stand Behind" until
> 2026-07-28 and is now **"Back"** (the action) / **"Backing"** (the state) per the §J.7 label
> table — but the wire name is deliberately frozen, because it is a stored `kind` enum value and
> the root of four view-model field families, the notification type, and three persisted
> preference keys. Do not "converge" it. Same precedent as **Endorse** above. The people backing
> something are labelled **Supporters** in the UI (`supporters_tab`, `bcc-frontend/src/lib/copy/trust-layer.ts`).

---

## 5. Watch / follow

| Term | What it is in code | Backing code |
|---|---|---|
| **Watch** | The canonical action for adding an entity to your watchlist. Routes under `/bcc/v1/me/watching`. | `WatchingEndpoint.php` (`bcc-trust/app/Domain/Core/REST/`): `GET /me/watching`, `GET /me/watching/summary`, `POST /me/watching/watch`, `DELETE /me/watching/{follow_id}`. |
| **Follow (storage)** | The underlying relationship lives in PeepSo's follow graph; a watch is an active follow row. | `peepso_user_followers` (`uf_follow = 1`), via `WatchingRepository`. |
| **Watch sidecar tables** | BCC metadata attached to watches. | `bcc_watch_meta`, `bcc_watch_batches` — renamed from `bcc_pull_meta` / `bcc_pull_batches` on 2026-06-26 by `bcc-trust/includes/database/rename-pull-to-watch.php`. |

**Rendered UI copy** (`bcc-frontend/src/lib/copy.ts`, `FOLLOW_COPY`): CTA **"Watch"**,
active state **"Watching"** (active CTA **"Watching ✓"**), group noun **"Watchers"**. The
collection surface is labelled **"Watchlist"** (`bcc-frontend/src/components/onboarding/DopamineStep.tsx`).

> The legacy "Pull"/"Binder" vocabulary and the `/me/binder/*` routes were removed (routes
> on 2026-06-10; physical table rename 2026-06-26). No `pull`/`binder` route or table remains.

> **Wire inconsistency, patched client-side.** The server labels this audience count
> "Followers" on entity cards and "Watchers" on member cards — same PeepSo graph, two words.
> The frontend normalizes both to `FOLLOW_COPY.noun` so adjacent cards never show two words
> for the same thing (`Nameplate` in `bcc-frontend/src/components/cards/CardFrontFace.tsx`);
> the server itself hasn't converged the wire label yet.

---

## 6. Claim & wallet

| Term | What it is in code | Backing code |
|---|---|---|
| **Claim** | The process by which a member proves ownership of an entity page via wallet signature, flipping the claim to `verified`. **Only validators and NFT collections are claimable** (`CardClaimTarget.entity_type` is `"validator" \| "collection"`) — both have an on-chain record to prove against. Projects have no on-chain entity backing them, so they can never be unclaimed; they're user-created from the start. | `ClaimService`, `ClaimRepository`, `ClaimStatus` (`pending` / `verified` / `revoked`) — `bcc-trust/app/Domain/Onchain/`; `bcc_onchain_claims`; `CardClaimTarget` (`bcc-frontend/src/lib/api/types.ts`). |
| **Sign** | The wallet challenge/response that authenticates a member or backs a claim. | `WalletAuthController` (`bcc-trust/app/Domain/Core/REST/Auth/`). |
| **Wallet link** | A verified wallet ↔ user ↔ entity association. | `bcc_wallet_links` (`DB::table('wallet_links')`); `WalletRepository`. |

> **"Claim" is overloaded.** A member's `unresolved_claims_count` counts open **disputes**,
> not page claims — never render it as "claims" in the UI; say **open disputes**.

---

## 7. On-chain layer

| Term | What it is in code | Backing code |
|---|---|---|
| **Signal** | An indexed on-chain fact (rows carry `role`, `chain`, `score_contribution`, `trust_boost`, `fraud_reduction`, etc.). Surfaced in the feed as `post_kind: "signal"`. | `bcc_onchain_signals`; `SignalRepository` (`bcc-trust/app/Domain/Onchain/Repositories/`). |
| **Fetcher** | A per-chain indexer class pulling data on a schedule. | `bcc-trust/app/Domain/Onchain/Fetchers/`: `EvmFetcher`, `SolanaFetcher`, `CosmosFetcher`, `ThorchainFetcher`, `PolkadotFetcher`, `NearFetcher`. |
| **Chain registry** | Normalized lookup of supported chains (RPC/explorer/token metadata). | `bcc_chains` (`DB::table('chains')`); `ChainRepository`; seeded in `bcc-trust/includes/database/schema-chains.php`. |

> **Validator stake vocabulary (card back face).** Fixed labels, not up for debate: **Total
> Stake · Self Delegation · Delegators · Commission · Voting Rank**. The wire field is
> `self_stake`; "self-delegation" and "self-stake" are the same number under two names from
> two conventions. Delegators and stakers are the same people.
> (`bcc-frontend/src/components/cards/CardOnchainSignals.tsx`)

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

The frontend has **two intentional surface families**, and this table covers the second one.
Both are current:

1. **Theme-aware application surfaces** — the app chrome (`.bcc-panel`, `bg-bcc-surface*`,
   page background). These use the `--bcc-*` token set and the theme text scale
   (`--bcc-text`, `-secondary`, `-muted`), and flip with `[data-theme]`.
2. **Fixed cream/ink paper surfaces** — the "worksite paper" object (`.bcc-paper`,
   `bg-cardstock*`, solid `--ink` blocks): empty states, record sheets, bio blocks, stream
   boxes, cover/avatar editors. These use the tokens below and the fixed ink text scale, and
   deliberately do **not** flip with theme.

The binding rule is that the text palette must match its surface family. Fixed ink on a fixed
cream surface is correct; fixed ink on a theme surface is a dark-mode bug. Full description:
`bcc-frontend/docs/frontend-doctrine.md` §5.

| Token | CSS var | Use |
|---|---|---|
| **Cardstock** | `--cardstock` (+ `-deep`, `-edge`) | Cream paper surface — the `.bcc-paper` sheet and cardstock-backed controls across app chrome (empty states, record sheets, editors), plus the trading-card crest. Not card-only. |
| **Paper** | `--paper` (+ `-warm`, `-hi`, `-pale`, `-cream`) | Warmer/brighter cardstock variants; `--paper` backs `.bcc-paper`. |
| **Concrete** | `--concrete` (+ `-hi`) | Near-black warehouse-floor background. |
| **Safety** | `--safety` | Safety-orange. The established micro-eyebrow label (`bcc-mono text-safety`) above headings and empty states, inline validation/error text, rails, and the `bcc-rail-dot`. Used on both surface families. |
| **Weld** | `--weld` | Arc-weld yellow — caution tape, stencil stamps, grade badges and `.bcc-paper-head` kickers. Fixed-dark only: 11.84:1 on `--ink`, 1.64:1 on white. **Not a warning colour** — warning states use `--bcc-warning` (theme-scoped). |
| **Blueprint** | `--blueprint` | Deep navy — dark inset blocks and blueprint-style chrome. |
| **Phosphor** | `--phosphor` | CRT-green "live" / on-chain readout (`.bcc-phosphor-text`, `.bcc-phosphor-dot`). |
| **Ink** | `--ink` (+ `-soft`, `-ghost`) | The fixed type scale for cream/paper surfaces, and a solid dark surface (`bg-ink`) in its own right. |
| **Crest** | `.bcc-hex-*` classes | Hexagonal entity emblem (nested cardstock/chain-color rings). |
| **Trust ramp** | `--bcc-trust-{risky,caution,neutral,trusted,proven}` | The **only** tier palette — RankChip dot/pill, card tier strip, avatar rings, search rows. Stable across themes. (`-elite` is a legacy alias for `proven`.) The rarity palette it replaced was retired in v1.57. |
| **Kind** | `--kind-{member,validator,project,creator,community}` | Trading-card frame color. Data, not chrome — does not flip with theme. |

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

---

## 11. Verification

Three identity-proof signals, all under `UserViewService::getProfile`.

| Term | What it is in code | Backing code |
|---|---|---|
| **X verified** | `x_verified` (boolean) + `x_username`. True only when an active, verified row exists. | `bcc_trust_user_verifications`; `bcc-frontend/src/lib/api/types.ts`. |
| **GitHub verified** | `github_verified` (boolean) + `github_username`, same verification table. | `bcc_trust_user_verifications`. |
| **Wallets verified** | `wallets_verified` — a **count**, not a boolean, and the only wallet signal permitted to cross a member boundary. | `MemberProfile.verifications.wallets_verified` (`bcc-frontend/src/lib/api/types.ts`). |
| **Profile completeness** | `profile_completeness` (0–100), the PeepSo profile-fields completeness percentage. On the wire but rendered nowhere on the card today; surfaced on `/me/progression`. | `bcc-frontend/src/lib/api/types.ts`. |

There's a bonus when the X and GitHub accounts share an email address, since that's evidence
they're the same person.

> **Per-wallet detail is own-account only.** `MemberProfile.wallets` is `[]` for every other
> viewer — never use its `.length` as a "has a wallet" signal; use `wallets_verified`. See
> `wallet-privacy-policy.md` (in this `docs/` folder).
