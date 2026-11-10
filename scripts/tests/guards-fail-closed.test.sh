#!/usr/bin/env bash
#
# Regression harness for the fail-closed behaviour of three umbrella guards:
#   scripts/contract-parity-guard.php
#   scripts/dead-file-scan.php
#   scripts/subsystem-count-guard.php
#
# ── WHY THIS EXISTS ──────────────────────────────────────────────────────
# Each guard used to PASS over nothing:
#   - contract-parity counted a code route as documented if ANY method on
#     its path was, ignored unresolved register_rest_route() sites, and
#     never checked that it parsed anything at all;
#   - dead-file-scan silently skipped a missing source root;
#   - subsystem-count passed when its doc-claim regex matched no line.
# A bare umbrella checkout (no plugin trees) turned all three green.
#
# These cases pin the fix: METHOD+path matching (with the one-handler
# EDITABLE-triple exception), every verb on a multi-verb contract header
# is read, unresolved sites FAIL, a zero parse FAILs,
# a missing root FAILs, and a doc with no recognisable claims FAILs.
#
# ── NO DB, NO SIBLING REPOS ──────────────────────────────────────────────
# Each case runs a COPY of the guard inside a throwaway fixture root. The
# guards resolve their repo root from their own location, so they never see
# the real tree. Every positive case also asserts on a line the guard prints
# only after a real parse, so a harness that parses nothing cannot read as
# green.

set -uo pipefail

HARNESS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPTS="$(cd "$HARNESS_DIR/.." && pwd)"

command -v php >/dev/null 2>&1 || { echo "harness: php not available" >&2; exit 2; }
for g in contract-parity-guard dead-file-scan subsystem-count-guard; do
  [ -f "$SCRIPTS/$g.php" ] || { echo "harness: cannot find $SCRIPTS/$g.php" >&2; exit 2; }
done

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

PASSED=0
FAILED=0
ok()   { PASSED=$((PASSED + 1)); printf '  ok   %s\n' "$1"; }
bad()  { FAILED=$((FAILED + 1)); printf '  FAIL %s\n' "$1"; [ -n "${2:-}" ] && printf '%s\n' "$2" | sed 's/^/       | /'; }

PLUGINS_REL="app/public/wp-content/plugins"

# new_root <name>: fixture root with all three guard copies and the three
# plugin dirs (empty). Prints the root path.
new_root() {
  local root="$TMP/$1"
  mkdir -p "$root/scripts" "$root/docs"
  cp "$SCRIPTS/contract-parity-guard.php" "$SCRIPTS/dead-file-scan.php" \
     "$SCRIPTS/subsystem-count-guard.php" "$root/scripts/"
  for p in bcc-core bcc-search bcc-trust; do mkdir -p "$root/$PLUGINS_REL/$p"; done
  printf '%s' "$root"
}

# run <root> <guard> → sets OUT and RC
run() {
  OUT="$(cd "$1" && php "scripts/$2.php" 2>&1)"
  RC=$?
}

has()    { printf '%s\n' "$OUT" | grep -qF -- "$1"; }

# ── contract-parity fixtures ─────────────────────────────────────────────
# contract_root <name> <contract-body> <php-body>
contract_root() {
  local root; root="$(new_root "$1")"
  printf '%s\n' "$2" > "$root/docs/api-contract-v1.md"
  printf '<?php\n%s\n' "$3" > "$root/$PLUGINS_REL/bcc-trust/routes.php"
  # A documented, registered GET keeps the parse non-empty in every case.
  printf '<?php\nregister_rest_route('"'"'bcc/v1'"'"', '"'"'/anchor'"'"', ['"'"'methods'"'"' => '"'"'GET'"'"']);\n' \
    > "$root/$PLUGINS_REL/bcc-core/anchor.php"
  printf '%s' "$root"
}
ANCHOR_DOC='#### `GET /bcc/v1/anchor`'

echo "contract-parity-guard"

R="$(contract_root editable "$ANCHOR_DOC
#### \`PATCH /bcc/v1/prefs\`" \
"register_rest_route('bcc/v1', '/prefs', ['methods' => WP_REST_Server::EDITABLE, 'callback' => 'h']);")"
run "$R" contract-parity-guard
if has 'Contract endpoints parsed: 2' && ! has '  - POST /bcc/v1/prefs' && ! has '  - PUT /bcc/v1/prefs'; then
  ok "EDITABLE triple is documented by PATCH"
