# Domain Cutover Runbook — apex → Vercel, WordPress → `cms.`

> **Status: CUTOVER COMPLETE (2026-08-19 22:09–22:13 CDT).** The apex and
> `www` now resolve to Vercel; `www` 308s to the apex. WordPress is canonical
> at `cms.bluecollarcrypto.io` — wp-admin and the REST API both stay on that
> host, and the `cms.` *root* 302 to `bluecollarcrypto.io/landing` is the
> intended hand-off to the frontend, not leftover §3.4 drift.
>
> Certificate issuance did **not** happen automatically: Vercel left the apex
> without a cert for ~25 minutes after the flip, and it took one manual
> issuance request. The Let's Encrypt cert dates from 2026-08-20 02:39 UTC.
> Budget for this on any future domain move rather than assuming auto-issue.
>
> All repo-side changes are merged and deployed to both environments
> (bcc-core #52/#53, bcc-trust #208/#209, bcc-search #22). Still open:
> the X portal consolidation in §5, and the OAuth callbacks baked into
> third-party dashboards, which have to be re-issued by hand.

Living procedure — edit in place, don't date-stamp (see
[docs/archive/README.md](archive/README.md)).

**No secret VALUES belong in this file — only the names.**

Moves the Next.js app onto `bluecollarcrypto.io` and re-homes WordPress at
`cms.bluecollarcrypto.io`. Companion docs:
[deploy-runbook.md](deploy-runbook.md) (plugin deploys),
[rollback-procedure.md](rollback-procedure.md) (§4a is the Vercel lever),
[environment.md](environment.md) (`BCC_FRONTEND_ORIGIN` semantics).

---

## 0 Why this is not just a DNS edit

`bluecollarcrypto.io` **is already production WordPress** (Hostinger,
`193.203.176.141`). The apex can only point one place, so putting the frontend
there means evicting WordPress from it — a WordPress hostname migration with a DB
rewrite, not a record swap.

Current state, verified by live DNS:

| Host | Points at | Role |
|---|---|---|
| `bluecollarcrypto.io` + `www` | Hostinger | production WordPress |
| `stage.bluecollarcrypto.io` | Hostinger | staging WordPress |
| `app.stage.bluecollarcrypto.io` | Vercel | staging frontend (already custom-domained) |
| `cms.bluecollarcrypto.io` | Hostinger origin `193.203.176.141` | production WordPress REST — **already live** |

**CL-7B is closed.** `cms.` was briefly CDN-fronted (`Server: hcdn`, edge IPs
`147.79.72.241`/`88.223.87.94`), which would have put authenticated REST behind
an edge that may cache on URL alone — the exact shape of the documented P0
cache-isolation leak, since the `.htaccess` bypass (§1.3) is a *LiteSpeed-at-origin*
rule. **The CDN was disabled 2026-08-19**; `cms.` now resolves to the same origin
IP as the apex and staging and returns `Server: LiteSpeed`. Risk closed by
removal rather than mitigation. Re-check with `curl -sI` after any hPanel change
that could re-enable it.

### The headless split — read this before touching `rest_url()`

WordPress is configured with **`WP_SITEURL` ≠ `WP_HOME`** (verified live at
`https://cms.bluecollarcrypto.io/wp-json/`):

```
"url":  "https://cms.bluecollarcrypto.io"   ← WP_SITEURL: where WordPress lives
"home": "https://bluecollarcrypto.io"       ← WP_HOME:    the public front door (Next.js)
```

Consequences, all of which bite silently:

- **`site_url()` → cms, `home_url()` → apex.** Anything serving a real file out of
  the WordPress install (`/wp-content/**`) must use `site_url()`. Anything linking
  a human to the product must use `home_url()` or `BCC_FRONTEND_ORIGIN`.
- **`rest_url()` is built from `home_url()`**, so it currently generates
  **apex** URLs — which become Next.js after the flip. That is what §4b's
  mu-plugin exists to fix, and it is why the X, GitHub and Helius callbacks
  (all `rest_url()`-derived) must be re-issued afterwards.
- **`home_url()` never changes across this cutover** (it was the apex before and
  stays the apex), so the JWT `iss` claim is stable — see the logout note below.

Nameservers are `ns1/ns2.dns-parking.com` (Hostinger hPanel). The apex also
carries **live MX + SPF** for Hostinger email and three domain-verification TXT
records. Staging is already frontend-and-WP-on-separate-hosts, so it is the
rehearsal environment and **keeps its current names**.

