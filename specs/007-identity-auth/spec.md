# Feature Specification: Identity & Authentication

**Feature Branch**: `007-identity-auth`

**Created**: 2026-09-26

**Status**: Draft

**Input**: Brief B1 ([specs/briefs/B01-identity-auth.md](../briefs/B01-identity-auth.md)): "Users register with email and password, verify the email, log in from an Android device to receive a device-scoped token, see and revoke their device sessions, reset a forgotten password, log out, and request account deletion." Plus the brief's endpoint list, its considerations, and its reconcile table.

## Clarifications

### Session 2026-09-27

- Q: Should "you already have an account" be an API response rather than a mail? → A: **Yes, registration only.** Registering an address that already has an account answers `409 EMAIL_ALREADY_REGISTERED` and sends nothing. Sign-in, password reset and the lock keep answering uniformly. This accepts that registration can confirm whether an address has an account, limited by the 10-per-hour-per-origin budget. The baseline's Uniform answers section is amended to take registration out of the uniform set and record that risk.

### Session 2026-09-26

- Q: Does B1 include changing credentials while signed in? → A: **Password change only.** It revokes every other session (baseline A4), invalidates outstanding reset links, and mails the person. Address change is deferred to a later brief, so `address_change_requested` stays reserved and is not emitted here.
- Q: May an account in the deletion grace period sign in and keep using the app (baseline, Deletion rule 2), or is sign-in refused except to cancel (brief)? → A: A new sign-in during the grace period is allowed and **cancels the deletion request**. The person is told by mail, and operators are notified. Sessions that already exist keep working without cancelling anything, as baseline rule 2 says, so an export during the grace period still works. The brief's "deleted account cannot log in" line is superseded. The baseline's Deletion section and the privacy commitments' "Leaving" section are amended in the same change.

> **Nature of this feature.** This is the first feature that stores a person. It replaces the starter-kit user with the Identity module's User, and it builds the rules the [security baseline](../../docs/security-baseline.md) assigns to Identity: device sessions, passwords, reset and verification links, lockout, uniform answers, the verification gate, protected identity attributes, and the audit log. The baseline fixes the numbers (lifetimes, lock durations, hash cost). This spec references them and does not restate them. Where the brief and the baseline disagree, the baseline wins unless this spec says otherwise and names the amendment.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Create an account and sign in on a phone (Priority: P1)

A person installs the app, enters an email address and a password, and is told to check their mail. Straight away they sign in on that phone and land in the app. The phone now holds its own session. The account starts with a profile in the language their phone asked for, and on the free plan.

**Why this priority**: nothing else in the product works without an account and a session. It is the MVP on its own: a person can create an account, sign in, and be recognised on later requests.

**Independent Test**: register an address, sign in with a device description, and call an authenticated endpoint with the credential that came back. Registering the same address again must be refused with `409 EMAIL_ALREADY_REGISTERED` and change nothing.

**Acceptance Scenarios**:

1. **Given** an address with no account, **When** the person registers with a valid password, **Then** the answer is "accepted, check your mail". The account, its profile (language from the request) and its free-plan subscription exist together, or none of them do. A welcome mail with a verification link is queued.
2. **Given** an address that already has an account, **When** someone registers with it, **Then** the answer is `409 EMAIL_ALREADY_REGISTERED`, so the app can offer sign-in or a password reset. The existing account is unchanged and no mail is sent.
3. **Given** a registered account, **When** the person signs in with the right password and a device description, **Then** they receive a session credential for that device, their account summary, and their plan's capability summary.
4. **Given** a wrong password, or an address with no account, **When** someone signs in, **Then** both answer `401 INVALID_CREDENTIALS` in comparable time.
5. **Given** a phone that already holds a session, **When** it signs in again, **Then** the earlier session for that phone ends and a new credential is issued. The phone ends up with one working session, not two.
6. **Given** a session credential, **When** it is used, **Then** the request is recognised as that person on that device, and the session's inactivity window is extended.

---

### User Story 2 - Verify the address (Priority: P1)

The person opens the link in their welcome mail and the address is confirmed. Until then they can look around, manage their profile and their sessions, and read wallets they were invited to, but they cannot write to any wallet. If the link has expired, they ask for a new one from inside the app.

**Why this priority**: the baseline's verification gate depends on it, and every wallet write in later features checks it. A mistyped or hostile address must not be able to put entries into someone's shared wallet.

**Independent Test**: register, try an action behind the gate and see it refused with its own code, verify with the mailed link, then try again and see it pass. Replaying the same link must fail.

**Acceptance Scenarios**:

1. **Given** a fresh, unused verification link, **When** it is submitted, **Then** the address is marked verified and the link stops working.
2. **Given** a link that is expired, already used, or replaced by a later request, **When** it is submitted, **Then** the answer is `400 VERIFICATION_TOKEN_INVALID` and nothing changes.
3. **Given** a signed-in person with an unverified address, **When** they ask for the verification mail again, **Then** a new link is sent and every earlier link stops working. A fourth request inside one hour is refused with `429 RATE_LIMITED`.
4. **Given** an unverified address, **When** the person attempts an action behind the verification gate, **Then** it is refused with `403 EMAIL_NOT_VERIFIED`. Profile, session management and reads are not refused.

