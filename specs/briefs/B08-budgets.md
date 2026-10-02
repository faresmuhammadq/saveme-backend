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

## Inherited from B5 (spec 011)

- **Usage**: register a `CategoryUsage` participant (through Budgeting's application layer; Wallets does not know Budgeting's tables) that says a category is used while any budget, live or tombstoned, references it. Answer `usedAmong` set-wise. A used category is archived, never deleted.
- A budget may name a person's category or a platform one (the id is used directly). B8 decides the scope rules for a platform category's budget; its progress is a total, so it is **always scoped and grouped by `system_key`** (ADR-0014 rules 5-6, spec 011 FR-027 and FR-028).
- Categories are flat in MVP (`parent_id` is reserved and always null); do not design roll-ups over a hierarchy.
- **Lock order** (spec 011 FR-023, ADR-0014 rule 10): lock category rows first (`CategoryRepository::findForShare`, or update), then wallet rows, several wallets in ascending id. Never lock a wallet and then a category: a rename of a category republishes to its wallets and would deadlock with you.  A budget does not publish a Category Reference (it has no wallet scope), so it normally takes no wallet lock.
