# CosmWasm CW-721 collection discovery — RETIRED

> ## ⛔ THIS SUBSYSTEM HAS BEEN REMOVED. The code is gone, not frozen.
>
> Automated CosmWasm / full-chain NFT collection discovery no longer exists in
> this codebase. There is no administrator control, no cron job, no CLI command
> and no implementation class. **It cannot be re-enabled**, because there is
> nothing left to enable.
>
> This document used to be a 1,000-line operating manual for a running
> subsystem. Everything it described has been deleted. What remains below is
> the record: what existed, how it was taken out, what was deliberately
> **kept**, and where the retained capabilities are documented now.
>
> ⚠ **Do not use an older revision of this file as instructions.** Every
> command, admin control, budget, cursor rule and pause/resume procedure it
> contained refers to code that is not there. The classification *semantics*
> survive, but only inside targeted validation — see §4.

---

## 1. What it was

A per-chain walk of `/cosmwasm/wasm/v1/code` that enumerated CosmWasm code
IDs, classified each code family as CW-721 or not by probing a sample contract,
enumerated the contracts of confirmed families, and recorded candidates for
administrator approval. It had its own durable progress state, retry schedule,
request budgets, advisory locks, run ledger and health snapshot.

It ran on Cosmos chains only, produced *candidates* rather than published
collections, and never auto-approved anything.

## 2. How it was removed

