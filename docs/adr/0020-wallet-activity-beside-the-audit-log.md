# ADR-0020: Wallet activity beside the audit log

- **Status**: Accepted
- **Date**: 2026-10-02
- **Principles**: P-01, P-07, P-08, P-15
- **Supersedes**: — (extends [ADR-0008](0008-sync-changes-feed.md) and the security baseline's audit section; no rule of either changes)
- **Resolves**: how members of a shared wallet see who did what (spec 016, brief B9)

## Context

Members of a shared wallet want to see who added, edited or deleted what, who joined and who was removed. Two existing things look like homes for it and are not:

- The **audit log** (security baseline, [the Audit Entry](../security-baseline.md#the-audit-entry)) is operator-only security evidence with a fixed event vocabulary, written by Identity, whose reading is itself recorded. Turning it into a product feed would expose it to members and widen its vocabulary.
- The **sync feeds** ([ADR-0008](0008-sync-changes-feed.md)) carry records, not acts. The glossary allows exactly two scopes and eleven syncable entities; a record of an act is not one of them, and a `created_by` on a transaction says who created it, not who edited or deleted it.

Forces: P-08 (a member sees the wallet, a non-member sees nothing), the privacy commitments (a departing member's name comes off), P-15 (no amount, note, name or address in any operational record), and the cost of a third sync scope.

## Decision

1. Wallets & Ledger MUST own an append-only **Activity Entry** (`wallet_activity`): `id`, `wallet_id`, `occurred_at`, `actor_user_id` (null when the server acted), `action`, `subject_type`, `subject_id`, `role` (membership and invitation acts only).
2. An Activity Entry MUST carry identifiers only: no amount, note, record name or address. The client resolves `subject_id` from its local copy.
3. An entry MUST be written inside the same unit of work as the write it records, by the Wallets services that own the write (or through Wallets' `WalletActivity` application service, for Sharing), and only when the write is applied: a replay, a refused write and a no-op write nothing.
4. An Activity Entry MUST NOT be a syncable entity, MUST NOT have a `version`, `change_seq` or tombstone, and MUST NOT ride a change feed. Clients read it through `GET /wallets/{id}/activity`, newest first, cursor-paged.
5. Every active member of the wallet MAY read it (row S-07 of the permission matrix). Anyone else gets `404`.
6. The actor's display name MUST be shown only while the actor is an active member of the wallet.
7. Entries MUST be removed after 90 days by the daily purge run.
8. The audit log MUST stay operator-only and MUST NOT be read by any member-facing endpoint. The two records are written independently: a membership change writes both, for different readers.

## Alternatives Considered

### Reuse the audit log

Add member-visible events to the audit log and filter by wallet. **Rejected because** the log is operator-only by contract, its reads are recorded, its retention is 400 days and its vocabulary is fixed by the baseline; a member feed would make every wallet write an audit event.

### Derive activity from the records

Read `created_by`, `updated_at` and tombstones from the wallet's records. **Rejected because** the records do not say who edited or deleted, and a purged tombstone would erase the act with it.

### A syncable Activity entity in the wallet scope

Ride the wallet feed like a transaction. **Rejected because** the glossary fixes eleven syncable entities and two scopes, every entry would cost a `change_seq` and a tombstone for a record nobody edits, and the feed would grow with every write for no benefit to balance, authorization or conflict handling.

## Consequences

### Positive

- Members get a "who did what" view without exposing the audit log or adding a sync entity.
- It holds only identifiers, so it adds no protected attribute (security baseline).

### Negative

- Every wallet write path gains one insert. Mitigated by the narrow table and its single descending index.
- An entry is only as complete as the writers that call it: a new write path must record its activity (the activity recording test lists each writer).

### Follow-ups

- If a later feature needs live activity on the phone, extend the feed through a new ADR rather than adding a scope.
