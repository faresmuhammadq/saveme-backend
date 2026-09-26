# Quickstart: Identity & Authentication

How to prove that B1 works end to end. For the contracts, see [contracts/](./contracts/), and for the tables, see [data-model.md](./data-model.md).

## Prerequisites

```bash
dev project open                      # environment up
dev exec -- composer install          # includes laravel/sanctum ^4.3 once added
dev migrate:fresh
```

`.env` needs the Identity secrets. Generate local ones:

```bash
dev exec -- php -r 'echo "IDENTITY_KEYS={\"k1\":\"".base64_encode(random_bytes(32))."\"}\nIDENTITY_ACTIVE_KEY=k1\nIDENTITY_LOOKUP_KEY=".base64_encode(random_bytes(32))."\n";'
```

Also set:

- `ADMIN_NOTIFICATION_RECIPIENTS=ops@example.test`
- `HASH_ARGON_MEMORY=65536`, `HASH_ARGON_TIME=4`, `HASH_ARGON_THREADS=1`

Mail lands in Mailpit at <http://mailpit.test>. Start a queue worker with `dev queue:work` in a second terminal.

## 1. The gates

```bash
dev exec -- composer test             # lint, Larastan level 8, arch, Unit + Feature + Contract
```

Narrower runs while iterating:

```bash
dev exec -- vendor/bin/pest tests/Contract/Api
dev exec -- vendor/bin/pest tests/Contract/Identity tests/Feature/Identity tests/Unit/Identity
dev exec -- vendor/bin/pest tests/Feature/Admin/OperatorNotificationsTest.php
```

## 2. A manual walk through the stories

The client headers go on every call: `-H 'Accept: application/json' -H 'Content-Type: application/json' -H 'X-Client-Platform: android' -H 'X-Client-Version: 1.0.0'`. Below, `$H` stands for them, and `$API` for `http://saveme-backend.test/api/v1`. The transport check is off only in `local` (see the runtime contract).

| # | Do | Expect |
|---|---|---|
| 1 | `POST $API/auth/register` `{email: "a@example.test", password: "correct horse"}` | `202`. Mailpit shows a welcome mail with a verify link |
| 2 | Repeat 1 | `409 EMAIL_ALREADY_REGISTERED`, and no mail |
| 3 | `POST $API/auth/login` with a `device` (new `session_id` UUIDv7) | `200` with `credential`, `account.email_verified = false`, `entitlements.plan` |
| 4 | `GET $API/auth/sessions` with `Authorization: Bearer <credential>` | one session, `current: true` |
| 5 | `POST $API/auth/verify-email` `{token}` from the mail, twice | `200`, then `400 VERIFICATION_TOKEN_INVALID` |
| 6 | Sign in again from a second `device_id`, then `DELETE $API/auth/sessions/{first id}` from the second | the first credential gets `401` on its very next call |
| 7 | Six wrong passwords in a row | the 6th gets `423 ACCOUNT_LOCKED` with `Retry-After` of about 900. Do the same for an unregistered address and get the same result |
| 8 | `POST $API/auth/forgot-password`, then `reset-password` with the mailed token | `202`, then `204`. All sessions gone, lock cleared |
| 9 | `POST $API/auth/change-password` from device A while B is signed in | `204`. B gets `401`, A keeps working, and a "password changed" mail arrives |
| 10 | `POST $API/account/deletion-request` `{password}`, twice | the same `deletion_scheduled_for` both times, and a "deletion scheduled" mail |
| 11 | Keep using the existing session: `GET $API/auth/sessions` | works. The account is still `pending_deletion` |
| 12 | Sign in from a new session | `200` with `deletion_cancelled: true` and `account.state = active`. The person gets a "cancelled by sign-in" mail, and ops@example.test gets the operator mail. `select * from operator_notifications` shows one row |
| 13 | Redeliver the event (retry the failed job, or dispatch it again in a test) | still one row, and no second operator mail |

## 3. Checks outside the suite

