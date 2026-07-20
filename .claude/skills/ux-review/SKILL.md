---
name: ux-review
description: Review UI flows on the BCC headless Next.js frontend against V1's actual UX vocabulary and quality bars (§J2/§L1 — Lighthouse, mobile 375px, reduced motion, keyboard nav, trust-signal density, empty/error/loading states). Audits what shipped, not generic UX principles. Use before merging a new component or page, after a fix that might regress a sibling surface, or for a pre-phase UX sweep.
---

# /ux-review

Procedure for reviewing UI flows on the BCC headless Next.js frontend
([bcc-frontend/](../../../bcc-frontend/)) against V1's actual UX vocabulary
and quality bars.

This skill audits **what shipped**, not generic UX principles. The
reference implementations live in
[bcc-frontend/src/components/](../../../bcc-frontend/src/components/) and the
walked-through user paths live in
[docs/v1-smoke-test-checklist.md](../../../docs/v1-smoke-test-checklist.md).
Read those before reviewing — half of "issues" reported without that
context turn out to be intentional.

---

## When to Use

- Reviewing a new component or page before merge
- Auditing a flow against §J2 / §L1 quality bars
- Verifying a fix didn't regress a sibling surface
- Pre-phase UX sweep

---

## V1 surface vocabulary (use these names)

If you write a review using generic words like "the profile sidebar"
the team won't know which surface you mean. Use the actual names:

**Floor (`/`)** — feed home. Three modes: **For You**, **Following**,
**Signals**. Header carries the **Highlight Strip** (slot 1 negative >
slot 2 positive > slot 3 external — strict order). **Inline Composer**
sits below the header with **Status / Review / Blog** tabs.

