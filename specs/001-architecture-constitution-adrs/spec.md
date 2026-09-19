# Feature Specification: Architecture Constitution & ADR Set

**Feature Branch**: `001-architecture-constitution-adrs`

**Created**: 2026-09-15

**Status**: Draft

**Input**: User description: "Produce the constitution and the ADR set that every feature plan must obey. Bounded contexts: Identity, Wallets & Ledger, Budgeting, Sharing, Plans & Billing, Sync, Reporting, Notifications, Admin. Modular monolith: one Laravel app, one module directory per context (app/Modules/<Context>/{Domain,Application,Infrastructure,Http}), cross-module calls only through application-layer services or domain events, never through another module's Eloquent models. Decisions to record (one ADR each, with alternatives and consequences): 1. Database engine. 2. IDs. 3. Money. 4. Ledger model (resolve O1). 5. Balance. 6. Authorization. 7. Entitlements. 8. Sync. 9. Events. 10. Observability. 11. Time."

> **Nature of this feature.** The deliverable is governance documentation, not end-user functionality. Its "users" are the people and agents who plan, build and review every later feature of this backend. Architectural vocabulary (modules, postings, cursors, UUIDv7) is the subject matter of this feature, not leaked implementation detail.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Planner gates a feature plan against the constitution (Priority: P1)

A person or agent writing an implementation plan for any MVP feature (e.g. Budgets, Recurring transactions) opens the constitution, walks its principles one by one, and records pass/fail for each in the plan's Constitution Check. Every principle is phrased so the answer is yes or no, with no interpretation needed.

**Why this priority**: The constitution exists to stop plans from drifting from the locked decisions. Without checkable principles, every later feature re-argues money, IDs, sync and authorization.

**Independent Test**: Take one realistic plan draft (e.g. "Budgets per category per period") and run the Constitution Check using only the constitution. Every principle yields an unambiguous pass/fail, and each fail cites the principle and the ADR that governs it.

**Acceptance Scenarios**:

1. **Given** a plan that stores an amount as a decimal, **When** the planner runs the Constitution Check, **Then** the money principle fails and points to ADR-003.
2. **Given** a plan in which the Budgeting module reads the Ledger's persistence models directly, **When** the check runs, **Then** the module-boundary principle fails and names the allowed alternatives (application service or domain event).
3. **Given** a plan that complies with every principle, **When** the check runs, **Then** every principle is marked pass with no "N/A without reason" entries.

---

### User Story 2 - Reviewer rejects a violating change with a citation (Priority: P1)

A code reviewer looking at a pull request finds a violation (a float in a money calculation, a plan-code comparison, a timestamp stored in local time, a transfer written as two independent transactions). They cite a specific numbered principle or ADR clause instead of arguing from opinion.

**Why this priority**: Decisions only hold if they are enforced at review time. This story makes the documents usable as a rulebook, not only as background reading.

**Independent Test**: Seed a list of 10 known violations, one per ADR theme. A reviewer with only the constitution and ADRs maps each violation to exactly one principle/ADR clause.

**Acceptance Scenarios**:

1. **Given** code that checks `plan == "premium"`, **When** reviewed, **Then** the reviewer cites the entitlements principle and ADR-007 ("capability checks only through the entitlement service").
2. **Given** a create endpoint that generates a server ID for a syncable entity, **When** reviewed, **Then** the reviewer cites ADR-002 (client-generated IDs, idempotent upsert).
3. **Given** a write that blocks a spend because the balance would go negative, **When** reviewed, **Then** the reviewer cites the locked "negative balances allowed" rule.

---

### User Story 3 - Contributor understands why a decision was made (Priority: P2)

A new contributor (or a future maintainer, or the Android app team) wants to know why the ledger uses postings instead of a two-column income/expense model, or why there is no outbox. They read one ADR and get the context, the alternatives weighed, the choice, and the consequences accepted, including the costs.

