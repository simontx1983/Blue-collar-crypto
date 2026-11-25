# Decisions: what was decided, when, and why

This page is a decision log assembled on 2026-11-25. It draws on:
- repo docs and PRs;
- code docblocks;
- the project owner's rulings, which until now lived only in private agent memory and planning files.

**How to use it.**
- Before you change behaviour in one of these areas, read the row. If you think a decision should change, raise it with the project owner. **Do not reverse a decision on your own**, even if it looks like an improvement.
- "Owner ruling" means a decision the project owner made. The date is when it was recorded.
- To record a new decision, see "Adding a decision" at the bottom.

## Architecture

| # | Decision | Date | Why | Source |
|---|---|---|---|---|
| A1 | Headless: Next.js is the only renderer. No user-facing UI in PHP. Old FSE/Gutenberg block code is dead and gets deleted | 2026-05-15 | One renderer. The owner also noted that dead block code produced false matches in duplicate scans | owner ruling; backend rule §8 ([CONVENTIONS.md](CONVENTIONS.md)) |
| A2 | Split domains: the apex domain is the Vercel app; WordPress lives on the `cms.` subdomain; www redirects to the apex | 2026-08-19 | Headless split; the public site is not WordPress | [`domain-cutover-runbook.md`](../domain-cutover-runbook.md), [`hosting.md`](../hosting.md) |
| A3 | The three predecessor plugins merged into **one** `bcc-trust`, with bounded contexts | 2026-04 | They were already coupled through service calls, shared transactions and cron ordering; merging removed contract drift | `bcc-trust:MIGRATED-FROM.md` |
| A4 | Core never depends on trust. Cross-plugin access goes through allowlisted contracts, with fail-closed null objects | 2026-05 | No load-order fatals, and no hijacking of services | `bcc-core:src/ServiceLocator.php` |
| A5 | Contract first: `docs/api-contract-v1.md` is the API. A contract break is P0. Docs change in the same change set | 2026-05-13 | The frontend and other clients depend on it | [`api-contract-v1.md`](../api-contract-v1.md), contract-parity guard |
| A6 | Clients branch on error **codes**, never on message text | 2026-05-13 | Messages are copy and may change | contract §1 |
| A7 | **Admin has two surfaces:** wp-admin is the infrastructure cockpit, Next.js `/admin/*` is the daily operations centre. Never a third | 2026-05-27 (locked) | Configuration and repair vs daily workflow | [ARCHITECTURE.md](ARCHITECTURE.md) §3.9 |
| A8 | One capability gate (`manage_options`); no role-based gates in v1. Step-up re-authentication for sensitive staff actions is not a role gate | 2026-05-27 | Only a couple of real operators exist; design for current reality | owner ruling |
| A9 | PeepSo is tolerated and replaceable: it sits behind repositories, adapters and the core writers, and services never call PeepSo classes | 2026-07-31 | Keeps the cost of replacing it low | owner ruling; GOLDEN_PATHS law §II.6 |
| A10 | The PeepSo version is pinned, and core updates are blocked until add-ons match | 2026-11-11 | A core/add-on mismatch silently unregistered add-ons in production | owner ruling; `PeepSoVersionLock` |
| A11 | Fail closed on reads everywhere a decision depends on the answer | 2026-08 → 11 (doctrine) | `null` read as `[]` caused silent leaks and silent no-op workers | [DATABASE.md](DATABASE.md) |
| A12 | Throttle fails closed without a backend; the wp_options fallback was removed | 2026 | Missing infrastructure must not disable abuse protection; the fallback amplified writes | `bcc-core:src/Security/Throttle.php` |
| A13 | No edge caching of BCC REST or of any credentialed request | 2026-08-06 / 08-20 | Edge entries were not varied on Origin, so browsers got the wrong CORS responses for days | `bcc-trust:app/Infrastructure/EdgeCache.php` |
| A14 | The schema version is a normalised content hash, not a hand-bumped constant | 2026 (F-151a, private tracker) | A forgotten bump silently never installs; a hash can only cause an extra, idempotent pass | `bcc-trust:includes/database/compute-schema-version.php` |
| A15 | Health comes from one place: every degradation subsystem registers in the bcc-core map and is documented in pattern-registry | 2026-05-13 | One source of truth for alerting | `subsystem-count-guard.php` |
| A16 | Hosting: shared hosting first. The first scaling step is a persistent object cache (configuration, not code) | 2026-06-18 | Upgrade on a concrete load trigger | owner ruling; [`capacity-model.md`](../capacity-model.md) |
| A17 | Never sweep option prefixes; name exact keys | 2026-11-11 | A sweep's "orphaned prefix" premise was false after the plugin merge | `bcc-core:bcc-core.php` comment |
| A18 | Edge, schema and cron guards register at boot, not inside REST route registration | 2026 | Hooks added during REST registration never fire under cron or non-REST requests | `bcc-trust:bcc-trust.php` comments |

