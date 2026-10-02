# Data Model: Budgets (spec 015, B8)

## Budget — `budgets` (Budgeting, user scope, syncable)

| Column | Type | Rules |
|---|---|---|
| `id` | uuid PK | client UUIDv7 (P-06) |
| `user_id` | uuid | the owner; no FK (users belong to Identity, P-02) |
| `category_id` | uuid null | a live expense category, the owner's or the platform's, at creation; null = all expenses; immutable. No FK across modules; eligibility checked through Wallets' `BudgetCategoryCheck` under a share lock |
| `period_type` | varchar(8) | CHECK `period_type IN ('monthly')`; immutable |
| `amount_minor` | bigint | the limit; CHECK `amount_minor BETWEEN 1 AND 9007199254740991` |
| `currency` | char(3) | CHECK `currency ~ '^[A-Z]{3}$'`; ISO 4217 validated by `Currency`; immutable |
| `archived_at` | timestamptz(6) null | set once by archive |
| `version` | integer | CHECK ≥ 1 |
| `change_seq` | bigint | from the owner's user-scope counter |
| `created_at`, `updated_at` | timestamptz(6) | |
| `deleted_at` | timestamptz(6) null | tombstone |

Indexes: `budgets_user_change_seq_index (user_id, change_seq)`; `budgets_user_live_index (user_id, created_at, id) WHERE deleted_at IS NULL`; `budgets_category_index (category_id) WHERE category_id IS NOT NULL`.

**States**: `active` (archived_at null) → `archived`; either → tombstoned (deleted_at set). No restore.

**Wire (Budget)**: `id`, `user_id`, `category_id`, `period_type`, `amount_minor`, `currency`, `archived_at`, `version`, `change_seq`, `created_at`, `updated_at`, `deleted_at`.

## Budget start day history — `budget_start_days` (Identity, server-internal)

| Column | Type | Rules |
|---|---|---|
| `id` | uuid PK | server UUIDv7 |
| `user_id` | uuid FK users, cascade on delete | |
| `start_day` | smallint | CHECK 1–28 |
| `applies_from` | date null | null only for the first entry |
| `created_at` | timestamptz(6) | |

Uniques: (`user_id`, `applies_from`); partial unique (`user_id`) WHERE `applies_from IS NULL`.

Written only by `SaveProfile` in the profile's transaction: when the day changes, if the person has no entry yet insert (the day in force before the save, null); delete entries with `applies_from` > the person's today (pending changes that never took effect); then insert (`new day`, `budget_period_start_day_from`). A person with no entry has one implicit entry (profile's current day, null), so registration writes nothing. Backfill migration: for each profile with `…_from` set, (1, null) and (day, `…_from`); nothing for the others.

## Budget Period (Budgeting, derived value object)

`BudgetPeriod { start: date, end: date }`, with `daysInPeriod()`, `contains(date)`, `daysElapsed(today)` (today − start + 1 when contained). Built by `BudgetPeriods::containing(date, list<StartDayEntry>)` (research R-3). Never stored.

## Budget Progress (Budgeting, derived read model)

`BudgetProgress { budgetId, period, isCurrent, limit: Money, spent: Money, remaining: Money, projectedEnd: ?Money, overLimit: bool, otherCurrencies: list<string> }`, from the budget, the period, the spending lines and today. Never stored.

## Spending line (Wallets application DTO)

`SpendingTotal { categoryId: string, currency: string, amountMinor: int }` — one per (category, currency) of the owner's in-period live expenses in wallets they belong to (plan design note 3).

## Wallets index

`transactions_expense_author_date_index ON transactions (created_by, date) WHERE deleted_at IS NULL AND kind = 'expense'`.
