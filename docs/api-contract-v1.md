# BCC API View-Model Contract — V1

**Status:** Draft v1.23 · 2026-05-26 · Phase 1 deliverable
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
| `/wp-json/bcc/v1/` | Shared cross-plugin **read** API + cross-plugin mutations consumed by blocks, bcc-search, and the headless frontend | `GET /page/{id}`, `GET /feed`, `GET /users/:handle`, `POST /disputes`, `POST /flag`, `POST /claim` |
| `/wp-json/bcc-trust/v1/` | Trust-engine-internal **mutations** (vote/endorse/revoke), user status, admin stats, OAuth callbacks | `POST /vote`, `POST /endorse`, `POST /remove-vote`, `POST /revoke-endorsement`, `GET /user/status`, `GET /github/*`, `GET /x/*` |

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
| Transient | `bcc_rate_limited`, `bcc_upstream_unavailable`, `bcc_internal` | Yes, with backoff |
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
| `bcc_internal` | 500 | Transient | Unhandled server error — never exposes internals |
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
    "can_pull":             { "allowed": true,  "unlock_hint": null },
    "can_review":           { "allowed": false, "unlock_hint": "Reach Level 2 (5 pulls + 3 Floor visits) to write reviews." },
    "can_dispute":          { "allowed": false, "unlock_hint": "Link a wallet and reach Level 3 to sign disputes." },
    "can_vouch":            { "allowed": false, "unlock_hint": "Reach Level 2 to use the Vouch reaction." },
    "can_stand_behind":     { "allowed": false, "unlock_hint": "Reach Level 2 to use the Stand-behind reaction." },
    "can_post_as_entity":   { "allowed": false, "unlock_hint": null },
    "can_edit_bio":         { "allowed": false, "unlock_hint": null },
    "can_attach_card":      { "allowed": true,  "unlock_hint": null },
    "can_open_dispute":     { "allowed": false, "unlock_hint": "Reach Level 3 to open disputes." }
  }
}
```

**Rules (§N7):**

- Every gate the viewer might *eventually* unlock is listed, even when `allowed: false`. Hidden gates teach nothing; visible gates teach the system.
- When `allowed: false`, `unlock_hint` is a **plain-English explanation** the frontend renders verbatim as a tooltip/disabled-button helper.
- When `allowed: true`, `unlock_hint` is `null` (not omitted — explicit `null` so client typing is uniform).
- When a gate is **structurally impossible** for this viewer (e.g., viewing your own card → `can_pull: false`, you can't follow yourself), `unlock_hint` is `null` and the frontend hides the action UI per §N7's "structurally impossible" carve-out (the always-visible rule applies to *gates a viewer could resolve*, not to nonsensical actions).

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
    "current_rank": "apprentice",
    "current_rank_label": "Apprentice",
    "next_rank": "journeyman",
    "next_rank_label": "Journeyman",
    "next_rank_thresholds": [
      { "metric": "reviews_written", "label": "Reviews", "current": 8,  "required": 20 },
      { "metric": "days_active",     "label": "Days active", "current": 41, "required": 90 }
    ],
    "trust_score_recent_changes": [
      { "delta":  1, "reason": "Governance vote", "at": "2026-04-22" },
      { "delta":  2, "reason": "Uptime streak (14d)", "at": "2026-04-15" },
      { "delta": -1, "reason": "Dispute lost", "at": "2026-04-10" }
    ]
  }
}
```

**Rules:**

- `next_rank` is `null` when the user is at the highest rank reachable through auto-promotion (Journeyman).
- For ranks above Journeyman (Foreman+, admin-conferred per §E2), `next_rank` stays `null` even though higher ranks exist — auto-promotion is the only progression path the user can drive themselves.
- `next_rank_thresholds` always has all metrics; the frontend renders the `current/required` ratio for each as a progress bar.
- `trust_score_recent_changes` is the most recent 5 events (sorted desc by `at`). Reason strings are plain English, ≤ 80 chars.

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
      "stand_behind_reaction":{ "allowed": true,  "unlock_hint": null },
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
| `"trust"`  | `review`, `dispute_signed`, `page_claim`, `project_drop`, `nft_drop`, `signal` | `solid`, `vouch`, `stand_behind` | restrained, intentional |
| `"social"` | `status`, `watch_batch` (legacy `pull_batch`), `blog_excerpt` | `like`, `love`, `haha`, `wow`, `fire` | expressive, emoji-forward |
| `"tribal"` | _(reserved — V2)_ | _(reserved — e.g. `same_wallet`, `onchain_confirm`)_ | identity-forward |

`counts` always carries all kinds for the active grammar with zero-fill. The frontend never derives the kind set from `post_kind` — it reads `kind_grammar`.

**Shape (trust grammar):**

```json
{
  "reactions": {
    "kind_grammar": "trust",
    "counts": {
      "solid":        14,
      "vouch":         3,
      "stand_behind":  1
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
    "binder_size": 38,
    "reviews_written": 8,
    "disputes_signed": 1,
    "solids_given": 240,
    "solids_received": 117
  },
  "privacy": {
    "watching_hidden": false,
    "binder_hidden": false,
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
    "binder":    "/u/simontx/binder",
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
- `privacy` block reduced to `{ watching_hidden, reviews_hidden, ... }` reflecting what the viewer can see, not what's set. Legacy `binder_hidden` is emitted alongside `watching_hidden` during the §1.1.1 deprecation window.
- `permissions.can_follow` becomes meaningful (`allowed: true` if the viewer can watch this user as a member card).
- `wallets` returns `address_short` only (never full addresses for others — the privacy floor).

**Field rules:**

- `trust_score`, `reputation_tier`, `card_tier`, `tier_label`, `rank`, `rank_label`, `is_in_good_standing`, `flags` — all derived per §A4 by `bcc-trust`. Frontend renders, never derives.
- `card_tier` follows §C1 mapping: `elite → legendary`, `trusted → rare`, `neutral → uncommon`, `caution → common`, `risky → null` (hidden from card UI per §C1).
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
  "card_tier": "legendary",
  "tier_label": "Legendary",
  "rank_label": null,
  "is_in_good_standing": true,
  "flags": [],
  "is_claimed": true,
  "claim_target": null,
  "viewer_has_reviewed": false,
  "viewer_has_endorsed": false,
  "endorse_unlock_hint": null,
  "chains": null,
  "crest": { "...": "see §2.9" },
  "stats": [ "...": "see per-kind below" ],
  "social_proof": { "...": "see §2.2" },
  "permissions": { "...": "see §2.1" },
  "links": { "...": "see §2.10" },
  "actions": { "...": "see §3.2.5" }
}
```

**Field rules:**

