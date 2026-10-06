# Session Security Stack — Rollout Checklist (#173 + #174 + #175)

**Audience:** Phillip + Tialuxe (operators).
**Scope:** releasing the accepted bcc-frontend session-security stack to **production**.
There is no staging tier for this repo — a merge to `main` *is* the production release.
**Related:** [deploy-runbook.md](deploy-runbook.md) (plugins; explicitly does **not** cover
the frontend) · [validator-messaging-rollout.md](validator-messaging-rollout.md) ·
[operator-runbook.md](operator-runbook.md).

**Status: NOTHING AUTHORIZED.** No merge, retarget, promotion, deployment or account
change has been performed or is approved. This document is the plan only.

---

## 0. The accepted artifact

| Item | Value |
|---|---|
| **Release head** (`fix/session-isolation`) | **`81807de644a31e10c97a57abfacd2a739533c201`** |
| **Release tree** | **`88a2e40af1672e20072f692bd4549a55c04eeb6f`** |
| Superseded integration commit | `3eed76b4880d4230107452829c089831190e73c5` (tree `c0034fdf…`) — kept on `tmp/integration-173-174-175` for audit |
| #173 session isolation | base `main`; head is now the release head above |
| #174 password continuity | `1b4b0fd0858168fdd1902dc373cb434bd25e5920` — **MERGED** into `fix/session-isolation` |
| #175 viewer-scoped storage | `c7c4205bd6551c7c4d8fdee217e5a1e8bcf7f9c3` — **MERGED** |
| #176 pre-revocation bearer + notice deadline | `86d6d6b1ef9c7b241ff048dd1a9c8a2d2a64f830` — **MERGED** |
| #177 preserve legacy drafts (Q1) | `97d8c48f54fc434c0b96dd482561b81a883c0047` — **MERGED** |
| #178 deadline on every read | `d182a992747eb076931538219328392ce0604acb` — **MERGED** |
| Temporary integration branch | `tmp/integration-173-174-175` (keep until release; delete after) |
| Its Preview deployment | GitHub deployment `6860774038`, `environment=Preview`, `production_environment=false`, state `success` |

**Check provenance.**

For the **release head `81807de`** the checks are complete and include GitHub
Actions, because it is #173's head and therefore runs as a `pull_request`:
`Frontend — tsc · lint · vitest` **pass (2m41s)** · Vercel **pass**,
`environment=Preview`, `production_environment=false`. Locally on the same tree:
`tsc` clean · `vitest` **110 files / 2680 passing** (1 expected fail) ·
`knip --include files` exit 0 · `next lint` 0 errors · cadence-pressure guard PASS ·
**53 mutation controls, all RED**.

For the **superseded `3eed76b4`** the record stands as it was: local plus Vercel
Preview only, with **no GitHub Actions run**, because `ci.yml` triggers on
`push: [main]` and `pull_request` only and no PR was opened for the integration
branch. That gap is now closed by the release head above.

---

## 1. What a push to frontend `main` automatically does

Three triggers fire, none of them gated by an approval step:

1. **Vercel — production deployment, immediately.** `main` is the production branch:
   every `Production` deployment record for this repo points at main's head (newest:
   `5728dfe…`, 2026-09-16). `vercel.json` has no `ignoreCommand`, so the push builds
   and goes live on the production domain.
2. **`ci.yml`** (`on: push: branches: [main]`) — `npm ci` → `npx tsc --noEmit` →
   `npx next lint` → `npx knip --include files` → `npx vitest run`.
   ⚠ This runs **after** the merge. It reports on `main`; it does not gate it.
3. **`notify-root.yml`** (`on: push: branches: [main]`) — dispatches `sibling-push` to
   `simontx1983/Blue-collar-crypto`, re-running the umbrella cross-repo guards
   (contract parity, subsystem count, cadence-pressure, dead-file) against the new
   frontend code. No-ops with a warning if `DISPATCH_TOKEN` is unset.

Side effect of any production deployment: `vercel.json` declares the one-minute cron
`/api/internal/cron/indexer-tick`. Crons register from **production** deployments only,
which is why none of this session's Preview builds scheduled anything.

---

## 2. Release steps — one production deployment, the complete tested tree

The two siblings merge into #173's branch first. Those merges target
`fix/session-isolation`, **not** `main`, so they produce **Preview** builds only.
Production is then entered once, with all three PRs in it.

