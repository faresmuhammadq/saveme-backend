# Feature Specification: Categories

**Feature Branch**: `011-categories`

**Created**: 2026-10-02

**Status**: Draft

**Input**: Brief B5 ([specs/briefs/B05-categories.md](../briefs/B05-categories.md)): "Categories have kind (income/expense), name, icon, colour, archived flag […]. Nesting: one level (parent/child) if included, otherwise flat — resolve open decision O2 here. Seeded defaults in ar and en with stable `system_key` so reports can group across wallets. Archiving, not deleting, once a category has transactions. Category is syncable." Plus the brief's owner decisions of 2026-09-25 (user-scoped categories **plus** a platform-owned default set), its reconcile table, and the paired client brief [C5](../../../saveme-kmp/specs/briefs/C05-categories.md) "Needs from the backend".

> **Nature of this feature.** B5 gives every person the buckets they file money under. Each person keeps their own categories (create, rename, re-icon, archive, delete while unused) and, beside them, sees one platform-owned default set — localised in Arabic and English, identified by a stable `system_key`, usable by everyone and editable by nobody. It also builds the read-only **Category Reference** a shared wallet carries, so a member can render a transaction their partner filed under the partner's own category. It records no transactions (B6), budgets (B8) or reports (B13), and runs no sync feed (B14): it fixes the contract those features consume.
>
> **Where the brief and the ratified decisions differ, the ratified side wins** (owner decisions 2026-09-25, glossary, ADR-0012, spec 010):
>
> | Brief | In force | Here |
> |---|---|---|
> | Categories belong to a wallet (recommended), the seeder copying the locale's default set into each new wallet | **User-scoped** categories that ride the user feed, plus a **platform-owned** default set (owner decision 2026-09-25; glossary; ADR-0012). A shared wallet renders a partner's category through the read-only Category Reference | User-scoped categories; one platform set, seeded once, never copied |
> | Endpoints under `/wallets/{id}/categories` | Categories live at the user level | `/categories/…`, `/platform-categories`; the wallet path carries only the read-only `/wallets/{id}/category-references` |
> | Editors manage categories "in that wallet" (B4 brief) | The permission matrix grants no category right per wallet (spec 010 §6) | Only a category's owner writes it; the platform set has no writer through the API |
> | Nesting O2: one level, or flat | Open | **Flat in MVP.** `parent_id` stays reserved and is not exposed |
> | Archive once a category has transactions | Glossary: a used category is never hard-deleted; an unused one may be deleted (tombstoned) | Archive always allowed; delete only while unused |

## Clarifications

### Session 2026-10-02

Owner away with delegated authority; each answer below is the recommended (most conservative) option, recorded as an **assumed default**.

- Q: When a person picks a platform category, does the transaction point at the platform category itself, or at a copy made in the person's own set? → A: **At the platform category itself.** No copy is ever made, so platform names follow the app language, `system_key` grouping needs no mapping, and the set can be retired centrally. *Assumed default.* (FR-017)
- Q: May a person archive or hide a platform category they do not want in their picker? → A: **No, not in this feature.** Nobody can change a record they do not own; the app may filter locally, and a synced per-person hide list is additive later (Open question 1). The platform itself retires entries by marking them archived. *Assumed default.* (FR-019)
- Q: Are category writes behind the address-verification gate, given the baseline names only wallet writes? → A: **Yes, every category write**, refused with `403 EMAIL_NOT_VERIFIED`; reads are not gated. A category's name is shown to wallet partners through its references, so the same reason the baseline bars wallet writes applies; the baseline's gate table gains a row in this change. *Assumed default.* (FR-011)
- Q: Does this feature build the Category Reference now, or only freeze its shape for B6/B14? → A: **Build it now**: storage, the publishing service B6 calls, republication on category edits, the wallet-deletion cascade, and the read endpoint `GET /wallets/{id}/category-references`. Category edits are this feature's, so keeping references current must be too; the endpoint returns an empty page until B6 publishes. *Assumed default.* (FR-021–FR-026)
- Q: Are category routes rate limited, and is anything logged about categories? → A: **No route-specific budget** (as for wallets and profile; `429` is not documented), and **no category-specific log line**: names are user data and never logged (P-15 rule 4). *Assumed default.*

Further defaults taken while writing the spec, each the conservative option and listed again in the plan's Assumed defaults:

- Kind is immutable (`IMMUTABLE_FIELD` at `/kind`): it fixes the category's side of the ledger (ADR-0012 rule 5).
- Flat categories (O2): `parent_id` reserved, never accepted or exposed.
- Archiving is one-way and deletion final, matching wallets; both reversals are additive later.
- Deleting a used category is refused with a new code `409 CATEGORY_IN_USE` (no existing code means "archive instead").
- Platform names travel in both languages in every response (`names.ar`, `names.en`), so a language switch works offline.
- Names 1–50 characters after trimming; duplicates allowed (two offline devices must both succeed).
- No events; no entitlement or quota; sizing target 200 categories per person.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See the default set and my own categories (Priority: P1)

