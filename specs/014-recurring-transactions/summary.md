# Summary: Recurring Transactions (spec 014, B7)

**Date**: 2026-10-02 · **Status**: implemented (T001–T059), QA and E2E green, committed on `main` (c0e9844).

## What was built

- **Recurring rules and occurrences** in the Wallets & Ledger module (`app/Modules/Wallets`, `Recurring` sub-namespaces in every layer).
  - **Recurring Rule** (client-created) and **Occurrence** (created only by the server, materialised ahead of time; ADR-0018): two wallet-scoped syncable tables. Transactions gain read-only `recurring_rule_id` and `occurrence_id`, now required in `transactions.yaml`.
  - Pure domain: `RuleDates` (daily, weekly, monthly, yearly; month-end clamping computed from the start, so no drift), `RuleToday`, `OccurrenceAmounts`/`OccurrencePlan`, `RecurringRuleRules`, `OccurrenceRules`; `Money::allocate()` in the shared kernel.
  - Services: `RecurringRuleService` (save, delete, materialise, catch up), `OccurrenceService` (edit, confirm, skip), `Materialiser`, `AutoConfirmer`, `OccurrenceReconciler`, `RecurringLocks` (categories share → wallets ascending → rule → its occurrences, drift restart at most 3), `RecurringStamps`, `RecurringPass`.
  - `TransactionService::recordForOccurrence` runs inside the caller's unit of work, authorises the actor, records as the rule's author and sets both links.
  - Hourly job `wallets:materialise-occurrences` (`withoutOverlapping`, per-rule failure isolation, one counts-only summary log line).
  - `WalletCategoryUses` fixes B6's Category Reference withdrawal to also consult rules and occurrences; `RecurringCategoryUsage` and `RecurringGoesWithWallet` registered.
  - Eight API operations: `/wallets/{id}/recurring-rules[/{rid}][/materialise]` and `/wallets/{id}/occurrences[/{oid}]`; writes behind `address.verified`. OpenAPI `recurring.yaml`, `info.version` v1.7.
- **Sync**: `recurring_rule` and `occurrence` wallet-feed rows; batch item types `recurring_rule` (upsert, delete) and `occurrence` (upsert); tombstone purge steps for occurrences, then rules.
- **Docs**: ADR-0018; constitution v1.3.7 (P-05, P-06, P-07, P-12 list ADR-0018); glossary; api-conventions; security baseline; "Inherited from B7" notes in briefs B08, B13, B15, B16.
- **Config**: `config/wallets.php`. No seeder (presets are behaviour, not reference data).
- **Apidog**: bundle (via `npx @redocly/cli bundle`) imported into project 1332822 / module 1363204 (11 endpoints created, 3 updated); project doc "Recurring transactions" (2463177) with client behaviour notes.

### Fix made during QA

On a gam'eya or `amount_basis: total` rule, changing `amount_minor` after a round was resolved re-split only the pending rounds, so the shares stopped summing to the total and a gam'eya payout stopped matching its rounds. `amount_minor` is now frozen (`IMMUTABLE_FIELD /amount_minor`) for those rules once anything is resolved, like `occurrence_count`. The only contract change is the `upsertRecurringRule` description in `recurring.yaml`.

## Results

- **QA** (`/speckit-qa-run`, curl): 213 checks in 32 groups, all pass; all 31 acceptance criteria covered. Report `qa/qa-20261002-143000.md`.
- **E2E** (`/speckit-test-by-validate`, over HTTP): 31/31 acceptance scenarios. Report `test-reports/validation-20261002-143005.md`.
- **Gates**: Pint and Larastan clean; Arch 50/50; Unit + Feature + Contract 1608 passed.
- **Performance** (test DB, Xdebug off, Docker stack at ~2.4 ms per query): daily-rule create (36 occurrences) p95 238 ms (one run 297 ms; target 300 ms); occurrence list over 2,000 occurrences p95 157 ms; rule list p95 176 ms; hourly job first pass over 10,260 rules 8.6 min (target 10 min); rerun with nothing to do 74.7 s.

## Deployment

- Run the three `2026_10_05` migrations.
- The scheduler runs `wallets:materialise-occurrences` hourly.
- Restart queue workers.

