#!/usr/bin/env bash
#
# Regression harness for scripts/schema-drift-guard.php CHECK 3 — static
# column parity between each table's schema code and its column block in
# docs/database-schema.md (issue #132).
#
# ── WHY THIS EXISTS ──────────────────────────────────────────────────────
# The guard compared table NAMES and index tuples only. Twenty column drifts
# accumulated across eight tables (columns dropped by migrations but still
# documented, columns added in code but never documented) and eight Active
# tables had no column block at all, with CI green throughout.
#
# These cases pin the check in BOTH directions and pin every doc / code
# idiom it must understand without false-failing a clean tree:
#   doc:  `name(K)`, `a / b · type`, `a · t1 / b · t2`, `a · t; b · t`,
#         struck `~~name~~` (retired in place, still declared), OPTIONAL rows,
#         `*Column dropped:*` history bullets, prose bullets, shared
#         `#### a / b` headings, RETIRED blocks kept as history;
#   code: literal ALTER ADD, migration-map `ADD COLUMN {$column} {$ddl}`,
#         `{$clause}` fragment maps, CREATE TABLE … LIKE, and the three
#         things that must NOT declare a column: a comment, a tests/ file
#         and a hand-run scripts/ file.
#
# ── NO DB, NO SIBLING REPOS ──────────────────────────────────────────────
# Same model as schema-drift-guard.test.sh: a COPY of the guard runs in a
# throwaway fixture root with a minimal doc and a `bcc-fixture` plugin.
# Every case asserts the guard saw the 3 fixture tables, so a harness that
# parses nothing cannot read as green.

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
CASE_ROOT=""

# new_root — a fresh fixture root in $CASE_ROOT.
new_root() {
  CASE_ROOT="$TMP/case$((PASSED + FAILED))"
  local root="$CASE_ROOT" plugin="$CASE_ROOT/app/public/wp-content/plugins/bcc-fixture"
  mkdir -p "$root/scripts" "$root/docs" "$plugin/tests" "$plugin/scripts"
  cp "$GUARD" "$root/scripts/schema-drift-guard.php"

  cat > "$root/docs/database-schema.md" <<'MD'
# Fixture schema

## Master inventory

| Table | ~rows | Purpose | Owning code | Status |
|---|---|---|---|---|
| wp_bcc_alpha | 0 | Alpha | schema.php | Active |
| wp_bcc_beta | 0 | Beta | schema.php | Active |
| wp_bcc_beta_archive | 0 | Beta archive (LIKE beta) | schema.php | Active |
| wp_bcc_gone_r | 0 | Dropped by a migration | none | RETIRED |

## Active tables — per-table detail

Legend: column rows are `name · type · null · key`.

### Fixture group

#### wp_bcc_alpha
Alpha purpose.
- id · bigint unsigned · NO · PK auto
- name · varchar(60) · NO; flag(K) · tinyint(1) · NO
- score · int · NO / extra · varchar(5) · YES
- ~~legacy~~ · int · YES — retired in place; still in the CREATE TABLE
- mapped_a / mapped_b · varchar(10) · YES — added by the migration map
- frag_c · int · YES
- slot · varchar(41) · YES · UQ · OPTIONAL — only scripts/add-slot.php adds it
- *Column dropped:* `old_col` was dropped by drop-old.php.
- Indexes: PRIMARY (id) [uq]; idx_flag (flag)
- A prose bullet about alpha, with no column lead.

#### wp_bcc_beta / wp_bcc_beta_archive
- id · bigint unsigned · NO · PK auto
- label · varchar(20) · NO
- created_at · datetime · NO

#### wp_bcc_gone_r
Kept as history; RETIRED in the inventory, so never compared.
- bogus · int · NO

## After the detail section
- not_a_column · int · NO
MD

  cat > "$plugin/schema.php" <<'PHP'
<?php
function bcc_fixture_alpha_map_columns(): array {
    return [
        'mapped_a' => "VARCHAR(10) DEFAULT NULL",
        'mapped_b' => "INT NOT NULL DEFAULT 0",
    ];
}
function bcc_fixture_install(): void {
    global $wpdb;
    $alpha   = $wpdb->prefix . 'bcc_alpha';
    $beta    = $wpdb->prefix . 'bcc_beta';
    $archive = $wpdb->prefix . 'bcc_beta_archive';
    $sql = "CREATE TABLE {$alpha} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(60) NOT NULL,
        flag TINYINT(1) NOT NULL DEFAULT 0,
        score INT NOT NULL DEFAULT 0,
        legacy INT DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY idx_flag (flag)
    ) ENGINE=InnoDB;";
    $sql2 = "CREATE TABLE {$beta} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        label VARCHAR(20) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id)
    ) ENGINE=InnoDB;";
    $wpdb->query("CREATE TABLE IF NOT EXISTS $archive LIKE $beta");
    // A comment is not a migration: ALTER TABLE {$alpha} ADD COLUMN phantom INT NULL
    $wpdb->query("ALTER TABLE {$alpha} ADD COLUMN extra VARCHAR(5) NULL");
    foreach (bcc_fixture_alpha_map_columns() as $column => $ddl) {
        $wpdb->query("ALTER TABLE {$alpha} ADD COLUMN {$column} {$ddl}");
    }
    $columns = [
        'frag_c' => 'ADD COLUMN frag_c INT NULL',
    ];
    foreach ($columns as $column => $clause) {
        $wpdb->query("ALTER TABLE {$alpha} {$clause}");
    }
}
PHP

  cat > "$plugin/tests/AlterFixtureTest.php" <<'PHP'
