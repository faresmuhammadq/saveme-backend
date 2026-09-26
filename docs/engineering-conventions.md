# Engineering Conventions

**Status**: Active. How this repository is laid out, tested, checked and run. The checklist a pull request satisfies is in [CONTRIBUTING.md](../CONTRIBUTING.md); this document is the reasoning and the detail behind it.

**Companions**: the [constitution](../.specify/memory/constitution.md) fixes the rules, the [decision records](adr/README.md) say why, [the API conventions](api-conventions.md) fix the HTTP contract, and [the security baseline](security-baseline.md) fixes what every feature must be true of.

Every convention here is either **enforced by the pipeline** or **checkable by a reviewer against one pull request**. One that is neither is a defect in this document.

---

## Getting started

The local environment is the `dev` CLI: a per-worktree Docker environment on a machine-wide shared stack. There is no second way to run locally.

```bash
dev up                       # start this worktree's environment
dev project env --yes        # fill .env with this worktree's database, cache, mail and URL
dev exec -- composer install
dev key:generate             # `dev <x>` runs `php artisan <x>` in the container
dev migrate
dev exec -- composer test    # the whole gate
```

The container runs PHP 8.5, the version `composer.json` requires. Host PHP has no PostgreSQL driver, so tests and artisan always run in the container.

| Need | Command |
|---|---|
| Run artisan | `dev <command>`, e.g. `dev migrate` — the `php artisan` prefix is added for you |
| Run anything else in the container | `dev exec -- <command>` or `dev -- <command>`, which run it as written |
| See this worktree's containers, hostname and port | `dev status` |
| Follow logs | `dev logs` |
| See the shared services, their health and credentials | `dev infra status` |
| Copy another worktree's database into this one | `dev clone-db --from main` |
| Stop this worktree's environment | `dev down` |

### The shared stack

| Service | Host | Use |
|---|---|---|
| PostgreSQL 16 | `postgres-16:5432` | the only database engine (ADR-0001) |
| Redis 7 | `redis-7:6379` | cache and queues, keyed per worktree |
| Mailpit | `mailpit:1025`, UI at <http://mailpit.test> | every mail sent locally |
| Proxy | resolves `*.test` | the app at <http://saveme-backend.test> |

### Worktrees

Each git worktree gets its **own** environment and its **own** database by default, named after the worktree. Two worktrees never share a database, so their test runs cannot corrupt each other. To start a new worktree from a known state, copy the main one's database in with `dev clone-db --from main`.

---

## Module layout

Code lives in one of nine bounded contexts, fixed by the constitution: Identity, Wallets, Budgeting, Sharing, Billing, Sync, Reporting, Notifications, Admin. Plus `app/Modules/Shared`, which holds persistence-free value objects only.

