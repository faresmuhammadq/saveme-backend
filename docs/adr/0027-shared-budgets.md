# ADR-0027: Shared budgets hold their own entries and sync scope

- **Status**: Proposed
- **Date**: 2026-11-01
- **Principles**: P-03, P-05, P-07, P-08, P-09, P-12
- **Supersedes**: [ADR-0008](0008-sync-changes-feed.md) rule 1 only, and only to add a third kind of feed scope (extends [ADR-0006](0006-wallet-scoped-authorization.md), [ADR-0017](0017-membership-on-the-members-user-feed.md), [ADR-0019](0019-budget-spending-by-author-computed-on-read.md) and [ADR-0024](0024-account-deletion-keeps-shared-history.md))
- **Resolves**: tracking one household's spending when its members pay from separate wallets (owner decision, 2026-11-01)

## Context

**Ratification.** Proposed until the owner ratifies the matching constitution amendment of P-08 rule 1 when the pull request merges. Until then, nothing here is in force on `main`.

Using the product as a couple showed that a shared wallet does not fit household spending: the money comes from several wallets, some of them private. A Budget cannot fill the gap, because it caps one person's own spending (ADR-0019), and a person never receives the transactions of a wallet they are not in.

Forces:

- P-08 rule 1 makes authorization wallet-scoped. A person who shares one household expense must not expose the wallet it came from.
- ADR-0008 rule 1 allows exactly two kinds of feed scope. A partner outside a wallet can read neither of them for this data.
- A partner's history must survive the author leaving (owner decision) and the wallet's deletion, while ledger rows are never rewritten after the fact (ADR-0024 rule 3).
- Removing a transaction from a budget must reach the other members' phones as a tombstone.
- ADR-0011 rule 7: a shared figure is identical for every reader.

## Decision

1. A **Shared Budget** MUST be a root entity with its own members, separate from a Budget. Personal budgets and ADR-0019 MUST NOT change.
2. Authorization for a Shared Budget MUST come from its own membership combined with the owner's entitlements. A non-member MUST receive 404. A member whose role does not permit the action MUST receive 403.
3. Only the budget's owner MAY change its name, limits, category lines, start day or members. Any member MAY read it, add their own transactions unless read-only, and leave. The owner MUST transfer ownership or delete the budget before leaving.
4. A transaction MUST join a Shared Budget only when its author names it. Nothing joins by category or by default. Only `expense` transactions qualify, in the budget's currency, and each transaction names at most one budget. Only the author MAY change the link.
5. Joining MUST write one **Shared Budget Entry** in the same database transaction as the ledger write. Budget figures MUST sum entries, never postings or wallet balances.
6. An entry MUST NOT carry or render a wallet id, wallet name, balance, `memo` or `counterparty`. It carries the transaction id, author, category, date, amount and `description`.
7. While its author is an active member, an entry MUST equal its transaction's amount, date, category and description, and MUST be tombstoned when the transaction is voided or unlinked and restored with it.
8. An entry stops following its transaction (`detached_at` set) when its author leaves or is removed, or its wallet is deleted. Leaving or removal MUST also erase `description`, with a new stamp so the erasure reaches other members. Amounts, dates and categories MUST stay. The ledger row keeps its link and MUST NOT be rewritten.
9. A Shared Budget MUST be a third kind of feed scope with its own `change_seq` counter and purge horizon. Budget, member, line, limit, start day, entry and invitation records MUST be stamped from it. A membership MUST also be stamped from its member's user scope and appear on that person's user feed (ADR-0017 rules 2–4).
10. When one unit of work takes several scope locks, the order MUST be: category rows, wallet rows ascending, shared budget rows ascending, user rows ascending, then the records (extends ADR-0017 rule 5).
11. A category named by any line of a budget, live or tombstoned, MAY be used by a member on a transaction linked to that budget, and counts as in use for its owner. Using it publishes a Category Reference exactly as any other transaction does.
12. Limit changes MUST apply from the current Budget Period and start day changes from the next. Each MUST be kept as dated history so a past period reports its own limit. This is not the out-of-scope "Budget period rows with rollover": no spending carries between periods.
13. A Shared Budget's Budget Periods and "today" MUST come from its own start day history and timezone, never a reader's profile.
14. Inviting and seats MUST be governed by the owner's `sharing.enabled` and `sharing.max_members_per_budget`. On a lower quota, members beyond it become read-only; nobody is removed.
15. An account purge MUST pass an owned budget to its earliest active member, or delete it when no other member remains, then leave the rest under rule 8. Author ids stay (ADR-0024 rule 3).

## Alternatives Considered

### Share a wallet instead

Keep household money in a joint wallet. **Rejected because** this is what failed in real use: the money sits in several wallets.

### Compute figures on read from the authors' postings

ADR-0019's approach, joined across members. **Rejected because** a partner's phone could not reproduce it without the wallets, an unlinked transaction would have no tombstone on the budget's feed, and history would vanish when an author left or deleted a wallet.

### Include transactions automatically by category

**Rejected because** one category can hold both personal and household spending, and sharing would become the default. The owner chose an explicit pick.

### Link each member's custom categories to budget lines

**Rejected because** the budget's own list, shown after picking the budget, needs no linking and no duplicates.

### Carry entries on each member's user feed

**Rejected because** each entry would need one stamp per member, and it would put another person's spending on a feed ADR-0017 reserves for a person's own records.

## Consequences

### Positive

- A household tracks one figure, whichever wallets pay, and a private wallet stays private.
- Nothing is shared without a deliberate pick on each transaction.
- History stays correct after a member leaves.

### Negative

- Entries duplicate ledger facts, so a bug could make them differ. An invariant test guards rule 7.
- A third feed scope adds one more feed for clients to follow.
- Transaction writes that link a budget take one more lock.
- P-08 rule 1 stops being the only authorization scope.

### Follow-ups

- Settling up between members, overrun push and bulk-adding old transactions are out of this decision.
