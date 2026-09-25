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
