# GOLDEN_PATHS.md — BCC Operational Verification Doctrine

**Status:** living document. Last verified end-to-end on 2026-05-13.
**Owner:** anyone with operator access. **Do not bypass — do not gloss over a section just because "we know it works."**

This file is the canonical, evergreen runbook for verifying every operationally-load-bearing path on the Blue Collar Crypto platform. It exists so that any contributor — present or future — can, without tribal knowledge:

- confirm a single subsystem is healthy on this install
- diagnose where a failure lives when something breaks
- prove to themselves the platform is operating as designed before approving a release

This is **not** a tutorial. Each section assumes you can read PHP/TypeScript and have shell access. This is **not** a regression suite. It is the smallest set of checks that, taken together, exercise the load-bearing seams.

If a procedure in this document fails on the current branch and you cannot explain why, **stop and ask before proceeding.** A failed procedure is a real signal, not a flake.

---

## Prerequisites

- **WordPress + bcc-trust running.** Local-by-Flywheel site at `http://blue-collar-crypto-custom.local`. `wp-admin` loads. `bcc-core`, `bcc-trust`, `blue-collar-crypto-peepso-integration`, `peepso-core` all active.
- **Next.js dev server running.** `cd bcc-frontend && npm run dev` → reachable at `http://localhost:3000`.
- **`BCC_FRONTEND_ORIGIN` set** in `wp-config.php` to `http://localhost:3000`.
- **At least one test user** signed up + onboarded.
- **wp-cli reachable.** Local-by-Flywheel ships wp-cli.phar under its Studio data dir. The portable form below works from any working tree:

  ```bash
  # macOS / Linux: WP-CLI is usually on PATH already.
  wp eval 'echo "ok\n";'

  # Windows / Local-by-Flywheel: bundle path varies per install. The
  # canonical pattern (verified 2026-05-13 on this dev box) is:
  PHP="<lightning-services>/php-8.2.30+1/bin/win64/php.exe"
  PHPINI="<Local-run-conf>/conf/php/php.ini"
  WPCLI="<Studio/server-files>/wp-cli.phar"
  "$PHP" -c "$PHPINI" -d mysqli.default_port=10014 "$WPCLI" eval 'echo "ok\n";'
  ```

  If running into "No such file or directory," the Local paths have changed (Local rotates the conf-dir hash). Use:
  - `find "$HOME/AppData/Roaming/Local" -name "wp-cli.phar"` (Windows)
  - `find "$HOME/Library/Application Support/Local" -name "wp-cli.phar"` (macOS)
  - The MySQL port is per-site and lives in Local's site UI. Pass via `-d mysqli.default_port=<port>`.

  Throughout this document, commands are written as `wp eval '...'` for brevity. Substitute the full PHP+phar invocation if running on Windows.

- **Background commands are not OK in this runbook.** Every check is synchronous so you can see the result before moving on.

---

## Section 1 — Auth Critical Path

**What this verifies.** The signup → JWT mint → bridge into NextAuth session → Bearer-token-protected REST works end-to-end. This is the load-bearing seam between the WordPress origin and the headless SPA.

**Constitutional law verified:** §I (WordPress is the user-account primitive), §IV.12 (NextAuth is the SPA's only session manager), §VI.22 (`bcc-core/src/Crypto/*` is the only signature verifier).

### 1.1 Sanity-check the JWT TTL alignment

```bash
wp eval '
$ttl = \BCC\Trust\Core\Support\JwtToken::TTL_SECONDS;
echo "JWT TTL_SECONDS = $ttl ( " . round($ttl / 86400, 1) . " days)" . PHP_EOL;
echo "Expected: 604800 (7 days) for V1." . PHP_EOL;
'
```

**Expected:** `JWT TTL_SECONDS = 604800 (7.0 days)`.

**Failure means:** Someone tightened or relaxed the JWT lifetime without filing a refresh-flow plan. Per `AuthEndpoint::JWT_TTL_SECONDS` docblock, tightening requires (a) `/auth/refresh` endpoint, (b) NextAuth silent-refresh, (c) rotating refresh-token store. None of those exist in V1.

### 1.2 Sign in via the SPA

Browser steps:

1. Open `http://localhost:3000/login` in a private/incognito window.
2. Submit email + password for a test account.
3. Confirm redirect to `/` (the Floor) and the header shows the operator handle.

**Expected:** `POST /api/auth/callback/credentials` returns 200; `getSession()` returns `{ user, bccToken: <non-empty>, bccTokenExpiresAt: <number ms epoch> }`.

**Failure means:** Either `/auth/login` is broken, `JwtToken::encode` is failing, or the NextAuth credentials provider's `authorize()` callback is shape-mismatched against `AuthTokenResponse`. Investigate `bcc-frontend/src/lib/auth.ts:47-114` and `app/Domain/Core/REST/AuthEndpoint.php`'s login handler.

### 1.3 Confirm token expiry blanks correctly

Run in browser devtools after sign-in:

```js
const s = await (await fetch('/api/auth/session', { credentials: 'include' })).json();
const remainingMs = (s.bccTokenExpiresAt ?? 0) - Date.now();
const remainingDays = remainingMs / 86400000;
console.log({ remainingDays, bccTokenIsEmpty: s.bccToken === '' });
```

**Expected:** `remainingDays ≈ 7.0`, `bccTokenIsEmpty: false`.

**Failure means:** NextAuth's `jwt` callback (`bcc-frontend/src/lib/auth.ts:215-220`) blanked the token. That is correct behavior IF `bccTokenExpiresAt` has actually passed. If `bccTokenIsEmpty: true` and `remainingDays > 0`, the expiry math diverged.

### 1.4 Bearer auth round-trip

```js
const s = await (await fetch('/api/auth/session', { credentials: 'include' })).json();
const r = await fetch('http://blue-collar-crypto-custom.local/wp-json/bcc/v1/me/account/email', {
  method: 'PATCH',
  headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${s.bccToken}` },
  body: JSON.stringify({ email: 'unused@example.com', current_password: 'definitely_wrong' }),
});
console.log({ status: r.status, body: await r.json() });
```

**Expected:** `status: 422`, `body.error.code: 'bcc_invalid_request'` (the current-password gate rejected the wrong password — that's success: it means auth was accepted, request was routed, and `verifyCurrentPassword` ran).

**Failure means:**
- `401` → JWT not accepted. Check `BearerAuth` middleware + `wp_salt('auth')` configured.
- `405` → route not registered. Plugin not loaded?
- `404` → namespace/path drift.
- 500 with `bcc_unknown` → uncaught PHP exception in the endpoint pipeline.

**Recovery:** see Section 1.1 / 1.2; if the JWT bridge is broken, fall back to logging in via the SPA and re-running the test.

---

## Section 2 — Account Security Flows

**What this verifies.** Credential rotation (email/password/delete) is rate-limited, audit-logged, AND emits a side-channel security email. This is the most security-relevant surface on the platform.

**Constitutional law verified:** §VIII.30 (audit logging never breaks the mutation path), Pattern-registry §"Destructive mutation hardening" invariant 5 (side-channel security email).

### 2.1 Email change — full forensic chain

Browser: navigate to `http://localhost:3000/settings/account`, fill the Change Email form with a valid new email + the test user's current password, click Save email.

