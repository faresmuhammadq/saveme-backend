# Research: Devices & Push Notifications

**Feature**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md)

Each item: Decision, Rationale, Alternatives considered. Items marked *assumed default* were chosen under the owner's delegated authority (owner away) as the most conservative option.

---

## R-1 Push without the Firebase SDK

**Decision**: A `PushGateway` port in `Notifications\Domain\Delivery`, with adapters in `Notifications\Infrastructure\Push`:

- `FcmPushGateway` — FCM HTTP v1: `POST https://fcm.googleapis.com/v1/projects/{project_id}/messages:send` with `Authorization: Bearer {access_token}`, through `Illuminate\Http\Client` (already installed; Guzzle underneath, already a framework dependency). Timeouts: connect 5 s, total 10 s.
- `ServiceAccountTokenSource` — OAuth 2.0 JWT bearer grant (RFC 7523): header `{"alg":"RS256","typ":"JWT","kid":private_key_id}`, claims `iss`=`client_email`, `scope`=`https://www.googleapis.com/auth/firebase.messaging`, `aud`=`token_uri` (default `https://oauth2.googleapis.com/token`), `iat`=now, `exp`=now+3600; signed with `openssl_sign(..., OPENSSL_ALGO_SHA256)` on the key from `openssl_pkey_get_private`; base64url without padding. POST form `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer&assertion=…`. The returned `access_token` is cached in the default cache store under `notifications:fcm:access-token` for `expires_in − 300` seconds, behind a `Cache::lock` so concurrent workers mint one token. A `401` from the send endpoint forgets the cached token, mints a new one and repeats the send once.
- `LogPushGateway` — logs `push_logged` with `type`, `category`, `notification_id` and the data identifiers; never the token, title or body. Returns `accepted`.
- `DisabledPushGateway` — sends nothing; the delivery records `skipped` (`disabled`) before calling it (FR-042).
- Tests bind `FakePushGateway` (`tests/Fakes/FakePushGateway.php`, namespace `Tests\Fakes`; never in `app/`, so it cannot be bound in production), which records messages and returns scripted outcomes per destination.

**Rationale**: the owner approved no dependency (CONTRIBUTING 17, Quality Gate 6). FCM v1 is a single JSON POST; the only non-trivial part is the RS256 JWT, which `ext-openssl` (present in the container, as `ext-sodium` and `ext-curl`) does in a few lines. The port takes a plain message and destination and returns a closed outcome set, so the Firebase Admin SDK, if ever approved, replaces one adapter class and one binding (FR-040).

**Alternatives considered**: `kreait/firebase-php` or `google/auth` (new dependencies, not approved); the legacy FCM HTTP API with a server key (shut down by Google in 2024); a third-party push relay (new processor of personal data, needs a privacy-commitment change).

## R-2 Gateway selection and boot refusal

