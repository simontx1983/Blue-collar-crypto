# Cross-Codebase Duplicate Scan

Before writing code, perform a full duplicate scan.

Search:

- current repo
- `/bcc-global-library/`
- `docs/pattern-registry.md`
- related repos if provided

## Quality requirement (load-bearing)

The scan **must** include at least one concrete grep / search attempt
and reference concrete file paths. Vague or empty reports are invalid.

"No matches found" without evidence of searching counts as **not
searching**. Reports that omit the "Search attempts" block, or that
list matches without `file:line` references, will be treated as if the
scan never happened.

## Required output

```
CROSS-CODEBASE SCAN REPORT

- Search attempts:
  - <tool> "<query>" in <path or scope>           → <hit count>
  - <tool> "<query>" in <path or scope>           → <hit count>
  - (one line per search; at least one is required)

- Pattern registry checked:                        yes / no
  (yes = read docs/pattern-registry.md and inspected the relevant
   sections for an existing canonical implementation)

- Exact matches:
  - <path>:<line> — <one-line description of why it's an exact match>
  - (use "none" only after listing the searches that produced none)

- Partial matches:
  - <path>:<line> — <what's similar, what's different>
  - (use "none" only after listing the searches that produced none)

- Related utilities:
  - <path>:<line> — <how the requested logic could compose with this>

- Recommendation:
  - REUSE   — call the existing implementation at <path>
  - EXTEND  — add a method/parameter to <path>
  - CREATE NEW — only if every search above returned zero relevant hits

- Justification (one sentence): <why the chosen recommendation is correct>
```

Do **NOT** write code until this report is complete.
