---

description: "Task list for Profile & Preferences (B2)"
---

# Tasks: Profile & Preferences

**Input**: Design documents from `/specs/008-profile-preferences/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/profile-api.md](./contracts/profile-api.md), [quickstart.md](./quickstart.md)

**Tests**: included. The spec's Invariants table names every test file, and constitution P-16 rule 3 requires one. Write each story's tests first and watch them fail.

**Organization**: grouped by user story: US1 read (P1), US2 save (P1), US3 nothing rewritten plus the currency lock (P2).

**Conventions for every task** are the same as spec 007's [tasks.md](../007-identity-auth/tasks.md):

- Run through the container: `dev exec -- vendor/bin/pest <path> --compact`, `dev <artisan command>`.
- Layer rules (P-02):
  - Controllers pass `callerId($request)` (the `ResolvesCaller` trait) to Application services.
  - Infrastructure depends only on Domain; ports live in `Domain/Ports`.
  - Another module is reached only through its `Application` namespace.
- Refusals are `Refusal` subclasses or `ApiRefusal` with field errors. Never hand-build a problem.
- Instants are written with microseconds (`format('Y-m-d H:i:s.uP')`), and calendar dates as `Y-m-d`.
- Run `dev exec -- vendor/bin/pint --dirty --format agent` after PHP edits.
- Contract tests use `withHeaders(asCredential($credential))`, `assertValidRequest()` and `assertValidResponse($status)`, and cover every documented status.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on unfinished tasks)
- **[Story]**: US1–US3 from spec.md

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: the schema change, the shared `Currency`, and the Wallets seam.

- [X] T001 Create the migration `app/Modules/Identity/Infrastructure/Database/Migrations/2026_09_27_000001_add_preferences_to_profiles.php`. It adds to `profiles`:
  - `default_currency` "char(3) NOT NULL DEFAULT 'EGP'";
  - `budget_period_start_day` "smallint NOT NULL DEFAULT 1", with `CHECK (budget_period_start_day BETWEEN 1 AND 28)` through `DB::statement`;
  - `budget_period_start_day_from` "date NULL".
  `down()` drops the three columns. Run `dev migrate`.
- [X] T002 [P] Create `App\Modules\Shared\Domain\Money\Currency` in `app/Modules/Shared/Domain/Money/Currency.php` (R-2): a `final readonly` class with `string $code` and `int $minorUnits`, a private `ACTIVE` table (`code => minor units`) of the **active ISO 4217 alphabetic codes** (from the ISO 4217 list, amendment in force September 2026; exclude funds, precious metals, test codes `XTS`/`XXX`, and withdrawn codes), `public static function isKnown(string $code): bool` (exact uppercase match only), and `public static function of(string $code): self`, which throws `InvalidArgumentException` for an unknown or lower-case code. The PHPDoc says it is the first piece of follow-up F-4 and that `Money` arrives with the first feature storing an amount. Include at least EGP 2, USD 2, EUR 2, SAR 2, AED 2, KWD 3, BHD 3, OMR 3, JOD 3, JPY 0 and GBP 2.
- [X] T003 [P] Create the Wallets seam (R-3):
  - `app/Modules/Wallets/Application/TransactionQueryService.php`: an interface with `hasLiveTransactionsRecordedBy(string $userId): bool` ("at least one transaction with created_by = the user and deleted_at null, in any wallet");
  - `app/Modules/Wallets/Application/Provisional/NoTransactionsYet.php`: a final class implementing it that always returns `false`, with PHPDoc "Replaced by B6; delete this directory then";
  - `app/Modules/Wallets/Application/WalletsServiceProvider.php`, binding the interface to `NoTransactionsYet`.
  Register the provider in `bootstrap/providers.php`, after `BillingServiceProvider`.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: the domain types, the repository, and the read and write shapes both stories share.

**⚠️ No user-story work starts until this phase is complete.**

- [X] T004 [P] Write `tests/Unit/Shared/CurrencyTest.php`:
  - `EGP`, `KWD` and `JPY` are known, with minor units 2, 3 and 0;
  - `egp`, `XXX` and `ABC` are not;
  - `Currency::of('egp')` throws.
  This is plain PHP with no framework.
- [X] T005 [P] Write `tests/Unit/Identity/BudgetStartDayTest.php` for `BudgetStartDay::appliesFrom($day, $today)` (R-4):
  - day 25 on 2026-09-27 gives `2026-10-25`;
  - day 28 on 2026-09-27 gives `2026-09-28`;
  - day 27 on 2026-09-27 gives `2026-10-27` (strictly after today);
  - day 1 on 2026-12-15 gives `2027-01-01`;
  - day 28 on 2027-02-28 gives `2027-03-28`;
  - days 0 and 29 throw `InvalidArgumentException`.
- [X] T006 Create `app/Modules/Identity/Domain/Preferences/BudgetStartDay.php`, with `public static function appliesFrom(int $day, DateTimeImmutable $today): string` returning the first `Y-m-d` strictly after `$today` whose day of month equals `$day`, and refusing a day outside 1–28. Make T005 pass. Make T004 pass with T002.
- [X] T007 [P] Create `app/Modules/Identity/Domain/Preferences/ProfileRecord.php` (`final readonly`): `id`, `userId`, `?string $displayName` (plaintext), `locale`, `timezone`, `defaultCurrency`, `int $budgetPeriodStartDay`, `?string $budgetPeriodStartDayFrom` ("Y-m-d or null"), `int $version`, `int $changeSeq`, `DateTimeImmutable $createdAt`, `DateTimeImmutable $updatedAt`. Add `withChanges(array $values): self` and `writableValues(): array{display_name: ?string, locale: string, timezone: string, default_currency: string, budget_period_start_day: int}`.
- [X] T008 [P] Create `app/Modules/Identity/Domain/Events/ProfileUpdated.php`: `final readonly`, `ShouldDispatchAfterCommit`, carrying `eventId`, `occurredAt`, `userId` and `list<string> $changedFields`. Its PHPDoc says "names only, never values" (R-7).
- [X] T009 Extend `app/Modules/Identity/Domain/Ports/ProfileRepository.php` with:
  - `findSealed(string $userId): array{id: string, user_id: string, display_name_ciphertext: ?string, locale: string, timezone: string, default_currency: string, budget_period_start_day: int, budget_period_start_day_from: ?string, version: int, change_seq: int, created_at: DateTimeImmutable, updated_at: DateTimeImmutable}`;
  - `saveIfVersion(string $userId, int $expectedVersion, array $columns): bool`, a conditional update that returns false when no row had that version.
  Implement both in `app/Modules/Identity/Infrastructure/Repositories/EloquentProfileRepository.php`. `saveIfVersion` runs `UPDATE … SET …, version = version + 1, change_seq = nextval, updated_at = now WHERE user_id = ? AND version = ?` through the query builder, with `ChangeSequence::next()` and microsecond instants. Add `default_currency`, `budget_period_start_day` and `budget_period_start_day_from` to `$casts` and the `@property` block in `app/Modules/Identity/Infrastructure/Models/Profile.php`.
- [X] T010 Create `app/Modules/Identity/Application/Profiles/Profiles.php`, the application service that turns rows into `ProfileRecord`: `find(string $userId): ProfileRecord` (opens `display_name_ciphertext` with `AttributeCipher`), and `isCurrencyLocked(string $userId): bool` (delegates to `Wallets\Application\TransactionQueryService`).
- [X] T011 [P] Create `app/Modules/Identity/Http/Resources/ProfileResource.php`, whose static `toArray(ProfileRecord $profile, bool $currencyLocked): array` emits:
  - the syncable envelope: `id`, `version`, `change_seq`, `created_at`, `updated_at`, and `deleted_at` as null, with instants through `Instants::format`;
  - `user_id`, `display_name`, `locale`, `timezone`, `default_currency`, `default_currency_locked`, `budget_period_start_day` and `budget_period_start_day_from`.
- [X] T012 Add to `docs/openapi/paths/identity.yaml`, per [contracts/profile-api.md](./contracts/profile-api.md):
  - the schemas `Profile` (allOf `SyncableResource` + `UserScoped` + its own members, with `default_currency` pattern `^[A-Z]{3}$`, `budget_period_start_day` 1–28, `budget_period_start_day_from` `[string, 'null']` format date, and `default_currency_locked` boolean), `ProfileWrite` (`additionalProperties: false`, required as in the contract, `display_name` `[string, 'null']` maxLength 100) and `Me` (`account` → `Account`, `profile` → `Profile`);
  - `/me` (`getMe`) and `/me/profile` (`saveProfile`) with every status in the contract; the 409 `$ref`s `Conflict` and the 422 `$ref`s `UnprocessableEntity`;
  - both paths in the `openapi.yaml` index.
  Add `IMMUTABLE_FIELD` wording to the `UnprocessableEntity` description in `docs/openapi/components/responses.yaml` if it is not listed. `dev exec -- vendor/bin/pest tests/Contract/OpenApi` stays green.

**Checkpoint**: unit tests green; the OpenAPI checks green.

---

## Phase 3: User Story 1 - See my account and preferences (Priority: P1) 🎯 MVP

**Goal**: `GET /me` returns the account and the profile, with defaults, and is not behind the verification gate.

**Independent Test**: register, sign in and `GET /me`. The values from registration are there, with EGP, day 1 and version 1.

- [X] T013 [P] [US1] Write `tests/Contract/Identity/ProfileTest.php`, starting with the read:
  - `GET /me` gives `200`, `data.account.email`, and `data.profile` with `default_currency` "EGP", `budget_period_start_day` 1, `budget_period_start_day_from` null, `default_currency_locked` false and `version` 1, validated against the spec;
  - without a credential it gives `401 UNAUTHENTICATED`.
- [X] T014 [P] [US1] Extend `tests/Feature/Identity/VerificationGateTest.php`: an unverified person gets `200` from `GET /me` and from a valid `PUT /me/profile` (unskip the second once T024 lands).
- [X] T015 [US1] Create `app/Modules/Identity/Application/Profiles/ReadMe.php`: `handle(string $userId): array{account: AccountView, profile: ProfileRecord, currencyLocked: bool}`, using `Accounts::view` and `Profiles`.
- [X] T016 [US1] Create `app/Modules/Identity/Http/Controllers/ProfileController.php` with `show(Request, ReadMe)`, returning `Envelope::ok(['account' => AccountResource::toArray(...), 'profile' => ProfileResource::toArray(...)])`. Add `Route::get('me', …)->name('me')` inside the `auth:api` group in `app/Modules/Identity/Http/routes.php`. Run T013 and T014 to green.

**Checkpoint**: the app can load settings.

---

## Phase 4: User Story 2 - Change my preferences (Priority: P1)

**Goal**: `PUT /me/profile` with the check order shape → identical replay → version → lock → apply (R-1), bumping `version` and `change_seq`, and falling back to the profile's language.

**Independent Test**: save new values, read them back, replay the same save with no movement, send a stale version and get `409` with `current`.

- [X] T017 [P] [US2] Extend `tests/Contract/Identity/ProfileTest.php` for `PUT /me/profile`:
  - `200` for a valid save;
  - `200` for an identical replay;
  - `409 VERSION_CONFLICT` with `current.version`;
  - `422` for each of `locale: "fr"` (`INVALID_ENUM_VALUE`), `timezone: "Mars/Base"` (`INVALID_ENUM_VALUE`), `default_currency: "egp"` (`INVALID_CURRENCY`), `budget_period_start_day: 29` (`OUT_OF_RANGE`), `display_name` of 101 characters (`OUT_OF_RANGE`), an omitted `timezone` (`REQUIRED`), and an extra `change_seq` (`UNKNOWN_FIELD`);
  - `401` without a credential.
- [X] T018 [P] [US2] Write `tests/Feature/Identity/ProfileUpdateTest.php`:
  - a save changes every value, bumps `version` by 1, draws a larger `change_seq`, moves `updated_at`, and dispatches `ProfileUpdated` once, with `changedFields` naming exactly the changed members and no values;
  - an identical replay leaves `version`, `change_seq` and `updated_at` unchanged and dispatches nothing;
  - a stale version applies nothing;
  - `display_name` "" or null clears it; "  Ada  " is stored as "Ada";
  - an Arabic name `عائشة` round-trips unchanged;
  - changing the start day sets `budget_period_start_day_from` to `BudgetStartDay::appliesFrom` of today in the profile's timezone (freeze time); leaving it unchanged keeps the stored date.
- [X] T019 [P] [US2] Write `tests/Feature/Identity/ProfileLanguageTest.php`:
  - after saving `locale: "ar"`, an authenticated request without `Accept-Language` answers `Content-Language: ar`, with an Arabic `detail` on a problem;
  - with `Accept-Language: en` it answers `en`;
  - an unauthenticated request without the header answers `en`;
  - a password-changed mail queued after the change renders in Arabic.
- [X] T020 [P] [US2] Extend `tests/Feature/Identity/AddressAtRestTest.php`: after saving `display_name: "Ada Lovelace"`, no Identity table contains `Ada Lovelace` in readable form.
- [X] T021 [US2] Create `app/Modules/Identity/Http/Requests/SaveProfileRequest.php`, extending `IdentityRequest`, with these rules:
  - `display_name`: `present`, `nullable`, `string`, `max:100`;
  - `locale`: `required`, `in:ar,en`;
  - `timezone`: `required`, `string`, `timezone:all`;
  - `default_currency`: `required`, `string`, plus a closure rule that fails with field code `INVALID_CURRENCY` unless `Currency::isKnown($value)`;
  - `budget_period_start_day`: `required`, `integer`, `between:1,28`;
  - `version`: `required`, `integer`, `min:1`.
  To map the closure to `INVALID_CURRENCY`, extend `App\Http\Api\Problems\FieldErrors` with a rule name `currency` → `ErrorCode::InvalidCurrency` and register the rule as an `Illuminate\Contracts\Validation\ValidationRule` class `App\Http\Api\Rules\IsoCurrency` (in `app/Http/Api/Rules/IsoCurrency.php`), so `failed()` reports it as `IsoCurrency` → `currency`. Map class basenames in `FieldErrors::codeFor`.
- [X] T022 [US2] Create `app/Modules/Identity/Application/Profiles/SaveProfile.php`: `handle(string $userId, array $values, int $version): ProfileRecord`, in the order of R-1.
  1. Trim `display_name`, treating an empty one as null.
  2. Load the current profile. If `writableValues()` equals the incoming values, return it unchanged (replay).
  3. If `$version !== $current->version`, throw `Identity\Domain\Refusals\ProfileVersionConflict`, a new `Refusal` with `ErrorCode::VersionConflict` and extension `current` → the `ProfileResource` array. Create the refusal class in `app/Modules/Identity/Domain/Refusals/ProfileVersionConflict.php`, with the `current` array passed in, since Domain cannot import Http.
  4. If `default_currency` differs and `Profiles::isCurrencyLocked`, throw `Identity\Domain\Refusals\DefaultCurrencyLocked` (new, in `app/Modules/Identity/Domain/Refusals/DefaultCurrencyLocked.php`): `VALIDATION_FAILED` with one `FieldError('/default_currency', ErrorCode::ImmutableField, __('problems.default_currency_locked'))`.
  5. Compute `budget_period_start_day_from` via `BudgetStartDay::appliesFrom` when the day changed, using today in the **new** timezone.
  6. Seal `display_name`, then `saveIfVersion`. On false, re-read and throw the conflict.
  7. Dispatch `ProfileUpdated` with the changed member names, and return the fresh record.
- [X] T023 [US2] Allow `Refusal` extensions to carry an array: widen the `$extensions` PHPDoc in `app/Modules/Shared/Domain/Errors/Refusal.php` to `array<string, scalar|array<mixed>>`, and make `ProblemRenderer` emit them as they are. That is how `current` rides on `VERSION_CONFLICT`. Add `'default_currency_locked'` to `lang/en/problems.php` ("The default currency can't change once you have recorded transactions.") and `lang/ar/problems.php` («لا يمكن تغيير العملة الافتراضية بعد تسجيل معاملات.»). `ProblemRenderer` already prefers `FieldError->detail` when it is set.
- [X] T024 [US2] Add `update(SaveProfileRequest, SaveProfile)` to `ProfileController`, returning `Envelope::ok(ProfileResource::toArray($saved, $locked))`, and add `Route::put('me/profile', …)->name('me.profile')` in the `auth:api` group. Unskip T014's PUT case.
- [X] T025 [US2] Create `app/Modules/Identity/Http/Listeners/ApplyProfileLanguage.php` (R-5), handling `Laravel\Sanctum\Events\TokenAuthenticated`: when the current request has no `Accept-Language` header, `app()->setLocale(ProfileRepository::localeOf($token->tokenable_id ?? user id))`. Resolve the user id via `$event->token->getAttribute('user_id')` so Http does not import the Identity model. Register it in `IdentityServiceProvider::boot` with `Event::listen`. Run T017–T020 to green.

**Checkpoint**: preferences can be read and saved, with conflicts and replays handled.

---

## Phase 5: User Story 3 - Preferences shape what comes next, never what came before (Priority: P2)

**Goal**: changing timezone, currency or start day rewrites nothing else, and the currency locks once the person has recorded transactions.

**Independent Test**: change the three preferences and diff every other table (none changed). Bind the transaction query service to `true` and see the currency change refused while the same currency passes.

- [X] T026 [P] [US3] Write `tests/Feature/Identity/ProfilePreferencesDoNotRewriteTest.php`. Snapshot `users`, `user_keys`, `device_sessions`, `identity_links`, `audit_entries` and `operator_notifications` (as `row_to_json` text, ordered by id); save a new timezone, currency and start day; assert every snapshot is identical, and that only the `profiles` row changed.
- [X] T027 [P] [US3] Write `tests/Feature/Identity/DefaultCurrencyLockTest.php`, binding `Wallets\Application\TransactionQueryService` to a fake that returns `true`:
  - changing `default_currency` gives `422 VALIDATION_FAILED` with `errors[0]` `{pointer: "/default_currency", code: "IMMUTABLE_FIELD"}`, the localised detail, and nothing applied (the display name changed in the same save is also not applied);
  - the same save with the current currency gives `200`;
  - `GET /me` shows `default_currency_locked: true`;
  - a stale version with a changed currency gives `409`, not `422`;
  - with the fake returning `false`, the change succeeds;
  - with the default binding (`NoTransactionsYet`), `default_currency_locked` is false.
- [X] T028 [US3] Add the lock case to `tests/Contract/Identity/ProfileTest.php` (the 422 `IMMUTABLE_FIELD` response validates against the spec). Run T026–T028 to green.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [X] T029 [P] Update `docs/api-conventions.md`, Localisation: "`Accept-Language` absent: an authenticated request uses the person's profile language; otherwise `en`", and keep the per-response fallback rule.
- [X] T030 [P] Update `docs/glossary.md`, the Profile entry's Notes: add `default_currency` (a default for new wallets only; locked once the person has recorded a live transaction), `budget_period_start_day` 1–28 with `budget_period_start_day_from`, and the language fallback.
- [X] T031 [P] Update `specs/README.md` Status with a B2 → `008-profile-preferences` row, "🟡 Built, awaiting review". Update `docs/engineering-conventions.md` if needed (no new environment keys are expected; say so in the PR).
- [X] T032 Run `dev exec -- vendor/bin/pint --format agent` and `dev exec -- composer test` (lint, Larastan level 8, arch, suite). Fix everything; Larastan needs array shapes on `ProfileRecord::writableValues` and the repository row.
- [X] T033 Walk [quickstart.md](./quickstart.md) steps 1–6 against `http://saveme-backend.test`. Re-bundle `docs/openapi` and re-import into Apidog project 1332822 with `--module-map "Personal finance backend API=1363204"`, then add a "Profile" section to the Apidog doc "Identity & authentication guide" (id 2444505) covering `GET /me`, `PUT /me/profile` with `version`, the `409` merge, and the currency lock. Append a short "Verification" note to `quickstart.md`.

