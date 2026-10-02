# Architecture Decision Records

Each file records one decision: why it was needed, what was chosen, what was rejected, and what it costs. The [constitution](../../.specify/memory/constitution.md) states the enforceable rule; the ADR carries the reasoning. Where they overlap, the constitution wins.

New records use [`template.md`](template.md) and take the next free number.

## Status legend

| Status | Meaning |
|---|---|
| `Proposed` | Under discussion. Decision text may still be edited. |
| `Accepted` | In force. Binding on every feature plan. |
| `Rejected` | Considered and not adopted. Kept for the record. |
| `Deprecated` | No longer relevant; nothing replaces it. |
| `Superseded by ADR-NNNN` | Replaced. The body stays as written. |

**An Accepted ADR is never rewritten.** To change a decision, write a new ADR that supersedes it (see [Superseding a decision](#superseding-a-decision)).

## Index

| ADR | Title | Status | Date | Principles |
|---|---|---|---|---|
| 0001 | [Database engine](0001-database-engine.md) | Accepted | 2026-09-19 | P-16 |
| 0002 | [Identifiers: UUIDv7](0002-identifiers-uuidv7.md) | Accepted | 2026-09-19 | P-06 |
| 0003 | [Money as integer minor units](0003-money-minor-units.md) | Accepted | 2026-09-19 | P-03 |
| 0004 | [Ledger: transactions and postings](0004-ledger-transactions-and-postings.md) | Superseded by 0012 | 2026-09-20 | P-01, P-04 |
| 0005 | [Derived balance with cached column](0005-derived-balance-with-cache.md) | Accepted | 2026-09-19 | P-05 |
| 0006 | [Wallet-scoped authorization](0006-wallet-scoped-authorization.md) | Accepted | 2026-09-19 | P-08 |
| 0007 | [Entitlements from provider-agnostic subscriptions](0007-entitlements-from-subscriptions.md) | Superseded by 0013 | 2026-09-19 | P-09, P-10 |
| 0008 | [Sync: per-scope changes feed](0008-sync-changes-feed.md) | Accepted | 2026-09-19 | P-07 |
| 0009 | [Domain events after commit](0009-domain-events-after-commit.md) | Accepted | 2026-09-19 | P-11 |
| 0010 | [Observability](0010-observability.md) | Accepted | 2026-09-19 | P-15 |
| 0011 | [Time and timezones](0011-time-and-timezones.md) | Accepted | 2026-09-19 | P-12 |
| 0012 | [Postings against wallet and category accounts](0012-postings-wallet-and-category-accounts.md) | Accepted | 2026-09-20 | P-01, P-04 |
| 0013 | [Entitlements with a manual channel, and Free as no subscription](0013-entitlements-with-manual-channel.md) | Accepted | 2026-09-28 | P-09, P-10 |
| 0014 | [Platform-owned categories beside user-scoped ones](0014-platform-owned-categories.md) | Accepted | 2026-10-02 | P-01, P-04, P-07 |

## Reading order

Not numerical order. Follow the thread you need:

- **The money model** — [0012](0012-postings-wallet-and-category-accounts.md) (what a transaction is; supersedes 0004) → [0014](0014-platform-owned-categories.md) (who owns a category) → [0003](0003-money-minor-units.md) (how an amount is represented) → [0005](0005-derived-balance-with-cache.md) (where a balance comes from).
- **Offline and sync** — [0002](0002-identifiers-uuidv7.md) (who generates an id) → [0008](0008-sync-changes-feed.md) (feeds, cursors, conflicts) → [0011](0011-time-and-timezones.md) (dates that do not shift).
- **Access and money-in** — [0006](0006-wallet-scoped-authorization.md) (who may act on a wallet) → [0013](0013-entitlements-with-manual-channel.md) (what their plan allows; supersedes 0007).
- **Runtime** — [0001](0001-database-engine.md) (engine) → [0009](0009-domain-events-after-commit.md) (events) → [0010](0010-observability.md) (logs and tracing).

## Superseding a decision

1. Write a new ADR with the next free number and `**Supersedes**: [ADR-NNNN](…)`.
2. In the old file, change only the status line to `Superseded by [ADR-NNNN](…)`. Leave the body as written.
3. Keep both rows in the index above.
4. Update the affected principle's `**ADRs**:` line in the constitution in the same change.