Then inspect the trail:

```bash
wp eval '
global $wpdb;

// 1) Audit row landed?
$row = $wpdb->get_row("SELECT id, user_id, action, created_at FROM {$wpdb->prefix}bcc_trust_activity WHERE action = \"account_email_changed\" ORDER BY id DESC LIMIT 1");
echo "Latest account_email_changed: " . ($row ? "id={$row->id} user={$row->user_id} at={$row->created_at}" : "(none)") . PHP_EOL;

// 2) wp_users actually updated?
if ($row) {
    $u = get_userdata((int) $row->user_id);
    echo "Current user_email: " . ($u ? $u->user_email : "(user gone)") . PHP_EOL;
}

// 3) Mail subsystem health (no degradation events = success)
$dm = apply_filters("bcc_system_health", []);
$ev = $dm["degradation_metrics"]["subsystems"]["account_security_mail"]["email_changed_send_failed"]["current"] ?? 0;
echo "email_changed_send_failed in current bucket: $ev" . PHP_EOL;
'
```

**Expected:**
- `Latest account_email_changed: id=N user=U at=YYYY-...` matching the click within the last minute.
- `Current user_email:` matches the new email.
- `email_changed_send_failed in current bucket: 0`.

**Failure means:**
- Missing audit row → `AuditLogger::log` failed silently (check `DegradationMetrics::record('audit_log_swallow', 'log_write_failed')` count — see Section 4).
- Stale `user_email` → `wp_update_user` returned WP_Error and the endpoint surfaced it without rolling back; check the response body of the PATCH.
- `email_changed_send_failed > 0` → `wp_mail` is failing. Check Local's Mailpit UI (typically `http://localhost:<mailpit-port>`) or `wp-content/bcc-logs/`.

**Authoritative artifacts:**
- `wp_bcc_trust_activity` row (canonical accountability)
- `wp_users.user_email` (canonical user state)
- Mailpit inboxes for both old + new addresses (canonical canary)

**Rollback / recovery:**
- If the audit row landed but the email didn't, `wp user update <id> --user_email=<old>` restores the user; the audit row records the intent and is forensically preserved.
- If the email landed but the audit row didn't, this is a §VIII.30 alignment issue — investigate via Section 4.

### 2.2 Password change

Same forensic shape:

```bash
wp eval '
global $wpdb;
$row = $wpdb->get_row("SELECT id, user_id, created_at FROM {$wpdb->prefix}bcc_trust_activity WHERE action = \"account_password_changed\" ORDER BY id DESC LIMIT 1");
echo "Latest account_password_changed: " . ($row ? "id={$row->id} user={$row->user_id} at={$row->created_at}" : "(none)") . PHP_EOL;
'
```

After a Save Password click, expect a fresh row with `created_at` matching the click.

**Failure means:** same diagnostic flow as 2.1.

### 2.3 Account deletion — capture-before-delete invariant

The mailer requires `email + display_name` captured BEFORE `wp_delete_user` because `get_userdata` returns false after deletion. Verify the capture by running the dev forensic test below, NOT by deleting a real account.

```bash
wp eval '
$rc = new ReflectionMethod("\\BCC\\Trust\\Core\\REST\\MyAccountEndpoint", "deleteAccount");
$src = file($rc->getFileName());
$body = implode("", array_slice($src, $rc->getStartLine() - 1, $rc->getEndLine() - $rc->getStartLine() + 1));
$captureBeforeDelete = (strpos($body, "get_userdata(\$userId)") !== false && strpos($body, "wp_delete_user(\$userId)") !== false);
$idx_get = strpos($body, "get_userdata(\$userId)");
$idx_del = strpos($body, "wp_delete_user(\$userId)");
echo "get_userdata before wp_delete_user: " . ($idx_get < $idx_del ? "YES" : "NO") . PHP_EOL;
'
```

**Expected:** `YES`.

**Failure means:** Someone reordered the deletion path. The mailer cannot send the canary email if the user record is gone before capture. This is a security regression — STOP and revert.

---

## Section 3 — Activity / Floor Write Path

