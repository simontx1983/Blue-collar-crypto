#!/usr/bin/env bash
#
# Regression harness for scripts/site-url-probe.sh.
#
# ── WHY THIS EXISTS ──────────────────────────────────────────────────────
# The probe shipped with two defects that were both invisible in CI for as
# long as BCC_INTERNAL_SECRET was unset, because it bailed before reaching
# either one:
#
#   1. It read the six fields from the response ROOT. Every bcc endpoint
#      wraps its payload in {"data":…,"_meta":…}, so this could never have
#      worked against a real host.
#   2. It defaulted a null constant to the literal "(unset)" and then
#      compared that to the expected URL. Staging defines neither
#      WP_SITEURL nor WP_HOME, so staging was guaranteed to report constant
#      drift the moment defect 1 was fixed — one bug hiding behind another.
#
# A guard that has never successfully parsed a real response is not a
# guard. These cases pin the shape of the response AND the meaning of an
# undefined constant, so neither can regress silently again.
#
# ── NO NETWORK, NO SECRET ────────────────────────────────────────────────
# `curl` is shadowed by a fake earlier on PATH that prints a fixture and a
# status code. Nothing here resolves a hostname, and the "secret" is an
# obvious non-credential placeholder.

set -uo pipefail

HARNESS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROBE="$(cd "$HARNESS_DIR/.." && pwd)/site-url-probe.sh"

[ -f "$PROBE" ] || { echo "harness: cannot find $PROBE" >&2; exit 2; }
command -v jq >/dev/null 2>&1 || { echo "harness: jq not available" >&2; exit 2; }

# Obviously not a credential. Asserted absent from output at the end.
FIXTURE_SECRET='fixture-not-a-real-secret'
FIXTURE_REQUEST_ID='fixture-request-id-must-not-print'

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# ── The fake curl ────────────────────────────────────────────────────────
# Mirrors the real invocation's contract: body, newline, HTTP code on the
# last line (the probe uses `-w '\n%{http_code}'`). FAKE_CURL_EXIT lets a
# case simulate a transport failure.
mkdir -p "$TMP/bin"
cat > "$TMP/bin/curl" <<'FAKE'
#!/usr/bin/env bash
if [ "${FAKE_CURL_EXIT:-0}" != "0" ]; then
  echo "curl: (6) Could not resolve host" >&2
  exit "${FAKE_CURL_EXIT}"
fi
printf '%s\n%s' "${FAKE_CURL_BODY:-}" "${FAKE_CURL_CODE:-200}"
FAKE
chmod +x "$TMP/bin/curl"

PASSED=0
FAILED=0
ALL_OUTPUT=""

# run <name> <expected_exit> <env> <body> <code> [curl_exit]
run() {
  local name="$1" want="$2" env="$3" body="$4" code="$5" curl_exit="${6:-0}"
  local out rc

  out=$(
    PATH="$TMP/bin:$PATH" \
    BCC_ENV="$env" \
    BCC_IDENTITY_BASE="https://identity.invalid" \
    BCC_INTERNAL_SECRET="$FIXTURE_SECRET" \
    FAKE_CURL_BODY="$body" \
    FAKE_CURL_CODE="$code" \
    FAKE_CURL_EXIT="$curl_exit" \
    bash "$PROBE" 2>&1
  )
  rc=$?

  ALL_OUTPUT="${ALL_OUTPUT}${out}"

  if [ "$rc" = "$want" ]; then
    printf '  ok    %-58s exit %s\n' "$name" "$rc"
    PASSED=$((PASSED + 1))
  else
    printf '  FAIL  %-58s exit %s (wanted %s)\n' "$name" "$rc" "$want"
    printf '%s\n' "$out" | sed 's/^/          | /'
    FAILED=$((FAILED + 1))
  fi
}

meta() { printf '"_meta":{"request_id":"%s"}' "$FIXTURE_REQUEST_ID"; }

