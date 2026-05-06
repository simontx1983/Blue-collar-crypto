#!/usr/bin/env bash
# UserPromptSubmit hook: inject §11 (Cross-Codebase Reuse) reminder when the
# user asks Claude to write/create/add/implement new code.
#
# Reads the user prompt from stdin JSON. Outputs additional context on stdout,
# which Claude Code prepends to the conversation as system context.

set -uo pipefail

input="$(cat)"
prompt=$(printf '%s' "$input" | sed -n 's/.*"prompt"[[:space:]]*:[[:space:]]*"\(.*\)".*/\1/p')

# Lowercase, strip JSON escapes for matching.
lower=$(printf '%s' "$prompt" | tr '[:upper:]' '[:lower:]')

# Only trigger on prompts that look like code-writing requests.
case "$lower" in
  *write*|*create*|*add*|*implement*|*build*|*new*|*refactor*|*extract*|*migrate*)
    cat <<'EOF'
[§11 reminder — Cross-Codebase Reuse Rule]

This request looks like new code. Per the repo CLAUDE.md §11, before writing
any code you MUST:

1. Search the current repository
2. Search /bcc-global-library/
3. Check docs/pattern-registry.md
4. Reference any external repos mentioned

Then produce a CROSS-CODEBASE SCAN REPORT (see docs/prompts/duplicate-scan.md)
that names concrete file paths and at least one grep attempt. "No matches"
without evidence of searching is invalid.

If similar logic exists, prefer REUSE or EXTEND over a parallel implementation.
EOF
    ;;
esac

exit 0
