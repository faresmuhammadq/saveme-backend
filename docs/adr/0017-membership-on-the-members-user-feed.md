# ADR-0017: A membership rides its member's user feed

- **Status**: Accepted
- **Date**: 2026-10-02
- **Principles**: P-07, P-08
- **Supersedes**: — (extends [ADR-0008](0008-sync-changes-feed.md) rules 1 and 15 and [ADR-0014](0014-platform-owned-categories.md) rule 10; no rule of either changes)
- **Resolves**: how a phone learns it gained or lost a wallet (spec 013, brief B14)

## Context

[ADR-0008](0008-sync-changes-feed.md) rule 1 puts "membership grants and revocations" in the **user** scope, and rule 15 requires a revocation to appear in the affected person's user feed. The glossary and spec 010 make Wallet Member a **wallet-scoped** record: it carries `wallet_id`, is stamped from the wallet's counter and rides that wallet's feed. A phone that only reads the wallets it already knows can never discover a wallet it was just added to, and a phone that has lost access gets only a `404` on that wallet's feed, with nothing in its own feed saying why.

[ADR-0015](0015-transfers-on-two-wallet-feeds.md) solved the same shape for transfers: one record, one scope key, a second stamp from a second scope's counter.

Forces:

- ADR-0008 rules 3 and 5: a feed is commit-ordered only if every row on it is stamped from that feed's counter, under that counter's row lock.
- P-08 rule 3: nothing on a person's feed may reveal another person's data; only their own memberships may ride it.
- Spec 003: one scope key per record; the record on the wire is the same wherever it appears.
- ADR-0014 rule 10 fixes the lock order categories → wallets; user-scope stamps now take user-row locks too.

## Decision

1. A Wallet Member MUST stay wallet-scoped: `wallet_id` is its one scope key and its `change_seq` comes from the wallet's counter.
2. Every write that creates, changes (role) or tombstones a membership MUST also stamp it from the **member's** user-scope counter (`member_change_seq`, server-internal, never on the wire), in the same unit of work.
3. A person's user feed MUST carry exactly that person's own memberships, selected by `member_change_seq`, as the identical record the wallet feed carries. Other members' memberships MUST NOT appear on it.
4. A tombstoned membership on a person's user feed means that person no longer belongs to the wallet. It MUST be emitted whenever a membership ends: removal, leaving, or the wallet's deletion.
5. When one unit of work takes several scope locks, the order MUST be: category rows, then wallet rows in ascending identifier order, then user rows in ascending identifier order, then the records themselves.
6. The user-scope counter MUST be the `change_seq` column on the person's `users` row, incremented under that row's lock (ADR-0008 rules 2–3), for every user-scoped record and every `member_change_seq`.

## Alternatives Considered

### A separate user-scoped "wallet access" record

**Rejected because** it is a second record type with its own version and tombstone lifecycle for the fact a membership already is — ADR-0015's reasoning for transfers.

### Wallet records on every member's user feed

**Rejected because** every wallet write would need one stamp per member, and the wallet's own feed already orders it.

### Learning access loss only from a `404` on the wallet feed

**Rejected because** ADR-0008 rule 15 requires the user feed to carry it, a phone that is not reading that wallet would never learn, and a grant would stay undiscoverable.

## Consequences

- A phone reads its user feed first: each live membership is a wallet to follow, each tombstoned one a wallet to purge.
- Membership writes lock one more row per member; memberships are rare writes, and the user lock is taken last.
- Display names on membership rows are as of the read; a member's rename alone does not re-stamp their memberships.
- B9 (invitations, removal, leaving) MUST stamp through this rule; it adds no new mechanism.
