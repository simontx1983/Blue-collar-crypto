# Trust Engine — Frontend Coverage

**Last audited: 2026-06-12** (auth wave — see *Recent changes* below)

## Why this doc exists

Backend leads, frontend trails. The bcc-trust plugin exposes a much fuller engine
than the current Next.js frontend reaches. Whenever a marketing claim, copy
change, or design promises a verb, the question is always: *do we actually
expose that to users today?*

This doc is the single source of truth for that question. Read it **before**:

- Promising a verb in landing copy (e.g. "Vote, endorse, or dispute")
- Sketching a button or affordance into a Figma frame
- Telling a partner "we already do X"
- Cutting V2 scope (so you know which gaps are deliberate vs accidental)

It's a snapshot, not a contract. Re-audit after each milestone.

## Recent changes (auth wave, 2026-06-12)

The 2026-05/06 auth work landed a full second generation of auth endpoints,
all frontend-covered (grep-verified 2026-06-12):

- **Wallet-native auth** — `wallet-nonce` / `wallet-login` / `wallet-signup`
  via `WalletAuthButton` on the login + signup pages; login flows through the
  NextAuth wallet Credentials provider (the typed `walletLogin()` client
  export is dead code — flagged in the table).
- **Wallet-only lockout recovery** (§4.24-adjacent, contract `/me/account/
  recovery-email[/verify]`) — `WalletsSection` banner for accounts with
  `has_recovery_email === false`.
- **Password recovery + email verification** — forgot/reset-password and
  verify-email/resend-verification pages under `(auth)/`.
- **2FA + SSO completion** — `2fa/verify|resend` (two-factor page),
  `oauth-complete` (complete-profile page), `auth/refresh` (Phase β silent
  refresh inside `bccFetch`, invisible to callers).
- **Phase γ error contract** — the frontend branches on `err.code` only
  (`lib/api/errors.ts` `humanizeCode()`, never `err.message`), enforced by
  `bcc-frontend/scripts/error-contract-guard.sh`.

## Recent changes (V1.5 wave, 2026-04-30)

The 2026-04-24 audit declared the dispute domain "the single largest unsurfaced
domain" and listed X/GitHub OAuth, endorsements, the wallets list, and the
device fingerprint as ❌. That snapshot is now stale — the V1.5 polish wave
landed all of them. Specifically:

- **X / GitHub OAuth** — both flows wired end-to-end, with backend support
  for headless return-URL handoff (`?return_to=` query param validated against
  `BCC_FRONTEND_ORIGIN` + persisted in user meta) and the `wp_verify_nonce`
  check dropped from disconnect routes (incompatible with bearer JWT auth).
- **Endorse / revoke** — `EndorseButton` mounted on every entity profile.
  CardViewService precomputes `permissions.can_endorse` + `viewer_has_endorsed`
  via a new `EndorsementService::getEndorseEligibility` resolver.
- **Wallets list + unlink** — `/settings/identity` now lists every linked
  wallet with primary/verified chips + per-row unlink (DELETE `/wallets/:id`).
- **Device fingerprint** — `<FingerprintReporter />` mounted in `Providers`,
  fires once per session per user via idle-deferred Web Crypto SHA-256 hash.
