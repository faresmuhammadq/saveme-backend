# Research: Sync Protocol (spec 013, B14)

Phase 0 of the plan. Each entry: decision, rationale, alternatives. Owner away; every decision is the conservative option and is listed again as an assumed default in [plan.md](./plan.md).

## R-1 Batch upload supersedes ADR-0008 rule 7 only

**Decision**: write **ADR-0016** "Batch upload beside per-record writes". It supersedes ADR-0008 rule 7 alone: push MAY be a per-record write (`PUT`/`PATCH`/`DELETE` at the record's path) **or** `POST /sync/batch`, whose every item is exactly one of those writes. Rules 8–11 and 14 of ADR-0008 keep governing each item. ADR-0008's status line becomes "Accepted — rule 7 superseded by ADR-0016"; its body is not edited (ADR README). The constitution's P-07 `ADRs:` line gains ADR-0016 (PATCH v1.3.6).

**Rationale**: the owner decided the batch changes how writes travel, not what a write is. Superseding one rule keeps every other rule of ADR-0008 in force without restating it.

**Alternatives**: supersede ADR-0008 wholesale with a restated ADR — rejected: sixteen rules re-copied invite drift. Amend P-07 — unnecessary: no P-07 rule names the transport.

## R-2 Items are applied by dispatching the equivalent single request

**Decision**: the batch controller turns each item into the per-record request it names (`upsert` → `PUT`, `delete` → `DELETE`, `restore` → `PATCH {deleted_at: null, version}`, at the route the record's own path file defines), dispatches it in-process through the router to the existing route — with its own route middleware (`auth:api`, `address.verified`), form request, controller, application service and problem renderer — and captures the status and JSON body as that item's result. The sub-request carries the caller's resolved user, `Accept-Language`, `X-Client-Platform`, `X-Client-Version` and correlation id; the batch's request id is written into every item problem's `request_id` (one id joins the whole batch in the logs). The item's `data` is the sub-request's JSON body verbatim.

**Rationale**:

- The owner's rule is "each item obeys exactly the rules a single write does". Reusing the single write's whole HTTP stack makes that true by construction: validation, `UNKNOWN_FIELD`, pointers, precedence, verification gate, authorization, conflict body and localisation cannot drift, because there is only one implementation. `BatchParityTest` asserts it item by item.
- P-02 holds: Sync's Http layer names routes, not other modules' classes.
- Later features (B7 rules, B8 budgets) join the batch by adding one row to the type map, not a second validation path.

**Alternatives**:

- A `BatchItemHandler` per module calling its application service with rules extracted from each form request — rejected: four modules' validation would need refactoring into a second, non-HTTP path, and a missed rule silently diverges from the single write.
- A full kernel sub-request (`app()->handle()`) — rejected: it re-runs the global stack (request id, transport, version gate) and swaps the container's request; the router dispatch inside the already-authenticated batch request is enough, and the global checks already passed once.

**Risk recorded**: per-item overhead (route middleware, form request) is a few milliseconds on top of the write; SC-006's 8 s budget for 50 creates leaves headroom over spec 012's 109 ms p95 per create. A spike task (T010) proves the dispatch keeps the guard's user, restores the parent request afterwards, and that an exception in one item is rendered by the problem renderer as that item's problem rather than escaping the batch.

## R-3 Independent items, array order, no key

**Decision**: each sub-request runs its own unit of work exactly as the single write does (the application services already open one database transaction per write); the batch controller holds **no** outer transaction. Items run strictly one after another in array order. An exception that escapes an item is rendered to that item's problem (`500 INTERNAL_ERROR`, logged with the batch request id) and the loop continues. The batch takes no `Idempotency-Key`; one sent is ignored and nothing is stored.

**Rationale**: an outer transaction would make the batch all-or-nothing and hold every scope lock it touched until the end, serialising other members behind a 50-item batch. Per-item idempotency (ADR-0008 rule 10) already makes a resend safe; a stored response would copy financial records and an encrypted profile attribute into a 24-hour table outside the security baseline's protected-attributes table (spec Clarifications).

**Alternatives**: all-or-nothing per batch or per scope; parallel items; a required key with a 24-hour stored response (spec 003's mechanism) — all rejected in the spec's Clarifications.

## R-4 Batch envelope validation

**Decision**: a dedicated form request validates only the envelope and refuses it whole (422 `VALIDATION_FAILED`, nothing dispatched):

| Pointer | Rule | Field code |
|---|---|---|
| `/items` | present, a JSON array, 1–50 entries | `REQUIRED`, `INVALID_TYPE`, `OUT_OF_RANGE` |
| `""` (root) | no member other than `items` | `UNKNOWN_FIELD` at `/<member>` |
| `/items/{n}` | a JSON object | `INVALID_TYPE` |
| `/items/{n}/type` | one of `wallet`, `category`, `transaction`, `profile` | `REQUIRED`, `INVALID_ENUM_VALUE` |
| `/items/{n}/op` | allowed for the type (spec FR-017) | `REQUIRED`, `INVALID_ENUM_VALUE` |
| `/items/{n}/id` | lowercase hyphenated UUIDv7 | `REQUIRED`, `INVALID_ID` |
| `/items/{n}/wallet_id` | UUIDv7, required for `transaction`, absent otherwise | `REQUIRED`, `INVALID_ID`, `UNKNOWN_FIELD` |
| `/items/{n}/data` | a JSON object | `REQUIRED`, `INVALID_TYPE` |
| `/items/{n}/<other>` | no other member | `UNKNOWN_FIELD` |

Everything inside `data` is the single write's business and is answered per item.

**Rationale**: a malformed envelope is a client bug, not a record problem; refusing it whole is the only answer that cannot half-apply a batch the client misbuilt. `INVALID_ID` is already a field-level code (level `[response, field]`). No new code is needed.

## R-5 User scope counter on the `users` row

**Decision**: add `users.change_seq bigint not null default 0` (ADR-0008 rule 2). Identity's `ChangeSequence` port changes from `next(): int` to `next(string $userId): int`, implemented by `UPDATE users SET change_seq = change_seq + 1 WHERE id = ? RETURNING change_seq`, which takes the row lock until commit. Identity publishes the same operation to other modules as an Application service, `Identity\Application\Sync\UserScopeCounter::next(string $userId): int`; Wallets' `UserScopeSequence` adapter calls it instead of the global sequence (P-02 rule 4). The global sequence `sync_change_seq` is dropped once no code or factory draws from it.

**Migration**: Identity adds the column; a one-off Sync switch migration (data-model.md) backfills each person's counter to the greatest `change_seq` already in their scope, after stamping every membership's `member_change_seq` (R-6), never lower, in one transaction with `users` locked `SHARE ROW EXCLUSIVE`. Because writers of the old code draw from the sequence without touching `users`, the migration runs as a deployment step with the application in maintenance mode (`php artisan down` → migrate → `up`), recorded in quickstart.md and the summary.

**Rationale**: a sequence hands numbers out at `nextval()` time, not commit time; a user-feed reader could skip a category saved on one device while a session ended on another (ADR-0008 context). Spec 007 R-19 and spec 011 R-5 deferred exactly this to B14. `UPDATE … RETURNING` is the lock and the increment in one statement.

**Alternatives**: keep the sequence and serve only rows below a "safe" horizon computed from `pg_snapshot_xmin` — rejected: Postgres-internal, hard to test, and ADR-0008 already chose the row counter. A separate Sync-owned counter table — rejected: ADR-0008 rule 2 puts the counter on the scope row.

## R-6 A membership rides its member's user feed (ADR-0017)

**Decision**: write **ADR-0017** "A membership rides its member's user feed" (extends ADR-0008 rules 1 and 15, no rule changed), modelled on ADR-0015:

1. A Wallet Member stays wallet-scoped (`wallet_id` is its one scope key) and keeps `change_seq` from the wallet counter.
2. Every write that creates, changes or tombstones a membership MUST also stamp `member_change_seq` from the member's user counter, in the same unit of work, after the wallet lock (R-7).
3. The member's user feed selects `wallet_members WHERE user_id = :me` by `member_change_seq`; the row on the wire is identical to the wallet feed's (`change_seq` is the wallet's, opaque); `member_change_seq` is never on the wire.
4. Only the member's own memberships ride their user feed; other members' memberships ride only the wallet feed.

Column `wallet_members.member_change_seq bigint`, NOT NULL after backfill; index `(user_id, member_change_seq)`. Writers: `WalletService` create (owner membership), ownership transfer (both members), deletion cascade (every member); B9 grant, role change, revocation, leave.

**Rationale**: ADR-0008 rule 15 requires revocation to reach the person's user feed, and a phone also has to discover a granted wallet. A second stamp on the same record is the smallest mechanism that does both, exactly as ADR-0015 did for transfers. A tombstoned membership on the user feed is the "you lost this wallet" signal.

**Alternatives**: a separate user-scoped "wallet access" projection — rejected (ADR-0015's reasoning: a second record type and lifecycle for the same fact). Putting Wallet records on user feeds — rejected in Clarifications.

## R-7 Lock order: categories → wallets → users

**Decision**: extend ADR-0014 rule 10's order with users last: category rows (share or update), then wallet rows ascending, then user rows ascending, then the record rows. The user stamp is therefore taken as late as possible in each write: Profile and Device Session writes lock only their user row; a Category write locks the category, any wallets whose references it republishes, then its owner's user row; a membership write locks the wallet, then every affected member's user row ascending. Recorded as ADR-0017 rule 5. `ScopeLockOrderTest` runs a category rename that republishes into a shared wallet concurrently with an ownership transfer of that wallet, both touching the same two users, and asserts no deadlock and both commits.

**Rationale**: every existing path already takes wallet locks before anything user-scoped would be stamped; putting users last needs no path to take a lock out of order.

## R-8 Feed queries and the position

**Decision**: each scope has one position space — its counter. A feed row's **position** is:

| Feed | Source | Position |
|---|---|---|
| wallet W | `wallets` where `id = W` | `change_seq` |
| wallet W | `wallet_members` where `wallet_id = W` | `change_seq` |
| wallet W | `category_references` where `wallet_id = W` | `change_seq` |
| wallet W | `transactions` where `wallet_id = W` | `change_seq` |
| wallet W | `transactions` where `counter_wallet_id = W` | `counter_change_seq` |
| user U | `profiles` where `user_id = U` | `change_seq` |
| user U | `device_sessions` where `user_id = U` | `change_seq` |
| user U | `categories` where `user_id = U` (person's only) | `change_seq` |
| user U | `wallet_members` where `user_id = U` | `member_change_seq` |

A page is: from each source, rows with position > p ordered by position, `LIMIT limit + 1`; merge by position; take the first `limit`; `has_more` = a row remained. Each source is served by its owning module's Application query (Wallets: `WalletFeedQuery`, `UserFeedQuery` for categories and memberships; Identity: `UserFeedQuery` for profile and sessions), returning `(type, position, resource array)` rendered with the same resource classes the read endpoints use, for the reader. Sync's `ChangeFeedService` merges. The existing indexes `(wallet_id, change_seq)` on each wallet table, the partial `(counter_wallet_id, counter_change_seq)`, and the new `(user_id, member_change_seq)` serve each source as an index range scan; `(user_id, change_seq)` indexes are added where missing (profiles, device sessions, categories).

**Rationale**: all stamps of a scope come from one counter under one lock (ADR-0008 rules 3, 5; ADR-0015 rule 3; ADR-0017 rule 2), so a merge by position is commit-ordered and gap-safe. Sync stays free of other modules' tables (P-02).

**Alternatives**: one `change_log` table written by every write — rejected: a second write per write, and the brief's own reconcile table forbids `change_log.id` as the cursor; a SQL `UNION ALL` across modules' tables in Sync — rejected (P-02).

## R-9 Cursor encoding

**Decision**: `sync_cursor` = base64url of a compact JSON `{"v":1,"f":"w"|"u","s":<scope id>,"r":<reader id>,"p":<position>}`, HMAC-SHA256-signed with the application key (truncated to 16 bytes, appended). Decoding refuses with `INVALID_CURSOR` when: not base64url, bad signature, unknown `v`, feed kind or scope differs from the path, reader differs from the caller, or `p` exceeds the scope's current counter. Expired (410) when `p` < the scope's purge horizon (R-10). A cursor from the collection endpoints (spec 010's `CollectionQuery`) fails the signature check and is `INVALID_CURSOR`. `next_cursor` equals `sync_cursor` while `has_more`, else null.

**Rationale**: opaque (spec 003) yet tamper-evident, so a client cannot skip ahead or read another reader's position; binding the reader means a cursor copied between accounts on one phone is refused instead of misapplied. No server-side cursor row (glossary: Sync Cursor is client-held).

## R-10 Purge horizon and the 410 decision

**Decision**: each scope row gets `purged_change_seq bigint not null default 0` (`wallets`, `users`). The purge (R-11) raises it to the greatest position of any tombstone it removes from that scope, in the same transaction as the removal, under the scope row lock. A feed request with cursor position `p < purged_change_seq` is `410 SYNC_CURSOR_EXPIRED`; `p ≥ purged_change_seq` is served. An absent cursor is never expired.

**Rationale**: a cursor is unsafe exactly when a tombstone the client has not seen (position > p) was removed. Comparing positions — not instants — is precise: an old cursor on a quiet scope keeps working, and no cursor is ever served a feed with a hole. ADR-0008's follow-up asked for exactly this ("the horizon value has to be readable by the pull endpoint").

**Alternatives**: expire any cursor issued more than 90 days ago (encode the issue time) — rejected: needlessly forces full resyncs of quiet scopes, and depends on the server clock in the cursor.

## R-11 The tombstone purge job

**Decision**: `sync:purge-tombstones`, scheduled daily in `routes/console.php`, owned by Sync (security baseline: Sync owns retention). Horizon from `config/sync.php` `tombstone_retention_days` (default 90; a value below 90 is clamped to 90 and logged as a warning). It calls each owning module's Application purge service (Wallets `TombstonePurge`, Identity `TombstonePurge`) which, per scope and in batches of 500 rows per transaction:

- locks the scope row (wallet or user), selects the scope's tombstones with `deleted_at < now − horizon`, deletes them, and raises the scope's `purged_change_seq` to the greatest removed position — for a transfer on **both** wallets (its `change_seq` on the source, its `counter_change_seq` on the destination, each under its own lock, in ascending order), for a membership on the wallet **and** on the member's user scope (`member_change_seq`), respecting R-7's order;
- removes, in this order per wallet: transactions, category references, wallet members; then the wallet itself only when it is tombstoned past the horizon **and** no transaction, posting, category reference or membership row still refers to it;
- removes a person's tombstoned category only when no transaction, recurring rule or budget refers to it (glossary "in use");
- logs one line per run: rows removed per type, scopes touched; no identifiers of records, no content.

Idempotent (a second run finds nothing), restartable, and safe beside writes because every deletion holds the scope lock a writer would take.

**Rationale**: ADR-0008 rule 12 and the security baseline require the purge; ADR-0008's consequences call it load-bearing. Spec 012 left "a tombstone is purged after 90 days by B14's retention".

**Alternatives**: cascade-delete a tombstoned wallet with everything under it — rejected: a live transfer from a deleted wallet keeps a live posting on it (ADR-0015 rule 5); deleting the wallet row would break that record.

## R-12 Feed authorization and deleted wallets

**Decision**: the wallet feed reuses `WalletAuthorizer`'s read rule (row W-01) including spec 010's tombstone rule: a tombstoned wallet stays readable by those who were members at deletion (their memberships tombstoned with it, not earlier). A purged wallet is `404` for everyone. The user feed needs only authentication.

## R-13 Feed and batch logging

**Decision**: one `info` line per feed read (`sync_feed_read`: feed kind, row count, `has_more`, whether the cursor was absent) and per batch (`sync_batch`: item count, counts per outcome, and for each item `type`, `op`, `outcome`, `status`, `code`), carrying the request and correlation ids from the log context. No identifiers of records, no bodies, no cursors, no amounts or text (P-15 rule 4).

## R-14 OpenAPI shape

**Decision**: `docs/openapi/paths/sync.yaml` holds the three operations and local schemas `ChangeRow` (with `type` enum and `record` as `anyOf` of the referenced resource schemas from the other path files), `ChangeFeedMeta` (`next_cursor`, `has_more`, `limit`, `sync_cursor`; `CollectionMeta` cannot be extended because it forbids additional properties), `SyncBatch`, `BatchItem` (`data` as `anyOf` of the referenced write bodies), `BatchItemResult` (`problem` references the shared `Problem`). `x-filterable: []` and `x-sortable: []` on both feeds. `info.version` → v1.6. Examples: a wallet feed page with a transfer and a tombstone, a user feed page with a membership, an expired cursor, a mixed batch and its results (applied/201, conflict/409 with `current`, rejected/422 with pointer, rejected/404), and an envelope refusal.

**Rationale**: spec 003 lets a feature add local schemas but not a second envelope; `anyOf` (not `oneOf`) because the resource schemas are not mutually exclusive under validation.

## R-15 Performance

**Decision**: targets per SC-006 — a 50-item batch of new transactions p95 < 8 s; a 200-row feed page in a 10,000-transaction wallet p95 < 300 ms — measured on the dev stack with Xdebug off. Feed pages render resources in bulk (one query per source and per derived member, e.g. display names in one call), never per row.

## R-16 Contract compatibility for the client

**Decision**: nothing an existing endpoint answers changes. Additive only: three paths, local schemas, `info.version` v1.6. The client's wave-0 engine keeps working unchanged; wave 2 switches to the feed and batch.
