# Data Model: Categories

**Feature**: [spec.md](./spec.md) · **Research**: [research.md](./research.md)

Owned by `app/Modules/Wallets`. Migrations live in `app/Modules/Wallets/Infrastructure/Database/Migrations`. PostgreSQL 16, UUIDv7 keys (P-06), UTC `timestamptz(6)` (P-12). No money column.

## `categories`: Category (a person's — syncable, user scope; or the platform's — reference data)

| Column | Type | Rules |
|---|---|---|
| `id` | uuid PK | person's: client-generated UUIDv7; platform: UUIDv7 generated once by the seeder, never changed |
| `user_id` | uuid null | the owner (no FK across modules); **null exactly for platform rows** |
| `system_key` | varchar(40) null, unique | `^[a-z][a-z0-9_]{1,39}$`; **set exactly for platform rows**; never renamed or reused |
| `kind` | varchar(7) + CHECK | `income` \| `expense`; never updated after insert |
| `name` | varchar(50) null | person's rows: trimmed, non-blank (CHECK `btrim(name) <> ''`); null on platform rows |
| `names` | jsonb null | platform rows: `{"ar": "…", "en": "…"}`, both non-blank; null on person's rows |
| `icon` | varchar(40) null | `^[a-z0-9_]{1,40}$`; required on platform rows |
| `color` | char(7) null | `^#[0-9A-F]{6}$`; required on platform rows |
| `position` | smallint null | platform rows only: the fixed order, unique among platform rows |
| `parent_id` | uuid null | reserved for nesting (O2); CHECK `parent_id IS NULL` in MVP |
| `archived_at` | timestamptz(6) null | person's: archived (set once); platform: retired |
| `version` | integer ≥ 1 | person's: +1 on each change to name, icon, colour, archive, delete; platform: +1 when the seeder changes the row |
| `change_seq` | bigint null | person's: from `sync_change_seq` (R-5), NOT NULL on person's rows; null on platform rows |
| `created_at`, `updated_at` | timestamptz(6) | |
| `deleted_at` | timestamptz(6) null | person's: tombstone (only while unused); platform rows: CHECK null — never deleted |

**Shape CHECKs**:

- person's row: `user_id IS NOT NULL AND system_key IS NULL AND name IS NOT NULL AND names IS NULL AND position IS NULL AND change_seq IS NOT NULL`;
- platform row: `user_id IS NULL AND system_key IS NOT NULL AND name IS NULL AND names IS NOT NULL AND icon IS NOT NULL AND color IS NOT NULL AND position IS NOT NULL AND change_seq IS NULL AND deleted_at IS NULL`.

**Indexes**: `(user_id, created_at, id) WHERE deleted_at IS NULL` and `(user_id, name, id) WHERE deleted_at IS NULL` for the two list sorts; unique `system_key`; unique `position WHERE user_id IS NULL`.

**Lifecycle**:

- person's: `active` → `archived` (sets `archived_at`); `active` | `archived` → `tombstoned` (sets `deleted_at`) **only while not in use**. No way back in this feature.
- platform: `active` ⇄ `retired` (seeder only); never tombstoned.

## `category_references`: Category Reference (syncable, wallet scope, read-only)

| Column | Type | Rules |
|---|---|---|
| `id` | uuid PK | server-generated UUIDv7 |
| `wallet_id` | uuid FK → `wallets` | the scope key |
| `category_id` | uuid FK → `categories` | always a **person's** category (a platform category never gets one; enforced in the publisher and by test) |
| `owner_user_id` | uuid | the category's owner, copied from `categories.user_id` |
| `kind` | varchar(7) | copied |
| `name` | varchar(50) | copied |
| `icon` | varchar(40) null | copied |
| `color` | char(7) null | copied |
| `version` | integer ≥ 1 | +1 on each republish, withdrawal, revival |
| `change_seq` | bigint | a unique value from the wallet's counter (spec 010 R-3), below the wallet's own final stamp |
| `created_at`, `updated_at` | timestamptz(6) | |
| `deleted_at` | timestamptz(6) null | withdrawn, category deleted, or wallet deleted |

**Indexes**: unique `(wallet_id, category_id) WHERE deleted_at IS NULL` (one live reference per pair); `(wallet_id, created_at, id) WHERE deleted_at IS NULL` for the read; `(category_id) WHERE deleted_at IS NULL` for republication.

**Lifecycle**: live → tombstoned. A later publish of the same pair revives the existing row (clears `deleted_at`, version + 1, new `change_seq`) rather than inserting a second row, so a client sees one identifier per (wallet, category).

## Domain types (`app/Modules/Wallets/Domain/Categories`)

- `CategoryKind` (enum `income`, `expense`).
- `CategoryRecord` (readonly): id, ownerId, kind, name, icon, color, archivedAt, version, changeSeq, createdAt, updatedAt, deletedAt.
- `PlatformCategoryRecord` (readonly): id, systemKey, kind, names (`ar`, `en`), icon, color, position, archivedAt, updatedAt.
- `CategoryReferenceRecord` (readonly): the reference columns.
- `CategoryRules`: `assertKindUnchanged`, `isIdenticalTo` (trimmed name), name length 1–50 after trim.
- `SystemKey` value object (shape rule).
- Refusals: `CategoryNotFound`, `CategoryVersionConflict` (carries `current`), `CategoryFieldImmutable` (`/kind`), `CategoryInUse`.
- Ports: `CategoryRepository`, `CategoryReferenceRepository`, `UserScopeSequence`.

## Relationships

- `categories.user_id` → Identity's users (no FK across modules, as `wallet_members.user_id`).
- `category_references.wallet_id` → `wallets.id`; `category_references.category_id` → `categories.id`.
- B6 `postings.category_id` → `categories.id` (either owner; ADR-0014).
- B8 budgets → `categories.id` (either owner; B8 decides scope rules).

## Protected attributes

Category names: "Wallet and category names — none at rest" (baseline, unchanged). Platform category names: new row, none at rest, readable by every signed-in person (R-11).
