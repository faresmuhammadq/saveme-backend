---

description: "Task list for Recurring Transactions (B7)"
---

# Tasks: Recurring Transactions

**Input**: Design documents from `/specs/014-recurring-transactions/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/recurring-api.md](./contracts/recurring-api.md), [quickstart.md](./quickstart.md), the frozen contract `docs/openapi/paths/recurring.yaml` (written and indexed; `info.version` v1.7; additive changes to `sync.yaml` and `transactions.yaml`) and [ADR-0018](../../docs/adr/0018-occurrences-materialised-by-the-server.md).

**Tests**: included. The spec's Invariants table names every test file (constitution P-16 rule 3). Write each story's tests first and watch them fail.

**Organization**: grouped by user story, in delivery order:

- US1: set up a monthly bill once (P1)
- US2: confirm, skip or adjust one occurrence (P1)
- US3: change the series (P2)
- US4: gam'eya and installment presets (P2)
- US5: catch up and stay in sync (P2)

**Conventions for every task** (as in specs 010–013):

- Run through the container: `dev exec -- vendor/bin/pest <path> --compact`, `dev <artisan command>`.
- P-02 layers: everything lives in `app/Modules/Wallets` under a `Recurring` sub-namespace; ports in `Domain/Recurring/Ports`; Infrastructure depends only on Domain; controllers pass `(string) $request->user()->getAuthIdentifier()` to Application services. Code outside Wallets: `Money::allocate` (Shared, T003), the Sync batch `ItemType` cases (T047). The author's timezone comes from Identity's Application `Profiles` only. The verification gate is the middleware alias `address.verified`.
- Names follow the glossary bans: never "schedule", "repeat", "recurrence", "instance", "run" in a class, table, column, route or event (the date arithmetic is `RuleDates`).
- Refusals extend `Shared\Domain\Errors\Refusal` with a catalogue `ErrorCode` (`RecurringRuleNotFound`, `OccurrenceNotFound` 404; `RecurringRuleVersionConflict`, `OccurrenceVersionConflict` 409 with `current`; `RecurringStateInvalid` 422 collecting `FieldError`s). **No new error code.**
- Writes follow spec 012 exactly: `version` in the JSON body (also on DELETE); identical write → `200` with no bump; tombstone → `409` with `current` for any non-delete write; stale → `409`; precedence of spec §6.
- **Lock order** (spec FR-026, research R-6): category rows (share, ascending id) → wallet rows (update, ascending id) → the rule row → its occurrence rows (ascending id). Never lock a category after a wallet, or a wallet after a rule.
- Every changed rule or occurrence row takes a `change_seq` from the rule's wallet counter under the wallet lock, then the wallet one final stamp (spec 012 default 18) — writes made by the server included.
- Instants written with microseconds; empty `meta` is `(object) []`. No amount, description, memo or counterparty in any log line (P-15).
- Run `dev exec -- vendor/bin/pint --dirty --format agent` after PHP edits.
- Contract tests use `assertValidRequest()` and `assertValidResponse($status)`, covering every documented status.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on unfinished tasks)
- **[Story]**: US1–US5 from spec.md

---

## Phase 1: Setup (docs, shared kernel, schema)

- [X] T001 [P] Docs before code uses the terms. `docs/glossary.md`: **Recurring Rule** entry — states `active` ⇄ `paused`, either → `ended` (final), any → tombstoned (final); fields as spec §3; presets `gameya` and `installment` constrain the shape; `timezone` captured from the author's profile at creation. **Occurrence** entry — "materialised **only by the server**" (ADR-0018); states `scheduled` → `confirmed` | `skipped`, `skipped` → `scheduled` | `confirmed`; "due" and "missed" derived, never stored; no postings. New terms: **occurrence key**, **materialise**, **horizon**, **auto-confirm**, **payout** (gam'eya), **anchor day** (spec §2 wording). Transaction entry: read-only `recurring_rule_id`, `occurrence_id`. `.specify/memory/constitution.md` → **v1.3.7 (PATCH)**: P-05, P-06, P-07 and P-12 `ADRs:` lines gain `[ADR-0018](../../docs/adr/0018-occurrences-materialised-by-the-server.md)`; Sync Impact Report entry ("PATCH: P-05, P-06, P-07, P-12 list ADR-0018, introduced by spec 014; no rule changes"); version line, footer, Constitution Check template version, Last Amended. `docs/api-conventions.md`: the batch paragraph names the `recurring_rule` and `occurrence` types; "Read-only resources" gains a paragraph: an Occurrence is server-created and accepts a `PUT` that never creates (unknown id → 404). `docs/security-baseline.md`: the verification-gate table gains the recurring writes. ADR-0018, its index row and spec 010's R-02 wording already exist (design phase).
- [X] T002 [P] `config/wallets.php`: `recurring.horizon_days` (35), `recurring.backfill_days` (90), `recurring.max_per_pass` (400), `recurring.max_confirms_per_pass` (50); read through `config()` in Application only.
- [X] T003 [P] `Money::allocate(int $parts): list<Money>` in `app/Modules/Shared/Domain/Money/Money.php` (P-03 rule 4, research R-10): `$parts` ≥ 1; each part `intdiv(amount, parts)`, the remainder one minor unit to each of the first parts; parts sum exactly to the original; negative amounts allocate symmetrically. Test `tests/Unit/Shared/MoneyAllocateTest.php`: 1,000,000 / 12 → four of 83,334 and eight of 83,333; 5 / 5; 7 / 1; sum invariant over random amounts; `parts < 1` throws.
- [X] T004 Migration `app/Modules/Wallets/Infrastructure/Database/Migrations/2026_10_05_000001_create_recurring_rules_table.php` per data-model.md, quoting its constraints: `preset` CHECK in (`custom`,`gameya`,`installment`); `status` CHECK in (`active`,`paused`,`ended`); `kind` CHECK in (`income`,`expense`,`transfer`); `amount_minor bigint` CHECK `> 0 AND <= 9007199254740991`; `amount_basis` CHECK in (`per_occurrence`,`total`); `currency char(3)`; `category_id uuid NULL FK → categories`, `counter_wallet_id uuid NULL FK → wallets`, CHECK `(kind = 'transfer') = (category_id IS NULL)`, CHECK `(kind = 'transfer') = (counter_wallet_id IS NOT NULL)`, CHECK `counter_wallet_id IS NULL OR counter_wallet_id <> wallet_id`; `description varchar(100) NULL`, `memo varchar(1000) NULL`, `counterparty varchar(100) NULL` (each CHECK `x IS NULL OR btrim(x) <> ''`); `auto_confirm boolean`; `auto_confirm_from date`; `frequency` CHECK in (`daily`,`weekly`,`monthly`,`yearly`); `interval smallint` CHECK 1–365; `anchor_day smallint NULL` CHECK 1–31 and CHECK `(frequency IN ('daily','weekly')) = (anchor_day IS NULL)`; `start_date date` CHECK 1900-01-01–2999-12-31; `end_date date NULL` CHECK `end_date >= start_date AND end_date <= '2999-12-31'`; `occurrence_count integer NULL` CHECK 1–1000; CHECK `NOT (end_date IS NOT NULL AND occurrence_count IS NOT NULL)`; `payout_round integer NULL`, `payout_category_id uuid NULL FK → categories`, CHECK `(preset = 'gameya') = (payout_round IS NOT NULL)`, CHECK `(preset = 'gameya') = (payout_category_id IS NOT NULL)`, CHECK `payout_round IS NULL OR payout_round BETWEEN 1 AND occurrence_count`; `timezone varchar(64)`; `created_by uuid` (no FK across modules); `version integer` CHECK ≥ 1; `change_seq bigint`; `timestampsTz(6)`; `deleted_at timestamptz(6) NULL`. Indexes per data-model.md.
- [X] T005 Migration `…/2026_10_05_000002_create_occurrences_table.php`: columns per data-model.md (`status` CHECK in (`scheduled`,`confirmed`,`skipped`); `amount_minor` CHECK `> 0 AND <= 9007199254740991`; `date` CHECK 1900–2999; CHECK `(status = 'confirmed') = (transaction_id IS NOT NULL)`; `auto_confirm_refusal` CHECK in (`not_permitted`,`counter_wallet_unavailable`,`currency_mismatch`) or null; `ordinal` CHECK ≥ 1); `recurring_rule_id FK → recurring_rules`; **unique** `(recurring_rule_id, scheduled_date, is_payout)`; **unique** `(transaction_id) WHERE transaction_id IS NOT NULL`; other indexes per data-model.md. Depends on T004.
- [X] T006 Migration `…/2026_10_05_000003_add_occurrence_links_to_transactions.php`: `recurring_rule_id uuid NULL FK → recurring_rules`, `occurrence_id uuid NULL FK → occurrences`, CHECK `(recurring_rule_id IS NULL) = (occurrence_id IS NULL)`, **unique** `(occurrence_id) WHERE occurrence_id IS NOT NULL`. Depends on T005. `tests/Arch/MigrationsTest.php` stays green.

---

## Phase 2: Foundational (blocking)

### Tests

- [X] T007 [P] `tests/Unit/Wallets/Recurring/RuleDatesTest.php` (FR-010): monthly anchor 31 from 2027-01-31 → 01-31, 02-28, 03-31, 04-30; leap year 2028-02-29; yearly anchor 29 Feb → 28 Feb in common years; interval 3 months; weekly on the start weekday every 2 weeks; daily every 3 days; start off-anchor (start 2026-10-20, anchor 5 → first 2026-11-05, ordinal 1); `end_date` inclusive; `occurrence_count` stops after the n-th round; dates computed from the origin (no drift over 24 months); `between(from, to, cap)` respects the cap and returns `(ordinal, date)`.
- [X] T008 [P] `tests/Unit/Wallets/Recurring/RuleTodayTest.php`: "today" for `Africa/Cairo` vs `Asia/Dubai` vs `UTC` at 21:30 UTC differ as expected; DST-switch days resolve to one calendar date; a frozen clock drives it.
- [X] T009 [P] `tests/Unit/Wallets/Recurring/RuleRulesTest.php` and `tests/Unit/Wallets/Recurring/PresetRulesTest.php` (FR-002–FR-007): immutables (`preset`, `kind`, `currency`, `counter_wallet_id` → `IMMUTABLE_FIELD`); frozen schedule members once resolved (`frequency`, `interval`, `anchor_day`, `start_date`, `amount_basis`, `payout_round`; plus `occurrence_count` for gam'eya and `total`); end cutting a resolved round → `OUT_OF_RANGE`; ended rule: any change → `IMMUTABLE_FIELD`; create as `ended` → `INVALID_ENUM_VALUE` at `/status`; `anchor_day` on daily/weekly → `INVALID_ENUM_VALUE`, missing on monthly/yearly → `REQUIRED`; both end members → `INVALID_ENUM_VALUE` at `/occurrence_count`; `total` without count → `INVALID_ENUM_VALUE` at `/amount_basis`; total below count → `OUT_OF_RANGE` at `/amount_minor`; presets' shapes (gam'eya: monthly, interval 1, expense, count 2–120, no end, payout members; installment: monthly, interval 1, expense or transfer, count 2–120, no end, no payout; custom: no payout); count below `payout_round` → `OUT_OF_RANGE`; several refusals collected at once.
- [X] T010 [P] `tests/Unit/Wallets/Recurring/OccurrenceAmountsTest.php` and `tests/Unit/Wallets/Recurring/OccurrenceRulesTest.php`: per-occurrence vs total shares (via `Money::allocate`), payout = Σ scheduled rounds (skips and edits ignored), payout above 2^53 − 1 refused; transitions `scheduled` → `confirmed`|`skipped`, `skipped` → `scheduled`|`confirmed`, `confirmed` → none (`IMMUTABLE_FIELD`); `transaction_id` required on confirm (`REQUIRED`), refused otherwise (`INVALID_ENUM_VALUE`); `edited` computed from rule-derived values (set on difference, cleared on equality).
- [X] T011 [P] `tests/Feature/Wallets/Recurring/RecurringConstraintTest.php`: each CHECK and unique of T004–T006 fails at the database for a violating row (duplicate occurrence key, two occurrences with one `transaction_id`, two transactions with one `occurrence_id`, confirmed without transaction, payout members on a custom rule, both end members); valid rows insert.

### Implementation

- [X] T012 [P] Domain values in `app/Modules/Wallets/Domain/Recurring/`: enums `Preset`, `RuleStatus`, `AmountBasis`, `Frequency`, `OccurrenceStatus`, `AutoConfirmRefusal`; `RuleDates` (pure; `between(from, to, cap)`, `nextAfter(date)`, `contains(date)`); `RuleToday::of(timezone, clock)`; `OccurrenceAmounts` (shares, payout); `RecurringRuleRecord`, `OccurrenceRecord` (readonly, every column, `isTombstone()`, `isResolved()`); `OccurrencePlan` (key + snapshot values); `RecurringRuleRules`, `OccurrenceRules` returning `list<FieldError>`. No framework imports. Run T007–T010 — green.
- [X] T013 [P] Refusals in `app/Modules/Wallets/Domain/Recurring/Refusals/` (see conventions) and ports in `Domain/Recurring/Ports/`: `RecurringRuleRepository` (`find`, `findForUpdate`, `isHeldByOther(id, walletId)`, `insert`, `updateIfVersion`, `tombstone`, `listLive`, `nextPageForJob(afterId, limit)`, `categoriesUsedAmong`, `anyInWalletUsing(walletId, categoryId)`), `OccurrenceRepository` (`find`, `findForUpdate`, `lockForRule(ruleId)`, `existingKeys(ruleId)`, `insertMany(list<OccurrencePlan>, stamps)`, `updateIfVersion`, `tombstoneWhere(...)`, `pendingUnedited(ruleId)`, `dueForAutoConfirm(ruleId, from, today, limit)`, `hasResolved(ruleId)`, `highestResolvedOrdinal(ruleId)`, `listLive`, `categoriesUsedAmong`, `anyInWalletUsing`, `isTransactionIdTaken(id)`).
- [X] T014 Models and factories: `app/Modules/Wallets/Infrastructure/Models/{RecurringRule,Occurrence}.php`, `Infrastructure/Database/Factories/{RecurringRuleFactory,OccurrenceFactory}.php` (states `monthly`, `daily`, `transfer`, `gameya`, `installment`, `paused`, `ended`, `tombstoned`; occurrence `confirmed` with a linked transaction, `skipped`, `edited`). Depends on T004–T006, T012.
- [X] T015 Repositories `app/Modules/Wallets/Infrastructure/Repositories/{EloquentRecurringRuleRepository,EloquentOccurrenceRepository}.php` implementing T013; `insertMany` is one `INSERT … ON CONFLICT (recurring_rule_id, scheduled_date, is_payout) DO NOTHING` with server UUIDv7 ids; `isHeldByOther` covers wallets, categories, references, memberships, transactions and the other recurring table. Bind in `WalletsPersistenceServiceProvider`; extend `EloquentWalletRepository::isHeldByOther`, `EloquentCategoryRepository::isHeldByOther` and `EloquentTransactionRepository::isHeldByOther` to include rule and occurrence ids (P-06 rule 3). Depends on T014.
- [X] T016 `TransactionService::recordForOccurrence(string $actorId, string $authorId, string $walletId, string $transactionId, array $values, string $ruleId, string $occurrenceId): TransactionView` in `app/Modules/Wallets/Application/Transactions/TransactionService.php` (research R-6, plan design note 4): asserts an open DB transaction; re-takes category (share) and wallet locks in the same order; authorizes the actor with T-02 in the wallet and T-02 (T-03) in a transfer's destination (refusal `WalletActionForbidden`); applies the state rules except category ownership (live, kind, currency, destination usable still enforced); writes `created_by = $authorId`, `cleared = false`, both links; postings, balance recompute, stamps, Category Reference publish and `TransactionPosted(recorded)` exactly as `save`'s create path; no drift-restart loop. Extend `TransactionRecord`, the repository insert and `TransactionResource` with `recurring_rule_id`, `occurrence_id`; in the same change add both to the `Transaction` schema's `required` list in `docs/openapi/paths/transactions.yaml` (research R-14). Test `tests/Feature/Wallets/Recurring/RecordForOccurrenceTest.php`: refuses outside a transaction; records with links and author; `occurrence_id` unique; a transaction PUT carrying either link → `UNKNOWN_FIELD`; `tests/Contract/Wallets/Transactions/*` stay green.
- [X] T017 Wallet-wide category use (research R-7): `app/Modules/Wallets/Application/Categories/WalletCategoryUse.php` (interface, tagged) and `WalletCategoryUses.php` (composite `usedIn(walletId, categoryId)`); implement for transactions (`TransactionRepository::anyInWalletUsing`) and recurring (rules' `category_id`/`payout_category_id`, occurrences' `category_id`, live or tombstoned); make `TransactionService` call `WalletCategoryUses::usedIn` instead of the transactions-only check before `CategoryReferences::withdrawIfUnused`. Register `RecurringCategoryUsage` (`CategoryUsage`, set-wise, tombstones included) tagged in `WalletsServiceProvider`.
- [X] T018 `app/Modules/Wallets/Application/Recurring/RecurringLocks.php`: the write algorithm of plan design note 1 (unlocked read → categories share ascending → wallets ascending → rule row → occurrence rows ascending → re-read → restart on drift ≤ 3, then 409 with `current`), and `RecurringStamps` (wallet counter per changed row, final wallet stamp). Depends on T015.
- [X] T019 `app/Modules/Wallets/Application/Recurring/Materialiser.php` (FR-011, FR-012, plan design note 2): from max(`start_date`, created day − `backfill_days`) to max(today + `horizon_days`, `RuleDates::nextAfter(today)`), cap `max_per_pass`, minus `existingKeys`; plans with snapshot values and amounts from `OccurrenceAmounts`; the gam'eya payout row (`is_payout`, kind income, payout category, round's date); `insertMany` with stamps. No-op for paused, ended or tombstoned rules. Depends on T012, T015, T018.
- [X] T020 HTTP skeleton: routes in `app/Modules/Wallets/Http/routes.php` — `GET wallets/{id}/recurring-rules`, `GET wallets/{id}/recurring-rules/{rid}`, `GET wallets/{id}/occurrences`, `GET wallets/{id}/occurrences/{oid}` under `auth:api`; `PUT|DELETE wallets/{id}/recurring-rules/{rid}`, `POST wallets/{id}/recurring-rules/{rid}/materialise`, `PUT wallets/{id}/occurrences/{oid}` also under `address.verified`; controllers `Http/Controllers/Recurring/{RecurringRuleController,OccurrenceController}.php` (empty actions); resources `Http/Resources/{RecurringRuleResource,OccurrenceResource}.php` with exactly the members of `RecurringRule` and `Occurrence` in `recurring.yaml` (never `auto_confirm_from`); `Http/Requests/Recurring/{RuleId,OccurrenceId}.php` (UUIDv7 → `422 INVALID_ID`).

**Checkpoint**: schema, domain, locks, materialiser and the transaction entry exist; no endpoint answers yet.

---

## Phase 3: User Story 1 - Set up a monthly bill once (Priority: P1) 🎯 MVP

**Goal**: a writer creates a rule; upcoming occurrences appear at once; automatic ones post exactly once on their date.

**Independent Test**: create an automatic monthly rule; occurrences `scheduled`, balance unchanged; advance the clock, run the job twice: one transaction, linked, balance moved once.

### Tests for User Story 1

- [X] T021 [P] [US1] `tests/Feature/Wallets/Recurring/CreateRuleTest.php`: US1 S1, S4, S5 — created at version 1, `status: active`, `timezone` from the author's profile (default timezone when no profile), `created_by`; occurrences materialised in the same unit of work through the horizon and at least the next (yearly); backfill ≤ 90 days; ≤ 400 per pass; replay → no second rule or occurrence, nothing bumped; end date and count respected; texts trimmed, blank → null; category own/platform/archived accepted, foreign/deleted/wrong kind → `INVALID_ENUM_VALUE`; currency mismatch → `CURRENCY_MISMATCH`.
- [X] T022 [P] [US1] `tests/Feature/Wallets/Recurring/OccurrenceBalanceTest.php` (US1 S2, FR-013): scheduled occurrences create no postings; balance and `currency_locked` unchanged until a confirmation.
- [X] T023 [P] [US1] `tests/Feature/Wallets/Recurring/AutoConfirmTest.php` and `tests/Feature/Wallets/Recurring/MaterialiseIdempotencyTest.php` (US1 S3, FR-017, FR-019): with a frozen clock at the rule's local midnight (Cairo vs Dubai rules differ by an hour), the job confirms each due occurrence once — transaction with the occurrence's values, links, `created_by` = author, `cleared: false`; never before `auto_confirm_from` (backdated create, offline-created rule); oldest first, ≤ 50 per pass; two sequential job runs and a job run interleaved with materialise produce no duplicates; `TransactionPosted(recorded)` once per confirmation; a rule whose pass throws is logged and skipped while every other rule in the run is still processed (FR-020).
- [X] T024 [P] [US1] `tests/Feature/Wallets/Recurring/RecurringAuthorizationTest.php` (US1 S7, FR-029): owner and editor write (R-02), viewer → 403, non-member → 404, reads open to all members (R-01); transfer rule needs T-02 in the destination (403 otherwise); rule read through the destination's path → 404; after the wallet's currency changes (no transactions yet), the rule can still be paused and ended and its confirmations are refused (FR-003); rows asked through `WalletAuthorizer`, never a role comparison.
- [X] T025 [P] [US1] `tests/Contract/Wallets/Recurring/UpsertRecurringRuleTest.php` and `tests/Contract/Wallets/Recurring/RecurringVerificationGateTest.php`: `201` (the `createRent` example), `200` replay, `422` (`AMOUNT_NOT_INTEGER`, `OUT_OF_RANGE`, `CURRENCY_MISMATCH`, `INVALID_ENUM_VALUE`, `REQUIRED`, `UNKNOWN_FIELD` for `timezone`/`created_by`/`change_seq`, `DATE_NOT_CALENDAR_DATE`, `INVALID_ID`), `403`, `404` (non-member; id held by a transaction, wallet or category; version > 0 for an unknown id), `409` stale and tombstone; every write → `403 EMAIL_NOT_VERIFIED` for an unverified caller, reads allowed. Each with `assertValidRequest()`/`assertValidResponse($status)`.
- [X] T026 [P] [US1] `tests/Feature/Wallets/Recurring/RecurringLogsTest.php` (FR-030): with `Log::spy()`, no log context of a rule write, an occurrence write, a refusal or a job run contains an amount, description, memo or counterparty; the job writes one summary line with counts.

### Implementation for User Story 1

- [X] T027 [US1] `app/Modules/Wallets/Http/Requests/Recurring/SaveRecurringRuleRequest.php`: `RecurringRuleWrite` verbatim — every member required (nullable ones may be null), `version` ≥ 0 optional; `amount_minor` 1..9007199254740991 (`MinorUnits`); `interval` 1–365; `anchor_day` 1–31 or null; `occurrence_count` 1–1000 or null; `payout_round` ≥ 1 or null; dates `YYYY-MM-DD` 1900-01-01..2999-12-31 (`CalendarDate`); `description` ≤ 100, `memo` ≤ 1000, `counterparty` ≤ 100 Unicode characters; enums per the path file; anything else `UNKNOWN_FIELD`.
- [X] T028 [US1] `app/Modules/Wallets/Application/Recurring/RecurringRuleService.php::save` create path and replay (FR-001–FR-004): authorization (R-02; T-02 in a destination), `RecurringRuleRules`, category checks as B6 (own or platform unless unchanged; live; kind; payout category income), currency vs wallet(s), timezone from `Identity\Application\Profiles\Profiles::find($author)->timezone` (fallback `config('identity.default_timezone')`), `auto_confirm_from` = rule today, `CategoryReferences::publish` for each person's category after the wallet lock, `Materialiser::extend` in the same unit of work. Identical replay detected before the stale check.
- [X] T029 [US1] `app/Modules/Wallets/Application/Recurring/AutoConfirmer.php` (FR-017, FR-018, research R-11): select via `dueForAutoConfirm(rule, auto_confirm_from, today, max_confirms_per_pass)`; for each, under the occurrence lock, `TransactionService::recordForOccurrence(actor = author, author, …, server UUIDv7)` and stamp the occurrence `confirmed`; a refusal → `auto_confirm_refusal` (`not_permitted` | `counter_wallet_unavailable` | `currency_mismatch`) in a savepoint so the rest of the pass continues. `RecurringRuleService::catchUp(ruleId, ?actorId)` = lock → `Materialiser::extend` → `AutoConfirmer::confirmDue`.
- [X] T030 [US1] Console `app/Modules/Wallets/Http/Console/MaterialiseOccurrences.php` (`wallets:materialise-occurrences`, beside `VerifyBalances`): pages live non-ended rules by id (500), one unit of work per rule via `catchUp(ruleId, null)`, per-rule exception logged (rule id and exception class only) and skipped, one summary log line; register in `routes/console.php` as `Schedule::command('wallets:materialise-occurrences')->hourly()->withoutOverlapping();`.
- [X] T031 [US1] `RecurringRuleController::upsert` (201/200, `meta` `(object) []`) and problem mapping. Run T021–T026 — green.

**Checkpoint**: US1 delivers automatic rules end to end — the C7 rule editor can run against live data.

---

## Phase 4: User Story 2 - Confirm, skip or adjust one occurrence (Priority: P1)

**Goal**: members resolve occurrences offline-safely; one transaction per occurrence whatever races.

**Independent Test**: edit one (becomes `edited`), confirm with a client transaction id, replay, skip, un-skip, race two stale confirms (one 200, one 409).

### Tests for User Story 2

- [X] T032 [P] [US2] `tests/Feature/Wallets/Recurring/SaveOccurrenceTest.php` (US2 S1, S5–S7, FR-014, FR-015): edit date (outside the rule window allowed, key unchanged), amount, category (kind, own/platform unless unchanged, null on a transfer), texts → `edited`; reverting clears it; skip and un-skip; confirmed → any change `IMMUTABLE_FIELD`; unknown id → 404; stale → 409 with `current`; identical → nothing moves; a changing save clears `auto_confirm_refusal`.
- [X] T033 [P] [US2] `tests/Feature/Wallets/Recurring/ConfirmOccurrenceTest.php` (US2 S2–S3, FR-016): confirm records one transaction with the client id and the values of the same write, links, author, `cleared: false`, balance moves; replay → nothing new; `transaction_id` taken by any record → `INVALID_ENUM_VALUE`; destination deleted → `INVALID_ENUM_VALUE` at `/status`; currency changed on an unlocked destination → `CURRENCY_MISMATCH` at `/status`; caller lacking T-02 in the destination → 403; nothing applied on any refusal; confirming a future-dated occurrence allowed.
- [X] T034 [P] [US2] `tests/Feature/Wallets/Recurring/AutoConfirmRaceTest.php` (US2 S4, FR-019): on two PostgreSQL connections (as spec 011/012 lock-order tests do): member confirm vs member confirm, member confirm vs job — exactly one transaction, the loser 409 with the confirmed occurrence; no deadlock.
- [X] T035 [P] [US2] `tests/Feature/Wallets/Recurring/AutoConfirmRefusalTest.php` (US2 S8, FR-018): author demoted to viewer → `not_permitted`; transfer destination deleted → `counter_wallet_unavailable`; destination currency changed → `currency_mismatch`; the job does not retry a refused occurrence; a member can still confirm (after fixing) or skip; a changing save clears the refusal and the next pass confirms it.
- [X] T036 [P] [US2] `tests/Contract/Wallets/Recurring/SaveOccurrenceTest.php`: `200` confirm (the `confirmWithActualAmount` example), `200` skip, `200` replay, `404` unknown id / other wallet / non-member, `403` viewer, `409` stale and tombstone, `422` (`REQUIRED` and `INVALID_ENUM_VALUE` at `/transaction_id`, `IMMUTABLE_FIELD`, `UNKNOWN_FIELD` for `kind`/`scheduled_date`/`edited`, `OUT_OF_RANGE` for `version: 0`), `405` for DELETE.

### Implementation for User Story 2

- [X] T037 [US2] `app/Modules/Wallets/Http/Requests/Recurring/SaveOccurrenceRequest.php`: `OccurrenceWrite` verbatim — `status` enum, `date` (`CalendarDate`), `amount_minor` (`MinorUnits`), `category_id` uuid or null, texts with their bounds, `transaction_id` UUIDv7 or null, `version` ≥ 1, all required; anything else `UNKNOWN_FIELD`.
- [X] T038 [US2] `app/Modules/Wallets/Application/Recurring/OccurrenceService.php::save` (FR-014–FR-016): locks per T018 (the requested category included), precedence of spec §6, `OccurrenceRules`, category checks, `edited` recomputed against the rule-derived values, confirm via `TransactionService::recordForOccurrence(actor = caller, author = rule's created_by, …)` with refusals mapped to `/status` (or 403), `auto_confirm_refusal` cleared on a changing save, references published/withdrawn via `WalletCategoryUses`.
- [X] T039 [US2] `OccurrenceController::save`. Run T032–T036 — green.

**Checkpoint**: manual rules work offline; C14's queue can carry confirmations.

---

## Phase 5: User Story 3 - Change the series (Priority: P2)

**Goal**: rule edits, pause/resume, end and delete keep resolved history intact.

**Independent Test**: template edit propagates to pending, unedited occurrences only; schedule frozen after a confirm; end at D − 1 refused across a resolved round; pause/resume across a due date leaves it for a decision.

### Tests for User Story 3

- [X] T040 [P] [US3] `tests/Feature/Wallets/Recurring/SaveRuleTest.php` (US3 S1–S6, FR-005–FR-007): template propagation (version + 1 and `change_seq` per touched occurrence; edited/resolved untouched); schedule change before any resolution reconciles by key; frozen members after a resolution; end/count shortening tombstones pending occurrences past the end and refuses cutting a resolved one; pause stops materialising and auto-confirming; resume resets `auto_confirm_from`; switching `auto_confirm` on resets it; ending tombstones pending occurrences after today and freezes the rule; stale → 409 with `current`; identical → nothing moves; a member's occurrence save after a propagating rule edit → 409.
- [X] T041 [P] [US3] `tests/Feature/Wallets/Recurring/DeleteRuleTest.php` (US3 S7, FR-008): delete tombstones the rule and every occurrence (resolved too) with stamps; produced transactions untouched; delete of a tombstone → unchanged; edit or materialise of a tombstone → 409.
- [X] T042 [P] [US3] `tests/Contract/Wallets/Recurring/DeleteRecurringRuleTest.php`: `200` tombstone (twice), `409` stale, `422` missing version, `404`, `403`.

### Implementation for User Story 3

- [X] T043 [US3] `RecurringRuleService::save` edit path (research R-9): classify the change, apply `RecurringRuleRules` against `hasResolved`/`highestResolvedOrdinal`, propagate template values to `pendingUnedited`, reconcile keys on schedule changes, tombstone past a shortened end or after today on `ended`, reset `auto_confirm_from` on resume or switch-on, publish/withdraw references via `WalletCategoryUses`, then `Materialiser::extend`.
- [X] T044 [US3] `RecurringRuleService::delete` and `RecurringRuleController::destroy` with `Http/Requests/Recurring/RuleVersionRequest.php`. Run T040–T042 — green.

---

## Phase 6: User Story 4 - Gam'eya and installment presets (Priority: P2)

**Goal**: the two presets validate their shape and produce exact amounts.

**Independent Test**: gam'eya count 10, payout round 4 → ten contributions + one payout worth ten; installment total 1,000,000 over 12 → exact split.

### Tests for User Story 4

- [X] T045 [P] [US4] `tests/Feature/Wallets/Recurring/PresetRuleTest.php` (US4 S1–S4, FR-004, FR-012): gam'eya materialises contributions and one payout occurrence (`is_payout`, income, payout category, round 4's date, Σ scheduled rounds) whose key does not collide with round 4's contribution; installment `total` split sums exactly (the `createInstallment` example: four of 83,334, eight of 83,333); installment as a transfer; every preset violation refused at its member; payout category reference published for a person's category.

### Implementation for User Story 4

- [X] T046 [US4] Wire presets and shares end to end: `RecurringRuleRules` preset checks used by `save`, `Materialiser` payout row and `OccurrenceAmounts` shares, payout bound refusal at `/amount_minor`. Run T045 — green.

---

## Phase 7: User Story 5 - Catch up and stay in sync (Priority: P2)

**Goal**: materialise on request; rules and occurrences on the wallet feed and in the batch; purge and wallet deletion handled.

**Independent Test**: materialise catches a missed job run; the feed shows both types in commit order; a batch of rule and occurrence items matches single writes; purge keeps referenced tombstones.

### Tests for User Story 5

- [X] T047 [P] [US5] `tests/Feature/Wallets/Recurring/MaterialiseRuleTest.php` and `tests/Contract/Wallets/Recurring/MaterialiseRecurringRuleTest.php` (US5 S1, FR-021): does what the job does; second call changes nothing; paused/ended → 200 unchanged; tombstone → 409 with `current`; viewer → 403; non-member → 404; unverified → 403 `EMAIL_NOT_VERIFIED`.
- [X] T048 [P] [US5] `tests/Contract/Sync/RecurringFeedTest.php` and `tests/Contract/Sync/RecurringBatchParityTest.php` (US5 S2–S3, FR-023): `recurring_rule` and `occurrence` rows on `GET /sync/wallets/{id}/changes` in `change_seq` order, tombstones included, each equal to its read; server-made writes (materialise, auto-confirm, propagation) appear; a transfer rule is not on the destination's feed while its transactions are; batch items `recurring_rule` upsert/delete and `occurrence` upsert give the single write's status and body; `occurrence` `delete` and a missing `wallet_id` → envelope `422`.
- [X] T049 [P] [US5] `tests/Feature/Sync/RecurringPurgeTest.php` (US5 S5, FR-025) and `tests/Feature/Wallets/Recurring/RecurringWalletDeletionTest.php` (FR-028): tombstones older than 90 days purged and `purged_change_seq` raised; a rule kept while any occurrence exists; an occurrence kept while a transaction names it; wallet deletion tombstones its rules and occurrences in the same unit of work; a destination's deletion leaves the rule live.
- [X] T050 [P] [US5] `tests/Feature/Wallets/Recurring/RecurringCategoryUsageTest.php`, `tests/Feature/Wallets/Recurring/RecurringCategoryReferencesTest.php` and `tests/Feature/Wallets/Recurring/RecurringLockOrderTest.php` (FR-026, FR-027): categories used by rules (incl. payout) and occurrences are `in_use`, set-wise in a constant number of queries, `DELETE /categories/{id}` → `409 CATEGORY_IN_USE`; a reference stays while a rule uses the category even after the last transaction moves away (B6 withdrawal fixed), and is withdrawn when nothing in the wallet uses it; a concurrent category rename and rule/occurrence write, and a job pass vs a rule edit, complete without deadlock.
- [X] T051 [P] [US5] `tests/Contract/Wallets/Recurring/ListRecurringRulesTest.php`, `tests/Contract/Wallets/Recurring/ListOccurrencesTest.php` and `tests/Contract/Wallets/Recurring/GetRecurringTest.php` (FR-022): rules — every status, default `created_at`, `sort=start_date`, `filter[status]`; occurrences — default `date` ascending, filters `recurring_rule_id` (eq/in, 51 values → `400`), `status`, `date` operators; unsupported field/operator/sort → matching `400`; cursor reused with another filter → `INVALID_CURSOR`; live and tombstoned single reads; foreign ids, non-members, deleted path wallet → 404; a transaction read shows `recurring_rule_id`/`occurrence_id`.

### Implementation for User Story 5

- [X] T052 [US5] `RecurringRuleController::materialise` (no body; `catchUp(ruleId, caller)` after R-02 and tombstone checks).
- [X] T053 [US5] Reads: `app/Modules/Wallets/Application/Recurring/RecurringQueryService.php` (`listRules`, `findRule`, `listOccurrences`, `findOccurrence`; `R01ReadRecurring`), keyset repositories, `Http/Requests/Recurring/{RuleListRequest,OccurrenceListRequest}.php` enforcing `x-filterable`, operators, `x-sortable` and the 50-value `in` bound; `index`/`show` actions on both controllers.
- [X] T054 [US5] Feed and batch (research R-8): `SyncFeedReader` (port + Eloquent) `rulesChangedIn`, `occurrencesChangedIn`; `FeedPresenter::recurringRule`, `::occurrence` (implemented in `Http/Sync/ResourceFeedPresenter.php` with the read resources); `WalletFeedQuery::page` merges both types; `app/Modules/Sync/Domain/Batch/ItemType.php` gains `RecurringRule = 'recurring_rule'` (`upsert`, `delete`) and `Occurrence = 'occurrence'` (`upsert`), wallet-scoped, routed by `RouterItemDispatcher` to the single-write routes; envelope validation updated (`wallet_id` required for both).
- [X] T055 [US5] Purge and deletion: `TombstoneStore` and `TombstonePurge` gain `occurrences` then `recurring_rules` steps (keep rules, keep linked occurrences), raising `wallets.purged_change_seq` under the documented lock order; `app/Modules/Wallets/Application/Recurring/RecurringGoesWithWallet.php` (`WalletDeletionParticipant`) tagged beside `TransactionsGoWithWallet`. Run T047–T051 — green.

---

## Phase 8: Polish & Cross-Cutting Concerns

- [X] T056 [P] Apply the endpoint review checklist and the security review checklist to every operation in `docs/openapi/paths/recurring.yaml` (and the changed parts of `sync.yaml`, `transactions.yaml`); record results in plan.md § Review checklists, with the exceptions already listed there. Confirm the implementation matches the frozen contract member for member — any change to a member C7 binds to (contracts/recurring-api.md) is a contract change to raise, not to make.
- [X] T057 [P] Record B7's obligations on later features as "Inherited from B7 (spec 014)" notes in `specs/briefs/B08-budgets.md` (confirmed occurrences are ordinary transactions; attribution by `created_by` = rule author), `specs/briefs/B13-reports.md` (forecast from `scheduled` occurrences through a query service; never from transactions), `specs/briefs/B15-export-data-deletion.md` (export rules and occurrences; a departing author's rules), `specs/briefs/B16-devices-push.md` (due-ness is derived; compute it, no stored state).
- [X] T058 Run the gates: `dev exec -- composer test` (lint, types, arch, suite, api) and the quickstart walk; measure p95 of a daily-rule create and of the occurrence list (200 rules / 2,000 occurrences) and one job run over 10,000 active rules on the dev stack with Xdebug off (SC-007); re-bundle `docs/openapi` and import into Apidog (project 1332822, module 1363204) with a "Recurring transactions" doc. State "Seeder: N/A — no reference data" in the commit/PR description.
- [X] T059 Write specs/014-recurring-transactions/summary.md (parent writes it)

---

## Dependencies & Execution Order

- **Setup (T001–T006)**: T001, T002, T003 independent; T004 → T005 → T006.
- **Foundational (T007–T020)**: tests T007–T011 first (T011 needs T004–T006); T012, T013 in parallel → T014 → T015 → T016, T017, T018 (T016 and T017 touch `TransactionService` — do T016 then T017) → T019; T020 independent of T016–T019. Blocks every story.
- **US1 (T021–T031)**: depends on Foundational.
- **US2 (T032–T039)**: depends on US1 (rules to resolve; shares `AutoConfirmer` for T034).
- **US3 (T040–T044)**: depends on US1 and US2 (resolved occurrences freeze the schedule).
- **US4 (T045–T046)**: depends on US1; independent of US2/US3.
- **US5 (T047–T055)**: depends on US1 and US2 (feed and batch of both types); T054 touches the Sync module.
- **Polish (T056–T059)**: after all stories; T059 last.

## Parallel Execution Examples

- Setup: T001, T002, T003 together; then T004 → T005 → T006.
- Foundational: T007–T010 together (pure units), T011 after the migrations; T012 ∥ T013; T020 alongside T016–T019.
- US1 tests T021–T026 together, then T027 → T028 → T029 → T030 → T031.
- After US1: US2 tests (T032–T036) and US4 test (T045) together.
- US5 tests T047–T051 together, then T052, T053 in parallel, T054, T055.

## Implementation Strategy

1. **MVP** = Setup + Foundational + US1: automatic rules with exact, idempotent posting — the C7 editor and upcoming list run against live data.
2. Add US2 (confirm/skip/edit-one): manual rules and the offline confirm contract C14 carries.
3. Add US3 (series edits) and US4 (presets), then US5 (catch-up, feed, batch, purge) and Polish. Each phase ends at a green checkpoint and can be committed on its own on `main`.
