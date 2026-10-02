# Implementation Plan: Sync Follow-ups

**Branch**: `017-sync-follow-ups` (committed on `main`, trunk-based) | **Date**: 2026-10-02 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/017-sync-follow-ups/spec.md`

## Summary

Three small, additive follow-ups to spec 013 that the client (KMP spec 009) asked for:

- **Heads** (US1): `GET /sync/wallets/heads` lists each wallet the caller actively belongs to with its scope head (`wallets.change_seq`) and purge horizon (`wallets.purged_change_seq`); every feed page gains `meta.sync_position`, the integer its cursor signs. A quiet refresh drops from 1 + N to 2 requests.
- **Names** (US2): Identity's existing `ProfileUpdated` (after commit) with `display_name` changed triggers a queued Wallets job that re-stamps the person's live memberships, one wallet lock per unit of work, 50 wallets per run with continuations. Only `change_seq` moves.
- **Restore pair** (US3): no code; `sync.yaml` states the restore version promise and the restore + upsert batch pair; a contract test proves it.

Contract frozen in `docs/openapi/paths/sync.yaml` at v1.10; glossary (Scope Head, Sync Position) and ADR-0021 written in this phase.

## Technical Context

**Language/Version**: PHP 8.5, Laravel 13

**Primary Dependencies**: existing only (Spectator for contract tests, Pest). **No new dependency.**

**Storage**: PostgreSQL 16; no migration (reads `wallets.change_seq`, `wallets.purged_change_seq`, `wallet_members`; re-stamps `wallet_members.change_seq`).

**Testing**: Pest — Feature (`tests/Feature/Sync`, `tests/Feature/Wallets`) against PostgreSQL; Contract (`tests/Contract/Sync`) validated against `docs/openapi` with Spectator; `tests/Contract/OpenApi` for the document itself.

**Target Platform**: Linux server (`dev` container), queue worker for the re-stamp job.

**Project Type**: web-service (API-first modular monolith).

**Performance Goals**: heads p95 ≤ 300 ms for 200 wallets in one page; rename answer +≤ 10 ms p95; re-stamp of 200 wallets complete within 60 s on a running worker (Xdebug off).

**Constraints**: additive to the frozen `v1` sync contract; lock order ADR-0017 rule 5 (never a user-row lock with a wallet lock in the re-stamp); P-02 module boundaries (Sync calls Wallets Application only; Wallets listens to an Identity Domain event).

**Scale/Scope**: 1 endpoint, 1 meta member, 1 listener, 1 job, 2 repository methods, 1 reader method, 1 application query, ~6 test files; docs: glossary, ADR-0021, constitution PATCH, api-conventions, brief B15 note.

## Constitution Check

*Constitution v1.3.9 (`.specify/memory/constitution.md`). GATE: before Phase 0 and again after Phase 1.*

**Pre-design (Phase 0 entry)**: no principle fails; no MVP-guarded capability; no new dependency.

**Post-design (after Phase 1)**:

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | "wallet", "scope head", "sync position", "purge horizon"; new terms added to `docs/glossary.md` before use |
| P-02 Module boundaries | PASS | `WalletHeadsController` (Sync Http) → `WalletHeadsQuery` (Wallets Application) → `SyncFeedReader` port; re-stamp listener in Wallets Application listens to Identity's Domain event `ProfileUpdated`; no Infrastructure/Http crossing |
| P-03 Money | N-A | handles no money |
| P-04 Ledger integrity | N-A | no postings read or written; the restore pair reuses existing writes |
| P-05 Derived balances | N-A | no balance change (the re-stamp re-sends the wallet record; its cached balance is untouched) |
| P-06 Identifiers | PASS | no new entity; heads keyed by existing UUIDv7 wallet ids; ordering by id is keyset paging, not time ordering (rule 4 concerns the timestamp, which is unused) |
| P-07 Sync and concurrency | PASS | re-stamp takes `change_seq` from the wallet counter under its lock (ADR-0008 rule 3); no merge; `version` untouched; restore pair keeps whole-write rejection; ADR-0021 recorded |
| P-08 Authorization | PASS | heads list only the caller's active memberships of live wallets; absent rather than 403/404 per wallet (rule 3: no disclosure) |
| P-09 Entitlements | N-A | no capability gated; sync is core |
| P-10 Billing channels | N-A | no billing surface |
| P-11 Events | PASS | consumes `ProfileUpdated` (already `ShouldDispatchAfterCommit`) with a queued, idempotent listener; payload ids and field names only; publishes nothing new |
| P-12 Time | N-A | no dates or instants written (`updated_at` deliberately untouched) |
| P-13 Naming | PASS | no codename in code, routes or tables |
| P-14 Localisation | N-A | no user-facing text; positions are integers |
| P-15 Observability | PASS | one info line per heads read and per job run, counts only; no name, id or position at info level; correlation id propagates to the queued job as for other jobs |
| P-16 Quality gates | PASS | every invariant in spec §4 has a named test; suite on PostgreSQL; `composer test` in the definition of done |
| P-17 Scope guard | PASS | no listed out-of-scope capability |
| P-18 Delivery surfaces | PASS | JSON endpoint under `/api/v1` only; no back-office change |

## Project Structure

### Documentation (this feature)

```text
specs/017-sync-follow-ups/
├── spec.md
├── plan.md              # this file
├── research.md          # R-1 … R-11
├── data-model.md
├── quickstart.md
├── contracts/sync-follow-ups-api.md
├── checklists/          # requirements.md, sync.md
└── tasks.md             # /speckit-tasks
```

### Source Code (repository root)

```text
app/Modules/Sync/
├── Application/FeedResult.php                      # + syncPosition
├── Application/ChangeFeedService.php               # passes FeedPage::nextPosition through
├── Http/Controllers/FeedResponse.php               # + meta.sync_position
├── Http/Controllers/WalletHeadsController.php      # new, GET sync/wallets/heads
└── Http/routes.php                                 # + sync.wallet.heads

