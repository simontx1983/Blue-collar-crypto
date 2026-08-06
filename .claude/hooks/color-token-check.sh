#!/usr/bin/env bash
# PostToolUse hook: color-token guard for the bcc-frontend Next.js app.
#
# Blocks a saved frontend file where THIS edit introduces a hardcoded color
# instead of a token, or reaches for a BRAND-namespace name where the aesthetic
# alias is the one the app actually uses. See bcc-frontend/docs/frontend-doctrine.md
# §5.4 for the canonical color policy (condensed in bcc-frontend/CLAUDE.md).
#
# Two failure modes it catches:
#   1. Literal color values — raw hex (#f05a28), rgb()/hsl() literals, or a
#      named Tailwind palette class (text-red-500, bg-white). These never flip
#      with [data-theme] light/dark, so the page looks broken in the other theme.
#   2. Brand-namespace names used outside the one place that owns them.
#      safety / weld / verified / blueprint exist under TWO names: the brand
#      layer (--bcc-safety, …) and the aesthetic layer (--safety, --weld,
#      --verified, --blueprint, plus the Tailwind aliases text-safety,
#      bg-blueprint, text-weld, text-verified). App code uses the AESTHETIC
#      names; the --bcc-* spellings are reserved for the brand layer and for
#      src/components/cards/, which consumes them directly.
#
#      This is a namespace rule, not a quarantine on the colors themselves.
#      text-safety is the established micro-eyebrow / inline-alert color across
#      page chrome, and weld/blueprint are part of the shipped vocabulary — the
#      aesthetic aliases are unpoliced everywhere and always have been (see the
#      CARDSTOCK_PATTERNS note below). Only the brand spelling is scoped.
#
# Whole-file guard: the tree was fully tokenized on 2026-07-08 (the legacy
# hex/rgba literals were converted to --bcc-*/workshop tokens, the genuine
# one-offs — Google logo, watching rarity palette — carry allow markers). So
# any literal on any line is now a real regression; no diff-against-HEAD needed.
#
# Precision over recall: patterns match token/class REFERENCES, never English
# words (a naive match on "safety"/"verified" hits ~478 innocent word uses).
#
# Inline override (for a genuine exception, e.g. Satori OG code that can't read
# CSS vars): put this marker on the same or the preceding line —
#   // color-token-guard:allow — <short reason>
#   /* color-token-guard:allow — <short reason> */
#
# Exit 0 = clean / out of scope. Exit 2 = violation surfaced back to Claude.

set -uo pipefail

input="$(cat)"

