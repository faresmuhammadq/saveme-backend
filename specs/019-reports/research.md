# Research: Reports (spec 019, B13)

Each entry: Decision, Rationale, Alternatives considered. Owner away; every decision is the conservative option and is listed in plan.md's Assumed defaults.

## R-1 Computed on read, no cache, no listeners

**Decision**: `ReportQueryService` computes every report on its read from a handful of grouped queries over postings, transactions and occurrences. No table, cache or projection holds a figure; Reporting listens to no event.

**Rationale**: ADR-0019 already weighed this for budgets: a `(wallet, period)` cache must be invalidated by backdated edits (two ranges), re-categorisation, restores, wallet deletion without per-record events (spec 012 FR-027), transfers touching two wallets, and — for cross-wallet reports — membership loss, which has no ledger event at all. Every miss is a wrong figure the person trusts. A wallet holds at most ~10,000 transactions (spec 012 sizing); grouped sums over one wallet's range run in tens of milliseconds on PostgreSQL. The constitution allows Reporting projections later "when a query service becomes too slow", which is a measurement, not a guess.

**Alternatives considered**: the brief's per-(wallet, period) cache invalidated by ledger events — rejected for correctness risk and the membership gap; event-fed Reporting projections — rejected until SC-005 fails (then a decision record).

## R-2 Which postings answer which figure

**Decision**:

- **Summary and balances** from **wallet-leg postings** (`postings.wallet_id` in the reported set) joined to their transaction for `kind`, `date`, `wallet_id` and `counter_wallet_id`:
  - income = Σ wallet legs of `income` (positive); expenses = −Σ wallet legs of `expense` (legs are negative);
  - transfers_in = Σ positive wallet legs of `transfer`; transfers_out = −Σ negative ones;
  - balance before a date = Σ every wallet leg dated before it, plus the opening balance (undated).
- **By-category** from **category-leg postings** (`postings.category_id` not null) of transactions whose `wallet_id` is in the set, grouped by category: expense legs are +a, income legs −a (negated for display) (ADR-0012 rule 11: a category total is Σ postings for the category).
- **Trend** from wallet-leg postings of `income` and `expense` grouped by transaction `date`, bucketed into Budget Periods in the domain.

Postings exist only for live transactions (spec 012 default 12: removed on delete, re-derived on restore), so "live" holds by construction; the queries still filter `t.deleted_at IS NULL` as a guard.

**Rationale**: reading postings honours P-05 rule 3 and ADR-0005 rule 5 (never `balance_minor`) and makes `balance_end` with an open-ended `to` equal the wallet's truth expression exactly (SC-001). Wallet legs carry both sides of a transfer, so a transfer is counted once per wallet in its direction with no special casing; category legs carry the category even when the transaction's own `category_id` changes, because both are re-derived together.

**Alternatives considered**: summing `transactions.amount_minor` by kind — rejected (the reconcile table requires postings); reading `balance_minor` for `balance_end` — forbidden (P-05); a per-wallet running balance table — a projection, rejected under R-1.

## R-3 Query shapes and the one index

**Decision**: at most five grouped ledger queries per report request (four below plus `scheduledTotals`, R-6), after one wallet lookup, all bound to an explicit list of wallet ids the caller may read:

1. `walletLegTotals(ids, from, to)` → rows `(wallet_id, kind, other_wallet_id, sign, amount)`: `SELECT p.wallet_id, t.kind, CASE WHEN t.kind = 'transfer' THEN CASE WHEN p.wallet_id = t.wallet_id THEN t.counter_wallet_id ELSE t.wallet_id END END AS other_wallet_id, sign(p.amount_minor), SUM(p.amount_minor) FROM postings p JOIN transactions t ON t.id = p.transaction_id WHERE p.wallet_id = ANY(:ids) AND t.deleted_at IS NULL AND t.date BETWEEN :from AND :to GROUP BY 1,2,3,4`.
2. `balancesBefore(ids, date)` → `(wallet_id, amount)`: Σ wallet legs dated before `date` (and the opening balances from the wallet rows).
3. `categoryTotals(ids, from, to)` → `(kind, category_id, system_key, amount)`: category legs joined to their transaction (wallet in `ids`) and to `categories` for `system_key`.
4. `dailyTotals(ids, from, to)` → `(date, kind, amount)` for `income`/`expense` wallet legs.

