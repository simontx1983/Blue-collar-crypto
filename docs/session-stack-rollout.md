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
| Integration commit | `3eed76b4880d4230107452829c089831190e73c5` |
| Integration tree | `c0034fdf148784168ec94aedbc9a5f104f368b80` |
| #173 session isolation | `cc4dc4736606d4001667ed6ebe25bc2a6f006a8d` (base `main`) |
| #174 password continuity | `1b4b0fd0858168fdd1902dc373cb434bd25e5920` (base `fix/session-isolation`) |
| #175 viewer-scoped storage | `c7c4205bd6551c7c4d8fdee217e5a1e8bcf7f9c3` (base `fix/session-isolation`) |
| Temporary integration branch | `tmp/integration-173-174-175` (keep until release; delete after) |
| Its Preview deployment | GitHub deployment `6860774038`, `environment=Preview`, `production_environment=false`, state `success` |

**Check provenance for `3eed76b4`: local plus Vercel Preview only.** `tsc --noEmit` clean ·
`vitest` **108 files / 2631 passing** (1 expected fail) · `next lint` 0 errors — all run
locally on that exact commit — plus the Vercel Preview build success above.
**No GitHub Actions run exists for `3eed76b4`**, because `ci.yml` triggers only on
`push: [main]` and `pull_request`, and no PR was opened for the integration branch.
Step 4 below is what produces that missing run before anything reaches production.

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
| **1** | Merge **#174** into `fix/session-isolation`. Use a **merge commit** (not squash, not rebase). | Preview | #174's checks green |
| **2** | Merge **#175** into `fix/session-isolation`. Merge commit. | Preview | #175's checks green |
| **3** | **Tree-identity gate.** In a clean checkout of `fix/session-isolation`:<br>`git fetch origin && git rev-parse origin/fix/session-isolation^{tree}`<br>must print **`c0034fdf148784168ec94aedbc9a5f104f368b80`**. | — | **Exact match required.** Any other value means the branch is not the accepted artifact — stop and report. |
| **4** | **CI + Preview gate.** #173's PR now contains all three. Wait for its `pull_request` CI run (tsc · lint · knip · vitest) **and** its Vercel Preview to pass. This is the GitHub Actions run the integration branch could not produce. | Preview | Both green |
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

## 5. The irreversible storage migration — exactly what is deleted

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
| `bcc.blog.draft.<handle>` and `bcc.blog.draft.anon` (every key with the prefix `bcc.blog.draft.`) | **the autosaved body of an UNPUBLISHED blog post** | **No. This is the costliest item.** |

**Unpublished drafts, stated plainly.** Only drafts whose last autosave came from the
*pre-release* build are affected — nothing a writer has open on screen is lost, because
the composer holds its text in memory and the new build autosaves to the new key within
five seconds. The loss lands on someone who closed the tab with an unfinished post and
returns after the release.

**Why safe migration cannot preserve any of this.**

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

### The missing discriminator

Nothing in the session body separates "a concurrent refresh replaced my write" from "a
pre-revocation refresh wrote a dead token". The only candidate, `bccTokenExpiresAt`, is
stamped `Date.now() + expiresIn` by whichever client built the write, and both endpoints
report the same TTL — so it orders **writers**, not **validity**.

**What would close it:** have `POST /auth/refresh` (and the password-change response)
return the **token version** it minted, so the frontend can compare the bearer it finds
against the version it expects and refuse a stale one. That is a **bcc-trust** change.
It is **not authorized** and is **not** part of this release.

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
