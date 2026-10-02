# Summary: Budgets (spec 015, B8)

**Date**: 2026-10-02 · **Status**: implemented (T001–T044), QA and E2E green, committed on `main` (bb7e61b).

## What was built

- **Budgeting module** (`app/Modules/Budgeting`, four layers, three providers in `bootstrap/providers.php`).
  - `Budget` is user-scoped and syncable (version, `change_seq` from `users.change_seq`, tombstone).
  - Domain: `BudgetPeriods`, `BudgetProgress`, `BudgetRules`.
  - `BudgetService` (save, patch, archive, delete) with the spec 011 write discipline; `BudgetQueryService` (list, find, `progressList`, `progressOf`).
  - `BudgetCategoryUsage` joins the category in-use check, set-wise.
  - Sync: user-feed source, `FeedPresenter`, and a tombstone purge step that runs before Wallets'.
- **Eight operations** at `/budgets…` and `/budget-progress`, matching the frozen `budgets.yaml` (`info.version` v1.8). No new error code. Progress is computed on every read: no cache, no events, no listeners.
- **Wallets additions**: `SpendingQueryService::expensesRecordedBy` (one grouped query per request, reused later by Reports), `BudgetCategoryCheck::lockEligible` (category share lock), `RecordIdentifiers::isTaken`, two `TransactionRepository` port methods, a new partial index on `transactions`.
- **Identity additions**: table `budget_start_days` (with backfill), `BudgetStartDayHistory` port and repository, `BudgetStartDayQuery`; `SaveProfile` records the history in the profile's transaction.
- **Sync additions**: batch `ItemType::Budget` (upsert, delete; no `wallet_id`), `budget` rows on the user feed (never a wallet feed), purge step `budgeting_budgets`.
- **Docs**: ADR-0019 (extends ADR-0011 and ADR-0014), constitution v1.3.8, glossary, api-conventions, security baseline, "Inherited from B8" notes in briefs B13, B15, B16.
- **Apidog**: bundle imported into project 1332822 / module 1363204; project doc "Budgets" (2463265).

## Results

- **QA** (`/speckit-qa-run`, HTTP): 262 checks in 23 groups, all pass, including concurrent identical creates (one 201, rest 200), concurrent PATCH (one 200, rest 409), projection overflow → null, recurring-rule author attribution, leaving a wallet, wallet deletion. Report `qa/qa-20261002-180817.md`.
- **E2E** (`/speckit-test-by-validate`, over HTTP): 33/33 acceptance scenarios (97 assertions), including a real `sync:purge-tombstones` run. Report `test-reports/validation-20261002-180817.md`.
- **Gates**: Pint and Larastan clean; Arch 50/50; Unit + Feature + Contract 1859 passed, 1 skipped (opt-in performance test, `BUDGET_PERF=1`).
- **Performance** (50 budgets over 20,000 in-period expenses, Xdebug off, tables analysed): `GET /budget-progress?limit=50` p95 221 ms (target 300; 388 ms with cold statistics); `GET /budgets?limit=50` p95 98 ms; `GET /budgets/{id}` p95 34 ms.
- No product defect found in QA; no code changed.

## Deployment

- Run the three `2026_10_06` migrations (budgets, budget_start_days with backfill, expense-author index).
- Run `ANALYZE` on `wallets`, `wallet_members` and `transactions` if table statistics are cold.

## Contract notes for the client

No change from the frozen v1.8. A batch item with op `archive`, or a `wallet_id` on a `budget` item, rejects the whole batch with 422; `limit` above 200 is clamped; an empty `date=` means today; the server sends no percentage (the phone derives it from `spent` and `limit`).

## Assumed defaults

Owner away with delegated authority; each the conservative option.

### The six banked Budgeting questions

1. **Shared-wallet attribution by `created_by`.** A transaction (including one produced by a recurring rule, which records as the rule's author) counts for its author, in wallets the budget owner currently belongs to, any role. The phone can reproduce the figure offline.
2. **One currency per budget, fixed at creation.** Only that currency is summed; matching spending in other currencies is listed in `other_currencies`, never converted (P-03).
3. **No income budgets.** A budget names an expense category, or `category_id: null` for all expenses. Overlapping budgets are allowed (no uniqueness rule, so two offline phones both sync).
4. **Archived category: nothing changes.** The budget keeps counting; archived or retired categories can be budgeted. Any budget, including a deleted one, keeps its category in use.
5. **Progress is served by Budgeting** through Wallets' `SpendingQueryService`; Reports (B13) will reuse it.
6. **No overrun notification in B8.** `over_limit` and `projected_end` are exposed; B16 owns `BudgetExceeded` and its delivery.

### Other design defaults

7. Progress is computed on every read (no cache); the brief's cache idea waits for measurements.
8. Spending counts only live expenses in the budget's currency. Transfers, incomes and unconfirmed occurrences never count; wallet balances are never read.
9. Periods run monthly from the profile's start day. A change applies from `budget_period_start_day_from`; the period under way at the change ends the day before. "Today" uses the profile timezone (default timezone and day 1 without a profile).
10. Start-day history is a new Identity table; the backfill assumes the day before any change was 1 (exact only for profiles changed once; no production users exist yet).
11. The server returns no percentage (ADR-0003 rule 7). `projected_end` is extrapolated with `Money::allocate`, rounded up, current period only, null above 2^53 − 1.
12. Past periods use the current limit (no per-period rows in MVP).
13. No rollover (outside MVP, P-17).
14. Category, currency and period type are immutable; the limit stays editable, including after archiving.
15. Archive is one-way; delete is final.
16. Lock order: category share → user counter → budget row.
17. Paths are user-scoped; `date` replaces the brief's `?period=`. The progress-list cursor carries its period (`INVALID_CURSOR` for another period's date).
18. No new error code, entitlement, rate limit, seeder or event.

### Implementation defaults (plan A-31 to A-43)

19. A category that can't be budgeted maps to `INVALID_ENUM_VALUE` at `/category_id`.
20. Writes to an existing budget take the user counter, then the budget row; the purge raises owner horizons (ascending) before deleting budget rows.
21. A replay found under the locks rolls the stamp back, so the counter has no hole.
22. The progress list accepts only `sort=created_at`; the budget list also accepts `-created_at`.
23. An empty `date=` is treated as absent.
24. `SaveProfile` computes "today" from `now()` (testable at a frozen instant).
25. Budgeting Http has its own `BudgetId` and `Instants` (P-02).
26. A budget id already held by a Wallets record is 404; Wallets does not check budget ids (A-32).
27. The performance test is opt-in (`BUDGET_PERF=1`).

## Remaining risks

- The start-day backfill assumption (day 1 before the latest change).
- Past periods compared with today's limit may surprise people who changed their limit.
- Compute-on-read depends on the index and fresh statistics; a larger owner may need a covering index or a cache (decision record).
- Attribution by author: a partner re-categorising your transaction removes it from your category budget (it still counts toward all-expenses).
- B16 needs its own trigger for notifications; nothing stores "already notified".
- `IMMUTABLE_FIELD /period_type` is unreachable while `monthly` is the only value; a retired platform category was not budgeted over HTTP (unit-tested); the cursor across midnight was not tested live.
