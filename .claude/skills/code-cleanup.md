# Skill: Code Cleanup

## Purpose

Procedure for finding and fixing code quality issues across the BCC stack
— `bcc-trust` (PHP, Domain-Driven), `bcc-core` (PHP, shared services),
`bcc-search`, `blue-collar-crypto-peepso-integration`, and `bcc-frontend`
(Next.js / TypeScript).

The architectural rules this skill enforces are documented in
[bcc-trust/CLAUDE.md](../app/public/wp-content/plugins/bcc-trust/CLAUDE.md).
Most of them are mechanically checked by
[scripts/arch-guardrails.sh](../app/public/wp-content/plugins/bcc-trust/scripts/arch-guardrails.sh)
and `scripts/phpstan-all.sh`. **Always run those first** — anything they
catch is non-negotiable. This skill catches the things they can't.

---

## When to Use

- Reviewing a PR or branch for quality issues
- Auditing a domain (e.g. "review all of `app/Domain/Disputes/`")
- Hunting dead code before a milestone cut
- Investigating a performance regression
- Pre-merge sweep before a phase closes

---

## Step 0 — Run the mechanical checks first

Before reading code by hand:

```bash
cd app/public/wp-content/plugins/bcc-trust
bash scripts/arch-guardrails.sh                # all plugins
bash scripts/arch-guardrails.sh bcc-trust      # one plugin (also runs php -l)
bash scripts/phpstan-all.sh bcc-trust          # PHPStan level 8
```

For the frontend:

```bash
cd bcc-frontend
npx tsc --noEmit
npx eslint .
```

If any of these fail, fix those first. The hand audit below assumes a
clean baseline.

---

## Step 1 — Layer-boundary audit (PHP)

The plugin uses bounded contexts inside one plugin:

- `app/Domain/Core/` — reputation, voting, scoring, fraud, read model
- `app/Domain/Disputes/` — dispute panel adjudication
- `app/Domain/Onchain/` — wallets, validator / NFT / builder / DAO signals

Each context follows the same internal layering:

```
Controllers / REST  →  Services  →  Repositories  →  $wpdb
                                ↘   DTO / ValueObjects
```

**Layer rules — violations are blockers:**

- [ ] `$wpdb` only appears in `app/Domain/*/Repositories/`,
  `app/Database/TableRegistry.php`, or
  `app/Security/TransactionManager.php`. Never in `Services/`,
  `Controllers/`, `Admin/`, `Integration/`, `templates/`, `blocks/`.
- [ ] Repositories use explicit column lists — no `SELECT *`. Columns
  typically live in `private const COLUMNS`.
- [ ] Every `SELECT` is bounded — `LIMIT`, unique-key filter, bounded
  `IN ()`, or aggregate (`COUNT`, `SUM`, `GROUP BY`).
- [ ] Templates and blocks receive data; they do not query.
- [ ] Cross-plugin calls go through `BCC\Core\ServiceLocator` — never
  direct `new` on classes from another plugin.
- [ ] Cross-plugin function calls use **positional args only**. Named
  parameters break compiled autoloads under `composer dump`.
- [ ] Cache invalidation uses generation counters
  (`wp_cache_incr( 'bcc_trust_gen_*' )`), not bulk `delete()`.
- [ ] No `@var` or `assert()` overrides to silence PHPStan — fix the
  types instead.

If `arch-guardrails.sh` passed but you still find a violation, add it
to the script as a new check.

---

## Step 2 — Duplicate / dead code

### Duplicates

- [ ] Two services doing the same thing under different names
  (common after sub-plugin merges — see `MIGRATED-FROM.md`)
- [ ] Two repositories querying the same table differently
- [ ] Two view-models computing the same derived field
- [ ] Same `class_exists( 'PeepSo\Core\... )' )` guard re-implemented
  inline instead of going through `bcc-core`'s `NullServices/`
- [ ] Same admin notice / cron registration pattern repeated across
  domains (should live in `app/Infrastructure/`)

### Dead code

- [ ] Public service methods with zero call sites
  (`Grep "MethodName"` across `app/`, `templates/`, `blocks/`,
  `bcc-frontend/`, sibling plugins)
- [ ] `add_action` / `add_filter` registrations for callbacks that no
  longer exist
