# Features

Each section covers what the feature is for, where it lives, its rules and why, and its status. Endpoint shapes and error codes are defined by the contract ([`api-contract-v1.md`](../api-contract-v1.md)): look up the § given there, don't trust a summary here.

Path prefixes: `core:`, `trust:` and `search:` mean the plugin repos; `fe:` means bcc-frontend.

---

## Accounts and sign-in [VERIFIED]

**What members can do:**
- sign up and sign in with email plus a one-time code, or a password;
- use Google, X, or a wallet signature;
- add 2FA;
- recover their account by email;
- sign out everywhere.

**Where it lives:**
- Backend: `trust:app/Domain/Core/REST/Auth/*` (wallet auth is in `WalletAuthController`).
- Frontend: `fe:src/lib/auth.ts`, plus the `(auth)` route group.

**Rules:**
- **Members sign in only through the app's `/auth/*` routes.** WordPress's own login, XML-RPC and PeepSo AJAX logins are refused for non-admins (`WordPressSignInGate`). *Why:* the app routes carry the second factor, throttles and captcha step-up that WordPress's doors lack.
- **Password rules:** at least 8 characters everywhere, and breached passwords are refused.
- **Captcha:** a Turnstile step-up appears only after repeated failures. It is configuration-driven and off until keys exist.
- **Account changes:** changing email, changing password or deleting the account needs the current password **or** a wallet signature bound to that purpose.
- **Suspended members** can sign in **read-only**. The only change they may make is their password.

Contract: §1 (auth), §4.1 (wallet auth), §4.24 (wallets and recovery).

## Wallet linking and verification [VERIFIED]

**What it is.** A member proves control of a wallet by signing a challenge. A linked wallet supports:
- sign-in;
- validator and collection claims;
- holder-group eligibility;
- account recovery.

**Chain families** (`core:src/Crypto/WalletVerifier.php`):

| Family | Verification |
|---|---|
| EVM | EIP-191 `personal_sign` |
| Cosmos | ADR-036, including THORChain, whose prefix comes from the address |
| Solana | ed25519 |
| Polkadot | sr25519/ed25519/ecdsa. PHP has no sr25519, so the backend calls an authenticated internal route on the Next.js app that uses `@polkadot/util-crypto` |

**One implementation each** ([`pattern-registry.md`](../pattern-registry.md) "Wallets"):
- `Secp256k1`: low-S only.
- `Bech32`: a strict decoder, and the only Bech32 implementation.
- `CosmosAddress::bytesFromPubKey`: Keccak for Ethermint chains.
- **Never add a second implementation.**

**Challenge flow** (contract §4.1):
1. The member gets a nonce for (chain, address, purpose).
2. They sign the returned text verbatim.
3. They post it to `/auth/wallet-link` (signed in) or to `/auth/wallet-login` / `/auth/wallet-signup` (anonymous; a separate keyspace, so anonymous nonces cannot be replayed into a link).

Challenge rules:
- Challenges are single-use and expire after 5 minutes.
- A challenge is bound to its purpose: `verify`, `confirm_email_change`, `confirm_password_change` or `confirm_account_delete`.
- While an anonymous nonce is live, re-requesting returns the same one, so a stranger can't invalidate someone else's nonce.

**Identity rules:**
- Addresses are stored in canonical form. Polkadot, for example, uses prefix-0 SS58.
- A wallet already linked to another account gets a 409.
- Cosmos account and `valoper` addresses match on their underlying bytes.

**Privacy (binding, [`wallet-privacy-policy.md`](../wallet-privacy-policy.md)):**
- No other user ever sees a member's address. That includes a shortened form, an ENS name, balances, holdings or transaction history.
- Others see only a count (`verifications.wallets_verified`) and a "Wallet verified" badge.
- A member sees their own wallets only through `GET /wallets`.
- Egress is allowlisted and fails closed (`UserViewService` wallet egress checks).
- *Why:* a 2026-07 audit found many cross-user disclosure paths. Remediating them was a breaking contract change, v1.51.

## Validator pages and claims

**What it is.** The runner of a validator (an **operator**) claims its page. The page then shows an ownership badge, and the operator can:
- post announcements;
- run a delegator community;
- receive DMs.

NFT collections are claimed the same way by their **creator**. Only validators and collections are claimable.

### Claim by linked wallet [VERIFIED]

