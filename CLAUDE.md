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
