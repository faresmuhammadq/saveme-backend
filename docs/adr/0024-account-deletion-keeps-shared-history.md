# ADR-0024: Account deletion keeps shared history; the departed id is the marker

- **Status**: Accepted
- **Date**: 2026-10-06
- **Principles**: P-07, P-08, P-11
- **Supersedes**: [ADR-0008](0008-sync-changes-feed.md) rule 12 only, and only for the user scope of a purged account; every other rule of ADR-0008 stands (extends [ADR-0017](0017-membership-on-the-members-user-feed.md) and [ADR-0021](0021-scope-heads-and-member-name-restamps.md))
- **Resolves**: brief B15, owner decision of 2026-09-25 (ownership transfer on deletion) (spec 022)

## Context

**Ratification.** Pending owner ratification: written under delegated authority. Status is Accepted so that spec 022 can proceed; the owner may amend or reject it, and the spec's tasks then change with it.

Spec 007 schedules an account for deletion and leaves the purge to a later feature. Spec 022 (B15) is that purge. A person who owns a wallet that other people use cannot take that wallet with them, and a person's name and address must not outlive the account.

Forces:

- Other members' history is theirs as much as the leaver's. Deleting a shared wallet destroys it for people who did nothing.
- Ledger rows are never rewritten after the fact (P-04, P-05). A rewrite of `created_by` would bump every transaction's version and flood every wallet feed.
- ADR-0021 rule 4: a display name that changes is re-sent to partners as `ProfileUpdated`. Erasing a name is a change of the same kind.
- ADR-0017 rule 5, lock order: categories, then wallets ascending, then users ascending. A purge that runs as one transaction would break it.
- ADR-0008 rule 12 keeps tombstones 90 days so a lagging reader can still learn of a delete. After the purge a user scope has no reader, so the rule protects nobody there.
- A transfer is an act the departing person is still entitled to perform until they leave (ADR-0006).

## Decision

1. An owned wallet with another active member MUST be transferred, never deleted. The successor is chosen in this order: the only other member, of any role; the member the person chose while the deletion was pending; the editor with the earliest membership; the viewer with the earliest membership. Earliest membership means the creation of the current membership record. A candidate whose own account is pending deletion MUST be skipped unless every candidate is pending.
2. Only a wallet with no other active member MAY be deleted, and only through the existing wallet delete.
3. A ledger row MUST NOT be rewritten by a purge. `created_by` and the other author ids keep the departed person's id. After the purge that id resolves to a husk with no attribute and later to nothing; clients show an author without a live membership as "Former member".
4. The display name MUST be erased through the profile save, publishing `ProfileUpdated` naming `display_name`, before any membership is tombstoned (ADR-0021 rule 4).
5. An account MUST pass through a `purging` state between `pending_deletion` and `purged`. A `purging` account cannot sign in, cannot cancel, and has no session. The purge resumes from its last completed step.
6. The purge MUST act through the existing services: transfer, delete, leave, revoke. Each owned wallet is its own unit of work and takes wallet then user locks in ascending order. The purge MUST NOT hold the user row lock while another module runs.
7. After the last step, the user row MUST remain as a husk: no address, no password, no data key, state `purged`, with a `purged_at`. The husk MUST be deleted 400 days after the purge.
8. The user scope of a purged account is the one place rule 12 of ADR-0008 does not hold. Its syncable records (budgets, categories without references, the profile) MUST be tombstoned and then removed at once, with the scope's purge horizon raised past them. Wallet-scope tombstones MUST still be kept 90 days.
9. Subscriptions that grant access (`manual`, `instapay`) MUST be ended at the purge. A Google Play subscription is not ours to cancel; the mail and the commitments say so.

## Alternatives Considered

### Transfer to the oldest editor only

**Rejected because** a wallet with viewers only would then be deleted under people who still read it.

### Delete the wallet when it has no editor

**Rejected because** it destroys other members' history on the strength of a role label.

### Replace `created_by` with a fixed sentinel

**Rejected because** it collapses every departed member into one and bumps the version of every transaction they wrote, flooding every wallet feed for no privacy gain over an identifier that resolves to nobody.

### Hard-delete the user row

**Rejected because** spec 007's `purged` state and the operator pages expect a retained row, and an operator could no longer answer "was this account deleted?".

### Act as a system actor that bypasses ownership rules

**Rejected because** it duplicates every authorisation path, and the person's own authority is valid until they leave.

### Keep user-scope tombstones for 90 days after the purge

**Rejected because** no reader of that scope remains, and the tombstones would carry record ids and timestamps of a person who asked to be forgotten.

## Consequences

### Positive

- Shared wallets survive the departure of an owner; nobody loses history they did not create.
- A purged person leaves no name, address or key; what stays is an identifier that points at nothing.
- A purge that stops halfway finishes correctly on the next run.

### Negative

- The purge is many short writes, not one transaction; a crash leaves an account in `purging` until the next run.
- A rejoined member's seniority restarts, which may pick a different successor than expected.
- The husk row keeps an id and two dates for 400 days.

### Follow-ups

- Revisit the 400-day husk against the audit horizon if either changes.
- If end-to-end encryption arrives (owner direction of 2026-09-25), the purge steps that read content change with it.
