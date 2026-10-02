---

description: "Task list for Transactions Ledger (B6)"
---

# Tasks: Transactions Ledger

**Input**: Design documents from `/specs/012-transactions-ledger/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/transactions-api.md](./contracts/transactions-api.md), [quickstart.md](./quickstart.md), the frozen contract `docs/openapi/paths/transactions.yaml` (written and indexed; `info.version` v1.5) and [ADR-0015](../../docs/adr/0015-transfers-on-two-wallet-feeds.md).

**Tests**: included. The spec's Invariants table names every test file (constitution P-16 rule 3). Write each story's tests first and watch them fail.

**Organization**: grouped by user story, in delivery order:

- US1: record an expense or an income (P1)
- US2: move money between two of my wallets (P1)
- US3: correct, delete and restore safely (P1)
- US4: browse and find transactions (P2)
- US5: the ledger behind the screens (P2)

**Conventions for every task** (as in specs 007–011):

- Run through the container: `dev exec -- vendor/bin/pest <path> --compact`, `dev <artisan command>`.
- P-02 layers: everything lives in `app/Modules/Wallets` under a `Transactions` sub-namespace; ports in `Domain/Transactions/Ports`; Infrastructure depends only on Domain; controllers pass `(string) $request->user()->getAuthIdentifier()` to Application services. The only code outside Wallets is the shared `Money` value object (T003) and the `ErrorCode` case (T002). The verification gate is the middleware alias `address.verified`.
- Refusals extend `Shared\Domain\Errors\Refusal` with a catalogue `ErrorCode`, following `Wallets\Domain\Refusals\*` (`WalletVersionConflict` carries `current` through a presenter closure). State-rule refusals are collected into one `422 VALIDATION_FAILED` with one `errors[]` entry each.
- Writes follow spec 010 exactly: `version` in the JSON body (also on DELETE); identical write → `200` with no bump; tombstone → `409` with `current` for any non-delete, non-restore write; stale → `409`; precedence of spec §6.
- Request rules reuse `App\Http\Api\Rules\MinorUnits`, `IsoCurrency` and `NotBlank`; unknown members → `UNKNOWN_FIELD`; `date` → `DATE_NOT_CALENDAR_DATE` when it carries a time or offset.
- Instants written with microseconds (`format('Y-m-d H:i:s.uP')`); empty `meta` is `(object) []`.
- **Lock order** (spec FR-011, ADR-0014 rule 10, research R-4): category rows (share, ascending id) first, then wallet rows (update, ascending id). Never lock a category after a wallet.
- Balances are **recomputed** from opening balance + Σ postings (`WalletRepository::refreshBalance`), never adjusted by a delta (ADR-0005, spec 010 R-2).
- No amount, description, memo or counterparty in any log line (P-15 rule 4).
- Run `dev exec -- vendor/bin/pint --dirty --format agent` after PHP edits.
- Contract tests use `assertValidRequest()` and `assertValidResponse($status)`, covering every documented status.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on unfinished tasks)
- **[Story]**: US1–US5 from spec.md

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: the documents the code depends on, the new field code, the Money value object and the two tables.

- [X] T001 [P] Docs before code uses the terms. In `docs/glossary.md`: the **Transaction** entry — Notes say it carries a calendar `date`, a positive `amount_minor` with its `currency`, an **optional** `description` (≤ 100 characters), an optional `memo` (≤ 1000), an optional **counterparty** (free-text person name, for debts, ≤ 100), a `cleared` flag and `created_by` (never changes); add **transfer** ("a Transaction whose two postings both reference wallets: money out of `wallet_id` into `counter_wallet_id`; one record, listed in both wallets and carried on both wallet feeds, ADR-0015") and **counter wallet** (`counter_wallet_id`, a transfer's destination; immutable); state `kind` is immutable. The **Wallet** entry — **currency locked** becomes "derived, never stored: true once any transaction, live or tombstoned, posts or posted to the wallet". In `.specify/memory/constitution.md` → **v1.3.5 (PATCH)**: P-04's and P-07's `ADRs:` lines gain `[ADR-0015](../../docs/adr/0015-transfers-on-two-wallet-feeds.md)`; Sync Impact Report entry ("PATCH: P-04 and P-07 list ADR-0015, introduced by spec 012; no rule changes"); version line, footer, Constitution Check template version and Last Amended. ADR-0015 and its index row already exist (design phase).
- [X] T002 [P] Add the field code **`CURRENCY_MISMATCH`** in one change (research R-14; the parity tests need all of these together): in `docs/openapi/error-catalog.yaml` add it to the `FieldErrorCode` enum and to `x-error-catalog` as `status: 422`, `level: [field]`, `title: 'Currency mismatch'`, `meaning: 'The currency differs from the wallet''s, or a transfer joins wallets of different currencies. Cross-currency transactions are not supported.'`, `retryable: after-change`, `since: v1.5`; add the case to `app/Modules/Shared/Domain/Errors/ErrorCode.php` (field-level only) with status, title and retry class; add `fields.CURRENCY_MISMATCH` sentences to `lang/en/problems.php` and `lang/ar/problems.php`; list it among the field codes in `docs/openapi/components/responses.yaml` `UnprocessableEntity`; in `docs/openapi/paths/transactions.yaml` replace the header paragraph "One new field-level code is introduced by this file … CATEGORY_IN_USE)." with one line "Field code CURRENCY_MISMATCH is new in v1.5." Run `dev exec -- vendor/bin/pest tests/Contract/OpenApi --compact` — green.
- [X] T003 [P] Create the shared **`Money`** value object in `app/Modules/Shared/Domain/Money/Money.php` (P-03 rule 1, ADR-0003 rules 1, 5): immutable, `int $amountMinor` plus `Currency`; `plus`, `minus`, `negate`, `isZero`, `equals`; arithmetic between different currencies throws `CurrencyMismatch` (a `LogicException` in the same namespace). No division, no rounding, no `allocate` yet (B7 adds it). Test in `tests/Unit/Shared/MoneyTest.php`: sums, negation, mismatch throws, bounds ±(2^53 − 1).
- [X] T004 Create `app/Modules/Wallets/Infrastructure/Database/Migrations/2026_10_03_000001_create_transactions_table.php` per data-model.md: `id uuid PK`; `wallet_id uuid FK → wallets`; `kind varchar(8)` CHECK in (`income`,`expense`,`transfer`); `date date` CHECK `date BETWEEN '1900-01-01' AND '2999-12-31'`; `amount_minor bigint` CHECK `amount_minor > 0 AND amount_minor <= 9007199254740991`; `currency char(3)`; `category_id uuid NULL FK → categories`; `counter_wallet_id uuid NULL FK → wallets`; CHECK "`(kind = 'transfer') = (category_id IS NULL)`", CHECK "`(kind = 'transfer') = (counter_wallet_id IS NOT NULL)`", CHECK "`counter_wallet_id IS NULL OR counter_wallet_id <> wallet_id`"; `description varchar(100) NULL`, `memo varchar(1000) NULL`, `counterparty varchar(100) NULL` (each CHECK `x IS NULL OR btrim(x) <> ''`); `cleared boolean default false`; `created_by uuid` (no FK across modules); `version integer` CHECK ≥ 1; `change_seq bigint`; `counter_change_seq bigint NULL` CHECK `(counter_wallet_id IS NULL) = (counter_change_seq IS NULL)`; `timestampsTz(6)`; `deleted_at timestamptz(6) NULL`; indexes `(wallet_id, date DESC, id)`, `(wallet_id, created_at, id)`, `(counter_wallet_id, date DESC, id) WHERE counter_wallet_id IS NOT NULL`, `(wallet_id, change_seq)`, `(counter_wallet_id, counter_change_seq) WHERE counter_wallet_id IS NOT NULL`, `(category_id)`, `(created_by) WHERE deleted_at IS NULL`.
- [X] T005 Create `app/Modules/Wallets/Infrastructure/Database/Migrations/2026_10_03_000002_create_postings_table.php`: `id uuid PK`; `transaction_id uuid FK → transactions ON DELETE CASCADE`; `wallet_id uuid NULL FK → wallets`; `category_id uuid NULL FK → categories`; CHECK "`(wallet_id IS NULL) <> (category_id IS NULL)`" (ADR-0012 rule 3); `amount_minor bigint` CHECK `<> 0`; `currency char(3)`; indexes `(wallet_id)`, `(category_id)`, `(transaction_id)`. No timestamps, no sync members (never exposed). Depends on T004. `tests/Arch/MigrationsTest.php` must stay green (no float/decimal).

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: domain types, ports, persistence, the real ledger answers and the HTTP skeleton every story uses.

### Tests first

- [X] T006 [P] `tests/Unit/Wallets/Transactions/PostingDerivationTest.php` and `tests/Unit/Wallets/Transactions/TransactionRulesTest.php`: derivation per data-model.md table (expense: wallet −a, category +a; income: category −a, wallet +a; transfer: source −a, destination +a), every result sums to zero in one currency and each line has exactly one target; `TransactionKind` has exactly `income`, `expense`, `transfer`; `TransactionRules` refuses a kind change (`IMMUTABLE_FIELD` at `/kind`), a changed counter wallet (`IMMUTABLE_FIELD` at `/counter_wallet_id`), a currency other than the wallet's (`CURRENCY_MISMATCH` at `/currency`), a transfer between currencies (`CURRENCY_MISMATCH` at `/counter_wallet_id`), a category on a transfer and a counter wallet on income/expense (`INVALID_ENUM_VALUE`), a missing category or counter wallet (`REQUIRED`); collects several refusals at once.
- [X] T007 [P] `tests/Feature/Wallets/Transactions/PostingConstraintTest.php`: a posting with both or neither target, a zero amount, a transaction with a category on a transfer, a counter wallet equal to its wallet, a non-positive amount, a date in 1899 each fail at the database; valid rows insert.

### Implementation

- [X] T008 [P] Domain types in `app/Modules/Wallets/Domain/Transactions/`: `TransactionKind` enum; `TransactionRecord` (readonly: every column of data-model.md, `isTombstone()`, `isTransfer()`, `walletIds()`); `PostingLine` (wallet id or category id, `Money`); `PostingDerivation::for(TransactionRecord): list<PostingLine>` asserting the zero sum; `TransactionRules` (immutables, kind/category/counter shape, currency) returning `list<FieldError>`. No framework imports.
- [X] T009 [P] Refusals in `app/Modules/Wallets/Domain/Transactions/Refusals/`: `TransactionNotFound` (404 `NOT_FOUND`), `TransactionVersionConflict` (409, carries `current`), `TransactionStateInvalid` (422 `VALIDATION_FAILED` with the collected `FieldError`s for `IMMUTABLE_FIELD`, `CURRENCY_MISMATCH`, `INVALID_ENUM_VALUE`, `REQUIRED`).
- [X] T010 [P] Ports in `app/Modules/Wallets/Domain/Transactions/Ports/`: `TransactionRepository` (`find`, `findForUpdate`, `isHeldByOther(tid, pathWalletId)`, `insert`, `updateIfVersion`, `tombstone`, `restore`, `anyInWalletUsing(walletId, categoryId, exceptId)`, `listLive(...)`, `liveTouching(walletId)`), `PostingRepository` (`replaceFor(transactionId, list<PostingLine>)`, `removeFor(transactionId)`).
- [X] T011 [P] Domain events in `app/Modules/Wallets/Domain/Events/`: `TransactionPosted` (event id, occurred at, transaction id, wallet id, counter wallet id|null, kind, date, previous date|null, category id|null, previous category id|null, actor user id, change `recorded`|`amended`|`restored`) and `TransactionVoided` (event id, occurred at, transaction id, wallet id, counter wallet id|null, kind, date, category id|null, actor user id), both `ShouldDispatchAfterCommit`, identifiers and scalars only (P-11), like `WalletDeleted`.
- [X] T012 Models and factory: `app/Modules/Wallets/Infrastructure/Models/{Transaction,Posting}.php`, `app/Modules/Wallets/Infrastructure/Database/Factories/TransactionFactory.php` (states `expense`, `income`, `transfer`, `tombstoned`; postings derived through `PostingDerivation`). Depends on T004, T005, T008.
- [X] T013 Repositories `app/Modules/Wallets/Infrastructure/Repositories/{EloquentTransactionRepository,EloquentPostingRepository}.php` implementing T010; `findForUpdate` uses `lockForUpdate`; `isHeldByOther` per research R-10 (a wallet, any category, a category reference, a membership, or a transaction touching neither `wallet_id` nor `counter_wallet_id` = the path wallet). Bind both in `WalletsPersistenceServiceProvider`. Depends on T012.
- [X] T014 Replace the stand-ins (research R-3, R-12): `app/Modules/Wallets/Infrastructure/Ledger/EloquentPostingTotals.php` (`sumFor` = Σ postings of the wallet; `hasAny` = any transaction, **live or tombstoned**, with `wallet_id` or `counter_wallet_id` = the wallet) and `app/Modules/Wallets/Infrastructure/Ledger/EloquentTransactionQueryService.php` (`hasLiveTransactionsRecordedBy` = any live transaction with `created_by` = the user); rebind `PostingTotals` and `TransactionQueryService` in `WalletsServiceProvider`/`WalletsPersistenceServiceProvider`; delete `app/Modules/Wallets/Application/Provisional/`. Update the `PostingTotals` docblock (no longer "until B6"). Existing `tests/Feature/Wallets/BalanceCacheTest.php` and profile tests stay green.
- [X] T015 `TransactionService` skeleton in `app/Modules/Wallets/Application/Transactions/TransactionService.php` with the write algorithm of research R-4 (unlocked read → lock categories share ascending via `CategoryRepository::findForShare`/`findPlatformForShare` → lock wallets ascending via `WalletRepository::findForUpdate` → re-read `findForUpdate` → restart on drift, at most 3 times, then `409` with `current`), the authorization of research R-6 (`WalletAuthorizer::authorize` with `Permission::T02WriteTransactions` on the path wallet; `T03Transfer` on the transfer's other live wallet the caller is a member of — the destination, or the source when the path names the destination), the precedence of spec §6, and the shared tail: write row → `PostingRepository::replaceFor`/`removeFor` → recompute every touched wallet with `refreshBalance(w, PostingTotals::sumFor(w))` → stamp sequences per research R-5 (transaction `change_seq` from `wallet_id`, `counter_change_seq` from the counter wallet, each touched wallet one final `nextFor`) → dispatch events. DTOs `Application/Transactions/Dto/{TransactionView,SavedTransaction}.php`. Depends on T008–T014.
- [X] T016 HTTP skeleton: routes in `app/Modules/Wallets/Http/routes.php` (`GET wallets/{id}/transactions`, `GET wallets/{id}/transactions/{tid}` under `auth:api`; `PUT`, `PATCH`, `DELETE wallets/{id}/transactions/{tid}` also under `address.verified`), `app/Modules/Wallets/Http/Controllers/TransactionController.php` (empty actions), `app/Modules/Wallets/Http/Resources/TransactionResource.php` (exactly the members of `Transaction` in `docs/openapi/paths/transactions.yaml`; never `postings`, never `counter_change_seq`), `app/Modules/Wallets/Http/Requests/TransactionId.php` (UUIDv7 path check → `422 INVALID_ID`).

**Checkpoint**: migrations run, `PostingDerivationTest`, `TransactionRulesTest`, `PostingConstraintTest` green; existing Wallets and Identity suites green with the stand-ins replaced.

---

## Phase 3: User Story 1 - Record an expense or an income (Priority: P1) 🎯 MVP

**Goal**: a writer records income and expense offline-first; the balance follows in the same unit of work.

**Independent Test**: record an expense with a client id, replay it, read the wallet (one transaction, balance lowered); record an income (balance raised).

### Tests for User Story 1

- [X] T017 [P] [US1] `tests/Feature/Wallets/Transactions/RecordTransactionTest.php`: US1 S1–S3 (create at version 1, kind, balance moves once; replay → no second row, no version/`change_seq`/balance move); `created_by` is the recorder; text trimmed, blank → null; archived wallet and archived category accepted.
- [X] T018 [P] [US1] `tests/Feature/Wallets/Transactions/BalanceRecomputeTest.php` and `tests/Feature/Wallets/Transactions/LedgerIntegrityTest.php`: after every create/edit/delete/restore the cached balance equals opening + Σ postings for every touched wallet; a scan finds exactly two postings per live transaction, none per tombstone, each summing to zero; `wallets:verify-balances` finds no drift; the wallet's `change_seq` is the highest in its scope and its `version` unchanged.
- [X] T019 [P] [US1] `tests/Feature/Wallets/Transactions/TransactionCategoryTest.php`: own and platform categories accepted; another person's, deleted, unknown and wrong-kind categories → one `INVALID_ENUM_VALUE` at `/category_id`; archived category accepted.
- [X] T020 [P] [US1] `tests/Feature/Wallets/Transactions/TransactionAuthorizationTest.php`: owner and editor may write (T-02), viewer → 403, non-member → 404, every read open to owner/editor/viewer (T-01); rows asked through `WalletAuthorizer`, never a role comparison.
- [X] T021 [P] [US1] `tests/Contract/Wallets/Transactions/UpsertTransactionTest.php`: `201`, `200` replay, `422` (`AMOUNT_NOT_INTEGER` for `"25.00"` and `25.5`, `OUT_OF_RANGE` for 0 and −1, `CURRENCY_MISMATCH` at `/currency`, `INVALID_CURRENCY`, `DATE_NOT_CALENDAR_DATE`, `UNKNOWN_FIELD` for `postings`/`wallet_id`/`created_by`/`change_seq`, `REQUIRED`, `INVALID_ID`), `403`, `404` (non-member; id held by another wallet's transaction, a wallet, a category; version > 0 for an unknown id), `409` stale — each `assertValidRequest()`/`assertValidResponse($status)`.
- [X] T022 [P] [US1] `tests/Contract/Wallets/Transactions/TransactionVerificationGateTest.php` (PUT, PATCH, DELETE → `403 EMAIL_NOT_VERIFIED`; GETs allowed) and `tests/Contract/Wallets/Transactions/NoPostingsExposedTest.php` (no response of any transaction or wallet operation contains `postings` or `counter_change_seq`; with `Log::spy()`, no log context of a create, edit or refusal contains the amount, description, memo or counterparty — spec FR-026).

### Implementation for User Story 1

- [X] T023 [US1] `app/Modules/Wallets/Http/Requests/SaveTransactionRequest.php`: `TransactionWrite` shape verbatim from the path file — all of `kind`, `date`, `amount_minor`, `currency`, `category_id`, `counter_wallet_id`, `description`, `memo`, `counterparty`, `cleared` required (nullable ones may be null), `version` ≥ 0 optional; `amount_minor` integer 1..9007199254740991 (`MinorUnits` + `OUT_OF_RANGE`); `date` `YYYY-MM-DD` 1900-01-01..2999-12-31; `description` ≤ 100, `memo` ≤ 1000, `counterparty` ≤ 100 Unicode characters; anything else `UNKNOWN_FIELD`.
- [X] T024 [US1] `TransactionService::save` create and whole-save paths for income and expense (research R-7, R-8): category rules (own or platform, live, same kind; unchanged category exempt on edit), `CategoryReferences::publish(wallet, category)` for a person's category after the wallet lock, postings derived, balance recomputed, `TransactionPosted(recorded)` after commit; replay detection before the stale check.
- [X] T025 [US1] `TransactionController::upsert` (201/200, `meta` `(object) []`), problem mapping for the refusals of T009. Run T017–T022 — green.

**Checkpoint**: US1 delivers the core loop for one wallet.

---

## Phase 4: User Story 2 - Move money between two of my wallets (Priority: P1)

**Goal**: one transfer record moves two balances together and shows in both wallets.

**Independent Test**: record a transfer between two same-currency wallets; both balances move; both lists show the one record.

### Tests for User Story 2

- [X] T026 [P] [US2] `tests/Feature/Wallets/Transactions/RecordTransferTest.php`: US2 S1, S3–S6 — one row, two wallet postings, both balances recomputed, `change_seq` from the source and `counter_change_seq` from the destination (each the highest-but-one in its scope), cross-currency → `CURRENCY_MISMATCH` at `/counter_wallet_id`, viewer of the destination → 403, non-member/unknown/deleted/same wallet → one `INVALID_ENUM_VALUE` at `/counter_wallet_id`, category on a transfer refused, delete removes both effects; archived destination accepted.
- [X] T027 [P] [US2] `tests/Contract/Wallets/Transactions/TransferTest.php`: create `201`; read and PATCH under the destination's path; destination change → `422 IMMUTABLE_FIELD` at `/counter_wallet_id`; the listed refusals with valid problem bodies.

### Implementation for User Story 2

- [X] T028 [US2] Extend `TransactionService` for transfers (research R-1, R-5, R-6): counter wallet resolved and locked in ascending order with the source, `T03Transfer` on it, currency equality with both wallets, `counter_change_seq` stamped, both balances recomputed; refusals per spec §5 field table.
- [X] T029 [US2] Either-path addressing: a transfer is found under `wallet_id` or `counter_wallet_id` (spec FR-016) in `TransactionRepository::find`/`isHeldByOther` and the controller; a non-transfer only under its own wallet. Run T026–T027 — green.

---

## Phase 5: User Story 3 - Correct, delete and restore safely (Priority: P1)

**Goal**: edits, deletes and restores obey versions, never merge, and keep every balance exact.

**Independent Test**: edit with the version (balance follows), edit stale (409 with current), delete (balance returns), restore (balance moves again).

### Tests for User Story 3

- [X] T030 [P] [US3] `tests/Feature/Wallets/Transactions/SaveTransactionTest.php`: US3 S1–S4, S7 — whole save and PATCH re-derive postings and recompute; stale → 409 with `current`; identical → nothing moves (no version, `change_seq`, balance, reference, event); kind change refused; editor keeps another member's category on an amount edit, refused when switching to a third person's.
- [X] T031 [P] [US3] `tests/Feature/Wallets/Transactions/DeleteRestoreTransactionTest.php`: US3 S5–S6 — delete tombstones, removes postings, recomputes; delete of a tombstone returns it unchanged; edit of a tombstone → 409 with the tombstone; restore re-derives at version + 1 and re-checks rules; restore of a live record → 200 unchanged; `created_by` never changes.
- [X] T032 [P] [US3] `tests/Feature/Wallets/Transactions/TransactionEventsTest.php`: `TransactionPosted` (`recorded`, `amended` with previous date and category, `restored`) and `TransactionVoided` dispatched after commit only, none on a replay or a rolled-back write, payload ids and scalars only (US3 S8).
- [X] T033 [P] [US3] `tests/Feature/Wallets/Transactions/TransactionLockOrderTest.php`: concurrent category rename (spec 011 `CategoryService`) and transaction write in a shared wallet, and two writes to the same transfer from its two paths, complete without deadlock (two PostgreSQL connections, as spec 011's lock-order test does); the reference ends with the newest name; one writer gets 409.
- [X] T034 [P] [US3] `tests/Contract/Wallets/Transactions/PatchTransactionTest.php` and `tests/Contract/Wallets/Transactions/DeleteTransactionTest.php`: PATCH edit `200`, restore `200`, `REQUIRED` at `/version` and at `""`, `UNKNOWN_FIELD` for `kind`/`currency`/`counter_wallet_id` and for a member beside `deleted_at`, `INVALID_TYPE` for a non-null `deleted_at`, `404` unknown id, `409` stale and tombstone; DELETE `200` tombstone (twice), `409` stale, `422` missing version.

### Implementation for User Story 3

- [X] T035 [US3] `TransactionService::patch` and the edit half of `save`: immutables (`kind`, `counter_wallet_id`, `currency`), category change publishes the new reference and calls `CategoryReferences::withdrawIfUnused` for the old one only when `TransactionRepository::anyInWalletUsing` finds no other live or tombstoned transaction (research R-8), `TransactionPosted(amended)` with previous date and category.
- [X] T036 [US3] `TransactionService::delete` (tombstone, `PostingRepository::removeFor`, recompute, `TransactionVoided`; tombstone → returned unchanged) and `TransactionService::restore` (re-check rules against the stored record, re-derive, recompute, `TransactionPosted(restored)`; live → unchanged).
- [X] T037 [US3] `app/Modules/Wallets/Http/Requests/{PatchTransactionRequest,RestoreTransactionRequest,TransactionVersionRequest}.php` and `TransactionController::patch` (dispatch to restore when the body has a `deleted_at` member) and `::destroy`. Run T030–T034 — green.

---

## Phase 6: User Story 4 - Browse and find transactions (Priority: P2)

**Goal**: a wallet's history, newest first, filterable and searchable, keyset-paged.

**Independent Test**: page through a mixed wallet; each filter and the search return exactly the matching rows; no skips or repeats.

### Tests for User Story 4

- [X] T038 [P] [US4] `tests/Contract/Wallets/Transactions/ListTransactionsTest.php`: live only, transfers into the wallet included, default `-date` stabilised by `id`, `sort=created_at`; filters `date` (eq/gt/gte/lt/lte), `category_id` (eq/in, 51 values → `400 INVALID_QUERY_PARAMETER`), `kind` (eq/in); `search` literal and case-insensitive over description/memo/counterparty (`%` matches itself), blank or > 100 → 400; unsupported field/operator/sort → the matching 400; cursor reused with another filter → `INVALID_CURSOR`; non-member → 404; `meta` shape.
- [X] T039 [P] [US4] `tests/Contract/Wallets/Transactions/GetTransactionTest.php`: live and tombstoned reads by members (owner, editor, viewer); transfer read under both paths; another wallet's transaction, another kind's id, non-member, deleted path wallet → 404.

### Implementation for User Story 4

- [X] T040 [US4] `app/Modules/Wallets/Application/Transactions/TransactionListQuery.php` (`list`, `find`; `T01ReadTransactions`) and `EloquentTransactionRepository::listLive` per research R-11 (one query over `wallet_id = w OR counter_wallet_id = w`, keyset on (sort value, id), `ILIKE … ESCAPE '\'` search, cursor bound to filters/sort/search), plus `app/Modules/Wallets/Http/Requests/TransactionListRequest.php` enforcing `x-filterable`, operators, `x-sortable`, `search` and the 50-value `in` bound.
- [X] T041 [US4] `TransactionController::index` and `::show`. Run T038–T039 — green.

