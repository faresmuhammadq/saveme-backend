---

description: "Task list for Reports (B13)"
---

# Tasks: Reports

**Input**: Design documents from `/specs/019-reports/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/reports-api.md](./contracts/reports-api.md), [quickstart.md](./quickstart.md), the frozen contract `docs/openapi/paths/reports.yaml` (written and indexed; `info.version` v1.11).

**Tests**: included. The spec's Invariants table names every test file (constitution P-16 rule 3). Write each story's tests first and watch them fail.

**Organization**: grouped by user story, in delivery order:

- US1: see this month's summary for one wallet (P1)
- US2: see where money went, by category (P1)
- US3: see the trend over recent months (P2)
- US4: see all my wallets together, in one currency (P2)
- US5: include what is scheduled (P3)

**Conventions for every task** (as in specs 010–018):

- Run through the container: `dev exec -- vendor/bin/pest <path> --compact`, `dev <artisan command>`. Host PHP has no `pdo_pgsql`.
- P-02 layers: Reporting code lives in `app/Modules/Reporting/{Domain,Application,Http}` (no Infrastructure: nothing persisted). Reporting Domain imports only `App\Modules\Shared`. Reporting reaches other modules **only** through their Application layer: Wallets `Reports\LedgerReportQuery` (and `WalletQueryService::assertCanRead` behind it), Budgeting `BudgetPeriodQuery`, Identity `Profiles\ReportingPreferences`. All SQL over Wallets tables lives in `app/Modules/Wallets/Infrastructure/Reports/SqlLedgerReportReader.php` behind the Domain port `Wallets\Domain\Reports\Ports\LedgerReportReader`.
- Names follow the glossary: "Report", "Report Range", "Budget Period", "Wallet"; never the bare word "Period" as a class name (`BudgetPeriodSpan` is a Budgeting DTO; Reporting's classes are `ReportRange`, `TrendSeries` and friends; the OpenAPI schema for a trend bucket is `TrendBudgetPeriod`).
- Money: `Shared\Domain\Money\Money` only — `plus`, `minus`, `negate`; no `/`, `intdiv`, `round`, `floor` or `ceil` on an amount (ADR-0003 rule 7); no share or percentage anywhere. Never read `balance_minor` (`tests/Arch/BalanceCacheTest.php` covers `app/Modules/Reporting`).
- Query refusals: `throw new ApiRefusal(ErrorCode::InvalidQueryParameter)` (400, plain problem, no `errors[]`), as `App\Http\Api\Collections\CollectionQuery` does. **No new error code.**
- Empty `meta` is `(object) []`. No figure in any log line (P-15). Routes in the `api` group with `auth:api`; **no** `address.verified` (reads).
- Run `dev exec -- vendor/bin/pint --dirty --format agent` after PHP edits.
- Contract tests use `assertValidRequest()` and `assertValidResponse($status)`, covering every documented status of each operation; 405 with `Allow` for other methods.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on unfinished tasks)
- **[Story]**: US1–US5 from spec.md

---

## Phase 1: Setup (docs, module, index)

- [X] T001 [P] Glossary before code uses the term, in `docs/glossary.md`: new **Report Range** entry under Reporting (derived value, never stored, no sync: the inclusive span of calendar dates a report covers — the caller's Budget Period containing a date, or an explicit `from`/`to` of at most 366 days; notes define *covered wallet* (a live wallet the caller currently belongs to, any role, archived included, in the report's currency) and *excluded wallet* (one in another currency, listed in `meta.excluded_wallets`)); the entity index gains its row; the Report entry's notes gain spec 019's answers (wallet reports count every member's transactions; cross-wallet = current memberships in one currency, internal transfers cancelled; balances = opening + Σ wallet postings; no share; `label` is a key; planned figures from scheduled occurrences kept apart; computed on read; not gated); Budget Period's "Referenced by" names Report Range.
- [X] T002 [P] Decision record `docs/adr/0022-reports-are-wallet-views-computed-on-read.md` (Accepted, 2026-10-04; Principles P-03, P-05, P-08, P-12; extends ADR-0011, ADR-0014 rule 5 and ADR-0019; no rule of any changes): Decision rules from research R-1, R-2, R-4, R-5, R-7 (all members per wallet; covered wallets = current memberships in one currency; internal transfers cancel; default Report Range = the viewer's Budget Period, membership by calendar date, range echoed; balances from opening + wallet postings, never the cache; no share; compute on read, no cache, no listeners; one snapshot per response); Alternatives (author attribution, cache, calendar months only, server shares); Consequences. Add its row to `docs/adr/README.md`. `.specify/memory/constitution.md` → **v1.3.11 (PATCH)**: P-05 and P-12 `ADRs:` lines gain ADR-0022; Sync Impact Report entry ("PATCH: P-05 and P-12 list ADR-0022, introduced by spec 019; no rule changes"); version line, footer, Constitution Check template version, Last Amended 2026-10-04.
- [X] T003 [P] Other docs: `docs/api-conventions.md` gains a short "Reports" section (reports are single objects, not collections, no cursor; `{label, value}` with `label` a machine key; Money values; no share; Report Range parameters `date` | `from`+`to`; `months` refused outside 1–12, never clamped; `meta.excluded_wallets`); `docs/security-baseline.md` gains report reads (financial attributes readable by wallet members via W-01, and by a person over their own memberships; never logged; no verification gate on reads); `specs/briefs/README.md` row B13 notes "Specified as spec 019".
- [X] T004 Module skeleton `app/Modules/Reporting/`: `Application/ReportingServiceProvider.php` (binds `ReportQueryService` as a singleton-free class; nothing else) and `Http/ReportingHttpServiceProvider.php` (`Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php')`, as `BudgetingHttpServiceProvider`); empty `Http/routes.php` with the `auth:api` group named `reports.`; register both in `bootstrap/providers.php` after the Budgeting providers. `tests/Arch/ModuleBoundariesTest.php` already names Reporting — keep it green.
- [X] T005 [P] Migration `app/Modules/Wallets/Infrastructure/Database/Migrations/2026_10_08_000001_add_wallet_leg_index_to_postings.php`: up `CREATE INDEX postings_wallet_leg_index ON postings (wallet_id, transaction_id) INCLUDE (amount_minor) WHERE wallet_id IS NOT NULL`; down `DROP INDEX IF EXISTS postings_wallet_leg_index`. Comment cites spec 019 research R-3. `tests/Arch/MigrationsTest.php` stays green.

