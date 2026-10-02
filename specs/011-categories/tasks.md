---

description: "Task list for Categories (B5)"
---

# Tasks: Categories

**Input**: Design documents from `/specs/011-categories/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/categories-api.md](./contracts/categories-api.md), [quickstart.md](./quickstart.md), the frozen contract `docs/openapi/paths/categories.yaml` (written and indexed; `info.version` v1.4) and [ADR-0014](../../docs/adr/0014-platform-owned-categories.md).

**Tests**: included. The spec's Invariants table names every test file (constitution P-16 rule 3). Write each story's tests first and watch them fail.

**Organization**: grouped by user story, in delivery order:

- US1: see the default set and my own categories (P1)
- US2: create and edit my own categories safely (P1)
- US3: archive or delete a category (P2)
- US4: see a partner's category in a shared wallet (P2)

**Conventions for every task** (as in specs 007–010):

- Run through the container: `dev exec -- vendor/bin/pest <path> --compact`, `dev <artisan command>`.
- P-02 layers: everything lives in `app/Modules/Wallets` under a `Categories` sub-namespace; ports in `Domain/Categories/Ports`; Infrastructure depends only on Domain; controllers pass `(string) $request->user()->getAuthIdentifier()` to Application services. No other module's code changes. The verification gate is the middleware alias `address.verified`.
- Refusals extend `Shared\Domain\Errors\Refusal` with a catalogue `ErrorCode`, following `Wallets\Domain\Refusals\*` (`WalletVersionConflict` carries `current` through a presenter closure).
- Writes follow spec 010 exactly: `version` in the JSON body (also on DELETE and archive); identical write → `200` with no bump; tombstone → `409` with `current` for any non-delete write; stale → `409`; precedence of spec §6.
- Request rules reuse `App\Http\Api\Rules\NotBlank` and the wallet `icon` (`^[a-z0-9_]{1,40}$`) and `color` (`^#[0-9A-F]{6}$`) shapes; unknown members → `UNKNOWN_FIELD`.
- Instants written with microseconds (`format('Y-m-d H:i:s.uP')`); empty `meta` is `(object) []`.
- **Lock order** (spec FR-023, ADR-0014 rule 10): category rows first, then wallet rows in ascending id.
- Run `dev exec -- vendor/bin/pint --dirty --format agent` after PHP edits.
- Contract tests use `assertValidRequest()` and `assertValidResponse($status)`, covering every documented status.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on unfinished tasks)
- **[Story]**: US1–US4 from spec.md

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: the documents the code depends on, the new error code, and the two tables.

