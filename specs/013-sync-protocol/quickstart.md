# Quickstart: Sync Protocol (spec 013)

How to prove the feature works once implemented. Contract: [contracts/sync-api.md](./contracts/sync-api.md); data: [data-model.md](./data-model.md).

## Prerequisites

```bash
dev project open
dev migrate          # in production: php artisan down → migrate → up (R-5: the user-counter switch)
dev exec -- composer test:arch
```

## Suites

```bash
dev exec -- vendor/bin/pest tests/Unit/Sync
dev exec -- vendor/bin/pest tests/Feature/Sync
dev exec -- vendor/bin/pest tests/Contract/Sync
dev exec -- vendor/bin/pest tests/Contract     # whole contract suite still green (no existing contract changed)
```

## Manual walk (curl, http://saveme-backend.test)

Headers on every call: `Authorization: Bearer <token>`, `X-Client-Platform: android`, `X-Client-Version: 1.0.0`, `Accept: application/json`.

1. **Full user feed**: `GET /api/v1/sync/user/changes` → the profile, sessions, own categories and one `wallet_member` per wallet; store `meta.sync_cursor`.
2. **Wallet feed**: `GET /api/v1/sync/wallets/{id}/changes` → the wallet, its owner membership, its transactions; page with `limit=2` and follow `next_cursor` until `has_more` is false.
3. **Incremental**: record an expense with `PUT /wallets/{id}/transactions/{tid}`; read the wallet feed from the stored cursor → exactly that transaction and the re-stamped wallet record.
4. **Transfer**: transfer from wallet A to B; B's feed shows the identical record.
5. **Batch**: `POST /api/v1/sync/batch` with the `mixedBatch` example from `sync.yaml` adjusted to real ids → results in order: `applied/201`, `applied/201`, `conflict/409` (when the version is stale), `rejected/404` (unknown id). Resend the same body → creates come back `applied/200` with unchanged `version` and `change_seq`.
6. **Envelope refusal**: 51 items → `422` with `OUT_OF_RANGE` at `/items`; nothing stored.
7. **Expiry**: in tinker-free fashion, run the Feature test `CursorExpiryTest`, or: tombstone a transaction, travel 91 days (`dev exec -- php artisan sync:purge-tombstones` after backdating `deleted_at` in a test database), read with the old cursor → `410 SYNC_CURSOR_EXPIRED`; read with no cursor → full state without the purged record.
8. **Purge job**: `dev sync:purge-tombstones` twice → second run removes nothing; logs one summary line per run.

## Expected performance (dev, Xdebug off)

- 50-item batch of transaction creates: p95 < 8 s.
- 200-row feed page in a wallet of 10,000 transactions: p95 < 300 ms.
