# BCC Trust Engine — Database Schema

## Tables Overview

| Table | Purpose |
|-------|---------|
| `bcc_trust_votes` | Vote records with fraud context |
| `bcc_trust_page_scores` | Aggregated page trust scores |
| `bcc_trust_endorsements` | Endorsement records |
| `bcc_trust_user_info` | User reputation metadata |
| `bcc_trust_fingerprints` | Device fingerprints for automation detection |
| `bcc_trust_fraud_analysis` | Flagged fraud activities |
| `bcc_trust_verifications` | Email verification tokens |
| `bcc_trust_edges` | Trust graph edges (voter → page owner) |
| `bcc_trust_patterns` | Behavioral pattern tracking |
| `bcc_trust_rings` | Vote ring (collusion) detection |
| `bcc_trust_activity` | Audit/activity log |
| `bcc_pull_meta` | Sidecar metadata for PeepSo follows (card pulls) — V1 |
| `bcc_photo_alts` | Sidecar alt-text metadata for PeepSo photos (§3.3.9 / §4.18) — V1.5 |
| `bcc_user_ranks` | Rank assignments (Apprentice / Journeyman auto, Foreman+ admin) — V1 |
| `bcc_onchain_claims` (extended) | Now also stores page claims (`entity_type='page'`); `recovery_pending` column added for §B5 lost-wallet rule — V1 |

---

## bcc_trust_votes

Stores individual vote records with fraud context.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `voter_user_id` | BIGINT UNSIGNED | WordPress user ID of voter |
| `page_id` | BIGINT UNSIGNED | Target page ID |
| `vote_type` | TINYINT | +1 (upvote) or -1 (downvote) |
| `weight` | DECIMAL(5,4) | Computed vote weight (0.03–0.60) |
| `category_id` | BIGINT UNSIGNED | Vote category |
| `reason` | TEXT | Optional vote reason |
| `status` | TINYINT(1) | 1 = active, 0 = soft-deleted |
| `fraud_score` | INT | Fraud score at time of vote |
| `fraud_context` | JSON | Snapshot of fraud signals |
| `created_at` | DATETIME | Vote timestamp |
| `updated_at` | DATETIME | Last modification |

**Unique Key:** `(voter_user_id, page_id, category_id)` — one vote per user per page per category.

**Indexes:** `voter_user_id`, `page_id`, `status`, `created_at`

---

## bcc_trust_page_scores

Aggregated trust scores per page.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `page_id` | BIGINT UNSIGNED | WordPress page/post ID |
| `page_owner_id` | BIGINT UNSIGNED | Page author user ID |
| `total_score` | DECIMAL(5,2) | Composite trust score (0–100) |
| `identity_score` | DECIMAL(5,2) | Identity verification subscore |
| `endorsement_score` | DECIMAL(5,2) | Endorsement subscore |
| `vote_score` | DECIMAL(5,2) | Vote subscore |
| `activity_score` | DECIMAL(5,2) | Activity subscore |
| `fraud_penalty` | DECIMAL(5,2) | Fraud penalty (subtracted) |
| `reputation_tier` | VARCHAR(20) | elite/trusted/neutral/caution/risky |
| `confidence` | DECIMAL(3,2) | Data sufficiency (0.0–1.0) |
| `total_votes` | INT | Total vote count |
| `total_endorsements` | INT | Total endorsement count |
| `recalculate_required` | TINYINT(1) | Dirty flag for async recalc |
| `is_dirty` | TINYINT(1) | Score needs refresh |
| `created_at` | DATETIME | First calculation |
| `updated_at` | DATETIME | Last recalculation |

**Unique Key:** `(page_id)`

**Indexes:** `page_owner_id`, `reputation_tier`, `total_score`, `recalculate_required`

---

## bcc_trust_endorsements

Endorsement records with vesting.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `endorser_id` | BIGINT UNSIGNED | User giving endorsement |
| `endorsed_id` | BIGINT UNSIGNED | User receiving endorsement |
| `page_id` | BIGINT UNSIGNED | Target page |
| `category` | VARCHAR(50) | Endorsement category |
| `weight` | DECIMAL(5,4) | Computed weight (tier-based) |
| `status` | VARCHAR(20) | active/revoked |
| `vesting_stage` | TINYINT | 0/1/2 (vesting period) |
| `created_at` | DATETIME | Endorsement timestamp |
| `updated_at` | DATETIME | Last modification |

**Unique Key:** `(endorser_id, endorsed_id, category)`

**Indexes:** `endorsed_id`, `page_id`, `status`

