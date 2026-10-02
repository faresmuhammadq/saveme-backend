# Quickstart: Transactions Ledger

How to prove that B6 works. The contract is [contracts/transactions-api.md](./contracts/transactions-api.md) and `docs/openapi/paths/transactions.yaml`; the tables are in [data-model.md](./data-model.md).

## Gates

```bash
dev migrate
dev exec -- vendor/bin/pest tests/Unit/Wallets tests/Feature/Wallets tests/Contract/Wallets tests/Contract/OpenApi tests/Arch --compact
dev exec -- composer test:lint && dev exec -- composer test:types
```

Then ask the owner to run the whole gate (`dev exec -- composer test`, or its stages one by one — the full run exceeds Composer's default timeout, spec 010 summary).

## Manual walk

Client headers plus a credential from `POST /auth/login`. A owns wallets C (EGP, opening 10000) and S (EGP) and U (USD); A and B share wallet H (EGP; B seeded as an editor directly, as spec 010's quickstart did); V is a viewer of H.

| # | Do | Expect |
|---|---|---|
| 1 | A: `PUT $API/wallets/C/transactions/<t1>` `{kind: expense, date: 2026-09-30, amount_minor: 2500, currency: EGP, category_id: <platform groceries>, counter_wallet_id: null, description: "Carrefour", memo: null, counterparty: null, cleared: false, version: 0}` | `201`, `version: 1`; `GET /wallets/C` → `balance_minor: 7500`, `currency_locked: true` |
| 2 | Repeat step 1 exactly | `200`, same `version` and `change_seq`; balance still 7500 |
| 3 | Step 1 with `amount_minor: "25.00"`, then `0`, then `currency: USD` | `422` `AMOUNT_NOT_INTEGER`, `OUT_OF_RANGE`, `CURRENCY_MISMATCH` at `/currency` |
| 4 | A: `PUT …/C/transactions/<t2>` transfer `{kind: transfer, counter_wallet_id: S, category_id: null, amount_minor: 1000, …}` | `201`; C balance 6500, S balance +1000; `GET /wallets/S/transactions` lists t2 |
| 5 | Same transfer to U | `422` `CURRENCY_MISMATCH` at `/counter_wallet_id` |
| 6 | V: `PUT …/H/transactions/<t3>` any kind; B: transfer H → C (B is not a member of C); A: transfer C → H | `403 FORBIDDEN`; `422` `INVALID_ENUM_VALUE` at `/counter_wallet_id`; `201`, and B sees it in `GET /wallets/H/transactions` |
| 7 | `PATCH …/t1` `{amount_minor: 3000, version: 1}`; again with `version: 1` | `200` `version: 2`, C balance 500 lower; then `409 VERSION_CONFLICT` with `current.version: 2` |
| 8 | `PUT …/t1` with `kind: income` | `422` `IMMUTABLE_FIELD` at `/kind` |
| 9 | `DELETE …/t1` `{version: 2}` twice | `200` tombstone both times; C balance 3000 higher |
| 10 | `PATCH …/t1` `{amount_minor: 1, version: 3}` | `409` with the tombstone in `current` |
| 11 | `PATCH …/t1` `{deleted_at: null, version: 3}` | `200` live `version: 4`; balance moves again |
| 12 | A: `PATCH $API/wallets/C` `{currency: USD, version: n}` | `422` `IMMUTABLE_FIELD` at `/currency` |
| 13 | A records with own category "Coffee" in H; B: `GET /wallets/H/category-references` | one reference for Coffee |
| 14 | A: `DELETE /categories/<Coffee>` | `409 CATEGORY_IN_USE`; `GET /categories` shows `in_use: true` |
| 15 | B: `PATCH` A's H transaction `{amount_minor: 5, version: n}` | `200`, category still A's |
| 16 | `GET /wallets/C/transactions?filter[kind]=transfer`, `?filter[date]=gte:2026-09-01&search=carre` | only matching rows |
| 17 | `GET …?filter[amount_minor]=1` | `400 FILTER_FIELD_NOT_SUPPORTED` |
| 18 | `dev wallets:verify-balances` | no drift logged |
| 19 | A: delete wallet S | t2 stays live in C's list; C's balance unchanged; `PATCH t2` → `422` `INVALID_ENUM_VALUE` at `/counter_wallet_id`; `DELETE t2` via C → `200` |

## Apidog

Re-import the bundled OpenAPI document into project 1332822 / module 1363204 after merge (see the Apidog memory note), and add a "Transactions" doc page.
