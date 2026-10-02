# Security & Privacy Baseline

**Status**: Active. These are the security and privacy rules every feature in this backend obeys. Its readers are the people specifying Identity, Billing, Sharing, Sync and Admin, and the reviewers who check them.

**Companions**: [docs/glossary.md](glossary.md) names the entities. [docs/api-conventions.md](api-conventions.md) fixes the HTTP contract these rules sit inside. [docs/adr/](adr/README.md) records why each underlying decision was taken. The promises this document makes to users, in plain language, are in [docs/privacy-commitments.md](privacy-commitments.md).

---

## Scope, ownership and precedence

This document **states rules and builds nothing**. Every rule here is implemented by a later feature, and every rule names the feature that owns it, so none arrives at implementation without somebody responsible for it.

| Area | Owned by |
|---|---|
| Device sessions, passwords, reset and verification tokens, lockout, uniform answers, the verification gate | Identity |
| What is protected at rest, the address lookup value, keys and rotation | Identity |
| The audit log and its application service | Identity |
| Messages from the payment provider | Billing |
| Retention horizons and the purge that enforces them | Sync |
| Operator accounts, the second factor and its recovery | Admin |
| Transport, and what must never be logged or disclosed | every feature, checked per endpoint |

### Precedence

1. The [constitution](../.specify/memory/constitution.md) and any Accepted [decision record](adr/README.md) win over this document. Where they overlap and disagree, that is a defect to fix here, in the same change.
2. This document wins over a feature spec. A feature that needs an exception records it in its own plan; an exception nobody wrote down is a defect.
3. A rule that cannot be checked as yes or no against one endpoint, one stored attribute or one recorded entry is a defect in this document. Report it and fix it.

The reviewer's form of every rule below is in the [appendix](#appendix-security-review-checklist).

### What this document is not

It is not a threat model, not a compliance certification, and not an operations runbook. Breach notification and penetration-testing cadence are operational commitments made elsewhere. Second factors for application users are not in MVP; the second factor here is for operators only.

## Reserved error codes

Ten codes are reserved in [the one error catalogue](openapi/error-catalog.yaml) for the refusals these rules require. They are reserved **before any endpoint returns them**, so that Identity and Billing cannot invent two names for the same refusal months apart.

The catalogue is the source of truth for their statuses and meanings, and this document does not copy the table. It records two choices that would otherwise look arbitrary:

- **`UNAUTHENTICATED` already covers an expired or revoked session**, so no new code was added for that. A revoked device is not a new kind of failure; it is the existing one.
- **A lockout carries `423`, not `429`.** The catalogue allows one status per code, and a client must be able to say "you are going too fast" and "this account is locked for a while" in different words on screen. Sharing `429` with `RATE_LIMITED` would make those indistinguishable without reading the code, which is exactly what the status is for.

## Device sessions

Authentication is **per device**. One signed-in device holds one credential, and the person can see and end each one separately.

| Property | Rule |
|---|---|
| Granularity | one credential per signed-in device |
| Form | opaque, at least 256 bits of entropy |
| Stored as | a hash. The readable value exists only in the response that issues it, and is never recoverable afterwards |
| Expiry | 90 days of **inactivity**, extended on each successful use |
| Absolute cap | none in MVP |
| Revocation | effective on the **next request**, with no cache of validity anywhere in the path |
| Blast radius | revoking one session leaves every other session working |

**Rules**

- A password change revokes every session **except** the one that made the change. That is what lets a person use their one lever — "sign everything else out" — without signing themselves out of the device in their hand.
- A revoked session's row survives, so the audit trail can still name it. Only the credential stops working.
- The session list shown to its owner names the device and the times. It exposes nothing further, because someone reading that screen may not be the owner.
- There is no positive cache of "this credential is valid". Any cache measured in minutes turns "I ended that session" into a promise the product does not keep for the length of the cache.

**Owned by**: Identity.

## Passwords

| Property | Value |
|---|---|
| Function | argon2id |
| Memory | 65536 KiB |
| Iterations | 4 |
| Parallelism | 1 |
| Target cost | 250–500 ms per hash on the deployment's own hardware |

