# BCC Domain Seams

## Why this doc exists

When working in any `bcc-trust` Domain (`Core` / `Disputes` / `Onchain`),
the question **"which Domain owns this behavior?"** should have a fast,
scannable answer. As [`bcc-trust/app/Domain/Core/Plugin.php`](../app/public/wp-content/plugins/bcc-trust/app/Domain/Core/Plugin.php)
has grown to ~2,485 lines with ~122 service-getter methods (each returning
a service from one of the three Domains), the answer is increasingly
buried in implementation details — and "which constructor does this
service depend on transitively?" has no one-page answer at all.

This doc is that page. It records:

1. Per-Domain ownership (data, services, REST surfaces).
2. The 11 **canonical interface-mediated seams** in `bcc-core/src/Contracts/*` — the recommended cross-Domain access path.
3. The known **intra-plugin direct-call shortcuts** that bypass the Contracts pattern.
4. The maintenance rule for new cross-Domain calls.

When the audit's "alarm bell" question becomes hard to answer
(*"which Domain actually owns this behavior?"*), this doc is the first
place to look. Append to it whenever you add a cross-Domain call so the
visibility deficit doesn't compound.

> **Constitutional reference**: Platform Constitution §VII.25
> (ServiceLocator allowlist) + §III.9 (`bcc-core/src/Contracts/*`
> interfaces are the only legal cross-plugin read surface). The
> intra-plugin extension recorded here uses the same machinery.

## How to read this doc

- §1 — what each Domain owns.
- §2 — the 11 canonical interface seams.
- §3 — the known intra-plugin direct-call shortcuts (append-only).
- §4 — the maintenance rule for new cross-Domain work.
- §5 — when the rule doesn't apply (legitimately scoped exceptions).

---

## §1. Domain ownership

### Domain/Core (trust + social + content)

| What | Where |
|---|---|
| Trust storage | `bcc_trust_*` tables (votes, endorsements, scores, user_info, edges, fraud_analysis, suspensions, patterns, reputation, page_read_model, ...) |
| Trust pipelines | Vote (5-stage), Endorsement (vesting), Read-model sync |
| Content & social | Comments, photos, GIFs, mentions, reactions (PeepSo sidecar tables for BCC-owned semantics) |
| Group + watching logic | `GroupContextResolver`, `GroupActivityHeatService`, `WatchingService`, `WatchingRepository`, `WatchBatchAggregator` (renamed 2026-05-13 from `BinderService` / `BinderRepository` / `PullBatchAggregator` — see `api-contract-v1.md §4.5.1`) |
| Read model | `bcc_page_read_model` (canonical denormalisation per §III.11) |
| Notifications | `NotificationDispatcher` + `PushDispatcher` + `DigestService` |
| Admin moderation surface | `ModerationService`, `ContentReportService`, `RepairService`, `AutoHideService`, `AdminDashboardRepository` |
| User lifecycle | `UserSyncService`, `UserLifecycleService`, `HandleService`, `QuestProgressService` |
| Cron service registry | `CronService` (the canonical registry per §VIII.29) |
| Trust-score formula | `TrustScoreService` (single canonical entry point per §A4) |
| Trust graph + ring detection | `TrustGraph` (PageRank + vote-ring + endorsement-ring detector) |

REST surfaces: most `*Endpoint.php` files under `Domain/Core/REST/` register `/bcc/v1/*` routes; some `*Controller.php` files register `/bcc-trust/v1/*`. Pattern-registry "REST namespace file-pattern rule" documents the split.

### Domain/Onchain (wallets + NFT + validators + holder groups)

| What | Where |
|---|---|
| Wallet linkage | `bcc_wallet_links` (`WalletRepository`, `WalletLinkReadService`, `WalletLinkWriteService`) |
| Onchain signals | `bcc_onchain_signals` (`SignalRepository`, `SignalRefreshService`, `SignalFetcher` factory) |
| Per-chain fetchers | 6 implementations of `FetcherInterface` (Solana / EVM / Cosmos / Polkadot / NEAR / Thorchain) under `Domain/Onchain/Fetchers/` |
| NFT holdings (V2 Phase 1) | `bcc_nft_holdings` (`NftHoldingsRepository`, `NftHoldingsIndexer`, `NftEnrichmentService`, `NftSpamFilter`, `NftEthIndexerWorker`) |
| Helius webhook + dedup | `HeliusWebhookEndpoint`, `bcc_helius_seen_signatures` (`HeliusSeenSignaturesRepository`) |
| NFT-gated holder groups | `GatedGroupRepository`, `NftGroupGateService`, `GatedGroupProvisioningService` (cron-driven via `bcc_gated_group_provision` daily + `bcc_gated_group_reconcile_sweep` twicedaily) |
| Validators | `ValidatorRepository`, `DelegationRepository` |
| Entity claims | `ClaimRepository`, `ClaimService` (page claims via signed wallet) |
| Chain config | `ChainRepository`, `ChainCheckpointRepository`, `ChainRefreshService` |
| Per-chain circuit breaker | `Domain/Onchain/Support/CircuitBreaker` (NOT the Core one — different storage + hardening; see pattern-registry "Same-name-different-class index") |

