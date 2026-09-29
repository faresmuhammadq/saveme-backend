---

description: "Task list for Plans & Entitlements (B3)"
---

# Tasks: Plans & Entitlements

**Input**: Design documents from `/specs/009-plans-entitlements/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/billing-api.md](./contracts/billing-api.md), [quickstart.md](./quickstart.md)

**Tests**: included. The spec's Invariants table names every test file (constitution P-16 rule 3). Write each story's tests first and watch them fail.

**Organization**: grouped by user story:

- US1: entitlements (P1)
- US2: catalogue (P1)
- US3: my subscription (P2)
- US4: operator actions (P2)
- US5: lapse (P2)

**Conventions for every task** (as in specs 007 and 008):

- Run through the container: `dev exec -- vendor/bin/pest <path> --compact`, `dev <artisan command>`.
- P-02 layers:
  - Ports live in `Domain/Ports`.
  - Infrastructure depends only on Domain.
  - Controllers pass `(string) $request->user()->getAuthIdentifier()` to Application services.
  - Another module is reached only through its `Application` namespace or a Domain enum.
- `Billing\Domain\PlanCode` may be used only in Billing Domain, `Application\Entitlements` and Infrastructure (`tests/Arch/EntitlementsTest.php`).
- Money is `amount_minor` integers plus an ISO 4217 `currency`, validated with `Shared\Domain\Money\Currency`.
- Instants are written with microseconds (`format('Y-m-d H:i:s.uP')`).
- Run `dev exec -- vendor/bin/pint --dirty --format agent` after PHP edits.
- Contract tests use `assertValidRequest()` and `assertValidResponse($status)`, covering every documented status.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on unfinished tasks)
- **[Story]**: US1–US5 from spec.md

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: the decision record, configuration, schema and wiring.

- [X] T001 Write `docs/adr/0013-entitlements-with-manual-channel.md` from `docs/adr/template.md`:
  - Status Accepted, date 2026-09-28, principles P-09 and P-10, "Supersedes: ADR-0007".
  - It restates ADR-0007's Decision rules 1–12, changing only rule 2 so that `provider` ∈ {`google_play`, `instapay`, `manual`}, and adding rule 13: "The free tier is the default plan applied when no subscription governs; no subscription row represents it. `manual` records operator grants and redeem codes and is never a payment."
  - Its Context covers why (spec 009, Q1 = A), and its Alternatives cover Free as a `system` row and grants recorded as `instapay`.
  - In `docs/adr/0007-entitlements-from-subscriptions.md`, change only the status line to `Superseded by [ADR-0013](0013-entitlements-with-manual-channel.md)`.
  - Add the ADR-0013 row to `docs/adr/README.md` and point the "Access and money-in" reading thread at 0013.
- [X] T002 [P] Amend `.specify/memory/constitution.md` to **v1.3.2 (PATCH)**:
  - P-09's and P-10's `**ADRs**:` lines become `[ADR-0013](../../docs/adr/0013-entitlements-with-manual-channel.md) (supersedes ADR-0007)`;
  - add a Sync Impact Report entry ("PATCH: ADR-0013 supersedes ADR-0007 to add the `manual` channel and record that the free tier is the absence of a subscription; no rule text changes");
  - update the header, footer and Constitution Check template version and the Last Amended date (2026-09-28).
- [X] T003 [P] Create `config/billing.php` with `grace_days` → `(int) env('BILLING_GRACE_DAYS', 3)` and a PHPDoc header ("grace for channels that set none: operator grants, InstaPay until B11 overrides it"). Add `BILLING_GRACE_DAYS=3` to `.env.example` with a comment.
- [X] T004 Create `app/Modules/Billing/Infrastructure/Database/Migrations/2026_09_28_000001_create_plans_table.php`:
  - `id uuid PK`;
  - `code varchar(40) UNIQUE`;
  - `name jsonb`;
  - `period_type varchar(10)` with CHECK in (`monthly`,`yearly`,`lifetime`,`custom`);
  - `period_days integer NULL`, with CHECK `(period_type = 'custom') = (period_days IS NOT NULL AND period_days >= 1)`;
  - `is_default boolean`;
  - `status varchar(10)` with CHECK in (`active`,`retired`);
  - `sort_order integer`;
  - `features jsonb`;
  - `prices jsonb DEFAULT '[]'`;
  - `timestampsTz(6)`;
  - the partial unique index `CREATE UNIQUE INDEX plans_one_active_default ON plans ((true)) WHERE is_default AND status = 'active'`.
- [X] T005 Create `app/Modules/Billing/Infrastructure/Database/Migrations/2026_09_28_000002_create_subscriptions_table.php`:
  - `id uuid PK`;
  - `user_id uuid` (no FK across modules);
  - `plan_id uuid FK → plans`;
  - `provider varchar(12)` with CHECK in (`google_play`,`instapay`,`manual`);
  - `provider_ref varchar(255)`;
  - `status varchar(10)` with CHECK in (`active`,`grace`,`expired`,`revoked`);
  - `current_period_start timestamptz(6)`;
  - `current_period_end timestamptz(6) NULL`;
  - `grace_until timestamptz(6) NULL`;
  - `auto_renew boolean`;
  - `source_meta jsonb DEFAULT '{}'`;
  - `timestampsTz(6)`;
  - indexes `(user_id, status)`, `(status, current_period_end)` and `(status, grace_until)`, and UNIQUE `(provider, provider_ref)`.

  Run `dev migrate`.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: the domain types, persistence, seed and the real entitlement service, which every story and B1 depend on.

**⚠️ No user-story work starts until this phase is complete.**

### Tests first

- [X] T006 [P] Write `tests/Unit/Billing/PlanCodeTest.php`: `free` and `plus_monthly` are accepted; `Plus`, `plus-monthly`, an empty string and a 41-character code throw `InvalidArgumentException`.
- [X] T007 [P] Write `tests/Unit/Billing/FeatureTest.php`:
  - the enum's values are exactly `sharing.enabled`, `sharing.max_members_per_wallet`, `export.enabled`, `reports.advanced` and `wallets.max`;
  - `isQuota()` is true only for `sharing.max_members_per_wallet` and `wallets.max`;
  - `deniedValue()` is `false` for flags and `0` for quotas.
- [X] T008 [P] Write `tests/Unit/Billing/SubscriptionStatusTest.php`:
  - allowed transitions: `active→grace`, `grace→expired`, `active→revoked`, `grace→revoked`, `expired→active`;
  - every other pair, including anything from `revoked`, is refused;
  - `grants()` is true only for `active` and `grace`.
- [X] T009 [P] Write `tests/Unit/Billing/GoverningSubscriptionTest.php` for `GoverningSubscription::among($subs, $now)`:
  - an empty list gives null;
  - expired and revoked subscriptions are ignored;
  - an `active` whose `current_period_end` passed with no `grace_until` is ignored;
  - a `grace` whose `grace_until` passed is ignored (dates win over status);
  - the latest `current_period_end` wins;
  - a null end (lifetime) beats any end;
  - a tie is broken by the later `createdAt`, then the higher `id`.
- [X] T010 [P] Write `tests/Feature/Billing/CatalogueSeedTest.php`:
  - after seeding there are exactly three active plans, `free`, `plus_monthly` and `plus_yearly`, in `sort_order` 1, 2, 3;
  - `free` is the only default;
  - free has `sharing.enabled` false, `sharing.max_members_per_wallet` 1, and every other flag true with `wallets.max` null (P-09 rule 5);
  - Plus has sharing true and 5 members;
  - the prices are `[{channel: instapay, product_id: null, amount_minor: 14900, currency: EGP}]` and `129000`;
  - seeding twice changes nothing (row count and `updated_at` stay put when the values match);
  - a plan definition missing a `Feature` key makes the seeder throw an exception naming the plan and the key (call the seeder's validation with a bad definition);
  - inserting a second active default fails on the partial index.

### Implementation

- [X] T011 [P] Create `app/Modules/Billing/Domain/PlanCode.php`: a `final readonly` class with `string $value`, validated against `^[a-z0-9_]{1,40}$`, throwing `InvalidArgumentException` otherwise. Make T006 pass.
- [X] T012 [P] Create `app/Modules/Billing/Domain/Feature.php`: a string-backed enum with the cases `SharingEnabled`, `SharingMaxMembersPerWallet`, `ExportEnabled`, `ReportsAdvanced` and `WalletsMax` (values as in T007), plus `isQuota(): bool` and `deniedValue(): bool|int`. Make T007 pass.
- [X] T013 [P] Create `app/Modules/Billing/Domain/Subscriptions/SubscriptionStatus.php` (an enum `active|grace|expired|revoked` with `canBecome(self): bool` and `grants(): bool`) and `SubscriptionRecord.php` (`final readonly`: `id`, `userId`, `planId`, `provider`, `providerRef`, `SubscriptionStatus $status`, `DateTimeImmutable $currentPeriodStart`, `?DateTimeImmutable $currentPeriodEnd`, `?DateTimeImmutable $graceUntil`, `bool $autoRenew`, `DateTimeImmutable $createdAt`). Make T008 pass.
- [X] T014 Create `app/Modules/Billing/Domain/Subscriptions/GoverningSubscription.php`, a pure static `among(array $subscriptions, DateTimeImmutable $now): ?SubscriptionRecord` per research R-4. Make T009 pass.
- [X] T015 [P] Create `app/Modules/Billing/Domain/Plans/PlanRecord.php` (`final readonly`):
  - `id`, `PlanCode $code`;
  - `array{ar: string, en: string} $name`, `periodType`, `?int $periodDays`;
  - `bool $isDefault`, `status`, `int $sortOrder`;
  - `array<string, bool|int|null> $features`;
  - `list<array{channel: string, product_id: ?string, amount_minor: int, currency: string}> $prices`.
  Add `nameIn(string $locale): string`, falling back to `en`.
- [X] T016 [P] Create `app/Modules/Billing/Domain/Events/SubscriptionActivated.php` (`eventId`, `occurredAt`, `userId`, `subscriptionId`, `planId`) and `SubscriptionLapsed.php` (`eventId`, `occurredAt`, `userId`, `subscriptionId`, `status`), both `final readonly`, implementing `ShouldDispatchAfterCommit`, with scalars only.
- [X] T017 Create `app/Modules/Billing/Domain/Ports/PlanRepository.php`:
  - `activeCatalogue(): list<PlanRecord>` (active, by `sort_order`);
  - `find(string $id): ?PlanRecord`;
  - `defaultPlan(): PlanRecord` (throws `RuntimeException` "No active default plan" when missing).

  Create `app/Modules/Billing/Domain/Ports/SubscriptionRepository.php`:
  - `forUser(string $userId): list<SubscriptionRecord>`;
  - `find(string $id): ?SubscriptionRecord`;
  - `create(array $row): SubscriptionRecord`;
  - `transition(string $id, SubscriptionStatus $from, SubscriptionStatus $to, array $changes = []): bool`, a conditional update `WHERE status = $from`;
  - `dueForGrace(DateTimeImmutable $now): list<SubscriptionRecord>`;
  - `dueForExpiry(DateTimeImmutable $now): list<SubscriptionRecord>`.
- [X] T018 Create the models `app/Modules/Billing/Infrastructure/Models/Plan.php` and `Subscription.php`: `HasUuids`, `$dateFormat = 'Y-m-d H:i:s.uP'`, casts (`name`, `features`, `prices` and `source_meta` as array; instants as `immutable_datetime`), `@property` blocks, and factories in `app/Modules/Billing/Infrastructure/Database/Factories/{PlanFactory,SubscriptionFactory}.php`. `SubscriptionFactory` has the states `manual()`, `active(int $daysLeft = 30)`, `grace(int $daysLeft = 2)`, `expired()`, `revoked()` and `lifetime()`, and defaults to the seeded `plus_monthly` plan.
- [X] T019 Implement `app/Modules/Billing/Infrastructure/Repositories/EloquentPlanRepository.php` and `EloquentSubscriptionRepository.php` for the ports. `transition` writes microsecond instants and returns `affected === 1`. Create `app/Modules/Billing/Infrastructure/BillingPersistenceServiceProvider.php`, binding both ports, and register it in `bootstrap/providers.php` before `BillingServiceProvider`.
- [X] T020 Create `app/Modules/Billing/Infrastructure/Database/Seeders/BillingSeeder.php` (R-8):
  - It holds a `definitions(): list<array{...}>` of the three launch plans exactly as in data-model.md's "Launch rows" (names en/ar: "Free"/"مجاني", "Plus · Monthly"/"بلس · شهري", "Plus · Yearly"/"بلس · سنوي").
  - `validate(array $definition): void` throws unless every `Feature` value is a key, and unless every price has a known `Currency` and an integer `amount_minor` ≥ 0.
  - `run()` validates all definitions, then upserts by `code` (updating only when values differ).
  - Remove the `@phpstan-ignore function.impossibleType` comment in `database/seeders/DatabaseSeeder.php` if Larastan now sees the class exist.
  - Run `dev db:seed --no-interaction`.
  - Set `protected bool $seed = true;` in `tests/TestCase.php`, with the PHPDoc line "reference data, the plan catalogue (spec 009, R-8), is present in every Feature and Contract test".
  Make T010 pass.
- [X] T021 Create `app/Modules/Billing/Application/Entitlements/Entitlements.php`, a `final readonly` class holding a `PlanRecord`:
  - `allows(Feature $feature): bool` (a flag's value; for a quota, `null` or > 0);
  - `quota(Feature $feature): ?int` (null = unlimited; a flag feature throws `LogicException`);
  - `summary(): EntitlementSummary`.

  Rewrite `app/Modules/Billing/Application/Entitlements/EntitlementSummary.php` as `final readonly` with `string $planId`, `array{ar: string, en: string} $planName` and `array<string, bool|int|null> $features`.
- [X] T022 Rewrite `app/Modules/Billing/Application/Entitlements/EntitlementService.php` from an interface into a `final class`:
  - `forUser(string $userId): Entitlements` resolves `GoverningSubscription::among($subs->forUser($userId), now())`, then that plan or `PlanRepository::defaultPlan()`, memoised in a private per-instance array;
  - `summaryFor(string $userId): EntitlementSummary`.

  PHPDoc cites ADR-0013 and request-scoped caching.
- [X] T023 Rewrite `app/Modules/Billing/Application/BillingServiceProvider.php` to bind `EntitlementService` as `scoped`. Delete `app/Modules/Billing/Application/SubscriptionService.php` and `app/Modules/Billing/Application/Provisional/` (R-7).
- [X] T024 Remove B1's enrolment seam (R-7):
  - in `app/Modules/Identity/Application/Registration/RegisterAccount.php`, drop the `SubscriptionService` constructor argument and the `enrolInDefaultPlan` call, and update the class PHPDoc ("user, data key and profile in one unit of work; no subscription: the free plan is the absence of one, ADR-0013");
  - in `tests/Feature/Identity/RegistrationTest.php`, rewrite "the default subscription is part of the same unit of work" as "the profile is part of the same unit of work": bind a `ProfileRepository` whose `create` throws, and assert no user, profile or user key remains;
  - add one line under spec 007's FR-003 in `specs/007-identity-auth/spec.md`: "Amended by spec 009 (2026-09-28): registration creates no subscription."
- [X] T025 Add `toWire(string $locale): array{plan: array{id: string, name: string}, features: object}` to `app/Modules/Billing/Application/Entitlements/EntitlementSummary.php`. It is the single serialiser, in Application, because Identity's Http may call Billing's Application but never Billing's Http (P-02). Update `app/Modules/Identity/Http/Controllers/LoginController.php` so `'entitlements' => $result->entitlements->toWire(app()->getLocale())`. Keep `app/Modules/Identity/Application/Dto/SignInResult.php` typed on `EntitlementSummary`. In `tests/Contract/Identity/LoginTest.php`, assert `data.entitlements.plan.name` "Free" and `features['sharing.enabled']` false. Run `dev exec -- vendor/bin/pest tests/Contract/Identity tests/Feature/Identity --compact` to green.
- [X] T026 Create `docs/openapi/paths/billing.yaml` with the schemas from [contracts/billing-api.md](./contracts/billing-api.md) (`PlanRef`, `Plan`, `EntitlementSummary`, `MySubscription`) and no operations yet. Move `EntitlementSummary` there: delete it from `docs/openapi/paths/identity.yaml` and point `SignInResult.entitlements` at `./billing.yaml#/components/schemas/EntitlementSummary`. `dev exec -- vendor/bin/pest tests/Contract/OpenApi tests/Contract/Identity/LoginTest.php --compact` stays green.

