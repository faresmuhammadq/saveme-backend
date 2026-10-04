---
description: "Task list for spec 018, Operator Sign-in (D2)"
---

# Tasks: Operator Sign-in

**Input**: Design documents from `specs/018-operator-sign-in/` (spec.md, plan.md, research.md R-1…R-19, data-model.md, contracts/routes-and-screens.md, contracts/second-factor.md, contracts/commands-and-audit.md, quickstart.md, checklists/security.md)

**Prerequisites**: spec 006 (shell, primitives, `RequiresReason`, `NavigationRegistry`, `OperatorPresence`) and spec 007 (Identity `AuditLog`, argon2id hasher, `AddressNormaliser`) are shipped. No new composer or npm dependency may be added (CONTRIBUTING item 17; research R-1, R-4).

**Tests**: required by the constitution (P-16) and CONTRIBUTING items 8–11; every invariant in spec §4 has a test task. Write each test first and see it fail. No Contract test: the feature adds no API, and `tests/Contract/OpenApi` must stay green.

**Organization**: by user story. Run everything in the container: `dev exec -- vendor/bin/pest <path>`; Pint with `dev exec -- vendor/bin/pint --dirty --format agent`. Create files with `dev make:… --no-interaction` where a generator exists, then move them into the module path.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1 sign-in · US2 provisioning and enrolment · US3 recovery codes · US4 re-enrolment · US5 security page · US6 sessions and sign-out limits · US7 lockout · US8 guard by default

---

## Phase 1: Setup

