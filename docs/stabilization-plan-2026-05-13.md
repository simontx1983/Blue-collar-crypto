# Post-Audit Consolidation: Mobile / Public-Client Stabilization Plan

**Date:** 2026-05-13 (post commit `1f6e8f0`)
**Companion documents:** `docs/GOLDEN_PATHS.md` (runbook), `docs/operational-audit-2026-05-13.md` (audit snapshot)

**Scope:** Surgical, additive stabilization. Five focus areas. No speculative rewrites, no framework migrations, no future-platform abstractions. Preserve existing doctrine and API envelope rules.

**Goal:** Smallest possible set of changes that materially increase survivability for external/public clients (mobile + public API).

> **STATUS (2026-06-12): ALL MUST-FIX ITEMS SHIPPED.** Phase α (Item 1)
> landed as the `isAlreadyEnveloped()` legacy-shape recognition in
> `Envelope.php`, locked by `tests/EnvelopeRecognitionTest.php`. Phase β
> (Items 2–4) shipped 2026-05-13. Phase γ (Items 5 + 8) shipped
> 2026-05-13 (`lib/api/errors.ts` + `error-contract-guard.sh` +
> contract error-code enumeration). Only the Phase δ acceptable-debt
> items (6, 7, V-07 namespace collapse, table-prefix rename) remain
> deferred-by-design. This document is retained as the rationale
> record — nothing below is open work.

---

## TL;DR — Priority order

| # | Item | Category | Risk | Blast radius |
|---|---|---|---|---|
| 1 | ✅ DONE — **Envelope mismatch on `/bcc-trust/v1/*`** — fixing 13 currently-broken endpoints | **MUST-FIX BEFORE BETA** | CONTRACT (live bug) | 1 PHP file, 2 lines |
| 2 | ✅ DONE — **`MyNotificationPrefsEndpoint` adds `push_available`** | MUST-FIX BEFORE MOBILE | UX (cold-start failure) | 1 PHP file + 1 TS hook |
| 3 | ✅ DONE — **JWT silent-refresh via existing `/auth/token` bridge** | MUST-FIX BEFORE MOBILE | UX (data loss in composer drafts) | 1 client.ts retry path |
| 4 | ✅ DONE — **Permission-block null-safety sweep** (16 sites) | MUST-FIX BEFORE MOBILE | ARCHITECTURAL (contract softening crashes) | 9 frontend files, 1-line edits each |
| 5 | ✅ DONE — **Error-contract `.message` → `.code` normalization** (8 HIGH sites) | MUST-FIX BEFORE PUBLIC API | CONTRACT (localization breakage) | 8 frontend files, structured |
| 6 | Centralize `ERROR_COPY` map across 19 components | Acceptable debt | UX (cosmetic drift) | Deferred until i18n lands |
| 7 | Replace 3 MEDIUM HTTP-status-based branches | Acceptable debt | CONTRACT (low-likelihood drift) | 2 frontend files |
| 8 | ✅ DONE — Per-endpoint error-code enumeration in `api-contract-v1.md` | MUST-FIX BEFORE PUBLIC API | CONTRACT (docs gap) | Documentation only |

---

## Item 1 — Dual REST namespace drift

### What's actually broken (live, today)

The Explore inventory found **13 endpoints currently shape-mismatched** between handler and frontend client. Empirical confirmation: the `/settings/account` page renders "Status unavailable" for both X and GitHub OAuth status today (visible in Playwright snapshot taken 2026-05-13). That's the user-visible manifestation.

**Mechanism (verified via code read):**

1. `TrustRestController::success(array $data)` at `app/Domain/Core/Controllers/TrustRestController.php:719-724` returns `{success: true, data}` (handler-level envelope).
2. `XController` + `GitHubController` return raw `new WP_REST_Response(['success' => true, 'data' => ...])` (line 116 of XController.php and similar across both).
3. `Envelope.php::wrap` (priority 999 on `rest_post_dispatch`) checks `isAlreadyEnveloped` for either `{data, _meta}` OR `{error: {code,message,status}}` — neither matches.
4. Envelope WRAPS the response. Result on the wire: `{data: {success: true, data: <real>}, _meta: {...}}`.
5. Frontend's `bccTrustFetch` parser (`bcc-trust-client.ts:127-131`) checks `v["success"] === true` at TOP level. Top level is `{data, _meta}`. `v["success"]` is undefined. Parser throws `bcc_invalid_envelope`.