### Session impact — smaller than first assessed, and partly avoidable

An earlier draft of this runbook said every token would be invalidated because
JWT `iss` is minted from `home_url('/')` (`JwtToken.php:110,248-251`). **That
cause does not apply here:** `home_url()` is the apex both before and after, so
`iss` never changes. What actually remains:

- **The frontend origin changes** (`bcc-frontend-rho.vercel.app` → apex). The
  NextAuth cookie is host-only with no `domain=`, so sessions do not follow. Users
  mid-session on the Vercel URL will re-authenticate. Unavoidable.
- **Narrowing `BCC_FRONTEND_ORIGIN` at §3.8 invalidates outstanding tokens** whose
  `aud` is the old Vercel origin. This one **is** avoidable: keep the Vercel entry
  in the allowlist (just not at position [0]) for one JWT TTL — **7 days**
  (`JwtToken.php:57`) — and drop it after. Decode accepts any entry; only minting
  uses [0].
- **Web-push subscriptions reset** — service workers are origin-bound.

---

## 1 Pre-flight (read-only)

- [ ] **1.1 Confirm the docroots.** SSH to the Hostinger account (same box as the
      `DEPLOY_*` secrets):
      ```bash
      ls -la ~/                       # expect public_html/ and stage/
      ls ~/public_html/wp-config.php  # prod
      ls ~/stage/wp-config.php        # staging
      ```
- [ ] **1.2 ~~Decide how `cms.` maps to the prod docroot.~~ DONE** — `cms.` already
      serves the same install (`/wp-json/bcc/v1/ranks` → 200, PHP 8.3.30). Confirm
      it is genuinely the *same* WordPress and not a copy:
      ```bash
      curl -s https://cms.bluecollarcrypto.io/wp-json/bcc/v1/ranks | head -c 200
      curl -s https://bluecollarcrypto.io/wp-json/bcc/v1/ranks     | head -c 200
      ```
      Identical payloads ⇒ same install. If they differ, stop — a second copy of
      WordPress would diverge the moment either is written to.
- [ ] **1.3 Verify the auth-cache bypass block** exists in the prod docroot and sits
      **above** `# BEGIN LSCACHE`:
      ```bash
      grep -n -A6 'BEGIN BCC AUTH CACHE BYPASS' ~/public_html/.htaccess
      grep -n 'BEGIN LSCACHE' ~/public_html/.htaccess
      ```
      Losing it is a **P0 cache-isolation leak** — anon payloads served to
      `Authorization`-bearing requests
      ([testnet-deploy-checklist.md](testnet-deploy-checklist.md) §1.6). Since
      `cms.` is a second hostname on the same docroot the block is shared — but it
      is an **origin** rule, and `cms.` sits behind Hostinger CDN (see §0), so
      verify bypass end-to-end through the CDN, not just at origin.
- [ ] **1.4 Record current values** (names only):
      ```bash
      grep -n 'BCC_FRONTEND_ORIGIN\|BCC_PUSH_VAPID_SUBJECT\|BCC_INTERNAL_CRON_SECRET\|WP_HOME\|WP_SITEURL' \
        ~/public_html/wp-config.php
      wp option get siteurl --path=~/public_html
      wp option get home    --path=~/public_html
      ```
      `WP_HOME`/`WP_SITEURL` are expected to be **absent** — the hostname lives only
      in the `siteurl`/`home` options, which is why §3 is a DB operation. Note also
      whether `BCC_INTERNAL_CRON_SECRET` is defined; without it the Vercel indexer
      cron is inert (`IndexerTickEndpoint.php:149-152`).
- [ ] **1.5 Lower TTLs** on the apex `A`/`AAAA` and `www` to 300s, **≥24h before**
      cutover, so rollback propagates in minutes.

---

## 2 Staging environment

Staging keeps its hostnames: `stage.` = staging WordPress,
`app.stage.` = staging frontend. What changed is the **Vercel side**, which had
`app.stage.` wired to the *Production* environment — backwards.

Target: `app.stage.` serves Vercel **Preview**, built from the `staging` branch
and pointed at staging WordPress; the apex serves **Production**, pointed at
`cms.`.

- [x] **2.1 Split `NEXT_PUBLIC_BCC_API_URL` by environment** in Vercel:

      | Environment | Value |
      |---|---|
      | Production | `https://cms.bluecollarcrypto.io` |
      | Preview | `https://stage.bluecollarcrypto.io` |

      ⚠️ The Production entry was created with the **Sensitive** flag, which Vercel
      cannot un-set after creation. Harmless — a `NEXT_PUBLIC_*` value ships to
      browsers regardless — but the value is no longer readable in the dashboard.
      Delete and recreate the entry if that becomes annoying.
