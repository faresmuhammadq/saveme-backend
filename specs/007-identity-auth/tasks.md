---

description: "Task list for Identity & Authentication (B1)"
---

# Tasks: Identity & Authentication

**Input**: Design documents from `/specs/007-identity-auth/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/](./contracts/), [quickstart.md](./quickstart.md)

**Tests**: included. The spec's Invariants table and Acceptance index (Project Sections 4 and 8) name every test file, and constitution P-16 rule 3 requires a failing test for each invariant. Write each story's tests first and watch them fail.

**Organization**: grouped by user story. The seven stories are US1 register and sign in (P1), US2 verify address (P1), US3 device sessions (P1), US4 reset (P2), US5 lockout (P2), US6 deletion (P2), and US7 password change (P3).

**Conventions for every task**:

- **Run in the container**:
  - tests with `dev exec -- vendor/bin/pest <path> --compact`;
  - artisan with `dev <command>`;
  - the whole gate with `dev exec -- composer test`.
- **Generators**: use `dev make:* --no-interaction` where one fits, then move the file into the module path given here.
- **Layer rules (P-02)**:
  - Domain never touches `Illuminate\Database`.
  - Infrastructure never imports Application or Http.
  - Http never imports its own Infrastructure. Controllers pass `(string) $request->user()->getAuthIdentifier()` and the current session id to Application services.
  - Another module is reached only through its `Application` namespace or its events.
- **Refusals**: every refusal is a `Refusal` subclass carrying an `ErrorCode` case (T011). Never hand-build a problem response.
- **Code style**:
  - explicit types, constructor promotion and PHPDoc array shapes;
  - curly braces on every control structure;
  - `final readonly` for DTOs and events;
  - run `dev exec -- vendor/bin/pint --dirty --format agent` after PHP edits.
- **Contract tests**:
  - use `$this->withHeaders(clientHeaders())->…->assertValidRequest()->assertValidResponse($status)`, plus `assertHeader('X-Request-Id')`;
  - cover every status the path file documents;
  - an empty `meta` is `(object) []`.
- **Mail and events in tests**: use `Mail::fake()` / `Queue::fake()` / `Event::fake()`, and assert on dispatch. Never assert on a real delivery.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on unfinished tasks)
- **[Story]**: US1–US7 from spec.md

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: add the approved dependency, remove the starter-kit user, and lay out the directories and configuration.

- [X] T001 Add Sanctum with `dev exec -- composer require laravel/sanctum:^4.3` (owner approval 2026-09-26, research R-1). Do **not** run `install:api`, and do not publish Sanctum's migration. Publish only its config, with `dev vendor:publish --tag=sanctum-config`. Then edit `config/sanctum.php`: `'stateful' => []`, `'guard' => []`, `'expiration' => null`, `'token_prefix' => ''`, and keep the middleware keys but don't use them.
- [X] T002 Remove the starter-kit user (R-20):
  - delete `app/Models/User.php` and `database/factories/UserFactory.php`;
  - remove the `users` and `password_reset_tokens` `Schema::create` and `dropIfExists` calls from `database/migrations/0001_01_01_000000_create_users_table.php`, keeping `sessions`, and change `sessions.user_id` from `foreignId` to a plain nullable `uuid('user_id')->nullable()->index()` with no FK;
  - remove the user-creation line from `database/seeders/DatabaseSeeder.php`;
  - confirm with `grep -rn "App\\\\Models\\\\User" app config database tests` that nothing is left.
- [X] T003 Create the module directories:
  - `app/Modules/Identity/{Domain/{Address,Password,Sessions,Account,Audit,Events,Refusals,Ports},Application/{Registration,Verification,SignIn,Sessions,Passwords,Deletion,Audit,Jobs,Mail,Dto},Infrastructure/{Models,Repositories,Crypto,Sessions,Sync,Console,Database/Migrations,Database/Factories},Http/{Controllers,Requests,Resources,Middleware,Views/mail,Lang/en,Lang/ar}}`;
  - `app/Modules/Billing/Application/{Entitlements,Provisional}`;
  - `app/Modules/Admin/{Application/{Listeners,Dto,Mail,Ports},Infrastructure/{Models,Repositories,Database/Migrations}}`;
  - `app/Modules/Shared/Domain/Errors`, `app/Http/Api/{Problems,Middleware,Logging}`;
  - `tests/{Unit,Feature,Contract}/Identity`, `tests/Contract/Api`.
- [X] T004 [P] Create `config/identity.php`, with a PHPDoc header per key, covering:
  - `keys` (`json_decode(env('IDENTITY_KEYS', '{}'), true)`), `active_key` (`env('IDENTITY_ACTIVE_KEY')`) and `lookup_key` (`env('IDENTITY_LOOKUP_KEY')`);
  - `lockout` → `['account' => [['failures' => 5, 'minutes' => 15], ['failures' => 10, 'minutes' => 60]], 'cap_minutes' => 60, 'origin' => ['failures' => 20, 'decay_minutes' => 15]]`;
  - `throttle` → `['register_per_hour_per_origin' => 10, 'resend_per_hour_per_address' => 3, 'reset_per_hour_per_address' => 3]`;
  - `links` → `['verify_url' => env('IDENTITY_VERIFY_URL', 'http://saveme-backend.test/verify-email'), 'reset_url' => env('IDENTITY_RESET_URL', 'http://saveme-backend.test/reset-password'), 'verify_minutes' => 1440, 'reset_minutes' => 60]`;
  - `session_inactivity_days` → `90`, `deletion_grace_days` → `30`, `default_timezone` → `'Africa/Cairo'`;
  - `google` → `['enabled' => (bool) env('IDENTITY_GOOGLE_ENABLED', false)]`.
- [X] T005 [P] Create `config/api.php` with `minimum_client_version` → `['android' => env('API_MIN_ANDROID_VERSION', '1.0.0')]`, `supported_platforms` → `['android']`, `supported_locales` → `['en', 'ar']`, and `hsts_max_age` → `31536000`.
- [X] T006 [P] Update `config/hashing.php` (publish with `dev config:publish hashing` if absent) to `'driver' => 'argon2id'`, `'argon' => ['memory' => (int) env('HASH_ARGON_MEMORY', 65536), 'threads' => (int) env('HASH_ARGON_THREADS', 1), 'time' => (int) env('HASH_ARGON_TIME', 4), 'verify' => true]` (R-5). In `phpunit.xml`, set `HASH_ARGON_MEMORY=1024`, `HASH_ARGON_TIME=1` and `HASH_ARGON_THREADS=1`, plus test values for `IDENTITY_KEYS` (one `k1` 32-byte base64 key), `IDENTITY_ACTIVE_KEY=k1` and `IDENTITY_LOOKUP_KEY`.
- [X] T007 [P] Update `config/admin.php`, adding `'notifications' => ['recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMIN_NOTIFICATION_RECIPIENTS', ''))))), 'unread_days' => 7]` with a doc line (R-14).
- [X] T008 [P] Add the new keys to `.env.example`, with blank or placeholder values and a comment for each: `IDENTITY_KEYS`, `IDENTITY_ACTIVE_KEY`, `IDENTITY_LOOKUP_KEY`, `IDENTITY_VERIFY_URL`, `IDENTITY_RESET_URL`, `IDENTITY_GOOGLE_ENABLED=false`, `HASH_ARGON_*`, `ADMIN_NOTIFICATION_RECIPIENTS`, `API_MIN_ANDROID_VERSION=1.0.0`.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: the API runtime (contracts/api-runtime.md), the shared error types, the Identity core (User, the crypto, the audit log, the session guard), the Billing seam, and the OpenAPI skeleton entry. Every story depends on these.

**⚠️ No user-story work starts until this phase is complete.**

### Tests for the foundation (write first)

- [X] T009 [P] Write `tests/Contract/OpenApi/ErrorCodeParityTest.php`. It parses `docs/openapi/error-catalog.yaml` with Symfony YAML (already a transitive dependency). It asserts that the set of `ErrorCode` enum case values equals `components.schemas.ErrorCode.enum` plus the field-level codes it uses, and that for every response-level code `ErrorCode::from($code)->status()` equals the catalogue `status` and `->retryable()` equals `retryable`.
- [X] T010 [P] Write the runtime contract tests in `tests/Contract/Api/`. They use a fixture route registered in a `beforeEach`: `Route::middleware('api')->prefix('api/v1')->get('_probe', …)` returning `Envelope::ok(['ok' => true])`, plus a POST probe with a form request. Spectator isn't used for the fixture; assert raw JSON and headers. Each file asserts:
  - `EnvelopeTest.php`: the body is `{"data":…,"meta":{}}`, and `meta` is `{}`, not `[]`.
  - `ProblemTest.php`: `application/problem+json` with `type`, `title`, `status`, `code`, `detail`, `request_id` equal to `X-Request-Id`; an unknown `/api/v1/nope` gives 404 `NOT_FOUND`; a wrong method gives 405 `METHOD_NOT_ALLOWED` with `Allow`; a validation failure gives 422 with `errors[].pointer` (`/field`) and `errors[].code`; an unknown body member gives `UNKNOWN_FIELD`.
  - `RequestIdTest.php`: `X-Request-Id` is a UUIDv7 on success and on failure; a valid `X-Correlation-Id` is echoed; an invalid one (`bad id!`) is silently dropped.
  - `TransportTest.php`: with `app()->detectEnvironment(fn () => 'production')` and an `http://` request, the answer is 400 `INSECURE_TRANSPORT`, not a redirect; `https://` responses carry `Strict-Transport-Security: max-age=31536000`.
  - `ContentNegotiationTest.php`: 406 `NOT_ACCEPTABLE` for `Accept: text/html`, 415 `UNSUPPORTED_MEDIA_TYPE` for a `text/plain` body, 400 `MALFORMED_JSON` for `{bad`.
  - `ClientVersionTest.php`: missing headers give 400 `CLIENT_VERSION_REQUIRED`; `X-Client-Version: abc` gives 400 `CLIENT_VERSION_INVALID`; `0.9.0` gives 426 `CLIENT_VERSION_UNSUPPORTED` with `minimum_version`; `ios` gives 400 `CLIENT_VERSION_INVALID`.
  - `LocalisationTest.php`: `Accept-Language: ar` gives `Content-Language: ar` and an Arabic `detail`; `fr` gives `en`; `title` and `code` are never localised.
  - `RateLimitHeadersTest.php`: a throttled probe carries `RateLimit-Limit`, `RateLimit-Remaining` and `RateLimit-Reset` and no `X-RateLimit-*`; on refusal it gives 429 `RATE_LIMITED` with `Retry-After`.