REST surfaces: wallet auth + onchain endpoints under `Domain/Onchain/Controllers/` (mixed namespace per the file-pattern rule).

### Domain/Disputes (panel adjudication only)

| What | Where |
|---|---|
| Dispute storage | `bcc_disputes_*` (`DisputeRepository`, `DisputeParticipationRepository`) |
| Pipeline | `DisputeResolver` → `DisputeAdjudicator` → `DisputeScheduler` (auto-resolve daily + reconcile sweep every 5 min) |
| Auth callback to Core | Implements `DisputeAdjudicationInterface` (the cross-plugin score-reversal callback) |

Disputes is the smallest Domain. It owns its tables and adjudication logic; everything else (user info, vote rows, score recalculation triggers) goes through `Core` via interfaces.

---

## §2. Canonical interface-mediated seams

The **11 interfaces** in [`bcc-core/src/Contracts/`](../app/public/wp-content/plugins/bcc-core/src/Contracts/)
are the canonical cross-Domain (and cross-plugin) seam. Implementations
live in the owning Domain; consumers resolve via `ServiceLocator`.

Every interface has a **Null fallback** in
[`bcc-core/src/NullServices/`](../app/public/wp-content/plugins/bcc-core/src/NullServices/)
that activates when the implementing plugin isn't loaded — fail-open
or fail-closed per the security posture of the contract.

| # | Interface | Owner Domain | Implementation | Null fallback behavior |
|---|---|---|---|---|
| 1 | `TrustReadServiceInterface` | Core | `Domain/Core/Application/TrustReadService` | **Fail-closed** (`isSuspended` returns true; `lockActiveVoteForDispute` returns false) |
| 2 | `ScoreReadServiceInterface` | Core | `Domain/Core/Application/ScoreReadService` | Empty reads |
| 3 | `ScoreContributorInterface` | Core | `Domain/Core/Application/ScoreContributorService` | Silent no-op (bonus deltas dropped) |
| 4 | `TrendingDataInterface` | Core | `Domain/Core/Application/TrendingDataService` | Empty trending list |
| 5 | `RecalcQueueReadInterface` | Core | `Domain/Core/Application/RecalcQueueReadService` | Returns `null` ("unknown queue depth") |
| 6 | `PageOwnerResolverInterface` | Core | `Domain/Core/Services/PageOwnerResolver` | Rejects non-`peepso-page` types |
| 7 | `DisputeAdjudicationInterface` | Core | `Domain/Core/Application/Disputes/DisputeAdjudicator` | accept/reject return false |
| 8 | `WalletLinkReadInterface` | Onchain | `Domain/Onchain/Services/WalletLinkReadService` | `hasLink` returns false; empty link list |
| 9 | `WalletLinkWriteInterface` | Onchain | `Domain/Onchain/Services/WalletLinkWriteService` | Silent no-op |
| 10 | `WalletSignalWriteInterface` | Onchain | `Domain/Onchain/Services/WalletSignalWriteService` | Silent no-op |
| 11 | `OnchainDataReadInterface` | Onchain | `Domain/Onchain/Services/OnchainDataReadService` | Empty validator + collection reads |

Disputes implements **none** of these — it consumes 1 (`TrustReadServiceInterface` for vote-locking) and 1 (`DisputeAdjudicationInterface`, exposed back to Core for the adjudication callback). Same goes for `bcc-search` — pure consumer.

