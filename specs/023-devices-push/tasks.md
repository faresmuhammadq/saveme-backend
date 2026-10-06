---

description: "Task list for Devices & Push Notifications (B16)"
---

# Tasks: Devices & Push Notifications

**Input**: Design documents from `/specs/023-devices-push/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/push-api.md](./contracts/push-api.md), [quickstart.md](./quickstart.md), [checklists/privacy-delivery.md](./checklists/privacy-delivery.md), the frozen contract `docs/openapi/paths/notifications.yaml` (written and indexed in `docs/openapi/openapi.yaml`; `info.version` v1.14).

**Tests**: included. The spec's Invariants table (§4) names every test file (constitution P-16 rule 3). Write each story's tests first and watch them fail.

**Organization**: grouped by user story, in priority order:

- US1: my phone can receive notifications (P1)
- US2: choose what I hear about, and when not (P1)
- US3: remind me when a recurring item needs me (P1)
- US4: tell me when a budget is exceeded, once (P2)
- US5: tell me about sharing changes that affect me (P2)
- US6: tell me about my plan, my export and my deletion (P3)
- US7: operators see whether push works, not what it said (P3)

**Conventions for every task** (as in specs 016–022):

- Run through the container: `dev exec -- vendor/bin/pest <path> --compact`, `dev <artisan command>`. Host PHP has no `pdo_pgsql`.
- P-02: new code in `app/Modules/Notifications/{Domain,Application,Infrastructure,Http}`; Notifications reaches Identity, Wallets, Sharing only through their `Application` services and reacts to their events; Budgeting reaches Wallets through `TransactionQueryService`. Domain imports only `Shared`.
- **No new Composer or npm dependency** (FR-040, CONTRIBUTING 17). `composer.json` and `package.json` must be unchanged at the end.
- **Never** put the push token, the service-account key, the OAuth assertion or the access token in a response, log line (any level), exception message, job payload, event, audit entry or screen (FR-007). Jobs carry the notification id only. Logs: ids, types, codes and counts only (FR-037, FR-048).
- Events implement `ShouldDispatchAfterCommit` and carry ids and scalars (P-11); listeners are queued (`notifications` queue for Notifications, default for Budgeting) and idempotent.
- Refusals extend `Shared\Domain\Errors\Refusal` with an existing catalogue code (`NOT_FOUND`, `VALIDATION_FAILED`, `VERSION_CONFLICT`, `RATE_LIMITED`). No new error code.
- Instants with microseconds in responses; empty `meta` is `(object) []`.
- Contract tests use `assertValidRequest()` and `assertValidResponse($status)` for every documented status.
- Run `dev exec -- vendor/bin/pint --dirty --format agent` after PHP edits.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on unfinished tasks)
- **[Story]**: US1–US7 from spec.md

---

## Phase 1: Setup (docs, decision record, config)

- [X] T001 [P] Write `docs/adr/0025-push-through-a-port-with-an-in-house-fcm-adapter.md` (template `docs/adr/template.md`, Status Accepted, Principles P-11, P-15, P-17; extends ADR-0009, ADR-0010, ADR-0018, ADR-0019): rules — push leaves only through the `PushGateway` port; the production adapter speaks FCM HTTP v1 with the framework HTTP client and an RS256 service-account JWT signed with `ext-openssl`, token cached until 5 minutes before expiry; `log` refused outside local/testing, `disabled` honest; every notification is a ledger row with a per-person dedupe key (the idempotency of every notification listener); occurrence due-ness stays derived and only "reminded" is stored; budget progress stays computed on read and Budgeting stores only that a period crossed its limit (Budget Overrun, threshold 100). Alternatives: Firebase Admin SDK (needs approval — drop-in later), legacy server-key API (gone), data-only messages, syncable preferences, Laravel database notifications. Add to `docs/adr/README.md` index.
- [X] T002 [P] Constitution PATCH v1.3.13 → v1.3.14 in `.specify/memory/constitution.md`: P-11 `**ADRs**` line lists ADR-0025 (extends ADR-0009, ADR-0010, ADR-0018, ADR-0019); Plans & Billing row Publishes gains `SubscriptionRenewalDue`; Identity row gains `ExportReady`, `DeletionReminderDue`; Sync Impact Report entry; both footer lines Last Amended 2026-10-06 and version 1.3.14 (fix the stale bottom footer that still says 1.3.12).
- [X] T003 [P] Glossary `docs/glossary.md` (before code, CONTRIBUTING 13): new entries **Push Registration** (Notifications, aggregate root, persisted, Sync: no — written by `PUT /me/devices/{id}`, never read back; `single-state`; notes: one per Device Session, one per token, sealed token, removed when the session ends / token invalid / 270 days idle / purge), **Notification Preferences** (Notifications, root, persisted, Sync: no — `GET /me/notification-preferences`; versioned; six categories; quiet hours in the profile timezone), **Push Notification** (Notifications, root, persisted, Sync: no — never read by the client, which receives the push; states `pending`→`held`→`sending`→`sent`|`skipped`|`failed`; dedupe key; 30 days; child **Push Delivery**), **Budget Overrun** (Budgeting, child of Budget, persisted, Sync: no; once per budget per period per threshold; publishes `BudgetExceeded`); Device Session "Referenced by" note points to Push Registration (replace "will be indexed here when that feature is specced"); entity index rows and counts; the "persisted but not synced" table rows.
- [X] T004 [P] Security baseline `docs/security-baseline.md` amendments (data-model.md "Protected-attributes rows" and "Retention rows"): protected attributes — push token (encrypted under Notifications' keyring + keyed lookup; nobody through any endpoint), notification preferences, notification and delivery records, budget overrun; retention — push registration (until session end or 270 days idle), notification and delivery records 30 days, budget overrun with its budget; What must never be logged — push tokens, FCM service-account key, OAuth assertion and access token; Device sessions rule "ending a session removes its push registration; no push is sent to an ended session"; new section **Push notifications** (no amount, balance, currency, names, notes, addresses, dates of money, tokens or URLs in payload or text; generic localised text; delivery-time session check; `log` gateway refused outside local/testing; rate 10/h 30/day); route budgets (device registration 20 per hour per session; preferences 30 per hour per person); appendix checklist line for push payload content.
- [X] T005 [P] Privacy commitments `docs/privacy-commitments.md`, **English and Arabic**: "What we collect" — the push address of each phone you sign in on, used only to send the notifications you choose, deleted when you sign out; "What is protected" — notifications never show amounts, names or notes on your lock screen; you choose categories and quiet hours; mapping rows (spec 023 FR numbers).
- [X] T006 [P] `docs/api-conventions.md`: note that a `/me/...` resource may be versioned with `409 VERSION_CONFLICT` + `current` without being syncable (`/me/notification-preferences`), and that `{id}` of `/me/devices/{id}` is the caller's current session id; `specs/briefs/README.md` row 17 note "Specified as spec 023; no Firebase SDK (in-house FCM v1 adapter)"; brief B16 untouched.
- [X] T007 [P] `config/notifications.php` with every key of data-model.md "Configuration introduced" (`push.driver` default `disabled`; `push.fcm.project_id`, `push.fcm.credentials`, `push.fcm.timeout` 10, `push.fcm.connect_timeout` 5, `push.fcm.token_uri` default `https://oauth2.googleapis.com/token`; `keys`, `active_key`, `lookup_key`; `rate.per_hour` 10, `rate.per_day` 30; `summary_threshold` 3; `reminder_hour` 9; `registration_idle_days` 270; `retention_days` 30; `defaults.quiet_hours` enabled 22:00–08:00; `stale_sending_minutes` 15); `.env.example` (`NOTIFICATIONS_PUSH_DRIVER=log`, placeholder `NOTIFICATIONS_KEYS`, `NOTIFICATIONS_ACTIVE_KEY`, `NOTIFICATIONS_LOOKUP_KEY`, commented FCM vars); `phpunit.xml` throwaway test keys and `NOTIFICATIONS_PUSH_DRIVER=log`.