---

### User Story 3 - See and end device sessions, and sign out (Priority: P1)

The person opens "devices signed in" and sees each phone by name, platform, app version, when it signed in and when it was last used, with the current one marked. They end the session on a phone they lost. That phone's next request is refused, and every other phone keeps working. They can also sign out of the current phone, or end every session except the current one.

**Why this priority**: this is the part of privacy a person can test for themselves in thirty seconds (baseline, "What a person sees and controls"), and it is the only defence against a lost phone.

**Independent Test**: sign in from two devices, list sessions from one, end the other, and check that the ended credential fails on its very next request while the first keeps working.

**Acceptance Scenarios**:

1. **Given** two signed-in devices, **When** the person lists sessions, **Then** both appear with device name, platform, app version, sign-in time and last-used time. The current one is marked. Nothing else is shown.
2. **Given** two signed-in devices, **When** the person ends the other session, **Then** that credential is refused `401 UNAUTHENTICATED` on its next request, and the current session keeps working.
3. **Given** a signed-in device, **When** the person signs out, **Then** that credential stops working and every other session is untouched.
4. **Given** several sessions, **When** the person ends all others, **Then** every session except the current one stops working.
5. **Given** a session identifier that belongs to another person, **When** someone tries to end it, **Then** the answer is `404 NOT_FOUND` and the session is untouched.

---

### User Story 4 - Recover a forgotten password (Priority: P2)

The person taps "forgot password", enters their address, and is told a link is on its way if the address is registered. The link lets them set a new password. Every device is then signed out, and a lock from failed attempts is cleared.

**Why this priority**: without it, a forgotten password is a lost account. It follows sign-in because the first days of use rarely need it, but launch can't ship without it.

**Independent Test**: request a reset for a registered and an unregistered address and compare the answers. Complete a reset and check that old sessions fail, the lock is cleared, and the link cannot be reused.

**Acceptance Scenarios**:

1. **Given** any address, registered or not, **When** a reset is requested, **Then** the answer is `202` with the same body and comparable timing. A mail is sent only if the address is registered.
2. **Given** a valid reset link, **When** a new password is set, **Then** the password changes, every session of the account ends, the account lock (if any) is cleared, and every other outstanding reset link stops working.
3. **Given** a reset link that is expired, used, or replaced by a later request or a password change, **When** it is submitted, **Then** the answer is `400 RESET_TOKEN_INVALID` and nothing changes.

---

### User Story 5 - Resist password guessing (Priority: P2)

Someone tries passwords against an account. After five straight failures the account refuses sign-ins for fifteen minutes, then sixty after ten. The app shows "locked, try again in N minutes" rather than "wrong password". Someone spraying guesses across many accounts from one origin is cut off. A person who is locked out can get back in through a password reset, which the attacker can't do without their mailbox.

**Why this priority**: required before launch by the baseline, but it hardens a flow that already works.

**Independent Test**: fail sign-in repeatedly against one account and check the lock thresholds, the `Retry-After`, the automatic expiry and the reset. Fail across many addresses from one origin and check that the origin is refused.

**Acceptance Scenarios**:

1. **Given** five consecutive failed sign-ins on one address, **When** a sixth arrives (even with the right password), **Then** it is refused `423 ACCOUNT_LOCKED` with `Retry-After`, for the duration the baseline sets.
2. **Given** an address with **no** account, **When** it fails the same number of times, **Then** it locks in exactly the same way, so the lock cannot be used to find out whether an account exists.
3. **Given** a locked account, **When** the lock period passes, or a password reset completes, **Then** sign-in works again.
4. **Given** twenty failures across any addresses from one origin within fifteen minutes, **When** that origin tries again, **Then** it is refused for the period the baseline sets.
5. **Given** a successful sign-in, **When** it completes, **Then** that account's failure counter returns to zero.

---

### User Story 6 - Request account deletion and change one's mind (Priority: P2)

The person asks to delete their account, confirming with their password. They are told the account will be removed in 30 days and that they can cancel until then, either from the app or simply by signing in again. A confirmation mail says the same. Inside those 30 days they cancel, and everything is exactly as it was.

**Why this priority**: leaving is a feature, not a support request (privacy commitments), and store policy requires in-app deletion. The purge itself and what happens to shared wallets belong to B15; this story covers the request, the grace period and the cancellation.

**Independent Test**: request deletion, check the account's state and scheduled date, cancel it, and check that nothing was lost. Request it twice and check that the scheduled date did not move.

**Acceptance Scenarios**:

1. **Given** an active account, **When** the person requests deletion with their correct password, **Then** the account enters the deletion grace period, the scheduled purge date is 30 days away, and a confirmation mail is queued.
2. **Given** a wrong password, **When** deletion is requested, **Then** it is refused `401 INVALID_CREDENTIALS` and nothing changes.
3. **Given** an account already in the grace period, **When** deletion is requested again, **Then** the answer shows the existing scheduled date. The clock is not reset.
4. **Given** an account in the grace period, **When** the person cancels, **Then** the account returns to active with every record untouched.
5. **Given** an account in the grace period, **When** anyone signs in with the correct password, **Then** sign-in succeeds, the deletion request is cancelled, and the response says so. A mail tells the person that the deletion was cancelled by a sign-in (naming the device and the time), and advises a password reset if it was not them. Operators are notified.
6. **Given** an account in the grace period, **When** the person keeps using a session that already existed (reads, writes, export), **Then** everything works, the deletion stays pending, and its date does not move.
7. **Given** an account in the grace period, **When** a sign-in fails (wrong password, or locked), **Then** the deletion stays pending, and the failure counts toward the lockout as usual.

