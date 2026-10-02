# Contract: Sync protocol (frozen for C14 wave 2)

The machine-readable contract is [`docs/openapi/paths/sync.yaml`](../../../docs/openapi/paths/sync.yaml) (indexed from `openapi.yaml`, `info.version` v1.6), with request and response examples. Where this page and the YAML disagree, the YAML wins (spec 003). This page lists what the client binds to, so any change to it is visibly a contract change.

## Answers to the client team's questions (C14 "Needs from the backend")

| Question | Frozen answer |
|---|---|
| Batch size limit | 1–50 items. Outside: `422 VALIDATION_FAILED`, `OUT_OF_RANGE` at `/items`, nothing applied |
| Per-item vs all-or-nothing | Per item. Each item commits on its own; one item's clash, refusal or failure never undoes or blocks another |
| Ordering within a batch | Array order, one at a time; results in the same order. The same record may repeat; the later item meets the earlier one's result |
| Batch `Idempotency-Key` vs per-item idempotency | No key (ignored if sent, nothing stored). Each item is idempotent by `id` + `version`, exactly like the per-record write; resending a batch is safe |
| Does per-record `PUT` stay? | Yes, permanently and unchanged. Archive, ownership transfer and partial `PATCH` edits are per-record only |
| Cursor per scope | One cursor for the user scope (`GET /sync/user/changes`) and one per wallet (`GET /sync/wallets/{id}/changes`). Store `meta.sync_cursor` after applying a page; send it back as `cursor`. Absent = from the beginning |
| `SYNC_CURSOR_EXPIRED` | `410` on one feed: discard that scope's local records (keep queued writes), read that feed again with no cursor. Other scopes unaffected |
| Transfers on two feeds | One record, identical on the source's and the destination's feeds. Its `change_seq` is the source's; never compare `change_seq` across feeds |
| Learning a new or lost wallet | Your own `wallet_member` rows ride your user feed: live = follow `wallet_id`'s feed (from the start if new); tombstoned = purge that wallet |

## Endpoints

| Method | Path | operationId | Success | Refusals |
|---|---|---|---|---|
| GET | `/sync/user/changes` | `listUserChanges` | `200` feed page | `400` `INVALID_CURSOR`, `INVALID_QUERY_PARAMETER`, `FILTER_FIELD_NOT_SUPPORTED`, `SORT_FIELD_NOT_SUPPORTED`; `410` `SYNC_CURSOR_EXPIRED` |
| GET | `/sync/wallets/{id}/changes` | `listWalletChanges` | `200` feed page | as above, plus `404` `NOT_FOUND`, `422` `INVALID_ID` |
| POST | `/sync/batch` | `uploadSyncBatch` | `200` results | `422` `VALIDATION_FAILED` (envelope), `400` `MALFORMED_JSON`, `415` `UNSUPPORTED_MEDIA_TYPE` |

Plus the shared `401`, `406`, `426`, `500` on all three.

## Feed page

```json
{ "data": [ { "type": "transaction", "record": { "...": "the resource" } } ],
  "meta": { "next_cursor": null, "has_more": false, "limit": 50, "sync_cursor": "…" } }
```

`limit` default 50, max 200 (clamped). `type` values: user feed — `profile`, `device_session`, `category`, `wallet_member`; wallet feed — `wallet`, `wallet_member`, `category_reference`, `transaction`. Skip unknown types. A deletion is `record.deleted_at` set. Keep the highest `version` when a record arrives again.

## Batch

Request: `{ "items": [ { "type", "op", "id", "wallet_id"?, "data" } ] }`.

| `type` | ops | `data` is |
|---|---|---|
| `wallet` | `upsert`, `delete` | `WalletWrite` / `{version}` |
| `category` | `upsert`, `delete` | `CategoryWrite` / `{version}` |
| `transaction` (needs `wallet_id`) | `upsert`, `delete`, `restore` | `TransactionWrite` / `{version}` / `{deleted_at: null, version}` |
| `profile` (`id` = your profile id) | `upsert` | `ProfileWrite` |

Response `200`: `{ "data": [ { "type", "id", "outcome", "status", "record", "problem" } ], "meta": {} }`.

| `outcome` | `status` | carries |
|---|---|---|
| `applied` | `200` / `201` | `record` |
| `conflict` | `409` | `problem` with `code: VERSION_CONFLICT` and `current` (tombstone included) |
| `rejected` | anything else (`403`, `404`, `409 CATEGORY_IN_USE`, `422`, `500`…) | `problem` exactly as the single write; pointers rooted at the item's `data` |

Allow at least 30 seconds for a batch response. Authentication is checked once for the whole batch. Unrecognised query parameters on the feeds are ignored (so a stray `since` yields a full resync); `filter[…]` and `sort` are refused.

Retry policy per item is the catalogue's `retryable` for `problem.code`, as for single writes (`500 INTERNAL_ERROR` is `automatic`).

## Unchanged

Every existing endpoint, schema and error code. No new error code.
