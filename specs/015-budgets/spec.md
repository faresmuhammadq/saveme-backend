# Feature Specification: Budgets

**Feature Branch**: `015-budgets`

**Created**: 2026-10-02

**Status**: Draft

**Input**: Brief B8 ([specs/briefs/B08-budgets.md](../briefs/B08-budgets.md)): "A budget sets a limit for a category (or all expenses) in a wallet per period, where the period is monthly by default and starts on the profile's start day; progress = spent / limit for the current period, with `over_limit` and `projected_end` fields. Endpoints: `GET /wallets/{id}/budgets?period=`, `PUT /wallets/{id}/budgets/{bid}`, `PATCH`, `DELETE`. Optional rollover flag for later." Plus the brief's considerations, its reconcile table, its binding "Inherited from B5/B6/B7/B14" sections, the six Budgeting questions banked in [specs/README.md](../README.md), and the paired client brief [C8](../../../saveme-kmp/specs/briefs/C08-budgets.md) "Needs from the backend" (answers to the six questions, the Budget Period boundaries, the progress fields).

> **Nature of this feature.** B8 lets a person set a monthly spending limit for one category, or for all their expenses, and see how far into it they are: spent, remaining, whether they are over, and where the month is heading. It builds the **Budget** (a user-scoped syncable record in the Budgeting module), the **Budget Period** arithmetic over the profile's budget start day, and **Budget Progress**, a read model computed from the ledger on every read. It builds no notifications (B16), no reports (B13), no rollover and no forecast of scheduled occurrences.
>
> **Where the brief and the ratified decisions differ, the ratified side wins** (constitution, glossary, ADR-0003, ADR-0011, ADR-0014, ADR-0017, specs 008, 011–014):
>
> | Brief | In force | Here |
> |---|---|---|
> | A budget "in a wallet", paths under `/wallets/{id}/budgets` | Glossary: **a budget is personal, user-scoped, never in a wallet feed** | Paths move to the user scope: `/budgets`, `/budgets/{id}`, `/budget-progress`, `/budgets/{id}/progress`; budgets ride the user feed |
> | `Period` value object | Glossary: **Budget Period** (derived) and Billing Period are distinct; never the bare word "Period" | The derived window is the **Budget Period**; the query parameter is `date` (any day inside the wanted Budget Period) |
> | "progress = spent / limit" as a percentage | ADR-0003 rule 7: no division or rounding of an amount outside `Money::allocate()`; MVP defines no rounding policy for "percentage division" | The server returns `spent`, `limit`, `remaining` and `over_limit` exactly; the percentage is a presentation figure the client derives. `projected_end` is computed with `Money::allocate()` (rounded up to the minor unit), never with `/` or a rounding function |
> | "Cache per (budget, period) invalidated by ledger events" | P-05 rule 3 (a cache is never truth); P-11 (idempotent queued listeners) | **Computed on read, no cache.** One grouped query per request answers every budget of the person, so there is nothing to invalidate and no listener on `TransactionPosted`, `TransactionVoided` or `WalletDeleted`; the inherited listener notes are satisfied because a read always re-derives from the ledger. A cache may be added later behind the same query service if a measurement demands it |
> | "Optional rollover flag for later" | Glossary "Out of MVP": **Budget period rows with rollover** need a constitution amendment (P-17) | No rollover member, not even reserved; a later additive member |
> | Overrun alerts implied by `over_limit` | Constitution lists `BudgetExceeded` under Budgeting; push delivery is B16 | `over_limit` is exposed; **no event, no threshold, no notification in B8**. B16 decides the event and delivery |

## Clarifications

### Session 2026-10-02

Owner away with delegated authority; each answer below is the recommended (most conservative) option, recorded as an **assumed default**. The first six are the six banked Budgeting questions.

