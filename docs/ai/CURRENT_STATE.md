# Current state

**Snapshot: 2026-11-25.** Update this page whenever a workstream starts, finishes or changes hands, and change the date when you do.

## Where `main` is

| Repo | main tip | Notes |
|---|---|---|
| umbrella | `e826bf83` | contract **v1.263**. The next contract number is v1.264, assigned at merge |
| bcc-core | `9e1e19b8` | |
| bcc-search | `ccab45cf` | |
| bcc-trust | `f789f5be` | |
| bcc-frontend | `3ef3054d` | Vercel production tracks `main` |

**What is deployed to production for the plugins, and what the next release contains, is deliberately not recorded in this public repo.** Security and hardening work is not listed here either. The maintainers track both privately; ask the project owner or the ops role. The general release procedure is in [DEPLOYMENT.md](DEPLOYMENT.md).

## Active workstreams

Open PRs at the snapshot. Check `gh pr list -R simontx1983/<repo>` for the live list.

| Workstream | Open PRs | State |
|---|---|---|
| Topic Halls 2b (Hall cards, TOPIC HALL kicker, Validators stat) | trust#908 (draft), search#55 (draft) | in progress |
| Topic Halls 3b (archive) and the `/admin/halls` screen | — | planned |
| Card cover photo on the Card view-model | trust#897, umbrella#415 | in review |
| Validator admin: public verification badge | FE#424 | approved. Merges once its backend is released |
| Validator admin: team, permissions and invites screens | FE#395 | waits for the backend team routes |
| Shared detail layout for validator, project and creator pages ("D2") | FE#435 (draft) | in progress. Includes the chain-label follow-up from FE#430 |
| Reposts frontend | FE#326 (draft) | merges once its backend is released |
| "Show on profile" switches and Edit About (profile redesign PR4) | FE#295 | merges once its backend is released |
| My Groups home tab | FE#330 (draft) | its title says "until backend", but the backend it waited for has shipped. Needs a refresh |
| Mobile "open in wallet app", stage 0 | FE#401 | waits for a human to smoke-test on a phone |
| Wallet sign-up keeps a typed email | FE#354 (draft) | the hold reason in its title is obsolete |
| Frontend "findings unavailable" state (contract v1.262) | — | planned |
| Subsystem-count guard: map-to-docs direction | umbrella#428 | in review |
| Dependency updates | FE#303–#306, FE#376 | FE#376 and FE#308 both bump `next`, so one of them is a duplicate |

## Recently shipped (2026-11-04 → 11-25, merged to `main`)

Feature work only; hardening work is tracked privately.

