#!/usr/bin/env bash
# PostToolUse hook: lint PHP files after Claude edits them.
#
# Reads tool call JSON from stdin (Claude Code hook contract).
# If the edited file is a .php file inside a bcc-* plugin, runs `php -l`
# and surfaces any parse errors back to Claude (exit 2).

set -uo pipefail

input="$(cat)"

# Pull file_path from the JSON payload without requiring jq.
file_path=$(printf '%s' "$input" | sed -n 's/.*"file_path"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n1)

[[ -z "$file_path" ]] && exit 0
[[ "$file_path" != *.php ]] && exit 0

# Skip vendor/ — composer-managed, not ours to lint.
case "$file_path" in
  */vendor/*) exit 0 ;;
esac

# php -l prints to stdout; we only care about failures.
if ! lint_output=$(php -l "$file_path" 2>&1); then
  printf 'PHP syntax error in %s:\n%s\n' "$file_path" "$lint_output" >&2
  exit 2
fi

exit 0