`POST /bcc/v1/pages/:id/claim` with `{entity_type, entity_id}`, routed through `trust:app/Domain/Onchain/Services/ClaimService.php`.
- **No signature at claim time.** The wallet must already be linked and verified. [HISTORICAL] Older docs and the glossary describe a signature in the claim; the contract was corrected in v1.258.
- **Validator:** the member's verified wallet on the right chain must be the operator address. Delegators never match.
- **Collection:** the owner or creator matches; a holder is refused. A Solana creator is identified by its update authority.
- **No proof possible:** a validator whose operator BCC cannot prove answers `not_claimable` *before* any wallet check, so nobody is told "wrong wallet" when no wallet could work.
- **Concurrency and records:** a per-page advisory lock is held, `bcc_page_claimed` is emitted and an audit row is written. Uniqueness is backed by a DB constraint, and the runtime resolver returns UNCLAIMED, RESOLVED or AMBIGUOUS and fails closed.

### Weekly re-verification [VERIFIED]

`ClaimReverifySweepService` runs weekly with three outcomes: **KEEP**, **REVOKE** (a closed list of definite reasons only) or **UNKNOWN** (never revokes; retried next week).
- A jailed, inactive or out-of-set validator is **kept**.
- Disconnecting the wallet revokes the claims that depend on it.
- The `bcc_claim_reverify_mode` option is either `report` (audit only) or `enforce`.
- A renounced creator (zero address) keeps the badge.

### Staff linking [VERIFIED]

`ValidatorRelationshipService` lets staff link, revoke or cancel a page owner, with an evidence type (`website`, `keybase`, `manual`) and a **step-up code**.
- **Rule D3:** staff may act only on a staff-approved owner. A wallet- or key-proven owner is locked against staff action.
- **Public badge:** `owner_verification.kind` is `wallet`, `node_key` or `staff`. It never carries the method detail or a date.
- **Page ownership:** moved after commit by `PageOwnershipAligner`.
- **Admin UI:** validator admin screens under `fe:/admin/validators`.

### Self-send (memo) claim [PLANNED; building blocks VERIFIED]

This lets a Cosmos validator prove ownership by sending a tiny transaction from the operator account with a one-time code in the memo, without connecting a browser wallet.

**Merged:**
- `core:src/Wallet/ValidatorMemoChallenge.php`. The memo carries a code only, never a handle.
- `core:src/Crypto/Cosmos/CosmosTxVerifier.php`. A pure verifier: strict protobuf, local signature check, single `MsgSend` from and to the operator.
- `trust:app/Domain/Onchain/Services/CosmosTxInclusionFetcher.php`. Two pinned endpoints must agree on the transaction's inclusion; anything ambiguous means "not yet".

**Decided** (2026-11-24) but not yet confirmed as a public route:
- the claim goes live at once;
- the validator's on-chain security contact is emailed with an undo link;
- some owner tools are locked for 72 hours, and each lock is explained;
- a page can have up to 3 co-owners.

A **member's** self-send proves access only: groups, badges and quests. Trust weight, bonuses, downvotes and owner claims still need a signature.

**Solana:** route A is an owner-key `signMessage` ("Wallet Verified"); route B is an identity-key CLI signature ("Node Key Verified"). A supersedes B.

[DISCREPANCY] The maintainers' design document lags these rulings. Ask the owner before implementing anything here.

### Related operator features [VERIFIED]

- Delegator groups (operator opt-in, with a minimum delegation).
- The operator profile (`/me/operator-profile`).
- Validator messaging: a pre-claim queue plus operator DMs, behind the `bcc_validator_messaging_enabled` option (default off; see [`validator-messaging-rollout.md`](../validator-messaging-rollout.md)).
- Announcements (below).
- No vouch, back or report actions on **unclaimed** pages.

## Trust and reputation

Formulas live in code and docs; point to them, don't re-derive them.
- [`trust-attestation-layer.md`](../trust-attestation-layer.md) is the product constitution (locked).
- [`trust-attestation-risk-assessment.md`](../trust-attestation-risk-assessment.md) is the threat model.

**Trust score** [VERIFIED, `trust:app/Domain/Core/Services/TrustScoreService.php`]. This is the **single** entry point:
- `compute()` in PHP, `formulaSql()` in SQL. Never write a parallel path.
- The score is a clamp of neutral plus weighted votes, on-chain, contribution, penalty and attestation terms, kept between 0 and 100.
- **Tiers:** Risky / Caution / Neutral / Trusted / Elite. **Elite** needs an eligibility flag and a conduct floor, not just a score.
- **People are trust subjects through their member self-page.** The legacy per-user reputation table is retired [HISTORICAL].
- The **intent guard** (`trust:scripts/intent-guard.sh`) pins formula invariants.

