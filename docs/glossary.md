# BCC — UI / Product Glossary

**Purpose:** a shared dictionary for everything we've been discussing. Every term from the prototypes and conversation is defined here, organized by category. Each entry has a status flag:

- 🔒 **Locked** — confirmed or unambiguous
- 🟡 **Tentative** — my working definition, please confirm or override in `planning-questions.md`
- 🎨 **Flavor** — a design word I chose, can be renamed freely
- 🚫 **V1 NOT USED** — term is reserved / parked; do not implement in V1

---

## 0. UX language rules (read these first)

These rules govern how the brand vocabulary in this glossary is exposed in the UI. They protect new users from being forced to learn the world before they can use the product.

**Rule 1 — Action before vocabulary.** The user must understand what's about to happen *before* they have to learn what we call it. Plain-English action labels lead; branded names are secondary.

**Rule 2 — Branded names ship with helper labels.** When a branded term is used in primary UI, it gets a descriptive companion (tooltip, sub-label, or helper text). The branded name becomes the only label only after the user has demonstrated familiarity.

**Rule 3 — Cards are the primary interaction unit.** Every entity (member, validator, project, NFT creator) lives behind a card. All key actions (follow / view / review / watch) are accessible directly from a card. UI patterns are designed card-first.

**Rule 4 — System terms ≠ UI terms.** The API, schema, and code use plain system terms (`follow`, `feed`, `endorsement`). The UI uses branded terms (`Watch`, `The Floor`, `Letter from the Floor`). The two never have to match.

**Rule 5 — V1-NOT-USED terms must not appear in shipping UI.** If a term is marked 🚫, it does not get rendered to users in V1, even as flavor copy. Reserved for later.

---

## 1. People & Roles

| Term | Definition | Status |
|---|---|---|
| **Member** | A basic community user with an account. Can follow, react, write reviews, sign disputes, watch cards. | 🔒 |
| **Operator** | A member who has claimed and verified ownership of a validator page via wallet signature. Can post AS the validator. | 🔒 |
| **Creator** | A member who has claimed and verified ownership of an NFT-creator page (same mechanism as operator). | 🔒 |
| **Delegator** | A user who has staked on-chain with a validator. Proven by `bcc_onchain_signals` rows. | 🔒 |
| **Collector** | A user who holds NFTs from a creator. Proven on-chain. | 🔒 |
| **Backer** | A user who supports a project. **Not used in V1.** Reserved for V2 — will tie to token holdings, funding, or governance once those concepts land. Do not surface in shipping UI. | 🚫 |
| **Rank** | A member's **earned capability ladder**, auto-derived from their feature-access **level** (activity-based, *not* reputation): **Apprentice → Journeyman → Master**. Shown on the member's card. Orthogonal to Trust Tier and to Role — a member holds one value on each axis independently. | 🔒 |
| **Apprentice** | Entry rank. Every new member starts here (feature-access level *New*). | 🔒 |
| **Journeyman** | Middle rank (feature-access level *Active*). | 🔒 |
| **Master** | Top earned rank (feature-access level *Veteran*). Replaces Foreman as the ladder's top rung — reaching it is purely a capability milestone, never an authority grant. | 🔒 |
| **Foreman** | A **conferred Role**, *not* a rank — community/panel moderator authority. Assigned (admin-conferred), never auto-earned. Orthogonal to Rank: a Journeyman can be a Foreman, and reaching Master does **not** make a member a Foreman (otherwise every highly-active user would drift into moderator status). Surfaced as a separate `foreman_insignia` flag, never folded into the rank label. | 🔒 |

---

## 2. Entities (things that get cards)

