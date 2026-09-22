# Domain Glossary & Model Map

The authoritative index of every entity in this product: what it is, which module owns it, whether it is stored, whether it syncs to the phone, and what may point at it.

## Purpose and precedence

1. This document is the **single entity index** (FR-001). Every feature spec references it instead of redefining entities. If a concept is not here, it does not exist yet — add it here first.
2. It records **what exists and who owns it**. It does not specify column types, indexes, endpoints, payloads or migrations; those belong to each feature's own spec and to the API conventions spec.
3. It does **not** contain the wallet permission matrix. The [constitution](../.specify/memory/constitution.md) assigns that exclusively to the Wallets spec (P-08 rule 5).
4. The constitution states the rules; the [ADRs](adr/README.md) carry the reasoning; this document fixes the vocabulary. Where this document would contradict an Accepted ADR, the ADR wins and the fix is a **superseding ADR** — never an edit to an existing one, and never a quiet edit here.
5. Prior art lives in [docs/legacy/DATA_MODEL.md](legacy/DATA_MODEL.md). It is not in force; see [Prior-art mapping](#prior-art-mapping).

## How to read an entry

```markdown
### Name  (Arabic term, if the UI shows one)

One sentence: what it is, in product terms.

- **Context**: one of the nine bounded contexts
- **Role**: aggregate root | child of X | value inside X | derived value | protocol value
- **Persistence**: persisted | derived (never stored) | client-held
- **Sync**: no | yes — wallet scope | yes — user scope | yes — wallet scope, read-only
- **States**: a → b → c, or `single-state`
- **Referenced by**: entities that may point at it
- **Notes**: rules a builder must know, citing the governing ADR
```

Rules:

- **Context** is exactly one of: Identity, Wallets & Ledger, Budgeting, Sharing, Plans & Billing, Sync, Reporting, Notifications, Admin. Never two. Where another module writes the entity, the entry names it and the application service it goes through.
- **Sync = yes** requires a scope, and the entity carries `version`, `change_seq`, `updated_at`, `deleted_at` (ADR-0008). **Only two scopes exist**: wallet and user.
- **Sync = no** on a persisted entity requires the Notes to say how the client obtains it.
- **States** is a finite set. One-state entities write `single-state` rather than inventing filler.
- **Referenced by** lists inbound references only.

---

## The entity index

22 entities: the 21 named in the spec, plus **Category Reference**, which exists because categories are user-scoped and shared wallets need to render a partner's category (see [ADR-0012](adr/0012-postings-wallet-and-category-accounts.md) and the Category entry).

| Entity | Arabic | Context | Role | Persistence | Sync |
|---|---|---|---|---|---|
| [User](#user) | — | Identity | root | persisted | no |
| [Profile](#profile) | — | Identity | child of User | persisted | yes — user |
| [Device Session](#device-session) | — | Identity | child of User | persisted | yes — user |
| [Wallet](#wallet-محفظة) | محفظة | Wallets & Ledger | root, and a sync scope | persisted | yes — wallet |
| [Wallet Member](#wallet-member) | — | Wallets & Ledger | child of Wallet | persisted | yes — wallet |
| [Category](#category) | — | Wallets & Ledger | root | persisted | yes — user |
| [Category Reference](#category-reference) | — | Wallets & Ledger | projection of Category | derived | yes — wallet, read-only |
| [Transaction](#transaction) | — | Wallets & Ledger | root | persisted | yes — wallet |
| [Posting](#posting) | — | Wallets & Ledger | child of Transaction | persisted | **no — never exposed** |
| [Recurring Rule](#recurring-rule) | جمعية / قسط as presets | Wallets & Ledger | root | persisted | yes — wallet |
| [Occurrence](#occurrence) | — | Wallets & Ledger | child of Recurring Rule | persisted | yes — wallet |
| [Budget](#budget) | — | Budgeting | root | persisted | yes — user |
| [Budget Period](#budget-period) | — | Budgeting | derived value | derived | no |
| [Invitation](#invitation) | — | Sharing | root | persisted | yes — wallet |
| [Plan](#plan) | — | Plans & Billing | root | persisted | no |
| [Plan Feature](#plan-feature) | — | Plans & Billing | value inside Plan | persisted in the plan | no |
| [Subscription](#subscription) | — | Plans & Billing | root | persisted | no |
| [Entitlement](#entitlement) | — | Plans & Billing | derived value | derived | no |
| [Payment Claim](#payment-claim) | — | Plans & Billing | root | persisted | no |
| [Redeem Code](#redeem-code) | — | Plans & Billing | root | persisted | no |
| [Sync Cursor](#sync-cursor) | — | Sync | protocol value | client-held | n/a |
| [Report](#report) | — | Reporting | derived value | derived | no |

#### What syncs, and how the client gets the rest

**11 syncable entities, in exactly two scopes. There is no third scope.**

- **User scope (4)** — Profile, Device Session, Category, Budget.
- **Wallet scope (7)** — Wallet, Wallet Member, Category Reference, Transaction, Recurring Rule, Occurrence, Invitation.

**7 persisted but not synced**, and how the client obtains each:

| Entity | How the client gets it |
|---|---|
| User | never as a record; identity is implied by the session |
| Plan, Plan Feature | read endpoint, cached by the client |
| Subscription | read endpoint, refreshed after purchase or restore |
| Payment Claim | website only (P-10); the app sees only the resulting Subscription |
| Redeem Code | never read by the client; it submits a code and sees the result |
| Posting | never — internal to the server (P-04) |

**4 never persisted** — Entitlement, Budget Period, Report (all derived), and Sync Cursor (held by the client).

---

## Identity

### User

The authenticated person who owns an account.

- **Context**: Identity
- **Role**: aggregate root
- **Persistence**: persisted
- **Sync**: no — credentials and account state are auth data, not client records
- **States**: `active` → `pending_deletion` → `purged` (after the 30-day grace period)
- **Referenced by**: Profile, Device Session, Wallet Member, Category, Budget, Subscription, Payment Claim, Transaction (`created_by`), and every user-scoped entity
- **Notes**: holds credentials and account lifecycle only. Display name, locale and timezone live on Profile. Deleting an account never destroys another member's data in a shared wallet.

### Profile

The user's own preferences and display identity.

- **Context**: Identity
- **Role**: child of User (one-to-one)
- **Persistence**: persisted
- **Sync**: yes — user scope
- **States**: `single-state`
- **Referenced by**: — (read by every module that renders a person or formats a date)
- **Notes**: carries `locale` (`ar` | `en`) and `timezone`, an IANA name. The timezone is applied only in read models and reports, never at write time (P-12, ADR-0011). Numerals are Western (0–9) regardless of locale (P-14).

### Device Session

One device's signed-in session — what the user sees in a "devices signed in" list.

- **Context**: Identity
- **Role**: child of User
- **Persistence**: persisted
- **Sync**: yes — user scope, so revoking a session on one device reaches the others
- **States**: `active` → `revoked`; `active` → `expired`
- **Referenced by**: Notifications, which binds a push registration to a session
- **Notes**: the access token itself is **not** an entity and never leaves the auth layer; "token" and "PAT" are banned as product vocabulary. Notifications owns the push-token registration, which is a separate record and is not one of the 21 named entities — it will be indexed here when that feature is specced.

---

## Wallets & Ledger

### Wallet (محفظة)

A container of money with a balance — the core primitive.

- **Context**: Wallets & Ledger
- **Role**: aggregate root, **and one of the two sync scopes**
- **Persistence**: persisted
- **Sync**: yes — wallet scope (it *is* the scope: its `change_seq` counter orders the whole feed)
- **States**: `active` → `archived`; either → `tombstoned`
- **Referenced by**: Wallet Member, Transaction, Posting, Recurring Rule, Category Reference, Invitation
- **Notes**: "Wallet" is the **only** term for a money container (P-01). `type` is a fixed enum — `cash`, `checking`, `savings` (حصالة), `credit_card`, `loan`, `investment` — with behavioural semantics per type. Liability types (`credit_card`, `loan`) are stored **negative** and displayed as the amount owed. `currency` is immutable once any posting references the wallet. `balance_minor` is a **cache**: truth is `opening_balance_minor + Σ postings` and no invariant, authorization decision or report total may read the cache (P-05, ADR-0005). A negative balance is never blocked at write time.

### Wallet Member

One person's membership of one wallet, carrying their role.

- **Context**: Wallets & Ledger — **written by Sharing** through `WalletService` when an invitation is accepted
- **Role**: child of Wallet
- **Persistence**: persisted
- **Sync**: yes — wallet scope
- **States**: `active` → `revoked` (a role change stays `active`)
- **Referenced by**: every authorization check
- **Notes**: roles are `owner`, `editor`, `viewer`. **The permission matrix — which role may do what — lives only in the Wallets spec** and is deliberately not repeated here (P-08, FR-017). Authorization is evaluated against every wallet a write touches, and a non-member receives 404 rather than 403 (ADR-0006). A shared wallet is governed by its **owner's** entitlements (ADR-0007). Ownership sits here and not on a separate grouping entity: `Space` was dropped, see [Prior-art mapping](#prior-art-mapping).

### Category

A user's own income or expense bucket — the budgetable and reportable dimension.

- **Context**: Wallets & Ledger
- **Role**: aggregate root
- **Persistence**: persisted
- **Sync**: yes — **user scope**. One personal set per person, reused across all of that person's wallets
- **States**: `active` → `archived`; an **unused** category may additionally be deleted (tombstoned)
- **Referenced by**: Posting, Budget, Category Reference
- **Notes**:
  - `kind` is `income` or `expense`. In the ledger a category is the counter-leg of a wallet posting (ADR-0012), so category totals are summed from postings exactly as wallet balances are.
  - **A category that any posting references is never hard-deleted.** Archiving hides it from pickers and leaves history intact; a cascade would destroy ledger rows and a silent re-point would rewrite records the user did not authorise.
  - `parent_id` is **reserved** for sub-categories; nesting behaviour is out of scope for MVP.
  - **In a shared wallet, members do not see each other's category lists** — categories ride the user feed. When a member categorises a transaction, the server publishes a [Category Reference](#category-reference) onto that wallet's feed so every member can render it. Nobody can edit anyone else's category.

### Category Reference

A read-only copy of a category's display fields, published to a wallet so its members can render transactions categorised by someone else.

- **Context**: Wallets & Ledger
- **Role**: projection of Category
- **Persistence**: derived — produced by the server, never authored
- **Sync**: yes — **wallet scope, read-only**
- **States**: `single-state` (it is republished when its source Category changes, and withdrawn when nothing in the wallet references it)
- **Referenced by**: client rendering only; no server entity points at it
- **Notes**: carries id, name, icon, colour, `kind` and the owning user's id. The server **must never accept** one from a client (ADR-0008 forbids client-supplied `change_seq` and this resource has no write endpoint). It grants no rights: only the owner edits the underlying Category, through their own user feed.

### Transaction

A money-movement event — what the user actually records.

- **Context**: Wallets & Ledger
- **Role**: aggregate root
- **Persistence**: persisted
- **Sync**: yes — wallet scope
- **States**: `live` → `live (v+1)` on edit → `tombstoned` on delete → `live` again on an explicit undelete
- **Referenced by**: Posting, Occurrence (which one it confirmed into), Report
- **Notes**:
  - Carries a calendar `date` (`YYYY-MM-DD`, no timezone), a positive amount in minor units with its currency, a description, an optional memo, a `cleared` flag and `created_by`.
  - **Exactly one category in MVP.** The ledger permits several category legs, so splits are a later contract change, not a data migration.
  - `kind` — `income`, `expense`, `transfer` — is a **derived cache** over the postings. A transaction whose postings all reference wallets is a `transfer` and is excluded from every spending and income aggregation.
  - All postings of one transaction share a single currency in MVP; cross-currency is rejected (P-04).

### Posting

The ledger leg — the row that makes balances and category totals the same kind of sum.

- **Context**: Wallets & Ledger
- **Role**: child of Transaction
- **Persistence**: persisted
- **Sync**: **no — never exposed through the API or the sync protocol**
- **States**: `single-state` (postings are replaced wholesale when their transaction is edited)
- **Referenced by**: balance and category-total queries only
- **Notes**: each posting references **exactly one of a wallet or a category** — never both, never neither — and the postings of one transaction **sum to zero per currency** ([ADR-0012](adr/0012-postings-wallet-and-category-accounts.md), P-04). The source side is negative, the destination positive; the user never types a sign. Editing a transaction deletes and re-derives all of its postings inside the same database transaction.

### Recurring Rule

A repeating money movement the user set up once — including the gam'eya (جمعية) and installment (قسط) presets.

- **Context**: Wallets & Ledger
- **Role**: aggregate root
- **Persistence**: persisted
- **Sync**: yes — wallet scope
- **States**: `active` → `paused` → `active`; `active` | `paused` → `ended`
- **Referenced by**: Occurrence
- **Notes**: the rule stores the IANA timezone captured at creation, so its schedule does not shift when a member in another timezone views it (ADR-0011). جمعية and قسط are **presets of this rule**, not separate entities — creating `Gameya` or `Installment` entities would fragment the recurring engine.

### Occurrence

One dated instance produced by a recurring rule, which the user confirms, skips or misses.

- **Context**: Wallets & Ledger
- **Role**: child of Recurring Rule
- **Persistence**: persisted — **materialised ahead of time**, not computed on read
- **Sync**: yes — wallet scope
- **States**: `scheduled` → `confirmed` (producing a Transaction); `scheduled` → `skipped`; `scheduled` → `due` → `missed`
- **Referenced by**: Transaction (the one it produced)
- **Notes**: materialised so the client can list and confirm upcoming items **offline**, and so per-occurrence state (paid, skipped) has somewhere to live — a gam'eya round needs exactly that.

---

## Budgeting

### Budget

One person's spending limit for one category in a repeating period.

- **Context**: Budgeting
- **Role**: aggregate root
- **Persistence**: persisted
- **Sync**: yes — **user scope**
- **States**: `active` → `archived`; either → `tombstoned` on delete (like every syncable entity, ADR-0008)
- **Referenced by**: Report
- **Notes**:
  - **A budget is personal, not a household limit.** It caps its owner's own spending. Two partners sharing a wallet each keep their own budgets, and a budget never appears in a wallet feed.
  - Actual spending is summed from postings for the category within the period's dates; Budgeting reads that total through the Wallets & Ledger query services, never through its models (P-02). Transfers are excluded, and totals are never read from `balance_minor` (P-05).
  - `monthly` is the only period type in MVP.
  - **Open, and owned by the Budgeting spec — not decided here**: how a transaction in a *shared* wallet is attributed to one member's budget (by the owning user of the category it references, or by the transaction's `created_by`); which currency a budget is denominated in when its owner holds wallets in more than one; whether an `income` category may carry a budget; and uniqueness per (user, category, period). This entry deliberately states the vocabulary and stops short of those rules.

### Budget Period

The calendar window a budget applies to.

- **Context**: Budgeting
- **Role**: derived value
- **Persistence**: derived — computed from the budget's period type plus a date; not stored in MVP
- **Sync**: no
- **States**: `single-state`
- **Referenced by**: budget progress and reports
- **Notes**: **the bare word "Period" is never an entity name.** This is the budget window; a subscription's window is the *billing period*, which is a pair of fields on [Subscription](#subscription). Period membership is decided by the transaction's **calendar date**, so every member of a shared wallet sees identical totals regardless of timezone (P-12). Per-month rows with rollover are deferred.

---

## Sharing

### Invitation

An offer to join a wallet, sent to one person.

- **Context**: Sharing
- **Role**: aggregate root
- **Persistence**: persisted
- **Sync**: yes — wallet scope (existing members see pending invitations)
- **States**: `pending` → `accepted` (producing a Wallet Member) | `declined` | `revoked` | `expired`
- **Referenced by**: Wallet Member (the membership it produced)
- **Notes**: acceptance calls into Wallets & Ledger through `WalletService` to create the membership — Sharing never writes another module's models (P-02). Sharing is the **only** capability the free tier limits (P-09); the inviting wallet's **owner's** entitlements decide whether an invitation may be sent.

---

## Plans & Billing

### Plan

A named set of capabilities and quotas a user can be subscribed to.

- **Context**: Plans & Billing
- **Role**: aggregate root (reference data)
- **Persistence**: persisted
- **Sync**: no — the client reads it from an endpoint and caches it
- **States**: `active` → `retired` (a retired plan keeps serving existing subscriptions)
- **Referenced by**: Subscription, Redeem Code, Entitlement (derivation input)
- **Notes**: **plans are data, not code.** A plan carries a period and its feature map. **No code anywhere compares a plan code** (P-09) — capability questions go to `EntitlementService`.

### Plan Feature

One capability flag or quota inside a plan's feature map.

- **Context**: Plans & Billing
- **Role**: value inside Plan
- **Persistence**: persisted as part of the plan, not as its own table
- **Sync**: no
- **States**: `single-state`
- **Referenced by**: Entitlement (derivation input)
- **Notes**: named by a `Feature` enum value, which is what call sites reference. Adding a capability means adding an enum value and plan data — never a conditional on a plan code.

### Subscription

One user's provider-agnostic record of paid access.

- **Context**: Plans & Billing
- **Role**: aggregate root
- **Persistence**: persisted
- **Sync**: no — read endpoint, refreshed after purchase or restore
- **States**: `active` → `grace` → `expired` → `active` (on renewal or an approved claim); `active` | `grace` → `revoked`
- **Referenced by**: Entitlement, Payment Claim, Redeem Code
- **Notes**: both channels normalise into this one record — `provider` is `google_play` or `instapay` (ADR-0007). It carries the **billing period** (`current_period_start`, `current_period_end`, `grace_until`); those fields are never called just "period". Losing a subscription **never deletes user data**; it blocks plan-gated sharing actions.

### Entitlement

The answer to "may this user do this right now".

- **Context**: Plans & Billing
- **Role**: derived value
- **Persistence**: **derived — never stored**
- **Sync**: no
- **States**: `single-state`
- **Referenced by**: every capability check in every module
- **Notes**: resolved from the user's Subscription and its Plan by `EntitlementService`, the **only** path to a capability decision (P-09). `forWallet()` resolves the wallet **owner's** entitlements, so a shared wallet runs on its owner's plan. Results may be cached per request only. A stored entitlement would become a second source of truth that contradicts the subscription.

### Payment Claim

A user's claim that they paid by InstaPay, awaiting review.

- **Context**: Plans & Billing
- **Role**: aggregate root
- **Persistence**: persisted
- **Sync**: no — **website only**
- **States**: `submitted` → `under_review` → `approved` | `rejected`; `submitted` → `expired`
- **Referenced by**: Subscription (created or extended on approval)
- **Notes**: **never submitted from inside the app** — Google Play policy forbids alternative payment there, so the app exposes only restore-purchases and redeem-code (P-10). Receipt images are never logged (ADR-0010).

### Redeem Code

A code that grants a subscription when redeemed.

- **Context**: Plans & Billing
- **Role**: aggregate root
- **Persistence**: persisted
- **Sync**: no
- **States**: `issued` → `redeemed` | `revoked` | `expired`
- **Referenced by**: Subscription (created on redemption)
- **Notes**: the in-app "enter code" surface is permitted because redemption is not a purchase (P-10).

---

## Sync

### Sync Cursor

The client's marker for "everything in this scope up to here, I already have".

- **Context**: Sync
- **Role**: protocol value
- **Persistence**: **client-held — there is no server-side row and there must not be one**
- **Sync**: n/a
- **States**: `single-state`
- **Referenced by**: every pull request
- **Notes**: the server side of the mechanism is the `change_seq` counter on the **scope row** (a wallet or a user) plus the `change_seq` stamped on each syncable entity (ADR-0008). A cursor older than the tombstone retention horizon (**≥ 90 days**) is answered with an expiry error and the client fully resyncs that scope. A server-side cursor table would duplicate state the client owns and then disagree with it.

---

## Reporting

### Report

A computed view over the ledger — spending by category, net worth, a period comparison.

- **Context**: Reporting
- **Role**: derived value
- **Persistence**: **derived — a query, never a row**
- **Sync**: no
- **States**: `single-state`
- **Referenced by**: —
- **Notes**: reads Transaction, Posting sums, Category and Budget through their owning modules' query services (P-02). Totals are recomputed from postings and **never read `balance_minor`** (P-05). The viewer's timezone affects only default date ranges; period membership is by calendar date, so members of a shared wallet see identical figures.

---

## Notifications and Admin

Neither context owns any of the 21 named entities.

- **Notifications** owns the push registration that binds a device to a [Device Session](#device-session), and notification preferences. Those records will be indexed here when that feature is specced.
- **Admin** owns admin accounts — a **separate guard**, with no role or admin flag on application users (P-08, ADR-0006) — and the audit record of admin actions. Admin **writes only through other modules' application services**, so back-office actions run the same invariants, authorization and events as app actions.

---

## Cross-module access

Every entity has exactly one owner. Other modules reach it only through that module's application services, never its models (P-02).

| Reader | Reads | Through |
|---|---|---|
| Budgeting | Category, transaction totals | Wallets & Ledger query services |
| Sharing | Wallet, Wallet Member | `WalletService` (writes membership on acceptance) |
| Reporting | Transaction, Posting sums, Category, Budget | owning modules' query services; event-fed projections allowed later |
| Notifications | Device Session | Identity application service |
| Admin | everything it displays or changes | each owning module's application service |
| every module | Entitlement | `EntitlementService` only (P-09) |

---

## Arabic terms

| Arabic | English | Kind | Where it lives |
|---|---|---|---|
| محفظة | Wallet | **entity** | the [Wallet](#wallet-محفظة) entity |
| حصالة | savings wallet | **type value** | `wallets.type = savings` |
| جمعية | rotating savings group (gam'eya) | **preset** | a [Recurring Rule](#recurring-rule) preset |
| قسط | installment | **preset** | a [Recurring Rule](#recurring-rule) preset |

Only محفظة names an entity. The other three are a type value and two presets — creating entities for them would fragment the ledger and the recurring engine.

Numerals in API output are always Western (0–9), whatever the locale (P-14).

---

## Banned synonyms

The left column must not appear in any namespace, class, table, column, route, event or user-facing string. The exception column marks terms permitted **only** when quoting an external system at its integration boundary.

| Forbidden | Canonical | External exception |
|---|---|---|
| account, ledger account | **Wallet** | no |
| purse, fund, pot, jar | **Wallet** | no |
| collaborator, participant, sharer, friend | **Wallet Member** | no |
| group, space, household, family, circle | *(none — use wallet membership)* | no |
| tag, label, bucket | **Category** | no |
| entry, record, movement, operation | **Transaction** | no |
| line, leg, split line, journal line | **Posting** | no |
| schedule, repeat, recurrence | **Recurring Rule** | no |
| instance, run | **Occurrence** | no |
| tier, package, product | **Plan** | yes — Google Play calls a subscription product a "product" |
| feature flag, permission | **Plan Feature** | no |
| token, PAT | **Device Session** | yes — inside the auth layer and in HTTP headers |
| purchase, order | **Subscription** / **Payment Claim** | yes — Google Play "purchase", "purchase token" |
| transfer (as a payment) | **Payment Claim** | yes — InstaPay calls its payment a transfer |
| limit, cap, envelope | **Budget** | no |
| invite link, join request | **Invitation** | no |

"Transfer" remains the correct internal word for a **Transaction** that moves money between two wallets. The ban is only on using it for an InstaPay payment.

#### Naming code, not just data

The bans above cover class and service names too, so a rename needs somewhere to land:

- A service is named after **the entity it serves**, plus its role: `WalletService`, `RecurringRuleService`, `EntitlementService`, `BudgetQueryService`. Never after a banned word — `AccountBalanceService` and `RecurrenceScheduler` are violations with no exception.
- A query service that another module calls ends in `QueryService` and returns read models, never Eloquent models (P-02).
- Where a canonical service name is not listed here, the owning module's spec names it. The rule is only that the entity word must be the canonical one.

---

## Field conventions

The shapes that recur across entities. Features use these names, not variants.

| Concept | Name | Shape | ADR |
|---|---|---|---|
| Identifier | `id` | UUIDv7; client-generated for syncable entities | [0002](adr/0002-identifiers-uuidv7.md) |
| Money | `<name>_minor` + `currency` | signed 64-bit integer minor units + ISO 4217 alpha-3 | [0003](adr/0003-money-minor-units.md) |
| Wallet balance | `balance_minor`, `opening_balance_minor` | as above; `balance_minor` is a cache, never truth | [0005](adr/0005-derived-balance-with-cache.md) |
| Calendar date | `date`, `<name>_date` | `YYYY-MM-DD`, no timezone | [0011](adr/0011-time-and-timezones.md) |
| Instant | `<name>_at` | UTC, microsecond precision | [0011](adr/0011-time-and-timezones.md) |
| Optimistic concurrency | `version` | integer, server-incremented, echoed by the client | [0008](adr/0008-sync-changes-feed.md) |
| Sync ordering | `change_seq` | server-assigned from the scope counter; never client-supplied | [0008](adr/0008-sync-changes-feed.md) |
| Soft delete | `deleted_at` | null or UTC instant; tombstone retained ≥ 90 days | [0008](adr/0008-sync-changes-feed.md) |
| Scope key | `user_id` / `wallet_id` | the sync scope; **not every entity is wallet-scoped** | [0008](adr/0008-sync-changes-feed.md) |

Forbidden variants: `amount_cents`, `total_minor`, `cached_balance`, `space_id`, `updated_time`, `is_deleted`, `rev`, `seq`.

These are the names. Their form on the wire — how money, dates, instants and the scope key appear in a request or a response, and what the API does when one arrives in the wrong shape — is in [docs/api-conventions.md](api-conventions.md).

---

## Prior-art mapping

How the eight MVP entities in [docs/legacy/DATA_MODEL.md](legacy/DATA_MODEL.md) land here. That document is **not in force**.

| Prior art | Here | Why |
|---|---|---|
| User | **User + Profile + Device Session** | Credentials stay on User; display name, locale and timezone move to Profile; sessions become visible and revocable |
| Space | **dropped** | Ownership is the wallet itself. A grouping entity above wallets added a second sharing boundary for no gain |
| SpaceMember | **dropped → Wallet Member** | Membership belongs on the wallet, with three roles (`owner`/`editor`/`viewer`) instead of two, per ADR-0006 |
| Wallet | **Wallet** | `cached_balance` → `balance_minor`; `space_id` replaced by the owner plus members; type enum kept |
| Category | **Category + Category Reference** | Now **user-scoped**; the projection is how shared wallets render a partner's category |
| Transaction | **Transaction** | `space_id` dropped; exactly one category in MVP |
| Posting | **Posting** | Kept as-is: wallet XOR category, summing to zero — this is the model ADR-0012 adopted |
| Budget | **Budget + Budget Period** | User-scoped and personal; the period becomes a derived window rather than a stored field |

Prior-art entities that map to nothing: `BalanceAssertion` and `ImportBatch`, both deferred below.

---

## Deferred, not built

These exist in earlier planning or in the product's future, and are **out of MVP scope**. Specifying any of them requires a constitution amendment first (P-17).

- **BalanceAssertion** — reconciliation checkpoint; activates with statement import.
- **ImportBatch** — one statement-import run.
- **FX / exchange-rate entities** — multi-currency transfers. The per-currency zero-sum rule already permits the shape; MVP rejects mixed currencies.
- **Savings goals**.
- **Envelope allocations** — zero-based budgeting on top of categories.
- **Budget period rows with rollover** — replacing the derived window.
- **Investment valuation snapshots** — for `investment` wallets, whose value is not transaction-derived.
- **AI entry fields** — `entry_method`, `raw_input` for assisted logging.
