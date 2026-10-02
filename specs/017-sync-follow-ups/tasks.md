---
description: "Task list for spec 017, Sync Follow-ups (B14a)"
---

# Tasks: Sync Follow-ups

**Input**: Design documents from `specs/017-sync-follow-ups/` (spec.md, plan.md, research.md, data-model.md, contracts/sync-follow-ups-api.md, quickstart.md)

**Prerequisites**: the contract is already frozen at v1.10 (`docs/openapi/paths/sync.yaml`, `docs/openapi/openapi.yaml`), the glossary has Scope Head and Sync Position, and ADR-0021 is written and indexed. Do not change them except to fix a defect found by a test.

**Tests**: required by the constitution (P-16 rule 3) and CONTRIBUTING items 9–11; every invariant in spec §4 has a test task. Write each test first and see it fail.

**Organization**: by user story. Run everything in the container: `dev exec -- vendor/bin/pest <path>`; Pint with `dev exec -- vendor/bin/pint --dirty --format agent`.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1 heads and positions, US2 names, US3 restore pair

---

## Phase 1: Setup

- [X] T001 Read `.ai/rules/engineering.md`, CONTRIBUTING.md, ADR-0021 and research R-1…R-11; confirm `dev exec -- vendor/bin/pest tests/Contract/OpenApi` passes on the frozen v1.10 document before any code change (it must stay green throughout).

---

## Phase 2: Foundational

No shared prerequisite: the three stories touch disjoint code (Sync feed meta and a new read; Wallets membership re-stamp; tests only). Proceed to the stories.

---

## Phase 3: User Story 1 — A refresh reads only the wallets that changed (Priority: P1) 🎯 MVP

**Goal**: `meta.sync_position` on every feed page and `GET /sync/wallets/heads` (FR-001–FR-006, FR-017, FR-018).

**Independent Test**: spec US1 — read three wallets to the end, store `sync_position`, read heads (all equal); write in one wallet; read heads (only that one greater).

### Tests for User Story 1

- [X] T002 [P] [US1] Feature test `tests/Feature/Sync/SyncPositionTest.php`: `ChangeFeedService::userPage`/`walletPage` results carry `syncPosition` equal to the position signed into `syncCursor` (decode with the `CursorSigner`) for a first page, an intermediate page (`has_more`), the last page, an empty page from a stored cursor (same position) and a cursorless read of an empty scope (0); a transfer into a wallet ends the destination's page at the destination stamp, not the record's `change_seq`.
- [X] T003 [P] [US1] Feature test `tests/Feature/Sync/WalletHeadsTest.php` (Wallets `WalletHeadsQuery`): lists exactly the caller's active memberships in non-deleted wallets (archived included; ended membership, deleted wallet and foreign wallet absent), ordered by wallet id, keyset paging with `limit + 1` lookahead; each entry's `head` equals `wallets.change_seq` and `purged` equals `wallets.purged_change_seq`; after reading a wallet feed to `has_more = false` the stored `sync_position` equals the head; a transaction, a membership change, a category reference, a recurring rule, an occurrence, an invitation and a wallet edit each move the head above it; a transfer moves both wallets' heads; a raised purge horizon is reported.
- [X] T004 [P] [US1] Contract test `tests/Contract/Sync/ListWalletHeadsTest.php` (Spectator, `assertValidRequest()` + `assertValidResponse($status)`): 200 with entries; 200 `data: []` for a caller in no wallet; two pages with `limit=1` (`next_cursor`, `has_more`); `limit=500` clamped to 200 in `meta.limit`; 400 `FILTER_FIELD_NOT_SUPPORTED` for `filter[wallet_id]=…`; 400 `SORT_FIELD_NOT_SUPPORTED` for `sort=wallet_id`; 400 `INVALID_QUERY_PARAMETER` for `limit=0`; 400 `INVALID_CURSOR` for a garbage cursor; 401 without a token; 426 with an unsupported client version; an unverified caller gets 200.
- [X] T005 [P] [US1] Contract test `tests/Contract/Sync/FeedPositionTest.php`: both feeds validate against `ChangeFeedMeta` with `sync_position` present; it equals the last row's feed position, and an empty page repeats the incoming position.

### Implementation for User Story 1

