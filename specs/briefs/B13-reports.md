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

## Inherited from B7 (spec 014)

- **Forecast from `scheduled` occurrences, through a query service, never from transactions.** Upcoming items are Occurrence records with `status: scheduled` (materialised through the rule's today + 35 days and at least the next one); there is no `planned` transaction and none will be. Read them through an Application query service over the Wallets tables, not by calling the recurring endpoints.
- **"Due" and "missed" are derived** (scheduled and dated on or before the rule's today, in the rule's stored `timezone`; missed is due while a later non-payout occurrence of the same rule is also due). Compute them; they are never stored.
- A gam'eya's **payout** is an income occurrence (`is_payout: true`) worth the sum of the scheduled contributions; a forecast counts it on the payout round's date. A skipped occurrence is a decision about one round, not a change to the series.
- Confirmed occurrences are ordinary transactions and are already in every actual total; do not add them again from the occurrence table.

## Inherited from B8 (spec 015)

- **Budget-vs-actual goes through Budgeting's `BudgetQueryService`**, not a second computation. Same attribution (the owner's own `created_by` expenses in wallets they currently belong to), same one-currency-per-budget rule (never sum currencies; list others) and same Budget Period rules (owner's budget start day history; calendar-date membership).
- Progress is computed on read from postings; there is no stored figure to reconcile with.
