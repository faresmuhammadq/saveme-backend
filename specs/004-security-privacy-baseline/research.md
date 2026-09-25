# Phase 0 Research: Security & Privacy Baseline

**Feature**: `004-security-privacy-baseline` | **Date**: 2026-09-22 | **Spec**: [spec.md](./spec.md)

Every decision below either closes a NEEDS CLARIFICATION in the plan's Technical Context or fixes a number the spec deliberately left as "the contract MUST define". Each is written so a reviewer can check one endpoint, one stored attribute or one recorded entry against it.

Inputs consulted: constitution v1.1.0, ADR-0001 (PostgreSQL), ADR-0002 (identifiers), ADR-0006 (wallet-scoped authorization), ADR-0008 (sync feed and the tombstone horizon), ADR-0010 (observability and the never-log list), ADR-0011 (time), [`docs/api-conventions.md`](../../docs/api-conventions.md) and its [error catalogue](../../docs/openapi/error-catalog.yaml), and [`docs/glossary.md`](../../docs/glossary.md).

---

## R-1 — Deliverables and where they live

**Decision**: three outputs.

| Output | Path | Audience |
|---|---|---|
| The security baseline | `docs/security-baseline.md` | feature authors, reviewers |
| The privacy commitments | `docs/privacy-commitments.md` | users, the store listing, the public page |
| Ten reserved error codes | `docs/openapi/error-catalog.yaml` | clients |

Both documents sit beside `docs/glossary.md` and `docs/api-conventions.md`, for the same reason those do: the Android and landing-page teams read `docs/` and have no reason to open a spec folder.

**Rationale**: the commitments are a separate file rather than a section because they have a different audience, a different tone and a different lifecycle — a public page is republished when the copy changes, not when a rule is added. Keeping them apart also makes the one-to-one mapping in FR-036 auditable: a reviewer diffs one file against one table.

**Alternatives rejected**: *one combined document* — the audience split is real, and a user-facing page that opens with argon2 parameters helps nobody.

---

## R-2 — FR-016 contradicted ADR-0010, and the spec was narrowed

**Decision**: the absolute never-log list covers passwords, tokens, session identifiers, one-time codes, purchase tokens, receipt images and the full body of a financial write. Amounts and record identifiers are permitted at **debug level only**, are barred from informational level and above, and never leave the service in an error report or crash payload.

**Rationale**: the spec's first draft banned amounts from "any log", which contradicts ADR-0010 rule 8, an Accepted decision that deliberately keeps debug-level amounts for diagnosing a balance complaint. The constitution's governance is explicit that an Accepted decision is superseded by a new decision record, never by a spec that talks over it. Narrowing the requirement costs nothing real — the backlog's actual concern was request bodies, which rule 7 already bans — and keeps the diagnostic capability someone will need the first time a user says their transfer vanished.

**Alternatives rejected**: *a new ADR superseding rule 8*. Rejected: it would remove a capability to satisfy a sentence written more absolutely than it was meant, and a debug-level line that never reaches production logs is not the leak the rule is protecting against.

---

## R-3 — Session credentials

**Decision**: one opaque bearer credential per device session.

| Property | Value |
|---|---|
| Form | opaque random string, at least 256 bits of entropy |
| Stored as | a hash; the readable value exists only in the response that issues it |
| Expiry | 90 days of **inactivity**, extended on each successful use |
| Absolute cap | none in MVP |
| Revocation | immediate, checked on every request against stored state |
| Scope | one session; revoking one leaves the others working |

There is no positive cache of "this token is valid". A revoked credential stops working on the next request, with no window.

**Rationale**: the spec requires revocation to take effect immediately, and any cache measured in minutes turns "I ended that session" into a promise the product does not keep. A sliding window rather than a fixed one matches how the app is used — daily for a while, then not for a month — and still cuts off a device that was lost and forgotten.

**Alternatives rejected**:
- *Self-contained signed tokens*. Rejected: they cannot be revoked without a revocation list, which is the state we were trying to avoid, plus a window during which a cut-off device still works.
- *An absolute cap alongside the sliding window*. Rejected for MVP: it forces a re-login on a schedule the user cannot predict, for a threat the sliding window already handles.

---

## R-4 — Password storage