---

### User Story 7 - Change credentials while signed in (Priority: P3)

A signed-in person changes their password by entering the current one and a new one. Every other device is signed out, and the phone in their hand stays signed in. A mail tells them their password was changed. Changing the address is not part of this feature.

**Why this priority**: it is the baseline's one lever, "sign everything else out", for someone who suspects their password leaked but still has their phone. Launch can do without it, because the reset link covers the same need more slowly.

**Independent Test**: sign in from two devices, change the password from one, and check that the other device's credential fails on its next request while the changing device keeps working. Sign in with the old password (fails) and the new one (succeeds).

**Acceptance Scenarios**:

1. **Given** a signed-in person, **When** they change their password with the correct current password and a valid new one, **Then** the password changes, every session except the current one ends, every outstanding reset link stops working, and a "your password was changed" mail is queued.
2. **Given** a wrong current password, **When** a change is attempted, **Then** it is refused `401 INVALID_CREDENTIALS`, nothing changes, and the failure counts toward the lockout.
3. **Given** a locked account, **When** a change is attempted, **Then** it is refused `423 ACCOUNT_LOCKED` with `Retry-After`.
4. **Given** a new password that breaks the password rules or equals the current one, **When** a change is attempted, **Then** it is refused `422 VALIDATION_FAILED` and nothing changes.

---

### Edge Cases

- **Registration races.** Two registrations for the same address arrive at the same moment. Exactly one account is created. One caller gets `202` and the other `409 EMAIL_ALREADY_REGISTERED`.
- **Normalisation.** `Ali@Example.com ` and `ali@example.com` are the same account. `a.b@example.com` and `ab@example.com` are two accounts (baseline, Normalisation).
- **Registration fails partway.** If the profile or the free-plan subscription can't be created, no account exists afterwards, and the caller sees an ordinary server error that is safe to retry.
- **Mail delivery fails.** Registration and reset still answer `202`. Delivery is queued and retried, and the person can ask for the verification mail again.
- **Verify while signed out, or on another device.** A verification link works without a session, so it can be opened on a laptop while the phone is signed in.
- **Verify an already-verified address.** A still-valid link for an address that is already verified succeeds and changes nothing.
- **Sign-in retried after a lost response.** The phone sends the same session identifier again. The same session answers with a fresh credential, and no second session appears.
- **A session identifier already used by another person, or one that has ended.** The sign-in is refused without disclosing anything, and the other session is untouched.
- **Ending a session that has already ended.** Succeeds, and changes nothing.
- **Signing out with an ended credential.** `401 UNAUTHENTICATED`, like any other request with that credential.
- **A session unused for 90 days.** It expires, its next use is `401 UNAUTHENTICATED`, and it shows as expired, not active.
- **A lock that expires mid-attempt.** The first attempt after the lock period is judged on its password alone.
- **Password rehash.** A person whose stored password was hashed at a lower cost than the current setting signs in, and the hash is upgraded without them noticing (baseline, Passwords).
- **Deletion requested with other sessions active.** Those sessions are not ended by the request, and keep working without cancelling it. Only a new sign-in cancels.
- **Sign-in retried during the grace period.** The first sign-in cancels the deletion. The retry (same session identifier) finds the account already active, cancels nothing more, and sends no second mail or operator notification.
- **Someone else signs in during the grace period.** The deletion is still cancelled. The mail to the address is how the owner learns of it, and it advises a password reset, which ends every session.
- **Account purged after the grace period (B15).** Sign-in with that address answers `401 INVALID_CREDENTIALS`, and a new registration with it creates a fresh account.
- **`Accept-Language` absent or unsupported at registration.** The profile's language is English, per the API conventions' localisation fallback.
- **Google Sign-In called while switched off.** The endpoint answers not-found, as if it did not exist.

## Requirements *(mandatory)*

### Functional Requirements

**Account and registration**

- **FR-001**: The system MUST replace the starter-kit user with the Identity module's User. Its identifier is a UUIDv7 (P-06). Its address is stored encrypted, with the keyed lookup value beside it (baseline, What is protected at rest; Finding a person by address). No readable address is stored anywhere.
- **FR-002**: Registration MUST take an address, a password, and optionally a display name and an IANA timezone. A new address MUST be answered `202`.
- **FR-003**: For a new address, registration MUST create the User, its Profile (language from `Accept-Language`, `ar` or `en`, falling back to `en`; the timezone given, or the default in Assumptions) and its free-plan subscription as **one unit of work**. Either all three exist afterwards, or none does. The subscription is created through the Billing module's application service (P-02 rule 4). Until B3 exists, that call is a seam that B3 fills.
- **FR-004**: For an address that is already registered, registration MUST be refused with `409 EMAIL_ALREADY_REGISTERED`, MUST change nothing about the existing account, and MUST send nothing (clarified 2026-09-27; baseline, Uniform answers, as amended).
- **FR-005**: Every lookup by address (registration, sign-in, reset) MUST use the normalised keyed lookup value, normalised exactly as the baseline specifies.
- **FR-006**: A password MUST be 8 to 128 characters, with no composition rules, and MUST NOT equal the address. It MUST be hashed with the baseline's function and parameters and never returned by any endpoint.

