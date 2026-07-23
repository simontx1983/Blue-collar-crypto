# BCC API View-Model Contract — V1

**Status:** Draft v1.49 · 2026-07-23 · Phase 1 deliverable
**Scope:** every endpoint the Next.js frontend (`bcc-frontend/`) calls during V1, and every view-model those endpoints return.
**Authority:** this document is the lock point between WordPress (implements) and Next.js (consumes). When implementation diverges from this contract, the contract wins until a versioned contract update lands.
**Source of truth for decisions referenced as `§Xn`:** `C:\Users\simon\.claude\plans\snazzy-wiggling-muffin.md`.

This is a **contract**, not an implementation guide. It does not prescribe controller class names, table layouts, or PHP. It prescribes **shapes**.

---

## 0. Architectural commitments this contract enforces

Three rules from the master plan govern every endpoint here. If any field below appears to violate one of these, the field is wrong, not the rule.

- **§A2 — no business logic in the frontend.** Trust scores, tiers, ranks, permissions, flags, derived labels — all pre-computed by the server. The client renders, never derives.
- **§A4 — single source of trust logic.** Trust-derived values come from `bcc-trust` plugin services. No other system recomputes, overrides, or approximates.
- **§L5 — view-models are presentation-ready.** If a junior frontend dev would be tempted to write `if (data.reputation_tier === 'elite') return 'Legendary'`, that mapping belongs on the server.

Design corollary: every endpoint in §4 returns a view-model **shaped exactly the way its consuming UI component expects to render it**. No transforms, no fallbacks, no "let the client figure it out."

---

## 1. Conventions

### 1.1 Base URL & versioning

REST endpoints live under two namespaces, by deliberate split:

| Namespace | Purpose | Examples |
|---|---|---|
| `/wp-json/bcc/v1/` | Shared cross-plugin **read** API + cross-plugin mutations consumed by bcc-search and the headless frontend | `GET /cards/:type/:id`, `GET /feed`, `GET /users/:handle`, `POST /disputes`, `POST /posts`, `POST /reactions` |
| `/wp-json/bcc-trust/v1/` | Trust-engine-internal **mutations** (endorse/revoke/device-fingerprint), OAuth | `POST /endorse`, `POST /revoke-endorsement`, `POST /device-fingerprint`, `GET /github/*`, `GET /x/*` |

The host is the WordPress site. The Next.js app proxies cross-origin via its API client. The split is enforced by convention (see `bcc-trust/app/Domain/Core/Plugin.php::registerRoutes` docblock): new reads → `bcc/v1`; new trust-engine mutations → `bcc-trust/v1`.

Versioning: `v1` is locked for V1 in both namespaces. Breaking changes go to `v2`. Additive changes (new fields, new endpoints) ship to `v1` with a deprecation header on anything removed.

#### 1.1.1 Additive-deprecation runway (rename pattern)

When a v1 surface needs to **rename** (route or field), a hard v2 cutover is not the only option. The rename can ship within v1 over a two-release runway:

1. **Release N — additive ship.** New name lands alongside the old. Both routes call the same handler; both fields hold identical values. The OLD name gets `Deprecation`/`Sunset` response headers per [RFC 8594](https://datatracker.ietf.org/doc/html/rfc8594). Frontend cuts over to the new name in the same release.

2. **Release N+1 — old name removed.** Cleanup release: the deprecated routes / fields disappear. The `Sunset` header date documents when this happens.

**Required headers on the deprecated surface (release N only):**

- `Deprecation: true`
- `Sunset: <RFC 7231 HTTP-date>` — the exact date the deprecated surface is removed in release N+1.
- `Link: <https://docs/api-contract-v1.md#section>; rel="deprecation"` — points clients to the migration guide.

**Required documentation in the contract:**

- The new canonical name is documented in its primary section.
- The deprecated name lives in a `§X.Y.1 Deprecated: <name>` subsection beside it, with a one-line "removed in release N+1" stamp.

**When to use this pattern instead of v2:**

- Use this pattern when the underlying handler / data is unchanged — only the name is moving. Single concept, two labels for one release.
- Use a hard v2 bump when shape changes (field types, response envelope, semantics).

Migration record (precedents):

- **2026-05-13** — Binder → Watching rename (§4.5). First use of this pattern. Sunset date documented per-route in §4.5.1.

### 1.2 Authentication

Three modes:

| Mode | When | How |
|---|---|---|
| **Anonymous** | Public reads (`GET /cards/:type/:id`, `GET /feed/hot`, `GET /users/:handle`) | No `Authorization` header |
| **Bearer** | Authenticated reads + writes | `Authorization: Bearer <jwt>` minted by the JWT plugin via NextAuth |
| **Wallet-signed** | One-time challenge-response (claim flow, wallet linking) | `POST` with `{ wallet, signature, nonce }` payload |

JWT lifetime: 1h access, 30d refresh. Refresh handled by NextAuth.
Anonymous endpoints **must still respect privacy** (per K2): if a user's watchlist is hidden, anonymous reads see "Watchlist is private," not the contents.

### 1.3 Headers

**Request (always):**
- `Accept: application/json`
- `Content-Type: application/json` (writes only)
- `Authorization: Bearer <jwt>` (authed only)

**Response (always):**
- `Content-Type: application/json; charset=utf-8`
- `X-BCC-Request-Id: <uuid>` — for log correlation
- `X-BCC-Cache: HIT|MISS|STALE` — see §1.6
- `Cache-Control: ...` per endpoint
- `X-RateLimit-Limit: <n>` / `X-RateLimit-Remaining: <n>` / `X-RateLimit-Reset: <unix>` — on rate-limited endpoints

### 1.4 Errors

Every error follows this shape (matches the canonical envelope; see §L5):

```json
{
  "error": {
    "code": "bcc_permission_denied",
    "message": "You need to be Level 2 to write reviews.",
    "status": 403,
    "data": {
      "rule": "O5+D2",
      "unlock_hint": "Pull 5 cards and visit the Floor 3 days to unlock reviews."
    }
  },
  "_meta": { "version": "1.0" }
}
```

`code` is machine-readable (snake_case, namespaced `bcc_`). `message` is human-readable copy intended for **debugging surfaces** (logs, Sentry, dev tools) and as a **last-resort presentation fallback** — but the canonical Phase γ rule is:

> **Clients MUST branch on `code`. Clients MUST NOT branch on `message` content, `message.includes(...)`, or HTTP status alone except where this contract explicitly says so.**

`data.unlock_hint` appears whenever the error is a soft gate the user can resolve themselves; prefer reading it from the `unlock_hint` companion of the relevant `PermissionsBlock` (§2.1) rather than the error body.

#### 1.4.1 Error envelope contract (Phase γ)

The envelope contract has three sub-rules. They are part of the public API:

1. **`error.code` is stable.** Once a code ships in this document, it does not change shape, location, or meaning. New codes may be added; existing codes are never repurposed. Removing a code requires a contract version bump.

2. **`error.message` is presentation, not contract.** The server is free to evolve the English copy on any code at any time — including rephrasing, localizing, or de-personalizing it. Clients that branch on the message string will silently break when the copy is rewritten.

3. **HTTP status mirrors `error.status`.** They are always equal. Clients SHOULD prefer `error.code` for branching; `status` is a coarse fallback that exists for transport-level concerns (proxies, retry tooling, CDN caching).

#### 1.4.2 Retryable vs non-retryable

Branch on `code`, not status, to decide whether a retry can succeed:

| Class | Codes | Retry? |
|---|---|---|
| Transient | `bcc_rate_limited`, `bcc_upstream_unavailable`, `bcc_internal`, `bcc_internal_error` | Yes, with backoff |
| Auth | `bcc_unauthorized`, `bcc_token_expired`, `bcc_token_invalid` | Refresh first (see §1.4.3), then retry once |
| Client error | `bcc_invalid_request`, `bcc_permission_denied`, `bcc_blocked`, `bcc_forbidden` | No — the request is wrong; show UX, do not retry |
| Resource state | `bcc_not_found`, `bcc_conflict` | No — state has changed; refetch |
| Configuration | `bcc_push_not_configured`, `x_not_configured`, `github_not_configured` | No — the SERVER must be reconfigured |

Clients SHOULD treat any unknown `bcc_*` code as **non-retryable** by default. Retry-on-unknown is unsafe.

#### 1.4.3 Auth-expired vs auth-invalid

Two distinct codes, two distinct UX paths:

- `bcc_token_expired` (401) — the bearer was once valid but has aged past expiry. Clients SHOULD attempt a silent refresh (`POST /bcc/v1/auth/refresh`, see §β.3) and retry once. If the refresh itself returns `bcc_token_expired` or `bcc_unauthorized`, sign the user out.
- `bcc_token_invalid` (401) — the bearer is malformed, was revoked, or never validated. Do NOT retry. Sign the user out and route to `/login`.
- `bcc_unauthorized` (401) — no bearer was provided. Route to `/login`.

The canonical envelope's `message` is presentation; the *behavior fork* is on `code` alone.

#### 1.4.4 Rate-limit semantics

`bcc_rate_limited` (429) is retryable with backoff. The server MAY include `data.retry_after_seconds` (positive integer). When present, clients SHOULD wait at least that long before retrying. When absent, clients SHOULD apply at least 1.5× exponential backoff starting from 2 seconds.

Rate-limit buckets are per-user (when authenticated) AND per-IP. Both must allow the request through; the more conservative rejection wins. The server never returns 429 from cached read paths (cached responses bypass throttling).

#### 1.4.5 Capability-denied semantics

`bcc_permission_denied` (403) is **never used for state errors** ("can't unlink — wallet doesn't exist") and **never used for input errors** ("missing chain field"). Those are `bcc_not_found` and `bcc_invalid_request` respectively.

`bcc_permission_denied` always carries `data.unlock_hint` (when one applies). UI surfaces that show entity actions (endorse, claim, post, pull) MUST prefer the `permissions.can_*.allowed` boolean + `unlock_hint` resolved server-side at view-model assembly time over a 403-then-handle pattern. The 403 path is the *race condition / direct-call* fallback, not the canonical UX.

Resource ownership errors use `bcc_forbidden` (403) — the viewer is authenticated but is not the owner / not authorized for this resource. Distinguishing `bcc_permission_denied` (gate not met) from `bcc_forbidden` (not the owner / wrong identity) lets clients branch the unlock-vs-redirect UX cleanly.

User-relationship errors (blocked, muted) use `bcc_blocked` (403). The frontend SHOULD NOT explain why (no "this user blocked you" copy); a generic "You can't message this person" is correct.

#### 1.4.6 Standard codes

| Code | HTTP | Class | Meaning |
|---|---|---|---|
| `bcc_invalid_request` | 400 | Client error | Bad input shape or missing required field |
| `bcc_unauthorized` | 401 | Auth | No bearer present |
| `bcc_token_expired` | 401 | Auth | Bearer expired; refresh + retry once |
| `bcc_token_invalid` | 401 | Auth | Bearer malformed/revoked; sign out |
| `bcc_permission_denied` | 403 | Client error | Auth'd but gate fail (soft, `unlock_hint`) |
| `bcc_forbidden` | 403 | Client error | Auth'd but not the owner / wrong identity |
| `bcc_blocked` | 403 | Client error | Blocked or muted by counter-party (do not explain) |
| `bcc_not_found` | 404 | Resource state | Resource does not exist or is hidden from this viewer |
| `bcc_conflict` | 409 | Resource state | State collision (claim already won, batch already closed) |
| `bcc_rate_limited` | 429 | Transient | Per-user / per-IP rate limit hit (optionally `data.retry_after_seconds`) |
| `bcc_internal` / `bcc_internal_error` | 500 | Transient | Unhandled server error — never exposes internals. Both spellings are emitted (`bcc_internal_error` is the dominant one, ~47 handlers); clients MUST treat both as retryable-transient. |
| `bcc_upstream_unavailable` | 502 | Transient | Upstream chain RPC / indexer down |

**Feature-specific codes** (extend the table above; same semantics):

| Code | HTTP | Surfaces | Meaning |
|---|---|---|---|
| `bcc_signature_invalid` | 400 | Wallet link, claim, wallet-login | Signed payload didn't verify against the address |
| `bcc_too_many_mentions` | 400 | Composer, comment | Body has more `@`-mentions than `data.max` allows |
| `bcc_invalid_mention_target` | 400 | Composer, comment | `data.user_id` is not addressable (privacy, blocked, deactivated) |
| `bcc_invalid_envelope` | 0 | All | Client-detected: response did not match envelope shape (NOT server-emitted) |
| `bcc_push_not_configured` | 503 | Push subscribe | Server lacks VAPID keys |
| `x_not_configured` | 503 | OAuth / X | Server lacks X (Twitter) OAuth config |
| `github_not_configured` | 503 | OAuth / GitHub | Server lacks GitHub OAuth config |
| `invalid_nonce` | 400 | OAuth callbacks | Stale or mismatched nonce |
| `share_not_found` | 404 | OAuth / X verify | No tweet found that links this site |
| `bcc_nft_not_owned` | 403 | NFT showcase | Selected NFT isn't in the viewer's linked wallets |
| `bcc_wallet_not_supported` | 400 | Wallet link | Chain isn't enabled on this site |
| `bcc_endorse_self` | 403 | Endorse | User attempted to endorse their own page |
| `bcc_fraud_locked` | 403 | Endorse, attest, vote | Account is restricted from this action due to flagged unusual activity. Distinct from `bcc_permission_denied` because it is NOT user-resolvable — no `unlock_hint` applies; the frontend surfaces a generic "temporarily restricted" copy. |

#### 1.4.7 Client-side typed errors

Some failures originate in the browser before any HTTP request lands. These MUST surface as **typed error classes**, not plain `new Error("...")` strings. Pattern-matching on `err.message` is forbidden (Phase γ).

| Class | Origin | Branch on |
|---|---|---|
| `PushUnsupportedError` | `lib/push/register.ts` | `instanceof` |
| `ServiceWorkerError` | `lib/push/register.ts` | `instanceof` |
| `PushPermissionDeniedError` | `lib/push/register.ts` | `instanceof` |
| `PushSubscriptionKeysError` | `lib/push/register.ts` | `instanceof` |
| `KeplrUnavailableError`, `KeplrUserRejectedError`, `KeplrError` | `lib/wallet/keplr.ts` | `instanceof` |
| `MetaMaskUnavailableError`, `MetaMaskUserRejectedError`, `MetaMaskError` | `lib/wallet/metamask.ts` | `instanceof` |
| `PhantomUnavailableError`, `PhantomUserRejectedError`, `PhantomError` | `lib/wallet/phantom.ts` | `instanceof` |

These classes carry **authored** `.message` copy — that copy is safe to render directly because it's owned by our frontend, not the server.

#### 1.4.8 Canonical client-side error helper

The frontend ships `bcc-frontend/src/lib/api/errors.ts`, exporting two functions:

```ts
isCode(err: unknown, code: string): boolean
humanizeCode(err: unknown, copyMap: Record<string, string>, defaultCopy: string): string
```

`humanizeCode` deliberately does NOT fall back to `err.message`. Every UI string is either authored at the call site (in `copyMap` or `defaultCopy`) or constructed by a typed client-side error class. The server's English copy is never user-visible through this helper.

The two functions are the ONLY supported pattern for surfacing BCC error envelopes in components. Direct `err.message` reads in user-facing JSX are a regression and will be caught by the regression grep documented in §γ.5.

### 1.5 Pagination

Two styles, **never mixed within an endpoint**:

**Cursor (feeds, activity streams, anything ordered by time):**

```json
{
  "items": [...],
  "pagination": {
    "next_cursor": "eyJ0IjoxNzU4...",
    "has_more": true
  }
}
```

**Cursor format (locked):** the server encodes `(timestamp, id, rank_score_at_emit)` as a base64-encoded JSON token, e.g.,

```
base64({"t":"2026-04-27T11:14:00Z","id":98712,"rs":0.873})
```

Including `rank_score_at_emit` lets the server preserve scroll order across re-ranks at the cost of slight duplication risk. The cursor is **opaque to the client** — never decode, parse, or modify it; round-trip it verbatim. Default `limit` = 20, max = 50.

**Offset (directories, static lists, admin tools):**

```json
{
  "items": [...],
  "pagination": {
    "page": 1,
    "page_size": 20,
    "total": 142,
    "total_pages": 8
  }
}
```

Default `page_size` = 20, max = 100.

### 1.6 Caching

Every response carries a `Cache-Control` header. The Next.js app uses React Query with a session-scoped cache; the server uses Redis + the existing `bcc_page_read_model` denormalized table.

| Endpoint kind | Server TTL | Client TTL (React Query `staleTime`) |
|---|---|---|
| Card view-models | 60s | 30s |
| Feed pages | 30s | 15s |
| User profile (others') | 60s | 30s |
| User profile (self) | 0s (always fresh) | 0s |
| Locals list | 5m | 2m |
| Ranks list | 5m | 5m |
| NFT gallery | 30m (stale-while-revalidate per §H1) | 5m |
| Auth nonce | 0s, single-use | 0s |

**Stale-while-revalidate (§H1) is the default for any endpoint reading from upstream chains or third-party APIs.** The response includes `X-BCC-Cache: STALE` and the server kicks a background refresh — the client never blocks on the upstream.

### 1.7 Timestamps, IDs, timezones

- **Timestamps:** ISO 8601 with timezone, e.g., `"2026-04-27T14:23:00Z"`. Always UTC on the wire. The frontend converts for display.
- **IDs:** integers (matches WP), serialized as JSON numbers. Page IDs and user IDs share the WP integer space.
- **Handles:** strings, no `@` prefix in payloads. The `@` is a render-time concern. Constraints (per §B6):
  - **lowercase only**
  - **3–20 characters**
  - allowed character set: `[a-z0-9-]`, no leading/trailing hyphens, no consecutive hyphens
  - **unique** (case-insensitive)
  - reserved handles blocked (server-managed list: `admin`, `bcc`, `support`, `system`, `api`, `null`, etc.)
  - **required before any posting/reacting/pulling/vouching/dispute-signing.** Endpoints that gate on posting MUST return `bcc_permission_denied` with `unlock_hint: "Pick a handle to start posting."` if the authenticated user has no `bcc_handle`.
- **Slugs:** strings, lowercase, kebab-case, no leading slash. **Immutable post-creation** — once assigned, a slug never changes. Admins rename via display name only (e.g., a Local's `name` updates, the slug does not). This guarantees `links.self` URLs stay stable forever.
  - **Feed-item nuance (v1.39):** a post's `links.self` is `/u/{handle}/post/{shortcode}`. The **shortcode** is the immutable, canonical resolver key (8 letters, `wp_bcc_post_shortcodes`); the **handle prefix is display context only** and follows the author's current handle — after a handle change the old URL's shortcode still resolves, and the frontend redirects to the canonical handle. Immutability attaches to the shortcode, not the full string.
- **Navigation URLs:** root-relative (`/v/blacksmith-node`, not `https://bluecollar.crypto/v/blacksmith-node`). Used in `links.*` fields. The frontend prepends host as needed.
- **Asset / media URLs (avatars, NFT images, drop images, blog images):** **absolute** (`https://bluecollar.crypto/wp-content/uploads/...`). CDN-ready: the server controls the host so we can swap to a CDN origin without a contract change. The frontend never rewrites these. **No relative paths for media.**
- **External URLs (`release_url`, cross-origin `mint_link`, etc.):** absolute.
- **Wallet `address_short`:** truncated as `<first-6>…<last-4>` for all chains, taken from the full chain-prefixed address string (e.g., `cosmos1abcdef0123…wxyzq3kf` → `cosmos…q3kf`; `0xab5801a7d398351b8be11c439e05c5b3259aec9b` → `0xab58…ec9b`). The server is the only writer; the frontend never re-truncates.

### 1.8 Locale

V1 ships English only (per §P4 deferred list). All user-facing strings (`message`, `label`, `unlock_hint`, etc.) are server-rendered English. The contract reserves a future `locale` query param but does not honor it in V1.

### 1.9 Debug block (`_debug`) — dev-only diagnostics

Any response MAY include an optional top-level `_debug` object with internal diagnostics. The block is **off by default in all environments** and is included only when both conditions are met:

1. The request explicitly opts in: `?debug=1` query param OR `X-BCC-Debug: 1` request header.
2. The viewer is authorized: WP `WP_DEBUG === true` (dev environment) OR the authenticated user has the `bcc_view_debug` capability (admin/staff).

When neither condition holds, the field is **omitted** (not `null`) — production payloads contain no `_debug` key at all.

**Shape (illustrative — fields vary by endpoint):**

```json
{
  "_debug": {
    "render_ms": 47,
    "cache": "HIT",
    "ranking_score": 0.87,
    "source_tables": ["bcc_page_read_model", "bcc_trust_score_events"],
    "viewer_level": 2,
    "feature_resolver_calls": 6,
    "queue_depth": 3
  }
}
```

**Rules:**

- The frontend MUST NOT depend on `_debug` for any rendering decision. It is strictly read-only diagnostic data.
- Field set is open-ended and may change without bumping the contract version (additive-only by convention).
- `_debug` MUST NEVER contain user-identifying data the viewer wouldn't otherwise be allowed to see (e.g., other users' private fields, raw wallet addresses for non-self).
- The leading underscore is intentional: signals "implementation detail, not API surface."

---

## 2. Shared embed types

These appear inside the four core view-models. Defined once here, referenced by name elsewhere.

### 2.1 `PermissionsBlock` and `UnlockHint`

The single most important shared type. Encodes §N7 (always visible, disabled with hint) and §O5.1 (locked-feature preview).

**Shape:**

```json
{
  "permissions": {
    "can_watch":             { "allowed": true,  "unlock_hint": null },
    "can_review":           { "allowed": false, "unlock_hint": "Reach Level 2 (5 pulls + 3 Floor visits) to write reviews." },
    "can_vouch":            { "allowed": false, "unlock_hint": "Reach Level 2 to use the Vouch reaction." },
    "can_post_as_entity":   { "allowed": false, "unlock_hint": null },
    "can_edit_bio":         { "allowed": false, "unlock_hint": null },
    "can_edit_image":       { "allowed": false, "unlock_hint": null, "reason_code": "not_claimer" },
    "can_open_dispute":     { "allowed": false, "unlock_hint": null, "reason_code": "not_page_owner" }
  }
}
```

**Rules (§N7):**

- Every gate the viewer might *eventually* unlock is listed, even when `allowed: false`. Hidden gates teach nothing; visible gates teach the system.
- When `allowed: false`, `unlock_hint` is a **plain-English explanation** the frontend renders verbatim as a tooltip/disabled-button helper.
- `can_open_dispute` is the **owner vote-dispute** entry (DisputeCallout → `POST /disputes`) and mirrors the write gate exactly: page ownership only, no feature ladder (shipped 2026-06-11). It is the **sole** dispute gate — the `can_dispute` §J attestation-cast gate (`sign_dispute` ladder) was a dead scaffold (no such attestation kind existed) and was retired 2026-07-08. Non-owners get `reason_code: "not_page_owner"` with no hint — the §N7 visible-gate rule applies to gates a viewer can eventually unlock, which ownership of someone else's page is not.
  - **Member self-pages (member-disputes slice, 2026-06-30):** a member is the owner of their own self-page, so on their OWN member card/profile `can_open_dispute` is `allowed: true` when they have a contestable active downvote on their self-page, else `reason_code: "no_contestable_downvote"`. Non-owners and anonymous viewers get `reason_code: "not_applicable"` (members can't dispute votes on each other — only the subject can contest a downvote on themselves, mirroring the entity owner-contests-downvote model). Ownership resolves via the zero-DB self-page identity (`page_id = ID_BASE + user_id`).
- When `allowed: true`, `unlock_hint` is `null` (not omitted — explicit `null` so client typing is uniform).
- When a gate is **structurally impossible** for this viewer (e.g., viewing your own card → `can_watch: false`, you can't follow yourself), `unlock_hint` is `null` and the frontend hides the action UI per §N7's "structurally impossible" carve-out (the always-visible rule applies to *gates a viewer could resolve*, not to nonsensical actions).

**Permission stacking (§O5+D2):** when both an O5 level gate and a D2 reputation/wallet gate apply, the server resolves both checks and returns a single `allowed` boolean. The frontend never combines gates. The `unlock_hint` describes whichever gate is closer to resolution (e.g., if the user has rep ≥ neutral but is Level 1, the hint says "Reach Level 2…", not "Earn neutral reputation…").

### 2.2 `SocialProof`

Encodes §O4 + §O4.1. Appears on Card and Feed item view-models.

**Shape:**

```json
{
  "social_proof": {
    "headline": "@trusteddan, @ironlocal +2 others follow this",
    "named_handles": ["trusteddan", "ironlocal"],
    "additional_count": 2,
    "kind": "follow",
    "weighted": true
  }
}
```

Or, if the viewer has zero network connection:

```json
{
  "social_proof": null
}
```

**Rules:**

- `headline` is server-rendered, ready to display. The frontend never composes it from `named_handles` + `additional_count`.
- `named_handles` only ever contains **elite** or **trusted** tier users from the viewer's network (§O4.1).
- `additional_count` includes neutral-tier users from the viewer's network. Caution/risky users are excluded entirely (§O4.1 + §K1 shadow-limit propagation).
- **Hidden-watchlist rule (§O4):** users who have hidden their watchlist (per §K2) still contribute to `additional_count` but **never** appear in `named_handles`, even if they're elite/trusted.
- `kind` is one of: `follow`, `vouch`, `stand_behind`, `dispute_signed`, `nft_held`, `local_member`. Determines which verb the headline uses.
- `weighted: true` means the headline applied trust weighting; `false` means raw count (used only on internal admin views, never on public surfaces — kept in the contract for symmetry).

**Anti-rule:** the frontend MUST NOT show `additional_count` as a separate UI element. The server has already incorporated it into `headline`. Showing it twice is a rendering bug.

### 2.3 `Celebration`

Encodes §O1.2 micro-dopamine. Two delivery paths:

1. **Inline on write responses** (POST/DELETE) — for celebrations whose trigger is sync with the originating request (Light intensity on a pull, Mid intensity on a first-of-its-kind action that the server detects synchronously).
2. **Out-of-band via the celebrations stash** (§4.11) — for Heavy celebrations whose trigger runs through an async §A3 subscriber (rank-up, future level-up, future tier-upgrade). The originating request's response can't include the celebration inline because the listener hasn't run yet; the frontend polls `GET /me/celebrations/pending` and clears via `POST /me/celebrations/consume`.

Both paths emit the **same shape** so the frontend's toast component is path-agnostic.

**Shape:**

```json
{
  "celebration": {
    "kind": "mid",
    "label": "First review posted.",
    "icon": "review-stamp",
    "rarity_tint": null
  }
}
```

Or `null` if the action shouldn't celebrate.

**Rules:**

- `kind` is one of: `light` (e.g., a normal watch), `mid` (first-of-its-kind action — server tracks via `bcc_first_*` user_meta flags per §O1.2), `heavy` (tier upgrade, rank promotion, feature unlock, 30-day streak).
- For Heavy moments delivered via §4.11, the inline shape is wrapped — `kind`/`label`/`icon` are nested under `celebration`, alongside an `id` for consume targeting. See §4.11 for the wrapper.
- `label` is what the frontend renders in the toast. Plain English, ≤ 50 chars.
- `icon` is a server-defined enum the frontend maps to a sprite (`watch`, `review-stamp`, `vouch-handshake`, `dispute-shield`, `rank-up`, `tier-upgrade`, `streak-flame`, `unlock`, `local-badge`). The legacy `pull` icon name is accepted by the frontend during the §1.1.1 deprecation window — see §4.5.1.
- `rarity_tint` is non-null only on `light` celebrations triggered by a watch — the value is the watched card's `card_tier`, used for the glow color (§O1).
- Reduced-motion fallback (§O1.2): the frontend renders the static toast with `label`, ignoring all animation hints. Server contract is identical; the rendering decision is client-side only.

### 2.4 `LivingBlock`

Encodes §O3 + §O3.1. Appears on User view-models.

**Shape:**

```json
{
  "living": {
    "streak_days": 31,
    "streak_at_risk_today": false,
    "today": {
      "reviews": 2,
      "solids_received": 14,
      "vouches_received": 3,
      "disputes_signed": 1,
      "pulls": 0
    },
    "comparison": {
      "headline": "Top 20% this week",
      "kind": "network_percentile",
      "as_of": "2026-04-27"
    }
  }
}
```

**Rules:**

- `today` keys with value `0` are **omitted** from the rendered string per §O3 — but they ARE returned in the JSON for completeness. The frontend filters zeros before composing the display line.
- `streak_at_risk_today: true` means the user has had no qualifying activity today (UTC) — surfaces a soft prompt in the UI.
- `comparison.kind` is one of: `network_percentile`, `local_peer`. The "friend comparison" variant was cut (§O3.1, deferred list).
- `comparison` rotates server-side every 24h between the available kinds. If the user has no primary Local, only `network_percentile` is returned.
- `comparison` is `null` when the user is too new for a meaningful comparison (server threshold: < 7 days active OR < 5 actions logged).

### 2.5 `ProgressionBlock`

Encodes §N11. Appears on the **own** User view-model (not on others' profiles).

**Shape:**

```json
{
  "progression": {
    "current_rank": "journeyman",
    "current_rank_label": "Journeyman",
    "next_rank": "master",
    "next_rank_label": "Master",
    "next_rank_thresholds": [
      { "metric": "reviews_written", "label": "Reviews", "current": 1,  "required": 3 },
      { "metric": "days_active",     "label": "Days active", "current": 12, "required": 30 }
    ],
    "trust_score_recent_changes": [
      { "delta":  1, "reason": "Governance vote", "at": "2026-04-22" },
      { "delta":  2, "reason": "Uptime streak (14d)", "at": "2026-04-15" },
      { "delta": -1, "reason": "Dispute lost", "at": "2026-04-10" }
    ],
    "quests": {
      "multiplier": 1.28,
      "completed_count": 4,
      "total_count": 7,
      "pct": 57,
      "items": [
        { "slug": "connect_wallet", "label": "Connect a Wallet", "hint": "Prove on-chain identity for higher credibility.", "done": true,  "weight_bonus": 0.08, "category": "identity" },
        { "slug": "verify_github",  "label": "Verify GitHub Account", "hint": "Proves code ownership — boosts your vote weight.", "done": true,  "weight_bonus": 0.07, "category": "identity" },
        { "slug": "explore_projects", "label": "Explore 3 Projects", "hint": "Browse and evaluate real projects.", "done": false, "weight_bonus": 0.02, "category": "engagement" }
      ]
    }
  }
}
```

**Rules:**

- Rank mirrors the feature-access **level** (Apprentice=New, Journeyman=Active, Master=Veteran), so `next_rank_thresholds` is exactly the next level's gate from §2.6 `next_level_thresholds`: **Apprentice → Journeyman** = `pulls` (≥5); **Journeyman → Master** = `reviews_written` (≥3) + `days_active` (≥30).
- `next_rank` is `null` when the user is at the top of the earned ladder (Master) — `next_rank_thresholds` is then `[]`.
- Master is the top of the earned ladder — there is no rung above it. (The conferred Foreman **Role** is retired for V1; see §4.8.)
- The frontend renders the `current/required` ratio for each threshold as a progress bar.
- `trust_score_recent_changes` is the most recent 5 reputation events (sorted desc by `at`). Reason strings are plain English, ≤ 80 chars. (Trust score drives the *Tier* axis, not Rank — it's surfaced here only as recent-activity context.)
- `quests` is the §N11 completion checklist and the earned **vote-weight multiplier** (`multiplier`, 1.00–1.30) it grants — the value `VoteWeightCalculator` applies to the operator's votes at cast time. `items` is a stable-ordered list (the quest catalogue order), each with `done` and the `weight_bonus` that quest contributes. `pct` is `round(completed_count / total_count × 100)`. Own-only, like the rest of the block. Copy is descriptive per §2.7 — the frontend never renders a prescriptive "complete this" nudge.

### 2.6 `FeatureAccess`

Encodes §O5 + §O5.1. Appears on the **own** User view-model.

**Shape:**

```json
{
  "feature_access": {
    "level": 2,
    "level_label": "Active",
    "next_level": 3,
    "next_level_label": "Veteran",
    "next_level_thresholds": [
      { "metric": "reviews_written", "label": "Reviews", "current": 1,  "required": 3 },
      { "metric": "days_active",     "label": "Days active", "current": 12, "required": 30 }
    ],
    "features": {
      "write_review":         { "allowed": true,  "unlock_hint": null },
      "vouch_reaction":       { "allowed": true,  "unlock_hint": null },
      "sign_dispute":         { "allowed": false, "unlock_hint": "Write 3 reviews and stay active 30 days to sign disputes." },
      "open_dispute":         { "allowed": false, "unlock_hint": "Write 3 reviews and stay active 30 days to open disputes." },
      "see_signal_details":   { "allowed": false, "unlock_hint": "Reach Level 3 to read on-chain signal details." },
      "see_trust_breakdown":  { "allowed": false, "unlock_hint": "Reach Level 3 to see your trust score breakdown." },
      "feed_tab_signals":     { "allowed": false, "unlock_hint": "Reach Level 3 to filter the Floor by Signals." }
    }
  }
}
```

**Rules:**

- `level` is the integer level (1, 2, 3); `level_label` is the user-facing string ("New", "Active", "Veteran").
- `features.<name>` uses the same `{allowed, unlock_hint}` pair as `PermissionsBlock` — one contract, two surfaces.
- `next_level_thresholds` is server-computed against `bcc_options` thresholds (§O5; admin-tunable).
- **Retroactive unlocks (§O5):** a feature that just unlocked applies to ALL past content immediately. There is no "feature_access.<name>.applies_from" timestamp — unlocks are global.
- The keys in `features` are the canonical names the rest of the contract uses. When `permissions.can_X` appears on a Card view-model, its falsy state's `unlock_hint` is sourced from this same table.

### 2.7 `UxHelpers`

Encodes §N1 + §N5. Appears on the own User view-model and (for anonymous viewers) on `GET /me/state`.

**Shape:**

```json
{
  "ux_helpers": {
    "show_helpers": true
  }
}
```

**Rules:**

- Single boolean: §N5 unifies all per-section / per-action counters into one flag (`wp_user_meta.bcc_ui_familiar`).
- `show_helpers: true` → render dual-labels and reaction helper labels.
- `show_helpers: false` → render brand names alone.
- Anonymous viewers always get `show_helpers: true` (default to teaching mode).

### 2.8 `Stat`

Used inside Card view-models for the stats array.

**Shape:**

```json
{
  "key": "uptime",
  "label": "Uptime",
  "value": "99.97%",
  "format": "percent",
  "raw": 0.9997
}
```

- `key` is canonical (snake_case, stable across kinds where shared).
- `label` is user-facing.
- `value` is the **pre-formatted** string the frontend renders.
- `format` describes how `value` was formatted: `score`, `percent`, `currency_usd`, `currency_native`, `count`, `duration`, `text`.
- `raw` is the underlying numeric — **always present** for `score`, `percent`, `currency_usd`, `currency_native`, `count`, `duration`. Used for sparklines, charts, and tooltip exact-value displays. The display path is always `value`.
- **Currency formatting (locked):** `currency_usd` and `currency_native` values are **server-side abbreviated** with K/M/B suffixes:
  - `< 1,000` → full numerals (`"$842"`, `"82 ATOM"`)
  - `≥ 1,000` and `< 1,000,000` → K suffix, 1 decimal max (`"$4.2K"`, `"12.5K ATOM"`)
  - `≥ 1,000,000` and `< 1,000,000,000` → M suffix, 1 decimal max (`"$4.2M"`, `"1.8M ATOM"`)
  - `≥ 1,000,000,000` → B suffix, 1 decimal max (`"$2.4B"`)
  - Currency symbol/code is included in `value`. The frontend NEVER re-abbreviates and NEVER re-attaches symbols.
  - Full numeric in `raw` for tooltips that show "Exact: 4,221,309".

### 2.9 `Crest`

Used on Card view-models. The visual identity of the entity.

**Shape:**

```json
{
  "initials": "BN",
  "monogram_color": "#1a0f3e",
  "background_kind": "chain",
  "background_value": "cosmos",
  "image_url": null
}
```

- `image_url` is non-null when the entity has uploaded a custom crest; the frontend prefers it over `initials + monogram_color`.
- `background_kind` is `chain` (background_value = chain slug), `tier` (= card_tier), or `solid` (= hex).

### 2.10 `Links`

App-internal navigation hints. The frontend uses these instead of hard-coding paths.

**Shape:**

```json
{
  "links": {
    "self":   "/v/blacksmith-node",
    "review": "/v/blacksmith-node?compose=review",
    "share":  "/v/blacksmith-node?share=1"
  }
}
```

All values are root-relative paths. Keys vary by view-model.

### 2.11 `ReactionState`

Appears on Feed item view-models and on Card view-models that support reactions.

**Layered grammar (v1.5):** every `reactions` block carries a `kind_grammar` discriminator that tells the renderer which interaction grammar applies. The kinds in `counts` and the allowed values for `viewer_reaction` are determined by the grammar:

| `kind_grammar` | Applies to `post_kind` | Reaction kinds | Visual grammar |
|---|---|---|---|
| `"trust"`  | `review`, `dispute_signed`, `page_claim`, `project_drop`, `nft_drop`, `signal` | `solid` | restrained, intentional |
| `"social"` | `status`, `watch_batch` (legacy `watch_batch`), `blog_excerpt` | `like`, `love`, `haha`, `wow`, `fire` | expressive, emoji-forward |
| `"tribal"` | _(reserved — V2)_ | _(reserved — e.g. `same_wallet`, `onchain_confirm`)_ | identity-forward |

`counts` always carries all kinds for the active grammar with zero-fill. The frontend never derives the kind set from `post_kind` — it reads `kind_grammar`.

**`solid` is the only trust reaction; `vouch`/`stand_behind` are attestations, not reactions.** `solid` is a lightweight ack (powers the "solids" stat) and confers no trust on its own. Vouching for a person/entity is **not a post reaction** — it moved to the trust-attestation layer (§J): the byline **Vouch** toggle and the §J.6 profile actions cast a `vouch` (or `stand_behind`) *attestation* against the target's self-page via `POST /me/attestations`, and those move the trust score through `AttestationScoreSynthesis`. The `vouch` and `stand_behind` **reaction** kinds were retired when vouch relocated to the attestation layer — a `vouch` reaction on `POST /reactions` is now rejected with `bcc_invalid_request`. Do not confuse the retired reaction with the live §J attestation of the same name.

**Shape (trust grammar):**

```json
{
  "reactions": {
    "kind_grammar": "trust",
    "counts": {
      "solid":        14
    },
    "viewer_reaction": "solid"
  }
}
```

**Shape (social grammar):**

```json
{
  "reactions": {
    "kind_grammar": "social",
    "counts": {
      "like":  9,
      "love":  4,
      "haha":  2,
      "wow":   0,
      "fire":  6
    },
    "viewer_reaction": null
  }
}
```

**Rules:**

- `kind_grammar` is **required** on every `reactions` block. Frontend MUST branch on it; deriving the rail from `post_kind` is forbidden (a future grammar change must not require a frontend rebuild).
- `counts` always has all kinds for the active grammar, even if zero. Eliminates `undefined` checks on the client.
- `viewer_reaction` is `null` when the viewer is anonymous or hasn't reacted; otherwise the kind name (a string belonging to the active grammar).
- A reaction kind from one grammar MUST NOT appear on a `reactions` block of another grammar. The server rejects cross-grammar set-reaction requests with `bcc_invalid_request`.
- _(Legacy fields `my_reactions` and `totals` shown in earlier drafts of this section were never emitted by the V1 implementation; the canonical shape has always been `viewer_reaction: string | null`.)_

### 2.12 Pagination envelopes

See §1.5. The two envelope shapes are referenced as `CursorEnvelope` and `OffsetEnvelope` in §4.

---

## 3. Core view-models

### 3.1 `User`

The full member view-model. Returned by `GET /users/:handle` and embedded everywhere a user appears.

**Shape (own profile, full):**

```json
{
  "id": 42,
  "handle": "simontx",
  "display_name": "Simon TX",
  "avatar_url": "https://bluecollar.crypto/wp-content/uploads/2026/04/simontx-avatar.jpg",
  "cover_photo_url": "https://bluecollar.crypto/wp-content/uploads/peepso/users/42/abc123-cover.jpg",
  "cover_photo_position": { "x": 50, "y": 50 },
  "joined_at": "2026-01-12T00:00:00Z",
  "is_self": true,
  "trust_score": 78,
  "reputation_tier": "trusted",
  "card_tier": "rare",
  "tier_label": "Rare",
  "rank": "journeyman",
  "rank_label": "Journeyman",
  "is_in_good_standing": true,
  "flags": [],
  "bio": "Cosmos validator nerd. Iron Local 342.",
  "primary_local": {
    "id": 12,
    "slug": "cosmos-base-fan",
    "name": "Local 342 Cosmos Base Fan",
    "number": 342
  },
  "locals": [
    { "id": 12, "slug": "cosmos-base-fan", "name": "Local 342 Cosmos Base Fan", "number": 342, "is_primary": true },
    { "id": 18, "slug": "delegators-united", "name": "Local 519 Delegators United", "number": 519, "is_primary": false }
  ],
  "wallets": [
    {
      "id": 17,
      "address": "cosmos1abc…q3kf",
      "address_short": "cosmos…q3kf",
      "chain_slug": "cosmos",
      "chain_name": "Cosmos Hub",
      "is_primary": true,
      "verified_at": "2026-01-13T10:00:00Z"
    },
    {
      "id": 21,
      "address": "0xab58c2…ec9b",
      "address_short": "0xab58…ec9b",
      "chain_slug": "ethereum",
      "chain_name": "Ethereum",
      "is_primary": false,
      "verified_at": "2026-02-01T12:00:00Z"
    }
  ],
  "counts": {
    "followers": 142,
    "following": 38,
    "watching_size": 38,
    "reviews_written": 8,
    "reviews_received": 5,
    "disputes_signed": 1,
    "solids_given": 240,
    "solids_received": 117
  },
  "privacy": {
    "watching_hidden": false,
    "reviews_hidden": false,
    "disputes_hidden": false,
    "delegations_hidden": false,
    "follower_count_hidden": false,
    "real_name_hidden": true,
    "email_hidden": true
  },
  "living": { "...": "see §2.4" },
  "progression": { "...": "see §2.5 — own profile only" },
  "feature_access": { "...": "see §2.6 — own profile only" },
  "ux_helpers": { "...": "see §2.7 — own profile only" },
  "permissions": {
    "can_follow":     { "allowed": false, "unlock_hint": null },
    "can_message":    { "allowed": false, "unlock_hint": null },
    "can_block":      { "allowed": false, "unlock_hint": null },
    "can_edit_profile": { "allowed": true, "unlock_hint": null }
  },
  "links": {
    "self":      "/u/simontx",
    "watching":  "/u/simontx/watching",
    "reviews":   "/u/simontx/reviews",
    "activity":  "/u/simontx/activity",
    "disputes":  "/u/simontx/disputes",
    "network":   "/u/simontx/network",
    "blog":      "/u/simontx/blog"
  }
}
```

**Shape (others' profile, key differences):**

- `is_self: false`
- `progression`, `feature_access`, `ux_helpers` are **omitted entirely** (not even `null`). They are own-only.
- `privacy` block reduced to `{ watching_hidden, reviews_hidden, ... }` reflecting what the viewer can see, not what's set.
- `permissions.can_follow` becomes meaningful (`allowed: true` if the viewer can watch this user as a member card).
- `wallets` returns `address_short` only (never full addresses for others — the privacy floor).

**Field rules:**

- `trust_score`, `reputation_tier`, `reputation_tier_label`, `card_tier`, `tier_label`, `rank`, `rank_label`, `current_rank_label`, `is_in_good_standing`, `flags` — all derived per §A4 by `bcc-trust`. Frontend renders, never derives.
- `card_tier` follows §C1 mapping: `elite → legendary`, `trusted → rare`, `neutral → uncommon`, `caution → common`, `risky → null` (hidden from card UI per §C1). This is **entity-card rarity** vocabulary.
- `reputation_tier_label` is the **honest member trust-tier name** (the chip a human reads): `risky → "Risky"`, `caution → "Caution"`, `neutral → "Neutral"`, `trusted → "Trusted"`, `elite → "Proven"`. Distinct from `card_tier`/`tier_label` rarity — a *caution* member must not read as "Common". Always present on member surfaces.
- `current_rank_label` is the pre-rendered §A2 label for the level-derived **Rank** (`rank_label`'s display string; e.g. `"Master"`). It is a member-axis field.
- `flags` is an array of short slugs; if non-empty, the frontend may render moderation chips. V1 codes: `suspended`, `shadow_limited`, `hidden`, `under_review`.
- Hidden privacy fields (per `privacy.*_hidden: true`) cause the corresponding sections in `counts`, `wallets`, etc. to either be omitted or zeroed depending on the viewer's relationship — server decides, client doesn't.
- `cover_photo_url` is `null` when no custom cover photo is set; the frontend renders a default treatment in that case. URL is absolute (per §1.7) and points at PeepSo's stored cover image. Self-edits go through `PATCH /me/profile/cover` (multipart upload) — see §V2 Phase 2 endpoints.
- `cover_photo_position` is `{x, y}` percentages (0–100) for the CSS `object-position` of the cover crop. Defaults to `{x: 50, y: 50}` (center). Self-edits go through `PATCH /me/profile/cover/position`.

### 3.2 `Card` (polymorphic)

Cards have a shared envelope and per-`card_kind` stat arrays.

**Shared envelope:**

```json
{
  "id": 1842,
  "card_kind": "validator",
  "name": "Blacksmith Node",
  "handle": "blacksmith-node",
  "bio": "Cosmos validator. Iron Local 342.",
  "trust_score": 98,
  "reputation_tier": "elite",
  "reputation_tier_label": null,
  "card_tier": "legendary",
  "tier_label": "Legendary",
  "rank_label": null,
  "current_rank_label": null,
  "is_in_good_standing": true,
  "flags": [],
  "is_claimed": true,
  "is_claim_verified": true,
  "claim_target": null,
  "viewer_has_reviewed": false,
  "viewer_has_endorsed": false,
  "endorse_unlock_hint": null,
  "chains": null,
  "member_dossier": null,
  "crest": { "...": "see §2.9" },
  "stats": [ "...": "see per-kind below" ],
  "social_proof": { "...": "see §2.2" },
  "permissions": { "...": "see §2.1" },
  "links": { "...": "see §2.10" },
  "actions": { "...": "see §3.2.5" }
}
```

**Field rules:**

- `card_kind` ∈ {`validator`, `project`, `creator`, `member`, `community`}. `community` cards (v1.27) are emitted ONLY as the additive `card` field on the §4.7.4 discovery items and the §4.7.5 group detail response — `GET /cards/:type/:id` and `GET /cards` do NOT accept `community` (route enum unchanged); see §3.2.4 for the kind's rules.
- `is_claimed` is meaningful for `validator` / `project` / `creator`. For `member`, `is_claimed: true` always (members are their own pages).
- `is_claim_verified` (verified-wins slice, 2026-06-30) — `true` when the page has a **verified on-chain operator/creator claim** (`onchain_claims.status='verified' AND claim_role IN ('operator','creator')`), i.e. the real entity proved key control. Distinct from `is_claimed` (ANY claim exists) and from `is_verified`/the member email flag. Drives the "✓ Verified Operator" badge + the dominant search-ranking bonus + same-name look-alike demotion. `false` on `member`/`community` cards (not on-chain-claimed). Projected from the read model (`has_verified_claim`), refreshed whenever a claim verify/revoke re-syncs the page.
- `claim_target` (per §N8) — non-null only when the page is unclaimed AND a claim target resolves. Drives the four-step claim modal.
- `chains` (per §K3) — list of `CardChain` objects when 2+ chains back the same page; `null` otherwise. V1.5 validator-only; creator gallery filter is V2.
- `rank_label` — **populated on `member` cards** (the level-derived Rank label — Apprentice/Journeyman/Master — via `UserViewService::getSummary`); may be `""` when the member has no derived rank yet. `null` on page kinds (`validator`/`project`/`creator`) — Rank is member-only. The field is always present (the union is `string | null`). `current_rank_label` mirrors it (own/profile surfaces).
- `reputation_tier_label` — **honest member trust-tier name** (Risky/Caution/Neutral/Trusted/Proven). Populated on `member` cards; `null` on page kinds (entity cards use `card_tier`/`tier_label` rarity instead). Always present (`string | null`).
- `member_dossier` — **non-null object on `member` cards, `null` on page kinds** (always present for shape uniformity, like `chains`). Carries the back-of-card signal blocks the `/members`, watchers, and followers/following lists previously emitted as a bare `MemberSummary`. Server-composed from the same `UserViewService::getSummary` resolution (no parallel query). Shape:
  ```json
  "member_dossier": {
    "verifications": {
      "x_verified": true,
      "x_username": "blacksmith",
      "github_verified": false,
      "github_username": null,
      "wallets_verified": 3
    },
    "engagement": {
      "endorsements_received": 12,
      "solids_received": 7,
      "reviews_written": 8,
      "disputes_signed": 1
    },
    "owned_pages_by_type": { "validator": 1, "project": 0, "nft": 0, "dao": 0 },
    "primary_local": { "id": 342, "slug": "iron-local-342", "name": "Iron Local 342", "number": 342 }
  }
  ```
  `primary_local` is `null` when the member has no primary Local; `primary_local.number` is `null` when the Local name has no parseable number.
- `card_tier` may be `null` only when the entity is risky-tier (per §C1) — and in that case the entity should not appear in card UIs at all. If a card response returns `card_tier: null`, the frontend renders nothing visible (treat as a 404 from the UI perspective).
- `permissions.can_watch.allowed` is `false` when (a) viewer is anonymous, (b) viewer is the card subject (you can't follow yourself), or (c) the card is hidden (risky tier). In cases (a) and (c), `unlock_hint` is `null` — these aren't hints the user can resolve. (Legacy permission key `permissions.can_watch` is emitted alongside `can_watch` during the §1.1.1 deprecation window.)
- `viewer_has_reviewed` / `viewer_has_endorsed` (per §D2 / §V1.5) — drives "WRITE A REVIEW" → "REMOVE YOUR REVIEW" CTA swaps. Always `false` for anonymous viewers. **v1.49:** `viewer_has_reviewed` is REAL on `member` cards too (has the viewer reviewed this member — a vote on their self-page); the old "always false on member cards" rule is retired. `viewer_has_endorsed` stays `false` on member cards (endorsements target page cards only).
- `review_target_id` (v1.49, **member cards only** — absent on other kinds) — the member's deterministic self-page id (`MemberSelfPageService::selfPageId(user_id)`), i.e. the page member reviews live on. This is the `:id` the frontend passes to `DELETE /me/reviews/:id` to remove its review of the member; the frontend must never derive `ID_BASE` itself (§L5). The WRITE path is unchanged (`POST /posts kind=review target_kind=user_profile target_user_id`).
- `endorse_unlock_hint` mirrors `permissions.can_endorse.unlock_hint` so the EndorseButton can render the hover hint without reaching into the permission object.
- `watching_size` (on member cards' stats and on `User.counts.watching_size`) **counts member follows alongside entity follows.** Watching another member is a first-class watchlist action, no separate `following_count` field exists.

**Deferred to V1.5:**

- `display: { title, subtitle, badge }` — pre-formatted header strings (e.g., `"Validator · Cosmos"`). Reintroduced when a compact card layout needs the kind+chain combo string. V1 surfaces use `name`, `tier_label`, and the chain band derived from `crest.background_kind === "chain"`.
- `chain` (singular slug) and `claimed_by_handle` — superseded by `chains` (plural, §K3) and `claim_target` respectively for V1.
- `updated_at` — read-model recompute timestamp; will return when client-side cache invalidation needs it.

**3.2.1 Entity card stats (validator / project / creator) — V1:**

V1 emits a single shared stat shape for all entity card kinds. On-chain–derived stats (uptime, fee, TVL, etc.) defer until on-chain meta is wired in V1.5.

```json
"stats": [
  { "key": "trust",        "label": "Trust",        "value": "98",  "format": "score", "raw": 98 },
  { "key": "followers",    "label": "Followers",    "value": "142", "format": "count", "raw": 142 },
  { "key": "reviews",      "label": "Reviews",      "value": "8",   "format": "count", "raw": 8 },
  { "key": "endorsements", "label": "Vouches", "value": "12",  "format": "count", "raw": 12 }
]
```

**3.2.2 Member card stats — V1:**

```json
"stats": [
  { "key": "trust",           "label": "Trust",    "value": "78", "format": "score", "raw": 78 },
  { "key": "reviews_written", "label": "Reviews",  "value": "8",  "format": "count", "raw": 8 },
  { "key": "watchers",        "label": "Watchers", "value": "31", "format": "count", "raw": 31 }
]
```

The `watchers` stat is the member's follower count (sourced from the same `UserViewService::getSummary` `followers_count` the `member_dossier` resolution already reads — no extra query), giving the member card the full 3-column stat panel.

**3.2.3 Per-kind stat expansion — Deferred to V1.5:**

When on-chain meta is wired (per §K3 chain support and §H1 indexer), entity cards expand to per-kind stat shapes:

- **Validator:** `trust`, `uptime` (percent), `fee` (percent), `self_bonded` (currency_native), `delegators` (count), `voting_power` (percent)
- **Project:** `trust`, `stage` (text), `tvl` (currency_usd), `contributors` (count), `last_release` (text)
- **Creator:** `trust`, `pieces` (count), `collections` (count), `collectors` (count), `last_drop_at` (duration)
- **Member:** add `rank` (text), `watching_size` (count), `primary_local` (text)

V1 frontend types declare `stats: CardStat[]` (opaque array, no per-kind narrowing). Adding kind-specific stats in V1.5 is purely additive — no breaking change.
```

**3.2.4 Community card (`card_kind: "community"`) — v1.27:**

Communities (PeepSo groups: `nft` / `local` / `system` / `user`) converge onto the Card view-model — same convergence members went through. Community cards are composed server-side from already-loaded group data (`CardViewService::getCommunityCardFromGroupData`, zero extra queries) and ship **additively** as the `card` field on §4.7.4 discovery items and the §4.7.5 detail response. No new endpoints; `GET /cards/*` does not serve them.

Locked field rules:

- **Trust placeholders (shape-stable, never rendered as trust):** communities have no trust system. `trust_score: 0`, `reputation_tier: "neutral"`, `card_tier: "common"`, `rank_label: null`, `is_in_good_standing: true`, `flags: []`. The community face does not render a trust dial; the values exist only to keep the envelope shape-stable.
- **`tier_label` = server-owned group-type kicker** (§L5 — supersedes the frontend `KICKER_BY_TYPE` map): `nft` → `"HOLDERS GROUP"`, `local` → `"LOCAL CHAPTER"`, `system` → `"SYSTEM GROUP"`, `user` → `"COMMUNITY"`.
- **Identity:** `id` = group id, `handle` = group slug, `bio` = group description (plain-text, ~200-char truncation — same bound as page/member bios; `""` when unset).
- **Crest (§2.9 grammar):** `initials` from the group name (same server derivation as the other kinds), `image_url` = the group's cover (NFT collection art; `null` → FE monogram fallback). Background: NFT groups WITH a `chain_tag` → `background_kind: "chain"`, `background_value: <chain_tag>`; otherwise `background_kind: "tier"`, `background_value: "common"`.
- **Stats (same CardStat shape as the other kinds, ≤3):**
  ```json
  "stats": [
    { "key": "members",  "label": "Members",    "value": "87", "raw": 87, "format": "count" },
    { "key": "posts_7d", "label": "Posts (7d)", "value": "14", "raw": 14, "format": "count" }
  ]
  ```
  `posts_7d` is the §4.7.1 activity-heat `posts_last_7d` number (already resolved by both producers — no extra query).
- **Permissions:** the full §3.2 key set (`can_watch`, `can_review`, `can_open_dispute`, `can_endorse`, `can_post_as_entity`, `can_edit_bio`, `can_edit_image`, `can_vouch`, `can_stand_behind`, `can_report`), every entry `{ allowed: false, unlock_hint: null, reason_code: "not_applicable" }` for ALL viewers — anon and authed alike. Communities use JOIN (gated on the group detail's own `permissions.can_join`), never the card verbs; signing in unlocks nothing on the card itself, so no `signin_required` copy is emitted.
- **Always-null/constant envelope fields:** `member_dossier: null`, `chains: null`, `claim_target: null`, `onchain_signals: null`, `social_proof: null`, `viewer_attestation: null`, `viewer_has_reviewed: false`, `viewer_has_endorsed: false`, `endorse_unlock_hint: null`, `is_claimed: true`.
- **`community_dossier`** — the community-only block, mirror of how `member_dossier` works: ALWAYS present on the wire, non-null on community cards, `null` on every other kind (and `member_dossier` is `null` here). Powers the community back face (`collection_stats` replaces FlippableNftCard's bespoke stats display):
  ```json
  "community_dossier": {
    "type": "nft",
    "privacy": "closed",
    "member_count": 87,
    "verification": { "kind": "on_chain", "label": "On-Chain Verified" },
    "chain_tag": "cosmos",
    "trust_min": null,
    "collection_stats": { "...": "§4.7.4 NFT market block, null for non-NFT kinds" },
    "viewer_is_member": true
  }
  ```
  `verification` is the §4.7.x GroupVerification object or `null`; `viewer_is_member` is always `false` for anonymous viewers.
- **Links + actions:** `links: { "self": "/communities/{slug}" }`. `actions` emits **`open` only** — a self-describing GET of the §4.7.5 detail endpoint (`{ "method": "GET", "href": "/wp-json/bcc/v1/groups/{slug}", "idempotent": true, "requires_auth": false }`). No `watch`/`pull` action: groups are joined, not watched.
- **Privacy:** no new privacy logic. The producing endpoints already gate visibility (secret-non-member → absent/404); any group an endpoint returns gets a card.

**3.2.5 Card actions (HATEOAS hints):**

`actions` is a server-authoritative map of API endpoints the client can invoke for gated card mutations. The server owns URL construction (per §A4); the client looks up the endpoint by action key rather than hardcoding a path.

```json
"actions": {
  "watch": {
    "method":        "POST",
    "href":          "/wp-json/bcc/v1/me/watching/watch",
    "body":          { "target_kind": "validator", "target_id": 1842 },
    "idempotent":    true,
    "requires_auth": true
  },
  "claim": {
    "method":        "POST",
    "href":          "/wp-json/bcc/v1/pages/1842/claim",
    "body":          { "entity_type": "validator", "entity_id": 47 },
    "idempotent":    true,
    "requires_auth": true
  }
}
```

**Rules:**

- Action keys are stable identifiers (`watch`, `claim`). Permission to invoke is in `permissions.*`; presence in `actions` does NOT imply the viewer is allowed (gate on `permissions.<key>.allowed`).
- `claim` is omitted when the page has no resolvable underlying on-chain entity. `watch` is always emitted.
- `body` is the request payload template — the client passes it as-is. Servers may add server-only fields (CSRF token, etc.) at request time.
- `idempotent` true means safe to retry on transport error.
- Member cards emit only `watch` (members are not claimable in V1).
- V1 frontend consumers may hardcode endpoint paths; V1.5 will switch to reading `actions[].href` so URL changes don't require frontend deploys.

### 3.3 `FeedItem` (polymorphic)

Feed items share an envelope and vary by `post_kind`.

**Shared envelope (V1):**

```json
{
  "id": "feed_98712",
  "external_id": 451208,
  "post_kind": "review",
  "posted_at": "2026-04-27T11:14:00Z",
  "scope_tags": ["for_you", "following"],
  "author": {
    "user_id": 42,
    "handle": "simontx",
    "display_name": "Simon TX",
    "avatar_url": "https://bluecollar.crypto/wp-content/uploads/2026/04/simontx-avatar.jpg",
    "rank_label": "Journeyman",
    "reputation_tier": "trusted",
    "is_operator": false,
    "viewer_attestation": { "vouch": { "id": 5012, "created_at": "2026-06-20T09:00:00Z" }, "stand_behind": null },
    "can_vouch": { "allowed": true, "unlock_hint": null }
  },
  "body": { "...": "kind-specific" },
  "attached_card": { "...": "summary Card view-model, optional" },
  "reactions": { "...": "see §2.11" },
  "comment_count": 7,
  "social_proof": { "...": "see §2.2 — applies to feed posts per §O4" },
  "permissions": {
    "can_report": { "allowed": true, "unlock_hint": null }
  },
  "links": {
    "self":   "/u/simontx/post/aBcDeFgH",
    "author": "/u/simontx"
  },
  "group": {
    "id": 4231,
    "type": "nft",
    "verification": { "kind": "on_chain", "label": "On-Chain Verified" }
  }
}
```

**Field rules:**

- `id` is a string in the form `feed_<int>` (the underlying activity ID is opaque to the client — using a string lets us migrate if needed).
- `external_id` is the module-specific FK (e.g. `wp_posts.ID` for status / blog / review backings, `bcc_watch_batches.id` for `watch_batch` posts (table name retains its legacy `watch_batches` form per §4.5.1), `bcc_onchain_claims.id` for `page_claim`, `0` for system-authored signals). Used by server-side hydrators and by the client as a stable React key. Treat as opaque.
- `scope_tags` lists which feed-mode tabs (§N6) this post is eligible for. Used for client-side optimistic filtering when switching tabs without refetching.
- `comment_count` is the number of visible (non-trashed) comments on the post at response-time. Server-computed via one batched COUNT(*) GROUP BY across the page. Clients MUST treat this as a count badge — the actual list is fetched separately via §4.13. Always present; `0` when there are no comments.
- `group` is **omitted** (not `null`) when the post does NOT come from a PeepSo group. When present, the post is a wall post inside a group:
  - `id` — group_id (matches `group_id` in §4.7.x endpoints).
  - `type` ∈ `nft` | `local` | `user` | `system` — matches §4.7.2 group `type`.
  - `verification` is `null` for non-NFT groups; for NFT-gated groups it carries `{kind: 'on_chain', label: 'On-Chain Verified'}`. Frontend MUST render `label` verbatim — never abbreviate to "Verified" alone.
  - **No server-side ranking is applied based on this field in v1.** The Floor feed continues to order strictly by recency. The `group` block is metadata for badge rendering and (optional) client-side prioritization. A scored ranking layer is deferred until usage telemetry exists to tune it honestly.
  - **Mapping:** `peepso_group_id` post-meta on the activity's wp_post (PeepSo writes this when a status post is created inside a group) → `GroupContextResolver::forManyGroups`. Batched per page; no N+1.
- V1 author block is **user-only** — every post in V1 is authored by a WP user (status, review, watch_batch, page_claim, dispute_signed, blog). System-emitted signals (§3.3.5) currently ride the same shape with the system actor's user_id; their `post_kind` discriminates them, not the author block.
- `author.is_operator` — true when the author holds a verified operator/creator claim on any entity (per §N8). Drives the OPERATOR chip next to the author name.
- `author.viewer_attestation` + `author.can_vouch` — **authed-only**, the per-author **Vouch toggle** state next to the byline (vouch is *author credibility*, not a post reaction). `viewer_attestation` is the viewer's own active attestation on this author's self-page (`{ vouch: {id,created_at}|null, stand_behind: {id,created_at}|null }`, reused from the §3.2 card shape); `can_vouch` is `{ allowed, unlock_hint }` (self/below-Neutral come back `allowed=false`). **Both fields are OMITTED for anonymous viewers** (the anon feed payload is unchanged; the sign-in CTA lives on the profile cluster, not every byline). Because the vouch targets the *author* (one vouch per person, full-weight via `POST /bcc/v1/me/attestations` `{kind:'vouch', target_kind:'user_profile', target_id: author.id}`), the toggle reads identically wherever that author appears — feed bylines and commenter names alike. Batched server-side across the page's distinct authors (no N+1).
- `attached_card` is omitted (not `null`) when no card is attached.

**Deferred to V1.5 — author block expansion:**

When entity-as-author posts ship (§D3 identity-toggle), the author block gains a `kind` discriminator and the polymorphic shape:

```jsonc
"author": {
  "kind": "user" | "entity" | "system",
  "id":   42,                    // replaces user_id; polymorphic across kinds
  "handle": "simontx",
  "display_name": "Simon TX",
  "avatar_url": "https://...",
  "card_tier": "rare",           // tier badge next to author name
  "rank_label": "Journeyman",
  "is_in_good_standing": true,   // standing chip on posts
  "is_followed_by_viewer": false // inline Following chip
}
```

V1.5 transition rules: clients tolerant of unknown fields ride forward without changes; `user_id` remains emitted alongside `id` for one minor version, then is removed.

**Post-kind variants (the `body` shape):**

**3.3.1 `status`** — D2 free-text post (≤ 500 chars):

```json
"body": {
  "text": "Just claimed my page. Going to write up uptime numbers tonight.",
  "embeds": [],
  "mentions": []
}
```

`mentions` is the §3.3.12 `Mention[]` overlay — array of `{user_id, handle, display_name, avatar_url, range: [start, end]}` extracted from the raw `text` at write-time. Always present (`[]` when no mentions).

**3.3.2 `review`** — D2 review of an attached target (entity card or member):

```json
"body": {
  "grade": "trust",
  "text": "Reliable through the last upgrade. Governance participation has been consistent.",
  "page_id": 412,
  "page_handle": "acme-validator",
  "page_name": "Acme Validator",
  "page_kind": "validator"
}
```

`grade` ∈ {`trust`, `neutral`, `caution`} (from `vote_type` 1/0/-1). `text` is the review body (≤ 4000 chars; falls back to the short `reason` slug for bare-vote rows). `page_kind` ∈ {`validator`, `project`, `creator`, `member`, `""`} and drives the target link prefix (`/v`|`/p`|`/c`|`/u`; `""` → no link).

Member-target rows (v1.48): `page_id` is the member's **self-page id** (`MemberSelfPageService::selfPageId(user_id)`), `page_name` is the member's display name, and `page_handle` is their `bcc_handle` — `""` when unset (the frontend suppresses the link; `user_login`/nicename are never projected).

> Contract-drift note (fixed in v1.48): earlier revisions documented `{grade: A–F, grade_label, summary, long_form}` — that shape never shipped. The block above is what `ReviewBodyHydrator` has emitted since the feed brain landed.

**3.3.3 `watch_batch`** — C3 batched watches (legacy kind name `watch_batch` accepted during the §1.1.1 deprecation window):

```json
"body": {
  "card_count": 5,
  "summary_text": "Simon started watching 5 cards",
  "top_cards": [
    { "...": "summary Card view-model, max 3" }
  ],
  "more_count": 2,
  "batch_id": "batch_abc123",
  "frozen": true
}
```

**Rules (§C3):**
- Batches close after exactly **10 minutes of watch inactivity**. At close, the server emits exactly one `watch_batch` FeedItem. (Pre-existing `peepso_activities` rows authored before the rename carry the legacy `act_module_id` value `pull_batch`; the bcc-core normalizer maps them to the canonical `watch_batch` post_kind on read.)
- `top_cards` contains a **maximum of 3** summary Card view-models. If the batch has more than 3 cards, `more_count = card_count - 3` (always, never paginated, never expandable in V1).
- `frozen: true` is always true once posted. There is no other value in V1.
- If the user later stops watching cards in this batch, the batch post does NOT update. `card_count`, `top_cards`, `more_count` reflect the batch at the moment of posting.
- The watchlist UI (separate from the feed) reflects unfollows immediately. Feed and watchlist are not synchronized after the post.

**3.3.4 `dispute_signed`** — D2 dispute signing:

```json
"body": {
  "dispute_id": 71,
  "dispute_title": "Validator went offline during upgrade window",
  "subject_card": { "...": "summary Card view-model" },
  "signer_count": 14,
  "summary": null
}
```

**3.3.5 `signal`** — system-emitted on-chain event:

```json
"body": {
  "signal_kind": "uptime_drop",
  "headline": "Blacksmith Node uptime dropped to 96.2%",
  "subject_card": { "...": "summary Card view-model" },
  "delta_label": "−3.7% in 24h",
  "severity": "warn"
}
```

`signal_kind` ∈ {`uptime_drop`, `uptime_recover`, `slashing`, `commission_change`, `governance_vote`, `delegation_milestone`, `validator_jailed`, `validator_unjailed`, `nft_mint`, `nft_transfer`}. `severity` ∈ {`info`, `warn`, `urgent`}.

**3.3.6 `project_drop`** — entity-authored project release:

```json
"body": {
  "release_label": "v0.9.4",
  "title": "Foundry v0.9.4 — staking weight rebalance",
  "summary": "Fixed the bug where validator-set transitions skipped the cooldown.",
  "release_url": "https://github.com/.../releases/tag/v0.9.4"
}
```

**3.3.7 `nft_drop`** — creator-authored drop:

```json
"body": {
  "title": "Foundry #042 — Hot Iron",
  "edition_count": 100,
  "primary_price_usd": "$120",
  "drop_image_url": "/wp-content/uploads/2026/04/foundry-042.jpg",
  "mint_link": "/c/welder?mint=042"
}
```

**3.3.8 `blog_excerpt`** — §D6 long-form post. Same FeedItem shape in
two rendering contexts (Floor vs. Blog tab). Body shape stabilized
2026-05-15 by the V1 crypto-blog composer:

```json
"body": {
  "title": "Why I'm rotating out of Cosmos validators",
  "excerpt": "The set is becoming concentrated. Three operators run 41% of voting power…",
  "full_text": "The set is becoming concentrated. Three operators run 41% of voting power, and the upcoming upgrade narrows the active set further.\n\n## What changes\n\n…",
  "wp_post_id": 4929,
  "category": "analysis",
  "tags": ["staking", "decentralization"],
  "chain_tags": [
    {"id": 8, "slug": "cosmos", "name": "Cosmos Hub", "color": null, "icon_url": null},
    {"id": 10, "slug": "akash",  "name": "Akash",      "color": null, "icon_url": null}
  ],
  "disclosure": {
    "tickers": ["ATOM", "AKT"],
    "note": "Author holds ATOM and AKT."
  },
  "cover_image_url": "https://example.com/wp-content/uploads/2026/05/cover.jpg"
}
```

**Rules (§D6):**

- `title` is author-supplied (≤ 120 chars), rendered as the stencil
  headline on both surfaces.
- `excerpt` is the author-written Floor teaser (80–500 chars,
  enforced server-side via `BLOG_EXCERPT_MIN_LENGTH` /
  `BLOG_EXCERPT_MAX_LENGTH`). NOT auto-derived from the body — the
  writer picks the hook. Rendered when this FeedItem appears in
  **Floor contexts** (`/feed`, `/feed/hot`).
- `full_text` is the complete post body (markdown source, capped at
  `BLOG_FULL_TEXT_MAX_LENGTH = 60_000`). Rendered when this FeedItem
  appears in **Blog tab contexts**
  (`/u/:handle?tab=blog`, `/v/:slug` blog sub-tab if applicable).
- **Floor context responses ship `full_text: ""`** — the server
  deliberately strips the body to keep Floor payloads small
  (20 items × 60KB body would be ~1.2MB). `BlogService::hydrateForPostId`
  accepts an `$includeFullText` flag wired to `false` from the Floor
  hydrator. Blog-tab context responses ship the full body.
- **Same `post_kind`, same FeedItem, two rendering surfaces** —
  there is **no separate blog post type or CPT** (§D6). Blog posts
  live as `peepso-post` wp_posts with `_bcc_activity_module='blog'`
  on the backing wp_post and `act_module_id = 204` on the
  `peepso_activities` row (per
  `PeepSoActivityWriter::MODULE_ID_BY_NAME`).
- `category` is one of `news | analysis | guide | opinion | tools |
  events` (the `BlogCategory` enum). May be `null` on pre-V1 posts
  that lack the meta.
- `tags` is a `string[]` of 0..`BLOG_TAGS_MAX` free-form pills
  (lowercase, alnum-dash, ≤ `BLOG_TAG_LEN_MAX` chars each). `[]`
  when absent.
- `chain_tags` is a `list<{id, slug, name, color, icon_url}>` of
  0..`BLOG_CHAIN_TAGS_MAX` chain references resolved server-side
  against `bcc_onchain_chains`. `[]` when absent. `color` and
  `icon_url` are nullable (admin-curated).
- `disclosure` is `null` (no disclosure declared) or
  `{tickers: string[], note: string}`. Empty struct
  (`{tickers: [], note: ""}`) is rejected at write-time as
  `bcc_invalid_request` — send `null` to clear instead.
- `cover_image_url` is the URL of the WP attachment pinned via
  `set_post_thumbnail`. `null` when no cover is set.
- `wp_post_id` is the canonical wp_post identifier; clients use it
  for the `PATCH /posts/{id}` edit path.

**Blog-edit view-model (`GET /posts/{id}`)** — owner-only edit-read
backing the composer's `?edit=<id>` cold-load / deep-link path (added
2026-06-04). Returns the §3.3.8 body fields **flat** (not wrapped in a
FeedItem) plus a `status` field:

```json
{
  "title": "Why I'm rotating out of Cosmos validators",
  "excerpt": "The set is becoming concentrated…",
  "full_text": "The set is becoming concentrated…",
  "wp_post_id": 4929,
  "category": "analysis",
  "tags": ["staking", "decentralization"],
  "chain_tags": [
    {"id": 8, "slug": "cosmos", "name": "Cosmos Hub", "color": null, "icon_url": null}
  ],
  "disclosure": {"tickers": ["ATOM", "AKT"], "note": "Author holds ATOM and AKT."},
  "cover_image_url": "https://example.com/wp-content/uploads/2026/05/cover.jpg",
  "cover_image_id": 4930,
  "sources": ["https://example.com/source"],
  "status": "publish"
}
```

- `status` is `"draft" | "publish"`. **Drafts are returned to their
  author** — a draft has no `peepso_activities` row so it never appears
  on `/users/:handle/blog`; this read is the only way to hydrate one for
  editing.
- **Not a FeedItem** — no reactions / social_proof / permissions /
  links. The composer only consumes body fields + status; the full
  FeedItem path can't represent a draft (no activity row) anyway.
- Auth: signed-in (`current_user_can('read')`); the service enforces
  `post_author === viewer`. Errors: `bcc_forbidden` (not the author),
  `bcc_not_found` (missing, or not a `_bcc_activity_module='blog'`
  `peepso-post`). Same identify + ownership guards as `PATCH /posts/{id}`.

**3.3.9 `photo`** — v1.5 photo post. Single image per post, optional caption. Underlying storage is PeepSo's photo module (act_module_id = 4), so multi-photo / album / GIF backgrounds inherit any future PeepSo capability without a contract change.

**Body shape:**

```json
{
  "post_kind": "photo",
  "body": {
    "caption":   "morning at the conference floor.",
    "photo_url": "https://bluecollar.crypto/wp-content/peepso/users/42/photos/3a7c…b9.jpg",
    "alt":       "Phillip standing under the BCC banner holding the v1.5 demo board.",
    "mentions":  []
  }
}
```

**Rules:**

- `caption` is `string | null`. `null` when the user posted photo-only (no text). Server-sanitized identically to status post bodies (PeepSo's `htmlspecialchars + strip_content`). Cap: 500 chars after trim.
- `photo_url` is the canonical full image URL. Empty string `""` on degraded reads (S3-only photos with no fallback URL, or a race where the activity row landed before `save_images` finished); the frontend gracefully omits the image when the URL is empty.
- `alt` is `string | null`. The author-supplied screen-reader description for the photo, written in the composer at upload time and editable via §4.18 `PATCH /photos/:pho_id/alt` after the fact. Stored in BCC's `bcc_photo_alts` sidecar (PeepSo's `peepso_photos` has no native alt column). Server-sanitised: HTML stripped, whitespace trimmed and collapsed, hard length cap of 500 chars. `null` when the photo has no alt row (legacy uploads pre-§3.3.9 alt write, or a user who didn't fill the field). **Frontend MUST render `<img alt="">`** when `alt` is `null` so the photo is treated as decorative; when `alt` is a non-empty string, render `<img alt={alt}>` — that string IS the photo's accessible name.
- `mentions` is the §3.3.12 `Mention[]` overlay extracted from `caption`. Range offsets reference the raw stored caption, not post-render content (§3.3.12 invariant). Always present (`[]` when no mentions, including photo-only posts where caption is `null`).
- Allowed mime types at upload time: `image/jpeg`, `image/png`, `image/webp`, `image/gif`. The hard size cap is 5 MB (matches the avatar/cover validator).
- **V1 deferred** (separate from now-shipped alt): multi-photo posts (`files: string[]`), photo-edit, lightbox/zoom render, S3-stored photo URLs. The body shape is stable; future fields will be additive.

**3.3.10 `gif`** — v1.5 GIF post. The user picked a GIF from the composer's Giphy picker; PeepSo's existing giphy plugin owns the API key + content rating + post_meta storage. `act_module_id` is `1` (status) at the activity layer; the `gif` post_kind is resolved by **metadata semantic override** in the feed body hydrator (see §3.3.11 below).

**Body shape:**

```json
{
  "post_kind": "gif",
  "body": {
    "caption":  "this is the energy.",
    "gif_url":  "https://media.giphy.com/media/3oEjI6SIIHBdRxXI40/giphy.gif",
    "provider": "giphy",
    "mentions": []
  }
}
```

**Rules:**

- `caption` is `string | null`. `null` when GIF-only (no text). Same shape as photo caption — 500-char cap, server-sanitized.
- `gif_url` is the canonical Giphy CDN URL. The GIF lives on Giphy's infrastructure; BCC stores only the URL via PeepSo's `peepso_giphy` post_meta. Frontend renders `<img src={gif_url}>` directly.
- `provider` is `"giphy"` in V1. Field is forward-stable; future providers (Tenor, custom sticker packs) will extend this enum. Frontend MAY use this for "Powered by …" attribution if it surfaces provider branding on the rendered card (V1 does NOT — attribution lives only inside the picker).
- `mentions` is the §3.3.12 `Mention[]` overlay extracted from `caption`. Range offsets reference the raw stored caption, not post-render content (§3.3.12 invariant). Always present (`[]` when GIF-only or no mentions in caption).
- The GIF post_kind is **enabled-gated** — PeepSo admin's `giphy_posts_enable` toggle controls whether the composer surfaces a GIF picker affordance at all. The integration config is exposed at §4.16 `GET /integrations/giphy`.
- **V1 deferred:** GIF in comments (`/posts/:feed_id/comments` would need its own giphy support — separate slice), GIF stickers in chat, multi-GIF posts, GIF-on-photo overlays. All deliberately out of scope.

**3.3.11 — post_kind precedence rules.** A FeedItem's `post_kind` is resolved in two layers:

1. **Module-default mapping** — `FeedItemNormalizer::MODULE_TO_KIND[act_module_id]` assigns the base kind from the stored PeepSo module ID (status=1, photo=4, etc.).
2. **Metadata semantic override** — the body hydrator may *promote* a base kind to a more specific semantic kind when discriminating post_meta is present. Example: a status post (kind=1, base=`status`) with `peepso_giphy` post_meta is promoted to `gif`.

**Metadata overrides take precedence over module defaults.** This is the canonical rule for future kinds (poll, mood, celebration, scheduled-state overlays) that need similar semantic discrimination — they extend the override layer rather than inventing parallel pipelines or polluting `MODULE_TO_KIND` with meta-aware entries.

**3.3.12 `Mention` (v1.5)** — semantic overlay used by `status.body.mentions`, `photo.body.mentions`, `gif.body.mentions`, and `Comment.mentions` (§3.5). One entry per resolved `@user` reference embedded in the post's raw text/caption.

```json
{
  "user_id":      42,
  "handle":       "simontx",
  "display_name": "Simon TX",
  "avatar_url":   "https://bluecollar.crypto/wp-content/uploads/peepso/users/42/abc-avatar.jpg",
  "range":        [12, 38]
}
```

**Field rules:**

- `user_id` — the resolved WP user_id of the mentionee. Stable across handle changes.
- `handle` — the mentionee's BCC handle at *response time*. Frontends route to `/u/:handle` for the link target. Re-resolved per-response so a handle change shows up after the mention was emitted.
- `display_name` — the mentionee's display name at *response time*. Re-rendered (not the literal `(<name>)` token captured at write-time) so a renamed user reads correctly in old posts.
- `avatar_url` — for hovercard / popover usage. Frontends MAY display.
- `range` — `[start, end]` byte offsets into the **raw stored body text** (`status.body.text`, `photo.body.caption`, `gif.body.caption`, or `Comment.body`). End-exclusive (`text.substring(start, end)` == the wire-format token `@peepso_user_<id>(name)`).

**§3.3.12 INVARIANT — range offsets are authoritative against RAW stored content, never post-render content.**

The wire format on disk is PeepSo's mention shortcode `@peepso_user_<id>(<name>)`. Frontends overlay `mentions[]` on top of the raw text — they MUST NOT first apply markdown/emoji/embed/contentEditable transforms before slicing on `range`. Future formatting layers (markdown post-processing, emoji shortcode replacement, rich embed insertion, link autodetection, contentEditable upgrades) MUST not shift these offsets — they apply *around* the mention spans, not through them. If a future layer needs offsets-against-rendered-content, it MUST emit a separate `rendered_mentions[]` block; the canonical `mentions[]` always references raw stored offsets.

**Render contract:**

- A frontend rendering raw text + `mentions[]` walks the text, slices at each `range`, and replaces the slice with `<Link href="/u/${handle}">@${display_name}</Link>` (or equivalent platform-specific affordance — native iOS/Android tap targets, hovercard popovers, etc.).
- The `@peepso_user_<id>(name)` wire token MUST NOT be rendered as-is; it is a server-side write-format only. A response missing `mentions[]` for content that contains the token is a server bug — clients MAY render the literal token rather than parse it themselves.
- Mentions outside the body's text field (e.g., a photo post with no caption) MUST emit `mentions: []` for shape stability.

**Server-side enforcement (write-time):**

- Mentions are extracted via the canonical regex `@peepso_user_([a-z]*\d+)(?:\(([^\)]+)\))?` ([peepso/classes/tags.php#L77](app/public/wp-content/plugins/peepso/classes/tags.php) — same regex PeepSo's notification dispatcher uses).
- Each candidate user_id is validated against the `MentionPolicy` privacy filter (PeepSo's ban filter + `profile_acc != PRIVATE` + bidirectional `peepso_user_blocked` + `allow_hide_user_from_user_listing` + BCC's `bcc_privacy_discovery_optout`).
- Failing the policy returns `bcc_invalid_mention_target` (with the offending `user_id` echoed in the error payload, but NOT the reason — privacy posture preserved). The post is rejected; nothing is written. Strict reject, not silent strip.
- Cap: **10 mentions per post**. Over-cap returns `bcc_too_many_mentions` (echoes the cap as `max`).

**Notification semantics — V1d ships a single mention notification type.** PeepSo's existing `Tags::after_save_post` dispatcher fires `tagged` notifications for every surviving (post-policy) mention in a status/photo/gif body. The post-V1 layered grammar (status vs dispute vs review distinct mention semantics) is **deferred** — when `kind_grammar` (§reactions) generalizes to mentions, this section grows a `kind_grammar` discriminator on `Mention`. V1d clients ride forward on additive fields.

**V1d scope:**

- Composer-only. Comments inherit the wire format (PeepSo's `Tags::after_save_comment` runs on the same regex) but the V1d comment composer does NOT ship the autocomplete picker UI — typing the wire token by hand still works because the server validation runs identically. The `Mention[]` overlay is emitted for comments per §3.5.
- Pre-type "recent contacts" candidates, follow-bias ranking, and contentEditable token highlighting are deferred. V1d ships a prefix-search dropdown only (no candidates surfaced when the query is empty).

### 3.4 `HighlightStrip`

Encodes §O2 + §O2.1. Strict slot ordering: negative → positive → external.

**Shape:**

```json
{
  "highlights": [
    {
      "id": "h_uptime_alert_4471",
      "slot": 1,
      "category": "negative",
      "title": "Blacksmith Node uptime dropped to 96.2%",
      "body": "−3.7% in the last 24h. You're watching this validator.",
      "cta": { "label": "View record", "href": "/v/blacksmith-node" },
      "severity": "warn",
      "source_event_id": "signal_evt_882012",
      "score": 0.92,
      "dismissable": true,
      "dismiss_kind": "state_bound"
    },
    {
      "id": "h_milestone_31423",
      "slot": 2,
      "category": "positive",
      "title": "Your card moved to Rare",
      "body": "Reputation tier upgraded based on this week's activity.",
      "cta": { "label": "See your card", "href": "/u/simontx" },
      "severity": "info",
      "source_event_id": "tier_evt_31423",
      "score": 0.74,
      "dismissable": true,
      "dismiss_kind": "ttl_24h"
    }
  ]
}
```

**Rules (§O2.1):**

- 0–3 items only. Always in slot order: 1 (negative), 2 (positive), 3 (external).
- Empty slots **collapse** — the array contains 0–3 items, never padded with `null`.
- Server picks the top item per category (one scorer each); never re-shuffles.
- `score` is the float (0.0–1.0) the per-category scorer assigned this item before it was selected as the slot winner. Surfaced for ranking visibility and debugging — the frontend MUST NOT use it for sort/filter logic (the server already chose the winner). Useful in `_debug` views and as telemetry input.
- `dismiss_kind` ∈ {`state_bound`, `ttl_24h`}. State-bound (§O2 negative TTL rule) means the highlight reappears when `source_event_id` updates (e.g., uptime drops further). The frontend doesn't enforce TTL — it just calls `POST /me/highlights/:id/dismiss`, and the server applies the right TTL based on category.
- Anonymous viewers receive **`401 bcc_unauthorized`** from `GET /me/highlights` — the strip is authenticated-only by design. The frontend hides the entire HighlightStrip component for unauthenticated viewers; nothing is rendered.

### 3.5 `Comment` (v1.5)

The `Comment` view-model used by §4.13 endpoints. One row per visible comment on a `FeedItem`.

**Shape:**

```json
{
  "id":         "comment_2210184",
  "comment_id": "comment_2210184",
  "feed_id":    "feed_2210184",
  "author": {
    "id":           42,
    "handle":       "simontx",
    "display_name": "Simon TX",
    "avatar_url":   "https://bluecollar.crypto/wp-content/uploads/2026/05/simontx-avatar.jpg",
    "viewer_attestation": { "vouch": null, "stand_behind": null },
    "can_vouch":    { "allowed": true, "unlock_hint": null }
  },
  "body":      "love this — finally a watchlist that respects watches.",
  "mentions":  [],
  "posted_at": "2026-05-06T14:09:33Z",
  "permissions": {
    "can_delete": { "allowed": true, "unlock_hint": null }
  },
  "stoke_count": 0,
  "viewer_has_stoked": false,
  "media": { "kind": "photo", "url": "https://bluecollar.crypto/wp-content/uploads/2026/07/jobsite.jpg", "width": 1280, "height": 960 },
  "parent_id": null,
  "reply_count": 3
}
```

**Field rules:**

- `id` and `comment_id` are the same opaque identifier (the duplicate `comment_id` is for symmetry with §4.13 DELETE which takes the id as a path param). Form: `comment_<int>`. Treat as opaque.
- `feed_id` echoes the **parent post's** feed_id, not the comment's own — useful for re-resolving the parent if the drawer was deep-linked. Form: `feed_<int>`.
- `body` is server-sanitized (PeepSo's `htmlspecialchars` + `strip_content`); the frontend SHOULD render plain text only and respect newlines but NOT trust HTML entities. The raw wire format `@peepso_user_<id>(name)` may appear in `body`; clients MUST overlay `mentions[]` to render those tokens as `<Link href="/u/:handle">@displayName</Link>` — see §3.3.12 invariant.
- `mentions` is the §3.3.12 `Mention[]` overlay extracted from the raw `body`. Range offsets reference raw stored content. Always present (`[]` when no mentions). V1d does NOT ship the comment-composer autocomplete picker — the array is still populated for any wire tokens authored via PeepSo's native UI or hand-typed by power users.
- `posted_at` is ISO-8601 UTC.
- `permissions.can_delete.allowed` is `true` only when the viewer is the comment's author. V1 does not support cross-author or admin moderation deletes through this endpoint.
- `author.viewer_attestation` + `author.can_vouch` — **authed-only**, identical shape + semantics to the §3.3 feed-item author block: the per-author **Vouch toggle** behind the commenter's name (vouch is author credibility, one vouch per person, full-weight via `POST /bcc/v1/me/attestations`). **Both OMITTED for anonymous viewers.** The same author vouched from a feed byline reads as already-VOUCHED here (and vice-versa) — one vouch, one weight, everywhere. Batched server-side across the page's distinct comment authors (no N+1).
- `stoke_count` + `viewer_has_stoked` (v1.38) — the comment's **Stoke** pair, a plain X-"like" toggle (NO `heat_stage`: a comment is not the post rail's velocity signal). `stoke_count` is the public total (present for anonymous viewers too); `viewer_has_stoked` is the viewer's own toggle (`false` when anonymous). Both **additive-optional**: a pre-1.2.22 backend omits them, and the frontend hides the comment's stoke rail when absent rather than posting to a route that doesn't exist. Batched server-side across the page's comment act_ids (two bounded IN-list reads — no N+1). Toggled via §4's `POST`/`DELETE /comments/:id/stoke`.
- `media` (v1.41) — the comment's single attachment: `{kind: "photo"|"gif", url}`, with `width`/`height` riding on `photo` only (from the attachment metadata; may be `0` when unknown — treat 0 as absent). One photo XOR gif per comment, set at create time via §4.13 `attachment_id`/`gif_url`; no post-hoc attach/detach (delete + recreate, matching the comment edit model). **Additive-optional:** absent on text-only comments and from pre-1.2.26 backends — render the row unchanged when absent. `photo` URLs are same-site WP uploads; `gif` URLs are Giphy-CDN (`giphy.com` host-validated server-side). Render both as plain `<img>`; the internal WP `attachment_id` is never emitted on the wire. Batched per page (one bounded IN-list post-meta read — no N+1). Media is stored as a sidecar on the comment's own wp_post, so a deleted comment takes its media record with it.
- `parent_id` + `reply_count` (v1.42) — the comment's **threading** pair. `parent_id` is the `comment_<int>` id of the comment this is a reply to, or `null` for a top-level comment; it always uses the same id form as `id`, so the frontend threads the flat page client-side. `reply_count` is the number of **DIRECT** replies to this comment (not the whole subtree) — it drives the "Follow the thread" drill control once a branch passes the visual-indent cap. **Additive-optional:** a pre-1.2.27 backend omits both, so every row reads as a root and the drawer renders the flat list unchanged; absent `reply_count` is treated as `0`. The list endpoint keeps returning the flat paginated page — threading is a client-side transform over the whole loaded set, and a reply whose parent isn't in the loaded pages surfaces at root (orphan-safe, nothing disappears). Set at create time via §4.13 `parent_id`; stored as a sidecar on the reply's own wp_post (deleted with the comment). `reply_count` is batched per page (one bounded IN-list post-meta read — no N+1).

**V1 deferred:**

- **Deeper threading loads.** Comment threading (parent link + direct-reply count) ships v1.42 — see `parent_id`/`reply_count` above. What remains deferred: per-subtree pagination (a `?parent=` load for a branch that outgrows the flat page) and a shareable `?thread=<id>` deep-link. The v1 drill-down is a client-side re-root over the already-loaded flat set.
- **Per-comment reactions.** No §2.11 reaction-kind rail on individual comments — reaction kinds remain on the parent post only. V2. (Comment **Stoke** is NOT this: it shipped v1.38 as its own `wp_bcc_trust_stokes`-backed like-toggle, see `stoke_count` above.)
- **Edit.** No edit endpoint. Delete + recreate is the V1 model.

### 3.6 `IndexerState` (V2 Phase 1c)

A `meta.indexer_state` + `meta.indexer_state_label` pair appears on any response whose data is sourced from the V2 confirmation-gated NFT indexer (Phase 1a/1b). Frontends use it to render a "Syncing…" affordance when the persistent path was bypassed for an indexer reason — never invent the chip copy client-side; render the server-pre-formatted label verbatim.

**Shape:**

```json
{
  "meta": {
    "indexer_state": {
      "ethereum": "healthy",
      "solana":   "syncing"
    },
    "indexer_state_label": {
      "ethereum": "",
      "solana":   "Syncing on-chain holdings…"
    }
  }
}
```

**Field rules:**

- `indexer_state[chainSlug]` is one of `"healthy" | "syncing" | "degraded"`.
  - `healthy` — persistent rows for at least one wallet on this chain were used. No chip.
  - `syncing` — checkpoint was healthy but the wallet has no enriched rows yet (cold-start), OR the chain checkpoint is in `disabled` / not-yet-initialised state. Soft chip.
  - `degraded` — the chain checkpoint is in `degraded` or `breaker_open` state. Show chip with the "showing cached holdings" copy so users know the data is older than usual.
- A chain whose state escalates monotonically across the user's wallets (one wallet healthy, another syncing) reports the worst state. Per-wallet drilldown is not exposed in v1; operator surfaces in wp-admin show the full per-wallet detail.
- `indexer_state_label[chainSlug]` is the rendered chip copy. Empty string = "do not render a chip." Filterable server-side via the `bcc_holdings_indexer_state_label` WP filter so copy can be tuned without a redeploy.
- A response whose data did not pass through the indexer (e.g., a per-creator `/creators/:slug/gallery` reading `bcc_onchain_collections` directly) does NOT carry these fields. The block is per-endpoint, not global.

**Endpoints surfacing `meta.indexer_state` (V2 Phase 1c):**

- `GET /bcc/v1/nft-selections/picker` — through `NftSelectionService::buildPickerData → HoldingsService::getForUser`.

When future endpoints surface user-scoped holdings reads, they MUST forward this block from `HoldingsService` rather than reconstruct it locally.

### 3.7 `NftPiece` (V2 Phase 6 / §H1)

Per-piece detail view-model used by §4.17. One row per uniquely-identified NFT, addressed by `(chain_slug, contract_address, token_id)`. Promotes the §8 deferred `GET /collections/:id/pieces` Phase-6 placeholder to a real V2 surface now that the on-chain indexer (Phase 1a/b/c) is online.

**Shape:**

```json
{
  "id":           "nft_piece_eth_0x1a2b3c_042",
  "collection": {
    "id":               187,
    "name":             "Welder Genesis",
    "creator_handle":   "welder",
    "chain_slug":       "ethereum",
    "contract_address": "0x1a2b3c4d5e6f7890abcdef1234567890abcdef12",
    "token_standard":   "ERC-721",
    "is_verified":      true
  },
  "token_id":     "042",
  "name":         "Welder Genesis #042",
  "description":  "The forty-second piece in the Welder Genesis drop.",
  "image_url":    "https://cdn.bluecollar.crypto/nft/eth/0x1a2b3c.../042.png",
  "image_url_thumb": "https://cdn.bluecollar.crypto/nft/eth/0x1a2b3c.../042_thumb.png",
  "attributes": [
    { "trait_type": "Background", "value": "Workshop",   "rarity_pct": 12.5 },
    { "trait_type": "Tool",       "value": "MIG Welder", "rarity_pct": 4.2  }
  ],
  "owner": {
    "wallet_address": "0xabcdef0123456789abcdef0123456789abcdef01",
    "address_short":  "0xabcd…ef01",
    "balance":        1,
    "is_linked":      true,
    "user": {
      "id":           42,
      "handle":       "simontx",
      "display_name": "Simon TX",
      "avatar_url":   "https://bluecollar.crypto/wp-content/uploads/2026/05/simontx-avatar.jpg"
    }
  },
  "owners_count":      1,
  "owners":            [],
  "marketplace_links": [
    { "name": "OpenSea", "url": "https://opensea.io/assets/ethereum/0x1a2b3c.../42" }
  ],
  "mint_link":   "/c/welder?mint=042",
  "permissions": {},
  "meta": {
    "read_time":           false,
    "indexer_state":       { "ethereum": "healthy" },
    "indexer_state_label": { "ethereum": "" },
    "owners_summary_label": null
  }
}
```

**Field rules:**

- `id` is opaque, prefixed `nft_piece_<chain>_<short-contract>_<tokenId>`. Frontend treats it as a string; routing uses the `(chain_slug, contract_address, token_id)` triple from `collection` + `token_id`.
- `collection` is an embed, NOT the full §3.2 Card. It carries only the fields the piece-detail view needs to render breadcrumbs and link back to the creator. For the full creator card the frontend resolves `/c/{collection.creator_handle}` via §4.2.
- `collection.token_standard` ∈ {`ERC-721`, `ERC-1155`, `SPL`, `CW-721`}. Surfaced for breadcrumb labelling only — the frontend MUST NOT branch presentation on this raw enum (use `meta.owners_summary_label` for the multi-holder render branch instead).
- `collection.is_verified` mirrors the admin-managed flag. Unverified pieces are still served — the field is rendered as a tier hint, not a hard gate.
- `token_id` is a STRING, not a number. CW-721 token IDs are arbitrary strings; ERC-1155 token IDs are uint256 and exceed JS `Number.MAX_SAFE_INTEGER`. Always render verbatim; never coerce to Number.
- `image_url` is the full asset URL; `image_url_thumb` is a CDN-resized thumbnail (≤ 512 px on the long edge). Both are absolute URLs per §1.7. `image_url_thumb` falls back to `image_url` when no resize is available.
- `attributes[]` is the standard NFT trait array (OpenSea convention). `rarity_pct` is OPTIONAL — present only when the indexer has computed the trait's frequency across the collection. Empty array `[]` when the piece has no metadata; never `null`.
- `owner` is the single dominant holder:
  - **ERC-721 / CW-721** — the unique holder. `null` when no on-chain holder is known (cold-cache, freshly minted, indexer behind).
  - **ERC-1155** — the top-balance holder, ties broken by lowest `wallet_address` lexicographic. Always non-null when at least one holder exists.
  - `owner.balance` is `1` for ERC-721 / CW-721, the actual SUM(balance) for ERC-1155.
  - `owner.is_linked` is `true` when the wallet is connected to a BCC user; `owner.user` is non-null IFF `is_linked` is true.
  - `owner.address_short` follows the §1.7 wallet pattern (`<first-6>…<last-4>`).
- `owners_count` is the total distinct holder count. Always `1` for ERC-721 / CW-721 with a known holder; `0` when no holder is known. For ERC-1155 it is the count of wallets with `balance > 0`.
- `owners[]` is empty (`[]`) for ERC-721 / CW-721 (the single holder lives on `owner` only). For ERC-1155 it is the top-N holders by balance, capped server-side at **N = 10**. Each item has the same shape as `owner` minus `is_linked` / `user` enrichment (privacy: only the dominant `owner` gets handle resolution; the rest stay wallet-only). Future expansion to a paginated full-holder list is a separate endpoint.
- `marketplace_links[]` is per-chain. Empty array when no marketplace is configured for the chain. The list is server-curated from a new `bcc_onchain_chains.marketplace_template` column (added in V2 Phase 6 — see §4.17 mapping notes) and is stable across requests.
- `mint_link` mirrors the §3.2 Card pattern; relative path within the BCC site, points to the creator's mint surface with the token pre-selected.
- `permissions` is reserved for future viewer-aware actions (favorite, hide-from-watchlist, etc.) — V2 Phase 6 ships with `{}`. Frontend renders no per-piece actions.
- `meta.read_time` is `true` for chains with no persistent indexer (CW-721 / Cosmos as of V2 Phase 2 — read-time + V1-transient per pattern-registry). Frontend MAY surface a "Live data — may take a moment" affordance when this is true and the piece is a thumbnail in a list, though for the detail view itself the latency is acceptable without explicit copy.
- `meta.indexer_state` + `meta.indexer_state_label` follow the §3.6 contract verbatim — frontend renders the server-pre-formatted label, never invents copy.
- `meta.owners_summary_label` is the server-pre-formatted multi-holder summary string (e.g., `"Held by 8 collectors"`). `null` for ERC-721 / CW-721 / SPL (single-holder standards) and for ERC-1155 with `owners_count <= 1`. Non-null for ERC-1155 with multiple holders. **Frontend renders the value verbatim**; the FE MUST NOT compose its own count-with-noun string from `owners_count`. The server uses `_n('Held by %d collector', 'Held by %d collectors', $count)` so the locale-correct plural lands in `meta.owners_summary_label` when i18n ships. Frontend rendering rule: when `meta.owners_summary_label !== null`, also render the `owners[]` co-owner tiles; when null, render only the dominant `owner` block. This single field drives both the count copy and the multi-holder render branch (§S "no business logic on client").

**V2 Phase 6 deferred:**

- **Per-piece reactions / comments** — `FeedItem` is the reactive surface for content; pieces are passive read-only views in V2. V2.5+ may introduce a `nft_piece_watched` event but it is not part of this view-model.
- **Provenance / transfer history** — Phase 7. The persistent index already stores transfers but exposing them requires a paginated history sub-endpoint with privacy redaction for non-linked wallets.
- **Floor / last-sale** — collection-level only today (§4.7.4 `collection_stats`). Per-piece price history is a marketplace integration, deferred until at least one chain has a stable price oracle.

---

## 4. Phase 1 endpoints

Each endpoint listed has: method, path, auth requirement, request shape, response shape, error codes, rate limit, cache policy.

### 4.1 Auth

#### `GET /bcc/v1/auth/nonce`

Issues a single-use challenge nonce for wallet signing.

- **Auth:** Anonymous OR Bearer
- **Query:** `wallet_address` (required), `chain` (required: `cosmos|ethereum|solana`), `purpose` (required: `link|claim|signup`)
- **Response 200:**
  ```json
  {
    "nonce": "8f3a7b2e9c1d4f6a",
    "challenge_message": "Sign this to verify you control this wallet on Blue Collar Crypto.\n\nNonce: 8f3a7b2e9c1d4f6a\nIssued: 2026-04-27T14:23:00Z\nExpires: 2026-04-27T14:33:00Z",
    "expires_at": "2026-04-27T14:33:00Z"
  }
  ```
- **Errors:** `bcc_invalid_request` (bad chain or wallet format), `bcc_rate_limited`
- **Rate limit:** 10/min/IP, 5/min/wallet
- **Cache:** `Cache-Control: no-store`

#### `POST /bcc/v1/auth/wallet-link`

Verifies a signed nonce and links the wallet to the authenticated user.

- **Auth:** Bearer (required — must already have a session via email signup)
- **Body:**
  ```json
  {
    "wallet_address": "cosmos1abcdef…",
    "chain": "cosmos",
    "nonce": "8f3a7b2e9c1d4f6a",
    "signature": "<hex or base64 signature>",
    "public_key": "<hex pubkey, optional for chains where signature includes pubkey>"
  }
  ```
- **Response 201 (first-ever wallet link):**
  ```json
  {
    "wallet": {
      "chain": "cosmos",
      "address_short": "cosmos…q3kf",
      "verified_at": "2026-04-27T14:24:00Z"
    },
    "user": { "...": "updated User view-model (own)" },
    "celebration": {
      "kind": "mid",
      "label": "Wallet linked.",
      "icon": "wallet-link",
      "rarity_tint": null
    }
  }
  ```
- **Response 201 (subsequent wallet link):**
  ```json
  {
    "wallet": {
      "chain": "ethereum",
      "address_short": "0xab58…ec9b",
      "verified_at": "2026-04-27T14:24:00Z"
    },
    "user": { "...": "updated User view-model (own)" },
    "celebration": null
  }
  ```
- **Errors:** `bcc_invalid_request` (bad signature, expired nonce, already-linked), `bcc_unauthorized`
- **Rate limit:** 5/hour/user
- **Cache:** `Cache-Control: no-store`

**Celebration rule:** `celebration` is non-null **only on the user's first successful wallet link**, tracked via `wp_usermeta.bcc_first_wallet_link` (set true after first emission, per §O1.2 first-occurrence pattern). All subsequent wallet links return `celebration: null` regardless of chain.

**Mapping:** verifier → existing `bcc-core` `WalletVerifier`. Storage → `bcc_wallet_links` table. Nonce → `bcc-core` challenge service. First-link flag → `wp_usermeta.bcc_first_wallet_link`.

**Supported `chain_type` values:** `evm` (MetaMask / any EIP-1193 EVM wallet), `solana` (Phantom), `cosmos` (Keplr — ADR-036 secp256k1; covers Cosmos Hub / Osmosis / Injective / Juno / THORChain — Stargaze removed v1.30 with the chain's 2026 shutdown), `polkadot` (Polkadot.js / Talisman / SubWallet / Nova — sr25519 default, ed25519 / ecdsa accepted). Polkadot signature verification is delegated to the bcc-frontend Next.js app's `@polkadot/util-crypto` via an internal authenticated route (PHP has no native schnorrkel); same trust domain, same `WalletVerifier::verify` surface to callers.

#### `GET /bcc/v1/auth/wallet-nonce`

Issues a single-use challenge nonce for **anonymous** wallet signing. Public sibling of `/auth/nonce`. Drives `/auth/wallet-login` and `/auth/wallet-signup`. Stored in a separate transient keyspace from the authed nonce, so an anonymous nonce can never be replayed against `/auth/wallet-link` or vice versa.

- **Auth:** Anonymous
- **Query:** `chain_slug` (required), `wallet_address` (required)
- **Response 200:**
  ```json
  {
    "nonce": "8f3a7b2e9c1d4f6a",
    "message": "Sign this to verify you control this wallet on Blue Collar Crypto.\n\nNonce: 8f3a7b2e9c1d4f6a\nIssued: 2026-04-27T14:23:00Z\nExpires: 2026-04-27T14:33:00Z",
    "chain_slug": "cosmos",
    "chain_id": 4,
    "wallet_address": "cosmos1abcdef…",
    "expires_at": "2026-04-27T14:33:00Z"
  }
  ```
- **Errors:** `bcc_invalid_request` (missing chain/address, unsupported chain, bad address format), `bcc_rate_limited`
- **Rate limit:** IP-bucketed (`WALLET_NONCE_RATE_LIMIT`/min/IP). Disjoint from `/auth/nonce`'s user-keyed bucket — the two routes never starve each other under partial DoS.
- **Cache:** `Cache-Control: no-store`

#### `POST /bcc/v1/auth/wallet-login`

Verifies a signed nonce, looks up the BCC user the wallet is linked to, mints + returns a JWT. Wallet-as-credential equivalent of `/auth/login`.

- **Auth:** Anonymous
- **Body:**
  ```json
  {
    "wallet_address": "cosmos1abcdef…",
    "signature": "<base64 or hex signature>",
    "extra": { "public_key": "<base64 pubkey, Cosmos only>" }
  }
  ```
  `extra` is an optional object passed verbatim to `WalletVerifier::verify` — Cosmos requires `public_key` here; ETH/Solana ignore it.
- **Response 200:**
  ```json
  {
    "user_id": 42,
    "handle": "alice",
    "token": "<JWT>",
    "expires_in": 604800,
    "token_type": "Bearer",
    "in_good_standing": true
  }
  ```
- **Errors:**
  - `bcc_invalid_request` 400 — missing field, expired nonce, unsupported chain.
  - `bcc_signature_invalid` 401 — signature verification failed.
  - `bcc_wallet_not_linked` 404 — no BCC account is bound to this wallet. Frontend should route the user to `/signup` (or the wallet-signup flow) with the wallet pre-attached. **Distinct, recoverable code** — never auto-promotes.
  - `bcc_invalid_state` 409 — account is missing a handle (created outside BCC signup); routes through handle-claim surface.
  - `bcc_rate_limited` 429.
  - `bcc_internal_error` 500 — stored challenge malformed.
- **Rate limit:** IP-bucketed (`WALLET_LOGIN_RATE_LIMIT`/min/IP). Throttle gates the route **before** the CPU-bound verify step.
- **Cache:** `Cache-Control: no-store`
- **Side effects:** sets `wp_set_auth_cookie`, emits `user_login` audit row, fires `bcc_user_login` action.

#### `POST /bcc/v1/auth/wallet-signup`

Verifies a signed nonce, creates a new user (placeholder email if none supplied), links the wallet, mints + returns a JWT. Wallet-as-credential equivalent of `/auth/signup`.

- **Auth:** Anonymous
- **Body:**
  ```json
  {
    "wallet_address": "cosmos1abcdef…",
    "signature": "<base64 or hex signature>",
    "handle": "alice",
    "display_name": "Alice",
    "email": "alice@example.com",
    "extra": { "public_key": "<base64 pubkey, Cosmos only>" }
  }
  ```
  `handle` is required (§B6 rules: 3–20 chars, lowercase + digits + hyphens). `display_name` and `email` are optional; missing email → deterministic placeholder.
- **Response 201:**
  ```json
  {
    "user_id": 42,
    "handle": "alice",
    "token": "<JWT>",
    "expires_in": 604800,
    "token_type": "Bearer",
    "in_good_standing": true
  }
  ```
- **Errors:**
  - `bcc_invalid_request` 400 — missing field, expired nonce, unsupported chain, bad address.
  - `bcc_invalid_handle` 400 / `bcc_handle_reserved` 409 / `bcc_conflict` 409 — handle validation per §B6.
  - `bcc_signature_invalid` 401 — signature verification failed.
  - `bcc_wallet_already_linked` 409 — wallet is already bound to an account. Frontend should route to `/login`.
  - `bcc_rate_limited` 429.
  - `bcc_internal_error` 500 — stored challenge malformed, or `wp_insert_user` failed.
- **Rate limit:** IP-bucketed (`WALLET_SIGNUP_RATE_LIMIT`/min/IP).
- **Cache:** `Cache-Control: no-store`
- **Side effects:** sets `wp_set_auth_cookie`, fires `bcc_wallet_verified` (seeds trust-engine + onchain-signals rows just like `verifyAndLink`), emits `user_signup` audit row, fires `bcc_user_signup` action. **Note:** does NOT emit the `bcc_first_wallet_link` celebration on the response — celebration delivery for wallet-signup is reserved for a future contract revision.
- **Race protection:** if a concurrent signup wins the wallet-link race between the `existsForOtherUser` check and the actual link write, the inserted user is rolled back (`wp_delete_user`) and `bcc_wallet_already_linked` is returned. No orphan user is left behind.

#### `POST /bcc/v1/auth/forgot-password`

Requests a password-reset email. **Always returns `ok: true`** regardless of whether the email matches a registered account — this is the anti-enumeration contract; callers cannot use response shape or timing to discover which emails exist.

- **Auth:** Anonymous
- **Body:**
  ```json
  { "email": "alice@example.com" }
  ```
- **Response 200:**
  ```json
  { "ok": true }
  ```
- **Errors:**
  - `bcc_invalid_request` 422 — missing/malformed email field.
  - `bcc_rate_limited` 429 — IP throttle tripped.
- **Rate limit:** IP-bucketed `FORGOT_PASSWORD_RATE_LIMIT`/hour (default 3) — caps email-bomb spam against any single inbox from one source.
- **Cache:** `Cache-Control: no-store`
- **Side effects (only when email matches a real user):**
  - `get_password_reset_key($user)` writes the WP-native reset key into `user_activation_key` (24h TTL via the `password_reset_expiration` filter — WP default).
  - `AccountSecurityMailer::passwordResetRequested($userId, $resetUrl)` sends a plain-text email to the user. URL built via `FrontendRedirect::defaultReturn('/reset-password?key=…&login=…')` so the link lands on `BCC_FRONTEND_ORIGIN`.
  - `AuditLogger::log('password_reset_requested', $userId, {email_hash: sha1(email)}, 'user', $userId)`.
  - On `wp_mail` failure, `DegradationMetrics::record('account_security_mail', 'password_reset_requested_send_failed')` fires. The endpoint still returns ok=true.

#### `POST /bcc/v1/auth/reset-password`

Consumes a reset key issued by `/auth/forgot-password` and sets a new password. Backed by WordPress's native `check_password_reset_key()` + `reset_password()` primitives — no custom token storage. The user is NOT auto-logged-in; they must sign in fresh on `/login`.

- **Auth:** Anonymous
- **Body:**
  ```json
  {
    "key":      "<20-char key from the reset email>",
    "login":    "<wp user_login>",
    "password": "<new password, min 8 chars>"
  }
  ```
- **Response 200:**
  ```json
  { "ok": true }
  ```
- **Errors:**
  - `bcc_invalid_request` 422 — missing field.
  - `bcc_weak_password` 422 — password shorter than `SIGNUP_MIN_PASSWORD_LENGTH` (8).
  - `bcc_invalid_reset_token` 400 — key is expired, single-use already consumed, or never existed. Generic for both "expired" and "wrong key" to avoid leaking which failed.
  - `bcc_rate_limited` 429 — IP throttle tripped.
- **Rate limit:** IP-bucketed `RESET_PASSWORD_RATE_LIMIT`/hour (default 10) — defense in depth against key brute force; WP keys are ~20 random chars so this is bug-or-misuse insurance, not an active budget.
- **Cache:** `Cache-Control: no-store`
- **Side effects:**
  - `reset_password($user, $password)` hashes the new password, clears `user_activation_key` (single-use), fires the `password_reset` action — other plugins listening invalidate sessions for this user.
  - `AccountSecurityMailer::passwordChanged($userId)` canary email (same one fired by the in-app password change in `/me/account`).
  - `AuditLogger::log('password_reset_completed', $userId, [], 'user', $userId)`.

#### `POST /bcc/v1/auth/signup`

Creates an email/password account with a public handle. Mints no JWT — the account is marked pending email verification and the user must complete `/auth/verify-email` (or sign in after verifying) before `/auth/login` will issue a token.

- **Auth:** Anonymous (no token)
- **Body:**
  ```json
  { "email": "alice@example.com", "password": "<min 8 chars>", "handle": "alice", "display_name": "Alice" }
  ```
  `email`, `password`, `handle` required; `display_name` optional (defaults to the handle). `handle` is lowercased + trimmed and validated per §B6 (3–20 chars, lowercase letters / digits / hyphens, no leading / trailing / consecutive hyphens).
- **Response 201:**
  ```json
  { "ok": true, "email": "alice@example.com" }
  ```
  Deliberately carries **no JWT** — the frontend routes to `/verify-email?email=…`.
- **Errors:** `bcc_invalid_request` 400 (missing/malformed email or password < 8) · `bcc_invalid_handle` 422 (fails §B6) · `bcc_handle_reserved` 422 · `bcc_conflict` 409 (handle taken or email already registered) · `bcc_rate_limited` 429 · `bcc_internal_error` 500 (`wp_insert_user` failure)
- **Rate limit:** IP-bucketed, 5 / 60s (before any DB write)
- **Cache:** `Cache-Control: no-store`
- **Mapping:** writes a `subscriber` `wp_users` row (`wp_insert_user`, internal login `u_<handle>`), sets `bcc_handle` + `_bcc_email_verified='0'`, stores an HMAC-hashed 6-digit OTP (15-min transient) + single-use verify token (24-h transient), dispatches the verification email (`AuthMailer::sendVerificationEmail`, best-effort — a mail failure does NOT roll back the account), emits the `user_signup` audit log, fires `do_action('bcc_user_signup', …)`. Handler `AuthEndpoint::signup` (route `AuthEndpoint.php:229`). Standard envelope per §1.4.

#### `POST /bcc/v1/auth/login`

First factor of the password login. Validates credentials, then **always** initiates an email-OTP second factor — the JWT is only minted by `/auth/2fa/verify`. Gated on email verification: accounts explicitly pending verification (`_bcc_email_verified='0'`) are blocked; legacy accounts with no flag and verified accounts are allowed.

- **Auth:** Anonymous (no token)
- **Body:**
  ```json
  { "identifier": "alice@example.com", "password": "<password>" }
  ```
  `identifier` (v1.33, replaces `email`) accepts **either** the account email **or** the handle. Shape is server-detected: anything passing `is_email()` is looked up by email; everything else is treated as a handle (lowercased, one leading `@` stripped) and looked up via the same `u_<handle>` login convention signup uses. The old `email` key is **not** accepted — renamed in place pre-launch (no external consumers; frontend shipped atomically).
- **Response 200:**
  ```json
  { "status": "2fa_required", "method": "email", "challenge_token": "<64-hex>" }
  ```
  No JWT here. The client routes to the 2FA code screen and completes `/auth/2fa/verify` with the `challenge_token` (10-min TTL) + the 6-digit code emailed to the account (5-min TTL).
- **Errors:** `bcc_invalid_request` 422 (missing identifier or empty password) · `bcc_invalid_credentials` 401 (user-not-found OR wrong password — generic, anti-enumeration; identical for email- and handle-shaped identifiers) · `bcc_email_not_verified` 403 · `bcc_invalid_state` 409 (account has no handle) · `bcc_rate_limited` 429
- **Rate limit:** IP-bucketed, 5 / 60s (before the bcrypt compare)
- **Cache:** `Cache-Control: no-store`
- **Mapping:** reads `wp_users` by email or by login (`u_<handle>` via `AuthSupport::deriveLogin`) + `wp_check_password`, reads `bcc_handle` + `_bcc_email_verified`, stores an HMAC-hashed 2FA OTP (`bcc_2fa_otp_<userId>`, 5-min transient) + challenge token (`bcc_2fa_ct_<token>`, 10-min transient), emails the code (`AuthMailer::send2faCode`), emits the `user_login_2fa_initiated` audit log. No auth cookie, no JWT. Handler `PasswordAuthController::login`. Standard envelope per §1.4.

#### `POST /bcc/v1/auth/2fa/verify`

Second factor: exchanges the `/auth/login` challenge token + emailed 6-digit code for the JWT. On success the response shape is identical to the pre-2FA `/auth/login` token response.

- **Auth:** Anonymous (no token — the challenge token is the credential)
- **Body:**
  ```json
  { "challenge_token": "<64-hex>", "code": "482915" }
  ```
- **Response 200:**
  ```json
  { "user_id": 42, "handle": "alice", "token": "<JWT>", "expires_in": 604800, "token_type": "Bearer", "in_good_standing": true }
  ```
  `expires_in` is the 7-day JWT TTL (`JwtToken::TTL_SECONDS`). `in_good_standing` per §A2.
- **Errors:** `bcc_invalid_request` 422 (missing challenge_token or code) · `bcc_invalid_2fa_token` 401 (challenge expired/unknown — restart login) · `bcc_invalid_2fa_code` 401 (wrong/expired code — challenge preserved, retry in place) · `bcc_invalid_state` 404/409 (account vanished / no handle) · `bcc_rate_limited` 429
- **Rate limit:** IP-bucketed, 10 / 60s (brute-force fence; the 10-min challenge TTL is the outer bound)
- **Cache:** `Cache-Control: no-store`
- **Mapping:** peeks `bcc_2fa_ct_<token>`, timing-safely consumes `bcc_2fa_otp_<userId>` (`hash_equals`), consumes the challenge token only after the OTP matches, sets the WP auth cookie, mints the JWT (`JwtToken::encode`), emits the `user_login` audit log (`via: 2fa`), fires `do_action('bcc_user_login', …)`. Handler `AuthEndpoint::twoFaVerify` (route `AuthEndpoint.php:546`). Standard envelope per §1.4.

#### `POST /bcc/v1/auth/2fa/resend`

Re-sends a fresh 2FA code for an in-progress login challenge. Always returns `ok: true` whether or not the challenge token is valid (anti-enumeration; mirrors `/auth/resend-verification`).

- **Auth:** Anonymous (no token)
- **Body:** `{ "challenge_token": "<64-hex>" }`
- **Response 200:** `{ "ok": true }` (identical for valid and invalid tokens)
- **Errors:** `bcc_rate_limited` 429
- **Rate limit:** IP-bucketed, 3 / 60s (email-bomb fence)
- **Cache:** `Cache-Control: no-store`
- **Mapping:** peeks the challenge token without consuming it; when valid, overwrites `bcc_2fa_otp_<userId>` with a fresh HMAC-hashed code (the prior code immediately stops working) and re-dispatches `AuthMailer::send2faCode`. Handler `AuthEndpoint::twoFaResend` (route `AuthEndpoint.php:563`). Standard envelope per §1.4.

#### `POST /bcc/v1/auth/oauth-complete`

Completes an OAuth (Google/X) signup for a first-time user: exchanges the `provider_token` minted by the internal `/auth/oauth` bridge (see `EXEMPT_INTERNAL`) plus a chosen handle for a new account + JWT. Browser-called from `/signup/complete-profile`; its security rests on the `provider_token` — an unforgeable, single-use, server-issued capability carrying server-stored (not client-supplied) provider identity.

- **Auth:** Anonymous (no token — the provider_token is the credential)
- **Body:**
  ```json
  { "provider_token": "<64-hex>", "handle": "alice", "display_name": "Alice", "email": "alice@example.com" }
  ```
  `provider_token`, `handle` required; `display_name` optional (falls back to the provider's display name, then the handle). `email` is **required iff the provider didn't supply one** (X/Twitter's OAuth2 user-context never returns an email; Google always does) and is ignored when it did.
- **Response 201:** identical shape to `/auth/2fa/verify` (`{ user_id, handle, token, expires_in, token_type, in_good_standing }`) — the user is signed in immediately.
- **Errors:** `bcc_invalid_request` 400 (missing provider_token) · `bcc_invalid_oauth_token` 400 (provider_token expired (15 min) or consumed — restart the OAuth flow) · `bcc_invalid_handle` 422 / `bcc_handle_reserved` 422 (§B6) · `bcc_invalid_email` 422 (email missing/invalid when the provider supplied none) · `bcc_conflict` 409 (handle or email already taken) · `bcc_rate_limited` 429 · `bcc_internal_error` 500. Validation errors leave the provider_token intact so the user corrects and retries in place.
- **Rate limit:** IP-bucketed, 5 / 60s
- **Cache:** `Cache-Control: no-store`
- **Mapping:** peeks the provider_token without consuming; creates a `subscriber` `wp_users` row (`wp_insert_user`, login `u_<handle>`, random 64-char password), sets `bcc_handle` + `_bcc_oauth_<provider>` meta. Email-verified flag depends on the email's origin: provider-supplied (Google) → `_bcc_email_verified='1'` + welcome email immediately; **user-typed** (X) → `'0'` + the standard verification email (same OTP/link machinery as `/auth/signup`; the welcome email arrives via `finalizeVerification`). A `'0'` account cannot be email-matched by the `/auth/oauth` bridge (anti-pre-registration gate) nor password-login until verified — provider sign-in is unaffected (provider-ID match). Consumes the provider_token only after the user row commits, mints the JWT, emits the `user_signup` audit log (`via: oauth`), fires `do_action('bcc_user_signup', …)`. Handler `AuthEndpoint::oauthComplete` (route `AuthEndpoint.php:621`). Standard envelope per §1.4.

#### `POST /bcc/v1/auth/refresh`

Silent-refresh seam: exchanges a recently-expired (or near-expiry) Bearer JWT for a fresh one within the `REFRESH_GRACE_SECONDS` (86400 / 24-h) grace window. Clients SHOULD invoke this once on a `bcc_token_expired` 401 before signing the user out (see §β.3). Every JWT check other than `exp` — signature, issuer, audience, version/revocation — is enforced identically to the canonical decode, so a rotated-key, revoked, or `revokeAllForUser`-nuked token can never refresh.

- **Auth:** Bearer required (the expired-or-near-expiry JWT in `Authorization: Bearer <jwt>`, NOT a cookie); checked in-handler via `JwtToken::decodeForRefresh`
- **Body:** none (token from the `Authorization` header)
- **Response 200:**
  ```json
  { "token": "<fresh JWT>", "expires_in": 604800, "token_type": "Bearer" }
  ```
  Unlike `/auth/login`, omits `user_id` / `handle` / `in_good_standing`.
- **Errors:** `bcc_unauthorized` 401 (missing/malformed Bearer, decode failed, grace window exceeded, or invalid `user_id` claim — collapsed to one code) · `bcc_forbidden` 403 (suspended) · `bcc_invalid_state` 409 (no handle) · `bcc_rate_limited` 429
- **Rate limit:** IP-bucketed, 30 / 60s
- **Cache:** `Cache-Control: no-store`
- **Mapping:** decodes via `JwtToken::decodeForRefresh`, re-reads `bcc_handle` from authoritative storage (a handle change since the original mint is reflected; the JWT payload is not trusted), mints a fresh JWT, emits the `token_refreshed` audit log. No cookie set, no DB mutation beyond the audit row. Handler `AuthEndpoint::refresh` (route `AuthEndpoint.php:292`). Standard envelope per §1.4.

#### `POST /bcc/v1/auth/verify-email`

Confirms email ownership and completes signup via either an OTP code or a one-shot link token, then signs the user in by returning a JWT (no separate `/auth/login` roundtrip).

- **Auth:** Anonymous (no token)
- **Body:** one of two shapes (token path wins when both present):
  ```json
  { "email": "alice@example.com", "code": "482915" }
  ```
  ```json
  { "token": "<64-hex one-shot token>" }
  ```
  OTP `code` = 6-digit email code (15-min TTL); `token` = self-identifying single-use link token (24-h TTL). The handler requires either `token` or both `email` and `code`.
- **Response 200:** identical shape to `/auth/login` (`{ user_id, handle, token, expires_in, token_type, in_good_standing }`) so the frontend treats both uniformly.
- **Errors:** `bcc_invalid_request` 422 (neither token nor email+code) · `bcc_invalid_verify_token` 400 (token expired/used) · `bcc_invalid_otp` 400 (email unknown — generic; or code wrong/expired) · `bcc_already_verified` 409 (OTP path only) · `bcc_invalid_state` 409 (no handle) · `bcc_rate_limited` 429 · `bcc_internal_error` 500
- **Rate limit:** IP-bucketed, 10 / 3600s
- **Cache:** `Cache-Control: no-store`
- **Mapping:** token path consumes the `bcc_vt_<token>` transient (single-use); OTP path resolves by email, checks `_bcc_email_verified`, timing-safely (`hash_equals`) consumes the HMAC-hashed `bcc_otp_<userId>` transient. Both converge on `finalizeVerification` → sets `_bcc_email_verified='1'`, sets the auth cookie, mints the JWT, emits `email_verified` audit, fires `do_action('bcc_email_verified', …)`. Handler `AuthEndpoint::verifyEmail` (route `AuthEndpoint.php:487`). Standard envelope per §1.4.

#### `POST /bcc/v1/auth/resend-verification`

Re-sends a fresh verification OTP + link to an unverified account. Always returns `ok: true` regardless of whether the email matches a registered unverified account (anti-enumeration; mirrors `/auth/forgot-password`).

- **Auth:** Anonymous (no token)
- **Body:** `{ "email": "alice@example.com" }`
- **Response 200:** `{ "ok": true }` (identical whether or not the email matches an account)
- **Errors:** `bcc_invalid_request` 422 (missing/malformed email) · `bcc_rate_limited` 429
- **Rate limit:** IP-bucketed, 3 / 3600s
- **Cache:** `Cache-Control: no-store`
- **Mapping:** resolves by email; only when `_bcc_email_verified === '0'` generates + stores a fresh HMAC-hashed OTP (overwriting the prior `bcc_otp_<userId>` transient) + verify token, then dispatches the email. Already-verified / legacy accounts are silently skipped. Handler `AuthEndpoint::resendVerification` (route `AuthEndpoint.php:521`). Standard envelope per §1.4.

### 4.2 Cards

#### `GET /bcc/v1/cards/:type/:id`

Returns a full Card view-model.

- **Auth:** Anonymous OR Bearer (response varies — `permissions` and `social_proof` differ for authed viewers)
- **Path:** `type` ∈ {`validator`, `project`, `creator`, `member`}; `id` is the integer entity ID
- **Response 200:** Card view-model (§3.2)
- **Errors:** `bcc_not_found` (entity missing OR risky-tier hidden), `bcc_invalid_request` (bad type)
- **Rate limit:** 60/min/user (anonymous: 60/min/IP)
- **Cache:** `Cache-Control: max-age=30, stale-while-revalidate=60`; React Query `staleTime: 30s`
- **Mapping:**
  - `trust_score`, `reputation_tier`, `card_tier`, `tier_label`, `is_in_good_standing`, `flags` ← `bcc_page_read_model` (§A4)
  - `is_claimed`, `claimed_by_handle` ← `peepso_pages.claimed_by` (§B5)
  - `chain` ← `peepso_page` meta (existing AbstractPageType)
  - `stats[]` per kind ← see §6 mapping table
  - `permissions.*` ← `bcc-trust` permission resolver (§A4) combining O5 + D2 gates
  - `social_proof` ← join over `peepso_user_followers` + `peepso_reactions` filtered by viewer's network and trust-weighted (§O4.1)

### 4.3 Feed

#### `GET /bcc/v1/feed`

Authenticated personalized feed (§F1, §N6).

- **Auth:** Bearer (required)
- **Query:**
  - `scope` ∈ {`for_you`, `following`, `signals`} — default `for_you` (§N6; "community" cut)
  - `cursor` (optional)
  - `limit` (optional, default 20, max 50)
- **Response 200:** `CursorEnvelope<FeedItem>` per §1.5 / §3.3
- **Errors:** `bcc_unauthorized`, `bcc_invalid_request` (bad scope)
- **Rate limit:** 60/min/user
- **Cache:** `Cache-Control: private, max-age=15`; React Query `staleTime: 15s`
- **Mapping:**
  - Items ← `peepso_activities` filtered by scope + `BccFeedRankingService` (§F3)
  - `social_proof` per item ← see §6
  - `reactions` per item ← `peepso_reactions` aggregated
  - **Scope-to-filter mapping:**
    - `for_you` → ranked stream, F1 priority chain applied
    - `following` → strict-time-ordered, posts attributed to entities/users in the viewer's `peepso_user_followers` set, signal kinds excluded
    - `signals` → signal post-kinds only, ranked by recency + severity
  - Shadow-limited authors (§K1, §O4.1) excluded from all scopes for this viewer

#### `GET /bcc/v1/feed/hot`

Anonymous-friendly trending feed (§F2 zero-follow fallback).

- **Auth:** Anonymous OR Bearer
- **Query:** `cursor`, `limit` as above
- **Response 200:** `CursorEnvelope<FeedItem>` (same shape as `/feed`)
- **Errors:** `bcc_invalid_request`
- **Rate limit:** 60/min/IP (anon), 60/min/user (authed)
- **Cache:** `Cache-Control: public, max-age=15, stale-while-revalidate=30`
- **Mapping:** `BccFeedRankingService` with global trending profile. The same service serves all feed surfaces (§F3) — no separate hot-feed code path.

#### `GET /bcc/v1/feed/tag`

Anonymous-friendly hashtag feed — the hot feed narrowed to a single tag.

- **Auth:** Anonymous OR Bearer
- **Query:**
  - `tag` (**required**) — the hashtag text; a leading `#` is stripped server-side
  - `cursor` (optional)
  - `limit` (optional, default 20, min 1, max 50)
- **Response 200:** `CursorEnvelope<FeedItem>` — **identical shape to `/feed` and `/feed/hot`**: `data = { items: FeedItem[], pagination: { next_cursor: string|null, has_more: bool } }`
- **Errors:** `bcc_invalid_request` (missing/empty `tag`)
- **Rate limit:** 60/min/IP (anon), 60/min/user (authed)
- **Cache:** `Cache-Control: private, max-age=15`; `Vary: Authorization, Cookie`
- **Mapping:** Same `FeedRankingService` single brain (§F3) as `/feed/hot` — identical visibility gates (caution/risky shadow-limit, moderation hide, non-open group exclusion at the anonymous posture, and the global `public_all` visibility gate). The candidate set is then narrowed by `peepso_activities` → `wp_posts.post_content LIKE '%#<tag>%'` (the same substring association PeepSo uses for its `ht_count`). The narrowing predicate can only remove posts from the gated set — the tag feed never surfaces a post `/feed/hot` would not.

#### `GET /bcc/v1/feed/:id`

Single-activity permalink read (v1.33 backfill — shipped with the post-detail page). Backs the post-detail page and shared links. Two sibling route patterns share one handler, and their input domains are disjoint by construction so neither collides with the other or with `/feed/hot` / `/feed/tag`:

- numeric (`\d+`) — the raw activity id, i.e. the numeric tail of the feed's opaque `feed_<act_id>`. Kept as the dev/legacy fallback.
- shortcode (`[a-zA-Z]{8}`, v1.39) — the post's canonical 8-letter code from `wp_bcc_post_shortcodes`, the tail of `links.self` = `/u/{handle}/post/{code}`. Letters-only (never digits) is what keeps the two domains unambiguous. Unknown code → the same `bcc_not_found` 404 as an unknown numeric id.

- **Auth:** Anonymous OR Bearer
- **Path:** `id` (int act_id **or** 8-letter shortcode — see above)
- **Response 200:** a single §3.3 `FeedItem` (bare object in the §1.4 envelope — NOT a `CursorEnvelope`), same hydration pipeline as `/feed`
- **Errors:** `bcc_not_found` 404 — covers both "no such activity" and "not visible to this viewer" (§O4.1 caution/risky author exclusion, §K1 mutual-block invisibility, §K1-C moderation hide all collapse to 404; no existence oracle)
- **Rate limit:** none route-specific
- **Cache:** `Cache-Control: private, max-age=15` · `Vary: Authorization, Cookie`
- **Mapping:** `FeedEndpoint::feedItem` → `FeedRankingService::getActivityById` (same §F3 single brain + `FeedHydrationPipeline` as the list surfaces). FE post-detail page / quick-view modal.

#### Server-side group-privacy + visibility filter (applies to `/feed`, `/feed/hot`, and `/feed/tag`)

**Group-post syndication (v1.24):** a group-tagged post (one carrying `peepso_group_id` post-meta) appears in the global feed ONLY when its `_bcc_post_visibility` post-meta is `public_all` (the opt-in chosen at compose time via the §4.14 / §4.15 `visibility` field). `members_only` and `public_group` group posts — and any group post with no visibility meta — never enter the global candidate set, for members and non-members alike. (Previously open-group posts leaked into the global feed regardless of intent; now only `public_all` group posts syndicate.) Non-group posts are unaffected and continue to flow into the feed as before.

**Group privacy does NOT independently gate global syndication.** The per-post `_bcc_post_visibility = 'public_all'` marker (above) is the SOLE gate for whether a group-tagged post enters the global feed. A `public_all` post syndicates globally **regardless of its group's privacy** (open, closed, or secret — including NFT-gated holder groups) and is visible to members, non-members, and anonymous viewers alike; a `members_only` / `public_group` / no-visibility-meta group post never enters the global candidate set for anyone.

The former membership-based exclusion — `FeedRankingService` computing `excludedGroupIds = (non-open group ids) − (viewer membership ids)` and `PeepSoActivityRepository::getActivities` appending a `gx_pm.meta_value NOT IN (...)` clause — has been **superseded and is now inert on the global path**: `PeepSoActivityRepository` keeps `$excludedGroupIds` in its signature for caller compatibility but does not consume it on the global path, and `FeedRankingService` **still computes** that membership-derived list and forwards it (at `buildHotFeed` / `getTagFeed` / `getFeed` / `getActivityForAuthor` — verified on `origin/main` 2026-07-22) — the value is now **dead weight the repository ignores globally**, pending a follow-up cleanup that removes the computation. The live global predicate is simply `(gx_pm.meta_value IS NULL OR vis_pm.meta_value = 'public_all')`, where `gx_pm` is the `peepso_group_id` marker and `vis_pm` the `_bcc_post_visibility` marker. Group-*scoped* surfaces (`GET /communities/{id}/feed`) still gate `members_only` / `public_group` teasers by membership via a separate INNER-JOIN path — that is unchanged.

> ⚠️ **Correction (2026-07-21):** an earlier version of this section claimed non-open-group posts are dropped for non-members and that "anonymous viewers see no posts from any non-open group." That does **not** match the shipped code — a `public_all` post in a closed or secret group *does* syndicate to the global feed for everyone. Corrected here to describe actual behavior.
>
> ✅ **Approved policy (Gate 12, 2026-07-22, Phillip): Option B — "public_all wins."** The syndication described above is **intended**. An explicit, valid `public_all` post may appear on all public surfaces (feed / hot / tag, public permalink, public content-search, public group-discovery) **even inside a closed/secret group**, exposing only minimum discovery context (public body/media, author public identity, timestamp, public engagement, group name/avatar/URL, join/follow action). Private-by-default, fail-closed on unknown/legacy visibility, explicit-choice-only, server-side authorization, and moderator/admin removal all hold. *(This **supersedes** the earlier "content search is stricter" framing in `docs/content-search-privacy-design.md`; content search is **not yet built** and must mirror the feed's `public_all` gate when implemented.)* **Canonical group-stream visibility matrix** — `members_only` (member stream only) · `public_group` (member + non-member open/closed stream; **not** global/search) · `public_all` (every stream incl. non-member secret preview + global feed/permalink/search) — is documented in `docs/content-search-privacy-design.md` **Note C**. The safe secret-group public preview, the owner/moderator-controlled `public_all` authorization, and public-cache invalidation are **approved but NOT BUILT** (before-production; tracker `docs/audit-remediation-checklist-2026-07-21.md` CL-FN04 / CL-FN06 / CL-73).

Defense-in-depth: the per-row `hydrateCommentCounts` membership gate is retained — a single bad caller path that bypasses the SQL exclusion would still see comment counts zeroed for gated-group items. The two layers compose; neither is sufficient alone.

#### `GET /bcc/v1/hashtags/trending`

Most-used hashtags, ordered most-used first. Non-personalized.

- **Auth:** Anonymous OR Bearer
- **Query:**
  - `limit` (optional, default 8, min 1, max 20)
- **Response 200:**
  ```json
  {
    "data": {
      "items": [
        { "tag": "blockchain", "count": 42 },
        { "tag": "validators", "count": 17 }
      ]
    }
  }
  ```
  - `tag` — the hashtag text **without** the leading `#`
  - `count` — number of posts PeepSo has counted for the tag (`int`)
- **Errors:** none beyond the standard envelope
- **Rate limit:** 60/min/IP (anon), 60/min/user (authed)
- **Cache:** `Cache-Control: public, max-age=300` (non-personalized, share-cacheable)
- **Mapping:** read-only projection over PeepSo's `peepso_hashtags` (`ht_name`, `ht_count`), `WHERE ht_count > 0 ORDER BY ht_count DESC, ht_id DESC`. PeepSo owns the write path / counter — no parallel BCC tag counter (§11).

#### `GET /bcc/v1/suggestions/users`

Personalized "who to follow" recommender. **Relevance/affinity-based, NOT popularity** — by hard product rule the ranking has no trust-score term and no follower-count term (same discovery doctrine as the cold-start surface). Reputation enters only as an exclusion.

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized 401`. The result is personalized to the current viewer's follow graph, group memberships, and validator backing, so there is no anonymous variant.
- **Query:**
  - `limit` (optional, default 12, min 1, max 24)
- **Response 200:**
  ```json
  {
    "data": {
      "items": [
        {
          "id": 412,
          "handle": "ramona",
          "display_name": "Ramona V.",
          "avatar_url": "https://.../avatar.jpg",
          "card_tier": "rare",
          "tier_label": "Rare",
          "rank_label": "Journeyman",
          "is_in_good_standing": true,
          "suggestion_reason": { "code": "mutual_follows", "label": "Followed by 3 you follow" }
        }
      ]
    }
  }
  ```
  - Items are ordered **best-first** by the affinity score.
  - `card_tier` ∈ `legendary | rare | uncommon | common | null` (mirrors §C1; `null` for risky-tier — but risky users are excluded from this surface anyway).
  - `tier_label` is the pre-rendered §A2 display string (or `null`).
  - `suggestion_reason` is the single highest-CONTRIBUTING affinity signal, or `null` for civic cold-start fallback rows. `code` ∈ `follows_you | mutual_follows | co_local | co_validator | co_nft_community`; `label` is a server-rendered §A2 string (e.g. `"Follows you"`, `"Followed by N you follow"`, `"In Local 34 — Brooklyn with you"`, `"Backs Stakecito too"`, `"In Bored Apes with you"`).
- **Scoring (affinity only):** `W_RECIP·followsViewer + W_MUTUAL·min(mutualFollows, MUTUAL_CAP) + W_LOCAL·sharedLocals + W_VALIDATOR·sharedValidatorBacking + W_NFT·sharedHolderCommunities`. Weights are server constants (tunable). **No popularity/trust term participates in ranking.**
- **Exclusions (security-sensitive):** self; already-following; caution/risky reputation tier; active suspensions; mutual blocks (either direction); and any candidate who hid their watching graph (`watching_hidden`).
- **Cold-start fallback:** when fewer than `limit` candidates survive scoring + exclusions (the dominant empty-graph case), the response tops up from the same civic recent-operators source the cold-start feed uses (recency + stable daily shuffle, NOT ranked), with `suggestion_reason: null`.
- **Errors:** `bcc_unauthorized` (anonymous)
- **Rate limit:** 60/min/user
- **Cache:** `Cache-Control: private, max-age=120`; `Vary: Authorization, Cookie` (per-viewer personalization — no shared cache)
- **Mapping:** candidate union from `peepso_user_followers` (reciprocity + 2nd-degree mutual counts), `peepso_group_members` (shared Locals + holder communities), and `bcc_onchain_delegations` ⋈ `bcc_wallet_links` (shared validator backing, verified wallets only). Member view-model fields composed via `UserViewService::getSummary` with the shared `MemberSummaryPrefetcher` batch (no parallel hydration). Validator monikers resolved via `bcc_onchain_validators`.

### 4.4 Users

#### `GET /bcc/v1/users/:handle`

Full User view-model.

- **Auth:** Anonymous OR Bearer
- **Path:** `handle` (lowercase, no `@` prefix)
- **Response 200:** User view-model (§3.1) — own variant if `is_self`, others' variant otherwise
- **Errors:** `bcc_not_found` (handle missing), `bcc_permission_denied` (account suspended; rare)
- **Rate limit:** 60/min/viewer
- **Cache:** `Cache-Control: private, max-age=30` (own: `no-store`); React Query `staleTime: 30s` (own: 0)
- **Mapping:**
  - Identity fields ← `wp_users` + `wp_usermeta.bcc_handle`
  - `trust_score`, `reputation_tier`, `card_tier`, `rank`, `is_in_good_standing`, `flags` ← `bcc-trust` services (§A4)
  - `living` ← derived from `peepso_activities` + `bcc_trust_score_events` (§A4 owns)
  - `progression` ← `bcc-trust` level + threshold service (rank auto-derived from feature-access level; §4.8)
  - `feature_access` ← `bcc-trust` feature-access service (§O5)
  - `wallets` ← `bcc_wallet_links`
  - `primary_local`, `locals` ← `bcc_user_locals` (joined to `peepso_groups`)
- **Profile-page extension:** the response also carries the
  `MemberProfileComposer` extras shipped alongside §3.1: `user_id`
  (alias of `id`), `bio_block` (paragraph + signature shape, server-
  rendered per §A2), `card` (full §3.2 Card view-model — same shape
  the cards endpoint returns), `standing` (`{is_in_good_standing,
  since_label, facts}`), `identity_meta` (label/value strip),
  `stats` (platform-tagged stats strip), `shift_log` (`{days,
  summary, month_ticks}` — note: differs from the standalone
  `/users/:handle/shift-log` shape which is tuned for the grid-only
  consumer), `activity_breakdown` (five-bucket §N9 breakdown),
  `live_shift` (recent-activity events for the hero panel), `tabs`
  (counts strip). `reviews` and `disputes` ship as empty arrays in
  V1; lazy-load list endpoints for those tabs land in V1.5.

  `tabs` entries are `{key, label, count, hidden}`. **v1.49 split:**
  key `reviews` counts reviews RECEIVED (`counts.reviews_received` —
  matches the tab's content since v1.48; public, so `hidden` is always
  `false`), and the new key `written` counts reviews AUTHORED
  (`counts.reviews_written`; `hidden` honors `reviews_hidden` for
  non-self viewers). Key union: `watching | reviews | written |
  activity | disputes | network`.

#### `GET /bcc/v1/users/:handle/shift-log`

52-week activity grid ([glossary §5 Shift Log](glossary.md#5-surfaces-places-in-the-ui)).

- **Auth:** Anonymous OR Bearer (privacy-filtered)
- **Path:** `handle`
- **Query:** `weeks` (optional, default 52, max 104)
- **Response 200:**
  ```json
  {
    "weeks": 52,
    "as_of": "2026-04-27T14:30:00Z",
    "cells": [
      { "date": "2026-04-26", "count": 4, "intensity": "high",   "kinds": ["review", "vouch", "pull"] },
      { "date": "2026-04-25", "count": 1, "intensity": "low",    "kinds": ["pull"] },
      { "date": "2026-04-24", "count": 0, "intensity": "none",   "kinds": [] }
    ],
    "totals_by_kind": {
      "pull": 38, "review": 8, "vouch": 47, "stand_behind": 3, "dispute_signed": 1, "post": 24
    }
  }
  ```
- **Errors:** `bcc_not_found`
- **Rate limit:** 30/min/viewer
- **Cache:** `Cache-Control: public, max-age=300, stale-while-revalidate=600`
- **Mapping:** `peepso_activities` aggregated by day, server-side bucketed; `intensity` computed server-side (`none`, `low`, `med`, `high`, `peak`) — frontend just renders the class.

#### `GET /bcc/v1/members`

Paginated directory of human members. Sibling to §4.9 `/cards` (entity-card directory). **Each item is a full member `Card` view-model (§3.2, `card_kind: "member"`)** so the frontend renders directory rows through the canonical `<CardFactory>` — the same component the entity-card directory uses. The per-member back-of-card signal blocks (verifications, engagement, owned-page typed counts, primary Local) ride along on the card's `member_dossier` block; nothing the older slim `MemberSummary` shape carried is lost. Click-through navigates to `/u/:handle` for the full profile.

- **Auth:** Anonymous OR Bearer (privacy-filtered — `real_name_hidden` honored)
- **Query:** `page` (1-indexed, default 1), `per_page` (default 20, max 50), `q` (optional — bounded to 64 chars, matched against `user_login` + `display_name` + `user_nicename`), `type` (optional — one of `validator | project | nft | dao`; restricts results to users with ≥1 owned page of that canonical type, intersecting with `q` when both are present)
- **Response 200:**
  ```json
  {
    "items": [
      { "...": "member Card view-model per §3.2 — card_kind: \"member\", with member_dossier populated and rank_label a string" }
    ],
    "pagination": { "page": 1, "per_page": 20, "total": 124, "total_pages": 7 },
    "type_counts": { "validator": 5, "project": 5, "nft": 5, "dao": 2 }
  }
  ```
  Each item carries the full §3.2 member-card envelope (`id`, `name`, `handle`, `card_kind: "member"`, `trust_score`, `card_tier`, `tier_label`, `rank_label`, `crest`, `stats`, `permissions`, `actions`, …) plus the `member_dossier` block:
  ```json
  "member_dossier": {
    "verifications":       { "x_verified": true, "x_username": "phillips_eth", "github_verified": true, "github_username": "phillips", "wallets_verified": 2 },
    "engagement":          { "endorsements_received": 17, "solids_received": 38, "reviews_written": 12, "disputes_signed": 3 },
    "owned_pages_by_type": { "validator": 1, "project": 1, "nft": 0, "dao": 0 },
    "primary_local":       { "id": 1138, "slug": "local-34-brooklyn", "name": "Local 34 — Brooklyn", "number": 34 }
  }
  ```
- **Errors:** `bcc_validation` (invalid `page` / `per_page`)
- **Cache:** `Cache-Control: private, max-age=15`; `Vary: Authorization, Cookie`
- **Pagination envelope:** offset (`OffsetPagination` per §1.5) — `total_pages` is the canonical field; clients derive "has more" as `page < total_pages`.
- **Mapping:** `WP_User_Query` ordered by `user_registered DESC`; each row composed via `CardViewService::getMemberCardForList` (one call per user). `UsersEndpoint::members` prefetches eleven batched maps — followers count, primary-Local resolution, owned-page count, owned-page typed counts, endorsements received, solids received, reviews written, disputes signed, verified-wallet count, X connections, GitHub connections — via `MemberSummaryPrefetcher::primeFor` before the per-row loop; the card builder delegates the dossier resolution to `UserViewService::getSummary($userId, $viewerId, $prefetched)` so the total query budget stays bounded regardless of `per_page` (no parallel dossier query — same resolution as before, now wrapped in the Card shape). `card_tier` mirrors the §C1 slug (`legendary|rare|uncommon|common|null`); null only for risky-tier users (entity hidden from card UI per §C1). `tier_label` is the pre-rendered §A2 display string. Frontends should encode the tier as a color/border treatment on the rank chip rather than rendering `tier_label` as a duplicate word next to `rank_label`.
- **Field rules** (the `member_dossier` sub-blocks; the rest follow the §3.2 Card rules):
    - `trust_score` ∈ [0, 100] per §D5. Augmented score = base reputation_score + clamped lifetime participation bonus (`DisputeParticipationRepository::getEarnedLifetimeTrust`). Clamped at the boundary; clients render as a stencil number, never derive. (Top-level card field per §3.2.)
    - `stats[].watchers` (the member-card third stat per §3.2.2) is the passive side of `peepso_user_followers` (people who follow this user), sourced from `UserViewService::getSummary`'s `followers_count`. The full `/users/:handle` response carries both `followers` and `following`; the directory ships the followers count only — `following` isn't a meaningful directory signal and the second SQL isn't worth the cost.
    - `member_dossier.primary_local` shape matches `MemberProfile.primary_local`. `number` is parsed from `name` via the `^Local\s+(\d+)\b` convention; null when the title doesn't follow the pattern. Frontends render display strings client-side from `name`/`number`. `null` when the member has no primary Local.
    - `member_dossier.owned_pages_by_type` is a per-canonical-type count of `member_owner` pages, derived from the PeepSo page-categories taxonomy (`peepso_page_categories` joined to the `peepso-page-cat` CPT). The four type keys (`validator`, `project`, `nft`, `dao`) are stable wire identifiers — decoupled from the underlying PeepSo category slugs (which are admin-controlled and may include legacy typos like `vaildators`). PeepSo pages are tag-shaped, not type-shaped: a single page can carry multiple categories. Frontends should render one badge per non-zero bucket (`6 PROJECTS`, `5 NFT COLLECTIONS`, `1 VALIDATOR`). New canonical types require a contract amendment + a new key in the response shape; we don't fall back to an "OTHER" bucket for unrecognized categories.
    - `type_counts` is the **global** count of distinct users with ≥1 owned page per canonical type. Independent of the active `q` and `type` filters by design — the chip-strip's `VALIDATORS · 5` numbers shouldn't shift around as a viewer types in the search box. Same four keys as `member_dossier.owned_pages_by_type`. Always emitted (even on the type-empty short-circuit) so a filter-specific empty state can suggest alternative chips with non-zero counts.
    - `member_dossier.verifications` carries connection presence + provider username for the social-proof panel on the back of the directory card. `x_verified` / `github_verified` are `true` only when an active row exists in `bcc_trust_user_verifications` AND `verified_at` is non-null — token presence alone does not count. `x_username` / `github_username` are the public handles for click-through display (`@phillips` etc.); never decrypt tokens into this payload. `wallets_verified` is the count of `bcc_wallet_links` rows where `verified_at IS NOT NULL` — the per-wallet detail (chain, address) lives on `MemberProfile.wallets`.
    - `member_dossier.engagement` carries lifetime activity counts for the back-of-card "ON THE FLOOR" panel. `endorsements_received` is summed across every page the user owns (`peepso_page_members.pm_user_status = 'member_owner'` JOINed to `bcc_trust_endorsements` on `page_id`); a multi-page operator's endorsement count is the union of endorsements on all their pages. `solids_received` counts `peepso_reactions` rows of kind `KIND_SOLID` on activities the user owns; returns 0 when the reaction set isn't seeded yet (`ReactionTypeRegistry::solidId() === null`). `reviews_written` mirrors `MemberCounts.reviews_written` (count via `VoteRepository::countByVoter`). `disputes_signed` mirrors `MemberCounts.disputes_signed` (count via `FlagsRepository::countByFlagger`).

#### `GET /bcc/v1/users/mention-search` (v1.5)

Slim prefix-search endpoint backing the composer's `@`-mention autocomplete (§3.3.12). Distinct from `/members` because keystroke autocomplete needs a tiny payload and tighter privacy filtering than the directory grid — the dropdown MUST NOT leak hidden / banned / blocked users that the directory might surface to peers via different code paths.

- **Auth:** Bearer required. Anonymous → `bcc_unauthorized 401`. The mention picker is composer-only and the composer is auth-gated, so the endpoint is too.
- **Query:**
  - `q` (string, **required**, 1–32 chars after trim) — handle/display-name prefix. Empty/missing returns `400 bcc_invalid_request` (V1d does not ship empty-query candidate sets — see §3.3.12 deferred).
  - `limit` (int, optional, default `8`, max `8`) — hard cap to bound enumeration surface. Server clamps; values above the cap silently clamp without erroring.
- **Response 200:**
  ```json
  {
    "items": [
      {
        "user_id":      42,
        "handle":       "simontx",
        "display_name": "Simon TX",
        "avatar_url":   "https://bluecollar.crypto/wp-content/uploads/peepso/users/42/abc-avatar.jpg"
      }
    ]
  }
  ```
- **Errors:**
  - `bcc_unauthorized 401` — anonymous.
  - `bcc_invalid_request 400` — `q` missing, empty after trim, or > 32 chars.
  - `bcc_rate_limited 429` — per-viewer throttle (60 keystrokes / 60s rolling window).
- **Cache:** `Cache-Control: private, max-age=10`; `Vary: Authorization, Cookie`. Short TTL deliberate — the typical autocomplete burst (3-6 keystrokes typed in succession) hits the cache for the steady-state prefix; a 10s window is long enough to absorb the burst, short enough that a recent join/ban/block updates within a session.
- **Privacy / mapping:** routed through `PeepSoUserSearch` ([peepso/classes/usersearch.php](app/public/wp-content/plugins/peepso/classes/usersearch.php)) which applies the canonical filter set: ban filter, `profile_acc != PRIVATE`, bidirectional `peepso_user_blocked` (viewer→target AND target→viewer), `allow_hide_user_from_user_listing`, plus the `peepso_user_search_args` hook BCC's [PrivacySettings::filterPeepSoSearchArgs](app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Support/PrivacySettings.php) attaches for `bcc_privacy_discovery_optout`. **Do NOT route this through the dormant `bcc-search` `UserSearchRepository`** — that one runs raw `wp_users` LIKE and bypasses every filter above; it would leak hidden users into the dropdown.
- **Ranking:** prefix match on `user_login` + `display_name` + `bcc_handle` user-meta, ordered by handle ASC. V1d does NOT bias by follower edges (deferred — see §3.3.12 deferred). When two candidates share a prefix, alphabetical order wins; ties broken by `ID ASC`.
- **Side effects:** none. Pure read; no notification, no logging beyond the rate-limit counter.
- **Sister endpoints:** the composer's full-name → user_id resolution at submit time is **not** exposed as a separate endpoint — the picker emits the wire-format token `@peepso_user_<id>(name)` directly into the post body using `user_id` from this response, and the server's `MentionPolicy` re-validates on `POST /posts*` (§3.3.12 server-side enforcement). A user picked from the dropdown but who turns hidden between picker-select and submit gets rejected at write-time with `bcc_invalid_mention_target` — picker results are advisory, not authoritative.

#### `GET /bcc/v1/users/:handle/blog`

The member's long-form blog posts (§D6 blog tab), newest-first, cursor-paginated.

- **Auth:** Anonymous OR Bearer (V1 blog rows are public; `$viewerId` unused — reactions/permissions hydrate as defaults)
- **Path:** `handle`
- **Query:** `cursor` (optional, opaque base64url `{t,id}`, same family as `/feed`) · `limit` (optional, default 20, min 1, max 50)
- **Response 200:** `CursorEnvelope<FeedItem>` — same shape as `/feed`: `data = { items: FeedItem[] (§3.3), pagination: { next_cursor: string|null, has_more: bool } }`, scoped to `act_user_id = handle AND act_module_id = 'blog'`. The blog body carries the full `full_text` (the Floor variant omits it): `{ excerpt, full_text, wp_post_id, title, category (string|null), tags (string[]), chain_tags ([{id, slug, name, color, icon_url}]), disclosure ({tickers, note}|null), cover_image_url (string|null), cover_image_id (int|null), sources (string[]) }`.
- **Errors:** `bcc_not_found` (handle missing)
- **Cache:** `Cache-Control: private, max-age=15`; `Vary: Authorization, Cookie`
- **Mapping:** `BlogService::getUserBlog` → `PeepSoActivityRepository::getActivities` (over-fetch-by-1 `has_more`, moderation-hidden excluded per §K1-C); body via `BlogService::hydrateForPostId(..., includeFullText: true)`. Handler `UsersEndpoint::blog` (route `UsersEndpoint.php:172`).

#### `GET /bcc/v1/users/:handle/reviews`

Reviews this member has written (§V1.5 reviews-on-file tab), offset-paginated.

- **Auth:** Anonymous OR Bearer (privacy-filtered — `reviews_hidden` honored for non-self viewers)
- **Path:** `handle`
- **Query:** `page` (1-indexed, default 1), `per_page` (default 20, max 50)
- **Response 200:**
  ```json
  {
    "items": [ { "id": 481, "grade": "A", "subject": "Acme Validator", "subject_handle": "acme-validator", "text": "Reliable signing.", "scope_label": "PAGE", "posted_at_label": "3d ago" } ],
    "pagination": { "page": 1, "per_page": 20, "total": 12, "total_pages": 1 },
    "hidden": false
  }
  ```
  `grade` ∈ `A|B|C` (from `vote_type` 1/0/-1). When the target hides reviews and the viewer isn't the owner: `items: []`, totals `0`, `hidden: true`.

  `scope_label` ∈ `PAGE|MEMBER` (v1.48). Member-target rows (reviews this user wrote **about another member**): `subject` = the member's display name, `subject_handle` = their `bcc_handle` (`""` when unset — the frontend suppresses the `ON @…` link; `user_login` is never projected), `scope_label` = `MEMBER`.
- **Errors:** `bcc_not_found` (handle missing)
- **Cache:** `Cache-Control: private, max-age=15`; `Vary: Authorization, Cookie`
- **Pagination envelope:** offset (§1.5); `total_pages` is `0` (not 1) on empty/hidden.
- **Mapping:** `UserReviewsService::getReviews` → `VoteRepository::countByVoter` + `findByVoterPaginated` (+ `UserMiniRepository::getRowsByIds` for member-target subjects, v1.48); `posted_at_label` server-rendered (§A2). Handler `UsersEndpoint::reviews` (route `UsersEndpoint.php:203`).

#### `GET /bcc/v1/users/:handle/disputes`

Disputes this member has **filed** (§V1.5 disputes tab), offset-paginated. A dispute is filed by a page owner contesting a downvote on a page they own; for a member, `reporter_id` = their user id (they own the self-page being defended).

- **Auth:** Anonymous OR Bearer (privacy-filtered — `disputes_hidden` honored for non-self viewers)
- **Path:** `handle`
- **Query:** `page` (1-indexed, default 1), `per_page` (default 20, max 50)
- **Response 200:**
  ```json
  {
    "items": [ { "id": 91, "status": "open", "status_label": "OPEN", "subject": "Acme Validator", "body": "Double-signed at height 4.2M.", "scope_label": "PAGE", "posted_at_label": "Jan 2026" } ],
    "pagination": { "page": 1, "per_page": 20, "total": 3, "total_pages": 1 },
    "hidden": false
  }
  ```
  `status` ∈ `open|resolved|dismissed` (mapped from `bcc_disputes.status`: `reviewing`→`open`, `accepted`/`rejected`/`timeout_no_quorum`→`resolved`, `dismissed`→`dismissed`; unknown → `open`). `status_label` carries the richer real outcome (`OPEN`/`ACCEPTED`/`REJECTED`/`NOT DECIDED`/`DISMISSED`). `subject` is the disputed page title, falling back to `"Page removed"`; `scope_label` is `PAGE`. Hidden behaves as reviews (`items: []`, totals `0`, `hidden: true`).
- **Errors:** `bcc_not_found` (handle missing)
- **Cache:** `Cache-Control: private, max-age=15`; `Vary: Authorization, Cookie`
- **Pagination envelope:** offset (§1.5); `total_pages` is `0` on empty/hidden.
- **Mapping:** `UserDisputesService::getDisputes` → `DisputeRepository::countByReporter` + `getByReporterPaginated` (live `bcc_disputes`, reporter-keyed). Handler `UsersEndpoint::disputes` (route `UsersEndpoint.php:215`). *(Was `bcc_trust_flags`/FlagsRepository — a write-dead legacy table — until 2026-07-08; the shape is unchanged.)*

#### `GET /bcc/v1/users/:handle/activity`

Per-member activity wall — a single-author slice of the same activity stream that backs the Floor feed. Cursor-paginated.

- **Auth:** Anonymous OR Bearer (privacy-filtered — caution/risky shadow-limit, mutual-block invisibility, moderation hide, and the author-wall closed/secret/NFT-gated group leak gate apply per-viewer)
- **Path:** `handle`
- **Query:** `cursor` (optional, same family as `/feed`) · `limit` (optional, default 20, min 1, max 50)
- **Response 200:** `CursorEnvelope<FeedItem>` — identical shape to `/feed`/`/feed/hot`/`/feed/tag`: `data = { items: FeedItem[] (§3.3), pagination: { next_cursor, has_more } }`, fully hydrated (bodies, viewer reactions, author badges/ranks, social proof, permissions, group context, comment counts).
- **Errors:** `bcc_not_found` (handle missing)
- **Cache:** `Cache-Control: private, max-age=15`; `Vary: Authorization, Cookie`
- **Mapping:** `FeedRankingService::getActivityForAuthor($authorId, $viewerId, …)` — the single-brain feed composition scoped to one author; `excludedGroupIds = (non-open group ids) − (viewer membership ids)` forwarded to the SQL so wall posts in groups the viewer can't see are dropped. Handler `UsersEndpoint::activity` (route `UsersEndpoint.php:228`).

#### `GET /bcc/v1/users/:slug/followers`

"Being Watched" — the members who follow this member (§3.1 Watching tab). Offset-paginated.

- **Auth:** Anonymous OR Bearer (privacy-filtered — `watching_hidden` gates non-self viewers across BOTH followers and following)
- **Path:** `slug` (sanitized via `sanitize_user`)
- **Query:** `offset` (default 0), `limit` (default 24, max 100)
- **Response 200:**
  ```json
  {
    "items": [ { "...": "member Card view-model per §3.2 — card_kind: \"member\", member_dossier populated" } ],
    "pagination": { "offset": 0, "limit": 24, "total": 57, "has_more": true }
  }
  ```
  Each item is a full member `Card` (§3.2, `card_kind: "member"`) — same shape as `/members`. Rows for deleted users are silently skipped (so `items.length` may momentarily be < page size while `total` reflects the raw follow-edge count).
- **Errors:** `bcc_not_found` (slug missing) · `bcc_permission_denied` 403 (`watching_hidden`)
- **Cache:** `Cache-Control: private, max-age=30`
- **Pagination envelope:** offset with `has_more` (§1.5) — `offset`/`limit`/`total`/`has_more`, NOT the `page`/`per_page`/`total_pages` shape.
- **Mapping:** `UserFollowsService::listFollowers` → `PeepSoFollowerRepository::getCounts` + `getFollowers`; cards via `CardViewService::getMemberCardForList` over a `MemberSummaryPrefetcher::primeFor` batch. Handler `UserFollowsEndpoint::listFollowers` (route `UserFollowsEndpoint.php:49`).

#### `GET /bcc/v1/users/:slug/following`

"Keeping Tabs" — the members this member follows (§3.1 Watching tab). Offset-paginated.

- **Auth:** Anonymous OR Bearer (privacy-filtered — same `watching_hidden` gate as `/followers`)
- **Path:** `slug` (sanitized via `sanitize_user`)
- **Query:** `offset` (default 0), `limit` (default 24, max 100)
- **Response 200:** identical envelope to `/followers` — `{ items: Card[] (§3.2, member), pagination: { offset, limit, total, has_more } }`. `total` is the *following* count.
- **Errors:** `bcc_not_found` (slug missing) · `bcc_permission_denied` 403 (`watching_hidden`)
- **Cache:** `Cache-Control: private, max-age=30`
- **Pagination envelope:** offset with `has_more` (§1.5)
- **Mapping:** `UserFollowsService::listFollowing` → `PeepSoFollowerRepository::getCounts` + `getFollowing`; same card hydration as `/followers`. Handler `UserFollowsEndpoint::listFollowing` (route `UserFollowsEndpoint.php:60`).

#### `GET /bcc/v1/users/:slug/albums`

The member's PeepSo photo albums (§3.1 Photos tab → Albums sub-tab), privacy-filtered per-album.

- **Auth:** Anonymous OR Bearer (per-album privacy filtered in-handler: public always; members-only to logged-in; friends-only to friends + owner when the PeepSo Friends plugin is loaded, else treated as private; private to owner only)
- **Path:** `slug` (sanitized via `sanitize_user`)
- **Query:** none (full filtered set, bounded by a repository hard cap)
- **Response 200:**
  ```json
  {
    "items": [ { "id": 1201, "title": "Floor Photos", "description": null, "photo_count": 14, "cover_url": "https://…/cover.jpg", "privacy": "public", "privacy_label": "Public", "is_system_album": true, "created_at": "2026-05-01 14:30:00" } ]
  }
  ```
  No pagination block. `privacy` ∈ `public|site_members|friends_only|only_me`; `privacy_label` server-rendered (§A2). `cover_url` is `""` when unresolvable. System-album titles remapped to BCC vocabulary ("Stream Photos" → "Floor Photos").
- **Errors:** `bcc_not_found` (slug missing). Albums the viewer can't see are silently filtered (never 403).
- **Cache:** `Cache-Control: private, max-age=30`
- **Mapping:** `PeepSoAlbumRepository::getAlbumsByOwner` filtered through `PeepSoAlbumAccess::canView` (+ `PeepSoFriendGate`); cover via `PhotoRepository::resolvePhotoUrl`. Handler `UserAlbumsEndpoint::getList` (route `UserAlbumsEndpoint.php:54`).

#### `GET /bcc/v1/users/:slug/albums/:album_id/photos`

Photos inside a single album (§3.1 Photos drill-down). Album access is re-checked server-side so a stale `album_id` can't be replayed after a viewer loses access.

- **Auth:** Anonymous OR Bearer (the album's `pho_album_acc` is re-evaluated with the `/albums` access matrix; a denied album returns 404, not 403, so existence isn't leaked)
- **Path:** `slug` (sanitized via `sanitize_user`), `album_id` (positive int, `absint`)
- **Query:** none (every photo in the album, bounded by a repository hard cap)
- **Response 200:**
  ```json
  {
    "items": [ { "id": 88123, "photo_url": "https://…/photo.jpg", "source_post": { "id": 55012, "url": "https://…/?p=55012" } } ]
  }
  ```
  No pagination block. `photo_url` is `""` when unresolvable (S3 fallback). `source_post` is `{ id, url }` deep-linking the parent post, or `null`.
- **Errors:** `bcc_not_found` — slug missing, `album_id` ≤ 0, album not owned by the user, OR album exists but viewer lacks access (all collapse to one 404 message to avoid leaking existence)
- **Cache:** `Cache-Control: private, max-age=30`
- **Mapping:** `PeepSoAlbumRepository::findOneByIdAndOwner` → `PeepSoAlbumAccess::canView` → `PhotoRepository::findByAlbumId`; per-photo URL via `PhotoRepository::resolvePhotoUrl`. Handler `UserAlbumPhotosEndpoint::getList` (route `UserAlbumPhotosEndpoint.php:52`).

### 4.5 Watching

> **2026-05-13 rename:** these routes replaced `/me/binder/*` under the §1.1.1 additive-deprecation pattern. The legacy `/me/binder/*` **routes were removed 2026-06-10** (one release early — they had no remaining consumers; canonical `/me/watching/*` is the only path). The response-field aliases were likewise removed — see §4.5.1.

#### `GET /bcc/v1/me/watching`

Returns the viewer's watchlist (§C2 — UI projection of PeepSo follows + `bcc_watch_meta` sidecar). The watchlist is the canonical name for what was formerly the "binder."

- **Auth:** Bearer
- **Query:** `cursor`, `limit` (default 18 = 2 watchlist pages of 3×3, max 54), `filter` (optional: `validator|project|creator|member`), `tier` (optional: `legendary|rare|uncommon|common`)
- **Response 200:**
  ```json
  {
    "items": [
      {
        "follow_id": 88123,
        "card": { "...": "summary Card view-model" },
        "watched_at": "2026-04-22T09:14:00Z",
        "card_tier_at_watch": "rare",
        "tier_label_at_watch": "Rare",
        "batch_id": "batch_abc123"
      }
    ],
    "pagination": { "next_cursor": "...", "has_more": true }
  }
  ```
- **Errors:** `bcc_unauthorized`
- **Rate limit:** 60/min/user
- **Cache:** `Cache-Control: private, max-age=30`
- **Mapping:** JOIN of `peepso_user_followers` + `bcc_watch_meta` (§C2). Removed cards (unwatched) do not appear. `card_tier_at_watch` is the `card_tier` (`legendary|rare|uncommon|common|null`) at the moment the card was watched, not the current tier — preserved for historical narrative. `tier_label_at_watch` is the pre-rendered display string per §A2.

#### `GET /bcc/v1/me/watching/summary`

Returns the viewer's identity-snapshot (§N9) — pre-computed tier distribution + monthly activity counts for the watchlist header.

- **Auth:** Bearer
- **Response 200:**
  ```json
  {
    "handle": "simontx",
    "watching_size": 39,
    "tier_distribution": {
      "legendary": { "count": 2, "percent": 5 },
      "rare":      { "count": 7, "percent": 18 },
      "uncommon":  { "count": 14, "percent": 36 },
      "common":    { "count": 14, "percent": 36 },
      "unknown":   { "count": 2, "percent": 5 }
    },
    "monthly_activity": {
      "reviews_written":  4,
      "solids_received":  12,
      "disputes_signed":  1
    }
  }
  ```
- **Errors:** `bcc_unauthorized`
- **Rate limit:** 60/min/user
- **Cache:** `Cache-Control: private, max-age=60`
- **Mapping:** server-pre-computed identity snapshot per §A2 / §L5; clients render, never derive percentages.

#### `POST /bcc/v1/me/watching/watch`

Starts watching a card (= creates a PeepSo follow + `bcc_watch_meta` row).

- **Auth:** Bearer
- **Body:**
  ```json
  {
    "card_kind": "validator",
    "card_id": 1842
  }
  ```
- **Response 201 (new watch):**
  ```json
  {
    "follow_id": 88123,
    "watching_size": 39,
    "already_watching": false,
    "card": { "...": "summary Card view-model" },
    "celebration": {
      "kind": "light",
      "label": "Now watching Blacksmith Node.",
      "icon": "watch",
      "rarity_tint": "legendary"
    },
    "feed_post": {
      "kind": "deferred_batch",
      "batch_id": "batch_abc456",
      "estimated_post_at": "2026-04-27T14:38:00Z"
    }
  }
  ```
- **Response 200 (already watching — idempotent):**
  ```json
  {
    "follow_id": 88123,
    "watching_size": 38,
    "already_watching": true,
    "card": { "...": "summary Card view-model" },
    "celebration": null,
    "feed_post": null
  }
  ```
- **Errors:**
  - `bcc_invalid_request` — bad card kind/id
  - `bcc_permission_denied` — viewing your own member card and trying to watch yourself
  - `bcc_rate_limited` — soft limit (per §L3)
- **Rate limit:** 120/hour/user (soft), 600/day/user (hard)
- **Cache:** `Cache-Control: no-store`
- **Mapping:**
  - Side effects: PeepSo follow created, `bcc_watch_meta` row inserted, `bcc_card_watched` event emitted (§A3 async — request returns before subscribers run). `bcc_card_watched` is the only event emitted — the legacy `bcc_card_pulled` event was removed per §4.5.1.
  - `feed_post.kind: "deferred_batch"` always (per §C3 — watches go through the rolling-window aggregator before becoming a feed post). The `estimated_post_at` = the current watch's timestamp + **10 minutes** (every watch resets the inactivity window per §C3). The frontend may show a passive "+1 to your watchlist" without waiting for the feed.
  - **Idempotency (`already_watching`):** if the card is already in the viewer's watchlist when this endpoint is called (e.g., user double-clicks Watch, or two tabs race), the server returns `200` with `already_watching: true`, the existing `follow_id`, the current `watching_size` (unchanged), `celebration: null`, and `feed_post: null`. No new row is inserted, no event re-emitted, no double-celebration fired. The frontend uses this signal to skip the dopamine animation on the second call.

#### `DELETE /bcc/v1/me/watching/:follow_id`

Stops watching a card (= PeepSo unfollow).

- **Auth:** Bearer
- **Path:** `follow_id` (the PeepSo follow row ID, returned by `GET /me/watching`)
- **Response 200:**
  ```json
  {
    "removed": true,
    "watching_size": 38
  }
  ```
- **Errors:** `bcc_not_found`, `bcc_unauthorized`
- **Rate limit:** 60/min/user
- **Cache:** `Cache-Control: no-store`
- **Mapping:** PeepSo unfollow + cascade `bcc_watch_meta`. **Does not** edit any prior feed post per §C3.

### 4.5.1 Removed: `/me/binder/*` routes (use `/me/watching/*`)

The legacy `/me/binder/*` **routes were removed 2026-06-10** — one release early, under
the fresh-install / no-backcompat policy (they were pure aliases of `/me/watching/*` with
zero remaining consumers). Old route → use instead:

| Removed route | Use instead |
|---|---|
| `GET /bcc/v1/me/binder` | `GET /bcc/v1/me/watching` |
| `GET /bcc/v1/me/binder/summary` | `GET /bcc/v1/me/watching/summary` |
| `POST /bcc/v1/me/binder/pull` | `POST /bcc/v1/me/watching/watch` |
| `DELETE /bcc/v1/me/binder/:follow_id` | `DELETE /bcc/v1/me/watching/:follow_id` |

The §1.1.1 **response-field** aliases that rode alongside these routes have likewise been
**removed** — the pull→watch vocabulary unification collapsed the runway early under the
fresh-install / no-backcompat policy (same basis the routes were removed on). The legacy
field names (`pulled_at`, `tier_at_pull`, `card_tier_at_pull`, `tier_label_at_pull`,
`binder_size`, `already_in_binder`), the `links.binder` profile-link alias, and the
`bcc_card_pulled`/`bcc_card_unpulled` events emitted in parallel with `bcc_card_watched`
are gone. The canonical names are the only ones emitted: `watched_at`, `card_tier_at_watch`,
`tier_label_at_watch`, `watching_size`, `already_watching`, `links.watching`,
`bcc_card_watched`. The physical storage tables/columns were also renamed
(`bcc_pull_meta`→`bcc_watch_meta`, `bcc_pull_batches`→`bcc_watch_batches`, the `*_at_pull`
columns→`*_at_watch`) via a data-preserving migration. Canonical celebration copy is
`"Now watching <name>."` (icon `watch`).

### 4.6 Pages (claim flow)

#### `POST /bcc/v1/pages/:id/claim`

Claims a validator/creator/project page using a wallet signature (§B5, §N8).

- **Auth:** Bearer (the user must be signed in; the wallet does the proving)
- **Path:** `id` (the `peepso_pages` ID)
- **Body:**
  ```json
  {
    "wallet_address": "cosmosvaloper1abc…",
    "chain": "cosmos",
    "nonce": "8f3a7b2e9c1d4f6a",
    "signature": "<hex>",
    "public_key": "<hex, optional>"
  }
  ```
- **Response 201 (success):**
  ```json
  {
    "claimed": true,
    "page_id": 1842,
    "claimed_by_user_id": 42,
    "card": { "...": "updated Card view-model with is_claimed: true" },
    "celebration": {
      "kind": "heavy",
      "label": "Page claimed. The stream is yours.",
      "icon": "claim-stamp",
      "rarity_tint": null
    },
    "next_steps": [
      { "kind": "edit_bio",      "label": "Write your bio",       "href": "/v/blacksmith-node?compose=bio" },
      { "kind": "first_post",    "label": "Post your first update", "href": "/v/blacksmith-node?compose=status" }
    ]
  }
  ```
- **Errors:**
  - `bcc_invalid_request` (bad signature, wrong chain, expired nonce)
  - `bcc_permission_denied` (signed wallet is not in the page's claim key set per §B5)
  - `bcc_conflict` (page already claimed by someone else; payload includes `claimed_by_handle`)
  - `bcc_rate_limited`
- **Rate limit:** 3/hour/user, 10/day/IP, **30 attempts/day/page** (caps total claim attempts on a single page across all users to prevent attempt flooding per §B5)
- **Cache:** `Cache-Control: no-store`
- **Mapping:**
  - Verifies signature via `bcc-core` `WalletVerifier`
  - Checks wallet against the page's claim key set: **operator address + consensus pubkey ONLY** (§B5 — delegators excluded)
  - Single-claim-wins via unique index on `peepso_pages.claimed_by` (§B5 race rule)
  - Emits `bcc_page_claimed` (§A3 async)
  - Lost-wallet edge case (§B5): if the page is in `claim_recovery_pending` state, this endpoint returns 409 with a recovery hint — the user must use the admin recovery flow.

#### `POST /bcc/v1/pages/:id/avatar`

Claimer uploads a custom image for a claimed page (validator/project/creator),
overriding the auto-imported logo. Gated by the card's `can_edit_image`
permission.

- **Auth:** Bearer; caller MUST hold a **verified `page` claim** on `:id`.
- **Path:** `id` (the `peepso-page` post id)
- **Body:** `multipart/form-data` with field `avatar` (JPEG/PNG/WebP/GIF, ≤8 MiB; MIME validated from file magic, not the header).
- **Response 200:**
  ```json
  { "page_id": 1842, "image_url": "https://…/wp-content/uploads/…png" }
  ```
- **Errors:** `bcc_unauthorized` (401, not signed in), `bcc_forbidden` (403, not the verified claimer), `bcc_not_found` (404), `bcc_invalid_request` (400, missing/oversized/wrong-type file), `bcc_rate_limited` (429), `bcc_unavailable` (503, storage failure).
- **Cache:** `Cache-Control: no-store`.
- **Mapping:** persists via `BlogCoverImageWriter` (uploader-owned attachment) → `set_post_thumbnail`. The crest resolver ranks the page thumbnail **above** the auto-imported `bcc_onchain_validators.logo_url`, so the upload wins. Refetch the card (or `/cards/:type/:id`) to get the updated `crest.image_url`.

#### `DELETE /bcc/v1/pages/:id/avatar`

Claimer removes their uploaded image, reverting to the auto-imported logo (or
the initials crest).

- **Auth:** Bearer; verified `page` claimer only (same gate as POST).
- **Response 200:** `{ "page_id": 1842, "image_url": null }`
- **Errors:** same gate errors as POST (`bcc_unauthorized`, `bcc_forbidden`, `bcc_not_found`).
- **Mapping:** `delete_post_thumbnail`; the next card read falls back through the crest precedence (auto logo → initials).

**Permission note (`can_edit_image`):** present on every Card's `permissions`.
`{allowed:true}` only for the verified claimer of a validator/project/creator
page; `{allowed:false, reason_code:"not_claimer"}` for other authenticated
viewers, `signin_required` when anonymous, and `not_applicable` on member cards
(member self-avatars use `POST /me/profile/avatar`).

### 4.7 Locals

#### `GET /bcc/v1/locals`

List of available Locals (PeepSo Groups in BCC clothing).

- **Auth:** Anonymous OR Bearer
- **Query:** `page` (default 1), `page_size` (default 20, max 50), `chain` (optional filter)
- **Response 200:**
  ```json
  {
    "items": [
      {
        "id": 12,
        "slug": "cosmos-base-fan",
        "name": "Local 342 Cosmos Base Fan",
        "number": 342,
        "chain": "cosmos",
        "member_count": 412,
        "viewer_membership": {
          "is_member": true,
          "is_primary": true,
          "joined_at": "2026-01-12T00:00:00Z"
        },
        "links": { "self": "/locals/cosmos-base-fan" }
      }
    ],
    "pagination": { "page": 1, "page_size": 20, "total": 142, "total_pages": 8 }
  }
  ```
- **Cache:** `Cache-Control: public, max-age=300`
- **Mapping:** `peepso-group` CPT posts filtered to BCC-tagged groups (V1 filter: `post_title LIKE 'Local %'` per §E3 convention), JOINed with `peepso_group_members` for `member_count` and with `bcc_user_locals` for viewer membership.

**`viewer_membership` semantics:**
- Anonymous viewer: `null` (no current user).
- Authenticated non-member: `{ "is_member": false, "is_primary": false, "joined_at": null }`.
- Authenticated member: `{ "is_member": true, "is_primary": <bool>, "joined_at": "<iso8601>" }`.

**Pagination:** uses **offset** envelope per §1.5 (Locals is a directory, not a time-ordered feed). Cursor pagination is reserved for `/feed`, `/feed/hot`, and `/me/watching`.

#### `POST /bcc/v1/me/locals/:id/membership`

Join a Local. The join/leave verb is `/membership` — same path, two methods.

- **Auth:** Bearer
- **Body:** `{ "set_as_primary": false }` (optional, default false)
- **Response 201:**
  ```json
  {
    "joined": true,
    "local": { "...": "Local view-model" },
    "celebration": {
      "kind": "mid",
      "label": "Joined Local 342 Cosmos Base Fan.",
      "icon": "local-badge",
      "rarity_tint": null
    }
  }
  ```
- **Errors:** `bcc_unauthorized`, `bcc_forbidden` (403 — account suspended "Your account is suspended.", or the Local does not accept open membership), `bcc_conflict` (already a member), `bcc_rate_limited`
- **Rate limit:** 10/hour/user
- **Mapping:** PeepSo group join + `bcc_user_locals` insert. Emits `bcc_local_joined` (§A3 async).

#### `DELETE /bcc/v1/me/locals/:id/membership`

Leave a Local. Sibling of the join above.

- **Auth:** Bearer
- **Response 200:** `{ "left": true }`
- **Errors:** `bcc_unauthorized`, `bcc_not_found`
- **Mapping:** PeepSo group leave + cascade `bcc_user_locals`. If the user was using this Local as primary, `primary_local` becomes `null` until they pick another (or until they explicitly clear via `DELETE /me/locals/primary`). Emits `bcc_local_left`.

#### `POST /bcc/v1/me/locals/:id/primary`

Mark a Local as the user's primary.

- **Auth:** Bearer
- **Response 200:**
  ```json
  {
    "primary_local": { "...": "Local view-model" }
  }
  ```
- **Errors:** `bcc_unauthorized`, `bcc_forbidden` (not a member of this Local)
- **Mapping:** Updates `bcc_user_locals.is_primary` (singleton — exactly one row per user has `is_primary: true`). Emits `bcc_local_primary_changed`.

#### `DELETE /bcc/v1/me/locals/primary`

Clear the user's primary-Local pointer. Note the path is bare `/primary`
(no `:id`) — clearing is identity-only since exactly one row per user
ever carries `is_primary: true`.

- **Auth:** Bearer
- **Response 200:** `{ "cleared": true }`
- **Errors:** `bcc_unauthorized`
- **Mapping:** Sets `bcc_user_locals.is_primary = 0` for the row that
  currently holds it (if any). Idempotent — calling when no primary is
  set still returns 200 with `cleared: true`. Emits `bcc_local_primary_changed`.

#### Local detail page — composition note (no new REST surface)

A Local is a semantic wrapper around a PeepSo group; the slug is identical on both routes (`peepso-page.post_name`). The Next.js Local detail page (`/locals/[slug]`) therefore composes the existing view-models in parallel rather than introducing a Locals-specific feed endpoint:

- `GET /bcc/v1/locals/:slug` → header, membership pill, join/leave controls (this section).
- `GET /bcc/v1/groups/:slug` → `GroupDetailResponse` with the server-authoritative `feed_visible` + `permissions.can_read_feed.unlock_hint` gate consumed by `<GroupFeedSection>` (§4.7.5).
- `GET /bcc/v1/groups/:id/feed` → cursor-paginated feed entries inside `<GroupFeedSection>` via `useGroupFeed` (§4.7.6).

**Group-feed visibility filter (§4.7.6, v1.24):** `GET /bcc/v1/groups/:id/feed` now returns a feed for non-members of non-secret groups instead of refusing them. Members get the **full** feed (all visibilities). Non-members of an `nft` / `closed` / `open` (non-secret) group get a **public-only filtered feed** — only `public_group` + `public_all` posts (per the `_bcc_post_visibility` post-meta set at compose time; see §4.14 / §4.15); `members_only` posts, and any post with no visibility meta, are never returned to non-members. This is the read-only teaser surface. **Secret groups are unchanged**: non-members still get `bcc_not_found 404` (existence never leaks). Consequently the §4.7.5 `GroupDetailResponse` now reports `feed_visible: true` and `permissions.can_read_feed.allowed: true` for non-members of `nft` / `closed` groups, so `<GroupFeedSection>` renders the teaser feed rather than a locked notice. (Previously non-members of NFT/closed groups received `403 bcc_permission_denied` from the feed endpoint and `feed_visible: false` from the detail view-model.)

No `/bcc/v1/locals/:slug/feed` endpoint exists or is planned. The two read calls are independent: a failed `/groups/:slug` read does not 500 the page — the header still renders and the feed slot shows a non-blocking notice. A 404 from `/locals/:slug` is still authoritative for page existence (Next `notFound()`).

### 4.7.1 Holder Groups (NFT-gated)

NFT-gated PeepSo groups: one closed group per admin-verified collection. Holders see suggestions and join explicitly (suggest-don't-auto-join). Auto-join is opt-in via a per-user preference. Privacy is `closed` (defense in depth) — non-members see the group exists but content is gated by both PeepSo and our server-side eligibility check.

**Shared shapes (apply across all 4.7.x endpoints):**

`verification` block — present on holder groups, `null` elsewhere:
```json
{ "kind": "on_chain", "label": "On-Chain Verified" }
```
Server-authoritative copy. The frontend MUST render `label` verbatim — never abbreviate to "Verified" alone.

`activity` block — present on every holder-group item:
```json
{
  "posts_last_7d": 14,
  "active_members_last_7d": 0,
  "last_activity_at": "2026-05-04T14:22:00+00:00",
  "heat": "warm",
  "heat_label": "Warm"
}
```
- `heat` ∈ `cold` | `warm` | `hot`. Server-bucketed (default thresholds: cold ≤ 2 posts/7d, warm 3–9, hot ≥ 10). Filterable via `bcc_group_heat_thresholds`.
- `heat_label` is the server-authoritative display string for the bucket (defaults: `Hot` / `Warm` / `Quiet`). Frontend renders verbatim per §A2 — no client-side `heat === "hot" ? "Hot" : …` mapping. Filterable via `bcc_group_heat_label`.
- `last_activity_at` is `null` when no posts in window or when the underlying timestamp is invalid.
- `active_members_last_7d` is reserved for v2.5; emit `0` until then.

#### `GET /bcc/v1/me/holder-groups`

The user's holder-groups state — joined groups, eligible-to-join suggestions, and previously opted-out groups.

- **Auth:** Bearer (401 anonymous)
- **Response 200:**
  ```json
  {
    "joined": [
      {
        "group_id": 4231,
        "slug": "holders-bored-apes",
        "name": "Holders: Bored Apes",
        "member_count": 87,
        "collection": {
          "chain": "ethereum",
          "contract": "0xbc4ca0eda7647a8ab7c2061c2e118a18a936f13d",
          "name": "Bored Apes",
          "image_url": "https://bluecollar.crypto/wp-content/uploads/.../bayc.png"
        },
        "verification": { "kind": "on_chain", "label": "On-Chain Verified" },
        "activity": {
          "posts_last_7d": 14,
          "active_members_last_7d": 0,
          "last_activity_at": "2026-05-04T14:22:00+00:00",
          "heat": "warm"
        }
      }
    ],
    "eligible_to_join": [ /* same item shape; user qualifies but isn't a member */ ],
    "opted_out": [ /* same item shape; user explicitly left or was mod-removed */ ]
  }
  ```
- **Cache:** `private, no-store` (per-viewer membership state).
- **Mapping:** `peepso-group` posts where `_bcc_group_kind = 'holders'`, joined with `peepso_group_members` for membership and `wp_bcc_onchain_collections` for collection display. Eligibility uses `HoldingsService::ownsAnyMany` (one batched holdings call per chain). Opt-out is read from `bcc_gated_groups_optout` user_meta with TTL applied.
- **Cold-start protection:** the `activity.heat` field is the user's escape hatch from ghost-town suggestions — frontend may sort `eligible_to_join[]` by heat to surface active rooms first.

#### `POST /bcc/v1/me/holder-groups/:id/join`

Explicit user-initiated join. Verifies eligibility server-side, clears any active opt-out for this group, and lands the user as a full member regardless of the group's `closed` privacy flag.

- **Auth:** Bearer (401 anonymous)
- **Response 200:**
  ```json
  { "joined": true, "group_id": 4231, "code": "ok" }
  ```
  `code` ∈ `ok` | `already_member` (idempotent re-join). Both are 200; the code distinguishes the side effect.
- **Errors:**
  - `bcc_forbidden` (403) — account suspended ("Your account is suspended."). Holding the NFT is the eligibility gate, not an override of moderation; admin bypass off.
  - `bcc_invalid_request` (400) — group is not a holder group
  - `bcc_permission_denied` (403) with `unlock_hint`:
    - opt-out cooldown active: "You opted out of this community recently. Try again later or rejoin from the discovery page."
    - holder check failed: "Hold a `<CollectionName>` NFT to join this community." (or "Hold at least N NFTs from this collection..." for `min_balance > 1`)
  - `bcc_internal_error` (503) — chain unsupported (transient infra issue)
  - `bcc_upstream_unavailable` (503) — the chain provider could not verify NFT
    ownership (timeout / 429 / circuit-breaker open / malformed response), OR
    the membership write itself failed / was refused (PeepSo unavailable, or
    the writer refused a banned membership row — v1.37).
    Transient; retry with backoff. The join **fails closed** — the user is NOT
    added when ownership can't be confirmed, so an outage never grants access.
    (Mirrors the §J read-time 503 precedent; the §1.4.6 standard-codes table
    lists this code at 502, but holder-gating emits 503 — both are valid for
    this code.)
- **Mapping:** `NftGroupGateService::joinIfEligible` → `PeepSoGroupWriter::join` (which fires `peepso_action_group_user_join` and recomputes `peepso_group_members_count`). A provider-verification failure short-circuits to `bcc_upstream_unavailable` before any join write; a `false` writer return maps to the same code (fail-closed, opt-out untouched, no audit row).

#### `POST /bcc/v1/me/holder-groups/:id/leave`

Leave a holder group and record a TTL'd opt-out so the reconcile sweep doesn't re-add the user (default 90 days; filterable via `bcc_gated_group_optout_ttl`).

- **Auth:** Bearer (401 anonymous)
- **Response 200:** `{ "left": true, "group_id": 4231 }`
- **Errors:**
  - `bcc_invalid_request` (400) — group is not a holder group
  - `bcc_permission_denied` (403) — caller is the group owner ("Owners cannot leave their own community...")
- **Mapping:** `PeepSoGroupWriter::leave` (refuses owners, fires `peepso_action_group_user_delete`) → opt-out timestamp written to `bcc_gated_groups_optout` user_meta. Mod-initiated removals (PeepSo's UI) record the opt-out as **permanent** (timestamp `0`) so banned users aren't re-added by the reconcile sweep.

#### `GET /bcc/v1/me/holder-groups/preferences`

Read the user's holder-group preferences.

- **Auth:** Bearer (401 anonymous)
- **Response 200:** `{ "auto_join": false }`
- **Cache:** `private, no-store`

#### `PATCH /bcc/v1/me/holder-groups/preferences`

Toggle `auto_join`. Default is `false` (suggest-don't-auto-join). Setting to `true` runs reconcile **synchronously** and the response includes the immediate join count — the user doesn't wait for the next cron tick.

- **Auth:** Bearer (401 anonymous)
- **Body:** `{ "auto_join": true }`
- **Response 200:**
  ```json
  {
    "auto_join": true,
    "reconciled": { "joined": 3, "skipped": 0 }
  }
  ```
  `reconciled.joined` is `0` when toggling OFF (no work to do).
- **Errors:** `bcc_invalid_request` (422) when no `auto_join` field is provided.
- **Mapping:** Writes `bcc_auto_join_eligible_groups` user_meta. The reconcile sweep cron (`bcc_gated_group_reconcile_sweep`, twicedaily, 20 users/tick) only iterates users with this meta set.

### 4.7.2 Profile Groups Tab

#### `GET /bcc/v1/users/:slug/groups`

Cross-kind list of all PeepSo groups a target user is an active member of: holder groups, Locals, plain user groups, and system groups all flow through the same shape via `GroupContextResolver`.

- **Auth:** Anonymous OR Bearer
- **Path:** `slug` matches the canonical `[a-z0-9][a-z0-9-]{1,18}[a-z0-9]` handle pattern (lowercase alphanumeric + hyphens, 3–20 chars). 404 with `bcc_not_found` for invalid or unknown handles.
- **Privacy filtering (server-authoritative):**
  - `secret` groups → included **only if the viewer is also a member**
  - `closed` groups → always included; non-members see name + member_count, content stays private at PeepSo's layer
  - `open` groups → always included
- **Response 200:**
  ```json
  {
    "items": [
      {
        "group_id": 4231,
        "slug": "holders-bored-apes",
        "name": "Holders: Bored Apes",
        "type": "nft",
        "type_label": "On-Chain Holders",
        "member_count": 87,
        "privacy": "closed",
        "verification": { "kind": "on_chain", "label": "On-Chain Verified" },
        "actions": {
          "join":  { "url": "/wp-json/bcc/v1/me/holder-groups/4231/join" },
          "leave": { "url": "/wp-json/bcc/v1/me/holder-groups/4231/leave" }
        },
        "permissions": {
          "can_join":  { "allowed": false, "unlock_hint": null, "reason_code": "already_member" },
          "can_leave": { "allowed": true,  "unlock_hint": null, "reason_code": null }
        }
      }
    ]
  }
  ```
- **Cache:** `private, max-age=30` (per-viewer privacy + permissions; short TTL is enough for tab navigation).
- **Field shapes:**
  - `type` ∈ `nft` | `local` | `user` | `system`. The frontend uses this to pick the action URL and to dispatch the right mutation hook (holder / local / plain).
  - `type_label` is the server-authoritative display string for the kind (defaults: `On-Chain Holders` / `Local` / `System` / `Group`). Frontend renders verbatim per §A2 — no client-side enum→label mapping. Filterable via `bcc_group_type_label`.
  - `privacy` ∈ `open` | `closed` | `secret`. Mirrors PeepSo's privacy flag.
  - `verification` is `null` for non-NFT types. Future verification kinds (e.g. `creator_verified`) will appear here without API shape changes.
  - `actions.join.url` / `actions.leave.url` vary by `type`: `/me/holder-groups/{id}/{action}` for `nft`, `/me/locals/{id}/{action}` for `local`, `/me/groups/{id}/{action}` for `user`/`system`. Frontend follows whatever URL the server returns.
- **Permission shape (per §A4 / §N7 — gated actions always visible):**
  - **Self profile** (viewer is the target): `can_leave.allowed = true` for every group; `can_join.allowed = false` with `reason_code: "already_member"`.
  - **Other profile**:
    - `can_leave.allowed = false` always (`reason_code: "not_self"`).
    - `can_join.allowed` reflects **the viewer's** eligibility for that group:
      - `nft` group: `true` if the viewer holds the gated NFT, otherwise `false` with `unlock_hint: "Hold an NFT from this collection to join."`
      - `closed` group: `false` with `unlock_hint: "Visit the group page to request to join."`, `reason_code: "requires_approval"`
      - `secret` group: `false` with `reason_code: "invite_only"`
      - `open` group: `true`
- **Mapping:** `PeepSoGroupRepository::getUserMemberGroupIds` → `GroupContextResolver::forManyGroups` for type/verification/privacy → `PeepSoGroupRepository::findManyByIds` for display fields. Holder eligibility for the viewer is computed via a single batched `HoldingsService::ownsAnyMany` call (no N+1 across NFT groups in the result).

### 4.7.3 Plain Group Membership

For non-gated, non-Local PeepSo groups (the residual case: user/system groups created via PeepSo's UI). Holder groups use §4.7.1; Locals use §4.7.

#### `POST /bcc/v1/me/groups/:id/join`

- **Auth:** Bearer (401 anonymous)
- **Response 200:** `{ "joined": true, "group_id": 4231 }`
- **Errors:**
  - `bcc_forbidden` (403) — account suspended ("Your account is suspended."). Checked before everything else; admin bypass off — a suspended account is blocked regardless of role.
  - `bcc_invalid_request` (404) — group not found
  - `bcc_invalid_request` (400) — group is a holder group or Local; use the dedicated endpoint
  - `bcc_permission_denied` (403):
    - `closed` group: "This community requires admin approval. Visit the group page to request access."
    - `secret` group: "This community is invite-only."
  - `bcc_unavailable` (503) — the membership write failed or was refused (PeepSo unavailable, or the writer refused a banned membership row). Fail-closed; nothing was joined. Same surface as the Locals join.
- **Mapping:** Resolves `GroupContext`; if `type` is `nft` or `local` rejects (use the dedicated endpoint); for `open` groups calls `PeepSoGroupWriter::join` and honors its verdict — a `false` return is surfaced as `bcc_unavailable`, never as `joined: true`. Closed/secret are not joined here — PeepSo's request-flow / invitation machinery is not replicated by this endpoint.

#### `POST /bcc/v1/me/groups/:id/leave`

- **Auth:** Bearer (401 anonymous)
- **Response 200:** `{ "left": true, "group_id": 4231 }`
- **Errors:**
  - `bcc_invalid_request` (404) — group not found
  - `bcc_invalid_request` (400) — group is a holder group or Local
  - `bcc_permission_denied` (403) — caller is the group owner
- **Mapping:** `PeepSoGroupWriter::leave` (refuses owners; PeepSo's `member_leave` recomputes `peepso_group_members_count` internally).

#### `POST /bcc/v1/me/groups/:id/post-policy`

Owner/manager/site-admin control for a single group knob: may **ordinary members** of this group set `visibility=public_all` (syndicate a post to the global feed)? Default is **off** — in a closed/secret group only the owner / manager / moderator roles may syndicate until an owner or manager opts ordinary members in. No effect on `open` groups, where any posting member may already syndicate. (Note: a **moderator** may *use* `public_all` on their own post but may **not** change this policy — see §4.14 + the `can_manage_public_all_policy` flag.)

- **Auth:** Bearer (401 anonymous)
- **Body:** `public_all_members` (boolean, required — strict boolean; a missing or non-boolean value is rejected by the arg schema) — enable/disable ordinary-member syndication.
- **Response 200:** `{ "ok": true, "public_all_members_enabled": true }` (the returned flag reflects the new state immediately).
- **Errors:**
  - `bcc_rate_limited` (429) — throttle (20 / 60s per user)
  - `bcc_not_found` (404) — group not found **OR** a secret group the caller is not a member of (existence never leaked)
  - `bcc_permission_denied` (403) — caller is not the group owner, a manager, or a site admin (`manage_options`) — moderators and ordinary members included
  - `bcc_invalid_request` (400) — missing/invalid group id
- **Mapping:** `GroupsService::setPublicAllMembersPolicy` — `resolveGroupAccess` (existence + secret-gate → 404, no leak), then the canonical `GroupsService::canManagePublicAllPolicy` (owner/manager/`manage_options`; moderator excluded), then `GroupPostPolicyRepository::setPublicAllMembers` writes the `_bcc_group_public_all_members` post-meta ('1' on; deleted on off). The read side is surfaced on the group-detail response (§4.7 `can_use_public_all` / `can_manage_public_all_policy` / `public_all_members_enabled`).

### 4.7.4 Groups Discovery

#### `GET /bcc/v1/groups`

Cross-kind discovery list. Sort key: `verified DESC, heat_score DESC, member_count DESC`. Verified groups rank above non-verified, but active verified beats sleepy verified — so a "verified but dead" community doesn't dominate the discovery surface.

- **Auth:** Anonymous OR Bearer
- **Query:** `verified` (0/1; default 0), `page` (default 1), `page_size` (default 20, max 50)
- **Response 200:**
  ```json
  {
    "items": [
      {
        "group_id": 4231,
        "slug": "holders-bored-apes",
        "name": "Holders: Bored Apes",
        "type": "nft",
        "member_count": 87,
        "privacy": "closed",
        "verification": { "kind": "on_chain", "label": "On-Chain Verified" },
        "description": "On-chain verified holders of Bored Apes. Auto-managed.",
        "image_url": "https://bluecollar.crypto/wp-content/uploads/.../bayc.png",
        "collection_stats": {
          "token_standard": "ERC-721",
          "total_supply": 10000,
          "unique_holders": 5421,
          "floor_price": "12.34000000",
          "floor_currency": "ETH",
          "total_volume": "987654.00000000",
          "listed_percentage": 3.42,
          "royalty_percentage": 5.00,
          "distribution_pct": 54,
          "min_balance": 1,
          "floor_display": "12.34 ETH",
          "volume_display": "987.7K ETH",
          "holders_display": "5,421 (54% dist.)",
          "supply_display": "10,000",
          "listed_display": "3.42%",
          "royalty_display": "5.00%",
          "min_balance_display": "1 NFT",
          "marketplace": {
            "url": "https://opensea.io/assets/ethereum/0xbc4ca0eda7647a8ab7c2061c2e118a18a936f13d",
            "label": "OpenSea"
          }
        },
        "activity": {
          "posts_last_7d": 14,
          "active_members_last_7d": 0,
          "last_activity_at": "2026-05-04T14:22:00+00:00",
          "heat": "warm",
          "heat_label": "Warm"
        },
        "card": { "...": "community Card view-model per §3.2.4 — card_kind: \"community\", community_dossier populated" }
      }
    ],
    "pagination": { "page": 1, "page_size": 20, "total": 142, "total_pages": 8 }
  }
  ```
- **`card` (v1.27, additive):** the full §3.2.4 community Card, composed from the same row data as the flat fields (zero per-item queries; viewer membership for `community_dossier.viewer_is_member` is one batched lookup over the page of items). New consumers render `item.card` via the CardFactory; the flat fields remain during the migration window.
- **Cache:** anon → `Cache-Control: public, max-age=60` (60s window keeps newly-warming groups discoverable quickly); authed → `private, no-store` (v1.27 — each item's `card.community_dossier.viewer_is_member` is viewer-scoped, so authed responses must never sit in a shared cache; this matches the §4.7.5 detail posture).
- **Privacy:** `secret` groups never appear here regardless of viewer. `closed` groups appear with name + member_count visible; content stays private at PeepSo's layer.
- **Filter `verified=1`:** restricts to groups with `_bcc_group_kind = 'holders'`. Use this to render an "On-Chain Verified only" filter chip on the discovery page.
- **`image_url`:** cover-art URL. NFT-type cards return the underlying collection's `image_url` (joined through `wp_bcc_onchain_collections`). Non-NFT cards (`local`/`system`/`user`) return `null` in V1 — the frontend falls back to a generated initials block. PeepSo group avatars for non-NFT kinds is V1.5.
- **`description`:** group post body, plain-text + tag-stripped + truncated to ~200 chars (em-dash ellipsis when truncated). `null` when the group has no description on file. Applies to all kinds — `local`/`system`/`user` cards can use the same field on a future detail surface.
- **`collection_stats`:** market-data block for NFT-type cards only — drives the discovery card's flip-to-back UX (floor price, holder distribution, lifetime volume, listed %, royalty %). Each inner field is independently nullable since the upstream fetch can leave any column unpopulated. Currency-bearing fields (`floor_price`, `total_volume`) are returned as raw strings (full decimal precision) PLUS server-pre-formatted `*_display` strings (`floor_display`, `volume_display`, `holders_display`, `supply_display`, `listed_display`, `royalty_display`, `min_balance_display`). Frontend renders `*_display` verbatim per §A2 / §S — no client-side number-formatting decisions. `distribution_pct` is the server-computed `holders / supply * 100` (rounded), exposed as a number alongside `holders_display` for charting use. `min_balance` mirrors the gate threshold (`_bcc_gate_min_balance` post-meta). Em-dash (`"—"`) appears in `*_display` when the underlying value is missing/zero so the wire never surfaces "0.00 STARS" as a fake-low signal. Non-NFT cards return `null` for the entire block — there is no equivalent for `local`/`system`/`user` kinds.
- **`collection_stats.marketplace`:** server-resolved canonical marketplace link for the underlying NFT collection — `{ url, label }` when the chain is mapped, `null` otherwise. V1 covers the Cosmos Hub (`cosmos` — stargaze.zone, the marketplace app that survived the 2026 Stargaze→Hub chain migration) and the major EVM chains via OpenSea (`ethereum`/`polygon`/`arbitrum`/`optimism`/`base`/`avalanche`/`bsc`); Solana, NEAR, and the other cosmos chains return `null` until canonical marketplace surfaces are picked. Filterable via `bcc_marketplace_link_map` so a deployment can extend or override without a code release. Frontend renders the URL verbatim with `target="_blank" rel="noopener noreferrer"` and `e.stopPropagation()` on click so the marketplace tab opens without flipping the discovery card back to the front.
- **Sort approximation note:** the candidate pool is fetched + sorted in PHP before pagination (limit 500). The cross-page sort is exact within the candidate pool; deep pagination beyond ~500 groups would require SQL-side sort. v1 scale is well under this.
- **Mapping:** `PeepSoGroupRepository::listBrowsableGroupIds` (excludes secret) → `GroupContextResolver::forManyGroups` → `GroupActivityHeatService::forGroups` for heat → `GatedGroupRepository::listAllGatedGroupConfigs` + `CollectionRepository::findManyByIds` for image_url + collection_stats enrichment (NFT-type only) → in-memory sort by (`is_verified`, `posts_last_7d`, `member_count`) all DESC.

### 4.7.9 Tour Seen Store

Server half of the site-wide product-tour "seen" store (bcc-trust `MeToursSeenEndpoint`). The frontend's `useToursSeen` unions this with `localStorage` so "seen" survives a device switch. Tour ids are frontend-registry data (`src/lib/tour/registry.ts`) — deliberately **not** enum-validated server-side, so adding a tour stays a frontend-only change; only the slug shape is checked.

#### `GET /bcc/v1/me/tours-seen`

- **Auth:** Bearer (401 anonymous).
- **Response 200:** `{ "seen": string[] }` — the tour ids this viewer has dismissed.
- **Cache:** `private, no-store`.

#### `POST /bcc/v1/me/tours-seen`

- **Auth:** Bearer (401 anonymous).
- **Body:** `tour_id` (string, required) — slug shape `^[a-z0-9][a-z0-9_-]{0,63}$`.
- **Response 200:** `{ "seen": string[] }` — the updated set (idempotent add; capped at 100 stored ids as a runaway-client guard).
- **Errors:** `bcc_unauthorized` 401 · `bcc_invalid_request` 400 (malformed `tour_id`).
- **Mapping:** `MeToursSeenEndpoint::markSeen` → `bcc_tours_seen` wp_usermeta (JSON array).

### 4.8 Ranks

#### `GET /bcc/v1/ranks`

The rank catalog and the viewer's current rank.

**Identity is two orthogonal axes (v1.36):** **Rank** (this endpoint's
earned ladder) and **Trust Tier** (`reputation_tier` / `reputation_tier_label`,
see §3.2). A member holds one value on each axis, independently. **Rank mirrors
the feature-access level** (§2.6): `apprentice = New`, `journeyman = Active`,
`master = Veteran` — earned from activity, **not** from reputation tier. Rank is
**fully auto-derived**; there are no conferred-Role fields on this endpoint.
(The conferred Foreman **Role** — locked as a third axis on 2026-06-22 — was
never given a conferral path and is retired for V1; see the note below.)

- **Auth:** Anonymous OR Bearer
- **Response 200:**
  ```json
  {
    "ranks": [
      { "key": "apprentice", "label": "Apprentice", "description": "New on the floor.",   "auto_assigned": true, "order": 1 },
      { "key": "journeyman", "label": "Journeyman", "description": "Earned the basics.",   "auto_assigned": true, "order": 2 },
      { "key": "master",     "label": "Master",     "description": "Master of the trade.", "auto_assigned": true, "order": 3 }
    ],
    "viewer": {
      "current_rank": "journeyman",
      "current_rank_label": "Journeyman",
      "auto_derived_rank": "journeyman",
      "next_rank": "master",
      "next_rank_label": "Master"
    }
  }
  ```
- **Cache:** `Cache-Control: public, max-age=300`
- **Mapping:** Static rank catalog from `RankCatalog::all()` (the three earned
  rungs only). `viewer.*` from `RankService::getViewerBlock()`. `current_rank` /
  `auto_derived_rank` are the **level-derived** earned rank and are always equal
  in V1 (no demotion path). `next_rank` / `next_rank_label` are `null` at
  `master` (top of the ladder). The viewer block carries no conferred-Role
  fields — Rank is fully level-derived.

#### Rank is fully auto-derived — conferred Foreman **Role** retired (v1.36)

V1 **Rank** is **fully auto-derived** from feature-access level by
[`RankProgressionListener`](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/RankProgressionListener.php),
which fires `bcc_rank_awarded` on real promotions. There is no admin-conferral
path: the contract once documented `POST /admin/ranks/award` and
`DELETE /admin/ranks/:rank/:user_id`, but those endpoints were never registered
in `register_rest_route` and had no frontend caller (parity guard correctly
flagged them as documenting endpoints that don't exist).

The **Foreman Role** was locked as a third identity axis on 2026-06-22 (v1.28)
with read-side placeholders (`foreman_insignia`, `is_admin_conferred`), but a
conferral path was never built — the fields were always `false`. In v1.36 the
scaffolding is **retired**, not deferred: the read artifacts and the dropped
`bcc_user_ranks` table are gone, and the viewer block carries no conferred-Role
fields. If a role/moderator-authority concept is wanted later, it is designed
fresh against the contract at that point — not resurrected from this spec.

### 4.9 Directory (§G1 / §G2)

The §G1/§G2 browse + search surfaces. Two endpoints, one shape contract: every result item is a Card or a SearchSuggestion — both pre-shaped per §A2/§L5 so the frontend renders without derivation.

#### `GET /bcc/v1/cards`

Paginated list of Cards filtered + sorted server-side. Backs `/directory`.

- **Auth:** Anonymous OR Bearer (per-viewer `permissions` + `social_proof` vary)
- **Query:**
  - `kind` ∈ {`validator`, `project`, `creator`} — optional; omitted = all kinds (member excluded — members aren't browsed here)
  - `tier` ∈ {`legendary`, `rare`, `uncommon`, `common`} — optional; canonical card-tier values per §C1. Risky tier is intentionally not selectable (entity hidden from card UI per §C1).
  - `sort` ∈ {`trust`, `newest`, `endorsements`, `followers`, `self_stake`} — optional; default `trust`. `self_stake` (bonded self-stake, DESC) is **validator-only** — see the validator-axis note below.
  - `q` (search string) — optional; passed verbatim to the underlying `PageDiscoveryService`
  - `good_standing_only` (`1`|`true`|`on`|`yes` → true; anything else → false) — optional; default false. When true, restricts results to operators in good standing per §E1 (`reputation_tier ∈ {neutral, trusted, elite}`). Composes with `tier` via AND server-side, so `tier=common&good_standing_only=1` is a vacuously empty intersection rather than an error.
  - `chain` (chain slug, e.g. `cosmos`) — optional; **validator-only**. Unknown slugs → `bcc_invalid_request` 400 (rejected at the boundary so a typo never silently returns empty).
  - `status` ∈ {`active`, `jailed`, `inactive`} — optional; **validator-only** on-chain status. `unknown` is intentionally not selectable.
  - `min_self_stake` (number ≥ 0) — optional; **validator-only**. Lower bound on bonded self-stake; validators below the floor (and those with no stake reading) are excluded. Negative → `bcc_invalid_request` 400.
  - `page` (1..20) — optional; default 1. The hard ceiling protects against unbounded `OFFSET` filesort
  - `per_page` (1..50) — optional; default 24
  - **Validator-axis note:** `chain`, `status`, `min_self_stake`, and `sort=self_stake` are served by the read-model query path's validator JOIN (through the `_bcc_onchain_validator_id` post-meta → `bcc_onchain_validators`). They are no-ops/empty on non-validator kinds and are **not** implemented by the legacy posts-table fallback (active only before the read model is populated).
- **Response 200:**
  ```json
  {
    "items": [ /* Card view-models per §3.2 */ ],
    "pagination": {
      "page": 1,
      "per_page": 24,
      "total_pages": 8,
      "has_more": true
    }
  }
  ```
- **Errors:** `bcc_invalid_request` (bad `kind`, `tier`, `sort`, `chain`, `status`, negative `min_self_stake`, or `page > 20`)
- **Cache:** `Cache-Control: private, max-age=15`. Underlying `PageDiscoveryService` query is server-cached for 30s with a stampede lock; the short client TTL is courtesy for back-button nav.
- **Mapping:**
  - Filter SQL ← `PageDiscoveryService::query()`. (`/bcc/v1/discover` was retired 2026-05-15 along with the legacy bcc-page-slider Gutenberg block it served; `PageDiscoveryService` is now used solely by this endpoint.)
  - Server translates canonical kind → legacy `_bcc_page_type` (validator→validator, project→builder, creator→nft) via `PageTypeMap`
  - Server translates canonical card-tier → reputation tier (legendary→elite, rare→trusted, uncommon→neutral, common→caution)
  - The `good_standing_only` `IN`-clause sources its tier list from `UserViewService::GOOD_STANDING_TIERS` — the same constant `isInGoodStanding()` (and therefore the per-row `is_in_good_standing` stamp + the `/auth/*` response `in_good_standing` flag) reads from. The filter chip and the per-row stamp can never disagree.
  - Each row hydrated through `CardViewService::getCard()` so the per-item shape is identical to `GET /cards/:type/:id`
  - `status` / `min_self_stake` / `sort=self_stake` read `bcc_onchain_validators.{status,self_stake}` via the validator JOIN. `sort=self_stake` orders DESC; MySQL sorts NULL last, so validators with no stake reading fall to the bottom.

#### `GET /bcc/v1/cards/search`

Top-N search suggestions for the §G1 nav-bar autocomplete. Smaller per-item shape than full Card — autocomplete needs name + tier badge + click-through, not stats / permissions / social_proof.

- **Auth:** Anonymous OR Bearer
- **Query:**
  - `q` (search string) — required; minimum 2 chars (server returns empty list for shorter; bcc-search has the same gate)
  - `kind` ∈ {`validator`, `project`, `creator`} — optional; restricts to one card kind
- **Response 200:**
  ```json
  {
    "items": [
      {
        "id": 1842,
        "name": "Blacksmith Node",
        "handle": "blacksmith-node",
        "card_kind": "validator",
        "card_tier": "legendary",
        "tier_label": "Legendary",
        "trust_score": 98,
        "is_verified": true,
        "is_claim_verified": true,
        "href": "/v/blacksmith-node"
      }
    ]
  }
  ```
- **`is_verified` vs `is_claim_verified` (verified-wins slice, 2026-06-30):** `is_verified` = the owner's **email** verification (weak; not an authenticity signal). `is_claim_verified` = the page has a **verified on-chain operator/creator claim** (`onchain_claims.status='verified' AND claim_role IN ('operator','creator')`) — i.e. the real entity proved key control. The FE renders the "✓ Verified Operator" badge from `is_claim_verified`, NOT `is_verified`. Claim-verified pages also receive a dominant ranking bonus (`bcc_rank_claim_verified_bonus`), and bcc-search demotes any unverified same-name look-alike strictly below the verified canonical page.
- **Errors:** `bcc_invalid_request` (bad `kind`)
- **Cache:** `Cache-Control: private, max-age=15`. Underlying bcc-search caches results for 60s.
- **Mapping:**
  - Internally calls `GET /bcc/v1/search` (bcc-search plugin) via `rest_do_request` — the FULLTEXT index + trust enrichment + 60s cache + rate limiting all live there
  - Server maps the flat result shape into `SearchSuggestion`: reputation_tier → card_tier per §C1, category_slug → card_kind per `PageTypeMap`, route prefix per kind (`/v/`, `/p/`, `/c/`)
  - Dropped silently: rows with unrecognized `category_slug` (e.g., `dao` — not a card kind in V1) and rows whose tier maps to risky (entity hidden from card UI)
  - Falls back to empty list when bcc-search is degraded (503) — autocomplete must never block the user mid-type with an error toast

#### `GET /bcc/v1/search` (multi-vertical: projects + trending)

Direct bcc-search project search and trending mode — the upstream that `cards/search` wraps. The frontend uses this **directly** on the `/search` results page so the Projects tab gets the full project shape (tier badge text, verified, endorsements, category) — fields the §A2 cards wrapper trims away. The autocomplete dropdown still goes through `cards/search`.

- **Auth:** Anonymous OR Bearer (token silently ignored by handler; sent only so viewer-aware ranking signals stay warm when the user is signed in)
- **Query:**
  - `q` (string) — 2..100 chars (server returns empty for shorter; `QueryQualityGate` rejects pure-stopword / low-entropy queries to empty too). **Over 100 chars → `rest_invalid_param` 400** at the REST validation layer (v1.47) — previously undefined behaviour, now rejected before any handler work.
  - `type` (string, optional) — reputation category slug (e.g. `validator`, `builder`, `creator`). Falls through to category routing in `SearchController`.
  - `trending` (string, optional) — when `=1`, ignores `q` / `type` and returns top-scored projects regardless of query.
- **Response 200:**
  ```json
  {
    "results": [
      {
        "page_id": 1842,
        "page_name": "Blacksmith Node",
        "page_url": "/v/blacksmith-node",
        "avatar_url": "https://…",
        "trust_score": 98,
        "tier": "elite",
        "endorsements": 24,
        "verified": true,
        "is_claim_verified": true,
        "followers": 312,
        "category": "Validator",
        "category_slug": "validator"
      }
    ],
    "categories": [
      { "slug": "validator", "name": "Validator" }
    ]
  }
  ```
- **`categories` scope (v1.46):** the full category list ships only on result-bearing responses. Empty-result short-circuits (query under 2 / over 100 chars, junk-gate rejection, unknown `type`) return `categories: []` — autocomplete fires these per keystroke and nothing reads categories off an empty response.
- **Errors (legacy WP shape — not §L5 envelope):**
  - `rate_limit_exceeded` (HTTP 429) — `10 req / 5s` per subnet
  - `dependency_unavailable` (HTTP 503) — PeepSo plugin not loaded
  - `categories_unavailable` (HTTP 503) — reputation category fetch failed
  - `rebuild_in_progress` (HTTP 503) — bcc-search FULLTEXT index rebuilding; `Retry-After: 5`. In trending mode the same code surfaces with a "Trending is warming up" message.
  - `score_enrichment_failed` (HTTP 503) — trust-score enrichment pipeline down
  - `temporarily_overloaded` (HTTP 503) — internal rate-limit ceiling reached
- **Cache:** server-side `60s` (per-query results) + LKG mirror for 503 fallback. Trending mode: `300s` (5 min) + LKG.
- **Envelope note:** bcc-search predates §L5 — this endpoint returns raw `{ results, categories }` (or `{ results, meta }` for trending mode where `categories` is absent because they don't apply). The bcc-frontend client routes these through `bccSearchFetchAsClient` (not the envelope-strict `bccFetch`) and maps legacy WP errors (`{ code, message, data: { status } }`) into `BccApiError` so the UI's `err.code` branching contract per Phase γ stays uniform.

#### `GET /bcc/v1/search/users`

Users vertical — separate cache + rate-limit bucket from project search so the two verticals don't share quota.

- **Auth:** Anonymous OR Bearer (token silently ignored by handler)
- **Query:**
  - `q` (string) — 2..100 chars (`QueryQualityGate` shared with project search). Over 100 chars → `rest_invalid_param` 400 (v1.47).
  - `limit` (int, optional) — default 20, capped at 50
- **Response 200:**
  ```json
  {
    "results": [
      {
        "id": 42,
        "username": "simontx",
        "display_name": "Simon",
        "avatar_url": "https://…",
        "profile_url": "/u/simontx"
      }
    ],
    "meta": { "count": 1, "query": "simon" }
  }
  ```
- **Errors (legacy WP shape):**
  - `rate_limit_exceeded` (HTTP 429)
  - `user_search_unavailable` (HTTP 503; `Retry-After: 5`)
- **Cache:** server-side `45s` per-query.
- **Envelope note:** raw shape (no §L5 envelope) — same client routing as `GET /bcc/v1/search` above.

#### `GET /bcc/v1/search/groups`

Groups vertical — separate cache + rate-limit bucket. Returns PeepSo group rows (open + closed are listed; secret are filtered server-side).

- **Auth:** Anonymous OR Bearer (token silently ignored by handler)
- **Query:**
  - `q` (string) — 2..100 chars. Over 100 chars → `rest_invalid_param` 400 (v1.47).
  - `limit` (int, optional) — default 20, capped at 50
- **Response 200:**
  ```json
  {
    "results": [
      {
        "id": 17,
        "name": "Blacksmiths Local 412",
        "slug": "blacksmiths-local-412",
        "description": "On-chain plumbers and welders.",
        "avatar_url": "https://…",
        "group_url": "/locals/blacksmiths-local-412"
      }
    ],
    "meta": { "count": 1, "query": "black" }
  }
  ```
- **Errors (legacy WP shape):**
  - `rate_limit_exceeded` (HTTP 429)
  - `group_search_unavailable` (HTTP 503; `Retry-After: 5`)
- **Cache:** server-side `45s` per-query.
- **Envelope note:** raw shape (no §L5 envelope) — same client routing as `GET /bcc/v1/search` above.

### 4.10 Notifications (§I1)

Three endpoints: list, unread-count, mark-read. Backs the SiteHeader bell.

PeepSo's `peepso_notifications` table is the storage layer (§I1 "extend, don't replace"); BCC writes through `PeepSoNotificationWriter` (bcc-core) and reads through `NotificationRepository` scoped to `not_module_id = BCC_NOTIFICATION_MODULE_ID` (= 9000). Reads bypass PeepSo's own `get_by_user` query because that path post_type-filters against `peepso-post`/`peepso-comment`/... and would silently drop BCC rows whose external_id points at non-allowlisted post types.

**Notification shape (returned by the list endpoint):**

```json
{
  "id": 4827,
  "type": "bcc_reaction",
  "message": "@simontx agreed with your post.",
  "created_at": "2026-04-29T18:42:13Z",
  "read": false,
  "actor": {
    "id": 42,
    "handle": "simontx",
    "display_name": "Simon",
    "avatar_url": "https://…"
  },
  "link": "/?focus=15823"
}
```

- `type` ∈ {`bcc_reaction`, `bcc_review`, `bcc_card_watched`, `bcc_rank_up`, `bcc_welcome`, `bcc_mention`, `bcc_local_post`, `bcc_comment_received`}. V1 catalogue per §I2; follow-posts deferred. *(v1.50: `bcc_endorse` retired — its subscriber never fired after the Slice-E endorse-write cutover; entity-page vouch casts dispatch `bcc_attestation_vouch_received` instead. Historical `bcc_endorse` rows are rejected by read-side validation, not rendered.)* **Legacy alias:** `bcc_card_watched` is emitted in parallel with `bcc_card_watched` during the §1.1.1 deprecation window; clients SHOULD branch on the new name and accept either. Removed in release N+1.
- `message` is server-rendered per §A2 — frontend renders verbatim. Plain English, capped at 200 chars (PeepSo's column width).
- `actor.handle` may be empty when the originating user has been deleted; the frontend renders the message verbatim regardless.
- `link` is a server-built relative path. Per type:
  - `bcc_reaction` → `/?focus=<act_id>` (jump back to the post)
  - `bcc_review` → `/v/<page-handle>` etc. (the reviewed page, route prefix per kind)
  - `bcc_card_watched` → `/u/<actor-handle>` (the watcher's profile) — legacy `bcc_card_watched` resolves identically during deprecation
  - `bcc_rank_up` → `/u/<recipient-handle>` (your own profile — progression strip lives there)
  - `bcc_welcome` → `/` (the floor — the user is probably already there when they see it)
  - `bcc_mention` → `/?focus=<act_id>` (jump to the floor focused on the post containing the @-tag; for comment mentions `act_id` is the **parent post's** act_id — the FE has no comment-anchor consumer in V1)
  - `bcc_local_post` → `/locals/<slug>` resolved from `external_id` (the Local's group_id). Falls back to `/locals` when the group is no longer a Local (deleted, renamed off-prefix).
  - `bcc_comment_received` → `/?focus=<act_id>` (jump to the floor focused on the parent post that received the comment; mirrors REACTION + MENTION shape — the FE has no comment-anchor consumer in V1). Covers both the "commented on your post" and the v1.45 "replied to your comment" messages — same type, same link shape.
- Self-notifications are emitted only for `bcc_rank_up` + `bcc_welcome` (audit trail beyond the §O1.2 Heavy toast / first-touch retention). Other types skip the dispatch when actor === recipient.

#### `GET /bcc/v1/me/notifications`

Cursor-paginated newest-first list.

- **Auth:** Bearer (required)
- **Query:** `limit` (1..50, default `BCC_NOTIFICATION_PAGE_SIZE` = 20), `cursor` (last seen `not_id`; omit for first page)
- **Response 200:**
  ```json
  {
    "items": [ /* Notification view-models */ ],
    "pagination": {
      "has_more": true,
      "next_cursor": "4810"
    }
  }
  ```
- **Errors:** `bcc_unauthorized`
- **Cache:** `Cache-Control: private, no-store`
- **Mapping:** `NotificationRepository::findForUser($userId, $limit + 1, $beforeId)` — fetches one extra row to compute `has_more` without a separate `COUNT(*)`. Rows with unknown / corrupt `not_type` are filtered server-side rather than surfaced as "Unknown."

#### `GET /bcc/v1/me/notifications/unread-count`

Drives the bell badge.

- **Auth:** Bearer (required)
- **Response 200:**
  ```json
  { "unread_count": 3 }
  ```
- **Errors:** `bcc_unauthorized`
- **Cache:** `Cache-Control: private, no-store`. Frontend polls every 60s + on window focus.
- **Mapping:** `SELECT COUNT(*) FROM peepso_notifications WHERE not_user_id = ? AND not_module_id = 9000 AND not_read = 0`.

#### `POST /bcc/v1/me/notifications/mark-read`

Single + bulk in one route.

- **Auth:** Bearer (required)
- **Body:** `{ "id": 4827 }` to mark a single notification, or `{}` (no body) to mark every unread notification for the viewer.
- **Response 200:**
  ```json
  { "ok": true, "updated": 1 }
  ```
- **Errors:** `bcc_unauthorized`
- **Cache:** `Cache-Control: no-store`
- **Mapping:** `UPDATE peepso_notifications SET not_read = 1 WHERE not_user_id = ? AND not_module_id = 9000 AND not_read = 0` (with optional `AND not_id = ?` when `id` was passed). Idempotent — already-read rows are no-op success.

#### Subscriber catalogue

Notifications are dispatched by `NotificationDispatcher` (sync subscribers, try/catch'd inside each method — failures log at warning level but never break the originating request).

| Event | Recipient | Skipped when |
|---|---|---|
| `bcc_reaction_added` | author of the reacted-to post | actor === author |
| `bcc_review_published` | page owner via `PageOwnerResolver` | author === page owner |
| `bcc_card_watched` (legacy `bcc_card_watched`) | the followee user | viewer === followee (impossible from the watchlist UI, defensive) |
| `bcc_rank_awarded` | the recipient (self-notification) | rank label not in catalog |
| `user_register` (WordPress core) | the new user (self-notification, type `bcc_welcome`) | `bcc_welcomed` user_meta already set (idempotency guard — once welcomed, never re-welcome) |
| `bcc_post_created` | every user @-tagged in the post body (after `MentionPolicy::filterMentionable`) | author === mentionee (self-mention skip); banned / blocked / private mentionees stripped at validation time |
| `bcc_comment_created` | every user @-tagged in the comment body | same skip rules as post mention; `act_id` passed is the **parent post's** act_id so the bell deep-links to the post on the floor |
| `bcc_post_created` (async via `bcc_primary_local_post_fanout`) | every user whose `bcc_primary_local_group_id` user_meta matches the post's `peepso_group_id` | author === recipient (self-skip); same (recipient, group) already notified within the last 5 min (transient gate); post's group is NOT a Local (`post_title` doesn't match `Local %`); recipient cap of 1000 reached |
| `bcc_comment_created` (priority 31; sibling of mention dispatch) | the parent post's author | commenter === post author (self-skip); same (recipient, post) already notified within the last 5 min (transient gate); parent activity row or post no longer resolvable |

##### @-mention dispatch — policy locks (V2 retention slice, 2026-05-11)

Three behaviours are intentional and load-bearing — do not relitigate without explicit re-planning:

1. **Original-write only.** Mentions fire ONLY on initial create. No `bcc_post_edited` / `bcc_comment_edited` action emission exists in bcc-trust — the dispatcher subscribes to `*_created` exclusively, so an edit-as-ping abuse vector cannot fire. Even if such an edit action lands later, it would need to be wired explicitly.
2. **Structural dedup per (post, mentioner, mentionee).** Three `@bob` tokens in one post produce exactly one bell row for Bob. The dedup is at `MentionExtractor::extractUserIds`, which returns unique-by-first-occurrence ids; the dispatcher never sees duplicates.
3. **Bell + push from day one.** `bcc_mention` is in both `BELL_TYPES` and `PUSH_TYPES`. Push aggregation via the existing 5-min `(recipient, eventType)` debounce coalesces rapid-fire mention floods into a single "N new mentions" push body — bell still fires per-post for the in-app row count.

The `MentionPolicy::filterMentionable` gate is applied a SECOND time at dispatch (write-time validation already filtered) as defense in depth: a future write path that bypasses validation still cannot dispatch to banned / blocked / private mentionees.

##### Primary-Local post dispatch — policy locks (V2 retention slice, 2026-05-11)

Three behaviours are intentional and load-bearing — do not relitigate without explicit re-planning:

1. **Primary-only recipient filter.** Only users whose `wp_usermeta.bcc_primary_local_group_id` matches the post's `peepso_group_id` are notified. Membership alone does NOT subscribe — the primary-Local pointer IS the "I want updates here" signal. Users who set a Local as primary today receive notifications starting with the next post; legacy posts stay silent (no backfill).
2. **Dual coalescing.** Bell writes are gated by a 5-min per-`(recipient, group)` transient (`bcc_local_post_notified_{userId}_{groupId}`). Push uses the existing `PushDispatcher` 5-min `(recipient, eventType)` debounce + count aggregation. Both windows align so a recipient sees at most one bell row + one push (or push-aggregate) per Local per 5-min window — even if 10 different authors post in the Local during that window.
3. **Always async via `AsyncDispatcher::enqueueAsync`.** A popular Local could fan out to thousands of recipients; sync dispatch would blow the §L1 300ms request budget. The originating `POST /posts/*` returns immediately; the async worker handles the per-recipient bell + push loop. Action Scheduler retries failed jobs per its standard semantics.

Defense in depth:
- `Plugin.php` pre-gates `(group_id > 0)` AND `(PeepSoGroupRepository::findOneById !== null)` BEFORE paying the async-enqueue cost. Non-Local posts never enqueue.
- The dispatcher orchestrator (`dispatchPrimaryLocalPostFor`) re-checks `findOneById` defensively (the Local could be deleted between enqueue and worker pickup).
- The shared `dispatch()` helper honors the per-recipient bell pref; `PushDispatcher::enqueue` self-gates on push pref + master.
- Recipient hard cap of 1000 per fan-out (revisit when a real Local crosses ~500 primary-members; switch to cursor-paginated async chain rather than raising the LIMIT).

The `bcc_post_created` event fires **both** subscribers when a mention sits inside a post in your primary Local — by design. Mention and Local-post are semantically distinct events ("you were called out" vs. "activity in your Local") and each is independently toggleable in prefs.

##### Comment-received dispatch — policy locks (V2 retention slice, 2026-05-13; reply routing v1.45, 2026-07-19)

Three behaviours are intentional and load-bearing — do not relitigate without explicit re-planning:

1. **Recipients (v1.45).** A **top-level comment** notifies the parent post's author ("@x commented on your post."). A **threaded reply** (v1.42 `parent_id`) notifies the replied-to comment's author ("@x replied to your comment.") AND the post author — except when they are the same user, who then receives ONLY the reply notification, never both. Post author resolved fresh at dispatch time by walking `parentActId → PeepSoActivityRepository::getById → act_external_id → wp_posts.post_author`; reply recipient via the reply's `_bcc_parent_comment` meta → `CommentRepository::getCommentMeta` (a parent comment that no longer resolves — deleted/unpublished — degrades to top-level routing). Self-activity is never notified (self-comment, self-reply). Both notifications ride the **same `bcc_comment_received` type** — same bell/push pref toggles, same payload shape and `link` — only the server-rendered `message` differs. At most two recipients per comment; dispatch is sync.
2. **Bell coalesced via 5-min per-(recipient, post) transient** (`bcc_comment_received_notified_{userId}_{postId}`). A hot post with 50 comments in 10 min produces at most 2 bell rows for the author (one per 5-min window). Recipient-scoped, so reply recipient and post author never share a window. Push uses the existing `PushDispatcher` 5-min `(recipient, eventType)` debounce + count aggregation, coalescing rapid bursts into "N new comments on your post." / "N new replies."
3. **Original-write only.** Comment edits do not re-dispatch — no `bcc_comment_edited` action emission exists in bcc-trust, so the edit-as-ping vector is structurally closed by absence (same posture as mention + local-post).

The `bcc_comment_created` event fires **both** subscribers when a commenter @-tags the post author — by design. Mention and comment-received are semantically distinct events ("you were called out" vs. "your post has activity") and each is independently toggleable in prefs.

#### Notification preferences (§I1 + V2 Phase 1)

Two-route surface (`GET` + `PATCH /me/notification-prefs`) covering three delivery channels: bell, email digest, and web push. Bell + email-digest land in V1; the `push` sub-object is V2 Phase 1.

**Shape — both routes return the full state:**

```json
{
  "email_digest": false,
  "bell": {
    "bcc_reaction":         true,
    "bcc_review":           true,
    "bcc_card_watched":     true,
    "bcc_rank_up":          true,
    "bcc_welcome":          true,
    "bcc_mention":          true,
    "bcc_local_post":       true,
    "bcc_comment_received": true
  },
  "push": {
    "enabled": false,
    "events": {
      "review":            true,
      "dispute_outcome":   true,
      "panelist_selected": true,
      "mention":           true,
      "local_post":        true,
      "comment_received":  true
    }
  }
}
```

- `bell.*` keys mirror the bell `type` taxonomy (matches §4.10's notification-shape `type` field). Defaults are all-on per §I1 baseline.
- `email_digest` defaults `false` (opt-in).
- `push.enabled` is the master toggle. Defaults `false` until the user explicitly enables it (a browser permission prompt fires on enable; no silent dispatch).
- `push.events.*` defaults are all-on per V2 Phase 1 §P1.C3 — anti-noise carries the load via debounce + 5-min aggregation in `PushDispatcher`. The push event taxonomy is intentionally narrower than the bell ("bell shows everything; push is 'you really need to know'"). Adding a new push event requires extending `NotificationPrefs::PUSH_TYPES` server-side and the corresponding subscriber wiring.

#### `GET /bcc/v1/me/notification-prefs`

Read every flag for the signed-in viewer. Returns the full shape above (defaults filled in for any unwritten flag).

- **Auth:** Bearer (required)
- **Response 200:** the full shape above
- **Errors:** `bcc_unauthorized` (401)
- **Cache:** `Cache-Control: no-store`
- **Mapping:** `NotificationPrefs::readAll($userId)` reads each flag from `wp_usermeta` under the `bcc_notif_pref_*` key prefix; defaults from `NotificationPrefs::DEFAULTS` fill in unwritten flags.

#### `PATCH /bcc/v1/me/notification-prefs`

Partial update — every key optional. Missing types are left untouched.

- **Auth:** Bearer (required)
- **Body:** any subset of `email_digest`, `bell.*`, `push.enabled`, `push.events.*`. Unknown keys are silently dropped server-side; type coercion via `FILTER_VALIDATE_BOOLEAN`.
- **Response 200:** the full post-write shape (same as GET) so the frontend can re-seed cache without a second round trip.
- **Errors:**
  - `bcc_unauthorized` (401) — no session
  - `bcc_invalid_request` (422) — body did not contain any recognized notification keys
- **Cache:** `Cache-Control: no-store`
- **Cascade — `push.enabled = false`:** When the master toggle is patched OFF, the server **deletes every `bcc_push_subscriptions` row for this user** in the same request. The frontend should ALSO call `PushManager.unsubscribe()` on the browser side; the server-side cascade is the safety net so a stale browser subscription can never resurrect deliveries when the user has explicitly opted out.
- **Mapping:** `NotificationPrefs::writePartial` walks the partial body and writes `wp_usermeta` per key; `PushSubscriptionRepository::deleteAllForUser` runs when `push.enabled = false` is in the partial.

#### Push subscriptions (V2 Phase 1)

Per-device VAPID subscriptions storage. One row per (user, browser endpoint) pair — a single user can have multiple devices registered. Storage table: `wp_bcc_push_subscriptions` (uniqueness via SHA-256 of the endpoint URL — the URL itself is too long for a unique-key prefix on older InnoDB row formats; see [`schema-push-subscriptions.php`](../app/public/wp-content/plugins/bcc-trust/includes/database/schema-push-subscriptions.php)).

VAPID config is read from `wp-config.php` constants — `BCC_PUSH_VAPID_PUBLIC_KEY`, `BCC_PUSH_VAPID_PRIVATE_KEY`, `BCC_PUSH_VAPID_SUBJECT`. When any are missing, every push-subscription route returns `bcc_push_not_configured` (503) so the frontend can surface "push isn't configured yet" UX instead of looking like a generic auth failure.

#### `GET /bcc/v1/me/push-subscriptions/vapid-public-key`

Returns the public key the service worker needs to call `PushManager.subscribe()`. Public keys are non-secret by design but the route is auth-gated to keep it from being a casual scraping target.

- **Auth:** Bearer (required)
- **Response 200:**
  ```json
  { "public_key": "BD…Q" }
  ```
- **Errors:**
  - `bcc_unauthorized` (401)
  - `bcc_push_not_configured` (503) — VAPID constants missing in `wp-config.php`
- **Cache:** `Cache-Control: no-store`

#### `POST /bcc/v1/me/push-subscriptions`

Register a fresh browser subscription. Body matches the standard browser `PushSubscription.toJSON()` shape plus an optional `user_agent` for observability.

- **Auth:** Bearer (required)
- **Body:**
  ```json
  {
    "endpoint": "https://fcm.googleapis.com/fcm/send/…",
    "keys": { "p256dh": "BD…", "auth": "…" },
    "user_agent": "Mozilla/5.0 …"
  }
  ```
- **Response 200:**
  ```json
  { "id": 17, "master_enabled": true }
  ```
  `id` is the subscription row's primary key — the frontend keeps it for the per-device DELETE call. `master_enabled: true` reflects the side effect (see below).
- **Side effect — push master flips ON:** the first successful registration for a user flips `push.enabled` to `true`. Subsequent device registrations are no-ops on the master flag. This is what the service worker registration path relies on: enabling push from the frontend = subscribe + POST + master flips, atomically.
- **Idempotency:** same (user_id, endpoint) replaces the existing row in place (upsert keyed by `endpoint_hash`); `last_used_at` is bumped. Re-registering the same browser does not create a duplicate row.
- **Errors:**
  - `bcc_unauthorized` (401)
  - `bcc_invalid_request` (422) — missing `endpoint`/`keys.p256dh`/`keys.auth`, malformed URL, or endpoint > 500 characters
  - `bcc_push_not_configured` (503) — VAPID constants missing
  - `bcc_internal_error` (500) — DB write failure
- **Cache:** `Cache-Control: no-store`
- **Mapping:** `PushSubscriptionRepository::upsert` → `NotificationPrefs::setPushMaster(true)` (only on first registration when master was previously false).

#### `DELETE /bcc/v1/me/push-subscriptions/:id`

Revoke a single device. Auth: the subscription row's `user_id` must match the caller. Idempotent — deleting an already-gone subscription returns 200 OK (treats "already revoked" the same as "just revoked"). Master `push.enabled` is **not** flipped by this route — that's the prefs PATCH cascade's job.

- **Auth:** Bearer (required)
- **Path:** `id` is the subscription row's primary key from the POST response.
- **Response 200:** `{ "ok": true }`
- **Errors:**
  - `bcc_unauthorized` (401)
  - `bcc_invalid_request` (422) — id missing or non-numeric
  - `bcc_forbidden` (403) — subscription belongs to a different user
- **Cache:** `Cache-Control: no-store`
- **Mapping:** `PushSubscriptionRepository::find` (auth check) → `delete` (no-op if already gone).

#### Push subscriber catalogue

Push deliveries fan out via `PushDispatcher::enqueue` (5-minute debounce + count aggregation) → Action Scheduler `bcc_push_flush` worker → `PushDispatcher::flush` (atomic queue pop, render via `PushPayload`, fan out via `minishlink/web-push`, tombstone 404/410 subscriptions). Sources:

| Push event       | Source hook                              | Wired in                                                                                                  |
|---|---|---|
| `review`         | `bcc_review_published`                   | [`NotificationDispatcher::dispatch`](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/NotificationDispatcher.php) — alongside the bell write |
| `dispute_outcome`| `bcc_disputes_email_reporter_result`     | [`bcc-trust.php`](../app/public/wp-content/plugins/bcc-trust/bcc-trust.php) — additive subscriber alongside the existing email handler |
| `panelist_selected` | `bcc_disputes_notify_panelist`        | [`bcc-trust.php`](../app/public/wp-content/plugins/bcc-trust/bcc-trust.php) — additive subscriber alongside the existing email handler |

**Self-suppression:** push inherits `NotificationDispatcher::dispatch`'s actor-vs-recipient guard for free (review). Disputes pushes always fire to a different recipient than the actor by construction.

**Anti-noise rules (P1.E):** debounce + count aggregation at the queue boundary mean rapid-fire events on the same (recipient, event_type) become exactly one push ("3 new reviews on Blacksmith Node") rather than three buzzes. Tombstoned subscriptions (404/410 from the push service) are deleted on the next flush — no retry storms.

### 4.11 Celebrations (§O1.2 out-of-band path)

Heavy celebrations whose trigger runs through an async §A3 subscriber (today: rank-up; reserved: level-up, tier-upgrade) can't ship inline on the originating request — the listener hasn't run yet. These two endpoints provide the out-of-band delivery path.

Single-slot stash per user. A second celebration that lands before the first is consumed overwrites it — stacking Heavy moments would dilute the moment per §O1.2.

#### `GET /bcc/v1/me/celebrations/pending`

Read the pending celebration without clearing it. The two-step read+consume split keeps render-then-consume safe: if the toast animation crashes mid-flight, the celebration survives to the next mount.

- **Auth:** Bearer (required)
- **Response 200:**
  ```json
  {
    "celebration": {
      "kind": "rank_up",
      "label": "You're now a Journeyman.",
      "icon": "rank-up"
    }
  }
  ```
  Or `{ "celebration": null }` when nothing is stashed.
- **Errors:** `bcc_unauthorized`
- **Cache:** `Cache-Control: private, no-store`
- **Mapping:** Reads `wp_usermeta.bcc_pending_celebration` (JSON-encoded). Frontend polls every 60s + on window focus.

#### `POST /bcc/v1/me/celebrations/consume`

Clear the stash after the toast renders.

- **Auth:** Bearer (required)
- **Response 200:**
  ```json
  { "ok": true }
  ```
- **Errors:** `bcc_unauthorized`
- **Cache:** `Cache-Control: no-store`
- **Mapping:** `delete_user_meta($userId, 'bcc_pending_celebration')`. Idempotent — consuming when nothing is stashed is a no-op success.

#### Producer catalogue

| Event | Stashed kind | Stashed label |
|---|---|---|
| `bcc_rank_awarded` | `rank_up` | `"You're now a {RankLabel}."` |
| (reserved) `bcc_card_tier_upgraded` | `tier_upgrade` | TBD when the tier-upgrade listener lands |

`RankProgressionListener` is the only producer in V1. It seeds quietly on a user's first event so users who are already Journeyman at rollout don't get a phantom celebration on their next activity.

Since Rank mirrors the feature-access **level** 1:1 (Apprentice=New, Journeyman=Active, Master=Veteran), a level crossing **is** a rank-up — there is no separate `level_up` celebration. (The 2026-06-22 identity slice retired the no-op `LevelProgressionListener` + its 0-subscriber `bcc_feature_level_unlocked` event; the frontend `level_up` celebration preset is therefore unreachable and slated for removal.)

### 4.13 Comments (v1.5)

Three endpoints under `/bcc/v1/posts/:feed_id/comments`. Comments are a hybrid PeepSo-proxy: BCC reads `peepso_activities` directly via a join to `wp_posts` + `wp_users`; BCC writes route through PeepSo's `add_comment` so moderation, notification fan-out, and the `peepso_disable_comments` gate apply automatically.

#### `GET /bcc/v1/posts/:feed_id/comments`

Paginated list of visible comments on the parent post.

- **Auth:** optional. Anonymous viewers get the same list on non-gated posts.
- **Holder-Groups gate:** when the parent post is in a PeepSo group (post-meta `peepso_group_id` set), the viewer MUST be a member (`gm_user_status` ∈ `member`, `member_owner`, `member_manager`, `member_moderator`, `member_readonly`). Non-members get `bcc_forbidden 403`.
- **Query params:**
  - `limit` (int, optional, default 20, max 50)
  - `sort` (string, optional, v1.40) — `relevant` (**default**) | `top` | `new`. `relevant` = lean stoke×recency-decay heuristic (server-owned; frontend never re-sorts); `top` = most-stoked; `new` = chronological newest-first (the pre-v1.40 behavior — note the default CHANGED from chronological to relevant). Unknown values fall back to `relevant`.
  - `cursor` (string, optional) — base64url-encoded JSON `{k: sort-key, id: act_id}` (v1.40; the key is the active sort's ordering value). Legacy `{t, id}` chronological cursors still decode. Cursors are only valid within the sort that issued them.
- **Response 200 data shape:**
  ```json
  {
    "items":       [ "...Comment view-model per §3.5..." ],
    "next_cursor": "eyJ0IjoiMjAyNi0wNS0wNlQxMzo1OToxMVoiLCJpZCI6MjIxMDE4MX0"
  }
  ```
- **Errors:**
  - `bcc_invalid_request 400` — malformed `feed_id`.
  - `bcc_not_found 404` — feed_id resolves to no activity.
  - `bcc_forbidden 403` — viewer fails the holder-groups gate.
- **Cache:** `private, no-store`. Per-viewer (delete permission depends on identity).

#### `POST /bcc/v1/posts/:feed_id/comments`

Create a comment on the parent post.

- **Auth:** required. Anonymous → `bcc_unauthorized 401`.
- **Request body:**
  ```json
  { "body": "love this — finally a watchlist that respects watches.", "attachment_id": 8123 }
  ```
  - `body` (string, optional since v1.44 — was required, 0–2000 chars after trim). PeepSo applies its own sanitization on top (`htmlspecialchars` + `strip_content`). **Body OR media required:** an empty/omitted `body` is accepted only when `attachment_id` or `gif_url` is present; empty body with no media → `bcc_invalid_request 400`.
  - `attachment_id` (int, optional, v1.41) — a WP attachment the **caller uploaded** via the shared `POST /blog/cover-image` route. The server verifies the caller owns it (`post_author` = viewer) and that it is an `image/*` mime before stamping it. Resolves to a §3.5 `media` block of `kind: "photo"`.
  - `gif_url` (string, optional, v1.41) — a remote Giphy CDN URL. Host-validated server-side (`giphy.com` or a subdomain, parsed host — not a substring match); the GIF stays on Giphy's CDN, nothing is staged locally. Resolves to `kind: "gif"`.
  - `attachment_id` XOR `gif_url` — **one attachment per comment**; if both are sent, the photo wins and `gif_url` is ignored. Attachment validation runs BEFORE the comment write: a rejected attachment fails the whole request and no comment is created. Media-only comments are supported since v1.44: PeepSo's own non-empty-body requirement is satisfied internally by a zero-width-space placeholder (`PeepSoCommentWriter::EMPTY_BODY_PLACEHOLDER`) that `CommentRepository` strips back out on read — the placeholder is a write-path internal and never appears in any API response (`body` reads back as `""`).
  - `parent_id` (string, optional, v1.42) — the `comment_<int>` id of the comment being replied to; omit for a top-level comment. Validated BEFORE the write: it MUST resolve to a live comment on the **same** parent post, else `bcc_invalid_request 400` (invalid id form, a deleted/missing parent, or a parent belonging to a different post all reject and no comment is created). Stored as a sidecar on the reply's own wp_post and echoed back as `parent_id` on the §3.5 response row.
- **Holder-Groups gate:** writes require write-grade membership (`gm_user_status` ∈ `member`, `member_owner`, `member_manager`, `member_moderator`). `member_readonly` can read but not create. Non-members get `bcc_forbidden 403`.
- **Rate limit:** burst seatbelt — `BCC_TRUST_RATE_LIMIT_COMMENT` (20) per `BCC_TRUST_RATE_WINDOW_COMMENT` (300s) per author.
- **Response 200 data shape:**
  ```json
  {
    "comment": { "...Comment view-model per §3.5..." }
  }
  ```
- **Errors:**
  - `bcc_invalid_request 400` — malformed `feed_id`, empty body **with no media** (v1.44 — a body-less comment carrying `attachment_id`/`gif_url` is valid), body over cap; (v1.41) `attachment_id` resolves to no attachment / a non-image attachment, or `gif_url` is not a Giphy-host URL; (v1.42) `parent_id` is malformed, resolves to no live comment, or points at a comment on a different post.
  - `bcc_unauthorized 401` — anonymous.
  - `bcc_forbidden 403` — gate fails OR PeepSo refused (parent has `peepso_disable_comments`, parent owner blocked the commenter); (v1.41) `attachment_id` exists but is not owned by the caller.
  - `bcc_invalid_mention_target 400` — body contains `@peepso_user_<id>(name)` token for a user_id that fails the §3.3.12 `MentionPolicy` privacy filter. Error payload echoes `{user_id: <int>}` but does NOT leak the failure reason (privacy posture).
  - `bcc_too_many_mentions 400` — body contains more than `max` mention tokens. Error payload echoes `{max: 10}`.
  - `bcc_rate_limited 429` — burst seatbelt fired.
  - `bcc_not_found 404` — feed_id resolves to no activity.
  - `bcc_unavailable 503` — PeepSo `add_comment` returned a falsey result for any other reason.
- **Side effects:**
  - PeepSo notifications fire to the parent post author + post followers + every surviving (post-policy) mention via `Tags::after_save_comment`.
  - `bcc_comment_created` event emitted on the §A3 bus (subscribers: NotificationDispatcher, future analytics).
- **Cache:** `no-store`.

#### `DELETE /bcc/v1/posts/:feed_id/comments/:comment_id`

Delete the viewer's own comment. Cross-author + admin moderation deletes are NOT supported in V1 — they continue to flow through PeepSo's existing UI.

- **Auth:** required. Anonymous → `bcc_unauthorized 401`.
- **Authorization:** viewer MUST be the comment's author. Otherwise `bcc_forbidden 403`.
- **Response 200 data shape:**
  ```json
  { "comment_id": "comment_2210184" }
  ```
- **Errors:**
  - `bcc_invalid_request 400` — malformed feed_id or comment_id.
  - `bcc_unauthorized 401` — anonymous.
  - `bcc_forbidden 403` — viewer is not the author.
  - `bcc_not_found 404` — comment doesn't exist or already trashed.
  - `bcc_internal_error 500` — `wp_trash_post` returned false.
- **Side effects:**
  - The comment's `wp_post.post_status` transitions to `trash`. Subsequent `GET` lists exclude it (filtered on `post_status='publish'`).
  - `bcc_comment_deleted` event emitted on the §A3 bus.
- **Cache:** `no-store`.

### 4.14 Photo posts (v1.5)

Multipart counterpart to the existing `POST /bcc/v1/posts` (which handles status / review / blog under JSON content-type). Separate route because multipart vs JSON request shapes don't share validation cleanly.

#### `POST /bcc/v1/posts/photo`

Create a photo post on the viewer's own wall. Single photo per post; optional caption.

- **Auth:** required. Anonymous → `bcc_unauthorized 401`.
- **Content-Type:** `multipart/form-data`.
- **Form fields:**
  - `photo` (file, required) — single image. Allowed mime types: `image/jpeg`, `image/png`, `image/webp`, `image/gif`. Hard size cap: 5 MB. Mime is sniffed via `wp_check_filetype_and_ext` (the browser-supplied Content-Type is not trusted).
  - `caption` (string, optional, 0–500 chars after trim) — accompanying text. Empty/missing → photo-only post.
  - `group_id` (integer, optional, > 0) — §4.7.6 group-scope. When present, the post lands inside that PeepSo group's wall (server stamps `peepso_group_id` post-meta on the new wp_post + fires `peepso_groups_new_post`). Viewer MUST be an active member: server returns `bcc_not_found 404` when the group is missing OR `secret` and the viewer isn't a member (defense-in-depth — never leaks existence), `bcc_permission_denied 403` when the viewer is not a member of an open/closed group (`error.message` is the server-pinned unlock hint, filterable via `bcc_group_post_membership_required`). Omit/0 → posts to viewer's own wall (existing behavior).
  - `visibility` (string, optional, default `members_only`) — enum `members_only` | `public_group` | `public_all`. **Only honored when `group_id` is present** (silently ignored on own-wall posts). Controls the group post's reach: `members_only` — only group members read it (group feed only); `public_group` — members plus non-members reading the group page (read-only teaser per §4.7.6), but NOT in the global `/feed`; `public_all` — group feed AND the global `/feed` (the only way a group post syndicates to the global feed; see §4.3). Stored server-side as `_bcc_post_visibility` post-meta. No response-shape change. **`public_all` is authorization-gated (not everyone may choose it):** in an `open` group any active posting member may; in a `closed`/`secret` group only the owner / group-admin roles (owner, manager, moderator) may — unless the owner opted ordinary members in via `POST /me/groups/:id/post-policy` (§4.7.3). A `member_readonly`/non-member/unauthorized request for `public_all` is **rejected** with `bcc_permission_denied 403` (backend-authoritative; a direct API call cannot bypass it), never silently down-clamped.
- **Rate limit:** burst seatbelt — `BCC_TRUST_RATE_LIMIT_STATUS_POST` (5) per `BCC_TRUST_RATE_WINDOW_STATUS_POST` (120s) per author. Same as status / blog.
- **Storage:** PeepSo owns the photo plumbing under the hood — wp_post (peepso-post CPT), peepso_activities row stamped with `act_module_id = 4` (PeepSoSharePhotos::MODULE_ID), peepso_photos row + thumbnail variants + Imagick metadata strip + JPEG compression. BCC's `PeepSoPhotoWriter` drives this via PeepSo's documented filter+hook surface; no parallel image pipeline.
- **Response 200 data shape:**
  ```json
  {
    "ok":       true,
    "feed_id":  "feed_2210184",
    "post_id":  4012,
    "act_id":   2210184,
    "photo_id": 312
  }
  ```
- **Errors:**
  - `bcc_unauthorized 401` — anonymous.
  - `bcc_invalid_request 400` — missing `photo` field, multi-photo upload (V1 single-photo only), upload error, oversized file, unsupported mime.
  - `bcc_invalid_mention_target 400` — caption contains `@peepso_user_<id>(name)` token for a user_id that fails the §3.3.12 `MentionPolicy`. Error payload echoes `{user_id: <int>}`, no reason.
  - `bcc_too_many_mentions 400` — caption contains more than `max` mention tokens. Error payload echoes `{max: 10}`.
  - `bcc_forbidden 403` — PeepSo's `PERM_POST` permission check refused (rare; pseudo-banned accounts).
  - `bcc_permission_denied 403` — `visibility=public_all` requested by a member not authorized to syndicate this group publicly (see the `visibility` rule above; filterable via `bcc_group_public_all_denied`).
  - `bcc_rate_limited 429` — burst seatbelt fired.
  - `bcc_unavailable 503` — PeepSo deactivated, tmp dir un-creatable, persist failure.
- **Side effects:**
  - `bcc_post_created` event emitted on the §A3 bus (subscribers: rank progression, future analytics) — uniform with status / blog paths.
  - PeepSo's notification fan-out (followers, plus every surviving mention via `Tags::after_save_post`).
- **Cache:** `no-store`.

**V1 deferred:**
- Multi-photo posts (would require extending the form to accept `photo[]` and the writer's `$_POST['files']` array to carry multiple hashes).
- S3-stored photo URLs (Phase 2; v1 falls back to local-storage URL convention, which 404s on S3-only deployments).
- Photo edit / replace.

**Alt text:** the §3.3.9 `alt` field is now author-supplied, not a deferred-debt null. Composer-time collection rides on this same multipart `POST /posts/photo` (no extra round-trip): the response includes `photo_id`, and the frontend chains a §4.18 `PATCH /photos/:photo_id/alt` after the upload returns. Direct upload-time inclusion (an `alt` form field on this multipart) is intentionally not part of this endpoint — keeping creation atomic on PeepSo's side and alt as a follow-up write means alt edits after the fact use the same code path as initial submission.

### 4.15 GIF posts (v1.5)

JSON counterpart to `POST /posts/photo`. Wraps PeepSo's existing giphy plugin — BCC owns the picker UI + activity surface; PeepSo owns the API key, content-rating policy, and `peepso_giphy` post_meta storage (single-graph rule, mirrors the photo + comment + reaction integrations).

#### `POST /bcc/v1/posts/gif`

Create a GIF post on the viewer's own wall. Single GIF per post; optional caption.

- **Auth:** required. Anonymous → `bcc_unauthorized 401`.
- **Content-Type:** `application/json`.
- **JSON body:**
  - `url` (string, required) — Giphy CDN URL. Server-side validation requires the URL contain the substring `giphy.com` (matches PeepSo's own check at `peepso/classes/giphy.php`).
  - `caption` (string, optional, 0–500 chars after trim) — accompanying text.
  - `group_id` (integer, optional, > 0) — §4.7.6 group-scope. Same gate matrix as `POST /posts/photo` (404 missing-or-secret, 403 non-member, server-pinned unlock hint in `error.message`). Omit/0 → viewer's own wall.
  - `visibility` (string, optional, default `members_only`) — enum `members_only` | `public_group` | `public_all`. Same semantics as `POST /posts/photo` (only honored when `group_id` is present; controls group-feed / public-teaser / global-feed reach; stored as `_bcc_post_visibility` post-meta). No response-shape change.
- **Rate limit:** burst seatbelt — `BCC_TRUST_RATE_LIMIT_STATUS_POST` (5) per `BCC_TRUST_RATE_WINDOW_STATUS_POST` (120s) per author. Same as status / photo.
- **Storage:** PeepSo handles the post_meta write under the hood. BCC's `PeepSoGifWriter` drives PeepSo's `PeepSoGiphy::after_add_post` hook by setting `$_POST['type'] = 'giphy'` + `$_POST['giphy'] = <url>` before calling `PeepSoActivity::add_post`. The activity row gets `act_module_id = 1` (status); the `peepso_giphy` post_meta on the wp_post is what discriminates this as a GIF post at hydration time (see §3.3.11).
- **Response 200 data shape:**
  ```json
  {
    "ok":      true,
    "feed_id": "feed_2210184",
    "post_id": 4012,
    "act_id":  2210184
  }
  ```
- **Errors:**
  - `bcc_unauthorized 401` — anonymous.
  - `bcc_invalid_request 400` — empty URL, URL doesn't contain `giphy.com`, caption over 500 chars.
  - `bcc_invalid_mention_target 400` — caption contains `@peepso_user_<id>(name)` token for a user_id that fails the §3.3.12 `MentionPolicy`. Error payload echoes `{user_id: <int>}`, no reason.
  - `bcc_too_many_mentions 400` — caption contains more than `max` mention tokens. Error payload echoes `{max: 10}`.
  - `bcc_forbidden 403` — PeepSo's `PERM_POST` permission check refused.
  - `bcc_rate_limited 429` — burst seatbelt fired.
  - `bcc_unavailable 503` — PeepSo deactivated, persist failure.
- **Side effects:**
  - `bcc_post_created` event emitted on the §A3 bus (uniform with status / photo paths).
  - PeepSo's notification fan-out via the standard `peepso_after_add_post` action — including every surviving (post-policy) mention via `Tags::after_save_post`.
- **Cache:** `no-store`.

**V1 deferred:** GIF in comments, multi-GIF posts, GIF stickers in chat, custom emoji / sticker providers (Tenor etc).

### 4.16 Integrations config (v1.5)

Surfaces PeepSo admin-configured integration toggles + keys to the BCC frontend. Architecturally, the `/integrations/*` namespace is the seam where "PeepSo owns operational state" meets "BCC owns presentation" — admin configures a feature once in PeepSo, all surfaces (PeepSo-native + BCC-frontend) honor the same setting.

#### `GET /bcc/v1/integrations/giphy`

Returns the Giphy integration config the composer's GIF picker needs.

- **Auth:** required. Anonymous → `bcc_unauthorized 401`.
- **Response 200 data shape:**
  ```json
  {
    "enabled":       true,
    "api_key":       "abc123…",
    "rating":        "pg-13",
    "display_limit": 25
  }
  ```
- **Field rules:**
  - `enabled` — `true` only when BOTH PeepSo's `giphy_posts_enable` toggle is on AND `giphy_api_key` is non-empty. When `false`, the BCC frontend MUST hide the GIF button in the composer entirely. `api_key` is `""` on disabled responses.
  - `api_key` — Giphy public/browser key. Designed for client-side use, rate-limited per-IP. Authed-only access limits scrape surface but is not strictly secret.
  - `rating` — Giphy content rating: `g`, `pg`, `pg-13`, or `r`. Default `pg-13`. Picker passes this verbatim to Giphy's API.
  - `display_limit` — max GIFs per page in the picker grid. Default 25; bounds the picker visual weight per Phase 1c UX call.
- **Cache:** `private, max-age=300`. Config doesn't change mid-session; 5-minute window lets the frontend skip refetching across navigations.
- **Errors:**
  - `bcc_unauthorized 401` — anonymous.
- **Behavior when PeepSo isn't installed:** returns the disabled config (`enabled: false`, empty `api_key`, default rating + display_limit). The frontend never errors; the GIF button just doesn't render.

### 4.12 Self-edit (V2 Phase 2 + 2.5)

All self-edit endpoints under `/bcc/v1/me/*` operate on `get_current_user_id()` only — there is no admin-override surface in V1/V2. Anonymous requests return `bcc_unauthorized` with a 401. Every successful response sets `Cache-Control: no-store`.

#### `GET /bcc/v1/me/profile/fields` (V2 Phase 2.5)

Schema + values + per-field visibility for the admin-configured PeepSo profile-fields catalogue.

- **Auth:** Bearer (required, self-only)
- **Response 200 data shape:**
  ```json
  {
    "fields": [
      {
        "key": "first_name",
        "label": "First Name",
        "help_text": null,
        "type": "text" | "textarea" | "date" | "url" | "email" | "select_single" | "select_multi" | "select_bool" | "country" | "location",
        "value": "<scalar string>" | ["<for select_multi>"],
        "visibility": "public" | "members" | "private",
        "visibility_locked": false,
        "editable": true,
        "required": false,
        "max_length": 250,
        "options": [{"value": "us", "label": "United States"}],
        "order": 100
      }
    ],
    "stats": { "filled": 4, "total": 7, "completeness": 57 }
  }
  ```
- **Errors:** `bcc_unauthorized` (401), `bcc_peepso_unavailable` (503).

#### `PATCH /bcc/v1/me/profile/fields/{key}` (V2 Phase 2.5)

Update one field's value. Body `{ "value": <typed> }`. Sanitization is type-aware (text → `sanitize_text_field`, textarea → `sanitize_textarea_field`, url → `esc_url_raw`, date → strict `^\d{4}(-\d{2}(-\d{2})?)?$`, email → `sanitize_email`, select_multi → array of sanitized strings). Returns the updated field item (single-item shape, identical to one entry in the GET list).

- **Errors:** `bcc_invalid_request` (422 — validation error; message is the first PeepSo validation message when present), `bcc_not_found` (404 — unknown key), `bcc_forbidden` (403 — field is read-only via PeepSo's `user_disable_edit` / `user_admin_editable_only`).

#### `PATCH /bcc/v1/me/profile/fields/{key}/visibility` (V2 Phase 2.5)

Update one field's per-field privacy. Body `{ "visibility": "public" | "members" | "private" }`. Stored as integer `peepso_user_field_{key}_acc` user_meta (10 / 20 / 40, matching `PeepSo::ACCESS_*`). Rejected with `bcc_forbidden` when PeepSo's `user_disable_acc` flag is set on the field. Returns the updated field item.

#### `GET /bcc/v1/me/profile-prefs` and `PATCH` (V2 Phase 2.5)

Profile-wide visibility + post-on-my-wall default + birthday-year toggle.

- **Response 200 data shape:**
  ```json
  {
    "profile_visibility": "public" | "members" | "private",
    "post_visibility":    "members" | "private",
    "hide_birthday_year": false
  }
  ```
- **Storage:**
  - `profile_visibility` → `peepso_users.usr_profile_acc` column (PeepSo's user-search and feed gate join against this — writing through this endpoint keeps gating coherent).
  - `post_visibility` → `peepso_profile_post_acc` user_meta. PUBLIC is intentionally absent (matches PeepSo's `access-profile-post` UI which strips it).
  - `hide_birthday_year` → `peepso_hide_birthday_year` user_meta, "1"/"0".
- **PATCH** is partial — missing keys are untouched.

#### `PATCH /bcc/v1/me/account/email` (V2 Phase 2.5)

Change the user's WordPress email. Body `{ "current_password": "...", "email": "..." }`. Server re-verifies the current password via `wp_check_password` on every call — there is no session-elevation flag.

- **Response 200:** `{ "email": "<new>" }`
- **Errors:** `bcc_invalid_request` (422 — bad email or wrong current password; message disambiguates), `bcc_conflict` (409 — email already taken by another user).

#### `PATCH /bcc/v1/me/account/password` (V2 Phase 2.5)

Change password. Body `{ "current_password": "...", "password": "..." }`. New password ≥ 10 chars (server-enforced). Server calls `wp_set_password` (which destroys all session tokens) and immediately re-issues the current session's auth cookie so the user is not kicked out mid-flow. It also bumps the per-user JWT token-version (revoking every outstanding bearer token, the bearer analogue of the session-token wipe) and mints a **fresh token** for the current session, returned below — bearer clients must swap to it.

- **Response 200:** `{ "ok": true, "token": "<jwt>", "expires_in": 604800, "token_type": "Bearer" }`

#### `DELETE /bcc/v1/me/account` (V2 Phase 2.5)

Permanently delete the user. Body `{ "current_password": "...", "confirm": "DELETE" }`. Gated by PeepSo's `site_registration_allowdelete` site option — when the admin has disabled self-deletion, returns `bcc_forbidden` (403). On success: `wp_delete_user` runs (PeepSo's hooks fan out to its activity / friends / messages cleanup), then `wp_logout`. The auth cookie is gone before the response returns; clients should redirect to `logout_url` rather than make another authenticated call.

- **Response 200:** `{ "deleted": true, "logout_url": "..." }`

#### `POST /bcc/v1/me/account/recovery-email` (wallet recovery, phase 2)

Lets a **wallet-only** account (random unseen password + undeliverable `@noreply.bcc.local` placeholder) attach a real recovery email. Authenticated by a **fresh wallet signature** instead of the password the user never had: the client first pulls an authed challenge from `GET /auth/nonce` for one of the user's *verified* wallets, signs it, and posts here. **Verify-before-promote** — this call only *stages* the email and mails a 6-digit OTP to it; `user_email` is untouched until the OTP is confirmed (see the verify route).

Body `{ "wallet_address", "chain_slug", "signature", "email", "extra"? }`. The signed message is the server-stored challenge (never caller input). The proving wallet must be the caller's own, **verified** wallet.

- **Auth:** required. **Rate limit:** 5/min/user (throttled *before* signature verification).
- **Response 200:** `{ "ok": true, "email_masked": "a****@example.com", "expires_in": 900 }`
- **Errors:** `bcc_unauthorized` 401; `bcc_invalid_request` 400 (missing fields / unknown chain / wallet not linked or not verified / challenge expired) / 422 (bad or placeholder email); `bcc_signature_invalid` 401; `bcc_conflict` 409 (email already in use); `bcc_rate_limited` 429.
- **Cache:** `Cache-Control: no-store`.
- **Side effects:** stages `{ email, otp_hash }` in the `bcc_recovery_email_{userId}` transient (15-min TTL); mails the OTP via `AuthMailer::sendRecoveryEmailOtp`; writes a `recovery_email_requested` audit row.

#### `POST /bcc/v1/me/account/recovery-email/verify` (wallet recovery, phase 2)

Confirms the OTP from the request above and **promotes** the pending address to `user_email`, marking it verified. After this, the standard `/auth/forgot-password` → `/auth/reset-password` flow can reach the user — the account is no longer wallet-only-unrecoverable.

Body `{ "code": "123456" }`.

- **Auth:** required. **Rate limit:** 10/hour/user. (A 6-digit OTP under this cap + the 15-min TTL is not brute-forceable; a wrong code leaves the pending transient in place for retry.)
- **Response 200:** `{ "ok": true, "email": "<new>" }`
- **Errors:** `bcc_unauthorized` 401; `bcc_invalid_request` 400 (no pending email) / 422 (missing or incorrect/expired code); `bcc_conflict` 409 (email taken in the race window); `bcc_internal_error` 500; `bcc_rate_limited` 429.
- **Cache:** `Cache-Control: no-store`.
- **Side effects:** `wp_update_user(user_email)` + `_bcc_email_verified='1'`; clears the pending transient; `recovery_email_confirmed` audit row; out-of-band notice via `AccountSecurityMailer::recoveryEmailConfirmed` (new address only, when replacing the placeholder) or `::emailChanged` (both addresses, when replacing a real email).

#### Earlier V2 Phase 2 endpoints

Already shipped, summarized for completeness:

| Endpoint | Body | Purpose |
|---|---|---|
| `PATCH /me/profile` | `{ bio }` | Update bio (`wp_users.description`, max 500 chars). |
| `POST /me/profile/avatar` | multipart `avatar` | Upload avatar (≤ 2 MB, JPEG/PNG/WebP). Wraps `PeepSoUser::move_avatar_file`. |
| `DELETE /me/profile/avatar` | — | Revert to default avatar. |
| `POST /me/profile/cover` | multipart `cover` | Upload cover (≤ 5 MB). Wraps `PeepSoUser::move_cover_file`. |
| `DELETE /me/profile/cover` | — | Remove cover. |
| `PATCH /me/profile/cover/position` | `{ x, y }` (0–100 each) | Drag-to-position cover crop. Stores `peepso_cover_position_x/y`. |
| `GET / PATCH /me/messages-prefs` | `{ chat_enabled, chat_friends_only }` | PeepSo-backed messaging gates (read by `peepso-messages/classes/chatmodel.php`). |
| `GET / PATCH /me/notification-prefs` | bell + email-digest + push toggles | §I1 + V2 Phase 1. |

These all return `Cache-Control: no-store` and respect `bcc_unauthorized` (401), `bcc_invalid_request` (422), `bcc_internal_error` (500), and (for image routes) `bcc_upload_failed` (422) / `bcc_peepso_unavailable` (503).

### 4.17 NFT pieces (V2 Phase 6 / §H1)

Promotes the §8 deferred `GET /collections/:id/pieces` placeholder to a real per-piece detail surface. (The per-creator list-form gallery `GET /creators/:slug/gallery` is now **live** — see §4.29.)

#### `GET /bcc/v1/nft-pieces/{chainSlug}/{contractAddress}/{tokenId}`

Returns an `NftPiece` view-model (§3.7) for one specific NFT.

- **Auth:** Anonymous OR Bearer (response shape is identical for both; no viewer-aware fields in V2 Phase 6 — `permissions` is `{}` for everyone).
- **Path:**
  - `chainSlug` — required, ∈ {`ethereum`, `solana`, `cosmos`}. Other chains return `bcc_invalid_chain` (422). New chain support lands by extending `bcc_onchain_chains` and is automatic without a contract bump.
  - `contractAddress` — required. EVM: lowercased `0x`-prefixed 20-byte hex. Solana: base58 mint address. Cosmos: bech32 contract address. The server normalizes case before lookup; the client SHOULD pass the canonical form but case-mismatches resolve correctly.
  - `tokenId` — required, STRING. May contain `/`-unsafe characters for some chains; the client MUST URL-encode (`encodeURIComponent`). The server URL-decodes once.
- **Response 200 data shape:** `NftPiece` view-model (§3.7).
- **Errors:**
  - `bcc_invalid_chain` (422) — unsupported `chainSlug`.
  - `bcc_invalid_request` (422) — malformed `contractAddress` (wrong format for the chain) or empty `tokenId`.
  - `bcc_not_found` (404) — collection unknown to the indexer AND the read-time fetch failed (Cosmos), OR the token ID does not exist on-chain. Distinguished at the application layer by whether the COLLECTION row exists; the wire response is the same `bcc_not_found` shape.
  - `bcc_unsupported_standard` (422) — the contract resolves but its `token_standard` is not in {`ERC-721`, `ERC-1155`, `SPL`, `CW-721`} (e.g., a fungible ERC-20 contract). Frontend should never reach this if it follows the gallery; included for defense-in-depth.
  - `bcc_upstream_unavailable` (503) — Cosmos read-time fetch failed (LCD endpoint timeout / 5xx) AND no cached metadata is available. Retry with backoff.
- **Rate limit:** 60/min/user (anonymous: 60/min/IP). Indexed chains hit local DB; Cosmos read-time path hits the LCD endpoint which is the actual capacity bottleneck.
- **Cache:**
  - **EVM / Solana (indexed):** `Cache-Control: max-age=300, stale-while-revalidate=1800` — 5m fresh, 30m stale per §1.6 NFT-gallery row.
  - **Cosmos (read-time):** `Cache-Control: max-age=60, stale-while-revalidate=600` — shorter window because there is no persistent backing store and we don't want stale-while-revalidate to mask a chain outage for too long.
  - Response includes `X-BCC-Cache: FRESH | STALE | MISS`. `STALE` triggers a server-side background refresh; the client never blocks.
  - React Query: `staleTime: 60_000` for indexed chains, `30_000` for Cosmos.
- **Mapping:**
  - `collection.*` ← `bcc_onchain_collections` row (joined on `chain_id` + `contract_address`). For Cosmos cold-cache, fetched read-time via `CosmosFetcher::fetchContractInfo` and not persisted.
  - `name`, `description`, `image_url`, `attributes[]` ← per-token metadata. EVM/SOL: cached in `bcc_onchain_collection_pieces` (new helper table; populated by indexer on first sight, refreshed via SWR). Cosmos: fetched read-time via `CosmosFetcher::fetchTokenMetadata` and not persisted.
  - `owner` + `owners_count` + `owners[]` ← new `NftHoldingsRepository::findOwnersByTokenId(chain_id, contract, token_id, limit)`. Index path: existing `idx_chain_contract (chain_id, contract_address)` filters to the contract's row set; `token_id` is a non-indexed filter on top of that scan. Bounded by `limit` (server-caps at N=10 for `owners[]` + 1 for the dominant `owner`). For Cosmos: read-time CW-721 `OwnerOf` query via `CosmosFetcher`; not persisted.
  - `owner.user` enrichment ← join over `bcc_onchain_wallets` → `wp_users` → `peepso_users.usr_handle`. Only the dominant `owner` is enriched; `owners[]` items stay wallet-only for privacy.
  - `marketplace_links[]` ← new `bcc_onchain_chains.marketplace_template` column (TEXT; nullable; one URL template per chain with `{contract}` / `{token_id}` placeholders) interpolated by a new `MarketplaceLinkBuilder` Support helper. Schema migration adds the column with the V2 Phase 6 deploy; back-fill populates the column for ethereum (OpenSea), solana (Magic Eden), cosmos (Stargaze) at deploy time. Empty array when the chain row's template is NULL.
  - `meta.indexer_state` + `meta.indexer_state_label` ← forwarded from `HoldingsService` per §3.6.
  - `meta.read_time` is `true` IFF `chainSlug === "cosmos"` for V2 Phase 6 (extends if/when other read-time chains land).
- **Notes:**
  - `bcc_not_found` returns within 100 ms on the indexed-collection / unknown-token path (single index lookup). Cosmos read-time path may take up to 2 s on cold cache.
  - The endpoint is a single read; concurrent requests for the same `(chain, contract, token_id)` are coalesced server-side via the existing onchain SWR helper. Burst traffic from a freshly-promoted gallery does not multiply upstream LCD calls.

### 4.18 Photo alt text (v1.5 a11y)

Author-supplied alt-text writer for `post_kind = "photo"` posts. The body field `alt` (§3.3.9) is populated through this endpoint; the canonical writer for the §3.3.9 `string | null` shape.

#### `PATCH /bcc/v1/photos/{pho_id}/alt`

Set or clear the alt text on one of the viewer's own photos.

- **Auth:** required. Anonymous → `bcc_unauthorized 401`.
- **Path:**
  - `pho_id` (integer, required) — `peepso_photos.pho_id` of the photo to annotate. The `POST /posts/photo` response (§4.14) returns this id as `photo_id`; the composer chains this PATCH after the post submission.
- **JSON body:**
  - `alt` (string, required) — 0–500 chars after server-side sanitisation. Empty string `""` clears any prior alt (deletes the row). Server applies: `wp_strip_all_tags` (HTML stripped, no inline-script smuggling), `trim`, then internal whitespace collapsed to single spaces. The 500-char cap is measured **after** sanitisation so a 1000-char HTML payload that strips to 200 visible chars is accepted.
- **Storage:** BCC-owned `bcc_photo_alts` sidecar (PK = `pho_id`, 1:1 with `peepso_photos`). PeepSo updates can't clobber the alt because the table is BCC-owned. See §6.5.
- **Response 200 data shape:**
  ```json
  {
    "pho_id": 312,
    "alt":    "Phillip standing under the BCC banner holding the v1.5 demo board."
  }
  ```
  When the alt was cleared (`""` body), `alt` is `null` in the response.
- **Errors:**
  - `bcc_unauthorized 401` — anonymous.
  - `bcc_not_found 404` — `pho_id` does not exist in `peepso_photos`. Returned BEFORE the ownership check so a deleted-photo write doesn't leak ownership info.
  - `bcc_forbidden 403` — `pho_id` exists but the viewer is not its owner (`peepso_photos.pho_owner_id !== current_user_id`).
  - `bcc_invalid_request 400` — non-string `alt`, post-sanitisation length exceeds 500 chars, malformed `pho_id`.
  - `bcc_unavailable 503` — DB write failure.
- **Side effects:**
  - Bumps the per-photo alt-cache generation counter (`photo_alt_gen:{pho_id}` in cache group `bcc_photo_alts`). Subsequent feed reads (§4.3) pick up the new value.
  - No `peepso_photos` write; PeepSo's update mechanism is untouched.
  - No bus event in V1 (alt edits don't ladder into reputation, notification, or moderation flows yet — flagged as a moderation follow-up if abuse emerges).
- **Cache:** `no-store`.
- **Authoring lifecycle:**
  - Composer (§D1) chains `POST /posts/photo` → `PATCH /photos/:photo_id/alt` so per-upload alt-text collection rides on the existing photo-post flow.
  - Edit-after-publish: same endpoint; idempotent upsert. Empty body deletes.

**V1.5 deferred:** alt-text moderation queue surfacing (current `FlagEndpoint` photo-report payload doesn't yet show alt; one-line follow-up), AI-generated alt suggestions, alt-text translation per locale.

### 4.19 Direct Messages (v1.5)

BCC's direct-message surface is a thin adapter on top of PeepSo's existing conversation graph (`peepso_message_participants`, `peepso_message_recipients`, the `peepso-message` CPT). Single-graph rule: BCC reads through `PeepSoMessageRepository` (bcc-core) and writes exclusively through `PeepSoMessageWriter` (bcc-core), which delegates to `PeepSoMessagesModel::create_new_conversation` / `add_to_conversation`. PeepSo's existing email-notification pipeline + SSE chat-pulse still fire — BCC owns presentation only.

**Privacy gates** (server-enforced; frontend never decides):
1. **Sender chat enabled** — `peepso_chat_enabled` user_meta on the sender. False → `bcc_forbidden 403`.
2. **Recipient chat enabled** — same flag on the recipient. False → `bcc_forbidden 403`.
3. **Friends-only override** — recipient's `peepso_chat_friends_only` user_meta. True AND `PeepSoFriendsModel::are_friends(sender, recipient) === false` → `bcc_forbidden 403`.
4. **Mutual block shield** — `PeepSoBlockRepository::isMutuallyBlocked` returns true → `bcc_not_found 404` (not 403; never reveals the block to either side).

The same gate set runs on `POST /me/conversations/:id/messages` so that a recipient flipping their chat_friends_only mid-thread blocks subsequent sends.

**Rate limit:** 30 messages per 5 minutes per sender across all conversations (`bcc_rate_limited 429`). Bounded SQL via the `(post_author, post_date_gmt)` index on `wp_posts`.

**Length cap:** 5000 chars after trim. Cap is enforced in three layers (client `maxLength`, server `mb_strlen` check, `wp_posts.post_content` LONGTEXT bound).

#### `GET /bcc/v1/me/conversations`

Paginated list of the viewer's conversations, ordered by `mpart_last_activity DESC`.

- **Auth:** required. Anonymous → `bcc_unauthorized 401`.
- **Query:** `page` (1-indexed, default 1), `per_page` (default 20, max 50).
- **Response 200:**
  ```json
  {
    "items": [
      {
        "id": 4031,
        "is_group": false,
        "participants": [
          { "id": 132, "handle": "phillips", "display_name": "Phillip", "avatar_url": "..." },
          { "id": 25,  "handle": "admin",    "display_name": "admin",   "avatar_url": "..." }
        ],
        "peer": { "id": 25, "handle": "admin", "display_name": "admin", "avatar_url": "..." },
        "last_message": {
          "id": 4032,
          "author": { "id": 25, "handle": "admin", "display_name": "admin", "avatar_url": "..." },
          "preview": "first 200 chars of the last message…",
          "posted_at": "2026-05-08T14:12:00Z"
        },
        "unread_count": 3,
        "last_activity": "2026-05-08T14:12:00Z"
      }
    ],
    "pagination": { "page": 1, "per_page": 20, "total": 7, "total_pages": 1 }
  }
  ```
- `peer` is the OTHER participant for 1-on-1 conversations and `null` for groups (where `is_group === true`); the frontend uses `peer` for the inbox-row title and falls back to a participant list when null.
- **Errors:** `bcc_unauthorized 401`.
- **Cache:** `private, no-store`. DM state mutates per-viewer on every read.

#### `POST /bcc/v1/me/conversations`

Start a new 1-on-1 conversation OR append to an existing 1-on-1 with the same recipient. Idempotent — backend find-or-creates via `PeepSoMessagesModel`.

- **Auth:** required.
- **Body:** `{ "recipient_id": 25, "body": "hey, what's up?" }`
- **Response 200:**
  ```json
  { "conversation_id": 4031, "message_id": 4051, "is_new_conversation": false }
  ```
- **Errors:**
  - `bcc_unauthorized 401`.
  - `bcc_invalid_request 400` — empty body, body > 5000 chars, missing/zero `recipient_id`, `recipient_id === sender`.
  - `bcc_not_found 404` — recipient doesn't exist OR mutual block (info-leak shield).
  - `bcc_forbidden 403` — sender's chat_enabled false, recipient's chat_enabled false, friends-only + not friends.
  - `bcc_rate_limited 429` — 30/5min cap exceeded.
  - `bcc_unavailable 503` — PeepSo write failure.
- **Side effects:** PeepSo's `peepso_messages_new_conversation` action fires; email notification queued; `peepso_should_get_chats` SSE event triggered for every participant (powers PeepSo-native UIs that share the database).
- **Cache:** `no-store`.

#### `GET /bcc/v1/me/conversations/{id}/messages`

Paginated message history. `{id}` is either the conversation root msg id OR any message id within it — server normalises via `get_root_conversation`.

- **Auth:** required + viewer must be a participant. Non-participant → `bcc_not_found 404` (info-leak shield).
- **Query:** `page` (1-indexed, default 1), `per_page` (default 30, max 100). `offset` walks BACKWARD through history: page 1 returns the most-recent N messages, page 2 returns the next-older window. Within each page, items are `posted_at ASC` for chat-style rendering.
- **Response 200:**
  ```json
  {
    "conversation": {
      "id": 4031,
      "is_group": false,
      "participants": [...UserMini[]],
      "peer": {...UserMini}
    },
    "items": [
      {
        "id": 4051,
        "author": { "id": 132, "handle": "phillips", "display_name": "Phillip", "avatar_url": "..." },
        "body": "hey, what's up?",
        "posted_at": "2026-05-08T14:00:00Z",
        "is_inline_notice": false
      }
    ],
    "pagination": { "page": 1, "per_page": 30, "total": null, "has_more": true }
  }
  ```
- `is_inline_notice` is `true` for system rows (`peepso-message-notic` post_type) — "Phillip joined the conversation," "Admin left." Frontend renders these with an austere centered treatment (no avatar, no bubble).
- **Side effect:** marks every unread message in the conversation as viewed for the viewer (`mrec_viewed = 1`). Equivalent to a `POST /me/conversations/{id}/read` riding on the same request — keeps the typical "open thread → mark read" flow to one round-trip.
- **Errors:** `bcc_unauthorized 401`, `bcc_not_found 404`.
- **Cache:** `private, no-store`.

#### `POST /bcc/v1/me/conversations/{id}/messages`

Append a message to an existing conversation.

- **Auth:** required + viewer must be a participant.
- **Body:** `{ "body": "..." }`
- **Response 200:**
  ```json
  { "conversation_id": 4031, "message_id": 4052, "is_new_conversation": false }
  ```
- **Errors:** as for `POST /me/conversations`, plus `bcc_not_found 404` when the conversation is missing or the viewer isn't a participant. The privacy gates (chat_enabled, friends_only, blocks) also re-run against the OTHER participant of a 1-on-1 — so a recipient who flipped their chat_friends_only mid-thread blocks subsequent replies even though the conversation already exists.
- **Cache:** `no-store`.

#### `POST /bcc/v1/me/conversations/{id}/read`

Explicitly mark a conversation viewed without fetching the thread. Used by the inbox "mark read" affordance and as the idempotent receipt for clients that scroll past unread without opening.

- **Auth:** required + viewer must be a participant.
- **Body:** none.
- **Response 200:** `{ "ok": true }`
- **Errors:** `bcc_unauthorized 401`, `bcc_not_found 404`.
- **Cache:** `no-store`.

#### `GET /bcc/v1/me/messages/unread-count`

Slim header-badge endpoint — returns the total count of conversations with unread messages for the viewer (mirrors PeepSo's own header-badge logic, which excludes muted conversations and conversations involving deleted users).

- **Auth:** required.
- **Response 200:** `{ "count": 3 }`
- **Cache:** `private, no-store`. Polled by the frontend's `useUnreadMessageCount` hook with adaptive cadence (5s active / 30s idle, mirroring PeepSo's `peepsomessages.js`).

**V1 deferred** (none of these block ship):
- Group-conversation creation UI (read-only support: existing group convos surface in the inbox + thread renders all participants).
- Mute / unmute conversation (PeepSo data model already supports it via `mpart_muted`).
- Typing indicators (PeepSo uses Mayfly transients; mirrorable later).
- Bulk actions (delete-all, mark-all-read).
- Per-message edit / delete.
- Attachments (photo / file / GIF in DMs).
- In-DM full-text search.
- SSE / WebSocket realtime — V1 polls.

---

### 4.20 Trust Attestations (§J)

The Trust Attestation Layer is foundational product architecture, locked in `docs/trust-attestation-layer.md`. This section encodes the wire-level contracts that follow from that design. **Read the design doc first** — this section assumes the reader knows the three-layer architecture, the three V1 primitives, the bandwidth model on Stand Behind, and the soft-accountability stack. The companion `docs/trust-attestation-risk-assessment.md` documents the threat model + behavioral risk map; Phase 1 implementation must address its §5 Critical items.

> **Status:** locked 2026-05-13. Phase 1 implementation gates on a separate scope-frozen plan. Endpoint shapes below are the V1 contract surface; routes ship as Phase 1 lands.

#### §J.1 Primitives

Three Layer-1 attestation kinds at V1:

| Kind | Semantic | Conviction | Bandwidth |
|---|---|---|---|
| `vouch` | "Competent." | Low/medium | Unlimited (throttle-gated) |
| `stand_behind` | "Reputation-staked." | High | Tier-scaled slot cap (Elite 7 / Trusted 5 / Neutral 3 / Caution+Risky 0) |
| `dispute` | "Formal challenge." | Adversarial | Tier-gated (≥ trusted), evidence-required, stake-required |

Each kind operates on the same target taxonomy:

```
target_kind ∈ { user_profile, validator_card, project_card, creator_card }
```

`endorse` is **not** a separate V1 verb — it collapses into `vouch` with `target_kind` in the `*_card` set.

#### §J.2 `POST /bcc/v1/me/attestations`

Cast a new attestation. Idempotent on `(attestor_user_id, target_kind, target_id, kind)` — a duplicate returns the existing row with `status: "existing"`.

- **Auth:** Bearer required.
- **Body:**
  ```json
  {
    "kind": "vouch" | "stand_behind",
    "target_kind": "user_profile" | "validator_card" | "project_card" | "creator_card",
    "target_id": 12345,
    "context_note": "Optional ≤ 280-char free-text rationale."
  }
  ```
- **Response 201 (created):**
  ```json
  {
    "id": 42,
    "status": "created",
    "kind": "vouch",
    "target_kind": "user_profile",
    "target_id": 12345,
    "weight_at_time": 1.0,
    "context_note": "...",
    "created_at": "2026-05-13T12:34:56Z",
    "decay": {
      "current_weight": 1.0,
      "as_of": "2026-05-13T12:34:56Z"
    },
    "attestor_summary": {
      "stand_behind_slots_used": 2,
      "stand_behind_slots_total": 5,
      "stand_behind_slots_graduated": 0,
      "is_dormant": false,
      "operator_reliability": 0.91,
      "reliability_standing": "highly_reliable"
    }
  }
  ```
  Notes on `attestor_summary`:
  - `stand_behind_slots_total` is the operator's *current effective*
    slot count = tier baseline + graduated slots (capped at +3 above
    tier baseline per §J.1 long-term graph health refinements).
  - `stand_behind_slots_graduated` is the count of additional slots
    earned via consistent reaffirmation + high Operator Reliability.
    Surfaces only to the operator themselves; omitted from other
    viewers' responses.
  - `is_dormant` is true when the operator has not had platform
    activity in ≥ the §J.10-tunable dormancy threshold (60 days
    default). Drives the activity-gated display rule (§J.1) — when
    true, this operator's attestations dim in rosters and deduct
    from aggregate counts on targets.
  - `operator_reliability` numeric value renders ONLY when the
    requesting operator is querying their own state. For
    third-party queries the field is absent — the asymmetric
    public-display rule (§J.3.2) forbids exposing the number.
  - When the operator is querying their own state, the response
    also includes the two-sub-track breakdown:
    `consensus_reliability` (numeric, self-only) and
    `early_read_accuracy` (numeric, self-only) — per the §J.3.2.1
    Early Read sub-track. These never expose to third-party queries.
  - `badges` array contains the operator's earned public badges,
    visible to third-party queries. V1 catalogue:
    `highly_reliable` / `consistent` / `newly_active` (the
    Reliability Standing positive ladder) and `early_read` (the
    independent-discovery public badge). Asymmetric-display rule
    preserved: no negative badges exist in the catalogue at all,
    so this field cannot expose stigma even by inclusion.
- **Response 200 (existing):** same shape with `status: "existing"`.
- **Errors:**
  - `bcc_invalid_request` (400) — bad kind, target_kind, or target_id
  - `bcc_attestation_self` (422) — attestor and target identity match
  - `bcc_attestation_ineligible` (403) — tier/standing gate failed; `error.unlock_hint` carries the plain-English path forward
  - `bcc_attestation_bandwidth_exhausted` (409) — only for `kind=stand_behind` when slots are full; body includes a `slot_holders[]` array so the FE can render the "drop one to add one" picker server-supplied
  - `bcc_rate_limited` (429) — per-user attestation throttle tripped
  - `bcc_attestation_fraud_blocked` (403) — fingerprint dedup or fraud orchestrator denied
- **Cache:** `private, no-store`. Mutation endpoint.
- **Audit:** lands a row in `bcc_trust_activity` per the Destructive Mutation Hardening recipe — `action ∈ {attestation_vouch_created, attestation_stand_behind_created}`.
- **Side effects:**
  - Push + bell notification to the target operator (§I1 taxonomy extends — see §J.7)
  - Layer 2 derived-intelligence read-model invalidation on the target via the existing generation-counter pattern

#### §J.2.1 `POST /bcc/v1/me/attestations/:id/reaffirm`

Soft-renewal endpoint — the operator confirms they still endorse
the attestation. Refreshes the attestation's effective timestamp
(resets the decay curve), preserves the original audit row, and
lands a new audit row for the reaffirm event.

Driven by the soft-renewal nudge described in §J.1 long-term graph
health refinements. The user-facing path is one-tap from the
notification (`stand_behind_renewal_nudge` event type — see §J.7);
this is the underlying endpoint.

- **Auth:** Bearer required; viewer must own the attestation.
- **Body:** empty
- **Response 200:**
  ```json
  {
    "id": 42,
    "reaffirmed_at": "2026-11-13T12:34:56Z",
    "decay_reset_to": 1.0
  }
  ```
- **Errors:** `bcc_not_found` (404), `bcc_forbidden` (403),
  `bcc_attestation_revoked` (409 — cannot reaffirm a revoked
  attestation), `bcc_rate_limited` (429).
- **Audit:** `action=attestation_reaffirmed` (new action type per
  the Destructive Mutation Hardening recipe).
- **Side effects:** decay-curve reset to age=0; Layer 2
  derived-intelligence read-model invalidation on the target.

#### §J.3 `DELETE /bcc/v1/me/attestations/:id`

Revoke an existing attestation.

- **Auth:** Bearer required; viewer must own the attestation.
- **Response 200:**
  ```json
  {
    "id": 42,
    "revoked_at": "2026-05-13T18:01:23Z",
    "attestor_summary": {
      "stand_behind_slots_used": 1,
      "stand_behind_slots_total": 5
    }
  }
  ```
- **Errors:** `bcc_not_found` (404), `bcc_forbidden` (403), `bcc_rate_limited` (429).
- **Idempotency:** re-DELETE on an already-revoked row returns 200 with the existing `revoked_at` (no audit row on the no-op — per Destructive Mutation Hardening invariant 1, "audit on real state transition only").
- **Audit:** `action=attestation_revoked` on real transition.
- **Notification:** push + bell to the (former) target — `attestation_revoked` event type.
- **Reputation Score impact on attestor:** none. Revocation is healthy signal of changing assessment, not punishment.

#### §J.4 `GET /bcc/v1/entities/:target_kind/:target_id/attestations`

Read the attestation roster for an entity.

- **Auth:** anonymous OR Bearer (viewer-aware fields vary).
- **Query:**
  - `kind` ∈ `{vouch, stand_behind, all}` — default `all`
  - `sort` ∈ `{decayed_weight, recency, reliability}` — default `decayed_weight`
  - `include_revoked` (`0|1`) — default `0`
  - `page` (1..20), `per_page` (1..50, default 24)
- **Response 200:**
  ```json
  {
    "items": [
      {
        "id": 42,
        "kind": "stand_behind",
        "attestor": {
          "id": 7,
          "handle": "phillip",
          "display_name": "Phillip",
          "avatar_url": "...",
          "reputation_score": 78,
          "reliability_standing": "highly_reliable",
          "badges": ["highly_reliable", "early_read"]
        },
        "is_pre_consensus_pick": true,
        "attestation_order": 1,
        "context_note": "...",
        "created_at": "...",
        "revoked_at": null
      }
    ],
    "summary": {
      "vouch_count": 14,
      "stand_behind_count": 3
    },
    "pagination": { "page": 1, "per_page": 24, "total_pages": 1, "has_more": false }
  }
  ```
  Note on synthesis invisibility (§J.4.1): per-attestation numeric
  weight fields (`weight_at_time`, `decayed_weight`) are server-side
  only and never appear in third-party roster responses. Sorting
  by decayed weight is supported via the `sort` query parameter
  (sorting is performed server-side; the resulting order surfaces
  the ranking without exposing the weights themselves). The
  `summary.divergence_signal` field has been removed; the
  authoritative divergence surface is the entity view-model's
  `divergence_state` enum (§J.6, five-state classification).
  Self-only views (e.g. an operator querying their own
  attestation surface) MAY include the weights — that's a future
  endpoint, not in V1 scope.
- **Cache:** `private, max-age=30`. Underlying read model is generation-counter invalidated by `POST` / `DELETE` against the same target.

#### §J.5 `GET /bcc/v1/me/reliability`

Returns the signed-in operator's own reliability surface. Mirror, not stigma — V1 is self-only; V2 expansion opens this to public viewers once decay history is dense enough to be meaningful (see §J.10).

- **Auth:** Bearer required.
- **Response 200:**
  ```json
  {
    "operator_reliability": 0.87,
    "consensus_reliability": 0.83,
    "early_read_accuracy": 0.79,
    "reliability_standing": "highly_reliable",
    "since_attestation_count": 28,
    "stand_behind_allocation": {
      "slots_total": 6,
      "slots_used": 2,
      "slots_recyclable_count": 1,
      "next_slot_unlocks_at": null
    },
    "track_record": {
      "total_attestations": 28,
      "outcomes": {
        "targets_disputed_and_upheld": 1,
        "targets_disputed_and_dismissed": 0,
        "targets_received_further_attestations": 19,
        "targets_clean_and_active": 8
      }
    },
    "trends": {
      "reliability_30d_ago": 0.91,
      "reliability_90d_ago": 0.95,
      "direction": "softening" | "steady" | "improving"
    },
    "divergence_state": "untested" | "well_regarded" | "poorly_regarded" | "polarizing" | "disputed",
    "explainer": {
      "state": "untested",
      "headline": "No reads yet.",
      "body": "Server-pinned copy explaining the operator's current divergence state. Render verbatim per §A2. Per the §2.7 status-anxiety mitigation, the body avoids any 'you should do X' nudge — it explains, it doesn't prescribe."
    }
  }
  ```
- **`consensus_reliability` / `early_read_accuracy`** — the two §J.3.2.1 Early Read sub-tracks, both SELF-ONLY (this surface only; never on third-party endpoints). `consensus_reliability` is the weighted-average goodness over the operator's *stand_behind* attestations (how often the operators they back earn subsequent confirming flow); `early_read_accuracy` is the same weighted average restricted to *pre-consensus* stand_behinds (`attestation_order_in_target ≤ 5`, excluding the operator's first-5 first-mover-protected casts), so the early-conviction multiplier is reflected. Both ∈ [0,1], `0.0` when the operator has no qualifying attestations.
- **`stand_behind_allocation.slots_total`** — the operator's *current effective* slot count = tier baseline + graduated slots (§J.1; graduation needs sustained reliability — `highly_reliable` / `consistent` standing — and is capped at +3 above baseline).
- **`divergence_state`** — the operator's own divergence-state classification per §J.8. The same value flows to the public §J.6 `negative_signals.divergence_state` for entity-card targets **and, as of the member-disputes slice (2026-06-30), for `user_profile`/member targets too** — members get the full public 5-state surface, same as entities (people are first-class trust subjects). PR-8a ships this as a read-time `DivergenceStateClassifier` output.
- **`explainer`** — server-pinned copy block explaining the current state in plain language. Per the §J.5 critical-risk-mitigation item #7 ("self-only 'why am I in this state' view"), the operator's self-mirror is the only surface that carries this — never on third-party endpoints. The `headline` + `body` strings are server-rendered per §A2; the FE renders them verbatim.
- **Cache:** `private, max-age=60`.
- **`slots_recyclable_count`:** number of currently-allocated Stand Behind slots whose decayed_weight has crossed the 50% threshold and are eligible to auto-free on the next write. FE renders this as a soft "you have N slots about to recycle" hint.

#### §J.6 Entity view-model extensions

Existing card and profile endpoints (`/bcc/v1/cards/:type/:id`, `/bcc/v1/users/:handle`) carry the following additions:

> **Member cards (member-disputes slice, 2026-06-30):** `member` cards (`card_kind: "member"`, target_kind `user_profile`) now emit the **populated** `negative_signals` block (same shape as entity cards) — previously it was omitted/inert for members. `divergence_state` produces the full 5-state enum for members, and `under_review`/`disputed` now fire when a member's self-page has an active vote-dispute. **Id-duality (server-side):** for `user_profile`, dispute/`under_review`/`unresolved_claims_count` reads key on the self-page id (`selfPageId(user_id) = ID_BASE + user_id`), while attestation-derived inputs key on the raw `user_id`. This is internal; the wire shape is unchanged.

```json
{
  "reputation_score": 78,
  "reliability_standing": "consistent",
  "attestation_summary": {
    "vouch_count": 14,
    "stand_behind_count": 3,
    "vouch_weight_sum": 9.7,
    "stand_behind_weight_sum": 2.4
  },
  "negative_signals": {
    "under_review": false,
    "divergence_state": "polarizing",
    "volatile": false,
    "unresolved_claims_count": 0
  },
  "viewer_attestation": {
    "vouch": { "id": 42, "created_at": "..." } | null,
    "stand_behind": { "id": null, "created_at": null }
  },
  "permissions": {
    "can_vouch":        { "allowed": true,  "unlock_hint": null },
    "can_stand_behind": { "allowed": false, "unlock_hint": "All 5 Stand Behind slots are in use. Drop one to add this." },
    "can_report":       { "allowed": true,  "unlock_hint": null }
  }
}
```

**`trust_score` cosmetic rename to `reputation_score`:** the API emits BOTH `trust_score` (legacy) AND `reputation_score` (new canonical) for one release cycle. Frontend reads `reputation_score`. `trust_score` is removed in the release after Phase 1 ships.

**`reputation_tier` and `card_tier` unchanged** — they remain the categorical-stratification axes per §C1. Reputation Score is the continuous axis they categorize.

**`is_in_good_standing` unchanged** — sourced from `UserViewService::GOOD_STANDING_TIERS` per §G2.

#### §J.7 Notification event taxonomy (§I1 extension)

The §I1 bell + push catalogue extends with five trust-event types:

| Event | Recipient | Skipped when |
|---|---|---|
| `attestation_vouch_received` | target operator | self-attest (structurally skipped); per-(recipient, kind, target) 5-min coalescing window |
| `attestation_stand_behind_received` | target operator | same as above |
| `attestation_revoked` | (former) target operator | self-revoke; structural target-equals-attestor |
| `attestation_reaffirmed` | target operator | self-reaffirm; structural target-equals-attestor |
| `stand_behind_renewal_nudge` | the attestor themselves | self-only; cadence per the soft-renewal nudge (§J.10 tunable, 6 months default); structurally skipped if attestation already revoked |
| `dispute_filed_against_you` | target operator | self (structurally impossible); cooldown per (recipient, filer) 24h |
| `reliability_threshold_crossed` | the operator themselves | direction must be *crossing*, not bouncing across the same boundary inside 24h; cross to a *positive* badge fires push, cross *away* from a positive badge fires bell only (asymmetric-display rule — losing a positive badge isn't a public stigma, but the operator should still know to look in their self-mirror) |
| `divergence_state_warning` | the target operator (page owner for entity targets; the user themselves for user_profile targets) | per-(recipient, target_kind, target_id, new_state) 24-hour coalescing window; only fires on transitions INTO `polarizing` or `disputed` — never on transitions out of them; PR-8b ships only the `disputed` path in V1 since the V1 classifier doesn't produce `polarizing` until Slice E.5 reliability cache lands |

Each event is opt-toggleable on `/me/notification-prefs` per the existing §I1 contract. Defaults: all enabled (six trust events + the legacy taxonomy). `NotificationType` enum extends; `NotificationPrefs::BELL_TYPES` and `PUSH_TYPES` extend; `NotificationViewService::resolveLink` adds:

- `attestation_vouch_received`, `attestation_stand_behind_received` → `/u/{attestor_handle}` (the source of the attestation)
- `attestation_revoked` → `/u/{former_attestor_handle}`
- `attestation_reaffirmed` → `/u/{attestor_handle}` (same target as the original attestation event)
- `stand_behind_renewal_nudge` → `/me/attestations` (the operator's own attestation list, where the one-tap reaffirm / revoke / ignore choice is presented)
- `dispute_filed_against_you` → `/disputes/{dispute_id}`
- `reliability_threshold_crossed` → `/me/reliability`
- `divergence_state_warning` → `/me/reliability` (the self-mirror surface where the §J.5 `explainer` block sits — the operator lands on plain-language framing for their new state, not on a raw card view)

#### §J.8 Negative-signal computation (Layer 2 read model)

The negative signals on entity cards are derived, not user-cast:

| Field | Trigger | Computed at |
|---|---|---|
| `under_review` | active dispute exists on this target (state = `'reviewing'`, per `DisputeStatus::REVIEWING`) | read-time |
| `divergence_state` | classification into one of five derived states per §J.2 polarization-as-intelligence — `untested` / `well_regarded` / `poorly_regarded` / `polarizing` / `disputed`. `polarizing` requires divergence among **high-reliability** attestors (reliability standing ≥ `consistent`); cheap low-reliability dispute-bombing does not trigger it | read-time in V1 (PR-8a `DivergenceStateClassifier`); nightly worker in Slice E.5+ once the threshold-tunables table populates |
| `volatile` | `reputation_score` swung > VOLATILITY_THRESHOLD points in a rolling 90-day window | nightly worker |
| `unresolved_claims_count` | active dispute count (state = `'reviewing'`) + open content-report count | read-time |

`divergence_state` replaces the previous separate `contested`
boolean + `divergence_signal` string. The five-state enum is
mutually exclusive — every entity is classified into exactly one
state. The classification is the load-bearing intelligence surface:
`polarizing` is not a negative state, it's a *signal worth examining*.

Thresholds are intentionally not locked in this contract — they're tunable parameters lifted to a `bcc_attestation_thresholds` config table populated by the nightly worker. The contract guarantees the *shape* of the surfaces; the *numbers* tune on real data once Phase 3 lands negative-badge surfaces in the UI.

#### §J.9 Reports extend to user_profile and card target kinds

`ContentReportService::TARGET_KINDS` extends from `['feed_item']` to `['feed_item', 'user_profile', 'validator_card', 'project_card', 'creator_card']`. The existing report pipeline (validate → throttle → insert → §A3 emit + auto-hide threshold + admin-queue notifier) is reused with no changes to the report row shape — only the target_kind column accepts new values.

Self-report rejection is preserved: reporter_user_id !== target's owner_user_id (resolved server-side from the target via the existing resolver pattern).

#### §J.10 Open questions deferred to Phase 1 plan

These are deliberately not locked in this contract — they're tuning decisions made in the Phase 1 scope-freeze plan against real performance and product data:

1. Stand Behind slot counts (`bcc_attestation_thresholds.stand_behind_slots_by_tier`)
2. Decay curve shape (`bcc_attestation_thresholds.decay_curve_function` + breakpoints)
3. Operator Reliability formula weights
4. Negative-state thresholds — `volatile` flag trigger points (reputation score swing magnitude × window length), and the `polarizing` state's high-reliability-attestor divergence cutoff (per the §J.8 five-state classification). The constitution's §J.10 has the full open-question list; this is the wire-relevant subset.
5. Context-note character cap (current contract: 280; revisit in Phase 1)
6. Revocation cooldown (current contract: none; revisit if flip-flop abuse emerges)
7. Public surfacing of Operator Reliability on others' profiles (V1 self-only; V2 expansion gated on ≥ 6 months of attestation density)

#### §J.11 Migration

- Existing `bcc_endorsements` rows materialize into `bcc_trust_attestations` as `kind=vouch, target_kind=*_card` on the Phase 1 migration. Original timestamps preserved.
- Existing `ReactionTypeRegistry::KIND_VOUCH` and `KIND_STAND_BEHIND` post-reaction rows are frozen at Layer 0. They remain queryable, surface on post UIs as +1-style content reactions, and contribute **zero** to Layer 2 synthesis going forward.
- `KIND_SOLID` and `KIND_FIRE` reactions are unchanged — always Layer 0, never contributed to trust graph.
- `trust_score` field cosmetically renames to `reputation_score` in API responses; both emitted for one release cycle.

---

### 4.21 NFT showcase selections (V2 Phase 1)

The viewer's saved NFT showcase — up to 200 token tuples surfaced on the profile photo strip and edited at `/settings/nft-showcase`. Backed by `bcc_user_nft_selections` (sort by `display_order ASC, added_at ASC`).

All endpoints in this section require Bearer JWT — anonymous → `bcc_unauthorized 401`. Suspended accounts → `bcc_forbidden 403`. Standard envelope per §1.4 / §1.5.

**Known contract debt:** the controller (`NftSelectionController.php`) currently emits some failure paths with non-canonical envelope shapes (status-only, no stable `code`). The frontend's `humanizeError` helper compensates with `err.status` shims that will be retired once the controller migrates to canonical envelopes + the codes documented per endpoint below. Tracked; not a contract break to ship the frontend ahead of the migration.

#### `GET /bcc/v1/nft-selections/picker`

Live holdings across the viewer's linked wallets, annotated with which are already selected. The single endpoint that surfaces `meta.indexer_state` + `meta.indexer_state_label` per §3.6.

- **Auth:** required.
- **Query:**
  - `force` (optional, boolean) — when truthy (`1` / `true`), bypasses the HoldingsService transient cache and re-fetches from chain. Costs one RPC round-trip per linked wallet. Default reads the 24h transient.
- **Response 200 data shape:**
  ```json
  {
    "items": [{
      "chain_id": 1, "contract_address": "0x…", "token_id": "42",
      "wallet_link_id": 15, "is_selected": true,
      "name": "Genesis #042", "collection_name": null,
      "image_url": "https://…", "metadata_uri": "ipfs://…",
      "token_standard": "ERC-721",
      "collection_verified": true
    }],
    "truncated": false,
    "wallets_checked": 2, "wallets_truncated": 0,
    "selected_keys": { "1|0x…|42": true },
    "refreshed_at": { "15": "2026-05-15 14:23:47" },
    "meta": {
      "indexer_state":       { "ethereum": "syncing" },
      "indexer_state_label": { "ethereum": "Syncing holdings…" }
    }
  }
  ```
  - `refreshed_at` is keyed by `wallet_link_id` (not chain). Value is MySQL UTC datetime (`YYYY-MM-DD HH:MM:SS`); frontend normalizes to ISO at the boundary.
  - `meta.indexer_state` ∈ {`healthy`, `syncing`, `degraded`} per §3.6. `indexer_state_label` is `""` for `healthy` — the contract's "no chip" signal.
  - `collection_verified` (v1.31) — whether the operator has verified the item's collection (Verify Collections). **Display-only**: the frontend dims unverified items (holder-community not yet activated) instead of hiding them; nothing gates on this flag. `false` when the contract has no collection row at all. Cosmos read-time items now include unverified-but-known collections (previously silently absent from the gallery).
- **Errors:** `bcc_unauthorized 401`, `bcc_rate_limited 429` (10/60/user — shared bucket with `force=1` refreshes).
- **Rate limit:** 10/60/user.
- **Cache:** `no-store` (transient is server-side; the response is per-request live). React Query `staleTime: 60_000`.

#### `GET /bcc/v1/nft-selections`

The viewer's currently saved selections, joined with chain metadata so the UI can render badges + explorer links without a second fetch.

- **Auth:** required.
- **Response 200 data shape:**
  ```json
  {
    "items": [{
      "id": 142, "user_id": 7, "wallet_link_id": 15,
      "chain_id": 1, "contract_address": "0x…", "token_id": "42",
      "collection_name": null, "name": "Genesis #042",
      "image_url": "https://…", "metadata_uri": "ipfs://…",
      "token_standard": "ERC-721",
      "display_order": 0, "added_at": "2026-05-15 14:00:00",
      "chain_slug": "ethereum", "chain_name": "Ethereum",
      "explorer_url": "https://etherscan.io/token/…"
    }]
  }
  ```
  - Numeric fields may arrive as strings from `$wpdb->get_results`; client types accept both per §A2 boundary tolerance.
  - `display_order` is 0-indexed; new selections get `MAX + 1`.
- **Errors:** `bcc_unauthorized 401`.
- **Rate limit:** unthrottled (read-only, single index lookup bounded to 200 rows).
- **Cache:** `no-store`. React Query `staleTime: 30_000`; mutations invalidate the namespace.

#### `POST /bcc/v1/nft-selections`

Add a token to the showcase. Server verifies the token appears in the viewer's HoldingsService holdings before persisting — silent ownership-mismatch is impossible.

- **Auth:** required.
- **JSON body:**
  ```json
  { "chain_id": 1, "contract_address": "0x…", "token_id": "42" }
  ```
- **Response 200 data shape:**
  ```json
  { "id": 142, "ok": true }
  ```
- **Errors:**
  - `bcc_nft_not_owned 403` — the token is not in any of the viewer's linked-wallet holdings (cache may lag; refresh via picker `?force=1` and retry).
  - `bcc_invalid_request 422` — malformed `contract_address` or empty `token_id`.
  - `bcc_rate_limited 429` — 60/60/user shared bucket with DELETE.
  - `bcc_unavailable 503` — DB write failure.
- **Rate limit:** 60/60/user.
- **Side effects:**
  - Bumps the per-user selections generation counter (`gen_user_selections_{user_id}` in cache group `bcc_nft_selections`). §5.
  - No bus event in V1.

#### `DELETE /bcc/v1/nft-selections`

Remove a token from the showcase. Body shape matches POST. Idempotent — removing a non-existent selection returns `{ok: false}`, not 404.

- **Auth:** required.
- **JSON body:** identical to POST.
- **Response 200 data shape:** `{ "ok": true | false }`.
- **Errors:** `bcc_invalid_request 422` (malformed body), `bcc_rate_limited 429` (shares the 60/60 bucket with POST).
- **Rate limit:** 60/60/user (shared with POST).
- **Side effects:** bumps the per-user selections generation counter on `ok: true`.

#### `POST /bcc/v1/nft-selections/refresh`

Explicit force re-fetch of on-chain holdings. Functionally equivalent to `GET /picker?force=1` but with a tighter throttle bucket — reserved for non-picker surfaces (a future wallets-section "Refresh holdings" button) where the picker isn't already open.

- **Auth:** required.
- **JSON body:** none.
- **Response 200 data shape:** same as `GET /picker` (post-refresh snapshot).
- **Errors:** `bcc_rate_limited 429` — 3/60/user.
- **Rate limit:** 3/60/user (separate bucket from picker GET).
- **Side effects:** clears the HoldingsService transient cache for every linked wallet; stamps `wallet_links.last_holdings_refresh_at` on each successful per-wallet chain fetch.
- **Frontend note:** the picker modal does NOT call this endpoint — it uses `GET /picker?force=1` directly because the picker is already open and the cache slot to seed is known. This POST exists for the wallets-section refresh use case.

#### `POST /bcc/v1/nft-selections/reorder`

Set new display order for the viewer's selections.

- **Auth:** required.
- **JSON body:**
  ```json
  { "ordered_ids": [142, 139, 150] }
  ```
  First element becomes `display_order = 0`, etc. Unowned ids are silently skipped (no leak).
- **Response 200 data shape:**
  ```json
  { "ok": true, "updated": 3 }
  ```
  `updated` is the number of rows actually written; can be less than `ordered_ids.length` if some ids were unowned (local cache lagged a remote delete).
- **Errors:** `bcc_invalid_request 422` (missing or non-array `ordered_ids`).
- **Rate limit:** unthrottled in V1 — UI is button-driven and naturally bounded by user pacing. Worth a throttle if abuse appears.
- **Side effects:** bumps the per-user selections generation counter when `updated > 0`.

---

### 4.22 User endorsements — given direction (§J.6)

Public read of pages a specific user has endorsed. The inverse of the attestation roster (which shows attestations RECEIVED by a user via `GET /entities/user_profile/:id/attestations`). Same row shape as `/endorsements/mine`; the per-handle public read shares the server-side hydration helper (`UserEndorsementsEndpoint::hydrate`) with the auth-only `/mine` endpoint so both surfaces emit identical row shapes — single source of trust per §A4.

Anonymous-readable per §J trust-graph doctrine: attestation data is public-by-design. The per-page endorser list is already public via the entity card; this endpoint is the same data sliced by user.

> **v1.28 source note:** attestation-backed. Rows are the user's ACTIVE `vouch` attestations (entity cards AND member self-pages; `stand_behind` is deliberately excluded — it has its own roster surfaces). The legacy `endorsements` table is dropped; its rows were materialized into attestations at the Phase-1 migration, so no history is lost. Row shape unchanged: `context` is the constant `'general'`, `weight` = the attestation's `weight_at_time`; `tier`/`trust_score` still snapshot at request time — from `bcc_page_read_model` for entity pages, from the self-page score row for member self-page targets (self-pages carry no read-model row).

#### `GET /bcc/v1/users/{handle}/endorsements`

- **Auth:** Anonymous OR Bearer (response shape is identical; no viewer-aware fields).
- **Path:**
  - `handle` — required, matches `HANDLE_PATTERN` (lowercase alphanumeric + hyphens, 3–20 chars).
- **Query:**
  - `limit` (integer, optional, default 20, min 1, max 50).
- **Response 200 data shape:**
  ```json
  {
    "items": [{
      "id": 142,
      "page_id": 4521,
      "page_title": "Validator name or page title",
      "page_url": "https://site/p/some-page",
      "avatar_url": "https://… (page-owner avatar; empty string if unresolvable)",
      "trust_score": 67,
      "tier": "trusted",
      "weight": 1.25,
      "context": "general",
      "reason": "Optional public note, server-trimmed; null when blank.",
      "created_at": "2026-05-15 14:23:47"
    }],
    "total": 12
  }
  ```
  - `created_at` is MySQL UTC datetime (`YYYY-MM-DD HH:MM:SS`); frontend normalizes at the boundary via `formatRelativeTime`.
  - `total` is the count of `items` actually returned (not a paginated grand total).
  - `tier` is the *current* reputation tier of the endorsed page, snapshotted from `bcc_page_read_model` at request time — not the tier at the moment of endorsement.
  - `trust_score` may be `null` when the page has no read-model row (e.g., a stub created by claim flow before the first scoring tick). Frontend should not display the score per the §J anti-leaderboard posture; it's exposed for non-UI consumers.
- **Errors:**
  - `bcc_not_found 404` — handle does not resolve to a known user.
  - `bcc_rate_limited 429` — 30/60/IP shared throttle bucket (`users_endorsements`).
- **Rate limit:** 30/60/IP (shared across all `/users/:handle/endorsements` callers — per-handle granularity isn't worth the bucket sprawl).
- **Cache:** `public, max-age=15` with `Vary: Authorization`. Short shared cache absorbs sub-tab toggles inside the backing panel without staling on fresh endorsements. React Query `staleTime: 15_000`.
- **Frontend consumer:** `BackingPanel` "Given" sub-tab on `/u/[handle]`. The "Received" sub-tab consumes `GET /entities/user_profile/:user_id/attestations` (§J.6); together they form the two faces of the operator's trust graph.

---

### 4.23 Account activity + session revocation (Tier D)

In-app counterpart to the `AccountSecurityMailer` out-of-band email channel (`project_account_security_mailer.md`). Two surfaces:

- **`GET /me/account-activity`** — the user's own audit timeline, filtered server-side to the six credential-class events that AccountSecurityMailer also emails on. The 1:1 correspondence is load-bearing: the in-app timeline lets the user cross-check an email alert against an authenticated view of their own account history. A hijacked-session attacker who reads in-app rows can't suppress the email; an attacker who suppresses email can't hide the in-app row.
- **`POST /auth/logout-everywhere`** — destructive credential mutation. Bumps the user's `bcc_token_version` user_meta via `JwtToken::revokeAllForUser`, invalidating every outstanding JWT (including the request's own bearer) on next decode. Writes a `sessions_revoked_all` audit row and fires the AccountSecurityMailer confirmation email BEFORE the response is sent.

Both surfaces require Bearer JWT; suspended accounts → `bcc_forbidden 403`. Standard envelope per §1.4 / §1.5.

#### `GET /bcc/v1/me/account-activity`

- **Auth:** required (401 anonymous, 403 suspended). Self-only by construction — the controller filters on `get_current_user_id()`; the repository's bounded query keys on `user_id`. No admin override.
- **Query:**
  - `page` (integer, optional, default 1, min 1).
  - `per_page` (integer, optional, default 20, min 1, max 50).
- **Response 200 data shape:**
  ```json
  {
    "items": [{
      "id": 142,
      "action": "account_password_changed",
      "target_type": "user",
      "target_id": 7,
      "ip_masked": "192.168.1.***",
      "created_at": "2026-05-15 14:23:47"
    }],
    "total": 12,
    "page": 1,
    "per_page": 20,
    "total_pages": 1
  }
  ```
  - `action` is one of (stable codes, branch on these): `account_email_changed`, `account_password_changed`, `account_deleted`, `wallet_linked`, `wallet_unlinked`, `sessions_revoked_all`. Server-side allowlist enforced — non-security audit rows (vote_*, endorse_*, group_join, etc.) do NOT bleed into this surface.
  - `created_at` is MySQL UTC datetime (`YYYY-MM-DD HH:MM:SS`); frontend normalizes at the boundary via `formatRelativeTime`.
  - `ip_masked` is the source IP with the last octet (IPv4) or last 64 bits (IPv6) replaced with `***`. Empty string when the row's `ip_address` column was NULL at write time.
- **Sensitive-field policy:**
  - `ip_masked` is the ONLY surface for source IP — the raw VARBINARY column never leaves the server. Security-through-obscurity posture for the case where the user's own audit log is later compromised (screen-shoulder-surf, shared device).
  - No user-agent, no session tokens, no device fingerprints. The `bcc_trust_activity` schema doesn't store them.
  - `target_type` + `target_id` are kept for future enrichment surfaces; V1 the UI doesn't display them.
- **Errors:**
  - `bcc_unauthorized 401` — anonymous.
  - `bcc_forbidden 403` — suspended.
  - `bcc_rate_limited 429` — 30/60/user (`me_account_activity` bucket).
- **Rate limit:** 30/60/user. Per-user (not per-IP) — this is an authed read of the user's own data; an attacker scraping their own audit log is bounded by the bucket without inadvertently locking out legitimate cross-tab fetches.
- **Cache:** `no-store`. Per-user state.
- **Frontend consumer:** `AccountActivitySection` on `/settings/account`. Renders the timeline with humanized labels keyed off `action`; paginates via "OLDER →" / "← NEWER" (no infinite scroll on security-sensitive surfaces).

#### `POST /bcc/v1/auth/logout-everywhere`

- **Auth:** required (401 anonymous).
- **Request body:** none.
- **Response 200 data shape:**
  ```json
  { "ok": true }
  ```
- **Errors:**
  - `bcc_unauthorized 401` — anonymous.
  - `bcc_rate_limited 429` — 5/60/user (`logout_everywhere` bucket). Tight cap because the blast radius is total session invalidation; no legitimate user fires this more than a few times per minute.
- **Rate limit:** 5/60/user.
- **Side effects (in order):**
  1. Audit row written: `AuditLogger::log('sessions_revoked_all', $userId, [], 'user', $userId)`. Counts toward the §4.23 GET timeline + 90-day retention.
  2. Out-of-band email: `AccountSecurityMailer::sessionsRevokedAll($userId)`. Track-F redundancy — if an attacker triggers this to lock the legitimate user out, the email channel still warns. Failure records `account_security_mail` / `sessions_revoked_all_send_failed` DegradationMetric per `project_account_security_mailer.md`.
  3. Token version bumped: `JwtToken::revokeAllForUser($userId)`. Every outstanding JWT for this user (including the bearer that authenticated THIS request) fails the `tv` check on next decode and returns `ERR_REVOKED`.
- **Client-side responsibility:** the bearer is invalidated by the time the response is read. The client MUST call `signOut({ callbackUrl: "/" })` (or equivalent) immediately on 200 — any subsequent authenticated request will 401. The hook `useLogoutEverywhere()` in `useAccount.ts` does this automatically in `onSuccess`.
- **Self-revocation note:** the user CAN sign themselves out of their own current session. The mutation is intentionally not session-aware (no "all OTHER devices" carve-out) — this is the minimal D2 per the Tier D scope decision; the per-device listing UI is deferred to a future planning session.
- **Cache:** `no-store`.

#### Cross-channel correspondence

| AuditLogger action          | AccountSecurityMailer email subject                      |
|-----------------------------|----------------------------------------------------------|
| `account_email_changed`     | "Your account email was changed"                         |
| `account_password_changed`  | "Your password was changed"                              |
| `account_deleted`           | "Your account was deleted"                               |
| `wallet_linked`             | "A wallet was linked to your account"                    |
| `wallet_unlinked`           | "A wallet was unlinked from your account"                |
| `sessions_revoked_all`      | "All other devices signed out"                           |

The user can verify any email against the in-app timeline. A row WITHOUT a matching email = the email channel failed (`account_security_mail` DegradationMetric should be active). An email WITHOUT a matching row = the timeline is stale OR the user is looking at a phishing email; treat the timeline as the trust anchor.

### 4.24 Wallets

Self-service wallet management for the `/settings/account` linked-wallets surface plus the §N8 page-claim wallet panel. Pairs with §4.1's `/auth/wallet-*` family — the auth endpoints establish a session; this section manages the wallet set bound to the established session.

Wallet `address_short` formatting follows §1.7. All write paths fire the `AccountSecurityMailer` out-of-band side-channel per §4.23.

#### `GET /bcc/v1/wallets`

Returns the current user's linked wallets.

- **Auth:** required. Suspended accounts → `bcc_forbidden 403`.
- **Response 200:**
  ```json
  {
    "items": [{
      "id": 142,
      "wallet_address": "cosmos1abcdef…",
      "chain_slug": "cosmos",
      "chain_name": "Cosmos Hub",
      "chain_type": "cosmos",
      "explorer_url": "https://www.mintscan.io/cosmos/account/{address}",
      "wallet_type": "user",
      "label": "",
      "is_primary": false,
      "verified": true,
      "created_at": "2026-04-27 14:24:00"
    }],
    "recovery": {
      "has_recovery_email": false,
      "verified_wallet_count": 1
    }
  }
  ```
- **`recovery`** (account-recovery posture for the settings UI): `has_recovery_email` is `true` when a real, non-placeholder email is set (a `@noreply.bcc.local` wallet-signup placeholder reads as `false`); `verified_wallet_count` is the number of the user's verified wallets (wallets usable for wallet login). Together they drive the "set up a recovery method" banner and mirror the `DELETE /wallets/{id}` self-lockout guard (`bcc_last_recovery_method`): the account is at lockout risk when `has_recovery_email` is `false` and `verified_wallet_count <= 1`.
- **Errors:** `bcc_rate_limited` 429.
- **Rate limit:** 30/min/user.
- **Cache:** `Cache-Control: no-store`.

#### `DELETE /bcc/v1/wallets/{id}`

Unlinks a wallet owned by the current user. Idempotent — a double-tap unlink against an already-gone row yields `removed: false` with HTTP 200 (no 404, to avoid leaking whether `id` exists for someone else).

- **Auth:** required. Suspended accounts → `bcc_forbidden 403`.
- **Path:** `id` (integer, wallet_link row id).
- **Response 200:**
  ```json
  { "ok": true, "id": 142, "removed": true }
  ```
- **Errors:** `bcc_unauthorized` 401, `bcc_invalid_request` 400 (id missing), `bcc_last_recovery_method` 409 (see lockout guard below), `bcc_rate_limited` 429.
- **Rate limit:** 10/min/user.
- **Cache:** `Cache-Control: no-store`.
- **Lockout guard (`bcc_last_recovery_method` 409):** the request is refused — and the wallet is NOT removed — when it would delete the caller's **last verified wallet** on an account with no real recovery email (i.e. only the synthetic `@noreply.bcc.local` placeholder). Such an account's wallet is its sole credential, so removing it would be a permanent self-lockout. Clients should prompt the user to add a recovery email or link a second wallet first. Accounts with a real recovery email, or with a second verified wallet, are never blocked.
- **Side effects on a true state transition (`removed: true` for an own-wallet):** removes the wallet's per-wallet on-chain data (NFT holdings + profile selections keyed on the `wallet_link_id`); writes a `wallet_unlinked` audit row (`AuditLogger::log`); fires `AccountSecurityMailer::walletUnlinked` (§4.23 side-channel); and dispatches the trust-engine domain event `bcc_wallet_disconnected` **directly from this endpoint** (this REST path deletes the row itself rather than routing through `WalletIdentityService::unlinkWallet`). Listeners on that event perform claim revocation + trust-score recalc (`BonusService::handleWalletDisconnect`), trust-signal teardown for the wallet's chain (`WalletSignalRepository::disconnect`), and Helius unsubscribe (Solana only).

#### `GET /bcc/v1/wallets/project/{post_id}`

Returns wallets linked to a project / validator / creator page, used by §N8 claim panels and on-page provenance strips.

- **Auth:** required.
- **Path:** `post_id` (integer, peepso-page CPT id).
- **Response 200:** standard `{ data, _meta }` envelope where `data` is an array of wallet records, shape matching `/wallets` items above **with one privacy gate** — non-owners and non-admins see the full record minus `wallet_address`. The post author and admins see the full record.
- **Errors:** `bcc_rate_limited` 429.
- **Rate limit:** 30/min/user.

#### `GET /bcc/v1/chains`

Returns the enabled-chain catalog. Public; consumed by the wallet-link selector and the §N8 claim chain dropdown.

- **Auth:** Anonymous.
- **Response 200:** standard `{ data, _meta }` envelope where `data` is an array of chain records.
  ```json
  {
    "data": [{
      "id": 4,
      "slug": "cosmos",
      "name": "Cosmos Hub",
      "chain_type": "cosmos",
      "chain_id_hex": null,
      "explorer_url": "https://www.mintscan.io/cosmos",
      "native_token": "ATOM",
      "icon_url": "https://…/cosmos.svg"
    }],
    "_meta": { "request_id": "8f3a7b2e9c1d4f6a" }
  }
  ```
- **Errors:** `bcc_rate_limited` 429.
- **Rate limit:** 30/min/IP.

---

### 4.25 Social connections & trust actions (GitHub / X / endorsements / device signal)

> **Envelope note (applies to every endpoint in §4.25):** these routes live in the `bcc-trust/v1` namespace, which returns the **legacy** `{ "success": true, "data": {...} }` envelope — **not** the standard `bcc/v1` `{ data, _meta }` envelope of §1.4. Errors are `WP_Error` (HTTP status from the error's `status`, `error.code` = the stable code, `error.message` = the message; soft-gate data like `unlock_hint` rides `error.data`). The frontend talks to these via the dedicated `bccTrustFetch` helper, which understands the older shape. All routes are bearer-JWT authed via the bcc-trust `BearerAuth` filter. The browser-redirect `GET /github/callback` + `GET /x/callback` routes are **internal** (server-to-browser OAuth round-trip) and intentionally undocumented.

#### `GET /bcc-trust/v1/github/auth`

Mint a GitHub OAuth authorize URL for the current user to redirect to. The caller is responsible for `window.location.href = data.auth_url`.

- **Auth:** Bearer **required** (`Permissions::restCallback`). Anonymous → auth failure.
- **Query:** `return_to` (optional) — a fully-qualified URL on the `BCC_FRONTEND_ORIGIN` allowlist (validated via `FrontendRedirect::validateReturnTo`; persisted to user meta `_bcc_github_return_to` for the callback). Off-allowlist input is silently rejected (callback falls back to `/settings/identity`).
- **Response 200:** `{ "success": true, "data": { "auth_url": string } }`
- **Errors:** `github_not_configured` (500) · `bcc_internal` (500)
- **Rate limit:** none (the rate-limited surface is the unauthenticated `/github/callback`, 10/min/IP)
- **Cache:** none (per-user, side-effecting via user-meta write)
- **Mapping:** `GitHubController::getAuthUrl` (route `GitHubController.php:36`) → `GitHubVerificationService::getAuthUrl`. FE `oauth-endpoints.ts:getGitHubAuthUrl`.

#### `GET /bcc-trust/v1/github/status`

Per-user GitHub connection status.

- **Auth:** Bearer **required**
- **Response 200:** discriminated on `connected`: disconnected `{ "success": true, "data": { "connected": false } }`; connected `{ "success": true, "data": { "connected": true, "username": string, "verified_at": string|null, "last_synced": string|null, "followers": int, "repos": int, "orgs": int } }`
- **Errors:** `bcc_internal` (500)
- **Cache:** none (per-user identity state; treat as private/no-store)
- **Mapping:** `GitHubController::getStatus` (route `GitHubController.php:48`) → `GitHubVerificationService::getStatus`. FE `oauth-endpoints.ts:getGitHubStatus`.

#### `POST /bcc-trust/v1/github/disconnect`

Disconnect the current user's GitHub account. Re-locks the `verify_github` quest and removes the GitHub score impact from the user's pages. Bearer JWT is the CSRF guard (no `X-WP-Nonce`).

- **Auth:** Bearer **required**
- **Body:** none (ignored)
- **Response 200:** `{ "success": true, "data": { "disconnected": true, "username": string|null } }`
- **Errors:** `bcc_internal` (500)
- **Side effects:** revokes `verify_github` quest signal; reverses trust-boost / fraud-reduction on owned pages.
- **Mapping:** `GitHubController::disconnect` (route `GitHubController.php:54`) → `GitHubVerificationService::disconnect`. FE `oauth-endpoints.ts:disconnectGitHub`.

#### `POST /bcc-trust/v1/github/refresh`

Re-fetch the current user's GitHub stats, persist them, and recompute the trust-boost / fraud-reduction page impact.

- **Auth:** Bearer **required**
- **Body:** none (ignored)
- **Response 200:** `{ "success": true, "data": { "refreshed": true, "username": string, "trust_boost": number, "fraud_reduction": number } }`
- **Errors:** `bcc_internal` (500 — covers "GitHub not connected" / token-missing, plus unexpected)
- **Side effects:** overwrites the stored GitHub connection row; re-applies trust/fraud delta; clears transient `bcc_trust_github_{userId}`.
- **Mapping:** `GitHubController::refreshData` (route `GitHubController.php:60`) → `GitHubVerificationService::refresh`. FE `oauth-endpoints.ts:refreshGitHub`.

#### `GET /bcc-trust/v1/x/auth`

Mint an X (Twitter) OAuth authorize URL for the current user. Caller redirects via `data.auth_url`.

- **Auth:** Bearer **required**
- **Query:** `return_to` (optional) — allowlisted fully-qualified URL, validated + persisted to `_bcc_x_return_to`; off-allowlist silently rejected.
- **Response 200:** `{ "success": true, "data": { "auth_url": string } }`
- **Errors:** `x_not_configured` (500) · `bcc_internal` (500)
- **Cache:** none (side-effecting — clears stale OAuth state meta, then writes `_bcc_x_return_to`)
- **Mapping:** `XController::getAuthUrl` (route `XController.php:36`) → `XVerificationService::getAuthUrl`. FE `oauth-endpoints.ts:getXAuthUrl`.

#### `GET /bcc-trust/v1/x/status`

Per-user X connection status (narrower than GitHub — no follower/repo counts).

- **Auth:** Bearer **required**
- **Response 200:** disconnected `{ "success": true, "data": { "connected": false } }`; connected `{ "success": true, "data": { "connected": true, "username": string, "verified_at": string|null, "last_synced": string|null } }`
- **Errors:** `bcc_internal` (500)
- **Mapping:** `XController::getStatus` (route `XController.php:48`) → `XVerificationService::getStatus`. FE `oauth-endpoints.ts:getXStatus`.

#### `POST /bcc-trust/v1/x/disconnect`

Disconnect the current user's X account. Bearer JWT is the CSRF guard.

- **Auth:** Bearer **required**
- **Body:** none (ignored)
- **Response 200:** `{ "success": true, "data": { "disconnected": true, "username": string|null } }`
- **Errors:** `bcc_internal` (500)
- **Mapping:** `XController::disconnect` (route `XController.php:54`) → `XVerificationService::disconnect`. FE `oauth-endpoints.ts:disconnectX`.

#### `POST /bcc-trust/v1/x/verify-share`

Verify that the current user shared their BCC profile on X (searches the user's recent tweets for the site URL via the stored X token; on a real match fires the `share_x` quest). Idempotent: an already-complete quest returns `already_done: true` without re-searching.

- **Auth:** Bearer **required**
- **Body:** none
- **Response 200:** already complete `{ "success": true, "data": { "verified": true, "already_done": true } }`; newly verified `{ "success": true, "data": { "verified": true, "message": "Thanks for sharing! Quest complete." } }`
- **Errors:** `bcc_rate_limited` (429) · `share_not_found` (400, no matching tweet) · `bcc_internal` (500)
- **Rate limit:** 5 / 60s / user (`RateLimiter::allow('x_verify_share', 5, 60)`)
- **Side effects:** fires `do_action('bcc_trust_quest_signal', $userId, 'share_x')`; authoritative validation is server-side in `QuestValidator::validateShareX` (live X-API search) — the controller never pre-sets the meta.
- **Mapping:** `XController::verifyShare` (route `XController.php:60`). FE `oauth-endpoints.ts:verifyXShare`.

#### `POST /bcc-trust/v1/endorse`

Endorse a PeepSo page. Thin controller → `EndorsementService::endorsePage` (owns rate-limiting, eligibility gating, cache invalidation).

- **Auth:** Bearer **required** (`is_user_logged_in()` AND `Permissions::is_not_suspended()`)
- **Query/Body:** `page_id` (**required**, int, `absint`) · `context` (optional, string, default `"general"`, **enum `["general"]`**, `sanitize_key`) · `reason` (optional, string, capped) · `fingerprint` (optional, passthrough anti-fraud signal)
- **Response 200:** `{ "success": true, "data": { "action": "endorse", "page_id": int, "vote": null, "endorsement": { "endorsement_id": int, "page_title": string, "context": string, "weight": number } | null, "score": <PageScore>, "votes_up": int, "votes_down": int, "endorsement_count": int } }`. FE reads `endorsement_count` and ignores server-only fraud fields.
- **Errors:** `bcc_invalid_request` (400) · `bcc_unauthorized` (401) · `bcc_endorse_self` (403) · `bcc_conflict` (409, already endorsed) · `bcc_fraud_locked` (403) · `bcc_rate_limited` (429) · `bcc_permission_denied` (403, soft gate — message + `error.data.unlock_hint` per §1.4.5) · `bcc_internal` (500)
- **Rate limit:** 10 / 300s / user (`BCC_TRUST_RATE_LIMIT_ENDORSE`), plus per-page daily/velocity caps in the service.
- **Mapping:** `TrustRestController::endorse` → `AttestationService::cast` (Slice E cutover, §J.11 — was `EndorsementService::endorsePage`; entity endorse now casts a `vouch` attestation on the `*_card` target, which folds into `attestation_bonus`). The PUBLIC contract (request/response/error vocabulary) is UNCHANGED: the response shape is preserved verbatim by `buildEndorseResponse`, and `TrustRestController::mapEndorseError` maps the attestation eligibility codes back to this endpoint's documented codes. (`bcc_conflict` 409 is now unreachable — re-endorse is idempotent → 200.) FE `endorse-endpoints.ts:endorsePage`. Canonical UX gate is the server-rendered `permissions.can_endorse` + `unlock_hint`; the 4xx codes are the race/direct-call fallback.

#### `POST /bcc-trust/v1/revoke-endorsement`

Revoke a previously-given endorsement. Same controller/service split and response shape as `/endorse` with `action: "revoke_endorsement"`.

- **Auth:** Bearer **required** (logged-in + not-suspended)
- **Query/Body:** `page_id` (**required**, int, `absint`) · `context` (optional, string, default `"general"`, `sanitize_key`; no enum restriction here)
- **Response 200:** identical envelope/shape to `/endorse` with `"action": "revoke_endorsement"`; `endorsement` is `null`, `endorsement_count` reflects the post-revoke count.
- **Errors:** `bcc_unauthorized` (401) · `bcc_rate_limited` (429) · `bcc_not_found` (404, endorsement not found) · `bcc_internal` (500)
- **Rate limit:** 5 / 60s / user (`RateLimiter::enforce('revoke_endorse', 5, 60)`)
- **Mapping:** `TrustRestController::revoke_endorsement` → `AttestationService::revokeByTarget` (Slice E cutover — was `EndorsementService::revokePageEndorsement`; revokes the viewer's active `vouch` attestation on the entity, re-folding the score). Response shape + error vocabulary preserved (idempotent: a missing row is a no-op 200). FE `endorse-endpoints.ts:revokeEndorsement`.

#### `POST /bcc-trust/v1/device-fingerprint`

Client anti-fraud signal submission. Fire-and-forget — the FE caller swallows errors (it is a fraud signal, never a UX gate). Delegates to `UserStatusController::store_fingerprint`.

- **Auth:** Bearer **required** (logged-in + not-suspended; handler re-checks `get_current_user_id()`)
- **Body (JSON):** `fingerprint.hash` (**required**, hex, length 32–128, `/^[a-f0-9]+$/i` — FE sends a 64-char SHA-256) · `data` (optional object — coarse browser signals; stored to `bcc_last_fingerprint_data` only if ≤ 10240 bytes, else silently dropped)
- **Response 200:** `{ "success": true, "data": { "stored": true } }` — **deliberately opaque** (no fraud verdict / account-count / automation score, to deny attacker feedback).
- **Errors:** `bcc_rate_limited` (429) · `bcc_invalid_request` (400 — not-authenticated / invalid fingerprint data / invalid format; other exceptions return the generic message under the same code)
- **Rate limit:** shared `api` bucket (`RateLimiter::allow('api')`)
- **Side effects (server-only, not surfaced):** the client hash is NOT the identity — the server computes its own server-side fingerprint (server signals + `wp_salt('auth')`, SHA-256) as the shared-device bucket; > 3 accounts → `multiple_accounts_detected` audit + alert; automation confidence > 70 → `automation_detected` audit + fraud-score increment + alert.
- **Mapping:** route `TrustRestController.php:129` → `UserStatusController::store_fingerprint`. FE `fingerprint-endpoints.ts:postFingerprint`.

### 4.26 Profile editing, privacy & blocks

Self-only settings surface for the signed-in viewer. Every route here is **Bearer-required** with the auth check performed **in-handler** (`permission_callback => '__return_true'`, then `get_current_user_id() <= 0 → bcc_unauthorized 401`). No admin-override surface — all routes operate on `get_current_user_id()`. All responses use the standard envelope (§1.4) and `Cache-Control: no-store`.

#### `PATCH /bcc/v1/me/profile`

Update text profile fields. Registered as `WP_REST_Server::EDITABLE` (also accepts POST/PUT). Bio is the only supported field in V1.

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized 401`.
- **Body (JSON):** `bio` (**required**, string) — `sanitize_textarea_field` (strips all tags), length-capped at **500**; stored to `wp_users.description`.
- **Response 200:** the full updated **User view-model** (§3.1, own variant).
- **Errors:** `bcc_unauthorized` (401) · `bcc_invalid_request` (422 — `bio` omitted / not a string / > 500) · `bcc_internal_error` (500)
- **Mapping:** `MyProfileEndpoint::patch` (route `MyProfileEndpoint.php:92`, EDITABLE) → `UserViewService::getUser($userId, $userId)`. FE `profile-endpoints.ts:patchProfile`.

#### `POST /bcc/v1/me/profile/avatar`

Upload a custom avatar. PeepSo owns the image pipeline; this route wraps `PeepSoUser::move_avatar_file`.

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized 401`.
- **Body (`multipart/form-data`):** `avatar` (**required**, file) — size ≤ **2 MB**; MIME detected from file contents via `wp_check_filetype_and_ext` (request Content-Type never trusted); must be `image/jpeg|png|webp`.
- **Response 200:** full updated **User view-model** (§3.1, own variant).
- **Errors:** `bcc_unauthorized` (401) · `bcc_peepso_unavailable` (503, PeepSo not loaded) · `bcc_invalid_request` (422, field missing / empty / > 2 MB / bad MIME) · `bcc_upload_failed` (422 PHP upload error; 500 on PeepSo throw)
- **Mapping:** `MyProfileEndpoint::uploadAvatar` (route `MyProfileEndpoint.php:103`, CREATABLE). FE `profile-endpoints.ts:uploadAvatar`.

#### `DELETE /bcc/v1/me/profile/avatar`

Remove the custom avatar (revert to default). Idempotent — a failed PeepSo delete is logged and still returns success.

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized 401`.
- **Response 200:** full updated **User view-model** (§3.1, own variant).
- **Errors:** `bcc_unauthorized` (401) · `bcc_peepso_unavailable` (503) · `bcc_internal_error` (500, only if the post-delete view-model load fails)
- **Mapping:** `MyProfileEndpoint::deleteAvatar` (route `MyProfileEndpoint.php:114`, DELETABLE) → `PeepSoUser::delete_avatar()`. FE `profile-endpoints.ts:deleteAvatar`.

#### `POST /bcc/v1/me/profile/cover`

Upload a cover photo. Wraps `PeepSoUser::move_cover_file` (resize + write + meta in one call).

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized 401`.
- **Body (`multipart/form-data`):** `cover` (**required**, file) — size ≤ **5 MB**; MIME detected from contents; must be `image/jpeg|png|webp`.
- **Response 200:** full updated **User view-model** (§3.1, own variant).
- **Errors:** same set as `POST /me/profile/avatar` (only the `cover` field name + 5 MB cap differ): `bcc_unauthorized` (401) · `bcc_peepso_unavailable` (503) · `bcc_invalid_request` (422) · `bcc_upload_failed` (422/500)
- **Mapping:** `MyProfileEndpoint::uploadCover` (route `MyProfileEndpoint.php:125`, CREATABLE). FE `profile-endpoints.ts:uploadCover`.

#### `DELETE /bcc/v1/me/profile/cover`

Remove the cover photo. Idempotent.

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized 401`.
- **Response 200:** full updated **User view-model** (§3.1, own variant).
- **Errors:** `bcc_unauthorized` (401) · `bcc_peepso_unavailable` (503) · `bcc_internal_error` (500, only if the post-delete view-model load fails)
- **Mapping:** `MyProfileEndpoint::deleteCover` (route `MyProfileEndpoint.php:136`, DELETABLE) → `PeepSoUser::delete_cover_photo()`. FE `profile-endpoints.ts:deleteCover`.

#### `PATCH /bcc/v1/me/profile/cover/position`

Set the cover-photo crop position (drag-to-position). Registered as `WP_REST_Server::EDITABLE` (also POST/PUT). Values are percentages, clamped server-side.

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized 401`.
- **Body (JSON):** `x` (**required**, number 0–100) · `y` (**required**, number 0–100). Both clamped to 0–100; stored as integer strings to `peepso_cover_position_x`/`_y`.
- **Response 200:** full updated **User view-model** (§3.1, own variant).
- **Errors:** `bcc_unauthorized` (401) · `bcc_invalid_request` (422, `x`/`y` missing or non-numeric) · `bcc_internal_error` (500)
- **Mapping:** `MyProfileEndpoint::patchCoverPosition` (route `MyProfileEndpoint.php:147`, EDITABLE). FE `profile-endpoints.ts:patchCoverPosition`.

#### `GET /bcc/v1/me/privacy`

Read the signed-in viewer's privacy toggles (§K2 + §G1 discovery opt-out).

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized 401`.
- **Response 200:** `data` is a flat map of **9 boolean** keys (`PrivacySettings::ALL_KEYS`), missing meta defaults to `false`:
  ```json
  { "data": { "watching_hidden": false, "reviews_hidden": false, "disputes_hidden": false, "delegations_hidden": false, "follower_count_hidden": false, "real_name_hidden": false, "email_hidden": false, "discovery_optout": false } }
  ```
  - `discovery_optout` (§G1) is owner-only and appears ONLY here (the other 7 also surface inside the User view-model).
- **Errors:** `bcc_unauthorized` (401)
- **Mapping:** `MyPrivacyEndpoint::get` (route `MyPrivacyEndpoint.php:48`, READABLE) → `PrivacySettings::readAll`. FE `privacy-endpoints.ts:getMyPrivacy`.

#### `PATCH /bcc/v1/me/privacy`

Partial-update privacy toggles. Registered as `WP_REST_Server::EDITABLE` (also POST/PUT). Only keys present in the body change.

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized 401`.
- **Body (JSON):** a partial bag — every key optional, each a boolean (coerced via `FILTER_VALIDATE_BOOLEAN`). Accepted keys = `PrivacySettings::ALL_KEYS` (the 9 above). Unknown keys silently dropped.
- **Response 200:** the full post-write state — identical shape to `GET /me/privacy` (all 9 keys).
- **Errors:** `bcc_unauthorized` (401) · `bcc_invalid_request` (422, no recognized keys)
- **Mapping:** `MyPrivacyEndpoint::patch` (route `MyPrivacyEndpoint.php:61`, EDITABLE) → `PrivacySettings::writePartial` then `readAll`. FE `privacy-endpoints.ts:updateMyPrivacy`.

#### `GET /bcc/v1/me/blocks`

Paginated list of users the signed-in viewer has blocked (§K1 Phase A). Owner-only.

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized 401`.
- **Query:** `page` (optional, default 1, min 1) · `per_page` (optional, default 20, min 1, max 50)
- **Response 200:** offset-paginated, each item hydrated with handle + display name + avatar:
  ```json
  { "data": { "items": [ { "user_id": 412, "handle": "ramona", "display_name": "Ramona V.", "avatar_url": "https://…", "profile_url": "/u/ramona" } ], "pagination": { "page": 1, "per_page": 20, "total": 1, "total_pages": 1 } } }
  ```
- **Errors:** `bcc_unauthorized` (401)
- **Mapping:** `MyBlocksEndpoint::list` (route `MyBlocksEndpoint.php:48`, READABLE entry) → `MyBlocksService::getMyBlocks` → `PeepSoBlockRepository`. FE `blocks-endpoints.ts:getMyBlocks`.

#### `POST /bcc/v1/me/blocks`

Block a target user. Idempotent — re-blocking returns `state: "existing"`.

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized 401`.
- **Body (JSON):** `user_id` (**required**, int ≥ 1, `absint`)
- **Response 200:** `{ "data": { "ok": true, "user_id": 412, "state": "created" } }` — `state` ∈ `created | existing`.
- **Errors:** `bcc_unauthorized` (401) · `bcc_rate_limited` (429, > 20 creates/60s, checked before target resolution) · `bcc_invalid_request` (400, `user_id` ≤ 0 or self-block) · `bcc_not_found` (404, target missing) · `bcc_unavailable` (503, insert failed)
- **Audit:** `user_blocked` fires only on the `created` transition.
- **Rate limit:** 20/60s/viewer
- **Mapping:** `MyBlocksEndpoint::create` (route `MyBlocksEndpoint.php:48`, CREATABLE entry) → `PeepSoBlockWriter::block` (fires `peepso_user_blocked` + `bcc_user_blocked` on create). FE `blocks-endpoints.ts:blockUser`.

#### `DELETE /bcc/v1/me/blocks/:user_id`

Unblock a target user. Idempotent — `removed: false` when nothing was blocked.

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized 401`.
- **Path:** `user_id` (**required**, int ≥ 1, `\d+` / `absint`)
- **Response 200:** `{ "data": { "ok": true, "user_id": 412, "removed": true } }`
- **Errors:** `bcc_unauthorized` (401) · `bcc_rate_limited` (429, > 20 unblocks/60s) · `bcc_invalid_request` (400, `user_id` ≤ 0)
- **Audit:** `user_unblocked` fires only when a row was actually deleted.
- **Rate limit:** 20/60s/viewer
- **Mapping:** `MyBlocksEndpoint::remove` (route `MyBlocksEndpoint.php:90`, DELETABLE) → `PeepSoBlockWriter::unblock`. FE `blocks-endpoints.ts:unblockUser`.

### 4.27 Highlights, badges, reports, messaging prefs & onboarding

Per-viewer "me" surfaces backing the feed highlight strip, the coalesced badge poll, content reporting, DM-gating preferences, and the post-signup onboarding wizard. Every endpoint here is **Bearer-required** with auth checked **inside the handler** (`permission_callback` is `__return_true`), so anonymous calls get the canonical `{ "error": {...} }` envelope (§1.4). Success bodies are wrapped in `{ "data": ... }` by `ApiResponse::ok`.

#### `GET /bcc/v1/me/highlights`

The feed highlight strip — "what to care about RIGHT NOW" (§O2 / §O2.1). Read-side of the §3.4 `HighlightStrip` view-model.

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized` 401. The frontend hides the HighlightStrip for unauthenticated viewers.
- **Response 200:**
  ```json
  { "data": { "items": [ { "id": "h-positive-reviews_today-2026-06-10-412", "slot": "positive", "category": "reviews_today", "title": "You wrote a review today.", "body": "It's on the record.", "cta": { "label": "View profile", "href": "/u/ramona" }, "actions": { "dismiss": { "method": "POST", "href": "/wp-json/bcc/v1/me/highlights/<id>/dismiss", "idempotent": true, "requires_auth": true } } } ] } }
  ```
  - `items` — 0–3 entries in strict §O2.1 slot order (negative → positive → external); empty slots collapse.
  - `slot` — string ∈ `negative | positive | external`. `category` — per-slot signal key. `title`/`body` are server-rendered §A2 strings. `cta` — `{ label, href }`.
- **V1.0/V1.5 reality:** POSITIVE + EXTERNAL slot scorers are live; NEGATIVE is a production stub (returns `null`). `BCC_HIGHLIGHTS_DEMO` (never defined in prod) returns contract-shaped placeholders.
- **Errors:** `bcc_unauthorized` 401
- **Cache:** `Cache-Control: private, no-store`
- **Mapping:** `HighlightsEndpoint::list` (route `HighlightsEndpoint.php:46`) → `HighlightsService::getHighlights`; dismissed ids filtered via `wp_usermeta.bcc_highlights_dismissed_until`. **Drift vs §3.4:** the live shape is `data.items[]` (not `data.highlights[]`), `slot` is a string (not int), and `severity`/`source_event_id`/`score`/`dismissable`/`dismiss_kind` from the §3.4 sample are not emitted. Treat the live shape as authoritative; §3.4 documents the intended fuller view-model.

#### `POST /bcc/v1/me/highlights/:id/dismiss`

Dismiss a single highlight. Idempotent — re-dismissing extends the per-slot TTL.

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized` 401.
- **Path:** `id` — route-constrained to `[A-Za-z0-9_-]{1,128}`; the service extracts the slot from the `h-{slot}-…` prefix.
- **Response 200:** `{ "data": { "status": "dismissed", "id": "...", "expires_at": "2026-06-11T00:00:00Z" } }` — TTL by slot: negative 30 days, positive/external 24h.
- **Errors:** `bcc_unauthorized` 401 · `bcc_invalid_request` 400 (empty/over-length id, or unresolvable slot prefix)
- **Cache:** `Cache-Control: no-store`
- **Mapping:** `HighlightsEndpoint::dismiss` (route `HighlightsEndpoint.php:56`) → `HighlightsService::dismiss`.

#### `GET /bcc/v1/me/badges`

Coalesced per-viewer badge payload. Replaces three previously-uncached polling endpoints (messages-unread, notifications-unread, per-conversation poll) with one cached read driving `useBadges`.

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized` 401.
- **Query:** `open_threads` (optional) — comma-separated conversation root ids; non-numeric tokens dropped; capped at **5**. Threads the viewer isn't a participant of are silently absent.
- **Response 200:**
  ```json
  { "data": { "messages_unread": 3, "notifications_unread": 1, "open_thread_hints": { "12": { "latest_message_id": 9981, "posted_at": "2026-06-10T14:03:11Z" } }, "polled_at": "2026-06-10T14:05:00Z" } }
  ```
  `open_thread_hints` lets an open conversation refetch only when `latest_message_id` advances. `polled_at` refreshes even on a cache hit (counts may be up to 15s older than `polled_at`).
- **Errors:** `bcc_unauthorized` 401
- **Cache:** `Cache-Control: private, max-age=10`. Server-side: 15s payload cache via the §5 generation-counter pattern (group `bcc_badges`), bumped on bell-row create, mark-read, DM send, DM view.
- **Mapping:** `MeBadgesEndpoint::get` (route `MeBadgesEndpoint.php:50`) → `BadgesService::getBadges` (← `MessagesService` + `NotificationRepository` + `PeepSoMessageRepository`). Relates to §4.19.

#### `POST /bcc/v1/me/reports`

File a content report against a feed item (§K1 Phase B). Idempotent per (reporter, target).

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized` 401.
- **Body (JSON):** `target_kind` (**required**, V1 only `feed_item`) · `target_id` (**required**, int ≥ 1, the `act_id`) · `reason_code` (**required**, ∈ `spam|harassment|hate|violence|misinformation|other`) · `comment` (optional, ≤ 500 chars; **required when `reason_code = other`**)
- **Response 200:** `{ "data": { "ok": true, "report_id": 5512, "status": "created" } }` — `status` ∈ `created | existing` (UNIQUE `(reporter, target_kind, target_id)` blocks a second row; `existing` returns `report_id: 0`).
- **Errors:** `bcc_unauthorized` 401 · `bcc_invalid_request` 400 (bad kind/id/reason, comment > 500, missing comment for `other`, or self-report) · `bcc_rate_limited` 429 (FLAG limits, 5 / 5 min / reporter) · `bcc_unavailable` 503
- **Mapping:** `MyReportsEndpoint::create` (route `MyReportsEndpoint.php:38`) → `ContentReportService::fileReport`; fires `do_action('bcc_content_reported', …)` for async Phase C subscribers.

#### `POST /bcc/v1/report-user`

Report another **member** (distinct from `POST /me/reports`, which reports a `feed_item`). Member-report → wp-admin "User Reports" moderation tab → admin "penalize" applies a trust-score deduction (`bcc.trust.admin_report_penalty`). This is the BCC trust-engine member-report path; it is independent of PeepSo's native flag-only profile report. Registered in `DisputeController` (`bcc/v1` namespace). **Implemented + admin-wired; the headless frontend does not yet surface a "report member" button — V1.5 frontend work.**

- **Auth:** Bearer **required** (`is_user_logged_in() && Permissions::is_not_suspended(null, false)`).
- **Body (JSON):** `reported_user_id` (**required**, int ≥ 1) · `reason_key` (**required**) ∈ `spam|harassment|fraud|misinformation|inappropriate|impersonation|other` (`sanitize_key`) · `reason_detail` (optional, string, ≤ 1000, `sanitize_textarea_field`, default `""`; **required ≥ `BCC_DISPUTES_MIN_DETAIL_LENGTH` chars when `reason_key = other`**)
- **Response 200:** `{ "data": { "message": "Your report has been submitted. Our team will review it shortly." }, "_meta": {...} }`
- **Errors (bare codes, not `bcc_`-prefixed — consistent with the other `DisputeController` routes):** `rate_limited` 429 (60s submit throttle) · `cannot_self_report` 400 · `user_not_found` 404 · `detail_required` 400 (`other` with too-short detail) · `report_limit_reached` 429 (reporter ≥ 5/day) · `already_reported` 409 (active report already exists reporter→reported) · `target_report_limit` 429 (target already has ≥ 10 active reports — anti-brigading) · `db_error` 500
- **Rate limit:** 1 / 60s / reporter, plus the 5/day reporter cap and the 10-active-against-target ceiling above.
- **Side effects:** inserts a `bcc_user_reports` row (`DisputeRepository::createReport`); enqueues two async emails (`bcc_disputes_email_reported_user`, `bcc_disputes_email_admin_report`) — enqueue failures are isolated so the 200 still returns and the row remains for a later retry; `CoreLogger::audit('user_reported', …)`.
- **Cache:** `no-store`.
- **Mapping:** `DisputeController::report_user` (route `DisputeController.php:82`) → `DisputeRepository::createReport` (+ guards `hasActiveReport` / `countRecentReportsByReporter` / `countActiveReportsAgainst`). Admin adjudication: wp-admin "User Reports" tab (`DisputeAdmin` + `ReportListTable`) → `updateReportStatus` + `bcc.trust.admin_report_penalty`.

#### `GET /bcc/v1/me/messages-prefs`

Read the viewer's DM-gating preferences (PeepSo-backed). Relates to §4.19. Self-only.

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized` 401.
- **Response 200:** `{ "data": { "chat_enabled": true, "chat_friends_only": false } }` — `chat_enabled` defaults **true**, `chat_friends_only` defaults **false** when no meta row exists.
- **Errors:** `bcc_unauthorized` 401
- **Cache:** `Cache-Control: no-store`
- **Mapping:** `MyMessagesPrefsEndpoint::get` (route `MyMessagesPrefsEndpoint.php:66`, READABLE) → reads `peepso_chat_enabled` + `peepso_chat_friends_only`. FE `messages-prefs-endpoints.ts`.

#### `PATCH /bcc/v1/me/messages-prefs`

Partial update of the viewer's DM-gating preferences. Registered as `WP_REST_Server::EDITABLE` (also POST/PUT). Omitted keys untouched.

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized` 401.
- **Body (JSON, every field optional):** `chat_enabled` (bool) · `chat_friends_only` (bool) — coerced via `FILTER_VALIDATE_BOOLEAN`, stored as `"1"`/`"0"`.
- **Response 200:** same shape as the GET (full re-read after write).
- **Errors:** `bcc_unauthorized` 401 · `bcc_invalid_request` 422 (no recognized field)
- **Cache:** `Cache-Control: no-store`
- **Mapping:** `MyMessagesPrefsEndpoint::patch` (route `MyMessagesPrefsEndpoint.php:76`, EDITABLE) → `update_user_meta` on the two PeepSo keys, then re-read.

#### `GET /bcc/v1/onboarding/suggestions`

Admin-curated first-pull follow candidates for the post-signup wizard (§B4). **Auth-gated despite the public-looking path.**

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized` 401.
- **Response 200:** three named buckets, each a list of polymorphic §L5/§3.2 Card view-models:
  ```json
  { "data": { "validators": [ { "card_kind": "validator", "...": "..." } ], "projects": [ { "card_kind": "project", "...": "..." } ], "creators": [ { "card_kind": "creator", "...": "..." } ] } }
  ```
  Each bucket holds up to **4** cards; buckets present even when empty. Bucket→type→kind map locked in `OnboardingEndpoint::BUCKETS`.
- **Errors:** `bcc_unauthorized` 401
- **Cache:** `Cache-Control: private, max-age=60`
- **Mapping:** `OnboardingEndpoint::suggestions` (route `OnboardingEndpoint.php:119`) → `PageDiscoveryService::query` (§F1 ranker) re-hydrated through `CardViewService::getCard`. FE `onboarding-endpoints.ts`.

#### `POST /bcc/v1/me/onboarding/complete`

Flip the onboarding-completion flag (and optionally persist the wizard's home-chain pick).

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized` 401.
- **Body (JSON):** `home_chain` (optional) — lowercased + trimmed, allowlist-checked against `cosmos|osmosis|injective|ethereum|solana|polkadot|thorchain|near`; null/empty = skipped; stored to `bcc_home_chain`.
- **Response 200:** `{ "data": { "completed": true, "home_chain": "ethereum", "rank_label": "Apprentice" } }` — `home_chain` null when none supplied; `rank_label` is the current auto-derived §A2 label (`""` for unknown).
- **Errors:** `bcc_unauthorized` 401 · `bcc_invalid_request` 422 (`home_chain` not in allowlist)
- **Cache:** `Cache-Control: no-store`
- **Mapping:** `OnboardingEndpoint::completeOnboarding` (route `OnboardingEndpoint.php:129`); sets `bcc_onboarding_completed = '1'`, audit-logs `onboarding_completed` on first completion, fires `do_action('bcc_onboarding_completed', …)`.

#### `GET /bcc/v1/me/onboarding/status`

Fresh read of the onboarding flag — drives the `/onboarding` gate to skip the wizard for already-onboarded users.

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized` 401.
- **Response 200:** `{ "data": { "onboarded": true } }` (`bcc_onboarding_completed === '1'`).
- **Errors:** `bcc_unauthorized` 401
- **Cache:** `Cache-Control: no-store`
- **Mapping:** `OnboardingEndpoint::onboardingStatus` (route `OnboardingEndpoint.php:146`); direct `get_user_meta`.

#### `PATCH /bcc/v1/me/handle`

Change the viewer's public handle (§B6). 7-day cooldown. Registered as `WP_REST_Server::EDITABLE` (also POST/PUT).

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized` 401.
- **Body (JSON):** `handle` (**required**) — lowercased + trimmed; §B6 rules (3–20 chars, `a-z`/digits/`-`, no leading/trailing/consecutive hyphens, not reserved).
- **Response 200:** `{ "data": { "handle": "ramona-v", "next_change_at": "2026-06-17T14:05:00Z" } }` — `next_change_at` is now + 7 days, **null on a no-op rename** (submitting the existing handle short-circuits without arming the cooldown).
- **Errors (checked in this order):** `bcc_unauthorized` 401 · `bcc_rate_limited` 429 (within the 7-day cooldown; checked FIRST so a locked-out user can't probe availability; carries `Retry-After`) · `bcc_invalid_handle` 422 · `bcc_handle_reserved` 422 · `bcc_conflict` 409 (taken)
- **Rate limit:** one successful change per 7 days (`HandleService::COOLDOWN_SECONDS = 604800`)
- **Cache:** `Cache-Control: no-store`
- **Mapping:** `OnboardingEndpoint::updateHandle` (route `OnboardingEndpoint.php:102`, EDITABLE) → `HandleService`; on a real rename writes `bcc_handle` + `bcc_handle_last_changed`, audit-logs `handle_changed`, fires `do_action('bcc_handle_changed', …)`.

#### `DELETE /bcc/v1/me/reviews/:id`

Remove the viewer's own review on a page.

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized` 401.
- **Path:** `id` — the target **page id** (`\d+`, `absint`); this is the page whose review by the viewer is removed, not a review-row id. **v1.49:** member self-page ids are valid here — this is the member-review removal path. The frontend sources the id from the member card's `review_target_id` field (it never derives `ID_BASE` itself).
- **Response 200:** `{ "data": { "ok": true, "page_id": 4471 } }`
- **Errors:** `bcc_unauthorized` 401 · `bcc_invalid_request` 400 (`page_id` ≤ 0) · `bcc_unavailable` 503 (vote-removal failure)
- **Cache:** `Cache-Control: no-store`
- **Mapping:** `PostsEndpoint::removeReview` (route `PostsEndpoint.php:58`) → `PostsService::removeReview` → `VoteService::removePageVote` (a review is the viewer's page vote).

### 4.28 Posts, reactions, blog composer & cold-start feed

The umbrella composer-create endpoint plus the reaction writer, the §D6 crypto-blog read/edit/picker surfaces, and the home-feed cold-start bridge. All in the `bcc/v1` namespace, standard §1.4 envelope. Read surfaces return §3.3 `FeedItem`s; the reaction shape is §2.11 `ReactionState`. Photo (§4.14) and GIF (§4.15) posts are separate routes; comments are §4.13.

#### `POST /bcc/v1/posts`

Create a post. The `kind` discriminator routes to one of three writers (`status` / `review` / `blog`). Auth checked in-handler (anonymous → `bcc_unauthorized 401`).

- **Auth:** required. Anonymous → `bcc_unauthorized 401`.
- **Content-Type:** `application/json`.
- **Body (common):** `kind` (string, optional, default `status`) ∈ `status|review|blog` (`sanitize_key`) · `content` (string, **required**) — kind-dependent (PeepSo's `add_post` owns escaping; not `wp_kses`'d at the REST layer).
- **Body (`kind=status`):** `group_id` (int, optional, > 0 — §4.7.6 group-scope; viewer must be an active member, validated before throttle) · `visibility` (string, optional, default `members_only`) ∈ `members_only|public_group|public_all` (only meaningful when `group_id > 0`; unrecognized clamps to `members_only`; stored `_bcc_post_visibility`; **`public_all` is authorization-gated — see the §4.14 `visibility` rule: open → any posting member, closed/secret → owner/admin or opted-in members, else `bcc_permission_denied 403`**) · content 1–500 chars.
- **Body (`kind=review`):** the target is **either** an entity page **or** a member. Entity: `target_page_id` (int, > 0; `group_id` ignored). Member (Slice 2, Architecture A — a person is a trust subject): `target_kind` = `user_profile` + `target_user_id` (int, > 0) — resolved server-side to the member's lazily-provisioned self-page; the self-page id scheme stays a backend detail. Always: `grade` (string, **required**) ∈ `trust|neutral|caution` (→ vote_type +1/0/−1) · content 1–4000 chars (the written reason — required for every review, incl. a down-review). A member down-review additionally fails closed (`bcc_forbidden`) when the target reported the voter within `BCC_TRUST_RETALIATION_WINDOW_DAYS`; self-reviews and the Trusted/Elite downvote gate apply identically to entity votes.
- **Body (`kind=blog`, §D6):** `content` (**required**, full_text, 1–60000) · `excerpt` (**required**, 80–500) · `title` (optional, ≤ 120) · `category` (optional) ∈ `news|analysis|guide|opinion|tools|events` · `tags` (string[], ≤ 5; lowercase `[a-z0-9-]`, ≤ 24 each, deduped) · `chain_tags` (string[], ≤ 3 — chain **slugs** over the wire, resolved via `ChainRepository::getBySlug`) · `disclosure` ({tickers ≤ 20 uppercase `[A-Z0-9]` ≤ 12 each, note ≤ 500} | null — empty struct rejected, send `null`) · `sources` (string[], ≤ 20, ≤ 280 each, deduped) · `cover_image_id` (int, > 0 — from `POST /blog/cover-image`, must be an `attachment` owned by the author) · `status` (optional, default `publish`) ∈ `draft|publish` (a draft fires no `bcc_blog_post_created`, gets no activity row, reachable only via `GET /posts/:id`). `group_id` is **rejected** for `kind=blog` (V1 blogs land on the author's own wall).
- **Rate limit:** burst seatbelt — 5 / 120s / author, keyed separately for status / blog. Reviews additionally pass through `VoteService`'s fraud/rate/coordination pipeline.
- **Response 200 (`kind=status`):** `{ "ok": true, "feed_id": "feed_2210184", "post_id": 4012, "act_id": 2210184 }`
- **Response 200 (`kind=review`):** `{ "ok": true, "feed_id": null, "vote_id": 9001, "page_id": 55, "grade": "trust" }` — `feed_id` is `null` (the activity row is written async by `ActivityStreamWriter` on `bcc_review_published`). For a member review `page_id` is the resolved self-page id.
- **Response 200 (`kind=blog`):** `{ "ok": true, "post_id": 4012, "excerpt_length": 142, "full_text_length": 5300, "status": "publish" }`
- **Errors:** `bcc_unauthorized` 401 · `bcc_invalid_request` 400 (unsupported kind; missing/over-length content/excerpt/title/tag/source; under-length excerpt; review missing/zero `target_page_id` (entity) or `target_user_id` (member); invalid grade; unknown category/chain_tag; malformed tags/disclosure; cover not found/owned; invalid status; `kind=blog` with `group_id`) · `bcc_invalid_mention_target` 400 (§3.3.12 `MentionPolicy`; echoes `{user_id}`) · `bcc_too_many_mentions` 400 (`{max: 10}`) · `bcc_forbidden` 403 (review eligibility / PeepSo `PERM_POST`) · `bcc_permission_denied` 403 (group-scoped status, non-member; **or `visibility=public_all` by a member not authorized to syndicate — §4.14 `visibility` rule**) · `bcc_not_found` 404 (group missing/secret) · `bcc_rate_limited` 429 · `bcc_unavailable` 503
- **Side effects:** `bcc_post_created` / `bcc_review_published` / `bcc_blog_post_created` (publish only) on the §A3 bus; subscribers run async via Action Scheduler.
- **Cache:** `no-store`.
- **Mapping:** `PostsEndpoint::create` (route `PostsEndpoint.php:75`) → `PostsService::createStatus` / `createReview` / `createBlog`.

#### `GET /bcc/v1/posts/:id`

Owner-only blog edit-read for the §D6 composer's `?edit=<id>` cold-load. Returns the flat blog-edit view-model (NOT a `FeedItem`). The author's own **drafts** are returned (the only way to hydrate a draft for editing).

- **Auth:** `current_user_can('read')` at the route; the service enforces `post_author === viewer`.
- **Path:** `id` (int, the wp_post id).
- **Response 200:**
  ```json
  { "excerpt": "…", "full_text": "…", "wp_post_id": 4012, "title": "…", "category": "analysis", "tags": ["staking"], "chain_tags": [{ "id": 3, "slug": "cosmos", "name": "Cosmos", "color": "#2E3148", "icon_url": "https://…" }], "disclosure": { "tickers": ["ATOM"], "note": "…" }, "cover_image_url": "https://…", "cover_image_id": 5120, "sources": ["https://… — staking paper"], "status": "draft" }
  ```
  Every field always present; unset fields resolve to `""`/`null`/`[]`. `cover_image_url` is the `large` thumbnail.
- **Errors:** `bcc_unauthorized` 401 · `bcc_forbidden` 403 (not the author) · `bcc_not_found` 404 (missing / wrong type / not a blog)
- **Cache:** `no-store`.
- **Mapping:** `PostsEndpoint::getBlogForEdit` (route `PostsEndpoint.php:265`) → `BlogService::getBlogForEdit`. FE `blog-endpoints.ts:getBlogPost`.

#### `PATCH /bcc/v1/posts/:id`

Owner blog **partial update** (§D6 PR-B). Registered as `WP_REST_Server::EDITABLE` (also accepts POST/PUT); FE uses PATCH. Every body field optional; **null/omitted = unchanged**.

- **Auth:** `current_user_can('read')` at the route; service enforces `post_author === viewer`.
- **Content-Type:** `application/json`. **Path:** `id` (int).
- **Body (all optional):** `title`, `excerpt`, `content`, `category`, `tags`, `chain_tags`, `disclosure`, `sources`, `cover_image_id`, `status` — same per-field validation/caps as `POST /posts kind=blog`. Three-state tunnels: `tags`/`chain_tags`/`sources` omitted=unchanged, `[]`=clear, non-empty=replace; `disclosure` omitted=unchanged (internal `__noop__` sentinel), `null`=clear, object=replace; `cover_image_id` omitted=unchanged, `0`=un-pin, positive=replace; `title` omitted=unchanged, `""`=clear; `category` empty="no change"; `status` drives `transition_post_status` → `BlogStatusTransitionHandler` (draft→publish fires `bcc_blog_post_created`; publish→draft removes the activity row); `wp_save_post_revision` runs before mutation.
- **Response 200:** `{ "ok": true, "post_id": 4012, "status": "publish" }` (`status` reflects the post-update state).
- **Errors:** `bcc_unauthorized` 401 · `bcc_forbidden` 403 · `bcc_not_found` 404 · `bcc_invalid_request` 400 (any field validation failure; echoes `{max}`/`{category}`/`{chain_id}`) · `bcc_unavailable` 503
- **Cache:** `no-store`.
- **Mapping:** `PostsEndpoint::updateBlog` (route `PostsEndpoint.php:298`, EDITABLE) → `PostsService::updateBlog`. FE `blog-endpoints.ts:updateBlog` (PATCH).

#### `POST /bcc/v1/reactions`

Set / replace the viewer's reaction on a feed item. Idempotent on same-kind set; swap on different-kind. Returns the §2.11 `ReactionState` block so the response patches the feed cache directly.

- **Auth:** required (in-handler). Anonymous → `bcc_unauthorized 401`.
- **Body (JSON):** `feed_id` (string, **required**) — the opaque `feed_<act_id>` string the feed emitted (clients round-trip it; bad prefix/tail → `bcc_invalid_request`) · `reaction` (string, **required**) — a kind from `ReactionGrammarMap::allKnownKinds()` (trust: `solid`; social: `like|love|haha|wow|fire`). The kind MUST belong to the **post's** grammar (cross-grammar is rejected). `vouch`/`stand_behind` are attestations (§J), not reaction kinds — sending them here returns `bcc_invalid_request`.
- **Rate limit:** 60/min/user (key `react`).
- **Response 200 (§2.11 `ReactionState`):** `{ "kind_grammar": "trust", "counts": { "solid": 14 }, "viewer_reaction": "solid" }` — `counts` zero-filled for the post's grammar; `viewer_reaction` kind or `null`.
- **Errors:** `bcc_unauthorized` 401 · `bcc_rate_limited` 429 · `bcc_invalid_request` 400 (bad `feed_id`; cross-grammar) · `bcc_permission_denied` 403 (group-scoped post, non-member — read but not react) · `bcc_unavailable` 503 (reaction types not seeded) · `bcc_internal_error` 500 (writer failed)
- **Side effects:** `bcc_reaction_added` on the §A3 bus (notification dispatch + first-action listener + future analytics). *(v1.28 correction: the former `post_vouch` endorsement side effect was retired with the vouch relocation — a `vouch` REACTION is engagement chrome only; the score-bearing vouch is the per-author byline toggle via `/me/attestations`.)*
- **Cache:** `no-store`.
- **Mapping:** `ReactionsEndpoint::setReaction` (route `ReactionsEndpoint.php:78`) → bcc-core `PeepSoReactionWriter`. FE `reaction-endpoints.ts:setReaction`.

#### `DELETE /bcc/v1/reactions/:feed_id`

Remove the viewer's reaction. Idempotent — removing when nothing is set is a no-op returning the current §2.11 state.

- **Auth:** required (in-handler). Anonymous → `bcc_unauthorized 401`.
- **Path:** `feed_id` (string, `feed_<act_id>`; route pattern `[a-z0-9_]+`).
- **Rate limit:** 60/min/user (shared `react` budget).
- **Response 200:** §2.11 `ReactionState` for the post's grammar (`viewer_reaction` typically `null`).
- **Errors:** same as `POST /reactions` minus cross-grammar — `bcc_unauthorized` 401 · `bcc_rate_limited` 429 · `bcc_invalid_request` 400 · `bcc_permission_denied` 403 · `bcc_internal_error` 500
- **Side effects:** `bcc_reaction_removed` on the §A3 bus.
- **Cache:** `no-store`.
- **Mapping:** `ReactionsEndpoint::removeReaction` (route `ReactionsEndpoint.php:105`). FE `reaction-endpoints.ts:removeReaction`.

#### `POST /bcc/v1/feed/:id/stoke` · `DELETE /bcc/v1/feed/:id/stoke`

Stoke — the forge-fire engagement toggle (v1.33 backfill). X-"like" model: one stoke per person per post; POST sets it, DELETE clears it, both **idempotent**. Stoke is NOT a §2.11 reaction-kind write — it is backed by its own `wp_bcc_trust_stokes` table (`StokeRepository`), not `peepso_reactions` — but it shares the surrounding architecture: same throttle pattern, same group-privacy interaction gate as `/reactions`, same event-emission convention. **Cosmetic for trust** — never writes `bcc_trust_scores`.

- **Auth:** required (in-handler). Anonymous → `bcc_unauthorized 401`.
- **Path:** `id` (int — raw activity id, same as `GET /feed/:id`).
- **Rate limit:** 60/min/user (key `stoke`, shared across add/remove).
- **Response 200 (both verbs):** the post's §2.11 `ReactionState` block (`kind_grammar` / `counts` / `viewer_reaction` — carried so the frontend cache patch never drops them) **extended additively** with:
  - `heat_stage` (int, ≥ 1) — the post's aggregate stoke heat tier
  - `viewer_has_stoked` (bool)
  - `stoke_count` (int) — public total
- **Errors:** `bcc_unauthorized` 401 · `bcc_rate_limited` 429 · `bcc_invalid_request` 400 (non-positive id) · `bcc_permission_denied` 403 (group-scoped post, non-member — `GroupInteractionGate`, same posture as `/reactions`) · `bcc_internal_error` 500 (repository write failed)
- **Side effects:** `bcc_stoke_added` / `bcc_stoke_removed` on the §A3 bus.
- **Cache:** `no-store`.
- **Mapping:** `StokeEndpoint::addStoke` / `removeStoke` → `StokeRepository`. FE `stoke-endpoints.ts`.

#### `POST /bcc/v1/comments/:id/stoke` · `DELETE /bcc/v1/comments/:id/stoke`

Stoke on a **comment** (v1.38). A comment is itself a `peepso_activities` row, so its act_id keys `wp_bcc_trust_stokes` exactly like a post's — same `StokeRepository`, no schema change. Dedicated endpoint (not a reuse of `/feed/:id/stoke`) because the group gate MUST resolve membership off the comment's **parent post**: the comment's own wp_post carries no `peepso_group_id`, so gating on the comment's act_id would silently pass every gated thread. Plain X-"like" toggle — **no `heat_stage`** (a comment is not a velocity rail). **Cosmetic for trust** — never writes `bcc_trust_scores`.

- **Auth:** required (in-handler). Anonymous → `bcc_unauthorized 401`.
- **Path:** `id` (int — the comment's raw act_id; the FE strips its `comment_` prefix client-side).
- **Rate limit:** 60/min/user (key `comment_stoke`, shared across add/remove, separate bucket from post `stoke`).
- **Response 200 (both verbs):** the comment's stoke pair only — `{ stoke_count, viewer_has_stoked }`. No reaction envelope, no `heat_stage`.
- **Errors:** `bcc_unauthorized` 401 · `bcc_rate_limited` 429 · `bcc_invalid_request` 400 (non-positive id) · `bcc_not_found` 404 (act_id is not a published comment row — top-level post act_ids 404 here by design) · `bcc_permission_denied` 403 (parent post is group-scoped, viewer not a member — `GroupInteractionGate::checkPost`) · `bcc_internal_error` 500 (repository write failed)
- **Side effects:** `bcc_stoke_added` / `bcc_stoke_removed` on the §A3 bus (same events as post stokes; the payload act_id may now be a comment's).
- **Cache:** `no-store`.
- **Mapping:** `CommentStokeEndpoint::addStoke` / `removeStoke` → `CommentRepository::getCommentMeta` (parent resolve) + `StokeRepository`. FE `comment-endpoints.ts:setCommentStoke/removeCommentStoke` via `useCommentStoke`.

#### `GET /bcc/v1/blog/chain-options`

Public picker source for the §D6 composer's chain-tag multi-select. Returns active `bcc_onchain_chains` rows with only display fields (chain internals omitted).

- **Auth:** anonymous-readable (so the composer can prefetch without `/me`).
- **Response 200:** `{ "items": [ { "id": 3, "slug": "cosmos", "name": "Cosmos", "color": "#2E3148", "icon_url": "https://…" } ] }` (`color`/`icon_url` null when unset).
- **Cache:** `Cache-Control: public, max-age=3600` (object-cache + 5-min transient via `ChainRepository::getActive()`).
- **Mapping:** `BlogChainOptionsEndpoint::list` (route `BlogChainOptionsEndpoint.php:55`). FE `blog-endpoints.ts:getBlogChainOptions`.

#### `POST /bcc/v1/blog/cover-image`

Multipart blog cover-image upload (§D6 PR-B). Wraps `wp_handle_upload` + `wp_insert_attachment` with `post_author = uploader` (so the later cover-ownership check passes). No activity row emitted.

- **Auth:** required (in-handler — anonymous → `bcc_unauthorized 401`).
- **Content-Type:** `multipart/form-data`.
- **Form fields:** `cover_image` (file, **required**) — allowed MIME `image/jpeg|png|webp|gif`; hard cap **8 MiB**; MIME sniffed from contents (browser Content-Type not trusted); multi-file rejected.
- **Rate limit:** burst seatbelt 5 / 60s / author (before disk write).
- **Response 200:** `{ "ok": true, "attachment_id": 5120, "url": "https://…/cover.webp", "width": 1600, "height": 900 }` (`attachment_id` → `cover_image_id` on blog create/update).
- **Errors:** `bcc_unauthorized` 401 · `bcc_invalid_request` 400 (missing field; multi-file; PHP upload error; empty; oversized `{max_bytes: 8388608}`; disallowed `{mime}`) · `bcc_rate_limited` 429 · `bcc_unavailable` 503
- **Cache:** `no-store`.
- **Mapping:** `BlogCoverImageEndpoint::upload` (route `BlogCoverImageEndpoint.php:55`) → `BlogCoverImageWriter::upload`. FE `blog-endpoints.ts:uploadBlogCoverImage`.

#### `GET /bcc/v1/feed/cold-start`

Public cold-start three-block bridge for the home-feed empty state — a civic map, not a recommendation engine. Composes Locals + recent operators + hot posts. Bounded (3 locals, 4 operators, 2 hot posts); no pagination.

- **Auth:** auth-permissive. Authed viewers get chain-aligned Locals (`bcc_home_chain`) + a per-(viewer, UTC-day) stable-random operator shuffle; anon viewers share the per-day seed.
- **Response 200:**
  ```json
  { "locals": [ { "slug": "iron-local-342", "name": "Iron Local 342", "chain_slug": "cosmos", "member_count": 88 } ], "recent_operators": [ { "handle": "ramona", "display_name": "Ramona V.", "avatar_url": "https://…", "card_tier": "rare", "tier_label": "Rare", "rank_label": "Journeyman", "recent_action": "REVIEWED a card", "link": "/u/ramona" } ], "hot_posts": [ /* §3.3 FeedItem[] */ ] }
  ```
  `recent_operators` are ordered by a stable daily shuffle (NOT ranked); `recent_action` uses a locked past-tense verb allowlist with a `Recently on the floor.` fallback. `card_tier`/`tier_label` may be `null`. `hot_posts` are `FeedRankingService::getHotFeed` items verbatim.
- **Cache:** `Cache-Control: private, no-store` (per-viewer per-day stable-randomization).
- **Mapping:** `FeedColdStartEndpoint::handle` (route `FeedColdStartEndpoint.php:45`) → `FeedColdStartService::getColdStart`; operator hydration reuses the `MemberSummaryPrefetcher` bundle. FE `cold-start-endpoints.ts:getColdStart`.

### 4.29 Group & local detail, entity tabs, creator gallery

Backfills the `####` endpoint headers the contract already cross-references (§4.7.5 group detail, §4.7.6 group feed, §4.7.7 group members) plus the entity-profile tab data planes and the now-shipped creator NFT gallery list. All in namespace `bcc/v1`, standard §1.4 envelope. Reads are auth-optional unless noted; the per-viewer fields they carry drive the cache posture.

#### `GET /bcc/v1/groups/:slug` (§4.7.5)

Cross-kind single-group detail view-model (`nft`/`local`/`system`/`user`). Powers `/communities/[slug]` header, membership pill, join/leave controls, and the `feed_visible` + `permissions.can_read_feed` gate consumed by `<GroupFeedSection>`.

- **Auth:** Anonymous OR Bearer. Anonymous → public slice (`viewer_membership: null`); authed → `viewer_membership` + viewer-resolved `permissions`.
- **Path:** `slug` — `[a-z0-9][a-z0-9-]{0,99}`, required.
- **Response 200:**
  ```json
  { "id": 4231, "slug": "holders-bored-apes", "name": "Holders: Bored Apes", "type": "nft", "privacy": "closed", "description": "…", "image_url": "https://…", "member_count": 87, "verification": { "kind": "on_chain", "label": "On-Chain Verified" }, "activity": { "posts_last_7d": 14, "last_activity_at": "2026-05-04T14:22:00Z", "heat": "warm", "heat_label": "Warm" }, "collection_stats": { "...": "§4.7.4 block, NFT-type only else null" }, "viewer_membership": { "is_member": true, "joined_at": "2026-01-12T00:00:00Z" }, "permissions": { "can_join": { "allowed": false, "unlock_hint": null, "reason_code": "already_member" }, "can_leave": { "allowed": true, "unlock_hint": null, "reason_code": null }, "can_read_feed": { "allowed": true, "unlock_hint": null, "reason_code": null } }, "feed_visible": true, "members_visible": true, "can_use_public_all": true, "can_manage_public_all_policy": false, "public_all_members_enabled": false, "chain_tag": "ethereum", "trust_min": null, "links": { "self": "/groups/holders-bored-apes" }, "card": { "...": "community Card view-model per §3.2.4 — card_kind: \"community\", community_dossier populated" } }
  ```
  - `card` (v1.27, additive): the full §3.2.4 community Card, composed from the same data already resolved for the flat fields (zero extra queries). New consumers render `group.card` via the CardFactory; the flat fields remain during the migration window.
  - `type` ∈ `nft|local|system|user`; `privacy` ∈ `open|closed|secret`. `verification`/`image_url`/`collection_stats` are NFT-type only (else `null`). `activity` is the §4.7.1 heat tile (defaults `posts_last_7d: 0, heat: "cold", heat_label: "Quiet"`). `viewer_membership`: `null` (anon), `{is_member: false, joined_at: null}` (authed non-member), `{is_member: true, joined_at}` (member). `permissions.*` each `{allowed, unlock_hint, reason_code}` (render `unlock_hint` verbatim per §A2/§N7); `can_join.reason_code` ∈ `auth_required|already_member|not_eligible|trust_threshold|requires_approval|invite_only`; `can_leave.reason_code` ∈ `auth_required|not_member|owner_cannot_leave`; `can_read_feed.allowed` always `true` for a built view-model (per-post visibility teaser, v1.24 — secret-non-member never gets a view-model). `feed_visible` mirrors `can_read_feed.allowed`. `members_visible` true for open groups, else only for active members. `can_use_public_all` (bool) — whether **this viewer** may set `visibility=public_all` when posting here (drives the composer's "PUBLIC" option; `false` for anon/non-members and members not authorized to syndicate — see §4.14). `can_manage_public_all_policy` (bool) — whether **this viewer** may change the group-wide ordinary-member opt-in (drives the owner control; `true` for the group **owner / manager** or a **site admin (`manage_options`)**, `false` for moderators, ordinary members, and anon). Distinct from `can_use_public_all`: a moderator may *use* `public_all` on their own post but may **not** manage the group policy. `public_all_members_enabled` (bool) — the group's ordinary-member opt-in state. **Minimum exposure:** it reflects the real value only for viewers who can manage the policy (`can_manage_public_all_policy = true`); every other viewer sees `false` (the raw config is not disclosed to ordinary/anon viewers — they rely on `can_use_public_all`). Toggled via `POST /me/groups/:id/post-policy` (§4.7.3). `chain_tag` slug or `null`. `trust_min` ∈ `25|50|75|null`.
- **Errors:** `bcc_invalid_request` 400 (empty slug) · `bcc_not_found` 404 (unresolved, OR secret + viewer not a member — §S, indistinguishable from missing)
- **Cache:** anon → `public, max-age=60`; authed → `private, no-store`.
- **Mapping:** `GroupsDetailEndpoint::show` (route `GroupsDetailEndpoint.php:52`) → `GroupsService::getGroup` (→ `PeepSoGroupRepository::findGroupBySlug` → `GroupContextResolver` → secret-gate → `GroupActivityHeatService` → `findUserMemberships` → NFT enrichment → `buildPermissions` → `ChainRepository::resolveSlugsForGroups`).

#### `GET /bcc/v1/groups/:id/feed` (§4.7.6)

Group-scoped, cursor-paginated activity stream. Membership-gated in the handler; same single-brain composition as `/feed`.

- **Auth:** Anonymous OR Bearer. Members get the **full** feed (incl. `members_only`); non-members of nft/closed/open (non-secret) groups and anonymous viewers get a **public-only teaser** (`public_group` + `public_all` only; absent-meta posts excluded), enforced by an SQL INNER JOIN.
- **Path:** `id` (int, required — group id, not slug).
- **Query:** `cursor` (optional, opaque per §1.5) · `limit` (optional, default 20, min 1, max 50)
- **Response 200:** `CursorEnvelope<FeedItem>` — identical shape to `/feed`: `data = { items: FeedItem[] (§3.3), pagination: { next_cursor, has_more } }`.
- **Errors:** `bcc_invalid_request` 400 (invalid/zero id) · `bcc_not_found` 404 (group missing OR secret + non-member). No 403 in v1.24 (non-members get the teaser rather than a refusal).
- **Cache:** `private, no-store`; `Vary: Authorization, Cookie`.
- **Mapping:** `GroupsDetailEndpoint::feed` (route `GroupsDetailEndpoint.php:69`) → `GroupsService::gateGroupFeed` → `FeedRankingService::getGroupFeed($viewerId, $groupId, $cursor, $limit, $publicOnly)`. FE `useGroupFeed`.

#### `GET /bcc/v1/groups/:id/members` (§4.7.7)

Paginated group roster. Offset-paginated (stable-ordered by role + joined_at).

- **Auth:** Anonymous OR Bearer. `open` + anyone → roster; `closed`/`secret` + member → roster; `closed` + non-member → 403; `secret` + non-member → 404. NFT-gated rosters are gated to members.
- **Path:** `id` (int, required). **Query:** `offset` (default 0, min 0) · `limit` (default 24, min 1, max 100)
- **Response 200:**
  ```json
  { "items": [ { "...": "MemberSummary (§3.1)", "role": "owner", "role_label": "OWNER", "joined_at": "2026-01-12T00:00:00Z" } ], "pagination": { "offset": 0, "limit": 24, "total": 87, "has_more": true } }
  ```
  Each item is the shared `MemberSummary` (§3.1, via `UserViewService::getSummary` + `MemberSummaryPrefetcher`) merged with `role` ∈ `owner|moderator|member`, `role_label` (server-rendered §A2, filterable via `bcc_group_role_labels`), `joined_at` (ISO 8601 UTC or `null`). Rows for deleted users are skipped (so `items.length` can trail `total`).
- **Errors:** `bcc_invalid_request` 400 · `bcc_not_found` 404 (missing or secret-non-member) · `bcc_permission_denied` 403 (closed/secret non-member)
- **Cache:** anon (open) → `public, max-age=300`; authed → `private, no-store` + `Vary`; denials → `private, no-store`.
- **Mapping:** `GroupMembersEndpoint::list` (route `GroupMembersEndpoint.php:58`) → `GroupMembersService::listMembers` (shares `GroupsService::resolveGroupAccess` with §4.7.5/§4.7.6).

#### `POST /bcc/v1/me/groups` (§4.7.3 — create, V1.6)

Create a plain (non-gated, non-Local) PeepSo group owned by the viewer. (Join/leave siblings are at §4.7.3.)

- **Auth:** Bearer **required**. Anonymous → `bcc_unauthorized 401`.
- **Body (JSON):** `name` (**required**, 3–100 chars) · `description` (optional, ≤ 2000, `wp_kses_post`) · `privacy` (optional, default `open`) ∈ `open|closed|secret|trust` (→ PeepSo 0/1/2; `trust` = open + a BCC reputation gate at join) · `trust_min` (**required when `privacy=trust`**) ∈ `25|50|75` · `chain` (**required**) — chain-tag slug, immutable after creation, validated via `ChainRepository::getBySlug`.
- **Response 201:** `{ "group_id": 5120, "slug": "evm-builders", "name": "EVM Builders", "privacy": "trust", "chain_tag": "ethereum", "trust_min": 50 }` (`trust_min` echoes the threshold for `privacy=trust`, else `null`).
- **Errors:** `bcc_unauthorized` 401 · `bcc_forbidden` 403 (account suspended — "Your account is suspended.", admin bypass off) · `bcc_invalid_request` 400 (name length, description > 2000, missing/unknown chain, `trust` without valid `trust_min`) · `bcc_rate_limited` 429 (5/hour/user) · `bcc_internal_error` 500
- **Rate limit:** 5/hour/user (`group_create:{user_id}`)
- **Cache:** `no-store`.
- **Mapping:** `MyGroupsEndpoint::postCreate` (route `MyGroupsEndpoint.php:75`) → `PeepSoGroupWriter::createPlainGroup`; emits `group_create` audit. FE `my-groups-endpoints.ts:createPlainGroup`.

#### `GET /bcc/v1/locals/:slug`

Single Local detail (auth-optional). Same item shape as the `/locals` directory rows. The `/locals/[slug]` page also composes `GET /groups/:slug` + `GET /groups/:id/feed` (§4.7 composition note).

- **Auth:** Anonymous OR Bearer.
- **Path:** `slug` — `[a-z0-9][a-z0-9-]{0,99}`, required.
- **Response 200:**
  ```json
  { "id": 342, "slug": "cosmos-base-fan", "name": "Local 342 Cosmos Base Fan", "number": 342, "chain": "cosmos", "member_count": 412, "viewer_membership": { "is_member": true, "is_primary": true, "joined_at": "2026-01-12T00:00:00Z" }, "links": { "self": "/locals/cosmos-base-fan" } }
  ```
  `number`/`chain` parsed from the title (§E3 `Local NNN`), `null` when absent. `viewer_membership` carries `is_primary` (Locals own the primary pointer; unlike §4.7.5).
- **Errors:** `bcc_invalid_request` 400 (empty slug) · `bcc_not_found` 404
- **Cache:** anon → `public, max-age=300`; authed → `private, no-store`.
- **Mapping:** `LocalsEndpoint::showBySlug` (route `LocalsEndpoint.php:82`) → `LocalsService::getLocal` (`PeepSoGroupRepository::findOneBySlug` + `findUserMemberships` + `bcc_primary_local_group_id`).

#### `GET /bcc/v1/entities/:target_kind/:target_id/reviews`

Reviews-tab data plane for entity profiles AND member profiles (v1.48) — reviews filed **against** this target (by `votes.page_id`). Page-paginated. Auth-optional (public trust signal).

- **Auth:** Anonymous OR Bearer (identical row set).
- **Path:** `target_kind` ∈ `validator_card|project_card|creator_card|user_profile` · `target_id` (int > 0). For entity kinds, `target_id` is the page's `wp_post.ID`. For `user_profile` (v1.48), `target_id` is the **raw user id**, translated server-side to the member's self-page (`MemberSelfPageService::selfPageId`) — the same bridge the write path (`POST /posts kind=review target_kind=user_profile`) uses. Received member reviews are **public** and deliberately NOT governed by the subject's `reviews_hidden` privacy flag (that flag governs only the written list on `/users/:handle/reviews`) — locked 2026-07-22.
- **Query:** `page` (default 1) · `per_page` (default 20, max 50)
- **Response 200:**
  ```json
  { "items": [ { "id": 88, "grade": "A", "text": "Reliable validator.", "posted_at_label": "3 days ago", "author": { "...": "MemberSummary (§3.1)" } } ], "pagination": { "page": 1, "per_page": 20, "total": 12, "total_pages": 1 } }
  ```
  `grade` ∈ `A|B|C`. `author` is the full §3.1 `MemberSummary` (hydrated like `/members`; authorless rows skipped). An unknown `target_id` returns `total: 0` (no 404).
- **Errors:** `bcc_invalid_request` 400 (bad `target_kind` or `target_id ≤ 0`)
- **Cache:** anon → `public, max-age=30`; authed → `private, max-age=30` + `Vary`; invalid → `private, no-store`.
- **Mapping:** `CardReviewsEndpoint::list` (route `CardReviewsEndpoint.php:65`) → `CardReviewsService::getReviews`. FE `card-tabs-endpoints.ts:getCardReviews`.

#### `GET /bcc/v1/entities/:target_kind/:target_id/watchers`

Watchers-tab data plane — people watching this entity. Offset-paginated to match `/users/:handle/followers`. "Watchers of card X" routes through the PeepSo follower graph: card → `wp_post` → `post_author` (owner) → owner's followers. Auth-optional (the owner's `watching_hidden` flag does NOT propagate to entity pages).

- **Auth:** Anonymous OR Bearer.
- **Path:** `target_kind` ∈ `validator_card|project_card|creator_card` · `target_id` (int > 0).
- **Query:** `offset` (default 0) · `limit` (default 24, max 100)
- **Response 200:** `{ items: Card[], pagination: { offset, limit, total, has_more } }`, each `Card` the **full member Card view-model (§3.2, with `member_dossier`)** (hydrated via `CardViewService::getMemberCardForList`). Unclaimed cards (no resolvable `post_author`) return an empty page.
- **Errors:** `bcc_invalid_request` 400
- **Cache:** anon → `public, max-age=30`; authed → `private, max-age=30` + `Vary`; invalid → `private, no-store`.
- **Mapping:** `CardWatchersEndpoint::list` (route `CardWatchersEndpoint.php:46`) → `CardWatchersService::listWatchers`. FE `card-tabs-endpoints.ts:getCardWatchers`.

#### `GET /bcc/v1/creators/:slug/gallery`

Paginated NFT collection gallery for a creator page (`/c/[slug]`). **Supersedes the §8 "deferred" note** — this endpoint is fully wired in V1, registered, and consumed by `useCreatorGallery`. Stale-while-revalidate: reads the cached/paginated rows immediately and, if the visible page has any expired row (or the creator is never-indexed but has wallets), dispatches one rate-limited (5-min per-post transient) async refresh.

- **Auth:** Anonymous OR Bearer (response currently viewer-agnostic).
- **Path:** `slug` — `[A-Za-z0-9][A-Za-z0-9_-]{0,99}`, resolves to a `peepso-page` with `_bcc_page_type = nft` (numeric slug → ID lookup).
- **Query:** `page` (default 1, **max 20** — over → 400) · `per_page` (default 12, max 50, over clamps) · `sort` ∈ `total_volume`(default)`|floor_price|unique_holders|total_supply|collection_name`
- **Response 200:**
  ```json
  { "items": [ { "id": 88, "contract_address": "0xbc4c…f13d", "chain_slug": "ethereum", "chain_name": "Ethereum", "name": "Bored Apes", "image_url": "https://…", "total_supply": 10000, "floor_price_label": "12.34 ETH", "total_volume_label": "987.7K ETH volume", "unique_holders_label": "5,421 holders", "explorer_url": "https://etherscan.io/address/0xbc4c…f13d" } ], "pagination": { "page": 1, "per_page": 12, "total": 7, "total_pages": 1, "has_more": false }, "is_stale": false, "last_refreshed_at": "2026-06-09T18:02:11Z" }
  ```
  Per §A2, floor/volume/holders are **pre-formatted display strings** (render verbatim, no `Intl.NumberFormat` in TS); each `null` when missing/zero. `name` falls back to a truncated contract; `image_url`/`explorer_url`/`total_supply` nullable. `is_stale` true when the visible page has an expired row (refresh already dispatched). `last_refreshed_at` is the newest `fetched_at` across the page (`null` when empty). V1 fetcher coverage: ETH + Solana; other chains return empty arrays ("Coming soon").
- **Errors:** `bcc_invalid_request` 400 (empty slug or `page > 20`) · `bcc_not_found` 404 (slug not an `nft` page)
- **Cache:** `Cache-Control: public, max-age=30, stale-while-revalidate=60`.
- **Mapping:** `CreatorGalleryEndpoint::handle` (route `CreatorGalleryEndpoint.php:80`) → `CollectionService::getForProject` → per-row `shapeRow` (§A2) → staleness sweep → `maybeDispatchRefresh`. FE `creator-gallery-endpoints.ts:getCreatorGallery`.

### 4.30 Disputes (file / vote / panel) & received endorsements

The §D5 vote-dispute panel-adjudication system (owner files a dispute against a downvote → a peer panel votes accept/reject → the verdict resolves async), plus the endorsement read direction. Dispute endpoints live in `DisputeController`; the endorsement reads live in `UserEndorsementsEndpoint`.

> **Envelope note (load-bearing asymmetry — do not "fix"):** dispute endpoints emit the canonical `{ data, _meta }` envelope via `ApiResponse::ok`/`error`. The two `/endorsements/mine*` reads return **unenveloped, top-level JSON** via raw `rest_ensure_response([...])` (they predate the helper; shape matches the §4.22 public read), and their error bodies are non-standard bare `{message}`. Documented reality, not the §1.4 target. The admin-only `POST /disputes/:id/resolve` (force-resolve) and `GET /disputes/health` are **internal** and intentionally undocumented (allowlisted).

#### `POST /bcc/v1/disputes`

File a dispute against a single downvote on a page the caller owns. The server atomically selects `BCC_DISPUTES_PANEL_SIZE` qualified panelists (§D5 affinity overlay + outsider quota) and queues per-panelist notifications.

- **Auth:** Bearer **required** (`is_user_logged_in() && Permissions::is_not_suspended`). Page-ownership enforced in-handler.
- **Body:** `vote_id` (**required**, int ≥ 1) · `reason` (**required**, `sandbox`d via `sanitize_textarea_field`; `BCC_DISPUTES_MIN_REASON_LENGTH`–`MAX_REASON_LENGTH` non-whitespace chars) · `evidence_url` (optional, ≤ 2083, `esc_url_raw`)
- **Response 200:** `{ "data": { "dispute_id": 41, "panelists": 5, "message": "Dispute submitted. 5 panelists have been notified." }, "_meta": {...} }`
- **Errors (bare codes, NOT `bcc_`-prefixed):** `dispute_subsystem_unhealthy` 503 (UNIQUE constraint missing) · `rate_limited` 429 · `vote_not_found` 404 · `not_page_owner` 403 · `cannot_self_dispute` 400 · `upvote_not_disputable` 400 · `already_disputed` 409 · `insufficient_panelists` 503 · `dispute_limit_reached` 429 · `reporter_limit_reached` 429 · `vote_no_longer_active` 410 · `db_transient` 503 · `db_error` 500
- **Rate limit:** 1 / 60s / user (`Throttle::allow('dispute_submit', 1, 60)`)
- **Cache:** `no-store`.
- **Mapping:** `DisputeController::open` (route `DisputeController.php:37`) → ownership `Permissions::owns_page`, vote context `TrustReadService::getVoteById`, `selectPanelists` → `rankPanelistsByAffinity`, atomic `DisputeRepository::createDisputeWithPanel`, async `DisputeNotificationService::enqueueAsync`. FE `disputes-endpoints.ts:openDispute`.

#### `GET /bcc/v1/disputes/votes/:page_id`

List the active votes on a page so the owner can pick which downvote to dispute. Offset-paginated via `X-WP-Total` / `X-WP-TotalPages` headers.

- **Auth:** Bearer **required**. Visibility gated in-handler: page owner **or** `manage_options` only.
- **Path:** `page_id` (int, `(?P<page_id>\d+)`). **Query:** `page` (default 1) · `per_page` (default 50, max 100)
- **Response 200:** `data` is a flat array (paging in headers):
  ```json
  { "data": [ { "id": 9912, "voter_name": "Dale R.", "vote_type": "downvote", "weight": 1.25, "reason": "", "date": "2026-05-15 14:23:47", "already_disputed": false } ], "_meta": {...} }
  ```
  `vote_type` ∈ `upvote|downvote`; `weight` 2dp; `date` UTC datetime or `null`; `already_disputed` from `DisputeRepository::getDisputedVoteIds`.
- **Errors:** `forbidden` 403 (not owner/admin) · `trust_service_unavailable` 503
- **Cache:** `no-store`. **Headers:** `X-WP-Total`, `X-WP-TotalPages`.
- **Mapping:** `DisputeController::getDisputableVotes` (route `DisputeController.php:50`) → `TrustReadService::countActiveVotesForPage` + `getActiveVotesForPage`. FE `disputes-endpoints.ts:getDisputableVotes`.

#### `GET /bcc/v1/disputes/mine`

The disputes the caller has filed (page-owner view). Offset-paginated via headers; optional `page_id` filter.

- **Auth:** Bearer **required**.
- **Query:** `page` (default 1) · `per_page` (default 20, max 100) · `page_id` (optional — caller must own that page or be admin, else 403; closes a probe vector)
- **Response 200:** `data` is a flat array of the shared `formatDispute` shape:
  ```json
  { "data": [ { "id": 41, "vote_id": 9912, "page_id": 5521, "page_title": "Stakecito", "voter_name": "Dale R.", "reporter_name": "Owner", "reason": "…", "evidence_url": "", "status": "reviewing", "accepts": 1, "rejects": 0, "panel_size": 5, "my_decision": null, "created_at": "…", "resolved_at": null } ], "_meta": {...} }
  ```
  `status` ∈ `reviewing|accepted|rejected|dismissed|timeout_no_quorum`. `my_decision` is **always `null`** here (the reporter is never a panelist on their own dispute).
- **Errors:** `forbidden` 403 (`page_id` for a page the caller doesn't own / isn't admin)
- **Cache:** `no-store`. **Headers:** `X-WP-Total`, `X-WP-TotalPages`.
- **Mapping:** `DisputeController::getMyDisputes` (route `DisputeController.php:57`) → `DisputeRepository::countByReporter` + `getByReporterPaginated`. FE `disputes-endpoints.ts:getMyDisputes`.

#### `GET /bcc/v1/disputes/panel`

The caller's panelist queue. Offset-paginated via headers. The **independent-deliberation privacy mask** is applied per row: for a panelist who hasn't finished, `reporter_name`/`accepts`/`rejects` are nulled and a terminal `status` is rewritten to `"closed"` so the tally can't be inferred.

- **Auth:** Bearer **required**.
- **Query:** `page` (default 1) · `per_page` (default 20, max 100)
- **Response 200:** `data` is a flat array of the masked `formatDispute` shape; `my_decision` ∈ `accept|reject|null` is set on these rows. FE MUST NOT treat `0`/null `accepts` or empty `reporter_name` as ground truth.
- **Errors:** none beyond auth.
- **Cache:** `no-store`. **Headers:** `X-WP-Total`, `X-WP-TotalPages`.
- **Mapping:** `DisputeController::getPanelQueue` (route `DisputeController.php:64`) — opportunistic self-heal `DisputeScheduler::emergencyResolveIfStale()` runs first; `DisputeRepository::countPanelQueueForUser` + `getPanelQueueForUser`. FE `disputes-endpoints.ts:getPanelQueue`.

#### `POST /bcc/v1/disputes/:id/vote`

Cast the caller's panel vote (accept/reject) on a dispute they're assigned to. The deciding vote enqueues async resolution; **running tallies are intentionally omitted** from the response.

- **Auth:** Bearer **required**. Panelist assignment enforced in-handler.
- **Path:** `id` (int, `(?P<id>\d+)`). **Body:** `decision` (**required**, ∈ `accept|reject`) · `note` (optional, ≤ 500, `sanitize_textarea_field`)
- **Response 200:**
  ```json
  { "data": { "message": "Vote recorded.", "decision": "accept", "participation": { "credited": true, "reason": null, "credited_today": 3, "credited_lifetime": 27 } }, "_meta": {...} }
  ```
  `participation.reason` ∈ `daily_cap|total_cap|suspended|fraud_flag|already_recorded|service_unavailable|null`; `credited_*` are post-vote counts.
- **Errors:** `rate_limited` 429 (10s) · `invalid_decision` 400 · `not_assigned` 403 · `already_voted` 409 · `dispute_closed` 410 · plus `{code, message, http}` surfaced by the atomic vote tx
- **Rate limit:** 1 / 10s / user (`Throttle::allow('panel_vote', 1, 10)`)
- **Cache:** `no-store`.
- **Mapping:** `DisputeController::castPanelVote` (route `DisputeController.php:71`) → assignment check `getPanelAssignment`, atomic `castPanelVoteAtomic`, verdict `computeVerdict`, deciding-vote async `bcc_disputes_async_resolve` enqueue, participation `DisputeParticipationService::recordParticipation` (outside the vote tx). FE `disputes-endpoints.ts:castPanelVote`.

#### `GET /bcc/v1/disputes/participation/me`

The caller's own §D5 panel-vote participation counters + caps. Powers the `/panel` header. Never throws; returns zeros for a user who has never sat on a panel.

- **Auth:** Bearer **required**.
- **Response 200:**
  ```json
  { "data": { "credited_today": 3, "credited_lifetime": 27, "correct_count": 19, "earned_today": 0.06, "earned_lifetime": 0.41, "caps": { "daily_trust": 1.0, "lifetime_trust": 10.0, "min_for_accuracy": 5, "base_weight": 0.01, "accuracy_weight": 0.02 } }, "_meta": {...} }
  ```
  `credited_*`/`correct_count` are row counts; `earned_*` are clamped trust-point contributions (4dp). `caps.*` mirror the `BCC_DISPUTE_PARTICIPATION_*` server constants so the FE never hardcodes them.
- **Errors:** none beyond auth (read-only, total-failure-safe).
- **Cache:** `no-store`.
- **Mapping:** `DisputeController::getMyParticipation` (route `DisputeController.php:115`) → `DisputeParticipationService::getStatus`. FE `disputes-endpoints.ts:getMyParticipation`.

#### `GET /bcc/v1/endorsements/mine`

The endorsements the caller authored, hydrated to the shared §J.6 row shape (the same `given`-direction read as §4.22's per-handle public endpoint — single source per §A4).

> **Direction note:** despite the "received" framing, the underlying read is `EndorsementService::getUserEndorsements` = endorsements the caller *authored*. There is no separate "endorsements I received" query behind this route. The genuine received-by-a-user roster is the attestation roster (`GET /entities/user_profile/:id/attestations`, §4.20/§J.6).
>
> **v1.28 source note:** attestation-backed — same repoint as §4.22 (active `vouch` attestations authored by the caller; legacy table dropped). Response shape, the UNENVELOPED root, and the `/mine/stats` sibling are all byte-stable.

- **Auth:** Bearer **required** (`is_user_logged_in() && Permissions::is_not_suspended()`).
- **Query:** `limit` (optional, default 20, min 1, max 50)
- **Response 200 (UNENVELOPED — `{items,total}` at the document root, no `data`/`_meta`):**
  ```json
  { "items": [ { "id": 142, "page_id": 4521, "page_title": "…", "page_url": "https://…", "avatar_url": "https://…", "trust_score": 67, "tier": "trusted", "weight": 1.25, "context": "general", "reason": "…|null", "created_at": "…" } ], "total": 1 }
  ```
  Identical row shape to §4.22 (`EndorsementService::hydrateEndorsementItems`). `trust_score` `null` when the page has no read-model row; `tier` `unavailable` likewise. `total` = count of items returned (capped by `limit`), NOT a paginated grand total.
- **Errors:** bare `{ "message": "Too many requests." }` **429** (non-standard); anonymous/suspended → WP `rest_forbidden` 401/403.
- **Rate limit:** 30 / 60s, bucket `endorsements_mine`.
- **Cache:** not set by the handler (treat as `no-store`).
- **Mapping:** `UserEndorsementsEndpoint::getMine` (route `UserEndorsementsEndpoint.php:35`) → `EndorsementService::getUserEndorsements`. FE shares the §4.22 `UserEndorsementsResponse` type.

#### `GET /bcc/v1/endorsements/mine/stats`

Aggregate stats over the caller's authored endorsements. Powers the endorsements-tab summary strip.

- **Auth:** Bearer **required** (`is_user_logged_in() && Permissions::is_not_suspended()`).
- **Response 200 (UNENVELOPED — raw service array at the document root):**
  ```json
  { "user_id": 412, "total_endorsements_given": 27, "unique_pages_endorsed": 5, "recent_endorsements": [ /* up to 5 raw EndorsementWithPage rows — opaque */ ], "endorsement_weight_avg": 1.18, "last_endorsement": "2026-05-15 14:23:47" }
  ```
  `unique_pages_endorsed` counts distinct pages within the 10 most-recent only (not lifetime distinct). `recent_endorsements` are raw repository rows (NOT the hydrated §J.6 shape — treat as opaque). `endorsement_weight_avg` floors to `1.0` when no positive average. `last_endorsement` `null` when none.
- **Errors:** bare `{ "message": "Too many requests." }` **429**; anonymous/suspended → WP forbidden.
- **Rate limit:** 30 / 60s, bucket `endorsements_mine_stats`.
- **Cache:** not set by the handler (treat as `no-store`).
- **Mapping:** `UserEndorsementsEndpoint::getMineStats` (route `UserEndorsementsEndpoint.php:51`) → `EndorsementService::getUserEndorsementStats`.

### 4.31 Collection stances (waitlist / spam) — v1.32

The airdrop-proof demand layer. Passive holdings are forgeable (a scammer can airdrop a token into every linked wallet), so community demand is measured by EXPLICIT per-collection user declarations instead: `waitlist` ("activate this community and count me in") and `spam` ("this is airdropped junk"). One stance per (user, collection), switchable, holder-gated server-side. Waitlist counts rank the operator's Verify Collections queue; spam tallies soft-hide a collection from user-facing surfaces at the threshold (`BCC_COLLECTION_SPAM_SOFT_HIDE`, default 3) — the hard kill stays operator-only (a DENY rule on the contract via the admin Hide button, which also blocks rediscovery). When a waitlisted collection's community provisions, waitlisted users receive one `bcc_holder_community_live` bell (+ push), stamped via `notified_at` so re-provisioning can't re-notify.

Endpoints live in `CollectionStancesEndpoint`. The join action is NOT here — the panel's "Join this community" button drives the existing §4.7.1 `POST /me/holder-groups/:id/join`.

#### `GET /bcc/v1/me/collection-stances/panel`

Every collection the viewer's linked wallets hold, with the state that picks the button pair. Sources mirror discovery (EVM/SOL holdings index; Cosmos Hub marketplace rollup, 6h-cached) — no fresh RPC walks. Collections the operator hid (DENY rule) or the community soft-hid never appear (a viewer who flagged a soft-hidden collection still sees their own row so they can retract).

- **Auth:** required.
- **Response 200 data shape:**
  ```json
  { "items": [{
      "chain_id": 8, "chain_slug": "cosmos",
      "contract_address": "cosmos1…", "name": "ATLAS", "image_url": "https://…",
      "collection_verified": false,
      "state": "waitlist",
      "group_id": null,
      "waitlist_count": 1,
      "viewer_stance": "waitlist"
  }] }
  ```
  `state` ∈ {`live`, `waitlist`} — `live` means verified + community provisioned (`group_id` non-null → feed it to §4.7.1 join). `waitlist_count` is public social proof. Rows sort live-first, then by waitlist momentum; capped at 60.
- **Errors:** `bcc_unauthorized` 401 · `bcc_rate_limited` 429.
- **Rate limit:** 20 / 60s / user (`collection_stance_panel`).
- **Cache:** `no-store` (viewer-scoped). React Query `staleTime: 60_000`.
- **Mapping:** `CollectionStancesEndpoint::getPanel` → `CollectionStanceService::panelForUser`. FE `collection-stances-endpoints.ts:getCollectionStancePanel` → `CollectionStancePanel` (post-wallet-link in settings + onboarding CollectionsStep).

#### `POST /bcc/v1/me/collection-stances`

Set or switch the viewer's stance. Holder-gated: primary evidence is the same sources the panel rendered from; falls back to a live `ownsAny` check for holdings fresher than the caches. Switching stance resets the go-live-notified stamp.

- **Auth:** required.
- **Body:** `chain_id` (**required**, int) · `contract_address` (**required**) · `stance` (**required**, `waitlist` | `spam`)
- **Response 200:** `{ "chain_id": 8, "contract_address": "cosmos1…", "stance": "waitlist" }`
- **Errors:** `bcc_invalid_request` 400 · `bcc_nft_not_owned` 403 (linked wallets don't hold it) · `bcc_unavailable` 503 (holdings unverifiable — retryable) · `bcc_rate_limited` 429 · `bcc_unauthorized` 401.
- **Rate limit:** 10 / 60s / user (`collection_stance_set`, shared with DELETE).
- **Mapping:** `CollectionStancesEndpoint::postStance` → `CollectionStanceService::setStance` → `CollectionSignalRepository::setStance`.

#### `DELETE /bcc/v1/me/collection-stances`

Retract the viewer's stance (back to neutral). NOT holder-gated — you can always withdraw your own testimony (e.g. after selling).

- **Auth:** required.
- **Body:** `chain_id` (**required**) · `contract_address` (**required**)
- **Response 200:** `{ "chain_id": 8, "contract_address": "cosmos1…", "stance": null }`
- **Errors:** `bcc_invalid_request` 400 · `bcc_rate_limited` 429 · `bcc_unauthorized` 401.
- **Rate limit:** shared `collection_stance_set` bucket (10 / 60s / user).
- **Mapping:** `CollectionStancesEndpoint::deleteStance` → `CollectionStanceService::clearStance`.

## 5. Encoded rules — quick reference

### 5.1 §N7 — gated actions always visible

Every gated action (Pull, Review, Dispute, Vouch, Stand-behind, etc.) appears in `permissions.*` regardless of whether the viewer can use it. `allowed` is the boolean; `unlock_hint` is the plain-English path forward. Frontend never hides gated actions a viewer could eventually unlock; it renders them disabled with the hint.

Exception: structurally impossible actions (e.g., follow-yourself) have `allowed: false, unlock_hint: null` — frontend hides those.

### 5.2 §O5 + §D2 — feature gating with permission stacking

When both an O5 level gate and a D2 reputation/wallet gate apply, the server resolves both checks and returns one combined `allowed` boolean per `permissions.can_X`. The client never combines gates. The `unlock_hint` describes whichever gate is closer to resolution, with priority: nearest threshold first (e.g., if user is Level 1 with neutral rep, the hint is "Reach Level 2…", since level is the closer unlock).

Retroactive: when a user crosses a threshold, the unlock applies to all past content (§O5).

### 5.3 §O4 + §O4.1 — social_proof composition

- Server computes `social_proof.headline` from the viewer's network filtered by trust weight.
- Elite/trusted contacts surface by name; neutrals count toward "+N others"; caution/risky are excluded.
- Hidden-watchlist users contribute to count, never to names.
- Shadow-limited authors are excluded from social_proof, F1 ranking inputs, and watch-batch feed visibility for any viewer (§K1 + §O4.1).
- `social_proof: null` when the viewer has zero network connection.

### 5.4 §C3 — watch batching frozen

Watches accumulate into a batch while the user keeps watching. The batch closes after exactly **10 minutes of watch inactivity**; at close, the server emits one `watch_batch` FeedItem (legacy kind name `watch_batch` during the §1.1.1 deprecation window). The post body shows up to **3 top cards** + "+N more" (`more_count = card_count - 3`). Once posted, the FeedItem is **frozen**: subsequent unfollows do not edit or remove the post. Watchlist UI updates immediately on unfollow; feed does not.

Server contract: `watch_batch.frozen` is always `true` in V1. The field exists for forward compatibility (V2 may introduce live batches).

### 5.5 `unlock_hint` usage

One concept, two surfaces:

- `permissions.can_X.unlock_hint` (Card, User, FeedItem) — describes how to unlock a per-resource action.
- `feature_access.<name>.unlock_hint` (User own profile) — describes how to unlock a system-wide feature.

Both source from the same server-side resolver. The frontend reads either; never composes its own hint text.

### 5.6 §A2 / §L5 — no business logic on frontend

The frontend MUST NOT compute, derive, or transform any of:
- `trust_score`, `reputation_tier`, `card_tier`, `tier_label`
- `rank`, `rank_label`
- `is_in_good_standing`
- `permissions.*` (any allowed boolean or unlock_hint)
- `flags`
- `social_proof.headline` (already composed)
- `living.comparison.headline`
- `progression.next_rank_thresholds[].current/required` (server pre-computed)
- `feature_access.*`
- Any tier-color, badge label, or category derived from reputation

The frontend MAY compute:
- Display formatting that's purely cosmetic (e.g., highlighting a search match in already-formatted text)
- Animation state, hover state, focus state
- Pagination cursor management
- Local form state before submit

If a UI need arises that requires deriving one of the above, the contract changes — not the client.

---

## 6. Field-to-source mapping

For each view-model field, the table below names the existing BCC system that owns it. New fields are marked `(new V1)` and described.

### 6.1 User view-model

| Field | Source |
|---|---|
| `id` | `wp_users.ID` |
| `handle` | `wp_usermeta.bcc_handle` (new V1, per §B6) |
| `display_name` | `wp_users.display_name` |
| `avatar_url` | PeepSo profile photo + `wp_usermeta` fallback. **Always returned as absolute URL** (WP host or CDN origin); never relative |
| `joined_at` | `wp_users.user_registered` |
| `trust_score` | `bcc-trust` `ReputationCalculatorService` (§A4) |
| `reputation_tier` | `bcc-trust` `ReputationCalculatorService` |
| `reputation_tier_label` | Server-side mapping `reputation_tier → honest label` (`ReputationTierMap::TIER_LABEL`; elite → "Proven") — the member trust chip, distinct from `card_tier` rarity |
| `card_tier`, `tier_label` | Server-side mapping `reputation_tier → card_tier` (§C1), in `bcc-trust` (entity-card rarity) |
| `rank`, `rank_label`, `current_rank_label` | Auto-derived from the feature-access **level** (`RankService::rankForLevel`: New→Apprentice, Active→Journeyman, Veteran→Master). Fully level-derived — no conferred-Role rows (§4.8) |
| `is_in_good_standing` | `bcc-trust` derived from tier ≥ neutral AND no moderation flags (§E1) |
| `flags` | suspension state + `wp_usermeta` moderation flags (`bcc_shadow_limited`/`bcc_hidden`/`bcc_under_review`) via `UserViewService::resolveFlags` — NOT the retired `bcc_trust_flags` vote-flag table |
| `bio` | PeepSo profile description |
| `primary_local`, `locals` | `peepso_group_members` (PeepSo's membership ledger — single graph rule) joined with `wp_usermeta.bcc_primary_local_group_id` for the primary pointer; no dedicated BCC table |
| `wallets` | `bcc_wallet_links` (existing) |
| `counts.followers/following` | `peepso_user_followers` aggregates |
| `counts.watching_size` | `peepso_user_followers` filtered to BCC card kinds |
| `counts.reviews_written` | `bcc_trust_votes` aggregated |
| `counts.disputes_signed` | `bcc_disputes` reporters aggregated (`DisputeRepository::countByReporter(s)`) |
| `counts.solids_given/received` | `peepso_reactions` aggregated by reaction-type ID |
| `privacy.*` | `wp_usermeta.bcc_privacy_*` keys (new V1) |
| `living.streak_days` | `bcc-trust` streak service (new V1, derived from `peepso_activities`) |
| `living.today` | `peepso_activities` filtered to last 24h |
| `living.comparison` | `bcc-trust` ranking service (§A4) |
| `progression.*` | `bcc-trust` threshold service (new V1) reading `bcc_options` |
| `feature_access.*` | `bcc-trust` feature-access service (new V1, per §O5) |
| `ux_helpers.show_helpers` | `wp_usermeta.bcc_ui_familiar` (new V1, per §N5) |

### 6.2 Card view-model

| Field | Source |
|---|---|
| `id` | `peepso_pages.ID` |
| `card_kind` | `peepso_pages.peepso_page_type` mapped via `AbstractPageType` |
| `name`, `handle` | `peepso_pages.name` + slug |
| `chain` | `peepso_page` meta (existing AbstractPageType) |
| `trust_score`, `reputation_tier`, `card_tier`, `tier_label`, `is_in_good_standing`, `flags` | `bcc_page_read_model` (denormalized read model, §A4) |
| `rank_label` | `null` for V1 entity cards (members only have ranks); for member cards, auto-derived from the feature-access **level** (`RankService::rankForLevel`; §4.8) |
| `is_claimed` | `peepso_pages.claimed_by IS NOT NULL` (new V1 column, per §B5) |
| `claimed_by_handle` | JOIN to `wp_usermeta.bcc_handle` |
| `crest` | `peepso_pages` meta + AbstractPageType convention |
| `stats[]` | per kind — see §3.2.x; sourced from `bcc_page_read_model` + chain-specific fetchers |
| `social_proof` | new V1 server composer over `peepso_user_followers` + `peepso_reactions` + `bcc-trust` weighting |
| `permissions` | `bcc-trust` permission resolver (combines O5 + D2) |
| `links` | server-side route map (canonical paths) |
| `updated_at` | `bcc_page_read_model.updated_at` |

### 6.3 Feed item

| Field | Source |
|---|---|
| `id` | `feed_<peepso_activities.id>` |
| `post_kind` | `peepso_activities.module_id` mapped to BCC kinds |
| `posted_at` | `peepso_activities.act_time` |
| `scope_tags` | server-derived from kind + visibility |
| `author.*` | JOIN `wp_users` + `peepso_pages` (entity authors) + system flag |
| `body.*` | per-kind, sourced from kind-specific tables (`bcc_trust_votes` for reviews, `bcc_watch_meta` batches for watches, `bcc_onchain_signals` for signals, etc.) |
| `attached_card` | summary Card view-model |
| `reactions` | `peepso_reactions` aggregated |
| `social_proof` | new V1 (same composer as Card) |
| `permissions` | `bcc-trust` permission resolver |

### 6.4 Highlight strip

| Field | Source |
|---|---|
| `highlights[].slot` | new V1 — `BccHighlightsService` strict slot rule (§O2.1) |
| `highlights[].category` | new V1 — `negative` / `positive` / `external` |
| `highlights[].source_event_id` | foreign key into the source table per category (`bcc_onchain_signals`, `bcc_trust_score_events`, `peepso_activities`) |
| `highlights[].dismiss_kind` | new V1 — derived from category per §O2 |

### 6.5 New V1 schemas referenced in this contract

The following schema additions are **referenced but not implemented** by Phase 1 (per the user's "stop at contract" rule):

- `bcc_onchain_claims.recovery_pending` — new column on the EXISTING `bcc_onchain_claims` table for §B5 page-claim recovery (no new table; entity_type='page' for page claims, single-claim-wins via existing `ClaimRepository::createExclusiveClaim()` advisory lock)
- `bcc_photo_alts` — `(pho_id, owner_id, alt_text, updated_at)` (v1.5 §3.3.9 / §4.18; sidecar to `peepso_photos`, PK = `pho_id`, BCC-owned so PeepSo updates can't clobber)
- `bcc_watch_meta` — `(follow_id, tier_at_watch, batch_id, watched_at)` (§C2). Renamed from `bcc_pull_meta` (and `*_at_pull` columns → `*_at_watch`) via the data-preserving migration documented in §4.5.1.
- `wp_usermeta.bcc_handle` (§B6)
- `wp_usermeta.bcc_primary_local_group_id` — singleton pointer to the user's primary Local; membership ledger itself stays in PeepSo's `peepso_group_members` per the single graph rule (§E3)
- `wp_usermeta.bcc_ui_familiar` (§N5 — boolean for UI dual-label drop-off only)
- `wp_usermeta.bcc_first_review`, `bcc_first_vouch`, `bcc_first_dispute`, `bcc_first_local_join`, `bcc_first_wallet_link` (§O1.2)
- `wp_usermeta.bcc_privacy_*` keys (§K2)
- `wp_usermeta.bcc_highlights_dismissed_until` (§O2)

**Removed via 2026-04-27 anti-overengineering pass:**
- `bcc_user_locals` (duplicated `peepso_group_members`; replaced with `bcc_primary_local_group_id` user-meta key)
- `bcc_page_claims` (duplicated `bcc_onchain_claims`; merged via `entity_type='page'` + `recovery_pending` column)

**Removed via 2026-05-14 gate-pruning pass:**
- `wp_usermeta.bcc_floor_visits` — was the Level-2 unlock gate's second axis. Removed because visiting the Floor is passive consumption, not a deliberate signal. LEVEL_ACTIVE now requires only pulls ≥ 5. Existing rows in production are ignored; no migration needed.

**Removed via 2026-07-09 conferred-Role retirement pass (v1.36):**
- `bcc_user_ranks` — `(user_id, rank_key, awarded_by, awarded_at, revoked_at, revoke_reason)` — was reserved for conferred **Role** rows (Foreman). The conferral path was never built and the read placeholders (`foreman_insignia`, `is_admin_conferred`) were always `false`; the table is dropped. Rank is fully auto-derived from the feature-access **level** (§4.8), which never used this table.

---

## 7. Resolved contract decisions

All ten open items locked **2026-04-27**. Phase 1 implementation may begin.

1. **Avatar URLs** — **absolute URL, CDN-ready.** No relative paths. The server controls the host so a CDN origin can be swapped in without a contract change. See §1.7 (Asset / media URLs) and §6.1.
2. **Currency formatting** — **server-side abbreviated** with K/M/B suffixes (1 decimal max). Full numeric value always present in `raw`. Thresholds: `< 1k` full numerals · `≥ 1k` → K · `≥ 1M` → M · `≥ 1B` → B. See §2.8.
3. **Slug stability** — **immutable post-creation.** Admins rename via display name only. `links.self` URLs are stable forever. See §1.7 (Slugs).
4. **Member-card watch semantics** — **member watches count toward `watching_size`.** Member cards are first-class watchlist citizens; no separate `following_count` field. See §3.2 field rules.
5. **Anonymous `GET /me/highlights`** — **401 `bcc_unauthorized`.** Frontend hides the entire HighlightStrip for unauthenticated viewers. See §3.4.
6. **Cursor format** — **base64-encoded JSON of `(timestamp, id, rank_score_at_emit)`.** Preserves scroll order across re-ranks. Opaque to the client; never decode. See §1.5.
7. **Wallet `address_short`** — **`<first-6>…<last-4>` for all chains.** Sliced from the full chain-prefixed address. Server-only writer; frontend never re-truncates. See §1.7.
8. **Claim rate limit per page** — **30 attempts/day/page** (in addition to 3/hour/user and 10/day/IP). Prevents attempt flooding. See §4.6.
9. **Wallet-link celebration** — **first link only**, tracked via `wp_usermeta.bcc_first_wallet_link` (§O1.2 first-occurrence pattern). All subsequent links return `celebration: null`. See §4.1.
10. **`Card.updated_at`** — **read-model recompute timestamp** from `bcc_page_read_model.updated_at`. Use as cache validator only; do not surface in UI as "last edited". See §3.2 field rules and §6.2.

---

## 8. Out of scope (intentionally not in this contract)

Logged here so re-readers don't expect them in V1's contract.

- **Real-time signal SSE endpoint** (`GET /signals/live`) — Phase 3 deliverable.
- **NFT collection-pieces list endpoint** (`GET /collections/:id/pieces`) — still deferred (needs cursor pagination + collection-level filters). The per-piece DETAIL endpoint (`GET /nft-pieces/{chain}/{contract}/{tokenId}`) ships in V2 Phase 6 — see §4.17. (`GET /creators/:slug/gallery`, the per-creator gallery list, is now **live** — see §4.29.)
- **~~Watchlist summary endpoint~~ (`GET /me/watching/summary`)** — **shipped 2026-05-13** per §4.5. Originally tracked here as the Phase-6 deferred `GET /me/binder/summary`.
- **Email digest endpoints** — opt-in only per §I1; deferred to V1.5.
- **Per-event notification preferences endpoints** — deferred to V1.5 per §I1.
- **@mentions notification + composer parsing** — deferred to composer v2.

### 8.1 Registered with stub data (V1-shipped, scorers deferred)

These routes ARE registered in V1 and return contract-compliant envelopes today, but the upstream data sources / scorers haven't landed yet. The frontend can call them without 404'ing; payloads are valid-but-empty until the deferred work ships. Listed here so re-readers don't mistake "registered + empty" for "missing."

- **Highlight strip endpoint** (`GET /me/highlights`, `POST /me/highlights/:id/dismiss`) — registered. `GET /me/highlights` returns `{items: []}` until the slot scorers (negative / positive / external) land. `POST /me/highlights/:id/dismiss` is fully implemented (dismissal pipeline + per-slot TTLs in `wp_usermeta`). Anonymous `GET` returns 401 per §7 item 5. Scorer roadmap is documented inline at `app/Domain/Core/Services/HighlightsService.php`.

### 8.2 Registered and fully wired in V1

These routes ARE shipped in V1 with real data — earlier drafts of this doc listed them as Phase 2 / Phase 5 deferrals, but implementation has caught up and they're now first-class V1 surfaces. Documented here so the contract matches reality.

- **Composer endpoints** — both fully wired:
  - `POST /posts` accepts `kind: 'status' | 'review' | 'blog'`
    (disputes / post-as-entity remain V1.5/V2 per §P1). Routes
    through `PostsService::createStatus` / `createReview` /
    `createBlog`. Status + review write via PeepSo's canonical
    `add_post` path; blog writes directly via
    `PeepSoStatusWriter::createSelfBlogPost` (post_type=`peepso-post`
    with `_bcc_activity_module='blog'` post_meta and
    `act_module_id=204` on the activity row — see
    `PeepSoActivityWriter::MODULE_ID_BY_NAME`). Auth-required;
    rejects unknown kinds with `bcc_invalid_request`/400. Reviews
    are gated on Level 2 + reputation tier ≥ neutral via
    `FeatureAccessService`. Blog accepts `title`, `excerpt`,
    `content`, `category`, `tags[]`, `chain_tags[]` (slugs resolved
    server-side), `disclosure` (`null = none` /
    `{tickers, note} = declared`; empty struct rejected), and
    `status: 'draft' | 'publish'`. Draft posts skip the
    `peepso_activities` insert until the
    `BlogStatusTransitionHandler` observes draft→publish. v1.5:
    status / blog bodies are run through `MentionPolicy` (§3.3.12)
    — bodies containing `@peepso_user_<id>(name)` tokens for
    hidden/blocked/banned/private users are rejected with
    `bcc_invalid_mention_target`; bodies with > 10 mention tokens
    get `bcc_too_many_mentions`.
  - `PATCH /posts/{id}` (added 2026-05-15) — owner-only blog edit.
    Partial-update semantics: omitted fields are unchanged;
    `tags: []` / `chain_tags: []` clear; `disclosure: null` clears;
    `cover_image_id: 0` un-pins the cover. `post_author` is
    immutable from the API. Server calls
    `wp_save_post_revision($postId)` before any mutation so edit
    history is captured via WP-native revisions (no separate
    revisions table). `bcc_not_found` when the post doesn't exist
    or isn't a blog post; `bcc_forbidden` when viewer is not the
    post_author.
  - `GET /posts/{id}` (added 2026-06-04) — owner-only edit-read backing
    the composer's `?edit=<id>` cold-load / deep-link path. Returns the
    flat blog-edit view-model (§3.3.8 body fields + `status`), **not** a
    FeedItem. Drafts are returned to their author (drafts have no
    activity row, so they're unreachable via `/users/:handle/blog`).
    Same identify + ownership guards as `PATCH /posts/{id}`:
    `bcc_forbidden` when viewer is not the post_author, `bcc_not_found`
    when missing or not a blog post. `Cache-Control: no-store`.
  - `POST /blog/cover-image` (added 2026-05-15) — multipart
    cover-image upload. Wraps `wp_handle_upload` +
    `wp_insert_attachment`. Accepts `image/jpeg|png|webp|gif`, ≤ 8
    MB. Returns `{attachment_id, url, width, height}`. Sets the
    attachment's `post_author` so the create-path ownership check
    in `PostsService::createBlog` accepts it. Throttled at 5 /
    60 s / user via `Throttle::allow` (key:
    `BCC_TRUST_RATE_LIMIT_BLOG_COVER_UPLOAD`).
  - `GET /blog/chain-options` (added 2026-05-15) — anonymous-readable
    picker source for the composer's chain-tag multi-select.
    Returns `{items: [{id, slug, name, color, icon_url}, ...]}`
    from `ChainRepository::getActive()`. `Cache-Control: public,
    max-age=3600` (the chain registry changes rarely).
  - `POST /reactions` accepts the trust kind `'solid'` plus the social kinds `'like' | 'love' | 'haha' | 'wow' | 'fire'`. (`vouch`/`stand_behind` were retired as reaction kinds — they're attestations, §J.) Routes through bcc-core's `PeepSoReactionWriter` (single-graph rule). Throttled at 60/minute per viewer. Returns the post-mutation `{counts, viewer_reaction}` shape so the frontend patches its cache without a feed refetch. `DELETE /reactions/:feed_id` also registered. **Group-membership gate (v1.24):** a reaction on a group-scoped post (parent carries `peepso_group_id` post-meta) requires active group membership; non-members get `bcc_permission_denied 403`. Applies to both `POST /reactions` and `DELETE /reactions/:feed_id`. This mirrors the existing comment-create gate (§4.13 `POST /posts/:feed_id/comments`, which enforces the same membership requirement — note that the comment path returns `bcc_forbidden 403` for the analogous refusal).
  - Bonus: `DELETE /me/reviews/:id` is also live and routes through `PostsService::removeReview`.
- **Onboarding endpoints** — all four fully wired:
  - `POST /auth/signup` — email / password / handle account creation. Rate-limited; validates handle availability; maps `db_insert_error` race conditions to `bcc_conflict`/409.
  - `GET /onboarding/suggestions` — three buckets (validators / projects / creators) populated via `PageDiscoveryService::query` + `CardViewService::getCard`. Returns real `Card` view-models per §3.2. Cached `private, max-age=60` for the wizard's tab-switching.
  - `PATCH /me/handle` — §B6 7-day cooldown enforced FIRST so probe attempts can't bypass it, then validation + conflict detection. No-op renames short-circuit without arming the cooldown.
  - `POST /me/onboarding/complete` — persists the `bcc_onboarded` flag + optional `home_chain` (validated against the `HOME_CHAINS` enum). Response carries `rank_label` (server-rendered per §A2 — the §O1 dopamine moment renders it verbatim, no client-side rank mapping). Idempotent on re-run.
  - Bonus: `GET /me/onboarding/status` is also registered (read-side flag check; not previously listed in §8).
- **Directory endpoints (§G1/§G2)** — both fully wired:
  - `GET /cards` — paginated list of Card view-models with `kind`/`tier`/`sort`/`q` filters. Wraps `PageDiscoveryService`; each row hydrated through `CardViewService::getCard()` so the per-item shape is identical to the single-card endpoint. (Historical note: `/bcc/v1/discover` previously shared this service for the legacy bcc-page-slider block; that endpoint was retired 2026-05-15.)
  - `GET /cards/search` — top-N suggestions for the §G1 nav-bar autocomplete. Internally calls bcc-search via `rest_do_request` and maps the flat result shape (reputation_tier → card_tier, category_slug → card_kind, route prefix per kind) into the `SearchSuggestion` shape per §A2.
- **Notifications endpoints (§I1)** — fully wired:
  - `GET /me/notifications` — cursor-paginated list scoped to `not_module_id = BCC_NOTIFICATION_MODULE_ID` (= 9000). Server-rendered messages + server-built `link` per type per §A2.
  - `GET /me/notifications/unread-count` — drives the bell badge; frontend polls 60s + on window focus.
  - `POST /me/notifications/mark-read` — single (`{id: N}`) + bulk (`{}`) in one route. Idempotent.
  - `NotificationDispatcher` subscribes to `bcc_reaction_added`, `bcc_review_published`, `bcc_card_watched` (and its legacy alias `bcc_card_watched` during the §1.1.1 deprecation window), `bcc_rank_awarded`. Writes through `PeepSoNotificationWriter` (bcc-core) — single-graph rule per §I1. The `bcc_reaction_added` / `bcc_reaction_removed` events were added to `ReactionsEndpoint` as part of this work (only event the catalogue was missing).
- **Celebrations endpoints (§O1.2 out-of-band path)** — fully wired:
  - `GET /me/celebrations/pending` — reads the single-slot `wp_usermeta.bcc_pending_celebration` stash. Frontend polls 60s + on window focus.
  - `POST /me/celebrations/consume` — clears the stash. Idempotent.
  - `RankProgressionListener` is the only producer in V1 — listens to activity events and detects auto-derived rank changes via `RankCatalog::orderOf` comparison, seeds quietly on first sighting so existing users at rollout don't get phantom celebrations.

---

## 9. Versioning & change protocol

- This document is versioned in git.
- Additive changes (new fields, new endpoints) bump the minor version (`1.1`, `1.2`, …) and append a changelog entry to §10.
- Breaking changes (renamed fields, removed fields, type changes) require a `v2` namespace; `v1` continues to serve until decommission.
- Implementation MUST NOT diverge from the contract. If the implementer hits a contradiction, the contract changes first, then the implementation.

---

## 10. Changelog

### v1.50 — 2026-07-23 — Endorse display labels converge to Vouch; dead endorse notification plumbing retired

Completes the §J.7 "Endorse replaces by Vouch" convergence at the display layer, and
retires notification plumbing that has been unreachable since the Slice-E endorse-write
cutover. **Wire names are unchanged**: `/endorse` + `/revoke-endorsement` routes,
`endorsement_count`, `endorsements_received`, `viewer_has_endorsed`,
`endorse_unlock_hint`, `action: "endorse"`, `bcc_endorse_self`, and the `endorsements`
sort/stat keys all keep their names.

- **§3.2 stats array** — the `endorsements` entry's display `label` is now `"Vouches"`
  (`CardViewService::buildPageStats`). `key`/`raw` unchanged.
- **Unlock hints + error text** (display strings only): `Sign in to vouch.` /
  `You can't vouch for your own page.` / `Reach Neutral standing to vouch.` /
  `Invalid vouch context.` / `This page cannot be vouched for.`
- **`bcc_endorse` bell type RETIRED** (§4.10 type enum, §I2 catalogue, deep-link map,
  subscriber catalogue): its `bcc_trust_endorsement_added` source event has not fired
  since Slice E — entity-page vouch casts dispatch `bcc_attestation_vouch_received`.
  Historical rows are rejected by read-side validation (`NotificationType::isValid`).
- **`bcc_endorse` / `endorse` notification-pref keys RETIRED** (bell + push prefs,
  §4.7-area samples): the keys gated nothing. `GET/PATCH /me/notification-prefs` no
  longer emits or accepts them; unknown keys in a PATCH are ignored as before.
  Frontend removes the two dead pref rows (coordinated FE sweep).
- **Push subscriber catalogue** — the `endorse` row removed (`PushPayload::forEndorse`
  deleted; verified zero call sites).
- Compatibility: display-only + removal of never-emitted keys/types. Clients that
  branched on `bcc_endorse` bell rows will simply never see one (unchanged in practice —
  none have been written since Slice E).

Finishes the member-review slice. Additive on the wire.

- **Member card** — `viewer_has_reviewed` is now REAL (vote on the member's self-page; anon → false); the "always false on member cards" rule is retired. New member-only field `review_target_id` = `selfPageId(user_id)` — the handle for `DELETE /me/reviews/:id`. (`permissions.can_review` was already real server-side via `resolveMemberPermissions` — the old constant claim described only the degraded placeholder card.)
- **`User.counts`** gains `reviews_received` (votes on the self-page; public by the 2026-07-22 decision — NOT zeroed by `reviews_hidden`).
- **Profile `tabs`** — received/written split: key `reviews` now counts received (never hidden), new key `written` counts authored (honors `reviews_hidden`).
- **`DELETE /me/reviews/:id`** — documents that member self-page ids are valid (`review_target_id` is the source).
- Compatibility: purely additive; pre-v1.49 clients ignore the new fields and keep the write-only member control.

### v1.48 — 2026-07-22 — Member-target review rendering + received-reviews surface

Finishes the "review a member" slice: reviews of a member (self-page votes) now render with their target everywhere and gain a public received-reviews read surface. Additive on the wire.

- **§3.3.2 `review` feed body** — rewritten to the implemented shape `{grade: trust|neutral|caution, text, page_id, page_handle, page_name, page_kind}` (the documented `A–F`/`grade_label`/`summary`/`long_form` shape never shipped — pre-existing drift, retired). New `page_kind` value `member` (link prefix `/u`); member rows carry the self-page `page_id`, display name, and `bcc_handle` (`""` when unset → link suppressed, login never projected).
- **`GET /entities/:target_kind/:target_id/reviews`** — `target_kind` gains `user_profile`; its `target_id` is the raw user id, translated server-side via `MemberSelfPageService::selfPageId`. Received member reviews are public and NOT governed by `reviews_hidden` (which governs only the written list) — decision locked 2026-07-22.
- **`GET /users/:handle/reviews`** — `scope_label` gains `MEMBER`; member-target rows resolve `subject`/`subject_handle` from the reviewed member (display name / `bcc_handle`) instead of the previous blank-from-NULL-join projection.
- **Notification copy** (no wire change): a review on a member self-page notifies "@actor reviewed your profile." instead of the degraded "reviewed your page."
- Compatibility: additive enums only. The FE review body is typed `Record<string, unknown>`, so pre-v1.48 clients render member reviews as unlinked text rather than breaking.

### v1.47 — 2026-07-20 — Search query-length guard (oversized `q` → 400)

bcc-search; hardening only, no shape change to valid responses.

- All three search verticals (`GET /bcc/v1/search`, `/search/users`, `/search/groups`) now declare `maxLength: 100` + an explicit `validate_callback` on the `q` arg, so a query over 100 characters is rejected with WordPress's standard `rest_invalid_param` **400** at the REST validation layer — before any handler/sanitize work. This defines behaviour that was previously undefined (the contract only specified 2..100 and the empty-for-shorter case); valid queries (≤100) are unaffected. The in-handler length window remains as defense-in-depth.
- Ships alongside the bcc-search correctness batch (projects `page_url` off-app-navigation fix — implementation now matches the already-documented `/v//p//c/` route; secret-page cache/LKG invalidation on privacy flips; trending trust-engine-failure fail-closed) and bcc-trust (trending read-model privacy filter; cards-autocomplete post-cache prime). Those are implementation-conformance / internal-behaviour fixes with no contract-shape change.

### v1.46 — 2026-07-19 — Search hygiene: users-vertical conformance, categories diet, FT-index observability

bcc-search + bcc-core; no frontend changes required.

- **`GET /bcc/v1/search/users` conformance fix (implementation, not contract):**
  the implementation had drifted from the documented shape — it returned the
  login-derived `user_nicename` (BCC signup derives login `u_<handle>` AND
  nicename from the credential name, so "@u_…" rendered in the FE and the
  credential name leaked to anonymous callers) and an absolute WP-origin
  permalink as `profile_url` (navigating users off the headless frontend).
  It now returns the §B6 canonical `bcc_handle` as `username` (nicename
  fallback for handle-less legacy accounts, never the login) and the
  relative `/u/{handle}` route as `profile_url` — exactly what §4 always
  documented. Also removes a per-row `PeepSoUser` instantiation.
- **`GET /bcc/v1/search` `categories` scope:** empty-result short-circuits
  now return `categories: []` (see the §4 note). Result-bearing responses
  are unchanged.
- **Junk-gate consolidation (internal):** `SearchController`'s private
  Phase-1 copy of the query-quality gate collapsed into the shared
  `QueryQualityGate`. No wire-visible change.
- **New degradation subsystem `search_ft_index`** (`title_only_fallback`) —
  recorded when an FT-eligible query is served by the title-prefix fallback
  because the FULLTEXT index is missing. Registered in the bcc-core
  canonical map; surfaced via `/system/health`.

### v1.45 — 2026-07-19 — Reply notifications route to the replied-to comment's author

bcc-trust only; **no wire-shape change** — no new notification `type`, no
prefs keys, no frontend edits (messages render verbatim per §I1).

- **Bug fixed:** a threaded reply (v1.42 `parent_id`) notified only the
  post owner; the comment author being replied to heard nothing.
- **New behaviour:** a reply notifies the replied-to comment's author
  ("@x replied to your comment.") AND the post author ("@x commented on
  your post."). When they're the same user, only the reply notification
  is sent. Self-replies never notify. See the updated §I1
  comment-received policy locks for the full decision table
  (`NotificationDispatcher::resolveCommentRecipients`).
- Both messages ride the existing `bcc_comment_received` type — same
  bell/push toggles, same `/?focus=<act_id>` link. Push copy branches on
  an internal `is_reply` payload flag ("N new replies." when aggregated).

### v1.44 — 2026-07-17 — Media-only comments (photo/GIF, no text)

bcc-trust #92 + bcc-core #26 (two halves of one fix) + bcc-frontend #43
(composer half). Additive relaxation of §4.13 `POST /posts/:feed_id/comments`.

- **`body` is now optional** (was required): 0–2000 chars after trim. The
  validation rule is **body OR media** — an empty/omitted `body` is accepted
  when `attachment_id` or `gif_url` (v1.41 media sidecar) is present; empty
  body with no media still rejects `bcc_invalid_request 400`. The REST-arg
  layer (`required: false`) and `CommentService::createComment()` enforce
  this together.
- **Write-path internal:** PeepSo's `add_comment` predates media-only
  comments and hard-requires non-empty content, so
  `PeepSoCommentWriter::addComment()` (bcc-core) gained
  `bool $hasMedia = false`; when the body is empty and `$hasMedia` is true
  it writes `EMPTY_BODY_PLACEHOLDER` (a zero-width space) instead.
  `CommentRepository` strips the placeholder back out on every read path —
  it never appears in any API response; a media-only comment's `body` reads
  back as `""`. Clients MUST treat empty `body` + `media` as a valid,
  renderable comment (the frontend composer already sends exactly this).
- No wire-shape changes to the §3.5 Comment view-model; `media` block,
  mentions, replies (v1.42), and stoke semantics are unchanged.

### v1.43 — 2026-07-16 — Internal indexer tick also warms the anon hot feed

Internal-endpoint change only (bcc-trust #91 + bcc-frontend #42); nothing
consumed by the typed frontend clients changes.

- **Internal `POST /internal/indexer/tick`:** the minutely tick now also
  rebuilds the anonymous `/feed/hot` first-page payload cache (reuses
  `CronService::warmHotFeed()`, run before the indexer since the warm is
  sub-second while the indexer may spend its full `MAX_RUNTIME_SECONDS`
  budget). The `bcc_trust_feed_hot_warm` WP-Cron hook stays registered as
  the local-dev/fallback driver, same as the indexer hook; racing is
  harmless (same payload, last write wins). Success body gains additive
  `warm_ms` alongside `elapsed_ms` (which still times the indexer alone):
  ```
  { "ok": true, "ran_at": "…", "elapsed_ms": 1203, "warm_ms": 142 }
  ```
- **Driver correction:** the Vercel Cron for this endpoint was shipped
  scheduled daily; fixed to `* * * * *` (bcc-frontend #42 — the project is
  on Vercel Pro, which supports minutely crons; the v1.18 note claiming
  free-tier 1-min support was wrong). Purpose: keep both 1-minute jobs at
  cadence once `DISABLE_WP_CRON` is enabled on shared hosting (hPanel cron
  floor 5–15 min).

### v1.42 — 2026-07-13 — Comment threading (parent link + reply count)

Nested / threaded replies (bcc-trust 1.2.27 + frontend PR #41 — Tia). Additive
only; a pre-1.2.27 backend omits both fields, so every comment reads as a root
and the drawer renders the flat list unchanged.

- **§4.13 POST:** new optional `parent_id` (the `comment_<int>` being replied
  to). Validated BEFORE the write — it must resolve to a live comment on the
  **same** parent post; a malformed id, deleted parent, or cross-post parent
  rejects with `bcc_invalid_request` and no comment is created.
- **§3.5 `Comment` view-model:** gains additive-optional `parent_id`
  (`comment_<int>` | `null` for top-level) + `reply_count` (count of DIRECT
  replies; absent → 0). The list stays flat + paginated — the frontend threads
  the loaded set client-side; an orphan (parent not in the loaded pages)
  surfaces at root. `reply_count` is batched per page (one bounded IN-list
  post-meta read, no N+1).
- **Storage:** `_bcc_parent_comment` post-meta on the reply's own wp_post (the
  parent's numeric act_id) — same single-graph sidecar as v1.41 media; no new
  table, no migration; PeepSo's comment delete trashes it with the post.
- **Deferred:** per-subtree (`?parent=`) pagination + a shareable `?thread=<id>`
  deep-link — the v1 drill-down is an in-drawer client-side re-root.

### v1.41 — 2026-07-12 — Comment media attachments (photo XOR gif)

`POST /posts/:feed_id/comments` gains optional media (bcc-trust 1.2.26 / PR
#85 + frontend PR #40 — Tia). Additive only; a text-only comment is unchanged.

- **§4.13 POST:** new optional `attachment_id` (caller-owned `image/*` WP
  attachment via the shared `POST /blog/cover-image` upload) XOR `gif_url`
  (Giphy-host URL, host-parsed). Photo wins if both are sent. Validation
  runs BEFORE the comment write — a bad attachment leaves no orphan comment.
  New error surface: `bcc_invalid_request` (missing/non-image attachment,
  non-Giphy URL), `bcc_forbidden` (foreign attachment).
- **§3.5 `Comment` view-model:** gains additive-optional `media`
  `{kind: "photo"|"gif", url, width?, height?}` (dims on photo only; the
  internal `attachment_id` never hits the wire). Absent on text-only
  comments and pre-1.2.26 backends. Batched per page — one bounded IN-list
  post-meta read, no N+1.
- **Storage:** `_bcc_comment_media` post-meta JSON sidecar on the comment's
  own wp_post — no new table, no migration; PeepSo's comment delete trashes
  the sidecar with the post.

### v1.40 — 2026-07-12 — Comment sort: relevant / top / new

`GET /posts/:feed_id/comments` gains a `sort` param (bcc-trust 1.2.24 / PR
#81 + frontend PR #39 — Tia; CI-fix + rebump merged in by Phillip's side).

- **`sort`** = `relevant` (default) | `top` | `new`. **Default flip:** the
  list was chronological-newest before; it now defaults to `relevant` — a
  lean `(stoke_count+1)/(age_hours+2)^1.5` gravity score, rounded to 6
  decimals so keyset cursors tiebreak stably. `top` orders on the v1.38
  comment stoke count; richer relevance inputs (replies, author tier) are
  deferred.
- **Cursor** payload is now `{k, id}` where `k` is the active sort's
  ordering key; legacy `{t, id}` cursors still decode. Cursors don't
  transfer across sorts.
- `stoke_count` now computes inline in the list query (`stoke_total`),
  replacing the v1.38 batched read — response shape unchanged.

### v1.39 — 2026-07-12 — Canonical post permalinks (`/u/{handle}/post/{shortcode}`)

Post `links.self` moves from `/post/{act_id}` to `/u/{handle}/post/{code}`
(bcc-trust 1.2.23 / PR #82). Decision record: bcc-frontend
`docs/post-url-shortcode-brief.md` — **stored `short_id` chosen over Hashids**
(production launches empty, so the backfill cost that motivated Hashids
doesn't exist; stored codes need no salt, no package, no dual resolver).

- **§3.3 `links.self`:** now `/u/{handle}/post/{code}` on every feed surface
  (list, hot, tag, single-item, author wall, group feeds, blog tab). The old
  example (`/p/feed_98712`) was doubly stale — code had emitted
  `/post/{act_id}` since the v1.33 permalink fix; both prior shapes are
  superseded. Numeric `/post/{act_id}` remains only as a degraded fallback
  when a code can't be ensured.
- **§1.7:** immutability nuance — the shortcode is the immutable key; the
  handle prefix is display context (redirect-on-rename is frontend behavior).
- **`GET /feed/:id` (§4):** gains a sibling `[a-zA-Z]{8}` route pattern
  resolving shortcodes; numeric ids keep working. Letters-only codes keep the
  input domains disjoint (and can't collide with `/feed/hot` / `/feed/tag`).
- **Storage:** new `wp_bcc_post_shortcodes` sidecar (act_id PK, unique
  CHAR(8) code), codes ensured lazily at emission + option-guarded dev
  backfill. Cosmetic/navigational only — no trust-surface impact.
- **Canonical-handle backfill:** legacy accounts missing `bcc_handle` get one
  derived + validated via HandleService (one-shot). Root-cause fix for
  space-bearing byline handles that could never resolve through the §B6
  route patterns (supersedes the v1.2.21 nicename fallback, which the route
  regex prevented from ever firing).

### v1.38 — 2026-07-12 — Stoke on comments

Comment rows gain a plain X-"like" Stoke toggle (bcc-trust 1.2.22 / PR #80,
frontend PR #38 — Tia). Additive only; no breaking changes.

- **New endpoint `POST`/`DELETE /comments/:id/stoke` (§4):** same
  auth/throttle/gate/event shape as `/feed/:id/stoke`, but the group gate
  resolves off the comment's PARENT post (`GroupInteractionGate::checkPost`
  via `CommentRepository::getCommentMeta`) because the comment's own wp_post
  carries no `peepso_group_id`. Response is the bare
  `{ stoke_count, viewer_has_stoked }` pair — no `heat_stage`.
- **§3.5 `Comment` view-model:** gains additive-optional `stoke_count` +
  `viewer_has_stoked` (batched per page, two bounded IN-list reads). Absent
  from pre-1.2.22 responses; the frontend hides the rail when absent.
- Comment stoke shares `wp_bcc_trust_stokes` + `StokeRepository` with the
  post rail — no schema change, still cosmetic for trust (never writes
  `bcc_trust_scores`).

### v1.37 — 2026-07-09 — Suspension-gate parity on all group-membership writes

The 2026-07-08 audit's "group rejoin" MEDIUM was fixed for
`POST /me/groups/:id/join` (bcc-trust PR #56): the join is gated on
`Permissions::is_not_suspended(userId, false)` — 403 `bcc_forbidden`, admin
bypass off. This entry documents that gate (omitted from the contract when
it shipped) and extends the same gate to the sibling membership-write doors
that were still open. Additive error case only; no response-shape changes.

- **`POST /me/groups/:id/join` (§4.7.3):** documents the already-shipped
  suspension 403.
- **`POST /me/locals/:id/membership` (§4.7.x):** suspension 403 added; the
  entry now also documents the existing `bcc_forbidden` for a non-open
  Local (shipped with the trust#54 join gate).
- **`POST /me/holder-groups/:id/join` (§4.7.1):** suspension 403 added —
  holding the NFT is eligibility, not an override of moderation.
- **`POST /me/groups` create (§4.7.3):** suspension 403 added — a
  suspended account cannot create communities (it would land as
  `member_owner` of a brand-new group).
- **Auto-join reconcile** (`PATCH /me/holder-groups/preferences` immediate
  reconcile + the cron sweep) now skips suspended users server-side.
  Response shape unchanged (`reconciled` reports zeros).
- **bcc-core `PeepSoGroupWriter::join`** now refuses to upgrade an
  existing `gm_user_status='banned'` row (returns `false` instead of
  silently flipping a group-level ban back to `member`). Defense-in-depth:
  no BCC surface can currently create a group-level ban, but the invariant
  is now enforced centrally for every present and future join door.
- **Writer verdict honored everywhere:** `POST /me/groups/:id/join` gains
  a `bcc_unavailable` 503 case and `POST /me/holder-groups/:id/join` folds
  writer refusal into its existing `bcc_upstream_unavailable` 503 — the two
  callers that previously ignored the writer's return no longer report
  `joined: true` for a write that didn't happen.

### v1.36 — 2026-07-09 — Conferred-Foreman **Role** scaffolding retired (never built)

Cleanup release. The 2026-06-22 identity slice (v1.28) shipped read-side
placeholders for a conferred **Foreman Role** that never got a conferral
path — the fields were always `false` and no code ever set them. Option B:
retire the scaffolding rather than keep dead future-readiness on the wire.

- **`foreman_insignia` removed** from the member/card view-models (§3.1,
  §3.2) and from the `GET /ranks` viewer block (§4.8). It was a boolean
  that was always `false` in V1.
- **`is_admin_conferred` removed** from the `GET /ranks` viewer block
  (§4.8). Likewise always `false`.
- **`GET /members?rank=` filter removed.** The dead filter queried the
  now-dropped `bcc_user_ranks` table and always returned empty; the
  endpoint's documented query set was already `page`/`per_page`/`q`/`type`.
- **Rank is unchanged** — it remains the auto-derived **level** label
  (Apprentice/Journeyman/Master). `rank`, `rank_label`, `current_rank_label`,
  `next_rank`, `next_rank_label` all stay. §4.8 now documents rank as
  fully level-derived with no conferred-Role fields.
- The identity model is now **Rank (level) · Trust Tier** (two axes). The
  Foreman **Role** is deferred entirely — there was never a conferral path,
  so it is retired, not "deferred with read artifacts." Historical
  changelog entries (v1.19/v1.23/v1.28) that describe these fields as
  shipped are left as history.

### v1.35 — 2026-07-08 — Disputes reconciliation: dead `bcc_trust_flags`/`can_dispute` layer retired

The 2026-07-06 audit flagged "disputes never surface on profiles." The
cause was not a missing writer — it was **two dispute layers sharing the
word "dispute."** The live layer (`bcc_disputes`, panel-adjudication,
written by `POST /disputes` via `OpenDisputeModal`) worked; the profile/
entity Disputes tabs read a *different*, write-dead legacy table
(`bcc_trust_flags`, whose only writer was the deleted `report_vote`
route). The fix repoints every reader/gate at the live layer and retires
the dead one. Same vestigial-residue pattern as the v1.34 reaction/
attestation cleanup.

- **`GET /users/:handle/disputes` (§3.1):** response shape **unchanged**;
  data source moved from `bcc_trust_flags` (write-dead → always empty) to
  the live `bcc_disputes` reporter-keyed reads. Disputes a member filed
  now actually appear. `status` mapping documented (`reviewing`→`open`,
  `accepted`/`rejected`/`timeout_no_quorum`→`resolved`,
  `dismissed`→`dismissed`); `status_label` carries the richer outcome.
- **`counts.disputes_signed`:** now aggregated from `bcc_disputes`
  reporters (was `bcc_trust_flags` signers, always 0).
- **`can_dispute` removed** from member (§J.6) and card/community (§3.2,
  §4.4) permission blocks. It was a dead scaffold — a "§J attestation
  cast" with no attestation kind, rendering a do-nothing DISPUTE button.
  The sole person-level negative action is now **`can_report`**;
  vote-disputes remain owner-only via **`can_open_dispute`** (unchanged).
- **`GET /entities/:kind/:id/disputes` route REMOVED.** The entity
  Disputes tab is retired — its "disputes filed *against* this entity"
  premise was the dead adversarial model (the live model is the owner
  *defending* against a downvote). Active-dispute context for an entity
  is already surfaced via the §J `negative_signals` summary.
- **`bcc_trust_flags` table dropped** (guarded migration); `FlagsRepository`,
  `CardDisputesService`/`CardDisputesEndpoint`, and the orphaned
  `sign_dispute` feature deleted. The view-model `flags` field
  (suspension + usermeta moderation flags) is unrelated and untouched.
- **No new mutation, no new auth surface.** `POST /report-user` and
  `POST /disputes` are untouched and already live.

### v1.34 — 2026-07-08 — Trust reaction grammar is `solid`-only; `vouch` is an attestation, not a reaction

Naming-clarity reconciliation. The v1.29 "vouch confers trust via a
`post_vouch` reaction" mechanism was superseded when vouch relocated to
the trust-attestation layer (§J): the byline **Vouch** toggle and §J.6
profile actions now cast a full `vouch` *attestation* through
`POST /me/attestations` (scored by `AttestationScoreSynthesis`), not a
reaction. The reaction grammar kept advertising a `vouch` kind that was
unseeded and uncastable — the residue that made the reaction and
attestation systems look merged. This makes the contract say what the
code does.

- **§2.11 reaction grammar:** trust kinds narrowed to `solid` (was
  `solid, vouch`). `solid` is a lightweight ack (powers the "solids"
  stat); it confers no trust. `vouch`/`stand_behind` are attestations
  (§J), never reaction kinds.
- **`POST /reactions`:** the `reaction` enum no longer accepts `vouch`
  (or `stand_behind`) — sending either now returns `bcc_invalid_request`
  400 (previously an unresolved `bcc_unavailable` 503). `solid` + social
  kinds unchanged.
- **No behaviour change to attestations.** §J vouch/stand_behind
  attestations — the live trust-moving path — are untouched.
- Shipped: bcc-core (`ReactionGrammarMap::TRUST_KINDS = [solid]`),
  bcc-trust (dead `reactionVerb` branch + docblocks), bcc-frontend
  (orphaned reaction client deleted; `TrustReactionKind = "solid"`).

### v1.33 — 2026-07-07 — Login accepts email or handle (`identifier` replaces `email`)

`POST /auth/login` body key renamed `email` → `identifier`; the field now
accepts either the account email or the handle (server-detected by
shape — `is_email()` → email lookup, otherwise handle → `u_<handle>`
login lookup, leading `@` stripped). Anti-enumeration behaviour
unchanged: one generic `bcc_invalid_credentials` 401 regardless of
identifier shape or which check failed; rate limit still precedes the
bcrypt compare.

Strictly a §9 breaking rename, shipped **in place** rather than as
`v2`: pre-launch, zero external consumers, and the sole consumer
(bcc-frontend login page, `loginWithIdentifier`) merged atomically
with the backend (bcc-trust #48 + bcc-frontend #12).

**Also in v1.33 — §4 backfill of three shipped-but-undocumented
routes** (surfaced by `contract-parity-guard.php` after the same
merge; the frontend already calls all three):

- **`GET /feed/:id`** — single-activity permalink read backing the
  post-detail page / quick-view modal. Bare §3.3 `FeedItem`;
  invisible-to-viewer collapses to `bcc_not_found` 404.
- **`POST /feed/:id/stoke`** / **`DELETE /feed/:id/stoke`** — the
  Stoke engagement toggle (one per person per post, idempotent both
  verbs). Returns §2.11 `ReactionState` + additive `heat_stage` /
  `viewer_has_stoked` / `stoke_count`. Own `wp_bcc_trust_stokes`
  table; cosmetic for trust (no `bcc_trust_scores` writes).

### v1.32 — 2026-07-03 — Collection stances: airdrop-proof demand + scam flags (additive)

New §4.31. Passive holdings turned out to be a forgeable demand signal
(airdrop spam lands in every wallet), so demand is now measured by
explicit per-collection user declarations:

- **NEW `GET /me/collection-stances/panel`** — the wallet-connect /
  onboarding panel: held collections with per-row state (`live` →
  drives the existing §4.7.1 join · `waitlist`), public waitlist count,
  viewer's stance.
- **NEW `POST /me/collection-stances`** / **`DELETE …`** — set/switch/
  retract `waitlist` | `spam` (holder-gated; one stance per collection).
- **NEW bell type `bcc_holder_community_live`** (+ push event
  `holder_community_live`, both default-on prefs) — fired once per
  (user, collection) when a waitlisted collection's community
  provisions.
- **Server-side:** admin Verify Collections ranks by waitlist count
  (spam-flagged rows sink; ⚑ counts shown), gains per-row Hide/Unhide
  writing contract DENY/ALLOW rules that survive rediscovery; Cosmos
  wallet-link discovery now runs the same spam pipeline as EVM; the
  spam-rule table's `contract_address` widened to VARCHAR(128) —
  Cosmos contract addresses (66 chars) were silently truncated and
  rules never matched.

### v1.31 — 2026-07-02 — Holdings surface unverified collections (additive)

Closes the "favorite NFT invisible → community never forms" UX hole:

- **`GET /nft-selections/picker` items** gain `collection_verified: boolean`
  (additive). Display-only — the frontend dims unverified items with a
  "community not yet activated" treatment instead of hiding them; group
  gating is untouched (it resolves per verified gate contract).
- **Cosmos gallery widened (value-level):** the read-time CW-721 walk now
  iterates every KNOWN collection (verified first under the same cap)
  instead of verified-only, so a linked wallet's assets never silently
  vanish. EVM/SOL items were already surfaced; they now carry the flag.
- **Server-side, no wire impact:** Cosmos Hub wallet links now auto-discover
  the wallet's collections via the Stargaze marketplace rollup
  (`source='discovery'`, unverified, operator-gated), and the admin Verify
  Collections queue ranks by distinct linked-wallet holders.

### v1.30 — 2026-07-02 — Stargaze chain retired (value-level; zero shape changes)

The Stargaze L1 (`stargaze-1`) halted in June 2026 after its migration to
the Cosmos Hub (Prop 1017); its CW-721 collections were re-instantiated on
the Hub as new `cosmos1…` contracts. No endpoint, field, or type changes —
this is a **value-level** retirement of one chain slug:

- **`stargaze` no longer appears** as a `chain_tag` / chain-slug value
  anywhere on the wire: the chain row is deleted (with its collections and
  wallet links) by a one-shot bcc-trust migration, and the seed no longer
  creates it. Cosmos NFT collections are curated on the `cosmos` (Cosmos
  Hub) chain instead.
- **`collection_stats.marketplace`** for `cosmos`-chain collections resolves
  to stargaze.zone — the marketplace app survived the chain migration and
  serves the Hub-hosted collections. Label stays `"Stargaze"`.
- **Wallet linking:** `cosmos` chain_type list loses Stargaze (§4.14 note);
  Keplr signing for the remaining Cosmos chains is unchanged.

### v1.29 — 2026-06-24 — Vouch confers trust; reaction `stand_behind` retired (Slice 3)

People-as-trust-subjects, Slice 3. The `vouch` **trust reaction** now lands
a light, **fixed-weight** (~10% of a top endorsement), **non-vesting**
`post_vouch` endorsement on the post author's **self-page** (Architecture A).
Rank-gated (`vouch_reaction` ≥ Level 2), one-per-voucher-per-author
(idempotent, anti-farm), and **ref-counted** — the endorsement persists while
the voucher holds ≥1 vouch reaction on any of that author's content, and lifts
when the last is removed. Rides the existing `endorsement_bonus` term (no new
formula term).

- **§2.11 reaction grammar:** trust kinds are now `solid`, `vouch`
  (`stand_behind` **reaction** retired). `solid` stays a pure lightweight ack
  (powers the "solids" stat); it confers no trust. `ReactionState.counts` for
  the trust grammar drops the `stand_behind` key.
- **`POST /reactions`:** `reaction` enum trust kinds `solid|vouch`; new
  documented side-effect when `reaction === "vouch"`.
- **Permissions / features:** `can_stand_behind` and the `stand_behind_reaction`
  feature gate removed; `can_vouch` / `vouch_reaction` unchanged.
- **No endpoint added** — vouch is a subscriber on the existing reaction bus.
- **Not** the trust-attestation-layer `stand_behind` (§J): that is a separate
  subsystem and is unaffected.

### v1.28 — 2026-06-22 — Identity slice: Rank↔level, honest tier labels, Foreman as Role

Collapses the three contradictory progression ladders into the locked
three orthogonal identity axes (**Rank · Trust Tier · Role**):

- **Rank now mirrors the feature-access level**, not reputation tier:
  Apprentice=New, Journeyman=Active, **Master=Veteran**. Master replaces
  Foreman as the top earned rung. `RankService::deriveRankFromTier` removed
  in favour of `rankForLevel`. Supersedes the v1.23 note that V1 ranks were
  "auto-derived from tier/trust score" — they are now derived from activity
  level (§2.5, §2.6, §4.8).
- **Foreman is a conferred Role, not a rank.** Removed from the `/ranks`
  catalog; never appears as `current_rank`. Surfaced via the new
  `foreman_insignia` boolean (always `false` in V1 — conferral path deferred).
- **Honest member trust chip:** new `reputation_tier_label`
  (Risky/Caution/Neutral/Trusted/**Proven**) on member/author surfaces,
  distinct from entity-card rarity (`card_tier`/`tier_label`, unchanged).
- New `/ranks` viewer fields (`current_rank_label`, `next_rank`,
  `next_rank_label`, `foreman_insignia`); new member view-model fields
  (`reputation_tier_label`, `current_rank_label`, `foreman_insignia`) —
  `null`/`false` on page + community kinds for shape stability.
- Additive + non-breaking. See glossary §1/§6 for the locked vocabulary.

### v1.28 — 2026-07-02 — Endorsement reads are attestation-backed (source-only; zero shape changes)

The legacy `wp_bcc_trust_endorsements` table (frozen since the Slice-E write
cutover) is DROPPED; every read that served it is repointed to the Trust
Attestation Layer. **No route, shape, field name, or error changed** — this
entry documents source-of-truth reality per §A4.

- **§4.22 `GET /users/:handle/endorsements` + §4.30 `/endorsements/mine`,
  `/mine/stats`** — rows are now the user's active `vouch` attestations
  (entity cards + member self-pages; `stand_behind` excluded — own roster).
  Legacy rows were materialized into attestations at the Phase-1 migration,
  so no history is lost.
- **`endorsement_count`** (cards, search `endorsements` field,
  `sort=endorsements`, read model) now counts active vouch attestations and
  refreshes on every cast/revoke/reaffirm + the nightly decay sweep —
  previously a snapshot of the frozen table taken only at vote-recalc time.
- **`endorsements_received`** member stat now counts vouches on the *person*
  (their self-page), not endorsements on pages they own — the honest number
  under the attestation model; values may shift.
- **Fraud graph** — endorsement-ring detection reads live vouch edges.
- **§4.28 reactions correction** — the stale `post_vouch` side-effect note
  removed: a `vouch` reaction is engagement chrome; the score-bearing vouch
  is the byline toggle (`/me/attestations`).

### v1.27 — 2026-06-11 — Community-card convergence (additive)

Communities converge onto the Card view-model — same convergence members went
through. Additive delivery only: no existing field changed, no new endpoints.

- **`card_kind` enum gains `community`** (§3.2). New §3.2.4 documents the
  kind's locked rules: trust placeholders (`trust_score: 0`,
  `reputation_tier: "neutral"`, `card_tier: "common"`, `rank_label: null`),
  `tier_label` = server-owned group-type kicker (HOLDERS GROUP / LOCAL
  CHAPTER / SYSTEM GROUP / COMMUNITY — supersedes the FE `KICKER_BY_TYPE`
  map per §L5), chain-keyed crest for NFT groups with a `chain_tag`,
  `members` + `posts_7d` stats, all permissions `not_applicable` for every
  viewer, `actions: { open }` only (no watch — groups are joined).
- **New envelope field `community_dossier`** — mirror of `member_dossier`:
  always present on the wire, populated on community cards
  (`{ type, privacy, member_count, verification, chain_tag, trust_min,
  collection_stats, viewer_is_member }`), `null` on
  validator/project/creator/member cards.
- **§4.7.4 `GET /groups` items each gain `card`** (composed from the same
  row data — zero per-item queries; one batched membership lookup per page).
  Cache posture tightened: authed → `private, no-store` because
  `community_dossier.viewer_is_member` is viewer-scoped; anon keeps the 60s
  public cache.
- **§4.7.5 `GET /groups/:slug` response gains `card`** (same composition,
  zero extra queries).
- `GET /cards/:type/:id` and `GET /cards` do NOT accept `community`; the
  additive fields on the two group endpoints are the entire delivery.
- Server: `CardViewService::getCommunityCardFromGroupData` (pure
  composition from already-loaded group data).

### v1.26 — 2026-06-11 — `can_open_dispute` ships (owner vote-dispute gate)

Additive permission field + example correction; no existing field changed.

- **`permissions.can_open_dispute` is now emitted** on card view-models. It was
  documented in the §4.4 shape since draft but never implemented; the frontend's
  `DisputeCallout` (owner-only "Open a Dispute" CTA) consequently consumed
  `can_dispute` — the §J attestation-cast gate (`sign_dispute` ladder) — which
  meant any veteran+wallet viewer saw the owner-only CTA on any entity page,
  and page owners below veteran never saw it for their own page.
- Semantics: mirrors `DisputeController`'s write gate exactly — page ownership
  only (`Permissions::owns_page` via PageOwnerResolver), **no feature ladder**.
  Non-owners: `allowed: false, reason_code: "not_page_owner"`, no unlock hint
  (ownership of someone else's page is not an unlockable gate per §N7's spirit).
  Member cards: `not_applicable`. Anon: `signin_required`.
- §4.4 example corrected (previously showed a fictional "Reach Level 3" hint).

### v1.25 — 2026-06-10 — Contract-route parity backfill (§γ gap closure)

Documentation-only + dead-route cleanup; **no behavior change** to any surviving
endpoint. Closes the gap surfaced by `scripts/contract-parity-guard.php` (was: 88
documented vs 233 registered in-scope routes; 128 undocumented). Full audit:
[docs/archive/route-audit-2026-06-10.md](archive/route-audit-2026-06-10.md).

- **§4 backfill (additive).** Documented the previously-undocumented but
  shipped + frontend-consumed routes under new sub-sections: §4.25 social
  connections & trust actions (GitHub/X OAuth, `bcc-trust/v1` endorse /
  revoke-endorsement / device-fingerprint), §4.26 profile editing / privacy /
  blocks, §4.27 highlights / badges / reports / messaging prefs / onboarding,
  §4.28 posts / reactions / blog composer / cold-start feed, §4.29 group & local
  detail / entity tabs / creator gallery, §4.30 disputes / received endorsements.
  Auth (`/auth/signup|login|refresh|verify-email|resend-verification`) gained
  canonical `####` headers. `GET /creators/:slug/gallery` promoted (the §8
  "deferred" note was stale — it is fully wired).
- **Dead-route removal** (no consumers; superseded — fresh-install no-backcompat).
  Removed 15 routes: the 4 `/me/binder/*` deprecation aliases, FSE-era
  `GET /page/:id`, the `bcc-trust/v1` discovery root, legacy `/vote`,
  `/remove-vote`, `/report-vote`, `/user/:id/pages/scores`, `/user/status`, and
  the never-wired `POST /claim`, `POST /flag`, `GET /nft/collections`,
  `POST /auth/token`, `GET /onchain/:page_id`. (`POST /report-user` was reverted
  after review — it is the write door to a live admin-adjudicated member-report →
  trust-penalty feature, not dead code; documented at §4.27.)
- **Guard hardening.** `contract-parity-guard.php` gained an `EXEMPT_INTERNAL`
  allowlist (23 admin/machine routes intentionally out of the public contract,
  each with a reason + stale-entry detection) and a relaxed header parser that
  recognizes the §J.* `#### §J.5 \`GET …\`` form. Result: undocumented-WARN → 0;
  the guard is now a live signal for the next accidentally-public route.
- **Documented envelope realities (no code change):** the `bcc-trust/v1` namespace
  uses the legacy `{success,data}` envelope (§4.25 note); `GET /endorsements/mine`
  and `/endorsements/mine/stats` return unenveloped top-level JSON (§4.30 note).
  Known §3.4 `HighlightStrip` shape drift flagged in §4.27.

### v1.24 — 2026-06-06

- **Group-post visibility — new optional `visibility` request field
  (additive).** `POST /bcc/v1/posts` (`kind=status`),
  `POST /bcc/v1/posts/photo`, and `POST /bcc/v1/posts/gif` gained an
  optional `visibility` enum (`members_only` | `public_group` |
  `public_all`, default `members_only`). Only honored when `group_id`
  is present (silently ignored on own-wall posts and for
  `kind=review`/`kind=blog`). Stored as `_bcc_post_visibility`
  post-meta. No response-shape change. See §4.14 / §4.15 / §8.2.
- **§4.7.6 group feed (`GET /bcc/v1/groups/:id/feed`) — behavior
  change.** Non-members of `nft` / `closed` / `open` (non-secret)
  groups now get `200` with a **public-only filtered feed**
  (`public_group` + `public_all` posts only; `members_only` and
  visibility-less posts are never returned to non-members), instead of
  the previous `403 bcc_permission_denied`. Members still get the full
  feed (all visibilities). **Secret groups unchanged** — non-members
  still get `404 bcc_not_found`. The §4.7.5 `GroupDetailResponse` now
  reports `feed_visible: true` / `permissions.can_read_feed.allowed:
  true` for non-members of `nft` / `closed` groups so the client
  renders the teaser feed instead of a locked notice.
- **Global feed (`GET /feed`, `GET /feed/hot`) — behavior change
  (§4.3).** A group-tagged post now appears in the global feed ONLY if
  `visibility = public_all`. Previously open-group posts leaked into
  the global feed regardless of intent; now only `public_all` group
  posts syndicate. Non-group posts are unaffected.
- **Reactions membership gate (§8.2).** `POST /bcc/v1/reactions` and
  `DELETE /bcc/v1/reactions/:feed_id` now require active group
  membership when the parent post is group-scoped; non-members get
  `403 bcc_permission_denied`. Mirrors the existing comment-create
  gate (§4.13). No endpoints added or removed — request fields +
  behavior only.

### v1.23 — 2026-05-26

- **§4.8 — admin/ranks REST surface retracted (doc-only).** Second
  finding from the 2026-05-26 `scripts/contract-parity-guard.php`
  first-run: the contract documented `POST /admin/ranks/award` and
  `DELETE /admin/ranks/:rank/:user_id` for admin-conferred ranks,
  but neither endpoint was ever wired into `register_rest_route`.
  Zero frontend callers, zero PHP handlers. V1 ranks are fully
  auto-derived (no admin-conferred *rank*); admin-override is not a
  V1 feature. Replaced the two `####` headers with a "deferred" note
  in §4.8. When admin-conferral becomes a real feature, the new
  endpoints get designed at that point — not resurrected from this
  retracted spec. Per fresh-install policy, the contract aligns with
  shipping reality. *(Updated 2026-06-22, v1.28: rank derivation moved
  from reputation tier to the feature-access level — `rankForLevel` —
  and the deferred admin-conferral surface is now the **Foreman Role**,
  not a rank. See v1.28.)*

### v1.22 — 2026-05-26

- **§4.7 — Locals mutation endpoints corrected to match code
  (doc-only).** The first run of `scripts/contract-parity-guard.php`
  surfaced four pieces of drift on `/me/locals/*`:
  - `POST /me/locals/:id/join` → actual code path is
    `POST /me/locals/:id/membership` (join + leave share a path, two
    methods).
  - `DELETE /me/locals/:id` → actual code path is
    `DELETE /me/locals/:id/membership`.
  - `PUT /me/locals/:id/primary` → actual method is `POST`.
  - `DELETE /me/locals/primary` (no `:id`) — clear-primary endpoint
    existed in code but wasn't documented anywhere in §4.7.
  Set-primary error code corrected: `bcc_not_found` →
  `bcc_forbidden` (the handler treats "not a member" as an
  authorization failure, not a missing resource — per
  [LocalsEndpoint.php docblock](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/REST/LocalsEndpoint.php)).
  No server-side behavior change; this is doc-only alignment with
  the existing implementation. Precedent for doc-only bumps: v1.21
  envelope-drift retraction (2026-05-26).

### v1.21 — 2026-05-26

- **§4.24 — `/wallets/project/:post_id` + `/chains` envelope deviation
  retracted (doc-only correction).** v1.19 §4.24 entries flagged both
  endpoints as raw-array drift to be closed in a "follow-up envelope
  sweep." Live verification on the 2026-05-26 sweep showed both
  endpoints have **always** been enveloped via `Envelope::wrap()`'s
  auto-wrap in `rest_post_dispatch` — `rest_ensure_response($indexed_array)`
  is correctly recognised as not-yet-enveloped and wrapped into
  `{ data: [...], _meta: { request_id } }` before the response leaves
  WordPress. The drift mentions were authored from a wrong reading of
  the controller code; no server-side behaviour change ships in this
  bump. §4.24 entries updated to reflect the actual on-the-wire shape.

### v1.20 — 2026-05-26

- **§1.4.6 — endorse error codes Phase γ-stabilized.**
  `POST /bcc-trust/v1/endorse` and `POST /bcc-trust/v1/revoke-endorsement`
  previously emitted every error envelope with the legacy `trust_error`
  code, forcing the frontend to pattern-match `err.message` (a §γ
  violation). Both routes now emit stable codes: existing standard
  codes (`bcc_unauthorized`, `bcc_invalid_request`, `bcc_conflict`,
  `bcc_not_found`, `bcc_rate_limited`, `bcc_permission_denied`,
  `bcc_internal`) plus two new feature-specific codes added to the
  table — `bcc_endorse_self` (403) and `bcc_fraud_locked` (403).
  Soft gates (quest-locked, account-too-new) surface as
  `bcc_permission_denied` with `data.unlock_hint` per §1.4.5; the
  canonical UX path remains the server-rendered
  `permissions.can_endorse` boolean.

### v1.19 — 2026-05-25

- **§4.1 — anonymous wallet-credential auth endpoints documented.**
  Locks the three already-shipped endpoints (`GET /auth/wallet-nonce`,
  `POST /auth/wallet-login`, `POST /auth/wallet-signup`) that were
  in production but absent from the contract. Documents the
  disjoint-keyspace nonce posture (anon nonce ≠ authed `/auth/nonce`),
  the IP-bucketed throttles, the distinct `bcc_wallet_not_linked` /
  `bcc_wallet_already_linked` recoverable codes, and the wallet-signup
  link-race rollback path.
- **§4.24 — new Wallets section.** Locks the self-service wallet
  management surface: `GET /wallets`, `DELETE /wallets/:id`
  (idempotent), `GET /wallets/project/:post_id`, `GET /chains` —
  all four endpoints emit the standard `{ data, _meta }` envelope
  via the auto-wrap (v1.19 originally flagged the two GET-array
  endpoints as drift; v1.21 retracted that — see v1.21 entry).
  Wallet write paths cross-reference
  the §4.23 `AccountSecurityMailer` side-channel.

### v1.18 — 2026-05-17

- **§4.31 — coalesced badges polling endpoint.**
  New `GET /wp-json/bcc/v1/me/badges?open_threads=12,47` replaces the
  three previously-uncached polling endpoints
  (`/me/messages/unread-count`, `/me/notifications/unread-count`, and
  the per-thread 5s `/me/conversations/{id}/messages` poll) with one
  cached payload. Auth required. Server-side cached for 15s with the
  §5 generation-counter pattern (cache group `bcc_badges`, key
  `me_badges:{userId}:gen{N}:{open_threads_key}`), bumped by
  `MessagesService::sendMessage`, `MessagesService::markRead`,
  `NotificationsEndpoint::markRead`, and
  `NotificationDispatcher::dispatch`. Response envelope (standard
  §1.4 `data`/`_meta` wrap):
  ```
  GET /wp-json/bcc/v1/me/badges?open_threads=12,47

  {
    "data": {
      "messages_unread": 3,
      "notifications_unread": 1,
      "open_thread_hints": {
        "12": { "latest_message_id": 88421, "posted_at": "2026-05-17T10:14:02Z" }
      },
      "polled_at": "2026-05-17T10:14:30Z"
    },
    "_meta": { "version": "v1", "reaction_types": { ... } }
  }
  ```
  `Cache-Control: private, max-age=10`. `open_threads` is a
  comma-separated list of conversation root ids (cap 5 server-side,
  silently dropped beyond). The map's keys are the same ids stringified
  (PHP json-encodes int-keyed assoc arrays as string keys). Threads
  where the viewer is not a participant are silently absent from
  `open_thread_hints` — server-side auth gate, never trust the
  frontend's query param. Frontend types in
  `bcc-frontend/src/lib/api/types.ts`: `BadgesResponse` +
  `BadgesOpenThreadHint`. The legacy single-count endpoints remain
  available for backward compat but the bcc-frontend hooks
  (`useUnreadMessageCount`, `useUnreadCount`, `useConversation`) all
  now shim into a single `useBadges` query via the
  `BadgesProvider`.

- **Internal `POST /wp-json/bcc/v1/internal/indexer/tick`** — signed
  cron-relay endpoint invoked by Vercel Cron at 1-min cadence. Auth via
  `X-Bcc-Internal` header verified `hash_equals` against
  `BCC_INTERNAL_CRON_SECRET` (must be defined in wp-config.php).
  Misconfigured constant → `503 bcc_misconfigured`. Missing/wrong
  header → `401 bcc_unauthorized` (with per-IP rate-limited
  sig-fail logging, mirrors `HeliusWebhookEndpoint`). Success body:
  ```
  { "ok": true, "ran_at": "2026-05-17T10:14:30Z", "elapsed_ms": 1203 }
  ```
  Error body uses the canonical §1.5 envelope shape with `error.code`,
  `error.message`, `error.status`. `Cache-Control: no-store`. Exists
  to replace WP-Cron on shared hosts (Hostinger Business) whose cron
  caps at 5–15 min — the `NftEthIndexerWorker` registration stays in
  place as a fallback, and a new `MAX_RUNTIME_SECONDS = 20` guard in
  the worker keeps a single tick well under shared-host
  `max_execution_time` caps. Per-chain `AdvisoryLock` makes WP-Cron +
  Vercel-Cron racing for the same chain a silent no-op for the late
  caller. Endpoint is internal, not consumed by `bcc-frontend` typed
  clients.

### v1.17 — 2026-05-15

- **§4.21 NFT showcase selections** documented end-to-end. Five
  previously-undocumented endpoints (`GET /picker`, `GET /`, `POST /`,
  `DELETE /`, `POST /refresh`, `POST /reorder`) ship with full
  envelope shapes, error codes, rate-limit posture, and side-effect
  notes (per-user generation counter bumps in cache group
  `bcc_nft_selections`). The picker GET was previously referenced
  only as an §3.6 cross-link at line 1363; the other endpoints
  existed in code but were not in the contract, creating §9
  contract-drift risk.
- **Known contract debt called out:** the controller emits some
  failure paths with non-canonical envelopes today; the frontend's
  `humanizeError` helper shims `err.status` until the canonical
  migration. Tracked.
- Frontend Tier A PR 2 (showcase MOVE UP / DOWN reorder, §5
  generation-counter fix on the repository) consumes this contract
  section.

### v1.16 — 2026-05-14

- **§J.7 notification taxonomy extends** with a seventh trust-event
  type: `divergence_state_warning`. Fires to the target operator
  when their entity (or own user_profile) transitions INTO the
  `polarizing` or `disputed` divergence-state per §J.8. Coalescing:
  per-(recipient, target_kind, target_id, new_state) 24h cooldown
  so the same transition can't spam the bell across multiple
  cron ticks. Routing: deep-links to `/me/reliability` (the §J.5
  self-mirror with the `explainer` body). PR-8b ships the worker
  + sidecar; V1 only fires on `disputed` transitions because the
  classifier doesn't produce `polarizing` until Slice E.5.
- `NotificationType::DIVERGENCE_STATE_WARNING` constant added;
  `NotificationPrefs::BELL_TYPES` / `PUSH_TYPES` / `DEFAULTS`
  extend; default ON in both bell and push (anti-noise carried by
  the 24h coalescing). Older FE clients reading the v1.15 prefs
  shape still parse cleanly — the new bell/push toggles default
  to true on the server, so absence of the key in a stored prefs
  blob falls through to enabled.

### v1.15 — 2026-05-14

- **§J.5 self-mirror response extended** with two new fields per
  PR-8a:
  - `divergence_state` (top-level) — the operator's own classification.
    Same five-state enum as `§J.6 negative_signals.divergence_state`,
    surfaced on the self-mirror for context.
  - `explainer` block (`{state, headline, body}`) — server-pinned
    plain-language explanation of the current state per the
    critical-risk-mitigation item #7. Surfaces verbatim per §A2;
    self-only by construction.
  Additive — older FE clients reading the v1.14 shape still parse
  cleanly (extra keys ignored). No breakage.
- **§J.8 dispute-state predicate aligned with `DisputeStatus` enum.**
  Earlier wording referenced `state ∈ {open, in_panel}` — these slugs
  don't exist in the actual enum (`app/Domain/Disputes/Domain/DisputeStatus.php`).
  The contract now references `'reviewing'` (the canonical active-dispute
  state per `DisputeStatus::REVIEWING`). All four terminal states
  (`accepted`, `rejected`, `dismissed`, `timeout_no_quorum`) explicitly
  do NOT count as active. Two §J.8 rows updated: `under_review` trigger
  and `unresolved_claims_count` trigger.
- **§J.8 `divergence_state` "Computed at" clarification.** V1 ships the
  classifier as a read-time pure function (`DivergenceStateClassifier`,
  PR-8a). The "nightly worker" wording is preserved for the post-Slice-E.5
  cached-recompute path; the surface shape is unchanged. No behavior
  change for clients — both implementations produce the same
  `negative_signals.divergence_state` enum value on the wire.

### v1.14 — 2026-05-13

- **§J.2 `slot_holders[]` row shape extended** with two server-rendered
  display fields so the FE picker renders without a follow-up
  display fetch:
  - `target_label` (string) — server-rendered display label per §A2.
    `"@phillip"` for `user_profile` targets; `post_title` (or
    `"#{id}"` fallback) for the three card kinds. Empty string when
    the target is deleted / unresolvable; the FE renders the row
    without a clickable affordance in that case.
  - `target_link` (string) — server-rendered relative URL per §A2.
    `/u/{handle}` for user_profile; `/v/{slug}` / `/p/{slug}` /
    `/c/{slug}` for card kinds. Empty string when the target slug
    is missing.
  Additive — older FE clients that read the v1.12 lean shape still
  parse cleanly (extra keys ignored). The lean-shape contract
  documented in v1.12 is superseded by this entry but not broken.

- **Picker UX contract preserved.** The §J.2 `bcc_attestation_bandwidth_exhausted`
  error envelope is otherwise unchanged. `error.data` carries
  `slot_holders[]` + `slots_total` + `slots_used`; status 409;
  bounded ≤ 10 holders. The FE wires the picker dialog
  (`SlotHoldersPicker`) on this code only — eligibility / fraud /
  rate-limit errors continue to surface as inline alerts on the
  cluster.

### v1.13 — 2026-05-13

- **§4.20 §J.4 Trust Attestations roster endpoint SHIPPED.**
  `GET /bcc/v1/entities/:target_kind/:target_id/attestations` is live
  end-to-end (Slice D). The contract surface is unchanged from §J.4;
  this entry records the implementation reconciliations callers MUST
  know about:
  - **`attestor.is_dormant`** — V1 emits `false` for every row. The
    field is contract-stable and present on every response; Slice E
    adds the dormancy detector (last_login + last_attestation activity
    over a 60-day window). Until then, no row dims as INACTIVE — the
    FE renders the `INACTIVE` marker conditionally on the flag so the
    activation is purely a backend change.
  - **`attestor.reliability_standing`** — V1 emits `"newly_active"`
    for every row. Same Slice E reconciliation as `is_dormant`. The
    public catalogue is positive-only by construction
    (`highly_reliable` / `consistent` / `newly_active`) per §J.3.2;
    even an attestor in `caution` tier renders with `newly_active`
    rather than a stigma marker.
  - **`attestor.badges`** — V1 emits `[]` for every row. Slice E
    populates per the §J.3.2.1 Early Read sub-track synthesis. The
    FE's row chrome already conditionally renders the badge area, so
    Slice E activation is again backend-only.
  - **`is_pre_consensus_pick`** — true when
    `kind === 'stand_behind'` AND `attestation_order_in_target ≤ 5`
    (the §J.3.2.1 early-conviction band: 1st + the 2nd–5th tier;
    edge tunable via `bcc_reliability_preconsensus_max_order`).
    Vouch rows never mark as pre-consensus (vouch is abundant per
    §J.1; the marker is reserved for the scarcer signal). This public
    boolean shares its band definition with the SELF-ONLY
    `early_read_accuracy` sub-track; the underlying numeric stays
    self-only while this marker is public.
  - **Sort modes** — `recency` produces a distinct ORDER BY
    (`created_at DESC, id DESC`). `decayed_weight` and `reliability`
    both collapse to `weight_at_time DESC, created_at DESC` in V1
    (decay age = 0 across all rows since no decay function ships
    until Slice E). The parameter is accepted on the wire either
    way — contract-stable.
  - **`include_revoked` ordering** — when true, the response places
    ACTIVE rows first, REVOKED rows after, regardless of the sort
    mode. Phillip's note: revoked is archival; preserves "no hiding
    the past" without interleaving the dead with the living.
  - **Pagination** — `total_pages` is computed against the ACTIVE
    count only. When `include_revoked` is true and revoked rows
    surface on later pages, `total_pages` may be a soft under-
    estimate (active-only ceiling). Acceptable V1; Slice E refines.
  - **Deleted-attestor rows** — if a user account has been deleted
    since casting, that row is silently dropped from the response
    (the underlying attestation stays in the DB, but rendering a
    "[deleted]" row would create UX awkwardness the contract
    doesn't model). Acceptable V1 — Slice E reconsiders.
  - **Cache:** authed → `private, max-age=30` + `Vary:
    Authorization, Cookie`; anon → `public, max-age=30`. Both
    aligned to the 30-second generation-counter invalidation
    contract from §J.4.
  - **§J.2 `slot_holders[]`** — Slice C's V1 stub returning `[]` is
    now replaced with the real payload. Shape per item:
    `{ id: int, kind: "stand_behind", target_kind: string,
    target_id: int, created_at: ISO8601, context_note: string|null }`.
    Bounded ≤ 10 rows (the §J.1 max for any attestor including
    future graduated bonus). Ordered by `created_at ASC` so the
    picker shows the operator's oldest commitment first — Phillip's
    note: nudges "which has changed since I cast it" rather than
    "which is least valuable to keep." The FE resolves target
    display info (handle/name) via existing entity endpoints
    rather than threading display data through the error envelope.

### v1.12 — 2026-05-13

- **§4.20 Trust Attestations — Slice C mutation surfaces SHIPPED.**
  No new architecture or contract drift; the three endpoints land as
  locked. Implementation notes that callers MUST know about:
  - **`POST /me/attestations` response shape clarification.** The
    `attestor_summary` block emits the locked self-view fields
    `stand_behind_slots_used`, `_total`, `_graduated`, `is_dormant`,
    `reliability_standing`, `badges`, and the self-only
    `operator_reliability` / `consensus_reliability` /
    `early_read_accuracy` keys. V1 returns SLICE-E baselines for
    fields that depend on the synthesis layer: `_graduated = 0`,
    `is_dormant = false`, `reliability_standing = "newly_active"`,
    `badges = []`, and the three numeric reliability fields = `null`.
    The keys are PRESENT (contract-stable shape) — Slice E populates
    real values without a shape change.
  - **`decay.current_weight` equals `weight_at_time` at cast time**
    (decay age = 0). Slice E ships the read-time decay function;
    until then, `decay.current_weight` mirrors `weight_at_time` for
    any subsequent reads — additive behavior, no caller change.
  - **`bcc_attestation_bandwidth_exhausted` error: `slot_holders[]`
    is `[]` in V1.** The contract surface stays; V1 emits an empty
    array. The FE renders the locked "you're at your maximum, revoke
    one to free a slot" fallback message rather than a server-supplied
    picker. The picker payload lands when the `/me/attestations` list
    endpoint ships (Slice D — same query as the roster surface,
    scoped to the viewer).
  - **`error.data` is the structured-error vehicle.** The locked
    text "`error.unlock_hint`" in §J.2 (and analogous in the
    bandwidth-exhausted body) is implemented as `error.data.unlock_hint`
    / `error.data.slot_holders`. This matches the §1.4 envelope
    where structured error context lives under `error.data` — the
    parent envelope's `data` field rides every typed error payload.
    Callers branch on `error.code` per the §γ error-contract rule;
    they read aspirational copy from `error.data.unlock_hint` for
    `bcc_attestation_ineligible`. The FE's `humanizeAttestationError`
    helper in `AttestationActionCluster.tsx` surfaces the server's
    `unlock_hint` verbatim when present (single source of truth).
  - **Timestamp format.** All ISO 8601 UTC `Z`-suffixed strings
    (e.g. `"2026-05-13T12:34:56Z"`). The service converts the DB
    DATETIME (stored in WP site-local timezone via
    `current_time('mysql')`) to UTC at response time.
  - **`bcc_attestation_self` returns HTTP 422** (not 403). The
    self-target case is a request-shape error ("you can't attest on
    yourself"), not an authorization failure. Matches the §1.4
    "request validation" convention.
  - **Race safety.** Cast / revoke / reaffirm wrap in
    `TransactionManager::run` with per-attestor + per-target MySQL
    advisory locks (`bcc_attestation_a_$userId` /
    `bcc_attestation_t_$targetKind_$targetId`). Idempotency check
    uses `FOR UPDATE` on the unique-key range; locks release
    AFTER commit so waiters see the committed insert under any
    isolation level.
  - **§I1 event taxonomy extension.** Four new bell/push event
    types landed: `bcc_attestation_vouch_received`,
    `bcc_attestation_stand_behind_received`,
    `bcc_attestation_revoked`, `bcc_attestation_reaffirmed`. All
    four resolve to `/u/{actor_handle}` per the locked link rule;
    revoke uses the same link (the former attestor is the actor).
    Bell prefs default ON for all four; push prefs default ON per
    §I1 V2 baseline ("anti-noise carries the load via debounce").
    Per-attestor push tag (`bcc-attestation-vouch-{handle}` etc.)
    so multiple operators don't collapse into one OS-shell push.

### v1.11 — 2026-05-13

- **§4.20 Trust Attestations — consistency reconciliation pass.**
  Cleanup-only commit; no new architecture, no new mechanics. Wire
  changes:
  - **Removed `weight_at_time` and `decayed_weight` from public
    roster items** (`GET /entities/:target_kind/:target_id/
    attestations`). Per the §J.4.1 synthesis-invisibility
    invariant, per-attestation numeric weights are server-side
    only. Sorting by decayed weight remains supported via the
    `sort` query parameter (server-side sort, weights not
    returned).
  - **Removed `summary.divergence_signal`** from the roster
    response. The authoritative divergence surface is the entity
    view-model's `divergence_state` enum (§J.8 five-state
    classification); the roster summary had legacy parallel
    vocabulary that drifted from the locked model.
  - **Added missing `resolveLink` mappings** for
    `attestation_reaffirmed` → `/u/{attestor_handle}` and
    `stand_behind_renewal_nudge` → `/me/attestations`.
  - **§J.10 item 4 vocabulary updated** from legacy "contested
    variance" wording to the locked "polarizing divergence cutoff."
  - Constitution (`docs/trust-attestation-layer.md`) and risk
    assessment (`docs/trust-attestation-risk-assessment.md`)
    received parallel cleanup: removed the obsolete five-badge
    negative-signal model superseded by the §J.8 five-state
    synthesis; removed `Volatile` from the public Reliability
    Standing enumeration per the §J.3.2 asymmetric-display rule;
    aligned constitution onboarding Card 3 with the Phase 1 plan
    verbatim copy (load-bearing "absence is not a negative
    signal" teaching); aligned notification taxonomy to the 7-event
    list (added `attestation_reaffirmed` and
    `stand_behind_renewal_nudge`; dropped the stale
    `dispute_resolved` reference).

### v1.10 — 2026-05-13

- **§4.20 Trust Attestations — anti-centralization refinement pass
  + architectural freeze.** Final foundational amendment to the
  Trust Attestation Layer before Phase 1 implementation planning.
  Locks the anti-centralization mechanics, an explicit
  anti-viral-by-design constitutional principle, the synthesis
  invisibility invariant, and declares the architectural freeze.
  Contract deltas:
  - `attestor_summary` (self-only branch) gains
    `consensus_reliability` and `early_read_accuracy` numeric
    sub-tracks. Third-party branch unchanged — these never expose.
  - `attestor.badges` array added to attestor surfaces (in both the
    self-only and third-party branches). V1 catalogue:
    `highly_reliable` / `consistent` / `newly_active` / `early_read`
    — asymmetric-display rule preserved (no negative badges in the
    catalogue at all).
  - Attestation roster items gain `is_pre_consensus_pick` boolean
    and `attestation_order` integer (the original position of this
    attestation among stand-behinds on the target). Lets the UI
    visibly mark first-movers in the roster without exposing the
    underlying early-conviction multiplier math.
  - Synthesis mechanics — Elite-tier weight cap (40% aggregate
    contribution to a single entity's Reputation Score),
    diversity multiplier (1.3× for cross-network attestations),
    early-conviction multiplier (2.5× → 1.5× → 1.0× → 0.5×
    gradient on Stand Behind reliability credit) — are
    **server-side only.** No API field exposes any of these
    mechanisms; clients see only the synthesis *outputs*
    (Reputation Score, Reliability Standing, badge list,
    divergence state).
  - "Discovery Specialist" terminology pressure-tested and
    renamed to **"Early Read"** — intelligence-oriented register
    rather than achievement-system framing. Internal field names:
    `early_read_accuracy` (sub-track metric), `early_read`
    (badge enum value).

### v1.9 — 2026-05-13

- **§4.20 Trust Attestations — failure-mode refinement pass.** The
  Phase 1-blocking pressure test on four risk areas (slot rigidity,
  reliability visibility, controversy as signal, 60-second
  comprehension) lands as constitutional amendments to
  `docs/trust-attestation-layer.md` and the corresponding wire-level
  changes here. Key contract deltas:
  - `attestor_summary` shape gains `stand_behind_slots_graduated`
    (operator-self-only) and `is_dormant` (drives the
    activity-gated display rule per §J.1).
  - `operator_reliability` numeric field renders ONLY when the
    requesting operator is querying their own state — the
    asymmetric-public-display rule (§J.3.2) forbids exposing the
    number to third parties.
  - `negative_signals.contested` boolean + `divergence_signal`
    string collapsed into a single `divergence_state` enum
    (`untested` / `well_regarded` / `poorly_regarded` /
    `polarizing` / `disputed`). The five-state synthesis
    distinguishes "broadly bad" from "genuinely polarizing,"
    surfacing controversy as intelligence rather than just
    warning.
  - `polarizing` classification requires divergence among
    high-reliability attestors only — substantive-divergence
    antibody against brigading-via-disagreement.
  - New endpoint: `POST /me/attestations/:id/reaffirm` — soft-
    renewal one-tap path driven by the §J.1
    `stand_behind_renewal_nudge` notification.
  - §I1 notification taxonomy extends with `attestation_reaffirmed`
    and `stand_behind_renewal_nudge`. The `reliability_threshold_
    crossed` event becomes asymmetric (push on positive cross,
    bell-only on negative cross — losing a positive badge isn't
    public stigma).

### v1.8 — 2026-05-13

- **§4.20 Trust Attestations (§J)** — new section. Locks the wire-level
  contract for the Trust Attestation Layer (foundational product
  architecture, full design in `docs/trust-attestation-layer.md`). Three
  V1 primitives: `vouch` (abundant, low/medium conviction), `stand_behind`
  (scarce, tier-scaled bandwidth slots — Elite 7 / Trusted 5 / Neutral
  3 / Caution+Risky 0), `dispute` (formal adversarial). Endpoints:
  `POST /me/attestations`, `DELETE /me/attestations/:id`,
  `GET /entities/:target_kind/:target_id/attestations`,
  `GET /me/reliability`. Entity view-model gains `reputation_score`
  (cosmetic rename of `trust_score`, dual-emitted for one release
  cycle), `reliability_standing`, `attestation_summary`,
  `negative_signals` (derived: `under_review`, `contested`, `volatile`,
  `unresolved_claims_count`, `divergence_signal` — no human-cast
  downvote primitive). §I1 notification taxonomy extends with five
  trust-event types. `ContentReportService::TARGET_KINDS` extends to
  `['feed_item', 'user_profile', 'validator_card', 'project_card',
  'creator_card']`. Existing `bcc_endorsements` rows migrate as
  `kind=vouch, target_kind=*_card`; existing `KIND_VOUCH` /
  `KIND_STAND_BEHIND` post reactions freeze at Layer 0 with zero
  trust-graph weight. Phase 1 implementation gates on a separate
  scope-frozen plan.

### v1.7 — 2026-05-09

- **Security fix — closed/secret/NFT-gated PeepSo group post bodies no
  longer leak into `/feed`, `/feed/hot`, or `/users/{slug}/activity`
  for non-members.** The §F3 single-brain feed pipeline now applies a
  SQL-level group-exclusion filter mirroring the existing
  `excludedAuthorIds` (§O4.1 caution/risky shadow-limit) and
  `excludedActIds` (§K1-C moderation hide) channels. `FeedRankingService`
  computes `excludedGroupIds = (non-open group ids) - (viewer
  membership ids)` and forwards it to bcc-core's
  `PeepSoActivityRepository::getActivities`, which appends a
  `LEFT JOIN postmeta gx_pm ON gx_pm.post_id = p.ID AND gx_pm.meta_key
  = 'peepso_group_id'` plus `WHERE (gx_pm.meta_value IS NULL OR
  gx_pm.meta_value NOT IN (...))`. Non-group posts pass through (LEFT
  JOIN preserves them with NULL); only posts inside excluded groups
  drop. The IN list is bounded at 500. Anonymous viewers see no posts
  from any non-open group; authed viewers see their own group posts.
  `bcc_core:groups` cache group, generation key `non_open_gen`, busted
  on any `peepso_group_privacy` post-meta write (added/updated/deleted
  hooks). The per-row `hydrateCommentCounts` membership gate is
  retained as defense-in-depth (a single bad caller path that
  bypasses the SQL exclusion would still see comment counts zeroed).
- **§4.14 Photo posts + §4.15 GIF posts — new optional `group_id`
  field.** When present and > 0, the post lands inside that PeepSo
  group's wall (server stamps `peepso_group_id` post-meta on the new
  wp_post + fires `peepso_groups_new_post` for PeepSo's group-followers
  notification fan-out + popular-posts cache invalidation). Viewer MUST
  be an active member: missing-or-secret-non-member returns 404
  `bcc_not_found` (defense-in-depth — never leaks existence),
  open-or-closed-non-member returns 403 `bcc_permission_denied` with
  the server-pinned unlock hint in `error.message` (filterable via
  `bcc_group_post_membership_required`). Omit/0 → posts to viewer's
  own wall (existing behavior). Same field added implicitly to
  `POST /bcc/v1/posts` (status JSON body, `kind=status`) — covered by
  the same validation matrix; `kind=blog + group_id > 0` returns 400
  `bcc_invalid_request` (V1 scope-fence: long-form blogs target the
  author's own wall only). The optional `visibility` enum
  (`members_only` | `public_group` | `public_all`, default
  `members_only`; see §4.14 / §4.15 / §4.3) rides the same three
  status / photo / GIF write paths and is only honored when
  `group_id > 0` and `kind=status` (ignored on own-wall posts and for
  `kind=review`/`kind=blog`); stored as `_bcc_post_visibility`
  post-meta. Implementation: `PostsService::gateGroupPost`
  reuses `GroupsService::resolveGroupAccess` (single source of truth
  for the group-existence + active-membership decision across §4.7.5,
  §4.7.6, §4.7.7, and now write paths); `PeepSoStatusWriter::attachToGroup`
  is the canonical post-meta + action seam for all three writers
  (status, photo, GIF).
- **`PeepSoGroupRepository::getActivityHeat` JOIN/module-id fix.** The
  prior implementation joined `p.ID = a.act_id` (wrong column —
  `act_id` is the activity PK, not a wp_posts.ID) and filtered
  `act_module_id = 8` (PeepSoGroups system events: banner-changed /
  group-created — NOT user content posts). Both bugs together meant
  the query returned zero rows for every group, so every surface
  silently emitted `heat: cold` / "Quiet" regardless of real activity.
  Fixed to mirror the postmeta-JOIN pattern from
  `PeepSoActivityRepository::getActivities` `$onlyForGroupId` branch:
  `INNER JOIN wp_posts p ON p.ID = a.act_external_id` plus
  `INNER JOIN wp_postmeta pm ON pm.post_id = p.ID AND pm.meta_key =
  'peepso_group_id' AND pm.meta_value IN (...)`, GROUP BY
  `pm.meta_value`. Single source of truth for "activities inside a
  PeepSo group" across discovery sort, holder-groups suggestion, and
  group detail/feed surfaces.

### v1.5 — 2026-05-05

- **§3.3 FeedItem — `group` block (Path A: verification metadata, no
  server-side ranking change).** Feed items from PeepSo group wall
  posts now carry `group: { id, type, verification }` so the frontend
  can render an "On-Chain Verified" badge per item. Field is **omitted**
  for non-group posts. Ranking remains recency-only; a scored layer is
  deferred until usage telemetry justifies the tuning. Hydrated via
  `peepso_group_id` post-meta → `GroupContextResolver::forManyGroups`
  in `FeedRankingService::hydrateGroupContexts`. Applies to
  `/feed/hot`, `/feed`, and `/users/:handle/activity`.
- **§4.7.1 Holder Groups** — new section. NFT-gated PeepSo groups
  (closed privacy + server-side eligibility gate; defense in depth):
  - `GET /me/holder-groups` — joined / eligible_to_join / opted_out
    buckets, each item carrying `verification` + `activity` blocks
    (heat / posts_last_7d / last_activity_at) so the frontend can
    render heat indicators and avoid ghost-town suggestions.
  - `POST /me/holder-groups/{id}/join` — explicit user-initiated join.
    Verifies eligibility server-side (HoldingsService), clears any
    active opt-out, lands the user as `member` regardless of the
    group's closed flag.
  - `POST /me/holder-groups/{id}/leave` — records a TTL'd opt-out
    (default 90d) so the reconcile sweep doesn't re-add the user.
    Refuses owners with `bcc_permission_denied`. Mod-driven removals
    (PeepSo UI ban) record permanent opt-out (timestamp 0).
  - `GET / PATCH /me/holder-groups/preferences` — `auto_join` toggle.
    Default `false`. PATCH-to-true reconciles synchronously and
    returns the immediate join count; the user doesn't wait for the
    next cron tick.
- **§4.7.2 Profile Groups Tab** — `GET /users/{slug}/groups`. Cross-
  kind list (holder + Local + plain user + system) via
  `GroupContextResolver`. Viewer-aware `permissions.can_join` /
  `can_leave` per §A4 / §N7 — secret groups filter server-side,
  closed groups visible with content gated at PeepSo's layer. Action
  URLs vary by `type`.
- **§4.7.3 Plain Group Membership** — `POST /me/groups/{id}/{join|leave}`
  for the residual case (non-gated, non-Local user/system groups).
  Closed/secret writes are rejected with hints — PeepSo's
  request-flow / invitation machinery is not replicated.
- **§4.7.4 Groups Discovery** — `GET /groups`. Sort key
  `verified DESC, heat DESC, member_count DESC`. `?verified=1` filters
  to On-Chain Verified groups for the discovery filter chip. Privacy
  excludes secret groups.
- **Shared shapes** locked: the `verification` block (server pins
  copy as "On-Chain Verified" — frontend MUST NOT abbreviate to
  "Verified"), the `activity` block (heat thresholds filterable via
  `bcc_group_heat_thresholds`), and the `actions` / `permissions`
  pattern for cross-kind group items.
- **Mapping notes** call out canonical seams: `GroupContextResolver`,
  `GatedGroupRepository`, `NftGroupGateService`, `GatedGroupProvisioningService`,
  `HoldingsService::ownsAnyMany`, `PeepSoGroupRepository::getActivityHeat`.
  See [docs/pattern-registry.md](pattern-registry.md) for the full
  list.
- **Cold-start design** documented inline: suggest-don't-auto-join
  is the default model, the `activity.heat` field is the user's
  escape hatch from ghost-town suggestions, discovery sort is
  verified-first-but-active-beats-sleepy.

### v1.4 — 2026-05-03

- **§4.12 Self-edit (V2 Phase 2 + 2.5)** — new section. Locks the
  /settings/profile mirror surface introduced in V2 Phase 2.5:
  - `GET / PATCH /me/profile/fields[/{key}[/visibility]]` — admin-configured
    PeepSo profile-fields catalogue with per-field value + visibility
    editing. Delegates to `PeepSoField::save` / `save_acc` so PeepSo's
    own search index and profile-completeness counter stay coherent.
  - `GET / PATCH /me/profile-prefs` — profile-wide `usr_profile_acc`
    (PeepSo's user-search join key), wall-post default, hide-birthday-year.
  - `PATCH /me/account/email`, `PATCH /me/account/password`,
    `DELETE /me/account` — every route re-verifies `current_password`;
    no session-elevation flag. Account deletion mirrors PeepSo's
    `site_registration_allowdelete` toggle.
- **§4.12 also backfills** the V2 Phase 2 endpoints that shipped earlier
  but weren't in the contract doc (`PATCH /me/profile`, avatar/cover
  routes, `/me/messages-prefs`, `/me/notification-prefs`) for symmetry.

### v1.3 — 2026-04-29

- **§3.1 / §4.4 — User view-model expanded with profile-page extras.**
  The /users/:handle response now carries `user_id` (alias of `id`),
  `bio_block` (paragraph + signature_line + is_editable triple,
  composed server-side from the §3.1 plain `bio` string), `card`
  (full §3.2 Card view-model for the hero render), `standing`
  (good-standing ribbon facts), `identity_meta` (strip facts),
  `stats` (platform-tagged stats strip), `shift_log`
  (`{days, summary, month_ticks}`), `activity_breakdown` (five-bucket
  §N9 totals), `live_shift` (recent-activity events for the hero
  panel), and `tabs` (count strip). `reviews` and `disputes` are
  emitted as empty arrays in V1 — lazy-load list endpoints for those
  tabs ship in V1.5. Composition lives in `MemberProfileComposer`,
  layered atop `UserViewService::getUser` (which still returns the
  basic shape for surfaces that don't need the rich extension —
  feed authors, search suggestions, etc.).
- **§3.1 — `bio` stays a plain string.** The profile-page rich shape
  lives in `bio_block` alongside it, NOT replacing it. Simple readers
  (the §3.1 contract page renderer, search snippets) keep using `bio`;
  rich consumers (member-bio paper-sheet component, identity strip)
  read `bio_block`. Avoids breaking the contract while letting the
  profile page render verbatim per §A2.
- **§2.4 LivingBlock** — added `recent_impact` (server-rendered
  one-line headline) and `rank_progress` (`{current_rank, next_rank,
  percent, remaining_label}`). Marked `streak_at_risk_today`,
  `today.vouches_received`, and `today.pulls` optional — the V1
  server build emits the wired set; the optional fields land as their
  source aggregators (D5 reaction merger, C3 batch counter) ship.

### v1.2 — 2026-04-29

- **§4.9 Directory** — new section. Locks the `GET /cards` (paginated browse) and `GET /cards/search` (autocomplete) contracts shipped for §G1/§G2. Both endpoints return canonical card-shaped view-models per §A2/§L5; the search endpoint wraps bcc-search server-side so the frontend never sees reputation_tier or category_slug.
- **§4.10 Notifications** — new section. Locks the §I1 contract: `GET /me/notifications`, `GET /me/notifications/unread-count`, `POST /me/notifications/mark-read`. Storage layer is `peepso_notifications` scoped to `BCC_NOTIFICATION_MODULE_ID = 9000` (single-graph rule). Notification view-models carry server-rendered messages + server-built `link` per §A2. V1 catalogue: `bcc_reaction`, `bcc_review`, `bcc_card_watched` (legacy alias `bcc_card_watched` per §1.1.1), `bcc_rank_up` — @mentions and follow-posts deferred to V1.5.
- **§4.11 Celebrations** — new section. Locks the §O1.2 out-of-band delivery path: `GET /me/celebrations/pending`, `POST /me/celebrations/consume`. Single-slot stash per user (last-write-wins). Today only `RankProgressionListener` produces; `level_up` and `tier_upgrade` kinds are reserved on the wire so future producers slot in without contract changes.
- **§2.3 `Celebration`** updated to acknowledge both delivery paths (inline on write responses for sync triggers; out-of-band via §4.11 for async-subscriber triggers like rank-up). Same shape on both paths so the frontend toast is path-agnostic.
- **§8 Out of scope** — the line "Notification endpoints — Phase 7" removed (notifications shipped in V1). Replaced with two narrower deferrals: email digest and per-event preferences (both V1.5 per §I1), and @mentions (composer v2).
- **§8.2 Registered and fully wired in V1** — directory, notifications, and celebrations added. The `bcc_reaction_added` / `bcc_reaction_removed` events were added to `ReactionsEndpoint` as part of the notifications work (the only event the §A3 catalogue was missing).

### v1.1 — 2026-04-29

- **§1.1 Base URL & versioning** — documented the two-namespace split. `/wp-json/bcc/v1/` is the cross-plugin read API (plus the dispute/flag/claim mutation surface consumed by other plugins and the headless frontend); `/wp-json/bcc-trust/v1/` is the trust-engine-internal mutation namespace (vote, endorse, revoke, user status, admin stats, OAuth). Convention had always been split — the contract previously claimed "all endpoints under `/bcc/v1/`" and that was inaccurate.
- **§8 Out of scope** restructured into three sub-sections to match what V1 actually ships:
  - **§8.1 Registered with stub data** — `GET /me/highlights` and `POST /me/highlights/:id/dismiss` moved here from §8. Endpoints are live; the dismissal pipeline is fully implemented; the slot scorers (negative / positive / external) stub to null in production until their underlying aggregators land.
  - **§8.2 Registered and fully wired in V1** — new section. Composer (`POST /posts`, `POST /reactions`, plus `DELETE /me/reviews/:id` and `DELETE /reactions/:feed_id`) and Onboarding (`POST /auth/signup`, `GET /onboarding/suggestions`, `PATCH /me/handle`, `POST /me/onboarding/complete`, `GET /me/onboarding/status`) moved here from §8. These are first-class V1 surfaces with real data — the §8 listing was a stale planning artifact.

### v1.0 — 2026-04-27

Initial Phase 1 deliverable. Ten open contract decisions resolved (see §7).

---

**End of contract.**