- Q (banked 1): How is a transaction in a **shared** wallet attributed to one member's budget — by the owner of the category it references, or by its `created_by`? → A: **By `created_by`.** A budget counts only transactions its owner recorded (for a recurring rule's transactions, the rule's author, spec 014 A-23), in any wallet the owner is currently a live member of. A platform category has no owner, so "category owner" cannot attribute the most common case; `created_by` is on every transaction the phone already holds, so the phone computes the same figure offline. A partner's spending never enters your budget, and re-categorising someone else's transaction cannot move it into your budget. *Assumed default.* (FR-010)
- Q (banked 2): What currency is a budget in when its owner holds wallets in several? → A: **Each budget names one currency, fixed at creation, and counts only spending in that currency.** No cross-currency sum and no conversion (P-03 rule 3, FX out of scope). Spending in other currencies that would otherwise match is reported by currency code only (`other_currencies`), never added. A person who wants a cap in two currencies keeps two budgets. *Assumed default.* (FR-004, FR-012)
- Q (banked 3): May an `income` category carry a budget? May budgets overlap? → A: **Expense categories only** (`INVALID_ENUM_VALUE` at `/category_id` otherwise, the same answer as unknown, foreign and deleted). **Overlap is allowed**: no uniqueness per (person, category, currency), and an all-expenses budget may sit beside category budgets. Two phones may each create "Groceries" offline; refusing the second would need a new code and leave a record the phone cannot sync. Duplicate category names are allowed for the same reason (spec 011). The client prevents duplicates in its own picker. *Assumed default.* (FR-003, FR-005)
- Q (banked 4): What happens to a live budget when its category is archived? → A: **Nothing.** It keeps counting, as an archived category stays valid on transactions (spec 012 FR-005) and the server never rewrites a record the person did not touch. A new budget may name an archived (or retired platform) category, so a budget created offline is never refused because the category was archived meanwhile. A category any budget references, live or tombstoned, is **in use** and cannot be deleted. *Assumed default.* (FR-003, FR-018)
- Q (banked 5): Which module serves budget progress? → A: **Budgeting**, through `BudgetQueryService`, from totals read through a new Wallets & Ledger query service (P-02). Reporting (B13) calls the same query service; it does not own budget progress. *Assumed default.* (FR-013)
- Q (banked 6): Are budget-overrun notifications in scope? → A: **No.** B8 exposes `over_limit` and `projected_end`; it publishes no event and stores no threshold or "already notified" state. B16 (devices and push) specifies `BudgetExceeded` and its delivery, and gets an "Inherited from B8" note. *Assumed default.* (FR-022)
- Q: Is progress computed on read or cached? → A: **On read**, every time, from postings (see the reconcile table). *Assumed default.* (FR-013)
- Q: How are Budget Period boundaries found when the budget start day changed? → A: **From the start day history Identity keeps.** Today the profile holds only the current start day and the date it applies from, so the day that governed earlier periods is lost on the next change. Identity records each change as a dated entry (`start_day`, `applies_from`) in the same unit of work as the profile save; a pending entry (its date not yet reached) is replaced by a newer change. A Budget Period begins on an entry's `applies_from` and then every month on that entry's day, and the period in progress when the next entry takes effect ends the day before it, so periods already begun keep their dates (spec 008 FR-013). Profiles already changed are backfilled. *Assumed default.* (FR-007, FR-008)
- Q: What is "the current Budget Period"? → A: The one containing **today in the owner's profile timezone** (ADR-0011 rule 8). Membership of a transaction in a period is by its calendar date only (rule 6). *Assumed default.* (FR-009)
- Q: Do scheduled occurrences (B7) show as "upcoming" spending? → A: **No.** Scheduled occurrences have no postings and never enter `spent` or `projected_end`; a forecast over them is B13's. The client may show them beside a budget from its own wallet feeds. *Assumed default.* (FR-011)
- Q: What does the limit of a past Budget Period mean, when the limit has since changed? → A: **The budget's current limit.** There are no per-period rows in MVP (glossary: "Budget period rows with rollover" are out of scope), so a past period's progress compares that period's spending with today's limit. *Assumed default.* (FR-014)

### Session 2026-10-02 (clarify pass)

- Q: Does a transaction still count when its author is now only a `viewer` of the wallet (demoted after recording)? → A: **Yes — any live membership counts, whatever the role.** The phone of a viewer still holds the wallet's transactions, so the figure stays reproducible offline; the role governs writing, not whose spending it was. *Assumed default.* (FR-010)
- Q: Which timezone decides "today" when the person has no profile row? → A: **The configured default timezone (`Africa/Cairo`)**, the same fallback spec 014 uses for a rule's timezone. *Assumed default.* (FR-009)
- Q: What is logged for budget reads and writes? → A: **Nothing beyond the shared request log line.** Limits, spending and projections are financial payloads and are never logged (P-15 rule 4); there is no job, so no summary line. *Assumed default.* (FR-024)

Further defaults taken while writing the spec, each the conservative option and listed again in the plan's Assumed defaults:

- `category_id`, `currency` and `period_type` are immutable after creation (`IMMUTABLE_FIELD`): changing them would silently change what every past period meant. Only the limit (`amount_minor`) is editable.
- `period_type` is `monthly`, the only value in MVP, and is required so a later type is additive.
- Archive is one-way, like a category: an archived budget leaves the progress list and keeps its record; delete is a final tombstone with no restore.
- No new error code, no entitlement (P-09 rule 5), no rate limit, no seeder, no event. Every write is behind the verification gate.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Set a monthly limit for a category (Priority: P1)

A person who spends too much on groceries sets a 6,000 EGP monthly budget for their Groceries category, possibly offline. Their other phone receives it on the next sync. The budget counts their own grocery expenses in their EGP wallets for the Budget Period that starts on their salary day.

**Why this priority**: this is the feature. Without a budget there is nothing to measure.

**Independent Test**: as a verified person, create a budget on an expense category with a client id; read it back; replay the same write; read the user feed and find a `budget` row.

**Acceptance Scenarios**:

1. **Given** a verified person and their expense category, **When** they create a budget with a new UUIDv7, `amount_minor` 600000, `currency` EGP and `period_type` monthly, **Then** it is stored as theirs with `version` 1 and the response is `201`.
2. **Given** the same write sent twice, **When** the second arrives, **Then** it is `200` with nothing changed (`version` and `change_seq` unmoved).
3. **Given** a budget with `category_id` null, **When** it is created, **Then** it is an all-expenses budget.
4. **Given** an income category, a partner's category, an unknown or a deleted category, **When** a budget names it, **Then** the write is refused with `422`, `INVALID_ENUM_VALUE` at `/category_id`, one answer for all four.
5. **Given** an archived category of the person's own, or a retired platform category, **When** a budget names it, **Then** the budget is created.
6. **Given** a person whose address is not verified, **When** they write a budget, **Then** it is `403 EMAIL_NOT_VERIFIED`; reading is allowed.
7. **Given** another person's budget id, **When** anyone else reads or writes it, **Then** it is `404 NOT_FOUND`, nothing disclosed or overwritten.