## Product

| # | Decision | Date | Why | Source |
|---|---|---|---|---|
| P1 | **Never about price**: no floor, volume, sales, listing or balance data is stored. The only exception is a marketplace research link (exact canonical URL, domain allowlist, no account data in the URL) | 2026-09-02 | Community, not market | owner ruling |
| P2 | The Trust Score is the one canonical number. Public views use prose, not numbers | 2026-07-08 | Avoids a scoreboard and confusion about which number matters | owner ruling |
| P3 | Trust is one global score, with Hall-scoped *context*, not a score per Hall | 2026-07-31 | Owner ruling (rationale not recorded beyond "simplicity") | owner ruling |
| P4 | No streaks and no cadence nudges | 2026-07-28 | Anti-viral constitution | [`cadence-pressure-policy.md`](../cadence-pressure-policy.md) |
| P5 | Execution mode: no structural redesign or new surfaces unless the owner reopens scope. Ship the smallest version | 2026-05-27 | Ship rather than re-debate | owner ruling |
| P6 | Unused code is deleted outright (no shims, no tombstones) together with its docs; dead planning docs move to `docs/archive/` | 2026-05 / 06 | It was a fresh install with no users | owner ruling. [UNKNOWN] The owner may revisit this now that there are live users; ask before deleting anything with live data behind it |
| P7 | The deferred "Phase D" cleanup (renames, REST namespace consolidation, table-prefix migration) is forbidden until the owner opens it | 2026-05-09 | High risk | owner ruling |
| P8 | Information architecture "option A": Home · Explore · Members · Communities · Messages · Watching. Disputes, Standing and Settings live inside the profile | 2026-11-24 | Outcome of the 2026-11 site-structure audit | owner ruling; `bcc-frontend:src/lib/route-moves.ts` |
| P9 | One look everywhere: one list template, one detail layout (compact identity header "B"), one card component with per-kind stats | 2026-11-24 | Outcome of the same audit | owner ruling; FE#430 |
| P10 | Vocabulary: "Operator" means only the runner of a claimed page; people are "members"; backing is "Vouches"; the top tier is "Elite" | 2026-11-23/24 | IA audit | owner ruling |
| P11 | Wire names are frozen when display labels change (e.g. `stand_behind`) | 2026-07-28 | Renames would reset stored preferences and break clients | contract v1.56 |
| P12 | "Local" was renamed "Hall". Halls are created by an admin, never automatically | 2026-07-24, amended 2026-11 | Union-hall metaphor; a public group should exist only when someone decided it should | glossary; contract v1.260/261 |
| P13 | Admins can create **topic** Halls; there is one mixed Halls list (All / Chain / Topic); a topic Hall can be a member's primary Hall | 2026-11-24 | Reverses a 2026-07-31 deferral | owner ruling; trust#898/#904 |
| P14 | Onboarding shows only the BCC Hall, and entering it writes no membership; the explicit Join is on the Hall page | 2026-09-03 | Consent; no auto-join | owner ruling. [DISCREPANCY] See the onboarding chain chips in [FEATURES.md](FEATURES.md) |
| P15 | Locked controls are explained (server `unlock_hint`), never silently hidden. Members are told when their standing limits their reach | 2026-11-24 | "No silent shadowbans" | owner ruling |

## Trust and reputation

| # | Decision | Date | Why | Source |
|---|---|---|---|---|
| T1 | Members are trust subjects through a member self-page ("Architecture A"); the legacy reputation table is retired | 2026-06-23/24 | Members without pages were stuck at Neutral | owner ruling |
| T2 | Attestations (vouch, stand_behind) move the score; content reactions are cosmetic and feed only a capped contribution bonus | 2026-07-08 | Keeps social signals out of trust | owner ruling |
| T3 | Trust is not per-post; per-post vouching was retired on purpose. Post analytics keep reach, engagement and trust as three separate systems, never blended | 2026-11-12 | Owner ruling. [UNKNOWN] No fuller rationale is recorded | owner ruling |
| T4 | Only Trusted and Elite members can downvote; contribution alone cannot reach Trusted | 2026-06-24 | Anti-brigading | owner ruling |
| T5 | Ring, sybil and shared-device signals are log-only, with dampeners at 1.00 | 2026-11-11 | There is no data to calibrate on yet (cold start) | owner ruling; `docs/proposal-*.md` |
| T6 | Rank is earned (Apprentice / Journeyman / Veteran), can fall, and has no celebration on demotion | v1.59 | Replaces the level-derived rank (rank redesign) | contract §4.8 |
| T7 | Disputes are decided by an open community vote on the poll engine (dual quorum, 60% weighted majority, a day-7 minimum, inconclusive at day 90), not a five-member panel | 2026-08-04 (v1.60) | Owner decision "D-7" in the rank redesign. [UNKNOWN] The changelog records the rules, not the reason | contract changelog v1.60 |
| T8 | A hidden review stops counting while hidden; review replies never count toward contribution | 2026-11-11/12 | Owner ruling (rationale not recorded) | owner ruling |
| T9 | Do not tune trust and rank constants until real distributions exist | 2026-07-31 | Cold start | owner ruling |

