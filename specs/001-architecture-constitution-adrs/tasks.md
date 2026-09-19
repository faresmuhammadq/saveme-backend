---

description: "Task list for 001-architecture-constitution-adrs"
---

# Tasks: Architecture Constitution & ADR Set

**Input**: Design documents from `/specs/001-architecture-constitution-adrs/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/](./contracts/)

**Tests**: No automated tests. This feature delivers documents only; the spec requests no test tasks. Verification runs through [quickstart.md](./quickstart.md), and code-level enforcement is follow-up feature F-1 (see plan.md).

**Organization**: Tasks are grouped by the four user stories in spec.md.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: US1–US4 from spec.md
- Paths are repository-relative

## Path Conventions

Documentation-only feature (see plan.md → Structure Decision):

- Constitution: `.specify/memory/constitution.md`
- ADRs: `docs/adr/NNNN-kebab-title.md`, index `docs/adr/README.md`, template `docs/adr/template.md`
- Spec-kit template touched once: `.specify/templates/plan-template.md`
- No files under `app/`, `database/`, `tests/` or `config/` are created or edited by this feature

**Source of truth for every task below**: `contracts/constitution-outline.md` (principle IDs P-01…P-17), `contracts/adr-template.md` (required ADR sections), `contracts/constitution-check.md` (gate table), `contracts/http-conventions.md` (API-wide shapes), `research.md` R-1…R-16 (decisions, rationale, rejected alternatives).

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Create the ADR home and its format contract, so every later ADR is written the same way.

- [X] T001 Create `docs/adr/` and write `docs/adr/template.md` as a verbatim copy of the fenced template block in `specs/001-architecture-constitution-adrs/contracts/adr-template.md` (sections: Status, Date, Principles, Supersedes, Resolves, Context, Decision, Alternatives Considered, Consequences → Positive/Negative/Follow-ups)
- [X] T002 [P] Write `docs/adr/README.md` with: purpose line, status legend (`Proposed | Accepted | Rejected | Deprecated | Superseded by ADR-NNNN`), the rule "an Accepted ADR is never rewritten, only superseded", and an empty index table with columns `ADR | Title | Status | Date | Principles`

**Checkpoint**: ADR format fixed; nothing yet decided.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Freeze the two identifier spaces (P-NN principles, NNNN ADRs) so constitution and ADRs can be written in parallel without cross-reference churn.

**⚠️ CRITICAL**: No user story work begins until T003 and T004 are done.

- [X] T003 Replace `.specify/memory/constitution.md` with the skeleton from `contracts/constitution-outline.md`: the `<!-- Sync Impact Report -->` comment placeholder plus empty sections in this exact order — Purpose & Precedence, Core Principles, Bounded Contexts & Modules, MVP Scope Guard, Quality Gates, Constitution Check, Governance — and the footer line `**Version**: 1.0.0 | **Ratified**: 2026-09-19 | **Last Amended**: 2026-09-19`. Title uses the codename as display text only (never as an identifier), per P-13
- [X] T004 [P] Seed the index table in `docs/adr/README.md` with 11 rows, `0001-database-engine` … `0011-time-and-timezones` (file names exactly as listed in plan.md → Deliverables), each with Status `Proposed` and its principle IDs from `contracts/constitution-outline.md`

**Checkpoint**: IDs frozen — US1 (constitution body) and US2 (ADR bodies) can now proceed in parallel.

---

## Phase 3: User Story 1 - Planner gates a feature plan against the constitution (Priority: P1) 🎯 MVP

**Goal**: A plan author can walk the constitution top to bottom and produce a pass/fail Constitution Check with no interpretation.

**Independent Test**: Take a plan draft (e.g. "Budgets per category per period") and fill the check table from the constitution alone. Every principle yields an unambiguous result; a plan that stores a decimal amount fails P-03 and cites ADR-0003.

**⚠️ File serialization**: T005–T010 all edit `.specify/memory/constitution.md`, so they run in sequence, not in parallel.

- [X] T005 [US1] Write "Purpose & Precedence" in `.specify/memory/constitution.md`: the constitution governs every feature plan, overrides conflicting guidance, and the ADRs carry the reasoning while the constitution states the rule (FR-016)
- [X] T006 [US1] Write all 17 principles in `.specify/memory/constitution.md` as `### P-NN <Name>` sections, one per row of the table in `contracts/constitution-outline.md`. Each gets: `**Status**: Active`, `**Non-negotiable**: yes|no` exactly as the NN column marks it, 1–5 numbered MUST/MUST NOT rules, a 1–3 sentence rationale, and `**ADRs**: ADR-NNNN…` (P-02, P-13, P-14, P-17 carry no ADR link). Rule wording must stay yes/no-checkable — no "should", "where possible" or "as appropriate"
- [X] T007 [US1] Write "Bounded Contexts & Modules" in `.specify/memory/constitution.md`: a 9-row table (`Context | Module dir | Owns | Publishes`) using the module directories and ownership notes from `research.md` R-2, plus prose for the `app/Modules/Shared` kernel (persistence-free value objects only), the layer dependency rule (Http → Application → Domain; Infrastructure → Domain), and the cross-module rule with its two permitted mechanisms (FR-005, FR-006, FR-007)
- [X] T008 [US1] Add the Reporting/Admin paragraph to the same section per `research.md` R-3: both read other modules through Application-layer query services returning DTOs, Reporting may add event-fed projections later, Admin writes only through application services, and there is no direct-persistence exception (FR-008)
- [X] T009 [US1] Write "MVP Scope Guard" in `.specify/memory/constitution.md` listing the brief's out-of-scope items verbatim — bank sync/SMS parsing, multi-currency transfers and FX, savings goals, bill reminders beyond recurring occurrences, AI features, iOS-specific work, web checkout with a registered payment provider, refunds automation, dark mode — and the rule that introducing any of them requires an amendment first (FR-009, P-17)
- [X] T010 [US1] Write "Quality Gates" and "Constitution Check" in `.specify/memory/constitution.md`: gates per `research.md` R-15 (Pint, Larastan level 7, Pest green on PostgreSQL once F-2 lands, a test per introduced invariant, completed check table), and the check procedure per `contracts/constitution-check.md` including the rule that a FAIL on a non-negotiable principle blocks the plan (FR-010, FR-011)

