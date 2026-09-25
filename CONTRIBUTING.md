# Contributing

This is the definition of done for every pull request, whoever writes it — a person or a coding agent. Read it before you start, not after review.

The detail behind each rule is in [docs/engineering-conventions.md](docs/engineering-conventions.md). The rules themselves come from the [constitution](.specify/memory/constitution.md).

## Getting started

The local environment is the `dev` CLI on Docker, running PHP 8.5. Host PHP has no PostgreSQL driver, so everything runs in the container.

A worktree set up before the move to 8.5 switches with `dev project setup php:8.5 --db postgres:16 --prefix "php artisan" --force`.

```bash
dev up                       # start this worktree's environment
dev project env --yes        # fill .env from the shared stack
dev exec -- composer install
dev key:generate            # `dev <x>` runs `php artisan <x>` in the container
dev migrate
dev exec -- composer test    # the whole gate — must be green
```

The app is at <http://saveme-backend.test>, mail lands in <http://mailpit.test>.

## Definition of done

Every item has exactly one checker. **Pipeline** items fail the build on their own. **Reviewer** items are checked by a person, and a pull request that cannot tick one says why in its description.

| # | Item | Applies when | Checked by |
|---|---|---|---|
| 1 | Code sits in `app/Modules/<Context>/{Domain,Application,Infrastructure,Http}` and respects the layer rules | always | pipeline |
| 2 | Formatting (Pint) and static analysis (Larastan level 8) pass | always | pipeline |
| 3 | A migration for each persisted change, in the module's `Infrastructure/Database/Migrations` | schema changes | reviewer |
| 4 | No float, double, real or decimal column — money is integer minor units | any migration | pipeline |
| 5 | A path file `docs/openapi/paths/<feature>.yaml`, indexed from `openapi.yaml`, referencing shared components | endpoint added or changed | reviewer writes; pipeline checks it is indexed and resolves |
| 6 | Every error code used exists in `docs/openapi/error-catalog.yaml`; `info.version` bumped for an additive change | endpoint or code added | pipeline |
| 7 | A policy for each resource | new resource | reviewer |
| 8 | Unit tests for each domain rule | domain rule added | reviewer |
| 9 | Feature tests for each application service | service added | reviewer |
| 10 | Contract tests for each endpoint, covering every documented status including errors | endpoint added | reviewer writes; pipeline validates against the definition |
| 11 | A test that fails when each new invariant is broken | invariant added | reviewer |
| 12 | A seeder for reference data, or "Seeder: N/A — no reference data" in the PR description | always stated | reviewer |
| 13 | A glossary entry for any new term, added before the term is used | new term | reviewer |
| 14 | The [security review checklist](docs/security-baseline.md#appendix-security-review-checklist) and the [endpoint review checklist](docs/api-conventions.md#appendix-endpoint-review-checklist) applied | endpoint or stored attribute added | reviewer |
| 15 | No plan code compared outside Billing's entitlement resolution | always | pipeline for the `PlanCode` type; reviewer for string literals |
| 16 | Commit subjects in the conventional shape | always | pipeline |
| 17 | No new dependency without the owner's approval | `composer.json` or `package.json` changed | reviewer |
| 18 | A decision record when a decision extends or contradicts an Accepted one | as needed | reviewer |

## Commit messages

```text
<type>(<scope>)!: <subject>
```

`type` is one of `feat`, `fix`, `docs`, `chore`, `refactor`, `test`, `build`, `ci`. The scope is optional, lowercase. `!` marks a breaking change. The subject is at most 72 characters. The body is free text.

## Tests

| Suite | Directory | What goes there |
|---|---|---|
| Unit | `tests/Unit/<Context>/` | domain rules — no framework, no database |
| Feature | `tests/Feature/<Context>/` | application services against PostgreSQL 16 |
| Contract | `tests/Contract/<Context>/` | HTTP endpoints, validated against `docs/openapi` |
| Arch | `tests/Arch/` | architecture gates; you should rarely need to add one |

Run one stage at a time with `composer test:arch`, `test:suite`, `test:api`, `test:lint`, `test:types`.
