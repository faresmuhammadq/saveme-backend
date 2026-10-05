# Implementation Plan: Export & Data Deletion

**Branch**: `022-export-data-deletion` (trunk-based; work lands on `main`) | **Date**: 2026-10-06 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/022-export-data-deletion/spec.md`.

## Summary

Build what spec 007's deletion request promised, inside the Identity module (the constitution gives it "account deletion and export orchestration"):

- **Export** (R-1–R-8): `POST/GET /exports`, `GET /exports/{id}`; a queued `GenerateExport` job writes a ZIP (JSON per record kind + `transactions.csv`) to the private `local` disk through `ExportSection`s each module implements; a signed web route serves it (15-minute links in the app, until-expiry link in the "ready" mail), records `export_delivered` once; files live 24 hours; one active export per person (partial unique index → `409 EXPORT_IN_PROGRESS` with `current`); 5 per 24 h.
- **Successor choice** (R-9, R-10): `GET /account/deletion-request` (the plan) and `successors` on the request; one domain `SuccessorRule` (only member → chosen → oldest editor → oldest viewer) shared by preview and purge.
- **Purge** (R-12–R-16): hourly `identity:purge-accounts` → unique `PurgeAccount` job running ordered, idempotent, recorded steps: begin (`purging`, sessions ended) → erase name (`ProfileUpdated`) → revoke invitations → transfer or delete owned wallets → leave the rest → budgets → categories → subscriptions → exports → completion mail (sync, before key loss) → erase identity (husk `purged`, key destroyed, `deletion_completed`). Module steps are `AccountPurgeParticipant`s in each module. Ledger untouched; `created_by` is the departed marker.
- **Mail** (R-17), **operators** (R-18: "Deleting", stalled-purge notification via `AccountPurgeStalled`).
- **API** frozen now: `docs/openapi/paths/exports.yaml` + additive `identity.yaml`, indexed, examples, `info.version` v1.13, no new code.
- **Docs**: ADR-0024, constitution PATCH v1.3.13, glossary, security baseline, privacy commitments (en/ar), briefs README.

## Technical Context

**Language/Version**: PHP 8.5, Laravel 13.

**Primary Dependencies**: none new (P-16 Quality Gate 6; CONTRIBUTING 17). `ZipArchive` (ext-zip, present), `fputcsv`, signed routes, queues (database driver), scheduler, mail, `RateLimiter`, `Storage` (`local` disk), Pest/Spectator — all installed. XLSX would need a dependency: not used (open question 1).

**Storage**: PostgreSQL 16 — three new Identity tables (`data_exports`, `deletion_successor_choices`, `account_purges`), `users` and `device_sessions` CHECK/column changes, one Billing column; the `local` disk (`storage/app/private/exports`).

**Testing**: Pest.
- Unit: `SuccessorRule` (every order branch, ties, pending candidates), `TransactionsCsv` (BOM, quoting, CRLF, formula neutralising, minor units), `ExportScope` validation, `PurgeStep` order, `ExportStatus` transitions.
- Feature: request export (one active, concurrency, budget, grace period, wallet scope 404), generate export (content per section, no other person's data, tombstones, failure and retry, wallet unavailable), download route (signature, expiry, once-only delivery audit, purged account), expiry run (expire, stale, 30-day delete), deletion plan and choices (validation, replace, cancel drops), purge (owned wallets each branch, history unchanged, order of name erase vs tombstones, invitations before key, removes/keeps per table, `purging` blocks sign-in/cancel, resume after injected failure at every step, concurrent runs, audit/logging exclusions, mails), reminder, operator page/overview/stalled notification, Billing `endForAccountPurge`, Budgeting/Wallets/Sharing participants, category sweep.
- Contract: every operation of `exports.yaml` and the three `/account/deletion-request` operations, every documented status, via Spectator.
- Arch: existing gates (no new module; participants live in their modules' `Application`).

**Target Platform**: the Linux server (`dev` container).

**Project Type**: modular-monolith JSON API plus one signed web route.

**Performance Goals**: SC-001 — account export of 10,000 transactions across 10 wallets ready within 2 minutes; request/read endpoints p95 < 200 ms; purge of an account with 20 wallets < 2 minutes of job time.

**Constraints**: P-02 (Identity reaches other modules only through their Application services; modules implement Identity-owned interfaces), P-03 (minor units in CSV/JSON), P-04/P-05 (no ledger write), P-07 (tombstones via existing services; ADR-0017 lock order), P-08 (purge acts as the departing person through authorised services), P-09 rule 5 (no export gating), P-11 (events after commit), P-12 (UTC), P-13 (no codename in file names), P-14 (bilingual mail), P-15 (no content in logs; mail with address sent synchronously in the purge), P-18 rule 1 (capabilities are `/api/v1` JSON; the file link is a delivery detail).

**Scale/Scope**: 6 API operations (3 new paths' operations + 1 new GET + 1 changed POST + 1 unchanged DELETE), 1 signed web route, 3 scheduled commands, ~5 migrations (Identity ×4, Billing ×1), ~55 classes across Identity, Wallets, Budgeting, Sharing, Billing, Admin, ~30 test files, 3 new mailables + 1 changed.

## Constitution Check

*Constitution v1.3.12 (`.specify/memory/constitution.md`). GATE: must pass before Phase 0 research. Re-check after Phase 1 design, keeping both results.*

### Pre-design

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | "Wallet", "Transaction", "Wallet Member", "Category" throughout; new terms **Export** and **Successor** and User state `purging` go into the glossary (T003) before code; no "account" for a wallet (the person's *account* is the User, as the glossary already says) |
| P-02 Module boundaries | PASS | Orchestration in `app/Modules/Identity/Application/{Export,Deletion}`; Wallets, Budgeting, Sharing, Billing implement Identity's `ExportSection` / `AccountPurgeParticipant` in their own `Application/` and are tagged from their providers; Identity calls Wallets' `MembershipQueryService` (Application); no Infrastructure or Http crossing |
| P-03 Money | PASS | Export JSON uses `amount_minor` + `currency`; CSV `amount_minor` integer + `currency`, no decimal string; no money column added |
| P-04 Ledger integrity | PASS | No transaction or posting is written, rewritten or deleted by this feature (R-16); postings never exported |
| P-05 Derived balances | PASS | No balance written; the solo-wallet delete reuses spec 010's path |
| P-06 Identifiers | PASS | UUIDv7 server ids for exports; client-generated ids untouched; no ordering by UUID time |
| P-07 Sync and concurrency | PASS | Every syncable change goes through existing services (transfer, leave, wallet delete, invitation revoke, profile save) which bump `version`/`change_seq` and tombstone; budgets, unused categories and the profile are tombstoned first (rule 5) and removed in the same purge with the user horizon raised — ADR-0024 supersedes ADR-0008 rule 12 only for a purged user scope, which has no reader left; wallet-scope tombstones keep 90 days; categories in use are archived, not deleted |
| P-08 Authorization | PASS | Exports own-only (404 otherwise), wallet export needs an active membership; the purge calls authorised services as the departing person while their role is valid, `system` only for sessions/subscriptions/completion; no matrix duplicated |
| P-09 Entitlements | PASS | No capability check added (export is a privacy right, rule 5); ownership transfer reuses the demotion rule (rule 4); no plan code compared |
| P-10 Billing channels | PASS | No purchase surface; Play subscriptions are not cancelled server-side and the mails say so |
| P-11 Events | PASS | `ProfileUpdated` (existing) published after commit by `SaveProfile`; new `AccountPurgeStalled` carries ids only, consumed by a queued, idempotent Admin listener |
| P-12 Time | PASS | All instants UTC; CSV dates `YYYY-MM-DD`; grace computed from stored instants |
| P-13 Naming | PASS | Archive named `export-YYYY-MM-DD.zip`; no codename in classes, tables, routes |
| P-14 Localisation | PASS | Mail texts in `Identity/Http/Lang/{en,ar}/mail.php`; Western numerals; JSON never pre-formatted |
| P-15 Observability | PASS | Jobs log ids and step names only; completion mail sent synchronously so no address sits in `jobs`; queued mails use encrypted jobs as existing Identity mails do |
| P-16 Quality gates | PASS | Every spec §4 invariant names a failing test; PostgreSQL partial unique index |
| P-17 Scope guard | PASS | No scope-guard capability |
| P-18 Delivery surfaces | PASS | All capabilities under `/api/v1`; the signed file route is a delivery link of `getExport`'s answer (spec 020 receipt precedent); back-office change is a lang key and a state value in an existing component |

### Post-design re-check

Unchanged: all rows PASS.

- **CONTRIBUTING item 18**: **ADR-0024** "Account deletion keeps shared history: ownership transfers, the departed id is the marker" — extends ADR-0017 (membership tombstones on deletion through the normal paths) and ADR-0021 (erasure publishes `ProfileUpdated`); records the successor order, the `purging` state and the husk, and supersedes ADR-0008 rule 12 (90-day tombstone minimum) for the user scope of a purged account only. Constitution PATCH v1.3.13: P-07 and P-08 list ADR-0024; Identity row publishes `AccountPurgeStalled`.
- **Security baseline amendments** (T003): Leaving a shared wallet table (owned shared wallets transfer; marker = identifier resolving to nobody); Deletion (purging state, husk, order, completion mail, Play); Export (format, link lifetimes, 24 h, 30-day records, not gated); protected attributes (export archive row); retention (export archive 24 h, export record 30 days, ended subscriptions and husk 400 days after purge); route budgets (export request 5/24 h per person; export file 20/10 min per origin); device session end reason `account_deleted`.
- **Privacy commitments** (en/ar): "Leaving" — shared wallets you own pass to another member (your choice, else the longest-standing editor); export format; mapping rows.
- **No new error code**; `EXPORT_IN_PROGRESS` meaning reworded; `info.version` v1.13 (additive).

## Complexity Tracking

| Violation | Why needed | Simpler alternative rejected because |
|---|---|---|
| A web route outside `/api/v1` (`GET /exports/{id}/file`) | A browser or share sheet must open the file without bearer or client headers (C15: never keep the file in app storage) | A bearer API download forces the app to store the file; Laravel's built-in `local` serve route cannot record `export_delivered` |
| Purge acts as the departing person rather than `system` for wallet/membership/invitation/budget acts | Reuses the authorised services unchanged, so invariants, activity and audit are identical to doing it by hand | New system-actor bypass methods would duplicate authorization paths in four modules |

## Project Structure

### Documentation (this feature)

```text
specs/022-export-data-deletion/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/export-deletion-api.md
├── checklists/requirements.md
├── checklists/privacy.md           # /speckit-checklist
├── tasks.md                        # /speckit-tasks
└── summary.md                      # written by the parent after implementation
```

### Source Code (repository root)

```text
app/Modules/Identity/
├── Domain/
│   ├── Account/AccountState.php                 # + Purging
│   ├── Sessions/EndReason.php                   # + AccountDeleted
│   ├── Export/                                  # ExportScope, ExportStatus, ExportFailure, ExportRecord,
│   │                                            # TransactionsCsv (row building, neutralising)
│   ├── Deletion/                                # SuccessorRule, Successor, SuccessorReason, SuccessorCandidate,
│   │                                            # PurgeStep, PurgeCounts
│   ├── Events/AccountPurgeStalled.php
│   ├── Ports/                                   # ExportRepository, ExportStore, SuccessorChoiceRepository,
│   │                                            # AccountPurgeRepository; UserRepository (+ markPurging, toHusk,
│   │                                            # duePurges, dueReminders, deleteOldHusks)
│   └── Refusals/                                # ExportInProgress(current), ExportNotFound
├── Application/
│   ├── Export/                                  # RequestExport, ExportQuery (list, get, signed link),
│   │                                            # GenerateExport (builder), ExportSection, ExportWriter,
│   │                                            # IdentitySections (account, profile, devices), ExpireExports,
│   │                                            # ExportDelivery (first-download audit)
│   ├── Deletion/                                # RequestDeletion (+ successors), CancelDeletion (+ drop),
│   │                                            # DeletionPlanner, PurgeAccount, AccountPurgeParticipant,
│   │                                            # PurgeContext, StartDuePurges, SendDeletionReminders
│   ├── Jobs/                                    # GenerateExportJob, PurgeAccountJob
│   └── Mail/                                    # ExportReadyMail, DeletionReminderMail, AccountDeletedMail
├── Infrastructure/
│   ├── Models/                                  # DataExport, DeletionSuccessorChoice, AccountPurge
│   ├── Repositories/                            # Eloquent*, LocalDiskExportStore
│   └── Database/Migrations/
│       ├── 2026_10_11_000001_create_data_exports_table.php
│       ├── 2026_10_11_000002_create_deletion_successor_choices_table.php
│       ├── 2026_10_11_000003_create_account_purges_table.php
│       └── 2026_10_11_000004_add_purging_and_husk_to_users.php   # + device_sessions end_reason
└── Http/
    ├── Requests/                                # RequestExportRequest, DeletionRequestRequest (+ successors)
    ├── Controllers/                             # ExportsController, ExportFileController,
    │                                            # DeletionRequestController (+ show)
    ├── Resources/                               # ExportResource, DeletionPlanResource
    ├── Console/                                 # PurgeAccounts, ExpireExports, SendDeletionReminders
    ├── Lang/{en,ar}/mail.php                    # new texts; deletion-scheduled text amended
    ├── Views/mail/                              # export-ready, deletion-reminder, account-deleted
    └── routes.php                               # + API operations, + signed web route
