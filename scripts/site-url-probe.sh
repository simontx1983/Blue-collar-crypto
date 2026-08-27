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
# the wp-config CONSTANTS. The drift is the delta between them: WordPress
# ignores those rows whenever the constants are defined, so the rows can name
# another environment indefinitely while the site serves correctly.
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

get() { jq -er "$1" <<<"$json" 2>/dev/null; }

ep_env=$(get '.env')            || fail_check "unparseable response body"
row_s=$(get '.rows.siteurl // "(null)"')      || fail_check "unparseable response body"
row_h=$(get '.rows.home // "(null)"')         || fail_check "unparseable response body"
con_s=$(get '.constants.siteurl // "(unset)"')|| fail_check "unparseable response body"
con_h=$(get '.constants.home // "(unset)"')   || fail_check "unparseable response body"
count=$(get '.options_table_count')           || fail_check "unparseable response body"

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
printf '  siteurl  rows=%-40s constants=%-40s expected=%s\n' "$row_s" "$con_s" "$want_siteurl"
printf '  home     rows=%-40s constants=%-40s expected=%s\n' "$row_h" "$con_h" "$want_home"

# >1 means multisite, two installs sharing a database, or a plugin table ending
# in "options". All need a human on-host; none should be guessed at from here.
[ "$count" = "1" ] || fail_check "options_table_count=${count} — investigate on-host"

drift=0
[ "${row_s%/}" = "$want_siteurl" ] || { echo "::error::siteurl ROW drift (${row_s})"; drift=1; }
[ "${row_h%/}" = "$want_home"    ] || { echo "::error::home ROW drift (${row_h})";    drift=1; }

# A drifted CONSTANT is a different and louder failure than a drifted row: the
# site is actively SERVING the wrong identity, not merely storing it.
[ "${con_s%/}" = "$want_siteurl" ] || { echo "::error::siteurl CONSTANT drift (${con_s}) — this host is SERVING the wrong identity"; drift=1; }
[ "${con_h%/}" = "$want_home"    ] || { echo "::error::home CONSTANT drift (${con_h}) — this host is SERVING the wrong identity";    drift=1; }

if [ "$drift" = "0" ]; then
  echo "PASS: ${BCC_ENV} identity matches (rows and constants)."
  exit 0
fi

echo "FAIL: identity drift on ${BCC_ENV}. Correct the DB row / wp-config — do NOT edit this probe."
exit 1
