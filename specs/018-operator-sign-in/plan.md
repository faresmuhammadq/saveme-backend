# Implementation Plan: Operator Sign-in

**Branch**: `018-operator-sign-in` (trunk-based; work lands on `main`) | **Date**: 2026-10-04 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/018-operator-sign-in/spec.md`

## Summary

Add the back office's lock, inside the Admin module:

- **Operator Accounts** on a separate `operator` session guard, with email and display name sealed at rest under Admin's own keyring and found by a keyed lookup value.
- **Two-step sign-in** (password, then a six-digit code or a recovery code), with the half-finished state kept out of the guard so a missing factor can never count as signed in.
- **In-house RFC 6238** codes (HMAC-SHA1 via `hash_hmac`, in-house base32, ±1 step, replay refused by the last accepted step), proven by the RFC vectors. No new dependency; no QR picture (setup key and setup link instead).
- **Recovery codes** (ten, single use, keyed hashes, shown once in the response that made them), **two-operator re-enrolment** with re-confirmation and reasons, a **security page**, and a reusable **re-confirmation trait** for sensitive actions.
- **Provisioning** by artisan commands only (create, reset password, deactivate) and a scheduled expiry of re-enrolment requests.
- **Guard by default**: a route group plus persistent middleware plus a `BackOfficeComponent` base whose `boot()` re-checks on every Livewire request, enforced by an arch gate; sessions end at 30 minutes idle, 12 hours absolute, or on a credential-epoch change.
- **Audit** through Identity's log via `AdminAuditService`: access events reused with a new `operator` subject type, everything else as `admin_action`.
- The shell's operator block is bound by a view composer, with no shell edit.

## Technical Context

**Language/Version**: PHP 8.5, Laravel 13.31, Livewire 4.4.5 (bundled Alpine), Tailwind 4, Pest 5.

**Primary Dependencies**: none new (R-1, R-4). Core PHP `hash_hmac`, `random_bytes`, `random_int`, libsodium (`sodium_crypto_aead_xchacha20poly1305_ietf_*`, already used by Identity). Laravel session guard, `RateLimiter`, argon2id hasher (configured in spec 007).

**Storage**: PostgreSQL 16. Four Admin tables (`operator_accounts`, `operator_recovery_codes`, `operator_reenrolment_requests`, `operator_reenrolment_approvals`) plus `operator_sign_in_failures`; one Identity constraint change on `audit_entries`. Sessions in the existing `sessions` table (database driver).

**Testing**: Unit (TOTP, base32, recovery-code format, password policy), Feature (services, controllers, Livewire components, commands, sessions, lockout, audit), Arch (component base, command set, no factor switch). Contract: nothing new; `tests/Contract/OpenApi` must stay green. Browser QA with Playwright later, against the `data-test` hooks in [contracts/routes-and-screens.md](./contracts/routes-and-screens.md).

**Target Platform**: evergreen desktop browsers; the `dev` container locally; HTTPS deployments.

**Project Type**: server-rendered back office (Livewire and Blade) inside the modular-monolith web service.

**Performance Goals**: step 1 bounded by one argon2id verification (250–500 ms by the baseline), equal for an unknown email; step 2 under 50 ms server time; the per-request guard check adds one primary-key read of `operator_accounts`.

**Constraints**: P-18 rules 2–7; no new dependency; nothing readable at rest beyond what the baseline lists; no session or log holds a secret, code or recovery code; logical (RTL-safe) utilities and tokens only (spec 006 arch gates); English and Arabic.

**Scale/Scope**: a handful of operators. About 13 screens or dialogs, 5 controllers, 5 Livewire components, 1 base component, 2 traits, 4 commands, ~10 application services, 6 domain types, 5 migrations (+1 Identity), 2 translation files per language.

## Constitution Check

*Constitution v1.3.10 (`.specify/memory/constitution.md`). GATE: must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | New terms Operator Account, Recovery Code, Re-enrolment Request are added to the glossary before code uses them (task T002); existing words (operator, back office, audit entry) as defined |
| P-02 Module boundaries | PASS | All code in `app/Modules/Admin/{Domain,Application,Infrastructure,Http}`. Cross-module: Admin Application → Identity Application `AuditLog` (application service), Admin → Identity Domain `AddressNormaliser`, `AuditEvent`, `ActorType`, `SubjectType` (domain types). The sealer is duplicated rather than importing Identity Infrastructure (R-10). Identity's own change (enum case + migration) stays in Identity |
| P-03 Money | N-A | No money |
| P-04 Ledger integrity | N-A | |
| P-05 Derived balances | N-A | |
| P-06 Identifiers | PASS | UUIDv7 for every new row; operator id is the `actor_id`/`subject_id` (uuid columns) |
| P-07 Sync and concurrency | N-A (sync) / PASS (concurrency) | Nothing syncs. Code acceptance and re-enrolment approval lock the row (`FOR UPDATE`) so a code is used once and a request completes once |
| P-08 Authorization | PASS | Rule 4: separate `operator` guard and accounts, no flag on users (ADR-0006 r7–8); `GuardSeparationTest` proves an application token never authenticates there |
| P-09 Entitlements | N-A | Back office has no entitlement gates |
| P-10 Billing channels | N-A | |
| P-11 Events | N-A | No domain events; audit written in-transaction (baseline) |
| P-12 Time | PASS | Instants UTC `timestamptz(6)`; displayed in the operator timezone via the spec 006 presenter; TOTP uses Unix time, independent of zones |
| P-13 Naming | PASS | Product name from config/translations; issuer label from `config('admin.auth.issuer')` |
| P-14 Localisation | PASS | Every string in `admin::auth` / `admin::security` in `en` and `ar`; Western digits; LTR isolation for codes, keys, emails (`TranslationCompletenessTest`, `DirectionTest` extended) |
| P-15 Observability | PASS | Nothing secret reaches logs: no logging of input; the central redaction scrubber (`RedactSecrets`, whose pattern already covers `password` and `secret`) gains `one_time_code|recovery_code|setup_key|otpauth`; back-office forms name the code field `one_time_code`, never the generic `code` (which error payloads use) |
| P-16 Quality gates | PASS | Unit, Feature and Arch tests; Pint; Larastan level 8; `composer test`; no new dependency |
| P-17 Scope guard | PASS | Nothing on the MVP guard list; no dark mode; no operator roles; no user-data screens |
| P-18 Delivery surfaces | PASS | r2 Livewire/Blade only; r3 components under `Admin/Http/Livewire`, views in the module; r4 every `/admin` route behind the guard and factor except the sign-in steps and the local gallery, and the Livewire update endpoint re-checked (R-8); r5 public properties scalars and DTOs, recovery codes never public; r6 every write through an Admin application service; r7 `BackOfficeComponent::boot()` re-authorizes each request; r8 no user data shown |

**Security baseline**: "Operator access" and "Second-factor recovery" implemented as written. Amendments made in the same change (task T003), none relaxing a rule: audit `subject_type` gains `operator`; "What is recorded" names Admin as writer of access events about operators; "What is protected at rest" gains six operator rows; a note under "Operator access" records the session limits and re-confirmation window.

**Post-design re-check**: unchanged — all PASS or N-A. Phase 1 added no dependency and no API. The only cross-module writes are through Identity's audit application service. Complexity Tracking records one justified duplication.

## Project Structure

### Documentation (this feature)

```text
specs/018-operator-sign-in/
├── spec.md
├── plan.md                         # this file
├── research.md                     # R-1 … R-19
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── routes-and-screens.md       # routes, gates, screens, copy, data-test hooks
│   ├── second-factor.md            # TOTP, base32, setup link, recovery codes, re-confirmation
│   └── commands-and-audit.md       # artisan commands, audit mapping
├── checklists/
│   ├── requirements.md
│   └── security.md                 # /speckit-checklist
└── tasks.md                        # /speckit-tasks
```

### Source Code (repository root)

```text
app/Modules/Admin/
├── Domain/
│   ├── SecondFactor/{Totp,Base32,RecoveryCode}.php
│   ├── Operators/{OperatorState,OperatorPasswordPolicy,SignInStage,ReenrolmentState,AdminAction}.php
│   ├── Operators/Refusals/{InvalidOperatorCredentials,SecondFactorInvalid,OperatorLocked,PendingSignInExpired,
│   │                       PasswordRuleBroken,ReenrolmentRefused,LastActiveOperator}.php
│   └── Ports/{OperatorRepository,RecoveryCodeStore,ReenrolmentStore,OperatorFailureCounter,SecretSealer,EmailLookup,RecoveryCodeHasher}.php
├── Application/
│   ├── Audit/{AdminAuditService,AdminActionDetail,OperatorSignInDetail}.php
│   ├── Operators/{CreateOperator,ResetOperatorPassword,DeactivateOperator,OperatorDirectory}.php
│   ├── SignIn/{CheckPassword,SecondFactorVerifier,CompleteSignIn,SignOut,OperatorOriginLimiter,FailureRecorder}.php
│   ├── Enrolment/{BeginEnrolment,ConfirmEnrolment,IssueRecoveryCodes,ReplaceAuthenticator}.php
│   ├── Credentials/ChangeOperatorPassword.php
│   ├── Reenrolment/{RequestReenrolment,ApproveReenrolment,DeclineReenrolment,ExpireReenrolmentRequests,ReenrolmentQuery}.php
│   └── Dto/{OperatorView,ReenrolmentRequestView,VerifiedFactor,IssuedRecoveryCodes,EnrolmentSetup}.php
├── Infrastructure/
│   ├── Models/{OperatorAccount,OperatorRecoveryCode,OperatorReenrolmentRequest,OperatorReenrolmentApproval,OperatorSignInFailure}.php
│   ├── Repositories/Eloquent*.php
│   ├── Crypto/{AdminSealer,AdminKeyring,HmacEmailLookup,HmacRecoveryCodeHasher}.php
│   ├── Database/Migrations/2026_10_04_1000xx_create_operator_*.php
│   └── Database/Factories/OperatorAccountFactory.php     # tests only; states: awaitingPasswordChange, awaitingEnrolment, enrolled, deactivated
└── Http/
    ├── routes.php                                          # guest, pending and operator groups (contracts/routes-and-screens.md)
    ├── AdminServiceProvider.php                            # + guard wiring, persistent middleware, view composer, nav entries
    ├── Auth/{PendingSignIn,OperatorSession,IntendedUrl}.php   # session-state helpers shared by middleware and components
    ├── Middleware/{EnsureOperatorSignedIn,EnsurePendingStage,RedirectSignedInOperator,BackOfficeHeaders}.php
    ├── Controllers/{SignInController,SecondFactorController,LostDeviceController,NewPasswordController,EnrolmentController,SignOutController}.php
    ├── Console/{CreateOperator,ResetOperatorPassword,DeactivateOperator,ExpireReenrolmentRequests}.php
    ├── Shell/OperatorPresenceComposer.php
    ├── Livewire/
    │   ├── BackOfficeComponent.php
    │   ├── Concerns/RequiresRecentFactor.php
    │   ├── {Overview,SecurityPage,ReplaceAuthenticator,ReenrolmentRequests}.php
    │   └── OperatorNotificationBell.php                    # now extends BackOfficeComponent
    ├── Views/
    │   ├── components/layouts/auth.blade.php
    │   ├── components/{factor-prompt,code-input,recovery-code-list,setup-key}.blade.php
    │   ├── auth/{sign-in,second-factor,recovery,lost-device,lost-device-sent,new-password,enrol,recovery-codes}.blade.php
    │   └── livewire/{overview,security-page,replace-authenticator,reenrolment-requests}.blade.php
    ├── Lang/{en,ar}/{auth,security}.php                    # + shell.php nav keys
    └── Assets/admin.js                                     # + code-input Alpine, countdown, copy/download, 401 → sign-in hook

