# V1 Smoke Test Checklist

**Last updated: 2026-06-12**

End-to-end manual walkthrough to verify V1 + V1.5 ship-readiness before
opening a closed beta. Walks the actual user paths through the live
stack: WordPress (Local-by-Flywheel) → bcc-trust REST → Next.js → browser.

Reconciled 2026-06-11 against a live automated pass (harness:
`~/bcc-smoke`, Playwright).

Each check has an explicit setup, action, and expected outcome. Mark
each box as you verify. **Anything you can't make pass is a closed-beta
blocker.**

This is *not* a regression suite — that's V2 work (Playwright + CI).
This is a one-shot pre-launch sanity pass.

---

## Pre-flight

Before starting, confirm:

- [ ] **WordPress + bcc-trust running.** Local-by-Flywheel site on
  `http://blue-collar-crypto-custom.local` reachable in a browser.
  `wp-admin` loads. Active plugins include: bcc-core, bcc-trust,
  blue-collar-crypto-peepso-integration, peepso-core.
- [ ] **Next.js dev server running.** `cd bcc-frontend && npm run dev`.
  `http://localhost:3000` returns the Floor home (anon shape).
- [ ] **Mailpit running.** Local's mail catcher (API on
  `127.0.0.1:10006`) is where signup verification codes and login
  2FA codes land locally — keep it open for §2.
- [ ] **`BCC_FRONTEND_ORIGIN` set** in `wp-config.php` to
  `http://localhost:3000`. Verify by `wp config get BCC_FRONTEND_ORIGIN`
  or grep `wp-config.php`.
- [ ] **Test users seeded.** At minimum:
  - `User A` — regular member, completed onboarding, has linked wallet
  - `User B` — regular member, no claimed page
  - `User C` — owns a claimed validator page (for review/dispute targets)
- [ ] **Test PeepSo page** — at least one validator page in `peepso-page` CPT
  with a `_bcc_page_type='validator'` meta and a corresponding row in
  `bcc_onchain_validators`. Pull the slug for `/v/<slug>` paths.
- [ ] **Backend health gates pass:**
  - [ ] `cd bcc-frontend && npx tsc --noEmit` → `EXIT=0`
  - [ ] `cd app/public/wp-content/plugins/bcc-trust && bash scripts/phpstan-all.sh bcc-trust` → `PASS`

If any of the above fails, fix before continuing — the rest of the
checklist depends on a healthy stack.

---

## 1. Anonymous flows

User is signed out. Open an incognito/private window so no NextAuth
session leaks in.

- [ ] **1.1** GET `/` → "Hot on the Floor" feed renders. Header shows
  Sign in / Sign up buttons (no bell, no user menu).
- [ ] **1.2** Click any card on the Floor → entity profile loads at
  `/v/<slug>` or `/c/<slug>`. Card hero renders with stats + bio.
- [ ] **1.3** On a validator profile, the **Wanted poster + Claim
  CTA** renders when the page is unclaimed. The CTA is disabled with
  a "Sign in to claim" tooltip.
- [ ] **1.4** **ENDORSE button visible-but-disabled** with "Sign in to
  endorse" tooltip on hover.
- [ ] **1.5** **WRITE A REVIEW button visible-but-disabled** with
  sign-in tooltip.
- [ ] **1.6** **GlobalSearch** in the header opens a dropdown when you
  type 2+ chars; results link to `/v/<slug>` etc. Dropdown closes on
  Esc + outside click.
- [ ] **1.7** GET `/directory` → grid of cards, anon-shape (no Pull
  button, no Block toggle on member cards).
- [ ] **1.8** Visit `/u/<handle>` of a real user → profile renders with
  the FLOOR // OPERATOR file-style header (see §4.1), tabs (Reviews /
  Disputes), no settings link.

## 2. Signup + onboarding

Brand-new account through the §B6 + §O1 onboarding wizard.

- [ ] **2.1** GET `/signup` → form renders with email, password,
  handle, optional display name fields.