**Decision**: argon2id, with `memory = 65536 KiB`, `iterations = 4`, `parallelism = 1`. Parameters are re-measured on the target hardware and raised until a single hash costs between 250 ms and 500 ms. A password is rehashed on the next successful sign-in whenever the stored parameters are below the current setting.

**Rationale**: argon2id is memory-hard, which is the property that makes a stolen hash expensive to attack on rented graphics hardware. The numbers are above the current OWASP minimum and match the framework default, so the first implementation is not also a tuning exercise. Rehash-on-login is what makes raising the cost later a config change rather than a migration nobody runs.

**Alternatives rejected**: *bcrypt* — still acceptable, but its memory cost is fixed and low, which is the whole reason argon2id exists.

---

## R-5 — Password and address changes

**Decision**: a successful password change ends **every other** session and keeps the session that made the change. An address change requires the new address to be verified before it takes effect, and the old address is notified. Both are recorded.

**Rationale**: ending other sessions is the user's one lever when they suspect someone else is in the account, and keeping the current one means using that lever does not sign them out of the device they are holding. Notifying the old address is what makes an attacker-initiated address change visible to the person losing the account.

---

## R-6 — Reset and verification tokens

**Decision**:

| Token | Lifetime | Single use | Also invalidated by |
|---|---|---|---|
| Password reset | 60 minutes | yes | any password change, any later reset request |
| Address verification | 24 hours | yes | a later verification request for the same address |

Both are issued as opaque random values and stored hashed. Resends are throttled to three per hour per address.

**Rationale**: an hour is long enough to find the mail and short enough that a forwarded link is usually dead. Invalidating earlier reset tokens when a new one is requested closes the case where an attacker requests a reset, the user requests their own, and two live links exist.

---

## R-7 — A lockout rule that resists guessing without becoming a weapon

**Decision**: two independent limits, and a way out that the attacker does not control.

| Limit | Trigger | Effect |
|---|---|---|
| Per account | 5 consecutive failures | refuse for 15 minutes |
| Per account, escalated | 10 consecutive failures | refuse for 60 minutes |
| Per origin address | 20 failures across any accounts in 15 minutes | refuse that origin for 15 minutes |

The account lock **caps at 60 minutes** and is never permanent. A successful password reset, which requires access to the mailbox, clears the lock immediately. A successful sign-in resets the counter.

**Rationale**: the edge case the spec names is real — an attacker who knows an address can otherwise lock its owner out at will. Capping the lock and letting a verified mailbox clear it means an attacker can annoy, but cannot deny. The per-origin limit is what actually stops credential stuffing, which spreads across many accounts and would never trip a per-account counter.

**Alternatives rejected**:
- *Permanent lock until support intervenes*. Rejected: it hands the attacker an off switch and hands the product a support queue.
- *Per-origin limiting alone*. Rejected: a distributed attempt against one account never trips it.

---

## R-8 — Uniform answers, so an address cannot be probed

**Decision**: sign-in, password reset, registration and invitation return the **same outcome for a known and an unknown address**.

| Surface | Answer, whether or not the address exists |
|---|---|
| Sign-in | `401 INVALID_CREDENTIALS` |
| Password reset request | `202`, "if that address is registered, a link is on its way" |
| Registration | `202`, followed by a mail that differs by case: welcome, or "you already have an account" |
| Invitation | `202`, with no indication of whether the invitee is registered |

Response timing is equalised so a wrong password and an unknown address take comparable time.

**Rationale**: three of these surfaces answering uniformly and the fourth leaking makes the other three pointless. Moving the difference into the mail body means the person who owns the address learns what happened and nobody else does.

**Alternatives rejected**: *telling registration honestly that the address is taken*. Rejected: it is the single easiest way to test a list of addresses against this service, and the mail path costs the real user one extra step.

---

## R-9 — The verification gate

**Decision**: an unverified person may read, manage their own profile, and manage their own sessions. They may **not** write to any wallet. The refusal is `403 EMAIL_NOT_VERIFIED`, its own code, so the app offers to resend rather than showing a generic denial.

**Rationale**: the spec fixes the gate at writes. Letting an unverified invitee read is what makes an invitation worth sending — they can see what they were invited to before committing. Barring writes is what stops a typo'd or hostile address from putting entries into someone's shared wallet.

---