app/Modules/Wallets/Application/
├── Export/WalletSections.php                    # wallets, transactions, rules, occurrences, categories, memberships
├── Departure/                                   # ResolveOwnedWallets, LeaveRemainingWallets, ReleaseCategories
└── Categories/CategoryService.php               # + releaseForDepartedUser; Sync purge sweep
app/Modules/Budgeting/Application/{Export/BudgetSection.php, Departure/DeleteBudgets.php}
app/Modules/Sharing/Application/{Export/InvitationSection.php, Departure/RevokeSentInvitations.php}
app/Modules/Billing/Application/{Export/SubscriptionSection.php, Departure/EndSubscriptions.php}
app/Modules/Billing/Infrastructure/Database/Migrations/2026_10_11_000005_add_ended_by_account_purge_to_subscriptions.php
app/Modules/Admin/Application/Listeners/NotifyOperatorsOfStalledPurge.php; account page lang ("Deleting")
routes/console.php (schedule), config/identity.php (export_hours 24, export_link_minutes 15, …), .env.example

tests/Unit/Identity/{Export,Deletion}/, tests/Feature/Identity/{Export,Deletion}/,
tests/Feature/{Wallets,Budgeting,Sharing,Billing}/Departure/, tests/Feature/Admin/AccountDeletionStateTest.php,
tests/Contract/Identity/{ExportsTest,DeletionRequestTest}.php
```

**Structure Decision**: Identity owns orchestration, export records and the purge state machine; every module owns the export and purge of its own records behind an Identity-defined interface (dependency inversion, as `WalletDeletionParticipant` does for wallet deletion).

## Design notes

1. **Export request flow**: `auth:api` → `RequestExportRequest` (422) → `RequestExport::handle(userId, scope)`: wallet scope → `MembershipQueryService::roleOf` null → `ExportNotFound` (404); budget check `RateLimiter::tooManyAttempts('identity-export:'.$userId, 5)` → 429; transaction: insert `queued` (unique violation → `ExportInProgress` with the active row → 409 + `current`), audit `export_requested`; `RateLimiter::hit(…, 86400)` after commit; `GenerateExportJob::dispatch($id)->afterCommit()`.
2. **Generation**: `GenerateExport::run(exportId)` — sections iterate per wallet in ascending id; each section writes through `ExportWriter` (temp files per JSON array, a CSV writer for transactions), `ZipArchive` assembles `.part`, rename, mark `ready`. A thrown `WalletUnavailable` ends `failed/wallet_unavailable` (`$this->fail()` without retry).
3. **Link**: `ExportQuery::present(record)` adds `download_url` only when `ready`, minted per read.
4. **Deletion plan**: `DeletionPlanner::plan(userId)` → `MembershipQueryService::ownedLiveWalletIds` → per wallet `activeMembersOf` → `Accounts::statesOf(candidateIds)` → stored choice → `SuccessorRule::resolve`. Used by `GET`, by the `POST` answer and by `ResolveOwnedWallets`.
5. **Purge**: R-12. Lock order per wallet unit of work is the services' own (wallet row, then user counters ascending). `PurgeAccount` never holds the users row lock while calling a participant.
6. **Sign-in**: `SignIn` treats `purging`/`purged` as unknown (uniform `INVALID_CREDENTIALS`).
7. **Scheduler** (`routes/console.php`): `identity:purge-accounts` hourly `withoutOverlapping`; `identity:expire-exports` every 15 minutes; `identity:send-deletion-reminders` daily.
8. **Logging**: job start/finish lines with export id / user id and step name only; nothing else.

## Assumed defaults (owner away)

| # | Decision | Why |
|---|---|---|
| A-1 | ZIP of JSON + `transactions.csv`; no XLSX | Baseline Export rule 2; XLSX needs a dependency |
| A-2 | CSV: UTF-8 BOM, comma, CRLF, minor units + currency, formula cells prefixed `'` | Arabic in spreadsheets; P-03; CSV injection |
| A-3 | Account export = own records + every wallet with an active membership, all its entries; others as `author: other_member` | Baseline Export rule 3 ("what they can already see"); no other person's identity |
| A-4 | Wallet-scope export for any active member role | C15 asks; reads are open to every member |
| A-5 | Export not gated by plan or verification; allowed in grace period | P-09 rule 5; baseline C2 and Export rule 4 |
| A-6 | Async job; 15-min in-app link; mail link until expiry; file 24 h; record 30 days | Brief 24 h; short links in app; owner asked for an email link |
| A-7 | One active export (409 + `current`); 5 accepted per 24 h; file route 20/10 min per origin; 60-min stale → failed | Brief; bounded work; never stuck |
| A-8 | Archive not encrypted at rest; baseline row says so | 24-hour file behind a signature; honest claim over implied one |
| A-9 | No `Idempotency-Key` on exports or deletion | Reserved for three money operations; both idempotent by state / `current` |
| A-10 | Successor: only member (any role) → chosen → oldest editor → oldest viewer; delete only with no other member | Owner decision; never destroy shared history |
| A-11 | Choices sent as `successors` on the request, replaceable on repeat, dropped on cancel; `GET` preview | C15 owner-choice step; additive to KMP 002 |
| A-12 | Transactions/rules keep `created_by`; the id resolving to nobody is the marker | Owner: "stay with their reference"; glossary immutability; no feed flood |
| A-13 | Departed author's recurring rules stay live; auto-confirm refused by spec 014's rule | Least mutation; never act on a departed person's authority |
| A-14 | Name erased via profile save + `ProfileUpdated` before any tombstone | B14a inheritance |
| A-15 | Invitations revoked first, while role is valid and before key loss | B9 inheritance |
| A-16 | Purge as ordered, idempotent, recorded steps; `purging` state blocks cancel and sign-in | Brief: idempotent, resumable |
| A-17 | Users row kept as a `purged` husk (no attributes), deleted at 400 days | Spec 007 states, spec 021 "Deleted" page; audit horizon |
| A-18 | Categories in use archived and kept until unreferenced; others deleted | FK from ledger; partners keep labels |
| A-19 | Internal subscriptions ended at purge; Play not cancellable, mails say so; rows kept 400 days | Brief; B10 not built; dispute window |
| A-20 | Reminder 3 days before; completion mail sent synchronously before key destruction | Irreversible act; no address left in the queue |
| A-21 | Operators: "Deleting" state, pending count, stalled-purge notification after 24 h | Counts and states only (P-18 rule 8) |
| A-22 | Purge acts as the departing person through existing services | Same invariants, activity and audit as by hand |