# Canonical envelope builder: rows always strings; constants may be null.
envelope() { # <env> <row_s> <row_h> <con_s_json> <con_h_json> <count>
  printf '{"data":{"env":"%s","rows":{"siteurl":"%s","home":"%s"},"constants":{"siteurl":%s,"home":%s},"options_table_count":%s},%s}' \
    "$1" "$2" "$3" "$4" "$5" "$6" "$(meta)"
}

PROD_S="https://cms.bluecollarcrypto.io"
PROD_H="https://bluecollarcrypto.io"
STAGE_URL="https://stage.bluecollarcrypto.io"

echo "site-url-probe regression harness"
echo "─────────────────────────────────────────────────────────────────────────"

# ── Healthy: the two shapes that actually exist in production today ──────
run "production: enveloped, both constants defined and matching" 0 production \
  "$(envelope production "$PROD_S" "$PROD_H" "\"$PROD_S\"" "\"$PROD_H\"" 1)" 200

run "staging: enveloped, BOTH constants null (unset is not drift)" 0 staging \
  "$(envelope staging "$STAGE_URL" "$STAGE_URL" null null 1)" 200

# The partial-cutover shape. Judged per constant, not as a pair.
run "mixed: siteurl defined+matching, home unset" 0 production \
  "$(envelope production "$PROD_S" "$PROD_H" "\"$PROD_S\"" null 1)" 200

run "mixed: siteurl unset, home defined+matching" 0 production \
  "$(envelope production "$PROD_S" "$PROD_H" null "\"$PROD_H\"" 1)" 200

# A trailing slash is cosmetic; the probe strips one before comparing.
run "trailing slash on a row is tolerated" 0 staging \
  "$(envelope staging "$STAGE_URL/" "$STAGE_URL" null null 1)" 200

# ── Real drift: exit 1 ───────────────────────────────────────────────────
run "wrong ROW is drift" 1 production \
  "$(envelope production "$STAGE_URL" "$PROD_H" "\"$PROD_S\"" "\"$PROD_H\"" 1)" 200

run "wrong DEFINED constant is drift" 1 production \
  "$(envelope production "$PROD_S" "$PROD_H" "\"$STAGE_URL\"" "\"$PROD_H\"" 1)" 200

run "wrong defined constant is drift even when its row is right" 1 staging \
  "$(envelope staging "$STAGE_URL" "$STAGE_URL" null "\"$PROD_H\"" 1)" 200

# ── Cannot check: exit 2 ─────────────────────────────────────────────────
run "endpoint reports the OTHER environment" 2 production \
  "$(envelope staging "$STAGE_URL" "$STAGE_URL" null null 1)" 200

run "endpoint reports env=unknown" 2 production \
  "$(envelope unknown "$PROD_S" "$PROD_H" "\"$PROD_S\"" "\"$PROD_H\"" 1)" 200

run "options_table_count != 1" 2 production \
  "$(envelope production "$PROD_S" "$PROD_H" "\"$PROD_S\"" "\"$PROD_H\"" 2)" 200

run "options_table_count is not a number" 2 production \
  "$(printf '{"data":{"env":"production","rows":{"siteurl":"%s","home":"%s"},"constants":{"siteurl":null,"home":null},"options_table_count":"1"},%s}' "$PROD_S" "$PROD_H" "$(meta)")" 200

run "malformed JSON" 2 production '{"data":{"env":"production"' 200

run "empty body" 2 production '' 200

run "missing .data" 2 production \
  "$(printf '{"env":"production","rows":{"siteurl":"%s","home":"%s"},"constants":{"siteurl":null,"home":null},"options_table_count":1}' "$PROD_S" "$PROD_H")" 200

run ".data present but not an object" 2 production \
  "$(printf '{"data":"production",%s}' "$(meta)")" 200

run ".data is null" 2 production "$(printf '{"data":null,%s}' "$(meta)")" 200

