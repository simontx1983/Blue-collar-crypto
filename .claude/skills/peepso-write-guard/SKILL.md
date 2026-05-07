---
name: peepso-write-guard
description: Verify that any new call to PeepSoGroupWriter::join (or direct PeepSoGroupUser::member_join) runs after a BCC server-side gate. PeepSoGroupWriter is intentionally a trusted-backend door that bypasses PeepSo's UI approval — calling it without a preceding gate check lands users in closed/secret groups they should never reach.
---

# /peepso-write-guard

Enforces the rule documented inline in [PeepSoGroupWriter.php](../../../app/public/wp-content/plugins/bcc-core/src/PeepSo/PeepSoGroupWriter.php):

> "Privacy semantics: `PeepSoGroupUser::member_join` writes `gm_user_status = 'member'` unconditionally — it does NOT branch on `is_closed` / `is_secret`. … This wrapper is the trusted-backend door — it bypasses PeepSo's UI gating, which is exactly what BCC's own server-side gates (Locals geofence, NFT-holder gate) need."

PeepSoGroupWriter is the right tool **after** BCC has decided the user is allowed in. Calling it without that decision lands users as full members of closed or secret groups regardless of privacy setting. This caused a holder-group footgun in v1.5.

Run this whenever an edit adds, moves, or modifies a call to:

- `PeepSoGroupWriter::join(`
- `PeepSoGroupWriter::leave(`
- `PeepSoGroupUser::member_join(` (the underlying primitive)

## When to invoke

- Claude has just edited a file under `app/Domain/*/Services/`, `app/Domain/*/REST/`, or `app/Domain/*/Application/`.
- Claude added a new shortcode, AJAX handler, REST endpoint, cron job, or admin-side helper that joins users to PeepSo groups.
- A reviewer flagged "where did this membership come from?" in a PR.

## Steps

### 1. Locate every call site touched by the change

```bash
# Find every call site in BCC code (peepso-pages/peepso-groups are PeepSo's own
# code and intentionally excluded — we don't audit PeepSo).
grep -rn "PeepSoGroupWriter::\|->member_join(" \
  app/public/wp-content/plugins/bcc-core \
  app/public/wp-content/plugins/bcc-trust \
  app/public/wp-content/plugins/bcc-search \
  app/public/wp-content/plugins/blue-collar-crypto-peepso-integration
```

The wrapper itself (`bcc-core/src/PeepSo/PeepSoGroupWriter.php`) is the only legitimate place that *names* `member_join()` directly — it is the primitive. Everywhere else should call `PeepSoGroupWriter::join(...)`.

### 2. For each call site, verify it sits behind a gate

A "gate" is an explicit BCC authorization check that runs **before** the join. The legitimate gates today:

| Gate | Where it lives |
|------|---------------|
| **Locals geofence** | `bcc-trust/app/Domain/Core/Services/LocalsService.php` (city/region match against the user's profile) |
| **NFT-holder gate** | `bcc-trust/app/Domain/Onchain/Services/NftGroupGateService.php` (verifies wallet ownership of qualifying NFT) |
| **Holder Groups gate** | `bcc-trust/app/Domain/Onchain/REST/HolderGroupsEndpoint.php` flow (delegates to NftGroupGateService) |

For each new or moved call site, read 30 lines above the `PeepSoGroupWriter::join(...)` call and confirm at least one of:

- A call to a `*GateService::canJoin($userId, $groupId)` (or equivalent) that returns true / throws, **and the join is in the success branch**.
- A call to a `*Service` method whose name contains `gate`, `verify`, `authorize`, `approve`, or `eligibility`, with the join inside its true branch.
- The join is itself the body of a method on a `*GateService` class (it is the gate enforcement boundary).

If none of those hold — the call is **bypassing the gate** and is a violation.

### 3. Reject these locations outright

A direct `PeepSoGroupWriter::join(...)` MUST NOT appear in any of these contexts. They are violation-by-construction:

- `add_action(...)` callbacks for non-gate hooks (e.g. `user_register`, `wp_login`, `peepso_user_*` hooks): these fire on lifecycle events, not on a gate decision.
- Shortcode handlers (any `add_shortcode(...)` body).
- AJAX endpoints under `wp-admin/admin-ajax.php` that don't go through a `*GateService`.
- Cron jobs or scheduled tasks that backfill membership without re-checking eligibility.
- WP-CLI commands that join users in bulk without per-user gate evaluation.
- Admin-side helpers for "fix membership" buttons.

If the use case is "the user already passed the gate yesterday and we're just re-syncing", the answer is still to call the gate — eligibility is a runtime fact (NFT could be sold, region could change) and stale approval is its own bug class.

### 4. Cross-check the leave path

`PeepSoGroupWriter::leave(...)` is similarly trusted. Most leave call sites are safe (removing a user is fail-safe in the closed-group case), but one anti-pattern to flag:

- `leave()` called on the **owner** of a group: the wrapper itself refuses this, but a caller that ignores the `false` return value and proceeds will silently corrupt the group's owner state. Confirm any new `leave()` call site checks the return value.

### 5. Report

Output, per call site, one of:

- ✅ `path/to/File.php:NN — calls PeepSoGroupWriter::join() in <gate-service> after <gate-check>. Compliant.`
- ❌ `path/to/File.php:NN — calls PeepSoGroupWriter::join() with no preceding gate. Violation. Remediation: route through <suggested-service> or add an explicit gate.`

Block on any ❌. The wrapper ships only the trusted-backend door; the gates are the lock and they live in BCC code.

## Hard rules

- **PeepSoGroupWriter is a private API for service-layer code.** Controllers, hooks, shortcodes, cron, CLI, and admin code do not call it directly.
- **`->member_join()` on a raw `PeepSoGroupUser` is always a violation outside `PeepSoGroupWriter.php` itself.** PeepSo's primitive is what the wrapper protects against — naming it directly skips both the wrapper's counter-cache update and any future safeguards added there.
- **Eligibility is runtime, not historical.** Re-sync, restore, and migration jobs must re-run the gate per user. There is no "trusted historical approval" exception.

## What this skill does NOT do

- It does not write code. It validates a change Claude (or a human) already made.
- It does not audit the gate services themselves — that's [arch-guardrails-reviewer](../../agents/arch-guardrails-reviewer.md)'s job (Repository discipline, $wpdb access, etc.).
- It does not check PeepSo's own plugin code (`peepso-groups/`, `peepso-pages/`). Those are vendor and intentionally out of scope.
