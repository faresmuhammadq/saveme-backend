# Research: Wallets & Membership

**Feature**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Date**: 2026-10-02

Every decision below was taken with the owner away under delegated authority; each is the recommended, most conservative option, and is listed in the plan's Assumed defaults.

---

## R-1 The wallet row: one table, the balance cache on it

**Decision**: `wallets` holds `id` (uuid, client-generated), `type`, `name`, `currency` (char 3), `opening_balance_minor` and `balance_minor` (bigint), `icon`, `color`, `archived_at`, and the sync members `version`, `change_seq`, `created_at`, `updated_at`, `deleted_at`. `balance_minor` is a cache (ADR-0005 rule 2). There is no `owner_id` column: ownership lives on `wallet_members` (glossary, Wallet Member notes).

**Rationale**: ADR-0005 puts the cache on the wallet row so the list reads one column. Keeping ownership only in `wallet_members` leaves a single source for "who is owner", so a transfer cannot leave two disagreeing copies.

**Alternatives**: an `owner_id` column on `wallets` — rejected, a second copy of a fact the membership table already holds; a partial unique index on `wallet_members (wallet_id) WHERE role = 'owner' AND deleted_at IS NULL` enforces exactly one owner instead (with the "at least one" half proven by tests, since a constraint cannot express it).

## R-2 Balance recompute: same transaction, wallet row lock, a postings port

**Decision**: every write that changes `opening_balance_minor` (here) or postings (B6) runs inside `DB::transaction`, takes `lockForUpdate` on the wallet row, and sets `balance_minor = opening_balance_minor + PostingTotals::sumFor(walletId)`. `Wallets\Domain\Ports\PostingTotals` has `sumFor(walletId): int` and `hasAny(walletId): bool`; until B6 it is bound to `Wallets\Application\Provisional\NoPostingsYet` (0 and false), following the `NoTransactionsYet` precedent. The B4 write path never reads `balance_minor` to compute the new value.

A scheduled `wallets:verify-balances` command (daily) recomputes each wallet's balance through the same port under its row lock, logs `wallet_balance_drift` at `warning` with the wallet id only (no amount — security baseline E2), and repairs the column.

**Rationale**: P-05 rule 2 and ADR-0005 rules 3–4; owner decision 2026-09-25 withdrew the queued job. Recomputing from opening balance + Σ postings (rather than adding a delta to the cache) makes every write self-healing and keeps rule 5 — the cache is never an input.

**Alternatives**: delta update (`balance_minor += Δ`) — rejected, it reads the cache as an input and propagates drift; queued listener — rejected by the owner decision and ADR-0005.

## R-3 `change_seq`: the wallet row is the scope counter

**Decision**: the wallet's own `change_seq` column is the wallet scope's counter (ADR-0008 rule 2). Every write to anything in the scope locks the wallet row; each record the write touches (a membership, later a transaction) takes the next value `wallets.change_seq + 1`, one per record; then the wallet record itself takes one **final** value, so it always holds the scope's highest value and no two records in the scope ever share one. Ties would break ADR-0008 rule 6's `change_seq > cursor ORDER BY change_seq LIMIT n` pull at a page boundary. A wallet's `change_seq` therefore moves whenever anything in it changes; its `version` moves only when the wallet's own fields change. A replay bumps neither. `Wallets\Infrastructure\Sync\WalletScopeSequence` implements `Wallets\Domain\Ports\ScopeSequence::nextFor(walletId)` and must be called with the row already locked.

**Rationale**: ADR-0008 rules 3 and 5 require commit-ordered values per scope, which the row lock gives for free (the balance lock is the same lock). A wallet re-appearing in its own feed when a member or transaction changes is harmless under at-least-once delivery, and is needed anyway once `balance_minor` changes with each transaction.

**Alternatives**:
- Identity's global `sync_change_seq` sequence (spec 007, R-19) — rejected for the wallet scope: ADR-0008 explains a global sequence is not commit-ordered, and shared wallets are exactly where concurrent writers occur. Identity's user scope keeps its sequence until B14.
- A separate `scope_seq` column — rejected: ADR-0008 names the scope counter `change_seq` on the scope row, and two columns would let them disagree.

## R-4 Writes: PUT upsert, PATCH, and the replay/conflict order

