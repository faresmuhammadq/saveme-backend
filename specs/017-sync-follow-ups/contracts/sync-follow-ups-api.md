# Contract: Sync follow-ups (v1.10)

Authoritative definition: `docs/openapi/paths/sync.yaml` (frozen, additive). This file summarises what changed for the client.

## New: `GET /api/v1/sync/wallets/heads` (`listWalletHeads`)

- Auth: bearer (`auth:api`); no verification gate, no entitlement.
- Query: `cursor`, `limit` (1–200 clamped, default 50). `filter[…]` → 400 `FILTER_FIELD_NOT_SUPPORTED`; `sort` → 400 `SORT_FIELD_NOT_SUPPORTED`; bad `limit` → 400 `INVALID_QUERY_PARAMETER`; bad cursor → 400 `INVALID_CURSOR`.
- 200: `data: [{wallet_id, change_seq, purged_change_seq}]` ordered by `wallet_id`; `meta`: `CollectionMeta` (`next_cursor`, `has_more`, `limit`).
- Other statuses: 401, 406, 426, 500 (shared responses).

Client rule, per followed wallet with stored position `p` (from `meta.sync_position`):

| Heads entry | Action |
|---|---|
| `change_seq == p` | skip the wallet's feed |
| `change_seq > p` | read the feed from the stored cursor |
| `purged_change_seq > p` | the stored cursor is expired; read from no cursor |
| listed, no stored position | read from the beginning |
| not listed | not a removal signal; read the feed (404 = access gone) and follow the user feed |

## Changed: `ChangeFeedMeta` (both feeds)

- New optional member `sync_position: integer ≥ 0`, always sent from v1.10: the position `sync_cursor` resumes from. Never sent back.

## Behaviour notes (no shape change)

- Wallet feed: a live `wallet_member` row is re-sent at a new `change_seq` and the same `version` when its member renames themselves; `display_name` is as of the read.
- Batch: an applied `restore` answers `version + 1`; `restore` (n) then `upsert` (n + 1) of the same transaction in one batch restores and edits; if the restore did not apply the upsert is 409 `VERSION_CONFLICT`.

No new error code. `info.version` v1.10.