- [ ] **2.2** **Handle live-validation:** typing `ab` (too short) shows
  "3-20 chars" error; `simon!` (bad chars) shows the format error;
  `admin` (reserved) shows "reserved"; a known-taken handle shows
  "already taken".
- [ ] **2.3** Submit a valid form → routes to `/verify-email?email=...`.
  A 6-digit code (or verification link) lands in the account's inbox —
  locally, pull it from Mailpit. Code is valid for 15 minutes; the
  account doesn't work until verified.
- [ ] **2.3.1** Enter the code → auto-login fires, then redirect to
  `/onboarding` wizard, step 1: home-chain picker. Skippable.
- [ ] **2.4** Step 2: first-watch suggestions render 3-5 cards. Click
  **KEEP TABS** (the Keep-Tabs rebrand of Watch) on each → button
  flips to its watching state / disabled.
- [ ] **2.5** Click Done → **§O1 dopamine animation** fires:
  - [ ] Cards fly into a watchlist icon (the visual still uses the
    3-ring binder iconography per pattern-registry — name is "watchlist")
  - [ ] Rarity-tinted glow trails (gold/blue/green/white)
  - [ ] Stat-pop "+ N cards · Apprentice rank earned · You're on the
    Floor"
  - [ ] Background shifts cream → concrete
  - [ ] Lands on `/` (the Floor) — NOT a "Done" screen
- [ ] **2.6** **Reduced-motion check:** in browser devtools, set
  `prefers-reduced-motion: reduce`. Re-run a fresh signup. The
  animation falls back to a static confirmation tile.
- [ ] **2.7** Newly authed user sees the Floor with their first-pull
  cards already in the For You feed.
- [ ] **2.8** Hit `/onboarding` after completion → redirects to `/`
  (state in `wp_user_meta.bcc_onboarded = 1`).
- [ ] **2.9** **Login 2FA:** sign out, then sign back in with
  credentials → routes through `/login/two-factor`. An emailed "login
  verification code" lands (Mailpit locally); enter it and click
  **CONFIRM SIGN-IN** → session established. (Verified working
  2026-06-11.)
- [ ] **2.10** **Wallet-only signup (§4.1):** signed out, on `/signup`
  use the WalletAuthButton: connect → sign the nonce → account created
  with NO email, auto-signed-in via the NextAuth wallet provider.
  Handle-pick respects the same 2.2 validation rules.
- [ ] **2.11** **Recovery-email banner (wallet lockout recovery):** as
  the 2.10 wallet-only account, open `/settings/identity` → Wallets
  section shows the "add a recovery email" banner (gated on
  `has_recovery_email === false`). Submit an email → code lands
  (Mailpit) → verify → banner clears and does not return.
- [ ] **2.12** **Wallet login:** sign out, then log back in with the
  same wallet (connect → sign) → session established, no password ever
  involved.
- [ ] **2.13** **Forgot/reset password:** from `/login` →
  forgot-password → email lands → reset link/code → set a new password
  → old password rejected, new one works.
- [ ] **2.14** **SSO complete-profile (oauth-complete):** sign up via
  X/Google SSO (requires the OAuth bridge secret configured on both
  ends — fail-closed otherwise) → lands on
  `/signup/complete-profile` to pick a handle → account active.

## 3. The Floor

Logged in as User A.

- [ ] **3.1** GET `/` → For You feed renders. Header shows bell, user
  menu, Compose button (or inline Composer below header).
- [ ] **3.2** **Feed mode tabs:** click For You / Watching / Signals
  in turn → tabs swap content (URL param not implemented — state-only,
  no `?feed=...`). Anon-shape doesn't appear.
- [ ] **3.3** **Highlight Strip:** if the user has a highlight, it
  renders above the feed. Dismissing one removes it (server records
  dismissal). Strict slot order: negative > positive > external.
- [ ] **3.4** **Inline Composer (Status tab):** type a 100-char
  message → counter green, submit → post appears in the feed within
  2s (refetch).
- [ ] **3.5** **Inline Composer (Blog tab):** switch tab, fill excerpt
  (80-500 chars) + body, submit → blog post appears with "Read full
  post" affordance on the Floor.
