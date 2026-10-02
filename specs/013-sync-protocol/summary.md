# Summary: Sync Protocol (spec 013, B14)

**Date**: 2026-10-02 · **Status**: implemented (T001–T050), QA and E2E green, committed on `main` (4f26ef5).

## What was built

- **Sync module** (`app/Modules/Sync`), the constitution's ninth context.
  - Domain: signed cursor `SyncCursor` (HMAC-SHA256 truncated to 16 bytes, bound to feed, scope and reader), `FeedMerge`, `BatchEnvelope`, batch value types, refusals `CursorInvalid`, `CursorExpired`, `EnvelopeInvalid`.
  - Application: `ChangeFeedService`, `UpsertService`, `TombstonePurgeRun`.
  - Http: `GET /sync/user/changes`, `GET /sync/wallets/{id}/changes`, `POST /sync/batch`, `RouterItemDispatcher`; console command `sync:purge-tombstones`, scheduled daily.
  - No new error code. `docs/openapi/paths/sync.yaml` frozen at `info.version` v1.6.
- **User-scope counter.** `users.change_seq`, stamped under the user row lock, replaces the global `sync_change_seq` sequence (not commit-ordered, so a feed could skip a committed change). Profiles, device sessions, categories and memberships use it through Identity's `UserScopeCounter`. Migrations add the counters, `wallets.purged_change_seq` and `wallet_members.member_change_seq`, backfill, then drop the sequence.
- **Memberships on the member's user feed** (ADR-0017). Every membership write (create, ownership transfer, deletion cascade) stamps `member_change_seq` from the member's counter, so a phone learns it gained or lost a wallet. Lock order: categories → wallets ascending → users ascending.
- **Batch upload beside per-record writes** (ADR-0016, supersedes ADR-0008 rule 7). Each item is dispatched to the single write's own route, so answers match the single write exactly.
- **Retention.** `purged_change_seq` on wallets and users; the daily purge removes tombstones older than 90 days. A cursor behind the horizon is `410 SYNC_CURSOR_EXPIRED`; a cursor at or beyond it is served however old.
- **Docs**: constitution v1.3.6; glossary Change Row, Sync Batch, Purge Horizon; api-conventions (batch, idempotency, feed meta, purge horizon); security baseline; "Inherited from B14" notes in briefs B07, B08, B09, B15.

### Fix made during QA

- A profile item from a caller with no profile row answered `500 INTERNAL_ERROR` (retryable on the phone). It now answers `404 NOT_FOUND`, like a foreign profile id. Regression test in `tests/Contract/Sync/BatchParityTest.php`.
- spec.md wording aligned: a body that is not an object is `400 MALFORMED_JSON`, as the conventions and `sync.yaml` already said.

## Results

- **QA** (`/speckit-qa-run`, curl): 140/141. The one miss is TC-043, a timing check that fails only because the dev stack runs Xdebug. Report `qa/qa-20261002-102132.md`.
- **E2E** (`/speckit-test-by-validate`, over HTTP): 25/25 acceptance scenarios. Report `test-reports/validation-20261002-102132.md`.
- **Gap safety**: no change missed by a reader paging both feeds during 80 concurrent writes.
- **Gates**: Pint and Larastan clean; Arch 50/50; Unit + Feature + Contract 1298 passed.
- **Performance** (Xdebug off): wallet feed, 200 rows of a 10,000-transaction wallet, p95 148–190 ms; user feed p95 155–225 ms (target 300 ms); 50-item batch of creates p95 7.6 s, median 5.9 s (target 8 s). With Xdebug on, about 2× slower (12.5 s for a 50-item batch on the dev stack).

## Deployment

- Run the four `2026_10_04` migrations **in maintenance mode**: old code would keep stamping from the dropped sequence during the backfill.
- Restart queue workers.
- `sync:purge-tombstones` runs daily from the scheduler.
- Production must run without Xdebug for the batch target.

