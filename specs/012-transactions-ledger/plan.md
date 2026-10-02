# Implementation Plan: Transactions Ledger

**Branch**: `012-transactions-ledger` (trunk-based; work lands on `main`) | **Date**: 2026-10-02 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/012-transactions-ledger/spec.md`.

## Summary

Add the ledger to the Wallets & Ledger module (`app/Modules/Wallets`), under a `Transactions` sub-namespace in each layer.

- **Records and postings** (R-2): `transactions` (syncable, wallet scope) and `postings` (server-derived, never exposed). Each transaction has exactly two postings summing to zero, derived by a pure domain function; every write replaces them, a delete removes them, a restore re-derives them.
- **Transfers** (R-1, ADR-0015): one record, `wallet_id` = source, `counter_wallet_id` = destination, stamped from both wallets' counters, listed in both wallets, addressable under either path. Both wallets immutable.
- **Writes** through `TransactionService` (`save`, `patch`, `restore`, `delete`) with spec 010's version, replay, tombstone and precedence rules; fixed lock order categories → wallets ascending (R-4); authorization by `WalletAuthorizer` rows T-02/T-03 (R-6); balance recomputed under each wallet's lock (R-3); Category References published/withdrawn in the unit of work (R-8).
- **Stand-ins replaced**: `NoPostingsYet` → `EloquentPostingTotals` (balance, currency lock); `NoTransactionsYet` → `EloquentTransactionQueryService` (default-currency lock). `TransactionCategoryUsage` registered as a `CategoryUsage` participant; `TransactionsGoWithWallet` as a `WalletDeletionParticipant` (R-9).
- **API**: five operations in `docs/openapi/paths/transactions.yaml` (written and indexed in this design phase; `info.version` v1.5), frozen for C6. One new field code, `CURRENCY_MISMATCH`, catalogued in implementation (T002) with the runtime enum and strings.
- **Events**: `TransactionPosted`, `TransactionVoided` after commit (R-13); no listeners yet.
- **Docs**: ADR-0015 (written in design), glossary Transaction and Wallet entries, constitution PATCH v1.3.5 (P-04 and P-07 ADR lines gain ADR-0015), ADR index.

## Technical Context

**Language/Version**: PHP 8.5, Laravel 13.

**Primary Dependencies**: none new.

**Storage**: PostgreSQL 16; two tables (`transactions`, `postings`); CHECK constraints for kind shape, posting XOR, positive amount, date range; partial indexes for transfers and live-by-author.

**Testing**: Pest.
- Unit: `PostingDerivation`, `TransactionRules`, `TransactionKind`.
- Feature: record, transfer, save/patch, delete/restore, balance recompute, ledger integrity scan, posting constraint, authorization (matrix rows), category rules, usage participant, references, lock order under concurrency, currency lock, default-currency lock, wallet deletion participant, events, replaced stand-ins.
- Contract: five operations with every documented status (Spectator), verification gate, no postings in any response.
- Arch: existing P-02 gates and the `balance_minor` gate; no new gate.

**Target Platform**: the Linux server (the `dev` container).

**Project Type**: modular-monolith JSON API.

**Performance Goals**: list page p95 < 300 ms at 10,000 transactions per wallet, filtered or searched; single write p95 < 150 ms (R-15).

**Constraints**: P-03 (bigint minor units, integer JSON), P-04 (two postings, zero sum, XOR, transfer is one record, never exposed), P-05 (recompute in the unit of work), P-06 (client UUIDv7, foreign id → 404), P-07 (version, tombstones, no merge, explicit restore), P-08 (T-01/T-02/T-03, non-member 404), P-11 (events after commit), P-12 (calendar `date`), P-15 (no amounts or text logged), ADR-0014 rule 10 (lock order).

**Scale/Scope**:
- 5 operations, 2 migrations, no seeder (no reference data);
- about 35 Wallets classes; in Shared the new `Money` value object and one `ErrorCode` case; `lang/`, Wallets providers and two repositories' `isHeldByOther`; no other module's code;
- about 24 test files;
- doc edits: glossary, constitution PATCH, ADR index, error catalogue (+1 field code), `responses.yaml` UnprocessableEntity description (lists the new field code).

## Constitution Check

*Constitution v1.3.4 (`.specify/memory/constitution.md`). GATE: must pass before Phase 0 research. Re-check after Phase 1 design, keeping both results.*

### Pre-design

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | "Transaction", "Posting", "Wallet", "transfer", "counter wallet"; no "account". New terms (transfer, counter wallet, counterparty) added to the glossary's Transaction entry (T001) before code uses them |
| P-02 Module boundaries | PASS | All in `app/Modules/Wallets` (its constitution row owns transactions and postings). Identity reaches the default-currency lock through the existing `TransactionQueryService` application interface; Categories through `CategoryUsage`; no Infrastructure crossing |
| P-03 Money | PASS | `amount_minor bigint`, `currency char(3)`; JSON integer, refused as string/float (`AMOUNT_NOT_INTEGER`); postings built through the shared `Money` value object, created here (constitution follow-up F-4, T003), whose arithmetic refuses mixed currencies; no division or rounding |
| P-04 Ledger integrity | PASS | Two postings per transaction summing to zero (domain assert + `LedgerIntegrityTest`); XOR CHECK; transfer one record (ADR-0015); single currency, cross-currency refused (`CURRENCY_MISMATCH`); postings in no resource (`NoPostingsExposedTest`) |
| P-05 Derived balances | PASS | `balance_minor` recomputed from opening + Σ postings in the write's transaction under each wallet's lock (R-3); never read for a decision (arch gate kept); negative balances never refused |
| P-06 Identifiers | PASS | `PUT /wallets/{id}/transactions/{tid}` upsert with client UUIDv7; id held by another wallet's transaction or another entity → 404 without write (R-10); postings get server UUIDv7 |
| P-07 Sync and concurrency | PASS | Five sync members; `version` in every body; 409 with `current` incl. tombstone; no merge; delete is a tombstone; restore explicit with `deleted_at: null` (ADR-0008 rule 14); `change_seq` per ADR-0008 and ADR-0015 |
| P-08 Authorization | PASS | Rows T-01, T-02, T-03 of spec 010 §6 through `WalletAuthorizer`; both wallets of a transfer authorised; non-member 404; matrix referenced, not restated |
| P-09 Entitlements | PASS | No capability or quota (rule 5); no plan code |
| P-10 Billing channels | N-A | No billing surface |
| P-11 Events | PASS | `TransactionPosted`, `TransactionVoided` after commit, ids and scalars only; balance is not a listener |
| P-12 Time | PASS | `date` is a calendar date with no timezone; instants UTC `timestamptz(6)` |
| P-13 Naming | PASS | No codename |
| P-14 Localisation | PASS | Problem strings for `CURRENCY_MISMATCH` in ar and en; user text stored as typed |
| P-15 Observability | PASS | No amount, description, memo or counterparty logged; no new log line |
| P-16 Quality gates | PASS | Every spec §4 invariant names its test; PostgreSQL only (partial indexes, `FOR SHARE`) |
| P-17 Scope guard | PASS | No FX, no splits, no multi-currency transfer |
| P-18 Delivery surfaces | PASS | Every capability is a `/api/v1` endpoint; no back-office screen |

### Post-design re-check

Unchanged: all rows PASS or N-A.

- **CONTRIBUTING item 18**: ADR-0015 extends ADR-0008 (a transfer rides two wallet feeds with a second scope stamp) and ADR-0012 (transfer wire shape, immutable wallets) without changing any of their rules; written in this design phase and indexed. The constitution's P-04 and P-07 `ADRs:` lines gain ADR-0015 (PATCH v1.3.5, T001).
- The api-conventions rule "exactly one scope key" holds: `counter_wallet_id` is a reference, not a scope key (ADR-0015 rule 6).
- The api-conventions collection parameters gain one endpoint-specific `search` parameter; it is a literal substring, not a query language (R-11), consistent with "no free-text query language".
- One new field code (`CURRENCY_MISMATCH`, since v1.5); `info.version` v1.5 already set for the new paths.

Complexity Tracking stays empty.

## Assumed defaults (owner away)

| # | Decision | Why |
|---|---|---|
| A-1 | Transfer = one record, `wallet_id` source, `counter_wallet_id` destination, in both lists and feeds | P-04 rule 3; ADR-0015 |
| A-2 | Archived wallets accept transactions and transfers | Archive is one-way; offline records must not become unrecordable; mirrors archived categories |
| A-3 | Restore supported via `PATCH {deleted_at: null, version}` | Row T-02 names restore; api-conventions; ADR-0008 rule 14 |
| A-4 | Wallet deletion leaves transfers to live wallets live; they can be deleted but not edited or restored | P-08 rule 2: never write into a wallet the deleter may not write |
| A-5 | `kind` and a transfer's `counter_wallet_id` immutable | Same ledger shape on every edit; a record never leaves a feed |
| A-6 | Fields per ADR-0012/glossary: `description`, `memo`, `cleared`, `created_by`, plus `counterparty`; description and memo optional | Ratified side wins over the brief's "note" |
| A-7 | Constitution event names kept (`TransactionPosted`, `TransactionVoided`) | No rename, no amendment |
| A-8 | One new field code `CURRENCY_MISMATCH` (422, after-change) for both currency cases | Mobile asked for a stable code; one meaning, two pointers |
| A-9 | Currency lock counts tombstoned transactions | A restore can never re-post in a stale currency |
| A-10 | References and "in use" count tombstoned transactions; references withdrawn only when no transaction (live or tombstoned) in the wallet uses the category | A tombstone may be restored (spec 011 FR-008) |
| A-11 | Archived categories allowed; another member's category kept on edit when unchanged | Spec 011 OQ3; spec 010 T-02 |
| A-12 | Body references outside the allowed set → `INVALID_ENUM_VALUE` (one answer per pointer) | Spec 010 A-7 precedent; no existence disclosure |
| A-13 | Dates 1900-01-01–2999-12-31; future and backdated allowed | Brief allows backdating; catches typo years |
| A-14 | Amount positive, max 2^53 − 1; direction from `kind` | Glossary "a positive amount"; api-conventions bound |
| A-15 | `search`: literal, case-insensitive substring of description/memo/counterparty | Baseline: notes searchable; no query language |
| A-16 | List: live only, default `-date`, sorts `date`/`created_at`, filters `date`/`category_id`/`kind` | Spec 010/011 list conventions |
| A-17 | No rate limit, no entitlement, no seeder; sizing 10,000 transactions per wallet | P-09 rule 5; no reference data |
| A-18 | `CURRENCY_MISMATCH` catalogued at implementation time with the enum and strings | Catalogue parity test (spec 011 A-18 precedent) |
| A-19 | Postings removed on delete, re-derived on restore | ADR-0012 rule 9 without a join in every sum |
| A-20 | Write algorithm reads unlocked, locks categories then wallets, re-reads, restarts on drift (≤ 3) | ADR-0014 rule 10 without deadlocking wallet deletion |

## Review checklists (applied at the polish task)

- **Endpoint review checklist** (`docs/api-conventions.md` appendix): expected exceptions — G3, G4 do not apply (no idempotency-keyed operation; PUT is idempotent by id); G5 does not apply (no throttle, `429` not documented); I2 is "no" for the feature (two path entries, one per distinct path).
- **Security review checklist**: C1 every transaction write behind the gate; C2 reads are not; D1 amount and note rows already in the protected-attributes table (unencrypted, searchable, members only); E1–E6 no amount or text logged; refusals disclose nothing beyond the caller's wallets.

### Result of applying both checklists (T048)

Both checklists were applied to each of the five operations in `docs/openapi/paths/transactions.yaml`, which the implementation matches member for member: no field name, code or status of the frozen contract changed.

- **Endpoint review checklist**: every line is "yes" except the expected exceptions above (G3 and G4 do not apply, G5 does not apply) and these:
  - I2 ("adds nothing to `openapi.yaml` beyond one `paths` entry") is "no" for the feature as a whole, not for any operation: the feature adds two path entries (`/wallets/{id}/transactions`, `/wallets/{id}/transactions/{tid}`), one per distinct path, in the design phase, frozen.
  - E4 ("no bespoke parameter names") holds for the filters (`filter[date]`, `filter[category_id]`, `filter[kind]`) and is a recorded exception only for `search`, an endpoint-specific literal-substring parameter declared in the path file (R-11); it is not a query language and no other operation uses it.
  - C8 does not apply (a writable resource).
  - D5 yes: no response, log or event carries a posting (`NoPostingsExposedTest`).
  - The Error catalogue gained `CURRENCY_MISMATCH` (B3 satisfied by a catalogue entry, `since: v1.5`, in the same change as the enum and the ar/en strings).
- **Security review checklist**: C1 yes (every write route sits behind `address.verified`, proved by `TransactionVerificationGateTest`); C2 yes (reads are open to unverified persons); D1 yes (amount, description, memo and counterparty are already protected attributes: unencrypted, searchable, members only; `counterparty` is a free-text name covered by the same Note row); E1-E6 yes (no new log line anywhere on the transaction path, asserted with `Log::spy()` in `NoPostingsExposedTest`; the events carry ids and scalars only; the 404 is identical for another wallet's transaction, a non-member, a wallet id and a category id).
- **Contract note**: the implementation changed nothing the C6 client binds to (the table in `contracts/transactions-api.md`).

## Project Structure

### Documentation (this feature)

```text
specs/012-transactions-ledger/
├── spec.md
├── plan.md                 # this file
├── research.md             # R-1 … R-15
├── data-model.md
├── quickstart.md
├── contracts/transactions-api.md
├── checklists/requirements.md, checklists/contract.md
└── tasks.md                # /speckit-tasks
docs/adr/0015-transfers-on-two-wallet-feeds.md        # written in design
docs/openapi/paths/transactions.yaml, openapi.yaml    # written and indexed in design (v1.5)
```

### Source Code (repository root)

```text
app/Modules/Wallets/
├── Domain/Transactions/
│   ├── {TransactionKind,TransactionRecord,PostingLine,PostingDerivation,TransactionRules}.php
│   ├── Refusals/{TransactionNotFound,TransactionVersionConflict,TransactionStateInvalid}.php
│   └── Ports/{TransactionRepository,PostingRepository}.php
├── Domain/Events/{TransactionPosted,TransactionVoided}.php   # beside WalletCreated, WalletDeleted
├── Application/Transactions/
│   ├── TransactionService.php              # save, patch, restore, delete
│   ├── TransactionListQuery.php            # list, find (T-01)
│   ├── TransactionCategoryUsage.php        # CategoryUsage participant
│   ├── TransactionsGoWithWallet.php        # WalletDeletionParticipant
│   └── Dto/{TransactionView,SavedTransaction}.php
├── Infrastructure/
│   ├── Models/{Transaction,Posting}.php
│   ├── Repositories/{EloquentTransactionRepository,EloquentPostingRepository}.php
│   ├── Ledger/{EloquentPostingTotals,EloquentTransactionQueryService}.php
│   └── Database/
│       ├── Migrations/2026_10_03_000001_create_transactions_table.php, …000002_create_postings_table.php
│       └── Factories/TransactionFactory.php
└── Http/
    ├── routes.php                          # + transaction routes; writes behind address.verified
    ├── Controllers/TransactionController.php
    ├── Requests/{TransactionId,SaveTransactionRequest,PatchTransactionRequest,RestoreTransactionRequest,TransactionVersionRequest,TransactionListRequest}.php
    └── Resources/TransactionResource.php

