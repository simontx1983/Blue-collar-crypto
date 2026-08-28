#!/usr/bin/env bash
#
# CI-side site-URL drift probe — HTTP transport.
#
# Sibling to scripts/site-url-guard.php, which is the ON-HOST mysqli tool.
# They are two files on purpose: this one must NEVER grow a database
# connection, because that would mean enabling Remote MySQL and allowlisting
# GitHub runner IPs — in practice '%' — to read two rows. Two transports, two
# files, so nobody wires mysqli into Actions later and reopens that door.
#
# Reads GET /bcc/v1/internal/identity, which returns the wp_options ROWS and
# the wp-config CONSTANTS inside the canonical bcc envelope:
#
#   {"data":{"env":…,"rows":{…},"constants":{…},"options_table_count":N},
#    "_meta":{…}}
#
# The drift is the delta between rows and constants: WordPress ignores those
# rows whenever the constants are DEFINED, so the rows can name another
# environment indefinitely while the site serves correctly.
#
# A constant reported as JSON null is UNDEFINED, which is not drift — it
# means WordPress is using the row, and the row is checked on its own.
# Staging legitimately defines neither WP_SITEURL nor WP_HOME.
#
#   BCC_ENV=production|staging
#   BCC_IDENTITY_BASE=https://cms.bluecollarcrypto.io
#   BCC_INTERNAL_SECRET=...      (same value as WP's BCC_INTERNAL_CRON_SECRET)
#
# Exit 0 = agree · 1 = drift · 2 = CANNOT CHECK.
# Exit 2 must fail its job wherever this is automated.
#
# NOTE ON `set -e`: it is deliberately NOT used. Several checks below inspect
# a command's exit status, and -e would kill the script before the check runs.
# Every failure path exits explicitly instead.
set -uo pipefail

fail_check() { echo "CANNOT CHECK — $1" >&2; exit 2; }

case "${BCC_ENV:-}" in
  production)
    want_siteurl="https://cms.bluecollarcrypto.io"
    want_home="https://bluecollarcrypto.io"      # the Vercel apex, on purpose
    ;;
  staging)
    want_siteurl="https://stage.bluecollarcrypto.io"
    want_home="https://stage.bluecollarcrypto.io"
    ;;
  *) fail_check "set BCC_ENV to production|staging (got '${BCC_ENV:-}')" ;;
esac

# Plain emptiness tests. `${VAR:?msg}` would exit the shell with status 1 —
# reporting DRIFT while meaning CANNOT CHECK — and its `|| exit 2` would be
# unreachable.
[ -n "${BCC_IDENTITY_BASE:-}" ]   || fail_check "BCC_IDENTITY_BASE unset"
[ -n "${BCC_INTERNAL_SECRET:-}" ] || fail_check "BCC_INTERNAL_SECRET unset"
command -v jq >/dev/null 2>&1     || fail_check "jq not available"

response=$(curl -sS -m 20 \
    -H "X-Bcc-Internal: ${BCC_INTERNAL_SECRET}" \
    -H 'Cache-Control: no-cache' \
    -w '\n%{http_code}' \
    "${BCC_IDENTITY_BASE}/wp-json/bcc/v1/internal/identity" 2>/dev/null)
[ $? -eq 0 ] || fail_check "request to ${BCC_IDENTITY_BASE} failed"

code=$(printf '%s' "$response" | tail -n1)
json=$(printf '%s' "$response" | sed '$d')

case "$code" in
  200) : ;;
  401) fail_check "HTTP 401 — BCC_INTERNAL_SECRET does not match this host" ;;
  404) fail_check "HTTP 404 — identity endpoint not deployed on this host" ;;
  *)   fail_check "HTTP ${code} from the identity endpoint" ;;
esac

