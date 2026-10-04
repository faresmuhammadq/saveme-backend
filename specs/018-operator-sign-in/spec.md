# Feature Specification: Operator Sign-in

**Feature Branch**: `018-operator-sign-in` (work lands on `main`; no branch is created)

**Created**: 2026-10-04

**Status**: Draft

**Input**: Brief D2 ([specs/briefs/D02-operator-sign-in.md](../briefs/D02-operator-sign-in.md)): "Operators sign in to the back office with email and password, then a time-based one-time code (the second factor). Ten single-use recovery codes are issued at enrolment and shown once. When they are exhausted, two other operators must approve a re-enrolment. An artisan command creates the first operator. Every back-office route, including Livewire's update endpoint when it serves a back-office component, sits behind the separate admin guard and its second factor." Plus the brief's considerations and reconcile table, and spec 006 FR-051 (the shell's operator and sign-out inputs).

> **Nature of this feature.** The lock on the back office. Spec 006 built the shell and its primitives and deliberately registered no `/admin` route except the local gallery. This feature adds the **Operator Account**, the separate operator guard, the two-step sign-in, enrolment of the second factor, recovery codes, the two-operator re-enrolment, the operator's own security page, and the rule that every later back-office screen (B3 plan catalog, B11 claim review, B12 operations) inherits the guard without doing anything. The [security baseline](../../docs/security-baseline.md) sections "Operator access" and "Second-factor recovery" fix the rules; this spec implements them as written and names every amendment it makes.
>
> | Brief says | Already decided | Resolved here |
> |---|---|---|
> | Second factor and recovery | Baseline "Operator access" and "Second-factor recovery" | Implemented as written: six-digit time-based codes, 30-second step, one step of drift, mandatory enrolment, ten single-use recovery codes shown once, two other operators approve a re-enrolment |
> | Separate guard | ADR-0006 rules 7–8, P-08 rule 4, P-18 rule 4 | A separate `operator` guard over Operator Accounts owned by Admin; application credentials never reach `/admin` |
> | Route surface | Spec 006 `AdminRouteSurfaceTest`: only the local gallery | The test is updated to the guarded surface this spec defines |
> | One-time-code implementation: package or in-house | No new dependency without approval (CONTRIBUTING item 17) | Decided at planning; this spec states only the behaviour |
> | "Screen · Operator sign-in" in the design source | Design source boards `SignIn` and `SignInAr` draw step 2 only | Step 2 is built to the board; the other sign-in, enrolment and security screens are composed from the spec 006 primitives in the same split layout |

## Clarifications

### Session 2026-10-04

Owner away with delegated authority; each answer below is the recommended (most conservative) option, recorded as an **assumed default**.