---

## Dependencies & Execution Order

- **Setup (T001–T003)**: T002 and T003 are parallel. T001 comes first for the Feature tests.
- **Foundational (T004–T012)**: T004 and T005 come before T006 (and T002). T007–T009 come before T010. T011 needs T007. T012 is independent.
- **US1 (T013–T016)**: needs Foundational.
- **US2 (T017–T025)**: needs US1's controller (T016). T023 comes before T022's conflict body. T021 comes before T024.
- **US3 (T026–T028)**: needs US2 (the save exists). The lock itself is implemented in T022; US3 proves it.
- **Polish (T029–T033)**: T029–T031 can happen at any time; T032 and T033 come last.

Story order: `Setup → Foundational → US1 → US2 → US3 → Polish`.

## Parallel Execution Examples

- **Setup**: `T002 Currency · T003 Wallets seam`.
- **Foundational tests and types**: `T004 · T005 · T007 · T008 · T011 · T012`.
- **US2 tests together**: `T017 · T018 · T019 · T020`.
- **US3 tests together**: `T026 · T027`.
- **Docs**: `T029 · T030 · T031`.

## Implementation Strategy

1. **MVP (US1)**: `GET /me`. The app can load settings with defaults.
2. **US2**: saving with conflicts, replay and the language fallback. B2 is complete for the app.
3. **US3**: the proofs that nothing is rewritten, plus the currency lock. It becomes observable with B6.
4. **Polish**: docs, the full gate, the manual walk and the Apidog sync.

Each checkpoint leaves `composer test` green.