**What this verifies.** The PeepSo write boundary (`bcc-core/src/PeepSo/*Writer.php`) is the only legal path for `peepso_*` table mutations.

**Constitutional law verified:** §II.6 (single graph rule), §II.7 (`PeepSoGroupWriter::join` bypasses approval — server-side gate required).

### 3.1 Confirm the boundary is intact

```bash
# Find any non-Writer file in bcc-* plugins that writes to peepso_* tables.
# Should return ZERO results.
grep -rn --include='*.php' -E 'wpdb->(insert|update|delete|query|prepare).*peepso_' \
  "app/public/wp-content/plugins/bcc-core/src" \
  "app/public/wp-content/plugins/bcc-trust/app" \
  "app/public/wp-content/plugins/bcc-search/app" \
  "app/public/wp-content/plugins/blue-collar-crypto-peepso-integration/app" \
  | grep -v 'PeepSo[A-Z][a-zA-Z]*Writer\.php' \
  | grep -v 'src/Repositories/PeepSo' \
  | grep -v 'app/Repositories/PeepSoCategoryRepository\.php'
```

**Expected:** No output.

**Failure means:** Someone has introduced a direct `peepso_*` mutation outside the canonical writer adapter. This is §II.6 violation. Investigate the offending file; the mutation must move into the appropriate `bcc-core/src/PeepSo/*Writer.php` adapter.

### 3.2 Confirm `PeepSoGroupWriter::join` callers are gated

For every caller of `PeepSoGroupWriter::join`, the call MUST be preceded by a server-side gate (eligibility check, privacy check, or trusted-backend invocation).

```bash
grep -rn "PeepSoGroupWriter::join" "app/public/wp-content/plugins/bcc-trust/app" "app/public/wp-content/plugins/bcc-core/src"
```

For each result, read the file at the cited line and confirm an explicit gate precedes the call. Known canonical gates:

- `NftGroupGateService::joinIfEligible()` — eligibility check, opt-out, balance, chain → then `::join` (verified 2026-05-13).
- `MyGroupsEndpoint::postJoin` — privacy=open check + closed/secret rejection → then `::join`.
- `LocalsService::setPrimary` — Locals are intentionally open-membership.

**Failure means:** A new code path is calling `PeepSoGroupWriter::join` without a gate. This is the most dangerous regression in the platform. Stop, revert, and invoke the `holder-groups-reviewer` agent.

### 3.3 Status post round-trip

Browser:

1. Compose a status post via the Floor's composer.
2. Submit.

```bash
wp eval '
global $wpdb;
$row = $wpdb->get_row("SELECT id, act_user_id, act_external_id, act_module_id, act_app_id, last_act_time FROM {$wpdb->prefix}peepso_activities ORDER BY id DESC LIMIT 1");
echo "Latest peepso_activity: id={$row->id} user={$row->act_user_id} module={$row->act_module_id} at={$row->last_act_time}" . PHP_EOL;
'
```

**Expected:** A row matching the submit, `act_user_id` = your user id, `last_act_time` within the last minute.

**Failure means:** `PeepSoStatusWriter` returned 0 (insert failed) or PeepSo is not loaded. If the writer recorded `peepso_absence` via DegradationMetric, see Section 4. If not, check `wp-content/debug.log` for `[bcc-core] PeepSo not loaded` warnings.

---

## Section 4 — Audit Persistence Verification

**What this verifies.** Every destructive Next.js mutation lands a row in `bcc_trust_activity` on real state transitions, and the §VIII.30 swallow surfaces failures via `DegradationMetric`.

**Constitutional law verified:** §VIII.30 (audit-log writes must never break the mutation path; failures observable via /system/health).

### 4.1 Inventory of audit actions

```bash
wp eval '
global $wpdb;
$rows = $wpdb->get_results("SELECT action, COUNT(*) c, MAX(created_at) latest FROM {$wpdb->prefix}bcc_trust_activity WHERE action IN (
  \"vote_cast\", \"page_flag_created\", \"page_flag_removed\",
  \"holder_group_join\", \"holder_group_leave\", \"holder_group_auto_reconciled\",
  \"account_email_changed\", \"account_password_changed\", \"account_deleted\",
  \"user_blocked\", \"user_unblocked\",
  \"wallet_linked\", \"wallet_unlinked\",
  \"dispute_submitted\", \"dispute_panel_vote_cast\",
  \"group_join\", \"group_leave\"
) GROUP BY action ORDER BY latest DESC");
foreach ($rows as $r) printf("  %-30s  count=%d  latest=%s\n", $r->action, $r->c, $r->latest);
'
```

**Expected:** All 17 actions appear at least once across the platform's lifetime (after at least one end-to-end smoke).

**Failure means:** A destructive endpoint failed to emit its audit row. Either:
- The endpoint is not on a tagged Tier 1A–1D path (verify against `pattern-registry.md §"Destructive mutation hardening"`).
- The endpoint emits the row on a code path the integration test didn't exercise.
- The `AuditLogger::log` call was deleted.

### 4.2 Verify the §VIII.30 swallow is observable

```bash
wp eval '
$dm = apply_filters("bcc_system_health", []);
$subs = $dm["degradation_metrics"]["subsystems"]["audit_log_swallow"] ?? null;
if ($subs === null) { echo "audit_log_swallow taxonomy NOT REGISTERED" . PHP_EOL; exit(1); }
foreach (["score_mutation_before_snapshot", "discovery_owner_verified_status", "leaderboard_owner_fallback", "log_write_failed"] as $event) {
  $known = isset($subs[$event]) ? "YES" : "MISSING";
  echo "  $event: $known" . PHP_EOL;
}
'
```

**Expected:** All four events present.

