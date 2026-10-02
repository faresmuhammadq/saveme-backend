# Data Model: Sharing & Invitations (spec 016)

PostgreSQL 16. UUIDv7 keys. Instants `timestamptz(6)` in UTC. No float/decimal columns.

## `invitations` — Sharing (`app/Modules/Sharing/Infrastructure/Database/Migrations`)

| Column | Type | Rule |
|---|---|---|
| `id` | uuid PK | client-generated UUIDv7 |
| `wallet_id` | uuid FK → `wallets.id` | scope key |
| `invited_by` | uuid FK → `users.id` | the owner who sent it |
| `role` | varchar(16) | CHECK in (`editor`, `viewer`) |
| `channel` | varchar(8) | CHECK in (`email`, `link`) |
| `address_sealed` | text null | address sealed under the inviter's data key; null for `link` and once closed |
| `address_lookup` | varchar(64) null | HMAC lookup value; null for `link` and once closed |
| `token_hash` | char(64) | SHA-256 of the secret; unique |
| `status` | varchar(16) | CHECK in (`pending`, `accepted`, `declined`, `revoked`, `expired`) |
| `expires_at` | timestamptz | `created_at` + 7 days |
| `responder_user_id` | uuid null | who accepted or declined (internal; idempotent replies) |
| `version` | integer | ≥ 1 |
| `change_seq` | bigint | from the wallet counter |
| `created_at`, `updated_at` | timestamptz | |
| `deleted_at` | timestamptz null | set exactly when `status` ≠ `pending` |

Constraints and indexes:

- CHECK `(status = 'pending') = (deleted_at IS NULL)`.
- CHECK `channel = 'link'` ⇒ `address_sealed IS NULL AND address_lookup IS NULL`; CHECK `deleted_at IS NOT NULL` ⇒ both null.
- UNIQUE `token_hash`.
- UNIQUE `(wallet_id, address_lookup) WHERE deleted_at IS NULL AND address_lookup IS NOT NULL` (one pending per address per wallet).
- INDEX `(wallet_id, change_seq)` (feed); INDEX `(wallet_id, created_at, id) WHERE deleted_at IS NULL` (list, quota count); INDEX `(expires_at) WHERE deleted_at IS NULL` (sweep); INDEX `(deleted_at) WHERE deleted_at IS NOT NULL` (purge).

State machine: `pending` → `accepted` | `declined` | `revoked` | `expired`; each transition sets `deleted_at`, `version + 1`, its own `change_seq`, erases `address_sealed` and `address_lookup`. No transition out of a closed state.

Wire mapping: `invitee_email` = opened `address_sealed` (pending email invitations only), else null. `token_hash`, `address_lookup`, `responder_user_id` are never on the wire.

## `wallet_members` — Wallets (spec 010; behaviour extended, no column change)

- Grant (accept): insert, role from the invitation, `version` 1, `change_seq` from the wallet counter, `member_change_seq` from the member's counter.
- Role change and demotion: `changeRole` (existing port).
- Removal and leave: new port method `tombstone(memberId, at, changeSeq, memberChangeSeq)` → `deleted_at`, `version + 1`. Glossary state `revoked`.
- New reads: `latestFor(walletId, userId)` (the newest row, live or tombstone, for replays of removal), `activeRanked(walletId)` (owner, then `created_at`, `id`), `countActive(walletId)`, `ownedLiveWalletsOf(userId)`.

## `wallet_activity` — Wallets & Ledger (new)

| Column | Type | Rule |
|---|---|---|
| `id` | uuid PK | server UUIDv7 |
| `wallet_id` | uuid FK → `wallets.id` ON DELETE CASCADE | the wallet purge may remove a wallet row the same day its last entries age out |
| `occurred_at` | timestamptz | the write's instant |
| `actor_user_id` | uuid null | null = the server |
| `action` | varchar(32) | CHECK in the 15 actions of the contract |
| `subject_type` | varchar(16) | CHECK in (`wallet`, `transaction`, `recurring_rule`, `occurrence`, `member`, `invitation`) |
| `subject_id` | uuid | |
| `role` | varchar(8) null | CHECK in (`owner`, `editor`, `viewer`) |

INDEX `(wallet_id, occurred_at DESC, id DESC)`; INDEX `(occurred_at)` (prune). Append-only: no update path in the repository. Not syncable (no `version`, `change_seq`, `deleted_at`). Removed after 90 days by the daily purge run.

## Configuration

`config/sharing.php`: `links.invitation_url` (env `SHARING_INVITATION_URL`, default `http://saveme-backend.test/invite`), `invitation_days` 7, `throttle.invites_per_day` 20, `throttle.mails_per_day_per_address` 5, `throttle.token_per_hour` 30, `activity_retention_days` 90 (clamped to ≥ 90 like the tombstone retention).

## Lock order (ADR-0014 rule 10, ADR-0017 rule 5)

wallet row (`FOR UPDATE`) → invitation row → user rows ascending (`member_change_seq`) → membership rows. Acceptance: invitation located by token hash unlocked → wallet lock → invitation `FOR UPDATE` re-read (status, expiry) → quota → member's user lock → insert membership → close invitation → wallet final stamp → activity → audit.
