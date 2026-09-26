# Research: Identity & Authentication

**Feature**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Date**: 2026-09-26

Each entry records a decision, why it was taken, and what else was considered. Owner decisions carry the date they were given.

---

## R-1 Authentication package: Laravel Sanctum

**Decision**: `laravel/sanctum` `^4.3` (v4.3.3 supports Laravel 13). **Owner approval: 2026-09-26** ("use sanctum for auth"). Use it only as a **bearer-token guard**:

- `config/sanctum.php`: `stateful => []` and `guard => []`. No cookie or SPA mode, and no fallback to the `web` session guard, so an app credential is the only way in and a browser session never authenticates an API call.
- `expiration => null`. Expiry is inactivity-based, handled in R-3.
- Do **not** run `php artisan install:api`. It publishes a bigint `personal_access_tokens` migration and a `routes/api.php`, and we want neither (R-2, R-16).
- `Sanctum::usePersonalAccessTokenModel(DeviceSession::class)`, with Identity's own model and table.

**Rationale**: Sanctum's guard gives us what the baseline needs. It stores tokens as SHA-256, checks each request against the database with no cache, and has one hook (`authenticateAccessTokensUsing`) where the inactivity and revocation rules fit. It is first-party, small, and already expected by the OpenAPI skeleton's `bearerAuth` scheme.

