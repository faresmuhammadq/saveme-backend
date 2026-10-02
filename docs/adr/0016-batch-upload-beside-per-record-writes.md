# ADR-0016: Batch upload beside per-record writes

- **Status**: Accepted
- **Date**: 2026-10-02
- **Principles**: P-06, P-07
- **Supersedes**: [ADR-0008](0008-sync-changes-feed.md) rule 7 only; every other rule of ADR-0008 stands
- **Resolves**: brief B14 collision 2 (batch push), client brief C14 "Needs from the backend" (spec 013)

## Context

[ADR-0008](0008-sync-changes-feed.md) rule 7 says push MUST be a per-entity `PUT /…/{id}`, and spec 003 put batch writes out of scope. An offline phone that comes back with dozens of edits then pays one round trip per record on a poor mobile network. On 2026-09-25 the owner decided that **upload is a batch** with a result per item, that **each item obeys exactly the rules a single write does**, and that the phone, never the server, resolves clashes (P-07 stands). The batch changes how writes travel, not what a write is.

Forces:

- P-07 rules 2–4 and ADR-0008 rules 8–10: every write carries its last-seen `version`; a stale write is refused whole with the current record; the server never merges; a replay is idempotent.
- P-06 rule 2: syncable records are created by an idempotent upsert addressed by a client-generated identifier.
- The client's engine sends whole records, one waiting change per record, and must keep sending unrelated records when one clashes.
- Spec 003 reserves `Idempotency-Key` for the three non-upsert money operations; a stored replay of a batch would copy financial records and encrypted attributes into a second store.

## Decision

1. Push MAY be either a per-record write at the record's own path (`PUT`, `PATCH`, `DELETE`) or `POST /sync/batch`. Per-record writes MUST remain available and unchanged.
2. A batch item MUST be exactly one per-record write — its type, operation, identifier(s) and the body that write takes — and MUST be applied with exactly that write's rules: authorization, verification gate, validation, `version` check, replay, tombstone handling and refusals. A batch MUST NOT accept a write no per-record endpoint accepts.
3. Each item's result MUST carry the status and body its per-record write would have answered: the record when applied, the problem otherwise, with `current` on `VERSION_CONFLICT`.
4. Items MUST be independent: each in its own unit of work, committed or refused before the next starts. A refusal or failure of one item MUST NOT undo or block another. A batch MUST NOT be all-or-nothing.
5. Items MUST be applied one at a time in array order, and results returned in the same order, one per item.
6. A batch MUST carry 1 to 50 items; an envelope outside that range or otherwise unusable MUST be refused whole with nothing applied.
7. A batch MUST NOT require or store an `Idempotency-Key`. It is idempotent because every item is idempotent by identifier and `version` (ADR-0008 rule 10).
8. The server MUST NOT merge within or across items (ADR-0008 rule 9 applies to every item).

## Alternatives Considered

### Keep per-record writes only

**Rejected because** the owner decided upload is a batch, and one round trip per record makes a large offline backlog slow and fragile on mobile networks.

### All-or-nothing batches, or all-or-nothing per scope

**Rejected because** one stale transaction would hold back every other offline edit, the client brief requires that one clash never blocks unrelated changes, and an atomic multi-record unit of work exists for no single write.

### A required `Idempotency-Key` with a stored, replayed response

**Rejected because** each item is already idempotent by identifier and version, and a 24-hour stored response would keep a second copy of financial records and of the encrypted profile display name outside their tables. Cost accepted: a retry after a lost response can report a clash that a stored replay would not, exactly as a retried single `PUT` does.

### Server-side field-level last-write-wins for equal versions (the brief's proposal)

**Rejected because** P-07 rule 4 and ADR-0008 rule 9 are non-negotiable and the owner withdrew it on 2026-09-25.

## Consequences

### Positive

- A phone sends its whole backlog in a few requests and learns, record by record, what was stored, what clashed and why something was refused.
- No second write path: every rule a single write enforces holds for an item by construction.
- The wave-0 client engine, built on per-record writes, keeps working unchanged.

### Negative

- A batch can partially succeed; the client must handle every item's outcome, not one status.
- A long batch holds each scope lock only for its own item, but a 50-item batch can take several seconds end to end.
- Without a stored response, a retry after another device's edit reports a clash the first attempt did not.

### Follow-ups

- Later syncable writes (budgets, recurring rules, occurrences, invitations) join the batch's type list in their own specs, inside `v1`.