# The pre-fix shape. Explicitly NOT accepted as a fallback.
run "obsolete flat response shape is rejected" 2 production \
  "$(printf '{"env":"production","rows":{"siteurl":"%s","home":"%s"},"constants":{"siteurl":"%s","home":"%s"},"options_table_count":1}' "$PROD_S" "$PROD_H" "$PROD_S" "$PROD_H")" 200

run "missing rows key" 2 production \
  "$(printf '{"data":{"env":"production","constants":{"siteurl":null,"home":null},"options_table_count":1},%s}' "$(meta)")" 200

run "missing constants key" 2 production \
  "$(printf '{"data":{"env":"production","rows":{"siteurl":"%s","home":"%s"},"options_table_count":1},%s}' "$PROD_S" "$PROD_H" "$(meta)")" 200

run "constants object missing the home key" 2 production \
  "$(printf '{"data":{"env":"production","rows":{"siteurl":"%s","home":"%s"},"constants":{"siteurl":null},"options_table_count":1},%s}' "$PROD_S" "$PROD_H" "$(meta)")" 200

run "row of the wrong type" 2 production \
  "$(printf '{"data":{"env":"production","rows":{"siteurl":123,"home":"%s"},"constants":{"siteurl":null,"home":null},"options_table_count":1},%s}' "$PROD_H" "$(meta)")" 200

run "constant of the wrong type" 2 production \
  "$(printf '{"data":{"env":"production","rows":{"siteurl":"%s","home":"%s"},"constants":{"siteurl":42,"home":null},"options_table_count":1},%s}' "$PROD_S" "$PROD_H" "$(meta)")" 200

# ── Transport and HTTP status ────────────────────────────────────────────
run "HTTP 401" 2 production "$(envelope production "$PROD_S" "$PROD_H" null null 1)" 401
run "HTTP 404" 2 production "$(envelope production "$PROD_S" "$PROD_H" null null 1)" 404
run "HTTP 500" 2 production "$(envelope production "$PROD_S" "$PROD_H" null null 1)" 500
run "transport failure" 2 production "" 000 6

# ── Misconfiguration fails before any request ────────────────────────────
no_request() { # <name> <want> <env> <base> <secret>
  local name="$1" want="$2" out rc
  out=$(
    PATH="$TMP/bin:$PATH" BCC_ENV="$3" BCC_IDENTITY_BASE="$4" BCC_INTERNAL_SECRET="$5" \
    FAKE_CURL_BODY='SHOULD_NEVER_BE_REACHED' FAKE_CURL_CODE=200 \
    bash "$PROBE" 2>&1
  ); rc=$?
  ALL_OUTPUT="${ALL_OUTPUT}${out}"
  if [ "$rc" = "$want" ] && ! printf '%s' "$out" | grep -q 'SHOULD_NEVER_BE_REACHED'; then
    printf '  ok    %-58s exit %s\n' "$name" "$rc"; PASSED=$((PASSED + 1))
  else
    printf '  FAIL  %-58s exit %s (wanted %s, or a request was made)\n' "$name" "$rc" "$want"
    FAILED=$((FAILED + 1))
  fi
}

no_request "missing secret fails before any request" 2 production "https://identity.invalid" ""
no_request "missing identity base fails before any request" 2 production "" "$FIXTURE_SECRET"
no_request "unknown BCC_ENV fails before any request" 2 testnet "https://identity.invalid" "$FIXTURE_SECRET"
no_request "empty BCC_ENV fails before any request" 2 "" "https://identity.invalid" "$FIXTURE_SECRET"

# `prod` is a banner-only alias; the probe must keep demanding `production`.
no_request "legacy 'prod' alias is not accepted here" 2 prod "https://identity.invalid" "$FIXTURE_SECRET"

