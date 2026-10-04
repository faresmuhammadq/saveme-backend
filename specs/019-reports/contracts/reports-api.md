# Contract: Reports API (v1.11)

**Source of truth**: [`docs/openapi/paths/reports.yaml`](../../../docs/openapi/paths/reports.yaml), indexed from `openapi.yaml` (`info.version` v1.11, additive). Where this summary and the YAML differ, the YAML wins. Frozen for the C13 client.

## Operations

| Method | Path | operationId | Who | Success | Errors |
|---|---|---|---|---|---|
| GET | `/wallets/{id}/reports/summary` | `getWalletReportSummary` | any active member | 200 | 400, 401, 404, 406, 422 `INVALID_ID`, 426, 500 |
| GET | `/wallets/{id}/reports/by-category` | `getWalletReportByCategory` | any active member | 200 | as above |
| GET | `/wallets/{id}/reports/trend` | `getWalletReportTrend` | any active member | 200 | as above |
| GET | `/reports/summary` | `getReportSummary` | any signed-in person | 200 | 400, 401, 406, 426, 500 |
| GET | `/reports/by-category` | `getReportByCategory` | any signed-in person | 200 | as above |
| GET | `/reports/trend` | `getReportTrend` | any signed-in person | 200 | as above |

All 400s are `INVALID_QUERY_PARAMETER`. No new error code. No entitlement, no verification gate, no rate limit, no `Idempotency-Key`. Not syncable.

## Query parameters

| Name | Operations | Rule | Default |
|---|---|---|---|
| `date` | all | `YYYY-MM-DD`, 1900–2999; a day in the wanted Budget Period (trend: last bucket); not with `from`/`to` | today, caller's profile timezone |
| `from`, `to` | summary, by-category | both or neither; `from` ≤ `to`; ≤ 366 days | the Budget Period containing `date` |
| `months` | trend | integer 1–12 (refused, never clamped) | 6 |
| `include_planned` | all | `true` / `false` | `false` |
| `currency` | `/reports/*` | `^[A-Z]{3}$` | profile `default_currency` (EGP) |

Empty values (`date=`) are absent. Undeclared parameters are ignored.

## What C13 binds to

1. **Scope**: a wallet report counts every member's live transactions (identical for every member); a cross-wallet report covers the live wallets the caller belongs to now, in one currency, other currencies listed in `meta.excluded_wallets` `{wallet_id, currency}`.
2. **Range**: default = the caller's Budget Period containing `date` (same dates as `/budget-progress`); explicit `from`/`to` otherwise; echoed as `data.range`; membership by calendar date.
3. **Summary**: `income`, `expenses` (positive), `net` = income − expenses, `transfers_in`, `transfers_out`, `balance_start` (opening + postings dated before `from`), `balance_end` (through `to`); identity `balance_end = balance_start + income − expenses + transfers_in − transfers_out`. Cross-wallet: transfers between covered wallets appear nowhere.
4. **By-category**: `expenses` and `income`, each `{total, planned, items}`; item `{label, category_id, system_key, value, planned}`; `label` = `system_key` or category id; sorted by amount desc then label. **No share**: derive `value / total`.
5. **Trend**: `periods [{label, from, to}]` (Budget Periods, oldest first, `label` = start date) and series `income`, `expenses`, `net` of `{label, value}`, zero-filled; `planned_income`/`planned_expenses` null unless asked.
6. **Planned**: from `scheduled` occurrences (due and missed included) in the range and currency; never added to actual figures or balances; only occurrences already materialised (each rule's today + 35 days).
7. **Gating**: none; `reports.advanced` gates nothing in this release.
8. **Offline fallback**: the rules above over local transactions reproduce the server's figures; replace with the server answer and label the cached one with its time.