---

### User Story 2 - See how far into my budgets I am (Priority: P1)

On the home screen the person sees, for each active budget, how much they have spent this Budget Period, how much is left, whether they are over, and what they will have spent by the period's end at the current pace. Numbers only; the app chooses the colours.

**Why this priority**: progress is why a person sets a budget.

**Independent Test**: create a 6,000 EGP Groceries budget; record two expenses of 1,000 and 2,500 EGP dated in the current period; read progress: spent 3,500, remaining 2,500, not over; record a 3,000 expense: spent 6,500, remaining −500, over.

**Acceptance Scenarios**:

1. **Given** a budget and the owner's expenses in its category, currency and current period, **When** progress is read, **Then** `spent` is their sum, `remaining` is limit − spent (negative when over), and `over_limit` is true exactly when spent > limit.
2. **Given** a transfer, an income, a deleted (voided) transaction, a transaction dated outside the period, one in another currency, or one in another category, **When** progress is read, **Then** none is in `spent`; a matching expense in another currency appears only as its code in `other_currencies`.
3. **Given** the current Budget Period, **When** progress is read, **Then** `projected_end` is `spent` extrapolated over the period's days at the pace so far (`spent × days_in_period ÷ days_elapsed`, rounded up to the minor unit); for a past or future period it is null.
4. **Given** an all-expenses budget, **When** progress is read, **Then** `spent` is every expense the owner recorded in that currency and period, whatever the category, platform or own.
5. **Given** several active budgets, **When** the progress list is read, **Then** each appears once with its period, and archived budgets do not appear.
6. **Given** a backdated edit moving an expense from last period into this one, or a re-categorisation out of the budget's category, **When** progress is read next, **Then** both periods reflect it immediately (no stale figure).

---

### User Story 3 - Budgets in a shared wallet stay personal (Priority: P1)

Two partners share a household wallet. Each has their own Groceries budget. When she records groceries in the shared wallet, it counts toward her budget and not his; he never sees her budget.

**Why this priority**: shared wallets are the product's social surface, and a budget that silently counts a partner's spending would be wrong for both of them.

**Independent Test**: two members of one wallet, each with a budget on the platform Groceries category; each records an expense; each reads progress and sees only their own expense.

**Acceptance Scenarios**:

1. **Given** two members of a shared wallet each with a budget on the same platform category, **When** each records an expense in that wallet, **Then** each budget's `spent` includes only its owner's expense.
2. **Given** a partner re-categorises my transaction into their own category, **When** my progress is read, **Then** that transaction no longer counts toward my category budget (it still counts toward my all-expenses budget, being my expense), and it does not count toward any budget of theirs (they did not record it).
3. **Given** I leave (or am removed from) a shared wallet, **When** my progress is read, **Then** my transactions in that wallet no longer count.
4. **Given** a transaction produced by a recurring rule, **When** budgets are read, **Then** it counts for the rule's author, whoever confirmed it.
5. **Given** any member of a shared wallet, **When** they read the wallet feed, **Then** no budget appears in it.

---

### User Story 4 - Change, archive or delete a budget (Priority: P2)

The person raises their Groceries limit, archives a budget they no longer track, or deletes one created by mistake. A change made on an offline phone that clashes with a newer one is refused with the current state so the phone can merge.

**Why this priority**: editing is needed soon after creation, but a budget is useful before it.

**Independent Test**: save a new limit with the current version; save again with the old version and get `409` with `current`; archive; delete; read the tombstone.

**Acceptance Scenarios**:

1. **Given** a budget at version n, **When** the owner saves a new limit (whole `PUT` or `PATCH`) carrying n, **Then** it is stored at n + 1.
2. **Given** a stale version, **When** a save arrives, **Then** it is `409 VERSION_CONFLICT` with the current budget in `current` and nothing applied.
3. **Given** a save that changes `category_id`, `currency` or `period_type`, **When** it arrives, **Then** it is `422` with `IMMUTABLE_FIELD` at that member.
4. **Given** an active budget, **When** it is archived with its version, **Then** `archived_at` is set, it leaves the progress list and stays in the budget list; archiving again returns it unchanged.
5. **Given** a budget, **When** it is deleted with its version, **Then** it becomes a tombstone (`deleted_at` set), leaves both lists, rides the user feed as a deletion, and any later edit is `409 VERSION_CONFLICT` with the tombstone in `current`. Deleting a tombstone returns it unchanged; there is no restore.
6. **Given** a category any budget references (live, archived or tombstoned), **When** its owner tries to delete the category, **Then** it is `409 CATEGORY_IN_USE`, and the category shows `in_use: true`.

---

### User Story 5 - Budget Periods follow my salary day (Priority: P2)

A person paid on the 25th sets their budget start day to 25. Their Budget Periods then run 25th to 24th. When they change the start day, the period already under way keeps its start, and the new day applies from the next period; looking back at an older month shows the dates that period really had.

**Why this priority**: periods are the unit of every figure, but the default (day 1, calendar months) works for most people.

**Independent Test**: with start day 1, change it to 25 on the 10th; read progress for today (period 1st → 24th, the day before the new day applies), for a date after the change takes effect (25th → 24th), and for a date last month (1st → last day).

**Acceptance Scenarios**:

