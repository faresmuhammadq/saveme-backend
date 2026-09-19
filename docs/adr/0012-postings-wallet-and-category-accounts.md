# ADR-0012: Postings against wallet and category accounts

- **Status**: Accepted
- **Date**: 2026-09-20
- **Principles**: P-01, P-04
- **Supersedes**: [ADR-0004](0004-ledger-transactions-and-postings.md)
- **Resolves**: O1

## Context

[ADR-0004](0004-ledger-transactions-and-postings.md) settled O1 in favour of a postings ledger under a single-entry UX, and that part is not reopened here. What it also fixed was the **account side** of a posting: a posting hit either a wallet or one of two system nominal accounts (`income`, `expense`), while the category lived as `category_id` on the Transaction row.

Earlier design work (`docs/legacy/DATA_MODEL.md`) modelled the same ledger differently: a posting hits **either a wallet or a category**, and a category total is summed from postings exactly the way a wallet balance is. Reconciling the two, the category-as-account form was chosen, for reasons ADR-0004 did not weigh:

- **One query shape for both axes.** "What is in this wallet" and "what went to this category" become the same sum over `postings`, filtered by a different column. Under ADR-0004 the second needs a join back through `transactions.category_id`, so the two most common reads of the product are built and indexed differently for no reason.
- **Split transactions have nowhere to live** under a single `category_id` column. One receipt that is partly groceries and partly household is a normal expectation in this product; as posting rows it needs no schema change, only more legs.
- The nominal accounts `income` and `expense` were carrying no information that the category leg does not already carry — every posting to `expense` was accompanied by a `category_id` that says which expense.

The constitution's governance forbids editing an Accepted ADR, so this record supersedes ADR-0004 rather than amending it. ADR-0004's body stands as written, for the history.

Unchanged from ADR-0004 and restated below so this record is self-contained: money is integer minor units ([ADR-0003](0003-money-minor-units.md)), balances are derived ([ADR-0005](0005-derived-balance-with-cache.md)), transactions are syncable and versioned ([ADR-0008](0008-sync-changes-feed.md)), and calendar dates carry no timezone ([ADR-0011](0011-time-and-timezones.md)).

## Decision

1. A **Transaction** MUST be the only user-facing, syncable ledger record. It carries a calendar `date` as `YYYY-MM-DD` with no timezone, a description, an optional memo, a `cleared` flag, `created_by`, and the syncable fields (`version`, `change_seq`, `deleted_at`).
2. **Postings** MUST be server-derived. The client MUST NOT send them. Each posting row is `(transaction_id, wallet_id, category_id, amount_minor, currency)` with `amount_minor` signed.
3. Each posting MUST set **exactly one** of `wallet_id` or `category_id` — never both, never neither. This XOR MUST be enforced by a database CHECK constraint as well as in the domain.
4. The postings of one transaction MUST sum to **zero per currency**. A write whose postings do not balance MUST be rejected, and the balancing rule MUST be covered by a feature test.
5. The sign convention is: `amount_minor` is the signed effect on the target. The **source** side is negative, the **destination** side positive. The user never types a sign; the server generates both legs.
6. `transactions.kind` MUST be a derived cache over the postings: a transaction **all** of whose postings reference wallets is `transfer`; otherwise it is `income` or `expense` according to the direction of its wallet leg. A `transfer` MUST be excluded from every spending and income aggregation.
7. Postings MUST NOT be exposed through the API and MUST NOT be synced. They appear in no resource, no payload and no changes feed.
8. Editing a transaction MUST delete and re-derive **all** of its postings inside the same database transaction as the transaction update, and MUST bump the transaction's `version` in that same database transaction.
9. Deleting a transaction MUST tombstone the transaction and MUST remove its postings from every sum.
10. In MVP all postings of one transaction MUST share a single currency, and both wallets of a transfer MUST share it. A cross-currency transaction MUST be rejected at validation.
11. A wallet's balance MUST remain `opening_balance_minor + Σ postings.amount_minor WHERE wallet_id = <wallet>` ([ADR-0005](0005-derived-balance-with-cache.md)). A category's total for a period MUST be `Σ postings.amount_minor WHERE category_id = <category>` joined to its transaction's date range. Neither MUST read a cached column for correctness.
12. "Wallet" MUST be the only term for a money container (P-01). `account`, `ledger account`, `card` and `purse` MUST NOT appear as synonyms in any identifier or user-facing string. Within this ADR "account" refers only to the *side* of a posting.
13. `wallets.type` MUST be a fixed enum with defined behavioural semantics. Liability-type wallets MUST be stored negative and MUST be displayed as the amount owed.

