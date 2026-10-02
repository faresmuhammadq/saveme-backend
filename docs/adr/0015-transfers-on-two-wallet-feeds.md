# ADR-0015: A transfer is one record on two wallet feeds

- **Status**: Accepted
- **Date**: 2026-10-02
- **Principles**: P-04, P-07, P-08
- **Supersedes**: — (extends [ADR-0008](0008-sync-changes-feed.md) and [ADR-0012](0012-postings-wallet-and-category-accounts.md); no rule of either changes)
- **Resolves**: how a transfer is represented on the wire and in sync (spec 012, brief B6, client brief C6)

## Context

[ADR-0012](0012-postings-wallet-and-category-accounts.md) rule 1 and P-04 rule 3 make a transfer **one** transaction with two wallet postings, never two linked transactions. [ADR-0008](0008-sync-changes-feed.md) gives every syncable record exactly one scope: a wallet-scoped record rides its wallet's feed, ordered by that wallet's `change_seq` counter, stamped under that wallet's row lock.

A transfer touches two wallets, and the two may have different members: a person can move money from their private Checking into a household wallet they share as an editor. The household's other members must see the transfer in that wallet's list and, once B14 ships, in that wallet's feed — otherwise their offline copy of the household wallet shows a balance that moved with no record explaining it. Yet the record can carry only one scope key and one `change_seq`.

Forces:

- P-04 rule 3: never two linked records.
- ADR-0008 rules 3 and 5: a feed is commit-ordered only if every record on it is stamped from that feed's counter, under that counter's row lock.
- P-08 rule 2: a write is authorised against every wallet it touches.
- Spec 003: the wire record is the same wherever it appears; postings are never exposed.

## Decision

1. A transfer MUST be one Transaction record with `kind: transfer`, `wallet_id` = the **source** wallet (its scope key and home), and `counter_wallet_id` = the **destination**. Money always moves out of `wallet_id` into `counter_wallet_id`.
2. A transfer MUST appear in the lists of both wallets, and B14 MUST carry it on both wallets' feeds, as the identical wire record.
3. Every write to a transfer MUST lock both wallet rows (ascending identifier, after any category rows, [ADR-0014](0014-platform-owned-categories.md) rule 10) and MUST stamp the record twice: `change_seq` from the source wallet's counter (on the wire) and a second value from the destination wallet's counter (`counter_change_seq`, server-internal, never on the wire).
3a. A transfer's two wallets MUST NOT change after creation (`wallet_id` is the path, `counter_wallet_id` is immutable): a record never leaves a feed it has appeared on, so no feed needs an "exit" row. Moving a transfer to another wallet is a delete plus a new record.
4. B14's wallet feed for wallet W MUST select records with `wallet_id = W` by `change_seq` and records with `counter_wallet_id = W` by `counter_change_seq`, merged in counter order. A cursor stays opaque; the client never compares `change_seq` across wallets.
5. A transfer is readable and writable under either wallet's path. A write MUST be authorised against both wallets (P-08 rule 2), except that a wallet already deleted is no longer authorised against: such a transfer stays live with its postings, can be deleted through its live wallet, and can no longer be edited or restored.
6. The scope key remains exactly one (`wallet_id`); `counter_wallet_id` is a reference, not a second scope key, so api-conventions' "exactly one scope key" holds.

## Alternatives Considered

### Two linked records, one per wallet

Each wallet gets its own record, linked by a shared transfer id. **Rejected because** P-04 rule 3 forbids it, and because the two records drift under edit, delete and offline sync: one side edited offline while the other is deleted produces a half-transfer that no server rule can reconcile without merging (P-07 rule 4).

### One record on the source feed only

The destination wallet learns only through its own balance. **Rejected because** a shared destination's other members would see their balance move with no record explaining it, and their offline balance projection (balance + pending local changes, C6) would be wrong until a full refresh.

### A per-transfer projection on the destination (like Category Reference)

Publish a read-only "incoming transfer" record into the destination scope. **Rejected because** it adds a second record type for the same fact, with its own version and tombstone lifecycle, to solve what a second sequence stamp solves on the original record.

## Consequences

- One record, one version, one conflict: two members editing the same transfer from its two wallets conflict exactly like any other edit.
- B14's feed query is a union of two indexed selections per wallet; both are bounded by the wallet's counter.
- Correcting a transfer's destination costs the client a delete and a re-create; in exchange no record ever has to be withdrawn from a feed it was on.
- Deleting a wallet never rewrites another wallet's balance; the cost is a transfer pointing at a deleted wallet, which clients render as such.