## Privacy and safety

| # | Decision | Date | Why | Source |
|---|---|---|---|---|
| S1 | The wallet privacy policy is **binding**: no other user sees an address (in any form), a balance, holdings or history | 2026-07-23 | Prevents correlating wallets with people | [`wallet-privacy-policy.md`](../wallet-privacy-policy.md) |
| S2 | No public dates for wallet verification, claims, or group or team joins; join date shows as month only; the public label comes from `kind`, never `method` | 2026-11-24 | Timing is profiling data | owner ruling |
| S3 | Profile privacy uses PeepSo's levels (Public / Members / Only me). Admins are exempt (except level-30 albums); stray values round to the stricter level; there is no friends feature | 2026-11-10/11 | Reuse the existing gate | owner ruling |
| S4 | A suspended profile answers with the generic `profile_private` 403. Suspended members may sign in read-only and may change only their password | 2026-11-11/12 | No disclosure of suspension | owner ruling |
| S5 | Member search matches display names and handles only, never login names; a handle may not equal the login; there is one server-side `hide_from_search` | 2026-11-11/12 | Login names never leak | owner ruling |
| S6 | Blocking removes follows both ways, and unblocking doesn't restore them; invites and requests between a blocked pair are silently refused | 2026-11-11/12 | Owner ruling (rationale not recorded beyond "safety") | owner ruling |
| S7 | Moderation flags are the member's and admins' to see, and are not part of the public profile | 2026-11-24 | Owner ruling (rationale not recorded publicly) | owner ruling |
| S8 | Analytics: one wrapper module, consent before the SDK starts, no autocapture or recording, query strings stripped, country-level geo only; PostHog is not a source of truth | 2026-11-11 | Privacy | `bcc-frontend:src/lib/analytics/` |
| S9 | Login hardening: a captcha step-up (Turnstile) after repeated failures, breached passwords refused, an 8-character minimum | 2026-11-11 → 17 | Owner ruling | owner ruling |
| S10 | DMs are open to all signed-in members (blocks still apply); people you can't message stay in the picker, greyed out, with no reason given | 2026-11-11/12 | The friends feature was removed | owner ruling |
| S11 | Raw secrets are never viewable or editable in admin; admin shows status, rotation date and a masked value | 2026-05-27 | Limits the damage from a compromised admin session | `bcc-core` `ApiKeysPage` |
| S12 | SEO: member profiles are indexable; the sitemap covers everything public and excludes hidden, private, suspended and placeholder or email-shaped handles; eligibility is a fail-closed backend flag; the WordPress SEO plugin was removed | 2026-11-20 | Discoverability without leaks | trust#838, FE#402, FE#434 |

## Wallets, validators and NFTs

| # | Decision | Date | Why | Source |
|---|---|---|---|---|
| W1 | One implementation per crypto primitive (secp256k1, bech32, Cosmos address derivation) in bcc-core | 2026-11 | One parser means one definition of what an address is | pattern-registry "Wallets" |
| W2 | Claims re-verify weekly, and a badge goes when ownership goes; an UNKNOWN answer never revokes; a renounced creator (zero address) keeps the badge | 2026-11-11/12 | Accuracy without false revocations | `ClaimReverifySweepService` |
| W3 | The self-send memo claim: the memo carries a code, never a handle; the claim is live at once; the on-chain security contact is emailed with an undo link; 72-hour explained locks; up to 3 co-owners | 2026-11-24 | Lets validators claim without a browser wallet, while protecting them | owner ruling. [PLANNED] The design doc lags these rulings |
| W4 | Solana validator claims: route A (owner-key signature, "Wallet Verified") supersedes route B (identity-key CLI signature, "Node Key Verified"); staff approval is the fallback | 2026-11-23 | Some validators can't sign in a browser | owner ruling |
| W5 | A **member's** self-send proves access only; trust weight, bonuses, downvotes and owner claims need a signature | 2026-11-24 | A self-send is weaker proof | owner ruling |
| W6 | Delegator groups are opt-in by the operator, who sets the minimum delegation; no vouch, back or report on unclaimed pages | 2026-11-05/11 | Consent; nothing to attest to yet | owner ruling |
| W7 | Automatic NFT discovery is stopped; collections are curated. Stargaze and Kujira are retired, Polkadot NFTs deferred | 2026-07 → 11 | Dead chains; no usable indexer; avoid unreviewed public content | [`cosmwasm-discovery.md`](../cosmwasm-discovery.md) |
| W8 | The V1 (wallet ownership) and V2 (confirmation-gated indexer) NFT paths stay separate | 2026-05-13 | Different trust requirements | pattern-registry |
| W9 | Cosmos NFT data is read at request time with a cache, not continuously indexed | 2026 | There is no public event stream; "don't force symmetry" | glossary §7 |

