# Data Model: Identity & Authentication

**Feature**: [spec.md](./spec.md) · **Research**: [research.md](./research.md)

All tables are PostgreSQL 16. Primary keys are UUIDv7 (P-06), and instants are `timestamptz` in UTC (P-12). Migrations live in `app/Modules/<Module>/Infrastructure/Database/Migrations`.

---

## Identity module

### `users`: User (aggregate root)

| Column | Type | Rules |
|---|---|---|
| `id` | uuid PK | UUIDv7, server-generated |
| `email_ciphertext` | text | sealed with the user's data key (R-6). Never null while `state ≠ purged` |
| `email_lookup` | char(64) UNIQUE | hex HMAC-SHA256 of the normalised address (R-7) |
| `password_hash` | text | argon2id (R-5). Never selected into any response |
| `email_verified_at` | timestamptz null | set once, by address verification |
| `state` | varchar(20) + CHECK | `active` \| `pending_deletion` \| `purged` |
| `deletion_requested_at` | timestamptz null | set only in `pending_deletion` |
| `deletion_scheduled_for` | timestamptz null | `deletion_requested_at + 30 days`. Set only in `pending_deletion` |
| `created_at`, `updated_at` | timestamptz | |

CHECK: `(state = 'pending_deletion') = (deletion_requested_at IS NOT NULL AND deletion_scheduled_for IS NOT NULL)`.

**State transitions**

```text
active ──request deletion (password ok)──▶ pending_deletion
pending_deletion ──cancel (explicit) | successful sign-in──▶ active
pending_deletion ──purge at/after deletion_scheduled_for (B15)──▶ purged
```

User is **not** syncable (glossary).

### `user_keys`: per-account key material

| Column | Type | Rules |
|---|---|---|
| `user_id` | uuid PK, FK → users ON DELETE CASCADE | one per user |
| `wrapped_key` | text | a 32-byte data key sealed by the key-encryption key `kid` |
| `kid` | varchar(32) | an identifier in `IDENTITY_KEYS` (baseline D4) |
| `created_at`, `updated_at` | timestamptz | `updated_at` moves on re-wrap during a key rotation |

Destroying this row is B15's purge step that makes backups unreadable for this person.

### `profiles`: Profile (child of User, syncable, user scope)

| Column | Type | Rules |
|---|---|---|
| `id` | uuid PK | UUIDv7 |
| `user_id` | uuid UNIQUE FK → users | the scope key |
| `locale` | varchar(2) + CHECK | `ar` \| `en` |
| `timezone` | varchar(64) | IANA identifier (R-23) |
| `display_name_ciphertext` | text null | sealed per R-6 |
| `version` | integer | starts at 1 |
| `change_seq` | bigint | from `sync_change_seq` (R-19) |
| `created_at`, `updated_at` | timestamptz | |
| `deleted_at` | timestamptz null | always null here. B2 and B15 own any change |

Created in the registration transaction. Its fields and editing belong to B2.

### `device_sessions`: Device Session (child of User, syncable, user scope; also the Sanctum token model, R-2)

| Column | Type | Rules |
|---|---|---|
| `id` | uuid PK | the **client-generated** `session_id` (R-4) |
| `user_id` | uuid FK → users | the scope key. Also Sanctum's `tokenable` |
| `token` | char(64) UNIQUE | SHA-256 of the secret. The column name is Sanctum's |
| `abilities` | jsonb | `["*"]`. Unused, and kept for Sanctum's model |
| `device_id` | varchar(64) | the client's stable installation id. Never exposed |
| `device_name` | varchar(100) | shown to the owner |
| `platform` | varchar(16) + CHECK | `android` |
| `app_version` | varchar(32) | semver |
| `state` | varchar(10) + CHECK | `active` \| `revoked` \| `expired` |
| `end_reason` | varchar(20) null + CHECK | `signed_out` \| `ended_by_owner` \| `replaced` \| `password_changed` \| `password_reset` \| `expired` |
| `signed_in_at` | timestamptz | refreshed on rotation |
| `last_used_at` | timestamptz null | Sanctum's write. It does **not** bump `version` (R-3) |
| `ended_at` | timestamptz null | set with `state ≠ active` |
| `expires_at` | timestamptz null | Sanctum's column. Always null (R-1) |
| `version` | integer | bumped on create, rotate, revoke and expire |
| `change_seq` | bigint | as `version` |
| `created_at`, `updated_at`, `deleted_at` | timestamptz | `deleted_at` is always null. Sessions are never tombstoned |

Indexes: a partial UNIQUE on `(user_id, device_id) WHERE state = 'active'`; `(user_id, signed_in_at DESC)`.

CHECK: `(state = 'active') = (ended_at IS NULL AND end_reason IS NULL)`.

**State transitions**

```text
(none) ──sign-in, new session_id──▶ active
active ──sign-in, same session_id──▶ active            (credential rotated)
active ──sign-out | end by owner | end all others | replaced | password change | password reset──▶ revoked
active ──90 days without use (guard refuses at once; sweep records it)──▶ expired
```

`revoked` and `expired` are terminal.

### `identity_links`: address verification and password reset links (auth layer, not a domain entity)

