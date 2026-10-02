# Quickstart: Budgets

**Feature**: [spec.md](./spec.md) · **Contract**: [contracts/budgets-api.md](./contracts/budgets-api.md)

Validation guide for after implementation. Everything runs in the container (host PHP has no `pdo_pgsql`).

## Prerequisites

```bash
dev project open
dev migrate
dev db:seed            # platform categories (spec 011)
```

A verified user with an EGP wallet and a bearer token (see spec 012's quickstart); headers `X-Client-Platform: android`, `X-Client-Version: 1.0.0` on every request.

## Gates

```bash
dev exec -- vendor/bin/pest tests/Contract/OpenApi          # contract skeleton, v1.8 indexed
dev exec -- vendor/bin/pest tests/Unit/Budgeting
dev exec -- vendor/bin/pest tests/Feature/Budgeting tests/Feature/Identity/BudgetStartDayHistoryTest.php tests/Feature/Sync/BudgetPurgeTest.php
dev exec -- vendor/bin/pest tests/Contract/Budgeting tests/Contract/Sync
dev exec -- composer test                                   # whole gate before a PR
```

## Scenarios

1. **Create** — `PUT /api/v1/budgets/{new uuidv7}` with the `createGroceries` example (platform Groceries id from `GET /platform-categories`) → `201`. Replay → `200`, version unchanged.
2. **Progress moves** — record expenses of 100000 and 250000 in the current period (`PUT /wallets/{w}/transactions/{id}`, kind expense, Groceries) → `GET /budget-progress`: `spent` 350000, `remaining` 250000, `over_limit` false, `projected_end` = ceil(350000 × days_in_period ÷ days_elapsed). Add 300000 → `spent` 650000, `remaining` −50000, `over_limit` true.
3. **Exclusions** — a transfer, an income, a USD expense (USD wallet) and a deleted expense leave `spent` unchanged; `other_currencies` = `["USD"]`.
4. **Re-date** — move an expense to last period: current `spent` drops; `GET /budget-progress?date=<last period day>` includes it.
5. **Shared wallet** — a second member records Groceries in the shared wallet: the first member's `spent` unchanged.
6. **Start day** — `PUT /me/profile` with `budget_period_start_day: 25` (note `budget_period_start_day_from`); progress for today keeps the old start; progress for a date on or after `…_from` starts on the 25th.
7. **Edits** — `PATCH /budgets/{id}` `{amount_minor: 700000, version: 1}` → `200`; resend with version 1 → `409` with `current`; `PUT` with another `currency` → `422 IMMUTABLE_FIELD /currency`.
8. **Archive, delete** — archive → leaves `/budget-progress`, stays in `/budgets`; delete → tombstone; `GET /budgets/{id}/progress` → `404`.
9. **Category in use** — `DELETE /categories/{own budgeted category}` → `409 CATEGORY_IN_USE`.
10. **Sync** — `GET /sync/user/changes` shows `budget` rows; `POST /sync/batch` with a `budget` upsert and a `budget` delete returns results equal to the single writes; no `budget` row on any wallet feed.
