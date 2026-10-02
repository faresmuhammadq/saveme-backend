# Research: Transactions Ledger

**Feature**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Date**: 2026-10-02

Every decision below was taken with the owner away under delegated authority; each is the recommended, most conservative option and is listed in the plan's Assumed defaults. Specs 010 (wallets) and 011 (categories) are the pattern reference throughout.

---

## R-1 Transfer: one record, two feeds (ADR-0015)

**Decision**: a transfer is one `transactions` row with `kind = transfer`, `wallet_id` = source, `counter_wallet_id` = destination, `category_id` null. It is stamped `change_seq` from the source wallet's counter and `counter_change_seq` from the destination's, both under their row locks. Both wallets of a transfer are immutable after creation.

**Rationale**: P-04 rule 3 forbids two linked records. A shared destination's members must see the record (list now, feed in B14), and ADR-0008 orders a feed only by its own counter, so the destination needs its own stamp. Freezing both wallets means a record never leaves a feed it was on, so B14 needs no "exit" row.

**Alternatives**: two linked records (P-04 rule 3, drift); source feed only (destination members see an unexplained balance move); a projection record in the destination (second lifecycle for one fact). Recorded in ADR-0015.

## R-2 Postings table and derivation

**Decision**: `postings(id uuid pk, transaction_id uuid fk, wallet_id uuid null fk, category_id uuid null fk, amount_minor bigint, currency char(3))` with `CHECK ((wallet_id IS NULL) <> (category_id IS NULL))` and `CHECK (amount_minor <> 0)`. A pure domain function `PostingDerivation::for(TransactionRecord): list<PostingLine>` produces the two lines (expense: wallet −a, category +a; income: category −a, wallet +a; transfer: source −a, destination +a) and asserts the zero sum. Every write deletes the transaction's postings and inserts the derived ones (ADR-0012 rule 8); a delete only removes them; a restore re-derives them.

**Rationale**: removing postings on tombstone makes "remove from every sum" (ADR-0012 rule 9) structural — balance and category sums need no join to `transactions`. The derivation is a domain rule with no framework, unit-testable.

**Alternatives**: keep postings on tombstone and filter by a join (every sum gains a join; easy to forget in B8/B13); a `void` flag on postings (a second source of truth).

## R-3 Balance recompute and `PostingTotals`

**Decision**: `EloquentPostingTotals` (Infrastructure) replaces `NoPostingsYet`: `sumFor(wallet)` = `SELECT COALESCE(SUM(amount_minor),0) FROM postings WHERE wallet_id = ?`; `hasAny(wallet)` = `EXISTS (SELECT 1 FROM transactions WHERE wallet_id = ? OR counter_wallet_id = ?)` — **tombstones included** (spec FR-019). After the posting change, for each touched wallet (old and new set), `WalletRepository::refreshBalance(wallet, sumFor(wallet))` under that wallet's lock. The `Application/Provisional` directory is deleted.

**Rationale**: P-05 rule 2 and spec 010 R-2 (recompute, never a delta). A lock that counts tombstones means a restore can never re-post in a currency the wallet no longer has; the cost is that a wallet whose transactions were all deleted keeps its currency until tombstones are purged (90 days), which is acceptable.

**Alternatives**: `hasAny` over postings (lock lifts on delete; restore would need a currency re-check that can fail forever); delta updates (drift risk, ADR-0005).

## R-4 Lock order and the write algorithm

**Decision**: `TransactionService::write` runs in one `DB::transaction`:

1. Read the stored transaction **without a lock** (if any) to learn the categories and wallets it touches.
2. Compute the lock set: categories = {stored `category_id`, requested `category_id`} minus nulls; wallets = {path wallet, stored wallet_id, stored counter, requested counter} minus nulls.
3. Share-lock the categories in ascending id (`CategoryRepository::findForShare` / `findPlatformForShare`), then `findForUpdate` the wallets in ascending id (ADR-0014 rule 10).
4. Re-read the transaction `FOR UPDATE`. If its category or wallets differ from step 1 (a concurrent write landed in between), roll back and restart from step 1, at most three times, then answer `409 VERSION_CONFLICT` with the current record.
5. Authorize (R-6), apply the precedence (spec §6), validate state rules, write the row, re-derive postings, publish/withdraw references (R-8), recompute balances, stamp sequences (R-5).

