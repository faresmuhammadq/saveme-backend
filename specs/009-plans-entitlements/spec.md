# Feature Specification: Plans & Entitlements

**Feature Branch**: `009-plans-entitlements`

**Created**: 2026-09-28

**Status**: Draft

**Input**: Brief B3 ([specs/briefs/B03-plans-entitlements.md](../briefs/B03-plans-entitlements.md)): "A data-driven plan catalog and the service that turns subscriptions into effective capabilities." Plus the brief's considerations and its reconcile table.

> **Nature of this feature.** B3 builds the question every later feature asks: *may this person do this right now?* It stores the plan catalogue as data, records subscriptions from any channel in one shape, and resolves the two into an answer. It sells nothing: purchases arrive with Google Play (B10), InstaPay claims (B11) and redeem codes, and the operator screens arrive with the back office (B12). It replaces the provisional Free-plan stand-in that B1 left in the Billing module.
>
> **Where the brief and the ratified decisions differ, the ratified side wins** ([ADR-0007](../../docs/adr/0007-entitlements-from-subscriptions.md), the glossary, constitution P-09):
>
> | Brief | In force | Here |
> |---|---|---|
> | `plan_features` table | A plan's features live **inside** the plan (glossary: Plan Feature, "not its own table"; ADR-0007 rule 1) | Features are part of the plan record |
> | Statuses trialing/active/in_grace/on_hold/paused/canceled/expired | `active`, `grace`, `expired`, `revoked` (ADR-0007 rule 4) | The four ADR states. Store-specific states map onto them in B10 |
> | Entitlements cached per user, invalidated on change | Cached for **one request at most** (ADR-0007 rule 8) | Request-scoped only; a change takes effect on the next request |
> | "Existing members become read-only" on downgrade | Defined by the Sharing spec, **not here** (ADR-0007 rule 10) | Deferred to B9; the brief's proposal is recorded as B9's input |
> | `display_price` | Money is integer minor units plus currency (P-03) | Prices are money; display is formatted per language, never stored |
> | "Nothing compares plan.code" | The plan code is the type `Billing\Domain\PlanCode`, usable only inside Billing's entitlement resolution (P-09 architecture gate) | Modelled as that type |

## Clarifications

### Session 2026-09-28

- Q: How are the free plan and non-store grants represented? → A: **Free means no subscription.** A person with no governing subscription is answered by the default plan, so nobody holds a "free" row and lapsing falls back to Free naturally. Operator grants and redeem codes record the channel **`manual`**. A new decision record supersedes ADR-0007 rule 2 to add `manual` to the channels. B1's registration step "enrol in the default plan" becomes unnecessary and is removed.
- Q: Which plans and prices launch? → A: **Free, Plus monthly (EGP 149) and Plus yearly (EGP 1,290). No lifetime plan.** Plus enables sharing with up to 5 members per wallet. The lifetime billing period stays supported (an operator may grant for life, and a lifetime plan can be added later as data).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - The app knows what the person may do (Priority: P1)

After sign-in, and whenever it refreshes, the app asks what the person is entitled to: which plan they are on, whether sharing is enabled, how many members a wallet of theirs may have. It shows or hides the matching controls from that answer, never from the plan's name.

**Why this priority**: every plan-gated feature (sharing first) depends on this answer, and the app needs it on its first screen.

**Independent Test**: register, sign in, and read the entitlements. A new person has the free plan's answer: sharing off, everything else on.

**Acceptance Scenarios**:

1. **Given** a person with no paid access, **When** the app reads their entitlements, **Then** it gets the free plan: sharing off, and every other capability on (P-09 rule 5).
2. **Given** a person whose paid subscription is active or in its grace period, **When** the app reads their entitlements, **Then** it gets that plan's capabilities and quotas.
3. **Given** a person whose paid subscription expired or was revoked, **When** the app reads their entitlements, **Then** it gets the free plan again, and **none of their data is deleted, hidden or degraded** (P-09 rule 6).
4. **Given** a person holding two paid subscriptions at once (for example Google Play and InstaPay overlapping), **When** entitlements are resolved, **Then** the one whose current billing period ends latest governs, and a subscription with no end governs over any that ends.
5. **Given** the sign-in response (B1), **When** a person signs in, **Then** it reports the same answer the entitlements read gives.

---

### User Story 2 - Browse the plans (Priority: P1)