---

## Phase 2: Foundational (schema, domain, gateway, ledger — blocks every story)

- [X] T008 Migrations in `app/Modules/Notifications/Infrastructure/Database/Migrations/`: `2026_10_12_000001_create_push_registrations_table.php` (`session_id` UNIQUE, `token_sealed` text, `token_lookup` char(64) UNIQUE, `user_id` index, `refreshed_at` index), `2026_10_12_000002_create_notification_preferences_table.php` (`user_id` PK; six booleans default true; `quiet_hours_enabled` default true; `quiet_hours_start` smallint default 1320, `quiet_hours_end` default 480, each CHECK `0 <= v AND v <= 1425 AND v % 15 = 0`; CHECK `quiet_hours_start <> quiet_hours_end`; `version` integer; `created_at`, `updated_at`), `2026_10_12_000003_create_push_notifications_and_deliveries_tables.php` (columns, CHECKs and indexes exactly as data-model.md: `type` CHECK over the twelve types, `category` CHECK over six, `state` CHECK `pending|held|sending|sent|skipped|failed`, `skip_reason` CHECK over seven and `(state = 'skipped') = (skip_reason IS NOT NULL)`, `(state = 'held') = (not_before IS NOT NULL)`, `(state = 'sent') = (sent_at IS NOT NULL)`, UNIQUE(`user_id`,`dedupe_key`), partial index (`state`,`not_before`) WHERE state IN ('held','sending'), partial (`user_id`,`sent_at`) WHERE sent_at IS NOT NULL, (`created_at`); `push_deliveries` FK cascade, UNIQUE(`notification_id`,`registration_id`), `state` CHECK `pending|sent|pruned|failed`, `last_error` varchar(64)). Reversible `down()`.
- [X] T009 [P] Migration `app/Modules/Identity/Infrastructure/Database/Migrations/2026_10_12_000005_allow_notifications_purge_step.php`: replace `account_purges_last_step_check` to add `'notifications'`; add `PurgeStep::Notifications = 'notifications'` between `Subscriptions` and `Exports` in `app/Modules/Identity/Domain/Deletion/PurgeStep.php`; extend the existing `PurgeStep` order test.
- [X] T010 [P] Domain values in `app/Modules/Notifications/Domain/`: `Preferences/{Category (six cases), QuietHours (start/end minutes, enabled; `contains(DateTimeImmutable $utc, string $tz)`, `endAfter(DateTimeImmutable $utc, string $tz): DateTimeImmutable` handling wrap and DST — a non-existent local end moves forward), Preferences (+ `defaults()` at version 1)}`; `Messages/{MessageType (twelve cases, each: category, allowed data keys, collapse-key template, priority HIGH for occurrence_due / occurrence_auto_confirm_refused / deletion_reminder else NORMAL), NotificationDraft (userId, type, dedupeKey, data), PushMessage, PayloadBuilder (throws on any key outside the type's list or a non-string value)}`; `Delivery/{PushGateway (port: `send(PushMessage, PushDestination): GatewayOutcome`), PushDestination (registrationId, token), GatewayOutcome (accepted | invalidToken | retryLater(?int seconds) | failed(code)), NotificationState, SkipReason (seven), DeliveryState, DeliveryPolicy (pure: from Preferences, timezone, account active, now, sentLastHour, sentLastDay → skip(reason) | hold(until) | send), PolicyDecision}`; `Ports/{RegistrationRepository, PreferencesRepository, NotificationRepository, DeliveryRepository, TokenSealer, TokenLookup, AccessTokenSource}`.
- [X] T011 [P] Unit tests: `tests/Unit/Notifications/QuietHoursTest.php` (wrap 22:00–08:00, no wrap 13:00–15:00, boundaries inclusive start / exclusive end, DST forward and back in `Europe/London` and `Africa/Cairo`), `tests/Unit/Notifications/DeliveryPolicyTest.php` (category off, inactive account, rate per hour and per day, quiet hours → hold until end, send), `tests/Unit/Notifications/PayloadShapeTest.php` (every `MessageType` builds only its keys plus `schema`, `type`, `category`, `notification_id`; any extra key, e.g. `amount_minor`, `name`, `note`, `date`, `url`, `token`, throws; all values strings), `tests/Unit/Notifications/MessageTypeTest.php` (category and collapse template per row of spec §5).
- [X] T012 [P] Crypto in `app/Modules/Notifications/Infrastructure/Crypto/`: `NotificationsKeyring` (several keys, active kid, from `notifications.keys`/`active_key`), `NotificationsSealer` (copy of `Billing\Infrastructure\Crypto\BillingSealer`: XChaCha20-Poly1305, `v1.{kid}.{base64}`, AD `push_registration:{session_id}`), `HmacTokenLookup` (hex HMAC-SHA256 under `notifications.lookup_key`); tests `tests/Feature/Notifications/PushTokenAtRestTest.php` (stored value never contains the token; opens with the right AD only; a value sealed under an old kid still opens after rotation).
- [X] T013 Gateways in `app/Modules/Notifications/Infrastructure/Push/`: `ServiceAccountKey` (parses the JSON from a path or base64; requires `client_email`, `private_key`, `private_key_id`, `token_uri`; never included in exception messages), `Jwt::rs256(array $header, array $claims, OpenSSLAsymmetricKey $key): string` (base64url, no padding, `openssl_sign(…, OPENSSL_ALGO_SHA256)`), `ServiceAccountTokenSource` implements `AccessTokenSource` (claims `iss`, `scope` `https://www.googleapis.com/auth/firebase.messaging`, `aud` token_uri, `iat`, `exp` = iat + 3600; POST form `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer`; cache `notifications:fcm:access-token` for `expires_in − 300` s under `Cache::lock`; `forget()`), `FcmErrorMap` (research R-9 table), `FcmPushGateway` (POST `https://fcm.googleapis.com/v1/projects/{project}/messages:send`, body per research R-10 incl. `android.collapse_key`, `android.priority`, `android.ttl` `86400s`, `android.notification.channel_id` = category and `tag` = collapse key; on 401 forget + mint + resend once), `LogPushGateway` (logs `push_logged` with type, category, notification id, data ids — never token or text), `DisabledPushGateway`.
- [X] T014 [P] Gateway tests: `tests/Unit/Notifications/ServiceAccountJwtTest.php` (key from `openssl_pkey_new` in the test; signature verifies with the public key; header `kid`; claims), `tests/Unit/Notifications/FcmErrorMapTest.php` (200, 404 UNREGISTERED, 400 INVALID_ARGUMENT on `message.token` vs other field, 403 SENDER_ID_MISMATCH, 429 with Retry-After, 500, 503, connection exception), `tests/Feature/Notifications/FcmGatewayTest.php` (`Http::fake` token + send endpoints: one token mint for two sends, refresh on 401 then single resend, body shape, no token or key in any captured log record), `tests/Fakes/FakePushGateway.php` (namespace `Tests\Fakes`; records messages; scripted outcome per token).
- [X] T015 `NotificationsServiceProvider` (`app/Modules/Notifications/Application/`), `NotificationsPersistenceServiceProvider` (`Infrastructure/`), `NotificationsHttpServiceProvider` (`Http/`, routes, `loadTranslationsFrom(__DIR__.'/Lang', 'notifications')`), registered in `bootstrap/providers.php`; gateway binding by `notifications.push.driver`; **boot refusal**: `log` outside `local`/`testing` throws ("the log push gateway is refused outside local and testing: it delivers nothing"), `fcm` without `project_id` or a readable key throws; missing sealer/lookup keys throw. Test `tests/Feature/Notifications/GatewayBindingTest.php` (each driver binds its class; refusals per environment; `disabled` allowed in production); `tests/Pest.php` helper binds `FakePushGateway` for Notifications tests.
- [X] T016 Eloquent in `app/Modules/Notifications/Infrastructure/`: `Models/{PushRegistration, NotificationPreference, PushNotification, PushDelivery}` (`$hidden = ['token_sealed','token_lookup']`), `Repositories/{EloquentRegistrationRepository (upsertForSession(sessionId, userId, sealed, lookup, now) in one transaction: delete other session's row with the same lookup, insert or update on session_id; deleteForSession; liveFor(userId); deleteIdleBefore; deleteAllFor(userId); countFor(userId)), EloquentPreferencesRepository, EloquentNotificationRepository (createIfAbsent → `INSERT … ON CONFLICT (user_id, dedupe_key) DO NOTHING RETURNING id`; lockForDelivery; markHeld/markSending/markSent/markSkipped/markFailed; dueHeld(now); staleSending(before); sentCountSince(userId, since); deleteCreatedBefore; deleteAllFor(userId); figuresSince(since)), EloquentDeliveryRepository}`; bindings.
- [X] T017 Identity seam: `app/Modules/Identity/Application/Sessions/SessionStatus.php` — `activeAmong(string $userId, array $sessionIds): array` (ids whose `device_sessions.state = 'active'`) and `isCurrent(string $userId, string $sessionId, string $currentSessionId): bool`; plus an Application read of profile language and timezone and account state for Notifications (reuse `Accounts::isActive` and the existing profile repository via a new `Identity\Application\Profiles\RecipientProfile::of(userId): {locale, timezone, active}`); tests in `tests/Feature/Identity/SessionStatusTest.php` (incl. `RecipientProfile`: purging/purged → inactive, pending deletion → active).
- [X] T018 `app/Modules/Notifications/Application/NotificationService.php` — `create(NotificationDraft): ?string`: build data through `PayloadBuilder` (validates keys early), `createIfAbsent`; if inserted, evaluate `DeliveryPolicy` with `RecipientProfile`, current preferences and sent counts → mark `skipped`/`held` or dispatch `DeliverNotification` after commit; returns the id or null when the key existed. `Delivery/PushText` renders `notifications::push.{type}.title|body` in the profile locale at send time. Translation files `app/Modules/Notifications/Http/Lang/{en,ar}/push.php`: twelve types × title/body, fixed text with no placeholder, no name, amount, number or date (test asserts no `:` placeholder and no digit).
- [X] T019 `app/Modules/Notifications/Application/Delivery/DeliverNotification.php` (queued on `notifications`, `ShouldBeUnique` by notification id, `tries` 5, `backoff` [10, 60, 300, 900]): lock row → return if terminal → re-run policy (skip/hold) → `sending` → live registrations filtered by `SessionStatus::activeAmong` (inactive ones deleted) → none: `skipped(no_device)`; `disabled` driver: `skipped(disabled)` → ensure `push_deliveries` rows → per delivery not terminal: open token, render text, `send`; `accepted` → `sent`, `invalidToken` → delete registration + `pruned` + log `push_registration_pruned`, `failed` → `failed`, `retryLater` → remember; any retryable → `release(max(backoff, Retry-After))`; else notification `sent` if any delivery `sent`, otherwise `failed`; `failed()` hook marks `failed`; failure log per FR-037; the job carries the request correlation id like other queued jobs (P-15 rule 3). Tests `tests/Feature/Notifications/DeliverNotificationTest.php` (accepted; invalid token prunes and is not retried; retry then success; exhausted → failed; crash after one device sent → rerun sends only to the other; two devices one pruned → sent; no device → skipped no_device; inactive session deleted and not sent; `INVALID_ARGUMENT` not about the token → failed, registration kept).
- [X] T020 [P] `tests/Feature/Notifications/DedupeTest.php`: same draft twice → one row, one job; concurrent creates (two processes) → one row; `tests/Feature/Notifications/TokenNeverLeaksTest.php`: run register + deliver + failure paths with a known token and a known key; assert neither appears in `jobs.payload`, `failed_jobs`, any log record (Log::spy / test channel), any response body, any event payload.

