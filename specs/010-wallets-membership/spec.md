# Feature Specification: Wallets & Membership

**Feature Branch**: `010-wallets-membership`

**Created**: 2026-10-02

**Status**: Draft

**Input**: Brief B4 ([specs/briefs/B04-wallets-membership.md](../briefs/B04-wallets-membership.md)): "A user creates wallets of a fixed type, each with name, currency, opening balance, icon/colour, archived flag and a cached balance. Creating a wallet inserts the creator into `wallet_members` with role `owner`. Roles: owner, editor, viewer." Plus the brief's considerations, its owner decision of 2026-09-25, its reconcile table, and the paired client brief [C4](../../../saveme-kmp/specs/briefs/C04-wallets.md) "Needs from the backend".

> **Nature of this feature.** B4 builds the money container every later feature hangs off, and the one place that says who may do what in it. It creates, edits, archives and deletes wallets, keeps each wallet's cached balance correct in the same unit of work as any change to it, records who belongs to each wallet with which role, and hands ownership over. It records no transactions (B6), sends no invitations (B9) and runs no sync feed (B14): until B9 the only member a wallet can have is its owner. It also wires the Billing entitlement lookup "by wallet" that B3 left for this feature.
>
> **Where the brief and the ratified decisions differ, the ratified side wins** (owner decision 2026-09-25, constitution, glossary, ADR-0005/0006/0008):
>
> | Brief | In force | Here |
> |---|---|---|
> | Cached balance recomputed by a queued job, possibly stale, with `balance_as_of` | P-05 rule 2 and ADR-0005 rules 3–4: recomputed **in the same database transaction**, under the wallet row lock. Owner decision 2026-09-25 withdraws the queued job | Same-transaction recompute; no `balance_as_of`, no stale read |
> | Editors manage "categories in that wallet" | Categories are **user-scoped**; a shared wallet renders a partner's category through the read-only Category Reference (glossary, ADR-0012) | The permission matrix grants no category rights per wallet |
> | Types "cash, bank, savings, liability, gam'eya — final per A1/A3" | Glossary fixes `cash`, `checking`, `savings` (حصالة), `credit_card`, `loan`, `investment`; gam'eya (جمعية) is a Recurring Rule preset, not a wallet type | The glossary enum |
> | Currency "immutable after the first transaction" | Glossary: immutable once any **posting** references the wallet | The glossary rule (the two coincide: a transaction always posts to its wallet) |
> | Deleting a wallet "cascades tombstones to its transactions" | P-07 rule 5, ADR-0008 rule 11: deletion is a tombstone | Kept; the cascade reaches every live wallet-scoped record, each owning feature adding its own records to it |

## Clarifications

### Session 2026-10-02

Owner away with delegated authority; each answer below is the recommended (most conservative) option, recorded as an **assumed default**.

- Q: Is `wallets.max` enforced? → A: **Yes, through the entitlement service, on create only**, counting the creator's live owned wallets. Every launch plan grants it unlimited (spec 009 FR-006; P-09 rule 5 allows the free tier to be limited only on sharing), so the refusal `403 FEATURE_NOT_ENTITLED` is reachable only through a later data change, never through code. *Assumed default.*
- Q: May a wallet's type change after creation? → A: **No.** Type carries the ledger sign semantics (liabilities negative), so changing it would silently invert history. Refused with `IMMUTABLE_FIELD` at `/type`. *Assumed default.*
- Q: Is archiving reversible? → A: **Not in this feature.** The glossary's states are `active` → `archived`; a restore can be added later as an additive endpoint. An archived wallet keeps its history, balance and members and stays readable and editable by its owner. *Assumed default.*
- Q: May a deleted wallet be restored? → A: **No.** Wallet deletion is final in MVP (glossary: either state → `tombstoned`). The wallet write body does not accept `deleted_at`; deletion is `DELETE /wallets/{id}` only. *Assumed default.*
- Q: What happens to the previous owner on ownership transfer? → A: **They stay, as an editor.** The new owner must already be another active member; the operation never adds or removes a person. *Assumed default.*
- Q: Do wallet write routes sit behind the address-verification gate? → A: **Yes, every one** (create, update, patch, archive, delete, transfer ownership), refused with `403 EMAIL_NOT_VERIFIED`. The security baseline's verification gate requires it (checklist C1). Reads are not gated. *Assumed default.*
- Q: How does a member's display name reach the member list? → A: Through Identity's application layer, decrypted for the response only; the address is never exposed. *Assumed default.*
- Q: Which module writes the audit entry for an ownership transfer, given the security baseline names Sharing as the writer of `role_changed`? → A: **Wallets.** Every membership change goes through Wallets' application service (glossary, Wallet Member), so the baseline's "Written by" cell for `membership_granted`, `membership_revoked` and `role_changed` moves from Sharing to Wallets in this change; each event keeps exactly one writer. *Assumed default.*
- Q: Are wallet routes rate limited? → A: **No route-specific budget in this feature**, matching the profile and billing reads; `429` is therefore not documented on these operations. A per-user API budget, if the security baseline later sets one, is additive. *Assumed default.*
- Q: In what order are wallets and members listed, and can the list be filtered? → A: Wallets sort by `created_at` (default) or `name`, ascending or descending, always stabilised by `id`; no filters in this feature (`x-filterable: []`). Members sort by `created_at` only (`x-sortable: []`, fixed order). Both page with the shared `cursor`/`limit`. *Assumed default.*
- Q: How many wallets and members must the reads handle comfortably? → A: Up to 50 wallets per person and 10 members per wallet with a single page at the default `limit` of 50; beyond that, paging. *Assumed default.*

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Create and see my wallets (Priority: P1)

