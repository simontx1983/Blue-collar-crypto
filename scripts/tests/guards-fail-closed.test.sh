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
#     its path was, read only the first verb of a multi-verb contract
#     header, let an unreadable method set fall back to a wildcard that
#     matched every documented verb, ignored unresolved register_rest_route()
#     sites, and never checked that it parsed anything at all;
#   - dead-file-scan silently skipped a missing source root;
#   - subsystem-count passed when its doc-claim regex matched no line.
# A bare umbrella checkout (no plugin trees) turned all three green.
#
# ── HOW THE CASES AVOID READING GREEN BY ACCIDENT ────────────────────────
# Each case runs a COPY of the guard inside a throwaway fixture root, with
# no DB and no sibling repos. The guards resolve their repo root from their
# own location, so they never see the real tree.
#
# contract-parity runs with BCC_CONTRACT_PARITY_EMPTY_ALLOWLIST=1. Otherwise
# every real EXEMPT_INTERNAL entry is "stale" in a fixture, every run exits
# 1, and a negative case's `rc != 0` would hold no matter what. With the
# allowlist emptied:
#   - positive cases assert rc == 0 AND the exact in-scope route count, so
#     they stay red if a route isn't extracted or a constant isn't mapped;
#   - negative cases assert the specific finding line.

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
check() { if eval "$2"; then ok "$1"; else bad "$1 (rc=$RC)" "$OUT"; fi; }

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
  OUT="$(cd "$1" && BCC_CONTRACT_PARITY_EMPTY_ALLOWLIST=1 php "scripts/$2.php" 2>&1)"
  RC=$?
}

has()  { printf '%s\n' "$OUT" | grep -qF -- "$1"; }

# ── contract-parity fixtures ─────────────────────────────────────────────
# contract_root <name> <contract-body> <php-body>
# Always adds a documented, registered `GET /bcc/v1/anchor`, so in-scope
# counts below are 1 + the case's own routes.
contract_root() {
  local root; root="$(new_root "$1")"
  printf '%s\n' "$ANCHOR_DOC" "$2" > "$root/docs/api-contract-v1.md"
  printf '<?php\n%s\n' "$3" > "$root/$PLUGINS_REL/bcc-trust/routes.php"
  cat > "$root/$PLUGINS_REL/bcc-core/anchor.php" <<'PHP'
<?php
register_rest_route('bcc/v1', '/anchor', ['methods' => 'GET']);
PHP
  printf '%s' "$root"
}
ANCHOR_DOC='#### `GET /bcc/v1/anchor`'

echo "contract-parity-guard"

R="$(contract_root control '' '')"
run "$R" contract-parity-guard
check "control: a fully documented fixture exits 0" \
  '[ "$RC" -eq 0 ] && has "in-scope: 1)"'

R="$(contract_root editable '#### `PATCH /bcc/v1/prefs`' \
"register_rest_route('bcc/v1', '/prefs', ['methods' => WP_REST_Server::EDITABLE, 'callback' => 'h']);")"
run "$R" contract-parity-guard
check "EDITABLE triple is documented by PATCH (exit 0, 3 verbs extracted)" \
  '[ "$RC" -eq 0 ] && has "in-scope: 4)"'

R="$(contract_root editable-fq '#### `POST /bcc/v1/prefs`' \
"register_rest_route('bcc/v1', '/prefs', ['methods' => \\WP_REST_Server::EDITABLE, 'callback' => 'h']);")"
run "$R" contract-parity-guard
check "\\WP_REST_Server::EDITABLE (leading backslash) is documented by POST" \
  '[ "$RC" -eq 0 ] && has "in-scope: 4)"'

R="$(contract_root editable-undoc '' \
"register_rest_route('bcc/v1', '/prefs', ['methods' => WP_REST_Server::EDITABLE, 'callback' => 'h']);")"
run "$R" contract-parity-guard
check "undocumented EDITABLE lists all three verbs (pins the mapping)" \
  'has "  - POST /bcc/v1/prefs" && has "  - PUT /bcc/v1/prefs" && has "  - PATCH /bcc/v1/prefs"'

R="$(contract_root literal-triple '#### `POST /bcc/v1/prefs`' \
"register_rest_route('bcc/v1', '/prefs', ['methods' => 'POST, PUT, PATCH', 'callback' => 'h']);")"
run "$R" contract-parity-guard
check "literal 'POST, PUT, PATCH' triple is documented by POST" \
  '[ "$RC" -eq 0 ] && has "in-scope: 4)"'

R="$(contract_root post-only '#### `PATCH /bcc/v1/prefs`' \
"register_rest_route('bcc/v1', '/prefs', ['methods' => 'POST', 'callback' => 'h']);")"
run "$R" contract-parity-guard
check "POST-only route is NOT documented by PATCH" \
  '[ "$RC" -ne 0 ] && has "  - POST /bcc/v1/prefs"'

