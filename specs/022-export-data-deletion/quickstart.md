# Quickstart: Export & Data Deletion (spec 022)

Validation guide for the built feature. Run everything in the container (`dev …`). Contract: [contracts/export-deletion-api.md](./contracts/export-deletion-api.md); data: [data-model.md](./data-model.md).

## Prerequisites

```bash
dev up && dev migrate
dev queue:work --stop-when-empty   # run after each step that queues work
```

Mail lands in <http://mailpit.test>. Create three people with the existing registration flow (or factories in a test): **D** (departing), **P** (partner), **Q** (third member). D owns wallet **W** shared with P (viewer) and Q (editor), owns solo wallet **S**, and is an editor of Q's wallet **R**; each has a few transactions; D has two categories, a budget, a pending invitation and two sessions.

## 1. Export (US1, US2)

1. `POST /api/v1/exports {"scope":"account"}` as D → `202`, `status: queued`; again → `409 EXPORT_IN_PROGRESS` with `current`.
2. Run the queue → `GET /api/v1/exports/{id}` → `ready`, `download_url`, `download_url_expires_at` ≤ 15 min ahead; one "export ready" mail.
3. `curl -o export.zip "<download_url>"` (no headers) → ZIP. `unzip -l export.zip` shows `manifest.json`, the JSON files, `transactions.csv`. `grep` the archive for P's and Q's ids and names → nothing. Open `transactions.csv` in a spreadsheet → Arabic intact, `amount_minor` integers.
4. Audit: one `export_requested`, and after the download one `export_delivered` (download twice → still one).
5. `POST /api/v1/exports {"scope":"wallet","wallet_id":"<R>"}` → `202`; a wallet D is not in → `404`.
6. `dev identity:expire-exports` after travelling 24 h (`dev tinker` / test clock) → `expired`, the old link → 404.

## 2. Successor choice (US3)

1. `GET /api/v1/account/deletion-request` as D → `active`; W listed with `reason: oldest_editor` (Q), `choice_expected: true`; S not listed.
2. `POST /api/v1/account/deletion-request {"password":"…","successors":[{"wallet_id":"<W>","member_user_id":"<P>"}]}` → `200`, `successors[0].reason = chosen`.
3. Same with Q's wallet R or a non-member → `422` with pointer `/successors/0/…`, state still `active`.

## 3. Purge (US4, US5, US6)

1. Move D's `deletion_scheduled_for` into the past (test clock), then `dev identity:send-deletion-reminders` → one reminder (before moving past), and `dev identity:purge-accounts` + run the queue.
2. Check: W owned by P (viewer promoted directly), balances and transaction counts of W and R unchanged, D's transactions in R keep `created_by = D`; S tombstoned; D's memberships tombstoned on W, R feeds and D's user feed; `ProfileUpdated` handled before (P's wallet feed for R shows D's membership re-stamped with `display_name: null`, then tombstoned); D's invitation revoked; D's budget and unused category gone, used category archived; D's export file gone; `users` row is a husk (`purged`, no address/lookup/password); `user_keys` row gone; one `deletion_completed`; one completion mail.
3. Sign in as D → `401 INVALID_CREDENTIALS`; register D's address → new account.
4. Resume: repeat with a fresh D', inject a failure after `resolve_owned_wallets` (feature test does this with a throwing participant), rerun → completes, no duplicate transfer, activity or audit entries.

## 4. Operators (US7)

Back office `/admin/accounts/{D}` while pending → "Deletion pending · date"; during the purge → "Deleting"; after → "Deleted". Overview shows the pending-deletion count (floor applied).

## 5. Contract and gates

```bash
dev exec -- vendor/bin/pest tests/Contract/OpenApi
dev exec -- vendor/bin/pest tests/Contract/Identity/ExportsTest.php tests/Contract/Identity/DeletionRequestTest.php
dev exec -- vendor/bin/pest tests/Feature/Identity/Export tests/Feature/Identity/Deletion tests/Unit/Identity
dev exec -- composer test
```

## Deployment notes

- Run the migrations; schedule `identity:purge-accounts` (hourly), `identity:expire-exports` (every 15 minutes), `identity:send-deletion-reminders` (daily).
- The queue worker must have `--timeout` above 900 s for the export job and 1800 s for the purge job (or a dedicated `long` queue).
- `storage/app/private/exports` must be on persistent storage shared by web and worker.
