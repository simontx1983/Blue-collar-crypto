# BCC API View-Model Contract — V1

**Status:** Draft v1.1 · 2026-04-29 · Phase 1 deliverable
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

### 1.2 Authentication

Three modes:

| Mode | When | How |
|---|---|---|
| **Anonymous** | Public reads (`GET /cards/:type/:id`, `GET /feed/hot`, `GET /users/:handle`) | No `Authorization` header |
| **Bearer** | Authenticated reads + writes | `Authorization: Bearer <jwt>` minted by the JWT plugin via NextAuth |
| **Wallet-signed** | One-time challenge-response (claim flow, wallet linking) | `POST` with `{ wallet, signature, nonce }` payload |

JWT lifetime: 1h access, 30d refresh. Refresh handled by NextAuth.
Anonymous endpoints **must still respect privacy** (per K2): if a user's binder is hidden, anonymous reads see "Binder is private," not the contents.

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

Every error follows this shape (matches WP REST conventions):

```json
{
  "code": "bcc_permission_denied",
  "message": "You need to be Level 2 to write reviews.",
  "status": 403,
  "data": {
    "rule": "O5+D2",
    "unlock_hint": "Pull 5 cards and visit the Floor 3 days to unlock reviews."
  }
}
```

`code` is machine-readable (snake_case, namespaced `bcc_`). `message` is user-safe (the frontend may render it directly per §L2). `data.unlock_hint` appears whenever the error is a soft gate the user can resolve themselves.

**Standard codes:**

| Code | HTTP | Meaning |
|---|---|---|
| `bcc_invalid_request` | 400 | Bad input shape or missing required field |
| `bcc_unauthorized` | 401 | Missing/expired JWT |
| `bcc_permission_denied` | 403 | Auth'd but not allowed (gate fail) |
| `bcc_not_found` | 404 | Resource does not exist or is hidden from this viewer |
| `bcc_conflict` | 409 | State collision (claim already won, batch already closed) |
| `bcc_rate_limited` | 429 | Per-user / per-IP rate limit hit |
| `bcc_internal` | 500 | Unhandled server error — never exposes internals |
| `bcc_upstream_unavailable` | 502 | Upstream chain RPC / indexer down |

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
- **Hidden-binder rule (§O4):** users who have hidden their binder (per §K2) still contribute to `additional_count` but **never** appear in `named_handles`, even if they're elite/trusted.
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

- `kind` is one of: `light` (e.g., a normal pull), `mid` (first-of-its-kind action — server tracks via `bcc_first_*` user_meta flags per §O1.2), `heavy` (tier upgrade, rank promotion, feature unlock, 30-day streak).
- For Heavy moments delivered via §4.11, the inline shape is wrapped — `kind`/`label`/`icon` are nested under `celebration`, alongside an `id` for consume targeting. See §4.11 for the wrapper.
- `label` is what the frontend renders in the toast. Plain English, ≤ 50 chars.
- `icon` is a server-defined enum the frontend maps to a sprite (`pull`, `review-stamp`, `vouch-handshake`, `dispute-shield`, `rank-up`, `tier-upgrade`, `streak-flame`, `unlock`, `local-badge`).
- `rarity_tint` is non-null only on `light` celebrations triggered by a pull — the value is the pulled card's `card_tier`, used for the glow color (§O1).
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

**Shape:**

```json
{
  "reactions": {
    "counts": {
      "solid":        14,
      "vouch":         3,
      "stand_behind":  1
    },
    "my_reactions": ["solid"],
    "totals": 18
  }
}
```

**Rules:**