- Q: Does the artisan command create only the first operator, or every operator, and how are passwords and departures handled without a back-office screen? → A: **Every operator is created by the artisan command** (no public sign-up, no in-app invitation in this feature; an operators screen is B12 work). The command prints a generated temporary password once, and the operator must replace it at first sign-in. Two more commands exist: one deactivates an operator (refusing the last active one), one reissues a temporary password for a forgotten password (the second factor is untouched). There is **no mailed self-service reset**: a mailed link would be a weaker path than the two factors it guards. Rationale: smallest surface; whoever runs artisan on the deployment already controls it. *Assumed default.* (FR-003 to FR-005, FR-063)
- Q: When may a re-enrolment request be opened, how long does it live, and what must an approver do? → A: **Any time after a correct password at step 2 ("Lost your device?")**, not only once codes are exhausted, because a lost paper of codes is the same situation and the path still needs two other operators. One open request per operator; it **expires after 72 hours**; another operator may **decline** it. Each approver must be a different, active, fully signed-in operator who **re-confirmed a code within the last 10 minutes** and gives a **reason** through the spec 006 reason prompt. The second approval invalidates the old secret and every recovery code, ends all the requester's sessions, and leaves the account awaiting enrolment; new recovery codes are issued at that enrolment. Rationale: the baseline's two-approver rule, with the approval itself as hard to forge as any other sensitive act. *Assumed default.* (FR-030 to FR-034)
- Q: How long does an operator session last? → A: **30 minutes idle, 12 hours absolute**, a half-finished sign-in (password only) 5 minutes, and **no "remember this device"**. Every request re-checks the operator's state and credential epoch, so a password change elsewhere, a deactivation or a completed re-enrolment signs the session out on its next request. Rationale: the back office is the most powerful surface; a working day fits in 12 hours. *Assumed default.* (FR-040 to FR-042, FR-014)
- Q: Which audit events do operator sign-in acts use, given the vocabulary is fixed and `subject_type` today is `user | wallet | session | subscription`? → A: **Reuse the access vocabulary for access acts** — `sign_in_succeeded`, `sign_in_failed`, `lockout_applied`, `session_revoked` (sign-out), `password_changed` — with a **new subject type `operator`**, and **`admin_action` with a closed `action` detail** for everything else (operator created, deactivated, temporary password reissued, enrolment completed, recovery codes regenerated, authenticator replaced, re-enrolment requested, approved, declined, completed, expired, cancelled). Operator acts carry `actor_type = operator` and the operator's UUID as `actor_id`; failures and command-line acts carry `actor_type = system` (as Identity does for user failures). The baseline's audit-entry table (subject types) and "What is recorded" table (Admin writes the access events whose subject is an operator) are amended in the same change, and Identity's `audit_entries_subject_type_check` constraint gains `operator` by an Identity migration. Rationale: no new event names; "one writer per event" stays true per subject type; approvals are `admin_action` exactly as the baseline says. *Assumed default.* (FR-080 to FR-082)
- Q: What counts as "forced re-authentication" for sensitive actions? → A: **A second-factor proof in the last 10 minutes**: a fresh one-time code (or an unused recovery code, which is consumed), entered in a dialog; the sign-in's own second factor counts. Password change additionally asks for the current password. Re-confirmation shares the lockout counter. Later features opt their own actions in with one marker. Rationale: a borrowed, unlocked browser cannot turn into a permanent takeover; the code proves the device, the stronger factor. *Assumed default.* (FR-060 to FR-062)
- Q: Are the operator's email and display name protected at rest like a user's? → A: **Yes**: email and display name encrypted (authenticated, per-record nonce, key identifier beside the value) with a keyed lookup value for the email, under **Admin's own keyring and lookup key**, separate from Identity's; the second-factor secret encrypted the same way; recovery codes stored as keyed one-way values. Added as rows to the baseline's "What is protected at rest". Rationale: baseline rule 1 ("an attribute not in this table carries no protection claim") and consistency with users. *Assumed default.* (FR-026)
- Q: Does the local gallery, and the language switch, stay reachable without signing in? → A: **Yes**: the gallery stays local-only and unguarded (spec 006 FR-041; it reads no data), and the language switch works on the sign-in pages. Every other back-office route and component requires a full sign-in. *Assumed default.* (FR-070)
- Q: The design board says "Re-enrolling … needs **a second operator** to approve"; the baseline says **two other operators**. Which wins? → A: **The baseline.** The copy reads "Re-enrolling a lost authenticator needs two other operators to approve." (Arabic to match). The design source is otherwise built as drawn. *Assumed default.*
- Q: Is the operator's language a stored preference now? → A: **No, it stays session-scoped** (spec 006). Stored language, timezone, and per-operator read state for Operator Notifications are later work. *Assumed default.*
- Q: How is the authenticator linked without a QR library? → A: Behaviour only here; planning decides the means within "no new dependency": the setup page always shows the setup key and setup link, and a scannable picture appears only if it needs no new dependency. *Assumed default; see plan research.* (FR-021)

## User Scenarios & Testing *(mandatory)*

Every scenario below is written so it can be driven in a browser (Playwright) against the running back office: each names the page the operator is on, what they type or press, and what they see.

### User Story 1 - Sign in with password and one-time code (Priority: P1)

Mariam is an enrolled operator. She opens `/admin`, is sent to the sign-in page, enters her work email and password, and is taken to step 2. She opens her authenticator app, types the current six-digit code and presses "Verify & sign in". She lands on the back-office overview, and the sidebar's operator block shows her name and "Two-factor on".

**Why this priority**: without it no back-office screen can ship (B11 and B12 depend on it), and the constitution forbids any back-office route without the guard and its second factor.

**Independent Test**: create an operator with the artisan command, complete enrolment once, sign out; then sign in with password and a code computed from the stored secret, and confirm the overview loads; confirm the same request without the code step is refused.

**Acceptance Scenarios**:

1. **Given** a signed-out visitor, **When** they open any `/admin` page, **Then** they are sent to the sign-in page (step 1 of 2), and after signing in they are taken back to the page they asked for.
2. **Given** an enrolled operator on step 1, **When** they submit the correct email and password, **Then** they see step 2 ("Step 2 of 2 · Second factor", "Enter your 6-digit code", their email, a "Not you?" link, a countdown to the next code, "Use a recovery code" and "Lost your device?"), and no back-office page is reachable yet.
3. **Given** step 2, **When** they enter the current code, **Then** they are signed in and see the overview with their name and "Two-factor on" in the operator block.
4. **Given** step 2, **When** they enter a code from the previous or next 30-second step, **Then** it is accepted; a code two or more steps away is refused.
5. **Given** step 2, **When** they enter a wrong code, **Then** they stay on step 2 with the message "That code didn't match. Try the current code, or use a recovery code.", and the digits are cleared.
6. **Given** a code that was already used to sign in, **When** the same code (or any code of the same or an earlier step) is entered again for the same operator, **Then** it is refused as wrong, even within its 30 seconds.
7. **Given** step 1, **When** the email is unknown or the password is wrong, **Then** the page shows the same message, "Email or password is incorrect.", in comparable time, with no hint which part was wrong.
8. **Given** step 2, **When** the operator presses "Not you?", **Then** the half-finished sign-in is discarded and step 1 is shown with empty fields.
9. **Given** step 2 left open for more than 5 minutes, **When** a code is submitted, **Then** step 1 is shown with "Your sign-in timed out. Start again."
10. **Given** a person holding only a valid application (mobile) credential, **When** they send it to any `/admin` page or to the component update endpoint for a back-office component, **Then** they are treated as signed out and never see back-office content.

