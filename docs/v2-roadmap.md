# V2 Roadmap

**Status as of 2026-05-13:** V1 + V1.5 are scope-complete. V2 is in flight — Push Notifications + NFT Scaling Phase 1 (a/b/c) + Cosmos CW-721 + NFT-piece detail + @mention dispatch + "primary Local" post dispatch + "comment received" dispatch + Good Standing directory filter have all shipped. Remaining items below.

This doc inventories every deferral logged in the V1 plan's §P4 ("the deferred list is real"), groups them by theme, and notes what each one actually buys vs. what it costs. It is **not** a build commitment — it is a working list to drive V2 phase planning.

---

## Scope rules carried forward from §P

The discipline that protected V1 still applies:

- **Adding requires removing.** A new V2 feature does not get bolted onto an existing one — it displaces or gets cut.
- **Four green flags.** Plots onto the loop · unblocks a phase · replaces something heavier · user has explicitly asked for it. Fail any → V2-of-V2.
- **Default answer is no.** Yes is reserved for items that pass all four flags AND have a clear phase home.
- **One thing at a time.** V2 ships in phases like V1 did, not as a feature dump.

---

## Status legend

- **OPEN** — eligible for V2 scoping
- **WON'T SHIP** — was cut for a reason that still holds; revisit only if the reason changes
- **SHIPPED** — already delivered (likely in V1.5); remove from list

---

## Engagement polish

Small-surface retention work. Most items are 1–10 days each.

| Item | What it buys | What it costs | Status |
|---|---|---|---|
| Sound on Heavy celebrations (§O1.2) | Extra dopamine on rare events (rank-up, tier-upgrade) | Asset pipeline + mute-respecting logic + iOS gesture rules | OPEN |
| Streak-freeze / streak-saver mechanic | Retention without punishing one missed day | New rules + UI + cap logic + abuse vector | OPEN |
| `/me/progression` standalone page (§N11) | Full progression map vs. just the Living Header strip | New route + richer view-model | SHIPPED 2026-05-13 — server-component route at `/me/progression` reusing the §3.1 own-only `progression` block (no new endpoint). Renders ALL `next_rank_thresholds` (vs. the leading-only LivingHeader bar) + the `trust_score_recent_changes` timeline. Discoverable via the `ViewerMenu` "Progression" item. |
| Network percentile shown on **others'** profiles (§O3.1) | Status display visible to others | Privacy toggle (some users won't want their rank visible) | OPEN |
| Per-card binder visibility toggle (§C2) | More granular hide control | Whole-binder hide (§K2) already covers the privacy job | WON'T SHIP |
| Rich-text status composer | Format expression for status posts | Conflicts with §D2 500-char rule; long-form is what the §D6 blog tab is for | WON'T SHIP |
| "Friend comparison" Living Header line (§O3.1) | Third comparison kind | Network percentile + Local peer already cover the slot | WON'T SHIP |
| Always-on reaction helper labels | Persistent clarity | Overrides §N1 familiarity drop-off (the whole point of §N5 was one flag, one drop) | WON'T SHIP |
| `@mention` parsing + notifications | Inline reference of users/pages in posts; powers a "you were mentioned" push | Server-side parser + event emission + composer affordance + render layer — nontrivial spread across backend and frontend | SHIPPED 2026-05-11 — parsing/overlay/picker landed earlier; bell + push dispatch completed via `bcc_post_created` / `bcc_comment_created` subscribers. Three locked policy decisions (original-write only, structural dedup, bell+push). See docs/api-contract-v1.md §4.10. |
| Per-category highlight muting (§O2.1) | A control surface that becomes a churn-prevention lever as content density grows | Undermines §O2.1 strict ordering — needs careful design (slot 1 negative signals must remain non-mutable) | OPEN — future retention lever, not now |

**Open subtotal:** 5 items. ~1 week of clustered work (mention dispatch shipped 2026-05-11). Per-category mute is parked — don't build until content density justifies it.

---

## Discovery additions

Curation + navigation polish.

| Item | What it buys | What it costs | Status |
|---|---|---|---|
| "Good Standing only" search filter (§G2) | One-click filter to caution-tier-and-above | Small UI + filter param | SHIPPED 2026-05-13 (`GET /cards?good_standing_only=1`; tier list sourced from `UserViewService::GOOD_STANDING_TIERS` — same constant that drives the per-row `is_in_good_standing` stamp + auth-response flag; chip on `/directory`) |
| Admin-curated featured row on `/directory` (§G4) | Editorial control over discovery | Needs an editorial ops role V1 doesn't have | OPEN — gated on staffing |
| "Community" feed-mode tab (§N6) | A fourth feed mode | Duplicates Following with no system posts removed; cut for overlap | WON'T SHIP |
| "Top cards" metric on Binder header (§N9) | Summary above the grid | The 3×3 grid below already shows them | WON'T SHIP |

**Open subtotal:** 1 item (admin-curated row), operationally gated. The §G2 toggle shipped 2026-05-13.

---

## NFT scaling

The biggest single bucket. V1 shipped on-demand indexing for ETH + SOL only (§H1 V1). This is the V2 ramp.

