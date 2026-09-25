---

description: "Task list for Engineering Conventions (005)"
---

# Tasks: Engineering Conventions

**Input**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/](./contracts/)

**Tests**: this feature's deliverables are largely tests — the gates. Each gate is proven by seeding a violation (quickstart §2). Everything runs in the container: `dev exec -- <cmd>`.

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup and verify-first

- [X] T001 Verify in the container how Pest `arch()` behaves when a target namespace under `App\Modules` has no classes; record the result in `specs/005-engineering-conventions/research.md` R-2 and pick the guard design accordingly
- [X] T002 Install `hotmeteor/spectator` as a dev dependency in `composer.json`, publish its config to `config/spectator.php`, and point its spec source at `docs/openapi/`
- [X] T003 Verify Spectator loads `docs/openapi/openapi.yaml` — OpenAPI 3.1, multi-file `$ref`s, `type: [string, 'null']`, `examples:` arrays — and how it matches paths under a `servers[].url` ending `/api/v1`; apply the R-6 fallback if needed and record the outcome in `specs/005-engineering-conventions/research.md` R-6

## Phase 2: Foundational

- [X] T004 Restructure `phpunit.xml` into four suites — Unit, Feature, Contract, Arch — and bind them in `tests/Pest.php`: Feature and Contract extend `Tests\TestCase` with `RefreshDatabase`; Unit and Arch do not boot the app
- [X] T005 Add composer scripts in `composer.json`: `test:lint`, `test:types`, `test:arch`, `test:suite`, `test:api`, and make `test` run lint → types → arch → suite
- [X] T006 Raise `phpstan.neon` to `level: 8`, add `tests/` to its paths, and fix anything the raise surfaces in `app/` and `tests/`
- [X] T007 Amend `.specify/memory/constitution.md` in the same change as T006: P-16 rule 1 and Quality Gates item 1 say level 8; version 1.1.0 → 1.2.0; new Sync Impact Report entry; footer date 2026-09-25

**Checkpoint**: `dev exec -- composer test` passes with four suites at level 8.

---

## Phase 3: User Story 2 — The pipeline refuses what the constitution forbids (P1)

**Goal**: each enforced rule fails a run and names itself. **Independent test**: quickstart §2, 6 of 6.

- [X] T008 [US2] Write `tests/Arch/ModuleBoundariesTest.php`: Domain never uses `Illuminate\Database`; no module uses another module's `Infrastructure` or `Http`; `Shared` uses no module and no `Illuminate\Database`; Domain does not use its own Application/Infrastructure/Http; Application does not use Http; Infrastructure does not use Http or Application; plus the guard from T001 that no directory exists under `app/Modules/` outside the nine contexts and `Shared`. Every failure message names P-02
- [X] T009 [P] [US2] Write `tests/Arch/MigrationsTest.php`: scan `database/migrations/` and `app/Modules/*/Infrastructure/Database/Migrations/` for `->decimal(`, `->unsignedDecimal(`, `->float(`, `->double(`, `->real(`, and raw `numeric`/`decimal`/`real`/`double precision` in SQL strings; fail naming P-03 and file:line
- [X] T010 [P] [US2] Write `tests/Arch/EntitlementsTest.php`: nothing outside `App\Modules\Billing\Application\Entitlements` and `App\Modules\Billing\Domain` uses `App\Modules\Billing\Domain\PlanCode`; failure names P-09
- [X] T011 [US2] Write `tests/Contract/OpenApi/SkeletonTest.php`: YAML parses; every `$ref` resolves (comment lines skipped); catalogue enums agree with `x-error-catalog` and entries carry all six fields; every error code named in a path file exists in the catalogue; every problem response in `paths/*.yaml` is a `$ref` into `components/responses.yaml`; `openapi.yaml` defines nothing inline; no banned field name; Spectator loads the document
- [X] T012 [US2] Run the six seeded violations from `specs/005-engineering-conventions/quickstart.md` §2 and confirm each fails naming its rule, then remove every seed

## Phase 4: User Story 1 — A feature PR is complete by construction (P1)

**Goal**: one list says what done means. **Independent test**: every item has exactly one checker.

- [X] T013 [US1] Write `CONTRIBUTING.md` at the repository root: the sixteen-item definition of done from `contracts/definition-of-done.md` with the checker column, the module layout, the four suites and the commit shape
- [X] T014 [P] [US1] Create `.ai/rules/index.md` mapping `app/**`, `tests/**`, `database/**`, `docs/openapi/**`, `specs/**` to `.ai/rules/engineering.md`, and write that rule file pointing agents at `CONTRIBUTING.md`, the layout and the suites
- [X] T015 [US1] Create `app/Providers/ModuleServiceProvider.php` — for each existing `app/Modules/<Context>/`, load `Infrastructure/Database/Migrations` and register the factory-name resolver — register it in `bootstrap/providers.php`, and add the seeder call order to `database/seeders/DatabaseSeeder.php`
- [X] T016 [US1] Add a Contract-suite helper in `tests/Pest.php` or `tests/Contract/` so an endpoint test asserts conformance in one line through Spectator

## Phase 5: User Story 3 — A new contributor is productive in one sitting (P2)

- [X] T017 [US3] Write `docs/engineering-conventions.md`: Getting started with the `dev` CLI (up, project env, migrate, run suites, clone-db, status, logs, infra status, Mailpit), the layout and layer rules, the four suites, the gates, the environment profiles table from `contracts/environment-profiles.md`, migrations append-only from `v1.0.0`, and the two version numbers

## Phase 6: User Story 4 — Specs in the project's shape (P2)

- [X] T018 [US4] Add the `## Project Sections` block to `.specify/templates/spec-template.md` between Requirements and Success Criteria: purpose, glossary terms, data model, invariants, API, authorization & entitlements, events, acceptance scenarios, open questions — each a heading with a one-line prompt, including the glossary-first rule and invariant-names-its-test rule

## Phase 7: User Story 5 — History and versions are legible (P3)

- [X] T019 [US5] Rewrite `.github/workflows/tests.yml` into named steps — Formatting, Static analysis, Architecture, Database engine (writes `SELECT version()` to the job summary), Test suite, Commit messages (pull requests only, pattern over `base..head`, merges skipped) — keeping pinned action SHAs and the PostgreSQL 16 service
- [X] T020 [US5] Document the commit shape and the API-versus-release version policy in `docs/engineering-conventions.md`

## Phase 8: Close-out

- [X] T021 [P] Update `specs/README.md`: A5 → done; follow-ups F-1 and F-5 closed
- [X] T022 Run `dev exec -- composer test` and the full `specs/005-engineering-conventions/quickstart.md`; all pass
- [X] T023 Run `vendor/bin/pint --dirty --format agent` via the container and confirm the whole gate still passes

## Dependencies

- T001–T003 first: they decide how T008 and T011 are written.
- T006 and T007 land together.
- Phase 3 depends on T004–T005. T009, T010 parallel with T008 (different files).
- T019 last among the build tasks, so the workflow calls scripts that exist.

## Strategy

MVP is Phases 1–3: the gates exist and bite. Everything after documents and wires what the gates enforce.