**Live-bug endpoint list:**

| Path | Handler | Frontend caller |
|---|---|---|
| `POST /bcc-trust/v1/endorse` | TrustRestController.php:99 | `endorse-endpoints.ts → bccTrustFetch` |
| `POST /bcc-trust/v1/revoke-endorsement` | TrustRestController.php:109 | `endorse-endpoints.ts → bccTrustFetch` |
| `POST /bcc-trust/v1/device-fingerprint` | TrustRestController.php:135 | `fingerprint-endpoints.ts → bccTrustFetch` |
| `GET /bcc-trust/v1/x/{auth,callback,status,disconnect,verify-share}` | XController.php:36-60 | `oauth-endpoints.ts → bccTrustFetch` |
| `GET /bcc-trust/v1/github/{auth,callback,status,disconnect,refresh}` | GitHubController.php:36-60 | `oauth-endpoints.ts → bccTrustFetch` |

13 endpoints. The endorse path is the highest-priority because §6 of GOLDEN_PATHS.md (rate-limit verification) exercises the endorse mutation; the live failure currently presents as "fingerprint reporter swallowed the error" rather than a user-visible crash.

### Surgical fix (additive, ~2 lines)

**Option A — Server-side, preferred.** Teach `Envelope.php::isAlreadyEnveloped` to recognize the trust-shape. One additional case:

```php
// app/Domain/Core/REST/Envelope.php — after line 107
// Trust-success envelope marker (legacy bcc-trust/v1 shape):
// {success: true, data: ...}. Recognize so we don't double-wrap.
if (
    array_key_exists('success', $data)
    && $data['success'] === true
    && array_key_exists('data', $data)
    && !array_key_exists('_meta', $data)
) {
    return true;
}
```

**Effect:** `{success: true, data}` survives unwrapped on the wire. `bccTrustFetch`'s `isTrustEnvelope` matches it. 13 endpoints work. Zero changes to handlers, zero changes to the frontend.

**Option B — Frontend-side, fallback.** Make `bccTrustFetch::isTrustEnvelope` accept EITHER `{success: true, data}` OR `{data, _meta}` and pluck `parsed.data.data` when it detects the double-wrap. This is more defensive but introduces a magic shape detector in the parser.

**Recommendation:** Option A. Smaller, single-source change, doesn't introduce dual-parsing logic.

### Risk dimensions

- **ARCHITECTURAL RISK** — Today: high (the dual-namespace shim is shipping a real bug). After Option A: low (the shim continues but is no longer broken).
- **UX RISK** — Today: medium (OAuth verification renders "Status unavailable" silently). After: low.
- **OPERATIONAL RISK** — Today: low (failures are caught and surface as visible UI degradation). After: zero.
- **CONTRACT RISK** — Today: high (the contract is documented one way and shipped another). After: low (additive recognition of an existing shape, no contract document change required).

### What this does NOT do

- Does NOT consolidate the two namespaces (V-07 in the audit doc — long-term project).
- Does NOT remove `bccTrustFetch` or merge the two HTTP clients.
- Does NOT change any handler.
- Does NOT change anything documented in `docs/api-contract-v1.md` — the contract simply starts working.

### Verification (after the patch)

GOLDEN_PATHS.md Section 14.3 (Envelope smoke) gains a sibling check:

```js
// In browser devtools after sign-in:
const s = await (await fetch('/api/auth/session', { credentials: 'include' })).json();
const r = await fetch('http://blue-collar-crypto-custom.local/wp-json/bcc-trust/v1/x/status', {
  headers: { Authorization: `Bearer ${s.bccToken}` },
});
const body = await r.json();
console.log(body);
```

**Expected after fix:** `{success: true, data: {...}}` (no nested `data.success`). UI should now render the actual GitHub/X connection status instead of "Status unavailable."

### Estimated effort

PHP: 5 lines + comment. Verification: 10 minutes via the GOLDEN_PATHS recipe. `arch-guardrails.sh --with-contract` should pass unchanged.

### Bucket: **MUST-FIX BEFORE BETA**

This is a live bug today, currently presenting silently. Beta users may notice "Status unavailable" without reporting. Public-client behavior is undefined — a mobile app calling `/x/status` would receive an `bcc_invalid_envelope` it has no recipe to interpret.

