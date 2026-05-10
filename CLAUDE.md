# CLAUDE.md — Blue Collar Crypto (repo root)

This file provides repo-wide guidance to Claude Code when working
anywhere in this project (PHP plugins, Next.js frontend, docs, tooling).

Plugin- and frontend-specific conventions live in their own CLAUDE.md
files alongside the code (e.g., `app/public/wp-content/plugins/bcc-trust/CLAUDE.md`).
Those files extend, never override, the rules here.

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

## Where to look for code

- **WordPress plugins:** `app/public/wp-content/plugins/bcc-*/`
- **Next.js frontend:** `bcc-frontend/`
- **Cross-project shared logic:** `/bcc-global-library/` (when present)
- **Canonical implementations:** `docs/pattern-registry.md`
- **API contract:** `docs/api-contract-v1.md`

## Plugin-specific guidance

- [bcc-trust/CLAUDE.md](app/public/wp-content/plugins/bcc-trust/CLAUDE.md) —
  the merged trust + disputes + onchain plugin (Domain/Core, Domain/Disputes,
  Domain/Onchain). Has §1–§9 architecture conventions plus §11.

## Available automation

Skills, subagents, and hooks live under `.claude/`. Use them — they
encode the rules above so you don't have to re-derive them every time.

For multi-agent and parallel-dispatch workflows (BE+FE in parallel,
background audits, worktree-based parallelism), see
[.claude/AGENTS.md](.claude/AGENTS.md).

### Skills (invoke with `/<name>`)

- `/duplicate-scan` — runs the §11 mandatory cross-codebase scan.
  Use **before** any new code. Wraps the `duplicate-scanner` subagent.
- `/new-repository` — scaffolds a Repository class in bcc-trust that
  complies with §1–§5 (no `SELECT *`, bounded queries, generation-counter
  cache invalidation).
- `/api-contract-guard` — verifies REST endpoint or view-model changes
  still conform to [docs/api-contract-v1.md](docs/api-contract-v1.md).
  Run **before** declaring a §9 change "done." A contract break is P0.
- `/frontend-feature` — scaffolds a Next.js feature in `bcc-frontend/`
  the way this codebase actually does it (typed API client, React Query
  hook shape, no business logic, reduced-motion respect).
- `/security-review` — runs a security review of pending changes on the
  current branch (auth, authz, input validation, secrets, injection,
  unsafe deserialization, SSRF). Run **before merging** any branch that
  touches REST endpoints, auth, wallet linking, on-chain signal writers,
  cron, or anything handling user input. Treat findings the same as
  arch-guardrails violations — fix or get explicit sign-off before merge.

### Subagents (invoke via the Agent tool)

**Reviewers** (verify, never edit):

- `duplicate-scanner` — the §11 search engine. Owned by `/duplicate-scan`.
- `arch-guardrails-reviewer` — reviews PHP changes against §1–§9.
- `frontend-reviewer` — reviews `bcc-frontend/` against the "no business
  logic / no raw fetch / no `as any`" rules.
- `holder-groups-reviewer` — feature-scoped to NFT→PeepSo group-gating;
  retire when that feature ships.

**Implementers** (build, run their own checks):

- `backend-implementer` — PHP under `app/public/wp-content/plugins/bcc-*/`.
  Pair with `arch-guardrails-reviewer`.
- `frontend-implementer` — TypeScript under `bcc-frontend/`. Pair with
  `frontend-reviewer`.

### Hooks (configured in [.claude/settings.json](.claude/settings.json))

- **PreToolUse** — `block-protected-files.sh` refuses edits to
  `vendor/`, `node_modules/`, lock files, `.env*`, and TypeScript build
  artifacts. Bypass means editing those files outside Claude.
- **PostToolUse** — `php-lint.sh` runs `php -l` on PHP edits;
  `ts-check.sh` runs `tsc --noEmit` on `bcc-frontend/` TypeScript edits.
- **UserPromptSubmit** — `section-11-reminder.sh` injects a §11 reminder
  when the prompt looks like new-code work.

### MCP servers ([.mcp.json](.mcp.json))

- `context7` — live docs for libraries the codebase imports.
- `playwright` — browser automation for Next.js smoke tests
  (see [docs/v1-smoke-test-checklist.md](docs/v1-smoke-test-checklist.md)).
- `mysql` — read-only queries against the Local-by-Flywheel database.
  Useful for read-model debugging, schema inspection, fingerprint
  resolver checks. **Never widen to write access casually.**
- `github` — issue/PR ops via the Copilot endpoint (auth required).
