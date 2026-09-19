# Implementation Plan: Domain Glossary & Model Map

**Branch**: `002-domain-glossary-model-map` | **Date**: 2026-09-20 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/002-domain-glossary-model-map/spec.md`

## Summary

Write one reference document, `docs/glossary.md`, that is the project's authoritative entity index: all 21 named entities with their owning context, aggregate role, persistence, sync scope, lifecycle states and inbound references; the Arabic terms the UI shows; a banned-synonym table; the fixed field conventions; and a mapping from the eight prior-art entities in `docs/legacy/DATA_MODEL.md`.

The design work happened in [research.md](./research.md): every entity was assigned an owner and a sync scope, the "Period" collision was split into budget period and billing period, `Sync Cursor` turned out not to be a server-side entity at all, and the open FR-019 question — how a member renders a transaction categorised by their partner when categories ride the user feed — is resolved by a read-only **Category Reference** projection on the wallet feed.

Documentation only. No application code, no migrations, no dependencies.

## Technical Context

**Language/Version**: N/A — Markdown. (The project it describes is PHP 8.4 / Laravel 13.)

**Primary Dependencies**: none added.

**Storage**: N/A for the document. The entities it indexes will live in PostgreSQL 16 (ADR-0001).

**Testing**: no automated tests. Validation is the reviewer and newcomer exercises in [quickstart.md](./quickstart.md), matching how spec 001 was verified.

**Target Platform**: repository documentation, read by backend, Android and landing-page contributors plus planning agents.

**Project Type**: reference document for a modular-monolith web service.

**Performance Goals**: N/A.

**Constraints**: must not contradict constitution v1.1.0 or any Accepted ADR (FR-015); must not specify schemas, endpoints or payloads (FR-016); must not restate the wallet permission matrix (FR-017).

**Scale/Scope**: 21 entities + 1 new projection entity, 4 Arabic terms, ~12 banned synonyms, 6 field conventions, 8 prior-art mappings.

## Constitution Check

*Constitution v1.1.0. GATE: before Phase 0 research, re-checked after Phase 1 design.*

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | This feature *is* P-01's enforcement surface: canonical names plus a banned-synonym table (FR-007) |
| P-02 Module boundaries | PASS | Every entity gets exactly one owning module; cross-module access recorded as an application-service consumer (FR-004, research R-3) |
| P-03 Money | PASS | Field conventions fix money as integer minor units + ISO 4217 (FR-009); no other representation appears |
| P-04 Ledger integrity | PASS | Posting is indexed as a non-syncable child with the wallet-XOR-category rule from ADR-0012 |
| P-05 Derived balances | PASS | Wallet entry names `balance_minor` as a cache and the derived balance as truth |
| P-06 Identifiers | PASS | Field conventions fix UUIDv7 ids, client-generated for syncable entities |
| P-07 Sync and concurrency | PASS | Every syncable entity carries `version`, `change_seq`, `updated_at`, `deleted_at` (FR-005) |
| P-08 Authorization | PASS | Wallet Member is indexed with its three roles; the matrix itself is deferred to the Wallets spec (FR-017) |
| P-09 Entitlements | PASS | Entitlement is indexed as derived and reachable only through the entitlement service; Plan Feature is data |
| P-10 Billing channels | PASS | Payment Claim is marked web-only, Redeem Code and restore in-app |
| P-11 Events | N-A | The glossary indexes entities, not events. Domain events stay with their owning module's spec |
| P-12 Time | PASS | Field conventions fix UTC instants and `YYYY-MM-DD` calendar dates; Profile carries the IANA timezone |
| P-13 Naming | PASS | No codename in any entity, field or module name |
| P-14 Localisation | PASS | Arabic terms recorded beside English (FR-010); Western numerals restated (FR-011) |
| P-15 Observability | N-A | No runtime behaviour introduced |
| P-16 Quality gates | N-A | No PHP files touched, so Pint/Larastan/Pest do not apply. Validation is the quickstart exercises |
| P-17 Scope guard | PASS | Deferred entities (statement import, FX, savings goals) are listed in a separate "not built" section (FR-014) and specced nowhere |

**Post-design re-check**: unchanged after Phase 1. The one new concept introduced during design — the **Category Reference** projection — was checked against P-02 (owned by Wallets & Ledger, fed by its own module), P-07 (rides the wallet feed with the standard sync fields) and P-08 (read-only; never editable by another member). No violations, so Complexity Tracking stays empty.

## Project Structure

### Documentation (this feature)

```text
specs/002-domain-glossary-model-map/
├── plan.md              # This file
├── research.md          # Phase 0: ownership, scope and lifecycle decisions (R-1…R-13)
├── data-model.md        # Phase 1: the 22-row entity index, ready to transcribe
├── quickstart.md        # Phase 1: validation exercises
├── contracts/
│   ├── glossary-entry-format.md   # required shape of one entry
│   └── naming-rules.md            # banned synonyms + fixed field conventions
├── checklists/requirements.md
└── tasks.md             # Phase 2 (/speckit-tasks — not created here)
```

### Deliverable (repository root)

```text
docs/glossary.md         # the authoritative entity index
docs/legacy/DATA_MODEL.md  # gains a pointer line to the glossary
specs/README.md          # A3 row → done
```

**Structure Decision**: one file at `docs/glossary.md`, beside `docs/adr/`, because its readers include the Android and landing-page teams who have no reason to look inside `specs/`. It is a living reference, unlike a spec folder which records one moment. `docs/` already exists (ADRs, legacy), so no new base folder.

## Phase 0 output

[research.md](./research.md) — 13 decisions, each with rationale and rejected alternatives. The consequential ones:

- **R-2** FR-019: a **Category Reference** read-only projection on the wallet feed.
- **R-3** ownership of the entities two modules both touch (Wallet Member, Category, Invitation).
- **R-4** Occurrence is materialised, not computed on read.
- **R-5** "Period" is split into **Budget Period** (Budgeting, derived) and **Billing Period** (Billing, fields on Subscription); neither is called just "Period".
- **R-6** **Sync Cursor is not a server-side entity**: the server keeps `change_seq` on the scope row, the cursor is a value the client holds. It stays in the glossary as a named concept marked "not persisted server-side".

## Phase 1 output

- [data-model.md](./data-model.md) — the full index table, one row per entity, in the exact column order the glossary will use, plus lifecycle state machines and the prior-art mapping.
- [contracts/glossary-entry-format.md](./contracts/glossary-entry-format.md) — what every entry must contain, so entries cannot be half-written.
- [contracts/naming-rules.md](./contracts/naming-rules.md) — the banned-synonym table and the six field conventions.
- [quickstart.md](./quickstart.md) — structural checks plus the six-violation reviewer exercise and the one-brief builder exercise from SC-002/SC-004.

## Downstream effects

| Spec | What it inherits |
|---|---|
| A2 API conventions (`003`) | The scope key is not always `wallet_id`; Category Reference is a read-only resource; one category per transaction |
| Wallets & Ledger | Category archive-only lifecycle, `wallets.type` enum, Category Reference projection to maintain |
| Budgeting | Budgets are personal, not household; Budget Period is derived |
| Sync | A third resource on the wallet feed (Category Reference) that is server-produced and never accepted from a client |

## Complexity Tracking

No Constitution Check violations. Table intentionally empty.
