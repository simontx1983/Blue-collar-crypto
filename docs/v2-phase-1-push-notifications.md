# V2 Phase 1 — Push Notifications

**Status:** scope-frozen 2026-04-30. Do not start coding until this doc is acknowledged.
**Estimated build:** 1–2 weeks of focused work.
**Predecessor:** V1 + V1.5 ship complete. Bell + email digest infra already exists.
**Successor:** V2 Phase 2 — NFT Scaling (continuous indexing, ETH + SOL).

---

## Why this is Phase 1

Push notifications are not "feature parity with email." They are **the platform's first real attention loop**. This phase will surface, in production, which actions actually drive return visits — data that should shape what we choose to ingest in NFT Scaling.

Three concrete reasons it ships before NFT scaling:

1. **Closes a known V1 gap.** §I1 launched with email digest only. Real-time delivery is the missing leg.
2. **Fast feedback loop.** Engagement deltas are observable within days of launch, not after a 4-week ingestion buildout.
3. **Low coupling.** No new data model, no new ingestion infra, no new chain integrations. Reuses the existing `bcc_*` event bus + `NotificationDispatcher`.

If we jumped to NFT scaling first, we'd sink 3–4 weeks into pipelines for signals we haven't yet proven worth notifying. Push first answers *what's worth notifying* — then NFT scaling optimizes for it.

---

## Locked decisions

These mirror V1's decision-numbering pattern. Each is **scope-frozen** for this phase. Re-opening a decision requires displacing another.

### Event scope

**P1.A1. Four event types — locked.** The push event taxonomy is **strictly narrower than the bell**. Bell shows everything; push is "you really need to know this." More events = more pings = inbox fatigue = users disable push. Originally proposed as 5; mention dropped after DC2 confirmed it isn't modeled in the event bus today (see Dependency Check Results below).

**Locked event list:**