Before or after signing up, the person opens the plans screen and sees each plan on offer: its name in their language, what it includes, its billing period and its list price.

**Why this priority**: it is the catalogue the upgrade screen, the website and the operators all read from.

**Independent Test**: read the catalogue without signing in, in Arabic and in English, and check it lists exactly the active plans in their declared order with localised names.

**Acceptance Scenarios**:

1. **Given** the catalogue, **When** anyone reads it (signed in or not), **Then** they see every active plan, in its declared order, with a name in the requested language, its billing period, its capabilities and its list prices per channel.
2. **Given** a retired plan, **When** the catalogue is read, **Then** it is not listed, yet people already subscribed to it keep its capabilities until their subscription ends.
3. **Given** prices, **When** they are shown, **Then** each is an integer amount of minor units plus a currency, formatted by the client in the reader's language with Western numerals. No pre-formatted price string is stored or sent.

---

### User Story 3 - See my subscription (Priority: P2)

The person opens "my plan" and sees which plan they are on, through which channel, until when, and whether it renews.

**Why this priority**: it answers "why can't I share?" and "when does my plan end?", but no capability depends on it.

**Independent Test**: give a person a subscription and read it back with its plan, channel, status and dates; read it for a person with none and get the free plan with no dates.

**Acceptance Scenarios**:

1. **Given** a person with a governing paid subscription, **When** they read their subscription, **Then** they see the plan, the channel, the status (`active` or `grace`), the current billing period's start and end, the grace end if any, and whether it renews.
2. **Given** a person with no paid access, **When** they read their subscription, **Then** they see the free plan with no channel and no dates.

---

### User Story 4 - Operators grant, extend and revoke (Priority: P2)

An operator gives a person a plan for a period (a goodwill grant, a support fix, a reviewer account), extends a subscription, or revokes one. Every such change is recorded with the operator's reason. The screens for this come with the back office (B12); this feature provides the actions they call.

**Why this priority**: support and review need it before paid channels exist, and B11 and B12 build on it.

**Independent Test**: call each action through the Billing application service with an operator identity and a reason; check the subscription, the resulting entitlements, and one `entitlement_changed` audit entry per action.

**Acceptance Scenarios**:

1. **Given** an operator grants a plan to a person for a number of days, **When** the grant is recorded, **Then** the person has an active subscription through the grant channel for that period, and their entitlements change on their next request.
2. **Given** an operator extends a subscription by a number of days, **When** it is recorded, **Then** its current billing period ends that much later and, if it was in grace or expired, it is active again (ADR-0013 rule 4).
3. **Given** an operator revokes a subscription, **When** it is recorded, **Then** it is `revoked`, it no longer grants anything, and it can never become active again.
4. **Given** any of these, **When** it completes, **Then** exactly one `entitlement_changed` audit entry names the operator, the person, and the reason; it carries no amount.

---

### User Story 5 - Subscriptions lapse on their own (Priority: P2)

A subscription whose billing period ends moves into grace, and at the end of grace expires, without anyone acting. The person's capabilities follow on their next request.

**Why this priority**: without it a lapsed subscription grants forever. It needs no user action, so it follows the stories that create subscriptions.

**Independent Test**: create a subscription ending yesterday and one whose grace ended yesterday; run the lapse; check their statuses and the person's entitlements.

**Acceptance Scenarios**:

1. **Given** an active subscription whose billing period has ended, **When** the lapse runs, **Then** it moves to `grace` until its grace end, and still grants its plan.
2. **Given** a subscription in grace whose grace has ended, **When** the lapse runs, **Then** it moves to `expired` and grants nothing.
3. **Given** a subscription with no billing-period end (a lifetime plan), **When** the lapse runs, **Then** it never lapses.
4. **Given** the lapse has not run yet, **When** entitlements are resolved, **Then** they already follow the dates: a subscription past its grace end grants nothing even before its status is updated.

---

### Edge Cases

- **No subscription at all.** The default (free) plan answers; no row is needed (clarified 2026-09-28).
- **A retired plan with active subscribers.** Keeps granting to them; is hidden from the catalogue; cannot be granted anew.
- **Two subscriptions ending at the same instant.** The later-created one governs, so the answer is deterministic.
- **A feature the plan does not mention.** A plan that omits a capability grants it as "off" (or a quota of 0), and the omission is refused when the catalogue is seeded, so it cannot happen silently.
- **A shared wallet.** Its owner's plan governs it (P-09 rule 4). Resolving by wallet needs to know the owner, which Wallets (B4) provides; B3 fixes the rule, B4 wires it.
- **Extending a revoked subscription.** Refused: `revoked` is terminal (ADR-0007 rule 4).
- **Granting while a paid subscription exists.** Allowed; the overlap rule (US1 scenario 4) decides which governs.
- **Clock skew at the period end.** Resolution uses the server's clock and the stored end instant, never the device's.