---

## Phase 7: User Story 5 - The ledger behind the screens (Priority: P2)

**Goal**: every obligation specs 008, 010 and 011 left for B6 is answered by the real ledger.

**Independent Test**: after a transaction, the wallet's currency is locked, the category is in use, the default currency is locked, the reference is published; a wallet deletion leaves other wallets untouched.

### Tests for User Story 5

- [X] T042 [P] [US5] `tests/Feature/Wallets/Transactions/CurrencyLockTest.php` (US5 S1: `currency_locked` true once any live or tombstoned transaction touches the wallet, as source or destination; wallet PUT/PATCH currency → `IMMUTABLE_FIELD` at `/currency`) and `tests/Feature/Wallets/Transactions/DefaultCurrencyLockTest.php` (US5 S3: profile `default_currency_locked` while a live transaction recorded by the person exists; unlocks when all are deleted).
- [X] T043 [P] [US5] `tests/Feature/Wallets/Transactions/TransactionCategoryUsageTest.php` (US5 S2: `in_use` true for categories used by live or tombstoned transactions; `usedAmong` for a page of 200 runs a constant number of queries; `DELETE /categories/{id}` → `409 CATEGORY_IN_USE`) and `tests/Feature/Wallets/Transactions/TransactionCategoryReferencesTest.php` (US1 S7, US5 S4: published once per (wallet, person's category), none for platform, kept while any live or tombstoned transaction in the wallet uses it, withdrawn on the last edit away).
- [X] T044 [P] [US5] `tests/Feature/Wallets/Transactions/TransactionWalletDeletionTest.php` (FR-015): deleting a wallet tombstones its own income/expense transactions and transfers whose other wallet is already deleted; leaves transfers to a live wallet live with their postings and that wallet's balance unchanged; such a transfer can be deleted through the live wallet and is refused on edit/restore (`INVALID_ENUM_VALUE` at `/counter_wallet_id`); a transaction id written to `/wallets/{tid}` or `/categories/{tid}` → 404.

### Implementation for User Story 5

- [X] T045 [US5] `app/Modules/Wallets/Application/Transactions/TransactionCategoryUsage.php` implementing `CategoryUsage` (`isUsed`, set-wise `usedAmong` with one `ANY(?)` query, tombstones included), tagged `CategoryUsage::class` in `WalletsServiceProvider`.
- [X] T046 [US5] `app/Modules/Wallets/Application/Transactions/TransactionsGoWithWallet.php` implementing `WalletDeletionParticipant` per research R-9, tagged beside `ReferencesGoWithWallet`; it locks no other wallet and dispatches no transaction event (spec FR-027).
- [X] T047 [US5] Extend `EloquentWalletRepository::isHeldByOther` and `EloquentCategoryRepository::isHeldByOther` to include transaction ids (research R-10). Run T042–T044 — green.

---

## Phase 8: Polish & Cross-Cutting Concerns

- [X] T048 [P] Apply the endpoint review checklist (`docs/api-conventions.md` appendix) and the security review checklist (C1–C2, D1, E1–E6) to every operation in `docs/openapi/paths/transactions.yaml`; record the results in plan.md § Review checklists. Confirm the implementation matches the frozen contract exactly — any change to a member the C6 client binds to (contracts/transactions-api.md table) is a contract change and must be raised, not made.
- [X] T049 [P] Record B6's obligations on later features under a short "Inherited from B6 (spec 012)" note in `specs/briefs/B07-recurring-transactions.md` (create transactions through `TransactionService`; lock order), `specs/briefs/B08-budgets.md` (listen to `TransactionPosted`/`TransactionVoided` and `WalletDeleted`; re-cut both dates on a backdated edit; exclude transfers; platform totals scoped and grouped by `system_key`), `specs/briefs/B14-sync-protocol.md` (the Transaction resource is the feed row; ADR-0015 two-feed selection by `change_seq`/`counter_change_seq`), and `specs/briefs/B15-export-data-deletion.md` (transactions kept, `created_by` anonymised).
- [X] T050 Run the gates: `dev exec -- composer test:lint`, `test:types`, `test:arch`, `test:suite`, `test:api` (or `composer test` with the raised timeout) and the quickstart manual walk; seed a wallet with 10,000 transactions and record the p95 of a filtered and a searched list page (`limit=50`) and of a single write on the dev stack with Xdebug off (spec SC-007, plan target 300 ms / 150 ms); re-bundle `docs/openapi` and import into Apidog (project 1332822, module 1363204) with a "Transactions" doc. State "Seeder: N/A — no reference data" in the commit/PR description.
- [ ] T051 Write `specs/012-transactions-ledger/summary.md` (what was built, results, assumed defaults, remaining risks), as specs 010 and 011 did. (Done by the parent session.)

---

## Dependencies & Execution Order

- **Setup (T001–T005)**: T001, T002, T003 independent; T004 → T005.
- **Foundational (T006–T016)**: depends on T003–T005; T009 depends on T002 (the field code). T008–T011 in parallel; T012 → T013 → T014; T015 needs T008–T014; T016 independent of T015. Blocks every story.
- **US1 (T017–T025)**: depends on Foundational.
- **US2 (T026–T029)**: depends on US1 (extends the same service and controller).
- **US3 (T030–T037)**: depends on US1; T033's transfer case also on US2.
- **US4 (T038–T041)**: depends on Foundational and US1 (data to list); independent of US2/US3 except transfers-in, which needs US2.
- **US5 (T042–T047)**: depends on US1 (T042–T043) and US2 (T044's transfer cases).
- **Polish (T048–T051)**: after all stories; T051 last.

## Parallel Execution Examples

- Setup: T001, T002, T003 together; then T004 → T005.
- Foundational: T006, T007, T008, T009, T010, T011 together; then T012 → T013 → T014, alongside T016; then T015.
- US1 tests: T017–T022 together, then T023 → T024 → T025.
- After US1: US2 tests (T026, T027), US3 tests (T030–T032, T034) and US4 tests (T038, T039) can all be written together.
- US5 tests T042–T044 together, then T045, T046, T047 in parallel (different files).

## Implementation Strategy

1. **MVP** = Setup + Foundational + US1: income and expense recorded offline-first with exact balances — the C6 entry screen can run against live data.
2. Add US2 (transfers) and US3 (edit, delete, restore): the full write contract C14's sync queue relies on.
3. Add US4 (list, search) and US5 (inherited obligations), then Polish. Each phase ends at a green checkpoint and can be committed on its own on `main`.
