# Phase 1 Data Model: Engineering Conventions

**Feature**: `005-engineering-conventions` | **Spec**: [spec.md](./spec.md) | **Research**: [research.md](./research.md)

No database entity is introduced. These are the shapes the conventions fix: where things live, what a gate checks, and what "done" contains.

## 1. Module layout

```text
app/Modules/<Context>/
├── Domain/                 # value objects, entities, domain services, repository interfaces, domain events
├── Application/            # application services, query services, DTOs
├── Infrastructure/
│   ├── Models/             # Eloquent models — private to the module
│   ├── Repositories/       # repository implementations
│   └── Database/
│       ├── Migrations/
│       ├── Factories/
│       └── Seeders/<Context>Seeder.php
└── Http/
    ├── Controllers/
    ├── Requests/
    ├── Resources/
    └── Policies/
```

Contexts: Identity, Wallets, Budgeting, Sharing, Billing, Sync, Reporting, Notifications, Admin. Plus `app/Modules/Shared/Domain` — persistence-free.

| Class kind | Directory |
|---|---|
| Value object, entity, domain event, repository interface | `Domain/` |
| Application service, query service, DTO | `Application/` |
| Eloquent model | `Infrastructure/Models/` |
| Repository implementation | `Infrastructure/Repositories/` |
| Migration, factory, seeder | `Infrastructure/Database/…` |
| Queued job, event listener | `Application/` (they orchestrate) |
| Controller, form request, API resource | `Http/…` |
| Policy | `Http/Policies/` |

## 2. Layer rules

| Layer | May use | May not use |
|---|---|---|
| Domain | Shared domain, its own Domain | `Illuminate\Database`, its own Application, Infrastructure, Http, any other module |
| Application | own Domain, Shared, other modules' **Application** | own Http, other modules' Infrastructure or Http |
| Infrastructure | own Domain, Shared, `Illuminate\Database` | own Http, own Application, other modules' Infrastructure or Http |
| Http | own Application, own Domain, Shared | own Infrastructure models directly, other modules' Infrastructure or Http |
| Shared | PHP, `Illuminate\Support` value helpers | any module, `Illuminate\Database` |

## 3. Test suites

| Suite | Directory | App booted | Database | Base |
|---|---|---|---|---|
| Unit | `tests/Unit/` | no | none | `PHPUnit\Framework\TestCase` |
| Feature | `tests/Feature/` | yes | PostgreSQL, refreshed | `Tests\TestCase` + `RefreshDatabase` |
| Contract | `tests/Contract/` | yes | PostgreSQL, refreshed | `Tests\TestCase` + `RefreshDatabase` + Spectator |
| Arch | `tests/Arch/` | no | none | Pest arch / file scans |

## 4. Gates

| Gate | Stage | Fails when | Names |
|---|---|---|---|
| Formatting | 1 | Pint finds a change | — |
| Static analysis | 2 | PHPStan level 8 error | the file and rule |
| Module boundaries | 3 | a forbidden `use` | P-02 and the import |
| Migrations | 3 | decimal / float / double / real column | P-03 and file:line |
| Entitlements | 3 | `PlanCode` used outside Billing's entitlement resolution | P-09 |
| Test suite | 4 | any Unit, Feature or Contract failure | the test |
| API definition | 5 | parse, reference, catalogue, inline-shape, loader error | the file and rule |
| Database engine | 4 (recorded) | never fails; writes engine and version to the summary | FR-012 |
| Commit messages | PR only | a first line off the pattern | the commit and the rule |

## 5. Definition of Done

| Item | Applies when | Checked by |
|---|---|---|
| Code in the module layout | always | pipeline (arch) |
| Formatting, static analysis pass | always | pipeline |
| Migration for each persisted change | schema changes | reviewer |
| No float/decimal money column | migrations | pipeline |
| Path file `docs/openapi/paths/<feature>.yaml` | new or changed endpoint | reviewer |
| API definition valid, codes in catalogue | always | pipeline |
| Policy for each resource | new resource | reviewer |
| Unit tests for domain rules | new domain rule | reviewer |
| Feature tests for application services | new service | reviewer |
| Contract tests for each endpoint, incl. error codes | new endpoint | reviewer writes, pipeline validates |
| Test that fails when each new invariant breaks | new invariant | reviewer |
| Seeder entry | reference data | reviewer, marked N/A otherwise |
| Glossary entry | new term | reviewer |
| Security review checklist | endpoint or stored attribute | reviewer |
| Conventional commit messages | always | pipeline |
| Decision record | a decision contradicting or extending an Accepted one | reviewer |

## 6. Profiles

See [contracts/environment-profiles.md](./contracts/environment-profiles.md).

## 7. Commit types

`feat`, `fix`, `docs`, `chore`, `refactor`, `test`, `build`, `ci`. Optional scope in parentheses, lowercase kebab. `!` before the colon marks a breaking change.
