# Feature Specification: Security & Privacy Baseline

**Feature Branch**: `004-security-privacy-baseline`

**Created**: 2026-09-22

**Status**: Draft

**Input**: Backlog item A4 (see [specs/README.md](../README.md)): "Sanctum personal access tokens, one per device session, 90-day sliding expiry, revocable; password hashing argon2id; login and reset endpoints rate-limited per IP and per account with lockout after N failures; email verification required before any wallet write; PII (email, display name) encrypted at rest with Laravel's encrypted casts; audit log of security events (login, token revoke, password change, role change, admin actions); webhook signature verification (Google Pub/Sub JWT); data retention: soft-deleted records purged after 30 days, deleted accounts purged after 30-day grace; admin access via separate guard with 2FA. Considerations: The app is a personal-finance product in a market where the local competitors are criticised for 'data not encrypted' — this baseline is a marketing asset, so write the user-facing privacy commitments alongside the technical ones. Do not log request bodies containing amounts or notes."

> **Nature of this feature.** The deliverable is the security and privacy rules every later feature obeys, plus the promises the product makes to its users in plain language. Its readers are the people specifying Identity, Billing and Sharing, the reviewers who check them, and — for the commitments half — the person deciding whether to trust this app with their salary. Security vocabulary is the subject matter here, not leaked implementation detail; no package, guard, column or middleware is prescribed.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - An account cannot be quietly taken over (Priority: P1)

Someone has a person's email address and wants their financial history. Guessing the password is throttled and then locked. A leaked password from another site is not enough on its own to start writing to wallets, because an unverified address cannot write. If the attacker does get in, the person can see the intruding session and end it, and the act is recorded.

**Why this priority**: this app holds a complete picture of a person's money. An account takeover is not an inconvenience here; it is a stranger reading a household's finances, and in a shared wallet it is two households.

**Independent Test**: attempt credential stuffing against a known address. The attempts are refused before they succeed, the account is not permanently bricked, the legitimate owner can still get in, and every attempt is visible afterwards.

**Acceptance Scenarios**:

1. **Given** repeated failed sign-in attempts for one address, **When** the threshold is crossed, **Then** further attempts are refused for a stated period rather than continuing to be evaluated.
2. **Given** a correct password for an account whose address is unverified, **When** the caller attempts any write to a wallet, **Then** the write is refused with a distinct outcome the app can explain.
3. **Given** a stolen device session token, **When** the owner ends that session, **Then** the token stops working immediately and the owner's other sessions are unaffected.
4. **Given** a sign-in from a new device, **When** it succeeds, **Then** the event is recorded with enough detail to answer "was that me?" later.

---

### User Story 2 - Personal and financial data stays unreadable to the wrong eyes (Priority: P1)

A person's identity and their money are two different kinds of secret, and both are protected: the first against anyone who reaches the stored data, the second against everyone who was not invited to the wallet. Nothing sensitive leaks sideways through a log line, an error message or a support screen.

**Why this priority**: the market's stated objection to the local competitors is that data is not encrypted. This is simultaneously the product's biggest trust liability and its clearest differentiator, and it has to be true before it can be claimed.

**Independent Test**: inspect stored data, logs and error responses for a completed money entry. Identity attributes are unreadable at rest, amounts and notes appear in no log, and no response discloses a resource the caller may not see.

**Acceptance Scenarios**:

1. **Given** access to stored data alone, **When** someone reads the identity attributes of a user, **Then** they are not readable without a separate secret.
2. **Given** a request carrying an amount and a note, **When** it is processed, **Then** neither value appears in any log entry, in any error report, or in any crash payload.
3. **Given** a failure of any kind, **When** its message reaches the client, **Then** it names no other user, no other wallet and no value belonging to either.
4. **Given** a person who is not a member of a wallet, **When** they reference one of its records, **Then** the response does not reveal that the record exists.

---

### User Story 3 - A person controls their sessions and can leave with their data (Priority: P2)

Someone sees every device signed into their account and can cut any of them off. When they want to leave, they can take a complete copy of what they entered, and the account and its data actually disappear afterwards — not immediately, because people change their minds, but genuinely.

**Why this priority**: it is the part of privacy a user can see and test for themselves. It is also what a store listing and a privacy page have to be able to claim honestly.

