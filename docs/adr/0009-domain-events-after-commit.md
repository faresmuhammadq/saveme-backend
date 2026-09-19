# ADR-0009: Domain events after commit

- **Status**: Accepted
- **Date**: 2026-09-19
- **Principles**: P-11
- **Supersedes**: —
- **Resolves**: —

## Context

Modules have to react to each other without calling into each other. Recording a transaction should be able to move a budget's progress, wake a recurring rule and send a push, without the Ledger module knowing that Budgets, Recurring or Notifications exist.

The forces:

- **A listener that runs mid-transaction can act on state that never existed.** If a listener fires when the event is dispatched and the surrounding transaction then rolls back, a push has been sent and a projection written for a transaction that does not exist. Laravel's `ShouldDispatchAfterCommit` and the queue's `after_commit` setting both exist for exactly this.
- **A listener that runs mid-transaction also lengthens the lock.** Writes in a wallet hold `lockForUpdate` on the wallet row for the sync counter and the balance cache ([ADR-0008](0008-sync-changes-feed.md), [ADR-0005](0005-derived-balance-with-cache.md)). Anything slow inside that window — an HTTP call, a push, a heavy read — serialises the whole wallet behind it.
- **Events that carry Eloquent models rot.** A serialised model in a queue payload either drags a stale snapshot across the boundary or is re-fetched into a different state than the one the event described. Passing ids and scalars makes the payload mean one thing.
- **At-least-once delivery is the realistic target.** Queue workers are killed, jobs are retried. A listener that is not idempotent will double-count.
- **An outbox is the correct answer and the expensive one.** It closes the crash window between COMMIT and enqueue, at the price of a table, a relay process, its monitoring and its failure modes. The MVP has one backend, a handful of cross-module reactions, and reconciliation jobs that already exist for other reasons.

## Decision

1. A domain event MUST be a plain PHP class in `Modules/<Context>/Domain/Events`. It MUST NOT extend a framework base class that binds it to a transport.
2. An event payload MUST carry identifiers and scalars only. It MUST NOT carry Eloquent models, query builders, or any object that resolves state at deserialisation time.
3. Every event MUST carry an `event_id` (UUIDv7, per [ADR-0002](0002-identifiers-uuidv7.md)) and an `occurred_at` UTC instant (per [ADR-0011](0011-time-and-timezones.md)).
4. Every domain event MUST implement `ShouldDispatchAfterCommit`, so a rolled-back transaction discards it and it is never observed.
5. A listener that lives in a different module from the event MUST implement `ShouldQueue`. It MUST NOT run synchronously inside the dispatching transaction.
6. Queue connections MUST set `after_commit => true`, so a job pushed from inside a transaction is only enqueued once that transaction commits.
7. Every listener MUST be idempotent. Where the side effect is not naturally idempotent, the listener MUST key its de-duplication on the event's `event_id`.
8. A listener MUST NOT assume it is the only listener for an event, MUST NOT assume an ordering relative to other listeners, and MUST NOT depend on the dispatching transaction still being open.
9. The queue MUST use the `database` driver on PostgreSQL ([ADR-0001](0001-database-engine.md)) for the MVP. No broker is introduced.
10. There MUST NOT be a transactional outbox in the MVP. The accepted risk is stated below, and the revisit triggers in rule 11 are binding.
11. An outbox MUST be reconsidered — this ADR superseded rather than bent — as soon as any of the following is true:
    - an event exists whose loss causes a **money or billing inconsistency** that no reconciliation job repairs;
    - an **external integration needs guaranteed delivery**, for example an entitlement grant driven asynchronously from a Google Play RTDN notification ([ADR-0007](0007-entitlements-from-subscriptions.md));
    - **loss is observed in logs** rather than reasoned about.
12. Any derived state maintained by a queued listener MUST have a reconciliation path that can rebuild it from the source of truth without that listener ever having run.

### Accepted risk

The window is between COMMIT and the enqueue that follows it. If the process dies in that window, the transaction is durable and the event is gone. Nothing retries it, and nothing logs it — the event simply never existed from the queue's point of view.

Mitigations that make the window survivable:

- **Derived state has reconciliation jobs.** Balance verification recomputes `opening + Σ postings` and repairs drift (ADR-0005). Recurring catch-up generates occurrences that are due but missing. Projections used by reporting can be rebuilt from the ledger.
- **User-visible one-shot effects are tolerable to miss.** A missed push notification is a missed notification; the data is still correct and the client will see it on the next sync.
- **Nothing that moves money is a listener.** Postings are written in the same transaction as the transaction row, not in reaction to an event.

## Alternatives Considered

### Transactional outbox plus relay

Write the event into an `outbox` table in the same transaction as the state change, and have a relay process read the table and publish. **Rejected because** for the MVP it adds a table, a long-running relay, a delivery-ordering question, a poison-message path and its own monitoring — all to close a crash window whose consequences are currently limited to a missed push, when every piece of derived state already has a reconciliation job. Rule 11 names precisely the conditions under which this becomes the right answer.

### Synchronous in-transaction cross-module listeners

Let listeners run inline as part of the dispatching transaction, making the side effect atomic with the state change. **Rejected because** it couples modules at runtime (the Ledger write now fails when Notifications fails), and it extends the wallet row lock for the duration of every listener, serialising all writes in that wallet behind whatever the slowest listener does. It also re-introduces the failure this ADR exists to prevent in reverse: a listener with an external side effect cannot be rolled back with the transaction.

### An external broker (Kafka or SQS)

Publish events to a managed broker and consume them from there. **Rejected because** it is infrastructure the MVP does not need — one backend, one database, a small number of cross-module reactions — and it introduces a second durability boundary without an outbox to bridge it, so the crash window would still exist between COMMIT and publish. Revisiting is cheap later: listeners already only depend on the event class, not on the transport.

## Consequences

### Positive

- A rolled-back transaction can never be observed. No push, no projection row, no derived state for a write that did not happen.
- The wallet row lock is held only for the database work, so events do not lengthen the window that serialises all writes in a wallet.
- Modules stay decoupled: the publisher knows the event class, not its listeners, and a failing listener cannot fail the write.
- Id-and-scalar payloads serialise cleanly into the queue and mean the same thing whenever they are consumed.
- `event_id` gives every listener a ready-made de-duplication key, which makes at-least-once safe without inventing a key per side effect.
- No new infrastructure. The database queue runs wherever PostgreSQL runs.

### Negative

- Events can be lost in the COMMIT-to-enqueue window, silently. This is accepted, bounded by rule 11, and carried by reconciliation jobs.
- Every listener must be written idempotently, which is extra work and is easy to forget, and the failure only shows under retry.
- Effects are eventually consistent. A response can return before a budget projection reflects the write, and the client has to tolerate that.
- The database queue shares the application database, so a queue backlog is load on the same instance that serves requests.
- Reconciliation jobs become load-bearing rather than a safety net: if one stops running, drift accumulates unnoticed until it is noticed by a user.

### Follow-ups

- Reconciliation jobs (balance verification, recurring catch-up, projection rebuild) are implemented alongside the features that create the derived state they repair; each must be able to run with no event history.
- Revisit when any rule 11 trigger fires. The replacement is an outbox ADR superseding this one, not an edit to this file.