---

### User Story 2 - First operator and first enrolment (Priority: P1)

The person who deploys Save Me runs the command that creates an operator, giving an email and a display name. The command prints a temporary password once. The new operator signs in with it, is made to choose their own password, then is made to enrol: the page shows a setup key (in groups of four) and a setup link they can paste into an authenticator app, asks for the first code to confirm, then shows ten recovery codes once and asks them to confirm they saved them. Only then do they reach the overview.

**Why this priority**: there is no other way to get the first operator, and the baseline makes enrolment mandatory: an operator that has not enrolled can do nothing.

**Independent Test**: run the command on an empty operator table, sign in with the printed password, change it, enrol with a code computed from the shown key, see the ten codes, confirm, reach the overview; then confirm none of the steps can be skipped by typing a back-office address.

**Acceptance Scenarios**:

1. **Given** the command run with an email and a display name, **When** it finishes, **Then** it prints the temporary password once, the operator exists in "awaiting password change" state, and an audit entry records the creation with the system as actor.
2. **Given** an email that already belongs to an operator, **When** the command is run with it, **Then** it refuses and changes nothing.
3. **Given** a new operator who signed in with the temporary password, **When** they open any back-office page, **Then** they are sent to "Choose a new password" and nothing else is reachable.
4. **Given** "Choose a new password", **When** they submit a password shorter than 12 characters, equal to their email, or equal to the temporary password, **Then** it is refused with a message naming the rule broken.
5. **Given** a valid new password, **When** it is saved, **Then** they are sent to "Set up your authenticator" showing the setup key, the setup link, the account label and an input for the six-digit code.
6. **Given** the setup page, **When** they enter a wrong code, **Then** enrolment is not completed and they may try again with the same key; **When** they enter a correct code, **Then** the authenticator is linked.
7. **Given** a linked authenticator, **When** the next page loads, **Then** it shows ten recovery codes once, with "Copy all" and "Download as text", and a required checkbox "I have saved these codes somewhere safe" before "Continue".
8. **Given** the recovery-codes page was left without confirming (closed tab, sign-out), **When** the operator later signs in, **Then** the same codes are never shown again; the operator is told they can generate a new set from their security page.
9. **Given** an operator awaiting a password change or awaiting enrolment, **When** they try to reach any other back-office page or component, **Then** they are sent back to the step they have not finished.

---

### User Story 3 - Recover with a recovery code (Priority: P1)

Karim's phone is at home. At step 2 he presses "Use a recovery code", types one of his ten codes, and is signed in. The page tells him how many codes he has left. Later, from his security page, he links a new authenticator.

**Why this priority**: the baseline requires recovery to exist; without it a lost phone locks an operator out until two colleagues act.

**Independent Test**: enrol, sign in with one recovery code, confirm it is consumed (a second use is refused) and the remaining count is nine.

**Acceptance Scenarios**:

1. **Given** step 2, **When** the operator presses "Use a recovery code", **Then** a single field "Recovery code" replaces the six digits, with a link back to "Use your authenticator code".
2. **Given** an unused recovery code, typed with or without the hyphen and in any letter case, **When** submitted, **Then** the operator is signed in and sees "Signed in with a recovery code. You have 9 of 10 left."
3. **Given** a recovery code already used, **When** submitted again, **Then** it is refused like a wrong code.
4. **Given** an operator with 3 or fewer unused codes, **When** they are signed in, **Then** the security page and a dismissible alert on the overview say how many are left and offer to generate a new set.
5. **Given** step 2 for an operator with no unused recovery codes, **When** they press "Use a recovery code", **Then** they are told none are left and are pointed to "Lost your device?".

---

### User Story 4 - Two other operators approve a re-enrolment (Priority: P2)

Nour lost her phone and her recovery codes. At step 2 she presses "Lost your device?", confirms she wants to ask for a re-enrolment, and sees "Request sent. Two other operators must approve it." Two colleagues each open "Re-enrolment requests" in the back office, re-confirm their own code, give a reason and approve. On her next sign-in, after her password, Nour is taken straight to "Set up your authenticator" and receives new recovery codes.

**Why this priority**: the baseline fixes this as the only way back when codes are gone; it must exist before go-live but is exercised rarely.

**Independent Test**: with three enrolled operators, have one request a re-enrolment after the password step, approve as the other two (each with re-confirmation and a reason), and confirm the requester's old code and old recovery codes stop working and the next sign-in leads to enrolment.

**Acceptance Scenarios**:

