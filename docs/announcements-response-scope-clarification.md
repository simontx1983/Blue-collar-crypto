# Announcements — which responses may carry viewer-dependent fields

**Status: clarification request. No code change is requested by this document.**
Nothing in bcc-trust, bcc-core or bcc-frontend changes as a result of reading it.
It asks §4.32 to settle one question so the frontend can stop guessing.

**Audience:** whoever implements A2 / A5 in bcc-trust.
**Raised from:** bcc-frontend #167 / #168 (both OPEN, unmerged).
**Contract:** [api-contract-v1.md](api-contract-v1.md) §4.32, v1.78.

---

## 1. The ask, in two rules

Please confirm (or correct) these, and state them in §4.32:

**Rule A — public responses carry public data only.**

`GET /bcc/v1/validators/:pageId/announcements` (both `?state=active` and
`?state=archived`) and `GET /bcc/v1/validators/:pageId/announcements/rotator`
return **no viewer-dependent fields whatsoever**:

- no `capabilities` block on any row;
- no `status` field (and therefore never a `draft`, `scheduled` or `blocked`
  row — §4.32.1's three predicates already exclude those from public reads);
- no publication-authorization metadata, no removal metadata, no other
  owner-private state.

Two viewers requesting the same page get **byte-identical** bodies.

**Rule B — viewer-dependent fields live only on viewer-scoped responses.**

These MAY carry a `capabilities` block and owner-only state:

- `GET /bcc/v1/me/validators/:pageId/announcements` — the owner list;
- `GET /bcc/v1/validators/:pageId/announcements/:id` — detail;
- `GET /bcc/v1/announcements/:id/comments` — which already carries
  `can_comment` per §4.32.11.

---

## 2. Why this is being asked — the contract currently says both

§4.32.11 (a) scopes capabilities to a named list of responses:

> Supplied **only** by announcement-scoped responses: the detail view-model,
> the comments page, and rows in an owner-scoped list. Never by the Card.

The public list is not in that list, which reads as Rule A.

But §4.32.11 (c) then says of `Announcement.capabilities`:

> `Announcement.capabilities` is **optional**: attached for a viewer who may
> manage, absent for public readers.

"Attached for a viewer who may manage" reads as the public list carrying
capabilities **for an operator** — i.e. the same URL producing different
bodies for different viewers, which is Rule A's opposite.

Both sentences are in the merged contract. Only one can be implemented.

⚠ This is not a wording nit. The two readings imply different cache
correctness requirements on the client, and the client has to pick one
*before* the endpoint exists.

---

## 3. What the frontend does today, and why

It assumes **the stricter of the two** — it treats the public list as
potentially viewer-dependent — because that assumption cannot be wrong for
privacy. Concretely, in `bcc-frontend/src/hooks/useAnnouncements.ts`:

| Read | Cache key | Gated on a resolved identity? |
|---|---|---|
| public list | `["announcements", pageId, "list", state, scope]` | **yes** |
| detail | `["announcement", id, scope]` | yes |
| comments | `["announcement-comments", id, scope]` | yes |
| **rotator** | `["announcements", pageId, "rotator"]` — **no scope** | **no** |

`scope` is the viewer's id, `"anon"` for a resolved signed-out visitor, or
`null` when identity is **unavailable**. A `null` scope neither reads nor
writes: it does not fall back to the anonymous namespace and it issues no
request. Invalidation stays scope-blind, so a mutation marks every viewer's
view stale.

The rotator is the one announcement cache left shared, on the strength of
§4.32.11 (a) — see §5.

---

## 4. The frontend dependency this changes

**If Rule A is confirmed**, one thing in the frontend becomes
over-conservative and should be relaxed:

- `announcementsListQueryKey(pageId, state, scope)` can drop its `scope`
  dimension, and `useAnnouncements`' `scope !== null` gate can go.
- Effect: the public list returns to a **shared** cache across viewers, and
  an anonymous reader no longer waits for session resolution before the
  list can load. Today they do — a small, deliberate latency cost paid for
  an ambiguity.
- That is a **frontend-only** follow-up, bounded to those two lines plus
  their tests. It is not required for correctness; it recovers caching.

**If Rule B is instead extended to the public list** (that is, the public
list may carry capabilities for an operator), then the current frontend
behaviour is already correct and **no frontend change is needed** — but
please say so explicitly, because the client would then be relying on a
property the contract only implies.

**Either way, nothing in #167/#168 is blocked by this.** The feature is
gated off entirely while `Card.announcements` is absent, which is every
backend today.

---

## 5. The rotator specifically

The frontend shares the rotator cache across all viewers. That is sound
**only** under Rule A. If A2 intends to attach a `capabilities` block — or
any viewer-dependent field — to rotator rows, that moves the rotator into
the scoped set and **must be stated**, because the client will otherwise
serve one viewer's rotator response to another.

A client-side mitigation already exists and is worth knowing about, though
it is **not** a substitute for not sending the data:
`AnnouncementBar` reads exactly `id`, `title`, `summary`, `published_at` and
`links.self` from a row. It reads no `capabilities` and no `status`, so an
over-returning server could not get those *rendered*. They would still be
in the browser's cache and in the response body, which is the part that
matters here.

---

## 6. Decision checklist

- [ ] Rule A confirmed, or corrected — public list and rotator carry public
      data only.
- [ ] Rule B confirmed — owner list, detail and comments may carry
      capabilities and owner state.
- [ ] §4.32.11 (c)'s "attached for a viewer who may manage" reworded so it
      cannot be read as applying to the public list.
- [ ] Explicit statement on whether the **rotator** may ever carry
      viewer-dependent fields.
- [ ] If Rule A: the frontend list-scoping relaxation above is filed as a
      follow-up.

---

## 7. Out of scope here

Deliberately not asked and not assumed:

- the **feed-card copy** for an announcement (no label is specified in
  §4.32 and none has been invented client-side; it currently falls back to
  the kind name upper-cased);
- **reportability** of an announcement, which §4.32.15 leaves an open
  proposal;
- the `FeedItem.comment_count` omission, which §4.32.15 already settles and
  the frontend already implements (absent means unknown; the Comment
  control renders with no figure rather than a fabricated `0`).
