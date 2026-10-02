# Contract: Budgets API (v1.8)

**Source of truth**: [`docs/openapi/paths/budgets.yaml`](../../../docs/openapi/paths/budgets.yaml), plus the additive v1.8 changes in [`sync.yaml`](../../../docs/openapi/paths/sync.yaml) (user-feed type `budget`; batch type `budget` with `upsert` and `delete`). Where this summary and the YAML differ, the YAML wins. Frozen for the C8 client.

## Operations

| Method | Path | operationId | Who | Success |
|---|---|---|---|---|
| GET | `/budgets` | `listBudgets` | owner | 200 collection (sort `created_at`; no filters) |
| GET | `/budgets/{id}` | `getBudget` | owner | 200 (tombstone included) |
| PUT | `/budgets/{id}` | `upsertBudget` | owner | 201 create / 200 save or replay |
| PATCH | `/budgets/{id}` | `patchBudget` | owner | 200 (`amount_minor` only; never creates) |
| DELETE | `/budgets/{id}` | `deleteBudget` | owner | 200 tombstone (final) |
| POST | `/budgets/{id}/archive` | `archiveBudget` | owner | 200 (one-way) |
| GET | `/budgets/{id}/progress?date=` | `getBudgetProgress` | owner | 200 (archived allowed; tombstone 404) |
| GET | `/budget-progress?date=` | `listBudgetProgress` | owner | 200 collection of active budgets' progress |

All writes: `403 EMAIL_NOT_VERIFIED` when unverified; `version` in the body; `409 VERSION_CONFLICT` with `current` (tombstone included); identical replay 200 unchanged; another person's id 404.

## What C8 binds to

1. **Attribution**: only transactions the owner recorded (`created_by`; for a recurring rule's transaction, the rule's author), in wallets the owner currently belongs to (any role). A partner's spending never counts.
2. **Kinds**: expenses only. Transfers, incomes, scheduled or skipped occurrences never count. Deleted transactions and deleted wallets drop out.
3. **Currency**: one per budget, immutable; only that currency is summed; other currencies with matching spending are named in `other_currencies`.
4. **Category**: an expense category (own or platform, archived allowed) or `null` for all expenses; immutable. Income categories refused (`INVALID_ENUM_VALUE /category_id`). Overlap allowed.
5. **Budget Period**: monthly from the profile's `budget_period_start_day`. A change applies from `budget_period_start_day_from`; the period under way at the change ends the day before. Membership by the transaction's calendar `date`; "today" by the profile timezone. Use `period.start`/`end` from the last server progress for the current period while a change is pending.
6. **Progress fields**: `limit`, `spent`, `remaining` (limit − spent, may be negative), `over_limit` (spent > limit), `projected_end` (current period only: spent × days_in_period ÷ days_elapsed, rounded up; else null), `period {start, end, days_in_period, days_elapsed}`, `is_current`, `other_currencies`. Percentage: derive `spent / limit` locally for display.
7. **Offline estimate**: compute the same sum over local transactions with the rules above; replace it with the server figure after pull.
8. **Sync**: budgets ride `GET /sync/user/changes` as `budget` rows (never a wallet feed); push via `POST /sync/batch` items `{type: budget, op: upsert|delete, id, data}` with no `wallet_id`. Archive is per-record only.
9. **No notifications** in this release; colour and wording are the client's (neutral presentation).

## Field-level codes used

`REQUIRED`, `UNKNOWN_FIELD`, `INVALID_TYPE`, `AMOUNT_NOT_INTEGER`, `OUT_OF_RANGE`, `INVALID_CURRENCY`, `INVALID_ENUM_VALUE`, `IMMUTABLE_FIELD`, `INVALID_ID`. No new code; `info.version` v1.7 → v1.8.
