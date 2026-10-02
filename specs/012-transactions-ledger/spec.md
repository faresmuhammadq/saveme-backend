# Feature Specification: Transactions Ledger

**Feature Branch**: `012-transactions-ledger`

**Created**: 2026-10-02

**Status**: Draft

**Input**: Brief B6 ([specs/briefs/B06-transactions-ledger.md](../briefs/B06-transactions-ledger.md)): "Users record income, expenses and transfers in a wallet; each transaction has date, amount, currency (must equal the wallet's), category (not for transfers), note, optional counterparty (person name, for debts), and produces postings per the A1 ledger ADR […]. Endpoints: `GET /wallets/{id}/transactions` (cursor, date range, category, kind, search), `PUT /wallets/{id}/transactions/{tid}`, `PATCH`, `DELETE`." Plus the brief's owner decisions of 2026-09-25 (balance recomputed in the same database transaction), its reconcile table, its binding "Inherited from B5" section, and the paired client brief [C6](../../../saveme-kmp/specs/briefs/C06-transactions.md) "Needs from the backend".

> **Nature of this feature.** B6 is the product's core loop: a person records money coming in, going out, or moving between two of their wallets — fast, offline, and safe to retry — and every wallet balance, category usage and partner's view follows in the same unit of work. It builds the Transaction record, the server-derived postings behind it, the five transaction endpoints, and the real ledger answers that specs 008, 010 and 011 left as stand-ins (the balance sum, the wallet currency lock, the default-currency lock, "category in use", Category Reference publishing). It builds no budgets (B8), recurring rules (B7), reports (B13) or change-feed endpoint (B14): it fixes the record those features consume.
>
> **Where the brief and the ratified decisions differ, the ratified side wins** (constitution, ADR-0012, ADR-0014, glossary, specs 003, 010, 011):
>
> | Brief | In force | Here |
> |---|---|---|
> | "Expense: one posting" | ADR-0012: postings sum to zero; an expense is a wallet posting **and** a category posting | Two postings for every kind; the client never sees them (P-04 rule 6) |
> | Events `TransactionRecorded` / `Amended` / `Deleted` | Constitution context table: `TransactionPosted`, `TransactionVoided` | The constitution's two names are kept; no rename, no amendment |
> | "This spec fixes the changes-feed row shape" | Spec 003 and ADR-0008: a feed row **is** the resource with the syncable envelope | The Transaction resource (§5) is the row; B14 adds only the feed endpoint |
> | Balance recompute as an event consumer | P-05 rule 2, owner decision: same database transaction | Recomputed in the write's unit of work under the wallet lock |
> | "note" | ADR-0012 rule 1 and the glossary: a description, an optional memo, a `cleared` flag, `created_by` | `description` and `memo` (both optional, both the baseline's unencrypted, searchable "Note" attribute), `cleared`, `created_by`; `counterparty` is additive |
> | Search over notes | Security baseline: notes unencrypted and searchable by members | A plain substring search over `description`, `memo` and `counterparty` |

## Clarifications

### Session 2026-10-02

Owner away with delegated authority; each answer below is the recommended (most conservative) option, recorded as an **assumed default**.

- Q: How is a transfer represented on the wire — one record carrying both wallets, or two linked records? → A: **One record**: `kind: transfer`, `wallet_id` = the source, `counter_wallet_id` = the destination, no category, positive amount; it appears in both wallets' lists and is addressable under either wallet's path. P-04 rule 3 forbids two linked transactions, and one record cannot drift under edit, delete or offline sync. *Assumed default.* (FR-006, FR-010, FR-016, §5)
- Q: May a transaction be recorded in, or transferred to or from, an archived wallet? → A: **Yes.** Spec 010 open question 3 recommended refusing, but archiving is one-way (spec 010 Clarifications) and hides a wallet from pickers only; refusing would make a transaction recorded offline before the archive permanently unrecordable, and would need a new response code. The conservative choice for money data is never to lose the user's record; this matches archived categories (spec 011). *Assumed default.* (Edge Cases)
- Q: Can a deleted transaction be restored, and how? → A: **Yes**, by `PATCH` carrying exactly `{ "deleted_at": null, "version": <current> }`, authorised like an edit (row T-02 names "restore"; api-conventions "Deleting and restoring"). It re-checks the state rules against the stored record. An ordinary edit to a tombstone stays a version conflict. *Assumed default.* (FR-014)
- Q: When a wallet is deleted, what happens to transfers that link it to another, still-live wallet? → A: **They stay live** with their postings, so the other wallet's balance and history do not change; through the live wallet they can be read and deleted but not edited or restored. A wallet deletion must not write into a wallet the deleter may not be allowed to write (P-08 rule 2). Transactions all of whose wallets are deleted are tombstoned with the last one. *Assumed default.* (FR-015)
- Q: Can a transaction's kind change after it is recorded (expense ↔ income ↔ transfer)? → A: **No** — `IMMUTABLE_FIELD` at `/kind`; the client deletes and records anew. A kind change flips the ledger sides and, for a transfer, the set of wallets and feeds the record rides; refusing it keeps every edit a re-derivation of the same shape. Category kind is immutable for the same reason (spec 011). *Assumed default.* (FR-004)

Further defaults taken while writing the spec, each the conservative option and listed again in the plan's Assumed defaults:

- Field names follow ADR-0012 rule 1 and the glossary (`description`, `memo`, `cleared`, `created_by`), not the brief's "note"; `counterparty` is additive. Description and memo are optional so entry stays a few taps.
- A transfer's destination is immutable like its kind (ADR-0015 rule 3a): a record never leaves a wallet feed it has appeared on. PATCH cannot carry `kind`, `currency` or `counter_wallet_id`.
- Events keep the constitution's names, `TransactionPosted` and `TransactionVoided`; no rename or amendment.
- One new field-level code, `CURRENCY_MISMATCH`, for both a currency differing from the wallet's and a cross-currency transfer; every other refusal reuses the catalogue (`IMMUTABLE_FIELD`, `INVALID_ENUM_VALUE`, …).
- A wallet's currency locks once any transaction, live **or tombstoned**, touches it, so a restore can never re-post in a stale currency.
- Category References stay while any transaction in the wallet, live or tombstoned, uses the category (a tombstone may be restored); "in use" counts tombstones (spec 011 FR-008).
- Archived categories are allowed on new and edited transactions (spec 011 open question 3).
- An editor may edit another member's transaction while keeping its author's category; switching to a category that is neither theirs nor a platform one is refused.
- Dates 1900-01-01 to 2999-12-31; future and backdated dates allowed.
- Search is one `search` parameter, a literal case-insensitive substring over description, memo and counterparty — not a query language.
- No rate limit, no entitlement, no seeder. Sizing target: 10,000 transactions per wallet.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Record an expense or an income (Priority: P1)

A person buys groceries for 250 EGP from their Cash wallet, possibly offline, picks "Groceries" and types "Carrefour". Later their salary lands in Checking under "Salary". Each record appears at once in the wallet's list, the wallet's balance moves by exactly the amount, and a retry after a dropped connection never records it twice.

**Why this priority**: this is the product. Everything else (budgets, reports, sharing) is a view over these records.

**Independent Test**: as the owner of an EGP wallet, record an expense with a client-generated identifier, replay it, and read the wallet: one transaction, balance lowered by the amount; record an income and see the balance rise.

**Acceptance Scenarios**:

1. **Given** a verified owner or editor of a wallet, **When** they record an expense with a new identifier, a date, a positive amount in the wallet's currency and an expense category (their own or a platform one), **Then** it is created at version 1, `kind: expense`, and the wallet's balance falls by the amount in the same unit of work.
2. **Given** the same, **When** they record an income with an income category, **Then** it is created `kind: income` and the balance rises by the amount.
3. **Given** a create sent twice with the same body, **When** the second arrives, **Then** it succeeds with the same record, the balance moves once, and nothing else changes.
4. **Given** an amount sent as a decimal string, a float, zero or a negative number, **When** the write arrives, **Then** it is refused with the matching field error and nothing is recorded.
5. **Given** a currency different from the wallet's, **When** the write arrives, **Then** it is refused with a currency-mismatch field error at `/currency`.
6. **Given** a category that is another person's, deleted, unknown, or of the other kind (an income category on an expense), **When** the write arrives, **Then** it is refused with a field error at `/category_id` that does not reveal which of these it was.
7. **Given** a person's own category used for the first time in a wallet, **When** the transaction is recorded, **Then** the wallet carries a Category Reference for it in the same unit of work; a platform category gets none.
8. **Given** a viewer, a non-member, or an unverified person, **When** they try to record, **Then** they are refused with forbidden, not-found or the verification code respectively.

---

### User Story 2 - Move money between two of my wallets (Priority: P1)

A person withdraws 500 EGP from Checking into Cash, or pays 300 off their credit card from Checking. They record it once; both balances move, it shows in both wallets' lists, and it never counts as spending or income.

**Why this priority**: transfers are the second most common record and the one that breaks a naive ledger; the mobile team needs its wire shape frozen before building the entry screen.

**Independent Test**: as a member allowed to write in two same-currency wallets, record a transfer from one to the other; read both wallets: the source fell and the destination rose by the amount; list both wallets' transactions and see the one record in each.

**Acceptance Scenarios**:

1. **Given** two live wallets in the same currency in which the person may write transactions, **When** they record `kind: transfer` under the source wallet with the destination as `counter_wallet_id` and no category, **Then** one record is created, the source balance falls and the destination balance rises by the amount, in one unit of work.
2. **Given** a transfer, **When** either wallet's transactions are listed or it is read through either wallet, **Then** the same record (same identifier, same fields) is returned.
3. **Given** a destination wallet in another currency, **When** the transfer is sent, **Then** it is refused with a currency-mismatch field error at `/counter_wallet_id`, and nothing moves.
4. **Given** a destination wallet where the person is only a viewer, **When** the transfer is sent, **Then** it is refused as forbidden; **given** a destination they are not a member of, an unknown or deleted wallet, or the source itself, **Then** it is refused with a field error at `/counter_wallet_id` that does not reveal which.
5. **Given** a transfer that names a category, or an income or expense that names a counter wallet, **When** it is sent, **Then** it is refused with a field error at the member that must be null.
6. **Given** a recorded transfer, **When** it is deleted, **Then** both of its effects disappear together: both balances return to their previous values.

---

### User Story 3 - Correct, delete and restore safely (Priority: P1)

A person fixes the amount of yesterday's coffee, moves a receipt to the right date, deletes a duplicate, and undoes the delete. Their partner edits the same shared-wallet transaction on another phone at the same time; one edit lands and the other is shown a conflict with the current record, never a silent overwrite.

**Why this priority**: an offline ledger with silent overwrites loses money; conflict behaviour is the contract the C14 sync queue is built on.

**Independent Test**: edit a transaction carrying its version and see the balance follow; edit again with the old version and get a conflict carrying the current record; delete it and see the balance return; restore it and see the balance move again.

**Acceptance Scenarios**:

1. **Given** the current version, **When** a writer saves the whole transaction or some of its editable fields, **Then** the change is stored, the version goes up by one, the postings are re-derived, and every affected balance is recomputed in the same unit of work.
2. **Given** a stale version, **When** a writer saves, **Then** the whole write is refused as a version conflict carrying the current transaction, and nothing is applied.
3. **Given** a save identical to the stored transaction, **When** it arrives, **Then** it succeeds with nothing changed (same version, no balance or sequence movement).
4. **Given** any transaction, **When** a writer sends a different kind, **Then** it is refused with an immutable-field error at `/kind`.
5. **Given** the current version, **When** a writer deletes the transaction, **Then** it is tombstoned (never removed), leaves the list, its effects leave every balance, and deleting it again returns the tombstone unchanged.
6. **Given** a tombstoned transaction and its current version, **When** a writer restores it, **Then** it is live again at version + 1 with its effects back on every balance; **given** an ordinary edit to a tombstone, **Then** it is a version conflict carrying the tombstone — never a silent restore.
7. **Given** a transaction recorded by another member with that member's own category, **When** an editor changes only its amount, **Then** the save succeeds and the category stays theirs; **when** the editor tries to switch it to a third person's category, **Then** it is refused at `/category_id`.
8. **Given** a backdated edit (a new date months earlier), **When** it is saved, **Then** it is accepted and the change is announced with both the old and the new date, so budget progress (B8) can re-cut both periods.

---

### User Story 4 - Browse and find transactions (Priority: P2)

A person opens a wallet and scrolls its history newest first, narrows it to September, to "Groceries", to transfers only, or searches "Carrefour".

**Why this priority**: the list is what the app shows on every open, but recording (US1–3) delivers value first and the client can list locally before this lands.

**Independent Test**: record transactions across dates, categories and kinds; page through them; apply each filter and the search; check every page is stable and nothing is skipped or repeated.

**Acceptance Scenarios**:

1. **Given** a wallet with transactions, **When** any active member lists them, **Then** they get the live ones (tombstones excluded) newest date first, stabilised by identifier, paged with the shared cursor and limit, transfers into or out of the wallet included.
2. **Given** date, category and kind filters, **When** they are applied alone or together, **Then** only matching transactions are returned; an unsupported field or operator is refused, never ignored.
3. **Given** a search term, **When** it is applied, **Then** only transactions whose description, memo or counterparty contains it, regardless of letter case, are returned.
4. **Given** a non-member, **When** they list or read, **Then** they are told the wallet was not found.
5. **Given** a tombstoned transaction, **When** a member reads it by identifier, **Then** it is returned with `deleted_at` set.

---

### User Story 5 - The ledger behind the screens (Priority: P2)

Two partners share a household wallet. One records rent under her own "Rent" category; the other sees the label and the new balance on his phone. Once the wallet has history, its currency can no longer change; once a category has history anywhere, it can be archived but not deleted.

**Why this priority**: these are the obligations specs 008, 010 and 011 left for this feature; each was frozen in a released contract and is unreachable until B6.

**Independent Test**: record a transaction, then try to change the wallet's currency (refused), delete the category used (refused as in use), change the person's default currency (refused as locked); list the wallet's category references and see the one for the person's category.

**Acceptance Scenarios**:

1. **Given** a wallet with any transaction, live or deleted, **When** its owner changes the currency, **Then** it is refused with an immutable-field error at `/currency`, and the wallet reports `currency_locked: true`.
2. **Given** a category referenced by any transaction, live or deleted, **When** its owner tries to delete it, **Then** it is refused as in use, and the category reports `in_use: true`.
3. **Given** a person with at least one live transaction they recorded, **When** they change their default currency, **Then** it is refused as locked (spec 008), and the profile reports it locked.
4. **Given** a transaction edited to another of the person's categories, **When** no other transaction in that wallet, live or deleted, still uses the old category, **Then** the old category's reference is withdrawn in the same unit of work.
5. **Given** any write, **When** the cached balance is compared with the opening balance plus the sum of the wallet's postings, **Then** they are equal; the nightly verification finds no drift.

---

### Edge Cases

- **Archived wallet.** Recording, editing, deleting and restoring transactions in an archived wallet, and transfers to or from one, are allowed: archiving is one-way and hides a wallet from pickers only, and an offline device may not have seen it (assumed default; closes spec 010 open question 3).
- **Archived category.** Allowed on new and edited transactions (B5's recommendation): archiving hides from pickers only.
- **Category deleted between the client's pick and the write.** Refused at `/category_id`; the delete and the first use are serialised on the category (spec 011 Edge Cases), so either the delete is refused as in use or the write refers to a tombstone and is refused.
- **Negative resulting balance.** Never refused (P-05 rule 4); a liability wallet goes more negative on spending.
- **Kind change (expense ↔ income ↔ transfer).** Refused at `/kind`; the client deletes and records anew.
- **A transfer's destination changes on edit.** Refused (`IMMUTABLE_FIELD` at `/counter_wallet_id`): a record never leaves a wallet feed it has appeared on (ADR-0015); the client deletes and records anew.
- **A wallet is deleted while transfers link it to another live wallet.** Those transfers stay live, so the other wallet's balance and history are untouched (a wallet deletion never writes into a wallet the deleter may not be allowed to write, P-08 rule 2). Through the live wallet such a transfer can be read and deleted; it can no longer be edited or restored (refused at `/counter_wallet_id`). A transaction whose wallets are all deleted is tombstoned with the last of them.
- **A member of a transfer's destination only edits it through the destination.** Reading it there is allowed (row T-01); editing, restoring or deleting it is `403 FORBIDDEN`, because T-02 does not hold in the source (row T-03) and `counter_wallet_id` names the caller's own wallet, so no field is wrong (assumed default, found in QA). Through the source, a destination the caller is not (or no longer) a member of stays `INVALID_ENUM_VALUE` at `/counter_wallet_id` on edit and restore (FR-006, FR-014); deleting is `403`.
- **Transaction identifier reused under another wallet, or held by another kind of record.** Not found (P-06 rule 3); the existing record is neither changed nor disclosed. A transfer is found under either of its two wallets.
- **Two writers edit the same transfer from its two wallets at once.** Serialised by the fixed lock order; one wins, the other gets a version conflict.
- **Concurrent category rename and transaction write.** Neither deadlocks: category rows are locked before wallet rows, wallets in ascending identifier order (ADR-0014 rule 10).
- **Client sends `postings`, `change_seq`, `created_by`, `created_at`, `updated_at`, `wallet_id`, `deleted_at` (outside a restore) or any other server-owned member.** Refused as unknown fields; postings are never accepted (P-04 rule 6).
- **Date with a time or offset; date outside 1900-01-01–2999-12-31.** Refused (`DATE_NOT_CALENDAR_DATE`, `OUT_OF_RANGE`); future and backdated dates within the range are accepted.
- **Description, memo or counterparty blank after trimming.** Stored as null, never as an empty string.
- **A member leaves or is removed (B9).** Their transactions stay, attributed by `created_by` (security baseline, "Leaving a shared wallet").
- **Search term with `%` or `_`.** Matched literally, never as a wildcard.

## Requirements *(mandatory)*

### Functional Requirements

**Recording**

- **FR-001**: A writer MUST be able to record a transaction offline-first, addressed by an identifier the client generates (UUIDv7), under the wallet it belongs to. A repeated create MUST NOT produce a second transaction (P-06 rule 2).
- **FR-002**: A transaction MUST carry: `kind` (`income`, `expense` or `transfer`), a calendar `date` (`YYYY-MM-DD`, 1900-01-01 to 2999-12-31, no timezone, P-12), a **positive** `amount_minor` (1 to 2^53 − 1) and its `currency`, `category_id` (required for income and expense, null for a transfer), `counter_wallet_id` (required for a transfer, null otherwise), `description` (optional, up to 100 characters), `memo` (optional, up to 1000 characters), `counterparty` (optional person name, up to 100 characters), and `cleared` (boolean). Lengths count Unicode characters, not bytes. Text is stored trimmed; blank becomes null. The server records `created_by` (the person who first recorded it); it never changes, whoever edits, deletes or restores the transaction later.
- **FR-003**: `currency` MUST equal the wallet's currency, and for a transfer the destination wallet's too. A mismatch MUST be refused with the field code `CURRENCY_MISMATCH` at `/currency` or `/counter_wallet_id`. Cross-currency transactions are out of MVP scope (P-04 rule 5, P-17).
- **FR-004**: `kind` MUST NOT change after creation (`IMMUTABLE_FIELD` at `/kind`), and neither may a transfer's `counter_wallet_id` (`IMMUTABLE_FIELD` at `/counter_wallet_id`, ADR-0015 rule 3a). `wallet_id` is the path and never changes.
- **FR-005**: The category of an income or expense MUST be of the same kind as the transaction, live (archived allowed), and either the caller's own or a platform category (spec 010 row T-02, ADR-0014 rule 3) — **except** that an edit which leaves `category_id` unchanged keeps the stored category whoever owns it. Anything else MUST be refused with `INVALID_ENUM_VALUE` at `/category_id`, with one answer for unknown, foreign, deleted and wrong-kind categories.
- **FR-006**: A transfer's destination MUST be a live wallet other than the source, of which the caller is an active member; otherwise it MUST be refused with `INVALID_ENUM_VALUE` at `/counter_wallet_id`, with one answer for every case. A transfer always moves money **out of** `wallet_id` **into** `counter_wallet_id`.

**The ledger**

- **FR-007**: The server MUST derive the postings; a client MUST NOT send, read or sync them (P-04 rule 6). Every transaction has exactly two postings, each referencing exactly one of a wallet or a category, summing to zero, in the transaction's currency (P-04, ADR-0012): expense — wallet −amount, category +amount; income — category −amount, wallet +amount; transfer — source wallet −amount, destination wallet +amount.
- **FR-008**: The client declares `kind`, and the server derives the postings from it, so the stored `kind` always equals the classification ADR-0012 rule 6 derives from the postings (all wallet postings → transfer; otherwise income or expense by the wallet leg's direction). A transfer is excluded from every spending and income total.
- **FR-009**: Every write that changes postings (create, edit, delete, restore) MUST, in one unit of work: re-derive all of the transaction's postings (or remove them on delete), recompute the cached balance of every wallet it touches (both wallets of a transfer) from opening balance plus postings, under each wallet's lock (P-05 rules 1–2, ADR-0005), and bump the transaction's version and sync sequence. No balance is ever adjusted by a delta.
- **FR-010**: A transfer touching two wallets MUST appear in both wallets' lists and later both wallets' change feeds, as one record. It takes its `change_seq` from its own wallet's scope (`wallet_id`); the server also stamps it from the destination wallet's scope so that B14 can carry it on the destination's feed in commit order (the second value is internal and never on the wire; ADR-0015).
- **FR-011**: Locks MUST be taken in one fixed order: category rows first (shared, ascending identifier), then wallet rows (ascending identifier), for every wallet the write touches and every category the stored and the requested record reference (ADR-0014 rule 10, spec 011 FR-023).

**Editing, deleting, restoring**

- **FR-012**: A writer MUST be able to save the whole transaction or a non-empty subset of its editable fields (`date`, `amount_minor`, `category_id`, `description`, `memo`, `counterparty`, `cleared`; a whole save also carries `kind`, `currency` and `counter_wallet_id`, which must equal the stored values), carrying their last-seen version. A stale version MUST refuse the whole write with `409 VERSION_CONFLICT` carrying the current transaction, including a tombstone (P-07 rules 2–4). An identical write — every member sent equals the stored value — MUST succeed with nothing changed (no version, `change_seq`, balance, reference or event movement). The server MUST NOT merge fields. A whole save carrying a version above 0 for an unknown identifier MUST be not-found.
- **FR-013**: A writer MUST be able to delete a transaction carrying its version. Deletion tombstones the transaction (never removes it, P-07 rule 5) and removes its postings from every sum. Deleting a tombstone returns it unchanged.
- **FR-014**: A writer MUST be able to restore a tombstoned transaction by an explicit write of `deleted_at: null` with its current version, authorised exactly like an edit (api-conventions "Deleting and restoring", ADR-0008 rule 14, spec 010 row T-02). The restore re-checks every state rule of FR-003, FR-005 and FR-006 against the stored record. An ordinary edit to a tombstone MUST be a version conflict, never an implicit restore.
- **FR-015**: A transfer one of whose wallets is deleted MUST stay live and keep its postings; through its live wallet it can be read and deleted but not edited or restored (refused with `INVALID_ENUM_VALUE` at `/counter_wallet_id`). Deleting a wallet MUST tombstone, in the same unit of work (spec 010 FR-010), every live transaction all of whose wallets are then deleted.

**Reading**

- **FR-016**: Any active member MUST be able to list a wallet's live transactions — its own and transfers into it — and read one, live or tombstoned (row T-01). A transfer is readable and writable under either of its wallets' paths; any other transaction only under its own.
- **FR-017**: The list MUST sort by `date` (default, descending) or `created_at`, stabilised by `id`; filter on `date` (`eq`, `gt`, `gte`, `lt`, `lte`), `category_id` (`eq`, `in`, at most 50 values) and `kind` (`eq`, `in`); accept a `search` term (1–100 characters after trimming) matched case-insensitively and literally as a substring of description, memo or counterparty; and page with the shared cursor and limit. Anything else is refused (api-conventions "Refusals").

**Obligations inherited from specs 008, 010 and 011**

- **FR-018**: The wallet balance MUST be the real ledger sum, replacing spec 010's provisional zero; the nightly balance verification MUST check against it.
- **FR-019**: A wallet's currency MUST be locked once any transaction, live or tombstoned, posts or posted to it (`currency_locked: true`; `IMMUTABLE_FIELD` at `/currency` on a wallet write, spec 010).
- **FR-020**: A person's default currency MUST be locked while they have recorded at least one live transaction (spec 008).
- **FR-021**: Categories referenced by any transaction, live or tombstoned, MUST be reported in use, answered set-wise for a whole page of categories in a bounded number of queries (spec 011 FR-008).
- **FR-022**: Inside the write, after the category lock and the wallet lock, the server MUST publish a Category Reference for each person's category a live or tombstoned transaction in the wallet uses, and withdraw it once no transaction in that wallet, live or tombstoned, uses it any more (spec 011 FR-021–FR-023). Platform categories never get one.
- **FR-023**: Platform-category totals MUST always be scoped (a wallet, or one person's wallets) and grouped by `system_key` (ADR-0014 rules 5–6); B6 itself computes none.

**Access and safety**

- **FR-024**: Every transaction write MUST require the caller to be allowed row T-02 in the path wallet and, for a transfer, in its other wallet too (row T-03) — whichever of the two wallets the path names. A non-member gets not-found (P-08 rule 3), an under-privileged member forbidden. A transfer one of whose wallets is deleted is authorised against its live wallet only.
- **FR-025**: Every transaction write MUST be refused with `403 EMAIL_NOT_VERIFIED` for an unverified person; reads are not gated (security baseline C1). No entitlement gates transactions (P-09 rule 5). Transaction routes carry no route-specific rate limit.
- **FR-026**: Amounts, descriptions, memos and counterparties MUST NOT be logged (P-15 rule 4).
- **FR-027**: After commit, the server MUST announce `TransactionPosted` for every create, edit and restore and `TransactionVoided` for every delete through the transaction endpoints (P-11), carrying identifiers and scalars only. Transactions tombstoned by a wallet deletion's cascade announce nothing of their own; consumers react to `WalletDeleted` (spec 010). An identical replay announces nothing.

### Key Entities

- **Transaction** (glossary): a money-movement event a person records; wallet-scoped, syncable; owns its postings.
- **Posting** (glossary): a server-derived ledger leg; never exposed.
- **Wallet**, **Category**, **Category Reference** (glossary): read and updated by this feature as described above.

## Project Sections *(mandatory for feature specs)*

### 1. Purpose

This feature lets a person record what they earn, spend and move between their wallets — in a few taps, offline, and safely retried — and keeps every balance, category and partner's screen consistent with those records. It is the core loop the rest of the product reports on.

### 2. Glossary terms

- [Transaction](../../docs/glossary.md#transaction), [Posting](../../docs/glossary.md#posting), [Wallet](../../docs/glossary.md#wallet-محفظة), [Wallet Member](../../docs/glossary.md#wallet-member), [Category](../../docs/glossary.md#category), [Category Reference](../../docs/glossary.md#category-reference), [Sync Cursor](../../docs/glossary.md#sync-cursor): as defined.
- **Transfer**: a Transaction whose two postings both reference wallets; money out of `wallet_id` into `counter_wallet_id`. Added to the Transaction entry in this change.
- **Counter wallet** (`counter_wallet_id`): a transfer's destination wallet. Added to the Transaction entry in this change.
- **Counterparty**: an optional free-text person name on a transaction, for debts. Added to the Transaction entry in this change.
- **Currency locked**: the glossary's Wallet entry is amended in this change to "once any transaction, live or tombstoned, posts or posted to the wallet" (FR-019).

### 3. Data model

Owned by `app/Modules/Wallets` (context Wallets & Ledger).

| Entity | Key fields | Scope | Lifecycle |
|---|---|---|---|
| Transaction | `id` (UUIDv7, client-generated), `wallet_id` (home wallet; the source of a transfer), `kind` (`income` \| `expense` \| `transfer`, immutable), `date` (calendar date), `amount_minor` (positive bigint), `currency` (char 3), `category_id` (nullable; null exactly for a transfer), `counter_wallet_id` (nullable; set exactly for a transfer), `description`, `memo`, `counterparty` (nullable text), `cleared` (boolean), `created_by` (user id), `version`, `change_seq`, counter-scope sequence (internal, transfers only), `created_at`, `updated_at`, `deleted_at` | wallet (`wallet_id`) | `live` → `live (v+1)` on edit → `tombstoned` on delete → `live` on restore |
| Posting | `id`, `transaction_id`, exactly one of `wallet_id` / `category_id`, `amount_minor` (signed bigint), `currency` | none — never exposed | replaced wholesale on every edit; removed on delete; re-derived on restore |

No float, double or decimal column (P-03). Money is `bigint` minor units plus `char(3)` currency (ADR-0003).

### 4. Invariants

| Invariant | Principle or decision | Test that fails when broken |
|---|---|---|
| A transaction's postings sum to zero in one currency | P-04 rules 1, 5; ADR-0012 rule 4 | `tests/Unit/Wallets/Transactions/PostingDerivationTest.php`, `tests/Feature/Wallets/Transactions/LedgerIntegrityTest.php` |
| Every posting references exactly one of a wallet or a category | P-04 rule 2; ADR-0012 rule 3 | `tests/Feature/Wallets/Transactions/PostingConstraintTest.php` |
| A transfer is one transaction with two wallet postings | P-04 rule 3 | `tests/Feature/Wallets/Transactions/RecordTransferTest.php` |
| Cached balance = opening balance + Σ postings after every write, recomputed under the wallet lock | P-05 rules 1–2; ADR-0005 | `tests/Feature/Wallets/Transactions/BalanceRecomputeTest.php` |
| Currency equals the wallet's (both wallets for a transfer) | P-04 rule 5; FR-003 | `tests/Unit/Wallets/Transactions/TransactionRulesTest.php`, `tests/Contract/Wallets/Transactions/UpsertTransactionTest.php` |
| Kind never changes | FR-004 | `tests/Unit/Wallets/Transactions/TransactionRulesTest.php` |
| Stale version refuses the whole write with the current state (incl. tombstone) | P-07 rules 2–4 | `tests/Feature/Wallets/Transactions/SaveTransactionTest.php` |
| Identical replay changes neither version, `change_seq` nor any balance | ADR-0008 rule 10 | `tests/Feature/Wallets/Transactions/SaveTransactionTest.php` |
| Delete is a tombstone; restore is explicit | P-07 rule 5; ADR-0008 rule 14 | `tests/Feature/Wallets/Transactions/DeleteRestoreTransactionTest.php` |
| Postings never appear in any response | P-04 rule 6 | `tests/Contract/Wallets/Transactions/NoPostingsExposedTest.php` |
| Write needs T-02 in every wallet touched; non-member 404 | P-08 rules 1–3; spec 010 T-02, T-03 | `tests/Feature/Wallets/Transactions/TransactionAuthorizationTest.php` |
| A foreign or other-kind identifier is 404 and not overwritten | P-06 rule 3 | `tests/Contract/Wallets/Transactions/UpsertTransactionTest.php` |
| Category own-or-platform, matching kind, not deleted | FR-005; spec 010 T-02 | `tests/Feature/Wallets/Transactions/TransactionCategoryTest.php` |
| Lock order categories → wallets ascending holds under concurrency | FR-011; ADR-0014 rule 10 | `tests/Feature/Wallets/Transactions/TransactionLockOrderTest.php` |
| Used categories (live or tombstoned history) are in use, answered set-wise | FR-021; spec 011 FR-008 | `tests/Feature/Wallets/Transactions/TransactionCategoryUsageTest.php` |
| Category References published and withdrawn in the write | FR-022 | `tests/Feature/Wallets/Transactions/TransactionCategoryReferencesTest.php` |
| Wallet currency locked once any transaction touched it | FR-019 | `tests/Feature/Wallets/Transactions/CurrencyLockTest.php` |
| Every transaction write behind the verification gate | FR-025 | `tests/Contract/Wallets/Transactions/TransactionVerificationGateTest.php` |
| A wallet deletion never changes another live wallet's balance; transactions whose wallets are all deleted are tombstoned | FR-015; P-08 rule 2 | `tests/Feature/Wallets/Transactions/TransactionWalletDeletionTest.php` |
| Default currency locked while the person has a live recorded transaction | FR-020; spec 008 | `tests/Feature/Wallets/Transactions/DefaultCurrencyLockTest.php` |

### 5. API

Under `/api/v1`, in `docs/openapi/paths/transactions.yaml`, tag `Wallets & Ledger`. **Frozen for the C6 client** (contract first). Shapes are defined in the path file; the members the client binds to are listed here so a change to any of them is visibly a contract change.

| Method | Path | operationId | Success | Error codes (beyond the shared 400/401/406/415/426/500 refusals) |
|---|---|---|---|---|
| GET | `/wallets/{id}/transactions` | `listTransactions` | `200` collection | `NOT_FOUND`, `INVALID_ID`, `INVALID_CURSOR`, `INVALID_QUERY_PARAMETER`, `FILTER_FIELD_NOT_SUPPORTED`, `FILTER_OPERATOR_NOT_SUPPORTED`, `SORT_FIELD_NOT_SUPPORTED` |
| GET | `/wallets/{id}/transactions/{tid}` | `getTransaction` | `200` | `NOT_FOUND`, `INVALID_ID` |
| PUT | `/wallets/{id}/transactions/{tid}` | `upsertTransaction` | `201` create, `200` update/replay | `EMAIL_NOT_VERIFIED`, `FORBIDDEN`, `NOT_FOUND`, `VERSION_CONFLICT`, `INVALID_ID`, `VALIDATION_FAILED` |
| PATCH | `/wallets/{id}/transactions/{tid}` | `patchTransaction` | `200` (edit or restore) | `EMAIL_NOT_VERIFIED`, `FORBIDDEN`, `NOT_FOUND`, `VERSION_CONFLICT`, `INVALID_ID`, `VALIDATION_FAILED` |
| DELETE | `/wallets/{id}/transactions/{tid}` | `deleteTransaction` | `200` with the tombstone | `EMAIL_NOT_VERIFIED`, `FORBIDDEN`, `NOT_FOUND`, `VERSION_CONFLICT`, `INVALID_ID`, `VALIDATION_FAILED` |

**Transaction** (response; composes `SyncableResource` + `WalletScoped`; also the change-feed row B14 serves): `id`, `wallet_id`, `version`, `change_seq`, `created_at`, `updated_at`, `deleted_at`, `kind` (`income` | `expense` | `transfer`), `date`, `amount_minor` (positive integer), `currency`, `category_id` (uuid or null), `counter_wallet_id` (uuid or null), `description` (string or null), `memo` (string or null), `counterparty` (string or null), `cleared` (boolean), `created_by` (uuid).

**Transfer on the wire**: **one record** with `kind: transfer`, `wallet_id` = the source, `counter_wallet_id` = the destination, `category_id: null`, a positive `amount_minor`. Never two linked records (P-04 rule 3). The same record appears in both wallets' lists (and both wallet feeds in B14) and is addressable under either wallet's path.

**Transaction write** (`PUT`, all required except `version`): `kind`, `date`, `amount_minor`, `currency`, `category_id` (nullable), `counter_wallet_id` (nullable), `description` (nullable), `memo` (nullable), `counterparty` (nullable), `cleared`, `version` (omitted or `0` to create). `PATCH` edit: any non-empty subset of `date`, `amount_minor`, `category_id`, `description`, `memo`, `counterparty`, `cleared`, plus required `version` ≥ 1. `PATCH` restore: exactly `{ "deleted_at": null, "version": n }`. `DELETE`: body `{ "version": n }`. A body without `version` on PATCH or DELETE is `422` with `REQUIRED` at `/version`; a PATCH carrying only `version` is `422` with `REQUIRED` at the body root (pointer `""`). Anything else (`postings`, `wallet_id`, `created_by`, `change_seq`, `created_at`, `updated_at`, `kind`, `currency` or `counter_wallet_id` on PATCH, `deleted_at` other than in a restore, …) is `UNKNOWN_FIELD`; a non-null `deleted_at` is `INVALID_TYPE` at `/deleted_at`.

**Restore of a tombstoned transaction**: supported, as `PATCH` with `{ "deleted_at": null, "version": <current> }` (FR-014). An ordinary PUT or PATCH edit of a tombstone is `409 VERSION_CONFLICT` with the tombstone in `current`.

**Role needed on each side of a transfer**: `owner` or `editor` (row T-02) in **both** wallets (row T-03). Non-member of the source (path) wallet → 404; viewer of either → 403 `FORBIDDEN`, and so is a non-member of the source editing, restoring or deleting it through the destination's path; destination unknown, deleted, not a membership, or the source itself → 422 `INVALID_ENUM_VALUE` at `/counter_wallet_id`.

**Field-level codes in `errors[]`**:

| Situation | Code | Pointer |
|---|---|---|
| Currency differs from the wallet's | `CURRENCY_MISMATCH` (new) | `/currency` |
| Transfer between wallets of different currencies | `CURRENCY_MISMATCH` (new) | `/counter_wallet_id` |
| Wallet currency change once it has transactions (wallet endpoints, spec 010) | `IMMUTABLE_FIELD` | `/currency` |
| Kind change | `IMMUTABLE_FIELD` | `/kind` |
| A transfer's destination changed | `IMMUTABLE_FIELD` | `/counter_wallet_id` |
| Category not own-or-platform, deleted, unknown or wrong kind; category on a transfer | `INVALID_ENUM_VALUE` | `/category_id` |
| Counter wallet not allowed (see above); counter wallet on an income or expense; edit or restore of a transfer whose other wallet is deleted | `INVALID_ENUM_VALUE` | `/counter_wallet_id` |
| Category missing on income/expense; counter wallet missing on a transfer | `REQUIRED` | `/category_id`, `/counter_wallet_id` |
| Amount zero, negative or above 2^53 − 1; date outside 1900–2999; text over its length | `OUT_OF_RANGE` | the member |
| Amount a string, float or fraction | `AMOUNT_NOT_INTEGER` | `/amount_minor` |
| Date with time or offset | `DATE_NOT_CALENDAR_DATE` | `/date` |

Plus the shared `REQUIRED`, `UNKNOWN_FIELD`, `INVALID_TYPE`, `INVALID_ENUM_VALUE`, `INVALID_CURRENCY`, `INVALID_ID`.

**New code**: `CURRENCY_MISMATCH` (field level, `422`, retryable `after-change`). `info.version` moves from v1.4 to v1.5 (additive: new paths and one new field code). No new response-level code.

### 6. Authorization and entitlements

Every rule references spec 010 §6 by row ID and never restates the matrix (P-08 rule 5).

- Reading a wallet's transactions: row **T-01**.
- Recording, editing, deleting and restoring a transaction, and choosing one's own category for it: row **T-02**, in the transaction's wallet.
- A transfer: row **T-03** — T-02 must hold in both wallets. A transfer one of whose wallets is deleted is authorised against its live wallet only.
- Non-member of the path wallet → **not-found**; a transaction that does not belong to the path wallet (or, for a transfer, to either of its wallets) → **not-found**. Under-privileged member → **403 FORBIDDEN**.
- Verification gate on every write (`403 EMAIL_NOT_VERIFIED`); reads are not gated.
- Entitlements: none. No capability or quota gates transactions (P-09 rule 5).

**Precedence** for every transaction write: `401 UNAUTHENTICATED` → `403 EMAIL_NOT_VERIFIED` → `422 INVALID_ID` → `422 VALIDATION_FAILED` (request shape) → `404 NOT_FOUND` (non-member of the path wallet; deleted path wallet; identifier held by another wallet's transaction or another kind of record; PATCH or DELETE of an unknown id; PUT with version > 0 for an unknown id) → `403 FORBIDDEN` (T-02 in the path wallet) → `409 VERSION_CONFLICT` for any non-delete, non-restore write to a tombstone (even an identical one) → `200` identical replay (and DELETE of a tombstone, and restore of a live transaction) → `409 VERSION_CONFLICT` (stale version) → `403 FORBIDDEN` (T-02 in a transfer's other wallet — the destination, or the source when the path names the destination) → `422 VALIDATION_FAILED` (state rules: `IMMUTABLE_FIELD`, `CURRENCY_MISMATCH`, `INVALID_ENUM_VALUE` at `/category_id` or `/counter_wallet_id`) → write.

### 7. Events

| Event | Payload | Listeners |
|---|---|---|
| `TransactionPosted` (constitution) | event id, transaction id, wallet id, counter wallet id (or null), kind, date, previous date (or null), category id (or null), previous category id (or null), actor user id, change (`recorded` \| `amended` \| `restored`) | none yet — Budgeting (B8, budget progress), Reporting (B13) |
| `TransactionVoided` (constitution) | event id, transaction id, wallet id, counter wallet id (or null), kind, date, category id (or null), actor user id | none yet — B8, B13 |

Dispatched after commit; identifiers and scalars only, no amounts or text (P-11). The balance cache is **not** a listener (P-05 rule 2). The constitution's table is unchanged.

### 8. Acceptance scenarios

| Story · scenario | Layer | Test file |
|---|---|---|
| US1 · 1–3 record, replay | Feature, Contract | `tests/Feature/Wallets/Transactions/RecordTransactionTest.php`, `tests/Contract/Wallets/Transactions/UpsertTransactionTest.php` |
| US1 · 4–6 refusals | Unit, Contract | `tests/Unit/Wallets/Transactions/TransactionRulesTest.php`, `tests/Contract/Wallets/Transactions/UpsertTransactionTest.php` |
| US1 · 7 reference | Feature | `tests/Feature/Wallets/Transactions/TransactionCategoryReferencesTest.php` |
| US1 · 8 access | Feature, Contract | `tests/Feature/Wallets/Transactions/TransactionAuthorizationTest.php`, `tests/Contract/Wallets/Transactions/TransactionVerificationGateTest.php` |
| US2 · 1–6 transfers | Feature, Contract | `tests/Feature/Wallets/Transactions/RecordTransferTest.php`, `tests/Contract/Wallets/Transactions/TransferTest.php` |
| US3 · 1–4, 7 edit | Feature, Contract | `tests/Feature/Wallets/Transactions/SaveTransactionTest.php`, `tests/Contract/Wallets/Transactions/PatchTransactionTest.php` |
| US3 · 5–6 delete, restore | Feature, Contract | `tests/Feature/Wallets/Transactions/DeleteRestoreTransactionTest.php`, `tests/Contract/Wallets/Transactions/DeleteTransactionTest.php` |
| US3 · 8 events | Feature | `tests/Feature/Wallets/Transactions/TransactionEventsTest.php` |
| US4 · 1–5 list, read | Contract | `tests/Contract/Wallets/Transactions/ListTransactionsTest.php`, `tests/Contract/Wallets/Transactions/GetTransactionTest.php` |
| Edge cases · wallet deletion | Feature | `tests/Feature/Wallets/Transactions/TransactionWalletDeletionTest.php` |
| US5 · 1–5 obligations | Feature | `tests/Feature/Wallets/Transactions/CurrencyLockTest.php`, `tests/Feature/Wallets/Transactions/TransactionCategoryUsageTest.php`, `tests/Feature/Wallets/Transactions/DefaultCurrencyLockTest.php`, `tests/Feature/Wallets/Transactions/BalanceRecomputeTest.php` |

### 9. Open questions

1. **Split transactions** (several category legs). The ledger allows them; the contract allows exactly one category in MVP (glossary). A later contract change, not a data migration. Does not block.
2. **Cross-currency transfers and FX.** Out of MVP (P-17); refused with `CURRENCY_MISMATCH`. Does not block.
3. **Budget attribution in a shared wallet** (by category owner or `created_by`) — B8. Does not block.
4. **How B14 serves a transfer on the destination feed** — fixed by ADR-0015 (a second scope stamp, merged by counter); B14 builds the endpoint. Does not block.
5. **Transactions authored by a person who leaves** — kept, attributed by `created_by`; the anonymous marker is B15's. Does not block.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A person can record an expense in one request after picking amount and category, and see it in the wallet's list and balance on the very next read, in 100% of cases.
- **SC-002**: 0 duplicate transactions after any number of retried creates.
- **SC-003**: 0 edits silently lost: every concurrent edit pair yields exactly one stored edit and one visible conflict carrying the current record.
- **SC-004**: After every write, every touched wallet's balance equals its opening balance plus its recorded history — 0 drift found by the nightly verification.
- **SC-005**: A transfer moves both balances together or neither, in 100% of cases, and never appears in spending or income.
- **SC-006**: 0 responses reveal a wallet, transaction or category to someone outside it.
- **SC-007**: A member can page through a wallet of 10,000 transactions, filtered or searched, with each page of 50 arriving in under 300 ms at the 95th percentile, measured on the development stack with debugging extensions off (as specs 010 and 011 measured).

## Assumptions

- Spec 010's permission matrix and authorizer, spec 011's category repository, Category References and usage hook, and spec 008's profile lock are reused as built; each provisional stand-in (`NoPostingsYet`, `NoTransactionsYet`) is replaced, not wrapped.
- Until B14 there is no feed endpoint; the list and single read are the client's source, and `change_seq` is stamped exactly as ADR-0008 requires so the feed needs no backfill.
- No reference data: there is nothing to seed.
- B7 (recurring) will create transactions through the same application service, so occurrences obey every rule here.