else bad "EDITABLE triple is documented by PATCH" "$OUT"; fi

R="$(contract_root literal-triple "$ANCHOR_DOC
#### \`POST /bcc/v1/prefs\`" \
"register_rest_route('bcc/v1', '/prefs', ['methods' => 'POST, PUT, PATCH', 'callback' => 'h']);")"
run "$R" contract-parity-guard
if has 'Contract endpoints parsed: 2' && ! has '  - PUT /bcc/v1/prefs' && ! has '  - PATCH /bcc/v1/prefs'; then
  ok "literal 'POST, PUT, PATCH' triple is documented by POST"
else bad "literal 'POST, PUT, PATCH' triple is documented by POST" "$OUT"; fi

R="$(contract_root post-only "$ANCHOR_DOC
#### \`PATCH /bcc/v1/prefs\`" \
"register_rest_route('bcc/v1', '/prefs', ['methods' => 'POST', 'callback' => 'h']);")"
run "$R" contract-parity-guard
if [ "$RC" -ne 0 ] && has '  - POST /bcc/v1/prefs'; then
  ok "POST-only route is NOT documented by PATCH"
else bad "POST-only route is NOT documented by PATCH (rc=$RC)" "$OUT"; fi

R="$(contract_root split-configs "$ANCHOR_DOC
#### \`PATCH /bcc/v1/prefs\`" \
"register_rest_route('bcc/v1', '/prefs', [
  ['methods' => 'POST',  'callback' => 'a'],
  ['methods' => 'PUT',   'callback' => 'b'],
  ['methods' => 'PATCH', 'callback' => 'c'],
]);")"
run "$R" contract-parity-guard
if [ "$RC" -ne 0 ] && has '  - POST /bcc/v1/prefs' && has '  - PUT /bcc/v1/prefs'; then
  ok "three separate configs are NOT grouped (distinct handlers)"
else bad "three separate configs are NOT grouped (rc=$RC)" "$OUT"; fi

R="$(contract_root delete-sibling "$ANCHOR_DOC
#### \`POST /bcc/v1/feed/{id}/stoke\`" \
"register_rest_route('bcc/v1', '/feed/(?P<id>\\d+)/stoke', [
  ['methods' => 'POST',   'callback' => 'a'],
  ['methods' => 'DELETE', 'callback' => 'b'],
]);")"
run "$R" contract-parity-guard
if [ "$RC" -ne 0 ] && has '  - DELETE /bcc/v1/feed/:id/stoke' && ! has '  - POST /bcc/v1/feed/:id/stoke'; then
  ok "DELETE sibling of a documented POST still FAILS"
else bad "DELETE sibling of a documented POST still FAILS (rc=$RC)" "$OUT"; fi

R="$(contract_root multi-verb-header "$ANCHOR_DOC
#### \`POST /bcc/v1/feed/:id/stoke\` · \`DELETE /bcc/v1/feed/:id/stoke\`" \
"register_rest_route('bcc/v1', '/feed/(?P<id>\\d+)/stoke', ['methods' => 'POST', 'callback' => 'a']);
register_rest_route('bcc/v1', '/feed/(?P<id>\\d+)/stoke', ['methods' => 'DELETE', 'callback' => 'b']);")"
run "$R" contract-parity-guard
if has 'Contract endpoints parsed: 3' && ! has '  - DELETE /bcc/v1/feed/:id/stoke'; then
  ok "second \`METHOD /path\` on a header is parsed"
else bad "second \`METHOD /path\` on a header is parsed" "$OUT"; fi

R="$(contract_root bare-method-header "$ANCHOR_DOC
#### \`GET /bcc/v1/me/prefs\` and \`PATCH\` (v2)" \
"register_rest_route('bcc/v1', '/me/prefs', ['methods' => 'GET', 'callback' => 'a']);
register_rest_route('bcc/v1', '/me/prefs', ['methods' => 'PATCH', 'callback' => 'b']);")"
run "$R" contract-parity-guard
if has 'Contract endpoints parsed: 3' && ! has '  - PATCH /bcc/v1/me/prefs'; then
  ok "bare \`PATCH\` on a header inherits the preceding path"
else bad "bare \`PATCH\` on a header inherits the preceding path" "$OUT"; fi

