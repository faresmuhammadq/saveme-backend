# ADR-0013: Entitlements with a manual channel, and Free as no subscription

- **Status**: Accepted
- **Date**: 2026-09-28
- **Principles**: P-09, P-10
- **Supersedes**: [ADR-0007](0007-entitlements-from-subscriptions.md)
- **Resolves**: —

## Context

[ADR-0007](0007-entitlements-from-subscriptions.md) made entitlements a function of provider-agnostic subscriptions. It allowed two channels, `google_play` and `instapay`, and said the free tier is "a plan row like any other". Specifying plans and entitlements (spec 009, B3) raised two forces it did not settle:

- **Access that is not a payment.** Operators must be able to grant a plan (a support fix, a goodwill month, a reviewer account), and redeem codes must grant one too. Neither is Google Play and neither is an InstaPay transfer. Recording them under either channel would mislabel a grant as a payment, and pollute the InstaPay review queue and any revenue figure built on it.
- **How the free tier is held.** The B3 brief wanted every person to hold a Free subscription row, created at registration. That makes the free tier a record to create, backfill and fall back to on every lapse, when it is really the answer when nothing paid applies.

A third gap appeared in the status machine: ADR-0007 let an `expired` subscription become `active` again, but not one still in `grace`. A store payment recovered during grace, or an operator extending a subscription in grace, would then have to wait for it to expire first, which is absurd.

The owner decided the first two on 2026-09-28 (spec 009, Q1 = A); the third follows from them. Everything else in ADR-0007 stands, and is restated below so this record can be read on its own.

## Decision

### Plans and subscriptions

1. `plans` MUST be data, stored as rows: `code` (a data key), the billing period, and the plan's features (capability flags and quotas) held inside the plan. A plan MUST NOT be represented as a class, a constant set or a branch in code.
2. `subscriptions` MUST record `user_id`, `plan_id`, `provider` ∈ {`google_play`, `instapay`, `manual`}, `provider_ref`, `status` ∈ {`active`, `grace`, `expired`, `revoked`}, the current billing period bounds (`current_period_start`, `current_period_end`) and `grace_until`. **`manual` records operator grants and redeem codes, and is never a payment.**
3. The Billing module MUST normalise every channel into that same subscription record: Google Play purchase verification and RTDN messages, approved InstaPay claims, and manual grants. No channel-specific subscription table and no channel-specific entitlement path may exist.
4. Subscription status MUST follow this machine:
   ```text
   active ─period end─▶ grace ─grace end─▶ expired
   active/grace ─refund/chargeback/admin─▶ revoked
   expired ─renewal/claim approved/extension─▶ active
   grace ─renewal/payment recovered/extension─▶ active
   ```
   `active` and `grace` grant the plan's features; `expired` and `revoked` do not. Transitions not drawn above MUST NOT be performed.

### Entitlement checks

5. Every capability check MUST go through `EntitlementService` (`forUser($userId)->allows(Feature::…)` or `->quota(Feature::…)`). No other component may read a plan's features or interpret a subscription.
6. `Feature` MUST be an enum of capabilities. Nothing anywhere (policy, controller, service, Blade view, job) may compare a plan code. The plan code MUST be referenced only inside Billing's entitlement resolution and the seeders that create plan rows.
7. `EntitlementService::forWallet($walletId)` MUST resolve the wallet **owner's** entitlements. A shared wallet is governed by its owner's plan, regardless of what the acting member is subscribed to.
8. Entitlement results MAY be cached for the duration of a single request. They MUST NOT be cached across requests, stored on the user record, or embedded in a credential.
9. The free tier MUST be a plan row like any other, limited **only** on sharing. Every other feature MUST be enabled on it.
10. On lapse, downgrade or revocation, no user data may be deleted, hidden or degraded. Plan-gated sharing actions (invite, add member) MUST be blocked; the effect on **existing** members is defined by the Sharing specification and MUST NOT be restated here.

### Billing channels (P-10)

11. Purchases inside the Android app MUST use Google Play Billing. The in-app API MUST expose only restore-purchases and redeem-code; it MUST NOT expose any endpoint that initiates or records a payment outside Play.
12. InstaPay claim submission MUST be web-only, authenticated from the landing-page flow, because Play policy forbids offering an alternative payment method inside the app.

### New in this record

13. **The free tier is the absence of a governing subscription.** A person with no `active` or `grace` subscription whose grace has not passed is answered by the default plan. No subscription row represents the free tier, and registration MUST NOT create one.
14. **The governing subscription** is, among the person's `active` and `grace` subscriptions whose grace end has not passed, the one whose billing period ends latest. A missing end (lifetime) beats any end, and ties go to the most recently created. Resolution MUST follow the stored dates, not only the stored status, so an unrun lapse never extends access.

## Alternatives Considered

### Free as a real subscription row with a `system` channel

Create a Free subscription for every person at registration, as the B3 brief proposed, and add both `system` and `manual` channels. **Rejected because** it turns "nothing paid applies" into data that must be created, backfilled for existing people, and fallen back to on every lapse. A person with no row would be an error state rather than the normal free state.

### Record grants under `instapay`

Keep ADR-0007's two channels and record operator grants and redeem codes as `instapay`. **Rejected because** it mislabels a gift as a payment. That corrupts the InstaPay review queue, revenue figures and fraud review, which is exactly the channel-specific confusion ADR-0007 set out to avoid.

## Consequences

### Positive

- The free tier needs no rows, no backfill and no fallback path. Lapsing to Free is simply no longer having a governing subscription.
- Grants and codes are visible as what they are, and never counted as money.
- The rule for which subscription governs is written down once, so overlapping Play and InstaPay periods give one deterministic answer.
- A subscription in grace returns to `active` when it is paid or extended, instead of having to expire first.

### Negative

- "Every person has exactly one effective subscription" is now true of the answer, not of the data. A report of "subscribers" must count governing paid subscriptions, not rows.
- A third channel adds one more path to test in the grant, extend and revoke flows.

### Follow-ups

- ADR-0007's follow-ups carry over unchanged: the arch gate on plan codes (in place since spec 005), the Sharing spec's lapse behaviour for existing members (B9), and RTDN handling (B10).
- Revisit if a channel appears that is neither a store, a transfer nor a grant.
