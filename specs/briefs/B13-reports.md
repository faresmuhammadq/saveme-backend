# B13 · Reports

**Call with**: `/speckit-specify B13` · **Source**: the Phase B brief set, recorded 2026-09-25

## Description

Read-only endpoints computed from the ledger: `GET /wallets/{id}/reports/summary?period=` (income, expenses, net, balance start/end), `GET …/reports/by-category?period=` (amount and share per category, grouped by `system_key`), `GET …/reports/trend?months=6` (per-month income/expense), and cross-wallet equivalents under `/reports/*` for the user's own wallets in a single currency.

## Considerations

Same `Period` value object as budgets. `planned` recurring occurrences excluded unless `include_planned=true`. Cross-wallet reports skip wallets in other currencies for MVP and say so in `meta.excluded_wallets`. Cache per (wallet, period) invalidated by ledger events. Keep the response shapes chart-friendly (arrays of `{ label, value }`).

## Reconcile at specify time

The brief predates decisions ratified since. These collide with it; the ratified side wins unless the spec deliberately supersedes it through a decision record or an amendment.

| Brief says | Already decided | Resolution needed |
|---|---|---|
| Totals from the ledger | P-05 rule 3: never read the cached balance; recompute from postings | Required, including balance start and end |
| "Share per category" | ADR-0003 rule 7: no division or rounding on minor-unit amounts outside `allocate` | A percentage is a ratio, not money — state the rounding rule |
| `{ label, value }` shapes | Money on the wire is `amount_minor` + `currency` (spec 003) | `value` must be money-shaped, never a decimal |
| Shared wallet totals | ADR-0011 rule 7: identical for every member | Required |
