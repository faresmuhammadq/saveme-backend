# Data Model: Billing — Google Play

**Feature**: [spec.md](./spec.md) · **Research**: [research.md](./research.md)

All tables in `app/Modules/Billing/Infrastructure/Database/Migrations`, PostgreSQL 16. Ids UUIDv7 (P-06); instants `timestamptz(6)` UTC (P-12). `user_id` carries no foreign key (users belong to Identity, P-02), as in `subscriptions`. No money column (P-03): the reference Play price lives in `plans.prices` (integer minor units) as before.

## play_account_references

The Play Account Reference (glossary): the obfuscated account id.

| Column | Type | Rules |
|---|---|---|
| `user_id` | uuid PK | one per account |
| `reference` | char(64) | UNIQUE; `hex(HMAC-SHA256(key[kid], "play-account:" ‖ user_id))` |
| `key_id` | varchar(32) | the kid of the key that produced it |
| `created_at` | timestamptz | |

Created on first need (`INSERT … ON CONFLICT (user_id) DO NOTHING`, re-read). Deleted at the account purge. Never logged; returned only to its own account.

## play_purchases

The Play Purchase (glossary): one purchase token known to this service.

| Column | Type | Rules |
|---|---|---|
| `id` | uuid PK | also the backing subscription's `provider_ref` |
| `token_lookup` | char(64) | UNIQUE; hex HMAC-SHA256 of the token under the Billing lookup key |
| `token_sealed` | text null | `BillingSealer` (`v1.{kid}.{b64}`, AD `play_purchase:{id}:token`); NULL after the account purge |
| `kind` | varchar(12) | CHECK in (`subscription`, `one_time`) |
| `product_id` | varchar(150) | as Google reports it |
| `user_id` | uuid null | the linked account; set once, never changed (`UPDATE … WHERE user_id IS NULL`) |
| `linked_at` | timestamptz null | |
| `subscription_id` | uuid null | FK `subscriptions(id)`; set when first granted |
| `obfuscated_ref` | char(64) null | the reference Google returned, if any |
| `replaces_lookup` | char(64) null | lookup of Google's `linkedPurchaseToken` |
| `play_state` | varchar(40) | Google's last state: `pending`, `active`, `grace`, `canceled`, `on_hold`, `paused`, `expired`, `revoked`, `pending_canceled` |
| `acknowledged_at` | timestamptz null | when Google confirmed the acknowledgement (or reported it already acknowledged) |
| `is_test` | boolean | license tester purchase |
| `base_plan_id` | varchar(64) null | from the line item; informational |
| `purchased_at` | timestamptz | Google's start / purchase time |
| `synced_at` | timestamptz null | last successful read from Google |
| `unreachable_since` | timestamptz null | first failed read since the last success |
| `ended_by_account_purge_at` | timestamptz null | stamped at the purge, deleted 400 days later with the subscription rows |
| `created_at`, `updated_at` | timestamptz | |

Indexes: `(user_id)`, `(subscription_id)`, `(acknowledged_at) WHERE acknowledged_at IS NULL AND user_id IS NOT NULL`, `(purchased_at) WHERE user_id IS NULL`, `(replaces_lookup)`.

Invariants: `user_id` set ⇒ `linked_at` set; `subscription_id` set ⇒ `user_id` set; a row with `user_id` NULL is never acknowledged; one row per token (unique lookup).

Retention: unlinked rows deleted 10 days after `purchased_at`; linked rows live with their subscription; purge-stamped rows deleted at 400 days.

## play_notifications

The Play Notification inbox (glossary).

