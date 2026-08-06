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

### 4. Look at the neighbours first

Before writing markup, **open the nearest existing screen or the closest component in the same domain folder and read it.** You are extending a design language that already exists, not inventing one. Match its structure, type roles, surface family, spacing and states.

The canonical description is [bcc-frontend/docs/frontend-doctrine.md §5](../../../bcc-frontend/docs/frontend-doctrine.md) — read it before building UI. The condensed operational version is in [bcc-frontend/CLAUDE.md](../../../bcc-frontend/CLAUDE.md). Don't re-derive the rules here.

**Precedence:** the doctrine, the shared `--bcc-*` tokens in `src/app/globals.css`, and the established reusable components are authoritative. The shipped frontend is the evidence those rules were derived from. When the docs don't cover a case, inspect nearby representative screens and repeated shared patterns before introducing anything new. **An isolated implementation does not override the doctrine or establish a new convention.**

The two things most often gotten wrong:

- **Name the surface family before you pick a text color.** Theme-aware app surfaces (`.bcc-panel`, `bg-bcc-surface*`) take the theme text scale (`text-bcc-text`, `-secondary`, `-muted`). Fixed cream/ink paper surfaces (`.bcc-paper`, `bg-cardstock*`, solid `bg-ink`) take the fixed ink scale (`text-ink`, `-soft`, `-ghost`, `text-cardstock`). **Both are current and intentional.** Mixing them is the repo's most repeated bug.
- **Reuse the shared primitives before inventing a variant** — `Dialog`, `Skeleton`/`SKELETON_CLASS`, `Spinner`, `LoadFailure`, `FilterChipRow`, `PagerNav`, `Lightbox`, `VerifiedBadge`, `PageHero`, and the `.bcc-*` classes. If none fits, say so explicitly and why. Several `.bcc-*` classes and the `rounded-bcc-*` / `shadow-bcc-*` aliases have zero consumers — they're dead CSS, not the standard.

### 5. Build the component

- Props are fully typed from `lib/api/types.ts`. No prop is `any`.
- Server-provided strings (`presentation.trust_label`, `presentation.tier_class`, etc.) are rendered as-is. **Do not** transform them client-side ("Elite" → "Legendary" mapping is a §S violation).
- Tailwind utilities are the default vehicle. **Inline `style` is permitted and normal when it carries a token or a dynamic value** — `style={{ background: "var(--bcc-glass-bg-solid)" }}`, a computed position. `Dialog`, `AppShell` and `MobileNav` all do this deliberately. What's banned is a **literal color** standing in for a token, not the `style` prop.
- Type roles: `bcc-mono` for labels/meta/chips, `bcc-stencil` for headings and controls, `font-serif` for prose. The signature micro-label is `bcc-mono text-[10px] tracking-[0.18em]`, uppercase.
- Flat, not elevated: 1px and dashed borders separate things, `rounded-sm`/`rounded-full` are the radius scale, shadows are near-absent. Don't add a shadow, large radius, gradient or glow the surrounding UI doesn't already use.
- Define **empty / loading / error** up front, from the canonical shapes in doctrine §5.11.
- Interaction states: hover, active/selected with matching ARIA (`aria-current` / `aria-selected` / `aria-pressed`), disabled as visible-but-disabled with a tooltip. **The focus ring is global** in `globals.css` — don't add per-component `focus-visible:` utilities unless the element genuinely needs different treatment.
- Touch targets ~44×44px for primary nav, dialogs, forms, important actions and standalone icon buttons; 36px is the sanctioned exception for dense repeated chips/filters/pagers only.
- Animation: any transition checks `usePrefersReducedMotion()` (or `motion-safe:`) and renders a **static** state when reduced — never a shorter animation.
- Check it at **375px** before calling it done. Fix small screens with structure, not another breakpoint.
- Memo any card that lives in a feed — feed re-renders on every tick, unstable callback props churn the whole list.

### 6. Decide client vs server component

`'use client'` only when you need state, effects, or browser APIs. A profile-card render that just displays data should stay server-rendered so Next can cache it. Audit `src/app/<route>/` for unnecessary client components — see [code-cleanup](../code-cleanup/SKILL.md) Step 3.

### 7. Verify

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
- **Every color resolves to a `--bcc-*` token.** No raw hex, `rgb()`/`hsl()` literal, or named Tailwind palette class (`text-red-500`, `bg-white`).
- **No new design system, component library, icon set, color, type face, radius, shadow or effect.** Extend what ships. Don't redesign existing surfaces, and don't rewrite existing components to make them match documentation.
- **Visual guidance never outranks** functional requirements, the REST contract, accessibility, or established product terminology. If matching a pattern would break one of those, stop and raise it.

## After scaffolding

1. The post-edit hook ran `tsc --noEmit` automatically. Fix any errors before declaring done.
2. Smoke-test the change in the browser. The Playwright MCP is wired up in [.mcp.json](../../../.mcp.json) — use it for non-trivial flows.
3. Invoke the [frontend-reviewer](../../agents/frontend-reviewer.md) subagent before declaring done.

## What this skill does NOT do

- It does not run the [/duplicate-scan](../duplicate-scan/SKILL.md). You run that first, manually, before invoking this.
- It does not write backend code. If the API doesn't return what you need, stop and build the backend view-model.
- It does not restate the visual language. [frontend-doctrine.md §5](../../../bcc-frontend/docs/frontend-doctrine.md) is the canonical reference; this skill only points at it and flags the rules most often skimmed past.
- It does not replace product/design judgment. Novel layout direction, copy and terminology decisions are not encoded here — conforming to the established visual language is, and that is not the same thing.