**Independent Test**: from one device, list the sessions, end another one, request an export, request deletion, then reverse the deletion inside the grace period and confirm nothing was lost.

**Acceptance Scenarios**:

1. **Given** several signed-in devices, **When** the person lists their sessions, **Then** each is identifiable well enough to recognise, without exposing anything that would help an attacker.
2. **Given** a deletion request, **When** the grace period has not elapsed, **Then** the person can cancel it and find their data intact.
3. **Given** a deletion request whose grace period has elapsed, **When** the purge runs, **Then** the person's identity attributes and their own records are gone, and a later export or sign-in finds nothing.
4. **Given** a person who shares a wallet they do not own, **When** their account is purged, **Then** the wallet's remaining members keep the wallet and its history, and the departed member is no longer named.

---

### User Story 4 - Every security-relevant act can be answered for afterwards (Priority: P2)

When a user asks "who removed me from this wallet?" or an operator asks "was this account accessed from somewhere new before the complaint?", there is a record that answers it. The record itself is not a second copy of the data it is protecting.

**Why this priority**: shared wallets make security questions social — a person's partner, sibling or flatmate is often the other party. Without a record, support is guesswork and every dispute becomes one person's word against another's.

**Independent Test**: perform a sign-in, a session revocation, a password change and a membership change, then answer, from the record alone, who did each one and when.

**Acceptance Scenarios**:

1. **Given** any change to credentials, sessions, membership or entitlements, **When** it completes, **Then** an entry exists naming the actor, the subject, the act and the time.
2. **Given** an audit entry, **When** anyone reads it, **Then** it contains no password, no token, no amount and no note.
3. **Given** a back-office action taken on a user's behalf, **When** it completes, **Then** the record distinguishes the operator who acted from the user it affected.

---

### User Story 5 - Privileged and machine access cannot be forged (Priority: P3)

The back office is not a second way into user data with a weaker lock, and a message claiming to come from the payment provider cannot simply be posted by anyone who knows the address.

**Why this priority**: it matters from the first paying customer, not the first user. But an unverified provider message can grant a paid subscription for free, and a back office without a second factor is the softest target in any system that has one.

**Independent Test**: attempt a back-office sign-in with a correct password alone, and post an unsigned and a replayed provider message. All three are refused.

**Acceptance Scenarios**:

1. **Given** a correct back-office password, **When** the second factor is absent or wrong, **Then** access is refused.
2. **Given** a message that claims to come from the payment provider, **When** its signature is absent, wrong, or valid but for a different audience, **Then** it is rejected and nothing is granted.
3. **Given** a provider message that was already processed, **When** it arrives again, **Then** it is recognised and does not grant a second entitlement.

---

### Edge Cases

- **Lockout as a weapon**: an attacker who knows a victim's address deliberately fails sign-in until the account locks, to deny the owner access. The rule must resist guessing without handing an attacker an off switch.
- **A revoked session that is offline**: a device is cut off while it holds unsynced local writes. What it may do when it reconnects has to be defined, or the user silently loses entries they made.
- **Enumeration through the side door**: sign-in, password reset, invitation by address and "address already registered" each reveal whether an address exists unless all four answer the same way.
- **Encrypted identity versus finding a person**: if an address cannot be read back, then login, uniqueness and invitation-by-address all need another way to match one.
- **The purge promise versus the sync horizon**: deleted records must survive long enough for an offline device to learn they were deleted, which is longer than the retention promise a privacy page would like to make.
- **Deleting one member of a shared wallet**: the departing person's identity has to go while the wallet's history — which other people rely on and partly authored — has to stay.
- **Export during the grace period**: a person asks for their data while their deletion is pending. Refusing it strands them; serving it has to not reset the clock.
- **The audit log as a leak**: a record built to answer security questions becomes the single richest target in the system if it copies the data it describes.
- **A lost second factor**: the one back-office account with two-factor enabled loses its device. Recovery must exist and must not be a hole.
- **Reset link reuse**: a password reset link is forwarded, used twice, or used after the password already changed.
- **A partner who was removed**: a revoked wallet member still holds a full local copy on their phone. What the contract can and cannot promise about that copy has to be stated rather than implied.

