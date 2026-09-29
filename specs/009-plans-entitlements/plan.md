# Implementation Plan: Plans & Entitlements

**Branch**: `009-plans-entitlements` (trunk-based; work lands on `main`) | **Date**: 2026-09-28 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/009-plans-entitlements/spec.md`.

## Summary

Build the Billing module's core.

- **Catalogue**: `plans` stores the catalogue as data, with features and prices inside each plan. It is seeded with Free, Plus monthly (EGP 149) and Plus yearly (EGP 1,290), with 5 members per wallet on Plus.
- **Subscriptions**: `subscriptions` records every channel in one shape: `google_play`, `instapay`, and the new `manual`, added by ADR-0013, which supersedes ADR-0007.
- **Entitlements**: `EntitlementService` resolves the governing subscription by dates. The latest-ending active or grace subscription wins, and with none the free plan applies. Answers are cached for one request only.
- **Lapse**: an hourly job moves subscriptions to grace and then to expired.
- **Operator actions**: grant, extend and revoke are application services with one audit entry each.
- **API**: three read endpoints (`GET /plans`, `/me/entitlements`, `/me/subscription`).

B1's provisional Free-plan stand-in and its registration enrolment step are removed (Q1 = A).

## Technical Context

**Language/Version**: PHP 8.5, Laravel 13.31.

**Primary Dependencies**: none new.

**Storage**: PostgreSQL 16, with two tables (`plans`, `subscriptions`). jsonb holds features, prices and localised names.

**Testing**: Pest 5.
- Unit: governing subscription, status machine, `PlanCode`, `Feature`.
- Feature: resolution, seed, lapse, operator actions, nothing deleted.
- Contract: the three endpoints plus the updated sign-in.
- Arch: the existing P-09 gate.
- `TestCase` seeds the catalogue.

**Target Platform**: the Linux server (the `dev` container).

**Project Type**: modular-monolith JSON API.

**Performance Goals**: an entitlement answer costs at most two indexed reads per request (subscriptions by user, plus the plan); repeats within a request are free. `GET /plans` p95 under 50 ms.

**Constraints**:
- P-09: no plan-code comparison outside entitlement resolution.
- ADR-0007/0013: statuses, channels, request-scoped cache only.
- P-03: prices are money.
- P-10: no payment endpoint.

**Scale/Scope**:
- 3 operations, 2 migrations, 1 seeder, 1 ADR;
- about 20 Billing classes;
- B1 edits to remove the seam and change the summary shape;
- about 10 test files.

## Constitution Check

*Constitution v1.3.1 (`.specify/memory/constitution.md`). GATE: must pass before Phase 0 research. Re-check after Phase 1 design, keeping both results.*

### Pre-design

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | Plan, Plan Feature, Subscription, Entitlement, "billing period" (never bare "period"). "Governing subscription" is added to the glossary's Subscription notes |
| P-02 Module boundaries | PASS | Everything is in `app/Modules/Billing`. Audit writes go through `Identity\Application\Audit\AuditLog`, an Application-to-Application call. Identity reads entitlements through `Billing\Application\Entitlements\EntitlementService`. `Feature` sits in Billing Domain, so other modules name it without touching Infrastructure or Http |
| P-03 Money | PASS | Prices are `amount_minor` integers plus an ISO 4217 `currency`, validated by the shared `Currency`. No decimal or float anywhere; no `display_price` |
| P-04 Ledger integrity | N-A | No ledger |
| P-05 Derived balances | N-A | No balances |
| P-06 Identifiers | PASS | UUIDv7 keys. The plan code is a data key, not an identifier exposed to clients (R-2) |
| P-07 Sync and concurrency | N-A | Neither entity syncs (glossary) |
| P-08 Authorization | PASS | Reads are the caller's own; the catalogue is public. Operator actions take an operator identity, and the guard is D2's |
| P-09 Entitlements | PASS | Plans are data (R-1). Every check goes through `EntitlementService` and `Feature` (R-2, R-3). `PlanCode` is confined by the existing arch gate. `forWallet` resolves the owner and is wired in B4. Free is limited only on sharing (seed check). Losing access deletes nothing (`LapseDeletesNothingTest`) |
| P-10 Billing channels | PASS | No endpoint initiates or records a payment. `manual` is never a payment (ADR-0013) |
| P-11 Events | PASS | `SubscriptionActivated` and `SubscriptionLapsed` are dispatched after commit, with scalars and an `eventId` |
| P-12 Time | PASS | UTC instants. Resolution and lapse use the server clock and the stored instants (R-4, R-5) |
| P-13 Naming | PASS | No codename. Plan names are data |
| P-14 Localisation | PASS | Plan names are stored in `ar` and `en` and served in `Content-Language`. Prices are integers, formatted by the client with Western numerals |
| P-15 Observability | PASS | Nothing logged beyond ids. Audit details carry no amount |
| P-16 Quality gates | PASS | Each spec §4 invariant names its test. No new dependency |
| P-17 Scope guard | PASS | No refunds automation, no web checkout, no FX |
| P-18 Delivery surfaces | PASS | The three capabilities are `/api/v1` endpoints. Operator actions have no screen until B12 |

### Post-design re-check

Unchanged. All rows are PASS or N-A.
- ADR-0013 supersedes ADR-0007, and the constitution's P-09 and P-10 `ADRs` lines move to it (PATCH v1.3.2, no rule text change).
- The sign-in summary's shape change (`plan` string → `PlanRef`) is inside the unreleased v1.2.
- Removing B1's seam narrows registration's unit of work to user, key and profile, and spec 007 FR-003 is noted.

Complexity Tracking stays empty.

## Project Structure

### Documentation (this feature)

```text
specs/009-plans-entitlements/
├── spec.md
├── plan.md                 # this file
├── research.md             # R-1 … R-10
├── data-model.md
├── quickstart.md
├── contracts/billing-api.md
├── checklists/requirements.md
└── tasks.md                # /speckit-tasks
```

### Source Code (repository root)

```text
app/Modules/Billing/
├── Domain/
│   ├── PlanCode.php, Feature.php
│   ├── Plans/PlanRecord.php
│   ├── Subscriptions/{SubscriptionRecord,SubscriptionStatus,GoverningSubscription}.php
│   ├── Events/{SubscriptionActivated,SubscriptionLapsed}.php
│   ├── Refusals/{PlanNotGrantable,SubscriptionRevoked,InvalidReason,InvalidDays,SubscriptionNotFound}.php
│   └── Ports/{PlanRepository,SubscriptionRepository}.php
├── Application/
│   ├── BillingServiceProvider.php                 # binds the real services (scoped EntitlementService)
│   ├── Entitlements/{EntitlementService,Entitlements,EntitlementSummary}.php
│   ├── Plans/PlanCatalogue.php                    # active plans for GET /plans
│   └── Subscriptions/{MySubscription,OperatorSubscriptions,LapseSubscriptions}.php
├── Infrastructure/
│   ├── BillingPersistenceServiceProvider.php      # port bindings
│   ├── Models/{Plan,Subscription}.php
│   ├── Repositories/{EloquentPlanRepository,EloquentSubscriptionRepository}.php
│   └── Database/{Migrations/…_create_plans_table.php,…_create_subscriptions_table.php,Seeders/BillingSeeder.php,Factories/…}
└── Http/
    ├── BillingHttpServiceProvider.php             # routes, console command
    ├── routes.php                                 # /plans, /me/entitlements, /me/subscription
    ├── Controllers/{PlansController,MyEntitlementsController}.php
    ├── Resources/{PlanResource,EntitlementSummaryResource,MySubscriptionResource}.php
    └── Console/LapseSubscriptions.php             # billing:lapse-subscriptions, hourly