| Step | Action | Deploys to | Gate before proceeding |
|---|---|---|---|
| ~~1~~ | ✅ **DONE** — #174 merged into `fix/session-isolation` (`a10540a`). | Preview | passed |
| ~~1b~~ | ✅ **DONE** — follow-ups #176, #177, #178 merged in (see §0). | Preview | each passed |
| ~~2~~ | ✅ **DONE** — #175 merged (`b712457`). | Preview | passed |
| ~~3~~ | ✅ **DONE** — the tree matched `c0034fdf…` exactly at that point. After the three follow-ups it is **`88a2e40af1672e20072f692bd4549a55c04eeb6f`**, which is the value to re-verify before step 5. | — | matched |
| ~~4~~ | ✅ **DONE** — #173's CI **pass (2m41s)** and its Vercel Preview **pass** on the release head `81807de`, `environment=Preview`, `production_environment=false`. This is the GitHub Actions run the integration branch could not produce. | Preview | both green |
| **3′** | **Re-verify before release:** `git fetch origin && git rev-parse origin/fix/session-isolation^{tree}` must print **`88a2e40af1672e20072f692bd4549a55c04eeb6f`**. | — | **Exact match required**, else stop and report. |
| **5** | Merge **#173** → `main`. **This is the production release.** | **Production, once** | — |
| **6** | Post-release verification (§4), then delete `tmp/integration-173-174-175`. Its Preview deployment record remains for audit. | — | — |

Why this shape:

- **No user is ever on a partial combination.** Production moves from "none of the
  three" to "all three" in a single deployment.
- **No retargeting.** Each PR merges into the base it already has; #174 and #175 close
  as merged on their own terms and the reviewed commit history survives.
- Steps 1–2 are reversible on a non-production branch; only step 5 is user-facing.

⚠ **Merge commits, not squash.** Squashing recomputes #175's three-way merge against a
different base. The content is expected to be identical here, but the step-3 tree
identity is only guaranteed for merge commits. If you squash anyway, re-run step 3
before step 5 and treat a mismatch as a stop.

⚠ **#174 and #175 cannot merge before #173.** Their base is `fix/session-isolation`.
If #173 merged first and that branch were deleted, GitHub would auto-retarget both to
`main` and their diffs would change shape. The order above avoids the situation
entirely.

---

## 3. Intermediate states, if you sequence to `main` instead

Direct answer to "could intermediate production builds expose an incomplete security
fix": **no intermediate state introduces a new vulnerability.** The three PRs fix three
independent pre-existing defects, so an intermediate state ships *fewer* fixes rather
than creating a hole.

| Production state | Still unfixed |
|---|---|
| `main` + #173 | #175's defect: browser storage remains unscoped, so one viewer's recents / tour / onboarding / communities state is still readable by the next viewer of that browser. Mitigated exactly as today, by #173's arrival sweep clearing the fixed legacy key list. Also #174's defect: a password change can still leave the session holding a revoked bearer while showing "Saved". |
| `main` + #173 + #174 | #175's storage defect, as above. |
| `main` + #173 + #175 | #174's password-change defect. |

Two real costs, neither a vulnerability:

- **The storage migration (§5) fires at the #175 step and cannot be undone.** Doing it
  once, inside a single release, is strictly better than doing it mid-sequence.
- **Three production deployments** means three windows where a user has an old tab open
  against a new build. In the #175 window specifically, an old tab keeps writing the
  unscoped key names while the new build's purge deletes them, so that tab loses its
  recents and tour state. No disclosure — the new build never reads unscoped keys — but
  avoidable noise.

---

## 4. Post-release verification

### 4a. No authorization needed (no session, no account)

1. Vercel shows a new **Production** deployment in state **Ready** for the merge commit,
   and the previous production deployment is still listed (§6 names it).
2. `ci.yml` on `main` is green.
3. `notify-root` either dispatched to the umbrella or logged the missing-token warning.
4. The guest root (`/`) serves the marketing landing, and `/login` renders a live
   sign-in form.
5. `GET /api/auth/session` with no cookie returns a readable `{}` (not an error page).
6. No new `CLIENT_FETCH_ERROR` volume in Sentry beyond baseline; no unhandled rejections.

### 4b. REQUIRES SEPARATE AUTHORIZATION — not performed by default

These need a signed-in session, and each one is explicitly **not** approved by this
document. **Your personal session must not be used, and no password may be changed.**
Each requires either your own hands-on execution or a dedicated test account you
authorize me to use.