---

## Phase 2: Foundational (blocking)

### Tests

- [X] T006 [P] `tests/Unit/Reporting/ReportRangeTest.php` (FR-003): `ReportRange::explicit('2026-09-01', '2026-09-30')` holds both; `from` > `to` refused; a span of exactly 366 inclusive days accepted (`2024-01-01`..`2024-12-31`), 367 refused (`2026-01-01`..`2027-01-02`); dates outside 1900-01-01 – 2999-12-31 refused; `ReportRange::ofBudgetPeriod(from, to)` accepts any span (a transitional Budget Period may exceed a month; bounds not applied, A-27).
- [X] T007 [P] `tests/Feature/Budgeting/BudgetPeriodQueryTest.php` (FR-002, FR-013): `todayOf` uses the profile timezone (frozen instant 2026-10-31T22:30Z → `2026-11-01` in Africa/Cairo, `2026-10-31` in UTC) and the configured default without a profile; `periodContaining` equals what `BudgetQueryService::progressOf` reports for the same date (day 1; day 25; history [(1, null), (25, 2026-10-25)]); `periodsEndingWith(user, '2026-10-04', 6)` → six spans oldest first `2026-05-01`..`2026-10-31` for day 1; with day 25 → spans from the 25th; across a start-day change the transitional span appears once; `count` 1 returns one span.
- [X] T008 [P] `tests/Feature/Wallets/LedgerReportQueryTest.php` (R-2, R-3, R-6, FR-004, FR-006): `readableWallet` returns `{id, currency, opening_balance_minor}` for owner, editor, viewer and throws the wallet not-found refusal for a non-member, a former member and a deleted wallet; `walletsOf(user)` lists live memberships' live wallets (archived included; deleted wallets and ended memberships excluded) with currency and opening balance; `walletLegTotals` rows carry kind, sign and `other_wallet_id` for transfers (source and destination sides); `balancesBefore` sums legs dated strictly before the date; `categoryTotals` groups category legs with `system_key` (null for a person's category), expense legs positive, income legs negative; `dailyTotals` groups income/expense legs by date; `scheduledTotals` includes only `scheduled`, live occurrences in the range and currency, transfer occurrences with either side in the id list; deleted transactions contribute nothing; `balance_minor` corrupted in the row changes nothing; `snapshot(fn)` runs `fn` in one transaction whose isolation is repeatable read and read-only (assert via `SHOW transaction_isolation` inside).

