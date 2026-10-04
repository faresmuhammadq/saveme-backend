# Feature Specification: Reports

**Feature Branch**: `019-reports`

**Created**: 2026-10-04

**Status**: Draft

**Input**: Brief B13 ([specs/briefs/B13-reports.md](../briefs/B13-reports.md)): "Read-only endpoints computed from the ledger: `GET /wallets/{id}/reports/summary?period=` (income, expenses, net, balance start/end), `GET …/reports/by-category?period=` (amount and share per category, grouped by `system_key`), `GET …/reports/trend?months=6` (per-month income/expense), and cross-wallet equivalents under `/reports/*` for the user's own wallets in a single currency." Plus the brief's considerations, its reconcile table, its binding "Inherited from B7" and "Inherited from B8" notes, and the paired client brief [C13](../../../saveme-kmp/specs/briefs/C13-reports.md) "Needs from the backend" (response shapes, the Period definition shared with budgets, whether `reports.advanced` gates any of it).

> **Nature of this feature.** B13 gives a person read-only figures about their money over a span of calendar dates: what came in, what went out, what moved between wallets, and the balance at the start and end; spending and income per category; and a monthly trend. Each report exists for **one wallet** and **across the caller's wallets in one currency**. Reports are derived values computed from the ledger on every read. Nothing is stored, nothing is synced, nothing appears on a change feed.
>
> **Where the brief and the ratified decisions differ, the ratified side wins** (constitution, glossary, ADR-0003, ADR-0005, ADR-0011, ADR-0014, ADR-0015, ADR-0018, ADR-0019, specs 012, 014, 015):
>
> | Brief | In force | Here |
> |---|---|---|
> | "Totals from the ledger" | P-05 rule 3: report totals never read `balance_minor`; recompute from postings | Every figure, including balance start and end, is summed from postings plus the wallet's opening balance |
> | `?period=` and a "`Period` value object" | Glossary: the bare word "Period" is never an entity name; the budget window is the **Budget Period** (spec 015) | The span a report covers is the **Report Range**: by default the caller's Budget Period containing `date` (default today), or an explicit `from`/`to`. Trend buckets are consecutive Budget Periods |
> | "share per category" | ADR-0003 rule 7: no division or rounding of an amount outside `Money::allocate()`, which splits only into equal parts | **No share on the wire.** Each category's amount and the total are exact; the client derives the percentage for display (as spec 015 does for budgets) |
> | `{ label, value }` arrays | Money on the wire is `{amount_minor, currency}` (spec 003); P-14 forbids pre-formatted display strings | `value` is Money; `label` is a stable machine key (a `system_key`, a category id or a Budget Period start date) the client turns into display text |
> | Shared wallet totals | ADR-0011 rule 7: identical for every member | A wallet report counts every member's transactions; for the same Report Range, every member sees the same figures |
> | "`planned` recurring occurrences excluded unless `include_planned=true`" | Spec 014 / ADR-0018: there is no `planned` transaction; upcoming items are `scheduled` Occurrences with no postings, read through a query service | `include_planned=true` adds **separate** `planned` figures summed from scheduled occurrences; they never enter actual totals or balances |
> | "Cache per (wallet, period) invalidated by ledger events" | ADR-0019 (budgets): compute on read; caches need invalidation for backdated edits, restores, wallet deletion and membership loss | **Computed on read, no cache, no listeners.** A cache can be added later behind the same query service under its own decision record if a measurement demands it |
> | `reports.advanced` (reserved key, spec 009) | P-09 rule 5: the free tier is limited only on sharing; the key is enabled on every plan | **Nothing is gated.** Every report in B13 is basic; `reports.advanced` stays reserved and unused |

## Clarifications

### Session 2026-10-04

Owner away with delegated authority; each answer below is the recommended (most conservative) option, recorded as an **assumed default**.