app/Modules/Wallets/
├── Application/Sync/WalletHead.php                 # new DTO
├── Application/Sync/WalletHeadsQuery.php           # new
├── Application/Membership/MembershipService.php    # + restampNames()
├── Application/Membership/RestampMemberNames.php   # new queued job (unique until processing)
├── Application/Membership/Listeners/ResendMemberNames.php  # new queued listener on ProfileUpdated
├── Application/WalletsServiceProvider.php          # Event::listen registration
├── Domain/Ports/SyncFeedReader.php                 # + headsFor()
├── Domain/Ports/MembershipRepository.php           # + liveWalletIdsOf(), restamp()
└── Infrastructure/Repositories/{EloquentSyncFeedReader,EloquentMembershipRepository}.php

tests/
├── Feature/Sync/{WalletHeadsTest,SyncPositionTest}.php
├── Feature/Wallets/MemberNameRestampTest.php
└── Contract/Sync/{ListWalletHeadsTest,FeedPositionTest,RestoreEditPairTest}.php

docs/
├── openapi/openapi.yaml, openapi/paths/sync.yaml   # v1.10 (done in this phase)
├── glossary.md, adr/0021-…, adr/README.md          # done in this phase
├── api-conventions.md                              # feed meta + heads read (task)
└── ../.specify/memory/constitution.md              # v1.3.10 PATCH (task)
specs/briefs/B15-export-data-deletion.md            # "Inherited from B14a" note (task)
```

**Structure Decision**: existing module layout; Sync owns the endpoint, Wallets owns the data and the re-stamp; no new module, table or base folder.

## Implementation notes

- **N-1** `sync_position` = `FeedPage::nextPosition` (already the value the cursor signs); add it to `FeedResult` and render it in `FeedResponse`.
- **N-2** Heads route registered inside the existing `sync.` group with `auth:api`; use `CollectionQuery::from($request, [], [], 'wallet_id')` and confirm a client `sort` is refused (R-3 implementation check); the cursor carries the last `wallet_id`.
- **N-3** `SyncFeedReader::headsFor(userId, ?afterWalletId, limit)` — one query: `wallet_members m JOIN wallets w ON w.id = m.wallet_id WHERE m.user_id = ? AND m.deleted_at IS NULL AND w.deleted_at IS NULL AND (? IS NULL OR w.id > ?) ORDER BY w.id LIMIT limit + 1`, selecting `w.id, w.change_seq, w.purged_change_seq`.
- **N-4** `MembershipService::restampNames(string $userId, string $afterWalletId, int $batch = 50): ?string` returns the continuation wallet id or null; each wallet via `WalletScopeWrites::within`, re-checking `activeFor` and `isTombstone()` inside the lock; `MembershipRepository::restamp(memberId, changeSeq)` updates `change_seq` only (no `updated_at` touch: use the query builder, not Eloquent `save()`).
- **N-5** `RestampMemberNames` implements `ShouldQueue`, `ShouldBeUniqueUntilProcessing`; `uniqueId()` = `userId|afterWalletId`; dispatches its own continuation. Confirm the unique-job API with `search-docs` first.
- **N-6** `ResendMemberNames` implements `ShouldQueue`; `handle(ProfileUpdated $event)` returns unless `in_array('display_name', $event->changedFields, true)`.
- **N-7** Lock-order test: assert the job never runs inside a transaction holding the user row (e.g. `DB::transactionLevel()` is 0 at job start, and the profile save's transaction has committed — `ShouldDispatchAfterCommit`); and that each wallet's unit of work locks only that wallet (pattern of `tests/Feature/Sync/ScopeLockOrderTest.php`).
- **N-8** Restore pair test through `POST /sync/batch` and per record; no production code.
- **N-9** Logs: `sync_wallet_heads_read {wallets, has_more}`, `member_names_restamped {wallets_restamped, wallets_skipped, continued}`.

- **N-10** (implementation) With `sortable: []`, `CollectionQuery::from` refuses even its own default sort (the default must be in `sortable`), so the heads controller declares `sortable: ['wallet_id']` and refuses a client `sort` first, as the members list does (R-3 check). The cursor payload is `{wallet_id}`.
- **N-11** (implementation) `ShouldBeUniqueUntilProcessing` in the installed Laravel (13): the unique lock is taken at dispatch (a second dispatch while it is held is dropped, also under `Queue::fake()`), and `CallQueuedHandler` releases it before `handle()` runs, so a rename after the run started queues a new run. The continuation has a different `uniqueId` and is dispatched from `handle()`.
- **N-12** (implementation) `restampNames` skips a wallet that disappeared between the listing and the lock (`WalletNotFound` is caught per wallet) as well as an ended membership and a tombstoned wallet; a failure of anything else propagates, the queue retries (tries 3, backoff 30/120 s) and the run re-stamps again from the start.
- **N-13** (implementation) An empty scope cannot be read: a wallet's `change_seq` is at least 1 and every user has a profile row on the user feed, so "cursorless read of an empty scope = 0" is not reachable; `SyncPositionTest` covers first, intermediate, last and empty-from-cursor pages instead.
- **N-14** (implementation, bug found by mobile QA, FR-022, T025a) A read from the start could not page past a purge horizon: the first page's cursor had a position below `purged_change_seq` and expired. `SyncCursor` now carries an optional signed `h` (the horizon when the read began; absent = 0, the old rule); a cursor expires only when `purged > max(position, h)`; every page of one read keeps the starting `h`. Old cursors decode with `h = 0`. No contract shape change (cursors are opaque).
- **N-15** (implementation) Performance (Xdebug off, test database, in-process HTTP): heads for 200 wallets p95 87 ms (limit 300); `PUT /me/profile` rename p95 105 ms against 108 ms for a timezone-only save; re-stamping 200 wallets 4.8 s over 4 runs, inline (limit 60 s). `EXPLAIN` on the tiny test tables used `wallet_members_active_member_unique` (index only) and a seq scan of `wallets`; no migration needed.

## Complexity Tracking

None.
