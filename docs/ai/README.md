# docs/ai: shared knowledge base for AI agents

This folder is where any AI agent (Claude Code, OpenAI Codex, or another) or a new human engineer starts on Blue Collar Crypto (BCC). It is a map with context. It links to the canonical docs rather than copying them, and records the reasons behind decisions that are otherwise only in people's heads, in private agent memory or in PR threads.

**Snapshot:** 2026-11-25. Audited against `main` of all five repos:

| Repo | main tip |
|---|---|
| umbrella | `e826bf83` |
| bcc-core | `9e1e19b8` |
| bcc-search | `ccab45cf` |
| bcc-trust | `f789f5be` |
| bcc-frontend | `3ef3054d` |

The API contract is at v1.263.

## Which source wins

- **What the API must do:** [`docs/api-contract-v1.md`](../api-contract-v1.md). Per its own header, when the implementation diverges, *the contract wins until a versioned contract update lands*.
- **What the system actually does:** the code on `main`. A difference between code and contract is a bug in one of them. Flag it; never pick one silently.
- **Everything else** lives in the canonical topic docs linked from each file here: `database-schema.md`, `pattern-registry.md`, `GOLDEN_PATHS.md`, `bcc-frontend/docs/frontend-doctrine.md` and so on.
- **This folder's summaries** lose to all of the above. If one disagrees, it is wrong: fix it in the same PR as your change.
- **Two exceptions:**
  - [CONVENTIONS.md](CONVENTIONS.md) §1 is the only tracked copy of the backend §1–§9 rules, so it is canonical for them.
  - [DECISIONS.md](DECISIONS.md) is the canonical decision log.

## Status labels used in this folder

| Label | Meaning |
|---|---|
| **[VERIFIED]** | Seen in code on `main` at the snapshot. |
| **[DOC]** | Stated by a repo doc; the code was not re-checked for this page. |
| **[PLANNED]** | Designed or decided, but not built (or only partly built). |
| **[HISTORICAL]** | Superseded. Kept so nobody re-introduces it. |
| **[DISCREPANCY]** | Two sources disagree. Both are cited, and nobody has resolved it yet. |
| **[UNKNOWN]** | Could not be determined. Ask the project owner. Don't guess. |

## Files

| File | Read it when you need |
|---|---|
| [PROJECT.md](PROJECT.md) | what BCC is, who it is for, and the product principles that override "best practice" |
| [ARCHITECTURE.md](ARCHITECTURE.md) | the system-wide picture: repos, request flow, auth, caching, cron, observability, frontend |
| [DATABASE.md](DATABASE.md) | the data-access rules, the fail-closed read doctrine, transactions, and schema changes |
| [FEATURES.md](FEATURES.md) | each product feature: what it does, where it lives, its rules and status |
| [DECISIONS.md](DECISIONS.md) | why things are the way they are, and which past decisions you must not reverse |
| [CONVENTIONS.md](CONVENTIONS.md) | coding rules (backend §1–§9 and §11, frontend), git/PR/review rules, testing, tooling, known traps |
| [SECURITY.md](SECURITY.md) | the security and privacy model, the public-repo disclosure rules, and secrets handling |
| [DEPLOYMENT.md](DEPLOYMENT.md) | environments, CI, how plugins and the frontend reach production, rollback |
| [ROADMAP.md](ROADMAP.md) | planned and in-progress work |
| [CURRENT_STATE.md](CURRENT_STATE.md) | the dated snapshot: open workstreams, held PRs, known doc drift |
| [HANDOFF.md](HANDOFF.md) | how Claude and Codex (or any two agents) pass work to each other, and the approval gates |

## Reading order

- **First session on this codebase:** [../../AGENTS.md](../../AGENTS.md), then [PROJECT.md](PROJECT.md), [HANDOFF.md](HANDOFF.md), [CONVENTIONS.md](CONVENTIONS.md) and [CURRENT_STATE.md](CURRENT_STATE.md).
- **Backend change:** [ARCHITECTURE.md](ARCHITECTURE.md) "Backend", [DATABASE.md](DATABASE.md), and the relevant section of [FEATURES.md](FEATURES.md). Then the canonical docs those link to, especially `pattern-registry.md` (the §11 reuse index) and the contract section for your route.
- **Frontend change:** [ARCHITECTURE.md](ARCHITECTURE.md) "Frontend", [CONVENTIONS.md](CONVENTIONS.md) "Frontend", then `bcc-frontend/CLAUDE.md` and `bcc-frontend/docs/frontend-doctrine.md`.
- **Anything touching auth, wallets, privacy or user input:** [SECURITY.md](SECURITY.md) first.
- **Anything that changes production:** [DEPLOYMENT.md](DEPLOYMENT.md) and the approval gates in [HANDOFF.md](HANDOFF.md).

## Keeping it true

- **Update in the same PR.** If your change makes a statement here wrong, fix it in that PR. A stale knowledge base is worse than none.
- **Keep it a map, not a copy.** Link the canonical doc. Summarise only what an agent needs in order to know where to look and what not to break.
- **Re-date the snapshot files.** `CURRENT_STATE.md` and `ROADMAP.md` carry a snapshot date: change it when you update them. The other files are evergreen.
- **These repos are public.** Never write here:
  - secrets or their values;
  - production counts, ids or user data;
  - server hosts beyond the public domains, or filesystem paths;
  - people's names;
  - details of any security weakness not yet fixed in production.

  See [SECURITY.md](SECURITY.md) "Disclosure rules".
- **Verify what you write.** Mark each claim with a status label. Cite paths as `repo:path` (for example `bcc-trust:app/Domain/Core/REST/Envelope.php`) and PRs as `repo#N`.