<?php
function bcc_fixture_test_setup(): void {
    global $wpdb;
    $alpha = $wpdb->prefix . 'bcc_alpha';
    $wpdb->query("ALTER TABLE {$alpha} ADD COLUMN test_only INT NULL");
}
PHP

  cat > "$plugin/scripts/add-slot.php" <<'PHP'
<?php
global $wpdb;
$t = $wpdb->prefix . 'bcc_alpha';
$wpdb->query("ALTER TABLE {$t} ADD COLUMN slot VARCHAR(41) NULL");
PHP
}

# mutate <path relative to the case root> <search> <replace> — exactly once.
mutate() {
  php -r '
    $f = $argv[1];
    $s = file_get_contents($f);
    $n = substr_count($s, $argv[2]);
    if ($n !== 1) { fwrite(STDERR, "harness: anchor found {$n}x in " . basename($f) . ": " . $argv[2] . "\n"); exit(2); }
    file_put_contents($f, str_replace($argv[2], $argv[3], $s));
  ' "$CASE_ROOT/$1" "$2" "$3" || { echo "harness: mutation failed" >&2; exit 2; }
}

DOC="docs/database-schema.md"
CODE="app/public/wp-content/plugins/bcc-fixture/schema.php"

# check <name> <expected exit> [required output fragment]
check() {
  local name="$1" want="$2" needle="${3:-}"
  local out code
  out="$(php "$CASE_ROOT/scripts/schema-drift-guard.php" 2>&1)"
  code=$?

  local why=""
  [ "$code" = "$want" ] || why="exit $code, want $want"
  if [ -z "$why" ] && ! printf '%s' "$out" | grep -q 'Declared  : 3 tables'; then
    why="guard did not see the 3 fixture declarations (vacuous run)"
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

echo "schema-drift-guard: column parity (CHECK 3) regression cases"

# ── clean ────────────────────────────────────────────────────────────────
# alpha 9 (5 CREATE + extra + mapped_a/b + frag_c) + beta 3 + archive 3 = 15.
new_root
check "clean fixture passes (every doc idiom + every code idiom)" 0 \
  '3 documented-Active tables / 15 declared columns compared'

new_root
php -r '$f=$argv[1]; file_put_contents($f, str_replace("\n", "\r\n", file_get_contents($f)));' "$CASE_ROOT/$DOC"
check "clean fixture passes with a CRLF doc" 0 '15 declared columns compared'

# ── declared ⇒ documented ────────────────────────────────────────────────
new_root
mutate "$DOC" '- frag_c · int · YES' '- frag_x · int · YES'
check "planted drift: declared column not documented fails (names it + file:line)" 1 \
  "declared column 'wp_bcc_alpha.frag_c' is NOT documented — declared at bcc-fixture/schema.php:"

new_root
mutate "$DOC" '- mapped_a / mapped_b' '- mapped_b'
check "migration-map ADD COLUMN {\$column} {\$ddl} declares its keys" 1 \
  "declared column 'wp_bcc_alpha.mapped_a' is NOT documented"

new_root
mutate "$DOC" '- score · int · NO / extra · varchar(5) · YES' '- score · int · NO'
check "literal ALTER ADD declares its column" 1 \
  "declared column 'wp_bcc_alpha.extra' is NOT documented"

new_root
mutate "$CODE" '        label VARCHAR(20) NOT NULL,' $'        label VARCHAR(20) NOT NULL,\n        note TEXT NULL,'
check "CREATE TABLE … LIKE inherits the source's columns" 1 \
  "declared column 'wp_bcc_beta_archive.note' is NOT documented"

# ── documented ⇒ declared ────────────────────────────────────────────────
new_root
mutate "$DOC" '- frag_c · int · YES' $'- frag_c · int · YES\n- ghost_col · int · NO'
check "planted drift: documented column not declared fails (names it + doc line)" 1 \
  "documented column 'wp_bcc_alpha.ghost_col' (doc:"

new_root
mutate "$CODE" '        flag TINYINT(1) NOT NULL DEFAULT 0,' ''
check "name(K) is parsed as the column name" 1 \
  "documented column 'wp_bcc_alpha.flag'"

new_root
mutate "$CODE" '        legacy INT DEFAULT NULL,' ''
check "a ~~struck~~ column must still be declared" 1 \
  "documented column 'wp_bcc_alpha.legacy'"

new_root
mutate "$DOC" '- frag_c · int · YES' $'- frag_c · int · YES\n- phantom · int · YES'
check "an ALTER inside a comment declares nothing" 1 \
  "documented column 'wp_bcc_alpha.phantom'"

new_root
mutate "$DOC" '- frag_c · int · YES' $'- frag_c · int · YES\n- test_only · int · YES'
check "an ALTER in a tests/ file declares nothing" 1 \
  "documented column 'wp_bcc_alpha.test_only'"

# ── OPTIONAL ─────────────────────────────────────────────────────────────
new_root
mutate "$DOC" '· UQ · OPTIONAL —' '· UQ —'
check "a scripts/-only column without OPTIONAL fails (hand-run ALTERs declare nothing)" 1 \
  "documented column 'wp_bcc_alpha.slot'"

new_root
mutate "$DOC" '- slot · varchar(41) · YES · UQ · OPTIONAL — only scripts/add-slot.php adds it' ''
check "a scripts/-only column needs no doc row at all" 0

new_root
mutate "$CODE" '        legacy INT DEFAULT NULL,' $'        legacy INT DEFAULT NULL,\n        slot VARCHAR(41) NULL,'
check "an OPTIONAL row that shipped schema declares fails" 1 \
  "'wp_bcc_alpha.slot' is documented OPTIONAL"

# ── coverage ─────────────────────────────────────────────────────────────
new_root
mutate "$DOC" '#### wp_bcc_beta / wp_bcc_beta_archive' '#### wp_bcc_beta'
check "an Active table with no column block fails" 1 \
  "'wp_bcc_beta_archive' has no column block"

new_root
mutate "$DOC" '#### wp_bcc_gone_r' $'#### wp_bcc_beta\n- id · bigint unsigned · NO\n\n#### wp_bcc_gone_r'
check "a second column block for the same table fails" 1 \
  "'wp_bcc_beta' has more than one column block"

new_root
mutate "$DOC" '#### wp_bcc_alpha' '#### wp_bcc_alpah'
check "a typo'd heading fails twice: the real block is missing, the typo is named" 1 \
  "column block 'wp_bcc_alpah' (doc:"

new_root
mutate "$CODE" '        $wpdb->query("ALTER TABLE {$alpha} {$clause}");' \
  $'        $wpdb->query("ALTER TABLE {$alpha} {$clause}");\n    }\n    foreach (bcc_fixture_unknown() as $c => $d) {\n        $wpdb->query("ALTER TABLE {$alpha} ADD COLUMN {$c} {$d}");'
check "an ADD COLUMN {\$var} that cannot be resolved fails (never a skip)" 1 \
  'could not be resolved to a literal column list'

new_root
mutate "$CODE" "'ADD COLUMN frag_c INT NULL'" "'MODIFY frag_c INT NULL'"
check "a {\$clause} map whose fragment is not an ADD COLUMN fails" 1 \
  'could not be resolved to a literal column list'

new_root
mutate "$CODE" '    $wpdb->query("ALTER TABLE {$alpha} ADD COLUMN extra VARCHAR(5) NULL");' \
  $'    $wpdb->query("ALTER TABLE {$alpha} ADD COLUMN extra VARCHAR(5) NULL");\n    $wpdb->query("ALTER TABLE {$alpha} DROP COLUMN score");'
check "dropping a column the schema still creates fails" 1 \
  "wp_bcc_alpha.score is dropped at bcc-fixture/schema.php:"

# ── RETIRED blocks are history ───────────────────────────────────────────
new_root
mutate "$DOC" '| wp_bcc_alpha | 0 | Alpha | schema.php | Active |' '| wp_bcc_alpha | 0 | Alpha | schema.php | RETIRED |'
check "a RETIRED table's block is not compared (its 1c failure is the only finding)" 1 \
  "Drift findings (fail) : 1"

echo "─────────────────────────────────────────────────────────────────────────"
if [ "$FAILED" = "0" ]; then
  echo "ALL GREEN  ${PASSED} case(s)"
  exit 0
fi
echo "FAILED  ${FAILED} of $((PASSED + FAILED)) case(s)"
exit 1
