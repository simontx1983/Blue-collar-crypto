# Contract discrepancies — `GET /users/{handle}` errors, and `GET /me/watching/summary` fields

**Status: handoff. No backend change, no contract change, no frontend
workaround.** Two places where `docs/api-contract-v1.md` and the deployed
PHP disagree. Each needs a decision about which side is wrong; neither is
guessed at here.

Found during a frontend audit (bcc-frontend #182–#186). Verified against
`bcc-trust` as checked in at the time of writing.

---

## 1. `GET /bcc/v1/users/{handle}` — the contract documents a 403 the handler cannot emit

**Contract**, `docs/api-contract-v1.md:2333`:

> **Errors:** `bcc_not_found` (handle missing), `bcc_permission_denied` (account suspended; rare)

**Code.** The route is registered with no permission gate —
`UsersEndpoint.php:132`:

```php
'methods'             => WP_REST_Server::READABLE,
'callback'            => [new self(), 'getUser'],
'permission_callback' => '__return_true',
```

and `UsersEndpoint::getUser` (`:383`) returns exactly two error shapes,
both 404:

```php
return ApiResponse::error('bcc_not_found', 'Handle not found.', 404);   // :389
return ApiResponse::error('bcc_not_found', 'User not found.', 404);     // :401
```

There is no suspension check anywhere in the file — the only matches for
`suspend` are prose in comments at `:341` and `:708`.

### Why it matters to the frontend

`/u/[handle]` now classifies a failed profile read three ways — 404 to
`notFound()`, 403 to a terminal "not available to you" state with no
retry, anything else to a retryable boundary (bcc-frontend #182). The 403
branch was written because the contract documents that state.

**If the contract is right**, the handler is missing a gate and a
suspended account's profile is currently readable by anyone. That is the
more serious reading and worth checking first.

**If the handler is right**, line 2333 should drop
`bcc_permission_denied`, and the frontend branch becomes defensive code
for a state that cannot occur. It is harmless there and will be left in
place either way — a 403 arriving from a future gate is handled correctly
rather than falling through to a generic failure.

### What is NOT being asked for

No suspension policy is being implemented in the client. Whether a
suspended operator's profile is public, redacted, or 403 is a product and
backend decision; the frontend only needs to know which status it will
receive.

### Questions

1. Is a suspended account's profile meant to be unreadable through this
   endpoint?
2. If yes — which code and status, and does it apply to anonymous readers,
   authenticated readers, or both?
3. If no — may line 2333 be corrected to list `bcc_not_found` only?

---

## 2. `GET /bcc/v1/me/watching/summary` — the contract's example response does not match the service

**Contract**, `docs/api-contract-v1.md:2664-2681`:

```json
{
  "handle": "simontx",
  "watching_size": 39,
  "tier_distribution": { "legendary": { "count": 2, "percent": 5 }, … },
  "monthly_activity": {
    "reviews_written":  4,
    "solids_received":  12,
    "disputes_signed":  1
  }
}
```

**Code**, `WatchingSummaryService::compose` (`:83-93`), whose own
`@return` docblock (`:76-80`) agrees with its body:

```php
return [
    'total'             => $total,
    'tier_distribution' => $tierDistribution,
    'monthly_activity'  => $this->monthlyActivity($userId),
];
```

and `monthlyActivity` (`:144-148`):

```php
return [
    'reviews'         => $this->voteRepo->countByVoterSince($userId, $sinceMysql),
    'solids_received' => $solidsReceived,
    'disputes_signed' => DisputeRepository::countByReporterSince($userId, $sinceMysql),
];
```

Three differences, not one:

| Contract example | Service returns | Frontend type expects |
|---|---|---|
| `handle` | *absent* | *absent* |
| `watching_size` | `total` | `total` |
| `monthly_activity.reviews_written` | `monthly_activity.reviews` | `monthly_activity.reviews` |

`tier_distribution` matches exactly, including the five slot names and the
`{count, percent}` shape.

### Which side appears to be right

The **service**, on two independent grounds:

- `WatchingSummaryResponse` in `bcc-frontend/src/lib/api/types.ts:3629`
  declares `total` and `monthly_activity.reviews`, and `WatchingHeader`
  renders against those. The watchlist header works in production, which
  it could not if the wire carried `watching_size` and `reviews_written`.
- `reviews_written` is a real field name elsewhere in the contract —
  `counts.reviews_written` (`:2363`, `:6506`) and
  `member_dossier.engagement.reviews_written` (`:2414`) — so this looks
  like the name being borrowed from a neighbouring block when the example
  was written, rather than a renamed field.

So the likely correction is to the **document**, not the code. It is
raised rather than edited because the frontend is not the owner of this
contract section, and because a hand-written example is exactly the kind
of thing that is easy to "fix" in the wrong direction.

### Why it is worth fixing even though nothing is broken

Nothing is broken today — this cost real time during the audit. A
test fixture was built from the contract example, the watchlist header
crashed on it (`Cannot read properties of undefined (reading 'percent')`
at `WatchingHeader.tsx:93`, because the fixture also carried reputation
tiers rather than the rarity slots), and ruling out a product bug meant
reading the service. An incorrect example is a trap for the next person
writing a fixture, a mock, or a second client.

### Questions

1. Confirm the service is authoritative and the example should be
   corrected to `total` / `monthly_activity.reviews`, with `handle`
   removed.
2. Is `handle` meant to be there? It is the only one of the three that
   could plausibly be a missing *feature* rather than a typo.

---

## Not in scope here

- **`isNonRetryableFixedReadFailure` was missing two 403 codes.** The
  contract's §1.4.2 non-retryable client-error class
  (`docs/api-contract-v1.md:137`) lists `bcc_invalid_request`,
  `bcc_permission_denied`, `bcc_blocked` and `bcc_forbidden`, but the
  frontend predicate listed only `bcc_forbidden` — so a
  `bcc_permission_denied` or `bcc_blocked` read offered a Retry that could
  never succeed. Fixed frontend-side in bcc-frontend #183; no backend
  question attached. Noted here only because it came from reading the same
  §1.4 table.
- **`GET /bcc/v1/disputes/mine` pagination.** The endpoint is
  offset-paginated with `X-WP-Total` / `X-WP-TotalPages`
  (`docs/api-contract-v1.md:6269`), and the frontend deliberately reads
  only the server default first page — `getMyDisputes` says so in its own
  docblock. That is a frontend product decision, not a contract
  discrepancy, and is handled separately.
