#!/usr/bin/env bash
#
# Regression harness for scripts/schema-drift-guard.php — inventory Status
# handling (issue #162).
#
# ── WHY THIS EXISTS ──────────────────────────────────────────────────────
# The guard used to classify an inventory row by SUBSTRING (`orphan` /
# `retired` anywhere in the Status cell) and only checked
#   documented-Active ⇒ declared   and   declared ⇒ documented.
# It never checked documented-ORPHAN ⇒ NOT declared. So relabelling a live,
# declared table's Status to ORPHAN removed it from the Active set and the
# guard stayed green: a static false-green available to anyone who wanted a
# red schema guard to pass without touching code.
#
# These cases pin the fix: a relabel fails and names the table, the Status
# cell is matched exactly, RETIRED stays distinct from ORPHAN (only RETIRED
# gets the explicit RETIREMENT_WINDOW escape hatch) and stale window entries
# fail.
#
# ── NO DB, NO SIBLING REPOS ──────────────────────────────────────────────
# Each case runs a COPY of the guard inside a throwaway fixture root that
# holds a minimal docs/database-schema.md and a one-file `bcc-fixture`
# plugin. The guard resolves its repo root from its own location, so it
# never sees the real tree. BCC_DB_* are unset so the live checks self-skip.
# Every case also asserts the guard saw the 2 declared fixture tables: a
# harness that parses nothing must not read as green.

set -uo pipefail

HARNESS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GUARD="$(cd "$HARNESS_DIR/.." && pwd)/schema-drift-guard.php"

[ -f "$GUARD" ] || { echo "harness: cannot find $GUARD" >&2; exit 2; }
command -v php >/dev/null 2>&1 || { echo "harness: php not available" >&2; exit 2; }

unset BCC_DB_HOST BCC_DB_PORT BCC_DB_USER BCC_DB_PASS BCC_DB_NAME

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

PASSED=0
FAILED=0

# Inventory rows. alpha + beta are declared by the fixture plugin; gone_o and
# gone_r are not declared anywhere (a genuine ORPHAN and a genuine RETIRED).
ROW_ALPHA='| wp_bcc_alpha | 0 | Alpha table | schema.php | Active |'
ROW_BETA='| wp_bcc_beta | 0 | Beta table | schema.php | Active |'
ROW_GONE_O='| wp_bcc_gone_o | 0 | Removed long ago | none | ORPHAN |'
ROW_GONE_R='| wp_bcc_gone_r | 0 | Dropped by a migration | none | RETIRED |'

# make_root <dir> <inventory rows (newline-separated)>
make_root() {
  local root="$1" rows="$2"
  mkdir -p "$root/scripts" "$root/docs" "$root/app/public/wp-content/plugins/bcc-fixture"
  cp "$GUARD" "$root/scripts/schema-drift-guard.php"
  {
    printf '# Fixture schema\n\n## Master inventory\n\n'
    printf '| Table | ~rows | Purpose | Owning code | Status |\n'
    printf '|---|---|---|---|---|\n'
    printf '%s\n' "$rows"
  } > "$root/docs/database-schema.md"
  cat > "$root/app/public/wp-content/plugins/bcc-fixture/schema.php" <<'PHP'
<?php
function bcc_fixture_install(): void {
    global $wpdb;
    $alpha = $wpdb->prefix . 'bcc_alpha';
    $beta  = $wpdb->prefix . 'bcc_beta';
    $sql = "CREATE TABLE {$alpha} (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        PRIMARY KEY  (id)
    ) ENGINE=InnoDB;";
    $sql2 = "CREATE TABLE {$beta} (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        PRIMARY KEY  (id)
    ) ENGINE=InnoDB;";
}
PHP
}

# add_window_entry <root> <bare table>
add_window_entry() {
  php -r '
    $f = $argv[1];
    $s = file_get_contents($f);
    $n = 0;
    $s = str_replace("const RETIREMENT_WINDOW = [", "const RETIREMENT_WINDOW = [\n    '\''" . $argv[2] . "'\'' => '\''fixture window'\'',", $s, $n);
    if ($n !== 1) { fwrite(STDERR, "harness: RETIREMENT_WINDOW anchor not found exactly once\n"); exit(2); }
    file_put_contents($f, $s);
  ' "$1/scripts/schema-drift-guard.php" "$2" || exit 2
}