**Checkpoint**: the unit tests and the seed test are green, B1's suites are green with the real Free plan, and `composer test:arch` is green.

---

## Phase 3: User Story 1 - The app knows what the person may do (Priority: P1) 🎯 MVP

**Goal**: `GET /me/entitlements` from the governing subscription or the free plan, with the same answer at sign-in.

**Independent Test**: register, sign in, read entitlements: the Free plan. Give a Plus subscription through a factory: Plus. Expire it: Free.

- [X] T027 [P] [US1] Write `tests/Feature/Billing/EntitlementResolutionTest.php`:
  - a person with no subscription gets the free plan, and registration created no `subscriptions` row;
  - an active Plus subscription gives sharing true and quota 5;
  - a grace one still grants;
  - one whose `grace_until` passed grants nothing **before** any lapse has run;
  - expired and revoked grant nothing;
  - two overlapping (instapay ending in 10 days, manual in 40) → the manual one governs;
  - lifetime beats a dated one;
  - within one service instance the second `forUser` issues 0 queries (use `DB::listen`), and a fresh scoped instance, `app()->forgetScopedInstances()`, sees a subscription created in between (ADR-0013 rule 8).
- [X] T028 [P] [US1] Write `tests/Contract/Billing/EntitlementsTest.php`:
  - `GET /me/entitlements` gives `200` with `data.plan.name` "Free" (and "مجاني" with `Accept-Language: ar`), `data.features['sharing.enabled']` false, and no `code` member anywhere;
  - `401` without a credential;
  - the sign-in response's `data.entitlements` equals the `GET /me/entitlements` data for the same person.