| Test | What it needs | Why it is gated |
|---|---|---|
| Storage keys are viewer-scoped (`bcc-recent-searches::<id>` etc., no bare legacy names, device preferences intact) | one signed-in session | reads a live session |
| **Sign-out** clears the departing viewer's scoped keys and preserves device preferences | signing a session out | ends a live session |
| **Second account** on the same browser sees none of the first account's state | a second account | account creation / use |
| **Password change** shows either "Saved" with the session intact, or the "Password changed … sign in again" panel landing on `/login?authNotice=password-changed` with the specific notice | rotating a real credential | **irreversible credential change** |

### 4c. What production cannot verify at all

The blip paths — unreadable session → hide-in-place → Retry, and the repeated-loss
re-check on a cross-tab broadcast — require forcing `/api/auth/session` to fail, which
cannot be induced safely in production. Their evidence is the 43 mutation-backed tests
and three browser runs against controlled fixtures, and that is the honest limit.

---

## 5. The storage migration — exactly what is deleted, and what is now PRESERVED

On the **first load of the new build in each browser**, `purgeLegacyUnscopedKeys()`
deletes the following from **both** `localStorage` and `sessionStorage`. This runs once
per document, for signed-in and anonymous visitors alike.

| Key deleted | What it held | Recoverable after a code rollback? |
|---|---|---|
| `bcc-recent-searches` | the last 5 search terms | **No.** Gone from that browser. |
| `bcc-tour-seen` | which product tours were finished | **Yes** — it mirrors `bcc_tours_seen` user-meta and repopulates from `/me/tours-seen`. |
| `bcc-tour-progress` | position inside a running tour | **No.** A mid-tour position is lost. |
| `bcc-tour-dismissed` | tours dismissed for this browser session | **No.** A dismissed tour may re-offer. |
| `bcc-onboarding-progress` | how far the setup wizard got | **No, and there is no server mirror.** A half-finished wizard's resume point is irrecoverable. |
| `bcc-onboarding-resume-dismissed` | "don't offer to resume setup" | **No.** The resume prompt may re-appear once. |
| `bcc.communities.dismissed` | "not now" on the NFT-community activation prompt | **No.** The prompt may re-show once. |
| ~~`bcc.blog.draft.<handle>` / `bcc.blog.draft.anon`~~ | the autosaved body of an UNPUBLISHED blog post | **NOT DELETED any more — decision Q1, #177.** Preserved in place: nothing reads it, nothing adopts or displays it, and sign-out still clears the prefix so its lifetime is unchanged. ⚠ Preserving it is not protecting it: it sits in plain `localStorage` and anyone with devtools on that browser profile can read it. Manual recovery requires establishing ownership out of band, since the key names no dependable owner. |

**Unpublished drafts, stated plainly.** Only drafts whose last autosave came from the
*pre-release* build are affected — nothing a writer has open on screen is lost, because
the composer holds its text in memory and the new build autosaves to the new key within
five seconds. The loss lands on someone who closed the tab with an unfinished post and
returns after the release.

### Quarantine instead of deletion — open decision (2026-10-06)

Deleting unpublished writing is the one loss in this list that is not cheap, and it is
avoidable. **After #175 nothing in the app reads a legacy draft key**: the composer
resolves `bcc.blog.draft::<viewer id>` and nothing else. So the drafts can simply be
**left in place** rather than deleted — preserving the writing without adopting or
displaying it to anyone.

| Option | Effect | Verdict |
|---|---|---|
| **Q1 — stop deleting the `bcc.blog.draft.` prefix** (keep deleting the seven exact keys) | The legacy value stays exactly where it already is, readable by nobody the app routes to it, and the existing sign-out sweep still clears the prefix — so its lifetime is unchanged from today's. **Access-neutral: no new exposure.** | **Recommended.** One-line change; preserves the writing. |
| Q2 — rename to an opaque `bcc.quarantine.draft.<n>` key | Same access profile as Q1 but more code, and it *extends* the data's lifetime past sign-out unless the new prefix is added to the departure sweep too. | Not worth it over Q1. |
| Q3 — offer a click-gated "recover a pre-update draft" affordance | Would actually return the writing to a person — but it **displays** unattributable content to whoever is signed in, which is the cross-account display defect behind a button. | **Not recommended.** |

Under Q1 the preserved value is recoverable only by hand (devtools) or with operator
help; nothing in the UI surfaces it. That is the honest trade: the writing survives, and
no one is shown someone else's words.

**Why safe migration cannot preserve the other seven, or re-attribute drafts.**

- The seven exact keys are **unscoped: they name no owner.** Neither the key nor the
  value records who wrote it. Renaming them into whoever signs in next is precisely the
  cross-account display defect being removed — it would hand one person's search
  history, or their position in onboarding, to another.