**Checkpoint**: US1 done — the constitution is usable as a planning gate even before the ADRs exist.

---

## Phase 4: User Story 2 - Reviewer rejects a violating change with a citation (Priority: P1)

**Goal**: Every architectural rule a reviewer needs is written as a citable numbered clause in exactly one ADR.

**Independent Test**: Give a reviewer only `docs/adr/` and run the 10 seeded violations in `quickstart.md` §4. All 10 map to the right ADR clause.

**Each ADR is its own file → all of T011–T021 are parallel.** Every one MUST follow `docs/adr/template.md`, take its Decision/Context/Alternatives from the matching `research.md` section, carry status `Accepted` and date `2026-09-19`, list ≥ 2 alternatives each with "Rejected because", and ≥ 1 Positive and ≥ 1 Negative consequence (FR-014).

- [X] T011 [P] [US2] Write `docs/adr/0001-database-engine.md` from `research.md` R-4: PostgreSQL 16+ in all environments including tests; rejected MySQL 8 and SQLite; criteria covered = native `uuid`, `timestamptz`, `FOR UPDATE` row locks, partial indexes, `jsonb`, CHECK constraints, managed hosting; negatives = Postgres service needed locally and in CI (F-2), slower than in-memory SQLite, mitigated by parallel test databases (FR-017)
- [X] T012 [P] [US2] Write `docs/adr/0002-identifiers-uuidv7.md` from `research.md` R-5: UUIDv7 in native `uuid` columns; server-side via Laravel 13 `HasUuids` (UUIDv7 by default); client-generated for syncable entities with `PUT /{id}` upsert; foreign-owned or wrong-type ID → 404 and never an overwrite; non-v7 → 422; ID timestamps never used for ordering, cursors, business dates or authorization; rejected bigint+temp-ID remapping and ULID/UUIDv4 (FR-018)
- [X] T013 [P] [US2] Write `docs/adr/0003-money-minor-units.md` from `research.md` R-6: `Money(int $amountMinor, Currency $currency)` in `app/Modules/Shared/Domain`; exponents EGP/SAR/AED = 2 and KWD/BHD/OMR = 3; storage `bigint` + `char(3)`; JSON `{"amount_minor": int, "currency": "EGP"}` per `contracts/http-conventions.md`; same-currency arithmetic only; `allocate()` uses largest-remainder so parts sum exactly; float/decimal/decimal-string forbidden everywhere; negatives allowed; rejected `decimal(19,4)` and `moneyphp/money` (FR-019)
- [X] T014 [P] [US2] Write `docs/adr/0004-ledger-transactions-and-postings.md` from `research.md` R-7 with `**Resolves**: O1`: accepted transactions-with-postings, rejected two-column model; postings sum to zero per currency against wallet accounts and the `income`/`expense` nominal accounts; worked mappings for expense, income, transfer, credit-card spend, debt and future FX; postings are internal, never exposed, replaced atomically on edit; MVP rejects mixed-currency transactions (FR-020)
- [X] T015 [P] [US2] Write `docs/adr/0005-derived-balance-with-cache.md` from `research.md` R-8: truth = `opening_balance_minor + Σ postings`; `wallets.balance_minor` is a cache written in the same DB transaction under `lockForUpdate` on the wallet row; cache never backs invariants, authorization, reports or reconciliation; scheduled `wallets:verify-balances` logs drift at `warning` and repairs; negative balances never blocked; rejected no-cache-always-sum and async cache (FR-021)
- [X] T016 [P] [US2] Write `docs/adr/0006-wallet-scoped-authorization.md` from `research.md` R-9: policies resolve `wallet_members.role` (owner/editor/viewer) via the Wallets application service, combined with `EntitlementService`; every wallet a transaction posts to is authorized; non-member → 404, not 403; platform admins use a separate `admin` guard owned by the Admin module, with no role column on app users; the permission matrix is referenced from the Wallets spec and never copied; rejected a global RBAC package and a `users.is_admin` flag (FR-022)
- [X] T017 [P] [US2] Write `docs/adr/0007-entitlements-from-subscriptions.md` from `research.md` R-10: `plans` (code, period, `features jsonb`) and `subscriptions` (provider ∈ {google_play, instapay}, provider_ref, status ∈ {active, grace, expired, revoked}, period bounds) with both providers normalising into one record; all checks through `EntitlementService::forUser()/forWallet()` against a `Feature` enum, never a plan code; owner's entitlements govern a shared wallet; free tier limited only on sharing; lapse/downgrade deletes no data and blocks plan-gated sharing actions, with member-level effects deferred to the Sharing spec; in-app exposes only restore-purchases and redeem-code while InstaPay claims are web-only; rejected plan-code checks, Cashier-style per-provider billables and user feature flags (FR-023)
- [X] T018 [P] [US2] Write `docs/adr/0008-sync-changes-feed.md` from `research.md` R-11: per-wallet and per-user feeds; `change_seq` counter on the scope row incremented under `lockForUpdate`, stamped on each entity; pull ordered by `change_seq` including tombstones; push `PUT /{id}` with `version`, and a mismatch rejects the **whole write** with 409 `VERSION_CONFLICT` returning the current entity, with no server-side merge (clarification Q2); idempotent replays; tombstones retained ≥ 90 days then purged, with an expired cursor → 410 `SYNC_CURSOR_EXPIRED` and full scope resync; undelete is an explicit write with `deleted_at: null`; membership revocation arrives in the user feed; guarantees = ordered within a scope, at-least-once, no cross-scope ordering; rejected global sequence / `updated_at` cursors, CRDTs, and both server-merge variants (FR-024)
- [X] T019 [P] [US2] Write `docs/adr/0009-domain-events-after-commit.md` from `research.md` R-12: events in `Modules/<X>/Domain/Events` carrying IDs and scalars plus `event_id` (UUIDv7) and `occurred_at`; `ShouldDispatchAfterCommit` plus queue `after_commit => true`; cross-module listeners `ShouldQueue` and idempotent keyed on `event_id`; database queue driver for MVP; no outbox with the crash-window risk stated, mitigations (reconciliation jobs for derived state, tolerable missed pushes) and the revisit triggers (money/billing-critical events, guaranteed-delivery integrations, observed loss); rejected outbox+relay, synchronous cross-module listeners and a broker (FR-025)
- [X] T020 [P] [US2] Write `docs/adr/0010-observability.md` from `research.md` R-13: Monolog `JsonFormatter` to stderr in production; middleware assigns a UUIDv7 `request_id` returned as `X-Request-Id`; `X-Correlation-Id` accepted when ≤ 128 chars of `[A-Za-z0-9._:-]` and otherwise replaced by the request id; both plus `user_id` stored in Laravel `Context` so they reach log entries, queued jobs and listeners; Sentry-compatible error tracking adopted in F-3 under dependency approval; never logged = passwords, tokens, OTPs, Play purchase tokens, InstaPay receipt images, full financial request bodies; 30-day retention; rejected line logs, OpenTelemetry now, and no correlation id (FR-026)
- [X] T021 [P] [US2] Write `docs/adr/0011-time-and-timezones.md` from `research.md` R-14: `APP_TIMEZONE=UTC`, DB session UTC, `timestampTz(6)` instants, `date` calendar columns, ISO 8601 `Z` in JSON, `users.timezone` as an IANA name; period membership is decided by calendar date so shared-wallet budgets are identical for every member; timezone only decides "today"/current period, report defaults and recurring due-ness; a recurring rule stores the IANA timezone captured at creation; changing a user's timezone rewrites nothing; rejected local-time-plus-offset (Egypt DST history), instant-typed transaction dates and per-wallet timezones (FR-027)
- [X] T022 [US2] Update every row in `docs/adr/README.md` to Status `Accepted`, date `2026-09-19`, with linked titles (depends on T011–T021)