- [x] **2.2 Create the `staging` branch** — `origin/staging`, branched from `main`
      at `a8e0170`. Vercel auto-builds a Preview from it.
- [ ] **2.3 Reassign `app.stage.bluecollarcrypto.io`** from Production → the
      `staging` branch, once its Preview build is green.
      ⚠️ **Order matters:** reassigning before the branch exists leaves `app.stage.`
      with nothing to serve; redeploying Production before reassigning makes
      `app.stage.` serve **live CMS data**. Branch → green build → reassign →
      redeploy Production.
- [ ] **2.4 Verify the split**: `app.stage.` reads from `stage.`, and a Production
      deploy reads from `cms.`. Confirm in the browser devtools network tab, not
      just in the dashboard.

### Optional: rehearse the DB rewrite

§3.4 is the one irreversible step and is unrehearsed. To practise it on
disposable data, run the equivalent `search-replace` on the `stage/` tree with
`--dry-run`, read the report, then **revert** — `stage.` must stay canonical or
`scripts/auth-cache-isolation-probe.sh` (which hard-pins that host) and
`.github/workflows/staging-cache-probe.yml` start failing.

---

## 3 Production cutover

Everything before 3.4 is reversible.

- [ ] **3.1 Widen the allowlist first.** In prod `wp-config.php`:
      ```php
      define('BCC_FRONTEND_ORIGIN', 'https://bcc-frontend-rho.vercel.app,https://bluecollarcrypto.io');
      ```
      ⚠️ **Order is load-bearing and not what you'd guess.** The constant is a
      comma-separated allowlist (`CorsHandler.php:190-209`), but **entry [0] is
      special**: it mints the JWT `aud` claim (`JwtToken.php:121-127`) and is the
      literal base URL for the backend→frontend Polkadot verify callout
      (`PolkadotSignatureVerifier.php:200-214`). The live frontend is still the
      Vercel URL at this point, so **it stays first**. CORS and JWT *decode* accept
      any entry, so adding the apex now is free.

      Related: `JwtToken::audienceAllowlist()` does **not** strip a `regex:` prefix
      the way `CorsHandler` does. A `regex:` preview pattern must never sit at [0].
- [x] **3.2 `cms.bluecollarcrypto.io` exists and serves WordPress** — verified
      2026-08-19:
      ```bash
      curl -sI https://cms.bluecollarcrypto.io/wp-json/bcc/v1/ranks   # 200, PHP 8.3.30
      ```
      Note its root still 302s to `https://bluecollarcrypto.io/landing`, because
      WP's canonical `home`/`siteurl` are still the apex. That resolves at §3.4 —
      it is expected, not a fault.
      Both hostnames now serve the same install. Nothing is broken yet.
- [ ] **3.3 Back up the database** — hPanel backup **and** an explicit `wp db export`
      you can find again. 3.4 is the irreversible step.
- [ ] **3.4 Move the canonical host.**
      ```bash
      wp search-replace 'bluecollarcrypto.io' 'cms.bluecollarcrypto.io' \
        --all-tables --precise --dry-run --path=~/public_html
      ```
      ⚠️ **That pattern also matches `stage.bluecollarcrypto.io` and
      `www.bluecollarcrypto.io`.** Confirm the dry-run catches neither; if it does,
      anchor on `//bluecollarcrypto.io`. Then run for real and set:
      ```bash
      wp option update siteurl 'https://cms.bluecollarcrypto.io' --path=~/public_html
      wp option update home    'https://cms.bluecollarcrypto.io' --path=~/public_html
      ```
- [ ] **3.5 Repoint the apex** in the hPanel DNS zone editor:
      - **Delete the apex `AAAA` record.** Vercel publishes no IPv6 for apex domains;
        leaving it sends IPv6 clients to Hostinger — a half-broken site that reads
        like a caching bug.
      - **Change the apex `A`** to the value **Vercel's Project → Settings → Domains
        tab shows you**. Do not copy an IP from memory or a blog post.
      - **Repoint `www`** to Vercel's CNAME target; add `www` in Vercel set to
        redirect to apex.
      - **Leave `MX`, SPF `TXT`, both `google-site-verification` records and
        `openai-domain-verification` untouched.** The `A` change doesn't affect mail,
        but fat-fingering those rows kills `phillip@` and `privacy@bluecollarcrypto.io`
        — both published in the legal pages (`src/lib/legal/config.ts:30,33`).