- [X] T006 [US1] Add `public int $syncPosition` to `app/Modules/Sync/Application/FeedResult.php` and set it from `FeedPage::nextPosition` in `ChangeFeedService::result()` (`app/Modules/Sync/Application/ChangeFeedService.php`); render `'sync_position' => $result->syncPosition` in `app/Modules/Sync/Http/Controllers/FeedResponse.php` after `sync_cursor` (research R-2, plan N-1).
- [X] T007 [P] [US1] Add `headsFor(string $userId, ?string $afterWalletId, int $limit): array` to `app/Modules/Wallets/Domain/Ports/SyncFeedReader.php` (returns `list<array{wallet_id: string, change_seq: int, purged_change_seq: int}>`, at most `$limit + 1`) and implement it in `app/Modules/Wallets/Infrastructure/Repositories/EloquentSyncFeedReader.php` as one query: `wallet_members` (`user_id = ?`, `deleted_at IS NULL`) join `wallets` (`deleted_at IS NULL`), `wallets.id > ?` when a cursor is given, `ORDER BY wallets.id`, selecting `id`, `change_seq`, `purged_change_seq` (plan N-3; uses the existing partial index `wallet_members_user_active_index`).
- [X] T008 [US1] Create `app/Modules/Wallets/Application/Sync/WalletHead.php` (final readonly DTO: `walletId`, `head`, `purged`) and `app/Modules/Wallets/Application/Sync/WalletHeadsQuery.php` with `page(string $readerId, ?string $afterWalletId, int $limit): array` returning `list<WalletHead>` (at most `limit + 1`); no authorization beyond the membership filter (FR-002, FR-006).
- [X] T009 [US1] Create `app/Modules/Sync/Http/Controllers/WalletHeadsController.php`: parse the query with `App\Http\Api\Collections\CollectionQuery::from($request, [], [], 'wallet_id')` (first confirm a client `sort` is refused with `SORT_FIELD_NOT_SUPPORTED`; if not, refuse `sort` before it as `FeedRequest` does — research R-3), call `WalletHeadsQuery::page`, render `data` as `{wallet_id, change_seq, purged_change_seq}` and `meta` `{next_cursor, has_more, limit}` through `App\Http\Api\Envelope::ok` with the collection cursor carrying the last `wallet_id`; log `sync_wallet_heads_read` with `wallets` and `has_more` only (FR-017).
- [X] T010 [US1] Register `Route::get('sync/wallets/heads', WalletHeadsController::class)->name('wallet.heads');` in `app/Modules/Sync/Http/routes.php` inside the `sync.` `auth:api` group, before `sync/wallets/{id}/changes`.
- [X] T011 [US1] Run T002–T005 until green: `dev exec -- vendor/bin/pest tests/Feature/Sync/SyncPositionTest.php tests/Feature/Sync/WalletHeadsTest.php tests/Contract/Sync/ListWalletHeadsTest.php tests/Contract/Sync/FeedPositionTest.php`, plus the existing `tests/Contract/Sync/ListUserChangesTest.php` and `tests/Contract/Sync/ListWalletChangesTest.php` unchanged.

**Checkpoint**: US1 is shippable alone; the client can adopt heads.

---

## Phase 4: User Story 2 — Partners see a member's new name (Priority: P1)

**Goal**: a display-name change re-stamps the person's live memberships on each wallet feed after commit, one wallet lock per unit of work, 50 wallets per run (FR-007–FR-013, FR-019, FR-020).

**Independent Test**: spec US2 — two shared wallets and one left wallet; rename; run the queue; both shared feeds re-send the membership with the new name and the same `version`; the left wallet's feed is untouched.

### Tests for User Story 2

- [X] T012 [US2] Feature test `tests/Feature/Wallets/MemberNameRestampTest.php` (follow the setup of `tests/Feature/Wallets/MembershipChangesTest.php` and the lock assertions of `tests/Feature/Sync/ScopeLockOrderTest.php`): (a) a rename through `SaveProfile` re-sends the membership on every shared wallet's feed above a partner's stored position with the new `display_name`, `version`, `updated_at` and `member_change_seq` unchanged, and the wallet record re-sent with its `version` unchanged; (b) the person's user feed gains no membership row; (c) clearing the name re-sends with `display_name: null`; (d) a timezone-only save, an identical save and a stale (409) save re-stamp nothing; (e) an ended membership, a deleted wallet and an archived wallet: only the archived one is re-stamped; (f) 120 wallets: the first run re-stamps 50 and queues a continuation, all 120 re-stamped after the queue drains, each wallet once per run; (g) the job runs outside any transaction (`DB::transactionLevel() === 0` at start, after the profile commit) and each wallet's unit of work locks only that wallet row and no `users` row; (h) no audit entry, no activity entry and no domain event are written; (i) a run failing on wallet k (simulate with a throwing repository double) leaves wallets before k re-stamped and a retry finishes the rest; (j) a `profile` item in `POST /sync/batch` triggers the same re-stamp; (k) a second rename dispatched while a run is still waiting is coalesced (one run), and a rename committed after a run started queues a new run (`ShouldBeUniqueUntilProcessing`).

