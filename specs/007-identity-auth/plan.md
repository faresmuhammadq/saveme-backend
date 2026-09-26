# Implementation Plan: Identity & Authentication

**Branch**: `007-identity-auth` (trunk-based; work lands on `main`) | **Date**: 2026-09-26 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/007-identity-auth/spec.md`, plus the owner's input at planning: "use sanctum for auth, notify admin via email and in dashboard notification".

## Summary

Build the Identity module on Laravel Sanctum, used as a bearer-only guard. Each **Device Session is the token record**:

- the client supplies its identifier, so a sign-in retry rotates the credential rather than duplicating the session;
- the credential secret is 381 bits, stored as SHA-256;
- revocation leaves the row in place and takes effect on the next request;
- the session expires after 90 days of inactivity.

The replacement for the starter-kit user:

- stores the address encrypted, under a per-account data key wrapped by a rotatable keyring, beside an HMAC lookup value;
- hashes passwords with argon2id and rehashes on sign-in.

Registration creates the user, the profile and the Free subscription in one transaction. Until B3, the subscription goes through a provisional Billing application service.

The feature also delivers:

- verification and reset links;
- two-scope lockout keyed by lookup value, so it can't be used to discover accounts;
- uniform answers with matched work on both paths;
- password change;
- the deletion request with its grace period, where a new sign-in cancels it;
- the append-only audit log.

When a sign-in cancels a deletion, the **Admin** module notifies operators **by email and with a dashboard notification** in the shell's notifications slot. The notification is stored now; the bell becomes reachable when operator sign-in (D2) lands.

B1 is also the first API feature, so it builds the shared **API runtime** that spec 003 fixed on paper: envelope, problem rendering, the error-code enum, request ids, the transport, content and client-version gates, localisation, rate-limit headers, and log redaction.

## Technical Context

**Language/Version**: PHP 8.5.10 (container), Laravel 13.31.

**Primary Dependencies**: **new**: `laravel/sanctum ^4.3` (owner approved 2026-09-26, R-1). Existing: libsodium (XChaCha20-Poly1305, R-6), `intl` (NFKC, R-7), argon2id (confirmed in the `dev` image), Livewire 4 (the operator bell), Spectator (contract tests). No other new package.

**Storage**: PostgreSQL 16.
- Identity: `users`, `user_keys`, `profiles`, `device_sessions`, `identity_links`, `sign_in_failures`, `audit_entries`, and the sequence `sync_change_seq`.
- Admin: `operator_notifications`.
- The cache store holds the per-origin limiter; the queue carries mail and listeners.

**Testing**: Pest 5.
- Unit: normalisation and lookup, password policy, the cipher, the audit vocabulary, session state rules.
- Feature: application services against PostgreSQL, including the triggers and the lockout.
- Contract: every endpoint and every documented status through Spectator, plus the API runtime.
- Arch: the existing P-02 gates cover the new Identity, Billing and Admin layers.
- The timing and at-rest checks run from quickstart.

**Target Platform**: a Linux server (the `dev` container locally). The client is the Android app.

**Project Type**: modular-monolith web service (JSON API), with the Livewire back office.

**Performance Goals**:
- Sign-in p95 under 600 ms at production hash cost (one argon2id verify of 250–500 ms dominates).
- An authenticated request adds one indexed primary-key read and one `last_used_at` write.
- Session list p95 under 100 ms for up to 50 sessions.

**Constraints**: from the baseline:
- no cache of credential validity;
- no readable address stored;
- uniform answers on four surfaces;
- the audit log is append-only;
- no secrets in logs.

Also: the P-02 layer rules. In particular, Http never imports its own Infrastructure, so controllers pass the authenticated identifier to Application services.

**Scale/Scope**:
- 13 operations (12 live, 1 reserved) and 7 Identity tables;
- 1 Admin table plus 1 Livewire component;
- 2 provisional Billing interfaces;
- the API runtime (7 middleware, a renderer, an envelope, the error-code enum);
- 6 mails in 2 languages;
- about 30 test files.

## Constitution Check

*Constitution v1.3.0 (`.specify/memory/constitution.md`). GATE: must pass before Phase 0 research. Re-check after Phase 1 design, keeping both results.*

### Pre-design

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | Uses the glossary's User, Profile and Device Session. "Token" appears only inside the auth layer (the Sanctum column, the `credential` field is named as such). Adds glossary entries for Audit Entry and Operator Notification, and the User cancellation path, in this change |
| P-02 Module boundaries | PASS | Everything sits in `app/Modules/Identity/{Domain,Application,Infrastructure,Http}`. Identity reaches Billing only through `Billing\Application\SubscriptionService` and `EntitlementService`, and Admin reacts to Identity only through the `UserDeletionCancelled` event. Other modules use the verification gate as the string alias `address.verified`. Controllers never import Identity Infrastructure. The runtime sits in `app/Http/Api` (R-15), and `Shared` gains only the `ErrorCode` enum and the `Refusal` exception, both persistence-free |
| P-03 Money | N-A | Handles no money |
| P-04 Ledger integrity | N-A | No ledger code |
| P-05 Derived balances | N-A | No balances |
| P-06 Identifiers | PASS | Every primary key is UUIDv7. `device_sessions.id` is client-generated, so sign-in is idempotent by it. An id owned by another account answers 404 and is untouched (R-4). UUID timestamps are never used for ordering: sessions sort by `signed_in_at` |
| P-07 Sync and concurrency | PASS | Profile and Device Session carry `version`, `change_seq`, `updated_at` and `deleted_at` (R-19). Device Session has no client write operation. Its transitions are server commands (sign-in, end, expire), terminal and idempotent, so rule 2's client version does not apply. There is no upsert endpoint for either in B1. Sessions are never hard-deleted |
| P-08 Authorization | PASS | Session operations are owner-only, and anything else answers 404. No wallet is touched. The app guard is `api` (Sanctum). The `web` guard has no user provider, so an app user cannot reach `/admin` (rule 4). Operators have no role in B1 |
| P-09 Entitlements | PASS | The sign-in response reports `EntitlementService::summaryFor()`. No plan code is compared, and `PlanCode` is not used. The provisional Free plan lives in Billing and is replaced by B3 |
| P-10 Billing channels | PASS | Nothing billing-related is exposed. `UserSignedIn` is only the seam B10's restore uses |
| P-11 Events | PASS | Five events, all `ShouldDispatchAfterCommit`, carrying scalars plus `event_id`. The Admin listener is queued and idempotent through `source_event_id` |
| P-12 Time | PASS | Every instant is `timestamptz` UTC. The profile timezone is stored as an IANA name, never applied at write time. Lock, link and grace periods are computed in UTC |
| P-13 Naming | PASS | No codename anywhere. Mail branding comes from `config('app.name')` and translations |
| P-14 Localisation | PASS | Mails, problem `detail` strings and operator notification text in `ar` and `en`, with completeness tests. Western numerals in dates within mails |
| P-15 Observability | PASS | `X-Request-Id` and correlation propagation are implemented here (R-15). The `RedactSecrets` processor keeps passwords, credentials and secrets out of logs (rule 4). The audit log is separate from logs |
| P-16 Quality gates | PASS | Each spec invariant names its test (spec §4). The new dependency is approved (R-1). The suite runs on PostgreSQL, where the triggers and partial indexes are exercised |
| P-17 Scope guard | PASS | No guarded capability. Google Sign-In ships switched off, and phone/OTP sign-in is excluded |
| P-18 Delivery surfaces | PASS | Every user capability is a `/api/v1` endpoint (rule 1). The operator bell is a Livewire component in `Admin/Http/Livewire` (rule 3), reading through `OperatorNotificationQueryService` (rule 5), and holding scalars only. It shows ids, never amounts or balances (rule 8). No new `/admin` route (rule 4 and spec 006 FR-051 hold until D2) |

### Post-design re-check

Unchanged. All rows are PASS or N-A. Phase 1 design points worth naming:

- **P-02**: the Billing seam is two interfaces plus a provisional class, all in `Billing/Application`. Admin's new Application and Infrastructure layers fall under the existing gates.
- **P-07**: rotating a session on a retried sign-in bumps `version` on the same row. It does not create a sibling.
- **P-08 / P-18**: the `web` guard losing its provider is asserted by `tests/Feature/Identity/GuardSurfaceTest.php`.
- **Security baseline**: two amendments are made in this change and neither weakens a rule. The audit entry's `subject_id` may be null for failures on unknown addresses (data-model), and the deletion section gains cancel-by-sign-in (spec FR-057). The protected-attributes table gains four rows.

Complexity Tracking stays empty.

## Project Structure

### Documentation (this feature)

```text
specs/007-identity-auth/
├── spec.md
├── plan.md                         # this file
├── research.md                     # R-1 … R-25
├── data-model.md                   # tables, states, config, baseline table rows
├── quickstart.md                   # gates, manual walk, timing and at-rest checks, key rotation
├── contracts/
│   ├── identity-api.md             # design of docs/openapi/paths/identity.yaml
│   ├── events-and-notifications.md # events, operator notification, mails
│   └── api-runtime.md              # what every /api/v1 response gets, and its tests
├── checklists/requirements.md
└── tasks.md                        # /speckit-tasks
```

### Source Code (repository root)

```text
app/Http/Api/                                   # shared API runtime (R-15)
├── Envelope.php
├── Problems/{ProblemRenderer,ProblemResponse}.php
├── Middleware/{AssignRequestId,RefuseInsecureTransport,NegotiateJson,
│              RequireClientVersion,ResolveContentLanguage,StandardRateLimitHeaders}.php
└── Logging/RedactSecrets.php

