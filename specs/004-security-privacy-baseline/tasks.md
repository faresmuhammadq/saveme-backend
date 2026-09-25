---

description: "Task list for the Security & Privacy Baseline (004)"
---

# Tasks: Security & Privacy Baseline

**Input**: Design documents from `/specs/004-security-privacy-baseline/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/](./contracts/)

**Tests**: No automated tests. This feature changes no PHP file, so Pint, Larastan and Pest do not apply (plan Constitution Check, P-16). The invariants written here are tested by the features that implement them, and each rule names its owning module so none arrives without one. Validation is the structural checks and reviewer exercises in [quickstart.md](./quickstart.md), which appear below as real tasks.

**Organization**: Tasks are grouped by user story so each can be delivered and validated on its own.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel — different file, no dependency on an incomplete task
- **[Story]**: US1 … US5, matching the user stories in [spec.md](./spec.md)
- Every task names the exact file it changes

## Path Conventions

Deliverables live in `docs/`, not `specs/` (plan → Structure Decision):

- `docs/security-baseline.md` — the rules every later feature obeys
- `docs/privacy-commitments.md` — the published promises, Arabic and English, with their mapping
- `docs/openapi/error-catalog.yaml` — gains ten reserved codes, 38 → 48

Source material is in this feature's `research.md`, `data-model.md` and `contracts/`. Where a task says "per contracts/X", transcribe that file rather than re-deciding it.

**Shared-file note**: `docs/security-baseline.md` is written by every phase and `docs/privacy-commitments.md` by one. Tasks on the same file are never marked `[P]`, even across phases. The Dependencies section gives the write order.

---

## Phase 1: Setup

- [X] T001 Create `docs/security-baseline.md` with the title, a status line, links to [docs/glossary.md](../../docs/glossary.md), [docs/api-conventions.md](../../docs/api-conventions.md) and [docs/adr/](../../docs/adr/README.md), and the empty section headings in the order the later tasks fill them
- [X] T002 [P] Create `docs/privacy-commitments.md` with the five section headings — what is collected, what is protected, who can see a shared wallet, how long things are kept, how to leave — each holding an empty Arabic and English pair, plus an empty mapping table at the end

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: the scope statement every rule sits inside, and the ten reserved codes every later phase refers to

**⚠️ CRITICAL**: no story work starts until this phase is done. The codes are transcribed once here rather than split across the three stories that use them, because they are one table in one file and splitting them would put three phases in a conflict for no gain.

- [X] T003 Write the "Scope, ownership and precedence" section of `docs/security-baseline.md`: this document states rules and builds nothing; the constitution and any Accepted decision record win where they overlap; and a table naming the module that will build each area — Identity for sessions, credentials, verification, lockout and the audit log; Billing for provider-message verification; Sync for retention and purge; Admin for the operator second factor
- [X] T004 [P] Transcribe the ten reserved codes from [data-model.md §13](./data-model.md) into `docs/openapi/error-catalog.yaml`: `INVALID_CREDENTIALS` 401, `ACCOUNT_LOCKED` 423, `EMAIL_NOT_VERIFIED` 403, `VERIFICATION_TOKEN_INVALID` 400, `RESET_TOKEN_INVALID` 400, `TWO_FACTOR_REQUIRED` 401, `TWO_FACTOR_INVALID` 401, `INSECURE_TRANSPORT` 400, `PROVIDER_SIGNATURE_INVALID` 401, `EXPORT_IN_PROGRESS` 409 — each into the `ErrorCode` enum and into `x-error-catalog` with `level: [response]`, its `title`, its one-sentence `meaning`, its `retryable` value from the table, and `since: v1.1`
- [X] T005 Write the "Reserved error codes" section of `docs/security-baseline.md`: link to the catalogue rather than copying it, state why codes are reserved before any endpoint returns them, and record that `UNAUTHENTICATED` already covers an expired or revoked session so no code was added for that, and that `423` rather than `429` carries a lockout so a client can show different words for "throttled" and "locked"
- [X] T006 Run [quickstart.md](./quickstart.md) §1.1 and §1.2 and confirm `catalogue and enum agree (48 codes)` and `all ten reserved codes present and correct`

**Checkpoint**: the scope is stated and the codes exist. Story work can begin.

---

## Phase 3: User Story 1 — An account cannot be quietly taken over (Priority: P1) 🎯 MVP

**Goal**: guessing is throttled and then locked, a leaked password alone cannot write, and a stolen session can be ended by its owner.

**Independent Test**: attempt credential stuffing against a known address. The attempts are refused before they succeed, the account is not permanently bricked, the legitimate owner can still sign in, and every attempt is visible afterwards.

- [X] T007 [US1] Write the "Device sessions" section of `docs/security-baseline.md` per [contracts/sessions-and-credentials.md](./contracts/sessions-and-credentials.md): one credential per signed-in device, at least 256 bits of entropy, stored as a hash with the readable value existing only in the response that issues it, 90 days of **inactivity** with no absolute cap in MVP, revocation effective on the next request with **no cache of validity anywhere in the path**, revoking one leaving every other working, a password change revoking every session except the one that made it, and a revoked row surviving so the audit trail can still name it
- [X] T008 [US1] Write the "Passwords" section of `docs/security-baseline.md`: argon2id with `memory = 65536 KiB`, `iterations = 4`, `parallelism = 1`, re-measured on the target hardware and raised until one hash costs 250–500 ms, rehashed on the next successful sign-in whenever the stored parameters are below the current setting, and never returned by any endpoint at any level of privilege
- [X] T009 [US1] Write the "Reset and verification tokens" section of `docs/security-baseline.md`: password reset lives 60 minutes, address verification 24 hours, both single-use, both opaque and stored hashed; a reset token is also invalidated by any password change and by any later reset request; a verification token by a later request for the same address; resends limited to three per hour per address
- [X] T010 [US1] Write the "Lockout" section of `docs/security-baseline.md`: 5 consecutive account failures refuse 15 minutes, 10 refuse 60 minutes, 20 failures from one origin across any accounts in 15 minutes refuse that origin 15 minutes; the account lock **caps at 60 minutes and is never permanent**; a successful password reset clears it immediately because that needs the mailbox; a successful sign-in resets the counter; the refusal is `423 ACCOUNT_LOCKED` with `Retry-After`, distinguishable from a wrong password. State plainly that this is what stops the lock being a weapon against the account's owner
- [X] T011 [US1] Write the "Uniform answers" section of `docs/security-baseline.md`: sign-in returns `401 INVALID_CREDENTIALS`, reset and registration and invitation each return `202`, response timing is equalised, and the difference lives in the mail body where it reaches the person who owns the address and nobody else. State that three surfaces answering uniformly and a fourth leaking makes the other three pointless — all four, or none
- [X] T012 [US1] Write the "Verification gate" section of `docs/security-baseline.md`: reads, profile management and session management stay available before verification; **every** write to a wallet is refused with `403 EMAIL_NOT_VERIFIED`, its own code so the app offers a resend; and the reasoning both ways — an unverified invitee may read so an invitation is worth sending, and may not write so a typo'd or hostile address cannot put entries into someone's shared wallet
- [X] T013 [US1] Write the "Transport" section of `docs/security-baseline.md`: TLS 1.2 or better on every request; a plaintext request to an API path is **refused** with `400 INSECURE_TRANSPORT` and not redirected, because a redirect teaches a client that plaintext is a working first step and the credential has already been sent by then; responses carry a strict-transport-security header with a one-year duration
- [X] T014 [US1] Run the lockout hand-walk in [quickstart.md](./quickstart.md) §4 and confirm 5 of 5, with no path by which an attacker denies the owner access for more than an hour without controlling their mailbox
- [X] T015 [US1] Verify in `docs/openapi/error-catalog.yaml` that the six codes this story relies on — `INVALID_CREDENTIALS`, `ACCOUNT_LOCKED`, `EMAIL_NOT_VERIFIED`, `VERIFICATION_TOKEN_INVALID`, `RESET_TOKEN_INVALID`, `INSECURE_TRANSPORT` — carry the statuses the sections above quote

**Checkpoint**: someone specifying Identity can build sign-in, reset, verification and session management from the document alone.

---

## Phase 4: User Story 2 — Personal and financial data stays unreadable to the wrong eyes (Priority: P1)

**Goal**: identity is protected against anyone who reaches the stored data, money against everyone who was not invited to the wallet, and nothing leaks sideways through a log, an error or a support screen.

**Independent Test**: inspect stored data, logs and error responses for a completed money entry. Identity attributes are unreadable at rest, amounts and notes appear in no log above debug, and no response discloses a resource the caller may not see.

- [X] T016 [US2] Write the "What is protected at rest" section of `docs/security-baseline.md`, transcribing the nine-row table from [contracts/protected-attributes.md](./contracts/protected-attributes.md) with its "searchable" and "who can read it" columns. State outright that amounts and notes are **not** encrypted at rest, and why: arithmetic, aggregation and indexing depend on them, and an attacker who reaches the database also reaches the application that would decrypt them. Include the rule that an attribute not in the table carries no protection claim
- [X] T017 [US2] Write the "Finding a person by address" section of `docs/security-baseline.md`: `HMAC-SHA256(normalise(address), lookup_key)`, unique-indexed; the three normalisation steps — trim, Unicode NFKC, lowercase the whole address — applied identically everywhere; **no provider-specific munging**, since dots and plus-tags distinguish addresses to anyone who is not that provider and merging them merges two people; the lookup key separate from the encryption key and outside the database; and the stated limit, that this defeats an attacker holding the data and not one holding the data and the key
- [X] T018 [US2] Write the "Keys and rotation" section of `docs/security-baseline.md`: every encrypted value stores the identifier of the key that produced it so a partially rotated table is a valid state; rotating an encryption key adds the new key, writes new values, and keeps the old readable until a re-encryption pass finishes with nobody re-registering; rotating the **lookup** key writes into a second column, backfills, swaps the unique index and drops the old column; keys live in the deployment's secret store and never in the database they protect
- [X] T019 [US2] Write the "What must never be logged" section of `docs/security-baseline.md`: the absolute list is passwords, tokens, session identifiers, one-time codes, purchase tokens, receipt contents and the full body of a financial write; **amounts and record identifiers are permitted at debug level only**, barred from informational level and above, and never leave the service in an error report or crash payload; redaction is enforced centrally rather than by each call site remembering. Note that this restates the existing decision rather than replacing it
- [X] T020 [US2] Write the "What must never be disclosed" section of `docs/security-baseline.md`: no response, error or message names another user, another wallet or a value belonging to either; a caller who is not a member receives a not-found rather than a forbidden, so existence is never revealed. Reference the authorization decision for the rule and **do not** restate the wallet role matrix, which lives only in the Wallets spec
- [X] T021 [US2] Run [quickstart.md](./quickstart.md) §1.4 and confirm nothing in either document claims blanket encryption

**Checkpoint**: a reviewer can answer, for any attribute, whether it is protected and who can read it.

---

## Phase 5: User Story 3 — A person controls their sessions and can leave with their data (Priority: P2)

**Goal**: every signed-in device is visible and can be cut off, a person can take a complete copy of what they entered, and deletion is genuine but reversible while they might change their mind.

**Independent Test**: from one device, list the sessions, end another, request an export, request deletion, then cancel inside the grace period and confirm nothing was lost.

- [X] T022 [US3] Write the "What a person sees and controls" section of `docs/security-baseline.md`: the session list names the device and the times and exposes nothing that helps someone who can already see the screen; ending one session is immediate and leaves the others working. Cross-reference the Device sessions section rather than restating its rules
- [X] T023 [US3] Write the "Retention" section of `docs/security-baseline.md` from [contracts/retention-and-departure.md](./contracts/retention-and-departure.md): the nine-row table with every period as a number and the decision that fixes it, and the rule that the published commitments state **90 days** for anything that syncs because that is the longer number and the only one the rules can keep
- [X] T024 [US3] Write the "Deletion" section of `docs/security-baseline.md`: the requested → cancelled or completed state machine with a 30-day grace period; cancelling loses nothing; the person may keep using the account during the grace period, writes included, and using it neither extends nor resets the clock; on completion the rows they alone own are deleted **and** the per-account key material is destroyed, so a backup restored afterwards yields nothing readable for them
- [X] T025 [US3] Write the "Leaving a shared wallet" section of `docs/security-baseline.md`: the five-row table of what goes and what stays; transactions authored in someone else's wallet are **kept**, with the author replaced by a stable anonymous marker that cannot be resolved back to a person; the reasoning, that deleting them would change other members' balances without those members knowing; and the stated limit, that a departed member's device keeps whatever it last synced and the contract cannot reach it
- [X] T026 [US3] Write the "Export" section of `docs/security-baseline.md`: produced asynchronously with a second request answered `409 EXPORT_IN_PROGRESS`; contents are complete structured data plus a spreadsheet-openable file of transactions; it holds what the person entered and what they can already see, not other members' records beyond that; an export during the grace period **is served and does not reset the clock**, because refusing strands someone who is leaving and resetting would let a scheduled export postpone a deletion forever
- [X] T027 [US3] Run [quickstart.md](./quickstart.md) §1.3 and confirm no retention period anywhere is stated as "a reasonable time" or any equivalent

**Checkpoint**: every number a person could ask about their data is written down, and leaving is fully specified.

---

## Phase 6: User Story 4 — Every security-relevant act can be answered for afterwards (Priority: P2)

**Goal**: "who removed me from this wallet" and "was this account accessed from somewhere new" are answerable from a record that is not a second copy of what it protects.

**Independent Test**: perform a sign-in, a session revocation, a password change and a membership change, then answer from the record alone who did each and when.

- [X] T028 [US4] Write the "Who owns the audit log" section of `docs/security-baseline.md` per [contracts/audit-entry.md](./contracts/audit-entry.md): Identity owns it and exposes an application service every other module calls; Admin keeps its constitution-named service as a **façade over the same store**, not a second one; and the reasoning — a cross-module write through an application service is a permitted mechanism, a shared store in the shared kernel is not, and a tenth bounded context would need a constitution amendment for a table
- [X] T029 [US4] Write the "The audit entry" section of `docs/security-baseline.md`: the ten fields — `id`, `occurred_at`, `event`, `actor_type` (`user` | `operator` | `system`), `actor_id` nullable only for `system`, `subject_type` (`user` | `wallet` | `session` | `subscription`), `subject_id`, `request_id` correlating with the server's logs, `origin` nullable, and a structured `detail` constrained by the exclusion list
- [X] T030 [US4] Write the "What is recorded" section of `docs/security-baseline.md`: the nineteen events grouped as access, sessions, credentials, sharing, billing, departure and privileged; the rule that a module does not invent a local name and a genuinely new event is added to this vocabulary; and the rule that every event names exactly one module that writes it, so no event arrives at implementation without an owner
- [X] T031 [US4] Write the "What an entry must never contain" section of `docs/security-baseline.md`: passwords, tokens, one-time codes, amounts, notes, receipt contents and readable addresses, with the subject referenced by identifier — an entry names what happened, never what it was worth. Add the operational rules: append-only, 400 days, operator-only, and **reading the log is itself an `audit_read` event** so the richest target in the system cannot be browsed unobserved
- [X] T032 [US4] Verify in `docs/security-baseline.md` that every one of the nineteen events in the vocabulary names an owning module in the ownership table written by T003, and add any that is missing

**Checkpoint**: the audit vocabulary is fixed, owned and bounded.

---

## Phase 7: User Story 5 — Privileged and machine access cannot be forged (Priority: P3)

**Goal**: the back office is not a softer way into user data, and a message claiming to come from the payment provider cannot simply be posted.

**Independent Test**: attempt a back-office sign-in with a correct password alone, and post an unsigned and a replayed provider message. All three are refused.

- [X] T033 [US5] Write the "Operator access" section of `docs/security-baseline.md`: accounts entirely separate from application users on their own guard, per the authorization decision; time-based codes, six digits, 30-second step, one step of drift tolerated; enrolment mandatory before the account can act; `401 TWO_FACTOR_REQUIRED` when the factor is absent and `401 TWO_FACTOR_INVALID` when it is wrong; and the rule that an operator acts through other modules' application services so the same invariants apply as for a user action
- [X] T034 [US5] Write the "Second-factor recovery" section of `docs/security-baseline.md`: ten single-use recovery codes issued at enrolment and shown once; when they are exhausted, **two other operators** must approve a re-enrolment and both approvals are recorded; recovery never reduces to asking someone to switch the requirement off
- [X] T035 [US5] Write the "Messages from the payment provider" section of `docs/security-baseline.md` per [data-model.md §11](./data-model.md): all five checks must hold — signature against current keys, expected issuer, an **audience naming this service**, an issued-at time within five minutes, and a message identifier unseen in 24 hours; an already-seen message is **acknowledged with no effect** rather than refused, because a refusal makes a well-behaved provider retry forever; a failed check is `401 PROVIDER_SIGNATURE_INVALID` with nothing granted; unreachable key material causes rejection and a provider retry, never acceptance and never deferred trust. Call out that the audience check is the one most often skipped and the one that stops a validly signed message meant for another service granting a subscription here
- [X] T036 [US5] Verify in `docs/openapi/error-catalog.yaml` that `TWO_FACTOR_REQUIRED`, `TWO_FACTOR_INVALID` and `PROVIDER_SIGNATURE_INVALID` carry the statuses the sections above quote

**Checkpoint**: the two surfaces that bypass ordinary user authentication are both specified.

---

## Phase 8: Privacy Commitments & Close-Out

**Purpose**: the promises the product makes, the appendix that makes every rule checkable, and the cross-artifact checks. The commitments sit here rather than inside a story because they draw on all five and no single story owns them (FR-035 to FR-037).

- [X] T037 Write the "What we collect" section of `docs/privacy-commitments.md` in Arabic and English: the address and display name, what people enter about their money, and the devices they sign in from. Plain short sentences, no security vocabulary
- [X] T038 Write the "What is protected, and how" section of `docs/privacy-commitments.md` in both languages. It **must not** claim that everything is encrypted: say that the address and name are unreadable without a separate key, that money entries are not encrypted at rest and are protected by who can reach them, and that passwords can never be read back by anyone
- [X] T039 Write the "Who can see a shared wallet" section of `docs/privacy-commitments.md` in both languages: members see the wallet's entries, nobody outside it sees anything at all, and a person's own categories and budgets stay theirs
- [X] T040 Write the "How long we keep things" section of `docs/privacy-commitments.md` in both languages, stating **90 days** for anything that syncs — the longer of the two horizons and the only number the retention rule can keep — plus the 30-day grace period on account deletion
- [X] T041 Write the "Leaving" section of `docs/privacy-commitments.md` in both languages: how to export, how to delete, what the 30-day grace period means, what survives in a shared wallet and why, and that a former member's phone keeps whatever it already synced
- [X] T042 Write the mapping table at the end of `docs/privacy-commitments.md`: one row per commitment, citing the requirement that backs it. A promise with no backing requirement is a defect and must be removed or a requirement added
- [X] T043 Run [quickstart.md](./quickstart.md) §1.5 and §1.6 and confirm every cited requirement exists in the spec, and that both languages are present
- [X] T044 Run the commitment audit in [quickstart.md](./quickstart.md) §5, reading the page as a sceptical user, with particular attention to the retention, encryption, deletion and shared-wallet sentences (SC-007)
- [X] T045 Transcribe [contracts/security-review-checklist.md](./contracts/security-review-checklist.md) into an appendix of `docs/security-baseline.md`, keeping all nine groups and all 49 checks, because the reviewers who need it work outside `specs/` (FR-038a)
- [X] T046 Run the seeded-violation exercise in [quickstart.md](./quickstart.md) §2 with a reviewer and confirm 5 of 5 violations are mapped to the rule they break (SC-009)
- [X] T047 Run the downstream-author exercise in [quickstart.md](./quickstart.md) §3 and record every clarifying question as a defect against `docs/security-baseline.md` (SC-008)
- [X] T048 [P] Update the A4 row in `specs/README.md` to Done, linking `docs/security-baseline.md` and `docs/privacy-commitments.md`, and mark the five A4 conflicts in that file as resolved — including the tombstone-retention conflict, whose answer is the two horizons
- [X] T049 [P] Add a pointer line to `docs/api-conventions.md` in the section that declares the bearer scheme, sending readers to `docs/security-baseline.md` for how credentials are issued, expired and revoked
- [X] T050 Read `docs/security-baseline.md` against constitution v1.1.0 and the Accepted decision records and confirm it contradicts none of them, does not restate the wallet role matrix, and does not weaken the never-log list or the tombstone horizon
- [X] T051 Re-run the whole of [quickstart.md](./quickstart.md) §1 against the finished deliverables and confirm every check passes
- [X] T052 Run `/speckit-analyze` across `spec.md`, `plan.md` and `tasks.md` and resolve anything it reports before implementation is called done

---

## Dependencies & Execution Order

### Phase dependencies

- **Setup (Phase 1)**: no dependencies. T002 runs in parallel with T001 — different file.
- **Foundational (Phase 2)**: depends on Setup. Blocks every story, because three of the five reference reserved codes and all five sit inside the scope statement.
- **User stories (Phases 3–7)**: all depend on Foundational. US1 and US2 are both P1; US1 is the MVP because account takeover is the failure that makes everything else moot.
- **Close-out (Phase 8)**: the commitments draw on all five stories, so they follow them. T048 and T049 can run as soon as the deliverables exist.

### Story dependencies

| Story | Depends on | Notes |
|---|---|---|
| US1 (P1) | Foundational only | The MVP. Delivers sessions, credentials, lockout, uniform answers, the gate and transport |
| US2 (P1) | Foundational only | Independent in content, but shares `docs/security-baseline.md`, so sequence the file |
| US3 (P2) | US1 in practice | Its session-control section cross-references US1's rules rather than restating them |
| US4 (P2) | Foundational only | Its verification task T032 checks the ownership table written in T003 |
| US5 (P3) | Foundational only | Touches only its own sections and the catalogue check |

### Shared-file sequencing

Two files are written by more than one phase. Nothing in them is parallel:

| File | Order |
|---|---|
| `docs/security-baseline.md` | T001 → T003, T005 → T007–T013 → T016–T020 → T022–T026 → T028–T031 → T033–T035 → T045 |
| `docs/privacy-commitments.md` | T002 → T037 → T038 → T039 → T040 → T041 → T042 |

### Parallel opportunities

- T001 and T002 — two different files
- T004 (`error-catalog.yaml`) is parallel with T003 (`security-baseline.md`); T005 needs both
- T048 (`specs/README.md`) and T049 (`docs/api-conventions.md`) — different files
- Validation tasks inside a story can run as soon as that story's sections are written

### Within each story

- Write the rule before the check that verifies it
- Cross-reference rather than restate: a section that repeats another section is a maintenance bug waiting to diverge
- Validation tasks close a phase, and a failed check is fixed before the checkpoint is claimed

---

## Parallel Example: Phase 1 and 2

```bash
# Two different files, no dependency:
Task: "Create docs/security-baseline.md with its section headings"
Task: "Create docs/privacy-commitments.md with its bilingual structure"