| Term | Definition | Status |
|---|---|---|
| **Card** | The visual representation of any entity: a **member, validator, project, or NFT creator**. The "atomic unit" of the UI — everything else is a collection of cards. | 🔒 |
| **Validator** | An on-chain validator node (Cosmos, EVM, Solana, etc.). Has a PeepSo page, auto-populated from chain data. | 🔒 |
| **Project** | A crypto project / protocol (e.g., Osmosis, Injective). PeepSo page type. | 🔒 |
| **NFT Creator / Studio** | An artist minting NFTs. "Studio" is alternative copy used in the creator profile. | 🔒 |
| **DAO** | A decentralized autonomous organization. One of the four PeepSo page types. Not yet prototyped. | 🔒 |
| **Builder** | A developer / contributor. One of the four PeepSo page types (currently rendered in the `bcc-builder-card` block). | 🔒 |
| **Piece** | A single NFT from a creator's collection. | 🔒 |
| **Collection** | A group of related pieces by one creator (e.g., FOUNDRY Series 01). | 🔒 |
| **Edition** | A specific numbered instance of a piece (e.g., FOUNDRY #014 of 50). | 🔒 |

---

## 3. Actions (things users do)

### 3a. Watch / Follow — split (replaces the retired Pull / Follow split)

Two terms, one relationship. Per UX Rule 4: the system uses one term, the UI uses the other.

| Term | Where it lives | Definition | Status |
|---|---|---|---|
| **Watch** | UI label + API verb | The button text + canonical API verb for adding an entity's card to your watchlist. Button: **"Watch"** / **"Watching"** (cast state). Endpoint: `POST /bcc/v1/me/watching/watch`. | 🔒 |
| **Follow** | Storage / underlying relationship | The underlying relationship (a `peepso_follower` row). Database, logs, admin tools all reference the follow graph. "Watch" is what the UI and the public API call the same write. | 🔒 |

**Why both:** "Watch" is the canonical interaction verb (UI + API); "Follow" is the underlying storage relationship that PeepSo owns. We keep the brand on the action, the universal word in the storage layer.

**Legacy "Pull" vocabulary (retired 2026-05-13):** the previous version of this row mapped "Pull" → UI / "Follow" → System. As of 2026-05-13 (full Binder → Watching rename, see `docs/api-contract-v1.md §1.1.1`), "Pull" is retired as user-facing vocabulary. It survives only as legacy physical names on internal tables (`bcc_pull_meta`, `bcc_pull_batches`) and on the deprecated `/me/binder/pull` route, which is removed in release N+1.

### 3b. Claim & sign

| Term | Definition | Status |
|---|---|---|
| **Claim** | When an operator/creator proves ownership of a page via wallet signature. Flips the page from auto-generated to verified. | 🔒 |
| **Sign** | (a) To sign a wallet message during claim. (b) To add your name to a dispute, meaning you back it with your on-chain stake/reputation. | 🔒 |

### 3c. Reactions — branded names ship with descriptive helper labels

Per UX Rule 2: every reaction has a branded name AND an always-visible UI helper label. The helper label conveys *strength of commitment* immediately, without users having to learn the lore.

| Branded name | UI helper label | Meaning | Icon direction | Status |
|---|---|---|---|---|
| **Solid** | 👍 *Agree* | "This is right / I agree." The default affirmation. | A check-style mark. | 🔒 |
| **Vouch** | 🤝 *Back this* | Stronger. "I'll publicly back this claim." | A handshake. | 🔒 |
| **Stand behind** | 🛡️ *Stake my rep* | Strongest. "You can stake my reputation on this." | A shield / stamp. | 🔒 |

Helper labels render as italic sub-labels under the chip in normal use, and as the *only* label until a user has clicked their first reaction. Icon set ships separately from the names — icons can iterate without renaming.

### 3d. Other actions

| Term | Definition | Status |
|---|---|---|
| **Comment** | Reply to a post. Standard PeepSo comment mechanism. | 🔒 |
| **Share** | Re-share a post to your own stream / externally. | 🔒 |
| **Endorse** | Existing `bcc_trust_endorsements` action. Positive statement with on-chain weight. A "Letter from the Floor" is a long-form endorsement. | 🔒 |
| **Vote** | Existing `bcc_trust_votes` action. The quantitative, short-form version of a review. | 🔒 |

---

## 4. Content types (things that get posted)

| Term | Definition | Status |
|---|---|---|
| **Post / Update** | A free-text status posted to the Floor. | 🔒 |
| **Review** | A structured opinion with a letter grade (A+ to F), body text, and an attached subject card. A full review is a vote + long-form reason. | 🔒 |
| **Dispute** | A formal complaint filed against an entity, with grounds + evidence. Signed by one or more members. | 🔒 |
| **Signal** | An on-chain event indexed by BCC (new delegation, missed block, commission change, NFT mint, governance vote). Drives the ticker and system posts. | 🔒 |
| **Letter (from the Floor)** | A long-form endorsement written about another member. | 🔒 |
| **Announcement** | An operator/creator/project posts news as the entity (not as themselves). Rendered with OPERATOR/CREATOR badge. | 🔒 |
| **Post-mortem** | A specific kind of announcement where an operator explains an incident (missed blocks, downtime, slashing, etc.). | 🔒 |
| **Drop** | A creator's new NFT release (single or edition). | 🔒 |
| **Release** | A project's software/protocol update (e.g., "Osmosis v24 Forge"). | 🔒 |
| **Pin** | A special post marked as featured/sticky at the top of a surface. | 🔒 |
| **Pack** | **Not used in V1.** A group of cards released together. Risks pulling in lootbox / gacha mechanics that don't fit the platform's tone. Reserved for explicit re-evaluation in V2+. Do not surface in shipping UI. | 🚫 |

---

## 5. Surfaces (places in the UI)

| Term | Definition | Status |
|---|---|---|
| **The Floor** | The main activity feed — the social-hub homepage. Where all public posts land. **Dual-labeled until familiar:** new users see *"The Floor (Activity Feed)"* in the nav and section headings. Once a user has visited the Floor 3+ times, the helper drops to just *"The Floor."* The tooltip on hover always reads "Activity Feed." | 🔒 |
| **Watchlist** | A member's personal collection of watched cards. Looks like a 3-ring binder with 3×3 card pages (visual metaphor preserved — the *name* "Binder" was retired 2026-05-13; the visual layout is unchanged). Backed by `peepso_user_followers` + the legacy-named `bcc_pull_meta` sidecar table. | 🔒 |
| **Stream** | A single user's or entity's personal post timeline (their profile's post feed). | 🔒 |
| **Studio Log** | A creator's stream — specifically branded for the artist-workshop context. | 🔒 |
| **The Record** | The on-chain, verifiable truth section on an entity profile (uptime, governance, slashing, commission history). No operator input. | 🔒 |
| **Hot on the Floor** | The trending sidebar on the Floor feed. Top cards / projects / NFTs / disputes this hour. | 🔒 |
| **Live Signals** | The phosphor-green dot-matrix ticker showing on-chain events in near-real-time. | 🔒 |
| **Shift Log** | The 52-week contribution grid on a member profile — visualizes platform activity by day. Section heading: *"Activity (Shift Log)"*. | 🔒 |
| **Recent Shift** | The phosphor-green column on a profile showing the user's last few actions. Section heading: *"Recent Activity (Recent Shift)"*. | 🔒 |
| **Studio Signals** | Creator-page equivalent of Live Signals — mints, sales, follower deltas. | 🔒 |
| **Gallery** | A creator's grid of works. | 🔒 |
| **Featured Drop** | A creator's current/hero release, shown as a big exhibition-poster panel. | 🔒 |
| **Wanted Poster** | The large claim-CTA block shown on an unclaimed validator/creator page. | 🎨 |
| **Patches** | The grid of achievement badges on a member profile (Genesis, 50 Reviews, Zero Flags, etc.). | 🎨 |
| **Union Card** | The credentials-display panel on a member profile — fact sheet + signature + "In Good Standing" stamp. | 🎨 |
| **Letters from the Floor** | The section on a member profile showing 3 long-form endorsements from other members. | 🔒 |
| **The Beat** | The section on a member profile showing which chains they're most active on. **Section heading is "Activity by Chain (The Beat)"** — branded name is the secondary label. | 🎨 |
| **Who he / she backs** | The member-profile section showing which validators they delegate to. | 🔒 |
| **Collectors** | The creator-profile section showing who holds their work. | 🔒 |
| **Delegator Network** | The validator-profile section showing top delegators. | 🔒 |
| **Years on the Floor** | The timeline section on a member feature profile showing their crypto / BCC journey milestones. **Section heading is "Member History (Years on the Floor)"** — branded name secondary. | 🎨 |

---

## 6. Visual & Design terms

| Term | Definition | Status |
|---|---|---|
| **Tier (entity-card rarity)** | An **entity card's** rarity classification: **Legendary / Rare / Uncommon / Common** — derived from the entity's reputation tier. Applies to collectible entity cards (validators, projects, creators, NFTs). This is *rarity vocabulary*, distinct from the member trust chip below. Field: `card_tier` / `tier_label`. | 🔒 |
| **Trust Tier (member chip)** | A **member's** reputation quality, shown with **honest trust names**: **Risky / Caution / Neutral / Trusted / Proven** (internal key `elite` → label "Proven"). Deliberately *not* rarity words — a *Caution* member must not read as "Common", and *Neutral* (the starting point) must not read as "Uncommon". Field: `reputation_tier_label`. Auto-derived; one of the three orthogonal identity axes (Rank · Trust Tier · Role). | 🔒 |
| **Foil** | The gold-holographic visual treatment reserved for Legendary-tier cards. | 🔒 |
| **Crest** | The hexagonal emblem with a monogram inside that serves as an entity's portrait on its card. | 🔒 |
| **Portrait** | The top art region of a card (contains the crest or, for NFTs, the artwork itself). | 🔒 |
| **Cardstock** | The cream-colored paper texture used for card backgrounds and content panels. | 🎨 |
| **Concrete** | The dark, near-black "warehouse floor" background of the site. | 🎨 |
| **Steel / Brushed steel** | The metallic grey used for the locker-panel hero on member profiles. | 🎨 |
| **Ticker** | The phosphor-green scrolling bar showing live signals. | 🔒 |
| **Ribbon** | The thin status banner across the top of a profile (caution-tape unclaimed / green-hatch Good Standing / green verified). | 🔒 |
| **Seal / Stamp** | A rubber-stamp visual (rotated slightly) used for "In Good Standing", "BCC Graded 9.8", "Verified Operator". | 🔒 |
| **Stencil** | The Big Shoulders Stencil Display typeface — all caps industrial lettering. Used for headlines and validator names. | 🔒 |
| **Signature** | (a) A member's short italic quote displayed on their profile. (b) Technical: a wallet signature for authentication. | 🔒 |
| **Caution tape** | The yellow-black diagonal-stripe pattern used for unclaimed status, disputes, and other warning states. | 🔒 |
| **Phosphor** | The neon-green glow used on the ticker and live signal panels — inspired by old CRT terminals. | 🎨 |
| **Blueprint grid** | The subtle faint-line grid across the warehouse-floor background. | 🎨 |
| **Card back** | The flipped face of a card, showing stats grid, uptime chart, career record, flavor quote. | 🔒 |

---

## 7. Community structure

| Term | Definition | Status |
|---|---|---|
| **Good Standing** | A member status displayed as a green ribbon at the top of their profile. **Definition (locked):** reputation tier ≥ neutral AND no active flags. Auto-derived; no admin toggle. UI surfaces this as an always-visible ribbon with a tooltip: *"No active flags + positive reputation."* | 🔒 |
| **Contribution / Consistency (internal trust-recovery signals)** | **Internal-only**, never a public score. Sustained positive participation (useful posts, helpful comments, reviews, upheld scam reports = *Contribution*; account age + consistent presence + clean record = *Consistency*) feeds a **capped** bonus into `reputation_score` so a user can climb out of **Risky** gradually. Hard rules: reactions never directly create trust (engagement only *multiplies* real contribution); contribution alone can't reach **Trusted/Proven** (a ceiling); engagement is weighted by the engager's tier. Identity stays Rank · Trust Tier · Role — no "Contribution Score" is shown. See `docs/trust-attestation-layer.md` §J.14. | 🔒 |
| **Local** | A real product construct. Locals are PeepSo Groups under the hood, named in the union-local convention (e.g., "Local 342 Cosmos Base Fan"). A user can join multiple Locals and designates **one as primary** — the primary Local shows on their card and biases their feed ranking. Switchable any time. *"Local 342"* is one specific Local, not the system itself. | 🔒 |
| **Series** | A "set" of cards released by a creator (e.g., Series 01 — Foundry). Used to organize creator collections. Stored as a `bcc_nft_collections.title` field on the creator's collections. | 🔒 |
| **Of the Month** | A featured-member honor (e.g., "Collector of the Month"). Admin-curated for V1 (not voted, not algorithmic). Reconsider mechanism in V2 once a community is in place. | 🔒 |
| **Badge Nº [0142]** | The unique member number displayed on a member card and Union Card. Auto-assigned sequentially at signup; never reused. Stored on `wp_user_meta` as `bcc_badge_no`. | 🔒 |
| **Studio Nº [0342]** | The creator equivalent of Badge Nº — a unique creator identifier. Auto-assigned sequentially when an NFT-creator page is first claimed. | 🔒 |
| **Genesis** | A member who joined before or at a chain's genesis block / platform launch. Gets a specific achievement patch. | 🔒 |
| **Streak** | Consecutive days of platform activity, shown on the Shift Log. | 🔒 |

---

## 8. Technical / on-chain terms

| Term | Definition | Status |
|---|---|---|
| **On-chain** | Data read directly from a blockchain. Tagged with a small green pill in the UI to distinguish from platform-only data. | 🔒 |
| **Platform / Off-chain** | Data generated by BCC itself (reviews, solids received, streak). Tagged with an orange pill. | 🔒 |
| **Signal** | See §4. An indexed on-chain event. | 🔒 |
| **Key set** | All wallet keys associated with a validator: operator address, consensus pubkey, any keys in `bcc_onchain_signals` for that validator. Used during claim to allow any one of them to sign. | 🔒 |
| **Trust score** | Numeric 0–100 reputation score from `bcc_trust_page_scores`. Drives the tier classification. | 🔒 |
| **Reputation tier** | Categorical label: elite / trusted / neutral / caution / risky. Maps to card tier (Legendary → hidden). | 🔒 |
| **Fraud score** | A numeric anti-abuse signal computed by `bcc-trust`'s behavioral analyzer + device fingerprinter. High = restricted actions. | 🔒 |
| **Read model** | `bcc_page_read_model` — the denormalized fast-query table that the UI reads from, cached via generation counters. | 🔒 |
| **Fetcher** | A per-chain indexer class in `bcc-trust/Onchain/Fetchers/` that pulls data from a chain on a schedule. | 🔒 |
| **Dispute panel** | 3 elite/trusted users randomly selected with IP diversity to adjudicate a dispute. | 🔒 |

---

## 9. Surface hierarchy

Surfaces are ranked by how often a user visits them and how much UX weight they carry. This drives navigation prominence, mobile prioritization (per J2), and what gets the most polish.

### 🟢 Primary — must-be-great, top-level nav

These are the entry points new users see first and return to most often.

- **The Floor (Activity Feed)** — the social-hub homepage
- **Profile pages** — member, validator, creator, project (and DAO/builder later)
- **Cards** — the atomic interaction unit; render correctly anywhere they appear

### 🟡 Secondary — feature surfaces, accessed via primary

Heavily used but reached *through* a primary surface, not directly.

- **Watchlist** (formerly "Binder," renamed 2026-05-13) — accessed from your own profile or a nav link
- **Gallery** — on a creator profile
- **Studio Log** — on a creator profile
- **Stream** — on any profile
- **Directory / Search** — discovery surface, accessed from the global search bar

### ⚪ Tertiary / contextual — sub-sections inside primary surfaces

Display surfaces. Not navigated to directly; live inside a profile or page.

- **Activity by Chain (The Beat)** — section inside member profile
- **Shift Log** — section inside member profile
- **Recent Shift** — column inside member profile
- **Letters from the Floor** — section inside member profile
- **The Record** — section inside entity profiles
- **Live Signals (ticker)** — atop the Floor
- **Hot on the Floor** — sidebar on the Floor
- **Featured Drop** — section inside creator profile
- **Wanted Poster** — claim CTA inside an unclaimed entity profile
- **Union Card** — section inside member profile
- **Patches** — section inside member profile

This hierarchy is the source of truth for: nav structure, mobile priority surfaces, performance budgets (primary surfaces get the strictest), and user testing focus.

---

## 10. Nicknames for chains (used in card bands)

| Chain | Full name | Ticker |
|---|---|---|
| Cosmos | Cosmos Hub | ATOM |
| Osmosis | Osmosis | OSMO |
| Injective | Injective | INJ |
| Kujira | Kujira | KUJI |
| Akash | Akash Network | AKT |
| Juno | Juno Network | JUNO |
| ETH | Ethereum | ETH |
| SOL | Solana | SOL |

Each chain has an assigned brand color in the design system (see `theme.json` tokens).

---

## Resolved naming questions — all locked

All previously-tentative terms are resolved. Recap:

- **Watch / Follow** — split. Watch = UI label + API verb, Follow = underlying storage relationship. (See §3a. Replaces the retired Pull / Follow split.)
- **Local 342** — real construct. Locals are PeepSo Groups; a user joins multiple, designates one as primary. (See §7.)
- **Rank · Trust Tier · Role — three orthogonal identity axes (locked 2026-06-22).** **Rank** (Apprentice → Journeyman → Master) is the earned *capability* ladder, auto-derived from feature-access **level** (activity), shown on the member card. **Trust Tier** (Risky / Caution / Neutral / Trusted / Proven) is auto-derived *reputation*, shown as the honest member chip (`reputation_tier_label`) — separate from entity-card rarity words. **Foreman** is an assigned **Role** (moderator authority), never auto-earned: reaching Master does not confer it. A member holds one value on each axis, in any combination. (See §1, §6.)
- **Good Standing** — locked. Auto-derived: tier ≥ neutral AND no active flags. (See §7.)
- **Shift Log / Recent Shift** — final names; section headings dual-label them ("Activity (Shift Log)").
- **Solid / Vouch / Stand behind** — locked names; ship with descriptive helper labels (Agree / Back this / Stake my rep). (See §3c.)
- **Wanted Poster** — keep as-is.
- **The Beat / Years on the Floor** — kept as branded names but always rendered with a plain-English primary heading.
- **Pack / Backer** — 🚫 V1 NOT USED. Parked for V2.
