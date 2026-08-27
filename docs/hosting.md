# Hosting topology

Three hosts. They are not interchangeable.

| Host | Serves | Runs on |
|---|---|---|
| `bluecollarcrypto.io` (apex) | Public site | **Vercel** — not Hostinger |
| `cms.bluecollarcrypto.io` | WordPress core, `wp-admin`, REST (`bcc/v1`, `bcc-trust/v1`, `peepso/v1`, `wp/v2`) | Hostinger |
| `stage.bluecollarcrypto.io` | Staging WordPress (core + frontend) | Hostinger |

Verified against the live hosts: the apex returns **403** on `/wp-json/` and
**200** on pages; `cms.` returns **200** on `/wp-json/`. If you ever see the
apex answer a REST call, something is misrouted.

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

> **Transport is not yet decided.** Running this from a GitHub-hosted runner
> would require enabling Remote MySQL and allowlisting runner IPs (in practice
> `%`), exposing the production database to the internet, with credentials in a
> **public** repository's Actions secrets, to read two rows. That trade has been
> rejected. The candidates are an on-host Hostinger cron, or a token-guarded
> internal REST endpoint that returns the rows. Until one is chosen this script
> is run on-host or by hand, and no CI workflow invokes it.
