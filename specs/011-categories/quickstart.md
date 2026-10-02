# Quickstart: Categories

How to prove that B5 works. The contract is [contracts/categories-api.md](./contracts/categories-api.md) and `docs/openapi/paths/categories.yaml`; the tables are in [data-model.md](./data-model.md).

## Gates

```bash
dev migrate
dev db:seed --no-interaction        # BillingSeeder + WalletsSeeder (the platform set)
dev exec -- vendor/bin/pest tests/Unit/Wallets tests/Feature/Wallets tests/Contract/Wallets tests/Contract/OpenApi tests/Arch --compact
dev exec -- composer test:lint && dev exec -- composer test:types
```

Then ask the owner to run the whole gate (`dev exec -- composer test`, or its stages one by one — the full run exceeds Composer's default timeout, spec 010 summary).

## Manual walk

Client headers plus a credential from `POST /auth/login` (spec 007 quickstart). A and B are verified and share wallet W (B seeded as an editor directly, as spec 010's quickstart did); U is not verified.

| # | Do | Expect |
|---|---|---|
| 1 | A: `GET $API/platform-categories` | `200`, 26 entries in `position` order, each with `system_key`, both `names`, icon, colour; `meta.next_cursor: null` |
| 2 | Same with `Accept-Language: ar` | identical `data` |
| 3 | `dev db:seed` again; repeat step 1 | identical ids and `system_key`s |
| 4 | A: `PUT $API/categories/<uuidv7>` `{kind: expense, name: "Coffee", icon: "cup", color: "#6D4C41", version: 0}` | `201`, `version: 1`, `in_use: false` |
| 5 | Repeat step 4 exactly | `200`, same `version` and `change_seq` |
| 6 | `PATCH` `{name: "Café", version: 1}`; again with `version: 1` | `200` `version: 2`; then `409 VERSION_CONFLICT`, `current.version: 2` |
| 7 | `PUT` with `kind: income` and the current version | `422`, `IMMUTABLE_FIELD` at `/kind` |
| 8 | A: `PUT $API/categories/<a platform id>` | `404 NOT_FOUND` |
| 9 | B: `GET`, `PATCH`, `DELETE` A's category | `404` each |
| 10 | U: `PUT` a new category | `403 EMAIL_NOT_VERIFIED` |
| 11 | `dev tinker`: inside `DB::transaction`, share-lock A's category row (`SELECT … FOR SHARE`), then `app(CategoryReferences::class)->publish(W, <A's category>)` — B6's call, in B6's lock order, done by hand | — |
| 12 | B: `GET $API/wallets/W/category-references` | one reference: `category_id`, `owner_user_id` = A, `name: "Café"` |
| 13 | A: `PATCH` the name to "Coffee"; B repeats step 12 | `name: "Coffee"`, reference `version` + 1, higher `change_seq`; W's own `change_seq` is the highest in W |
| 14 | B: `PUT $API/wallets/W/category-references` | `405`, `Allow: GET` |
| 15 | A: `POST …/archive` twice | `200` with `archived_at`; second unchanged |
| 16 | A: `DELETE` with the current version, twice | `200` tombstone both times; the reference in W is tombstoned; A's list no longer shows the category |
| 17 | With a stub `CategoryUsage` participant answering true (Feature test only) | delete refused `409 CATEGORY_IN_USE` |

## Apidog

After the contract tests pass, re-bundle `docs/openapi` and import into the Save Me Apidog project (1332822, module 1363204) as for earlier features; add a "Categories" doc covering the platform set, `system_key`, references and the delete/archive rule.
