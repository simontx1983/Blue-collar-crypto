# CosmWasm CW-721 collection discovery

Blue Collar Crypto performs one resumable historical CosmWasm discovery
backfill per supported chain. Afterward, it incrementally checks for new
code IDs and new contracts under confirmed CW-721 code families. Settled
historical contracts are not routinely rescanned. Every probable NFT
collection enters an unverified admin queue, and only an administrator
can approve it for community provisioning.

That paragraph is the whole design. The rest of this document is what it
means in practice, what it deliberately does not promise, and how to
operate it.

**Owning code:** `bcc-trust` — `app/Domain/Onchain/{Services,Workers,Repositories,Support}`
**Tables:** `wp_bcc_cosmwasm_code_families`, `wp_bcc_cosmwasm_contracts`,
plus the `cw_*` columns on `wp_bcc_chain_checkpoints`
(see [database-schema.md](database-schema.md)).
**Operator surface:** wp-admin → BCC System → **Verify Collections** →
the *CosmWasm collection scanner* panel.

---

## 1. What it replaced, and why

The previous loop learned a chain's CW-721 code IDs by sampling
**already-curated collections**. A code family with nothing curated under
it was never sampled, so it was never enumerated, so it was never
discovered — a chain with zero curated collections discovered nothing,
forever. `BCC_CW721_CODE_IDS` was an allowlist that bounded the search,
which made the closed loop worse rather than better.

Discovery now starts from the chain itself: enumerate the wasm code
listing, classify each code family, enumerate the contracts of the
families that are CW-721, and test them.

---

## 2. Accuracy of the claim

Discovery **incrementally enumerates and tests every reachable CosmWasm
code and contract using the supported classification rules.**

It is **not** a guarantee that every custom NFT implementation will be
identified. A contract that implements NFT semantics without answering
any of the supported queries is not detected by rules that ask those
queries — by construction, not by oversight. When such a family is found,
the answer is a new probe plus a classifier-version bump (§8), not a
looser rule.

Nor does it promise every contract is reachable. A chain whose LCD is
down is retried, not skipped forever; a chain with no wasm module at all
is skipped forever, on purpose (§11).

---

## 3. The classification states

Both the code-family table and the contract table use the same five
states.

| State | Meaning | Re-checked? |
|---|---|---|
| `confirmed_cw721` | Counted its tokens **and** returned a collection name. | No — classification is done. Its family is still swept for NEW contracts. |
| `probable_cw721` | Half the evidence, with the other half a **definite refusal** from the contract: counts tokens but refuses both collection-info variants, or names itself but definitively cannot count tokens. | No. Surfaced to the admin queue with lower confidence. |
| `not_cw721` | **Every** probe was decisively refused by the contract. | **Never.** Terminal. This is the state that makes the whole scheme affordable, and the state a node hiccup must never be allowed to produce. |
| `inconclusive` | No answer either way: nothing has been deployed from the code yet, no contract exists at the address, or a reply could not be read. | Yes, under the retry cap and backoff. |
| `temporarily_unreachable` | The **node** failed — VM error, panic, 5xx, timeout, transport. Says nothing about the contract. | Yes, under the retry cap and backoff. |

### The probe set

Three smart queries, deliberately minimal:

1. `contract_info {}` — classic cw721 (≤ v0.18) answers `{name, symbol}`.
2. `get_collection_info_and_extension {}` — cw721 v0.19+ renamed the
   variant. The Stargaze collections re-instantiated on the Cosmos Hub
   (SG721, code 434) reject `contract_info` outright and answer only
   this.
3. `num_tokens {}` — the one that earns its round trip. A launchpad
   **minter** answers `config` and `minter` and often carries a
   collection-shaped name in its own state, so "something answered a
   collection-info query" is not evidence of NFT-ness. `num_tokens` is
   part of the CW-721 base query enum and a minter does not implement it.

