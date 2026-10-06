# Contract: Devices & Push API (spec 023)

The machine-readable contract is `docs/openapi/paths/notifications.yaml` (`info.version` v1.14). This page states what the C16 client binds to. Do not restate shapes elsewhere.

## Operations

| Method | Path | Answer |
|---|---|---|
| PUT | `/api/v1/me/devices/{id}` | `200` PushRegistration · `404` (not the caller's current session) · `422` · `429` |
| DELETE | `/api/v1/me/devices/{id}` | `204` (also when nothing was registered) · `404` · `429` |
| GET | `/api/v1/me/notification-preferences` | `200` NotificationPreferences (defaults at `version: 1` until first save) |
| PUT | `/api/v1/me/notification-preferences` | `200` NotificationPreferences · `409 VERSION_CONFLICT` + `current` · `422` · `429` |

`{id}` is the caller's own current device session id (the `session_id` the app generated at sign-in). Body of the device PUT: `{"push_token": "<FCM registration token>"}` only.

## Client behaviour

- Register after every sign-in and on every `onNewToken`; re-register on app start when a token exists (keeps `refreshed_at` inside 270 days).
- Signing out needs no extra call: the server drops the registration when the session ends. Call `DELETE` only when the person turns notifications off for this phone.
- Preferences: read when the settings screen opens; save the whole object with the version read; on `409`, show `current` and let the person re-apply.
- Android channels: create one channel per category id (`recurring_due`, `budget_exceeded`, `sharing`, `billing`, `export_ready`, `deletion_reminder`). The server sets `android.notification.channel_id` to the category.
- Quiet hours are applied by the server. The app does nothing at quiet time.

## Push payload (`PushPayload`, data block; all values strings)

Common keys: `schema` (`"1"`), `type`, `category`, `notification_id`. Then exactly:

| `type` | extra keys | collapse key | open |
|---|---|---|---|
| `occurrence_due` | `wallet_id`, `occurrence_id` | `occurrence:{occurrence_id}` | the occurrence |
| `occurrence_auto_confirm_refused` | `wallet_id`, `occurrence_id` | `occurrence:{occurrence_id}` | the occurrence |
| `occurrences_due_summary` | — | `occurrences_due` | upcoming items |
| `budget_exceeded` | `budget_id` | `budget:{budget_id}` | the budget |
| `invitation_received` | `invitation_id` | `invitation:{invitation_id}` | "an invitation is waiting in your email" notice |
| `member_role_changed` | `wallet_id`, `role` | `wallet:{wallet_id}` | the wallet |
| `member_removed` | `wallet_id` | `wallet:{wallet_id}` | wallet list |
| `wallet_ownership_received` | `wallet_id` | `wallet:{wallet_id}` | the wallet |
| `subscription_ending` | — | `subscription` | plan screen |
| `subscription_lapsed` | — | `subscription` | plan screen |
| `export_ready` | `export_id` | `export:{export_id}` | the export (`GET /exports/{id}`) |
| `deletion_reminder` | — | `deletion` | account deletion screen |

The visible notification block carries generic, server-localised text in the profile language (never a name, amount or date). Unknown `type`: ignore. De-duplicate on `notification_id`. A deep link to a record the phone has not synced yet should trigger a sync of that scope first.

## Guarantees

- No amount, balance, currency, name (wallet, category, person), note, address, date of money, token or URL in any payload or text.
- At most one message per subject (dedupe key); budget alerts once per budget per period.
- At most 10 per hour and 30 per day per person; beyond that, messages are dropped, not delayed.
- A session that ended receives nothing more, even before its registration is removed.