A person creates a wallet — say "Cash" in EGP with 500 pounds in it, or "Visa" as a credit card owing 2,000 — choosing its type, name, currency, opening balance, icon and colour. The app may do this offline and send it later. The person then sees all their wallets with their balances and their own role on each.

**Why this priority**: nothing else in the product (transactions, budgets, sharing, reports) can exist without a wallet.

**Independent Test**: as a verified person, create two wallets with client-generated identifiers, list them, and check each shows the opening balance as its balance, the person as owner, and the right type, currency, icon and colour.

**Acceptance Scenarios**:

1. **Given** a verified person, **When** they create a wallet with a new identifier, **Then** it is created, they are its only member with role `owner`, its balance equals its opening balance, and its version is 1.
2. **Given** the same create is sent twice (a retry after a lost response), **When** the second arrives, **Then** it is answered as success with the same record, and no second wallet or membership exists.
3. **Given** a person who is a member of some wallets, **When** they list wallets, **Then** they see exactly the live wallets they belong to (archived included, deleted excluded), each with their role, and never a wallet they do not belong to.
4. **Given** a liability wallet (credit card or loan) created owing 2,000, **When** it is read, **Then** its opening balance and balance are stored and returned negative, for the app to present as an amount owed.
5. **Given** an identifier that already belongs to a wallet the person is not a member of (or to another kind of record), **When** they try to create a wallet with it, **Then** they are told it was not found, and the existing record is neither changed nor disclosed.
6. **Given** a person whose address is not verified, **When** they try to create a wallet, **Then** they are refused with the verification code, so the app offers to resend the mail.

---

### User Story 2 - Edit a wallet safely (Priority: P1)

The owner renames a wallet, changes its icon, colour or opening balance, or (while nothing has been recorded in it) its currency. Edits made on two devices offline never silently overwrite each other.

**Why this priority**: wallets are edited constantly in the first days of use, and a lost edit to money data is the bug users remember.

**Independent Test**: edit a wallet carrying its last-seen version; edit again with the old version and get a conflict carrying the current wallet; check the balance follows an opening-balance change.

**Acceptance Scenarios**:

1. **Given** the owner and the wallet's current version, **When** they save the whole wallet or only some fields, **Then** the change is stored, the version goes up by one, and the response carries the new state.
2. **Given** a stale version, **When** the owner saves, **Then** the whole save is refused as a version conflict carrying the wallet's present state, and nothing is applied.
3. **Given** a save identical to the stored wallet, **When** it arrives, **Then** it succeeds with the wallet unchanged (same version).
4. **Given** the owner changes the opening balance, **When** it is saved, **Then** the balance moves by the same amount in the same unit of work.
5. **Given** a wallet that any recorded transaction posts to, **When** the owner sends a different currency, **Then** the save is refused with an "immutable field" error pointing at the currency; sending the same currency passes. The wallet reports whether its currency is locked.
6. **Given** any wallet, **When** the owner sends a different type, **Then** the save is refused with an "immutable field" error pointing at the type.
7. **Given** an editor or viewer of the wallet, **When** they try to edit it, **Then** they are refused as forbidden; **given** a non-member, **then** not found.
8. **Given** a wallet deleted on another device, **When** the owner saves an edit to it, **Then** it is a version conflict carrying the tombstone, never a silent restore.

---

### User Story 3 - Archive and delete (Priority: P2)

The owner archives a wallet they no longer use (a closed bank account) so it drops out of everyday pickers but keeps its history, or deletes one created by mistake.

**Why this priority**: a tidy wallet list matters after weeks of use, not on day one.

**Independent Test**: archive a wallet and read it back archived with its balance intact; delete another and check it is tombstoned, missing from the list, and its memberships tombstoned with it.

**Acceptance Scenarios**:

1. **Given** the owner and the wallet's version, **When** they archive it, **Then** it records when it was archived, its balance and history are unchanged, it stays in the list, and the version goes up by one.
2. **Given** an already archived wallet, **When** archive is sent again, **Then** it succeeds with nothing changed.
3. **Given** the owner and the wallet's version, **When** they delete it, **Then** the wallet is tombstoned (never removed), every live record in it — its memberships now, its transactions, recurring rules and invitations as those features arrive — is tombstoned in the same unit of work, and it disappears from every member's list.
4. **Given** an already deleted wallet, **When** delete is sent again, **Then** it succeeds with the tombstone and nothing changes.
5. **Given** an editor or viewer, **When** they try to archive or delete, **Then** they are refused as forbidden.

