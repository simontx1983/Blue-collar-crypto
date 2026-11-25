# Roadmap

**Snapshot: 2026-11-25.**

This page replaces [`docs/v2-roadmap.md`](../v2-roadmap.md) (status "as of 2026-07-19") and [`docs/TODO.md`](../TODO.md) (last updated 2026-08-06) as the current view. Both are stale, but they keep their historical detail and are linked below.

The next plugin release and its gates are tracked privately by the maintainers. Security and hardening work is tracked privately too, so it does not appear on this page.

**Labels used on this page:**
- **[IN PROGRESS: PR]**: open work.
- **[PLANNED]**: decided, not started.
- **[IDEA]**: not decided; needs the project owner.

Nothing here is a commitment until the project owner prioritises it.

## Near term

| Item | Status | Notes |
|---|---|---|
| Topic Halls 2b: Hall cards | [IN PROGRESS: trust#908, search#55] | |
| Topic Halls 3b: archive | [PLANNED] | An archived Hall hides its posts |
| `/admin/halls` screen | [PLANNED] | Merges once its backend is released |
| Card cover photo | [IN PROGRESS: trust#897, umbrella#415] | The frontend part shipped in FE#411 |
| Validator admin: verification badge | [IN PROGRESS: FE#424] | Merges once its backend is released |
| Validator admin: team screens, plus backend team routes and owner-end flows | [IN PROGRESS: FE#395] / [PLANNED] | |
| Validator self-send (memo) claim, end to end | [PLANNED] | Building blocks merged (core#183, #184; trust#874). Rulings in [DECISIONS.md](DECISIONS.md) W3–W5. The design doc lags the rulings, so confirm with the owner first |
| Explained 72-hour locks for new owners | [PLANNED] | Tied to the self-send claim |
| Shared detail layout for validator, project and creator pages | [IN PROGRESS: FE#435] | |
| Reposts frontend | [IN PROGRESS: FE#326] | Merges once its backend is released |
| My Groups home tab | [IN PROGRESS: FE#330] | |
| Profile redesign PR4 (Edit About, "Show on profile") | [IN PROGRESS: FE#295] | Merges once its backend is released |
| Frontend "findings unavailable" state | [PLANNED] | Contract v1.262 |
| Mobile wallet connect | [IN PROGRESS: FE#401, stage 0] / later stages [IDEA] | |
| Solana validator vote-account use | [PLANNED] | Its prerequisite (trust#832) shipped |
| Subsystem-count guard, map-to-docs direction | [IN PROGRESS: umbrella#428] | |
| Dependency updates | [IN PROGRESS: FE#303–#306, #376] | |

## Product ideas (spec first, owner decides)

- **Ambassador programme** [IDEA]: mentors evolve into ambassadors. Members apply with their socials, staff approve, and ambassadors get a badge, a featured slot, early access and a capped trust credit. **Never money or tokens.**
- **Manual validator ↔ member linking with team roles** [IDEA]. Staff link, revoke and cancel for a single owner has shipped. Team roles are still undecided.
- **All daily moderation in `/admin/*`** [IN PROGRESS, partly]. The Admin navigation group, validator admin and the Halls admin API have shipped.
- **Sponsorship records in wp-admin** [PLANNED, post-launch].
- **The "Signals" tab returning** [IDEA].

## Deferred to v2

Open rows from `docs/v2-roadmap.md`. They are gated on demand or staffing.

- **Engagement:**
  - sound on "heavy" celebrations;
  - a streak-freeze mechanic (check it against the cadence-pressure policy first);
  - a network percentile on other members' profiles;
  - per-category highlight muting.
- **Curation:** an admin-curated featured row.
- **Chains:** Injective NFT support; Polkadot, NEAR and THORChain NFT support.
- **Platform:** a public API, a native mobile app, i18n, and the "Backer" concept.

## Debt and hygiene

From `TODO.md` and `open-findings-handoff.md`, themes only:
- leftover dead code and orphan development tables, plus a decision on collapsing the `Infrastructure` directory;
- a one-shot backfill for the NFT indexer;
- observability gaps in post-vote async work;
- log noise: a spurious "missing table" line, and a migration warning that claims work it did not do;
- the doc drift listed in [CURRENT_STATE.md](CURRENT_STATE.md);
- a public vulnerability-reporting policy [IDEA] (see [SECURITY.md](SECURITY.md)).
