# Quickstart: Sync Follow-ups (spec 017)

Prerequisites: `dev project open`; migrated database; a queue worker for the rename check (`dev queue:work --once` repeatedly, or `dev queue:work`).

## Automated proof

```bash
dev exec -- vendor/bin/pest tests/Contract/OpenApi
dev exec -- vendor/bin/pest tests/Feature/Sync/WalletHeadsTest.php tests/Feature/Sync/SyncPositionTest.php
dev exec -- vendor/bin/pest tests/Contract/Sync/ListWalletHeadsTest.php tests/Contract/Sync/FeedPositionTest.php tests/Contract/Sync/RestoreEditPairTest.php
dev exec -- vendor/bin/pest tests/Feature/Wallets/MemberNameRestampTest.php
dev exec -- composer test
```

Expected: all green; Redocly warnings unchanged (203).

## Manual walk-through (curl, two accounts A and B sharing wallet W)

1. As B, read `GET /api/v1/sync/wallets/W/changes` until `has_more` is false; note `meta.sync_position` = p.
2. As B, `GET /api/v1/sync/wallets/heads`: W's `change_seq` equals p.
3. As A, create a transaction in W. As B, read the heads again: W's `change_seq` > p; the feed from the stored cursor returns the transaction.
4. As A, `PUT /api/v1/me/profile` with a new `display_name` (current `version`); run the queue. As B, read W's feed from the stored cursor: A's `wallet_member` row with the new name, same `version`.
5. As A, delete a transaction (version n), then `POST /api/v1/sync/batch` with `[restore v=n, upsert v=n+1]`: both `applied`, final version n + 2.