---

## bcc_trust_user_info

User-level reputation and fraud metadata.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `user_id` | BIGINT UNSIGNED | WordPress user ID |
| `fraud_score` | INT | Current fraud score (0–100) |
| `risk_level` | VARCHAR(20) | critical/high/medium/low/minimal |
| `trust_rank` | DECIMAL(5,4) | PageRank-style trust (0.0–1.0) |
| `automation_score` | INT | Automation detection score (0–100) |
| `behavior_score` | INT | Behavioral analysis score (0–100) |
| `device_fraud_probability` | DECIMAL(3,2) | Device fraud probability (0.0–1.0) |
| `is_verified` | TINYINT(1) | Email verified flag |
| `is_suspended` | TINYINT(1) | Suspension flag |
| `votes_cast` | INT | Total votes cast |
| `endorsements_given` | INT | Total endorsements given |
| `pages_owned` | INT | Number of pages owned |
| `page_ids_owned` | JSON | Array of owned page IDs |
| `posts_created` | INT | Content creation count |
| `comments_made` | INT | Comment count |
| `fraud_triggers` | JSON | Fraud trigger history |
| `signals_updated_at` | DATETIME | Last async signal update |
| `usr_last_activity` | DATETIME | Last user activity |
| `created_at` | DATETIME | Record creation |
| `updated_at` | DATETIME | Last modification |

**Unique Key:** `(user_id)`

**Indexes:** `fraud_score`, `risk_level`, `is_suspended`, `behavior_score`

---

## bcc_trust_fingerprints

Device fingerprints for multi-account and automation detection.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `user_id` | BIGINT UNSIGNED | WordPress user ID |
| `fingerprint` | VARCHAR(128) | SHA-256 fingerprint hash |
| `automation_score` | INT | Automation confidence (0–100) |
| `automation_signals` | JSON | Detected automation signals |
| `ip_address` | VARCHAR(64) | HMAC-hashed IP (privacy-safe) |
| `user_agent` | TEXT | Browser user agent |
| `risk_level` | VARCHAR(20) | high/medium/low |
| `first_seen` | DATETIME | First appearance |
| `last_seen` | DATETIME | Most recent appearance |

**Indexes:** `user_id`, `fingerprint`, `risk_level`

---

## bcc_trust_edges

Trust graph edges for PageRank-style propagation.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `source_user_id` | BIGINT UNSIGNED | Edge source (voter/endorser) |
| `target_user_id` | BIGINT UNSIGNED | Edge target (page owner) |
| `edge_type` | VARCHAR(20) | vote/endorsement |
| `weight` | DECIMAL(5,4) | Edge weight |
| `created_at` | DATETIME | Edge creation |
| `updated_at` | DATETIME | Last recalculation |

**Unique Key:** `(source_user_id, target_user_id, edge_type)`

---

## bcc_trust_fraud_analysis

Detailed fraud analysis records.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `user_id` | BIGINT UNSIGNED | Analyzed user |
| `fraud_score` | INT | Computed fraud score |
| `risk_level` | VARCHAR(20) | Risk classification |
| `confidence` | DECIMAL(3,2) | Analysis confidence |
| `triggers` | JSON | Array of triggered rules |
| `details` | JSON | Full analysis details |
| `expires_at` | DATETIME | Record expiry (retention) |
| `created_at` | DATETIME | Analysis timestamp |

**Indexes:** `user_id`, `risk_level`, `expires_at`

---

## bcc_trust_patterns

Behavioral pattern storage for ML training.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `user_id` | BIGINT UNSIGNED | Pattern subject |
| `pattern_type` | VARCHAR(50) | Pattern classification |
| `pattern_data` | JSON | Pattern details |
| `severity` | DECIMAL(3,2) | Severity score (0.0–1.0) |
| `created_at` | DATETIME | Detection timestamp |

**Indexes:** `user_id`, `pattern_type`, `created_at`

---

## bcc_trust_rings

Vote ring (collusion) detection results.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `ring_hash` | VARCHAR(64) | Unique ring identifier |
| `user_ids` | JSON | Array of participating user IDs |
| `ring_size` | INT | Number of participants |
| `strength` | DECIMAL(5,2) | Ring strength score |
| `mutual_count` | INT | Number of mutual votes |
| `status` | VARCHAR(20) | detected/confirmed/dismissed |
| `created_at` | DATETIME | Detection timestamp |

**Indexes:** `ring_hash`, `status`

---

## bcc_trust_activity

