# Research: Budgets (spec 015, B8)

Each entry: Decision, Rationale, Alternatives considered. Owner away; every decision is the conservative option and is listed in plan.md's Assumed defaults.

## R-1 Progress computed on read, not cached

**Decision**: `BudgetQueryService` computes progress on every read from one grouped spending query. No table, cache or projection holds a figure; Budgeting registers no listener on `TransactionPosted`, `TransactionVoided` or `WalletDeleted`.

**Rationale**: a person's budgets share one Budget Period for a given date, so one `GROUP BY (category_id, currency)` over the owner's in-period expenses answers the whole page. With the partial index on `transactions (created_by, date)` for live expenses, 20,000 in-period transactions sum in tens of milliseconds on PostgreSQL. A cache invalidated by events must handle backdated and re-categorised edits (both periods), restores, wallet deletion without per-record events (spec 012 FR-027) and membership loss (no ledger event at all), each through an idempotent queued listener (P-11) with lag; any miss is a wrong figure the person trusts. Reading from postings satisfies the inherited "re-derive" obligations by construction.

**Alternatives considered**: a `(budget, period)` cache invalidated by events — the brief's suggestion; rejected for correctness risk and the membership-loss gap; can be added later behind the same query service if SC-005 fails. A Reporting projection — rejected: progress belongs to Budgeting (constitution context table).

## R-2 Which transactions count

**Decision**: Σ category-leg postings of transactions with `deleted_at IS NULL`, `kind = 'expense'`, `created_by = owner`, `date` within the period, in a live wallet where the owner holds a live membership (any role); grouped by category and currency. The budget picks its category (or all) and its currency.

**Rationale**: `created_by` is the only attribution that works for platform categories (no owner) and that the phone can reproduce offline; it matches spec 014 A-23 (a rule's transactions are its author's). Restricting to current memberships keeps the server equal to what the phone holds. Category-leg postings of an expense are positive and equal the amount (ADR-0012), so the sum is the spending; reading postings honours ADR-0012 rule 11 and P-05.

**Alternatives considered**: attribute by category owner — fails for platform categories and moves spending when a partner re-categorises; count all members' spending in shared wallets — contradicts "a budget is personal"; include wallets the owner left — the phone no longer holds them, so offline and server figures would differ.

## R-3 Budget Period arithmetic and the start day history

**Decision**: Identity keeps `budget_start_days (user_id, start_day, applies_from)`. `SaveProfile` replaces pending entries and appends one on every change. `BudgetPeriods::containing(D, entries)` takes the governing entry E (last with `applies_from` ≤ D, or the first), starts the period on the latest date ≥ E.`applies_from` and ≤ D whose day is E.`start_day` (E.`applies_from` itself always qualifies, since `appliesFrom` returns a date on that day; for the first entry, unbounded), and ends it the day before the next start on that day or the day before the next entry's `applies_from`, whichever is first.

**Rationale**: the profile alone holds the current day and the date it applies from, so the day that governed earlier periods is lost on the next change; spec 008 FR-013 promised B8 periods "on both sides of the change". A dated list is the smallest exact record. A transitional period may be shorter or longer than a month; that is the cost of never re-cutting a period already begun.

**Alternatives considered**: a `previous_start_day` column — exact for one change only; Budgeting keeps the history from an Identity event — needs a new event and a queued listener, with a lag window in which periods are wrong; recompute all periods with the current day — re-cuts periods already begun (violates spec 008 FR-013).

**Backfill**: a profile with `…_from` set gets (1, null) and (day, `…_from`); others need nothing (implicit entry: current day, null). Exact for profiles changed at most once; pre-launch, no production users.

## R-4 Projection and percentage under ADR-0003 rule 7

**Decision**: `projected_end` = the first (largest) share of `Money(spent × days_in_period, currency)->allocate(days_elapsed)` — linear extrapolation rounded up — for the current period only; null when `spent × days_in_period` exceeds 2^53 − 1, the API's integer bound (checked before multiplying, so PHP never produces a float). No percentage.

**Rationale**: rule 7 bans `/` and rounding functions on amounts outside `allocate`, and names "percentage division" as the reason no rounding policy exists. Splitting a total into equal parts is exactly what `allocate` does; its largest-remainder share is the ceiling. The percentage is a display figure the client derives from two exact integers.

**Alternatives considered**: a basis-points percentage and a rounded projection with `intdiv` — needs a superseding ADR for a rounding policy; omit `projected_end` — the brief and C8 ask for it.

**Implementation note**: `Money::allocate(int $parts)` already exists (spec 014) and gives the earliest parts the remainder, so part 0 is the ceiling. The multiplication of a minor-unit amount by a day count is not division or rounding and needs no `Money` change.

## R-5 Write discipline and lock order

**Decision**: spec 011's category write discipline, applied to budgets (precedence in spec §6). Under one transaction: category share lock (create only; the category is immutable afterwards) → `UserScopeCounter::next` (user row lock) → budget row `FOR UPDATE` → re-check → write. Replays and conflicts found under the lock roll back.

**Rationale**: ADR-0017 rule 5 fixes categories → wallets → users → records. The share lock races correctly with `CategoryService::delete` (which takes the category `FOR UPDATE` then asks `CategoryUsage`): whichever commits first decides, and the other sees it.

**Alternatives considered**: no category lock — a budget could reference a category deleted in the same instant, leaving a dangling id; locking the budget row first — breaks the global order.

## R-6 Category usage, feed, batch

**Decision**: `BudgetCategoryUsage` (tagged `CategoryUsage`) answers `usedAmong` with one `SELECT DISTINCT category_id FROM budgets WHERE category_id = ANY(?)` over live and tombstoned rows. Budgeting's `UserFeedQuery` adds `budget` rows; `ItemType::Budget` routes to the single writes.

**Rationale**: inherited from B5 and B14; the same shapes as `RecurringCategoryUsage` and the Wallets user feed.

**Alternatives considered**: Budgeting calling into Wallets to mark categories — Wallets must not know Budgeting's tables (spec 011 R-7).

## R-7 Purge order

**Decision**: `TombstonePurgeRun` runs Budgeting's purge before Wallets'.

**Rationale**: a category whose only user was a budget tombstone becomes unused once the budget is purged, so it can be purged in the same run instead of the next.

**Alternatives considered**: any order — correct but one day slower.

## R-8 Paths and parameters

**Decision**: `/budgets`, `/budgets/{id}`, `/budgets/{id}/archive`, `/budgets/{id}/progress`, `/budget-progress?date=`. The list of progress is a separate path (not `/budgets/progress`) so no literal segment competes with `{id}` in routing or in OpenAPI matching.

**Rationale**: user scope (glossary); a progress figure is not part of the syncable record, so the feed row can equal `GET /budgets/{id}` exactly (spec 013 FR-005).

**Alternatives considered**: embedding progress in `GET /budgets` — the feed row would then differ from the read and change without a `change_seq`; `?period=YYYY-MM` — ambiguous when the start day is not 1.
