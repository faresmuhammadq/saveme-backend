---

description: "Task list for Sync Protocol (B14)"
---

# Tasks: Sync Protocol

**Input**: Design documents from `/specs/013-sync-protocol/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/sync-api.md](./contracts/sync-api.md), [quickstart.md](./quickstart.md), the frozen contract `docs/openapi/paths/sync.yaml` (written and indexed; `info.version` v1.6), [ADR-0016](../../docs/adr/0016-batch-upload-beside-per-record-writes.md) and [ADR-0017](../../docs/adr/0017-membership-on-the-members-user-feed.md) (written and indexed in design).

**Tests**: included. The spec's Invariants table names every test file (constitution P-16 rule 3). Write each story's tests first and watch them fail.

**Organization**: grouped by user story, in delivery order:

- US3: my own records and my wallet list follow me (P1) — first, because it carries the user-counter switch every user-scoped write depends on
- US1: catch up on a wallet (P1)
- US2: send many offline edits in one request (P1)
- US4: a phone away for months starts over (P2)

**Conventions for every task** (as in specs 010–012):

- Run through the container: `dev exec -- vendor/bin/pest <path> --compact`, `dev <artisan command>`. The full contract suite is long; run it in the background.
- P-02 layers: protocol code in `app/Modules/Sync`; Domain ports in `Sync/Domain/Ports` (Infrastructure and Http implement them; Infrastructure depends only on Domain); Sync reaches Wallets and Identity only through their `Application` classes; console commands live in `Http/Console` (like `wallets:verify-balances`). Item dispatch names routes, never another module's controller.
- Refusals extend `Shared\Domain\Errors\Refusal` with a catalogue `ErrorCode` (`INVALID_CURSOR`, `SYNC_CURSOR_EXPIRED` already exist). No new error code.
- Empty `meta` is `(object) []`; instants with microseconds.
- **Lock order** (FR-020, ADR-0017 rule 5): category rows → wallet rows ascending → user rows ascending → records. Take the user-counter stamp as late as possible in each write.
- No record id, body, cursor, amount or note in any log line (P-15 rule 4, FR-027).
- Run `dev exec -- vendor/bin/pint --dirty --format agent` after PHP edits.
- Contract tests use `assertValidRequest()` and `assertValidResponse($status)`, covering every documented status.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on unfinished tasks)
- **[Story]**: US1–US4 from spec.md

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: the documents the code depends on, the Sync module skeleton and the schema.

- [X] T001 [P] Docs before code uses the terms (FR-026). `docs/glossary.md`, Sync section: add **Change Row** ("one record on a change feed: its type plus the record exactly as its read returns it; a deletion is a record with `deleted_at` set"), **Sync Batch** ("one upload of 1–50 per-record writes, each answered by its own Batch Item Result: `applied`, `conflict` or `rejected`; ADR-0016") and **Purge Horizon** ("per scope, the highest feed position whose tombstone has been purged; a cursor behind it is expired"); Sync Cursor notes gain "one per scope: the user scope and each wallet; bound to the feed and account that issued it"; Wallet Member notes gain "a person's own memberships also ride their user feed (ADR-0017)"; Profile/Category notes: the user scope counter is `users.change_seq`. `.specify/memory/constitution.md` → **v1.3.6 (PATCH)**: P-07's `ADRs:` line gains `[ADR-0016](../../docs/adr/0016-batch-upload-beside-per-record-writes.md) (supersedes ADR-0008 rule 7)` and `[ADR-0017](../../docs/adr/0017-membership-on-the-members-user-feed.md) (extends ADR-0008)`; P-08's line gains ADR-0017; Sync Impact Report entry ("PATCH: P-07 and P-08 list ADR-0016 and ADR-0017, introduced by spec 013; no rule changes"); version line, footer, Constitution Check template version, Last Amended. `docs/api-conventions.md`: **Writes** gains a "Batch upload" subsection (`POST /sync/batch`, 1–50 items, each item exactly one per-record write, independent, array order, per-item results, per-record writes stay; link ADR-0016); **Idempotency** gains "The sync batch carries no key: it is idempotent because every item is"; **Collections** → "What paging guarantees" gains the feed meta `sync_cursor` (never null) and the two feed endpoints; **Deleting and restoring** links the purge horizon. `docs/security-baseline.md` Retention: the deleted-syncable-record row names `sync:purge-tombstones` as the enforcing job.
- [X] T002 [P] Create the Sync module skeleton: `app/Modules/Sync/{Domain,Application,Infrastructure,Http}`; `app/Modules/Sync/Infrastructure/SyncPersistenceServiceProvider.php` (binds Domain ports to Infrastructure, loads `Infrastructure/Database/Migrations`); `app/Modules/Sync/Http/SyncHttpServiceProvider.php` (loads `Http/routes.php` under `Route::middleware('api')->prefix('api/v1')`, registers the console command); register both in `bootstrap/providers.php`; `config/sync.php` with `tombstone_retention_days` (env `SYNC_TOMBSTONE_RETENTION_DAYS`, default 90; values below 90 are clamped to 90) and `cursor_key` (defaults to `app.key`). `tests/Arch/ModuleBoundariesTest.php` stays green.
- [X] T003 Identity migration `app/Modules/Identity/Infrastructure/Database/Migrations/2026_10_04_000001_add_sync_counters_to_users.php`: `users.change_seq bigint not null default 0` ("the user scope counter"), `users.purged_change_seq bigint not null default 0`; indexes `profiles (user_id, change_seq)`, `device_sessions (user_id, change_seq)`.
- [X] T004 Wallets migration `app/Modules/Wallets/Infrastructure/Database/Migrations/2026_10_04_000002_add_sync_columns.php`: `wallets.purged_change_seq bigint not null default 0`; `wallet_members.member_change_seq bigint NULL` (NOT NULL is set by T005); index `wallet_members_user_member_change_seq_index (user_id, member_change_seq)`; partial index `categories (user_id, change_seq) WHERE user_id IS NOT NULL`. Depends on T003.
- [X] T005 Sync switch migration `app/Modules/Sync/Infrastructure/Database/Migrations/2026_10_04_000003_switch_user_scope_counter.php` (data-model.md "The switch migration"), one transaction with `LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE`: per user `base` = greatest `change_seq` over their profile, device sessions and own categories (0 if none); `wallet_members.member_change_seq = base + row_number() OVER (PARTITION BY user_id ORDER BY created_at, id)` for every membership, live or tombstoned; `users.change_seq = greatest(base, max member_change_seq)`; then `ALTER COLUMN member_change_seq SET NOT NULL`. PHPDoc: runs in maintenance mode (research R-5). Depends on T003, T004.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: the user-scope counter every user-scoped write uses, membership stamping, and the Sync domain values.

### Tests first

- [X] T006 [P] `tests/Feature/Sync/UserScopeCounterTest.php`: a profile save, a sign-in (new session), a session end and a category save each take `change_seq` = the person's previous `users.change_seq` + 1 and leave `users.change_seq` equal to it; two people's counters are independent; an identical replay moves nothing; a rolled-back write leaves the counter unchanged (no holes).
- [X] T007 [P] `tests/Feature/Sync/UserCounterBackfillTest.php`: seed profiles, sessions, categories and memberships with pre-switch stamps, run the T005 migration's up step, assert for every user that no record of their scope has `change_seq` or `member_change_seq` above `users.change_seq`, that member stamps are unique per user, and that a new write stamps above every existing one.
- [X] T008 [P] `tests/Arch/SyncCounterTest.php`: no class under `app/` references `sync_change_seq` or `nextval(`; Wallets never uses `App\Modules\Identity\Infrastructure`.
- [X] T009 [P] Unit tests in `tests/Unit/Sync/SyncCursorTest.php` (encode/decode round trip; tampered signature, other feed kind, other scope, other reader, unknown version, non-base64 → each a distinct `CursorRefusal` reason) and `tests/Unit/Sync/FeedMergeTest.php` (k-way merge of position-ordered sources takes the lowest `limit` positions, reports `has_more` when any source has a further row, and returns the incoming position on an empty page).

### Implementation

- [X] T010 Identity counter: change `app/Modules/Identity/Domain/Ports/ChangeSequence.php` to `next(string $userId): int` (PHPDoc: the user scope counter on the person's `users` row, ADR-0008 rules 2–3, ADR-0017 rule 6); replace `Infrastructure/Sync/PostgresChangeSequence.php` with `Infrastructure/Sync/UserRowChangeSequence.php` (`UPDATE users SET change_seq = change_seq + 1 WHERE id = ? RETURNING change_seq`, throwing if no row); update callers `EloquentProfileRepository`, `EloquentSessionRepository`, `SanctumSessionIssuer` to pass the owner's id; add `app/Modules/Identity/Application/Sync/UserScopeCounter.php` (`next(string $userId): int`, delegates to the port; PHPDoc: callers lock wallets first, users last). Update `ProfileFactory` and `DeviceSessionFactory` to stamp through the counter. Bind in `IdentityPersistenceServiceProvider`. T006 profile/session cases green.
- [X] T011 Wallets user stamp: change `app/Modules/Wallets/Domain/Categories/Ports/UserScopeSequence.php` to `next(string $userId): int`; `app/Modules/Wallets/Infrastructure/Sync/UserScopeSequence.php` delegates to Identity's `UserScopeCounter`; `CategoryService` passes the owner id and takes the stamp **after** any wallet locks it takes for reference republishing (research R-7); `CategoryFactory` stamps through the counter. T006 category case green.
- [X] T012 Membership stamping (ADR-0017 rule 2, FR-019): add `member_change_seq` to the `WalletMember` model and `MembershipRepository` writes; in `app/Modules/Wallets/Application/WalletService.php` stamp `member_change_seq` from `UserScopeSequence::next($memberUserId)` on create (owner membership) and on ownership transfer (both members, ascending user id, after the wallet lock); in the deletion cascade under `app/Modules/Wallets/Application/Deletion/` stamp every member's tombstoned membership (ascending user id); `WalletMemberFactory` stamps it. `member_change_seq` never appears in `WalletMemberResource`.
- [X] T013 Retire the sequence: Identity migration `app/Modules/Identity/Infrastructure/Database/Migrations/2026_10_04_000004_drop_sync_change_seq.php` (`DROP SEQUENCE IF EXISTS sync_change_seq`; down recreates it starting above `max(users.change_seq)`); remove remaining test references (`tests/Feature/Wallets/Categories/SaveCategoryTest.php` reads `last_value` — assert against `users.change_seq` instead). T006–T008 green.
- [X] T014 [P] Sync domain values in `app/Modules/Sync/Domain/`: `Cursor/FeedKind.php` (enum `user`, `wallet`), `Cursor/SyncCursor.php` (feed kind, scope id, reader id, position; `v: 1`), `Cursor/CursorRefusal.php`, `Ports/CursorSigner.php` (`sign(string): string`, `verify(string, string): bool`), `Feed/FeedRow.php` (type, position, record array), `Feed/FeedPage.php` (rows, has_more, next position), `Feed/FeedMerge.php` (pure k-way merge), `Refusals/CursorInvalid.php` (`INVALID_CURSOR`), `Refusals/CursorExpired.php` (`SYNC_CURSOR_EXPIRED`). `app/Modules/Sync/Infrastructure/HmacCursorSigner.php`: HMAC-SHA256 over the JSON with `sync.cursor_key`, truncated to 16 bytes, base64url, compared with `hash_equals`. T009 green.
- [X] T015 `tests/Feature/Sync/ScopeLockOrderTest.php` then fix any path it exposes: run, concurrently and repeatedly (two connections), a category rename that republishes into a shared wallet and an ownership transfer of that wallet involving the same two people; assert both commit, no deadlock, and each person's counter advanced by exactly the stamps taken.

**Checkpoint**: every user-scoped write stamps from `users.change_seq` under its row lock; memberships carry `member_change_seq`; the global sequence is gone.

---

## Phase 3: User Story 3 — My own records and my wallet list follow me (Priority: P1)

**Goal**: `GET /sync/user/changes` serves the caller's profile, sessions, own categories and own memberships in user-counter order, tombstones included.

**Independent Test**: read the user feed from the beginning; change the profile, a category, a session and a membership; read from the stored `sync_cursor`: exactly those changes, in order; a deleted wallet's membership arrives tombstoned.

### Tests first

- [X] T016 [P] [US3] `tests/Feature/Sync/UserFeedTest.php`: full read with no cursor returns profile, sessions, own categories (never platform ones, never another person's) and own memberships, ordered by position, tombstones included; reading from `sync_cursor` returns only later changes; a record changed twice arrives once in its latest state; `device_session.current` is true only for the reading session; empty page returns the same position.
- [X] T017 [P] [US3] `tests/Feature/Sync/MembershipOnUserFeedTest.php`: wallet creation puts the owner's membership on their user feed; ownership transfer puts both changed memberships on each member's own feed only; wallet deletion puts each member's tombstoned membership on that member's feed; another member's membership never appears on a person's user feed; the row equals the wallet feed's row for the same membership.
- [X] T018 [P] [US3] `tests/Contract/Sync/ListUserChangesTest.php`: `200` (first page, later page, empty page, `meta.sync_cursor` never null, `limit` clamped to 200), `400 INVALID_CURSOR` (garbage; a wallet-feed cursor; another account's cursor; a cursor ahead of the counter), `400 FILTER_FIELD_NOT_SUPPORTED`, `400 SORT_FIELD_NOT_SUPPORTED`, `400 INVALID_QUERY_PARAMETER` (`limit=0`), `401`, `406`, `410 SYNC_CURSOR_EXPIRED` (raise `users.purged_change_seq` above the cursor), `426`; an unknown parameter `since` is ignored.

### Implementation

- [X] T019 [P] [US3] Identity feed source `app/Modules/Identity/Application/Sync/UserFeedQuery.php`: `page(string $userId, string $readerSessionId, int $after, int $limit): list<array{type: string, position: int, record: array}>` over profiles (`type: profile`) and device sessions (`type: device_session`) with `change_seq > $after ORDER BY change_seq LIMIT $limit + 1`, records rendered exactly as `GET /me` (`profile` member) and `GET /auth/sessions` render them (reuse their resource/DTO mapping; `current` relative to the reader's session); `scope(string $userId): array{counter: int, purged: int}`.
- [X] T020 [P] [US3] Wallets user-feed source `app/Modules/Wallets/Application/Sync/UserFeedQuery.php`: person's categories (`type: category`, `categories.user_id = U`, by `change_seq`, rendered as `CategoryResource`) and the person's memberships (`type: wallet_member`, `wallet_members.user_id = U`, by `member_change_seq`, rendered as `WalletMemberResource` with display names fetched in one call); same signature and `LIMIT + 1` paging.
- [X] T021 [US3] `app/Modules/Sync/Application/ChangeFeedService.php` `userPage(string $readerId, string $readerSessionId, ?string $cursor, int $limit): FeedPage`: decode and verify the cursor (bound to feed `user`, scope = reader, reader = reader) → `CursorInvalid`; `p > counter` → `CursorInvalid`; `p < purged` → `CursorExpired`; fetch both sources, `FeedMerge`, sign `sync_cursor`; log `sync_feed_read` (feed kind, row count, has_more, cursor absent) with no ids or content. Depends on T014, T019, T020.
- [X] T022 [US3] HTTP: `app/Modules/Sync/Http/routes.php` (`GET sync/user/changes` behind `auth:api`), `Http/Requests/FeedRequest.php` (only `cursor` and `limit`; `filter[…]` → `FILTER_FIELD_NOT_SUPPORTED`, `sort` → `SORT_FIELD_NOT_SUPPORTED`, `limit` integer ≥ 1 else `INVALID_QUERY_PARAMETER`, clamped to 200, default 50; other parameters ignored), `Http/Controllers/UserChangesController.php` returning `{data: [{type, record}], meta: {next_cursor, has_more, limit, sync_cursor}}`. T016–T018 green.

**Checkpoint**: a phone can sync its own scope and discover its wallets.

---

## Phase 4: User Story 1 — Catch up on a wallet after being offline (Priority: P1)

**Goal**: `GET /sync/wallets/{id}/changes` serves the wallet, members, category references, transactions and transfers into it, in wallet-counter order, tombstones included.

**Independent Test**: as a member, read the feed from the beginning, have another member write, read from `sync_cursor`: exactly the new writes, in order, deletions included; a transfer into the wallet arrives as the identical record.

### Tests first

- [X] T023 [P] [US1] `tests/Feature/Sync/WalletFeedTest.php`: full read returns the wallet, its members, category references, transactions and transfers into it, ordered by position, tombstones included; incremental read from `sync_cursor`; the wallet record re-sent after any write in the wallet; `my_role` is the reader's; paging with `limit=2` walks every row exactly once in order; a deleted, unpurged wallet's feed is readable by members at deletion and `404` to a person whose membership ended earlier.
- [X] T024 [P] [US1] `tests/Feature/Sync/TransferOnTwoFeedsTest.php`: a transfer A → B appears on A's feed at its `change_seq` and on B's feed at its `counter_change_seq`, identical as JSON on both, readable on B's feed by a member of B who is not a member of A, and on no third wallet's feed; editing it re-delivers it on both feeds.
- [X] T025 [P] [US1] `tests/Feature/Sync/FeedGapSafetyTest.php`: with two writer connections committing interleaved writes into one wallet (and, separately, into one user scope: a category save on one connection while a session ends on the other) and a reader paging between commits, the union of rows read equals the set of committed records — nothing skipped — over 1,000 interleaved writes; every position in a scope is unique.
- [X] T026 [P] [US1] `tests/Contract/Sync/ListWalletChangesTest.php`: `200` (first, later, empty page; `sync_cursor` never null), `400 INVALID_CURSOR` (garbage, user-feed cursor, another wallet's cursor, ahead of counter), `400 FILTER_FIELD_NOT_SUPPORTED`, `400 SORT_FIELD_NOT_SUPPORTED`, `401`, `404 NOT_FOUND` (non-member, unknown wallet, purged wallet), `406`, `410 SYNC_CURSOR_EXPIRED`, `422 INVALID_ID`, `426`; no response contains a `postings` member or posting data (P-04 rule 6).

### Implementation

- [X] T027 [US1] Wallets feed source `app/Modules/Wallets/Application/Sync/WalletFeedQuery.php`: authorise the reader by row W-01 through `WalletAuthorizer`, including spec 010's deleted-wallet rule (members at deletion), else `WalletNotFound`; sources `wallet` (`wallets.id = W`, `change_seq`), `wallet_member` (`wallet_id = W`, `change_seq`), `category_reference` (`wallet_id = W`, `change_seq`), `transaction` (`wallet_id = W`, `change_seq`) and `transaction` (`counter_wallet_id = W`, `counter_change_seq`), each `> $after ORDER BY position LIMIT $limit + 1`, rendered with `WalletResource` (reader's `my_role`), `WalletMemberResource`, `CategoryReferenceResource`, `TransactionResource` in bulk (no per-row queries); `scope(string $walletId): array{counter: int, purged: int}`.
- [X] T028 [US1] `ChangeFeedService::walletPage(string $readerId, string $walletId, ?string $cursor, int $limit): FeedPage` (cursor bound to feed `wallet`, scope = wallet, reader = reader; same invalid/expired rules), route `GET sync/wallets/{id}/changes` (`auth:api`; malformed id → `422 INVALID_ID`), `Http/Controllers/WalletChangesController.php`. T023–T026 green.

**Checkpoint**: a phone can catch up on every wallet it belongs to.

---

## Phase 5: User Story 2 — Send many offline edits in one request (Priority: P1)

**Goal**: `POST /sync/batch` applies 1–50 per-record writes one at a time in order and answers each with the status and body its single write gives.

**Independent Test**: send a batch mixing creates, a stale edit, a delete and an invalid item: each result matches the same write sent alone; resending creates nothing twice.

### Tests first

- [X] T029 [P] [US2] Unit tests `tests/Unit/Sync/BatchEnvelopeTest.php` (the R-4 table: every pointer and field code, `OUT_OF_RANGE` for 0 and 51 items, op not allowed for type, `wallet_id` required on `transaction` and `UNKNOWN_FIELD` elsewhere, `INVALID_ID` for bad ids) and `tests/Unit/Sync/ItemOutcomeTest.php` (200/201 → `applied`; 409 with `VERSION_CONFLICT` → `conflict`; 409 `CATEGORY_IN_USE`, 403, 404, 422, 500 → `rejected`).
- [X] T030 [P] [US2] `tests/Contract/Sync/BatchParityTest.php`: for each type/op (wallet upsert create/update/replay/stale/delete; category upsert/delete incl. `CATEGORY_IN_USE`; transaction upsert/delete/restore incl. transfer, viewer `403`, unknown `404`, `AMOUNT_NOT_INTEGER`, unverified `EMAIL_NOT_VERIFIED`; profile upsert and wrong profile id `404`), send the write alone on one fresh fixture and inside a batch on an identical fixture: the item's `status` equals the single response's status and its `record`/`problem` equals the single body (ignoring `request_id` and instants that differ by fixture); validation pointers are rooted at `data`. Every response validates against `sync.yaml`.
- [X] T031 [P] [US2] `tests/Contract/Sync/UploadSyncBatchTest.php`: `200` mixed results (the `mixedResults` example's shape), `200` with every item rejected, `400 MALFORMED_JSON`, `401`, `406`, `415`, `422 VALIDATION_FAILED` for each envelope refusal (nothing written — assert table counts unchanged), `426`; an `Idempotency-Key` header is ignored and nothing is stored.
- [X] T032 [P] [US2] `tests/Feature/Sync/BatchOrderingTest.php`: a batch creating a wallet, then a category, then a transaction using both is fully applied; results are in item order; the same record twice (identical → second is a replay `200`; different body with the old version → second is `conflict`).
- [X] T033 [P] [US2] `tests/Feature/Sync/BatchConflictTest.php`: a stale item is `conflict` with `problem.current` the stored record (and the tombstone when deleted elsewhere); nothing of it stored or merged; the other items applied.
- [X] T034 [P] [US2] `tests/Feature/Sync/BatchReplayTest.php`: resending the same batch any number of times creates nothing twice and moves no `version`, `change_seq` or balance for already-applied items, which come back `applied` (`200`).
- [X] T035 [P] [US2] `tests/Feature/Sync/BatchFailureIsolationTest.php`: an item whose write throws an unexpected exception is `rejected` `500 INTERNAL_ERROR`, earlier items stay committed, later items run; the batch holds no outer transaction (a later item sees an earlier item's commit from another connection); revoking the caller's session after the first item does not stop the remaining items (FR-016a: authenticated once per batch).

### Implementation

- [X] T036 [P] [US2] Batch domain in `app/Modules/Sync/Domain/Batch/`: `ItemType` (enum `wallet`, `category`, `transaction`, `profile`, with `allowedOperations()` per FR-017), `ItemOperation` (enum `upsert`, `delete`, `restore`), `BatchItem` (type, op, id, wallet id or null, data array), `ItemOutcome` (from status and problem code), `BatchItemResult`; `app/Modules/Sync/Domain/Ports/ItemDispatcher.php` (`dispatch(BatchItem $item): array{status: int, body: array}`).
- [X] T037 [US2] `app/Modules/Sync/Http/Requests/SyncBatchRequest.php`: the envelope rules of research R-4 exactly (root members, `items` 1–50, per-item members, enums, op allowed for type, UUIDv7 via the shared rule with field code `INVALID_ID`, `wallet_id` presence rule, `data` an object); never validates inside `data`.
- [X] T038 [US2] `app/Modules/Sync/Http/RouterItemDispatcher.php` implementing `ItemDispatcher` (research R-2): map type/op to the named route and method (`wallets.upsert` PUT, `wallets.destroy` DELETE, `categories.upsert`, `categories.destroy`, `transactions.upsert`, `transactions.destroy`, `transactions.patch` with `{deleted_at: null, version}` from `data`, the profile save route (`PUT /me/profile`, Identity's named route) — first checking `id` equals the caller's profile id through Identity's application layer, else a `404 NOT_FOUND` problem); build a sub-request with the JSON `data` body, the caller's resolved user (`setUserResolver`), `Accept-Language`, `X-Client-Platform`, `X-Client-Version`, `X-Correlation-Id`, `Content-Type`/`Accept: application/json`; dispatch through the router so route middleware (`auth:api`, `address.verified`) runs; render any escaping throwable with `ProblemRenderer` (500 → `INTERNAL_ERROR`, logged with the batch request id); restore the container's request afterwards; overwrite `request_id` in a captured problem with the batch's. First prove it in a spike test inside T035/T030 (guard keeps the user; parent request restored; exception isolated).
- [X] T039 [US2] `app/Modules/Sync/Application/UpsertService.php`: loop items in order, dispatch each, map to `BatchItemResult`, never wrap in a transaction; log one `sync_batch` line (item count, counts per outcome, per item `type`, `op`, `outcome`, `status`, `code`; no ids, bodies or content). Route `POST sync/batch` (`auth:api`), `Http/Controllers/SyncBatchController.php` returning `{data: [results], meta: {}}`. T029–T035 green.

**Checkpoint**: a phone can push its whole backlog and handle each result.

---

## Phase 6: User Story 4 — A phone away for months starts over cleanly (Priority: P2)

**Goal**: tombstones older than 90 days are purged daily; the purge horizon makes older cursors `410`.

**Independent Test**: purge a tombstone newer than a stored cursor; read with it → `410`; read with no cursor → the full current state.

### Tests first

- [X] T040 [P] [US4] `tests/Feature/Sync/PurgeTombstonesTest.php`: with the clock travelled, tombstones older than the retention (transactions, category references, memberships, person's categories, tombstoned wallets) are removed and younger ones kept; a transfer tombstone raises both wallets' `purged_change_seq` (source by `change_seq`, destination by `counter_change_seq`); a membership tombstone raises the wallet's and the member's user `purged_change_seq`; a tombstoned wallet still referenced by a live transfer (or its postings) is kept, and removed on a later run once unreferenced; a referenced tombstoned category is kept; a second run removes nothing; a configured retention of 30 is clamped to 90; one summary log line per run without ids or content.
- [X] T041 [P] [US4] `tests/Feature/Sync/CursorExpiryTest.php`: a cursor behind a purged position → `CursorExpired` for that scope only (other wallets and the user feed unaffected); a cursor at or after the horizon, however old, is served; an absent cursor is never expired and returns the state without the purged rows; a purged wallet's feed is `404`.

### Implementation

- [X] T042 [P] [US4] `app/Modules/Wallets/Application/Sync/TombstonePurge.php`: `purge(DateTimeImmutable $before, int $batchSize = 500): array<string, int>` — per wallet scope with expired tombstones, in a transaction: first collect the set of wallet rows the batch of expired tombstones touches (this wallet plus the other wallet of each expired transfer) and lock them all in ascending id order, then the affected member/owner user rows in ascending id order through Identity's `TombstonePurge::raiseHorizon` (ADR-0017 rule 5: wallets before users, ascending), delete expired transaction tombstones (postings already gone), category references and memberships, raise each affected scope's `purged_change_seq` to the greatest removed position (never lower); delete a tombstoned wallet past the horizon only when no transaction, posting, reference or membership refers to it; delete a person's tombstoned category past the horizon only when nothing refers to it (raising the owner's user horizon through Identity). Returns rows removed per type.
- [X] T043 [P] [US4] `app/Modules/Identity/Application/Sync/TombstonePurge.php`: purges tombstoned profiles or device sessions past the horizon if any exist (none today) and exposes `raiseHorizon(string $userId, int $position): void` (locks the user row, `purged_change_seq = greatest(purged_change_seq, ?)`) used by Wallets for categories and memberships.
- [X] T044 [US4] `app/Modules/Sync/Application/TombstonePurgeRun.php` (horizon from `config('sync.tombstone_retention_days')`, clamped ≥ 90 with a warning; calls Wallets then Identity purge; logs one `sync_tombstones_purged` line with counts per type) and `app/Modules/Sync/Http/Console/PurgeTombstones.php` (`sync:purge-tombstones`); schedule it daily in `routes/console.php` with a comment ("Purges tombstones past the 90-day horizon and raises purge horizons (ADR-0008 rule 12, spec 013)"). T040–T041 green.

**Checkpoint**: retention enforced; expired cursors answered explicitly.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [X] T045 [P] `tests/Feature/Sync/SyncLoggingTest.php` with `Log::spy()`: a feed read, a batch with every outcome and a purge run each log exactly one summary line; none contains a record id, an amount, a description, a memo, a cursor or a request body.
- [X] T046 Performance (R-15, SC-006), dev stack with Xdebug off: seed a wallet with 10,000 transactions and a user with 200 categories and 50 memberships; measure p95 of a 200-row page on both feeds (< 300 ms) and of a 50-item batch of creates (< 8 s); record the numbers in plan.md under a "Measured" note. Fix N+1 rendering if a target is missed.
- [X] T047 Apply the endpoint review checklist and the security review checklist to the three operations in `docs/openapi/paths/sync.yaml` (plan.md "Review checklists"); record the result in plan.md. Confirm the implementation changed nothing the client binds to in `contracts/sync-api.md`; if anything had to change, bump `info.version` and say so in the summary.
- [X] T048 Run the gates: `dev exec -- composer test:lint`, `test:types`, `test:arch`, then `dev exec -- vendor/bin/pest tests/Unit tests/Feature/Sync tests/Contract/Sync --compact`, then the full Feature and Contract suites in the background (no existing test changed except `SaveCategoryTest`'s sequence read, T013).
- [X] T049 [P] Record B14's obligations on later features under a short "Inherited from B14 (spec 013)" note in `specs/briefs/B07-recurring-transactions.md` (recurring rules and occurrences join the wallet feed and the batch type list), `specs/briefs/B08-budgets.md` (budgets join the user feed and the batch; stamp from `users.change_seq` under the user lock, users locked last), `specs/briefs/B09-sharing-invitations.md` (every grant, role change, removal and leave stamps `member_change_seq` per ADR-0017; invitations join the wallet feed) and `specs/briefs/B15-export-data-deletion.md` (account deletion purges the user scope; memberships tombstoned per ADR-0017).
- [X] T050 Write specs/013-sync-protocol/summary.md (parent writes it).

---

## Dependencies & Execution Order

- **Phase 1** → **Phase 2** → stories. T003 → T004 → T005 (migration order). T010 → T011 → T012 → T013.
- **US3** (Phase 3) needs Phase 2 (user counter, member stamps). **US1** (Phase 4) needs T014 and T021's `ChangeFeedService` shape; it does not need US3's controllers. **US2** (Phase 5) needs only Phase 1–2 (it dispatches existing routes); it can run in parallel with US3/US1. **US4** (Phase 6) needs T021 and T028 (horizon checks in the feed service) and T012 (member stamps).
- **Polish** after all stories; T050 last.

### Parallel opportunities

- Phase 1: T001 and T002 together.
- Phase 2: T006–T009 together; T014 beside T010–T013.
- US3: T016–T018 together; T019 and T020 together.
- US1: T023–T026 together.
- US2: T029–T035 together; T036 beside the tests.
- US4: T040–T043 together.

## Implementation Strategy

1. Phases 1–2: the counter switch is the riskiest change (it touches Identity and Wallets write paths); land it with its tests before any endpoint.
2. US3 then US1: the two feeds — the wave-2 client can pull.
3. US2: the batch — the client can push.
4. US4: retention and expiry.
5. Polish, gates, summary.