```text
app/Modules/<Context>/
├── Domain/                 value objects, entities, domain events
│   └── Ports/              repository interfaces (ports)
├── Application/            application services, query services, DTOs, jobs, listeners
├── Infrastructure/
│   ├── Models/             Eloquent models — private to the module
│   ├── Repositories/       repository implementations
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

Every kind of class has exactly one home:

| Class | Directory |
|---|---|
| Value object, entity, domain event | `Domain/` |
| Repository interface (port) | `Domain/Ports/` |
| Application service, query service, DTO | `Application/` |
| Queued job, event listener | `Application/` |
| Eloquent model | `Infrastructure/Models/` |
| Repository implementation | `Infrastructure/Repositories/` |
| Migration, factory, seeder | `Infrastructure/Database/…` |
| Controller, form request, API resource | `Http/…` |
| Policy | `Http/Policies/` |
| Shared API runtime: envelope, problem renderer, api-group middleware, base `ApiRequest`, collection query, log redaction | `app/Http/Api/`, outside the modules |

### Layer rules

| Layer | May use | Must not use |
|---|---|---|
| Domain | its own Domain, Shared | `Illuminate\Database`, its own Application, Infrastructure or Http, any other module |
| Application | its own Domain, Shared, other modules' **Application** | its own Http; any other module's Infrastructure or Http |
| Infrastructure | its own Domain, Shared, `Illuminate\Database` | its own Application or Http; any other module's Infrastructure or Http |
| Http | its own Application and Domain, Shared | any other module's Infrastructure or Http |
| Shared | PHP and framework value helpers | any module, `Illuminate\Database` |

Cross-module interaction uses exactly one of two mechanisms: calling another module's Application service, or handling its domain event.

A module's ports — the repository interfaces its Application layer calls — live in `Domain/Ports`, and their implementations in `Infrastructure/Repositories`. Infrastructure therefore depends only on Domain, never on Application.

### The shared API runtime

The plumbing every endpoint shares lives in `app/Http/Api`: the response `Envelope`, the problem renderer and its refusals, the middleware of the `api` group (request id, JSON negotiation, content language, client version, transport, rate-limit headers), the base `ApiRequest`, the collection query, and log redaction. It is cross-cutting plumbing outside the modules, in the same way `app/Providers/ModuleServiceProvider.php` is, and belongs to no bounded context. A module's form requests extend `ApiRequest`, and its controllers return an `Envelope`; a module never builds its own envelope or problem body.

### How a module is wired

`app/Providers/ModuleServiceProvider.php` wires every module **by existing**: for each directory under `app/Modules/`, it loads the module's migrations and teaches factories where the module keeps them. A new module needs no registration. It lives in `app/Providers` rather than `Shared` because it is persistence wiring, and the shared kernel holds none.

A model at `App\Modules\Wallets\Infrastructure\Models\Wallet` finds its factory at `App\Modules\Wallets\Infrastructure\Database\Factories\WalletFactory`.

### Seeders

A module with **reference data** — data the application needs to run, like plans — ships `Infrastructure/Database/Seeders/<Context>Seeder.php`. It must be idempotent, keyed on a natural key, and safe to run in production. `database/seeders/DatabaseSeeder.php` calls each module's seeder in a fixed dependency order, and skips a module that has none. Sample data for local development is a separate `<Context>DemoSeeder`, never called in production.

A pull request that adds no reference data says so: "Seeder: N/A — no reference data".

---

## Test suites

| Suite | Directory | Boots the app | Database | Exercises |
|---|---|---|---|---|
| Arch | `tests/Arch/` | no | none | architecture gates |
| Unit | `tests/Unit/<Context>/` | no | none | domain rules |
| Feature | `tests/Feature/<Context>/` | yes | PostgreSQL 16, refreshed | application services |
| Contract | `tests/Contract/<Context>/` | yes | PostgreSQL 16, refreshed | HTTP endpoints against `docs/openapi` |

Unit tests deliberately do not boot the framework. A domain rule that needs the framework to be tested is in the wrong layer.

### Contract tests

Every Contract test validates against the published definition through [Spectator](https://github.com/hotmeteor/spectator). An endpoint test asserts conformance in one line:

```php
$this->withHeaders(clientHeaders())
    ->getJson('/api/v1/wallets/'.$id)
    ->assertValidRequest()
    ->assertValidResponse(200);