**Checkpoint**: US2 done — all 11 decisions are citable. The quickstart §4 reviewer exercise can run.

---

## Phase 5: User Story 3 - Contributor understands why a decision was made (Priority: P2)

**Goal**: A newcomer can answer "why" from the ADRs alone, and can navigate between a rule and its reasoning in one hop.

**Independent Test**: Run `quickstart.md` §5 — 10 "why" questions, one per ADR 0002–0011, at least 9 answered correctly in 30 minutes from the documents alone.

- [X] T023 [US3] Verify and fix bidirectional links: every `**ADRs**:` reference in `.specify/memory/constitution.md` names an existing file in `docs/adr/`, and every ADR's `**Principles**:` line names an existing `P-NN`. Fix mismatches in whichever file is wrong (FR-016)
- [X] T024 [P] [US3] Add a "Reading order" paragraph to `docs/adr/README.md`: start at 0004 (ledger) → 0003 (money) → 0005 (balance) for the money model, 0002 → 0008 for offline sync, 0006 → 0007 for access, and note that ADRs record reasoning while the constitution states the enforceable rule
- [X] T025 [P] [US3] Add a `## Consequences → Follow-ups` entry to each ADR that has one, naming its follow-up feature ID from plan.md (F-1 arch tests in 0002/0003/0007, F-2 PostgreSQL environments in 0001, F-3 observability stack in 0010, F-4 shared kernel in 0003) so a reader sees what is decided but not yet built
- [X] T026 [US3] Run the quickstart §5 newcomer exercise (self-check by re-reading each ADR and answering its "why" question without other context); record any ADR that fails to answer its own question and expand that ADR's Context section

