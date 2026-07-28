#!/usr/bin/env bash
# cadence-pressure-guard.sh
#
# Mitigation for plan §J.5 critical-risk-mitigation item #11
# ("cadence-pressure + judgment-fatigue operational bundle").
#
# Fails CI when operator-facing copy contains cadence-pressure
# patterns — language that pushes operators to attest on a schedule,
# nudges them about silence, or rewards streaks. Per the §2.7
# threat model, the platform is *information, not a nudge*; once it
# feels like a daily-streak game, attestation quality collapses
# because operators cast for the system rather than from real
# judgment.
#
# Scope: operator-facing surfaces only. Comments in code are fine
# (a comment explaining why we don't say "you should attest" must
# itself contain the phrase). Inline overrides are supported via:
#
#   // cadence-pressure-guard:allow — short reason
#   /* cadence-pressure-guard:allow — short reason */
#   # cadence-pressure-guard:allow — short reason  (shell + Python)
#
# Place the allow marker on the SAME line as the match, OR the
# immediately preceding line.
#
# Returns 0 when clean, 1 when any unallowed pattern matches.

set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || echo "$(pwd)")"
cd "$REPO_ROOT"

# Operator-facing scope. Tight on purpose — false positives in
# technical PHP/TS identifiers ("daysSince", "lastSeen", etc.) are
# unhelpful. Add paths as new operator-facing surfaces ship.
SCOPE_PATHS=(
  "bcc-frontend/src/app"
  "bcc-frontend/src/components"
  # Lifted copy module — the file, not a directory; a bare "copy" path
  # here resolved to nothing and was silently skipped (fixed 2026-07-23).
  "bcc-frontend/src/lib/copy.ts"
  "app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/ContestedStateExplainer.php"
  "app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/NotificationDispatcher.php"
  "app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/AttestationService.php"
  "app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Services/PolarizationTransitionNotifier.php"
  "docs/cadence-pressure-policy.md"
)

# Forbidden patterns. Each pattern targets English-text uses; the
# regex is specific enough that random variable names won't match.
# Add patterns sparingly — every new pattern is a false-positive
# tax future contributors will have to navigate around.
PATTERNS=(
  # "haven't" — the canonical nudge opener
  "haven['’]?t"
  # "days since" — temporal pressure framing
  "days since (you|your)"
  # "in the last N days/weeks/months" — same
  "in the last [0-9]+ (day|week|month)"
  # "streak" — gamification language (operator-facing only)
  "\\bstreak\\b"
  # "remind(er)" + action verb — soft nudge
  # ("stand behind" retained alongside "back": the label changed in v1.56 but
  #  older copy may still say it, and a stale pattern costs nothing)
  "remind(er|ing)? to (attest|vouch|cast|back|stand behind)"
  # "you should X" with attestation verbs
  "you should (attest|vouch|cast|back|stand behind)"
  # "consider attesting" and variants
  "consider (attesting|casting|vouching|backing|standing behind)"
  # "active operators" + implied scheduling
  "active operators (attest|vouch|cast)"
)

VIOLATIONS=0

# ── Blindness self-test ────────────────────────────────────────────
# This guard was a silent no-op from its introduction until 2026-07-06:
# it used `git grep --exclude-standard` from the umbrella root, whose
# deny-all `/*` .gitignore covers every code scope path (the frontend
# and plugins are nested sibling repos the umbrella ignores). The guard
# scanned only the tracked policy doc and printed PASS on every run.
# Plain filesystem grep below fixes the scan; this self-test makes the
# failure mode structural: if the scope resolves to (almost) no files,
# the guard fails loudly instead of passing blind.
SCANNABLE_FILES=0
for path in "${SCOPE_PATHS[@]}"; do
  if [[ -f "$path" ]]; then
    SCANNABLE_FILES=$((SCANNABLE_FILES + 1))
  elif [[ -d "$path" ]]; then
    count="$(find "$path" -type f \( -name '*.ts' -o -name '*.tsx' -o -name '*.php' -o -name '*.md' \) 2>/dev/null | wc -l)"
    SCANNABLE_FILES=$((SCANNABLE_FILES + count))
  fi
done
if [[ "$SCANNABLE_FILES" -lt 10 ]]; then
  printf "FAIL: cadence-pressure guard resolved only %d scannable file(s) — the scope paths are wrong or the guard is running from the wrong root. Refusing to pass blind.\n" "$SCANNABLE_FILES" >&2
  exit 1
fi

for pattern in "${PATTERNS[@]}"; do
  for path in "${SCOPE_PATHS[@]}"; do
    [[ -e "$path" ]] || continue

    # Plain filesystem grep (NOT git grep): the frontend and plugins are
    # nested sibling git repos that the umbrella's deny-all .gitignore
    # covers, so `git grep --exclude-standard` from this root silently
    # skipped every code path (the guard passed green while checking
    # nothing — 2026-07-06 audit). -r recurse, -n line numbers, -I skip
    # binary, -E extended regex. Scope paths are tight source dirs, so
    # no ignore-file handling is needed.
    # -H forces the filename prefix even when the scope path is a single
    # file (grep omits it otherwise and the file:line parse below breaks).
    matches="$(grep -rnHIE "$pattern" "$path" 2>/dev/null || true)"
    [[ -z "$matches" ]] && continue

    # For each match, check the same line and preceding line for an
    # allow marker. The marker grants permission to use the pattern
    # in that specific spot (e.g. explanatory copy in a policy doc).
    while IFS= read -r match; do
      [[ -z "$match" ]] && continue
      file="${match%%:*}"
      rest="${match#*:}"
      lineno="${rest%%:*}"

      # Pull the matched line + one above it.
      same_line="$(sed -n "${lineno}p" "$file" 2>/dev/null || echo "")"
      prev_lineno=$((lineno - 1))
      prev_line=""
      if [[ "$prev_lineno" -ge 1 ]]; then
        prev_line="$(sed -n "${prev_lineno}p" "$file" 2>/dev/null || echo "")"
      fi

      if echo "$same_line" | grep -qE "cadence-pressure-guard:allow" \
         || echo "$prev_line" | grep -qE "cadence-pressure-guard:allow"; then
        # Allowed — skip.
        continue
      fi

      # Comment lines are exempt per the header ("a comment explaining
      # why we don't say 'you should attest' must itself contain the
      # phrase"). A line whose first non-whitespace is a comment opener
      # can't be operator-facing copy. String literals that merely
      # FOLLOW a comment on the same line still match (the opener test
      # anchors at line start).
      if echo "$same_line" | grep -qE '^[[:space:]]*(//|/\*|\*|#)'; then
        continue
      fi

      printf "❌ cadence-pressure pattern \"%s\" — %s\n" "$pattern" "$match" >&2
      VIOLATIONS=$((VIOLATIONS + 1))
    done <<< "$matches"
  done
done

if [[ "$VIOLATIONS" -gt 0 ]]; then
  printf "\n" >&2
  printf "FAIL: %d cadence-pressure violation(s). See docs/cadence-pressure-policy.md\n" "$VIOLATIONS" >&2
  printf "      To allow a specific match, place this marker on the same or\n" >&2
  printf "      preceding line:  cadence-pressure-guard:allow — <short reason>\n" >&2
  exit 1
fi

printf "PASS: no cadence-pressure patterns in operator-facing copy.\n"
exit 0