- `counts` always has all three keys, even if zero. Eliminates `undefined` checks on the client.
- `my_reactions` is empty array `[]` when the viewer is anonymous or hasn't reacted.
- `totals` is the sum (server-computed; the frontend never sums).

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
    "binder_size": 38,
    "reviews_written": 8,
    "disputes_signed": 1,
    "solids_given": 240,
    "solids_received": 117
  },
  "privacy": {
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
- `privacy` block reduced to `{ binder_hidden, reviews_hidden, ... }` reflecting what the viewer can see, not what's set.
- `permissions.can_follow` becomes meaningful (`allowed: true` if the viewer can pull this user as a member card).
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
- `permissions.can_pull.allowed` is `false` when (a) viewer is anonymous, (b) viewer is the card subject (you can't follow yourself), or (c) the card is hidden (risky tier). In cases (a) and (c), `unlock_hint` is `null` — these aren't hints the user can resolve.
- `viewer_has_reviewed` / `viewer_has_endorsed` (per §D2 / §V1.5) — drives "WRITE A REVIEW" → "REMOVE YOUR REVIEW" CTA swaps. Always `false` for anonymous viewers and on member cards.
- `endorse_unlock_hint` mirrors `permissions.can_endorse.unlock_hint` so the EndorseButton can render the hover hint without reaching into the permission object.
- `binder_size` (on member cards' stats and on `User.counts.binder_size`) **counts member follows alongside entity follows.** Pulling another member is a first-class binder action, no separate `following_count` field exists.

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
- **Member:** add `rank` (text), `binder_size` (count), `primary_local` (text)

V1 frontend types declare `stats: CardStat[]` (opaque array, no per-kind narrowing). Adding kind-specific stats in V1.5 is purely additive — no breaking change.
```

**3.2.5 Card actions (HATEOAS hints):**

`actions` is a server-authoritative map of API endpoints the client can invoke for gated card mutations. The server owns URL construction (per §A4); the client looks up the endpoint by action key rather than hardcoding a path.

```json
"actions": {
  "pull": {
    "method":        "POST",
    "href":          "/wp-json/bcc/v1/me/binder/pull",
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

- Action keys are stable identifiers (`pull`, `claim`). Permission to invoke is in `permissions.*`; presence in `actions` does NOT imply the viewer is allowed (gate on `permissions.<key>.allowed`).
- `claim` is omitted when the page has no resolvable underlying on-chain entity. `pull` is always emitted.
- `body` is the request payload template — the client passes it as-is. Servers may add server-only fields (CSRF token, etc.) at request time.
- `idempotent` true means safe to retry on transport error.
- Member cards emit only `pull` (members are not claimable in V1).
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
- `external_id` is the module-specific FK (e.g. `wp_posts.ID` for status / blog / review backings, `bcc_pull_batches.id` for `pull_batch`, `bcc_onchain_claims.id` for `page_claim`, `0` for system-authored signals). Used by server-side hydrators and by the client as a stable React key. Treat as opaque.
- `scope_tags` lists which feed-mode tabs (§N6) this post is eligible for. Used for client-side optimistic filtering when switching tabs without refetching.
- `group` is **omitted** (not `null`) when the post does NOT come from a PeepSo group. When present, the post is a wall post inside a group:
  - `id` — group_id (matches `group_id` in §4.7.x endpoints).
  - `type` ∈ `nft` | `local` | `user` | `system` — matches §4.7.2 group `type`.
  - `verification` is `null` for non-NFT groups; for NFT-gated groups it carries `{kind: 'on_chain', label: 'On-Chain Verified'}`. Frontend MUST render `label` verbatim — never abbreviate to "Verified" alone.
  - **No server-side ranking is applied based on this field in v1.** The Floor feed continues to order strictly by recency. The `group` block is metadata for badge rendering and (optional) client-side prioritization. A scored ranking layer is deferred until usage telemetry exists to tune it honestly.
  - **Mapping:** `peepso_group_id` post-meta on the activity's wp_post (PeepSo writes this when a status post is created inside a group) → `GroupContextResolver::forManyGroups`. Batched per page; no N+1.
- V1 author block is **user-only** — every post in V1 is authored by a WP user (status, review, pull_batch, page_claim, dispute_signed, blog). System-emitted signals (§3.3.5) currently ride the same shape with the system actor's user_id; their `post_kind` discriminates them, not the author block.
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
  "embeds": []
}
```

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

**3.3.3 `pull_batch`** — C3 batched pulls:

```json
"body": {
  "card_count": 5,
  "summary_text": "Simon pulled 5 cards",
  "top_cards": [
    { "...": "summary Card view-model, max 3" }
  ],
  "more_count": 2,
  "batch_id": "batch_abc123",
  "frozen": true
}
```

**Rules (§C3):**
- Batches close after exactly **10 minutes of pull inactivity**. At close, the server emits exactly one `pull_batch` FeedItem.
- `top_cards` contains a **maximum of 3** summary Card view-models. If the batch has more than 3 cards, `more_count = card_count - 3` (always, never paginated, never expandable in V1).
- `frozen: true` is always true once posted. There is no other value in V1.
- If the user later unfollows cards in this batch, the batch post does NOT update. `card_count`, `top_cards`, `more_count` reflect the batch at the moment of posting.
- The binder UI (separate from the feed) reflects unfollows immediately. Feed and binder are not synchronized after the post.

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

**3.3.8 `blog_excerpt`** — D6 long-form post. Same FeedItem shape in two rendering contexts:

```json
"body": {
  "is_blog_excerpt": true,
  "title": "Why I'm rotating out of Cosmos validators",
  "excerpt": "The set is becoming concentrated. Three operators run 41% of voting power, and the upcoming upgrade…",
  "excerpt_truncated_at": 412,
  "full_text": "The set is becoming concentrated. Three operators run 41% of voting power, and the upcoming upgrade narrows the active set further.\n\n## What changes\n\nIf you're a delegator on a top-3 validator, your effective decentralization just dropped. The math: when one operator controls X% of voting power…\n\n## What I'm doing about it\n\nRotating 60% of my position to validators ranked 15–35…",
  "read_link": "/u/simontx/blog/why-im-rotating-out"
}
```

**Rules (§D6):**

- `excerpt` is server-truncated at the nearest sentence boundary within the 300–500 char window (§D6). Rendered when this FeedItem appears in **Floor contexts** (`/feed`, `/feed/hot`).
- `full_text` is the complete post body (markdown/HTML, no character limit). Rendered when this FeedItem appears in **Blog tab contexts** (`/u/:handle/blog`, `/v/:slug/blog`).
- **Same `post_kind`, same FeedItem, two rendering surfaces** — there is **no separate blog post type or CPT** (§D6). Blog posts live in the FeedItem system; the Blog tab is a filter where `post_kind = blog_excerpt`.
- The server MAY omit `full_text` from Floor-context responses to save bytes; a Blog-context response MUST include it.
- `is_blog_excerpt: true` is the marker the frontend uses to render the "Read full post" affordance in Floor contexts.

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
      "body": "−3.7% in the last 24h. You hold this validator in your binder.",
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

### 4.5 Binder

#### `GET /bcc/v1/me/binder`

Returns the viewer's binder (§C2 — UI projection of PeepSo follows + `bcc_pull_meta`).

- **Auth:** Bearer
- **Query:** `cursor`, `limit` (default 18 = 2 binder pages of 3×3, max 54), `filter` (optional: `validator|project|creator|member`), `tier` (optional: `legendary|rare|uncommon|common`)
- **Response 200:**
  ```json
  {
    "items": [
      {
        "follow_id": 88123,
        "card": { "...": "summary Card view-model" },
        "pulled_at": "2026-04-22T09:14:00Z",
        "card_tier_at_pull": "rare",
        "tier_label_at_pull": "Rare",
        "batch_id": "batch_abc123"
      }
    ],
    "pagination": { "next_cursor": "...", "has_more": true }
  }
  ```
- **Errors:** `bcc_unauthorized`
- **Rate limit:** 60/min/user
- **Cache:** `Cache-Control: private, max-age=30`
- **Mapping:** JOIN of `peepso_follower` + `bcc_pull_meta` (§C2). Removed cards (unfollowed) do not appear. `card_tier_at_pull` is the `card_tier` (`legendary|rare|uncommon|common|null`) at the moment of the pull, not the current tier — preserved for historical narrative. `tier_label_at_pull` is the pre-rendered display string per §A2.

#### `POST /bcc/v1/me/binder/pull`

Pulls a card (= creates a PeepSo follow + `bcc_pull_meta` row).

- **Auth:** Bearer
- **Body:**
  ```json
  {
    "card_kind": "validator",
    "card_id": 1842
  }
  ```
- **Response 201 (new pull):**
  ```json
  {
    "follow_id": 88123,
    "binder_size": 39,
    "already_in_binder": false,
    "card": { "...": "summary Card view-model" },
    "celebration": {
      "kind": "light",
      "label": "Pulled Blacksmith Node.",
      "icon": "pull",
      "rarity_tint": "legendary"
    },
    "feed_post": {
      "kind": "deferred_batch",
      "batch_id": "batch_abc456",
      "estimated_post_at": "2026-04-27T14:38:00Z"
    }
  }
  ```
- **Response 200 (already in binder — idempotent):**
  ```json
  {
    "follow_id": 88123,
    "binder_size": 38,
    "already_in_binder": true,
    "card": { "...": "summary Card view-model" },
    "celebration": null,
    "feed_post": null
  }
  ```
- **Errors:**
  - `bcc_invalid_request` — bad card kind/id
  - `bcc_permission_denied` — viewing your own member card and trying to pull yourself
  - `bcc_rate_limited` — soft limit (per §L3)
- **Rate limit:** 120/hour/user (soft), 600/day/user (hard)
- **Cache:** `Cache-Control: no-store`
- **Mapping:**
  - Side effects: PeepSo follow created, `bcc_pull_meta` row inserted, `bcc_card_pulled` event emitted (§A3 async — request returns before subscribers run)
  - `feed_post.kind: "deferred_batch"` always (per §C3 — pulls go through the rolling-window aggregator before becoming a feed post). The `estimated_post_at` = the current pull's timestamp + **10 minutes** (every pull resets the inactivity window per §C3). The frontend may show a passive "+1 to your binder" without waiting for the feed.
  - **Idempotency (`already_in_binder`):** if the card is already in the viewer's binder when this endpoint is called (e.g., user double-clicks Pull, or two tabs race), the server returns `200` with `already_in_binder: true`, the existing `follow_id`, the current `binder_size` (unchanged), `celebration: null`, and `feed_post: null`. No new row is inserted, no event re-emitted, no double-celebration fired. The frontend uses this signal to skip the dopamine animation on the second call.

#### `DELETE /bcc/v1/me/binder/:follow_id`

Removes a card from the binder (= PeepSo unfollow).

- **Auth:** Bearer
- **Path:** `follow_id` (the PeepSo follow row ID, returned by `GET /me/binder`)
- **Response 200:**
  ```json
  {
    "removed": true,
    "binder_size": 38
  }
  ```
- **Errors:** `bcc_not_found`, `bcc_unauthorized`
- **Rate limit:** 60/min/user
- **Cache:** `Cache-Control: no-store`
- **Mapping:** PeepSo unfollow + cascade `bcc_pull_meta`. **Does not** edit any prior feed post per §C3.

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

**Pagination:** uses **offset** envelope per §1.5 (Locals is a directory, not a time-ordered feed). Cursor pagination is reserved for `/feed`, `/feed/hot`, `/me/binder`.

#### `POST /bcc/v1/me/locals/:id/join`

Join a Local.

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

#### `DELETE /bcc/v1/me/locals/:id`

Leave a Local.

- **Auth:** Bearer
- **Response 200:** `{ "left": true }`
- **Errors:** `bcc_unauthorized`, `bcc_not_found`
- **Mapping:** PeepSo group leave + cascade `bcc_user_locals`. If the user was using this Local as primary, `primary_local` becomes `null` until they pick another. Emits `bcc_local_left`.

#### `PUT /bcc/v1/me/locals/:id/primary`

Mark a Local as the user's primary.

- **Auth:** Bearer
- **Response 200:**
  ```json
  {
    "primary_local": { "...": "Local view-model" }
  }
  ```
- **Errors:** `bcc_unauthorized`, `bcc_not_found` (not a member of this Local)
- **Mapping:** Updates `bcc_user_locals.is_primary` (singleton — exactly one row per user has `is_primary: true`). Emits `bcc_local_primary_changed`.

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
  "heat": "warm"
}
```
- `heat` ∈ `cold` | `warm` | `hot`. Server-bucketed (default thresholds: cold ≤ 2 posts/7d, warm 3–9, hot ≥ 10). Filterable via `bcc_group_heat_thresholds`.
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
  - `type` ∈ `nft` | `local` | `user` | `system`. The frontend uses this to pick action URL + render verification badge.
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
        "activity": {
          "posts_last_7d": 14,
          "active_members_last_7d": 0,
          "last_activity_at": "2026-05-04T14:22:00+00:00",
          "heat": "warm"
        }
      }
    ],
    "pagination": { "page": 1, "page_size": 20, "total": 142, "total_pages": 8 }
  }
  ```
- **Cache:** `Cache-Control: public, max-age=60` (60s window keeps newly-warming groups discoverable quickly).
- **Privacy:** `secret` groups never appear here regardless of viewer. `closed` groups appear with name + member_count visible; content stays private at PeepSo's layer.
- **Filter `verified=1`:** restricts to groups with `_bcc_group_kind = 'holders'`. Use this to render an "On-Chain Verified only" filter chip on the discovery page.
- **Sort approximation note:** the candidate pool is fetched + sorted in PHP before pagination (limit 500). The cross-page sort is exact within the candidate pool; deep pagination beyond ~500 groups would require SQL-side sort. v1 scale is well under this.
- **Mapping:** `PeepSoGroupRepository::listBrowsableGroupIds` (excludes secret) → `GroupContextResolver::forManyGroups` → `GroupActivityHeatService::forGroups` for heat → in-memory sort by (`is_verified`, `posts_last_7d`, `member_count`) all DESC.

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
- **Mapping:** Static rank catalog from `bcc_options`. `viewer.*` from `bcc_user_ranks`. `auto_derived_rank` may differ from `current_rank` if an admin-conferred rank was revoked — the user drops to `auto_derived_rank` per §E2 revocation rule.

#### `POST /bcc/v1/admin/ranks/award`

Confers a rank (admin-only; Foreman+).

- **Auth:** Bearer + admin capability (`bcc_admin_award_ranks`)
- **Body:**
  ```json
  {
    "user_id": 42,
    "rank": "foreman",
    "reason": "Sustained governance leadership in the Cosmos hub."
  }
  ```
- **Response 201:**
  ```json
  {
    "awarded": true,
    "user_id": 42,
    "rank": "foreman",
    "awarded_by": 1,
    "awarded_at": "2026-04-27T14:50:00Z"
  }
  ```
- **Errors:** `bcc_permission_denied` (not admin), `bcc_invalid_request` (bad rank, auto-assignable rank passed), `bcc_conflict` (already at rank)
- **Rate limit:** 30/day/admin
- **Mapping:** Insert into `bcc_user_ranks`. Emits `bcc_rank_awarded`.

#### `DELETE /bcc/v1/admin/ranks/:rank/:user_id`

Revokes a rank.

- **Auth:** Bearer + admin
- **Response 200:**
  ```json
  {
    "revoked": true,
    "user_id": 42,
    "rank": "foreman",
    "user_drops_to": "journeyman"
  }
  ```
- **Errors:** `bcc_permission_denied`, `bcc_not_found`
- **Mapping:** Sets `bcc_user_ranks.revoked_at`. User's `current_rank` is recomputed and returned as `user_drops_to` (= `auto_derived_rank` per §E2). Emits `bcc_rank_revoked`. **No Heavy celebration** for negative events (§E2 + §O1.2).

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
  - Filter SQL ← `PageDiscoveryService::query()` (the same service `/discover` already uses for the legacy bcc-page-slider block — discovery is shared, the legacy endpoint kept for back-compat)
  - Server translates canonical kind → legacy `_bcc_page_type` (validator→validator, project→builder, creator→nft) via `PageTypeMap`
  - Server translates canonical card-tier → reputation tier (legendary→elite, rare→trusted, uncommon→neutral, common→caution)
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

- `type` ∈ {`bcc_reaction`, `bcc_review`, `bcc_card_pulled`, `bcc_rank_up`}. V1 catalogue per §I2; @mentions / follow-posts / comments deferred to V1.5.
- `message` is server-rendered per §A2 — frontend renders verbatim. Plain English, capped at 200 chars (PeepSo's column width).
- `actor.handle` may be empty when the originating user has been deleted; the frontend renders the message verbatim regardless.
- `link` is a server-built relative path. Per type:
  - `bcc_reaction` → `/?focus=<act_id>` (jump back to the post)
  - `bcc_review` → `/v/<page-handle>` etc. (the reviewed page, route prefix per kind)
  - `bcc_card_pulled` → `/u/<actor-handle>` (the puller's profile)
  - `bcc_rank_up` → `/u/<recipient-handle>` (your own profile — progression strip lives there)
- Self-notifications are emitted only for `bcc_rank_up` (audit trail beyond the §O1.2 Heavy toast). Other types skip the dispatch when actor === recipient.

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
| `bcc_card_pulled` | the followee user | viewer === followee (impossible from the binder UI, defensive) |
| `bcc_rank_awarded` | the recipient (self-notification) | rank label not in catalog |

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
- Hidden-binder users contribute to count, never to names.
- Shadow-limited authors are excluded from social_proof, F1 ranking inputs, and pull-batch feed visibility for any viewer (§K1 + §O4.1).
- `social_proof: null` when the viewer has zero network connection.

### 5.4 §C3 — pull batching frozen

Pulls accumulate into a batch while the user keeps pulling. The batch closes after exactly **10 minutes of pull inactivity**; at close, the server emits one `pull_batch` FeedItem. The post body shows up to **3 top cards** + "+N more" (`more_count = card_count - 3`). Once posted, the FeedItem is **frozen**: subsequent unfollows do not edit or remove the post. Binder UI updates immediately on unfollow; feed does not.

Server contract: `pull_batch.frozen` is always `true` in V1. The field exists for forward compatibility (V2 may introduce live batches).

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
| `counts.binder_size` | `peepso_follower` filtered to BCC card kinds |
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
| `body.*` | per-kind, sourced from kind-specific tables (`bcc_trust_votes` for reviews, `bcc_pull_meta` batches for pulls, `bcc_onchain_signals` for signals, etc.) |
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
- `bcc_pull_meta` — `(follow_id, tier_at_pull, batch_id, pulled_at)` (§C2)
- `bcc_user_ranks` — `(user_id, rank_key, awarded_by, awarded_at, revoked_at, revoke_reason)` (§E2)
- `wp_usermeta.bcc_handle` (§B6)
- `wp_usermeta.bcc_primary_local_group_id` — singleton pointer to the user's primary Local; membership ledger itself stays in PeepSo's `peepso_group_members` per the single graph rule (§E3)
- `wp_usermeta.bcc_ui_familiar` (§N5 — boolean for UI dual-label drop-off only)
- `wp_usermeta.bcc_floor_visits` (§O5 — integer counter for Level-2 unlock gate; distinct from §N5's familiarity boolean per the §N5 scope clarification)
- `wp_usermeta.bcc_first_review`, `bcc_first_vouch`, `bcc_first_dispute`, `bcc_first_local_join`, `bcc_first_wallet_link` (§O1.2)
- `wp_usermeta.bcc_privacy_*` keys (§K2)
- `wp_usermeta.bcc_highlights_dismissed_until` (§O2)

**Removed via 2026-04-27 anti-overengineering pass:**
- `bcc_user_locals` (duplicated `peepso_group_members`; replaced with `bcc_primary_local_group_id` user-meta key)
- `bcc_page_claims` (duplicated `bcc_onchain_claims`; merged via `entity_type='page'` + `recovery_pending` column)

---

## 7. Resolved contract decisions

All ten open items locked **2026-04-27**. Phase 1 implementation may begin.

1. **Avatar URLs** — **absolute URL, CDN-ready.** No relative paths. The server controls the host so a CDN origin can be swapped in without a contract change. See §1.7 (Asset / media URLs) and §6.1.
2. **Currency formatting** — **server-side abbreviated** with K/M/B suffixes (1 decimal max). Full numeric value always present in `raw`. Thresholds: `< 1k` full numerals · `≥ 1k` → K · `≥ 1M` → M · `≥ 1B` → B. See §2.8.
3. **Slug stability** — **immutable post-creation.** Admins rename via display name only. `links.self` URLs are stable forever. See §1.7 (Slugs).
4. **Member-card pull semantics** — **member pulls count toward `binder_size`.** Member cards are first-class binder citizens; no separate `following_count` field. See §3.2 field rules.
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
- **NFT gallery endpoints** (`GET /creators/:slug/gallery`, `GET /collections/:id/pieces`) — Phase 6.
- **Binder summary endpoint** (`GET /me/binder/summary`) — Phase 6.
- **Email digest endpoints** — opt-in only per §I1; deferred to V1.5.
- **Per-event notification preferences endpoints** — deferred to V1.5 per §I1.
- **@mentions notification + composer parsing** — deferred to composer v2.

### 8.1 Registered with stub data (V1-shipped, scorers deferred)

These routes ARE registered in V1 and return contract-compliant envelopes today, but the upstream data sources / scorers haven't landed yet. The frontend can call them without 404'ing; payloads are valid-but-empty until the deferred work ships. Listed here so re-readers don't mistake "registered + empty" for "missing."

- **Highlight strip endpoint** (`GET /me/highlights`, `POST /me/highlights/:id/dismiss`) — registered. `GET /me/highlights` returns `{items: []}` until the slot scorers (negative / positive / external) land. `POST /me/highlights/:id/dismiss` is fully implemented (dismissal pipeline + per-slot TTLs in `wp_usermeta`). Anonymous `GET` returns 401 per §7 item 5. Scorer roadmap is documented inline at `app/Domain/Core/Services/HighlightsService.php`.

### 8.2 Registered and fully wired in V1

These routes ARE shipped in V1 with real data — earlier drafts of this doc listed them as Phase 2 / Phase 5 deferrals, but implementation has caught up and they're now first-class V1 surfaces. Documented here so the contract matches reality.

- **Composer endpoints** — both fully wired:
  - `POST /posts` accepts `kind: 'status' | 'review'` (V1 set; disputes / blog / post-as-entity remain V1.5/V2 per §P1). Routes through `PostsService::createStatus` / `createReview` which write via PeepSo's canonical `add_post` path. Auth-required; rejects unknown kinds with `bcc_invalid_request`/400. Reviews are gated on Level 2 + reputation tier ≥ neutral via `FeatureAccessService`.
  - `POST /reactions` accepts §D5 kinds `'solid' | 'vouch' | 'stand_behind'` (locked). Routes through bcc-core's `PeepSoReactionWriter` (single-graph rule). Throttled at 60/minute per viewer. Returns the post-mutation `{counts, viewer_reaction}` shape so the frontend patches its cache without a feed refetch. `DELETE /reactions/:feed_id` also registered.
  - Bonus: `DELETE /me/reviews/:id` is also live and routes through `PostsService::removeReview`.
- **Onboarding endpoints** — all four fully wired:
  - `POST /auth/signup` — email / password / handle account creation. Rate-limited; validates handle availability; maps `db_insert_error` race conditions to `bcc_conflict`/409.
  - `GET /onboarding/suggestions` — three buckets (validators / projects / creators) populated via `PageDiscoveryService::query` + `CardViewService::getCard`. Returns real `Card` view-models per §3.2. Cached `private, max-age=60` for the wizard's tab-switching.
  - `PATCH /me/handle` — §B6 7-day cooldown enforced FIRST so probe attempts can't bypass it, then validation + conflict detection. No-op renames short-circuit without arming the cooldown.
  - `POST /me/onboarding/complete` — persists the `bcc_onboarded` flag + optional `home_chain` (validated against the `HOME_CHAINS` enum). Response carries `rank_label` (server-rendered per §A2 — the §O1 dopamine moment renders it verbatim, no client-side rank mapping). Idempotent on re-run.
  - Bonus: `GET /me/onboarding/status` is also registered (read-side flag check; not previously listed in §8).
- **Directory endpoints (§G1/§G2)** — both fully wired:
  - `GET /cards` — paginated list of Card view-models with `kind`/`tier`/`sort`/`q` filters. Wraps `PageDiscoveryService` (shared with the legacy `/discover` endpoint kept for back-compat with the bcc-page-slider block); each row hydrated through `CardViewService::getCard()` so the per-item shape is identical to the single-card endpoint.
  - `GET /cards/search` — top-N suggestions for the §G1 nav-bar autocomplete. Internally calls bcc-search via `rest_do_request` and maps the flat result shape (reputation_tier → card_tier, category_slug → card_kind, route prefix per kind) into the `SearchSuggestion` shape per §A2.
- **Notifications endpoints (§I1)** — fully wired:
  - `GET /me/notifications` — cursor-paginated list scoped to `not_module_id = BCC_NOTIFICATION_MODULE_ID` (= 9000). Server-rendered messages + server-built `link` per type per §A2.
  - `GET /me/notifications/unread-count` — drives the bell badge; frontend polls 60s + on window focus.
  - `POST /me/notifications/mark-read` — single (`{id: N}`) + bulk (`{}`) in one route. Idempotent.
  - `NotificationDispatcher` subscribes to `bcc_reaction_added`, `bcc_review_published`, `bcc_card_pulled`, `bcc_rank_awarded`. Writes through `PeepSoNotificationWriter` (bcc-core) — single-graph rule per §I1. The `bcc_reaction_added` / `bcc_reaction_removed` events were added to `ReactionsEndpoint` as part of this work (only event the catalogue was missing).
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
- **§4.10 Notifications** — new section. Locks the §I1 contract: `GET /me/notifications`, `GET /me/notifications/unread-count`, `POST /me/notifications/mark-read`. Storage layer is `peepso_notifications` scoped to `BCC_NOTIFICATION_MODULE_ID = 9000` (single-graph rule). Notification view-models carry server-rendered messages + server-built `link` per §A2. V1 catalogue: `bcc_reaction`, `bcc_review`, `bcc_card_pulled`, `bcc_rank_up` — @mentions and follow-posts deferred to V1.5.
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
