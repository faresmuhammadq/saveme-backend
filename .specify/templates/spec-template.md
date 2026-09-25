# Feature Specification: [FEATURE NAME]

**Feature Branch**: `[###-feature-name]`

**Created**: [DATE]

**Status**: Draft

**Input**: User description: "$ARGUMENTS"

## User Scenarios & Testing *(mandatory)*

<!--
  IMPORTANT: User stories should be PRIORITIZED as user journeys ordered by importance.
  Each user story/journey must be INDEPENDENTLY TESTABLE - meaning if you implement just ONE of them,
  you should still have a viable MVP (Minimum Viable Product) that delivers value.

  Assign priorities (P1, P2, P3, etc.) to each story, where P1 is the most critical.
  Think of each story as a standalone slice of functionality that can be:
  - Developed independently
  - Tested independently
  - Deployed independently
  - Demonstrated to users independently
-->

### User Story 1 - [Brief Title] (Priority: P1)

[Describe this user journey in plain language]

**Why this priority**: [Explain the value and why it has this priority level]

**Independent Test**: [Describe how this can be tested independently - e.g., "Can be fully tested by [specific action] and delivers [specific value]"]

**Acceptance Scenarios**:

1. **Given** [initial state], **When** [action], **Then** [expected outcome]
2. **Given** [initial state], **When** [action], **Then** [expected outcome]

---

### User Story 2 - [Brief Title] (Priority: P2)

[Describe this user journey in plain language]

**Why this priority**: [Explain the value and why it has this priority level]

**Independent Test**: [Describe how this can be tested independently]

**Acceptance Scenarios**:

1. **Given** [initial state], **When** [action], **Then** [expected outcome]

---

### User Story 3 - [Brief Title] (Priority: P3)

[Describe this user journey in plain language]

**Why this priority**: [Explain the value and why it has this priority level]

**Independent Test**: [Describe how this can be tested independently]

**Acceptance Scenarios**:

1. **Given** [initial state], **When** [action], **Then** [expected outcome]

---

[Add more user stories as needed, each with an assigned priority]

### Edge Cases

<!--
  ACTION REQUIRED: The content in this section represents placeholders.
  Fill them out with the right edge cases.
-->

- What happens when [boundary condition]?
- How does system handle [error scenario]?

## Requirements *(mandatory)*

<!--
  ACTION REQUIRED: The content in this section represents placeholders.
  Fill them out with the right functional requirements.
-->

### Functional Requirements

- **FR-001**: System MUST [specific capability, e.g., "allow users to create accounts"]
- **FR-002**: System MUST [specific capability, e.g., "validate email addresses"]
- **FR-003**: Users MUST be able to [key interaction, e.g., "reset their password"]
- **FR-004**: System MUST [data requirement, e.g., "persist user preferences"]
- **FR-005**: System MUST [behavior, e.g., "log all security events"]

*Example of marking unclear requirements:*

- **FR-006**: System MUST authenticate users via [NEEDS CLARIFICATION: auth method not specified - email/password, SSO, OAuth?]
- **FR-007**: System MUST retain user data for [NEEDS CLARIFICATION: retention period not specified]

### Key Entities *(include if feature involves data)*

- **[Entity 1]**: [What it represents, key attributes without implementation]
- **[Entity 2]**: [What it represents, relationships to other entities]

## Project Sections *(mandatory for feature specs)*

<!--
  Project-specific, added by spec 005 (engineering conventions). Every feature
  spec in this repository carries these nine sections, in this order, so two
  specs written months apart have the same bones. The workflow's own mandatory
  sections above and below stay as they are.

  Foundation specs (constitution, glossary, conventions) may mark a section
  "Not applicable — <one-line reason>". Never delete a heading: an empty
  section is a visible decision, a missing one is an oversight.
-->

### 1. Purpose

[One paragraph: what this feature makes possible, and for whom. No mechanism.]

### 2. Glossary terms

[Every domain term this spec uses, each linked to its entry in docs/glossary.md. A term that is not in the glossary is added there IN THIS CHANGE, before this spec uses it — the glossary's own rule.]

### 3. Data model

[The entities this feature owns or changes: fields, types, scope key (user_id or wallet_id), lifecycle states. Follow the glossary's field conventions; name the owning module for each entity.]

### 4. Invariants

[Every rule that must always hold, as a table. Each invariant names the test that fails when it is broken — an invariant without a test is a wish.]

| Invariant | Principle or decision | Test that fails when broken |
|---|---|---|
| [e.g. postings of one transaction sum to zero per currency] | [P-04, ADR-0012] | [tests/Feature/Wallets/…] |

### 5. API

[The endpoints: method, path, the path file under docs/openapi/paths/, and the error codes each returns from the catalogue. Shapes are defined in the path file, not repeated here.]

### 6. Authorization and entitlements

[Who may do what, by wallet role, referencing the Wallets spec's matrix rather than copying it. Which Feature each capability is gated on, checked through EntitlementService. What a non-member sees: a not-found.]

### 7. Events

[Domain events this feature publishes and consumes: name, payload of identifiers and scalars, and which modules listen. Dispatched after commit; listeners idempotent.]

### 8. Acceptance scenarios

[An index from each user story's scenarios above to the test layer that proves it — Unit, Feature or Contract — and the test file.]

### 9. Open questions

[What is not yet decided, who decides it, and whether it blocks. "None." is an answer; a deleted heading is not.]

## Success Criteria *(mandatory)*

<!--
  ACTION REQUIRED: Define measurable success criteria.
  These must be technology-agnostic and measurable.
-->

### Measurable Outcomes

- **SC-001**: [Measurable metric, e.g., "Users can complete account creation in under 2 minutes"]
- **SC-002**: [Measurable metric, e.g., "System handles 1000 concurrent users without degradation"]
- **SC-003**: [User satisfaction metric, e.g., "90% of users successfully complete primary task on first attempt"]
- **SC-004**: [Business metric, e.g., "Reduce support tickets related to [X] by 50%"]

## Assumptions

<!--
  ACTION REQUIRED: The content in this section represents placeholders.
  Fill them out with the right assumptions based on reasonable defaults
  chosen when the feature description did not specify certain details.
-->

- [Assumption about target users, e.g., "Users have stable internet connectivity"]
- [Assumption about scope boundaries, e.g., "Mobile support is out of scope for v1"]
- [Assumption about data/environment, e.g., "Existing authentication system will be reused"]
- [Dependency on existing system/service, e.g., "Requires access to the existing user profile API"]