**Failure means:** The canonical-subsystem map in `bcc-core/bcc-core.php` is missing a taxonomy entry. New audit-swallow surfaces must register here — the map is the only place future agents discover which events are even possible.

### 4.3 Force a write failure (DEVELOPMENT ONLY)

To prove the swallow surfaces a DegradationMetric without an audit row, temporarily rename `bcc_trust_activity` and fire any audited mutation, then:

```bash
wp eval '
$dm = apply_filters("bcc_system_health", []);
echo "log_write_failed current: " . ($dm["degradation_metrics"]["subsystems"]["audit_log_swallow"]["log_write_failed"]["current"] ?? 0) . PHP_EOL;
'
```

**Expected after the forced failure:** `log_write_failed current: > 0`.

Restore the table immediately after the test. The mutation completed successfully despite the lost audit row — that's §VIII.30 working as designed.

---

## Section 5 — Rate-Limit Verification

**What this verifies.** Every destructive mutation endpoint has a per-user `Throttle::allow` gate, the gate denies after the configured ceiling, and the SPA renders contextual `bcc_rate_limited` copy.

**Constitutional law verified:** Pattern-registry §"Destructive mutation hardening" invariant 2 (Throttle BEFORE any credential gate).

### 5.1 In-process Throttle smoke

```bash
wp eval '
wp_set_current_user(<your_test_user_id>);
$results = [];
for ($i = 0; $i < 7; $i++) {
  $results[] = \BCC\Core\Security\Throttle::allow("smoke_test:" . get_current_user_id(), 5, 60) ? "allow" : "deny";
}
echo implode(", ", $results) . PHP_EOL;
'
```

**Expected:** `allow, allow, allow, allow, allow, deny, deny`.

**Failure means:**
- All seven `allow` → Throttle backend is fail-open. Check `bcc_system_health`'s `throttle` block; `rate_limiter_ready` should be 1 and `backend` should be `trust_engine` (or `object_cache` if Redis is wired).
- All seven `deny` → backend is fail-closed (no cache + no RateLimiter). The `degraded` flag in the health block should be true.
- Anything in between → likely a bucket-key collision; investigate.

### 5.2 Live HTTP Throttle drain — the verified browser-eval recipe

This is the proven recipe for tripping a Throttle gate during end-to-end testing. Playwright clicks are too slow (each click takes 3-5s, and the 60s sliding-window resets too often to drain via UI clicks).

Sign in to the SPA, open devtools console at any page, paste:

```js
(async () => {
  const s = await (await fetch('/api/auth/session', { credentials: 'include' })).json();
  const wp = 'http://blue-collar-crypto-custom.local';
  const t0 = Date.now();
  const out = await Promise.all(Array.from({ length: 8 }).map((_, i) =>
    fetch(`${wp}/wp-json/bcc/v1/me/account/email`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${s.bccToken}` },
      body: JSON.stringify({ email: 'unused@example.com', current_password: 'definitely_wrong' }),
    }).then(async r => ({ i, status: r.status, code: (await r.json())?.error?.code ?? null }))
  ));
  console.table(out);
  console.log('elapsedMs', Date.now() - t0);
})();
```

**Expected:** A mix of `422 / bcc_invalid_request` and `429 / bcc_rate_limited`. The 429s start once the per-user 5/60s bucket fills. Verified 2026-05-13: 4×422 then 4×429 in 657 ms.

**Failure means:**
- No 429s at all → Throttle is fail-open, OR the bucket key for `account_email:$uid` is differing between HTTP and CLI contexts.
- All 429s → bucket pre-loaded from a prior drain; wait 60 seconds and re-run.

### 5.3 Contextual copy renders for `bcc_rate_limited`

After 5.2 drains the bucket, click the in-SPA Save Email button once more. The inline alert should read **"Too many attempts. Wait a minute and try again."** (literal — that copy lives in `AccountSection.tsx`'s `ERROR_COPY` map).

**Failure means:** The component's `ERROR_COPY` map for `bcc_rate_limited` was removed or changed. Refer to commit `13deb24` for the canonical map.

### 5.4 Endpoint coverage inventory

```bash
grep -rn "Throttle::allow" "app/public/wp-content/plugins/bcc-trust/app/Domain" \
  | grep -v 'tests/' \
  | awk -F: '{ print $1 }' | sort -u
```

Every destructive mutation endpoint should appear in this list. Cross-reference against pattern-registry §"Destructive mutation hardening" — any endpoint not listed needs investigation.

---

## Section 6 — Mail Subsystem Verification

**What this verifies.** `wp_mail` is reachable, dispute notifications route correctly, and the AccountSecurityMailer side-channel emails fire on credential changes.

### 6.1 Confirm a sender identity

```bash
wp eval '
echo "admin_email: " . get_option("admin_email") . PHP_EOL;
echo "blogname: " . get_bloginfo("name") . PHP_EOL;
'
```

**Expected:** A real address + a real site name. AccountSecurityMailer's `From:` header is composed from these (`fromHeader()` in `AccountSecurityMailer.php`).

**Failure means:** The site's identity is unconfigured. Security emails will go out with an unrecognizable sender, increasing the chance of being marked spam.

### 6.2 Mailpit reachable (Local-by-Flywheel)

Local-by-Flywheel ships Mailpit as the SMTP capture. Find its port via the Local UI (Site → Tools → Mailpit), then:

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:<mailpit-port>
```

**Expected:** `200`.

### 6.3 Fire a real security email

Trigger an email change via the SPA (Section 2.1), then check Mailpit. Expect TWO emails:

- To OLD address: subject `[<site_name>] Your account email was changed`, body containing "AWAY from this address" + "if you did NOT make this change."
- To NEW address: subject `[<site_name>] Email change confirmed`.

