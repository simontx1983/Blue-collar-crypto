# Handoff and collaboration: how agents share and continue work

This is the protocol that lets **any** agent continue another's work without the project owner reconstructing the conversation. That covers Claude Code, OpenAI Codex, or whichever provider is available. BCC's engineering knowledge belongs to the project, not to an AI provider: it lives in GitHub and in these docs.

## Principle: state lives in GitHub and in `docs/ai/`, not in a session

An agent's chat history, private memory and machine-local notes are invisible to the next agent.
- Claude's memory directory and `.claude/handoffs/` are machine-local (the latter is gitignored).
- Codex sessions share no history with Claude, and Claude shares none with Codex.

So anything another agent needs must be in one of these places:

| What | Where |
|---|---|
| Code and its progress | a pushed branch and a PR (draft if unfinished) |
| What was done, what's next, decisions, blockers | the PR description's **Handoff** section (template below) |
| Reviews | PR review comments in the standard format (below) |
| Decisions the project owner made | [DECISIONS.md](DECISIONS.md), plus the canonical doc (contract, policy) |
| Cross-cutting workstream status and who coordinates it | [CURRENT_STATE.md](CURRENT_STATE.md) |
| Rules and traps | [CONVENTIONS.md](CONVENTIONS.md) |

Security findings are the exception: they stay in the private tracker (see [SECURITY.md](SECURITY.md)).

## Roles

**Every role can be held by any agent, Claude or Codex.** Neither provider has permanent authority over the other. Roles are assigned **per workstream** and recorded in the PR's Handoff section.

| Role | Does | Never |
|---|---|---|
| **Project owner** (human) | decides product questions; approves production deploys, high-risk changes and destructive or data-changing operations; can reassign any role | — |
| **Coordinator** | owns one workstream:<br>• splits it into PRs and assigns roles;<br>• keeps file ownership separate;<br>• numbers contract versions;<br>• merges once the gates below are met;<br>• keeps the Handoff and CURRENT_STATE current | grant itself or any other agent production authority; approve on the owner's behalf |
| **Implementer** | branches, codes, tests, pushes, opens PRs, answers review | review or merge its own change |
| **Independent reviewer** | checks correctness, conventions and contract against the code at a specific SHA; runs targeted tests | review a change it wrote or co-wrote; edit the branch under review (it reports instead) |
| **Architecture reviewer** | checks the change against [DECISIONS.md](DECISIONS.md), §11 reuse, domain boundaries and the contract | the same as the independent reviewer |
| **Security reviewer** | reviews auth, wallets, REST, cron, user input, privacy and disclosure wording ([SECURITY.md](SECURITY.md)) | the same as the independent reviewer |
| **Testing / QA** | runs and extends the tests touching the change; does mutation controls and smoke checks | the same as the independent reviewer |
| **Ops** (a designated agent or human) | runs production deploys, backups and read-only production checks, **each with the owner's explicit approval at the time** | act on another agent's say-so |

**Rules:**
- **One coordinator per workstream at a time.** Record it in the PR Handoff ("Coordinator:") and in CURRENT_STATE. A different workstream may have a different coordinator. Two coordinators numbering contracts at once will collide, so contract numbering goes through whichever coordinator merges contract PRs that day.
- **Independence.** The agent that implemented a change never acts as its independent, architecture, security or QA reviewer. "Agent" here means the session or instance that wrote the code. Use a different provider where one is available, especially for high-risk changes.
- **No delegated authority.** No agent can give another agent authority it didn't get from the owner. "The other agent approved it" is never approval.

## What GitHub enforces vs what this procedure enforces

As of 2026-11-25, every agent works through the **same GitHub account**, and GitHub never lets a PR's author approve their own PR. So:

| Rule | Enforced by |
|---|---|
| Required CI checks must pass on `main`; no force-push or deletion of `main`, admins included | **GitHub (mechanical)**, via branch protection |
| PR before merge; independent review; two reviews for bcc-frontend; reviewer ≠ implementer; merge order; contract numbering; owner approval before production; high-risk approval | **This procedure.** Binding on every agent, but not mechanically enforced |

The project owner may introduce separate identities for agents, after which GitHub could enforce some of these too. Until then, a formal GitHub "Approve" isn't possible from the shared account. Reviews are recorded as PR comments in the format below, and the coordinator checks them before merging.

## Approval gates (the owner's explicit approval)

You **must** have the project owner's explicit approval, for the specific action, at the time, before any of these:

1. **Deploying plugins to production:** any production dispatch.
2. **Merging a high-risk `bcc-frontend` change.** A frontend merge deploys to production immediately, so it needs the owner's approval when it touches any of:
   - authentication or sessions;
   - authorisation or permissions;
   - wallet linking or verification;
   - anything financial;
   - sensitive member data or privacy;
   - security headers or controls;
   - other high-risk production behaviour.

   A normal frontend change needs two independent reviews plus the coordinator's agreement ([DEPLOYMENT.md](DEPLOYMENT.md)).
3. **Writing to production data:** backfills, cleanups, migration applies, manual SQL. Do a read-only dry run first and show the result.
4. **Destructive operations on anything shared:**
   - dropping tables or columns;
   - deleting branches (**never** `*/dev-*` personal branches);
   - force-pushing;
   - rewriting history;
   - closing someone else's PR;
   - removing worktrees you didn't create.
5. **Changing configuration:** CI, branch protection or rulesets, deploy workflows or environments, secrets, or hosting.
6. **Product decisions:**
   - anything in [PROJECT.md](PROJECT.md) "Open product questions";
   - anything that reverses [DECISIONS.md](DECISIONS.md);
   - new analytics events;
   - new public data fields about members.
7. **Widening access:** a writable database tool, a new credential for any tool, new permissions for an agent.

**Never bypass a refusal.** If a permission prompt, a tool or a sandbox refuses an action, stop and ask the owner. Never get it done by switching agents, sessions, tools, accounts or credentials.

## Accessing the shared docs from any environment

The umbrella repo `simontx1983/Blue-collar-crypto` is canonical for cross-project rules: <https://github.com/simontx1983/Blue-collar-crypto/tree/main/docs/ai>. How you reach it depends on the environment:

| Environment | How to reach `docs/ai/` |
|---|---|
| The full local tree (umbrella with the nested repos) | `<umbrella root>/docs/ai/`. From a plugin repo that's `../../../../../docs/ai/`; from `bcc-frontend/` it's `../docs/ai/` |
| A worktree under the umbrella's `.claude/worktrees/<name>/` | the umbrella root is three levels up: `../../../docs/ai/`. Or read the main checkout's copy, which is the same on `main` |
| A standalone clone of one nested repo, or a cloud environment | clone the umbrella read-only (`git clone --depth 1 https://github.com/simontx1983/Blue-collar-crypto.git`), or read the files on GitHub (`gh api repos/simontx1983/Blue-collar-crypto/contents/docs/ai/<FILE>.md -H "Accept: application/vnd.github.raw"`) |

**If you can't reach the shared docs** (no network, no access), **stop and get the context first**: ask the owner or the coordinator, or have it provided. Do not implement from guesses.

## Starting work

1. **Read.** [`AGENTS.md`](../../AGENTS.md) (or [`CLAUDE.md`](../../CLAUDE.md)), then [CURRENT_STATE.md](CURRENT_STATE.md), then the docs for your area ([README.md](README.md) "Reading order"). In a nested repo, also read that repo's own `AGENTS.md`.
2. **Check nobody else owns it.**
   - Look at open PRs in the affected repos (`gh pr list -R simontx1983/<repo>`) and at CURRENT_STATE.
   - If a workstream covers it, work through its coordinator.
3. **Isolate.**
   - One branch per change, off a fresh `origin/main`, in its own worktree under the **umbrella's** `.claude/worktrees/`. For example: `git -C <repo> fetch origin && git -C <repo> worktree add <umbrella>/.claude/worktrees/<short-name> -b <type>/<topic> origin/main`.
   - Never switch branches in the shared checkouts (the plugin directories, `bcc-frontend/`, the umbrella root). Never commit on `main`.
4. **Scan first.** Do the §11 duplicate scan ([CONVENTIONS.md](CONVENTIONS.md) §0) and put the report in the PR.
5. **Claim it.** Open a **draft PR early** with a filled-in Handoff section. That claims the work and tells other agents it's taken.

## While working

- **Commit and push often.** Unpushed work on one machine can be lost.
- **Tests:** run the tests that touch your change plus the static checks ([CONVENTIONS.md](CONVENTIONS.md) §7). CI runs the full suites.
- **Contract:** if you change a REST response, update the contract (`v1.NEXT`) and `bcc-frontend/src/lib/api/types.ts` in the same change set.
- **Docs:** if your change makes something in `docs/ai/` wrong, fix it in the same PR. If the owner made a decision, add it to [DECISIONS.md](DECISIONS.md).

