# Contract: The Audit Entry

Normative. One shape, one fixed vocabulary, one hard exclusion list.

## Ownership

**Identity owns the audit log** and exposes an application service every other module calls. Admin keeps the `AdminAuditService` name the constitution gives it, as its own façade for operator actions, writing through Identity's service rather than to a second store.

This is a cross-module write through an application service, which is one of the two mechanisms P-02 rule 4 permits. A shared store in `Shared` is not an option, because P-02 rule 5 keeps `Shared` free of persistence. A tenth bounded context is not an option either, because the constitution fixes nine and an amendment is a high price for a table.

## The entry

| Field | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `occurred_at` | instant | UTC |
| `event` | enum | from the vocabulary below, and nothing else |
| `actor_type` | enum | `user` \| `operator` \| `system` |
| `actor_id` | uuid, nullable | null only for `system` |
| `subject_type` | enum | `user` \| `wallet` \| `session` \| `subscription` |
| `subject_id` | uuid | |
| `request_id` | uuid | correlates the entry with the server's logs |
| `origin` | string, nullable | coarse origin of the request |
| `detail` | object | structured, constrained by the exclusion list |

## The vocabulary

Fixed, so that "log security events" becomes something a reviewer can check:

| Group | Events |
|---|---|
| Access | `sign_in_succeeded`, `sign_in_failed`, `lockout_applied` |
| Sessions | `session_issued`, `session_revoked` |
| Credentials | `password_changed`, `address_change_requested`, `address_verified` |
| Sharing | `membership_granted`, `membership_revoked`, `role_changed` |
| Billing | `entitlement_changed` |
| Departure | `export_requested`, `export_delivered`, `deletion_requested`, `deletion_cancelled`, `deletion_completed` |
| Privileged | `admin_action`, `audit_read` |

Adding an event means adding it here. A module does not invent a local name.

## What must never be inside an entry

- passwords, and anything derived from one
- session credentials and any token
- one-time codes
- amounts and balances
- notes
- receipt contents
- a readable address

The subject is referenced by identifier. **An entry names what happened, never what it was worth.**

This list exists because a record built to answer security questions becomes the single best thing to steal the moment it copies what it describes.

## Rules

1. Append-only. An entry is never edited, and never deleted before its retention expires.
2. Retention is 400 days — long enough that a person disputing something from this time last year is inside the window, with room for the investigation.
3. Readable by an operator only.
4. **Reading is itself an event.** Every read produces an `audit_read` entry, so the richest target in the system cannot be browsed unobserved.
5. `actor_type` is what satisfies FR-022: it distinguishes a user acting for themselves from an operator acting on their behalf.
6. Every event in the vocabulary has exactly one module that writes it, named in the baseline, so no event arrives at implementation without an owner.

## Reviewer checks

- [ ] Every act in the vocabulary produces exactly one entry.
- [ ] No entry contains anything on the exclusion list.
- [ ] The actor and the subject are distinguishable, and an operator acting for a user is distinguishable from that user acting alone.
- [ ] The request correlation is present, so the entry joins the server's logs.
- [ ] Reading the log produces an entry.
- [ ] No module writes to a second audit store.
