# Summary: Categories (spec 011, B5)

**Date**: 2026-10-02 · **Status**: implemented (all 42 tasks), QA and E2E green, committed on `main` (e358537).

## What was built

- **Categories, in the Wallets module** (`app/Modules/Wallets`, `Categories` sub-namespace in each layer). `categories` holds a person's categories (user scope, syncable) and the platform default set (reference data); two CHECKs keep the shapes apart, `parent_id` is reserved and always null. `category_references` holds the read-only projection published into wallets, with a partial unique index for one live row per (wallet, category).
- **Eight endpoints** under `/api/v1`, as frozen in `docs/openapi/paths/categories.yaml` (`info.version` v1.4): `GET /categories`, `GET|PUT|PATCH|DELETE /categories/{id}`, `POST /categories/{id}/archive`, `GET /platform-categories`, `GET /wallets/{id}/category-references`.
- **Writes** go through `CategoryService` with spec 010's rules: `version` in the body (also on archive and DELETE), identical write → 200 with no bump, tombstone → 409 with `current`, stale → 409, `kind` immutable (`IMMUTABLE_FIELD` at `/kind`), delete refused with `409 CATEGORY_IN_USE` while used. Every write route is behind `address.verified`.
- **Platform set**: `WalletsSeeder` → `PlatformCategorySeeder` seeds 26 categories (19 expense, 7 income, en/ar). Idempotent on `system_key`, ids kept, a dropped key is retired, never deleted. It runs in every Feature and Contract test (`TestCase::$seed`).
- **In use**: tagged `CategoryUsage` participants answer `isUsed` and set-wise `usedAmong`; none registered until B6.
- **Category References**: `CategoryReferences` has `publish`, `withdrawIfUnused`, `republish`, `withdrawAll` and `listFor`. A rename or icon/colour change republishes every live reference in the same transaction; category deletion and wallet deletion tombstone them. Lock order (ADR-0014 rule 10): category rows, then wallet rows in ascending id.
- **New error code** `CATEGORY_IN_USE` (409, never retry) in the catalogue, the `ErrorCode` enum and the ar/en strings.
- **Docs**: glossary, security baseline, constitution v1.3.4, ADR-0014 (extends ADR-0012), briefs B06–B08 ("Inherited from B5"), plan.md review-checklist results.
- **Apidog**: bundle imported into project 1332822 / module 1363204; "Categories" doc 2462527 created.

## QA and E2E results

- **Gates** (`composer test`): Pint clean, Larastan level 8 clean, Arch 47/47, Unit + Feature + Contract 920/920 (728 before; 192 new tests in 18 files).
- **QA** (`/speckit-qa-run`, curl): 113/113 HTTP checks + 1 suite check. Report `qa/qa-20261002-042903.md`.
- **E2E** (`/speckit-test-by-validate`, over HTTP): 22/22 acceptance scenarios (21 HTTP, 1 suite). Report `test-reports/validation-20261002-042903.md`. No browser surface, so no Playwright.
- `CATEGORY_IN_USE` is unreachable over HTTP before B6 (no usage participant yet); covered by suite tests with a stub participant.
- **Performance** (dev, Xdebug off, p95): `GET /categories?limit=200` 21 ms; `GET /platform-categories` 26 ms (target 100 ms).
- No defects found; no code changed during QA.

## Deployment

Run `php artisan db:seed` once when B5 ships (`PlatformCategorySeeder`, idempotent). Without it `/platform-categories` is empty.

## Assumed defaults

Owner away with delegated authority; each taken as the conservative option.

### Design (spec clarifications, plan A-1 to A-18)

1. A platform category is referenced directly, never copied into a person's set.
2. Nobody can archive or hide a platform category; the platform retires entries through the seeder.
3. `kind` is immutable for both owner kinds.
4. Categories are flat; `parent_id` is reserved and not on the wire.
5. Archive is one-way; delete is final and allowed only while the category is unused.
6. New code `CATEGORY_IN_USE` (409, retryable never).
7. `in_use` is exposed; "in use" includes tombstoned records (they can be restored).
8. Platform names come back in both languages (`names {ar, en}`) so the app can switch language offline.
9. Names 1–50 characters after trimming, stored trimmed; duplicates allowed.
10. Every category write is behind the verification gate; the security baseline gains that row.
11. The Category Reference is fully built now (storage, publishing, republish on edit, cleanup on wallet deletion, read endpoint).
12. Lock order: category rows first, then wallet rows ascending; binding on B6+ (ADR-0014 rule 10).
13. References only for a person's own categories; one live reference per (wallet, category); republishing revives the row.
14. Reference list: live rows only, creation order, no filters or sorts; 404 to everyone once the wallet is deleted.
15. Categories take `change_seq` from the shared `sync_change_seq` sequence through an adapter in the Wallets module.
16. No events, no plan limits, no rate limit.
17. Sizing: 200 categories per person in one page at `limit=200`.
18. ADR-0014 extends ADR-0012 rather than superseding it.

### Implementation

19. `CategoryUsage` has `isUsed` and `usedAmong(list<string>)`; B6–B8 implement both.
20. `findForShare` on the category repository; no `upsertPlatform` port (the seeder writes through the model, like `BillingSeeder`).
21. `publish` is a no-op for a platform or unknown id and throws for a tombstoned category; B6 must refuse that case first.
22. A retired platform category gives up its `position`; positions may swap.
23. `PlatformCategorySeeder::apply()` is public for tests; a kind change rolls the whole seed back.
24. `/platform-categories` reads no query parameters; `meta.limit` is the row count (clamped 1–200).
25. `GET /categories` reads plain rows instead of Eloquent models (hydration of 200 models cost about 100 ms).
26. One `@phpstan-ignore argument.type` in `EloquentCategoryRepository::listOwned`.

## Remaining risks

- Until B6, `in_use` is always false and nothing publishes references; both paths are exercised only by stubs.
- B6–B8 must follow the lock order and answer `usedAmong` set-wise; the briefs say so, nothing enforces it.
- DELETE with a JSON body is a client-library hazard (spec 010 R-5).