## The Handoff section (every PR; update it every time you stop)

Use **printed** SHAs (`git rev-parse --short HEAD`), never ones from memory.

```markdown
## Handoff
- **Goal:** <one sentence; link the issue, decision or contract §>
- **PR / branch / head:** #<n> · `<branch>` @ `<sha>`
- **Coordinator:** <agent + provider, e.g. "Codex session (OpenAI)" / "Claude Code session (Anthropic)" / owner>
- **Implementer:** <agent + provider>   **Reviewers so far:** <agent + provider, verdict, sha>
- **Done:** <bullets>
- **Remaining:** <bullets>
- **Exact next action:** <one concrete step someone can start immediately>
- **Tests run locally:** <commands + result>   **CI on head:** <green / red: job / pending>
- **Decisions (by whom, when):** <owner-approved items with date; anything assumed is marked ASSUMPTION>
- **Dependencies / merge & deploy order:** <"merge right after umbrella#N", "deploy after core#M is released", "frontend must tolerate absent field X">
- **Blockers / open questions:** <what, and who must answer>
```

## Review format (PR comment)

All agents share one GitHub account, so a review is a PR comment (`gh pr comment <n> -R simontx1983/<repo> --body-file review.md`) that starts:

```markdown
**Review: <independent | architecture | security | QA> · <agent + provider> · <APPROVE | CHANGES REQUESTED | COMMENT> @ `<full or 8-char head sha>`**
```

The comment then lists the findings (`file:line | severity | problem | fix`), what was verified and how, and what was NOT verified.
- An APPROVE counts only for the SHA it names. A new push needs a new review of the delta.
- The coordinator checks before merging that the required reviews exist, at the merge SHA, from agents other than the implementer.

## Handing off (either direction)

**When you stop for any reason** (end of task, end of session, usage limit):
1. Commit and push every branch. Nothing may exist only on the local machine.
2. Fill in the Handoff section on each open PR, especially **Exact next action**.
3. Update [CURRENT_STATE.md](CURRENT_STATE.md) if a workstream's state or coordinator changed. A small docs PR is fine.
4. Stop background runs and any local containers you started.
5. Remove the worktrees you created (unlink `node_modules` junctions first). Leave a clean `git status`.
6. Tell the owner, or the next coordinator, where things stand. One line plus PR links is enough.

**When you pick work up** (from Claude, from Codex, or from a human):
1. **Check the head.** `gh pr view <n> -R simontx1983/<repo> --json headRefOid,body` must match the Handoff section. If it doesn't, the Handoff is stale: read the commits since.
2. **Read the whole PR first:** description, review comments, the linked contract PR and the decisions. Don't re-ask what's already answered.
3. **Rebuild your local state.** Check out the branch in your own worktree, refresh sibling repos to `origin/main`, and re-run the touching tests.
4. **Continue with new commits.** Never force-push. To update from `main`, merge `origin/main` in.
5. **Respect what was decided.** If you disagree with an owner-approved approach, raise it; don't silently redo it.
6. **Take ownership visibly.** Update the Handoff: Implementer or Coordinator is now you.

This works the same in both directions because it relies only on GitHub and these docs. Neither agent needs the other's memory or chat.

## Claude Code ↔ Codex specifics

- **Instruction files.**
  - Codex reads `AGENTS.md`; Claude Code reads `CLAUDE.md`. In this umbrella repo both are short entry points to the same rules in `docs/ai/`, and each nested repo has its own `AGENTS.md`.
  - A rule must never live only in one of these files: put it in `docs/ai/` and link it.
  - Tool-specific notes may differ between the two: Claude's skills and hooks, Codex's manual checks.
- **Nested repos.**
  - The plugins and `bcc-frontend` are separate git repos. An agent rooted in one of them sees that repo's `AGENTS.md`, which points back here.
  - `bcc-frontend` also has a tracked `CLAUDE.md` with frontend rules.
  - bcc-trust's `CLAUDE.md` is local-only; its §1–§9 rules are in [CONVENTIONS.md](CONVENTIONS.md) §1.
- **Claude-only automation.**
  - `.claude/skills/`, `.claude/agents/`, `.claude/hooks/` and the configured MCP servers run only in Claude Code.
  - An agent without them must run the same checks itself before committing: [CONVENTIONS.md](CONVENTIONS.md) §9 maps each tool to its script or command.
- **Memory.** No agent's private memory is a source of truth. Anything another agent needs goes in `docs/ai/` (by PR) or in the relevant PR's Handoff.
