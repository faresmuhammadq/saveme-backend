# Implementation Plan: Budgets

**Branch**: `015-budgets` (trunk-based; work lands on `main`) | **Date**: 2026-10-02 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/015-budgets/spec.md`.

## Summary

Create the Budgeting module (`app/Modules/Budgeting`, the constitution's third context) with the user-scoped, syncable **Budget**, the **Budget Period** arithmetic and **Budget Progress** computed on read.

- **Records** (data-model.md): `budgets` (client-created, syncable, user scope) in Budgeting; `budget_start_days` (server-internal start day history) in Identity, written by `SaveProfile` and backfilled; one partial index on `transactions` for the spending query (Wallets).
- **Spending** (R-1, R-2): a new Wallets & Ledger application query, `SpendingQueryService::expensesRecordedBy(userId, from, to)`, returns Σ category-leg postings grouped by (category, currency) for the person's own live expenses in wallets where they hold a live membership. One query per request answers every budget on the page, because all of a person's budgets share one Budget Period for a given date.
- **Periods** (R-3): pure domain `BudgetPeriods` over the start day history; "today" in the owner's profile timezone (`identity.default_timezone` without a profile).
- **Progress** (R-4): pure domain `BudgetProgress` computes `remaining`, `over_limit` and `projected_end` (via `Money::allocate()`, largest share) — no `/`, no rounding (ADR-0003 rule 7).
- **Writes** (R-5): `BudgetService` with spec 011's discipline (version in the body, replay, tombstone conflict, immutable members, archive, final delete); lock order category share-lock → user counter → budget row (ADR-0017 rule 5).
- **Inherited obligations**: `BudgetCategoryUsage` participant (set-wise); `budget` user-feed rows; `budget` batch item type (`upsert`, `delete`); purge step for budget tombstones run before Wallets' (R-6, R-7).
- **No cache, no events, no listeners** (R-1): every read re-derives from postings, so `TransactionPosted`/`TransactionVoided`/`WalletDeleted` need no Budgeting listener.
- **API**: eight operations in `docs/openapi/paths/budgets.yaml` (written, indexed, examples, `info.version` v1.8 in this phase), additive `sync.yaml` changes (`budget` feed type and batch item type); frozen for C8. No new error code.
- **Docs** (T001): ADR-0019, constitution PATCH v1.3.8, glossary (Budget answers, Budget Progress, start day history), api-conventions (batch type list), security baseline (verification gate rows, budget attributes), brief B16 "Inherited from B8" note, specs/README banked questions marked answered.

## Technical Context

**Language/Version**: PHP 8.5, Laravel 13.

**Primary Dependencies**: none new.

**Storage**: PostgreSQL 16. New `budgets` (Budgeting) and `budget_start_days` (Identity); new partial index `transactions (created_by, date) WHERE deleted_at IS NULL AND kind = 'expense'`. CHECKs for amount range, currency, period type, start day.

**Testing**: Pest.
- Unit: `BudgetPeriods` (default day 1, day 25, a change mid-period, a pending change replaced, leap years, year boundary), `BudgetProgress` (remaining, over, at limit, projection first/last day, rounding up, overflow → null), `BudgetRules` (immutable members), `PeriodType`.
- Feature: save/patch/archive/delete/replay/conflict/tombstone; category eligibility (expense, own or platform, archived allowed, income/foreign/deleted refused) under the share lock; spending (own vs partner, kinds, currencies, periods, deleted transactions, deleted wallet, left wallet, viewer role, recurring author, corrupted `balance_minor`); period timezone; start day history (Identity) and backfill; category usage; lock order; purge.
- Contract: eight operations with every documented status (Spectator); verification gate; access; feed rows and batch parity.
- Arch: existing gates (Budgeting already named in `ModuleBoundariesTest`).

**Target Platform**: the Linux server (the `dev` container).

**Project Type**: modular-monolith JSON API.

**Performance Goals**: progress list p95 < 300 ms for 50 budgets over 20,000 of the owner's in-period transactions; budget list and reads p95 < 150 ms (SC-005).

**Constraints**: P-02 (Budgeting reads Wallets and Identity only through their Application layers), P-03 (bigint, no cross-currency sum, allocate only), P-05 (postings, never `balance_minor`), P-06 (client UUIDv7, foreign id 404), P-07 (version, tombstones, no merge), P-09 rule 5 (no entitlement), P-11 (no events), P-12 (calendar dates; profile timezone only for "today"), P-15 (no amounts logged), ADR-0014 rules 5 and 10, ADR-0016, ADR-0017 rule 5.

**Scale/Scope**:
- 8 operations (5 path entries), 3 migrations (Budgeting 1, Identity 1, Wallets 1 index), no seeder (no reference data);
- about 30 Budgeting classes; Wallets: `SpendingQueryService`, `BudgetCategoryCheck`; Identity: start day history repository and query; Sync: item type, dispatcher map, feed source, purge step;
- about 22 test files;
- doc edits listed in the Summary.

## Constitution Check

*Constitution v1.3.7 (`.specify/memory/constitution.md`). GATE: must pass before Phase 0 research. Re-check after Phase 1 design, keeping both results.*

### Pre-design

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | "Budget", "Budget Period" (never bare "Period": the query parameter is `date`, the column `period_type`), "Budget Progress" added to the glossary in T001 before code; services `BudgetService`, `BudgetQueryService` as the glossary's naming rule shows; "limit" appears only as prose for `amount_minor`, never as an entity name (glossary bans limit/cap/envelope as synonyms of Budget) |
| P-02 Module boundaries | PASS | All Budgeting code in `app/Modules/Budgeting`; spending through Wallets' Application `SpendingQueryService`; category eligibility through Wallets' Application `BudgetCategoryCheck`; timezone and start day history through Identity's Application `BudgetStartDayQuery`; user stamp through Identity's `UserScopeCounter`; Sync composes Budgeting's Application feed and purge |
| P-03 Money | PASS | `amount_minor bigint`, `currency char(3)`; spending summed per currency and never across; `projected_end` by `Money::allocate()` only; no percentage |
| P-04 Ledger integrity | PASS | Reads postings through the ledger's query service; writes none; postings never exposed (the response has totals only) |
| P-05 Derived balances | PASS | Totals from postings; `balance_minor` never read (corrupted-cache test) |
| P-06 Identifiers | PASS | `PUT /budgets/{id}` upsert with client UUIDv7; id held by another person or another entity → 404 |
| P-07 Sync and concurrency | PASS | Five sync members + `user_id`; `version` in every body (PUT, PATCH, DELETE, archive); 409 with `current` incl. tombstone; no merge; delete is a tombstone, final |
| P-08 Authorization | PASS | User-scoped: owner only, everyone else 404; no wallet role involved, so the matrix is not consulted or restated; the spending query is restricted to wallets the owner currently belongs to |
| P-09 Entitlements | PASS | No capability or quota (rule 5) |
| P-10 Billing channels | N-A | No billing surface |
| P-11 Events | PASS | No event published or consumed; progress re-derives on read |
| P-12 Time | PASS | Period dates are calendar dates; membership by transaction `date`; profile timezone only for "today" (ADR-0011 rule 8); a start day change rewrites no stored date (history is appended, periods begun keep their dates) |
| P-13 Naming | PASS | No codename |
| P-14 Localisation | PASS | No new user-facing string; Western numerals in JSON integers |
| P-15 Observability | PASS | No new log line; amounts never logged |
| P-16 Quality gates | PASS | Every spec §4 invariant names its test; PostgreSQL only (partial indexes, `FOR SHARE`) |
| P-17 Scope guard | PASS | No rollover, no envelope allocations, no FX, no savings goals, no push |
| P-18 Delivery surfaces | PASS | Every capability is a `/api/v1` endpoint; no back-office screen |

### Post-design re-check

Unchanged: all rows PASS or N-A.

- **CONTRIBUTING item 18**: ADR-0019 (written in T001) records attribution by `created_by` in the owner's current wallets, one currency per budget, and progress computed on read. It **extends** ADR-0011 (rule 7's "identical totals for every member" is satisfied because a budget's figure depends on its owner, never on the viewer, and membership is by calendar date) and ADR-0014 rule 5 (a platform category's budget total is scoped to one person's own recorded transactions). It contradicts no rule. Constitution PATCH v1.3.8: P-12's `ADRs:` line gains ADR-0019.
- The brief's "cache invalidated by ledger events" and the inherited "listen to `TransactionPosted`…" notes are satisfied by computing on read (R-1); recorded as A-7.
- No new error code; `info.version` v1.8.

Complexity Tracking stays empty.

## Project Structure

### Documentation (this feature)

```text
specs/015-budgets/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/budgets-api.md
├── checklists/requirements.md
├── checklists/budgets.md        # /speckit-checklist
└── tasks.md                     # /speckit-tasks
```

### Source Code (repository root)

```text
app/Modules/Budgeting/
├── Domain/                          # BudgetRecord, PeriodType, BudgetPeriod, BudgetPeriods,
│                                    # StartDayEntry, BudgetProgress, SpendingLine, BudgetRules,
│                                    # Ports/BudgetRepository, Refusals/
├── Application/                     # BudgetService (save, patch, archive, delete),
│                                    # BudgetQueryService (list, find, progressList, progressOf),
│                                    # BudgetCategoryUsage, BudgetingServiceProvider,
│                                    # Sync/{UserFeedQuery,FeedPresenter,FeedRecord,TombstonePurge}, Dto/
├── Infrastructure/                  # Models/Budget, Database/Migrations, Database/Factories/BudgetFactory,
│                                    # Repositories/EloquentBudgetRepository, BudgetingPersistenceServiceProvider
└── Http/                            # Controllers/, Requests/, Resources/{BudgetResource,BudgetProgressResource},
                                     # BudgetFeedPresenter, BudgetingHttpServiceProvider, routes.php