- `card_kind` ∈ {`validator`, `project`, `creator`, `member`}.
- `is_claimed` is meaningful for `validator` / `project` / `creator`. For `member`, `is_claimed: true` always (members are their own pages).
- `claim_target` (per §N8) — non-null only when the page is unclaimed AND a claim target resolves. Drives the four-step claim modal.
- `chains` (per §K3) — list of `CardChain` objects when 2+ chains back the same page; `null` otherwise. V1.5 validator-only; creator gallery filter is V2.
- `card_tier` may be `null` only when the entity is risky-tier (per §C1) — and in that case the entity should not appear in card UIs at all. If a card response returns `card_tier: null`, the frontend renders nothing visible (treat as a 404 from the UI perspective).
- `permissions.can_watch.allowed` is `false` when (a) viewer is anonymous, (b) viewer is the card subject (you can't follow yourself), or (c) the card is hidden (risky tier). In cases (a) and (c), `unlock_hint` is `null` — these aren't hints the user can resolve. (Legacy permission key `permissions.can_pull` is emitted alongside `can_watch` during the §1.1.1 deprecation window.)
- `viewer_has_reviewed` / `viewer_has_endorsed` (per §D2 / §V1.5) — drives "WRITE A REVIEW" → "REMOVE YOUR REVIEW" CTA swaps. Always `false` for anonymous viewers and on member cards.
- `endorse_unlock_hint` mirrors `permissions.can_endorse.unlock_hint` so the EndorseButton can render the hover hint without reaching into the permission object.
- `watching_size` (on member cards' stats and on `User.counts.watching_size`) **counts member follows alongside entity follows.** Watching another member is a first-class watchlist action, no separate `following_count` field exists. Legacy alias `binder_size` is emitted alongside `watching_size` during the §1.1.1 deprecation window.

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
  { "key": "endorsements", "label": "Endorsements", "value": "12",  "format": "count", "raw": 12 }
]
```

**3.2.2 Member card stats — V1:**

```json
"stats": [
  { "key": "trust",           "label": "Trust",   "value": "78", "format": "score", "raw": 78 },
  { "key": "reviews_written", "label": "Reviews", "value": "8",  "format": "count", "raw": 8 }
]
```

**3.2.3 Per-kind stat expansion — Deferred to V1.5:**

When on-chain meta is wired (per §K3 chain support and §H1 indexer), entity cards expand to per-kind stat shapes:

- **Validator:** `trust`, `uptime` (percent), `fee` (percent), `self_bonded` (currency_native), `delegators` (count), `voting_power` (percent)
- **Project:** `trust`, `stage` (text), `tvl` (currency_usd), `contributors` (count), `last_release` (text)
- **Creator:** `trust`, `pieces` (count), `collections` (count), `collectors` (count), `last_drop_at` (duration)
- **Member:** add `rank` (text), `watching_size` (count, legacy alias `binder_size`), `primary_local` (text)

V1 frontend types declare `stats: CardStat[]` (opaque array, no per-kind narrowing). Adding kind-specific stats in V1.5 is purely additive — no breaking change.
```

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

- Action keys are stable identifiers (`watch`, `claim`). Permission to invoke is in `permissions.*`; presence in `actions` does NOT imply the viewer is allowed (gate on `permissions.<key>.allowed`). During the §1.1.1 deprecation window the legacy key `pull` is emitted alongside `watch` with the legacy `/me/binder/pull` href.
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
    "is_operator": false
  },
  "body": { "...": "kind-specific" },
  "attached_card": { "...": "summary Card view-model, optional" },
  "reactions": { "...": "see §2.11" },
  "comment_count": 7,
  "social_proof": { "...": "see §2.2 — applies to feed posts per §O4" },
  "permissions": {
    "can_react":  { "allowed": true, "unlock_hint": null },
    "can_reply":  { "allowed": true, "unlock_hint": null },
    "can_share":  { "allowed": true, "unlock_hint": null },
    "can_report": { "allowed": true, "unlock_hint": null }
  },
  "links": {
    "self":   "/p/feed_98712",
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
- `external_id` is the module-specific FK (e.g. `wp_posts.ID` for status / blog / review backings, `bcc_pull_batches.id` for `watch_batch` posts (table name retains its legacy `pull_batches` form per §4.5.1), `bcc_onchain_claims.id` for `page_claim`, `0` for system-authored signals). Used by server-side hydrators and by the client as a stable React key. Treat as opaque.
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

**3.3.2 `review`** — D2 review of an attached entity:

```json
"body": {
  "grade": "A",
  "grade_label": "Strong",
  "summary": "Reliable through the last upgrade. Governance participation has been consistent.",
  "long_form": null
}
```

`grade` ∈ {`A`, `B`, `C`, `D`, `F`}. `long_form` is a long-form review body (≤ 2000 chars), null for short reviews.

**3.3.3 `watch_batch`** — C3 batched watches (legacy kind name `pull_batch` accepted during the §1.1.1 deprecation window):

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
- Batches close after exactly **10 minutes of watch inactivity**. At close, the server emits exactly one `watch_batch` FeedItem. The legacy `post_kind` value `pull_batch` is emitted on the deprecated `/me/binder/*` mutation path; the canonical `/me/watching/*` path emits `watch_batch`. Both serialize to the same `body` shape.
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
    "avatar_url":   "https://bluecollar.crypto/wp-content/uploads/2026/05/simontx-avatar.jpg"
  },
  "body":      "love this — finally a watchlist that respects watches.",
  "mentions":  [],
  "posted_at": "2026-05-06T14:09:33Z",
  "permissions": {
    "can_delete": { "allowed": true, "unlock_hint": null }
  }
}
```

**Field rules:**

- `id` and `comment_id` are the same opaque identifier (the duplicate `comment_id` is for symmetry with §4.13 DELETE which takes the id as a path param). Form: `comment_<int>`. Treat as opaque.
- `feed_id` echoes the **parent post's** feed_id, not the comment's own — useful for re-resolving the parent if the drawer was deep-linked. Form: `feed_<int>`.
- `body` is server-sanitized (PeepSo's `htmlspecialchars` + `strip_content`); the frontend SHOULD render plain text only and respect newlines but NOT trust HTML entities. The raw wire format `@peepso_user_<id>(name)` may appear in `body`; clients MUST overlay `mentions[]` to render those tokens as `<Link href="/u/:handle">@displayName</Link>` — see §3.3.12 invariant.
- `mentions` is the §3.3.12 `Mention[]` overlay extracted from the raw `body`. Range offsets reference raw stored content. Always present (`[]` when no mentions). V1d does NOT ship the comment-composer autocomplete picker — the array is still populated for any wire tokens authored via PeepSo's native UI or hand-typed by power users.
- `posted_at` is ISO-8601 UTC.
- `permissions.can_delete.allowed` is `true` only when the viewer is the comment's author. V1 does not support cross-author or admin moderation deletes through this endpoint.

**V1 deferred:**

- **Threading.** PeepSo's storage is flat at the (act_comment_object_id) index; replies-in-replies in PeepSo's UI surface as @-mentions in body. V1 lists comments flat; surfacing reply-context is V1.5+ work.
- **Per-comment reactions.** No reaction rail on individual comments. Reactions remain on the parent post only. V2.
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

**Supported `chain_type` values:** `evm` (MetaMask / any EIP-1193 EVM wallet), `solana` (Phantom), `cosmos` (Keplr — ADR-036 secp256k1; covers Cosmos Hub / Osmosis / Injective / Juno / Stargaze / THORChain), `polkadot` (Polkadot.js / Talisman / SubWallet / Nova — sr25519 default, ed25519 / ecdsa accepted). Polkadot signature verification is delegated to the bcc-frontend Next.js app's `@polkadot/util-crypto` via an internal authenticated route (PHP has no native schnorrkel); same trust domain, same `WalletVerifier::verify` surface to callers.

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
  - `social_proof` ← join over `peepso_follower` + `peepso_reactions` filtered by viewer's network and trust-weighted (§O4.1)

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
    - `following` → strict-time-ordered, posts attributed to entities/users in the viewer's `peepso_follower` set, signal kinds excluded
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

#### Server-side group-privacy filter (applies to both `/feed` and `/feed/hot`)

Posts authored inside non-open PeepSo groups (`peepso_group_privacy ∈ {1, 2}` — closed or secret, including NFT-gated holder groups, which are closed + sidecar-marked) the viewer is NOT a member of are dropped from the candidate set at the SQL layer. Anonymous viewers see no posts from any non-open group. Members of a non-open group continue to see that group's posts in their main feed.

The filter mirrors the existing `excludedAuthorIds` (§O4.1 caution/risky shadow-limit) and `excludedActIds` (§K1-C moderation hide) channels: `FeedRankingService` computes `excludedGroupIds = (non-open group ids) - (viewer membership ids)` and forwards it to `bcc-core`'s `PeepSoActivityRepository::getActivities`, which appends a `LEFT JOIN postmeta gx_pm ON gx_pm.post_id = p.ID AND gx_pm.meta_key = 'peepso_group_id'` plus `WHERE (gx_pm.meta_value IS NULL OR gx_pm.meta_value NOT IN (...))`. Non-group posts pass through (the LEFT JOIN preserves them with NULL); only posts inside excluded groups drop. The IN list is bounded at 500 (matching the candidate-pool cap on `getNonOpenGroupIds`).

Defense-in-depth: the per-row `hydrateCommentCounts` membership gate is retained — a single bad caller path that bypasses the SQL exclusion would still see comment counts zeroed for gated-group items. The two layers compose; neither is sufficient alone.

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
  - `progression` ← `bcc_user_ranks` + `bcc-trust` threshold service
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

Paginated directory of human members. Sibling to §4.9 `/cards` (entity-card directory). Slim list-shape — drops the heavy blocks `/users/:handle` carries (counts, locals, wallets, permissions, privacy, viewer_blocking, plus self-only `living`/`progression`/`feature_access`/`ux_helpers` bundles). Click-through navigates to `/u/:handle` for the full profile.

- **Auth:** Anonymous OR Bearer (privacy-filtered — `real_name_hidden` honored)
- **Query:** `page` (1-indexed, default 1), `per_page` (default 20, max 50), `q` (optional — bounded to 64 chars, matched against `user_login` + `display_name` + `user_nicename`), `type` (optional — one of `validator | project | nft | dao`; restricts results to users with ≥1 owned page of that canonical type, intersecting with `q` when both are present)
- **Response 200:**
  ```json
  {
    "items": [
      {
        "id": 42,
        "handle": "phillips",
        "display_name": "u_phillips",
        "avatar_url": "https://bluecollar.crypto/wp-content/uploads/peepso/users/42/abc123-avatar.jpg",
        "joined_at": "2026-04-12T18:30:00Z",
        "card_tier": "uncommon",
        "tier_label": "Uncommon",
        "rank_label": "Journeyman",
        "is_in_good_standing": true,
        "flags": [],
        "trust_score": 78,
        "followers_count": 42,
        "primary_local": {
          "id": 1138,
          "slug": "local-34-brooklyn",
          "name": "Local 34 — Brooklyn",
          "number": 34
        },
        "owned_pages_count": 2,
        "owned_pages_by_type": {
          "validator": 1,
          "project": 1,
          "nft": 0,
          "dao": 0
        },
        "cover_photo_url": "https://bluecollar.crypto/wp-content/uploads/peepso/users/42/abc123-cover.jpg",
        "verifications": {
          "x_verified": true,
          "x_username": "phillips_eth",
          "github_verified": true,
          "github_username": "phillips",
          "wallets_verified": 2
        },
        "engagement": {
          "endorsements_received": 17,
          "solids_received": 38,
          "reviews_written": 12,
          "disputes_signed": 3
        }
      }
    ],
    "pagination": { "page": 1, "per_page": 20, "total": 124, "total_pages": 7 },
    "type_counts": { "validator": 5, "project": 5, "nft": 5, "dao": 2 }
  }
  ```
- **Errors:** `bcc_validation` (invalid `page` / `per_page`)
- **Cache:** `Cache-Control: private, max-age=15`; `Vary: Authorization, Cookie`
- **Pagination envelope:** offset (`OffsetPagination` per §1.5) — `total_pages` is the canonical field; clients derive "has more" as `page < total_pages`.
- **Mapping:** `WP_User_Query` ordered by `user_registered DESC`; results composed via `UserViewService::getSummary` (one call per user, but `UsersEndpoint::members` prefetches eleven batched maps — followers count, primary-Local resolution, owned-page count, owned-page typed counts, endorsements received, solids received, reviews written, disputes signed, verified-wallet count, X connections, GitHub connections — before the per-row loop, so the total query budget is bounded regardless of `per_page`). `card_tier` mirrors the §C1 slug (`legendary|rare|uncommon|common|null`); null only for risky-tier users (entity hidden from card UI per §C1). `tier_label` is the pre-rendered §A2 display string. Frontends should encode the tier as a color/border treatment on the rank chip rather than rendering `tier_label` as a duplicate word next to `rank_label`.
- **Field rules:**
    - `trust_score` ∈ [0, 100] per §D5. Augmented score = base reputation_score + clamped lifetime participation bonus (`DisputeParticipationRepository::getEarnedLifetimeTrust`). Clamped at the boundary; clients render as a stencil number, never derive.
    - `followers_count` is the passive side of `peepso_follower` (people who follow this user). The full /users/:handle response carries both `followers` and `following`; the directory ships the followers count only — `following` isn't a meaningful directory signal and the second SQL isn't worth the cost.
    - `primary_local` shape matches `MemberProfile.primary_local`. `number` is parsed from `name` via the `^Local\s+(\d+)\b` convention; null when the title doesn't follow the pattern. Frontends render display strings client-side from `name`/`number`.
    - `owned_pages_count` counts rows where `peepso_page_members.pm_user_status = 'member_owner'`. `> 0` indicates a builder/operator.
    - `owned_pages_by_type` is a per-canonical-type count of `member_owner` pages, derived from the PeepSo page-categories taxonomy (`peepso_page_categories` joined to the `peepso-page-cat` CPT). The four type keys (`validator`, `project`, `nft`, `dao`) are stable wire identifiers — decoupled from the underlying PeepSo category slugs (which are admin-controlled and may include legacy typos like `vaildators`). PeepSo pages are tag-shaped, not type-shaped: a single page can carry multiple categories, so the sum across the four buckets MAY exceed `owned_pages_count` for a multi-categorized portfolio. Conversely, pages with no recognized category contribute to `owned_pages_count` but to none of the typed buckets. Frontends should render one badge per non-zero bucket (`6 PROJECTS`, `5 NFT COLLECTIONS`, `1 VALIDATOR`) — `owned_pages_count` is informational. New canonical types require a contract amendment + a new key in the response shape; we don't fall back to an "OTHER" bucket for unrecognized categories.
    - `type_counts` is the **global** count of distinct users with ≥1 owned page per canonical type. Independent of the active `q` and `type` filters by design — the chip-strip's `VALIDATORS · 5` numbers shouldn't shift around as a viewer types in the search box. Same four keys as `owned_pages_by_type`. Always emitted (even on the type-empty short-circuit) so a filter-specific empty state can suggest alternative chips with non-zero counts.
    - `cover_photo_url` mirrors `MemberProfile.cover_photo_url` (PeepSo cover photo, absolute URL, `null` when no custom cover set). Drives the directory card's flippable front-face cover area. Frontends render a tier-tinted gradient fallback when `null` so cold-start accounts still get a presentable card.
    - `verifications` carries connection presence + provider username for the social-proof panel on the back of the directory card. `x_verified` / `github_verified` are `true` only when an active row exists in `bcc_trust_user_verifications` AND `verified_at` is non-null — token presence alone does not count. `x_username` / `github_username` are the public handles for click-through display (`@phillips` etc.); never decrypt tokens into this payload. `wallets_verified` is the count of `bcc_wallet_links` rows where `verified_at IS NOT NULL` — the per-wallet detail (chain, address) lives on `MemberProfile.wallets`.
    - `engagement` carries lifetime activity counts for the back-of-card "ON THE FLOOR" panel. `endorsements_received` is summed across every page the user owns (`peepso_page_members.pm_user_status = 'member_owner'` JOINed to `bcc_trust_endorsements` on `page_id`); a multi-page operator's endorsement count is the union of endorsements on all their pages. `solids_received` counts `peepso_reactions` rows of kind `KIND_SOLID` on activities the user owns; returns 0 when the reaction set isn't seeded yet (`ReactionTypeRegistry::solidId() === null`). `reviews_written` mirrors `MemberCounts.reviews_written` (count via `VoteRepository::countByVoter`). `disputes_signed` mirrors `MemberCounts.disputes_signed` (count via `FlagsRepository::countByFlagger`).

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

### 4.5 Watching

> **2026-05-13 rename:** these routes replaced `/me/binder/*` under the §1.1.1 additive-deprecation pattern. The legacy routes remain available with `Deprecation`/`Sunset` headers for one release — see §4.5.1.

#### `GET /bcc/v1/me/watching`

Returns the viewer's watchlist (§C2 — UI projection of PeepSo follows + `bcc_pull_meta` sidecar). The watchlist is the canonical name for what was formerly the "binder."

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
- **Mapping:** JOIN of `peepso_follower` + `bcc_pull_meta` (§C2). Removed cards (unwatched) do not appear. `card_tier_at_watch` is the `card_tier` (`legendary|rare|uncommon|common|null`) at the moment the card was watched, not the current tier — preserved for historical narrative. `tier_label_at_watch` is the pre-rendered display string per §A2. The underlying storage table retains its legacy physical name `bcc_pull_meta` — see `docs/database-schema.md` for the storage-vs-logical naming note.

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

Starts watching a card (= creates a PeepSo follow + `bcc_pull_meta` row).

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
  - Side effects: PeepSo follow created, `bcc_pull_meta` row inserted, `bcc_card_watched` event emitted (§A3 async — request returns before subscribers run). For release N back-compat the server ALSO emits the legacy `bcc_card_pulled` event with the same payload; removed in release N+1.
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
- **Mapping:** PeepSo unfollow + cascade `bcc_pull_meta`. **Does not** edit any prior feed post per §C3.

### 4.5.1 Deprecated: `/me/binder/*` (removed in release N+1)

Legacy routes kept alive for one release under the §1.1.1 additive-deprecation runway. They return **identical** payloads to their `/me/watching/*` counterparts, with the following back-compat aliases:

- `watched_at` ↔ legacy `pulled_at`
- `card_tier_at_watch` ↔ legacy `card_tier_at_pull`
- `tier_label_at_watch` ↔ legacy `tier_label_at_pull`
- `watching_size` ↔ legacy `binder_size`
- `already_watching` ↔ legacy `already_in_binder`
- Celebration `label`: `"Pulled <name>."` (legacy) replaced with `"Now watching <name>."` (canonical). The canonical route returns the new copy; the deprecated route returns the legacy copy unchanged so old clients render the wording they expect.
- Celebration `icon`: `"pull"` (legacy) ↔ `"watch"` (canonical), same one-for-one alias rule.

| Legacy route | Canonical replacement |
|---|---|
| `GET /bcc/v1/me/binder` | `GET /bcc/v1/me/watching` |
| `GET /bcc/v1/me/binder/summary` | `GET /bcc/v1/me/watching/summary` |
| `POST /bcc/v1/me/binder/pull` | `POST /bcc/v1/me/watching/watch` |
| `DELETE /bcc/v1/me/binder/:follow_id` | `DELETE /bcc/v1/me/watching/:follow_id` |

**Deprecation headers (required on every response from these routes):**

- `Deprecation: true`
- `Sunset: <RFC 7231 HTTP-date>` — exact removal date, set by the release N+1 cut.
- `Link: <https://docs/api-contract-v1.md#45-watching>; rel="deprecation"`

The legacy `bcc_card_pulled` event is also emitted in parallel with `bcc_card_watched` during the deprecation window and removed in release N+1.

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

**Pagination:** uses **offset** envelope per §1.5 (Locals is a directory, not a time-ordered feed). Cursor pagination is reserved for `/feed`, `/feed/hot`, `/me/watching` (and its legacy alias `/me/binder` during the §1.1.1 deprecation window).

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
- **Errors:** `bcc_unauthorized`, `bcc_conflict` (already a member), `bcc_rate_limited`
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
  - `bcc_invalid_request` (400) — group is not a holder group
  - `bcc_permission_denied` (403) with `unlock_hint`:
    - opt-out cooldown active: "You opted out of this community recently. Try again later or rejoin from the discovery page."
    - holder check failed: "Hold a `<CollectionName>` NFT to join this community." (or "Hold at least N NFTs from this collection..." for `min_balance > 1`)
  - `bcc_internal_error` (503) — chain unsupported (transient infra issue)
- **Mapping:** `NftGroupGateService::joinIfEligible` → `PeepSoGroupWriter::join` (which fires `peepso_action_group_user_join` and recomputes `peepso_group_members_count`).

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
  - `bcc_invalid_request` (404) — group not found
  - `bcc_invalid_request` (400) — group is a holder group or Local; use the dedicated endpoint
  - `bcc_permission_denied` (403):
    - `closed` group: "This community requires admin approval. Visit the group page to request access."
    - `secret` group: "This community is invite-only."
- **Mapping:** Resolves `GroupContext`; if `type` is `nft` or `local` rejects (use the dedicated endpoint); for `open` groups calls `PeepSoGroupWriter::join`. Closed/secret are not joined here — PeepSo's request-flow / invitation machinery is not replicated by this endpoint.

#### `POST /bcc/v1/me/groups/:id/leave`

- **Auth:** Bearer (401 anonymous)
- **Response 200:** `{ "left": true, "group_id": 4231 }`
- **Errors:**
  - `bcc_invalid_request` (404) — group not found
  - `bcc_invalid_request` (400) — group is a holder group or Local
  - `bcc_permission_denied` (403) — caller is the group owner
- **Mapping:** `PeepSoGroupWriter::leave` (refuses owners; PeepSo's `member_leave` recomputes `peepso_group_members_count` internally).

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
        }
      }
    ],
    "pagination": { "page": 1, "page_size": 20, "total": 142, "total_pages": 8 }
  }
  ```
- **Cache:** `Cache-Control: public, max-age=60` (60s window keeps newly-warming groups discoverable quickly).
- **Privacy:** `secret` groups never appear here regardless of viewer. `closed` groups appear with name + member_count visible; content stays private at PeepSo's layer.
- **Filter `verified=1`:** restricts to groups with `_bcc_group_kind = 'holders'`. Use this to render an "On-Chain Verified only" filter chip on the discovery page.
- **`image_url`:** cover-art URL. NFT-type cards return the underlying collection's `image_url` (joined through `wp_bcc_onchain_collections`). Non-NFT cards (`local`/`system`/`user`) return `null` in V1 — the frontend falls back to a generated initials block. PeepSo group avatars for non-NFT kinds is V1.5.
- **`description`:** group post body, plain-text + tag-stripped + truncated to ~200 chars (em-dash ellipsis when truncated). `null` when the group has no description on file. Applies to all kinds — `local`/`system`/`user` cards can use the same field on a future detail surface.
- **`collection_stats`:** market-data block for NFT-type cards only — drives the discovery card's flip-to-back UX (floor price, holder distribution, lifetime volume, listed %, royalty %). Each inner field is independently nullable since the upstream fetch can leave any column unpopulated. Currency-bearing fields (`floor_price`, `total_volume`) are returned as raw strings (full decimal precision) PLUS server-pre-formatted `*_display` strings (`floor_display`, `volume_display`, `holders_display`, `supply_display`, `listed_display`, `royalty_display`, `min_balance_display`). Frontend renders `*_display` verbatim per §A2 / §S — no client-side number-formatting decisions. `distribution_pct` is the server-computed `holders / supply * 100` (rounded), exposed as a number alongside `holders_display` for charting use. `min_balance` mirrors the gate threshold (`_bcc_gate_min_balance` post-meta). Em-dash (`"—"`) appears in `*_display` when the underlying value is missing/zero so the wire never surfaces "0.00 STARS" as a fake-low signal. Non-NFT cards return `null` for the entire block — there is no equivalent for `local`/`system`/`user` kinds.
- **`collection_stats.marketplace`:** server-resolved canonical marketplace link for the underlying NFT collection — `{ url, label }` when the chain is mapped, `null` otherwise. V1 covers Stargaze (canonical) and the major EVM chains via OpenSea (`ethereum`/`polygon`/`arbitrum`/`optimism`/`base`/`avalanche`/`bsc`); Solana, NEAR, and the other cosmos chains return `null` until canonical marketplace surfaces are picked. Filterable via `bcc_marketplace_link_map` so a deployment can extend or override without a code release. Frontend renders the URL verbatim with `target="_blank" rel="noopener noreferrer"` and `e.stopPropagation()` on click so the marketplace tab opens without flipping the discovery card back to the front.
- **Sort approximation note:** the candidate pool is fetched + sorted in PHP before pagination (limit 500). The cross-page sort is exact within the candidate pool; deep pagination beyond ~500 groups would require SQL-side sort. v1 scale is well under this.
- **Mapping:** `PeepSoGroupRepository::listBrowsableGroupIds` (excludes secret) → `GroupContextResolver::forManyGroups` → `GroupActivityHeatService::forGroups` for heat → `GatedGroupRepository::listAllGatedGroupConfigs` + `CollectionRepository::findManyByIds` for image_url + collection_stats enrichment (NFT-type only) → in-memory sort by (`is_verified`, `posts_last_7d`, `member_count`) all DESC.

### 4.8 Ranks

#### `GET /bcc/v1/ranks`

The rank catalog and the viewer's current rank.

- **Auth:** Anonymous OR Bearer
- **Response 200:**
  ```json
  {
    "ranks": [
      { "key": "apprentice", "label": "Apprentice", "description": "New on the floor.", "auto_assigned": true,  "order": 1 },
      { "key": "journeyman", "label": "Journeyman", "description": "Earned the basics.", "auto_assigned": true,  "order": 2 },
      { "key": "foreman",    "label": "Foreman",    "description": "Conferred for trust.","auto_assigned": false, "order": 3 }
    ],
    "viewer": {
      "current_rank": "journeyman",
      "auto_derived_rank": "journeyman",
      "is_admin_conferred": false
    }
  }
  ```
- **Cache:** `Cache-Control: public, max-age=300`
- **Mapping:** Static rank catalog from `bcc_options`. `viewer.*` from `bcc_user_ranks`. `current_rank` and `auto_derived_rank` are always equal in V1 (see deferral note below); they're separate fields because the data layer can already store admin-conferred rank rows — only the REST mutation surface is deferred.

#### Admin-conferral REST surface — deferred (V1 ships auto-derivation only)

V1 contract previously documented `POST /admin/ranks/award` and
`DELETE /admin/ranks/:rank/:user_id` for admin-conferred ranks. Those
endpoints were never registered in `register_rest_route` and have no
frontend caller. V1 ranks are **fully auto-derived** from tier/trust
score by [`RankProgressionListener::run`](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/RankProgressionListener.php),
which fires `bcc_rank_awarded` on real promotions; admin override is
not a V1 capability.

The read-side artifacts kept around for future-build readiness:
- `is_admin_conferred` in `GET /ranks` is in the contract but always
  `false` in V1 (no path sets a non-auto rank).
- The `auto_assigned: false` flag on certain rank catalog entries
  (e.g. `foreman`) is design intent, not enforced today.

When admin-conferral becomes a real feature, the REST surface gets
designed against the contract at that point — not resurrected from
this retracted spec, which the parity guard correctly flagged as
documenting endpoints that don't exist.

### 4.9 Directory (§G1 / §G2)

The §G1/§G2 browse + search surfaces. Two endpoints, one shape contract: every result item is a Card or a SearchSuggestion — both pre-shaped per §A2/§L5 so the frontend renders without derivation.

#### `GET /bcc/v1/cards`

Paginated list of Cards filtered + sorted server-side. Backs `/directory`.

- **Auth:** Anonymous OR Bearer (per-viewer `permissions` + `social_proof` vary)
- **Query:**
  - `kind` ∈ {`validator`, `project`, `creator`} — optional; omitted = all kinds (member excluded — members aren't browsed here)
  - `tier` ∈ {`legendary`, `rare`, `uncommon`, `common`} — optional; canonical card-tier values per §C1. Risky tier is intentionally not selectable (entity hidden from card UI per §C1).
  - `sort` ∈ {`trust`, `newest`, `endorsements`, `followers`} — optional; default `trust`
  - `q` (search string) — optional; passed verbatim to the underlying `PageDiscoveryService`
  - `good_standing_only` (`1`|`true`|`on`|`yes` → true; anything else → false) — optional; default false. When true, restricts results to operators in good standing per §E1 (`reputation_tier ∈ {neutral, trusted, elite}`). Composes with `tier` via AND server-side, so `tier=common&good_standing_only=1` is a vacuously empty intersection rather than an error.
  - `page` (1..20) — optional; default 1. The hard ceiling protects against unbounded `OFFSET` filesort
  - `per_page` (1..50) — optional; default 24
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
- **Errors:** `bcc_invalid_request` (bad `kind`, `tier`, `sort`, or `page > 20`)
- **Cache:** `Cache-Control: private, max-age=15`. Underlying `PageDiscoveryService` query is server-cached for 30s with a stampede lock; the short client TTL is courtesy for back-button nav.
- **Mapping:**
  - Filter SQL ← `PageDiscoveryService::query()`. (`/bcc/v1/discover` was retired 2026-05-15 along with the legacy bcc-page-slider Gutenberg block it served; `PageDiscoveryService` is now used solely by this endpoint.)
  - Server translates canonical kind → legacy `_bcc_page_type` (validator→validator, project→builder, creator→nft) via `PageTypeMap`
  - Server translates canonical card-tier → reputation tier (legendary→elite, rare→trusted, uncommon→neutral, common→caution)
  - The `good_standing_only` `IN`-clause sources its tier list from `UserViewService::GOOD_STANDING_TIERS` — the same constant `isInGoodStanding()` (and therefore the per-row `is_in_good_standing` stamp + the `/auth/*` response `in_good_standing` flag) reads from. The filter chip and the per-row stamp can never disagree.
  - Each row hydrated through `CardViewService::getCard()` so the per-item shape is identical to `GET /cards/:type/:id`

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
        "href": "/v/blacksmith-node"
      }
    ]
  }
  ```
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
  - `q` (string) — 2..100 chars (server returns empty for shorter; `QueryQualityGate` rejects pure-stopword / low-entropy queries to empty too)
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
  - `q` (string) — 2..100 chars (`QueryQualityGate` shared with project search)
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
  - `q` (string) — 2..100 chars
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

- `type` ∈ {`bcc_reaction`, `bcc_review`, `bcc_card_watched`, `bcc_rank_up`, `bcc_endorse`, `bcc_welcome`, `bcc_mention`, `bcc_local_post`, `bcc_comment_received`}. V1 catalogue per §I2; follow-posts deferred. **Legacy alias:** `bcc_card_pulled` is emitted in parallel with `bcc_card_watched` during the §1.1.1 deprecation window; clients SHOULD branch on the new name and accept either. Removed in release N+1.
- `message` is server-rendered per §A2 — frontend renders verbatim. Plain English, capped at 200 chars (PeepSo's column width).
- `actor.handle` may be empty when the originating user has been deleted; the frontend renders the message verbatim regardless.
- `link` is a server-built relative path. Per type:
  - `bcc_reaction` → `/?focus=<act_id>` (jump back to the post)
  - `bcc_review` → `/v/<page-handle>` etc. (the reviewed page, route prefix per kind)
  - `bcc_card_watched` → `/u/<actor-handle>` (the watcher's profile) — legacy `bcc_card_pulled` resolves identically during deprecation
  - `bcc_rank_up` → `/u/<recipient-handle>` (your own profile — progression strip lives there)
  - `bcc_endorse` → `/v/<page-handle>` etc. (the endorsed page)
  - `bcc_welcome` → `/` (the floor — the user is probably already there when they see it)
  - `bcc_mention` → `/?focus=<act_id>` (jump to the floor focused on the post containing the @-tag; for comment mentions `act_id` is the **parent post's** act_id — the FE has no comment-anchor consumer in V1)
  - `bcc_local_post` → `/locals/<slug>` resolved from `external_id` (the Local's group_id). Falls back to `/locals` when the group is no longer a Local (deleted, renamed off-prefix).
  - `bcc_comment_received` → `/?focus=<act_id>` (jump to the floor focused on the parent post that received the comment; mirrors REACTION + MENTION shape — the FE has no comment-anchor consumer in V1).
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
| `bcc_card_watched` (legacy `bcc_card_pulled`) | the followee user | viewer === followee (impossible from the watchlist UI, defensive) |
| `bcc_rank_awarded` | the recipient (self-notification) | rank label not in catalog |
| `bcc_trust_endorsement_added` | endorsed page owner | endorser === page owner |
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

##### Comment-received dispatch — policy locks (V2 retention slice, 2026-05-13)

Three behaviours are intentional and load-bearing — do not relitigate without explicit re-planning:

1. **Single recipient = parent post's author.** Resolved fresh at dispatch time by walking `parentActId → PeepSoActivityRepository::getById → act_external_id → wp_posts.post_author`. No fan-out cost concern (one recipient per comment); dispatch is sync. Author self-comments are skipped.
2. **Bell coalesced via 5-min per-(recipient, post) transient** (`bcc_comment_received_notified_{userId}_{postId}`). A hot post with 50 comments in 10 min produces at most 2 bell rows for the author (one per 5-min window). Push uses the existing `PushDispatcher` 5-min `(recipient, eventType)` debounce + count aggregation, coalescing rapid bursts into "N new comments on your post."
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
    "bcc_endorse":          true,
    "bcc_welcome":          true,
    "bcc_mention":          true,
    "bcc_local_post":       true,
    "bcc_comment_received": true
  },
  "push": {
    "enabled": false,
    "events": {
      "review":            true,
      "endorse":           true,
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
| `endorse`        | `bcc_trust_endorsement_added`            | [`NotificationDispatcher::dispatch`](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/NotificationDispatcher.php) — alongside the bell write |
| `dispute_outcome`| `bcc_disputes_email_reporter_result`     | [`bcc-trust.php`](../app/public/wp-content/plugins/bcc-trust/bcc-trust.php) — additive subscriber alongside the existing email handler |
| `panelist_selected` | `bcc_disputes_notify_panelist`        | [`bcc-trust.php`](../app/public/wp-content/plugins/bcc-trust/bcc-trust.php) — additive subscriber alongside the existing email handler |

**Self-suppression:** push inherits `NotificationDispatcher::dispatch`'s actor-vs-recipient guard for free (review + endorse). Disputes pushes always fire to a different recipient than the actor by construction.

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
| (reserved) `bcc_feature_level_unlocked` | `level_up` | TBD when FeatureAccessService starts emitting transitions |
| (reserved) `bcc_card_tier_upgraded` | `tier_upgrade` | TBD when the tier-upgrade listener lands |

`RankProgressionListener` is the only producer in V1. It seeds quietly on a user's first event so users who are already Journeyman at rollout don't get a phantom celebration on their next activity.

### 4.13 Comments (v1.5)

Three endpoints under `/bcc/v1/posts/:feed_id/comments`. Comments are a hybrid PeepSo-proxy: BCC reads `peepso_activities` directly via a join to `wp_posts` + `wp_users`; BCC writes route through PeepSo's `add_comment` so moderation, notification fan-out, and the `peepso_disable_comments` gate apply automatically.

#### `GET /bcc/v1/posts/:feed_id/comments`

Paginated list of visible comments on the parent post.

- **Auth:** optional. Anonymous viewers get the same list on non-gated posts.
- **Holder-Groups gate:** when the parent post is in a PeepSo group (post-meta `peepso_group_id` set), the viewer MUST be a member (`gm_user_status` ∈ `member`, `member_owner`, `member_manager`, `member_moderator`, `member_readonly`). Non-members get `bcc_forbidden 403`.
- **Query params:**
  - `limit` (int, optional, default 20, max 50)
  - `cursor` (string, optional) — base64url-encoded JSON `{t: ISO-8601, id: act_id}`. Same encoding as `/feed`.
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
  { "body": "love this — finally a watchlist that respects watches." }
  ```
  - `body` (string, required, 1–2000 chars after trim). PeepSo applies its own sanitization on top (`htmlspecialchars` + `strip_content`).
- **Holder-Groups gate:** writes require write-grade membership (`gm_user_status` ∈ `member`, `member_owner`, `member_manager`, `member_moderator`). `member_readonly` can read but not create. Non-members get `bcc_forbidden 403`.
- **Rate limit:** burst seatbelt — `BCC_TRUST_RATE_LIMIT_COMMENT` (20) per `BCC_TRUST_RATE_WINDOW_COMMENT` (300s) per author.
- **Response 200 data shape:**
  ```json
  {
    "comment": { "...Comment view-model per §3.5..." }
  }
  ```
- **Errors:**
  - `bcc_invalid_request 400` — malformed `feed_id`, empty body, body over cap.
  - `bcc_unauthorized 401` — anonymous.
  - `bcc_forbidden 403` — gate fails OR PeepSo refused (parent has `peepso_disable_comments`, parent owner blocked the commenter).
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

Change password. Body `{ "current_password": "...", "password": "..." }`. New password ≥ 10 chars (server-enforced). Server calls `wp_set_password` (which destroys all session tokens) and immediately re-issues the current session's auth cookie so the user is not kicked out mid-flow.

- **Response 200:** `{ "ok": true }`

#### `DELETE /bcc/v1/me/account` (V2 Phase 2.5)

Permanently delete the user. Body `{ "current_password": "...", "confirm": "DELETE" }`. Gated by PeepSo's `site_registration_allowdelete` site option — when the admin has disabled self-deletion, returns `bcc_forbidden` (403). On success: `wp_delete_user` runs (PeepSo's hooks fan out to its activity / friends / messages cleanup), then `wp_logout`. The auth cookie is gone before the response returns; clients should redirect to `logout_url` rather than make another authenticated call.

- **Response 200:** `{ "deleted": true, "logout_url": "..." }`

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

Promotes the §8 deferred `GET /collections/:id/pieces` placeholder to a real per-piece detail surface. The list-form gallery endpoint (`GET /creators/:slug/gallery`) remains deferred — V2 Phase 6 ships the detail view only.

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
    "reliability_standing": "highly_reliable",
    "since_attestation_count": 28,
    "stand_behind_allocation": {
      "slots_total": 5,
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
- **`divergence_state`** — the operator's own divergence-state classification per §J.8. SELF-ONLY context on this surface (the same value flows to the public §J.6 `negative_signals.divergence_state` for entity-card targets; user_profile targets keep it self-only in V1 per §J.10 q14). PR-8a ships this as a read-time `DivergenceStateClassifier` output.
- **`explainer`** — server-pinned copy block explaining the current state in plain language. Per the §J.5 critical-risk-mitigation item #7 ("self-only 'why am I in this state' view"), the operator's self-mirror is the only surface that carries this — never on third-party endpoints. The `headline` + `body` strings are server-rendered per §A2; the FE renders them verbatim.
- **Cache:** `private, max-age=60`.
- **`slots_recyclable_count`:** number of currently-allocated Stand Behind slots whose decayed_weight has crossed the 50% threshold and are eligible to auto-free on the next write. FE renders this as a soft "you have N slots about to recycle" hint.

#### §J.6 Entity view-model extensions

Existing card and profile endpoints (`/bcc/v1/cards/:type/:id`, `/bcc/v1/users/:handle`) carry the following additions:

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
    "can_dispute":      { "allowed": false, "unlock_hint": "Reach Trusted tier to file disputes." },
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
      "token_standard": "ERC-721"
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
    }]
  }
  ```
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
- **Errors:** `bcc_unauthorized` 401, `bcc_invalid_request` 400 (id missing), `bcc_rate_limited` 429.
- **Rate limit:** 10/min/user.
- **Cache:** `Cache-Control: no-store`.
- **Side effects on a true state transition (`removed: true` for an own-wallet):** writes `wallet_unlinked` audit row (`AuditLogger::log`) and fires `AccountSecurityMailer::walletUnlinked` (§4.23 side-channel). The trust-engine domain event `bcc_wallet_disconnected` fires from the underlying `WalletIdentityService::unlinkWallet`, triggering `BonusService::handleWalletDisconnect` and Helius unsubscribe (Solana only).

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

Watches accumulate into a batch while the user keeps watching. The batch closes after exactly **10 minutes of watch inactivity**; at close, the server emits one `watch_batch` FeedItem (legacy kind name `pull_batch` during the §1.1.1 deprecation window). The post body shows up to **3 top cards** + "+N more" (`more_count = card_count - 3`). Once posted, the FeedItem is **frozen**: subsequent unfollows do not edit or remove the post. Watchlist UI updates immediately on unfollow; feed does not.

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
| `card_tier`, `tier_label` | Server-side mapping `reputation_tier → card_tier` (§C1), in `bcc-trust` |
| `rank`, `rank_label` | `bcc_user_ranks` (new V1, per §E2) + auto-derived from activity |
| `is_in_good_standing` | `bcc-trust` derived from tier ≥ neutral AND no flags (§E1) |
| `flags` | `bcc_trust_flags` |
| `bio` | PeepSo profile description |
| `primary_local`, `locals` | `peepso_group_members` (PeepSo's membership ledger — single graph rule) joined with `wp_usermeta.bcc_primary_local_group_id` for the primary pointer; no dedicated BCC table |
| `wallets` | `bcc_wallet_links` (existing) |
| `counts.followers/following` | `peepso_follower` aggregates |
| `counts.watching_size` (legacy `counts.binder_size`) | `peepso_follower` filtered to BCC card kinds |
| `counts.reviews_written` | `bcc_trust_votes` aggregated |
| `counts.disputes_signed` | `bcc_trust_flags` signers aggregated |
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
| `rank_label` | `null` for V1 entity cards (members only have ranks); for member cards, mapped from `bcc_user_ranks` |
| `is_claimed` | `peepso_pages.claimed_by IS NOT NULL` (new V1 column, per §B5) |
| `claimed_by_handle` | JOIN to `wp_usermeta.bcc_handle` |
| `crest` | `peepso_pages` meta + AbstractPageType convention |
| `stats[]` | per kind — see §3.2.x; sourced from `bcc_page_read_model` + chain-specific fetchers |
| `social_proof` | new V1 server composer over `peepso_follower` + `peepso_reactions` + `bcc-trust` weighting |
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
| `body.*` | per-kind, sourced from kind-specific tables (`bcc_trust_votes` for reviews, `bcc_pull_meta` batches for watches (table retains legacy physical name per §4.5.1), `bcc_onchain_signals` for signals, etc.) |
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
- `bcc_pull_meta` — `(follow_id, tier_at_pull, batch_id, pulled_at)` (§C2). Table + column names retain their legacy `pull`-prefixed forms per §4.5.1; the logical concept they store is "watch metadata."
- `bcc_user_ranks` — `(user_id, rank_key, awarded_by, awarded_at, revoked_at, revoke_reason)` (§E2)
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

---

## 7. Resolved contract decisions

All ten open items locked **2026-04-27**. Phase 1 implementation may begin.

1. **Avatar URLs** — **absolute URL, CDN-ready.** No relative paths. The server controls the host so a CDN origin can be swapped in without a contract change. See §1.7 (Asset / media URLs) and §6.1.
2. **Currency formatting** — **server-side abbreviated** with K/M/B suffixes (1 decimal max). Full numeric value always present in `raw`. Thresholds: `< 1k` full numerals · `≥ 1k` → K · `≥ 1M` → M · `≥ 1B` → B. See §2.8.
3. **Slug stability** — **immutable post-creation.** Admins rename via display name only. `links.self` URLs are stable forever. See §1.7 (Slugs).
4. **Member-card watch semantics** — **member watches count toward `watching_size` (legacy alias `binder_size`).** Member cards are first-class watchlist citizens; no separate `following_count` field. See §3.2 field rules.
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
- **NFT gallery list endpoints** (`GET /creators/:slug/gallery`, `GET /collections/:id/pieces`) — still deferred. The per-piece DETAIL endpoint (`GET /nft-pieces/{chain}/{contract}/{tokenId}`) ships in V2 Phase 6 — see §4.17. The list-form gallery is a follow-on phase that still needs cursor pagination + collection-level filters.
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
  - `POST /reactions` accepts §D5 kinds `'solid' | 'vouch' | 'stand_behind'` (locked). Routes through bcc-core's `PeepSoReactionWriter` (single-graph rule). Throttled at 60/minute per viewer. Returns the post-mutation `{counts, viewer_reaction}` shape so the frontend patches its cache without a feed refetch. `DELETE /reactions/:feed_id` also registered.
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
  - `NotificationDispatcher` subscribes to `bcc_reaction_added`, `bcc_review_published`, `bcc_card_watched` (and its legacy alias `bcc_card_pulled` during the §1.1.1 deprecation window), `bcc_rank_awarded`. Writes through `PeepSoNotificationWriter` (bcc-core) — single-graph rule per §I1. The `bcc_reaction_added` / `bcc_reaction_removed` events were added to `ReactionsEndpoint` as part of this work (only event the catalogue was missing).
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

### v1.23 — 2026-05-26

- **§4.8 — admin/ranks REST surface retracted (doc-only).** Second
  finding from the 2026-05-26 `scripts/contract-parity-guard.php`
  first-run: the contract documented `POST /admin/ranks/award` and
  `DELETE /admin/ranks/:rank/:user_id` for admin-conferred ranks,
  but neither endpoint was ever wired into `register_rest_route`.
  Zero frontend callers, zero PHP handlers. V1 ranks are fully
  auto-derived from tier/trust score by
  `RankProgressionListener::run`; admin-override is not a V1
  feature. Replaced the two `####` headers with a "deferred" note
  in §4.8 explaining what stays (read-side `is_admin_conferred`
  field, catalog `auto_assigned: false` design intent) and what
  doesn't (the mutation REST surface). When admin-conferral
  becomes a real feature, the new endpoints get designed at that
  point — not resurrected from this retracted spec. Per fresh-
  install policy, the contract aligns with shipping reality.

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
  - **`is_pre_consensus_pick`** — V1 heuristic: true when
    `kind === 'stand_behind'` AND `attestation_order_in_target ≤ 3`.
    Vouch rows never mark as pre-consensus (vouch is abundant per
    §J.1; the marker is reserved for the scarcer signal). Slice E
    replaces with the §J.3.2.1 Early Read synthesis that compares
    the call to later consensus.
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
  `POST /bcc/v1/posts` (status JSON body) — covered by the same
  validation matrix; `kind=blog + group_id > 0` returns 400
  `bcc_invalid_request` (V1 scope-fence: long-form blogs target the
  author's own wall only). Implementation: `PostsService::gateGroupPost`
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
- **§4.10 Notifications** — new section. Locks the §I1 contract: `GET /me/notifications`, `GET /me/notifications/unread-count`, `POST /me/notifications/mark-read`. Storage layer is `peepso_notifications` scoped to `BCC_NOTIFICATION_MODULE_ID = 9000` (single-graph rule). Notification view-models carry server-rendered messages + server-built `link` per §A2. V1 catalogue: `bcc_reaction`, `bcc_review`, `bcc_card_watched` (legacy alias `bcc_card_pulled` per §1.1.1), `bcc_rank_up` — @mentions and follow-posts deferred to V1.5.
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
