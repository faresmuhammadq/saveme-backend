# ADR-0018: Occurrences are materialised by the server and confirmed once

- **Status**: Accepted
- **Date**: 2026-10-02
- **Principles**: P-05, P-06, P-07, P-12
- **Supersedes**: —
- **Resolves**: brief B7 reconcile rows 1–3, client brief C7 "Needs from the backend" (spec 014)

## Context

A recurring rule produces dated occurrences, and a confirmed occurrence produces a real transaction. The app is offline-first: a phone may create a rule, confirm an occurrence or skip one with no network, and two members of a shared wallet may do so on two phones while the server's own job confirms automatic rules. The brief proposed occurrences as `planned` transactions created ahead; the glossary already made the Occurrence its own syncable entity, child of the rule, materialised ahead so it can be listed and confirmed offline.

Forces:

- **Duplicates are the failure that matters.** If both the phone and the server can create an occurrence for 1 November, or both can turn it into a transaction, the person pays rent twice in the books. Clients and the server have no shared clock and no shared id space for "the November rent" unless one side alone creates it.
- **P-05**: a balance is opening plus Σ postings. A "planned" transaction with postings would be in the balance; one without postings would be a second kind of transaction every sum must remember to skip.
- **P-06 rule 2** makes create an idempotent `PUT` with a client id. That rule exists so a client can create offline; a record the client never creates (the Category Reference already) is minted by the server.
- **P-07**: every write is versioned, stale writes are refused whole, the server never merges. Server housekeeping that rewrites a record turns into version conflicts on the phones that hold it.
- **ADR-0011 rule 9**: a rule's schedule is evaluated in the timezone stored on the rule, not the viewer's.

## Decision

1. Occurrences MUST be created only by the server: when a rule is written, by the scheduled job, and on an explicit materialise request. A client MUST NOT create an occurrence; a write to an occurrence id the server never created MUST be `404 NOT_FOUND`. Occurrence ids are server-minted UUIDv7.
2. Every occurrence MUST have an **occurrence key** — (rule id, scheduled date, payout flag) — unique among the rule's occurrences, live or tombstoned. Materialising a key that exists MUST NOT create a second occurrence.
3. An occurrence MUST NOT have postings. Only the Transaction it produces posts, so an upcoming occurrence never enters a balance and no "planned" transaction status exists.
4. Confirming MUST be a versioned write of the occurrence (status `confirmed`) carrying the identifier of the transaction it produces — client-generated when a member confirms, server-minted when the server auto-confirms — and MUST record that transaction in the same unit of work through the ledger's transaction service. Member confirmations and automatic ones MUST serialise on the rule and occurrence row locks and the occurrence's `version`; the second to arrive is a `409 VERSION_CONFLICT` carrying the confirmed occurrence.
5. An occurrence MUST link to at most one transaction and a transaction to at most one occurrence, both enforced by unique constraints. A confirmed occurrence MUST NOT return to another status; the produced transaction is corrected through its own endpoints.
6. A transaction recorded through the transactions endpoints MUST NOT be linked to an occurrence. A client that wants an occurrence's money recorded confirms the occurrence.
7. The server MUST NOT auto-confirm an occurrence dated before the day automatic confirmation took effect for its rule (creation, resume from pause, or switching it on), evaluated in the rule's timezone. Earlier pending occurrences wait for a member.
8. Time-derived states ("due", "missed") MUST be computed from the occurrence's date and the rule's timezone, never stored, so the passage of time causes no write and no version conflict. Stored statuses are `scheduled`, `confirmed` and `skipped`.
9. "Today" for a rule MUST be the current calendar date in the IANA timezone stored on the rule at its creation (ADR-0011 rule 9). Changing anyone's timezone MUST NOT move it.

## Alternatives Considered

### Occurrences as `planned` transactions (the brief)

Create transactions ahead with a `planned` status excluded from balances. **Rejected because** a planned transaction with postings breaks P-05, one without postings is a second kind of transaction every balance, report and budget query must remember to filter, and the glossary already fixes the Occurrence as the entity that carries per-occurrence state (skipped, paid) a gam'eya needs.

### Phone and server both materialise

Let the phone create occurrences for its own preview and upload them. **Rejected because** two generators produce two records for one due date unless their ids are derived deterministically from the key, which P-06 rule 1 (UUIDv7, time-ordered and random) rules out, and two schedules computed on two devices would still need a merge when they disagree. The phone previews dates locally without creating records.

### Phone-only generation ("the phone posts what is due")

**Rejected because** a rule must keep working when no member opens the app, an automatic salary must post on the day, and the client brief C7 asks the server to materialise.

### Confirm by recording a transaction with an `occurrence_id`

Let the phone `PUT` a transaction naming the occurrence. **Rejected because** the transaction and the occurrence would be two writes that can each succeed alone, and two phones (or a phone and the server) would race on two different transaction ids with no version to refuse the second. One versioned write on the occurrence closes that race.

### Store `due` and `missed`

**Rejected because** the server would rewrite every occurrence on its due day, and each rewrite would refuse a member's offline edit as a version conflict for no change they could act on.

## Consequences

### Positive

- At most one occurrence per due date and one transaction per occurrence, by construction and by constraint, whatever reruns, retries or races happen.
- Balances, budgets and reports keep reading postings only; nothing upcoming leaks into them.
- Every member's phone shows the same upcoming items, from the feed, in commit order.
- Automatic rules never back-post money nobody saw.

### Negative

- A phone offline since before a rule's creation cannot confirm its first occurrences until it syncs; it previews dates and may record by hand, which creates an unlinked transaction.
- Occurrences are a second record per payment; their tombstones stay while a transaction refers to them.
- A server confirmation can refuse a member's concurrent confirm as a conflict; the client must show the confirmed state, not retry blindly.

### Follow-ups

- B16 computes "due" for reminders from the same rule; it needs no stored state.
- B13 reads scheduled occurrences for forecasts through a query service.
