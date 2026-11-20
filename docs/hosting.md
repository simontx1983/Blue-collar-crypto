# Hosting topology

Three hosts. They are not interchangeable.

| Host | Serves | Runs on |
|---|---|---|
| `bluecollarcrypto.io` (apex) | Public site | **Vercel** — not Hostinger |
| `cms.bluecollarcrypto.io` | WordPress core, `wp-admin`, REST (`bcc/v1`, `bcc-trust/v1`; `peepso/v1`, `wp/v2` and the rest for admins only) | Hostinger |
| `stage.bluecollarcrypto.io` | Staging WordPress (core + frontend) | Hostinger |

Verified against the live hosts: the apex returns **403** on `/wp-json/` and
**200** on pages; `cms.` returns **200** on `/wp-json/bcc/v1/ranks`. If you
ever see the apex answer a REST call, something is misrouted.

To check `cms.`, probe a BCC route such as `/wp-json/bcc/v1/ranks`, never the
REST index. Only BCC's namespaces answer a caller who is not an admin, so
`/wp-json/` itself is **401** to a signed-out caller (bcc-trust
`RestRouteAllowlist`).

**Before enabling a service that calls back into `cms.` signed out:**
enabling any third-party service that calls back into cms `/wp-json/` while
signed out requires an `EXEMPT_ROUTES` PR in bcc-trust **first**. That covers
Wordfence Central, QUIC.cloud image/CSS/CDN callbacks over GET, Rank Math
headless `getHead`, WP Mail SMTP provider webhooks and Hostinger callbacks.
Since bcc-trust #761, every signed-out non-BCC `/wp-json/` request answers
**401**, so such a callback fails until its exact `"METHOD /route"` is exempted.

## Production is deliberately asymmetric

```
production   WP_SITEURL = https://cms.bluecollarcrypto.io
             WP_HOME    = https://bluecollarcrypto.io      <- the apex
staging      WP_SITEURL = WP_HOME = https://stage.bluecollarcrypto.io
```

`WP_HOME` is the apex in production because the public site is the Vercel
frontend, not WordPress. **Setting `WP_HOME` to `cms.` would point visitors at
WordPress and bypass the frontend entirely.** It looks like an inconsistency
worth tidying. It is not. Leave it.

Staging is symmetric because it has no separate frontend host.

The frontend reaches WordPress only through `NEXT_PUBLIC_BCC_API_URL`, which
must be `https://cms.bluecollarcrypto.io` — never the apex, never staging from a
production build. `bcc-frontend/src/lib/env.ts` rejects both at the single point
where the value is read.

## ⚠️ The wp-config constants mask drift; they do not prevent it

When `wp-config.php` defines `WP_SITEURL`/`WP_HOME`, WordPress **ignores**
`wp_options.siteurl` and `wp_options.home` completely.

That is the whole failure mode. A restored or copied database can carry another
environment's URLs indefinitely while every page, every admin screen and every
REST call serves correctly. Nothing surfaces it, because nothing reads those
rows. This is not hypothetical: a staging-flavoured dump was restored into
production and sat there serving correctly, with the rows pointing at
`stage.bluecollarcrypto.io`.

Production's rows have since been corrected to `https://cms.bluecollarcrypto.io`
and `https://bluecollarcrypto.io`, verified by direct query and against the live
REST API. **`scripts/site-url-guard.php` is regression protection, not an
outstanding repair** — it reads the rows directly, which is the only way to see
past the constants.

## wp-config.php block