**Failure means:**
- No emails → check `account_security_mail.email_changed_send_failed` DegradationMetric (Section 6.4).
- Only one email → one of the two `send()` calls failed silently; check `bcc-logs/`. The two sends are independent by design — one failure doesn't block the other.

### 6.4 Mailer health snapshot

```bash
wp eval '
$dm = apply_filters("bcc_system_health", []);
$subs = $dm["degradation_metrics"]["subsystems"]["account_security_mail"] ?? null;
if ($subs === null) { echo "account_security_mail taxonomy NOT REGISTERED" . PHP_EOL; exit(1); }
foreach (["email_changed_send_failed", "password_changed_send_failed", "account_deleted_send_failed", "wallet_linked_send_failed", "wallet_unlinked_send_failed"] as $ev) {
  $cur = $subs[$ev]["current"] ?? 0;
  $prev = $subs[$ev]["previous"] ?? 0;
  echo "  $ev: cur=$cur prev=$prev" . PHP_EOL;
}
'
```

**Expected:** All zero in steady state.

**Failure means:** Sustained nonzero on any event = a P1 alert per the bcc-core/bcc-core.php taxonomy comment. The user who was supposed to receive a canary signal did not, and only the audit_log row remains. Investigate `wp_mail` / SMTP / Mailpit configuration immediately.

---

## Section 7 — Wallet-Link Lifecycle

**What this verifies.** Wallet challenge → signature → link → unlink works end-to-end, with audit + security email + DegradationMetric coverage.

**Constitutional law verified:** §VI.22 (bcc-core/src/Crypto is the only signature verifier).

### 7.1 Challenge round-trip

Browser console (signed in):

```js
const s = await (await fetch('/api/auth/session', { credentials: 'include' })).json();
const r = await fetch('http://blue-collar-crypto-custom.local/wp-json/bcc/v1/auth/nonce?chain_slug=ethereum&wallet_address=0x0000000000000000000000000000000000000000', {
  headers: { Authorization: `Bearer ${s.bccToken}` },
});
console.log(r.status, await r.json());
```

**Expected:** `200`, body contains `nonce`, `message`, `expires_at`.

**Failure means:** ChallengeRepository is broken, advisory-lock acquisition is failing, or the chain registry doesn't recognize the slug. Check `wp_bcc_chains` for an active row matching the slug.

### 7.2 Link audit + security email pair

The full sign-and-link flow requires MetaMask/Keplr/Phantom — out of scope for a purely scripted golden path. After a manual link via the SPA's Wallet settings:

```bash
wp eval '
global $wpdb;
$row = $wpdb->get_row("SELECT id, user_id, target_id, created_at FROM {$wpdb->prefix}bcc_trust_activity WHERE action = \"wallet_linked\" ORDER BY id DESC LIMIT 1");
echo "Latest wallet_linked: " . ($row ? "id={$row->id} user={$row->user_id} wallet_link_id={$row->target_id} at={$row->created_at}" : "(none)") . PHP_EOL;
'
```

**Expected:** Row matching the link, plus a corresponding `wallet_linked_send_failed` count of 0 in the security-mail subsystem.

### 7.3 Unlink only fires on real state transition

After unlinking via the SPA, then immediately attempting to unlink the (already-gone) wallet via `curl -X DELETE`:

```bash
wp eval '
global $wpdb;
$rows = $wpdb->get_results("SELECT id, target_id, created_at FROM {$wpdb->prefix}bcc_trust_activity WHERE action = \"wallet_unlinked\" ORDER BY id DESC LIMIT 5");
foreach ($rows as $r) echo "  id={$r->id} wallet_link_id={$r->target_id} at={$r->created_at}" . PHP_EOL;
'
```

**Expected:** Exactly one row per real unlink. The idempotent second-tap unlink (with `removed: false`) does NOT generate an audit row — this is the "audit on real state transition only" invariant.

**Failure means:** Audit-log noise on idempotent re-attempts. See pattern-registry §"Destructive mutation hardening" invariant 1.

---

## Section 8 — Holder-Group Gating

**What this verifies.** The trusted-backend `PeepSoGroupWriter::join` door is always preceded by `NftGroupGateService::joinIfEligible`'s gate stack (config / opt-out / membership / chain / balance).

**Constitutional law verified:** §II.7 (the load-bearing-est invariant in the platform).

### 8.1 Run the `holder-groups-reviewer` agent

This is the canonical check. Invoke the subagent on every PR that touches:
- `bcc-trust/app/Domain/Onchain/Services/NftGroupGateService.php`
- `bcc-trust/app/Domain/Onchain/REST/HolderGroupsEndpoint.php`
- `bcc-core/src/PeepSo/PeepSoGroupWriter.php`
- Any new caller of `PeepSoGroupWriter::join`.

**Expected:** "Gate integrity — CLEAN" with the canonical execution order: auth → throttle → joinIfEligible → writer.

**Failure means:** A regression in the most consequential invariant on the platform. Block the PR.

### 8.2 Static call-site audit

```bash
grep -rn "PeepSoGroupWriter::join\|member_join\|PeepSoGroupUser->member_join" \
  "app/public/wp-content/plugins/bcc-core/src" \
  "app/public/wp-content/plugins/bcc-trust/app"
```

**Expected callers (verified 2026-05-13):**

- `bcc-core/src/PeepSo/PeepSoGroupWriter.php` — the writer itself
- `bcc-trust/app/Domain/Onchain/Services/NftGroupGateService.php:87` — gated by `joinIfEligible`
- `bcc-trust/app/Domain/Onchain/Services/NftGroupGateService.php:184` — gated by `reconcileForUser` (which is gated by `findEligibleGroups` + opt-out)
- `bcc-trust/app/Domain/Core/REST/MyGroupsEndpoint.php:133` — gated by privacy=open check + Throttle
- `bcc-trust/app/Domain/Core/Services/LocalsService.php` — Locals are intentionally open-membership