**Cards** — the trading-card atom. Front face shows name + stats +
**Pull button** (always visible per §N7, disabled with sign-in tooltip
when anon). Back face is stats. Hover = tilt. Legendary tier renders a
**foil** effect. Optional **social proof line** ("@x, @y +N follow
this") below the name when the viewer has trust-weighted network ties
(§O4.1 — caution-tier and below excluded from the count).

**Validator profile (`/v/<slug>`)** — Wanted poster + locked stream
when unclaimed. **CLAIM THIS PAGE** opens a 3-step modal
(explanation → wallet connect → success). Multi-chain pages render
**ChainTabs** below the stats panel.

**Member profile (`/u/<handle>`)** — **Living Header** carries three
elements: streak counter (flame), today's recent-impact line, comparison
line ("Top X% this week" or "Quiet shift"). Own-profile only adds the
**rank progress strip**. Tabs: **Reviews / Disputes / Activity /
Network / Watching / Blog**.

**Creator profile (`/c/<slug>`)** — gallery + featured drop layout.

**Composer** — unified modal with Update / Review / Blog tabs. When
opened from a target (e.g. WRITE A REVIEW on a validator page), the
Review tab is locked to that target — switching tabs and back preserves
it.

**Vouch / Review / Dispute** — three primary verbs (the Endorse verb was
retired in the 2026-07 attestation cutover; the write path is vouch /
STAND BEHIND). Each has a button that is visible-but-disabled with a
sign-in or eligibility tooltip when locked. Once the action lands, the
button flips to its **REMOVE …** counterpart.

**Pull batching (§C3)** — pulling 3 cards within 30s on different
surfaces produces **exactly one** "@you pulled 3 cards" feed item,
frozen at flush time. Don't re-render the post when the underlying
follows change.

**Onboarding** — wizard at `/onboarding`. Step 1 home-chain picker
(skippable). Step 2 first-pull suggestions. Done triggers the **§O1
dopamine animation**: cards fly into a watchlist icon, rarity-tinted glow
trails, stat-pop, background shift, lands on the Floor (not a "Done"
screen). **Reduced-motion** falls back to a static confirmation tile.

**Bell** — top-right notification surface. Badge ≤ "9+", refreshes
every 60s + on focus. Per-event toggles live at
`/settings/notifications`. Email digest is the at-least-weekly fallback.

**Panel duty** — `/panel`. Juror queue with vote + reason but no tally
until you cast. **PanelVoteModal** fires Accept / Reject. Participation
strip shows TRUST TODAY, VOTES, ACCURACY (gated on
`credited_lifetime ≥ min_for_accuracy`).

When you find a surface not on this list, check
[docs/trust-engine-coverage.md](../../../docs/trust-engine-coverage.md) — it
maps every backend verb to its frontend exposure.

---

## Step 1 — View-model contract check

The **single biggest UX bug class** on this stack is the frontend
deriving values it shouldn't. The rule is §A2 / §L5 of
[api-contract-v1.md](../../../docs/api-contract-v1.md) (and what the
`frontend-reviewer` agent enforces): no tier mapping, score formatting,
or locked-button copy synthesis in components — render off the
server-computed `presentation.*` fields (`tier_label`, `tier_class`,
`cta_state` with locked label + tooltip pre-filled).

If a component is computing one of these locally, the bug is on the
server (the view-model is missing the field). Open a ticket against
the API contract, not the component.

---

## Step 2 — Flow integrity (against the smoke test)

Pull the relevant section from
[v1-smoke-test-checklist.md](../../../docs/v1-smoke-test-checklist.md) and
walk it. Each checklist row is a contract — a regression on one is a
closed-beta blocker.

Common failure modes worth re-verifying:

- [ ] **Anon-shape leakage** — a logged-out user sees a Pull button
  enabled, or the directory shows the Block toggle. The visible-but-
  disabled pattern with sign-in tooltip is the only correct state.
- [ ] **Eligibility leakage** — Vouch enabled before identity quest
  done, before account ≥ 7 days, or with fraud score ≥ HIGH. Trust the
  server's `permissions.can_vouch`, not local checks.
- [ ] **Composer target loss** — opening WRITE A REVIEW on a page,
  switching tabs, switching back — the target must persist.
- [ ] **Pull batching** — the post must be **one** item, frozen.
  Unfollowing one of the cards must not change the post text or count.
- [ ] **Self-action blocks** — self-vouch, self-review on owned page,
  self-block. Each must surface a tooltip, not silently 200.
- [ ] **Modal step regressions** — Claim modal must open at step 1,
  not jump straight to wallet picker. Cancel + reopen must reset to
  step 1, not the last visited step.

---

## Step 3 — §J2 / §L1 quality bars

These are the bars V1 holds itself to. A new component or page that
falls below them is a regression even if "working":

- [ ] **Lighthouse** on the affected route in Chrome devtools.
  Performance ≥ **80**, Accessibility ≥ **90**. Don't fudge — re-run
  in incognito with cache disabled.
- [ ] **Mobile (real device, not just devtools)** at iPhone SE width
  (375px). iOS Safari + Android Chrome. Verify:
  - Cards readable at 375px width without horizontal scroll
  - Profile header doesn't overflow
  - Modals fit in the viewport with the dynamic toolbar visible
  - Bell + ViewerMenu open correctly; outside-click closes
- [ ] **Reduced motion** — set OS-level reduce motion (iOS
  Accessibility, Android Animations off). Re-walk the affected flow.
  Hook: [`usePrefersReducedMotion()`](../../../bcc-frontend/src/hooks/usePrefersReducedMotion.ts).
  Animations must fall back to a static state, **not just a shorter
  animation**.
- [ ] **Keyboard nav** — Tab through every interactive element in
  reading order. Esc closes modals + dropdowns. Enter activates the
  default action.
- [ ] **Tap targets ≥ 44×44px** on every interactive element on
  mobile. The Pull button on a card and the reaction row are the
  usual offenders.

---

## Step 4 — Trust signal density

Trust info must be **visible where it matters and absent where it's
noise**. Use the smoke-test checklist to scope:

- [ ] Profile, card, directory: trust signals visible
  (tier class, badges, counts)
- [ ] Composer modal, settings forms, panel duty queue: trust signals
  absent (would distract from the task)
- [ ] Verification badges (X / GitHub / wallet) clickable, render
  primary/verified chips correctly
- [ ] **Trust-weighted social proof (§O4.1)** — the "+N follow this"
  count must exclude caution-tier and below. If your network has 3
  elites + 1 caution, the count is 3.

---

## Step 5 — Empty / error / loading states

Every list, feed, and grid must define all three:

- [ ] **Empty** with guidance copy (not blank). E.g. directory empty
  state: "No cards match these filters" + reset CTA.
- [ ] **Loading** — skeleton, not spinner-on-a-blank-page; no layout
  shift when data arrives.
- [ ] **Error** — plain language, retry option where retryable.
  Network errors degrade gracefully (see
  [bcc-frontend/src/lib/api/client.ts](../../../bcc-frontend/src/lib/api/client.ts)
  for the canonical error map).

Specific empty states to spot-check:
- Panel duty with no assignments: "Not on duty"
- Watchlist with `watching_hidden=true` (legacy alias `binder_hidden`) viewed by another user: "Watchlist is
  private" placeholder
- Feed with all sources blocked: empty-state CTA to unblock or
  broaden filters
- Directory with no `Good Standing only` matches: state hint, not
  "0 results"

---

## Step 6 — Accessibility spot-checks

- [ ] Color contrast ≥ WCAG AA (4.5:1 text). Verify in devtools or
  via axe.
- [ ] Tier color is **not the only** signal — text label + class
  prefix carry semantics for colorblind users
- [ ] All images / icons have alt text or `aria-label`. Decorative
  flair (foil sheen, glow rings) marked `aria-hidden`.
- [ ] Focus states visible on all interactive elements — Tailwind
  `focus-visible:` rings, not just `focus:` (mouse users don't need
  the ring; keyboard users do)
- [ ] Modal focus is **trapped** (Esc closes; Tab cycles inside).
  Focus returns to the triggering element on close.
- [ ] Screen reader walks the Living Header coherently — streak
  count, impact line, comparison line read in order, not as raw
  numbers.

---

## Output format

Report findings using this structure (one block per issue):

```
## Issue
Short name (e.g. "Claim modal jumps past step 1 on reopen")

### Severity
Critical / High / Medium / Low

### Location
File + surface name. Use [path:line](path#Lline) for components.

### Explanation
What is wrong, anchored to V1 vocabulary. Reference §-numbers from
api-contract-v1.md or v1-smoke-test-checklist.md when applicable.

### Risk
The user-flow consequence — not "looks bad," but "blocks claim flow on
mobile" or "causes a vouch to fire twice."

### Recommended Fix
Concrete. If the fix is a server-side view-model change, say so —
don't paper over it on the client.

### Example (optional)
Screenshot reference or code excerpt.
```

End with a rollup:

```
## Summary
- **Quality bars:** Lighthouse perf X / a11y Y. Mobile: pass / issues.
  Reduced motion: pass / issues. Keyboard nav: pass / issues.
- **Issue counts:** N critical, N high, N medium.
- **Top theme:** e.g. "Composer target persistence broken across
  three surfaces — single fix in `<ComposerModal>`."
- **Recommended order of fixes:** [...]
```

---

## Severity guidance

- **Critical** — blocks a smoke-test row, breaks an anon-shape
  guarantee, fails a quality bar (Lighthouse <80 perf or <90 a11y),
  reduced-motion regression, leaks business logic into the client
- **High** — flow friction the user can work around but shouldn't
  have to (modal step jump, bad empty state on a hot path)
- **Medium** — polish on a non-hot surface, copy clarity, tooltip
  missing
- **Low** — cosmetic, alignment, asset weight

If you can't decide between two levels, pick the higher one.