removed: app/Modules/Wallets/Application/Provisional/{NoPostingsYet,NoTransactionsYet}.php
added:   app/Modules/Shared/Domain/Money/Money.php (P-03 rule 1; first money arithmetic in the codebase)
changed: app/Modules/Shared/Domain/Errors/ErrorCode.php (+CURRENCY_MISMATCH), lang/{ar,en}/problems.php,
         Wallets{ServiceProvider,PersistenceServiceProvider} bindings and tags,
         EloquentWalletRepository::isHeldByOther, EloquentCategoryRepository::isHeldByOther (+transactions)
docs/openapi/error-catalog.yaml, components/responses.yaml, docs/glossary.md,
.specify/memory/constitution.md (v1.3.5), docs/adr/README.md

tests/Unit/Wallets/Transactions/{PostingDerivation,TransactionRules}Test.php
tests/Feature/Wallets/Transactions/{RecordTransaction,RecordTransfer,SaveTransaction,DeleteRestoreTransaction,BalanceRecompute,LedgerIntegrity,PostingConstraint,TransactionAuthorization,TransactionCategory,TransactionCategoryUsage,TransactionCategoryReferences,TransactionLockOrder,CurrencyLock,DefaultCurrencyLock,TransactionWalletDeletion,TransactionEvents}Test.php
tests/Contract/Wallets/Transactions/{ListTransactions,GetTransaction,UpsertTransaction,Transfer,PatchTransaction,DeleteTransaction,TransactionVerificationGate,NoPostingsExposed}Test.php
```

**Structure Decision**: transactions live in the Wallets module (constitution: Wallets & Ledger owns transactions and postings) under a `Transactions` sub-namespace in each layer, beside `Wallets`, `Membership` and `Categories`.

## Complexity Tracking

No Constitution Check violations; nothing to justify.