- [X] T001 Read `.ai/rules/engineering.md`, CONTRIBUTING.md, `docs/security-baseline.md` ("Lockout", "What is protected at rest", "The audit entry", "What is recorded", "Operator access", "Second-factor recovery"), spec 006 `contracts/reason-prompt.md` and `contracts/shell-and-navigation.md`, and this feature's research R-1…R-19; confirm `dev exec -- vendor/bin/pest tests/Contract/OpenApi tests/Arch` pass before any change.
- [X] T002 [P] Glossary (`docs/glossary.md`), before any code uses the terms: add **Operator Account** (Context Admin; aggregate root; persisted; Sync no — operators only, never through `/api/v1`; States `awaiting_password_change → awaiting_enrolment → active`, any non-deactivated state `→ awaiting_password_change` (reset command; an enrolled secret is kept), `active → awaiting_enrolment` (approved re-enrolment), any → `deactivated`; Referenced by Re-enrolment Request, Audit Entry actor/subject; Notes: separate guard, email and display name sealed with a lookup value, no role in MVP) with **Recovery Code** as a value inside it, and **Re-enrolment Request** (Admin; root; persisted; States `open → completed | declined | expired | cancelled`; Notes: two distinct approvers, neither the requester, 72 hours). Update the entity index table, the "persisted but not synced" table and count, and the "Notifications and Admin" intro (Admin now owns three entities).
- [X] T003 [P] Security baseline amendments (`docs/security-baseline.md`), none relaxing a rule (research R-13, R-10): "The audit entry" `subject_type` adds `operator`; "What is recorded" adds a sentence that Access, Sessions and Credentials events whose subject is an operator are written by Admin; "What is protected at rest" adds rows — operator email (encrypted under Admin's keyring, keyed lookup; operators), operator display name (encrypted; operators), operator password (argon2id; nobody), second-factor secret (encrypted; nobody after enrolment display), recovery codes (keyed hash; nobody after the one display), operator sign-in failure counter (none, keyed by lookup); "Operator access" adds the session limits (30 minutes idle, 12 hours absolute), the 10-minute re-confirmation and "operators are created only from the command line"; the appendix checklist row F-series gains "operator access guarded on every request, including component updates".
- [X] T004 Configuration: in `config/auth.php` add guard `'operator' => ['driver' => 'session', 'provider' => 'operators']` and provider `'operators' => ['driver' => 'eloquent', 'model' => App\Modules\Admin\Infrastructure\Models\OperatorAccount::class]`, keep `defaults.guard = api` and the `web` guard without a provider, and extend the header comment (R-5); in `config/admin.php` add the `auth` block exactly as data-model "Configuration" (keys from `ADMIN_KEYS`, `ADMIN_ACTIVE_KEY`, `ADMIN_LOOKUP_KEY`, `ADMIN_CODE_KEY`; `issuer` "Save Me Back office"; `idle_minutes` 30; `absolute_hours` 12; `pending_minutes` `{second_factor: 5, password_change: 15, enrolment: 15, codes_shown: 15}`; `reconfirm_minutes` 10; `reenrolment_hours` 72; `temporary_password_hours` 72; `lockout` = baseline numbers; `drift_steps` 1); add the four variables (empty) to `.env.example` and fixed base64 test keys to `phpunit.xml`.

---

## Phase 2: Foundational (blocks every story)

### Domain (Unit tests first)

- [X] T005 [P] Unit test `tests/Unit/Admin/Base32Test.php`: RFC 4648 §10 vectors (`""`, `f`→`MY`, `fo`→`MZXQ`, `foo`→`MZXW6`, `foob`→`MZXW6YQ`, `fooba`→`MZXW6YTB`, `foobar`→`MZXW6YTBOI`, unpadded), decode case-insensitive ignoring spaces and `=`, decode refuses `0`, `1`, `8`, `9`, round trip of 100 random 20-byte secrets (32 characters each).
- [X] T006 [P] Unit test `tests/Unit/Admin/TotpTest.php`: the six RFC 6238 SHA-1 vectors and the ten RFC 4226 HOTP values from `contracts/second-factor.md`; `verify()` accepts steps T−1, T, T+1 and refuses T±2; returns the matched step; refuses a step ≤ `lastStep`; refuses non-six-digit input (five or seven digits, letters, empty). The no-early-exit rule (all candidates compared with `hash_equals`) is a code-review item, not a test.
- [X] T007 [P] Unit test `tests/Unit/Admin/RecoveryCodeTest.php`: generated codes are 10 characters of `0123456789ABCDEFGHJKMNPQRSTVWXYZ`, displayed `XXXXX-XXXXX`; normalisation upper-cases, strips spaces and `-`, maps `I`/`L`→`1`, `O`→`0`, and refuses any other length or character.
- [X] T008 [P] Unit test `tests/Unit/Admin/OperatorPasswordPolicyTest.php`: 12–128 characters (`mb_strlen`), not equal to the normalised email, not equal to the current or temporary password; each violation named (`too_short`, `too_long`, `equals_email`, `reused`).
- [X] T009 [P] Create `app/Modules/Admin/Domain/SecondFactor/Base32.php` (R-2) and `app/Modules/Admin/Domain/SecondFactor/Totp.php` (`codeAt(string $secret, int $step): string`, `stepAt(int $unixSeconds): int`, `verify(string $secret, string $input, int $unixSeconds, ?int $lastStep, int $driftSteps = 1): ?int`, `hash_hmac('sha1', pack('J', $step), $secret, true)`, dynamic truncation, `hash_equals` on all candidates — contracts/second-factor.md) and `app/Modules/Admin/Domain/SecondFactor/RecoveryCode.php` (generate with `random_int`, `display()`, `normalise(): ?string`) until T005–T007 pass.
- [X] T010 [P] Create enums in `app/Modules/Admin/Domain/Operators/`: `OperatorState` (`awaiting_password_change`, `awaiting_enrolment`, `active`, `deactivated`), `SignInStage` (`second_factor`, `password_change`, `enrolment`, `codes_shown`), `ReenrolmentState` (`open`, `completed`, `declined`, `expired`, `cancelled`), `AdminAction` (the twelve actions of research R-13), and `OperatorPasswordPolicy` (using `App\Modules\Identity\Domain\Address\AddressNormaliser`) until T008 passes; refusals in `app/Modules/Admin/Domain/Operators/Refusals/` (`InvalidOperatorCredentials`, `SecondFactorInvalid`, `OperatorLocked {int retryAfterSeconds}`, `PendingSignInExpired`, `PasswordRuleBroken {list violations}`, `ReenrolmentRefused {reason: own_request|already_approved|expired|closed|already_open}`, `LastActiveOperator`).
- [X] T011 [P] Create ports in `app/Modules/Admin/Domain/Ports/`: `OperatorRepository` (find by id / by lookup, `lockForUpdate`, save state transitions), `RecoveryCodeStore` (`replaceSet`, `consumeMatching(operatorId, hash): bool`, `unusedCount`), `ReenrolmentStore`, `OperatorFailureCounter` (same contract as Identity's `FailureCounter`), `SecretSealer` (`seal(plaintext, operatorId, field)`, `open(sealed, operatorId, field)`), `EmailLookup` (`lookup(email): string`), `RecoveryCodeHasher` (`hash(normalised): string`).

### Persistence

- [X] T012 Identity change: add `case Operator = 'operator';` to `app/Modules/Identity/Domain/Audit/SubjectType.php`; add optional `?string $stage` to `app/Modules/Identity/Application/Audit/Details/SignInFailedDetail.php` (emitted only when set; existing callers unchanged); migration `app/Modules/Identity/Infrastructure/Database/Migrations/2026_10_04_100000_allow_operator_audit_subject.php` dropping and re-adding `audit_entries_subject_type_check` with `('user', 'wallet', 'session', 'subscription', 'operator')` (down restores the four). Run the existing `tests/Feature/Identity` audit tests.
- [X] T013 Admin migrations in `app/Modules/Admin/Infrastructure/Database/Migrations/` exactly as data-model.md: `2026_10_04_100001_create_operator_accounts_table.php` (all columns, `email_lookup` unique char(64), `state` varchar(30) CHECK in the four states, `display_name_sealed`, `temporary_password_expires_at`, `credential_epoch integer default 0`, and the three CHECKs: `state <> 'active' OR (secret_sealed IS NOT NULL AND enrolled_at IS NOT NULL)`, `state <> 'awaiting_enrolment' OR secret_sealed IS NULL`, `(secret_sealed IS NULL) = (enrolled_at IS NULL)`); `…100002_create_operator_recovery_codes_table.php` (FK cascade, unique `(operator_id, code_hash)`); `…100003_create_operator_reenrolment_requests_table.php` (state CHECK in five states, `(state = 'open') = (closed_at IS NULL)`, partial unique `(operator_id) WHERE state = 'open'`); `…100004_create_operator_reenrolment_approvals_table.php` (unique `(request_id, approver_id)`); `…100005_create_operator_sign_in_failures_table.php` (`lookup char(64)` PK). All instants `timestampTz(…, 6)`; no float columns (`tests/Arch/MigrationsTest.php`).
- [X] T014 [P] Models in `app/Modules/Admin/Infrastructure/Models/`: `OperatorAccount` (implements `Illuminate\Contracts\Auth\Authenticatable` via `Illuminate\Auth\Authenticatable` trait, `HasUuids` with UUIDv7, `$hidden` every sealed and hash column, `getRememberTokenName()` returns `''`, no casts that decrypt), `OperatorRecoveryCode`, `OperatorReenrolmentRequest`, `OperatorReenrolmentApproval`, `OperatorSignInFailure`.
- [X] T015 [P] Crypto in `app/Modules/Admin/Infrastructure/Crypto/`: `AdminKeyring` (reads `admin.auth.keys`/`active_key`, fails fast when missing outside testing), `AdminSealer` implements `SecretSealer` (XChaCha20-Poly1305, `v1.{ref}.{base64}`, associated data `operator:{id}:{field}` — copy of Identity's `Sealer` shape, R-10), `HmacEmailLookup` implements `EmailLookup` (HMAC-SHA256 of `AddressNormaliser::normalise`), `HmacRecoveryCodeHasher` (key `admin.auth.code_key`).
- [X] T016 Repositories in `app/Modules/Admin/Infrastructure/Repositories/` implementing T011's ports (`EloquentOperatorRepository`, `EloquentRecoveryCodeStore`, `EloquentReenrolmentStore`, `EloquentOperatorFailureCounter` with the baseline thresholds from config); bind them in `app/Modules/Admin/Infrastructure/AdminPersistenceServiceProvider.php`.
- [X] T017 [P] Factory `app/Modules/Admin/Infrastructure/Database/Factories/OperatorAccountFactory.php` with states `awaitingPasswordChange(string $temporaryPassword)`, `awaitingEnrolment()`, `enrolled(string $secret = null)` (seals a known secret, `enrolled_at = now`, state `active`), `deactivated()`, `withRecoveryCodes(array $plain)`; it seals through the real `AdminSealer`. Feature test `tests/Feature/Admin/Auth/OperatorStorageTest.php`: a created operator's row contains neither the email, the display name, the secret nor any recovery code in readable form (scan the raw row with `DB::table`), sealed values carry the active key ref, and opening with a wrong associated data fails.

### Audit, session and guard plumbing

- [X] T018 [P] `app/Modules/Admin/Application/Audit/AdminAuditService.php` (methods per row of contracts/commands-and-audit.md, each building an Identity `AuditRecord` with `SubjectType::Operator`), `AdminActionDetail` (`AdminAction`, `?string reenrolmentRequestId`, `?AuditReason reason`; key `reenrolment_request_id`, never `request_id`) and `OperatorSignInDetail` (`factor`, `recovery_codes_left`), both `implements App\Modules\Identity\Application\Audit\Details\AuditDetail`.
- [X] T019 [P] Lockout: `app/Modules/Admin/Application/SignIn/OperatorOriginLimiter.php` (RateLimiter key `admin-origin:{AuditLog::coarsen(ip)}`, 20 / 15 min) and `app/Modules/Admin/Application/SignIn/FailureRecorder.php` (hits account counter and origin limiter, writes `sign_in_failed` with `stage`, writes `lockout_applied` when a lock begins, returns the lock state).
- [X] T020 `app/Modules/Admin/Application/SignIn/SecondFactorVerifier.php` per contracts/second-factor.md "Verifier service": locks the operator row, tries six digits as a code (`Totp::verify` with `last_code_step`, writes the matched step) else as a recovery code (`RecoveryCodeStore::consumeMatching`), refuses when locked (`OperatorLocked`), records failures through T019, returns `VerifiedFactor {kind, codesLeft}` (DTO in `app/Modules/Admin/Application/Dto/`).
- [X] T021 Session helpers in `app/Modules/Admin/Http/Auth/`: `PendingSignIn` (read/write/clear `admin.pending` `{operator_id, stage, started_at, code_failures, epoch}`, staleness by `admin.auth.pending_minutes[stage]`), `OperatorSession` (`start()` sets `admin.signed_in_at`, `admin.last_seen_at`, `admin.epoch`, `admin.factor_confirmed_at`, regenerates the id, logs in the guard; `end()` logs out, invalidates, regenerates the token; `qualifies(): ?string` returns null or the refusal reason `signed_out|idle|absolute|epoch|state`), `IntendedUrl` (stores only same-host `/admin…` paths, FR-015).
- [X] T022 Middleware in `app/Modules/Admin/Http/Middleware/`: `EnsureOperatorSignedIn` (R-7: fresh read of the operator, `OperatorSession::qualifies()`, on refusal end the session and redirect to `admin.sign-in` with the reason flash, or `abort(401)` for a Livewire update request; on success touch `admin.last_seen_at`), `EnsurePendingStage:{stage}`, `RedirectSignedInOperator` (guest pages), `BackOfficeHeaders` (`Cache-Control: no-store, private`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, `X-Content-Type-Options: nosniff`).
- [X] T023 `app/Modules/Admin/Http/Livewire/BackOfficeComponent.php` (abstract, extends `Livewire\Component`; `boot()` runs the same `OperatorSession::qualifies()` check and `abort(401)` on refusal; exposes `protected function operator(): OperatorView`), and `app/Modules/Admin/Http/Livewire/Concerns/RequiresRecentFactor.php` per contracts/second-factor.md "Re-confirmation trait" (locked public `factorMethod`, `factorArguments` scalars, `factorOpen`; `confirmFactor(string $oneTimeCode)` through `SecondFactorVerifier` with purpose `reconfirm`), plus the dialog `app/Modules/Admin/Http/Views/components/factor-prompt.blade.php` composed from `<x-admin::dialog>`, `<x-admin::field>`, `<x-admin::input>` (copy in contracts/routes-and-screens.md "Factor prompt").
- [X] T024 Routes skeleton in `app/Modules/Admin/Http/routes.php`: keep the local gallery; add the three groups of contracts/routes-and-screens.md (guest with `RedirectSignedInOperator`; pending with `EnsurePendingStage`; operator with `EnsureOperatorSignedIn`), all with `BackOfficeHeaders`; in `AdminServiceProvider::boot()` add `EnsureOperatorSignedIn` to `Livewire::addPersistentMiddleware()` beside `ResolveAdminLocale`.
- [X] T025 [P] Auth layout `app/Modules/Admin/Http/Views/components/layouts/auth.blade.php` (split layout of the design board: brand panel at the start with logo, "Save Me · Back office", headline, operators-only paragraph, audit note; 420 px form column; two-segment step indicator prop; language switch at the top end) and the code-input component `app/Modules/Admin/Http/Views/components/code-input.blade.php` (one `name="one_time_code"` field enhanced by Alpine into six boxes: paste of six digits fills all, arrow/backspace move focus, `aria-label` per box in both languages, error in an `aria-live` region, plain field without JS — FR-092); Alpine helpers (code boxes, countdown from `data-server-time`, copy, text download) in `app/Modules/Admin/Http/Assets/admin.js`; translation files `app/Modules/Admin/Http/Lang/{en,ar}/auth.php` and `security.php` with every string of contracts/routes-and-screens.md (re-enrolment hint reads "two other operators").
- [X] T026 [P] Test helpers in `tests/Pest.php`: `makeOperator(string $state = 'enrolled', array $attributes = []): array` (returns the model, plain email, password, secret), `currentCode(string $secret, int $offsetSteps = 0): string` (uses `Totp`), `signInOperator(OperatorAccount $operator): void` (sets the full session state through `OperatorSession::start()`), `signInOperatorPending(OperatorAccount $operator, SignInStage $stage): void`.

**Checkpoint**: domain proven by Unit tests; storage, audit, guard and session plumbing in place; no screen yet.

---

## Phase 3: User Story 1 — Sign in with password and one-time code (Priority: P1) 🎯 MVP

**Goal**: two-step sign-in, landing page, shell binding, sign-out (FR-010–FR-016, FR-090, FR-091).

**Independent Test**: spec US1 — an enrolled operator from the factory signs in with password then a code computed from the known secret, lands on the overview with the operator block; a password-only session reaches nothing.

### Tests for User Story 1

- [X] T027 [P] [US1] Feature test `tests/Feature/Admin/Auth/SignInTest.php`: US1 scenarios 1–9 — redirect to sign-in and back to the intended page; correct password → `admin.sign-in.code` with the email shown and no guarded page reachable; current, previous and next-step codes accepted, ±2 refused; wrong code message and digits cleared; the same step refused twice (also across two sessions); unknown email and wrong password give the identical message and both call the hasher (assert via `Hash::spy` or timing probe pattern of spec 007); "Not you?" discards the pending state; a pending state older than 5 minutes → step 1 with the timeout message; session id differs before step 1, after step 1 and after step 2; argon2id rehash when parameters are below config; `last_signed_in_at` set; exactly one `sign_in_succeeded` entry with `factor: code`; an operator deactivated between step 1 and step 2 is refused at step 2 like a wrong code; an intended URL outside `/admin` (absolute, protocol-relative `//evil.test`, or another host) is ignored and the overview is used (FR-015); the step-2 HTML gives each digit box an `aria-label`, has an `aria-live` error region and submits as one `one_time_code` field without JavaScript (FR-092; paste and focus movement are checked in Playwright QA).
- [X] T028 [P] [US1] Feature test `tests/Feature/Admin/Auth/GuardSeparationTest.php`: an application user's Sanctum bearer token on `/admin`, `/admin/security` and a Livewire update for `SecurityPage` never authenticates on `operator` (redirect / 401); `Auth::guard('operator')` never resolves an Identity `User`; a session holding only `admin.pending` reaches no guarded route.
- [X] T029 [P] [US1] Feature test `tests/Feature/Admin/Auth/ShellBindingTest.php`: on `/admin` the sidebar shows the operator's display name, initial and "Two-factor on" (and Arabic equivalent), the sign-out form posts to `admin.sign-out`; the shell blade file is unchanged (compare with spec 006's file hash in the test's fixture note) and the gallery still renders its sample operator.

### Implementation for User Story 1

- [X] T030 [US1] `app/Modules/Admin/Application/SignIn/CheckPassword.php`: looks up by `EmailLookup`, refuses locked (`OperatorLocked`) before hashing, verifies with argon2id (dummy hash for unknown email), refuses `deactivated` and an expired temporary password as invalid credentials, records failures via T019, rehashes when needed, returns the next stage from the row (data-model "States": secret present → `second_factor`; else `awaiting_password_change` → `password_change`; else `enrolment`).
- [X] T031 [US1] `app/Modules/Admin/Application/SignIn/CompleteSignIn.php`: inside one transaction re-reads the operator, checks the epoch from the pending state, clears the failure counter, sets `last_signed_in_at`, writes `sign_in_succeeded` (`OperatorSignInDetail`); `app/Modules/Admin/Application/SignIn/SignOut.php` writes `session_revoked` with `reason: signed_out`.
- [X] T032 [US1] Controllers `app/Modules/Admin/Http/Controllers/SignInController.php` (`show`, `submit` → `PendingSignIn` + redirect per stage, `restart`) and `SecondFactorController.php` (`show` with `data-server-time` and codes-left hint, `submit` through `SecondFactorVerifier` purpose `sign_in` then `CompleteSignIn` + `OperatorSession::start()` + redirect to `IntendedUrl` or `admin.overview`; 5 wrong codes discard the pending state); `SignOutController.php`. Views `app/Modules/Admin/Http/Views/auth/sign-in.blade.php` and `second-factor.blade.php` on `<x-admin::layouts.auth>` matching the `SignIn`/`SignInAr` boards (version `1791137074-53df`), with the `data-test` hooks of contracts/routes-and-screens.md.
- [X] T033 [US1] Livewire `app/Modules/Admin/Http/Livewire/Overview.php` (extends `BackOfficeComponent`) and view `app/Modules/Admin/Http/Views/livewire/overview.blade.php` (page header, empty state, low-codes alert ≤ 3 — R-18); register `admin.overview`, `admin.sign-in*`, `admin.sign-out` routes.
- [X] T034 [US1] `app/Modules/Admin/Http/Shell/OperatorPresenceComposer.php` registered in `AdminServiceProvider` for `admin::components.layouts.shell`: when an operator is signed in and the caller passed no `operator`/`signOutUrl`, provide `OperatorPresence(displayName, first grapheme via grapheme_substr, 'admin::shell.operator.two_factor_on')` and `route('admin.sign-out')` (R-17); add the `two_factor_on` key to `Lang/{en,ar}/shell.php` if missing.
- [X] T035 [US1] Run `dev exec -- vendor/bin/pest tests/Feature/Admin/Auth/SignInTest.php tests/Feature/Admin/Auth/GuardSeparationTest.php tests/Feature/Admin/Auth/ShellBindingTest.php tests/Unit/Admin` until green.

**Checkpoint**: an enrolled operator can sign in and out.

---

## Phase 4: User Story 2 — First operator and first enrolment (Priority: P1)

**Goal**: commands, forced password change, enrolment with setup key and link, recovery codes shown once (FR-003–FR-005, FR-004a, FR-020–FR-022, FR-026, FR-063).

**Independent Test**: spec US2 — command → temporary password → new password → setup key → code → ten codes → overview; no step skippable.

### Tests for User Story 2

- [X] T036 [P] [US2] Feature test `tests/Feature/Admin/Auth/OperatorCommandsTest.php`: `admin:operator:create` prints the temporary password once, creates `awaiting_password_change` with `temporary_password_expires_at = now + 72 h`, refuses a duplicate (normalised) email and a missing `--name`, writes `admin_action operator_created` (system actor); `admin:operator:reset-password` issues a new password, sets `awaiting_password_change`, increments the epoch, keeps `secret_sealed` and recovery codes, refuses a deactivated operator; `admin:operator:deactivate` refuses the last active operator, increments the epoch, cancels an open re-enrolment request (`reenrolment_cancelled`); all exit codes and messages per contracts/commands-and-audit.md; no output or log line contains anything but the printed temporary password.
- [X] T037 [P] [US2] Feature test `tests/Feature/Admin/Auth/EnrolmentTest.php`: US2 scenarios 3–9 — a pending `password_change` stage reaches only `admin.password.new`; each password rule refused with its message; valid password → `enrolment` stage; setup page shows a 32-character key in groups of four and an `otpauth://totp/…?secret=…&issuer=…&algorithm=SHA1&digits=6&period=30` link; reload within 15 minutes shows the same key, after 15 minutes a new one; wrong code keeps the key; correct code → response 200 contains ten `XXXXX-XXXXX` codes and `Cache-Control: no-store`; the session payload and the database contain none of the ten codes in readable form; a reload of `admin.enrol` shows no codes; `admin.enrol.done` without the checkbox refused, with it → full sign-in → overview; an enrolled reset operator (secret kept) passes the code before choosing a new password; expired temporary password fails like a wrong one; one `password_changed` and one `enrolment_completed` entry.

### Implementation for User Story 2

- [X] T038 [P] [US2] Application services in `app/Modules/Admin/Application/Operators/`: `CreateOperator` (validates email and 1–80 character display name, refuses duplicate lookup, generates a 20-character temporary password from the R-11 alphabet, stores argon2id hash and `temporary_password_expires_at`), `ResetOperatorPassword`, `DeactivateOperator` (refuses `LastActiveOperator`, cancels open request), each writing its `admin_action` entry in the same transaction.
- [X] T039 [P] [US2] Console commands in `app/Modules/Admin/Http/Console/`: `CreateOperator` (`admin:operator:create {email} {--name=}`), `ResetOperatorPassword` (`admin:operator:reset-password {email}`), `DeactivateOperator` (`admin:operator:deactivate {email}`), outputs and exit codes per contracts/commands-and-audit.md.
- [X] T040 [US2] `app/Modules/Admin/Application/Credentials/ChangeOperatorPassword.php` (current-password check when signed in; `OperatorPasswordPolicy`; clears `temporary_password_expires_at`; transitions `awaiting_password_change → (secret ? active : awaiting_enrolment)`; increments the epoch and returns it so the caller keeps its own session; writes `password_changed`).
- [X] T041 [US2] Enrolment services in `app/Modules/Admin/Application/Enrolment/`: `BeginEnrolment` (reuses a pending secret younger than 15 minutes, else generates 20 bytes and seals into `pending_secret_sealed`; returns `EnrolmentSetup {key grouped, otpauth link, label}`), `ConfirmEnrolment` (verifies against the pending secret with `Totp`, moves it to `secret_sealed`, sets `enrolled_at`, `last_code_step`, state `active`, issues recovery codes, writes `enrolment_completed`), `IssueRecoveryCodes` (replace set, returns `IssuedRecoveryCodes` with the plain codes for the caller only).
- [X] T042 [US2] Controllers `app/Modules/Admin/Http/Controllers/NewPasswordController.php` and `EnrolmentController.php` (`show`, `submit` rendering `auth/recovery-codes` directly as the 200 response and switching the stage to `codes_shown`, `done` requiring the checkbox then `CompleteSignIn` + `OperatorSession::start()`); views `auth/new-password.blade.php`, `auth/enrol.blade.php` (with `<x-admin::setup-key>` and `<x-admin::ltr>`), `auth/recovery-codes.blade.php` (with `<x-admin::recovery-code-list>`: two LTR columns, "Copy all", "Download as text" client-side, required checkbox); components `setup-key.blade.php` and `recovery-code-list.blade.php` in `app/Modules/Admin/Http/Views/components/`; register the pending-stage routes.
- [X] T043 [US2] Run `dev exec -- vendor/bin/pest tests/Feature/Admin/Auth/OperatorCommandsTest.php tests/Feature/Admin/Auth/EnrolmentTest.php tests/Feature/Admin/Auth/OperatorStorageTest.php` until green.

**Checkpoint**: a fresh deployment can create its first operator and reach the back office.

---

## Phase 5: User Story 8 — Every later screen is guarded without doing anything (Priority: P1)

**Goal**: guard by default for routes and component updates, with gates that fail when a later feature forgets (FR-070–FR-073, P-18 r4, r7).

**Independent Test**: spec US8 — a fixture route and component registered in the operator group are unreachable signed out, password-only, or with an app token.

- [X] T044 [P] [US8] Rewrite `tests/Feature/Admin/AdminRouteSurfaceTest.php`: in `local`, the `/admin` URIs equal exactly the list in contracts/routes-and-screens.md plus `admin/_gallery`; in `production`/`staging` the same list without the gallery; every route except the guest and pending ones and the gallery carries `EnsureOperatorSignedIn`; every `/admin` route carries `BackOfficeHeaders`.
- [X] T045 [P] [US8] Feature test `tests/Feature/Admin/Auth/ComponentGuardTest.php`: with a test-only component under `tests/Feature/Admin/Fixtures/GuardedFixture.php` extending `BackOfficeComponent` mounted on a fixture route in the operator group, a signed-in operator can call an action; after `OperatorSession::end()`, after idle expiry, after an epoch change, and with only `admin.pending`, an update to `/livewire/update` with the captured snapshot answers 401; `OperatorNotificationBell` also answers 401 signed out; `LocaleSwitcher` still works on the sign-in page.
- [X] T046 [US8] Extend `tests/Arch/AdminBackOfficeTest.php`: every class in `App\Modules\Admin\Http\Livewire` (excluding `Concerns`) extends `BackOfficeComponent` except `LocaleSwitcher` and `Gallery`; the registered `admin:*` artisan command names equal exactly `admin:operator:create`, `admin:operator:reset-password`, `admin:operator:deactivate`, `admin:expire-reenrolment-requests` (FR-027: no switch-off command); no route name or command name contains `disable`, `two-factor-off` or `bypass`.
- [X] T047 [US8] Move `app/Modules/Admin/Http/Livewire/OperatorNotificationBell.php` onto `BackOfficeComponent`; add the `Livewire.hook('request', …)` handler to `app/Modules/Admin/Http/Assets/admin.js` that sends the browser to `/admin/sign-in` on a 401 from a back-office component; run T044–T046 until green.

**Checkpoint**: B3, B11 and B12 can add screens and inherit the guard.

---

## Phase 6: User Story 3 — Recover with a recovery code (Priority: P1)

**Goal**: recovery code at step 2 (FR-023, US3).

**Independent Test**: spec US3 — enrol, sign in with one code, the code is consumed, nine remain.

- [X] T048 [P] [US3] Feature test `tests/Feature/Admin/Auth/RecoveryCodesTest.php`: US3 scenarios 1–5 — recovery screen; an unused code typed lower-case without the hyphen signs in with "You have 9 of 10 left" and `sign_in_succeeded` `factor: recovery_code, recovery_codes_left: 9`; a used code refused like a wrong one and counted as a failure; two concurrent submissions of one code sign in once (row lock); ≤ 3 left shows the overview alert; zero left → "You have no recovery codes left…" pointing to "Lost your device?"; a recovery-code sign-in sets `admin.factor_confirmed_at`.
- [X] T049 [US3] Add `showRecovery`/`submitRecovery` to `app/Modules/Admin/Http/Controllers/SecondFactorController.php` and the view `app/Modules/Admin/Http/Views/auth/recovery.blade.php` (single `recovery_code` field, `XXXXX-XXXXX` hint, link back); register the routes; run T048 until green.

---

## Phase 7: User Story 5 — Manage my own sign-in security (Priority: P2)

**Goal**: security page with password change, recovery-code regeneration and authenticator replacement behind re-confirmation (FR-024, FR-025, FR-060–FR-062).

**Independent Test**: spec US5 — each action asks for a fresh code when the last proof is older than 10 minutes, then takes effect and is audited.

- [X] T050 [P] [US5] Feature test `tests/Feature/Admin/Auth/ReconfirmationTest.php`: a fixture component using `RequiresRecentFactor` runs the protected method at once within 10 minutes of the last proof and opens the prompt after; a correct code (new step) runs it and refreshes `factor_confirmed_at`; the sign-in step's code cannot be replayed in the prompt; a recovery code is accepted and consumed; a wrong code records `sign_in_failed` stage `reconfirm` and counts toward the lock; the protected method cannot be called directly from the browser; locked `factorMethod` cannot be redirected.
- [X] T051 [P] [US5] Feature test `tests/Feature/Admin/Auth/SecurityPageTest.php`: US5 scenarios 1–6 — description list values (linked date, "N of 10", last sign-in in the operator timezone); change password with the current password ends other sessions (a second session's next request is refused) and keeps this one, and the response snapshot after a successful or failed submit contains no password value; regenerate shows ten new codes once (not in public Livewire state: assert the snapshot JSON lacks them), old codes fail, `recovery_codes_regenerated` written; replacing the authenticator keeps the old one until the new code is confirmed, then the old secret fails, `authenticator_replaced` written; after a recovery-code sign-in within 10 minutes no prompt appears.
- [X] T052 [US5] `app/Modules/Admin/Application/Enrolment/ReplaceAuthenticator.php` (pending secret on the operator, confirm swaps `secret_sealed`, resets `last_code_step` to the matched step, keeps codes) and `app/Modules/Admin/Application/Operators/OperatorDirectory.php` (returns `OperatorView`: id, display name, state, enrolled_at, codes left, last sign-in — scalars).
- [X] T053 [US5] Livewire `app/Modules/Admin/Http/Livewire/SecurityPage.php` (extends `BackOfficeComponent`, uses `RequiresRecentFactor`; change-password modal bound to scalar properties that are `reset()` on every submit, success or failure, so no password travels back in a response snapshot; regenerated codes held in a **protected** property rendered once) and `ReplaceAuthenticator.php`, with views `app/Modules/Admin/Http/Views/livewire/security-page.blade.php` and `replace-authenticator.blade.php`; routes `admin.security`, `admin.security.authenticator`; navigation entry `security` (group `configure`, icon `lock`, label `admin::shell.nav.security`); run T050–T051 until green.

---

## Phase 8: User Story 4 — Two other operators approve a re-enrolment (Priority: P2)

**Goal**: request, approve, decline, expire, cancel (FR-030–FR-034, FR-053).

**Independent Test**: spec US4 — three operators; one requests after the password step, two others approve with re-confirmation and reasons; the requester's old factor and codes stop working; the next sign-in goes to enrolment.

- [X] T054 [P] [US4] Feature test `tests/Feature/Admin/Auth/ReenrolmentTest.php`: US4 scenarios 1–10 — request only from the `second_factor` pending stage; second request refused with the expiry shown; the list shows display name, opened, expires, approvals and nothing about factors; approve requires re-confirmation then a non-blank reason (`RequiresReason`); requester and repeat approver refused even with forged calls; two concurrent second approvals complete once; completion sets `awaiting_enrolment`, clears secret, codes and `last_code_step`, increments the epoch (requester's sessions refused), writes `reenrolment_approved` (approver, reason) and `reenrolment_completed` (system); the requester's next password sign-in goes to enrolment and receives ten new codes; decline with a reason closes it; `admin:expire-reenrolment-requests` expires requests past 72 hours (`reenrolment_expired`) and expired ones cannot be approved; with fewer than two eligible approvers the request still opens with the warning; request opening throttled (5 / 15 min).
- [X] T055 [US4] Services in `app/Modules/Admin/Application/Reenrolment/`: `RequestReenrolment`, `ApproveReenrolment` (lock request row, checks of data-model "Re-enrolment Approval", completion in the same transaction), `DeclineReenrolment`, `ExpireReenrolmentRequests`, `ReenrolmentQuery` (returns `ReenrolmentRequestView` list and the open-count for the badge, excluding the viewer's own).
- [X] T056 [US4] `app/Modules/Admin/Http/Controllers/LostDeviceController.php` and views `auth/lost-device.blade.php`, `auth/lost-device-sent.blade.php`; Livewire `app/Modules/Admin/Http/Livewire/ReenrolmentRequests.php` (extends `BackOfficeComponent`, uses `RequiresRecentFactor` then `RequiresReason` with action keys `reenrolment.approve` / `reenrolment.decline`) and view `livewire/reenrolment-requests.blade.php` (data table, empty state); route `admin.reenrolment-requests`; navigation entry `reenrolment-requests` (group `configure`, icon `key`, badge = open requests not by the viewer).
- [X] T057 [US4] Console `app/Modules/Admin/Http/Console/ExpireReenrolmentRequests.php` (`admin:expire-reenrolment-requests`) and `Schedule::command('admin:expire-reenrolment-requests')->everyFiveMinutes()->withoutOverlapping();` in `routes/console.php`; run T054 until green.

---

## Phase 9: User Story 6 — Sign out and session limits (Priority: P2)

**Goal**: idle, absolute and epoch-based session ends (FR-040–FR-043, FR-083).

**Independent Test**: spec US6 with `Carbon::setTestNow` / `travel()`.

- [X] T058 [P] [US6] Feature test `tests/Feature/Admin/Auth/OperatorSessionTest.php`: sign-out ends the session, writes one `session_revoked`, and a replayed old cookie reaches nothing; 31 minutes idle → step 1 with the idle message, no audit entry; 12 hours absolute despite activity → step 1 with the limit message; deactivation, reset-password and completed re-enrolment sign out existing sessions on their next page request and component update; a password change keeps the changing session; every back-office response carries the R-16 headers; the gallery stays reachable signed out in `local`.
- [X] T059 [US6] Fix whatever T058 exposes in `EnsureOperatorSignedIn`, `OperatorSession` or `BackOfficeComponent`; ensure the reason flash keys map to the copy in `Lang/{en,ar}/auth.php`; run T058 until green.

---

## Phase 10: User Story 7 — Lockout and throttling (Priority: P2)

**Goal**: baseline lockout for operators, separate from the application's (FR-050–FR-053).

**Independent Test**: spec US7.

- [X] T060 [P] [US7] Feature test `tests/Feature/Admin/Auth/LockoutTest.php`: 5 consecutive failures (any mix of password, code, recovery code) → 15-minute lock refusing even the right password with "Too many attempts. Try again in N minutes."; 10 → 60 minutes, never longer; an unknown email locks identically and its entries carry `lookup_prefix`, never the email; 20 failures across emails from one origin → origin refused 15 minutes; a full sign-in resets the account counter; 5 wrong codes discard the pending sign-in; operator failures never touch Identity's `sign_in_failures` or `identity-origin:` limiter and vice versa; one `lockout_applied` per lock begun.
- [X] T061 [US7] Fix whatever T060 exposes in `FailureRecorder`, `EloquentOperatorFailureCounter` or `OperatorOriginLimiter`; run T060 until green.

---

## Phase 11: Polish & Cross-Cutting

- [X] T062 [P] Feature test `tests/Feature/Admin/Auth/OperatorAuditTest.php`: drive every act of contracts/commands-and-audit.md once and assert exactly one entry each with the documented event, actor, subject and detail keys; scan every entry's JSON for the operator's email, password, temporary password, setup key, otpauth link and every recovery code and assert none appears (FR-082); idle and absolute expiry write nothing (FR-083).
- [X] T063 [P] Extend `app/Http/Api/Logging/RedactSecrets.php` `SENSITIVE_KEY` with `one_time_code|recovery_code|setup_key|otpauth` and add cases to its existing test.
- [X] T064 [P] Extend `tests/Feature/Admin/TranslationCompletenessTest.php` and `tests/Feature/Admin/DirectionTest.php` to cover `auth.php`, `security.php` and the new screens: every key in both languages; Arabic pages render `dir="rtl"`; codes, keys, emails and counts are inside `<x-admin::ltr>` with Western digits.
- [X] T065 [P] Update `docs/engineering-conventions.md` back-office section: new screens go in the operator route group and extend `BackOfficeComponent`; sensitive actions use `RequiresRecentFactor` (FR-061); pre-authentication screens are controllers, not components.
- [X] T066 Visual fidelity: compare step 2 in both languages with the design source boards `SignIn` / `SignInAr` (artifact `Ag9pG4RnifekirczEG2drM`, version `1791137074-53df`) and record the result in `specs/018-operator-sign-in/checklists/fidelity.md` (layout, copy, tokens, focus ring, countdown, recovery hint wording change).
- [X] T067 Gates: `dev exec -- vendor/bin/pint --dirty --format agent`, then `dev exec -- composer test` (Pint, Larastan level 8, Arch, Unit, Feature, Contract) green; `dev exec -- vendor/bin/pest tests/Contract/OpenApi` green and `docs/openapi` untouched.
- [X] T068 Quickstart walk-through: run `specs/018-operator-sign-in/quickstart.md` steps 1–12 against `http://saveme-backend.test/admin` (manually or with Playwright using the `data-test` hooks); note results for the summary.
- [ ] T069 Write `specs/018-operator-sign-in/summary.md` (parent writes it).

---

## Dependencies & Execution Order

- **Setup** T001 → T002, T003, T004 (parallel).
- **Foundational** T005–T008 (parallel tests) → T009–T011 → T012, T013 → T014–T017 → T018–T020 → T021 → T022 → T023, T024, T025, T026. Blocks every story.
- **US1** (T027–T035) first: every other story signs operators in.
- **US2** (T036–T043) after US1 (shares `CompleteSignIn`, `OperatorSession`).
- **US8** (T044–T047) after US1 (needs real routes); may run alongside US2.
- **US3** (T048–T049) after US1.
- **US5** (T050–T053) after US2 (uses `BeginEnrolment`/`IssueRecoveryCodes`).
- **US4** (T054–T057) after US2 and US5's re-confirmation tests (T050) are green.
- **US6** (T058–T059) and **US7** (T060–T061) after US1; independent of each other.
- **Polish**: T062–T065 any time after US4; T066–T068 after all stories; T069 last.

### Parallel examples

- Foundational: T005, T006, T007, T008 together; then T009, T010, T011 together; T014, T015, T017 together.
- US1: T027, T028, T029 together.
- After US1: US8 (T044–T047), US3 (T048–T049), US6 (T058) and US7 (T060) can proceed in parallel with US2.
- Polish: T062, T063, T064, T065 together.

## Implementation Strategy

1. **MVP** = Setup + Foundational + US1 + US2 + US8: a deployment can create an operator, enrol, sign in, and every back-office surface is guarded — enough for B11/B12 to start.
2. US3 (recovery codes at sign-in) before go-live: without it a lost phone needs two colleagues.
3. US5, US4, US6, US7 complete the baseline's rules; all are required before the back office is exposed outside local.
4. Polish, gates, quickstart, summary.