R="$(contract_root split-configs '#### `PATCH /bcc/v1/prefs`' \
"register_rest_route('bcc/v1', '/prefs', [
  ['methods' => 'POST',  'callback' => 'a'],
  ['methods' => 'PUT',   'callback' => 'b'],
  ['methods' => 'PATCH', 'callback' => 'c'],
]);")"
run "$R" contract-parity-guard
check "three separate configs are NOT grouped (distinct handlers)" \
  '[ "$RC" -ne 0 ] && has "  - POST /bcc/v1/prefs" && has "  - PUT /bcc/v1/prefs" && ! has "  - PATCH /bcc/v1/prefs"'

R="$(contract_root triple-plus-post '#### `PATCH /bcc/v1/prefs`' \
"register_rest_route('bcc/v1', '/prefs', ['methods' => WP_REST_Server::EDITABLE, 'callback' => 'a']);
register_rest_route('bcc/v1', '/prefs', ['methods' => 'POST', 'callback' => 'b']);")"
run "$R" contract-parity-guard
check "a separate POST config beside a triple is NOT covered (per-config)" \
  '[ "$RC" -ne 0 ] && has "  - POST /bcc/v1/prefs" && ! has "  - PUT /bcc/v1/prefs"'

R="$(contract_root delete-sibling '#### `POST /bcc/v1/feed/{id}/stoke`' \
"register_rest_route('bcc/v1', '/feed/(?P<id>\\d+)/stoke', [
  ['methods' => 'POST',   'callback' => 'a'],
  ['methods' => 'DELETE', 'callback' => 'b'],
]);")"
run "$R" contract-parity-guard
check "DELETE sibling of a documented POST still FAILS" \
  '[ "$RC" -ne 0 ] && has "  - DELETE /bcc/v1/feed/:id/stoke" && ! has "  - POST /bcc/v1/feed/:id/stoke"'

R="$(contract_root const-delete '#### `GET /bcc/v1/thing`' \
"final class ThingEndpoint {
  private const METHOD = 'DELETE';
  public static function register(): void {
    register_rest_route('bcc/v1', '/thing', ['methods' => 'GET', 'callback' => 'a']);
    register_rest_route('bcc/v1', '/thing', ['methods' => self::METHOD, 'callback' => 'b']);
  }
}")"
run "$R" contract-parity-guard
check "self::CONST method resolves: DELETE with only GET documented FAILS" \
  '[ "$RC" -ne 0 ] && has "  - DELETE /bcc/v1/thing" && has "in-scope: 3)" && ! has "UNRESOLVED"'

R="$(contract_root var-method '#### `GET /bcc/v1/thing`' \
"register_rest_route('bcc/v1', '/thing', ['methods' => \$m, 'callback' => 'a']);")"
run "$R" contract-parity-guard
check "a variable method set is UNRESOLVED, not a wildcard" \
  '[ "$RC" -ne 0 ] && has "FAIL: UNRESOLVED" && has "methods=UNRESOLVED"'

R="$(contract_root var-config '#### `GET /bcc/v1/thing`' \
"register_rest_route('bcc/v1', '/thing', \$config);")"
run "$R" contract-parity-guard
check "a variable config (no 'methods' key) is UNRESOLVED, not a wildcard" \
  '[ "$RC" -ne 0 ] && has "methods=UNRESOLVED"'

R="$(contract_root multi-verb-header '#### `POST /bcc/v1/feed/:id/stoke` · `DELETE /bcc/v1/feed/:id/stoke`' \
"register_rest_route('bcc/v1', '/feed/(?P<id>\\d+)/stoke', ['methods' => 'POST', 'callback' => 'a']);
register_rest_route('bcc/v1', '/feed/(?P<id>\\d+)/stoke', ['methods' => 'DELETE', 'callback' => 'b']);")"
run "$R" contract-parity-guard
check "second \`METHOD /path\` on a header is parsed" \
  '[ "$RC" -eq 0 ] && has "Contract endpoints parsed: 3"'

R="$(contract_root bare-method-header '#### `GET /bcc/v1/me/prefs` and `PATCH` (v2)' \
"register_rest_route('bcc/v1', '/me/prefs', ['methods' => 'GET', 'callback' => 'a']);
register_rest_route('bcc/v1', '/me/prefs', ['methods' => 'PATCH', 'callback' => 'b']);")"
run "$R" contract-parity-guard
check "bare \`PATCH\` on a header inherits the preceding path" \
  '[ "$RC" -eq 0 ] && has "Contract endpoints parsed: 3"'

R="$(contract_root unresolved-path '' \
"register_rest_route('bcc/v1', \$this->path(), ['methods' => 'GET']);")"
run "$R" contract-parity-guard
check "unresolved register_rest_route() path FAILS" \
  '[ "$RC" -ne 0 ] && has "FAIL: UNRESOLVED"'

