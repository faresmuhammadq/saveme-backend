# Phase 0 Research: Engineering Conventions

**Feature**: `005-engineering-conventions` | **Date**: 2026-09-25 | **Spec**: [spec.md](./spec.md)

Inputs: constitution v1.1.0 (P-02, P-03, P-09, P-16, Quality Gates), ADR-0001, [docs/api-conventions.md](../../docs/api-conventions.md), spec 003's quickstart checks, the existing workflow, `composer.json`, `phpunit.xml`, and `dev --help` on this machine.

Facts established before deciding: the repository is greenfield (three stock files in `app/`, no modules, no domain migrations, no API routes). Pest 5.2 ships `pest-plugin-arch`, so architecture tests need no new dependency. Larastan 3.12, Pint 1.32. The shared stack runs PostgreSQL 16, Redis 7 and Mailpit.

Two behaviours are **verify-first**: they are decided here but checked by the first implementation tasks, with a stated fallback.

---

## R-1 — Four test suites, one per layer plus architecture

**Decision**:

| Suite | Directory | Boots the app | Database | Exercises |
|---|---|---|---|---|
| Unit | `tests/Unit/<Context>/` | no | none | domain rules: value objects, entities, pure services |
| Feature | `tests/Feature/<Context>/` | yes | PostgreSQL, `RefreshDatabase` | application services, persistence, events |
| Contract | `tests/Contract/<Context>/` | yes | PostgreSQL, `RefreshDatabase` | HTTP endpoints, validated against the OpenAPI definition |
| Arch | `tests/Arch/` | no | none | architecture gates: boundaries, migrations, entitlements, skeleton |

The skeleton's own checks live in `tests/Contract/OpenApi/` because they are about the contract, not about a module.

**Rationale**: the spec's three layers are the backlog's domain / application / HTTP split. Architecture gates are a fourth suite so they can run first and fast — they need no database and fail in seconds.

**Alternatives rejected**: *putting arch tests in Unit* — they would run interleaved with domain tests and a boundary violation would read as a unit failure.

---

## R-2 — Architecture tests use Pest's arch plugin, already installed

**Decision**: `tests/Arch/ModuleBoundariesTest.php` expresses P-02 as `arch()` expectations over `App\Modules\*`:

- `App\Modules\*\Domain` does not use `Illuminate\Database` or `Illuminate\Database\Eloquent`.
- For each context: `App\Modules\<A>` does not use `App\Modules\<B>\Infrastructure` or `App\Modules\<B>\Http` for every other context B.
- `App\Modules\Shared` does not use any `App\Modules\<Context>` namespace, nor `Illuminate\Database`.
- `Domain` does not use its own module's `Application`, `Infrastructure` or `Http`; `Application` does not use `Http`; `Infrastructure` does not use `Http` or `Application`.

**Verified 2026-09-25 (T001)**: an `arch()` expectation over a namespace with no classes **passes vacuously**, and the same expectation fails with `Expecting 'App\Modules\Wallets\Domain' not to use 'Illuminate\Database'` the moment one violating class exists. No fallback was needed. The guard that no directory exists under `app/Modules/` outside the nine contexts plus `Shared` is kept anyway, so the tree cannot grow a module the gates do not cover.

**Rationale**: the plugin is already in `vendor/`, so F-1 from spec 001 is paid with zero new dependencies.

---

## R-3 — The migration gate is a scan, not an arch rule

**Decision**: `tests/Arch/MigrationsTest.php` reads every file under `database/migrations/` and `app/Modules/*/Infrastructure/Database/Migrations/`, and fails on `->decimal(`, `->unsignedDecimal(`, `->float(`, `->double(`, `->real(`, and raw SQL `numeric`/`decimal`/`real`/`double precision` inside a `DB::statement`. The failure names P-03 and `file:line`.

**Rationale**: column types are method calls on a Blueprint, invisible to an import-based arch rule. A text scan is exactly as precise as needed; the three stock migrations are clean.

---

## R-4 — The entitlement gate targets a named symbol

**Decision**: the plan code is modelled, when Billing lands, as `App\Modules\Billing\Domain\PlanCode`. Only `App\Modules\Billing\Application\Entitlements` and `App\Modules\Billing\Domain` may use it; `tests/Arch/EntitlementsTest.php` asserts nothing else does. The symbol is named now so the gate exists before the first plan does.