- Q: In a shared wallet, whose transactions does a wallet report count — every member's, or only the caller's own (`created_by`)? → A: **Every member's.** A wallet report describes the wallet, so its figures are identical for every member (ADR-0011 rule 7) and its balances reconcile with the wallet's balance. Attribution by `created_by` belongs to budgets (ADR-0019); a person's own spending against their limits stays at `/budget-progress`. *Assumed default.* (FR-005)
- Q: Which wallets does a cross-wallet report cover? → A: **Every live wallet the caller currently holds a live membership in, any role, archived included**, in the report's currency. It equals the sum of those wallets' own reports, except that transfers between two covered wallets cancel out. These are exactly the wallets the phone holds, so the phone can reproduce the figures offline. *Assumed default.* (FR-006, FR-007)
- Q: Which currency is a cross-wallet report in? → A: **One currency per request**: `currency` (ISO 4217), default the caller's profile `default_currency` (EGP without a profile). Wallets in other currencies are skipped and listed in `meta.excluded_wallets` as `{wallet_id, currency}`. Nothing is converted (P-03 rule 3; FX out of scope). *Assumed default.* (FR-008)
- Q: What span does a report cover by default, and how is another chosen? → A: **The caller's Budget Period containing `date`** (default: today in the caller's profile timezone), computed exactly as budget progress computes it (spec 015 FR-008). An explicit `from`/`to` pair (inclusive calendar dates, at most 366 days) selects any other span. The resolved span is echoed in every response. Membership of a transaction in the span is by its calendar `date` only. *Assumed default.* (FR-002, FR-003)
- Q: What does the trend cover? → A: **`months` consecutive Budget Periods** (1–12, default 6) ending with the one containing `date` (default today). With the default start day 1 these are calendar months. Each bucket is labelled by its start date. *Assumed default.* (FR-013)
- Q: Does the server return a category's share? → A: **No.** The server returns each category's exact amount and the exact total; the percentage is a presentation figure the client derives (ADR-0003 rule 7, the spec 015 precedent). *Assumed default.* (FR-012)
- Q: How do transfers appear? → A: **Never as income or expense.** A wallet report shows `transfers_in` and `transfers_out` separately so that `balance_end = balance_start + income − expenses + transfers_in − transfers_out`. In a cross-wallet report, a transfer between two covered wallets is internal and appears nowhere; one crossing the boundary counts as in or out. *Assumed default.* (FR-009, FR-010)
- Q: Is anything gated on `reports.advanced`? → A: **No.** P-09 rule 5; the key stays reserved and enabled on every plan. No `FEATURE_NOT_ENTITLED` is reachable from a report. *Assumed default.* (FR-020)
- Q: Cache or compute on read? → A: **Compute on read**, every time, from postings and occurrences, with measured p95 targets (SC-005). *Assumed default.* (FR-017)

### Session 2026-10-04 (clarify pass)

- Q: Which `planned` occurrences are counted when `include_planned=true`? → A: **Live occurrences with status `scheduled` dated within the Report Range**, whether still upcoming or already due or missed (both are derived from `scheduled`, ADR-0018 rule 8); `confirmed` ones are already actual transactions and `skipped` ones never happen, so neither is planned. A gam'eya payout counts as planned income on its date. The server materialises occurrences only through each rule's today + 35 days, so a range further out shows less planned money than the rules imply; the response says nothing to hide this, and the API description states it. *Assumed default.* (FR-014)
- Q: What does the caller see for a wallet they left, or a deleted wallet? → A: **404 `NOT_FOUND`** for its wallet report; it drops out of cross-wallet reports. An archived wallet is still reported. *Assumed default.* (FR-004, FR-006)
- Q: Is a malformed or contradictory query refused or ignored? → A: **Refused** with `400 INVALID_QUERY_PARAMETER` (a malformed `date`, `from`, `to`, `months`, `currency` or `include_planned`; `from` without `to` or the reverse; `date` together with `from`/`to`; `from` after `to`; a range over 366 days; `months` outside 1–12). A silently ignored parameter would return a plausible report of the wrong span. Parameters the operation does not declare are ignored, as on every other read. *Assumed default.* (FR-019)
- Q: Does a wallet's opening balance count in a range that ends before the wallet was created? → A: **Yes — the opening balance has no date and counts for every range.** It is the base of the wallet's balance (ADR-0005 rule 1), the phone holds no creation-dated alternative, and treating it as dated would invent a business date from `created_at` (P-06 rule 4 spirit, ADR-0011). A range before the first transaction shows the opening balance at both ends. *Assumed default.* (FR-010)
- Q: Which wallets does a cross-wallet report over a past range cover — the caller's memberships then, or now? → A: **Now.** Membership history is not kept per date, and the phone holds only the wallets it currently belongs to; a wallet left last month drops out of every range, and a wallet joined today counts for past ranges too. *Assumed default.* (FR-006)
- Q: Does a scheduled occurrence in a currency other than its wallet's (possible after a rule's currency was checked only on create, spec 014 default 18) count as planned? → A: **Only when its currency equals the report's currency**; otherwise it is left out (it could never be confirmed into that wallet anyway). No conversion. *Assumed default.* (FR-014)

Further defaults taken while writing the spec, each the conservative option and listed again in the plan's Assumed defaults:

- No budget-vs-actual report: that figure is budget progress, served by Budgeting (spec 015); B13 does not compute it a second time.
- No export, no counterparty or payee report, no net-worth-over-time series, no comparison with a previous span. The summary's `balance_end` across all wallets is the person's net worth in that currency on `to`.
- Reports are not paged: a report is one object; `by-category` returns every category with a non-zero amount in one array.
- No new error code, entitlement, rate limit, event, listener, seeder, table or dependency. Reads are not behind the verification gate.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See this month's summary for one wallet (Priority: P1)

A person opens their cash wallet's report screen and sees, for the current Budget Period, what came in, what went out, the net, what moved to and from other wallets, and the balance at the start and the end.

**Why this priority**: the period summary is the report people look at first, and it proves the ledger arithmetic every other report reuses.