### Implementation

- [X] T009 Budgeting `app/Modules/Budgeting/Application/BudgetPeriodQuery.php` with `todayOf(string $userId): string`, `periodContaining(string $userId, string $date): BudgetPeriodSpan`, `periodsEndingWith(string $userId, string $date, int $count): list<BudgetPeriodSpan>` (period containing `date`, then repeatedly the period containing the day before the earliest `from`; returned oldest first), over `BudgetPeriods::containing` and `Identity\Application\Profiles\BudgetStartDayQuery` exactly as `BudgetQueryService` uses them (refactor `BudgetQueryService::todayOf`/period resolution to call it, so there is one code path); DTO `app/Modules/Budgeting/Application/Dto/BudgetPeriodSpan.php` (`from`, `to`, both `YYYY-MM-DD`). Make T007 pass.
- [X] T010 Identity: `app/Modules/Identity/Domain/Ports/ProfileRepository.php` gains `defaultCurrencyOf(string $userId): string`; implement in the Eloquent profile repository (`Profile::query()->where('user_id', …)->value('default_currency') ?? 'EGP'`, the column default); `app/Modules/Identity/Application/Profiles/ReportingPreferences.php` with `defaultCurrencyOf`. Add a case to an existing profile feature test or a new `tests/Feature/Identity/ReportingPreferencesTest.php` (profile value; no profile → `EGP`).
- [X] T011 Wallets port and reader: `app/Modules/Wallets/Domain/Reports/Ports/LedgerReportReader.php` (methods `readableWallet` data lookup, `walletsOf`, `walletLegTotals`, `balancesBefore`, `categoryTotals`, `dailyTotals`, `scheduledTotals`, `snapshot`), implemented by `app/Modules/Wallets/Infrastructure/Reports/SqlLedgerReportReader.php` with the SQL of research R-3 and R-6 (every id list bound as `= ANY(?::uuid[])`; `SUM(...)::bigint`; `t.deleted_at IS NULL`; never `balance_minor`; `snapshot` = `DB::transaction` whose first statement is `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY`); bind in `WalletsPersistenceServiceProvider`.
- [X] T012 Wallets Application `app/Modules/Wallets/Application/Reports/LedgerReportQuery.php` (`readableWallet(userId, walletId)` authorises with `WalletQueryService::assertCanRead` then returns the DTO; the other methods delegate to the port) and DTOs in `app/Modules/Wallets/Application/Reports/Dto/` (`ReportWallet {id, currency, openingBalanceMinor}`, `LegRow {walletId, kind, otherWalletId, amountMinor}`, `CategoryRow {kind, categoryId, systemKey, amountMinor}`, `DailyRow {date, kind, amountMinor}`, `ScheduledRow {date, kind, categoryId, systemKey, walletId, counterWalletId, amountMinor}`). Make T008 pass.
- [X] T013 Reporting Domain `app/Modules/Reporting/Domain/ReportRange.php` (`explicit` with the FR-003 rules throwing `Domain/Refusals/InvalidReportRange`; `ofBudgetPeriod`); make T006 pass.
- [X] T014 Reporting Http `app/Modules/Reporting/Http/ReportQueryParser.php`: reads `date`, `from`, `to`, `months`, `include_planned`, `currency` from the raw query per operation kind (summary/by-category: `date`|`from`+`to`, `include_planned`; trend: `date`, `months`, `include_planned`; cross-wallet adds `currency`); empty value = absent; refuses with `ApiRefusal(ErrorCode::InvalidQueryParameter)` on every FR-019 case (calendar-date grammar as the shared `CalendarDate` rule; `months` integer string 1–12; `include_planned` exactly `true`/`false`; `currency` `^[A-Z]{3}$`; `InvalidReportRange` mapped to the same 400). Undeclared parameters ignored. Unit-test the pure rules in `tests/Unit/Reporting/ReportQueryParserTest.php`.

