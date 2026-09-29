# Contract: Plans & Billing API

The design of `docs/openapi/paths/billing.yaml`, tag `Plans & Billing`, with its three `openapi.yaml` index entries. Every operation takes the shared client headers, sends `X-Request-Id` and `Content-Language`, and documents the shared refusals (`400`, `406`, `426`, `500`). Nothing here writes: the in-app surface stays restore-purchases and redeem-code only (P-10 rule 2).

## Schemas (defined once, in `billing.yaml`)

```yaml
PlanRef:              # how responses name a plan; the code is never exposed
  id: uuid
  name: string        # in the response's Content-Language

Plan:                 # a catalogue item
  id: uuid
  name: string
  period_type: enum [monthly, yearly, lifetime, custom]
  period_days: integer | null
  is_default: boolean
  features: object    # additionalProperties: boolean | integer | null
  prices: array of { channel: enum [google_play, instapay], product_id: string | null, amount_minor: integer >= 0, currency: ^[A-Z]{3}$ }

EntitlementSummary:   # moved here from identity.yaml; signIn $refs it
  plan: PlanRef
  features: object    # additionalProperties: boolean | integer | null

MySubscription:
  plan: PlanRef
  provider: enum [google_play, instapay, manual] | null      # null on the free plan
  status: enum [active, grace] | null
  current_period_start: date-time | null
  current_period_end: date-time | null                        # null also for lifetime
  grace_until: date-time | null
  auto_renew: boolean | null
```

## `GET /plans`: `listPlans` (public, `security: []`)

`x-filterable: []`, `x-sortable: []`.

| Status | When |
|---|---|
| `200` | `data: Plan[]` (active plans, by `sort_order`), `meta: {next_cursor: null, has_more: false, limit}` |

## `GET /me/entitlements`: `getMyEntitlements`

| Status | When |
|---|---|
| `200` | `data: EntitlementSummary` |
| `401` | `UNAUTHENTICATED` |

## `GET /me/subscription`: `getMySubscription`

| Status | When |
|---|---|
| `200` | `data: MySubscription`, with the governing subscription or the free plan with nulls |
| `401` | `UNAUTHENTICATED` |

## Change to spec 007's contract

In `signIn`'s response, `entitlements` becomes `$ref: './billing.yaml#/components/schemas/EntitlementSummary'`. `plan` changes from a label-key string to `PlanRef`. v1.2 is unreleased, so no version bump is needed.

## Application services (no HTTP in B3; B12's screens call them)

| Service | Method | Refusals |
|---|---|---|
| `EntitlementService` | `forUser(userId): Entitlements`, `summaryFor(userId)` | none (a missing default plan is a server fault) |
| `OperatorSubscriptions` | `grant(operatorId, userId, planId, ?days, reason)` | `PlanNotGrantable` (retired, default or unknown), `InvalidReason` (blank or over 500 characters), `InvalidDays` (< 1) |
| | `extend(operatorId, subscriptionId, days, reason)` | `SubscriptionRevoked`, `InvalidReason`, `InvalidDays`, not found |
| | `revoke(operatorId, subscriptionId, reason)` | `InvalidReason`, not found. Revoking an already revoked subscription is a no-op |
| `LapseSubscriptions` | `run(): {graced, expired}` | none |

## Events

- `SubscriptionActivated { event_id, occurred_at, user_id, subscription_id, plan_id }`: on a grant, or an extension that reactivates.
- `SubscriptionLapsed { event_id, occurred_at, user_id, subscription_id, status: grace|expired|revoked }`: on a lapse or revocation.
