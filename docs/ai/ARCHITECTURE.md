# Architecture

This page gives the system-wide picture, which no other doc has. Each section links to the canonical doc for its area:
- [`domain-seams.md`](../domain-seams.md) covers which bcc-trust domain owns what;
- [`pattern-registry.md`](../pattern-registry.md) is where every shared piece of logic lives (the §11 reuse index);
- [`api-contract-v1.md`](../api-contract-v1.md) is the REST contract;
- `bcc-frontend/docs/frontend-doctrine.md` explains the frontend rules and the reasons for them.

Paths are given as `repo:path`. Plugin repos live on disk under `app/public/wp-content/plugins/<repo>/`.

## 1. Shape of the system

```
 Browser
   │  HTTPS
   ▼
 bcc-frontend (Next.js 15, React 19) on Vercel ── bluecollarcrypto.io
   │  • server components fetch with the member's bearer JWT; anonymous reads may revalidate (60 s)
   │  • NextAuth holds the session (HttpOnly cookie); the BCC JWT is kept inside it
   │  • /api/internal/verify-wallet-signature: verifies sr25519 for PHP (no sr25519 in PHP)
   │  • Vercel Cron, every minute ──► /api/internal/cron/indexer-tick ──► WordPress
   ▼  Authorization: Bearer <BCC JWT>, credentials: omit
 WordPress REST ── cms.bluecollarcrypto.io (Hostinger, LiteSpeed; REST is not edge-cached)
   ├─ bcc-core    infrastructure: ServiceLocator, DB, Throttle, Logger, crypto, PeepSo writers, wp-admin shell
   ├─ bcc-trust   reputation, disputes, on-chain, social, most REST routes (/bcc/v1, /bcc-trust/v1)
   ├─ bcc-search  /bcc/v1/search*
   └─ PeepSo      profiles, groups, pages, activity, messages (data model; its UI is not used)
   │
   ├─► MariaDB (production) / MySQL 8 (local). Tables: wp_bcc_*, peepso_*, WordPress core
   ├─► Redis object cache (production drop-in): rate limits, generation counters, caches
   └─► Chains and providers: RPC/LCD endpoints, Alchemy, the Helius webhook (Solana), on-chain fetchers
```

Facts behind the picture:
- **Hosts** [DOC]: the hosts are listed in [`docs/hosting.md`](../hosting.md) and the README.
  - `WP_HOME` is the apex domain and `WP_SITEURL` is `cms.`. This asymmetry is deliberate.
  - The apex answers 403 on `/wp-json`.
  - Staging WordPress is `stage.bluecollarcrypto.io` and is **not** split, so staging cannot test split-sensitive behaviour.
- **Headless** [VERIFIED]: WordPress builds `rest_url()` from `home_url()`, which is the Next.js app. `bcc-core` rebases that URL to `site_url()` through `HeadlessOrigin` (`bcc-core:bcc-core.php`, the `rest_url` filter).

## 2. Repos and responsibilities

| Repo | Version (header) | Responsibility | Depends on |
|---|---|---|---|
| bcc-core | 1.2.18 | ServiceLocator + contracts + null objects, `DB::table`, `TransactionState`, `AdvisoryLock`, `Throttle`, `Logger`, `DegradationMetrics`, crypto verifiers, PeepSo writers, `Permissions`, wp-admin shell (Moderation / On-Chain / System menus) | nothing BCC |
| bcc-trust | 1.2.34 | the merged trust + disputes + on-chain plugin; most REST endpoints; cron; schema | bcc-core |
| bcc-search | 1.0.6 | `GET /bcc/v1/search`, `/search/users`, `/search/groups` | bcc-core |
| bcc-frontend | (package.json) | every user-facing page | the REST contract |
| umbrella | — | docs, CI guards, agent tooling | checks out all four in CI |

[VERIFIED: the plugin headers.] Releases are batched, and version headers are not bumped per feature (see [DEPLOYMENT.md](DEPLOYMENT.md)).

## 3. Backend

### 3.1 Boot and dependency direction [VERIFIED]

**Dependency direction.**
- bcc-trust and bcc-search refuse to boot, with an admin notice, if `BCC_CORE_VERSION` is undefined (`bcc-trust:bcc-trust.php` and `bcc-search:bcc-search.php`, near the top).
- Core never imports trust. It reaches trust only through interfaces in `bcc-core:src/Contracts/`.