**Checkpoint**: US3 done — decisions are self-explaining and navigable.

---

## Phase 6: User Story 4 - Maintainer changes a decision without losing history (Priority: P3)

**Goal**: A decision can be reversed without rewriting history or silently invalidating past plans.

**Independent Test**: Simulate superseding ADR-0009 with an outbox ADR — the old file keeps its body, gains a forward link, the index shows both, and the constitution's P-11 points at the new number.

- [X] T027 [US4] Write "Governance" in `.specify/memory/constitution.md` per `research.md` R-15: amendment procedure, SemVer rule (MAJOR = principle retired or redefined, MINOR = principle added or materially expanded, PATCH = wording), repository owner ratifies, principle IDs are never reused or renumbered (retired principles are marked `Retired (vX.Y.Z)` and kept), an Accepted ADR changes only by supersession, and `/speckit-analyze` runs before each feature's implementation (FR-012, FR-015)
- [X] T028 [P] [US4] Document the supersession mechanics in `docs/adr/README.md`: new ADR gets the next free number with `**Supersedes**: ADR-NNNN`, the old file's status line becomes `Superseded by ADR-NNNN` with nothing else edited, the index keeps both rows, and the affected principle's `**ADRs**:` line is updated in the same change
- [X] T029 [US4] Align `.specify/templates/plan-template.md`: replace the `[Gates determined based on constitution file]` placeholder in its Constitution Check section with the table shape from `contracts/constitution-check.md` (columns `Principle | Result | Evidence / justification`, results PASS/FAIL/N-A, the non-negotiable-FAIL blocking rule, and re-check after Phase 1)
- [X] T030 [US4] Fill the `<!-- Sync Impact Report -->` comment at the top of `.specify/memory/constitution.md`: version `none → 1.0.0`, the 17 principles added, templates updated (`plan-template.md` ✅), follow-ups (F-1…F-4), and confirm the footer reads `**Version**: 1.0.0 | **Ratified**: 2026-09-19 | **Last Amended**: 2026-09-19` (depends on T005–T010, T027)

