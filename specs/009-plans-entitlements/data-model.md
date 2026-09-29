# Data Model: Plans & Entitlements

**Feature**: [spec.md](./spec.md) · **Research**: [research.md](./research.md)

Owned by `app/Modules/Billing`. Migrations live in `app/Modules/Billing/Infrastructure/Database/Migrations`. PostgreSQL 16, UUIDv7 keys (P-06), UTC `timestamptz` (P-12).

## `plans`: Plan (reference data, not synced)

| Column | Type | Rules |
|---|---|---|
| `id` | uuid PK | |
| `code` | varchar(40) UNIQUE | `PlanCode`, matching `^[a-z0-9_]{1,40}$`. Never exposed by the API |
| `name` | jsonb | `{"ar": string, "en": string}`, both non-empty |
| `period_type` | varchar(10) + CHECK | `monthly` \| `yearly` \| `lifetime` \| `custom` |
| `period_days` | integer null | required and ≥ 1 exactly when `period_type = 'custom'` (CHECK) |
| `is_default` | boolean | the free plan |
| `status` | varchar(10) + CHECK | `active` \| `retired` |
| `sort_order` | integer | catalogue order |
| `features` | jsonb | every `Feature` key → bool, int, or null (unlimited) |
| `prices` | jsonb | a list of `{channel: "google_play"\|"instapay", product_id: string\|null, amount_minor: int ≥ 0, currency: ISO 4217}` |
| `created_at`, `updated_at` | timestamptz | |

Index: `CREATE UNIQUE INDEX plans_one_active_default ON plans ((true)) WHERE is_default AND status = 'active'`.

**Lifecycle**: `active` → `retired`.

### Launch rows (`BillingSeeder`, keyed on `code`)

| code | name (en / ar) | period | default | sharing.enabled | sharing.max_members_per_wallet | export.enabled | reports.advanced | wallets.max | prices |
|---|---|---|---|---|---|---|---|---|---|
| `free` | Free / مجاني | lifetime | yes | false | 1 | true | true | null | none |
| `plus_monthly` | Plus · Monthly / بلس · شهري | monthly | no | true | 5 | true | true | null | instapay 14900 EGP |
| `plus_yearly` | Plus · Yearly / بلس · سنوي | yearly | no | true | 5 | true | true | null | instapay 129000 EGP |

The free plan's `period_type` is `lifetime` because it never ends. It is never subscribed to, only applied when nothing governs.

## `subscriptions`: Subscription (not synced)

| Column | Type | Rules |
|---|---|---|
| `id` | uuid PK | |
| `user_id` | uuid | the scope key. No FK across modules. Identity's purge (B15) removes a person's subscriptions through Billing |
| `plan_id` | uuid FK → plans | |
| `provider` | varchar(12) + CHECK | `google_play` \| `instapay` \| `manual` (ADR-0013) |
| `provider_ref` | varchar(255) | the channel's reference (a purchase token hash, a claim id, `op:{operator id}`, `code:{code id}`) |
| `status` | varchar(10) + CHECK | `active` \| `grace` \| `expired` \| `revoked` |
| `current_period_start` | timestamptz | |
| `current_period_end` | timestamptz null | null = lifetime |
| `grace_until` | timestamptz null | set when entering grace, or by the channel |
| `auto_renew` | boolean | |
| `source_meta` | jsonb | scalars only (for example `{"reason_ref": …}`), never payment payloads |
| `created_at`, `updated_at` | timestamptz | |

Indexes: `(user_id, status)`; `(status, current_period_end)` and `(status, grace_until)` for the lapse. A unique index on `(provider, provider_ref)` stops one channel reference from creating two subscriptions.

**State transitions** (ADR-0007 rule 4, restated in ADR-0013):

```text
active ──period end (lapse)──▶ grace ──grace end (lapse)──▶ expired
active | grace ──revoke──▶ revoked            (terminal)
expired | grace ──extend | renewal | recovered payment | approved claim──▶ active
```

## Domain types (Billing)

- `Domain\PlanCode`: a value object.
- `Domain\Feature`: an enum (see research R-2).
- `Domain\Subscriptions\SubscriptionStatus`: an enum with `canBecome()`.
- `Domain\Subscriptions\SubscriptionRecord`: a readonly record of the columns above.
- `Domain\Subscriptions\GoverningSubscription::among()`: pure, see R-4.
- `Domain\Plans\PlanRecord`: `id`, `code`, `name`, `periodType`, `periodDays`, `isDefault`, `status`, `sortOrder`, `features`, `prices`.
- `Domain\Events\SubscriptionActivated` and `Domain\Events\SubscriptionLapsed`, both `ShouldDispatchAfterCommit`, carrying scalars and an `eventId`.
- `Domain\Ports\PlanRepository` and `Domain\Ports\SubscriptionRepository`.

## Derived, never stored

**Entitlement**: `Application\Entitlements\Entitlements`. It holds the governing plan's features, with `allows()`, `quota()` and `summary()`, and is scoped to one request.

## Audit

`entitlement_changed` (baseline vocabulary, written by Billing through Identity's `AuditLog`):

- **Operator actions**: actor `operator`, subject `subscription`, detail `{action: grant|extend|revoke, plan_id, days?, reason}`.
- **A lapse that changes the governing plan**: actor `system`, subject `subscription`, detail `{action: expire, plan_id}`.

No amount is ever recorded.