**Boot guards.**
- Core returns early if the GMP extension is missing.
- Trust goes **inert** if its encryption key constant is undefined. It clears its crons, and the null read service then treats everyone as suspended, so access fails closed.

**Cross-plugin services** (`bcc-core:src/ServiceLocator.php`):
- Each contract resolves through a filter.
- Providers must be on a hard-coded class allowlist, so a co-installed plugin cannot hijack a service.
- An unbound contract returns a `Null*` object, and null objects fail closed on security methods.
- The locator is frozen at `plugins_loaded`.
- *Why:* this design avoids fatals from plugin load order and rejects rogue providers.

**Inside bcc-trust.** `BCC\Trust\Core\Plugin::instance()` is a lazy container (`bcc-trust:app/Domain/Core/Plugin.php`). It sits alongside `OnchainPlugin` and `DisputesPlugin`.

**bcc-trust bounded contexts** (detail in [`domain-seams.md`](../domain-seams.md); [DISCREPANCY] that doc's line counts are stale):

| Context | Owns |
|---|---|
| `app/Domain/Core` | votes, the trust-score pipeline, the page read model, attestations, social features on top of PeepSo, notifications, moderation, auth endpoints, cron registry |
| `app/Domain/Rank` | ranks, findings and appeals, poll engine, rank sweeps |
| `app/Domain/Disputes` | dispute lifecycle and adjudication, now through community polls (see [FEATURES.md](FEATURES.md)) |
| `app/Domain/Onchain` | wallet links, chain fetchers, NFT holdings and indexers, validators and delegations, claims, gated groups |
| `app/Infrastructure` | `EdgeCache.php` only (the LiteSpeed bridge) |

*Why one plugin:* the predecessor plugins (trust-engine, disputes, onchain-signals) were already coupled through cross-plugin service calls, shared DB transactions and cron ordering. Merging them removed contract drift at the boundary ([HISTORICAL] record in `bcc-trust:MIGRATED-FROM.md`).

### 3.2 REST [VERIFIED]

**Namespaces.**
- `bcc/v1` is the main API, served by `*Endpoint.php` classes in trust, the search controllers and core's identity endpoint.
- `bcc-trust/v1` is a small legacy set: endorse, revoke-endorsement, device fingerprint, and GitHub/X OAuth (`bcc-trust:app/Domain/Core/Controllers/`).
- **New routes go in `bcc/v1`.**

**Route registration.**
- Each endpoint class has a static `register()`.
- `Plugin::registerRoutes()` calls `Envelope::init()` and then every `register()`.
- Route arguments must be **literal**, so the contract-parity scanner can resolve them.

**Envelope** (`bcc-trust:app/Domain/Core/REST/Envelope.php`):
- Hooks `rest_post_dispatch` for the two BCC namespaces.
- Success shape: `{data, _meta}`. Error shape: `{error: {code, message, status, data?}, _meta}`.
- Adds a request-id header.
- On 429, adds `Retry-After` and `X-RateLimit-*` from the denying bucket.
- Rewrites a failed-bearer 401 to `bcc_token_expired` or `bcc_token_invalid`.
- Clients branch on `error.code`, never on `message`.
- A read that can't be trusted answers `503 bcc_unavailable`.

**Rate limiting.**
- `BCC\Core\Security\Throttle` uses the atomic limiter or the persistent object cache, and **fails closed** when neither is available.
- `BCC\Trust\Core\Security\RateLimiter` sets per-action limits.
- Each endpoint declares its own budget.

**Contract enforcement.** The umbrella `scripts/contract-parity-guard.php` compares §4 headings with every `register_rest_route` in both directions, as a blocking CI check. See [CONVENTIONS.md](CONVENTIONS.md) "Contract changes".

### 3.3 Authentication and authorisation (summary; detail in [SECURITY.md](SECURITY.md))

**Bearer JWT.**
- Members are authenticated by `Authorization: Bearer <jwt>` (`bcc-trust:app/Domain/Core/Support/BearerAuth.php`, `JwtToken.php`).
- The bearer is accepted on REST requests only.
- If the token's revocation state can't be read, the answer is 503, not 401, so members aren't signed out spuriously.

**Sign-in gate.** Members sign in only through the app's `/auth/*` routes. Those routes carry a second factor, throttles and a captcha step-up. `WordPressSignInGate` refuses non-admins at every other WordPress or PeepSo login path.

**Admin.**
- Admin means the `manage_options` capability, checked by `BearerAuth::requireAdmin()`.
- `can_moderate` in auth responses is **display-only** (it decides whether the frontend shows the Admin menu).
- [UNKNOWN] No separate moderator capability was found.

**Suspension.** `BCC\Core\Permissions\Permissions::suspensionState()` is tri-state, and "unknown" is never cached or acted on. Suspended members are read-only (`SuspendedWriteGate`).

### 3.4 PeepSo integration

PeepSo supplies the social data model: profiles, groups, pages (the `peepso-page` post type), activity, reactions, follows, photos and messages. **Its UI is not used.**

**Writes.** BCC writes `peepso_*` tables **only** through `bcc-core:src/PeepSo/*Writer.php` (law §II.6 in [`GOLDEN_PATHS.md`](../GOLDEN_PATHS.md) §3, which includes a grep to prove it).
- `PeepSoGroupWriter::join` bypasses PeepSo's own approval, so every caller must pass a BCC server-side gate first. The `peepso-write-guard` skill checks this.
- The one approved direct-write exception is the closed-group join-request and invite doors ([`pattern-registry.md`](../pattern-registry.md) "Groups"). Don't extend it.

**Reads.** Reads go through repositories such as `PeepSoActivityRepository`.

**Why PeepSo stays behind adapters.** PeepSo is tolerated and replaceable, so services never call PeepSo classes directly. Its version is **pinned**: a core/add-on version mismatch once silently unregistered the add-ons (see [CONVENTIONS.md](CONVENTIONS.md) "Traps").

### 3.5 Data access

See [DATABASE.md](DATABASE.md) for:
- repository-only `$wpdb` access;
- the fail-closed read doctrine (`ProbesReads`, `GuardsReadFailures`, `MetaCacheGuard`);
- transactions (`TransactionManager`, `TransactionState`);
- schema install.

### 3.6 Caching [VERIFIED unless marked]

| Layer | What | Rule |
|---|---|---|
| Object cache | Redis drop-in in production | It is required: rate limiting and API budgets fail closed without it |
| Generation counters | The write path bumps a counter; read-path cache keys include it | The source of truth for bcc-search counters is an option, mirrored to cache, and it never regresses (`bcc-search:app/Support/CacheGeneration.php`) |
| Last-known-good | Search responses, hidden-activity and caution lists | Used instead of "empty" when a fresh read fails |
| Edge (LiteSpeed) | `bcc-trust:app/Infrastructure/EdgeCache.php` | **All BCC REST responses and all credentialed requests are excluded** (policies of 2026-08-06 and 2026-08-20). *Why:* entries keyed only by URL were served to browsers with the wrong CORS headers for days |
| Frontend | `ANON_SSR_REVALIDATE_SECONDS` (60 s) for anonymous server reads only | Authed or personalised responses are never cached: serving one viewer's data to another is the worst failure (`frontend-doctrine.md` §2.4) |
| React Query | staleTime 30 s, gcTime 2 min, retry 1, no refetch on window focus | `bcc-frontend:src/app/providers.tsx` |

### 3.7 Cron and background work

**Hook registry.** [`docs/cron-registry.md`](../cron-registry.md) is the canonical hook list ([DISCREPANCY] its line references are stale).
- Each plugin adds to `bcc_expected_cron_hooks`.
- Core's wp-admin Cron page shows drift.
- Every expected hook writes a `<hook>_last_success` heartbeat, and `/system/health` reads it.

**Recurring work.** Trust recalculation, graph and fraud updates, rank evaluation and poll close sweeps, weekly claim re-verification, digest, and Helius dedupe/reconcile. See the registry.

**Retired.** Automatic NFT discovery, automatic Hall provisioning and NFT enrichment ticks are all **[HISTORICAL]**. Owner rule: only an explicit admin action may create discovery work.

**Async work.** `BCC\Core\Cron\AsyncDispatcher` uses Action Scheduler or a single event. Validator pre-claim messages use a durable queue table plus a worker.

**Vercel Cron relay.** Vercel calls the frontend route every minute. That route authenticates the caller with a shared secret and forwards the tick to WordPress's indexer endpoint.

### 3.8 Observability [VERIFIED]

**Logger** (`bcc-core:src/Log/Logger.php`):
- Writes to a randomly named log file outside the web root and rotates it.
- Scrubs secret-like and wallet-shaped values; wallets are logged only as keyed fingerprints.

**DegradationMetrics** (`bcc-core:src/Observability/`):
- Keeps hourly counters per subsystem.
- The canonical subsystem map is in `bcc-core:bcc-core.php`, about 40 subsystems.
- **Every** subsystem and event must also be listed in `pattern-registry.md` and `GOLDEN_PATHS.md`, and `scripts/subsystem-count-guard.php` diffs the map against both.
- `record()` takes literal arguments.
- *Bake before alerting:* observe a metric over a real window before giving it a threshold.

**DegradationAlerter.** Mails, plus an optional webhook, once on entry into a degraded state and once on recovery. Auth mail and account-security mail are P1.

**Health and identity endpoints.**
- `/bcc/v1/system/health` collects throttle state, degradation counters, cron heartbeats, indexer and queue gauges. It is read by wp-admin's System Health page.
- `/bcc/v1/internal/identity` reports which environment the database believes it is. *Why:* a restored staging database once sat in production undetected.

**Error tracking.**
- The frontend uses `@sentry/nextjs` with PII scrubbing and no session replay.
- [UNKNOWN] No PHP-side Sentry SDK was found.

### 3.9 Admin: two surfaces (locked 2026-05-27)

- **wp-admin is the infrastructure cockpit**: configuration, secret *status* (never raw values), cron and queues, schema, developer tools, emergency repair. Examples: core's `SystemHealthPage`, `CronPage` and `ApiKeysPage`; trust's Chains page, where chain Halls are created.
- **Next.js `/admin/*` is the operations centre**: moderation, validator admin, reports, Halls admin. It is backed by `bcc/v1/admin/*` endpoints gated by `manage_options` (`AdminReportsEndpoint`, `AdminHallsEndpoint`, `AdminDigestEndpoint`, and others).
- **The rule:** a configuration or repair task belongs in wp-admin; a daily workflow belongs in Next.js. **Never add a third surface.**

### 3.10 Search

bcc-search has no separate index. Page search uses a FULLTEXT index on posts with a title-prefix fallback. Members and groups go through PeepSo's search with BCC's privacy filters.

- **How the frontend calls it.** The frontend calls `GET /bcc/v1/cards/search` (trust), an adapter over `/bcc/v1/search`. The results page calls `/search/users` and `/search/groups` directly.
- **Privacy rules.**
  - `hide_from_search` members are excluded.
  - Suspended members are dropped.
  - Secret and closed groups are excluded.
  - A privacy change bumps a generation counter so no older cached entry is reachable.
- **Trust enrichment.** If it is unavailable, results are served text-only, tagged as degraded, and never cached as last-known-good.

## 4. Frontend (`bcc-frontend`)

Canonical sources: `bcc-frontend:docs/frontend-doctrine.md` (the reasons behind each rule; §5 is the visual canon) and `bcc-frontend:CLAUDE.md` (tracked in this repo).

**Stack** [VERIFIED `package.json`]:
- Next.js 15 (App Router, `typedRoutes`), React 19, TypeScript in strict mode plus `exactOptionalPropertyTypes` and `noUncheckedIndexedAccess`;
- TanStack Query 5, NextAuth 4 (JWT strategy), Tailwind 3;
- Vitest 4 with jsdom;
- PostHog, Sentry, Vercel Analytics and Speed Insights.

**Route groups** (`src/app`):
- `(main)/(app)` is the app shell; `(main)/(detail)` holds post detail pages; `(main)/(marketing)`; `(auth)`; `(legal)`.
- Profiles: `/u/[handle]` for members, `/v/[slug]` for validators, `/p/[slug]` for projects, `/c/[slug]` for NFT creators.
- Communities and Halls live under `/communities`. `/halls/*` permanently redirects there.
- Settings moved into `/u/me?tab=…`.
- Admin is `/admin/*`.
- Permanent redirects are listed in `src/lib/route-moves.ts`. A 308 is cached by browsers, so get the destination right.

**Middleware** (`src/middleware.ts`):
- Anonymous `/` is *rewritten* (not redirected) to the welcome page, so the SEO root stays `/`.
- Private routes redirect to `/login?callbackUrl=…`.
- `/admin/*` is gated on login only. Capability is always enforced by the server.

**Data layer:**
- Two clients:
  - `bccFetch` / `bccFetchAsClient` (`src/lib/api/client.ts`) for `bcc/v1`;
  - `bccTrustFetch` for the legacy `bcc-trust/v1` envelope.
- Calls use `credentials: "omit"` deliberately:
  - stale WordPress cookies would win over the bearer and cause silent 401s;
  - cookies would split the edge-cache key.
- Endpoints live in per-domain `src/lib/api/*-endpoints.ts` files, one hook per capability in `src/hooks/`, and components use hooks only.
- `src/lib/api/types.ts` mirrors the contract **by hand**. Nothing checks it automatically, so a contract change must update the PHP builder, the contract doc and `types.ts` together.

**No business logic.**
- Scores, tiers, ranks, labels, permissions and feature flags arrive pre-computed.
- The UI may branch on a server enum but must never derive the value.
- Permissions are read through `src/lib/permissions.ts`, which degrades to "action hidden".

**Server vs client boundaries** (each rule traces to a past production build failure):
- `useSearchParams()` needs a `<Suspense>`.
- `next-auth/react` is never imported from a server file.
- `SessionProvider` lives only in `providers.tsx`.
- Server components import shared helpers only from hook-free, JSX-free modules.
- `next build` runs only on Vercel, so "it compiles locally" proves nothing.

**Auth** (`src/lib/auth.ts`):
- NextAuth uses JWT sessions. The providers are Google, X (OAuth 2.0 against x.com), `bcc-verified` (accepts a BCC JWT minted by the backend after email/OTP/2FA) and `wallet`. There is deliberately **no** email/password Credentials provider.
- The BCC bearer token lives inside the NextAuth session, never in localStorage.
- Near expiry, the token is refreshed via `/auth/refresh`.
- The OAuth bridge to WordPress is HMAC-signed with a single-use nonce.

**SEO** (`src/lib/seo/`, `src/app/robots.ts`, `src/app/sitemap.xml/`):
- Indexing happens only when `VERCEL_ENV=production` **and** the host is the hardcoded canonical host. Everything else is noindex.
- A profile is indexable only when the server says `indexable: true`, the same rule as the server sitemap (`PublicProfileIndex`).
- JSON-LD has no `aggregateRating`, because trust is not a star rating.

**Analytics and monitoring.**
- PostHog is opt-in per device. There is one wrapper module, `src/lib/analytics/posthog.ts`, and a closed typed event list ("adding one means disclosing it").
- PostHog is sanitized before sending; the identity is a pseudonymous keyed hash.
- Sentry: `sendDefaultPii: false`, URL queries stripped, no session replay.

**Security headers** (`src/lib/security-headers.ts`):
- Enforced: frame-ancestors none, nosniff, a strict referrer policy and a restrictive Permissions-Policy.
- The full CSP runs in **Report-Only** today. Enforcing it is a separate, deliberate change [PLANNED].

## 5. Known code ↔ doc mismatches (verify before relying on either)

| Topic | Code says | Doc says |
|---|---|---|
| BCC JWT lifetime | 7 days (`JwtToken::TTL_SECONDS`) | contract §1: "1h access, 30d refresh" |
| Request-id header | `X-Request-Id` (`Envelope.php`) | contract: `X-BCC-Request-Id` |
| `_meta` contents | `ApiResponse`: `{version, reaction_types}` on success, `{version}` on error; Envelope safety net: `{version, request_id}` (matches its docblock) | contract §1: the safety net gives `{request_id}` without `version` |
| `/search/users`, `/search/groups` envelope | registered under `bcc/v1` | bcc-search header says "raw / no envelope" ([UNKNOWN] which applies) |
| `DB::table` filter | removed (prevents table redirection) | stale docblock in `bcc-core:src/DB/DB.php` still mentions it |
| Frontend auth providers | Google, X, bcc-verified, wallet | `bcc-frontend:README.md`: "Credentials + Wallet" |
| Sentry config comment | runs on Vercel | `next.config.ts` comment: "runs on Local-by-Flywheel" (stale) |

The full list of stale docs is in [CURRENT_STATE.md](CURRENT_STATE.md).
