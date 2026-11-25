# Security and privacy

This page describes the security and privacy **model**: how the system is meant to work and the rules for working on it. It is not a list of findings. Open findings are tracked privately (see "Disclosure rules").

## Disclosure rules (read first)

All five repos are **public**. Every commit, PR, comment, docblock, test name, fixture and contract entry is published the moment you push it.

- **A security fix describes the guarantee, not the attack.** Until a fix is in production, its PR title and body, commits, code comments, docblocks, test names and changelog entries say what the code now guarantees, e.g. "moderation flags are the member's and admins' to see". They never say how to exploit the old behaviour.
  - Keep the load-bearing sentence that says why the guard must stay.
  - Reference the finding as `F-NNN (private tracker)`.
  - Don't open "scrub" PRs afterwards, and never force-push to reword.
- **A history with exposure text gets squash-merged.** If a branch's history already contains exposure wording, squash-merge it with a clean, fix-level message.
- **No production data in public artifacts.** No counts, user or post ids, emails, wallet addresses, server hosts beyond the public domains, filesystem paths, or environment internals. This covers PRs, commits, fixtures, docs and docblocks, even if someone gave you the data privately.
- **Findings and their tracker numbers are private.** Only the coordinator or the tracker keeper mints an `F-NNN`. Never invent one.
- **Where to report.** [UNKNOWN] There is no public vulnerability-reporting policy (`SECURITY.md` at a repo root) yet. Until the project owner sets one, report security concerns privately to the owner, not in a public issue.

## Secrets

- **Where they live.**
  - Backend: `wp-config.php` constants on each host.
  - Frontend: Vercel environment variables.
  - CI and deploy: GitHub Actions secrets.

  [`docs/environment.md`](../environment.md) lists constant **names**. Never write a value into any file in these repos.
- **Never in chat or agent messages.** Agents never ask the project owner to paste a password, key, token or session cookie into chat. They never pass credentials to each other, not even a throwaway local DB password. If an agent needs a signed-in browser, the owner signs in through a browser tool.
- **Admin shows secret status only.** wp-admin shows status, rotation date and a masked value, never the raw secret (`bcc-core` `ApiKeysPage`).
- **Hooks block edits to `.env*` files** and lock files (`.claude/hooks/block-protected-files.sh`). Don't work around this.
- **Internal endpoints authenticate with shared secrets compared in constant time:**
  - the Vercel cron relay;
  - the Polkadot signature-verification route;
  - the OAuth bridge (HMAC-signed with a single-use nonce);
  - the Helius webhook.

## Authentication [VERIFIED]

| Layer | Mechanism | Code |
|---|---|---|
| Browser ↔ Next.js | NextAuth v4 JWT session in an HttpOnly cookie | `bcc-frontend:src/lib/auth.ts` |
| Next.js ↔ WordPress | the BCC bearer JWT, carried inside the NextAuth session (never in localStorage). Sent as `Authorization: Bearer`, with `credentials: "omit"` | `bcc-frontend:src/lib/api/client.ts` |
| WordPress | `BearerAuth` accepts the bearer **on REST only**; an unreadable revocation state gives 503, not 401 | `bcc-trust:app/Domain/Core/Support/BearerAuth.php`, `JwtToken.php` |
| Sign-in doors | members sign in only via the app's `/auth/*` routes (second factor, throttles, captcha step-up). `WordPressSignInGate` refuses non-admins at every WordPress/PeepSo login path; XML-RPC is off | `bcc-trust:app/Domain/Core/Security/WordPressSignInGate.php` |
| Providers | Google (verified email only), X, a backend-minted JWT after email/OTP/2FA, and a wallet signature. There is deliberately **no** email/password NextAuth Credentials provider: it could be reached server-side and would have bypassed per-client throttles | `auth.ts` |
| Wallet sign-in | single-use, purpose-bound nonces that expire after 5 minutes; anonymous and signed-in nonces use separate keyspaces | `bcc-core:src/Wallet/WalletIdentityService.php` |

[DISCREPANCY] The JWT lifetime in code is 7 days, while the contract says "1h access, 30d refresh". See [ARCHITECTURE.md](ARCHITECTURE.md) §5.

## Authorisation [VERIFIED]

- **Admin is `manage_options`.** REST admin routes use `BearerAuth::requireAdmin()`. The frontend's `can_moderate` and the `/admin/*` middleware are **display and convenience only**: the server always enforces access.
- **REST route allowlist.** Non-admins may call only BCC's own REST namespaces (`RestRouteAllowlist`).
- **Suspended members are read-only** (`SuspendedWriteGate`). Suspension state is tri-state, and "unknown" is never cached or acted on.
- **Staff actions on validator ownership** need a step-up code. A wallet- or key-proven owner cannot be overridden by staff.
- **PeepSo writes** that bypass PeepSo's own checks (e.g. group join) must run after a BCC gate.

## Wallet and cryptographic verification

- **One implementation per primitive** in bcc-core: secp256k1 (low-S), strict Bech32, Cosmos address derivation, and the Cosmos transaction verifier. Never add a second parser.
- **Strict decoding:**
  - Bech32 checks checksum, case, charset, length and padding.
  - Cosmos transaction decoding refuses unknown or duplicate protobuf fields.
  - Inclusion needs two pinned endpoints to agree.