**Rules**

- The parameters above are a floor, not a ceiling. They are re-measured on the target hardware and raised until one hash costs within the target range.
- A password is rehashed on the next successful sign-in whenever its stored parameters are below the current setting. That is what makes raising the cost later a configuration change rather than a migration nobody runs.
- The hash is never returned by any endpoint, to any caller, at any level of privilege.

argon2id is chosen because it is memory-hard, which is the property that makes a stolen hash expensive to attack on rented graphics hardware. A function whose cost is fixed and low does not have that property.

**Owned by**: Identity.

## Reset and verification tokens

| Token | Lifetime | Single use | Also invalidated by |
|---|---|---|---|
| Password reset | 60 minutes | yes | any password change; any later reset request |
| Address verification | 24 hours | yes | a later verification request for the same address |

**Rules**

- Both are opaque random values, sent once, stored only as a hash.
- Resends are limited to three per hour per address.
- Invalidating earlier reset tokens when a new one is requested closes the case where an attacker requests a reset, the owner requests their own, and two live links exist at the same time.

An hour is long enough to find the mail and short enough that a link forwarded to a colleague, or left in a shared inbox, is usually already dead.

**Owned by**: Identity.

## Lockout

Two independent limits, and a way out the attacker does not control.

| Scope | Trigger | Effect |
|---|---|---|
| Account | 5 consecutive failures | refuse for 15 minutes |
| Account | 10 consecutive failures | refuse for 60 minutes |
| Origin | 20 failures across any accounts within 15 minutes | refuse that origin for 15 minutes |

**Rules**

- The account lock **caps at 60 minutes and is never permanent**.
- A successful password reset clears the lock immediately. A reset requires the mailbox, which the attacker does not have.
- A successful sign-in resets the counter.
- The refusal is `423 ACCOUNT_LOCKED` with `Retry-After`, distinguishable by the app from a wrong password, so the person is told to wait rather than told they typed it wrong.

**Why the cap and the reset matter.** Without them, anyone who knows an address can lock its owner out at will, simply by failing on purpose. A lock that expires on its own and can be cleared through the mailbox means an attacker can annoy, but cannot deny.

The per-origin limit is what actually stops credential stuffing, which spreads thinly across thousands of accounts and would never trip a per-account counter.

### Route budgets

The routes that send mail or create accounts carry their own budgets, independent of the sign-in counters above.

| Route | Budget |
|---|---|
| Registration | 10 per hour per origin |
| Resend verification | 3 per hour per address |
| Password reset request | 3 per hour per address, counted per lookup value whether the address is registered or not |
| Invitation create | 20 per 24 hours per inviter, counted for applied creates only (429) |
| Invitation mail | 5 per 24 hours per address lookup value, across all inviters; silent: the invitation is created and answered the same, no mail is sent |
| Invitation token operations (preview, accept, decline) | 30 per hour per person (429) |

**Owned by**: Identity.

## Uniform answers

Four surfaces can be used to ask "does this address have an account here". Three answer the same way whether it does or not. Registration, by owner decision (2026-09-27, spec 007), says so.

| Surface | Answer, either way |
|---|---|
| Sign-in | `401 INVALID_CREDENTIALS` |
| Password reset request | `202` — "if that address is registered, a link is on its way" |
| Registration | **not uniform**: `202` for a new address, `409 EMAIL_ALREADY_REGISTERED` for one that has an account, with no mail |
| Invitation | `201` (or `200` for a replay) with the same body whether or not the address is registered; only the mail differs |

**Rules**

- Response timing is equalised, so a wrong password and an unknown address take comparable time. A timing difference is a slower version of the same leak.
- The difference lives in the **mail body**, which reaches the person who owns the address and nobody else.
- **The accepted risk.** Registration tells anyone whether an address has an account. That was chosen so the app can say "you already have an account" on screen rather than in a mail. It is bounded only by the registration budget, 10 attempts per hour per origin (Route budgets), which slows a list check without stopping one.
- The other three stay uniform. They still protect what registration does not reveal: whether a password is right, whether a reset link was sent, and whether an invitee is registered. Their cost is small, and making registration uniform again later would need no change to them.

