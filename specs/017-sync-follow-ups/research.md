# Research: Sync Follow-ups (spec 017, B14a)

Each item: decision, rationale, alternatives. Code facts were read from `main` at `2a3b414`.

## R-1 How a phone learns which wallets moved

- **Decision**: `GET /sync/wallets/heads` (Sync module, `listWalletHeads`), one entry per non-deleted wallet with an active membership of the caller: `wallet_id`, `change_seq` (scope head), `purged_change_seq` (purge horizon). Plus `meta.sync_position` on every feed page.
- **Rationale**: the wallet scope's counter is the `wallets.change_seq` column itself (`WalletScopeSequence::nextFor` is `UPDATE wallets SET change_seq = change_seq + 1 … RETURNING`), so the head is one column read per wallet, joined to the caller's active memberships. A refresh with nothing new becomes user feed + heads = 2 requests.
- **Alternatives**: membership rows on the user feed carrying the wallet counter (a user stamp per member on every wallet write; rejected in ADR-0017 already); heads in the user feed's meta (couples scopes, grows every page); `POST` with the phone's cursors (a read as POST, and the server would decode every cursor); comparing heads with cursor strings (cursors must never be compared, api-conventions).

## R-2 What the phone compares the head with

- **Decision**: a new integer `meta.sync_position` = the position the page's cursor encodes (`FeedPage::nextPosition`, already computed by `FeedMerge`). Added to `FeedResult` and `FeedResponse`; declared optional in `ChangeFeedMeta`, always sent.
- **Rationale**: the cursor stays opaque; the integer is the same value the cursor signs, so the two never disagree. Caught-up invariant: every write in a wallet scope bumps `wallets.change_seq`, so the wallet row's feed position *is* the head; a read to `has_more: false` that included the wallet row ends at the head. `FeedMerge` drops rows above the counter read at the start, so a concurrent write leaves the position below the new head — reported next time, never skipped.
- **Alternatives**: the held wallet record's `change_seq` (unsafe: a phone applying its own write's answer, e.g. `PUT /wallets/{id}`, holds a record newer than its feed position and would skip partners' rows between); decoding the cursor on the phone (forbidden).

## R-3 Which wallets the heads list, and paging

- **Decision**: `wallet_members.deleted_at IS NULL` for the caller joined to `wallets.deleted_at IS NULL`, ordered by `wallet_id`, keyset-paged through the shared `CollectionQuery` (no filterable fields, no sortable fields, default 50, max 200; cursor bound to the path). Archived wallets included.
- **Rationale**: matches spec 010 row W-01 for live wallets; deleted wallets and ended memberships are signalled by the user feed (ADR-0017 rule 4), so the heads never act as a removal signal. Paging follows api-conventions "Collections".
- **Alternatives**: unpaged list (breaks the collection rule); include deleted-not-purged wallets readable by former members (adds a second authorization path for no client benefit).
- **Implementation check**: confirm `CollectionQuery::from($request, [], [], 'wallet_id')` refuses any `sort` parameter while applying the default internally; if it accepts the default sort name as a client value, refuse `sort` in the controller first as `FeedRequest` does.

## R-4 Where the heads code lives

- **Decision**: Wallets publishes `App\Modules\Wallets\Application\Sync\WalletHeadsQuery::page(readerId, ?afterWalletId, limit)` returning `list<WalletHead>` DTOs (`walletId`, `head`, `purged`), backed by a new `SyncFeedReader::headsFor(userId, ?afterWalletId, limit)` in `EloquentSyncFeedReader` (one query). Sync owns `WalletHeadsController` and the route `GET sync/wallets/heads` (name `sync.wallet.heads`), registered **before** `sync/wallets/{id}/changes` (no clash either way: different segment count).
- **Rationale**: P-02 — Sync calls a Wallets Application query, as `ChangeFeedService` already does; Wallets owns the tables.
- **Alternatives**: a Sync service reading the tables (P-02 violation).

## R-5 Index for the heads query

- **Decision**: no new index. `wallet_members` already has the partial index `wallet_members_user_active_index (user_id) WHERE deleted_at IS NULL` (spec 010 migration `2026_10_02_000002`); the join hits `wallets` by primary key. Verify with `EXPLAIN` in T-perf; add a migration only if p95 exceeds 300 ms for 200 wallets.
- **Rationale**: 200 rows per person at most per page; a primary-key join.

## R-6 When and how a rename re-stamps memberships

