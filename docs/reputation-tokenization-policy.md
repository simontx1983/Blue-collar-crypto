# Reputation Tokenization Policy

**Status:** Locked · 2026-07-28 · applies to any future on-chain issuance of a BCC card
**Owners:** Phillip, Tialuxe

---

## The rule

**If a BCC trading card is ever minted as an NFT, the token MUST be
non-transferable.**

No sale, no transfer, no secondary market, no wrapping in a transferable
container. Soulbound to the account whose standing it represents, or it does
not ship.

This is not a preference about market design. It is the minimum condition
under which a tokenized card is compatible with the product's central claim.

---

## Why

The landing headline is **"Reputation you can't buy."** That is not marketing
garnish — it is the thesis the entire trust engine exists to defend. Vote
weight is tier-scaled, attestations decay, elite-source contribution is capped
at 40%, wallet age damps cast weight, and (as of §J.12) the top tier is gated
behind distinct-attestor, tenure and clean-record conditions. Every one of
those mechanisms is a defense against the same attack: acquiring standing
rather than earning it.

A tradeable token whose visual encodes a trust tier hands that attack a front
door. The buyer does not acquire a *souvenir* of someone's reputation; they
acquire **the artifact that is the signal**. Anyone reading the card reads the
tier, and the tier is now a function of who paid, not who was trusted.

The failure is not partial. It is not "some confusion at the margins" — it is
the literal, complete negation of the sentence at the top of the homepage.

### Disclaimers do not fix it

"This NFT does not represent current standing" printed in metadata does not
help, for the same reason a disclaimer on a forged certificate does not help.
The signal is carried by the visual, in the context where people look for
trust signals. A caveat readable only by someone who already suspects a
problem protects nobody.

### The mint is not the problem — transferability is

A non-transferable card that renders live standing is *fine*, and arguably
good: it is a portable, verifiable statement of something the holder actually
earned, and it cannot be detached from them. Everything that makes tokenization
appealing (portability, verifiability, permanence of the record) survives
soulbinding. Only the resale survives transferability, and resale is the entire
problem.

So the design question is never "should we mint?" It is **"can this token
change hands?"** If yes, stop.

---

## Consequences for design work

- A card may be designed with future tokenization in mind. Nothing in this
  policy constrains the *visual*.
- A card's tier treatment must not be designed as a **scarcity** signal — see
  the v1.56 rarity-vocabulary retirement in
  [api-contract-v1.md §10](api-contract-v1.md). Tiers are earned, dynamic and
  value-laden; NFT rarity works precisely because supply is fixed and scarcity
  is arbitrary. They are different things and the visual language should not
  borrow across.
- If tokenization is ever scoped, the non-transferability constraint enters the
  spec on line one, not as a later hardening pass.

## Related

- [api-contract-v1.md](api-contract-v1.md) §10 v1.56 — rarity vocabulary retired
- [trust-attestation-layer.md](trust-attestation-layer.md) §J.4 — anti-cartel
  caps; §J.12 — the Elite gate
- [glossary.md](glossary.md) §6 — tier vocabulary
