# Implementation Plan: Reports

**Branch**: `019-reports` (trunk-based; work lands on `main`) | **Date**: 2026-10-04 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/019-reports/spec.md`.

## Summary

Create the Reporting module (`app/Modules/Reporting`, the constitution's seventh context, `ReportQueryService`) serving six read-only reports — summary, by-category and trend, per wallet and across the caller's wallets in one currency — computed from the ledger on every read.

- **No persistence** (data-model.md): Reporting has Domain, Application and Http layers only. Wallets gains one partial covering index on `postings` for wallet-leg sums (R-3).
- **Ledger reads** (R-2, R-3): a new Wallets Application query, `Reports\LedgerReportQuery`, answers wallet lists (readable wallet, the caller's memberships with currency and opening balance), wallet-leg totals by kind/direction/other wallet, balances before a date, category-leg totals with `system_key`, daily income/expense totals, and scheduled-occurrence totals (R-6) — each one grouped SQL query bound to an explicit list of wallet ids, behind a Wallets Domain port `LedgerReportReader`.
- **Periods** (R-5): Budgeting Application gains `BudgetPeriodQuery` (`todayOf`, `periodContaining`, `periodsEndingWith`) over spec 015's `BudgetPeriods` and start day history; Reporting's Domain `ReportRange` validates explicit ranges.
- **Arithmetic** (R-4, R-7): pure Reporting Domain — `SummaryFigures` (balance identity, internal transfers dropped across wallets), `CategoryBreakdown` (labels, sort, totals), `TrendSeries` (bucketing, zero fill), `PlannedFigures` — with `Money` only (`plus`, `minus`, `negate`); no share, no division.
- **Preferences** (R-9): Identity Application gains `ReportingPreferences::defaultCurrencyOf`.
- **API**: six operations in `docs/openapi/paths/reports.yaml` (written, indexed, examples, `info.version` v1.11 in this phase), frozen for C13. No new error code, entitlement, event, listener, rate limit, seeder or dependency.
- **Docs** (T001–T003): ADR-0022, constitution PATCH v1.3.11, glossary (Report Range; Report notes), api-conventions (reports section), security baseline (report reads row), briefs README (B13 → spec 019).

## Technical Context

**Language/Version**: PHP 8.5, Laravel 13.

**Primary Dependencies**: none new (P-16 Quality Gate 6; CONTRIBUTING item 17). Everything uses the installed framework, Pest/Spectator and the shared kernel's `Money`.

**Storage**: PostgreSQL 16, read-only for this feature. One partial covering index `postings_wallet_leg_index ON postings (wallet_id, transaction_id) INCLUDE (amount_minor) WHERE wallet_id IS NOT NULL`.

**Testing**: Pest.
- Unit: `ReportRange` (order, span 366/367, bounds), `SummaryFigures` (identity, transfers in/out, internal transfers dropped, liability negatives, planned kept apart), `CategoryBreakdown` (labels, sort ties, totals, planned-only items), `TrendSeries` (zero fill, order, bucket edges), `ReportQueryParser` refusals as pure rules.
- Feature: wallet summary (all kinds, deleted transactions, corrupted cache, balance truth), report range (default Budget Period, start day 25, start-day change, timezone of "today", calendar-date membership), by-category (platform vs own vs partner's category, archived/retired), trend, cross-wallet (currencies, internal transfers, transfers to non-covered wallets, left wallet, joined wallet, archived wallet, deleted wallet), shared wallet (identical figures, platform category scoped), planned (scheduled vs confirmed vs skipped, payout, other currency, transfer occurrences), `BudgetPeriodQuery`, `LedgerReportQuery`.
- Contract: six operations, every documented status (200, 400, 401, 404, 406, 422, 426, 500 where listed; 405 with `Allow`) via Spectator.
- Arch: existing gates (`ModuleBoundariesTest` already lists Reporting; `BalanceCacheTest` covers it).

**Target Platform**: the Linux server (the `dev` container).

**Project Type**: modular-monolith JSON API.

**Performance Goals** (SC-005): wallet summary, by-category and 6-period trend p95 < 300 ms over a wallet with 10,000 transactions; cross-wallet reports p95 < 500 ms over 20 wallets / 20,000 transactions; Xdebug off, tables analysed; opt-in test `REPORTS_PERF=1`.

**Constraints**: P-02 (Reporting reads Wallets, Budgeting and Identity only through their Application layers; Reporting Domain imports only `Shared`), P-03 (Money, no cross-currency sum, no division), P-04 rule 6 (postings never exposed), P-05 rule 3 (postings, never `balance_minor`), P-08 (W-01; non-member 404), P-09 rule 5 (no gating), P-11 (no events), P-12 (calendar dates; profile timezone only for "today" and the default range), P-14 (labels are keys), P-15 (no amounts logged), ADR-0014 rules 5–6, ADR-0015, ADR-0018 rule 3.

**Scale/Scope**:
- 6 operations (6 path entries), 1 migration (index), no seeder (no reference data);
- about 20 Reporting classes; Wallets: `LedgerReportQuery`, a Domain port, an Infrastructure reader, DTOs; Budgeting: `BudgetPeriodQuery`, `BudgetPeriodSpan`; Identity: `ReportingPreferences`, one port method;
- about 18 test files;
- doc edits listed in the Summary.

## Constitution Check

*Constitution v1.3.10 (`.specify/memory/constitution.md`). GATE: must pass before Phase 0 research. Re-check after Phase 1 design, keeping both results.*

### Pre-design

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | "Wallet" only; "Report" (glossary); new term **Report Range** added to the glossary in T001 before code; never the bare word "Period" (the default range and trend buckets are **Budget Periods**); `ReportQueryService` as the constitution names it |
| P-02 Module boundaries | PASS | Reporting code in `app/Modules/Reporting/{Domain,Application,Http}`; ledger through Wallets' Application `LedgerReportQuery`; periods through Budgeting's Application `BudgetPeriodQuery` (DTO `BudgetPeriodSpan`, no Budgeting Domain import); default currency through Identity's Application `ReportingPreferences`; no Infrastructure in Reporting |
| P-03 Money | PASS | Every figure a `Money`; sums grouped per wallet, and only wallets of one currency are queried together; no share, no `/` or rounding on amounts |
| P-04 Ledger integrity | PASS | Read-only; postings summed inside Wallets and never exposed (responses carry totals only) |
| P-05 Derived balances | PASS | `balance_start`/`balance_end` = opening + Σ wallet legs; `balance_minor` never read (corrupted-cache test; `BalanceCacheTest` gate) |
| P-06 Identifiers | PASS | Malformed wallet id 422 `INVALID_ID`; foreign id 404; no record created |
| P-07 Sync and concurrency | N-A | No syncable entity, no write, not on any feed |
| P-08 Authorization | PASS | Wallet reports: spec 010 matrix row W-01 through `WalletQueryService::assertCanRead` (non-member 404); cross-wallet: the caller's own live memberships only; matrix not restated |
| P-09 Entitlements | PASS | No check; `reports.advanced` stays reserved (rule 5) |
| P-10 Billing channels | N-A | No billing surface |
| P-11 Events | PASS | None published or consumed; computed on read |
| P-12 Time | PASS | Range membership by transaction `date`; profile timezone only for "today" and the default range (ADR-0011 rule 8); nothing stored |
| P-13 Naming | PASS | No codename |
| P-14 Localisation | PASS | No new user-facing string; `label` is a key (`system_key`, id, date); Western numerals in JSON integers |
| P-15 Observability | PASS | No new log line; figures never logged |
| P-16 Quality gates | PASS | Every spec §4 invariant names its test; PostgreSQL only (`INCLUDE` partial index, `ANY(:ids)`) |
| P-17 Scope guard | PASS | No FX, no savings goals, no AI, no export; one currency per report |
| P-18 Delivery surfaces | PASS | Six `/api/v1` endpoints; no back-office screen |

### Post-design re-check

Unchanged: all rows PASS or N-A.

- **CONTRIBUTING item 18**: ADR-0022 (written in T002) records reports as wallet views computed on read: all members' transactions per wallet; cross-wallet = the caller's current memberships in one currency with internal transfers cancelled; default Report Range = the viewer's Budget Period; no share. It **extends** ADR-0011 (rule 8's viewer-dependent default range; rules 6–7 hold because membership is by date and the range is echoed), ADR-0014 rule 5 (a platform category total is scoped to the covered wallets) and ADR-0019 (author attribution stays with budgets). It contradicts no rule. Constitution PATCH v1.3.11: P-05 and P-12 `ADRs:` lines gain ADR-0022.
- The brief's cache and "share" are reconciled in the spec's table (R-1, R-7); recorded as A-8 and A-6.
- No new error code; `info.version` v1.11.

Complexity Tracking stays empty.

## Project Structure

### Documentation (this feature)

```text
specs/019-reports/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/reports-api.md
├── checklists/requirements.md
├── checklists/contract.md       # /speckit-checklist
└── tasks.md                     # /speckit-tasks
```

### Source Code (repository root)

```text
app/Modules/Reporting/
├── Domain/                          # ReportRange, SummaryFigures, CategoryBreakdown, TrendSeries,
│                                    # PlannedFigures, LegTotal, CategoryTotal, DailyTotal, ScheduledTotal,
│                                    # Refusals/InvalidReportRange
├── Application/                     # ReportQueryService, ReportScope (wallet | covered wallets),
│                                    # Dto/{SummaryReport, CategoryReport, TrendReport, ExcludedWallet},
│                                    # ReportingServiceProvider
└── Http/                            # Controllers/{WalletReportController, ReportController},
                                     # ReportQueryParser, Resources/{SummaryResource, ByCategoryResource,
                                     # TrendResource}, ReportingHttpServiceProvider, routes.php