- [ ] DB columns written but never read (or vice versa)
- [ ] Frontend hooks (`bcc-frontend/src/hooks/use*.ts`) with no
  importers
- [ ] Components in `bcc-frontend/src/components/` with no importers
- [ ] Commented-out blocks — git history is the archive
- [ ] Unused exports in `bcc-frontend/src/lib/api/`

**Resolution:** delete it. Do not deprecate, do not comment out.

---

## Step 3 — Performance audit

Bias toward the things that bite at 50k users / 500 concurrent reqs:

- [ ] N+1 in service methods that loop over a list and call a
  per-item repository — batch via `IN (?,?,?)` or a join
- [ ] Trust scores computed on read instead of read from
  `bcc_trust_page_scores` / `bcc_page_read_model`
- [ ] `get_option()` called inside loops — hoist to a static or pass in
- [ ] Missing index on a hot `WHERE` column — confirm with
  `EXPLAIN` against the schema in [docs/database-schema.md](../docs/database-schema.md)
- [ ] Frontend: a React Query hook fetching on mount that should be
  `enabled: false` until an interaction. Check
  `bcc-frontend/src/hooks/use*.ts` for missing `enabled` guards.
- [ ] Frontend: a card component that re-renders on every feed tick —
  look for missing `memo` / unstable callback props in
  `bcc-frontend/src/components/cards/`
- [ ] Frontend: a route in `app/` that renders client-side when SSR
  would cache better. Check whether `'use client'` is necessary.

---

## Step 4 — Frontend-specific checks (Next.js)

The architectural rule from [bcc-frontend/README.md](../bcc-frontend/README.md):
**no business logic in the frontend.** All trust scores, reputation
tiers, card tiers, ranks, permissions, and feature-access flags are
pre-computed by the API per §A2 / §L5.

- [ ] No `if (tier === 'elite') return 'Legendary'` style mappings —
  push to the server view-model builder
- [ ] No client-side trust-score arithmetic — the API provides
  `presentation.trust_label`, `presentation.tier_class`, etc.
- [ ] No `as any` outside `lib/api/types.ts` — fix the type or
  add it to the contract
- [ ] No direct `fetch()` outside `lib/api/client.ts` — the wrapper
  handles auth, envelope unwrapping, and error mapping
- [ ] Hooks return `{ data, isLoading, error }` shape; components
  don't construct their own loading state
- [ ] Reduced-motion: any animation respects
  `usePrefersReducedMotion()`. Falls back to a static state, not a
  shorter animation.

---

## Step 5 — Convention spot-checks

- [ ] PHP files declare `declare(strict_types=1);`
- [ ] PHPStan level 8 clean across touched files
- [ ] No `error_log()` in production paths — use
  `BCC\Core\Log\Logger`
- [ ] No raw `wp_die()` in REST controllers — return a
  `WP_Error` with the right `bcc_*` code
- [ ] TypeScript: no `console.log` left in
- [ ] No `any`, no `// @ts-ignore`, no `// eslint-disable-next-line`
  without an inline reason

---

## Output format

Report findings using this structure (one block per issue):

```
## Issue
Short name

### Severity
Critical / High / Medium / Low

### Location
file:line (clickable: [path](path#Lline))

### Explanation
What is wrong and why it matters.

### Risk
What can break in production. Reference an actual user flow if you can
(e.g. "blocks dispute panel vote casting").

### Recommended Fix
Concrete change. Include the file/function names. If it's a 1-line
edit, show the diff.

### Example (optional)
Code or SQL when useful.
```

After the per-issue blocks, end with a one-paragraph rollup:

```
## Summary
N critical, N high, N medium. Top theme: [e.g. "Repository layer leaking
into Disputes/Services"]. Recommended order of fixes: [...].
```

---

## Severity guidance

- **Critical** — production data loss, security, broken user flow,
  PHPStan/arch-guardrails violation, V1 smoke-test breaker
- **High** — performance bite at scale, layer-boundary violation,
  type safety hole that could mask a bug
- **Medium** — duplication, dead code, missed convention
- **Low** — cosmetic, unused imports, doc staleness

If you can't decide between two levels, pick the higher one. Reviewers
calibrate down faster than they calibrate up.
