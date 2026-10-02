# Implementation Plan: Sync Protocol

**Branch**: `013-sync-protocol` (trunk-based; work lands on `main`) | **Date**: 2026-10-02 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/013-sync-protocol/spec.md`.

## Summary

Open the **Sync** module (`app/Modules/Sync`, the constitution's ninth context) and close the two sync gaps earlier specs left for B14.

- **Feeds** (R-8, R-9, R-12): `GET /sync/user/changes` and `GET /sync/wallets/{id}/changes`. Sync's `ChangeFeedService` merges per-source pages from Wallets' and Identity's Application feed queries by scope position (wallet: `change_seq`, transfers into it by `counter_change_seq`; user: `change_seq`, own memberships by `member_change_seq`). Rows are `{type, record}`, the record rendered by the same resource classes as the read endpoints. Signed opaque cursor bound to feed, scope and reader; `sync_cursor` in meta.
- **Batch** (R-2, R-3, R-4): `POST /sync/batch`, 1–50 items. An envelope form request refuses unusable input whole; each item is dispatched in-process to the per-record route it names and its status/body captured as the item result. Independent, sequential, array order, no key (ADR-0016).
- **User scope counter** (R-5): `users.change_seq` under the row lock replaces the global `sync_change_seq` for profiles, sessions, categories and memberships; one switch migration backfills; the sequence is dropped.
- **Memberships on the user feed** (R-6, R-7): `wallet_members.member_change_seq` stamped from the member's user counter on every membership write (create, transfer, deletion cascade); lock order categories → wallets → users (ADR-0017).
- **Retention** (R-10, R-11): `purged_change_seq` on `wallets` and `users`; daily `sync:purge-tombstones` removes tombstones older than 90 days through Wallets' and Identity's purge services and raises the horizons; a cursor behind a horizon is `410 SYNC_CURSOR_EXPIRED`.
- **API**: three operations in `docs/openapi/paths/sync.yaml`, written, indexed and frozen in this design phase (`info.version` v1.6); no new error code.
- **Docs**: ADR-0016 (supersedes ADR-0008 rule 7) and ADR-0017 written in design, ADR index and ADR-0008 status line updated; constitution PATCH v1.3.6 (P-07 and P-08 ADR lines), api-conventions (Writes, Idempotency, Collections), glossary (Change Row, Sync Batch, Purge Horizon; Sync Cursor and Wallet Member notes), security baseline (Retention owner note) in implementation.

## Technical Context

**Language/Version**: PHP 8.5, Laravel 13.

**Primary Dependencies**: none new (`hash_hmac` for the cursor; Laravel router for item dispatch).

**Storage**: PostgreSQL 16. Columns: `users.change_seq`, `users.purged_change_seq`, `wallets.purged_change_seq`, `wallet_members.member_change_seq`; indexes `(user_id, change_seq)` on profiles, device sessions and person's categories, `(user_id, member_change_seq)` on memberships. Sequence `sync_change_seq` dropped.

**Testing**: Pest.
- Unit: cursor codec (sign, tamper, bind), feed page merge, batch envelope rules, outcome mapping.
- Feature: user and wallet feeds, gap safety under concurrency, transfers on two feeds, memberships on the user feed, user counter (lock, increment, no sequence), backfill, lock order, cursor expiry, purge (horizon, references kept, idempotent), batch ordering, conflict, replay, failure isolation, logging.
- Contract: three operations with every documented status (Spectator), batch parity against each single write, envelope refusals.
- Arch: no use of `sync_change_seq`; Sync references no other module's Infrastructure or Http.

**Target Platform**: the Linux server (the `dev` container).

**Project Type**: modular-monolith JSON API.

**Performance Goals**: 50-item batch of creates p95 < 8 s; 200-row feed page in a 10,000-transaction wallet p95 < 300 ms (R-15).

**Constraints**: P-02 (Sync calls other modules only through their Application services; item dispatch names routes, not classes), P-04 rule 6 (no postings on a feed), P-06 (client UUIDv7 per item), P-07 (no merge; tombstones on feeds; version per item), P-08 (W-01 for wallet feeds; each item authorised as its single write), P-15 (no record content in logs), ADR-0008 rules 2–16, ADR-0015 rule 4, ADR-0016, ADR-0017.

**Scale/Scope**:
- 3 operations; 4 migrations (Identity ×2, Wallets ×1, Sync ×1); no seeder;
- about 25 Sync classes; Identity: counter adapter, `UserScopeCounter`, `UserFeedQuery`, `TombstonePurge`; Wallets: `WalletFeedQuery`, `UserFeedQuery` (categories, memberships), `TombstonePurge`, membership stamping in `WalletService` and its deletion cascade, `UserScopeSequence` delegating to Identity;
- about 20 test files;
- doc edits: constitution PATCH, api-conventions, glossary, security baseline note.

## Constitution Check

*Constitution v1.3.5 (`.specify/memory/constitution.md`). GATE: must pass before Phase 0 research. Re-check after Phase 1 design, keeping both results.*

### Pre-design

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | "Wallet", "Wallet Member", "change feed", "tombstone", "Sync Cursor"; new terms Change Row, Sync Batch, Purge Horizon added to the glossary before code uses them (T001) |
| P-02 Module boundaries | PASS | New `app/Modules/Sync` with four layers. Feed sources and purge are Wallets' and Identity's Application services, called by Sync (rule 4). Wallets' user stamp goes through Identity's Application `UserScopeCounter`. Batch items are dispatched by route name, importing no other module's Http class. The one-off switch migration is SQL, not a class reference |
| P-03 Money | PASS | Money travels only inside records rendered by their owners' resources; nothing computed |
| P-04 Ledger integrity | PASS | Feeds never select `postings`; `NoPostingsOnFeedTest` (in `ListWalletChangesTest`) |
| P-05 Derived balances | PASS | No balance written by Sync; item writes recompute through `TransactionService` as before |
| P-06 Identifiers | PASS | Each item is the per-record upsert with its client UUIDv7; foreign id → item `404` exactly as the single write |
| P-07 Sync and concurrency | PASS | Per-item `version`, whole-item `409` with `current`, no merge (ADR-0016 rules 2, 8); tombstones on feeds; per-scope counters under row locks (R-5, R-6) |
| P-08 Authorization | PASS | Wallet feed: row W-01 plus spec 010's deleted-wallet rule, non-member `404`; items authorised by their own routes (W-02, W-04, T-02, T-03, ownership) |
| P-09 Entitlements | PASS | No capability gated; `wallets.max` still applies per item through the single write |
| P-10 Billing channels | N-A | No billing surface |
| P-11 Events | PASS | Sync publishes and consumes no events; item writes publish theirs as before (after commit, per item) |
| P-12 Time | PASS | Positions, not instants, decide order and expiry; retention compares `deleted_at` (UTC) with the server clock only |
| P-13 Naming | PASS | No codename |
| P-14 Localisation | PASS | Item problems localised by the single write; feed and batch refusals use existing ar/en strings |
| P-15 Observability | PASS | One summary line per feed read, batch and purge run; no record ids, bodies, cursors, amounts or text (R-13) |
| P-16 Quality gates | PASS | Every spec §4 invariant names its test; PostgreSQL row locks and `UPDATE … RETURNING` |
| P-17 Scope guard | PASS | No out-of-scope capability |
| P-18 Delivery surfaces | PASS | Every capability is a `/api/v1` endpoint; the purge is a console command, no screen |

### Post-design re-check

Unchanged: all rows PASS or N-A.

- **CONTRIBUTING item 18**: ADR-0016 supersedes ADR-0008 rule 7 only (batch beside per-record writes); ADR-0017 extends ADR-0008 rules 1 and 15 and ADR-0014 rule 10 (membership on the member's user feed; users locked last). Both written and indexed in this design phase; ADR-0008's status line names the superseded rule. The constitution's P-07 and P-08 `ADRs:` lines gain them (PATCH v1.3.6, T001).
- **Spec 003 deviations, recorded**: `POST /sync/batch` is a `POST` that is not one of the three key-bearing operations and carries no `Idempotency-Key` (ADR-0016 rule 7; api-conventions Idempotency gains one sentence); the feeds add `sync_cursor` to their `meta` through a local `ChangeFeedMeta` (the shared `CollectionMeta` forbids extra members) — no second envelope.
- **Spec 007 R-19 and spec 011 R-5 closed**: the user scope now has the commit-ordered counter ADR-0008 requires.
- No new error code; `info.version` v1.6 for the new paths.

Complexity Tracking stays empty.

## Assumed defaults (owner away)

| # | Decision | Why |
|---|---|---|
| A-1 | Batch items independent (no all-or-nothing) | Client brief: one clash never blocks others; no multi-record unit of work exists |
| A-2 | Items applied sequentially in array order; repeats allowed | Lets a batch create a wallet, a category and a transaction using both; no hidden reordering |
| A-3 | 1–50 items; outside refused whole with `OUT_OF_RANGE` at `/items` | Writes cannot be clamped; 50 = default page size, a few seconds at measured write cost |
| A-4 | No batch `Idempotency-Key`; per-item idempotency by id + version | Spec 003 keeps keys for three money ops; no 24-hour copy of financial records or encrypted names |
| A-5 | Per-record writes stay, unchanged | Wave-0 client keeps working; archive, transfer, PATCH stay per-record |
| A-6 | Batch types/ops: wallet (upsert, delete), category (upsert, delete), transaction (upsert, delete, restore), profile (upsert) | Every whole-record write that exists today; commands and partial edits excluded |
| A-7 | Item = dispatch of the equivalent single request (R-2) | Parity by construction; no second validation path |
| A-8 | Envelope errors refuse the whole batch (422) | A misbuilt envelope is a client bug; never half-apply it |
| A-9 | Per-item verification gate exactly where the single write has it | "Each item obeys exactly the single-write rules" |
| A-10 | Unexpected failure of one item → that item `500 INTERNAL_ERROR`, others continue | Committed items stay committed; resend is safe |
| A-11 | Feeds at `/sync/user/changes`, `/sync/wallets/{id}/changes`; shared `cursor`/`limit`; no filters or sorts | Spec 003 grammar; no bespoke `since`/`wallet_id` parameters; path gives the 404 |
| A-12 | Row = `{type, record}`; deletion = `record.deleted_at` set; unknown types skipped | Spec 003/012: a feed row is the resource |
| A-13 | `meta.sync_cursor` never null | The last page must still give a resume position |
| A-14 | Signed cursor bound to feed, scope, reader; ahead-of-scope → `INVALID_CURSOR` | Opaque yet tamper-evident; no cross-account misuse |
| A-15 | `410` decided by position vs `purged_change_seq`, not cursor age | Precise; quiet scopes never forced to resync |
| A-16 | User counter = `users.change_seq` under row lock; global sequence dropped | ADR-0008 rules 2–5; closes spec 007 R-19 |
| A-17 | Own memberships ride the user feed via `member_change_seq` (ADR-0017) | ADR-0008 rule 15; discover granted wallets |
| A-18 | Wallet records do not ride user feeds | One scope per record; no per-member stamps on every wallet write |
| A-19 | Lock order categories → wallets → users, ascending | Extends ADR-0014 rule 10 with no out-of-order path |
| A-20 | Retention 90 days, configurable upward only; daily purge | ADR-0008 rule 12; security baseline |
| A-21 | Tombstoned wallets/categories purged only once unreferenced | A live transfer can still post to a deleted wallet (ADR-0015 rule 5) |
| A-22 | Deleted, unpurged wallet's feed readable by members at deletion | Spec 010 default 25 |
| A-23 | One summary log line per feed read, batch and purge run; no content | P-15 rule 4 |
| A-24 | No rate limit, entitlement, events or seeder | Consistent with specs 010–012; no reference data |
| A-25 | Switch migration in maintenance mode | Old code would stamp from the sequence during the backfill |
| A-26 | Display names on membership rows not re-stamped on rename | Spec 010 behaviour; member list refreshes names |
| A-27 | Item problems use the batch's request id | One id joins the batch in logs |

## Review checklists (applied at the polish task)

- **Endpoint review checklist** (`docs/api-conventions.md` appendix): expected exceptions — C1–C7 do not apply to the feeds (reads) and apply to the batch per item through the single writes; G3 is a recorded deviation for the batch (a `POST` with no key, ADR-0016 rule 7); G5 does not apply (no throttle); E1–E6 apply to the feeds (`x-filterable: []`, `x-sortable: []`); I2 is "no" for the feature (three path entries, one per distinct path); I4 is "no": api-conventions changes in the same commit (T001).
- **Security review checklist**: no stored attribute added beyond counters and horizons (no protection claim needed: positions only); feeds disclose nothing outside the reader's scopes; the batch adds no write path that bypasses a single write's checks; logs carry no content.

## Project Structure

### Documentation (this feature)

```text
specs/013-sync-protocol/
├── spec.md
├── plan.md                 # this file
├── research.md             # R-1 … R-16
├── data-model.md
├── quickstart.md
├── contracts/sync-api.md
├── checklists/requirements.md, checklists/protocol.md
└── tasks.md                # /speckit-tasks
docs/adr/0016-batch-upload-beside-per-record-writes.md     # written in design
docs/adr/0017-membership-on-the-members-user-feed.md       # written in design
docs/adr/README.md, docs/adr/0008-sync-changes-feed.md     # index; status line
docs/openapi/paths/sync.yaml, openapi.yaml                 # written and indexed in design (v1.6)
```

### Source Code (repository root)

```text
app/Modules/Sync/
├── Domain/
│   ├── Cursor/{SyncCursor,FeedKind,CursorRefusal}.php      # value + refusal reasons
│   ├── Feed/{FeedRow,FeedPage,FeedMerge}.php               # k-way merge by position
│   ├── Batch/{BatchItem,ItemType,ItemOperation,ItemOutcome,BatchItemResult}.php
│   ├── Refusals/{CursorInvalid,CursorExpired}.php          # INVALID_CURSOR, SYNC_CURSOR_EXPIRED
│   └── Ports/{ItemDispatcher,CursorSigner}.php             # Domain ports (Infrastructure and Http implement them)
├── Application/
│   ├── ChangeFeedService.php                               # user/wallet pages via Wallets + Identity feed queries
│   ├── UpsertService.php                                   # batch loop: dispatch, capture, log
│   └── TombstonePurgeRun.php                               # orchestrates owners' purge services
├── Infrastructure/
│   ├── HmacCursorSigner.php                                # implements Domain\Ports\CursorSigner
│   ├── Database/Migrations/2026_10_04_000003_switch_user_scope_counter.php
│   └── SyncPersistenceServiceProvider.php
└── Http/
    ├── routes.php                                          # GET user/wallet feeds, POST batch (auth:api)
    ├── Controllers/{UserChangesController,WalletChangesController,SyncBatchController}.php
    ├── Requests/{FeedRequest,SyncBatchRequest}.php
    ├── RouterItemDispatcher.php                            # implements Domain\Ports\ItemDispatcher: item → sub-request → (status, body)
    ├── Console/PurgeTombstones.php                         # sync:purge-tombstones (commands live in Http/Console, like wallets:verify-balances)
    └── SyncHttpServiceProvider.php