# ── POSITIVE CONTROLS: prove this harness is not vacuously green ─────────
#
# Every case above asserts that a CORRECT probe behaves correctly. None of
# them can tell the difference between "the probe is right" and "the harness
# is asserting nothing" — and guards in this repo have printed PASS having
# scanned nothing before.
#
# So: reintroduce each original defect into a throwaway copy and require the
# corresponding case to FAIL. If a mutant still passes, the case that is
# supposed to pin that defect is decorative, and this harness says so out
# loud rather than reporting ALL GREEN.
#
# The mutation itself is verified to have applied. A sed that silently
# matched nothing would produce an unmodified copy that passes for the right
# reason — which is the same false green, one level up.
echo "─────────────────────────────────────────────────────────────────────────"

# control <name> <sed_expr> <env> <body>
control() {
  local name="$1" expr="$2" env="$3" body="$4" mutant="$TMP/mutant.sh" out rc

  sed "$expr" "$PROBE" > "$mutant"
  if cmp -s "$PROBE" "$mutant"; then
    printf '  FAIL  %-58s %s\n' "control: $name" "mutation did not apply"
    FAILED=$((FAILED + 1))
    return
  fi

  out=$(
    PATH="$TMP/bin:$PATH" BCC_ENV="$env" \
    BCC_IDENTITY_BASE="https://identity.invalid" \
    BCC_INTERNAL_SECRET="$FIXTURE_SECRET" \
    FAKE_CURL_BODY="$body" FAKE_CURL_CODE=200 FAKE_CURL_EXIT=0 \
    bash "$mutant" 2>&1
  )
  rc=$?
  ALL_OUTPUT="${ALL_OUTPUT}${out}"

  if [ "$rc" != "0" ]; then
    printf '  ok    %-58s exit %s\n' "control: $name" "$rc"
    PASSED=$((PASSED + 1))
  else
    printf '  FAIL  %-58s %s\n' "control: $name" "mutant still passed — case is decorative"
    FAILED=$((FAILED + 1))
  fi
}

# Defect 1: read the six fields from the response ROOT instead of `.data`.
# The canonical production envelope must then stop verifying.
control "root-reading parser rejects the real envelope" \
  's|(\.data // null) as \$d|(. // null) as $d|' \
  production "$(envelope production "$PROD_S" "$PROD_H" "\"$PROD_S\"" "\"$PROD_H\"" 1)"

# Defect 2: compare an undefined constant unconditionally. Staging, which
# defines neither, must then report drift again.
control "unconditional constant compare breaks staging" \
  's|if \[ "\$con_s_defined" = "1" \]; then|if true; then|; s|if \[ "\$con_h_defined" = "1" \]; then|if true; then|' \
  staging "$(envelope staging "$STAGE_URL" "$STAGE_URL" null null 1)"

# ── Nothing sensitive may reach the output ───────────────────────────────
echo "─────────────────────────────────────────────────────────────────────────"
leak=0
if printf '%s' "$ALL_OUTPUT" | grep -q "$FIXTURE_SECRET"; then
  echo "  FAIL  the secret appeared in probe output"; leak=1
fi
if printf '%s' "$ALL_OUTPUT" | grep -q "$FIXTURE_REQUEST_ID"; then
  echo "  FAIL  _meta.request_id appeared in probe output"; leak=1
fi
if printf '%s' "$ALL_OUTPUT" | grep -q 'X-Bcc-Internal'; then
  echo "  FAIL  the authorization header appeared in probe output"; leak=1
fi
if printf '%s' "$ALL_OUTPUT" | grep -q '"data"'; then
  echo "  FAIL  a raw response body appeared in probe output"; leak=1
fi
if [ "$leak" = "0" ]; then
  echo "  ok    no secret, request id, auth header or response body in output"
  PASSED=$((PASSED + 1))
else
  FAILED=$((FAILED + 1))
fi

echo "─────────────────────────────────────────────────────────────────────────"
if [ "$FAILED" = "0" ]; then
  echo "ALL GREEN  ${PASSED} case(s)"
  exit 0
fi
echo "FAILED  ${FAILED} of $((PASSED + FAILED)) case(s)"
exit 1