- [X] T011 [P] Write `tests/Feature/Support/LogRedactionTest.php`. Logging `['password' => 'x', 'credential' => 'y', 'nested' => ['token' => 'z'], 'authorization' => 'Bearer q']` at every level through the default channel yields `[REDACTED]` for each value (use a test handler to capture).
- [X] T012 [P] Write the Unit tests in `tests/Unit/Identity/`:
  - `AddressLookupTest.php`: `" Ali@Example.com "` and `"ali@example.com"` give the same lookup; `a.b@example.com` and `ab@example.com` differ; a fullwidth `ａ@example.com` normalises (NFKC) to `a@example.com`; the output is 64 lowercase hex characters; a different key gives a different value.
  - `AttributeCipherTest.php`: seal then open round-trips; the output starts `v1.`; the same plaintext sealed twice differs (fresh nonce); a tampered ciphertext, a wrong associated user id, or a wrong data key throws; a wrapped data key names its `kid` and unwraps after `active_key` changes, as long as the old `kid` is still in the keyring.
  - `PasswordPolicyTest.php`: rejects length 7 and 129; accepts 8 and 128 and any composition; rejects a password equal to the normalised address (case-insensitive).
  - `AuditVocabularyTest.php`: the `AuditEvent` cases equal exactly the 19 names in the security baseline's "What is recorded" table.
  - `SessionStateTest.php`: `active` → `revoked` or `expired` is allowed; `revoked` and `expired` are terminal; the end reasons equal `signed_out|ended_by_owner|replaced|password_changed|password_reset|expired`.
  These use plain PHP with no framework boot. Construct the pure Domain classes and the Infrastructure crypto classes with explicit keys.
- [X] T013 [P] Write `tests/Feature/Identity/AuditTrailTest.php`. `AuditLog::record()` writes exactly one row with `actor_type`, `actor_id`, `subject_type`, `subject_id`, `request_id` and a coarsened `origin` (`203.0.113.0/24` for `203.0.113.77`). A raw `DB::update('update audit_entries set event = ?', …)` throws a `QueryException` from the trigger. A `DELETE` of a row with `occurred_at = now()` throws, while one older than 400 days succeeds. A `detail` DTO has no way to carry an address or password (assert on the serialised JSON of each detail class).
- [X] T014 [P] Write `tests/Feature/Identity/AddressAtRestTest.php`. After registering `ada@example.test` / `correct horse`, no column of any row in `users`, `profiles`, `device_sessions`, `identity_links`, `audit_entries`, `user_keys` or `operator_notifications` contains `ada@example.test` or `correct horse` (select everything with `row_to_json`, and search the text). Deleting the `user_keys` row makes `email_ciphertext` unreadable (opening it throws).
- [X] T015 [P] Write `tests/Feature/Identity/GuardSurfaceTest.php`:
  - `config('auth.defaults.guard') === 'api'`;
  - the `api` guard driver is `sanctum`;
  - the `web` guard has no `provider` key;
  - `config('sanctum.guard') === []`;
  - a request with a valid app credential but no `Authorization` header, and a web session cookie, is `401` on an authenticated fixture route.

### Implementation for the foundation

