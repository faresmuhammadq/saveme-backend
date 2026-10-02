# Implementation Plan: Categories

**Branch**: `011-categories` (trunk-based; work lands on `main`) | **Date**: 2026-10-02 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/011-categories/spec.md`.

## Summary

Add categories to the Wallets & Ledger module (`app/Modules/Wallets`).

- **Two owners, one table** (R-1, ADR-0014): `categories` holds each person's categories (user-scoped, syncable) and the platform-owned default set (reference data with a stable `system_key`, both names, icon, colour, position). CHECK constraints keep the two shapes apart; `parent_id` is reserved and always null (O2: flat).
- **Platform set**: 26 entries (spec §3), seeded by `WalletsSeeder` → `PlatformCategorySeeder`, idempotent on `system_key`, ids kept, removed keys retired (R-4). Read through `GET /platform-categories`, both languages always present (R-3). Using one means referencing it directly (R-2).
- **A person's categories**: `PUT`/`PATCH`/archive/`DELETE` through `CategoryService`, with spec 010's version, replay, tombstone and precedence rules (R-6). `kind` immutable. Delete refused with `409 CATEGORY_IN_USE` while any usage participant says the category is used (R-7); none exists until B6.
- **Category Reference** (R-8, R-9): stored projection in `category_references`, one live row per (wallet, person's category). `CategoryReferences` offers `publish`/`withdrawIfUnused` to B6/B7, republishes in the same transaction when a category's display fields change, tombstones on category deletion and through a `WalletDeletionParticipant` on wallet deletion. Fixed lock order: category before wallets, wallets ascending. Read at `GET /wallets/{id}/category-references` (row T-01).
- **API**: eight operations in `docs/openapi/paths/categories.yaml` (written and indexed in this design phase; `info.version` v1.4), frozen for the C5 client. One new code, `CATEGORY_IN_USE`, catalogued in the implementation (T004) together with the runtime enum and its strings.
- **Docs in this change**: ADR-0014 (written in design), glossary Category and Category Reference entries, security baseline (gate row, platform names row), constitution PATCH v1.3.4 (P-04 ADR line).

## Technical Context

**Language/Version**: PHP 8.5, Laravel 13.

**Primary Dependencies**: none new.

**Storage**: PostgreSQL 16; two tables (`categories`, `category_references`); jsonb for platform names; partial unique index for one live reference per (wallet, category).

**Testing**: Pest.
- Unit: `CategoryRules`, `CategoryKind`, `SystemKey`, seeder definition validation.
- Feature: save/patch/archive/delete, usage participants, ownership CHECKs, platform seeder, reference publish/republish/withdraw/cascade, lock order under concurrency.
- Contract: the eight operations with every documented status (Spectator), the verification gate, the `405`s.
- Arch: existing P-02 gates; no new gate.

**Target Platform**: the Linux server (the `dev` container).

**Project Type**: modular-monolith JSON API.

**Performance Goals**: `GET /categories` p95 under 100 ms for 200 categories at `limit=200` (one indexed scan; `in_use` answered set-wise, constant until B6); `GET /platform-categories` one query over 26 rows; a rename republishes to at most the person's wallets (sizing: ≤ 50), each under a short wallet lock.

**Constraints**: P-06 (client UUIDv7, foreign id → 404), P-07 (version, tombstones, no merge), P-08 (non-member → 404 on references; matrix untouched), P-11 (no events), P-14 (platform names in ar and en as data), P-15 (no names logged).

**Scale/Scope**:
- 8 operations, 2 migrations, 1 seeder (platform set, en/ar);
- about 30 Wallets classes, no change to other modules' code;
- 18 test files;
- doc edits: glossary, security baseline, constitution PATCH, error catalogue (+1 code), `responses.yaml` Conflict description.

## Constitution Check

*Constitution v1.3.3 (`.specify/memory/constitution.md`). GATE: must pass before Phase 0 research. Re-check after Phase 1 design, keeping both results.*

### Pre-design

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | "Category", "Category Reference", "Wallet" only. New terms (platform category, `system_key`, retired, in use) are added to the glossary's Category entry in this change (T001) before code uses them |
| P-02 Module boundaries | PASS | All in `app/Modules/Wallets` (context Wallets & Ledger, whose constitution row already lists categories). No other module's code changes; Wallets keeps its own user-scope sequence adapter instead of importing Identity's (R-5). Later modules reach "in use" through tagged `CategoryUsage` participants in Wallets' Application layer |
| P-03 Money | N-A | No money column or amount |
| P-04 Ledger integrity | PASS | No posting written. ADR-0014 keeps ADR-0012's single category leg and zero-sum rules unchanged and adds "a platform category's total is always scoped"; deletion is blocked while used, never a cascade (ADR-0012 consequences) |
| P-05 Derived balances | N-A | No balance touched; a reference write moves the wallet's `change_seq` only, never `balance_minor` |
| P-06 Identifiers | PASS | `PUT /categories/{id}` upsert with client UUIDv7; another person's, a platform or another kind's id → 404 without write; references and platform rows get server UUIDv7 |
| P-07 Sync and concurrency | PASS | Categories and references carry the five sync members; `version` in every write body; 409 with `current` incl. tombstone; no merge; delete and withdrawal are tombstones. Platform rows are reference data, not syncable (read endpoint, like plans) |
| P-08 Authorization | PASS | Categories are user-scoped (owner only, others 404). References read under spec 010 row T-01 through `WalletAuthorizer`; the matrix is referenced, not restated; no new row |
| P-09 Entitlements | PASS | No capability or quota gates categories (rule 5: free limited only on sharing); no plan code |
| P-10 Billing channels | N-A | No billing surface |
| P-11 Events | PASS | No events; republication and cascade run inside the unit of work (R-8), usage through participants (R-7) |
| P-12 Time | PASS | `archived_at` and sync instants UTC `timestamptz(6)`; no calendar dates |
| P-13 Naming | PASS | No codename anywhere |
| P-14 Localisation | PASS | Platform names in ar and en as seeded data (R-4); problem strings for the new code in `lang/{ar,en}/problems.php`; a person's names untouched |
| P-15 Observability | PASS | No category name or user data logged; no new log line |
| P-16 Quality gates | PASS | Every spec §4 invariant names its test; PostgreSQL only (jsonb, partial indexes) |
| P-17 Scope guard | PASS | No nesting, no per-person hide list, no FX |
| P-18 Delivery surfaces | PASS | Every capability is a `/api/v1` endpoint; no back-office screen (platform set changes through the seeder) |

### Post-design re-check

Unchanged: all rows PASS or N-A.

- **CONTRIBUTING item 18**: ADR-0014 extends ADR-0012 (a new owner kind, scoped totals, lock order) without changing any of its rules; written in this design phase, indexed in `docs/adr/README.md`. The constitution's P-04 (and P-01) `ADRs:` line gains ADR-0014 (PATCH v1.3.4, T003).
- The security baseline's verification gate gains a row for category writes, and its protected-attributes table a row for platform category names (R-11). Each is a yes/no rule per endpoint.
- One new error code (`CATEGORY_IN_USE`, since v1.4); `info.version` v1.4 already set for the new paths.

Complexity Tracking stays empty.

## Assumed defaults (owner away)

| # | Decision | Why |
|---|---|---|
| A-1 | Using a platform category = referencing it directly; never copied | One row per key; names follow language; no offline duplicates (R-2, ADR-0014 rule 3) |
| A-2 | Nobody archives or hides a platform category through the API; the platform retires entries via the seeder | Not the person's record; hide list additive later |
| A-3 | `kind` immutable (`IMMUTABLE_FIELD` at `/kind`) | Fixes the ledger side; mirrors wallet `type` |
| A-4 | Flat categories; `parent_id` reserved, not on the wire | O2; C5 asks for no nesting |
| A-5 | Archive one-way; delete final and only while unused; no `deleted_at` in the write body | Wallet precedent; glossary |
| A-6 | New code `CATEGORY_IN_USE` (409, `never`) | No existing code means "archive instead" |
| A-7 | `in_use` exposed; defined over live and tombstoned referencing records, answered by participants from B6–B8 | A restorable transaction must not point at a deleted category |
| A-8 | Platform names as `names {ar, en}` in every response | Offline language switch (C5) |
| A-9 | Name 1–50 after trim; duplicates allowed | Picker chips; offline creates must both succeed |
| A-10 | Every category write behind `address.verified`; baseline amended | Names reach partners via references |
| A-11 | Category Reference built now: storage, publisher, republish in the same transaction, wallet-deletion cascade, read endpoint | Category edits are B5's; no stale partner labels |
| A-12 | Lock order: category rows before wallet rows, wallets ascending | Removes the rename vs. transaction deadlock |
| A-13 | References only for persons' categories; one live row per (wallet, category), revived rather than duplicated | Platform set is already on every client |
| A-14 | Reference list: live only, `created_at` order, no filters/sorts; 404 after wallet deletion | The feed (B14) carries tombstones |
| A-15 | User-scope `change_seq` from `sync_change_seq` via a Wallets adapter | Spec 007 R-19; P-02 |
| A-16 | No events, no entitlement/quota, no rate limit | Nothing listens; P-09 rule 5 |
| A-17 | Sizing: 200 categories per person, one page at `limit=200` | Generous for a personal set |
| A-18 | `CATEGORY_IN_USE` catalogued at implementation time, with the enum and strings | Catalogue parity test needs all three in one change; the code is frozen in spec §5 meanwhile |

## Review checklists (applied at T039)

- **Endpoint review checklist** (`docs/api-conventions.md` appendix): expected exceptions — C8 applies to `listCategoryReferences` and `listPlatformCategories` (read-only resources; `405` on writes); G3, G4 do not apply (no money operation, no `Idempotency-Key`); G5 does not apply (no throttle, `429` not documented).
- **Security review checklist**: C1 every category write behind the gate; C2 reads are not; D1 category names already in the protected-attributes table, platform names added; E1–E5 no name or body logged, refusals carry no other person's data (a partner's category id in a reference is disclosed only to members of a wallet where it was used, which T-01 permits).

### Result of applying both checklists (T039)

Both checklists were applied to each of the eight operations in `docs/openapi/paths/categories.yaml`, which the implementation matches member for member (no field name, code or status changed).

- **Endpoint review checklist**: every line is "yes" except the expected exceptions above (C8 applies only to the two read-only resources; G3-G5 do not apply) and one more:
  - I2 ("adds nothing to `openapi.yaml` beyond one `paths` entry") is "no" for the feature as a whole, not for any operation: the feature adds five path entries (`/categories`, `/categories/{id}`, `/categories/{id}/archive`, `/platform-categories`, `/wallets/{id}/category-references`), one per distinct path, all in the design phase and frozen. Each operation adds nothing else.
  - E1-E3 on `listPlatformCategories`: the path takes no `cursor` or `limit` (the whole set is one page, `next_cursor: null`, `limit` is the row count); `x-filterable: []` and `x-sortable: []` are present.
- **Security review checklist**: C1 yes (every write route sits behind `address.verified`, proved by `CategoryVerificationGateTest`); C2 yes; D1 yes (platform names row added to the protected-attributes table); E1-E6 yes (no new log line; the 404 for another person's, a platform or a wallet's id is identical).
- **Contract note**: the implementation changed nothing the C5 client binds to (the table in `contracts/categories-api.md`).

## Project Structure

### Documentation (this feature)

```text
specs/011-categories/
├── spec.md
├── plan.md                 # this file
├── research.md             # R-1 … R-14
├── data-model.md
├── quickstart.md
├── contracts/categories-api.md
├── checklists/requirements.md, checklists/contract.md
└── tasks.md                # /speckit-tasks
docs/adr/0014-platform-owned-categories.md          # written in design
docs/openapi/paths/categories.yaml, openapi.yaml    # written and indexed in design (v1.4)
```

### Source Code (repository root)

```text
app/Modules/Wallets/
├── Domain/Categories/
│   ├── {CategoryKind,CategoryRecord,PlatformCategoryRecord,CategoryReferenceRecord,CategoryRules,SystemKey}.php
│   ├── Refusals/{CategoryNotFound,CategoryVersionConflict,CategoryFieldImmutable,CategoryInUse}.php
│   └── Ports/{CategoryRepository,CategoryReferenceRepository,UserScopeSequence}.php
├── Application/Categories/
│   ├── CategoryService.php                 # save, patch, archive, delete
│   ├── CategoryQueryService.php            # listFor, find (with in_use)
│   ├── PlatformCategoryQueryService.php    # all
│   ├── CategoryReferences.php              # publish, withdrawIfUnused, republish, withdrawAll, listFor
│   ├── CategoryUsage.php                   # participant interface (tagged)
│   ├── CategoryUsageQuery.php              # any participant says used
│   ├── ReferencesGoWithWallet.php          # WalletDeletionParticipant
│   └── Dto/{CategoryView,SavedCategory}.php
├── Infrastructure/
│   ├── Models/{Category,CategoryReference}.php
│   ├── Repositories/{EloquentCategoryRepository,EloquentCategoryReferenceRepository}.php
│   ├── Sync/UserScopeSequence.php          # sync_change_seq
│   └── Database/
│       ├── Migrations/2026_10_02_000003_create_categories_table.php, …000004_create_category_references_table.php
│       ├── Seeders/{WalletsSeeder,PlatformCategorySeeder}.php
│       └── Factories/{CategoryFactory,CategoryReferenceFactory}.php
└── Http/
    ├── routes.php                          # + category routes; writes behind address.verified
    ├── Controllers/{CategoryController,PlatformCategoryController,CategoryReferenceController}.php
    ├── Requests/{SaveCategoryRequest,PatchCategoryRequest,CategoryVersionRequest}.php
    └── Resources/{CategoryResource,PlatformCategoryResource,CategoryReferenceResource}.php