**Votes.**
- Only Trusted and Elite members can downvote.
- Contribution alone cannot reach Trusted.
- Vote weight matures with account age.

**Attestations** [VERIFIED; contract §4.20]:

| Attestation | What it means | Limits |
|---|---|---|
| `vouch` | low-conviction "competent" | unlimited, throttled |
| `stand_behind` (UI label "Back") | a stronger statement | a slot cap scaled by tier |
| `dispute` | a challenge | tier-gated, evidence required |

- `endorse` is legacy wire naming for vouch [HISTORICAL table dropped].
- Caution and Risky members cannot attest.
- [DISCREPANCY] §4.20's "not yet shipped" banner is stale: `MeAttestationsEndpoint` and `EntityAttestationsEndpoint` exist.

**Anti-inflation** (constitution §J.4):
- no automatic positives;
- a cost to attest (fingerprint dedupe, throttle, tier gate);
- backing caps;
- reliability weighting and decay;
- an Elite aggregate weight cap;
- a diversity multiplier;
- meta-dispute as a malice backstop.

**Fraud signals.**
- Ring, sybil and shared-device signals are **log-only** today: the dampeners sit at multiplier 1.00 until real data exists to calibrate them (`ReciprocityPenaltyResolver`, `CohortOverlapDampener`; the proposals are in `docs/proposal-*.md`, [DISCREPANCY] which still call them "unmerged").
- `TrustGraph` runs ring detection daily.

**Reactions are not trust.** The content reactions `solid` and `fire` ("Stoke") are cosmetic. They feed only a capped contribution bonus. Trust is **not per-post**: per-post vouching was retired on purpose.

## Rank, findings and appeals [VERIFIED; contract §4.8]

**Ladder.**
- Three earned rungs: Apprentice, Journeyman, Veteran. "New Member" is a state, not a rung.
- Rank is stored in `bcc_trust_rank_state` and decided daily by `RankPromotionEngine`. It can fall, with no celebration on demotion.
- [HISTORICAL] Level-derived ranks (`RankService::LEVEL_TO_RANK`) were retired in v1.59. The glossary row is stale.

**Rank as a gate.** Rank gates capabilities. For example, creating a community needs Apprentice or above, Neutral tier or above, and not being in recovery, with caps on owned groups.

**Findings.**
- Recorded misconduct, appealable once (`POST /me/findings/:id/appeal`).
- Adjudicated in wp-admin.
- Someone else's finding id answers 404, so nothing leaks.
- A findings list that can't be loaded is reported as `findings_unavailable` and must never read as "clean" (contract v1.262).

## Disputes [VERIFIED; contract §4 disputes]

**Who disputes.** A page owner can dispute a single downvote (`POST /disputes`).

**How it's decided** (since v1.60):
- The decision is an **open community vote** on the poll engine (`bcc_trust_polls` and `bcc_trust_ballots`).
- Quorum is dual: a count of voters and an effective weight.
- Majority is weighted.
- There is a minimum open period, and the poll ends inconclusive after a long timeout.
- An hourly sweep closes polls.
- Voters must meet a rank and tier floor and must not be a party.
- [HISTORICAL] The five-member panel is retired. The glossary still says "panel of 5".

**Release practice.** Before any release that changes dispute code, run one end-to-end dispute on staging with test accounts: create it, resolve it, and check the outcome and notifications (see [DEPLOYMENT.md](DEPLOYMENT.md)).

## Social: feed, posts, reviews, DMs, notifications [VERIFIED]

**Feed** (`GET /feed`, `/feed/hot`, `/feed/tag`, group feeds):
- `FeedRankingService` is the single ranking brain. It ranks by recency and actor reliability, **never** by controversy.
- Members in the Caution and Risky tiers have reduced reach, and they are **told so** on their Standing tab. The owner may soften this rule; see [PROJECT.md](PROJECT.md).