| Step | What it did | Landed |
|---|---|---|
| **Freeze** (#261) | `ScannerFreeze::frozen()` hard-coded `true`, refusing all 13 entry points. Retirement scaffolding, not a setting. | production 2026-09-18 |
| **S1** | Hardened per-request 501 fidelity so "this chain has no wasm module" stopped depending on matching message text. | |
| **S2** | Replaced the Cosmos endpoint switch's scanner coupling: own advisory lock, compare-and-swap instead of a plan digest, authorization record dropped. | |
| **S3** | Detached Hide/Unhide from the scanner deny cache. ⚠ See §4 — this changed what Hide *means*. | |
| **S4** | Withdrew the surface: entry-point registrations, admin sections, the four `*Markup()` twins, the CLI command. | |
| **S5** | The NFT capability model stopped reading `cw_discovery_state`. Stale scanner evidence became **no verdict at all** rather than a durable refusal. | |
| **S6** | Cron retirement: `DiscoveryRunMaintenance::register()` deleted, `bcc_discovery_run_maintenance` moved to `cleanup_only`, unschedule migration shipped with its own `done_option`. | staging `d84c2ac1` |
| **S7** | Retired the `enumeration` operation and the `cosmwasm_enumeration` driver key, with a bounded idempotent override migration. | staging `81935f8b` |
| **S8** | Deleted the implementation: **26 classes, 12,911 production lines**, plus 52 test files. `ScannerFreeze` itself went with them. | staging `75f55065` |
| **S9a** | Removed the seven `cw_*` columns and `cosmwasm_nft_discovery_enabled` from the live `SELECT` lists, and deleted twelve callerless `cw_*` writers. ⚠ **Projection only — every table, column and installer stayed.** | staging `c1ecd1d9` |
| **S9b** | ⏳ **PENDING, NOT MERGED.** Drops the three tables, the eight columns and `idx_cw_discovery`. Behind a verified backup and an explicit operator window. See [scanner-schema-drop-runbook.md](../app/public/wp-content/plugins/bcc-trust/docs/scanner-schema-drop-runbook.md). |

⚠ **`ScannerFreeze` no longer exists.** Earlier revisions of this file, of
[cron-registry.md](cron-registry.md) and of
[database-schema.md](database-schema.md) described the freeze as the live
mechanism and listed `ScannerFreeze::FROZEN_ENTRY_POINTS`. S8 deleted the class
and its constant. The entry points are not *refused* any more — they are
**absent**.

### Production is behind

Production is at bcc-trust `d84c2ac1` (S1–S6, deployed 2026-10-07). **S7, S8,
S9a and S9b are not in production.** So on production the implementation
classes still exist and are still held shut by the freeze; on staging they are
deleted. Read any statement here as "in `main`" unless it says otherwise.

## 3. What was deliberately kept

Removing chain-wide discovery was never meant to remove per-contract work. All
of the following are **live and unfrozen**:

- **Manual Add Collection** — an administrator submits ONE chain and ONE
  contract. It never enumerates. ⚠⚠ **ALL THREE FAMILIES ARE VALIDATED, and all
  three can call a provider.** `ContractValidator::validate()` dispatches a
  probe per family — `CosmosContractProbe`, `EvmContractProbe`,
  `SolanaContractProbe` — and `ManualCollectionIntakeService` persists nothing
  unless the verdict is persistable.
  - **Cosmos** — one bounded CW-721 `contract_info` probe (up to two LCD
    queries, falling back to `get_collection_info_and_extension` for
    SG721-shaped contracts); a contract answering neither is refused.
  - **EVM** — probed, but ⚠ only on a chain inside `NftLaunchChains`. A chain
    outside that scope is refused **without asking it anything**, so that
    refusal is about scope, not about evidence.
  - **Solana** — probed.

  ⚠ An earlier revision of this file said **EVM** and **Solana** "perform no
  provider validation and accept the row as entered". That was accurate when
  written for the #161 freeze documentation and was superseded by PR E, which
  built both validators; it was carried forward here without re-verification.
  Corrected 2026-10-09.
- **Targeted validation** — `CosmosContractProbe` still calls
  `CosmwasmClassifier::classify()`. The classification vocabulary and its
  error discrimination survive here, and only here.
- **Metadata retrieval** — the EVM and Solana fetchers are retained and
  unfrozen, with two live callers each (`NftPieceViewModelBuilder` for the
  piece endpoint, `NftEnrichmentService` for enrichment). The retired monthly
  CosmWasm metadata-refresh job is gone.
- **Ownership, holdings, holder-gated join and revocation** — untouched.
- **The EVM indexer tick and holder-group reconciliation** — untouched.
- **The two manual capability columns** — `bcc_supports_nft_collections` and
  `manual_collection_discovery_enabled`. ⚠ `manual_collection_discovery_enabled`
  gates **operator-initiated intake of one submitted contract**. It is not
  permission to start a chain-wide scan, it authorizes nothing chain-wide, and
  it cannot re-arm anything. The name is historical and misleading.
- **The Cosmos endpoint switch** — the only path by which a chain's `rest_url`
  moves. See §5.
- **The shared chain-checkpoint primitive** — `wp_bcc_chain_checkpoints` is
  still the "where is each chain-walking worker up to" table, read by the EVM
  indexer, the circuit breaker and the daily CU budget. Only its `cw_*`
  extension is retired.

### ⚠ Hide/Unhide changed meaning in S3

Hide/Unhide is now a **stance toggle only**. S3 deleted
`syncScannerDenyFlag()` and collapsed the audit vocabulary to two actions. It
no longer writes `wp_bcc_cosmwasm_contracts.denied`, and after S9b that table
does not exist. Anyone who remembers the deny-flag write will read its absence
as a regression. It is not one.

## 4. Where the retained capabilities are documented

| Capability | Document |
|---|---|
| Per-family contract validation, intake metadata | [pattern-registry.md](pattern-registry.md) |
| `metadata_state` / `metadata_checked_at` | [database-schema.md](database-schema.md) |
| Holdings, gates, revocation | [pattern-registry.md](pattern-registry.md), [GOLDEN_PATHS.md](GOLDEN_PATHS.md) |
| Cron state of the two retained hooks | [cron-registry.md](cron-registry.md) |
| Endpoint management | §5 below |
| The S9b schema drop and its recovery | [scanner-schema-drop-runbook.md](../app/public/wp-content/plugins/bcc-trust/docs/scanner-schema-drop-runbook.md) |
| Retained-feature verification | [s9b-retained-feature-verification.md](../app/public/wp-content/plugins/bcc-trust/docs/s9b-retained-feature-verification.md) |

## 5. Endpoint management — the gap this note closes

There was no standalone document for this, and the switch is now **the only
way a chain's `rest_url` moves**. Hand-editing the column is the alternative,
which is why the control exists.

**Components.** `CosmosEndpointPolicy` (the approved-host list, also unioned
into the wallet SSRF allowlist by `BlockchainQueryService`),
`CosmosEndpointVerifier` (live identity proof), `CosmosEndpointTransition`
(the switch itself), and `ChainRepository::updateRestUrl()` — the only write
path to the column.

**The flow.** capability → POST → nonce → chain resolves → governed →
`normalize(to)` → `approved(to)` → `to ≠ from` → acquire the advisory lock
`bcc_cosmos_endpoint_<chainId>` (**timeout 0 — refuse, never wait**) → live
uncached fail-closed verification → **compare-and-swap**
`UPDATE … WHERE id = ? AND rest_url = <expected_from>` → post-write read-back
→ cache clear → breaker `forget()` → audit row → release in a `finally`.

**Design notes worth keeping.**

- Approval is an **exact match**, never prefix or suffix, so
  `rest.cosmos.directory.evil.test` and `…/cosmoshub/../osmosis` both fail.
  Credentials and query strings are rejected by `normalize()`.
- Verification is deliberately **off `ApiRetry`**, so asking permission cannot
  open the circuit breaker. *A verification that cannot be completed is a
  verification that failed.*
- The **read-back** exists because `updateRestUrl()` reports only "no DB
  error", which is also true for zero rows matched.
- The breaker `forget()` is deliberately **not** `recordSuccess()` — that would
  fake a success timestamp the stale-chain detector reads.
- The audit records **hosts and counts only**: no cursor value, no contract
  address, no provider sentence.
- Nine refusals, every one endpoint-level: `invalid_chain`, `missing_input`,
  `not_governed`, `target_malformed`, `target_not_approved`, `already_current`,
  `lock_contended`, `target_unreachable` / `target_identity_unreadable` /
  `target_network_mismatch`, `from_mismatch`, `write_failed`,
  `post_write_mismatch`.

⚠ **The operator surface is incomplete.** There is no form, no plan panel and
no result notice. `plan()`'s only caller is a test, and the PRG argument
`bcc_endpoint` is read by nothing, so **every refusal is currently invisible**
— an operator gets a redirect and no explanation, and a switch requires a
hand-built POST. This is a known gap, recorded here rather than left implicit.

## 6. What the freeze was, for the record

`docs/` contained no occurrence of "frozen", "freeze" or `ScannerFreeze` until
#161. The freeze is written down here, in the past tense, because S8 removed
the flag itself:

> Between 2026-09-18 and S8, `ScannerFreeze::frozen()` was a hard-coded `true`
> — not a filter, an option, an environment switch or an operator setting —
> and all 13 entry points in `ScannerFreeze::FROZEN_ENTRY_POINTS` were refused.
> It was retirement scaffolding that held the surface shut while the deletion
> programme ran, and it was deleted once the surface it guarded was gone.

`DiscoveryRunExecutor` is the one piece of that scaffolding that survives, and
it survives on purpose: see [cron-registry.md](cron-registry.md).

## Related

- [cron-registry.md](cron-registry.md) — the two retained discovery hooks
- [database-schema.md](database-schema.md) — the three retired tables and the
  two-deploy sequence
- [pattern-registry.md](pattern-registry.md) — the retained validation and
  metadata paths
- [scanner-schema-drop-runbook.md](../app/public/wp-content/plugins/bcc-trust/docs/scanner-schema-drop-runbook.md)
- [s9b-deferred-cleanup-review.md](../app/public/wp-content/plugins/bcc-trust/docs/s9b-deferred-cleanup-review.md)
