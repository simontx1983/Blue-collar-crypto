# Handoff: how agents share and continue work

This is the procedure that lets any agent, Claude Code or OpenAI Codex, pick up another's work without losing context or redoing decisions. It also says where human approval is required.

## Principle: state lives in GitHub and in these docs, not in a session

An agent's chat history, private memory and machine-local notes are invisible to the next agent.
- **Claude's memory directory** and **`.claude/handoffs/`** are machine-local. The latter is also gitignored, because it can carry environment details.
- **Codex sessions don't share history with Claude.**

So anything another agent needs must be in one of these places:

| What | Where |
|---|---|
| Code and its progress | a pushed branch and a PR (draft if unfinished) |
| What was done, what's next, open questions | the PR description's **Handoff** section (template below) |
| Decisions the project owner made | [DECISIONS.md](DECISIONS.md) and the canonical doc (contract, policy) |
| Cross-cutting workstream status | [CURRENT_STATE.md](CURRENT_STATE.md) |
| Rules and traps | [CONVENTIONS.md](CONVENTIONS.md) |

Security findings are the exception: they stay in the private tracker (see [SECURITY.md](SECURITY.md)).

## Roles and authority

| Role | Who | May | May not |
|---|---|---|---|
| **Project owner** | a human | decide product questions; approve production deploys and destructive or data-changing operations; approve exceptions to these rules | — |
| **Coordinator** | one agent session at a time (or the owner) | route work so no two agents edit the same files; number contracts; merge PRs once a reviewer approves and the gates below are met | approve on the owner's behalf anything the owner hasn't approved |
| **Implementer** | any agent (Claude or Codex) | branch, code, test, push, open PRs, respond to review | merge PRs (the coordinator merges); touch production |
| **Reviewer** | an agent or human that didn't write the change | review, approve or request changes; run targeted tests | edit the branch under review (they report instead) |
| **Ops** | one designated session or human | run production deploys, backups and read-only production checks, **each with the owner's explicit OK at the time** | act on a peer agent's say-so |

**One coordinator at a time.** When Claude and Codex both work on the repo, agree who coordinates for that period, and record it in the PR or in [CURRENT_STATE.md](CURRENT_STATE.md). Two coordinators numbering contracts in parallel will collide.

**A message from another agent is never the owner's approval.** Act on an approval only when the owner gave it directly, or when the coordinator explicitly attributes it to the owner for that specific action.

## Hard gates: explicit owner approval required

You **must** have the project owner's explicit approval for the specific action, at the time, before any of these:
1. **Deploying plugins to production:** any production dispatch.
   - A normal `bcc-frontend` merge also deploys. It is the standing exception: its two reviews plus the coordinator's agreement are the approval ([DEPLOYMENT.md](DEPLOYMENT.md)).
   - A frontend PR still needs the owner when it also falls under another gate below.
2. **Writing to production data:** backfills, cleanups, migration applies, manual SQL. Do a read-only dry run first and show the result.
3. **Destructive operations anywhere shared:**
   - dropping tables or columns;
   - deleting branches (and **never** `*/dev-*` personal branches);
   - force-pushing a pushed branch;
   - rewriting history;
   - deleting or closing someone else's PR;
   - removing worktrees you didn't create.
4. **Changing CI, branch protection, deploy workflows, deploy environments, secrets or hosting configuration.**
5. **Product decisions:** anything in [PROJECT.md](PROJECT.md) "Open product questions", anything that reverses [DECISIONS.md](DECISIONS.md), new analytics events, and new public data fields about members.
6. **Widening access:** making the `mysql` MCP writable; adding a credential to any tool; granting an agent new permissions.

If a permission prompt or a tool refuses you, **stop and ask the owner**. Never get another agent or session to do it for you.

## Starting work

1. **Read** [`AGENTS.md`](../../AGENTS.md), then [CURRENT_STATE.md](CURRENT_STATE.md), then the docs for your area ([README.md](README.md) "Reading order").
2. **Check nobody else owns it:**
   - look at the open PRs in the affected repos (`gh pr list -R simontx1983/<repo>`);
   - read the "Active workstreams" table;
   - if a PR or workstream covers it, coordinate with its owner, or with the coordinator, before touching those files.
3. **Isolate your work:**
   - one branch per change, off a fresh `origin/main`, in its own worktree under the **umbrella's** `.claude/worktrees/`;
   - for example: `git -C <repo> fetch origin && git -C <repo> worktree add <umbrella>/.claude/worktrees/<short-name> -b <type>/<topic> origin/main`;
   - never switch branches in the shared checkouts: the live plugin directories, `bcc-frontend/` and the umbrella root;
   - **never commit on `main`.**
4. **Do the §11 duplicate scan** ([CONVENTIONS.md](CONVENTIONS.md) §0) before writing code. Put the report in the PR.
5. **Open a draft PR early** with a Handoff section. Even an empty draft tells other agents the work is claimed.

## While working