---

## Item 2 — Notification capability contract

### What's missing

`/bcc/v1/me/notification-prefs` returns the prefs tree but says nothing about whether the server is capable of delivering push at all. `NotificationPrefsForm.tsx:285-336` renders the "Enable push" toggle based purely on browser support. When the user clicks Enable, `usePushSubscription.ts:88-120` calls `getVapidPublicKey()`, gets a 503 `bcc_push_not_configured` for instances without VAPID keys, and surfaces the error AFTER the user has already engaged.

For a web SPA this is annoying-but-recoverable. For mobile, this is a cold-start papercut on every new instance.

### Surgical fix (additive)

Extend the response shape — additive, backward-compatible:

```php
// In NotificationPrefs::readAll($userId):
return [
    'email_digest' => ...,
    'bell'         => [...],
    'push'         => [
        'enabled' => $enabled,
        'events'  => $events,
    ],
    // NEW: server capability flag. False when VAPID keys aren't configured;
    // true when getVapidPublicKey() would succeed. Frontends should
    // conditional-render the push-enable CTA on this.
    'push_available' => self::pushAvailable(),
];

private static function pushAvailable(): bool
{
    // Mirrors the same check getVapidPublicKey() makes server-side.
    return defined('BCC_VAPID_PUBLIC_KEY') && BCC_VAPID_PUBLIC_KEY !== '';
}
```

Frontend addition:

```ts
// usePushSubscription.ts and NotificationPrefsForm.tsx:
// Gate the Enable Push UI on serverPushAvailable AND browserPushAvailable.
const canEnable = prefs.push_available === true && isPushSupported;
```

### Backward compatibility

`push_available` is a new optional field. Older mobile clients that don't read it fall through to the existing browser-support check — same behavior as today. Newer clients gate properly. No contract break.

### Risk dimensions

- **ARCHITECTURAL RISK** — low. Pure additive.
- **UX RISK** — Today: medium for mobile cold-start. After: low.
- **OPERATIONAL RISK** — zero either way.
- **CONTRACT RISK** — Add a line to `docs/api-contract-v1.md §I1` documenting `push_available` so future agents don't think it's optional-vestigial.

### Bucket: **MUST-FIX BEFORE MOBILE**

Cold-start failure is acceptable on a web SPA where users can refresh; not acceptable on a mobile cold start. Web survivability is fine.

### Estimated effort

PHP: ~10 lines (constant check + response extension + readAll caller). Frontend: ~5 lines in two files. Contract doc: 1 line. ~1 hour total.

---

## Item 3 — JWT survivability for mobile

### Current state (verified)

- TTL: `JwtToken::TTL_SECONDS = 604800` (7 days).
- No `/auth/refresh` endpoint. `/auth/token` exists but requires a valid WP cookie session to mint a JWT — not callable from a mobile client whose only credential is the JWT itself.
- NextAuth `jwt` callback (`bcc-frontend/src/lib/auth.ts:215-220`) blanks `token.bccToken` when `Date.now() >= bccTokenExpiresAt`.
- `bccFetchAsClient` (`bcc-frontend/src/lib/api/client.ts:221-228`) defensively `signOut`s if `session.bccTokenExpiresAt` is past.
- `bccFetchAsClient` 401-on-real-request safety net (`client.ts:241-244`) signs out.

The 3-layer chain is intentional V1 design (locked per `AuthEndpoint::JWT_TTL_SECONDS` docblock) — works on web because the user re-logs in once a week, no big deal.

### Mobile failure modes (simulated)

| Scenario | Today's behavior | Severity (mobile) |
|---|---|---|
| App suspended for 8 days; user returns | Every request 401s; client.ts signOuts; user faces login screen with no draft loss because no draft was in flight. | Medium |
| Offline wake (cached React Query data) | First online write fails 401; signOut. Cached reads remain visible briefly, then the next refetch fails and the UI degrades. | Medium |
| **Stale react-query cache that mutates** | Optimistic mutation fires with stale bearer; server rejects with 401; cache rolls back; signOut. **User sees their attempted action disappear AND the app suddenly demands re-login.** | High |
| **Expired auth mid-composer-draft** | User composes a long post over 7+ days. Submit fails 401. Mutation rolls back. SignOut. **The draft text is lost because composer state is local to the unmounted component.** | High |
| OAuth-style background refresh | Currently impossible — there's no refresh path. | (precondition) |

