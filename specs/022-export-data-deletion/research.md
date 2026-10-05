# Research: Export & Data Deletion (spec 022, B15)

Every decision below is an **assumed default** (owner away, delegated authority): the recommended or most conservative option. Each names the spec requirement it serves.

## R-1 Archive format: ZIP of JSON plus one CSV

**Decision**: one ZIP (`ZipArchive`, PHP's bundled `zip` extension, present in the container) with `manifest.json`, one JSON file per record kind (`account.json`, `profile.json`, `devices.json`, `subscriptions.json`, `categories.json`, `budgets.json`, `memberships.json`, `invitations.json`, `wallets.json`, `transactions.json`, `recurring_rules.json`, `occurrences.json`) and `transactions.csv`. JSON is pretty-printed UTF-8 (`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`), arrays sorted by `id` for stable diffs. Entries are written with `ZipArchive::CM_DEFLATE`. File name offered to the browser: `export-<YYYY-MM-DD>.zip` (no product name, P-13).

**Rationale**: baseline Export rule 2 ("complete structured data, plus a spreadsheet-openable file of transactions"); FR-001–FR-007; no dependency (FR-072).

**Alternatives**: XLSX (needs `phpoffice/phpspreadsheet` or similar — new dependency, open question 1); one big JSON (not spreadsheet-openable); separate downloads per file (more links, more secrets).

## R-2 CSV dialect

**Decision**: UTF-8 with BOM (Excel shows Arabic correctly), comma, CRLF, RFC 4180 quoting via `fputcsv($h, $row, ',', '"', '')` on a `php://temp` stream, header row of FR-005. Amounts as integer `amount_minor` plus `currency` (P-03 forbids decimal strings). Formula neutralisation: a text cell starting with `=`, `+`, `-`, `@`, `\t`, `\r` gets a leading `'` (OWASP CSV injection guidance). Numeric columns are never prefixed. `wallet` and `category` are names as the person sees them; `counter_wallet` is the name when the person is a member of it, else empty.

**Rationale**: FR-005, SC-003.

## R-3 Who and what an export covers

**Decision**: the account export reads, as the person, every wallet with an **active** membership (any role) and, per wallet, everything a member's read returns: transactions, rules, occurrences, and tombstones still within retention (as `{id, deleted_at, wallet_id}`). Authorship is rewritten to `author: "me" | "other_member"` from `created_by`. Category labels on other members' entries come from the wallet's Category References (the same projection the app renders). User-scoped records come from the person's own query services. Invitations: only those the person sent, without address or token. Activity entries and audit entries are not exported (activity is 90-day operational history readable in the app; audit is operator-only).

**Rationale**: baseline Export rule 3 ("what the person entered and what they can already see"); B6/B7/B8/B9 inherited notes; FR-002–FR-004.

**Alternatives**: only records the person authored in shared wallets (balances and history become unreadable; the person already sees the rest); include other members' ids (an identifier of another person is not the person's data).

## R-4 Module seam for export: `ExportSection`

**Decision**: Identity owns orchestration (constitution: "account deletion and export orchestration"). `Identity\Application\Export\ExportSection` is an interface `write(ExportScope $scope, ExportWriter $writer): void`; Wallets (wallets, transactions, rules, occurrences, categories, memberships), Budgeting (budgets), Sharing (invitations sent), Billing (subscriptions) implement it in their own `Application/Export/` namespace and are tagged `identity.export-sections` in their service providers; Identity writes account, profile, start days and devices itself. `ExportWriter` streams JSON arrays and CSV rows to temp files, so memory stays flat. Every section reads through its own module's repositories/query services (P-02); Identity never touches another module's tables.

**Rationale**: FR-011 (P-02), same dependency direction as `WalletDeletionParticipant`, each module owns the shape of its own records.

**Alternatives**: Identity calling each module's list endpoints/query services directly (Identity would know every module's DTOs; paging through HTTP-shaped queries is slower); a Reporting projection (wrong owner).

## R-5 Export lifecycle and job