**Alternatives**: Passport (OAuth2 server, far more than one-device-one-credential needs). An in-house guard (more code to own for no gain once Sanctum's model is replaceable).

## R-2 One table: the Device Session *is* the token record

**Decision**: `Identity\Infrastructure\Models\DeviceSession` extends `Laravel\Sanctum\PersonalAccessToken` on the table `device_sessions`. Its `tokenable()` relation is overridden to `belongsTo(User::class, 'user_id')`, so there are no polymorphic columns. It carries the device description, the lifecycle state, the end reason and the syncable members.

**Rationale**: two tables (a token plus a session pointing at it) would need to be kept in step on every revoke, and "which row is the truth" becomes a bug class. One row means revoking the session is revoking the credential. The glossary's rule that the token is not an entity still holds: the credential hash is a column, never exposed.

**Alternatives**: a separate `device_sessions` table with a `personal_access_token_id` foreign key. Rejected for the reason above.

## R-3 Revocation, inactivity and "no cache of validity"

**Decision**:

- Revoking sets `state = revoked`, `ended_at` and `end_reason`, and bumps `version` and `change_seq`. The row is **never deleted** (baseline: a revoked row survives for the audit trail).
- `Sanctum::authenticateAccessTokensUsing(fn ($session, $isValid) => $isValid && $session->isUsable(now()))`. `isUsable` is true when `state = active` and the last use (or sign-in) is within 90 days. Sanctum calls this before it records `last_used_at`, so the check sees the previous use.
- Sanctum's own `last_used_at` write extends the window. That write does **not** bump `version` or `change_seq`, so ordinary use does not flood the sync feed. Last-used time is shown in the list, but is not a synced change.
- A daily scheduled command, `identity:expire-sessions`, marks sessions idle for more than 90 days as `expired` (bumping `version` and `change_seq`), so the sync feed and the list show them as expired. An idle session is refused at the guard whether or not the sweep has run.

**Rationale**: every request reads the row, so a revocation is effective on the next request (baseline A3) with no cache anywhere.

## R-4 Client-generated session identifier and the credential format

**Decision**: the sign-in body carries `session_id` (UUIDv7, P-06 rule 2). The credential is `{session_id}|{secret}`, where `secret` is 64 random characters from `[A-Za-z0-9]` (about 381 bits, above the baseline's 256-bit floor) and only `sha256(secret)` is stored. That is the format Sanctum's `findToken` already parses (id lookup, then `hash_equals`). Issuance is Identity's own `SessionIssuer`, not `HasApiTokens::createToken()`, because Sanctum's default secret is `Str::random(40)` (about 238 bits), below the floor.

Sign-in behaviour:

| Situation | Effect |
|---|---|
| `session_id` new | Create the session. End any other **active** session with the same `(user, device_id)` as `replaced`. |
| `session_id` is this user's active session | Rotate: new secret, new hash, `signed_in_at` refreshed. The same row, and the old credential dies. |
| `session_id` belongs to another user, or is this user's ended session | `404 NOT_FOUND`. Nothing touched (FR-023). |

A partial unique index `(user_id, device_id) WHERE state = 'active'` makes "one active session per device" a database fact.

## R-5 Passwords

**Decision**: `config/hashing.php` uses `driver => argon2id`, with memory, time and threads from `HASH_ARGON_MEMORY` (65536), `HASH_ARGON_TIME` (4) and `HASH_ARGON_THREADS` (1). The pipeline and tests use lower values (1024, 1, 1), which is added as a row in the engineering conventions' environment table, next to `BCRYPT_ROUNDS`. On a successful sign-in, `Hash::needsRehash()` triggers a rehash. The argon2id build is confirmed in the `dev` image.

Policy (FR-006): 8 to 128 characters, not equal to the normalised address, and nothing else. The 128 cap stops pathological hashing input. A new password on change must differ from the current one (US7 scenario 4).

## R-6 Encryption at rest, with per-account key material

**Decision**: envelope encryption.

- Each user gets a random 256-bit **data key** at registration, stored in `user_keys` **wrapped** by a key-encryption key from a keyring (`IDENTITY_KEYS`, a JSON map `{kid: base64 key}`, plus `IDENTITY_ACTIVE_KEY`), and stored with that key's `kid`.
- The address and the display name are sealed with the data key using XChaCha20-Poly1305 (libsodium, AEAD), with a fresh 24-byte nonce per value and the user id as associated data. Stored format: `v1.{key_ref}.{base64(nonce‖ciphertext)}`.
- **Purge (B15)** deletes the `user_keys` row. After that, every ciphertext for that person, including ciphertext in a restored backup, is unreadable (baseline, Deletion rule 3).
- **Rotating the key-encryption key** re-wraps data keys only. No attribute is re-encrypted. A mixed state is valid, because each wrapped key names its `kid` (baseline D4).

**Rationale**: Laravel's `Crypt` is authenticated, but it stores no key identifier and has no per-person key to destroy, so it cannot deliver "an old backup cannot bring it back". Sodium ships with PHP, so there is no dependency.

**Alternatives**: `Crypt` with `APP_PREVIOUS_KEYS` (no key id, no per-account destruction). A KMS service (a deployment decision, not yet made. The keyring interface is the seam a KMS later sits behind).

## R-7 The address lookup value

**Decision**: `email_lookup = hex(HMAC-SHA256(normalise(address), IDENTITY_LOOKUP_KEY))`, unique-indexed. `normalise` is `trim` → `Normalizer::normalize(NFKC)` (`intl` is present) → `mb_strtolower`. No provider-specific rules. The key is separate from the keyring and lives in the environment, never in the database. The four-step lookup-key rotation in the baseline needs no code now. It is recorded as a runbook note in quickstart.

## R-8 Verification and reset links: own table, not the password broker

**Decision**: one table, `identity_links`, with `kind` (`verify_address` | `reset_password`), `secret_hash` (SHA-256 of 43 base64url characters, 256 bits), `expires_at`, `used_at` and `superseded_at`.

- Issuing a link supersedes every unused link of the same kind for that user.
- A password change supersedes every reset link.
- Redemption is a single conditional `UPDATE … WHERE used_at IS NULL AND superseded_at IS NULL AND expires_at > now()`, so two concurrent clicks cannot both succeed.

The mailed URL is `config('identity.links.verify_url')` or `reset_url` with `?token=`. These are the website or app deep-link pages that post the secret to the API.

**Rationale**: Laravel's broker keys its table on the plaintext email (we store none), uses bcrypt, and has no "superseded" semantics or verification kind.

## R-9 Lockout

**Decision**:

- **Account scope**: table `sign_in_failures (lookup PK, consecutive_failures, locked_until, last_failed_at)`, keyed by the **lookup value**, so an unregistered address counts and locks exactly like a registered one (FR-041). Thresholds come from `config/identity.php` (`5 → 15 min`, `10 → 60 min`, capped at 60). A success deletes the row. A completed reset deletes it (FR-031).
- **Origin scope**: Laravel `RateLimiter`, keyed by client IP, 20 failures per 15 minutes, on the cache store (Redis in production).
- **Refusal**: `423 ACCOUNT_LOCKED` with `Retry-After` in whole seconds. A request while locked is refused **before** the password is checked, and does not extend the lock.
- **Deletion and password change** check the same counter (FR-050, FR-035).

**Rationale**: account locks must survive a cache flush and be inspectable against PostgreSQL in Feature tests. Origin counting is high-volume and short-lived, which is what the rate limiter is for.

## R-10 Uniform answers and comparable timing

**Decision**:

- **Sign-in with an unknown address** runs `Hash::check` against a fixed dummy argon2id hash made with the current parameters. Both paths cost one hash verification.
- **Registration** (superseded 2026-09-27): an existing address is now refused with `409 EMAIL_ALREADY_REGISTERED` and sends nothing, so registration no longer equalises work. Only a new address hashes and queues the welcome mail.
- **Reset request** always dispatches one queued job, carrying the lookup value. The job sends a mail only if the account exists.
- **Tests**: Feature tests assert that the same work happens on both paths: one hash operation, one dispatched job, identical status, body and headers except `X-Request-Id`. The statistical timing check (SC-003) is a scripted run in quickstart, not a suite test, because 2,000 real argon2id hashes would slow the pipeline by minutes.

## R-11 The registration unit of work, and the Billing seam

**Decision**: `Identity\Application\Registration\RegisterAccount` runs inside one `DB::transaction`. It creates the user, the user key, the profile, and calls `Billing\Application\SubscriptionService::enrolInDefaultPlan($userId)`. Billing does not exist yet, so this feature creates, **in the Billing module**:

- `Application/SubscriptionService.php` (interface);
- `Application/Entitlements/EntitlementService.php` (interface, `summaryFor(string $userId): EntitlementSummary`);
- `Application/Entitlements/EntitlementSummary.php` (DTO: plan label key plus a feature map);
- `Application/Provisional/ProvisionalFreePlan.php`, implementing both: enrolling is a no-op, and the summary reports the Free plan with `sharing.enabled = false`. It is bound in `Application/BillingServiceProvider.php`.

B3 replaces the binding with the real, persisted implementation, and deletes `Provisional/`. No plan code is compared anywhere, and `PlanCode` is not used (P-09 gate).

**Rationale**: P-02 allows exactly this call (Application to another module's Application). The transaction holds from day one, so when B3 lands, the same boundary already covers the real insert.

**Alternatives**: a `UserRegistered` listener in Billing. Rejected because a queued listener leaves a window with no subscription, which B3 forbids ("every user always has exactly one effective subscription").

## R-12 Audit log

**Decision**: `Identity\Application\Audit\AuditLog::record(AuditRecord $record)`, the only writer. Its implementation is the only code touching the `audit_entries` table.

- `event` is the backed enum `Identity\Domain\Audit\AuditEvent`, holding the baseline's full vocabulary. A test pins it to the baseline table.
- `actor_type` and `subject_type` are enums.
- `detail` is `jsonb`, built from a closed per-event DTO, so a free-form array cannot smuggle an address in.
- `origin` is the client IP coarsened to a `/24` (IPv4) or a `/48` (IPv6).
- **Append-only** is enforced by PostgreSQL. A trigger raises on `UPDATE`, and on `DELETE` of any row younger than 400 days. The Sync purge (later) deletes only older rows.
- **Exactly one entry per act**: the record is written inside the same transaction as the act.

**Rationale**: a trigger makes append-only true for every code path, including a future one that forgets.

## R-13 Events

**Decision**: event classes live in `Identity\Domain\Events`, are `final readonly`, implement `ShouldDispatchAfterCommit`, and carry scalars only (P-11). Listeners are queued (`ShouldQueue`), and each has a deduplication key: the event carries an `event_id` UUIDv7, and the consumer records it or uses a natural unique key. The events are `UserRegistered`, `UserSignedIn`, `DeviceSessionEnded`, `UserDeletionRequested` and `UserDeletionCancelled`. The constitution's Identity row gains the three new ones as PATCH v1.3.1.

## R-14 Operator notifications: email and dashboard

**Decision** (owner, 2026-09-26: "notify admin via email and in dashboard notification"): the Admin module owns this. A queued listener, `Admin\Application\Listeners\NotifyOperatorsOfDeletionCancelledBySignIn`, reacts to `UserDeletionCancelled` when `reason = sign_in`:

1. **Dashboard**: it inserts one `operator_notifications` row (`kind = deletion_cancelled_by_sign_in`, `subject_user_id`, `occurred_at`, `payload` of scalars). It is unique on `source_event_id`, so a redelivered event adds nothing.
2. **Email**: it queues one `OperatorNotificationMail` to each address in `config('admin.notifications.recipients')` (`ADMIN_NOTIFICATION_RECIPIENTS`, comma-separated). It is sent only when the row was newly inserted, which makes the email idempotent as well.

Neither channel carries a readable address. Operators see the user id and look the user up through the audited back-office read (B12).

**Dashboard surface**: `Admin\Http\Livewire\OperatorNotificationBell` renders into the shell's existing `notifications` slot (spec 006). It shows an unread count and a panel listing the latest notifications. It reads them through `Admin\Application\OperatorNotificationQueryService`, which returns DTOs, and holds only scalars (P-18 rule 5).

**Visibility**: every `/admin` page except the local gallery waits for operator sign-in (D2, spec 006 FR-051). Until D2, notifications are stored and emailed but the bell is not reachable, and the gallery shows the bell's primitive with sample data only. Per-operator read state (`operator_notification_reads`) is D2's, because it needs operator accounts. Until then, "unread" means "newer than 7 days".

**Rationale**: Admin already owns "audit of admin actions" and the shell. Keeping the listener in Admin means Identity knows nothing about operators. Operator email addresses come from configuration, not from operator accounts, which don't exist yet.

## R-15 The API runtime: this is the first API feature

**Finding**: nothing of the API contract is implemented yet. There is no `/api` route, envelope, problem rendering, request id, client-version check or rate-limit header. Spec 003 fixed the contract in `docs/openapi` only. B1 cannot pass a single Contract test without these.

**Decision**: B1 builds the shared API runtime, as the minimum every endpoint needs and nothing more:

| Piece | Where |
|---|---|
| `ErrorCode` backed enum, one case per catalogue code, carrying `status()` and `retryable()` | `app/Modules/Shared/Domain/Errors/ErrorCode.php` (a persistence-free primitive; a parity test pins it to `error-catalog.yaml`) |
| `Refusal`, the base exception carrying an `ErrorCode`, optional `retryAfter` and field errors | `app/Modules/Shared/Domain/Errors/Refusal.php` |
| Problem rendering (`application/problem+json`, `request_id`, localised `detail`, `errors[]` with JSON Pointers) for `Refusal`, validation, authentication, 404, 405 and throttling | `app/Http/Api/Problems/*`, registered in `bootstrap/app.php` |
| Envelope responder (`{data, meta}`, empty `meta` as `{}`) | `app/Http/Api/Envelope.php` |
| Middleware for the `api` group: `AssignRequestId` (UUIDv7, `X-Request-Id`, correlation propagated to the log context and jobs), `RefuseInsecureTransport` (400, plus HSTS), `NegotiateJson` (406/415), `RefuseUnknownMembers` (hooked through form requests instead, see below), `RequireClientVersion` (the headers, 400/426), `ResolveContentLanguage` | `app/Http/Api/Middleware/*` |
| `RateLimit-*` headers replacing `X-RateLimit-*` | `app/Http/Api/Middleware/StandardRateLimitHeaders.php` |
| Log scrubber (Monolog processor redacting `password`, `token`, `secret`, `authorization`, `credential` keys) | `app/Http/Api/Logging/RedactSecrets.php`, attached to the default channel |

`app/Http` already exists, holding the starter kit's `Controller`. The runtime is cross-cutting HTTP plumbing, not a bounded context, and the shared kernel may hold no middleware (P-02 rule 5; the arch test lets `Shared` contain only `Domain`). This follows the precedent of `app/Providers/ModuleServiceProvider`, framework wiring outside the modules. `docs/engineering-conventions.md` gains one row saying so. Unknown request members are refused in each module's base form request (`prohibits` of anything not in `rules()`), producing `UNKNOWN_FIELD`.

**Alternatives**: a separate "API runtime" spec before B1. Rejected: it would ship code with no endpoint to prove it, and B1 is the first consumer. It is recorded here so that B2 onward reuse it rather than rebuild it.

## R-16 Routing

**Decision**: `Identity\Http\IdentityServiceProvider` registers `Http/routes.php` under `prefix('api/v1')->middleware('api')`. There is no `routes/api.php`. `bootstrap/app.php` appends the runtime middleware to the `api` group, so every later module gets them by using the group. `POST /auth/google` is registered only when `identity.google.enabled` is true (default false). Otherwise the API fallback answers `404 NOT_FOUND` (FR-080).

## R-17 The verification gate, usable across modules

**Decision**: two forms, both from Identity:

- **Middleware alias** `address.verified`, registered by Identity's provider. Another module's routes write `->middleware('address.verified')`, a **string**, so there is no import of Identity's `Http` namespace (P-02 rule 3).
- **Application check**: `Identity\Application\VerificationGate::assertVerified(string $userId)`, for application services that write outside HTTP.

Both throw `Refusal(EMAIL_NOT_VERIFIED)`. B1 applies the gate to nothing of its own. A fixture route in the test suite proves it.

## R-18 Mail

**Decision**: queued mailables in `Identity\Application\Mail` (and the operator mail in Admin), with views in `Identity/Http/Views/mail` under the `identity::` namespace. Every mailable sets `->locale()` from the profile, or from the request's language when there is no account. Strings live in `Identity/Http/Lang/{en,ar}/mail.php`, and a completeness test checks both languages. Locally they go to Mailpit, and in tests to `Mail::fake()`. The production mail provider is chosen with the deployment target (engineering conventions, environment profiles), so it does not block this feature.

## R-19 Syncable members before the Sync module exists

**Decision**: Profile and Device Session carry `version` (integer, starting at 1), `change_seq` (bigint), `created_at`, `updated_at` and `deleted_at`. Until B14:

- `change_seq` is drawn from one PostgreSQL sequence, `sync_change_seq`, through a small `Shared`-free helper in Identity Infrastructure;
- `version` increments on every state change the feed must see: create, revoke, expire and rotate for sessions, and create for profiles.

B14 owns the commit-order guarantee (ADR-0008) and may replace the helper. The columns and their meaning do not change.

**Session revocation and P-07 rule 2**: Device Session has **no client write operation**. There is no `PUT` for it, and the client never sends its fields. Its changes are server commands (sign-in, sign-out, end, expire), in the same class as "authenticating", which the API conventions reserve for `POST`-style operations. They are idempotent terminal transitions: ending an already-ended session is a no-op. They cannot lose a concurrent edit, which is the only thing `version` protects. The rule "every write carries the client's version" governs writes of a record's content through the upsert contract, which Device Session does not expose. This resolves spec open question 4. It is recorded in the Constitution Check.

## R-20 Replacing the starter-kit user

**Decision**: before the `v1.0.0` tag, migrations may be edited (engineering conventions).

- Remove `users` and `password_reset_tokens` from `0001_01_01_000000_create_users_table.php`, and keep `sessions` (Livewire and the back office use web sessions).
- Delete `app/Models/User.php`, `database/factories/UserFactory.php` and the seeder's user.
- The Identity model is `Identity\Infrastructure\Models\User`, which is `Authenticatable` and not `Notifiable`. Mail goes through mailables addressed from the decrypted address, inside Application.
- `config/auth.php`: the default guard is `api` (driver `sanctum`, provider `users` → the Identity model). The `web` guard has **no** user provider, so an application user can never hold a web session. The admin guard arrives with D2 (P-08 rule 4, P-18 rule 4). The `passwords` broker config is removed (R-8).

## R-21 Deletion request and cancellation

**Decision**: `users.state` moves between `active` and `pending_deletion`. Requesting sets `deletion_requested_at` and `deletion_scheduled_for = requested + 30 days`, and repeating returns the stored date (FR-052). Cancelling, explicitly or by sign-in, clears both, writes one audit entry with `detail.reason`, and publishes `UserDeletionCancelled` with that reason. In sign-in, the cancellation happens in the same transaction as issuing the session. B15 owns the purge job, which reads `deletion_scheduled_for`.

## R-22 Password change while signed in

**Decision**: `POST /auth/change-password` takes `{current_password, new_password}`. In one transaction it checks the lockout counter, verifies the current password, applies the policy (R-5), writes the hash, ends every **other** active session (`end_reason = password_changed`), supersedes reset links, and records `password_changed` and one `session_revoked` per ended session. After commit it publishes `DeviceSessionEnded` for each ended session and queues the "password changed" mail.

## R-23 Profile defaults and IANA validation

**Decision**:

- **Locale**: `Accept-Language` resolved by the runtime (R-15) to `ar` or `en`.
- **Timezone**: the `timezone` member if it appears in `DateTimeZone::listIdentifiers()`, and `422 INVALID_ENUM_VALUE` otherwise. Absent means `config('identity.default_timezone')` = `Africa/Cairo`.
- **Display name**: optional, 1 to 100 characters after trimming, sealed per R-6.

## R-24 Session list shape

**Decision**: `GET /auth/sessions` returns every session of the caller in **all** states (active, revoked, expired), newest sign-in first. It uses cursor paging per the conventions (`limit` default 50), with `x-filterable: [state]` and `x-sortable: [signed_in_at, last_used_at]`. Each item is the syncable envelope plus `user_id`, `device_name`, `platform`, `app_version`, `state`, `signed_in_at`, `last_used_at`, `ended_at`, `end_reason` and `current` (a boolean). No IP address and no device identifier are exposed (baseline: nothing further).

## R-25 Client version gate on auth endpoints

**Decision**: the `X-Client-Platform` and `X-Client-Version` gate applies to every `/api/v1` route, auth included (API conventions: "the gate applies to reads as well as writes"). The minimum version is `config('api.minimum_client_version.android')`, starting at `1.0.0`. It is the same middleware as R-15.
