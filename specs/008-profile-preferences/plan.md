# Implementation Plan: Profile & Preferences

**Branch**: `008-profile-preferences` (trunk-based; work lands on `main`) | **Date**: 2026-09-27 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/008-profile-preferences/spec.md`, plus the owner's input at planning: "consider locking user for changing currency if already transaction recorded".

## Summary

The Profile that registration already creates becomes readable and writable:

- `GET /me` returns the account and the profile together.
- `PUT /me/profile` saves the whole profile under the syncable write rules. The checks run in order: shape, identical replay (a no-op `200`), `version` (`409`), then the currency lock.

Profile gains a default currency (EGP), a budget start day (1–28), and the date that start day applies from.

**Owner input, adopted:** the default currency locks once the person has recorded a live transaction. Changing it is then refused with the existing `IMMUTABLE_FIELD` code, and the profile reports `default_currency_locked` so the app can disable the picker. Transactions arrive in B6, so the lock reads from a provisional Wallets query service that always answers "no transactions", following the same pattern as spec 007's Billing seam.

A missing `Accept-Language` now falls back to the signed-in person's language.

**New pieces:** the shared kernel gains its first `Currency` value object. No new dependency and no new error code.

## Technical Context

**Language/Version**: PHP 8.5, Laravel 13.31.

**Primary Dependencies**: none new. Sanctum's `TokenAuthenticated` event supports the language fallback (R-5).

**Storage**: PostgreSQL 16. One migration adds three columns to `profiles` (R-6).

**Testing**: Pest 5.
- Unit: `BudgetStartDay` and `Currency`.
- Feature: update, replay, conflict, currency lock, nothing-rewritten, language fallback, and the display name at rest.
- Contract: `GET /me` and `PUT /me/profile` for every documented status.

**Target Platform**: the Linux server (the `dev` container). The client is the Android app.

**Project Type**: modular-monolith JSON API.

**Performance Goals**: `GET /me` p95 under 100 ms (one profile read, one address open, one lock query). A save p95 under 150 ms.

**Constraints**:
- P-07: version required, no server-side merge.
- P-12 rule 5: a timezone change rewrites nothing.
- The display name is encrypted at rest.
- P-02: the lock is read through the Wallets Application layer only.

**Scale/Scope**:
- 2 operations and 1 migration;
- about 10 new classes (Identity, Shared, Wallets seam);
- 7 test files.

## Constitution Check

*Constitution v1.3.1 (`.specify/memory/constitution.md`). GATE: must pass before Phase 0 research. Re-check after Phase 1 design, keeping both results.*

### Pre-design

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | Profile, Wallet and Budget Period as in the glossary. The field is named `budget_period_start_day`, avoiding the banned bare word "period". The Profile entry's Notes gain the new fields in this change |
| P-02 Module boundaries | PASS | Everything is in `app/Modules/Identity`. The lock goes through `Wallets\Application\TransactionQueryService` only (R-3). `Currency` in `Shared/Domain` is persistence-free. The language listener sits in Identity Http and uses Domain ports |
| P-03 Money | PASS | No amount is handled. `Currency` validates uppercase ISO 4217 alpha-3 codes and carries minor units for the later `Money` |
| P-04 Ledger integrity | N-A | No ledger |
| P-05 Derived balances | N-A | No balances |
| P-06 Identifiers | PASS | The profile id stays server-generated at registration. The singleton path `/me/profile` accepts no foreign identifier, so there is nothing to disclose |
| P-07 Sync and concurrency | PASS | The save carries `version`. A stale one gives `409` with `current`, an identical replay gives `200` with no bump, and there is no field merge. The save bumps `version` and `change_seq` through a conditional `WHERE version = ?` |
| P-08 Authorization | PASS | Owner only: the caller's own profile. No wallet scope |
| P-09 Entitlements | N-A | No plan-gated capability |
| P-10 Billing channels | N-A | None |
| P-11 Events | PASS | `ProfileUpdated` is dispatched after commit and carries field names only (R-7) |
| P-12 Time | PASS | The timezone is stored as an IANA name and applied only in presentation. `budget_period_start_day_from` is a calendar date computed in the profile's timezone (R-4). The nothing-rewritten test covers rule 5 |
| P-13 Naming | PASS | No codename |
| P-14 Localisation | PASS | Language `ar`/`en`. The new `IMMUTABLE_FIELD` detail string exists in both. A missing `Accept-Language` falls back to the profile's language (R-5) |
| P-15 Observability | PASS | The display name never goes into the event or logs. The existing redactor is untouched |
| P-16 Quality gates | PASS | Each invariant in spec §4 names its test. No new dependency |
| P-17 Scope guard | PASS | No multi-currency transfers or FX: the currency is only a default and a lock |
| P-18 Delivery surfaces | PASS | Both capabilities are `/api/v1` endpoints. No back-office screen |

### Post-design re-check

Unchanged. All rows are PASS or N-A.
- The Wallets seam adds one interface, one provisional class and one provider under `Wallets/Application`. The existing P-02 gates cover it.
- The api-conventions Localisation change is additive and stays within v1.2.

Complexity Tracking stays empty.

## Project Structure

### Documentation (this feature)

```text
specs/008-profile-preferences/
├── spec.md
├── plan.md                 # this file
├── research.md             # R-1 … R-8
├── data-model.md
├── quickstart.md
├── contracts/profile-api.md
├── checklists/requirements.md
└── tasks.md                # /speckit-tasks
```

### Source Code (repository root)

```text
app/Modules/Shared/Domain/Money/Currency.php                     # active ISO 4217 codes + minor units (R-2)