R="$(contract_root zero-contract '' '')"
printf 'no endpoint headers here\n' > "$R/docs/api-contract-v1.md"
run "$R" contract-parity-guard
check "zero parsed contract endpoints FAILS" \
  '[ "$RC" -ne 0 ] && has "FAIL: parsed 0 contract endpoints"'

R="$(new_root zero-in-scope)"
printf '%s\n' "$ANCHOR_DOC" > "$R/docs/api-contract-v1.md"
cat > "$R/$PLUGINS_REL/bcc-core/other.php" <<'PHP'
<?php
register_rest_route('other/v1', '/anchor', ['methods' => 'GET']);
PHP
run "$R" contract-parity-guard
check "zero in-scope code routes FAILS" \
  '[ "$RC" -ne 0 ] && has "FAIL: parsed 0 in-scope register_rest_route() endpoints"'

R="$(contract_root missing-root '' '')"
rm -rf "$R/$PLUGINS_REL/bcc-search"
run "$R" contract-parity-guard
check "missing plugin root FAILS (exit 2)" \
  '[ "$RC" -eq 2 ] && has "Missing plugin root"'

# ── dead-file-scan ───────────────────────────────────────────────────────
echo "dead-file-scan"

R="$(new_root dfs-missing)"
run "$R" dead-file-scan
check "missing source roots FAIL" \
  '[ "$RC" -ne 0 ] && has "missing root: app/public/wp-content/plugins/bcc-core/src"'

R="$(new_root dfs-truncated)"
for d in bcc-core/src bcc-search/app bcc-trust/app bcc-trust/includes; do
  mkdir -p "$R/$PLUGINS_REL/$d"
  printf '<?php\nclass C%s {}\n' "$RANDOM" > "$R/$PLUGINS_REL/$d/One.php"
done
run "$R" dead-file-scan
check "a tree below the file-count floor FAILS" \
  '[ "$RC" -ne 0 ] && has "only 4 candidate files found"'

R="$(new_root dfs-empty-root)"
mkdir -p "$R/$PLUGINS_REL/bcc-core/src" "$R/$PLUGINS_REL/bcc-search/app" \
         "$R/$PLUGINS_REL/bcc-trust/app" "$R/$PLUGINS_REL/bcc-trust/includes"
run "$R" dead-file-scan
check "an existing but empty source root FAILS" \
  '[ "$RC" -ne 0 ] && has "source root has no PHP files"'

# ── subsystem-count-guard ────────────────────────────────────────────────
echo "subsystem-count-guard"

# subsys_root <name> <map-body> <pattern-registry> <golden-paths>
subsys_root() {
  local root; root="$(new_root "$1")"
  printf '<?php\nDegradationMetrics::healthSnapshot([\n%s\n]);\n' "$2" \
    > "$root/$PLUGINS_REL/bcc-core/bcc-core.php"
  printf '%s\n' "$3" > "$root/docs/pattern-registry.md"
  printf '%s\n' "$4" > "$root/docs/GOLDEN_PATHS.md"
  printf '%s' "$root"
}
MAP="    'demo_sub' => ['a.one', 'a.two'],"

R="$(subsys_root ssc-clean "$MAP" '`demo_sub` (2 events)' 'demo_sub / 2 events')"
run "$R" subsystem-count-guard
check "control: matching claims in both docs PASS (2 compared)" \
  '[ "$RC" -eq 0 ] && has "(2 doc claims compared across 2 docs"'

R="$(subsys_root ssc-drift "$MAP" '`demo_sub` (3 events)' 'demo_sub / 2 events')"
run "$R" subsystem-count-guard
check "control: a count mismatch FAILS" \
  '[ "$RC" -ne 0 ] && has "'"'"'demo_sub'"'"' claims 3 events but canonical map has 2"'

R="$(subsys_root ssc-blind "$MAP" '`demo_sub` (2 events)' 'this doc lost its claims')"
run "$R" subsystem-count-guard
check "a doc with zero recognisable claims FAILS" \
  '[ "$RC" -ne 0 ] && has "docs/GOLDEN_PATHS.md — 0 subsystem-count claims compared"'

R="$(subsys_root ssc-skipped-only "$MAP" '`demo_sub` (2 events)' 'total (5 events) across everything')"
run "$R" subsystem-count-guard
check "a doc whose only hit is a skipped underscore-free name FAILS" \
  '[ "$RC" -ne 0 ] && has "docs/GOLDEN_PATHS.md — 0 subsystem-count claims compared"'

R="$(subsys_root ssc-empty-map "" '`demo_sub` (2 events)' 'demo_sub / 2 events')"
run "$R" subsystem-count-guard
check "an empty canonical map FAILS" \
  '[ "$RC" -ne 0 ] && has "canonical subsystem map parsed as EMPTY"'

echo
echo "passed=$PASSED failed=$FAILED"
[ "$FAILED" -eq 0 ]
