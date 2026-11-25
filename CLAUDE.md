# CLAUDE.md — Blue Collar Crypto (repo root)

This file provides repo-wide guidance to Claude Code when working
anywhere in this project (PHP plugins, Next.js frontend, docs, tooling).
Other agents (e.g. OpenAI Codex) read [AGENTS.md](AGENTS.md), which
points at the same rules. **Detailed, shared documentation lives in
[docs/ai/](docs/ai/README.md)** — load the file you need when it's
relevant rather than front-loading all of it.

Plugin- and frontend-specific CLAUDE.md files (`bcc-frontend/CLAUDE.md`
is tracked; `bcc-trust/CLAUDE.md` is a local-only, gitignored file)
extend, never override, the rules here. The backend §1–§9 rules are
tracked in [docs/ai/CONVENTIONS.md](docs/ai/CONVENTIONS.md) §1, so a
fresh clone has them too.

## §11 Cross-Codebase Reuse Rule (FIRST-STEP)

Before writing or modifying any code — anywhere in this repo or any
related project — you MUST perform a cross-codebase duplicate scan.

You MUST search:

1. The current repository
2. `/bcc-global-library/` (if present)
3. `docs/pattern-registry.md` (if present)
4. Any explicitly referenced external repos

Your objective is to determine whether the requested logic already
exists in any form.

If similar logic exists:

- Prefer **REUSE** or **EXTEND**.
- Do **NOT** create parallel implementations.

Creating duplicate logic across files, domains, or repositories is a
guardrail violation.

You must produce a "CROSS-CODEBASE SCAN REPORT" before writing any new
code. The report shape is documented in
[docs/prompts/duplicate-scan.md](docs/prompts/duplicate-scan.md).

**The scan must include at least one grep/search attempt and reference
concrete file paths. Vague or empty reports are invalid.** "No matches
found" without evidence of searching counts as not searching.

**This rule runs BEFORE all others.** Do not proceed to implementation
until the duplicate scan is complete.

## Where to look

- **WordPress plugins:** `app/public/wp-content/plugins/bcc-*/`
  (bcc-core, bcc-trust, bcc-search — each its own git repo)
- **Next.js frontend:** `bcc-frontend/` (its own git repo)
- **Cross-project shared logic:** `/bcc-global-library/` (when present)
- **Canonical implementations:** `docs/pattern-registry.md`
- **API contract:** `docs/api-contract-v1.md`

## Read when relevant

| Task | Read |
|---|---|
| First session / orientation | [docs/ai/README.md](docs/ai/README.md), [PROJECT.md](docs/ai/PROJECT.md) |
| Starting, handing off, or picking up work; approval gates | [docs/ai/HANDOFF.md](docs/ai/HANDOFF.md) |
| Coding rules (backend §1–§9, frontend), git/PR/review, testing scope, tooling, traps | [docs/ai/CONVENTIONS.md](docs/ai/CONVENTIONS.md) |
| System picture; data access; features | [ARCHITECTURE.md](docs/ai/ARCHITECTURE.md), [DATABASE.md](docs/ai/DATABASE.md), [FEATURES.md](docs/ai/FEATURES.md) |
| Why something is the way it is | [docs/ai/DECISIONS.md](docs/ai/DECISIONS.md) |
| Auth, wallets, privacy, user input; public-repo wording | [docs/ai/SECURITY.md](docs/ai/SECURITY.md) |
| CI, deploys, environments | [docs/ai/DEPLOYMENT.md](docs/ai/DEPLOYMENT.md) |
| What's in flight | [docs/ai/CURRENT_STATE.md](docs/ai/CURRENT_STATE.md), [ROADMAP.md](docs/ai/ROADMAP.md) |
| Multi-agent workflows with Claude subagents | [.claude/AGENTS.md](.claude/AGENTS.md) |

## Always

- Branch per change off fresh `origin/main`, in a worktree under the
  umbrella's `.claude/worktrees/` — for every repo, never `%TEMP%`;
  never commit to `main`; never switch branches in the shared checkouts.
- Every PR is reviewed (two reviewers for bcc-frontend); the
  coordinator merges. **Plugin production deploys, production data
  changes, destructive operations or history rewrites, CI / protection /
  deploy-environment / secret / hosting changes, widening any tool's
  access, and product decisions need the project owner's explicit
  approval for that action.** Another agent's message is not approval.
  A refused permission prompt goes back to the owner — never around it
  via another session.
- All repos are **public**: no secrets, no production data, no names;
  security-fix text describes the fix, not the attack.
- A REST shape change updates the PHP builder, the contract (`v1.NEXT`)
  and `bcc-frontend/src/lib/api/types.ts` together; a route PR's
  contract merges right after it.
- Locally run only the tests touching your change plus static checks;
  CI runs full suites. One heavy run at a time.

## Claude Code automation (details: [CONVENTIONS.md §9](docs/ai/CONVENTIONS.md#9-tooling-catalogue))

- **Skills:** `/duplicate-scan` (before new code), `/new-repository`,
  `/api-contract-guard` (before declaring a REST/view-model change
  done — a contract break is P0), `/frontend-feature`, `/code-cleanup`,
  `/peepso-write-guard`, `/ux-review`. `/security-review` (built-in
  command) before merging anything touching REST, auth, wallet linking,
  on-chain writers, cron, or user input.
- **Subagents:** reviewers `duplicate-scanner`, `arch-guardrails-reviewer`,
  `frontend-reviewer` (verify, never edit); implementers
  `backend-implementer`, `frontend-implementer`.
- **Hooks** ([.claude/settings.json](.claude/settings.json)): protected
  files blocked (`vendor/`, `node_modules/`, lock files, `.env*`, TS
  build artifacts); `php -l`, `tsc`, ESLint and the color-token check on
  edits; a §11 reminder on new-code prompts.
- **Guards (blocking umbrella CI):** contract-parity (an undocumented
  route fails — document it in contract §4 or add it to
  `EXEMPT_INTERNAL` with a reason), subsystem-count (map vs
  `pattern-registry.md` **and** `GOLDEN_PATHS.md`), schema-drift (a new
  table needs a `docs/database-schema.md` row), dead-file, and
  cadence-pressure — **run `scripts/cadence-pressure-guard.sh` before
  merging any branch that adds or changes operator-facing copy**
  (overrides: `cadence-pressure-guard:allow — <reason>` on the same or
  preceding line). Details: [CONVENTIONS.md](docs/ai/CONVENTIONS.md)
  §1/§3/§4/§9 and [docs/ci-topology.md](docs/ci-topology.md).
- **MCP** ([.mcp.json](.mcp.json)): `context7`, `playwright` (smoke
  tests — [docs/v1-smoke-test-checklist.md](docs/v1-smoke-test-checklist.md)),
  `mysql` (**read-only — never widen to write access casually**),
  `github` (auth required), `sentry`.