CW2 `contract_version` was evaluated and **rejected**: it is a raw-state
read rather than a smart query, its `contract` field is a free-form crate
name with no registry (`crates.io:cw721-base`, `crates.io:sg721-base`,
every fork's own name), and it decides nothing `num_tokens` has not
already decided.

### Error discrimination is load-bearing

`not_cw721` is terminal, so mistaking a node hiccup for a negative
verdict is permanent data loss. wasmd's error **body** carries the
distinction and the HTTP status does not — both arrive as a non-200
`{"code":3,"message":"…"}`:

- `Error parsing into type …` / `unknown variant` / `expected one of` /
  `missing field` → **the contract does not implement that query.**
  Decisive negative evidence.
- `Error calling the VM` / `panicked` / `out of gas` /
  `Querier system error` / `rpc error` / `connection refused` / 5xx /
  timeout → **node-side.** Not evidence about the contract at all.

Matching is by substring token against the JSON `message` field only —
never the whole raw body — and only a bounded, sanitized ≤255-character
excerpt is ever persisted.

Two rules follow from this and both are enforced in the state machine:

- A mix of "unsupported" and "node error" settles
  `temporarily_unreachable`, never `not_cw721`.
- A half-answer whose remainder is unknown settles
  `temporarily_unreachable`, never a guess.

### Family sampling

A code family is classified by probing at most **3** of its contracts,
not all of them. One `confirmed_cw721` sample settles the family
immediately. Sample verdicts reduce to a family verdict with
`not_cw721` **last**, not first: one live CW-721 instance proves the
family implements CW-721 even if a sibling instantiation is dead, and a
node failure on one sample must not out-vote a positive answer from
another. A family only settles negative when every sample said so
decisively — and when it does, **its remaining contracts are never
requested.**

### Checksum reuse

Two code IDs with the same `data_hash` are the same binary
byte-for-byte, so an already-settled twin on the same chain answers the
question "does this code implement the CW-721 query set?" for **zero
requests**. 12–20% of families share a checksum with another (measured).

Checksum reuse accelerates **family classification only**. It cannot and
does not: verify a collection, skip a per-contract liveness probe, bypass
a spam/deny rule, or imply that two instances share collection metadata
(no name, symbol, supply or artwork is copied, and the twin's
`sample_contract` is deliberately not inherited). Only settled,
binary-determined verdicts propagate — `temporarily_unreachable` and
`inconclusive` describe a node or a sample, not the binary, so they never
spread.

---

## 4. The schedule

| Pass | Hook | Cadence | What it does |
|---|---|---|---|
| Historical backfill | `bcc_cosmwasm_backfill_tick` | every 5 minutes, **on demand / while enabled** | One chain slice: drain more of the code listing, then classify and enumerate with whatever budget is left. |
| New code IDs + new contracts | `bcc_cosmwasm_daily_discovery` | **daily** | (a) reverse-walk the code listing for newly-uploaded code IDs; (b) reverse-walk the contract listing of drained CW-721 families for newly-instantiated contracts; (c) classify whatever is queued; (d) emit classified CW-721s to the admin queue. |
| Retry sweep | `bcc_cosmwasm_weekly_retry` | **weekly** | Re-runs the same classification work on a slower cadence for `inconclusive` / `temporarily_unreachable` rows whose backoff has expired. |
| Migration + metadata | `bcc_cosmwasm_metadata_refresh` | **monthly**, via a daily hook and a durable ≥30-day elapsed guard | Re-reads each CW-721 family's sampled contract to notice a code migration; refreshes mutable metadata. |

**There is no routine reclassification** of a settled `not_cw721` family,
a decided CW-721 family, or an already-inspected contract. The durable
inventory row *is* the memory, and every work query filters on it.

The weekly pass has **no special "retry" query**. The ordinary pending
queries already encode the whole policy — cap, backoff, exclusion of
settled negatives and of decided CW-721s — so the retry pass is the same
work on a slower cadence. One policy, one place; a second retry query is
exactly how the two would drift.

wp-cron has no monthly interval and none was invented. The monthly pass
rides the daily hook and is gated by
`wp_bcc_chain_checkpoints.cw_metadata_refreshed_at`.

### Fail-closed gates

| Constant | Governs | Undefined means |
|---|---|---|
| `BCC_COSMWASM_DISCOVERY_ENABLED` | **All** scheduled discovery, including the backfill. | OFF |
| `BCC_COSMWASM_BACKFILL_ENABLED` | The historical backfill specifically, **in addition to** the gate above. | OFF |
| `BCC_COSMWASM_REQUEST_BUDGET` | Requests per worker invocation. | The documented default of 50 (capped at 500). |
| `BCC_COSMWASM_CHAIN_ALLOWLIST` | A temporary canary scope — comma-separated chain **ids**. It can only ever NARROW the scanned set. | No extra restriction. Note this one is not a gate, so "undefined" is not "enabled": which chains get scanned is still decided by the per-chain opt-in below. |

A missing constant never means "enabled". There is no environment
sniffing — no `WP_ENVIRONMENT_TYPE`, no host check, no "looks like
staging" heuristic. The backfill gate is AND-ed with the discovery gate,
so turning discovery off stops everything including a backfill in
progress.

A **defined but unusable** allowlist (empty string, `"cosmos,osmosis"`,
`"0,0"`, a non-scalar) scans **nothing**. It does not fall through to
"all chains" — that fall-through is the same fail-open shape as the
retired gate below, and it would fire at exactly the moment an operator
had just typo'd their config. An individual unparseable entry is dropped
rather than promoted, because dropping can only ever make the set
smaller: `"8,osmosis"` narrows to chain 8.

### Which chains get scanned

The environment gates answer "may this install scan at all". A separate,
per-chain answer decides "which chains, on an install that may":

| Condition | Source | Kind |
|---|---|---|
| `is_active = 1` | `wp_bcc_chains` | registry |
| `chain_type = 'cosmos'` | `wp_bcc_chains` | registry |
| `cosmwasm_nft_discovery_enabled = 1` | `wp_bcc_chains` | **operator intent** |
| `cw_discovery_state != 'unsupported'` | `wp_bcc_chain_checkpoints` | **measured capability** |
| id in `BCC_COSMWASM_CHAIN_ALLOWLIST` | wp-config | canary scope (optional) |

All five are ANDed, in one function —
`CosmwasmDiscoveryWorker::eligibleChainIds()` — which every one of the
four passes routes through, so a new pass inherits the policy by
construction rather than by remembering to.

#### Two locks, and both must be open

Opting a chain in does **not** start scanning. There are two independent
locks and a pass runs only when both are open:

| Lock | Where it lives | Scope |
|---|---|---|
| `BCC_COSMWASM_DISCOVERY_ENABLED` | wp-config | the whole install |
| `cosmwasm_nft_discovery_enabled = 1` | `wp_bcc_chains`, per chain | one chain |

An operator can therefore prepare the chain selection on a live install
with the global constant still undefined, and nothing will run. That is
the intended sequence: choose the chains first, open the global lock
second. It also means an accidental opt-in cannot start work by itself,
and neither can defining the constant on an install where no chain has
been selected.

Historical backfill is a **third** lock on top of those two:
`BCC_COSMWASM_BACKFILL_ENABLED` is checked separately and additionally
requires discovery to be enabled, so backfill can never run on its own.

The canary allowlist can only ever **narrow**. A chain named in
`BCC_COSMWASM_CHAIN_ALLOWLIST` that is not otherwise eligible stays
excluded — the allowlist removes chains from the eligible set and never
adds one to it. Undefined means "no canary restriction", not "everything
allowed": the four permanent conditions still apply in full. Defined but
empty, malformed or naming no usable id means **no chain is eligible**,
because a configured-but-unreadable restriction is treated as a
restriction, not as an absence of one.

#### Turning a chain on or off

wp-admin → **BCC System → Verify Collections** → *CosmWasm collection
scanner*, per chain row. The two actions are:

| Action | Effect |
|---|---|
| `cw_discovery_on_<chain_id>` | sets `cosmwasm_nft_discovery_enabled = 1` |
| `cw_discovery_off_<chain_id>` | sets it back to `0` |

Both require `manage_options` **and** a valid nonce; the capability is
re-checked inside the handler rather than relying on the page-level
check, so the gate sits on the write itself. The write goes through
`ChainRepository::setCosmwasmNftDiscoveryEnabled()`, which busts the chain
cache as part of the write, and the flag is then **read back** — a
disagreement is reported as an error rather than as success. Both the
transition and any refusal are audit-logged.

Turning a chain off touches **only** that column. The chain keeps working
everywhere else on the platform — Halls, validators, wallet linking,
holdings, holder-group gating — and nothing already discovered is removed
or un-verified.

`cosmwasm_nft_discovery_enabled` ships **DEFAULT 0 and is never
backfilled**, so the migration that adds it enables discovery on zero
chains: an install that updates the plugin keeps scanning nothing until
someone opts a chain in. Intent and capability are deliberately separate
columns in separate tables: intent is a decision a human makes,
capability is a fact the chain reported (the HTTP 501 that produces
`unsupported`). One hand-maintained "supports CosmWasm" flag would let an
operator assert a wasm module that does not exist, and would leave the
501 the code already learns with nowhere to live.

Every unsure branch answers with FEWER chains. If the column is missing
because the migration has not run, no chain is eligible — never "the
field is absent, so skip that filter". If the checkpoint read fails, no
chain is eligible — a read that did not run is not evidence that a chain
is supported. The one deliberate exception is a chain with **no
checkpoint row yet**, which IS allowed through: the first pass is what
creates the measurement, so refusing an unmeasured chain would be a
permanent deadlock dressed up as caution.

The retired `BCC_CW721_DISCOVERY_ENABLED` was fail-**open** (`if
(!defined(...)) return true;`), so every environment ran discovery unless
someone remembered to switch it off. It is gone.

**Consequence, and it is intended:** an environment that does not define
`BCC_COSMWASM_DISCOVERY_ENABLED` does no discovery. Staging must define
it or discovery stops there.

Registration is not permission: the cron hooks self-heal onto the
schedule regardless of the gates, and every handler re-checks the gate
before doing work. A scheduled hook on an environment that has not opted
in is a no-op that costs one function call. There is **no**
activation-time backfill, no migration-triggered backfill and no
request-triggered discovery, so a deploy cannot kick off tens of
thousands of LCD requests.

---

## 5. Per-run limits

Every pass is bounded twice — by a wall clock and by a request budget —
and **the wall clock always wins**.

| Limit | Value | Why |
|---|---|---|
| Wall-clock deadline per invocation | 20 s | Hostinger Business shared caps PHP `max_execution_time` at 30 s; 20 leaves headroom for teardown. Being killed mid-write is the failure mode that actually costs progress, so a tick with requests left but no clock left stops anyway. |
| Request budget per invocation | 50 (override up to 500) | A fast node must not turn a 20-second window into hundreds of LCD calls. |
| Chains per backfill tick | 1 | Least-recently-worked first, so a chain that keeps failing cannot monopolise the ticks or starve the others. |
| Code-listing page size | 100 | |
| Contracts sampled per family | 3 | Enough to survive a couple of dead instantiations without turning family classification into a per-contract sweep. |
| Families classified per chain per pass | 25 | |
| Contracts classified per chain per pass | 25 | |
| Families enumerated per chain per pass | 10 | |
| Collections emitted per chain per pass | 25 | |
| Migration checks per chain per monthly pass | 10 | |
| Rows requeued per chain on a classifier bump | 100 | |
| Reverse code-tail pages per chain per daily pass | 5 | Typical daily cost is **one** request per chain — the walk stops the moment it meets the watermark. |
| Reverse contract-tail pages per family | 3 | |
| Operator "Force retry" rows per table per chain | 100 | The button is a nudge, not a reset: comfortably more than one scheduled pass (25 + 25) can chew through. |
| Operator "Run backfill slice" budget | 20 requests / 8 s | Smaller than a cron tick because it runs inside an admin page load. A slice that ties up the browser for 20 s gets clicked twice, and the second click is a wasted advisory-lock miss. |

Each chain runs under a **non-blocking** advisory lock in `try/finally`,
so two overlapping invocations (a cron tick and an admin click) cannot
interleave cursor writes — the second one skips.

Every chain also runs in its own `try/catch` and stamps its own
`cw_last_discovery_at` even when it fails. That stamp is what stops a
broken chain being re-picked first forever and starving the rest.

---

## 6. Cursor semantics

### The code-ID watermark (incremental)

`cw_max_code_id` is **contiguous by contract**: "every code ID at or
below this is inventoried."

The daily pass walks the code listing **newest-first**
(`pagination.reverse=true`), ingests each page fully, and stops as soon
as a page reaches a `code_id <= watermark`. The comparison is numeric,
not positional, so gaps in the ID sequence are harmless.

The watermark only advances on **proof** that the walk actually met it.
If the walk ran out of pages or budget first, the IDs between the
watermark and the walk's lowest page are still missing, and advancing
would strand them permanently. In that case the pass:

- does **not** advance the watermark,
- does **not** report success,
- hands the catch-up to the historical backfill by clearing the chain's
  cursor and recording the reason in `cw_last_error`, so the chain is
  visibly degraded rather than quietly wrong.

**An empty page is not authoritative.** With a watermark of 0 the chain
genuinely has nothing inventoried and an empty first page means "no code
IDs". With a watermark above zero we *know* code IDs exist, so an empty
page contradicts stored state; it is reported as an anomaly and handed to
the backfill, never as "nothing new".

### The opaque page key (historical)

The backfill resumes from `cw_code_cursor`, an opaque `pagination.key`.
Opaque keys are **minted by a node**, and `rest.cosmos.directory`
round-robins across nodes, so a key stored from one node may be rejected
or misread by the next.

A resumed page that comes back **failed**, or **empty-and-final**, is
therefore not evidence that the walk finished — accepting it as
"complete" would silently truncate the inventory while every health
signal read green. Both cases clear the cursor and restart that chain's
walk from the beginning. Restarting is cheap and safe: every write is
idempotent under `uk_chain_code` / `uk_chain_contract`.

A completed walk also clears the cursor, so a stale key can never be
replayed against a re-indexed node.

### Per-family contract enumeration

Each CW-721 family has its own `contracts_cursor` plus
`contracts_enumerated`, an **absolute** high-water mark written with
`GREATEST()` so it is monotonic. It is absolute rather than a delta
because the incremental tail pass deliberately re-reads an overlap, and a
delta would be corrupted by that (and by any duplicate LCD page).

Progress is written **before** the tick's deadline can bite, which is
what makes the walk resumable rather than restartable.

Once a family's forward walk drains, the daily pass checks it for new
contracts with a **reverse** tail read that stops at the first address
already inventoried. That stop condition is sound precisely because the
forward walk completed: everything older than a known address is
necessarily inventoried too. If that reverse read comes back empty for a
family we know has contracts, the family is **reopened** for a forward
re-walk rather than being declared unchanged.

### `pagination.offset` is never used

The first implementation tailed the listing with
`pagination.offset = knownFamilyCount`. **Measured 2026-08-06: any
non-zero offset returns an EMPTY list with HTTP 200 on cosmoshub, juno,
osmosis and injective.** An empty 200 is not an error, so no retry fires
and the pass concludes "nothing new" forever — daily discovery would have
been permanently dead on the four biggest chains while every health
signal read green.

Offset must not be reintroduced. The reverse-walk watermark exists
because of this measurement.

---

## 7. Retry behaviour and caps

- **Cap:** 6 attempts. After that a row stops being swept automatically.
- **Backoff:** staged/exponential — 6 h, 12 h, 1 d, 2 d, 4 d, 8 d, …
  capped at 28 days.
- The target is **precomputed** into `next_attempt_at`, so retry
  selection is one bounded indexed range scan rather than a per-row
  backoff recomputation.
- `not_cw721` is never retried. That exclusion lives in SQL, in the
  repository's pending queries, so no caller can forget it.
- Denied contracts are never retried: `denied = 0` is a hard predicate on
  the work queue, so a hidden contract never burns a probe.

**Force retry** (admin panel, per chain) clears the backoff *and* the cap
for up to 100 unresolved code families and 100 unresolved contracts. It
touches only `inconclusive` and `temporarily_unreachable`. It will not
resurrect a settled `not_cw721`, will not redecide a CW-721, and will not
un-hide a denied contract — a force-retry button must not become a back
door around the operator's own hide decision.

---

## 8. Classifier versioning

`CosmwasmClassifier::VERSION` is bumped when the probe set or the
decision rules change. A bump does **not** sweep the inventory. Only
`inconclusive`, `temporarily_unreachable` and `probable_cw721` are
requeued (100 rows per chain per pass), by clearing the decision
timestamp and the backoff so the ordinary pending query picks the row up.

`not_cw721` is deliberately excluded: a settled negative is never
routinely reclassified, and a version bump is routine. `confirmed_cw721`
is excluded because re-proving it costs requests and changes nothing.

The previous verdict stays readable until a new one replaces it.

---

## 9. `BCC_CW721_CODE_IDS` — new semantics

**It is no longer an allowlist. It does not bound discovery.**

Format is unchanged for compatibility: a JSON object mapping chain slug →
list of code IDs, e.g. `{"cosmos":[434,467]}`. Its permitted roles now,
and only these:

1. **Priority hint.** The named code IDs are classified and enumerated
   first, ahead of the ordinary walk order.
2. **Recovery override.** Push a specific family back to the front after
   a bad classification or an outage.
3. **Manually-confirmed family hint.** "I checked this one myself, look
   at it early." It still gets classified normally — it does **not** skip
   probing and it does **not** imply `confirmed_cw721`.
4. **Fallback** for a chain whose LCD cannot list codes at all while
   `/code/{id}/contracts` still works.

What it may never do again: restrict which code IDs discovery is allowed
to see. An empty or absent constant means "no priority hints", never
"discover nothing". The hint is capped at 50 IDs per chain so a
pathological `wp-config.php` cannot widen the query.

---

## 10. The admin approval boundary

**Discovery cannot verify anything, and cannot provision anything.**

- Emitted rows land on the collections-table default `is_verified = 0`.
  Nothing in the discovery path sets that flag.
- The discovery path never calls a provisioning service.
- The only way a holder community is created is an administrator
  verifying the collection in **Verify Collections** (or the daily
  provisioning sweep acting on an already-verified row).

Emission is also the point where the most guarantees apply. It is the
only path that can create a user-facing row, so it:

- re-checks the **live** deny rules per contract *with* the resolved
  collection name, so the name heuristics apply and an explicit
  `RULE_ALLOW` can override them;
- **skips a contract that already has a collection row.** The bulk upsert
  assigns `collection_name` and `image_url` from the incoming row, and a
  discovery row carries a freshly-probed name but no artwork — re-emitting
  would wipe an operator-curated image.

`RULE_ALLOW` overrides the name heuristics and nothing else. An allowed
contract still arrives unverified.

---

## 11. Rejection and reconsideration

Hiding and unhiding reuse the existing operator rule table
(`wp_bcc_nft_spam_contracts`) — discovery does not build a second
rejection system.

- **Hide** writes `RULE_DENY` on the contract. A deny rule is what
  survives rediscovery; deleting the collection row does not, because the
  next sweep (or the next wallet link) would land it again.
- **Unhide** writes `RULE_ALLOW`, which clears the cached flag and
  genuinely permits later discovery and explicit retry, rather than
  leaving the candidate permanently suppressed.

Deny is threaded through **four** points, because row-presence is not a
deny check:

1. **Intake.** Every enumerated address is resolved against the rule
   table before it is written. A denied address is still **recorded**
   (with `denied = 1`) rather than dropped — dropping it would make it
   look brand-new on the next sweep and it would be rediscovered and
   re-logged as a fresh candidate forever. Stored-and-suppressed is what
   makes the rule survive rediscovery.
2. **The work queue** excludes denied rows, so a denied contract never
   burns a probe.
3. **Emission** re-checks the live rule table per row, because a rule can
   land between two sweeps.
4. **Hide/unhide** syncs the cached flag onto the inventory, so unhiding
   really does permit later discovery.

The `denied` column is a **cache**, never the authority. The rule table
is the source of truth and every write path re-consults it.

---

## 12. Migration handling

CosmWasm contracts can be **migrated** to a different code ID while
keeping their address. The monthly pass re-reads each CW-721 family's
sampled contract's current code ID.

When it changed, the existing inventory row is **re-pointed** and
`migrated_at` is stamped. It does not create a second contract row and it
does not create a second collection: the address is the identity, and the
collection for that address already exists.

The classification is deliberately **not** reset. The running contract is
the same contract; re-probing it is the monthly pass's job, not a side
effect of noticing the move.

---

## 13. Metadata refresh

Mutable collection metadata (name, and whatever the enrichment path
maintains) is refreshed on the same monthly cadence as the migration
check, guarded by `cw_metadata_refreshed_at`.

Immutable facts are not re-fetched. In particular the code binary itself
is never downloaded: `checksum` comes from the code **listing**'s
`data_hash` field, because `/cosmwasm/wasm/v1/code/{id}` returns the
entire wasm binary base64-encoded in its `data` field.

---

## 14. Inspecting scanner health

wp-admin → **BCC System → Verify Collections** → *CosmWasm collection
scanner*.

The panel is built from **four bounded aggregate queries for all chains
combined** — not one query per chain, and never a per-row recompute. It
shows:

**Status and gates**

The overall verdict is one of seven values, decided in a fixed order. The
order matters more than the individual values: several of these states are
simultaneously true in ordinary operation, and the one that wins is the one
an operator can act on.

| # | Status | When | Colour |
|---|---|---|---|
| 1 | `unavailable` | a required read failed | red |
| 2 | `idle` | no chain is opted in | grey |
| 3 | `blocked` | chains are opted in, but none can be scanned | amber |
| 4 | `disabled` | `BCC_COSMWASM_DISCOVERY_ENABLED` is undefined | grey |
| 5 | `red` | a scheduled pass is missing, or the registry is empty | red |
| 6 | `yellow` | a chain errored, everything is stale, or a pass is overdue | amber |
| 7 | `green` | scannable chains, scheduled passes, fresh runs | green |

**`unavailable` outranks everything**, and is not decided with the others —
a failed read returns before the verdict is computed at all. This is
deliberate: the failure path carries an *empty* chain list, so "nobody
opted a chain in" is trivially true of it, and a database outage reported
as a calm "Idle — nothing to do" would be the green-with-zeroes lie in a
quieter voice. Worse, because idle invites no action.

**`idle` and `blocked` both outrank `disabled`** for the same reason. Each
means the scanner has nowhere to go, so defining the environment constant
would change nothing — leading with it sends an operator to edit
`wp-config.php` for no effect. The constant is still named in both notices,
so no fact is hidden; opt a scannable chain in and `disabled` returns.

**Neither `idle` nor `blocked` is a fault.** They are configuration states,
rendered informational and warning respectively, never as errors. An
operator who has not turned something on is not looking at a broken system.
An **empty registry**, by contrast, stays `red` — that is not somebody
declining to scan, it is a registry with nothing in it.

`blocked` has exactly three causes, and the panel names which applies to
each chain:

| Cause | Meaning |
|---|---|
| paused | an operator paused the chain; resume returns it |
| no CosmWasm module | the chain answered the code listing with HTTP 501 |
| outside the canary allowlist | opted in and supported, but `BCC_COSMWASM_CHAIN_ALLOWLIST` names other chains |

The 501 case is durable in a specific sense worth stating precisely: no
scheduled pass retries an `unsupported` chain, and no admin control clears
the state — pause refuses on it and resume acts only on a paused chain. It
is not metaphysically permanent (the row is a database row like any other),
but nothing in the product will re-evaluate it.

**The verdict and the per-chain eligibility column agree by construction.**
The status arithmetic counts exactly the chains the worker's selector would
walk, rather than re-deriving its own idea of "eligible" — a panel that
counted differently from the worker is precisely the defect this section
exists to describe. A chain excluded for any reason cannot make the scanner
look healthy, and equally cannot make it look degraded: an error stamp on a
chain the worker never touches is not a fact about the scanner.

**Per-chain eligibility**

Every listed chain carries a verdict and, when it is not eligible, the
reason. Without this an operator sees chains listed that will never be
scanned and no explanation — the same failure the `unsupported` case
already avoided:

| State | Meaning | What to do |
|---|---|---|
| **Eligible** | Nothing is blocking it. It is in the rotation whenever discovery runs. | — |
| **Not opted in** | Discovery is switched off for this chain. | Enable it on the row |
| **No wasm module** | The chain answered the code listing with HTTP 501, so it is permanently skipped. | Nothing — opting it in would change nothing |
| **Outside canary scope** | Opted in and supported, but `BCC_COSMWASM_CHAIN_ALLOWLIST` names other chains. The only case with no other visible symptom. | Widen or remove the allowlist |
| **Unknown** | The opt-in could not be read. Treated as **not** eligible. | Check the error log |

The opt-in state is shown separately from the verdict, so a chain that is
opted in but unsupported reports both facts rather than hiding one behind
the other, and its toggle stays available in every state — an opted-in
unsupported chain is never stranded.

`Unknown` exists because the alternative is worse: a missing or
unreadable status must never render as eligible. Green is reserved for
`Eligible` and the colour map defaults to the unknown treatment.

**Counts**
- Code families known · classified CW-721 · classified non-NFT ·
  inconclusive · awaiting classification · contracts inspected ·
  unverified candidates · hidden by a rule.
- "Contracts inspected" counts rows actually probed, which is
  deliberately not the same as rows inventoried.

**Schedule**
- Per hook: cadence, next run, and whether it is registered at all. A
  hook that is not scheduled is red — that pass will never run.

**Per chain**
- State (`idle` / `backfilling` / `backfilled` / `unsupported` /
  `paused`), which chain is currently being worked, and which chain the
  next backfill tick will take.
- Progress as a **cursor and a watermark, never a percentage** — see
  §16 for why there is no trustworthy denominator.
- Last successful pass (as an age), last recorded error (the sanitized
  excerpt, behind a disclosure), and the last monthly migration check.

**Per candidate** (a sub-row under each Cosmos collection the scanner
knows about)
- Chain, contract address, collection name, code ID, checksum/family,
  token count when the upstream reported one, first-discovered timestamp,
  classification with a coarse confidence word, verification/rejection
  state, and an explorer link.
- The detection reasons are rendered as a **sentence**, not as the stored
  evidence tokens. The raw upstream message is offered behind a
  disclosure and labelled as raw — it is the one field whose content we
  do not control.

Confidence is a word (`high` / `medium` / `low` / `none`), never a
percentage: the classifier produces a verdict from a fixed probe set, not
a score.

The scanner also appears in the plugin's structured logs under
`[CosmwasmDiscovery]` / `[CosmwasmDiscoveryWorker]`.

---

## 15. Safe pause and resume

Pause is **durable state the worker honours**, not a UI flag:
`wp_bcc_chain_checkpoints.cw_discovery_state = 'paused'`. There is
deliberately no second switch — a parallel flag is how "paused in the UI,
still hammering the LCD" happens.

A paused chain is skipped in two places: the worker refuses to prepare
it, and the backfill rotation query excludes it.

- Pausing is **per chain**, so one chain can be worked at a time.
- Pausing **keeps all progress**. Cursors, watermarks, classifications
  and retry state are untouched.
- Pausing a chain marked `unsupported` is refused — that state is already
  terminal, and overwriting it would lose the durable "no wasm module"
  fact.
- **Resume re-derives the state from the chain's own progress**, because
  pausing overwrote the previous value and there is nowhere it was kept.
  Backfill completed → `backfilled`. A cursor or a non-zero watermark →
  `backfilling`. Nothing learned yet → `idle`.

  That derivation is load-bearing: resuming a drained chain to `idle`
  would make the backfill re-walk its entire code listing, because the
  backfill's first phase runs for every state except `backfilled`.

**Run backfill slice** executes one bounded slice immediately instead of
waiting for the next tick. It respects the same gates, the same advisory
lock, the same budgets and the same durable progress writes, so clicking
it is equivalent to the next scheduled tick arriving early. It is refused
on a paused chain — resume first.

Turning `BCC_COSMWASM_DISCOVERY_ENABLED` off is the global stop. It halts
every pass on every chain, including a backfill in progress, without
losing progress.

---

## 16. Chain API limitations

All of the following were **live-probed on 2026-08-06**.

| Chain | Finding |
|---|---|
| `cryptoorgchain` | Returns **`501 Not Implemented`** on the wasmd endpoints — it has no wasm module. **Permanently unsupported.** The chain is marked `unsupported` and skipped, because retrying it forever would burn budget on a guaranteed failure. |
| `kujira` | Its configured LCD is **unreachable** — 502 across three endpoints. **Capability unknown, not absent.** It keeps its state and is retried; it is *not* marked unsupported. |

**Pagination**

- **`pagination.count_total` is honoured only by Jackal.** This is why
  there is no percentage anywhere in the scanner: for every other chain
  there is no trustworthy denominator to divide by.
- **`pagination.offset` is broken on cosmoshub, juno, osmosis and
  injective — any non-zero offset returns an EMPTY list with HTTP 200.**
  It works only on Jackal. This is why discovery uses reverse-walk
  watermarks, and why offset must never be reintroduced (§6).
- **`pagination.reverse` is verified working** on both `/code` and
  `/code/{id}/contracts`.

**Node topology**

`rest.cosmos.directory` round-robins across nodes, so an opaque
`pagination.key` minted by one node can be rejected or misread by a
different one. Every cursor-resume path treats that case as a stale key
and restarts, rather than accepting a truncated walk (§6).

**Measured code counts**

| Chain | Code families |
|---|---|
| juno | 5,149 |
| injective | 2,081 |
| osmosis | 1,900 |
| cosmos | 713 |
| dungeon | 176 |
| akash | 5 |
| jackal | 5 |

Roughly **50% of code families have zero contracts** and roughly **5% are
CW-721**. The overwhelming majority of what discovery learns is therefore
negative knowledge — "this is a minter", "this is a factory", "nothing
was ever deployed from this code" — which is exactly why that knowledge
lives in its own tables and not in the collections table.

---

## 17. The planned first live run (Dungeon-only canary)

**Not yet run. Nothing below is a claim that it has been.** At the time of
writing every chain is opted out, both CosmWasm constants are undefined,
and both CosmWasm tables are empty on every environment.

The first live exercise is scoped to **one chain: Dungeon Chain (id 17)**,
on staging only. It was chosen on measured evidence rather than
preference:

- **179 code families.** The incremental reverse walk stops at the first
  code id at or below the watermark, and a fresh install has a watermark
  of 0, which nothing satisfies — so the walk runs until it exhausts its
  page budget of 5 × 100 = 500 families. A chain **under** that ceiling
  therefore reaches the bottom, the result is authoritative, and the
  watermark advances. A chain over it ingests 500, correctly refuses to
  advance, requests a backfill restart, and — with backfill disabled —
  sits degraded indefinitely. That single number is what makes a chain a
  viable discovery-only subject.
- **It has real CW-721 collections**, so the run can prove the whole path
  rather than only proving that nothing exploded: code 3 (*AshFall: Lost
  Artifacts*) and code 6 (*BBL Test NFTs*, 55 minted tokens).
- **Its probes answer.** Contract metadata resolves and smart queries
  return well-formed CosmWasm variant errors, which is the evidence the
  classifier records for `not_cw721`.

Chains deliberately **not** chosen: Cosmos Hub (723 families) and
Injective (2,082) both have real NFT populations but sit past the ceiling;
Juno (5,149) and Osmosis (1,900) have no collection evidence at all;
Jackal and Akash have 5 families each and no CW-721; Cronos POS has no
wasm module (501); Kujira's endpoint returned 502 on two probes weeks
apart, so its capability is unknown rather than absent.

Historical backfill stays **disabled** throughout. The canary exercises
ordinary incremental discovery only — on Dungeon, that is itself the
bounded slice, which is why no `BCC_CW721_CODE_IDS` override is planned:
pointing discovery at codes 3 and 6 would guarantee a candidate while
masking whether unaided discovery finds them, and that is the question the
run exists to answer.

---

## 18. Expected discovery delay

**Historical backfill: days, not hours.** With one chain per five-minute
tick and 50 requests per tick, a chain the size of juno (5,149 families)
takes on the order of a day of its share of the ticks to classify, and
the full set of supported chains takes several days of wall-clock time
for a first complete pass. Checksum reuse (12–20% of families) and the
three-sample rule (a non-NFT family settles without enumerating its
contracts) are what keep that number in days rather than weeks.

Backfill duration scales with the number of chains sharing the rotation,
and the rotation only ever contains chains an operator has opted in
(`wp_bcc_chains.cosmwasm_nft_discovery_enabled`). So the way to make one
chain finish sooner is to keep the others out of the rotation: leave them
un-opted-in, or pause a chain that is already in it. Pausing preserves
progress and is reversible from the admin panel; the opt-in column is the
durable statement of which chains this install scans at all.

**After the backfill, steady state:**

| Event | Typical time to appear in the admin queue |
|---|---|
| A new code ID is uploaded to a chain | Seen within **24 h** (daily reverse tail read; typical cost one request per chain). |
| A new contract is instantiated under an already-**confirmed** CW-721 family | Seen within **24 h**, then classified and emitted on the same or the next daily pass. |
| A new contract under a family that has **not yet been classified** | Waits for that family's classification, which is queued behind the backfill/daily budgets. |
| A row that failed on a node error | Retried on the staged backoff: 6 h, then 12 h, 1 d, 2 d, 4 d, 8 d — up to 6 attempts. **Force retry** short-circuits the wait. |
| A contract migrated to a new code ID | Noticed on the monthly pass. |

These are the cadences, not an SLA. A chain whose LCD is down does not
progress until it comes back, and that is visible in the panel as a
recorded error rather than as silence.

---

## Related

- [database-schema.md](database-schema.md) — the two new tables and the
  `cw_*` checkpoint columns.
- [cron-registry.md](cron-registry.md) — the four hooks in the
  ecosystem-wide cron inventory.
- [pattern-registry.md](pattern-registry.md) — canonical implementations.
