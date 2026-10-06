# Implementation Plan: Devices & Push Notifications

**Branch**: `023-devices-push` (trunk-based; work lands on `main`) | **Date**: 2026-10-06 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/023-devices-push/spec.md`.

## Summary

Build the constitution's ninth context, **Notifications** (`app/Modules/Notifications`), and the minimum each other module must publish for it:

- **Registration** (R-4, R-5): `PUT`/`DELETE /me/devices/{id}` for the caller's own current session; push token sealed (XChaCha20-Poly1305, Notifications keyring) with a keyed lookup value; one per session, one per token; removed on `DeviceSessionEnded`, invalid token, 270 days idle, purge; a delivery-time guard re-checks the session.
- **Preferences** (R-6): `GET`/`PUT /me/notification-preferences` — six categories, quiet hours in the profile timezone, versioned, not synced, defaults at version 1.
- **Ledger and delivery** (R-7–R-11): every message is a Push Notification with a per-person dedupe key; a pure `DeliveryPolicy` (category, quiet hours, rate limit, account state); queued `DeliverNotification` with per-device Push Deliveries, retries, pruning; a closed `MessageType` set building lock-screen-safe notification+data payloads with server-localised generic text.
- **Gateway without a new dependency** (R-1, R-2): `PushGateway` port; `fcm` adapter (FCM HTTP v1 via Laravel's HTTP client; OAuth token from an RS256 JWT signed with `openssl_sign`, cached); `log` (refused outside local/testing); `disabled`; a test fake.
- **Sources** (R-12–R-14): hourly occurrence reminder pass over a new Wallets query; Budgeting's **Budget Overrun** and first-ever `BudgetExceeded`; new events `SubscriptionRenewalDue` (Billing), `ExportReady` and `DeletionReminderDue` (Identity); Sharing's verified-addressee lookup; listeners for the existing sharing, ownership and lapse events.
- **Operators** (R-15): counts on the account page and the overview.
- **API** frozen now: `docs/openapi/paths/notifications.yaml` (4 operations + the `PushPayload` schema), indexed, examples, `info.version` v1.14, no new error code.
- **Docs**: ADR-0025, constitution PATCH v1.3.14, glossary, security baseline, privacy commitments (en/ar), api-conventions, briefs README.

## Technical Context

**Language/Version**: PHP 8.5, Laravel 13.

**Primary Dependencies**: **none new** (Quality Gate 6, CONTRIBUTING 17 — the owner has approved no Firebase package). Used, all installed: `Illuminate\Http\Client` (Guzzle), `ext-openssl` (`openssl_pkey_get_private`, `openssl_sign`), `ext-sodium` (sealer), queues (database driver), scheduler, cache (database store, `Cache::lock`), `RateLimiter`, translations, Pest + Spectator, Livewire (existing admin components).

**Storage**: PostgreSQL 16 — Notifications: `push_registrations`, `notification_preferences`, `push_notifications`, `push_deliveries`; Budgeting: `budget_overruns`; Identity: `account_purges_last_step_check` extended. Cache: the FCM access token (≤ 55 minutes).

**Testing**: Pest — Unit (policy, quiet hours, payload shapes, message types, JWT signing, FCM error map, preference rules), Feature against PostgreSQL (registration lifecycle, sealing, dedupe, delivery job, gateway with `Http::fake`, boot refusal, reminders, overruns, listeners, purge, prune, admin figures), Contract (Spectator on `notifications.yaml`, every documented status), Arch (existing gates cover the new module).

**Target Platform**: the Linux server (`dev` container); FCM (Google) as the only external service, reached over HTTPS.

**Project Type**: modular-monolith JSON API with queued jobs and scheduled passes.

**Performance Goals**: registration and preference endpoints p95 < 150 ms; a notification created outside quiet hours reaches the gateway within 1 minute on a healthy queue (SC-005); the hourly reminder pass < 10 minutes over 10,000 active rules (SC-008); FCM token minted at most once per ~55 minutes per environment.

**Constraints**: P-02 (cross-module only via Application services and events), P-08 (matrix stays in Wallets; another person's session is 404), P-09 rule 5 (no entitlement on push), P-11 (after-commit events; queued idempotent listeners), P-12 (UTC instants; quiet hours as wall-clock minutes applied at send time), P-13 (no codename in names), P-14 (ar/en translation files, no placeholders), P-15 (token never logged; scrubber keys), P-17 (Android only; reminders only for recurring occurrences), security baseline (sealed token, protected-attributes and retention rows).

**Scale/Scope**: 4 API operations, 3 scheduled commands, 5 migrations (Notifications ×3, Budgeting ×1, Identity ×1), ~45 classes (mostly Notifications), ~25 test files, 4 new events, 12 message types × 2 languages.

## Constitution Check

*Constitution v1.3.13 (`.specify/memory/constitution.md`). GATE: must pass before Phase 0 research. Re-check after Phase 1 design, keeping both results.*

### Pre-design

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | New terms Push Registration, Notification Preferences, Push Notification (+ Push Delivery), Budget Overrun go into the glossary before code (T004); "Wallet" throughout; the push token is `push_token`, never "token" as product vocabulary for the session credential |
| P-02 Module boundaries | PASS | All new code in `app/Modules/Notifications/{Domain,Application,Infrastructure,Http}`; Notifications calls Identity (`SessionStatus`, profile/account queries), Wallets (`OccurrenceReminderQuery`), Sharing (`InvitationQueryService::verifiedAddresseeOf`) Application services only; Budgeting calls Wallets' `TransactionQueryService`; sealer copied, not imported (R-4) |
| P-03 Money | PASS | No money stored or sent; payloads carry no amount (FR-020); overrun detection compares through `BudgetQueryService` (existing `Money` arithmetic) |
| P-04 Ledger integrity | N-A | Writes no transaction or posting |
| P-05 Derived balances | N-A | Reads no balance; budget progress stays computed on read (overrun stores only "crossed once") |
| P-06 Identifiers | PASS | UUIDv7 server ids for every new row; `{id}` of `/me/devices/{id}` is the existing client-generated session id; no ordering by UUID time (paging by id is a stable tiebreak only, not a business order) |
| P-07 Sync and concurrency | PASS | No syncable entity added or changed; preferences use `version` with 409 `VERSION_CONFLICT` + `current` and no merge (rules 2–4 applied by choice) |
| P-08 Authorization | PASS | Own session / own preferences only; foreign or ended session id → 404 (no disclosure); occurrence recipients resolved by Wallets from its matrix (R-02), not copied |
| P-09 Entitlements | PASS | No capability check added; no plan code compared; push free for all |
| P-10 Billing channels | PASS | No purchase surface; InstaPay claims produce no push (FR-031) |
| P-11 Events | PASS | New `BudgetExceeded`, `SubscriptionRenewalDue`, `ExportReady`, `DeletionReminderDue` implement `ShouldDispatchAfterCommit`, carry ids/scalars; every Notifications and Budgeting listener queued and idempotent (dedupe key, unique overrun) |
| P-12 Time | PASS | Instants UTC; `period_start` a calendar date; quiet hours and reminder hour are wall-clock values evaluated in the profile timezone at send time; due-ness in the rule timezone (ADR-0018). `not_before` is an operational scheduling instant, not domain data: it is re-evaluated at every release sweep against current preferences and timezone, so a timezone change rewrites nothing (rule 5) |
| P-13 Naming | PASS | `Notifications`, `push_*`, `notifications:*` commands; no codename |
| P-14 Localisation | PASS | `Http/Lang/{en,ar}/push.php`; text rendered in the profile language; no numerals or placeholders in push text |
| P-15 Observability | PASS | Token never logged, queued, evented or audited (FR-007); fixed log events with ids and codes only (R-16); scrubber keys added; jobs carry the notification id only and propagate the correlation id |
| P-16 Quality gates | PASS | Each spec §4 invariant names a failing test; PostgreSQL unique keys enforce dedupe; `composer test` gate |
| P-17 Scope guard | PASS | Reminders only for recurring occurrences ("bill reminders beyond recurring occurrences" excluded); the plan-ending push mirrors spec 020's existing renewal mail about the product's own subscription, not a bill of the person's; Android only, no APNs (iOS-specific work excluded) |
| P-18 Delivery surfaces | PASS | Every capability under `/api/v1`; back-office change is two read-only counts through Notifications' Application query service in existing components, authorised per request |

### Post-design re-check

Unchanged: all rows PASS / N-A as above.

- **CONTRIBUTING item 18 — ADR-0025** "Push through a port with an in-house FCM adapter; notifications deduplicated by a ledger; budget overruns recorded once": extends ADR-0009 (after-commit events — adds four events and the idempotency-by-dedupe-key pattern for notification listeners), ADR-0018 (due-ness stays derived; reminders compute it at send time, recording only "reminded"), ADR-0019 (progress stays computed on read; Budgeting stores only the fact that a period crossed its limit) and ADR-0010 (token and assertion are never logged). Records the no-SDK decision and the drop-in path to the SDK. Constitution **PATCH v1.3.14**: P-11 lists ADR-0025; the Budgeting row's `BudgetExceeded` is now real; the Plans & Billing row gains `SubscriptionRenewalDue`; the Identity row gains `ExportReady`, `DeletionReminderDue`. No rule changes.
- **Security baseline amendments** (T005): protected attributes (push token sealed + lookup; preferences; notification records; budget overrun); retention (registration until session end or 270 days idle; notification records 30 days; overruns with their budget); what must never be logged (push tokens, service-account key, OAuth assertion and access token); device sessions ("ending a session removes its push registration"); a new short section "Push notifications" (no amounts, names, notes, addresses, dates of money or URLs; generic text; delivery guard; the `log` gateway refused outside local/testing).
- **Privacy commitments** (en/ar): "What we collect" gains the push address of each signed-in phone and what we do with it; "What is protected" gains "notifications never show amounts or names on your lock screen"; mapping rows.
- **API conventions**: a short note that `/me/...` resources may be versioned without being syncable (preferences), mirroring `/me/profile`'s write rules.
- **No new error code**; `info.version` v1.14 (additive).

## Complexity Tracking

| Violation | Why needed | Simpler alternative rejected because |
|---|---|---|
| A ~150-line in-house FCM HTTP v1 adapter with RS256 JWT signing instead of the Firebase SDK | The owner has approved no new dependency; push is the feature | The SDK (`kreait/firebase-php`, `google/auth`) needs approval; the legacy server-key API no longer exists |
| A third copy of the XChaCha20 sealer (after Admin and Billing) | P-02 forbids importing another module's Infrastructure; the token has no user data key context worth binding to | Moving the sealer to `Shared` would put key handling (config access) into the persistence-free shared kernel, which P-02 rule 5 keeps to value objects |
| Preferences versioned with 409 but not syncable | Two phones must not overwrite each other; the screen reads on demand | A user-feed entity type costs a Sync change and client sync code for a value only the server acts on |

## Project Structure

### Documentation (this feature)

```text
specs/023-devices-push/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/push-api.md
├── checklists/requirements.md
├── checklists/privacy-delivery.md   # /speckit-checklist
├── tasks.md                         # /speckit-tasks
└── summary.md                       # written by the parent after implementation
```

### Source Code (repository root)

```text
app/Modules/Notifications/
├── Domain/
│   ├── Registration/            # PushRegistrationRecord, RegistrationRefusals (SessionNotCurrent)
│   ├── Preferences/             # Preferences, Category (enum), QuietHours, PreferencesRules, PreferencesVersionConflict
│   ├── Messages/                # MessageType (enum: category, allowed keys, collapse template, priority),
│   │                            # NotificationDraft, PushMessage, PayloadBuilder
│   ├── Delivery/                # PushGateway (port), PushDestination, GatewayOutcome, DeliveryPolicy,
│   │                            # PolicyDecision, NotificationState, SkipReason, DeliveryState
│   └── Ports/                   # RegistrationRepository, PreferencesRepository, NotificationRepository,
│                                # DeliveryRepository, TokenSealer, AccessTokenSource
├── Application/
│   ├── DeviceService.php        # register, unregister, dropForSession, pruneIdle
│   ├── PreferencesService.php   # read, save
│   ├── NotificationService.php  # create(draft) → dedupe, policy, dispatch
│   ├── Delivery/                # DeliverNotification (job), ReleaseHeld, PushText (translator)
│   ├── Reminders/               # SendOccurrenceReminders
│   ├── Listeners/               # DropRegistrationOfEndedSession, NotifyInvitationReceived,
│   │                            # NotifyMemberRoleChanged, NotifyMemberRemoved, NotifyOwnershipReceived,
│   │                            # NotifyBudgetExceeded, NotifySubscriptionRenewalDue,
│   │                            # NotifySubscriptionLapsed, NotifyExportReady, NotifyDeletionReminder
│   ├── Departure/NotificationsPurge.php   # AccountPurgeParticipant, step `notifications`
│   ├── NotificationFigures.php  # counts for Admin
│   └── NotificationsServiceProvider.php
├── Infrastructure/
│   ├── Push/                    # FcmPushGateway, ServiceAccountTokenSource, ServiceAccountKey, Jwt (RS256),
│   │                            # FcmErrorMap, LogPushGateway, DisabledPushGateway
│   ├── Crypto/                  # NotificationsSealer, NotificationsKeyring, TokenLookup
│   ├── Models/                  # PushRegistration, NotificationPreference, PushNotification, PushDelivery
│   ├── Repositories/            # Eloquent*
│   ├── Database/Migrations/
│   │   ├── 2026_10_12_000001_create_push_registrations_table.php
│   │   ├── 2026_10_12_000002_create_notification_preferences_table.php
│   │   └── 2026_10_12_000003_create_push_notifications_and_deliveries_tables.php
│   └── NotificationsPersistenceServiceProvider.php
└── Http/
    ├── Controllers/             # DevicesController, NotificationPreferencesController
    ├── Requests/                # RegisterDeviceRequest, SavePreferencesRequest
    ├── Resources/               # PushRegistrationResource, NotificationPreferencesResource
    ├── Console/                 # OccurrenceRemindersCommand, ReleaseHeldCommand, PruneCommand
    ├── Lang/{en,ar}/push.php
    ├── routes.php
    └── NotificationsHttpServiceProvider.php