**Checkpoint**: periods, ledger reads, range and parsing ready; no endpoint yet.

---

## Phase 3: User Story 1 — Summary for one wallet (P1) 🎯 MVP

**Goal**: `GET /wallets/{id}/reports/summary`.
**Independent test**: spec US1 Independent Test (income 10,000; expenses 3,500; net 6,500; transfers_out 2,000; balance_start 800; balance_end 5,300 in EGP major units).

### Tests for User Story 1

- [X] T015 [P] [US1] `tests/Unit/Reporting/SummaryFiguresTest.php` (FR-009, FR-010, invariant row 1): income/expenses from leg signs; transfers in/out per wallet; liability negatives unclamped; `balance_end = balance_start + income − expenses + transfers_in − transfers_out` for random leg sets; with a covered set, transfer rows whose `other_wallet_id` is covered are dropped from in/out while balances still net to the identity; zero legs → zeros in the currency.
- [X] T016 [P] [US1] `tests/Feature/Reporting/WalletSummaryTest.php` (US1 S2–S5, SC-001): the independent-test numbers; deleted transaction excluded; transaction on the day before `from` only in `balance_start`; on `from` and `to` inside; corrupted `balance_minor` changes nothing; with `to` after the last transaction `balance_end` equals opening + Σ postings; credit-card wallet negative balances; a transfer from a since-deleted wallet into this live wallet counts as `transfers_in`; with the log captured (`Log::spy`), no line carries an amount (FR-021).
- [X] T017 [P] [US1] `tests/Feature/Reporting/ReportRangeTest.php` (US1 S1, S6, FR-002, FR-003, P-12): default range = caller's Budget Period containing today (day 1 and day 25; frozen clock, profile timezone Asia/Dubai vs Africa/Cairo around midnight decides only "today"); `date=2026-09-10` → the period containing it; `from`/`to` exact; membership by `transactions.date` only.
- [X] T018 [P] [US1] `tests/Feature/Reporting/ReportSnapshotTest.php` (FR-017a): inside `LedgerReportQuery::snapshot`, after the first query, commit a new transaction on a second connection; the later queries of the same report do not see it and the response's identity holds; a fresh report sees it.
- [X] T019 [P] [US1] `tests/Contract/Reporting/WalletSummaryReportTest.php`: 200 (both examples' shapes, `meta` `{}`, `planned` null), 400 (each refusal of FR-019 that applies: malformed `date`, `from` alone, `date`+`from`+`to`, `from` > `to`, 367-day span, `include_planned=yes`), 401, 404, 406, 422 (`INVALID_ID`), 426; 405 with `Allow: GET, HEAD` for `POST`; a `currency` parameter on a wallet report is ignored (200, wallet's currency).
- [X] T020 [P] [US1] `tests/Contract/Reporting/WalletReportAccessTest.php` (US1 S7, FR-004, P-08): owner, editor, viewer 200; non-member, former member, deleted wallet, unknown id → 404 on all three wallet report paths; a non-member with a malformed query still gets 404 (precedence 404 before 400); unverified member 200 (no gate); a member whose wallet owner has no subscription (free tier) 200 — no entitlement check (FR-020).

### Implementation for User Story 1