---

### User Story 4 - See who is in a wallet, and hand it over (Priority: P2)

A member opens a wallet's members screen and sees each member's display name and role. The owner hands the wallet to another member, staying on as an editor.

**Why this priority**: the members screen and the role it shows gate every edit control in the app; transfer is rare but must be safe. Before sharing (B9) a wallet has only its owner, so transfer can only be refused until then.

**Independent Test**: list a wallet's members as its owner (one member, role owner); set up a second member directly and transfer ownership to them; check roles swap and exactly one owner remains.

**Acceptance Scenarios**:

1. **Given** any member of a wallet, **When** they list its members, **Then** they see every active member with display name and role, and exactly one member is `owner`.
2. **Given** a non-member, **When** they list a wallet's members, **Then** they are told it was not found.
3. **Given** the owner and another active member, **When** the owner transfers ownership to that member carrying the wallet's version, **Then** that member becomes owner, the previous owner becomes editor, the wallet's version goes up by one, and exactly one owner exists throughout.
4. **Given** the owner names themselves, a non-member, or nobody valid, **When** they transfer, **Then** it is refused with a validation error pointing at the person, and nothing changes.
5. **Given** an editor or viewer, **When** they try to transfer, **Then** they are refused as forbidden.
6. **Given** a transfer already applied (the named person is already owner), **When** it is resent, **Then** it succeeds with nothing changed.

---

### User Story 5 - One rulebook for every role (Priority: P1)

Every later feature (transactions, recurring rules, invitations) asks one question: may this member do this in this wallet? This feature answers it from one table, and answers "not found" to anyone who is not a member.

**Why this priority**: the constitution makes this spec the single home of the permission matrix (P-08 rule 5); B6, B7 and B9 cannot be specified without it.

**Independent Test**: for each row of the matrix in §6, call the wallet authorization service as owner, editor, viewer and non-member, and check allow / forbidden / not-found exactly as the table says.

**Acceptance Scenarios**:

1. **Given** the matrix, **When** any wallet-scoped action is authorized, **Then** the decision comes from the caller's role in that wallet and, where the row names a capability, from the wallet owner's entitlements — both must allow it.
2. **Given** a caller who is not an active member, **When** any wallet-scoped action is authorized, **Then** the answer is not-found, never forbidden.
3. **Given** a capability check for a wallet (for example sharing in B9), **When** it is resolved, **Then** it uses the wallet **owner's** entitlements (P-09 rule 4).

---

### Edge Cases

- **Two owners at once, or none.** Impossible: every wallet has exactly one active owner at every commit; transfer swaps roles in one unit of work.
- **Negative balance.** Never refused (P-05 rule 4). A cash wallet may be overdrawn; a liability is negative by design.
- **Concurrent edits from two devices.** Exactly one lands; the other gets a version conflict with the present state.
- **Balance cache drift.** A scheduled check recomputes every balance from its source, logs any drift with the wallet id at warning level, and repairs it (ADR-0005 rule 6).
- **Create with an identifier of a deleted wallet the person belonged to.** A version conflict carrying the tombstone; deletion is final.
- **Create with an identifier used by another person's record.** Not found, nothing disclosed (P-06 rule 3).
- **Non-UUIDv7 identifier.** Refused as an invalid identifier.
- **Currency in lowercase, or a decimal amount.** Refused, never coerced.
- **Unknown request member** (for example `balance_minor` or `change_seq` sent by the client). Refused as an unknown field: the balance is never writable.
- **Editing an archived wallet.** Allowed for the owner; archiving is not freezing.
- **Owner wants to leave.** Not possible without first transferring ownership (B9 builds leaving; the matrix fixes the rule now).
- **An unverified person who is a member** (later, an invitee). May read the wallet and its members; every write is refused with the verification code.
- **Owner's plan lapses.** Nothing is deleted or hidden (P-09 rule 6); the effect on existing members is B9's to define.

## Requirements *(mandatory)*

### Functional Requirements

**Wallets**