Paste into each server's `wp-config.php`, above `/* That's all, stop editing! */`.

Values are plain literals per host. `BCC_ENV` is a **PHP constant** in this
stack — see `docs/environment.md` and `bcc-core/src/Admin/EnvBanner.php`, which
reads it via `defined('BCC_ENV')`. It is never an OS environment variable, so
there is nothing to read from the environment and nothing for a cloned config to
inherit if a variable goes missing.

`BCC_ENV` takes an **exact, case-sensitive** token from the closed vocabulary in
[environment.md](environment.md#bcc_env--the-canonical-tokens): `production`,
`staging`, `local`, `dev`. The blocks below are the canonical values — copy them
verbatim.

> **`prod` is a legacy banner alias and must not be used for deployed
> production.** The banner still renders it identically, but
> `IdentityEndpoint` reports it verbatim and `scripts/site-url-probe.sh`
> compares against the literal `production` — so a host on `prod` turns the
> daily `site-url-guard` red. Anything outside the vocabulary (`Live Site`,
> `Production`, `testnet`) reads as `ENV UNKNOWN` and also fails the guard.

```php
// ---- PRODUCTION (cms.bluecollarcrypto.io) --------------------------------
// ASYMMETRIC ON PURPOSE. WP_HOME is the Vercel apex, not this host.
// Do not "tidy" WP_HOME to cms. — that bypasses the frontend.
define( 'BCC_ENV',              'production' );
define( 'WP_ENVIRONMENT_TYPE',  'production' );
define( 'WP_SITEURL',           'https://cms.bluecollarcrypto.io' );
define( 'WP_HOME',              'https://bluecollarcrypto.io' );
```

```php
// ---- STAGING (stage.bluecollarcrypto.io) ---------------------------------
// Symmetric: staging serves its own frontend.
define( 'BCC_ENV',              'staging' );
define( 'WP_ENVIRONMENT_TYPE',  'staging' );
define( 'WP_SITEURL',           'https://stage.bluecollarcrypto.io' );
define( 'WP_HOME',              'https://stage.bluecollarcrypto.io' );
```

`wp-config.php` is gitignored and exists only on the servers, so the blocks above
are documentation. **This file is Markdown and is never executed** — there is
deliberately no `.php` file carrying these values, so nothing can be reached by a
web request.

## Checking for drift

`scripts/site-url-guard.php` compares `wp_options.siteurl` / `.home` against the
expected identity for an environment. Read-only — two `SELECT`s, no writes.

```bash
BCC_ENV=production \
BCC_DB_HOST=... BCC_DB_PORT=... BCC_DB_USER=... BCC_DB_PASS=... BCC_DB_NAME=... \
php scripts/site-url-guard.php
```

Exit `0` = agree · `1` = drift · **`2` = could not check**. Treat `2` as a
failure wherever this is automated: a guard that quietly stops checking is
indistinguishable from one that passes.

Database users are strictly per-database — production's user gets `#1044 Access
denied` against the staging database — so each environment needs its own
credentials.

### Two transports, two files — on purpose

Running the mysqli guard from a GitHub-hosted runner would require enabling
Remote MySQL and allowlisting runner IPs (in practice `%`), exposing the
production database to the internet, with credentials in a **public**
repository's Actions secrets, to read two rows. That trade is rejected.

| File | Transport | Where it runs |
|---|---|---|
| `scripts/site-url-guard.php` | direct mysqli | **on-host** (Hostinger cron, or by hand) |
| `scripts/site-url-probe.sh` | HTTPS + shared secret | **CI** (`.github/workflows/site-url-guard.yml`, daily) |

They are deliberately separate files so nobody wires mysqli into Actions later
and reopens that door.

### `GET /bcc/v1/internal/identity`

Registered by `bcc-core` (`src/Rest/IdentityEndpoint.php`) and gated by the
`X-Bcc-Internal` header against `BCC_INTERNAL_CRON_SECRET` — the same scheme the
Vercel cron relay already uses. Exists on **both** production and staging.

```json
{
  "env": "production",
  "rows":      { "siteurl": "...", "home": "..." },
  "constants": { "siteurl": "...", "home": "..." },
  "options_table_count": 1
}
```

- **Rows and constants both**, because the drift IS the delta between them.
- Rows are read with `$wpdb->get_var()`, never `get_option()` — `get_option`
  runs through `pre_option_siteurl`, which is how WordPress returns `WP_SITEURL`
  when the constant is defined, so it would return the constant and compare a
  value to itself.
- **`env` lets the caller cross-check the host it believes it reached**, which
  defangs every misrouting failure: the host states its own identity rather than
  the caller assuming it.
- **No table prefix is returned.** `options_table_count` gives callers enough to
  detect ambiguity (>1 ⇒ investigate on-host) without handing out the schema
  knowledge most SQL-injection payloads need.
- Caching is refused twice — `Cache-Control: no-store` *and* the LiteSpeed
  exclusion, because LSCWP caches REST on this site and a cached identity
  response would report healthy long after the rows changed.

## Repair: one member's requests all answer 503 `bcc_unavailable`

bcc-trust reads a member's token state (the user-meta keys
`bcc_token_version`, `bcc_jwt_denylist` and `bcc_jwt_retired`) in one bounded
query of 30 rows. If a member ever has 30 or more rows across those keys, the
read counts as unknown, every bearer request of theirs answers 503, and each
read logs `token state read failed`. It does not heal by itself. On the host:

1. List the rows: `wp user meta list <id> --keys=bcc_token_version,bcc_jwt_denylist,bcc_jwt_retired --fields=umeta_id,meta_key`.
2. Keep the **newest** row (highest `umeta_id`) of each key and delete the
   rest: `wp db query "DELETE FROM <prefix>usermeta WHERE umeta_id IN (…)"`.
3. End the member's sessions: they sign out everywhere from the app, or bump
   `bcc_token_version` (`wp user meta update <id> bcc_token_version <current + 1>`).

The same note sits on `TokenStateRepository::MAX_ROWS` (F-508).