app/Modules/Budgeting/
├── Domain/Events/BudgetExceeded.php, Domain/Ports/OverrunRepository.php
├── Application/Overruns/DetectBudgetOverruns.php, Application/Listeners/EvaluateBudgetsAfterPosting.php
├── Application/BudgetService.php                 # + forBudget after limit change
└── Infrastructure/Database/Migrations/2026_10_12_000004_create_budget_overruns_table.php (+ model, repository)

app/Modules/Wallets/Application/Recurring/OccurrenceReminderQuery.php (+ DTO OccurrenceReminder)
app/Modules/Wallets/Application/TransactionQueryService.php              # + authorAndCurrencyOf
app/Modules/Sharing/Application/InvitationQueryService.php               # + verifiedAddresseeOf
app/Modules/Billing/Domain/Events/SubscriptionRenewalDue.php; Application/Reminders/SendRenewalReminders.php
app/Modules/Identity/Domain/Events/{ExportReady,DeletionReminderDue}.php
app/Modules/Identity/Application/Sessions/SessionStatus.php; Application/Profiles/RecipientProfile.php
app/Modules/Identity/Application/Export/GenerateExport.php; Deletion/SendDeletionReminders.php
app/Modules/Identity/Domain/Deletion/PurgeStep.php                        # + Notifications
app/Modules/Identity/Infrastructure/Database/Migrations/2026_10_12_000005_allow_notifications_purge_step.php
app/Modules/Admin/Application/Accounts/AccountSnapshot.php; Overview/OverviewFigures.php (+ views/lang)
config/notifications.php; bootstrap/providers.php; routes/console.php; .env.example; phpunit.xml (keys)

