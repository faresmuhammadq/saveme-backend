# ADR-0004: Ledger: transactions and postings

- **Status**: Accepted
- **Date**: 2026-09-19
- **Principles**: P-01, P-04
- **Supersedes**: —
- **Resolves**: O1

## Context

The product records income, expenses and transfers between wallets, including credit cards and money owed to a friend. The UX is single-entry: the user picks a type, an amount, a date, a category and a wallet. Nothing in the interface mentions debits, credits or accounts.

Underneath, that UX has to answer questions a flat row cannot answer cleanly:

- A transfer moves money out of one wallet and into another. Modelled as two independent rows, the pair can drift: one half edited, one half deleted, one half synced and the other not.
- A credit card and a debt to a friend are liabilities. Their "balance" is what is owed, and spending on them moves the number the opposite way from a cash wallet.
- Multi-currency is out of MVP scope, but the shape chosen now decides whether FX can be added later without rewriting every balance query.
- Balances are derived by summation ([ADR-0005](0005-derived-balance-with-cache.md)), which needs one table to sum over, with a consistent sign convention.

Money is always integer minor units with an ISO 4217 currency ([ADR-0003](0003-money-minor-units.md)). Transactions are syncable, versioned and tombstoned ([ADR-0008](0008-sync-changes-feed.md)), so whatever the server derives from a transaction must be reproducible from the synced record alone.

The brief left this as open question **O1**: a two-column income/expense model, or double-entry postings.

## Decision

1. A **Transaction** MUST be the only user-facing, syncable ledger record. It carries `type` ∈ {`income`, `expense`, `transfer`}, a calendar `date` as `YYYY-MM-DD` with no timezone ([ADR-0011](0011-time-and-timezones.md)), `category_id` (income and expense), `wallet_id`, `counter_wallet_id` (transfer only), a note, and a **positive** `amount_minor` with its `currency`. Direction is carried by `type`, never by the sign of `amount_minor`.
2. **Postings** MUST be server-derived. The client MUST NOT send them. Each posting row is `(transaction_id, account_type, account_ref, amount_minor, currency)`, where `amount_minor` is signed.
3. The postings of one transaction MUST sum to **zero per currency**. A write whose postings do not balance MUST be rejected, and the balancing rule MUST be covered by a feature test.
4. A posting's account MUST be either a **wallet** (`account_type = wallet`, `account_ref` = wallet id) or a **system nominal account** (`account_type = nominal`, `account_ref` ∈ {`income`, `expense`}, plus `opening`/`adjustment` where the ledger needs them). No other account kinds exist in MVP.
5. Postings MUST NOT be exposed through the API and MUST NOT be synced. They appear in no resource, no payload and no changes feed. Accounting vocabulary stays inside the server.
6. Editing a transaction MUST delete and re-derive **all** of its postings inside the same database transaction as the transaction update, and MUST bump the transaction's `version` in that same database transaction. A partial replacement is a bug, not a state.
7. Deleting a transaction MUST tombstone the transaction (`deleted_at` set, `version` and `change_seq` bumped) and MUST remove its postings from balance sums. Postings of a tombstoned transaction are hard-deleted or excluded from every sum; they are never synced either way.
8. In MVP, **all postings of one transaction MUST share a single currency**, and both wallets of a transfer MUST share it. A cross-currency transfer MUST be rejected at validation. FX is out of MVP scope.
9. "Wallet" MUST be the only term for a money container, in code, database, API and UI copy (P-01). `account`, `ledger account`, `card` and `purse` MUST NOT appear as synonyms in any identifier or user-facing string.
10. `wallets.type` MUST be a fixed enum with defined behavioural semantics, not free text and not a user-created label. Liability-type wallets MUST be stored negative and MUST be displayed as the amount owed.

### Worked mappings

All amounts are minor units; each row's postings sum to zero.