## Requirements *(mandatory)*

### Functional Requirements

#### Sessions and credentials

- **FR-001**: Authentication MUST be per device session: one credential per signed-in device, individually listable and individually revocable, so ending one session leaves the others working.
- **FR-002**: A session credential MUST expire after a period of inactivity, and MUST extend on use rather than requiring a fixed re-login date.
- **FR-003**: Revocation MUST take effect on every subsequent request, with no cache of validity anywhere in the path. A request already past authentication when revocation lands completes; the contract MUST NOT promise to retract one, because no server rule can.
- **FR-004**: Passwords MUST be stored using a memory-hard hashing function with per-password salting, and MUST NOT be recoverable from storage.
- **FR-005**: A password change MUST end every other session, and the contract MUST state whether the changing device keeps its own.
- **FR-006**: Password reset MUST use a single-use, short-lived token, invalidated by use, by expiry, and by any other successful reset or password change.

#### Resisting abuse

- **FR-007**: Sign-in, password reset and address-verification endpoints MUST be rate-limited both per originating address and per targeted account.
- **FR-008**: Repeated failures against one account MUST lead to a temporary refusal, and the rule MUST NOT allow an attacker to deny a legitimate owner access indefinitely.
- **FR-009**: Sign-in, password reset, invitation and registration MUST NOT disclose whether an address is registered. All four MUST answer identically for known and unknown addresses.
- **FR-010**: A refusal caused by throttling or lockout MUST be distinguishable by the app from a wrong password, so the person is told to wait rather than told they typed it wrong.

#### The verification gate

- **FR-011**: A person MUST verify their address before any write to a wallet. Reading, and managing their own profile and sessions, MUST remain available before verification.
- **FR-012**: The refusal for an unverified writer MUST be its own outcome, distinct from any other denial, so the app can offer to resend the verification rather than showing a generic error.
- **FR-013**: Verification links MUST be single-use and time-limited, and re-sending MUST be rate-limited.

#### Data at rest and in flight

- **FR-014**: Identity attributes that name a person — at minimum their address and display name — MUST be unreadable from stored data alone.
- **FR-014a**: Because an unreadable address cannot be searched, the system MUST also store a **keyed one-way value** derived from the address after normalisation, and MUST use that value — never a readable address — for sign-in lookup, uniqueness and invitation matching.
- **FR-014b**: Address normalisation MUST be defined once and applied identically everywhere the keyed value is produced, so the same address always yields the same value.
- **FR-014c**: The keys protecting identity attributes and the keyed lookup value MUST be rotatable without asking anyone to register again, and the contract MUST state what happens to stored values during a rotation.
- **FR-015**: The contract MUST state, for every stored attribute this feature protects, whether it is protected at rest, and MUST NOT leave "everything is encrypted" as an unqualified claim.
- **FR-016**: Tokens, passwords, one-time codes, purchase receipts and the full body of a financial write MUST NOT appear in any log, error report or crash payload, at any level. This restates the existing never-log list rather than replacing it.
- **FR-016a**: Amounts and record identifiers MUST NOT appear at informational level or above, and MUST NOT leave the service in an error report or crash payload. The existing decision permits them at debug level for diagnosis, and this feature does not withdraw that; it fixes where the line is.
- **FR-016b**: Redaction MUST be enforced centrally, so a rule holds without every call site remembering it.
- **FR-017**: No response, error or message MUST disclose the existence of a record the caller is not entitled to see.
- **FR-018**: Every request and response carrying credentials or financial data MUST travel over an encrypted channel, and the contract MUST define the refusal when one does not.

#### Accountability

- **FR-019**: The system MUST record an entry for each of: sign-in success, sign-in failure, session issue, session revocation, password change, address change, verification, membership grant, membership revocation, role change, entitlement change, export request, deletion request and every back-office action.
- **FR-020**: Each entry MUST name the actor, the subject, the act, the time, and enough request context to correlate it with the server's logs.
- **FR-021**: Entries MUST NOT contain passwords, tokens, amounts, notes or any other protected value. An entry names what happened, never what it was worth.
- **FR-022**: Entries MUST distinguish an act taken by a user from the same act taken on their behalf by an operator.
- **FR-023**: The contract MUST define how long entries are kept and who may read them, and reading them MUST itself be an act that is recorded.