**Owned by**: Identity, with the invitation surface shared with Sharing.

## Verification gate

| Action | Before the address is verified |
|---|---|
| Read anything they were invited to | allowed |
| Manage their own profile | allowed |
| Manage their own sessions | allowed |
| Preview, accept or decline an invitation addressed to them | allowed |
| Leave a wallet | allowed |
| **Any write to any wallet** — including a recurring rule or one of its occurrences, and a rule's catch-up request — and, for the wallet's owner, sending or revoking an invitation, changing a role and removing a member | **refused** |
| **Any write to their own categories** | **refused** |
| **Any write to their own budgets** (save, change, archive, delete) | **refused** |

The refusal is `403 EMAIL_NOT_VERIFIED` — its own code, so the app offers to send the verification again rather than showing a denial the person cannot act on.

**Why the line is here.** Letting an unverified invitee read is what makes an invitation worth sending: they can see what they were invited to before committing to anything. Barring writes is what stops a mistyped or hostile address from putting entries into somebody's shared wallet, which is the case where an unverified account does real damage.

A category write is barred for the same reason: a category's name reaches wallet partners through its references.

**Owned by**: Identity, enforced on every write path by the feature that owns it.

## Transport

- TLS 1.2 or better on every request.
- A plaintext request to an API path is **refused** with `400 INSECURE_TRANSPORT`. It is not redirected.
- Responses carry a strict-transport-security header with a one-year duration.

**Why refuse rather than redirect.** A redirect is a convenience for a browser and a hazard for a client holding a bearer credential: by the time the redirect arrives, the credential has already been sent in the clear. A redirect also teaches a client that plaintext is a working first step, so the mistake is repeated on every request.

**Owned by**: every feature, checked per endpoint.

## What is protected at rest

There is no blanket claim here, because a blanket claim collapses under one question. This table is the answer, attribute by attribute.

| Attribute | Protection at rest | Searchable | Who can read it |
|---|---|---|---|
| Address | encrypted, with a keyed lookup value beside it | by lookup value only | the account owner, and an operator with a recorded read |
| Display name | encrypted | no | the account owner, other members of a shared wallet, an operator with a recorded read |
| Password | argon2id hash | no | nobody, ever, at any level of privilege |
| Session credential | hash | by hash | nobody after issue |
| Reset and verification tokens | hash | by hash | nobody after issue |
| Invited address | encrypted under the inviter's data key, with a keyed lookup value beside it; erased when the invitation closes | by lookup value only | members of the wallet, while the invitation is pending |
| Invitation token | hash | by hash | nobody after issue |
| Activity entries | none at rest, identifiers only: no amount, note, name or address | yes | members of the wallet |
| Amount, balance | **none at rest** | yes | members of the wallet |
| Note | **none at rest** | yes | members of the wallet |
| Budget limit and progress (`amount_minor`, spent, remaining, projection) | **none at rest**, never logged | yes | the budget's owner only |
| Wallet and category names | none at rest | yes | members of the wallet, and the category's owner |
| Platform category names | none at rest | yes | every signed-in person |
| Audit entries | none at rest, and they carry nothing protected | yes | operators only, and the read is recorded |
| Device name, platform, app version | none | no | the account owner |
| Device installation identifier | none | by owner's sessions only | nobody through any endpoint |
| Sign-in failure counter | none (keyed by lookup value) | by lookup value | nobody through any endpoint |
| Operator notification | none, carrying identifiers only | yes | operators |

**Rules**

1. An attribute not in this table carries **no** protection claim. Adding a claim means adding a row, in the same change that adds the attribute.
2. A row marked "hash" is one-way, and is never returned by any endpoint to any caller.
3. "An operator with a recorded read" means the read produces an `audit_read` entry.
4. Encryption is authenticated, with a per-record nonce and the key identifier stored beside the value.

### Why amounts are not encrypted

Deliberately, and worth saying plainly rather than leaving as an omission somebody later reads as an oversight.

