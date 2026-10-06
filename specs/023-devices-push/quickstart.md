# Quickstart: Devices & Push Notifications (spec 023)

A validation run that proves the feature end to end on the local stack with the `log` gateway. Shapes: [contracts/push-api.md](./contracts/push-api.md); tables: [data-model.md](./data-model.md).

## Prerequisites

```bash
dev project open
dev migrate
# .env: NOTIFICATIONS_PUSH_DRIVER=log, NOTIFICATIONS_KEYS / NOTIFICATIONS_ACTIVE_KEY / NOTIFICATIONS_LOOKUP_KEY set (see .env.example)
dev queue:work --queue=notifications,default   # in a second terminal
```

Headers on every API call: `Accept: application/json`, `X-Client-Platform: android`, `X-Client-Version: 1.0.0`, `Authorization: Bearer <token>`.

## 1. Register a device (US1)

1. Register and sign in two accounts (spec 007); keep each `session_id` and bearer.
2. `PUT /api/v1/me/devices/{session_id}` with `{"push_token":"local-test-token-A"}` → `200`, body has `session_id`, `registered_at`, `refreshed_at`, **no token**.
3. Same call with another account's session id → `404`. With an unknown UUIDv7 → `404`.
4. `{"push_token":"x","platform":"android"}` → `422`, `UNKNOWN_FIELD` at `/platform`.
5. Database: `push_registrations.token_sealed` starts with `v1.` and does not contain `local-test-token-A`.
6. Account B registers `local-test-token-A` for its own session → `200`; account A's row is gone.
7. `POST /api/v1/auth/logout` as B; after the queue runs, B's registration is gone.

## 2. Preferences (US2)

1. `GET /api/v1/me/notification-preferences` → defaults, `version: 1`, `updated_at: null`.
2. `PUT` with `version: 1`, `budget_exceeded: false`, quiet hours 23:00–07:00 → `200`, `version: 2`.
3. Repeat the same body with `version: 1` → `409 VERSION_CONFLICT`, `current.version = 2`.
4. `start: "23:10"` → `422 OUT_OF_RANGE` at `/quiet_hours/start`.

## 3. Reminders and events (US3–US6)

1. Create a wallet and a monthly rule with `auto_confirm: false` whose next occurrence is today; run `dev notifications:occurrence-reminders` after 09:00 Cairo (or `travelTo` in a test). The log shows one `push_logged` with `type: occurrence_due` and the two ids, nothing else; `push_notifications` has one `sent` row. Run again: nothing new.
2. Create a budget (limit 100.00 EGP → `amount_minor` 10000) and record expenses of 6000 and 5000 minor units → one `budget_overruns` row and one `budget_exceeded` notification; a third expense → nothing new.
3. Invite account B's verified address by email to a wallet → B gets `invitation_received` with `invitation_id` only. A link invitation → nothing.
4. Request an export, let it finish → `export_ready` with `export_id`.

## 4. Quiet hours and limits

1. Set quiet hours to cover now; trigger any notification → row `held` with `not_before` at the end; `dev notifications:release-held` after that instant (or in a test with `travelTo`) → `sent`.
2. Turn a category off; trigger it → `skipped` / `category_off`.

## 5. Gateway safety

1. `APP_ENV=production NOTIFICATIONS_PUSH_DRIVER=log dev about` → refuses to boot with the log-gateway message.
2. `NOTIFICATIONS_PUSH_DRIVER=fcm` without credentials → refuses to boot.
3. `grep -R "local-test-token-A" storage/logs` → no match.

## 6. Operators

Open `/admin` overview → "Push, last 24 hours" counts; open account A → "Devices that can receive notifications: 1". No token, text or list.

## 7. Gates

```bash
dev exec -- vendor/bin/pest tests/Contract/OpenApi
dev exec -- vendor/bin/pest tests/Unit/Notifications tests/Feature/Notifications tests/Contract/Notifications tests/Feature/Budgeting
dev exec -- composer test
```