- `bcc.blog.draft.<handle>` looks owned but is not dependable. Its suffix collapsed to
  the literal `anon` whenever the session had not resolved yet, which names nobody and
  was shared by everyone; and a handle is **renameable** (`PATCH /me/handle`, 7-day
  cooldown) and reclaimable, so `…draft.alice` may have been written by a different
  person than today's "alice". Adopting it could hand one person's unpublished post body
  to another — the worst payload in the set. Deletion is the cheaper error.
- There is no server copy to restore from for any of these except `bcc-tour-seen`.

---

## 6. Rollback — the supported operation, and its limits

### 6a. These are three different Vercel operations

- **Redeploy** of an earlier deployment creates a **new** deployment and **rebuilds**
  from that commit. It is not a restore and not instant.
- **Promote to Production** takes an **already built** deployment and makes it
  production by moving the production alias. No rebuild.
- **Instant Rollback** is Vercel's dedicated feature for reassigning production to the
  previous deployment. **Availability depends on the project's plan and settings and
  has not been verified from here** — this session had no Vercel API access, only
  GitHub's mirror of deployment status.

**Before step 5, open the Vercel dashboard and record which of Promote / Instant
Rollback this project actually offers.** Do not plan around an instant restore that may
not exist.

⚠ **OUTSTANDING — dashboard verification not done.** It could not be done from here:
there are **no Vercel CLI auth artifacts on this machine, no `VERCEL_TOKEN`, and no
Vercel MCP connection**, and no account was connected and no token requested. So
**which rollback operation this project supports is UNVERIFIED**, and that is a release
prerequisite, not a detail.

⚠ **A 302 from the old deployment does not prove it can be promoted.** The probe below
shows the deployment still *exists and is served* — it answers Vercel's
deployment-protection gate rather than a `DEPLOYMENT_NOT_FOUND` — and that is all it
shows. Whether the production alias can be moved back to it is a dashboard question.
**Treat `git revert` (§6c) as the operation you actually have.**

### 6b. The rollback target, exactly

The deployment serving production today, and therefore the thing to roll back to:

| Field | Value |
|---|---|
| GitHub deployment id | **6475654088** |
| Environment | `Production`, state `success` |
| Created | 2026-09-16T07:08:50Z |
| Commit | **`5728dfe62feeb6d62ca25619fa2f1d02d6f8fc18`** (current `main`) |
| Vercel URL | `https://bcc-frontend-28h8cemkc-phillip-simon-s-projects.vercel.app` |

### 6c. The always-available path

`git revert -m 1 <merge commit>` on `main`, then push. This triggers a **fresh
production build** plus `ci.yml` and the root dispatch. Budget **roughly two minutes**
for the build based on this session's Vercel builds — **not** instant.

### 6d. What a rollback cannot undo

- **The storage deletions in §5.** They happened in each visitor's browser and no code
  change brings them back.
- **A rollback is a second loss event in effect.** The old build reads the bare key
  names (now deleted) and cannot see the new `base::<viewer id>` keys the new build
  wrote, so users see empty recents and tour state again. Nothing is destroyed by the
  rollback itself, but nothing is restored either.
- **Credential rotation is server-side.** A password changed during the release window
  bumped `bcc_token_version` in bcc-trust; a frontend rollback cannot un-revoke those
  tokens, by design.

**Decision rule: roll back for breakage** (blank screens, sign-in failures, 401 storms).
**Do not roll back to recover storage** — it cannot.

---

## 7. Unresolved decision carried into acceptance — #174 residual B

This is open, it is part of the acceptance decision, and it needs a **backend** change
that is not authorized.

### What the backend does today

A password change calls `JwtToken::revokeAllForUser()`, which bumps a per-user counter
(`bcc_token_version`) in user-meta, and then mints one fresh bearer carrying the new
counter value as its `tv` claim. `JwtToken::decode()` rejects any token whose `tv` does
not match the current counter (`jwt_revoked` → 401), and `decodeForRefresh()` shares the
same gate, so a revoked token can never be refreshed either.

### The race

A token **refresh** that minted its bearer a moment *before* the bump carries the old
`tv` — it is dead the instant the bump lands. If that refresh's session write lands
*after* the frontend's confirming read, the frontend sees "a different, non-empty
bearer", reads that as a concurrent write replacing its own, and reports success.

### Practical security impact

- The viewer is told **"Saved"** while their browser session holds a **dead** bearer.
- **Nothing can act with it.** Every protected request 401s; the single refresh attempt
  fails the same revocation gate; the session is then *proven* dead and torn down —
  render gate closed, query cache purged, the departing viewer's storage cleared,
  sign-out with a document load.
