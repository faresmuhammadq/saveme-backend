# Research: Categories

**Feature**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Date**: 2026-10-02

Every decision below was taken with the owner away under delegated authority; each is the recommended, most conservative option, and is listed in the plan's Assumed defaults. Spec 010 (wallets) is the pattern reference throughout.

---

## R-1 One `categories` table for both owners

**Decision**: a single `categories` table holds a person's categories and the platform set. A row is **either** a person's (`user_id` set, `system_key` null, `name` set, `names` null) **or** the platform's (`user_id` null, `system_key` set, `names` jsonb `{ar, en}` set, `name` null, `icon`, `color` and `position` set). CHECK constraints enforce both shapes. `parent_id` (uuid, nullable) exists with `CHECK (parent_id IS NULL)`, reserving nesting (O2) without exposing it.

**Rationale**: ADR-0012 gives a posting a single `category_id`. One identifier space means one foreign key from `postings` (B6) and one lookup for the client, whatever the owner. The two kinds differ in four columns, which CHECKs keep honest.

**Alternatives**:
- Two tables (`categories`, `platform_categories`) — rejected: postings would need two nullable columns or a polymorphic key, breaking ADR-0012 rule 3's single category leg and its CHECK.
- Copy the platform set into each person's rows (the brief's original idea, per user rather than per wallet) — rejected by R-2.

## R-2 "Using" a platform category means referencing it

**Decision**: a transaction, recurring rule or budget references the platform row directly. No per-person copy, no lazy copy-on-first-use.

**Rationale**: the owner decision says the platform set is one shared set nobody can edit. A reference keeps one row per `system_key` across all users, so reports group by key with no mapping table (spec FR-028), names follow the app language (C5 consideration), and a retirement or icon fix reaches everyone at once.

**Alternatives**: copy-on-use with `system_key` carried on the copy — rejected: names would freeze in the language of first use, the person could rename "Groceries" and still be grouped as groceries, and two devices using the same platform category offline would each create a copy (a duplicate the server cannot merge, P-07 rule 4).

## R-3 Platform names travel in both languages