config/sync.php                                             # tombstone_retention_days (≥ 90), cursor key

app/Modules/Identity/
├── Domain/Ports/ChangeSequence.php                         # next(string $userId)
├── Application/Sync/{UserScopeCounter,UserFeedQuery,TombstonePurge}.php
├── Infrastructure/Sync/UserRowChangeSequence.php           # replaces PostgresChangeSequence
└── Infrastructure/Database/Migrations/2026_10_04_000001_add_sync_counters_to_users.php,
                                       2026_10_04_000004_drop_sync_change_seq.php
app/Modules/Wallets/
├── Application/Sync/{WalletFeedQuery,UserFeedQuery,TombstonePurge}.php
├── Application/WalletService.php                           # stamp member_change_seq on create, transfer, deletion
├── Infrastructure/Sync/UserScopeSequence.php               # delegates to Identity UserScopeCounter
└── Infrastructure/Database/Migrations/2026_10_04_000002_add_sync_columns.php
routes/console.php                                          # daily sync:purge-tombstones
bootstrap/providers.php                                     # Sync providers

tests/Unit/Sync/{SyncCursor,FeedMerge,BatchEnvelope,ItemOutcome}Test.php
tests/Feature/Sync/{UserFeed,WalletFeed,FeedGapSafety,TransferOnTwoFeeds,MembershipOnUserFeed,UserScopeCounter,UserCounterBackfill,ScopeLockOrder,CursorExpiry,PurgeTombstones,BatchOrdering,BatchConflict,BatchReplay,BatchFailureIsolation,SyncLogging}Test.php
tests/Contract/Sync/{ListUserChanges,ListWalletChanges,UploadSyncBatch,BatchParity}Test.php
tests/Arch/SyncCounterTest.php
```

**Structure Decision**: the protocol lives in the new Sync module, the constitution's owner of "change feeds, cursors, conflict handling, tombstone retention". Records, counters and horizons stay with the modules that own their tables; Sync reaches them only through those modules' Application services.

## Complexity Tracking

No Constitution Check violations; nothing to justify.

## Measured (T046)

Dev container, `XDEBUG_MODE=off`, PHP 8.5, PostgreSQL 16, requests through the test HTTP kernel (one committed transaction per request, as in production; run inside a single test transaction the repeated updates of one wallet row made every write several times slower, so the figures use committed writes). A wallet with 10,000 transactions; a person with 200 categories and 50 memberships.

| What | Target | Measured |
|---|---|---|
| Wallet feed page, 200 rows (30 reads, from the start and mid-feed) | p95 < 300 ms | p95 148-190 ms, max 200 ms |
| User feed page, 200 rows (30 reads) | p95 < 300 ms | p95 155-225 ms |
| Batch of 50 transaction creates (10 runs) | p95 < 8 s | p95 (max of 10) 7.6 s, median 5.9 s |
| One single `PUT` transaction create, same harness | n/a | median 190 ms, p95 250 ms |

The batch is within the target with little headroom: about 117 ms per item against 190 ms for the same write as a request of its own (the items skip the global middleware once and the connection set-up). With Xdebug loaded, the same run is about 2x slower (feeds p95 about 313-433 ms, batch p95 28 s in one transaction), so the targets hold only with it off. No N+1 rendering was found: a feed page costs a constant number of queries per source.

## Review checklists applied (T047)

**Endpoint review checklist** (`docs/api-conventions.md`), the three operations in `docs/openapi/paths/sync.yaml`:

- A (paths, envelope): pass. `/api/v1/...`, `{data, meta}`, `meta` an object (`(object) []` for the batch), problems `application/problem+json`.
- B (errors): pass. Every code exists in the catalogue (`INVALID_CURSOR`, `SYNC_CURSOR_EXPIRED`, `VALIDATION_FAILED`, `OUT_OF_RANGE`, `UNKNOWN_FIELD`, `INVALID_ID`, `INVALID_ENUM_VALUE`, `INVALID_TYPE`, `REQUIRED`); no new code.
- C (writes): C1-C7 do not apply to the feeds (reads); for the batch they apply per item through the single writes (`BatchParityTest` proves the same status and body). **G3 is a recorded deviation**: `POST /sync/batch` is a `POST` that is not one of the three key-bearing operations and carries no `Idempotency-Key` (ADR-0016 rule 7; api-conventions Idempotency says so).
- D/E (resources, collections): E1-E6 apply to the feeds with `x-filterable: []` and `x-sortable: []`; filters and sorts are refused (`FILTER_FIELD_NOT_SUPPORTED`, `SORT_FIELD_NOT_SUPPORTED`), `limit` clamps to 200. D4/D5: tombstones appear, no posting appears (`WalletFeedTest`, `ListWalletChangesTest`).
- G5 (throttle): does not apply, no throttle. I2: three path entries, one per distinct path. I4: api-conventions changed in this change (T001).

**Security review checklist**: no stored attribute added beyond counters and horizons (positions, no protection claim); feeds disclose nothing outside the reader's scopes (wallet feed is W-01 or 404, user feed is the caller's rows only, a person's own memberships only, platform categories never); the batch adds no write path that bypasses a single write's checks (each item runs the single write's route with its middleware, `address.verified` included); logs carry no ids, bodies, cursors, amounts or text (`SyncLoggingTest`); cursors are signed and bound to feed, scope and reader.

**Contract compatibility**: nothing the client binds to in `contracts/sync-api.md` changed; `docs/openapi/paths/sync.yaml` and `info.version` (v1.6) are as frozen in design.
