# Research: Recurring Transactions

**Feature**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Date**: 2026-10-02

Every decision below was taken with the owner away under delegated authority; each is the recommended, most conservative option and is listed in the plan's Assumed defaults. Specs 010–013 are the pattern reference throughout.

---

## R-1 Who generates occurrences (ADR-0018)

**Decision**: the server alone materialises occurrences — on every rule write (create or edit, inside the write's unit of work), on the hourly job, and on `POST …/materialise`. Occurrence ids are server-minted UUIDv7. A `PUT` to an unknown occurrence id is `404`. Idempotency rests on the **occurrence key** `(recurring_rule_id, scheduled_date, is_payout)`, a unique index over live and tombstoned rows, and an `INSERT … ON CONFLICT DO NOTHING` under the rule row lock.

**Rationale**: offline-first makes duplicate generation the dominant risk. One generator with a unique key cannot duplicate; two generators need deterministic ids (excluded by UUIDv7, P-06 rule 1) and a merge when schedules disagree. C7 itself says "don't materialise on the phone; ask the server, then pull". P-06 rule 2 governs records clients create; Category References already set the precedent of a server-minted syncable record.

**Alternatives**: phone materialises and uploads (duplicates); phone-only (nothing posts while the app is closed); occurrences as `planned` transactions (P-05; a second transaction kind every sum must filter). Recorded in ADR-0018.

## R-2 Confirmation as a versioned occurrence write

**Decision**: confirm = `PUT /wallets/{id}/occurrences/{oid}` with `status: confirmed` and a client-generated `transaction_id`. Inside one unit of work, under the fixed lock order (R-6), the service checks the occurrence's version, records the transaction through `TransactionService` with that id, and stamps the occurrence `confirmed`. Auto-confirm does the same transition with a server-minted id. Uniques: `occurrences.transaction_id` and `transactions.occurrence_id` (both partial on not-null). A confirmed occurrence never changes again.

**Rationale**: one versioned write gives P-07's conflict for every race (two phones, phone vs server). The transaction id from the phone lets it show the transaction locally at once with the id the server will keep. The batch (ADR-0016) carries only whole-record `PUT`/`DELETE`/restore writes, so a `PUT` of the occurrence keeps confirm/skip/edit-one batchable.

**Alternatives**: `POST …/confirm` action endpoints (not batchable, so not offline-queueable through C14's engine); phone records the transaction with an `occurrence_id` (two independent writes, no version to refuse the second confirm); un-confirm on transaction delete (re-opens double-posting).

## R-3 Stored versus derived status

**Decision**: stored `status ∈ {scheduled, confirmed, skipped}`. "Due" and "missed" are derived (spec FR-013) by readers — the phone, and later B13/B16 through a query service.

**Rationale**: a stored `due` would be a server write on every occurrence's due day; every such write bumps `version` and refuses any member's offline edit of that occurrence as a conflict the person cannot act on. The glossary entry is amended (T001).

**Alternatives**: store due/missed with a daily transition job (spurious conflicts, 365 writes per daily rule per year).

## R-4 Schedule arithmetic and month-end

**Decision**: a pure domain value object `RuleDates` (not named "Schedule": the glossary bans it as a synonym): frequency, interval, anchor day, start date, end date, count. `datesBetween(from, to, limit)` yields `(ordinal, date)` pairs; each date computed from the origin index k: daily `start + k·i` days; weekly `start + 7·k·i`; monthly month `M0 + k·i` with day `min(anchor, daysInMonth)`; yearly year `Y0 + k·i`, start's month, day clamped. Dates before `start_date` are dropped and ordinals renumbered from 1 over what remains; stop at `end_date` or the count. Implemented with `DateTimeImmutable` on calendar dates (no timezone) — no library.

**Rationale**: computing from the origin avoids drift (Jan 31 → Feb 28 → Mar 31, never Mar 28). Clamping, not rolling over, matches what people mean by "the 31st" and C7's request for a stated month-end rule. Pure domain code is unit-testable without a database (P-02 Domain).

**Alternatives**: RRULE library (a new dependency needing approval, CONTRIBUTING item 17; more than needed); roll-over to the next month (pays rent on 1 March for a February "31st"); "last day of month" flag (the clamp of anchor 31 already expresses it).

## R-5 "Today", the horizon and the backfill window

**Decision**: the rule stores `timezone`, captured at creation from the author's profile through Identity's application layer (`Profiles::find($userId)->timezone`), immutable. `today(rule) = now()->setTimezone(rule.timezone)->format('Y-m-d')`, through the shared clock. Horizon: through today + 35 days, and at least the next scheduled date after today. Backfill: no date before (creation day − 90 days). Cap: 400 new occurrences per rule per pass. All three are config values in `config/wallets.php` (`recurring.horizon_days`, `recurring.backfill_days`, `recurring.max_per_pass`).

**Rationale**: ADR-0011 rules 8–9 and the glossary fix the rule's timezone; reading the author's profile is the only timezone the server knows. 35 days always covers the next monthly occurrence; "at least the next" covers yearly rules. 90 days of backfill covers an offline-created rule and a gam'eya joined mid-way, without flooding a daily rule started years ago. The cap bounds one transaction's size; the next pass continues.

**Alternatives**: client-supplied timezone (the phone's clock zone, which C7 says is not "today"); materialise the whole finite series at once (a 1000-round daily rule is 1000 rows in one write); unlimited backfill (unbounded writes).

## R-6 Lock order and calling `TransactionService` inside a unit of work

**Decision**: every recurring write runs in one DB transaction: share-lock categories ascending (the stored and requested `category_id`, `payout_category_id`, and the occurrence's category) → lock wallets ascending (the rule's wallet and a transfer's destination) → lock the rule row → lock the occurrence rows it touches (ascending id). `TransactionService` gains an entry, `recordForOccurrence(...)`, that runs **inside the caller's transaction** (a savepoint; Postgres row locks are re-entrant within one transaction, so its own category → wallet locking re-takes locks already held in the same order) and accepts: the transaction id, the actor to authorise (T-02 in the wallet, T-02 in a transfer's destination), the author for `created_by`, the occurrence link, and the occurrence's values; it skips the category-ownership check (settled when the rule or occurrence was written) but keeps the live/kind/currency/destination rules. Its drift-restart loop is not used in this entry (the caller holds the locks; the transaction id is new).

**Rationale**: ADR-0014 rule 10 and spec 012 FR-011; recording through the service keeps postings, balance recompute, sequence stamps, references and `TransactionPosted` in one place (brief "Inherited from B6"). A nested transaction keeps the occurrence's confirm and the transaction's insert atomic.

**Alternatives**: write transactions directly (forbidden by the brief); call `TransactionService::save` after committing the occurrence (not atomic: a crash between the two leaves a confirmed occurrence with no transaction); lock the rule before categories (deadlocks against a category rename republishing references).

## R-7 Category usage and references

**Decision**: register `RecurringCategoryUsage` as a `CategoryUsage` participant (rules' `category_id` and `payout_category_id`, occurrences' `category_id`, live or tombstoned, set-wise with one query per table). Add `WalletCategoryUse` (Application interface, tagged) answering "is this category used anywhere in this wallet", implemented by transactions and by recurring records; `TransactionService` and the recurring services call a composite `WalletCategoryUses::usedIn(walletId, categoryId)` before `CategoryReferences::withdrawIfUnused`.

**Rationale**: today B6 withdraws a reference when no *transaction* in the wallet uses it; once rules use the same category, B6 would withdraw a reference a rule still needs, and members could not render the rule. The check must be wallet-wide across every participant (brief "Inherited from B5").

**Alternatives**: B7 republishes on every read (writes on read); never withdraw (references leak forever).

## R-8 Feed, batch and purge (spec 013 extension)

**Decision**: `SyncFeedReader` gains `rulesChangedIn` and `occurrencesChangedIn` (by `wallet_id`, `change_seq > after`, limit + 1, tombstones included); `WalletFeedQuery::page` merges them as `recurring_rule` and `occurrence` rows; `FeedPresenter` gains `recurringRule()` and `occurrence()` implemented with the read resources. Sync's batch `ItemType` gains `RecurringRule` (`upsert` → PUT, `delete` → DELETE) and `Occurrence` (`upsert` → PUT) routed by `RouterItemDispatcher` to the single-write routes. `TombstoneStore`/`TombstonePurge` gain `occurrences` then `recurring_rules` steps (occurrences first; an occurrence kept while a transaction's `occurrence_id` names it; a rule kept while any occurrence row exists), raising `wallets.purged_change_seq`.

**Rationale**: brief "Inherited from B14"; ADR-0016's follow-up names recurring rules and occurrences as later batch types. A feed row equal to its read is spec 013 FR-005.

**Alternatives**: a separate recurring feed (a third cursor; ADR-0008 allows two scopes only).

## R-9 Rule edits: template propagation, frozen schedule, series split

**Decision**: domain `RuleChange` classifies a save into template members (amount, basis-derived amounts, category, texts, `auto_confirm`), schedule members, end members and status. Template changes update every `scheduled`, not-`edited` occurrence (version + 1, `change_seq` each). Schedule members are frozen once any occurrence is confirmed or skipped (`IMMUTABLE_FIELD`); before that a schedule change reconciles the pending set by key (tombstone keys no longer scheduled, insert new keys). End changes tombstone pending occurrences past the new end and are refused (`OUT_OF_RANGE`) if they would cut off a resolved one. "From date D" is the client's two writes (end at D − 1, new rule from D).

**Rationale**: keeps every resolved occurrence (money already posted, or a decision already made) immutable history; a frozen schedule after the first resolution makes it impossible for an edit to produce a second occurrence for a period already paid. Two plain `PUT`s are batchable and offline-friendly.

**Alternatives**: rule versions/segments inside one rule (a second schedule model, harder for the client's preview); server-side "split" endpoint (not batchable); regenerate everything on any edit (rewrites history).

## R-10 Amount split and payout

**Decision**: `Money::allocate(int $parts): list<Money>` added to the shared `Money` (P-03 rule 4): `intdiv` share to every part, the remainder one minor unit to each of the first parts. A `total` basis rule's round n takes part n. The gam'eya payout is `Σ` of the rounds' scheduled amounts (bounded by 2^53 − 1). Overrides on an occurrence never change other rounds or the payout.

**Rationale**: the brief's reconcile row makes `Money::allocate()` mandatory; even split with earliest-first remainder is the common installment practice and sums exactly.

**Alternatives**: ratio-based allocate (no ratios in the product); last-part remainder (equally valid; earliest-first chosen so a person pays the odd minor units first and the series ends on the round amount).

## R-11 Auto-confirm start and refusals

**Decision**: internal column `auto_confirm_from date` = rule-timezone today at creation; reset to today when `status` goes `paused` → `active` or `auto_confirm` goes false → true. The job and materialise confirm `scheduled` occurrences with `auto_confirm_from ≤ date ≤ today`. A refusal (author lacks T-02 in the wallet or destination → `not_permitted`; destination tombstoned or author not a member → `counter_wallet_unavailable`; currency differs → `currency_mismatch`) is stored on the occurrence (one write, version + 1) and that occurrence is excluded from later automatic passes (`auto_confirm_refusal IS NULL` in the query). Any manual save clears it.

**Rationale**: never back-post money nobody saw (FR-017); brief "a destination that was deleted or left is a refusal to surface, not a retry".

**Alternatives**: retry every run (log noise, and a fix by the person could trigger a surprise post days later); refuse silently (the person never learns why rent was not posted).

## R-12 The job

**Decision**: console command `wallets:materialise-occurrences`, scheduled `hourly()` with `withoutOverlapping()` in `routes/console.php`. It pages live, non-ended rules by id (500 per page) and, for each, opens its own unit of work running the same `RecurringRuleService::catchUp(ruleId, actor: null)` the materialise endpoint uses. An exception on one rule is logged (rule id and exception class only) and the run continues. One summary log line per run (`rules`, `materialised`, `confirmed`, `refused`, `failed` counts).

**Rationale**: hourly meets each timezone's midnight within the hour (SC-004); per-rule units of work keep failures independent (FR-020); sharing one code path with the endpoint guarantees "materialise does what the job does".

**Alternatives**: daily at a fixed UTC hour (Cairo's day would start up to 22 hours late); a queued job per rule (more moving parts for 10,000 short transactions; revisit if the run exceeds its budget).

## R-13 Wallet deletion, destination deletion, author departure

**Decision**: `RecurringGoesWithWallet` (a `WalletDeletionParticipant`) tombstones the wallet's rules and all their occurrences with the wallet's stamps. A deleted transfer destination leaves the rule live; confirmations are refused (`counter_wallet_unavailable` automatically, `INVALID_ENUM_VALUE` at `/status` manually). An author who leaves (B9) stops automatic confirmations (`not_permitted`).

**Rationale**: spec 010 FR-010; P-08 rule 2 (never write into a wallet the deleter may not write); a person who left must not keep posting into the wallet.

## R-14 Endpoint shape and errors

**Decision**: eight operations in `docs/openapi/paths/recurring.yaml` (written and indexed in this design phase; `info.version` v1.7), additive enum and `anyOf` members in `sync.yaml`, and two read-only members in `transactions.yaml` (listed as optional properties now; added to the Transaction's `required` list in the same implementation task that makes the resource emit them, so existing contract tests stay green until then). No new error code: every refusal maps onto the catalogue (`IMMUTABLE_FIELD`, `CURRENCY_MISMATCH`, `INVALID_ENUM_VALUE`, `OUT_OF_RANGE`, `REQUIRED`, `UNKNOWN_FIELD`, …). `auto_confirm_refusal` is a resource enum, not an error code.

**Rationale**: spec 003 extension rules; P-17 discourages new codes without a new client branch.

## R-15 Performance

**Decision**: indexes — `recurring_rules (wallet_id, change_seq)`, `(wallet_id, created_at, id)` where live, `(status, id)` where live and not ended (job paging); `occurrences (wallet_id, change_seq)`, `(wallet_id, date, id)` where live, unique `(recurring_rule_id, scheduled_date, is_payout)`, `(recurring_rule_id, status)`, unique `transaction_id` where not null; `transactions` unique `occurrence_id` where not null. Targets (SC-007): rule write p95 < 300 ms (a daily rule's 36-row horizon in one multi-row insert); job 10,000 rules < 10 min.

**Rationale**: specs 012/013 measured comparable writes at 100–170 ms; a multi-row insert of ≤ 400 rows is one statement.