| User action | Transaction | Postings |
|---|---|---|
| Expense 500 from Cash | `type=expense, amount=500, wallet=Cash` | `Cash −500`, `expense +500` |
| Income 3000 to Bank | `type=income, amount=3000, wallet=Bank` | `Bank +3000`, `income −3000` |
| Transfer 1000 Bank → Cash | `type=transfer, amount=1000, wallet=Bank, counter_wallet=Cash` — **one** transaction | `Bank −1000`, `Cash +1000` (two wallet postings) |
| Spend 200 on a credit card | `type=expense, amount=200, wallet=Card` | `Card −200`, `expense +200` — the card is a liability wallet, so its balance goes **more negative** |
| Pay 200 off the card from Bank | `type=transfer, amount=200, wallet=Bank, counter_wallet=Card` | `Bank −200`, `Card +200` — paying a card is an ordinary transfer, not a special case |
| Owe a friend 750 | liability-type wallet holding the debt | the wallet is **stored negative** (`−750`) and shown as "750 owed" |
| Future FX transfer (post-MVP) | one transfer transaction | four postings through an `fx` account, e.g. `Bank(EGP) −1000`, `fx(EGP) +1000`, `fx(USD) −20`, `Wallet(USD) +20` — balanced **per currency**, already permitted by rule 3 and blocked in MVP only by rule 8 |

## Alternatives Considered

### Two-column income/expense model on the transaction

Store the direction and amount on the transaction row itself (an `income_amount`/`expense_amount` pair, or a signed amount plus a wallet), with no posting table. Balance = sum of the transaction column per wallet. **Rejected because** a transfer has no single wallet, so it becomes two linked rows that can drift under edit, delete and offline sync; liabilities and credit cards need sign special-cases scattered through every query; and multi-currency has nowhere to live, so adding FX later would mean rewriting the balance path rather than adding two posting rows. The balancing invariant — the one property worth testing — cannot even be stated.

### Exposing postings to the client

Sync postings alongside transactions so clients can render the ledger directly. **Rejected because** it leaks accounting vocabulary into a deliberately single-entry UX, and it doubles the sync surface: postings would need their own versions, tombstones and conflict rules ([ADR-0008](0008-sync-changes-feed.md)) even though they are fully derivable from the transaction the client already has. Every client would also become able to submit an unbalanced ledger.

### Per-wallet running balance stored on the transaction

Write the resulting wallet balance onto each transaction as it is created, so history and balance are one read. **Rejected because** it makes every backdated or edited transaction rewrite the tail of the wallet's history, which is unbounded work on a shared wallet and unworkable when two offline clients backdate into the same period.

## Consequences

### Positive

- Transfers, credit cards and debts are the same mechanism: one transaction, wallet postings that balance. No special cases in the balance path.
- Balance is always one query over one table ([ADR-0005](0005-derived-balance-with-cache.md)).
- There is exactly one invariant to test — postings sum to zero per currency — and it catches malformed writes of every type.
- The per-currency form of the rule means FX is an additional posting pair, not a schema change.
- The client contract stays single-entry: the UX and the sync feed never mention postings.

### Negative

- Every transaction write costs extra rows and a derivation step, so the write path is longer than a flat insert.
- Postings must be regenerated on edit, which makes "edit a transaction" a multi-statement database transaction rather than a single `UPDATE`.
- A server-only table means reconciliation and debugging need server tooling; a user report of a wrong balance cannot be diagnosed from client data alone.
- Nominal accounts (`income`, `expense`) are an internal concept a new contributor has to learn before reading the ledger code.

### Follow-ups

- The Wallets & Ledger spec defines the `wallets.type` enum values and each type's behavioural semantics (sign convention, whether it can be a transfer counterpart).
- Revisit rule 8 when multi-currency enters scope: the FX shape is already permitted by rule 3, so the change is lifting the single-currency validation and defining the `fx` nominal account and rate capture.
- Add the posting-derivation rules for `opening` and `adjustment` accounts when opening-balance edits or manual corrections are specified.