- **FR-001**: A person MUST be able to create a wallet offline-first, addressed by an identifier they generate (UUIDv7), carrying type, name, currency, opening balance, icon and colour. A repeated create MUST NOT produce a second wallet (P-06 rule 2).
- **FR-002**: Wallet type MUST be exactly one of `cash`, `checking`, `savings`, `credit_card`, `loan`, `investment` (glossary). `credit_card` and `loan` are liability types: their balances are stored negative when owed and presented by the client as an amount owed (P-04 rule 4, ADR-0005 rule 8). The client sends and receives the **ledger sign** (owing 2,000.00 EGP on a card is `-200000`); the server never flips a sign. Type MUST NOT change after creation.
- **FR-003**: Name MUST be 1–100 characters after trimming (blank or longer is refused with `OUT_OF_RANGE` at `/name`); duplicate names are allowed. Icon and colour are optional presentation tokens (icon a short lowercase key, colour `#RRGGBB` uppercase hex); the server stores them without interpreting them.
- **FR-004**: Currency MUST be an uppercase ISO 4217 code. It MUST NOT change once any posting references the wallet; the wallet MUST report whether its currency is locked. Sending the unchanged currency always passes.
- **FR-005**: Opening balance and balance MUST be integer minor units in the wallet's currency (P-03). Neither may be refused for its sign (P-05 rule 4).
- **FR-006**: The wallet's balance MUST equal its opening balance plus the sum of its postings, and its stored copy MUST be updated in the same unit of work as any change to either, while holding the wallet's lock (P-05 rules 1–2, ADR-0005 rules 3–4). No invariant, authorization decision or report total may read the stored copy (P-05 rule 3). Until the ledger exists (B6), the sum of postings is zero.
- **FR-007**: A scheduled check MUST recompute every wallet's balance from its source, log each drift with the wallet identifier at warning level (no amount), and repair the stored copy (ADR-0005 rule 6).
- **FR-008**: The owner MUST be able to save the whole wallet, or only some of its editable fields, carrying their last-seen version. A stale version MUST refuse the whole write with a version conflict carrying the present state, including a tombstone (P-07 rules 2–4). An identical write MUST succeed with nothing changed. The server MUST NOT merge fields. A write is **identical** when every writable member it carries equals the stored value (after trimming the name), whatever version it carries; that includes a create replayed with version 0 against the wallet it created.
- **FR-008b**: A whole-wallet save carrying a version above 0 for an identifier that matches no record MUST be refused as not found: there is nothing to update, and nothing is created.
- **FR-009**: The owner MUST be able to archive a wallet. Archiving records when it happened, is not reversible in this feature, changes no balance, membership or history, and is idempotent.
- **FR-010**: The owner MUST be able to delete a wallet. Deletion MUST tombstone the wallet and, in the same unit of work, every live wallet-scoped record in it (its memberships here; transactions, recurring rules, occurrences and invitations as B6, B7 and B9 add them), each with its version and sync sequence advanced (P-07 rule 5, ADR-0008 rule 11). Deletion is final in this feature and idempotent.
- **FR-011**: A person MUST be able to list the live wallets they are an active member of (archived included, tombstoned excluded) and read one wallet, each showing their own role. A **tombstoned** wallet is visible only to the people who were its members when it was deleted (their memberships were tombstoned by the same deletion): a single read returns the tombstone, any write except delete is a version conflict carrying it, and delete returns it unchanged. To everyone else it is not found.
- **FR-011a**: The wallet list MUST sort by `created_at` (default) or `name` (byte order, not locale-aware; clients re-sort for display), stabilised by `id`, MUST accept no filters, and MUST page with the shared cursor and limit. The member list MUST be ordered by membership creation and page the same way.
- **FR-012**: Every wallet and every membership MUST carry the syncable members (`version`, `change_seq`, `created_at`, `updated_at`, `deleted_at`) and the wallet scope key (P-07 rule 1). `change_seq` MUST come from the wallet's own counter, advanced under the wallet's lock in commit order (ADR-0008 rules 2–5). Every record a write touches gets its **own** value (no two records in a scope share one), and the wallet record itself always takes the **last** value of the write, so it is the scope's highest and moves on **any** write inside the wallet (a membership now, a transaction later), while its `version` moves only when the wallet's own fields, archive state, deletion or ownership change; a client MUST NOT read a `change_seq` move alone as an edit.
- **FR-013**: Creating a wallet MUST be refused when it would exceed the creator's `wallets.max` quota, decided by the entitlement service (P-09 rule 2). Archived wallets count; tombstoned ones do not.

**Membership**

- **FR-014**: Creating a wallet MUST make the creator its only member, with role `owner`, in the same unit of work.
- **FR-015**: Roles MUST be exactly `owner`, `editor`, `viewer`. Every wallet MUST have exactly one active owner at every commit.
- **FR-016**: Any active member MUST be able to list the wallet's active members with each one's display name and role. No address or other attribute of another person is exposed (security baseline, What must never be disclosed).
- **FR-017**: The owner MUST be able to transfer ownership to another active member of the same wallet, carrying the wallet's version. The new owner becomes `owner`, the previous owner becomes `editor`, in one unit of work. Naming oneself, a non-member or a removed member MUST be refused with a validation error at the person field. Naming the current owner MUST succeed with nothing changed.
- **FR-018**: Membership MUST be written only by this module's application services; Sharing (B9) creates and revokes memberships through them (glossary, Wallet Member).

**Authorization**