**Why this priority**: Without the reasoning, decisions get reversed by people who don't know the trade-off. It is needed before scaling the team, but not to unblock the first plan.

**Independent Test**: Give a newcomer 10 "why" questions (one per ADR, e.g. "why integer minor units?", "why no outbox?"). They answer each correctly from the ADRs alone.

**Acceptance Scenarios**:

1. **Given** ADR-004, **When** a reader looks for the resolution of open question O1, **Then** they find the postings model marked Accepted, the two-column model marked Rejected, and the reasons (transfers, debts, future multi-currency).
2. **Given** ADR-009, **When** a reader asks what happens if a listener fails after commit, **Then** the Consequences section states the failure window and how it is mitigated.

---

### User Story 4 - Maintainer changes a decision without losing history (Priority: P3)

A maintainer finds that an accepted decision must change (e.g. an outbox becomes necessary once billing webhooks are added). They follow the governance process: write a new ADR that supersedes the old one, update the linked constitution principle, bump the constitution version, and plans written under the old rule stay traceable.

**Why this priority**: Rarely used early on, but without it the documents rot or get silently edited.

**Independent Test**: Simulate superseding ADR-009 with a new outbox ADR. The old ADR stays readable with status "Superseded by ADR-0NN", the constitution version changes according to its versioning rule, and the link in the constitution now points to the new ADR.

**Acceptance Scenarios**:

1. **Given** an accepted ADR, **When** it is superseded, **Then** its body is left unchanged except for the status line and a forward link.
2. **Given** an amendment that removes or redefines a principle, **When** it is ratified, **Then** the constitution's major version increments and the Sync Impact Report lists the affected templates and plans.

---

### Edge Cases

These are the boundary questions the documents must answer explicitly, so plans do not invent their own answers:

- **Cross-module reads for Reporting and Admin**: both need data owned by other modules. The constitution states whether they read through published query services, through their own event-fed projections, or through a documented read-only exception. Raw access to another module's persistence models stays forbidden.
- **Transfer between wallets with different roles**: a member who can edit wallet A but only view wallet B cannot post a transfer touching B. ADR-006 states that authorization is evaluated on every wallet a transaction posts to.
- **Transfer between wallets in different currencies**: FX is out of MVP scope. ADR-003/ADR-004 state that postings within one transaction must share a currency, and that cross-currency transfers are rejected in MVP.
- **Client-generated ID collisions or reuse**: a client sends a PUT with an ID that already belongs to another user's entity or another entity type. ADR-002 states that this is rejected and never overwrites or reveals the existing record.
- **Client clock skew**: UUIDv7 embeds client time. ADR-002/ADR-008 state that ID timestamps are never used for ordering, sync cursors or business dates.
- **Owner downgrades or subscription lapses on a shared wallet**: ADR-007 states that entitlement loss never deletes data, and states which actions become blocked. The detailed sharing behaviour is deferred to the Sharing spec.
- **Cached balance drift**: the cached balance disagrees with opening + Σ postings. ADR-005 states that the derived value wins, how drift is detected, and that no invariant or authorization decision reads the cache.
- **Stale offline edits after deletion**: a client edits an entity that another member already tombstoned. Because the tombstone bumped the version, the stale write gets 409 `VERSION_CONFLICT` and returns the tombstone. ADR-008 states whether the client may then restore the entity by an explicit un-delete write, or must discard its edit.
- **Listener failure after commit**: the business write committed but a queued listener failed. ADR-009 states retry and idempotency expectations and accepts the no-outbox risk explicitly.
- **User changes timezone**: ADR-011 states that stored UTC instants and calendar dates (YYYY-MM-DD) are not rewritten. Only read models and reports change.
- **Leaking the codename**: the constitution forbids the working name in namespaces, table names and package identifiers, so a module or table named after "Save Me" is a violation.

## Requirements *(mandatory)*

### Functional Requirements

#### Constitution

