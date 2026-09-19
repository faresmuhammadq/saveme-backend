# Implementation Plan: Architecture Constitution & ADR Set

**Branch**: `001-architecture-constitution-adrs` | **Date**: 2026-09-15 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/001-architecture-constitution-adrs/spec.md`

## Summary

Write the project's governing documents: a ratified constitution (replacing the unfilled template at `.specify/memory/constitution.md`) and eleven Architecture Decision Records under `docs/adr/`. The constitution states verifiable MUST/MUST NOT principles, the nine bounded contexts, the modular-monolith rules, quality gates, the per-plan Constitution Check and governance. Each ADR records context, decision, at least two rejected alternatives, and consequences.

The technical positions the documents will fix were settled in [research.md](./research.md), mapped onto Laravel 13 / PHP 8.4:

- PostgreSQL, with tests also on PostgreSQL
- UUIDv7 via `HasUuids`, client-supplied for syncable entities
- integer minor-unit `Money` in a shared kernel
- a zero-sum postings ledger against wallet and nominal accounts
- a cached balance recomputed under the wallet row lock
- wallet-scoped policies with a separate admin guard
- an entitlement service over provider-agnostic subscriptions
- per-wallet and per-user change feeds with a per-scope monotonic counter and whole-write 409 on stale version
- `ShouldDispatchAfterCommit` events with idempotent queued listeners, no outbox
- JSON logs with request and correlation IDs carried through `Context`
- UTC `timestamptz` storage with calendar `date` columns

This feature produces documentation only. It changes no application code, configuration or dependencies. Enforcement (architecture tests, PostgreSQL in `phpunit.xml`/CI, an error-tracker SDK) is listed as follow-up features.

## Technical Context

**Language/Version**: PHP 8.4 (composer constraint `^8.3`; CI runs 8.4), Laravel 13.31

**Primary Dependencies**: laravel/framework 13, livewire/livewire 4 (admin panel), pestphp/pest 5, larastan 3 (level 7), laravel/pint. No new dependencies in this feature. The error tracker (ADR-010) is recorded as "Sentry-compatible, SDK pending dependency approval".

**Storage**: PostgreSQL (ADR-001). Current dev `.env` and `phpunit.xml` use SQLite and are left unchanged by this feature; migrating them is follow-up F-2.

**Testing**: Pest. No tests for a docs-only change. Validation runs through [quickstart.md](./quickstart.md): structural checks plus the seeded-violation and "why" exercises from SC-003/SC-004.

**Target Platform**: Linux server, JSON API consumed by a Kotlin Multiplatform Android app, plus a Livewire admin panel.

**Project Type**: web-service (modular monolith)

**Performance Goals**: N/A for documents. ADRs keep MVP-scale expectations (single-region, individuals/couples, low thousands of writes per wallet per year).

**Constraints**: Documents in English (a product localisation concern does not apply to engineering docs). Must not contradict the brief (project memory) or the spec's clarifications. Codename "Save Me" must not appear as an identifier in any prescribed namespace, table or package name.

**Scale/Scope**: 1 constitution (~15 principles, 9 contexts), 11 ADRs, 1 ADR index, 1 ADR template.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

**Pre-research**: `.specify/memory/constitution.md` is still the unfilled template, so there are no ratified principles to gate against. This feature creates them. As an interim gate, the plan was checked against the locked decisions in the product brief and the repository rules in `CLAUDE.md`:

| Gate (interim source) | Result | Note |
|---|---|---|
| No dependency changes without approval (CLAUDE.md) | PASS | Docs only; Sentry SDK deferred to approval. |
| No new base folders without approval (CLAUDE.md) | PASS | `docs/adr/` was requested by the user (ADR set); `app/Modules/` was named by the user. Neither is created in code here. |
| Documentation files only when requested (CLAUDE.md) | PASS | Constitution and ADRs explicitly requested. |
| Every locked brief decision represented (SC-002) | PASS | Traceability table in [research.md §R-0](./research.md#r-0-traceability-brief--constitution--adr). |
| Codename not in identifiers | PASS | No prescribed namespace/table/package uses it. |
| Clarifications resolved (Q1 PostgreSQL, Q2 whole-write 409) | PASS | Encoded in ADR-001 and ADR-008 positions. |

**Post-design re-check (after Phase 1)**: Checked against the draft principle list in [contracts/constitution-outline.md](./contracts/constitution-outline.md). Every principle has a backing ADR. No ADR position contradicts a principle. The three automatable rules (module boundaries, no floats in money, no plan-code comparison) each have a named enforcement mechanism. **PASS**, no violations, so Complexity Tracking stays empty.

## Project Structure

### Documentation (this feature)

```text
specs/001-architecture-constitution-adrs/
├── plan.md              # This file
├── research.md          # Phase 0: decisions behind each ADR + doc-format decisions
├── data-model.md        # Phase 1: document entities + conventions ADRs impose on data
├── quickstart.md        # Phase 1: validation guide
├── contracts/
│   ├── constitution-outline.md   # Required structure + draft principle list
│   ├── adr-template.md           # ADR file contract
│   ├── constitution-check.md     # Per-plan gate contract
│   └── http-conventions.md       # Cross-cutting API conventions the ADRs fix
├── checklists/requirements.md
└── tasks.md             # Phase 2 (/speckit-tasks — not created here)
```

### Deliverables (repository root)

```text
.specify/memory/constitution.md          # replaced: ratified constitution v1.0.0
.specify/templates/plan-template.md      # Constitution Check section aligned (sync impact)
docs/adr/
├── README.md                            # index: number, title, status
├── template.md                          # copy of contracts/adr-template.md
├── 0001-database-engine.md
├── 0002-identifiers-uuidv7.md
├── 0003-money-minor-units.md
├── 0004-ledger-transactions-and-postings.md
├── 0005-derived-balance-with-cache.md
├── 0006-wallet-scoped-authorization.md
├── 0007-entitlements-from-subscriptions.md
├── 0008-sync-changes-feed.md
├── 0009-domain-events-after-commit.md
├── 0010-observability.md
└── 0011-time-and-timezones.md
```

Target code layout that the constitution prescribes for later features (not created by this feature):

```text
app/Modules/
├── Shared/          # shared kernel: Money, Currency, Ids, Clock, DomainEvent base — no Eloquent models
├── Identity/        {Domain,Application,Infrastructure,Http}
├── Wallets/         # Wallets & Ledger
├── Budgeting/
├── Sharing/
├── Billing/         # Plans & Billing
├── Sync/
├── Reporting/
├── Notifications/
└── Admin/
tests/Arch/          # Pest architecture tests enforcing module boundaries (follow-up F-1)
```

**Structure Decision**: Documentation-only deliverable at `.specify/memory/` and `docs/adr/`. Module directory names use short PascalCase context names (`Wallets`, `Billing`) because `&` cannot appear in a PHP namespace. The mapping to the context names is fixed in the constitution. The shared kernel sits inside `app/Modules/Shared` so no base folder beyond `app/Modules` is introduced.

## Follow-up features (out of scope here)

| ID | Feature | Why separate |
|---|---|---|
| F-1 | Architecture tests (Pest `arch()`) for module boundaries, float ban in Money paths, plan-code comparison ban | Code + test change; enforces SC-007 |
| F-2 | Switch dev/test/CI to PostgreSQL (`.env.example`, `phpunit.xml`, `tests.yml` service) | Environment change |
| F-3 | Observability middleware + JSON log channel + error-tracker SDK | Needs dependency approval |
| F-4 | Shared kernel (`Money`, `Currency`, clock) with unit tests | First code under `app/Modules/Shared` |

## Complexity Tracking

No Constitution Check violations. Table intentionally empty.
