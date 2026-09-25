# B4 · Wallets & membership

**Call with**: `/speckit-specify B4` · **Source**: the Phase B brief set, recorded 2026-09-25

## Description

A user creates wallets of a fixed type (cash, bank, savings, liability, gam'eya — final enum per A1/A3), each with name, currency, opening balance, icon/colour, archived flag and a cached balance. Creating a wallet inserts the creator into `wallet_members` with role `owner`. Roles: `owner` (all, including delete, transfer ownership, manage members), `editor` (create/edit/delete transactions, categories in that wallet), `viewer` (read only). Endpoints: `GET /wallets`, `PUT /wallets/{id}`, `PATCH /wallets/{id}`, `POST /wallets/{id}/archive`, `DELETE /wallets/{id}`, `GET /wallets/{id}/members`, `POST /wallets/{id}/transfer-ownership`.

## Considerations

Permission matrix lives here as a table and is the single source for every policy in later specs. A wallet's currency is immutable after the first transaction. Deleting a wallet is a soft delete that cascades tombstones to its transactions (sync). Cached balance recompute is a queued job triggered by ledger events but the read endpoint may return a stale value with `balance_as_of`. Membership is modeled now even though invitations arrive in spec 9 — the only members possible before spec 9 are owners.

## Owner decisions (2026-09-25)

- **The cached balance is recomputed inside the same database transaction that changes postings**, as P-05 rule 2 requires. No queued recompute, no stale read, no `balance_as_of`. The brief's queued-job consideration is withdrawn.

## Reconcile at specify time

The brief predates decisions ratified since. These collide with it; the ratified side wins unless the spec deliberately supersedes it through a decision record or an amendment.

| Brief says | Already decided | Resolution needed |
|---|---|---|
| Cached balance recomputed by a queued job, possibly stale | **P-05 rule 2** (non-negotiable): the cache MUST be recomputed within the same database transaction that changes postings; ADR-0005 takes the wallet row lock for exactly this | Direct constitutional conflict. Drop the queued recompute and `balance_as_of`, or amend P-05 |
| Categories editable by editors "in that wallet" | Categories are **user-scoped**; a shared wallet sees a partner's category through the read-only Category Reference (glossary, ADR-0012) | The matrix should not grant category rights per wallet |
| Wallet type enum "final per A1/A3" | Glossary fixes `wallets.type`, with savings as حصالة | Take the enum from the glossary |