- [ ] **3.6 Add both domains in Vercel** (project `bcc-frontend`); wait for the cert.
- [ ] **3.7 Set Vercel env, then redeploy:**

      | Var | Value | Note |
      |---|---|---|
      | `NEXTAUTH_URL` | `https://bluecollarcrypto.io` | Fixes NextAuth callbacks, CSRF **and** `metadataBase`/OG at once. Unset ⇒ `layout.tsx:68` falls back to the ephemeral per-deployment `VERCEL_URL` and stamps OG tags with a hash hostname. |
      | `NEXT_PUBLIC_BCC_API_URL` | `https://cms.bluecollarcrypto.io` | No trailing slash. **Build-time inlined — editing the var alone does nothing, you must redeploy.** |
      | `NEXTAUTH_SECRET` | unchanged | |
      | `CRON_SECRET`, `BCC_INTERNAL_CRON_SECRET` | confirm present | |

- [ ] **3.8 Promote the apex to [0]** once it is confirmed serving Next.js:
      ```php
      define('BCC_FRONTEND_ORIGIN', 'https://bluecollarcrypto.io');
      ```
      This is what finally points the Polkadot callout and JWT `aud` at the real
      domain. Drop the `vercel.app` entry — 3.4 already invalidated every token, so
      there is nothing in flight to preserve.
- [ ] **3.9 Set the CI health-probe variable** in **all three** plugin repos
      (`bcc-trust`, `bcc-core`, `bcc-search`) → Settings → Secrets and variables →
      Actions → Variables:
      `PROD_HEALTH_BASE = https://cms.bluecollarcrypto.io`
      Without it the probe falls back to the apex default and every production
      plugin deploy fails (see §5).
- [ ] **3.9b Deploy bcc-core** so the `rest_url` rebase (§5) is live **before** the
      apex stops being WordPress. Until it ships, WP generates apex-based REST URLs;
      after the flip those resolve to Next.js. Verify:
      ```bash
      curl -s https://cms.bluecollarcrypto.io/wp-json/ | grep -o '"[^"]*wp-json[^"]*"' | head -3
      ```
      Every URL must read `cms.`, not the bare apex. **Not an mu-plugin** — it lives
      in bcc-core so the existing rsync pipeline carries it; a hand-installed
      mu-plugin would be untracked and lost on migration, exactly like the
      `.htaccess` block in §1.3.
- [ ] **3.9c Define `BCC_INTERNAL_CRON_SECRET` in production `wp-config.php`.**
      **Confirmed missing on production, present on staging** (2026-08-19):
      ```bash
      curl -s -X POST https://cms.bluecollarcrypto.io/wp-json/bcc/v1/internal/indexer/tick
      # prod    → 500 {"code":"bcc_internal","message":"Internal cron secret not configured."}
      # staging → 401 {"code":"bcc_unauthorized"}  ← correct
      ```
      `vercel.json` runs `/api/internal/cron/indexer-tick` every minute. This was
      harmless while the Production frontend pointed at **staging** WP — the relay
      hit a correctly-configured host. **The production redeploy repointed it at
      `cms.`, which arms the fault:** the on-chain indexer tick now fails ~1440×/day
      and the production indexer never runs.

      ⚠️ **Do not simply copy staging's value.** Generate a distinct production
      secret, put it in prod `wp-config.php`, and scope Vercel's
      `BCC_INTERNAL_CRON_SECRET` to **Production** only — leaving the existing value
      on **Preview**, mirroring the `NEXT_PUBLIC_BCC_API_URL` split. A shared
      secret means a staging compromise drives production cron.
      Verify by re-running the curl above and expecting **401**, not 500.
- [ ] **3.10 Purge LiteSpeed.** The `EdgeCache` exclusion (`EdgeCache.php:60-68`)
      prevents *new* CORS poisoning but not pre-existing entries.
- [ ] **3.11 Re-provision the Helius webhook** — its callback is baked from
      `rest_url()` (`HeliusWebhookEndpoint.php:88-91`) and still points at the old
      host. Re-run from the NFT indexer status admin view.
- [ ] **3.12 Update `BCC_PUSH_VAPID_SUBJECT`** in wp-config to the new canonical
      https URL.

---

## 4 External consoles

