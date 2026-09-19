---

description: "Task list for 002-domain-glossary-model-map"
---

# Tasks: Domain Glossary & Model Map

**Input**: Design documents from `/specs/002-domain-glossary-model-map/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/](./contracts/)

**Tests**: No automated tests. The deliverable is a reference document; the spec requests no test tasks. Validation is [quickstart.md](./quickstart.md).

**Organization**: Tasks are grouped by the four user stories in spec.md.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: US1–US4 from spec.md
- Paths are repository-relative

## Path Conventions

Documentation-only feature (plan.md → Structure Decision):

- Deliverable: `docs/glossary.md` (single file)
- Also touched: `docs/legacy/DATA_MODEL.md` (pointer), `specs/README.md` (status)
- No files under `app/`, `database/`, `config/`, `routes/` or `tests/`

**⚠️ One-file serialization**: T002–T017 all edit `docs/glossary.md`, so they run **in sequence**. Parallelism is only available in the polish phase and for the two files outside the deliverable.

**Source of truth per task**: `data-model.md` Part B (the 22-row index), Part C (lifecycles), Part D (prior art) · `contracts/glossary-entry-format.md` (entry shape, document section order) · `contracts/naming-rules.md` (three tables verbatim) · `research.md` R-1…R-13 (why each assignment).

---

## Phase 1: Setup

**Purpose**: Create the deliverable with its section skeleton, so every later task fills a named place instead of inventing structure.

- [X] T001 Create `docs/glossary.md` with the eight required sections in the exact order from `contracts/glossary-entry-format.md` → "Required document sections": Purpose and precedence · How to read an entry · The entity index · Arabic terms · Banned synonyms · Field conventions · Prior-art mapping · Deferred, not built. Headings only, no body yet

**Checkpoint**: Structure fixed; nothing yet defined.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Write the two sections every entry depends on. No entry can be written before the entry format is stated.

**⚠️ CRITICAL**: T002 and T003 block all user stories.

- [X] T002 Write "Purpose and precedence" in `docs/glossary.md`: this document is the single authoritative entity index (FR-001); every later spec references it rather than redefining entities; it states *what* exists and *who owns it*, never schemas, endpoints, payloads or the permission matrix (FR-016, FR-017); a conflict with an Accepted ADR is resolved by a superseding ADR, never by editing an entry (FR-015); link the constitution and `docs/adr/README.md`
- [X] T003 Write "How to read an entry" in `docs/glossary.md`: reproduce the entry shape from `contracts/glossary-entry-format.md` (Context · Role · Persistence · Sync · States · Referenced by · Notes) plus its rules — exactly one context from the nine, `Sync = yes` requires a scope and the four sync fields, only two scopes exist (wallet, user), `single-state` for one-state entities, Notes cite the governing ADR

**Checkpoint**: Format fixed — US1 can now write entries.

---

## Phase 3: User Story 1 - Builder looks up an entity before writing anything (Priority: P1) 🎯 MVP

**Goal**: All 22 entries exist, each with owner, role, persistence, sync scope, lifecycle and inbound references, so a builder can start a feature without inventing a name or a scope.

**Independent Test**: Hand a builder the brief *"budgets per category per period"* and only the glossary (quickstart §4). They name every entity, its owner and its sync status, with zero follow-up questions.

- [X] T004 [US1] Write the summary index table in `docs/glossary.md` → "The entity index": one row per entity from `data-model.md` Part B, columns `Entity | Arabic | Context | Role | Persistence | Sync`. 22 rows, grouped by context in constitution order
- [X] T005 [US1] Write the **Identity** entries in `docs/glossary.md` — User (root, persisted, **not** syncable: auth data; states active → pending_deletion → purged after 30-day grace), Profile (child of User 1:1, syncable **user** scope, holds display name, locale and the IANA `timezone` required by P-12), Device Session (child of User, syncable **user** scope so a revocation reaches other devices; states active → revoked, active → expired; Notes: the token itself is never an entity and never leaves the auth layer, per research R-8)
- [X] T006 [US1] Write the **Wallets & Ledger** entries in `docs/glossary.md` for Wallet (محفظة; root **and** sync scope; `balance_minor` is a cache, truth is `opening_balance_minor` + Σ postings per ADR-0005; `type` is a fixed enum `cash|checking|savings|credit_card|loan|investment` with liabilities stored negative; states active → archived, either → tombstoned) and Wallet Member (child of Wallet, syncable wallet scope, roles owner/editor/viewer, **written by Sharing through `WalletService`** per research R-3; Notes must reference the Wallets spec for the permission matrix and MUST NOT restate it, per FR-017)
- [X] T007 [US1] Write the **Category** and **Category Reference** entries in `docs/glossary.md`: Category (root, syncable **user** scope per FR-018, states active → archived, a category any posting references is never hard-deleted per research R-9, `parent_id` reserved but nesting out of scope); Category Reference (projection of Category, **derived and server-produced**, syncable **wallet scope, read-only**, never accepted from a client, republished when the category changes). The Category entry MUST answer FR-019/SC-008 — what a member sees when another member categorises a transaction in a shared wallet — and link to Category Reference
- [X] T008 [US1] Write the **Transaction**, **Posting**, **Recurring Rule** and **Occurrence** entries in `docs/glossary.md`: Transaction (root, syncable wallet scope, **exactly one category in MVP** per FR-020 while the ledger permits several legs; `kind` derived cache income/expense/transfer; transfer excluded from spending and income aggregations); Posting (child of Transaction, persisted, **Sync = no, never exposed**, references wallet XOR category per ADR-0012, postings of one transaction sum to zero per currency); Recurring Rule (root, syncable wallet scope, states active → paused → active, active|paused → ended, presets جمعية and قسط); Occurrence (child of Recurring Rule, **materialised** per research R-4, syncable wallet scope, states scheduled → confirmed → Transaction, scheduled → skipped, scheduled → due → missed)
- [X] T009 [US1] Write the **Plans & Billing** entries in `docs/glossary.md` — Plan (root, reference data, not syncable, fetched by read endpoint), Plan Feature (**value inside Plan's feature map**, not a table), Subscription (root, not syncable, provider `google_play|instapay`, states active → grace → expired → active, active|grace → revoked; carries the **billing period** fields per research R-5), Entitlement (**derived, never stored**, reachable only through `EntitlementService`, never compares a plan code per P-09, a shared wallet is governed by its owner's entitlements), Payment Claim (root, persisted, **web-only surface** per P-10, states submitted → under_review → approved|rejected, submitted → expired), Redeem Code (root, states issued → redeemed|revoked|expired)
- [X] T010 [US1] Write the **Budgeting**, **Sharing**, **Sync** and **Reporting** entries in `docs/glossary.md` — Budget (root, syncable **user** scope, **personal not household**: it limits its owner's own spending across their wallets, per FR-018; states active → archived), Budget Period (**derived** calendar window, month in MVP, not persisted; the bare word "Period" is never an entity name per research R-5), Invitation (Sharing-owned root, syncable wallet scope, states pending → accepted → Wallet Member, pending → declined|revoked|expired), Sync Cursor (**client-held protocol value, not a server row** per research R-6; the server side is `change_seq` on the scope row), Report (Reporting-owned, **derived** query, not syncable)
- [X] T011 [US1] Add the cross-module access table to `docs/glossary.md` from `data-model.md` Part B → "Cross-module access": each reader, what it reads, and the application service it goes through (FR-004); state that Admin writes only through owning modules' application services and that no module reads another's models (P-02)

**Checkpoint**: US1 done — the index is usable for planning even before the naming tables exist.

---

## Phase 4: User Story 2 - Reviewer rejects vocabulary drift with a citation (Priority: P1)

**Goal**: A reviewer can map any invented name to the canonical term and cite a table.

**Independent Test**: The six seeded violations in quickstart §3 all map to the canonical term using only the glossary.

- [X] T012 [US2] Write "Banned synonyms" in `docs/glossary.md` — the full table from `contracts/naming-rules.md` §1 verbatim (16 rows), including the external-boundary exceptions for Google Play "purchase"/"product", InstaPay "transfer" and the auth layer's "token" (FR-008), plus the note that "transfer" remains correct for a Transaction moving money between two wallets
- [X] T013 [US2] Write "Field conventions" in `docs/glossary.md` — the table from `contracts/naming-rules.md` §3 verbatim (9 rows: `id`, `<name>_minor` + `currency`, `balance_minor`/`opening_balance_minor`, calendar `date`, `<name>_at`, `version`, `change_seq`, `deleted_at`, scope key), each citing its ADR, plus the forbidden-variants line (`amount_cents`, `total_minor`, `cached_balance`, `space_id`, `updated_time`, `is_deleted`, `rev`, `seq`) per FR-009

**Checkpoint**: US2 done — quickstart §3 can run.

---

## Phase 5: User Story 3 - Client and translator share one vocabulary (Priority: P2)

**Goal**: The Android team and the translator can resolve any term, and know exactly which entities sync and in which scope.

**Independent Test**: Each of the four Arabic terms maps to exactly one concept, labelled entity / type value / preset (SC-007), and the syncable list matches the two defined scopes (SC-006).

- [X] T014 [US3] Write "Arabic terms" in `docs/glossary.md` — the table from `contracts/naming-rules.md` §2: محفظة = Wallet (**entity**), حصالة = savings wallet (**type value**, `wallets.type = savings`), جمعية = rotating savings group (**preset** of Recurring Rule), قسط = installment (**preset** of Recurring Rule); each labelled by kind per FR-010, plus the Western-numerals rule (0–9 in API output regardless of locale) per FR-011
- [X] T015 [US3] Add the sync summary to `docs/glossary.md` under the entity index: the **11 syncable** entities split **4 user-scoped** (Profile, Device Session, Category, Budget) and **7 wallet-scoped** (Wallet, Wallet Member, Category Reference, Transaction, Recurring Rule, Occurrence, Invitation), the 7 persisted-but-not-synced (User, Plan, Plan Feature, Subscription, Payment Claim, Redeem Code, Posting) with how the client obtains each, and the 4 never persisted (Entitlement, Budget Period, Report, Sync Cursor). State that no third scope exists (SC-006, FR-005)

**Checkpoint**: US3 done — the client team can build against the vocabulary.

---

## Phase 6: User Story 4 - Prior art is reconciled once, not re-argued (Priority: P3)

**Goal**: Anyone holding the old `DATA_MODEL.md` can see where each of its entities went, and cannot mistake it for current.

**Independent Test**: All eight prior-art MVP entities appear, each mapped or marked dropped with a reason (SC-003).

- [X] T016 [US4] Write "Prior-art mapping" in `docs/glossary.md` from `data-model.md` Part D / `research.md` R-11: the eight entities (User → User + Profile + Device Session; **Space dropped**; **SpaceMember dropped** → Wallet Member with owner/editor/viewer; Wallet with `cached_balance` → `balance_minor`; Category now user-scoped + Category Reference; Transaction one category in MVP; Posting wallet XOR category; Budget user-scoped and personal), each with its reason (FR-012)
- [X] T017 [US4] Write "Deferred, not built" in `docs/glossary.md` listing BalanceAssertion, ImportBatch, FX/exchange-rate entities, savings goals, envelope allocations, BudgetPeriod rollover rows, investment valuation snapshots and AI entry-method fields, with the note that specifying any of them requires a constitution amendment first (FR-014, P-17)
- [X] T018 [P] [US4] Add a pointer line to `docs/legacy/DATA_MODEL.md` directing readers to `docs/glossary.md` as the authoritative index, keeping the existing "prior art — not in force" header intact

**Checkpoint**: US4 done — the old document can no longer be mistaken for current.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [X] T019 Run every structural check in `quickstart.md` §1 against `docs/glossary.md` and fix what fails: 22 `###` entries, each carrying all six required fields, only the four legal Sync values, every Context one of the nine, all eight document sections present
- [X] T020 Run the `quickstart.md` §2 content checks and fix what fails: sync split 4 user / 7 wallet, Posting `Sync = no`, Entitlement derived, Sync Cursor client-held, Category answers FR-019, no permission matrix, no schema or endpoint leak, no codename as an identifier, 4 Arabic rows, 8 prior-art rows
- [X] T021 [P] Run the `quickstart.md` §5 consistency greps and confirm each matches its ADR: wallet XOR category (ADR-0012), `balance_minor` not `cached_balance` (ADR-0005), 90-day tombstone retention (ADR-0008), shared wallet governed by the owner's entitlements (ADR-0007)
- [X] T022 [P] Run the quickstart §3 reviewer exercise (six seeded violations) and the §4 builder exercise (the budgets brief) with fresh eyes; expand any entry that failed to answer
- [X] T023 [P] Update the A3 row in `specs/README.md` to done, linking `docs/glossary.md`, and note that A2 (`003`) inherits: the scope key is not always `wallet_id`, Category Reference is a read-only feed resource with no write endpoint, and Transaction carries one category in MVP
- [X] T024 Run `/speckit-analyze` and resolve any CRITICAL or HIGH inconsistency between `spec.md`, `plan.md`, `tasks.md`, the constitution and the ADRs (SC-005)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none
- **Foundational (Phase 2)**: needs T001; blocks every user story
- **US1 (Phase 3)**: needs Phase 2. Produces the entries everything else annotates
- **US2 (Phase 4)**: needs Phase 2 only — the naming tables are independent of the entries, though they read better after them
- **US3 (Phase 5)**: T014 needs Phase 2 only; **T015 needs US1** (it summarises the entries' sync scopes)
- **US4 (Phase 6)**: T016–T017 need Phase 2; T018 is independent of everything
- **Polish (Phase 7)**: needs all desired stories

### Within Each Story

Every task from T002 to T017 edits the same file, `docs/glossary.md`, so they run strictly in sequence. Ordering within US1 follows the document: summary table → Identity → Wallets & Ledger → Category → ledger entities → Billing → remaining contexts → cross-module table.

### Parallel Opportunities

Thin by design — one deliverable file:

- T018 (legacy pointer) with any glossary task
- T021, T022, T023 with each other in the polish phase
- If two people work at once: one writes the entries (US1), the other prepares the three naming tables (US2/T014) as a patch to apply after, since they cannot edit the file simultaneously

---

## Parallel Example: Polish phase

```bash
# Different files, no shared state:
Task: "Run quickstart §5 consistency greps against the ADRs"
Task: "Run the reviewer and builder exercises with fresh eyes"
Task: "Update the A3 row in specs/README.md"
```

---

## Implementation Strategy

### MVP First (US1)

1. Phase 1 Setup → Phase 2 Foundational
2. Phase 3 (US1): the 22 entries and the cross-module table
3. **STOP and VALIDATE**: run the quickstart §4 builder exercise
4. At this point the glossary already unblocks A2 and the first feature specs, even without the naming tables

### Incremental Delivery

1. Setup + Foundational → structure and entry format fixed
2. US1 → the index exists (MVP)
3. US2 → reviewers can cite; quickstart §3 passes
4. US3 → client and translator served; sync scopes summarised
5. US4 → prior art reconciled and closed
6. Polish → quickstart clean, `/speckit-analyze` clean, backlog updated

---

## Notes

- Documents only. No `app/`, `database/`, `config/` or `tests/` files are touched, so Pint, Larastan and Pest do not run for this feature (constitution P-16 is N-A, as recorded in plan.md).
- The Category Reference entity did not exist before this feature; it comes from research R-2 as the answer to FR-019.
- Any decision here that would contradict an Accepted ADR must become a superseding ADR instead — do not edit an existing one.
- Commit after each phase checkpoint.