Activity log for audit trail.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `user_id` | BIGINT UNSIGNED | Acting user |
| `action` | VARCHAR(50) | Action type (vote_up, page_view, etc.) |
| `target_id` | BIGINT UNSIGNED | Target entity ID |
| `target_type` | VARCHAR(20) | Target type (page, user, etc.) |
| `details` | JSON | Additional context |
| `created_at` | DATETIME | Action timestamp |

**Indexes:** `user_id`, `action`, `target_id`, `created_at`

---

# V1 Frontend Support Tables

The four tables below back the headless Next.js frontend per [api-contract-v1.md §6.5](api-contract-v1.md). All defined in `wp-content/plugins/bcc-trust/includes/database/schema-*.php` and installed via the schema-content-hash mechanism in `bcc-trust.php` (auto-runs `dbDelta` on next request when any schema file changes).

---

## bcc_pull_meta — legacy physical name; stores Watch metadata

Sidecar metadata for PeepSo follows that represent BCC card watches. Per §C2 of the V1 plan, the watchlist (formerly "Binder," renamed 2026-05-13 — see `pattern-registry.md` and `api-contract-v1.md §4.5.1`) is a UI projection of `peepso_follower` joined to this table; **NO separate follow graph**. Rows are 1:1 with `peepso_follower` rows.

The **table name and column names retain their original `pull`-prefixed forms** (`bcc_pull_meta`, `tier_at_pull`, `pulled_at`) — a physical rename is deferred to a later release because it requires a data-copy migration with no API-surface benefit (these names are internal storage; user-facing API field names are `watched_at`, `card_tier_at_watch`). The logical concept stored here is "watch metadata."

| Column | Type | Description |
|--------|------|-------------|
| `follow_id` | BIGINT UNSIGNED | PeepSo follow row ID — PRIMARY KEY (1:1 with follow) |
| `tier_at_pull` | VARCHAR(20) | `card_tier` at moment the card was watched (`legendary`/`rare`/`uncommon`/`common`); preserves historical narrative even when the entity's current tier changes. **API exposes this as `card_tier_at_watch`.** |
| `batch_id` | VARCHAR(64) | Ties watches into one feed post per §C3 (10-minute rolling inactivity window) |
| `visibility` | VARCHAR(20) | Per-watch visibility (V1: always `'public'`; reserved for V2 per-card hiding) |
| `pulled_at` | DATETIME | Watch timestamp. **API exposes this as `watched_at`.** |

**Primary Key:** `(follow_id)` — single row per follow

**Indexes:** `batch_id`, `pulled_at`, `tier_at_pull`

---

## bcc_photo_alts

Sidecar alt-text metadata for PeepSo photos. PeepSo's `peepso_photos` has no native `alt` column, and adding one would be brittle under PeepSo updates — so the alt lives BCC-side. Rows are 1:1 with `peepso_photos.pho_id`. The author-supplied string surfaces in the §3.3.9 photo body via `FeedRankingService::loadPhotoBodies` and is written via §4.18 `PATCH /photos/:pho_id/alt`.

| Column | Type | Description |
|--------|------|-------------|
| `pho_id` | BIGINT UNSIGNED | `peepso_photos.pho_id` — PRIMARY KEY (1:1 with photo row) |
| `owner_id` | BIGINT UNSIGNED | Cached `peepso_photos.pho_owner_id` snapshot at write time. Lets the write endpoint authorise via a single PK read on this table without joining peepso_photos. Authoritative ownership remains `peepso_photos.pho_owner_id` (re-checked on every write). |
| `alt_text` | VARCHAR(500) | Author-supplied alt text (post-sanitise: HTML stripped, whitespace collapsed). Hard cap 500 chars; A11y best practice is 125–150 — the cap blocks alt-stuffing without truncating descriptions of complex images. |
| `updated_at` | DATETIME | Server-set on every upsert (default `CURRENT_TIMESTAMP`). Lets future moderation queues sort by recency. |

**Primary Key:** `(pho_id)` — single row per photo

**Indexes:** `owner_id`, `updated_at`

**Invariants:**
- A row exists IFF the photo's author has set a non-empty alt. Clearing alt (PATCH with `""`) DELETEs the row so subsequent feed reads return `alt: null` (the §3.3.9 "decorative" fallback).
- `owner_id` is denormalised from PeepSo and not the source of truth — `peepso_photos.pho_owner_id` is. The write endpoint always re-checks against PeepSo before upserting to prevent a stale `owner_id` from being trusted across photo-ownership transfers (none in V1, but the check is cheap).

---

## bcc_user_ranks

