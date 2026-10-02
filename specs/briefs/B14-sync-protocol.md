# B14 · Sync protocol

**Call with**: `/speckit-specify B14` · **Source**: the Phase B brief set, recorded 2026-09-25

## Description

The client pulls `GET /sync/changes?wallet_id=&since=<cursor>` returning ordered change rows (`entity`, `id`, `version`, `op` upsert/delete, `payload`) up to a page limit and a `next_cursor`; it pushes `POST /sync/batch` with a list of upserts/deletes each carrying `version`; the server applies field-level last-write-wins for equal versions by `updated_at`, returns per-item results (`applied`, `conflict` with server state, `rejected` with error), and the batch is idempotent by `Idempotency-Key`.

## Considerations

The cursor is a monotonic per-wallet sequence (`change_log.id`), not a timestamp. Membership changes (spec 9) appear as change rows so the client learns it lost access. Tombstones remain in the feed for 30 days. Batch size limits and partial success semantics must be explicit. Write this contract right after spec 6 even if implemented later; the KMP local schema mirrors it.

## Owner decisions (2026-09-25)

- **The phone resolves clashes; the server never merges.** A stale write is refused whole with `409 VERSION_CONFLICT` and the record as it now stands, and the phone merges or asks the user before resending. P-07 and ADR-0008 stand unchanged. The brief's server-side last-write-wins is withdrawn.
- **Upload is a batch**: many records in one request, with a result per item — `applied`, `conflict` with the current record, or `rejected` with an error code.
- Each item in a batch obeys exactly the rules a single write does: client-generated id, `version` check, no merge, whole-item refusal. The batch changes how writes travel, not what a write is.
- The spec must settle: the batch size limit; whether items are applied independently or all-or-nothing per scope; ordering within a batch; how the batch's `Idempotency-Key` relates to per-item idempotency by id and version; and whether the per-record `PUT` remains available alongside it.
- **Needs a superseding decision record** for ADR-0008 rule 7 ("push MUST be a per-entity `PUT`"), and extends the API conventions, which put batch writes out of scope. The cursor (per-scope `change_seq`, opaque on the wire), the two scopes and the 90-day tombstone horizon stay as ADR-0008 and the security baseline fix them.

## Reconcile at specify time

The brief predates decisions ratified since. These collide with it; the ratified side wins unless the spec deliberately supersedes it through a decision record or an amendment.

| Brief says | Already decided | Resolution needed |
|---|---|---|
| Server-side field-level last-write-wins | **ADR-0008 rule 9, P-07 rule 4** (non-negotiable): the server MUST NOT merge; a stale write is rejected whole with `409 VERSION_CONFLICT` | Direct constitutional conflict. Drop LWW, or supersede ADR-0008 and amend P-07 |
| Cursor = `change_log.id` | ADR-0008: per-scope `change_seq` stamped under the scope row lock; a global sequence is rejected because it is not commit-ordered | The cursor is opaque (spec 003) and encodes `change_seq` |
| Per-wallet feeds only | Two scopes: user and wallet; four of eleven syncable entities are user-scoped | Both feeds |
| Tombstones for 30 days | ADR-0008 rule 12 and the security baseline: at least **90** days | 90 |
| `POST /sync/batch` | ADR-0008 rule 7: push is per-entity `PUT`; spec 003 put batch writes out of scope | A batch is a new decision needing a superseding ADR |
| Membership loss as a change row | ADR-0008 rule 15: revocation appears in the user feed | Already defined |

## Inherited from B6 (spec 012)

- **The `Transaction` resource is the feed row** (spec 012 contract, `docs/openapi/paths/transactions.yaml`): `id`, `wallet_id`, `version`, `change_seq`, timestamps, `deleted_at` and the writable members. Postings are never part of it (P-04 rule 6).
- **A transfer rides two wallet feeds** (ADR-0015): select a wallet's feed rows by `change_seq` where `wallet_id` is the wallet **or** by `counter_change_seq` where `counter_wallet_id` is the wallet. Both stamps are taken under the respective wallet's lock; the server never exposes `counter_change_seq`, and the row on the wire is identical in both feeds (its `change_seq` is the source scope's, opaque to the client and never compared across wallets). Indexes for both selections exist (`transactions_wallet_change_seq_index`, `transactions_counter_wallet_change_seq_index`).
- **Both wallets of a transfer are immutable**, so a record never leaves a feed it was on; there is no "exit" row to emit.
- **A wallet deletion stamps** each transaction it tombstones with the deleted wallet's next `change_seq` (the destination's column when the deleted wallet is the destination); transfers to a live wallet stay live.
- Tombstones are kept at least 90 days (ADR-0008 rule 12); `postings` rows of a tombstone are already removed, and the cascade on purge only concerns the transaction row.
