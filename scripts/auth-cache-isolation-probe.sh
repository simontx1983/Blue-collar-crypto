#!/usr/bin/env bash
#
# auth-cache-isolation-probe.sh — verify the LiteSpeed Authorization cache-bypass
# rule still holds (testnet-deploy-checklist.md §1.6, "BCC AUTH CACHE BYPASS").
#
# What it checks, in order (sequential requests, >=1s apart — NOT a load test):
#   1. anon GET on a fresh REST URL        -> stored at the edge (miss)
#   2. anon GET, same URL                  -> must be an edge HIT (proves caching is live)
#   3. GET, same URL, dummy Bearer header  -> must NOT be a cache HIT
#   4. GET, same URL, dummy Bearer header  -> must NOT be a cache HIT
# The Bearer requests target the exact URL the anon pair just primed, so a HIT
# means an authenticated client was served the cached anonymous variant — the
# original 2026-07-13 leak. That is a P0 cache-isolation failure.
#
# Exit codes:
#   0  PASS  — anon caching live, both Bearer requests bypassed the cache
#   1  INCONCLUSIVE — anon second request never HIT (cache off, CDN interference,
#      or client-IP ban; see note below) — fix visibility before trusting a PASS
#   2  P0 FAIL — a Bearer request was served a cache HIT
#   3  usage/target error
#
# Scope guard: STAGING ONLY. The production hostname is refused outright —
# production runs are out of scope until the production phase is explicitly
# authorized (see deploy checklist §1.6).
#
# Client-IP ban gotcha: after heavy load-testing this client IP can be
# TLS-reset by the host for ~4h (curl returns empty/000). If every request
# reports HTTP 000, re-run this script FROM the staging server over SSH.
#
# No secrets: the Bearer token is a hardcoded dummy; it is never a real JWT
# and the Authorization header value is never echoed.

set -euo pipefail

BASE_URL="${BASE_URL:-https://stage.bluecollarcrypto.io}"
ENDPOINT="${ENDPOINT:-/wp-json/bcc/v1/cards?per_page=5}"
SPACING_SECONDS="${SPACING_SECONDS:-2}"
DUMMY_BEARER="bcc-cache-isolation-probe-dummy-token"

case "$BASE_URL" in
  https://stage.bluecollarcrypto.io*) ;;
  http://blue-collar-crypto-custom.local*|http://localhost*|http://127.0.0.1*) ;;
  *)
    echo "REFUSED: target '$BASE_URL' is not staging or local dev." >&2
    echo "Production probing requires explicit operator authorization." >&2
    exit 3
    ;;
esac

# Unique query param so request #1 is a genuine store, not a pre-existing entry.
PROBE_URL="${BASE_URL}${ENDPOINT}&_probe=$(date +%s)$$"

# probe <label> [bearer] — one request; prints "label: HTTP <code> <cache-state>"
# and echoes the cache state on fd 3 for the caller. Never prints the header value.
request() {
  local label="$1" auth="${2:-}" headers code lsc
  local -a curl_args=(-sS -o /dev/null -D - --max-time 20 "$PROBE_URL")
  if [ -n "$auth" ]; then
    curl_args=(-H "Authorization: Bearer ${DUMMY_BEARER}" "${curl_args[@]}")
  fi
  headers="$(curl "${curl_args[@]}" 2>/dev/null | tr -d '\r' || true)"
  code="$(printf '%s\n' "$headers" | awk 'toupper($1) ~ /^HTTP/ {print $2; exit}')"
  lsc="$(printf '%s\n' "$headers" | awk -F': ' 'tolower($1)=="x-litespeed-cache" {print tolower($2); exit}')"
  echo "${label}: HTTP ${code:-000} x-litespeed-cache=${lsc:-<absent>}"
  LAST_CODE="${code:-000}"
  LAST_LSC="${lsc:-absent}"
}

echo "== auth-cache isolation probe =="
echo "target: ${PROBE_URL}"

request "anon #1 (store)"
sleep "$SPACING_SECONDS"
request "anon #2 (expect HIT)"
ANON2_LSC="$LAST_LSC"; ANON2_CODE="$LAST_CODE"
sleep "$SPACING_SECONDS"
request "bearer #1 (must NOT hit)" bearer
B1_LSC="$LAST_LSC"
sleep "$SPACING_SECONDS"
request "bearer #2 (must NOT hit)" bearer
B2_LSC="$LAST_LSC"

echo "--"
if [ "$B1_LSC" = "hit" ] || [ "$B2_LSC" = "hit" ]; then
  echo "P0 FAIL: a Bearer-authenticated request was served a cached anonymous"
  echo "response. The AUTH CACHE BYPASS .htaccess block is missing or inert."
  echo "Restore it from the timestamped backup (see checklist §1.6) immediately."
  exit 2
fi
if [ "$ANON2_CODE" = "000" ]; then
  echo "INCONCLUSIVE: no HTTP responses (likely client-IP ban — re-run from the"
  echo "staging server over SSH)."
  exit 1
fi
if [ "$ANON2_LSC" != "hit" ]; then
  echo "INCONCLUSIVE: anon repeat request did not HIT the edge, so the cache"
  echo "layer was not exercised (cache disabled, CDN in front, or TTL config"
  echo "changed). Bearer bypass could not be meaningfully tested."
  exit 1
fi
echo "PASS: anon caching live (anon #2 HIT); both Bearer requests bypassed the"
echo "edge cache. Authorization isolation holds."
exit 0