docs/openapi/paths/notifications.yaml, docs/openapi/openapi.yaml (index, v1.14)   # done in this phase
docs/adr/0025-…, docs/glossary.md, docs/security-baseline.md, docs/privacy-commitments.md,
docs/api-conventions.md, .specify/memory/constitution.md, specs/briefs/README.md

tests/Unit/Notifications/…, tests/Feature/{Notifications,Budgeting,Wallets,Sharing,Billing,Identity,Admin}/…,
tests/Contract/Notifications/{DevicesTest,PreferencesTest}.php, tests/Fakes/FakePushGateway.php
```

**Structure Decision**: one new module directory with the standard four layers and three providers (Application, Persistence, Http) as Budgeting and Billing do; every other module gains only Application-layer methods, an event class, or (Budgeting) one table.

## Phase 0 / Phase 1 outputs

- [research.md](./research.md) — R-1 … R-18, no NEEDS CLARIFICATION left.
- [data-model.md](./data-model.md) — five tables, state machine, dedupe keys, baseline rows, configuration.
- [contracts/push-api.md](./contracts/push-api.md) — what C16 binds to; machine-readable in `docs/openapi/paths/notifications.yaml`.
- [quickstart.md](./quickstart.md) — validation run.

## Assumed defaults added at planning (A-n)

Owner away with delegated authority; each the conservative option.

- **A-1** Gateway default when unset is `disabled`; `.env.example` sets `log` for local work.
- **A-2** FCM credentials via `NOTIFICATIONS_FCM_CREDENTIALS` (path or base64 JSON); never committed; `phpunit.xml` sets the `fake` binding and throwaway sealer/lookup keys.
- **A-3** Access token cached in the default cache store (database) with a lock; 5-minute early refresh.
- **A-4** `android.ttl` 24 hours; priority `HIGH` only for due/refused/deletion reminders.
- **A-5** The summary carries no count; the text is fixed.
- **A-6** Retention prune and idle-registration prune in one daily `notifications:prune`.
- **A-7** `notifications:release-held` every 5 minutes also re-queues stale `sending`.
- **A-8** Jobs on a dedicated `notifications` queue name on the existing connection; workers must listen to it (deployment note).
- **A-9** Paging the reminder query by occurrence id (UUIDv7 used as a tiebreak cursor only, not as a business order — P-06 rule 4 respected).
- **A-10** Overrun evaluation ignores transactions dated outside the current period; past-period edits never alert.
- **A-11** `INVALID_ARGUMENT` not tied to the token marks the delivery `failed` without pruning or retry (our message bug must not delete a good registration).

## Implementation notes

- **R-12 index decision (T036/T038)**: no partial index migration was added in Wallets. Measured with `PUSH_PERF=1` (`tests/Feature/Notifications/OccurrenceRemindersPerformanceTest.php`): 10,000 active rules with a due occurrence each, spread over 100 people (100 summaries, 10,000 `summarised` markers), one full pass took about 42 s against the 10-minute target (SC-008).
- **Performance test** is opt-in; run it in the container with `dev exec -- env PUSH_PERF=1 vendor/bin/pest tests/Feature/Notifications/OccurrenceRemindersPerformanceTest.php`.
- **Log redaction (T058)**: the central `RedactSecrets` pattern gained `assertion`, `private_key` and `push_` (the last covers `push_token`); `token` was already covered, which includes `token_sealed` and `access_token`. `TokenNeverLeaksTest` proves the token appears in no response, job, failed job, log line, event or table other than its sealed column.
- **Contract review (T059)**: the four operations are exercised in `tests/Contract/Notifications` with `assertValidRequest()` and `assertValidResponse()` against the frozen `docs/openapi/paths/notifications.yaml`; no deviation found, no contract change made. The reviewer-owned privacy checklist markers stay unchecked by design.
- **Migrations**: five B16 migrations (push_registrations, notification_preferences, push_notifications and push_deliveries, budget_overruns, allow_notifications_purge_step), all additive, applied to dev after their tests passed.
