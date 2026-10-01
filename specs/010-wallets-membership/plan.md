# Implementation Plan: Wallets & Membership

**Branch**: `010-wallets-membership` (trunk-based; work lands on `main`) | **Date**: 2026-10-02 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/010-wallets-membership/spec.md`.

## Summary

Build the Wallets module's first real slice.

- **Wallets**: `wallets` stores each wallet with its cached balance, recomputed as opening balance + Σ postings inside the same transaction under the wallet row lock (P-05, ADR-0005). Postings arrive in B6; until then a provisional `PostingTotals` answers 0.
- **Membership**: `wallet_members` records each person's role; the creator is the sole owner; ownership transfers swap roles in one transaction and write one `role_changed` audit entry.
- **Sync members**: the wallet row's `change_seq` is the wallet scope counter (ADR-0008); every record written in a wallet takes its next value under the lock.
- **Authorization**: the permission matrix (spec §6) is encoded once as a pure domain table and enforced by `WalletAuthorizer`, which later specs reuse. Non-members get 404. Every write is behind the verification gate.
- **Entitlements**: Billing's `EntitlementService::forWallet` is wired through Wallets' owner lookup; `wallets.max` is checked on create.
- **API**: eight operations in `docs/openapi/paths/wallets.yaml` (already written and indexed in this design phase; `info.version` v1.3), frozen for the C4 client.
- **Drift check**: `wallets:verify-balances`, daily.

## Technical Context

**Language/Version**: PHP 8.5, Laravel 13.

**Primary Dependencies**: none new.

**Storage**: PostgreSQL 16; two tables (`wallets`, `wallet_members`), partial unique indexes for one active membership per person and one active owner per wallet.

**Testing**: Pest.
- Unit: `PermissionMatrix` (against spec §6), `WalletRules`, `WalletType`.
- Feature: create, save, balance cache, change sequence, delete cascade, ownership transfer, permission matrix through the authorizer, verify-balances, entitlements for a wallet.
- Contract: the eight operations with every documented status (Spectator).
- Arch: existing P-02 gates; a new grep-style gate that `balance_minor` is read only by Wallets Infrastructure and the wallet resource (P-05 rule 3).

**Target Platform**: the Linux server (the `dev` container).

**Project Type**: modular-monolith JSON API.

**Performance Goals**: `GET /wallets` p95 under 100 ms for 50 wallets (one indexed join, no aggregation); a wallet write holds the row lock for at most a handful of statements.

**Constraints**: P-03 (bigint minor units), P-05 (same-transaction cache, never read for decisions), P-06 (client UUIDv7, foreign id → 404), P-07 (version, tombstones, no merge), P-08 (matrix only in the spec, 404 for non-members), P-09 (capabilities via `EntitlementService`), P-11 (events after commit).

**Scale/Scope**:
- 8 operations, 2 migrations, no seeder;
- about 25 Wallets classes, plus small edits in Billing (`forWallet`) and Identity (`DisplayNames`, `RoleChangedDetail`);
- about 18 test files;
- doc edits: glossary, security baseline (audit writer), constitution PATCH v1.3.3 (events).

## Constitution Check

*Constitution v1.3.2 (`.specify/memory/constitution.md`). GATE: must pass before Phase 0 research. Re-check after Phase 1 design, keeping both results.*

### Pre-design

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | "Wallet" only; type enum from the glossary (no "bank", no "gam'eya" type). New terms ("currency locked", "ownership transfer") are added to the glossary in this change |
| P-02 Module boundaries | PASS | All in `app/Modules/Wallets`. Billing reaches `Wallets\Application\MembershipQueryService`; Wallets reaches `Billing\Application\Entitlements\EntitlementService`, `Identity\Application\Profiles\DisplayNames` and `Identity\Application\Audit\AuditLog`. The verification gate is applied by middleware alias `address.verified`, not by import |
| P-03 Money | PASS | `opening_balance_minor`, `balance_minor` are bigint minor units with the wallet's `currency`; no decimal anywhere |
| P-04 Ledger integrity | N-A | No postings are written; the balance formula reads them through `PostingTotals` (zero until B6) and none is exposed |
| P-05 Derived balances | PASS | Recompute = opening + Σ postings in the same transaction under `lockForUpdate` (R-2); verify job (FR-007); `tests/Arch/BalanceCacheTest.php` guards rule 3; negative balances never refused |
| P-06 Identifiers | PASS | `PUT /wallets/{id}` upsert with client UUIDv7; foreign id → 404 without write; members get server UUIDv7 |
| P-07 Sync and concurrency | PASS | Both entities carry the five sync members; `version` in every write; 409 with `current` incl. tombstone; no merge; deletion is a tombstone cascade |
| P-08 Authorization | PASS | Matrix lives only in spec §6; `PermissionMatrix` encodes it as data and a unit test compares it with the spec rather than restating it in comments; non-member → 404; no global role |
| P-09 Entitlements | PASS | `wallets.max` through `EntitlementService`; `forWallet` uses the owner; no plan code touched; losing entitlement deletes nothing |
| P-10 Billing channels | N-A | No billing surface |
| P-11 Events | PASS | `WalletCreated`, `WalletDeleted`, `WalletOwnershipTransferred` dispatched after commit with ids and scalars; the deletion cascade uses an in-transaction participant hook, not a listener (R-9) |
| P-12 Time | PASS | `archived_at`, sync instants UTC `timestamptz(6)`; no calendar dates in this feature |
| P-13 Naming | PASS | No codename in namespaces, tables or routes |
| P-14 Localisation | PASS | Problem details localised (ar/en) via translation files; numbers are integers; names are user data |
| P-15 Observability | PASS | Drift warning logs the wallet id only, no amount; no financial payload logged |
| P-16 Quality gates | PASS | Every spec §4 invariant names its test; no new dependency |
| P-17 Scope guard | PASS | No FX, no multi-currency transfer; one currency per wallet |
| P-18 Delivery surfaces | PASS | Every capability is a `/api/v1` endpoint; no back-office screen |

### Post-design re-check

Unchanged: all rows PASS or N-A.

- The constitution's Wallets row gains `WalletDeleted` and `WalletOwnershipTransferred` (PATCH v1.3.3; wording only, as v1.3.1 did for Identity).
- The security baseline's audit table names Wallets, not Sharing, as writer of `membership_granted`, `membership_revoked`, `role_changed` (spec Clarifications). Each event still has exactly one writer.
- No ADR is extended or contradicted: R-3 applies ADR-0008 rules 2–5 as written; Identity's global sequence (spec 007 R-19) stays as it is for the user scope. No decision record needed (CONTRIBUTING item 18).
- No new error code; `info.version` v1.3 for the new paths.

Complexity Tracking stays empty.

## Assumed defaults (owner away)

| # | Decision | Why |
|---|---|---|
| A-1 | Type enum from the glossary; type immutable | Ratified vocabulary; changing type would invert liability signs |
| A-2 | Currency locked once posted to → `IMMUTABLE_FIELD` at `/currency`; `currency_locked` exposed | Same code and pattern as the profile's default currency |
| A-3 | `wallets.max` enforced on create via entitlements; unlimited on every plan | P-09 rule 2 and rule 5 |
| A-4 | Archive one-way; delete final; no `deleted_at` in the write body | Glossary state machine; restore can be added later additively |
| A-5 | Owner-only wallet edits, archive, delete, transfer; editors write transactions/rules; viewers read | Brief's role descriptions, narrowest reading |
| A-6 | Transfer: target must be another active member; previous owner → editor; already-owner → 200 no-op | Never adds or removes a person; retry-safe |
| A-7 | Transfer target error reuses `INVALID_ENUM_VALUE` at `/user_id` | No new catalogue code |
| A-8 | `address.verified` on every write route | Security baseline Verification gate, checklist C1 |
| A-9 | `version` in the JSON body, including on DELETE and archive; no `If-Match` | Matches `saveProfile`; header inventory has no `If-Match` |
| A-10 | Wallet row `change_seq` is the scope counter | ADR-0008 rules 2–5; the balance lock already serialises the scope |
| A-11 | Deletion cascade inside the transaction through participants | FR-010 one unit of work |
| A-12 | Wallets writes the three membership audit events | One writer per event; all membership changes go through `WalletService` |
| A-13 | Member exposes `member_user_id`, `display_name`, `role` only | `user_id` is the user-scope key (D2); baseline allows display names to members |
| A-14 | `GET /wallets/{id}` added; list sorts `created_at`/`name`, no filters; no rate limit | Minimal additive surface the client needs |
| A-15 | `wallets:verify-balances` daily via provisional postings port | ADR-0005 rule 6 from day one |

## Review checklists (T043)

Applied to every operation in `docs/openapi/paths/wallets.yaml` against the implementation.

- **Endpoint review checklist** (`docs/api-conventions.md` appendix): every line answers yes, with these recorded exceptions. C8 (read-only resource) and G3, G4 (money operations with `Idempotency-Key`) do not apply: wallets are upserted and idempotent by outcome. G5 does not apply: no wallet route is throttled (R-12), so `429` is not documented. D5: the cached `balance_minor` is a wallet member, not a posting; no posting is exposed.
- **Security review checklist** (C1-C2, D1, E1-E5): C1 every wallet write route sits behind `address.verified`; C2 reads do not (`VerificationGateTest`). D1 wallet names and the display name shown to members are in the protected-attributes table. E1, E3-E5 hold: no body, name or amount is logged, and refusals carry no other person's data. **E2 is a justified "no"**: the drift warning `wallet_balance_drift` carries the wallet id, as spec FR-007 and ADR-0005 rule 6 require, so an operator can find the wallet; it never carries an amount.
- The implemented API matches the frozen contract exactly; no member the C4 client binds to changed.

## Project Structure

### Documentation (this feature)

```text
specs/010-wallets-membership/
├── spec.md
├── plan.md                 # this file
├── research.md             # R-1 … R-13
├── data-model.md
├── quickstart.md
├── contracts/wallets-api.md
├── checklists/requirements.md, checklists/contract.md
└── tasks.md                # /speckit-tasks
```

### Source Code (repository root)

```text
app/Modules/Wallets/
├── Domain/
│   ├── Wallets/{WalletType,WalletRecord,WalletRules}.php
│   ├── Membership/{Role,Permission,PermissionMatrix,MemberRecord}.php
│   ├── Events/{WalletCreated,WalletDeleted,WalletOwnershipTransferred}.php
│   ├── Refusals/{WalletNotFound,WalletActionForbidden,WalletVersionConflict,WalletFieldImmutable,OwnershipTargetInvalid}.php
│   └── Ports/{WalletRepository,MembershipRepository,PostingTotals,ScopeSequence}.php
├── Application/
│   ├── WalletsServiceProvider.php                  # binds services, provisional PostingTotals
│   ├── WalletService.php                           # save, patch, archive, delete, transferOwnership
│   ├── WalletQueryService.php                      # list, find (with my_role, currency_locked)
│   ├── MembershipQueryService.php                  # ownerOf, roleOf, activeMembersOf (+ display names)
│   ├── Authorization/WalletAuthorizer.php
│   ├── Deletion/WalletDeletionParticipant.php
│   ├── Balances/VerifyBalances.php
│   └── Provisional/{NoTransactionsYet,NoPostingsYet}.php
├── Infrastructure/
│   ├── WalletsPersistenceServiceProvider.php       # port bindings
│   ├── Models/{Wallet,WalletMember}.php
│   ├── Repositories/{EloquentWalletRepository,EloquentMembershipRepository}.php
│   ├── Sync/WalletScopeSequence.php
│   └── Database/{Migrations/2026_10_02_000001_create_wallets_table.php,…000002_create_wallet_members_table.php,Factories/{WalletFactory,WalletMemberFactory}.php}
└── Http/
    ├── WalletsHttpServiceProvider.php              # routes, console command
    ├── routes.php                                  # auth:api; writes + address.verified
    ├── Controllers/{WalletController,WalletMembersController}.php
    ├── Requests/{SaveWalletRequest,PatchWalletRequest,VersionRequest,TransferOwnershipRequest}.php
    ├── Resources/{WalletResource,WalletMemberResource}.php
    └── Console/VerifyBalances.php                  # wallets:verify-balances, daily

