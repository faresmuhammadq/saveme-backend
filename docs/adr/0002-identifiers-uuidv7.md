# ADR-0002: Identifiers: UUIDv7

- **Status**: Accepted
- **Date**: 2026-09-19
- **Principles**: P-06
- **Supersedes**: —
- **Resolves**: —

## Context

The app is offline-first. A user records a transaction on a plane, edits it, attaches it to a budget, and only later syncs. That means the client creates entities and relates them to each other before the server has ever seen them, so the identifier has to exist at creation time on the device.

Two consequences follow. First, the server cannot be the source of IDs for syncable entities, or the client would need a temporary-ID table and a remapping pass over every local reference after each sync. Second, a create has to be replay-safe: a phone on a bad connection will resend a request whose response it never received, and the second attempt must not produce a duplicate transaction.

A client-chosen ID is also attacker-chosen. Someone can `PUT` an ID that already exists in another user's wallet. The response must neither overwrite that row nor reveal that it exists.

Randomly distributed identifiers have a cost at the storage layer: inserts land at random points in the primary-key B-tree, fragmenting pages and dirtying more of them per commit. UUIDv7 puts a millisecond timestamp in the high bits, so inserts append.

That embedded timestamp is tempting and unreliable. Devices have wrong clocks and users change them. Nothing in the system may take a client-supplied timestamp as truth.

PostgreSQL (ADR-0001) stores any UUID version in the same native 16-byte `uuid` column, and Laravel 13's `HasUuids` trait generates UUIDv7 by default.

## Decision

1. Every primary key in the application MUST be a UUIDv7, stored in a native PostgreSQL `uuid` column. Auto-incrementing integer primary keys MUST NOT be used.
2. In JSON, identifiers MUST be lowercase hyphenated UUID strings.
3. Entities created only by the server MUST obtain their ID from Laravel's `HasUuids` trait, which generates UUIDv7 by default in Laravel 13. Such code MUST NOT generate UUIDs of another version.
4. Syncable entities MUST accept a client-generated UUID in the URL. Creation is `PUT /…/{id}`, which is an upsert: no row with that ID → create (201); an existing row of the same entity type that the caller is authorized to write → update, requiring the caller's `version` per ADR-0008 (200).
5. If the supplied ID exists but belongs to a different owner or scope, or to a different entity type, the server MUST respond `404 NOT_FOUND`. It MUST NOT overwrite the existing row, and MUST NOT disclose its existence, its type, or its owner through the status code, the message, or a distinguishable timing.
6. The server MUST validate that a supplied UUID is version 7 and reject any other version, or a malformed value, with `422 INVALID_ID`.
7. The timestamp embedded in a UUID MUST NOT be used for ordering, sync cursors, business dates, expiry, or any authorization decision. It is an index-locality property only. Sync ordering comes from the per-scope `change_seq` counter in ADR-0008, and business dates are explicit `date` fields (ADR-0011).
8. An identical replay of an already-applied write MUST return the current entity with 200 rather than creating a duplicate or failing.

## Alternatives Considered

### Bigint auto-increment with client temporary IDs

The server assigns the real ID on first sync, and the client keeps a map from its temporary local ID to the server ID, rewriting every local reference afterwards. **Rejected because** the remapping pass has to run over every foreign key the client holds, must itself be crash-safe mid-sync, and turns every offline-created relationship into a two-phase operation; it also makes a retried create indistinguishable from a second create, which is exactly the duplicate-transaction bug the offline-first model must not have.

### ULID

Also time-ordered and generatable on the client, with a shorter, case-insensitive text form. **Rejected because** PostgreSQL has no native ULID type, so it would be stored as a 26-character string — larger indexes, string comparison, and a custom cast — while UUIDv7 gives the same lexical time ordering in the native 16-byte `uuid` column and is a published standard (RFC 9562) that both platforms' libraries already implement.

### UUIDv4

The familiar choice, and Laravel supports it directly. **Rejected because** its full randomness scatters inserts across the primary-key B-tree, fragmenting pages and increasing write amplification as tables grow, with no compensating benefit: v7 is equally opaque for anything the client may rely on, because rule 7 forbids reading its timestamp.

## Consequences

### Positive

- A client can create entities and link them together fully offline, with no temporary IDs and no remapping pass.
- Retries are naturally idempotent: the same `PUT /…/{id}` either creates once or updates, so a lost response costs nothing.
- Inserts append to the primary-key index instead of scattering, keeping write amplification low as transaction tables grow.
- Guessing or probing another user's ID yields a plain 404, identical to an ID that does not exist.
- Native `uuid` columns keep keys at 16 bytes with native comparison.

### Negative

- ID collision and ownership checks now live on the write path of every syncable entity, and each one needs a test proving the foreign-ID case returns 404 without a write.
- IDs are opaque and unordered to a human reader: no "transaction #412" in support conversations, and debugging needs the explicit `created_at`/`change_seq` columns rather than an eyeballed sequence.
- Rule 7 is a rule that code can silently violate — reading the timestamp out of a UUID "just for sorting" looks harmless and is not — so it needs an automated check rather than review alone.
- 16-byte keys are larger than a bigint, costing some space in every index and foreign key.

### Follow-ups

- **F-1**: architecture tests — assert that models use `HasUuids`, that migrations declare `uuid` primary keys rather than `id()`, and that no code parses a timestamp out of a UUID.
- Revisit if a future entity genuinely requires a dense sequential identifier (for example a legally numbered invoice series), which would be a separate, server-assigned attribute rather than a change to primary keys.
