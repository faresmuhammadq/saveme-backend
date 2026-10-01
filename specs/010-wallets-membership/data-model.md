# Data Model: Wallets & Membership

**Feature**: [spec.md](./spec.md) · **Research**: [research.md](./research.md)

Owned by `app/Modules/Wallets`. Migrations live in `app/Modules/Wallets/Infrastructure/Database/Migrations`. PostgreSQL 16, UUIDv7 keys (P-06), UTC `timestamptz(6)` (P-12), money as `bigint` minor units (P-03; the migration gate refuses float/decimal).

## `wallets`: Wallet (syncable, the wallet scope)

| Column | Type | Rules |
|---|---|---|
| `id` | uuid PK | client-generated UUIDv7 (validated v7 at the edge) |
| `type` | varchar(12) + CHECK | `cash` \| `checking` \| `savings` \| `credit_card` \| `loan` \| `investment`; never updated after insert |
| `name` | varchar(100) | trimmed, non-blank (CHECK `btrim(name) <> ''`) |
| `currency` | char(3) + CHECK `~ '^[A-Z]{3}$'` | validated by `Shared\Domain\Money\Currency`; immutable once `PostingTotals::hasAny` |
| `opening_balance_minor` | bigint | any sign |
| `balance_minor` | bigint | **cache** = `opening_balance_minor + Σ postings`; written only under the row lock in the same transaction (R-2) |
| `icon` | varchar(40) null | `^[a-z0-9_]{1,40}$` |
| `color` | char(7) null | `^#[0-9A-F]{6}$` |
| `archived_at` | timestamptz(6) null | set once (R-5) |
| `version` | integer ≥ 1 | +1 on each change to the wallet's own fields, archive, delete, transfer |
| `change_seq` | bigint ≥ 1 | **the wallet scope counter** (R-3); also this record's own stamp — always the last, unique value of any write in the wallet |
| `created_at`, `updated_at` | timestamptz(6) | |
| `deleted_at` | timestamptz(6) null | tombstone; never a hard delete |


**Lifecycle**: `active` → `archived` (sets `archived_at`); `active` | `archived` → `tombstoned` (sets `deleted_at`). No way back in this feature.

## `wallet_members`: Wallet Member (syncable, wallet scope)

| Column | Type | Rules |
|---|---|---|
| `id` | uuid PK | server-generated UUIDv7 (`HasUuids`) |
| `wallet_id` | uuid FK → `wallets` | the scope key |
| `user_id` | uuid | the member (no FK across modules); exposed as `member_user_id` |
| `role` | varchar(8) + CHECK | `owner` \| `editor` \| `viewer` |
| `version` | integer ≥ 1 | +1 on role change or tombstone |
| `change_seq` | bigint | a unique value from the wallet's counter, below the wallet's own |
| `created_at`, `updated_at` | timestamptz(6) | |
| `deleted_at` | timestamptz(6) null | tombstoned with the wallet (FR-010); B9 adds revocation |

Indexes:

- `UNIQUE (wallet_id, user_id) WHERE deleted_at IS NULL` — one active membership per person per wallet.
- `UNIQUE (wallet_id) WHERE role = 'owner' AND deleted_at IS NULL` — at most one active owner (at least one is proven by tests, FR-015).
- `(user_id) WHERE deleted_at IS NULL` — the wallet list.
- `(wallet_id, change_seq)` — the future wallet feed (B14).

**Lifecycle**: `active` (role may change) → tombstoned.

## Derived, never stored

| Member | Source |
|---|---|
| `currency_locked` | `PostingTotals::hasAny(walletId)` (provisional `false` until B6) |
| `my_role` | the caller's active membership role |
| `display_name` (member) | `Identity\Application\Profiles\DisplayNames` |

## Domain types (`app/Modules/Wallets/Domain`)

- `Wallets\WalletType` (enum, with `isLiability()` for `credit_card`, `loan`), `Wallets\WalletRecord` (readonly), `Wallets\WalletRules` (immutability checks: type, currency-when-locked).
- `Membership\Role`, `Membership\Permission` (one case per matrix row), `Membership\PermissionMatrix`.
- `Ports\WalletRepository`, `Ports\MembershipRepository`, `Ports\PostingTotals`, `Ports\ScopeSequence`.
- `Events\WalletCreated`, `Events\WalletDeleted`, `Events\WalletOwnershipTransferred`.
- `Refusals\WalletNotFound`, `WalletActionForbidden`, `WalletVersionConflict`, `WalletFieldImmutable`, `OwnershipTargetInvalid` (each mapping to a catalogue code).

## Reference data

Seeder: N/A — no reference data (wallet types are an enum, not rows).
