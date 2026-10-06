# Data Model: Devices & Push Notifications

**Feature**: [spec.md](./spec.md) · **Research**: [research.md](./research.md)

PostgreSQL 16. Primary keys UUIDv7 (P-06), instants `timestamptz` UTC (P-12). Migrations in `app/Modules/<Module>/Infrastructure/Database/Migrations`. No float, decimal or money column. None of these entities is syncable.

---

## Notifications module

### `push_registrations`: Push Registration (aggregate root)

| Column | Type | Rules |
|---|---|---|
| `id` | uuid PK | UUIDv7, server-generated |
| `user_id` | uuid | the owner; no FK across modules (P-02); index |
| `session_id` | uuid UNIQUE | Identity's device session id; one registration per session |
| `token_sealed` | text | `v1.{kid}.{base64(nonce‖ciphertext)}`, XChaCha20-Poly1305, AD `push_registration:{session_id}` (R-4). Never selected into a response |
| `token_lookup` | char(64) UNIQUE | hex HMAC-SHA256 of the token under `NOTIFICATIONS_LOOKUP_KEY` |
| `registered_at` | timestamptz | first registration of this session |
| `refreshed_at` | timestamptz | last `PUT`; index (prune sweep) |
| `created_at`, `updated_at` | timestamptz | |

Lifecycle: created by `PUT`; token replaced on rotation; **hard-deleted** on `DELETE`, session end, invalid token, 270 days without refresh, purge. `single-state`.

### `notification_preferences`: Notification Preferences (aggregate root)

| Column | Type | Rules |
|---|---|---|
| `user_id` | uuid PK | one per person |
| `recurring_due`, `budget_exceeded`, `sharing`, `billing`, `export_ready`, `deletion_reminder` | boolean | default `true` |
| `quiet_hours_enabled` | boolean | default `true` |
| `quiet_hours_start` | smallint | minutes since midnight, default 1320 (22:00); CHECK `0 ≤ v ≤ 1425 AND v % 15 = 0` |
| `quiet_hours_end` | smallint | default 480 (08:00); same CHECK |
| `version` | integer | ≥ 2 once a row exists (version 1 is the unsaved defaults) |
| `created_at` | timestamptz | |
| `updated_at` | timestamptz | the last save |

CHECK `quiet_hours_start <> quiet_hours_end`. A missing row means the defaults at version 1 (R-6). `single-state`.

### `push_notifications`: Push Notification (aggregate root)

| Column | Type | Rules |
|---|---|---|
| `id` | uuid PK | UUIDv7; also `notification_id` in the payload |
| `user_id` | uuid | recipient |
| `type` | varchar(40) + CHECK | the twelve `MessageType` values |
| `category` | varchar(20) + CHECK | the six categories |
| `dedupe_key` | varchar(160) | UNIQUE with `user_id` |
| `data` | jsonb | identifiers only, the keys allowed for `type` |
| `collapse_key` | varchar(80) | |
| `state` | varchar(10) + CHECK | `pending` \| `held` \| `sending` \| `sent` \| `skipped` \| `failed` |
| `skip_reason` | varchar(20) null + CHECK | `category_off` \| `no_device` \| `rate_limited` \| `collapsed` \| `inactive_account` \| `disabled` \| `summarised`; non-null iff `state = 'skipped'` |
| `not_before` | timestamptz null | set iff `state = 'held'` |
| `sending_since` | timestamptz null | set while `sending` |
| `sent_at` | timestamptz null | set iff `state = 'sent'` |
| `created_at`, `updated_at` | timestamptz | |

Indexes: `UNIQUE(user_id, dedupe_key)`; `(state, not_before)` partial `WHERE state IN ('held','sending')`; `(user_id, sent_at)` partial `WHERE sent_at IS NOT NULL` (rate limit); `(created_at)` (retention, figures).

**State transitions**

```text
(create) ─▶ pending ──policy: hold──▶ held ──release at not_before──▶ pending
pending ──job──▶ sending ──≥1 device accepted──▶ sent
sending ──no device accepted, nothing retryable──▶ failed
sending ──no live registration──▶ skipped(no_device)
pending | held ──policy──▶ skipped(category_off | rate_limited | collapsed | inactive_account | disabled)
(create, folded into a summary) ─▶ skipped(summarised)
sending (>15 min, worker died) ──sweep──▶ sending (job re-queued; sent deliveries kept)
```

`sent`, `skipped`, `failed` are terminal. Deleted 30 days after `created_at`.

**Dedupe keys**

