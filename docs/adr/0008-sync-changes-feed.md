# ADR-0008: Sync: per-scope changes feed

- **Status**: Accepted — rule 7 superseded by [ADR-0016](0016-batch-upload-beside-per-record-writes.md)
- **Date**: 2026-09-19
- **Principles**: P-07
- **Supersedes**: —
- **Resolves**: —

## Context

The mobile client is offline-first. It writes transactions, budgets and categories with no network, and reconciles later. Two people can share a wallet and edit the same transaction from two devices while both are offline. The server therefore has to answer two questions reliably: *what changed since I last synced?* and *what happens when two edits collide?*

The forces:

- **The pull has to be gap-safe.** If a client's cursor advances past a row that the client never received, that row is lost until a full resync. There is no background process that would notice.
- **`updated_at` cannot be the cursor.** It comes from a clock, it is not unique, and two rows written in the same microsecond force a choice between skipping one and re-sending the whole boundary forever.
- **A single global Postgres sequence cannot be the cursor either, and this is the non-obvious part.** A sequence hands out numbers at `nextval()` time, not at commit time. Transaction A can call `nextval()` and get 100, then transaction B starts, gets 101 and commits; A commits a moment later. A reader that pulls in between sees 101 but not 100, advances its cursor to 101, and **never sees row 100** — it is permanently invisible to that client even though it is committed and live. Sequences are also non-transactional: a rollback burns the number, leaving holes a reader cannot distinguish from "not committed yet".
- **Writers in a wallet are already serialised.** The balance cache (ADR-0005) takes `lockForUpdate` on the wallet row inside the write transaction. A counter on that same row costs nothing extra and inherits commit ordering from the lock.
- **Conflict resolution has a product answer, not just a technical one.** The client owns the merge UI; it knows what the user typed and can show it. The server does not, and a silent server-side merge produces rows that neither user wrote.
- Entity ids are client-generated UUIDv7 and writes are idempotent upserts ([ADR-0002](0002-identifiers-uuidv7.md)), so the same write can arrive twice without creating two rows.
- Postgres is the only engine ([ADR-0001](0001-database-engine.md)), so `SELECT … FOR UPDATE` semantics are the ones assumed here.

Wire shapes — the syncable envelope, the upsert status table, the error envelope — are fixed in `specs/001-architecture-constitution-adrs/contracts/http-conventions.md` and are not restated here.

## Decision

1. Sync MUST be organised into exactly two kinds of feed scope:
   - a **wallet scope**, carrying wallet-owned entities: transactions, budgets, recurring rules, wallet members and wallet-scoped categories;
   - a **user scope**, carrying the user's own entities: profile, preferences, devices, membership grants and revocations, and user-scoped categories.
2. Each scope row — a row in `wallets` or in `users` — MUST hold a `change_seq bigint` counter. Every syncable entity MUST carry its own `change_seq bigint not null` plus `version integer` and `deleted_at`.
3. Every write to an entity in a scope MUST, inside the same database transaction: take `lockForUpdate` on the scope row, increment that row's `change_seq`, and stamp the new value onto the entity. A write MUST NOT assign `change_seq` any other way.
4. `change_seq` MUST NOT be accepted from the client. It is server-assigned, and a client-supplied value is ignored.
5. Because all writers in a scope hold the same row lock, `change_seq` values in a scope MUST be in COMMIT order. A global sequence or an `updated_at` value MUST NOT be used as the cursor.
6. Pull MUST be `WHERE <scope column> = ? AND change_seq > :cursor ORDER BY change_seq LIMIT n`, and MUST include tombstones. It MUST NOT filter out soft-deleted rows.
7. Push MUST be a per-entity `PUT /…/{id}` whose body carries the client's last-seen `version` (omitted or `0` means create).
8. On a `version` mismatch the server MUST reject the **whole** write with `409 VERSION_CONFLICT` and return the current entity — including when the current entity is a tombstone — in the envelope's `current` field.
9. The server MUST NOT merge fields. It MUST NOT apply part of a rejected write, and MUST NOT resolve a conflict by picking a value. Any field-level last-write-wins merging happens on the client, before it resends against the new `version`.
10. A replay of a write whose result is already stored MUST be idempotent: the same response, no `version` bump, no `change_seq` bump.
11. A delete MUST be a tombstone: set `deleted_at`, bump `version`, bump `change_seq`. It MUST NOT remove the row.
12. Tombstones MUST be retained for at least 90 days, then purged.
13. A pull with a cursor older than the scope's purge horizon MUST be answered with `410 SYNC_CURSOR_EXPIRED`. The client then fully resyncs that scope. The server MUST NOT silently serve a partial feed to an expired cursor.
14. Undelete MUST be an explicit write carrying `deleted_at: null` plus the current `version`, authorised exactly like an update.
15. A membership revocation MUST appear in the affected user's **user** feed. After it, any pull or push on that wallet by that user MUST return `404 NOT_FOUND` (not 403 — see [ADR-0006](0006-wallet-scoped-authorization.md)).
16. The guarantees the server offers, and the only ones a client may assume, are: **ordered within a scope**, **at-least-once** delivery, **idempotent replays**. There MUST NOT be any assumption of ordering *across* scopes; a client that needs a cross-scope invariant resolves it locally.

