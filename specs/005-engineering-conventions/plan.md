# Implementation Plan: Engineering Conventions

**Branch**: `005-engineering-conventions` | **Date**: 2026-09-25 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/005-engineering-conventions/spec.md`

## Summary

Make the project's conventions enforceable rather than remembered. Four test suites (Unit, Feature, Contract, Arch); architecture gates for module boundaries (P-02), money columns (P-03) and plan-code use (P-09) built on Pest's already-installed arch plugin; the API skeleton checks from spec 003 promoted into permanent tests; contract validation through **Spectator**, the one owner-approved dev dependency; a pipeline split into named stages that records the database engine it tested against and checks commit messages; static analysis raised to level 8 with the constitution amended in the same commit; module wiring by convention in one provider; and the documents a contributor needs — `docs/engineering-conventions.md`, `CONTRIBUTING.md` with the definition of done, `.ai/rules/` for agents, and the project block in the spec template.

Pays follow-up **F-1** (architecture tests, spec 001) and **F-5** (skeleton linting, spec 003).

## Technical Context

**Language/Version**: PHP 8.5 (container and pipeline; `composer.json` requires `^8.5`), Laravel 13, Pest 5.2 with `pest-plugin-arch`, Larastan 3.12, Pint 1.32.

**Primary Dependencies**: one addition, `hotmeteor/spectator` (dev), approved by the owner. It pulls `cebe/php-openapi` and `opis/json-schema`.

**Storage**: PostgreSQL 16 for Feature and Contract suites; Unit and Arch touch no database.

**Testing**: Pest. The new gates are themselves tests; the quickstart seeds one violation per gate to prove each bites.

**Target Platform**: contributors' machines via the `dev` CLI; GitHub Actions.

**Project Type**: repository tooling and conventions for a modular-monolith web service.

**Constraints**: every gate must pass on the current empty module tree; no dependency beyond the one approved; the constitution amendment lands with the level change.

**Scale/Scope**: 4 suites, 5 gate files, 1 provider, 1 workflow rewrite, 1 constitution amendment, 4 documents, 1 template edit.

## Constitution Check

*Constitution v1.1.0 → amended to v1.2.0 by this feature. GATE: before Phase 0, re-checked after Phase 1.*

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | Module and context names in the layout are the constitution's own |
| P-02 Module boundaries | PASS | This feature enforces it: `tests/Arch/ModuleBoundariesTest.php`. The module provider lives in `app/Providers`, not `Shared`, because it is persistence wiring (R-8) |
| P-03 Money | PASS | Enforced by `tests/Arch/MigrationsTest.php` (R-3) |
| P-04 Ledger integrity | N-A | No ledger code |
| P-05 Derived balances | N-A | No balance code |
| P-06 Identifiers | N-A | No persisted entity |
| P-07 Sync | N-A | No syncable entity |
| P-08 Authorization | PASS | Definition of done requires a policy per resource |
| P-09 Entitlements | PASS | Enforced by `tests/Arch/EntitlementsTest.php` over a named `PlanCode` symbol (R-4) |
| P-10 Billing channels | N-A | No billing surface |
| P-11 Events | N-A | No events |
| P-12 Time | PASS | "Instants stored in UTC" is on the may-never-differ list of every profile |
| P-13 Naming | PASS | The codename appears only in config values (`APP_URL`, database names) and the dev host, which are configuration |
| P-14 Localisation | N-A | No user-facing text |
| P-15 Observability | PASS | Structured JSON logs outside local is on the may-never-differ list |
| P-16 Quality gates | PASS | Tightened: level 7 → 8, with the principle amended in the same change (R-5). The suite still runs on PostgreSQL, now with the engine recorded per run |
| P-17 Scope guard | PASS | No MVP-guard capability; the image-build stage is deferred with the deployment target |

**Post-design re-check**: unchanged. The one governance action — amending P-16 — is the owner's decision, recorded in the spec's clarifications, and follows the constitution's own amendment rules (MINOR bump, Sync Impact Report, footer date). No dependency beyond the approved one. Complexity Tracking stays empty.

## Project Structure

### Documentation (this feature)

```text
specs/005-engineering-conventions/
├── plan.md, research.md (R-1…R-16), data-model.md, quickstart.md
├── contracts/definition-of-done.md, environment-profiles.md, pipeline-stages.md
├── checklists/requirements.md
└── tasks.md
```

### Source (repository root)

```text
tests/Arch/ModuleBoundariesTest.php      # P-02
tests/Arch/MigrationsTest.php            # P-03
tests/Arch/EntitlementsTest.php          # P-09
tests/Contract/OpenApi/SkeletonTest.php  # skeleton checks + Spectator load
tests/Contract/, tests/Feature/, tests/Unit/, tests/Pest.php, phpunit.xml
app/Providers/ModuleServiceProvider.php, bootstrap/providers.php
database/seeders/DatabaseSeeder.php
config/spectator.php
phpstan.neon, composer.json, .github/workflows/tests.yml
.specify/memory/constitution.md          # P-16 amendment, v1.2.0
.specify/templates/spec-template.md      # project sections block
docs/engineering-conventions.md, CONTRIBUTING.md, .ai/rules/index.md, .ai/rules/engineering.md
specs/README.md                          # A5 → done; F-1, F-5 closed
```

**Structure Decision**: gates live in `tests/` because they are tests and the runner already exists. The provider lives in `app/Providers` because it is framework wiring, not domain.

## Complexity Tracking

No violations. Table intentionally empty.
