# B6 · Transactions ledger

**Call with**: `/speckit-specify B6` · **Source**: the Phase B brief set, recorded 2026-09-25

## Description

Users record income, expenses and transfers in a wallet; each transaction has date, amount, currency (must equal the wallet's), category (not for transfers), note, optional counterparty (person name, for debts), and produces postings per the A1 ledger ADR (expense: one posting; transfer: two postings across two wallets in one transaction). Endpoints: `GET /wallets/{id}/transactions` (cursor, date range, category, kind, search), `PUT /wallets/{id}/transactions/{tid}`, `PATCH`, `DELETE`. Events: `TransactionRecorded`, `TransactionAmended`, `TransactionDeleted` → balance recompute, budget progress, reports cache.

## Considerations

Transfers between wallets of different currencies are out of scope for MVP — reject with a stable error code. A transfer between a wallet the user owns and one they only `view` is forbidden; define which role is needed on each side. Edit/delete must respect `version` and return the conflict payload. Backdated transactions are allowed and re-cut budget progress. Deleting a transfer deletes both postings. This spec also fixes the **changes-feed row shape** used by spec 14, because transactions are the highest-volume syncable entity.

## Owner decisions (2026-09-25)

- **The cached balance is recomputed inside the same database transaction that changes postings**, as P-05 rule 2 requires. No queued recompute, no stale read, no `balance_as_of`. The brief's queued-job consideration is withdrawn.
- Ledger events still drive budget progress and report caches; only the balance cache is updated in-transaction.

## Reconcile at specify time

The brief predates decisions ratified since. These collide with it; the ratified side wins unless the spec deliberately supersedes it through a decision record or an amendment.

| Brief says | Already decided | Resolution needed |
|---|---|---|
| "Expense: one posting" | ADR-0012: every transaction's postings sum to zero; an expense is a wallet posting **and** a category posting | Two postings, not one |
| Events `TransactionRecorded/Amended/Deleted` | The constitution's context table publishes `TransactionPosted`, `TransactionVoided` | Pick one set; change the table in the same change if renaming |
| Changes-feed row shape fixed here | Spec 003 and ADR-0008 already fix it: feed rows are the resources themselves with the syncable envelope | Nothing left to fix; reference it |
| Balance recompute as an event consumer | P-05 rule 2: same database transaction, not a listener | As in B4 |
| Search over notes | Notes are unencrypted and searchable (baseline table) | Compatible; state it |
