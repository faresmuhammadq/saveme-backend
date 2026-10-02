# Summary: Transactions Ledger (spec 012, B6)

**Date**: 2026-10-02 · **Status**: implemented (T001–T051), QA and E2E green, committed on `main` (2dbb8d7).

## What was built

- **Ledger tables.** `transactions` and `postings`, with CHECK constraints for kind shape, positive bounded amount (≤ 2^53−1), date 1900–2999, one posting target and non-zero postings. A transfer is **one record** with `counter_wallet_id` and an internal `counter_change_seq` (never on the wire) so it appears on both wallets' feeds (ADR-0015).
- **Shared `Money` value object** (`Shared/Domain/Money`): immutable, plus/minus/negate/isZero/equals, bounded to ±(2^53−1), no division or rounding (P-03).
- **New field code `CURRENCY_MISMATCH`** (v1.5) in the catalogue, `ErrorCode` enum and ar/en strings.
- **Domain**: `TransactionKind`, `TransactionRecord`, `PostingLine`, `PostingDerivation` (asserts the zero sum), `TransactionRules` (collects every violation into one 422), refusals, ports, events `TransactionPosted` and `TransactionVoided`.
- **`TransactionService`** (`save`, `patch`, `restore`, `delete`): one DB transaction per write; locks categories (share, ascending) → wallets (update, ascending) → transaction row (ADR-0014 order); restarts up to 3 times if the record moved between the unlocked read and the locks. Balances are recomputed from postings under the locks (P-05). Category References published and withdrawn per research R-8.
- **Five endpoints** under `/api/v1/wallets/{id}/transactions[/{tid}]` (GET list, GET, PUT, PATCH edit or restore, DELETE with body), writes behind `address.verified`. New request rules `CalendarDate` and `MustBeNull`; `CollectionQuery` binds a cursor to `search`.
- **Stand-ins replaced**: `NoPostingsYet` → `EloquentPostingTotals` (currency lock counts tombstones); `NoTransactionsYet` → `LedgerTransactionQueryService`; `TransactionCategoryUsage` and `TransactionsGoWithWallet` registered. `CATEGORY_IN_USE` and the wallet currency lock are now reachable over HTTP. Transaction ids return 404 on `/wallets/{id}` and `/categories/{id}`.
- **Docs**: glossary, constitution v1.3.5, ADR-0015, "Inherited from B6" notes in briefs B07, B08, B14, B15.
- **Apidog**: bundle imported into project 1332822 / module 1363204; "Transactions" doc 2462557 created.

### Fixes made during QA

1. **Repeated filters were dropped.** `?filter[date]=gte:…&filter[date]=lte:…` (the documented grammar) applied only the last condition. `CollectionQuery` now reads the raw query string; this fixes wallets and categories too.
2. **Non-member of a transfer's source** editing or restoring through the destination's path got 422 at `/counter_wallet_id`; now 403 `FORBIDDEN`, matching DELETE.

## Results

- **QA** (`/speckit-qa-run`, curl): 173/173 after fixes (171 first pass; the 2 failures were the bugs above). Includes 5 concurrent same-version edits → one 200, four 409; 4 concurrent identical creates → one record; 0 balance drift. Report `qa/qa-20261002-064334.md`.
- **E2E** (`/speckit-test-by-validate`, over HTTP): 32/32 acceptance scenarios. Report `test-reports/validation-20261002-064334.md`.
- **Gates**: Pint and Larastan clean; Arch 47/47; Unit + Feature + Contract 1164 passed (920 before).
- **Performance** (10,000 transactions in one wallet, in process, Xdebug off, p95): list 60 ms, filtered list 24 ms, search 78 ms (ILIKE, no trigram index), create 109 ms. Targets 300 ms list / 150 ms write met; with Xdebug on, a write is 166 ms.

## Contract

`docs/openapi/paths/transactions.yaml` unchanged apart from the planned v1.5 header line for `CURRENCY_MISMATCH`. Client note: 403 on a transfer write means no rights in the other wallet.

## Assumed defaults

Owner away with delegated authority; each the conservative option.

### Design

1. A transfer is one record: `wallet_id` source, `counter_wallet_id` destination, `category_id` null (P-04 forbids linked pairs).
2. A transfer appears on both wallets' feeds via `counter_change_seq` from the destination's counter (ADR-0015 extends ADR-0008).
3. `kind` and a transfer's destination are immutable; delete and record again.
4. Archived wallets accept transactions (overrides spec 010's recommendation) so offline records are never stranded.
5. Restore is supported via `PATCH {"deleted_at": null, "version": n}`, authorised like an edit.
6. Deleting a wallet leaves transfers to other live wallets live; through the live wallet they can be deleted, not edited or restored.
7. Field names follow ADR-0012 and the glossary: `description`, `memo`, `cleared`, `created_by`, plus `counterparty`.
8. Events keep the constitution's names `TransactionPosted` and `TransactionVoided`.
9. One new code, `CURRENCY_MISMATCH`; bad body references reuse `INVALID_ENUM_VALUE`.
10. The currency lock, "in use" and Category References count deleted transactions too, so a restore never posts in a stale currency or to a deleted category. A wallet whose transactions are all deleted stays currency-locked until purge (90 days).
11. Archived categories are allowed; an editor keeps another member's category on edit if unchanged.
12. Postings are removed on delete and re-derived on restore.
13. Dates 1900-01-01 to 2999-12-31; amounts positive up to 2^53−1; `search` is a literal case-insensitive substring; `category_id` `in` filter max 50 values; no rate limit, no entitlement check, no seeder; sized for 10,000 transactions per wallet.
14. Transfers require owner or editor in both wallets.

### Implementation

15. The transaction query service lives in Application (`LedgerTransactionQueryService`), since Infrastructure may not implement an Application interface (P-02).
16. `PostingTotals::lockedAmong(ids)` added so `GET /wallets` keeps a constant query count.
17. PATCH edit and restore share one request class; rules chosen by the presence of `deleted_at`. A body mixing restore and edit fields is a restore, so the extra field is `UNKNOWN_FIELD`.
18. Every touched live wallet takes a final `change_seq` stamp on any real write, keeping the wallet's value above its children's.
19. Restoring a live transaction returns it unchanged whatever version is sent; a stale version is 409 only for a tombstone.
20. An unchanged `category_id` skips category checks on edit and restore.
21. Invalid filter values → 400 `INVALID_QUERY_PARAMETER`; a cursor that doesn't match the sort → 400 `INVALID_CURSOR`; a present but blank `search` → 400.
22. Through the source's path, a destination the caller is not a member of stays 422 at `/counter_wallet_id` on edit and restore (FR-006, FR-014); through the destination's path, a non-member of the source gets 403.

## Remaining risks

- Clients that sent conflicting repeated filters now get fewer rows (the documented behaviour).
- Search has no trigram index; revisit if wallets grow well past 10,000 transactions.
- B14 must implement ADR-0015's two-feed query (`change_seq` for the source, `counter_change_seq` for the destination).
- Shared-wallet scenarios used hand-seeded members until B9 invitations exist.
- DELETE with a JSON body remains a client-library hazard (spec 010 R-5).
