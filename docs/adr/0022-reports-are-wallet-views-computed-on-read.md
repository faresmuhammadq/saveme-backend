# ADR-0022: Reports are wallet views computed on read

- **Status**: Accepted
- **Date**: 2026-10-04
- **Principles**: P-03, P-05, P-08, P-12
- **Supersedes**: — (extends [ADR-0011](0011-time-and-timezones.md) rule 8, [ADR-0014](0014-platform-owned-categories.md) rule 5 and [ADR-0019](0019-budget-spending-by-author-computed-on-read.md); no rule of any changes)
- **Resolves**: which transactions a report counts, which wallets a cross-wallet report covers, where its default range comes from and what its balances are made of (spec 019, brief B13)

## Context

A [report](../glossary.md#report) is a derived view. Budgets (ADR-0019) count only their owner's own spending, but a report is a view of a wallet, and wallets are shared. [ADR-0011](0011-time-and-timezones.md) rule 7 says shared-wallet totals are identical for every member. A person may hold wallets in several currencies with no exchange rate in scope, may have left a shared wallet, and may belong to wallets whose transfers go to a wallet they are not in. The brief proposed a per-(wallet, period) cache and a percentage share; the first has the invalidation problems ADR-0019 already weighed, the second needs a rounding policy ADR-0003 rule 7 does not allow.

Forces:

- Balances must reconcile with the wallet's truth: opening balance plus postings (P-05 rule 3).
- A platform category has no owner; its total is scoped by the wallets read (ADR-0014 rule 5).
- Membership loss has no ledger event, so any cache of a cross-wallet figure can silently go wrong.

## Decision

1. A **wallet report** MUST count every member's live transactions in that wallet, so every member sees the same figures for the same range (ADR-0011 rule 7). Attribution by author stays with budgets (ADR-0019).
2. A **cross-wallet report** MUST cover the live wallets the caller currently belongs to (any role, archived included) in exactly one currency: the `currency` parameter, else the profile default (EGP without a profile). Wallets in other currencies are listed in `meta.excluded_wallets` and never converted. A transfer whose two wallets are both covered appears as neither `transfers_in` nor `transfers_out`.
3. The default **Report Range** MUST be the caller's Budget Period containing the requested date (today in the profile timezone when absent), computed exactly as `/budget-progress` computes it. An explicit `from`/`to` MUST span at most 366 days. A transaction belongs to a range by its calendar `date` alone, and the response echoes the resolved range (ADR-0011 rules 6 to 8).
4. `balance_start` and `balance_end` MUST be the wallet's opening balance plus the sum of its wallet-leg postings dated before `from` and through `to`. `balance_minor` is never read.
5. A report MUST NOT carry a share or percentage. Category and series `label` members are machine keys (a platform `system_key`, a category id or a Budget Period start date).
6. A report MUST be computed on each read from grouped queries over postings, transactions and scheduled occurrences. No figure is stored or cached, Reporting listens to no event, and planned figures are kept apart from actual ones.
7. All queries of one response MUST read one snapshot: a repeatable-read, read-only transaction, so the balance identity cannot straddle a concurrent commit.

## Alternatives Considered

### Attribute by author, as budgets do

**Rejected because** a report would then disagree with the wallet's balance, and two members would see different figures for one wallet.

### A per-(wallet, period) cache invalidated by ledger events

**Rejected because** of the invalidation cases ADR-0019 lists and, for cross-wallet reports, membership loss, which has no event. It can be added behind the same query service if the performance target fails, under its own decision record.

### Calendar months only

**Rejected because** a person paid on the 25th would see reports that disagree with their budgets.

### Server-computed shares

**Rejected because** ADR-0003 rule 7 forbids dividing or rounding an amount outside `Money::allocate()`; the client derives `value / total`.

## Consequences

- A wallet report is identical for every member; a cross-wallet report depends on the caller's memberships and currency.
- Reporting persists nothing. Wallets owns every SQL statement over its tables; Reporting reads them through Wallets' Application layer.
- Budget Period arithmetic lives in one place, Budgeting's `BudgetPeriodQuery`, used by budgets and reports alike.
- B15 (export) and B16 (notifications) inherit these rules.