## Requirements *(mandatory)*

### Functional Requirements

**Catalogue**

- **FR-001**: Plans MUST be stored as data (P-09 rule 1): a stable code, a name in Arabic and English, a billing period (monthly, yearly, lifetime, or a custom number of days), whether it is the default plan, whether it is active, a display order, and its capabilities and quotas as part of the plan.
- **FR-002**: Exactly one active plan MUST be the default (free) plan. It MUST be limited **only** on sharing: every other capability on, with unlimited or the highest quotas (P-09 rule 5).
- **FR-003**: Each plan MAY carry list prices per channel, each an integer amount of minor units plus an ISO 4217 currency (P-03), and, for Google Play, the store product identifier. Prices are never stored as formatted text.
- **FR-004**: The catalogue MUST be seeded as reference data, idempotently and safely in production. A seed that leaves a capability undeclared on any plan MUST fail. The launch catalogue (clarified 2026-09-28) is:

  | Plan | Billing period | Sharing | Members per wallet | List price |
  |---|---|---|---|---|
  | Free (default) | none | off | 1 (the owner) | none |
  | Plus · Monthly | monthly | on | 5 | EGP 149.00 |
  | Plus · Yearly | yearly | on | 5 | EGP 1,290.00 |

  Every other capability is on for all three. Google Play product identifiers are filled in by B10.
- **FR-005**: A plan MUST move `active` → `retired` only; a retired plan keeps serving its existing subscriptions and cannot be granted anew.

**Capabilities**

- **FR-006**: Capabilities MUST be named by a fixed list, not by free text. MVP: sharing enabled (yes/no) and members per wallet (a number). Reserved for later, declared now and enabled on every plan: data export, advanced reports, and number of wallets (unlimited).
- **FR-007**: Every capability decision in any module MUST go through the Billing entitlement service (P-09 rule 2). No code outside Billing's entitlement resolution may read a plan's code or its capability data (P-09 rule 3, enforced by the existing architecture gate).
- **FR-008**: The entitlement service MUST answer, for a person: whether a capability is allowed, a quota's value, and a summary (plan name and every capability). A shared wallet MUST be answered with its **owner's** entitlements (P-09 rule 4); that lookup is wired in B4.
- **FR-009**: Answers MAY be cached within one request and MUST NOT be cached across requests, stored on the person, or carried in a credential (ADR-0007 rule 8).

**Subscriptions**

- **FR-010**: A subscription MUST record the person, the plan, the channel, the channel's reference, the status (`active`, `grace`, `expired`, `revoked`), the current billing period's start and end (end empty for lifetime), the grace end, and whether it renews.
- **FR-011**: Status MUST follow only: `active` → `grace` at the period end; `grace` → `expired` at the grace end; `active`/`grace` → `revoked`; `expired` or `grace` → `active` on renewal, recovered payment, extension or an approved claim. Nothing leaves `revoked` (ADR-0013 rule 4, which adds the `grace` → `active` edge ADR-0007 lacked).
- **FR-012**: The **governing** subscription MUST be, among the person's `active` and `grace` subscriptions whose grace end has not passed, the one whose billing period ends latest (no end beats any end; ties go to the most recently created). With none, the default plan governs.
- **FR-013**: A scheduled lapse MUST move subscriptions past their period end to `grace` and past their grace end to `expired`. Resolution MUST NOT depend on the lapse having run (US5 scenario 4).
- **FR-014**: Losing access MUST NOT delete, hide or degrade any data (P-09 rule 6). The effect on existing members of a shared wallet is B9's to define (ADR-0007 rule 10).

**Reading, for the app and the website**

- **FR-015**: Anyone MUST be able to read the catalogue of active plans, in the requested language, without signing in.
- **FR-016**: A signed-in person MUST be able to read their entitlement summary and their governing subscription.
- **FR-017**: The sign-in response (B1) MUST report the same summary, from the same service, replacing B1's provisional stand-in. Registration MUST NOT create a subscription: B1's "enrol in the default plan" step is removed, since having no subscription already means the free plan (clarified 2026-09-28).

