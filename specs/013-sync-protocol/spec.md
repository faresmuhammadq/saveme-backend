# Feature Specification: Sync Protocol

**Feature Branch**: `013-sync-protocol`

**Created**: 2026-10-02

**Status**: Draft

**Input**: Brief B14 ([specs/briefs/B14-sync-protocol.md](../briefs/B14-sync-protocol.md)): "The client pulls `GET /sync/changes?wallet_id=&since=<cursor>` returning ordered change rows […] up to a page limit and a `next_cursor`; it pushes `POST /sync/batch` with a list of upserts/deletes each carrying `version`; […] returns per-item results (`applied`, `conflict` with server state, `rejected` with error), and the batch is idempotent by `Idempotency-Key`." Plus the brief's owner decisions of 2026-09-25 (the phone resolves clashes, the server never merges; upload is a batch with a result per item; each item obeys the single-write rules; a decision record supersedes ADR-0008 rule 7), its reconcile table, its binding "Inherited from B6" notes, and the paired client brief [C14](../../../saveme-kmp/specs/briefs/C14-sync-engine.md) "Needs from the backend".

> **Nature of this feature.** B14 is how an offline phone catches up and how it sends what it did offline. It builds the two change feeds (one for the person's own records, one per wallet) that tell a phone everything that changed since it last asked, deletions included, in the order it happened; the batch upload that carries many writes in one request and answers each one separately; and the retention job that removes deletion markers after 90 days and makes a phone that was away longer start that feed over. It also closes two gaps the earlier specs left for it: the person's own records are put on a commit-ordered counter (spec 007 R-19 used a global sequence until now), and a person's own memberships ride their own feed, so a phone learns it gained or lost a wallet (ADR-0008 rule 15). It changes **what a write is** nowhere: every write keeps its endpoint and its rules, and the batch is a second way for the same writes to travel.
>
> **Where the brief and the ratified decisions differ, the ratified side wins** (constitution, ADR-0008, ADR-0015, glossary, specs 003, 007, 010–012):
>
> | Brief | In force | Here |
> |---|---|---|
> | Server-side field-level last-write-wins for equal versions | P-07 rule 4, ADR-0008 rule 9; owner decision 2026-09-25 | Withdrawn. A stale item is refused whole with `VERSION_CONFLICT` and the current record; the phone merges |
> | Cursor = `change_log.id`, a timestamp-free global id | ADR-0008 rules 2–5: a per-scope counter stamped under the scope row lock | The cursor is opaque and encodes a position on the scope's own counter |
> | `GET /sync/changes?wallet_id=&since=` (wallet feeds only) | ADR-0008 rule 1: two scopes; four of eleven syncable entities are user-scoped; spec 003 collection grammar (`cursor`, `limit`, no bespoke parameters) | Two feed endpoints, `GET /sync/user/changes` and `GET /sync/wallets/{id}/changes`, with the shared `cursor` and `limit` |
> | Change row = `entity`, `id`, `version`, `op`, `payload` | Spec 003 and spec 012: a feed row **is** the resource with its syncable envelope; a tombstone is a record with `deleted_at` set | A row is `{ "type", "record" }`, where `record` is exactly the resource a read returns; no separate `op` |
> | Tombstones kept 30 days | ADR-0008 rule 12, security baseline Retention: 90 days | 90 days |
> | `POST /sync/batch` | ADR-0008 rule 7 (push is per-entity `PUT`); spec 003 put batch writes out of scope | Built, through a new decision record that supersedes rule 7 only; per-record writes stay |
> | The batch is idempotent by `Idempotency-Key` | Spec 003: `Idempotency-Key` only on the three non-upsert money operations; every syncable write is already idempotent by its identifier and version | The batch takes no key; it is idempotent because every item is (see Clarifications) |
> | Membership changes appear as change rows | ADR-0008 rule 15: a revocation appears in the affected person's **user** feed | A person's own memberships ride their user feed as well as the wallet's feed |

## Clarifications

### Session 2026-10-02

Owner away with delegated authority; each answer below is the recommended (most conservative) option, recorded as an **assumed default**.

- Q: Are the items of a batch applied independently, or all-or-nothing (per batch or per scope)? → A: **Independently.** Each item is one write in its own unit of work, committed before the next item starts, with its own result; a conflict or refusal on one item never undoes or blocks another. All-or-nothing would let one stale transaction hold back every other offline edit, which is exactly what the client brief forbids ("one clash never blocks unrelated changes"), and an all-or-nothing scope would need a multi-record transaction no single write has. *Assumed default.* (FR-012, FR-013)
- Q: In what order are a batch's items applied? → A: **In array order, one after another.** Item *n + 1* starts only after item *n* has committed or been refused, so a phone can send a new wallet, then a new category, then a transaction using both, in one batch. The same record may appear more than once; the later item simply meets the earlier one's result (an identical repeat is a replay, a different body against the old version is a conflict). No reordering, no deduplication, no parallelism. *Assumed default.* (FR-014)
- Q: What is the batch size limit, and what happens above it? → A: **1 to 50 items.** An empty batch or one with more than 50 items is refused whole with `422 VALIDATION_FAILED`, `OUT_OF_RANGE` at `/items`, and nothing applied — a write cannot be clamped the way a page size is. 50 matches the default page size and keeps one request at a few seconds at the measured single-write cost (spec 012: 109 ms p95 for a create). *Assumed default.* (FR-011)
- Q: How does the batch's `Idempotency-Key` relate to per-item idempotency? → A: **The batch takes no `Idempotency-Key`; each item is idempotent by its record's identifier and version, exactly as a single write is.** Resending a batch after a timeout re-runs every item: what was applied comes back `applied` with nothing bumped, what was not is applied now. A key that stored and replayed the whole response would keep a second, 24-hour copy of financial records and encrypted attributes (profile display name) outside their tables, and would add a fourth key-bearing operation to the three spec 003 allows. A key sent anyway is ignored and nothing is stored. Cost, accepted: if another device changes a record between the lost response and the retry, the retry reports a clash the first attempt did not — the same answer a single `PUT` retry gives today. *Assumed default.* (FR-016)
- Q: Does the per-record write (`PUT`/`PATCH`/`DELETE`) stay alongside the batch? → A: **Yes, permanently, with no change.** The batch is a transport over those writes, not a replacement: the client's wave-0 engine keeps working, and archive, ownership transfer and partial edits remain per-record only. *Assumed default.* (FR-010)

- Q: What does the server record about a batch or a feed read in its logs? → A: **One structured line per request**: the scope or item count, per-outcome counts, and each item's type, outcome and code — never a record, a body, an amount, a note or a cursor's contents (P-15 rule 4). Enough to answer "my transfer vanished" by request id; nothing that turns the log into a second copy of the ledger. *Assumed default.* (FR-027)
- Q: Does the user feed also carry the wallet records themselves, so a phone can list its wallets without opening each wallet feed? → A: **No — only the person's memberships.** A wallet record has one scope (ADR-0008 rule 1) and rides its own feed; a membership row names the wallet and the role, and the phone reads that wallet's feed from the beginning when a new membership arrives. Carrying wallets on user feeds would need a stamp per member on every wallet write. *Assumed default.* (FR-001)
- Q: In which language are the per-item problems of a batch written? → A: **The request's language**, exactly as the single write would answer (`Accept-Language`, else the profile language), and `Content-Language` on the batch response names it; `code` and `title` are never localised, so the phone branches on `code` per item as it does today. *Assumed default.* (FR-012)

Further defaults taken while writing the spec, each the conservative option and listed again in the plan's Assumed defaults:

- **Feed endpoints**: `GET /sync/user/changes` and `GET /sync/wallets/{id}/changes`, using the shared `cursor` and `limit` (default 50, maximum 200, clamped). No filters and no sorts; any `filter[…]` or `sort` is refused, never ignored.
- **Row shape**: `{ "type": "<record type>", "record": <the resource a read returns> }`. A deletion is a row whose `record` has `deleted_at` set. A client skips any `type` it does not know; later features add types inside `v1`.
- **Feed meta**: the shared `next_cursor`, `has_more`, `limit`, plus `sync_cursor` — never null, the position after this page — which the client stores and sends next time even when the page was the last one.
- **Expired cursor**: `410 SYNC_CURSOR_EXPIRED` when a deletion marker newer than the cursor has been purged from that scope; a cursor from another scope, another person, another endpoint, or ahead of the scope is `400 INVALID_CURSOR`.
- **Batch item**: `{ "type", "op", "id", "wallet_id" (transactions only), "data" }`, where `data` is **exactly** the body the per-record write takes. Types and operations: `wallet` (`upsert`, `delete`), `category` (`upsert`, `delete`), `transaction` (`upsert`, `delete`, `restore`), `profile` (`upsert`). Archive, ownership transfer and partial edits are not batch operations.
- **Batch result**: one result per item, in item order: `type`, `id`, `outcome` (`applied` | `conflict` | `rejected`), `status` (the HTTP status the single write would have answered), and either `record` (applied) or `problem` (the single write's problem body, pointers rooted at the item's `data`; a conflict's `problem.current` holds the record as it stands).
- **Batch shape refusals**: a body that is not a JSON object is `400 MALFORMED_JSON`, as on every endpoint (api-conventions; confirmed in QA 2026-10-02). An unusable envelope (`items` missing or not an array, count out of range, an item missing a member, an unknown member, an unknown `type`, an `op` its type does not support, an identifier that is not UUIDv7, `wallet_id` missing on a transaction or present on anything else) is `422 VALIDATION_FAILED` for the whole request with pointers such as `/items/3/op`, and nothing is applied.
- **Verification gate**: applied per item, exactly where the single write applies it (wallet, category and transaction writes refuse an unverified address with `EMAIL_NOT_VERIFIED`; the profile write does not).
- **User scope counter**: a counter on the person's own `users` row, taken under that row's lock, replaces the global sequence for Profile, Device Session, Category and the person's memberships (ADR-0008 rules 2–3; closes spec 007 R-19).
- **Membership on the user feed**: a membership record is stamped a second time from its member's user counter whenever it changes, so it rides both the wallet's feed and its member's user feed, as the identical record (new ADR-0017, modelled on ADR-0015).
- **Retention**: deletion markers kept 90 days (configurable upward, never below 90), then purged daily; a tombstoned wallet or category is removed only once nothing refers to it any more.
- **No rate limit, no entitlement, no events, no seeder.** Sizing target: a wallet of 10,000 transactions and a 50-item batch.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Catch up on a wallet after being offline (Priority: P1)

Mona and her husband share a household wallet. Her phone was offline for a week while he recorded 30 expenses, edited two and deleted one. When her phone reconnects it asks the household wallet's feed for everything since its last position, receives the 30 new records, the two edits and the deletion in the order they happened, and stores the new position. A transfer he made from his own wallet into the household wallet arrives on the household feed too.

**Why this priority**: without the feed, an offline-first phone can only re-download whole lists, cannot learn about deletions, and can miss a change made while it was reading.

**Independent Test**: as a member of a wallet, read its feed from the beginning, have another member make writes, read again from the stored position: exactly the new writes arrive, in order, deletions included.

**Acceptance Scenarios**:

1. **Given** a wallet with records, **When** a member reads its feed with no cursor, **Then** every record in the wallet arrives (the wallet itself, its members, its category references, its transactions and the transfers into it), tombstones included, ordered by the wallet's counter, a page at a time, each row being the record exactly as a read returns it.
2. **Given** a stored `sync_cursor`, **When** another member writes and the phone reads from that cursor, **Then** only the records written since arrive, and a record changed twice arrives once, in its latest state.
3. **Given** a transfer from wallet A into wallet B, **When** a member of B who is not a member of A reads B's feed, **Then** the transfer arrives as the identical record a read of it returns, and it never arrives on a feed of a wallet it does not touch.
4. **Given** a deletion, **When** the feed is read, **Then** the deleted record arrives with `deleted_at` set; it is never silently missing.
5. **Given** the last page, **When** it is read, **Then** `has_more` is false, `next_cursor` is null, and `sync_cursor` is still a position the phone can resume from; reading from it with nothing new returns an empty page and the same position.
6. **Given** two members writing at the same moment while a third reads, **When** the reader keeps paging, **Then** no committed write is ever skipped (at-least-once, ordered within the wallet).
7. **Given** a person who is not a member of the wallet, or an unknown wallet, **When** they read its feed, **Then** `404 NOT_FOUND`, revealing nothing.

---

### User Story 2 - Send many offline edits in one request, each answered on its own (Priority: P1)

Ahmed recorded 20 expenses, edited a category and deleted a transfer on a flight. When he lands, his phone sends all 22 writes in one request. Twenty-one are accepted; one edit clashes because his wife changed that expense meanwhile. His phone gets 21 confirmations and one clash carrying her version, shows him the choice, and resends only that one.

**Why this priority**: the core loop of offline-first money entry; per-record calls cost one round trip each on a bad mobile network.

**Independent Test**: send a batch mixing creates, an edit with a stale version, a delete and an invalid item: each comes back with its own outcome, in order, and the valid ones are stored exactly as their single writes would store them.

**Acceptance Scenarios**:

1. **Given** a batch of new transactions with client-generated identifiers, **When** it is sent, **Then** each result is `applied` with status `201` and the stored record, in the same order as the items.
2. **Given** a batch item whose `version` is stale, **When** the batch is sent, **Then** that item's result is `conflict` with status `409`, a `VERSION_CONFLICT` problem and the current record in `problem.current`; nothing of it is applied or merged, and the other items are applied.
3. **Given** an item that its single write would refuse (validation, not found, forbidden, category in use), **When** the batch is sent, **Then** that item is `rejected` with the same status and problem the single write would answer, pointers rooted at the item's `data`, and the rest are unaffected.
4. **Given** a batch whose response was lost, **When** the phone sends the same batch again, **Then** no record is created twice, no `version` or `change_seq` moves for what was already applied, and each such item comes back `applied` with the record as stored.
5. **Given** a batch creating a wallet, then a category, then a transaction using both, **When** it is sent, **Then** all three are applied in that order.
6. **Given** a deletion and a restore of a transaction in a batch, **When** it is sent, **Then** each behaves exactly as the per-record `DELETE` and restore do.
7. **Given** a batch with 51 items, an empty batch, an unknown `type`, an operation its type does not support, or a malformed identifier, **When** it is sent, **Then** the whole request is `422 VALIDATION_FAILED` with a pointer to the offending member and nothing is applied.
8. **Given** the per-record `PUT` the phone used before, **When** it is used after this feature, **Then** it behaves exactly as before.

---

### User Story 3 - My own records and my wallet list follow me across devices (Priority: P1)

Sara renames a category and changes her app language on her tablet, signs in on a new phone, and is added to her sister's wallet. Her phone's personal feed brings the category, the profile, the new device session and a membership telling her she now belongs to her sister's wallet as an editor; the phone then reads that wallet's feed from the beginning. When her sister later removes her, her personal feed carries the ended membership, and the wallet's feed answers not-found, so the phone removes that wallet's data.

**Why this priority**: four of the eleven syncable record types live only in the person's own scope, and the phone has no other way to learn it gained or lost a wallet.

**Independent Test**: read the user feed from the beginning; change the profile, a category, a session and a membership; read again: exactly those changes arrive in order, and a membership ending arrives as a tombstoned membership.

**Acceptance Scenarios**:

1. **Given** a person, **When** they read their user feed with no cursor, **Then** their profile, their device sessions, their own categories (never the platform's) and their own memberships arrive, tombstones included, in the order of their user counter.
2. **Given** a profile save, a category edit, a new sign-in and an ownership transfer that changes the person's role, **When** the user feed is read from the previous position, **Then** each change arrives exactly once more, in commit order.
3. **Given** a wallet the person is made a member of, **When** the user feed is read, **Then** a live membership row names the wallet and the role; **Given** the wallet is deleted or (from B9) the person removed, **Then** the membership arrives tombstoned on the person's own feed.
4. **Given** two writes in the person's scope committing concurrently (a category saved on one device while a session ends on another), **When** the user feed is read in between, **Then** neither is ever skipped.
5. **Given** another person's records, **When** the user feed is read, **Then** none of them ever appears.

---

### User Story 4 - A phone away for months starts over cleanly (Priority: P2)

Karim's old phone sat in a drawer for four months. When it reconnects, its stored positions are too old: deletions it never saw have since been purged. The server tells it so explicitly; the phone discards that scope's data and reads the feed from the beginning, ending with exactly the server's state.

**Why this priority**: rare, but the alternative is a phone that silently keeps records everyone else deleted.

**Independent Test**: purge a deletion newer than a stored cursor and read from that cursor: `410 SYNC_CURSOR_EXPIRED`; read with no cursor: the full current state.

**Acceptance Scenarios**:

1. **Given** a deletion marker older than 90 days, **When** the daily retention job runs, **Then** the marker is removed and the scope remembers how far its history has been purged.
2. **Given** a cursor older than a purged marker of that scope, **When** the feed is read with it, **Then** `410 SYNC_CURSOR_EXPIRED`, nothing partial is served.
3. **Given** a cursor newer than every purged marker of that scope, **When** the feed is read with it, **Then** the feed is served normally, however old the cursor is.
4. **Given** a cursor that was not issued for this scope and this person, is unparseable, or is ahead of the scope, **When** it is used, **Then** `400 INVALID_CURSOR`.
5. **Given** a tombstoned wallet or category still referred to by a record that is kept, **When** the job runs, **Then** it is not removed until nothing refers to it.

### Edge Cases

- **A record moves forward on its feed.** Every write in a wallet re-stamps the wallet record (spec 010), and every write in a scope re-stamps what it touched, so a record may arrive again later in its newer state. A phone keeps the latest by `version`; a `change_seq` move alone is not an edit (spec 010 default 17).
- **A transfer's `change_seq` on the destination feed** is the source wallet's value (ADR-0015 rule 4); the phone never compares `change_seq` across feeds, and only the opaque cursor carries positions.
- **A wallet deleted while a member is offline**: the wallet feed keeps serving the tombstones to everyone who was a member at deletion, until the wallet is purged; afterwards it is `404`, and the member's user feed already carried the tombstoned membership.
- **Losing access mid-walk** (B9): the next page is `404`; the phone purges the wallet scope when its user feed carries the ended membership.
- **A batch item for a wallet the person cannot see**: the item is `rejected` with `404`, nothing disclosed; the rest proceed.
- **The server fails on one item** (an unexpected error): that item is `rejected` with `500 INTERNAL_ERROR` (retryable), what was already committed stays committed, and later items still run; resending the batch is safe.
- **A batch item with an `Idempotency-Key` header on the request**: ignored; nothing stored.
- **The same record twice in a batch**: applied in order; the second meets the first's result.
- **A profile item whose `id` is not the caller's profile**: `rejected`, `404 NOT_FOUND`.
- **Display name changes of other members**: a member row on a wallet feed carries the display name as of the read; a rename alone does not re-stamp it (spec 010 behaviour). The member list endpoint stays the way to refresh names.
- **Feed rows render the reader's view**: `my_role` on a wallet and `current` on a device session are computed for the reader at read time.
- **Unverified address**: may read both feeds (reads are not gated); batch items that are wallet, category or transaction writes are each `rejected` with `403 EMAIL_NOT_VERIFIED`.
- **Clock skew**: no ordering, cursor or horizon decision uses the phone's clock or any `updated_at`.
- **Upgrade from the global sequence**: records stamped before this feature keep their values; every person's counter starts above the highest value already in their scope, so the first feed read after deployment misses nothing.

## Requirements *(mandatory)*

### Functional Requirements

**Feeds**

- **FR-001**: The system MUST serve a **user feed** to each signed-in person carrying, from that person's scope only: their Profile, their Device Sessions, their own Categories (never platform categories), and their own Wallet Memberships; and later (B8) their Budgets. Wallet records MUST NOT ride the user feed; a membership row names its wallet.
- **FR-002**: The system MUST serve a **wallet feed** per wallet carrying: the Wallet, its Wallet Members, its Category References, its Transactions, and the transfers whose destination it is (ADR-0015 rule 4); and later (B7, B9) its Recurring Rules, Occurrences and Invitations.
- **FR-003**: Each feed MUST be ordered by its scope's counter position and MUST include tombstones (ADR-0008 rule 6). Positions MUST be unique within a scope (every stamp is a fresh counter value), so the order is total and a cursor never splits rows that share a position. A transfer's position on its destination's feed MUST be its destination stamp; a membership's position on its member's user feed MUST be its user-scope stamp.
- **FR-004**: Each row MUST be the record exactly as the record's own read endpoint returns it for that reader, labelled with its type. Postings MUST never appear (P-04 rule 6).
- **FR-005**: A page MUST hold at most `limit` rows (default 50, maximum 200, clamped). The response MUST carry `next_cursor`, `has_more`, `limit` and `sync_cursor`; `sync_cursor` MUST always be present and MUST resume after the last row served (or at the incoming position when the page is empty).
- **FR-006**: Positions MUST be gap-safe: every committed write in a scope MUST be served to a reader resuming from any earlier position (at-least-once, ordered within the scope; no ordering across scopes, ADR-0008 rule 16).
- **FR-007**: A cursor MUST be opaque, bound to its endpoint, scope and reader. An unparseable or foreign cursor, or one ahead of the scope, MUST be refused with `400 INVALID_CURSOR`; a cursor behind the scope's purge horizon MUST be refused with `410 SYNC_CURSOR_EXPIRED` and nothing partial served (ADR-0008 rule 13).
- **FR-008**: The wallet feed MUST be readable by any active member of the wallet and, for a deleted wallet not yet purged, by everyone who was a member at its deletion (spec 010 default 25). Anyone else, and any purged or unknown wallet, MUST get `404 NOT_FOUND`.
- **FR-009**: Any `filter[…]` or `sort` on a feed MUST be refused (`FILTER_FIELD_NOT_SUPPORTED`, `SORT_FIELD_NOT_SUPPORTED`). Other unrecognised query parameters are ignored, as on every collection endpoint (a brief-era `since` therefore yields a full resync, never a wrong page).

**Batch upload**

- **FR-010**: Every per-record write endpoint MUST remain available and unchanged beside the batch.
- **FR-011**: A batch MUST carry 1 to 50 items. Outside that range, or with an unusable envelope (see Clarifications), the whole request MUST be refused with `422 VALIDATION_FAILED` and nothing applied.
- **FR-012**: Each item MUST be applied with exactly the rules, authorization, verification gate, validation, version check, replay behaviour and refusals its per-record write applies (client-generated identifier, no merge, whole-item refusal), and its result MUST carry the status and body that write would have answered.
- **FR-013**: Items MUST be independent: each in its own unit of work, committed or refused before the next starts; a refusal or failure of one MUST NOT undo or block another.
- **FR-014**: Items MUST be applied in array order, one at a time, and results returned in the same order, one per item.
- **FR-015**: An item's outcome MUST be `applied` (`200` or `201`, with the record), `conflict` (`409 VERSION_CONFLICT`, with the current record, tombstone included, in `problem.current`), or `rejected` (any other status, with the problem the single write would answer).
- **FR-016**: The batch MUST NOT require or honour an `Idempotency-Key`; resending a batch MUST be safe because each item is idempotent by identifier and version (ADR-0008 rule 10).
- **FR-016a** (added by the requirements review): The caller MUST be authenticated once for the whole batch; every item runs as that caller even if the session ends while the batch runs. No byte limit applies beyond the item count: each item's `data` is bounded by its own write's field limits.
- **FR-017**: Supported item types and operations MUST be exactly: `wallet` (`upsert`, `delete`), `category` (`upsert`, `delete`), `transaction` (`upsert`, `delete`, `restore`), `profile` (`upsert`). Later features add theirs to this list inside `v1`.

**Scope counters**

- **FR-018**: Every write to a user-scoped record (Profile, Device Session, Category, and a person's own membership) MUST take its `change_seq` from a counter on that person's own scope row, incremented under that row's lock in the same unit of work (ADR-0008 rules 2–5). The global sequence MUST no longer stamp anything.
- **FR-019**: Every write that creates, changes or ends a membership (wallet creation, ownership transfer, wallet deletion; later B9 grant and revocation) MUST also stamp it from the member's user counter, so it reaches that person's user feed.
- **FR-020**: When one unit of work takes several scope locks, user rows MUST be locked after wallet rows, and rows of the same kind in ascending identifier order (extending ADR-0014 rule 10).
- **FR-021**: After the switch, each person's counter MUST start above every `change_seq` already stamped in their scope, so no existing record is ever behind a new one.

**Retention**

- **FR-022**: A daily job MUST remove every tombstone older than the retention horizon (90 days; configurable, never below 90), for every syncable record type that has tombstones, and MUST record on each affected scope the highest position it has purged — on both wallets for a transfer, and on the member's user scope for a membership.
- **FR-023**: The job MUST NOT remove a tombstoned wallet or category that a kept record still refers to; it is removed on a later run once nothing does.
- **FR-024**: The job MUST be idempotent and safe to run while writes and feed reads continue, and MUST log one summary line per run (rows removed per type, scopes touched) with no record identifiers or content.

**Contract and records**

- **FR-025**: The contract MUST be frozen in `docs/openapi/paths/sync.yaml`, indexed from `openapi.yaml`, with request and response examples, and `info.version` bumped (additive).
- **FR-026**: A decision record MUST supersede ADR-0008 rule 7 (batch upload beside per-record writes); a second MUST record a membership riding its member's user feed (extending ADR-0008 rule 15). The constitution's P-07 ADR line, the ADR index and `docs/api-conventions.md` (Writes, Idempotency, Collections) MUST be updated in the same change.
- **FR-027**: Each feed read and each batch MUST be logged as one structured line carrying the request id, the scope or item count, per-outcome counts and each item's type, outcome and code, and MUST NOT log any record content, request body or cursor contents (P-15 rule 4).

### Key Entities *(include if feature involves data)*

- **Sync Cursor** (glossary, existing): the client-held position on one scope's counter. Opaque; never stored by the server.
- **Change Row** (new term): one record on a feed — the record's type plus the record exactly as its read returns it.
- **Sync Batch** (new term): one upload request of up to 50 writes, each answered by its own **Batch Item Result**.
- **Purge Horizon** (new term): per scope, the highest position whose tombstone has been purged; a cursor behind it is expired.
- **User scope counter**: the `change_seq` counter on the person's own row (ADR-0008 rule 2), now actually used.

## Project Sections *(mandatory for feature specs)*

### 1. Purpose

Lets an offline-first phone stay in step with the server for everything a person owns or shares: it learns, in order and without gaps, every change and deletion made on other devices or by other members, and it sends what it did offline in one request, learning record by record what was stored, what clashed and why something was refused. A phone that has been away too long is told to start over rather than silently keeping deleted data.

### 2. Glossary terms

From [docs/glossary.md](../../docs/glossary.md): [Sync Cursor](../../docs/glossary.md#sync-cursor), [Wallet](../../docs/glossary.md#wallet-محفظة), [Wallet Member](../../docs/glossary.md#wallet-member), [Profile](../../docs/glossary.md#profile), [Device Session](../../docs/glossary.md#device-session), [Category](../../docs/glossary.md#category), [Category Reference](../../docs/glossary.md#category-reference), [Transaction](../../docs/glossary.md#transaction); field conventions `version`, `change_seq`, `deleted_at`, scope key. Added to the glossary in this change, under Sync, before use: **Change Row**, **Sync Batch** (with Batch Item Result) and **Purge Horizon**. "Tombstone", "scope" and "change feed" are used as in the glossary and api-conventions.

### 3. Data model

Owned by the new **Sync** module (`app/Modules/Sync`) for the protocol (cursor, batch, purge orchestration); each scope row and record stays with its owner.

| Change | Owner | What |
|---|---|---|
| `users.change_seq` | Identity | bigint, not null, the user scope counter (ADR-0008 rule 2); backfilled to the highest value in the person's scope |
| `users.purged_change_seq` | Identity | bigint, not null, default 0: the user scope's purge horizon |
| `wallets.purged_change_seq` | Wallets | bigint, not null, default 0: the wallet scope's purge horizon |
| `wallet_members.member_change_seq` | Wallets | bigint, not null (backfilled for every existing membership): the membership's position on its member's user feed (ADR-0017); indexed with `user_id` |
| sequence `sync_change_seq` | Identity | retired once nothing draws from it |

No new syncable entity and no new table. Purge removes tombstoned rows of: transactions (postings already gone), category references, wallet members, person's categories, wallets (only when unreferenced), and device sessions or profiles if ever tombstoned.

### 4. Invariants

| Invariant | Principle or decision | Test that fails when broken |
|---|---|---|
| A feed never skips a committed write under concurrent writers (user and wallet scopes) | ADR-0008 rules 3, 5; FR-006, FR-018 | `tests/Feature/Sync/FeedGapSafetyTest.php` |
| A feed is ordered by its scope position and includes tombstones | ADR-0008 rule 6; FR-003 | `tests/Feature/Sync/WalletFeedTest.php`, `tests/Feature/Sync/UserFeedTest.php` |
| A transfer is on both wallets' feeds as the identical record, and on no other | ADR-0015 rules 2, 4 | `tests/Feature/Sync/TransferOnTwoFeedsTest.php` |
| A membership is on its wallet's feed and its member's user feed, and on no other user feed | ADR-0017; ADR-0008 rule 15 | `tests/Feature/Sync/MembershipOnUserFeedTest.php` |
| No other person's record is ever on a user feed; a non-member gets 404 on a wallet feed | P-08 rule 3; FR-008 | `tests/Contract/Sync/ListUserChangesTest.php`, `tests/Contract/Sync/ListWalletChangesTest.php` |
| Postings never appear on a feed | P-04 rule 6 | `tests/Contract/Sync/ListWalletChangesTest.php` |
| A cursor behind the purge horizon is 410, never a partial feed | ADR-0008 rule 13 | `tests/Feature/Sync/CursorExpiryTest.php` |
| Positions are unique within a scope | FR-003; ADR-0008 rule 3 | `tests/Feature/Sync/FeedGapSafetyTest.php` |
| Every batch item answers exactly what its single write answers | owner decision 2026-09-25; FR-012 | `tests/Contract/Sync/BatchParityTest.php` |
| A stale item is refused whole with the current record; nothing merged | P-07 rules 3–4 | `tests/Feature/Sync/BatchConflictTest.php` |
| Items are independent and applied in array order | FR-013, FR-014 | `tests/Feature/Sync/BatchOrderingTest.php` |
| Resending a batch creates nothing twice and bumps nothing applied | ADR-0008 rule 10; FR-016 | `tests/Feature/Sync/BatchReplayTest.php` |
| An unusable envelope applies nothing | FR-011 | `tests/Contract/Sync/UploadSyncBatchTest.php` |
| User-scope `change_seq` comes only from the person's counter under its row lock | ADR-0008 rules 2–4; FR-018 | `tests/Feature/Sync/UserScopeCounterTest.php`, `tests/Arch/SyncCounterTest.php` |
| Lock order wallets → users, ascending, holds under concurrency | FR-020 | `tests/Feature/Sync/ScopeLockOrderTest.php` |
| Tombstones younger than the horizon are never purged; referenced wallets and categories are kept | ADR-0008 rule 12; FR-022, FR-023 | `tests/Feature/Sync/PurgeTombstonesTest.php` |
| A batch item failing unexpectedly leaves earlier items committed and later items running; a session ending mid-batch does not stop it | FR-013, FR-016a | `tests/Feature/Sync/BatchFailureIsolationTest.php` |
| Feed reads, batches and purge runs log one summary line with no record content | FR-024, FR-027; P-15 rule 4 | `tests/Feature/Sync/SyncLoggingTest.php` |
| After the counter switch no record of a scope is above its counter | FR-021 | `tests/Feature/Sync/UserCounterBackfillTest.php` |

### 5. API

Under `/api/v1`, in `docs/openapi/paths/sync.yaml`, tag `Sync`. **Frozen for the C14 client (wave 2)**. Shapes and examples are in the path file; the members the client binds to are listed here so that any change to them is visibly a contract change.

| Method | Path | operationId | Success | Error codes (beyond the shared 400/401/406/426/500 refusals) |
|---|---|---|---|---|
| GET | `/sync/user/changes` | `listUserChanges` | `200` feed page | `INVALID_CURSOR`, `INVALID_QUERY_PARAMETER`, `FILTER_FIELD_NOT_SUPPORTED`, `SORT_FIELD_NOT_SUPPORTED`, `SYNC_CURSOR_EXPIRED` (410) |
| GET | `/sync/wallets/{id}/changes` | `listWalletChanges` | `200` feed page | as above, plus `NOT_FOUND`, `INVALID_ID` |
| POST | `/sync/batch` | `uploadSyncBatch` | `200` one result per item | `VALIDATION_FAILED`, `MALFORMED_JSON`, `UNSUPPORTED_MEDIA_TYPE` (415) for the request; per item, every code its single write can answer |

**Cursor per scope.** The phone keeps one cursor for its user scope and one per wallet scope it holds. Absent means from the beginning (a full resync). The phone stores `meta.sync_cursor` after applying a page and sends it back as `cursor`; while `has_more` is true it keeps reading. A cursor is valid only on the endpoint, scope and account that issued it.

**Feed page**: `{ "data": [ChangeRow…], "meta": { "next_cursor", "has_more", "limit", "sync_cursor" } }`.

**Change row**: `{ "type": …, "record": … }`.

| Feed | `type` | `record` is the resource of |
|---|---|---|
| user | `profile` | `GET /me` (its `profile` member, `Profile`) |
| user | `device_session` | `GET /auth/sessions` (`DeviceSession`; `current` relative to the reading session) |
| user | `category` | `GET /categories/{id}` (`Category`, the person's own only) |
| user | `wallet_member` | `GET /wallets/{id}/members` (`WalletMember`, the reader's own memberships only) |
| wallet | `wallet` | `GET /wallets/{id}` (`Wallet`; `my_role` for the reader) |
| wallet | `wallet_member` | `GET /wallets/{id}/members` (`WalletMember`) |
| wallet | `category_reference` | `GET /wallets/{id}/category-references` (`CategoryReference`) |
| wallet | `transaction` | `GET /wallets/{id}/transactions/{tid}` (`Transaction`, transfers into the wallet included) |

A deletion is a row whose `record.deleted_at` is set. A tombstoned `wallet_member` on the user feed means the person no longer belongs to that wallet: the phone purges that wallet's scope. A client MUST skip a row whose `type` it does not know.

**`SYNC_CURSOR_EXPIRED` handling**: `410` on either feed means "discard this scope's local data (keeping queued writes) and read the feed again with no cursor". It is scoped: one wallet expiring says nothing about the user feed or other wallets.

**Batch request**: `{ "items": [BatchItem × 1–50] }`; no `Idempotency-Key`.

| Member | Rule |
|---|---|
| `type` | `wallet` \| `category` \| `transaction` \| `profile` |
| `op` | `upsert` \| `delete` \| `restore`, as allowed for the type (FR-017) |
| `id` | UUIDv7; the record's identifier (the caller's profile id for `profile`) |
| `wallet_id` | required for `transaction` (the path wallet of its single write; either wallet of a transfer); refused on every other type |
| `data` | exactly the single write's body: `upsert` → the `PUT` body (`WalletWrite`, `CategoryWrite`, `TransactionWrite`, `ProfileWrite`); `delete` → `{ "version": n }`; `restore` → `{ "deleted_at": null, "version": n }` |

Each item is the single write it names: `upsert` = `PUT`, `delete` = `DELETE`, `restore` = the restore `PATCH`, at that record's per-record path.

**Batch response**: `200 { "data": [BatchItemResult…], "meta": {} }`, one result per item in item order, even when every item was refused.

| Member | Rule |
|---|---|
| `type`, `id` | echoed from the item |
| `outcome` | `applied` \| `conflict` \| `rejected` |
| `status` | the HTTP status the single write would have answered (`200`, `201`, `409`, `403`, `404`, `422`, `500`, …) |
| `record` | the stored record (`applied` only), otherwise null |
| `problem` | the single write's problem body (`conflict` and `rejected`), otherwise null; `VERSION_CONFLICT` carries `current`; validation pointers are rooted at the item's `data` |

**Batch limits and semantics (frozen)**: 1–50 items; independent per item; array order, sequential; same record may repeat; no all-or-nothing; no key; per-record writes stay.

**Request-level refusals** (nothing applied): `422 VALIDATION_FAILED` with field codes `REQUIRED`, `UNKNOWN_FIELD`, `INVALID_TYPE`, `INVALID_ENUM_VALUE`, `OUT_OF_RANGE` (count), `INVALID_ID` (item `id` or `wallet_id`), at pointers `/items`, `/items/{n}/{member}`; `400 MALFORMED_JSON`; `415 UNSUPPORTED_MEDIA_TYPE`.

**No new error code.** `info.version` moves from v1.5 to v1.6 (additive: new paths).

### 6. Authorization and entitlements

Every rule references spec 010 §6 by row and never restates the matrix (P-08 rule 5).

- User feed: the signed-in person, for their own scope only. Nobody can read another person's user feed; there is no identifier to ask for.
- Wallet feed: row **W-01** (read the wallet) — any active member; plus, for a deleted wallet not yet purged, the members at deletion. Others → `404 NOT_FOUND` (ADR-0006).
- Batch: no authorization of its own. Each item is authorised exactly as its single write — a wallet item by rows W-02 and W-04 (create: any verified person, spec 010), a transaction item by rows T-02 and T-03, a category or profile item by ownership (the person themselves; spec 011, spec 008) — and each item meets the verification gate exactly where its single write does.
- Entitlements: none. No feature or quota gates sync (P-09 rule 5). The wallet-count quota on wallet creation still applies per item, through the single write.

### 7. Events

None published or consumed. The feed reads `change_seq`, not events (spec 008 §7). The purge job publishes nothing: a purge is not a domain change, and every client already received the tombstone it removes.

### 8. Acceptance scenarios

| Story · scenario | Layer | Test file |
|---|---|---|
| US1 · 1, 2, 4, 5 wallet feed | Feature, Contract | `tests/Feature/Sync/WalletFeedTest.php`, `tests/Contract/Sync/ListWalletChangesTest.php` |
| US1 · 3 transfers | Feature | `tests/Feature/Sync/TransferOnTwoFeedsTest.php` |
| US1 · 6 gap safety | Feature | `tests/Feature/Sync/FeedGapSafetyTest.php` |
| US1 · 7 access | Contract | `tests/Contract/Sync/ListWalletChangesTest.php` |
| US2 · 1–3, 6 outcomes | Feature, Contract | `tests/Feature/Sync/BatchConflictTest.php`, `tests/Contract/Sync/BatchParityTest.php`, `tests/Contract/Sync/UploadSyncBatchTest.php` |
| US2 · 4 replay | Feature | `tests/Feature/Sync/BatchReplayTest.php` |
| Edge cases · item failure, session ending mid-batch | Feature | `tests/Feature/Sync/BatchFailureIsolationTest.php` |
| US2 · 5 ordering | Feature | `tests/Feature/Sync/BatchOrderingTest.php` |
| US2 · 7 envelope refusals | Contract | `tests/Contract/Sync/UploadSyncBatchTest.php` |
| US2 · 8 per-record unchanged | Contract | the existing `tests/Contract/Wallets/**` and `tests/Contract/Identity/**` suites, unchanged |
| US3 · 1, 2, 5 user feed | Feature, Contract | `tests/Feature/Sync/UserFeedTest.php`, `tests/Contract/Sync/ListUserChangesTest.php` |
| US3 · 3 membership | Feature | `tests/Feature/Sync/MembershipOnUserFeedTest.php` |
| US3 · 4 user counter | Feature | `tests/Feature/Sync/UserScopeCounterTest.php`, `tests/Feature/Sync/FeedGapSafetyTest.php` |
| US4 · 1, 5 purge | Feature | `tests/Feature/Sync/PurgeTombstonesTest.php` |
| US4 · 2–4 cursor | Feature, Contract | `tests/Feature/Sync/CursorExpiryTest.php`, `tests/Contract/Sync/ListWalletChangesTest.php` |

### 9. Open questions

1. **Membership grant and revocation (B9)** stamp the member's user feed through the same rule (FR-019); B9 calls it. Does not block.
2. **Budgets (B8), recurring rules and occurrences (B7), invitations (B9)** join the feeds and the batch type list when they ship; each adds its `type` inside `v1`. Does not block.
3. **Batch rate limit**: none now, like every other write route. Revisit if abuse appears. Does not block.
4. **Display names on member rows** are not re-stamped when a member renames; a later change could re-stamp memberships on profile save. Does not block.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 0 committed writes missed by a phone that pages a feed while others write, across 1,000 interleaved concurrent writes and reads per scope.
- **SC-002**: A phone that was offline catches up on 1,000 changes in a wallet in at most 5 page requests at the maximum page size.
- **SC-003**: 0 records duplicated and 0 versions bumped by resending any batch any number of times.
- **SC-004**: 100% of batch items answer the same outcome, status and problem as the same write sent on its own.
- **SC-005**: 0 clashes resolved by the server: every stale item comes back with the current record and nothing of it is stored.
- **SC-006**: A 50-item batch of new transactions completes in under 8 seconds at the 95th percentile, and a feed page of 200 rows — of a wallet holding 10,000 transactions, or of a user feed holding 200 categories and 50 memberships — in under 300 ms at the 95th percentile, measured on the development stack with debugging extensions off (as specs 010–012 measured).
- **SC-007**: 0 phones served a partial feed after their history was purged: every such cursor gets the explicit "start over" answer.
- **SC-008**: 0 deletion markers kept beyond 91 days unless still referred to, and 0 removed before 90 days.

## Assumptions

- The per-record endpoints of specs 007, 008, 010, 011 and 012 are the single writes a batch item names; their rules are reused as built, not reimplemented.
- The wallet scope counter (spec 010 R-3) and the transfer destination stamp (spec 012, ADR-0015) are already commit-ordered and need no change; only the user scope moves off the global sequence.
- A membership's user-scope stamp is new; existing live memberships are stamped once when this feature deploys, and tombstoned ones too while their tombstones are kept.
- The client keeps its wave-0 per-record engine until it switches to the batch; both work against the same server.
- No reference data: there is nothing to seed.
- Account deletion (B15) purges a person's scope wholesale and is out of this feature.
- The user-counter switch runs once, as a deployment step with the application in maintenance mode; it is a single database transaction, so a failure leaves the previous state intact and the step is simply rerun.
- A client allows at least 30 seconds for a batch response; a 50-item batch is expected to take a few seconds.