- **Timing (SC-003).** `dev exec -- php artisan identity:timing-probe --trials=1000` (a local-only command, not registered in production) signs in 1,000 times with a wrong password and 1,000 times with an unknown address, at the production argon2id cost. It prints the medians and fails if they differ by 10% or more.
- **Nothing readable at rest (SC-006).** After a full `composer test:suite` run against the local database (not refreshed), grep the database dump, `storage/logs`, and the Mailpit API for the test addresses and passwords. Only mail bodies may contain an address, and only the recipient's own.
- **Audit append-only.** `update audit_entries set event = 'x'` in `psql` fails with the trigger's message.

## 4. Operations notes

- **Rotating the key-encryption key**: add a `kid` to `IDENTITY_KEYS`, point `IDENTITY_ACTIVE_KEY` at it, and run `dev identity:rewrap-keys`, which re-wraps each `user_keys` row. Remove the old `kid` once the command reports 0 rows left on it.
- **Rotating the lookup key**: the baseline's four steps (new column, backfill, swap the unique index, drop). No command ships now. It is a planned migration when needed.
- **Sessions sweep**: `identity:expire-sessions` runs daily from the scheduler.

## Verification (2026-09-26)

- **Gate**: `dev exec -- composer test` is green. Pint passes, Larastan level 8 reports no errors, the architecture suite has 46 tests, and the test suite has 400 tests (1,600 assertions).
- **Manual walk (§2)**: steps 1–5 and 10–12 were run against `http://saveme-backend.test` with real mail through Mailpit.
  - Registering an address twice returned identical `202` bodies, and both mails were delivered. This was superseded on 2026-09-27: a repeat now returns `409 EMAIL_ALREADY_REGISTERED` and sends no mail.
  - The verify link worked once; replaying it returned `400 VERIFICATION_TOKEN_INVALID`.
  - A deletion request returned a date 30 days out. The existing session kept working.
  - A new sign-in returned `deletion_cancelled: true` and `state: active`. It produced the person's mail, exactly one `operator_notifications` row (identifiers only), and one mail to `ops@example.test`.
  - Steps 6–9 and 13 are covered by the Feature and Contract suites.
- **Timing (SC-003)**: `identity:timing-probe --trials=40` at the production argon2id cost gave a median of 243.13 ms for a wrong password and 242.37 ms for an unknown address, a 0.3% difference. That is just under the baseline's 250–500 ms target on this laptop, so re-measure on the deployment hardware and raise `HASH_ARGON_*` there.
- **At rest (SC-006)**: after the walk, the Identity, Admin and queue tables and `storage/logs/laravel.log` had 0 occurrences of the walk address or its password.
- **Operations note**: a queue worker that was already running in the `dev` environment still had pre-feature code loaded, and failed the first mail jobs until `dev queue:restart`. Restart workers after deploying this feature.

### Where the build differs from the plan

- **Admin ports and DTOs**: Admin's port and notification view DTO live in `Admin/Domain` (`Ports/OperatorNotificationStore`, `Notifications/OperatorNotificationView`), not `Admin/Application`. The P-02 gate refuses Infrastructure → Application.
- **Console commands**: `identity:expire-sessions` and `identity:timing-probe` live in `Identity/Http/Console`, because they call Application services. `identity:rewrap-keys` lives in `Identity/Infrastructure/Console`.
- **API fallback**: `App\Http\Api\ApiFallback` answers every method under `/api/v1`, with `404`, or `405` plus `Allow`.
- **Collection parameters**: `App\Http\Api\Collections\CollectionQuery` implements the shared collection grammar.
- **Spectator limitation**: Spectator 3 cannot validate a request that carries the shared deepObject `filter[...]` parameter, or a fallback-routed response. Those Contract tests call `withoutSpectatorRequestValidation()` and assert on status and code directly (see `tests/Pest.php`).
- **Trusted proxies**: not configured yet. Behind a TLS terminator, `API_REQUIRE_TLS` needs trusted proxies, so the deployment-target feature must set them.