1. **Given** start day 25 since forever, **When** progress is read for 2026-10-02, **Then** the period is 2026-09-25 to 2026-10-24.
2. **Given** start day 1 changed to 25 on 2026-10-10 (applies from 2026-10-25), **When** progress is read for 2026-10-12, **Then** the period is 2026-10-01 to 2026-10-24; for 2026-10-30 it is 2026-10-25 to 2026-11-24; for 2026-09-15 it is 2026-09-01 to 2026-09-30.
3. **Given** a change still pending (its date not reached) and a second change, **When** periods are computed, **Then** the first change never takes effect and the period under way ends the day before the second change's date.
4. **Given** a person in `Africa/Cairo` and one in `America/New_York`, **When** a transaction dated 2026-09-30 is counted, **Then** it is in the period containing 2026-09-30 for both; only "today" (the default period) follows each owner's timezone.
5. **Given** no `date`, **When** progress is read, **Then** the period is the one containing today in the owner's profile timezone.

---

### User Story 6 - Budgets sync like every user record (Priority: P2)

The phone keeps budgets offline, pushes them in a batch with categories and transactions, and pulls them from the user feed. Progress is computed on the phone from the same rules for instant feedback, then replaced by the server figure.

**Why this priority**: offline-first is the product's contract, but it reuses the existing feed and batch.

**Independent Test**: create a budget through `POST /sync/batch` with a `budget` upsert item; read it on the user feed; delete it through a batch `delete` item; see the tombstone on the feed.

**Acceptance Scenarios**:

1. **Given** a batch item of type `budget` and action `upsert`, **When** the batch is applied, **Then** its result equals what `PUT /budgets/{id}` would answer for the same write.
2. **Given** a batch item of type `budget` and action `delete`, **When** applied, **Then** its result equals `DELETE /budgets/{id}`.
3. **Given** a budget written, archived or deleted, **When** the user feed is read past the previous cursor, **Then** one `budget` row carries the record exactly as `GET /budgets/{id}` renders it.
4. **Given** a budget tombstone older than 90 days, **When** the daily purge runs, **Then** it is removed and the person's purge horizon rises; afterwards the category it referenced is no longer kept in use by it.

### Edge Cases