A new person opens the category picker and sees the platform's default categories — "Groceries / بقالة", "Salary / مرتب" and the rest — in the app's language, with their icons and colours, without having created anything. Once they add their own, those appear beside the defaults. Switching the app's language switches the defaults' names, never the names the person typed.

**Why this priority**: recording the first transaction (B6) needs something to file it under on day one, offline.

**Independent Test**: as a signed-in person with no categories, read the platform set and check every seeded entry carries its `system_key`, kind, both Arabic and English names, icon and colour; read the person's own list and get an empty page.

**Acceptance Scenarios**:

1. **Given** a signed-in person, **When** they read the platform set, **Then** they get every platform category, retired ones included and marked, in the platform's fixed order, each with its `system_key`, kind, Arabic and English names, icon and colour, in one response.
2. **Given** the platform set is read twice with different `Accept-Language` values, **When** the two are compared, **Then** they carry identical names: both languages are always present, so the app can switch language offline.
3. **Given** a person who has created categories, **When** they list their own, **Then** they see exactly their live categories (archived included, deleted excluded) and never anyone else's or a platform one.
4. **Given** a platform category, **When** the same entry is read in two environments or after a re-seed, **Then** its `system_key` is the same, so reports group by it.

---

### User Story 2 - Create and edit my own categories safely (Priority: P1)

A person adds "Coffee" as an expense category with a cup icon, possibly offline, and later renames it or changes its colour. Edits made on two devices never silently overwrite each other.

**Why this priority**: the defaults never fit everyone; a personal bucket is the first customisation users make.

**Independent Test**: create a category with a client-generated identifier, replay the same create, rename it carrying the version, then rename again with the old version and get a conflict carrying the current category.

**Acceptance Scenarios**:

1. **Given** a verified person, **When** they create a category with a new identifier, kind, name, icon and colour, **Then** it is created as theirs, live, unarchived, at version 1.
2. **Given** the same create sent twice, **When** the second arrives, **Then** it succeeds with the same record and no second category exists.
3. **Given** the owner and the current version, **When** they save the whole category or only some of its editable fields, **Then** the change is stored and the version goes up by one.
4. **Given** a stale version, **When** the owner saves, **Then** the whole save is refused as a version conflict carrying the present category, and nothing is applied.
5. **Given** a save identical to the stored category, **When** it arrives, **Then** it succeeds with nothing changed (same version).
6. **Given** any category, **When** the owner sends a different kind, **Then** the save is refused with an "immutable field" error pointing at the kind.
7. **Given** an identifier belonging to another person's category, to a platform category, or to any other kind of record, **When** someone writes to it, **Then** they are told it was not found, and the record is neither changed nor disclosed.
8. **Given** a person whose address is not verified, **When** they try any category write, **Then** they are refused with the verification code.

---

### User Story 3 - Archive or delete a category (Priority: P2)

A person archives a category they no longer use so it leaves the picker but still shows on old transactions; a category created by mistake and never used can be deleted outright.

**Why this priority**: lists get cluttered after weeks of use, not on day one.

**Independent Test**: archive a category and read it back archived; delete an unused one and read it back tombstoned; with a stub usage source reporting a category as used, try to delete it and get the in-use refusal.

**Acceptance Scenarios**:

1. **Given** the owner and the version, **When** they archive a category, **Then** it records when it was archived, stays in their list, and the version goes up by one; archiving it again succeeds with nothing changed.
2. **Given** an unused category, **When** the owner deletes it carrying the version, **Then** it is tombstoned (never removed) and leaves their list; deleting it again returns the tombstone unchanged.
3. **Given** a category that any record references (a transaction, a recurring rule or a budget, as those features arrive), **When** the owner tries to delete it, **Then** it is refused as "in use" and nothing changes; archiving remains available.
4. **Given** a tombstoned category, **When** the owner sends any other write to it, **Then** it is a version conflict carrying the tombstone — never a silent restore.
5. **Given** a category, **When** it is read, **Then** it says whether it is in use, so the app offers "archive" rather than "delete".

---

### User Story 4 - See a partner's category in a shared wallet (Priority: P2)

Two partners share a wallet. One files a purchase under her own "Pharmacy" category. The other, who cannot see her category list, still sees "Pharmacy" with its icon and colour on that transaction, and sees it renamed when she renames it.

**Why this priority**: shared wallets are the product's social surface; an unlabeled transaction there reads as a bug. It is P2 because transactions (B6) arrive later; this feature freezes the shape and builds the machinery.