| Column | Type | Rules |
|---|---|---|
| `id` | uuid PK | |
| `user_id` | uuid FK → users ON DELETE CASCADE | |
| `kind` | varchar(20) + CHECK | `verify_address` \| `reset_password` |
| `secret_hash` | char(64) UNIQUE | SHA-256 of a 256-bit secret |
| `expires_at` | timestamptz | verify: +24 h. Reset: +60 min |
| `used_at` | timestamptz null | |
| `superseded_at` | timestamptz null | set by a later link of the same kind, and for reset links also by a password change |
| `created_at` | timestamptz | |

A link is **live** if and only if `used_at IS NULL AND superseded_at IS NULL AND expires_at > now()`.

### `sign_in_failures`: account lockout counter (auth layer)

| Column | Type | Rules |
|---|---|---|
| `lookup` | char(64) PK | the address lookup value. It exists for unregistered addresses too (FR-041) |
| `consecutive_failures` | integer | |
| `locked_until` | timestamptz null | `≤ last_failed_at + 60 min` |
| `last_failed_at` | timestamptz | |

A sign-in success or a completed reset deletes the row. The per-origin counter lives in the cache store's rate limiter, not here (R-9).

### `audit_entries`: Audit Entry (aggregate root, not synced, operator-read)

The baseline's field table, verbatim:

| Column | Type | Rules |
|---|---|---|
| `id` | uuid PK | |
| `occurred_at` | timestamptz | |
| `event` | varchar(40) + CHECK | the baseline vocabulary (`AuditEvent` enum) |
| `actor_type` | varchar(10) + CHECK | `user` \| `operator` \| `system` |
| `actor_id` | uuid null | null only for `system`, and for a failed sign-in on an unknown address (FR-062) |
| `subject_type` | varchar(15) + CHECK | `user` \| `wallet` \| `session` \| `subscription` |
| `subject_id` | uuid null | null only for `sign_in_failed` and `lockout_applied` on an unknown address. **Baseline addition, see below** |
| `request_id` | uuid null | null for scheduled work |
| `origin` | varchar(64) null | coarsened IP (R-12) |
| `detail` | jsonb | per-event DTO, never containing an exclusion-list value |

Indexes: `(subject_type, subject_id, occurred_at)`, `(event, occurred_at)`.

A trigger refuses `UPDATE`, and refuses `DELETE` where `occurred_at > now() - interval '400 days'`.

**Baseline addition**: the baseline types `subject_id` as non-null, but a failed sign-in or a lock on an **unregistered** address has no subject, and inventing one would itself be a record of a non-person. This change amends the baseline's entry table so that `subject_id` may be null for those two events only. `detail.lookup_prefix` then carries the first 8 hex characters of the lookup value, enough to group attempts and not reversible.

**Events this feature writes**: `sign_in_succeeded`, `sign_in_failed`, `lockout_applied`, `session_issued`, `session_revoked`, `password_changed`, `address_verified`, `deletion_requested`, `deletion_cancelled` (`detail.reason`: `explicit` | `sign_in`).

---

## Admin module

### `operator_notifications`: Operator Notification (new glossary entry)

| Column | Type | Rules |
|---|---|---|
| `id` | uuid PK | |
| `kind` | varchar(40) + CHECK | `deletion_cancelled_by_sign_in` (the first kind) |
| `subject_user_id` | uuid | an identifier only, never an address |
| `occurred_at` | timestamptz | when the underlying act happened |
| `payload` | jsonb | scalars only (for example `session_id`) |
| `source_event_id` | uuid UNIQUE | the event's `event_id`, which makes the listener idempotent (R-14) |
| `created_at` | timestamptz | |

Single-state, and append-only in practice. Per-operator read state arrives with operator accounts (D2).

---

## Billing module (provisional, replaced by B3)

No tables. `ProvisionalFreePlan` implements `SubscriptionService` and `EntitlementService` in memory (R-11).

---

## Protected-attributes table: rows added to the baseline in this change

| Attribute | Protection at rest | Searchable | Who can read it |
|---|---|---|---|
| Device name, platform, app version | none | no | the account owner |
| Device installation identifier | none | by owner's sessions only | nobody through any endpoint |
| Sign-in failure counter | none (keyed by lookup value) | by lookup value | nobody through any endpoint |
| Operator notification | none, carrying identifiers only | yes | operators |

## Configuration introduced

| Key | Source | Default |
|---|---|---|
| `identity.keys`, `identity.active_key` | `IDENTITY_KEYS`, `IDENTITY_ACTIVE_KEY` | none. Required |
| `identity.lookup_key` | `IDENTITY_LOOKUP_KEY` | none. Required |
| `identity.lockout.*` | config | 5/15 min, 10/60 min, origin 20 per 15 min |
| `identity.throttle.*` | config | register 10/h per origin; resend and reset 3/h per address |
| `identity.links.verify_url`, `reset_url` | `IDENTITY_VERIFY_URL`, `IDENTITY_RESET_URL` | local placeholders |
| `identity.default_timezone` | config | `Africa/Cairo` |
| `identity.google.enabled` | `IDENTITY_GOOGLE_ENABLED` | `false` |
| `hashing.argon.*` | `HASH_ARGON_MEMORY/TIME/THREADS` | 65536 / 4 / 1 (tests 1024 / 1 / 1) |
| `admin.notifications.recipients` | `ADMIN_NOTIFICATION_RECIPIENTS` | empty (email skipped, row still stored) |
| `api.minimum_client_version.android` | config | `1.0.0` |
