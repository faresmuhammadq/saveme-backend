# ADR-0019: Budget spending is attributed by author and computed on read

- **Status**: Accepted
- **Date**: 2026-10-06
- **Principles**: P-03, P-05, P-08, P-11, P-12
- **Supersedes**: — (extends [ADR-0011](0011-time-and-timezones.md) rule 7 and [ADR-0014](0014-platform-owned-categories.md) rule 5; no rule of either changes)
- **Resolves**: whose spending a budget counts, in which currency, and where its progress comes from (spec 015, brief B8)

## Context

A budget is personal ([glossary](../glossary.md#budget)): it caps its owner's own spending. Wallets are shared, categories may be platform-owned ([ADR-0014](0014-platform-owned-categories.md)), and a person may hold wallets in several currencies with no exchange rate in scope. [ADR-0011](0011-time-and-timezones.md) rule 7 says shared-wallet totals are identical for every member. Spec 002 banked six open questions for this feature.

Forces:

- A platform category has no owner, so "the category's owner" cannot attribute a transaction. `created_by` exists on every transaction and on the phone.
- Postings balance per currency ([ADR-0012](0012-postings-wallet-and-category-accounts.md), P-03 rule 3); summing across currencies is undefined.
- A cached figure must be invalidated by backdated edits, re-categorisation, restores, wallet deletion (no per-record events) and membership loss (no ledger event at all); any miss is a wrong figure the person trusts.
- P-05 rule 3: totals come from postings, never `balance_minor`.

## Decision

1. A budget's spending MUST be the sum of category-leg postings of **expense** transactions that the budget's owner recorded (`created_by`; for a recurring rule's transaction, the rule's author), dated within the Budget Period, in the budget's category (or any expense category when it has none), in a live wallet where the owner currently holds a live membership of any role.
2. A budget MUST have exactly one currency, immutable. Only that currency is summed; spending in other currencies is reported as the list of their codes, never added.
3. Progress MUST be computed on every read from one grouped query over postings. No figure is stored or cached, and Budgeting MUST NOT listen to ledger events.
4. `projected_end` MUST be computed with `Money::allocate()` (largest share), rounded up, for the current Budget Period only, and be null when the product exceeds 2^53 - 1. No percentage is returned and no amount is divided or rounded outside `allocate` (ADR-0003 rule 7).
5. The Budget Period containing a date MUST come from the owner's **budget start day history** (Identity, server-internal): a period already begun keeps its dates after a start day change. Membership is by the transaction's calendar `date`; "today" is in the owner's profile timezone.
6. A budget write that names a category MUST share-lock that category, then take the owner's user-scope stamp, then lock the budget row (extends [ADR-0017](0017-membership-on-the-members-user-feed.md) rule 5).

## Alternatives Considered

### Attribute by the category's owner

**Rejected because** platform categories have no owner, and a partner re-categorising a transaction would move spending between budgets.

### Count every member's spending in a shared wallet

**Rejected because** a budget is personal; two partners would see one household figure and neither limit would mean anything.

### A per-(budget, period) cache invalidated by ledger events

**Rejected because** of the invalidation cases above, in particular membership loss, which has no event. It can be added behind the same query service if the performance target fails, under its own decision record.

### Sum across currencies at a fixed rate

**Rejected because** FX is out of scope (P-17).

## Consequences

- A budget's figure depends on its owner, never on who views it, so ADR-0011 rule 7 holds; a platform category's total is always scoped to one person (ADR-0014 rule 5).
- A partner's spending never counts, and wallets the owner left stop counting, so the server equals what the phone holds.
- Wallets gains one partial index on `transactions (created_by, date)` for the spending query.
- B13 (reports), B15 (export) and B16 (notifications) inherit these rules; overrun detection needs its own trigger because no stored state exists.
