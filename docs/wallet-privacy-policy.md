# Wallet Privacy Policy

**Status:** BINDING as of 2026-07-23. Set by the product owner.
**Applies to:** every client-accessible surface — REST responses, RSC/flight
payloads, profiles, feeds, search, notifications, cards, admin-adjacent reads.

## The rule

> Verified wallet addresses must **never** be visible to other users.
>
> User A may view and manage their own connected wallets **only** on their own
> account surface. User B must not receive User A's wallet addresses through
> profiles, REST responses, feeds, search, notifications, or any other
> client-accessible surface.
>
> Wallet ownership may support internal trust or eligibility signals, but public
> UI may expose only **derived, non-identifying status** such as "Wallet
> verified" when explicitly required — never the address, shortened address, ENS
> name, balances, holdings, or transaction history.

## What this forbids, explicitly

A **shortened / masked address is a leak.** `0xab58…ec9b` is not anonymised: the
first-6 + last-4 form is the standard way wallets are visually matched, and it is
directly searchable against a block explorer. Treat `address_short` exactly as
you would treat `address`.

Also forbidden on any non-self surface:

- ENS names (they resolve 1:1 to an address)
- balances (`balance`, `min_balance`, per-holder amounts)
- holdings attributed to an identifiable member
- transaction history
- **any join that correlates a wallet to a member identity** — an address next to
  a handle, display name, avatar, or profile link is the most severe form of this
  violation, because it de-anonymises in both directions

## What is permitted

- `verifications.wallets_verified` — an integer count
- a boolean "Wallet verified" badge or filter facet
- chain-level or collection-level **aggregates** not attributable to one member
- contract addresses (a contract is not a member wallet)
- the user's **own** wallets, on their own account surface, sourced from the
  session-scoped `GET /me/wallets` — never from another member's profile payload

## Design consequence

The canonical non-identifying signal already exists and should be the only thing
crossing a member boundary:

```
verifications.wallets_verified: int
```

If a surface needs "does this member have a verified wallet", it uses that. It
does not receive an array of wallet objects and count them client-side, because
that requires shipping the wallets.

## Note on the profile settings migration (2026-07-23)

The `/u/[handle]` profile page previously rendered a visitor-visible wallets list
inside a read-only shadow settings panel (`ProfilePanel.tsx`). That panel was
deleted when `/settings/*` was absorbed into owner-gated profile tabs.

**Its removal is an intentional privacy correction, not a regression.** It must
not be reinstated, and no replacement visitor-facing wallet surface should be
built. Wallet management lives on the owner-only `account` tab, backed by the
session-scoped `/me/wallets` endpoint.

## Audit findings — 2026-07-23

A full sweep of REST serializers, RSC payloads, frontend props, caches, logs,
telemetry, notifications, search documents and export paths found **14
cross-user disclosure paths** (13 in the first pass + the `md5`-email oracle
below). All 14 are **REMEDIATED and SHIPPED** as contract **v1.51 (BREAKING)** —
`core#35 → trust#112 → umbrella#88 → fe#64`, merged and auto-deployed to
**staging**. Production is not yet deployed (see the data note under finding 14).

| # | Surface | Severity | Root cause | Status |
|---|---|---|---|---|
| 1 | `GET /nft-pieces/{chain}/{contract}/{id}` | **P0** | Anonymous route emitting full `wallet_address` + `address_short` + `balance` **joined to** `user{id,handle,display_name,avatar_url}`. Enumerable by iterating token IDs → a wallet→member table | FIXED — `owner` reduced to `{is_linked}` |
| 2 | `GET /users/{handle}` | **P0** | `address_short` ungated to every non-self viewer incl. anonymous; only `address` sat behind `$isSelf` | FIXED — `wallets: []` for non-self |
| 3 | `enforceWalletPrivacyAtEgress` | **P0** | "Fail-closed" net was a **denylist of one key** (`address`); could not catch #2 and never logged it | FIXED — allowlist (non-self ⇒ `[]`, anything else is P0) |
| 4 | `CardViewService` `operator_address` | High | Public validator cards published an on-chain address matched against the claimant's verified wallet; FE rendered it truncated | FIXED — `operator_verified: bool` |
| 5 | `Logger::redactSensitive` | High | Redaction keyed on exact names `address`/`wallet_address`; five call sites passed the key **`wallet`**, writing FULL addresses to disk. `BonusService` wrote `user_id` + address on one line | FIXED — substring key match |
| 6 | `Logger::redactSensitive` | High | "Safe" redaction *produced* `first-6…last-4` — the forbidden shortened form — next to `user_id` | FIXED — salt-keyed HMAC fingerprint |
| 7 | `GET /wallets/project/{post_id}` | High | No user scoping; `\d+` matched `0`; nothing ever sets a non-zero `post_id`, so `/wallets/project/0` dumped 200 arbitrary members' wallet links (full addresses for admins) | FIXED — route deleted |
| 8 | `/suggestions/users` `co_validator` | Medium | Label named the shared validator; delegator sets are public on-chain, narrowing the member's wallet to one published list — a member↔holding join | FIXED — generic label, moniker lookup removed |
| 9 | `/members` `EdgeCache::tag` | Medium | Viewer-varying payload tagged into LiteSpeed, whose REST key varies on Cookie **not** Authorization; FE sends `credentials: "omit"`, so authed responses shared the anonymous bucket | FIXED — tag only when `get_current_user_id() === 0` |
| 10 | RSC payload `/u/[handle]` | Medium | Whole `profile` passed to client components ⇒ `wallets[]` serialized into every visitor's HTML | FIXED via #2 (server stops sending it) |
| 11 | RSC payload `/c/[slug]/[tokenId]` | Medium | Whole `piece` passed to a client component ⇒ owner wallet + handle in anonymous HTML | FIXED via #1 |
| 12 | NFT-piece `Cache-Control` | Medium | Wallet-bearing payload shared-cacheable for up to 30 min with no `private` | FIXED via #1 (payload now viewer-invariant); invariant documented at the constants |
| 13 | `wallets[].id` to non-self | Low | Stable cross-user join key; made #7's enumeration useful | FIXED via #2 |

