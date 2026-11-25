# Conventions

These rules apply to every agent and every human. Every rule has a reason, and usually a past incident behind it.

Where a rule is enforced mechanically, the enforcing script is named. The script is the authority: if this page and the script disagree, trust the script, fix this page, and say so in your PR.

## 0. The first rule: §11, reuse before you write

Before writing or modifying code anywhere, do the **cross-codebase duplicate scan** defined in the root [`CLAUDE.md`](../../CLAUDE.md) §11.
- **Search:** the current repo, `bcc-global-library/`, [`docs/pattern-registry.md`](../pattern-registry.md), and any referenced repo.
- **Prefer:** REUSE or EXTEND. Parallel implementations are a guardrail violation.
- **Report:** produce the CROSS-CODEBASE SCAN REPORT in the shape given in [`docs/prompts/duplicate-scan.md`](../prompts/duplicate-scan.md). It must include at least one real search and concrete file paths.
- **Claude Code:** the `/duplicate-scan` skill does this.
- **Codex and others:** do it by hand with `rg`, and put the report in your PR description.

## 1. Backend rules §1–§9 (all bcc-* plugins)

These were previously written only in the gitignored `bcc-trust/CLAUDE.md`, so a fresh clone could not see them. This section is now the tracked copy. Local agent files may add to these rules but never relax them.

1. **Repository-only DB access.** All `$wpdb` lives in `app/Domain/*/Repositories/` or `app/Infrastructure/`. Each documented exception is commented in place (for example `TransactionManager`).
2. **No `SELECT *`.** Use explicit column lists, usually `private const COLUMNS`.
3. **No queries in templates.** Templates receive data from controllers or services.
4. **Bounded queries.** Every `SELECT` has a `LIMIT`, a unique-key filter, a bounded `IN ()`, or is an aggregate.
5. **Cache invalidation by generation counters.** Write paths increment a generation counter, and read paths key their cache by it.
6. **PHPStan level 8, with no `@var` or `assert()` overrides.** Fix the types; don't suppress them.
7. **No named parameters in cross-plugin calls.** Pass arguments positionally, to keep compiled autoloads stable under composer dump.
8. **No user-facing UI in PHP.** PHP returns arrays, DTOs or JSON over REST. No shortcodes, no block types, no `templates/`, and no echoed HTML in services or controllers.
   - Exception: wp-admin pages and admin notices (paths under `*/Admin/`, `admin_notices` subscribers, methods named `*Notice`).
   - The admin split is locked; see [ARCHITECTURE.md](ARCHITECTURE.md) §3.9.
   - Enforced by `bcc-trust:scripts/arch-guardrails.sh`, rules 6–9.
9. **Load-bearing contract, verified rather than assumed.** Every `/wp-json/bcc/v1/` and `/wp-json/bcc-trust/v1/` response conforms to the contract (§1.4–§1.5), and `Envelope` wraps it automatically.
   - A PR touching `app/Domain/*/REST/` or a view-model builder **must** run `bash scripts/arch-guardrails.sh --with-contract`, which calls `scripts/api-contract-check.sh` against the live local site.
   - Claude Code can also use the `api-contract-guard` skill mid-flow. It wraps the same check plus a manual diff against the contract doc and `bcc-frontend/src/lib/api/types.ts`. The skill is in addition to the script, not instead of it.
   - **A contract break is P0.**

**Additional backend rules:**
- **Fail closed** on any read that decides access, visibility, moderation, a list's completeness, or suspension ([DATABASE.md](DATABASE.md) "Fail-closed reads"). Use `ProbesReads` and `GuardsReadFailures`; never `?: []` on such a read.
- **PeepSo writes** go only through `bcc-core:src/PeepSo/*Writer.php`. Any `PeepSoGroupWriter::join` must come after a BCC server-side gate (the `peepso-write-guard` skill checks this).
- **One implementation per primitive.** Crypto, address parsing, trust score (`TrustScoreService`), feed ranking (`FeedRankingService`) and notification dispatch each have exactly one home. Look it up in [`pattern-registry.md`](../pattern-registry.md).
- **Degradation metrics.** A new degradation subsystem or event registers in the bcc-core map **and** in both `pattern-registry.md` and `GOLDEN_PATHS.md`. `scripts/subsystem-count-guard.php` regex-scans those docs for `name (N events)` and `name / N events`, and fails on drift. `record()` takes literal arguments.
- **New tables** need a `| wp_bcc_… | ~rows | Purpose | Owning code | Status |` row in [`docs/database-schema.md`](../database-schema.md), otherwise `scripts/schema-drift-guard.php` fails.
- **New cron hooks** register in the expected-hooks filter and in [`docs/cron-registry.md`](../cron-registry.md).
- **Docs cite symbols, not line numbers.** Line numbers rot.
- **Before sizing a refactor,** grep and count the real call sites.

