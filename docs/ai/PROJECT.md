# Project: what Blue Collar Crypto is, and the principles behind it

## In one paragraph

Blue Collar Crypto (BCC) is a community and reputation platform for the people who run crypto infrastructure and the people who depend on them. Its subjects are:
- **operators** of validators and **creators** of NFT collections, who can claim the public page for what they run (validators and collections are the only claimable kinds; projects also have pages);
- **members**, who browse, join communities ("Halls" and groups), review, vouch and dispute.

The product's own constitution calls it an *operator intelligence network* ([`docs/trust-attestation-layer.md`](../trust-attestation-layer.md)); the README calls it a *trust & reputation platform*. Both are true:
- the visible product is a social network (feeds, communities, profiles, DMs);
- the reason it exists is a reputation layer people can believe.

## Who it is for

**Both operators and members, with operators seeded first** [DOC, owner decision 2026-07-31]. So claim flows, signal accuracy and dispute fairness come before browsing polish.

**Cold start is the main open problem.**
- Attestation volume is still far below the thresholds that switch on Elite eligibility, reliability standing and the later rank phases, so those mechanisms have little effect today.
- Do **not** tune their constants: there is no real distribution to fit them to.

## Product principles

These override generic "best practice". Each has a reason.

1. **Never about price.** BCC is about understanding a community, not checking a price. This limits what BCC **stores**, not just what it shows:
   - no floor price, volume, sales, listings or balances;
   - the one allowed market touchpoint is a marketplace research link with strict rules (see [DECISIONS.md](DECISIONS.md)).
2. **Ask what behaviour a metric encourages.**
   - There is one canonical number, the Trust Score; don't add competing scores.
   - Public views prefer human prose to raw numbers.
   - Never show a social signal (reactions) under a trust heading.
   - Measure breadth, diversity and durability, not volume.
3. **Anti-viral by constitution.**
   - No leaderboards and no engagement ranking.
   - Attestation-synthesis mechanics stay invisible to users ([`trust-attestation-layer.md`](../trust-attestation-layer.md) §J.4.1).
4. **No cadence pressure.**
   - No streaks, no "you haven't posted in N days", no guilt nudges ([`docs/cadence-pressure-policy.md`](../cadence-pressure-policy.md)).
   - Enforced in CI by `scripts/cadence-pressure-guard.sh`.
5. **Privacy is structural, not a setting.** It is enforced at the server's egress, not by the UI hiding things ([SECURITY.md](SECURITY.md)):
   - wallet addresses are never shown to other users;
   - no verification, claim or join dates are public;
   - analytics are opt-in and pseudonymous.
6. **Fail closed.** If the server cannot *prove* an answer (a failed read, an unknown state), it excludes, denies or answers 503. It never pretends "none" or "allowed". This is the single most repeated engineering rule ([DATABASE.md](DATABASE.md)).
7. **Explain, never silently hide.** A locked or limited control shows the server's reason or unlock hint. A member is told when their standing limits their reach: "no silent shadowbans".
8. **Design for current reality.**
   - Build for the operators who exist today; ask "who exactly is the second user of this, today?"
   - Ship no UI whose only user is hypothetical.
   - Ship the smallest version.
   - No structural redesign unless the project owner reopens scope.

## What BCC is not

- **Not a block explorer.** Halls help people learn about a chain; they don't reinvent chain explorers.
- **Not a trading or portfolio tool, and not a bank.** No token prices.
- **Not a points or engagement game.** Reputation cannot be farmed by volume (see the anti-inflation rules in [FEATURES.md](FEATURES.md) "Trust and reputation").
- **Not a place that publishes on-chain identity links.** A member's wallets and holdings are private (see [`wallet-privacy-policy.md`](../wallet-privacy-policy.md), binding).

## Halls thesis

A **Hall** is an official, admin-created open community for one chain (a *chain Hall*) or one subject (a *topic Hall*).
- **Why people come:** to compare validators, understand delegation, find peers on their chain and get help. Reputation builds up as a by-product.
- **Why the scale is small:** a Hall gathers people locally, so a little activity still means something.
- **What can be seeded:** usefulness can come from indexed chain data; reputation cannot.
- **Where attestations happen:** turning "this person helped me" into a lasting attestation must happen in the same place, not on a separate screen.

Details are in [FEATURES.md](FEATURES.md) "Communities, groups and Halls".

## The system at a glance

There are five public GitHub repos, owned by `simontx1983`. Locally they are checked out into one WordPress + Next.js layout, so it looks like one repo:

| Repo | What it is |
|---|---|
| `Blue-collar-crypto` (umbrella) | cross-cutting docs (`docs/`), agent tooling (`.claude/`), CI guards (`scripts/`, `.github/`) |
| `bcc-core` | WordPress plugin: shared infrastructure (service locator, DB helpers, crypto, throttle, logging, PeepSo writers, admin shell) |
| `bcc-trust` | WordPress plugin: reputation, disputes, on-chain data, and most REST routes |
| `bcc-search` | WordPress plugin: headless search |
| `bcc-frontend` | Next.js app: the only user-facing renderer |

The public site `bluecollarcrypto.io` runs on Vercel. WordPress and its REST API run on `cms.bluecollarcrypto.io`. Read [ARCHITECTURE.md](ARCHITECTURE.md) next.

## Vocabulary

[`docs/glossary.md`](../glossary.md) is the glossary. Some rows are stale; [CURRENT_STATE.md](CURRENT_STATE.md) lists them.

Display words decided in the 2026-11 information-architecture audit:
- **Operator** means only the runner of a claimed page;
- people are **members**;
- backing others is **Vouches**;
- the top trust tier is **Elite**.

Wire names (API field and enum values) are **frozen** when display labels change. For example `stand_behind` stays on the wire although the UI says "Back".

## People and roles

Described by role, not name, because these repos are public:
- **The project owner:**
  - makes product decisions;
  - approves every production deploy and every destructive or data-changing operation;
  - is the final reviewer of this knowledge base.
- **`*/dev-*` branches belong to another engineer.** Never delete them.
- **AI agents** (several Claude Code sessions to date, plus OpenAI Codex from now on) implement, review and operate under the rules in [HANDOFF.md](HANDOFF.md).

## Open product questions

These were undecided as of 2026-11-25. Do not decide them yourself; ask the owner.
- The display name for NFT-creator pages.
- Whether to soften the reach limit for members in the Caution and Risky tiers.
- The ambassador programme, which would evolve mentors into ambassadors. The spec comes first; it would never involve money or tokens.
- Team roles beyond a single owner for validator pages.
- Whether a "Signals" tab returns.
- Whether `bcc-core` and `bcc-search` get repo-level agent instruction files.
