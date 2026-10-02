# Data Model: Recurring Transactions

**Feature**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Date**: 2026-10-02

Owned by `app/Modules/Wallets`; migrations in `app/Modules/Wallets/Infrastructure/Database/Migrations`. PostgreSQL 16 only. No float, double, real or decimal column (P-03). Calendar dates are `date`; instants `timestamptz(6)` UTC (ADR-0011).

## recurring_rules (syncable, wallet scope)

| Column | Type | Rule |
|---|---|---|
| `id` | uuid pk | client UUIDv7 |
| `wallet_id` | uuid fk wallets | scope key; the source for a transfer |
| `preset` | varchar(16) | CHECK in (`custom`, `gameya`, `installment`); immutable |
| `status` | varchar(8) | CHECK in (`active`, `paused`, `ended`) |
| `kind` | varchar(8) | CHECK in (`income`, `expense`, `transfer`); immutable |
| `amount_minor` | bigint | CHECK 1 … 9007199254740991 |
| `amount_basis` | varchar(16) | CHECK in (`per_occurrence`, `total`) |
| `currency` | char(3) | the wallet's; immutable |
| `category_id` | uuid null fk categories | CHECK null ⇔ kind = transfer |
| `counter_wallet_id` | uuid null fk wallets | CHECK not null ⇔ kind = transfer; ≠ `wallet_id`; immutable |
| `description` | varchar(100) null | trimmed; blank → null |
| `memo` | varchar(1000) null | |
| `counterparty` | varchar(100) null | |
| `auto_confirm` | boolean | |
| `auto_confirm_from` | date | internal, never on the wire (research R-11) |
| `frequency` | varchar(8) | CHECK in (`daily`, `weekly`, `monthly`, `yearly`) |
| `interval` | smallint | CHECK 1 … 365 |
| `anchor_day` | smallint null | CHECK 1 … 31; CHECK null ⇔ frequency in (daily, weekly) |
| `start_date` | date | CHECK 1900-01-01 … 2999-12-31 |
| `end_date` | date null | CHECK ≥ `start_date`, ≤ 2999-12-31 |
| `occurrence_count` | integer null | CHECK 1 … 1000; CHECK not both `end_date` and `occurrence_count` |
| `payout_round` | integer null | CHECK null unless preset = gameya; 1 … `occurrence_count` |
| `payout_category_id` | uuid null fk categories | CHECK null unless preset = gameya |
| `timezone` | varchar(64) | IANA, from the author's profile at creation; immutable |
| `created_by` | uuid | author; never changes |
| `version` | integer | server-incremented |
| `change_seq` | bigint | wallet counter, stamped under the wallet lock |
| `created_at`, `updated_at` | timestamptz(6) | |
| `deleted_at` | timestamptz(6) null | tombstone; final (no restore) |

Indexes: `(wallet_id, change_seq)`; `(wallet_id, created_at, id) WHERE deleted_at IS NULL`; `(wallet_id, start_date, id) WHERE deleted_at IS NULL`; `(id) WHERE deleted_at IS NULL AND status <> 'ended'` for the job; `(category_id)`, `(payout_category_id)` for usage.

**States**: `active` ⇄ `paused`; `active` | `paused` → `ended` (final); any → tombstoned (final).

## occurrences (syncable, wallet scope, server-created)

| Column | Type | Rule |
|---|---|---|
| `id` | uuid pk | server UUIDv7 |
| `wallet_id` | uuid fk wallets | = the rule's |
| `recurring_rule_id` | uuid fk recurring_rules | |
| `ordinal` | integer | ≥ 1; the round |
| `is_payout` | boolean | true only for a gam'eya payout |
| `scheduled_date` | date | from the schedule; never changes |
| `date` | date | effective; CHECK 1900–2999 |
| `status` | varchar(9) | CHECK in (`scheduled`, `confirmed`, `skipped`) |
| `kind` | varchar(8) | snapshot from the rule (income for a payout) |
| `amount_minor` | bigint | CHECK 1 … 2^53 − 1 |
| `currency` | char(3) | |
| `category_id` | uuid null fk categories | |
| `counter_wallet_id` | uuid null fk wallets | |
| `description`, `memo`, `counterparty` | varchar null | as the rule |
| `edited` | boolean | values differ from the rule-derived ones |
| `transaction_id` | uuid null | CHECK not null ⇔ status = confirmed |
| `auto_confirm_refusal` | varchar(32) null | CHECK in (`not_permitted`, `counter_wallet_unavailable`, `currency_mismatch`) |
| `version`, `change_seq`, `created_at`, `updated_at`, `deleted_at` | | syncable envelope |

Indexes: **unique** `(recurring_rule_id, scheduled_date, is_payout)` (the occurrence key, all rows); **unique** `(transaction_id) WHERE transaction_id IS NOT NULL`; `(wallet_id, change_seq)`; `(wallet_id, date, id) WHERE deleted_at IS NULL`; `(recurring_rule_id, status) WHERE deleted_at IS NULL`; `(category_id)`.

No foreign key from `transaction_id` to `transactions` (the transaction is inserted in the same unit of work, after the occurrence row is locked; the link is enforced by the uniques on both sides and by `ConfirmOccurrenceTest`). `transactions.occurrence_id` does carry a foreign key to `occurrences`.

**States**: `scheduled` → `confirmed` | `skipped`; `skipped` → `scheduled` | `confirmed`; `confirmed` is final; any → tombstoned (rule deleted, rule ended past today, series shortened, schedule recomputed before any resolution, wallet deleted). Derived for readers: **due** = `scheduled` and `date` ≤ the rule's today; **missed** = due while a later non-payout occurrence of the same rule is also due.

## transactions (changed)

| Column | Type | Rule |
|---|---|---|
| `recurring_rule_id` | uuid null fk recurring_rules | read-only; set only through `recordForOccurrence` |
| `occurrence_id` | uuid null fk occurrences | read-only; **unique** where not null; CHECK both null or both set |

## Relationships

- Wallet 1 — n Recurring Rule (by `wallet_id`); a transfer rule also references its destination wallet.
- Recurring Rule 1 — n Occurrence.
- Occurrence 0..1 — 0..1 Transaction (`occurrences.transaction_id` ↔ `transactions.occurrence_id`).
- Category 1 — n Recurring Rule (`category_id`, `payout_category_id`), 1 — n Occurrence (in use, set-wise).

## Validation summary (domain `RecurringRuleRules`, `OccurrenceRules`)

- Presets: gameya ⇒ monthly, interval 1, expense, count 2–120, no end date, payout round ∈ 1..count, income payout category; installment ⇒ monthly, interval 1, expense or transfer, count 2–120, no end date, no payout; custom ⇒ no payout.
- `amount_basis = total` ⇒ count required and `amount_minor ≥ occurrence_count`.
- Gam'eya ⇒ `payout_round ≤ occurrence_count`; for gam'eya and `total` rules `occurrence_count` and `amount_minor` are frozen after the first resolution.
- Frozen after the first resolved occurrence: frequency, interval, anchor_day, start_date, amount_basis, payout_round; always immutable: preset, kind, currency, counter_wallet_id; ended ⇒ every member immutable.
- An end or count that excludes a resolved occurrence ⇒ `OUT_OF_RANGE`.
- Occurrence: confirmed ⇒ every member immutable; `transaction_id` required exactly on the transition to confirmed; transitions as listed above.