deleted: app/Modules/Billing/Application/SubscriptionService.php, Application/Provisional/
changed: Identity RegisterAccount (no enrolment), LoginController (summary via resource), SignInResult,
         Identity\Application\Audit\Details\EntitlementChangedDetail (new)
config/billing.php                                 # grace_days = 3
routes/console.php                                 # hourly lapse
tests/TestCase.php                                 # $seed = true
docs/adr/0013-entitlements-with-manual-channel.md, docs/adr/0007-… (status line), docs/adr/README.md
docs/openapi/paths/billing.yaml, openapi.yaml, paths/identity.yaml ($ref EntitlementSummary)
docs/glossary.md (Subscription channels, governing subscription), .specify/memory/constitution.md (v1.3.2)

tests/Unit/Billing/{GoverningSubscription,SubscriptionStatus,PlanCode,Feature}Test.php
tests/Feature/Billing/{CatalogueSeed,EntitlementResolution,Lapse,LapseDeletesNothing,OperatorActions}Test.php
tests/Contract/Billing/{Plans,Entitlements,Subscription}Test.php
```

**Structure Decision**: Billing uses all four layers, like Identity. The provisional `Application/Provisional` directory from B1 is deleted. `Http` gets its own provider for routes and the command, and Infrastructure gets its own for port bindings, following Identity's split.

## Complexity Tracking

No Constitution Check violations; nothing to justify.
