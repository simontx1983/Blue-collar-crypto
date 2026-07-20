---
name: frontend-feature
description: Build a new feature in the bcc-frontend Next.js app following the established patterns — typed API client, React Query hooks, server-rendered view-models, Tailwind styling, reduced-motion respect, no business logic in the frontend. Use when adding components, pages, or hooks under bcc-frontend/.
---

# /frontend-feature

Scaffolds frontend work in [bcc-frontend/](../../../bcc-frontend/) the way this codebase actually does it. The architectural rule from [bcc-frontend/README.md](../../../bcc-frontend/README.md) and reinforced in [code-cleanup](../code-cleanup/SKILL.md) Step 4: **no business logic in the frontend.** Tier labels, trust scores, rank strings, and feature-access flags arrive pre-computed from the API per §A2 / §L5 of [docs/api-contract-v1.md](../../../docs/api-contract-v1.md).

Run [/duplicate-scan](../duplicate-scan/SKILL.md) first. Most "new" components are extensions of existing ones in [bcc-frontend/src/components/](../../../bcc-frontend/src/components/) (cards, feed, profile, directory, etc.).

## Inputs to gather

- **Feature surface** — page, card, feed item, modal, hook, settings panel?
- **API source** — which endpoint provides the data? Find it in [REST/](../../../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/REST/). If none exists, **stop** — build the backend view-model first.
- **Auth context** — does the data require a logged-in session? (NextAuth in [src/lib/auth.ts](../../../bcc-frontend/src/lib/auth.ts))
- **Animation needs** — any motion must fall back via `usePrefersReducedMotion()` to a static state, not a shorter animation.

## Layering — what goes where

```
src/app/<route>/page.tsx        → server component, fetches & passes data
src/components/<domain>/*.tsx   → presentational, accepts typed props
src/hooks/use<X>.ts             → React Query wrappers, return { data, isLoading, error }
src/lib/api/client.ts           → the ONLY place fetch() is called
src/lib/api/types.ts            → contract types — must match backend exactly
```

**No direct `fetch()` outside `lib/api/client.ts`.** The wrapper handles auth, envelope unwrapping, and error mapping.

## Steps

### 1. Verify the contract type exists

Open [bcc-frontend/src/lib/api/types.ts](../../../bcc-frontend/src/lib/api/types.ts). The response shape you're rendering must already be typed. If it's missing or stale:

- The backend [Envelope](../../../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/REST/Envelope.php) determines truth.
- Update `types.ts` to match. Do **not** patch over with `as any` or `// @ts-ignore`.
- Cross-reference [docs/api-contract-v1.md](../../../docs/api-contract-v1.md). If the contract doc lags behind reality, that's a §9 problem — invoke [/api-contract-guard](../api-contract-guard/SKILL.md).

### 2. Add the API client method (if needed)

In `lib/api/client.ts`, follow the existing pattern: typed input, typed output, automatic envelope unwrapping, error mapped to a thrown `BccApiError` (or whatever the file uses — read first).

### 3. Add the React Query hook

```ts
// src/hooks/use<Feature>.ts
export function use<Feature>(/* args */) {
  return useQuery({
    queryKey: ['<feature>', /* args */],
    queryFn: () => api.<feature>(/* args */),
    enabled: /* gate on inputs that aren't ready */,
    // staleTime tuned to how often the read-model regenerates
  });
}
```

Hooks return `{ data, isLoading, error }`. **Components do not construct their own loading state from scratch** — they consume the hook's shape.

### 4. Build the component

- Props are fully typed from `lib/api/types.ts`. No prop is `any`.
- Server-provided strings (`presentation.trust_label`, `presentation.tier_class`, etc.) are rendered as-is. **Do not** transform them client-side ("Elite" → "Legendary" mapping is a §S violation).
- Tailwind classes only. No inline styles unless dynamic (e.g. an animated value).
- Animation: any transition checks `usePrefersReducedMotion()` and renders a static state when reduced.
- Memo any card that lives in a feed — feed re-renders on every tick, unstable callback props churn the whole list.

### 5. Decide client vs server component

`'use client'` only when you need state, effects, or browser APIs. A profile-card render that just displays data should stay server-rendered so Next can cache it. Audit `src/app/<route>/` for unnecessary client components — see [code-cleanup](../code-cleanup/SKILL.md) Step 3.

### 6. Verify

```bash
cd bcc-frontend
npx tsc --noEmit
npx eslint .
```

The post-edit `ts-check.sh` hook already runs `tsc` on save, so most type errors surface inline. Lint and a real browser check are still on you.

## Hard rules

- **No business logic.** No `if (tier === 'elite') return 'Legendary'`, no client-side trust-score arithmetic, no permission decisions.
- **No `any`** outside of [lib/api/types.ts](../../../bcc-frontend/src/lib/api/types.ts) (and even there, prefer concrete types).
- **No `as any`, no `// @ts-ignore`, no `// eslint-disable-next-line`** without an inline reason on the same line.
- **No raw `fetch()`** outside the API client.
- **No `console.log`** left in. Use the project's logger if one exists; remove otherwise.
- **Reduced-motion:** respected, not skipped.

## After scaffolding

1. The post-edit hook ran `tsc --noEmit` automatically. Fix any errors before declaring done.
2. Smoke-test the change in the browser. The Playwright MCP is wired up in [.mcp.json](../../../.mcp.json) — use it for non-trivial flows.
3. Invoke the [frontend-reviewer](../../agents/frontend-reviewer.md) subagent before declaring done.

## What this skill does NOT do

- It does not run the [/duplicate-scan](../duplicate-scan/SKILL.md). You run that first, manually, before invoking this.
- It does not write backend code. If the API doesn't return what you need, stop and build the backend view-model.
- It does not replace product/design judgment. Layout and copy decisions are not encoded here.
