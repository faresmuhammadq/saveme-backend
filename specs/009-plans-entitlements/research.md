# Research: Plans & Entitlements

**Feature**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Date**: 2026-09-28

---

## R-1 The plan record: one table, features and prices inside

**Decision**: `plans` has these columns:

- `id` (UUIDv7) and `code` (unique, varchar 40);
- `name jsonb` (`{"ar": …, "en": …}`, both required);
- `period_type` (`monthly` | `yearly` | `lifetime` | `custom`) and `period_days` (only for `custom`);
- `is_default`, `status` (`active` | `retired`), `sort_order`;
- `features jsonb` (capability key → bool or int or null);
- `prices jsonb` (a list of `{channel, product_id, amount_minor, currency}`).

A partial unique index allows exactly one `is_default AND status = 'active'` row.

**Rationale**: ADR-0007 rule 1 and the glossary put features inside the plan. Prices are few per plan and are read with it, so a second table buys nothing. Money in `prices` is integer minor units plus a currency (P-03). The P-03 migration gate scans column types, and jsonb carries integers.

**Alternatives**:

- `plan_features` and `plan_prices` tables, as in the brief. Rejected: the glossary says "not its own table", and joins would buy nothing.
- A stored `display_price`. Rejected: that is presentation, and P-03 forbids decimal strings.

## R-2 `PlanCode` and `Feature`

**Decision**:

- **`Billing\Domain\PlanCode`**: a readonly value object (lower-case, `[a-z0-9_]{1,40}`). It is used only in Billing's Domain, `Application\Entitlements` and Infrastructure, which the existing `tests/Arch/EntitlementsTest.php` already enforces. The API never exposes the code. Clients identify a plan by `id` and read its `features`, so no client can gate on a code either.
- **`Billing\Domain\Feature`**: a string-backed enum with one case per capability:
  - `SharingEnabled` = `sharing.enabled` (bool);
  - `SharingMaxMembersPerWallet` = `sharing.max_members_per_wallet` (int);
  - `ExportEnabled` = `export.enabled` (bool);
  - `ReportsAdvanced` = `reports.advanced` (bool);
  - `WalletsMax` = `wallets.max` (int, or `null` meaning unlimited).

  Each case declares its kind: `isQuota()`, plus a `defaultIfDenied()` of false or 0. It lives in Domain, so any module's Application can name `Feature::SharingEnabled` without reaching into Billing's Infrastructure or Http (P-02).

## R-3 The entitlement service