**Checkpoint**: schema migrated; a draft can be created, policed and delivered through the fake gateway.

---

## Phase 3: User Story 1 — My phone can receive notifications (P1) 🎯 MVP

**Goal**: register, rotate, move and remove push registrations tied to device sessions.

**Independent Test**: spec US1 Independent Test.

### Tests

- [X] T021 [P] [US1] `tests/Contract/Notifications/DevicesTest.php` against `notifications.yaml`: PUT 200 (first, rotate, same token), 404 (other person's session, own other session, ended session, unknown id), 422 (missing `push_token` REQUIRED, empty or 4097 chars OUT_OF_RANGE, number INVALID_TYPE, `platform` UNKNOWN_FIELD, non-UUIDv7 id INVALID_ID), 401, 429 after 20 writes; DELETE 204 twice, 404, 429; body never contains the token; `meta` is `{}`.
- [X] T022 [P] [US1] `tests/Feature/Notifications/RegisterDeviceTest.php`: one row per session; rotation replaces the sealed value; same token refreshes `refreshed_at` and re-seals under the active key; a token moving from session A (other user) to B deletes A's row in the same transaction; not behind the verification gate (unverified account registers).
- [X] T023 [P] [US1] `tests/Feature/Notifications/RegistrationLifecycleTest.php`: every `EndReason` (signed_out, ended_by_owner, replaced, password_changed, password_reset, expired, account_deleted) removes the registration through `DeviceSessionEnded`; listener idempotent on redelivery; delivery after session end but before the listener runs sends nothing; `notifications:prune` deletes registrations idle ≥ 270 days and keeps 269.

### Implementation

- [X] T024 [US1] `app/Modules/Notifications/Application/DeviceService.php`: `register(userId, currentSessionId, pathId, token)` (pathId ≠ current → `SessionNotCurrent` refusal → 404; seal + lookup; upsert), `unregister(userId, currentSessionId, pathId)`, `dropForSession(sessionId)`, `pruneIdle(now)`; `Domain/Registration/{PushRegistrationRecord, SessionNotCurrent}`.
- [X] T025 [US1] Http: `app/Modules/Notifications/Http/Requests/RegisterDeviceRequest.php` (exactly `push_token`: string, 1–4096, `^[\x21-\x7E]+$`; unknown members UNKNOWN_FIELD; path id UUIDv7 → INVALID_ID as other modules do), `Http/Controllers/DevicesController.php` (`update`, `destroy`; current session from `$request->user()->currentAccessToken()`), `Http/Resources/PushRegistrationResource.php` (`session_id`, `registered_at`, `refreshed_at` only), `Http/routes.php` (`PUT|DELETE /api/v1/me/devices/{id}` under `auth:sanctum`, the shared API middleware, **not** `address.verified`; rate limiter `notifications-devices` 20 per hour keyed by session id).
- [X] T026 [US1] `app/Modules/Notifications/Application/Listeners/DropRegistrationOfEndedSession.php` on `Identity\Domain\Events\DeviceSessionEnded` (queued, `notifications`), registered in `NotificationsServiceProvider`.
- [X] T027 [US1] `app/Modules/Notifications/Http/Console/PruneCommand.php` (`notifications:prune`): idle registrations (270 days), notifications and deliveries older than 30 days; one `notifications_pruned` counts line; schedule daily `withoutOverlapping` in `routes/console.php`.

**Checkpoint**: US1 independently testable (quickstart §1).

---

## Phase 4: User Story 2 — Choose what I hear about, and when not (P1)

**Goal**: preferences endpoint; category switches and quiet hours applied at creation and at send.

**Independent Test**: spec US2 Independent Test.

### Tests

- [X] T028 [P] [US2] `tests/Contract/Notifications/PreferencesTest.php`: GET 200 defaults (version 1, `updated_at` null); PUT 200 (change → version 2; identical replay → unchanged version); 409 VERSION_CONFLICT with `current`; 422 (missing category REQUIRED, unknown category UNKNOWN_FIELD, `"23:10"` OUT_OF_RANGE, `"24:00"` OUT_OF_RANGE, start = end OUT_OF_RANGE at `/quiet_hours/end`, string boolean INVALID_TYPE, `updated_at` UNKNOWN_FIELD); 401; 429 after 30 saves.
- [X] T029 [P] [US2] `tests/Feature/Notifications/SavePreferencesTest.php`: GET does not insert; first PUT with version 1 inserts at 2; two concurrent PUTs at the same version → one 200, one 409; stale version applies nothing.
- [X] T030 [P] [US2] `tests/Feature/Notifications/DeliveryPolicyTest.php`: category off → `skipped(category_off)`; inside quiet hours → `held` with `not_before` = end in the profile timezone; release sweep sends at the end; quiet hours turned off while held → released at next sweep; two held with one collapse key → older `skipped(collapsed)`; released burst over the rate limit → overflow `skipped(rate_limited)` oldest first sent; profile timezone change moves the evaluation; held message rendered in the language current at send.

### Implementation

- [X] T031 [US2] `app/Modules/Notifications/Application/PreferencesService.php` (`read(userId)`, `save(userId, payload, version)`: lock or compare against defaults; identical → no write; changed → version + 1; refusal `PreferencesVersionConflict(current)` → 409) and `Domain/Preferences/PreferencesRules.php` (validation per FR-015/FR-016 with JSON pointers).
- [X] T032 [US2] Http: `Requests/SavePreferencesRequest.php`, `Controllers/NotificationPreferencesController.php` (`show`, `update`), `Resources/NotificationPreferencesResource.php` (`version`, `categories`, `quiet_hours` with `HH:MM`, `updated_at`), routes `GET|PUT /api/v1/me/notification-preferences` (not verification-gated; limiter `notifications-preferences` 30 per hour per user on PUT).
- [X] T033 [US2] `app/Modules/Notifications/Application/Delivery/ReleaseHeld.php` + `Http/Console/ReleaseHeldCommand.php` (`notifications:release-held`, every 5 minutes, `withoutOverlapping`): held rows whose `not_before ≤ now` or whose recipient is no longer inside quiet hours → collapse (latest per collapse key, others `skipped(collapsed)`) → `pending` → dispatch, oldest first; `sending` older than 15 minutes → re-dispatch (FR-049); one `notifications_released` counts line; per-row failure isolated (FR-051).

**Checkpoint**: US1 + US2 make push controllable (quickstart §2, §4).

---

## Phase 5: User Story 3 — Remind me when a recurring item needs me (P1)

**Goal**: hourly reminders for due manual occurrences and refused automatic ones, summarised above 3.

**Independent Test**: spec US3 Independent Test.

### Tests

- [X] T034 [P] [US3] `tests/Feature/Wallets/OccurrenceReminderQueryTest.php`: returns `scheduled` manual occurrences dated rule-today and rule-today − 1 (rule timezone, e.g. a rule in `Asia/Tokyo` vs `Africa/Cairo` around midnight UTC), refused ones within 2 days, not auto rules without refusal, not confirmed/skipped/tombstoned, not deleted wallets or ended rules; recipients = active members allowed `R-02` (owner and editor; not viewer, not ended members); paging by id.
- [X] T035 [P] [US3] `tests/Feature/Notifications/OccurrenceRemindersTest.php`: one `occurrence_due` per eligible recipient with `wallet_id` and `occurrence_id`; rerun → none; recipient before 09:00 local → none this run, sent on the first run after 09:00; refused → `occurrence_auto_confirm_refused`; 4 occurrences for one person → one `occurrences_due_summary` (no ids) + 4 `skipped(summarised)` markers; occurrence two days old never reminded → not reminded; one failing recipient does not stop the pass; one counts-only log line.

### Implementation

- [X] T036 [US3] `app/Modules/Wallets/Application/Recurring/OccurrenceReminderQuery.php` + DTO `OccurrenceReminder` (`occurrenceId`, `walletId`, `kind` due|refused, `recipientUserIds`): set-wise SQL per research R-12 (rule-today as `(now() AT TIME ZONE rule.timezone)::date`), recipients via `PermissionMatrix::allows(role, Permission::R02WriteRecurring)` plus its capability through `EntitlementService` where the matrix attaches one; `EXPLAIN` on 10,000 rules and add a partial index migration in Wallets only if it scans (record the decision in plan.md "Implementation notes").
- [X] T037 [US3] `app/Modules/Notifications/Application/Reminders/SendOccurrenceReminders.php` + `Http/Console/OccurrenceRemindersCommand.php` (`notifications:occurrence-reminders`, hourly, `withoutOverlapping`): page the query; for each recipient read `RecipientProfile` (timezone) and skip if local time < `reminder_hour`; group per recipient; ≤ 3 → `occurrence_due`/`occurrence_auto_confirm_refused` drafts (keys `occurrence:{id}` / `occurrence_refused:{id}`); > 3 → only occurrences not yet reminded count, one `occurrences_due_summary` (`summary:{UTC hour}`) plus `skipped(summarised)` markers under each occurrence key; per-recipient try/catch (FR-051); `notifications_pass` counts line; schedule in `routes/console.php`.
- [X] T038 [US3] Performance check, opt-in `tests/Feature/Notifications/OccurrenceRemindersPerformanceTest.php` (`PUSH_PERF=1`): 10,000 active rules, pass < 10 minutes (SC-008); record in plan.md.

**Checkpoint**: P1 stories done — MVP (quickstart §3.1).

---

## Phase 6: User Story 4 — Tell me when a budget is exceeded, once (P2)

**Goal**: Budgeting records the first overrun per budget per period and publishes `BudgetExceeded`; Notifications delivers it.

**Independent Test**: spec US4 Independent Test.

### Tests

- [X] T039 [P] [US4] `tests/Feature/Budgeting/BudgetOverrunTest.php`: crossing strictly above the limit → one overrun row and one `BudgetExceeded` (Event::fake after commit); equal to the limit → none; further spending, void and re-record → none; next period → new row; lowering the limit below spent → one; expense dated in a past period → none; archived budget → none; all-expenses budget (`category_id` null) counts; other currency ignored; partner amends the author's transaction → evaluated for the author (ADR-0019); listener redelivery → no duplicate; deleting the budget cascades its overruns.
- [X] T040 [P] [US4] `tests/Feature/Notifications/EventNotificationsTest.php` (budget part): `BudgetExceeded` → one `budget_exceeded` to the owner with `budget_id`, key `budget_exceeded:{budget}:{period_start}`.

### Implementation

- [X] T041 [US4] Migration `app/Modules/Budgeting/Infrastructure/Database/Migrations/2026_10_12_000004_create_budget_overruns_table.php` (`budget_id` FK → budgets ON DELETE CASCADE, `user_id`, `period_start` date, `threshold` smallint CHECK (`threshold = 100`), `crossed_at`, UNIQUE(`budget_id`,`period_start`,`threshold`)); model + `OverrunRepository` port and Eloquent implementation (`recordOnce(...)`: `INSERT … ON CONFLICT DO NOTHING RETURNING id`).
- [X] T042 [US4] `app/Modules/Wallets/Application/TransactionQueryService.php` + `authorAndCurrencyOf(string $transactionId): ?array{author_id: string, currency: string, date: string, category_id: ?string, kind: string}` (live transactions only).
- [X] T043 [US4] `app/Modules/Budgeting/Domain/Events/BudgetExceeded.php` (`eventId`, `occurredAt`, `budgetId`, `userId`, `periodStart`, `threshold`; `ShouldDispatchAfterCommit`), `Application/Overruns/DetectBudgetOverruns.php` (`forTransaction`, `forBudget`, rules of FR-026 using `BudgetQueryService::progressOf` for today's period), `Application/Listeners/EvaluateBudgetsAfterPosting.php` on `TransactionPosted` (kind `expense`, any `change`; queued), and `BudgetService` save/patch calling `forBudget` after commit when the limit changed; register in `BudgetingServiceProvider`.
- [X] T044 [US4] `app/Modules/Notifications/Application/Listeners/NotifyBudgetExceeded.php` (queued, `notifications`) → draft `budget_exceeded`.

---

## Phase 7: User Story 5 — Tell me about sharing changes that affect me (P2)

**Goal**: invitation received (verified addressee), role changed, removed, ownership received.

**Independent Test**: spec US5 Independent Test.

### Tests

- [X] T045 [P] [US5] `tests/Feature/Sharing/InvitationAddresseeTest.php`: `verifiedAddresseeOf` returns the user id for a pending email invitation to a registered, active, verified address; null for link invitations, unregistered or unverified addresses, a `purging` or `purged` account (a `pending_deletion` account still gets its id), the inviter's own address, and a closed invitation.
- [X] T046 [P] [US5] `tests/Feature/Notifications/EventNotificationsTest.php` (sharing part): `InvitationSent` email → `invitation_received` with only `invitation_id`; link → nothing; `MemberRoleChanged` → `member_role_changed` with `wallet_id`, `role`; actor = member → nothing; `MemberRevoked` `removed` → `member_removed`, `left` → nothing; `WalletOwnershipTransferred` → `wallet_ownership_received` to the new owner; each event delivered twice → one row.

### Implementation

- [X] T047 [US5] `app/Modules/Sharing/Application/InvitationQueryService.php` + `verifiedAddresseeOf(string $invitationId): ?string` (through Identity's Application address lookup and verification state; never returns or logs the address).
- [X] T048 [US5] Listeners in `app/Modules/Notifications/Application/Listeners/`: `NotifyInvitationReceived` (`InvitationSent`, channel `email`; key `invitation:{id}`), `NotifyMemberRoleChanged` (key `event:{eventId}`), `NotifyMemberRemoved` (`MemberRevoked` reason `removed`), `NotifyOwnershipReceived` (`WalletOwnershipTransferred`, recipient `newOwnerId`); queued on `notifications`; registered in the provider.

---

## Phase 8: User Story 6 — Tell me about my plan, my export and my deletion (P3)

**Goal**: new events from Billing and Identity and their notifications; mail unchanged.

**Independent Test**: spec US6 Independent Test.

### Tests

- [X] T049 [P] [US6] `tests/Feature/Billing/RenewalReminderEventTest.php`: `SendRenewalReminders::run` dispatches one `SubscriptionRenewalDue` per stamped subscription after commit; a rerun dispatches none; the mail is still queued as before.
- [X] T050 [P] [US6] `tests/Feature/Identity/ExportAndReminderEventsTest.php`: an export reaching `ready` dispatches one `ExportReady`; failed exports none; `SendDeletionReminders::run` dispatches `DeletionReminderDue` with `deletionScheduledFor`; mails unchanged.
- [X] T051 [P] [US6] `tests/Feature/Notifications/EventNotificationsTest.php` (account part): `SubscriptionRenewalDue` → `subscription_ending` (key `renewal:{subscription}`); `SubscriptionLapsed` → `subscription_lapsed`; `ExportReady` → `export_ready` with `export_id` and no URL; `DeletionReminderDue` → `deletion_reminder` (key `deletion_reminder:{scheduled_for}`); pending-deletion account still receives; purging account → `skipped(inactive_account)`.

### Implementation

- [X] T052 [P] [US6] `app/Modules/Billing/Domain/Events/SubscriptionRenewalDue.php` and dispatch in `app/Modules/Billing/Application/Reminders/SendRenewalReminders.php` inside the stamping transaction.
- [X] T053 [P] [US6] `app/Modules/Identity/Domain/Events/{ExportReady,DeletionReminderDue}.php`; dispatch in `app/Modules/Identity/Application/Export/GenerateExport.php` (on `ready`) and `app/Modules/Identity/Application/Deletion/SendDeletionReminders.php` (beside `markReminded`).
- [X] T054 [US6] Listeners `NotifySubscriptionRenewalDue`, `NotifySubscriptionLapsed`, `NotifyExportReady`, `NotifyDeletionReminder` in `app/Modules/Notifications/Application/Listeners/`, queued, registered.
- [X] T055 [US6] Purge participant `app/Modules/Notifications/Application/Departure/NotificationsPurge.php` (step `notifications`: delete registrations, preferences, notifications (cascade deliveries) of the user; idempotent), tagged `identity.purge-participants`; test `tests/Feature/Notifications/NotificationsPurgeTest.php` (after a purge nothing of the person remains in the four tables; running the step twice is a no-op; a crash before the step resumes and finishes).

---

## Phase 9: User Story 7 — Operators see whether push works, not what it said (P3)

**Goal**: counts on the account page and the overview.

**Independent Test**: spec US7 Independent Test.

- [X] T056 [P] [US7] `tests/Feature/Admin/NotificationFiguresTest.php`: account page shows "Devices that can receive notifications: N"; overview shows created/sent/skipped/failed/pruned for 24 hours; the rendered HTML contains no token, no push text, no payload key values, no per-person list; authorised per request (P-18 rule 7).
- [X] T057 [US7] `app/Modules/Notifications/Application/NotificationFigures.php` (`registrationCountFor`, `last24Hours`); Admin `app/Modules/Admin/Application/Accounts/AccountSnapshot.php` and `app/Modules/Admin/Application/Overview/OverviewFigures.php` read it; Admin views and `en`/`ar` lang keys for the two labels.

---

## Phase 10: Polish & cross-cutting

- [X] T058 [P] Log scrubber: add `push_token`, `token_sealed`, `assertion`, `access_token`, `private_key` to the central redaction keys (where spec 020 added its keys); test in `tests/Feature/Notifications/TokenNeverLeaksTest.php`.
- [X] T059 Apply the endpoint and security review checklists (both appendices) to the four operations and each new stored attribute; walk `checklists/privacy-delivery.md` with evidence; confirm every implemented response matches `docs/openapi/paths/notifications.yaml` member for member — any deviation is a contract change → stop and record it in plan.md.
- [X] T060 Gates: `dev exec -- composer test` (Pint, Larastan 8, arch, suite, API), `dev exec -- vendor/bin/pest tests/Contract/OpenApi --compact`; `git diff --stat composer.json composer.lock package.json package-lock.json` is empty (no new dependency); walk quickstart.md §1–§6 on the dev stack with the `log` gateway. State "Seeder: N/A — no reference data" in the commit description. Deployment notes for the summary: run the five migrations; workers listen to the `notifications` queue; schedule the three commands; set `NOTIFICATIONS_KEYS`/`ACTIVE_KEY`/`LOOKUP_KEY`; production uses `disabled` until the owner supplies Firebase credentials. Ask the owner to run the complete suite.
- [X] T061 Apidog sync: bundle with `npx @redocly/cli bundle docs/openapi/openapi.yaml -o <scratchpad>/bundle.json`; import with `apidog import --project 1332822 --format openapi --file <scratchpad>/bundle.json --module-map "Personal finance backend API=1363204"`; create or update the project doc **"Devices & push"** with contracts/push-api.md (registration rules and when to call, preference keys and defaults, quiet-hours behaviour, the full payload table — the `PushPayload` schema is not attached to an operation, so the bundle drops it and the doc must carry it — channels per category, rate limits, guarantees). Record the import counts and doc id for the summary.
- [ ] T062 Write specs/023-devices-push/summary.md (parent writes it)

---

## Dependencies & execution order

- **Phase 1**: T001–T007 in parallel (glossary T003 before any code using the terms).
- **Phase 2**: T008, T009 (migrations) and T010 (domain) first; T011 after T010; T012 ∥ T013 after T010; T014 after T013; T015 after T012–T013; T016 after T008, T010; T017 independent; T018 after T015–T017; T019 after T018; T020 after T019. Blocks every story.
- **US1** (T021–T027) needs Phase 2. **US2** (T028–T033) needs Phase 2 (independent of US1, but its delivery tests register a device through T024). **US3** (T034–T038) needs Phase 2. **US4** (T039–T044), **US5** (T045–T048), **US6** (T049–T055) need Phase 2 and are independent of each other. **US7** (T056–T057) needs Phase 2.
- **Polish** after all stories; T062 last.

## Parallel examples

- Phase 1: T001–T007 together.
- Phase 2: T011 ∥ T012 ∥ T013 once T010 lands.
- US1 tests T021 ∥ T022 ∥ T023; then T024 → T025 ∥ T026 ∥ T027.
- After Phase 2: US3, US4, US5, US6 can proceed in parallel (different modules: Wallets, Budgeting, Sharing, Billing/Identity).

## Implementation strategy

1. **MVP**: Phases 1–2 + US1 + US2 + US3 → phones register, people control push, recurring reminders arrive (the B7 promise). Ship with `disabled` in production until Firebase credentials exist; C16 can integrate against `log` locally.
2. **Increment**: US4 (closes the B8 gap), US5 (sharing), US6 (plan, export, deletion).
3. **Operators and polish**: US7, scrubber, gates, Apidog, summary.