- [X] T029 [US1] Create `app/Modules/Billing/Http/routes.php` (under `prefix('api/v1')->middleware('api')`, registered by a new `app/Modules/Billing/Http/BillingHttpServiceProvider.php` added to `bootstrap/providers.php`) with `Route::get('me/entitlements', MyEntitlementsController::class)->middleware('auth:api')`. Create `app/Modules/Billing/Http/Controllers/MyEntitlementsController.php`, returning `Envelope::ok($summary->toWire(app()->getLocale()))`.
- [X] T030 [US1] Add `getMyEntitlements` to `docs/openapi/paths/billing.yaml` and the `/me/entitlements` entry to `docs/openapi/openapi.yaml`. Run T027 and T028 to green.

**Checkpoint**: every module (and the app) can ask what a person may do.

---

## Phase 4: User Story 2 - Browse the plans (Priority: P1)

**Goal**: public, localised `GET /plans` listing active plans in order, with money prices and no code.

**Independent Test**: read `/plans` without a credential in both languages; retire a plan and see it gone while its subscribers keep it.

- [X] T031 [P] [US2] Write `tests/Contract/Billing/PlansTest.php`:
  - without a credential, `GET /plans` gives `200` with three items in order, each with `id`, `name`, `period_type`, `period_days`, `is_default`, `features` and `prices`, and no `code`;
  - with `Accept-Language: ar` the names are Arabic;
  - `meta` is `{next_cursor: null, has_more: false, limit: 50}`;
  - Plus monthly's price is `{channel: "instapay", product_id: null, amount_minor: 14900, currency: "EGP"}`;
  - after `Plan::where('code','plus_yearly')->update(['status' => 'retired'])` only two items are listed, and a person with an active `plus_yearly` subscription still gets sharing true from `/me/entitlements`.