## 2. Frontend rules (`bcc-frontend`)

The canonical sources, both tracked in that repo, are:
- `bcc-frontend:CLAUDE.md`, the working rules;
- `bcc-frontend:docs/frontend-doctrine.md`, the reasons behind them. §5 is the visual canon and wins any conflict.

The summary below is for orientation:
- **No business logic.**
  - Scores, tiers, ranks, permissions and labels come from the API.
  - You may branch on a server enum; you may never derive a value.
  - Permissions are read via `src/lib/permissions.ts`.
- **No raw `fetch()` outside the API clients** (`src/lib/api/client.ts`, `bcc-trust-client.ts`, plus the documented exceptions). [DISCREPANCY] The doctrine's allowlist omits a few same-origin NextAuth calls in `src/lib/auth/`.
- **Hooks** live one per capability in `src/hooks/`.
  - They return the React Query shape `{data, isLoading, error, …}`.
  - The `queryKey` covers every input, and `enabled` gates queries whose inputs aren't ready.
  - Server state is never mirrored into `useState`.
- **No `as any`.** ESLint errors on `no-explicit-any`. TypeScript is strict, plus `exactOptionalPropertyTypes` and `noUncheckedIndexedAccess`.
- **Server/client boundaries** (each rule traces to a past Vercel build failure):
  - `useSearchParams()` needs `<Suspense>`;
  - never import `next-auth/react` in a server file;
  - `SessionProvider` lives only in `providers.tsx`;
  - server components import shared helpers only from **hook-free, JSX-free** modules.
- **Never cache authed or personalised responses.** Only anonymous server reads get `revalidate`.
- **Colour.**
  - Every colour is a token defined in `src/app/globals.css`. No raw hex, no `rgb()`/`hsl()` literals, no named Tailwind palette classes such as `text-red-500` or `bg-white`.
  - App code uses the *aesthetic* names: `--safety`, `--weld`, `--verified`, `--blueprint` and their Tailwind aliases such as `text-safety`. The *brand-namespace* spellings (`--bcc-safety` and the rest) are reserved for the brand layer and for `src/components/cards/`.
  - Text colour must match its surface family: theme surfaces vs the fixed cream/ink "paper" surfaces. Mixing them is the most repeated bug.
  - Enforced by the umbrella `.claude/hooks/color-token-check.sh` (Claude only) and by source-scan tests in `src/app/` (everyone). See `frontend-doctrine.md` §5.4.
- **Motion.** Every animation respects `usePrefersReducedMotion()` and falls back to a static state, not a shorter animation.
- **Tap targets.**
  - About 44px for primary controls (`HIT_AREA_44`); 36px only for dense chips.
  - Enforced by `src/app/f317-tap-targets.test.ts` and similar tests.