- **Dispute system** — turns out the entire flow (file dispute, juror panel,
  juror vote, participation strip) was already wired; the audit had the
  namespace wrong (`bcc-trust/v1` → it's actually under `bcc/v1`). Only gap:
  no nav entry to `/panel`, fixed by adding "PANEL DUTY" to ViewerMenu.

Down to V2-scoped gaps now (leaderboards, on-chain detail, NFT showcase,
admin stats UI). The "single largest unsurfaced domain" framing no longer
applies.

## Legend

| Mark | Meaning |
|---|---|
| ✅ | Wired. Typed client wrapper exists and at least one UI surface uses it. |
| 🟡 | Read-only. Frontend reads the data but provides no UI to mutate. |
| 🚧 | Partial. Wrapper exists but a sibling route is missing, OR the verb is wired internally but has no user-facing button (e.g. vote-via-review). |
| ❌ | Missing. Backend exposes the route, frontend has no client at all. |

## Source of truth (where the answers come from)

- **Backend route registrations**: `wp-content/plugins/bcc-trust/app/Domain/**/Controllers/*.php` and `app/Domain/**/REST/*.php` — grep for `register_rest_route(`.
- **Frontend wrappers**: [bcc-frontend/src/lib/api/*-endpoints.ts](../bcc-frontend/src/lib/api/).
- **API contract**: [docs/api-contract-v1.md](api-contract-v1.md) — canonical request/response shapes + what each verb *means*.
- **Database schema**: [docs/database-schema.md](database-schema.md) — table-by-table reference for trust + dispute + onchain tables.
- **Glossary**: [docs/glossary.md](glossary.md) — UI / product vocabulary (Pull, Floor, Solid/Vouch/Stand-behind, etc.).

## Namespace conventions

- `bcc/v1/*` — public-facing reads + most mutations (votes, disputes,
  endorsements, claims). The headless Next.js frontend mostly hits this
  namespace.
- `bcc-trust/v1/*` — historically: trust-engine-internal mutations + admin/fraud
  reads + OAuth flows. Originally built for the legacy WP-side trust-frontend.js
  admin JS. Some of these have been re-exposed under `bcc/v1/*` for the
  headless frontend (e.g. dispute routes); others remain at `bcc-trust/v1/*`
  (X/GitHub OAuth, fraud admin stats).

---

## Auth & Identity

| Route | Method | Frontend wrapper | Status | Notes |
|---|---|---|---|---|
| `/bcc/v1/auth/signup` | POST | `auth-endpoints.signup` | ✅ | |
| `/bcc/v1/auth/login` | POST | `auth-endpoints` | ✅ | |
| `/bcc/v1/auth/token` | POST | `auth-endpoints` | ✅ | NextAuth JWT exchange |
| `/bcc/v1/auth/nonce` | POST | `auth-endpoints.getWalletNonce` | ✅ | |
| `/bcc/v1/auth/wallet-link` | POST | `auth-endpoints.linkWallet` | ✅ | |
| `/bcc/v1/auth/wallet-nonce` | GET | `auth-endpoints.ts:310` | ✅ | `WalletAuthButton` (login + signup pages) |
| `/bcc/v1/auth/wallet-login` | POST | NextAuth wallet Credentials provider (`lib/auth.ts:309`) | ✅ | The typed `walletLogin()` export at `auth-endpoints.ts:336` is **dead code** (zero callers) — live path goes through NextAuth |
| `/bcc/v1/auth/wallet-signup` | POST | `auth-endpoints.ts:362` | ✅ | `WalletAuthButton` connect → sign → account |
| `/bcc/v1/auth/forgot-password` | POST | `auth-endpoints.ts:391` | ✅ | `(auth)/forgot-password` |
| `/bcc/v1/auth/reset-password` | POST | `auth-endpoints.ts:408` | ✅ | `(auth)/reset-password` |
| `/bcc/v1/auth/2fa/verify` | POST | `auth-endpoints.ts:79` | ✅ | `(auth)/login/two-factor` |
| `/bcc/v1/auth/2fa/resend` | POST | `auth-endpoints.ts:95` | ✅ | `(auth)/login/two-factor` |
| `/bcc/v1/auth/oauth-complete` | POST | `auth-endpoints.ts:144` | ✅ | `(auth)/signup/complete-profile` (SSO handle pick) |
| `/bcc/v1/auth/refresh` | POST | `lib/api/client.ts:307` | ✅ | Phase β silent refresh inside `bccFetch` — transparent, pre-request |
| `/bcc/v1/auth/verify-email` | POST | `auth-endpoints.ts:208` | ✅ | `(auth)/verify-email` |
| `/bcc/v1/auth/resend-verification` | POST | `auth-endpoints.ts:226` | ✅ | `(auth)/verify-email` |
| `/bcc/v1/me/account/recovery-email` | POST | `account-endpoints.ts:104` → `useRecoveryEmail` | ✅ | `settings/WalletsSection` banner, gated on `has_recovery_email === false` (wallet-only lockout recovery) |
| `/bcc/v1/me/account/recovery-email/verify` | POST | `account-endpoints.ts:123` → `useRecoveryEmail` | ✅ | Same banner, code-entry step |
| `/bcc-trust/v1/x/auth` | GET | `oauth-endpoints.getXAuthUrl` | ✅ | Accepts `?return_to=` validated against `BCC_FRONTEND_ORIGIN`, persisted in user meta for the callback |
| `/bcc-trust/v1/x/callback` | GET | (browser-redirect target) | ✅ | Reads return URL from user meta; falls back to `defaultReturn('/settings/identity')` |
| `/bcc-trust/v1/x/status` | GET | `oauth-endpoints.getXStatus` | ✅ | |
| `/bcc-trust/v1/x/disconnect` | POST | `oauth-endpoints.disconnectX` | ✅ | Bearer JWT only — `wp_verify_nonce` check removed for headless compatibility |
| `/bcc-trust/v1/x/verify-share` | POST | `oauth-endpoints.verifyXShare` | ✅ | Wrapper exists; companion UI for the share-X quest is V2 |
| `/bcc-trust/v1/github/auth` | GET | `oauth-endpoints.getGitHubAuthUrl` | ✅ | Same return-URL contract as X |
| `/bcc-trust/v1/github/callback` | GET | (browser-redirect target) | ✅ | |
| `/bcc-trust/v1/github/status` | GET | `oauth-endpoints.getGitHubStatus` | ✅ | |
| `/bcc-trust/v1/github/disconnect` | POST | `oauth-endpoints.disconnectGitHub` | ✅ | |
| `/bcc-trust/v1/github/refresh` | POST | `oauth-endpoints.refreshGitHub` | ✅ | Wrapper exists; refresh button on settings is V2 polish |
| `/bcc-trust/v1/device-fingerprint` | POST | `fingerprint-endpoints.postFingerprint` | ✅ | Fired once per session by `<FingerprintReporter />` (idle-deferred, fail-silent) |
| `/bcc-trust/v1/user/status` | GET | — | (redundant) | Suspension/verification flags are already in the `/users/:handle` view-model — this is a legacy WP-admin endpoint, no frontend gap |

**Impact**: "Verified Human" copy on the homepage is now backed end-to-end —
wallet sig + X handle + GitHub identity all reachable from `/settings/identity`.

---

## Voting (page up/down)

| Route | Method | Frontend wrapper | Status | Notes |
|---|---|---|---|---|
| `/bcc-trust/v1/vote` | POST | wrapped only inside `posts-endpoints.createReview` | 🚧 | **No slim button-vote UI.** Voting today requires writing a review. |
| `/bcc-trust/v1/remove-vote` | POST | wrapped via `removeReview` (DELETE `/me/reviews/:page_id`) | 🚧 | Self-removal only |
| `/bcc-trust/v1/report-vote` | POST | — | ❌ | V2 — flag a specific vote (separate from `/me/reports` content reports) |
| `/bcc-trust/v1/user/{id}/pages/scores` | GET | — | ❌ | V2 — per-user score breakdown across all pages they've voted on |

**Impact**: Trust Engine copy says **"Vote, endorse, or dispute"**. The "vote"
verb is fused to the review flow. The deliberate V1.5 decision was to leave
this as-is — §O5 progressive disclosure gates voting behind reviews on purpose
(slim thumb-up/thumb-down would short-circuit the progression). Either soften
homepage copy ("review, react, or dispute") or accept the framing.

---

## Endorsements

| Route | Method | Frontend wrapper | Status | Notes |
|---|---|---|---|---|
| `/bcc-trust/v1/endorse` | POST | `endorse-endpoints.endorsePage` | ✅ | EndorseButton on every entity profile |
| `/bcc-trust/v1/revoke-endorsement` | POST | `endorse-endpoints.revokeEndorsement` | ✅ | Confirm-and-revoke flow |
| `/bcc/v1/endorsements/mine` | GET | — | ❌ | V2 — own-endorsements list (data exists in user view-model already) |

**Impact**: Endorsement give/revoke is live, gated server-side via
`EndorsementService::getEndorseEligibility` (auth + self-block + identity-quest
+ account-age + fraud-score). Card view-models now expose `viewer_has_endorsed`
+ `endorse_unlock_hint` so the EndorseButton renders the disabled-with-tooltip
state cleanly.

---

## Disputes

Backend lives at `bcc/v1/disputes/*` (the 2026-04-24 audit listed these under
`bcc-trust/v1` — that namespace is wrong; the routes were re-exposed under
`bcc/v1` for the headless frontend).

| Route | Method | Frontend wrapper | Status | Notes |
|---|---|---|---|---|
| `/bcc/v1/disputes` | POST | `disputes-endpoints.openDispute` | ✅ | OpenDisputeModal, owner-only via `permissions.can_dispute` |
| `/bcc/v1/disputes/votes/{page_id}` | GET | `disputes-endpoints.getDisputableVotes` | ✅ | Picker for OpenDisputeModal |
| `/bcc/v1/disputes/mine` | GET | proxied via `getUserDisputes` (`/users/:handle/disputes`) | 🟡 | Read-only display in profile tab; the file-action lives on EntityProfile, so no separate "my disputes" management surface needed |
| `/bcc/v1/disputes/panel` | GET | `disputes-endpoints.getPanelQueue` | ✅ | `/panel` route + ViewerMenu link |
| `/bcc/v1/disputes/{id}/vote` | POST | `disputes-endpoints.castPanelVote` | ✅ | PanelVoteModal |
| `/bcc/v1/disputes/{id}/resolve` | POST | — | ❌ | V2 — admin force-resolve, lives in wp-admin |
| `/bcc/v1/disputes/health` | GET | — | ❌ | V2 — internal health check |
| `/bcc/v1/disputes/participation/me` | GET | `disputes-endpoints.getMyParticipation` | ✅ | Drives the ParticipationStrip on `/panel` |
| `/bcc-trust/v1/report-user` | POST | — | ❌ | V2 — overlaps with `/me/reports` (content) + block surface |

**Impact**: The dispute domain is fully surfaced end-to-end. Page owners can
file disputes against downvotes, eligible jurors get a queue at `/panel`,
votes hide tallies during deliberation, and the participation strip shows
trust earned today/lifetime against caps.

---

## Reviews & Posts

| Route | Method | Frontend wrapper | Status | Notes |
|---|---|---|---|---|
| `/bcc/v1/posts` (kind=review) | POST | `posts-endpoints.createReview` | ✅ | |
| `/bcc/v1/posts` (kind=status) | POST | `posts-endpoints.createPost` | ✅ | |
| `/bcc/v1/posts` (kind=blog) | POST | `posts-endpoints.createBlog` | ✅ | |
| `/bcc/v1/me/reviews/:page_id` | DELETE | `removeReview` | ✅ | |
| `/bcc/v1/me/reports` | POST | `reports-endpoints.createContentReport` | ✅ | |
| `/bcc/v1/flag` | POST | — | ❌ | Live route — page-level signal flagging (distinct from content reports + vote reports). V2 — overlaps with the §K1 reports surface in user-facing intent |

---

## Cards & Browse

| Route | Method | Frontend wrapper | Status | Notes |
|---|---|---|---|---|
| `/bcc/v1/cards` | GET | `cards-list-endpoints.getCardsList` | ✅ | |
| `/bcc/v1/cards/:type/:id` | GET | `card-endpoints.getCardEntity` | ✅ | View-model now includes `viewer_has_endorsed` + `endorse_unlock_hint` + `permissions.can_endorse` |
| `/bcc/v1/cards/search` | GET | `cards-search-endpoints.getSearchSuggestions` | ✅ | |
| `/bcc/v1/discover` | GET | — | ⛔ retired 2026-05-15 | Legacy back-compat endpoint for a consumer that no longer exists. `PageDiscoveryService` lives on under `/cards/list`. |

**Impact**: leaderboard endpoints retired; `/cards/list` is the canonical browse surface for trust-ranked entities.

---

## Profile

| Route | Method | Frontend wrapper | Status |
|---|---|---|---|
| `/bcc/v1/users/:handle` | GET | `user-endpoints.getUser` | ✅ |
| `/bcc/v1/users/:handle/reviews` | GET | `user-activity-endpoints.getUserReviews` | ✅ |
| `/bcc/v1/users/:handle/disputes` | GET | `user-activity-endpoints.getUserDisputes` | ✅ |
| `/bcc/v1/me/privacy` | GET, PATCH | `privacy-endpoints` | ✅ |
| `/bcc/v1/me/blocks` | GET, POST, DELETE | `blocks-endpoints` | ✅ |
| `/bcc/v1/pages/:id/claim` | POST | `pages-endpoints.claimPage` | ✅ |

---

## Watching & Cards-In-Hand

Renamed from "Binder & Cards-In-Hand" 2026-05-13 per the §1.1.1 additive-deprecation runway (see `api-contract-v1.md §4.5.1`). The legacy `/me/binder/*` routes remain alive for one release with `Deprecation`/`Sunset` headers; the frontend talks to `/me/watching/*` exclusively.

| Route | Method | Frontend wrapper | Status |
|---|---|---|---|
| `/bcc/v1/me/watching` | GET | `watching-endpoints.getWatching` | ✅ |
| `/bcc/v1/me/watching/summary` | GET | `getWatchingSummary` | ✅ |
| `/bcc/v1/me/watching/watch` | POST | `watchCard` | ✅ |
| `/bcc/v1/me/watching/:follow_id` | DELETE | `unwatchCard` | ✅ |
| `/bcc/v1/me/binder` (deprecated, removed in release N+1) | GET | — (legacy alias, no FE wrapper) | ⚠️ |
| `/bcc/v1/me/binder/summary` (deprecated) | GET | — | ⚠️ |
| `/bcc/v1/me/binder/pull` (deprecated) | POST | — | ⚠️ |
| `/bcc/v1/me/binder/:follow_id` (deprecated) | DELETE | — | ⚠️ |

---

## Feed & Reactions

| Route | Method | Frontend wrapper | Status |
|---|---|---|---|
| `/bcc/v1/feed/hot` | GET | `feed-endpoints.getHotFeed` | ✅ |
| `/bcc/v1/feed` | GET | `feed-endpoints.getFeed` | ✅ |
| Feed-item reaction | POST/DELETE | `reaction-endpoints.setReaction`, `removeReaction` | ✅ |
| Per-user blog tab | GET | `blog-endpoints.getUserBlog` | ✅ |

---

## Locals (geo communities)

| Route | Method | Frontend wrapper | Status |
|---|---|---|---|
| `/bcc/v1/locals` (list) | GET | `locals-endpoints.getLocals` | ✅ |
| `/bcc/v1/locals/:slug` | GET | `getLocal` | ✅ |
| Set/clear primary local | POST/DELETE | `setPrimaryLocal`, `clearPrimaryLocal` | ✅ |
| Join/leave local | POST/DELETE | `joinLocal`, `leaveLocal` | ✅ |

---

## Notifications, Highlights, Celebrations

| Route | Method | Frontend wrapper | Status |
|---|---|---|---|
| `/bcc/v1/me/notifications` | GET | `notifications-endpoints.getNotifications` | ✅ |
| `/bcc/v1/me/notifications/unread-count` | GET | `getUnreadCount` | ✅ |
| `/bcc/v1/me/notifications/read` | POST | `markNotificationsRead` | ✅ |
| `/bcc/v1/me/highlights` | GET | `highlights-endpoints.getHighlights` | ✅ |
| `/bcc/v1/me/highlights/:id/dismiss` | POST | `dismissHighlight` | ✅ |
| `/bcc/v1/me/celebrations/pending` | GET | `celebrations-endpoints.getPendingCelebration` | ✅ |
| `/bcc/v1/me/celebrations/consume` | POST | `consumeCelebration` | ✅ |

---

## Onboarding

| Route | Method | Frontend wrapper | Status |
|---|---|---|---|
| `/bcc/v1/me/onboarding/*` (suggestions, status) | GET | `onboarding-endpoints.getOnboardingSuggestions`, `getOnboardingStatusServerSide` | ✅ |
| `/bcc/v1/me/onboarding/complete` | POST | `completeOnboarding` | ✅ |
| `/bcc/v1/me/handle` | POST | `updateHandle` | ✅ |

---

## Creator Gallery

| Route | Method | Frontend wrapper | Status |
|---|---|---|---|
| `/bcc/v1/creators/:slug/gallery` | GET | `creator-gallery-endpoints.getCreatorGallery` | ✅ |

---

## On-chain Signals

| Route | Method | Frontend wrapper | Status | Notes |
|---|---|---|---|---|
| `/bcc/v1/onchain/:page_id` | GET | — | ❌ | V2 — page-level on-chain detail (validator uptime, project tx counts). Validator profiles render basic stats from the unified `/users/:handle` view-model today; the rich detail panel is a V2 surface |
| `/bcc/v1/onchain/:page_id/refresh` | POST | — | ❌ | V2 |
| `/bcc/v1/wallets` | GET | `wallets-endpoints.getMyWallets` | ✅ | Listed at `/settings/identity` |
| `/bcc/v1/wallets/:id` | DELETE | `wallets-endpoints.unlinkWallet` | ✅ | Confirm-and-unlink flow |
| `/bcc/v1/wallets/project/:post_id` | GET | — | ❌ | V2 — project wallet listings (admin/owner debug) |
| `/bcc/v1/chains` | GET | — | ❌ | V2 — supported chain whitelist (used internally; no UI need today) |

**Impact**: §B2 multi-wallet linking is now end-to-end visible. The rich
on-chain detail surface remains a V2 differentiator — what makes BCC stand
out from a generic review site, but not what blocks a closed beta.

---

## NFT Showcase

| Route | Method | Frontend wrapper | Status |
|---|---|---|---|
| `/bcc/v1/nft/collections` | GET | — | ❌ |
| `/bcc/v1/nft-selections` | GET | — | ❌ |
| `/bcc/v1/nft-selections` | POST | — | ❌ |
| `/bcc/v1/nft-selections` | DELETE | — | ❌ |
| `/bcc/v1/nft-selections/picker` | GET | — | ❌ |
| `/bcc/v1/nft-selections/refresh` | POST | — | ❌ |
| `/bcc/v1/nft-selections/reorder` | POST | — | ❌ |

**Impact**: Entire profile-level NFT showcase module is V2. The plan §H1
deferred this explicitly — Phase 6 ships the *creator gallery* (✅ wired),
not the per-profile NFT picker.

---

## Admin (fraud / stats / reports)

| Route | Method | Frontend wrapper | Status |
|---|---|---|---|
| `/bcc/v1/admin/reports` | GET | `admin-reports-endpoints.getAdminReports` | ✅ |
| `/bcc/v1/admin/reports/:id/resolve` | POST | `resolveAdminReport` | ✅ |
| `/bcc-trust/v1/fraud/stats` | GET | — | ❌ | V2 — admin dashboard lives in wp-admin |
| `/bcc-trust/v1/users/high-risk` | GET | — | ❌ | V2 — wp-admin |
| `/bcc-trust/v1/activity/fraud` | GET | — | ❌ | V2 — wp-admin |
| `/bcc-trust/v1/stats/trust-trend` | GET | — | ❌ | V2 — wp-admin |
| `/bcc-trust/v1/stats/risk-distribution` | GET | — | ❌ | V2 — wp-admin |
| `/bcc-trust/v1/stats/fraud-trend` | GET | — | ❌ | V2 — wp-admin |
| `/bcc-trust/v1/stats/devices` | GET | — | ❌ | V2 — wp-admin |
| `/bcc-trust/v1/analyze-user/:id` | POST | — | ❌ | V2 — wp-admin |

These are deliberate V1 cuts — the admin surface lives in wp-admin for now.
Listed here for completeness so future-you knows nothing is forgotten.

---

## Coverage summary

| Domain | Wired | Missing |
|---|---|---|
| Auth (core) | ✅ | — |
| Auth (X / GitHub / fingerprint) | ✅ all | — |
| Voting | 🚧 review-only | slim vote, vote reporting, score breakdown — V2 / copy-fix |
| Endorsements | ✅ give + revoke | leaderboards, own-list — V2 |
| Disputes | ✅ open + panel + vote + participation | resolve (admin-only), report-user, health — V2 |
| Reviews & posts | ✅ full | `/flag` overlap with reports — V2 cleanup |
| Cards & browse | ✅ core | `/discover`, leaderboards — V2 |
| Profile | ✅ full | — |
| Watching (formerly Binder, renamed 2026-05-13) | ✅ full | — |
| Feed & reactions | ✅ full | — |
| Locals | ✅ full | — |
| Notifications / highlights / celebrations | ✅ full | — |
| Onboarding | ✅ full | — |
| Creator gallery | ✅ full | — |
| Wallets | ✅ list + unlink | project/chains lookups — V2 |
| On-chain signals (page-level) | — | ❌ all — V2 |
| NFT showcase | — | ❌ all — V2 |
| Admin | ✅ reports only | rest deliberately wp-admin-only |

Every remaining ❌ is V2-scoped. There are no accidental gaps left from V1 /
V1.5 work.

---

## How to use this doc

1. **Before claiming an action in copy**: find the route → check the status mark.
2. ❌ MISSING = either soften the copy or wire the action before shipping the claim.
3. 🚧 PARTIAL = clarify the surface in copy (e.g. today "vote" really means "leave a written review").
4. 🟡 READ-ONLY = the data shows but the user can't act — be explicit about that in any product positioning.
5. **Re-audit after every milestone.** The goal is to drive the ❌ count down to *deliberate* (V2-scoped) gaps only. Anything ❌ that the homepage promises is a debt to repay.

## Known divergences — participation bonus (Option A wiring)

The §D5 dispute participation system contributes a small bonus to a user's
`trust_score` field on the `/users/:handle` view-model. The bonus is added at
**read time** in `UserViewService::resolveAugmentedTrustScore()`. The
underlying `bcc_trust_reputation.reputation_score` column is **not** mutated.

This creates an intentional split:

- `trust_score` (augmented, UI only) — what users see on profiles. Includes
  participation contribution clamped to `[0, 100]`.
- `reputation_score` (base, system truth) — the DB column, unchanged. Drives
  every gating decision below.

### Hard rules (enforce on every change)

> **Invariant:** All gating decisions MUST use `reputation_score` (base).
>
> **Prohibition:** `trust_score` (augmented) MUST NOT be used in repositories,
> selectors, or persistence. Use it only for read-side display in
> `/users/:handle` responses.

### Surface inventory

| Reader | What it shows | Sees bonus? | Why |
|---|---|---|---|
| `UserViewService::getUser` (`/u/[handle]`) | User trust score on profile page | ✅ augmented | The user-facing surface |
| `LivingService` (rank progression) | "X reviews to next rank" math | ❌ base | Rank thresholds are absolute base values |
| `AdminDashboardRepository` (wp-admin queue) | Admin user lists / sorts | ❌ base | Mod tools need ground truth |
| `tab-users.php` (wp-admin user table) | Admin sortable score column | ❌ base | Same |
| `getEligiblePanelistUserIds` (panel selection) | Picks panelists by tier | ❌ base | Tier derives from base — bonus shouldn't unlock panel duty |
| Tier / standing / `is_in_good_standing` | Card tier, good-standing seal | ❌ base | Bonus shouldn't paper over fraud signals |
| `bcc_trust_reputation.reputation_score` | The DB column itself | ❌ base | Source of truth |

### Promotion trigger (Option A → Option B)

Promote read-time augmentation to a write-time event integration when **any**
of the "❌ base" surfaces above needs to reflect the participation bonus. Most
likely trigger: a leaderboard or `/me/dashboard` summary that mixes admin
views with user-facing scores.

Migration cost when triggered: emit `reputation_event` rows from
`DisputeParticipationService::recordParticipation()` and let
`ReputationCalculatorService` integrate them on its next recalc. At that
point, delete `resolveAugmentedTrustScore()` and revert to the original
`(int) round($this->reputationRepo->getScore($userId))`.

### V1 smoke checklist

- [ ] Vote on a panel → own profile `trust_score` bumps `+0.01` (rounds to
      same int below the accuracy floor)
- [ ] Reach 5 credited votes → next correct vote bumps `+0.03` once accuracy
      unlocks
- [ ] Repeat-vote on the same dispute → no change (UNIQUE constraint
      idempotency)
- [ ] Hit lifetime trust cap (10.0) → score stops increasing; further votes
      are `was_credited=0` audit rows
- [ ] Suspended user votes → no bonus, profile score unchanged
- [ ] Admin user list still shows base score — divergence visible and
      expected per the inventory above

## Resolved questions (from prior audits)

- ~~`/bcc/v1/flag` — dead or real gap?~~ **Real, live route.** Page-level
  signal flagging via `FlagEndpoint`. Distinct from `/me/reports` (content)
  and `/report-vote` (vote-level). User-facing intent overlaps with the §K1
  reports surface; deferred to V2 to avoid duplicating reporting paths.
- ~~`/bcc-trust/v1/user/status` — overlaps with `/users/:handle`?~~ **Yes.**
  Suspension/verification flags are already in the unified user view-model.
  No frontend gap; the legacy route stays for WP-admin compatibility.
- ~~Namespace migration for `bcc-trust/v1/*` routes?~~ **Decided per-route.**
  Disputes were re-exposed under `bcc/v1`. OAuth flows + admin/fraud reads
  remain at `bcc-trust/v1`. The `bcc-trust-client.ts` helper handles the
  envelope-shape difference (`{success, data}` vs `{data, _meta}`) so
  consumers don't care about the split.