**Independent Test**: publish a reference for a person's category into a wallet they share (the publishing service is called directly, as B6 will call it); as the other member, list the wallet's category references and see the name, icon, colour, kind and owner; rename the category and see the reference follow, with a higher version.

**Acceptance Scenarios**:

1. **Given** a member's category published into a wallet, **When** any active member lists the wallet's category references, **Then** they see one reference for it with the category's identifier, its owner, kind, name, icon and colour.
2. **Given** a published category, **When** its owner renames it or changes its icon or colour, **Then** every reference to it is updated in the same unit of work, each with its version and sync sequence moved on.
3. **Given** a reference, **When** anyone tries to write it, **Then** the write is refused as a method that does not exist: nobody can write a reference, including the category's owner.
4. **Given** a non-member, **When** they list a wallet's category references, **Then** they are told it was not found.
5. **Given** a platform category used in a wallet, **When** references are listed, **Then** none exists for it: every member already holds the platform set.

---

### Edge Cases

- **Two people create "Groceries" of their own.** Each gets their own record; duplicate names are allowed, within one person's set too (two offline devices could otherwise never both succeed).
- **A person creates "Groceries" while the platform set has one.** Allowed; it is a separate user category with no `system_key`, and reports treat it as the person's own bucket, not the platform one.
- **Platform category retired by the platform.** It stays readable, marked archived, so existing transactions keep rendering; the app hides it from the picker. It is never deleted.
- **Kind change on a used category.** Refused like any kind change: kind is immutable because it fixes which side of the ledger the category stands on (ADR-0012).
- **Writing a platform category's identifier through the category endpoints.** Not found — it is a different kind of record (P-06 rule 3).
- **Client sends `system_key`, `user_id`, `archived_at`, `in_use`, `parent_id`, `change_seq` or `deleted_at`.** Refused as unknown fields.
- **Category deleted while a stale reference is still published somewhere.** The deletion withdraws (tombstones) every live reference to it in the same unit of work.
- **Concurrent rename of a category and a transaction write in a shared wallet.** One waits for the other; neither deadlocks and the reference ends with the newest name (fixed lock order: the category before any wallet).
- **Wallet deleted.** Its category references are tombstoned with it (spec 010 FR-010 cascade). The categories themselves are untouched: they belong to people, not wallets.
- **Archived category on a new transaction.** Not decided here; B6 decides, recommended allowed (archiving hides from pickers only, and an offline device may not have seen the archive yet).
- **Delete racing a first use.** A delete and a transaction write that first uses the category are serialised on the category: either the delete lands first (the transaction then refers to a tombstone and B6 refuses it), or the use lands first (the delete is refused as in use). Never both.
- **A member leaves a shared wallet (B9).** Their transactions stay, so the references to their categories stay; B9 confirms (Open question 6).
- **Blank name, name over 50 characters, lowercase colour.** Refused with the matching field code, never coerced.

## Requirements *(mandatory)*

### Functional Requirements

**The person's own categories**

- **FR-001**: A person MUST be able to create a category offline-first, addressed by an identifier they generate (UUIDv7), carrying kind, name, icon and colour. A repeated create MUST NOT produce a second category (P-06 rule 2).
- **FR-002**: Kind MUST be exactly `income` or `expense` and MUST NOT change after creation, for a person's category and for a platform category alike (the platform seeder refuses a kind change too).
- **FR-003**: Name MUST be 1–50 characters after trimming, and is stored trimmed; duplicates are allowed. Icon (a short lowercase key) and colour (`#RRGGBB`, uppercase hex) are optional presentation tokens, validated for shape only, exactly as for wallets (spec 010 FR-003).
- **FR-004**: A category's names are the person's own words and MUST NOT be translated or altered by the server.
- **FR-005**: The owner MUST be able to save the whole category, or only some editable fields (name, icon, colour), carrying their last-seen version. A stale version MUST refuse the whole write with a version conflict carrying the present state, including a tombstone (P-07 rules 2–4). An identical write MUST succeed with nothing changed. The server MUST NOT merge fields. A whole-category save carrying a version above 0 for an identifier that matches no record MUST be refused as not found.
- **FR-006**: The owner MUST be able to archive a category. Archiving records when it happened, is not reversible in this feature, leaves every reference to the category valid, and is idempotent.
- **FR-007**: The owner MUST be able to delete a category **only while it is unused**. Deletion tombstones the category (never removes it) and withdraws every live Category Reference to it in the same unit of work. Deletion is final in this feature and idempotent. Deleting a used category MUST be refused as in use, changing nothing.
- **FR-008**: A category is **in use** when any record references it, whether that record is live or tombstoned (a tombstoned transaction may be restored, and must not then point at a deleted category): a transaction or its postings (B6), a recurring rule (B7), a budget (B8). Each of those features answers for its own records through a usage hook this feature offers; until one exists, every category is unused. The category MUST report whether it is in use (derived, never stored).
- **FR-009**: A person MUST be able to list their own live categories (archived included, tombstoned excluded) and read one. A tombstoned category is visible to its owner on a single read; any write except delete is a version conflict carrying it; delete returns it unchanged.
- **FR-010**: The list MUST sort by `created_at` (default) or `name` (byte order), stabilised by `id`, accept no filters, and page with the shared cursor and limit.
- **FR-011**: Every category write MUST be refused with the verification code for a person whose address is not verified. Reads are not gated. Category routes carry no route-specific rate limit, and no category name is ever logged (P-15 rule 4).
- **FR-012**: Only the category's owner may read or write it through the category endpoints. Anyone else — including members of a wallet the owner shares — MUST get not-found (P-06 rule 3).
- **FR-013**: Every category MUST carry the syncable members (`version`, `change_seq`, `created_at`, `updated_at`, `deleted_at`) and the user scope key (P-07 rule 1). `change_seq` is drawn from the user-scope source in use until B14 (spec 007 R-19); an identical replay moves neither `version` nor `change_seq`.
- **FR-014**: Categories are flat in MVP (O2 resolved). The reserved parent link MUST stay empty and MUST NOT be accepted or exposed.