### Surgical fix (minimum viable)

**Add a `/auth/refresh` endpoint** (NOT `/auth/token` — that requires the cookie). Bearer-JWT-in, fresh-JWT-out, with two conservative gates:

1. Accept JWTs that are within `BCC_REFRESH_GRACE_DAYS = 1` of expiry OR already-expired by ≤ `BCC_REFRESH_GRACE_DAYS`. Refuse anything older.
2. Validate the user is not suspended (Permissions::is_not_suspended()). If suspended, return 403 (forces re-login through the suspension path).

```php
// AuthEndpoint::refresh()
public function refresh(WP_REST_Request $request): WP_REST_Response
{
    $token = self::extractBearer($request);
    if ($token === null) {
        return ApiResponse::error('bcc_unauthorized', 'Bearer token required.', 401);
    }

    // Decode allowing up to BCC_REFRESH_GRACE_DAYS of post-expiry.
    $payload = JwtToken::decodeWithGrace($token, BCC_REFRESH_GRACE_DAYS * DAY_IN_SECONDS);
    if ($payload === null) {
        return ApiResponse::error('bcc_unauthorized', 'Token cannot be refreshed.', 401);
    }

    $userId = (int) ($payload['user_id'] ?? 0);
    if (!Permissions::is_not_suspended($userId)) {
        return ApiResponse::error('bcc_forbidden', 'Account suspended.', 403);
    }

    $newToken = JwtToken::encode($userId, (string) $payload['handle']);
    return ApiResponse::ok([
        'token'      => $newToken,
        'expires_in' => JwtToken::TTL_SECONDS,
        'token_type' => 'Bearer',
    ]);
}
```

**Frontend silent-refresh in `client.ts`:**

```ts
// Pre-emptive: if token is within 24h of expiry, refresh BEFORE the fetch.
// Reactive: on 401 with hadToken, attempt ONE refresh-then-retry before signOut.
```

Concretely: extend `bccFetchAsClient` so on 401 it:
1. Calls `POST /bcc/v1/auth/refresh` with the (just-expired) bearer.
2. If success → updates the NextAuth session with the new token, retries the original request once.
3. If failure → existing signOut path.

That's the entire mobile refresh story. Two files (one new PHP endpoint, one client.ts patch). No rotating refresh tokens, no new schema, no NextAuth callback refactor.

### Backward compatibility

The endpoint is additive. The frontend retry-on-401 path is also additive — old clients that don't call `/auth/refresh` still get the signOut behavior.

### Composer draft survival

This is the worst case (data loss). Defense in depth:

1. JWT refresh (above) — closes the 401-causes-draft-loss window for tokens within grace.
2. Composer draft persistence (separate concern) — add `localStorage`-backed draft state to `Composer.tsx`. Outside the scope of this plan; flag as related follow-up.

### Risk dimensions

- **ARCHITECTURAL RISK** — low. Additive endpoint, no schema change. The session-bridge endpoint (`/auth/token`) and the refresh endpoint coexist cleanly — token bridges WP→JWT, refresh extends JWT→JWT.
- **UX RISK** — Today: high for mobile mid-flight mutations. After: low (one silent refresh on background-wake).
- **OPERATIONAL RISK** — low. The grace window introduces a small replay surface — a stolen JWT can be refreshed up to N days after capture. Mitigation: short grace window (1 day default), require non-suspended check on every refresh.
- **CONTRACT RISK** — low. New endpoint documented in `api-contract-v1.md §B`; old clients ignore.

### Bucket: **MUST-FIX BEFORE MOBILE**

Web SPA users can re-login weekly without major UX cost. Mobile users cannot.

### Estimated effort

PHP: ~30 lines + JwtToken::decodeWithGrace addition (~20 lines) + activation constant. Frontend: ~40 lines in client.ts. Tests: smoke per GOLDEN_PATHS.md (refresh happy path + grace window + suspended user reject). ~4 hours.

---

## Item 4 — Permission-block null-safety sweep

### Inventory (verified)

16 MOBILE-RISK accesses across 9 components. 0 CRASH-RISK today (because TypeScript types pin every field as required). The risk lives in the gap between "types say required" and "API may soften."