## Process

| # | Decision | Date | Why | Source |
|---|---|---|---|---|
| R1 | Releases are batched, with no version bump per feature; the `Version:` header and the `BCC_*_VERSION` constant change together | 2026-05-27 | Each bump prompted every consumer site | [`deploy-runbook.md`](../deploy-runbook.md) |
| R2 | Deploys are driven by commits: staging auto-deploys on green main; production deploys only by manual dispatch asserting `expected_sha`; Git Updater is retired | 2026-07-15 | Unbumped merges were invisible to Git Updater | [`deploy-runbook.md`](../deploy-runbook.md) |
| R3 | Every production step needs the project owner's explicit approval, and only the designated ops role executes it | 2026-11-18 | Owner ruling | owner ruling; procedure in [HANDOFF.md](HANDOFF.md) |
| R4 | A merge needs a reviewer's approval **and** the coordinator's agreement; frontend PRs need two approvals and must be safe against the backend **currently in production** | 2026-11-19 | Green CI alone isn't enough; a frontend merge goes live immediately | [CONVENTIONS.md](CONVENTIONS.md) |
| R5 | All five repos are public. Security-fix text describes the fix, not the attack, until the fix is in production | 2026-11-19/20 | A public PR is a disclosure channel | [SECURITY.md](SECURITY.md) |
| R6 | Plugin-level `CLAUDE.md` files are local-only (gitignored) in bcc-trust; `bcc-frontend/CLAUDE.md` and the umbrella root `CLAUDE.md` are tracked | 2026-07-08 (agreement), landed 2026-07-15 | The engineers kept divergent local agent instructions (owner-confirmed) | `bcc-trust:.gitignore`. [DISCREPANCY] Older notes say bcc-frontend's is local-only too, but it is tracked |
| R7 | Local test scope: run the tests touching the change plus static checks; full suites run in CI; one heavy run at a time | 2026-11-24 | A shared development PC was overloaded by parallel agents | [CONVENTIONS.md](CONVENTIONS.md) "Testing" |

## Superseded: do not re-introduce

| Was | Now | Since |
|---|---|---|
| Five-member dispute panel (`BCC_DISPUTES_PANEL_SIZE`) | community poll vote | v1.60 |
| Rarity tiers (legendary…) | Risky / Caution / Neutral / Trusted / Elite | v1.57 |
| Level-derived rank (`RankService::LEVEL_TO_RANK`) | earned rank in `bcc_trust_rank_state` | v1.59 |
| Automatic Hall provisioning cron | admin-created chain and topic Halls | 2026-11 |
| "Locals" | Halls | 2026-07-24 |
| Automatic NFT discovery, scanner, enrichment tick | curated collections | 2026-08 → 11 |
| `bcc_trust_endorsements` table, `endorsement_bonus` | attestations (vouch) | 2026-07 |
| Signature in the page-claim request | a claim uses an already verified linked wallet | contract v1.258 |
| a second Bech32 implementation in bcc-trust | core's `BCC\Core\Crypto\Bech32` only | 2026-11 |
| Git Updater deploys | commit-driven deploy workflow | 2026-07-15 |
| Edge-cached REST | REST and credentialed requests excluded at the edge | 2026-08-06 |
| PeepSo friends | removed; DMs open to members | 2026-11-11 |
| WordPress SEO plugin, core WP sitemaps | app sitemap from `PublicProfileIndex` | 2026-11-20 |
| Per-post vouching | attestations on people and entities only | 2026-11-12 |
| Email/password NextAuth Credentials provider | browser posts to `/auth/login`, then `signIn("bcc-verified")` | F-132 (private tracker) |
| Raw-byte md5 schema stamp | normalised token hash | F-151a (private tracker) |

## Adding a decision

When the project owner rules on something, the agent that received the ruling does this:
1. Adds a row here (number, decision, date, why, source PR or doc) in the same PR that implements it, or in a docs PR straight away.
2. If the ruling reverses an older row, moves that row to "Superseded" and says so in the PR description.
3. Updates the canonical doc too, if one exists: the contract, a policy doc or pattern-registry.

Decisions made in chat or in private memory and recorded nowhere else are invisible to other agents.