- **Budget created mid-period.** It counts the whole period's spending, including expenses dated before the budget existed.
- **No spending.** `spent` is 0 in the budget's currency, `remaining` equals the limit, `projected_end` is 0 in the current period.
- **Over the limit.** `remaining` is negative and `over_limit` is true; the server never clamps or colours.
- **Exactly at the limit.** `remaining` 0, `over_limit` false.
- **First day of the period.** `days_elapsed` is 1 and `projected_end` is `spent × days_in_period`.
- **Last day of the period.** `projected_end` equals `spent`.
- **Projection overflow.** If `spent × days_in_period` would exceed 2^53 − 1 (the API's integer bound), `projected_end` is null rather than wrong.
- **Spending sum beyond 2^53 − 1.** Unreachable in practice (over 90 trillion EGP in one period); not designed for.
- **Refunds.** MVP has no refund kind; an income does not reduce a budget's spending.
- **Liability wallets.** An expense paid from a credit-card wallet counts like any other expense.
- **Archived wallet.** Its expenses still count while the owner is a member (archiving hides from pickers only).
- **Deleted wallet.** Its transactions are tombstoned (spec 012 FR-027) and drop out on the next read.
- **Currency with exponent 3 (KWD).** Amounts stay in that currency's minor units; nothing assumes two decimals.
- **`date` outside 1900-01-01 – 2999-12-31, or not a calendar date.** `400 INVALID_QUERY_PARAMETER`.
- **Progress of a tombstoned budget.** `404 NOT_FOUND` (the record is readable at `/budgets/{id}`, progress is not).
- **Progress of an archived budget by id.** Served; archived budgets are omitted only from the progress list.
- **Start day 29–31.** Impossible (profile range 1–28), so every month has the start day.
- **`rollover` or any unknown member in a write.** `422 UNKNOWN_FIELD`.
- **Account pending deletion.** Budgets are read and written like any other record during the grace period; purging them with the account is B15's.

## Requirements *(mandatory)*

### Functional Requirements

**Budgets**

- **FR-001**: A verified person MUST be able to create a budget offline-first, addressed by a client-generated UUIDv7, owned by them (user scope). A repeated create MUST NOT produce a second budget (P-06 rule 2).
- **FR-002**: A budget MUST carry `category_id` (a category id, or null for all expenses; immutable), `amount_minor` (the limit, an integer 1 to 2^53 − 1), `currency` (uppercase ISO 4217; immutable), `period_type` (`monthly`; immutable), server-owned `archived_at`, `user_id`, and the syncable members `version`, `change_seq`, `created_at`, `updated_at`, `deleted_at`.
- **FR-003**: A named category MUST be an `expense` category that is live (archived or retired allowed) and either the writer's own or a platform category — except that a save leaving `category_id` unchanged is never re-checked. Anything else MUST be refused with `INVALID_ENUM_VALUE` at `/category_id`, one answer for unknown, foreign, deleted and income categories.
- **FR-004**: `currency` MUST NOT be checked against any wallet: a budget may be in any valid currency.
- **FR-005**: Budgets MAY overlap: no uniqueness on (owner, category, currency, period type).
- **FR-006**: Writes MUST follow the syncable write rules (spec 003, spec 011 discipline): whole save `PUT` and partial save `PATCH` (`amount_minor` only) carry `version`; stale → `409 VERSION_CONFLICT` with `current`; identical → `200` with nothing changed; any non-delete write to a tombstone → `409` with the tombstone; version above 0 for an unknown id → `404`; PATCH never creates; a changed immutable member → `IMMUTABLE_FIELD`; archive (`POST /budgets/{id}/archive`, one-way, repeat returns unchanged; an archived budget's limit stays editable, as an archived category stays renameable) and delete (`DELETE` with `{version}`, final tombstone, repeat returns the tombstone) carry `version`. No restore.

**Budget Periods**

- **FR-007**: Identity MUST keep the person's **budget start day history**: an ordered list of (`start_day` 1–28, `applies_from` date or null for the first entry); a person with no entry has the implicit single entry (current day, null). Saving a profile with a changed start day MUST, in the same unit of work, first record the day in force as (day, null) if the list is empty, remove any entry whose `applies_from` is after the person's today (a change that never took effect), and append (`new day`, `budget_period_start_day_from`). Existing profiles with `budget_period_start_day_from` set MUST be backfilled with (1, null) and (current day, `…_from`). The history is server-internal and not on the wire.
- **FR-008**: The Budget Period containing a date D MUST be computed from the history: take the last entry E with `applies_from` ≤ D (or the first entry); the period starts on the latest date S ≤ D with S ≥ E.`applies_from` whose day of month is E.`start_day` (or on E.`applies_from` itself); it ends the day before the next start on E's day, or the day before the next entry's `applies_from` if that comes first. Periods are calendar dates, never instants.
- **FR-009**: "Today" and the default period MUST use the owner's profile timezone at read time (the configured default timezone, and start day 1, when there is no profile); a transaction's membership in a period MUST be decided by its calendar `date` only (P-12, ADR-0011 rules 6–8).

**Spending and progress**

- **FR-010**: A budget's **spent** for a Budget Period MUST be the sum of category-leg postings of transactions that are: live (not tombstoned), `kind = expense`, `created_by` = the budget's owner, dated within the period, in the budget's currency, in a live wallet where the owner currently holds a live membership of any role, and — unless the budget is all-expenses — filed under the budget's category. Totals MUST come from postings, never from `balance_minor` (P-05), and a platform category's sum is always restricted to that owner (ADR-0014 rule 5).
- **FR-011**: Transfers, incomes, scheduled or skipped occurrences (which have no postings), and other people's transactions MUST NOT count.
- **FR-012**: Spending that matches every condition except currency MUST be reported only as the sorted list of those currency codes (`other_currencies`), never summed into `spent`.
- **FR-013**: Budget Progress MUST be computed on every read by Budgeting's query service from a Wallets & Ledger query service, with no stored or cached figure, so every ledger change (record, amend, re-date, re-categorise, void, restore, wallet deletion, membership loss) is reflected on the next read.
- **FR-014**: Progress for one budget and one Budget Period MUST carry: `budget_id`; `period` {`start`, `end`, `days_in_period`, `days_elapsed` (null unless current)}; `is_current`; `limit` (the budget's current `amount_minor` and currency); `spent`; `remaining` (limit − spent, may be negative); `over_limit` (spent > limit); `projected_end` (current period only, else null); `other_currencies`. Money members are `{amount_minor, currency}`.
- **FR-015**: `projected_end` MUST be `spent × days_in_period` split into `days_elapsed` equal parts by `Money::allocate()`, taking the largest part (spent extrapolated linearly, rounded up to the minor unit), and null when the product exceeds 2^53 − 1. No `/` operator or rounding function is applied to an amount (ADR-0003 rule 7). No percentage is returned.
- **FR-016**: `GET /budget-progress?date=` MUST return progress for every live, non-archived budget of the caller for the Budget Period containing `date` (default: the caller's today), as a collection paged by the shared cursor and limit, sorted by budget `created_at` then `id`, no filters or sorts. The first page resolves the Budget Period (from `date`, or today); its cursor carries that period, so later pages of the same walk use it even if the day changes mid-walk, and a cursor sent with a `date` in another Budget Period is `400 INVALID_CURSOR`. `GET /budgets/{id}/progress?date=` MUST return one live budget's progress, archived included; a tombstone, or another person's budget, is `404`.

**Reads, feed, batch, usage, purge**

- **FR-017**: `GET /budgets` MUST list the caller's live budgets (archived included), sorted by `created_at` (default) or nothing else, stabilised by `id`, no filters; `GET /budgets/{id}` returns the caller's budget, tombstone included.
- **FR-018**: Budgeting MUST register a `CategoryUsage` participant that calls a category used while any budget, live or tombstoned, references it, answering `usedAmong` set-wise in one query.
- **FR-019**: Budgets MUST ride the owner's user feed as `budget` rows, rendered exactly as `GET /budgets/{id}`, stamped from the owner's user-scope counter (`UserScopeCounter`) taken after any category lock (ADR-0017 rule 5). They MUST NOT appear on any wallet feed.
- **FR-020**: `POST /sync/batch` MUST accept item type `budget` with actions `upsert` (→ `PUT /budgets/{id}`) and `delete` (→ `DELETE /budgets/{id}`), with no `wallet_id`; results equal the single write's. Archive stays per-record only.
- **FR-021**: The daily tombstone purge MUST remove budget tombstones older than the retention horizon and raise the owner's purge horizon; a category kept only by a purged budget becomes deletable and purgeable.
- **FR-022**: B8 MUST NOT publish or consume domain events, store thresholds, or send notifications.
- **FR-023**: Every budget write MUST be behind the verification gate; reads are not. No entitlement check (P-09 rule 5).
- **FR-024**: Budget amounts, spending and projections MUST NOT be logged; budget operations add no log line beyond the shared request log (P-15 rule 4).

### Key Entities

- **Budget** (Budgeting, user scope, syncable): one person's monthly limit for one expense category or all expenses, in one currency. States `active` → `archived`; either → tombstoned.
- **Budget Period** (Budgeting, derived): a window of calendar dates computed from the owner's budget start day history.
- **Budget Progress** (Budgeting, derived read model): spent, remaining, over-limit and projection of one budget in one Budget Period.
- **Budget start day history** (Identity, server-internal): the dated list of start days behind the profile's current `budget_period_start_day`.

## Project Sections *(mandatory for feature specs)*

### 1. Purpose

Let a person cap their own spending per category, or overall, per Budget Period, and see neutral figures — spent, remaining, over or not, and where the period is heading — that match on every device, offline and online, and never mix currencies or partners' spending.

### 2. Glossary terms

[Budget](../../docs/glossary.md#budget), [Budget Period](../../docs/glossary.md#budget-period), [Profile](../../docs/glossary.md#profile) (`budget_period_start_day`, `budget_period_start_day_from`), [Category](../../docs/glossary.md#category) (in use, platform category, archived), [Transaction](../../docs/glossary.md#transaction) (`created_by`, kind), [Posting](../../docs/glossary.md#posting), [Wallet Member](../../docs/glossary.md#wallet-member), [Occurrence](../../docs/glossary.md#occurrence), [Change Row](../../docs/glossary.md#change-row), [Sync Batch](../../docs/glossary.md#sync-batch), [Purge Horizon](../../docs/glossary.md#purge-horizon). **Added in this change** (before use in code): **Budget Progress** (derived read model) and **budget start day history** (Profile notes); the Budget entry's "Open" paragraph is replaced by the answers above.

### 3. Data model

**`budgets`** (Budgeting, `app/Modules/Budgeting/Infrastructure`): `id` uuid PK (client UUIDv7); `user_id` uuid (no FK across modules); `category_id` uuid null (no FK across modules; validated through Wallets' application layer); `period_type` varchar (`monthly`); `amount_minor` bigint CHECK 1..2^53 − 1; `currency` char(3) CHECK `^[A-Z]{3}$`; `archived_at` timestamptz(6) null; `version` int ≥ 1; `change_seq` bigint; `created_at`, `updated_at`, `deleted_at` timestamptz(6). Indexes: (`user_id`, `change_seq`); (`user_id`, `created_at`, `id`) WHERE `deleted_at` IS NULL; (`category_id`) WHERE `category_id` IS NOT NULL.

**`budget_start_days`** (Identity): `user_id` uuid FK users; `start_day` smallint CHECK 1–28; `applies_from` date null; unique (`user_id`, `applies_from`) with at most one null per user.

**Wallets** gains an index for the spending query: `transactions (created_by, date)` WHERE `deleted_at IS NULL AND kind = 'expense'`.

No money column other than integer minor units (P-03).

### 4. Invariants

| Invariant | Principle or decision | Test that fails when broken |
|---|---|---|
| Spent counts only the owner's own live expenses, in the budget's currency, category and period, in wallets they belong to | FR-010, FR-011 | `tests/Feature/Budgeting/BudgetSpendingTest.php` |
| No cross-currency sum | P-03 rule 3; FR-012 | `tests/Feature/Budgeting/BudgetSpendingTest.php`, `tests/Unit/Budgeting/BudgetProgressTest.php` |
| Transfers never count | Glossary, ADR-0012; FR-011 | `tests/Feature/Budgeting/BudgetSpendingTest.php` |
| Totals from postings, never `balance_minor` | P-05 rule 3 | `tests/Feature/Budgeting/BudgetSpendingTest.php` (corrupted cache case) |
| Platform category totals scoped to the owner | ADR-0014 rule 5 | `tests/Feature/Budgeting/BudgetSharedWalletTest.php` |
| Period membership by calendar date, not timezone | P-12; ADR-0011 rules 6–7 | `tests/Feature/Budgeting/BudgetPeriodTimezoneTest.php` |
| Periods already begun keep their dates after a start day change | Spec 008 FR-013; FR-007, FR-008 | `tests/Unit/Budgeting/BudgetPeriodsTest.php`, `tests/Feature/Identity/BudgetStartDayHistoryTest.php` |
| No division or rounding of an amount outside `allocate` | ADR-0003 rule 7; FR-015 | `tests/Unit/Budgeting/BudgetProgressTest.php` |
| Stale version rejects the whole write | P-07 | `tests/Feature/Budgeting/SaveBudgetTest.php` |
| Immutable members stay immutable | FR-002, FR-006 | `tests/Feature/Budgeting/SaveBudgetTest.php` |
| A budgeted category is never deleted | FR-018; spec 011 | `tests/Feature/Budgeting/BudgetCategoryUsageTest.php` |
| Budgets never on a wallet feed; feed row equals the read | Glossary; FR-019 | `tests/Contract/Sync/BudgetFeedTest.php` |
| Another person's budget is not found | P-08 rule 3 spirit; FR-016 | `tests/Contract/Budgeting/BudgetAccessTest.php` |
| Lock order: category, then user counter | ADR-0017 rule 5 | `tests/Feature/Budgeting/BudgetLockOrderTest.php` |

### 5. API

Under `/api/v1`, in `docs/openapi/paths/budgets.yaml`, tag `Budgeting`. **Frozen for the C8 client** (contract first); `info.version` v1.7 → v1.8 (additive). The `budget` feed row and batch item type are added to `docs/openapi/paths/sync.yaml`.

| Method | Path | operationId | Success | Error codes (beyond the shared 400/401/406/415/426/500 refusals) |
|---|---|---|---|---|
| GET | `/budgets` | `listBudgets` | `200` collection | `INVALID_CURSOR`, `INVALID_QUERY_PARAMETER`, `FILTER_FIELD_NOT_SUPPORTED`, `SORT_FIELD_NOT_SUPPORTED` |
| GET | `/budgets/{id}` | `getBudget` | `200` | `NOT_FOUND`, `INVALID_ID` |
| PUT | `/budgets/{id}` | `upsertBudget` | `201` create, `200` update/replay | `EMAIL_NOT_VERIFIED`, `NOT_FOUND`, `VERSION_CONFLICT`, `INVALID_ID`, `VALIDATION_FAILED` |
| PATCH | `/budgets/{id}` | `patchBudget` | `200` | as PUT |
| DELETE | `/budgets/{id}` | `deleteBudget` | `200` tombstone | as PUT |
| POST | `/budgets/{id}/archive` | `archiveBudget` | `200` | as PUT |
| GET | `/budget-progress` | `listBudgetProgress` | `200` collection | `INVALID_CURSOR`, `INVALID_QUERY_PARAMETER`, `FILTER_FIELD_NOT_SUPPORTED`, `SORT_FIELD_NOT_SUPPORTED` |
| GET | `/budgets/{id}/progress` | `getBudgetProgress` | `200` | `NOT_FOUND`, `INVALID_ID`, `INVALID_QUERY_PARAMETER` |

Any other method on these paths is `405 METHOD_NOT_ALLOWED` with `Allow`.

**Budget** (response; `SyncableResource` + `UserScoped`; also the `budget` feed row): `category_id` (uuid or null), `period_type` (`monthly`), `amount_minor`, `currency`, `archived_at`.

**Budget write** (`PUT`): `category_id`, `period_type`, `amount_minor`, `currency` (all required; `category_id` may be null), `version` (omitted or 0 to create). **Patch**: `amount_minor` and `version` (≥ 1). **Delete / archive body**: `{ "version": n }`. Anything else is `UNKNOWN_FIELD`.

**Budget Progress** (response, not syncable): `budget_id`, `period {start, end, days_in_period, days_elapsed}`, `is_current`, `limit`, `spent`, `remaining`, `projected_end` (Money or null), `over_limit`, `other_currencies` (array of codes).

**Query parameter** `date` (both progress operations): `YYYY-MM-DD`, 1900-01-01 – 2999-12-31; malformed → `400 INVALID_QUERY_PARAMETER`; absent → today in the caller's profile timezone.

**Answers C8 needs** (frozen):

| C8 asks | Answer |
|---|---|
| Shared-wallet attribution | By the transaction's `created_by` (a rule's transactions: its author), in wallets the owner currently belongs to |
| Currency | One currency per budget, immutable; only that currency counts; others listed in `other_currencies` |
| Income budgets | No — expense categories, or `category_id: null` for all expenses |
| Overlap | Allowed; no uniqueness |
| Archived category | The budget keeps counting; an archived category may be budgeted |
| Where progress is served | Budgeting: `GET /budget-progress`, `GET /budgets/{id}/progress`; computed on read |
| Budget Period boundaries | Start on the profile's start day each month, in force from `budget_period_start_day_from`; the period under way at a change ends the day before that date; membership by calendar date; "today" by the profile timezone. Each progress response carries `period.start`/`end`, which the phone uses for the current period while a change is pending |
| Progress fields | `spent`, `remaining`, `limit`, `over_limit`, `projected_end` (rounded up, current period only), `period`, `is_current`, `other_currencies`. Percentage: derive `spent / limit` on the phone for display only |
| Upcoming occurrences | Not counted; not shown by the server |

**Field-level codes in `errors[]`**: `REQUIRED`, `UNKNOWN_FIELD`, `INVALID_TYPE`, `AMOUNT_NOT_INTEGER`, `OUT_OF_RANGE` (`/amount_minor`), `INVALID_CURRENCY`, `INVALID_ENUM_VALUE` (`/category_id`, `/period_type`), `IMMUTABLE_FIELD` (`/category_id`, `/currency`, `/period_type`), `INVALID_ID`. **No new code.**

### 6. Authorization and entitlements

Budgets are user-scoped: only the owner reads or writes a budget and its progress; anyone else gets `404 NOT_FOUND`. No wallet role is involved, so spec 010's matrix is not consulted (a budget touches no wallet; spending is read from wallets the owner currently belongs to, which is itself the read rule W-01). Verification gate on every write; none on reads. No entitlement: the free tier is limited only on sharing (P-09 rule 5).

**Precedence** for every budget write: `401` → `403 EMAIL_NOT_VERIFIED` → `422 INVALID_ID` → `422 VALIDATION_FAILED` (shape) → `404 NOT_FOUND` (id held by another person's budget or another kind of record; unknown id with version > 0, or on PATCH, DELETE, archive) → `409 VERSION_CONFLICT` for a non-delete write to a tombstone → `200` identical replay (and DELETE of a tombstone, archive of an archived budget) → `409 VERSION_CONFLICT` (stale) → `422 VALIDATION_FAILED` (state rules: `IMMUTABLE_FIELD`, `INVALID_ENUM_VALUE` at `/category_id`) → write.

### 7. Events

None published, none consumed (FR-013, FR-022). Progress re-derives from the ledger on every read, so `TransactionPosted`, `TransactionVoided` and `WalletDeleted` need no Budgeting listener. `BudgetExceeded` (constitution, Budgeting row) is left to B16, which receives an "Inherited from B8" note.

### 8. Acceptance scenarios

| Story · scenario | Layer | Test file |
|---|---|---|
| US1 · 1–3 create, replay, all-expenses | Feature, Contract | `tests/Feature/Budgeting/SaveBudgetTest.php`, `tests/Contract/Budgeting/UpsertBudgetTest.php` |
| US1 · 4–5 category rules | Feature, Contract | `tests/Feature/Budgeting/BudgetCategoryRulesTest.php`, `tests/Contract/Budgeting/UpsertBudgetTest.php` |
| US1 · 6–7 gate, access | Contract | `tests/Contract/Budgeting/BudgetVerificationGateTest.php`, `tests/Contract/Budgeting/BudgetAccessTest.php` |
| US2 · 1–4, 6 spending | Unit, Feature | `tests/Unit/Budgeting/BudgetProgressTest.php`, `tests/Feature/Budgeting/BudgetSpendingTest.php` |
| US2 · 5 progress list | Contract | `tests/Contract/Budgeting/ListBudgetProgressTest.php`, `tests/Contract/Budgeting/GetBudgetProgressTest.php` |
| US3 · 1–4 shared wallet | Feature | `tests/Feature/Budgeting/BudgetSharedWalletTest.php` |
| US3 · 5 not on wallet feed | Contract | `tests/Contract/Sync/BudgetFeedTest.php` |
| US4 · 1–5 edit, archive, delete | Feature, Contract | `tests/Feature/Budgeting/SaveBudgetTest.php`, `tests/Contract/Budgeting/PatchBudgetTest.php`, `tests/Contract/Budgeting/DeleteBudgetTest.php`, `tests/Contract/Budgeting/ArchiveBudgetTest.php` |
| US4 · 6 category in use | Feature | `tests/Feature/Budgeting/BudgetCategoryUsageTest.php` |
| US5 · 1–3 periods, history | Unit, Feature | `tests/Unit/Budgeting/BudgetPeriodsTest.php`, `tests/Feature/Identity/BudgetStartDayHistoryTest.php` |
| US5 · 4–5 timezone, default | Feature | `tests/Feature/Budgeting/BudgetPeriodTimezoneTest.php` |
| US6 · 1–2 batch | Contract | `tests/Contract/Sync/BudgetBatchParityTest.php` |
| US6 · 3 feed | Contract | `tests/Contract/Sync/BudgetFeedTest.php` |
| US6 · 4 purge | Feature | `tests/Feature/Sync/BudgetPurgeTest.php` |
| Reads, lists | Contract | `tests/Contract/Budgeting/ListBudgetsTest.php`, `tests/Contract/Budgeting/GetBudgetTest.php` |

### 9. Open questions

1. **`BudgetExceeded` and overrun push** — B16; does not block.
2. **Per-period limits and rollover** — out of MVP (P-17); a later amendment. Does not block.
3. **Budget-vs-actual reports and forecasts over scheduled occurrences** — B13, through `BudgetQueryService`; does not block.
4. **Budgets of a deleted account** — B15 purges them with the account; does not block.
5. **Restoring a deleted budget** — not offered; a later additive `PATCH` restore. Does not block.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A person creates a budget in one request and sees its progress on the next read, in 100% of cases.
- **SC-002**: After any ledger change (record, edit, re-date, re-categorise, delete, restore, wallet deletion, leaving a wallet), the next progress read reflects it in 100% of cases; 0 stale figures.
- **SC-003**: 0 progress figures that sum two currencies, count a transfer, or count another person's transaction.
- **SC-004**: The phone's offline estimate, computed from the same published rules over the same transactions, equals the server's `spent` in 100% of the integration checkpoint cases (C8).
- **SC-005**: The progress list for 50 budgets over a period with 20,000 of the owner's transactions answers in under 300 ms at the 95th percentile on the development stack with debugging extensions off; the budget list and single reads under 150 ms.
- **SC-006**: 0 responses reveal a budget or its progress to anyone but its owner.
- **SC-007**: A start-day change never moves the dates of a period already begun, in 100% of cases.

## Assumptions

- Spec 008's profile (timezone, start day, `…_from`), spec 011's categories and `CategoryUsage`, spec 012's ledger and `TransactionQueryService`, spec 013's user feed, batch and purge, and Identity's `UserScopeCounter` are reused and extended, not wrapped.
- The Budgeting module directory is created by this feature (`app/Modules/Budgeting`), as the constitution lists it.
- Sizing: up to 200 budgets per person (one page of `GET /budgets`), 50 active for the progress target.
- No reference data: nothing to seed.
- Pre-launch data: the start-day backfill's assumption that a changed start day replaced the default 1 is exact for at most one prior change; no production users exist yet.
