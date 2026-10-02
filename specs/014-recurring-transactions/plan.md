# Implementation Plan: Recurring Transactions

**Branch**: `014-recurring-transactions` (trunk-based; work lands on `main`) | **Date**: 2026-10-02 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/014-recurring-transactions/spec.md`.

## Summary

Add recurring rules and their occurrences to the Wallets & Ledger module (`app/Modules/Wallets`), under a `Recurring` sub-namespace in each layer.

- **Records** (data-model.md): `recurring_rules` (client-created, syncable, wallet scope) and `occurrences` (server-created, syncable, wallet scope); `transactions` gains read-only `recurring_rule_id` and unique `occurrence_id`.
- **Who generates** (R-1, ADR-0018 written in this phase): only the server materialises occurrences — on a rule write, on the hourly job `wallets:materialise-occurrences`, and on `POST …/materialise` — idempotent by the occurrence key `(recurring_rule_id, scheduled_date, is_payout)`.
- **Confirm** (R-2): a versioned `PUT` of the occurrence with a client-generated `transaction_id`; the transaction is recorded through a new `TransactionService::recordForOccurrence` inside the same unit of work (R-6). Auto-confirm is the same transition with a server-minted id, as the rule's author, only for dates on or after the rule's auto-confirm start (R-11). One-to-one uniques on both sides.
- **Schedule** (R-4): pure domain `RuleDates` with month-end clamping from the origin; "today" in the rule's stored timezone (R-5); horizon today + 35 days and at least the next; backfill ≤ 90 days; ≤ 400 per pass.
- **Edits** (R-9): template changes propagate to pending, unedited occurrences; schedule frozen once any occurrence is resolved; "from date D" = end at D − 1 + new rule.
- **Presets and split** (R-10): `gameya` (payout occurrence) and `installment`; `Money::allocate()` added to the shared kernel.
- **Inherited obligations**: lock order categories → wallets → rule → occurrences (R-6); `CategoryUsage` participant and a wallet-wide use check before withdrawing references, fixing B6's withdrawal (R-7); wallet deletion participant (R-13); wallet feed types, batch types and purge steps (R-8).
- **API**: eight operations in `docs/openapi/paths/recurring.yaml` (written, indexed, examples, `info.version` v1.7 in this phase), additive `sync.yaml` and `transactions.yaml` changes; frozen for C7. No new error code.
- **Docs**: ADR-0018 and its index row (this phase); spec 010's R-02 wording (this phase; roles unchanged); glossary, constitution PATCH v1.3.7 (P-05, P-06, P-07, P-12 `ADRs:` lines gain ADR-0018), api-conventions and security baseline in T001.

## Technical Context

**Language/Version**: PHP 8.5, Laravel 13.

**Primary Dependencies**: none new (schedule arithmetic on `DateTimeImmutable`; no RRULE library — CONTRIBUTING item 17).

**Storage**: PostgreSQL 16; two new tables, one altered (`transactions`); CHECK constraints for shapes; unique occurrence key; partial uniques for the one-to-one link.

**Testing**: Pest.
- Unit: `RuleDates` (month-end, leap year, weekly/daily/yearly, start off-anchor, end/count), `Money::allocate`, `OccurrenceAmounts`, `RecurringRuleRules` (presets, frozen members, ended), `OccurrenceRules` (transitions), `RuleToday` (timezone).
- Feature: create/save/delete rule, propagation, schedule reconcile, series end, presets; occurrence edit/confirm/skip; auto-confirm, auto-confirm start, refusals, races (two connections), idempotent reruns; materialise; balance untouched by scheduled occurrences; authorization (R-01/R-02/T-02 destination); lock order; category usage and references (incl. B6's withdrawal fixed); wallet deletion; purge; job command.
- Contract: eight operations with every documented status (Spectator), verification gate, feed rows and batch parity, transaction links.
- Arch: existing gates; no new gate.

**Target Platform**: the Linux server (the `dev` container) plus the scheduler (`schedule:run` every minute).

**Project Type**: modular-monolith JSON API.

**Performance Goals**: rule write p95 < 300 ms with a daily horizon; list p95 < 300 ms at 200 rules / 2,000 occurrences per wallet; job 10,000 active rules < 10 min (SC-007).

**Constraints**: P-03 (bigint, allocate), P-04/P-05 (occurrences never post; transactions only through `TransactionService`), P-06 (client UUIDv7 rules; server UUIDv7 occurrences; foreign id 404), P-07 (version, tombstones, no merge), P-08 (R-01/R-02, T-02 destination, non-member 404), P-11 (events only through `TransactionService`), P-12 / ADR-0011 rule 9 (rule timezone), P-15 (no amounts or text logged), ADR-0014 rule 10 lock order, ADR-0016 batch, ADR-0018.

**Scale/Scope**:
- 8 operations (5 path entries), 3 migrations, 1 console command, 1 schedule entry, no seeder (no reference data);
- about 40 Wallets classes, `Money::allocate` in Shared, 2 Sync batch types, `TransactionService::recordForOccurrence`, purge and feed extensions;
- about 30 test files;
- doc edits: glossary, constitution PATCH, ADR-0018 + index, spec 010 R-02 wording, api-conventions (batch type list, read-only server-created syncable record), security baseline (verification gate rows), `lang/` none (no new code).

## Constitution Check

*Constitution v1.3.6 (`.specify/memory/constitution.md`). GATE: must pass before Phase 0 research. Re-check after Phase 1 design, keeping both results.*

### Pre-design

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | "Recurring Rule", "Occurrence", "Wallet"; gam'eya and installment are `preset` values, not entities (glossary); no "schedule/repeat/recurrence/instance/run" in names — the job is `wallets:materialise-occurrences`, the services `RecurringRuleService`, `OccurrenceService`; the domain value object for dates is `RuleDates` (not "Schedule" as a class name; see note below). New terms added to the glossary in T001 before code uses them |
| P-02 Module boundaries | PASS | All in `app/Modules/Wallets` (its constitution row owns recurring rules); author timezone through Identity's Application `Profiles`; Sync reaches recurring through Wallets' Application `FeedPresenter`/`SyncFeedReader` and the batch through routes (spec 013 R-20) |
| P-03 Money | PASS | `amount_minor bigint`, integer JSON; totals split by `Money::allocate()` (rule 4), added to the shared `Money` (T003) |
| P-04 Ledger integrity | PASS | Occurrences have no postings; a confirmed occurrence's transaction is recorded by `TransactionService` (two postings, zero sum, transfer one record) |
| P-05 Derived balances | PASS | Scheduled occurrences never post; balances move only through `TransactionService`'s recompute under the wallet lock |
| P-06 Identifiers | PASS | `PUT /wallets/{id}/recurring-rules/{rid}` upsert with client UUIDv7; occurrences are server-created records (as Category References) with server UUIDv7 and a `PUT` that never creates (ADR-0018 rule 1); foreign or other-entity id → 404 |
| P-07 Sync and concurrency | PASS | Five sync members on both; `version` in every body; 409 with `current` incl. tombstone; no merge; deletes are tombstones; server writes stamp `change_seq` like any write; rule delete final (no restore offered, as spec 010's wallet delete) |
| P-08 Authorization | PASS | Rows R-01, R-02 of spec 010 §6 (wording amended there, roles unchanged); T-02 in a transfer's destination; non-member 404; matrix referenced, not restated |
| P-09 Entitlements | PASS | No capability or quota (rule 5) |
| P-10 Billing channels | N-A | No billing surface |
| P-11 Events | PASS | No new event; `TransactionPosted` from `TransactionService` after commit |
| P-12 Time | PASS | `start_date`, `end_date`, `scheduled_date`, `date` are calendar dates; the rule stores its IANA timezone at creation (rule 2, ADR-0011 rule 9); "today" only decides due-ness (ADR-0011 rule 8); timezone changes rewrite nothing |
| P-13 Naming | PASS | No codename |
| P-14 Localisation | PASS | No new user-facing string (no new code); preset names translated by the client |
| P-15 Observability | PASS | Job logs counts only; no amount or text logged |
| P-16 Quality gates | PASS | Every spec §4 invariant names its test; PostgreSQL only (partial uniques, `ON CONFLICT`, row locks) |
| P-17 Scope guard | PASS | "Bill reminders beyond recurring occurrences" stays out (no push, B16); no FX |
| P-18 Delivery surfaces | PASS | Every capability is a `/api/v1` endpoint; no back-office screen |

Note on P-01: the glossary bans "schedule" as a synonym for Recurring Rule in names. The schedule arithmetic lives in a value object named `RuleDates` (domain), and prose may say "the rule's schedule" as the glossary's own entries do.

### Post-design re-check

Unchanged: all rows PASS or N-A.

- **CONTRIBUTING item 18**: ADR-0018 (Accepted, written and indexed in this phase) extends ADR-0008 (a server-created syncable record on the wallet feed, server writes stamped like any write) and ADR-0011 (derived due-ness, rule timezone as "today") without changing any of their rules, and fixes the reconcile rows of brief B7. The constitution's P-05, P-06, P-07 and P-12 `ADRs:` lines gain ADR-0018 (PATCH v1.3.7, T001).
- The api-conventions rule "a create is `PUT` with a client id" holds for rules; occurrences are a second server-produced syncable record beside Category Reference, but unlike it they accept a `PUT` that never creates. api-conventions "Read-only resources" gains a paragraph for this case (T001).
- Endpoint review checklist exceptions are recorded below.
- No new error code; `info.version` v1.7 for the new paths.

Complexity Tracking stays empty.

## Project Structure

### Documentation (this feature)

```text
specs/014-recurring-transactions/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/recurring-api.md
├── checklists/requirements.md
├── checklists/recurring.md      # /speckit-checklist
└── tasks.md                     # /speckit-tasks
```

### Source Code (repository root)

```text
app/Modules/Shared/Domain/Money/Money.php                 # + allocate()
app/Modules/Wallets/
├── Domain/Recurring/                # RuleDates, Frequency, Preset, AmountBasis, RuleStatus,
│                                    # OccurrenceStatus, AutoConfirmRefusal, RecurringRuleRecord,
│                                    # OccurrenceRecord, RecurringRuleRules, OccurrenceRules,
│                                    # OccurrenceAmounts, OccurrencePlan, Ports/, Refusals/
├── Application/Recurring/           # RecurringRuleService (save, delete, catchUp),
│                                    # OccurrenceService (save), Materialiser, AutoConfirmer,
│                                    # RecurringQueryService (list/read), RecurringCategoryUsage,
│                                    # RecurringGoesWithWallet, Dto/
├── Application/Categories/WalletCategoryUses.php        # wallet-wide use check (R-7)
├── Application/Transactions/TransactionService.php      # + recordForOccurrence (R-6)
├── Application/Sync/{FeedPresenter,WalletFeedQuery,TombstonePurge}.php   # extended (R-8)
├── Infrastructure/Database/Migrations/                  # 3 migrations
├── Infrastructure/Repositories/Eloquent{RecurringRule,Occurrence}Repository.php
├── Infrastructure/Models/{RecurringRule,Occurrence}.php
├── Http/Controllers/Recurring/, Http/Requests/Recurring/, Http/Resources/{RecurringRuleResource,OccurrenceResource}.php
├── Http/Console/MaterialiseOccurrences.php              # wallets:materialise-occurrences
└── Http/routes.php                                      # + 8 routes, writes behind address.verified
app/Modules/Sync/Domain/Batch/ItemType.php               # + RecurringRule, Occurrence
routes/console.php                                       # + hourly()->withoutOverlapping()
config/wallets.php                                       # + recurring.{horizon_days,backfill_days,max_per_pass}