- [X] T021 [US1] Reporting Domain `app/Modules/Reporting/Domain/SummaryFigures.php` (+ value types `LegTotal`) per plan design note 3; make T015 pass.
- [X] T022 [US1] Reporting Application `app/Modules/Reporting/Application/ReportQueryService.php` `walletSummary(userId, walletId, ReportRequest)`: `readableWallet` → range (`BudgetPeriodQuery` or explicit) → `LedgerReportQuery::snapshot(fn)` running `walletLegTotals` and `balancesBefore` → `SummaryFigures` → DTO `Application/Dto/SummaryReport`. `ReportRequest` DTO carries the parsed parameters.
- [X] T023 [US1] Reporting Http: `app/Modules/Reporting/Http/Controllers/WalletReportController.php` `summary` (validate id with the shared UUIDv7 check → 422 `INVALID_ID`; `readableWallet` before `ReportQueryParser`; precedence 401 → 422 → 404 → 400), `Http/Resources/SummaryResource.php` (members exactly as `ReportSummary` in reports.yaml, Money as `{amount_minor, currency}`), route `GET wallets/{id}/reports/summary` named `reports.wallet.summary`; empty meta `(object) []`. Make T016–T020 pass.

**Checkpoint**: US1 green; MVP shippable.

---

## Phase 4: User Story 2 — By category (P1)

**Goal**: `GET /wallets/{id}/reports/by-category`.
**Independent test**: spec US2 Independent Test.

### Tests for User Story 2

- [X] T024 [P] [US2] `tests/Unit/Reporting/CategoryBreakdownTest.php` (FR-011, FR-012): `label` = `system_key` else id; personal categories with equal names stay separate; income legs negated; sort by amount desc then label asc (tie case); `total` = Σ items exactly; zero items omitted; planned-only item present only when planned given; no share member.
- [X] T025 [P] [US2] `tests/Feature/Reporting/WalletByCategoryTest.php` (US2 S1–S5): the independent-test numbers; transfers absent; incomes under `income`; a partner's personal category in a shared wallet labelled by id; archived category and retired platform category reported; re-categorised transaction moves between items on the next read.
- [X] T026 [P] [US2] `tests/Contract/Reporting/WalletByCategoryReportTest.php`: 200 (example shapes; schema forbids extra members, so no share), 400, 401, 404, 406, 422, 426; 405.

### Implementation for User Story 2

- [X] T027 [US2] Reporting Domain `app/Modules/Reporting/Domain/CategoryBreakdown.php` (+ `CategoryTotal`); make T024 pass.
- [X] T028 [US2] `ReportQueryService::walletByCategory` (snapshot → `categoryTotals`) → `Dto/CategoryReport`; `WalletReportController::byCategory`; `Http/Resources/ByCategoryResource.php`; route `GET wallets/{id}/reports/by-category` named `reports.wallet.by-category`. Make T025–T026 pass.

---

## Phase 5: User Story 3 — Trend (P2)

**Goal**: `GET /wallets/{id}/reports/trend`.
**Independent test**: spec US3 Independent Test.

### Tests for User Story 3

- [X] T029 [P] [US3] `tests/Unit/Reporting/TrendSeriesTest.php` (FR-013): buckets daily rows into given spans by date inclusive; zero fill; `net` = income − expenses per bucket; series order equals `periods`; labels = span `from`.
- [X] T030 [P] [US3] `tests/Feature/Reporting/TrendTest.php` (US3 S1–S4): default 6 periods ending with today's; `months=12&date=2026-03-15`; start day 25 buckets; a start-day change yields the same transitional bucket as `/budget-progress`; transfers absent; one `dailyTotals` query per request (query count).
- [X] T031 [P] [US3] `tests/Contract/Reporting/WalletTrendReportTest.php`: 200 (example shape, 1–12 periods), 400 (`months=0`, `13`, `x`, malformed `date`), 401, 404, 406, 422, 426; 405; `from`/`to` on the trend are ignored (200).

### Implementation for User Story 3