#### Retention, export and departure

- **FR-024**: A person MUST be able to request a complete copy of the data they entered, in a form they can open without this app.
- **FR-025**: Account deletion MUST have a grace period during which the person can cancel and lose nothing.
- **FR-026**: After the grace period, the person's identity attributes and the records they alone own MUST be irreversibly removed.
- **FR-027**: Purging one member of a shared wallet MUST NOT remove the wallet or the history other members depend on, and the departed member MUST stop being named in it.
- **FR-028**: Retention MUST use **two horizons, stated separately**. A deleted record that syncs keeps its content and its deletion marker for the sync horizon — at least 90 days — so a device that was offline learns the record is gone. Every other soft-deleted record is purged after 30 days.
- **FR-028a**: The user-facing commitments MUST state the longer horizon for anything that syncs, and MUST NOT claim the shorter one. A promise the retention rule cannot keep is worse than a longer promise honestly made.
- **FR-029**: Every retention period in this feature MUST be stated as a number a reviewer can check, not as "a reasonable time".

#### Privileged and machine access

- **FR-030**: Back-office access MUST require a second factor in addition to a password, and MUST use accounts entirely separate from application users.
- **FR-031**: The contract MUST define second-factor recovery, and recovery MUST NOT reduce to "ask someone to turn it off".
- **FR-032**: A message from an external provider MUST be rejected unless its origin is cryptographically verified, including that it was issued for this service rather than another.
- **FR-033**: A provider message that has already been processed MUST NOT take effect a second time.
- **FR-034**: The contract MUST define what happens when provider verification is unavailable, and the answer MUST NOT be to accept the message.

#### What the product promises

- **FR-035**: The feature MUST produce user-facing privacy commitments in plain language, in both supported languages, stating what is collected, what is protected, how long it is kept and how to leave.
- **FR-036**: Every user-facing commitment MUST correspond to a requirement in this specification. A promise with no backing requirement is a defect.
- **FR-037**: The commitments MUST be usable on a store listing and a public page without rewriting, since that is where the market objection this feature answers is made.

#### Scope of this feature

- **FR-038**: The deliverable is the **written baseline plus the user-facing commitments**, as the three foundation specs before it were. No runtime part is built here.
- **FR-038a**: Every rule here MUST be expressible as a check a reviewer can apply to one endpoint, one stored attribute or one recorded entry.
- **FR-038b**: Every rule that needs code MUST name the feature that will build it, so no rule arrives at implementation without an owner.

### Key Entities

- **Device Session**: one signed-in device — what identifies it to its owner, when it was last used, and when it expires.
- **Credential**: the stored form of a password, plus the record of when it last changed.
- **Verification Token**: a single-use, time-limited proof of address ownership.
- **Reset Token**: a single-use, time-limited authority to set a new password.
- **Lockout Record**: the state that makes repeated failure against one account stop being useful.
- **Audit Entry**: actor, subject, act, time and correlation — and deliberately nothing else.
- **Deletion Request**: a pending departure, its grace period and its outcome.
- **Export**: the copy of a person's own data, and the record that it was produced.
- **Operator Account**: a back-office identity, separate from every application user, carrying a second factor.
- **Provider Message**: an inbound claim from an external system, with the evidence that makes it trustworthy.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Automated guessing against a single known address is refused before it can test more than a stated number of passwords, and the legitimate owner can still sign in within one hour of the attack starting.
- **SC-002**: For a completed money entry, a reviewer inspecting stored data, logs, error reports and crash payloads finds zero occurrences of the amount, the note or the person's address in readable form.
- **SC-003**: Sign-in, password reset, registration and invitation produce responses that cannot be distinguished between a registered and an unregistered address, measured over repeated attempts.
- **SC-004**: All four questions — who signed in, who ended a session, who changed the password, who changed a membership — are answerable from the record alone, with no access to the application's data.
- **SC-005**: A person can list their sessions, end one, export their data and request deletion using only the app, with no support contact.
- **SC-006**: A deletion cancelled inside the grace period loses nothing; a deletion left to complete leaves no way to recover the person's identity attributes.
- **SC-007**: Every user-facing privacy commitment maps to exactly one requirement in this specification, and a reviewer checking the published commitments against this document finds no promise without a backing rule.
- **SC-008**: A person specifying Identity, Billing or Sharing can determine, from this document alone, what their feature must do about sessions, verification, protected attributes, recorded events and retention — without asking.
- **SC-009**: Given five seeded violations — a logged amount, an error naming another user's wallet, an unverified writer, an unsigned provider message, and a retention period stated as "a reasonable time" — a reviewer maps 5 of 5 to the rule they break.

