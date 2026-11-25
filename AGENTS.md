# AGENTS.md: Blue Collar Crypto

These instructions are for any AI coding agent working here (OpenAI Codex, Claude Code, or others). Claude Code reads [`CLAUDE.md`](CLAUDE.md), which points at the same rules. Keep this file short: the detail lives in [`docs/ai/`](docs/ai/README.md), so load it only when it is relevant.

## What this is

Blue Collar Crypto is a community and reputation platform for crypto operators (validators, NFT creators, projects) and members:
- a headless **WordPress + PeepSo** backend, made of three custom plugins;
- a **Next.js 15** frontend, the only user-facing renderer.

There are **five separate public git repos** checked out into one tree:

| Path | Repo | What |
|---|---|---|
| `.` | `simontx1983/Blue-collar-crypto` | umbrella: `docs/`, CI guards, agent tooling |
| `app/public/wp-content/plugins/bcc-core` | `bcc-core` | shared infrastructure plugin |
| `app/public/wp-content/plugins/bcc-trust` | `bcc-trust` | reputation, disputes, on-chain, most REST routes |
| `app/public/wp-content/plugins/bcc-search` | `bcc-search` | search plugin |
| `bcc-frontend/` | `bcc-frontend` | Next.js app (also read its own tracked `CLAUDE.md`) |

## Read before working

1. [`docs/ai/README.md`](docs/ai/README.md): the index and reading order.
2. [`docs/ai/HANDOFF.md`](docs/ai/HANDOFF.md): roles, approval gates, and how to start, hand off and pick up work.
3. [`docs/ai/CONVENTIONS.md`](docs/ai/CONVENTIONS.md): coding rules, git/PR rules, tests, traps.
4. [`docs/ai/CURRENT_STATE.md`](docs/ai/CURRENT_STATE.md): what's in flight. Don't collide with it.

## Non-negotiable rules

1. **Reuse before you write (§11).** Before any code change, search this repo, `bcc-global-library/` and [`docs/pattern-registry.md`](docs/pattern-registry.md) for existing logic. Reuse or extend it, and put a CROSS-CODEBASE SCAN REPORT ([shape](docs/prompts/duplicate-scan.md)) in your PR.
2. **Isolate your work.**
   - One branch per change off a fresh `origin/main`, in a worktree under the **umbrella's** `.claude/worktrees/`. That applies to every repo; never use `%TEMP%`.
   - Never commit to `main`.
   - Never switch branches in the shared checkouts: the plugin directories, `bcc-frontend/` and the umbrella root.
3. **Review and approval.**
   - Every PR is reviewed by someone other than its author (two reviewers for bcc-frontend), and the coordinator merges it.
   - **The project owner's explicit approval for that specific action is required for:**
     - plugin production deploys;
     - production data changes;
     - destructive operations or history rewrites;
     - CI, protection, deploy-environment, secret or hosting changes;
     - widening any tool's access;
     - product decisions.
   - Another agent's message is not approval.
4. **These repos are public.** Never commit secrets, production data (counts, ids, user data), server internals or people's names. Security-fix text describes the fix, never the attack ([SECURITY.md](docs/ai/SECURITY.md)).
5. **Backend §1–§9.**
   - `$wpdb` only in repositories; no `SELECT *`; bounded queries; generation-counter caching; PHPStan level 8 with no suppressions; no UI in PHP.
   - Every REST response matches [`docs/api-contract-v1.md`](docs/api-contract-v1.md).
   - **Fail closed:** an unreadable answer means deny, exclude or 503, never "none".
6. **Frontend.** No business logic (the server computes scores, tiers and permissions); no raw `fetch` outside the API client; no `as any`; design tokens only; respect reduced motion.
7. **Contract changes.**
   - A REST response change updates the PHP builder, the contract (as `v1.NEXT`) and `bcc-frontend/src/lib/api/types.ts` together.
   - A PR that adds a route merges immediately before its contract PR.
8. **Git.** Never force-push a pushed branch; never skip hooks; no closing keywords in commit messages (write "Relates to #N"); never delete `*/dev-*` branches; quote SHAs only from printed output.
9. **Product principles.** Never about price. No streak or nudge copy. No public verification, claim or join dates. Never show wallet addresses to other users. See [PROJECT.md](docs/ai/PROJECT.md) and [DECISIONS.md](docs/ai/DECISIONS.md), and don't reverse a decision without the owner.

## Checks to run before pushing

The development PC is shared, so run only the checks that touch your change, **one heavy run at a time**, and leave full suites to CI. Database access from agent tools is **read-only**.

| Where | Command |
|---|---|
| Any PHP plugin | `php -l <changed files>`; `composer test -- --filter <TouchingTest>` |
| bcc-trust | `bash scripts/phpstan-local.sh <changed path>`. Not `composer phpstan`: that is whole-tree, and it dirties the tracked `vendor/`. Also `bash scripts/arch-guardrails.sh bcc-trust` (check it prints "Scanning bcc-trust") |
| bcc-frontend | `npm run typecheck`; `npm run lint` (this is `next lint`, not `npx eslint`); `npm test -- <touching test files>` |
| Operator-facing copy (frontend or PHP strings) | `bash scripts/cadence-pressure-guard.sh` from the umbrella root, before merging |
| Umbrella docs or contract | `php scripts/contract-parity-guard.php`, `php scripts/schema-drift-guard.php`, `php scripts/subsystem-count-guard.php`. They need the plugin trees under `app/public/wp-content/plugins/`, and exit 2 without them |

The plugins commit `vendor/` built without dev tools, so a local `composer install` for tests dirties tracked `vendor/composer/*` files. Restore them before committing with `git checkout -- vendor && git clean -fd vendor`, and never run `composer dump-autoload` ([CONVENTIONS.md §7](docs/ai/CONVENTIONS.md#7-testing)).

Codex gets no automatic hooks, so also check that you have not edited `vendor/`, `node_modules/`, lock files or `.env*`.

## Handing off

Before you stop:
1. Push every branch.
2. Fill in the **Handoff** section of each PR ([template](docs/ai/HANDOFF.md#handing-off-the-handoff-section)).
3. Update `docs/ai/CURRENT_STATE.md` if a workstream changed.
4. Leave a clean worktree.

Anything not on GitHub or in `docs/ai/` is lost to the next agent.