- **FR-001**: The project MUST have one constitution that replaces the unfilled template, with a version, a ratification date and a last-amended date.
- **FR-002**: Every constitution principle MUST be written as a verifiable rule (MUST / MUST NOT) with a short rationale and a link to the ADR(s) that detail it.
- **FR-003**: The constitution MUST record the product's locked decisions as non-negotiable principles, including at least:
  - "Wallet" is the only term for a money container
  - derived balances
  - double-entry postings under a single-entry UX
  - negative balances allowed and never blocked at write time
  - liabilities stored negative
  - integer minor-unit money with ISO 4217 code
  - UUIDv7 with client-generated IDs and idempotent upserts for syncable entities
  - `version`/`updated_at`/`deleted_at` with optimistic concurrency returning a version conflict
  - plans as data behind a single entitlement service
  - free tier limited only on sharing
  - owner's entitlements govern a shared wallet
  - the billing channel split (Play in app, InstaPay claims on web only)
  - wallet-scoped authorization, with the permission matrix living only in the Wallets spec
  - UTC storage
  - codename confined to config/translations
  - Arabic/English with Western numerals
- **FR-004**: The constitution MUST define the nine bounded contexts (Identity, Wallets & Ledger, Budgeting, Sharing, Plans & Billing, Sync, Reporting, Notifications, Admin), each with a one-paragraph statement of responsibility and the data it owns.
- **FR-005**: The constitution MUST define the modular-monolith structure: one application, one module directory per context with Domain, Application, Infrastructure and Http layers, and the allowed dependency direction between layers.
- **FR-006**: The constitution MUST forbid any module from using another module's persistence models, and MUST name the only permitted cross-module mechanisms: calls to the other module's application-layer services, and domain events.
- **FR-007**: The constitution MUST define what may be shared across modules (a shared kernel of pure value objects and primitives such as Money, identifiers and clock). It MUST forbid shared persistence models.
- **FR-008**: The constitution MUST state how Reporting and Admin get cross-module data without breaking FR-006.
- **FR-009**: The constitution MUST record the explicit MVP out-of-scope list, so a plan that introduces any of those capabilities fails the Constitution Check.
- **FR-010**: The constitution MUST define a Constitution Check procedure that every feature plan completes: one line per principle, pass/fail, and a justification for any accepted deviation.
- **FR-011**: The constitution MUST define quality gates every feature meets before merge: automated tests covering the feature's invariants, code-style and static-analysis checks passing, and the Constitution Check recorded in the plan.
- **FR-012**: The constitution MUST define governance: how principles are amended, the semantic versioning rule (major = principle removed or redefined, minor = principle added or materially expanded, patch = wording), who ratifies, and that the constitution overrides conflicting guidance.

#### ADR set: common format

- **FR-013**: The project MUST have exactly one ADR per decision listed below (ADR-001 to ADR-011), numbered sequentially and stored in one ADR directory with an index.
- **FR-014**: Every ADR MUST contain: title, status (Proposed / Accepted / Superseded by ADR-NNN / Deprecated), date, context, decision, at least two alternatives considered with the reason each was rejected, consequences (positive and negative), and links to the constitution principle(s) it backs.
- **FR-015**: An accepted ADR MUST NOT be rewritten to change its decision. Changes MUST come from a new ADR that supersedes it, and the old ADR's status MUST link forward to the new one.
- **FR-016**: The constitution and the ADRs MUST NOT contradict each other. Where they overlap, the constitution states the rule and the ADR carries the reasoning.

#### ADR set: required decisions