## Implementation notes

Written during implementation (owner away, delegated authority). Each item is a choice the spec, plan and research left open or a place where the build differs in detail; none changes the frozen contract (`docs/openapi/paths/exports.yaml`, the `/account/deletion-request` entry of `identity.yaml`, the schemas, responses and error catalogue). One contract-file edit was made as planned in T032: `successors` added to `DeletionSchedule.required` in `identity.yaml`, once the resource emitted it.

**Needs the owner**

- **ADR-0024 needs owner ratification.** It was written under delegated authority with Status Accepted and a "pending owner ratification" note. It supersedes ADR-0008 rule 12 for a purged user scope only (immediate removal of the tombstones of a purged account's user scope; wallet-scope tombstones keep 90 days). Constitution v1.3.13 lists it under P-07 and P-08.

**Defaults chosen**

- **Export read order.** Records inside a JSON file are written in change order (position on the feed), not sorted by id: sections stream page by page, so memory stays flat. Order is deterministic for an unchanged account. Wallets are visited in ascending id.
- **Transfers in the archive.** A transfer between two exported wallets appears once, with its source wallet; with the destination's only when the person cannot read the source (or it is outside a wallet export).
- **Entry JSON carries both `category_id` and `category`** (the label the person sees, from the wallet's category references, the platform set in the profile language, or their own list). A label shown for another member's entry is the one the person already sees; their unused categories never appear.
- **Seams.** `ExportSection::write(ExportTarget, ExportWriter)` takes a target value (user, scope, wallet id) instead of the bare scope. `PurgeContext` holds the planner, not a precomputed plan: `successorFor(walletId)` decides at act time, so a member who left between listing and acting is not chosen (the step also retries a stale transfer up to four times).
- **Tie-break for oldest editor/viewer** is joined instant, then user id (research R-9, task T027), not membership id (FR-031 says "member id"); both are deterministic.
- **`begin` is one transaction** (`markPurging`, progress row, sessions ended, web sessions dropped), so it is never recorded through `record()`; every later step is.
- **One run per account.** `AccountPurgeRepository::tryClaim/release` take a PostgreSQL advisory lock for the run, so a second `PurgeAccount::run` leaves the work to the first even if the unique job lock fails (FR-053). The unique job also carries `uniqueFor`.
- **Invitations sent from a wallet the person no longer owns.** Only an owner can revoke (S-06), so `InvitationService::revokeForDepartingInviter` revokes in the person's name while they own the wallet and as `system` (no actor) otherwise. Wallets deleted meanwhile are skipped. New repository methods `pendingInvitedBy` and `sentBy`.
- **Profile erasure** tombstones the row (`deleted_at`, version + 1, `change_seq` from the user counter, name nulled), deletes it and raises the user scope's horizon past that stamp, inside the `erase_identity` transaction.
- **Categories.** A category no record uses is tombstoned and its row removed at once when nothing at all (not even a tombstoned category reference) points at it. Where tombstoned references still do, the category stays tombstoned until the daily purge removes it (at most 90 days). Categories the person had already deleted follow the normal 90-day rule. Used categories are archived; the daily purge gains a `departed_categories` sweep (`Accounts::departedAmong`), which deletes an archived category once its owner is purged or gone and nothing refers to it.
- **Budgets and subscriptions.** `BudgetService::removeAllForDepartedUser`, `SubscriptionService::endForAccountPurge` (new class), `PurgeEndedSubscriptions` (run by `billing:purge-claims`) as in tasks.
- **Operator notification kind.** `operator_notifications.kind` is CHECK-limited; migration `2026_10_11_000006_allow_account_purge_stalled_notification` (Admin) adds `account_purge_stalled`. `OperatorNotificationMail` takes the kind.
- **Overview.** `pending_deletion` counts `pending_deletion` only; `purging` and `purged` leave every other figure out. A purging account's page shows its id and "Deleting" only, like a husk.
- **Export presentation past expiry.** A `ready` export read after `expires_at` but before the sweep is presented as `expired` with no link.
- **Order of refusals on `POST /exports`:** not found (wallet), then `409` (uncounted), then `429`.
- **Deletion request.** `successors` is validated before the password, so a bad choice never counts as a failed attempt.
- **File route.** The signature is checked in the controller, not with `signed` middleware, so a bad or expired signature is `404` like every other refusal. Filename date is the day of the download.
- **Queue.** Job timeouts: export 900 s, purge 1800 s. `config/queue.php` `retry_after` for the database queue is raised from 90 to 1900 (`DB_QUEUE_RETRY_AFTER` still overrides) so a long job is never reserved twice; no dedicated queue. Workers honour the per-job timeout; they should not be started with a smaller `--timeout`.
- **Reminder window.** An account is reminded when its purge is more than 0 and at most 3 days away.
- **`deletion_completed` detail** carries the three counts of FR-055; the progress row also holds the other five for diagnosis.
- **Logs.** Export and purge lines carry export id, user id and step name only (a user id is not a record identifier in the baseline's sense; no wallet, category or entry id is logged).
- **Existing tests.** Tests that set `state = purged` directly now also set `purged_at` (the new CHECK); the console output of `billing:purge-claims` gained a second line.

**Gates and measurements**

- Performance (T056, `EXPORT_PERF=1`): 10,000 transactions over 10 wallets exported in 1.4 s with about 4 MB of peak memory growth (SC-001 asks for under 2 minutes).
- `tests/Feature/Admin/AuditLogTest` "records one audit_read with…" is date-sensitive around 00:00 UTC (it builds its expected range from a Cairo date and a UTC clock). It fails only in that hour and is unrelated to this spec.

**Apidog (T059):** bundle imported into project 1332822, module 1363204 (a second identical import changes nothing); project doc "Export & deletion" created (id 2467355) from the contract's client notes; "Error catalogue" doc (2444506) reworded for `EXPORT_IN_PROGRESS`. Dev stack walk (throwaway users, migrations applied to dev): export ready, signed download 200 `application/zip`, bad signature 404, `409` with a second request, deletion plan and request, purge of the throwaway account (wallet passed to the partner, husk, key gone, old credential 401).