- [X] T032 [US2] Create `app/Modules/Billing/Application/Plans/PlanCatalogue.php` (`active(): list<PlanRecord>`) and `app/Modules/Billing/Http/Controllers/PlansController.php`, returning `Envelope::collection(array_map(fn ($plan) => [...], $plans), null, 50)`. The item's `name` is `$plan->nameIn(app()->getLocale())`, prices are copied as they are, and there is **no** `code`. Add the public route `Route::get('plans', PlansController::class)`.
- [X] T033 [US2] Add `listPlans` (`security: []`, `x-filterable: []`, `x-sortable: []`) to `billing.yaml` and the `/plans` index entry. Run T031 to green.

---

## Phase 5: User Story 3 - See my subscription (Priority: P2)

**Goal**: `GET /me/subscription`: the governing subscription, or the free plan with nulls.

**Independent Test**: with no subscription you get Free and nulls; with a manual Plus subscription you get its provider, status and dates.

- [X] T034 [P] [US3] Write `tests/Contract/Billing/SubscriptionTest.php`:
  - with no subscription, `200` with `plan.name` "Free", and `provider`, `status`, `current_period_start`, `current_period_end`, `grace_until` and `auto_renew` all null;
  - with an active manual Plus subscription, `provider` "manual", `status` "active" and UTC instants with microseconds and `Z`;
  - in grace, `status` "grace" and a `grace_until`;
  - `401` without a credential.
