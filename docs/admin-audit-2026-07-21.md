# Admin Dashboard Audit — 2026-07-21

Live audit of both admin surfaces (Next.js `/admin/*` ops center + wp-admin
"BCC System" cockpit), plus a placement recommendation for sponsorship-data
entry. All findings below were verified against the running local site
(Playwright click-through as a temporarily-promoted admin test user, direct
REST smokes with admin/anon auth, and DB inspection). The temporary admin
promotion (smoke user 141) was reverted; one resolved test report
(report #1, "safe to dismiss") remains in the local DB as a harmless artifact.

## Verdict summary

| Question | Answer |
|---|---|
| Useful to admins? | **Yes, both surfaces** — every page maps to a real subsystem; nothing renders errors; data populates. |
| Overkill? | **No.** The Next.js side is a single moderation queue. The wp-admin side is broad but each page earns its place — except ~8 dead REST stats routes + stale admin.js fetch code (below). |
| Underbuilt? | **One real gap:** the moderation queue has **no inbound link anywhere** — it is unreachable except by typing the URL. Everything else is right-sized for a 2-operator team. |
| Broken? | **One P1 cache-invalidation bug** (admin Hide doesn't take effect publicly until the object cache is flushed) + one P2 (post-kind filter never matches) + assorted P3s. |
| Build sponsorship input here? | **In wp-admin (cockpit), not the Next.js ops center.** Internal-only deal records are low-frequency bookkeeping — §8 routing rule sends them to wp-admin. Recommended shape below. |

## Architecture context

The admin is deliberately **two surfaces by role** (locked 2026-05-27,
`bcc-trust/CLAUDE.md` §8): wp-admin = infrastructure cockpit
(config/repair/health), Next.js `/admin/*` = operational command center
(daily workflows). The Next.js side currently has exactly one page:
`/admin/moderation` (§K1 Phase C reports queue). The wp-admin side is the
"BCC System" menu (bcc-core) + "Trust Engine" menu (bcc-trust legacy) with
~20 pages/tabs.

## Findings (ranked)

### P1 — Moderation queue has no inbound link (reports accumulate invisibly)

`ViewerMenu.tsx` (which contains the "Moderation" menu entry, with a comment
explicitly warning that without it "filed reports accumulated invisibly")
is **dead code — nothing imports it**. The live header menu is the inline
`AVATAR_MENU` in `bcc-frontend/src/components/layout/SiteHeader.tsx:292`,
which has only My Profile / My Progression / Settings / Sign Out. The
left-rail Quick Links don't link the queue either. Verified live: an admin's
menus contain no path to `/admin/moderation`.

**Fix:** add a Moderation entry to `AVATAR_MENU` (and decide whether
`ViewerMenu.tsx` should be deleted as dead code — it also links `/panel`,
which the live menu likewise lacks, though `/panel` is reachable from the
left-rail Quick Links).

### P1 — Generation-counter cache invalidation broken under the Redis object cache (admin Hide doesn't propagate)

Live repro: admin Hide on report #1 wrote the `wp_bcc_hidden_activities`
row correctly, but the post **stayed publicly visible on the anon feed and
permalink for 10+ minutes**, disappearing only after `wp cache flush`.

Mechanism (verified): integers round-trip through the Redis (Predis)
object-cache as **strings** cross-process (`maybe_serialize(0)` → `"0"`).
`HiddenActivityRepository::getGeneration()` (line ~202) checks
`is_int($gen)` and **resets the generation to 0** whenever the check fails —
i.e. on every cross-process read. Every `bustCache()` increment is
immediately reverted, so generation-keyed caches never invalidate.
Same-process reads hit the typed runtime cache, which is why tests and
single-request flows never see it.

Three sites use the fragile strict-`is_int` read:
- `bcc-trust/app/Domain/Core/Repositories/HiddenActivityRepository.php:202` (moderation hide/unhide — the live repro)
- `bcc-trust/app/Domain/Core/Repositories/BlogChainTagRepository.php:241`
- `bcc-core/src/Repositories/PeepSoGroupRepository.php:810,818` (non-open-groups counter)

The other five generation counters (ScoreReadService, ScoreRepository,
AttestorReliabilityCacheRepository, DisputeRepositorySupport,
CollectionSignalRepository) use tolerant reads (`(int)` cast /
`is_numeric` / `!== false`) and are fine.

**Fix:** change the three sites to the tolerant pattern
(`is_numeric($gen) ? (int)$gen : 0`-style, matching
`CollectionSignalRepository.php:284`). Also verify staging/prod behavior:
they run LSMCD (memcached), whose drop-in may or may not preserve int
types — the fix removes the dependency either way. **Until fixed, an
operator hiding hateful/spam content should not trust that it's actually
gone** on any environment with a persistent object cache.

### P2 — Moderation queue POST KIND filter never matches + raw kind + empty preview (one bug, three symptoms)

`ModerationQueueService::shapeTarget()`
(`bcc-trust/.../Services/ModerationQueueService.php:883-894`) sets
`post_kind = (string) $activity->act_module_id` — PeepSo's **numeric module
id** ("1") — where the contract and UI expect semantic kinds
(`status|blog|review|photo|gif`). Live-verified consequences:
- Queue rows render "TARGET · 1" instead of the post kind.
- The preview branch (`$module === 'status' || $module === 'blog'`) never
  matches, so **preview is always empty** even for text posts.
- The POST KIND filter chips can never match a stored report — verified:
  filtering by STATUS made the (status-post) report vanish.

**Fix:** map `act_module_id` → semantic kind at hydration (and in the repo's
filter WHERE clause, which must be comparing the same raw value — check
`ContentReportRepository`'s post_kind filter join).

### P3 — Non-admins get a misleading generic error instead of the "ADMIN ACCESS REQUIRED" panel

The API's permission callback rejects with WP-core `rest_forbidden`, but
`ModerationQueue.tsx`'s `QueueError` (line ~883) branches on
`bcc_forbidden` — so the dedicated 403 panel is unreachable; non-admins see
"Couldn't load reports. Try again in a moment," which invites pointless
retries. Fix: branch on both codes (or emit `bcc_forbidden` from
`BearerAuth::requireAdmin`). §γ rule (branch on `err.code`) is followed —
the code value is just wrong.

### P3 — Queue rows don't link to the reported post

Row copy says "open the activity directly to view in full" but renders no
link (only the reporter is linked). The target has `target_id`/author to
compose the post URL (`CardUrlMap::postUrl` pattern). Operators currently
have to find the post by hand.

### P3 — Expired-undo feedback is visible but too brief

Clicking UNDO after the 30s token expiry produces a 410
(`bcc_undo_expired`) and the toast DOES render "Undo window expired." —
but only for 2 seconds before auto-dismissing, short enough to miss
entirely (the initial audit pass misread this as a silent failure; code
inspection of `UndoToast.tsx` corrected that). Minor: lengthen the
error display so the message can actually be read.

### P3 — `/admin` missing from middleware matcher

`bcc-frontend/src/middleware.ts` matcher covers `/panel`, `/settings/*`,
`/me/*` etc. but not `/admin/:path*`. Not a hole (the server component does
its own session redirect — verified live; the API owns `manage_options`),
but it's the only protected surface relying on page-level checks alone.
One-line consistency fix.

### Dead code / dead endpoints (recommend delete per fresh-install convention)

- **Eight `bcc-trust/v1` admin stats routes are dead in practice**
  (`/fraud/stats`, `/users/high-risk`, `/activity/fraud`,
  `/stats/{trust-trend,risk-distribution,fraud-trend,devices}`,
  `/analyze-user/:id` — `AdminStatsController`). All work (200 with admin
  auth, 401 anon), but the Trust Engine dashboard's current server-rendered
  tabs **never call them**: walked every cluster/sub-tab
  (overview/ecosystem/pages/users/signals[fraud,devices,rings,ml]/activity[log,push])
  with network monitoring — zero `bcc-trust/v1` XHRs. `admin.js` is enqueued
  and contains the fetch code, but its target DOM no longer exists.
  Verify per-selector (audit findings are method-precise, not file-precise),
  then delete routes + the dead admin.js sections, or rewire if the charts
  are wanted back.
- **`POST /bcc/v1/disputes/:id/resolve` + `GET /disputes/health`** — no UI
  consumer (adjudication happens via the wp-admin User Reports/Disputes
  tables). Functional. Either reserve explicitly for the future Next.js
  disputes surface or delete.
- **`POST /bcc/v1/onchain/:page_id/refresh`** — no consumer (ChainsPage
  AJAX covers the operator action). Also returns `{"refreshed":0}` success
  for a nonexistent page id instead of 404. Delete-candidate.
- **`ViewerMenu.tsx`** — dead component (see P1).
- **`POST /bcc/v1/admin/digest/run-now`** — KEEP: documented ops/recovery
  tool, works (verified live; correctly cooldown-gated, 401 anon).

### What's healthy (verified live)

- **Next.js queue:** renders clean; status tabs, reason chips, reporter
  search, date filters, pagination controls, keyboard-shortcut sheet all
  present; Hide/Restore round-trip correct at DB level; 30s server-token
  undo toast appears; resolve/undo audit-logged; envelope shapes correct;
  `Cache-Control: no-store` on the queue.
- **Report filing** (member side) → queue appearance: works end-to-end.
- **Auth:** all admin REST routes 200 admin / 401 anon / 403 non-admin;
  2FA email codes on credential login work; logged-out `/admin/moderation`
  redirects to login with callbackUrl.
- **wp-admin:** all 21 pages/tabs walked render HTTP 200 with **zero PHP
  fatals/warnings/notices**: System Health, API Keys (masked-only — no raw
  secrets, by design), Cron, Developer, Notifications, Sessions, Wallets,
  On-Chain Signals (validator/nft/rpc/spam/health tabs), Chains, Verify
  Collections, Holder Groups, Webhooks, Trust Moderation, Disputes, User
  Reports, Trust Engine dashboard (all 6 clusters + sub-tabs).

## Sponsorship data input — placement recommendation

**Answer: not the Next.js admin dashboard.** Sponsorship data as specified
(internal deal records: contacts, amounts, terms, dates — never shown to
users) is low-frequency bookkeeping/configuration. The §8 routing rule
sends that to the **wp-admin infrastructure cockpit**. Building it in
Next.js would add a REST surface, contract exemptions, and frontend work
for zero user-facing benefit.

Sponsorship is fully greenfield — a repo-wide scan (sponsor, sponsorship,
advertiser, campaign, partner, promo, deal) found **zero prior art** in
bcc-* plugins, bcc-frontend, docs, and bcc-global-library. The only
adjacent artifact is `bcc-frontend/src/components/layout/AdCarousel.tsx`,
a prototype ad slot with three hardcoded "Sponsored" slides and no backend
(untouched by this recommendation).

Recommended build (follow-up slice, pending approval):

- **Table** `wp_bcc_sponsorships` via
  `bcc-trust/includes/database/schema-sponsorships.php` — sponsor name,
  contact name/email, amount + currency, terms/notes, start/end dates,
  status (prospect/active/completed/cancelled), created/updated timestamps.
- **Repository** `app/Domain/Core/Repositories/SponsorshipRepository.php`
  per §1–§5 (`/new-repository` skill; use the tolerant generation-read
  pattern per the P1 above).
- **Admin page** modeled on the existing admin-CRUD precedent:
  `app/Domain/Onchain/Admin/Views/NftSpamContractsView.php` +
  `NftSpamContractRepository` (add/edit/remove form, `add_settings_error`
  feedback), registered as a submenu of `bcc-system-health`,
  `manage_options`-gated.
- **No REST route, no api-contract change, no frontend work.** If sponsor
  data later needs public rendering (e.g. a real AdCarousel), that's a
  separate slice adding a read endpoint — this schema doesn't block it.

## Test-session notes

- Admin path exercised as smoke user A (id 141) temporarily promoted to
  administrator via wp-cli; **reverted to subscriber** at session end
  (admin list verified back to: admin, bluecollarcryptolearning, Tialuxe).
- wp-login has a ReCaptcha that blocks headless login; wp-admin walk used
  wp-cli-generated auth cookies injected into the browser context.
- Report #1 (spam, "Audit smoke test report 2026-07-21 — safe to dismiss")
  remains in the local DB, status resolved, post restored/visible.
- The undo-expiry observed during testing was harness latency (>30s between
  clicks), not a product timeout bug.
