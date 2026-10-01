# Quickstart: Wallets & Membership

How to prove that B4 works. The contract is [contracts/wallets-api.md](./contracts/wallets-api.md) and `docs/openapi/paths/wallets.yaml`; the tables are in [data-model.md](./data-model.md).

## Gates

```bash
dev migrate
dev exec -- composer test
dev exec -- vendor/bin/pest tests/Unit/Wallets tests/Feature/Wallets tests/Contract/Wallets tests/Feature/Billing/EntitlementsForWalletTest.php tests/Arch --compact
```

## Manual walk

Client headers plus a credential from `POST /auth/login` (spec 007 quickstart). Person A is verified; person U is not.

| # | Do | Expect |
|---|---|---|
| 1 | A: `PUT $API/wallets/<uuidv7>` with a cash wallet, `version: 0` | `201`, `my_role: owner`, `balance_minor` = opening balance, `version: 1` |
| 2 | Repeat step 1 exactly | `200`, same `version` and `change_seq` |
| 3 | `GET $API/wallets` | the wallet, once |
| 4 | `PATCH` with `opening_balance_minor` changed and `version: 1` | `200`, `version: 2`, `balance_minor` moved by the same amount |
| 5 | `PATCH` again with `version: 1` | `409 VERSION_CONFLICT`, `current.version: 2` |
| 6 | `PUT` with a different `type` and the current version | `422`, `IMMUTABLE_FIELD` at `/type` |
| 7 | U: `PUT` a new wallet | `403 EMAIL_NOT_VERIFIED` |
| 8 | B (another verified person): `GET`, `PATCH`, `DELETE` A's wallet | `404 NOT_FOUND` each |
| 9 | A: `GET $API/wallets/<id>/members` | one member, A, `role: owner`, A's display name |
| 10 | A: `POST …/transfer-ownership` naming B | `422`, `INVALID_ENUM_VALUE` at `/user_id` |
| 11 | `dev tinker`: insert B as `editor` through `WalletService`'s membership seam (or the test helper), then A transfers to B | `200`, `my_role: editor`; members show B `owner`, A `editor`; one `role_changed` audit row |
| 12 | A: `POST …/archive`; again | `200` with `archived_at`; second call unchanged |
| 13 | B (now owner): `DELETE` with the current version; again | `200` tombstone both times; A's and B's lists no longer show it; `wallet_members` rows tombstoned |
| 14 | `UPDATE wallets SET balance_minor = 1` on a live wallet; `dev wallets:verify-balances` | a `warning` log with the wallet id, the column repaired |

## Apidog

After the contract tests pass, import the bundled OpenAPI into the Save Me Apidog project as for earlier features.