- **FR-019**: Every wallet-scoped action MUST be authorized from the caller's role in that wallet per the permission matrix in §6, combined with the wallet owner's entitlements where the row names a capability (P-08 rule 1, ADR-0006 rule 3). That matrix is the single source for every policy in later specs (P-08 rule 5).
- **FR-020**: A caller who is not an active member MUST receive not-found for any wallet-scoped resource, read or write (P-08 rule 3). The one exception is FR-011's tombstone, visible to the people who were members when the wallet was deleted. Forbidden is only for a member whose role or plan does not permit the action.
- **FR-021**: Every wallet write MUST be refused with the verification code for a person whose address is not verified (security baseline, Verification gate).
- **FR-022**: The Billing entitlement service MUST answer for a wallet with its owner's entitlements, using this module's owner lookup (P-09 rule 4; spec 009 FR-008).

**Records and events**

- **FR-023**: An ownership transfer MUST record exactly one `role_changed` audit entry, written by Wallets, naming the actor, the wallet and the two people by identifier, with no amount or name. The security baseline's audit table names Wallets as the writer of the three membership events (Clarifications).
- **FR-024**: Creating, deleting and transferring a wallet MUST each publish a domain event after commit carrying identifiers and scalars only (P-11).

### Key Entities

- **Wallet** (glossary): a container of money with a fixed type, a name, a currency, an opening balance, a cached balance, presentation tokens, an archived instant, and its sync members. It is the wallet sync scope.
- **Wallet Member** (glossary): one person's membership of one wallet with a role.

## Project Sections *(mandatory for feature specs)*

### 1. Purpose

This feature gives every person their wallets — the money containers the rest of the product records into — and gives the product its one rulebook for who may do what in a shared wallet. A wallet is created offline and synced safely, its balance is always the sum of its history kept current in the same unit of work, conflicting edits are refused rather than merged, and a wallet's existence is never revealed to someone outside it.

### 2. Glossary terms