- [ ] **3.6** **Reactions:** feed status posts use emoji reactions
  (👍 ❤️ 😂 😮 🔥 = like/love/haha/wow/fire). Click 👍 on someone
  else's post → count increments, your reaction shows highlighted.
  Recipient's bell increments with "@handle reacted to your post."
  (The Solid/Vouch/Stand-behind trio applies to trust-grammar
  surfaces, not social status posts — those casts live in the
  profile attestation cluster, §J.)
- [ ] **3.7** **Pull batching (§C3):** pull 3 cards within 30 seconds
  on different surfaces. Wait 11+ minutes. **Exactly one** "@you
  pulled 3 cards" feed item renders, frozen — unfollowing one of the
  cards doesn't change the post.

## 4. Member profile

Logged in as User A. Visit `/u/<userA-handle>`.

- [ ] **4.1** Profile header renders as the FLOOR // OPERATOR
  file-style header: JOINED date, FILE no., reputation block, GOOD
  STANDING chip, rank chip. (The earlier Living Header spec —
  streak-flame, today's-impact line, comparison line — was not
  rendered as of 2026-06-11; flag for Phillip to confirm the drop
  is intentional.)
- [ ] **4.2** **Rank progress strip:** shows current rank + next-rank
  progress bar with "X reviews to go" copy (only on own profile).
- [ ] **4.3** Tabs: Reviews, Disputes, Activity, Network, Watching,
  Blog. Click each → content loads.
- [ ] **4.4** Visit `/u/<userB-handle>` (someone else) → no rank
  progress strip, no own-profile-only chrome.
- [ ] **4.5** Privacy: User B sets `watching_hidden=true` (legacy
  alias `binder_hidden` accepted during the §1.1.1 deprecation window) in
  `/settings/privacy`. User A visits `/u/<userB-handle>/watching` →
  sees "Watchlist is private" placeholder.
- [ ] **4.5.1** Visit the legacy URL `/u/<userB-handle>/binder` →
  308 redirects to `/u/<userB-handle>/watching`. The legacy route
  remains alive for one release per `api-contract-v1.md §4.5.1`.

## 5. Validator profile + claim flow

Pick `/v/<unclaimed-validator-slug>` (page exists, no `claimed_by` user).

- [ ] **5.1** Wanted poster renders with operator address.
- [ ] **5.2** Stream is locked with "This validator hasn't claimed
  the page yet" copy.
- [ ] **5.3** Click **CLAIM THIS PAGE** → modal opens at step 1
  (Explanation), NOT directly at wallet picker.
- [ ] **5.4** Step 2 (Wallet connect): wallet picker renders matching
  the chain (Keplr for Cosmos). Cancel + reopen — modal resets to
  step 1.
- [ ] **5.5** Sign the challenge → success step shows "What's next"
  links. Modal closes; profile reloads in claimed state.