- **Decision**: Wallets listener `App\Modules\Wallets\Application\Membership\Listeners\ResendMemberNames` (queued, `ShouldQueue`) on Identity's `ProfileUpdated` (already `ShouldDispatchAfterCommit`, payload `changedFields`). When `display_name` ∈ `changedFields` it dispatches the job `RestampMemberNames(userId, afterWalletId = '')` (`ShouldQueue`, `ShouldBeUniqueUntilProcessing`, `uniqueId = userId.'|'.afterWalletId`). The job calls `MembershipService::restampNames(userId, afterWalletId, 50)`:
  1. list up to 51 wallet ids of the person's live memberships in non-deleted wallets, `wallet_id > afterWalletId`, ascending (`MembershipRepository::liveWalletIdsOf`);
  2. for each of the first 50: `WalletScopeWrites::within(walletId)` → re-read `activeFor(walletId, userId)`; skip if gone or the wallet is a tombstone; else `MembershipRepository::restamp(memberId, $scope->nextChangeSeq())` (sets `change_seq` only); the scope stamps the wallet last;
  3. if a 51st existed, dispatch `RestampMemberNames(userId, lastWalletId)`.
- **Rationale**: after commit (P-11 rule 1) and outside the profile transaction, so the user row lock is never held with a wallet lock (ADR-0017 rule 5); one wallet lock per unit of work; bounded per run. `SaveProfile` already emits `ProfileUpdated` only when values changed (an identical save returns early; a refused save throws before the event), and only lists changed members, so FR-009 holds without new code there. A batch `profile` item runs through the same route and service.
- **Coalescing**: `ShouldBeUniqueUntilProcessing` drops a second dispatch while the first is still waiting, and releases the lock when processing starts, so a rename committed during a run queues a fresh run; names are rendered at read, so whichever run is last covers the latest name.
- **Alternatives**: inside `SaveProfile` (lock-order breach, slow rename); `ShouldBeUnique` until processed (would drop a rename that commits mid-run, leaving stale names); one transaction for all wallets (many wallet locks at once).
- **Implementation check**: unique jobs need a lock-capable cache store (`array` in tests, the app's store in deployment); confirm with `search-docs` ("unique jobs", "ShouldBeUniqueUntilProcessing") before writing the job.

## R-7 What a re-stamp changes on the row

- **Decision**: `wallet_members.change_seq` only. Not `version`, `updated_at`, `member_change_seq`.
- **Rationale**: the record's own fields did not change; `version` is for conflicts on client writes (no client writes a membership); the client applies equal versions (KMP data-model: "apply if version ≥ stored"). Not moving `member_change_seq` means no user-row lock and no extra row on the person's own feed, whose phone has the name from its profile.
- **Alternatives**: bump `version` (a fake edit that would make a concurrent role change by the owner conflict); bump `member_change_seq` (needs user locks after wallet locks — legal, but unnecessary work).

## R-8 Audit, activity, events, logs

- **Decision**: no audit entry, no activity entry, no event. One info log line per job run: `member_names_restamped` with `wallets_restamped`, `wallets_skipped`, `continued` (no user id at info, per spec 016 assumed default 25). One info line per heads read: `sync_wallet_heads_read` with `wallets`, `has_more`.
- **Rationale**: ADR-0020 rule 3 (only applied wallet acts); security baseline logging rules.

## R-9 Restore + edit

- **Decision**: no new batch operation; describe the pair in `sync.yaml` (`POST /sync/batch`). `PATCH` already documents "live again at version + 1" and "restoring a live transaction returns it unchanged"; `PUT` already refuses a tombstone with `409`. A contract test proves the pair in both directions.
- **Rationale**: ADR-0016 rule 2; zero code.
- **Alternatives**: a `restore` op carrying a full body (a write no per-record endpoint accepts).

## R-10 Contract version and client compatibility

- **Decision**: `info.version` v1.9 → v1.10; `sync.yaml` header notes the additions; examples gain `sync_position`. Existing client fixtures without `sync_position` still validate (member optional).
- **Rationale**: additive only (FR-016); Redocly warnings unchanged at 203.

## R-11 Docs touched

- Glossary: Scope Head, Sync Position (added in this change, before use).
- ADR-0021 (extends ADR-0008, ADR-0017; replaces ADR-0017's rename consequence), indexed in `docs/adr/README.md`.
- To do in implementation: constitution PATCH v1.3.10 (P-07, P-08, P-11 list ADR-0021); `docs/api-conventions.md` change-feed meta (`sync_position`) and the heads read; brief B15 "Inherited from B14a" note (any display-name erasure publishes `ProfileUpdated` with `display_name`); spec 013 summary's remaining-risk line is historical and stays.