```

`clientHeaders()` supplies the platform and version headers every request must carry. Spectator validates the body and status against the operation; a test still asserts `X-Request-Id` with `assertHeader`, since header presence is not part of body validation.

**An empty `meta` is an object.** PHP serialises an empty array as JSON `[]`, but the envelope requires `meta` to be `{}`. Emit `(object) []`. The contract tests catch this; it is written here so it is not rediscovered one endpoint at a time.

### Every documented status gets a test

An endpoint's contract tests cover every status its path file documents, including each problem response. A client that has not been told about a `409` will not handle it; a server that has never been tested returning one may not return it correctly.

---

## The gates

| Gate | Enforces | Where | Fails naming |
|---|---|---|---|
| Formatting | Pint's Laravel preset | `composer test:lint` | the files to fix |
| Static analysis | Larastan **level 8** on application code | `composer test:types` | the file and rule |
| Module boundaries | P-02 | `tests/Arch/ModuleBoundariesTest.php` | P-02 and the forbidden import |
| Unknown modules | P-02 | same file | any directory outside the nine contexts and Shared |
| Money columns | P-03 | `tests/Arch/MigrationsTest.php` | P-03 and `file:line` |
| Plan codes | P-09 | `tests/Arch/EntitlementsTest.php` | P-09 and the class using `PlanCode` |
| Database engine | P-16 rule 2 | `tests/Feature/Support/DatabaseEngineTest.php` | anything other than PostgreSQL 16 |
| API definition | the skeleton's rules | `tests/Contract/OpenApi/` | the file and the rule |
| Path template | the template every feature copies | `tests/Contract/OpenApi/TemplateConformanceTest.php` | the schema mismatch |
| Commit messages | the shape below | pipeline, pull requests only | the commit |
| Frozen migrations | append-only from `v1.0.0` | pipeline | the migration edited after the tag |

Every architecture gate passes on an empty module tree and fails on the first violating class.

Two things the gates deliberately do not do. **Test code is not statically analysed**: PHPStan cannot see that Pest binds `$this` inside a test closure, and the Pest-aware extension that fixes this is a dependency not yet approved. Test code is checked by running it. And **a plan code compared as a string literal** is invisible to the entitlement gate, which checks use of the `PlanCode` type; that half is a reviewer item.

`App\Modules\Billing\Domain\PlanCode` is named before it exists, so the gate is in place the day the first plan is. Only Billing's domain, infrastructure, and `Application\Entitlements` may use it. Every other module asks `EntitlementService` whether a feature is enabled.

---

## The pipeline

GitHub Actions, one job, steps in this order. A failing step stops every later one, so the red step names the stage.

| # | Step | Runs |
|---|---|---|
| 1 | Formatting | `composer test:lint` |
| 2 | Static analysis | `composer test:types` |
| 3 | Architecture | `composer test:arch` |
| 4 | Database engine | records `SELECT version()` in the run summary |
| 5 | Frozen migrations | passes until the `v1.0.0` tag exists |
| 6 | Test suite | `composer test:suite` — Unit, Feature, Contract |

A second job checks commit messages on pull requests. It is separate so a badly worded commit does not hide what the code stages found.

Locally, `composer test` runs formatting, static analysis, architecture and the test suite, in that order. It is the whole gate the constitution names, and it passes before a pull request is opened.

There is **no image-build step**. It is added by the feature that chooses a deployment target, along with the secret store and the staging and production hosts.

---

## Environment profiles

Four profiles, stated by what differs. Staging and production are rows only until a deployment target is chosen.

| Setting | Local | Pipeline | Staging | Production |
|---|---|---|---|---|
| `APP_ENV` | `local` | `testing` | `staging` | `production` |
| `APP_DEBUG` | `true` | `true` | `false` | `false` |
| `APP_URL` | `http://saveme-backend.test` | unused | chosen with the target | chosen with the target |
| `DB_HOST`, `DB_DATABASE` | filled by `dev project env`, one database per worktree | `127.0.0.1`, `saveme_backend_main_test` | chosen with the target | chosen with the target |
| `CACHE_STORE` | `database` or `redis` | `array` | `redis` | `redis` |
| `QUEUE_CONNECTION` | `database` | `sync` | `redis` or `database` | `redis` or `database` |
| `SESSION_DRIVER` | `database` | `array` | `redis` | `redis` |
| `SESSION_ENCRYPT` | `false` | `false` | `true` | `true` |
| `MAIL_MAILER` | `smtp` to Mailpit | `array` | a provider | a provider |
| `LOG_LEVEL` | `debug` | `debug` | `info` | `info` |
| `BCRYPT_ROUNDS` | `12` | `4` | `12` | `12` |
| `HASH_ARGON_MEMORY`, `HASH_ARGON_TIME`, `HASH_ARGON_THREADS` | `65536`, `4`, `1` | `1024`, `1`, `1` | `65536`, `4`, `1`, re-measured to 250–500 ms | `65536`, `4`, `1`, re-measured to 250–500 ms |
| `IDENTITY_KEYS`, `IDENTITY_ACTIVE_KEY`, `IDENTITY_LOOKUP_KEY` | the untracked `.env` | the job's environment | the deployment's secret store | the deployment's secret store |
| `ADMIN_NOTIFICATION_RECIPIENTS` | any local address, caught by Mailpit | empty | the operators' addresses | the operators' addresses |
| `API_MIN_ANDROID_VERSION`, `API_MIN_WEB_VERSION` | `1.0.0` | `1.0.0` | the oldest supported client | the oldest supported client |
| `API_REQUIRE_TLS` | off | off | on | on |
| `API_PROBLEM_BASE_URL` | `APP_URL` | `APP_URL` | the public API origin | the public API origin |
| `IDENTITY_VERIFY_URL`, `IDENTITY_RESET_URL` | pages under `APP_URL` | unused | the website or app deep-link pages | the website or app deep-link pages |
| `IDENTITY_GOOGLE_ENABLED` | `false` | `false` | `false` | `false` until Google Sign-In is built |
| Secrets live in | the untracked `.env` | the job's environment | the deployment's secret store | the deployment's secret store |

### May never differ