# ── ONE VALIDATION PASS OVER THE CANONICAL ENVELOPE ──────────────────────
#
# Every bcc REST response is wrapped: {"data":{...},"_meta":{...}}. This
# probe used to read the six fields from the response ROOT, which no
# deployed endpoint has ever returned. The bug was unreachable for as long
# as BCC_INTERNAL_SECRET was unset — the script bailed earlier — so the
# first run with working credentials was the first run that could see it.
#
# The flat shape is NOT accepted as a fallback. A response without a
# `.data` object is either an endpoint this probe does not understand or
# something that is not the endpoint at all (a WAF interstitial, a cached
# error page), and guessing at either is how a guard starts reporting
# confident nonsense.
#
# Structure and TYPES are validated here, once, so the comparisons below
# operate on values that are already known to be the right shape. jq exits
# non-zero on any `error(...)`, which becomes CANNOT CHECK.
#
# The two constants are emitted as a defined-flag plus a value rather than
# a sentinel string, so an unset constant can never be confused with a
# constant whose value happens to look like the sentinel.
parsed=$(jq -er '
  (.data // null) as $d
  | if ($d | type) != "object" then error("no .data object") else . end
  | ($d.env)                 as $env
  | ($d.rows)                as $rows
  | ($d.constants)           as $cons
  | ($d.options_table_count) as $cnt
  | if ($env  | type) != "string" or ($env | length) == 0 then error("env")   else . end
  | if ($rows | type) != "object"                         then error("rows")  else . end
  | if ($cons | type) != "object"                         then error("cons")  else . end
  | if ($cnt  | type) != "number"
       or ($cnt != ($cnt | floor)) or $cnt < 0            then error("count") else . end
  | if ($rows.siteurl | type) != "string"                 then error("rows.siteurl") else . end
  | if ($rows.home    | type) != "string"                 then error("rows.home")    else . end
  | if ($cons | has("siteurl")) | not                     then error("constants.siteurl absent") else . end
  | if ($cons | has("home"))    | not                     then error("constants.home absent")    else . end
  | if (($cons.siteurl | type) | . != "string" and . != "null") then error("constants.siteurl type") else . end
  | if (($cons.home    | type) | . != "string" and . != "null") then error("constants.home type")    else . end
  | [ $env,
      $rows.siteurl,
      $rows.home,
      (if $cons.siteurl == null then "0" else "1" end), ($cons.siteurl // ""),
      (if $cons.home    == null then "0" else "1" end), ($cons.home    // ""),
      ($cnt | tostring)
    ] as $out
  | if ($out | map(select(index("\u001f") != null)) | length) > 0
      then error("field contains the field separator") else . end
  | $out | join("\u001f")
' <<<"$json" 2>/dev/null) || fail_check "unparseable response body"

# ── ONE ROW, SPLIT ON A NON-WHITESPACE SEPARATOR ─────────────────────────
#
# US (0x1F), not tab, and one row rather than one value per line.
#
# Tab looks like the obvious choice and is wrong here: tab is IFS
# WHITESPACE in bash, so a run of them collapses into a single delimiter.
# An unset constant is an EMPTY field, and two adjacent empty fields —
# exactly what staging produces, where neither constant is defined — would
# silently shift every later column, putting a URL where the count belongs.
# US is not IFS whitespace, so empty fields survive.
#
# jq has already refused any value containing the separator, so a field
# cannot split in two. A single row also means the split does not depend on
# how the local jq build terminates lines (Windows jq emits CRLF, ubuntu's
# LF) — hence the one trailing-CR trim.
parsed=${parsed%$'\r'}

IFS=$'\x1f' read -r ep_env row_s row_h con_s_defined con_s con_h_defined con_h count <<<"$parsed"

# A short row means jq emitted fewer fields than the contract requires.
[ -n "${count:-}" ] || fail_check "unparseable response body"

# Display forms only — never used for comparison.
con_s_show="(unset)"; [ "$con_s_defined" = "1" ] && con_s_show="$con_s"
con_h_show="(unset)"; [ "$con_h_defined" = "1" ] && con_h_show="$con_h"

# ── THE HOST TELLS US WHO IT IS ───────────────────────────────────────────
# Cross-check before comparing anything. This single test defangs every
# misrouting failure — a wrong repo variable, a bad workflow_dispatch input, a
# fallback that quietly selected the other environment — because otherwise we
# would compare production's expectations against staging's rows and report a
# confident "production DRIFT", sending someone hunting a production problem
# that does not exist.
[ "$ep_env" != "unknown" ] || fail_check "endpoint reports env='unknown' (BCC_ENV not defined on that host)"
[ "$ep_env" = "$BCC_ENV" ] || fail_check "endpoint reports env='${ep_env}', expected '${BCC_ENV}'. Wrong host."

echo "env=${ep_env}  options_table_count=${count}"
printf '  siteurl  rows=%-40s constants=%-40s expected=%s\n' "$row_s" "$con_s_show" "$want_siteurl"
printf '  home     rows=%-40s constants=%-40s expected=%s\n' "$row_h" "$con_h_show" "$want_home"

# >1 means multisite, two installs sharing a database, or a plugin table ending
# in "options". All need a human on-host; none should be guessed at from here.
[ "$count" = "1" ] || fail_check "options_table_count=${count} — investigate on-host"

drift=0
[ "${row_s%/}" = "$want_siteurl" ] || { echo "::error::siteurl ROW drift (${row_s})"; drift=1; }
[ "${row_h%/}" = "$want_home"    ] || { echo "::error::home ROW drift (${row_h})";    drift=1; }

# ── AN UNDEFINED CONSTANT IS NOT DRIFT ───────────────────────────────────
#
# WordPress uses wp_options whenever WP_SITEURL / WP_HOME are undefined, so
# an unset constant means "the row above is the live value" — and that row
# has just been checked. Staging legitimately defines neither.
#
# The previous version defaulted a null constant to the literal string
# "(unset)" and then compared THAT to the expected URL, so it could never
# match: staging was guaranteed to report constant drift on both fields the
# moment the parser was fixed. Two defects, one of them hidden behind the
# other.
#
# Each constant is judged independently, so the mixed case — one defined,
# one not, which is what a partial cutover looks like — is handled without
# a special branch.
#
# A DEFINED constant that disagrees stays a loud drift: the site is
# actively SERVING the wrong identity, not merely storing it.
if [ "$con_s_defined" = "1" ]; then
  [ "${con_s%/}" = "$want_siteurl" ] || { echo "::error::siteurl CONSTANT drift (${con_s}) — this host is SERVING the wrong identity"; drift=1; }
fi
if [ "$con_h_defined" = "1" ]; then
  [ "${con_h%/}" = "$want_home" ] || { echo "::error::home CONSTANT drift (${con_h}) — this host is SERVING the wrong identity"; drift=1; }
fi

if [ "$drift" = "0" ]; then
  echo "PASS: ${BCC_ENV} identity matches (rows match; any defined constants match)."
  exit 0
fi

echo "FAIL: identity drift on ${BCC_ENV}. Correct the DB row / wp-config — do NOT edit this probe."
exit 1