- [X] T035 [US3] Create `app/Modules/Billing/Application/Subscriptions/MySubscription.php` (`handle(userId): array{plan: PlanRecord, subscription: ?SubscriptionRecord}`), plus an invokable `MySubscriptionController` in `app/Modules/Billing/Http/Controllers/`, rendering `{plan: {id, name}, provider, status, current_period_start, current_period_end, grace_until, auto_renew}`. Add `Route::get('me/subscription', …)->middleware('auth:api')`, `getMySubscription` in `billing.yaml`, and the index entry. Run T034 to green.

---

## Phase 6: User Story 4 - Operators grant, extend and revoke (Priority: P2)

**Goal**: operator actions as application services, each with exactly one `entitlement_changed` entry.

**Independent Test**: grant, extend and revoke through `OperatorSubscriptions`, checking the subscription, the entitlements and one audit entry each.

- [X] T036 [P] [US4] Write `tests/Feature/Billing/OperatorActionsTest.php` with an operator id `(string) Str::uuid7()`:
  - `grant(op, user, plusMonthlyId, 30, ' Goodwill ')` creates one `manual` active subscription with `provider_ref` `op:{op}`, an end 30 days out (frozen time) and `auto_renew` false; entitlements become Plus; one `entitlement_changed` entry has `actor_type` operator, `actor_id` op, `subject_type` subscription and detail `{action: grant, plan_id, days: 30, reason: "Goodwill"}`; one `SubscriptionActivated` is dispatched;
  - `grant(..., null, ...)` gives a lifetime subscription (end null);
  - granting the default plan, a retired plan or an unknown plan throws `PlanNotGrantable`;
  - a blank or 501-character reason throws `InvalidReason`;
  - days 0 throws `InvalidDays`;
  - `extend(op, sub, 15, 'x')` moves the end by 15 days;
  - on an expired subscription, extend makes it active again and dispatches `SubscriptionActivated`;
  - extending a revoked subscription throws `SubscriptionRevoked`;
  - `revoke(op, sub, 'x')` gives `revoked`, Free entitlements and `SubscriptionLapsed(status: revoked)`, and revoking again changes nothing and writes no second entry;
  - no audit detail contains `14900` or any amount.
