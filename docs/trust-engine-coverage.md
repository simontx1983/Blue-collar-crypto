# Trust Engine — Frontend Coverage

**Last audited: 2026-06-28** (full route resync against checked-in code)

## Why this doc exists

Backend leads, frontend trails. The bcc-trust plugin exposes a much fuller engine
than the Next.js frontend reaches. Whenever a marketing claim, copy change, or
design promises a verb, the question is always: *do we actually expose that to
users today?*

This doc is the single source of truth for that question. Read it **before**:

- Promising a verb in landing copy (e.g. "Vote, endorse, or dispute")
- Sketching a button or affordance into a Figma frame
- Telling a partner "we already do X"
- Cutting V2 scope (so you know which gaps are deliberate vs accidental)

It's a snapshot, not a contract. Re-audit after each milestone.

## What changed since the last audit (2026-06-12 → 2026-06-28)

Three engine moves landed and several whole domains came online, so this audit
is a full resync rather than a diff. Present-tense reality:

- **Attestation cutover (Slice E).** The endorsement *write* path is retired.
  `/bcc-trust/v1/endorse` and `/revoke-endorsement` now cast/revoke a
  `kind=vouch` **attestation** via `AttestationService::cast()` /
  `revokeByTarget()`; the response shape is preserved byte-compatibly so
  `EndorseButton` is unchanged. `EndorsementService` survives only as read-only
  eligibility. A first-class **Attestations** domain (`/me/attestations*`,
  `/entities/:kind/:id/attestations`) is now FE-wired.
- **Watch eradication.** The "Binder" → "Watching" rename is complete. The
  legacy `/me/binder/*` alias routes are removed (no deprecation runway left);
  only `/me/watching/*` exists.
- **Reaction grammar narrowed.** Post reaction kinds are now **`solid`** and
  **`fire`** (`ReactionTypeRegistry`). `vouch` is no longer a reaction — it's
  the per-author byline attestation toggle (`AuthorVouchButton`). `stand_behind`
  was hard-deleted.
- **New domains shipped to the FE:** messaging, comments, the per-profile NFT
  showcase (previously "all ❌"), holder-group join/leave, push subscriptions,
  notification/profile prefs, badges/reliability, follows, group surfaces, and
  search verticals.

## Legend

| Mark | Meaning |
|---|---|
| ✅ | Wired. Typed client wrapper exists and at least one UI surface uses it. |
| 🟡 | Read-only. Frontend reads the data but provides no UI to mutate. |
| 🚧 | Partial. Wrapper exists but a sibling route is missing, OR the verb is wired internally but has no user-facing button (e.g. vote-via-review). |
| ❌ | Missing. Backend exposes the route, frontend has no client at all. |
| ⛔ | Retired route — kept in the table only to record that it's gone. |

## Source of truth (where the answers come from)

- **Backend route registrations**: `wp-content/plugins/bcc-trust/app/Domain/**/REST/*.php`
  and `**/Controllers/*.php` (plus bcc-core + bcc-search) — grep
  `register_rest_route(`.
