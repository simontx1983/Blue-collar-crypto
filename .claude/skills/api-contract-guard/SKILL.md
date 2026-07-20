---
name: api-contract-guard
description: Verify REST endpoint changes under app/Domain/*/REST/ still conform to docs/api-contract-v1.md (envelope shape, error codes, view-model fields). Run before declaring any REST or view-model change "done." A contract break is P0 per §9.
---

# /api-contract-guard

Enforces §9 of [bcc-trust/CLAUDE.md](../../../app/public/wp-content/plugins/bcc-trust/CLAUDE.md): every response under `/wp-json/bcc/v1/` and `/wp-json/bcc-trust/v1/` conforms to [docs/api-contract-v1.md](../../../docs/api-contract-v1.md) §1.4–§1.5. Run this whenever an edit touches:

- `app/Domain/*/REST/*.php` (endpoint controllers)
- `app/Domain/*/Application/*ViewModel*.php` or `*Builder*.php` (view-model assembly)
- `bcc-frontend/src/lib/api/types.ts` (TypeScript contract types)
- [docs/api-contract-v1.md](../../../docs/api-contract-v1.md) itself

## When to invoke

- Claude has just edited a REST endpoint or view-model builder.
- Before opening a PR that touches the contract.
- When a frontend type breaks unexpectedly — usually means the backend shape drifted.

## Steps

### 1. Confirm the Envelope wrapper is in use

Every endpoint must return through [Envelope.php](../../../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/REST/Envelope.php). Check:

```bash
# In each changed REST file, the public callback returns Envelope::success/error.
grep -n "Envelope::" <changed-file>
```

A direct `return rest_ensure_response([...])` or `return new \WP_REST_Response(...)` bypassing the envelope is a §9 violation.

### 2. Run the static guardrails

```bash
cd app/public/wp-content/plugins/bcc-trust
bash scripts/arch-guardrails.sh bcc-trust --with-contract
```

`--with-contract` invokes [scripts/api-contract-check.sh](../../../app/public/wp-content/plugins/bcc-trust/scripts/api-contract-check.sh) against the live Local site and diffs the responses against the documented shapes. **Non-zero exit is a P0 — stop and report.**

### 3. Run PHPStan on the touched files

```bash
bash scripts/phpstan-all.sh bcc-trust
```

View-model field types must match the contract. PHPStan level 8 catches most drift; the contract check catches the rest.

### 4. Manual diff against the contract

For every view-model field added, removed, or renamed in this change:

- Look it up in [docs/api-contract-v1.md](../../../docs/api-contract-v1.md). If it isn't documented, the contract must be updated **in the same PR** — not later.
- Check the corresponding TS type in `bcc-frontend/src/lib/api/types.ts`. If the backend shape changed and the type didn't, the frontend will silently break at runtime.
- Required vs optional: a field that was always present cannot become conditionally absent without a contract version bump.

### 5. Cross-check the frontend consumers

```bash
# What components/hooks read the field you just changed?
grep -rn "presentation.<changed_field>\|<changed_field>" bcc-frontend/src
```

If the field disappeared and a frontend component still references it, fail loudly here rather than letting it ship.

## Hard rules

- **No silent contract changes.** If the response shape moved, [docs/api-contract-v1.md](../../../docs/api-contract-v1.md) and `bcc-frontend/src/lib/api/types.ts` must move with it, in the same change.
- **No view-model logic in the frontend.** The frontend rule from [code-cleanup](../code-cleanup/SKILL.md) Step 4 is the contract's other half — labels, tier classes, and trust strings are pre-computed server-side.
- **Error codes are part of the contract.** A new `WP_Error` code under a `bcc_*` prefix counts as an additive contract change and belongs in the contract doc.

## What this skill does NOT do

- It does not write code. It validates a change Claude already made.
- It does not replace the [arch-guardrails-reviewer](../../agents/arch-guardrails-reviewer.md) subagent — that agent enforces all of §1–§9. This skill zooms in on §9 specifically and is meant to be cheap to run mid-flow.
- It does not validate routes that aren't under `/wp-json/bcc/v1/` or `/wp-json/bcc-trust/v1/`.