file_path=$(printf '%s' "$input" | sed -n 's/.*"file_path"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n1)
[[ -z "$file_path" ]] && exit 0

# JSON escapes backslashes; normalize Windows separators to forward slashes so
# the globs and git paths below match regardless of how the path was serialized.
file_path="${file_path//\\\\//}"
file_path="${file_path//\\//}"

# Scope: TypeScript/CSS sources inside bcc-frontend/ only.
case "$file_path" in
  */node_modules/*) exit 0 ;;
  *.d.ts)           exit 0 ;;
  *bcc-frontend/*.ts|*bcc-frontend/*.tsx|*bcc-frontend/*.css) ;;
  *) exit 0 ;;
esac

# Definition-file & renderer exemptions: these legitimately hold literal colors.
#   globals.css / tailwind.config.ts — where the --bcc-* tokens are DEFINED.
#   src/lib/og/*                      — Satori/OG image rendering can't read CSS
#                                       custom properties; it needs literal hex.
case "$file_path" in
  */bcc-frontend/src/app/globals.css) exit 0 ;;
  */bcc-frontend/tailwind.config.ts)  exit 0 ;;
  */bcc-frontend/src/lib/og/*)        exit 0 ;;
esac

[[ -f "$file_path" ]] || exit 0

# src/components/cards/ is the one directory that consumes the brand-namespace
# spellings directly (--bcc-safety / --bcc-verified on the standing strip and
# action bar), so the brand-namespace check is skipped there. NB the reason has
# changed since this exemption was written: it was originally "card faces are
# the only home for the cardstock aesthetic." The 2026-08-04 rebuild made the
# card brand-native and theme-aware, so cards/ now uses the --bcc-* names
# because it is brand-native, not because it is skeuomorphic. Same directory,
# opposite rationale — don't re-derive the old policy from this line.
is_card=0
case "$file_path" in
  */bcc-frontend/src/components/cards/*) is_card=1 ;;
esac

# Test/spec files are exempt from the LITERAL patterns ONLY (see the PATTERNS
# assembly below). A fixture legitimately carries hex: `crest.monogram_color`
# is a SERVER-supplied hex string (src/lib/api/types.ts), so a test that mocks
# the API payload has to spell it out — writing a token there would make the
# test wrong. Test titles also carry entity IDs like "member #123", which the
# 3-digit-hex branch reads as a color.
#
# The brand-namespace check still applies to tests: nothing about a test makes
# `--bcc-safety` the right spelling. Only the literal-color rule is relaxed.
is_test=0
case "$file_path" in
  *.test.ts|*.test.tsx|*.spec.ts|*.spec.tsx) is_test=1 ;;
esac

# Literal-color patterns — apply everywhere in scope, cards/ included (a card
# must use the --bcc-safety etc. TOKENS, never a raw hex).
LITERAL_PATTERNS=(
  # raw hex: 3/4/6/8 digits (valid CSS color lengths only). The trailing
  # [^-...] guard (not just \b) rejects id selectors like "#bcc-p0" and
  # "#bcc-preloader", where \b would fire on the hyphen and false-positive.
  '#([0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})([^-0-9A-Za-z_]|$)'
  # rgb()/rgba()/hsl()/hsla() color-function LITERALS — a digit must follow the
  # paren. This is what distinguishes rgb(240 90 40 / .3) (banned literal) from
  # rgb(var(--bcc-safety-rgb) / .3) (the compliant token-triplet form).
  '\b(rgb|rgba|hsl|hsla)\([[:space:]]*[0-9]'
)
# Named Tailwind palette classes — zero pre-existing, zero false positives, and
# the most common hardcode mistake. Variant prefixes (hover:/dark:) still carry
# the bare "text-red-500" substring, so they match too.
LITERAL_PATTERNS+=(
  '(text|bg|border|ring|from|via|to|fill|stroke|divide|outline|decoration|accent|caret|placeholder|shadow)-(red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|slate|gray|zinc|neutral|stone)-[0-9]{2,3}\b'
  '(text|bg|border|ring|from|via|to|fill|stroke|divide|outline|decoration|accent|caret|placeholder|shadow)-(white|black)\b'
)

# Brand-namespace patterns — policed only OUTSIDE src/components/cards/.
# (Variable name is historical; it predates the two-surface model and is kept so
# the diff stays comment-only. Read it as BRAND_NAMESPACE_PATTERNS.)
#
# ONLY the brand-namespaced --bcc-safety/-weld/-verified/-blueprint spelling is
# policed. The aesthetic aliases (var(--safety), var(--weld), var(--blueprint),
# text-safety, text-cardstock, text-ink, bg-paper, …) are the app's shipped
# design language — used ~1800× across page chrome intentionally — and are
# deliberately UNPOLICED. Two surface families are current and correct: the
# theme-aware app surfaces (--bcc-* tokens + theme text scale) and the fixed
# cream/ink paper surfaces (.bcc-paper, cardstock, ink). See
# bcc-frontend/docs/frontend-doctrine.md §5.3.
#
# So this block is a NAMESPACE rule: reach for the aesthetic alias in app code,
# leave the --bcc-* spelling to the brand layer and to cards/ (which consumes it
# directly). It says nothing about where the colors themselves may appear —
# that is a design decision no regex can adjudicate, and it has been settled in
# favour of these colors being part of page chrome.
CARDSTOCK_PATTERNS=(
  # CSS var reference (also covers arbitrary values like [var(--bcc-safety)])
  '--bcc-(safety|weld|verified|blueprint)\b'
)

PATTERNS=()
if [[ "$is_test" -eq 0 ]]; then
  PATTERNS+=( "${LITERAL_PATTERNS[@]}" )
fi
if [[ "$is_card" -eq 0 ]]; then
  PATTERNS+=( "${CARDSTOCK_PATTERNS[@]}" )
fi

# A test file under src/components/cards/ clears both bands, leaving nothing to
# check. Bail early rather than expanding an empty array under `set -u`.
[[ ${#PATTERNS[@]} -eq 0 ]] && exit 0

VIOLATIONS=0
REPORTED_LINES=" "   # space-delimited set of already-reported line numbers

for pattern in "${PATTERNS[@]}"; do
  # Grep the single known file WITHOUT -H: an absolute Windows path carries a
  # drive-letter colon (C:/...) that would corrupt a file:line:content parse.
  # Output is "lineno:content"; lineno is numeric.
  # -e is required: several patterns begin with "--", which grep would otherwise
  # parse as an option and silently fail on.
  matches="$(grep -nIE -e "$pattern" "$file_path" 2>/dev/null || true)"
  [[ -z "$matches" ]] && continue

  while IFS= read -r match; do
    [[ -z "$match" ]] && continue
    lineno="${match%%:*}"
    line_content="${match#*:}"
    line_content="${line_content%$'\r'}"   # drop trailing CR (CRLF working tree)
    [[ "$lineno" =~ ^[0-9]+$ ]] || continue

    # De-dupe: one report per line even if several patterns hit it.
    case "$REPORTED_LINES" in *" $lineno "*) continue ;; esac

    # Comment-only lines can't render a color — skip (also lets a comment that
    # quotes the policy, e.g. "don't use text-red-500", pass).
    if printf '%s' "$line_content" | grep -qE '^[[:space:]]*(//|/\*|\*)'; then
      continue
    fi

    # Inline allow marker on the same or immediately preceding line.
    prev_line=""
    if [[ "$lineno" -gt 1 ]]; then
      prev_line="$(sed -n "$((lineno - 1))p" "$file_path" 2>/dev/null || echo "")"
    fi
    if printf '%s' "$line_content" | grep -qE 'color-token-guard:allow' \
       || printf '%s' "$prev_line" | grep -qE 'color-token-guard:allow'; then
      continue
    fi

    hit="$(printf '%s' "$line_content" | grep -oE -e "$pattern" | head -n1)"
    trimmed="$(printf '%s' "$line_content" | sed 's/^[[:space:]]*//')"
    printf '❌ hardcoded color "%s" at %s:%s\n     %s\n' "$hit" "$file_path" "$lineno" "$trimmed" >&2
    REPORTED_LINES+="$lineno "
    VIOLATIONS=$((VIOLATIONS + 1))
  done <<< "$matches"
