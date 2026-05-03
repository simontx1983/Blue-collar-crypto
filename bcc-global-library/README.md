# bcc-global-library

This directory is reserved for shared logic across projects.

Claude must search this directory **before** creating new
implementations. See §11 of [CLAUDE.md](../CLAUDE.md) (Cross-Codebase
Reuse Rule) and [../docs/prompts/duplicate-scan.md](../docs/prompts/duplicate-scan.md).

Future shared modules (reputation, wallets, feeds, auth, etc.) should
be promoted here instead of duplicated across plugins.

## When to promote code here

A piece of logic earns a place in `bcc-global-library/` once **two or
more independent projects** need the same thing — e.g., a second app
or service alongside the WordPress plugins + Next.js frontend.

Until then, single-repo shared logic stays at its plugin-local
canonical location, recorded in [../docs/pattern-registry.md](../docs/pattern-registry.md).

## What this directory must NOT become

- A dumping ground for "might be useful someday" utilities — YAGNI
- A second place that duplicates the pattern registry — the registry
  is the index; this directory holds extracted code only