- **Frontend wrappers**: [bcc-frontend/src/lib/api/*-endpoints.ts](../bcc-frontend/src/lib/api/).
- **API contract**: [docs/api-contract-v1.md](api-contract-v1.md) — canonical
  request/response shapes + what each verb *means*.
- **Database schema**: [docs/database-schema.md](database-schema.md).
- **Glossary**: [docs/glossary.md](glossary.md) — UI / product vocabulary
  (Watching, reactions `solid`/`fire`, Vouch, attestations, etc.).

## Namespace conventions

- `bcc/v1/*` — public-facing reads + most mutations (posts, disputes,
  attestations, claims, wallets, messaging, **report-user**). The headless
  Next.js frontend mostly hits this namespace.
- `bcc-trust/v1/*` — trust-engine-internal mutations (`endorse`,
  `device-fingerprint`), OAuth flows (X / GitHub), and admin/fraud reads.
  The `bcc-trust-client.ts` helper handles the envelope-shape difference
  (`{success, data}` vs `{data, _meta}`) so consumers don't care about the split.

---

## Auth & Identity

| Route | Method | Frontend wrapper | Status | Notes |
|---|---|---|---|---|
| `/bcc/v1/auth/signup` | POST | `auth-endpoints.signup` | ✅ | `(auth)/signup` |
| `/bcc/v1/auth/login` | POST | `auth-endpoints.loginWithEmail` | ✅ | email+password → 2FA challenge |
| `/bcc/v1/auth/nonce` | GET | `auth-endpoints.getWalletNonce` | ✅ | authed wallet-link challenge |
| `/bcc/v1/auth/wallet-link` | POST | `auth-endpoints.linkWallet` | ✅ | |
| `/bcc/v1/auth/wallet-nonce` | GET | `auth-endpoints.getPublicWalletNonce` | ✅ | anon challenge; `WalletAuthButton` (login + signup) |
| `/bcc/v1/auth/wallet-login` | POST | NextAuth wallet Credentials provider (`lib/auth.ts`) | ✅ | No typed wrapper — the dead `walletLogin()` export was deleted 2026-06-12; login flows through NextAuth so the JWT lands in the session |
| `/bcc/v1/auth/wallet-signup` | POST | `auth-endpoints.walletSignup` | ✅ | `WalletAuthButton` connect → sign → account |
| `/bcc/v1/auth/forgot-password` | POST | `auth-endpoints.requestPasswordReset` | ✅ | `(auth)/forgot-password` |
| `/bcc/v1/auth/reset-password` | POST | `auth-endpoints.confirmPasswordReset` | ✅ | `(auth)/reset-password` |
| `/bcc/v1/auth/2fa/verify` | POST | `auth-endpoints.verify2fa` | ✅ | `(auth)/login/two-factor` |
| `/bcc/v1/auth/2fa/resend` | POST | `auth-endpoints.resend2faCode` | ✅ | same page |
| `/bcc/v1/auth/oauth` | POST | NextAuth SSO provider (`lib/auth.ts`) | ✅ | find-user → JWT / `handle_required` bridge for X/GitHub SSO |
| `/bcc/v1/auth/oauth-complete` | POST | `auth-endpoints.oauthComplete` | ✅ | `(auth)/signup/complete-profile` (SSO handle pick) |
| `/bcc/v1/auth/refresh` | POST | `lib/api/client.ts` (`bccFetch`) | ✅ | Phase β silent refresh — transparent, pre-request |
| `/bcc/v1/auth/verify-email` | POST | `auth-endpoints.verifyEmail` | ✅ | `(auth)/verify-email` |
| `/bcc/v1/auth/resend-verification` | POST | `auth-endpoints.resendVerification` | ✅ | same page |
| `/bcc/v1/auth/logout-everywhere` | POST | `account-endpoints.logoutEverywhere` | ✅ | revoke every outstanding token |
| `/bcc/v1/me/account/recovery-email` | POST | `account-endpoints.requestRecoveryEmail` | ✅ | settings wallets banner (wallet-only lockout recovery) |
| `/bcc/v1/me/account/recovery-email/verify` | POST | `account-endpoints.verifyRecoveryEmail` | ✅ | same banner, code-entry step |
| `/bcc/v1/me/account/email` | PATCH | `account-endpoints.patchAccountEmail` | ✅ | requires current password |
| `/bcc/v1/me/account/password` | PATCH | `account-endpoints.patchAccountPassword` | ✅ | requires current password |
| `/bcc/v1/me/account` | DELETE | `account-endpoints.deleteAccount` | ✅ | requires current password |
| `/bcc/v1/me/account-activity` | GET | `account-endpoints.getMyAccountActivity` | ✅ | `AccountActivitySection` |
| `/bcc-trust/v1/x/auth` | GET | `oauth-endpoints.getXAuthUrl` | ✅ | `?return_to=` validated against `BCC_FRONTEND_ORIGIN`, persisted for the callback |
| `/bcc-trust/v1/x/callback` | GET | (browser-redirect target) | ✅ | reads return URL from user meta |
| `/bcc-trust/v1/x/status` | GET | `oauth-endpoints.getXStatus` | ✅ | |
| `/bcc-trust/v1/x/disconnect` | POST | `oauth-endpoints.disconnectX` | ✅ | bearer JWT only |
| `/bcc-trust/v1/x/verify-share` | POST | `oauth-endpoints.verifyXShare` | ✅ | wrapper exists; share-X quest UI is V2 |
| `/bcc-trust/v1/github/auth` | GET | `oauth-endpoints.getGitHubAuthUrl` | ✅ | same return-URL contract as X |
| `/bcc-trust/v1/github/callback` | GET | (browser-redirect target) | ✅ | |
| `/bcc-trust/v1/github/status` | GET | `oauth-endpoints.getGitHubStatus` | ✅ | |
| `/bcc-trust/v1/github/disconnect` | POST | `oauth-endpoints.disconnectGitHub` | ✅ | |
| `/bcc-trust/v1/github/refresh` | POST | `oauth-endpoints.refreshGitHub` | ✅ | wrapper exists; refresh button is V2 polish |
| `/bcc-trust/v1/device-fingerprint` | POST | `fingerprint-endpoints.postFingerprint` | ✅ | fired once per session by `<FingerprintReporter />` (idle-deferred, fail-silent) |
| `/bcc-trust/v1/user/status` | GET | — | (redundant) | suspension/verification flags already in the `/users/:handle` view-model — legacy WP-admin endpoint, no frontend gap |

> There is no `/bcc/v1/auth/token` route. Earlier audits listed one; the JWT
> exchange happens inside `/auth/login` and `/auth/oauth`.

**Impact**: "Verified Human" copy is backed end-to-end — wallet sig + X handle +
GitHub identity are all reachable from settings.

---

## Attestations

Backend `bcc/v1`. The central trust verb since Slice E (2026-06-25). Casting an
attestation on an entity card synchronously re-folds that entity's trust score.

| Route | Method | Frontend wrapper | Status | Notes |
|---|---|---|---|---|
| `/bcc/v1/me/attestations` | POST | `attestations-endpoints.castAttestation` | ✅ | `AttestationActionCluster` (entity + author surfaces) |
| `/bcc/v1/me/attestations/{id}` | DELETE | `revokeAttestation` | ✅ | soft-delete + score re-fold |
| `/bcc/v1/me/attestations/{id}/reaffirm` | POST | — | ❌ | decay reaffirmation; wrapper + `useReaffirmAttestation` removed 2026-08-14 as dead code — no UI surface ever called them |
| `/bcc/v1/entities/{kind}/{id}/attestations` | GET | `getAttestationRoster` | ✅ | `AttestationRoster` / `BackingPanel` |

**Impact**: the per-author **Vouch** byline toggle (`AuthorVouchButton`) and the
entity **Endorse** button both cast attestations through this domain. There is
no separate GET `/me/attestations` list — the roster read is the entity route
above.

---

## Endorsements (now an attestation alias)

| Route | Method | Frontend wrapper | Status | Notes |
|---|---|---|---|---|
| `/bcc-trust/v1/endorse` | POST | `endorse-endpoints.endorsePage` | ✅ | Slice E: casts a `kind=vouch` attestation via `AttestationService::cast()`; response shape preserved so `EndorseButton` / `EntityProfile` are unchanged |
| `/bcc-trust/v1/revoke-endorsement` | POST | `endorse-endpoints.revokeEndorsement` | ✅ | revokes the vouch attestation (`revokeByTarget`) |
| `/bcc/v1/endorsements/mine`, `/endorsements/mine/stats` | GET | — | ❌ | own-endorsements list; no FE wrapper (the same data is in the user view-model) |

**Impact**: `EndorsementService` is now read-only eligibility
(`getEndorseEligibility` — auth + self-block + identity-quest + account-age +
fraud-score). The write path is the attestation system; card view-models expose
`viewer_attestation` + the endorse/attest permission gates.

---

## Voting (page up/down)

There is **no public vote REST route**. Voting is an internal scoring operation
(`VoteService`) performed when a review is created or removed — not a verb the
frontend calls directly.

| Surface | Mechanism | Status | Notes |
|---|---|---|---|
| Cast a vote | folded into review creation — `posts-endpoints.createReview` (POST `/bcc/v1/posts`, kind=review) | 🚧 | **No slim button-vote UI.** §O5 progressive disclosure gates voting behind reviews on purpose |
| Remove a vote | `posts-endpoints.removeReview` (DELETE `/bcc/v1/me/reviews/:id`) | 🚧 | self-removal only |
| Report a vote / per-user score breakdown | — | ❌ | not built — no `/report-vote` or `/pages/scores` route exists |

> The `/bcc-trust/v1/vote` / `/remove-vote` routes named in earlier audits are
> **not registered REST routes**. Trust Engine copy says "Vote, endorse, or
> dispute" — the "vote" verb is fused to the review flow. Either soften the copy
> ("review, react, or dispute") or accept the framing.

---

## Disputes

Backend lives at `bcc/v1/disputes/*` (`DisputeController::NS = 'bcc/v1'`).

| Route | Method | Frontend wrapper | Status | Notes |
|---|---|---|---|---|
| `/bcc/v1/disputes` | POST | `disputes-endpoints.openDispute` | ✅ | OpenDisputeModal, owner-only via `permissions.can_dispute` |
| `/bcc/v1/disputes/votes/{page_id}` | GET | `getDisputableVotes` | ✅ | picker for OpenDisputeModal |
| `/bcc/v1/disputes/mine` | GET | `getMyDisputes` | ✅ | also surfaced in the profile tab via `user-activity.getUserDisputes` (`/users/:handle/disputes`) |
| `/bcc/v1/disputes/panel` | GET | `getPanelQueue` | ✅ | `/panel` route + ViewerMenu link |
| `/bcc/v1/disputes/{id}/vote` | POST | `castPanelVote` | ✅ | PanelVoteModal |
| `/bcc/v1/disputes/participation/me` | GET | `getMyParticipation` | ✅ | drives ParticipationStrip on `/panel` |
| `/bcc/v1/disputes/{id}/resolve` | POST | — | ❌ | admin force-resolve — wp-admin, V2 |
| `/bcc/v1/disputes/health` | GET | — | ❌ | internal health check |
| `/bcc/v1/report-user` | POST | `report-user-endpoints.reportUser` | ✅ | `ReportMemberModal` (was listed ❌ + wrong namespace in the 2026-06-12 audit) |

**Impact**: the dispute domain is fully surfaced end-to-end — file, panel queue,
hidden tallies during deliberation, participation strip against caps.

---

## Reviews & Posts

| Route | Method | Frontend wrapper | Status | Notes |
|---|---|---|---|---|
| `/bcc/v1/posts` (kind=review) | POST | `posts-endpoints.createReview` | ✅ | also casts the page vote |
| `/bcc/v1/posts` (kind=status) | POST | `posts-endpoints.createPost` | ✅ | |
| `/bcc/v1/posts` (kind=blog) | POST | `posts-endpoints.createBlog` | ✅ | |
| `/bcc/v1/posts/photo` | POST | `posts-endpoints.createPhotoPost` | ✅ | multipart |
| `/bcc/v1/posts/gif` | POST | `posts-endpoints.createGifPost` | ✅ | Giphy URL |
| `/bcc/v1/posts/{id}` | GET, PATCH | `blog-endpoints.getBlogPost`, `posts-endpoints.updateBlog` | ✅ | EDITABLE = PATCH/PUT |
| `/bcc/v1/me/reviews/{id}` | DELETE | `posts-endpoints.removeReview` | ✅ | |
| `/bcc/v1/photos/{id}/alt` | PATCH | `posts-endpoints.setPhotoAlt` | ✅ | a11y alt text |
| `/bcc/v1/me/reports` | POST | `reports-endpoints.createContentReport` | ✅ | content report |

> There is no `/bcc/v1/flag` route. The "page-level signal flagging" endpoint
> from earlier audits was never registered — content reporting goes through
> `/me/reports`, member reporting through `/report-user`.

---

## Comments

| Route | Method | Frontend wrapper | Status |
|---|---|---|---|
| `/bcc/v1/posts/{feed_id}/comments` | GET, POST | `comment-endpoints.listComments`, `createComment` | ✅ |
| `/bcc/v1/posts/{feed_id}/comments/{comment_id}` | DELETE | `comment-endpoints.deleteComment` | ✅ |

---

## Cards & Browse

| Route | Method | Frontend wrapper | Status | Notes |
|---|---|---|---|---|
| `/bcc/v1/cards` | GET | `cards-list-endpoints.getCardsList` | ✅ | canonical trust-ranked browse |
| `/bcc/v1/cards/{type}/{id}` | GET | `card-endpoints.getCardEntity` | ✅ | view-model includes `viewer_attestation` + endorse/attest permissions |
| `/bcc/v1/cards/search` | GET | `cards-search-endpoints.getSearchSuggestions` | ✅ | |
| `/bcc/v1/entities/{kind}/{id}/{reviews,disputes,watchers}` | GET | `card-tabs-endpoints` (EntityTabs) | ✅ | per-card tabs |
| `/bcc/v1/discover` | GET | — | ⛔ | route deleted; `/cards` is canonical browse |

---

## Search & Discovery

| Route | Method | Frontend wrapper | Status | Notes |
|---|---|---|---|---|
| `/bcc/v1/search` | GET | `cards-search-endpoints.*` | ✅ | bcc-search global |
| `/bcc/v1/search/users` | GET | `members-endpoints` / `mentions-endpoints` | ✅ | |
| `/bcc/v1/search/groups` | GET | `groups-discovery-endpoints` | ✅ | |
| `/bcc/v1/hashtags/trending` | GET | `hashtags-endpoints.getTrendingHashtags` | ✅ | |
| `/bcc/v1/users/mention-search`, `/members` | GET | `mentions-endpoints`, `members-endpoints` | ✅ | |
| `/bcc/v1/suggestions/users` | GET | `suggestions-endpoints` | ✅ | follow suggestions |

---

## Profile

| Route | Method | Frontend wrapper | Status |
|---|---|---|---|
| `/bcc/v1/users/{handle}` | GET | `user-endpoints.getUser` | ✅ |
| `/bcc/v1/users/{handle}/{reviews,disputes,activity,blog,shift-log}` | GET | `user-activity-endpoints.*`, `blog-endpoints.getUserBlog` | ✅ |
| `/bcc/v1/users/{handle}/{follows,groups,albums}` | GET | `user-activity-endpoints.*` | ✅ |
| `/bcc/v1/users/{id}/follow` | POST, DELETE | follow controls (user-activity) | ✅ |
| `/bcc/v1/me/profile`, `/me/profile/fields`, `/me/profile-prefs` | GET, PATCH | `profile-endpoints`, `profile-fields-endpoints`, `profile-prefs-endpoints` | ✅ |
| `/bcc/v1/me/privacy` | GET, PATCH | `privacy-endpoints` | ✅ |
| `/bcc/v1/me/blocks` | GET, POST, DELETE | `blocks-endpoints` | ✅ |
| `/bcc/v1/me/badges` | GET | `useBadges` hook | ✅ |
| `/bcc/v1/me/reliability` | GET | `me-reliability-endpoints` | ✅ |
| `/bcc/v1/pages/{id}/claim` | POST | `pages-endpoints.claimPage` | ✅ |
| `/bcc/v1/pages/{id}/avatar` | POST, DELETE | `page-avatar-endpoints` | ✅ |

---

## Watching & Cards-In-Hand

Renamed from "Binder & Cards-In-Hand" — the rename is **complete** (2026-06-28).
The legacy `/me/binder/*` alias routes are removed; the frontend talks to
`/me/watching/*` exclusively.

| Route | Method | Frontend wrapper | Status |
|---|---|---|---|
| `/bcc/v1/me/watching` | GET | `watching-endpoints.getWatching` | ✅ |
| `/bcc/v1/me/watching/summary` | GET | `getWatchingSummary` | ✅ |
| `/bcc/v1/me/watching/watch` | POST | `watchCard` | ✅ |
| `/bcc/v1/me/watching/{follow_id}` | DELETE | `unwatchCard` | ✅ |

---

## Feed & Reactions

| Route | Method | Frontend wrapper | Status | Notes |
|---|---|---|---|---|
| `/bcc/v1/feed/hot` | GET | `feed-endpoints.getHotFeed` | ✅ | anon-OK trending |
| `/bcc/v1/feed` | GET | `getFeed` | ✅ | personalized |
| `/bcc/v1/feed/tag` | GET | `getTagFeed` | ✅ | hashtag feed |
| `/bcc/v1/feed/{id}` | GET | (permalink target) | ✅ | single-activity |
| `/bcc/v1/feed/cold-start` | GET | `cold-start-endpoints` | ✅ | new-user feed |
| `/bcc/v1/reactions`, `/reactions/{feed_id}` | POST, DELETE | `reaction-endpoints.setReaction`, `removeReaction` | ✅ | reaction kinds are **`solid`** + **`fire`** (`ReactionTypeRegistry`); `vouch` is the byline attestation toggle, `stand_behind` retired |
| `/bcc/v1/feed/{id}/stoke` | POST, DELETE | Stoke control (`ReactionRail`) | ✅ | feed-level boost, distinct from reactions |
| per-user blog tab | GET | `blog-endpoints.getUserBlog` | ✅ | |

---

## Messaging

| Route | Method | Frontend wrapper | Status | Notes |
|---|---|---|---|---|
| `/bcc/v1/me/conversations` | GET, POST | `messages-endpoints.listConversations`, `startConversation` | ✅ | `/messages`, ConversationsPanel |
| `/bcc/v1/me/conversations/{id}/messages` | GET, POST | `getConversation`, `replyInConversation` | ✅ | `/messages/[id]`, MessageComposer |
| `/bcc/v1/me/conversations/{id}/read` | POST | — | ❌ | wrapper removed 2026-08-14 as dead code — no UI surface called it |
| `/bcc/v1/me/messages/unread-count` | GET | — | ❌ | header badge now reads the aggregate `/me/badges` payload; the dedicated wrapper was removed 2026-08-14 as dead code |
| `/bcc/v1/me/messages-prefs` | GET, PATCH | `messages-prefs-endpoints` | ✅ | MessagesPrefsForm |

---

## Halls (per-chain communities)

| Route | Method | Frontend wrapper | Status |
|---|---|---|---|
| `/bcc/v1/halls` | GET | `halls-endpoints.getHalls` | ✅ |
| `/bcc/v1/halls/{slug}` | GET | `getHall` | ✅ |
| `/bcc/v1/me/halls/{id}/primary`, `/me/halls/primary` | POST, DELETE | `setPrimaryHall`, `clearPrimaryHall` | ✅ |
| `/bcc/v1/me/halls/{id}/membership` | POST, DELETE | `joinHall`, `leaveHall` | ✅ |

---

## Groups & Holder Groups

| Route | Method | Frontend wrapper | Status | Notes |
|---|---|---|---|---|
| `/bcc/v1/groups` | GET | `groups-discovery-endpoints` | ✅ | discover/list |
| `/bcc/v1/groups/{id}`, `/groups/{id}/feed`, `/groups/{id}/members` | GET | `groups-detail-endpoints`, `members-endpoints` | ✅ | |
| `/bcc/v1/me/groups`, `/me/groups/{id}/{join,leave}` | GET, POST | `my-groups-endpoints` | ✅ | GroupsPanel / GroupActionButton |
| `/bcc/v1/me/holder-groups` | GET | `holder-groups-endpoints.getMyHolderGroups` | ✅ | NFT-gated; CommunityJoinCard / CommunitiesList |
| `/bcc/v1/me/holder-groups/{id}/join` | POST | `joinHolderGroup` | ✅ | |
| `/bcc/v1/me/holder-groups/{id}/leave` | POST | `leaveHolderGroup` | ✅ | |
| `/bcc/v1/me/holder-groups/preferences` | GET, PATCH | `getHolderGroupPreferences`, `updateHolderGroupPreferences` | ✅ | |

---

## Notifications, Highlights, Celebrations, Prefs & Push

| Route | Method | Frontend wrapper | Status | Notes |
|---|---|---|---|---|
| `/bcc/v1/me/notifications` | GET | `notifications-endpoints.getNotifications` | ✅ | |
| `/bcc/v1/me/notifications/unread-count` | GET | — | ❌ | served by the aggregate `/me/badges` payload; the dedicated wrapper was removed 2026-08-14 as dead code |
| `/bcc/v1/me/notifications/mark-read` | POST | `markNotificationsRead` | ✅ | (was `…/read` in the prior audit) |
| `/bcc/v1/me/notification-prefs` | GET, PATCH | `notification-prefs-endpoints` | ✅ | |
| `/bcc/v1/me/push-subscriptions` (+ `/vapid-public-key`, `/{id}`) | GET, POST, DELETE | `push-endpoints.getVapidPublicKey`, `registerPushSubscription` | 🚧 | V2 Phase 1 push; DELETE has no client — `revokePushSubscription` was removed 2026-08-14 as dead code |
| `/bcc/v1/me/highlights` | GET | `highlights-endpoints.getHighlights` | ✅ | |
| `/bcc/v1/me/highlights/{id}/dismiss` | POST | `dismissHighlight` | ✅ | |
| `/bcc/v1/me/celebrations/pending` | GET | `celebrations-endpoints.getPendingCelebration` | ✅ | |
| `/bcc/v1/me/celebrations/consume` | POST | `consumeCelebration` | ✅ | |

---

## Onboarding

| Route | Method | Frontend wrapper | Status |
|---|---|---|---|
| `/bcc/v1/onboarding/suggestions` | GET | `onboarding-endpoints.getOnboardingSuggestions` | ✅ |
| `/bcc/v1/me/onboarding/status` | GET | `getOnboardingStatusServerSide` | ✅ |
| `/bcc/v1/me/onboarding/complete` | POST | `completeOnboarding` | ✅ |
| `/bcc/v1/me/handle` | PATCH | `updateHandle` | ✅ |

---

## Creator Gallery

| Route | Method | Frontend wrapper | Status |
|---|---|---|---|
| `/bcc/v1/creators/{slug}/gallery` | GET | `creator-gallery-endpoints.getCreatorGallery` | ✅ |

---

## On-chain Signals

| Route | Method | Frontend wrapper | Status | Notes |
|---|---|---|---|---|
| `/bcc/v1/wallets` | GET | `wallets-endpoints.getMyWallets` | ✅ | listed at `/settings/identity` |
| `/bcc/v1/wallets/{id}` | DELETE | `wallets-endpoints.unlinkWallet` | ✅ | confirm-and-unlink |
| `/bcc/v1/wallets/project/{post_id}` | GET | — | ❌ | project wallet listing (owner/admin) — V2 |
| `/bcc/v1/chains` | GET | — | ❌ | supported-chain whitelist (internal) — V2 |
| `/bcc/v1/onchain/{page_id}/refresh` | POST | — | ❌ | manual re-index trigger — V2 |

**Impact**: §B2 multi-wallet linking is end-to-end visible. There is **no
`GET /onchain/:page_id`** read route — the rich page-level on-chain detail panel
remains a V2 surface; validator/project cards render basic stats from
`/users/:handle` + `/cards/:type/:id` today.

---

## NFT Showcase

Shipped since the last audit — this was the "entire module is V2 / all ❌"
section on 2026-06-12.

| Route | Method | Frontend wrapper | Status | Notes |
|---|---|---|---|---|
| `/bcc/v1/nft-selections/picker` | GET | `nft-selections-endpoints.getNftPicker` | ✅ | NftPickerModal |
| `/bcc/v1/nft-selections` | GET | `listNftSelections` | ✅ | NftShowcaseSettings |
| `/bcc/v1/nft-selections` | POST | `saveNftSelection` | ✅ | |
| `/bcc/v1/nft-selections` | DELETE | `deleteNftSelection` | ✅ | |
| `/bcc/v1/nft-selections/refresh` | POST | (refresh in picker) | ✅ | |
| `/bcc/v1/nft-selections/reorder` | POST | `reorderNftSelections` | ✅ | |
| `/bcc/v1/nft-pieces/{chain}/{contract}/{token}` | GET | `nft-pieces-endpoints.getNftPiece` | ✅ | single-piece metadata |

---

## Admin (fraud / stats / reports)

| Route | Method | Frontend wrapper | Status | Notes |
|---|---|---|---|---|
| `/bcc/v1/admin/reports` | GET | `admin-reports-endpoints.getAdminReports` | ✅ | moderation queue |
| `/bcc/v1/admin/reports/{id}/resolve` | POST | `resolveAdminReport` | ✅ | |
| `/bcc/v1/admin/reports/undo` | POST | `undoAdminReport` | ✅ | |

The eight `bcc-trust/v1` fraud/stats routes (`/fraud/stats`,
`/users/high-risk`, `/activity/fraud`, `/stats/*`, `/analyze-user/{id}`)
were **deleted 2026-07-21** (admin-audit dead-endpoint cleanup): their only
consumer was admin.js fetch code targeting DOM the server-rendered Trust
Engine dashboard tabs no longer emit. The fraud surface itself still lives
in wp-admin — rendered server-side via `AdminDashboardRepository`.

---

## System & internal (non-FE, listed for completeness)

| Route | Method | Surface |
|---|---|---|
| `/bcc/v1/system/health`, `/system/ping` (bcc-core) | GET | infra / uptime probe |
| `/bcc-trust/v1/health/read-model` | GET | read-model sync health (wp-admin) |
| `/bcc/v1/webhooks/helius` | POST | Solana indexer webhook |
| `/bcc/v1/admin/indexer/tick` | POST | NFT indexer cron trigger |
| `/bcc/v1/digest/unsubscribe`, `/admin/digest/run-now` | GET / POST | email digest |

---

## Coverage summary

| Domain | Wired | Missing |
|---|---|---|
| Auth (core + recovery + account mgmt) | ✅ | — |
| Auth (X / GitHub / fingerprint) | ✅ all | — |
| Attestations | ✅ cast + revoke + reaffirm + roster | — |
| Endorsements (vouch alias) | ✅ give + revoke | own-list (`/endorsements/mine`) — V2 |
| Voting | 🚧 review-fused only | slim vote, vote reporting, score breakdown — copy-fix / V2 |
| Disputes | ✅ open + panel + vote + participation + report-user | resolve (admin), health — V2 |
| Reviews & posts | ✅ full (review/status/blog/photo/gif/alt) | — |
| Comments | ✅ full | — |
| Cards & browse | ✅ core + entity tabs | `/discover` retired; project/chain lookups — V2 |
| Search & discovery | ✅ full | — |
| Profile (+ follows / fields / prefs / badges / reliability) | ✅ full | — |
| Watching (rename complete) | ✅ full | — |
| Feed & reactions (+ stoke) | ✅ full | — |
| Messaging | ✅ full | — |
| Halls | ✅ full | — |
| Groups & holder groups | ✅ full | — |
| Notifications / highlights / celebrations / prefs / push | ✅ full | — |
| Onboarding | ✅ full | — |
| Creator gallery | ✅ full | — |
| Wallets | ✅ list + unlink | project/chains/onchain-refresh — V2 |
| On-chain detail (page-level read) | — | ❌ no read route exists — V2 |
| NFT showcase | ✅ full | — |
| Admin reports | ✅ | fraud surface wp-admin-only (server-rendered; its REST routes deleted 2026-07-21) |

Every remaining ❌ is V2-scoped or deliberately wp-admin-only. There are no
accidental gaps left from V1 / V1.5 work.

---

## How to use this doc

1. **Before claiming an action in copy**: find the route → check the status mark.
2. ❌ MISSING = either soften the copy or wire the action before shipping the claim.
3. 🚧 PARTIAL = clarify the surface in copy (e.g. today "vote" really means "leave a written review").
4. 🟡 READ-ONLY = the data shows but the user can't act — be explicit about that in any product positioning.
5. **Re-audit after every milestone.** The goal is to drive the ❌ count down to *deliberate* (V2-scoped) gaps only.

## Known divergences — participation bonus (Option A wiring)

The §D5 dispute participation system contributes a small bonus to a user's
`trust_score` field on the `/users/:handle` view-model. The bonus is added at
**read time** in `UserViewService::resolveAugmentedTrustScore()`. The underlying
base score is **not** mutated.

> **Architecture A note:** member trust now lives on the member's self-page row
> in `bcc_trust_page_scores` (`reputationRepo->getScore()`), read via
> `ReputationRepository`. The legacy `bcc_trust_reputation` table is retired.
> The augmentation mechanism is unchanged — only the storage moved.

This creates an intentional split:

- `trust_score` (augmented, UI only) — what users see on profiles. Includes
  participation contribution clamped to `[0, 100]`.
- base `reputation_score` (system truth) — the self-page score, unchanged. Drives
  every gating decision below.

### Hard rules (enforce on every change)

> **Invariant:** All gating decisions MUST use the base `reputation_score`.
>
> **Prohibition:** the augmented `trust_score` MUST NOT be used in repositories,
> selectors, or persistence. Use it only for read-side display in
> `/users/:handle` responses.

### Surface inventory

| Reader | What it shows | Sees bonus? | Why |
|---|---|---|---|
| `UserViewService::getUser` (`/u/[handle]`) | User trust score on profile page | ✅ augmented | the user-facing surface |
| `LivingService` (rank progression) | "X reviews to next rank" math | ❌ base | rank thresholds are absolute base values |
| `AdminDashboardRepository` (wp-admin queue) | admin user lists / sorts | ❌ base | mod tools need ground truth |
| `getEligiblePanelistUserIds` (panel selection) | picks panelists by tier | ❌ base | tier derives from base — bonus shouldn't unlock panel duty |
| Tier / standing / `is_in_good_standing` | card tier, good-standing seal | ❌ base | bonus shouldn't paper over fraud signals |
| `bcc_trust_page_scores` self-page row | the stored base score itself | ❌ base | source of truth |

### Promotion trigger (Option A → Option B)

Promote read-time augmentation to a write-time event integration when **any** of
the "❌ base" surfaces above needs to reflect the participation bonus. Most
likely trigger: a leaderboard or `/me/dashboard` summary that mixes admin views
with user-facing scores.

Migration cost when triggered: emit `reputation_event` rows from
`DisputeParticipationService::recordParticipation()` (which today writes only
participation-audit rows, not reputation events) and let the recalculator
integrate them. At that point, delete `resolveAugmentedTrustScore()` and revert
to the plain base read.

### V1 smoke checklist

- [ ] Vote on a panel → own profile `trust_score` bumps `+0.01` (rounds to same
      int below the accuracy floor)
- [ ] Reach 5 credited votes → next correct vote bumps `+0.03` once accuracy unlocks
- [ ] Repeat-vote on the same dispute → no change (UNIQUE constraint idempotency)
- [ ] Hit lifetime trust cap (10.0) → score stops increasing; further votes are
      `was_credited=0` audit rows
- [ ] Suspended user votes → no bonus, profile score unchanged
- [ ] Admin user list still shows base score — divergence visible and expected

## Resolved questions (from prior audits)

- ~~`/bcc/v1/flag` — dead or real gap?~~ **Neither — the route does not exist.**
  Page-level signal flagging was never registered. Content reporting is
  `/me/reports`; member reporting is `/report-user`.
- ~~`/bcc-trust/v1/user/status` — overlaps with `/users/:handle`?~~ **Yes.**
  Suspension/verification flags are already in the unified user view-model. No
  frontend gap; the legacy route stays for WP-admin compatibility.
- ~~Namespace migration for `bcc-trust/v1/*` routes?~~ **Decided per-route.**
  Disputes + report-user are under `bcc/v1`. `endorse` / `device-fingerprint`,
  OAuth flows, and admin/fraud reads remain at `bcc-trust/v1`. The
  `bcc-trust-client.ts` helper handles the envelope-shape difference
  (`{success, data}` vs `{data, _meta}`) so consumers don't care about the split.
- ~~Are endorsements still their own write path?~~ **No.** Slice E repointed
  `/endorse` to cast a `kind=vouch` attestation; `EndorsementService` is now
  read-only eligibility.