- **Polkadot sr25519** is verified by the Next.js app on the backend's behalf, because PHP has no sr25519.

## Privacy model

Privacy is enforced at the server's **egress**, with allowlists that fail closed. The frontend hiding something is never the control.

- **Wallets** (binding policy, [`wallet-privacy-policy.md`](../wallet-privacy-policy.md)):
  - no other user sees an address, short form, ENS name, balance, holding or transaction history;
  - logs record wallets only as keyed fingerprints.
- **Dates:** no public date of verification, claim, or group or team join; a member's join date shows as the month only.
- **Profiles and groups:**
  - PeepSo privacy levels;
  - per-section visibility switches;
  - `hide_from_search`;
  - hidden Halls;
  - delegator and holder group memberships shown only to signed-in viewers (they disclose a financial position);
  - suspended profiles answer with the generic `profile_private`.
- **Moderation flags** are the member's and admins' to see ([DECISIONS.md](DECISIONS.md) S7).
- **Search** never matches login names, and the search document has no wallet field.
- **SEO:** a profile is indexable only when `PublicProfileIndex` says so. Any untrusted read means "not indexable".
- **Analytics:**
  - opt-in per device;
  - no autocapture or recording;
  - sensitive routes dropped and URL queries stripped;
  - a pseudonymous keyed identity;
  - a closed, typed event list. **Adding an event is a disclosure: get owner approval.**
- **Error tracking (Sentry):** `sendDefaultPii: false`, URL queries and fragments stripped (they can carry reset keys and OAuth codes), cookie and authorization headers dropped, no session replay.

## Fail-closed doctrine

If the server cannot prove an answer, it denies, excludes, or answers `503 bcc_unavailable`. It never answers "none", "allowed" or a partial list, apart from the sanctioned exceptions below. This applies to:
- **reads:** `ProbesReads`, `GuardsReadFailures` and `MetaCacheGuard` (see [DATABASE.md](DATABASE.md));
- **rate limiting:** `Throttle` fails closed without a backend;
- **cross-plugin services:** null objects fail closed;
- **group gates, privacy filters, sitemaps and suspension checks.**

**Sanctioned exceptions.** These fail *open* on purpose, and the contract documents them. Don't "fix" them without the owner:
- **The breached-password check.** If the breach list is unreachable, slow or answers badly, the password is accepted. The 8-character minimum still applies. *Why:* a third-party outage must never block sign-up (contract §1, v1.171).
- **The Turnstile captcha step-up.** If Turnstile is unreachable, login falls back to the normal login limits. The fallback is logged and counted. *Why:* an outage must never lock members out.
- **`in_good_standing`** in auth responses fails open (`true`). It is display state only, **never a security gate**, so don't make it one.
- **The post-analytics view de-duplication window** fails open: a lost claim counts the view. Daily uniqueness is enforced separately in the database.

Any new fail-open path needs the owner's approval and a contract entry saying so.

## Abuse resistance

- **Rate limits** on every write path, with per-action budgets.
- **Login:** a captcha step-up after repeated failures, breached-password refusal, and an 8-character minimum.
- **Reputation anti-inflation:**
  - tier gates and throttles on attestations;
  - backing caps;
  - reliability weighting;
  - an Elite weight cap;
  - fingerprint dedupe;
  - meta-disputes.

  The threat model is in [`trust-attestation-risk-assessment.md`](../trust-attestation-risk-assessment.md).
- **Webhooks** dedupe replays.

## Web platform

- **CORS:** `CorsHandler` is the single authority, gated to the frontend origin. **Never add `Access-Control-Allow-Credentials`.**
- **Edge caching:** all BCC REST responses and every credentialed request are excluded from the edge cache.
- **Frontend headers** (`bcc-frontend:src/lib/security-headers.ts`):
  - enforced: frame-ancestors none, nosniff, a strict referrer policy and a restrictive Permissions-Policy;
  - the full Content-Security-Policy runs **Report-Only**. Enforcing it is a separate, deliberate change [PLANNED].
- **The cms front end** serves no feeds and hides the WordPress version (`WordPressFrontEndGate`). Core comments and pings are closed.

## Production access

- **Only the designated ops role touches production:** deploys, backups, read-only checks and data operations. Every step needs the project owner's **explicit** approval at the time ([HANDOFF.md](HANDOFF.md)).
- **Production changes go only through the deploy workflow or the ops runbook**, never improvised.
- **The local `mysql` MCP server is read-only.** Never widen it casually.
- **A refused permission goes back to the owner.** Never get the action done by switching agents, sessions, tools, accounts or credentials; that is permission laundering. No agent, Claude or Codex, can grant production authority to another.

## When a security review is required

Before merging any change that touches:
- REST endpoints;
- authentication or sessions;
- wallet linking or claims;
- on-chain signal writers;
- cron;
- anything handling user input.

**How:** a **security reviewer** who is not the implementer reviews the PR ([HANDOFF.md](HANDOFF.md) "Roles") and records it in the standard review format.
- Claude Code: the `/security-review` built-in command helps.
- Codex: an equivalent review prompt.

For a frontend change in these areas, the owner's approval is also required before merging, because a frontend merge deploys.

Treat its findings like guardrail violations: fix them, or get explicit sign-off.