## R-10 — What is protected at rest, attribute by attribute

**Decision**: a table in the baseline, with no unqualified claim anywhere.

| Attribute | At rest | Why |
|---|---|---|
| Address | encrypted, plus a keyed lookup value (R-11) | most identifying attribute a person has here |
| Display name | encrypted | names a person and often their household |
| Password | argon2id hash | never recoverable, by design |
| Session credential | hash | same |
| Reset and verification tokens | hash | same |
| Amounts, balances, notes | **not** encrypted at rest | needed for arithmetic, aggregation and indexing; protected by scope, transport and access control instead |
| Category and wallet names | not encrypted | same |
| Audit entries | not encrypted, and carry nothing protected (R-18) | |

**Rationale**: FR-015 exists because "everything is encrypted" is the claim that collapses under one question. Encrypting amounts would break every balance, every budget total and every report, and would buy little: an attacker who reaches the database also reaches the application that must decrypt them. Saying exactly what is and is not protected is both more honest and more defensible than a slogan.

Encryption is authenticated, with a per-record nonce and a key identifier stored beside the value so rotation is possible (R-12).

---

## R-11 — The keyed lookup value

**Decision**: alongside the encrypted address, store `HMAC-SHA256(normalised_address, lookup_key)`, unique-indexed. Every lookup — sign-in, "is this address registered", invitation matching — uses that value and never a readable address.

Normalisation, applied identically everywhere the value is produced:

1. trim surrounding whitespace;
2. Unicode NFKC;
3. lowercase the whole address.

No provider-specific munging: gmail dots and plus-tags are **not** stripped, because `a.b@` and `ab@` are different addresses to anyone who is not Google, and guessing otherwise silently merges two people.

The lookup key is separate from the encryption key and is never stored beside the data it protects.

**Rationale**: this is the mechanism the clarification chose, and it keeps every product flow working. Its limit is stated rather than hidden: someone holding the stored data alone learns nothing, and someone holding the data *and* the lookup key can confirm an address they already guessed, though not enumerate the addresses that exist.

**Alternatives rejected**: *an unkeyed hash*. Rejected: addresses have low enough entropy that an unkeyed hash of a common address is a lookup table away from plaintext.

---

## R-12 — Keys and rotation

**Decision**: every encrypted value stores the identifier of the key that produced it. Rotation adds a new key, writes new values with it, and keeps the old key readable until a re-encryption pass finishes. Nobody is asked to register again, and no value is unreadable mid-rotation.

Rotating the **lookup** key is the harder case, because the value is an index: rotation writes the new value into a second column, backfills, swaps the unique index, then drops the old column. The baseline states this sequence so it is a planned operation rather than an outage.

Keys live outside the database, in the deployment's secret store.

**Rationale**: a key that cannot be rotated is one incident away from being permanent. Storing the key identifier per record is what makes a partial rotation a valid state rather than a corrupted one.

---

## R-13 — What "irreversibly removed" means

**Decision**: purging an account deletes the rows the person alone owns, and then destroys the per-account key material that would decrypt anything encrypted with it. The commitments claim removal of the person's identity attributes and their own records — not that every byte they ever caused to exist is gone, because a shared wallet's history is not theirs alone to delete.

**Rationale**: deletion plus key destruction means a backup restored after the purge still yields nothing readable for that person, which row deletion alone does not give. Claiming more than that would be a promise the shared-wallet requirement contradicts on the same page.

**Alternatives rejected**: *key destruction alone*. Rejected: it leaves rows that look like data, and a future key-management mistake turns a completed deletion back into a disclosure.

---

## R-14 — Every retention period, as a number

**Decision**: one table, no "reasonable time" anywhere.

| What | Kept for | Fixed by |
|---|---|---|
| Deleted syncable record, content and marker | 90 days | ADR-0008, the tombstone horizon |
| Other soft-deleted records | 30 days | this feature |
| Account after a deletion request | 30 days, then purged | this feature |
| Audit entries | 400 days | this feature |
| Application logs | 30 days | ADR-0010 |
| Idempotency records | 24 hours | spec 003 |
| Reset token | 60 minutes | R-6 |
| Verification token | 24 hours | R-6 |
| Provider message identifiers, for replay detection | 24 hours | R-20 |