Arithmetic, aggregation and indexing all depend on amounts: a balance is a sum, a budget is a comparison, a report is a group-by. Encrypting them would mean decrypting every row to answer any of those, which is both slow and pointless — an attacker who reaches the database has reached the application that must decrypt them anyway.

Amounts are protected by who can reach them: wallet scope, transport, and access control. That is a true statement about a real protection, where "everything is encrypted" would be a false statement about an imaginary one.

**Owned by**: Identity for the identity attributes; every feature for its own.

## Finding a person by address

An encrypted address cannot be searched, and sign-in, uniqueness and invitations all need to find one. The answer is a second value stored beside it:

```text
lookup = HMAC-SHA256(normalise(address), lookup_key)
```

Unique-indexed. Every lookup by address — sign-in, "is this address already registered", invitation matching — goes through this value and never through a readable address.

### Normalisation

Applied identically everywhere the value is produced, or the same address yields two different values and one person becomes two accounts:

1. trim surrounding whitespace
2. Unicode NFKC
3. lowercase the entire address

**No provider-specific munging.** Dots and plus-tags are not stripped. `a.b@example.com` and `ab@example.com` are different addresses to everyone who is not that particular provider, and treating them as one silently merges two people — which, in an app holding money, is the worst available outcome.

### What this does and does not protect against

| Attacker holds | Outcome |
|---|---|
| The stored data | learns nothing. The addresses are unreadable and the lookup values are not reversible |
| The stored data **and** the lookup key | can confirm an address they already guessed. Cannot list the addresses that exist |

The second row is the honest limit of this design, and it is written here so that nobody builds a promise on top of a protection that does not extend that far.

The lookup key is separate from the encryption key and lives outside the database.

**Owned by**: Identity.

## Keys and rotation

A key that cannot be rotated is one incident away from being permanent.

**Rules**

- Every encrypted value stores the identifier of the key that produced it. A partially rotated table is therefore a valid state, not a corrupt one.
- Keys live in the deployment's secret store. Never in the database they protect, and never in the repository.

### Rotating an encryption key

Add the new key, write new values with it, and keep the old key readable until a re-encryption pass finishes. Nobody re-registers, and no value is unreadable at any point during the rotation.

### Rotating the lookup key

Harder, because the value is a unique index rather than a payload. The sequence:

1. write the new lookup value into a second column;
2. backfill it for every existing row;
3. swap the unique index onto the new column;
4. drop the old column.

This is written down so it is a planned operation with four steps, rather than an outage somebody discovers halfway through.

**Owned by**: Identity.

## What must never be logged

This restates the existing [observability decision](adr/0010-observability.md) rather than replacing it, and fixes exactly where the line falls.

### Never, at any level

- passwords, and anything derived from one
- authentication tokens and session identifiers
- one-time codes
- purchase tokens
- receipt images and their contents
- the full body of a financial write

### Debug level only

**Amounts and record identifiers.** They are permitted at debug level, for diagnosing the "my transfer vanished" report that this app will certainly receive. They are barred from informational level and above, and they never leave the service in an error report or a crash payload sent to a third party.

### Enforcement

Redaction is enforced **centrally**, by a processor or scrubber, not by asking each call site to remember. A rule that depends on every author remembering it is a rule that holds until the first busy afternoon.

**Owned by**: every feature; the central scrubber is owned by the observability work.

## What must never be disclosed

**Rules**

1. No response, error or message names another user, another wallet, or a value belonging to either.
2. A caller who is not a member of a wallet receives a **not-found**, never a forbidden, so the wallet's existence is never disclosed. A forbidden is correct only for a caller who is already a member and whose role or plan does not permit the action — membership has already disclosed existence.
3. An error message says what the caller did wrong, never what somebody else has.

The rule in 2 is fixed by the [authorization decision](adr/0006-wallet-scoped-authorization.md). The wallet role and action matrix itself lives **only** in the Wallets specification and is deliberately not repeated here, or anywhere else.

A useful test: read the error message as though you were an attacker holding a list of addresses and no account. If it tells you something you did not already know, it is a leak.

**Owned by**: every feature, checked per endpoint.

