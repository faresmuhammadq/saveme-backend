# Summary: Sync Follow-ups (spec 017, B14a)

**Date**: 2026-10-03 · **Status**: implemented (T001–T026, plus T025a), QA and E2E green, committed on `main` (c0406b0).

Three gaps found by the mobile client while adopting the B14 change feeds (KMP spec 009), plus one bug its QA found.

## What was built

- **`meta.sync_position`** on both change feeds: an integer equal to the position the page's opaque `sync_cursor` resumes from (`FeedResult.syncPosition`, rendered by `FeedResponse`). Optional in the schema, always sent.
- **`GET /sync/wallets/heads`** (`listWalletHeads`): `{wallet_id, change_seq, purged_change_seq}` for every non-deleted wallet the caller actively belongs to (archived included), keyset-paged by `wallet_id` (50 default, 200 max). Sync's `WalletHeadsController` → Wallets' `WalletHeadsQuery` → `SyncFeedReader::headsFor`, one query over live memberships joined to live wallets. Authentication only. A quiet refresh now costs 2 requests (user feed + heads) instead of 1 + N.
- **Display-name re-stamp** (ADR-0021): Wallets' queued listener `ResendMemberNames` reacts to `ProfileUpdated` with `display_name` changed and dispatches `RestampMemberNames` (unique until processing). `MembershipService::restampNames` re-stamps the person's live memberships 50 wallets per run, one wallet lock per unit of work, moving only `change_seq` (same `version`, `updated_at`, `member_change_seq`), with a continuation job for the rest. Only the wallet feed re-sends the row. No audit, activity or event.
- **Restore + edit in one batch**: the documented promise (an applied restore answers version + 1, so `restore` at n then `upsert` at n + 1) is proven by `RestoreEditPairTest`; no new batch operation (ADR-0016 rule 2).
- **Purge-horizon cursor fix (FR-022)**: a read from the start could not page past a scope's purge horizon — the server expired its own `next_cursor` (410). `SyncCursor` now carries an optional signed `h` (the scope's purge horizon when the read began); a cursor expires only when `purged_change_seq > max(position, h)`. Old cursors decode with `h = 0` (the previous rule).
- **Docs**: ADR-0021 (extends ADR-0008 and ADR-0017); glossary (Scope Head, Sync Position); constitution v1.3.10; api-conventions (heads comparison table, rename note); B15 "Inherited from B14a" note; `sync.yaml` and `openapi.yaml` v1.10 (additive).
- **Apidog**: 1 endpoint created, 3 updated; project doc "Sync follow-ups (v1.10)" (2463664).

## Results

- **QA** (`/speckit-qa-run`, HTTP, real queue worker): 202 checks in 13 groups, all pass. Report `qa/qa-20261003-000500.md`. Includes: heads matching the DB exactly, paging and refusals, `sync_position` on every page, quiet refresh, partner writes and transfers moving heads, renames reaching partners within 4.7 s with unchanged `version`, no-op saves re-stamping nothing, restore + upsert pairs, and the purge-horizon fix on both feeds at limits 1, 2, 3 and 5 (with pre-purge and mid-read-then-repurged cursors still 410).
- **E2E** (`/speckit-test-by-validate`, HTTP): 18/18 acceptance scenarios (114 assertions). A person in 120 wallets had all 120 re-stamped 9–11 s after a rename.
- **Gates**: Pint and Larastan clean; Arch 50/50; Unit + Feature + Contract 2,173 passed, 2 opt-in performance tests skipped. 50 new tests.
- **Performance** (Xdebug off): heads for 200 wallets p95 87 ms (target 300 ms); rename save p95 105 ms vs 108 ms for a timezone-only save; re-stamp of 200 wallets 4.8 s (target 60 s).
- No product defect in QA; no code changed.

## Deployment

Restart queue workers. No migration.

## Contract notes for the client

- Store `meta.sync_position` beside each wallet cursor. Skip a wallet when its head's `change_seq` equals the stored position; read from the stored cursor when greater; read from no cursor when `purged_change_seq` exceeds the stored position. A wallet missing from the heads is not a removal signal (use the user feed or a 404).
- Apply a `wallet_member` row whose `version` equals the stored one: a re-stamped row keeps its version but carries the new name.
- After a 410, a read from no cursor now pages to the end at any page size.
- Restore with an edit: two items in one batch (restore at n, upsert at n + 1).

## Assumed defaults

Owner away with delegated authority; each the conservative option.

### Design

1. Heads are a separate read. Rejected: membership rows carrying the wallet's counter (a user-row stamp per wallet write) and heads inside the user feed's meta (mixes scopes, grows every page).
2. The phone compares the head with `sync_position`, not the wallet record's `change_seq` (a phone holding its own write's answer would miss partners' changes in between).
3. No user-scope head.
4. Re-stamp runs after the profile commits, via Identity's existing `ProfileUpdated` event; one wallet lock per unit of work, never a user-row lock; archived wallets included; ended memberships and deleted wallets skipped.
5. Only `change_seq` moves on a re-stamp; only the wallet feed re-sends.
6. No combined restore + edit operation; the version promise is documented instead.
7. Any future path that changes or erases a display name (B15) must publish the same event.
8. No new rate limit, entitlement, event, migration, seeder or dependency.
9. `sync_position` is optional in the schema so fixtures recorded before v1.10 still validate.

### Implementation (plan N-10 to N-15)

10. Heads declare `sortable: ['wallet_id']` and refuse a client `sort` first (`CollectionQuery` refuses even its own default when `sortable` is empty).
11. `ShouldBeUniqueUntilProcessing` (Laravel 13) releases its lock before `handle()`, so a rename after a run starts queues a fresh run; pending renames coalesce. `uniqueId` is `userId|afterWalletId`.
12. Re-stamp retries 3 times with 30/120 s backoff; a wallet that vanished between listing and lock is skipped; logs carry counts only (`member_names_restamped`).
13. A wallet or user scope is never empty, so position 0 is unreachable.
14. Cursor `h` is omitted when 0; a malformed `h` is refused as malformed; a null `h` is treated as absent.
15. The lock-order test inspects `for update` statements via `DB::listen` (only `wallets` rows locked, no `users` row).

## Remaining risks

- Names reach partners only when the queue is running; each rename costs one write per wallet the person is in.
- The client (KMP) must adopt `sync_position`, the heads read and equal-version member rows — a small KMP follow-up.
- The heads cursor is not tied to one reader (like other collection cursors); results are always filtered to the caller's memberships, so nothing leaks.
- QA's `sync:purge-tombstones` ran across the whole dev database; only QA rows had been aged.