**Independent Test**: in an EGP wallet with opening balance 1,000, record income 10,000 and expenses 3,000 and 500 dated in the current Budget Period, an expense 200 dated last period, and a transfer of 2,000 out to another wallet in the current period; read the summary: income 10,000, expenses 3,500, net 6,500, transfers_out 2,000, balance_start 800, balance_end 5,300.

**Acceptance Scenarios**:

1. **Given** a wallet member, **When** they read the wallet's summary without parameters, **Then** the response covers their Budget Period containing today and echoes it as `range {from, to}`.
2. **Given** live transactions of every kind, **When** the summary is read, **Then** `income` is the sum of incomes dated in the range, `expenses` the sum of expenses, `net` = income − expenses, `transfers_in` and `transfers_out` the transfers into and out of the wallet, and none of these includes a deleted transaction.
3. **Given** the wallet's opening balance and transactions dated before and within the range, **When** the summary is read, **Then** `balance_start` is the opening balance plus every live posting to the wallet dated before `from`, `balance_end` is the opening balance plus every live posting dated on or before `to`, and `balance_end = balance_start + income − expenses + transfers_in − transfers_out`.
4. **Given** the wallet's cached balance is wrong, **When** the summary is read, **Then** the figures are unaffected (they never read the cache).
5. **Given** a liability wallet (credit card) with spending, **When** the summary is read, **Then** balances are negative and returned as stored, never clamped.
6. **Given** `from=2026-09-01&to=2026-09-30`, **When** the summary is read, **Then** it covers exactly those dates.
7. **Given** a person who is not a member of the wallet, or a deleted wallet, **When** they read any of its reports, **Then** it is `404 NOT_FOUND`, nothing disclosed.

---

### User Story 2 - See where money went, by category (Priority: P1)

The person sees their wallet's expenses for the range broken down by category, largest first, and the same for income, as data a donut chart can draw.

**Why this priority**: spending by category is the most asked-for personal finance view and the reason people categorise.

**Independent Test**: record expenses 3,000 Groceries (platform), 500 Groceries, 1,200 Transport (platform) and 800 in a personal "Gym" category in the range; read by-category: expenses total 5,500, items Groceries 3,500 (`label: groceries`), Transport 1,200, Gym 800 (`label` = the category id), in that order.

**Acceptance Scenarios**:

1. **Given** expenses in several categories, **When** by-category is read, **Then** `expenses.items` has one entry per category with a non-zero total, each `{label, category_id, system_key, value}`, sorted by amount descending then `label` ascending, and `expenses.total` equals the sum of the items.
2. **Given** a platform category, **When** it appears, **Then** its `label` is its `system_key`; a person's category has `label` equal to its id and `system_key` null; two personal categories with the same name are never merged.
3. **Given** incomes, **When** by-category is read, **Then** they appear under `income` the same way, never mixed with expenses.
4. **Given** transfers, **When** by-category is read, **Then** they appear in neither list.
5. **Given** a partner's personal category used in a shared wallet, **When** by-category is read, **Then** it appears with its id as `label`, and the client resolves its name from the wallet's Category References.
6. **Given** any response, **When** inspected, **Then** no percentage or share appears; the client derives it from `value` and `total`.

---

### User Story 3 - See the trend over recent months (Priority: P2)

The person sees income and expenses for each of the last six Budget Periods, as bars, to tell whether things are getting better.

**Why this priority**: useful after the summary and categories exist; it reuses their arithmetic per bucket.

**Independent Test**: with start day 1, record incomes and expenses across May–October; read the trend with `months=6` on 2026-10-04: six buckets labelled 2026-05-01 … 2026-10-01 with the expected sums, empty months showing zero.

**Acceptance Scenarios**:

1. **Given** no parameters, **When** the trend is read, **Then** it has 6 buckets: the caller's Budget Period containing today and the 5 before it, oldest first.
2. **Given** `months=12&date=2026-03-15`, **When** read, **Then** it has 12 buckets ending with the Budget Period containing 2026-03-15.
3. **Given** a start day of 25, **When** the trend is read, **Then** buckets run from the 25th to the 24th; after a start day change, the transitional bucket has the dates budget progress uses.
4. **Given** a bucket without transactions, **When** read, **Then** its income, expenses and net are zero in the wallet's currency, never omitted.
5. **Given** `months=0`, `months=13` or `months=x`, **When** read, **Then** `400 INVALID_QUERY_PARAMETER`.

---

### User Story 4 - See all my wallets together, in one currency (Priority: P2)

A person with a cash wallet, a bank account and a credit card, all in EGP, plus a USD savings wallet, sees one EGP summary, one EGP category breakdown and one EGP trend across the three EGP wallets, with a note that the USD wallet was left out.

**Why this priority**: the overall picture matters, but each wallet report is useful alone first.