Any other caller is a violation.

### 8.3 Reconcile audit granularity

```bash
wp eval '
global $wpdb;
$rows = $wpdb->get_results("SELECT id, user_id, target_id, JSON_EXTRACT((SELECT \"\"), \"\") AS placeholder, created_at FROM {$wpdb->prefix}bcc_trust_activity WHERE action = \"holder_group_join\" ORDER BY id DESC LIMIT 10");
foreach ($rows as $r) echo "  id={$r->id} user={$r->user_id} group={$r->target_id} at={$r->created_at}" . PHP_EOL;
'
```

The audit log should distinguish explicit joins (via=`explicit`) from cron-reconcile joins (via=`reconcile`) via the meta payload. Per commit `25b54d9`, every reconcile-driven join lands its own row — this is what makes "why was user X added to group Y" forensically answerable.

**Failure means:** Reconcile audit was reverted or the `via` meta key was lost.

---

## Section 9 — Feed Propagation

**What this verifies.** A post submitted via the SPA appears in `/feed`, `/feed/hot`, `/groups/{id}/feed` (if scoped), and the read-model's denormalized projections within the configured stale window.

### 9.1 Post then fetch

After a status post via the Floor composer, in browser console:

```js
const s = await (await fetch('/api/auth/session', { credentials: 'include' })).json();
const r = await fetch('http://blue-collar-crypto-custom.local/wp-json/bcc/v1/feed?per_page=5', {
  headers: { Authorization: `Bearer ${s.bccToken}` },
});
const body = await r.json();
console.log('latest items:', body.data?.items?.slice(0, 3).map(i => ({ id: i.id, body: i.body?.slice(0, 50) })));
```

**Expected:** The most recent item matches the post just submitted.

**Failure means:**
- `FeedItemNormalizer` is rejecting the row → check `peepso_activities.act_module_id` for the inserted row; it must be a known kind.
- `ActivityFeedService` is reading a different table → confirm the source is `peepso_activities` and the read goes through `PeepSoActivityRepository`.

### 9.2 Hot feed sanity

```js
const r = await fetch('http://blue-collar-crypto-custom.local/wp-json/bcc/v1/feed/hot?per_page=5');
console.log(r.status, (await r.json()).data?.items?.length);
```

**Expected:** 200, items count > 0 on any seeded install. Hot feed is anonymous-accessible.

---

## Section 10 — Notification Path

**What this verifies.** In-app notifications (PeepSo), push (V2 Phase 1), and digest emails all share the `NotificationDispatcher` fan-out without parallel pipelines.

**Constitutional law verified:** §V.17 (no duplicate notification systems).

### 10.1 Confirm single dispatcher

```bash
grep -rn "add_notification\|PeepSoNotifications::add_notification\|PEEPSO_NOTIFICATIONS_TYPE" \
  "app/public/wp-content/plugins/bcc-trust/app" \
  "app/public/wp-content/plugins/bcc-core/src" \
  | grep -v 'tests/'
```

**Expected:** All notification writes go through `bcc-core/src/PeepSo/PeepSoNotificationWriter.php`. Any direct `PeepSoNotifications::add_notification` outside the writer is a §II.6 violation.

### 10.2 Push subscription smoke

```bash
wp eval '
global $wpdb;
$cnt = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bcc_push_subscriptions");
echo "Push subscriptions: $cnt" . PHP_EOL;
'
```

**Expected:** Some number > 0 on a seeded install. Subscriptions are written by `MyPushSubscriptionEndpoint`.

### 10.3 @mention fan-out check

After a real @mention in a feed post, expect:
- A row in `wp_bcc_trust_activity` with `action='mention_dispatched'` (or whatever the registered action name is — verify against `pattern-registry.md`)
- A corresponding in-app notification for the mentioned user
- A push payload if the user has an active push subscription

**Failure means:** The §4.10 mention dispatch is broken. See memory note `project_mention_dispatch.md` for the policy locks (original-write only, structural dedup, bell+push from day one).

---

## Section 11 — Drift / Read-Model Integrity

**What this verifies.** `bcc_page_read_model` is fresh enough; the legacy aggregation fallback is the documented fallback, not a parallel authority.

**Constitutional law verified:** §III.11 (read-model is canonical; legacy aggregation is a fallback only).

### 11.1 Read-model freshness

```bash
wp eval '
global $wpdb;
$row = $wpdb->get_row("SELECT COUNT(*) c, MIN(last_calculated_at) oldest, MAX(last_calculated_at) newest FROM {$wpdb->prefix}bcc_page_read_model");
echo "page_read_model rows: {$row->c}" . PHP_EOL;
echo "  oldest last_calculated_at: {$row->oldest}" . PHP_EOL;
echo "  newest last_calculated_at: {$row->newest}" . PHP_EOL;
$queue = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bcc_rm_dirty_queue");
echo "Recalc queue depth: $queue" . PHP_EOL;
'
```

**Expected:** Queue depth < 100 in steady state; oldest entry < 2h old (per the hourly safety-net cron in `CronService::hourlyRecalc`).

**Failure means:** The 5-minute processor cron isn't running OR the hourly safety net is throttled. Check `wp cron event list | grep bcc_trust_process_recalculations`.

### 11.2 Fallback trigger

