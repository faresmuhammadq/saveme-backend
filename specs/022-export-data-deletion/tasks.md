---

description: "Task list for Export & Data Deletion (B15)"
---

# Tasks: Export & Data Deletion

**Input**: Design documents from `/specs/022-export-data-deletion/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/export-deletion-api.md](./contracts/export-deletion-api.md), [quickstart.md](./quickstart.md), [checklists/privacy.md](./checklists/privacy.md), the frozen contract `docs/openapi/paths/exports.yaml` and the `/account/deletion-request` entry of `docs/openapi/paths/identity.yaml` (written and indexed; `info.version` v1.13).

**Tests**: included. The spec's Invariants table (§4) names every test file (constitution P-16 rule 3). Write each story's tests first and watch them fail.

**Organization**: grouped by user story, in delivery order (P1 first):

- US1: take a copy of everything (P1)
- US3: choose who keeps my shared wallets (P1)
- US4: the account is deleted when the grace period ends (P1)
- US5: a purge that stops halfway finishes correctly (P1)
- US2: export one wallet (P2)
- US6: reminders and honest mail (P2)
- US7: operators see that an account is leaving (P3)

**Conventions for every task** (as in specs 016–021):

- Run through the container: `dev exec -- vendor/bin/pest <path> --compact`, `dev <artisan command>`.
- P-02: orchestration lives in `app/Modules/Identity/{Domain,Application,Infrastructure,Http}`. Other modules implement Identity's `Application\Export\ExportSection` and `Application\Deletion\AccountPurgeParticipant` in their own `Application/Export/` and `Application/Departure/` and tag them from their service providers (`identity.export-sections`, `identity.purge-participants`). Identity reads other modules only through their Application services (`MembershipQueryService`, `WalletService`, …). Identity Domain imports only `Shared`.
- Refusals extend `Shared\Domain\Errors\Refusal` with an existing catalogue code: `ExportInProgress` → 409 `EXPORT_IN_PROGRESS` with `current`; `ExportNotFound` / wallet not readable → 404 `NOT_FOUND`. No new error code.
- **Lock order** (ADR-0017 rule 5): the purge never holds the `users` row lock while a participant runs; each wallet act is its own unit of work through the existing service, which takes wallet then user locks ascending.
- Never log an address, display name, wallet or category name, amount, note, link or archive content (P-15 rule 4, FR-056); ids and step names only. Instants with microseconds; empty `meta` is `(object) []`.
- Contract tests use `assertValidRequest()` and `assertValidResponse($status)` covering every documented status.
- Run `dev exec -- vendor/bin/pint --dirty --format agent` after PHP edits.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on unfinished tasks)
- **[Story]**: US1–US7 from spec.md

---

## Phase 1: Setup (docs, decision record, config)

- [X] T001 [P] Write `docs/adr/0024-account-deletion-keeps-shared-history.md` (template `docs/adr/template.md`, Status Accepted, Principles P-07, P-08, P-11; extends ADR-0017 and ADR-0021): rules — owned wallets with another active member transfer (only member any role → chosen → oldest editor → oldest viewer, earliest membership = current membership record's creation, pending candidates skipped unless alone), only solo wallets are deleted; ledger rows are never rewritten and `created_by` keeps the departed id as the marker; the display name is erased through the profile path publishing `ProfileUpdated` before any membership tombstone; the `purging` state and the husk; the purge acts through existing services. the user scope of a purged account: its syncable records are tombstoned then removed at once with the horizon raised — **supersedes ADR-0008 rule 12 for that case only** (no reader remains); wallet-scope tombstones keep 90 days. Alternatives (oldest editor only, delete-if-no-editor, sentinel `created_by`, hard-delete user row, system-actor bypass, 90-day user-scope tombstones). Mark ADR-0008's status line "rule 12 partly superseded by ADR-0024" as ADR-0016 did for rule 7. Add it to `docs/adr/README.md` index.
- [X] T002 [P] Constitution PATCH v1.3.12 → v1.3.13 in `.specify/memory/constitution.md`: P-07 and P-08 `**ADRs**` lines list ADR-0024 (extends ADR-0017 and ADR-0021; supersedes ADR-0008 rule 12 for purged user scopes only); the Identity row's Publishes gains `AccountPurgeStalled`; Sync Impact Report entry; footer Last Amended 2026-10-06.
- [X] T003 [P] Glossary `docs/glossary.md` (before code, CONTRIBUTING 13): User states `active` ⇄ `pending_deletion` → `purging` → `purged` (a husk with no identity attribute, deleted after 400 days); new entries **Export** (Identity, aggregate root, persisted, Sync: no — read through `GET /exports`, states `queued`→`running`→`ready`→`expired` | `failed`, notes: ZIP of JSON + CSV, 24 h, one active) and **Successor** (Identity, value, derived; reasons; choices stored while pending); Transaction note: `created_by` outlives its author as an identifier that resolves to nobody ("Former member"); entity index rows.
- [X] T004 [P] Security baseline `docs/security-baseline.md` amendments (FR-020, FR-034, FR-071): Leaving a shared wallet table ("Wallets they own" → "pass to another member: the only one, the one they chose, else the longest-standing editor, else viewer; removed only if nobody else belongs"; "Their name on those transactions" → "comes off; the entries keep an identifier that no longer resolves to any person"); Deletion section (the `purging` state, step order, husk, key destroyed after invitations revoked and the completion mail, Google Play not cancellable); Export section (ZIP of JSON + CSV, not gated, 15-minute in-app link, mail link until expiry, file 24 h, records 30 days); protected-attributes row "Export archive"; retention rows (export archive 24 h after ready; export record 30 days; ended subscriptions and the account husk 400 days after purge; a purged account's user-scope records — tombstoned and removed at the purge); route budgets (export request 5 per 24 h per person; export file link 20 per 10 min per origin); device session end reason `account_deleted`; appendix checklist lines if a rule is new.
- [X] T005 [P] Privacy commitments `docs/privacy-commitments.md`, **English and Arabic**: "Leaving" — the export is a ZIP with a spreadsheet (CSV) of transactions; "If you own a shared wallet, it passes to another member — you can choose who; otherwise the member who has been an editor longest; it is never deleted while someone else is in it"; "Deleting your account does not cancel a Google Play subscription; cancel it in Google Play"; "How long we keep things": the export link and file last 24 hours; mapping table rows (spec 022 FR numbers) in the same edit.
- [X] T006 [P] `specs/briefs/README.md` row 16 note "Specified as spec 022"; `config/identity.php` keys `export_hours` (24), `export_link_minutes` (15), `export_daily_limit` (5), `export_stale_minutes` (60), `export_record_days` (30), `deletion_reminder_days` (3), `purge_stall_hours` (24), `husk_days` (400), `export_file_budget` (20 per 600 s); `.env.example` only if any is env-driven.

---

## Phase 2: Foundational (schema, domain values, seams — blocks every story)

- [X] T007 Migration `app/Modules/Identity/Infrastructure/Database/Migrations/2026_10_11_000004_add_purging_and_husk_to_users.php`: `users.state` CHECK `active|pending_deletion|purging|purged`; pending CHECK `(state IN ('pending_deletion','purging')) = (deletion_requested_at IS NOT NULL AND deletion_scheduled_for IS NOT NULL)`; add `deletion_reminded_at` and `purged_at` timestamptz null with CHECK `(state = 'purged') = (purged_at IS NOT NULL)`; make `email_ciphertext`, `email_lookup`, `password_hash` nullable with CHECK `state = 'purged' OR (email_ciphertext IS NOT NULL AND email_lookup IS NOT NULL)`; `device_sessions.end_reason` CHECK gains `account_deleted`. Reversible `down()`.
- [X] T008 [P] Migrations `2026_10_11_000001_create_data_exports_table.php` (columns, CHECKs and indexes exactly as data-model.md: `scope` `account|wallet`, `CHECK ((scope = 'wallet') = (wallet_id IS NOT NULL))`, `status` `queued|running|ready|failed|expired`, `failure_code` `generation_failed|wallet_unavailable|timed_out` non-null iff `failed`, partial UNIQUE `data_exports_one_active (user_id) WHERE status IN ('queued','running')`, list index (`user_id`, `requested_at` DESC, `id` DESC), (`status`, `expires_at`)), `2026_10_11_000002_create_deletion_successor_choices_table.php` (PK (`user_id`,`wallet_id`), FK user cascade), `2026_10_11_000003_create_account_purges_table.php` (`last_step` CHECK over the eleven `PurgeStep` values, `counts` jsonb default `{}`, `attempts` int default 0) in Identity; `app/Modules/Billing/Infrastructure/Database/Migrations/2026_10_11_000005_add_ended_by_account_purge_to_subscriptions.php`.
- [X] T009 [P] Domain values in `app/Modules/Identity/Domain/`: `Account/AccountState` + `Purging`; `Sessions/EndReason` + `AccountDeleted`; `Export/{ExportScope,ExportStatus,ExportFailure,ExportRecord}`; `Deletion/{PurgeStep (ordered enum: begin, erase_name, revoke_invitations, resolve_owned_wallets, leave_wallets, budgets, categories, subscriptions, exports, completion_mail, erase_identity), PurgeCounts, Successor, SuccessorReason, SuccessorCandidate}`; `Events/AccountPurgeStalled` (`ShouldDispatchAfterCommit`, event id, occurred_at, user id); `Refusals/{ExportInProgress (carries the active ExportRecord), ExportNotFound}`.
- [X] T010 [P] Seams in `app/Modules/Identity/Application/`: `Export/ExportSection` (`write(ExportScope, ExportWriter): void`), `Export/ExportWriter` (JSON array streams per file name, `transactions()` CSV writer, `count()` per file), `Deletion/AccountPurgeParticipant` (`step(): PurgeStep`, `purge(string $userId, PurgeContext): PurgeCounts`), `Deletion/PurgeContext` (the departing user id, the `DeletionPlanner` result, now); tags `identity.export-sections` and `identity.purge-participants` resolved in `IdentityServiceProvider`.
- [X] T011 Ports and Eloquent: `Domain/Ports/{ExportRepository, ExportStore, SuccessorChoiceRepository, AccountPurgeRepository}` and `UserRepository` additions (`markPurging(userId, now): bool` conditional on `pending_deletion` and due, `toHusk(userId, now)`, `duePurges(now)`, `dueReminders(now, days)`, `markReminded(userId, now): bool`, `deleteHusksBefore(instant): int`, `statesOf(array ids): array`); `Infrastructure/Models/{DataExport, DeletionSuccessorChoice, AccountPurge}`; `Infrastructure/Repositories/{EloquentExportRepository, LocalDiskExportStore (local disk, `exports/<user_id>/<id>.zip`, write `.part` then rename, delete), EloquentSuccessorChoiceRepository, EloquentAccountPurgeRepository}`; bindings in `IdentityServiceProvider`. Factories for `DataExport` in `database/factories` or the module's convention.
- [X] T012 [P] `tests/Feature/Identity/Deletion/SchemaTest.php`: the new CHECKs reject a `purged` row with an address, a `pending_deletion` row without dates, a second `queued` export for one user (unique violation), a `wallet` export without `wallet_id`.

**Checkpoint**: schema migrated, seams resolvable; stories can start.

---

## Phase 3: User Story 1 — Take a copy of everything (P1) 🎯 MVP

**Goal**: request an account export, produce the ZIP asynchronously, deliver it by a signed link and a mail.

**Independent Test**: spec US1 Independent Test.

### Tests

- [X] T013 [P] [US1] `tests/Unit/Identity/Export/TransactionsCsvTest.php` (FR-005): BOM first, CRLF, header row exactly `date,wallet,wallet_id,kind,amount_minor,currency,category,counter_wallet,description,memo,counterparty,cleared,author,transaction_id,created_at,updated_at`; quoting of commas, quotes and line breaks; Arabic round-trips; a description starting with `=`, `+`, `-`, `@`, tab or CR is prefixed `'`, numeric columns never; amounts integers.
- [X] T014 [P] [US1] `tests/Feature/Identity/Export/RequestExportTest.php` (FR-008–FR-010, FR-017–FR-019, FR-021): `202 queued`, one `export_requested` with detail `{scope}`, job dispatched after commit; second while queued → `ExportInProgress` with the same export, nothing audited or counted; concurrent requests → exactly one row; sixth accepted in 24 h → 429 with Retry-After; a failed export does not block; request during `pending_deletion` served and `deletion_scheduled_for` unchanged; unverified account served; no entitlement consulted (a plan without `export.enabled` still served).
- [X] T015 [P] [US1] `tests/Feature/Identity/Export/GenerateExportTest.php` (FR-011, FR-012): `queued → running → ready` with `size_bytes`, `completed_at`, `expires_at = completed + 24h`; file at `exports/<user>/<id>.zip` on the local disk, no `.part` left; `ExportReadyMail` queued once in the profile language with a link valid until `expires_at` (encrypted job); a section throwing → retried, after 3 attempts `failed/generation_failed`, partial file removed, no mail; a re-run on `ready` does nothing; a run whose row was deleted or whose account entered `purging` meanwhile leaves no file; no log record contains content (log spy).
- [X] T016 [P] [US1] `tests/Feature/Identity/Export/ExportContentTest.php` (FR-001–FR-004, FR-006, SC-002): person D in wallet W (owner, shared with P and Q who wrote entries) and wallet R (viewer) and formerly in L (left): archive has `manifest.json` (format 1, counts match), every file of FR-002; W and R included with all entries, L absent; other members' entries `author: other_member`; P's and Q's ids, names, addresses and own categories appear nowhere (byte search); tombstones as `{id, deleted_at, wallet_id}`; no `change_seq`, `member_change_seq`, `purged_change_seq`, `auto_confirm_from`, postings, credential or device installation id; invitations without address or token; no activity or audit file; empty account → empty arrays and a header-only CSV.
- [X] T017 [P] [US1] `tests/Feature/Identity/Export/DownloadExportTest.php` (FR-013, FR-014, FR-018): app read of `ready` carries `download_url` expiring ≤ 15 min and ≤ `expires_at`; following it without any header → 200 `application/zip`, attachment `export-YYYY-MM-DD.zip`, `Cache-Control: no-store`; first download writes one `export_delivered` `{export_id}`, second none; bad or expired signature, unknown id, `expired` export, account `purging` or purged → 404, nothing recorded; 21st request in 10 minutes from one origin → 429.
- [X] T018 [P] [US1] `tests/Feature/Identity/Export/ExpireExportsTest.php` (FR-016, FR-019): `ready` past `expires_at` → file deleted, `expired`, `file_path` null; `queued`/`running` older than 60 min → `failed/timed_out`; rows older than 30 days deleted with files; orphan files with no row deleted; idempotent.
- [X] T019 [P] [US1] `tests/Contract/Identity/ExportsTest.php`: `requestExport` 202, 400 (headers), 401, 404 (wallet), 406, 409 (`current` validates as Export), 415, 422 (`/scope`, `/wallet_id`), 426, 429; `listExports` 200 (newest first, cursor), 400 (bad cursor), 401; `getExport` 200 for queued/ready/failed, 401, 404 (another's), 422 (`INVALID_ID`).

### Implementation

- [X] T020 [US1] `app/Modules/Identity/Domain/Export/TransactionsCsv.php` (row building and neutralising, research R-2) and `Application/Export/ZipExportWriter.php` (temp files, `ZipArchive::CM_DEFLATE`, sorted by id, `JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT`). Make T013 pass.
- [X] T021 [US1] Identity's own sections `Application/Export/IdentitySections.php` (`account.json`, `profile.json` with `budget_start_days`, `devices.json` — name, platform, app version, state, signed-in/last-used/ended instants only).
- [X] T022 [P] [US1] Wallets section `app/Modules/Wallets/Application/Export/WalletSections.php` (tagged in `WalletsServiceProvider`): wallets with the person's role and member count, transactions (JSON + CSV rows, category label from own categories or Category References, counter wallet name only when a member), recurring rules (template, schedule, `timezone`, author marker; no `auto_confirm_from`), occurrences (key, status, effective values, `transaction_id`), own categories, own memberships; reads through Wallets' query/repository layer as the person; ascending wallet id.
- [X] T023 [P] [US1] Budgeting `app/Modules/Budgeting/Application/Export/BudgetSection.php` (category, currency, limit, period type, `archived_at`, tombstones), Sharing `app/Modules/Sharing/Application/Export/InvitationSection.php` (sent invitations: wallet id, channel, role, state, instants; never address or token), Billing `app/Modules/Billing/Application/Export/SubscriptionSection.php` (plan code and name, provider, status, period instants); each tagged in its provider.
- [X] T024 [US1] `Application/Export/RequestExport.php`, `GenerateExport.php`, `ExportQuery.php` (list/get, link minting with `URL::temporarySignedRoute('identity.exports.file', min(now+15m, expires_at))`), `ExportDelivery.php` (conditional `delivered_at` update → audit `export_delivered`), `ExpireExports.php`; job `Application/Jobs/GenerateExportJob.php` (`ShouldQueue`, `ShouldBeUnique` by export id, `$tries = 3`, `$timeout = 900`, backoff `[30, 120]`, `failed()` → `generation_failed`); mailable `Application/Mail/ExportReadyMail.php` + encrypted send job; audit detail classes for `export_requested` / `export_delivered` in `Application/Audit/Details/`. Rate limiter `identity-export:<user>` (5/86400 s, hit after commit). Make T014, T015, T016, T018 pass.
- [X] T025 [US1] HTTP: `Http/Requests/RequestExportRequest.php` (ApiRequest; `scope` required in `account|wallet`; `wallet_id` required iff wallet, uuid → `INVALID_ID`, refused with account → `UNKNOWN_FIELD` at `/wallet_id`), `Http/Controllers/ExportsController.php` (store 202, index, show), `Http/Resources/ExportResource.php` (all members of the `Export` schema, nulls explicit), `Http/Controllers/ExportFileController.php` + `RateLimiter::for('identity-export-file')` (20 per 600 s per IP); routes in `Http/routes.php`: API `POST/GET exports`, `GET exports/{id}` under `auth:api`; web `GET /exports/{export}/file` named `identity.exports.file` with `signed` and the throttle, no session/CSRF middleware. Make T017, T019 pass.
- [X] T026 [US1] Command `Http/Console/ExpireExports.php` (`identity:expire-exports`), schedule every 15 minutes `withoutOverlapping()` in `routes/console.php`; mail texts `ExportReady` in `Identity/Http/Lang/{en,ar}/mail.php` and view `Http/Views/mail/export-ready.blade.php` (no wallet name, size and expiry only).

**Checkpoint**: account export end to end (quickstart §1).

---

## Phase 4: User Story 3 — Choose who keeps my shared wallets (P1)

**Goal**: preview and store successor choices; one rule for preview and purge.

**Independent Test**: spec US3 Independent Test.

### Tests

- [X] T027 [P] [US3] `tests/Unit/Identity/Deletion/SuccessorRuleTest.php` (FR-031): no candidates → null; one candidate viewer → `only_member`; chosen present → `chosen`; chosen absent → oldest editor by joined instant, tie by user id; pending editors skipped unless all pending; no editor → oldest viewer; one candidate who is pending → still `only_member`.
- [X] T028 [P] [US3] `tests/Feature/Identity/Deletion/DeletionPlanTest.php` (FR-033, FR-035–FR-038): plan for active and pending accounts (solo wallets absent, archived shared wallets present, `choice_expected` true only with >1 other member); request with valid `successors` stores and answers `chosen`; invalid entries (not owned, solo, deleted, self, non-member, duplicate wallet, 101 entries, malformed ids) → validation error with pointer and state unchanged; repeat with successors replaces, without leaves, date unchanged; cancel (explicit and sign-in) drops choices; preview equals what `ResolveOwnedWallets` would do (shared planner).
- [X] T029 [P] [US3] `tests/Contract/Identity/DeletionRequestTest.php`: `getAccountDeletionPlan` 200 (active and pending), 401; `requestAccountDeletion` 200 with `successors` (and spec 007's password-only body still valid), 401 `INVALID_CREDENTIALS`, 422 at `/successors/0/member_user_id`, 423; `cancelAccountDeletion` 204.

### Implementation

- [X] T030 [US3] `Domain/Deletion/SuccessorRule.php` (research R-9). Make T027 pass.
- [X] T031 [US3] `Application/Deletion/DeletionPlanner.php` (`plan(userId)`, `validateChoices(userId, entries)`; candidates from `MembershipQueryService::ownedLiveWalletIds` and `activeMembersOf` (archived included, joined instant = membership `created_at`), account states via `UserRepository::statesOf`); `RequestDeletion::handle(userId, password, ?array successors)` stores/replaces choices in its transaction; `CancelDeletion` deletes them. If `MembershipQueryService` lacks joined instants or archived wallets, add them there (Wallets Application) with a feature test in `tests/Feature/Wallets/MembershipQueryServiceTest.php`.
- [X] T032 [US3] HTTP: `Http/Requests/DeletionRequestRequest.php` (replaces `PasswordConfirmationRequest` on this route; `password`, optional `successors` max 100 of `{wallet_id, member_user_id}` uuid, pointers `/successors/{n}/…`, codes `INVALID_ENUM_VALUE`, `INVALID_ID`, `OUT_OF_RANGE`), `DeletionRequestController::show` + route `GET account/deletion-request`, `Http/Resources/DeletionPlanResource.php`; `DeletionSchedule` answer gains `successors`, then add `successors` to `DeletionSchedule.required` in `docs/openapi/paths/identity.yaml` in this same task (it is optional until the resource emits it). Make T028, T029 pass; existing `tests/Contract/Identity` deletion tests stay green.

**Checkpoint**: C15 can build the owner-choice step against the live API.

---

## Phase 5: User Story 4 — The account is deleted when the grace period ends (P1)

**Goal**: the ordered purge with every participant.

**Independent Test**: spec US4 Independent Test.

### Tests

- [X] T033 [P] [US4] `tests/Feature/Identity/Deletion/PurgeOwnedWalletsTest.php` (FR-030–FR-032, FR-045): one other member viewer → owner directly; several with valid choice → chosen; choice left → oldest editor; no editor → oldest viewer; pending candidate skipped; solo wallet (archived or not) → tombstoned with contents and `WalletDeleted`; archived shared wallet transferred; each transfer writes one `role_changed`, one activity entry, plan demotion applied under a free new owner, nobody removed; activity and audit entries of transfers, deletions, leaves, invitation revokes and budget removals name the departing person as actor, while session ends, subscription ends and `deletion_completed` name `system` (FR-064).
- [X] T034 [P] [US4] `tests/Feature/Identity/Deletion/PurgeKeepsHistoryTest.php` (FR-041, FR-042, SC-005): for every surviving wallet, balances (derived, not cache) and transaction counts identical before and after; D's transactions keep `created_by`; no transaction/posting `version`, `change_seq` or row changed; D's auto-confirm rule untouched and its next due occurrence ends with `auto_confirm_refusal = not_permitted` after `wallets:materialise-occurrences`.
- [X] T035 [P] [US4] `tests/Feature/Identity/Deletion/PurgeOrderTest.php` (FR-040, FR-043, FR-044): `ProfileUpdated` with `display_name` dispatched (Event::fake ordering, or recorded change_seq order) before the first membership tombstone; a null name publishes nothing; invitations revoked (address erased) before `user_keys` deleted; memberships tombstoned with wallet stamps and `member_change_seq` (ADR-0017) and one `membership_revoked` each.
- [X] T036 [P] [US4] `tests/Feature/Identity/Deletion/PurgeRemovesTest.php` (FR-046–FR-050): after the purge — husk (`purged`, `purged_at`, null address/lookup/password, counters kept); no `profiles`, `budget_start_days`, `identity_links`, `device_sessions`, `user_keys`, `data_exports` (and files), choices, `account_purges`, framework `sessions`, `sign_in_failures` row for the lookup; budgets and unreferenced categories tombstoned then gone with the user scope's `purged_change_seq` raised, referenced categories archived with owner id; granting `manual`/`instapay` subscriptions ended with `ended_by_account_purge_at`, `google_play` stamped only; audit, activity, category references, operator notifications, claims and redeem codes untouched; old address signs in `401 INVALID_CREDENTIALS` and registers fresh.
- [X] T037 [P] [US4] `tests/Feature/Identity/Deletion/PurgingStateTest.php` (FR-052): due `pending_deletion` → `purging` and every session fails next request (`UNAUTHENTICATED`) with end reason `account_deleted`, one `session_revoked` each (actor system); in `purging`, sign-in → `INVALID_CREDENTIALS` (lockout counted, uniform), cancel impossible; not-due or `active` accounts untouched.
- [X] T038 [P] [US4] `tests/Feature/Identity/Deletion/PurgeAuditTest.php` (FR-055, FR-056, SC-006): exactly one `deletion_completed` (actor system, counts only); every audit/activity entry written during the purge free of excluded values; log spy finds no address, name, wallet/category name, amount or note; queued mail jobs encrypted.
- [X] T039 [P] [US4] Participant tests: `tests/Feature/Sharing/Departure/RevokeSentInvitationsTest.php`, `tests/Feature/Wallets/Departure/{ResolveOwnedWalletsTest,LeaveRemainingWalletsTest,ReleaseCategoriesTest}.php`, `tests/Feature/Budgeting/Departure/DeleteBudgetsTest.php`, `tests/Feature/Billing/Departure/EndSubscriptionsTest.php` — each: does its job, returns counts, is a no-op on a second call.

### Implementation

- [X] T040 [US4] `Application/Deletion/PurgeAccount.php` (`run(userId)`: begin step under the users row lock via `markPurging`; per step skip if `last_step` is at or past it; run Identity's own steps or the tagged participant for the step; after each, record `last_step` and merge counts; fail fast if a participant is missing), Identity steps: begin (`EndSessions::endAllFor(userId, EndReason::AccountDeleted, actor: System)` + delete framework `sessions` rows), erase_name (`SaveProfile::handle` with `display_name = null` and the current version, skip if null), exports (delete files, rows, choices), completion_mail (sync send, `mail_sent_at`), erase_identity (one transaction: tombstone then delete the profile, delete other dependants and `sign_in_failures` by lookup, `user_keys`, `toHusk`, `deletion_completed`, delete `account_purges`). Job `Application/Jobs/PurgeAccountJob.php` (`ShouldQueue`, `ShouldBeUnique` `uniqueId = userId`, `$tries = 5`, backoff `[60, 300, 900, 3600]`, `$timeout = 1800`).
- [X] T041 [P] [US4] Sharing participant `app/Modules/Sharing/Application/Departure/RevokeSentInvitations.php` (step `revoke_invitations`; each pending invitation with `invited_by = userId` → `InvitationService::revoke(userId, walletId, id, version, present)`), tagged in `SharingServiceProvider`.
- [X] T042 [P] [US4] Wallets participants `app/Modules/Wallets/Application/Departure/{ResolveOwnedWallets (step resolve_owned_wallets; per owned live wallet ascending: `WalletService::transferOwnership(userId, walletId, successorId, version, present)` or `WalletService::delete(userId, …)`), LeaveRemainingWallets (step leave_wallets; `MembershipService::leave`), ReleaseCategories (step categories; new `CategoryService::releaseForDepartedUser(userId)`: tombstone then delete unreferenced (user horizon raised), archive referenced)}.php`, tagged in `WalletsServiceProvider`; Wallets' tombstone purge (`Application/Sync/TombstonePurge.php`) gains the sweep deleting archived categories of departed owners once unreferenced, asking Identity `Accounts::departedAmong(ids)` (new Identity Application method).
- [X] T043 [P] [US4] Budgeting participant `app/Modules/Budgeting/Application/Departure/DeleteBudgets.php` (step budgets; new `BudgetService::removeAllForDepartedUser(userId): int`: tombstone each live budget from the user counter, then delete the rows and raise the user horizon), tagged in its provider.
- [X] T044 [P] [US4] Billing participant `app/Modules/Billing/Application/Departure/EndSubscriptions.php` (step subscriptions; new `SubscriptionService::endForAccountPurge(userId, at)`: granting `manual`/`instapay` ended as `OperatorSubscriptions::revoke` ends them, reason `account_deleted`, actor system, one `entitlement_changed` each; `google_play` never changed; every row stamped `ended_by_account_purge_at`); `billing:purge-claims` (or a sibling in the same daily command) deletes subscriptions with `ended_by_account_purge_at` older than 400 days.
- [X] T045 [US4] `Application/Deletion/StartDuePurges.php` + command `Http/Console/PurgeAccounts.php` (`identity:purge-accounts`: dispatch `PurgeAccountJob` for due `pending_deletion` and all `purging` ordered by `deletion_scheduled_for`; delete husks with `purged_at` older than 400 days), scheduled hourly `withoutOverlapping()`; `SignIn` treats `purging`/`purged` as unknown; `AccountPurgeStalled` publish is T055's. Mailable `Application/Mail/AccountDeletedMail.php` + texts and view (Google Play sentence, no names). Make T033–T039 pass.

**Checkpoint**: quickstart §3 steps 1–3 pass.

---

## Phase 6: User Story 5 — A purge that stops halfway finishes correctly (P1)

**Goal**: restart safety proven at every step.

**Independent Test**: spec US5 Independent Test.

- [X] T046 [P] [US5] `tests/Feature/Identity/Deletion/PurgeResumeTest.php` (FR-053, FR-054): for each `PurgeStep`, a participant (or Identity step) that throws once after its work commits but before `last_step` is recorded; rerun completes with the same end state as an uninterrupted purge and no duplicate transfer, tombstone, `version`/`change_seq` bump, audit, activity, event or mail; two concurrent `PurgeAccount::run` calls → one does the work (unique job + conditional `markPurging`); a third run on a purged husk does nothing; two members of one wallet purged in sequence → B owner at A's purge, wallet deleted at B's.
- [X] T047 [US5] Fix whatever T046 exposes in `PurgeAccount` and the participants (every step must be a no-op on done state); document the recovery behaviour in research R-12 if it changed.

---

## Phase 7: User Story 2 — Export one wallet (P2)

- [X] T048 [P] [US2] Extend `tests/Feature/Identity/Export/ExportContentTest.php` and `tests/Contract/Identity/ExportsTest.php` (FR-007, FR-008): wallet scope for a viewer → only that wallet's files, `categories.json` holding the labels its entries use, CSV for that wallet, no user-scoped files; non-member or deleted wallet → 404, nothing queued or audited; wallet deleted between request and run → `failed/wallet_unavailable`, no retry.
- [X] T049 [US2] Wallet scope in `RequestExport` (membership check through `MembershipQueryService::roleOf`), the sections (skip user-scoped sections for wallet scope; `WalletSections` writes the one wallet) and `GenerateExport` (`WalletUnavailable` → fail without retry). Make T048 pass.

---

## Phase 8: User Story 6 — Reminders and honest mail (P2)

- [X] T050 [P] [US6] `tests/Feature/Identity/Deletion/DeletionReminderTest.php` (FR-058): pending with purge ≤ 3 days away → one reminder in the profile language; second run none; cancelled then re-requested → `deletion_reminded_at` reset, new reminder later; `purging`/`active` none.
- [X] T051 [P] [US6] `tests/Feature/Identity/Deletion/PurgeMailTest.php` (FR-057, FR-059, FR-060): the spec-007 confirmation mail now contains the Google Play and export sentences (en and ar); completion mail sent once, synchronously (no queued job holds the address), before `user_keys` is deleted; resumed purge after the mail step does not resend; no mail names a wallet, amount or other person.
- [X] T052 [US6] `Application/Deletion/SendDeletionReminders.php`, `Application/Mail/DeletionReminderMail.php` (+ encrypted send job), command `Http/Console/SendDeletionReminders.php` (`identity:send-deletion-reminders`, daily, `withoutOverlapping()`); `CancelDeletion` clears `deletion_reminded_at`; amend `DeletionScheduledMail` texts; all texts in `Identity/Http/Lang/{en,ar}/mail.php` and views. Make T050, T051 pass.

---

## Phase 9: User Story 7 — Operators see that an account is leaving (P3)

- [X] T053 [P] [US7] `tests/Feature/Admin/AccountDeletionStateTest.php` (FR-061–FR-063): account page shows "Deletion pending · date", "Deleting" for `purging`, "Deleted" for a husk (id only); no successor, wallet or export detail on any; overview `pending_deletion` count with the floor, `accounts`/`verified`/`new_*` exclude `purging` and `purged`; a purge stalled > 24 h publishes `AccountPurgeStalled` once and Admin raises one `account_purge_stalled` notification (identifiers only), a redelivered event adds nothing.
- [X] T054 [US7] `Identity/Application/Operators/AccountFacts` state `purging`; `EloquentUserRepository::figures()` excludes `purging`; Admin lang `accounts.php` en/ar "Deleting" and the account-page state branch.
- [X] T055 [US7] `StartDuePurges` publishes `AccountPurgeStalled` when `started_at` < now − 24 h and `stall_notified_at` null (set it in the same transaction); Admin queued listener `app/Modules/Admin/Application/Listeners/NotifyOperatorsOfStalledPurge.php` (dedupe by event id, kind `account_purge_stalled`, notification lang en/ar) registered in `AdminServiceProvider`. Make T053 pass.

---

## Phase 10: Polish & cross-cutting

- [X] T056 [P] Performance (SC-001), opt-in `tests/Feature/Identity/Export/ExportPerformanceTest.php` (`EXPORT_PERF=1`): 10,000 transactions over 10 wallets, export ready < 2 minutes, peak memory recorded; record in plan.md "Implementation notes".
- [X] T057 Apply the endpoint and security review checklists (both appendices) to the six operations, the signed route, and each new stored attribute; tick `checklists/privacy.md` evidence; confirm every implemented response matches `docs/openapi/paths/exports.yaml` and `identity.yaml` member for member — any deviation is a contract change → stop and record it in plan.md.
- [X] T058 Gates: `dev exec -- composer test` (Pint, Larastan 8, arch, suite, API), `dev exec -- vendor/bin/pest tests/Contract/OpenApi --compact`; walk quickstart.md §1–§4 on the dev stack (Mailpit). State "Seeder: N/A — no reference data" in the commit description. Ask the owner to run the complete suite.
- [X] T059 Apidog sync: bundle with `npx @redocly/cli bundle docs/openapi/openapi.yaml -o <scratchpad>/bundle.json`; import with `apidog import --project 1332822 --format openapi --file <scratchpad>/bundle.json --module-map "Personal finance backend API=1363204"`; create or update the project doc **"Export & deletion"** with contracts/export-deletion-api.md "What C15 binds to" (export states and polling, `download_url` in the system browser, `409` + `current`, 5 per 24 h, ZIP/CSV format, no gating, ownership-choice fields and fallbacks, Google Play notice, "Former member" rendering); update the "Error catalogue" doc (2444506) with the reworded `EXPORT_IN_PROGRESS` meaning. Record the import counts and doc id for the summary.
- [X] T060 Write specs/022-export-data-deletion/summary.md (parent writes it)

---

## Dependencies & execution order

- **Phase 1**: T001–T006 in parallel (docs first: glossary T003 before any code using the terms).
- **Phase 2**: T007, T008 (migrations) → T011; T009, T010 parallel; T012 after T007–T008. Blocks every story.
- **US1** (T013–T026) needs Phase 2. **US3** (T027–T032) needs Phase 2 (independent of US1). **US4** (T033–T045) needs US3's planner (T031) and US1's export store for the exports step (T024). **US5** (T046–T047) needs US4. **US2** (T048–T049) needs US1. **US6** (T050–T052) needs US4's mail step (T040, T045). **US7** (T053–T055) needs US4 (T045).
- **Polish** after all stories; T060 last.

## Parallel examples

- Phase 1: T001–T006 together.
- US1 tests: T013–T019 together; then T020 → (T021 ∥ T022 ∥ T023) → T024 → T025 → T026.
- US4: tests T033–T039 together; participants T041 ∥ T042 ∥ T043 ∥ T044 while T040 is written; T045 last.

## Implementation strategy

1. **MVP**: Phases 1–2 + US1 → "take a copy" works (privacy page's first promise).
2. **Deletion core**: US3 → US4 → US5 → the purge is real and restart-safe; run the C15 checkpoint (export, owner choice, `dev identity:purge-accounts`).
3. **Completion**: US2, US6, US7, polish.