tests/Unit/Wallets/Recurring/, tests/Unit/Shared/MoneyAllocateTest.php
tests/Feature/Wallets/Recurring/, tests/Feature/Sync/RecurringPurgeTest.php
tests/Contract/Wallets/Recurring/, tests/Contract/Sync/Recurring{Feed,BatchParity}Test.php
```

**Structure Decision**: the existing module layout (constitution P-02); `Recurring` sub-namespaces mirror `Transactions` and `Categories`. The console command sits beside `wallets:verify-balances` (check the existing location of `VerifyBalances`' command class in T002 and follow it).

## Design notes

1. **Write algorithm** (rule and occurrence writes, materialise, job): read the stored rule/occurrence unlocked → share-lock categories ascending (stored ∪ requested `category_id`, `payout_category_id`, occurrence `category_id`) → lock wallets ascending (rule wallet, destination if transfer and a membership) → lock the rule row (`FOR UPDATE`) → lock affected occurrence rows ascending → re-read; restart (≤ 3) if the lock signature drifted (as spec 012 R-4) → precedence checks → write → stamp `change_seq` from the wallet counter for every changed row, then a final wallet stamp (spec 012 implementation default 18).
2. **Materialiser** (`Materialiser::extend(rule, today)`): `RuleDates::between(from = max(start, createdDay − 90), to = max(today + 35, nextAfter(today)), cap 400)` minus existing keys → `OccurrencePlan` rows (amounts from `OccurrenceAmounts`, payout row for gam'eya) → one multi-row `INSERT … ON CONFLICT (recurring_rule_id, scheduled_date, is_payout) DO NOTHING` with server UUIDv7 ids and wallet stamps.
3. **AutoConfirmer** (`confirmDue(rule, today)`): for `active` + `auto_confirm` rules, select `scheduled`, unrefused occurrences with `auto_confirm_from ≤ date ≤ today` ordered by date; for each, `recordForOccurrence(actor = author, author, …)`; on refusal set `auto_confirm_refusal` (R-11). The whole catch-up of one rule is one unit of work; if it would confirm more than 50 occurrences, it confirms 50 and the next pass continues (bounds one transaction).
4. **`TransactionService::recordForOccurrence`**: no `DB::transaction` restart loop (the caller holds the locks); requires an open transaction (asserts `DB::transactionLevel() > 0`); reuses the private create path with `created_by = author`, links set, ownership check skipped; returns the `TransactionView`. Refusals surface as the same domain refusals, mapped by `OccurrenceService` to `/status` pointers.
5. **Feed**: `occurrences` and `recurring_rules` stamped from the rule's wallet only (no counter stamp; a transfer rule is not on the destination feed — spec Open question 5).
6. **Transactions resource**: emits `recurring_rule_id`, `occurrence_id`; the same task adds both to the `Transaction` schema's `required` list in `transactions.yaml` (research R-14).

## Assumed defaults (owner away)

| # | Decision | Why |
|---|---|---|
| A-1 | Only the server materialises occurrences; a client never creates one (404) | One generator + unique key cannot duplicate; C7 asks the server to materialise; ADR-0018 |
| A-2 | Occurrence key `(recurring_rule_id, scheduled_date, is_payout)` | Brief's `rule_id + due_date`, plus the payout flag for a gam'eya round that also pays out |
| A-3 | No `planned` transactions; scheduled occurrences have no postings | P-05; glossary's Occurrence entity |
| A-4 | Confirm = versioned occurrence `PUT` with client `transaction_id`; auto-confirm the same transition | One write, one version: every race is a 409; batchable (ADR-0016) |
| A-5 | `auto_confirm` per rule; automatic rules confirmed by the server, manual rules by members | Brief's job + glossary/R-02's confirm |
| A-6 | Job hourly (`withoutOverlapping`) instead of daily | Meets every timezone's midnight within an hour |
| A-7 | Never auto-confirm before the auto-confirm start (creation, resume, switch-on) | Never back-post money nobody saw |
| A-8 | Refused auto-confirmations surfaced in `auto_confirm_refusal`, not retried | Brief: "a refusal to surface, not a retry" |
| A-9 | `due`/`missed` derived, not stored | No housekeeping writes, no spurious conflicts |
| A-10 | Rule timezone = author's profile timezone at creation, immutable, read-only | ADR-0011 rule 9, glossary |
| A-11 | Month-end: clamp `anchor_day`; compute from the origin | No drift; C7 asked for one rule |
| A-12 | Weekly on `start_date`'s weekday; `anchor_day` only for monthly/yearly; yearly month = `start_date`'s month | Fewest members that express every case |
| A-13 | Horizon today + 35 days and at least the next; backfill ≤ 90 days; ≤ 400 per pass; ≤ 50 auto-confirms per pass | Bounded writes; next monthly occurrence always visible |
| A-14 | Template edits propagate to pending, unedited occurrences; schedule frozen after the first resolution; "from D" = end at D − 1 + new rule | Resolved history immutable; no period posted twice; batchable |
| A-15 | Shortening an end below a resolved occurrence → `OUT_OF_RANGE` | Never orphan or rewrite a decided round |
| A-16 | Status `active` ⇄ `paused` → `ended`; ended is immutable except delete; create as active or paused | Glossary states; simplest safe lifecycle |
| A-17 | Ending tombstones pending occurrences after the rule's today; earlier ones stay for a decision | Ending stops the future, never erases due items |
| A-18 | Rule delete final, cascades tombstones to all its occurrences; transactions untouched | Spec 010 precedent (wallet delete final); money history kept |
| A-19 | Confirmed occurrence immutable; deleting its transaction does not un-confirm | Un-confirm re-opens double-posting |
| A-20 | Skipped → scheduled (un-skip) and skipped → confirmed allowed | A mis-tap must be recoverable |
| A-21 | Occurrence `date` editable to any date 1900–2999; key unchanged | Late or early payments are common |
| A-22 | Skipped rounds count toward `occurrence_count`; payout and total shares from scheduled rounds | A skip decides one round, not the series |
| A-23 | Produced transaction: `created_by` = rule's author, `cleared: false` | Brief's inherited B6 note; one attribution for automatic and manual |
| A-24 | Category ownership checked at rule/occurrence write, not again at confirm (liveness and kind are) | An editor's confirm must not fail on the author's own category |
| A-25 | Gam'eya: monthly expense contributions + one income payout occurrence (payout category required) worth Σ scheduled rounds | The common Egyptian bookkeeping; presets of one rule |
| A-26 | Installment: monthly, expense or transfer, count 2–120; `amount_basis: total` splits by `Money::allocate`, remainder to earliest rounds | P-03 rule 4; common practice |
| A-27 | Presets are an enum with validation; nothing seeded | Behaviour, not reference data; "Seeder: N/A" |
| A-28 | Transfer rule lives in the source scope only; needs T-02 in the destination on writes and confirms | ADR-0015 for the produced transactions; T-03 applied to rules |
| A-29 | Rule list returns every live rule (all statuses), filter `status`; occurrence list sorted by date ascending | Upcoming-first is what the screen shows |
| A-30 | Materialise on a paused/ended rule → 200 unchanged; on a tombstone → 409 with `current` | A tombstone is a current state like any other |
| A-31 | Paths under `/wallets/{id}/…` (brief's `/recurring/{id}` reconciled) | Spec 003 / 012 convention for wallet-scoped resources |
| A-32 | No new error code; refusal reason as a resource enum | Every case maps onto the catalogue |
| A-33 | No new event, no rate limit, no entitlement, no seeder | P-09 rule 5; B16 computes due-ness |
| A-34 | Transaction links listed as optional in `transactions.yaml` now, made required in the task that emits them | Keeps the existing contract suite green between design and implementation |
| A-35 | Spec 010 R-02 wording amended (delete, catch-up, edit one, destination T-02); roles unchanged | The matrix lives only in spec 010 (P-08 rule 5) |
| A-36 | B6's reference withdrawal consults rules and occurrences (wallet-wide use check) | Otherwise a B6 edit would withdraw a reference a rule needs |
| A-37 | A `total` must be ≥ `occurrence_count` minor units; gam'eya and `total` rules freeze `occurrence_count` and `amount_minor` after the first resolution (amount added in QA); count ≥ `payout_round` | Every share positive; shares and payout never re-spread over agreed rounds |
| A-38 | A changing manual save clears `auto_confirm_refusal` and re-enables automatic confirmation; identical saves change nothing | The person fixes the cause, then the rule resumes on its own |
| A-39 | No author profile → `identity.default_timezone` | Same fallback Identity already uses (`EloquentProfileRepository::timezoneOf`) |
| A-40 | A rule's currency is checked on create only; a later wallet currency change (possible before any transaction) refuses confirmations, not rule edits; the wallet currency lock stays transaction-based | A rule must stay pausable and deletable; extending the lock would change spec 010's contract |

## Review checklists (applied at the polish task)

- **Endpoint review checklist** (`docs/api-conventions.md` appendix), expected exceptions:
  - C1 ("a create is `PUT` with a client UUIDv7") and C2 (`201` on create) do not apply to `saveOccurrence`: occurrences are created only by the server (ADR-0018); the `PUT` never creates and answers 404 for an unknown id.
  - C3 does not apply to `materialiseRecurringRule` (no body, no field change by the client; idempotent by construction).
  - G3, G4 do not apply (no idempotency-keyed operation); G5 does not apply (no throttle).
  - I2 is "no" for the feature: five path entries, one per distinct path.
- **Security review checklist**: every write behind the gate; amounts and texts already in the protected-attributes table (members only, unencrypted, not logged); refusals disclose nothing outside the caller's wallets; automatic posting acts only with the author's current authority.

### Results (T056)

Applied to every operation of `docs/openapi/paths/recurring.yaml` (`listRecurringRules`, `getRecurringRule`, `upsertRecurringRule`, `deleteRecurringRule`, `materialiseRecurringRule`, `listOccurrences`, `getOccurrence`, `saveOccurrence`) and to the changed parts of `sync.yaml` and `transactions.yaml`, against the implementation and its contract tests.

**Endpoint review checklist**

- A (shape), B (errors), D (syncable envelope), F (values): yes for all eight. Every body is `{ data, meta }` with `meta` an empty object when empty; every problem `$ref`s `components/responses.yaml`; every code used exists in `error-catalog.yaml` (no new code, `info.version` v1.7); `RecurringRule` and `Occurrence` compose `SyncableResource` plus `WalletScoped` (wallet scope, matching the glossary); `deleted_at` is nullable and tombstones ride the feed; no posting is exposed; money is `amount_minor` plus `currency`, dates are `format: date`, instants UTC.
- C (identifiers and writes): yes for `upsertRecurringRule` and `deleteRecurringRule` (client UUIDv7, `201`/`200`, `version` in the body, `409` with `current` including a tombstone, `404` for another wallet's or another entity's id, `422 INVALID_ID`; tested in `UpsertRecurringRuleTest`, `DeleteRecurringRuleTest`). Exceptions as listed above and confirmed in the implementation: C1 and C2 do not apply to `saveOccurrence` (the server alone creates occurrences, ADR-0018; an unknown id is `404`, never `201`; tested in `SaveOccurrenceTest`) and C3 does not apply to `materialiseRecurringRule` (no body, nothing the client changes). C8 is not applicable: an occurrence is not read-only but a server-created record that accepts a `PUT`; its `DELETE` is `405` with `Allow`, tested.
- E (collections): yes for both lists: cursor and limit, `meta` with `next_cursor`, `has_more` and `limit`, `x-filterable` and `x-sortable` present, `filter[field]=op:value`, unsupported field, operator and sort refused, opaque cursor valid only with its filters and sort (`ListRecurringRulesTest`, `ListOccurrencesTest`).
- G (headers and request handling): G3 and G4 do not apply (no idempotency key), G5 does not apply (no throttle); the other lines are yes through the shared runtime.
- I2: no, as expected: five path entries, one per distinct path.

**Security review checklist**

- C (verification gate): yes. Every write (rule `PUT`, `DELETE`, `materialise`, occurrence `PUT`) is behind `address.verified`; reads are not (`RecurringVerificationGateTest`).
- D (data at rest): amounts, descriptions, memos and counterparties are the same attributes the protected-attributes table already lists for a transaction (members only, not logged); `timezone` and the schedule members are not sensitive; `auto_confirm_from` is internal and never serialised.
- E (leakage): yes. No amount, text or record identifier is logged: the job writes one summary line of counts, a failed rule is logged by id and exception class only (`RecurringLogsTest`); a non-member always gets `404`, never `403`, so existence is not disclosed; refusals name no value outside the caller's wallets (SC-008).
- F (accountability): no new event and no audit entry; confirmations announce `TransactionPosted` through the transaction service as any recorded transaction does.
- G (retention): rule and occurrence tombstones follow the existing 90-day retention (`RecurringPurgeTest`); no new period.
- Automatic posting acts only with the rule author's current authority (T-02 in the wallet and in a transfer's destination at that moment); a refusal is surfaced, never retried.