app/Modules/Wallets/Domain/Reports/Ports/LedgerReportReader.php
app/Modules/Wallets/Application/Reports/LedgerReportQuery.php            # + Dto/{ReportWallet, LegRow, CategoryRow, DailyRow, ScheduledRow}
app/Modules/Wallets/Infrastructure/Reports/SqlLedgerReportReader.php     # bound in WalletsPersistenceServiceProvider
app/Modules/Wallets/Infrastructure/Database/Migrations/2026_10_08_000001_add_wallet_leg_index_to_postings.php
app/Modules/Budgeting/Application/BudgetPeriodQuery.php                  # + Dto/BudgetPeriodSpan
app/Modules/Identity/Application/Profiles/ReportingPreferences.php
app/Modules/Identity/Domain/Ports/ProfileRepository.php                  # + defaultCurrencyOf
app/Modules/Identity/Infrastructure/…/EloquentProfileRepository.php      # + defaultCurrencyOf
bootstrap/providers.php                                                  # + 2 Reporting providers

tests/Unit/Reporting/, tests/Feature/Reporting/, tests/Feature/Wallets/LedgerReportQueryTest.php,
tests/Feature/Budgeting/BudgetPeriodQueryTest.php, tests/Contract/Reporting/
```

**Structure Decision**: a new module directory, as P-02 and the constitution's context table name it. No Infrastructure layer (nothing persisted); providers in Application and Http, as Budgeting's. Wallets owns all SQL over its tables.

## Design notes

1. **Request flow** (wallet report): route `GET /wallets/{id}/reports/{summary|by-category|trend}` → `api` group + `auth:api` (no `address.verified`) → controller validates the id (`422 INVALID_ID`) → `LedgerReportQuery::readableWallet(userId, walletId)` (404 for non-member / deleted) → `ReportQueryParser::parse(request, kind)` (400) → `ReportQueryService` → resource → `{data, meta: (object) []}`.
2. **Range**: no `from`/`to` → `BudgetPeriodQuery::periodContaining(userId, date ?? todayOf(userId))`; else `ReportRange::explicit(from, to)`. Trend: `periodsEndingWith(userId, date ?? today, months)`.
3. **Summary**: `legs = walletLegTotals(ids, from, to)`; `before = balancesBefore(ids, from)` + Σ openings; `SummaryFigures::from(legs, before, coveredIds, currency)`: income, expenses, transfers (rows whose `other_wallet_id` ∈ covered dropped when cross-wallet), `balance_end = balance_start + Σ legs in range` (all legs, internal included — they net to zero), asserted equal to the identity in the domain. Planned: `scheduledTotals` → `PlannedFigures`.
4. **By-category**: `categoryTotals(ids, from, to)` → `CategoryBreakdown::from(rows, plannedRows)`: key = `system_key ?? category_id`, expense legs as is, income legs negated, items with non-zero actual or planned, sort, totals.
5. **Trend**: `dailyTotals(ids, first.from, last.to)` once → `TrendSeries::bucket(periods, rows, plannedRows)`.
6. **Cross-wallet**: `LedgerReportQuery::walletsOf(userId)` → split by currency (`ReportingPreferences::defaultCurrencyOf` default) into covered and excluded; covered ids empty → zero figures without querying postings. `meta.excluded_wallets` sorted by `wallet_id`.
7. **SQL** (Wallets Infrastructure, `SqlLedgerReportReader`): as research R-3/R-6; every statement binds the id list with `= ANY(?::uuid[])`; `SUM(...)::bigint`; no statement reads `wallets.balance_minor`.
8. **Snapshot** (FR-017a): `ReportQueryService` runs each report's Wallets reads inside `LedgerReportQuery::snapshot(Closure)`, which opens `DB::transaction` with `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY` as its first statement; every query of the response sees one snapshot. No lock is taken.
9. **Logging**: none beyond the request log line (P-15 rule 4).

## Assumed defaults (owner away)

| # | Decision | Why |
|---|---|---|
| A-1 | A wallet report counts every member's live transactions | ADR-0011 rule 7: identical for every member; balances must reconcile |
| A-2 | Cross-wallet covers the caller's current live memberships (any role, archived wallets included) for every range | The phone holds exactly those; no membership history per date |
| A-3 | One currency per cross-wallet report (`currency`, default profile `default_currency`, EGP); others in `meta.excluded_wallets` `{wallet_id, currency}` | P-03 rule 3; FX out of scope; the brief's `meta.excluded_wallets` |
| A-4 | Default Report Range = caller's Budget Period containing `date` (default today); explicit `from`/`to` ≤ 366 days | Brief "same Period as budgets"; C13 asks for the shared definition |
| A-5 | Trend = `months` (1–12, default 6) consecutive Budget Periods, labelled by start date | Brief's `months=6`; same periods as budgets |
| A-6 | No share/percentage on the wire | ADR-0003 rule 7; spec 015 precedent |
| A-7 | `label` is a machine key (`system_key`, category id, period start date) | P-14; offline language switching |
| A-8 | Computed on read; no cache, no listeners | ADR-0019 reasoning; P-05 rule 3 |
| A-9 | Transfers never income/expense; shown as `transfers_in`/`transfers_out`; internal transfers dropped across wallets | Balance identity; ADR-0015 |
| A-10 | Balances = opening (undated, counted in every range) + Σ wallet legs | ADR-0005 rule 1; the phone has no dated opening |
| A-11 | `include_planned` adds separate `planned` figures from scheduled occurrences (due/missed included) in the report's currency; never in actual figures or balances | B7 inheritance; ADR-0018 rule 3 |
| A-12 | No entitlement; `reports.advanced` unused | P-09 rule 5 |
| A-13 | No budget-vs-actual report (served by `/budget-progress`) | B8 inheritance: one computation, in Budgeting |
| A-14 | No export, counterparties, comparisons or net worth series | Not in the brief; later briefs |
| A-15 | Reports not paged; by-category returns every non-zero category | Bounded size; reports are objects, not collections |
| A-16 | Malformed/contradictory query → 400 `INVALID_QUERY_PARAMETER`; `months` refused, not clamped; empty values absent; undeclared parameters ignored | Conventions: refuse what changes the answer |
| A-17 | Wallet report precedence 401 → 422 → 404 → 400 | Non-members learn nothing about parameters |
| A-18 | Reads not behind the verification gate; no rate limit | Spec 010 default 6: reads are not gated |
| A-19 | Deleted wallet / former member → 404; archived wallet reported | P-08 rule 3; archive hides from pickers only |
| A-20 | One covering partial index on `postings` wallet legs, kept only if the measurement shows a gain (T041) | R-3; spec 015 lesson on statistics |
| A-21 | ADR-0022 extends ADR-0011, ADR-0014, ADR-0019; constitution PATCH v1.3.11 | CONTRIBUTING item 18 |
| A-22 | No new error code, event, seeder, table or dependency | Every case maps onto the catalogue |
| A-23 | `wallet_id` member on every report object, null across wallets | One schema per report for both scopes |
| A-24 | Occurrence `date` (editable), not `scheduled_date`, decides planned membership | When the member expects it |
| A-25 | Performance test opt-in (`REPORTS_PERF=1`) | Spec 015 A-41 precedent |
| A-26 | One report response reads one repeatable-read, read-only snapshot | Separate statements under READ COMMITTED could straddle a commit and break the balance identity (checklist CHK011) |
| A-27 | Budget Periods computed from a bounded `date` may reach outside 1900–2999; served as computed | Only request inputs are bounded (checklist CHK017) |

## Review checklists (applied at the polish task)

- **Endpoint review checklist** (`docs/api-conventions.md` appendix), expected exceptions:
  - B (collections: cursor, limit, `x-filterable`, `x-sortable`) does not apply: reports are single objects.
  - C1–C3 and D (syncable members, version, envelope of a syncable resource) do not apply: reports are derived, never stored or synced.
  - G (idempotency, throttling) does not apply: read-only, no rate limit.
- **Security review checklist**: reports are financial attributes readable by members of the wallet only (W-01) and by the caller over their own memberships; a foreign or deleted wallet is 404; every SQL statement is bound to ids the caller may read; no figure in any log line; no new secret, no new stored attribute.

## Implementation notes

Decisions taken during implementation (owner away), each consistent with the spec, plan and research.

- **I-1 Snapshot inside a caller's transaction.** `SqlLedgerReportReader::snapshot` runs `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY` as the first statement only when it opens the outermost transaction (`DB::transactionLevel() === 0`), because PostgreSQL refuses the statement once a transaction has run a query. A caller already inside a transaction (every Feature and Contract test, wrapped by `RefreshDatabase`) keeps its own isolation. The snapshot tests (`LedgerReportQueryTest`, `ReportSnapshotTest`) commit the test transaction, work with committed rows on two connections and delete them afterwards, as the lock-order tests do.
- **I-2 The wallet is read inside the snapshot.** The controller first calls `assertWalletReadable` (404 before any 400), and each wallet report re-reads the wallet through `readableWallet` inside its snapshot, so the opening balance and the legs of one response agree even if the opening balance is edited between the two. The second authorisation is a few indexed lookups.
- **I-3 An unknown alpha-3 currency is 400.** `currency` must match `^[A-Z]{3}$` and be an active ISO 4217 code (`Currency::isKnown`); `Money` cannot hold any other, and no wallet can have it. The frozen contract already says "uppercase ISO 4217 alpha-3" and documents 400 on `/reports/*`; no member, name or code changes.
- **I-4 `BudgetPeriodQuery::containing`.** Besides the three methods of T009, it exposes the Budgeting Domain `BudgetPeriod` (for `BudgetQueryService`, which still needs `start`/`end`/`daysInPeriod`/`contains`); `periodContaining` wraps it into the `BudgetPeriodSpan` DTO Reporting uses. `BudgetQueryService` now takes `BudgetPeriodQuery` in place of `BudgetStartDayQuery`: one code path for today and periods; all existing budget tests pass unchanged.
- **I-5 Ledger port returns arrays.** Wallets' Domain port `LedgerReportReader` returns plain arrays (Domain may not import Application DTOs); `LedgerReportQuery` maps them to the DTOs of T012. `balancesBefore` returns the leg sums per wallet id only; the opening balances come from the wallet rows, summed in `ReportScope`.
- **I-6 Reporting Domain value types.** `LegTotal`, `CategoryTotal`, `DailyTotal`, `ScheduledTotal` carry `Money`; `PlannedSummary`, `CategoryItem`, `CategoryGroup`, `CategoryBreakdown`, `TrendBucket` complete the set. Planned category and daily shapes are produced by `PlannedFigures`. `SummaryFigures` asserts the balance identity and throws `LogicException` if the legs it is given are inconsistent (internal transfers whose two legs do not net); real ledgers cannot produce it.
- **I-7 Planned flag with nothing covered.** A cross-wallet report with no covered wallet and `include_planned=true` returns zero `planned` figures (an object of zeros, empty planned items, zero-filled planned series), not null, so the shape follows the flag.
- **I-8 Planned items.** With `include_planned=true`, every item carries `planned` (zero when nothing is scheduled in its category) and the group `planned` is the sum of the items'; without the flag both are null. A planned-only item appears with `value` zero.
- **I-9 Test helpers.** `tests/Pest.php` gains a "Reports (spec 019)" section: `reportCall`, `reportScenario` (the spec's independent-test ledger), `wireMoney` and `assertReportSharedRefusals` (401, 406, 426 and 405 with `Allow`).
- **I-10 Documentation touched.** Glossary: Report Range entry, entity index row, counts (26 entities, 7 never persisted); `docs/api-conventions.md` "Reports" section; `docs/security-baseline.md` "Report reads"; ADR-0022 and its index/threads entry; constitution v1.3.11; briefs README B13.

### Performance (T041, SC-005)

`REPORTS_PERF=1 XDEBUG_MODE=off`, tables analysed, 40 in-process requests per row after a warm-up, one wallet with 10,000 transactions and one person with 20 wallets / 20,000 transactions (80% expenses, 15% incomes, 5% transfers over two years). p95 in ms, two runs each:

| Report | With `postings_wallet_leg_index` | Without |
|---|---|---|
| wallet summary (target 300) | 53.2 / 49.1 / 50.9 | 55.8 / 63.2 |
| wallet by-category | 17.3 / 16.9 / 18.4 | 18.8 / 29.0 |
| wallet trend, 6 periods | 47.0 / 45.6 / 44.8 | 53.1 / 46.9 |
| cross-wallet summary (target 500) | 93.0 / 85.1 / 87.0 | 88.7 / 83.4 |
| cross-wallet by-category | 17.2 / 15.2 / 15.1 | 15.2 / 15.1 |
| cross-wallet trend, 6 periods | 168.5 / 87.9 / 84.8 | 87.9 / 85.2 |

Every target is met with a wide margin. The index lowers the wallet-summary p95 and median in every pairing (median about 40 to 42 ms against 45 to 50 ms), so **the index is kept** (A-20). The cross-wallet figures are within noise of each other.

### Review checklists (T040)

- **Endpoint review checklist** (`docs/api-conventions.md` appendix), applied to each of the six operations: A1-A5 yes; B1-B4, B6 yes (all problems are `$ref`s to shared responses; only `INVALID_QUERY_PARAMETER`, `NOT_FOUND`, `INVALID_ID`, `UNAUTHENTICATED`, `NOT_ACCEPTABLE`, `CLIENT_VERSION_UNSUPPORTED` and the shared 500 are used; no new code); B5 n/a (no body, no `errors[]` on 400); C1-C5, C8 n/a (read-only, nothing created; 405 with `Allow` tested on every path); C6, C7 yes (foreign wallet 404, malformed id 422 `INVALID_ID`); D n/a (not syncable; no posting exposed, D5 yes); E n/a (single objects, not collections); F1-F5 yes (Money as `{amount_minor, currency}`, `format: date`, labels are keys); G1 yes, G2 yes, G3-G5 n/a (read-only, no rate limit), G6 yes (426 tested); H1-H4 yes; I1-I4 yes (one tag `Reporting`, one `paths` index entry, api-conventions gained the "Reports" section in this change). Exceptions are those listed above in "Review checklists (applied at the polish task)".
- **Security review checklist**, applied to the reads: C2 yes (reads are not gated, unverified member test); E1, E2 yes (no log line; `WalletSummaryTest` captures log events); E5, E6 yes (non-member and deleted wallet are 404, existence not disclosed, 404 before 400); D1 yes (nothing is stored); I2 yes; A, B, F, G, H are outside a read-only report.
- **Contract conformance**: the implementation matches `docs/openapi/paths/reports.yaml` member for member; Spectator validates every status of every operation. No change was made to the frozen contract.