- **FR-017 (ADR-001 Database engine)**: MUST select the production database engine for the MVP and justify it against at least one alternative. The justification MUST cover native UUID storage, transactional integrity for multi-posting writes, row-level locking for optimistic concurrency and balance recomputation, a mechanism for monotonic sync cursors, and managed-hosting availability. Accepted choice: **PostgreSQL**, with MySQL 8 and SQLite recorded as rejected alternatives. The automated test suite MUST run against PostgreSQL, so engine-specific behaviour is covered by tests; SQLite is not an accepted test substitute.
- **FR-018 (ADR-002 Identifiers)**: MUST decide on UUIDv7 for all entities. Clients generate IDs for syncable entities, making create an idempotent upsert keyed by ID. The server generates IDs for all other entities. MUST define rejection of IDs that collide with another owner's or another type's entity, and MUST forbid using ID-embedded timestamps for ordering or business logic.
- **FR-019 (ADR-003 Money)**: MUST decide on integer minor units plus ISO 4217 code, a single Money value object in the shared kernel, and no floats or decimal strings in storage, API payloads or arithmetic. MUST define minor-unit exponent handling per currency, rounding and allocation rules (e.g. splitting an installment), same-currency enforcement for arithmetic, and the representation of negative amounts.
- **FR-020 (ADR-004 Ledger model, resolves O1)**: MUST mark O1 resolved. Accepted: transactions with postings (double-entry underneath a single-entry UX). Rejected: a two-column income/expense model. MUST show how income, expense, transfer (one transaction, two postings), liabilities/debts (stored negative) and future multi-currency map onto postings. MUST state the per-transaction balancing invariant, the MVP same-currency restriction, and how edits and deletions of a transaction affect its postings.
- **FR-021 (ADR-005 Balance)**: MUST decide that a wallet's balance is derived as opening balance + Σ postings. A cached balance column is recomputed inside the same write that changes postings, and it is never read for invariants, authorization or reconciliation. MUST define drift detection and repair, and MUST confirm that negative balances are never blocked.
- **FR-022 (ADR-006 Authorization)**: MUST decide that authorization is wallet-scoped policy keyed on the member's role in that wallet (owner / editor / viewer), combined with entitlement checks. The only global role is platform admin. MUST reference the Wallets spec as the sole home of the permission matrix instead of duplicating it. MUST require evaluation against every wallet a transaction touches, and MUST state that a non-member receives a not-found response, not a forbidden response, if that is the chosen policy for existence leakage.
- **FR-023 (ADR-007 Entitlements)**: MUST decide that entitlements come from provider-agnostic subscriptions (Google Play and InstaPay claims both normalise into the same subscription record). Plans are data (period plus feature flags/quotas). All capability checks go through a single entitlement service, and nothing compares plan codes. The wallet owner's entitlements govern a shared wallet. MUST state behaviour on lapse or downgrade (no data deletion, which actions become blocked) and that the free tier is limited only on sharing.
- **FR-024 (ADR-008 Sync)**: MUST decide on a per-wallet changes feed with a server-assigned monotonic cursor, tombstones via `deleted_at`, and version-checked writes. Conflict semantics: every write carries the version the client last saw; if it differs from the stored version, the server rejects the **whole write** with 409 `VERSION_CONFLICT` and returns the current entity; the server never merges fields. Any field-level merge ("last write wins" per field) is done by the client before it resends with the current version. Field-level last-write-wins with server-side merging is a rejected alternative. MUST also define the pull/push contract shape at the level of guarantees (ordering, at-least-once delivery, idempotent replays), tombstone retention and what a client does after retention expires, and how membership changes (being removed from a wallet) show up in the feed.
- **FR-025 (ADR-009 Events)**: MUST decide that domain events are dispatched only after the database transaction commits and are handled by queued listeners, and that listeners are idempotent. MUST record that an outbox is not required for MVP, the accepted risk of losing events between commit and dispatch, the mitigation, and the trigger that would force revisiting (e.g. billing webhooks or external integrations).
- **FR-026 (ADR-010 Observability)**: MUST decide on structured JSON logs, a server-generated request ID on every request and response, acceptance and propagation of a client-supplied correlation ID into logs, queued jobs and events, and an error-tracking service (Sentry or equivalent). MUST define what is never logged (tokens, passwords, full financial payloads) and the retention expectation.
- **FR-027 (ADR-011 Time)**: MUST decide that all instants are stored in UTC, the user's timezone is applied only in read models and reports, and calendar dates such as the transaction date are stored as `YYYY-MM-DD` without timezone. MUST define how period boundaries (budget month, report ranges, recurring occurrences) are computed in the user's timezone, and which timezone applies on a shared wallet.