### Worked mappings

All amounts are minor units; each transaction's postings sum to zero.

| User action | Postings | `kind` |
|---|---|---|
| Expense 80 from Checking, category Dining | `Checking (wallet) −80`, `Dining (category) +80` | expense |
| Income 5000 into Checking, category Salary | `Salary (category) −5000`, `Checking (wallet) +5000` | income |
| Transfer 1000 Checking → Savings | `Checking −1000`, `Savings +1000` — both wallets | transfer |
| ATM withdrawal 500 | `Checking −500`, `Cash +500` | transfer (never counted as spending) |
| Credit-card spend 500, category Shoes | `CreditCard (liability wallet) −500`, `Shoes (category) +500` | expense (owes more) |
| Pay 300 off the card from Checking | `Checking −300`, `CreditCard +300` | transfer (owes less) |
| Split receipt 200: 120 groceries, 80 household | `Checking −200`, `Groceries +120`, `Household +80` | expense |
| Future FX transfer (post-MVP) | four legs through an `fx` account, balanced per currency | transfer |

An income category is a **source** (negative), an expense category a **destination** (positive). They are mirror images, and the user sees neither.

## Alternatives Considered

### Nominal `income`/`expense` accounts with `category_id` on the transaction (ADR-0004)

Keep two system accounts as the counter-leg and carry the category as a column on the transaction. **Rejected because** category totals then need a join through `transactions` while wallet balances are a direct sum, so the product's two main reads diverge in shape and indexing for no gain; and a single `category_id` column cannot express a split receipt without either a second table or a fake extra transaction. The nominal accounts also duplicated information the category leg already carried.

### Two-column income/expense model on the transaction

Store direction and amount on the transaction row with no posting table. **Rejected because** a transfer has no single wallet, so it becomes two linked rows that drift under edit, delete and offline sync; liabilities need sign special-cases in every query; and multi-currency has nowhere to live. This is the same rejection ADR-0004 recorded for O1, restated here so this record stands alone.

### Category totals from a maintained rollup table

Keep ADR-0004's posting shape and add a per-category-per-period aggregate updated on write. **Rejected because** it is a second cache to invalidate, with the same class of drift risk ADR-0005 confines to one column, and it answers only the periods it was built for — ad-hoc report ranges fall back to the join anyway.

## Consequences

### Positive

- Wallet balance and category total are the same query over one table, with two indexes on `postings`.
- Split transactions need no schema change — only more category legs that still sum to zero.
- Fewer concepts: no system nominal accounts to explain, seed or protect from deletion.
- The zero-sum invariant is unchanged and still the single testable property of the ledger.
- Future FX remains additive, since the rule balances per currency.

### Negative

- Postings can no longer be derived from `type` alone: the server must know which leg is the category, so the derivation code is slightly more involved than "wallet plus nominal".
- Categories are now referenced by ledger rows, so merging or deleting a category touches postings rather than a single transaction column. Category deletion must therefore be a re-point or a block, never a cascade.
- `transactions.kind` is a derived cache and can disagree with the postings if written carelessly; it needs the same recompute-on-write discipline as the balance cache.
- ADR-0004's worked mappings remain readable in the repository and now describe a model that is no longer in force — a reader must follow the supersession link.

### Follow-ups

- The Wallets & Ledger spec defines the `wallets.type` enum values (`cash`, `checking`, `savings`, `credit_card`, `loan`, `investment`) and each type's semantics, plus the category-deletion policy this ADR requires.
- Spec A3 (domain glossary & model map) carries the full entity index built on this posting shape.
- Whether MVP exposes split transactions in the UX is a product decision for A3/Wallets; the ledger permits them either way.