- [X] T037 [US4] Create the refusals in `app/Modules/Billing/Domain/Refusals/`: `PlanNotGrantable`, `InvalidReason`, `InvalidDays`, `SubscriptionRevoked` and `SubscriptionNotFound`, extending `Shared\Domain\Errors\Refusal`. Use `VALIDATION_FAILED` with a field error (`/plan_id` `INVALID_ENUM_VALUE`, `/reason` `OUT_OF_RANGE`, `/days` `OUT_OF_RANGE`), `IMMUTABLE_FIELD` at `/status` for revoked, and `NOT_FOUND`. They are ready for B12's HTTP surface.
- [X] T038 [US4] Add `app/Modules/Identity/Application/Audit/Details/EntitlementChangedDetail.php` (`final readonly`, `action` in `grant|extend|revoke|expire`, `planId`, `?int $days`, `?string $reason`; `toArray()` omits nulls). The reason is trimmed and at most 500 characters.
- [X] T039 [US4] Create `app/Modules/Billing/Application/Subscriptions/OperatorSubscriptions.php` with `grant`, `extend` and `revoke` per research R-6. Each runs in `DB::transaction`, validates the reason (trimmed, 1–500 characters) and days (≥ 1), and writes exactly one `AuditLog::record(new AuditRecord(AuditEvent::EntitlementChanged, ActorType::Operator, $operatorId, SubjectType::Subscription, $subscriptionId, new EntitlementChangedDetail(...)))`. Events are published after commit. `extend` on `expired` transitions to `active`, sets `current_period_end = max(now, old end) + days`, and clears `grace_until`. Run T036 to green.