### Implementation for User Story 2

- [X] T013 [P] [US2] Add to `app/Modules/Wallets/Domain/Ports/MembershipRepository.php` and implement in `app/Modules/Wallets/Infrastructure/Repositories/EloquentMembershipRepository.php`: `liveWalletIdsOf(string $userId, string $afterWalletId, int $limit): array` (wallet ids of the person's memberships with `deleted_at IS NULL` in wallets with `deleted_at IS NULL`, archived included, `wallet_id > $afterWalletId`, ascending, at most `$limit`) and `restamp(string $memberId, int $changeSeq): void` (sets `change_seq` only through the query builder; `version`, `updated_at`, `member_change_seq` untouched — data-model.md).
- [X] T014 [US2] Add `restampNames(string $userId, string $afterWalletId, int $batch = 50): ?string` to `app/Modules/Wallets/Application/Membership/MembershipService.php`: list `$batch + 1` wallet ids; for each of the first `$batch`, `WalletScopeWrites::within($walletId, …)` re-reads `activeFor($walletId, $userId)` and skips when absent or `$scope->wallet->isTombstone()`, else `restamp($member->id, $scope->nextChangeSeq())`; no `UserScopeSequence`, audit or activity call; log `member_names_restamped` with `wallets_restamped`, `wallets_skipped`, `continued`; return the last processed wallet id when a further wallet exists, else null (plan N-4).
- [X] T015 [US2] Create the queued job `app/Modules/Wallets/Application/Membership/RestampMemberNames.php` (`ShouldQueue`, `ShouldBeUniqueUntilProcessing`, constructor `string $userId, string $afterWalletId = ''`, `uniqueId()` = `$userId.'|'.$afterWalletId`, default tries/backoff as the project's other jobs): calls `restampNames` and dispatches `new self($userId, $continuation)` when it returns a wallet id. Check the unique-job API with `search-docs` (`["unique jobs", "ShouldBeUniqueUntilProcessing"]`) before writing it (research R-6).
- [X] T016 [US2] Create the queued listener `app/Modules/Wallets/Application/Membership/Listeners/ResendMemberNames.php` (`ShouldQueue`, `handle(ProfileUpdated $event)`: returns unless `in_array('display_name', $event->changedFields, true)`, then dispatches `RestampMemberNames($event->userId)`) and register `Event::listen(ProfileUpdated::class, ResendMemberNames::class)` in `app/Modules/Wallets/Application/WalletsServiceProvider.php` (same pattern as `app/Modules/Sharing/Application/SharingServiceProvider.php`).
- [X] T017 [US2] Run `dev exec -- vendor/bin/pest tests/Feature/Wallets/MemberNameRestampTest.php tests/Feature/Wallets/MembershipChangesTest.php tests/Feature/Sync/ScopeLockOrderTest.php` until green; then `composer test:arch` (the listener in Wallets Application importing Identity's Domain event must pass the module gates).

**Checkpoint**: US2 shippable alone; no contract change beyond the documented behaviour.

---

## Phase 5: User Story 3 — Restore with my change in one batch (Priority: P3)

**Goal**: prove the written promise; no production code (FR-014, FR-015).

**Independent Test**: spec US3.

- [X] T018 [US3] Contract test `tests/Contract/Sync/RestoreEditPairTest.php` (pattern of `tests/Contract/Sync/BatchParityTest.php`): per-record `PATCH` restore at version n answers n + 1; restore of a live transaction answers it unchanged; batch `[restore n, upsert n + 1 with a new amount]` → both `applied`, final version n + 2 with the new amount; batch with a stale restore (n − 1) → restore `conflict` and upsert `conflict` (`VERSION_CONFLICT`, `current` is the tombstone), stored transaction unchanged; batch where the restore is refused for permission (viewer) → upsert refused, nothing changed. Run it with `dev exec -- vendor/bin/pest tests/Contract/Sync/RestoreEditPairTest.php`; if any case fails, stop and report (the contract promise would be wrong).

---

## Phase 6: Polish & Cross-Cutting

- [X] T019 [P] Constitution PATCH v1.3.9 → v1.3.10 in `.specify/memory/constitution.md`: Sync Impact Report entry (spec 017; ADR-0021 extends ADR-0008 and ADR-0017; no rule changes) and ADR-0021 on the `**ADRs**:` lines of P-07, P-08 and P-11 (FR-021).
- [X] T020 [P] `docs/api-conventions.md`: in the change-feed meta section add `sync_position` (integer, always sent from v1.10, never sent back, compare only with the same scope's head) and a short "Which wallets moved" paragraph for `GET /sync/wallets/heads` with the client comparison table from `contracts/sync-follow-ups-api.md` (FR-021).
- [X] T021 [P] Brief `specs/briefs/B15-export-data-deletion.md`: add an "Inherited from B14a" note — any path that changes or erases a display name MUST publish `ProfileUpdated` with `display_name` in `changedFields` so partners' wallet feeds re-send the membership (FR-019, FR-021).
- [X] T022 Performance check (Xdebug off, plan N-1…N-9): seed a person with 200 memberships; `GET /sync/wallets/heads?limit=200` p95 ≤ 300 ms (SC-002), with `EXPLAIN` showing the partial index; a rename's `PUT /me/profile` p95 within 10 ms of the baseline (SC-004); a running worker re-stamps all 200 wallets within 60 s (SC-003). Record the numbers for the summary; add a migration only if SC-002 fails (research R-5).
- [X] T023 Gates: `dev exec -- vendor/bin/pint --dirty --format agent`, then `dev exec -- composer test` (Pint, Larastan level 8, Arch, Unit + Feature + Contract) all green; `dev exec -- vendor/bin/pest tests/Contract/OpenApi` green; `npx @redocly/cli lint docs/openapi/openapi.yaml` valid with warnings unchanged (203).
- [X] T024 Quickstart walk-through: run the manual curl steps of `specs/017-sync-follow-ups/quickstart.md` against `http://saveme-backend.test` with two accounts and a queue worker; note results for the summary.
- [X] T025 Apidog sync: bundle with `npx @redocly/cli bundle docs/openapi/openapi.yaml -o <scratchpad>/bundle.json`; import with `apidog import --project 1332822 --format openapi --file <scratchpad>/bundle.json --module-map "Personal finance backend API=1363204"`; update the project's sync doc (or create "Sync follow-ups (v1.10)") with the client comparison table and behaviour notes from `contracts/sync-follow-ups-api.md`. Record the import counts and doc id for the summary.
- [X] T025a Bug fix (found by mobile QA; FR-022): `SyncCursor` carries an optional signed `h` (purge horizon when the read began; absent = 0); `ChangeFeedService::positionOf` returns `[position, horizon]` (the scope's horizon for a read with no cursor, the cursor's `h` otherwise), expires a cursor only when `purged > max(position, h)` and mints every page's cursor with the same `h`. Tests: `tests/Unit/Sync/SyncCursorTest.php` (round-trip with and without `h`, a malformed `h` refused), `tests/Feature/Sync/CursorExpiryTest.php` (a from-start read of a wallet feed and of the user feed pages through a purge horizon with a small limit; a cursor minted before a later purge still expires; a cursor with no `h` keeps the old rule).
- [ ] T026 Write `specs/017-sync-follow-ups/summary.md` (parent writes it).

---

## Dependencies & Execution Order

- T001 first. US1 (T002–T011), US2 (T012–T017) and US3 (T018) are independent and may run in any order or in parallel; within a story, tests before implementation, implementation in task order (T006 before T011; T007 before T008 before T009 before T010; T013 before T014 before T015 before T016).
- Polish: T019–T021 any time; T022–T024 after all stories; T025 after T023; T026 last.

### Parallel examples

- US1: T002, T003, T004, T005 together; then T006 and T007 together.
- US2: T013 while T012 is being written.
- Across stories: T018 alongside US1 or US2; T019, T020, T021 alongside anything.

## Implementation Strategy

1. MVP = US1 (heads + `sync_position`): the client's main cost saving; ship and let the client adopt it.
2. US2 (names) next: independent server work, no contract shape change.
3. US3 is a test of an existing promise; run it early since it costs minutes and guards the contract text.
4. Polish, gates, Apidog, summary.