R="$(contract_root unresolved "$ANCHOR_DOC" \
"register_rest_route('bcc/v1', \$this->path(), ['methods' => 'GET']);")"
run "$R" contract-parity-guard
if [ "$RC" -ne 0 ] && has 'FAIL: UNRESOLVED'; then
  ok "unresolved register_rest_route() site FAILS"
else bad "unresolved register_rest_route() site FAILS (rc=$RC)" "$OUT"; fi

R="$(contract_root zero-contract "no endpoint headers here" "")"
run "$R" contract-parity-guard
if [ "$RC" -ne 0 ] && has 'FAIL: parsed 0 contract endpoints'; then
  ok "zero parsed contract endpoints FAILS"
else bad "zero parsed contract endpoints FAILS (rc=$RC)" "$OUT"; fi

R="$(contract_root missing-root "$ANCHOR_DOC" "")"
rm -rf "$R/$PLUGINS_REL/bcc-search"
run "$R" contract-parity-guard
if [ "$RC" -eq 2 ] && has 'Missing plugin root'; then
  ok "missing plugin root FAILS (exit 2)"
else bad "missing plugin root FAILS (rc=$RC)" "$OUT"; fi

# ── dead-file-scan ───────────────────────────────────────────────────────
echo "dead-file-scan"

R="$(new_root dfs-missing)"
run "$R" dead-file-scan
if [ "$RC" -ne 0 ] && has 'missing root: app/public/wp-content/plugins/bcc-core/src'; then
  ok "missing source roots FAIL"
else bad "missing source roots FAIL (rc=$RC)" "$OUT"; fi

R="$(new_root dfs-truncated)"
for d in bcc-core/src bcc-search/app bcc-trust/app bcc-trust/includes; do
  mkdir -p "$R/$PLUGINS_REL/$d"
  printf '<?php\nclass C%s {}\n' "$RANDOM" > "$R/$PLUGINS_REL/$d/One.php"
done
run "$R" dead-file-scan
if [ "$RC" -ne 0 ] && has 'only 4 candidate files found'; then
  ok "a tree below the file-count floor FAILS"
else bad "a tree below the file-count floor FAILS (rc=$RC)" "$OUT"; fi

R="$(new_root dfs-empty-root)"
mkdir -p "$R/$PLUGINS_REL/bcc-core/src" "$R/$PLUGINS_REL/bcc-search/app" \
         "$R/$PLUGINS_REL/bcc-trust/app" "$R/$PLUGINS_REL/bcc-trust/includes"
run "$R" dead-file-scan
if [ "$RC" -ne 0 ] && has 'source root has no PHP files'; then
  ok "an existing but empty source root FAILS"
else bad "an existing but empty source root FAILS (rc=$RC)" "$OUT"; fi

# ── subsystem-count-guard ────────────────────────────────────────────────
echo "subsystem-count-guard"

subsys_root() {
  local root; root="$(new_root "$1")"
  cat > "$root/$PLUGINS_REL/bcc-core/bcc-core.php" <<'PHP'
<?php
DegradationMetrics::healthSnapshot([
    'demo_sub' => ['a.one', 'a.two'],
]);
PHP
  printf '%s\n' "$2" > "$root/docs/pattern-registry.md"
  printf '%s\n' "$3" > "$root/docs/GOLDEN_PATHS.md"
  printf '%s' "$root"
}

R="$(subsys_root ssc-clean '`demo_sub` (2 events)' 'demo_sub / 2 events')"
run "$R" subsystem-count-guard
if [ "$RC" -eq 0 ] && has 'PASS: subsystem counts match' && has '(1 subsystems checked)'; then
  ok "matching claims in both docs PASS (control)"
else bad "matching claims in both docs PASS (rc=$RC)" "$OUT"; fi

R="$(subsys_root ssc-blind '`demo_sub` (2 events)' 'this doc lost its claims')"
run "$R" subsystem-count-guard
if [ "$RC" -ne 0 ] && has 'docs/GOLDEN_PATHS.md — 0 subsystem-count claims matched'; then
  ok "a doc with zero recognisable claims FAILS"
else bad "a doc with zero recognisable claims FAILS (rc=$RC)" "$OUT"; fi

echo
echo "passed=$PASSED failed=$FAILED"
[ "$FAILED" -eq 0 ]
