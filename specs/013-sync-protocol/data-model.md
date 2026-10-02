# Data Model: Sync Protocol (spec 013, B14)

**Feature**: [spec.md](./spec.md) · **Research**: [research.md](./research.md)

No new table and no new syncable entity. Sync owns protocol values (cursor, batch, purge orchestration); every column lives with the module that owns its table (P-02). PostgreSQL 16; `bigint` counters; UTC `timestamptz(6)`.

## Column changes

### `users` (Identity) — the user scope row

| Column | Type | Rules |
|---|---|---|
| `change_seq` | bigint not null default 0 | **the user scope counter** (ADR-0008 rule 2). Incremented only by `UPDATE … SET change_seq = change_seq + 1 … RETURNING`, which takes the row lock until commit (R-5). Backfilled to the greatest stamp already in the person's scope (FR-021). |
| `purged_change_seq` | bigint not null default 0 | the user scope's **purge horizon**: highest user-feed position whose tombstone was purged (R-10). Raised only by the purge, under the row lock. |

Migration: `app/Modules/Identity/Infrastructure/Database/Migrations/2026_10_04_000001_add_sync_counters_to_users.php` adds both columns and the indexes `profiles (user_id, change_seq)` and `device_sessions (user_id, change_seq)`. The backfill is the Sync switch migration below.

### `wallets` (Wallets) — the wallet scope row

| Column | Type | Rules |
|---|---|---|
| `purged_change_seq` | bigint not null default 0 | the wallet scope's purge horizon (R-10), raised by the purge under the wallet lock — including by transfers into this wallet (`counter_change_seq`) |

(`wallets.change_seq` is already the wallet scope counter, spec 010 R-3; unchanged.)

### `wallet_members` (Wallets)

| Column | Type | Rules |
|---|---|---|
| `member_change_seq` | bigint not null (after backfill) | the membership's position on its **member's user feed** (ADR-0017); stamped from the member's user counter on create, role change and tombstone, in the same unit of work, after the wallet lock; never on the wire |

Index: `wallet_members_user_member_change_seq_index (user_id, member_change_seq)`.

Migration: `app/Modules/Wallets/Infrastructure/Database/Migrations/2026_10_04_000002_add_sync_columns.php` adds `wallets.purged_change_seq`, `wallet_members.member_change_seq` (nullable at first), the index above, and `categories (user_id, change_seq) WHERE user_id IS NOT NULL`.

### The switch migration (Sync)

`app/Modules/Sync/Infrastructure/Database/Migrations/2026_10_04_000003_switch_user_scope_counter.php`, one transaction, with `users` locked `SHARE ROW EXCLUSIVE` so no user-scope write runs during it:

1. per user, `base` = greatest `change_seq` over their profile, device sessions and own categories (0 if none);
2. `wallet_members.member_change_seq = base + row_number() OVER (PARTITION BY user_id ORDER BY created_at, id)` for every membership, live or tombstoned;
3. `users.change_seq` = greatest of `base` and the user's highest `member_change_seq`;
4. `wallet_members.member_change_seq` set NOT NULL.

Plain SQL over the tables of two modules, in a migration only: it is a one-off data move owned by Sync, not runtime code (P-02 governs namespaces; no class of another module is referenced). `2026_10_04_000004_drop_sync_change_seq.php` (Identity) then drops the sequence.

### Retired

| Object | Owner | Fate |
|---|---|---|
| sequence `sync_change_seq` | Identity | dropped (spec 007 R-19 ends); `PostgresChangeSequence` replaced by `UserRowChangeSequence`, Wallets' `UserScopeSequence` delegates to Identity's `UserScopeCounter`; factories stamp through the counter |

## Feed positions (read model, never stored)

| Feed | Record type on the wire | Selected by | Position |
|---|---|---|---|
| user U | `profile` | `profiles.user_id = U` | `change_seq` |
| user U | `device_session` | `device_sessions.user_id = U` | `change_seq` |
| user U | `category` | `categories.user_id = U` (never platform rows) | `change_seq` |
| user U | `wallet_member` | `wallet_members.user_id = U` | `member_change_seq` |
| wallet W | `wallet` | `wallets.id = W` | `change_seq` |
| wallet W | `wallet_member` | `wallet_members.wallet_id = W` | `change_seq` |
| wallet W | `category_reference` | `category_references.wallet_id = W` | `change_seq` |
| wallet W | `transaction` | `transactions.wallet_id = W` | `change_seq` |
| wallet W | `transaction` | `transactions.counter_wallet_id = W` | `counter_change_seq` |

All positions of one feed come from the same counter (wallet W's, or user U's), stamped under that row's lock, so the merged order is commit order.

## Protocol values (Sync module, `app/Modules/Sync/Domain`)

### Sync Cursor (client-held)

`{ v: 1, f: "u" | "w", s: <scope id>, r: <reader id>, p: <position> }`, base64url + truncated HMAC-SHA256 (R-9). Valid when signature, version, feed kind, scope and reader match and `0 ≤ p ≤ counter`. Expired when `p < purged_change_seq`. Never stored server-side.

### Change Row

`{ type, record }` — `type` ∈ the table above; `record` is the resource the record's read endpoint returns for the reader.

### Sync Batch / Batch Item / Batch Item Result

| Value | Members | Rules |
|---|---|---|
| Sync Batch | `items` | 1–50 |
| Batch Item | `type`, `op`, `id`, `wallet_id`?, `data` | see the type/op map below; `data` is the single write's body |
| Batch Item Result | `type`, `id`, `outcome`, `status`, `record`, `problem` | `outcome` from `status`: 200/201 → `applied`; 409 with `VERSION_CONFLICT` → `conflict`; anything else → `rejected` |

Type/op map (each op is one per-record write):

| `type` | `upsert` | `delete` | `restore` |
|---|---|---|---|
| `wallet` | `PUT /wallets/{id}` | `DELETE /wallets/{id}` | — |
| `category` | `PUT /categories/{id}` | `DELETE /categories/{id}` | — |
| `transaction` | `PUT /wallets/{wallet_id}/transactions/{id}` | `DELETE /wallets/{wallet_id}/transactions/{id}` | `PATCH /wallets/{wallet_id}/transactions/{id}` with `{deleted_at: null, version}` |
| `profile` | `PUT /me/profile` (item `id` must be the caller's profile id, else `404`) | — | — |

### Purge Horizon

Per scope row, `purged_change_seq` (above). Raised monotonically; never lowered.

## Lifecycle effects

| Record | Purged when | Horizon raised on |
|---|---|---|
| Transaction tombstone | `deleted_at` older than retention | source wallet (`change_seq`); destination wallet for a transfer (`counter_change_seq`) |
| Category Reference tombstone | as above | its wallet |
| Wallet Member tombstone | as above | its wallet (`change_seq`) and its member's user scope (`member_change_seq`) |
| Person's Category tombstone | as above and nothing refers to it | its owner's user scope |
| Wallet tombstone | as above and no transaction, posting, reference or membership row refers to it | — (the wallet row is gone; its feed becomes `404`) |
| Profile, Device Session | not tombstoned by any current flow; purged by the same rule if ever tombstoned | its user scope |

## Reference data

Seeder: N/A — no reference data.