| Item | What it buys | What it costs | Status |
|---|---|---|---|
| Continuous indexing (mint/transfer event listeners) | Near-real-time gallery vs. stale-while-revalidate cache | Event listener per chain + cron infra + budget cap | PARTIALLY SHIPPED 2026-05-07 — Phase 1 a/b/c backend landed on `bcc-trust` (ETH + SOL confirmation-gated walker, cron self-heal per `project_v2_nft_cron_drift_incident.md`). Active iteration on enrichment + indexer edge cases. Frontend syncing chip deferred until picker UI consumer lands. |
| NFT-piece detail page (§H1) | Deeper drill-down per piece beyond the thumbnail | New route + per-piece view-model | SHIPPED — `/c/[slug]/[tokenId]` route + `NftPieceDetail` component live (`NftPieceEndpoint` + `NftPieceViewModelBuilder` on the backend) |
| Cosmos NFT support (CW-721) | Cosmos creator coverage via standard CW-721 LCD queries | Per-chain fetcher | SHIPPED 2026-05-07 — `feat/v2-phase-2-cosmos-cw721`; intentionally asymmetric with ETH/SOL persistence (read-time + V1 transient, see `project_v2_phase_2_cosmos.md`). |
| Injective NFT support | Injective creator coverage | Per-chain fetcher or indexer API | OPEN — separate chain from Cosmos CW-721 path |
| Polkadot + NEAR + Thorchain NFT support | Long-tail chain coverage | Same per-chain cost; lower expected demand | OPEN — wait for evidence of demand |

**Open subtotal:** Continuous indexing edge-case iteration is ongoing; Injective + long-tail chains remain genuinely open and demand-gated.

---

## Platform reach

Multi-month bets that change *who* and *where* the platform serves.

| Item | What it buys | What it costs | Status |
|---|---|---|---|
| Push notifications (§I1) | Bell delivery beyond email | VAPID + service worker + opt-in flow (per-event toggles already exist on backend) | SHIPPED — `PushDispatcher` + `PushPayload` + frontend `register.ts` / `usePushSubscription.ts` / `push-endpoints.ts`; per-event toggles on `/me/notification-prefs` via `NotificationPrefs::PUSH_TYPES`; 9-event V1 taxonomy complete (reaction / review / card_pulled / rank_up / endorse / welcome / mention / local_post / comment_received) |
| Public API for external consumers (§Q12.10) | External integrations + ecosystem | Rate limiting + auth + docs + versioning + support burden | OPEN — wait for explicit demand |
| Native mobile app (§J1) | Native gestures + push reliability | RN or native — months. PWA may cover most of the gap | OPEN — wait for engagement evidence |
| Localization / i18n | Non-English markets | Translation pipeline + RTL + locale routing + ongoing translation cost | OPEN — wait for non-English demand |

**Open subtotal:** All remaining items are V2-of-V2 (demand-gated). Push notifications landed.

---

## New product concepts

These aren't features — they're product directions. Need design + data-model work before any engineering.

| Item | What it buys | What it costs | Status |
|---|---|---|---|
| **Backer** concept (§N4) | Token-holding / governance / funding visibility layer on profiles | New product concept — design pass + data model + likely on-chain integrations | OPEN — needs product scoping before engineering |
| **Pack** concept (§N4) | Lootbox / gacha pull mechanic | Cut on tone grounds — pulls in mechanics that don't fit BCC's voice | WON'T SHIP |

---

## Already shipped — drop from the list

| Item | Where it landed |
|---|---|
| Per-event notification bell toggles (§I1) | V1.5 — `/me/notification-prefs` + `NotificationPrefsForm` |
| Email digest (§I1) | V1.5 — `DigestService` weekly cron + signed unsubscribe + admin manual trigger |
| Endorse → bell notification | V1.5 — `NotificationDispatcher::onEndorseAdded` |

These are listed only to confirm they no longer count against V2 scope.

---

## Recommendation for the next V2 chunk

The two originally-recommended first-V2 phases both landed (Push Notifications + NFT Scaling Phase 1 backend). The remaining buckets, ranked by leverage:

1. **Engagement polish — small bundle.** Pick 2–3 from the open Engagement Polish rows above (`/me/progression` standalone page, sound on Heavy celebrations, network percentile on others' profiles). Each is ~half a day to a couple of days; together they thicken the V1 loop without a flagship-sized commitment.
2. **NFT Scaling — edge-case iteration + Injective.** Continuous-indexing Phase 1 backend has shipped, but enrichment and indexer edge cases are still in active iteration. Closing those + landing Injective is the natural follow-on.
3. **Operationally / strategically gated.** Admin curation (§G4), native mobile (§J1), public API (§Q12.10), i18n, and the Backer concept (§N4) all need staffing, design, or demand evidence before engineering — leave parked.

**Pick if forced:** the small engagement-polish bundle. Each item plots directly onto the existing dopamine loop, none introduces a new product surface, and the bundle ships in days rather than weeks. The §P "default answer is no" discipline still applies — each polish row needs to clear the four green flags before it leaves the OPEN column.

---

## What this doc is not

It's not a build plan. Once a V2 phase is selected, that phase gets its own scope-frozen plan in `C:\Users\simon\.claude\plans\` mirroring how V1 was planned. This doc just keeps the deferred list honest.