- [ ] **5.6** Bio + Stream now editable (you're the claimer). The
  Wanted poster is gone.
- [ ] **5.7** **Multi-chain check (§K3):** if a validator runs on 2+
  chains, the `<ChainTabs>` strip renders below the stats panel with
  one pill per chain. Single-chain pages: strip is hidden.

## 6. Reviewing + endorsing

Logged in as User A. Visit `/v/<userC-claimed-page>`.

- [ ] **6.1** **WRITE A REVIEW** button visible.
  - [ ] Disabled with unlock hint when User A hasn't earned the
    `write_review` level gate yet.
  - [ ] Enabled once the `write_review` level gate is met.
  - [ ] **CAUTION (downvote) grade** additionally requires trusted+
    rep tier AND identity verification (verified 2026-06-11 — the
    old "Level 2+ with rep tier ≥ neutral" line understated the
    downvote gate).
- [ ] **6.2** Click WRITE A REVIEW → unified Composer modal opens with
  Review tab pre-selected. Header shows "Reviewing <pageName>".
- [ ] **6.3** Modal has tabs Update / Review / Blog. The Review tab is
  locked to the target — clicking other tabs works but switching back
  to Review preserves the target.
- [ ] **6.4** Pick grade (Trust/Neutral/Caution) + write body → submit.
  Modal closes; profile refreshes; new review on the page; CTA flips
  to **REMOVE YOUR REVIEW**.
- [ ] **6.5** Click REMOVE YOUR REVIEW → confirm dialog → review
  disappears; CTA flips back.
- [ ] **6.6** **ENDORSE button:** when User A meets §I1 eligibility
  (identity quest done, account ≥ 7 days, fraud score < HIGH), button
  is enabled. Click → profile's endorsement count increments by 1;
  CTA flips to **REMOVE ENDORSEMENT**.
- [ ] **6.7** Click REMOVE ENDORSEMENT → confirm dialog → endorsement
  count decrements; CTA flips back.
- [ ] **6.8** **Self-endorse blocked:** as User C, visit your own
  page. ENDORSE is disabled with "You can't endorse your own page"
  tooltip.
- [ ] **6.9** **Phase γ error contract:** force an endorse failure (e.g.
  an account under the 7-day §I1 age gate, or re-endorse after revoke
  inside a rate window). The UI copy must be the humanized
  `err.code` mapping from `lib/api/errors.ts` — never the raw backend
  `err.message` string (that's the whole point of `humanizeCode()`;
  regression guard: `bcc-frontend/scripts/error-contract-guard.sh`).

## 7. Disputes

User C owns a page that has at least one downvote on it.

- [ ] **7.1** As User C on `/v/<own-slug>` → **OPEN A DISPUTE** button
  renders (owner-only). Other users don't see it. **Caution:** the
  "DISPUTE" button in the entity-page action cluster is the §J
  attestation cast (veteran + wallet gated), NOT this owner
  vote-dispute entry — don't confuse the two. The owner entry is
  `DisputeCallout` (`src/components/disputes/DisputeCallout.tsx`),
  rendered by `EntityProfile.tsx` in the legacy IdentityBlock stack
  on `/v/[slug]`, `/p/[slug]`, `/c/[slug]`; it lazy-loads
  `OpenDisputeModal` on click.
- [ ] **7.2** Click → OpenDisputeModal opens with vote-picker. Pick a
  downvote, write a reason, submit → dispute filed; modal closes;
  refreshes profile.
- [ ] **7.3** As User A (assuming jury-eligible per
  ReputationCalculatorService): GET `/panel`. **PANEL DUTY** entry
  in ViewerMenu opens the page.
- [ ] **7.4** If you have an assignment, the queue shows it with vote
  + reason but no tally. If you don't, the empty state copy is "Not
  on duty".
- [ ] **7.5** Open a case → PanelVoteModal. Cast Accept or Reject →
  modal closes; queue row flips to a "YOU VOTED · ACCEPT/REJECT"
  badge.
- [ ] **7.6** Participation strip shows "TRUST TODAY", "VOTES", and
  "ACCURACY" (if `credited_lifetime ≥ min_for_accuracy`). Numbers
  match what you cast.

## 8. Notifications (§I1)

Logged in as User A. The bell + the digest.

- [ ] **8.1** Bell badge shows unread count (≤ "9+") — verify on BOTH
  the desktop header and the mobile sheet. Refreshes every 60s + on
  window focus.
- [ ] **8.2** Click bell → dropdown (desktop header) / sheet (mobile)
  lists recent notifications, newest first. Click a row → marks read,
  navigates to the linked surface.
- [ ] **8.3** "Mark all read" header button bulk-clears.
- [ ] **8.4** As User B, react to one of User A's posts. Within 5s,
  User A's bell badge increments. The dispatcher fires correctly.
- [ ] **8.5** Settings → Notifications → toggle **bell_bcc_reaction**
  off → save. As User B, react again → User A's bell does NOT
  increment. The §I1 per-event gate works.
- [ ] **8.6** Re-enable bell_bcc_reaction. Toggle **email_digest** on.
  Save → "Saved" confirmation flashes.
- [ ] **8.7** **Manual digest trigger:** as an admin user, hit
  `POST /wp-json/bcc/v1/admin/digest/run-now` (curl with bearer, or
  the browser console while signed into the Next.js app). Response
  reports how many emails went out. User A's inbox receives a
  plain-text digest with subject "Your weekly Floor digest —
  N notifications"; body lists unread items + signed unsubscribe link.
  Re-running within 5 minutes returns `bcc_rate_limited` (cooldown
  guard). Alternative: WP-CLI `wp cron event run bcc_trust_weekly_digest`.
- [ ] **8.8** Click the unsubscribe link in the email. Lands on a
  minimal HTML page reading "You're unsubscribed". Reload Settings →
  Notifications → email_digest is now off (server flipped it).
- [ ] **8.9** Re-trigger the digest with no opt-ins → no email sent;
  cron returns 0 silently.

## 9. Settings

All paths require logged-in. ViewerMenu has Profile, Panel Duty,
Settings, Sign Out.

- [ ] **9.1** **/settings/identity** — handle change form. Submit a
  new valid handle → "Saved" confirmation; `session.user.handle`
  updates after `router.refresh()`.
- [ ] **9.2** Try to change handle within 7 days → 429
  `bcc_rate_limited` rendered as cooldown copy.
- [ ] **9.3** **Connections section:** click Connect X → leaves
  Next.js, lands on twitter.com (or skip to manual after OAuth) →
  redirect back to `/settings/identity?x_verified=success`. Banner
  fires; status query refetches; "Linked as @username" renders.
- [ ] **9.4** Click Disconnect → status flips to disconnected.
- [ ] **9.5** Repeat 9.3-9.4 for GitHub.
- [ ] **9.6** **Wallets section** — linked wallets list renders with
  primary/verified chips, link date, explorer link.
- [ ] **9.7** Click Unlink on a wallet → confirm step → row removed.
  Idempotent (clicking again on a phantom row returns `removed: false`,
  doesn't error).
- [ ] **9.8** **/settings/privacy** — toggle a flag (e.g.
  `watching_hidden`; legacy alias `binder_hidden` accepted during the
  §1.1.1 deprecation window). Save → server returns the full prefs tree.
  Verify on `/u/<handle>/watching` from a different account.
- [ ] **9.9** **/settings/notifications** — toggles work; Save fires
  PATCH; "Saved" auto-fades after 3s; PATCH is partial-only (diff'd
  by the form).
- [ ] **9.10** **/settings/blocks** — block User B by ID. The
  blocks list renders the row.
- [ ] **9.11** Verify on Floor: User B's posts no longer appear in
  User A's feed (FeedRankingService merges blocks into excludedAuthorIds).

## 10. Cards (atomic UI)

- [ ] **10.1** On any feed card or directory grid card, hover →
  tilt animation; click body → flips to back face with stats.
- [ ] **10.2** **Pull button always visible on the front face**
  (per §N7), disabled with sign-in tooltip when anon.
- [ ] **10.3** Foil effect renders on Legendary cards (look at a
  validator with `card_tier: legendary`).
- [ ] **10.4** Social proof line renders below the card name when
  the viewer has network connections to the card ("@x, @y +N follow
  this"). Hidden when zero.
- [ ] **10.5** **Trust-weighted social proof (§O4.1):** verify by
  setting up a network where User A follows User-elite + User-neutral
  + User-caution who all follow card C. The "+N" count includes
  elite/trusted only; caution is excluded.

## 11. Multi-actor flows

Need two browser sessions or two accounts.

- [ ] **11.1** User A endorses User C's page → User C's bell badge
  increments. Click the row → lands on the page. Toggle
  `bell_bcc_endorse` off in /settings/notifications → re-endorse from
  a third user → no bell increment for User C.
- [ ] **11.2** User A reviews User C's page → User C's bell badge
  increments. Click row → lands on the page with the new review.
- [ ] **11.3** User A pulls User B's member card → User B's bell
  badge increments.
- [ ] **11.4** **Block round-trip:** User A blocks User B → User B's
  posts vanish from User A's feed. User B can still see User A's
  posts (block is one-directional from the blocker's view per §K1
  Phase A).

## 12. Quality bars (§L1-§L6, §J2)

- [ ] **12.1** **Lighthouse on Chrome devtools** for `/`,
  `/u/<handle>`, `/v/<slug>`, `/directory`. Performance ≥ 80,
  Accessibility ≥ 90.
- [ ] **12.2** **Mobile (real phone):** load the same four pages on
  iOS Safari + Android Chrome. Verify:
  - [ ] Floor feed scrolls smoothly; cards readable at 375px width
  - [ ] Profile header doesn't overflow; tabs swipe-scroll horizontally
  - [ ] Modal dialogs (claim, review, dispute, vote) fit in viewport
    with the dynamic toolbar showing
  - [ ] Bell + ViewerMenu open correctly; outside-click closes
  - [ ] GlobalSearch dropdown reachable
- [ ] **12.3** **Reduced motion:** OS-level setting (Settings →
  Accessibility → Reduce Motion on iOS / Animations on Android).
  Re-run signup, dopamine animation falls back to static tile.
- [ ] **12.4** **Keyboard navigation:** tab through the Floor → all
  interactive elements reachable in reading order. Esc closes modals
  + dropdowns. Enter on cards activates default action.

## 13. Backend health (admin only)

- [ ] **13.1** `wp-admin` → check Cron view (or via WP-CLI
  `wp cron event list`). Verify `bcc_trust_weekly_digest` is scheduled.
- [ ] **13.2** Trigger `bcc_trust_weekly_digest` manually → check
  WP error log for warnings; verify at least one email-digest opt-in
  user received an email.
- [ ] **13.3** **PHPStan PASS** for bcc-trust at level 8.
- [ ] **13.4** **`composer dump-autoload -o`** in bcc-trust runs
  cleanly (no missing classes after the V1.5 additions).
- [ ] **13.5** **Activate / deactivate / activate** the bcc-trust
  plugin from wp-admin. No fatal errors. Cron jobs re-schedule on
  reactivation.
- [ ] **13.6** Hit `/wp-json/bcc/v1/system/health` (if exposed) →
  green response.

## 14. Known limitations (intentional V2 deferrals)

These are NOT bugs to fix before closed beta — they're documented
gaps. Mark them OK so future-you doesn't re-litigate.

- [ ] **14.1** §K3 chain tabs are visual-only. Clicking a chain pill
  doesn't filter stats yet — V2 work.
- [ ] **14.2** Live signals ticker is static-paginated, no SSE/poll.
  V2 (no `/signals/live` backend route).
- [ ] **14.3** NFT-creator profile-level showcase (`/nft-selections/*`)
  unwired. V2.
- [ ] **14.4** On-chain signal detail panel (`/onchain/:page_id`)
  unwired. V2.
- [x] **14.5** ~~Endorse doesn't fire a bell notification today.~~
  **Shipped 2026-04-30.** `NotificationType::ENDORSE` + the
  `onEndorseAdded` dispatcher subscriber are wired; the
  `bell_bcc_endorse` toggle on /settings/notifications gates writes.
- [ ] **14.6** Composer's §D4 inline `#`/`@` embed search +
  Attach button is deferred. V2.
- [ ] **14.7** Per-event email-digest filtering (vs all-or-nothing
  email opt-in today). V2.
- [ ] **14.8** Fraud admin dashboard lives in wp-admin, not
  Next.js. V2.

---

## How to use this doc

- Walk through it linearly the first time. Each section can be
  re-run independently after a code change to that surface.
- A failed check is a closed-beta blocker by default. If you choose
  to ship despite a failure, document it in `## Known limitations`.
- Ignore mobile (§12.2) at your own risk — it's the surface that
  burns hardest in user testing if neglected.
- After every milestone, refresh the **Last updated** date at the
  top + flip §14 entries to ✅ as their V2 implementations land.
