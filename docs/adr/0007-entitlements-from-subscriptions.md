# ADR-0007: Entitlements from provider-agnostic subscriptions

- **Status**: Accepted
- **Date**: 2026-09-19
- **Principles**: P-09, P-10
- **Supersedes**: —
- **Resolves**: —

## Context

The application sells a paid tier through two channels that have nothing in common technically. Google Play Billing runs inside the Android app and reports through purchase verification and Real-Time Developer Notifications. InstaPay is a manual bank transfer: the user pays, submits a claim with a reference, and someone approves it. The two produce different identifiers, different lifecycles and different notions of "renewed".

Above them sits a single question asked all over the codebase: may this user do this? If that question is answered by comparing a plan code, every price change, every new tier and every promotional plan becomes a code change scattered across call sites, and the answer differs depending on which call site is read.

Wallets are shared, and a shared wallet has members on different plans. Something has to decide whose plan governs the wallet.

Subscriptions also end — a period lapses, a card fails, a refund is issued, a user downgrades. This is financial data the user entered by hand over months. Losing it because a payment lapsed would be indefensible.

Finally, Google Play policy forbids offering an alternative payment method for digital goods inside the app, which constrains where the InstaPay flow may be exposed.

## Decision

### Plans and subscriptions

1. `plans` MUST be data, stored as rows: `code` (a data key), `period`, and `features jsonb` holding capability flags and quotas. A plan MUST NOT be represented as a class, a constant set or a branch in code.
2. `subscriptions` MUST record `user_id`, `plan_id`, `provider` ∈ {`google_play`, `instapay`}, `provider_ref`, `status` ∈ {`active`, `grace`, `expired`, `revoked`}, the current period bounds (`current_period_start`, `current_period_end`) and `grace_until`.
3. The Billing module MUST normalise both channels into that same subscription record: Google Play purchase verification and RTDN messages on one side, approved InstaPay claims on the other. No provider-specific subscription table and no provider-specific entitlement path may exist.
4. Subscription status MUST follow this machine:

   ```text
   active ─period end─▶ grace ─grace end─▶ expired
   active/grace ─refund/chargeback/admin─▶ revoked
   expired ─renewal/claim approved─▶ active
   ```

   `active` and `grace` grant the plan's features; `expired` and `revoked` do not. Transitions not drawn above MUST NOT be performed.

### Entitlement checks

5. Every capability check MUST go through `EntitlementService::forUser($userId)->allows(Feature::…)` or `->quota(Feature::…)`. No other component may read `plans.features` or interpret a subscription.
6. `Feature` MUST be an enum of capabilities. Nothing anywhere — policy, controller, service, Blade view, job — may compare a plan code. `plans.code` MUST be referenced only inside `EntitlementService` and the seeders that create plan rows.
7. `EntitlementService::forWallet($walletId)` MUST resolve the wallet **owner's** entitlements. A shared wallet is governed by its owner's plan, regardless of what the acting member is subscribed to.
8. Entitlement results MAY be cached for the duration of a single request. They MUST NOT be cached across requests, stored on the user record, or embedded in a token.
9. The free tier MUST be a plan row like any other, limited **only** on sharing. Every other feature MUST be enabled on it.
10. On lapse, downgrade or revocation, no user data may be deleted, hidden or degraded. Plan-gated sharing actions (invite, add member) MUST be blocked; the effect on **existing** members is defined by the Sharing specification and MUST NOT be restated here.

### Billing channels (P-10)

11. Purchases inside the Android app MUST use Google Play Billing. The in-app API MUST expose only restore-purchases and redeem-code; it MUST NOT expose any endpoint that initiates or records a payment outside Play.
12. InstaPay claim submission MUST be web-only, authenticated from the landing-page flow, because Play policy forbids offering an alternative payment method inside the app.

## Alternatives Considered

### Comparing plan codes at call sites

The direct approach: `if ($user->plan->code === 'pro')` wherever a capability is gated. **Rejected because** it spreads the plan catalogue across the codebase. Adding a tier, renaming a plan, running a promotional plan or splitting a feature out means finding every comparison, and any one that is missed produces a wrong answer silently. It also makes the free tier's rule ("limited only on sharing") unverifiable, since there is no single place where it is expressed.

### A Cashier-style billable per provider

Model each provider with its own billable trait and subscription relation, in the style of Laravel Cashier. **Rejected because** it is provider-shaped, and one of the two providers is a human approving a bank transfer. InstaPay has no webhook contract, no provider-side subscription object and no renewal event to subscribe to, so it would need a parallel implementation anyway — leaving two entitlement paths that can disagree about the same user.

### Feature flags stored on the user

Write the granted capabilities onto the user row when a purchase is verified, and read them from there. **Rejected because** it discards why and until when the capability was granted. There is no period, no provider, no `provider_ref` to reconcile against Play, no history to audit a disputed charge, and expiry becomes a scheduled job that can silently fail and leave a user entitled forever.

## Consequences

### Positive

- Adding a plan, changing a price or running a promotion is a data change, not a deployment.
- The two payment channels converge on one record, so every consumer of entitlements is written once and works for both.
- `plans.code` appearing outside `EntitlementService` is mechanically detectable, which makes P-09 enforceable rather than aspirational.
- A shared wallet has one unambiguous governing plan, so members never see the wallet behave differently depending on who is looking.
- A lapsed subscription is a purely functional restriction; the user's data survives untouched and returns in full on renewal.
- Restricting the request-scoped cache to a single request means a purchase or revocation takes effect on the next request with no cache invalidation to get wrong.

### Negative

- Every capability check pays an indirection through `EntitlementService`, which is slower and less obvious to read than a boolean on the user.
- `features jsonb` is schemaless, so a typo in a feature key fails as "not entitled" rather than as an error; the `Feature` enum bounds the read side but not what a seeder writes.
- Tying wallet entitlements to the owner means a paying member cannot lift a wallet owned by a free-tier user, which will need explaining in the product.
- Two submission paths for the same subscription (in-app Play, web InstaPay) means two sets of integration tests and two fraud surfaces.
- The web-only InstaPay flow adds friction: an app user must leave the app to pay that way, and the app may not tell them so in terms Play would read as steering.

### Follow-ups

- **F-1** — Pest `arch()` test banning plan-code comparison: only `Billing\Application\EntitlementService` may reference `Plan::code`, plus a grep gate for plan code literals outside `database/seeders`. Without it, rule 6 is prose only.
- Define the lapse behaviour for existing members of a shared wallet in the Sharing specification; this ADR deliberately leaves it open.
- Decide how RTDN messages are handled — synchronously or via a queued listener — against the no-outbox risk accepted in [ADR-0009](0009-domain-events-after-commit.md); a lost entitlement grant is exactly the revisit trigger named there.
- Revisit if a third provider appears, if entitlements ever need to outlive a request in cache, or if a feature needs per-wallet rather than per-owner entitlement.