**Address verification**

- **FR-010**: Registration of a new address MUST queue a welcome mail containing a verification link. The link's lifetime, single use and invalidation follow the baseline's token table.
- **FR-011**: Submitting a valid verification link MUST mark the address verified and record `address_verified`. It MUST work without a session.
- **FR-012**: A signed-in person with an unverified address MUST be able to ask for the verification mail again. Each request invalidates every earlier link. The baseline's resend limit applies (three per hour per address), refused with `429 RATE_LIMITED` and `Retry-After`.
- **FR-013**: The system MUST provide the verification gate as a reusable check that answers `403 EMAIL_NOT_VERIFIED` (baseline, Verification gate). This feature applies it to nothing of its own. Every later feature applies it to its wallet writes.

**Sign-in and device sessions**

- **FR-020**: Sign-in MUST take the address, the password, and a device description: a client-generated session identifier (UUIDv7), a device identifier (stable per app installation), device name, platform and app version. Only `android` is accepted as a platform in MVP.
- **FR-021**: A successful sign-in MUST return the session credential (shown this once only), the account summary (identifier, address, whether it is verified, account state, and the scheduled purge date if any), whether this sign-in cancelled a pending deletion (FR-054), and the plan's capability summary from the Billing module's entitlement service.
- **FR-022**: Sign-in MUST be idempotent per session identifier. Retrying with the same identifier for the same account returns the same session with a fresh credential, and the previous credential for it stops working. Signing in with a **new** session identifier from a device identifier that already has an active session for that account MUST end the earlier session.
- **FR-023**: A session identifier that belongs to another account, or to a session that has ended, MUST be refused without disclosing anything, and MUST NOT affect that session (P-06 rule 3).
- **FR-024**: Sessions MUST follow the baseline's Device sessions table: opaque credential, stored hashed, 90-day inactivity expiry extended on use, no absolute cap, revocation effective on the next request with no cache of validity.
- **FR-025**: A person MUST be able to list their own sessions, showing device name, platform, app version, sign-in time, last-used time, state and which one is current, and nothing further.
- **FR-026**: A person MUST be able to end one of their sessions, end every session except the current one, and sign out (end the current session). Ending one session MUST leave every other working. An ended session's record survives with its end time and reason (signed out, ended by owner, replaced, password changed, expired).
- **FR-027**: A session belonging to someone else MUST answer `404 NOT_FOUND` to every session operation.
- **FR-028**: Device Session is a user-scoped syncable entity (glossary). Sessions MUST carry the syncable members, so that ending a session on one device reaches the others through the user feed (B14).
- **FR-029**: After a successful sign-in the system MUST publish an event that the Billing module can listen to for restoring purchases (B10). This feature performs no restore itself.

**Password reset**

- **FR-030**: A reset request MUST answer `202` with the same body and comparable timing whether or not the address is registered, and MUST send a mail only if it is. The baseline's lifetime, single use and invalidation rules apply. The resend limit is three per hour per address, as for verification.
- **FR-031**: Completing a reset with a valid link MUST set the new password (FR-006), end **every** session of the account, clear any account lock immediately, invalidate every other outstanding reset link, and record `password_changed`. It MUST NOT sign the person in.

**Password change while signed in**

- **FR-035**: A signed-in person MUST be able to change their password by giving the current password and a new one that meets FR-006 and differs from the current one. A wrong current password is refused `401 INVALID_CREDENTIALS` and counts toward the lockout (FR-040). A locked account is refused `423 ACCOUNT_LOCKED`.
- **FR-036**: A successful change MUST, in one unit of work: set the new password, end every session of the account **except the one that made the change** (end reason: password changed), and invalidate every outstanding reset link. It MUST record `password_changed` and queue a "your password was changed" mail that advises a reset if the change was not theirs.
- **FR-037**: Changing the address is out of scope for this feature. `address_change_requested` stays reserved in the audit vocabulary for a later brief.

**Lockout and guessing**

- **FR-040**: Sign-in failures MUST be counted per account **and** per origin, with the thresholds, durations and 60-minute cap from the baseline's Lockout table. A refusal is `423 ACCOUNT_LOCKED` with `Retry-After`.
- **FR-041**: The account counter MUST be keyed by the address's lookup value, so an address with no account counts and locks exactly like one with an account. The lock MUST NOT reveal whether an account exists.
- **FR-042**: A successful sign-in MUST reset that account's counter. A completed reset MUST clear the lock (FR-031).
- **FR-043**: Sign-in with a wrong password and sign-in with an unregistered address MUST take comparable time (baseline, Uniform answers). So must reset requests for registered and unregistered addresses.
- **FR-044**: Registration MUST be throttled per origin at 10 requests per hour, refused with `429 RATE_LIMITED`. This budget, and the reset and resend limits, MUST be added to the baseline in the same change.

