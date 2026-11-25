#!/usr/bin/env bash
#
# Regression harness for scripts/auth-cache-isolation-probe.sh and the step
# in .github/workflows/staging-cache-probe.yml that runs it.
#
# ── WHY THIS EXISTS ──────────────────────────────────────────────────────
# F-418: staging stopped sending `x-litespeed-cache` on REST around
# 2026-10-19, so the weekly probe failed red as INCONCLUSIVE every week. A
# check that is red for a known, harmless reason stops being read, and the
# day it goes red for a P0 nobody looks. An absent cache marker now ends as
# an explicit SKIPPED (exit 4, green in the workflow). These cases pin:
#   - SKIPPED only when NEITHER anon 2xx answer carries the marker;
#   - the SKIPPED path sends NO Authorization header (not even the dummy);
#   - a marker that is present (hit or miss) runs exactly as before:
#     PASS 0, INCONCLUSIVE 1, P0 FAIL 2;
#   - no responses, or a non-2xx answer, stays INCONCLUSIVE, never SKIPPED;
#   - the production hostname is still refused before any request;
#   - the workflow maps 4 to green with a notice and passes 0/1/2 through.
#
# ── NO NETWORK ───────────────────────────────────────────────────────────
# `curl` is shadowed by a fake earlier on PATH. It answers from a scripted
# list (FAKE_RESPONSES, "code:marker" per request) and logs whether each
# call carried an Authorization header. Nothing resolves a hostname.

set -uo pipefail

HARNESS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HARNESS_DIR/../.." && pwd)"
PROBE="$REPO/scripts/auth-cache-isolation-probe.sh"
WORKFLOW="$REPO/.github/workflows/staging-cache-probe.yml"
DUMMY_BEARER="bcc-cache-isolation-probe-dummy-token"
SKIP_LINE="SKIPPED: staging serves REST uncached; nothing to test."

[ -f "$PROBE" ] || { echo "harness: cannot find $PROBE" >&2; exit 2; }
[ -f "$WORKFLOW" ] || { echo "harness: cannot find $WORKFLOW" >&2; exit 2; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# ── The fake curl ────────────────────────────────────────────────────────
# Mirrors the probe's invocation: `-o /dev/null -D -` prints the response
# HEADERS on stdout. A "000" (or a missing entry) is a transport failure.
mkdir -p "$TMP/bin"
cat > "$TMP/bin/curl" <<'FAKE'
#!/usr/bin/env bash
n=$(( $(cat "$FAKE_STATE/count" 2>/dev/null || echo 0) + 1 ))
echo "$n" > "$FAKE_STATE/count"
kind=anon
for a in "$@"; do
  case "$a" in [Aa]uthorization:*) kind=auth ;; esac
done
echo "$kind" >> "$FAKE_STATE/calls"
spec="$(printf '%s' "${FAKE_RESPONSES:-}" | cut -d, -f"$n")"
code="${spec%%:*}"; marker="${spec#*:}"
if [ -z "$spec" ] || [ "$code" = "000" ]; then
  echo "curl: (7) Failed to connect" >&2
  exit 7
fi
printf 'HTTP/1.1 %s Fake\r\n' "$code"
# Mixed case on purpose: the probe matches the header name case-insensitively.
[ "$marker" != "absent" ] && printf 'X-LiteSpeed-Cache: %s\r\n' "$marker"
printf 'Content-Type: application/json\r\n\r\n'
FAKE
chmod +x "$TMP/bin/curl"

# ── The workflow step's run block, as the runner executes it ─────────────
# Lines under `run: |` of the probe step, de-indented. Fails closed if the
# step's shape changes.
awk '
  /name: Auth-cache isolation probe \(staging\)/ { f = 1; next }
  f && /run: \|/ { r = 1; next }
  r { if ($0 ~ /^          / || $0 ~ /^[[:space:]]*$/) { sub(/^          /, ""); print } else exit }
' "$WORKFLOW" > "$TMP/workflow-step.sh"
grep -q 'bash scripts/auth-cache-isolation-probe.sh' "$TMP/workflow-step.sh" \
  || { echo "harness: could not extract the probe step's run block from $WORKFLOW" >&2; exit 2; }

PASSED=0
FAILED=0
ALL_OUTPUT=""

# run_probe <responses> [env...] — runs the probe; sets OUT, RC, CALLS, AUTHS.
run_probe() {
  local responses="$1"; shift
  rm -rf "$TMP/state"; mkdir -p "$TMP/state"
  : > "$TMP/summary.md"
  OUT="$(cd "$REPO" && env PATH="$TMP/bin:$PATH" FAKE_STATE="$TMP/state" FAKE_RESPONSES="$responses" \
    SPACING_SECONDS=0 GITHUB_STEP_SUMMARY="$TMP/summary.md" "$@" bash "$PROBE" 2>&1)"
  RC=$?
  finish_run
}

# run_step <responses> — runs the workflow's run block the way the runner
# does (bash -e -o pipefail); sets OUT, RC, CALLS, AUTHS.
run_step() {
  local responses="$1"
  rm -rf "$TMP/state"; mkdir -p "$TMP/state"
  : > "$TMP/summary.md"
  OUT="$(cd "$REPO" && env PATH="$TMP/bin:$PATH" FAKE_STATE="$TMP/state" FAKE_RESPONSES="$responses" \
    SPACING_SECONDS=0 GITHUB_STEP_SUMMARY="$TMP/summary.md" bash --noprofile --norc -eo pipefail "$TMP/workflow-step.sh" 2>&1)"
  RC=$?
  finish_run
}

