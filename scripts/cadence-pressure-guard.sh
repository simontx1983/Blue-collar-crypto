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
  "bcc-frontend/src/lib/copy"
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
  "remind(er|ing)? to (attest|vouch|cast|stand behind)"
  # "you should X" with attestation verbs
  "you should (attest|vouch|cast|stand behind)"
  # "consider attesting" and variants
  "consider (attesting|casting|vouching|standing behind|backing)"
  # "active operators" + implied scheduling
  "active operators (attest|vouch|cast)"
)

VIOLATIONS=0

for pattern in "${PATTERNS[@]}"; do
  for path in "${SCOPE_PATHS[@]}"; do
    [[ -e "$path" ]] || continue

    # git grep -nIE: line numbers, skip binary, extended regex.
    # --untracked + --exclude-standard scans new files too while
    # respecting .gitignore — local pre-commit runs catch violations
    # before commit, not after. The 2>/dev/null suppresses "no
    # matches" stderr; the || true keeps set -e happy.
    matches="$(git grep --untracked --exclude-standard -nIE "$pattern" -- "$path" 2>/dev/null || true)"
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