**Decision**: `Billing\Application\Entitlements\EntitlementService` (the constitution's name) becomes a concrete service with:

- `forUser(string $userId): Entitlements`. `Entitlements` is an immutable object with `allows(Feature)`, `quota(Feature)` and `summary(): EntitlementSummary`.
- `summaryFor(string $userId): EntitlementSummary`, a shorthand kept for B1's sign-in.
- `forWallet(string $walletId)`, which is **not built here**. It needs Wallets' owner lookup (B4). B4 adds it with the rule already fixed (P-09 rule 4).

The service is bound `scoped`, so it is fresh for each request or job, and memoises per user within that scope (ADR-0007 rule 8). Nothing persists an answer.

`EntitlementSummary` becomes `{ plan: { id, name: {ar, en} }, features: { key: value } }`. The sign-in response and `GET /me/entitlements` share this schema. B1's `plan` member was a string label key, and it becomes this object. v1.2 is unreleased, so this changes an unpublished shape, and the Identity contract and its tests are updated in the same change.

**If there is no default plan** (a missing seed), the service throws, which is a `500`. A catalogue test guards the seed, so this is an operations fault rather than a user state.

## R-4 Choosing the governing subscription

**Decision**: `Billing\Domain\Subscriptions\GoverningSubscription::among(list<SubscriptionRecord>, DateTimeImmutable $now): ?SubscriptionRecord` is pure and unit-tested. It works in two steps.

- **Filter**: keep subscriptions whose status is `active` or `grace` and whose `grace_until` (or, for `active` with no grace, `current_period_end`) has not passed. Lifetime subscriptions (end null) always qualify.
- **Pick**: the latest `current_period_end`, where null counts as infinite. Ties go to the latest `created_at`, then the highest `id`, so the choice is deterministic.

Resolution filters by dates, never by status alone, so it does not depend on the lapse having run (FR-013).

## R-5 The lapse

**Decision**: `billing:lapse-subscriptions` is scheduled hourly (`routes/console.php`). It calls `Billing\Application\Subscriptions\LapseSubscriptions`, which:

1. moves `active` subscriptions with `current_period_end <= now` to `grace`. `grace_until` becomes `current_period_end + config('billing.grace_days')` (3) when the channel set none;
2. moves `grace` subscriptions with `grace_until <= now` to `expired`.

Each transition is a conditional update (`WHERE status = ?`), so concurrent runs and replays change nothing twice. After commit it publishes `SubscriptionLapsed(user_id, subscription_id, status)`, and writes one `entitlement_changed` entry (actor `system`) **only when the person's governing plan changes**, that is, at `expired` when no other subscription governs.

## R-6 Operator actions

**Decision**: `Billing\Application\Subscriptions\OperatorSubscriptions` provides:

- **`grant(string $operatorId, string $userId, string $planId, ?int $days, string $reason)`**. The plan must be active and not the default. `$days` null means for life. It creates a `manual` subscription running from now to now + days, with `provider_ref` = `op:{operatorId}` and `auto_renew` false.
- **`extend(string $operatorId, string $subscriptionId, int $days, string $reason)`**. It moves `current_period_end` (and `grace_until`) later by the days. An `expired` subscription becomes `active` (ADR-0007 rule 4), and a `revoked` one is refused.
- **`revoke(string $operatorId, string $subscriptionId, string $reason)`**, which is terminal.

The reason is trimmed, and must be 1–500 characters and not blank (the same rule as spec 006's reason prompt). Each action runs in one transaction and writes **exactly one** `entitlement_changed` entry through `Identity\Application\Audit\AuditLog`. That is the one audit log (baseline, Who owns the audit log), and a cross-module Application call is permitted by P-02.

The entry has `actor_type` `operator`, `subject_type` `subscription`, and a detail of `{action, plan_id, days?, reason}`. Identity owns the audit detail classes, so `EntitlementChangedDetail` is added under `Identity\Application\Audit\Details` in this change.

After commit, `SubscriptionActivated` or `SubscriptionLapsed(status: revoked)` is published. The refusals are `Billing\Domain\Refusals\*`, for a later HTTP surface in B12. B3 has no operator endpoint.

## R-7 Removing B1's enrolment seam

**Decision** (Q1 = A): delete `Billing\Application\SubscriptionService`, `Billing\Application\Provisional\ProvisionalFreePlan`, and the call in `Identity\Application\Registration\RegisterAccount`.

Registration's unit of work becomes the user, the data key and the profile. `tests/Feature/Identity/RegistrationTest.php`'s "one unit of work" case changes to fail on the profile insert instead of the Billing call. Spec 007's FR-003 gets a one-line note that B3 removed the subscription step.

`BillingServiceProvider` then binds only the real service (scoped).

## R-8 The launch catalogue as reference data

**Decision**: `Billing\Infrastructure\Database\Seeders\BillingSeeder` upserts the plans by `code`: `free`, `plus_monthly` and `plus_yearly`, with the Q2 values. It is idempotent and safe in production. *Superseded by spec 021 (R-6, FR-039): it is now create-missing-only, inserting an absent plan and adding an absent capability key, and never overwriting a stored value, so a back-office edit survives a deployment.* `database/seeders/DatabaseSeeder.php` already calls each module's seeder.

Before writing, it checks that **every plan declares every `Feature` case**, and throws naming the plan and the missing key otherwise (FR-004). Prices are:

- `plus_monthly`: `{channel: "instapay", amount_minor: 14900, currency: "EGP"}`;
- `plus_yearly`: `{channel: "instapay", amount_minor: 129000, currency: "EGP"}`.

`google_play` entries are added by B10 with product ids.

**Tests**: `tests/TestCase.php` sets `protected bool $seed = true` with `DatabaseSeeder`, so every Feature and Contract test runs against the real catalogue. This is cheap, three upserts once per migration. B1's sign-in tests then see the real free plan, not the stand-in.

## R-9 The `manual` channel: a superseding decision record

**Decision**: write `docs/adr/0013-entitlements-with-manual-channel.md`, which **supersedes ADR-0007**. It restates ADR-0007's decisions unchanged, except:

- **rule 2**: `provider` ∈ {`google_play`, `instapay`, `manual`}. `manual` covers operator grants and redeem codes, and is never a payment.
- **a new rule**: the free tier is the default plan applied when no subscription governs. No subscription row represents it.

ADR-0007's status line becomes "Superseded by ADR-0013", and the ADR index gains the row. The constitution's P-09 and P-10 `ADRs` lines point to ADR-0013. That is a PATCH to v1.3.2, since no rule text changes (governance: superseding updates the principle's ADR line in the same change). The glossary's Subscription entry names the three channels and "governing subscription".

## R-10 The API

**Decision**: `docs/openapi/paths/billing.yaml`, tag `Plans & Billing`, with three operations:

- **`GET /plans`** (`listPlans`, public, `security: []`). A collection in the standard envelope (`CollectionMeta`, `next_cursor` always null because the catalogue is small), with `x-filterable: []` and `x-sortable: []`. Items are `{id, name, period_type, period_days, is_default, features, prices}`. The name is localised by `Accept-Language` into a single string, and the resource carries `name` in the requested language only.
- **`GET /me/entitlements`** (`getMyEntitlements`), returning `EntitlementSummary`.
- **`GET /me/subscription`** (`getMySubscription`), returning `{plan, provider, status, current_period_start, current_period_end, grace_until, auto_renew}`. On the free plan, `provider`, `status`, the dates and `auto_renew` are null.

`EntitlementSummary` moves from `identity.yaml` into `billing.yaml` as the single definition, and `identity.yaml`'s sign-in response `$ref`s it.