### Verified clean

bcc-search (no wallet field in any indexed document), notification builders and
email templates, `AccountSecurityMailer` (truncates, but sends only to the
wallet's own owner), analytics/telemetry (none exists; Sentry has
`sendDefaultPii: false` and Session Replay deliberately off), export paths (none
exist), holder-group and NFT-selection routes (all `get_current_user_id()`-scoped),
`CreatorGalleryEndpoint` (contract addresses only), and `GET /wallets` +
`WalletsSection` (owner-only by both data path and route).

### 14 — FIXED — `md5(wallet_address)` placeholder email (Gravatar oracle)

**`AuthSupport::placeholderEmailForWallet` stored `md5(wallet_address)` as the
user's `user_email`** for wallet-only signups. A hash of an address is barred by
this policy ("hashed … or otherwise represented"). The escape path is Gravatar,
which publishes `md5(user_email)`: if PeepSo's `avatars_wordpress_only` or
`peepso_use_gravatar` + `avatars_gravatar_enable` options are on, the resulting
URL ships in `avatar_url` on every public surface, giving an attacker with a
candidate address a **confirmation oracle** for member↔wallet.

**Fix (2026-07-23):** the token is now an HMAC keyed on `wp_salt('auth')`, which
the attacker does not know, so the oracle no longer resolves. Determinism-per-
wallet (the signup-retry idempotency net behind the `wallet_links` UNIQUE) is
preserved; it fails closed to a random token if the salt is ever empty.
`AccountRecoveryService::isPlaceholderEmail` is domain-only, so recovery logic is
untouched. Existing pre-fix accounts are repaired by
`includes/database/backfill-wallet-placeholder-emails.php` — a one-shot backfill
that rewrites each placeholder email to a `user_id`-keyed token via `$wpdb` (no
wallet lookup, so orphaned and multi-wallet accounts are handled uniformly;
direct write avoids `wp_update_user()`'s change-notice mail to the dead
placeholder). Covered by `WalletPlaceholderEmailTest`.

**How the backfill runs (trust#118).** The backfill is executed by the
**data-migration runner** — `bcc_trust_run_pending_migrations()` on
`plugins_loaded`, independent of `BCC_TRUST_SCHEMA_VERSION`. It is *not* invoked
inside `bcc_trust_create_tables()`; wiring it to the schema-install gate is
exactly what left it dormant when v1.51 shipped (the fix touched no
`schema-*.php`, so the gate never fired). Because the runner is schema-hash
independent, a **files-only deploy is sufficient** to migrate a DB — no schema
bump required. See `docs/database-schema.md` ▸ *Schema-install gate vs.
data-migration runner*. The local dormant accounts have been migrated; staging
migrates on the first request after the trust#118 deploy.

> **Data note.** The *code* fix protects every future signup on any DB. The
> *backfill* only matters for accounts that already carry an `md5`-derived
> placeholder email. A fresh production launch with no migrated users needs only
> the code fix. Migrating existing local/staging accounts happens automatically
> via the runner on `plugins_loaded` after deploy — no activation, schema bump,
> or manual invocation required. **Production rollout stays gated on the
> PeepSo-Gravatar config check** (`avatars_wordpress_only` / `peepso_use_gravatar`
> + `avatars_gravatar_enable`): confirmed off on local and staging; verify before
> the prod deploy so the oracle window never opens.

## Enforcement

Any change touching wallet data must be checked against this document. When in
doubt, emit the count, not the wallet.