## Assumed defaults

Owner away with delegated authority; each the conservative option.

### Design

1. **Only the server generates occurrences** (on rule write, the hourly job, and `materialise`). The phone never creates one; a PUT to an unknown occurrence id is 404. One generator plus a unique key cannot duplicate (ADR-0018).
2. Occurrence key (`recurring_rule_id`, `scheduled_date`, `is_payout`), unique over live and deleted rows.
3. No `planned` transactions: upcoming items are Occurrences (`scheduled`, no postings); only confirming one creates a Transaction.
4. Confirm is a versioned PUT of the occurrence carrying a client-generated `transaction_id`. Server auto-confirm is the same transition under the same locks, so the second arrival gets 409.
5. `auto_confirm` is per rule; the job runs hourly.
6. No automatic back-posting: auto-confirm covers only dates on or after creation, resume or switch-on.
7. A refused auto-confirm is shown, not retried: `auto_confirm_refusal` = `not_permitted`, `counter_wallet_unavailable` or `currency_mismatch`.
8. "Due" and "missed" are derived, not stored.
9. "Today" uses the rule's timezone, taken from the author's profile at creation (default timezone fallback).
10. `anchor_day` is clamped to the month's last day; every date is computed from the start.
11. Window: through today + 35 days and always at least the next one; backfill at most 90 days; each pass creates at most 400 occurrences and auto-confirms at most 50.
12. Series edits change only pending, unedited occurrences; schedule fields freeze once anything is confirmed or skipped; "from date D" ends the old rule at D − 1 and creates a new rule from D.
13. Deleting a rule is final and deletes its occurrences; its transactions stay. An ended rule cannot change. A confirmed occurrence never changes; deleting its transaction does not un-confirm it.
14. Presets: gam'eya = monthly expense contributions plus one income payout worth the sum of all rounds; installment = monthly, optional total split by `Money::allocate` (remainder to the earliest rounds). Presets are an enum; nothing is seeded.
15. The produced transaction is recorded as the rule's author, `cleared: false`.
16. A transfer rule lives in the source wallet's scope and needs T-02 in the destination (ADR-0015).
17. Paths sit under `/wallets/{id}/…` (the brief's `/recurring/{id}/materialise` reconciled). No new error code, event, rate limit or entitlement.
18. The rule's currency is checked against the wallet on create only; a later mismatch refuses confirmations instead.

### Implementation

19. The job pages `active` rules only, does a cheap unlocked "anything to do?" check, and re-checks under the locks.
20. A tombstoned, still-scheduled occurrence that the schedule gives again is revived (version + 1), so the unique key never leaves a permanent hole.
21. A pending edited occurrence that falls off the pattern is kept; one beyond a shortened end is tombstoned.
22. Confirm's `transaction_id` must be a UUIDv7 (new `UuidV7` rule → `INVALID_ID`) not held by any record.
23. `StampBlock` port reserves consecutive `change_seq` values for multi-row writes.
24. `exists`/`isHeldByOther` on the Transaction and Category repositories include rules and occurrences.
25. `recordForOccurrence` uses a savepoint so a duplicate transaction id maps to `/transaction_id` without aborting the caller.
26. Per-rule job failures log the rule id and exception class only.
27. The purge keeps a rule while any transaction names it.
28. An occurrence PUT on an id the server never minted is 404, even for a viewer.
29. `amount_minor` freezes for gam'eya and total rules once a round is resolved (QA fix).

### QA

30. QA users' audit entries were left in place (append-only, ids only); all other QA data was deleted.

## Remaining risks

- Monthly clamping in short months is covered only by `RuleDatesTest` (not observable over HTTP from today's date).
- The per-pass caps and job performance were not exercised over HTTP; they rely on feature tests.
- The hourly job is near its 10-minute target at 10,000 rules in Docker.
- A phone cannot confirm occurrences it hasn't received; a hand-recorded transaction is unlinked and can duplicate an automatic one.
- Server-side rule edits that update pending occurrences can 409 a member's offline edit of one of them (by design).
- Occurrence tombstones stay while a transaction refers to them.
- Open: a departing author's rules (B15) and rules on a transfer destination's feed.
