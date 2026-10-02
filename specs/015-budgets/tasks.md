---

description: "Task list for Budgets (B8)"
---

# Tasks: Budgets

**Input**: Design documents from `/specs/015-budgets/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/budgets-api.md](./contracts/budgets-api.md), [quickstart.md](./quickstart.md), the frozen contract `docs/openapi/paths/budgets.yaml` (written and indexed; `info.version` v1.8; additive `budget` types in `sync.yaml`).

**Tests**: included. The spec's Invariants table names every test file (constitution P-16 rule 3). Write each story's tests first and watch them fail.

**Organization**: grouped by user story, in delivery order:

- US1: set a monthly limit for a category (P1)
- US2: see how far into my budgets I am (P1)
- US3: budgets in a shared wallet stay personal (P1)
- US4: change, archive or delete a budget (P2)
- US5: Budget Periods follow my salary day (P2)
- US6: budgets sync like every user record (P2)

**Conventions for every task** (as in specs 010–014):

- Run through the container: `dev exec -- vendor/bin/pest <path> --compact`, `dev <artisan command>`.
- P-02 layers: Budgeting code lives in `app/Modules/Budgeting/{Domain,Application,Infrastructure,Http}`; ports in `Domain/Ports`; Infrastructure depends only on Domain. Budgeting reaches other modules **only** through their Application layer: Wallets `SpendingQueryService`, `BudgetCategoryCheck`, `CategoryUsage`; Identity `BudgetStartDayQuery`, `UserScopeCounter`, `TombstonePurge::raiseHorizon`. Controllers pass `(string) $request->user()->getAuthIdentifier()`. The verification gate is the middleware alias `address.verified`.
- Names follow the glossary: "Budget", "Budget Period", "Budget Progress"; never the bare word "Period" as a class or column (`BudgetPeriod`, `period_type`), never "limit", "cap" or "envelope" as an entity name.
- Refusals extend `Shared\Domain\Errors\Refusal` with a catalogue `ErrorCode` (`BudgetNotFound` 404; `BudgetVersionConflict` 409 with `current`; `BudgetStateInvalid` 422 collecting `FieldError`s). **No new error code.**
- Writes follow spec 011 exactly: `version` in the JSON body (also on DELETE and archive); identical write → `200` with no bump; tombstone → `409` with `current` for any non-delete write; stale → `409`; precedence of spec §6.
- **Lock order** (research R-5, ADR-0017 rule 5): category share lock (create only) → `UserScopeCounter::next` (user row) → budget row `FOR UPDATE`. A replay or conflict found under the locks rolls the transaction back so the counter has no hole.
- Money: integers only; no `/`, `intdiv`, `round`, `floor` or `ceil` on an amount outside `Money::allocate` (ADR-0003 rule 7). Never read `balance_minor`.
- Instants written with microseconds; empty `meta` is `(object) []`. No amount in any log line (P-15).
- Run `dev exec -- vendor/bin/pint --dirty --format agent` after PHP edits.
- Contract tests use `assertValidRequest()` and `assertValidResponse($status)`, covering every documented status.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on unfinished tasks)
- **[Story]**: US1–US6 from spec.md

---

## Phase 1: Setup (docs, module, schema)

- [X] T001 [P] Docs before code uses the terms. Write `docs/adr/0019-budget-spending-by-author-computed-on-read.md` (Accepted; extends ADR-0011 and ADR-0014): attribution by `created_by` in wallets the owner currently belongs to; one currency per budget, no cross-currency sum; progress computed on read from postings, no cache, no listeners; `projected_end` by `Money::allocate`, no percentage; alternatives from research R-1, R-2, R-4. Add its row to `docs/adr/README.md`. `.specify/memory/constitution.md` → **v1.3.8 (PATCH)**: P-12's `ADRs:` line gains ADR-0019; Sync Impact Report entry ("PATCH: P-12 lists ADR-0019, introduced by spec 015; no rule changes"); version line, footer, Constitution Check template version, Last Amended. `docs/glossary.md`: Budget entry — replace the "Open … not decided here" paragraph with spec 015's answers (attribution by `created_by` in current wallets; one immutable currency; expense categories or all expenses; overlap allowed; archived category keeps counting; progress served by Budgeting, computed on read; no notifications); fields `category_id` (nullable), `period_type`, `amount_minor`, `currency`, `archived_at`; new **Budget Progress** entry (Budgeting, derived read model, never stored); Profile notes gain the **budget start day history**; Budget Period notes gain the boundary rule (FR-008). `docs/api-conventions.md`: batch paragraph names the `budget` type. `docs/security-baseline.md`: verification-gate table gains the budget writes; protected attributes gain budget limit and progress (owner only, not logged). `specs/README.md`: the six banked Budgeting questions marked answered by spec 015.
- [X] T002 [P] Module skeleton `app/Modules/Budgeting/`: `Application/BudgetingServiceProvider.php` (binds services, tags `BudgetCategoryUsage` as `App\Modules\Wallets\Application\Categories\CategoryUsage`), `Infrastructure/BudgetingPersistenceServiceProvider.php` (binds `Domain\Ports\BudgetRepository` → `EloquentBudgetRepository`), `Http/BudgetingHttpServiceProvider.php` (loads `Http/routes.php` under `api/v1` with the shared runtime middleware, binds `Application\Sync\FeedPresenter`); register all three in `bootstrap/providers.php` after the Wallets providers. `tests/Arch/ModuleBoundariesTest.php` already names Budgeting — keep it green.
- [X] T003 Migration `app/Modules/Budgeting/Infrastructure/Database/Migrations/2026_10_06_000001_create_budgets_table.php` per data-model.md: `id uuid` PK; `user_id uuid` (no FK across modules); `category_id uuid NULL` (no FK across modules); `period_type varchar(8)` CHECK `period_type IN ('monthly')`; `amount_minor bigint` CHECK `amount_minor BETWEEN 1 AND 9007199254740991`; `currency char(3)` CHECK `currency ~ '^[A-Z]{3}$'`; `archived_at timestamptz(6) NULL`; `version integer` CHECK `version >= 1`; `change_seq bigint` CHECK `change_seq >= 1`; `timestampsTz(6)`; `deleted_at timestamptz(6) NULL`. Indexes `budgets_user_change_seq_index (user_id, change_seq)`, `budgets_user_live_index (user_id, created_at, id) WHERE deleted_at IS NULL`, `budgets_category_index (category_id) WHERE category_id IS NOT NULL`. Model `Infrastructure/Models/Budget.php` (UUID key, `immutable_datetime` casts) and `Infrastructure/Database/Factories/BudgetFactory.php` (states `allExpenses`, `archived`, `deleted`). `tests/Arch/MigrationsTest.php` stays green (no money float/decimal).
- [X] T004 [P] Migration `app/Modules/Identity/Infrastructure/Database/Migrations/2026_10_06_000002_create_budget_start_days_table.php` per data-model.md: `id uuid` PK; `user_id uuid` FK → `users` cascade on delete; `start_day smallint` CHECK `start_day BETWEEN 1 AND 28`; `applies_from date NULL`; `created_at timestamptz(6)`; unique `(user_id, applies_from)`; partial unique `(user_id) WHERE applies_from IS NULL`. Backfill in the same migration: for each profile with `budget_period_start_day_from` set, insert (`1`, null) and (`budget_period_start_day`, `budget_period_start_day_from`); nothing for the others.
- [X] T005 [P] Migration `app/Modules/Wallets/Infrastructure/Database/Migrations/2026_10_06_000003_add_expense_author_index_to_transactions.php`: `CREATE INDEX transactions_expense_author_date_index ON transactions (created_by, date) WHERE deleted_at IS NULL AND kind = 'expense'` (down drops it).

---

## Phase 2: Foundational (blocking)

### Tests

- [X] T006 [P] `tests/Unit/Budgeting/BudgetPeriodsTest.php` (FR-008, US5 S1–S3): no entries/implicit (1, null) → calendar months; (25, null) and 2026-10-02 → 2026-09-25..2026-10-24; entries [(1, null), (25, 2026-10-25)] → 2026-10-12 → 2026-10-01..2026-10-24, 2026-10-30 → 2026-10-25..2026-11-24, 2026-09-15 → 2026-09-01..2026-09-30; a transitional period longer than a month ([(25, null), (10, 2026-11-10)] with 2026-10-30 → 2026-10-25..2026-11-09); year boundary (day 25, 2026-12-30 → 2026-12-25..2027-01-24); February with day 28; `daysInPeriod`, `contains`, `daysElapsed` (null outside).
- [X] T007 [P] `tests/Unit/Budgeting/BudgetProgressTest.php` (FR-012, FR-014, FR-015): spent from lines filtered by category (or all) and currency; `other_currencies` sorted, distinct, excluding the budget's; `remaining` negative when over; `over_limit` strictly `spent > limit` (at limit → false); `projected_end` first day = spent × days, last day = spent, mid-period rounded up (350000, 30 days, 8 elapsed → 1312500; 100001, 31, 3 → 1033344), null outside the current period, null when `spent × days_in_period > 9007199254740991`; zero spending → 0 and remaining = limit; KWD (exponent 3) amounts untouched.
- [X] T008 [P] `tests/Unit/Budgeting/BudgetRulesTest.php` (FR-002, FR-006): changed `category_id`, `currency`, `period_type` → `IMMUTABLE_FIELD` at each pointer, collected together; unchanged members pass; `period_type` other than `monthly` → `INVALID_ENUM_VALUE`.

### Implementation

- [X] T009 [P] Domain value types in `app/Modules/Budgeting/Domain/`: `PeriodType` enum (`Monthly = 'monthly'`); `BudgetRecord` (readonly: id, userId, categoryId ?string, periodType, amountMinor, currency, archivedAt, version, changeSeq, createdAt, updatedAt, deletedAt; `isTombstoned()`, `isArchived()`); `StartDayEntry` (startDay 1–28, appliesFrom ?string YYYY-MM-DD); `BudgetPeriod` (start, end; `daysInPeriod()`, `contains(string)`, `daysElapsed(string $today): ?int`); `SpendingLine` (categoryId, currency, amountMinor). Pure PHP, `DateTimeImmutable` on dates only.
- [X] T010 `app/Modules/Budgeting/Domain/BudgetPeriods.php` (`containing(string $date, list<StartDayEntry> $entries, int $fallbackDay): BudgetPeriod`) implementing research R-3; `app/Modules/Budgeting/Domain/BudgetProgress.php` (`of(BudgetRecord, BudgetPeriod, list<SpendingLine>, string $today): self`) implementing FR-014/FR-015 with `Shared\Domain\Money\Money` and `allocate`; `app/Modules/Budgeting/Domain/BudgetRules.php` (immutables). Run T006–T008 — green.
- [X] T011 Ports and refusals: `app/Modules/Budgeting/Domain/Ports/BudgetRepository.php` (`find`, `findForUpdate`, `create`, `save`, `listLive(userId, after, limit)`, `listActive(userId, after, limit)`, `changedFor(userId, after, limit)`, `usedAmong(list)`, `isUsed`, `purgeTombstonesBefore(before, batch)`); `Domain/Refusals/{BudgetNotFound,BudgetVersionConflict,BudgetStateInvalid}.php`. `app/Modules/Budgeting/Infrastructure/Repositories/EloquentBudgetRepository.php` (rows → `BudgetRecord`; keyset on (`created_at`, `id`); `FOR UPDATE` via `lockForUpdate`).
- [X] T012 [P] Wallets application additions (Wallets owns its tables): `app/Modules/Wallets/Application/Transactions/SpendingQueryService.php` with `expensesRecordedBy(string $userId, string $from, string $to): list<Dto\SpendingTotal>` (the SQL of plan design note 3, through a new port method on the transaction repository implemented in `EloquentTransactionRepository`; `SUM(...)::bigint`; postings only, never `balance_minor`), and `app/Modules/Wallets/Application/Transactions/Dto/SpendingTotal.php`; `app/Modules/Wallets/Application/Categories/BudgetCategoryCheck.php` with `lockEligible(string $userId, string $categoryId): void` — share-locks through `CategoryRepository::findForShare` / `findPlatformForShare`, requires `kind = expense`, live (archived/retired allowed), owner = user or platform; otherwise throws `Wallets\Domain\Categories\Refusals\CategoryNotEligible` (new refusal class, mapped by Budgeting to `INVALID_ENUM_VALUE` at `/category_id`; no new code). Must run inside the caller's transaction (assert `DB::transactionLevel() > 0`).
- [X] T013 [P] Identity application additions: `app/Modules/Identity/Domain/Ports/BudgetStartDayHistory.php` (`entriesOf(userId)`, `record(userId, int $previousDay, int $newDay, string $appliesFrom, string $today)`), Eloquent implementation in `app/Modules/Identity/Infrastructure/Repositories/`; `app/Modules/Identity/Application/Profiles/BudgetStartDayQuery.php` with `entriesOf(string $userId): list<array{start_day: int, applies_from: ?string}>`, `currentDayOf(string $userId): int` and `timezoneOf(string $userId): string` (profile timezone, else `config('identity.default_timezone')`; day 1 without a profile).

---

## Phase 3: User Story 1 - Set a monthly limit for a category (Priority: P1) 🎯 MVP

**Goal**: create and read budgets, offline-first, owner only.

**Independent Test**: create a budget with a client id; read it back; replay it; foreign ids and unverified writers refused.

### Tests for User Story 1

- [X] T014 [P] [US1] `tests/Feature/Budgeting/SaveBudgetTest.php` (create part; US1 S1–S3): create → version 1, `change_seq` from the owner's counter (`users.change_seq` moved by one); replay → nothing moves; `category_id: null` accepted; version > 0 for an unknown id → `BudgetNotFound`; a budget in a currency the owner holds no wallet in (USD) is accepted (FR-004); two live budgets on the same category and currency both persist (FR-005); no domain event is dispatched by any write (`Event::fake`, FR-022).
- [X] T015 [P] [US1] `tests/Feature/Budgeting/BudgetCategoryRulesTest.php` (US1 S4–S5, FR-003): own expense, platform expense, own archived, retired platform → created; income, partner's, unknown, deleted, a transaction's id → `INVALID_ENUM_VALUE` at `/category_id`, same answer; a category deleted concurrently (two connections) → exactly one of {category deleted, budget created} wins and the other sees it.
- [X] T016 [P] [US1] `tests/Contract/Budgeting/UpsertBudgetTest.php`, `tests/Contract/Budgeting/GetBudgetTest.php`, `tests/Contract/Budgeting/ListBudgetsTest.php`, `tests/Contract/Budgeting/BudgetVerificationGateTest.php`, `tests/Contract/Budgeting/BudgetAccessTest.php` (US1 S6–S7, FR-017): every documented status of `upsertBudget`, `getBudget`, `listBudgets` (201, 200 replay, 400, 401, 403 `EMAIL_NOT_VERIFIED`, 404 foreign/other-entity id, 406, 409, 415, 422 `INVALID_ID`, `VALIDATION_FAILED` with `REQUIRED`, `UNKNOWN_FIELD` (`rollover`, `user_id`), `INVALID_TYPE`, `AMOUNT_NOT_INTEGER`, `OUT_OF_RANGE` (0, 2^53), `INVALID_CURRENCY` (`egp`), `INVALID_ENUM_VALUE` (`weekly`), 426, 500); list paging, `sort=created_at`, unsupported filter/sort → 400; tombstone read by id; another person's budget → 404 for every operation; reads allowed unverified.

### Implementation for User Story 1

- [X] T017 [US1] `app/Modules/Budgeting/Application/BudgetService.php` `save(string $userId, string $budgetId, array $values, int $version)` per plan design note 1 and spec §6 precedence (create and whole save), returning `SavedBudget` DTO (`record`, `created`); `app/Modules/Budgeting/Application/BudgetQueryService.php` `list` and `find` (owner only; tombstone included in `find`).
- [X] T018 [US1] HTTP: `app/Modules/Budgeting/Http/Requests/SaveBudgetRequest.php` (rules quoting `budgets.yaml`: `category_id` nullable UUID, `period_type` in [`monthly`], `amount_minor` integer 1..9007199254740991, `currency` `^[A-Z]{3}$` and known ISO 4217, `version` integer ≥ 0; unknown members → `UNKNOWN_FIELD`), `BudgetListRequest.php`; `Http/Resources/BudgetResource.php` (members exactly as the `Budget` schema); `Http/Controllers/BudgetController.php` (`index`, `show`, `update`); routes in `Http/routes.php`: `GET /budgets`, `GET /budgets/{id}`, and `PUT /budgets/{id}` inside `Route::middleware('address.verified')`. Map `CategoryNotEligible` → `BudgetStateInvalid` at `/category_id`. Run T014–T016 — green.
- [X] T019 [US1] `app/Modules/Budgeting/Application/BudgetCategoryUsage.php` implementing `CategoryUsage` (live and tombstoned budgets; `usedAmong` in one query) — tagged in T002; test `tests/Feature/Budgeting/BudgetCategoryUsageTest.php` (US4 S6): budgeted category `in_use: true`, `DELETE /categories/{id}` → `409 CATEGORY_IN_USE`, set-wise in a constant number of queries, a tombstoned budget still counts.

---

## Phase 4: User Story 2 - See how far into my budgets I am (Priority: P1)

**Goal**: progress computed on read for one budget and for every active budget.

**Independent Test**: record expenses and read progress: spent, remaining, over, projected, other currencies.

### Tests for User Story 2

- [X] T020 [P] [US2] `tests/Feature/Budgeting/BudgetSpendingTest.php` (US2 S1–S4, S6, FR-010–FR-013): own expenses in category, currency and period summed; excluded — transfer, income, deleted transaction, outside the period, other category, other currency (named in `other_currencies`), scheduled and skipped occurrences; all-expenses budget sums every expense category, own and platform; backdated edit and re-categorisation reflected on both periods at once; restore counts again; a corrupted `wallets.balance_minor` changes nothing; deleting a wallet removes its transactions from `spent`; one spending query per progress page (query count); no log record written during a progress read or a budget write contains `amount_minor`, `spent` or any amount value (log capture, FR-024).
- [X] T021 [P] [US2] `tests/Contract/Budgeting/GetBudgetProgressTest.php` and `tests/Contract/Budgeting/ListBudgetProgressTest.php` (US2 S5, FR-014, FR-016): every documented status (200, 400 malformed/out-of-range `date`, 401, 404 tombstone/foreign/unknown, 406, 422 `INVALID_ID`, 426, 500); archived omitted from the list but served by id; default `date` = owner's today; paging with the cursor keeping its Budget Period, a cursor sent with a `date` in another period → `400 INVALID_CURSOR`; unsupported filter/sort → 400; `meta` collection members.

### Implementation for User Story 2

- [X] T022 [US2] `BudgetQueryService::progressList(string $userId, ?string $date, ?string $cursor, int $limit)` and `progressOf(string $userId, string $budgetId, ?string $date)` per plan design note 2 (today from `BudgetStartDayQuery::timezoneOf`; period from `BudgetPeriods` with `entriesOf`/`currentDayOf`; one `SpendingQueryService` call per request; the list cursor encodes the resolved period start and the keyset).
- [X] T023 [US2] HTTP: `Http/Requests/BudgetProgressRequest.php` (`date` `Y-m-d`, 1900-01-01..2999-12-31 else `INVALID_QUERY_PARAMETER`; collection params for the list), `Http/Resources/BudgetProgressResource.php` (members exactly as `BudgetProgress`; Money as `{amount_minor, currency}`), `Http/Controllers/BudgetProgressController.php` (`index` for `GET /budget-progress`, `show` for `GET /budgets/{id}/progress`); routes (reads, not gated). Run T020–T021 — green.

---

## Phase 5: User Story 3 - Budgets in a shared wallet stay personal (Priority: P1)

**Goal**: attribution by author in the owner's current wallets.

**Independent Test**: two members, each with a budget on the same platform category, see only their own spending.

### Tests for User Story 3

- [X] T024 [P] [US3] `tests/Feature/Budgeting/BudgetSharedWalletTest.php` (US3 S1–S4, ADR-0014 rule 5): two members' budgets on the platform Groceries category each count only their own expense; a partner re-categorising my transaction removes it from my category budget, keeps it in my all-expenses budget, and adds it to none of theirs; after I leave (membership tombstoned) my transactions in that wallet stop counting; a viewer-role member's own earlier transactions still count; a recurring rule's transaction confirmed by another member counts for the rule's author.

### Implementation for User Story 3

- [X] T025 [US3] Confirm the spending query's membership and author joins against T024 (no new code expected beyond T012); fix `SpendingQueryService` if any case fails. Run T024 — green.

---

## Phase 6: User Story 4 - Change, archive or delete a budget (Priority: P2)

**Goal**: full write discipline.

**Independent Test**: save, stale save, immutable member, archive, delete, edit tombstone.

### Tests for User Story 4

- [X] T026 [P] [US4] `tests/Feature/Budgeting/SaveBudgetTest.php` (edit part; US4 S1–S5): whole save and PATCH bump version; stale → `BudgetVersionConflict` with current; tombstone → conflict even when identical; immutables refused; archive sets `archived_at`, repeat unchanged, archived limit still editable; delete tombstones, repeat returns tombstone whatever version; no restore path; every write stamps the owner's counter and a rolled-back conflict leaves no counter hole; no domain event dispatched (`Event::fake`, FR-022).
- [X] T027 [P] [US4] `tests/Contract/Budgeting/PatchBudgetTest.php`, `tests/Contract/Budgeting/DeleteBudgetTest.php`, `tests/Contract/Budgeting/ArchiveBudgetTest.php`: every documented status of `patchBudget`, `deleteBudget`, `archiveBudget` (PATCH of an unknown id → 404; `category_id` in a PATCH → `UNKNOWN_FIELD`; body without `version` → `REQUIRED`; 409 with `current`; 403 `EMAIL_NOT_VERIFIED`; 405 with `Allow` for an unsupported method on each path).
- [X] T028 [P] [US4] `tests/Feature/Budgeting/BudgetLockOrderTest.php` (§4, ADR-0017 rule 5): a budget create on a person's category concurrent with a rename of that category (which republishes references under wallet locks then stamps the user) and with a category write by the same person complete without deadlock (two real connections, as spec 013's concurrency tests).

### Implementation for User Story 4

- [X] T029 [US4] `BudgetService::patch`, `archive`, `delete` (same precedence; archive and delete with `{version}`); `Http/Requests/{PatchBudgetRequest,BudgetVersionRequest}.php`; controller actions `patch`, `archive`, `destroy`; routes `PATCH|DELETE /budgets/{id}`, `POST /budgets/{id}/archive` inside `address.verified`. Run T026–T028 — green.

---

## Phase 7: User Story 5 - Budget Periods follow my salary day (Priority: P2)

**Goal**: start day history in Identity; periods and "today" end to end.

**Independent Test**: change the start day and read progress for dates before, during and after the change.

### Tests for User Story 5

- [X] T030 [P] [US5] `tests/Feature/Identity/BudgetStartDayHistoryTest.php` (FR-007): first change from an empty history records (old day, null) then (new day, `…_from`); an unchanged day writes nothing; a second change while the first is pending deletes the pending entry; history and profile written in one transaction (a failed profile save leaves no entry); backfill migration produces (1, null) + (day, `…_from`) for changed profiles and nothing for others; `BudgetStartDayQuery::timezoneOf` falls back to `identity.default_timezone`.
- [X] T031 [P] [US5] `tests/Feature/Budgeting/BudgetPeriodTimezoneTest.php` (US5 S2, S4–S5, FR-009): owners in `Africa/Cairo` and `America/New_York` count a 2026-09-30 transaction in the same period; the default period follows each owner's "today" at a frozen instant near midnight; the US5 S2 dates through `GET /budgets/{id}/progress?date=` after a real `PUT /me/profile`.

### Implementation for User Story 5

- [X] T032 [US5] `app/Modules/Identity/Application/Profiles/SaveProfile.php`: when `budget_period_start_day` changes, call `BudgetStartDayHistory::record(...)` inside the existing transaction with the person's today in the saved timezone (FR-007); no change to the profile contract. Run T030–T031 — green; `tests/Contract/Identity/ProfileTest.php` stays green.

---

## Phase 8: User Story 6 - Budgets sync like every user record (Priority: P2)

**Goal**: user feed rows, batch items, purge.

**Independent Test**: batch upsert and delete a budget; read both rows on the user feed; purge an old tombstone.

### Tests for User Story 6

- [X] T033 [P] [US6] `tests/Contract/Sync/BudgetFeedTest.php` (US6 S3, US3 S5, FR-019): `budget` rows on `GET /sync/user/changes` in position order with profiles, categories and memberships; each equal to `GET /budgets/{id}`; archive and delete appear; no `budget` row on any wallet feed.
- [X] T034 [P] [US6] `tests/Contract/Sync/BudgetBatchParityTest.php` (US6 S1–S2, FR-020): `budget` `upsert` and `delete` items give the single write's status and body (201, 200, 409, 422, 404); a `budget` item with `wallet_id` or op `restore` → envelope 422.
- [X] T035 [P] [US6] `tests/Feature/Sync/BudgetPurgeTest.php` (US6 S4, FR-021): a budget tombstone older than 90 days is removed and the owner's `purged_change_seq` raised; a younger one kept; a category used only by the purged budget becomes deletable and is purged in the same run if itself an old tombstone (budget step runs first).

### Implementation for User Story 6

- [X] T036 [US6] Feed: `app/Modules/Budgeting/Application/Sync/{FeedPresenter,FeedRecord,UserFeedQuery}.php` (rows by `change_seq`, at most `limit + 1`); `app/Modules/Budgeting/Http/BudgetFeedPresenter.php` using `BudgetResource`; `app/Modules/Sync/Application/ChangeFeedService.php` `userPage` adds Budgeting's source.
- [X] T037 [US6] Batch: `app/Modules/Sync/Domain/Batch/ItemType.php` gains `Budget = 'budget'` (`Upsert`, `Delete`), user-scoped (no `wallet_id`); `app/Modules/Sync/Http/RouterItemDispatcher.php` routes it to `PUT|DELETE /budgets/{id}`; envelope validation refuses `wallet_id` on it.
- [X] T038 [US6] Purge: `app/Modules/Budgeting/Application/Sync/TombstonePurge.php` (batches of 500; raises horizons through Identity's `TombstonePurge::raiseHorizon`, users ascending); `app/Modules/Sync/Application/TombstonePurgeRun.php` calls it before Wallets' purge and prefixes its counts `budgeting_`. Run T033–T035 — green.

---

## Phase 9: Polish & Cross-Cutting Concerns

- [X] T039 [P] Apply the endpoint review checklist and the security review checklist to every operation in `docs/openapi/paths/budgets.yaml` (and the changed parts of `sync.yaml`); record results in plan.md § Review checklists → Results, with the exceptions already listed there. Confirm the implementation matches the frozen contract member for member — any change to a member C8 binds to (contracts/budgets-api.md) is a contract change to raise, not to make.
- [X] T040 [P] Record B8's obligations on later features as "Inherited from B8 (spec 015)" notes: `specs/briefs/B13-reports.md` (budget-vs-actual through `BudgetQueryService`; same attribution, currency and Budget Period rules; never sum currencies), `specs/briefs/B15-export-data-deletion.md` (export budgets; purge them and `budget_start_days` with the account), `specs/briefs/B16-devices-push.md` (`BudgetExceeded` and overrun push are B16's; progress is computed on read, so detection needs its own trigger — no stored "notified" state exists).
- [X] T041 Performance (SC-005), dev stack with Xdebug off: seed 50 budgets and 20,000 in-period expenses for one owner; measure p95 of `GET /budget-progress` (< 300 ms), `GET /budgets` and `GET /budgets/{id}` (< 150 ms); record results in plan.md. If the progress target fails, record it and raise it — do not add a cache without a decision record.
- [X] T042 Run the gates: `dev exec -- composer test` (lint, types, arch, suite, api) and the quickstart walk. State "Seeder: N/A — no reference data" in the commit/PR description.
- [X] T043 Apidog sync: bundle with `npx @redocly/cli bundle docs/openapi/openapi.yaml -o <scratchpad>/bundle.json`; import with `apidog import --project 1332822 --format openapi --file <scratchpad>/bundle.json --module-map "Personal finance backend API=1363204"`; create or update the project doc "Budgets" with the C8 client notes (contracts/budgets-api.md "What C8 binds to": attribution, currency, Budget Period boundaries, progress fields, offline estimate, sync types). Record the import counts and doc id for the summary.
- [X] T044 Write specs/015-budgets/summary.md (parent writes it)

---

## Dependencies & Execution Order

- **Setup (T001–T005)**: all independent except T003 after T002 (module directory).
- **Foundational (T006–T013)**: unit tests T006–T008 first; T009 → T010 → T011; T012 and T013 in parallel with T009–T011. Blocks every story.
- **US1 (T014–T019)**: depends on Foundational.
- **US2 (T020–T023)**: depends on US1 (budgets to measure).
- **US3 (T024–T025)**: depends on US2.
- **US4 (T026–T029)**: depends on US1; independent of US2/US3.
- **US5 (T030–T032)**: T030 depends only on T004 and T013; T031 depends on US2.
- **US6 (T033–T038)**: depends on US1 and US4 (delete); T037 touches the Sync module.
- **Polish (T039–T044)**: after all stories; T043 after T042; T044 last.

## Parallel Execution Examples

- Setup: T001, T002, T004, T005 together; then T003.
- Foundational: T006–T008 together (pure units); T012 ∥ T013 ∥ T009.
- US1 tests T014–T016 together, then T017 → T018 → T019.
- After US1: US2 tests (T020–T021), US4 tests (T026–T028) and US5's T030 together.
- US6 tests T033–T035 together, then T036, T037, T038.

## Implementation Strategy

1. **MVP** = Setup + Foundational + US1 + US2: create budgets and see progress — the C8 editor and progress screen run against live data.
2. Add US3 (shared-wallet attribution proven), US4 (edits), US5 (start day history), US6 (feed, batch, purge), then Polish. Each phase ends at a green checkpoint and can be committed on its own on `main`.