app/Modules/Wallets/Application/Transactions/SpendingQueryService.php   # + Dto/SpendingTotal
app/Modules/Wallets/Application/Categories/BudgetCategoryCheck.php
app/Modules/Wallets/Infrastructure/Database/Migrations/…_add_expense_author_index_to_transactions.php
app/Modules/Identity/Application/Profiles/BudgetStartDayQuery.php
app/Modules/Identity/Application/Profiles/SaveProfile.php                # + history entry
app/Modules/Identity/Domain/Ports/BudgetStartDayHistory.php
app/Modules/Identity/Infrastructure/…                                    # migration + backfill, repository
app/Modules/Sync/Domain/Batch/ItemType.php                               # + Budget
app/Modules/Sync/Http/RouterItemDispatcher.php                           # + budget routes
app/Modules/Sync/Application/{ChangeFeedService,TombstonePurgeRun}.php   # + Budgeting source, purge step
bootstrap/providers.php                                                  # + 3 Budgeting providers

tests/Unit/Budgeting/, tests/Feature/Budgeting/, tests/Feature/Identity/BudgetStartDayHistoryTest.php,
tests/Feature/Sync/BudgetPurgeTest.php, tests/Contract/Budgeting/, tests/Contract/Sync/Budget{Feed,BatchParity}Test.php
```

**Structure Decision**: a new module directory, as P-02 and the constitution's context table name it; its layout mirrors `Wallets` (persistence provider in Infrastructure, application provider in Application, HTTP provider in Http). `ModuleServiceProvider` loads its migrations and factories by convention.

## Design notes

1. **Write algorithm** (`BudgetService`): validate shape (Form Request) → read stored budget unlocked → precedence checks that need no lock (foreign id 404, unknown id with version > 0 404) → `DB::transaction`: on create, `BudgetCategoryCheck::lockEligible(userId, categoryId)` (share-locks a person's category with `findForShare`, platform with `findPlatformForShare`; throws `CategoryNotEligible` → `INVALID_ENUM_VALUE /category_id`) → `UserScopeCounter::next(userId)` (user row lock) → budget row `FOR UPDATE` → re-check tombstone / replay / version / immutables under the lock; a replay or a conflict throws out of the transaction so the stamp rolls back (spec 013 implementation default 26) → write with the stamp. An identical replay found before the transaction answers without opening one.
2. **Progress** (`BudgetQueryService::progressList(userId, ?date, cursor, limit)`): `tz = BudgetStartDayQuery::timezoneOf(userId)`; `today` = now in `tz`; `D = date ?? today`; `period = BudgetPeriods::containing(D, history)`; page live, non-archived budgets by (`created_at`, `id`); `lines = SpendingQueryService::expensesRecordedBy(userId, period.start, period.end)` once; per budget `BudgetProgress::of(budget, period, lines, today)`. `progressOf(id)` is the same for one budget (archived allowed, tombstone → `BudgetNotFound`).
3. **Spending SQL** (Wallets infrastructure, behind the query service): `SELECT p.category_id, p.currency, SUM(p.amount_minor)::bigint FROM postings p JOIN transactions t ON t.id = p.transaction_id JOIN wallets w ON w.id = t.wallet_id AND w.deleted_at IS NULL JOIN wallet_members m ON m.wallet_id = t.wallet_id AND m.user_id = :user AND m.deleted_at IS NULL WHERE t.created_by = :user AND t.deleted_at IS NULL AND t.kind = 'expense' AND t.date BETWEEN :from AND :to AND p.category_id IS NOT NULL GROUP BY p.category_id, p.currency`. Uses the new partial index. A sum beyond 2^63 − 1 raises a PostgreSQL error (unreachable in practice: 2^53 per transaction).
4. **Feed**: Budgeting's `UserFeedQuery::page(userId, after, limit)` returns `budget` rows by `change_seq`; `ChangeFeedService::userPage` adds it as a third source. Budget rows are stamped only from the owner's counter.
5. **Batch**: `ItemType::Budget` (`upsert`, `delete`), no `wallet_id`; `RouterItemDispatcher` maps to `PUT|DELETE /budgets/{id}`.
6. **Purge**: Budgeting's `TombstonePurge::purge(before)` deletes budget tombstones older than the horizon in batches of 500, raising the owner's horizon through Identity's `TombstonePurge::raiseHorizon` (user row lock); `TombstonePurgeRun` calls it **before** Wallets' purge, so a category freed by a purged budget can go in the same run.
7. **Start day history**: `SaveProfile`, when the day changes, records the day in force as (day, null) if the person has no entry, deletes entries with `applies_from` > the person's today and inserts (`day`, `…_from`), in the same transaction as the profile update; a person with no entry has the implicit entry (current day, null), so registration is untouched; the migration backfills profiles whose `…_from` is set (FR-007).

## Assumed defaults (owner away)

| # | Decision | Why |
|---|---|---|
| A-1 | Attribution by `created_by` (a rule's transactions: its author) | Platform categories have no owner; the phone has `created_by`; a partner's spending never counts (banked Q1) |
| A-2 | Only wallets where the owner currently holds a live membership (any role) count | The phone holds exactly those; role governs writing, not authorship |
| A-3 | One immutable currency per budget; other currencies listed in `other_currencies`, never summed | P-03 rule 3; FX out of scope (banked Q2) |
| A-4 | Expense categories only, or null for all expenses | Income "budgets" are targets, not limits (banked Q3) |
| A-5 | Overlap allowed, no uniqueness | Offline creates on two phones must both sync; no new code (banked Q3) |
| A-6 | Archived category: budget unaffected; archived/retired categories budgetable | Spec 012 precedent; never refuse an offline record (banked Q4) |
| A-7 | Progress computed on read; no cache; no listeners | Never stale; P-05 rule 3; nothing to invalidate or replay (brief's cache deferred) |
| A-8 | Budgeting serves progress (`BudgetQueryService`); Reporting consumes it | Constitution context table (banked Q5) |
| A-9 | No overrun event, threshold or notification; B16 owns `BudgetExceeded` | Delivery needs B16's devices (banked Q6) |
| A-10 | No percentage; `projected_end` by `Money::allocate()`, rounded up, current period only, null on overflow | ADR-0003 rule 7 |
| A-11 | Identity keeps a start day history; pending entries replaced; backfill assumes the prior default 1 | Spec 008 FR-013; pre-launch data |
| A-12 | "Today" = owner's profile timezone; default timezone without a profile | ADR-0011 rule 8; spec 014 A-39 |
| A-13 | `date` query parameter selects the Budget Period; default today | Brief's `?period=` reconciled with the glossary's ban on bare "Period" |
| A-14 | Past periods compare with the current limit | No per-period rows in MVP (glossary) |
| A-15 | Scheduled occurrences never counted or shown | Brief's B7 note; forecast is B13's |
| A-16 | `category_id`, `currency`, `period_type` immutable; only `amount_minor` editable (PUT or PATCH) | A changed meaning would rewrite every past period |
| A-17 | Archive one-way, hides from the progress list; delete final, no restore | Spec 011 precedent |
| A-18 | Paths `/budgets…`, `/budget-progress` (user scope) | Glossary: never in a wallet |
| A-19 | Progress list paged (cursor, limit), sorted by `created_at`, `id`; no filters or sorts | Collection conventions |
| A-20 | Progress of a tombstone → 404; of an archived budget by id → 200 | Nothing to measure for a deleted record |
| A-21 | No rollover member, not even reserved | Glossary "Out of MVP", P-17 |
| A-22 | No new error code, no entitlement, no rate limit, no seeder, no event | Every case maps onto the catalogue; P-09 rule 5 |
| A-23 | Lock order: category share lock → user counter → budget row; replays and conflicts roll the stamp back | ADR-0017 rule 5; spec 013 default 26 |
| A-24 | Budget purge step runs before Wallets' purge | A freed category can go in the same run |
| A-25 | Batch item type `budget` with `upsert` and `delete`; archive per-record only | Spec 013 default 5 |
| A-26 | No log line beyond the request log; amounts never logged | P-15 rule 4 |
| A-27 | ADR-0019 extends ADR-0011 and ADR-0014; constitution PATCH v1.3.8 | CONTRIBUTING item 18 |
| A-28 | The progress list's cursor carries the resolved Budget Period; a `date` in another period with that cursor → `400 INVALID_CURSOR` | One walk, one period, even across midnight (checklist CHK010) |
| A-29 | An archived budget's limit stays editable | Archive hides, it does not freeze (archived categories stay renameable) (CHK018) |
| A-30 | No profile → default timezone and start day 1 | Identity's existing defaults (CHK013) |

## Review checklists (applied at the polish task)

- **Endpoint review checklist** (`docs/api-conventions.md` appendix), expected exceptions:
  - C1–C3 do not apply to the two progress reads (read models, not syncable records) or to `archiveBudget` (a command with a version body, as `archiveCategory`).
  - D (syncable envelope) does not apply to `BudgetProgress` (derived, never stored or synced).
  - G3, G4 do not apply (no idempotency-keyed operation); G5 does not apply (no throttle).
  - I2 is "no" for the feature: five path entries, one per distinct path.
- **Security review checklist**: every write behind the gate; budget limits and progress are financial attributes visible to their owner only, unencrypted like transaction amounts, never logged; a foreign id is 404; the spending query is bound to the caller's own id and live memberships.

### Results (filled at the polish task)

- **Endpoint review checklist** (all eight operations, `docs/openapi/paths/budgets.yaml`, and the `budget` parts of `sync.yaml`): applied with the exceptions listed above (C1–C3 not for the two progress reads and `archiveBudget`; D not for `BudgetProgress`; G3–G5 not applicable; I2 "no", five path entries). Contract tests assert `assertValidRequest()` and `assertValidResponse($status)` for every documented status of every operation (200, 201, 400, 401, 403, 404, 406, 409, 415, 422, 426, 500 where the operation lists it; 405 with `Allow` for the methods a path does not offer). Result: pass, no deviation. The implementation matches the frozen contract member for member; no name, code or shape changed.
- **Security review checklist**: every write is behind `address.verified` (`BudgetVerificationGateTest`); budget limits and progress are returned to the owner only, a foreign id is `404` for every operation (`BudgetAccessTest`); the spending query is bound to the caller's id twice (author and live membership) and to live wallets; no amount in any log line (`BudgetSpendingTest`, log capture); unknown members refused; no new secret, no new error code. Result: pass.

### Performance (SC-005, T041)

Dev stack, Xdebug off (`XDEBUG_MODE=off`; with Xdebug in `develop` mode the same run is about 6× slower, do not measure with it), PostgreSQL 16, one owner with 50 budgets (5 all-expenses) and 20,000 in-period expenses, tables analysed, 40 in-process requests each (`BUDGET_PERF=1 vendor/bin/pest tests/Feature/Budgeting/BudgetProgressPerformanceTest.php`):

| Operation | p95 | Target |
|---|---|---|
| `GET /budget-progress?limit=50` | 221 ms (median 160 ms) | < 300 ms |
| `GET /budgets?limit=50` | 98 ms | < 150 ms |
| `GET /budgets/{id}` | 34 ms | < 150 ms |

The grouped spending query runs in about 137 ms (hash join over all 20,000 postings; it reads every one of the owner's in-period transactions, so the partial index on `(created_by, date)` does not narrow it here; it is what keeps the query cheap when other people's rows share the table). Run before the planner knew the wallet tables' sizes (no `ANALYZE` of `wallets` and `wallet_members`), the planner chose a nested loop and the progress p95 was 388 ms: statistics matter, and production's autovacuum keeps them. The first full read of the page is dominated by that query; if a larger owner ever misses the target, the options are a covering index on `postings (transaction_id) INCLUDE (category_id, currency, amount_minor)` or a cache behind `BudgetQueryService`, each needing its own decision record (research R-1).

## Implementation notes (assumed defaults, owner away)

| # | Decision | Why |
|---|---|---|
| A-31 | `BudgetCategoryCheck::lockEligible` throws `Wallets\Domain\Categories\Refusals\CategoryNotEligible` (code `INVALID_ENUM_VALUE`); `BudgetService` maps it to `BudgetStateInvalid` at `/category_id` | One answer for unknown, foreign, deleted and income categories (FR-003); no new code |
| A-32 | Wallets Application gains `RecordIdentifiers::isTaken(id)` (wallet, category, reference, membership, transaction, rule, occurrence); a budget id any of them holds is `404`. Wallets' own id checks do not know budgets | P-06 rule 3 without Budgeting knowing Wallets' tables; a UUIDv7 collision in the other direction is not designed for |
| A-33 | Writes to an existing budget take the owner's counter first, then the budget row (as the plan's lock order says); the purge therefore raises the owners' horizons (ascending) before it deletes the rows | A purge taking the row first and the user second could deadlock with a write taking them the other way round |
| A-34 | A replay found under the locks leaves the transaction through an internal `Replay` exception, so the stamp rolls back (spec 013 default 26); a replay found before the transaction answers without opening one | No counter hole; no needless user-row lock |
| A-35 | The progress list accepts `sort=created_at` (its default) and refuses `-created_at` with `SORT_FIELD_NOT_SUPPORTED`; the budget list accepts both | The contract declares no sort for the progress list; the shared grammar needs one named field |
| A-36 | An empty `date=` is read as absent (the framework turns empty strings into null before the controller) | The shared pipeline owns that conversion; a client sends no `date` for "today" |
| A-37 | `SaveProfile` computes the person's "today" from the application clock (`now()`) instead of the system clock, so the start day history is testable at a frozen instant | Same value in production; the history's `applies_from` must be reproducible |
| A-38 | The purge run reports the budget step as `budgeting_budgets`; it runs before Wallets' purge | Spec 013 R-11 naming; A-24 |
| A-39 | Budgeting Http has its own `BudgetId` and `Instants` instead of importing Wallets' | P-02: a module never imports another module's Http |
| A-40 | The start day history backfill runs inside its migration, with the prior default day 1 | A-11; pre-launch data, no separate command |
| A-41 | `BudgetProgressPerformanceTest` is opt-in (`BUDGET_PERF=1`), not part of `composer test` | Seeding 20,000 expenses takes seconds and the timing assertion is machine-dependent |
| A-42 | Test helpers `budgetBody`, `budgetValues`, `ownedBudget`, `saveBudget`, `createBudget`, `budgetCall`, `spend`, `budgetProgress`, `nthPlatformCategoryId` are added to `tests/Pest.php` | The convention of specs 010–014 |
| A-43 | `SpendingQueryService` and `RecordIdentifiers` are new Wallets Application services over two new `TransactionRepository` port methods (`expenseTotalsRecordedBy`, `identifierTaken`) | The plan's "new port method on the transaction repository" |
