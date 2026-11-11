# Validator announcements — the CRUD transition

**Status: DESIGN ONLY. Nothing here is implemented, and two things here are
deliberately left undesigned.**

This document exists because A2.4 shipped the read half of §4.32 and the write
half now has a shape that can be reasoned about: four routes are registered,
the visibility predicate is one producer, the capability resolver has four
producers and no public one, and the feature switch defaults off. What follows
is what the writes would have to be **given those decisions** — not a proposal
to build them.

It is written against `api-contract-v1.md` §4.32 as accepted. Where the
contract is silent, this document says so and stops, rather than filling the
gap. Two of those silences are load-bearing and are listed in §6.

Audience: whoever implements A3 (the writer) and A5 (comments). Read §1 and §5
first — §5 is the list of things that would be easy to get wrong and expensive
to discover.

---

## 1. What already exists, and what it constrains

| Piece | Where | What it fixes for the writes |
|---|---|---|
| Three visibility predicates | `AnnouncementVisibility::publicPredicateSql()` | A row is public only if `post_status='publish'` **and** `post_date_gmt <= now` **and** a valid publication-authorization marker is present. The writes must satisfy all three deliberately; satisfying two is invisible. |
| The publication-authorization marker | `_bcc_announcement_publish_authorized_at` + `_bcc_announcement_publish_authorized_user_id` | **Read by A2. Written by nobody.** This document does not invent who writes it or when — see §6.1. |
| Operator authority | `ValidatorPageOperatorAuthority::decide()` | The only accepted answer to "may this person act". Writes take the decision array; they do not re-resolve. |
| Capability producers | `AnnouncementCapabilityResolver` | Four producers, none of them public. A write response that needs capabilities uses an existing producer or adds one — it does not assemble a shape inline. |
| Read states | §4.32.13 | `draft` / `scheduled` / `published` / `blocked`. The read mapping never emits `blocked`; see §6.2. |
| Archival | §4.32.6, `_bcc_announcement_archived_at` | One-way in v1, keeps the permalink, is not a deletion. |
| The feature switch | `bcc_announcements_enabled`, default off | Every write route must consult it **first**, before authority and before any read. |

---

## 2. The write verbs, and the one mapping that is not obvious

§4.32.13 defines the write vocabulary as **verbs** (`draft`, `publish`,
`schedule`) and the read vocabulary as **states**. The verb → stored-state
mapping is:

| Verb | WordPress `post_status` | `post_date_gmt` | Marker |
|---|---|---|---|
| `draft` | `draft` | server time | **not written** |
| `publish` | `publish` | server time (§4.32.5: authoritative server time, client timestamps ignored) | written |
| `schedule` | **`future`** | the requested strictly-future instant | written at schedule time, or at promotion — **undecided, see §6.1** |

⚠⚠ **`schedule` means `post_status = 'future'`, never `publish` with a future
date.** This is the one mapping that looks interchangeable and is not. A row
stored as `publish` with a future `post_date_gmt` is excluded from the public
list by predicate 2 *and* from the owner list, because the owner list asks for
`draft` and `future` and never for `publish` — so the row becomes invisible on
both collections, with no error anywhere. Its author can still reach it by
permalink through the detail route, which is the only reason the state is
recoverable at all.

This was measured against real WordPress dispatch, not reasoned about:
`scripts/tests/announcement-real-dispatch.php` in bcc-trust seeds exactly that
shape and asserts its absence from both lists.

---

## 3. Route shapes the writes would take

These are **not** `#### \`METHOD /path\`` sections, and that is deliberate:
`scripts/contract-parity-guard.php` fails on any endpoint the contract declares
in that format but PHP does not register. A section here would turn umbrella CI
red immediately. Each becomes a canonical section **in the same coordinated
sequence A2.4 used** — PHP registers first, then the umbrella converts — and
not before.

| Route | Verb | Auth | Viewer-dependent? |
|---|---|---|---|
| `POST /bcc/v1/validators/:pageId/announcements` | create (`draft` \| `publish` \| `schedule`) | operator | yes |
| `PATCH /bcc/v1/validators/:pageId/announcements/:id` | edit body/title; re-schedule | operator | yes |
| `POST /bcc/v1/validators/:pageId/announcements/:id/publish` | promote a draft | operator | yes |
| `POST /bcc/v1/validators/:pageId/announcements/:id/archive` | archive (one-way, §4.32.6) | operator | yes |
| `POST /bcc/v1/validators/:pageId/announcements/:id/pin` | pin / unpin | operator | yes |

All five are viewer-dependent by construction: every one of them is a
consequence of who asked. So all five carry `Cache-Control: private, no-store`
and `Vary: Authorization, Cookie`, matching the read side's owner surfaces.

**Refusals reuse the read side's vocabulary exactly** — one 404 producer, one
403 producer, one 503 producer:

- feature off → `404 bcc_not_found`, byte-identical to a missing row;
- not the operator → `403 bcc_forbidden` + `reason_code: not_claimer`, the
  same body for no claim, someone else's claim and competing claims;