| Source | Key |
|---|---|
| occurrence due | `occurrence:{occurrence_id}` |
| auto-confirm refused | `occurrence_refused:{occurrence_id}` |
| summary | `summary:{YYYY-MM-DDTHH}` (pass hour, UTC) |
| `BudgetExceeded` | `budget_exceeded:{budget_id}:{period_start}` |
| `InvitationSent` | `invitation:{invitation_id}` |
| `MemberRoleChanged`, `MemberRevoked`, `WalletOwnershipTransferred`, `SubscriptionLapsed` | `event:{event_id}` |
| `SubscriptionRenewalDue` | `renewal:{subscription_id}` |
| `ExportReady` | `export:{export_id}` |
| `DeletionReminderDue` | `deletion_reminder:{deletion_scheduled_for}` |

### `push_deliveries`: Push Delivery (child of Push Notification)

| Column | Type | Rules |
|---|---|---|
| `id` | uuid PK | |
| `notification_id` | uuid FK → push_notifications ON DELETE CASCADE | |
| `registration_id` | uuid | no FK: the registration may be deleted (pruned) |
| `state` | varchar(10) + CHECK | `pending` \| `sent` \| `pruned` \| `failed` |
| `attempts` | smallint | |
| `last_error` | varchar(64) null | provider error code only (e.g. `UNAVAILABLE`), never a body |
| `created_at`, `updated_at` | timestamptz | |

`UNIQUE(notification_id, registration_id)`; index `(state, updated_at)` for figures.

---

## Budgeting module

### `budget_overruns`: Budget Overrun

| Column | Type | Rules |
|---|---|---|
| `id` | uuid PK | |
| `budget_id` | uuid FK → budgets ON DELETE CASCADE | |
| `user_id` | uuid | the budget's owner |
| `period_start` | date | the Budget Period's first day (P-12 rule 2) |
| `threshold` | smallint + CHECK (`= 100`) | percent; only 100 in MVP |
| `crossed_at` | timestamptz | |

`UNIQUE(budget_id, period_start, threshold)`. `single-state`. Removed with its budget (the budget's tombstone purge and the account purge's `budgets` step delete budget rows, which cascades).

---

## Identity module (one CHECK change, no new table)

- `PurgeStep` gains `notifications` between `subscriptions` and `exports`; migration `…_allow_notifications_purge_step` replaces `account_purges_last_step_check` to include `'notifications'`.
- New Application query `Sessions\SessionStatus::activeAmong(string $userId, list<string> $sessionIds): list<string>`.
- New events `ExportReady`, `DeletionReminderDue`.

## Billing module (no table change)

- New event `SubscriptionRenewalDue`.

## Wallets module (no table change)

- `Recurring\OccurrenceReminderQuery` (Application) and `TransactionQueryService::authorAndCurrencyOf` (R-12, R-13); an index on occurrences only if the plan's EXPLAIN check shows a sequential scan.

## Sharing module (no table change)

- `InvitationQueryService::verifiedAddresseeOf` (R-14).

---

## Protected-attributes rows added to the baseline

| Attribute | Protection at rest | Searchable | Who can read it |
|---|---|---|---|
| Push token | encrypted under Notifications' keyring, with a keyed lookup value beside it | by lookup value only | nobody through any endpoint; decrypted only to send |
| Notification preferences | none | no | the account owner |
| Push notification and delivery records | none, identifiers and states only | yes | nobody through any endpoint; operators see counts only |
| Budget overrun | none, identifiers and a date only | yes | nobody through any endpoint |

## Retention rows added to the baseline

| What | Kept for |
|---|---|
| Push registration | until its session ends, or 270 days without a refresh |
| Push notification and delivery records | 30 days |
| Budget overrun | while its budget exists |

## Configuration introduced (`config/notifications.php`)

| Key | Source | Default |
|---|---|---|
| `push.driver` | `NOTIFICATIONS_PUSH_DRIVER` | `disabled` (`.env.example`: `log`) |
| `push.fcm.project_id` | `NOTIFICATIONS_FCM_PROJECT_ID` | none; required for `fcm` |
| `push.fcm.credentials` | `NOTIFICATIONS_FCM_CREDENTIALS` | none; path or base64 JSON; required for `fcm` |
| `push.fcm.timeout`, `connect_timeout` | config | 10 s, 5 s |
| `keys`, `active_key` | `NOTIFICATIONS_KEYS`, `NOTIFICATIONS_ACTIVE_KEY` | none; required |
| `lookup_key` | `NOTIFICATIONS_LOOKUP_KEY` | none; required |
| `rate.per_hour`, `rate.per_day` | config | 10, 30 |
| `summary_threshold` | config | 3 |
| `reminder_hour` | config | 9 |
| `registration_idle_days` | config | 270 |
| `retention_days` | config | 30 |
| `defaults.quiet_hours` | config | enabled, 22:00–08:00 |