BCC rank assignments per §E2. Apprentice + Journeyman are auto-assigned by reputation tier + activity thresholds; Foreman+ are admin-conferred. Revocations preserved as historical state via `revoked_at`.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `user_id` | BIGINT UNSIGNED | WordPress user ID |
| `rank_key` | VARCHAR(32) | `apprentice` / `journeyman` / `foreman` / etc. (named `rank_key` because RANK is reserved in MySQL 8.0+) |
| `awarded_by` | BIGINT UNSIGNED NULL | NULL for auto-derived; admin user ID for admin-conferred |
| `awarded_at` | DATETIME | When the rank was assigned |
| `revoked_at` | DATETIME NULL | NULL = active rank; non-NULL = historical |
| `revoke_reason` | VARCHAR(255) NULL | Optional admin-supplied reason |

**Indexes:** `(user_id, revoked_at)` for active-rank lookups, `rank_key`, `awarded_by`

**Invariant:** at most one row per `user_id` with `revoked_at IS NULL` — enforced at the application layer (MySQL has no partial unique indexes). On revocation, the user drops to their auto-derived rank, NOT to flat Apprentice (§E2 revocation rule).

---

## Locals (no dedicated table)

Per §E3, BCC Locals membership lives entirely in PeepSo's `peepso_group_members` (the single graph rule). The earlier dedicated `bcc_user_locals` table was removed as part of the §A4 anti-overengineering pass — it duplicated PeepSo's existing membership ledger.

The single piece of per-user state BCC stores about Locals:

- **`wp_usermeta.bcc_primary_local_group_id`** — integer pointer to the user's primary Local's `group_id` (or unset/0 for none). Singleton key per user.

Reads:
- *"Is user X in Local Y?"* → query `peepso_group_members WHERE gm_user_id=X AND gm_group_id=Y AND gm_user_status LIKE 'member%'`.
- *"What's user X's primary Local?"* → `get_user_meta(X, 'bcc_primary_local_group_id', true)`.
- *"What was user X's join date?"* → `peepso_group_members.gm_joined`.

Writes (when /me/locals/:id/join lands): use PeepSo's group-join API; toggle `bcc_primary_local_group_id` via `update_user_meta` for primary changes. App-layer enforces the single-primary invariant by virtue of the singleton meta key (only one value can be stored).

---

## bcc_onchain_claims (V1 extension: page claims + recovery flag)

Existing table from `bcc-onchain-signals` (now in `bcc-trust/Onchain`) — logs every claim attempt by every user. **V1 extension:** the `recovery_pending` column is added to support §B5's lost-wallet rule, and `entity_type='page'` is added to the value set for page claims (merged from the originally-planned dedicated `bcc_page_claims` table per the §A4 single-source consolidation).

| Column (existing + new) | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `user_id` | BIGINT UNSIGNED | Claiming user |
| `entity_type` | VARCHAR(20) | `validator` / `collection` / `page` (V1 adds `page`) |
| `entity_id` | BIGINT UNSIGNED | Per-type FK (`onchain_validators.id`, `onchain_collections.id`, or PeepSo page ID) |
| `wallet_address` | VARCHAR(128) | The signing wallet |
| `chain_id` | BIGINT UNSIGNED | FK to `bcc_onchain_chains` |
| `claim_role` | VARCHAR(20) | `operator` / `creator` / `holder` / `manager` |
| `status` | VARCHAR(20) | `pending` / `verified` |
| `verified_at` | DATETIME NULL | When the wallet signature was verified |
| `recovery_pending` | TINYINT(1) NOT NULL DEFAULT 0 | **NEW V1** — §B5 lost-wallet flag for page-type claims |
| `created_at` | DATETIME | Insert time |

**Existing indexes:** `uq_user_entity (user_id, entity_type, entity_id)` UNIQUE, `idx_entity (entity_type, entity_id)`, `idx_user (user_id)`, `idx_status (status)`

**New V1 index:** `idx_recovery_pending (recovery_pending)`

**Concurrency rules (§B5):**
- §B5 single-claim-wins for `entity_type='page'` is enforced by `ClaimRepository::createExclusiveClaim()` — an entity-scoped advisory lock serializes concurrent claim attempts on the same `(entity_type, entity_id, claim_role)`. Two simultaneous valid claims see exactly one acquire the lock and verify; the other is rejected with an "already claimed" error.
- Subsequent claim attempts on a claimed page must use the dispute / admin-override flow.
- The `recovery_pending` flag is raised by the wallet-disconnect / lost-wallet handler (per §B5) and consumed by the admin queue.