**Operator actions (called by the back office, B12)**

- **FR-018**: The Billing module MUST offer grant (a plan, for a number of days or for life), extend (by a number of days) and revoke, each taking the operator's identity and a non-blank reason.
- **FR-019**: Each operator action and each change of a person's governing plan MUST record exactly one `entitlement_changed` audit entry, naming actor and subject by identifier and carrying no amount (security baseline, What is recorded).
- **FR-020**: This feature MUST NOT expose any endpoint that starts or records a payment (P-10 rule 2).

### Key Entities

- **Plan** (glossary): code, localised name, billing period, default flag, active/retired, order, capabilities and quotas, list prices per channel.
- **Plan Feature** (glossary): one capability inside a plan, named by the fixed capability list.
- **Subscription** (glossary): one person's record of access to a plan through a channel, with its billing period and status.
- **Entitlement** (glossary): derived, never stored.

## Project Sections *(mandatory for feature specs)*

### 1. Purpose

This feature makes "what may this person do" a single answer, computed from data. It holds the plan catalogue, records every subscription in one shape whatever channel it came from, lapses them on their dates, and answers capability questions for every other module, so a price change or a new plan is a data change rather than a release, and a lapsed payment restricts sharing without ever touching a person's records.

### 2. Glossary terms

- [Plan](../../docs/glossary.md#plan), [Plan Feature](../../docs/glossary.md#plan-feature), [Subscription](../../docs/glossary.md#subscription), [Entitlement](../../docs/glossary.md#entitlement): as defined.
- **Billing period**: the subscription's `current_period_start`/`current_period_end`, never just "period" (glossary).
- **Governing subscription**: the one subscription whose plan answers for a person (FR-012). Added to the Subscription entry's notes in this change.
- **Channel**: the Subscription's `provider`: `google_play`, `instapay`, or `manual` (operator grants and redeem codes; added by a decision record superseding ADR-0007 rule 2 in this change).

### 3. Data model

Owned by `app/Modules/Billing`.

| Entity | Key fields | Scope | Lifecycle |
|---|---|---|---|
| Plan | `id` (UUIDv7), `code` (unique, a `PlanCode`), `name` (`ar`, `en`), `period_type` (`monthly` \| `yearly` \| `lifetime` \| `custom`), `period_days` (for `custom`), `is_default`, `status`, `sort_order`, `features` (capability → bool or int), `prices` (list of channel, store product id, amount in minor units, currency) | global reference data | `active` → `retired` |
| Subscription | `id`, `user_id`, `plan_id`, `provider` (`google_play` \| `instapay` \| `manual`), `provider_ref`, `status`, `current_period_start`, `current_period_end` (null = lifetime), `grace_until`, `auto_renew`, `source_meta` (scalars only) | `user_id` | per FR-011 |

Neither is syncable (glossary): the client reads them through endpoints.

### 4. Invariants

| Invariant | Principle or decision | Test that fails when broken |
|---|---|---|
| No code outside Billing's entitlement resolution uses `PlanCode` | P-09 rule 3 | `tests/Arch/EntitlementsTest.php` (existing) |
| Registration creates no subscription, and a person without one gets the free plan | Clarified 2026-09-28; FR-012, FR-017 | `tests/Feature/Billing/EntitlementResolutionTest.php` |
| Exactly one active default plan, limited only on sharing | P-09 rule 5, FR-002 | `tests/Feature/Billing/CatalogueSeedTest.php` |
| A plan missing a declared capability fails the seed | FR-004 | `tests/Feature/Billing/CatalogueSeedTest.php` |
| The governing subscription is the latest-ending active/grace one; none → default plan | FR-012 | `tests/Unit/Billing/GoverningSubscriptionTest.php` |
| Status moves only along the ADR machine; `revoked` is terminal | ADR-0007 rule 4, FR-011 | `tests/Unit/Billing/SubscriptionStatusTest.php` |
| A subscription past its grace end grants nothing even before the lapse runs | FR-013 | `tests/Feature/Billing/EntitlementResolutionTest.php` |
| Losing access deletes nothing | P-09 rule 6 | `tests/Feature/Billing/LapseDeletesNothingTest.php` |
| Entitlements are not cached across requests | ADR-0007 rule 8 | `tests/Feature/Billing/EntitlementResolutionTest.php` |
| Each operator action records exactly one `entitlement_changed` entry, without amounts | Baseline; FR-019 | `tests/Feature/Billing/OperatorActionsTest.php` |
| Sign-in reports the same summary as the entitlements read | FR-017 | `tests/Contract/Billing/EntitlementsTest.php` |

### 5. API

Under `/api/v1`, in `docs/openapi/paths/billing.yaml`, tag `Plans & Billing`.

| Method | Path | Auth | Success | Error codes |
|---|---|---|---|---|
| GET | `/plans` | none | `200` | — (shared refusals only) |
| GET | `/me/entitlements` | session | `200` | `UNAUTHENTICATED` |
| GET | `/me/subscription` | session | `200` | `UNAUTHENTICATED` |

No write endpoint (FR-020). Operator actions are application services only, reached from the back office in B12. No new error code is expected.

### 6. Authorization and entitlements

The catalogue is public. Entitlements and subscription are the caller's own. Operator actions take an operator identity; the operator guard and screens are D2 and B12. This feature is itself the entitlement service; it gates nothing of its own.

### 7. Events

| Event | Payload | Listeners |
|---|---|---|
| `SubscriptionActivated` (constitution) | user id, subscription id, plan id | Sharing (B9), Notifications (B16) |
| `SubscriptionLapsed` (constitution) | user id, subscription id, new status (`grace` \| `expired` \| `revoked`) | Sharing (B9), Notifications (B16) |

Dispatched after commit; identifiers and scalars only (P-11).

### 8. Acceptance scenarios

| Story · scenario | Layer | Test file |
|---|---|---|
| US1 · 1–5 entitlements | Unit, Feature, Contract | `tests/Unit/Billing/GoverningSubscriptionTest.php`, `tests/Feature/Billing/EntitlementResolutionTest.php`, `tests/Contract/Billing/EntitlementsTest.php` |
| US2 · 1–3 catalogue | Contract, Feature | `tests/Contract/Billing/PlansTest.php`, `tests/Feature/Billing/CatalogueSeedTest.php` |
| US3 · 1–2 my subscription | Contract | `tests/Contract/Billing/SubscriptionTest.php` |
| US4 · 1–4 operator actions | Feature | `tests/Feature/Billing/OperatorActionsTest.php` |
| US5 · 1–4 lapse | Unit, Feature | `tests/Unit/Billing/SubscriptionStatusTest.php`, `tests/Feature/Billing/LapseTest.php`, `tests/Feature/Billing/LapseDeletesNothingTest.php` |

### 9. Open questions

1. ~~**Q1 — The free plan and non-store grants.**~~ Decided 2026-09-28: Free means no subscription; `manual` channel for grants and codes (see Clarifications).
2. ~~**Q2 — The launch catalogue.**~~ Decided 2026-09-28: Free, Plus monthly EGP 149, Plus yearly EGP 1,290, 5 members per wallet (FR-004).
3. **Existing members after a downgrade** belongs to B9 (ADR-0007 rule 10). The brief proposes "members become read-only rather than removed"; recorded as B9's input. Does not block.
4. **Resolving by wallet** needs Wallets' owner lookup (B4). The rule is fixed here. Does not block.
5. **Grace length for channels without their own** (operator grants, InstaPay): a configuration default of 3 days, which B11 may override. Does not block.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A new person sees their capabilities on their first screen, with 0 manual steps and the same answer on sign-in and on refresh.
- **SC-002**: A plan's price or capability changes with 0 code changes and 0 deployments: only data changes.
- **SC-003**: After a grant, extension, revocation or lapse, the person's next request reflects it in 100% of trials.
- **SC-004**: 0 records of a person are deleted or hidden when their paid access ends.
- **SC-005**: 0 code paths outside Billing's entitlement resolution read a plan's code, confirmed by the architecture gate.
- **SC-006**: The catalogue is readable in Arabic and English with 0 missing plan names.

## Assumptions

- The app uses Google Play's own localised price when selling in-app (B10). The catalogue's list prices serve the website, InstaPay (B11) and operators.
- The reserved capabilities (export, advanced reports, wallet count) are enabled on every plan until a later decision gates one, which would be a data change.
- Plan names are short labels in both languages; longer marketing copy belongs to the website.
- Operator identity is an identifier supplied by the back office; the operator guard itself is D2.
- Subscription instants are UTC (P-12); the lapse runs at least hourly.