- [X] T032 [US3] Reporting Domain `app/Modules/Reporting/Domain/TrendSeries.php` (+ `DailyTotal`); `ReportQueryService::walletTrend` (`periodsEndingWith`, snapshot → `dailyTotals(first.from, last.to)`) → `Dto/TrendReport`; `WalletReportController::trend`; `Http/Resources/TrendResource.php`; route `GET wallets/{id}/reports/trend` named `reports.wallet.trend`. Make T029–T031 pass.

---

## Phase 6: User Story 4 — Across my wallets (P2)

**Goal**: `GET /reports/summary`, `/reports/by-category`, `/reports/trend`.
**Independent test**: spec US4 Independent Test.

### Tests for User Story 4

- [X] T033 [P] [US4] `tests/Feature/Reporting/CrossWalletReportTest.php` (US4 S1–S6, S8, FR-006–FR-008): three EGP wallets and one USD; totals = Σ wallet reports minus internal transfers; the cash → bank transfer appears nowhere; bank → a non-member wallet counts as `transfers_out`; `excluded_wallets` = [USD wallet] sorted by id, archived USD wallet included; `currency=USD` flips coverage; default currency from the profile (and EGP without a profile); left wallet drops out; a wallet joined today counts for last month; no EGP wallet → zeros, all excluded; by-category sums a platform category across wallets under one `system_key`; trend sums per bucket.
- [X] T034 [P] [US4] `tests/Feature/Reporting/SharedWalletReportTest.php` (US4 S7, SC-004, ADR-0011 rule 7, ADR-0014 rule 5): two members in different timezones reading the wallet report with the same explicit range get identical bodies; a third user's spending in the same platform category in their own wallet is absent from both.
- [X] T035 [P] [US4] `tests/Contract/Reporting/CrossWalletReportsTest.php`: the three operations — 200 (example shapes, `meta.excluded_wallets`), 400 (`currency=egp`, `currency=EURO`, plus the range/months refusals), 401, 406, 426; 405.

### Implementation for User Story 4

- [X] T036 [US4] `app/Modules/Reporting/Application/ReportScope.php` (from `LedgerReportQuery::walletsOf` and `ReportingPreferences::defaultCurrencyOf`: covered ids, excluded `{wallet_id, currency}` sorted by id); `ReportQueryService::summary`, `byCategory`, `trend` reuse the wallet code paths over the covered ids (no query when none is covered) and pass the covered set to `SummaryFigures`; `app/Modules/Reporting/Http/Controllers/ReportController.php` (`summary`, `byCategory`, `trend`; meta `{excluded_wallets: [...]}`); routes `GET reports/summary|by-category|trend` named `reports.summary|by-category|trend`. Make T033–T035 pass.

---

## Phase 7: User Story 5 — Planned figures (P3)

**Goal**: `include_planned=true` on all six operations.
**Independent test**: spec US5 Independent Test.

### Tests for User Story 5

- [X] T037 [P] [US5] `tests/Feature/Reporting/PlannedFiguresTest.php` (US5 S1–S5, FR-014): a scheduled rent occurrence later in the range → `planned.expenses`; confirmed and skipped occurrences absent; a due/missed (past, still scheduled) occurrence included; gam'eya payout counted as planned income on its date; an occurrence in another currency absent; a transfer-rule occurrence between two covered wallets absent from cross-wallet planned transfers; by-category items gain `planned` (planned-only item listed with `value` 0); trend `planned_income`/`planned_expenses` series; actual figures and balances identical with and without the flag; without the flag every planned member is null.

### Implementation for User Story 5

- [X] T038 [US5] Reporting Domain `app/Modules/Reporting/Domain/PlannedFigures.php` (+ `ScheduledTotal`) folding `ScheduledRow`s into summary, category and trend shapes (internal transfers dropped across wallets); wire `scheduledTotals` into the three `ReportQueryService` paths inside the same snapshot when `include_planned`; resources render `planned` members and series (null otherwise). Make T037 pass and re-run T019, T026, T031, T035.

---

## Phase 8: Polish & Cross-Cutting Concerns