# run_case <name> <expected exit> <inventory rows> [required output fragment] [window entry]
run_case() {
  local name="$1" want="$2" rows="$3" needle="${4:-}" window="${5:-}"
  local root="$TMP/case$((PASSED + FAILED))"
  make_root "$root" "$rows"
  [ -n "$window" ] && add_window_entry "$root" "$window"

  local out code
  out="$(php "$root/scripts/schema-drift-guard.php" 2>&1)"
  code=$?

  local why=""
  [ "$code" = "$want" ] || why="exit $code, want $want"
  if [ -z "$why" ] && ! printf '%s' "$out" | grep -q 'Declared  : 2 tables'; then
    why="guard did not see the 2 fixture declarations (vacuous run)"
  fi
  if [ -z "$why" ] && [ -n "$needle" ] && ! printf '%s' "$out" | grep -qF -- "$needle"; then
    why="output does not contain: $needle"
  fi

  if [ -z "$why" ]; then
    echo "  ok    $name"
    PASSED=$((PASSED + 1))
  else
    echo "  FAIL  $name — $why"
    printf '%s\n' "$out" | sed 's/^/          | /'
    FAILED=$((FAILED + 1))
  fi
}

BASE="$ROW_ALPHA
$ROW_BETA
$ROW_GONE_O
$ROW_GONE_R"

echo "schema-drift-guard: inventory Status regression cases"

run_case "clean fixture passes; genuine ORPHAN + RETIRED rows (undeclared) pass" 0 "$BASE" \
  'Documented: 4 tables (2 Active, 1 ORPHAN, 1 RETIRED)'

run_case "declared table relabelled ORPHAN fails and names it" 1 \
  "${BASE/"$ROW_ALPHA"/"${ROW_ALPHA/Active/ORPHAN}"}" \
  "documented-ORPHAN 'wp_bcc_alpha' is STILL declared"

run_case "declared table relabelled RETIRED fails and names it" 1 \
  "${BASE/"$ROW_ALPHA"/"${ROW_ALPHA/Active/RETIRED}"}" \
  "documented-RETIRED 'wp_bcc_alpha' is STILL declared"

run_case "deleting a declared table's row fails and names it" 1 \
  "$ROW_BETA
$ROW_GONE_O
$ROW_GONE_R" \
  "declared table 'wp_bcc_alpha' is NOT in docs/database-schema.md"

run_case "documented-Active but undeclared still fails (1a unchanged)" 1 \
  "$BASE
| wp_bcc_ghost | 0 | Never declared | none | Active |" \
  "documented-Active 'wp_bcc_ghost' is NOT declared"

run_case "the word ORPHAN in the Purpose cell does not reclassify" 0 \
  "${BASE/"Alpha table"/"Not an ORPHAN, not RETIRED"}" \
  '(2 Active, 1 ORPHAN, 1 RETIRED)'

run_case "lower-case 'orphan' Status is unrecognised (exact match)" 1 \
  "${BASE/"$ROW_GONE_O"/"${ROW_GONE_O/ORPHAN/orphan}"}" \
  "'wp_bcc_gone_o' has unrecognised Status 'orphan'"

run_case "decorated '**RETIRED** — note' Status is unrecognised" 1 \
  "${BASE/"$ROW_GONE_R"/"${ROW_GONE_R/RETIRED/**RETIRED** — dropped by x.php}"}" \
  "'wp_bcc_gone_r' has unrecognised Status"

run_case "a declared table with free-text Status containing 'orphan' fails" 1 \
  "${BASE/"$ROW_ALPHA"/"${ROW_ALPHA/Active/orphaned soon}"}" \
  "'wp_bcc_alpha' has unrecognised Status 'orphaned soon'"

run_case "a duplicate inventory row fails" 1 \
  "$BASE
${ROW_ALPHA/Active/ORPHAN}" \
  "'wp_bcc_alpha' appears more than once"

run_case "RETIRED + declared + RETIREMENT_WINDOW entry is tolerated (INFO)" 0 \
  "${BASE/"$ROW_ALPHA"/"${ROW_ALPHA/Active/RETIRED}"}" \
  "[retirement-window] RETIRED 'wp_bcc_alpha' still declared" bcc_alpha

run_case "ORPHAN + declared fails even with a RETIREMENT_WINDOW entry" 1 \
  "${BASE/"$ROW_ALPHA"/"${ROW_ALPHA/Active/ORPHAN}"}" \
  "documented-ORPHAN 'wp_bcc_alpha' is STILL declared" bcc_alpha

run_case "stale window entry (table no longer declared) fails" 1 "$BASE" \
  "RETIREMENT_WINDOW entry 'wp_bcc_gone_r' is stale — the table is no longer declared" bcc_gone_r

run_case "stale window entry (row is Active, not RETIRED) fails" 1 "$BASE" \
  "RETIREMENT_WINDOW entry 'wp_bcc_beta' is stale — its inventory Status is 'Active'" bcc_beta

echo "─────────────────────────────────────────────────────────────────────────"
if [ "$FAILED" = "0" ]; then
  echo "ALL GREEN  ${PASSED} case(s)"
  exit 0
fi
echo "FAILED  ${FAILED} of $((PASSED + FAILED)) case(s)"
exit 1
