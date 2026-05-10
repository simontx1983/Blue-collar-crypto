# Multi-Agent Workflows for BCC

How to actually use the subagents in [.claude/agents/](agents/) for parallel work. Read [CLAUDE.md](../CLAUDE.md) first — the §1–§11 rules apply to every agent and every workflow on this list.

## Honest constraints (so you don't design around them)

1. **Subagents are leaf nodes.** A subagent invoked via the `Agent` tool **cannot** invoke `Agent` itself. All coordination is hub-and-spoke through the main (orchestrator) session.
2. **Cross-agent state lives in files.** Subagents inherit project [CLAUDE.md](../CLAUDE.md) and your user-level `MEMORY.md`, but they do **not** see each other's conversations. To pass state mid-task, write to a file the next agent reads, or have the orchestrator carry the summary forward in its next prompt.
3. **Parallel dispatch is one message, multiple tool calls.** Spawning two `Agent` calls in a single assistant message runs them concurrently. Spawning them in two separate messages serializes them.
4. **Worktrees are for separate Claude windows, not for one orchestrator.** `git worktree add` lets you run two real Claude Code sessions on the same repo in two terminals. The orchestrator inside one session does not "switch worktrees."
5. **New `.claude/agents/*.md` files are not picked up mid-session.** Claude Code loads subagent definitions at session start. If you (or Claude) add a new agent file during a session, `Agent` calls for that name fail with "agent type not found." **Restart Claude Code (or open a new session) before the new agent is usable.** Same for new skills under `.claude/skills/`.

## The agents you have

**Reviewers** (verify, never edit):

- [duplicate-scanner](agents/duplicate-scanner.md) — runs the §11 cross-codebase scan. **Always before new code.**
- [arch-guardrails-reviewer](agents/arch-guardrails-reviewer.md) — PHP §1–§9 enforcement, after backend edits.
- [frontend-reviewer](agents/frontend-reviewer.md) — Next.js rules, after frontend edits.
- [holder-groups-reviewer](agents/holder-groups-reviewer.md) — feature-scoped to NFT→PeepSo group-gating; retire when that feature ships.

**Implementers** (build, run their own checks):

- [backend-implementer](agents/backend-implementer.md) — PHP under `app/public/wp-content/plugins/bcc-*/`.
- [frontend-implementer](agents/frontend-implementer.md) — TypeScript under `bcc-frontend/`.

## Workflow A — Single feature, BE + FE in parallel

The most common shape on this codebase. One feature touches a REST endpoint and a frontend hook/component.

1. **Run `/duplicate-scan`** with the feature description. Wait for the report.
2. **Orchestrator drafts (or confirms) the REST contract** — field names, envelope shape, presentation fields. This is the synchronization point; both implementers must be told the *same* contract.
3. **Single assistant message, two `Agent` calls in parallel:**
   - `backend-implementer` — brief includes the contract, target domain (e.g., `bcc-trust/app/Domain/Disputes/`), reuse hints.
   - `frontend-implementer` — brief includes the same contract, target area (e.g., `bcc-frontend/src/components/profile/`), reuse hints.
4. **Reconcile.** Read both reports. If either flagged a contract question, resolve it and re-dispatch the affected agent only.
5. **Single assistant message, two reviewer `Agent` calls in parallel:**
   - `arch-guardrails-reviewer`
   - `frontend-reviewer`
6. **Fix anything they flag**, then declare the work done.

## Workflow B — Independent BE and FE work

If the two streams of work are genuinely unrelated (e.g., a backend refactor and a frontend bug), don't try to share an orchestrator context.

```
git worktree add ../bcc-feat-x feat/x
# open a second Claude Code window in ../bcc-feat-x
# original session keeps working on the other branch
```

When you're done: `git worktree remove ../bcc-feat-x`. Two real sessions, two contexts, no cross-contamination, no token cost in either session for the other's work.

## Workflow C — Long audits in the background

Use the `Agent` tool's `run_in_background: true` flag for read-only sweeps that would otherwise block you for minutes:

- "Grep every PHP file under `app/public/wp-content/plugins/` for `SELECT *` in non-test paths and report path:line."
- "Walk every React Query hook in `bcc-frontend/src/hooks/` and check for missing `enabled` guards."
- "Cross-codebase scan for any `wp_cache_set()` call that doesn't go through a generation counter."

Spawn the agent, keep working. Claude Code notifies you when it finishes. Don't poll. Don't `sleep`.

## Workflow D — Experimental Agent Teams (footnote)

Claude Code has an experimental mode where teammates can message each other directly via a shared task list:

```
CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1 claude
```

This is the only mechanism that gives you real teammate-to-teammate messaging (instead of hub-and-spoke through the orchestrator). The cost is ~3–5× a normal session in tokens because each teammate is a full context window, and it's experimental — expect breakage. **Use for genuinely complex coordination only**; daily work should stay in Workflows A–C.

## A worked example of the parallel dispatch pattern

You're adding a `last_seen_at_string` field to the user view-model and rendering it in the profile card. After `/duplicate-scan` and after drafting the REST contract:

```
Spawn (in one message):

Agent(backend-implementer):
  brief: "Add `last_seen_at_string` (already-formatted relative time, server side)
  to the User view-model under bcc-trust/app/Domain/Core/. Existing column
  `last_seen_at` is the source. Use the timezone-aware helper in
  Domain/Core/Util/RelativeTimeFormatter.php. No new DB column. Update
  Envelope-wrapped REST response per docs/api-contract-v1.md. Out of scope:
  any other view-model field, the disputes domain."

Agent(frontend-implementer):
  brief: "Render `presentation.last_seen_at_string` in
  src/components/profile/ProfileCard.tsx as plain text under the avatar.
  No client-side time math. Hook is already typed; just add the field to
  the type and render. Out of scope: any other ProfileCard change."
```

Both return. Orchestrator dispatches `arch-guardrails-reviewer` + `frontend-reviewer` in parallel. Done.

## What this setup will NOT do for you

- It will not let two subagents talk to each other directly. (Workflow D excepted, with caveats.)
- It will not give subagents persistent memory beyond `CLAUDE.md` + `MEMORY.md`.
- It will not stop a poorly-briefed implementer from making bad design choices. The orchestrator is responsible for the brief.
- It will not replace the §11 duplicate-scan, which still runs **before** any new code is written.