changed: app/Modules/Shared/Domain/Errors/ErrorCode.php (+CATEGORY_IN_USE), lang/{ar,en}/problems.php,
         Wallets{ServiceProvider,PersistenceServiceProvider} bindings and tags
docs/openapi/error-catalog.yaml, components/responses.yaml (Conflict codes), paths/categories.yaml (delete text)
docs/glossary.md, docs/security-baseline.md, .specify/memory/constitution.md (v1.3.4)

tests/Unit/Wallets/Categories/{CategoryRules,SystemKey,PlatformCategoryDefinitions}Test.php
tests/Feature/Wallets/Categories/{SaveCategory,DeleteCategory,CategoryOwnershipConstraint,PlatformCategorySeeder,CategoryReferences,CategoryReferenceLockOrder}Test.php
tests/Contract/Wallets/Categories/{ListCategories,GetCategory,UpsertCategory,PatchCategory,ArchiveCategory,DeleteCategory,PlatformCategories,ListCategoryReferences,CategoryVerificationGate}Test.php
```

**Structure Decision**: categories live in the Wallets module (constitution: Wallets & Ledger owns categories) under a `Categories` sub-namespace in each layer, beside spec 010's `Wallets` and `Membership`. Wallets gains its first seeder, discovered by `DatabaseSeeder`'s module loop.

## Complexity Tracking

No Constitution Check violations; nothing to justify.
