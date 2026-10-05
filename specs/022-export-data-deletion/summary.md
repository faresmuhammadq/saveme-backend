# Summary: Export & Data Deletion (spec 022, B15)

**Date**: 2026-10-06 · **Status**: implemented (T001–T060), QA and E2E green, committed on `main` (776e5b2). **ADR-0024 needs the owner's ratification.**

## What was built

- **Account and wallet export.** `POST /exports` (`scope: account|wallet`, `wallet_id?`), `GET /exports`, `GET /exports/{id}`. A queued `GenerateExportJob` builds a ZIP (one JSON file per record kind plus `transactions.csv`) on the private disk, streamed through a `ZipArchive` writer. The CSV is UTF-8 with a BOM, amounts as integer minor units plus currency, and spreadsheet-formula cells neutralised. Modules supply their share through `ExportSection`s. Other members appear only as `author: "other_member"`: no name, address or id anywhere in the archive.
- **Delivery.** A signed web route `GET /exports/{id}/file` (not in OpenAPI) serves `application/zip`. Each status read returns a fresh 15-minute link; the mailed "ready" link lasts until the file expires after 24 hours; export records are kept 30 days. The first download records `export_delivered`.
- **Limits.** One active export per person (409 `EXPORT_IN_PROGRESS` with `current`), 5 accepted requests per 24 hours (429), download route 20 per 10 minutes per origin (JSON 429). Not gated by plan, `export.enabled` or address verification; allowed during the grace period. A run stuck for 60 minutes is marked failed. `identity:expire-exports` (every 15 minutes) handles expiry, stale runs, 30-day record deletion and stray files.
- **Successor choice.** `GET /account/deletion-request` (new) returns a `DeletionPlan`; `POST /account/deletion-request` accepts an optional `successors` list (up to 100, replaceable while pending, dropped on cancel). One `SuccessorRule` serves the preview and the purge: the only other member whatever their role (a viewer becomes owner), else the stored choice, else the oldest editor, else the oldest viewer; a wallet is deleted only when nobody else belongs to it.
- **The purge.** The hourly `identity:purge-accounts` runs a unique `PurgeAccountJob` through eleven ordered, idempotent steps with recorded progress (one run per account via a PostgreSQL advisory lock). The account moves to `purging` (blocks sign-in and cancel; all sessions end as `account_deleted`). The display name is erased through the normal profile save (publishing `ProfileUpdated`) before any membership is tombstoned; pending invitations are revoked before the data key is destroyed; owned wallets are transferred or (if solo) deleted; other memberships end; budgets and categories are released; `manual` and `instapay` subscriptions end (Google Play is left alone and the mails say so). The completion mail is sent synchronously before the key is destroyed; one `deletion_completed` entry carries three counts. The `users` row stays as a `purged` husk (no address, lookup value, password or key) and is deleted after 400 days. Ledger rows are untouched and `created_by` stays as the departed marker.
- **Mail and operators.** New mails in English and Arabic: export ready, deletion reminder (3 days before; `identity:send-deletion-reminders`, daily) and account deleted; the deletion confirmation mail gained the Google Play and export sentences. The back office shows "Deleting" for a purging account and the pending-deletion count under the anonymity floor; a purge stalled for more than 24 hours raises one `account_purge_stalled` notification (new `AccountPurgeStalled` event, new Admin migration).
- **Docs**: ADR-0024, constitution v1.3.13, glossary, security baseline, privacy commitments (en, ar), OpenAPI v1.13 (`paths/exports.yaml`, additive edits to `identity.yaml`, schemas, responses, error catalogue).
- **Migrations (all additive, applied to dev)**: Identity `2026_10_11_000001`–`000004` (`data_exports`, `deletion_successor_choices`, `account_purges`, users and `device_sessions` changes), Billing `000005` (`ended_by_account_purge_at` on `subscriptions`), Admin `000006` (the `account_purge_stalled` notification kind). `billing:purge-claims` now also deletes the subscription rows of purged accounts after 400 days.
- **Apidog**: bundle imported; project doc "Export & deletion" (2467355); "Error catalogue" doc (2444506) updated.

## Results

- **QA** (`qa/qa-20261005-232700.md`): 27 test cases, all PASS — every export status and error case, the signed route (valid 200; bad or expired signature 404; no download while `purging`), ZIP contents, `export_delivered` on first download only, the preview and every successor case, the purge on throwaway users, reminders, operator screens, log and audit hygiene, and resumability (a crash after `resolve_owned_wallets` plus two concurrent `identity:purge-accounts` runs finished with no duplicate `role_changed`; `purging` blocked cancel and sign-in).
- **E2E** (`test-reports/validation-20261005-233000.md`): 36 of 36 acceptance scenarios PASS; the back-office "Deleting" screen was checked in Playwright (`qa/screenshots/account-deleting.png`).
- **Gates**: Pint clean; Larastan level 8 clean; 52 arch tests; 3,409 passed, 6 skipped (including the Contract/OpenApi stage). About 130 new tests.
- **Performance**: 10,000 transactions over 10 wallets exported in 1.4 s with about 4 MB peak memory growth (target under 2 minutes).
- **Fixes during QA**: `AuditLogTest` "records one audit_read…" no longer hard-codes dates; it derives both from `config('admin.timezone')`, so it passes around 00:00 UTC. One contract-doc line reworded so `GET /exports` `limit` follows the shared clamped parameter; no OpenAPI file changed. `SessionStateTest` updated to include `account_deleted`.
- All 28 throwaway accounts were purged through the feature's own purge during QA; the throwaway operator was deactivated; audit rows remain.

