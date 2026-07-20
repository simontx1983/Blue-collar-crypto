# Documentation Cleanup — Final Execution Report

**Executed:** 2026-07-19 → 2026-07-20 (after PR #70 `docs/staging-readiness-2026-07-19` merged to umbrella main, per the approval's Phase-0 prerequisite) · **Companion:** [docs-audit-report-2026-07-19.md](docs-audit-report-2026-07-19.md) (the pre-cleanup audit this executes).
**Git posture:** nothing was staged, committed, pushed, merged, or deployed. All changes are working-tree only, awaiting operator commit.

---

## 1. Every file UPDATED (24 executed + 1 rewritten as part of relocation prep)

Umbrella `docs/`:
1. `docs/TODO.md` — ported implementation-audit §5 residue set (+ indexer-backfill medium) into Backend/Contract; added the performance-review canonical link under Performance/Ops (evidence-audit framing, no competing launch list); closed the stale media-CDN item (shipped 2026-07-17, bcc-frontend #45) into Recently shipped; repointed both `backend-security-pass` links to `archive/`.
2. `docs/capacity-model.md` — inline **SUPERSEDED** labels added at header L5 clause, global-assumptions "Redis warm"/"no Redis" rows, §5 totals, §6 (whole section), §9 formula + Redis memory row; repointed the dangling `GOLDEN_PATHS §5.6` citation to `scripts/bcc-query-floor-probe.php` + `scripts/perf/load-test.js` (no §5.6 exists). Measured-staging sections are now the unmistakable current truth.
3. `docs/deploy-runbook.md` — documented the environment→docroot split (staging `…/stage/wp-content/plugins/<plugin>/`, prod `…/wp-content/plugins/<plugin>/`, per `deploy.yml:57-61`) in "How it works" + the rsync-safety section.
4. `docs/rollback-procedure.md` — **minimal rewrite per approval**: status header now reflects staging stood-up/load-tested + prod frozen; §1 records deployed SHAs from Deploy workflow runs; §3 deploys via deploy-runbook; §4b is now "Backend (CI pipeline)" — redeploy last-good SHA via Actions dispatch, never `git checkout` on the server. DB (§2/§4c/§5/§6 drill) and Vercel (§4a) material preserved untouched.
5. `docs/ci-topology.md` — added the deploy layer (deploy.yml per plugin repo, staging auto/prod manual, → deploy-runbook) + `staging-cache-probe.yml` to the umbrella row; corrected bcc-search's row (it HAS ci.yml with PHPStan L8 + PHPUnit since 2026-07-08 — the "no CI of its own" claim was stale); updated the branch-protection note (bcc-search still unprotected — verified `gh api` 404 — but the stated reason no longer holds).
6. `docs/database-schema.md` — `wp_bcc_user_ranks` reclassified **RETIRED** at all 3 sites (inventory row L52, §detail heading, orphan-audit note), citing `drop-user-ranks-table.php` + contract v1.36; matches the `wp_bcc_trust_flags` precedent.
7. `docs/environment.md` — 3 read-site corrections: `BCC_OAUTH_BRIDGE_SECRET` → `Core/REST/Auth/OAuthController.php:159-160`; `BCC_ENCRYPTION_KEY` → `bcc-trust.php:150` (+166/185/646/1849); `BCC_INTERNAL_CRON_SECRET` → `IndexerTickEndpoint.php:150-151`.
8. `docs/testnet-deploy-checklist.md` — header date 2026-06-12 → 2026-07-19; §1.5 now leads with LSMCD-on-shared (Redis framed as the VPS/Agency path).
9. `docs/cadence-pressure-policy.md` — phrase table synced to the guard's 8 patterns (added `in the last N days/weeks/months`, `remind(er) to attest/…`, each behind allow-comments) + "the script wins" authority note; status header + section headings now state PR-11b/PR-11c **never landed** (stub = final policy, parked).
10. `docs/v2-roadmap.md` — header date → 2026-07-19 with drafting-date note.
11. `docs/dev-setup.md` — ghost plugin removed from both plugin lists; `peepso-core` corrected to the actual `peepso` + `peepso-*` folder set; retirement pointer to `bcc-core/src/PeepSo/`.
12. `docs/v1-smoke-test-checklist.md` — pre-flight plugin list: same fix.
13. `docs/GOLDEN_PATHS.md` — prereq list ghost fix; §3.1 grep dropped the nonexistent plugin path; §3.2 + §8.1 repointed from `holder-groups-reviewer` to the `peepso-write-guard` skill; §13.1 `bcc_trust_hourly_safety_recalc` → `bcc_trust_hourly_recalc` (code-verified); §14.4 test path → `tests/Unit/EnvelopeRecognitionTest.php`.
14. `docs/pattern-registry.md` — gettext-overrides entry marked RETIRED-with-plugin (no equivalent exists; rebuild guidance retained); NAMING.md dead link replaced (this section is now canonical); OptionsHelper note updated (class shell gone with plugin); **bonus fix found in validation**: `bcc_pull_meta`/`PullMetaRepository` → `bcc_watch_meta`/`WatchMetaRepository` (2026-06-28 watch rename had orphaned the link).
15. `docs/cron-registry.md` — ShadowPageSync row converted to annotated historical (~~struck~~, REMOVED-with-plugin, "no current equivalent hook found, verified 2026-07-19", per the approved annotate-don't-delete preference); **bonus fix found in validation**: `bcc_trust_async_endorsement_fraud_analysis` row likewise annotated (class deleted in the 2026-07-02 endorse cutover; canonical event name remains registered).
16. `docs/archive/README.md` — 6 new index rows (build-plan, 3 backend reports, audit report, this report).

Umbrella root + `.claude/`:
17. `README.md` — repo-model parenthetical corrected: plugins + frontend are separate gitignored sibling repos reassembled only in CI.
18. `CLAUDE.md` (root) — hooks section now lists all 6 wired hooks (added `eslint-frontend.sh`, `color-token-check.sh` with not-yet-committed caveat); guard list gained CI-blocking `dead-file-scan.php` + a one-line inventory of the non-load-bearing scripts; contract-parity note updated ("wired as blocking CI since Phase 2c" — it no longer says "safe to wire now"); `holder-groups-reviewer` bullet removed.
19. `.claude/AGENTS.md` — `holder-groups-reviewer` bullet removed (only stale item).
20. `.claude/skills/peepso-write-guard/SKILL.md` — ghost plugin path removed from the grep command.

Sub-repos:
21. `bcc-search/README.md` — Shortcodes section deleted (`[bcc_search]` removed in the 2026-05-03 headless cleanup; zero `add_shortcode` in the plugin); all **3** live routes now documented (`/search`, `/search/users`, `/search/groups` — re-verified against the ROUTE consts in the current working tree post-branch-churn).
22. `bcc-trust/CLAUDE.md` (local-only; backed up first) — M1.0–M1.5 narrative collapsed to a pointer at MIGRATED-FROM.md + `[M1.N]` commit history; `verify-golden.sh` added to Commands; **bonus fix found in validation**: both `docs/prompts/duplicate-scan.md` links were one directory-level short (pre-existing) — corrected to `../../../../../`.
23. `bcc-frontend/CLAUDE.md` (local-only; backed up first) — color-rule heading no longer claims local auto-block enforcement; now states the hook is umbrella-layout-only and not yet committed; the rule itself unchanged and binding.

Converted (Phase 4 — counted under the approved UPDATE-conversions):
24. `.claude/skills/code-cleanup.md` → `.claude/skills/code-cleanup/SKILL.md` — YAML frontmatter added; unique content preserved verbatim (Step 2 dead-code/duplicate heuristics, Step 3 scale-performance audit, the Logger/WP_Error bullets, Output-format + Severity scaffold); Steps 0/1/4 + duplicated Step 5 items trimmed to cross-references; ghost plugin dropped from the stack list; all links re-depthed to `../../../`.
25. `.claude/skills/ux-review.md` → `.claude/skills/ux-review/SKILL.md` — frontmatter added; V1 surface vocabulary preserved (minus the stale "3-ring binder iconography preserved" clause); **Endorse→Vouch** corrected as the primary verb (with retirement note) and `permissions.can_endorse` → `can_vouch`, self-endorse → self-vouch; Step 1 condensed to the §A2/§L5 cross-reference (unique `presentation.cta_state` detail + "bug is on the server" guidance kept); links re-depthed.

## 2. Every file RELOCATED (7)

| From | To | Banner |
|---|---|---|
| `docs/build-plan.md` | `docs/archive/build-plan-2026-06-28.md` | ARCHIVED — superseded by TODO.md; internal links re-depthed for the new location |
| `docs/backend-implementation-audit-2026-07-08.md` | `docs/archive/` | ARCHIVED — remediated; §5 items ported to TODO **before** the move (approval precondition met) |
| `docs/backend-bug-hunt-2026-07-09.md` | `docs/archive/` | ARCHIVED — all fixed/refuted |
| `docs/backend-security-pass-2026-07-09.md` | `docs/archive/` | ARCHIVED — fixes shipped; open items mirrored in TODO |
| `bcc-frontend/docs/comment-features-brief.md` | `bcc-frontend/docs/archive/` (new dir) | ARCHIVED completed plan + "Top sort shipped as most-stoked, not reply_count" correction |
| `bcc-frontend/docs/post-url-shortcode-brief.md` | `bcc-frontend/docs/archive/` | ARCHIVED + **DECISION INVERTED** banner (short_id shipped, Hashids rejected — contract v1.39) |
| `docs-audit-report.md` (umbrella root) | `docs/archive/docs-audit-report-2026-07-19.md` | Pre-cleanup-audit header pointing to this report |

Historical references **inside** archived documents were left untouched (e.g. `operational-audit-2026-05-13.md`'s holder-groups-reviewer mentions) — none masquerade as current instructions.

## 3. The one file DELETED

`.claude/agents/holder-groups-reviewer.md` — per its own retirement instruction; feature verified fully shipped (backend services + reconcile cron + 17 frontend files + contract §4.7.1). Superseded by the `peepso-write-guard` skill + `PeepSoGroupWriterJoinGuardTest` + GOLDEN_PATHS §8. Git-recoverable: tracked since 2026-05-06 (`git log -- .claude/agents/holder-groups-reviewer.md`, last SHA touching it: `79a7c24`). All 4 live references repaired before deletion; the two loose-skill removals were part of the approved conversions, not additional deletions.

## 4. Every repaired reference

| Reference | Repair |
|---|---|
| `CLAUDE.md` holder-groups-reviewer bullet | removed |
| `.claude/AGENTS.md:20` | removed |
| `docs/GOLDEN_PATHS.md` §3.2 + §8.1 (was L339/L620) | repointed to `peepso-write-guard` skill, retirement noted |
| `docs/TODO.md` ×2 → backend-security-pass | → `archive/backend-security-pass-2026-07-09.md` |
| `.claude/agents/frontend-implementer.md:9`, `frontend-reviewer.md:9` | → `../skills/code-cleanup/SKILL.md` |
| `.claude/skills/api-contract-guard/SKILL.md:71`, `frontend-feature/SKILL.md:8,71` | → `../code-cleanup/SKILL.md` |
| Ghost plugin in 8 live files (cron-registry, dev-setup ×2, GOLDEN_PATHS ×2, v1-smoke, pattern-registry ×3, peepso-write-guard, code-cleanup) | all removed or retirement-annotated; remaining doc mentions are retirement notes only |
| `docs/archive/build-plan-2026-06-28.md` internal links (8) | re-depthed for archive location |
| Bonus (found in validation, pre-existing breakage): pattern-registry `PullMetaRepository` link; cron-registry `EndorsementFraudAnalyzer` link; bcc-trust CLAUDE.md duplicate-scan links ×2 | fixed as described in §1 |

## 5. Exact validation results (Phase 7)

| Check | Result |
|---|---|
| `contract-parity-guard.php` | **PASS** ×2 (endpoints + exempt allowlist; 175 contract / 195 parsed routes) |
| `subsystem-count-guard.php` | **PASS** (27 subsystems — note: 27, not the audit-era 26; a subsystem landed with the staging-readiness/notification work) |
| `cadence-pressure-guard.sh` | **PASS**, exit 0 (new policy-table rows sit behind allow-comments and don't trip the 8 patterns) |
| `dead-file-scan.php` | exit 0 |
| Markdown links in all ~36 touched files | all resolve; the audit-report's forward link to this file resolves as of this write; 5 pre-existing breaks found → 4 fixed, 1 was the forward link |
| bcc-search routes vs README | 3/3 match ROUTE consts (re-verified after the other session's branch switch) |
| Ghost-plugin grep (live docs) | only retirement-annotation mentions remain (cron-registry, dev-setup, pattern-registry ×3) — zero live claims |
| `holder-groups-reviewer` grep | archive-historical + explicit "retired/replaced" notes only |
| Old relocated paths grep | zero live references |
| Skill registration | **pending fresh session** (known harness behavior: `.claude/skills` scanned at session start). Frontmatter matches the 5 working skills' format; both should appear as `/code-cleanup` and `/ux-review` next session — verify then. |
| No application source changed by this cleanup | **confirmed** — this cleanup touched only `.md` files (+ the two file deletions and directory moves listed above). The PHP modifications visible in the sub-repos belong to concurrent sessions (see §7). |

## 6. Deferred item status

`bcc-trust/CLAUDE.md` — **NOT deferred; executed.** The approval deferred it only while bcc-trust sat on `fix/reply-notify-comment-author`; at execution time that branch had merged (bcc-trust #92 → 7f67172 on main) and the tree was clean on main, so the edit proceeded after checksum-verifying the file against its backup. (bcc-trust has since been moved to another session's branch — irrelevant to CLAUDE.md, which is gitignored.) Backups (md5-verified) persist at scratchpad `claude-md-backups/bcc-trust-CLAUDE.md.2026-07-19.bak` + `bcc-frontend-CLAUDE.md.2026-07-19.bak` — retain until the operator is satisfied.

## 7. Unexpected differences from the approved audit

1. **Contract header lag (new, post-audit):** PR #71 added the v1.45 changelog entry (reply-notification routing) but left `docs/api-contract-v1.md:3` at "Draft v1.44". The contract is a KEEP file outside this cleanup's scope — **left untouched; needs a one-line header bump** in a contract-owned change.
2. **Shared-worktree churn during execution:** bcc-core → `feat/search-ft-index-metric` (modified `bcc-core.php`), bcc-search → `feat/search-conformance-hygiene` (8 modified PHP/test files), bcc-trust → `docs/fix-stale-user-search-comments` (2 modified PHP files) — all **other sessions' uncommitted work, untouched by me**. ⚠️ My `bcc-search/README.md` edit now rides uncommitted on that session's branch — whoever commits there should stage explicit paths (per the shared-worktree convention) or deliberately include the README fix.
3. **TODO's media-CDN item had already shipped** (bcc-frontend #45, 2026-07-17) — closed into Recently shipped rather than merely link-fixed.
4. **Two "§5 doc chores" from the audit summary were not ported to TODO** ("regenerate database-schema.md", "drop DORMANT search label") — they are not actually in the report's §5 text, and the schema regeneration demonstrably happened 2026-07-09. Nothing lost.
5. **bcc-search CI reality** exceeded the audit's note: it has full PHPStan L8 + PHPUnit CI (2026-07-08), not merely deploy+notify — ci-topology now says so.
6. **Pre-existing broken links** (4) discovered by validation and fixed — see §4 bonus row.
7. **`staging-cache-probe.yml` is now active on main** (merged with PR #70) — the weekly Authorization-cache guard (performance-review gate A4) is live config, no longer branch-only.

## 8. Remaining configuration gaps (unchanged — need separate implementation decisions)

1. `.claude/hooks/color-token-check.sh` untracked + `.claude/settings.json` modified-uncommitted — the color guard exists only in this worktree; commit both to make it a shared guarantee (CLAUDE.md files now state this honestly).
2. `.claude/settings.local.json` (repo root) — dead `plugins/bcc-trust-engine` allow-rule.
3. **NEW FIND:** `app/public/wp-content/.claude/settings.local.json` — stale allowlist containing the ghost-plugin path **and a plaintext admin credential inside an old curl allow-rule (`admin:passwrord1234`)**. Not a doc; untouched. Recommend deleting the file and rotating that password if it was ever real.
4. `bcc-trust/scripts/arch-guardrails.sh` + `phpstan-all.sh` — ghost plugin still in `ALL_PLUGINS` arrays (harmless no-op globs; script change, not doc).
5. `schema-drift-guard.php` not CI-armed; ci-topology still notes the arming step.
6. Self-expiring scripts pending their own cleanup: `bcc-query-floor-probe.php` ("delete after boot-floor verification" — that pass closed 2026-06-12), `wallet-case-preservation-check.php` (throwaway).
7. bcc-search branch protection — now possible (it has CI) but an open operator decision.

## 9. Git status + branch per repository (at report write)

| Repo | Branch | State |
|---|---|---|
| umbrella | `main` (f8da146) | Working-tree changes = exactly this cleanup (18 modified docs + 5 root/.claude modified + 6 deletions from moves/conversions/delete + 7 untracked adds in archive/ + 2 untracked skill dirs) **plus** pre-existing preserved: `M .claude/settings.json`, `?? .claude/hooks/color-token-check.sh` |
| bcc-core | `feat/search-ft-index-metric` | Another session: `M bcc-core.php` + vendor churn. **Untouched by cleanup.** |
| bcc-search | `feat/search-conformance-hygiene` | Another session: 8 modified PHP/test files + vendor churn; plus **my uncommitted `M README.md`** (the one cleanup change in this repo) |
| bcc-trust | `docs/fix-stale-user-search-comments` | Another session: 2 modified PHP files. Cleanup's CLAUDE.md edit is untracked/gitignored (invisible to git). |
| bcc-frontend | `main` | Cleanup: `D docs/comment-features-brief.md`, `D docs/post-url-shortcode-brief.md`, `?? docs/archive/`; preserved: `M package.json` (local TLS tweak — never commit) |

**Nothing was committed. Operator owns all commits/PRs** — suggested grouping: one umbrella docs PR; one bcc-frontend docs PR; the bcc-search README fix either as its own explicit-path commit or folded deliberately into the conformance branch; bcc-trust CLAUDE.md needs no git action (local-only).