**Account deletion**

- **FR-050**: A signed-in person MUST be able to request deletion of their own account by confirming their current password. A wrong password is refused `401 INVALID_CREDENTIALS` and counts toward the lockout.
- **FR-051**: A deletion request MUST put the account into the deletion grace period with a purge date 30 days after the request, record `deletion_requested`, publish the deletion-requested event, and queue a confirmation mail that states the date and how to cancel.
- **FR-052**: Requesting deletion again during the grace period MUST return the existing purge date and MUST NOT move it.
- **FR-053**: Cancelling during the grace period, whether explicitly or by sign-in (FR-054), MUST return the account to active with nothing lost, record exactly one `deletion_cancelled` entry naming how it was cancelled (`explicit` or `sign_in`), and publish the deletion-cancelled event. Cancelling when no deletion is pending answers success and changes nothing.
- **FR-054**: A successful sign-in to an account in the grace period MUST cancel the deletion request, in the same unit of work as issuing the session. It MUST then queue a mail to the account's address, naming the device and the time and advising a password reset if the sign-in was not theirs, and notify operators (FR-056). A failed sign-in MUST NOT cancel anything.
- **FR-055a**: Sessions that already existed when deletion was requested MUST keep working during the grace period, writes and export included, without cancelling the request or moving its date (baseline, Deletion rule 2; Export rule 4).
- **FR-056**: The operator notification MUST carry the user identifier, the instant and the cancellation reason, and MUST NOT carry a readable address or any value from the audit exclusion list. It is delivered by the Admin module, which listens to the deletion-cancelled event. The channel (the back-office notifications slot from D1, an operations mailbox, or both) is chosen at planning. Until D2 gives operators accounts, the `deletion_cancelled` audit entry is the durable record.
- **FR-057**: This feature MUST amend, in the same change and in both languages where the text is bilingual: the baseline's Deletion section (a new sign-in during the grace period cancels the request, and the person and operators are notified) and the privacy commitments' "Leaving" section ("Signing in again within the 30 days cancels the deletion, and we will email you when it does"), including the promise-to-rule mapping row.
- **FR-055**: The purge after 30 days, ownership transfer of shared wallets, cancellation at payment providers and key-material destruction are out of scope here and belong to B15. This feature MUST record the purge date so B15 can act on it.

**Audit log**

- **FR-060**: The system MUST provide the single audit log the baseline assigns to Identity: its entry shape, the fixed event vocabulary, append-only storage, 400-day retention, and the exclusion list. Other modules write only through its application service.
- **FR-061**: This feature MUST record exactly one entry for each of: `sign_in_succeeded`, `sign_in_failed`, `lockout_applied`, `session_issued`, `session_revoked`, `password_changed`, `address_verified`, `deletion_requested` and `deletion_cancelled`. `address_change_requested` is not emitted (FR-037).
- **FR-062**: A failed sign-in on an unregistered address MUST be recorded without naming any person and without a readable address.

**Mail**

- **FR-070**: Every mail this feature sends (welcome and verify, reset, password changed, deletion confirmation, deletion cancelled by sign-in) MUST be queued, MUST be written in the Profile's language (or the request's language when there is no account), and MUST exist in both Arabic and English (P-14).
- **FR-071**: No mail, log line, audit entry or response MUST contain a password, a session credential or a readable link secret, except the one mail that carries its own link (baseline, What must never be logged).

**Google Sign-In (extension point)**

- **FR-080**: The contract for Google Sign-In MUST be specified (an ID assertion from Google, plus the same device description as FR-020, returning the same shape as FR-021), behind a switch that is **off** in MVP. While it is off, the endpoint MUST answer `404 NOT_FOUND`. Building the verification of Google's assertion is out of scope and needs its own dependency approval.
- **FR-081**: When it is built, Google Sign-In MUST NOT link to an existing account unless Google asserts that the address is verified. The linking rules are settled when the switch is turned on, not here.
- **FR-082**: Phone number and one-time-code sign-in are out of scope.

### Key Entities

- **User** (existing glossary entry): the person with an account. Holds the encrypted address and its lookup value, the password hash, when the address was verified, account state (`active`, `pending_deletion`, `purged`) and, while pending, the requested and scheduled purge instants.
- **Profile** (existing glossary entry): created at registration with language and timezone. Its fields and how it is edited belong to B2.
- **Device Session** (existing glossary entry): one device's session. Holds the device description, the credential hash, the sign-in, last-used and end instants, the state and the end reason.
- **Address verification link** and **password reset link**: single-use, time-limited, stored only as a hash. These are auth-layer records, not domain entities, like the session credential.
- **Sign-in failure counter**: per lookup value and per origin, with its lock expiry. Transient state, not a domain entity.
- **Audit Entry**: one recorded security or privacy event, shaped by the baseline. **New to the glossary** in this change.

## Project Sections *(mandatory for feature specs)*

### 1. Purpose