**Rationale**: P-09 rule 3 is otherwise unenforceable — "no plan-code comparison" is only checkable if there is one type that carries a plan code.

---

## R-5 — Static analysis at level 8, with the constitution amended in the same commit

**Decision**: `phpstan.neon` goes to `level: 8`, and `tests/` joins its paths. Constitution P-16 rule 1 and Quality Gates item 1 change "level 7" to "level 8"; version 1.1.0 → **1.2.0** (MINOR: a principle materially tightened), Sync Impact Report and footer updated, dated 2026-09-25.

**Rationale**: the owner's decision.

**Revised during implementation (T006)**: `tests/` was to join the analysed paths. It does not. At any level, PHPStan infers `$this` inside a Pest test closure as `Pest\PendingCalls\TestCall` rather than the bound test case, so every HTTP assertion reads as an undefined method — five false errors on the current suite, and one per HTTP test from here on. The clean fix is a Pest-aware PHPStan extension, which is a second dependency the owner has not approved. Suppressing `method.notFound` under `tests/` would hide real errors too. Application code is analysed at level 8 and passes; test code is checked by running it.

---

## R-6 — Spectator is the one approved validator

**Decision**: `hotmeteor/spectator` as a development dependency. It pulls `cebe/php-openapi ^1.7` and `opis/json-schema ^2.3`. Tests call `Spectator::using('openapi.yaml')` and assert `assertValidRequest()` / `assertValidResponse($status)`. Its config points at `docs/openapi/`.

**Verify-first**: that it loads the skeleton — OpenAPI 3.1.0, split across files with relative `$ref`s, using `type: [string, 'null']` and `examples:` arrays — and matches paths when `servers[].url` ends in `/api/v1`. **Fallback, in order**: (1) if multi-file refs fail, keep the source split and have the loader resolve a bundled copy built in the test bootstrap; (2) if 3.1 constructs fail, rewrite them in 3.0 form (`nullable: true`, singular `example`) and record the downgrade in the API conventions.

The owner chose it over the framework-agnostic alternative for its Laravel-native assertions and request validation.

**Verified 2026-09-25 (T003)**, v3.0.4, no fallback needed. It parses the 3.1 skeleton across files, resolves relative `$ref`s through `allOf` down to `SyncableResource`, reads `type: [string, 'null']` as nullable, validates shared problem responses as `application/problem+json`, and matches paths with `path_prefix: api/v1`. It rejects a non-conforming response by throwing `ErrorException` with a readable schema diff.

Two real defects surfaced while verifying, both kept as findings:

1. **The skeleton's `Accept-Language` was typed `enum: [ar, en]`**, which rejects what clients actually send (`en-US,en;q=0.9`). Spec 003's conventions already say an unsupported language is a fallback, never a refusal, so the schema was wrong, not the rule. Fixed to a bounded string.
2. **An empty PHP array serialises to JSON `[]`**, while the envelope requires `meta` to be the object `{}`. Every single-item endpoint will hit this. It becomes a written convention: an empty `meta` is emitted as an object.

The verification probe is kept as `tests/Contract/OpenApi/TemplateConformanceTest.php`, which proves on every run that the path template every feature copies is still valid.

---

## R-7 — The skeleton checks become permanent tests

**Decision**: the five `php -r` snippets in spec 003's quickstart become `tests/Contract/OpenApi/SkeletonTest.php`, run on every push:

1. every YAML file parses;
2. every `$ref` resolves, ignoring comment lines;
3. the catalogue's enums and its `x-error-catalog` map agree, and every entry carries all six fields;
4. every problem response in `paths/*.yaml` is a `$ref` into `components/responses.yaml`;
5. no banned field name appears.

Plus two new ones: `openapi.yaml` defines no schema, parameter or response inline; Spectator loads the document without error. `paths/_template.yaml` is checked like any path file but never required to be in the index.

**Rationale**: a check that lives in a quickstart runs when someone remembers. This closes follow-up F-5.

---

## R-8 — Module wiring: one provider, discovered by convention

**Decision**: `app/Providers/ModuleServiceProvider.php`, registered in `bootstrap/providers.php`, which for each `app/Modules/<Context>/` directory:

- loads migrations from `Infrastructure/Database/Migrations`;
- registers a `Factory::guessFactoryNamesUsing` resolver mapping `App\Modules\<C>\Infrastructure\Models\X` to `App\Modules\<C>\Infrastructure\Database\Factories\XFactory`.

Seeders live at `app/Modules/<Context>/Infrastructure/Database/Seeders/<Context>Seeder.php`, called from `database/seeders/DatabaseSeeder.php` in a fixed context order.

The provider lives in `app/Providers`, not in `Shared`, because P-02 rule 5 keeps `Shared` free of persistence and this provider is nothing but persistence wiring.

**Rationale**: one file makes a new module work by existing; nine hand-registered providers would be nine chances to forget one. On an empty tree it loops over nothing.

---

## R-9 — The pipeline: one job, named steps, stop on first failure

**Decision**: `.github/workflows/tests.yml` keeps one job and its pinned action SHAs, and splits `composer ci:check` into named steps: **Formatting** → **Static analysis** → **Architecture** → **Test suite** → **API definition**. GitHub stops at the first failing step, so a failure names its stage. Two further steps:

- **Database engine** — runs `SELECT version()` against the service and writes it to the job summary, so every run records what it tested against (FR-012).
- **Commit messages** — pull requests only, checks every commit in `base..head` against the Conventional Commits pattern.

`composer test` still runs the whole gate locally, as P-16 rule 1 requires, now as the sum of `test:lint`, `test:types`, `test:arch`, `test:suite`.

**Rationale**: one job keeps the setup cost paid once; named steps give the per-stage failure the spec wants.

---

## R-10 — Commit messages

**Decision**: first line matches `^(feat|fix|docs|chore|refactor|test|build|ci)(\([a-z0-9-]+\))?!?: .+`, at most 72 characters. Merge commits are skipped. The body is free text.

**Rationale**: the fixed set in the spec's assumptions; the `!` marks a breaking change, which the API version policy uses.

---

## R-11 — Profiles by difference

**Decision**: a table in `docs/engineering-conventions.md` with four columns — local, pipeline, staging, production — and a row per setting the application reads, plus a "may never differ" list: database engine and major version, money representation, identifier format, time zone of storage (UTC), and the log format. Staging and production are rows only; no files are created for them.

---

## R-12 — Migrations append-only from `v1.0.0`

**Decision**: from the first tag matching `v1.0.0`, a merged migration is never edited, renamed or deleted; a mistake is corrected by a new migration. Before that tag, migrations may be edited or squashed. The rule is documented; enforcement becomes a pipeline check the day the tag exists, because the check needs the tag to compare against.

---

## R-13 — Two version numbers, two meanings

**Decision**:

| Number | Where | Bumped when |
|---|---|---|
| API version `v1.N` | `info.version` in `openapi.yaml` | minor: any additive change; major: only with a new base path |
| Release `vX.Y.Z` | git tag | semver over the service as a whole; a commit with `!` forces a major |

The base path `/api/v1` moves only with the API major, as the API conventions already state.

---

## R-14 — The definition of done, and where agents find it

**Decision**: `CONTRIBUTING.md` at the repository root holds the checklist, each item tagged **pipeline** or **reviewer**. Agents reach it through `.ai/rules/`, which the project's own instructions already name as the committed, shared agent-rule mechanism: `.ai/rules/index.md` maps `app/**`, `tests/**`, `database/**`, `docs/openapi/**` and `specs/**` to `.ai/rules/engineering.md`, a short rule that points at `CONTRIBUTING.md` and states the layout and the four suites.

**Rationale**: the root `CLAUDE.md` and `AGENTS.md` are gitignored here, so they cannot carry a shared rule. `.ai/rules/` is committed and is already where the project instructions tell agents to look.

---

## R-15 — The spec template gains a project block

**Decision**: `.specify/templates/spec-template.md` keeps its mandatory sections and gains, after Requirements and before Success Criteria, a `## Project Sections *(mandatory for feature specs)*` block with the nine headings in order, each with a one-line prompt. The glossary rule and the invariant-names-its-test rule are written into those prompts.

**Rationale**: the workflow's tooling keys on its own headings, so adding a block rather than renaming sections keeps it working.

---

## R-16 — Dependency footprint

**Decision**: exactly one new development dependency, `hotmeteor/spectator`, approved by the owner at specify time. Nothing else is added. `pest-plugin-arch` and `symfony/yaml` are already present.