## Alternatives Considered

### Global sequence or `updated_at` as the cursor

One monotonic counter for the whole database, or ordering by the row's last-modified timestamp, removes the per-scope bookkeeping. **Rejected because** neither is commit-ordered. A sequence number is taken at `nextval()` time, so a transaction that started earlier can commit later with a *lower* number; a client that pulled in between advances its cursor past that number and never sees the row again. `updated_at` has the same hole plus clock dependence and ties — two rows sharing a microsecond mean either skipping one or re-reading the boundary on every pull. Both fail exactly under the concurrent writes a shared wallet is designed to have.

### CRDTs

Represent each entity as a convergent replicated data type so merges are automatic and conflict-free. **Rejected because** the cost is paid on every entity, every write and every client, while the contention it buys resilience against is two people occasionally editing the same transaction. It also gives up a reviewable audit story — a money row's value becomes the output of a merge function rather than something a user typed — and it makes the wire format hard to evolve.

### Server-side field merge

Accept a stale write, merge the fields that do not overlap, and return `409` only when the same field was changed on both sides. **Rejected because** it produces rows that neither user wrote (amount from one device, category and note from another) and the user is never shown that it happened. It also makes the server's behaviour depend on field-level diffing it cannot validate as a whole — a merged row can break an invariant that each input satisfied. Settled in clarification Q2.

### Silent field-level last-write-wins

Let the newest write for each field win, with no `409` at all. **Rejected because** it loses edits with no signal to either user, which for money data is a correctness bug reported as "the app changed my numbers". The client may still apply field-level LWW locally, because there it can show the user what it did before resending.

## Consequences

### Positive

- The cursor is gap-safe by construction: one lock per scope means `change_seq` is assigned in commit order, so `change_seq > cursor` cannot skip a committed row.
- The lock is free. ADR-0005 already takes `lockForUpdate` on the wallet row for the balance cache, so the counter rides along in the same transaction with no extra contention.
- Pull is a single index scan per scope, and paging is a plain `LIMIT` with the last `change_seq` as the next cursor.
- Deletes propagate. A tombstone is an ordinary feed row, so an offline client learns about a deletion the same way it learns about an edit.
- Conflicts are visible. `409` plus the current entity gives the client everything it needs to merge and show the user what happened.
- At-least-once plus idempotent upserts means a retry after a timeout is always safe, so the client never has to decide whether a failed request was applied.

### Negative

- Writes in one wallet are serialised on one row. For two-person wallets this is irrelevant; a wallet with many concurrent writers would make that row a hotspot, and the transaction holding the lock must stay short.
- Two scopes mean a client tracks two cursors and cannot order events between them. A membership grant (user feed) and the first transaction in the granted wallet (wallet feed) have no defined relative order.
- Tombstones keep dead rows for 90 days, so tables grow beyond live data and the purge job is load-bearing — if it stops, the cursor horizon silently stops moving.
- A client offline for more than 90 days pays a full resync of the scope.
- Rejecting the whole write on conflict pushes merge work onto the client, including the UI for showing the user a conflict.
- Every write path must remember the lock-and-increment step. A write that forgets it produces a row invisible to sync, which is not detectable from the row itself.

### Follow-ups

- The purge job and the retention horizon it enforces are implemented with the first syncable feature; the horizon value has to be readable by the pull endpoint so it can decide `410`.
- Revisit if a wallet ever needs write throughput that one row lock cannot carry — a per-scope counter table sharded by entity type would be the next step, at the cost of ordering guarantees.