**Decision**: `data_exports` row, states `queued → running → ready → expired`, `queued|running → failed`. `RequestExport` (Identity Application): validates scope (wallet membership through `MembershipQueryService::roleOf`), checks the budget (`RateLimiter` key `identity-export:<user>`, 5 per 86,400 s, hit only on accept), inserts the row inside a transaction; a unique partial index `data_exports_one_active` (`user_id` where `status in ('queued','running')`) makes the one-active rule hold under concurrency — a unique violation becomes `ExportInProgress` carrying the active row. Audit `export_requested` in the same transaction; `GenerateExport` job dispatched `afterCommit`.
`GenerateExport` (`ShouldQueue`, `$tries = 3`, `$timeout = 900`, `backoff [30, 120]`, unique per export id): lock row; if not `queued`/`running` → return; set `running`, `started_at`; build into `storage/app/private/exports/<user_id>/<export_id>.zip.part`, then rename to `.zip` (atomic on one filesystem); set `ready`, `size_bytes`, `completed_at`, `expires_at = completed + 24h`; queue `ExportReadyMail` after commit. `failed()` handler sets `failed` + `generation_failed` and deletes the partial file. Before the rename the job re-reads the row and the account state; if the row is gone or the account is no longer `active`/`pending_deletion` it deletes its partial file and ends (the purge's exports step owns the cleanup). `identity:expire-exports` also deletes files under `exports/` that no row references (orphans). A wallet export whose wallet is unreadable at run time ends `failed` + `wallet_unavailable` without retry.
`identity:expire-exports` (every 15 minutes): `ready` past `expires_at` → delete file, `expired`; `queued|running` older than 60 minutes → `failed` + `timed_out`, delete partial; rows older than 30 days → delete (with any file).

**Rationale**: FR-010–FR-019; baseline Export rule 1.

## R-6 Download link

**Decision**: `URL::temporarySignedRoute('identity.exports.file', $until, ['export' => $id])` on a **web** route `GET /exports/{export}/file` (Identity's routes file, group without session, cookies or CSRF: `signed` + `throttle:identity-export-file`), controller `ExportFileController`. It loads the export; if not `ready`, past `expires_at`, or the user is not `active|pending_deletion`, 404. Otherwise records `export_delivered` once (`UPDATE … SET delivered_at = now() WHERE id = ? AND delivered_at IS NULL`; the audit entry is written only when that update touched a row) and returns `Storage::disk('local')->download($path, 'export-YYYY-MM-DD.zip', ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'])`. App reads get `until = min(now + 15 min, expires_at)`; the mail gets `until = expires_at`. Laravel's built-in `local` "serve" route is **not** used, because it cannot record `export_delivered`. Budget `identity-export-file`: 20 per 10 minutes per IP.

The route sits outside `/api/v1` because a browser or share sheet opens it without bearer or client headers; P-18 rule 1 holds because the capability (asking for and finding the export) is the JSON API, and the link is a delivery detail of its answer. Spec 020's receipt link is the precedent (signed route, private disk).

**Rationale**: FR-012–FR-015, FR-018, FR-021; owner instruction "signed short-lived download link on Laravel's existing private disk".

**Alternatives**: bearer-authenticated `GET /api/v1/exports/{id}/file` (the app must download into its own storage, which C15 forbids, and browsers cannot add headers); the mail carrying no link (owner instruction asks for an email link).

## R-7 No encryption of the archive at rest

**Decision**: plain ZIP on the private disk; 24-hour life; deleted at purge; protected-attributes row added (FR-020).

**Rationale**: encryption would force a custom decrypting stream for the download and buys little for a 24-hour file reachable only through a signature. Recorded honestly in the baseline rather than implied.

**Alternatives**: seal with the user's data key (custom streaming, key fetch per download); ZIP AES password (the person would need a second secret).

## R-8 Export not gated

**Decision**: no `EntitlementService` check, no verification gate, served during the grace period.

**Rationale**: P-09 rule 5 (free tier limited only on sharing); baseline C2; baseline Export rule 4; privacy commitments "Leaving". `export.enabled` stays in the catalogue untouched (open question 2).

## R-9 Successor rule (domain)

**Decision**: `Identity\Domain\Deletion\SuccessorRule::resolve(array $candidates, ?string $chosenUserId): ?Successor` — pure domain, unit-tested. Input candidates: the wallet's active members other than the departing person, each `{user_id, role, joined_at, account_pending}`. Output `{user_id, reason}` or null (no candidates → delete). Order: one candidate → `only_member`; chosen ∈ candidates → `chosen`; editors by `joined_at`, then `user_id`, skipping `account_pending` unless all are pending → `oldest_editor`; same over viewers → `oldest_viewer`. Candidates come from `MembershipQueryService::activeMembersOf` (Wallets Application) plus `Accounts::statesOf` (Identity). The preview (`GET /account/deletion-request`) and the purge call the same `DeletionPlanner` application service, which feeds this rule.

`joined_at` is the membership's `created_at` (a re-joined member gets a new membership record, spec 016, so their seniority restarts — conservative).

**Rationale**: FR-031, FR-033; owner decision 2026-09-25.

**Alternatives**: promote a viewer to editor first, then transfer (two writes, two feed events, same end state); delete when no editor (destroys others' history — rejected by the owner's reasoning).

## R-10 Successor choices: storage and validation

**Decision**: `deletion_successor_choices (user_id, wallet_id, member_user_id, chosen_at)`, PK (`user_id`, `wallet_id`), FK user cascade. `RequestDeletion::handle(userId, password, ?list successors)` validates through `DeletionPlanner::validateChoices` (owned live wallet with ≥1 other active member, member active and ≠ self, no duplicate wallet; at most 100 entries) **before** the password check's side effects, and writes choices in the same transaction as scheduling; repeating with `successors` replaces all rows for the user; without, leaves them. `CancelDeletion` deletes them in its transaction (both `explicit` and `sign_in`). Validation errors map to `422 VALIDATION_FAILED` with pointers `/successors/{n}/wallet_id` (`INVALID_ENUM_VALUE`: not one of the person's owned shared wallets, or duplicated) and `/successors/{n}/member_user_id` (`INVALID_ENUM_VALUE`: not an active other member); `INVALID_ID` for malformed UUIDs. No new field code.

**Rationale**: FR-035–FR-038; additive to the KMP 002 client (it sends `{password}` only, which stays valid).

## R-11 Idempotency key not used

**Decision**: neither the deletion request/cancel nor `POST /exports` takes `Idempotency-Key`. Deletion request and cancel are idempotent by state (spec 007 FR-052/FR-053); `POST /exports` retried after a lost answer meets `409 EXPORT_IN_PROGRESS` whose `current` is the export the first attempt created, so the client recovers it without a key.

**Rationale**: api-conventions reserves the key for three money operations; FR-039.

## R-12 The purge: orchestration, steps, restart safety

**Decision**: `identity:purge-accounts` (hourly, `withoutOverlapping`) selects `pending_deletion` users with `deletion_scheduled_for <= now()` and all `purging` users, ordered by `deletion_scheduled_for`, and dispatches `PurgeAccount(userId)` (`ShouldQueue`, `ShouldBeUnique` with `uniqueId = userId`, `$tries = 5`, backoff `[60, 300, 900, 3600]`, `$timeout = 1800`). The job calls `Identity\Application\Deletion\PurgeAccount::run(userId)`, which:

1. **Begin** (Identity): in one transaction under the `users` row lock: if state is `pending_deletion` and due → state `purging`, insert `account_purges(started_at, last_step = 'begin')`; if `purging` → continue from `last_step`; anything else → return. Then `EndSessions::endAllFor(userId, EndReason::AccountDeleted, actor: System)`; delete Laravel `sessions` rows of the user.
2. **erase_name** (Identity): if the profile's display name is not null, `SaveProfile::handle(userId, values with display_name = null, currentVersion)` → `ProfileUpdated` naming `display_name` after commit (B14a). A null name → no-op (no event).
3. **revoke_invitations** (Sharing participant): every pending invitation with `invited_by = userId` → `InvitationService::revoke(userId, walletId, invitationId, version)`; already closed ones are skipped.
4. **resolve_owned_wallets** (Wallets participant, using Identity's `DeletionPlanner` result passed in): per owned live wallet, ascending wallet id, one unit of work each: successor → `WalletService::transferOwnership(userId, walletId, successorId, version)`; none → `WalletService::delete(userId, walletId, version)`. A wallet the person no longer owns (already transferred in an earlier attempt) is skipped — that is the idempotence.
5. **leave_wallets** (Wallets participant): every remaining active membership → `MembershipService::leave(walletId, userId)` (they are never owner now).
6. **budgets** (Budgeting participant): through a new `BudgetService::removeAllForDepartedUser(userId)`, tombstone every live budget (P-07 rule 5: `deleted_at`, `version + 1`, `change_seq` from the user counter) and then delete the user's budget rows, raising the user scope's purge horizon (`raiseHorizon`). Immediate removal of these tombstones is what ADR-0024 permits for a purged user scope only (no reader remains); it supersedes ADR-0008 rule 12 for that case.
7. **categories** (Wallets participant): new `CategoryService::releaseForDepartedUser(userId)`: tombstone then delete categories with no reference (transactions, rules, occurrences, budgets — budgets already gone; same user-scope rule as step 6), archive (`archived_at`) the rest, leaving their Category References live on wallet feeds. Wallets' part of the daily `sync:purge-tombstones` run gains one sweep: delete archived categories whose owner Identity reports as `purged` or gone (`Accounts::departedAmong(userIds)`, Identity Application) and that nothing references any more.
8. **subscriptions** (Billing participant): new `SubscriptionService::endForAccountPurge(userId, at)` ends granting `manual` and `instapay` subscriptions (status as `OperatorSubscriptions::revoke` sets, reason `account_deleted`, actor `system`, one `entitlement_changed` each) and stamps `ended_by_account_purge_at` on every row of the user; `google_play` rows are stamped only.
9. **exports** (Identity): delete export files and rows; delete successor choices.
10. **completion_mail** (Identity): if not yet sent (`account_purges.mail_sent_at` null), decrypt the address and send `AccountDeletedMail` **synchronously** (`Mail::to()->locale()->send()`), then stamp `mail_sent_at`. A send failure throws → job retry (the step repeats; the stamp prevents a duplicate once it succeeded). Sending synchronously keeps the plaintext address out of the `jobs` table.
11. **erase_identity** (Identity): one transaction: tombstone the profile from the user counter, then delete `profiles` (raising the user horizon), `budget_start_days`, `identity_links`, `device_sessions`, `user_keys` (key destruction), `data_exports`, and the `sign_in_failures` row for the stored `email_lookup`; update `users` to husk (`email_ciphertext`, `email_lookup`, `password_hash` null; `state = purged`; `purged_at = now()`; deletion columns null); write `deletion_completed` (actor system, detail counts accumulated in `account_purges.counts` jsonb); delete the `account_purges` row (cascade would do it later; explicit is clearer).

After each step commits, `account_purges.last_step` is set in its own short update. A step is re-run safely if the crash fell between the step's commit and the marker update, because every step is a no-op on already-done state (owned-wallet set empty; no pending invitations; null name; no budgets…).

Steps 3–8 are **participants**: `Identity\Application\Deletion\AccountPurgeParticipant` (`step(): PurgeStep`, `purge(string $userId, PurgeContext $ctx): PurgeCounts`) implemented in each module's `Application/Departure/` and tagged `identity.purge-participants`; `PurgeAccount` runs the `PurgeStep` enum in order and fails fast if a step has no participant.

**Rationale**: FR-040–FR-056, FR-064; brief "idempotent and resumable"; B14 inherited note (memberships tombstoned via the normal leave/transfer paths so the member's user feed and wallet feeds both carry them, ADR-0017).

**Alternatives**: one giant transaction (locks every wallet of the person at once, breaks ADR-0017 rule 5 granularity and ADR-0021's per-wallet restamp model, and a failure rolls back hours of work); acting as `system` through new owner-bypassing service methods (duplicates authorization paths; the person's own authority is valid until they leave); hard-deleting the `users` row (spec 007's `purged` state and spec 021's "Deleted" page already expect a retained row; a husk keeps audit/operator lookups by id meaningful).

## R-13 State `purging`, sign-in and cancel

**Decision**: `AccountState::Purging = 'purging'`; `users_state_check` and the pending CHECK updated. `SignIn` treats `purging` and `purged` exactly like a wrong password (`401 INVALID_CREDENTIALS`, counts toward lockout, uniform timing — a `purged` husk has no lookup value, so it is not even found). `CancelDeletion` only acts on `pending_deletion` (unchanged); sessions are already ended, so an API cancel gets `401 UNAUTHENTICATED`. The `Account` schema's `state` enum does not gain `purging` (no session can read it).

**Rationale**: FR-052.

## R-14 Husk retention

**Decision**: `identity:purge-accounts` also deletes husks with `purged_at < now() - 400 days` (cascade removes nothing else — every dependent row is already gone). Spec 021's account page renders `purged` already ("Deleted. Nothing else is kept.").

**Rationale**: FR-049; aligns with the 400-day audit horizon so an operator can answer "was this account deleted?" for as long as the audit trail can.

## R-15 Recurring rules of a departed author

**Decision**: untouched (FR-042). Spec 014's `AutoConfirmer` already refuses as `not_permitted` once the author is not a member; the occurrence stays `scheduled` with `auto_confirm_refusal` visible to members, who confirm by hand.

**Alternatives**: transfer authorship to the new owner (rewrites `created_by`, contradicting the glossary and acting under authority never given); end the rules (destroys schedules others rely on); pause (a change the remaining members did not ask for, and pause by a departed actor is not permitted).

## R-16 Ledger references and the departed marker

**Decision**: no ledger write at all. `transactions.created_by`, `recurring_rules.created_by`, `wallet_activity.actor_user_id`, `category_references.owner_user_id`, `audit_entries.*_id`, `redeem_codes.redeemed_by` keep the id. After the purge the id maps to a husk with no attribute, then to nothing; clients render an author without a live membership as "Former member". The baseline's Leaving table row "Their name on those transactions → replaced by a stable anonymous marker" is reworded to "their name comes off: the entries keep an identifier that no longer resolves to any person".

**Rationale**: owner decision ("transactions stay with their reference"); glossary (`created_by` never changes); P-04/P-05 (no balance change); FR-041.

**Alternatives**: rewrite `created_by` to a fixed sentinel (collapses all departed members into one, bumps every transaction's version and floods every wallet feed); a per-departure random UUID (same feed flood, no privacy gain over an id that resolves to nothing).

## R-17 Mails

**Decision**: Identity mailables `ExportReadyMail` (link until expiry, size, expiry instant), `DeletionReminderMail` (date, how to cancel, export suggestion, Google Play sentence), `AccountDeletedMail` (completion, Google Play sentence, no names). `DeletionScheduledMail` (spec 007) text gains the Google Play and export sentences. Bilingual in `Identity/Http/Lang/{en,ar}/mail.php`. `identity:send-deletion-reminders` daily: `pending_deletion`, `deletion_scheduled_for <= now + 3 days`, `deletion_reminded_at` null → set the column and queue the mail in one transaction (`afterCommit`). The reminder and ready mails are queued through jobs implementing `ShouldBeEncrypted` (they carry the address as recipient), the pattern spec 020 uses for code-carrying mails.

**Rationale**: FR-057–FR-060.

## R-18 Operators

**Decision**: `AccountFacts.state` gains `purging`; the account page shows "Deleting" (new lang key en/ar). Overview figures unchanged in shape: `pending_deletion` already counted; `accounts`/`verified`/`new_*` exclude `purged` and now also `purging`. New `operator_notifications` kind `account_purge_stalled` raised by `identity:purge-accounts` when `account_purges.started_at < now - 24h` and `stall_notified_at` null (Identity calls Admin's existing notification application service — or publishes `AccountPurgeStalled`, consumed by an Admin queued listener, matching spec 007's pattern; chosen: the event, so Identity does not depend on Admin).

**Rationale**: FR-061–FR-063.

## R-19 Contract and docs

**Decision**: `docs/openapi/paths/exports.yaml` (three operations) and additive changes to `identity.yaml` (`GET` on `/account/deletion-request`, `DeletionRequest` body, `DeletionSchedule.successors`, `DeletionPlan`); `info.version` v1.13. `EXPORT_IN_PROGRESS` meaning gains "The body's `current` is the export in progress." (wording change, same code). A 409 response local to `exports.yaml` adds `current: Export` to the Problem. ADR-0024 "Account deletion transfers shared wallets; the departed id is the marker". Glossary: Export, Successor, User states. Baseline: Leaving table, Deletion rules (purging, husk), Export rules (format, link, retention), protected attribute row, retention rows, route budgets, session end reason. Privacy commitments en/ar "Leaving" + mapping rows. Constitution: PATCH v1.3.13 (P-07/P-08 list ADR-0024; Identity row unchanged — no new event published outside Identity except `AccountPurgeStalled`, which is added to the Identity row).

## R-20 Dependencies

**Decision**: none. `zip` and `sodium` extensions present (`dev exec -- php -m`), `ZipArchive`, `fputcsv`, signed routes, queues, scheduler, mail, `RateLimiter` all in Laravel 13.