Frontend origin moved:
- [ ] **Google Cloud Console** → `https://bluecollarcrypto.io/api/auth/callback/google`
- [ ] **X Developer Portal** → `https://bluecollarcrypto.io/api/auth/callback/twitter`

WordPress origin moved:
- [ ] **X Developer Portal** (second entry) →
      `https://cms.bluecollarcrypto.io/wp-json/bcc-trust/v1/x/callback` (`XOAuthService.php:33`)
- [ ] **GitHub OAuth app** →
      `https://cms.bluecollarcrypto.io/wp-json/bcc-trust/v1/github/callback` (`GitHubOAuthService.php:37`)
- [ ] **Google Search Console** — verification TXT survives, but the property now
      serves a different app; re-submit.

The two frontend entries have been flagged **MANUAL, still open** since 2026-06-11
([testnet-deploy-checklist.md](testnet-deploy-checklist.md) lines 52, 273).

---

## 5 Repo changes (already landed)

Written to be **safe to merge before the cutover** — none hard-points at a host
that doesn't exist yet.

| Change | File | Behaviour |
|---|---|---|
| `cms.*` added to image allowlist | `bcc-frontend/next.config.ts` | additive; apex retained for pre-cutover media rows |
| `cms.*` added to `WP_MEDIA_HOSTS` | `bcc-frontend/src/lib/media.ts` | additive; **must stay in sync with the above** or `/_next/image` 400s |
| Email logo + footer link derived, not pinned | `bcc-trust/…/Core/Services/AuthMailer.php` | `logoUrl()` → **`site_url()`** (a `/wp-content` asset, so it must follow WP to `cms.`); `siteUrl()` → `BCC_FRONTEND_ORIGIN` (the product, i.e. the apex). Was one hardcoded apex literal across 5 templates. ⚠️ `home_url()` is **wrong** for the logo under the headless split — it resolves to the apex, i.e. Next.js, and 404s. |
| Share-on-X quest matches the frontend host | `bcc-trust/…/Core/Services/Quest/QuestValidator.php` | was `home_url()`, i.e. the WP host — users share *frontend* links, so it silently stopped matching once the hosts diverged |
| **`metadataBase` origin hardened** | new `bcc-frontend/src/lib/app-origin.ts` (+ tests); `src/app/layout.tsx` imports it | Was: `NEXTAUTH_URL` → `VERCEL_URL` → `http://localhost:3000`. `VERCEL_URL` is the **ephemeral per-deployment** host, so an unset `NEXTAUTH_URL` stamped a throwaway hostname into every og:image and canonical — and the localhost tier did the same with an unreachable one, silently. Now mirrors Next's own resolver (`VERCEL_PROJECT_PRODUCTION_URL` for production, `VERCEL_BRANCH_URL \|\| VERCEL_URL` for previews) and **throws in production** rather than falling back to localhost. Non-breaking: unreachable on Vercel, which always sets `VERCEL_PROJECT_PRODUCTION_URL`. |
| **`BCC_FRONTEND_ORIGIN` parsing unified** | new `bcc-trust/…/Core/Support/FrontendOrigin.php`; rewires `CorsHandler`, `JwtToken`, `FrontendRedirect`; separate minimal fix in `bcc-core/…/PolkadotSignatureVerifier.php` | The constant had **four** parsers and only `CorsHandler` understood the `regex:` prefix. A `regex:` entry in first position leaked a raw pattern into the JWT `aud` claim, password-reset/verify links, and the internal wallet-verify callout. Now one parser per plugin (two total — the minimum, since the dependency runs trust→core). `exactOrigins()`/`canonical()` for anything needing a usable URL; only `match()` considers patterns. |
| `rest_url()` rebased onto the WP origin | `bcc-core/bcc-core.php` (§ Headless REST origin) | Filters `rest_url` to swap the `home_url()` origin for `site_url()`. **No-op when the two match**, so local dev and non-split installs are untouched — no env gate needed. Fixes the `/wp-json` index, the `<head>` discovery link, wp-admin's own REST calls (block editor would otherwise go cross-origin to Next.js), and all future `rest_url()`-derived callbacks. |
| Health-probe hosts moved to repo variables | `deploy.yml` × 3 plugin repos | `vars.PROD_HEALTH_BASE` / `vars.STAGE_HEALTH_BASE`, defaulting to today's hosts. Makes §3.9 a settings change instead of a merge timed against DNS. |