## Deployment

- Run the migrations `2026_10_11_000001`–`000006`.
- Worker `--timeout` of at least 1800 s (export jobs 900 s, purge 1800 s); the database queue's `retry_after` was raised from 90 to 1900 in `config/queue.php`.
- `storage/app/private/exports` must be shared between web and worker.
- Scheduler: `identity:expire-exports` every 15 minutes, `identity:purge-accounts` hourly, `identity:send-deletion-reminders` daily.
- `ext-zip` is required (present in the container); no composer or npm dependency was added.
- Nine new keys in `config/identity.php`.

## Contract notes for the mobile client

- Re-reading `GET /exports/{id}` in the same second returns the same signed link (links are per-second); a later read gives a later expiry.
- A `ready` export read after `expires_at` is shown as `expired` with no link, even before the sweep runs.
- `choice_expected` stays true in the plan after a choice is sent: it describes the wallet, not whether a choice was made (the `reason: chosen` marks the choice).
- If the new owner is on the Free plan, the wallet's other editors are demoted to viewer (existing demotion rule).
- No `Idempotency-Key` on exports or on the deletion request and cancel.
- `successors` is validated before the password; `POST /exports` checks 404, then 409, then 429.

## Assumed defaults

Owner away with delegated authority; each the conservative option (plan A-1…A-22, Implementation notes).

1. One ZIP of JSON files plus `transactions.csv`; no XLSX (needs a new dependency).
2. CSV: UTF-8 with BOM; amounts as integer minor units plus currency (P-03); formula-injection neutralised.
3. Export contents: everything the person entered plus every wallet they actively belong to, with all its entries; other members as `other_member` only.
4. Export is not gated by plan, flag or verification, and works during grace.
5. Delivery by queued job on the private disk; fresh 15-minute link per status read; mailed link valid until the 24-hour expiry; records kept 30 days.
6. The archive is not encrypted at rest (24 hours behind a signature); a baseline row says so plainly.
7. No `Idempotency-Key` (reserved for the three money operations; these are idempotent by state or `current`).
8. Successor order and choice mechanics as above; the successor tie-break is joined instant, then user id (the spec said "member id").
9. The departed marker is the unchanged `created_by` id; clients show "Former member".
10. The departing author's recurring rules stay live; auto-confirm is already refused for a non-member, so the remaining members confirm by hand.
11. Ordering: name erased via the normal profile save first (the B14a rule); invitations revoked before the data key is destroyed (the B9 rule).
12. `begin` is a single transaction; a new `purging` state; each run unique per account.
13. The `users` row is kept as a `purged` husk, deleted after 400 days.
14. User-scope records are tombstoned and then removed in the same purge (ADR-0024 supersedes ADR-0008 rule 12 for a purged user scope only); categories still referenced by surviving records are archived and kept.
15. Subscriptions: `manual` and `instapay` ended at the purge; Google Play left alone (cannot be cancelled server-side), rows kept 400 days.
16. A reminder goes out when the purge is more than 0 and at most 3 days away; the completion mail is synchronous.
17. The purge calls the existing services as the departing person (invitations, transfers, leaving, budgets) and as `system` only for sessions, subscriptions and the completion entry.
18. Operators see states and counts only.
19. Records in the export are in change order, not id order; JSON entries carry both `category_id` and the category label; transfers appear once with their source wallet.
20. Invitations from wallets the person no longer owns are revoked by the system; categories with tombstoned references and no users wait for the daily `departed_categories` sweep (at most 90 days).
21. The file signature is checked in the controller (bad signature is 404, not 403).
22. Records in the export for a ready export past its expiry show as `expired` with no link.

## Remaining risks

- **ADR-0024 partly supersedes ADR-0008 rule 12** (tombstones removed at once for a purged user scope). This changes a decision the owner ratified; it needs the owner's sign-off.
- The member-name re-stamp is a queued job and, with a real worker, runs after the purge has tombstoned the memberships, so it re-stamps 0 wallets. `ProfileUpdated` is still published first and partners get a nameless tombstone, so no name remains; if the owner wants the live re-stamp row visible before the tombstone, the purge would need to run that re-stamp inline.
- Owner-only services get a departing person as actor; any future account-state or session check there would need adapting.
- The download link is a bearer secret in mail for up to 24 hours, and the archive is unencrypted on disk for that window (documented in the baseline).
- Open questions: XLSX (needs a dependency), the unused `export.enabled` flag, decimal amounts in the CSV (P-03), Google Play handling once B10 exists, whether to mail the new owner.
- The privacy page and security baseline changed (ownership transfer, export promises, English and Arabic) and need review.