- [X] T001 [P] Update `docs/glossary.md` (research R-13), before any code uses the terms: rewrite the **Category** entry — one-sentence definition "an income or expense bucket, owned by one person or by the platform"; Sync "yes — user scope, for a person's category; a platform category is reference data, read through `GET /platform-categories` and cached"; States "a person's: `active` → `archived`; an unused one may be tombstoned. The platform's: `active` ⇄ `retired`, never deleted"; Notes add **platform category** (owned by the platform, carries a `system_key`, used by reference, never copied, editable by nobody through the API; ADR-0014), **`system_key`** (stable, language-independent, unique, never renamed or reused; reports group by it), **retired** (`archived_at` on a platform category), **in use** (derived, never stored: any live or tombstoned record references it; blocks deletion), and that `parent_id` stays reserved — **flat in MVP (O2 resolved by spec 011)**. Rewrite **Category Reference**: Persistence "persisted projection — produced by the server, never authored"; States "live → tombstoned (withdrawn, category deleted, or wallet deleted); a re-publish revives the same record"; Notes: carries `category_id`, `owner_user_id`, `kind`, `name`, `icon`, `color`; published only for a person's category. In "What syncs" add a row/note that platform categories are read through `GET /platform-categories`. Entity count stays 24.
- [X] T002 [P] Amend `docs/security-baseline.md` (research R-11): in § Verification gate add the row "**Any write to their own categories** — **refused**" and one sentence why (a category's name reaches wallet partners through its references); in the checklist append to C1 "and every write to a category"; in § What is protected at rest add the row "Platform category names — none at rest — yes — every signed-in person". No other change.
- [X] T003 [P] Amend `.specify/memory/constitution.md` to **v1.3.4 (PATCH)**: P-01's and P-04's `ADRs:` lines gain `[ADR-0014](../../docs/adr/0014-platform-owned-categories.md)` (extends ADR-0012); add a Sync Impact Report entry ("PATCH: P-01 and P-04 list ADR-0014, introduced by spec 011; no rule changes"); update the version line, footer, Constitution Check template version and Last Amended.
- [X] T004 Add the error code **`CATEGORY_IN_USE`** in one change (research R-7; the parity tests need all of these together): in `docs/openapi/error-catalog.yaml` add it to the `ErrorCode` enum and to `x-error-catalog` as `status: 409`, `level: [response]`, `title: 'Category in use'`, `meaning: 'The category is referenced by recorded history and cannot be deleted. Archive it instead.'`, `retryable: never`, `since: v1.4`; add the case to `app/Modules/Shared/Domain/Errors/ErrorCode.php` with status, title and retry class; add detail sentences to `lang/en/problems.php` and `lang/ar/problems.php`; add `CATEGORY_IN_USE` to the codes listed in `docs/openapi/components/responses.yaml` `Conflict` description; in `docs/openapi/paths/categories.yaml` `deleteCategory` replace "the category-in-use code frozen in spec 011 §5" with "CATEGORY_IN_USE". Run `dev exec -- vendor/bin/pest tests/Contract/OpenApi --compact` (ErrorCodeParityTest, SkeletonTest) — green.
- [X] T005 Create `app/Modules/Wallets/Infrastructure/Database/Migrations/2026_10_02_000003_create_categories_table.php` per data-model.md: `id uuid PK`; `user_id uuid NULL` (no FK across modules); `system_key varchar(40) NULL UNIQUE` CHECK `~ '^[a-z][a-z0-9_]{1,39}$'`; `kind varchar(7)` CHECK in (`income`,`expense`); `name varchar(50) NULL` CHECK `name IS NULL OR btrim(name) <> ''`; `names jsonb NULL`; `icon varchar(40) NULL` CHECK `~ '^[a-z0-9_]{1,40}$'`; `color char(7) NULL` CHECK `~ '^#[0-9A-F]{6}$'`; `position smallint NULL`; `parent_id uuid NULL` CHECK `parent_id IS NULL`; `archived_at timestamptz(6) NULL`; `version integer` CHECK ≥ 1; `change_seq bigint NULL`; `timestampsTz(6)`; `deleted_at timestamptz(6) NULL`; the two shape CHECKs verbatim from data-model.md ("person's row: `user_id IS NOT NULL AND system_key IS NULL AND name IS NOT NULL AND names IS NULL AND position IS NULL AND change_seq IS NOT NULL`"; "platform row: `user_id IS NULL AND system_key IS NOT NULL AND name IS NULL AND names IS NOT NULL AND icon IS NOT NULL AND color IS NOT NULL AND position IS NOT NULL AND change_seq IS NULL AND deleted_at IS NULL`") combined with OR; indexes `(user_id, created_at, id) WHERE deleted_at IS NULL`, `(user_id, name, id) WHERE deleted_at IS NULL`, `UNIQUE (position) WHERE user_id IS NULL`.
- [X] T006 Create `app/Modules/Wallets/Infrastructure/Database/Migrations/2026_10_02_000004_create_category_references_table.php`: `id uuid PK`; `wallet_id uuid FK → wallets`; `category_id uuid FK → categories`; `owner_user_id uuid`; `kind varchar(7)` CHECK in (`income`,`expense`); `name varchar(50)`; `icon varchar(40) NULL`; `color char(7) NULL`; `version integer` CHECK ≥ 1; `change_seq bigint`; `timestampsTz(6)`; `deleted_at timestamptz(6) NULL`; indexes `UNIQUE (wallet_id, category_id) WHERE deleted_at IS NULL`, `(wallet_id, created_at, id) WHERE deleted_at IS NULL`, `(category_id) WHERE deleted_at IS NULL`. Depends on T005.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: domain types, ports, persistence, the usage hook and the HTTP skeleton every story uses.

### Tests first

- [X] T007 [P] `tests/Unit/Wallets/Categories/CategoryRulesTest.php` and `tests/Unit/Wallets/Categories/SystemKeyTest.php`: `CategoryKind` has exactly `income`, `expense`; `CategoryRules` refuses any kind change (`IMMUTABLE_FIELD` at `/kind`), accepts names of 1–50 characters after trimming and refuses blank or 51, compares identity with the trimmed name; `SystemKey` accepts `groceries`, `phone_internet`, refuses `Groceries`, `1abc`, `a`, a 41-character key.
- [X] T008 [P] `tests/Feature/Wallets/Categories/CategoryOwnershipConstraintTest.php`: inserting a row that is both or neither (user + `system_key`; neither; platform without `names`; person's without `change_seq`; platform with `deleted_at`; any `parent_id`) fails at the database; one valid row of each kind inserts.

### Implementation

- [X] T009 [P] Domain types in `app/Modules/Wallets/Domain/Categories/`: `CategoryKind.php`, `CategoryRecord.php`, `PlatformCategoryRecord.php`, `CategoryReferenceRecord.php` (readonly, the columns of data-model.md), `CategoryRules.php`, `SystemKey.php`.
- [X] T010 [P] Refusals in `app/Modules/Wallets/Domain/Categories/Refusals/`: `CategoryNotFound` (NOT_FOUND), `CategoryVersionConflict` (VERSION_CONFLICT, `current`), `CategoryFieldImmutable` (VALIDATION_FAILED + `IMMUTABLE_FIELD` at `/kind`), `CategoryInUse` (CATEGORY_IN_USE). Localised details in `lang/en/problems.php` and `lang/ar/problems.php` (kind immutable). Depends on T004.
- [X] T011 [P] Ports in `app/Modules/Wallets/Domain/Categories/Ports/`: `CategoryRepository` (findAny, findOwnedForUpdate, findPlatformForShare, insert, updateIfVersion, listOwned with keyset cursor and sort, allPlatform, upsertPlatform), `CategoryReferenceRepository` (liveFor(walletId, categoryId), anyFor(walletId, categoryId), liveWalletIdsFor(categoryId), insert, update, tombstone, tombstoneAllInWallet, listLive(walletId, cursor, limit)), `UserScopeSequence` (`next(): int`).
- [X] T012 Infrastructure in `app/Modules/Wallets/Infrastructure/`: `Models/Category.php` (client id; `names` cast to array), `Models/CategoryReference.php` (HasUuids, server UUIDv7), `Repositories/EloquentCategoryRepository.php`, `Repositories/EloquentCategoryReferenceRepository.php`, `Sync/UserScopeSequence.php` (`select nextval('sync_change_seq')`, PHPDoc citing spec 007 R-19); bind in `WalletsPersistenceServiceProvider.php`; factories `Database/Factories/CategoryFactory.php` (states `archived`, `deleted`, `income`, `expense`, `platform` with a unique `system_key`, `names`, `position`) and `CategoryReferenceFactory.php`.
- [X] T013 [P] `app/Modules/Wallets/Application/Categories/CategoryUsage.php` (interface `isUsed(string $categoryId): bool`, PHPDoc: B6 transactions and postings — live and tombstoned —, B7 recurring rules, B8 budgets each register one, tagged with this interface's name) and `CategoryUsageQuery.php` (`isUsed`, and `usedAmong(list<string>): array<string,bool>` for lists, asking every tagged participant; false when none). Wire `giveTagged(CategoryUsage::class)` in `app/Modules/Wallets/Application/WalletsServiceProvider.php`.
- [X] T014 HTTP skeleton: in `app/Modules/Wallets/Http/routes.php` add the category route groups — reads under `auth:api`; writes (`PUT|PATCH|DELETE categories/{id}`, `POST categories/{id}/archive`) under `auth:api` **and** `address.verified` — each route being added by the task that creates its controller action (T020, T021, T027, T031, T038), so no route ever points at a missing class. Add `Http/Resources/CategoryResource.php` (spec §5 Category members incl. `user_id`, `archived_at`, `in_use`), `PlatformCategoryResource.php` (`id`, `system_key`, `kind`, `names {ar, en}`, `icon`, `color`, `position`, `archived_at`, `updated_at`), `CategoryReferenceResource.php` (spec §5 members, `wallet_id`).

**Checkpoint**: migrations run, rules and constraint tests pass, routes resolve.

---

## Phase 3: User Story 1 - See the default set and my own categories (Priority: P1) 🎯 MVP

**Goal**: every signed-in person reads the localised platform set and their own list.

**Independent Test**: seed, read the platform set (26 entries, both names), read an empty own list.

### Tests for User Story 1

- [X] T015 [P] [US1] `tests/Unit/Wallets/Categories/PlatformCategoryDefinitionsTest.php`: `PlatformCategorySeeder::definitions()` has exactly the 26 rows of spec §3 (key, kind, en, ar, icon, colour, position), unique keys and positions, every entry passes `validate()`; `validate()` rejects a bad key, a blank name, a 51-character name, a lowercase colour.
- [X] T016 [P] [US1] `tests/Feature/Wallets/Categories/PlatformCategorySeederTest.php`: first run inserts 26 rows; a second run changes nothing (same ids, `updated_at`, `version`); a changed name updates in place keeping the id and bumping `version` and `updated_at`; a key dropped from the definitions is retired (`archived_at` set, row kept); re-adding it un-retires it with the same id; a kind change throws and changes nothing.
- [X] T017 [P] [US1] `tests/Contract/Wallets/Categories/PlatformCategoriesTest.php`: `200` with 26 entries in `position` order, `meta {next_cursor: null, has_more: false}`; identical `data` under `Accept-Language: ar` and `en`; retired entry present with `archived_at`; `401` without a credential; `PUT`, `POST`, `PATCH`, `DELETE` on the path → `405` with `Allow` containing `GET` and `code: METHOD_NOT_ALLOWED`; an unverified person can read.
- [X] T018 [P] [US1] `tests/Contract/Wallets/Categories/ListCategoriesTest.php`: own live categories only (archived included, tombstoned, another person's and platform excluded); `sort=name`, `sort=-created_at`; paging with `cursor`/`limit` up to 200; `INVALID_CURSOR`, `SORT_FIELD_NOT_SUPPORTED`, `FILTER_FIELD_NOT_SUPPORTED`, `INVALID_QUERY_PARAMETER`; empty list `200` with `data: []`.

### Implementation for User Story 1

- [X] T019 [US1] **Platform set seeder** (research R-4, CONTRIBUTING item 12): create `app/Modules/Wallets/Infrastructure/Database/Seeders/PlatformCategorySeeder.php` with `definitions()` returning exactly spec §3's table — `system_key`, `kind`, `names` `{en, ar}`, `icon`, `color`, `position` — i.e. groceries/بقالة, dining_out/مطاعم وكافيهات, transport/مواصلات, fuel/بنزين, rent/إيجار, utilities/كهرباء ومياه وغاز, phone_internet/موبايل وإنترنت, health/صحة وعلاج, education/تعليم, clothing/ملابس, household/مستلزمات البيت, personal_care/عناية شخصية, entertainment/ترفيه, subscriptions/اشتراكات, travel/سفر, kids/أطفال, gifts_given/هدايا, charity/زكاة وصدقات, other_expense/مصروفات أخرى (expense, positions 1–19) and salary/مرتب, business_income/دخل تجاري, freelance/عمل حر, investment_income/عائد استثمار, gifts_received/هدايا مستلمة, refunds/مبالغ مستردة, other_income/دخل آخر (income, positions 20–26), with the English names, icons and colours of §3; `validate()` as `BillingSeeder::validate` (key shape `^[a-z][a-z0-9_]{1,39}$`, unique keys and positions, names non-blank ≤ 50, icon and colour shapes); `run()` upserting by `system_key` in one transaction — insert new keys with a fresh UUIDv7 and `version` 1, update changed rows in place (`version` + 1, `updated_at`), never change an id, throw on a kind change, retire keys absent from `definitions()` (`archived_at` once), un-retire re-added ones. Create `app/Modules/Wallets/Infrastructure/Database/Seeders/WalletsSeeder.php` calling it (the module seeder `DatabaseSeeder` discovers). Update `database/seeders/DatabaseSeeder.php`'s stale comment ("No module ships a seeder yet") if it is still there. Run `dev db:seed --no-interaction` twice.
- [X] T020 [US1] `app/Modules/Wallets/Application/Categories/PlatformCategoryQueryService.php` (`all()` ordered by `position`) and `app/Modules/Wallets/Http/Controllers/PlatformCategoryController.php` (`index`, one page, `meta` with `next_cursor: null`, `has_more: false`, `limit` = row count clamped to 1–200).
- [X] T021 [US1] `app/Modules/Wallets/Application/Categories/CategoryQueryService.php` (`listFor(userId, sort, cursor, limit)` live own categories, `x-sortable [created_at, name]` byte order stabilised by `id`, `in_use` via `CategoryUsageQuery::usedAmong`; `find(userId, id)` owner only, tombstone included, else `CategoryNotFound`) and `app/Modules/Wallets/Http/Controllers/CategoryController.php` (`index`, `show`), using the shared collection grammar as `WalletController::index`.

**Checkpoint**: US1 tests green; quickstart steps 1–3 pass.

---

## Phase 4: User Story 2 - Create and edit my own categories safely (Priority: P1)

**Goal**: offline-safe create, whole and partial save, conflicts, immutable kind, verification gate.

**Independent Test**: create, replay, PATCH, stale PATCH → 409, kind change → 422.

### Tests for User Story 2

- [X] T022 [P] [US2] `tests/Feature/Wallets/Categories/SaveCategoryTest.php`: create sets owner, `version` 1, a `change_seq` from `sync_change_seq`; replay with version 0 or the current version → no change to `version` or `change_seq`; save bumps both; stale version → `CategoryVersionConflict` carrying the present state, nothing written; tombstone → conflict carrying the tombstone even for an identical body; kind change → `CategoryFieldImmutable`; name stored trimmed; PUT version > 0 for an unknown id → `CategoryNotFound`; no event dispatched.
- [X] T023 [P] [US2] `tests/Contract/Wallets/Categories/UpsertCategoryTest.php` and `tests/Contract/Wallets/Categories/GetCategoryTest.php`: `201` create, `200` replay and save; `409 VERSION_CONFLICT` with `current`; `422` `IMMUTABLE_FIELD` at `/kind`, `OUT_OF_RANGE` at `/name` (blank, 51 characters), `INVALID_ENUM_VALUE` at `/kind`, `UNKNOWN_FIELD` for `user_id`, `system_key`, `archived_at`, `in_use`, `parent_id`, `change_seq`, `deleted_at`; `422 INVALID_ID`; `404` for another person's category, a platform category's id, a wallet's id, and version > 0 on an unknown id — each leaving the record unchanged; GET own `200`, own tombstone `200` with `deleted_at`, another person's `404`.
- [X] T024 [P] [US2] `tests/Contract/Wallets/Categories/PatchCategoryTest.php`: `200` partial save; identical → unchanged; `kind` → `UNKNOWN_FIELD`; version-only body → `REQUIRED` at `""`; missing version → `REQUIRED` at `/version`; unknown id → `404`; stale → `409`.
- [X] T025 [P] [US2] `tests/Contract/Wallets/Categories/CategoryVerificationGateTest.php`: an unverified person gets `403 EMAIL_NOT_VERIFIED` on PUT, PATCH, archive and DELETE, and `200` on `GET /categories`, `GET /categories/{id}` and `GET /platform-categories`.

### Implementation for User Story 2

- [X] T026 [US2] `app/Modules/Wallets/Application/Categories/CategoryService.php` with `save(actorId, id, values, version, $present): SavedCategory` and `patch(...)` per research R-6: `DB::transaction` → `findOwnedForUpdate` (`SELECT … FOR UPDATE`; any other owner, a platform row, an id held by a wallet or a category reference (this module's other tables — P-06 rule 3; other modules' tables are not probed, as in spec 010), or no row with version > 0 → `CategoryNotFound`; no row with version 0 → create) → tombstone refusal → identical replay → stale version → `CategoryRules::assertKindUnchanged` → `updateIfVersion` with `version + 1`, `change_seq` from `UserScopeSequence`, `updated_at`. Add `Application/Categories/Dto/{CategoryView,SavedCategory}.php`.
- [X] T027 [US2] `app/Modules/Wallets/Http/Requests/SaveCategoryRequest.php` (`kind` required in enum; `name` required string, `NotBlank`, max 50 after trim → `OUT_OF_RANGE`; `icon`, `color` present and nullable with the wallet shapes; `version` integer ≥ 0 optional; unknown members refused) and `PatchCategoryRequest.php` (≥ 1 of `name`, `icon`, `color`; `version` ≥ 1 required; `kind` and every other member → `UNKNOWN_FIELD`), following `SaveWalletRequest`/`PatchWalletRequest`; `CategoryController::upsert` (`201` on create) and `::patch`.

**Checkpoint**: US2 tests green; quickstart steps 4–10 pass.

---

## Phase 5: User Story 3 - Archive or delete a category (Priority: P2)

**Goal**: archive (one-way, idempotent) and delete (unused only, tombstone, idempotent).

**Independent Test**: archive; delete unused; with a stub usage participant, delete → 409.

### Tests for User Story 3

- [X] T028 [P] [US3] `tests/Feature/Wallets/Categories/DeleteCategoryTest.php`: delete tombstones (row kept, `deleted_at`, `version` + 1, new `change_seq`); delete of a tombstone returns it unchanged whatever the version; a test-only tagged `CategoryUsage` answering true → `CategoryInUse`, nothing changed, archive still allowed and `in_use: true` on read; archive sets `archived_at` once and is idempotent; a stale version → conflict; delete re-checks usage after locking the row.
- [X] T029 [P] [US3] `tests/Contract/Wallets/Categories/ArchiveCategoryTest.php` and `tests/Contract/Wallets/Categories/DeleteCategoryTest.php`: archive `200` and repeat unchanged; `409` stale and tombstone; delete `200` tombstone and repeat; `409 CATEGORY_IN_USE` (stub participant bound in the test); missing body → `REQUIRED` at `/version`; `404` for another person's category and a platform id; the deleted category disappears from `GET /categories`.

### Implementation for User Story 3

- [X] T030 [US3] `CategoryService::archive(actorId, id, version, $present)` and `::delete(actorId, id, version, $present)` in `app/Modules/Wallets/Application/Categories/CategoryService.php`: same lookup and precedence; archive sets `archived_at` once; delete returns a tombstone unchanged, else checks stale version, then `CategoryUsageQuery::isUsed` (after the row lock) → `CategoryInUse`, else tombstones with `version + 1` and a new `change_seq`.
- [X] T031 [US3] `app/Modules/Wallets/Http/Requests/CategoryVersionRequest.php` (`{version ≥ 1}` only) and `CategoryController::archive`, `::destroy`.

**Checkpoint**: US3 tests green; quickstart steps 15–17 pass (16 without the reference part until US4).

---

## Phase 6: User Story 4 - See a partner's category in a shared wallet (Priority: P2)

**Goal**: the read-only Category Reference — stored, published, republished, withdrawn, cascaded, readable by members.

**Independent Test**: publish a reference directly; list it as another member; rename and see it follow; `405` on writes.

### Tests for User Story 4

- [X] T032 [P] [US4] `tests/Feature/Wallets/Categories/CategoryReferencesTest.php`: `publish` inserts one reference with the category's display fields and owner, `change_seq` from the wallet counter, and the wallet's `change_seq` then highest in the scope with its `version` unchanged; publishing again is a no-op; publishing a platform category creates nothing; `withdrawIfUnused` tombstones; publishing after withdrawal revives the same row (same id, version + 1); a rename, icon or colour change of the category updates every live reference in the same transaction (rolled back with it if the save fails) with version + 1 and a new wallet-scope `change_seq`; archiving and an identical replay change no reference; deleting the category tombstones its references; deleting a wallet tombstones its references (through the deletion participant) and leaves the category untouched; at most one live reference per (wallet, category) even under two concurrent publishes.
- [X] T033 [P] [US4] `tests/Feature/Wallets/Categories/CategoryReferenceLockOrderTest.php`: two real connections (outside `RefreshDatabase`'s transaction, as spec 010's concurrency tests do) — one renames a category referenced in two wallets, the other runs a simulated B6 write (share-lock the category, then lock the wallet, then `publish`) — both commit, no deadlock, and the reference ends with the newest name; assert `republish` locks wallets in ascending id order.
- [X] T034 [P] [US4] `tests/Contract/Wallets/Categories/ListCategoryReferencesTest.php`: a member (owner, editor, viewer) gets `200` with live references in publication order, paging; a non-member `404`; a former member after wallet deletion `404`; `INVALID_ID`, `INVALID_CURSOR`, `INVALID_QUERY_PARAMETER`; `PUT`, `POST`, `PATCH`, `DELETE` on the path → `405` with `Allow` containing `GET`; an empty wallet `200` with `data: []`.

### Implementation for User Story 4

- [X] T035 [US4] `app/Modules/Wallets/Application/Categories/CategoryReferences.php` per research R-8: `publish(walletId, categoryId)` (caller holds the category share lock; locks the wallet; no-op for a platform category or an existing live reference; inserts or revives, stamps `ScopeSequence::nextFor` on the reference then once more for the wallet's final stamp), `withdrawIfUnused(walletId, categoryId)` (PHPDoc: B6/B7 call it when their last record in the wallet stops using the category), `republish(CategoryRecord)` (category row already locked; wallets from `liveWalletIdsFor` sorted ascending, each locked then updated and stamped), `withdrawAll(categoryId)` (same order), `listFor(userId, walletId, cursor, limit)` authorised by `WalletAuthorizer::authorize(…, Permission::T01ReadTransactions)`. PHPDoc states the lock-order rule for B6/B7.
- [X] T036 [US4] `app/Modules/Wallets/Application/Categories/ReferencesGoWithWallet.php` implementing `WalletDeletionParticipant` (tombstone every live reference of the wallet with `version + 1` and a value from `$nextChangeSeq`); tag it in `WalletsServiceProvider`.
- [X] T037 [US4] Wire `CategoryReferences::republish` into `CategoryService::save`/`patch` (only when `name`, `icon` or `color` actually changed) and `withdrawAll` into `CategoryService::delete`, inside the same transaction, after the category row lock.
- [X] T038 [US4] `app/Modules/Wallets/Http/Controllers/CategoryReferenceController.php` (`index`) with the shared collection grammar, `x-filterable: []`, `x-sortable: []`.

**Checkpoint**: US4 tests green; quickstart steps 11–14 and 16 pass.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [X] T039 [P] Apply the endpoint review checklist (`docs/api-conventions.md` appendix) and the security review checklist (C1–C2, D1, E1–E5) to every operation in `docs/openapi/paths/categories.yaml`; record any justified "no" in plan.md § Review checklists. Confirm the implementation matches the frozen contract exactly — any change to a member the C5 client binds to (contracts/categories-api.md table) is a contract change and must be raised, not made.
- [X] T040 [P] Record B5's obligations on later features in `specs/briefs/B06-transactions-ledger.md`, `specs/briefs/B07-recurring-transactions.md` and `specs/briefs/B08-budgets.md` under a short "Inherited from B5 (spec 011)" note (lock order; `publish`/`withdrawIfUnused`; a `CategoryUsage` participant counting live and tombstoned records; refuse a reference to a tombstoned category, which closes the delete-vs-first-use race of spec Edge Cases; T-02 "own or platform category only"; archived-category references; platform totals always scoped and grouped by `system_key`, ADR-0014 rules 5–6, spec FR-027–FR-028), per research R-14.
- [X] T041 Run the gates: `dev exec -- composer test:lint`, `test:types`, `test:arch`, `test:suite`, `test:api` (or `composer test` with the raised timeout) and the quickstart manual walk after `dev db:seed`; time `GET /categories?limit=200` for a person with 200 categories and `GET /platform-categories` on the dev stack and record the p95 (plan target < 100 ms; spec SC-007) in summary.md; re-bundle `docs/openapi` and import into Apidog (project 1332822, module 1363204) with a "Categories" doc. State "Seeder: `WalletsSeeder` → `PlatformCategorySeeder` — the platform default set (26 categories, en/ar), idempotent, run at every deploy" in the commit/PR description, and remind the deployer to run `db:seed` once B5 ships.
- [ ] T042 Write `specs/011-categories/summary.md` (what was built, results, assumed defaults, remaining risks), as spec 010 did.

---

## Dependencies & Execution Order

- **Setup (T001–T006)**: T001–T003 are independent docs; T004 is independent; T005 → T006.
- **Foundational (T007–T014)**: depends on T005–T006; T010 depends on T004. Blocks every story.
- **US1 (T015–T021)**: depends on Foundational.
- **US2 (T022–T027)**: depends on Foundational; independent of US1 except both edit `CategoryController.php` (serialise those edits).
- **US3 (T028–T031)**: depends on US2 (extends `CategoryService`).
- **US4 (T032–T038)**: depends on US2 (republish on save) and US3 (withdraw on delete) for T037; T032–T036 and T038 can start after Foundational.
- **Polish (T039–T042)**: after all stories.

## Parallel Execution Examples

- Setup: T001, T002, T003, T004 together; then T005 → T006.
- Foundational: T007, T008, T009, T010, T011, T013 together; then T012, T014.
- US1 tests: T015, T016, T017, T018 together, then T019 → T020, T021.
- US2 tests: T022, T023, T024, T025 together, then T026 → T027.
- After US2: US3 tests (T028, T029) alongside US4 tests (T032, T033, T034) and T035, T036.

## Implementation Strategy

1. **MVP** = Setup + Foundational + US1: the platform set is seeded and readable, own lists answer — the C5 picker can be built against live data.
2. Add US2 (create, edit, conflicts): everything B6 needs to reference categories.
3. Add US3 (archive, delete with the usage hook) and US4 (references); then Polish. Each phase ends at a green checkpoint and can be committed on its own on `main`.