- **Responsive.** The primary target is 375px wide, with a floor of 360px. A component that breaks at 375px is not done.
- **Feed and card components are memoised**, and parents pass stable callbacks.
- **Browser storage** keys are viewer-scoped (`scopedKey`); a guard test fails on unclassified `bcc*` keys.
- **Errors.** Branch on `err.code`, never on `err.message`.
- **Commits in bcc-frontend use `type(scope): description` with NO `Co-Authored-By` trailer** (that repo's rule).

## 3. Contract changes

The contract is [`docs/api-contract-v1.md`](../api-contract-v1.md), in the umbrella repo.

**What changes together.** A response shape change touches three places at once:
1. the PHP builder (plugin PR);
2. the contract (umbrella PR);
3. `bcc-frontend:src/lib/api/types.ts` (frontend PR).

Nothing checks `types.ts` automatically.

**Numbering:**
- An unmerged contract PR uses the literal **`v1.NEXT`** in its changelog heading, inline markers and title.
- Real numbers (`v1.264`, …) are assigned **at merge time, in merge order**, by the coordinator who merges contract PRs (see [HANDOFF.md](HANDOFF.md) "Roles"). The coordinator also updates the Status line. Only one coordinator numbers at a time, or numbers collide.
- The changelog is newest-first, so the new entry must sit above the current top entry.

**A route PR and its contract merge back to back.**
- `scripts/contract-parity-guard.php` fails every umbrella PR while a registered route is undocumented.
- So never merge a plugin PR that adds `register_rest_route` until its contract PR is ready to merge within minutes.
- This has broken umbrella CI twice: on 2026-11-24 and 2026-11-25.

**What the guard fails on.** Every kind of drift fails. A route registered in PHP must either be documented in §4 (a `#### \`METHOD /path\`` heading) or be added to the guard's `EXEMPT_INTERNAL` allowlist with a reason. A stale allowlist entry also fails.

**Retired routes.** A route kept as a `#### METHOD /path` heading still counts as declared. Delete or reword the heading when the route goes.

**Route arguments must be literals** so the guard can resolve them.

## 4. Copy and vocabulary

- **No cadence-pressure copy:** no streaks, "days since", "you haven't…" or "you should…" ([`cadence-pressure-policy.md`](../cadence-pressure-policy.md)).
  - `scripts/cadence-pressure-guard.sh` checks operator-facing copy: the frontend's `src/app`, `src/components` and `src/lib/copy.ts`, plus four bcc-trust services that emit user-facing strings.
  - **Run it before merging any branch that adds or changes such copy.** Umbrella CI checks the sibling repos only at their default-branch tip, so a frontend or plugin PR is not checked before it merges.
  - The guard scans fixed paths relative to the umbrella root, so run it with your branch checked out where those paths point.
  - Override only inline, with `cadence-pressure-guard:allow — <reason>` on the same or the preceding line.
- **Never price.** No market data in copy or storage ([PROJECT.md](PROJECT.md)).
- **No public dates** of verification, claim or join (join date is month only).
- **Display vocabulary** is listed in [PROJECT.md](PROJECT.md). Wire names stay frozen.

## 5. Git, PRs and commits

| Rule | Why |
|---|---|
| One branch per change, off a fresh `origin/main`. Never commit to `main` directly | Isolation; reviewability |
| Commit format: `type(scope): description` | Consistent history |
| Never write a closing keyword (`fixes #N`, `closes #N`) in a **commit message**: GitHub closes the issue even when the sentence negates it. Write `Relates to #N`. Check `closingIssuesReferences` is empty before merging | Issues were closed by accident |
| Never force-push a branch once it is pushed. Add commits; update by merging `origin/main` in | A force-push voids approvals and loses review context |
| Never skip hooks (`--no-verify`, `core.hooksPath`), even on throwaway commits | Hooks are part of the guardrails |
| **Security wording in public repos:** PR titles and bodies, commits, comments, docblocks, test names and contract entries for a security fix describe the *guarantee*, not the attack, until the fix is in production. Reference the private tracker as `F-NNN (private tracker)` | These repos are public; see [SECURITY.md](SECURITY.md) |
| No production data in public artifacts: no counts, user or post ids, hosts or environment details in PRs, commits, fixtures or docblocks | The same disclosure rule applies |
| Cite an `F-NNN` tracker number only if the coordinator or tracker keeper gave it to you | A wrong number in a commit can't be fixed without a force-push |
| Quote SHAs, PR numbers and branch names only from **printed command output**, never from memory | Misremembered SHAs caused wrong merges |
| Never delete long-lived `*/dev-*` personal branches; merge their PRs without `--delete-branch` | They belong to another engineer |
| Stacked PRs: retarget the child to `main` **before** merging its base. `--delete-branch` on the base closes the child | Lost PRs |
| Always pass `--repo`/`-R` to `gh pr …` | Five repos are on disk; the wrong one gets edited |
| Merge with `gh pr merge <N> --merge --match-head-commit <reviewed-sha>` | Never merge a head that wasn't reviewed |
| Stage explicit paths; never `git add -A` in shared checkouts. bcc-trust tracks `vendor/`, so don't stage regenerated autoloads by accident | Accidental commits |

## 6. Review and merge

- **Who must agree:** a reviewer's approval, plus agreement from whoever coordinates the merge (the coordinating agent or the project owner).
- **Frontend PRs** need **two** reviewers, and must be safe against the **backend currently in production**, not against `main`. A frontend merge goes live on Vercel immediately; backend merges wait for the next manual deploy.
- **Read the Vercel check on frontend PRs.** `next build` runs only there, and it catches server/client import errors that tsc and vitest miss.
- **Check fixtures against the real emitter.** A hand-made test fixture can pass while production breaks; grep the server builder.
- **Before merging:**
  - the head equals the reviewed SHA;
  - CI is green on that head;
  - `closingIssuesReferences` is empty.
- **Merge order:**
  - a docs PR describing code in another repo merges after that code;
  - a contract PR merges right after its route PR.
- **Two green PRs that share a collaborator can break `main` together.** After merging the first, re-run the second's CI. A cancelled run is not a green run.
- **Security-sensitive PRs** get a security review before the normal review. That covers REST endpoints, auth, wallet linking, on-chain signal writers, cron, and anything handling user input. In Claude Code, `/security-review` is a built-in command, not a project skill. Fix findings or get explicit sign-off.

## 7. Testing

| Repo | Command (from the repo root) | CI |
|---|---|---|
| bcc-core | `composer test` (phpunit); PHPStan via `vendor/bin/phpstan analyse` | PHPStan L8 + PHPUnit (required) + classmap guard |
| bcc-search | `composer test` | `php -l` + PHPStan L8 + PHPUnit (required); checks out bcc-core |
| bcc-trust | `composer test` (filter to touching tests); `composer test:integration`; **`bash scripts/phpstan-local.sh <changed path>`** for local PHPStan; `bash scripts/arch-guardrails.sh bcc-trust`; `php scripts/golden-staleness-guard.php`. All `scripts/` paths here are bcc-trust's own `scripts/`. Don't run `composer phpstan` locally: it analyses the whole tree, it is CI's command, and installing the dev tools for it rewrites the tracked `vendor/composer/autoload_*` files. `phpstan-local.sh` restores them (see `bcc-trust:docs/running-phpstan-locally.md`) | PHPStan + unit + guardrails + mutation controls (required); MySQL integration (required); MariaDB integration and DB mutation controls (not required) |
| bcc-frontend | `npm run typecheck`; `npm run lint` (this is `next lint`, **not** `npx eslint`); `npm test -- <paths>` | tsc + lint + knip (dead files) + vitest (required). `next build` runs only on Vercel |
| umbrella | `php scripts/contract-parity-guard.php`; `php scripts/subsystem-count-guard.php`; `php scripts/schema-drift-guard.php`; `php scripts/dead-file-scan.php`; `bash scripts/cadence-pressure-guard.sh`. Run them from a checkout that has the plugin trees under `app/public/wp-content/plugins/`. Without them the guards refuse to run (exit 2) | Cross-repo guards (required). It checks out the other four repos at their default-branch tip |

**Local test scope.** This is a standing rule from the project owner; the development PC is shared by many agents.
- **Run locally:**
  - every test that **touches** your change (grep the tests for the classes, imports and routes you changed);
  - PHPStan on the changed files;
  - arch-guardrails;
  - frontend `tsc` and `next lint`.
- **Leave to CI and Vercel:** full PHPUnit, both-engine integration, full vitest and `next build`.
- **Limits:**
  - at most **one heavy run at a time** (mutation runs count);
  - no new Docker database containers without approval.
- **Report:** say in the PR what ran locally and that CI covers the rest.

**The committed `vendor/` trap** (all three plugins). Each plugin commits `vendor/` because it *is* the deploy artifact, built `--no-dev`. Running PHPUnit or PHPStan locally needs `composer install` with the dev tools, and that rewrites a handful of **tracked** `vendor/composer/*` files.
- **Restore before committing:** `git checkout -- vendor && git clean -fd vendor`.
- **Never** run `composer dump-autoload` or `composer update` to "fix" anything, and never stage `vendor/` changes you didn't intend.
- A clean `git status` and working dev tools are mutually exclusive in one checkout. bcc-trust's `scripts/phpstan-local.sh` installs, runs and restores for you.
- Full explanation: `bcc-trust:docs/running-phpstan-locally.md`.

**Integration tests** (bcc-trust) need a throwaway MySQL database and PHP with gmp, pdo_sqlite and pdo_mysql. Point `PHPRC` at a php.ini that loads them: `-d extension=` does not reach PHPUnit's separate-process children. Print `memory_limit` in your log. They skip without a database; CI runs them with `--fail-on-skipped`.

**Mutation controls** (`bcc-trust:scripts/tests/MUTATION-CONTROLS.md`):
- Commit before mutating.
- For a guard with N conditions, write N scenarios, each isolating one condition.
- A surviving mutant means a **missing scenario**, not an equivalent mutant.
- `php -l` each mutant and require a real `FAILURES!` line.

**Never claim more than a bounded test showed.** State which conditions you exercised and which you didn't.

**Guards that scanned nothing.** The guards used to print PASS after scanning nothing. They now fail instead:
- bcc-trust's `arch-guardrails.sh` and `phpstan-all.sh` fail when they scan nothing;
- the umbrella guards exit 2 when the plugin trees are missing.

Still check that the output names what it scanned (e.g. "Scanning bcc-trust"). CI is the authority.

## 8. Worktrees and shared checkouts

- **The live plugin directories are not reliable `main` checkouts.** `app/public/wp-content/plugins/bcc-*` and `bcc-frontend/` are what the local WordPress site runs; another agent may have a branch checked out there. Build and review from a **worktree of `origin/main`**.
- **Where worktrees go:** under the **umbrella's** `.claude/worktrees/` (gitignored there), for every repo. For example: `git -C bcc-frontend worktree add ../.claude/worktrees/fe-<topic> -b <branch> origin/main`.
  - Only the umbrella ignores `.claude/worktrees/`. A worktree created inside a plugin or frontend directory shows up there as untracked content.
  - **Never put worktrees under `%TEMP%`.** Windows' automatic temp cleanup deletes files untouched for 7 days, worktrees included.
  - Before committing in an old worktree, check `git status` for unexpected ` D` lines.
- **`node_modules` junctions:** frontend worktrees may junction `node_modules` to a shared copy. **Never `git worktree remove --force` or `rm -rf` a tree containing a junction**, because both follow the junction and delete the shared target. Unlink first (`cmd //c rmdir <path>`).
- **Tests and PHPStan need the sibling plugin.** Trust and search unit tests and PHPStan load `../bcc-core`. A stale or missing sibling breaks dozens of tests; refresh it to `origin/main`.
- **Check the branch before every commit** in a shared checkout.

## 9. Tooling catalogue

The umbrella repo provides these for Claude Code under `.claude/`. Codex and other agents can run the underlying scripts directly.

| Kind | Name | What it does |
|---|---|---|
| Skill | `duplicate-scan` | the §11 scan (wraps the `duplicate-scanner` subagent) |
| Skill | `new-repository` | scaffolds a §1–§5-compliant repository class |
| Skill | `api-contract-guard` | checks REST and view-model changes against the contract |
| Skill | `frontend-feature` | scaffolds a frontend feature the house way |
| Skill | `code-cleanup` | dead-code, duplicate and performance sweep |
| Skill | `peepso-write-guard` | checks every `PeepSoGroupWriter::join` caller is gated |
| Skill | `ux-review` | reviews UI flows against V1's UX bars |
| Subagents | `duplicate-scanner`, `arch-guardrails-reviewer`, `frontend-reviewer` (read-only reviewers); `backend-implementer`, `frontend-implementer` | see [`.claude/AGENTS.md`](../../.claude/AGENTS.md) for multi-agent workflows |
| Hook (PreToolUse) | `block-protected-files.sh` | refuses edits to `vendor/`, `node_modules/`, lock files, `.env*` and TS build artifacts |
| Hooks (PostToolUse) | `php-lint.sh`, `ts-check.sh`, `eslint-frontend.sh`, `color-token-check.sh` | `php -l`; `tsc`; ESLint; the colour-token rule |
| Hook (UserPromptSubmit) | `section-11-reminder.sh` | reminds about §11 on new-code prompts |
| MCP | `context7` (library docs), `playwright` (browser smoke tests; see [`v1-smoke-test-checklist.md`](../v1-smoke-test-checklist.md)), `mysql` (read-only queries against the local database; **never widen to write access casually**), `github` (auth required), `sentry` | configured in `.mcp.json` |
| Umbrella scripts | `contract-parity-guard.php`, `subsystem-count-guard.php`, `schema-drift-guard.php`, `dead-file-scan.php`, `cadence-pressure-guard.sh` | blocking CI guards |
| Umbrella scripts | `site-url-guard.php`, `site-url-probe.sh`, `auth-cache-isolation-probe.sh` | environment-drift and cache probes (scheduled workflows) |
| Umbrella scripts | `bcc-query-floor-probe.php`, `perf/` | a temporary boot-query diagnostic; the k6 load-test harness (local only by default) |
| bcc-trust scripts | `arch-guardrails.sh`, `phpstan-local.sh`, `phpstan-all.sh`, `intent-guard.sh` (needs a live site), `verify-golden.sh` (needs a live site), `golden-staleness-guard.php`, `endpoint-exposure-guard.php`, `composer-classmap-guard.php`, `api-contract-check.sh` | in bcc-trust's own `scripts/` |

`ui-ux-pro-max` under `.claude/skills/` is a third-party, gitignored local skill, not a team skill.

## 10. Traps

These are known pitfalls that cost real time. Each one is followed by its fix.

**Shell and git**
1. `cmd | tail` reports tail's exit code. Redirect to a file, capture `$?`, and read the verdict line; a missing verdict line means no result.
2. `gh pr checks --watch` can exit 0 on a network error. Re-read with plain `gh pr checks`.
3. `git checkout -- <file>` restores from HEAD and silently wipes uncommitted work. Commit first; revert by naming a SHA.
4. Inserting code anchored on a function *signature* steals that function's docblock. Anchor on `/**`, then run full PHPStan, because errors surface at the callers.
5. Backslashes get mangled in heredocs and plain Python strings. Write patch scripts with a file tool, using raw strings.
6. Git Bash: `MSYS_NO_PATHCONV=1` is needed for some path arguments, and `ln -s` copies directories.

**PHP, PHPUnit and WordPress**
7. `-d extension=` doesn't reach separate-process children. A `PHPRC` pointing at a missing ini silently falls back to a 128M limit, and the full suite then dies without an OK line. Use a PHPRC ini; no OK line means no verdict.
8. bcc-search's `requireCore()` skips tests locally but fatals in CI. Compare the test and skip counts with CI.
9. File-level test stubs leak across the whole run, and `class_exists('X')` autoloads the real class. Require stubs in `setUp()`, use separate processes, and call `class_exists('X', false)`.
10. `wpdb::get_col()` never returns null; `get_row(null)` ignores `last_result`; `query()` returns 0 for successful DDL. Test `=== false`.
11. A failed `get_users()` lookup is cached, and `FOUND_ROWS` clears `last_error`. Pass `cache_results => false` and `count_total => false` where it matters.
12. `wp_cache_add` in a fallback makes a poisoned entry permanent. Use `add` on a true miss and `set` on poison.
13. The Redis drop-in returns its in-memory copy on a same-request re-read. Use `wp_cache_get($k, $g, true)`.
14. `admin-post.php` reads `$_REQUEST`, and PeepSo's input reads `$_GET` first. Pin request keys to neutral values; never unset them.
15. A PeepSo core/add-on version mismatch silently unregisters add-ons on web requests while WP-CLI looks healthy. Check version alignment first.
16. PeepSo comment rows share a module id with posts and carry no group id. Identify comments by `act_comment_object_id > 0`.

**Frontend**
17. A server component importing *anything* from a module that uses hooks fails only in `next build`. Keep shared helpers hook-free.
18. Vitest exits 1 on unhandled rejections even when every test passes. An expression-bodied `beforeEach(() => mock.mockReset())` returns a teardown that then gets called.
19. Count `next lint` errors with `grep "Error:"`; the summary line misleads.
20. TanStack `mutate()` per-call callbacks are dropped if the component unmounts before the mutation settles. Put durable effects in the hook's options.

**Engines and CI**
21. MySQL 8 and MariaDB differ (for example `DISTINCT` + `ORDER BY`), which is why CI runs both.
22. `GET_LOCK` is server-wide, so concurrent integration suites on one DB server collide.
23. An umbrella PR that conflicts on open gets no CI run, and `gh pr merge` will merge on a stale green check. Re-run after updating.
24. Plugin CI checks out sibling repos at their default-branch tip at run time. A trust PR that needs an unmerged core PR fails until core merges. A *re-run* reuses the original merge ref, so update the branch to pick up a newer `main`.
25. CORS: never add `Access-Control-Allow-Credentials`.