The published commitments state **90 days** for anything that syncs, which is the longer number, per the clarification.

**Rationale**: 400 days for audit rather than 365 so that a person disputing something from "this time last year" is still inside the window, with room for the investigation. Every other number is either already fixed by a decision record or set here once.

---

## R-15 — Departing a shared wallet

**Decision**: when an account is purged, the wallets it owns and the records it alone owns go with it. In a wallet owned by someone else, the transactions the departing person authored **stay**, because they are the other members' history too and removing them would silently change balances. The author reference becomes a stable anonymous marker — "a former member" — that cannot be resolved back to a person.

**Rationale**: this is the case where privacy and correctness pull in opposite directions, and correctness has to win for the money while privacy wins for the identity. Deleting the transactions would change another person's balances without their knowledge, which is the worse outcome by a distance.

**Open consequence, named rather than hidden**: a revoked or departed member's phone still holds a local copy of what it last synced. The baseline states that the contract cannot reach that copy, rather than implying a remote wipe that does not exist.

---

## R-16 — Export

**Decision**: an export is produced asynchronously and delivered as an archive containing complete structured data plus a spreadsheet-openable file of transactions. It contains what the person entered and what they can already see, not other members' records beyond that.

An export requested during the deletion grace period is served, and **does not** reset the grace clock. A second request while one is in progress is `409 EXPORT_IN_PROGRESS`.

**Rationale**: "a form they can open without this app" means a spreadsheet for the part people actually want to keep, and structured data for completeness. Serving an export during the grace period is the point at which the promise is tested; refusing it strands someone who is leaving, and resetting the clock would let a scheduled export postpone a deletion forever.

---

## R-17 — Who owns the audit log

**Decision**: **Identity owns it** and exposes an application service that other modules call. Admin keeps the `AdminAuditService` name the constitution gives it, as its own façade for operator actions, writing through Identity's service rather than to a second store.

**Rationale**: the constitution fixes nine contexts, and inventing a tenth for this would need an amendment for a log. Identity already owns users, credentials and sessions, which is where most recorded events originate. A shared store in `Shared` is not an option — P-02 rule 5 keeps `Shared` free of persistence. Cross-module writes through an application service are exactly the mechanism P-02 rule 4 permits.

**Alternatives rejected**:
- *A log per module*. Rejected: "who removed me from this wallet" then needs three queries and a merge, and the answer depends on which module remembered to record.
- *A tenth bounded context*. Rejected: a constitution amendment is a high price for a table, and Identity is a defensible owner.

---

## R-18 — The audit entry, and what must never be in it

**Decision**: one shape, one fixed vocabulary of events, and a hard exclusion list. An entry names the actor, the subject, the act, the time and the request correlation — and nothing about value.

Recorded: sign-in success, sign-in failure, lockout applied, session issued, session revoked, password changed, address change requested, address verified, membership granted, membership revoked, role changed, entitlement changed, export requested, export delivered, deletion requested, deletion cancelled, deletion completed, admin action, audit read.

Never inside an entry: passwords, tokens, one-time codes, amounts, notes, receipt contents, or the readable address. The subject is referenced by identifier.

Entries are append-only, kept 400 days, readable by an operator only — and **reading them is itself an event**, so the richest target in the system cannot be browsed unobserved.

**Rationale**: a record built to answer security questions becomes the best thing to steal the moment it copies what it describes. Fixing the event vocabulary is what makes FR-019 checkable; "log security events" is not.

---

## R-19 — The operator's second factor

**Decision**: time-based one-time codes, six digits, 30-second step, one step of drift tolerated, plus ten single-use recovery codes issued at enrolment and shown once. Enrolment is mandatory before an operator account can do anything. Losing the device is recovered with a recovery code; exhausting those requires **two other operators** to approve a re-enrolment, and both approvals are recorded.

**Rationale**: the spec forbids recovery from reducing to "ask someone to turn it off", and two-operator approval is the smallest rule that satisfies it without a support process nobody staffs. Time-based codes need no messaging channel, which matters because the messaging channel is often the thing an attacker already owns.

---

## R-20 — Messages from the payment provider

**Decision**: a provider message is rejected unless **all** of these hold: the signature verifies against the provider's current public keys; the issuer is the expected one; the audience names this service and not another; the issued-at time is within five minutes of now; and the message identifier has not been seen in the last 24 hours.