1. **Given** step 2, **When** the operator presses "Lost your device?" and confirms, **Then** a re-enrolment request is opened for them, the page says two other operators must approve within 72 hours, and the half-finished sign-in ends.
2. **Given** an operator with an open request, **When** they ask again, **Then** no second request opens; they are told one is already waiting and when it expires.
3. **Given** a signed-in operator who is not the requester, **When** they open "Re-enrolment requests", **Then** they see each open request with the requester's display name, when it was opened, when it expires and how many approvals it has (0 or 1), and nothing about the requester's factor.
4. **Given** an open request, **When** an operator approves it, **Then** they must have confirmed a fresh code within the last 10 minutes (otherwise they are asked for one first) and must give a reason in the reason prompt; the approval is recorded with the reason.
5. **Given** an open request, **When** the requester themselves, or an operator who already approved it, tries to approve it, **Then** the approve action is not offered and a forged attempt is refused.
6. **Given** the second distinct approval, **When** it is recorded, **Then** the request is complete, the requester's authenticator and every recovery code stop working at once, every session of the requester ends, and their account is awaiting enrolment.
7. **Given** a completed request, **When** the requester signs in with their password, **Then** they go straight to "Set up your authenticator", and after confirming a code they receive ten new recovery codes (US2 scenarios 5–7).
8. **Given** an open request older than 72 hours, **When** anyone views or approves it, **Then** it is shown as expired and cannot be approved; the requester may open a new one.
9. **Given** an open request, **When** an operator declines it with a reason, **Then** it closes as declined, and the requester may open a new one.
10. **Given** fewer than two other active, enrolled operators exist, **When** a request is opened, **Then** the requester is told it cannot complete until enough operators exist, and the request still opens.

---

### User Story 5 - Manage my own sign-in security (Priority: P2)

From the operator block, Mariam opens "Security". She sees when her authenticator was linked, how many recovery codes are left, and her last sign-in time. She can change her password, generate a new set of recovery codes, or link a new authenticator (for a new phone). Each of these first asks for a fresh code if she has not given one in the last 10 minutes.

**Why this priority**: an operator must be able to rotate their own credentials without help; and the "forced re-confirmation" rule protects the actions that would let a borrowed session become a permanent takeover.

**Independent Test**: sign in, wait past the re-confirmation window (or simulate it), attempt each action, confirm the code prompt appears, then complete each action and confirm its effect and its audit entry.

**Acceptance Scenarios**:

1. **Given** a signed-in operator, **When** they open "Security", **Then** they see the authenticator's linked date, "N of 10 recovery codes left", their last sign-in time in their timezone, and the three actions.
2. **Given** the last code confirmation is older than 10 minutes, **When** they start any of the three actions, **Then** a dialog asks for the current code (or a recovery code) first; a correct one opens the action, a wrong one counts as a failed attempt.
3. **Given** "Change password", **When** they submit the current password and a valid new one, **Then** the password changes, every other session of theirs ends, and this session stays signed in.
4. **Given** "Generate new recovery codes", **When** confirmed, **Then** ten new codes are shown once (as in US2 scenario 7) and every earlier code stops working.
5. **Given** "Link a new authenticator", **When** they complete the setup page with a code from the new app, **Then** the old authenticator stops working and recovery codes stay as they were; **When** they abandon it, **Then** the old authenticator keeps working.
6. **Given** a sign-in completed with a recovery code less than 10 minutes ago, **When** they choose "Link a new authenticator", **Then** no further code is asked for.

---

### User Story 6 - Sign out and session limits (Priority: P2)

Mariam presses "Sign out" in the operator block and is back on step 1. On another day she leaves the back office open over lunch; when she returns after 30 minutes idle, her next click takes her to step 1 with "You were signed out after 30 minutes without activity." Even when active, she must sign in again after 12 hours.

**Why this priority**: an unattended back-office tab is the cheapest way into the back office.

**Independent Test**: sign in, sign out, confirm the back button and the old session cookie reach nothing; sign in, advance the clock 31 minutes, confirm the next request (page or component update) is signed out.

**Acceptance Scenarios**:

1. **Given** a signed-in operator, **When** they press "Sign out", **Then** the session ends, an audit entry records it, step 1 is shown, and replaying the old session cookie reaches nothing.
2. **Given** no request for 30 minutes, **When** the next page or component request arrives, **Then** the operator is signed out and step 1 shows the idle message.
3. **Given** 12 hours since sign-in, **When** the next request arrives, **Then** the operator is signed out regardless of activity, with "Your session reached its 12-hour limit. Sign in again."
4. **Given** an operator whose account was deactivated, whose password changed elsewhere, or whose re-enrolment completed, **When** their existing session makes its next request, **Then** it is signed out at once.

---

### User Story 7 - Lockout and throttling (Priority: P2)

Someone tries passwords against an operator's email. After 5 consecutive failures the account refuses sign-in for 15 minutes; after 10, for 60. Wrong codes at step 2 count the same way. Many failures from one network across any operator emails close that network out for 15 minutes.

**Why this priority**: the second factor makes a guessed password insufficient, but the lock keeps the code from being brute-forced and keeps the password from being confirmed.

**Independent Test**: fail 5 times against one email and confirm the sixth attempt, even with the right password, shows the lock message; fail 20 times across many emails from one origin and confirm the origin is refused.

**Acceptance Scenarios**:

1. **Given** 5 consecutive failures (wrong password or wrong code) on one operator email, **When** a sixth attempt arrives within 15 minutes, **Then** it is refused with "Too many attempts. Try again in N minutes." whether or not the password is right.
2. **Given** 10 consecutive failures, **Then** the refusal lasts 60 minutes, never longer.
3. **Given** an unknown email, **When** it fails 5 times, **Then** it is locked in exactly the same way, so the lock reveals nothing about which emails are operators.
4. **Given** 20 failures from one origin across any emails within 15 minutes, **When** another attempt arrives from that origin, **Then** it is refused for 15 minutes.
5. **Given** a successful sign-in (both steps), **Then** the account's failure counter resets.
6. **Given** 5 wrong codes in one half-finished sign-in, **Then** that sign-in is discarded and the operator starts again at step 1.

---

### User Story 8 - Every later screen is guarded without doing anything (Priority: P1)

A developer adds the claim-review screen (B11) by registering a route and a component. Without writing any sign-in code, the screen is unreachable unless the operator completed both steps, and its component updates are refused the moment the session ends.

**Why this priority**: the constitution (P-18 rules 4 and 7) makes this the feature's real deliverable for every later spec.

**Independent Test**: register a fixture route and component under the back office in a test, and confirm a signed-out visitor, a password-only session and an application credential all fail to reach both.

**Acceptance Scenarios**:

1. **Given** a new route registered in the back-office route group, **When** it is requested by anyone not fully signed in, **Then** it is refused like every other back-office page.
2. **Given** a back-office component on a page, **When** the session ends and the component sends an update, **Then** the update is refused and the browser is sent to step 1.
3. **Given** the list of routes under `/admin`, **When** the route-surface test runs, **Then** every route except the sign-in, enrolment and recovery steps and the local gallery requires a fully signed-in operator.

---

### Edge Cases

- **Same code twice in one step** (an operator signs in on two browsers within 30 seconds): the second use of the same time step is refused; they wait for the next code.
- **Clock drift**: a phone more than 30 seconds off is refused; the message suggests checking the phone's time.
- **Account deactivated mid-sign-in**: the half-finished sign-in is refused at step 2 like a wrong password, with no disclosure.
- **Deactivated operator with an open re-enrolment request**: the request closes as cancelled.
- **Approver's account deactivated after approving**: the approval stands; it was valid when given.
- **Two approvals arriving at the same moment** from two operators: both are recorded once each; the request completes exactly once.
- **Operator signs in on two browsers**: both sessions work; signing out of one leaves the other working; a password change ends the other.
- **Recovery codes page reloaded or left**: the codes appear only in the one response that generated them. A reload never shows them again (the enrolment is already complete); the operator signs in normally and can generate a new set from the security page.
- **Temporary password never used**: it stops working after 72 hours (FR-004a); the deployer re-runs the reset command to issue a new one (FR-063).
- **Lock while signed in**: a lock on an email refuses new sign-ins only; a session already signed in keeps working (its own limits still apply), and its re-confirmations count against the same lock.
- **Who approves**: approvers are expected to verify the requester's identity out of band (a call, in person) before approving; the reason prompt records how. The requests page shows a count badge in the navigation so waiting requests are noticed.
- **Last active operator deactivated**: refused; at least one active operator always remains.
- **Language**: every screen in this feature works in English and Arabic, right-to-left for Arabic, with the codes, setup key and email shown left-to-right and in Western numerals.
- **Back button after sign-out**: pages are not served from the browser cache (no-store), so the back button shows nothing from the session.
- **Browser with JavaScript disabled**: step 1 and step 2 still submit as plain forms; the six digit boxes degrade to one field.

## Requirements *(mandatory)*

### Functional Requirements

**Operator accounts and provisioning**