This feature lets a person own an account and reach it safely from their phone. They register, prove the address is theirs, sign in per device, see and end those sessions, recover a forgotten password, and start or cancel leaving. It also builds the Identity side of the security baseline, which every later feature relies on: the verification gate that wallet writes check, the protected identity attributes, and the one audit log that Sharing, Billing and Admin write through.

### 2. Glossary terms

- [User](../../docs/glossary.md#user), [Profile](../../docs/glossary.md#profile), [Device Session](../../docs/glossary.md#device-session): as defined. "Token" and "PAT" stay banned as product vocabulary. The words appear only inside the auth layer and in HTTP headers.
- **Audit Entry**: added to the glossary in this change (Context: Identity; Role: aggregate root; Persistence: persisted; Sync: no, operators read it through Admin; States: `single-state`, append-only).
- **User states**: the glossary entry's lifecycle is amended in this change to show the cancellation path: `active` ⇄ `pending_deletion` → `purged`.
- **Address**: the documents' word for the email address, following the baseline. The API field is `email`, and the reserved code keeps its name `EMAIL_NOT_VERIFIED`: codes are identifiers clients branch on, and the catalogue title already reads "Address not verified".
- **Operator**, **origin**, **lookup value**: as used by the [security baseline](../../docs/security-baseline.md).

### 3. Data model

All entities are owned by `app/Modules/Identity`.

| Entity | Key fields | Scope | Lifecycle |
|---|---|---|---|
| User | `id` (UUIDv7), `email_encrypted` + key id, `email_lookup` (unique), `password_hash`, `email_verified_at`, `state`, `deletion_requested_at`, `deletion_scheduled_for`, timestamps | itself | `active` ⇄ `pending_deletion` → `purged` |
| Profile | `id`, `user_id`, `locale`, `timezone`, `display_name_encrypted` + key id, syncable members | `user_id` | `single-state` (B2 owns its editing) |
| Device Session | `id` (client UUIDv7), `user_id`, `device_id`, `device_name`, `platform`, `app_version`, `credential_hash`, `signed_in_at`, `last_used_at`, `ended_at`, `end_reason`, syncable members | `user_id` | `active` → `revoked`; `active` → `expired` |
| Verification link / Reset link | `id`, `user_id`, `secret_hash`, `expires_at`, `used_at`, `superseded_at` | `user_id` | issued → used, expired or superseded |
| Audit Entry | the baseline's field table, unchanged | none (operator-read) | append-only |

Every instant is UTC (P-12). Every new attribute that is stored appears in the baseline's protected-attributes table, or is added to it in this change (baseline rule D1). The device name is the new one: stored unprotected, and read only by its owner.

### 4. Invariants

| Invariant | Principle or decision | Test that fails when broken |
|---|---|---|
| Registration creates User, Profile and free subscription together or not at all | FR-003 | `tests/Feature/Identity/RegistrationTest.php` |
| Registration refuses a registered address with `409 EMAIL_ALREADY_REGISTERED` and sends nothing | FR-004, clarified 2026-09-27 | `tests/Contract/Identity/RegisterTest.php` |
| One normalised address maps to one lookup value; no readable address is stored | Baseline, Finding a person by address; FR-001, FR-005 | `tests/Unit/Identity/AddressLookupTest.php`, `tests/Feature/Identity/AddressAtRestTest.php` |
| A password, credential or link hash is never returned by any endpoint | Baseline A5 | `tests/Contract/Identity/NoSecretsInResponsesTest.php` |
| An ended session fails on its very next request; others keep working | Baseline A2, A3; FR-024, FR-026 | `tests/Feature/Identity/SessionRevocationTest.php` |
| Re-sign-in from one device leaves exactly one active session for it | FR-022 | `tests/Feature/Identity/SignInIdempotencyTest.php` |
| A session identifier owned by another account is never affected or disclosed | P-06 rule 3; FR-023, FR-027 | `tests/Feature/Identity/SessionOwnershipTest.php` |
| An unregistered address locks exactly like a registered one | FR-041 | `tests/Feature/Identity/LockoutTest.php` |
| A lock caps at 60 minutes, and a completed reset clears it | Baseline B2, B3 | `tests/Feature/Identity/LockoutTest.php` |
| A verification or reset link works once, within its lifetime, and not after a later request | Baseline, Reset and verification tokens | `tests/Feature/Identity/VerificationLinkTest.php`, `tests/Feature/Identity/PasswordResetTest.php` |
| A completed reset ends every session of the account | FR-031 | `tests/Feature/Identity/PasswordResetTest.php` |
| A signed-in password change ends every session except the one that made it | Baseline A4; FR-036 | `tests/Feature/Identity/PasswordChangeTest.php` |
| The verification gate refuses with `EMAIL_NOT_VERIFIED` and lets reads, profile and sessions through | Baseline C1–C3; FR-013 | `tests/Feature/Identity/VerificationGateTest.php` |
| A repeated deletion request does not move the purge date | FR-052 | `tests/Feature/Identity/AccountDeletionTest.php` |
| Cancelling deletion loses nothing | Baseline G3; FR-053 | `tests/Feature/Identity/AccountDeletionTest.php` |
| A successful sign-in during the grace period cancels the deletion exactly once; a failed one, or use of an existing session, never does | FR-054, FR-055a | `tests/Feature/Identity/DeletionCancelledBySignInTest.php` |
| The operator notification carries no readable address | FR-056 | `tests/Feature/Identity/DeletionCancelledBySignInTest.php` |
| Each recorded act produces exactly one audit entry, with nothing from the exclusion list | Baseline F1, F3; FR-061 | `tests/Feature/Identity/AuditTrailTest.php` |
| Audit entries cannot be updated or deleted before retention | Baseline, Operational rules | `tests/Feature/Identity/AuditTrailTest.php` |
| Every mail exists in Arabic and English | P-14; FR-070 | `tests/Feature/Identity/MailTranslationTest.php` |
| Identity code imports no other module's Infrastructure or Http | P-02 | `tests/Arch` (existing gates) |

### 5. API

All under `/api/v1`, in `docs/openapi/paths/identity.yaml`, tag `Identity`. Unauthenticated operations declare `security: []`. Shapes live in the path file.

| Method | Path | Auth | Success | Error codes from the catalogue |
|---|---|---|---|---|
| POST | `/auth/register` | none | `202` | `VALIDATION_FAILED`, `RATE_LIMITED` |
| POST | `/auth/verify-email` | none | `200` | `VALIDATION_FAILED`, `VERIFICATION_TOKEN_INVALID`, `RATE_LIMITED` |
| POST | `/auth/resend-verification` | session | `202` | `UNAUTHENTICATED`, `RATE_LIMITED` |
| POST | `/auth/login` | none | `200` | `VALIDATION_FAILED`, `INVALID_CREDENTIALS`, `ACCOUNT_LOCKED`, `NOT_FOUND` (FR-023), `RATE_LIMITED` |
| GET | `/auth/sessions` | session | `200` | `UNAUTHENTICATED` |
| DELETE | `/auth/sessions/{id}` | session | `204` | `UNAUTHENTICATED`, `NOT_FOUND`, `INVALID_ID` |
| DELETE | `/auth/sessions` (all except current) | session | `204` | `UNAUTHENTICATED` |
| POST | `/auth/logout` | session | `204` | `UNAUTHENTICATED` |
| POST | `/auth/forgot-password` | none | `202` | `VALIDATION_FAILED`, `RATE_LIMITED` |
| POST | `/auth/reset-password` | none | `204` | `VALIDATION_FAILED`, `RESET_TOKEN_INVALID` |
| POST | `/auth/change-password` | session | `204` | `UNAUTHENTICATED`, `INVALID_CREDENTIALS`, `ACCOUNT_LOCKED`, `VALIDATION_FAILED` |
| POST | `/account/deletion-request` | session | `200` | `UNAUTHENTICATED`, `INVALID_CREDENTIALS`, `ACCOUNT_LOCKED`, `VALIDATION_FAILED` |
| DELETE | `/account/deletion-request` | session | `204` | `UNAUTHENTICATED` |
| POST | `/auth/google` | none | — | `NOT_FOUND` while switched off (FR-080) |

Every operation also carries the shared refusals (`MALFORMED_JSON`, `UNSUPPORTED_MEDIA_TYPE`, `NOT_ACCEPTABLE`, the client-version codes, `INSECURE_TRANSPORT`, `INTERNAL_ERROR`). None of these operations takes an `Idempotency-Key`: sign-in is idempotent by its session identifier, and the others are naturally repeatable. No new error code is needed.

### 6. Authorization and entitlements

No wallet roles apply. Every session operation is limited to the caller's own sessions, and anything else answers not-found (FR-027). The verification gate is built here and applied by later features to their wallet writes. No capability in this feature is gated on a plan. The sign-in response only **reports** the capability summary, taken from `EntitlementService` (P-09 rule 2). Operators have no route in this feature. Operator sign-in is D2.

### 7. Events

Dispatched after commit (P-11), carrying identifiers and scalars only.

| Event | Payload | Listeners |
|---|---|---|
| `UserRegistered` | user id, locale | Billing (none needed if FR-003 creates the subscription in the same unit of work; kept for later listeners) |
| `UserSignedIn` | user id, session id, platform | Billing, for restoring purchases (B10) |
| `DeviceSessionEnded` | user id, session id, reason | Notifications, to drop the push registration (B16) |
| `UserDeletionRequested` | user id, scheduled purge instant | B15 |
| `UserDeletionCancelled` | user id, reason (`explicit` \| `sign_in`), session id when by sign-in | Admin, to notify operators when the reason is `sign_in` (FR-056); Identity, to send the person's mail; B15 |

The constitution's Identity row lists `UserRegistered` and `UserDeletionRequested`. The other three are added to that row in this change, as a PATCH amendment.

### 8. Acceptance scenarios

| Story · scenario | Layer | Test file |
|---|---|---|
| US1 · 1, 2 registration, 409 for an existing address, one unit of work | Contract, Feature | `tests/Contract/Identity/RegisterTest.php`, `tests/Feature/Identity/RegistrationTest.php` |
| US1 · 3, 4 sign-in success and uniform failure | Contract | `tests/Contract/Identity/LoginTest.php` |
| US1 · 5 re-sign-in from the same device | Feature | `tests/Feature/Identity/SignInIdempotencyTest.php` |
| US1 · 6 credential recognised, expiry extended | Feature | `tests/Feature/Identity/SessionLifetimeTest.php` |
| US2 · 1–3 verification and resend | Contract, Feature | `tests/Contract/Identity/VerifyEmailTest.php`, `tests/Feature/Identity/VerificationLinkTest.php` |
| US2 · 4 verification gate | Feature | `tests/Feature/Identity/VerificationGateTest.php` |
| US3 · 1–5 list, end, sign out, others' sessions | Contract, Feature | `tests/Contract/Identity/SessionsTest.php`, `tests/Feature/Identity/SessionRevocationTest.php` |
| US4 · 1–3 reset | Contract, Feature | `tests/Contract/Identity/PasswordResetTest.php`, `tests/Feature/Identity/PasswordResetTest.php` |
| US7 · 1–4 password change while signed in | Contract, Feature | `tests/Contract/Identity/PasswordChangeTest.php`, `tests/Feature/Identity/PasswordChangeTest.php` |
| US5 · 1–5 lockout | Feature | `tests/Feature/Identity/LockoutTest.php` |
| US6 · 1–4 deletion request and cancel | Contract, Feature | `tests/Contract/Identity/AccountDeletionTest.php`, `tests/Feature/Identity/AccountDeletionTest.php` |
| US6 · 5–7 sign-in cancels, existing sessions and failures do not | Contract, Feature | `tests/Contract/Identity/LoginTest.php`, `tests/Feature/Identity/DeletionCancelledBySignInTest.php` |
| Normalisation and lookup | Unit | `tests/Unit/Identity/AddressLookupTest.php` |
| Password rules | Unit | `tests/Unit/Identity/PasswordPolicyTest.php` |
| Comparable timing (FR-043) | Feature | `tests/Feature/Identity/UniformTimingTest.php` |

### 9. Open questions

1. ~~**Q1 — Using the account during the deletion grace period.**~~ Decided 2026-09-26: a new sign-in cancels the request, and the person and operators are notified (see Clarifications; FR-054 to FR-057).
1a. ~~**Operator notification channel.**~~ Decided at planning, 2026-09-26: both. The operators get an email and a dashboard notification in the shell's notifications slot (plan R-14).
2. ~~**Q2 — Credential changes while signed in.**~~ Decided 2026-09-26: password change only (US7; FR-035 to FR-037). Address change needs a later brief. Not blocking.
3. ~~**Authentication package.**~~ Decided at planning, 2026-09-26: Laravel Sanctum, approved by the owner and used as a bearer-only guard (plan R-1).
4. ~~**Session revocation against the syncable write rules.**~~ Resolved in the plan (R-19). Device Session has no client write operation, and its transitions are idempotent server commands, so P-07 rule 2 does not apply.
5. **Mail transport.** Which mail service delivers in production is chosen with the deployment target (plan R-18). Mailpit is used locally. Does not block.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A new person goes from an empty app to signed in within 2 minutes, with no wait on mail delivery.
- **SC-002**: 100% of requests made with an ended credential are refused, starting with the first request after it was ended.
- **SC-003**: For sign-in and reset, a registered and an unregistered address produce identical status and body in 100% of trials. Over 1,000 trials each, their median response times differ by less than 10%.
- **SC-004**: Apart from registration, which answers `409` for a registered address by owner decision and is limited to 10 attempts per hour per origin, an attacker holding a list of addresses learns nothing about which are registered from any response, lock or mail sent to them, judged against the baseline's attacker test.
- **SC-005**: A locked-out person regains access through a password reset in under 5 minutes, without contacting support.
- **SC-006**: 0 readable addresses, passwords, credentials or link secrets appear in stored data, logs, audit entries or error reports, confirmed by inspecting a test deployment after the full scenario suite has run.
- **SC-007**: A cancelled deletion restores the account with 0 records lost or changed.
- **SC-008**: Every act in the audit vocabulary that this feature performs produces exactly one entry, in 100% of scenario runs.
- **SC-009**: Every mail and every user-visible message this feature produces is available in Arabic and English, with 0 missing translations.

## Assumptions

- The Android app is the only client that signs in during MVP. The website does not sign in to the API until a later feature needs it (for example InstaPay claims, B11).
- A verification or reset link opens a page or app screen that submits its secret to the API. The shape of that landing page belongs to the client and the website, not this spec.
- The default timezone for a profile created without one is `Africa/Cairo`, since Egypt is the launch market. B2 lets the person change it.
- The password policy (8–128 characters, no composition rules, not equal to the address) follows current NIST guidance. Checking against breached-password lists would need an external service or a new dependency, and is left for later.
- The registration budget of 10 per hour per origin is a starting value, tunable by configuration and recorded in the baseline.
- B3 lands soon after this feature. Until then, the free-plan subscription call (FR-003) and the capability summary (FR-021) go through seams. The summary reports the Free plan's capabilities, which in MVP differ only on sharing (P-09 rule 5).
- Mail is delivered through the application's queue. A failed delivery is retried, and never changes an answer already given.
- Operator (back-office) accounts are entirely separate and out of scope (P-08 rule 4; D2).