- a write that cannot be completed → **not** a 200. §4.32's read side chose 503
  over an empty page because an empty announcement bar looks normal; a write
  has the sharper version of the same problem, since a "saved" response that
  did not save is worse than an error.

⚠ **Every bounded argument needs an explicit `validate_callback`.** WordPress
defaults an argument into schema enforcement only when the argument declares no
`sanitize_callback`, so an argument with a sanitizer and `minimum`/`maximum`
has **decorative** bounds. Measured on WordPress 6.7.2: `?limit=51` answered
200 with 50 rows until `'validate_callback' => 'rest_validate_request_arg'` was
added. This applies to every length-bounded or range-bounded write field, and
~30 arguments across 15 existing endpoint files in bcc-trust have the same
shape today (reported separately; not changed by A2.4).

---

## 4. Ordering inside a write

The reads established that the gate order is itself a guarantee. Writes take
the same order, for the same reason:

```
feature switch  →  schema validation  →  operator authority  →  state transition
                                                              →  marker write
                                                              →  read-back
                                                              →  response
```

- **Switch first.** A withdrawn surface must not resolve a claim, and must not
  write. The read side proves this at SQL level: with the feature off, a
  request to any of the four routes issues zero announcement queries and zero
  claim-resolution queries, with a positive control showing both fire when on.
- **Authority before transition**, taking the decision array — never
  `current_user_can`, never page authorship (the claim flow *moves* page
  authorship, so `post_author` is not an ownership signal), never a mirror
  claim row, never PeepSo membership, never a read model. §4.32.2 forbids all
  five.
- **Read-back after write.** The precedent is the Cosmos endpoint switch:
  "no DB error" is also true for zero rows matched, so the write is not
  confirmed until a read says the row holds the new state.

---

## 5. What would be easy to get wrong

1. **Writing the marker without the ownership recheck.** Predicate 3 exists
   because `post_status` can be moved by paths that are not this feature. A
   writer that sets `publish` and writes the marker in one step, from one
   authority check, collapses the recheck the predicate was added for.
2. **Scheduling as `publish` + future date.** §2 above. Invisible on both
   collections.
3. **Emitting `blocked`.** There is no marker for it (§6.2). A write that
   accepts it, or a read mapping that emits it, is reporting a state nothing
   can set.
4. **Reusing the public list's cache posture.** The reads are all `no-store`
   and the two public ones send no `Vary`; a write must not inherit the
   *absence* of `Vary` along with the `no-store`.
5. **Treating archival as deletion.** §4.32.6 keeps the permalink and the
   original `published_at`, and the detail route must keep resolving an
   archived row.
6. **Backdating.** §4.32.5 rejects it on every path, including update.
7. **Assuming an uncached repository makes an HTTP cache safe.** The
   repository sits behind the cache. This is why the reads carry no TTL, and a
   write response is not a reason to revisit that.

---

## 6. What this document refuses to decide

### 6.1 Who writes the publication-authorization marker, and when

The marker is **read** by every public read path and **written** by nothing.
Three candidate moments exist (at the publish verb; at scheduled promotion; by
a reconciler), and choosing between them decides whether a scheduled row
carries a marker before it is due — which in turn decides whether predicate 3
or predicate 2 is the one keeping it private. That is a real design decision
with a real failure mode on each side, and A3 owns it. **This document does not
pick, and does not invent a third marker to sidestep it.**

### 6.2 `blocked`, and reconciler arm C

§4.32.13 defines `blocked` as a row moved to a non-public state by **reconciler
arm C**, which does not exist. It has no `post_status`, so nothing distinguishes
it from an ordinary `draft`. A2.4's consequences, which A3 inherits:

- the owner list's `status` enum **rejects** `blocked` rather than mapping it,
  because mapping it would return ordinary drafts — a wrong answer that looks
  right;
- the read-state mapping never returns `blocked`, and says so where it would
  otherwise be computed;
- an operator's own blocked row therefore reads as `draft` until arm C lands.

When arm C is built it must define its marker, the enum must accept `blocked`,
and the read mapping must consult it — three edits in lockstep. Until then this
is a **documented** gap, not a silent one.

### 6.3 Two further silences, named and left alone

- **Tombstone representation** — whether a removed comment is
  `comment_approved = '0'` or a meta flag is A5's decision. §4.32.7 requires
  tombstones to count toward `comment_count` so the rendered count matches, and
  the read side puts that predicate behind one named constant so A5 changes one
  line. No write here depends on the answer.
- **Scheduler transport** — nothing promotes a `future` row at its
  `post_date_gmt` today. WordPress's own `future_to_publish` transition, a cron
  sweep and an Action Scheduler job are all plausible, and they differ in what
  happens to a row whose promotion is missed. Out of scope here.

Also unresolved and not this document's to fix: the feed projection of a
publish (`FeedColdStartService::VERB_BY_MODULE` has no announcement verb, and
the §2.7 cadence-pressure policy constrains the wording), and the pre-existing
`_meta.version` discrepancy — `ApiResponse::ok()` emits `v1` while §1.4's
worked examples show `"1.0"` — which affects every endpoint equally and is
deliberately left alone rather than fixed under an announcements change.