app/Modules/Identity/
├── Domain/Audit/SubjectType.php                            # + Operator
├── Application/Audit/Details/SignInFailedDetail.php        # + optional stage
└── Infrastructure/Database/Migrations/2026_10_04_100000_allow_operator_audit_subject.php

config/auth.php                                             # + operator guard, operators provider
config/admin.php                                            # + auth.* (data-model)
routes/console.php                                          # + admin:expire-reenrolment-requests every five minutes
app/Http/Api/Logging/RedactSecrets.php                      # + back-office secret field names
.env.example, phpunit.xml                                   # + ADMIN_* keys
docs/glossary.md, docs/security-baseline.md                 # entries and amendments (T002, T003)

tests/Unit/Admin/{TotpTest,Base32Test,RecoveryCodeTest,OperatorPasswordPolicyTest}.php
tests/Feature/Admin/Auth/{SignInTest,GuardSeparationTest,EnrolmentTest,RecoveryCodesTest,ReenrolmentTest,
                          SecurityPageTest,ReconfirmationTest,OperatorSessionTest,LockoutTest,OperatorAuditTest,
                          OperatorStorageTest,OperatorCommandsTest,ComponentGuardTest,ShellBindingTest}.php
tests/Feature/Admin/AdminRouteSurfaceTest.php               # rewritten to the guarded surface
tests/Feature/Admin/TranslationCompletenessTest.php         # covers auth/security files
tests/Arch/AdminBackOfficeTest.php                          # + BackOfficeComponent rule, admin:* command set, no factor switch
tests/Pest.php                                              # + signInOperator() helper (factory + session state)
```

**Structure Decision**: one module, Admin, using all four layers for the first time. Identity changes only what it owns (an enum case, a detail field, a constraint). Pre-authentication screens are controllers and Blade (no Livewire state before sign-in); post-authentication screens are Livewire components on `BackOfficeComponent`.

## Complexity Tracking

| Violation | Why needed | Simpler alternative rejected because |
|---|---|---|
| `AdminSealer` duplicates ~50 lines of Identity's `Sealer` | P-02 forbids importing another module's Infrastructure; the shared kernel holds no infrastructure | Laravel's `encrypted` cast lacks the key reference the baseline requires (R-10); moving `Sealer` to Shared would need a constitution amendment |

## Implementation notes

Decisions taken while building, under delegated authority, none contradicting the spec, plan or research.

- **Result objects.** Domain ports return `OperatorRecord` / `ReenrolmentRecord` (readonly, sealed values opened by the repository), so the Application layer never sees a model. Refusals extend `RuntimeException`, not the API `Refusal`, because the back office answers with pages. An extra `OperatorCommandRefused` carries the command-line reasons, and `TemporaryPassword` and `FactorPurpose` (sign_in, reconfirm, enrolment) are small Domain types.
- **Password policy** takes `matchesCurrent: bool`; the caller checks the candidate against the stored hash, which is the temporary password while one is pending (the plain temporary password is never stored).
- **Verifier** accepts any operator who can sign in and holds a secret (not only `active`), so an enrolled operator on a reset password passes the code before choosing the new password (FR-063). The failure is recorded after the verifying transaction, so it survives the refusal.
- **Pending state** also carries the proven factor kind and codes-left count (no code), so the single `sign_in_succeeded` entry is written when the last step completes. A stale or missing pending state is checked in `EnsurePendingStage`; the epoch and state are checked by `CompleteSignIn`, so an operator deactivated after step 1 is refused at step 2 like a wrong code.
- **Password change** always moves the epoch; the pending sign-in adopts the new one and the signed-in session adopts it through `OperatorSession::adoptEpoch()`.
- **Security page** asks for the factor before it opens the password dialog, and again on submit if the proof went stale in between. The password fields are reset at the top of the submit, so no password is ever in a snapshot. New recovery codes live in a protected property. The setup key on "Link a new authenticator" is re-derived on each render from the sealed pending secret, only after a proof marks the page `ready` (a locked property).
- **Sidebar sections** (Security, Re-enrolment requests) are registered by `OperatorPresenceComposer` when an operator is signed in, not at boot, so the spec 006 registry and shell tests (which assert an empty registry) stand unchanged. The composer binds the operator block only when the caller passed none, so the gallery keeps its sample. `NavigationRegistry` gained `has()`.
- **Notification bell** is mounted in the `admin::page` layout for signed-in operators (it was waiting for D2) and is now a `BackOfficeComponent`.
- **Closed dialogs.** `dialog:not([open])` is hidden in `admin.css`; the slide-over's `flex` utility had been showing a closed dialog (spec 006 latent defect, found when the bell first rendered).
- **Re-enrolment** requests whose 72 hours have passed but which the scheduler has not yet closed are shown as expired and refused as `expired`; opening a new request first closes the stale one as expired. `RequestReenrolment` hits a 5 per 15 minutes `RateLimiter` before anything else.
- **Lockout** is checked (origin first, then email) before the password is hashed; attempts made while locked are not counted and write nothing.
- **Dev data.** Migrations were applied to the dev database with `dev migrate --force` after their tests passed. Dev `.env` carries generated Admin keys (git-ignored). One operator, `qa.operator@saveme.test` ("QA Operator"), was created during the quickstart walk-through and is enrolled; its audit entries stay for the 400-day retention.
- **Quickstart.** Steps 1 to 5 and 11 were driven in a browser (create, temporary password, forced change, enrolment with the typed digit boxes, recovery codes once, overview, security page, regenerate, sign-out, Arabic step 2). Steps 6 to 10 and 12 are proven by the Feature tests (re-confirmation, lockout, idle, re-enrolment, guard separation, deactivation) because they need clock changes or several operators.