---

## Phase 7: User Story 5 - Subscriptions lapse on their own (Priority: P2)

**Goal**: an hourly lapse moving subscriptions to grace and then expired, idempotent, with an audit entry only when the governing plan changes.

**Independent Test**: seed one subscription past its period end and one past its grace end, run the command, and check statuses, events and entitlements.

- [X] T040 [P] [US5] Write `tests/Feature/Billing/LapseTest.php`:
  - an active subscription ended an hour ago → `grace`, with `grace_until` = end + 3 days, still granting;
  - a grace subscription whose `grace_until` passed → `expired`, granting nothing;
  - a lifetime subscription never lapses;
  - running twice changes nothing more and dispatches no second event;
  - `SubscriptionLapsed` is dispatched once per transition with the new status;
  - one `entitlement_changed` entry (actor `system`, action `expire`) is written only when expiry changes the governing plan: a person still covered by another active subscription gets none;
  - `dev billing:lapse-subscriptions` exits 0 and reports the counts.
- [X] T041 [P] [US5] Write `tests/Feature/Billing/LapseDeletesNothingTest.php`: snapshot every Identity table and the person's `subscriptions` rows; expire and revoke their subscription; assert that no row was deleted anywhere and the Identity snapshot is identical (P-09 rule 6).
- [X] T042 [US5] Create `app/Modules/Billing/Application/Subscriptions/LapseSubscriptions.php` (`run(): array{graced: int, expired: int}`) per research R-5, using `SubscriptionRepository::transition` and resolving the governing plan before and after expiry to decide on the audit entry. Create `app/Modules/Billing/Http/Console/LapseSubscriptions.php` (`#[Signature('billing:lapse-subscriptions')]`), registered from `BillingHttpServiceProvider`. Add `Schedule::command('billing:lapse-subscriptions')->hourly();` to `routes/console.php`. Run T040 and T041 to green.

