#!/usr/bin/env bash
# PostToolUse hook: typecheck Next.js frontend after Claude edits a .ts/.tsx file.
#
# Mirrors php-lint.sh: extracts file_path from the hook JSON and runs the
# TypeScript compiler in --noEmit mode. tsc uses the project's tsbuildinfo
# for incremental builds, so subsequent runs are fast.
#
# Exit 2 surfaces type errors back to Claude.

set -uo pipefail

input="$(cat)"

file_path=$(printf '%s' "$input" | sed -n 's/.*"file_path"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n1)

[[ -z "$file_path" ]] && exit 0

# Only act on TypeScript sources inside bcc-frontend/. Skip declaration
# files (they're generally generated or boundary types) and node_modules.
case "$file_path" in
  */node_modules/*) exit 0 ;;
  *.d.ts)           exit 0 ;;
  *bcc-frontend/*.ts|*bcc-frontend/*.tsx) ;;
  *) exit 0 ;;
esac

# Find the bcc-frontend root from the file path.
fe_root="${file_path%%/bcc-frontend/*}/bcc-frontend"

if [[ ! -f "$fe_root/tsconfig.json" ]]; then
  # Frontend not where we expected — silently skip rather than block work.
  exit 0
fi

if ! tsc_output=$(cd "$fe_root" && npx --no-install tsc --noEmit -p tsconfig.json 2>&1); then
  printf 'TypeScript errors after editing %s:\n%s\n' "$file_path" "$tsc_output" >&2
  exit 2
fi

exit 0