**Independent Test**: as above, with a 1,000 EGP transfer from cash to bank and a 500 EGP transfer from bank into a partner's wallet the person is not a member of; read `/reports/summary`: totals equal the sum of the three EGP wallets' reports, the internal 1,000 transfer appears nowhere, the 500 counts as `transfers_out`, `meta.excluded_wallets` names the USD wallet with `USD`.

**Acceptance Scenarios**:

1. **Given** the caller's live memberships, **When** a cross-wallet report is read, **Then** it covers every live wallet they belong to whose currency is the report's currency, archived included, any role.
2. **Given** wallets in other currencies, **When** read, **Then** they are excluded from every figure and listed in `meta.excluded_wallets` as `{wallet_id, currency}`, sorted by `wallet_id`.
3. **Given** `currency=USD`, **When** read, **Then** the report covers only USD wallets and the EGP ones are listed as excluded.
4. **Given** no `currency`, **When** read, **Then** the report uses the caller's profile default currency (EGP without a profile).
5. **Given** a transfer between two covered wallets, **When** read, **Then** it is in neither `transfers_in` nor `transfers_out`; a transfer between a covered wallet and any other wallet counts in the matching direction.
6. **Given** the caller left a shared wallet, **When** read, **Then** that wallet no longer counts.
7. **Given** a shared wallet, **When** both members read the same cross-wallet report with the same parameters, **Then** that wallet contributes the same amounts to both.
8. **Given** no wallet in the requested currency, **When** read, **Then** `200` with zero figures and every wallet listed as excluded.

---

### User Story 5 - Include what is scheduled (Priority: P3)