Wallets gains one partial covering index: `postings_wallet_leg_index ON postings (wallet_id, transaction_id) INCLUDE (amount_minor) WHERE wallet_id IS NOT NULL`, so queries 1, 2 and 4 read the wallet's legs from the index and probe `transactions` by primary key for `date`/`kind`. The existing `postings_wallet_index (wallet_id)` stays (other readers use it; dropping it is out of scope).

**Rationale**: a wallet's whole history must be read for `balance_start` anyway (the opening balance plus every earlier leg), so the dominant cost is one pass over the wallet's legs; an index-only scan of `(wallet_id, transaction_id, amount_minor)` keeps it narrow. Spec 015 measured that cold statistics change plans dramatically (221 ms vs 388 ms), so the performance test analyses tables first and the deployment note asks for `ANALYZE postings`.

**Alternatives considered**: no new index — kept as the fallback if the measurement (T041) shows no gain, in which case the migration is dropped before merge and the plan note updated; an index on `transactions (wallet_id, date) INCLUDE (kind, …)` — does not help the posting sums, which are the heavier side; a materialised running balance — a projection (R-1).

## R-4 Cross-wallet arithmetic and internal transfers

**Decision**: the cross-wallet report runs the same queries over the covered wallet ids. A transfer row whose `other_wallet_id` is also covered is dropped from `transfers_in`/`transfers_out` in the domain (`SummaryFigures`); balances still sum every covered wallet's legs, where an internal transfer nets to zero by construction (−a in one, +a in the other). Wallets in another currency are never queried (filtered by the wallet list), so no SQL ever sums two currencies.

**Rationale**: the identity `balance_end = balance_start + income − expenses + transfers_in − transfers_out` then holds across wallets as well as per wallet; internal moves are neither earned nor spent.

**Alternatives considered**: counting internal transfers in both directions — inflates both figures and makes the cross-wallet report disagree with the person's intuition; excluding all transfers — breaks the balance identity.

## R-5 Report Range and Budget Periods

**Decision**: Budgeting Application gains `BudgetPeriodQuery`:

- `todayOf(userId)` — today in the profile timezone (the configured default without a profile);
- `periodContaining(userId, date)` — `BudgetPeriods::containing(date, history, currentDay)`;
- `periodsEndingWith(userId, date, count)` — the period containing `date`, then repeatedly the period containing the day before the earliest start, oldest first.

It returns an Application DTO `BudgetPeriodSpan {from, to}`, so Reporting imports nothing from Budgeting's Domain. Reporting's Domain `ReportRange` validates an explicit `from`/`to` (order, ≤ 366 days, 1900–2999).

**Rationale**: the brief asks for "the same Period value object as budgets" and C13 for "the Period definition shared with budgets"; reusing Budgeting's arithmetic over the start day history guarantees the report's default range equals the dates `/budget-progress` shows. A viewer-dependent default range is explicitly allowed by ADR-0011 rule 8; membership stays by calendar date (rule 6), and the response echoes the resolved range, so two members asking for the same range get identical figures (rule 7).

**Alternatives considered**: calendar months only — simpler, but a person paid on the 25th sees reports that disagree with their budgets; a `period=` enum (`month`, `week`, `budget`) — more surface with no client ask; letting Reporting read the start-day history from Identity directly and re-implement periods — duplicates spec 015's arithmetic.

## R-6 Planned figures from occurrences

**Decision**: Wallets Application gains `scheduledTotals(ids, currency, from, to)` over `occurrences` with `status = 'scheduled'`, `deleted_at IS NULL`, `date BETWEEN from AND to`, `currency = :currency`, and (`wallet_id = ANY(ids)` or, for transfers, `counter_wallet_id = ANY(ids)`), grouped by `(date, kind, category_id, wallet_id, counter_wallet_id)` with `system_key` joined. Reporting's domain folds them into the same shapes as actual figures: income/expense by kind, transfers in/out by which side is covered (internal ones dropped across wallets), categories by key, buckets by date.

