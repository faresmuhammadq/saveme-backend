# Feature Specification: Sync Follow-ups

**Feature Branch**: `017-sync-follow-ups` (work lands on `main`; no branch is created)

**Created**: 2026-10-02

**Status**: Draft

**Input**: User description: "Sync follow-ups (B14a). Two gaps the mobile client found while adopting the B14 change feeds (KMP spec 009-sync-feed-batch): (1) No way to know which wallet feeds have news, so every refresh costs 1 + N feed requests for N wallets. Add a cheap way for a phone to learn, in one request, which of its wallet scopes have changes beyond the positions it holds (e.g. the user feed's membership rows carrying the wallet's current change_seq, or a GET /sync/wallets/heads endpoint returning each accessible wallet's latest change_seq / purge horizon) — decide the design. (2) Membership rows are not re-sent when a member renames themselves, so partners see stale display names. Make a display-name change reach every wallet partner through the wallet feed (e.g. re-stamp that person's membership rows in each wallet they belong to under the correct lock order and scope counters), bounded for people in many wallets. Also consider whether a restore + edit could be one batch item (gap noted by the client) — decide and record, but keep scope small. Changes must be additive to the frozen sync contract (new endpoint or new optional fields only)."

> **Nature of this feature.** A small follow-up to spec 013 (B14, sync protocol), driven by the client spec `saveme-kmp/specs/009-sync-feed-batch` §9 and plan "External dependencies". It changes nothing a client already relies on: one new read, one new optional member on the feed page meta, one new server-side re-stamp after a rename, and one written promise about restores. `sync.yaml` stays frozen; every change is additive inside `v1` (`info.version` v1.9 → v1.10).
>
> | Gap reported by the client | Where it comes from | Decided here |
> |---|---|---|
> | A refresh costs 1 + N feed reads for N wallets | No "which wallets changed" summary (spec 013 has only the two feeds) | `GET /sync/wallets/heads`: each wallet the caller belongs to, with its **Scope Head** and **Purge Horizon**; every feed page also states its **Sync Position**, so the phone compares two integers and reads only the wallets that moved |
> | Partners see a stale display name after a rename | ADR-0017 Consequences: "a member's rename alone does not re-stamp their memberships"; spec 013 summary, Remaining risks | A rename re-stamps the renamer's live memberships on each wallet's feed (wallet counter only), after the profile commits, one wallet at a time |
> | "Restore with my change" cannot be one batch pair | The restored `version` is not promised by the contract | **No new batch operation.** The contract promises that an applied restore answers `version + 1`, so a `restore` item followed by an `upsert` item at that version is a safe pair inside one batch |

## Clarifications

### Session 2026-10-02

Owner away with delegated authority; each answer below is the recommended (most conservative) option, recorded as an **assumed default**.