```bash
wp eval '
$dm = apply_filters("bcc_system_health", []);
$ev = $dm["degradation_metrics"]["subsystems"]["read_model_fallback"]["legacy_aggregation"]["current"] ?? 0;
echo "read_model_fallback.legacy_aggregation current: $ev" . PHP_EOL;
'
```

**Expected:** 0 in steady state.

**Failure means:** Sustained nonzero = `PageReadModelRepository` is taking the legacy-aggregation path repeatedly. The read model is empty or stale on a hot read. Either:
- Initial bootstrap incomplete — re-run `wp cron event run bcc_trust_initial_read_model_sync`.
- A specific page hasn't been recalculated — find it via `bcc_rm_dirty_queue`.

---

## Section 12 — Health / Degradation Taxonomy Validation

**What this verifies.** The `/wp-json/bcc/v1/system/health` filter surfaces every registered DegradationMetric subsystem with all its registered events.

### 12.1 Filter contents

```bash
wp eval '
$dm = apply_filters("bcc_system_health", []);
echo "Top-level keys: " . implode(", ", array_keys($dm)) . PHP_EOL;
echo "DegradationMetrics subsystems:" . PHP_EOL;
foreach (($dm["degradation_metrics"]["subsystems"] ?? []) as $name => $events) {
  echo "  $name: " . count($events) . " events catalogued" . PHP_EOL;
}
'
```

**Expected (verified 2026-05-13):** at minimum these subsystems registered:

- `throttle`
- `null_trust_read` + 9 other `null_*` NullService activations
- `peepso_absence` (18 events)
- `search_lkg` (2 events)
- `read_model_fallback` (1 event)
- `audit_log_swallow` (4 events)
- `legacy_ajax` (9 events)
- `account_security_mail` (5 events)

**Failure means:** A registered subsystem dropped out of the canonical map in `bcc-core/bcc-core.php`. New subsystems wired into `DegradationMetrics::record()` MUST register here — the map is the only place future agents discover which events are even possible.

### 12.2 Health endpoint reachable

```bash
curl -s "http://blue-collar-crypto-custom.local/wp-json/bcc/v1/system/health" | head -200
```

**Expected:** Envelope-wrapped JSON `{data: {...}, _meta: {...}}`.

**Failure means:** Either `/bcc/v1` namespace is broken (Envelope wrapper not loaded) or the health endpoint itself was removed.

---

## Section 13 — Cron / Indexer Sanity

**What this verifies.** All canonical cron events are scheduled, no events are unscheduled, and the V2 NFT indexer worker is healthy.

### 13.1 Cron registry

```bash
wp cron event list --fields=hook,next_run_relative,recurrence
```

**Expected events** (verified 2026-05-13 post-resolution):

| Event | Recurrence | Notes |
|---|---|---|
| `bcc_trust_daily_cleanup` | daily | Audit-log retention via archiveBatch |
| `bcc_trust_process_recalculations` | bcc_five_minutes | Read-model recalc |
| `bcc_trust_hourly_safety_recalc` | hourly | Read-model safety net |
| `bcc_search_ensure_ft_index` | hourly | FT index self-heal |
| `bcc_helius_dedupe_sweep` | bcc_five_minutes | Helius dedup table sweep |
| `bcc_onchain_daily_refresh` | daily | NFT/wallet refresh |
| `bcc_onchain_retry_bonus` | hourly | Failed bonus retry |
| `bcc_gated_group_provision` | daily | Gated-group provisioning |
| `bcc_gated_group_reconcile_sweep` | twicedaily | Holder-group reconcile |
| `bcc_core_rl_cleanup` | bcc_thirty_minutes | Rate-limit table cleanup |
| `<NftEthIndexerWorker::CRON_HOOK>` | bcc_one_minute | V2 NFT indexer |

**Failure means:** A cron didn't get scheduled on plugin activation. Per memory note `project_v2_nft_cron_drift_incident.md`, the activation hook only fires on plugin activate — sites upgraded in-place may never schedule new events. Re-activation (deactivate → activate) re-fires the activation block. Plugin code now also self-heals on `plugins_loaded` — DO NOT remove the apparent redundancy.

### 13.2 NFT indexer health snapshot

```bash
wp eval '
if (class_exists("\\BCC\\Trust\\Onchain\\Services\\NftIndexerHealthSnapshot")) {
  $h = \BCC\Trust\Onchain\Services\NftIndexerHealthSnapshot::snapshot();
  print_r($h);
}
'
```

**Expected:** A populated snapshot with per-chain checkpoints. Stale checkpoints (> 1 hour for active chains) are a degradation signal.

### 13.3 Helius dedup table bounded

```bash
wp eval '
global $wpdb;
$cnt = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bcc_helius_seen_signatures");
echo "helius_seen_signatures rows: $cnt" . PHP_EOL;
'
```

**Expected:** ≤ ~10,000 (capped by `bcc_helius_dedupe_sweep` which trims to 10k oldest-first).

**Failure means:** The sweep cron is unscheduled — return to Section 13.1.

---

## Section 14 — Frontend API Contract Verification

**What this verifies.** The frontend's `lib/api/types.ts` shapes match the actual REST envelope. Drift here is the canonical source of "frontend rendered nothing and no error showed up."

### 14.1 Run the contract guard

```bash
bash app/public/wp-content/plugins/bcc-trust/scripts/arch-guardrails.sh --with-contract
```

This invokes `scripts/api-contract-check.sh` against the live site, diffs every REST endpoint's actual response shape against the documented one in `docs/api-contract-v1.md` and `bcc-frontend/src/lib/api/types.ts`.

**Expected:** `PASS`.

**Failure means:** A REST endpoint added/removed/renamed a field without updating the contract. Per the constitution §9, contract breaks are P0. Fix the contract OR fix the response — do not merge until aligned.