- [X] T016 Create `App\Modules\Shared\Domain\Errors\ErrorCode` in `app/Modules/Shared/Domain/Errors/ErrorCode.php`: a string-backed enum with one case per code in `docs/openapi/error-catalog.yaml` (the unique names across the response-level and field-level lists, with `INVALID_ID` appearing once; TitleCase cases such as `InvalidCredentials = 'INVALID_CREDENTIALS'`). Add `status(): int`, `title(): string` (the catalogue English title) and `retryable(): string` (`automatic|after-change|never`) as `match` expressions. Also create `FieldError` (`final readonly`, `string $pointer`, `ErrorCode $code`, `?string $detailKey`), and `Refusal` (an abstract exception with `ErrorCode $code`, `?int $retryAfterSeconds`, `list<FieldError> $fieldErrors`, `array<string, scalar> $extensions` for members such as `minimum_version`, and `?string $detailKey`). Make T009 pass.
- [X] T017 Create `App\Http\Api\Envelope` in `app/Http/Api/Envelope.php`, with static `ok(mixed $data, array $meta = [], int $status = 200): JsonResponse`, `accepted(array $data): JsonResponse` (202), `noContent(): Response` (204), and `collection(array $data, ?string $nextCursor, bool $hasMore, int $limit): JsonResponse`. It always emits `meta` as an object: `(object) $meta` when empty.
- [X] T018 Create `App\Http\Api\Problems\ProblemRenderer` in `app/Http/Api/Problems/ProblemRenderer.php`, and register it in `bootstrap/app.php` through `$exceptions->render(...)` for `api/*` requests. It maps:
  - `Refusal` → its code;
  - `ValidationException` → `VALIDATION_FAILED`, with `errors[]` whose `pointer` is `/` + the dotted key with `.` → `/`, and whose `code` is a `FieldError` code from the form request's rule (T021);
  - `AuthenticationException` → `UNAUTHENTICATED`;
  - `NotFoundHttpException` / `ModelNotFoundException` → `NOT_FOUND`;
  - `MethodNotAllowedHttpException` → `METHOD_NOT_ALLOWED` plus `Allow`;
  - `ThrottleRequestsException` → `RATE_LIMITED` plus `Retry-After`;
  - a JSON parse failure → `MALFORMED_JSON`;
  - anything else → `INTERNAL_ERROR`, with no exception detail in the body.
  The body is `type` (`https://problems.example/{code-lowercase-hyphenated}`), `title` (`ErrorCode::title()`), `status`, `code`, `detail` (`__('problems.'.$code)` or the refusal's `detailKey`), `request_id`, extensions, and `errors[]`. The content type is `application/problem+json`. Also create `lang/en/problems.php` and `lang/ar/problems.php` with one entry per response-level code, the Arabic properly translated.
- [X] T019 Create the middleware in `app/Http/Api/Middleware/`, following contracts/api-runtime.md:
  - `AssignRequestId`: a `Str::uuid7()` request id; validate `X-Correlation-Id` against `^[A-Za-z0-9._:-]{1,128}$`; share both through `Context::add('request_id', …)` and `Context::add('correlation_id', …)` so logs and queued jobs inherit them; set `X-Request-Id`, and the echo, on every response, including problems.
  - `RefuseInsecureTransport`: when `! $request->secure() && ! app()->environment('local')`, throw `Refusal(InsecureTransport)`; otherwise add `Strict-Transport-Security: max-age={config('api.hsts_max_age')}` to secure responses.
  - `NegotiateJson`: 406 and 415 per the api-conventions table.
  - `RequireClientVersion`: `X-Client-Platform` must be in `config('api.supported_platforms')`, and `X-Client-Version` must be semver `^\d+\.\d+\.\d+$` and at least the configured minimum (`version_compare`), else the matching `ClientVersion*` refusal with extension `minimum_version`.
  - `ResolveContentLanguage`: pick `ar` or `en` from `Accept-Language` (default `en`), call `app()->setLocale()`, and set `Content-Language`.
  - `StandardRateLimitHeaders`: rename `X-RateLimit-Limit`/`-Remaining` to `RateLimit-Limit`/`-Remaining`, and add `RateLimit-Reset` in seconds (from `X-RateLimit-Reset` or `Retry-After`).
- [X] T020 Wire the runtime in `bootstrap/app.php`:
  - `$middleware->appendToGroup('api', [AssignRequestId::class, RefuseInsecureTransport::class, NegotiateJson::class, RequireClientVersion::class, ResolveContentLanguage::class, StandardRateLimitHeaders::class])`, keeping `SubstituteBindings` last (use `$middleware->group('api', [...])` with the full ordered list if `append` can't order);
  - a fallback for `api/v1/*` giving `NOT_FOUND`;
  - keep `shouldRenderJsonWhen`;
  - `$middleware->trustProxies(at: '*')` behind `env('TRUSTED_PROXIES')` for `secure()` detection.
  Make T010 pass.
- [X] T021 Create `App\Http\Api\Requests\ApiRequest` in `app/Http/Api/Requests/ApiRequest.php`, an abstract `FormRequest` base:
  - `prepareForValidation` / `withValidator` refuse any top-level or nested member not declared in `rules()`, with a field error `UNKNOWN_FIELD` and pointer `/member`;
  - it maps Laravel rule failures to `FieldError` codes (`required` → `REQUIRED`, `string|integer|array|boolean` → `INVALID_TYPE`, `in|enum|timezone` → `INVALID_ENUM_VALUE`, `min|max|between|size` → `OUT_OF_RANGE`, `uuid` → `INVALID_ID`) through a protected `fieldCodes(): array` override hook;
  - `authorize()` returns true.
- [X] T022 Create `App\Http\Api\Logging\RedactSecrets` in `app/Http/Api/Logging/RedactSecrets.php`, a Monolog `ProcessorInterface` that recursively replaces values whose key matches `/pass(word)?|token|secret|credential|authorization|cookie/i` with `[REDACTED]` in `context` and `extra`. Attach it to every channel in `config/logging.php` through a `tap` class, `App\Http\Api\Logging\AttachRedaction`. Make T011 pass.
- [X] T023 [P] Create the Identity Domain value types:
  - `app/Modules/Identity/Domain/Address/AddressNormaliser.php`: `normalise(string): string` = `mb_strtolower(Normalizer::normalize(trim($s), Normalizer::FORM_KC))`.
  - `app/Modules/Identity/Domain/Address/EmailAddress.php`: a readonly value holding the normalised form. The constructor validates `filter_var(..., FILTER_VALIDATE_EMAIL)` and ≤ 254 characters.
  - `app/Modules/Identity/Domain/Password/PasswordPolicy.php`: `violations(string $password, EmailAddress $address): list<string>`, 8 to 128 characters (`mb_strlen`) and not equal to the address.
  - `app/Modules/Identity/Domain/Account/AccountState.php`: an enum `Active='active'`, `PendingDeletion='pending_deletion'`, `Purged='purged'`.
  - `app/Modules/Identity/Domain/Sessions/SessionState.php`: an enum `active|revoked|expired`, with `isTerminal()`.
  - `app/Modules/Identity/Domain/Sessions/EndReason.php`: an enum `signed_out|ended_by_owner|replaced|password_changed|password_reset|expired`.
  - `app/Modules/Identity/Domain/Sessions/DeviceDescription.php`: a readonly holding `sessionId` (UUIDv7), `deviceId` ("string 1..64, `[A-Za-z0-9._:-]`"), `name` ("string 1..100"), `platform` (`android`) and `appVersion` (semver).
- [X] T024 [P] Create `app/Modules/Identity/Domain/Audit/{AuditEvent,ActorType,SubjectType}.php`:
  - `AuditEvent`: backed enum values exactly `sign_in_succeeded`, `sign_in_failed`, `lockout_applied`, `session_issued`, `session_revoked`, `password_changed`, `address_change_requested`, `address_verified`, `membership_granted`, `membership_revoked`, `role_changed`, `entitlement_changed`, `export_requested`, `export_delivered`, `deletion_requested`, `deletion_cancelled`, `deletion_completed`, `admin_action`, `audit_read`;
  - `ActorType`: `user|operator|system`;
  - `SubjectType`: `user|wallet|session|subscription`.
  That is 19 values, matching the baseline table and T012's AuditVocabularyTest.
- [X] T025 [P] Create the refusals in `app/Modules/Identity/Domain/Refusals/`, each extending `Shared\Domain\Errors\Refusal` with its fixed code:
  - `InvalidCredentials` (`INVALID_CREDENTIALS`);
  - `AccountLocked` (`ACCOUNT_LOCKED`, carries `retryAfterSeconds`);
  - `EmailNotVerified` (`EMAIL_NOT_VERIFIED`);
  - `VerificationLinkInvalid` (`VERIFICATION_TOKEN_INVALID`);
  - `ResetLinkInvalid` (`RESET_TOKEN_INVALID`);
  - `SessionNotFound` (`NOT_FOUND`);
  - `TooManyRequests` (`RATE_LIMITED`, carries `retryAfterSeconds`).
- [X] T026 [P] Create the events in `app/Modules/Identity/Domain/Events/`, per contracts/events-and-notifications.md. Each is `final readonly`, implements `Illuminate\Contracts\Events\ShouldDispatchAfterCommit`, and carries `string $eventId` (UUIDv7), `string $occurredAt` (ISO-8601 UTC) and scalars only:
  - `UserRegistered(userId, locale)`;
  - `UserSignedIn(userId, sessionId, platform)`;
  - `DeviceSessionEnded(userId, sessionId, reason)`;
  - `UserDeletionRequested(userId, scheduledFor)`;
  - `UserDeletionCancelled(userId, reason: 'explicit'|'sign_in', ?sessionId)`.
- [X] T027 Create the ports in `app/Modules/Identity/Domain/Ports/`, each an interface with PHPDoc array shapes:
  - `AttributeCipher`: `newDataKeyFor(string $userId): void`, `seal(string $userId, string $plaintext): string`, `open(string $userId, string $sealed): string`;
  - `AddressLookup`: `for(EmailAddress): string`;
  - `UserRepository`, `ProfileRepository`, `SessionRepository`, `LinkRepository`, `FailureCounter`, `AuditStore`;
  - `SessionIssuer`: `issue(string $userId, DeviceDescription): IssuedCredential`, `rotate(string $sessionId): IssuedCredential`;
  - `ChangeSequence`: `next(): int`.
  Each method gets only as much as the stories below need. Add methods in story tasks as they're needed, and keep the interfaces narrow.
- [X] T028 Create the Identity migrations in `app/Modules/Identity/Infrastructure/Database/Migrations/` (UUID primary keys, `timestampTz`, CHECK constraints through `DB::statement`), following data-model.md verbatim:
  - `2026_09_26_000001_create_sync_change_seq.php`: `CREATE SEQUENCE sync_change_seq`.
  - `…000002_create_users_table.php`: `email_ciphertext text`, `email_lookup char(64) unique`, `password_hash text`, `email_verified_at timestamptz null`, `state varchar(20)` CHECK in (`active`,`pending_deletion`,`purged`), `deletion_requested_at` and `deletion_scheduled_for` timestamptz null, and CHECK `(state = 'pending_deletion') = (deletion_requested_at IS NOT NULL AND deletion_scheduled_for IS NOT NULL)`.
  - `…000003_create_user_keys_table.php`: `user_id uuid PK FK → users ON DELETE CASCADE`, `wrapped_key text`, `kid varchar(32)`.
  - `…000004_create_profiles_table.php`: `user_id uuid UNIQUE FK`, `locale varchar(2)` CHECK in (`ar`,`en`), `timezone varchar(64)`, `display_name_ciphertext text null`, `version integer`, `change_seq bigint`, `deleted_at timestamptz null`.
  - `…000005_create_device_sessions_table.php`: `id uuid PK` (client-generated), `user_id uuid FK`, `token char(64) UNIQUE`, `abilities jsonb default '["*"]'`, `device_id varchar(64)`, `device_name varchar(100)`, `platform varchar(16)` CHECK (`android`), `app_version varchar(32)`, `state varchar(10)` CHECK in (`active`,`revoked`,`expired`), `end_reason varchar(20) null` CHECK in the six reasons, `signed_in_at`, `last_used_at null`, `ended_at null`, `expires_at null`, `version integer`, `change_seq bigint`, `deleted_at null`, the partial unique index `CREATE UNIQUE INDEX device_sessions_one_active_per_device ON device_sessions (user_id, device_id) WHERE state = 'active'`, the index `(user_id, signed_in_at DESC)`, and CHECK `(state = 'active') = (ended_at IS NULL AND end_reason IS NULL)`.
  - `…000006_create_identity_links_table.php`: `kind varchar(20)` CHECK in (`verify_address`,`reset_password`), `secret_hash char(64) UNIQUE`, `expires_at`, `used_at null`, `superseded_at null`, FK cascade.
  - `…000007_create_sign_in_failures_table.php`: `lookup char(64) PK`, `consecutive_failures integer`, `locked_until timestamptz null`, `last_failed_at timestamptz`.
  - `…000008_create_audit_entries_table.php`: the baseline fields with `subject_id uuid null`, `detail jsonb`, the indexes `(subject_type, subject_id, occurred_at)` and `(event, occurred_at)`, and a PL/pgSQL trigger function `audit_entries_append_only()` that raises on `UPDATE`, and on `DELETE` when `OLD.occurred_at > now() - interval '400 days'`.
  Run `dev migrate:fresh`.
- [X] T029 Create the Eloquent models in `app/Modules/Identity/Infrastructure/Models/`, using `HasUuids` (Laravel 13 generates UUIDv7) except where noted:
  - `User`: extends `Illuminate\Foundation\Auth\User`, `$hidden = ['password_hash', 'email_ciphertext', 'email_lookup']`, `getAuthPassword()` returns `password_hash`, no `remember_token` (override `getRememberTokenName()` to `''`).
  - `UserKey`: key `user_id`, non-incrementing.
  - `Profile`.
  - `DeviceSession`: extends `Laravel\Sanctum\PersonalAccessToken`, `$table = 'device_sessions'`, no auto-UUID (`$incrementing = false`, `$keyType = 'string'`, id set explicitly); `tokenable()` returns `$this->belongsTo(User::class, 'user_id')`; `isUsable(CarbonInterface $now): bool` is true when `state === 'active'` and `($this->last_used_at ?? $this->signed_in_at)->gt($now->copy()->subDays(config('identity.session_inactivity_days')))`.
  - `IdentityLink`.
  - `SignInFailure`: key `lookup`.
  - `AuditEntry`: `$timestamps = false`, `detail` cast to array.
  Add factories in `Infrastructure/Database/Factories/` for `User` (sealed test address through the cipher, with `unverified()`, `verified()` and `pendingDeletion()` states), `Profile`, and `DeviceSession` (with `revoked()` and `idleFor(int $days)` states).
- [X] T030 Create the crypto in `app/Modules/Identity/Infrastructure/Crypto/`:
  - `Keyring`: reads `identity.keys` and `active_key`, and throws at boot when the active key is missing or not 32 bytes.
  - `SodiumAttributeCipher` implements `AttributeCipher`, per R-6:
    - the data key comes from `random_bytes(32)`, is wrapped with `sodium_crypto_aead_xchacha20poly1305_ietf_encrypt` using the active KEK (AD = user id), and is stored in `user_keys` with its `kid`;
    - values are sealed as `v1.{kid}.{base64(nonce‖ct)}` (AD = user id);
    - `open` resolves the wrapped key's `kid` from the keyring;
    - a per-request in-memory cache of unwrapped data keys is allowed, but never persisted.
  - `HmacAddressLookup` implements `AddressLookup`: `hash_hmac('sha256', $address->normalised, base64_decode(config('identity.lookup_key')))`.
  Make T012's AddressLookup and AttributeCipher tests pass.
- [X] T031 Create `app/Modules/Identity/Infrastructure/Sync/PostgresChangeSequence.php` implementing `ChangeSequence` with `select nextval('sync_change_seq')`. Its PHPDoc notes that B14 owns commit-order semantics (R-19).
- [X] T032 Create the audit writer:
  - `app/Modules/Identity/Application/Audit/AuditRecord.php`: `final readonly`, holding `AuditEvent`, `ActorType`, `?actorId`, `SubjectType`, `?subjectId`, and `AuditDetail $detail`.
  - `app/Modules/Identity/Application/Audit/Details/*.php`: one small `final readonly` class per event this feature writes, each implementing `AuditDetail::toArray(): array<string, scalar|null>`. `SignInFailedDetail(?lookupPrefix)` holds the first 8 hex characters only. `DeletionCancelledDetail(reason)`. `SessionDetail(platform)`. `SessionRevokedDetail(reason)`. The rest are empty.
  - `app/Modules/Identity/Application/Audit/AuditLog.php`: `record(AuditRecord)` fills `occurred_at` (now, UTC), `request_id` (`Context::get('request_id')`) and `origin` (the request IP coarsened to `/24` or `/48`), then calls `AuditStore::append()`.
  - `app/Modules/Identity/Infrastructure/Repositories/EloquentAuditStore.php`.
  Make T013 pass.
- [X] T033 Create the Billing seam (R-11), in `app/Modules/Billing/Application/`:
  - `SubscriptionService.php`: interface, `enrolInDefaultPlan(string $userId): void`.
  - `Entitlements/EntitlementService.php`: interface, `summaryFor(string $userId): EntitlementSummary`.
  - `Entitlements/EntitlementSummary.php`: `final readonly`, `string $planLabelKey`, `array<string, bool|int> $features`.
  - `Provisional/ProvisionalFreePlan.php`: implements both; enrolling is a no-op; the summary is `new EntitlementSummary('billing.plans.free', ['sharing.enabled' => false])`. The class PHPDoc says "Replaced by B3; delete this directory then".
  - `BillingServiceProvider.php`: binds both interfaces to `ProvisionalFreePlan`.
  Register the provider in `bootstrap/providers.php`. `tests/Arch/EntitlementsTest.php` must stay green (no `PlanCode`).
- [X] T034 Create `app/Modules/Identity/Infrastructure/IdentityPersistenceServiceProvider.php`. It:
  - binds every Domain port to its Infrastructure implementation;
  - calls `Sanctum::usePersonalAccessTokenModel(DeviceSession::class)`;
  - calls `Sanctum::authenticateAccessTokensUsing(fn (DeviceSession $session, bool $isValid): bool => $isValid && $session->isUsable(now()))` (R-3).
  Register it in `bootstrap/providers.php`.
- [X] T035 Update `config/auth.php`:
  - `defaults.guard` → `'api'`;
  - guards `api` → `['driver' => 'sanctum', 'provider' => 'users']`;
  - `web` → `['driver' => 'session']` with no provider;
  - providers `users.model` → `App\Modules\Identity\Infrastructure\Models\User::class`;
  - remove the `passwords` broker array and `password_timeout` (R-20).
  Make T015 pass.
- [X] T036 Create `app/Modules/Identity/Http/IdentityServiceProvider.php`. It:
  - loads `Http/routes.php` inside `Route::prefix('api/v1')->middleware('api')->group(...)`;
  - `loadViewsFrom(__DIR__.'/Views', 'identity')`;
  - `loadTranslationsFrom(__DIR__.'/Lang', 'identity')`;
  - defines the named rate limiters `identity-register` (per IP, `throttle.register_per_hour_per_origin` per hour), `identity-resend` (per user id, 3 per hour) and `identity-reset` (per lookup value of the request's `email`, 3 per hour), each with a response callback that throws `TooManyRequests`.
  Create `app/Modules/Identity/Http/routes.php` holding an empty group commented per operation, to be filled story by story. Register the provider in `bootstrap/providers.php`.
- [X] T037 Create the OpenAPI entry:
  - `docs/openapi/paths/identity.yaml`, copied from `_template.yaml`'s conventions, with the shared schemas from contracts/identity-api.md (`DeviceDescription`, `Account`, `EntitlementSummary`, `DeviceSession` composing `SyncableResource` and `UserScoped`, and `Password`), and no operations yet;
  - bump `info.version` in `docs/openapi/openapi.yaml` to `v1.2`.
  Operations are added story by story, each with its `paths:` index entry. `dev exec -- vendor/bin/pest tests/Contract/OpenApi` stays green.
- [X] T038 Create the base request `app/Modules/Identity/Http/Requests/IdentityRequest.php`, extending `App\Http\Api\Requests\ApiRequest`. Create the authenticated-caller helper `app/Modules/Identity/Http/Controllers/Concerns/ResolvesCaller.php`, with `callerId(Request): string` and `currentSessionId(Request): string` (from `$request->user()->currentAccessToken()->getKey()`, typed through `Laravel\Sanctum\Contracts\HasAbilities` / `getKey`, never the Identity model class).

**Checkpoint**: the runtime tests (T009–T011), the unit crypto tests, the audit test and the guard test are green, and `dev exec -- composer test` passes.

---

## Phase 3: User Story 1 - Create an account and sign in on a phone (Priority: P1) 🎯 MVP

**Goal**: registration with uniform answers, creating the User, Profile and Free subscription in one unit of work, and sign-in issuing a device-scoped credential that later requests are recognised by, with rotation on retry and replacement per device.

**Independent Test**: register, sign in with a device description, call an authenticated fixture route with the credential. Re-register the same address and get an identical answer with nothing changed.

### Tests for User Story 1 (write first, confirm they fail)

- [X] T039 [P] [US1] Write `tests/Contract/Identity/RegisterTest.php` covering:
  - `202` for a new address, with body `{"data":{"status":"accepted"},"meta":{}}`;
  - a byte-identical `202` body for an existing address;
  - `422` with `UNKNOWN_FIELD` (extra member), `OUT_OF_RANGE` (password of 7 characters), and `INVALID_ENUM_VALUE` (timezone `Mars/Base`);
  - `429 RATE_LIMITED` on the 11th request from one IP in an hour, with `Retry-After` and `RateLimit-*`.
  Validate everything against `identity.yaml`.
- [X] T040 [P] [US1] Write `tests/Feature/Identity/RegistrationTest.php`:
  - a new address creates exactly one `users`, one `user_keys` and one `profiles` row, with `profiles.locale = 'ar'` for `Accept-Language: ar` and `'en'` when absent, and `timezone = 'Africa/Cairo'` by default;
  - `SubscriptionService::enrolInDefaultPlan` is called once, inside the transaction: bind a spy that throws, and assert that no user, profile or user key remains;
  - two concurrent registrations of the same address, simulated by catching the unique violation, leave one user and both answer 202;
  - an existing address leaves the row untouched (compare `updated_at` and `password_hash`);
  - `UserRegistered` is dispatched once, after commit, and only for a new address.
- [X] T041 [P] [US1] Write `tests/Feature/Identity/UniformWorkTest.php`, for registration and sign-in:
  - with `Hash` spied, a new address and an existing address each perform exactly one `Hash::make`, and each dispatch exactly one `SendRegistrationMail` job;
  - a wrong password and an unknown address each perform exactly one `Hash::check`;
  - the responses are identical apart from `X-Request-Id`.
  US4 extends this file for the reset request.
- [X] T042 [P] [US1] Write `tests/Contract/Identity/LoginTest.php` covering:
  - `200`, with `credential` matching `^[0-9a-f-]{36}\|[A-Za-z0-9]{64}$`, `session.current = true`, `account.email_verified = false`, `entitlements.features['sharing.enabled'] = false` and `deletion_cancelled = false`;
  - `401 INVALID_CREDENTIALS` for a wrong password and for an unknown address, with identical bodies;
  - `404 NOT_FOUND` for a `session_id` owned by another user;
  - `422` for `platform: "ios"` and for a missing `device`;
  - calling an authenticated fixture route (`GET /api/v1/_whoami`, registered in the test, `auth:api`) with the credential gives `200`, and with a garbage credential gives `401 UNAUTHENTICATED`.
- [X] T043 [P] [US1] Write `tests/Feature/Identity/SignInIdempotencyTest.php`:
  - signing in twice with the same `session_id` gives one row, `version` 2, and a new credential, and the first credential is refused `401`;
  - a new `session_id` from the same `device_id` gives the old session `revoked` with `end_reason = replaced`, and exactly one active session for that device, so the partial unique index holds;
  - a `session_id` of the user's own **revoked** session gives `404`.
- [X] T044 [P] [US1] Write `tests/Feature/Identity/SessionOwnershipTest.php`: user B signing in with user A's `session_id` gets `404`, and A's row (token hash, `version`, state) is byte-for-byte unchanged. US3 extends this file for end-session.
- [X] T045 [P] [US1] Write `tests/Feature/Identity/SessionLifetimeTest.php`:
  - using a credential updates `last_used_at` but not `version` or `change_seq`;
  - with `$this->travel(89)->days()` the credential still works, and the use extends the window;
  - with a session idle 91 days (the `idleFor(91)` factory state) the next request is `401`;
  - the stored hash is `hash('sha256', secret)`;
  - an argon2id hash with lower stored parameters is rehashed on successful sign-in (`password_hash` changes and `Hash::needsRehash` is false afterwards).

### Implementation for User Story 1

- [X] T046 [US1] Implement the repositories that US1 needs in `app/Modules/Identity/Infrastructure/Repositories/`:
  - `EloquentUserRepository`: `createActive(id, sealedEmail, lookup, passwordHash): void`, `findByLookup(string): ?UserRecord` (a Domain DTO), `updatePasswordHash`, `markVerified`, `setDeletionSchedule`, `clearDeletionSchedule`;
  - `EloquentProfileRepository::create(userId, locale, timezone, ?sealedName)`, which sets `version = 1` and `change_seq` from `ChangeSequence`.
  Add `UserRecord` (`final readonly`: `id`, `passwordHash`, `state`, `emailVerifiedAt`, `deletionScheduledFor`) in `app/Modules/Identity/Domain/Account/UserRecord.php`.
- [X] T047 [US1] Implement `app/Modules/Identity/Application/Registration/RegisterAccount.php`, taking `(string $email, string $password, ?string $displayName, ?string $timezone, string $locale)`. It:
  1. normalises the address, and computes the lookup;
  2. **always** computes `Hash::make($password)`;
  3. in `DB::transaction`: if the lookup exists, it does nothing; otherwise it takes a new UUIDv7, calls `AttributeCipher::newDataKeyFor`, seals the email and display name, creates the user, creates the profile, and calls `SubscriptionService::enrolInDefaultPlan`, catching a unique violation on `email_lookup` as "exists";
  4. **always** dispatches exactly one `SendRegistrationMail` job, carrying the lookup, the locale and a `bool $isNew` that is computed but never returned;
  5. dispatches `UserRegistered` only when new.
  It returns void, so the controller cannot vary its answer. Also implement `app/Modules/Identity/Application/Jobs/SendRegistrationMail.php` (queued): for a new user it issues a verify link (through `LinkRepository::issue` from T057; until US2 lands, a minimal insert that T058 replaces) and sends `VerifyAddressMail`; for an existing one it sends `AlreadyRegisteredMail`, addressed to the decrypted address.
- [X] T048 [US1] Implement `app/Modules/Identity/Infrastructure/Sessions/SanctumSessionIssuer.php` implementing `SessionIssuer` (R-4):
  - the secret is `Str::random(64)`, and the credential is `"{$sessionId}|{$secret}"`;
  - `issue()` inserts a `device_sessions` row: `token = hash('sha256', $secret)`, `state = active`, `signed_in_at = now()`, `version = 1`, `change_seq = next()`;
  - `rotate()` updates `token` and `signed_in_at`, bumps `version`, and assigns a new `change_seq`.
  Also implement `EloquentSessionRepository` with `findById`, `activeForDevice(userId, deviceId)`, and `end(id, EndReason)`. `end` sets `state = revoked`, `ended_at` and `end_reason`, bumps `version`, assigns a new `change_seq`, and is a no-op if already terminal.
- [X] T049 [US1] Implement `app/Modules/Identity/Application/SignIn/SignIn.php`, returning `SignInResult` (`app/Modules/Identity/Application/Dto/SignInResult.php`: `credential`, `DeviceSessionView`, `AccountView`, `EntitlementSummary`, `bool $deletionCancelled`). The steps:
  1. find the user by lookup;
  2. if the user is not found, run `Hash::check` against a fixed dummy argon2id hash (a constant computed once per process from the current parameters) and refuse `InvalidCredentials`; if found but `state === purged`, do the same;
  3. on a wrong password, refuse `InvalidCredentials`;
  4. in `DB::transaction`, resolve the session id:
     - owned by another user, or a terminal session → `SessionNotFound`;
     - this user's active session → `rotate`;
     - otherwise, end the active session for `(user, device_id)` with `replaced`, then `issue`;
  5. rehash if `Hash::needsRehash`;
  6. audit `sign_in_succeeded` and `session_issued` (and `session_revoked` for a replaced one);
  7. after commit, dispatch `UserSignedIn`, and `DeviceSessionEnded` for a replaced session.
  Leave marked extension points: **lockout (US5, T076)**, **deletion cancel (US6, T085)**, and **failure audit (US5)**. The entitlements come from `EntitlementService::summaryFor`.
- [X] T050 [P] [US1] Create `app/Modules/Identity/Application/Dto/{AccountView,DeviceSessionView}.php` (`final readonly`), with the fields from contracts/identity-api.md. `AccountView` holds the decrypted email. `DeviceSessionView` holds `id`, `version`, `changeSeq`, `createdAt`, `updatedAt`, `deletedAt`, `userId`, `deviceName`, `platform`, `appVersion`, `state`, `endReason`, `signedInAt`, `lastUsedAt`, `endedAt` and `current`. Create `app/Modules/Identity/Http/Resources/{AccountResource,DeviceSessionResource}.php`, rendering the snake_case members and ISO-8601 `Z` instants with microseconds (the `SyncableResource` format).
- [X] T051 [US1] Create the requests and controllers:
  - `app/Modules/Identity/Http/Requests/RegisterRequest.php`: `email` required string email max 254; `password` required string between 8 and 128; `display_name` sometimes string between 1 and 100 after trim; `timezone` sometimes `timezone:all`.
  - `app/Modules/Identity/Http/Requests/LoginRequest.php`: `email`, `password`, and `device` as an array with `device.session_id` a required UUID (and check the version-7 nibble), `device.device_id` required matching `regex:/^[A-Za-z0-9._:-]{1,64}$/`, `device.name` required string between 1 and 100, `device.platform` required `in:android`, and `device.app_version` required matching `regex:/^\d+\.\d+\.\d+$/`.
  - `app/Modules/Identity/Http/Controllers/RegisterController.php`: invokable, `Envelope::accepted(['status' => 'accepted'])`.
  - `app/Modules/Identity/Http/Controllers/LoginController.php`: invokable, returns `Envelope::ok([...])`.
  Add both routes to `Http/routes.php`: `POST auth/register` with `throttle:identity-register`, and `POST auth/login`.
- [X] T052 [US1] Add `registerAccount` and `signIn` to `docs/openapi/paths/identity.yaml` exactly as in contracts/identity-api.md (every status, `security: []`, `additionalProperties: false`), and add their entries to the `paths:` index in `docs/openapi/openapi.yaml`.
- [X] T053 [US1] Run `dev exec -- vendor/bin/pest tests/Contract/Identity/RegisterTest.php tests/Contract/Identity/LoginTest.php tests/Feature/Identity/{Registration,UniformWork,SignInIdempotency,SessionOwnership,SessionLifetime}Test.php --compact` until green.

**Checkpoint**: a person can register and sign in, and the credential authenticates. This is the MVP.

---

## Phase 4: User Story 2 - Verify the address (Priority: P1)

**Goal**: single-use, 24-hour verification links; an authenticated resend limited to 3 per hour that supersedes earlier links; and the reusable verification gate.

**Independent Test**: register; hit a gated fixture route and get `403 EMAIL_NOT_VERIFIED`; verify via the mailed link; the fixture passes; a replayed link fails.

### Tests for User Story 2

- [X] T054 [P] [US2] Write `tests/Contract/Identity/VerifyEmailTest.php` covering:
  - `200 {"email_verified":true}`;
  - `400 VERIFICATION_TOKEN_INVALID` for a used, expired (`travel(25)->hours()`), superseded, or random link;
  - `422` for a missing `token`;
  - `resend-verification`: `202`; `401` without a credential; `429` on the 4th call within an hour.
- [X] T055 [P] [US2] Write `tests/Feature/Identity/VerificationLinkTest.php`:
  - the secret in the mail (captured with `Mail::fake`) is 43 base64url characters, and the DB holds only its SHA-256;
  - a resend supersedes the earlier link;
  - two concurrent redemptions give one success;
  - verifying an already-verified address with a live link succeeds without changing `email_verified_at`;
  - `address_verified` is audited exactly once.
- [X] T056 [P] [US2] Write `tests/Feature/Identity/VerificationGateTest.php`, using fixture routes registered in the test:
  - `POST /api/v1/_gated` with `auth:api` and `address.verified`: an unverified user gets `403 EMAIL_NOT_VERIFIED`, and a verified one gets `200`;
  - `GET /auth/sessions` (US3) is **not** gated, so an unverified user gets `200` there (mark the test `->skip()` until T066 lands; T067 unskips it);
  - `VerificationGate::assertVerified` throws `EmailNotVerified` for an unverified user id.

### Implementation for User Story 2

- [X] T057 [US2] Implement `app/Modules/Identity/Infrastructure/Repositories/EloquentLinkRepository.php` (implements `LinkRepository`):
  - `issue(userId, LinkKind, int $minutes): string`: in one transaction, set `superseded_at = now()` on the user's live links of that kind, then insert a new link with `secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')` and `secret_hash = hash('sha256', secret)`; return the plain secret.
  - `redeem(string $secret, LinkKind): ?string $userId`: a single `UPDATE identity_links SET used_at = now() WHERE secret_hash = ? AND kind = ? AND used_at IS NULL AND superseded_at IS NULL AND expires_at > now() RETURNING user_id`.
  - `supersedeAll(userId, LinkKind)`.
  Add `app/Modules/Identity/Domain/Account/LinkKind.php`, an enum `verify_address|reset_password`.
- [X] T058 [US2] Implement:
  - `app/Modules/Identity/Application/Verification/VerifyAddress.php`: `redeem` the link, or refuse `VerificationLinkInvalid`; if `email_verified_at` is null, set it and audit `address_verified` in the same transaction.
  - `app/Modules/Identity/Application/Verification/ResendVerification.php`: if already verified, do nothing; otherwise issue a link with `config('identity.links.verify_minutes')` and queue `VerifyAddressMail`.
  Update `SendRegistrationMail` (T047) to use `LinkRepository::issue`.
- [X] T059 [US2] Implement:
  - `app/Modules/Identity/Application/VerificationGate.php`: `assertVerified(string $userId): void`, throwing `EmailNotVerified`;
  - `app/Modules/Identity/Http/Middleware/EnsureAddressVerified.php`, which calls it with the caller id.
  Register the alias `address.verified` in `IdentityServiceProvider` (`$router->aliasMiddleware`). Add a PHPDoc on the provider saying other modules reference the **string alias only** (R-17).
- [X] T060 [US2] Create the requests and controllers:
  - `app/Modules/Identity/Http/Requests/VerifyEmailRequest.php`: `token` required string max 128;
  - `app/Modules/Identity/Http/Controllers/VerifyEmailController.php`: `200 Envelope::ok(['email_verified' => true])`;
  - `app/Modules/Identity/Http/Controllers/ResendVerificationController.php`: `202`.
  Add the routes: `POST auth/verify-email` (public), and `POST auth/resend-verification` with `auth:api` and `throttle:identity-resend`. Add `verifyEmail` and `resendVerification` to `identity.yaml` and the index.
- [X] T061 [US2] Create the mails:
  - `app/Modules/Identity/Application/Mail/VerifyAddressMail.php` (queued, `->locale($locale)`, link = `config('identity.links.verify_url').'?token='.$secret`);
  - `app/Modules/Identity/Application/Mail/AlreadyRegisteredMail.php` (no secret; points to the app's "forgot password");
  - views `app/Modules/Identity/Http/Views/mail/{verify-address,already-registered}.blade.php`, using `__('identity::mail.*')` only;
  - `app/Modules/Identity/Http/Lang/{en,ar}/mail.php`, with real Arabic.
  Run the US2 tests to green.

**Checkpoint**: registration mails carry working single-use links, and later features can gate writes with `address.verified`.

---

## Phase 5: User Story 3 - See and end device sessions, and sign out (Priority: P1)

**Goal**: list your own sessions, end one, end all others, and sign out, with revocation effective on the next request.

**Independent Test**: two devices; list from one, end the other; the ended credential gets 401 on its next request and the first keeps working.

### Tests for User Story 3

- [X] T062 [P] [US3] Write `tests/Contract/Identity/SessionsTest.php` covering:
  - `GET /auth/sessions` gives `200` with `data` of the caller's sessions only, `current` true on exactly one, no `device_id` member, `meta.limit`, `meta.has_more` and `meta.next_cursor`, `filter[state]=eq:active` working, `sort=-last_used_at` working, and `400 FILTER_FIELD_NOT_SUPPORTED` for `filter[device_name]`;
  - `DELETE /auth/sessions/{id}`: `204` for own, `204` again for already ended, `404` for another user's, `422 INVALID_ID` for `abc`;
  - `DELETE /auth/sessions`: `204`;
  - `POST /auth/logout`: `204`;
  - `401` on every one without a credential.
- [X] T063 [P] [US3] Write `tests/Feature/Identity/SessionRevocationTest.php`:
  - ending B from A makes B's credential `401` on the **very next** request while A still gets `200` (no cache; assert with two sequential requests in the same test process, since `Auth::forgetGuards()` between requests mirrors production);
  - ending all others leaves only the current one active;
  - logout ends only the current session;
  - each end writes one `session_revoked` audit entry with `SessionRevokedDetail(reason)` and dispatches one `DeviceSessionEnded` after commit;
  - an ended row survives with `ended_at` and `end_reason`, and its `version` is bumped.
- [X] T064 [US3] Extend `tests/Feature/Identity/SessionOwnershipTest.php`: user B `DELETE`s user A's session id and gets `404`, and A's row is unchanged.

### Implementation for User Story 3

- [X] T065 [US3] Implement the session services in `app/Modules/Identity/Application/Sessions/`:
  - `ListSessions`: `(callerId, currentSessionId, ?stateFilter, sort, cursor, limit): array{items: list<DeviceSessionView>, nextCursor: ?string, hasMore: bool}`. Use keyset pagination on `(sort column, id)`, and make the cursor opaque (`base64url(json)`) with `INVALID_CURSOR` on a decode failure.
  - `EndSession`: `(callerId, sessionId, EndReason::EndedByOwner or SignedOut if current)`. A session not the caller's gives `SessionNotFound`.
  - `EndOtherSessions`: `(callerId, currentSessionId)`, ending every active except the current one with `ended_by_owner`.
  - `SignOut`: `(callerId, currentSessionId)`, ending with `signed_out`.
  Each ends inside a transaction, audits `session_revoked` per ended session, and dispatches `DeviceSessionEnded` after commit.
- [X] T066 [US3] Create `app/Modules/Identity/Http/Controllers/SessionsController.php` with `index`, `destroy(string $id)` (validate UUIDv7, or `INVALID_ID`), and `destroyOthers`, plus `LogoutController.php`. The `index` query validation (`limit` 1..100, default 50; `filter[state]` op `eq` in `active|revoked|expired`; `sort` in `signed_in_at|-signed_in_at|last_used_at|-last_used_at`) uses the runtime's collection refusals (`INVALID_QUERY_PARAMETER`, `FILTER_FIELD_NOT_SUPPORTED`, `FILTER_OPERATOR_NOT_SUPPORTED`, `SORT_FIELD_NOT_SUPPORTED`). If the runtime lacks a helper, add `app/Http/Api/Collections/CollectionQuery.php` parsing `cursor`, `limit`, `filter[*]` and `sort` against an operation's declared filterable and sortable lists. Add the routes under `auth:api`.
- [X] T067 [US3] Add `listDeviceSessions` (with `x-filterable: [state]` and `x-sortable: [signed_in_at, last_used_at]`), `endDeviceSession`, `endOtherDeviceSessions` and `signOut` to `identity.yaml` and the index. Unskip T056's session case. Run the US3 tests to green.

**Checkpoint**: the P1 stories are complete, and the app can register, sign in, verify, and manage devices.

---

## Phase 6: User Story 4 - Recover a forgotten password (Priority: P2)

**Goal**: a uniform reset request; a single-use 60-minute link; completing the reset ends every session, clears the lock, and supersedes other reset links.

**Independent Test**: request a reset for a registered and an unregistered address and get identical answers; complete the reset; old sessions fail; the lock is cleared; the link can't be reused.

### Tests for User Story 4

- [X] T068 [P] [US4] Write `tests/Contract/Identity/PasswordResetTest.php` covering:
  - `forgot-password` gives `202` with identical bodies for a registered and an unregistered address, `422` for a missing `email`, and `429` on the 4th request for the same address within an hour, also for an unregistered address;
  - `reset-password` gives `204`, `400 RESET_TOKEN_INVALID` (used, expired after `travel(61)->minutes()`, superseded, or random), and `422 OUT_OF_RANGE` for a 7-character password.
- [X] T069 [P] [US4] Write `tests/Feature/Identity/PasswordResetTest.php`:
  - a completed reset ends **every** session (`end_reason = password_reset`, one `session_revoked` audit per session), deletes the `sign_in_failures` row, supersedes other reset links, audits `password_changed` once, and does not sign in (no new session);
  - a second reset request supersedes the first link.
  Extend `tests/Feature/Identity/UniformWorkTest.php`: a reset request for a registered and an unregistered address each dispatch exactly one `SendResetMail` job, and the job sends mail only for the registered one.

### Implementation for User Story 4

- [X] T070 [US4] Implement:
  - `app/Modules/Identity/Application/Passwords/RequestReset.php`: compute the lookup, and **always** dispatch one `SendResetMail(lookup, locale)`. Nothing else, so both paths do identical work.
  - `app/Modules/Identity/Application/Jobs/SendResetMail.php` (queued): find by lookup; if there's no user or the user is purged, stop; otherwise `LinkRepository::issue(reset_password, 60)` and send `ResetPasswordMail` in the profile's locale.
- [X] T071 [US4] Implement `app/Modules/Identity/Application/Passwords/ResetPassword.php`. It:
  - `redeem`s the link, or refuses `ResetLinkInvalid`;
  - applies `PasswordPolicy` (a violation raises a `ValidationException` on `password`);
  - in one transaction: updates the hash, ends every active session with `password_reset`, deletes the `sign_in_failures` row for the user's lookup (`FailureCounter::clear`), supersedes the reset links, and audits `password_changed`;
  - after commit, dispatches `DeviceSessionEnded` per ended session.
- [X] T072 [US4] Create:
  - the requests `ForgotPasswordRequest` (`email` required email max 254) and `ResetPasswordRequest` (`token` required string max 128; `password` required string between 8 and 128);
  - the controllers `ForgotPasswordController` (`202`) and `ResetPasswordController` (`204`);
  - the routes `POST auth/forgot-password` with `throttle:identity-reset`, and `POST auth/reset-password`;
  - `ResetPasswordMail` plus its view `reset-password.blade.php`, and the `mail.php` keys in both languages.
  Add `requestPasswordReset` and `resetPassword` to `identity.yaml` and the index. Run the US4 tests to green.

---

## Phase 7: User Story 5 - Resist password guessing (Priority: P2)

**Goal**: an account lock at 5 failures (15 minutes) and 10 failures (60 minutes), keyed by lookup value so unknown addresses lock identically; a per-origin limit of 20 per 15 minutes; `423` with `Retry-After`; reset clears the lock.

**Independent Test**: fail repeatedly against one address (registered and unregistered alike), and check the thresholds, `Retry-After`, expiry and reset clearing. Fail across addresses from one IP, and check the origin refusal.

### Tests for User Story 5

- [X] T073 [P] [US5] Write `tests/Feature/Identity/LockoutTest.php`:
  - 5 wrong passwords, then the 6th attempt **with the correct password** gets `423 ACCOUNT_LOCKED` with `Retry-After` between 899 and 900;
  - after `travel(15)->minutes()` sign-in works;
  - 10 consecutive failures lock for 60 minutes, and the lock never exceeds 60 (a `locked_until - last_failed_at` ≤ 60 min assertion);
  - an unregistered address behaves identically: the same statuses, the same `Retry-After`, a `sign_in_failures` row;
  - a success deletes the counter row;
  - a completed reset (US4) clears an active lock at once;
  - 20 failures across 20 different addresses from one IP within 15 minutes make the 21st attempt from that IP refused `429 RATE_LIMITED`, while a different IP is unaffected;
  - `sign_in_failed` is audited per failure, and for an unknown address has a null `subject_id` and `detail.lookup_prefix` of 8 hex characters;
  - `lockout_applied` is audited once per lock;
  - a request while locked neither checks the password (spy `Hash::check`: not called) nor extends `locked_until`.
- [X] T074 [US5] Extend `tests/Contract/Identity/LoginTest.php` with `423 ACCOUNT_LOCKED` and `429 RATE_LIMITED` responses, validated against the spec.

### Implementation for User Story 5

- [X] T075 [US5] Implement `app/Modules/Identity/Infrastructure/Repositories/EloquentFailureCounter.php` (implements `FailureCounter`):
  - `lockedFor(string $lookup): ?int $secondsRemaining`;
  - `recordFailure(string $lookup): ?int $newLockMinutes`: upsert `consecutive_failures + 1`, `last_failed_at = now()`, and when the count reaches the next configured threshold, `locked_until = now() + minutes` (capped at `cap_minutes`), returning the minutes when a lock was applied;
  - `clear(string $lookup)`.
  Use `INSERT … ON CONFLICT (lookup) DO UPDATE` so concurrent failures count correctly.
- [X] T076 [US5] Implement `app/Modules/Identity/Application/SignIn/OriginLimiter.php`, wrapping `RateLimiter` with the key `identity-origin:{ip}`, `tooMany()` and `hit()`, using `config('identity.lockout.origin')`. Hook both into `SignIn` (T049) in this order: the origin `tooMany` check → `TooManyRequests`, then the account lock → `AccountLocked(retryAfter)`, then the password. On failure, hit the origin limiter, record the failure, audit `sign_in_failed` (with the subject when the user exists, otherwise null plus the lookup prefix), and audit `lockout_applied` when a lock was applied. On success, clear the counter. Make `ChangePassword` (US7) and `RequestDeletion` (US6) reuse the same account-lock check and failure recording through a small `app/Modules/Identity/Application/SignIn/CredentialCheck.php` (`verify(userId|lookup, password)`).
- [X] T077 [US5] Add the `423` and `429` responses to `signIn` in `identity.yaml`. Run the US5 tests plus the whole US1 test set to green.

---

## Phase 8: User Story 6 - Request account deletion and change one's mind (Priority: P2)

**Goal**: request deletion with the password, a 30-day grace period, idempotent repeats, explicit cancel, **cancel by new sign-in** with a mail to the person, and **operator notification by email and in the dashboard**.

**Independent Test**: request, check the state and date; repeat and see the same date; cancel and see everything intact. Then request again and sign in anew: the deletion is cancelled, the person's mail is queued, one operator notification row exists and one operator email is queued, and redelivering the event adds nothing.

### Tests for User Story 6

- [X] T078 [P] [US6] Write `tests/Contract/Identity/AccountDeletionTest.php` covering:
  - `POST /account/deletion-request` gives `200 {state: "pending_deletion", deletion_scheduled_for}`, the same date on repeat, `401 INVALID_CREDENTIALS` for a wrong password, `401 UNAUTHENTICATED` without a credential, `423` when locked, and `422` for a missing password;
  - `DELETE /account/deletion-request` gives `204`, including when nothing is pending, and `401`.
- [X] T079 [P] [US6] Write `tests/Feature/Identity/AccountDeletionTest.php`:
  - a request sets `deletion_scheduled_for = requested + 30 days` exactly (freeze time), audits `deletion_requested`, dispatches `UserDeletionRequested` after commit, and queues `DeletionScheduledMail`;
  - a repeat after `travel(5)->days()` keeps the date;
  - a cancel restores `active` with every row identical (snapshot `users`, `profiles`, `device_sessions` and `user_keys` before and after, ignoring `updated_at` on `users`), audits `deletion_cancelled` with `detail.reason = explicit`, and dispatches `UserDeletionCancelled(reason: explicit)`;
  - an existing session keeps working throughout, with state and date untouched (FR-055a).
- [X] T080 [P] [US6] Write `tests/Feature/Identity/DeletionCancelledBySignInTest.php`:
  - with a pending account, a successful **new** sign-in returns `deletion_cancelled = true` and `account.state = active`, the cancel and the session issue commit together (force a failure after the cancel inside the transaction and assert nothing changed), exactly one `deletion_cancelled` audit with `reason = sign_in`, `UserDeletionCancelled(reason: sign_in, sessionId)` once, and `DeletionCancelledBySignInMail` queued once, naming the device name and instant;
  - a retried sign-in with the same `session_id` gives `deletion_cancelled = false`, no second mail, no second event;
  - a wrong password or a locked account cancels nothing;
  - using an existing session cancels nothing.
- [X] T081 [P] [US6] Write `tests/Feature/Admin/OperatorNotificationsTest.php`:
  - handling `UserDeletionCancelled(reason: sign_in)` inserts one `operator_notifications` row (`kind = deletion_cancelled_by_sign_in`, `subject_user_id`, `payload.session_id`) and queues one `OperatorNotificationMail` per configured recipient;
  - handling the **same** event again inserts nothing and sends nothing;
  - `reason: explicit` produces nothing;
  - no row or mail contains the user's address or device name (seed a known address, and search the serialised row and the rendered mail);
  - with an empty recipients list, the row is stored and no mail is sent;
  - `OperatorNotificationQueryService::latest()` returns DTOs, and `unreadCount()` counts only the last 7 days.
- [X] T082 [P] [US6] Write `tests/Feature/Admin/OperatorNotificationBellTest.php`. Livewire-test `OperatorNotificationBell` with a bound fake query service returning two views: it renders the count badge and the list in `en` and `ar`, shows the user id through the `identifier` primitive and the instant with its zone, and has only public properties of type `int` and `array` (the existing Arch test covers the no-Model rule). All new `admin::notifications.*` keys exist in both languages (extend the existing `TranslationCompletenessTest` data set if it enumerates files).

### Implementation for User Story 6

- [X] T083 [US6] Implement `app/Modules/Identity/Application/Deletion/RequestDeletion.php`. It checks the password through `CredentialCheck` (T076: lock → verify → record failure), then, in a transaction:
  - if `pending_deletion`, return the existing date;
  - otherwise set `deletion_requested_at = now()` and `deletion_scheduled_for = now() + config('identity.deletion_grace_days')` days, audit `deletion_requested`, and return the date.
  After commit, when newly requested, it dispatches `UserDeletionRequested` and queues `DeletionScheduledMail`.
- [X] T084 [US6] Implement `app/Modules/Identity/Application/Deletion/CancelDeletion.php`, with `cancel(string $userId, string $reason, ?string $sessionId): bool $cancelled`. It must run inside the caller's transaction when one is open. It clears both columns and sets `active` only if the state is `pending_deletion` (a conditional update, so it is idempotent), audits `deletion_cancelled` with `DeletionCancelledDetail($reason)`, and dispatches `UserDeletionCancelled` (after commit through the event interface) when cancelled.
- [X] T085 [US6] Wire cancel-by-sign-in into `SignIn` (T049's extension point). Inside the sign-in transaction, when the user is `pending_deletion` **and** the session was newly issued (not a rotation of an existing active session), call `CancelDeletion::cancel($userId, 'sign_in', $sessionId)`, set `deletionCancelled = true`, and after commit queue `DeletionCancelledBySignInMail(device name, signed-in instant)` in the profile's locale. A rotation of a session that already existed never cancels.
- [X] T086 [US6] Create:
  - the request `DeletionRequestRequest` (`password` required string max 128);
  - `app/Modules/Identity/Http/Controllers/DeletionRequestController.php` with `store` (200) and `destroy` (204, calling `CancelDeletion` with `explicit`), and the routes under `auth:api` for `POST` and `DELETE account/deletion-request`;
  - the mails `DeletionScheduledMail` and `DeletionCancelledBySignInMail`, with their views and `mail.php` keys in both languages. The scheduled mail states the date (a calendar date in the profile timezone, Western numerals) and "sign in or cancel in the app". The cancelled mail advises a password reset.
  Add `requestAccountDeletion` and `cancelAccountDeletion` to `identity.yaml` and the index, and add `deletion_cancelled` to the `signIn` response.
- [X] T087 [US6] Create the Admin persistence:
  - `app/Modules/Admin/Infrastructure/Database/Migrations/2026_09_26_100001_create_operator_notifications_table.php`: `id uuid PK`, `kind varchar(40)` CHECK in (`deletion_cancelled_by_sign_in`), `subject_user_id uuid`, `occurred_at timestamptz`, `payload jsonb`, `source_event_id uuid UNIQUE`, `created_at timestamptz`, and an index `(occurred_at DESC)`;
  - `app/Modules/Admin/Infrastructure/Models/OperatorNotification.php`;
  - `app/Modules/Admin/Application/Ports/OperatorNotificationStore.php`: `recordOnce(...): bool $inserted`, `latest(int): list<…>`, `countSince(CarbonInterface): int`;
  - `app/Modules/Admin/Infrastructure/Repositories/EloquentOperatorNotificationStore.php`, where `recordOnce` uses `insertOrIgnore` and returns the affected count > 0;
  - `app/Modules/Admin/Infrastructure/AdminPersistenceServiceProvider.php`, binding the port, registered in `bootstrap/providers.php`.
- [X] T088 [US6] Implement the Admin application layer:
  - `app/Modules/Admin/Application/Listeners/NotifyOperatorsOfDeletionCancelledBySignIn.php`: `ShouldQueue`; `handle(UserDeletionCancelled $event)` returns early unless `reason === 'sign_in'`; `recordOnce(kind, subjectUserId, occurredAt, ['session_id' => …], sourceEventId: $event->eventId)`; only if inserted, `Mail::to($recipient)->queue(new OperatorNotificationMail(...))` for each of `config('admin.notifications.recipients')`.
  - `app/Modules/Admin/Application/Mail/OperatorNotificationMail.php` plus the view `app/Modules/Admin/Http/Views/mail/operator-notification.blade.php`: user id, UTC instant and `admin.timezone` instant, one explanatory line; no address, name or device name.
  - `app/Modules/Admin/Application/Dto/OperatorNotificationView.php`: `id`, `kind`, `subjectUserId`, `occurredAt`, `titleKey`, `bodyKey`, `params`.
  - `app/Modules/Admin/Application/OperatorNotificationQueryService.php`: `latest(int $limit = 20)`, and `unreadCount()` as the count since `now() - admin.notifications.unread_days`.
  Register the listener in `AdminServiceProvider` (`Event::listen(UserDeletionCancelled::class, …)`). Admin importing an Identity **Domain event** is the event mechanism P-02 rule 4 allows. Confirm the arch tests stay green.
- [X] T089 [US6] Create `app/Modules/Admin/Http/Livewire/OperatorNotificationBell.php` and its view `app/Modules/Admin/Http/Views/livewire/operator-notification-bell.blade.php`:
  - public `int $unread` and `array $items` (a list of arrays of scalars), loaded in `mount()` from `OperatorNotificationQueryService`;
  - an icon-button with `badge` count, opening a `slide-over` listing items with the `identifier` and `instant` primitives, logical utilities only;
  - strings in `app/Modules/Admin/Http/Lang/{en,ar}/notifications.php`.
  Don't add it to any route or the shell yet (D2 mounts it in the `notifications` slot). Add a sample of the bell's markup to the gallery, fed with static sample `OperatorNotificationView`s, never the query service (spec 006 FR-040).
- [X] T090 [US6] Run the US6 tests and `dev exec -- vendor/bin/pest tests/Arch tests/Feature/Admin --compact` to green.

---

## Phase 9: User Story 7 - Change credentials while signed in (Priority: P3)

**Goal**: change the password with the current one; every other session ends; the current one stays; reset links are superseded; a mail tells the person.

**Independent Test**: two devices; change from A; B gets 401, A keeps working; the old password fails and the new one works.

### Tests for User Story 7

- [X] T091 [P] [US7] Write `tests/Contract/Identity/PasswordChangeTest.php` covering `204`; `401 INVALID_CREDENTIALS` for a wrong current password; `401 UNAUTHENTICATED` without a credential; `422` for a 7-character password and for new = current; and `423` when locked.
- [X] T092 [P] [US7] Write `tests/Feature/Identity/PasswordChangeTest.php`:
  - after a change from A, B's credential gets `401` on its next request, A gets `200`, B's row has `end_reason = password_changed`, and outstanding reset links are superseded;
  - `password_changed` is audited once, plus one `session_revoked` per ended session;
  - `DeviceSessionEnded` is dispatched per ended session, after commit;
  - `PasswordChangedMail` is queued once;
  - a wrong current password increments the lockout counter.

### Implementation for User Story 7

- [X] T093 [US7] Implement `app/Modules/Identity/Application/Passwords/ChangePassword.php`. It uses `CredentialCheck` (lock → verify → record failure), applies `PasswordPolicy`, and refuses `new === current` through a `ValidationException` on `new_password`. Then, in a transaction: update the hash, end every active session except the current one with `password_changed`, supersede reset links, and audit. After commit: events and `PasswordChangedMail` (with view and keys in both languages).
- [X] T094 [US7] Create the request `ChangePasswordRequest` (`current_password` required string max 128; `new_password` required string between 8 and 128), `ChangePasswordController` (`204`), and the route `POST auth/change-password` under `auth:api`. Add `changePassword` to `identity.yaml` and the index. Run the US7 tests to green.

---

## Phase 10: Polish & Cross-Cutting Concerns

**Purpose**: the reserved Google endpoint, scheduled and console commands, the document amendments the spec requires, the global secret check, and the full gate.

- [X] T095 [P] Write `tests/Contract/Identity/GoogleSignInTest.php`: with `identity.google.enabled = false`, `POST /api/v1/auth/google` gives `404 NOT_FOUND`. Register the route in `Http/routes.php` only when `config('identity.google.enabled')` (with no controller body beyond `abort(404)`; a `// B1 reserved, FR-080` PHPDoc on the closure's controller class). Document `signInWithGoogle` in `identity.yaml` with `x-status: reserved`, per contracts/identity-api.md.
- [X] T096 [P] Write `tests/Contract/Identity/NoSecretsInResponsesTest.php`. Across one full scenario (register, login, sessions, verify, reset, change, deletion), no response body contains `password_hash`, `token`, `secret_hash`, `email_ciphertext`, `email_lookup` or `wrapped_key`, nor any hash value read from the database; and `credential` appears only in the login response.
- [X] T097 [P] Write `tests/Feature/Identity/MailTranslationTest.php`: every `identity::mail.*` key and `admin::notifications.*` key used by the mail views exists in both `en` and `ar`, and each mailable renders in `ar` with `dir="rtl"` in the HTML part and Western numerals only (use the existing `toUseWesternDigitsOnly` expectation).
- [X] T098 Create `app/Modules/Identity/Infrastructure/Console/ExpireSessions.php` (`identity:expire-sessions`), which marks active sessions idle for more than `session_inactivity_days` as `state = expired`, `end_reason = expired`, `ended_at = now()`, bumping `version` and `change_seq`, and audits `session_revoked` with reason `expired`. Schedule it daily in `routes/console.php`. Add a case to `SessionLifetimeTest.php`: after the command, the 91-day-idle session shows `expired` in `GET /auth/sessions`.
- [X] T099 [P] Create `app/Modules/Identity/Infrastructure/Console/RewrapKeys.php` (`identity:rewrap-keys`), which re-wraps every `user_keys` row not on `active_key`, in chunks of 500, reporting the rows remaining per `kid`. Add a case to `tests/Unit/Identity/AttributeCipherTest.php` or a Feature test: after adding `k2` and rewrapping, the values still open, and each `kid` is `k2`.
- [X] T100 [P] Create `app/Modules/Identity/Infrastructure/Console/TimingProbe.php` (`identity:timing-probe --trials=`), registered only when `app()->environment('local')`. It creates a throwaway user in a rolled-back transaction, times `SignIn` for a wrong password and for an unknown address, prints the medians, and exits 1 if they differ by 10% or more (SC-003, quickstart §3).
- [X] T101 Amend `docs/security-baseline.md`:
  - **Deletion**: rule 5, "A new successful sign-in during the grace period cancels the request; the person is told by mail and operators are notified", and update the state diagram's cancel arrow to "cancel, or sign in, before the 30 days elapse";
  - **The audit entry**: `subject_id` is "uuid, null only for `sign_in_failed` and `lockout_applied` on an unregistered address";
  - **What is protected at rest**: add the four rows from data-model.md;
  - **Lockout**: a short "Route budgets" table (register 10 per hour per origin; resend and reset 3 per hour per address);
  - the appendix checklist gains **G7**: "A sign-in during the grace period cancels the deletion and notifies the person and operators."
- [X] T102 [P] Amend `docs/privacy-commitments.md`, in both languages, under "Leaving":
  - English: "**Signing in again within the 30 days cancels the deletion.** We email you when that happens, so you'll know if it wasn't you."
  - Arabic: «**تسجيل الدخول مرة أخرى خلال الثلاثين يوماً يلغي الحذف.** نرسل لك رسالة عندما يحدث ذلك، لتعرف إن لم يكن أنت.»
  Also update the "How long we keep things" deletion bullet to mention signing in, and add a mapping row, "Signing in during the grace period cancels deletion, and we tell you" → "B1 FR-054, FR-057".
- [X] T103 [P] Amend `docs/glossary.md`:
  - **User**: States `active` ⇄ `pending_deletion` → `purged`, with the note "a new sign-in or an explicit cancel returns to active";
  - a new **Audit Entry** entry: Identity, aggregate root, persisted, Sync no ("operators read it through Admin"), `single-state`, append-only, 400-day retention;
  - a new **Operator Notification** entry: Admin, aggregate root, persisted, Sync no ("operators see it in the back office"), `single-state`;
  - both new entities added to the entity index table and to the "persisted but not synced" table, with the count lines updated (22 → 24 entities; 7 → 9 persisted but not synced).
- [X] T104 [P] Amend `.specify/memory/constitution.md` to **v1.3.1 (PATCH)**:
  - the Identity row's Publishes gains `UserSignedIn`, `DeviceSessionEnded` and `UserDeletionCancelled`;
  - add a Sync Impact Report entry at the top ("PATCH: Identity row lists three events introduced by spec 007; no rule changes");
  - update the footer's Last Amended to 2026-09-26.
  The plan's Constitution Check keeps citing v1.3.0, since in-flight plans keep their version.
- [X] T105 [P] Amend `docs/engineering-conventions.md`:
  - **Module layout**: one paragraph plus a row saying that the shared API runtime lives in `app/Http/Api` (envelope, problem renderer, api-group middleware, base `ApiRequest`, log redaction), is cross-cutting plumbing outside the modules, and that modules extend `ApiRequest` and return `Envelope`;
  - **Environment profiles**: rows for `HASH_ARGON_MEMORY/TIME/THREADS` (local and staging and production 65536/4/1, pipeline 1024/1/1), `IDENTITY_KEYS`, `IDENTITY_ACTIVE_KEY` and `IDENTITY_LOOKUP_KEY` ("secret store"), `ADMIN_NOTIFICATION_RECIPIENTS`, and `API_MIN_ANDROID_VERSION`;
  - **Dependencies**: `laravel/sanctum` added to the approved list, with the date.
- [X] T106 [P] Update `specs/README.md`'s Status table with a row for B1 → `007-identity-auth`, state "🟡 Planned" (moving to "Built" at the end of implementation), and a one-line summary. Update `specs/briefs/README.md` to note that the authentication package is decided (Sanctum).
- [X] T107 Run `dev exec -- vendor/bin/pint --dirty --format agent`, then `dev exec -- composer test` (lint, Larastan level 8, arch, the full suite). Fix every failure. Larastan will need PHPDoc generics on the Eloquent relations and the `@property` blocks on the new models.
- [X] T108 Walk [quickstart.md](./quickstart.md) §2 by hand (13 steps, using Mailpit and `psql` for `operator_notifications`) and §3 (the timing probe and the at-rest grep). Record the results, including the timing medians, in a short "Verification" note appended to `quickstart.md`. Then ask the owner to run the complete suite.

---

## Dependencies & Execution Order

### Phase dependencies

- **Setup (T001–T008)**: T002 is independent of T001. T003 comes before any file creation. T004–T008 run in parallel.
- **Foundational (T009–T038)**: depends on Setup.
  - The tests T009–T015 are written first.
  - T016 comes before T018–T021 and T025.
  - T017 and T018 come before T020.
  - T023–T027 come before T028–T032.
  - T028 comes before T029, which comes before T030–T032, which come before T034.
  - T033 and T035 are independent of the Identity models.
  - T036 depends on T025.
  - T037 is independent.
- **US1 (T039–T053)**: depends on Foundational.
- **US2 (T054–T061)**: depends on US1's registration (T047). T057 is also needed by US4.
- **US3 (T062–T067)**: depends on US1 (sessions exist). It is independent of US2.
- **US4 (T068–T072)**: depends on US1 and T057 (the link repository).
- **US5 (T073–T077)**: depends on US1 (it extends `SignIn`). T073's reset-clears-lock case needs US4.
- **US6 (T078–T090)**: depends on US1 and US5's `CredentialCheck` (T076). The Admin part (T081, T082, T087–T089) depends only on T026 (the event) and can start right after Foundational.
- **US7 (T091–T094)**: depends on US1, US3 (session end), US4 (link supersede) and US5 (`CredentialCheck`).
- **Polish (T095–T108)**: T101–T106 (docs) can start any time after the plan. T107 and T108 come last.

### Story completion order

`Setup → Foundational → US1 → {US2, US3} → US4 → US5 → US6 → US7 → Polish`

US2 and US3 can run in parallel. US6's Admin half can run alongside US1–US5.

### Within each story

Tests first and failing, then repositories, then application services, then requests, controllers and routes, then the OpenAPI operation, then green.

## Parallel Execution Examples

**Foundational tests together:**

```text
T009 ErrorCodeParity   T010 Api/* runtime tests   T011 LogRedaction
T012 Unit/Identity/*   T013 AuditTrail            T014 AddressAtRest   T015 GuardSurface
```

**Foundational domain types together:** `T023 value types · T024 audit enums · T025 refusals · T026 events`, and separately `T033 Billing seam · T035 auth config · T037 identity.yaml skeleton`.

**US1 tests together:** `T039 RegisterTest · T040 RegistrationTest · T041 UniformWorkTest · T042 LoginTest · T043 SignInIdempotencyTest · T044 SessionOwnershipTest · T045 SessionLifetimeTest`.

**US2 alongside US3** (after US1): `T054–T056` with `T062–T063`, then `T057–T061` with `T065–T067`.

**US6 Admin half early:** `T081 · T082 · T087 · T088 · T089` alongside US2–US5, since they need only the `UserDeletionCancelled` event from T026.

**Docs in parallel at any time:** `T101 baseline · T102 privacy · T103 glossary · T104 constitution · T105 conventions · T106 READMEs`.

## Implementation Strategy

### MVP (US1 only)

Phases 1–3 give a working API runtime, the Identity core, registration and sign-in with device credentials. That is enough for the Android team to integrate sign-in. Stop and validate with the US1 tests and `composer test`.

### Incremental delivery

1. US1: register and sign in (MVP).
2. US2: address verification and the gate. Later wallet features can gate writes.
3. US3: the sessions screen and sign-out. All P1 stories are done here.
4. US4: password reset.
5. US5: lockout. The launch security bar is met here.
6. US6: deletion with cancel-by-sign-in and operator notifications. Store-policy deletion is met.
7. US7: password change.
8. Polish: the reserved Google endpoint, the sweeps, the document amendments, the full gate, and the manual walk.

Each checkpoint leaves `composer test` green.
