# ADR-0006: Wallet-scoped authorization

- **Status**: Accepted
- **Date**: 2026-09-19
- **Principles**: P-08
- **Supersedes**: —
- **Resolves**: —

## Context

Every resource that matters in this application hangs off a wallet: transactions, categories, budgets, recurring rules and the membership rows themselves. A wallet can be shared, and the members of one wallet have no relationship to the members of another. Two people can therefore hold different roles over different wallets at the same time, and a single request — a transfer — can touch two wallets with two different roles.

Some capabilities are additionally plan-gated (see [ADR-0007](0007-entitlements-from-subscriptions.md)), so "may this caller do this?" is two questions, not one: does their membership role permit it, and does the governing subscription include it.

Authorization also leaks information. A wallet ID is a UUIDv7 ([ADR-0002](0002-identifiers-uuidv7.md)) and is not secret once it has been shared in an invite link or a support thread. A response that distinguishes "this wallet exists but is not yours" from "no such wallet" turns any ID into an existence oracle.

Finally, the platform needs operators who can act across wallets for support and moderation. That is a fundamentally different authority from a wallet role, and it must not be reachable from an ordinary app session token.

The concrete role-by-action permission matrix is product surface, not architecture. It changes when features change, and duplicating it here or in the constitution guarantees two copies that drift.

## Decision

1. Authorization MUST be wallet-scoped. A Laravel policy resolves the caller's `wallet_members.role` — `owner`, `editor` or `viewer` — for the specific wallet the resource belongs to, and decides from that role.
2. Policies MUST read membership through the Wallets module's application service. They MUST NOT query another module's Eloquent models or join `wallet_members` directly from outside Wallets.
3. Where a capability is plan-gated, the policy MUST combine the role check with an entitlement check via `EntitlementService` (see [ADR-0007](0007-entitlements-from-subscriptions.md)). Both MUST pass; neither substitutes for the other.
4. A transaction write MUST authorize **every** wallet it posts to, not only a primary or first wallet. A transfer with postings in wallet A and wallet B is permitted only if the caller may write to A **and** to B. A member who may edit A but only view B MUST be denied the whole write.
5. A non-member MUST receive **404** for any wallet-scoped resource — reads and writes alike — so that wallet existence is never disclosed. 403 MUST NOT be used to mean "not a member of this wallet".
6. 403 remains correct for a caller who **is** a member but whose role or entitlement does not permit the action, because membership already discloses existence.
7. The only global role is platform admin. It MUST use a separate `admin` guard, with admin accounts owned by the Admin module and stored apart from application users.
8. There MUST NOT be a `role`, `is_admin` or equivalent global-authority column on application users. An application user token MUST NOT be able to reach an admin-guarded route.
9. Admin actions MUST go through other modules' application services, so the same invariants and domain events apply as for a normal user action.
10. The role × action permission matrix MUST live only in the Wallets specification. It MUST NOT be copied into this ADR, into the constitution, or into any other feature spec; those documents reference it.

## Alternatives Considered

### A global RBAC package (e.g. `spatie/laravel-permission`)

A mature package with roles, permissions and caching, assigned to users. **Rejected because** its model is global: a user "is an editor", not "is an editor of wallet 7". Making it wallet-scoped means bending it into team/tenant mode and still writing the per-wallet resolution by hand, so the package adds a dependency and a second vocabulary without removing the work. Wallet roles are three values on a table this application already owns.

### Returning 403 for non-members

The more literal HTTP reading: the resource exists, the caller is not permitted. **Rejected because** it discloses existence. Anyone holding or guessing a wallet ID — from an old invite, a shared screenshot, a log line — can separate real wallets from invented ones, and enumerate which wallets a leaked ID set refers to. 404 costs nothing and removes the oracle.

### A `users.is_admin` flag

One boolean on the application users table, checked by a middleware or gate. **Rejected because** it puts global authority inside the same account and the same token as ordinary app usage. One leaked or stolen app session token becomes full platform access, there is no separate credential to rotate, and every future authorization check has to remember the flag exists. A separate guard keeps the blast radius of an app token to that app user's wallets.

## Consequences

### Positive

- Wallet existence cannot be probed: an ID alone yields nothing.
- Transfers cannot be used to write into a wallet the caller may only read, which is the one place where a single request spans two authorization scopes.
- The permission matrix has exactly one home, so a product change to roles is a one-file change and cannot leave a stale copy behind.
- Compromising an app session can never yield platform admin, because the admin guard's credentials are separate.
- Plan gating and role gating stay independent and composable; adding a plan-gated capability does not touch the role model.

### Negative

- Returning 404 instead of 403 makes "I lost access to a wallet I was in" indistinguishable from "that wallet never existed" in client error handling. The client learns about removal from the membership revocation in the user sync feed ([ADR-0008](0008-sync-changes-feed.md)), not from the 404.
- Multi-wallet writes need every wallet resolved before the write is authorized, which is more membership lookups per transfer than a single-scope check.
- The separate `admin` guard means a second authentication path, its own session handling and its own tests.
- Reading the permission matrix requires opening the Wallets spec; it is deliberately not answerable from this document.

### Follow-ups

- The Wallets feature owns and maintains the role × action matrix this ADR defers to; it must exist before the first plan-gated wallet action ships.
- A feature test per wallet-scoped endpoint asserting 404 (not 403) for a non-member, including the transfer case where the caller is a member of only one of the two wallets.
- Revisit if a wallet ever needs a role beyond owner/editor/viewer, or if a resource appears that is genuinely not wallet-scoped and not user-scoped.