finish_run() {
  CALLS=0; AUTHS=0
  if [ -f "$TMP/state/calls" ]; then
    CALLS="$(wc -l < "$TMP/state/calls" | tr -d ' ')"
    AUTHS="$(grep -c '^auth$' "$TMP/state/calls" || true)"
  fi
  ALL_OUTPUT+="$OUT"$'\n'
}

check() { # <label> <condition...>
  local label="$1"; shift
  if "$@"; then
    PASSED=$((PASSED + 1)); echo "  ok   $label"
  else
    FAILED=$((FAILED + 1)); echo "  FAIL $label"
    echo "       rc=$RC calls=$CALLS auth=$AUTHS"
    printf '%s\n' "$OUT" | sed 's/^/       | /'
  fi
}
has() { printf '%s' "$OUT" | grep -qF -- "$1"; }
lacks() { ! has "$1"; }

echo "== auth-cache isolation probe harness =="

echo "-- no cache marker on either anon 2xx: SKIPPED, no Bearer request"
run_probe "200:absent,200:absent,200:miss,200:miss"
check "exit 4" [ "$RC" -eq 4 ]
check "prints the SKIPPED line" has "$SKIP_LINE"
check "only the two anon requests ran" [ "$CALLS" -eq 2 ]
check "no request carried an Authorization header" [ "$AUTHS" -eq 0 ]
check "never claims a PASS" lacks "PASS: anon caching live"
check "writes the job-summary line" grep -qF "nothing to test" "$TMP/summary.md"

echo "-- marker present, anon #2 HIT, Bearer pair bypassed: PASS, as before"
run_probe "200:miss,200:hit,200:miss,200:miss"
check "exit 0" [ "$RC" -eq 0 ]
check "prints PASS" has "PASS: anon caching live"
check "four requests" [ "$CALLS" -eq 4 ]
check "the Bearer pair still carries the dummy header" [ "$AUTHS" -eq 2 ]
check "no SKIPPED" lacks "SKIPPED"
check "no job-summary line" [ ! -s "$TMP/summary.md" ]

echo "-- marker present, a Bearer request HIT: P0, as before"
run_probe "200:miss,200:hit,200:hit,200:miss"
check "exit 2" [ "$RC" -eq 2 ]
check "prints P0 FAIL" has "P0 FAIL"

echo "-- marker present but anon #2 only a miss: INCONCLUSIVE, as before"
run_probe "200:miss,200:miss,200:miss,200:miss"
check "exit 1" [ "$RC" -eq 1 ]
check "prints INCONCLUSIVE" has "INCONCLUSIVE: anon repeat request did not HIT"
check "the Bearer pair still ran, as before" [ "$CALLS" -eq 4 ]
check "no SKIPPED" lacks "SKIPPED"

echo "-- marker on anon #2 only: the normal path, not SKIPPED"
run_probe "200:absent,200:hit,200:miss,200:miss"
check "exit 0" [ "$RC" -eq 0 ]
check "no SKIPPED" lacks "SKIPPED"

echo "-- no HTTP responses: INCONCLUSIVE (client-IP ban), never SKIPPED"
run_probe "000:absent,000:absent,000:absent,000:absent"
check "exit 1" [ "$RC" -eq 1 ]
check "prints the no-responses line" has "INCONCLUSIVE: no HTTP responses"
check "no SKIPPED" lacks "SKIPPED"

echo "-- no marker, but the anon answers are 503: INCONCLUSIVE, never SKIPPED"
run_probe "503:absent,503:absent,503:absent,503:absent"
check "exit 1" [ "$RC" -eq 1 ]
check "no SKIPPED" lacks "SKIPPED"

echo "-- the production hostname is refused before any request"
run_probe "200:absent,200:absent" BASE_URL=https://bluecollarcrypto.io
check "exit 3" [ "$RC" -eq 3 ]
check "prints REFUSED" has "REFUSED"
check "no request at all" [ "$CALLS" -eq 0 ]

echo "-- the workflow step: 4 is green with a notice; 0, 1, 2 pass through"
run_step "200:absent,200:absent"
check "SKIPPED step exits 0" [ "$RC" -eq 0 ]
check "SKIPPED step emits the notice" has "::notice title=Auth-cache probe skipped::staging serves REST uncached; nothing to test"
check "SKIPPED step sent no Authorization header" [ "$AUTHS" -eq 0 ]
run_step "200:miss,200:hit,200:miss,200:miss"
check "PASS step exits 0, no notice" [ "$RC" -eq 0 ]
check "PASS step has no notice" lacks "::notice"
run_step "200:miss,200:hit,200:hit,200:miss"
check "P0 step exits 2" [ "$RC" -eq 2 ]
run_step "200:miss,200:miss,200:miss,200:miss"
check "INCONCLUSIVE step exits 1" [ "$RC" -eq 1 ]

echo "-- the dummy Bearer value is never printed"
OUT="$ALL_OUTPUT"; RC=0; CALLS="-"; AUTHS="-"
check "token absent from every run's output" lacks "$DUMMY_BEARER"

echo "--"
echo "passed: $PASSED  failed: $FAILED"
[ "$FAILED" -eq 0 ]
