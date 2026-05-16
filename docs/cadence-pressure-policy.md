# Cadence-Pressure Policy

**Status:** stub (PR-11a). Full editorial register lands in PR-11c.
**Enforces:** `scripts/cadence-pressure-guard.sh`
**Threat model reference:** plan §J.5 critical-risk-mitigation item #11
+ §2.7 status-anxiety mitigation + §J.3.2 asymmetric display.

## The rule

Operator-facing copy must not push the operator to attest on a schedule,
nudge them about silence, reward streaks, or imply that "active operators"
are differentiated by recent activity volume.

Concretely, every phrase listed in the table below is rejected by the
mechanical guard (each row carries its own allow marker because the
policy doc must be able to name what it forbids):

<!-- cadence-pressure-guard:allow — enumeration of forbidden phrases -->
| Phrase | Why it's forbidden |
<!-- cadence-pressure-guard:allow — enumeration of forbidden phrases -->
|---|---|
<!-- cadence-pressure-guard:allow — enumeration of forbidden phrases -->
| `haven't` | Implies the operator owes the system action |
<!-- cadence-pressure-guard:allow — enumeration of forbidden phrases -->
| `days since you` | Temporal pressure framing |
<!-- cadence-pressure-guard:allow — enumeration of forbidden phrases -->
| `streak` | Gamification — rewards presence over judgment |
<!-- cadence-pressure-guard:allow — enumeration of forbidden phrases -->
| `you should attest` | Direct prescription |
<!-- cadence-pressure-guard:allow — enumeration of forbidden phrases -->
| `consider attesting` | Softer prescription, same vector |
<!-- cadence-pressure-guard:allow — enumeration of forbidden phrases -->
| `active operators attest weekly` | Implies cadence-as-signal |

The mechanical guard catches these obvious tells. Subtler tone work is
human judgment — that's PR-11b walkthrough + PR-11c register.

## Why

The platform succeeds when operators attest **from real judgment**.
It fails when they cast for the system — when silence reads as a
penalty, when streaks reward presence over thought, when nudges
turn an evidence-based act into a habit. Cadence-pressure is the
canonical path to that failure mode.

The system is **information, not a nudge.** Operators see their
own track record; they don't get pinged when it goes quiet.
"Absence of attestation is not a negative signal" is the load-bearing
sentence from §2.9 onboarding — the entire platform is built around
not betraying it.

## How to allow an exception

The guard supports inline overrides for legitimate uses (a comment
explaining the rule, a doc body describing what NOT to say, etc.).
Place this marker on the same line as the match OR on the line
immediately above it:

```
// cadence-pressure-guard:allow — short reason
```

The reason is for the reviewer — make it specific. "Comment
explaining the §2.7 rule" is fine; "false positive" is not.

## Scope

The guard scans:
- `bcc-frontend/src/app/` (page-level surfaces)
- `bcc-frontend/src/components/` (rendered components)
- `bcc-frontend/src/lib/copy/` (the centralized copy module)
- A short list of bcc-trust PHP services that emit copy
  (`ContestedStateExplainer`, `NotificationDispatcher`,
  `AttestationService`, `PolarizationTransitionNotifier`)
- This policy doc itself

Out of scope: technical PHP/TS where identifiers like `daysSince`
or `lastSeen` legitimately appear — those don't render as
operator-facing English.

## Coming in PR-11b

Subjective tone audit of every operator-facing page in the app —
the kind of judgment the regex can't make. Each visible string
walked through, classified, lifted to the shared `lib/copy/trust-layer.ts`
module when load-bearing, marked with a tone annotation.

## Coming in PR-11c

The full editorial register — "what to say / what not to say"
examples, anti-pattern catalogue, community-management voice
baseline, marketing copy guardrails. The policy doc that this
stub becomes.

## Where this policy applies

Code surfaces — enforced by the guard, plus PR-11b human review.

Out-of-band:
- Marketing site copy
- Email subject lines + body content (DigestService templates)
- Community-management voice (Discord/Slack/forum responses to users)

For out-of-band surfaces the policy is advisory until PR-11c lands
the editorial register that community managers can reference.