| Push event | Trigger | Hook source (confirmed in bcc-trust) |
|---|---|---|
| `bcc_push_review` | New review on a page the viewer owns | `bcc_review_published` ([PostsService.php:282](../../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/PostsService.php#L282)) |
| `bcc_push_endorse` | New endorsement on a page the viewer owns | `bcc_trust_endorsement_added` ([EndorsementService.php:383](../../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/EndorsementService.php#L383)) |
| `bcc_push_dispute_outcome` | A dispute the viewer reported reaches final adjudication | `bcc_disputes_email_reporter_result` async hook ([DisputeNotificationService.php:38](../../app/public/wp-content/plugins/bcc-trust/app/Domain/Disputes/Services/DisputeNotificationService.php#L38)) |
| `bcc_push_panelist_selected` | The viewer is selected as a juror for a new dispute panel | `bcc_disputes_notify_panelist` async hook ([DisputeNotificationService.php:20](../../app/public/wp-content/plugins/bcc-trust/app/Domain/Disputes/Services/DisputeNotificationService.php#L20)) |

**P1.A2. No source-side modeling work in this phase.** If a future event needs source-side work (mention parsing, panelist-as-juror outcome notifications beyond reporter), it ships in its own phase. The non-negotiable rule: *if it isn't already emitted in the bus, it doesn't ship in Phase 1.* Mention is the only event that hit this rule — added to [v2-roadmap.md](v2-roadmap.md) as a deferred item.

**P1.A3. No marketing notifications. No "we miss you" pings. No engagement nudges.** This phase ships *signal*, not noise. Engagement-loop notifications are V2 Phase 3+ if at all.

### Delivery scope

**P1.B1. Web push only.** Browser-native via VAPID + service worker. No native mobile (mobile is its own deferred V2 item).

**P1.B2. Email digest stays as the at-least-weekly fallback.** Users who don't enable push still get the existing weekly digest. We do **not** build "send immediate email if push fails" — that's a different feature and would double the dispatch surface.

**P1.B3. Bell continues to write for every event regardless of push state.** Push is a delivery channel layered on top of the existing notifications system; it does not replace or short-circuit `peepso_notifications`.

### User control

**P1.C1. Two toggles, period.**
- **Global on/off.** "Enable push notifications" — triggers the browser permission flow + VAPID subscription on enable, revokes on disable.
- **Per-type toggles.** One checkbox per shipped event type from P1.A1.

**P1.C2. No quiet hours, no per-page muting, no DND, no time-of-day controls.** Those are V2-of-V2.

**P1.C3. All event types default ON when push is first enabled.** Anti-noise rules (P1.E1–E5) carry the load — debounce + aggregation + tombstoning are tight enough that "all on" doesn't firehose the user. The reasoning beats asymmetric defaults: if high-value events (review, endorse) default OFF, new users may never see push working → no signal → no habit loop. First session must demonstrate the system *is* useful; users tune down from there.

If telemetry in week 1 shows >50% of users muting a specific type, flip *that* type to OFF by default in a hotfix and treat the mute rate as the dominant signal — see Success Metric.

### Backend shape

**P1.D1. No new "notification framework."** We extend what exists, we don't build a parallel system. Concretely:
- `NotificationDispatcher` (existing) gains a push side-effect call after the bell write.
- `NotificationPrefs` (existing) gains a `push` sub-object alongside `email_digest` and `bell`.
- New `PushDispatcher` service handles VAPID delivery — single class, ~200 LOC.
- New `bcc_push_subscriptions` table for VAPID endpoint storage (one row per device per user).

**P1.D2. Reuse Action Scheduler for queued pushes.** Per V1 §A3, subscribers run async via the WP cron / Action Scheduler queue. Push dispatch runs in that same queue — debounce + aggregation logic lives at the queue boundary, not in the dispatch call site.

**P1.D3. PHP web push library: `minishlink/web-push`.** Composer dep. Mature, maintained, RFC 8030 compliant. Add to `bcc-trust/composer.json`.

### Anti-noise rules

**P1.E1. Self-notification suppression.** Already enforced in `NotificationDispatcher::dispatch` — push inherits this for free.

**P1.E2. Per-(recipient, event_type) debounce window: 5 minutes.** Within the window, additional events of the same type for the same recipient defer instead of firing. This prevents the "3 reviews on Blacksmith Node in a minute = 3 phone buzzes" pattern.

**P1.E3. Aggregation on flush.** When the debounce window closes and ≥2 deferred events sit in the queue, they merge into one push:
- 1 event → "@simontx wrote a review on Blacksmith Node"
- 2+ events → "2 new reviews on Blacksmith Node"

**P1.E4. No per-event debounce override.** Dispute-outcome and panelist-selected are rare enough that debouncing them is harmless. One rule for all five event types — simpler to reason about, simpler to test.

**P1.E5. Permanent unsubscribe on browser-side decline.** If a push provider returns 410 Gone (subscription expired/revoked), we DELETE the subscription row immediately. No retry, no zombie state.

### Observability (no silent failures)

**P1.F1. Per-attempt outcome counters.** Every push dispatch attempt MUST be classified into exactly one of four outcome buckets, written to a counter that admin tools can read:
- `push_send_attempt` — total attempts (denominator for everything else)
- `push_send_success` — provider accepted (2xx)
- `push_send_410_tombstoned` — subscription gone, row deleted
- `push_send_other_failure` — anything else (network error, 5xx, malformed payload, etc.)

**P1.F2. Hourly UTC bucket pattern, mirroring `DisputeNotificationService`.** Same `wp_cache_incr` + transient-fallback pattern that the dispute notification system already uses for mail-failure metrics ([DisputeNotificationService.php:75](../../app/public/wp-content/plugins/bcc-trust/app/Domain/Disputes/Services/DisputeNotificationService.php#L75)). Reuse, don't invent.

**P1.F3. Admin surface — read-only counter view.** New tab in wp-admin under bcc-trust admin: "Push delivery stats" showing the four counters for the current and previous hour. No graphs, no time-series — single screen, last-2-hours snapshot. If the success rate drops below 80% within an hour, log a warning to the existing logger.

**P1.F4. Per-event-type breakdown captured.** Counters are keyed by `(outcome, event_type)` so we can see "review pushes are 95% successful but dispute_outcome pushes are 40% successful — something is wrong with that payload builder specifically."

Without this instrumentation we'll *think* push is working when it isn't. Push systems fail silently in many ways (expired subscriptions, browser permission revokes, payload size limits, provider quirks). P1.F1–F4 is the floor on "you actually know what's happening."

---

## Dependency check results (run 2026-04-30, locked)

These were run before scope-freeze. Results gate Phase 1's actual event list:

| Check | Result | Action |
|---|---|---|
| **DC1 — Dispute outcome event** | ✅ EXISTS — `bcc_dispute_status_changed` fires from DisputeRepository on status transitions; `bcc_disputes_email_reporter_result` async hook fires on resolution | INCLUDED — push subscribes to `bcc_disputes_email_reporter_result` (same hook the existing email path uses, per [DisputeNotificationService.php:38](../../app/public/wp-content/plugins/bcc-trust/app/Domain/Disputes/Services/DisputeNotificationService.php#L38)) |
| **DC2 — Mention modeling** | ❌ DOES NOT EXIST — `NotificationDispatcher.php:33` explicitly comments "Deferred (per §P parking lot): @mentions (composer v2)"; no `MentionService`, no `bcc_mention_*` events | DROPPED — captured for V2 Phase 2+ |
| **DC3 — Panelist selection event** | ✅ EXISTS — `bcc_disputes_notify_panelist` async hook ([DisputeNotificationService.php:20](../../app/public/wp-content/plugins/bcc-trust/app/Domain/Disputes/Services/DisputeNotificationService.php#L20)) already triggers email; push subscribes alongside | INCLUDED |

**Net Phase 1 event scope:** 4 of 5 originally-proposed events (review + endorse + dispute_outcome + panelist_selected). Mention drops.

**Architectural bonus:** Three of the four hooks (`bcc_review_published`, `bcc_disputes_email_reporter_result`, `bcc_disputes_notify_panelist`) already have an async-dispatch pattern via `BCC\Core\Cron\AsyncDispatcher`. The push handlers can use the same pattern — no new queue infra needed.

---

## Build sequence

Four sub-phases, each independently shippable.

### Sub-phase 1.1 — Foundation (backend)

**Goal:** subscriptions can be registered + stored; prefs schema extended; no actual pushes yet.

**Files to add:**
- `bcc-trust/app/Domain/Core/Database/Schema/PushSubscriptionsSchema.php` — `bcc_push_subscriptions` table (id, user_id, endpoint, p256dh_key, auth_key, user_agent, created_at, last_used_at). UNIQUE on `(user_id, endpoint)`.
- `bcc-trust/app/Domain/Core/Repositories/PushSubscriptionRepository.php` — repository-only DB access per §L6.
- `bcc-trust/app/Domain/Core/REST/MyPushSubscriptionEndpoint.php` — POST `/me/push-subscriptions` (register), DELETE `/me/push-subscriptions/:id` (revoke), GET `/me/push-subscriptions/vapid-public-key`.

**Files to modify:**
- `bcc-trust/app/Domain/Core/Support/NotificationPrefs.php` — extend schema with `push: { enabled, events: { ... } }` sub-object. Add `isPushEnabled(int $userId, string $eventType): bool`.
- `bcc-trust/app/Domain/Core/REST/MyNotificationPrefsEndpoint.php` — accept `push` in PATCH body.
- `bcc-trust/composer.json` — add `minishlink/web-push`.

**Config:**
- `wp-config.php` (or env-loader pattern) — `BCC_PUSH_VAPID_PUBLIC_KEY`, `BCC_PUSH_VAPID_PRIVATE_KEY`, `BCC_PUSH_VAPID_SUBJECT` (mailto: or origin URL).
- One-time WP-CLI script to generate the keys: `wp bcc-trust push:generate-vapid`.

**Verify:** subscription registration round-trips; PATCH on prefs persists `push.enabled = true`. PHPStan clean. No actual push fires yet.

### Sub-phase 1.2 — Service worker + permission flow (frontend)

**Goal:** browser asks for permission, registers subscription, shows status in settings UI.

**Files to add:**
- `bcc-frontend/public/sw.js` — minimal service worker. Listens for `push` events (no-op stub for now; renders in 1.4), `notificationclick` events (open URL, focus tab), `pushsubscriptionchange` (re-register).
- `bcc-frontend/src/lib/push/register.ts` — `registerPush()`, `unregisterPush()`, `getPushSubscription()`. Wraps the Service Worker API + `PushManager.subscribe`.
- `bcc-frontend/src/lib/api/push-endpoints.ts` — `getVapidPublicKey()`, `registerPushSubscription()`, `revokePushSubscription()`.
- `bcc-frontend/src/hooks/usePushSubscription.ts` — React Query hook exposing { isEnabled, isSupported, enable, disable }.

**Files to modify:**
- `bcc-frontend/src/components/settings/NotificationPrefsForm.tsx` — add a third section "Push notifications" with the master toggle + per-type checkboxes. Re-uses `<ToggleRow>`. Toggle interactions call `enable()`/`disable()` from the hook.
- `bcc-frontend/src/lib/api/types.ts` — extend `NotificationPrefs` with `push` sub-object matching backend.

**Verify:** turning on the master toggle prompts for browser permission, subscription appears in DB, status persists across reloads. Turning off revokes server-side. Per-type checkboxes save without re-prompting permission.

### Sub-phase 1.3 — PushDispatcher + queue + anti-noise + observability (backend)

**Goal:** events emit → queue debounces → flush sends real pushes for all 4 event types → all four outcomes counted.

**Files to add:**
- `bcc-trust/app/Domain/Core/Services/PushDispatcher.php` — single class. Methods:
  - `enqueue(int $recipientId, string $pushEventType, array $payload): void` — writes to a transient/options-backed deferred queue keyed by `(recipient_id, event_type)`. Schedules `bcc_trust_push_flush` via `BCC\Core\Cron\AsyncDispatcher` (same async pattern as `bcc_disputes_notify_panelist`) at debounce-window expiry.
  - `flush(int $recipientId, string $pushEventType): void` — pops the queue, builds aggregated payload, sends via `minishlink/web-push` to all of the recipient's active subscriptions. Increments P1.F counters per attempt outcome.
  - Failure handling: 410 Gone → `PushSubscriptionRepository::delete()` + `push_send_410_tombstoned` counter. Other errors → log + `push_send_other_failure` counter + retry once via Action Scheduler.
- `bcc-trust/app/Domain/Core/Support/PushPayload.php` — payload builder. Title + body + icon + URL. Knows how to format single-event vs aggregated bodies (P1.E3) per event type. One method per event type: `forReview()`, `forEndorse()`, `forDisputeOutcome()`, `forPanelistSelected()`.
- `bcc-trust/app/Domain/Core/Support/PushMetrics.php` — counter helpers mirroring `DisputeNotificationService::recordMailFailure()`. Methods: `recordAttempt(string $eventType): void`, `recordSuccess(string $eventType): void`, `recordTombstone(string $eventType): void`, `recordFailure(string $eventType, string $reason): void`. Plus readers for the admin tab.

**Files to modify:**
- `bcc-trust/app/Domain/Core/Services/NotificationDispatcher.php` — after each successful bell write, call `PushDispatcher::enqueue()` if `NotificationPrefs::isPushEnabled($recipientId, $pushEventType) === true`. Add the call inside `onReviewAdded` and `onEndorseAdded` (existing handlers).
- `bcc-trust/bcc-trust.php` — register `bcc_trust_push_flush` Action Scheduler hook. Subscribe `PushDispatcher::onDisputeReporterResult` to `bcc_disputes_email_reporter_result` (alongside the existing email handler — both run, neither blocks the other). Subscribe `PushDispatcher::onPanelistNotify` to `bcc_disputes_notify_panelist` similarly.
- `bcc-trust/app/Domain/Core/Admin/AdminMenu.php` (or wherever the admin tabs are wired) — add "Push delivery stats" tab rendering the P1.F3 counter snapshot.

**Verify:**
- Rapid-fire 3 reviews within 5 minutes → exactly one push lands, body says "3 new reviews on Blacksmith Node."
- Flushes for different (recipient, type) pairs don't collide.
- 410 Gone tombstones dead subscriptions on first failure; counter increments.
- Trigger a dispute resolution → push fires to the reporter; existing email still fires too (additive, not replacing).
- Admin tab shows non-zero attempt + success counts after smoke flow.

### Sub-phase 1.4 — Service worker render + click handling (frontend)

**Goal:** real push payload shows a real notification with a real click target.

**Files to modify:**
- `bcc-frontend/public/sw.js` — `push` handler parses payload, calls `self.registration.showNotification(title, { body, icon, badge, data: { url } })`. `notificationclick` opens `data.url` (focus existing tab or open new).

**Out of scope for 1.4:**
- Notification grouping in OS shell (browser-native; no work needed)
- Sound / vibration (use OS defaults)
- Action buttons (e.g., "Mark read" inline) — V2-of-V2

**Verify:** end-to-end smoke: trigger a review on a test page, recipient's browser shows the notification, clicking it lands them on the right URL.

---

## Acceptance criteria

A copy of this list lives in the smoke test for Phase 1; it must be walked end-to-end before merge:

1. New user logs in → settings page shows "Push notifications" section with master toggle OFF.
2. Toggle ON → browser permission prompt → subscription registered in DB.
3. Per-type toggles **all default ON** when push is first enabled (P1.C3).
4. Trigger a review on user's page → exactly one push notification within ~5 minutes.
5. Trigger 3 reviews within 5 minutes → exactly one aggregated push ("3 new reviews on …").
6. Trigger an endorse → push body says endorsement; endorser handle + page name render correctly.
7. Trigger a dispute resolution where viewer was the reporter → push body says outcome; existing email also still fires.
8. Trigger a panelist selection → push body says "you've been selected for dispute panel #N"; existing email also still fires.
9. Disable per-type toggle for `bcc_push_review` → next review writes to bell but does NOT push.
10. Disable master toggle → server-side subscription deleted; subsequent events do not push.
11. Browser revokes permission externally → next push attempt returns 410 → subscription tombstoned + `push_send_410_tombstoned` counter increments.
12. Click notification → tab opens (or focuses) to the correct URL (page profile, dispute detail, etc.).
13. After full smoke flow, admin "Push delivery stats" tab shows non-zero `push_send_attempt` + `push_send_success` per event type (P1.F).
14. PHPStan level 8 clean across all touched files.
15. tsc clean across the frontend.

---

## What this phase is NOT

To prevent scope drift, the following are explicitly OUT for Phase 1:

- Native mobile push (different transport, different infra)
- iOS Safari push (Safari has its own quirks; cover in V2-of-V2 if web push proves out)
- Per-event quiet hours / time-of-day controls
- Per-page muting ("don't push me about Blacksmith Node specifically")
- Push-only digest mode ("send me a push instead of an email digest")
- Action buttons in notifications (Mark read / Reply inline)
- Notification grouping logic beyond OS-default behavior
- Mention modeling (if DC2 fails, mention drops out — we do not build mention infra here)
- Dispute-outcome event modeling (if DC1 fails, drops out — same rule)
- Marketing / engagement / "we miss you" pings — full stop

If anyone proposes adding one of these mid-phase, the answer is **V2 Phase 2+** per §P2 ("adding requires removing").

---

## What we'll learn (the strategic point)

Phase 1 is also instrumentation for Phase 2. After 2–4 weeks in production, we'll have data on:

- **Which event types drive return visits.** Per-type click-through rate from push → tab focus → session length.
- **Which event types get muted.** Per-type opt-out rates after first push enable.
- **Which delivery times correlate with engagement.** Time-of-day patterns in clicks (informs eventual quiet hours design).
- **Aggregation effectiveness.** Did the 5-min debounce reduce notification fatigue, or did users still mute review pushes?

These four signals **directly inform NFT Scaling Phase 2**: they tell us which on-chain signals are worth indexing in real-time vs. which can stay on-demand. If users mute review-push, the lesson is *"high-frequency events are noise even when actionable"* → continuous mint indexing should aggressively aggregate or stay batched.

This is why Phase 1 ships first.

---

## Success metric

Push notifications alone don't control retention strongly enough to make absolute retention a useful gate — too many external variables. Phase success is measured on **engagement signals** plus a **delta retention** comparison, not absolute retention.

**Framing — the one-line success test:**

> Push succeeds if it drives measurable return sessions without increasing the disable rate.

That's actionable. Either of those signals failing means the phase needs iteration.

**Primary metrics (gate phase success):**
- **Opt-in rate** — % of logged-in users who enable push when shown the toggle. Healthy = >25%; <10% = the prompt UX or the value prop is wrong.
- **Notification-to-session rate** — % of delivered pushes that lead to a session (tab focus or new pageview) within 24h. Healthy = >15%; <5% = pushes are noise users dismiss.

**Secondary metrics (track + diagnose):**
- **Notifications per user per day** — should stay low, ideally <3 average. If it climbs above 5, debounce + aggregation are leaking.
- **Disable rate** — % of users who turn push back off within 7 days of enabling. Healthy = <15%; >30% = events are firing too often or are low-signal.
- **Per-type mute rate** — same metric scoped per event type. Identifies which specific event types are noise (informs the P1.C3 hotfix path).

**Retention (delta, not absolute):**
- Compare **14-day retention of users who enabled push** vs. a matched cohort of users who saw the toggle but didn't enable. A positive delta of any size is success; the absolute number is meaningless.

**Failure modes to watch:**
- Disable rate >30% in week 1 → events firing too often or wrong types prioritized → flip the offending type's default to OFF (P1.C3 hotfix).
- Notification-to-session rate <5% across the board → push payloads aren't compelling enough; review titles/bodies before adding more event types.
- Opt-in rate <10% → the master-toggle UX or the value pitch in settings is wrong, not the events.

Each of these is observable from the P1.F observability counters + standard session telemetry. No new metric pipeline required.

---

## Plan is closed

This doc is **scope-frozen 2026-04-30**. Decisions P1.A1–P1.E5 are the inputs for the build. Sub-phases 1.1–1.4 are the build path. Anything not on this list is V2 Phase 2+.

Default response to mid-phase scope additions: **"V2 Phase 2 — opening a deferred-list entry."**