**Rationale**: every writer of a transaction locks its home wallet, so after step 3 the row cannot change; the restart only covers the window between steps 1 and 3. Locking a category after a wallet would deadlock with a category rename (spec 011 FR-023).

**Alternatives**: lock the transaction row first (deadlocks with a wallet deletion, which holds the wallet and then tombstones transactions); advisory locks per transaction (a second lock family to order).

## R-5 Sequence stamps

**Decision**: inside the locks, the transaction takes `ScopeSequence::nextFor(wallet_id)` as `change_seq` and, for a transfer, `nextFor(counter_wallet_id)` as `counter_change_seq`. References take their own next values (spec 011). Each touched wallet whose balance changed takes one final `nextFor` as its own `change_seq` (the highest in its scope, spec 010 R-3); the wallet's `version` never moves for a balance change. An identical replay takes none.

**Rationale**: ADR-0008 rules 3 and 5; spec 010's "wallet takes the last value".

## R-6 Authorization

**Decision**: `WalletAuthorizer::authorize(actor, pathWallet, T02WriteTransactions)` first (404 / 403), then for a transfer `authorize(actor, counterWallet, T03Transfer)` when the counter wallet is live and the actor a member; a counter wallet that is unknown, deleted, not a membership, or equal to the source is a state-rule refusal (`INVALID_ENUM_VALUE` at `/counter_wallet_id`) rather than a 404, so a body reference never discloses existence. Reads use `T01ReadTransactions`. A transfer whose other wallet is deleted is authorised against its live wallet only.

**Rationale**: P-08 rules 2–3; spec 010 R-10 order; spec 010 A-7 precedent of `INVALID_ENUM_VALUE` for a body reference outside the allowed set.

## R-7 Category rules on a write

**Decision**: for income/expense, the category must exist, be live, match the kind, and be the actor's own or a platform category; on an edit, an **unchanged** `category_id` is exempt from the ownership rule (another member's category stays). Archived categories pass. Every failure is one `INVALID_ENUM_VALUE` at `/category_id`. Platform categories are share-locked with `findPlatformForShare` for symmetry (they are never deleted, so the lock is cheap and harmless).

**Rationale**: spec 010 T-02 ("choosing one's own category"), ADR-0014 rule 3, spec 011 "refuse a reference to a tombstoned category". One code for every reason avoids disclosing another person's category.

## R-8 Category usage and references

**Decision**: `TransactionCategoryUsage implements CategoryUsage`, tagged: `isUsed(c)` = `EXISTS (SELECT 1 FROM transactions WHERE category_id = ?)`; `usedAmong(ids)` = `SELECT DISTINCT category_id FROM transactions WHERE category_id = ANY(?)` — one query per page, tombstones included. In the write, after the wallet lock: if the new category is a person's, `CategoryReferences::publish(wallet_id, category)`; if the category changed away from a person's category, `withdrawIfUnused(wallet_id, old)` **only when** no other transaction (live or tombstoned) in that wallet references it. Deletes and restores change no reference (the tombstone still references the category).

**Rationale**: spec 011 FR-008, FR-021–FR-023 and its brief obligations; postings mirror `transactions.category_id`, so counting transactions covers postings.

## R-9 Wallet deletion participant

**Decision**: `TransactionsGoWithWallet implements WalletDeletionParticipant`, tagged. Inside the deletion (wallet W locked) it tombstones every live transaction with `wallet_id = W` or `counter_wallet_id = W` **whose other wallet is null or already deleted**, removing their postings, each with `nextChangeSeq()`. Live transfers to or from a still-live wallet stay live with their postings (spec FR-015). No other wallet is locked or written.