### 14.2 TypeScript strict check

```bash
cd bcc-frontend && npx tsc --noEmit
```

**Expected:** No output (exit 0).

**Failure means:** `types.ts` is out of sync with itself, OR a hook/component references a removed field. Fix before merging.

### 14.3 Envelope smoke

```js
// In browser devtools:
const s = await (await fetch('/api/auth/session', { credentials: 'include' })).json();
const r = await fetch('http://blue-collar-crypto-custom.local/wp-json/bcc/v1/me/account/email', {
  method: 'PATCH',
  headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${s.bccToken}` },
  body: JSON.stringify({ email: 'unused@example.com', current_password: 'wrong' }),
});
const body = await r.json();
console.log(Object.keys(body));
```

**Expected:** `["error", "_meta"]` on the error envelope (because we sent a wrong password). The shape `{ error: { code, message, status } , _meta: { ... } }` is the contract.

**Failure means:** Envelope wrapper isn't running on this endpoint, or the `rest_post_dispatch` filter priority got changed.

### 14.4 Dual-client namespace discipline

The frontend ships two HTTP clients per the dual-namespace migration shim (V-07 / V-29 in the audit doc):

- `bcc-frontend/src/lib/api/client.ts` → `/bcc/v1/*` (envelope: `{ data, _meta }`)
- `bcc-frontend/src/lib/api/bcc-trust-client.ts` → `/bcc-trust/v1/*` (envelope: `{ success: true, data }`)

These envelopes have **incompatible parser shapes**. Any new endpoint adapter must call the correct client for its namespace.

```bash
# Confirm every endpoint adapter's bcc*Fetch call matches the namespace.
# Should produce a tabular listing; review by hand.
grep -rn "bccFetch\|bccTrustFetch\|bccFetchAsClient" "bcc-frontend/src/lib/api/" | grep -v 'types.ts'
```

**Failure means:** A new adapter is calling the wrong client. The envelope parser will throw `bcc_invalid_envelope` at first contact. See `audit-doc V-07 / V-29` and Agent B's coupling-audit Finding #2.

---

## Appendix A — Failure Severity Decision Tree

When a Golden Path check fails, walk this triage:

1. **Did the platform serve the request at all?** No → site/plugin loading issue. Check plugin activation status.
2. **Was the response shape malformed?** Yes → contract break. Section 14.
3. **Did the mutation NOT persist to the canonical table?** Yes → repository or transaction bug. Check `wp-content/debug.log`.
4. **Did the mutation persist but no audit row?** Yes → §VIII.30 swallow fired silently. Section 4.2.
5. **Did the user experience generic error copy instead of contextual?** Yes → per-component `ERROR_COPY` map missing the code. Cosmetic, not operational.
6. **Did a DegradationMetric fire?** Yes → see the appropriate subsystem section.

## Appendix B — Things That Look Like Bugs But Aren't

These behaviors are intentional. If a future contributor "fixes" them, that's a regression:

- **Empty audit rows on no-op mutations** — `holder_group_join` does NOT fire on `CODE_ALREADY_MEMBER`. This is correct per pattern-registry §"Destructive mutation hardening" invariant 1.
- **`NullTrustReadService::isSuspended() → true`** — deny-by-default. If bcc-trust isn't loaded, every suspension check denies. This is the only correct fail-closed posture for a suspension gate.
- **`PeepSoGroupWriter::join` returning true on no-op** — its contract documents this. The audit gating in `MyGroupsEndpoint` pre-checks membership before calling the writer.
- **`Throttle::allow` fail-closed on no cache backend** — without Redis + without bcc-trust's `RateLimiter`, every mutation denies. This is by design — a previous wp_options-backed fallback caused write amplification on a contended table.
- **`AuditLogger::log` swallowing insert failures** — §VIII.30. Failure observable via `audit_log_swallow.log_write_failed`.
- **Sentry replay disabled** — by design until a redaction policy exists for wallet addresses, NFT holdings, dispute content.
- **Two HTTP clients on the frontend** — necessary scaffolding for the dual REST namespace migration; collapses when V-07 closes.

## Appendix C — Procedures That Are Not in This Document (Yet)

These are operational checks that warrant golden-path coverage but require more verification work before codifying:

- **Dispute panel quorum + auto-resolve.** End-to-end test requires multiple test users with Trusted/Elite tier.
- **OAuth verification (X / GitHub).** Round-trip requires live OAuth app credentials.
- **Helius webhook signature verification.** Requires Helius secret + a way to forge a delivery.
- **Search FULLTEXT index rebuild under load.** Requires a stampede simulator.
- **Cron-event scheduling during plugin re-activation.** Procedure exists but the failure mode (V2 NFT cron drift incident) is documented as a separate memory note.

Add these as they become exercisable.

---

## How to use this document

- **Before a release:** run every section. Mark failures. Do not release if any fail without an explicit deferral.
- **After a backend deploy:** run Sections 4, 5, 11, 12, 13. These catch the most common deploy regressions.
- **After a frontend deploy:** run Sections 1, 5.3, 14. These catch envelope drift + copy regressions.
- **When investigating a user report:** start with the section most likely to contain the failed surface. Each section names its canonical DB tables + DegradationMetrics for direct inspection.
- **When adding a new destructive mutation:** the recipe in pattern-registry §"Destructive mutation hardening" is the implementation doctrine; the verification doctrine is here. Add a new section if the mutation is operationally distinct (e.g. crosses the PeepSo boundary, emits a new audit action, registers a new DegradationMetric).

If a procedure here is wrong, **fix it**. This document is the canonical operational record, not a historical artifact. PRs to this file are reviewed like any other PR — see commit `0df0e87`'s shape for the pattern.