changed: Billing EntitlementService (forWallet), Identity Profiles/DisplayNames (new),
         Identity Audit/Details/RoleChangedDetail (new), Identity UserFactory (R-13),
         bootstrap/providers.php, routes/console.php, lang/{ar,en}/problems.php
docs/openapi/paths/wallets.yaml, openapi.yaml (done in design)
docs/glossary.md, docs/security-baseline.md, .specify/memory/constitution.md (v1.3.3)

tests/Unit/Wallets/{PermissionMatrix,WalletRules,WalletType}Test.php
tests/Feature/Wallets/{CreateWallet,SaveWallet,BalanceCache,ChangeSequence,DeleteWallet,OwnershipTransfer,PermissionMatrix,VerifyBalances}Test.php
tests/Feature/Billing/EntitlementsForWalletTest.php
tests/Feature/Identity/UserFactoryOutsideTransactionTest.php
tests/Contract/Wallets/{ListWallets,GetWallet,UpsertWallet,PatchWallet,ArchiveWallet,DeleteWallet,ListMembers,TransferOwnership,VerificationGate}Test.php
tests/Arch/BalanceCacheTest.php
```

**Structure Decision**: Wallets uses all four layers like Identity and Billing, with separate Infrastructure and Http providers. The existing provisional `NoTransactionsYet` stays until B6; `NoPostingsYet` joins it in `Application/Provisional`, and B6 deletes both.

## Complexity Tracking

No Constitution Check violations; nothing to justify.