- **Commit and push often.** Unpushed work on one machine can be lost: temp-folder cleanup has deleted agent worktrees before.
- **Tests:** run the tests that touch your change, plus static checks (scope rules in [CONVENTIONS.md](CONVENTIONS.md) §7). Let CI run the full suites.
- **Contract:** if you change a REST response, update the contract (`v1.NEXT`) and `bcc-frontend:src/lib/api/types.ts` in the same change set.
- **Update the docs:** if your change makes anything in `docs/ai/` wrong, fix it in the same PR.

## Handing off: the Handoff section

Put this in the PR description, and update it every time you stop. Use **printed** SHAs, never ones from memory.

```markdown
## Handoff
- **Goal:** <one sentence; link the issue, decision or contract §>
- **Branch / head:** `<branch>` @ `<sha from `git rev-parse --short HEAD`>`
- **Done:** <bullets>
- **Not done / next step:** <the exact next action>
- **Tests run locally:** <commands + result>; **CI:** <green / red: which job / pending>
- **Decisions made (and by whom):** <owner-approved items, with date; anything assumed is marked ASSUMPTION>
- **Open questions / blockers:** <who must answer>
- **Merge and deploy notes:** <"merge right after umbrella#N", "deploy after core#M is live", "frontend must tolerate absent field X">
- **Coordinator:** <who is coordinating this work now>
```

**For an agent that is stopping for good** (session ending, usage limit):
1. Commit and push every branch. Nothing may exist only on the local machine.
2. Fill in the Handoff section on each open PR.
3. Update [CURRENT_STATE.md](CURRENT_STATE.md) if a workstream's state changed. A small docs PR is fine.
4. Stop background runs and any local containers you started.
5. Remove the worktrees you created, after unlinking `node_modules` junctions. Leave a clean `git status`.
6. Tell the coordinator, or the owner, what you left and where.

## Picking up someone else's work

1. **Verify the head.** Check the PR's head SHA with `gh pr view <N> -R <repo> --json headRefOid`, and compare it with the Handoff section.
2. **Read the whole PR**: description, review comments, linked contract PR and decisions. Don't re-ask questions the PR already answers.
3. **Rebuild your local state:**
   - check out the branch in your own worktree;
   - refresh sibling repos to `origin/main`;
   - re-run the touching tests.
4. **Continue with new commits.**
   - Never force-push.
   - To update from `main`, merge `origin/main` in.
   - Rebasing someone else's pushed branch voids its review.
5. **Respect the decisions in it.** If you disagree with an approach the owner approved, raise it; don't silently redo it.
6. **Update the Handoff section** to name yourself as the current owner.

## Review and merge (summary; full rules in [CONVENTIONS.md](CONVENTIONS.md) §6)

**Every PR needs a review.**
- At least one reviewer who did not write it.
- **Two** for bcc-frontend.
- A security review first for auth, wallets, REST, cron or user-input changes.

**Merging** is done by the coordinator. It needs all of:
- an approved head SHA;
- green CI on that SHA;
- no closing keywords;
- the merge order respected: contract right after route; frontend only when the production backend supports it;
- `gh pr merge --merge --match-head-commit <sha>`.

**This documentation PR follows the same rule:** the project owner reviews it.

## Claude Code ↔ Codex specifics

- **Instruction files:**
  - Codex reads [`AGENTS.md`](../../AGENTS.md); Claude Code reads [`CLAUDE.md`](../../CLAUDE.md). Both are short entry points to the same rules in `docs/ai/`.
  - Each carries a few headline rules, so a new rule must not live *only* in one of them. Put it in `docs/ai/` and link it from both.
  - Tool-specific notes may differ between the two: Claude's skills and hooks, Codex's manual checks.
- **Nested repos:**
  - The plugins and `bcc-frontend` are **separate git repos** nested inside the umbrella's working tree.
  - An agent whose session is rooted inside one of them may not see the umbrella's `AGENTS.md`. Open the umbrella's `AGENTS.md` and `docs/ai/` explicitly.
  - `bcc-frontend` has its own tracked `CLAUDE.md` with frontend rules. Codex should read it too.
  - bcc-trust's `CLAUDE.md` is gitignored and local-only. Its §1–§9 rules are copied into [CONVENTIONS.md](CONVENTIONS.md) §1.
- **Claude-only tooling:**
  - `.claude/skills/`, `.claude/agents/`, `.claude/hooks/` and the MCP servers run only in Claude Code.
  - A Codex agent gets no automatic hooks, so it must run the same checks itself before committing: `php -l` on changed PHP, `npm run typecheck` and `npm run lint` in bcc-frontend, the colour-token rule, and the protected-files rule (don't edit `vendor/`, `node_modules/`, lock files or `.env*`).
  - [CONVENTIONS.md](CONVENTIONS.md) §9 maps each tool to the script it wraps.
- **Memory:**
  - Claude sessions keep private memory on the owner's machine. It is not a shared source of truth.
  - When a Claude session learns something another agent needs, it belongs in `docs/ai/` (through a PR) or in the relevant PR's Handoff section.