## What a person sees and controls

A person can list every device signed into their account and end any of them. The rules for what a session is and what ending one does are in [Device sessions](#device-sessions) and are not repeated here.

What this section adds is the shape of the list itself:

- each entry names the device and the times well enough that its owner recognises it;
- it exposes nothing further, because the person reading that screen may not be the owner;
- ending a session is immediate, and leaves every other session working.

This is the part of privacy a user can test for themselves in thirty seconds, which is why it has to be true before anything is claimed about the rest.

**Owned by**: Identity.

## Retention

Every period is a number. Nothing here says "a reasonable time", because a reviewer cannot check one.

| What | Kept for | Fixed by |
|---|---|---|
| Deleted syncable record, content and marker | 90 days, enforced by `sync:purge-tombstones` | [the sync decision](adr/0008-sync-changes-feed.md) |
| Other soft-deleted records | 30 days | this document |
| Account after a deletion request | 30 days, then purged | this document |
| Audit entries | 400 days | this document |
| Application logs | 30 days | [the observability decision](adr/0010-observability.md) |
| Idempotency records | 24 hours | [the API conventions](api-conventions.md) |
| Password reset token | 60 minutes | this document |
| Address verification token | 24 hours | this document |
| Provider message identifiers | 24 hours | this document |

### Two horizons, and why

The privacy promise wants deleted data gone quickly. The sync protocol needs a deletion marker to survive long enough for a device that was offline to learn the record is gone — otherwise that device silently keeps a record everyone else has deleted.

Both are true at once, stated separately rather than averaged into a number that satisfies neither. **The published commitments state 90 days for anything that syncs**, which is the longer figure. A promise the retention rule cannot keep is worse than a longer promise honestly made.

400 days for audit entries rather than 365, so that a person disputing something from this time last year is still inside the window, with room for the investigation that follows.

**Owned by**: Sync for the purge that enforces these; each feature for its own records.

## Deletion

```text
requested ──cancel, or sign in, before the 30 days elapse──▶ cancelled
    │
    └──purge runs, at or after 30 days──▶ completed
```

**Rules**

1. Cancelling inside the grace period **loses nothing**. The account and every record come back untouched.
2. The person may keep using the account during the grace period, writes included. Using it neither extends nor resets the clock.
3. On completion, the rows the person alone owns are deleted **and** the per-account key material is destroyed.
4. What is claimed is the removal of their identity attributes and their own records — not every byte they ever caused to exist, because a shared wallet's history is not theirs alone to delete.
5. A new successful sign-in during the grace period cancels the request; the person is told by mail and operators are notified.

### Why the key material matters

Deleting rows is not quite enough on its own: a backup taken before the purge and restored afterwards would bring the readable data back. Destroying the key that decrypts that person's attributes means a restored backup yields nothing readable for them, which is what makes "irreversibly removed" survive contact with an ordinary operations practice.

**Owned by**: Identity for the request and the purge of owned records; Sync for the horizon.

## Leaving a shared wallet

This is the case where privacy and correctness pull in opposite directions.

| What the departing person owned | What happens |
|---|---|
| Wallets they own | removed with them |
| Records only they can see | removed |
| Transactions they authored in **someone else's** wallet | **kept** |
| Their name on those transactions | replaced by a stable anonymous marker that cannot be resolved back to a person |
| Their membership row | removed |

**Why the transactions stay.** Deleting them would change the other members' balances, without those members doing anything or being told. A partner would open the app to find last month's total different and no explanation available. Correctness wins for the money; privacy wins for the identity, which is why the name goes and the amount does not.

### The limit we cannot reach

A revoked or departed member's phone still holds whatever it last synced. No server rule reaches a copy already on someone else's device, and this document says so rather than implying a remote wipe that does not exist. Anything the commitments say about sharing has to be true with that sentence in view.

**Owned by**: Identity for the purge; Wallets for the anonymous marker.

## Export

A person can take a complete copy of what they entered.

**Rules**

1. Produced asynchronously. A second request while one is in progress is `409 EXPORT_IN_PROGRESS`.
2. Contents: complete structured data, plus a spreadsheet-openable file of transactions — so "a form they can open without this app" is literally true and not a technicality about a format nobody has a reader for.
3. It contains what the person entered and what they can already see. Not other members' records beyond that.
4. An export requested **during the deletion grace period is served**, and does **not** reset the clock. Refusing it strands someone at the exact moment they are leaving; resetting the clock would let a scheduled export postpone a deletion indefinitely.
5. Both `export_requested` and `export_delivered` are recorded.

**Owned by**: Identity.

## Who owns the audit log

**Identity owns the audit log** and exposes an application service that every other module calls. Admin keeps the `AdminAuditService` name the constitution gives it, as its own façade for operator actions — writing through Identity's service, **not** to a second store.

### Why there

Three options were available and two were closed by rules already in force:

- **A shared store in the shared kernel.** Not possible: the shared kernel holds persistence-free value objects only, by constitutional rule.
- **A tenth bounded context, owning nothing but the log.** Possible, but it needs a constitution amendment, which is a high price for one table.
- **A log per module.** Possible, and wrong: "who removed me from this wallet" then takes three queries and a merge, and the answer depends on which module remembered to write its own copy.

Identity already owns users, credentials and sessions, which is where most recorded events originate. A cross-module write through an application service is one of the two mechanisms the module rules permit, so nothing is bent to make this work.

**Rule**: nothing writes to a second audit store. One log, one shape, one query.

## The audit entry

| Field | Type | Notes |
|---|---|---|
| `id` | uuid | UUIDv7 |
| `occurred_at` | instant | UTC |
| `event` | enum | from [the vocabulary](#what-is-recorded), and nothing else |
| `actor_type` | enum | `user` \| `operator` \| `system` |
| `actor_id` | uuid, nullable | null only for `system` |
| `subject_type` | enum | `user` \| `wallet` \| `session` \| `subscription` |
| `subject_id` | uuid, nullable | null only for `sign_in_failed` and `lockout_applied` on an unregistered address |
| `request_id` | uuid | correlates the entry with the server's logs |
| `origin` | string, nullable | coarse origin of the request |
| `detail` | object | structured, constrained by [the exclusion list](#what-an-entry-must-never-contain) |

`actor_type` is what makes "an operator did this on your behalf" distinguishable from "you did this", which is the question a support conversation turns on.

## What is recorded

The vocabulary is **fixed**. "Log security events" is not a rule anybody can check; a list is.

| Group | Events | Written by |
|---|---|---|
| Access | `sign_in_succeeded`, `sign_in_failed`, `lockout_applied` | Identity |
| Sessions | `session_issued`, `session_revoked` | Identity |
| Credentials | `password_changed`, `address_change_requested`, `address_verified` | Identity |
| Sharing | `membership_granted`, `membership_revoked`, `role_changed` | Wallets |
| Billing | `entitlement_changed` | Billing |
| Departure | `export_requested`, `export_delivered`, `deletion_requested`, `deletion_cancelled`, `deletion_completed` | Identity |
| Privileged | `admin_action`, `audit_read` | Admin |

The Sharing events are written by Wallets, not Sharing: every membership change goes through `WalletService`, so the module that performs the act records it (spec 010, Clarifications), and each event still has exactly one writer.

**Rules**

- A module does not invent a local event name. A genuinely new event is added to this table, in the same change that starts emitting it.
- Every event names **exactly one** module that writes it, so no event reaches implementation without an owner.
- An act that appears in this table produces exactly one entry. Not two, and not none.

## What an entry must never contain

An entry names **what happened, never what it was worth**.

Never inside an entry:

- passwords, and anything derived from one
- session credentials and any token
- one-time codes
- amounts and balances
- notes
- receipt contents
- a readable address

The subject is referenced by identifier.

### Why the list is this strict

A record built to answer security questions becomes the single best thing in the system to steal, the moment it starts copying what it describes. A log that named amounts would be a second, unencrypted, conveniently chronological copy of the ledger — with a longer retention than the application logs and a reason for operators to read it.

### Operational rules

- **Append-only.** An entry is never edited, and never deleted before its retention expires.
- **400 days**, per the retention table.
- **Operators only** may read it.
- **Reading is itself an event.** Every read produces an `audit_read` entry, so the richest target in the system cannot be browsed unobserved. This is the rule that makes operator access to the log self-limiting.

**Owned by**: Identity.

## Operator access

The back office is not a second way into user data with a weaker lock.

**Rules**

- Operator accounts are **entirely separate** from application users, on their own guard, per [the authorization decision](adr/0006-wallet-scoped-authorization.md). An application credential can never reach an operator route.
- A second factor is required in addition to a password: time-based codes, six digits, a 30-second step, one step of drift tolerated.
- Enrolment is **mandatory**. An operator account that has not enrolled can do nothing at all.
- A correct password alone is refused: `401 TWO_FACTOR_REQUIRED` when the factor is absent, `401 TWO_FACTOR_INVALID` when it is wrong.
- An operator acts **through other modules' application services**, so the same invariants, authorization and events apply to a back-office action as to a user's own. There is no direct-write path and no read-only exception.

Time-based codes need no messaging channel, which matters: the messaging channel is often the thing an attacker already controls.

**Owned by**: Admin.

## Second-factor recovery

Recovery has to exist, and must not become the hole that makes the second factor decorative.

**Rules**

1. Ten single-use recovery codes are issued at enrolment and shown **once**.
2. Losing the device is recovered with one of those codes.
3. When they are exhausted, **two other operators** must approve a re-enrolment, and both approvals are recorded as `admin_action` entries.
4. Recovery never reduces to asking somebody to switch the requirement off. There is no such switch.

Two approvals rather than one, because one approver is a single person to socially engineer, and this is the path that grants full back-office access to whoever walks it.

**Owned by**: Admin.

## Messages from the payment provider

A message claiming to come from the payment provider is rejected unless **all five** of these hold:

1. the signature verifies against the provider's current public keys;
2. the issuer is the expected one;
3. the audience names **this** service, not another;
4. the issued-at time is within five minutes of now;
5. the message identifier has not been seen in the last 24 hours.

### Outcomes

| Case | Response |
|---|---|
| All five hold | processed |
| Already seen | **acknowledged, with no effect** |
| Signature, issuer or audience wrong | `401 PROVIDER_SIGNATURE_INVALID`, nothing granted |
| Outside the five-minute window | `401 PROVIDER_SIGNATURE_INVALID`, nothing granted |
| Key material unreachable | rejected, left for the provider to retry |

### The two that are easy to get wrong

**The audience check.** It is the one people skip, because a message with a valid signature looks finished. Without it, a validly signed message issued for a different service can be replayed here and grant a subscription nobody paid for.

**Acknowledging a repeat instead of refusing it.** A well-behaved provider retries anything it does not see acknowledged, so refusing a duplicate produces a retry loop that never ends. Acknowledging without effect stops the retries and grants nothing.

Unverifiable key material causes **rejection**, never acceptance and never deferred trust. "Accept now, verify later" is a decision to grant entitlements on unverified claims, whatever it is called.

**Owned by**: Billing.

## Appendix: security review checklist

Run this against **one** thing at a time: one endpoint, one stored attribute, or one recorded entry. Every line is answerable yes or no without reading another feature, which is what makes the rules above reviewable rather than aspirational.

A reviewer who answers "no" either fixes the thing or records why the exception is correct in that feature's plan. A "no" with no record is a defect.
FR-038a requires every rule in this baseline to be expressible as a check a reviewer can apply to **one** endpoint, **one** stored attribute or **one** recorded entry. This is that list. Every line is answerable yes or no without reading another feature.

### A. Sessions and credentials

- [ ] A1 — Authentication is per device session; the endpoint does not issue or accept an account-wide credential.
- [ ] A2 — Ending one session leaves every other session working.
- [ ] A3 — A revoked credential fails on the very next request, with no cache of validity anywhere in the path.
- [ ] A4 — A password change revokes every session except the one that made the change.
- [ ] A5 — No endpoint returns a password hash, a credential hash, or a token hash.
- [ ] A6 — Session expiry extends on use and is not a fixed date the user cannot predict.

### B. Guessing and probing

- [ ] B1 — Sign-in failures are counted per account **and** per origin.
- [ ] B2 — An account lock expires on its own within 60 minutes and is never permanent.
- [ ] B3 — A successful password reset clears a lock immediately.
- [ ] B4 — A lockout refusal is distinguishable by the client from a wrong password.
- [ ] B5 — Sign-in, reset and invitation answer identically for a registered and an unregistered address. Registration is the recorded exception (Uniform answers).
- [ ] B6 — Response timing does not distinguish a wrong password from an unknown address.

### C. The verification gate

- [ ] C1 — Every write to a wallet and every write to a category is behind the gate.
- [ ] C2 — Reads, profile management and session management are **not** behind the gate.
- [ ] C3 — The refusal is its own code, so the app can offer a resend.
- [ ] C4 — Verification links are single-use, time-limited, and resends are throttled.

### D. Data at rest

- [ ] D1 — Every attribute this endpoint stores appears in the protected-attributes table, or is added to it in the same change.
- [ ] D2 — The address is stored encrypted, and every lookup by address goes through the keyed value.
- [ ] D3 — Nothing in the change claims protection the table does not grant.
- [ ] D4 — Every encrypted value stores the identifier of the key that produced it.
- [ ] D5 — No key is stored in the database it protects.

### E. Leakage

- [ ] E1 — No log line, at any level, contains a password, a token, a one-time code, a receipt or the full body of a financial write.
- [ ] E2 — No amount or record identifier is logged at informational level or above.
- [ ] E3 — Nothing on the never-log list leaves the service in an error report or crash payload.
- [ ] E4 — Redaction is enforced centrally, not by each call site remembering.
- [ ] E5 — No error message names another user, another wallet, or a value belonging to either.
- [ ] E6 — A caller who is not a member gets a not-found, never a forbidden, so existence is not disclosed.

### F. Accountability

- [ ] F1 — Every act this endpoint performs that appears in the event vocabulary produces exactly one entry.
- [ ] F2 — The entry names the actor, the subject, the act, the time and the request correlation.
- [ ] F3 — The entry contains nothing on the exclusion list.
- [ ] F4 — An operator acting on a user's behalf is distinguishable from the user acting alone.
- [ ] F5 — No new event name was invented locally; a genuinely new event was added to the vocabulary in the same change.
- [ ] F6 — Nothing writes to a second audit store.

### G. Retention and departure

- [ ] G1 — Every retention period this feature introduces is a number in the retention table.
- [ ] G2 — No user-facing text states a shorter period than the table allows.
- [ ] G3 — A cancelled deletion loses nothing.
- [ ] G4 — A completed deletion leaves no readable identity attribute, including in a restored backup.
- [ ] G5 — A shared wallet keeps its history and balances when one member departs.
- [ ] G6 — An export served during the grace period does not reset the clock.
- [ ] G7 — A sign-in during the grace period cancels the deletion and notifies the person and operators.

### H. Privileged and machine access

- [ ] H1 — A correct operator password alone does not grant access.
- [ ] H2 — Second-factor recovery exists and does not reduce to asking someone to switch it off.
- [ ] H3 — An operator acts through other modules' application services, so the same invariants apply.
- [ ] H4 — A provider message is checked for signature, issuer, **audience**, time window and replay.
- [ ] H5 — An already-processed message is acknowledged without effect, not refused.
- [ ] H6 — Unverifiable key material causes rejection, never acceptance and never deferred trust.

### I. Transport and contract hygiene

- [ ] I1 — A plaintext request is refused, not redirected.
- [ ] I2 — Every error code this endpoint returns exists in the shared catalogue.
- [ ] I3 — No security rule is restated here that the baseline already fixes; the endpoint references it.
- [ ] I4 — Every user-facing promise touched by this change still maps to a requirement.

### Using this list

A reviewer who answers "no" to any line either fixes the endpoint or records why the exception is correct in that feature's plan. A "no" with no record is a defect. The list is also the source for the reviewer exercise in the feature specs.