## Clarifications

### Session 2026-09-22

- Q: Encrypting the address makes it unsearchable, but sign-in, uniqueness and invitation-by-address all need to find a person by it. → A: **Encrypt both attributes and store a keyed one-way lookup value** derived from the normalised address. Every existing flow keeps working. Someone holding the stored data alone learns nothing; someone holding the data *and* the key can confirm an address they already guessed, but cannot list the addresses that exist.
- Q: The privacy promise wants deleted data gone in 30 days; the sync decision requires deletion markers to survive at least 90. → A: **Two horizons, stated honestly.** Syncable records keep content and marker for 90 days; other soft deletes purge at 30. The published commitment says 90 days for anything that syncs. No decision record changes, and restoring a deleted record keeps working exactly as the sync contract already defines it.
- Found during implementation, 2026-09-22: **FR-003 promised more than any server can do.** It said revocation takes effect for requests "already in flight", which have already passed authentication. The requirement now says every subsequent request, with no cache of validity, and states plainly that an in-flight request completes. The shipped baseline already said the correct thing; the requirement was the thing that was wrong.
- Found during planning, 2026-09-22: **FR-016 as first written contradicted ADR-0010 rule 8**, which permits amounts and identifiers at debug level for diagnosis. An Accepted decision is never overridden by a spec, so the requirement was narrowed rather than the decision reopened: the absolute ban covers tokens, passwords, codes, receipts and full financial bodies; amounts are barred from informational level and above and from anything leaving the service. No new decision record is needed.
- Q: Does this feature ship the written baseline only, or also the runtime parts that do not need an Identity module? → A: **The written baseline and the commitments.** Identity builds sessions, passwords and verification; Billing builds provider-message verification; Sync builds retention and purge. Nothing here depends on a module that does not exist yet.

## Assumptions

- This baseline is written before the Identity module exists. It therefore states what every later feature must do, in the same way the API conventions do, rather than assuming a user table it can point at.
- The authentication mechanism is described by behaviour, not by package. Which library provides it, and whether that needs a new dependency approved by the repository owner, is a planning decision and not a requirement here.
- Second factors are for back-office operators only in MVP. Offering them to application users is desirable and is not in this scope.
- Verification gates writes, not reads. A person who has not yet clicked the link can still look at what they were invited to, which is what makes an invitation worth sending.
- Rate limiting reuses the header and refusal shapes already fixed by the API conventions; this feature sets the budgets and the lockout rule, not the wire format.
- The never-log list extends the one already recorded in the observability decision rather than restating or contradicting it.
- Export is a copy of what the person entered, not a database dump, and not other members' records in a shared wallet beyond what that person can already see.
- Back-office operators can act on a user's behalf through the same rules users are subject to. A back office that can bypass an invariant is out of scope and would need its own decision.
- Breach notification, penetration testing schedules, and formal compliance certification are not in this scope. They are operational commitments, not contract rules, and none is required to ship.
- User-facing commitments are a deliverable of this feature because the market objection they answer is the reason the feature is prioritised, not a marketing afterthought.
- The keyed lookup value is a trade, and the spec says so rather than implying perfect protection: it defeats an attacker who obtains the stored data, and it does not defeat one who obtains the data and the key together. FR-015 exists so this is stated per attribute instead of being flattened into "everything is encrypted".
- Restoring a deleted record is unchanged by the retention decision. Content survives the tombstone window, so a restore behaves exactly as the sync contract already defines it.
- The runtime parts named in FR-038b land as follows: Identity builds sessions, credentials, verification and the lockout rule; Billing builds provider-message verification; Sync builds retention and the purge job; the audit log's owner is a planning question, since more than one module writes to it.