**Rationale**: B7's inherited note requires forecasts from `scheduled` occurrences through an Application query service, never from transactions; occurrences have no postings (ADR-0018 rule 3), so `amount_minor` with `kind` is the only figure. "Due" and "missed" are derived from `scheduled`, so they are included; `confirmed` ones are already actual transactions and are never added twice. The occurrence's `date` (editable) is used, not `scheduled_date`, since that is when the member expects it.

**Alternatives considered**: adding planned into actual totals behind the flag (the brief's wording) — rejected: one figure meaning two things; projecting beyond the materialised window by expanding rules — duplicates `RuleDates` and invents occurrences the server has not minted (ADR-0018 rule 1).

## R-7 Labels, shares and money shapes

**Decision**: `{label, value}` where `value` is Money and `label` is a machine key: a platform category's `system_key`, a person's category id, or a Budget Period's start date. No percentage or share is returned. Items are sorted by amount descending, then label.

**Rationale**: ADR-0003 rule 7 forbids division and rounding of amounts outside `Money::allocate()`, which only splits into equal parts, so a share cannot be produced without a new rounding policy (a superseding ADR). The spec 015 precedent (no percentage) applies. P-14 forbids pre-formatted display strings and the phone switches language offline, so the label is a key the client localises (it holds the platform set with both names, its own categories and partners' Category References).

**Alternatives considered**: basis-point shares by largest remainder — needs an ADR for ratio rounding and new code dividing amounts; localised category names as labels — breaks offline language switching and leaks a partner's private category names to a cross-wallet reader who might not hold that reference (they do, through the wallet's Category References, but the key form needs no such reasoning).

## R-8 Query parsing and refusals

**Decision**: a Reporting Http `ReportQuery` parser reads `date`, `from`, `to`, `months`, `include_planned` and `currency` from the raw query and refuses with `ApiRefusal(ErrorCode::InvalidQueryParameter)` (400, plain problem) on: malformed dates (the shared `CalendarDate` rule's grammar), dates outside 1900–2999, `from` without `to` or the reverse, `date` with either, `from` > `to`, a span over 366 days, `months` not an integer 1–12, `include_planned` not `true`/`false`, `currency` not `^[A-Z]{3}$`. An empty value (`date=`) is treated as absent (spec 015 A-36). Undeclared parameters are ignored. On wallet reports, membership is checked before parsing (404 first).

**Rationale**: the conventions refuse rather than ignore anything that would change the answer; `months` is refused, not clamped, because unlike `limit` it changes the meaning of the response, not its page size.

**Alternatives considered**: clamping `months` to 12 with a `meta` member — more surface; 422 validation errors with pointers — the conventions use 400 for query problems.

## R-9 Default currency and timezone

**Decision**: Identity Application gains `ReportingPreferences::defaultCurrencyOf(userId)` (the profile's `default_currency`, EGP without a profile — the column default), through a new `ProfileRepository::defaultCurrencyOf` port method. Timezone comes from `BudgetStartDayQuery::timezoneOf` via Budgeting's `BudgetPeriodQuery::todayOf`.

**Rationale**: P-02: Reporting reads Identity only through its Application layer; one small method, no new event.

## R-10 Entitlements

**Decision**: no check. `Feature::ReportsAdvanced` stays reserved and enabled on every plan (spec 009 FR-006).

**Rationale**: P-09 rule 5 limits the free tier only on sharing; gating any B13 report would contradict it, and every report requested here is "basic". A later advanced report behind the key would need a constitution amendment first.

## R-11 Decision record

**Decision**: ADR-0022 "Reports are wallet views computed on read" — extends ADR-0011 (default Report Range is the viewer's Budget Period; rule 8 already allows a viewer-dependent default), ADR-0014 rule 5 (cross-wallet scope = the caller's current memberships) and ADR-0019 (reports count all members; attribution by author stays with budgets). Constitution PATCH v1.3.11: P-05 and P-12 `ADRs:` lines list ADR-0022. No rule changes.

**Rationale**: CONTRIBUTING item 18; the attribution split between budgets and reports is non-obvious and will be asked again.