**Decision**: `GET /platform-categories` returns `names: {ar, en}` on every entry, ignoring `Accept-Language` for the names (the response's problem strings and `Content-Language` still follow the conventions).

**Rationale**: the app caches the set and must switch language offline (C5). Plans (spec 009) localise one `name` per request, but plans are read online at purchase time; categories are rendered on every screen.

**Alternatives**: one localised `name` per request (the plans pattern) — rejected for the offline language switch; a client-side translation table keyed by `system_key` — rejected, it would freeze the set into the app release.

## R-4 Platform categories are reference data, seeded

**Decision**: `Wallets\Infrastructure\Database\Seeders\WalletsSeeder` (already discovered by `DatabaseSeeder`'s module loop) calls `PlatformCategorySeeder`, which holds the spec §3 table as `definitions()` and upserts by `system_key`:

- a new key is inserted with a fresh UUIDv7;
- an existing key keeps its `id`; `kind` may never change (the seeder throws), names, icon, colour and position are updated in place, `updated_at` moves only when something changed;
- a key present in the table but missing from `definitions()` is **retired** (`archived_at` set once), never deleted; a key in `definitions()` that is retired in the table is un-retired.

`definitions()` is validated like `BillingSeeder::validate`: key shape `^[a-z][a-z0-9_]{1,39}$`, unique keys and positions, both names non-blank and ≤ 50 characters, icon and colour in the shared shapes. It is safe in production and runs at every deploy (`dev db:seed`), like the plan catalogue.

**Rationale**: P-14 — the names are data in both languages, not hardcoded UI strings; the seeder is the CONTRIBUTING item 12 reference-data seeder. Retire-not-delete keeps every posting's category leg valid (glossary: a used category is never hard-deleted).

**Alternatives**: a data migration — rejected, migrations are for schema and cannot be re-run when a name changes; names in `lang/*.php` keyed by `system_key` — rejected, reports (B13) and exports (B15) would then depend on the translation loader to name a ledger dimension.

## R-5 User-scope `change_seq`

**Decision**: a person's categories draw `change_seq` from the shared PostgreSQL sequence `sync_change_seq`, through a Wallets Infrastructure adapter `UserScopeSequence` implementing a new `Wallets\Domain\Ports\UserScopeSequence::next(): int`. Wallets does not import Identity's `PostgresChangeSequence` (P-02).

**Rationale**: spec 007 R-19 put the user scope on this sequence until B14 takes over commit ordering; Profile and Device Session already use it, and all four user-scoped resources must share one counter so one user cursor orders them.

**Alternatives**: a per-user counter row — rejected for now; B14 owns that choice for the whole user scope and must change Identity too.

## R-6 Writes: PUT, PATCH, archive, DELETE — spec 010's shape

**Decision**: `Wallets\Application\Categories\CategoryService` with `save`, `patch`, `archive`, `delete`, each in `DB::transaction` with `SELECT … FOR UPDATE` on the category row, following spec §6's precedence. The flow is spec 010 R-4's: find (owner check → 404) → tombstone refusal → identical replay (no bumps) → stale version (409 with `current`, rendered through a presenter closure, the `SaveProfile` pattern) → `IMMUTABLE_FIELD` at `/kind` → in-use refusal (delete) → write with `version + 1` and a fresh `change_seq` → republish references (R-8). `version` travels in the JSON body for every write, including DELETE and archive (spec 010 R-5, same client-library caveat). Request validation reuses the Shared `NotBlank` rule (names) and the wallet `Icon`/`Color` shapes. Domain refusals: `CategoryNotFound`, `CategoryVersionConflict`, `CategoryFieldImmutable`, `CategoryInUse`.

**Identical**: every writable member equals the stored value (name compared after trimming), whatever version it carries — including a create replayed with version 0.

**Rationale**: the C5 client reuses its C4 write plumbing unchanged; identical semantics on both resources.

**Alternatives**: `deleted_at` in the PUT body (API conventions' generic form) — rejected for consistency with wallets, which the client already speaks.

## R-7 "In use" through usage participants

**Decision**: `Wallets\Application\Categories\CategoryUsage` is an interface (`isUsed(string $categoryId): bool`), tagged like `WalletDeletionParticipant`; `CategoryUsageQuery` asks every tagged participant and answers `true` if any does. B6 (transactions and postings, tombstoned included), B7 (recurring rules) and B8 (budgets, through Budgeting's application layer) each register one. B5 registers none, so every category is unused. `in_use` on the resource and the delete refusal both come from this query; the delete re-checks it inside the transaction after locking the category row, and a publisher (R-8) takes the category row lock in share mode, so a concurrent first use and a delete serialise.

**Rationale**: the glossary forbids deleting a used category; B5 cannot know B6–B8's tables (P-02), and the participant pattern is already established by spec 010 R-9.

**Alternatives**: a `use_count` column — rejected, a second cache to drift (the same objection ADR-0012 raises to rollups); a database FK with `ON DELETE RESTRICT` — insufficient, tombstoning is an UPDATE, not a DELETE.

**New code**: `CATEGORY_IN_USE` (409, response level, retryable `never`, since v1.4: "The category is referenced by recorded history and cannot be deleted; archive it instead."). No existing code says this: `VERSION_CONFLICT` means "merge and resend", `FORBIDDEN` means a role, `VALIDATION_FAILED` means the input is wrong.

## R-8 Category Reference: stored projection, published in the writer's unit of work

**Decision**: `category_references` stores the projection (`id` server UUIDv7, `wallet_id`, `category_id`, `owner_user_id`, `kind`, `name`, `icon`, `color`, sync members). A partial unique index allows one live row per (`wallet_id`, `category_id`). `Wallets\Application\Categories\CategoryReferences` offers:

- `publish(string $walletId, string $categoryId): void` — called by B6/B7 inside their transaction **after** they have locked the category in share mode and **before** they lock the wallet; it is a no-op for a platform category, inserts or revives (new version) a reference for a person's category, and stamps it from the wallet scope counter;
- `withdrawIfUnused(string $walletId, string $categoryId): void` — called by B6/B7 when their last record in the wallet stops referencing it; tombstones the reference;
- `republish(CategoryRecord $category): void` — called by `CategoryService` after a name/icon/colour change, with the category row already locked: it finds every live reference, then locks each wallet **in ascending id order**, updates the reference (version + 1, `change_seq` from that wallet's counter) and gives the wallet its final stamp;
- `withdrawAll(string $categoryId)` — on category deletion (defensive: an unused category should have none);
- a `WalletDeletionParticipant` that tombstones a deleted wallet's references inside the wallet deletion.

Wallet-scope stamping reuses spec 010 R-3 as it is: with the wallet row locked, the reference takes `ScopeSequence::nextFor($walletId)`, then one more `nextFor` call gives the wallet row its final, highest value (the wallet's `change_seq` column *is* the counter, so the second call is the stamp). `WalletService` is not changed.

**Lock order** (spec FR-023), the one rule every later feature must follow: **category rows (share or update) before wallet rows; several wallets in ascending id**. A wallet deletion takes only the wallet lock and never a category lock, so it cannot deadlock with a rename.

**Rationale**: a reference needs `version` and a wallet-scope `change_seq`, so it must be stored (the glossary's "derived" means server-produced). Republishing in the same transaction (rather than a queued listener) means no partner ever reads a stale label (spec SC-006) and needs no repair job. The fixed lock order removes the deadlock between "rename category" (category → wallets) and "record transaction" (which would otherwise lock wallet → category).

**Alternatives**:
- Compute references on read from transactions — rejected: no `change_seq`, nothing for the B14 feed to carry, and B5 has no transactions to read.
- After-commit queued republish — rejected: a window where partners see the old name, plus a race with a concurrent publish that would need a repair job.
- Withdrawal deleting the row — rejected: P-07 rule 5 (tombstones).

## R-9 The reference read endpoint

**Decision**: `GET /wallets/{id}/category-references`, authorised by `WalletAuthorizer::authorize($user, $wallet, Permission::T01ReadTransactions)` (any active member; non-member 404). It lists **live** references ordered by `created_at`, `id`, paged with the shared cursor and limit, no filters or sorts. Writes to the path are `405` with `Allow: GET` (the router's default, mapped to `METHOD_NOT_ALLOWED` by the shared runtime). A tombstoned wallet answers 404 to everyone, including former members: its references were tombstoned with it, and the wallet tombstone itself is what the client needs.

**Rationale**: the client can render partners' labels before B14's feed exists; the feed will carry tombstones later, so the read lists only live rows.

**Alternatives**: embed references in `GET /wallets/{id}` — rejected, it would grow with every category used and change the frozen C4 Wallet schema.

## R-10 Reads

**Decision**: `CategoryQueryService` with `listFor(userId, sort, cursor, limit)` (live categories, archived included; `x-sortable: [created_at, name]`, byte order, stabilised by `id`; `x-filterable: []`) and `find(userId, id)` (owner only; tombstones returned to the owner). `PlatformCategoryQueryService::all()` returns every platform row ordered by `position`, one page (`next_cursor: null`, `has_more: false`, `limit` = the row count clamped to 200), `x-sortable: []`, `x-filterable: []`. `in_use` is computed per row through `CategoryUsageQuery` (constant `false` until B6; B6 must answer it set-wise to avoid N+1).

**Rationale**: matches spec 010 R-11 and the plans catalogue (spec 009).

## R-11 The verification gate and the baseline

**Decision**: every category write route sits behind `address.verified`. The security baseline's gate table gains "Any write to their own categories — refused", and its protected-attributes table gains "Platform category names — none at rest — yes — every signed-in person". The baseline's "Wallet and category names" row is unchanged.

**Rationale**: spec Clarifications; checklist C1 is a yes-or-no per endpoint, so the gate's scope must be written down.

## R-12 ADR-0014 extends ADR-0012

**Decision**: a new [ADR-0014](../../docs/adr/0014-platform-owned-categories.md) records: a category is owned by a person or by the platform; a posting's category leg may reference either; a platform category's total is only ever computed within a scope; platform categories are retired, never deleted; references to platform categories are never published. ADR-0012 stays Accepted and unedited — its posting rules hold as written; ADR-0014 extends rather than supersedes it (README "Superseding a decision" does not apply; CONTRIBUTING item 18 does). The constitution's P-04 `ADRs:` line gains ADR-0014 (PATCH).

**Rationale**: the owner decision requires ADR-0012's category postings to be updated in the same change; ADRs are never edited, and no rule of ADR-0012 changes, so an extending record is the honest form.

## R-13 Glossary

**Decision**: in this change the Category entry is rewritten to two owners (a person, user scope; the platform, reference data with `system_key`, retired not deleted), with "platform category", "`system_key`", "retired" and "in use" in its notes, and the O2 outcome (flat). Category Reference's Persistence becomes "persisted projection — produced by the server, never authored", its States "live → tombstoned (withdrawn)", and its fields gain `category_id` and `owner_user_id` (the glossary's "owning user's id"). The "What syncs" table notes that platform categories are read through `GET /platform-categories` and cached. Entity count stays 24: a platform category is a Category.

## R-14 What later features must do (contract for B6–B8)

- B6: lock categories (share) before wallets; call `CategoryReferences::publish` for each person's category a transaction uses and `withdrawIfUnused` when the last use in that wallet goes; register a `CategoryUsage` participant counting live **and** tombstoned transactions; enforce T-02's "own category or platform category" rule; decide archived-category references (recommended allowed).
- B7: the same for recurring rules.
- B8: register a `CategoryUsage` participant for budgets.
- B14: carry categories in the user feed and references in the wallet feed; may replace `UserScopeSequence`.