# After the scaffolds exist, the scope section and the codes are independent:
Task: "Write the Scope, ownership and precedence section of docs/security-baseline.md"
Task: "Transcribe the ten reserved codes into docs/openapi/error-catalog.yaml"
```

---

## Implementation Strategy

### MVP first (User Story 1 only)

1. Phase 1 Setup — T001, T002
2. Phase 2 Foundational — T003–T006, ending with a checked catalogue of 48 codes
3. Phase 3 US1 — T007–T015
4. **Stop and validate**: walk the lockout rule by hand. If any path lets an attacker deny the owner access for more than an hour without their mailbox, the rule is wrong and everything downstream inherits it
5. At this point someone specifying Identity can build sign-in, reset, verification and sessions from the document alone

### Incremental delivery

1. Setup + Foundational → the scope and the codes exist
2. US1 → account takeover is specified (MVP)
3. US2 → what is protected, and what is deliberately not, is stated attribute by attribute
4. US3 → every number about a person's data is written down, and leaving is specified
5. US4 → the audit vocabulary is fixed, owned and bounded
6. US5 → the two surfaces that bypass ordinary authentication are covered
7. Close-out → the promises, the appendix, and the cross-checks

### Parallel team strategy

`docs/security-baseline.md` is one file and is the bottleneck, so two people writing sections at once will conflict. The workable split is one person on the baseline sections in phase order and one on the commitments from T037, meeting at the close-out checks. A third can take T048 and T049 as soon as the deliverables exist.

---

## Coverage notes recorded at implementation

**Five success criteria cannot be verified by this feature.** SC-002 through SC-006 describe a running system: no readable amount in stored data, four probe surfaces indistinguishable over repeated attempts, four questions answerable from the audit record alone, a person completing every step in the app, and a cancelled deletion losing nothing. This feature builds nothing, so it can only fix the rules those criteria test. They become the acceptance criteria of **Identity** and **Sync**, and the baseline names the owning module for every rule so each arrives with one. SC-001, SC-007, SC-008 and SC-009 were verified here.

**One vocabulary split, left deliberate for now.** The reserved code is `EMAIL_NOT_VERIFIED` while both documents say "address" throughout. Renaming the code is free until an endpoint returns it, and that decision belongs to Identity, which builds the endpoint.

**Three defects were found and fixed during the run.** FR-003 promised to retract requests already past authentication, which no server can do, and was corrected to match the shipped baseline. Two of the quickstart's checks flagged the very sentences that exist to forbid what they search for, and their filters were corrected.

## Notes

- No task changes a PHP file, adds a dependency or touches the database. If one appears to, stop — it is out of scope for this feature.
- `[P]` means a different file. Same file is never parallel, in any phase.
- Transcribe from `data-model.md` and `contracts/` rather than re-deciding. A disagreement with those documents is a defect to raise, not a judgement call to make while writing.
- The baseline states rules and names the module that will build each one. A rule with no named owner is incomplete, which is what T032 and T050 check.
- Deferred on purpose and not to be added here: the authentication package and its approval (Identity), operator account creation (Admin), the purge job's schedule (Sync), the wallet role matrix (Wallets), second factors for application users, breach notification and penetration-testing cadence.
