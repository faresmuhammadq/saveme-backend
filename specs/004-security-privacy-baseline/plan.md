# Implementation Plan: Security & Privacy Baseline

**Branch**: `004-security-privacy-baseline` | **Date**: 2026-09-22 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/004-security-privacy-baseline/spec.md`

## Summary

Write the security and privacy rules every later feature obeys, and the promises the product makes to its users, in two documents: `docs/security-baseline.md` for the people building and reviewing features, and `docs/privacy-commitments.md` for the person deciding whether to trust this app with their salary. Reserve the ten error codes those rules require in the shared catalogue, so Identity and Billing cannot invent two names for the same refusal months apart.

The contract fixes: one credential per device session with a 90-day sliding expiry and revocation that takes effect on the next request; argon2id with stated parameters; a lockout rule that resists guessing **without** handing an attacker a way to lock a victim out; uniform answers across sign-in, reset, registration and invitation so an address cannot be probed; a verification gate on writes but not reads; an attribute-by-attribute table of what is protected at rest, with the address encrypted beside a keyed lookup value; a fixed audit vocabulary and a hard exclusion list for what an entry may never contain; two retention horizons with every period written as a number; and verification of provider messages including the audience check that is the one people skip.

The design work is in [research.md](./research.md): 25 decisions, of which the consequential ones are the lockout rule that caps at 60 minutes and is cleared by a mailbox the attacker does not own (R-7), Identity owning the single audit log with Admin as a façade over it (R-17), crypto-shredding as what makes "irreversibly removed" survive a restored backup (R-13), and the finding that the spec's first draft contradicted an Accepted decision about logging amounts (R-2).

Documentation only. No application code, no migrations, no dependencies.

## Technical Context

**Language/Version**: N/A — Markdown, plus ten entries in an existing YAML file. (The service it governs is PHP 8.4 / Laravel.)

**Primary Dependencies**: none added. The authentication package the backlog names is Identity's decision and Identity's approval request, not this feature's.

**Storage**: N/A for the deliverables. Eleven shapes the baseline requires of later features are specified in [data-model.md](./data-model.md); each names the module that will build it.

**Testing**: no automated tests; no PHP file changes. Validation is the structural checks and four reviewer exercises in [quickstart.md](./quickstart.md), matching how specs 001 through 003 were verified.

**Target Platform**: repository documentation, plus published copy reused by a store listing and a public page.

**Project Type**: security contract and user-facing commitments for a modular-monolith web service.

**Constraints**: must not contradict constitution v1.1.0 or any Accepted ADR — one contradiction was found and resolved during planning, see R-2; must not restate the wallet permission matrix (P-08 rule 5); must not add a dependency without the owner's approval; every rule must be checkable against one endpoint, one stored attribute or one recorded entry (FR-038a).

**Scale/Scope**: 2 documents plus 10 reserved error codes; 11 required shapes across 4 owning modules; 19 audit events; 9 retention periods; 49 reviewer checks.

## Constitution Check

*Constitution v1.1.0 (`.specify/memory/constitution.md`). GATE: before Phase 0 research, re-checked after Phase 1 design.*

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | The baseline says wallet, wallet member and device session throughout; no synonym appears, and the departure rule is written in terms of wallet membership rather than a group or household |
| P-02 Module boundaries | PASS | The audit log is owned by Identity and written by other modules through its application service, which is one of the two mechanisms rule 4 permits. `Shared` gains nothing, per rule 5. No tenth context is invented (R-17) |
| P-03 Money | PASS | The protected-attributes table states that amounts are integer minor units held unencrypted at rest, and explains why; nothing in the baseline introduces another money representation |
| P-04 Ledger integrity | PASS | The departure rule keeps transactions authored in another member's wallet precisely so balances are not silently changed. No posting is exposed |
| P-05 Derived balances | N-A | The baseline touches no balance computation or cache |
| P-06 Identifiers | PASS | Every entity the baseline requires carries a UUIDv7 identifier; audit entries reference subjects by identifier rather than by name |
| P-07 Sync and concurrency | PASS | The 90-day tombstone horizon is honoured, not shortened. Retention takes two horizons rather than promising a number sync cannot keep (R-14) |
| P-08 Authorization | PASS | A non-member gets a not-found, restated as checklist item E6 rather than re-decided. The role matrix is not copied here |
| P-09 Entitlements | PASS | `entitlement_changed` is in the audit vocabulary; no plan code is compared anywhere in the baseline |
| P-10 Billing channels | PASS | Provider-message verification covers the in-app purchase path; nothing in the baseline opens a payment surface inside the app |
| P-11 Events | N-A | The baseline defines audit records, which are stored rows, not domain events. No listener or dispatch is introduced |
| P-12 Time | PASS | Every retention period and token lifetime is an instant in UTC; the retention table gives durations, not local dates |
| P-13 Naming | PASS | No codename in any document, error code, event name or attribute |
| P-14 Localisation | PASS | The commitments ship in Arabic and English in one file, so a copy change cannot land in one language only (R-23) |
| P-15 Observability | PASS | The never-log list is restated as ADR-0010 fixed it, and the audit entry carries the request correlation so an entry joins the server's logs (R-2, R-18) |
| P-16 Quality gates | N-A | No PHP file changes, so Pint, Larastan and Pest do not apply. Gate 3 — a test per invariant — binds the features that implement these rules, and the baseline names the owning module for each so no rule arrives without one |
| P-17 Scope guard | PASS | Nothing from the MVP Scope Guard is introduced. Second factors for application users, breach notification and penetration-testing cadence are explicitly excluded |

**Post-design re-check**: unchanged after Phase 1, with one item resolved during it.

**The resolved item**: the spec's FR-016 originally banned amounts from "any log", which contradicts ADR-0010 rule 8 — an Accepted decision that deliberately keeps debug-level amounts for diagnosing a balance complaint. The constitution is explicit that an Accepted decision is superseded by a new decision record, never by a spec talking over it. The requirement was narrowed rather than the decision reopened: the absolute ban covers tokens, passwords, one-time codes, receipts and full financial bodies, while amounts are barred from informational level and above and from anything leaving the service. The spec now carries FR-016, FR-016a and FR-016b, and the change is recorded in its Clarifications section. Had it gone unnoticed, P-15 would have been a FAIL.

Two design-time decisions were checked separately. **Identity owning the audit log** (R-17) was checked against P-02 rules 4 and 5 and against the constitution's context table, which gives Admin an `AdminAuditService` — that name survives as a façade over one store rather than a second store. **Crypto-shredding on account purge** (R-13) was checked against P-07: it destroys key material for a departed account and touches no tombstone, so the sync horizon is unaffected.

No `FAIL` on any principle, so Complexity Tracking stays empty.

## Project Structure

### Documentation (this feature)

```text
specs/004-security-privacy-baseline/
├── plan.md              # This file
├── research.md          # Phase 0: 25 decisions (R-1 … R-25) with rejected alternatives
├── data-model.md        # Phase 1: the 11 shapes later features must build, plus retention and codes
├── quickstart.md        # Phase 1: structural checks + four reviewer exercises
├── contracts/
│   ├── protected-attributes.md       # the per-attribute table, the lookup value, key rotation
│   ├── sessions-and-credentials.md   # sessions, passwords, tokens, lockout, uniform answers, the gate
│   ├── audit-entry.md                # ownership, the entry, the fixed vocabulary, the exclusion list
│   ├── retention-and-departure.md    # two horizons, deletion, shared-wallet departure, export
│   └── security-review-checklist.md  # FR-038a: 49 checks a reviewer applies to one thing at a time
├── checklists/requirements.md
└── tasks.md             # Phase 2 (/speckit-tasks — not created here)
```

### Deliverables (repository root)

```text
docs/security-baseline.md          # the rules every later feature obeys
docs/privacy-commitments.md        # the published promises, Arabic and English, with their mapping
docs/openapi/error-catalog.yaml    # gains 10 reserved codes: 38 → 48
specs/README.md                    # A4 row → done
```

**Structure Decision**: both documents live in `docs/`, beside the glossary and the API conventions, for the same reason those do — their readers include the Android and landing-page teams, who have no reason to open a spec folder. The commitments are a **separate file** rather than a section, because a public page has a different audience, tone and lifecycle, and because keeping them apart makes FR-036's one-to-one mapping auditable by diffing one file against one table.

## Phase 0 output

[research.md](./research.md) — 25 decisions, each with rationale and rejected alternatives. The consequential ones:

- **R-2** the spec contradicted ADR-0010 on logging amounts. Narrowed the requirement rather than reopening an Accepted decision.
- **R-7** the lockout rule: caps at 60 minutes, never permanent, cleared by a password reset that requires the mailbox. That is what stops the lock being a weapon against the account's owner, which is the edge case the spec names.
- **R-8** all four probe surfaces answer uniformly, with the difference moved into the mail body. Three uniform and one leaking makes the other three pointless.
- **R-10 / R-11** amounts are deliberately **not** encrypted at rest, and the baseline says so per attribute instead of claiming everything is protected. The address is encrypted with a keyed lookup value whose limit is stated rather than hidden.
- **R-13** crypto-shredding is what makes "irreversibly removed" survive a backup restored after the purge.
- **R-17** Identity owns the one audit log; Admin's constitution-named service becomes a façade over it, not a second store.
- **R-20** a provider message is checked for audience, not only signature. That is what stops a validly signed message meant for another service from granting a subscription here.
- **R-22** ten codes are reserved in the shared catalogue now, before any endpoint returns them, so two features cannot name the same refusal differently.

## Phase 1 output

- [data-model.md](./data-model.md) — the eleven shapes the baseline requires, each naming its owning module; the protected-attributes table; the audit vocabulary of 19 events; every retention period as a number; and the ten reserved codes ready to transcribe.
- [contracts/protected-attributes.md](./contracts/protected-attributes.md) — the table, the normalisation rules for the lookup value, and the key-rotation sequence including the harder index case.
- [contracts/sessions-and-credentials.md](./contracts/sessions-and-credentials.md) — session, password and token rules, the lockout table, the four uniform answers, the verification gate and transport.
- [contracts/audit-entry.md](./contracts/audit-entry.md) — who owns the log and why, the entry's fields, the fixed vocabulary, and the list of what may never be inside one.
- [contracts/retention-and-departure.md](./contracts/retention-and-departure.md) — the two horizons, the deletion state machine, what happens to a shared wallet when a member departs, and export.
- [contracts/security-review-checklist.md](./contracts/security-review-checklist.md) — 49 yes-or-no checks in nine groups, which is FR-038a discharged.
- [quickstart.md](./quickstart.md) — six structural checks that run with no new dependency, plus the SC-009 seeded-violation exercise, the SC-008 downstream-author exercise, a hand-walk of the lockout rule, and the SC-007 commitment audit.

## Downstream effects

| Spec | What it inherits |
|---|---|
| Identity & auth | Everything about sessions, passwords, tokens, lockout, the uniform answers, the verification gate, the protected-attributes table and the lookup value. It also owns the audit log and asks for the authentication dependency |
| Billing | Provider-message verification including the audience check and replay handling; `entitlement_changed` in the audit vocabulary |
| Sharing | `membership_granted`, `membership_revoked` and `role_changed`; the uniform invitation answer; the departure rule for a shared wallet |
| Sync | The two retention horizons and the purge they imply. The tombstone horizon is unchanged |
| Admin | The second-factor rule, the two-operator recovery path, and `AdminAuditService` as a façade over Identity's log rather than a second store |
| A5 engineering conventions | Ten more catalogue codes to lint once a linter is approved (follow-up F-5) |
| Every feature | The 49-item review checklist as part of the definition of done for its endpoints |

## Complexity Tracking

No Constitution Check violations. Table intentionally empty.
