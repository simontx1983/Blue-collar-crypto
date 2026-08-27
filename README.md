# Blue Collar Crypto

A blue-collar-worker crypto **trust & reputation** platform: WordPress (PHP plugins) for the
trust engine + REST API, a headless **Next.js** frontend, and on-chain signal indexing.

> **Heads-up for new contributors:** this looks like one repo but is **five separate Git
> repos** checked out into a WordPress + Next.js layout. The umbrella repo tracks only
> `docs/`, `.claude/`, `scripts/`, `.github/`, and `bcc-global-library/`; the three WordPress
> plugins (`bcc-core`, `bcc-trust`, `bcc-search`) and `bcc-frontend` are separate, gitignored
> sibling repos — reassembled only in CI. Start with the setup guide before cloning anything.

## Start here

➡️ **[docs/dev-setup.md](docs/dev-setup.md)** — repository model, clone → install → run,
minimum config, first-request verification, and the full doc map.

## Hosts (three, and they are not interchangeable)

| Host | Serves | Runs on |
|---|---|---|
| `bluecollarcrypto.io` (apex) | Public site | **Vercel** — not Hostinger |
| `cms.bluecollarcrypto.io` | WordPress core, `wp-admin`, REST | Hostinger |
| `stage.bluecollarcrypto.io` | Staging WordPress | Hostinger |

Production is **deliberately asymmetric** — `WP_SITEURL` is `cms.` while
`WP_HOME` is the apex, because the public site is the Vercel frontend, not
WordPress. It looks like an inconsistency worth tidying; it is not.

⚠️ Because `wp-config.php` defines those constants, WordPress **ignores**
`wp_options.siteurl` / `.home` — so a restored database can carry another
environment's URLs indefinitely while every page serves correctly.
`scripts/site-url-guard.php` (read-only) is how you see past that.

➡️ **[docs/hosting.md](docs/hosting.md)** — full topology, the wp-config blocks
per host, and the drift story.

## Quick links

- [docs/hosting.md](docs/hosting.md) — three-host topology & site-URL drift
- [docs/environment.md](docs/environment.md) — `wp-config.php` constants & env vars
- [docs/ci-topology.md](docs/ci-topology.md) — what CI runs and where
- [docs/GOLDEN_PATHS.md](docs/GOLDEN_PATHS.md) — operational verification runbook
- [docs/domain-seams.md](docs/domain-seams.md) — architecture / domain ownership
- [docs/glossary.md](docs/glossary.md) — domain language
- [docs/api-contract-v1.md](docs/api-contract-v1.md) — REST API contract
- [CLAUDE.md](CLAUDE.md) — repo-wide engineering guardrails (§1–§11)

## Layout (on disk)

```
.                              # umbrella repo (docs/, .claude/, scripts/)
├─ app/public/wp-content/plugins/
│  ├─ bcc-core/                # infra plugin (DI, logging, crypto) — activate first
│  ├─ bcc-trust/               # trust engine (Core/Disputes/Onchain) + REST API
│  └─ bcc-search/              # search plugin
├─ bcc-frontend/              # Next.js 15 / React 19 frontend (the only renderer)
└─ docs/                       # this documentation
```