- [Wallet (محفظة)](../../docs/glossary.md#wallet-محفظة), [Wallet Member](../../docs/glossary.md#wallet-member), [Posting](../../docs/glossary.md#posting), [Entitlement](../../docs/glossary.md#entitlement), [Category Reference](../../docs/glossary.md#category-reference): as defined.
- **Liability type**: `credit_card` and `loan` (glossary, Wallet notes).
- **Currency locked**: derived, never stored — true once any posting references the wallet. Added to the Wallet entry's notes in this change, mirroring Profile's `default_currency_locked`.
- **Ownership transfer**: the owner hands the `owner` role to another active member and becomes `editor`. Added to the Wallet Member entry's notes in this change.
- **Archived**: the Wallet state `archived`, recorded as the instant `archived_at`.

### 3. Data model

Owned by `app/Modules/Wallets`.

| Entity | Key fields | Scope | Lifecycle |
|---|---|---|---|
| Wallet | `id` (UUIDv7, client-generated), `type` (enum FR-002, immutable), `name`, `currency` (ISO 4217, locked once posted to), `opening_balance_minor` (bigint), `balance_minor` (bigint, cache), `icon` (nullable), `color` (nullable), `archived_at` (nullable instant), `version`, `change_seq` (the wallet scope counter), `created_at`, `updated_at`, `deleted_at` | it **is** the wallet scope (`wallet_id` = `id`) | `active` → `archived`; either → `tombstoned` |
| Wallet Member | `id` (UUIDv7, server-generated), `wallet_id`, `user_id` (the member; exposed as `member_user_id`), `role` (`owner` \| `editor` \| `viewer`), `version`, `change_seq`, `created_at`, `updated_at`, `deleted_at` | `wallet_id` | `active` → tombstoned (on wallet deletion; B9 adds revocation); a role change stays active |

One active membership per (wallet, person). Exactly one active `owner` per live wallet.

### 4. Invariants

| Invariant | Principle or decision | Test that fails when broken |
|---|---|---|
| Balance = opening balance + Σ postings, updated in the same transaction under the wallet lock | P-05 rules 1–2, ADR-0005 | `tests/Feature/Wallets/BalanceCacheTest.php` |
| No policy, invariant or report reads `balance_minor` | P-05 rule 3 | `tests/Arch/BalanceCacheTest.php` |
| Drift is detected, logged with the wallet id, and repaired | ADR-0005 rule 6 | `tests/Feature/Wallets/VerifyBalancesTest.php` |
| Negative balances are never refused | P-05 rule 4 | `tests/Feature/Wallets/SaveWalletTest.php` |
| Exactly one active owner per live wallet, at create and across transfer | FR-015, FR-017 | `tests/Feature/Wallets/OwnershipTransferTest.php` |
| The creator is the sole owner, in the same unit of work as the wallet | FR-014 | `tests/Feature/Wallets/CreateWalletTest.php` |
| Stale version refuses the whole write with the current state (incl. tombstone) | P-07 rules 2–4 | `tests/Feature/Wallets/SaveWalletTest.php` |
| Identical replay changes neither version nor `change_seq` | ADR-0008 rule 10 | `tests/Feature/Wallets/SaveWalletTest.php` |
| `change_seq` comes from the wallet counter under its lock, unique and strictly increasing per wallet, the wallet record holding the highest | ADR-0008 rules 2–6 | `tests/Feature/Wallets/ChangeSequenceTest.php` |
| Deletion tombstones, never removes, and cascades to the wallet's records | P-07 rule 5, FR-010 | `tests/Feature/Wallets/DeleteWalletTest.php` |
| Type never changes; currency never changes once posted to | FR-002, FR-004 | `tests/Unit/Wallets/WalletRulesTest.php`, `tests/Contract/Wallets/UpsertWalletTest.php` |
| A foreign identifier is 404 and is not overwritten | P-06 rule 3 | `tests/Contract/Wallets/UpsertWalletTest.php` |
| Non-member gets 404, under-privileged member gets 403, for every row of the matrix | P-08 rules 1–3, ADR-0006 | `tests/Feature/Wallets/PermissionMatrixTest.php` |
| Every wallet write is behind the verification gate | Baseline C1, FR-021 | `tests/Contract/Wallets/VerificationGateTest.php` |
| Entitlements for a wallet are the owner's | P-09 rule 4 | `tests/Feature/Billing/EntitlementsForWalletTest.php` |
| `wallets.max` is decided by the entitlement service | P-09 rule 2, FR-013 | `tests/Feature/Wallets/CreateWalletTest.php` |
| Ownership transfer writes exactly one `role_changed` entry without amounts | FR-023, baseline | `tests/Feature/Wallets/OwnershipTransferTest.php` |

### 5. API

Under `/api/v1`, in `docs/openapi/paths/wallets.yaml`, tag `Wallets & Ledger`. **Frozen for the C4 client** (contract first). Shapes are defined in the path file; the members the client binds to are listed here so a change to any of them is visibly a contract change.

| Method | Path | operationId | Success | Error codes (beyond the shared 400/401/406/415/426/500 refusals) |
|---|---|---|---|---|
| GET | `/wallets` | `listWallets` | `200` collection | `INVALID_CURSOR`, `INVALID_QUERY_PARAMETER`, `SORT_FIELD_NOT_SUPPORTED`, `FILTER_FIELD_NOT_SUPPORTED` |
| GET | `/wallets/{id}` | `getWallet` | `200` | `NOT_FOUND`, `INVALID_ID` |
| PUT | `/wallets/{id}` | `upsertWallet` | `201` create, `200` update/replay | `EMAIL_NOT_VERIFIED`, `FORBIDDEN`, `FEATURE_NOT_ENTITLED`, `NOT_FOUND`, `VERSION_CONFLICT`, `INVALID_ID`, `VALIDATION_FAILED` |
| PATCH | `/wallets/{id}` | `patchWallet` | `200` | `EMAIL_NOT_VERIFIED`, `FORBIDDEN`, `NOT_FOUND`, `VERSION_CONFLICT`, `INVALID_ID`, `VALIDATION_FAILED` |
| POST | `/wallets/{id}/archive` | `archiveWallet` | `200` | `EMAIL_NOT_VERIFIED`, `FORBIDDEN`, `NOT_FOUND`, `VERSION_CONFLICT`, `INVALID_ID`, `VALIDATION_FAILED` |
| DELETE | `/wallets/{id}` | `deleteWallet` | `200` with the tombstone | `EMAIL_NOT_VERIFIED`, `FORBIDDEN`, `NOT_FOUND`, `VERSION_CONFLICT`, `INVALID_ID`, `VALIDATION_FAILED` |
| GET | `/wallets/{id}/members` | `listWalletMembers` | `200` collection | `NOT_FOUND`, `INVALID_ID`, `INVALID_CURSOR`, `INVALID_QUERY_PARAMETER` |
| POST | `/wallets/{id}/transfer-ownership` | `transferWalletOwnership` | `200` | `EMAIL_NOT_VERIFIED`, `FORBIDDEN`, `NOT_FOUND`, `VERSION_CONFLICT`, `INVALID_ID`, `VALIDATION_FAILED` |

**Wallet** (response; composes `SyncableResource` + `WalletScoped`): `id`, `wallet_id` (= `id`), `version`, `change_seq`, `created_at`, `updated_at`, `deleted_at`, `type`, `name`, `currency`, `opening_balance_minor`, `balance_minor`, `icon` (string or null), `color` (string or null), `archived_at` (instant or null), `currency_locked` (boolean, derived), `my_role` (`owner` | `editor` | `viewer`, the caller's role, derived).

**Wallet write** (`PUT`, all required): `type`, `name`, `currency`, `opening_balance_minor`, `icon` (nullable), `color` (nullable), `version` (omitted or `0` to create). `PATCH`: any non-empty subset of `name`, `currency`, `opening_balance_minor`, `icon`, `color`, plus required `version` ≥ 1. `archive`, `DELETE`: body `{ "version": n }`. A body without `version` is `422` with `REQUIRED` at `/version`; a `PATCH` carrying only `version` is `422` with `REQUIRED` at the body root (pointer `""`). `transfer-ownership`: body `{ "user_id": "<member's user id>", "version": n }`. Anything else (`balance_minor`, `change_seq`, `deleted_at`, `archived_at`, `currency_locked`, `my_role`, …) is `UNKNOWN_FIELD`.

**Wallet Member** (response; composes `SyncableResource` + `WalletScoped`): `id`, `wallet_id`, `version`, `change_seq`, `created_at`, `updated_at`, `deleted_at`, `member_user_id`, `display_name` (string or null), `role`.

**Field-level codes in `errors[]`**: `IMMUTABLE_FIELD` at `/currency` (currency change once posted to) and at `/type` (any type change); `INVALID_ENUM_VALUE` at `/user_id` on transfer (the named person is not another active member — the allowed set is the wallet's other active members); plus the shared `REQUIRED`, `UNKNOWN_FIELD`, `INVALID_TYPE`, `INVALID_ENUM_VALUE`, `OUT_OF_RANGE`, `AMOUNT_NOT_INTEGER`, `INVALID_CURRENCY`, `INVALID_ID`.

**Role violations**: `403 FORBIDDEN` for a member whose role does not permit the action; `404 NOT_FOUND` for a non-member; `403 EMAIL_NOT_VERIFIED` for an unverified writer; `403 FEATURE_NOT_ENTITLED` when the owner's plan does not permit it.

`info.version` moves from v1.2 to v1.3 (additive: new paths). **No new error code**: every refusal reuses the catalogue. The path file is `docs/openapi/paths/wallets.yaml`, already written and indexed in this design phase.

### 6. Authorization and entitlements

**The permission matrix.** This table is the single source (P-08 rule 5, ADR-0006 rule 10). Every policy in every later spec references a row here by its ID and never restates it. A caller who is not an active member of the wallet gets **not-found** on every row. "Yes" means allowed for that role; "—" means **403 FORBIDDEN**. Where a row names a capability, the **wallet owner's** entitlements must also allow it (else **403 FEATURE_NOT_ENTITLED**), checked through the entitlement service. Every write row is additionally behind the verification gate (FR-021). Rows marked with a later spec are fixed here and enforced when that spec builds the action.

| ID | Action | owner | editor | viewer | Capability (owner's) | Built in |
|---|---|---|---|---|---|---|
| W-01 | Read the wallet, its balance, and its member list | yes | yes | yes | — | B4 |
| W-02 | Edit the wallet's name, icon, colour, opening balance, currency | yes | — | — | — | B4 |
| W-03 | Archive the wallet | yes | — | — | — | B4 |
| W-04 | Delete the wallet | yes | — | — | — | B4 |
| W-05 | Transfer ownership to another member | yes | — | — | — | B4 |
| T-01 | Read transactions (any author's) | yes | yes | yes | — | B6 |
| T-02 | Create, edit, delete, restore a transaction (any author's), including choosing one's own category for it | yes | yes | — | — | B6 |
| T-03 | A transfer between two wallets: T-02 must hold in **both** wallets | yes | yes | — | — | B6 |
| R-01 | Read recurring rules and their occurrences | yes | yes | yes | — | B7 |
| R-02 | Create, edit, pause, end, delete a recurring rule and request its catch-up; confirm, skip or edit one of its occurrences. A recurring transfer rule also needs T-02 in its destination (as T-03) | yes | yes | — | — | B7 |
| S-01 | See the wallet's pending invitations | yes | yes | yes | — | B9 |
| S-02 | Send an invitation | yes | — | — | `sharing.enabled`, and `sharing.max_members_per_wallet` not exceeded, pending invitations counted | B9 |
| S-03 | Change another member's role (never to `owner`; that is W-05); raising to `editor` also needs the owner's `sharing.enabled` and the active member count within `sharing.max_members_per_wallet` | yes | — | — | — (enforced by Sharing's `MemberService`: a quota, not a flag) | B9 |
| S-04 | Remove another member | yes | — | — | — | B9 |
| S-05 | Leave the wallet | — (must transfer first) | yes | yes | — | B9 |
| S-06 | Revoke a pending invitation | yes | — | — | — (a lapsed plan can still withdraw an offer) | B9 |
| S-07 | Read the wallet's activity | yes | yes | yes | — | B9 |

Outside the matrix: **creating** a wallet is not wallet-scoped — any verified person may, subject to their own `wallets.max` (FR-013). **Categories and budgets** are user-scoped (glossary, ADR-0012): nobody gains a right over another person's categories or budgets through a wallet; the Category Reference is read-only and covered by T-01.

**Precedence** for every wallet write: `401 UNAUTHENTICATED` → `403 EMAIL_NOT_VERIFIED` → `422 INVALID_ID` → `422 VALIDATION_FAILED` (request shape) → `404 NOT_FOUND` (non-member, foreign id; a tombstoned wallet for anyone who was not a member at deletion) → `403 FORBIDDEN` (role) → `403 FEATURE_NOT_ENTITLED` → `409 VERSION_CONFLICT` for any non-delete write to a tombstone (even an identical one) → `200` identical replay (and `DELETE` of a tombstone) → `409 VERSION_CONFLICT` (stale version) → `422 VALIDATION_FAILED` (state rules: `IMMUTABLE_FIELD`, `INVALID_ENUM_VALUE` at `/user_id`) → write.

### 7. Events

| Event | Payload | Listeners |
|---|---|---|
| `WalletCreated` (constitution) | event id, wallet id, owner user id, type, currency | none yet (Reporting, Notifications later) |
| `WalletDeleted` (new) | event id, wallet id, actor user id | none yet (Notifications, B16). Records of other modules in the wallet (B9's invitations) are tombstoned **inside** the deletion's unit of work through a deletion hook Wallets' application layer offers, not by a listener (FR-010) |
| `WalletOwnershipTransferred` (new) | event id, wallet id, previous owner id, new owner id | Notifications (B16) |

Dispatched after commit; identifiers and scalars only (P-11). The two new events are added to the constitution's Wallets row (PATCH v1.3.3, as spec 007 did for Identity).

### 8. Acceptance scenarios

| Story · scenario | Layer | Test file |
|---|---|---|
| US1 · 1–2, 4 create | Feature, Contract | `tests/Feature/Wallets/CreateWalletTest.php`, `tests/Contract/Wallets/UpsertWalletTest.php` |
| US1 · 3 list and read | Contract | `tests/Contract/Wallets/ListWalletsTest.php`, `tests/Contract/Wallets/GetWalletTest.php` |
| US1 · 5 foreign id | Contract | `tests/Contract/Wallets/UpsertWalletTest.php` |
| US1 · 6 verification | Contract | `tests/Contract/Wallets/VerificationGateTest.php` |
| US2 · 1–8 edit | Unit, Feature, Contract | `tests/Unit/Wallets/WalletRulesTest.php`, `tests/Feature/Wallets/SaveWalletTest.php`, `tests/Feature/Wallets/BalanceCacheTest.php`, `tests/Contract/Wallets/UpsertWalletTest.php`, `tests/Contract/Wallets/PatchWalletTest.php` |
| US3 · 1–5 archive, delete | Feature, Contract | `tests/Feature/Wallets/DeleteWalletTest.php`, `tests/Contract/Wallets/ArchiveWalletTest.php`, `tests/Contract/Wallets/DeleteWalletTest.php` |
| US4 · 1–2 members | Contract | `tests/Contract/Wallets/ListMembersTest.php` |
| US4 · 3–6 transfer | Feature, Contract | `tests/Feature/Wallets/OwnershipTransferTest.php`, `tests/Contract/Wallets/TransferOwnershipTest.php` |
| US5 · 1–3 matrix | Unit, Feature | `tests/Unit/Wallets/PermissionMatrixTest.php`, `tests/Feature/Wallets/PermissionMatrixTest.php`, `tests/Feature/Billing/EntitlementsForWalletTest.php` |

### 9. Open questions

1. **Archive reversal and wallet restore.** Not offered (Clarifications). A later spec may add them additively. Does not block.
2. **How B9 represents a revoked member** — tombstone (`deleted_at`) or a separate state. The B4 schema already carries `deleted_at`; B9 decides and any new member is additive. Does not block.
3. **Transactions into an archived wallet** — B6 decides (recommended: refused). Does not block.
4. **Existing members after the owner's downgrade** — B9 (ADR-0013). Does not block.
5. **Whether pending invitations count toward `sharing.max_members_per_wallet`** in row S-02 — B9 decides. Does not block. *Resolved by spec 016: they count (research R-7).*

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A person can create a wallet offline and have it accepted on reconnect with 0 duplicates, in 100% of retried creates.
- **SC-002**: A wallet's shown balance equals its opening balance plus its recorded history in 100% of reads immediately after any change — no stale balance is ever served.
- **SC-003**: 0 edits are silently lost: every concurrent edit pair yields exactly one stored edit and one visible conflict.
- **SC-004**: 0 responses to a non-member reveal that a wallet exists.
- **SC-005**: Every role decision in this and later features traces to exactly one row of one table.
- **SC-006**: The wallet list opens with every balance in a single page, with no per-wallet summing, for a person with up to 50 wallets.

## Assumptions

- Icon keys and colours are chosen from the client's own set; the server validates only their shape.
- Until B6, no posting exists, so every wallet's currency is unlocked and its balance equals its opening balance.
- Until B9, every wallet has exactly one member (its owner), so ownership transfer can only be refused through the API; it is proven through tests that set up a second member directly.
- Until B14, the wallet's `change_seq` is assigned and stored correctly but there is no feed endpoint; the list and single reads are the client's source.
- Display names come from the member's profile (spec 008) and may be null.