app/Modules/Wallets/Application/                                 # provisional seam, replaced by B6 (R-3)
├── TransactionQueryService.php
├── Provisional/NoTransactionsYet.php
└── WalletsServiceProvider.php

app/Modules/Identity/
├── Domain/
│   ├── Preferences/{ProfileRecord,BudgetStartDay}.php
│   ├── Events/ProfileUpdated.php
│   └── Ports/ProfileRepository.php                             # + find(), save()
├── Application/
│   ├── Profiles/{ReadMe,SaveProfile}.php                       # R-1 order, lock, start-day date
│   └── Dto/ProfileView.php
├── Infrastructure/
│   ├── Repositories/EloquentProfileRepository.php              # conditional update on version
│   └── Database/Migrations/2026_09_27_000001_add_preferences_to_profiles.php
└── Http/
    ├── Controllers/ProfileController.php                       # show (GET /me), update (PUT /me/profile)
    ├── Requests/SaveProfileRequest.php
    ├── Resources/ProfileResource.php
    ├── Listeners/ApplyProfileLanguage.php                      # TokenAuthenticated → profile locale (R-5)
    └── routes.php                                              # + /me, /me/profile

lang/{en,ar}/problems.php                                       # + the IMMUTABLE_FIELD detail for currency
bootstrap/providers.php                                         # + WalletsServiceProvider
docs/openapi/paths/identity.yaml, docs/openapi/openapi.yaml     # getMe, saveProfile, Profile/ProfileWrite/Me
docs/api-conventions.md                                         # Localisation fallback
docs/glossary.md                                                # Profile notes

tests/Unit/Identity/BudgetStartDayTest.php
tests/Unit/Shared/CurrencyTest.php
tests/Contract/Identity/ProfileTest.php
tests/Feature/Identity/{ProfileUpdate,ProfilePreferencesDoNotRewrite,ProfileLanguage,DefaultCurrencyLock}Test.php
tests/Feature/Identity/{AddressAtRest,VerificationGate}Test.php  # extended
```

**Structure Decision**: this is Identity work in the existing four layers, plus two small seams. The first is `Shared/Domain/Money/Currency`, which starts follow-up F-4. The second is `Wallets/Application`, a provisional query service that B6 replaces, mirroring spec 007's Billing seam.

## Complexity Tracking

No Constitution Check violations; nothing to justify.

The owner's currency lock was adopted with the existing `IMMUTABLE_FIELD` code rather than a new one (R-3). It is enforced from the first commit and becomes observable once B6 records transactions.