---

## Phase 8: Polish & Cross-Cutting Concerns

- [X] T043 [P] Update `docs/glossary.md`'s Subscription entry: the channels are `google_play`, `instapay` and `manual` (operator grants, redeem codes; never a payment; ADR-0013); a "governing subscription" definition (the latest-ending active or grace subscription whose grace has not passed; none → the default plan); and the status machine citing ADR-0013. In the Plan entry, add "the default plan is applied when no subscription governs; nobody subscribes to it".
- [X] T044 [P] Update `specs/README.md` Status with a B3 → `009-plans-entitlements` row, "🟡 Built, awaiting review". In `specs/briefs/README.md`, note B3 is specified as spec 009.
- [X] T045 Run `dev exec -- vendor/bin/pint --format agent` and `dev exec -- composer test`. Fix everything: Larastan array shapes on the jsonb columns and records; the P-09 arch gate must stay green with `PlanCode` used only in Billing Domain, `Application\Entitlements` and Infrastructure.
- [X] T046 Walk [quickstart.md](./quickstart.md) steps 1–8 against `http://saveme-backend.test` (run `dev db:seed` first, then `dev queue:restart`). Re-bundle `docs/openapi` and re-import into Apidog project 1332822 with `--module-map "Personal finance backend API=1363204"`. Create an Apidog Markdown doc "Plans & entitlements" covering the catalogue, the summary, gating on `features` and not names, and the subscription read. Update the Identity guide's sign-in section for `entitlements.plan` being `{id, name}`. Append a "Verification" note to `quickstart.md`.

---

## Dependencies & Execution Order

- **Setup (T001–T005)**: T002 and T003 are parallel with T001. T004 comes before T005.
- **Foundational (T006–T026)**: the tests T006–T010 come first; T020 also sets the TestCase seed. T011–T016 are parallel. T017 comes before T019. T018 comes before T019 and T020. T021 comes before T022. T022 comes before T023, T024 and T025. T026 can come any time after T001.
- **US1 (T027–T030)**: needs Foundational.
- **US2 (T031–T033)**: needs Foundational. It is parallel with US1 except for the shared `routes.php` and provider (do T029 first).
- **US3 (T034–T035)**: needs Foundational and the Billing Http provider (T029).
- **US4 (T036–T039)**: needs Foundational. It is independent of US1–US3.
- **US5 (T040–T042)**: needs Foundational. T042 reuses the governing resolution (T014).
- **Polish (T043–T046)**: T043 and T044 can happen any time. T045 and T046 come last.

Story order: `Setup → Foundational → US1 → US2 → US3 → US4 → US5 → Polish`. US4 and US5 can run alongside US1–US3.

## Parallel Execution Examples

- **Setup**: `T002 constitution · T003 config` alongside `T001 ADR`.
- **Foundational tests**: `T006 · T007 · T008 · T009 · T010`.
- **Foundational types**: `T011 PlanCode · T012 Feature · T013 status/record · T015 PlanRecord · T016 events`.
- **Stories**: `T027 · T028` (US1), `T031` (US2), `T034` (US3), `T036` (US4) and `T040 · T041` (US5), all tests, can be written together.
- **Docs**: `T043 · T044`.

## Implementation Strategy

1. **MVP (US1)**: the real `EntitlementService`, the seeded catalogue, `GET /me/entitlements`, and sign-in reporting the real Free plan. From here every later feature can gate on `Feature`.
2. **US2**: the public catalogue for the upgrade screen and the website.
3. **US3**: "my plan".
4. **US4**: operator grants for support and review, ready for B12's screens.
5. **US5**: automatic lapse.
6. **Polish**: glossary, full gate, walk, Apidog.

Each checkpoint leaves `composer test` green.