**The platform default set**

- **FR-015**: The platform MUST own one default set of categories, each with a stable `system_key`, a kind, an Arabic and an English name, an icon, a colour and a fixed position. The set's contents are §3's table.
- **FR-016**: A `system_key` MUST be unique, lowercase, never renamed and never reused. A platform category's identifier, once seeded, MUST never change.
- **FR-017**: "Using" a platform category MUST mean **referencing it directly**: a transaction, recurring rule or budget points at the platform category itself, never at a per-person copy. No per-person copy is ever made.
- **FR-018**: Any signed-in person MUST be able to read the whole platform set in one response, retired entries included and marked, with both names always present whatever language was asked for.
- **FR-019**: No person may create, edit, archive or delete a platform category through the API. The platform changes the set only through its seeded reference data: a removed entry is **retired** (marked archived), never deleted, so every existing reference stays valid. A person cannot archive or hide a platform category in this feature.
- **FR-020**: Seeding the platform set MUST be idempotent, keyed on `system_key`, safe to run in production at every deploy, and MUST NOT change an existing entry's identifier.

**Category Reference (shared wallets)**

- **FR-021**: For every wallet in which a live record references a **person's own** category, the server MUST keep exactly one live Category Reference per (wallet, category), carrying the category's identifier, its owner's identifier, kind, name, icon and colour. Platform categories never get a reference.
- **FR-022**: This feature MUST offer the publishing service (publish if absent; withdraw when nothing in the wallet references the category any more) that B6 and B7 call inside their own unit of work. Until B6, nothing calls it except tests. Publishing a pair whose reference was withdrawn revives that same reference (same identifier, version + 1) rather than creating a second one.
- **FR-023**: When a category's name, icon or colour changes, every live reference to it MUST be updated in the same unit of work, each taking a new version and a `change_seq` from its wallet's scope counter under that wallet's lock (spec 010 FR-012). Locks MUST be taken in a fixed order — the category first, then wallets by ascending identifier — and every later feature that publishes a reference MUST take them in the same order. Archiving a category, and an identical (replayed) write, change no reference. A reference write moves its wallet's `change_seq` (the wallet takes the last value) but never the wallet's `version`.
- **FR-024**: A Category Reference MUST carry the syncable members and the wallet scope key, MUST be readable by any active member of its wallet (matrix row T-01), MUST be not-found to anyone else (including the former members of a deleted wallet), and MUST have no write operation: a write to its path is `405 METHOD_NOT_ALLOWED` (API conventions, Read-only resources).
- **FR-025**: Deleting a wallet MUST tombstone its references in the same unit of work, through the wallet deletion hook (spec 010 FR-010).
- **FR-026**: A reference grants no right over the category: the owner alone edits it, through their own categories.

**Ledger, reports and budgets**

- **FR-027**: A posting's category leg MAY reference either a person's category or a platform category (ADR-0012 as extended by ADR-0014). A platform category's total is meaningful only within a scope (a wallet, a person's wallets): no report, budget or invariant sums a platform category across everyone. B5 builds no posting, budget or report: FR-027 and FR-028 bind B6, B8 and B13, and are recorded on their briefs.
- **FR-028**: Reports group platform categories by `system_key` and a person's own categories by their identifier; a person's category is never merged into a platform one by name.

### Key Entities

