#!/usr/bin/env bash
# PostToolUse hook: ESLint Next.js frontend after Claude edits a .ts/.tsx file.
#
# Mirrors ts-check.sh: extracts file_path from the hook JSON and runs eslint
# on the single edited file. Complements ts-check.sh — together they cover
# the lint asymmetry where php-lint.sh runs on PHP edits but only tsc was
# running on TS edits.
#
# Exit 2 surfaces lint errors back to Claude.

set -uo pipefail

input="$(cat)"

file_path=$(printf '%s' "$input" | sed -n 's/.*"file_path"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n1)

[[ -z "$file_path" ]] && exit 0

# JSON escapes backslashes; normalize Windows separators to forward slashes so
# the globs and the fe_root split below match regardless of how the path was
# serialized. Same normalization as color-token-check.sh.
file_path="${file_path//\\\\//}"
file_path="${file_path//\\//}"

# Only act on TypeScript sources inside bcc-frontend/. Skip declaration
# files and node_modules.
# Scope on a LOWERCASED copy: on a case-insensitive filesystem `A.TS` or
# `BCC-Frontend/` is the same file, and must not silently skip the lint.
lc="${file_path,,}"
case "$lc" in
  */node_modules/*) exit 0 ;;
  *.d.ts)           exit 0 ;;
  */bcc-frontend/*.ts|*/bcc-frontend/*.tsx) ;;
  *) exit 0 ;;
esac

# Find the bcc-frontend root from the file path. Locate the segment in the
# lowercased copy, but slice the ORIGINAL so the real spelling is kept.
prefix="${lc%%/bcc-frontend/*}"
fe_root="${file_path:0:${#prefix}+13}"   # 13 = length of "/bcc-frontend"

if [[ ! -f "$fe_root/package.json" ]]; then
  # Frontend not where we expected — silently skip rather than block work.
  exit 0
fi

# Compute path relative to fe_root so eslint resolves it correctly.
relative_path="${file_path:${#fe_root}+1}"

if ! lint_output=$(cd "$fe_root" && npx --no-install next lint --file "$relative_path" 2>&1); then
  printf 'ESLint errors after editing %s:\n%s\n' "$file_path" "$lint_output" >&2
  exit 2
fi

exit 0
