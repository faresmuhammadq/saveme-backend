# ADR-0021: Scope heads, and member names re-sent on wallet feeds

- **Status**: Accepted
- **Date**: 2026-10-02
- **Principles**: P-07, P-08, P-11
- **Supersedes**: — (extends [ADR-0008](0008-sync-changes-feed.md) and [ADR-0017](0017-membership-on-the-members-user-feed.md); replaces only ADR-0017's consequence "a member's rename alone does not re-stamp their memberships"; no rule of either changes)
- **Resolves**: client gaps from `saveme-kmp` spec 009 §9 (spec 017, B14a)

## Context

The phone adopted the two change feeds of spec 013 and found two gaps.

- **No way to know which wallet feeds moved.** A person in N wallets pays 1 + N feed reads per refresh, most of them empty. Cursors are opaque (api-conventions), so the phone cannot compare its place with anything the server says.
- **Stale display names.** A Wallet Member row renders its member's display name at read time, but a rename writes only the profile, in the user scope. Partners re-receive the membership only when it changes for another reason, so their copy keeps the old name. ADR-0017 accepted this as a consequence.

Forces:

- ADR-0008 rules 3 and 5: a position is meaningful only within one scope's counter. The wallet scope's counter *is* `wallets.change_seq`, so the wallet row always sits at its scope's head.
- ADR-0017 rule 5, lock order: categories, then wallets ascending, then users ascending. A profile save holds its user row lock; it must not then take wallet locks.
- P-08 rule 3: nothing may reveal a wallet the caller does not belong to.
- P-11: cross-module listeners are queued, idempotent, and run after commit.
- The sync contract is frozen in `v1`: only additive changes.

## Decision

1. The server MUST offer `GET /sync/wallets/heads`: for the caller, one entry per non-deleted wallet with an active membership of theirs, carrying only `wallet_id`, the wallet's **scope head** (`change_seq`, the scope counter) and its **purge horizon** (`purged_change_seq`). It is a paged collection with no filters or sorts.
2. Every feed page MUST state `meta.sync_position`, the integer position its `sync_cursor` resumes from. The cursor MUST stay opaque; the position is the comparable value, never sent back.
3. A head MUST be read as committed: it is never ahead of what a feed read returns.
4. When a person's stored display name changes, every live membership of theirs in a non-deleted wallet MUST be re-stamped from that wallet's counter under that wallet's lock, with no change to its `version`, `updated_at` or `member_change_seq`. It MUST NOT appear again on the person's own user feed.
5. The re-stamp MUST run after the profile commit, as queued work: one unit of work per wallet holding that wallet's lock only (never a user-row lock), wallets in ascending id, a bounded number per run with a continuation. It MUST be idempotent and MUST NOT write an audit or activity entry.
6. A restore and an edit MUST NOT be combined into one batch item. The contract instead states that an applied restore answers `version + 1`, so a `restore` item followed by an `upsert` item at that version is a safe pair (ADR-0016 rule 2 stands).

## Alternatives Considered

### Each membership on the user feed carries its wallet's current counter

**Rejected because** every wallet write would have to stamp a user-scope row for every member — the per-member stamping ADR-0017 already rejected — and take user locks on every wallet write.

### Heads in the user feed's meta

**Rejected because** it couples two scopes in one response, grows every user-feed page with a list of every wallet, and does not page with the feed.

### Compare the head with the held wallet record's `change_seq`

**Rejected because** a phone that applies its own write's answer holds a wallet record newer than its feed position and would skip the other members' changes in between.

### Re-stamp inside the profile save

**Rejected because** the save holds the user row lock and would then take wallet locks, breaking the lock order, and a person in many wallets would make a rename slow and wide.

### Copy the display name into the membership row

**Rejected because** it duplicates an encrypted protected attribute into a second table under another key, and the re-send is still needed.

### A combined "restore with edit" batch operation

**Rejected because** no per-record write accepts it (ADR-0016 rule 2); the PATCH refuses an edit of a tombstone by design.

## Consequences

### Positive

- A quiet refresh costs two requests (user feed, heads) whatever the number of wallets.
- Partners see a member's new name on their next refresh once the queue has run.
- Every change is additive; existing clients notice nothing.

### Negative

- A rename costs one short write per wallet the person belongs to, plus one extra wallet row on each of those feeds.
- Names reach partners only after the queue runs; with the queue down they stay stale until it runs.
- The phone must store one integer beside each cursor.

### Follow-ups

- A user-scope head, if the client wants a one-request quiet refresh.
- Any later path that changes or erases a display name (B15) must publish `ProfileUpdated` naming `display_name`.
