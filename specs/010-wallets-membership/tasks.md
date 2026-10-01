---

description: "Task list for Wallets & Membership (B4)"
---

# Tasks: Wallets & Membership

**Input**: Design documents from `/specs/010-wallets-membership/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/wallets-api.md](./contracts/wallets-api.md), [quickstart.md](./quickstart.md), and the frozen contract `docs/openapi/paths/wallets.yaml` (already written and indexed; `info.version` v1.3).

**Tests**: included. The spec's Invariants table names every test file (constitution P-16 rule 3). Write each story's tests first and watch them fail.

**Organization**: grouped by user story, in delivery order:

- US1: create and list wallets (P1)
- US2: edit a wallet safely (P1)
- US5: one rulebook for every role (P1) — the authorizer itself is foundational; US5 proves the whole matrix and wires `forWallet`
- US3: archive and delete (P2)
- US4: members and ownership transfer (P2)

**Conventions for every task** (as in specs 007–009):

- Run through the container: `dev exec -- vendor/bin/pest <path> --compact`, `dev <artisan command>`.
- P-02 layers: ports in `Domain/Ports`; Infrastructure depends only on Domain; controllers pass `(string) $request->user()->getAuthIdentifier()` to Application services; another module is reached only through its `Application` namespace or a Domain enum (`Billing\Domain\Feature`). The verification gate is the middleware alias `address.verified` — never import `Identity\Http`.
- Refusals extend `Shared\Domain\Errors\Refusal` with a catalogue `ErrorCode` (pattern: `Identity\Domain\Refusals\ProfileVersionConflict` carrying `current`). **No new catalogue code**: use `NOT_FOUND`, `FORBIDDEN`, `FEATURE_NOT_ENTITLED`, `VERSION_CONFLICT`, `VALIDATION_FAILED` with field codes `IMMUTABLE_FIELD`, `INVALID_ENUM_VALUE`, `OUT_OF_RANGE`, `REQUIRED`, `UNKNOWN_FIELD`.
- Money: `bigint` minor units validated as integers (`AMOUNT_NOT_INTEGER` on strings/floats); currency through `Shared\Domain\Money\Currency`.
- Instants written with microseconds (`format('Y-m-d H:i:s.uP')`); empty `meta` is `(object) []`.
- Every wallet-scope write: `DB::transaction` → `lockForUpdate` on the wallet row → next `change_seq` from the wallet row (R-3) → write → recompute `balance_minor` when the opening balance changed (R-2) → events after commit.
- Run `dev exec -- vendor/bin/pint --dirty --format agent` after PHP edits.
- Contract tests use `assertValidRequest()` and `assertValidResponse($status)`, covering every documented status.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on unfinished tasks)
- **[Story]**: US1–US5 from spec.md

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: the documents the code depends on, and the two tables.

- [X] T001 [P] Update `docs/glossary.md`: in the Wallet entry's notes add `archived_at` (the `archived` state's instant), **currency locked** (derived, never stored; true once any posting references the wallet; reported as `currency_locked`), and that `change_seq` on the wallet is the scope counter and moves on any write inside it. In the Wallet Member entry add **ownership transfer** (owner hands `owner` to another active member and becomes `editor`; never adds or removes a person) and that the member is exposed as `member_user_id`.
- [X] T002 [P] Amend `docs/security-baseline.md` § What is recorded: the Sharing row's "Written by" becomes **Wallets** (`membership_granted`, `membership_revoked`, `role_changed` — every membership change goes through `WalletService`; spec 010 Clarifications). Add one sentence under the table recording why. No other change.
- [X] T003 [P] Amend `.specify/memory/constitution.md` to **v1.3.3 (PATCH)**: the Wallets & Ledger row's Publishes gains `WalletDeleted`, `WalletOwnershipTransferred`; add a Sync Impact Report entry ("PATCH: the Wallets row lists two events introduced by spec 010; no rule changes"); update header, footer, Constitution Check template version and Last Amended (2026-10-02).
- [X] T004 Create `app/Modules/Wallets/Infrastructure/Database/Migrations/2026_10_02_000001_create_wallets_table.php` per data-model.md: `id uuid PK`; `type varchar(12)` CHECK in (`cash`,`checking`,`savings`,`credit_card`,`loan`,`investment`); `name varchar(100)` CHECK `btrim(name) <> ''`; `currency char(3)` CHECK `~ '^[A-Z]{3}$'`; `opening_balance_minor bigint`; `balance_minor bigint`; `icon varchar(40) NULL` CHECK `~ '^[a-z0-9_]{1,40}$'`; `color char(7) NULL` CHECK `~ '^#[0-9A-F]{6}$'`; `archived_at timestamptz(6) NULL`; `version integer` CHECK ≥ 1; `change_seq bigint` CHECK ≥ 1; `timestampsTz(6)`; `deleted_at timestamptz(6) NULL`. No float/decimal (the migrations arch gate).
- [X] T005 Create `app/Modules/Wallets/Infrastructure/Database/Migrations/2026_10_02_000002_create_wallet_members_table.php`: `id uuid PK`; `wallet_id uuid FK → wallets`; `user_id uuid` (no FK across modules); `role varchar(8)` CHECK in (`owner`,`editor`,`viewer`); `version integer`; `change_seq bigint`; `timestampsTz(6)`; `deleted_at timestamptz(6) NULL`; indexes `UNIQUE (wallet_id, user_id) WHERE deleted_at IS NULL`, `UNIQUE (wallet_id) WHERE role = 'owner' AND deleted_at IS NULL`, `(user_id) WHERE deleted_at IS NULL`, `(wallet_id, change_seq)`.
- [X] T006 [P] Fix `app/Modules/Identity/Infrastructure/Database/Factories/UserFactory.php` so `User::factory()->create()` works **outside** a database transaction (research R-13): the `user_keys` row is inserted while computing `email_ciphertext`, before the `users` row, and `user_keys_user_id_foreign` is `DEFERRABLE INITIALLY DEFERRED`, so it only passes inside `RefreshDatabase`'s wrapping transaction. Make the factory's create path run the key insert and the user insert in one `DB::transaction`. Add `tests/Feature/Identity/UserFactoryOutsideTransactionTest.php` proving creation succeeds with no wrapping transaction (e.g. `DatabaseTruncation`/a test without `RefreshDatabase`, cleaning up after itself). Independent of the rest of B4.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: domain types, ports, persistence, the authorizer and the HTTP skeleton every story uses.

### Tests first

- [X] T007 [P] `tests/Unit/Wallets/PermissionMatrixTest.php`: a dataset that **is** spec 010 §6 (rows W-01…S-05 × owner/editor/viewer) asserts `PermissionMatrix::allows()` for every cell, and `capabilityFor(S02SendOrRevokeInvitation)` is `Feature::SharingEnabled` while every other row has none. A header comment says the dataset must be edited together with spec §6 (not a copy of the rule text: it references row IDs).
- [X] T008 [P] `tests/Unit/Wallets/WalletTypeTest.php` and `tests/Unit/Wallets/WalletRulesTest.php`: the six types, `isLiability()` true only for `credit_card`, `loan`; `WalletRules` refuses any type change (`IMMUTABLE_FIELD` at `/type`), refuses a currency change only when locked (`IMMUTABLE_FIELD` at `/currency`), passes the unchanged currency when locked.

### Implementation

- [X] T009 [P] Domain enums and records in `app/Modules/Wallets/Domain/`: `Wallets/WalletType.php` (`isLiability()`), `Wallets/WalletRecord.php` (readonly, all columns), `Wallets/WalletRules.php`, `Membership/Role.php`, `Membership/MemberRecord.php`, `Membership/Permission.php` (cases `W01ReadWallet`, `W02EditWallet`, `W03Archive`, `W04Delete`, `W05TransferOwnership`, `T01ReadTransactions`, `T02WriteTransactions`, `T03Transfer`, `R01ReadRecurring`, `R02WriteRecurring`, `S01SeeInvitations`, `S02SendOrRevokeInvitation`, `S03ChangeRole`, `S04RemoveMember`, `S05Leave`), `Membership/PermissionMatrix.php` (`allows(Role, Permission): bool`, `capabilityFor(Permission): ?Feature`).
- [X] T010 [P] Refusals in `app/Modules/Wallets/Domain/Refusals/`: `WalletNotFound` (NOT_FOUND), `WalletActionForbidden` (FORBIDDEN), `WalletVersionConflict` (VERSION_CONFLICT, `current`), `WalletFieldImmutable` (VALIDATION_FAILED + `IMMUTABLE_FIELD` at a pointer), `OwnershipTargetInvalid` (VALIDATION_FAILED + `INVALID_ENUM_VALUE` at `/user_id`). Add localised details to `lang/en/problems.php` and `lang/ar/problems.php` (currency locked, type immutable, ownership target, wallet forbidden).
- [X] T011 [P] Ports in `app/Modules/Wallets/Domain/Ports/`: `WalletRepository` (find, findForUpdate, insert, updateIfVersion, listForMember with keyset cursor, countOwnedLive), `MembershipRepository` (activeFor, roleOf, ownerOf, activeMembersOf with cursor, insert, changeRole, tombstoneAllIn), `PostingTotals` (`sumFor(walletId): int`, `hasAny(walletId): bool`), `ScopeSequence` (`nextFor(walletId): int`, called with the wallet row locked).
- [X] T012 Infrastructure in `app/Modules/Wallets/Infrastructure/`: `Models/Wallet.php`, `Models/WalletMember.php` (HasUuids for members only; wallets take the client id), `Repositories/EloquentWalletRepository.php`, `Repositories/EloquentMembershipRepository.php`, `Sync/WalletScopeSequence.php` (`UPDATE wallets SET change_seq = change_seq + 1 … RETURNING change_seq`), `WalletsPersistenceServiceProvider.php` binding the ports; factories `Database/Factories/WalletFactory.php` (states: `archived`, `deleted`, per type) and `WalletMemberFactory.php` (states `owner`, `editor`, `viewer`). Register the provider in `bootstrap/providers.php`; register the migrations path like Billing.
- [X] T013 [P] `app/Modules/Wallets/Application/Provisional/NoPostingsYet.php` implementing `PostingTotals` (0, false), bound in `WalletsServiceProvider` next to `NoTransactionsYet`; PHPDoc "Replaced by B6; delete this directory then".
- [X] T014 `app/Modules/Wallets/Application/MembershipQueryService.php` (`ownerOf`, `roleOf`, `activeMembersOf` returning member DTOs with display names) and `app/Modules/Wallets/Application/Authorization/WalletAuthorizer.php` (`authorize(userId, walletId, Permission): Role`: no active membership → `WalletNotFound`; role denied → `WalletActionForbidden`; capability denied by `EntitlementService::forWallet` → `FeatureNotEntitled` refusal) per research R-6.
- [X] T015 [P] Identity additions: `app/Modules/Identity/Application/Profiles/DisplayNames.php` (`of(list<string>): array<string, ?string>`, opening each sealed name with the member's key; R-8) and `app/Modules/Identity/Application/Audit/Details/RoleChangedDetail.php` (`previous_owner_id`, `new_owner_id`; no amounts, no names).
- [X] T016 HTTP skeleton: `app/Modules/Wallets/Http/WalletsHttpServiceProvider.php` (routes under the `api` group like Billing, console command), `Http/routes.php` — reads under `auth:api`; `PUT|PATCH|DELETE wallets/{id}`, `POST wallets/{id}/archive`, `POST wallets/{id}/transfer-ownership` under `auth:api` **and** `address.verified`; `Http/Resources/WalletResource.php` (every Wallet member of spec §5, with `wallet_id` = `id`, `currency_locked`, `my_role`) and `Http/Resources/WalletMemberResource.php` (`member_user_id`, `display_name`, `role` + sync members). Register in `bootstrap/providers.php`.

**Checkpoint**: migrations run, matrix and rules unit tests pass, routes resolve.

---

## Phase 3: User Story 1 - Create and see my wallets (Priority: P1) 🎯 MVP

**Goal**: a verified person creates wallets offline-first and lists them with balances and their role.

**Independent Test**: create two wallets, replay one create, list; check balance = opening balance, `my_role: owner`, one membership each.

### Tests for User Story 1

- [X] T017 [P] [US1] `tests/Feature/Wallets/CreateWalletTest.php`: creator is the only member with role `owner` in the same transaction (roll back mid-way → neither row exists); `balance_minor` = `opening_balance_minor`; `version` 1; a replay creates nothing new and bumps nothing; `wallets.max` from `EntitlementService` — a plan with `wallets.max: 1` refuses the second live owned wallet (archived counts, tombstoned does not) with `FEATURE_NOT_ENTITLED`; `WalletCreated` dispatched after commit with ids and scalars.
- [X] T018 [P] [US1] `tests/Contract/Wallets/UpsertWalletTest.php` (create half): `201` with the schema; replay `200` with same `version`/`change_seq`; liability opening balance negative accepted; foreign id (another person's wallet, or a profile/session id) → `404`, row untouched; non-v7 id → `422 INVALID_ID`; version > 0 for an unknown id → `404`; `balance_minor`/`change_seq`/`deleted_at` in the body → `422 UNKNOWN_FIELD`; `"125.00"` → `AMOUNT_NOT_INTEGER`; `"egp"` → `INVALID_CURRENCY`; blank or 101-char name → `OUT_OF_RANGE` at `/name`; bad colour/icon → `INVALID_TYPE`; `401`, `406`, `415`, `426`.
- [X] T019 [P] [US1] `tests/Contract/Wallets/ListWalletsTest.php` and `tests/Contract/Wallets/GetWalletTest.php`: only the caller's live wallets (archived included, deleted excluded); `my_role` per wallet; `sort=name`, `sort=-created_at`; `filter[type]=cash` → `400 FILTER_FIELD_NOT_SUPPORTED`; `sort=balance_minor` → `400 SORT_FIELD_NOT_SUPPORTED`; paging with `limit=1` and `next_cursor`; empty list `200 data: []`; with 50 wallets the list runs a constant number of queries independent of the wallet count and never sums postings (SC-006); single read `200`, non-member `404`, malformed id `422`.
- [X] T020 [P] [US1] `tests/Contract/Wallets/VerificationGateTest.php`: an unverified person gets `403 EMAIL_NOT_VERIFIED` on every write route (PUT, PATCH, DELETE, archive, transfer) and `200` on `GET /wallets`, `GET /wallets/{id}`, `GET …/members` for a wallet they belong to (seeded through the factory).

### Implementation for User Story 1

- [X] T021 [US1] `app/Modules/Wallets/Application/WalletService.php` — `save(actorId, walletId, values, version, Closure $present)` create path: validate id ownership (any existing record of another scope or type → `WalletNotFound`), `wallets.max` via `EntitlementService::forUser(actor)->quota(Feature::WalletsMax)` (null = unlimited; count non-tombstoned owned wallets), in one transaction: insert the wallet with `version` 1, `change_seq` 1 and `balance_minor` = opening + `PostingTotals::sumFor` (= opening), lock it, insert the owner membership with `ScopeSequence::nextFor` (2), then stamp the wallet with the final value (3) — research R-3; identical replay returns current; `WalletCreated` after commit (`Domain/Events/WalletCreated.php`: event id, wallet id, owner user id, type, currency).
- [X] T022 [US1] `app/Modules/Wallets/Application/WalletQueryService.php`: `listFor(userId, cursor, limit, sort)` (keyset on sort key + id; `name` in byte order — use `COLLATE "C"`), `find(userId, walletId)` (live for members; tombstone for members tombstoned by the deletion; else `WalletNotFound`), each returning the record plus `my_role` and `currency_locked` (`PostingTotals::hasAny`).
- [X] T023 [US1] `app/Modules/Wallets/Http/Requests/SaveWalletRequest.php` (extends `App\Http\Api\Requests\ApiRequest`; all six members required — `name` must be present, and a name blank after trimming or longer than 100 is `OUT_OF_RANGE` at `/name`, not `REQUIRED`, so check it before the empty-string-to-null conversion applies; `icon`/`color` nullable with the patterns, `version` integer ≥ 0 optional; unknown members refused) and `Http/Controllers/WalletController.php` `index`, `show`, `upsert` (201 on create, 200 otherwise); collection `meta` per `CollectionMeta`, single `meta` `(object) []`.

**Checkpoint**: US1 tests green; quickstart steps 1–3, 7, 8 pass.

---

## Phase 4: User Story 2 - Edit a wallet safely (Priority: P1)

**Goal**: owner edits with optimistic concurrency, immutable type, locked currency, and a balance that follows the opening balance.

**Independent Test**: edit with the current version, then with a stale one; change the opening balance and read the balance.

### Tests for User Story 2

- [X] T024 [P] [US2] `tests/Feature/Wallets/SaveWalletTest.php`: stale version → `WalletVersionConflict` whose `current` equals a read's rendering, nothing written; identical write → no `version`/`change_seq` bump; a negative resulting balance is accepted; editing a tombstoned wallet (former member) → conflict carrying the tombstone, never a restore; currency change refused when `PostingTotals::hasAny` is bound true (test double), allowed when false.
- [X] T025 [P] [US2] `tests/Feature/Wallets/BalanceCacheTest.php`: an opening-balance change moves `balance_minor` by the same amount in the same transaction (roll back → both unchanged); the recompute reads `PostingTotals::sumFor` (bound to a fake returning 700 → `balance_minor` = opening + 700) and never the stored cache; two concurrent saves serialise on the row lock (second gets `VERSION_CONFLICT`).
- [X] T026 [P] [US2] `tests/Feature/Wallets/ChangeSequenceTest.php`: every record a write touches takes its own next value of that wallet's counter, and the wallet record then takes the final one — after any write the wallet holds the scope's highest `change_seq` and **no two records in a wallet share a value** (create, transfer and delete cascade included); a membership write moves the wallet's `change_seq` but not its `version`; a replay moves neither; two wallets count independently.
- [X] T027 [P] [US2] Update `tests/Contract/Wallets/UpsertWalletTest.php` (update half) and add `tests/Contract/Wallets/PatchWalletTest.php`: `200` update; `409` with `current` (schema-valid, incl. tombstone); `422 IMMUTABLE_FIELD` at `/type` and `/currency` (locked via a bound fake); editor and viewer `403 FORBIDDEN`; non-member `404`; PATCH partial update leaves absent members untouched; PATCH with only `version` → `422 REQUIRED` at `""`; PATCH with `type` → `UNKNOWN_FIELD`; PATCH on unknown id → `404`.

### Implementation for User Story 2

- [X] T028 [US2] Extend `WalletService::save` update path per research R-4 (authorize `W02EditWallet` → replay → conditional `UPDATE … WHERE version = ?` under the lock → `WalletRules` → recompute balance → `version + 1`, new `change_seq`); add `patch(actorId, walletId, partialValues, version, $present)` overlaying sent members on the stored wallet and delegating to the same path (never creates).
- [X] T029 [US2] `Http/Requests/PatchWalletRequest.php` (≥ 1 editable member besides `version`, `version` ≥ 1; `type` and server-owned members `UNKNOWN_FIELD`; empty patch → `REQUIRED` at pointer `""`) and `WalletController::patch`; make `upsert` route existing ids through the update path.

**Checkpoint**: US2 tests green; quickstart steps 4–6 pass.

---

## Phase 5: User Story 5 - One rulebook for every role (Priority: P1)

**Goal**: the matrix is enforced uniformly and entitlements resolve through the wallet's owner.

**Independent Test**: drive every matrix row through `WalletAuthorizer` as owner, editor, viewer and non-member.

### Tests for User Story 5

- [X] T030 [P] [US5] `tests/Feature/Wallets/PermissionMatrixTest.php`: for every `Permission` case and each role plus a non-member and a member tombstoned with a deleted wallet, `WalletAuthorizer::authorize` allows / throws `WalletActionForbidden` / throws `WalletNotFound` exactly as `PermissionMatrix` says; for `S02SendOrRevokeInvitation`, an owner on the free plan gets `FEATURE_NOT_ENTITLED` and on Plus is allowed — resolved through the **owner's** subscription even when the caller is another member.
- [X] T031 [P] [US5] `tests/Feature/Billing/EntitlementsForWalletTest.php`: `EntitlementService::forWallet($walletId)` equals `forUser($ownerId)`; after an ownership transfer it follows the new owner on the next request; an unknown or tombstoned wallet throws (never silently answers the free plan).
- [X] T032 [P] [US5] `tests/Arch/BalanceCacheTest.php`: a file scan of `app/` asserting `balance_minor` and `balanceMinor` appear only in `app/Modules/Wallets/Infrastructure/**`, `app/Modules/Wallets/Domain/Wallets/WalletRecord.php`, `app/Modules/Wallets/Application/WalletQueryService.php` (passing it to the read) and `app/Modules/Wallets/Http/Resources/WalletResource.php` — never in `WalletService`, `WalletAuthorizer` or any other module (P-05 rule 3; the message names P-05).

### Implementation for User Story 5

- [X] T033 [US5] Add `forWallet(string $walletId): Entitlements` to `app/Modules/Billing/Application/Entitlements/EntitlementService.php`, resolving `Wallets\Application\MembershipQueryService::ownerOf()` (lazy resolution, research R-7) and delegating to `forUser`; remove the "arrives with Wallets (B4)" note; throw for a wallet with no live owner.

**Checkpoint**: the matrix is proven row by row; later specs call `WalletAuthorizer` with their row's `Permission`.

---

## Phase 6: User Story 3 - Archive and delete (Priority: P2)

**Goal**: owner archives (one-way) or deletes (final, cascading tombstones).

**Independent Test**: archive and read back; delete and check tombstones on the wallet and its memberships.

### Tests for User Story 3

- [X] T034 [P] [US3] `tests/Feature/Wallets/DeleteWalletTest.php`: delete tombstones the wallet and every membership in one transaction (each with `version + 1` and a fresh `change_seq`), never removes rows; a registered `WalletDeletionParticipant` fake is called inside the same transaction (its exception rolls everything back); a repeat delete changes nothing; `WalletDeleted` after commit; the wallet disappears from every member's list; a former member reads the tombstone, a non-member gets `WalletNotFound`.
- [X] T035 [P] [US3] `tests/Contract/Wallets/ArchiveWalletTest.php` and `tests/Contract/Wallets/DeleteWalletTest.php`: `200` with `archived_at`, repeat `200` unchanged, balance and members untouched; delete `200` tombstone, repeat `200`; stale version `409` with `current`; archive of a tombstone `409`; missing `version` or no body at all → `422 REQUIRED` at `/version`; a non-JSON `Content-Type` → `415`; editor/viewer `403`; non-member `404`; an identical PUT/PATCH/archive on a tombstone → `409` (never `200`).

### Implementation for User Story 3

- [X] T036 [US3] `WalletService::archive(actorId, walletId, version, $present)` (authorize `W03Archive`; already archived → current; version check; set `archived_at`, `version + 1`, new `change_seq`) and `WalletService::delete(actorId, walletId, version, $present)` per research R-9 (authorize `W04Delete`; tombstone → return it; version check; tombstone memberships via `MembershipRepository::tombstoneAllIn`, then every tagged `app/Modules/Wallets/Application/Deletion/WalletDeletionParticipant.php`, then the wallet; `Domain/Events/WalletDeleted.php` after commit).
- [X] T037 [US3] `Http/Requests/VersionRequest.php` (`version` integer ≥ 1, required, nothing else) and `WalletController::archive`, `WalletController::destroy` (JSON body on DELETE).

**Checkpoint**: US3 tests green; quickstart steps 12–13 pass.

---

## Phase 7: User Story 4 - See who is in a wallet, and hand it over (Priority: P2)

**Goal**: members list with display names; safe ownership transfer with one audit entry.

**Independent Test**: list members as the owner; seed a second member and transfer.

### Tests for User Story 4

- [X] T038 [P] [US4] `tests/Feature/Wallets/OwnershipTransferTest.php`: target becomes `owner`, actor becomes `editor`, exactly one active owner after commit and never two (the partial unique index would reject the reverse order); wallet `version + 1`; both memberships get new `version` and `change_seq`; exactly one `role_changed` audit entry (actor user, subject wallet, detail ids only, no amounts or names); `WalletOwnershipTransferred` after commit; target = self / non-member / tombstoned member → `OwnershipTargetInvalid`, nothing changed; target already owner → no change, no audit entry.
- [X] T039 [P] [US4] `tests/Contract/Wallets/ListMembersTest.php` and `tests/Contract/Wallets/TransferOwnershipTest.php`: members `200` with `member_user_id`, `display_name` (null when unset), `role`, ordered by creation, paging; no `email` or other attribute anywhere in the body; non-member `404`; transfer `200` with `my_role: editor`; `422 INVALID_ENUM_VALUE` at `/user_id`; `409` stale version; editor/viewer `403`; non-member `404`.

### Implementation for User Story 4

- [X] T040 [US4] `WalletService::transferOwnership(actorId, walletId, targetUserId, version, $present)` per research R-10 (authorize `W05TransferOwnership`; already owner → current; version check; validate target; demote actor then promote target; wallet `version + 1`; one `AuditLog::record` with `AuditEvent::RoleChanged`, actor type user, subject type wallet, `RoleChangedDetail`; `Domain/Events/WalletOwnershipTransferred.php` after commit).
- [X] T041 [US4] `Http/Requests/TransferOwnershipRequest.php` (`user_id` uuid, `version` ≥ 1) and `Http/Controllers/WalletMembersController.php` (`index` via `MembershipQueryService::activeMembersOf` + `DisplayNames`), `WalletController::transferOwnership`.

**Checkpoint**: US4 tests green; quickstart steps 9–11 pass.

---

## Phase 8: Polish & Cross-Cutting Concerns

- [X] T042 `app/Modules/Wallets/Application/Balances/VerifyBalances.php` and `app/Modules/Wallets/Http/Console/VerifyBalances.php` (`wallets:verify-balances`): per live wallet, under its row lock, recompute opening + `PostingTotals::sumFor`; on drift log `wallet_balance_drift` at `warning` with the wallet id only (no amount) and repair the column. Schedule `->daily()` in `routes/console.php` with a comment citing ADR-0005 rule 6. Test in `tests/Feature/Wallets/VerifyBalancesTest.php` (drift detected, logged, repaired; no drift → no log).
- [X] T043 [P] Apply the endpoint review checklist (`docs/api-conventions.md` appendix) and the security review checklist (C1–C2, D1, E1–E5) to every operation in `docs/openapi/paths/wallets.yaml`; record any justified "no" in plan.md. Confirm the contract still matches the implementation exactly — any change to a member the C4 client binds to (contracts/wallets-api.md table) is a contract change and must be raised, not made.
- [X] T044 [P] Update `docs/openapi/paths/billing.yaml` / Billing PHPDoc only if `forWallet` changed a documented behaviour (expected: none); confirm `ErrorCodeParityTest`, `SkeletonTest` and `TemplateConformanceTest` stay green.
- [X] T045 Run `dev exec -- composer test` (Pint, Larastan level 8, arch, suite) and the quickstart manual walk; state "Seeder: N/A — no reference data" in the commit/PR description.

---

## Dependencies & Execution Order

- **Setup (T001–T006)**: T001–T003 and T006 are independent docs/fixes; T004 → T005.
- **Foundational (T007–T016)**: depends on T004–T005. Blocks every story.
- **US1 (T017–T023)** → **US2 (T024–T029)** (update path extends the create path in the same service).
- **US5 (T030–T033)**: depends on Foundational and US1 (needs wallets to exist); independent of US2.
- **US3 (T034–T037)** and **US4 (T038–T041)**: depend on US1; independent of each other except both edit `WalletService.php` and `WalletController.php` (serialise those edits).
- **Polish (T042–T045)**: after all stories.

## Parallel Execution Examples

- Setup: T001, T002, T003, T006 together.
- Foundational tests and types: T007, T008, T009, T010, T011, T013, T015 together.
- US1 tests: T017, T018, T019, T020 together, then T021 → T022 → T023.
- After US1: US5 (T030–T033) alongside US2 tests (T024–T027).

## Implementation Strategy

1. **MVP** = Setup + Foundational + US1: a person can create and list wallets, and the contract the C4 client builds against is live for those operations.
2. Add US2 (edits and conflicts) and US5 (matrix proven, `forWallet`): this is everything B6 needs.
3. Add US3 and US4; then Polish. Each phase ends at a green checkpoint and can be committed on its own on `main`.