**Decision**: `config/notifications.php` → `push.driver` from `NOTIFICATIONS_PUSH_DRIVER`, one of `fcm`, `log`, `disabled`; default `log` in `.env.example`, `disabled` when unset in config. `NotificationsServiceProvider::boot` throws when `log` is selected outside `local`/`testing` (message names the reason, as Billing's mailer refusal does), and when `fcm` is selected without `push.fcm.project_id` and a readable service-account key (`NOTIFICATIONS_FCM_CREDENTIALS`: a path to the JSON key file, or the JSON base64-encoded; never committed). The `testing` environment binds the fake in `tests/Pest.php` / a base test helper.

**Rationale**: matches spec 020's discipline for `log`/`array` mailers: an adapter that pretends to deliver must not reach production. `disabled` pretends nothing — it records `skipped (disabled)` — so production can ship before the owner supplies Firebase credentials.

**Alternatives considered**: a `null` driver that returns `accepted` (lies in the ledger); failing silently when credentials are missing (a misconfiguration would look like "nobody gets notifications").

## R-3 Where Notifications sits

**Decision**: new module `app/Modules/Notifications` with the four layers and `NotificationsServiceProvider` registered in `bootstrap/providers.php`; OpenAPI tag `Notifications` (already declared). It owns Push Registration, Notification Preferences, Push Notification and Push Delivery. It reads Identity (`UserQueryService`-style application calls: session active, account state, profile language and timezone), Wallets (`OccurrenceReminderQuery`), Sharing (`InvitationQueryService::verifiedAddresseeOf`) only through Application services, and consumes events. Budget Overrun lives in Budgeting.

**Rationale**: the constitution's Notifications row ("devices, push tokens, notification dispatch and preferences"), P-02. The glossary keeps Device Session in Identity; Notifications keys its registration to the session id and never writes Identity's table (brief reconcile row 1).

**Alternatives considered**: inside Identity (the constitution gives push tokens to Notifications); a `push_token` column on `device_sessions` (crosses the module boundary and puts a deliverable secret beside the session credential hash).

## R-4 Registration path and identity

**Decision**: `PUT /me/devices/{id}`, `DELETE /me/devices/{id}`; `{id}` must equal the caller's current session id (`$request->user()->currentAccessToken()->id`, the `device_sessions.id`), else `404`. Body `{push_token}` only. The token is sealed with `NotificationsSealer` (XChaCha20-Poly1305, `v1.{kid}.{b64}`, associated data `push_registration:{session_id}`), with `token_lookup` = hex HMAC-SHA256 under `NOTIFICATIONS_LOOKUP_KEY`. Uniqueness: `UNIQUE(session_id)`, `UNIQUE(token_lookup)`. Upsert in one transaction: delete any row with the same lookup and a different session, then insert-or-update on `session_id`. Every `PUT`, even with an unchanged token, re-seals under the active key, so key rotation completes as phones refresh (FR-006).

**Rationale**: the brief's `PUT /devices/{id}/push-token` names a device; this product's device is the session (glossary). `/me/…` follows `/me/profile`. Restricting to the current session means a stolen credential cannot point another session's notifications elsewhere, and the client never needs another session's id. A sealed value (not a hash) because the token must be sent; the lookup value gives the one-token-one-registration rule without decrypting every row. A copy of Billing's sealer (about 50 lines) because a module never imports another's Infrastructure (spec 018/020 precedent).

**Alternatives considered**: `PUT /devices/{id}/push-token` verbatim (introduces "device" as a resource the glossary does not have); Laravel `encrypted` cast with `APP_KEY` (no key identifier beside the value — baseline rule 4); body with `platform`, `app_version`, `locale` (duplicates the session and profile, two sources of truth).

## R-5 Removal paths

**Decision**:
1. `DeviceSessionEnded` → queued listener `DropRegistrationOfEndedSession` (delete by `session_id`; idempotent).
2. Delivery-time guard: before sending, `Identity\Application\Sessions\SessionStatus::activeAmong(userId, sessionIds)` (new small query in Identity's Application layer) — registrations whose session is not active are deleted, not sent.
3. FCM `UNREGISTERED` / token `INVALID_ARGUMENT` / `SENDER_ID_MISMATCH` → delete at once.
4. Daily `notifications:prune` deletes registrations with `refreshed_at < now − 270 days`, and notifications and deliveries older than 30 days.
5. Purge step `notifications` (new `PurgeStep::Notifications` between `subscriptions` and `exports`), implemented by `Notifications\Application\Departure\NotificationsPurge` tagged `identity.purge-participants`.

**Rationale**: the event path is the normal one; the guard closes the window between a session ending and the queued listener running (baseline: "Revocation effective on the next request" — here, on the next send). 270 days: FCM documents that Android tokens inactive for 270 days expire; a registration older than that is dead.

**Alternatives considered**: synchronous listener inside Identity's transaction (P-11 rule 1 forbids in-transaction cross-module listeners); no guard (a push could reach a signed-out phone).

## R-6 Preferences storage and concurrency

**Decision**: table `notification_preferences` (one row per `user_id`), six boolean columns, `quiet_hours_enabled`, `quiet_hours_start`/`end` as `smallint` minutes since midnight (0–1425, multiple of 15), `version`, `updated_at` nullable (null until first save), `created_at`. `GET` returns the stored row or the defaults at version 1 without inserting; `PUT` locks the row (`SELECT … FOR UPDATE`) or, when absent, compares against version 1 and inserts with `ON CONFLICT (user_id) DO NOTHING` then re-reads under lock. Changed → `version + 1`; identical → no write. Not on the sync feed.

**Rationale**: two phones must not overwrite each other silently (P-07's spirit, applied by choice); a read must not write. Minutes since midnight avoid any time-zone-bearing type (P-12: wall-clock values, applied in the profile timezone at send time).

**Alternatives considered**: syncable user-feed entity (sync module change and client sync work for a screen read on demand); a JSON column (harder CHECK constraints); `time` columns (fine, but invite timezone confusion).

## R-7 Notification ledger, states and dedupe

**Decision**: `push_notifications` (`id`, `user_id`, `type`, `category`, `dedupe_key` varchar(160), `data` jsonb of identifiers, `collapse_key`, `state`, `skip_reason`, `not_before`, `sending_since`, `sent_at`, `created_at`, `updated_at`), `UNIQUE(user_id, dedupe_key)`; `push_deliveries` (`id`, `notification_id` FK cascade, `registration_id` uuid without FK (registration may be deleted), `state`, `attempts`, `last_error` varchar(64), `updated_at`), `UNIQUE(notification_id, registration_id)`. `NotificationService::create(Draft)` inserts with `ON CONFLICT DO NOTHING`; only an inserted row is evaluated and, if sendable, dispatches `DeliverNotification` after commit.

State machine (domain `NotificationState`): `pending` → `held` (quiet hours; `not_before` set) → `pending` (release) ; `pending` → `sending` → `sent` | `failed`; `pending` | `held` → `skipped(reason)`. Terminal: `sent`, `skipped`, `failed`.

**Rationale**: one table gives idempotency for every source (P-11 rule 2), the "already notified" record for occurrences and event-driven messages, and the counts operators see. 30-day retention is longer than any dedupe horizon a source needs (occurrence keys are only re-evaluated for 2 days; budget dedupe is held by Budgeting).

**Alternatives considered**: Laravel's `notifications` table and `Notification` classes (database channel stores rendered content; a custom FCM channel would still be needed; mixes mail and push concerns the spec keeps apart); Redis-only dedupe (lost on flush).

## R-8 Policy evaluated at creation and at send

**Decision**: `DeliveryPolicy` (pure domain) decides, from preferences, profile timezone, account state, now and recent-send counts: `skip(category_off | inactive_account | rate_limited | collapsed)`, `hold(until)`, or `send`. Evaluated when created and again inside the job (preferences may have changed). Rate limit counts `push_notifications` of the person in state `sent` (or `sending`) over the last 60 minutes and 24 hours (index `(user_id, sent_at)`); configurable `notifications.rate.per_hour` = 10, `per_day` = 30. Quiet-hours arithmetic: `QuietHours::endAfter(now, tz)` returns the next end instant in UTC, handling wrap and DST by resolving the wall-clock end in the zone (a non-existent local time moves forward to the first valid instant).

**Rationale**: a pure, unit-testable rule (tests/Unit) that the job and the creator share.

## R-9 Delivery job and retries

**Decision**: `DeliverNotification` (queued on `notifications`, `ShouldBeUnique` by notification id, `tries = 5`, `backoff = [10, 60, 300, 900]`, `maxExceptions = 5`, `failOnTimeout`). Steps: lock the notification row; return if terminal; re-run policy; mark `sending` (`sending_since = now`); load live registrations (guard R-5.2); create missing `push_deliveries` rows; for each delivery not `sent`/`pruned`/`failed`, decrypt the token, render title/body (R-11), call the gateway; record per-registration outcome. If any outcome is `retry_later`, `release()` with the max of the backoff step and `Retry-After`. When nothing is retryable: `sent` if any delivery `sent`, else `failed` (or `skipped(no_device)` when there were no registrations). On final failure (`failed()` hook) the notification becomes `failed`. Sweep `notifications:release-held` (every 5 minutes) also re-queues `sending` rows older than 15 minutes (FR-049).

FCM outcome mapping (`FcmErrorMap`): `200` → accepted; `404 UNREGISTERED` → invalid_token; `400 INVALID_ARGUMENT` whose `fieldViolations` name `message.token` → invalid_token, other `INVALID_ARGUMENT` → failed (our bug; logged, not retried); `403 SENDER_ID_MISMATCH` → invalid_token; `429 QUOTA_EXCEEDED`, `503 UNAVAILABLE`, `500 INTERNAL`, connection errors → retry_later(Retry-After); `401` → refresh token once, then retry_later; anything else → failed.

**Rationale**: per-registration delivery rows make the job idempotent across retries and crashes (FR-036) and give operators their pruned/failed counts.

## R-10 Payload

**Decision**: `PushMessage` value object built by `PayloadBuilder` from a closed `MessageType` enum, each case declaring its category, allowed data keys and collapse-key template; any other key throws (FR-020; unit-tested per type). FCM body:

```json
{"message":{"token":"…","notification":{"title":"…","body":"…"},
 "data":{"schema":"1","type":"occurrence_due","category":"recurring_due","notification_id":"…","wallet_id":"…","occurrence_id":"…"},
 "android":{"collapse_key":"occurrence:…","priority":"HIGH|NORMAL","ttl":"86400s",
  "notification":{"channel_id":"recurring_due","tag":"occurrence:…"}}}}
```

Priority `HIGH` for `occurrence_due`, `occurrence_auto_confirm_refused`, `deletion_reminder`; `NORMAL` otherwise. TTL 24 hours (a reminder older than a day is noise). `android.notification.tag` = collapse key so a newer message replaces the displayed one.

**Rationale**: notification+data shows reliably while the app is not running; the generic text keeps the lock screen private; the closed key list is enforceable by a test.

## R-11 Text

**Decision**: `app/Modules/Notifications/Http/Lang/{en,ar}/push.php`, registered by the module's provider as the `notifications` namespace (as Identity does), keys `notifications::push.{type}.title|body`, rendered at send time with the profile locale. No placeholders allowed (a test asserts no `:` placeholder in any push string). Example en: `occurrence_due` → "Something is due today" / "A recurring item needs your confirmation."; `occurrences_due_summary` → "Items need you" / "Several recurring items are due. Open to review them." (no count, so nothing about volume of finances leaks); ar equivalents reviewed in the PR.

**Rationale**: P-14 (translation files), privacy (no names/amounts/dates). The summary carries no count to keep the text fixed.

## R-12 Occurrence reminders

**Decision**: Wallets adds `Recurring\OccurrenceReminderQuery` (Application) with `needingAttention(DateTimeImmutable $now, ?string $afterOccurrenceId, int $limit): list<OccurrenceReminder>` returning `{occurrence_id, wallet_id, kind: due|refused, recipient_user_ids}`, paged by id, set-wise: occurrences `scheduled`, not deleted, of live wallets and active rules, where (`auto_confirm = false` and `scheduled_date` ∈ {rule-today, rule-today − 1}) or (`auto_confirm_refusal IS NOT NULL` and `scheduled_date ≥ rule-today − 2`), rule-today computed per rule timezone in SQL (`(now() AT TIME ZONE rules.timezone)::date`). Recipients: active members whose role the `PermissionMatrix` allows `R-02` (the permission `OccurrenceService` authorizes a confirm with), plus any entitlement the matrix attaches to it, resolved inside Wallets. Notifications' `SendOccurrenceReminders` (command `notifications:occurrence-reminders`, hourly, `withoutOverlapping`) pages through it, filters recipients whose local time is before 09:00 (they are picked up by a later hour — the occurrence stays eligible through "yesterday"), groups by recipient, and creates either single notifications or, above 3 for a recipient, one summary plus "marker" rows for each occurrence (state `skipped`, reason `summarised` — counted separately from real skips). Index: partial index on `occurrences (scheduled_date) WHERE status = 'scheduled' AND deleted_at IS NULL` if not already covered (checked against spec 014's indexes at implementation).

**Rationale**: ADR-0018 — due-ness is derived; computing it at send time in the rule timezone; the permission matrix stays in Wallets (P-08 rule 5). The two-day window prevents a backlog flood after an outage or at rollout (FR-023).

**Alternatives considered**: a `DueOccurrence` event from the materialiser (B7 note: "there is no stored state to listen for"); notifying viewers (they cannot act).

## R-13 Budget overruns

**Decision**: Budgeting adds table `budget_overruns` (`id`, `budget_id` FK cascade, `user_id`, `period_start` date, `threshold` smallint CHECK (= 100), `crossed_at`), `UNIQUE(budget_id, period_start, threshold)`; `DetectBudgetOverruns` service with `forTransaction(string $transactionId)` and `forBudget(string $budgetId)`. The queued listener `EvaluateBudgetsAfterPosting` on `TransactionPosted` (kind `expense`) asks Wallets `TransactionQueryService::authorAndCurrencyOf($transactionId)` (new small method; returns author, currency, date, category, or null if gone), then evaluates the author's matching budgets (FR-026) with `BudgetQueryService::progressOf` for today's period; `spent > limit` → `INSERT … ON CONFLICT DO NOTHING RETURNING id`; on insert, `BudgetExceeded(eventId, occurredAt, budgetId, userId, periodStart, threshold=100)` dispatched after commit. `BudgetService::save/patch` calls `forBudget` after commit when `amount_minor` changed. Tombstone purge of a budget cascades its overruns.

**Rationale**: closes the B8 gap where it belongs (Budgeting owns progress and the constitution names `BudgetExceeded` there); the unique row is the "already notified" state; Notifications only delivers.

## R-14 Event sources added in other modules

| Event | Module, where | Notes |
|---|---|---|
| `BudgetExceeded` | Budgeting, `DetectBudgetOverruns` | R-13 |
| `SubscriptionRenewalDue` | Billing, `SendRenewalReminders::run` inside the transaction that stamps `renewal_reminded_at` (`ShouldDispatchAfterCommit`) | one per subscription per stamp |
| `ExportReady` | Identity, `GenerateExport` when status → `ready` | carries `exportId`, `userId` |
| `DeletionReminderDue` | Identity, `SendDeletionReminders::run` beside `markReminded` | carries `userId`, `deletionScheduledFor` (instant, ISO-8601 UTC) for the dedupe key |

Consumed unchanged: `DeviceSessionEnded`, `InvitationSent`, `MemberRoleChanged`, `MemberRevoked`, `WalletOwnershipTransferred`, `SubscriptionLapsed`, `TransactionPosted`.

Sharing adds `InvitationQueryService::verifiedAddresseeOf(string $invitationId): ?string` — the user id whose address lookup equals the invitation's, if the invitation is pending, the channel is `email`, the account is active and its address verified, and it is not the inviter (asks Identity through its Application lookup service). Returns null otherwise.

## R-15 Operator visibility

**Decision**: `Notifications\Application\NotificationFigures` with `registrationCountFor(userId): int` and `last24Hours(): array{created:int, sent:int, skipped:int, failed:int, pruned:int}`. Admin's `AccountSnapshot` adds "Devices that can receive notifications"; `OverviewFigures` adds a "Push, last 24 hours" block. No per-person list, no token, no text, no payload. Not subject to the anonymity floor (platform totals, not a cohort).

## R-16 Logging

**Decision**: `NotificationsLog` helper with fixed event names: `notifications_pass` (`created`, `summarised`, `skipped`), `notifications_released`, `notifications_pruned` (`registrations`, `notifications`), `push_delivery_failed` (`notification_id`, `type`, `status`, `reason`), `push_registration_pruned` (`registration_id`). The central log scrubber gains the keys `push_token`, `token`, `assertion`, `access_token`, `private_key` (if not already listed). No user id above debug.

## R-17 Testing seam

- Unit: `QuietHoursTest` (wrap, no wrap, DST forward/back in `Africa/Cairo` and `Europe/London`, start=end refused), `DeliveryPolicyTest`, `PayloadShapeTest` (every type, forbidden keys), `MessageTypeTest`, `ServiceAccountJwtTest` (signs with a key generated by `openssl_pkey_new` in the test; verifies with the public key; claims), `FcmErrorMapTest`, `PreferencesRulesTest`.
- Feature (PostgreSQL): registration lifecycle, sealing at rest, dedupe, policy, delivery job (fake gateway: accepted, invalid_token, retry_later, failed, crash/rerun), FCM adapter (`Http::fake` for token and send endpoints; token cached; 401 refresh; error mapping), gateway binding and boot refusal, reminders, overruns, event listeners, purge participant, admin figures, prune.
- Contract: `DevicesTest`, `PreferencesTest` against `notifications.yaml` with Spectator, every documented status.
- Arch: existing gates cover the new module (P-02).

## R-18 Things the plan deliberately does not do

No change to existing mail; no shared mail/push template layer (spec Q4); no iOS/APNs block (P-17); no test-push endpoint (FR-050); no `InvitationAccepted` push (Q3); no Firebase SDK (FR-040); no new audit event (FR-045); no new error code.
