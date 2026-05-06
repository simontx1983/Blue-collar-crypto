---
name: duplicate-scan
description: Run the §11 mandatory cross-codebase duplicate scan before writing or modifying code in the BCC repo. Produces the CROSS-CODEBASE SCAN REPORT required by docs/prompts/duplicate-scan.md. Use when the user asks for new functionality, a refactor, an extraction, or any code change that might duplicate logic that already exists.
---

# /duplicate-scan

This skill enforces §11 of the [repo CLAUDE.md](../../../CLAUDE.md): no new code without a duplicate scan first.

## How to run it

1. Identify what is being asked for. Pick 3–6 keywords (function names, domain terms, class fragments).

2. Delegate the actual scan to the `duplicate-scanner` subagent. It owns the search procedure and the report format. Pass it the request description and your keywords.
   ```
   Use the Agent tool with subagent_type=duplicate-scanner.
   ```

3. The subagent returns a CROSS-CODEBASE SCAN REPORT in the exact shape from [docs/prompts/duplicate-scan.md](../../../docs/prompts/duplicate-scan.md). Surface it to the user verbatim.

4. Honor the recommendation:
   - **REUSE** → call the existing implementation, do not write new code.
   - **EXTEND** → add a method/parameter to the existing class, do not write a parallel class.
   - **CREATE NEW** → only allowed when the report shows concrete searches with zero relevant hits. Cite the report when proceeding.

## What this skill does NOT do

- It does not write code. The scan gates code, it doesn't replace it.
- It does not approve a CREATE NEW recommendation that lacks evidence. "No matches found" without listed searches is invalid per §11.
- It does not run for trivial edits (typo fixes, formatting). Use judgment — §11 targets new logic, not cosmetic changes.
