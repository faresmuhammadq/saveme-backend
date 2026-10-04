# Summary: Reports (spec 019, B13)

**Date**: 2026-10-05 · **Status**: implemented (T001–T044), QA and E2E green, committed on `main` (d7ed413).

## What was built

- **Reporting module** (`app/Modules/Reporting`, Domain/Application/Http, no persistence): six read-only reports computed from the ledger on every read — summary, by-category and trend, each for one wallet (`/wallets/{id}/reports/*`) and across the caller's wallets (`/reports/*`). No cache, no events, no new error code, no entitlement. Frozen contract `docs/openapi/paths/reports.yaml` (`info.version` v1.11, tag Reporting), with examples.
- **Wallets**: `LedgerReportQuery` (Application), the `LedgerReportReader` port and `SqlLedgerReportReader` (all the SQL, bound to explicit wallet ids). One grouped query per figure inside a single repeatable-read, read-only snapshot per response, so a concurrent write can't break the balance identity. Partial covering index `postings_wallet_leg_index` (migration `2026_10_08_000001_add_wallet_leg_index_to_postings`, additive).
- **Budgeting**: `BudgetPeriodQuery` is now the single code path for "today" and Budget Periods, used by `BudgetQueryService` and by reports; `BudgetPeriodQuery::containing` keeps the domain `BudgetPeriod`. All existing budget tests pass unchanged.
- **Identity**: `ReportingPreferences::defaultCurrencyOf`.
- **Reporting Domain**: `ReportRange` (explicit span at most 366 days, inclusive), `SummaryFigures` (the balance identity; transfers between covered wallets cancel), `CategoryBreakdown` (`label` is a machine key, no share), `TrendSeries` (zero-filled), `PlannedFigures` (scheduled occurrences kept apart from actual figures).
- **Cross-wallet**: one currency per report; wallets in other currencies are listed in `meta.excluded_wallets` and never converted.
- **Docs**: ADR-0022 ("reports are wallet views computed on read", extends ADR-0011, ADR-0014, ADR-0019); constitution v1.3.11; glossary (Report Range; 26 entities, 7 never persisted); api-conventions and security-baseline sections; briefs README B13.
- **Apidog**: six endpoints imported into project 1332822 / module 1363204; project doc "Reports" (2465481).

## Results

- **QA** (`/speckit-qa-run`, HTTP against the dev app): 2,021 checks in 11 groups, 0 failures; every figure compared with an independent Python model built from the transactions and occurrences the script created. Report `qa/qa-20261005-000000.md`.
- **E2E** (`/speckit-test-by-validate`, over HTTP): 31/31 acceptance scenarios of User Stories 1–5 (91 checks). Report `test-reports/validation-20261005-000500.md`.
- **Gates**: Pint and Larastan clean; Arch passes; Unit + Feature + Contract 2,545 passed, 3 opt-in tests skipped. 177 new tests across 25 files.
- **Performance** (Xdebug off, tables analysed, 40 requests per row; 10,000 transactions in one wallet; 20 wallets / 20,000 transactions cross-wallet; p95): wallet summary 49–63 ms (target 300), wallet by-category 17–29 ms, wallet trend 6 periods 45–53 ms, cross-wallet summary 83–93 ms (target 500), cross-wallet by-category about 15 ms, cross-wallet trend 85–88 ms (one 168 ms outlier). The index lowers the wallet-summary p95 and median in every pairing, so it is kept. Opt-in: `dev exec -- env REPORTS_PERF=1 [REPORTS_PERF_NO_INDEX=1] XDEBUG_MODE=off vendor/bin/pest tests/Feature/Reporting/ReportPerformanceTest.php`.
- No product defect in QA; no code or contract changed.

## Deployment

Run the `2026_10_08` migration; `ANALYZE` `postings`, `transactions` and `wallets` if statistics are cold.

## Contract notes for the client