**Rationale**: P-08 rule 2 (a deletion must not write into a wallet the deleter may not write); spec 010 FR-010.

## R-10 Identifier collisions (P-06 rule 3)

**Decision**: `TransactionRepository::isHeldByOther(tid, pathWallet)` answers true when `tid` is a wallet, a category (person's or platform), a category reference, a wallet membership, or a transaction that touches neither the path wallet as `wallet_id` nor as `counter_wallet_id`. The wallet and category repositories' existing `isHeldByOther` checks gain "a transaction" so a transaction id written to `/wallets/{tid}` or `/categories/{tid}` is 404.

**Rationale**: P-06 rule 3 across entity types, as spec 010 and 011 did for theirs.

## R-11 List query and search

**Decision**: one query: `WHERE deleted_at IS NULL AND (wallet_id = :w OR counter_wallet_id = :w)` plus filters, keyset on (`date`, `id`) or (`created_at`, `id`). `search` is `ILIKE '%' || escaped || '%' ESCAPE '\'` over `description`, `memo`, `counterparty`. Indexes: `(wallet_id, date DESC, id)`, partial `(counter_wallet_id, date DESC, id) WHERE counter_wallet_id IS NOT NULL`, `(category_id)`, `(created_by) WHERE deleted_at IS NULL`; postings `(wallet_id)`, `(category_id)`, `(transaction_id)`. No trigram index in MVP (search stays bounded by the wallet; revisit at the 10k sizing target if p95 exceeds 300 ms). Cursor encodes sort value + id + a hash of filters/sort/search, so a changed query is `INVALID_CURSOR`.

**Rationale**: api-conventions "Collections"; the baseline lists notes as unencrypted and searchable. A union of two index scans is planned by PostgreSQL as a BitmapOr.

**Alternatives**: full-text search (`tsvector`) — Arabic stemming is poor and it is a query language; trigram index — extension not yet approved, deferred.

## R-12 Default-currency lock

**Decision**: `EloquentTransactionQueryService` replaces `NoTransactionsYet`: `hasLiveTransactionsRecordedBy(user)` = `EXISTS (… WHERE created_by = ? AND deleted_at IS NULL)`.

**Rationale**: spec 008 R-3 defined the question; B6 answers it.

## R-13 Events

**Decision**: `TransactionPosted` (change `recorded` | `amended` | `restored`) and `TransactionVoided`, dispatched after commit through the shared domain-event base (P-11), carrying ids, kind, date, previous date, category ids and the actor — no amount, no text. No listener in B6. An identical replay dispatches nothing.

**Rationale**: constitution's Wallets row already names both events; B8 needs both dates to re-cut backdated periods.

## R-14 Validation and refusals

**Decision**: FormRequests per shape (`SaveTransactionRequest`, `PatchTransactionRequest`, `RestoreTransactionRequest`, `TransactionVersionRequest`) reuse the shared rules (`MinorUnits`, `NotBlank`, calendar date, UUID) and `FieldErrors` mapping; state rules (`IMMUTABLE_FIELD`, `CURRENCY_MISMATCH`, `INVALID_ENUM_VALUE`, `REQUIRED`) are returned by `TransactionRules` as field errors and thrown together as one `TransactionStateInvalid` (422). New field code `CURRENCY_MISMATCH` added to `error-catalog.yaml`, `ErrorCode` and `lang/{ar,en}/problems.php` in one change (parity test). The PATCH dispatcher picks the restore shape when the body has a `deleted_at` member.

**Rationale**: spec 010/011 patterns; ADR-0003 "refuse, never coerce".

## R-15 Performance

**Decision**: target p95 < 300 ms for a list page at `limit=50` over a wallet with 10,000 transactions (filtered or searched) and < 150 ms for a single write; measured in QA on the dev stack with Xdebug off, as spec 011 did.

**Rationale**: SC-007; recompute is an indexed `SUM` over one wallet's postings (≤ 20,000 rows at the sizing target).
