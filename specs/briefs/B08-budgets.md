# B8 · Budgets

**Call with**: `/speckit-specify B8` · **Source**: the Phase B brief set, recorded 2026-09-25

## Description

A budget sets a limit for a category (or all expenses) in a wallet per period, where the period is monthly by default and starts on the profile's start day; progress = spent / limit for the current period, with `over_limit` and `projected_end` fields. Endpoints: `GET /wallets/{id}/budgets?period=`, `PUT /wallets/{id}/budgets/{bid}`, `PATCH`, `DELETE`. Optional rollover flag for later.

## Considerations

Progress is a read model computed from the ledger, never stored as truth; cache per (budget, period) invalidated by ledger events. Present progress neutrally (percentage, remaining) — the research showed red/green shaming drives churn; the API should expose numbers, and the client decides colour. Period boundaries must use the same `Period` value object as reports.

## Reconcile at specify time

The brief predates decisions ratified since. These collide with it; the ratified side wins unless the spec deliberately supersedes it through a decision record or an amendment.

| Brief says | Already decided | Resolution needed |
|---|---|---|
| A budget "in a wallet", paths under `/wallets/{id}/budgets` | **Already decided**: a budget is personal, user-scoped, never in a wallet feed (glossary) | Paths move to the user scope, or the glossary changes first |
| Open questions banked for Budgeting | `specs/README.md` lists six: shared-wallet attribution, budget currency, income budgets, archived categories, which module serves progress, overrun notifications | All six are this spec's to answer |
| `Period` value object | Glossary: **Budget Period** (derived, Budgeting) and **Billing Period** are distinct; neither is called just "Period" | Name it Budget Period |