**Posts.**
- Photo and GIF posts, "Stoke", helpful marks.
- **Reposts work like X:** one plain repost per root plus unlimited quote reposts, public posts only. If the root is invisible, a plain repost is hidden, and a quote shows "original unavailable".
- [PLANNED] The frontend for reposts (FE#326).

**Post analytics** are for every member, with no premium tier:
- they count unique viewers per day using a daily salt;
- they never read PeepSo's activity-views table;
- reach, engagement and trust are reported separately, never blended.

**Reviews and blogs.**
- Reviews: `/entities/:kind/:id/reviews`, plus owner replies. A review hidden by moderation stops counting while hidden.
- Blogs: member blogs, and group blogs for posting-capable roles.

**DMs** (contract §4.19): a thin adapter over PeepSo messages.
- Open to all signed-in members.
- A mutual block answers 404, so it never reveals the block.
- People you can't message stay in the picker, greyed out, with no reason given.
- Rate- and length-capped.

**Notifications.**
- One dispatcher handles the bell, web push, celebrations and the digest email.
- No cadence-pressure copy ([PROJECT.md](PROJECT.md)).
- `@mention` fans out through the same dispatcher.

**Watching.** `/me/watching*` is a rename of PeepSo follow. The old Pull/Binder vocabulary is removed [HISTORICAL].

## Communities, groups and Halls [VERIFIED; contract §4.7, §4.34]

**Group kinds** (`GroupContextResolver`): `nft` (holder), `validator` (delegator), `hall`, `system`, `user`. Never read `peepso-group` meta directly.

**Privacy and join rules:**

| Group type | Join rule |
|---|---|
| Open | join directly |
| Closed | request to join. The owner or a manager approves or denies; a denial starts a 7-day cooldown. Moderators don't decide membership |
| Secret | invite only. Non-members get 404 |
| Trust-gated (`trust_min` 25/50/75, fixed at creation) | fails closed (503) if the score can't be read |
| Holder | ownership checked on join |
| Delegator | delegation checked on join |

Re-joining:
- A banned member cannot rejoin.
- An explicit Join clears a *voluntary* leave, for every group type.
- A moderator removal still blocks.

**Halls** (see [PROJECT.md](PROJECT.md) "Halls thesis"):
- **What a Hall is:** an official, open group marked with `_bcc_group_kind = 'hall'`.
- **Two kinds** (`hall_kind`, v1.260):
  - **chain**: exactly one canonical Hall is created when each chain is successfully registered.
  - **topic**: e.g. DeFi. Managed by `/bcc/v1/admin/halls` (`AdminHallsEndpoint`, `manage_options`; contract v1.261).
- **Primary Hall:** a member may set one (`bcc_primary_hall_group_id`). Posts in it notify that Hall's members, up to a cap.
- **Provisioning:** chain registration creates its canonical Hall. Reads never provision Halls. The periodic scanner and provisioning cron are retired. Admins may create additional Halls, including topic Halls.
- **Onboarding:** shows only the BCC Hall. Entering it writes no membership; the explicit Join lives on the Hall page.
- **Frontend:** Halls live under `/communities` (`/halls` redirects). One card component shows per-kind stats; for a Hall these are Members · Posts 7d · Validators.

**Group stats.** `GroupActivityHeatService` provides `posts_last_7d`, `active_members_7d`, heat and member counts.

**Roster privacy.**
- Open-group rosters omit members the viewer may not see.
- `joined_at` is shown to admins only.
- Delegator and holder group memberships on a profile are shown only to signed-in viewers, because they disclose a financial position.

**Posting into groups.** Posts follow the group's rules. When a group becomes non-open, posts made while it was open drop out of public syndication.

## NFT discovery and holder groups

**What it is.** Members browse curated collections and pieces, pick showcase NFTs, and join holder-gated groups. **There is no price data**; see [PROJECT.md](PROJECT.md).

**Indexers** (`trust:app/Domain/Onchain/Fetchers/`) [VERIFIED]:

| Chain family | How it is indexed |
|---|---|
| EVM | confirmation-gated continuous indexing |
| Solana | continuous indexing, plus one shared Helius webhook with replay dedupe |
| Cosmos | read-time, with a 24 h cache, because Cosmos has no public event stream ("don't force symmetry") |

**Curation.**
- Collections are curated (`is_verified`). Automatic discovery is retired [HISTORICAL].
- Retired: Stargaze, Kujira, and CW-721 discovery ([`cosmwasm-discovery.md`](../cosmwasm-discovery.md)).
- Deferred: Polkadot NFTs.
- Two NFT paths stay separate on purpose: V1 wallet ownership and the V2 confirmation-gated indexer. They meet different trust requirements.

**Resilience.** Provider budgets and per-chain circuit breakers.

**Holder groups.**
- Config is stored in post meta.
- Admins approve the creation of closed holder groups.
- The opt-out rules are as for groups above.

**Market touchpoint.** The only one is a "Research on marketplace" link: exact canonical URL, domain allowlist, no BCC account data in the URL.

## Members, profiles and privacy [VERIFIED]

**Profile.** `GET /users/:handle` (`UserViewService`) returns the tier, verifications (counts and booleans only), primary Hall and completeness. It also returns `indexable`, the SEO flag below.

**Privacy controls.**
- `/me/privacy` boolean toggles, including `discovery_optout`, which aliases `hide_from_search`. [DISCREPANCY] The contract says 9 keys; the code has 8.
- Profile visibility uses PeepSo levels, shown as Public / Members / Only me. There is no friends feature: the plugin is not installed.
- Per-section "show on profile" switches; when `show_groups_on_profile` is off, others see only Halls.
- Hidden Halls.
- Blocks. Blocking removes follows both ways, and unblocking doesn't restore them.

**Suspended members.** Their profile answers with the generic `profile_private` 403, so nothing reveals the suspension.

**Dates.** **No public dates** for wallet verification, page claims, or group and team joins. A member's join date shows as the month only.

**Search.** Search matches display names and @handles only, never login names. A handle may not equal the login.

## Search [VERIFIED]

See [ARCHITECTURE.md](ARCHITECTURE.md) §3.10. The search document carries no wallet field.

## SEO [VERIFIED]

**Server:**
- `PublicProfileIndex` (`trust:app/Domain/Core/Services/`) is the single owner of "may this be listed publicly".
- It feeds both the sitemap endpoints (`/bcc/v1/sitemap/members|pages|groups`; keyset-paged, anonymous, viewer-independent; 503 on an untrusted read) and the profile's `indexable` flag.
- Excluded:
  - suspended, hidden or non-public profiles;
  - search opt-outs;
  - unlisted roles;
  - placeholder or email-shaped handles.

**Frontend:**
- `/sitemap.xml` is an index of per-section sitemaps.
- `robots.ts` blocks the private prefixes.
- Production is indexable only on the canonical host.
- Profiles use `indexable === true`, never `flags`.
- OG images are rendered with Satori.
- [HISTORICAL] The WordPress SEO plugin was removed, and core WordPress sitemaps are off.

## Admin and moderation [VERIFIED]

See [ARCHITECTURE.md](ARCHITECTURE.md) §3.9 for the two-surface rule.

**Moderation tools:**
- content reports (`/me/reports`, `/report-user`);
- auto-hide with a notice;
- "Undo last override";
- announcement takedowns;
- an admin digest.

**Moderation flags** are the member's and admins' to see ([DECISIONS.md](DECISIONS.md) S7).

**Admin navigation.** The frontend Admin group shows when `can_moderate` is true. That flag is display-only; the server enforces access.

**Secrets in admin.** Admin never shows raw secrets: only status, rotation date and a masked value (`ApiKeysPage`).

## Announcements [VERIFIED code; DISCREPANCY contract banner]

**What they are.** Operator-authored posts on a claimed validator page: a private `bcc_announcement` post type, one pin per page, comments, and admin takedown and restore (`trust:app/Domain/Core/Announcements/`).

[DISCREPANCY] Contract §4.32's "PLANNED, every route 404 until `bcc_announcements_enabled`" banner is stale relative to the code. Check the option's value in the target environment before claiming the feature is live there.

## Analytics [VERIFIED]

**PostHog in the frontend.**
- Opt-in per device. The SDK doesn't load until consent.
- No autocapture, recording or heatmaps.
- Sensitive routes are dropped, URL queries stripped, and person paths generalised.
- Identity is a server-keyed pseudonymous hash.
- The events are a **closed typed union** in `fe:src/lib/analytics/posthog.ts`. Adding an event is a privacy disclosure; get owner approval.
- PostHog is never a source of truth for product data.

**Vercel Analytics and Speed Insights** are also installed.

## Onboarding [VERIFIED, partially DISCREPANCY]

`/onboarding`, `GET /onboarding/suggestions` and a cold-start feed.

[DISCREPANCY] A 2026-09 decision removed the onboarding chain picker, but later work (2026-11) shipped chain-bias chips ("Also sets your home chain"). Ask the owner which is intended before changing onboarding.
