# Data Model: Reports (spec 019, B13)

Reports persist nothing (glossary: Report is "a query, never a row"). This file lists what they read, the derived values they produce, and the one index added.

## Read (owned elsewhere, through Application query services only — P-02)

| Record | Owner | Read through | Fields used |
|---|---|---|---|
| Wallet | Wallets | `Wallets\Application\Reports\LedgerReportQuery` | `id`, `currency`, `opening_balance_minor`, `deleted_at` (never `balance_minor`, P-05) |
| Wallet Member | Wallets | same | `wallet_id`, `user_id`, `deleted_at` (live membership = coverage and read access) |
| Transaction | Wallets | same | `id`, `wallet_id`, `counter_wallet_id`, `kind`, `date`, `deleted_at` |
| Posting | Wallets | same (sums only; never exposed, P-04 rule 6) | `transaction_id`, `wallet_id`, `category_id`, `amount_minor`, `currency` |
| Category | Wallets | same (joined for `system_key`) | `id`, `system_key` |
| Occurrence | Wallets | same (`scheduledTotals`) | `wallet_id`, `counter_wallet_id`, `kind`, `status`, `date`, `amount_minor`, `currency`, `category_id`, `deleted_at` |
| Budget start day history, timezone | Identity via Budgeting | `Budgeting\Application\BudgetPeriodQuery` | Budget Periods, today |
| Profile | Identity | `Identity\Application\Profiles\ReportingPreferences` | `default_currency` |

## Derived values (Reporting, never stored)

**Report Range** — `{from, to}`, inclusive calendar dates, `from ≤ to`, span ≤ 366 days (explicit) or exactly one Budget Period (default). Domain value object `Reporting\Domain\ReportRange`.

**Covered wallets** — for a cross-wallet report: live wallets with a live membership of the caller, any role, archived included, whose `currency` equals the report's currency. **Excluded wallets** — the same set in any other currency, returned as `{wallet_id, currency}` sorted by `wallet_id`.

**Summary** — `wallet_id` (null across wallets), `range`, `currency`, `income`, `expenses`, `net`, `transfers_in`, `transfers_out`, `balance_start`, `balance_end`, `planned` (null or `{income, expenses, transfers_in, transfers_out}`).
- Invariant: `balance_end = balance_start + income − expenses + transfers_in − transfers_out` (actual figures only).
- `income`, `expenses`, `transfers_*` ≥ 0; `net` and balances signed.

**By-category** — `wallet_id`, `range`, `currency`, `expenses` and `income`, each `{total, planned, items[]}`; item `{label, category_id, system_key, value, planned}`.
- `label` = `system_key` for a platform category, else the category id.
- `total` = Σ items' `value`; `planned` (group) = Σ items' `planned` or null.
- An item exists when its actual or (with `include_planned`) planned sum is non-zero.
- Sorted by `value.amount_minor` desc, then `label` asc.

**Trend** — `wallet_id`, `currency`, `periods [{label, from, to}]` (1–12 Budget Periods, oldest first, `label` = `from`), series `income`, `expenses`, `net` (`[{label, value}]`, one per period), `planned_income`, `planned_expenses` (series or null).

All money is `{amount_minor, currency}` built with `Shared\Domain\Money\Money` (P-03): `plus`, `minus`, `negate` only; no division, no rounding (ADR-0003 rule 7).

## Index added (Wallets)

| Index | Table | Definition | Why |
|---|---|---|---|
| `postings_wallet_leg_index` | `postings` | `(wallet_id, transaction_id) INCLUDE (amount_minor) WHERE wallet_id IS NOT NULL` | Wallet-leg sums for summary, balances and trend read the legs from the index (research R-3) |

Migration: `app/Modules/Wallets/Infrastructure/Database/Migrations/2026_10_08_000001_add_wallet_leg_index_to_postings.php`. No column, table, constraint or money type added.

## State transitions

None. Reports have no lifecycle.