### Key Entities

- **Constitution**: the single governing document. Holds versioned principles, bounded-context map, module rules, quality gates, Constitution Check procedure and governance.
- **Principle**: a numbered, verifiable rule with rationale and links to backing ADRs, marked non-negotiable or amendable.
- **Bounded Context**: one of the nine modules. Has a responsibility, owned data, published application services and published domain events.
- **ADR**: a numbered decision record with status, context, decision, alternatives, consequences and links. Can supersede or be superseded.
- **ADR Index**: list of all ADRs with number, title and current status.
- **Constitution Check**: the per-plan record of pass/fail for every principle, with justifications for accepted deviations.
- **Open Question (O1…)**: a tracked undecided item that an ADR resolves. O1 (ledger model) is resolved by ADR-004.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: All 11 listed decisions have an Accepted ADR, and 100% of them contain at least two rejected alternatives and both positive and negative consequences.
- **SC-002**: 100% of the brief's locked product decisions appear as a constitution principle or ADR clause. Spot-check: every bullet in the brief maps to one cited location.
- **SC-003**: Given 10 seeded violations (one per ADR theme except Database engine), a reviewer maps 10 of 10 to the correct principle or ADR using only these documents.
- **SC-004**: A newcomer answers at least 9 of 10 "why was this decided" questions correctly from the ADRs alone, within 30 minutes.
- **SC-005**: The first feature plan written after ratification completes its Constitution Check with a pass/fail for every principle and zero "unclear/needs discussion" entries.
- **SC-006**: A cross-artifact consistency review finds zero contradictions between the constitution, the ADRs and the brief.
- **SC-007**: At least the module-boundary rule, the no-float money rule and the no-plan-code-comparison rule are phrased so each can be verified by an automated check, not only by human review.

## Clarifications

### Session 2026-09-15

- Q: Which production database engine does ADR-001 accept? → A: PostgreSQL; tests run on PostgreSQL too.
- Q: Stale-version write: reject, merge non-overlapping fields, or silent field-level LWW? → A: Reject the whole write with 409 `VERSION_CONFLICT`; no server-side merge. Clients merge and resend.

## Assumptions

- Output locations: the constitution replaces `.specify/memory/constitution.md`, and ADRs live under `docs/adr/` as `NNNN-kebab-title.md` with a `README.md` index. The user explicitly requested these documents.
- ADR format follows a lightweight MADR-style layout that satisfies FR-014. No tooling is added.
- Laravel conventions already in the repo (Pest tests, Pint formatting, Larastan static analysis) are the concrete tools behind the FR-011 quality gates. No new dependencies.
- A small shared kernel (Money, identifiers, clock/time, base domain event) is allowed, and is not counted as a tenth bounded context.
- The default `app/Models/User.php` scaffold will move under the Identity module when that feature is built. This spec does not relocate code.
- The permission matrix is not written here. ADR-006 references the future Wallets spec as its only home.
- Reporting and Admin reach cross-module data through published application query services, with event-fed projections allowed later for performance. Direct access to another module's persistence models is not an exception.
- Non-member access to a wallet returns not-found, to avoid disclosing that the wallet exists.
- On a shared wallet, period boundaries use the viewing user's timezone for read models. Stored data is identical for all members.
- Tombstone retention defaults to at least 90 days. A client whose cursor is older than retention performs a full resync of that wallet.
- The error tracker is Sentry-compatible. The exact vendor is left to the observability plan, and adding the SDK needs dependency approval at that time.
- "Ratified" means the repository owner approves the constitution. There is no multi-person approval process yet.