- the PHP version: 8.5, in the `dev` image, the pipeline and every deployment;
- the database engine and its major version: PostgreSQL 16;
- money as integer minor units with an ISO 4217 currency;
- identifiers as UUIDv7;
- instants stored in UTC;
- the migration set and the published API definition;
- the [never-log list](security-baseline.md#what-must-never-be-logged).

A setting the application reads that is missing from this table is a defect here.

---

## Migrations

**Before the `v1.0.0` tag**, a migration may be edited or squashed, because nothing has been deployed that a migration could break. Squash to one migration per aggregate before tagging.

**From `v1.0.0` onward, a merged migration is never edited, renamed or deleted.** A mistake is corrected by a new migration that moves the schema forward. `down()` is not relied on, and a rollback never runs in a shared environment.

The pipeline enforces this from the moment the tag exists: a change to any migration present at `v1.0.0` fails the build.

---

## Commit messages

```text
<type>(<scope>)!: <subject>
```

| Part | Rule |
|---|---|
| `type` | one of `feat`, `fix`, `docs`, `chore`, `refactor`, `test`, `build`, `ci` |
| `scope` | optional, lowercase, e.g. `wallets`, `openapi`, `ci` |
| `!` | marks a breaking change |
| subject | at most 72 characters |
| body | free text — the why |

Merge commits are skipped. A history in this shape can be grouped into a changelog by type with no human editing.

---

## Versions

Two numbers, with two meanings.

| Number | Lives in | Minor or patch moves when | Major moves when |
|---|---|---|---|
| API version `v1.N` | `info.version` in `docs/openapi/openapi.yaml` | any additive change: a new endpoint, member, code or parameter | only with a new base path, `/api/v2` |
| Release `vX.Y.Z` | a git tag | minor for a feature or an API minor; patch for a fix with no contract change | with an API major, or any commit marked `!` |

An additive change bumps the API minor **in the same pull request**. The pipeline refuses a catalogue code whose `since` is newer than `info.version`.

The base path `/api/v1` moves only with the API major, as the [API conventions](api-conventions.md#base-path-and-versioning) already fix.

---

## Specifications and decisions

Features are specified with `/speckit-specify`, then planned, broken into tasks and implemented through the same workflow. The specification template carries nine project sections — purpose, glossary terms, data model, invariants, API, authorization and entitlements, events, acceptance scenarios, open questions — so every feature spec has the same bones.

Two rules the template repeats because they are the ones most often skipped:

- a term not in [the glossary](glossary.md) is added there **before** the spec uses it;
- every invariant names the test that fails when it is broken.

A decision that extends or contradicts an Accepted decision record gets a **new** record that supersedes it. An Accepted record is never edited. A contradiction between a spec and a decision record is a defect, resolved before implementation.

---

## Back office (Admin module)

The back office is the only server-rendered interface (constitution P-18), built from the primitives spec 006 delivers. A feature composes its screens from them and writes no styling of its own.

- **Where things live**: everything is in `app/Modules/Admin/Http`, registered by `AdminServiceProvider`.
  - Primitives are anonymous Blade components in `Views/components`, used as `<x-admin::button>`, `<x-admin::table>` and so on.
  - Livewire classes are in `Livewire`, views in `Views/livewire`, strings in `Lang/{en,ar}` under the `admin::` namespace, and tokens and Alpine helpers in `Assets`.
  - Nothing goes in `resources/views` or `app/Livewire`.
- **Contracts**: the props, slots and states of every primitive are in [spec 006's contracts](../specs/006-admin-dashboard-foundation/contracts/components.md). The design source and its version are named in the spec.
- **Right-to-left by construction**: Admin views use only logical utilities (`ms-`, `me-`, `ps-`, `pe-`, `start-`, `end-`, `text-start`, `text-end`, `border-s`, `border-e`, `rounded-s`, `rounded-e`). Colours and sizes come from the token set, never arbitrary values. `tests/Arch/AdminBackOfficeTest.php` fails on either.
- **Text**: every string is in both `ar` and `en`, which `TranslationCompletenessTest` checks. Numerals are Western in both languages.
- **Navigation**: a feature adds its sidebar entry from its own service provider, without editing the shell:

  ```php
  app(NavigationRegistry::class)->add(new NavigationEntry(
      key: 'claims', group: 'operate', labelKey: 'billing::admin.nav.claims',
      icon: 'claims', route: 'admin.claims', order: 20,
      badge: fn (): ?int => $claims->pendingCount(),
  ));
  ```

- **Audited actions**: a screen uses the `RequiresReason` trait. The action itself is a **protected** method whose last parameter is `AuditReason $reason`, so it cannot run without a reason and the browser cannot call it directly. The public method only calls `$this->requireReason('claim.approve', 'approveClaim', [$claimId])`, and the view renders `<x-admin::reason-prompt action-key="claim.approve" />`.
- **Full pages**: a full-page Livewire component renders into the shell with `->layout('admin::page', ['title' => …, 'breadcrumbs' => …])`.
- **Gallery**: `/admin/_gallery` shows every primitive in every state. It exists in the local environment only.

---

## Dependencies

No package is added without the repository owner's approval. The approved development tools today are Pint, Larastan, Pest with its architecture plugin, and Spectator. The approved runtime packages beyond the framework and Livewire are `laravel/sanctum` (approved 2026-09-26, spec 007), for device-session bearer credentials.
