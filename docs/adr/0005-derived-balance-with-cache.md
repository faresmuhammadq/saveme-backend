# ADR-0005: Derived balance with cached column

- **Status**: Accepted
- **Date**: 2026-09-19
- **Principles**: P-05
- **Supersedes**: —
- **Resolves**: —

## Context

A wallet's balance is the number the user checks most often. It appears on the wallet list, on the home screen and next to every transaction, so it is read far more than it is written.

The ledger already defines what the balance *is*: a wallet's postings sum to its movement, on top of whatever the wallet started with ([ADR-0004](0004-ledger-transactions-and-postings.md)). Summing is correct by construction — it cannot drift, because there is nothing to drift from. It is also a per-wallet aggregate over a growing table, run once per wallet on a list screen.

Two forces pull against each other:

- Correctness: anything that gates a write, authorizes an action or is reported as a total must not be able to be wrong. A cached number can be wrong.
- Latency: a wallet list that aggregates the posting table per row degrades as history grows, and it degrades first for the most active users.

Shared wallets add concurrency: two members can post to the same wallet at the same time, so a cache update that reads-then-writes without a lock will lose one of the two increments. The sync design already takes a `lockForUpdate` on the wallet row to serialise the scope's `change_seq` ([ADR-0008](0008-sync-changes-feed.md)), which means a correctly-serialised cache update is available at no extra lock.

Liability wallets (credit cards, money owed to a friend) can and should be negative, and a cash wallet can go negative because the user recorded reality in the wrong order or is simply overdrawn.

## Decision

1. The truth of a wallet's balance MUST be `wallets.opening_balance_minor + Σ postings.amount_minor WHERE account = wallet`. Nothing else is the balance.
2. `wallets.balance_minor` MUST be treated as a **cache** of that expression. It is a display convenience and carries no authority.
3. The cache MUST be updated inside the **same database transaction** as the posting change that moves it. A write that changes postings and commits without updating the cache in that transaction is a bug.
4. That update MUST be performed while holding `lockForUpdate` on the wallet row — the same lock that serialises the wallet scope's sync counter in [ADR-0008](0008-sync-changes-feed.md). A cache update outside that lock MUST NOT be written.
5. No invariant check, authorization decision, report total or reconciliation MAY read `wallets.balance_minor`. Each of those MUST recompute from postings.
6. A scheduled `wallets:verify-balances` job MUST recompute every wallet's balance from postings, MUST log any drift at `warning` level including the wallet id, and MUST repair the cached column to the recomputed value.
7. Negative balances MUST NOT be blocked at write time. There is no minimum-balance check, no overdraft rejection and no confirmation gate in the API. Whether a negative number is alarming is a **presentation** concern for the client.
8. Liability-type wallets MUST be stored negative and MUST be displayed as the amount owed. The stored sign is the ledger's; the displayed sign is the UI's ([ADR-0004](0004-ledger-transactions-and-postings.md), rule 10).
9. Both the truth expression and the cache MUST be integer minor units with the wallet's ISO 4217 currency ([ADR-0003](0003-money-minor-units.md)). No float or decimal value participates in either.

## Alternatives Considered

### No cache — always sum the postings

Drop `wallets.balance_minor` entirely and compute every balance by aggregating postings on read. **Rejected because** the wallet list and home screen would run one aggregate per wallet on every open, and the cost grows with the user's history, so the product gets slower exactly for the users who use it most. The correctness argument for it is already satisfied: rule 5 makes summation the only source for anything that matters, so the cache buys latency without buying risk.

### Asynchronous, event-driven cache update

Update `balance_minor` in a queued listener on the posting-changed domain event ([ADR-0009](0009-domain-events-after-commit.md)), keeping the write path short. **Rejected because** the user who just recorded a transaction would reload and see the old balance until the job runs — the single most confusing moment the app can produce. It also inherits the MVP's no-outbox risk: a lost event leaves the cache silently stale until the next verify run, and on a shared wallet two concurrent listeners would need the wallet lock anyway, so the saving is illusory.

### Per-period balance snapshots

Store a closing balance per wallet per month and compute the current balance from the latest snapshot plus the postings after it. **Rejected because** it is premature: it adds a second derived structure, a closing job and a backdating path (a transaction dated into a closed period invalidates every snapshot after it) to solve a scale problem the MVP does not have. The verify job plus the in-transaction cache covers the same read pattern with one column.

## Consequences

### Positive

- Reads are a single column on the wallet row, so list screens do not aggregate.
- The cache can never be *authoritatively* wrong: rule 5 means a stale value can at worst show a wrong number briefly, never permit a wrong decision.
- The cache update is free of extra contention — it reuses the wallet row lock the sync counter already takes.
- Drift is detected and repaired on a schedule, and every occurrence leaves a `warning` log with the wallet id to investigate.
- Negative balances need no write-path handling at all, which keeps liability wallets and overdrawn cash wallets on the same code path.

### Negative

- Every posting write now touches the wallet row, so concurrent writers to one shared wallet serialise on that lock.
- The same number exists in two places, and a contributor who reads the cached column in a report or a policy introduces a correctness bug that tests must catch rather than the type system.
- Reports and reconciliation pay the full aggregate cost by design, so report latency is not improved by the cache.
- The `wallets:verify-balances` job is operational surface: it must be scheduled, monitored and its `warning` logs actually read.

### Follow-ups

- Define the `wallets:verify-balances` schedule and the alerting threshold on its drift warnings when observability is wired up ([ADR-0010](0010-observability.md)).
- Add a test that asserts no policy, report or invariant path reads `wallets.balance_minor`; if a static check can express rule 5, add it to the F-1 arch tests.
- Revisit snapshots if the verify job's full recomputation, or report aggregation, becomes too slow on real history.