- **Timing:** the badges query polls every 30–60s while the tab is visible and refetches
  on focus, so an idle tab self-corrects inside about a minute; any interaction corrects
  it at once.
- **Owner UI can stay painted during that window.** Server-rendered owner gating is
  computed from the NextAuth **cookie**, not from the bearer, so owner controls already
  on screen remain visible — but no data can be read or written.
- **Who is exposed:** the account holder themselves, on their own device, seconds after
  typing their own current password. **It is not a cross-account disclosure.**
- **The misleading part, and the real cost:** because the frontend reported success it
  withdrew the parked `password-changed` notice, so the teardown a moment later shows
  the generic "your session ended" instead of "use your new password". A viewer may then
  try their **old** password, fail, and conclude the change did not work — when it did.

### ✅ RESOLVED 2026-10-06 in #176 + #178 — but read the limits below

Both halves are now implemented, frontend-only:

- **the notice survives the race.** `password-changed` is parked with a
  **120s deadline** and no longer withdrawn on a reported success, because that
  report includes the outcome that can be wrong. Browser-verified: after a
  reported success, a generic teardown landed on `/?authNotice=password-changed`
  and rendered "…sign in again using your NEW password", not "your session
  ended".
- **a pre-revocation bearer is refused.** Browser-verified: with the echo
  present but the confirm reading a `tv`-6 bearer against a `tv`-7 mint, the
  flow reports failure and shows "Password changed … sign in again" instead of
  "Saved". A `tv`-7 concurrent bearer still reports success.

⚠ **What it is not.** The check is a consistency test over an **unverified**
payload, so it may only ever refuse; the echo remains the only thing that can
accept a foreign bearer. Malformed, missing, out-of-range or
subject-mismatched claims count as refusals. An absent `tv` is treated as
malformed, not as a comparable 0.

### The discriminator — CORRECTED 2026-10-06: it is available client-side

An earlier revision of this document said no discriminator existed in the session body
and that closing residual B required a bcc-trust change. **That was wrong**, and the
correction matters for the acceptance decision.

What is true: `bccTokenExpiresAt` cannot discriminate — it is stamped
`Date.now() + expiresIn` by whichever client built the write, and both endpoints report
the same TTL, so it orders **writers**, not **validity**.

What was missed: the bearer itself carries the counter. `JwtToken::encode()` puts
`'tv' => currentTokenVersion($userId)` into the payload of a plain HS256 JWT, and the
password-change response hands the frontend the freshly minted token. So the frontend
can base64url-decode the `tv` claim of the token it was given, decode the `tv` of
whatever bearer it later finds in the session, and compare:

| Found bearer's `tv` | Meaning | Correct report |
|---|---|---|
| `>=` the minted `tv` | minted at or after the revocation bump — a genuine concurrent write | success |
| `<` the minted `tv` (including absent, i.e. 0) | minted **before** the bump — already revoked | failure: "sign in again" |

This is a **frontend-only** fix, needs no new endpoint and no backend change, and it
closes residual B itself rather than only its symptom. Reading claims without verifying
the signature is sound here because the comparison is between two of our own tokens and
is not a trust decision: a garbled payload decodes to no `tv`, which compares as stale
and errs toward the safe answer.

**Not implemented.** It changes #175 and therefore supersedes the accepted artifact
(§0), so it is a release-approval decision, not a silent edit.

**Likelihood:** requires a refresh minted inside the sub-second window before the
revocation bump, whose session write also lands after the confirming read, and only on a
password change. Rare, bounded, and self-correcting within about a minute — but real,
and recorded here rather than buried.

---

## 8. Deliberately out of this release

- **#167–#172 are not bundled and are not to be rebased.** They remain untouched at
  `fe7599bd`, `1cdf9cae`, `0b860971`, `5900579c`, `c8233d1d`, `44597a13`. (For
  information only: the accepted tree merges cleanly against all six, so no contention
  is expected whenever they are taken up. **No rebase is planned or authorized.**)
- The five blanket-`isError` wipes in the journey-review backlog.
- The `/auth/refresh` discriminator of §7.

---

## 9. Authorization still required

| Needs your explicit go-ahead | Effect |
|---|---|
| Steps 1–2 (merge the siblings into `fix/session-isolation`) | Preview builds only |
| Step 5 (merge #173 → `main`) | **Production release** |
| Each §4b production test | live session / account / credential change |
| Deleting `tmp/integration-173-174-175` | removes the integration branch (its Preview record persists) |
| Any `/auth/refresh` discriminator work in bcc-trust | backend change, separate review |