- Q: How does a phone learn which wallets have news — membership rows on the user feed carrying the wallet's counter, the heads ride on the user feed's meta, or a separate read? → A: **A separate read, `GET /sync/wallets/heads`.** Membership rows carrying the wallet's counter would need a user-scope stamp on every member's user row for every wallet write (the alternative ADR-0017 already rejected: every wallet write would also lock every member's user row); heads in the user feed's meta would couple two scopes and grow every user-feed page. A separate read is additive, holds no state of its own, and costs one request. *Assumed default.* (FR-001 to FR-006)
- Q: What does the phone compare a head with? Cursors are opaque and must not be parsed. → A: **A new integer `meta.sync_position` on every feed page**: the scope position the page's `sync_cursor` resumes from. The phone stores it beside the cursor and compares it with the wallet's head; the cursor stays opaque. Comparing with the held wallet record's `change_seq` was rejected: a phone that applied its own write's answer holds a wallet record newer than its feed position, and would miss the other members' changes in between. *Assumed default.* (FR-004, FR-005)
- Q: Which wallets does the heads read list, and is it paged? → A: **Every non-deleted wallet in which the caller has an active membership** (archived included), ordered by wallet id, cursor-paged like every collection (default 50, up to 200 per page, no filters or sorts). Deleted wallets and ended memberships are absent: the phone learns those from the tombstoned membership on its user feed (ADR-0017 rule 4). *Assumed default.* (FR-002, FR-003)
- Q: Should the heads read also report the user scope, so a quiet refresh is one request? → A: **No.** The phone reads its user feed on every refresh anyway (it carries gained and lost wallets); a refresh with nothing new costs two requests (user feed and heads) instead of 1 + N. *Assumed default.* (SC-001)
- Q: When a member renames themselves, which feeds carry it and what moves? → A: **Each wallet feed the person belongs to.** Every live membership of the person in a non-deleted wallet takes a new `change_seq` from that wallet's counter (and the wallet record follows, as on any write in the scope). The membership's `version`, `updated_at` and `member_change_seq` do not move: the record's own fields did not change, and the person's own phone has the new name from its profile. Clearing the name (null) counts as a change. *Assumed default.* (FR-007 to FR-010)
- Q: When does the re-stamp happen, and how is it bounded for someone in many wallets? → A: **After the profile write commits, as queued work**: one short unit of work per wallet (that wallet's lock only, so no user-row lock is ever held with a wallet lock), wallets in ascending id, at most 50 wallets per queued run with a continuation for the rest. Repeated renames are coalesced: a run that has not started yet serves the later rename too. A rename is never refused or slowed because of the re-stamp. *Assumed default.* (FR-008, FR-011, FR-012)
- Q: Does a re-stamp write an audit entry or an activity entry? → A: **Neither.** It is not an act on the wallet (ADR-0020 rule 3); the profile change is already recorded by Identity as before. *Assumed default.* (FR-013)
- Q: Can a restore plus an edit be one batch item? → A: **No new item or operation.** ADR-0016 rule 2 forbids a batch item that no per-record write accepts, and the per-record PATCH refuses an edit of a tombstone. Instead the contract promises what the server already does: an applied restore answers the transaction at `version + 1`; a restore of a live transaction answers it unchanged; a stale restore is `409` with the current record. A phone may therefore send `restore` (version n) then `upsert` (version n + 1) as consecutive items of one batch; if the restore did not apply, the upsert meets a tombstone or a newer version and is refused `409 VERSION_CONFLICT` whole, never applied to the wrong state. *Assumed default.* (FR-014, FR-015)
- Q: Rate limits, entitlements, events, seeder? → A: **None new.** The heads read is gated like the feeds (authentication only, verification not required); no plan gates sync; no new domain event (the re-stamp listens to Identity's existing `ProfileUpdated`); no reference data. *Assumed default.*
- Q: How fast? → A: p95 on the dev stack with Xdebug off: the heads read ≤ 300 ms for a person in 200 wallets (one page); a rename reaches every partner's next wallet-feed read within 60 seconds on a running queue for a person in up to 200 wallets; the rename's own answer is not measurably slower (≤ 10 ms added at p95). *Assumed default.* (SC-002 to SC-004)

- Q: What does each heads entry carry beyond the wallet id and the two positions? → A: **Nothing else**: `wallet_id`, `change_seq` (the scope head) and `purged_change_seq` (the purge horizon). No name, role, balance or archive state, so the read is cheap, reveals no record content, and never competes with the feed as a source of records. *Assumed default.* (FR-001, FR-018)
- Q: Which display-name changes trigger the re-send — only profile saves, or any path that changes the stored name (such as a later account erasure)? → A: **Any change of the stored display name**, signalled by Identity's `ProfileUpdated` naming `display_name`. Today the profile save (single or batch item) is the only path; a later path that changes or erases the name (B15) MUST publish the same signal, recorded as an "Inherited from B14a" note on brief B15. *Assumed default.* (FR-007, FR-019)
- Q: Is `meta.sync_position` required on every feed page, or optional? → A: **Always sent from v1.10, declared optional in the schema**, so pages recorded before v1.10 (client fixtures) stay valid; a client that finds it absent treats the wallet as changed and reads its feed. *Assumed default.* (FR-004)

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A refresh reads only the wallets that changed (Priority: P1)

Sara belongs to six wallets. She opens the app; nothing changed anywhere since her last refresh. Her phone asks once which wallets moved, learns that none did, and reads no wallet feed. Later Karim adds an expense to "Home"; on her next refresh the phone reads the "Home" feed only.

**Why this priority**: today every refresh costs one request per wallet, which grows with sharing and costs battery and data on poor networks. It is the client's main open question (KMP spec 009 §9).

**Independent Test**: as a member of three wallets, read each wallet's feed to the end and keep each page's `sync_position`; read the heads: every head equals the stored position. Write a transaction in one wallet as another member; read the heads again: only that wallet's head is greater.

**Acceptance Scenarios**:

1. **Given** a person with active memberships in several wallets, **When** they read the heads, **Then** each of those wallets appears once with its current scope head and purge horizon, ordered by wallet id, and no other wallet appears.
2. **Given** a phone that read a wallet's feed to the end and stored the page's `sync_position`, **When** nothing is written in that wallet and the heads are read, **Then** that wallet's head equals the stored position.
3. **Given** the same phone, **When** anyone commits a write in that wallet (a transaction, a membership change, a category reference, a recurring rule, an occurrence, an invitation or the wallet itself) and the heads are read, **Then** that wallet's head is greater than the stored position, and the next feed read from the stored cursor returns that change.
4. **Given** a transfer between two wallets, **When** the heads are read, **Then** both wallets' heads have moved.
5. **Given** a wallet whose tombstones were purged past the phone's stored position, **When** the heads are read, **Then** that wallet's purge horizon is greater than the stored position, which means the next read from that cursor is `410 SYNC_CURSOR_EXPIRED`.
6. **Given** any feed page, user or wallet, **When** it is answered, **Then** its meta carries `sync_position`, the integer position its `sync_cursor` resumes from, and a page with no rows carries the same position it was asked from.

---

### User Story 2 - Partners see a member's new name (Priority: P1)

Karim renames himself from "K" to "Karim" on his phone. Mona, who shares two wallets with him, refreshes and sees "Karim" in both member lists without reopening anything.

**Why this priority**: a stale name on a shared wallet is visible to every partner, and today it only corrects itself when the membership changes for another reason.

**Independent Test**: as a person in two shared wallets and one wallet they left, store each wallet's feed position as a partner, change the person's display name, run the queue: each shared wallet's feed from the stored position carries the person's membership with the new name; the left wallet's feed carries nothing new.

**Acceptance Scenarios**:

1. **Given** a person with live memberships in several wallets, **When** they change their display name and the queued work has run, **Then** each of those wallets' feeds carries that person's membership row again, with the new name, at a position above any partner's stored position.
2. **Given** the same rename, **When** the membership row is re-sent, **Then** its `version` is unchanged, and the person's own user feed carries no new membership row.
3. **Given** a person who clears their display name, **When** the queued work has run, **Then** their memberships are re-sent with `display_name: null`.
4. **Given** a profile save that does not change the display name (a timezone change, or an identical save), **When** it commits, **Then** no membership is re-stamped.
5. **Given** a person whose membership in a wallet has ended, or whose wallet is deleted, **When** they rename themselves, **Then** that wallet's feed is not touched.
6. **Given** a person in 120 wallets, **When** they rename themselves, **Then** the rename answers as fast as any profile save, and every one of the 120 wallets carries the re-sent membership once the queued work has run, without any single unit of work holding more than one wallet lock.
7. **Given** two renames in quick succession, **When** the queued work has run, **Then** every partner's next read shows the second name.
8. **Given** the profile save arrives as a `profile` item of a batch, **When** it is applied, **Then** the memberships are re-stamped exactly as for the single write.

---

### User Story 3 - Restore with my change in one batch (Priority: P3)

Mona deleted an expense offline, then changed her mind and restored it with a corrected amount. Her phone sends the restore and the edit as two consecutive items of one batch instead of two separate requests.

**Why this priority**: it saves one request per "Restore with my change"; rare, and the per-record path already works.

**Independent Test**: tombstone a transaction at version n; send one batch `[restore (version n), upsert (version n + 1, new amount)]`: both are `applied`, the restore answers version n + 1 and the upsert version n + 2 with the new amount. Repeat with a stale version n − 1 on the restore: the restore is `conflict`, the upsert is `conflict`, nothing changed.

**Acceptance Scenarios**:

1. **Given** a tombstoned transaction at version n, **When** a restore at version n is applied (per record or as a batch item), **Then** it answers the live transaction at version n + 1.
2. **Given** a batch whose items are a restore at version n then an upsert of the same transaction at version n + 1, **When** both apply, **Then** the transaction is live with the edit at version n + 2.
3. **Given** the restore is refused (stale version, wallet gone, not permitted), **When** the upsert at version n + 1 is applied next, **Then** it is refused whole (`409 VERSION_CONFLICT` with the current record, or the same refusal the single write gives) and the stored transaction is unchanged.
4. **Given** a restore of a transaction that is already live, **When** it is applied, **Then** it answers the transaction unchanged.

---

### Edge Cases

- **A phone with no stored position for a wallet listed in the heads** (a wallet it just gained): it reads that wallet's feed from the beginning; the heads read gives it nothing to compare with.
- **A wallet the phone holds but the heads omit** (ended membership or deleted wallet): the heads read is not a removal signal; the phone acts on its user feed's tombstoned membership, or on `404` from the wallet feed (ADR-0008 rule 15, ADR-0017 rule 4).
- **A write that commits just after the heads read**: it is not reported; the next refresh reports it. A head is only ever behind the truth, never ahead of what a feed read returns.
- **A head equal to the stored position while the phone's cursor was from a page with `has_more: true`**: impossible for a wallet scope, because the scope counter is the wallet row's own `change_seq`, so the wallet row always sits at the head and is on the last page; the phone keeps reading while `has_more` is true in any case.
- **A user feed page's `sync_position`**: present for symmetry; there is no user head to compare it with in this feature.
- **A rename while the person is being removed from a wallet**: the removal and the re-stamp each take that wallet's lock; whichever runs second sees the final state. The re-stamp skips a membership that is no longer live.
- **A rename by a person whose profile has never existed** (no profile row): not possible through the profile write, which answers `404` as before.
- **A wallet deleted between the queued run's listing and its re-stamp**: skipped, nothing written.
- **A caller in no wallet**: the heads read answers `200` with `data: []`.
- **A caller in more wallets than one page holds** (over 200, or over the requested `limit`): the heads read pages by wallet id like any collection; a membership gained or ended between pages may appear or vanish, which the user feed reports anyway.
- **The queue is down**: renames still succeed; partners see the new name as soon as the queue runs, or earlier for any membership re-sent for another reason (names are rendered at read).
- **A display name equal after trimming** (`" Karim "` → `"Karim"`): not a change; no re-stamp.
- **A restore + upsert pair where another device restored in between at the same version + 1 without editing**: the upsert applies to an identical live record at the version it expected — the same result a single PUT would give.
- **A read from the start that must cross the purge horizon** (a new phone, a reinstall, or recovery after `410`): the scope's purge horizon is above some positions the pages pass through, yet no page's `next_cursor` may expire for it (FR-022). Only a cursor whose position and starting horizon are both below a horizon raised after it was issued expires.

## Requirements *(mandatory)*

### Functional Requirements

**Scope heads**

- **FR-001**: The system MUST offer one read that lists, for the caller, each wallet whose feed the caller may read as an active member, with that wallet's current scope head (the highest position committed in its scope) and its purge horizon.
- **FR-002**: The list MUST contain exactly the non-deleted wallets in which the caller has an active membership, archived wallets included, each once, ordered by wallet id ascending.
- **FR-003**: The list MUST be paged like every collection (opaque cursor, `limit` 1–200 clamped, default 50); filters and sorts MUST be refused, never ignored.
- **FR-004**: Every page of both change feeds MUST carry, in its meta, the integer position its `sync_cursor` resumes from (`sync_position`). A page with no rows MUST carry the position it was asked from. The cursor MUST stay opaque and unchanged in form. The member is always sent from v1.10 and declared optional in the schema; a client that finds it absent treats the scope as changed.
- **FR-005**: For every wallet, a phone that read the wallet's feed until `has_more` was false and stored the last page's `sync_position` MUST find that wallet's head equal to it while nothing new is committed in the wallet, and greater than it once anything is.
- **FR-006**: The heads read MUST be refused exactly as the feeds are for authentication and client-version failures; it MUST NOT require a verified address, an entitlement or an `Idempotency-Key`, and MUST NOT reveal any wallet the caller is not an active member of.

**Display name on partners' feeds**

- **FR-007**: When a person's stored display name changes (set, changed or cleared), every live membership of that person in a non-deleted wallet (archived wallets included) MUST be re-sent on that wallet's feed with the name as it is at read time.
- **FR-008**: A re-sent membership MUST take a new position from its wallet's counter under that wallet's lock, like any write in the scope, and the wallet record MUST follow as on any write in the scope. Its `version`, `updated_at` and `member_change_seq` MUST NOT change, and it MUST NOT appear again on the person's own user feed. The contract MUST tell clients to apply a re-sent row of an equal `version` (the record is newer even though its `version` is not).
- **FR-009**: A profile save that leaves the display name unchanged (after trimming), a replay, and a refused save MUST NOT re-send any membership.
- **FR-010**: Ended memberships and memberships in deleted wallets MUST NOT be re-sent.
- **FR-011**: The re-send MUST happen after the profile change commits, never inside it; it MUST NOT delay, slow or refuse the profile save; each unit of work MUST hold at most one wallet's lock and no user-row lock.
- **FR-012**: The re-send MUST be bounded per run (at most 50 wallets per queued run, continuing with the rest), idempotent (running it again only re-sends again), and coalesce renames whose work has not started yet.
- **FR-013**: The re-send MUST NOT write an audit entry or an activity entry, and MUST NOT publish a domain event.

**Restore and edit**

- **FR-014**: The contract MUST state, for transactions (the only record a batch can restore), that an applied restore answers it at the sent version + 1, a restore of a live transaction answers it unchanged, and that a batch may carry a `restore` item followed by an `upsert` item of the same transaction at that version + 1.
- **FR-015**: No new batch type or operation, and no change to what the per-record PATCH or PUT accepts, MUST be introduced.

**Contract and observability**

- **FR-016**: Every change MUST be additive to the frozen sync contract: one new read, one new meta member; no existing member, status, code or behaviour changes. `info.version` MUST move from v1.9 to v1.10.
- **FR-017**: The heads read MUST log one summary line (number of wallets returned, whether more remain) and each re-send run one summary line (wallets re-stamped, wallets skipped, whether a continuation was queued), with no ids beyond what the security baseline allows at info level, no names and no positions.
- **FR-018**: Each heads entry MUST carry only the wallet id, its scope head and its purge horizon.
- **FR-019**: The re-send MUST be triggered by any change of the stored display name signalled through Identity's `ProfileUpdated`; brief B15 MUST receive an "Inherited from B14a" note requiring any erasure path to publish that signal.
- **FR-020**: A re-stamp run that fails MUST be retried by the queue; because every wallet is its own unit of work and re-stamping is idempotent, wallets already re-stamped stay re-stamped and a retry only re-sends them again.
- **FR-021**: In the same change the constitution MUST list ADR-0021 on P-07, P-08 and P-11 (PATCH), `docs/api-conventions.md` MUST describe `sync_position` and the heads read, and brief B15 MUST carry the "Inherited from B14a" note of FR-019.
- **FR-022**: A change-feed read that begins with no cursor, after a purge has raised the scope's purge horizon, MUST be readable to the end at any page size: the cursor each page issues carries the horizon in force when the read began, and a cursor expires (`410 SYNC_CURSOR_EXPIRED`) only when the scope's purge horizon has moved past both its position and that starting horizon. A stored cursor older than the current horizon still expires; a cursor without the starting horizon (issued before this change) behaves as before. Cursors stay opaque and signed; no contract shape changes.

### Key Entities

- **Scope Head** (new protocol value, Sync): per scope, the highest position committed in it — for a wallet, the wallet's `change_seq` counter. Derived on read, never stored apart from the counter.
- **Sync Position** (new protocol value, Sync): the integer position a feed page's `sync_cursor` resumes from, stated in the page's meta beside the opaque cursor.
- **Wallet Member** (existing): gains one behaviour — re-sent on its wallet's feed when its member's display name changes; no new field.
- **Purge Horizon** (existing): now also reported by the heads read.

## Project Sections *(mandatory for feature specs)*

### 1. Purpose

Let the phone keep a person's wallets current at the cost of what changed, not of how many wallets they hold, and make a member's new name reach every partner. Both are follow-ups the client found while adopting the B14 feeds; neither changes what a client already relies on.

### 2. Glossary terms

[Wallet](../../docs/glossary.md#wallet-محفظة), [Wallet Member](../../docs/glossary.md#wallet-member), [Profile](../../docs/glossary.md#profile), [Transaction](../../docs/glossary.md#transaction), [Sync Cursor](../../docs/glossary.md#sync-cursor), [Change Row](../../docs/glossary.md#change-row), [Sync Batch](../../docs/glossary.md#sync-batch), [Purge Horizon](../../docs/glossary.md#purge-horizon). **New, added to the glossary in this change before use**: [Scope Head](../../docs/glossary.md#scope-head), [Sync Position](../../docs/glossary.md#sync-position).

### 3. Data model

No table, column or migration. The scope head is the existing `wallets.change_seq`; the purge horizon is the existing `wallets.purged_change_seq`; the sync position is the cursor's existing position. The re-stamp writes the existing `wallet_members.change_seq` (Wallets & Ledger) and `wallets.change_seq`.

### 4. Invariants

| Invariant | Principle or decision | Test that fails when broken |
|---|---|---|
| A wallet's head equals the `sync_position` of a phone caught up on that wallet, and moves on every committed write in its scope | ADR-0008 rules 3, 5; FR-005 | `tests/Feature/Sync/WalletHeadsTest.php` |
| The heads list exactly the caller's active memberships in non-deleted wallets | P-08 rule 3, ADR-0006; FR-002, FR-006 | `tests/Feature/Sync/WalletHeadsTest.php`, `tests/Contract/Sync/ListWalletHeadsTest.php` |
| `sync_position` is the position the page's cursor encodes | FR-004 | `tests/Feature/Sync/SyncPositionTest.php` |
| A display-name change re-sends every live membership of the person, once per wallet, on the wallet feed only, `version` unchanged | ADR-0017 (extended), FR-007, FR-008 | `tests/Feature/Wallets/MemberNameRestampTest.php` |
| No re-send for an unchanged name, an ended membership or a deleted wallet | FR-009, FR-010 | `tests/Feature/Wallets/MemberNameRestampTest.php` |
| A re-send unit of work holds one wallet lock and no user lock; it runs after the profile commit | ADR-0017 rule 5, P-11 rule 1; FR-011 | `tests/Feature/Wallets/MemberNameRestampTest.php` |
| An applied restore answers version + 1; a restore + upsert pair applies in one batch, and a refused restore makes the upsert refused | ADR-0016 rule 2, P-07; FR-014 | `tests/Contract/Sync/RestoreEditPairTest.php` |

### 5. API

All under `/api/v1`, in `docs/openapi/paths/sync.yaml` (frozen, additive to v1.10).

| Method and path | Change | Codes |
|---|---|---|
| `GET /sync/wallets/heads` | **new** (`listWalletHeads`) | 200; 400 `INVALID_CURSOR`, `INVALID_QUERY_PARAMETER`, `FILTER_FIELD_NOT_SUPPORTED`, `SORT_FIELD_NOT_SUPPORTED`; 401; 406; 426; 500 |
| `GET /sync/user/changes`, `GET /sync/wallets/{id}/changes` | `meta.sync_position` (integer, ≥ 0) added to `ChangeFeedMeta` | unchanged |
| `POST /sync/batch`, `PATCH /wallets/{id}/transactions/{tid}` | description only: the restore version promise and the restore + upsert pair | unchanged |

No new error code.

### 6. Authorization and entitlements

The heads read lists only wallets where the caller holds an active membership (spec 010 row W-01 for a live wallet); nothing else is revealed and nothing is refused per wallet. The re-stamp is server work on the person's own memberships; it authorizes nothing new. No `Feature` gates either.

### 7. Events

Consumes `ProfileUpdated` (Identity, existing; `changedFields` contains `display_name`) through a queued, idempotent Wallets listener. Publishes nothing new.

### 8. Acceptance scenarios

| Story | Layer | Proof |
|---|---|---|
| US1 Heads | Feature (heads service and `sync_position`), Contract (endpoint and meta against `sync.yaml`) | `tests/Feature/Sync/WalletHeadsTest.php`, `tests/Feature/Sync/SyncPositionTest.php`, `tests/Contract/Sync/ListWalletHeadsTest.php`, `tests/Contract/Sync/FeedPositionTest.php` |
| US2 Names | Feature (listener, job, lock and skip rules, batch profile item) | `tests/Feature/Wallets/MemberNameRestampTest.php` |
| US3 Restore pair | Contract (per record and batch) | `tests/Contract/Sync/RestoreEditPairTest.php` |

### 9. Open questions

- **A user-scope head** (a quiet refresh in one request instead of two): not needed now; a later additive member of the same read if the client asks. Non-blocking.
- **Push-triggered refresh** (B16) may make the heads read rarer; it does not replace it. Non-blocking.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A refresh with nothing new costs a phone in N wallets 2 requests instead of 1 + N; a refresh after a change in k wallets costs 2 + k.
- **SC-002**: For a person in 200 wallets, the heads read answers in one page within 300 ms at p95.
- **SC-003**: After a rename, 100% of the person's partners see the new name on their next refresh once the queue has run, within 60 seconds for a person in up to 200 wallets.
- **SC-004**: A rename's own answer is no more than 10 ms slower at p95 than before this feature.
- **SC-005**: A "Restore with my change" can travel in one batch request, and a refused restore never lets its edit apply.
- **SC-006**: No existing contract test, client fixture or documented behaviour changes; the contract suite passes unchanged except for added cases.

## Assumptions

- The client (KMP spec 009 follow-up) adopts the heads read and `sync_position` in its own spec; until it does, nothing changes for it.
- `wallets.change_seq` is the wallet scope's counter itself (every stamp is `UPDATE wallets SET change_seq = change_seq + 1`, spec 013), so the wallet row always sits at its scope's head and a caught-up position equals the head (research R-2).
- Display names are rendered at read time from the profile (spec 010 R-8), so a re-stamp needs no name copy.
- The client applies a feed row whose `version` equals the stored one (KMP spec 009 data-model: "apply if version ≥ stored"); a re-sent membership relies on it.
- The queue workers already run in every environment (spec 013, 016 deployment notes).
- Memberships per person are small at MVP scale (a handful), but the design is bounded for hundreds.
- No migration, seeder or new dependency is needed; "Seeder: N/A — no reference data".