- **Category** (glossary, changed in this feature): an income or expense bucket, owned **either by one person** (user-scoped, syncable) **or by the platform** (the default set; reference data, not synced, read by everyone).
- **Category Reference** (glossary): a read-only copy of a person's category's display fields, published into a wallet so every member can render it.

## Project Sections *(mandatory for feature specs)*

### 1. Purpose

This feature gives every person somewhere to file their money from the first minute — a localised default set they can use but never break — and lets them add, rename, archive and, while unused, delete their own buckets, offline and conflict-safe. It keeps each person's list private even inside a shared wallet, while letting partners see each other's labels on shared transactions.

### 2. Glossary terms

- [Category](../../docs/glossary.md#category), [Category Reference](../../docs/glossary.md#category-reference), [Wallet](../../docs/glossary.md#wallet-محفظة), [Wallet Member](../../docs/glossary.md#wallet-member), [Posting](../../docs/glossary.md#posting), [Transaction](../../docs/glossary.md#transaction), [Budget](../../docs/glossary.md#budget), [Report](../../docs/glossary.md#report): as defined, Category and Category Reference as amended in this change.
- **Platform category**: a Category owned by the platform rather than by a person, carrying a `system_key`. Added to the Category entry in this change.
- **`system_key`**: the stable, language-independent key of a platform category. Added to the Category entry in this change.
- **Retired**: a platform category the platform no longer offers; recorded as `archived_at`, never deleted. Added to the Category entry in this change.
- **In use**: a category some record references (FR-008); derived, never stored. Added to the Category entry in this change.

### 3. Data model

Owned by `app/Modules/Wallets` (context Wallets & Ledger).

| Entity | Key fields | Scope | Lifecycle |
|---|---|---|---|
| Category — a person's | `id` (UUIDv7, client-generated), `user_id` (owner), `kind` (`income` \| `expense`, immutable), `name`, `icon` (nullable), `color` (nullable), `archived_at` (nullable instant), `parent_id` (reserved, always null), `version`, `change_seq`, `created_at`, `updated_at`, `deleted_at` | user (`user_id`) | `active` → `archived`; either → `tombstoned` while unused |
| Category — platform | `id` (UUIDv7, seeded once), `system_key` (unique), `kind`, `names` (`ar`, `en`), `icon`, `color`, `position`, `archived_at` (retired), `version` (internal, not exposed), `created_at`, `updated_at` | none — reference data | `active` → `retired`; never deleted |
| Category Reference | `id` (UUIDv7, server-generated), `wallet_id`, `category_id`, `owner_user_id`, `kind`, `name`, `icon`, `color`, `version`, `change_seq`, `created_at`, `updated_at`, `deleted_at` | wallet (`wallet_id`) | live → tombstoned (withdrawn, category deleted, or wallet deleted); a later re-publish revives it as a new version |

Both kinds of Category share one identifier space, so a posting's category leg is one reference (ADR-0014). A person's category has no `system_key`; a platform category has no owner.

**The platform set** (seeded; positions are the fixed order; icon keys are tokens of the client's icon set):

| # | `system_key` | kind | en | ar | icon | colour |
|---|---|---|---|---|---|---|
| 1 | `groceries` | expense | Groceries | بقالة | `cart` | `#2E7D32` |
| 2 | `dining_out` | expense | Dining out | مطاعم وكافيهات | `restaurant` | `#EF6C00` |
| 3 | `transport` | expense | Transport | مواصلات | `bus` | `#1565C0` |
| 4 | `fuel` | expense | Fuel | بنزين | `fuel` | `#455A64` |
| 5 | `rent` | expense | Rent | إيجار | `home` | `#6D4C41` |
| 6 | `utilities` | expense | Utilities | كهرباء ومياه وغاز | `bolt` | `#F9A825` |
| 7 | `phone_internet` | expense | Phone & internet | موبايل وإنترنت | `phone` | `#00838F` |
| 8 | `health` | expense | Health | صحة وعلاج | `health` | `#C62828` |
| 9 | `education` | expense | Education | تعليم | `school` | `#283593` |
| 10 | `clothing` | expense | Clothing | ملابس | `shirt` | `#AD1457` |
| 11 | `household` | expense | Household | مستلزمات البيت | `sofa` | `#5D4037` |
| 12 | `personal_care` | expense | Personal care | عناية شخصية | `care` | `#8E24AA` |
| 13 | `entertainment` | expense | Entertainment | ترفيه | `ticket` | `#7B1FA2` |
| 14 | `subscriptions` | expense | Subscriptions | اشتراكات | `repeat` | `#3949AB` |
| 15 | `travel` | expense | Travel | سفر | `plane` | `#0277BD` |
| 16 | `kids` | expense | Kids | أطفال | `child` | `#F06292` |
| 17 | `gifts_given` | expense | Gifts | هدايا | `gift` | `#D81B60` |
| 18 | `charity` | expense | Charity & zakat | زكاة وصدقات | `heart` | `#00897B` |
| 19 | `other_expense` | expense | Other expenses | مصروفات أخرى | `dots` | `#757575` |
| 20 | `salary` | income | Salary | مرتب | `wallet` | `#2E7D32` |
| 21 | `business_income` | income | Business income | دخل تجاري | `store` | `#1B5E20` |
| 22 | `freelance` | income | Freelance | عمل حر | `laptop` | `#00695C` |
| 23 | `investment_income` | income | Investment returns | عائد استثمار | `chart` | `#4527A0` |
| 24 | `gifts_received` | income | Gifts received | هدايا مستلمة | `gift` | `#C2185B` |
| 25 | `refunds` | income | Refunds | مبالغ مستردة | `undo` | `#0097A7` |
| 26 | `other_income` | income | Other income | دخل آخر | `dots` | `#616161` |

### 4. Invariants

| Invariant | Principle or decision | Test that fails when broken |
|---|---|---|
| A category is owned by exactly one of a person or the platform; `system_key` set exactly on platform ones | ADR-0014, FR-015 | `tests/Feature/Wallets/Categories/CategoryOwnershipConstraintTest.php` |
| Kind never changes | FR-002, ADR-0012 rule 5 | `tests/Unit/Wallets/Categories/CategoryRulesTest.php`, `tests/Contract/Wallets/Categories/UpsertCategoryTest.php` |
| A foreign, platform or other-kind identifier is 404 and is not overwritten | P-06 rule 3 | `tests/Contract/Wallets/Categories/UpsertCategoryTest.php` |
| Stale version refuses the whole write with the current state (incl. tombstone) | P-07 rules 2–4 | `tests/Feature/Wallets/Categories/SaveCategoryTest.php` |
| Identical replay changes neither version nor `change_seq` | ADR-0008 rule 10 | `tests/Feature/Wallets/Categories/SaveCategoryTest.php` |
| A used category is never deleted; deletion is a tombstone | Glossary, ADR-0012 consequences, P-07 rule 5 | `tests/Feature/Wallets/Categories/DeleteCategoryTest.php` |
| No platform category is writable through the API | FR-019 | `tests/Contract/Wallets/Categories/PlatformCategoriesTest.php` |
| Seeding is idempotent, keeps identifiers, retires rather than deletes | FR-016, FR-020 | `tests/Feature/Wallets/Categories/PlatformCategorySeederTest.php` |
| At most one live reference per (wallet, category); never one for a platform category | FR-021 | `tests/Feature/Wallets/Categories/CategoryReferencesTest.php` |
| A category change republishes every reference in the same unit of work, under the wallet lock, with a wallet-scope `change_seq` | FR-023, spec 010 FR-012 | `tests/Feature/Wallets/Categories/CategoryReferencesTest.php` |
| Lock order category → wallets (ascending) holds under concurrency | FR-023 | `tests/Feature/Wallets/Categories/CategoryReferenceLockOrderTest.php` |
| References have no write operation | FR-024, API conventions | `tests/Contract/Wallets/Categories/ListCategoryReferencesTest.php` |
| A non-owner never reads or writes another person's category | FR-012, P-08 rule 3 | `tests/Contract/Wallets/Categories/GetCategoryTest.php` |
| Every category write is behind the verification gate | FR-011, baseline | `tests/Contract/Wallets/Categories/CategoryVerificationGateTest.php` |
| Wallet deletion tombstones its references | FR-025 | `tests/Feature/Wallets/Categories/CategoryReferencesTest.php` |

### 5. API

Under `/api/v1`, in `docs/openapi/paths/categories.yaml`, tag `Wallets & Ledger`. **Frozen for the C5 client** (contract first). Shapes are defined in the path file; the members the client binds to are listed here so a change to any of them is visibly a contract change.

| Method | Path | operationId | Success | Error codes (beyond the shared 400/401/406/415/426/500 refusals) |
|---|---|---|---|---|
| GET | `/categories` | `listCategories` | `200` collection | `INVALID_CURSOR`, `INVALID_QUERY_PARAMETER`, `SORT_FIELD_NOT_SUPPORTED`, `FILTER_FIELD_NOT_SUPPORTED` |
| GET | `/categories/{id}` | `getCategory` | `200` | `NOT_FOUND`, `INVALID_ID` |
| PUT | `/categories/{id}` | `upsertCategory` | `201` create, `200` update/replay | `EMAIL_NOT_VERIFIED`, `NOT_FOUND`, `VERSION_CONFLICT`, `INVALID_ID`, `VALIDATION_FAILED` |
| PATCH | `/categories/{id}` | `patchCategory` | `200` | `EMAIL_NOT_VERIFIED`, `NOT_FOUND`, `VERSION_CONFLICT`, `INVALID_ID`, `VALIDATION_FAILED` |
| POST | `/categories/{id}/archive` | `archiveCategory` | `200` | `EMAIL_NOT_VERIFIED`, `NOT_FOUND`, `VERSION_CONFLICT`, `INVALID_ID`, `VALIDATION_FAILED` |
| DELETE | `/categories/{id}` | `deleteCategory` | `200` with the tombstone | `EMAIL_NOT_VERIFIED`, `NOT_FOUND`, `VERSION_CONFLICT`, `CATEGORY_IN_USE`, `INVALID_ID`, `VALIDATION_FAILED` |
| GET | `/platform-categories` | `listPlatformCategories` | `200` collection, one page | `METHOD_NOT_ALLOWED` on any other method |
| GET | `/wallets/{id}/category-references` | `listCategoryReferences` | `200` collection | `NOT_FOUND`, `INVALID_ID`, `INVALID_CURSOR`, `INVALID_QUERY_PARAMETER`; `METHOD_NOT_ALLOWED` on any other method |

**Category** (response; composes `SyncableResource` + `UserScoped`): `id`, `user_id`, `version`, `change_seq`, `created_at`, `updated_at`, `deleted_at`, `kind` (`income` | `expense`), `name`, `icon` (string or null), `color` (string or null), `archived_at` (instant or null), `in_use` (boolean, derived).

**Category write** (`PUT`, all required except `version`): `kind`, `name`, `icon` (nullable), `color` (nullable), `version` (omitted or `0` to create). `PATCH`: any non-empty subset of `name`, `icon`, `color`, plus required `version` ≥ 1 (`kind` is `UNKNOWN_FIELD`). `archive`, `DELETE`: body `{ "version": n }`. A body without `version` on PATCH, archive or DELETE is `422` with `REQUIRED` at `/version`; a `PATCH` carrying only `version` is `422` with `REQUIRED` at the body root (pointer `""`). Anything else (`user_id`, `system_key`, `archived_at`, `in_use`, `parent_id`, `change_seq`, `deleted_at`, …) is `UNKNOWN_FIELD`.

**Platform Category** (response; reference data, not a syncable resource): `id`, `system_key`, `kind`, `names` (`{ "ar": string, "en": string }`, both always present), `icon` (string), `color` (string), `position` (integer, the fixed order), `archived_at` (instant or null — retired), `updated_at`.

**Category Reference** (response; composes `SyncableResource` + `WalletScoped`; read-only): `id`, `wallet_id`, `version`, `change_seq`, `created_at`, `updated_at`, `deleted_at`, `category_id`, `owner_user_id`, `kind`, `name`, `icon` (string or null), `color` (string or null).

**Resolving a transaction's category on the client** (for C5; B6 confirms): look the `category_id` up in, in order, the person's own categories, the platform set, then the wallet's category references.

**Field-level codes in `errors[]`**: `IMMUTABLE_FIELD` at `/kind`; `OUT_OF_RANGE` at `/name` (blank or over 50 after trimming); plus the shared `REQUIRED`, `UNKNOWN_FIELD`, `INVALID_TYPE`, `INVALID_ENUM_VALUE`, `INVALID_ID`.

**New response code**: `CATEGORY_IN_USE` (`409`, retryable `never`: archive instead). `info.version` moves from v1.3 to v1.4 (additive: new paths and one new code).

### 6. Authorization and entitlements

Categories are **user-scoped**, so the wallet permission matrix (spec 010 §6) does not govern them, and this spec adds no row to it.

- A person's category: only its owner reads or writes it. Anyone else gets **not-found** (P-06 rule 3, security baseline "What must never be disclosed"). No wallet role grants a right over another person's category (spec 010 §6, "Outside the matrix").
- Platform categories: every signed-in person reads them; nobody writes them through the API.
- Category References: readable under spec 010 row **T-01** (any active member: owner, editor, viewer); a non-member gets **not-found**. No row permits writing one. Publishing happens as a side effect of a write authorised under **T-02** (B6) or **R-02** (B7), which already says a member may choose only their own category or a platform one.
- Verification gate: every category write refused with `403 EMAIL_NOT_VERIFIED` for an unverified person; reads are not gated (baseline amended in this change to name category writes).
- Entitlements: none. No capability or quota gates categories (P-09 rule 5: the free tier is limited only on sharing).

**Precedence** for every category write: `401 UNAUTHENTICATED` → `403 EMAIL_NOT_VERIFIED` → `422 INVALID_ID` → `422 VALIDATION_FAILED` (request shape) → `404 NOT_FOUND` (another person's category, a platform category, another kind of record; a PATCH, archive or DELETE of an unknown id; a PUT with version > 0 for an unknown id) → `409 VERSION_CONFLICT` for any non-delete write to a tombstone (even an identical one) → `200` identical replay (and `DELETE` of a tombstone) → `409 VERSION_CONFLICT` (stale version) → `422 VALIDATION_FAILED` (`IMMUTABLE_FIELD` at `/kind`) → `409 CATEGORY_IN_USE` (delete only) → write.

### 7. Events

None. No module needs to react to a category change after commit: references are republished inside the unit of work (FR-023), and later features answer "in use" through the usage hook (FR-008). Adding an event later is additive.

### 8. Acceptance scenarios

| Story · scenario | Layer | Test file |
|---|---|---|
| US1 · 1–2, 4 platform set | Feature, Contract | `tests/Feature/Wallets/Categories/PlatformCategorySeederTest.php`, `tests/Contract/Wallets/Categories/PlatformCategoriesTest.php` |
| US1 · 3 own list | Contract | `tests/Contract/Wallets/Categories/ListCategoriesTest.php` |
| US2 · 1–2 create, replay | Feature, Contract | `tests/Feature/Wallets/Categories/SaveCategoryTest.php`, `tests/Contract/Wallets/Categories/UpsertCategoryTest.php` |
| US2 · 3–6 edit | Unit, Feature, Contract | `tests/Unit/Wallets/Categories/CategoryRulesTest.php`, `tests/Feature/Wallets/Categories/SaveCategoryTest.php`, `tests/Contract/Wallets/Categories/UpsertCategoryTest.php`, `tests/Contract/Wallets/Categories/PatchCategoryTest.php` |
| US2 · 7 foreign id | Contract | `tests/Contract/Wallets/Categories/UpsertCategoryTest.php`, `tests/Contract/Wallets/Categories/GetCategoryTest.php` |
| US2 · 8 verification | Contract | `tests/Contract/Wallets/Categories/CategoryVerificationGateTest.php` |
| US3 · 1–5 archive, delete | Feature, Contract | `tests/Feature/Wallets/Categories/DeleteCategoryTest.php`, `tests/Contract/Wallets/Categories/ArchiveCategoryTest.php`, `tests/Contract/Wallets/Categories/DeleteCategoryTest.php` |
| US4 · 1–5 references | Feature, Contract | `tests/Feature/Wallets/Categories/CategoryReferencesTest.php`, `tests/Contract/Wallets/Categories/ListCategoryReferencesTest.php` |

### 9. Open questions

1. **Hiding a platform category per person.** Not offered (FR-019); the app may filter locally. A synced per-person hide list can be added additively. Does not block.
2. **Un-archiving a category, restoring a deleted one.** Not offered, matching wallets. Additive later. Does not block.
3. **Whether a new transaction may reference an archived category** — B6 decides; recommended allowed. Does not block.
4. **Budget attribution in a shared wallet** (by the category's owner or by `created_by`) — B8, as the glossary says. Does not block.
5. **Nesting** — flat in MVP; a later spec may expose `parent_id`. Does not block.
6. **References after a member leaves** (B9) — recommended: they stay while the departed member's transactions reference their categories, still showing their last name, and stop being republished only if B15 removes the account. Does not block.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A new person can pick from all 26 platform categories, named in Arabic and in English, with 0 categories of their own created.
- **SC-002**: A person can create a category offline and have it accepted on reconnect with 0 duplicates, in 100% of retried creates.
- **SC-003**: 0 edits are silently lost: every concurrent edit pair yields exactly one stored edit and one visible conflict.
- **SC-004**: 0 responses reveal another person's category list; a partner sees only the labels on transactions in the wallet they share.
- **SC-005**: A report can group "Groceries" and "بقالة" spending across any wallets and any people as one bucket in 100% of cases where the platform category was used.
- **SC-006**: A partner sees a renamed category's new name on the very next read after the rename — never a stale label.
- **SC-007**: A person with up to 200 categories gets them all in one request at the maximum page size, and the platform set always arrives in one response.

## Assumptions

- Icon keys and colours come from the client's own set; the server validates only their shape. The platform set's icon keys (§3) are part of the contract the client must render.
- Until B6, no record references any category, so every category is unused (`in_use: false`), and no reference is published except by tests.
- Until B14, categories' `change_seq` comes from the shared user-scope source (spec 007 R-19) and references' from the wallet scope counter (spec 010 R-3); there is no feed endpoint, so the list reads are the client's source.
- The platform set is reference data, seeded by the Wallets module's seeder at every deploy, like the plan catalogue (spec 009).
- Account deletion's effect on categories (and on references a departed member's categories left in shared wallets) is B15's to define.