**Checkpoint**: US4 done — the constitution is ratified at 1.0.0 and amendable.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [X] T031 Run every structural check in `quickstart.md` §1–§2 and fix what fails: no template placeholders left, 11 ADRs numbered 0001–0011, all required sections present, ≥ 2 "Rejected because" per ADR, every ADR indexed, 17 `### P-NN` headings, O1 marked resolved in 0004, PostgreSQL in 0001, `VERSION_CONFLICT` in 0008
- [X] T032 [P] Verify P-13 by grepping the codename across `docs/adr/` and `.specify/memory/constitution.md`: it may appear only as display prose, never in a prescribed namespace, table name, package name or file path
- [X] T033 [P] Verify SC-002 traceability: walk the brief → principle → ADR table in `research.md` R-0 and confirm each row's target text now exists in the written documents
- [X] T034 Run `/speckit-analyze` and resolve any CRITICAL or HIGH inconsistency between `spec.md`, `plan.md`, `tasks.md`, the constitution and the ADRs (SC-006)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: no dependencies
- **Foundational (Phase 2)**: needs T001–T002; blocks all user stories
- **US1 (Phase 3)** and **US2 (Phase 4)**: both need Phase 2 only; independent of each other and can run in parallel
- **US3 (Phase 5)**: needs US1 + US2 (it verifies links between the two document sets)
- **US4 (Phase 6)**: needs US1 (T027/T030 edit the same constitution file); T029 is independent of everything but Phase 2
- **Polish (Phase 7)**: needs all desired stories

### Within Each Story

- Phase 3 (US1): strictly sequential T005 → T010, one file
- Phase 4 (US2): T011–T021 fully parallel, then T022
- Phase 5 (US3): T023 first (it may edit both document sets), then T024–T025 parallel, then T026
- Phase 6 (US4): T027 → T030; T028 and T029 parallel with them (different files)

### Parallel Opportunities

- T002 with T001's directory creation
- T004 with T003
- **Largest win**: the 11 ADR tasks T011–T021, one file each, no shared state
- T024 with T025; T028 with T029; T032 with T033

---

## Parallel Example: User Story 2

```bash
# All eleven ADRs at once — separate files, no dependencies:
Task: "Write docs/adr/0001-database-engine.md from research.md R-4"
Task: "Write docs/adr/0002-identifiers-uuidv7.md from research.md R-5"
Task: "Write docs/adr/0003-money-minor-units.md from research.md R-6"
Task: "Write docs/adr/0004-ledger-transactions-and-postings.md from research.md R-7"
Task: "Write docs/adr/0005-derived-balance-with-cache.md from research.md R-8"
Task: "Write docs/adr/0006-wallet-scoped-authorization.md from research.md R-9"
Task: "Write docs/adr/0007-entitlements-from-subscriptions.md from research.md R-10"
Task: "Write docs/adr/0008-sync-changes-feed.md from research.md R-11"
Task: "Write docs/adr/0009-domain-events-after-commit.md from research.md R-12"
Task: "Write docs/adr/0010-observability.md from research.md R-13"
Task: "Write docs/adr/0011-time-and-timezones.md from research.md R-14"
```

---

## Implementation Strategy

### MVP First (US1)

1. Phase 1 Setup → Phase 2 Foundational
2. Phase 3 (US1): constitution body
3. **STOP and VALIDATE**: fill a Constitution Check for a hypothetical Budgets plan using only the constitution
4. The constitution alone already gates plans, with ADR links pointing to files that are still `Proposed`

### Incremental Delivery

1. Setup + Foundational → ID spaces frozen
2. US1 → planning gate usable (MVP)
3. US2 → reviewers can cite; quickstart §4 exercise passes
4. US3 → newcomers self-serve; quickstart §5 passes
5. US4 → ratified at 1.0.0 and amendable; plan template aligned
6. Polish → quickstart §1–§2 clean, `/speckit-analyze` clean

### Parallel Team Strategy

After Phase 2: one person writes the constitution (US1, one file, sequential), another writes ADRs (US2, eleven files, parallelisable). They meet at T023.

---

## Notes

- Documents only. No `app/`, `database/`, `config/` or `tests/` file is touched, so `vendor/bin/pint` and Pest are not run for this feature.
- Enforcement of P-02, P-03 and P-09 by automated checks is follow-up F-1, listed in plan.md, not part of these tasks.
- All 11 ADRs are created directly as `Accepted`, per data-model.md Part A.
- Dates use `2026-09-19` (ratification date) throughout.
- Commit after each task or phase checkpoint.