| Area | Highlights |
|---|---|
| Halls and communities | **Site Structure A**, which merged Halls into `/communities`: FE#407…#430, trust#875/#876, core#185. **Topic Halls**, read side and admin API: trust#898, trust#904; contract v1.260–v1.261. **Closed groups**: join requests and invitations |
| Validators and wallets | Validator admin V1 (FE#383, #392); staff link and revoke with step-up (trust#856, #881); owner badge and permissions on page cards (trust#894); "claimable" answered by the server (trust#821); memo-claim building blocks (core#183, #184; trust#874) |
| SEO | Public sitemap (trust#838, FE#402); the profile robots tag reads `indexable` (FE#434) |
| Admin and moderation | Admin menus regrouped; announcement takedowns; auto-hide notice; undo last override; admin digest; an Admin navigation group driven by `can_moderate` |
| Account | Login captcha step-up; breached-password refusal; server-side logout; handle changes; resend verification |
| Infrastructure | Degradation subsystems registered; classmap guards; CI action pinning; the schema-drift guard extended; a PeepSo upkeep cron; one shared service-error status map (trust#900) |

## Known doc drift

Fix these opportunistically in small, separate PRs. The PR that added this page fixed only the items in the last list.

**API contract** (`docs/api-contract-v1.md`):
- The header cites a planning document that is not in any repo as the source for decisions referenced as `§Xn`. Other docs rely on that reference (`§A2`, `§J.5`, `§L5`). → Point it at [DECISIONS.md](DECISIONS.md) or the relevant policy doc.
- §1 says "1h access, 30d refresh"; the code issues a 7-day JWT.
- The response header is called `X-BCC-Request-Id` in the contract but `X-Request-Id` in the code. `_meta` contents also differ; see [ARCHITECTURE.md](ARCHITECTURE.md) §5.
- §4.20 (attestations) and §4.32 (announcements) still carry "planned / not shipped" banners, but the code exists.
- One Halls passage still says Halls are "auto-provisioned one per chain". That is retired.
- `/me/privacy` is described as "9 boolean keys"; the code has 8.
- The Status line's date (2026-11-12) lags the version (v1.263, dated 2026-11-25).

**Glossary** (`docs/glossary.md`):
- The Rank row cites the retired `RankService::LEVEL_TO_RANK` and a test path that no longer exists.
- Disputes are described as "panel of 5".
- A claim is described as "a wallet signature"; since v1.258 the claim request takes no signature.

**Other umbrella docs:**
- `domain-seams.md`: stale line counts, and a link to the deleted `DisputeParticipationService.php`.
- `cron-registry.md`: stale line references.
- `database-schema.md`:
  - its header says the drift guard is "being added", but it has been armed since 2026-07-23;
  - its scanner-tables note conflicts with `bcc-trust:docs/scanner-schema-drop-runbook.md`.
- `proposal-cohort-overlap-dampener.md`, `proposal-fan-in-coordination.md`, `proposal-reciprocity-attestation-dampener.md`: they say "unmerged", but they merged on 2026-10-13 at multiplier 1.00.
- `v2-roadmap.md` (as of 2026-07-19) and `TODO.md` (2026-08-06) are stale; see [ROADMAP.md](ROADMAP.md).
- `rollback-procedure.md` and `testnet-deploy-checklist.md`: "production frozen pre-launch" is historical.
- `ci-topology.md`:
  - it says bcc-search is unprotected (it is protected) and that trust runs `composer update` (it runs `install`);
  - it omits two trust jobs;
  - it calls the notify shims identical, but the frontend's shim warns instead of failing.
- `environment.md`:
  - it says CI runs trust and search on PHP 8.2 (CI pins 8.3);
  - its staging REST cache claim conflicts with `testnet-deploy-checklist.md` and with the staging cache probe.
- `dev-setup.md`: it says bcc-search has no test CI (it does), and its local PHP version conflicts with `environment.md`.
- `deploy-runbook.md`: omits the deploy variable that bcc-search's production deploy requires.
- `domain-cutover-runbook.md`: names a frontend `staging` branch that no longer exists.
- Release order: no public doc stated core → search → trust as a rule before [DEPLOYMENT.md](DEPLOYMENT.md), and `environment.md` gives a different *activation* order.
- `validator-baseline-recovery.md` exists twice: a short version in the umbrella, and a long one in bcc-trust's `docs/` that the code cites. Neither links the other.
- `.claude/AGENTS.md`: its worked examples use paths that don't exist.

**Code comments:**
- `bcc-core:src/DB/DB.php`: the docblock still mentions the removed table-name filter.
- `bcc-trust:bcc-trust.php`: the schema-version docblock says `md5_file()`, but the hash is now normalised.
- `bcc-search:bcc-search.php`: describes `/search/users` and `/search/groups` as "raw / no envelope". [UNKNOWN] Whether the Envelope wraps them.
- `bcc-frontend:next.config.ts`: a Sentry comment says the app "runs on Local-by-Flywheel".
- `bcc-frontend:.github/workflows/ci.yml`: cites `knip.json`, but the file is `knip.jsonc`.

**bcc-frontend docs:**
- `README.md` lists only "Credentials + Wallet" auth providers; there are also Google and X.
- It places OG images under `app/api/`, but they are colocated `opengraph-image.tsx` files.

**Repo hygiene:**
- `bcc-core` has no README and no tracked agent instructions.
- Several open PR titles carry obsolete hold labels (FE#354, FE#330), and some merged PR titles still say "[HELD]".
- Some frontend surfaces shipped before their backend field: the card cover photo (FE#411) and the Hall Validators stat (FE#423/#425). [UNKNOWN] Confirm they degrade gracefully while the field is absent.

**Fixed by the PR that added `docs/ai/`:**
- Root `CLAUDE.md`:
  - it omitted the `code-cleanup`, `peepso-write-guard` and `ux-review` skills and the `sentry` MCP server;
  - it presented `/security-review` as a project skill (it is a built-in command);
  - it pointed at the untracked `bcc-trust/CLAUDE.md` as the only home of §1–§9.
- `README.md`: its "tracks only" list.
- `docs/dev-setup.md` §6: the "intentionally no architecture map" note.
- `.gitignore`: its comment said "four bcc-* plugins".