## Contract

`sync.yaml` and `contracts/sync-api.md` unchanged after QA. Validation pointers in a rejected batch item are rooted at the item's `data` (e.g. `/amount_minor`), matching the single write.

## Assumed defaults

Owner away with delegated authority; each the conservative option.

### Design

1. Batch items are independent, not all-or-nothing: one clash must never block unrelated edits.
2. Items run in array order, one at a time; the same record may repeat, so a batch can create a wallet, a category and a transaction using both.
3. 1–50 items; outside that the whole request is refused (422 `OUT_OF_RANGE` at `/items`), because writes can't be clamped like page sizes.
4. No `Idempotency-Key` on the batch (one sent is ignored); each item is idempotent by id + version. Conventions reserve keys for three money operations, and stored replays would keep a 24-hour copy of financial records. A retry after a lost response may report a clash the first attempt didn't.
5. Per-record PUT, PATCH and DELETE stay unchanged; archive, ownership transfer and partial edits remain per-record only.
6. A malformed batch envelope is refused whole.
7. Two feed endpoints; shared `cursor`/`limit`; filters and sorts refused; other unknown parameters ignored.
8. Feed row is `{type, record}`; a deletion is `record.deleted_at` set.
9. `meta.sync_cursor` is never null.
10. 410 is decided by the scope's `purged_change_seq`, not cursor age, so quiet scopes are never forced to resync.
11. Cursors are signed and bound to feed, scope and account.
12. Wallet records do not ride user feeds: one scope per record.
13. Lock order categories → wallets → users, ascending (extends ADR-0014 rule 10).
14. Purge daily, 90 days, configurable upward only; a deleted wallet or category is kept while anything refers to it.
15. One log line per feed read, batch and purge run, with no record content.
16. No rate limit, entitlement, events or seeder.
17. The counter-switch migration runs in maintenance mode.

### Implementation

18. Feed rows are rendered by each module's own resources through Application `FeedPresenter` interfaces, so a feed record equals its read.
19. The scope counter is read first and rows above it are dropped, so paging never skips a record.
20. Batch items go through `Router::dispatchToRoute` on a sub-request with the route's middleware; the guard's cached user is reused (authentication once per batch); each item's exception is contained and rendered as that item's problem with the batch request id.
21. A `restore` item that omits `deleted_at` gets `deleted_at: null`.
22. A profile item whose id is not the caller's profile, or a caller with no profile, is `404 NOT_FOUND`.
23. Feed limit default 50, clamped to 200; `limit` below 1 is `INVALID_QUERY_PARAMETER`.
24. Retention under 90 days is clamped to 90 with a warning.
25. Wallet-feed authorization runs before cursor decoding, so a foreign wallet is always 404.
26. Identity profile and session writes wrap the stamp and the row write in one transaction; a stale write rolls its stamp back, so the counter has no holes.
27. `CategoryService::update` republishes references (wallet locks) before taking the user stamp, preserving the lock order.
28. Membership repository writes require the stamp, so B9 cannot forget it.
29. Purge batches are 500 rows per transaction, each locking wallets then users in ascending order.
30. Test factories give orphan users (no `users` row) a stamp above 1,000,000,000; production throws if the row is missing.
31. QA seeded memberships in tinker (invitations arrive with B9) and aged tombstones in tinker before running the purge.

## Remaining risks

- The 50-item batch has little headroom against the 8 s target (7.6 s p95).
- A session revoked mid-batch still finishes that batch's remaining items, by design.
- Display names on membership rows are not re-sent when someone renames themselves; the member list refreshes names.
- Once the purge removes a deleted wallet's membership tombstones, former members get 404 on its feed, even if the wallet row is kept for a live transfer. The phone has already received the tombstoned membership by then.
- B9 must stamp grants, removals and leaves per ADR-0017.
- The concurrency tests simulate two writers on two real connections deterministically; PHP cannot run truly parallel requests in a test.