- **FR-001**: The system MUST keep operator accounts entirely separate from application users: their own records, their own guard, and no flag or role on application users (ADR-0006 rules 7–8).
- **FR-002**: An operator account MUST hold an email (unique, compared after the baseline's normalisation), a display name (1 to 80 characters), a password, a second-factor secret once enrolled, recovery codes, and a state.
- **FR-003**: There MUST be no public sign-up. Operators MUST be created only by an artisan command run by whoever operates the deployment, taking an email and a display name and printing a generated temporary password once.
- **FR-004**: A newly created operator MUST change the temporary password at first sign-in, then enrol, before any other back-office page or component is reachable.
- **FR-004a**: A temporary password MUST stop working 72 hours after it is issued; the command then issues a new one.
- **FR-005**: An artisan command MUST deactivate an operator, ending all their sessions on the next request. It MUST refuse to deactivate the last active operator. A deactivated operator cannot sign in. Reactivation is out of scope.
- **FR-006**: Every operator MUST have the same authority (one global role, platform admin; owner decision 2026-09-25). There are no finer roles.

**Sign-in**

- **FR-010**: Sign-in MUST be two steps: email and password, then a six-digit time-based code (or a recovery code). A correct password alone MUST NOT reach any back-office page or component.
- **FR-011**: Codes MUST follow the baseline: six digits, 30-second step, one step of drift tolerated either side.
- **FR-012**: A code MUST be accepted at most once per operator: a code whose time step is the same as or earlier than the last accepted step for that operator MUST be refused.
- **FR-013**: Step 1 MUST answer an unknown email and a wrong password with the same message, in comparable time.
- **FR-014**: A half-finished sign-in (password accepted, no second factor yet) MUST expire after 5 minutes and after 5 wrong codes, and MUST be discardable by "Not you?". The set-up steps of a new or re-enrolled operator (new password, authenticator, recovery codes) MUST each expire 15 minutes after they begin.
- **FR-015**: After full sign-in the operator MUST land on the page they first asked for, if it is a back-office page, otherwise the overview.
- **FR-016**: The session identifier MUST be replaced at each step (after the password and after the second factor) and on sign-out.

**Enrolment and recovery codes**

- **FR-020**: Enrolment MUST be mandatory: an operator not enrolled can do nothing in the back office except enrol (baseline).
- **FR-021**: Enrolment MUST show a newly generated secret as a setup key (grouped for reading) and as a standard setup link for authenticator apps, and MUST complete only after the operator enters a correct code from it.
- **FR-022**: On first enrolment and on re-enrolment after approval, ten single-use recovery codes MUST be issued and shown once, with copy and download, and a required confirmation that they were saved.
- **FR-023**: A recovery code MUST be accepted at step 2 in place of a code, at most once, case- and hyphen-insensitive.
- **FR-024**: An operator MUST be able to generate a new set of ten recovery codes; every earlier code stops working at once.
- **FR-025**: An operator MUST be able to link a new authenticator from their security page; the old one stops working only when the new one is confirmed.
- **FR-026**: Recovery codes and second-factor secrets MUST never be shown again after their one display, and never be recoverable by any operator, including the account holder.
- **FR-027**: There MUST be no switch, command or screen that turns the second factor off for an operator or for the back office (baseline Second-factor recovery rule 4).

**Re-enrolment by two other operators**

- **FR-030**: After a correct password at step 1, an operator MUST be able to open a re-enrolment request ("Lost your device?"). At most one request per operator is open at a time.
- **FR-031**: A request MUST need approvals from two distinct operators, neither of them the requester, each active and fully signed in, each having confirmed a fresh code within the last 10 minutes, and each giving a reason through the reason prompt.
- **FR-032**: A request MUST expire 72 hours after it opens if it has not completed. Any other operator MAY decline it with a reason, closing it. The requester's deactivation cancels it.
- **FR-033**: On the second approval the request MUST complete atomically: the requester's secret and every recovery code are invalidated, all their sessions end, and their account becomes awaiting enrolment.
- **FR-034**: Operators MUST see open requests on a "Re-enrolment requests" page in the back office, showing display name, opened and expiry instants, and approval count only.

**Sessions**

- **FR-040**: An operator session MUST end after 30 minutes without a request and 12 hours after sign-in, whichever comes first.
- **FR-041**: Sign-out MUST end the session at once; a replayed session cookie MUST reach nothing.
- **FR-042**: A password change MUST end every other session of that operator and keep the current one. Deactivation and a completed re-enrolment MUST end all of them. The check MUST happen on every request, with no cached "still valid" answer.
- **FR-043**: Every back-office response MUST forbid browser caching and framing.

**Lockout and throttling**

- **FR-050**: Operator sign-in MUST apply the baseline's lockout: 5 consecutive failures lock the email for 15 minutes, 10 for 60, never longer, keyed so an unknown email locks identically.
- **FR-051**: Wrong second-factor codes and wrong recovery codes MUST count as failures on the same counter as wrong passwords; a full sign-in resets it.
- **FR-052**: 20 failures from one origin across any operator emails within 15 minutes MUST refuse that origin for 15 minutes. The operator counters MUST be separate from the application's.
- **FR-053**: Opening a re-enrolment request, and every code re-confirmation, MUST be throttled the same way.

**Re-confirmation for sensitive actions**

- **FR-060**: Changing the password, generating recovery codes, linking a new authenticator, and approving or declining a re-enrolment MUST require a code (or recovery code) confirmed within the last 10 minutes; otherwise the operator is asked for one first.
- **FR-061**: The re-confirmation MUST be reusable by later back-office features (B11, B12) for their own sensitive actions without writing their own check.
- **FR-062**: Password change MUST also ask for the current password. New passwords MUST be 12 to 128 characters, not equal to the email, and not equal to the current or temporary password.
- **FR-063**: An operator who forgets their password MUST be recovered only by the deployment's artisan command, which issues a new temporary password and requires a change at next sign-in; it MUST NOT touch the second factor, and an enrolled operator MUST still pass the second factor before choosing the new password. There is no self-service mailed reset.

**Guarding every back-office surface**

- **FR-070**: Every route under `/admin` MUST require a fully signed-in operator (both steps, enrolled, password not pending), except: the sign-in steps, the enrolment and recovery-code steps (each requiring the matching partial state), and the local-only gallery.
- **FR-071**: Every back-office component MUST re-check the full sign-in on every update it handles, including those arriving through the shared component update endpoint, and MUST be refused when the session no longer qualifies.
- **FR-072**: An application credential MUST NOT be accepted by the operator guard on any back-office route or component update.
- **FR-073**: A route or component a later feature adds to the back office MUST be guarded by default, with no per-feature code.

**Audit**

- **FR-080**: Each of these MUST produce exactly one audit entry through Identity's audit service: full sign-in succeeded; sign-in failed (password or code); lockout applied; sign-out; password changed; operator created; operator deactivated; temporary password reissued; enrolment completed; recovery code used (as part of the sign-in it completes); recovery codes regenerated; authenticator replaced; re-enrolment requested, approved, declined, completed, expired and cancelled.
- **FR-081**: Entries an operator causes MUST carry the operator as actor (actor id = the operator account's identifier); entries the command line causes MUST carry the system as actor; failures before an operator is identified MUST carry no readable email.
- **FR-082**: No entry MUST contain a password, temporary password, secret, setup link, code, or recovery code.
- **FR-083**: A session ending by idle or absolute limit is not an act and MUST NOT produce an entry; the sign-out that a person chooses does.

**Shell binding**

- **FR-090**: The shell's operator block MUST show the signed-in operator's display name, initial and second-factor status, and its sign-out control MUST end the session, bound without editing the shell (spec 006 FR-051).
- **FR-091**: Every screen in this feature MUST exist in English and Arabic, right-to-left in Arabic, using only spec 006 primitives and tokens; step 2 MUST match the design source board "Screen · Operator sign-in".
- **FR-092**: The code entry MUST accept a pasted six-digit code into the first box, move focus between boxes with the keyboard, give each box an accessible label, announce errors to assistive technology, and fall back to one ordinary field without JavaScript.

### Key Entities

- **Operator Account**: a person who may use the back office. Email, display name, password, state (awaiting password change, awaiting enrolment, active, deactivated), enrolled second factor, last accepted code step, and timestamps. Owned by Admin.
- **Recovery Code**: one of ten single-use codes belonging to an Operator Account, stored only as a one-way value, used or unused.
- **Re-enrolment Request**: an operator's request to replace a lost second factor; open, completed, declined, expired or cancelled; with up to two approvals by other operators, each with a reason.
- **Operator Session**: a signed-in browser session of an Operator Account, ending on sign-out, idle, absolute limit, password change, deactivation or re-enrolment.

## Project Sections *(mandatory for feature specs)*

### 1. Purpose

Let Save Me's own staff into the back office, and nobody else: an email and password are never enough, a lost phone has a way back that cannot be talked past by one person, and every later back-office screen is behind this lock without having to remember it.

### 2. Glossary terms

[Admin](../../docs/glossary.md#notifications-and-admin), [Audit Entry](../../docs/glossary.md#audit-entry), [Operator Notification](../../docs/glossary.md#operator-notification), **Operator**, **Back office** (spec 006). **New, added to the glossary in this change before use**: **Operator Account**, **Recovery Code** (a value inside Operator Account), **Re-enrolment Request**.

### 3. Data model

Owned by Admin; detailed in [data-model.md](./data-model.md).

- **Operator Account** (`operator_accounts`): identifier (UUIDv7), email (protected at rest, with a lookup value), display name (protected at rest), password hash, temporary-password expiry, state, second-factor secret and a pending secret during set-up (protected at rest), last accepted code step, enrolled-at, credential epoch, password-changed-at, last-signed-in-at, timestamps.
- **Recovery Code** (`operator_recovery_codes`): owner, one-way value, used-at, set identifier.
- **Re-enrolment Request** (`operator_reenrolment_requests`) and its **approvals** (`operator_reenrolment_approvals`).
- **Operator sign-in failure counter** (`operator_sign_in_failures`): consecutive failures and lock per email lookup value, separate from the application's.
- Identity's audit log gains the subject type `operator` (Identity migration; baseline table amended).

### 4. Invariants

| Invariant | Principle or decision | Test that fails when broken |
|---|---|---|
| No back-office route except the sign-in, enrolment, recovery-code steps and the local gallery is reachable without a fully signed-in operator | P-18 r4, P-08 r4, FR-070 | `tests/Feature/Admin/AdminRouteSurfaceTest.php` |
| A back-office component update is refused when the session is not fully signed in | P-18 r4, r7, FR-071 | `tests/Feature/Admin/Auth/ComponentGuardTest.php` |
| An application credential never authenticates on the operator guard | ADR-0006 r8, FR-072 | `tests/Feature/Admin/Auth/GuardSeparationTest.php` |
| A code is computed per RFC 6238 and accepted only within ±1 step | Baseline "Operator access", FR-011 | `tests/Unit/Admin/TotpTest.php` |
| A code step is never accepted twice for one operator | FR-012 | `tests/Feature/Admin/Auth/SignInTest.php` |
| A recovery code is accepted once; regeneration invalidates the old set | Baseline recovery r1–2, FR-023, FR-024 | `tests/Feature/Admin/Auth/RecoveryCodesTest.php` |
| A re-enrolment completes only with two distinct approvers, neither the requester | Baseline recovery r3, FR-031 | `tests/Feature/Admin/Auth/ReenrolmentTest.php` |
| No path disables the second factor | Baseline recovery r4, FR-027 | `tests/Arch/AdminBackOfficeTest.php` (no such command/route), `ReenrolmentTest` |
| Secret, email and display name are never stored readable | Baseline "What is protected at rest", FR-026 | `tests/Feature/Admin/Auth/OperatorStorageTest.php` |
| Sessions end on idle (30 min), absolute (12 h), password change, deactivation, re-enrolment | FR-040–FR-042 | `tests/Feature/Admin/Auth/OperatorSessionTest.php` |
| Lockout thresholds and origin limit as the baseline | Baseline Lockout, FR-050–FR-052 | `tests/Feature/Admin/Auth/LockoutTest.php` |
| Each act writes exactly one audit entry and none contains a secret | Baseline audit rules, FR-080–FR-082 | `tests/Feature/Admin/Auth/OperatorAuditTest.php` |
| Sensitive actions refuse without a re-confirmation in the last 10 minutes | FR-060 | `tests/Feature/Admin/Auth/ReconfirmationTest.php` |
| The last active operator cannot be deactivated | FR-005 | `tests/Feature/Admin/Auth/OperatorCommandsTest.php` |

### 5. API

Not applicable: the back office serves no `/api/v1` endpoints (P-18 rule 2), and this feature adds none. `docs/openapi` is unchanged. The reserved codes `TWO_FACTOR_REQUIRED` and `TWO_FACTOR_INVALID` describe the back office's refusals in the baseline's words; the back office answers as HTML pages and redirects, not as API envelopes.

### 6. Authorization and entitlements

Outside wallet-scoped authorization (P-08 rule 4). One global role: every active, enrolled, fully signed-in operator may do everything this feature offers, except the self-exclusions (an operator never approves or declines their own re-enrolment, nor approves twice). No entitlements apply.

### 7. Events

None published or consumed. Audit entries are written in the same transaction as the act (baseline), not through events.

### 8. Acceptance scenarios

| Story | Layer | Proof |
|---|---|---|
| US1 Sign-in | Unit (code maths, recovery-code format, password policy), Feature (sign-in service, pages) | `tests/Unit/Admin/TotpTest.php`, `tests/Unit/Admin/Base32Test.php`, `tests/Unit/Admin/RecoveryCodeTest.php`, `tests/Unit/Admin/OperatorPasswordPolicyTest.php`, `tests/Feature/Admin/Auth/SignInTest.php`, `tests/Feature/Admin/Auth/GuardSeparationTest.php` |
| US2 Provisioning and enrolment | Feature | `tests/Feature/Admin/Auth/OperatorCommandsTest.php`, `tests/Feature/Admin/Auth/EnrolmentTest.php` |
| US3 Recovery codes | Feature | `tests/Feature/Admin/Auth/RecoveryCodesTest.php` |
| US4 Re-enrolment | Feature | `tests/Feature/Admin/Auth/ReenrolmentTest.php` |
| US5 Security page | Feature | `tests/Feature/Admin/Auth/SecurityPageTest.php`, `tests/Feature/Admin/Auth/ReconfirmationTest.php` |
| US6 Sessions | Feature | `tests/Feature/Admin/Auth/OperatorSessionTest.php` |
| US7 Lockout | Feature | `tests/Feature/Admin/Auth/LockoutTest.php` |
| US8 Guard by default | Feature, Arch | `tests/Feature/Admin/AdminRouteSurfaceTest.php`, `tests/Feature/Admin/Auth/ComponentGuardTest.php`, `tests/Arch/AdminBackOfficeTest.php` |
| All stories, in a browser | Playwright QA (later) | the acceptance scenarios above, driven against `http://saveme-backend.test/admin` |

### 9. Open questions

None blocking. Decisions taken with delegated authority are recorded in Clarifications.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An enrolled operator completes sign-in (both steps) in under 30 seconds.
- **SC-002**: A new operator goes from the printed temporary password to the overview (password change, enrolment, recovery codes) in under 5 minutes.
- **SC-003**: 100% of back-office routes and component updates, current and added later, refuse anyone not fully signed in (route-surface and component-guard tests).
- **SC-004**: 0 audit entries, logs or pages after their one display contain a password, secret, setup link, code or recovery code.
- **SC-005**: A password-guessing run against one email is refused after 5 failures and for no longer than 60 minutes; a run from one network across many emails is refused after 20 failures in 15 minutes.
- **SC-006**: A lost device with no recovery codes is recovered with exactly two approvals from other operators, and never with fewer.
- **SC-007**: An unattended session is signed out within 30 minutes of the last request, and every session within 12 hours.
- **SC-008**: Every screen in this feature renders in English and Arabic with correct direction, checked in a browser.

## Assumptions

- Operators are a handful of internal staff; volumes are tiny. At least three operators are expected before go-live so a re-enrolment can complete.
- Operators use a standard authenticator app that accepts a setup key or a setup link (RFC 6238 defaults: SHA-1, 6 digits, 30 seconds).
- Whoever runs artisan commands on the deployment already has full control of it; the commands add no power they lack.
- The application's own sign-in counters, keys and audit rules (spec 007) are reused in shape, not shared in state.
- The back office runs over HTTPS in every non-local environment; local runs over the dev stack's HTTP.
- The operator's language choice stays session-scoped as in spec 006; per-operator stored language and timezone are later work.
- Per-operator read state for Operator Notifications is not part of this feature (B12).
- Seeder: N/A — no reference data. Operators are created by the artisan command; a local factory helps tests only.
