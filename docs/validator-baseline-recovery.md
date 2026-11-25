# Validator index baseline recovery

How to recognise a chain whose validator index is **pinned partial**, how to
tell that apart from a fetch that is really truncating, and how to clear a
stale baseline safely. Cited by `ChainRefreshService` in bcc-trust.

## What the baseline is

The WordPress option `bcc_onchain_indexer_stats` (not autoloaded) holds one
entry per chain slug. Two keys in an entry form the **baseline**:

| key | meaning |
|---|---|
| `last_good_total` | validators returned by the last **clean** index run |
| `last_good_at` | when that run happened (UTC, `Y-m-d H:i:s`) |

Every index run (`bcc_index_validators`, every 4 hours) compares the number it
fetched against `last_good_total` (`ChainRefreshService::isPartialFetch()`):

- baseline above 10: fewer than 70% of it is **partial**;
- baseline of 10 or less: fewer than 50% of it is **partial**.

A partial run still writes the rows it fetched, but it **never advances the
baseline**. That is deliberate (truncation must not teach the index a smaller
number), and it is also how a chain gets stuck: if the chain's real set
shrinks below 70% of an old baseline, every later run reads partial and the
baseline never moves again.

## What a pinned-partial chain looks like

- The same warning on every run, with the **same baseline number** each time:
  `[Onchain] Partial fetch detected for <Chain>: got N against baseline B (stored S). Dead-validator marking is skipped for the WHOLE RUN.`
- `[Onchain] Indexed <Chain>: … (PARTIAL)` on every run.
- In the stats entry: `partial: true` on every run while `last_good_at` stays
  old.

What it costs while it lasts:

- no placeholder pages are minted for that chain;
- `markDeadValidators` is withheld for **every** chain (the gate is run-wide);
- that chain never gets a run-level breaker success.

## Pinned or truncated? Decide before you clear

Clearing is only right when the baseline is stale: the chain's real set
shrank, or the provider endpoint changed what it counts. It is wrong when the
fetch is truncating, because the next clean run would then record the
truncated number as the new baseline.

1. Find the provider's own size of the set: the API's `count`, or the chain's
   explorer.
2. Compare it with `N` from the warning:
   - `N` matches the provider's count: the baseline is stale. Clear it.
   - `N` is below the provider's count: the fetch is truncating. Fix the
     fetch and do **not** clear.

## How to clear (read, then write, with staff OK)

This is a production data write. Get staff OK first, and run it through the
ops session, between index runs (a run in flight rewrites the option).

1. **Read and record** the entry before changing it, and keep the output in
   the ops ledger:

   ```sh
   wp option pluck bcc_onchain_indexer_stats <slug> --format=json
   ```

2. **Remove only the two baseline keys** from that one entry. Leave every
   other key and every other chain alone:

   ```sh
   wp option patch delete bcc_onchain_indexer_stats <slug> last_good_total
   wp option patch delete bcc_onchain_indexer_stats <slug> last_good_at
   ```

3. **Read again** and confirm that only those two keys are gone.

4. **After the next clean run**, check that the entry has `partial: false`, a
   `last_good_total` close to the provider's count, and a fresh
   `last_good_at`.

With no baseline, the relative test is skipped for one run and the absolute
floors decide alone. The next clean run writes the new baseline.