**Verified consumers of each interface**:
- `TrustReadServiceInterface` ← `Domain/Disputes/Controllers/DisputeController`
- `DisputeAdjudicationInterface` ← `Domain/Disputes/Services/DisputeScheduler`, `DisputeResolver`
- (Other consumers route through `ServiceLocator::resolveX()` and aren't enumerated here — check `ServiceLocator.php` for the registry.)

---

## §3. Intra-plugin direct-call shortcuts

These are runtime cross-Domain couplings that **don't** go through
`bcc-core/src/Contracts/*`. Three shapes count as shortcuts:

1. `Plugin::instance()->someService()` direct getter call into another Domain.
2. `new \BCC\Trust\OtherDomain\X(...)` direct instantiation.
3. **Static method calls on another Domain's Repositories or Services** (e.g., `WalletRepository::exists(...)`).

Each is an honest engineering choice today — the contract pattern is
heavyweight and not every cross-Domain call justifies it.

But each one also:

- Compounds `Plugin.php`'s God-object surface (one more service-getter the boot wiring has to know about).
- Creates a coupling that's invisible until a constructor change or static-method signature change cascades — the audit's specific concern.
- Should be **promoted to an interface when the access pattern grows** beyond a single caller.

> **Type-only imports** (a `use` statement consumed only for a
> return-type hint, parameter type, or PHPDoc reference) do NOT count
> as shortcuts and don't need a §3 entry. See §5.

### Known shortcuts (append-only as new ones land)

| Caller (Domain) | Calling | Callee (Domain) | Why direct, no contract? | Promote when? |
|---|---|---|---|---|
| Onchain — `HolderGroupsEndpoint` ([`:150`,`:166`,`:227`,`:275`,`:298`,`:323`](../app/public/wp-content/plugins/bcc-trust/app/Domain/Onchain/REST/HolderGroupsEndpoint.php#L150)) | `groupActivityHeatService()`, `nftGroupGateService()` | Core (heat) + Onchain (gate) | Heat lookup is presentation-tier (badge `cold/warm/hot`); gate service is owned by Onchain. Bundling the call site through `Plugin::instance()` keeps the endpoint thin. | A second consumer of `groupActivityHeatService` outside Onchain (e.g., a future communities admin tab) — promote to `GroupHeatReadInterface`. |
| Disputes — `DisputeParticipationService` ([`:112`](../app/public/wp-content/plugins/bcc-trust/app/Domain/Disputes/Services/DisputeParticipationService.php#L112)) | `userInfoRepository()` | Core | Read-only access to one column on an existing per-user row, on the dispute participation hot path. A full `UserInfoReadInterface` would need a Null fallback that materialises a sane "user info unavailable" shape — overkill for one column. | Disputes adds a write to user_info, OR a second non-Core caller appears — promote to `UserInfoReadInterface`. |
| **Domain/Core (~10 services + REST endpoints) → Onchain Repositories/Services via static method calls** | `WalletRepository::*`, `ChainRepository::*`, `ClaimRepository::*`, `ValidatorRepository::*`, `CollectionRepository::*`, `GatedGroupRepository::*`, `CollectionService::*`, `ClaimService::*`, `HoldingsService::*` | Onchain | The Onchain repositories are stateless static utilities (no DI registration). Domain/Core needs to read wallet identity, chain config, NFT claims, validator membership, gated-group config, and holdings on hot paths (auth flow, card view, feed ranking, group discovery, creator gallery). Each static call is small; collectively they're a dense coupling. | **The largest cross-Domain seam in the platform.** Promotion is Phase D shape: introduce 4–6 read-only interfaces in `bcc-core/src/Contracts/*` (`WalletReadInterface`, `ChainReadInterface`, `ClaimReadInterface`, `ValidatorReadInterface`, `CollectionReadInterface`, `GatedGroupConfigReadInterface`) backed by Null fallbacks, and migrate the static call sites incrementally. Trigger: when a static method signature change in Onchain breaks a Domain/Core caller silently (the audit's specific failure mode), OR when an external plugin needs to consume any of these reads. See §3 audit notes below. |

### §3 audit notes (Phase 2.5 — 2026-05-09)

A read-only audit of every `BCC\Trust\Onchain\*` import / FQCN reference inside `Domain/Core/*` produced this picture:

- **24 `use BCC\Trust\Onchain\*` statements across 13 Domain/Core files** plus 8 additional files with fully-qualified `\BCC\Trust\Onchain\*` references. After de-duplication, ~15 files in Domain/Core are coupled to Onchain.
- **Zero `new \BCC\Trust\Onchain\X(...)` instantiations** in Domain/Core. All cross-Domain access is either type-only or static method.
- **Zero `Plugin::instance()->onchainXService()` getter calls** from Domain/Core for the imported repositories/services. Coupling is direct, not via the wiring root.
- The dominant pattern (~30+ call sites) is **static method calls on Onchain Repository / Service classes** — `WalletRepository::exists`, `ChainRepository::getById`, `ClaimRepository::getForEntity`, `ValidatorRepository::findFirstByPageId`, `CollectionService::getForProject`, etc.
- **Exception-legitimate exemptions confirmed**:
  - 1 ValueObject import (`UserGroupsEndpoint` line 39 — `BCC\Trust\Onchain\ValueObjects\GatedGroupConfig`). Used as a value-shape type only.
  - 2 Plugin.php Onchain Service imports (`GatedGroupProvisioningService`, `NftGroupGateService`) — wiring-root concerns; Plugin.php's job IS to know about all Domains.
  - Plugin.php's own internal static calls on Onchain repos during boot wiring (e.g., `WalletRepository::getForProject` inside the boot-time chain-refresh flow). Same exception bucket: wiring-root coupling.
- The previous speculative "Core `UserViewService` → `WalletRepository` direct import — flag for review" entry was a worked example of the broader cluster, not a one-off Phase B leftover. Resolved into the collective row above.
- The previous "WalletSignalRepository in Core/ implements `WalletSignalWriteInterface`" suspicion turned out to be a documented dual-write fan-in: Core's repository writes signals into bcc_onchain_signals through the same contract Onchain's `WalletSignalWriteService` implements. Not a drift; the fan-in is intentional and bounded. No §3 entry needed.

The audit confirmed the cross-Domain coupling exists but bounded — it's exclusively read-side static utility access, not stateful service composition. Phase D work to consolidate these into 4–6 read-only interfaces is well-scoped but not urgent: trigger conditions are (a) a signature-change breakage incident, (b) cross-plugin demand for any of these reads, or (c) post-MVP cleanup window. Until then, this row is the registry entry.

---

## §4. Maintenance rule

When you add a cross-Domain call:

1. **Check existing contracts first.** Is there already an interface in [`bcc-core/src/Contracts/*`](../app/public/wp-content/plugins/bcc-core/src/Contracts/) that fits?
   - **Yes** → resolve via `ServiceLocator::resolveX()`. Done.
   - **No** → continue.

2. **Decide deliberately:**

   - **One-off call** (you can describe a specific reason this caller needs this callee, and you don't expect more callers): direct call is OK. **Add a row to §3 above** with the reason and the promotion trigger. Don't skip the row — that's how the registry stays useful.

   - **Multiple callers expected**, or the data crosses a plugin boundary, or the access is on a hot path that needs a Null fallback: **write a new interface** in `bcc-core/src/Contracts/`, implement it in the owning Domain, register a `NullThing` fallback, wire it via `ServiceLocator`. The Null fallback is a hard requirement — every interface in `Contracts/` has one (see §2 table).

3. **Never add a getter on `Plugin.php` that returns another Domain's service** without first asking: should this be a contract? If it should and you're skipping it for time pressure, **at minimum add the row to §3** so the technical debt is recorded and the promotion trigger is named.

4. **PR-review checkbox** (informal but recommended):
   - [ ] Cross-Domain call follows the rule above.
   - [ ] If shortcut: row added to §3 with promotion trigger.

## §5. Legitimate exceptions

Not every cross-Domain reference is a seam concern:

- **Type-only imports** (e.g., `use BCC\Trust\Onchain\ValueObjects\HoldingsSnapshot` for a return-type hint) don't create service coupling. They're shape contracts, not behavioral ones, and don't compound Plugin.php.
- **Domain-owned admin pages calling their own Domain's services** are fine even when routed via `Plugin::instance()` — the admin page lives in the Domain. Example: `Domain/Onchain/Admin/VerifyCollectionsPage::handlePost` calling `Plugin::instance()->gatedGroupProvisioningService()` (an Onchain service, called from an Onchain admin page) is intra-Domain, not cross-Domain.
- **Test code under `tests/` / `tests-disputes/`** that imports across Domains for fixture wiring is exempt. Tests routinely need to know more than production code.

---

## Phase D end-state (when opened)

Per the Stabilization Cleanup Plan §7 Phase D and the audit's
"modular monolith discipline" framing, the post-MVP target is:

- Split `Plugin.php` into per-Domain `DomainPlugin` classes (`Core\DomainPlugin`, `Onchain\DomainPlugin`, `Disputes\DomainPlugin`), each owning its own service-getter set.
- The top-level `Plugin` becomes pure boot wiring: instantiate the three `DomainPlugin`s, register their REST routes + cron events + ServiceLocator bindings, and exit.
- Every cross-Domain getter currently in `Plugin.php` either becomes (a) an interface in `bcc-core/src/Contracts/*`, or (b) an explicit `DomainPlugin::resolve()` call inside the calling Domain.

Until Phase D opens, **growing this doc is the cheapest mitigation**.
Each new shortcut documented here is a debt entry the future Phase D
work will resolve. Each new interface in `Contracts/` reduces the
number of Phase D items by one.

---

This file will be updated whenever new cross-Domain calls are added.
Doc-only — no automation reads it. Pattern-registry's
[Observability section](pattern-registry.md) is the corresponding
mitigation for fault line #3 (silent degradation); this doc is the
mitigation for fault line #2 (Plugin.php God-object accumulation).