- [X] T039 [P] `tests/Contract/Reporting/ReportsNotSyncedTest.php` (FR-018): after recording transactions, no `report` row type appears on `GET /sync/user/changes` or the wallet feed, and `POST /sync/batch` with an item `type: report` is refused as an unknown type (existing behaviour); `tests/Contract/Reporting/ReportQueryRefusalsTest.php` gathers the FR-019 matrix across all six operations.
- [X] T040 [P] Apply the endpoint review checklist and the security review checklist to every operation in `docs/openapi/paths/reports.yaml`; record results in plan.md § Review checklists (exceptions as listed there). Confirm the implementation matches the frozen contract member for member — any change to a member C13 binds to (contracts/reports-api.md) is a contract change to raise, not to make.
- [X] T041 Performance (SC-005), opt-in `tests/Feature/Reporting/ReportPerformanceTest.php` (`REPORTS_PERF=1`, skipped otherwise): seed one wallet with 10,000 transactions over two years and a person with 20 wallets / 20,000 transactions; `ANALYZE postings, transactions, wallets, wallet_members`; 40 in-process requests each; assert p95 < 300 ms (wallet summary, by-category, 6-period trend) and < 500 ms (cross-wallet three); run with `XDEBUG_MODE=off`. Measure **with and without** `postings_wallet_leg_index` (T005): keep the migration only if it lowers the wallet-summary p95; otherwise delete the migration, the index line in data-model.md and spec §3, and record why (A-20). Record numbers in plan.md. If a target fails, record it and raise it — no cache without a decision record.
- [X] T042 Run the gates: `dev exec -- composer test:lint`, `test:types`, `test:arch`, `test:suite`, `test:api` (the whole `composer test` exceeds Composer's 300 s timeout in the container) and the quickstart walk. State "Seeder: N/A — no reference data" in the commit/PR description.
- [X] T043 Apidog sync: bundle with `npx @redocly/cli bundle docs/openapi/openapi.yaml -o <scratchpad>/bundle.json`; import with `apidog import --project 1332822 --format openapi --file <scratchpad>/bundle.json --module-map "Personal finance backend API=1363204"`; create or update the project doc "Reports" with the C13 client notes (contracts/reports-api.md "What C13 binds to": scope, Report Range = Budget Period, summary identity, by-category labels and no share, trend, planned, no gating, offline fallback). Record the import counts and doc id for the summary.
- [X] T044 Write specs/019-reports/summary.md (parent writes it)

---

## Dependencies & Execution Order

- **Setup (T001–T005)**: T001–T003 and T005 independent; T004 before any Reporting code.
- **Foundational (T006–T014)**: tests T006–T008 first; T009 (Budgeting), T010 (Identity), T011 → T012 (Wallets), T013, T014 in parallel. Blocks every story.
- **US1 (T015–T023)**: depends on Foundational; T021 → T022 → T023.
- **US2 (T024–T028)**: depends on T022/T023 (service and controller exist).
- **US3 (T029–T032)**: depends on T022/T023; independent of US2.
- **US4 (T033–T036)**: depends on US1–US3 (reuses all three code paths).
- **US5 (T037–T038)**: depends on US4.
- **Polish (T039–T044)**: after all stories; T043 after T042; T044 last.

## Parallel Execution Examples

- Setup: T001, T002, T003, T005 together; T004 alongside.
- Foundational: T006, T007, T008 together; then T009 ∥ T010 ∥ T011 ∥ T013 ∥ T014; T012 after T011.
- US1 tests T015–T020 together, then T021 → T022 → T023.
- After US1: US2 (T024–T026) and US3 (T029–T031) tests together; T028 and T032 touch the same service and controller, so run them one after the other.

## Implementation Strategy

1. **MVP** = Setup + Foundational + US1: the wallet summary with correct balances — the C13 summary screen runs against live data.
2. Add US2 (category donut), US3 (trend bars), US4 (cross-wallet with excluded wallets), US5 (planned), then Polish. Each phase ends at a green checkpoint and can be committed on its own on `main`.
