# Data model: Transactions Ledger

**Feature**: [spec.md](./spec.md) · Owner module: `app/Modules/Wallets` (context Wallets & Ledger). PostgreSQL 16. No float, double, real or decimal column (P-03).

## `transactions` (syncable, wallet scope)

| Column | Type | Rules |
|---|---|---|
| `id` | uuid pk | client UUIDv7 (P-06) |
| `wallet_id` | uuid not null fk → wallets | scope key; the source of a transfer; never changes |
| `kind` | varchar, CHECK in (`income`,`expense`,`transfer`) | immutable |
| `date` | date not null | 1900-01-01 – 2999-12-31 (CHECK) |
| `amount_minor` | bigint not null | CHECK `> 0` and `<= 9007199254740991` |
| `currency` | char(3) not null | equals the wallet's (both wallets' for a transfer) |
| `category_id` | uuid null fk → categories | CHECK: null ⇔ `kind = transfer` |
| `counter_wallet_id` | uuid null fk → wallets | CHECK: not null ⇔ `kind = transfer`; CHECK `<> wallet_id`; immutable |
| `description` | varchar(100) null | trimmed; blank → null |
| `memo` | varchar(1000) null | trimmed; blank → null |
| `counterparty` | varchar(100) null | trimmed; blank → null |
| `cleared` | boolean not null default false | |
| `created_by` | uuid not null | the recorder (Identity user id; no cross-module fk) |
| `version` | integer not null | starts at 1 |
| `change_seq` | bigint not null | from `wallet_id`'s counter |
| `counter_change_seq` | bigint null | transfers only, from `counter_wallet_id`'s counter; never on the wire (ADR-0015) |
| `created_at`, `updated_at` | timestamptz(6) | UTC |
| `deleted_at` | timestamptz(6) null | tombstone |

Indexes: `(wallet_id, date DESC, id)`; `(wallet_id, created_at, id)`; partial `(counter_wallet_id, date DESC, id) WHERE counter_wallet_id IS NOT NULL`; `(wallet_id, change_seq)`; partial `(counter_wallet_id, counter_change_seq) WHERE counter_wallet_id IS NOT NULL` (for B14); `(category_id)`; partial `(created_by) WHERE deleted_at IS NULL`.

**States**: `live` → `live (v+1)` (edit) → `tombstoned` (delete) → `live (v+1)` (restore). A tombstone is purged after 90 days by B14's retention (ADR-0008 rule 12).

## `postings` (never exposed, never synced)

| Column | Type | Rules |
|---|---|---|
| `id` | uuid pk | server UUIDv7 |
| `transaction_id` | uuid not null fk → transactions (cascade on purge only) | |
| `wallet_id` | uuid null fk → wallets | exactly one of `wallet_id`, `category_id` (CHECK) |
| `category_id` | uuid null fk → categories | |
| `amount_minor` | bigint not null | signed, CHECK `<> 0` |
| `currency` | char(3) not null | equals the transaction's |

Indexes: `(wallet_id)`, `(category_id)`, `(transaction_id)`.

**Lifecycle**: replaced wholesale on every edit; removed on delete; re-derived on restore. Each live transaction has exactly two, summing to zero: `PostingDerivation` asserts it on every write, and `LedgerIntegrityTest` scans the table after every scenario. A tombstoned transaction has none.

| Kind | Posting 1 | Posting 2 |
|---|---|---|
| expense | wallet `wallet_id`, −amount | category, +amount |
| income | category, −amount | wallet `wallet_id`, +amount |
| transfer | wallet `wallet_id`, −amount | wallet `counter_wallet_id`, +amount |

## Changed, not new

- `wallets.balance_minor`: now the real `opening_balance_minor + Σ postings` (spec 010 cache).
- Wallet `currency_locked`: `EXISTS transactions WHERE wallet_id = W OR counter_wallet_id = W` (tombstones included).
- Category `in_use`: `EXISTS transactions WHERE category_id = C` (tombstones included), set-wise for a page.
- `category_references`: published and withdrawn by transaction writes (spec 011).
- Profile `default_currency_locked`: `EXISTS transactions WHERE created_by = U AND deleted_at IS NULL`.

## Domain types (no framework)

- `TransactionKind` enum; `TransactionRecord` (readonly); `PostingLine` (wallet or category, signed Money); `PostingDerivation`; `TransactionRules` (immutables, kind/category/counter shape); refusals `TransactionNotFound`, `TransactionVersionConflict`, `TransactionStateInvalid` (one 422 carrying every state-rule field error); events `TransactionPosted`, `TransactionVoided`.
- Ports: `TransactionRepository`, `PostingRepository`; existing `PostingTotals`, `ScopeSequence`, `WalletRepository`, `CategoryRepository`.