done

if [[ "$VIOLATIONS" -gt 0 ]]; then
  {
    printf '\n'
    printf 'FAIL: %d color-token violation(s) in %s\n' "$VIOLATIONS" "$file_path"
    printf '      Every color must resolve to a token in src/app/globals.css —\n'
    printf '      never a raw hex, rgb()/hsl() literal, or Tailwind palette class.\n'
    printf '      App chrome: --bcc-accent/-bg/-surface/-border/-text* (theme surfaces)\n'
    printf '        and cardstock/ink/paper (fixed cream paper surfaces).\n'
    printf '      safety/weld/verified/blueprint: use the AESTHETIC alias in app code —\n'
    printf '        text-safety, text-weld, bg-blueprint, var(--safety), var(--weld) …\n'
    printf '        The --bcc-safety/-weld/-verified/-blueprint spelling is the brand\n'
    printf '        layer, reserved for src/components/cards/. These colors ARE part of\n'
    printf '        page chrome; only the brand spelling is scoped.\n'
    printf '      See bcc-frontend/docs/frontend-doctrine.md §5.3-5.4 (canonical),\n'
    printf '        condensed in bcc-frontend/CLAUDE.md ▸ "Visual language".\n'
    printf '      Genuine exception? Add on the same or preceding line:\n'
    printf '        color-token-guard:allow — <short reason>\n'
  } >&2
  exit 2
fi

exit 0