**Decision**: one application service, `Wallets\Application\WalletService` (the constitution's name), with `save`, `patch`, `archive`, `delete`, `transferOwnership`. Each follows the precedence in spec §6: authorize → replay check (values identical → return current, no bumps) → version check under the lock (`UPDATE … WHERE id = ? AND version = ?`, zero rows = conflict) → state rules (`type` immutable, `currency` locked when `PostingTotals::hasAny`) → write, recompute balance, stamp `change_seq`, bump `version` → dispatch events after commit. A conflict raises `WalletVersionConflict` carrying the present wallet rendered as a read renders it (the `SaveProfile` pattern, with a presenter closure), which the runtime renders as `409 VERSION_CONFLICT` with `current`.

PATCH reuses `save` by overlaying the sent members on the stored wallet and requires `version` ≥ 1; it never creates.

**Rationale**: matches spec 008's proven `SaveProfile` flow and conventions' "Every outcome" table. A conditional `UPDATE` guarded by `version` cannot lose an update even if the lock were forgotten.

**Alternatives**: `If-Match`/ETag — rejected: not in the header inventory (checklist G2) and the profile contract already carries `version` in the body, which the C4 client mirrors.

## R-5 DELETE and archive carry `version` in a JSON body

**Decision**: `DELETE /wallets/{id}` and `POST /wallets/{id}/archive` take `{ "version": n }`. Delete of an already tombstoned wallet returns the tombstone (200) for a former member regardless of version; archive of an archived wallet returns it unchanged.

**Rationale**: every write carries the last-seen version (P-07 rule 2), and the header inventory has no `If-Match`. Idempotent-by-outcome means a retried delete or archive after a lost response is safe without an `Idempotency-Key`, which conventions reserve for three money operations.

**Alternatives**: `?version=` query parameter — rejected, the conventions keep query parameters to the collection grammar; `204` on delete — rejected, the client needs the tombstone's new `version` and `change_seq`.

**Risk**: some HTTP clients drop a DELETE body by default; the C4 client must send it explicitly (Ktor allows it; Retrofit needs `@HTTP(method = "DELETE", hasBody = true)`). Recorded in the contract.

## R-6 The permission matrix as code: a pure domain table

**Decision**: `Wallets\Domain\Membership\Role` (enum `owner|editor|viewer`) and `Wallets\Domain\Membership\Permission` (enum, one case per matrix row ID: `W01ReadWallet` … `S05Leave`), with `PermissionMatrix::allows(Role, Permission): bool` and `PermissionMatrix::capabilityFor(Permission): ?Feature`. `Wallets\Application\Authorization\WalletAuthorizer::authorize(string $userId, string $walletId, Permission): Role` resolves the caller's active membership, then:

- no membership → `WalletNotFound` (404);
- role not allowed → `WalletActionForbidden` (403 FORBIDDEN);
- capability named and `EntitlementService::forWallet($walletId)` denies → `FeatureNotEntitled` (403).

Later specs call the authorizer with their row's `Permission` case and never compare a role. The Unit test asserts the domain table equals spec §6 row by row; the Feature test drives every row through the authorizer as owner, editor, viewer and non-member. The authorizer is the "policy for the resource" CONTRIBUTING item 7 asks for; a Laravel `Policy` class is not used because there is no Eloquent model the HTTP layer may hold (P-02).

**Rationale**: P-08 rule 5 forbids copying the matrix into code comments; encoding it once as data with a test that compares it to the spec keeps one source and catches drift.

## R-7 Entitlements by wallet, and `wallets.max`

**Decision**: `Wallets\Application\MembershipQueryService` (constitution name) gets `ownerOf(walletId): ?string`, `roleOf(userId, walletId): ?Role`, `activeMembersOf(walletId)`. Billing's `EntitlementService` gains `forWallet(string $walletId): Entitlements`, resolving `ownerOf` and delegating to `forUser` (P-09 rule 4, spec 009 FR-008). On create, `WalletService` asks `EntitlementService::forUser($creator)->quota(Feature::WalletsMax)`; `null` is unlimited, otherwise the creator's non-tombstoned owned wallets (archived included) must be below it, else `FeatureNotEntitled`.

**Rationale**: P-09 rule 2 (every capability through the service). All launch plans hold `wallets.max: null`, so the branch never refuses today but is data-driven.

**Note**: Billing → Wallets (owner lookup) and Wallets → Billing (entitlements) are both Application-to-Application calls, allowed by P-02 rule 4. The container resolves them lazily, so the mutual dependency does not loop at construction.

## R-8 Display names in the member list

**Decision**: Identity gains `Identity\Application\Profiles\DisplayNames::of(list<string> $userIds): array<string, ?string>`, opening each sealed display name with the member's own key, for the response only. Wallets calls it from its query service. No address and no other profile member is exposed.

**Rationale**: the security baseline lets other members of a shared wallet read the display name; reading Identity's tables from Wallets would break P-02 rule 3.

## R-9 Deletion cascade through a participant hook

**Decision**: `Wallets\Application\Deletion\WalletDeletionParticipant` (interface: `tombstoneWithin(string $walletId, DateTimeImmutable $at, Closure $nextChangeSeq): void`). `WalletService::delete` runs, in one transaction under the wallet lock: tombstone memberships (B4's own participant), every tagged participant (B6 transactions, B7 rules and occurrences, B9 invitations register theirs), then the wallet itself. Each record gets `deleted_at`, `version + 1` and a fresh `change_seq`. `WalletDeleted` is dispatched after commit for notifications only.

**Rationale**: FR-010 needs one unit of work; a queued listener would leave invitations live after the wallet is gone. Calling into Sharing's Application layer through an interface Wallets owns keeps P-02.

## R-10 Ownership transfer and its audit entry

**Decision**: `WalletService::transferOwnership(actor, walletId, targetUserId, version)`: authorize W-05; if the target is already the owner, return the wallet (no bumps); version check; target must be an active member other than the actor, else `ValidationFailed` with `INVALID_ENUM_VALUE` at `/user_id`; then in one transaction: actor's membership → `editor`, target's → `owner` (each `version + 1`, new `change_seq`), wallet `version + 1` and new `change_seq`, one `role_changed` audit entry (actor user, subject wallet, detail `{previous_owner_id, new_owner_id}`) through `Identity\Application\Audit\AuditLog`. The partial unique index on the owner role is checked at statement end, so the demotion runs before the promotion.

The security baseline's audit table moves `membership_granted`, `membership_revoked` and `role_changed` from "Sharing" to "Wallets" as writer, because every membership change goes through `WalletService`. Identity's `AuditEvent` already has the three cases; Identity gains a `RoleChangedDetail`.

**Rationale**: exactly one entry per act, one writer per event (baseline rules). Reusing `INVALID_ENUM_VALUE` avoids a new catalogue code: the allowed set of `user_id` values is the wallet's other active members.

## R-11 The verification gate on every write route

**Decision**: write routes (`PUT`, `PATCH`, `DELETE /wallets/{id}`, `POST …/archive`, `POST …/transfer-ownership`) use `auth:api` and `address.verified` (Identity's `EnsureAddressVerified`, referenced by alias so Wallets never imports Identity's Http namespace). Reads use `auth:api` only.

**Rationale**: security baseline Verification gate and checklist C1–C2. The gate is independent of the wallet, so answering it before membership discloses nothing.

## R-12 No rate limit, no new error code, info.version v1.3

**Decision**: no throttle on wallet routes (as profile and billing reads); `429` not documented. No catalogue entry is added. `openapi.yaml` `info.version` becomes `v1.3` because five paths are added (CONTRIBUTING item 6).

## R-13 The `UserFactory` fails outside a test transaction

**Finding** (from the C4 client session): `UserFactory::addressAttributes()` creates the `user_keys` row (via `AttributeCipher::newDataKeyFor`) while computing `email_ciphertext`, i.e. **before** the `users` row is inserted. `user_keys_user_id_foreign` is `DEFERRABLE INITIALLY DEFERRED`, which is why tests pass: `RefreshDatabase` wraps each test in a transaction, so the check runs at its end, after the user exists. Outside a transaction (QA seeding, `dev tinker`, a seeder) each statement autocommits and the key insert fails at once. Production registration is unaffected — it already runs in one transaction. B4's QA seeding (a second member, a transfer) needs factories that work outside tests.

**Decision**: a small, separate task makes the factory's create path run the key insert and the user insert in one `DB::transaction` (for example by overriding `create()`/using `afterMaking` to open one), so the deferred FK is checked at that transaction's commit. Acceptance: a test that disables the wrapping transaction (or uses a fresh connection) and creates a user through the factory without error. The exact approach is the implementer's.