app/Modules/Shared/Domain/Errors/{ErrorCode,Refusal,FieldError}.php

app/Modules/Identity/
├── Domain/
│   ├── Address/{EmailAddress,AddressNormaliser}.php         # normalisation (pure)
│   ├── Password/PasswordPolicy.php
│   ├── Sessions/{DeviceDescription,SessionState,EndReason}.php
│   ├── Account/{AccountState,DeletionSchedule}.php
│   ├── Audit/{AuditEvent,ActorType,SubjectType}.php
│   ├── Events/{UserRegistered,UserSignedIn,DeviceSessionEnded,
│   │           UserDeletionRequested,UserDeletionCancelled}.php
│   ├── Refusals/{InvalidCredentials,AccountLocked,EmailNotVerified,
│   │             VerificationLinkInvalid,ResetLinkInvalid,SessionNotFound}.php   # extend Shared Refusal
│   └── Ports/{UserRepository,SessionRepository,LinkRepository,FailureCounter,
│              AttributeCipher,AddressLookup,SessionIssuer,AuditStore}.php
├── Application/
│   ├── Registration/RegisterAccount.php
│   ├── Verification/{VerifyAddress,ResendVerification}.php
│   ├── VerificationGate.php
│   ├── SignIn/SignIn.php                                    # lockout, rehash, rotate/replace, cancel deletion
│   ├── Sessions/{ListSessions,EndSession,EndOtherSessions,SignOut}.php
│   ├── Passwords/{RequestReset,ResetPassword,ChangePassword}.php
│   ├── Deletion/{RequestDeletion,CancelDeletion}.php
│   ├── Audit/{AuditLog,AuditRecord}.php
│   ├── Jobs/{SendRegistrationMail,SendResetMail}.php          # the uniform-work jobs (R-10)
│   ├── Mail/{VerifyAddressMail,ResetPasswordMail,PasswordChangedMail,
│   │         DeletionScheduledMail,DeletionCancelledBySignInMail}.php
│   └── Dto/{AccountView,DeviceSessionView,SignInResult}.php
├── Infrastructure/
│   ├── IdentityPersistenceServiceProvider.php               # port bindings, Sanctum model and callback
│   ├── Models/{User,UserKey,Profile,DeviceSession,IdentityLink,SignInFailure,AuditEntry}.php
│   ├── Repositories/…                                       # port implementations
│   ├── Crypto/{SodiumAttributeCipher,Keyring,HmacAddressLookup}.php
│   ├── Sessions/SanctumSessionIssuer.php
│   ├── Sync/ChangeSequence.php                              # sync_change_seq (R-19)
│   ├── Console/{ExpireSessions,RewrapKeys,TimingProbe}.php  # TimingProbe local-only
│   └── Database/{Migrations,Factories}/…                    # users … audit_entries, trigger, sequence
└── Http/
    ├── IdentityServiceProvider.php                          # routes, alias address.verified, limiters, views, lang
    ├── routes.php
    ├── Controllers/{Register,VerifyEmail,ResendVerification,Login,Sessions,
    │               Logout,ForgotPassword,ResetPassword,ChangePassword,DeletionRequest}Controller.php
    ├── Requests/…                                           # base request refuses unknown members
    ├── Resources/{AccountResource,DeviceSessionResource}.php
    ├── Middleware/EnsureAddressVerified.php
    ├── Views/mail/*.blade.php
    └── Lang/{en,ar}/{mail,validation}.php

app/Modules/Billing/Application/                             # provisional, replaced by B3 (R-11)
├── BillingServiceProvider.php
├── SubscriptionService.php
├── Entitlements/{EntitlementService,EntitlementSummary}.php
└── Provisional/ProvisionalFreePlan.php

app/Modules/Admin/
├── Application/
│   ├── Listeners/NotifyOperatorsOfDeletionCancelledBySignIn.php
│   ├── OperatorNotificationQueryService.php
│   ├── Dto/OperatorNotificationView.php
│   ├── Mail/OperatorNotificationMail.php
│   └── Ports/OperatorNotificationStore.php
├── Infrastructure/
│   ├── AdminPersistenceServiceProvider.php
│   ├── Models/OperatorNotification.php
│   ├── Repositories/EloquentOperatorNotificationStore.php
│   └── Database/Migrations/…_create_operator_notifications_table.php
└── Http/
    ├── Livewire/OperatorNotificationBell.php
    ├── Views/livewire/operator-notification-bell.blade.php
    └── Lang/{en,ar}/notifications.php

bootstrap/app.php            # api group middleware, problem rendering, API fallback
bootstrap/providers.php      # + Identity, Billing, Admin persistence providers
config/{auth,sanctum,hashing,identity,api,admin,logging}.php
lang/{en,ar}/problems.php
database/migrations/0001_01_01_000000_create_users_table.php   # users + reset tokens removed (R-20)
app/Models/User.php, database/factories/UserFactory.php        # deleted

docs/openapi/paths/identity.yaml, docs/openapi/openapi.yaml (paths index, v1.2)
docs/glossary.md             # Audit Entry, Operator Notification, User states
docs/security-baseline.md    # deletion by sign-in, audit subject_id, attribute rows, route budgets
docs/privacy-commitments.md  # "Leaving", both languages, mapping row
docs/engineering-conventions.md  # app/Http/Api row, HASH_ARGON_* env row, new env keys
.specify/memory/constitution.md  # Identity row events, PATCH 1.3.1

tests/Unit/Identity/{AddressLookup,PasswordPolicy,AttributeCipher,AuditVocabulary,SessionState}Test.php
tests/Feature/Identity/{Registration,AddressAtRest,SessionRevocation,SessionLifetime,SignInIdempotency,
    SessionOwnership,Lockout,VerificationLink,VerificationGate,PasswordReset,PasswordChange,
    AccountDeletion,DeletionCancelledBySignIn,AuditTrail,UniformWork,MailTranslation,GuardSurface}Test.php
tests/Feature/Admin/{OperatorNotifications,OperatorNotificationBell}Test.php
tests/Feature/Support/LogRedactionTest.php
tests/Contract/Api/{Envelope,Problem,RequestId,Transport,ContentNegotiation,ClientVersion,
    Localisation,RateLimitHeaders}Test.php
tests/Contract/Identity/{Register,VerifyEmail,Login,Sessions,PasswordReset,PasswordChange,
    AccountDeletion,GoogleSignIn,NoSecretsInResponses}Test.php
tests/Contract/OpenApi/ErrorCodeParityTest.php
```

**Structure Decision**: the Identity module uses all four layers. Billing gains only `Application`, holding the provisional seam B3 replaces. Admin gains `Application` and `Infrastructure` for operator notifications. The cross-cutting API runtime lives in `app/Http/Api`, next to the starter kit's existing `app/Http`. It is framework plumbing like `app/Providers/ModuleServiceProvider`, not a bounded context, and the engineering conventions record it. Each module with persistence bindings gets an Infrastructure-layer provider, since only Infrastructure may import its own models, and an Http-layer provider for routes, views, language files and aliases.

## Complexity Tracking

No Constitution Check violations; nothing to justify.

Two scope notes the reviewer should see, since they are not violations:

1. **The API runtime is built here** (R-15). It is the implementation of spec 003's contract, which no feature had built yet. B2 onward reuse it.
2. **Billing interfaces are created ahead of B3** (R-11), so the registration transaction boundary exists from the first commit.