- A 366-day span counts inclusively (2026-01-01 to 2027-01-01 is 200; 367 days is 400).
- `months` accepts 1–12; anything else (0, 13, -1, 1.5, `6abc`) is 400, never clamped; blank or whitespace is absent (default 6).
- `currency` must be an uppercase ISO 4217 code (`ZZZ`, `egp`, `XX`, `123` are 400). A valid code with no wallets (`JPY`) returns 200 with zero figures and every wallet in `meta.excluded_wallets`.
- With `include_planned=true` and nothing covered or scheduled the planned object is zeros, not null.
- Wallet reports have `meta: {}`; cross-wallet reports have `meta.excluded_wallets` (`[]` when none).
- A transfer to or from a wallet the caller has left, or from a deleted wallet, counts as `transfers_in` or `transfers_out`.
- A gam'eya payout shows as planned income on its date; planned money covers only occurrences already generated (each rule's today + 35 days).
- The client divides for shares and localises labels (machine keys: `system_key`, category id, or period start date); partners' category ids resolve through Category References.

## Assumed defaults

Owner away with delegated authority; each the conservative option.

### Design

1. Wallet reports count every member's transactions (ADR-0011 rule 7: identical figures for every member, balances reconcile). A person's own spending stays with budgets.
2. Cross-wallet reports cover the wallets the caller belongs to now (any role, archived included) for every range; there is no membership history per date.
3. One currency per cross-wallet report, defaulting to the profile's `default_currency`; no FX (P-03).
4. Default range is the caller's Budget Period containing `date` (default today, profile timezone), computed by the same code as `/budget-progress`; explicit `from`/`to` up to 366 days also accepted.
5. Trend is `months` (1–12, default 6) consecutive Budget Periods, each labelled by its start date; out-of-range values are refused, not clamped.
6. Transfers are never income or expense: `balance_end = balance_start + income − expenses + transfers_in − transfers_out`.
7. Balances are opening balance + wallet postings, never `balance_minor` (P-05); the opening balance has no date, so it counts in every range.
8. No share or percentage from the server (ADR-0003 rule 7; spec 015 precedent).
9. `label` is a machine key (P-14: no display text; the phone switches language offline).
10. `include_planned` adds separate `planned` figures from scheduled occurrences (due and missed included); they never enter actual figures or balances.
11. No gating: `reports.advanced` stays reserved (P-09 rule 5 limits the free tier only on sharing).
12. Computed on every read, no cache, no listeners (same reasoning as ADR-0019); each response reads one repeatable-read, read-only snapshot.
13. No budget-vs-actual report (`/budget-progress` serves it).
14. Not built: export, counterparties, comparisons, net-worth series, paging, rate limit, verification gate on reads, new error codes, events, seeders, tables or dependencies.
15. Refusals: malformed or contradictory query → 400 `INVALID_QUERY_PARAMETER`; wallet reports answer 401 → 422 `INVALID_ID` → 404 → 400; non-members and deleted wallets get 404.
16. One partial covering index on `postings (wallet_id, transaction_id) INCLUDE (amount_minor) WHERE wallet_id IS NOT NULL`.

### Implementation (plan I-1 to I-10)

17. A well-formed but unknown currency (`ZZZ`) is 400 (`Money` cannot hold it).
18. `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY` is issued only when the snapshot opens the outermost transaction (PostgreSQL refuses it after a query has run; tests wrapped in `RefreshDatabase` keep their own isolation).
19. Each wallet report re-reads the wallet inside its snapshot; the controller still authorises first, so 404 comes before 400.
20. With the flag set, every item and group carries a `planned` figure (zero if nothing is scheduled); cross-wallet with no covered wallet returns zero planned figures.
21. The performance test is opt-in via `REPORTS_PERF=1`.

## Remaining risks

- Past ranges use current memberships: a wallet left last month vanishes from history, and one joined today shows up in it.
- Planned figures under-report beyond today + 35 days (only generated occurrences count).
- The default range depends on the viewer's start day; the same explicit range gives identical figures for every member.
- Performance depends on the index and fresh statistics (spec 015 saw a 221 → 388 ms swing with cold stats).
- A three-decimal currency (KWD) and an occurrence whose currency differs from its wallet's could not be reproduced through the API; feature tests cover both.
- The profile-timezone check proves only the month (4 and 5 October fall in the same Budget Period).