A message that has been seen before is **acknowledged without effect**, so the provider stops retrying, rather than refused — a refusal makes a well-behaved provider retry forever.

When key material cannot be fetched, messages are **rejected and left for the provider to retry**. They are never accepted unverified, and never queued for later trust.

**Rationale**: the audience check is the one people skip, and it is what stops a validly signed message meant for another service from granting a subscription here. Distinguishing "already processed" from "invalid" is the difference between a quiet system and a retry storm.

---

## R-21 — Transport

**Decision**: TLS 1.2 or better for every request. A plaintext request to an API path is refused with `400 INSECURE_TRANSPORT` rather than redirected, since a redirect teaches a client that plaintext is a working first step and the credential has already been sent by then. Responses carry a strict-transport-security header with a one-year duration.

**Rationale**: redirecting an API request is a convenience for browsers and a hazard for clients holding bearer credentials.

---

## R-22 — Ten error codes reserved in the shared catalogue

**Decision**: the baseline adds ten codes to `docs/openapi/error-catalog.yaml` now, even though no endpoint returns them yet.

| Code | Status | Retryable |
|---|---|---|
| `INVALID_CREDENTIALS` | 401 | never |
| `ACCOUNT_LOCKED` | 423 | automatic, after `Retry-After` |
| `EMAIL_NOT_VERIFIED` | 403 | never |
| `VERIFICATION_TOKEN_INVALID` | 400 | after-change |
| `RESET_TOKEN_INVALID` | 400 | after-change |
| `TWO_FACTOR_REQUIRED` | 401 | after-change |
| `TWO_FACTOR_INVALID` | 401 | after-change |
| `INSECURE_TRANSPORT` | 400 | never |
| `PROVIDER_SIGNATURE_INVALID` | 401 | never |
| `EXPORT_IN_PROGRESS` | 409 | automatic |

The catalogue goes from 38 codes to 48. `UNAUTHENTICATED` already covers a revoked or expired session, so no code is added for that.

`423` rather than `429` for a lockout because the catalogue requires one status per code, and a client must be able to tell "you are throttled" from "this account is locked", which need different words on screen.

**Rationale**: spec 003 makes the catalogue the one place a code may come from, and reserving them here stops Identity and Billing inventing two names for the same refusal months apart. A code with no endpoint costs one enum entry.

---

## R-23 — The privacy commitments

**Decision**: `docs/privacy-commitments.md` holds the published copy in Arabic and English side by side, in short plain sentences, organised as: what is collected, what is protected and how, who can see a shared wallet, how long things are kept, and how to leave. Every commitment carries the identifier of the requirement that backs it, in a mapping table at the end of the file.

**Rationale**: FR-036 makes a promise without a backing rule a defect, which is only checkable if the mapping is written down. Keeping both languages in one file means a copy change cannot land in one language and not the other, which is the common failure.

---

## R-24 — No dependency is added here

**Decision**: this feature adds nothing to `composer.json`. The authentication package the backlog names is Identity's decision and Identity's approval request. The baseline describes required behaviour, not the library that provides it.

**Rationale**: the constitution's quality gates require the repository owner's approval for a new dependency, and asking for one to write two documents would be asking early and for the wrong feature.

---

## R-25 — How the baseline is checked

**Decision**: [`contracts/security-review-checklist.md`](./contracts/security-review-checklist.md) is the FR-038a mechanism — yes-or-no checks a reviewer applies to one endpoint, one stored attribute or one recorded entry. No scanner, no linter, no new tool.

**Rationale**: the same reasoning as spec 003's endpoint checklist. Every candidate tool is a dependency, and the requirement is that a human can check one thing at a time.

---

## Open items carried forward (not blocking)

| Item | Owner | Why not here |
|---|---|---|
| The authentication package and its approval | Identity feature | R-24 |
| Where operator accounts are stored and how they are created | Admin feature | the baseline fixes the second factor, not the account lifecycle |
| The wallet role × action permission matrix | Wallets spec | P-08 rule 5 keeps it in exactly one place |
| The purge job's schedule and failure handling | Sync feature | the baseline fixes the horizons, not the job |
| Breach notification and penetration-testing cadence | operations | excluded by the spec's assumptions |