**Verified as needing no change:** `CardUrlMap` and `PushPayload` emit relative
paths; `middleware.ts` builds every redirect from `request.url`;
`safe-callback.ts` is relative-path-only; `scripts/verify-golden.sh` normalises
origins to `{{ORIGIN}}` so the golden fixtures are host-portable;
`docs/api-contract-v1.md` is deliberately host-agnostic;
`src/lib/legal/config.ts:39` `siteUrl` becomes correct as-is.

---

## 6 Verification

```bash
# DNS split is clean — no stray AAAA on the apex
nslookup -type=A    bluecollarcrypto.io
nslookup -type=AAAA bluecollarcrypto.io          # expect NO answer
nslookup cms.bluecollarcrypto.io

# Each host serves the right stack
curl -sI https://bluecollarcrypto.io/ | grep -i 'x-vercel-id\|server'
curl -sI https://cms.bluecollarcrypto.io/wp-json/bcc/v1/ranks | grep -i 'server\|litespeed'

# www redirects to apex
curl -sI https://www.bluecollarcrypto.io/ | grep -i location

# CORS preflight — expect 204, Allow-Credentials: true, echoed origin, Vary: Origin
curl -si -X OPTIONS https://cms.bluecollarcrypto.io/wp-json/bcc/v1/ranks \
  -H 'Origin: https://bluecollarcrypto.io' \
  -H 'Access-Control-Request-Method: GET' | grep -i 'access-control\|vary\|HTTP/'

# The exact three endpoints deploy.yml probes (200/400/401/403 = healthy)
for p in 'ranks' 'search/groups?q=ping' 'cards?per_page=1'; do
  curl -s -o /dev/null -w "$p -> %{http_code}\n" "https://cms.bluecollarcrypto.io/wp-json/bcc/v1/$p"
done

# Mail survived the DNS edit
nslookup -type=MX  bluecollarcrypto.io
nslookup -type=TXT bluecollarcrypto.io           # SPF + 3 verification records intact
```

In a browser on `https://bluecollarcrypto.io`:

- [ ] Log in with email/password, Google, and X — all three round-trips.
- [ ] A WP-hosted avatar renders (proves `remotePatterns` and `WP_MEDIA_HOSTS`
      agree; a mismatch shows as a 400 on `/_next/image`).
- [ ] `view-source:` a profile → `og:image` is on `bluecollarcrypto.io`, **not** a
      `vercel.app` hash host. If it is, `NEXTAUTH_URL` didn't take.
- [ ] Connect X or GitHub from the connections tab — exercises the `?return_to=`
      allowlist, which fails **silently** when `BCC_FRONTEND_ORIGIN` is wrong
      (`FrontendRedirect.php:68-89`; `defaultReturn()` falls back to `home_url()`,
      so a misconfiguration dumps users on the WP host rather than erroring).
- [ ] Trigger a password reset and **open the email** — logo renders (proves the
      `AuthMailer` change), footer link points at the apex.
- [ ] Deploy one plugin to production and confirm the health probe passes (proves
      §3.9).

Optionally drive the browser pass with the Playwright MCP against
[v1-smoke-test-checklist.md](v1-smoke-test-checklist.md).

---

## 7 Rollback

- **Before 3.4** — revert the apex `A`/`AAAA`. Minutes, given the 300s TTLs from 1.5.
- **After 3.4** — restore the 3.3 export (or reverse the `search-replace`), then
  revert DNS.
- **Frontend** rolls back independently: [rollback-procedure.md](rollback-procedure.md)
  §4a (Vercel → Promote previous deployment).

---

## 8 Open decisions this forces

1. **CL-32 — prod `cache-ttl_rest` is still `604800`** (one week). Staging was fixed
   2026-07-16; prod is frozen. Going public on that setting serves badly stale API
   responses. `wp option update litespeed.conf.cache-ttl_rest 60`.
2. **CL-7B — Hostinger CDN on or off at launch?**
   ([audit-remediation-checklist-2026-07-21.md](audit-remediation-checklist-2026-07-21.md)
   lines 495-499). Its per-IP burst limits previously banned the owner's home IP.
3. **Should the production Vercel deployment keep pointing at staging WP?** It
   currently does ([capacity-model.md](capacity-model.md) lines 670-672), and prod
   WP runs bcc-trust 1.1.0 vs staging's 1.2.26. Once the apex is public this must be
   a deliberate choice, not an inherited one.

## Out of scope

`sitemap.ts`, `robots.ts`, and a CSP via `next.config.ts` `headers()` — none exist
today. A real public domain is the natural moment to add all three, but they are
separate work and should not ride along with a cutover.