| File:line | Expression | Today | After mobile-client drift |
|---|---|---|---|
| `BlockToggle.tsx:27` | `profile.permissions.can_block.allowed` | safe (types pinned) | crash if backend omits `can_block` |
| `CommentDrawer.tsx:131` | `comment.permissions.can_delete.allowed` | safe | crash |
| `CardFactory.tsx:476,478,482,510,512,514` | `card.permissions.can_pull.*` + `can_review.*` | safe | crash (6 sites) |
| `GroupFeedSection.tsx:47` | `group.permissions.can_read_feed.unlock_hint` | safe | crash |
| `EntityProfile.tsx:233,246,259` | `card.permissions.{can_review,can_endorse,can_dispute}.allowed` | safe | crash (3 sites) |
| `GroupMembershipStrip.tsx:106,111,116` | `permissions.{can_leave,can_join}.*` | safe | crash (3 sites) |
| `GroupsPanel.tsx:166,169,172` | `permissions.{can_leave,can_join}.*` | safe | crash (3 sites) |
| `ReportButton.tsx:34` | `item.permissions["can_report"]?.allowed === true` | SAFE | SAFE (optional chained, bracket notation) |

### Surgical fix

Each site converts from `permissions.X.Y` to `permissions?.X?.Y`. The TypeScript inference is unaffected (optional-chaining doesn't change types); React render simply degrades gracefully if a field is ever omitted instead of crashing.

For boolean fields with semantic default, fall through to `false`:

```ts
// Before:
if (profile.permissions.can_block.allowed) { ... }

// After:
if (profile.permissions?.can_block?.allowed === true) { ... }
```

For nullable string fields (`unlock_hint`), the same pattern:

```ts
// Before:
{permissions.can_join.unlock_hint !== null && <Hint>{permissions.can_join.unlock_hint}</Hint>}

// After:
{permissions?.can_join?.unlock_hint != null && <Hint>{permissions.can_join.unlock_hint}</Hint>}
```

### Canonical accessor (optional)

A small `lib/permissions.ts` helper would deduplicate:

```ts
export function isAllowed(
  permissions: Record<string, { allowed: boolean } | undefined> | undefined,
  capability: string,
): boolean {
  return permissions?.[capability]?.allowed === true;
}
```

This is the only abstraction worth adding — it's narrow, names a real concept, and removes the per-site optional-chain repetition. Use it OR skip it; if used, replace direct access with `isAllowed(group.permissions, "can_join")`.

### Backward compatibility

Zero contract change. Pure frontend defensive coding.

### Risk dimensions

- **ARCHITECTURAL RISK** — Today: medium (contract softening crashes). After: low.
- **UX RISK** — Today: nil for current contract; future risk on contract drift. After: degraded UI (button missing) instead of crash.
- **OPERATIONAL RISK** — zero either way.
- **CONTRACT RISK** — zero either way (additive defense).

### Bucket: **MUST-FIX BEFORE MOBILE**

A web SPA forces all clients to ship with the latest types.ts. Mobile cannot — older app versions on the App Store + a newer backend = contract drift surface. Defense is the only mitigation that survives.

### Estimated effort

16 sites × ~1 minute each + 1 helper file = ~30 minutes. Plus tsc + smoke pass.

---

## Item 5 — Error contract normalization

### Findings (verified)

**HIGH severity (8 sites — branches on `err.message` text):**

| File:line | Expression | What it tries to know | Should branch on |
|---|---|---|---|
| `NotificationPrefsForm.tsx:498` | `err.message.includes("permission") \|\| err.message.includes("denied")` | "browser blocked notification permission" | NEW code `bcc_browser_notification_blocked` (or detect client-side from `Notification.permission`) |
| `app/messages/new/page.tsx:72` | `err.message !== "" ? err.message : default` | "any send error" | switch on `err.code` first |
| `MessageComposer.tsx:31` | same | "any send error" | switch on `err.code` first |
| `ConnectionsSection.tsx:366` | same | "any provider connection error" | switch on `err.code` first |
| `WalletsSection.tsx:476` (unlink default) | `err.message !== "" ? err.message : default` in default-case fallthrough | "unknown unlink error" | add explicit code cases; remove text fallback |
| `WalletsSection.tsx:514` (link default) | same | "unknown link error" | same |
| `EndorseButton.tsx:176` (default) | same | "unknown endorse error" | same |
| `CommentDrawer.tsx:241` | `error.message !== "" ? error.message : "(${error.code})"` | BACKWARD: shows message first, code second | swap: prefer code with humanizer; never display raw message in production text |

**MEDIUM severity (3 sites — HTTP status-based branches):**

| File:line | Expression | Acceptable today? |
|---|---|---|
| `EndorseButton.tsx:167` | `if (err.status === 400 && err.message !== "")` | Today: works. Mobile/i18n: fragile. |
| `NftPickerModal.tsx:398` | `if (err.status === 403)` for "NFT not in linked wallets" | Today: only 403 the endpoint emits. Fragile if a second 403 case is added. |
| `NftPickerModal.tsx:401` | `if (err.status === 429)` | Duplicates `bcc_rate_limited` — accept one, retire the other. |

### Surgical fix per site

1. **NotificationPrefsForm.tsx:498** — Replace with client-side `Notification.permission === "denied"` check (browser API; not backend). The previous string-includes is detecting a browser concept via a server message.

2. **app/messages/new/page.tsx:72, MessageComposer.tsx:31, ConnectionsSection.tsx:366** — Add a `humanizeError(err: BccApiError)` switch with code cases for the codes each endpoint actually emits. Fall through to a static "Couldn't send message. Try again." when code is unmapped.

3. **WalletsSection.tsx default cases, EndorseButton.tsx:176, CommentDrawer.tsx:241** — Remove the `err.message` fallback. Use a static "Something went wrong. Try again." or "(error: {code})" if code reveal is helpful for debug.

4. **EndorseButton.tsx:167 (MEDIUM)** — Document the 400+message assumption in code OR replace with explicit error codes (`bcc_quest_not_complete`, `bcc_age_too_young`, etc.) emitted by the endorse controller. Lower-priority because the 400 codes are narrow today.

5. **NftPickerModal.tsx:398,401** — Replace status checks with code-based: `err.code === "bcc_wallet_not_linked"` (or whatever the endpoint emits) and `err.code === "bcc_rate_limited"`.

### Canonical taxonomy (codes already in use, grouped by family)

This is documentation, not new code. Add to `docs/api-contract-v1.md §Error Codes`:

- **Auth & authorization:** `bcc_unauthorized` (401), `bcc_invalid_credentials`, `bcc_forbidden` (403), `bcc_permission_denied`, `bcc_invalid_state`.
- **Throttling:** `bcc_rate_limited` (429).
- **Validation:** `bcc_invalid_request` (400/422), `bcc_invalid_handle`, `bcc_invalid_chain`, `bcc_invalid_mention_target`, `bcc_too_many_mentions`, `bcc_invalid_envelope`, `bcc_invalid_response`.
- **State:** `bcc_conflict` (409), `bcc_precondition_failed` (412), `bcc_not_found` (404), `bcc_unavailable`, `bcc_peepso_unavailable`.
- **Moderation undo:** `bcc_undo_expired`, `bcc_undo_forbidden`, `bcc_undo_stale_state`.
- **Feature gating:** `bcc_push_not_configured`, `bcc_signature_invalid`, `bcc_wallet_already_linked`, `bcc_wallet_not_linked`.
- **Server:** `bcc_internal_error` (500), `bcc_unexpected_status`, `bcc_network_error`, `bcc_upstream_unavailable`.
- **Misc:** `bcc_unknown`, `bcc_card_pulled`, `bcc_upload_failed`.

No new codes are needed. The codebase already emits a coherent taxonomy. The frontend just needs to consume it via `code` rather than `message`.

### Centralize ERROR_COPY?

19 components currently maintain their own per-surface copy map. Centralization is a real cleanup opportunity but doesn't belong in this stabilization plan (the user constraint says "no future-platform abstractions"). **Defer to i18n introduction.** When localization is wired, the natural pattern is a single `lib/i18n/errors.ts` that exports `errorCopy(locale, code)`.

### Risk dimensions

- **ARCHITECTURAL RISK** — low. Frontend-side defensive coding.
- **UX RISK** — Today: medium (any backend message wording change breaks the UI silently). After: low.
- **OPERATIONAL RISK** — zero either way.
- **CONTRACT RISK** — Today: high (the contract pin is `code`, the SPA branches on `message`). After: low (SPA aligns with contract).

### Bucket

- **HIGH sites: MUST-FIX BEFORE PUBLIC API.** A documented public API requires consumers to be able to branch on stable codes; the SPA being the example consumer must demonstrate the right pattern.
- **MEDIUM sites: acceptable debt** until V2 i18n surfaces. The wording is narrow today; the risk is real but distant.
- **Centralized ERROR_COPY: acceptable debt** until i18n.

### Estimated effort

HIGH sites: 8 surgical edits, ~1 hour total. Documentation: ~1 hour to write the error-code reference section in `api-contract-v1.md`. MEDIUM sites: deferred.

---

## Execution order — what to ship and when

### Phase α — MUST-FIX BEFORE BETA (within current cycle)

1. **Envelope mismatch patch.** ~5 lines in `Envelope.php`. Verify with the GOLDEN_PATHS recipe + browser check that X/GitHub status renders the actual state instead of "Status unavailable."
2. **Document the V-07 / V-29 dual-namespace shape recognition** in `pattern-registry.md` so future agents don't re-introduce the regression.

### Phase β — MUST-FIX BEFORE MOBILE (after Phase α, before any mobile alpha)

3. **`push_available` capability.** PHP + frontend. 1 hour.
4. **Permission null-safety sweep.** 16 sites + optional helper. ~30 min.
5. **JWT silent refresh:** new `/auth/refresh` endpoint + client.ts retry path. ~4 hours.

### Phase γ — MUST-FIX BEFORE PUBLIC API (after Phase β)

6. **Error contract normalization (HIGH sites).** 8 surgical edits + new client-side `Notification.permission` check. ~1 hour.
7. **Per-endpoint error-code enumeration** in `docs/api-contract-v1.md §Error Codes`. ~1 hour.

### Phase δ — Acceptable debt (defer until forced)

8. Error-contract MEDIUM sites (HTTP-status branches).
9. Centralize ERROR_COPY across 19 components (defer until i18n).
10. Composer draft persistence in `localStorage` (related to JWT survivability but a separate concern).
11. V-07 namespace consolidation (long-term project documented in audit).

---

## Cumulative risk profile

After Phase α: the OAuth + endorse + fingerprint endpoints work end-to-end for the first time on this branch. Beta-ready.

After Phase β: mobile cold-start, suspended-wake, and mid-flight expiry all degrade gracefully instead of catastrophically. Mobile-ready (modulo composer draft persistence which is a separate scope).

After Phase γ: public-client contract is honest — `code` is the stable identifier, `message` is humanizable. Public-API-ready.

After Phase δ (whenever): the codebase is technically clean. Not gating.

---

## What this plan deliberately does NOT propose

- Consolidating `/bcc-trust/v1/*` into `/bcc/v1/*` (V-07). That's a multi-week project. Phase α makes the dual-namespace functional, not consolidated.
- Removing `bccTrustFetch`. Two clients persist; both work after Phase α.
- Schema changes. None of the five items touch the DB.
- Rotating refresh tokens / NextAuth silent-refresh callback. The minimum-viable refresh is single-shot `/auth/refresh` on 401, with grace window.
- New DegradationMetrics subsystems. Existing observability (audit_log_swallow, account_security_mail, etc.) is sufficient.
- Bundle-size / Sentry / replay redaction policy work. Out of scope for stabilization.

---

## Verification doctrine (extends GOLDEN_PATHS.md)

After Phase α: Section 14.3 of GOLDEN_PATHS.md gains the `/bcc-trust/v1/*` envelope check (verify X status renders correctly).

After Phase β: GOLDEN_PATHS Section 1 (Auth) gains a "refresh round-trip" subsection: extract bearer, post to `/auth/refresh`, confirm new token has fresh `exp` and survives the original 401-causing request.

After Phase γ: A new GOLDEN_PATHS Section "Error contract enforcement" lists the canonical taxonomy + a smoke check that every documented endpoint actually emits the documented codes.

---

## Closing observation

The five focus areas are surgical because the platform's substrate is sound. Nothing in this plan rewrites the trust pipeline, the PeepSo write boundary, or the DegradationMetric surface. Everything is additive defensive depth. Each item closes a specific risk to external clients without expanding the platform's contract surface.

Phase α alone — the 5-line Envelope.php patch — closes a live production bug. Phases β and γ harden the contract for clients that can't be relied on to ship in lockstep with the backend.

**Hard rule reminder:** do not let scope creep convert this into a "while we're in there" pass. Each item is bounded. Ship one, verify via GOLDEN_PATHS, commit, move on.