Before the month ends, the person turns on "include scheduled" and sees, beside the actual figures, the income and expenses their recurring rules still expect in the range (salary, rent, gam'eya rounds).

**Why this priority**: a forecast is valuable but depends on recurring rules being used.

**Independent Test**: a monthly rent rule (expense 5,000) with an occurrence scheduled on the 28th, and a salary already confirmed on the 1st; read the summary with `include_planned=true`: actual income contains the salary; `planned.expenses` is 5,000; actual expenses and balances do not contain the rent.

**Acceptance Scenarios**:

1. **Given** scheduled occurrences dated in the range, **When** a report is read with `include_planned=true`, **Then** a separate `planned` member carries their sums (`income`, `expenses`, `transfers_in`, `transfers_out` in the summary; per item in by-category; per bucket in the trend).
2. **Given** confirmed or skipped occurrences, **When** read, **Then** they are not in `planned` (a confirmed one is already an actual transaction).
3. **Given** `include_planned` absent or `false`, **When** read, **Then** `planned` is null.
4. **Given** a gam'eya payout occurrence, **When** read, **Then** it counts as planned income on its date.
5. **Given** any request, **When** read, **Then** `balance_start` and `balance_end` never include planned amounts.

### Edge Cases

- **Empty range.** All figures zero in the report's currency; `by-category` items are empty arrays; still `200`.
- **Range before the wallet had any transaction, or before it was created.** `balance_start` and `balance_end` both equal the opening balance (it is undated).
- **Wallet joined today, report for last month.** The wallet counts: coverage is by current membership.
- **A transaction dated the day before `from`.** In `balance_start`, not in income or expenses.
- **A transaction dated exactly `from` or `to`.** Inside the range.
- **Backdated edit, re-date, re-categorise, delete or restore.** Reflected on the next read; nothing stale.
- **A deleted wallet with a live transfer into a live wallet** (spec 012 default 6). The transfer still counts in the live wallet's report as `transfers_in`/`transfers_out`.
- **A wallet the caller is a viewer of.** Readable like any membership.
- **Archived category, retired platform category.** Reported like any other.
- **Currency with exponent 3 (KWD).** Amounts in that currency's minor units; nothing assumes two decimals.
- **A sum beyond 2^53 − 1.** Unreachable in practice (over 90 trillion EGP); not designed for, like spec 015.
- **`date`, `from` or `to` outside 1900-01-01 – 2999-12-31, or not a calendar date.** `400 INVALID_QUERY_PARAMETER`.
- **Budget Periods reaching past the date bounds.** `date=1900-01-05` with start day 25, or a 12-period trend ending in 1900, gives periods starting in 1899; they are served as computed (only the request's own dates are bounded).
- **`currency=egp` or `currency=XX`.** `400 INVALID_QUERY_PARAMETER` (uppercase alpha-3 only).
- **`currency` on a wallet report.** Ignored (a wallet report is always in the wallet's currency; not declared on those operations).
- **Caller without a profile.** Today in the default timezone (`Africa/Cairo`), start day 1, default currency EGP.
- **Account pending deletion.** Reports are served like any other read during the grace period.
- **An occurrence of a transfer rule between two covered wallets.** Internal: not in cross-wallet `planned` transfers.
- **Occurrences far ahead.** Only materialised occurrences count (rule's today + 35 days); a range further out under-reports planned money.

## Requirements *(mandatory)*

### Functional Requirements

**Report Range**

- **FR-001**: Every summary and by-category report MUST cover a **Report Range**: an inclusive span of calendar dates `{from, to}`, echoed in the response as `range`.
- **FR-002**: Without `from`/`to`, the Report Range MUST be the caller's Budget Period containing `date` (default: today in the caller's profile timezone), computed from the caller's budget start day history through Budgeting's query service, identically to budget progress (spec 015 FR-008, FR-009). The configured default timezone and start day 1 apply without a profile.
- **FR-003**: With `from` and `to` (both required together, `from` ≤ `to`, span at most 366 days, both within 1900-01-01 – 2999-12-31), the Report Range MUST be exactly those dates. `date` and `from`/`to` MUST NOT be combined. A transaction belongs to the range by its calendar `date` only; no timezone is consulted for membership (P-12, ADR-0011 rule 6).

**Scope**

- **FR-004**: Wallet reports (`/wallets/{id}/reports/*`) MUST be readable by every live member of a live wallet, any role (spec 010 matrix W-01), archived wallets included. A non-member, a former member, an unknown id or a deleted wallet MUST get `404 NOT_FOUND`.
- **FR-005**: A wallet report MUST count every live transaction of the wallet, whoever recorded it, so every member sees identical figures for the same Report Range (ADR-0011 rule 7). It is always in the wallet's currency.
- **FR-006**: Cross-wallet reports (`/reports/*`) MUST cover the **covered wallets**: every live wallet in which the caller holds a live membership **at the time of the read** (any role, archived included) whose currency equals the report's currency, for every range, past ranges included.
- **FR-007**: A cross-wallet figure MUST equal the sum of the covered wallets' own figures, except that a transfer whose source and destination are both covered wallets MUST be left out of `transfers_in` and `transfers_out` (in the balances its two legs cancel, so it changes no covered balance in total).
- **FR-008**: The report's currency MUST be the `currency` parameter (uppercase ISO 4217 alpha-3), default the caller's profile `default_currency` (EGP without a profile). Wallets the caller belongs to (live, archived included) in any other currency MUST be excluded from every figure and listed in `meta.excluded_wallets` as `{wallet_id, currency}` sorted by `wallet_id`; amounts in different currencies MUST NOT be added or converted (P-03 rule 3). A platform category's totals are always restricted to the covered wallets, never summed across all users (ADR-0014 rule 5).

**Figures**

- **FR-009**: The summary MUST carry `income`, `expenses`, `net` (= income − expenses), `transfers_in`, `transfers_out`, `balance_start` and `balance_end`, each Money in the report's currency. `income` and `expenses` are positive sums of income and expense transactions; transfers MUST NOT count as income or expense.
- **FR-010**: `balance_start` MUST be the opening balances of the reported wallets (undated: counted in every range) plus every live wallet posting dated before `from`; `balance_end` the same through `to`. Both MUST be summed from postings, never from `balance_minor` (P-05 rule 3, ADR-0005 rule 5), and MUST satisfy `balance_end = balance_start + income − expenses + transfers_in − transfers_out`. Liability balances are returned negative, unclamped (ADR-0003 rule 8).
- **FR-011**: By-category MUST return `expenses` and `income`, each `{total, items}`, where each item is `{label, category_id, system_key, value}` for one category with a non-zero sum in the range: a platform category is labelled and grouped by its `system_key`, a person's category by its id; two categories are never merged by name (ADR-0014 rule 6). Items are sorted by `value.amount_minor` descending, then `label` ascending; `total` equals the sum of the items.
- **FR-012**: No report MUST return a percentage, share or ratio, and no amount MUST be divided or rounded (ADR-0003 rule 7). The client derives shares from `value` and `total`.
- **FR-013**: The trend MUST NOT accept `from`/`to` (undeclared there, so ignored). It MUST return `months` (1–12, default 6) consecutive Budget Periods of the caller's, oldest first, the last containing `date` (default today), as `periods: [{label, from, to}]` (label = the period's start date) and series `income`, `expenses` and `net`, each an array of `{label, value}` in the same order with one entry per period, zero when empty.
- **FR-014**: With `include_planned=true`, every report MUST add a `planned` member computed from live occurrences with status `scheduled`, dated within the range (or bucket) and in the report's currency, by kind and category exactly like actual transactions, through a Wallets query service over occurrences (B7 inheritance): the summary's `planned {income, expenses, transfers_in, transfers_out}`; each by-category item's and total's `planned` (an item exists if either its actual or planned sum is non-zero); the trend's `planned_income` and `planned_expenses` series. Planned figures MUST NOT enter actual figures or balances. Without it (or `false`), `planned` members and the planned series are null.
- **FR-015**: All money MUST be `{amount_minor, currency}` with integer minor units (P-03); zero is `{amount_minor: 0, currency}`.

**Computation**

- **FR-016**: Reports MUST be served by the Reporting module's `ReportQueryService`, reading the ledger only through Wallets & Ledger application query services, Budget Periods through Budgeting's application layer and the profile through Identity's (P-02).
- **FR-017**: Every report MUST be computed on its read, with no stored or cached figure and no listener on ledger events, so every ledger change is reflected on the next read.
- **FR-017a**: All the reads behind one report response MUST see one consistent snapshot of the ledger (a single read-only, repeatable-read unit of work), so a write committed while the report is being computed can never break the balance identity or make two figures of one response disagree.
- **FR-018**: Reports MUST NOT be syncable: not on any change feed, not a batch item type, no `version` or `change_seq`.

**Requests and access**

- **FR-019**: A malformed or contradictory query (FR-003, `months`, `currency`, `include_planned` other than `true`/`false`) MUST be refused whole with `400 INVALID_QUERY_PARAMETER` (a plain problem, like every other query refusal; no `errors[]`); a malformed wallet id is `422 INVALID_ID`.
- **FR-020**: No report MUST be gated by an entitlement (P-09 rule 5); `reports.advanced` stays reserved. Reads are not behind the verification gate. No rate limit.
- **FR-021**: Report figures MUST NOT be logged; reports add no log line beyond the shared request log (P-15 rule 4). B13 publishes and consumes no domain event.

### Key Entities

- **Report** (Reporting, derived value): a computed view over the ledger — here the summary, by-category and trend reports, per wallet and across wallets. Never stored.
- **Report Range** (Reporting, derived value, new term): the inclusive span of calendar dates a report covers — the caller's Budget Period containing a date, or an explicit `from`/`to`.
- **Covered wallet** (derived, in Report Range's glossary note): a wallet included in a cross-wallet report.
- Read, not owned: Transaction, Posting sums, Occurrence, Category, Wallet, Wallet Member, Budget Period, Profile.

## Project Sections *(mandatory for feature specs)*

### 1. Purpose

Let a person understand their money over a span of dates — in, out, between wallets, by category and month over month — per wallet and across their wallets in one currency, with figures that every member of a shared wallet sees identically, that never mix currencies, and that the phone can reproduce from the transactions it already holds.

### 2. Glossary terms

[Report](../../docs/glossary.md#report), [Budget Period](../../docs/glossary.md#budget-period), [Wallet](../../docs/glossary.md#wallet-محفظة) (opening balance, archived, liability), [Wallet Member](../../docs/glossary.md#wallet-member), [Transaction](../../docs/glossary.md#transaction) (kind, transfer, `counter_wallet_id`), [Posting](../../docs/glossary.md#posting), [Category](../../docs/glossary.md#category) (platform category, `system_key`), [Category Reference](../../docs/glossary.md#category-reference), [Occurrence](../../docs/glossary.md#occurrence) (scheduled, payout), [Profile](../../docs/glossary.md#profile) (timezone, `default_currency`). **Added in this change** (before use in code): **Report Range** (with *covered wallet* and *excluded wallet* in its notes); the Report entry's notes gain the B13 answers.

### 3. Data model

No table, column or persisted state. Reports are derived (glossary: "a query, never a row").

**Wallets** gains one partial covering index for the wallet-leg sums (plan R-3): `postings (wallet_id, transaction_id) INCLUDE (amount_minor) WHERE wallet_id IS NOT NULL`. No money column is added (P-03).

### 4. Invariants

| Invariant | Principle or decision | Test that fails when broken |
|---|---|---|
| `balance_end = balance_start + income − expenses + transfers_in − transfers_out`, per wallet and across wallets | FR-010 | `tests/Unit/Reporting/SummaryFiguresTest.php`, `tests/Feature/Reporting/WalletSummaryTest.php` |
| Totals and balances from postings, never `balance_minor` | P-05 rule 3; ADR-0005 rule 5 | `tests/Feature/Reporting/WalletSummaryTest.php` (corrupted cache case); existing gate `tests/Arch/BalanceCacheTest.php` covers `app/Modules/Reporting` |
| `balance_end` with `to` ≥ the last transaction equals opening + Σ postings (the wallet's truth) | P-05 rule 1 | `tests/Feature/Reporting/WalletSummaryTest.php` |
| Transfers never count as income or expense; internal transfers cancel across wallets | FR-009, FR-007 | `tests/Feature/Reporting/CrossWalletReportTest.php` |
| No cross-currency sum; other-currency wallets listed, never added | P-03 rule 3; FR-008 | `tests/Feature/Reporting/CrossWalletReportTest.php` |
| Same figures for every member of a shared wallet for the same range | ADR-0011 rule 7; FR-005 | `tests/Feature/Reporting/SharedWalletReportTest.php` |
| Period membership by calendar date, not timezone | P-12; ADR-0011 rule 6 | `tests/Feature/Reporting/ReportRangeTest.php` |
| Platform category totals restricted to the covered wallets | ADR-0014 rule 5 | `tests/Feature/Reporting/SharedWalletReportTest.php` (another user's spending in the same platform category is absent) |
| No percentage, no division or rounding of an amount | ADR-0003 rule 7; FR-012 | `tests/Unit/Reporting/CategoryBreakdownTest.php` (items sum exactly to the total), `tests/Contract/Reporting/WalletByCategoryReportTest.php` (the schema has no share member and forbids extra members) |
| One response reads one ledger snapshot | FR-017a | `tests/Feature/Reporting/ReportSnapshotTest.php` (a write committed between the report's queries is not half-seen) |
| Planned figures never enter actual figures or balances | FR-014 | `tests/Feature/Reporting/PlannedFiguresTest.php` |
| Non-members get 404 | P-08 rule 3 | `tests/Contract/Reporting/WalletReportAccessTest.php` |
| Reports never on a feed | FR-018 | `tests/Contract/Reporting/ReportsNotSyncedTest.php` |

### 5. API

Under `/api/v1`, in `docs/openapi/paths/reports.yaml`, tag `Reporting`. **Frozen for the C13 client** (contract first); `info.version` v1.10 → v1.11 (additive). Read-only; any other method is `405 METHOD_NOT_ALLOWED` with `Allow`.

| Method | Path | operationId | Success | Error codes (beyond the shared 401/406/426/500 refusals) |
|---|---|---|---|---|
| GET | `/wallets/{id}/reports/summary` | `getWalletReportSummary` | `200` | `INVALID_QUERY_PARAMETER`, `NOT_FOUND`, `INVALID_ID` |
| GET | `/wallets/{id}/reports/by-category` | `getWalletReportByCategory` | `200` | as above |
| GET | `/wallets/{id}/reports/trend` | `getWalletReportTrend` | `200` | as above |
| GET | `/reports/summary` | `getReportSummary` | `200` | `INVALID_QUERY_PARAMETER` |
| GET | `/reports/by-category` | `getReportByCategory` | `200` | `INVALID_QUERY_PARAMETER` |
| GET | `/reports/trend` | `getReportTrend` | `200` | `INVALID_QUERY_PARAMETER` |

**Query parameters**

| Name | On | Form | Default |
|---|---|---|---|
| `date` | all six | `YYYY-MM-DD`; any day in the wanted Budget Period (trend: the last bucket's) | today in the caller's profile timezone |
| `from`, `to` | summary, by-category | `YYYY-MM-DD`, both or neither, `from` ≤ `to`, ≤ 366 days, never with `date` | the Budget Period containing `date` |
| `months` | trend | integer 1–12 | 6 |
| `include_planned` | all six | `true` or `false` | `false` |
| `currency` | the three `/reports/*` | uppercase ISO 4217 alpha-3 | the caller's profile `default_currency` (EGP) |

**Response shapes** (`data` is one object; reports are not collections and are not paged):

- **Summary**: `{ wallet_id, range {from, to}, currency, income, expenses, net, transfers_in, transfers_out, balance_start, balance_end, planned }` — `planned` null, or `{income, expenses, transfers_in, transfers_out}`. `wallet_id` is the wallet on a wallet report and null on a cross-wallet report (all three reports).
- **By-category**: `{ wallet_id, range, currency, expenses {total, planned, items[]}, income {total, planned, items[]} }`; item `{label, category_id, system_key, value, planned}` (`planned` Money or null).
- **Trend**: `{ wallet_id, currency, periods [{label, from, to}], income [{label, value}], expenses [{label, value}], net [{label, value}], planned_income, planned_expenses }` — the planned series null unless `include_planned=true`.
- **meta**: wallet reports `{}`; cross-wallet reports `{ excluded_wallets: [{wallet_id, currency}] }`.

**Answers C13 needs** (frozen):

| C13 asks | Answer |
|---|---|
| Response shapes, `{label, value}` arrays | As above: `value` is Money; `label` is a machine key — `system_key` (platform category), category id (own or partner's category), or the Budget Period's start date (trend). The client localises it from its local categories, Category References and dates |
| The `Period` definition shared with budgets | The default Report Range and every trend bucket is a **Budget Period** of the caller's, computed exactly as `/budget-progress` (start day history, profile timezone for "today", calendar-date membership). `from`/`to` picks any other span. Every response echoes the span |
| Does `reports.advanced` gate anything | No. Every B13 report is available on every plan; no `FEATURE_NOT_ENTITLED` |
| `meta.excluded_wallets` | Cross-wallet only: `{wallet_id, currency}` for every wallet the caller belongs to in another currency, sorted by `wallet_id`; `[]` when none |
| `include_planned` | Adds separate `planned` figures from scheduled occurrences; actual figures and balances never include them |
| Share per category | Not sent; derive `value / total` for display |
| Offline fallback | Reproducible from local data: same range rules, all members' live transactions of the wallet (cross-wallet: the held wallets in one currency, internal transfers cancelled), opening balance + postings for balances |

**Codes**: `INVALID_QUERY_PARAMETER` (400, a plain problem without `errors[]`), `INVALID_ID` (422, malformed path id), `NOT_FOUND` (404). **No new code.**

### 6. Authorization and entitlements

Wallet reports follow spec 010's matrix row W-01 (read the wallet and its balance): owner, editor and viewer may read; anyone else gets `404 NOT_FOUND` (P-08 rule 3). Cross-wallet reports need only authentication; their scope is the caller's own live memberships, so there is nothing to disclose. No entitlement (P-09 rule 5; `reports.advanced` reserved). No verification gate on reads.

**Precedence** for a wallet report: `401` → `422 INVALID_ID` → `404 NOT_FOUND` (membership) → `400 INVALID_QUERY_PARAMETER` → `200`. Membership is checked before the query so a non-member never learns which parameters a wallet would accept. Cross-wallet: `401` → `400` → `200`.

### 7. Events

None published, none consumed (FR-017, FR-021). Reports re-derive from the ledger on every read, so `TransactionPosted`, `TransactionVoided` and `WalletDeleted` need no Reporting listener.

### 8. Acceptance scenarios

| Story · scenario | Layer | Test file |
|---|---|---|
| US1 · 1, 6 range | Feature | `tests/Feature/Reporting/ReportRangeTest.php` |
| US1 · 2–5 figures and balances | Unit, Feature | `tests/Unit/Reporting/SummaryFiguresTest.php`, `tests/Feature/Reporting/WalletSummaryTest.php` |
| US1 · 7 access | Contract | `tests/Contract/Reporting/WalletReportAccessTest.php` |
| US1 · all, HTTP shape | Contract | `tests/Contract/Reporting/WalletSummaryReportTest.php` |
| US2 · 1–6 by category | Unit, Feature, Contract | `tests/Unit/Reporting/CategoryBreakdownTest.php`, `tests/Feature/Reporting/WalletByCategoryTest.php`, `tests/Contract/Reporting/WalletByCategoryReportTest.php` |
| US3 · 1–5 trend | Feature, Contract | `tests/Feature/Reporting/TrendTest.php`, `tests/Contract/Reporting/WalletTrendReportTest.php` |
| US4 · 1–8 cross-wallet | Feature, Contract | `tests/Feature/Reporting/CrossWalletReportTest.php`, `tests/Feature/Reporting/SharedWalletReportTest.php`, `tests/Contract/Reporting/CrossWalletReportsTest.php` |
| US5 · 1–5 planned | Feature | `tests/Feature/Reporting/PlannedFiguresTest.php` |
| Query refusals | Contract | `tests/Contract/Reporting/ReportQueryRefusalsTest.php` |

### 9. Open questions

1. **Server-side category shares** — deferred; would need an ADR on rounding ratios (ADR-0003 rule 7). Does not block.
2. **Advanced reports behind `reports.advanced`** (counterparties, comparisons, net worth over time, export) — later briefs; enabling a gate would need a P-09 rule 5 amendment. Does not block.
3. **Report caching or event-fed projections** — only if the measured p95 targets fail, under its own decision record. Does not block.
4. **A person's own spending across shared wallets by category** — available as budget progress (spec 015); a separate "mine only" report waits for demand. Does not block.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For every seeded integration case, a wallet summary's `balance_end` (with `to` on or after the last transaction) equals the wallet's opening balance plus all its postings, and the balance identity holds, in 100% of cases.
- **SC-002**: After any ledger change (record, edit, re-date, re-categorise, delete, restore, wallet deletion, leaving a wallet), the next report reflects it in 100% of cases; 0 stale figures.
- **SC-003**: 0 reports that add two currencies, count a transfer as income or expense, include planned amounts in actual figures, or include a wallet the caller does not belong to.
- **SC-004**: Two members of one shared wallet reading its report with the same parameters get identical figures in 100% of cases, whatever their timezones.
- **SC-005**: On the development stack with debugging extensions off, the wallet summary, by-category and 6-period trend over a wallet with 10,000 transactions answer in under 300 ms at the 95th percentile; the cross-wallet reports over 20 wallets and 20,000 transactions in under 500 ms.
- **SC-006**: The phone's offline figures computed with the published rules from the same transactions equal the server's in 100% of the C13 integration checkpoint cases.

## Assumptions

- Spec 010's membership and authorization, spec 011's categories, spec 012's ledger (one transfer record, postings removed on delete), spec 014's occurrences, spec 015's Budget Period arithmetic and `BudgetStartDayQuery`, and spec 008's profile (timezone, `default_currency`) are reused, not wrapped.
- The Reporting module directory (`app/Modules/Reporting`) is created by this feature, as the constitution lists it with `ReportQueryService`.
- A transaction's currency always equals its wallet's (spec 012), so a wallet report has exactly one currency.
- Sizing: 10,000 transactions per wallet (spec 012), 20 wallets per person for the cross-wallet target, up to a few hundred categories in one by-category answer.
- No reference data: nothing to seed.