| Column | Type | Rules |
|---|---|---|
| `id` | uuid PK | |
| `message_id` | varchar(128) | UNIQUE — Pub/Sub `messageId` (replay guard, kept 30 days ≥ the baseline's 24 h) |
| `kind` | varchar(16) | CHECK in (`subscription`, `one_time`, `voided`, `test`, `malformed`) |
| `notification_type` | smallint null | Google's numeric type (subscription 1–13, 20; one-time 1–2); voided: `refund_type` |
| `product_id` | varchar(150) null | |
| `token_lookup` | char(64) null | |
| `token_sealed` | text null | erased when the row is terminal |
| `event_at` | timestamptz null | `eventTimeMillis` |
| `published_at` | timestamptz null | Pub/Sub `publishTime` |
| `received_at` | timestamptz | |
| `state` | varchar(10) | CHECK in (`received`, `processed`, `ignored`, `failed`) |
| `ignored_reason` | varchar(24) null | `test`, `foreign_subscription`, `foreign_package`, `malformed`, `purged_account` |
| `attempts` | smallint | |
| `updated_at` | timestamptz | |

Indexes: `(received_at)`, `(state, received_at)`, `(token_lookup)`. Rows deleted 30 days after `received_at`.

## subscriptions (existing, spec 009) — no new column

For `provider = google_play`: `provider_ref` = `play_purchases.id`; `current_period_end` NULL for a one-time (lifetime) product; `grace_until` is Google's grace end (never `billing.grace_days`); `auto_renew` follows Google; `source_meta` = `{"test": bool, "base_plan_id": string|null}`. The status machine is ADR-0013's; Google states map per research R-5. `LapseSubscriptions` excludes `google_play`.

## plans.prices (existing) — new entries only

`{"channel": "google_play", "product_id": "plus_monthly", "amount_minor": <int>, "currency": "EGP"}` appended by the seeder from configuration (create-missing). A `product_id` appears on at most one plan (seeder validation).

## State machines

**Play Purchase link**: `unlinked` → `linked` (final). Never unlinked, never re-linked.

**Play Notification**: `received` → `processed` | `failed`; verified-but-irrelevant pushes are inserted directly as `ignored`.

**Subscription (google_play)** — ADR-0013 rule 4 arcs only:

```text
(new) ──ACTIVE──▶ active
active ──IN_GRACE──▶ grace ──ACTIVE/RECOVERED──▶ active
active ──ON_HOLD/PAUSED/EXPIRED──▶ grace(grace_until = now) ──▶ expired     (one transaction, one SubscriptionLapsed)
grace ──ON_HOLD/PAUSED/EXPIRED──▶ expired
expired ──RECOVERED/RESTARTED/RESUMED──▶ active
active/grace ──REVOKED/VOIDED──▶ revoked (terminal)
```

## Configuration (`config/billing.php` → `google_play`)

| Key | Env | Default |
|---|---|---|
| `driver` | `BILLING_PLAY_DRIVER` | `disabled` (`.env.example`: `fake`) |
| `credentials` | `BILLING_PLAY_CREDENTIALS` | none — path or base64 service-account JSON |
| `package_name` | `BILLING_PLAY_PACKAGE` | none |
| `account_reference_keys`, `account_reference_active_key` | `BILLING_PLAY_ACCOUNT_KEYS`, `BILLING_PLAY_ACCOUNT_ACTIVE_KEY` | none |
| `lookup_key` | `BILLING_PLAY_LOOKUP_KEY` | none (token lookup HMAC) |
| `push.audience` | `BILLING_PLAY_PUSH_AUDIENCE` | none |
| `push.service_account` | `BILLING_PLAY_PUSH_SERVICE_ACCOUNT` | none |
| `push.subscription` | `BILLING_PLAY_PUSH_SUBSCRIPTION` | none |
| `push.certs_url` | — | `https://www.googleapis.com/oauth2/v1/certs` |
| `products.plus_monthly` / `products.plus_yearly` | `BILLING_PLAY_PRODUCT_PLUS_MONTHLY`, `…_YEARLY`, `BILLING_PLAY_PRICE_PLUS_MONTHLY`, `…_YEARLY`, `BILLING_PLAY_CURRENCY` | ids `plus_monthly`, `plus_yearly`; amounts null; `EGP` |
| `fake_script` | `BILLING_PLAY_FAKE_SCRIPT` | none (local only) |
| `throttle.verify_per_hour`, `throttle.restore_per_hour` | — | 20, 10 |
| `unreachable_lapse_hours`, `ack_alert_hours`, `unlinked_keep_days`, `inbox_keep_days` | — | 72, 48, 10, 30 |

## Security baseline rows (added in this change)

| Attribute | At rest | Searchable | Readable by |
|---|---|---|---|
| Purchase token | sealed | by keyed lookup value | nobody (the server, to call Google) |
| Play Account Reference | keyed hash | by value | its own account |
| Play Notification | ids and states; token sealed until processed | by message id | operators as counts only |
