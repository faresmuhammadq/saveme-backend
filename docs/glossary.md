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

27 entities: the 21 named in the spec, plus **Category Reference**, which exists because categories are user-scoped and shared wallets need to render a partner's category (see [ADR-0012](adr/0012-postings-wallet-and-category-accounts.md) and the Category entry), plus **Audit Entry** and **Operator Notification**, added by spec 007 (Identity & Authentication), plus **Operator Account**, **Recovery Code** and **Re-enrolment Request**, added by spec 018 (Operator Sign-in), plus **Activity Entry**, added by spec 016 (Sharing & Invitations; see [ADR-0020](adr/0020-wallet-activity-beside-the-audit-log.md)), plus **Report Range**, added by spec 019 (Reports; see [ADR-0022](adr/0022-reports-are-wallet-views-computed-on-read.md)), plus **Idempotency Record**, added by spec 020 (InstaPay Claims; see [ADR-0023](adr/0023-instapay-claims-and-claim-bound-redeem-codes.md)), plus **Export** and **Successor**, added by spec 022 (Export & Data Deletion; see [ADR-0024](adr/0024-account-deletion-keeps-shared-history.md)), plus **Push Registration**, **Notification Preferences** and **Push Notification** (with its child Push Delivery), added by spec 023 (Devices & Push Notifications; see [ADR-0025](adr/0025-push-through-a-port-with-an-in-house-fcm-adapter.md)), plus **Budget Overrun** (Budgeting), added by spec 023.

| Entity | Arabic | Context | Role | Persistence | Sync |
|---|---|---|---|---|---|
| [User](#user) | — | Identity | root | persisted | no |
| [Profile](#profile) | — | Identity | child of User | persisted | yes — user |
| [Device Session](#device-session) | — | Identity | child of User | persisted | yes — user |
| [Audit Entry](#audit-entry) | — | Identity | root | persisted | no |
| [Export](#export) | — | Identity | root | persisted | no |
| [Successor](#successor) | — | Identity | value | derived | no |
| [Wallet](#wallet-محفظة) | محفظة | Wallets & Ledger | root, and a sync scope | persisted | yes — wallet |
| [Wallet Member](#wallet-member) | — | Wallets & Ledger | child of Wallet | persisted | yes — wallet |
| [Activity Entry](#activity-entry) | — | Wallets & Ledger | root | persisted | no — read endpoint |
| [Category](#category) | — | Wallets & Ledger | root | persisted | yes — user |
| [Category Reference](#category-reference) | — | Wallets & Ledger | projection of Category | derived | yes — wallet, read-only |
| [Transaction](#transaction) | — | Wallets & Ledger | root | persisted | yes — wallet |
| [Posting](#posting) | — | Wallets & Ledger | child of Transaction | persisted | **no — never exposed** |
| [Recurring Rule](#recurring-rule) | جمعية / قسط as presets | Wallets & Ledger | root | persisted | yes — wallet |
| [Occurrence](#occurrence) | — | Wallets & Ledger | child of Recurring Rule | persisted | yes — wallet |
| [Budget](#budget) | — | Budgeting | root | persisted | yes — user |
| [Budget Period](#budget-period) | — | Budgeting | derived value | derived | no |
| [Budget Overrun](#budget-overrun) | — | Budgeting | child of Budget | persisted | no |
| [Invitation](#invitation) | — | Sharing | root | persisted | yes — wallet |
| [Plan](#plan) | — | Plans & Billing | root | persisted | no |
| [Plan Feature](#plan-feature) | — | Plans & Billing | value inside Plan | persisted in the plan | no |
| [Subscription](#subscription) | — | Plans & Billing | root | persisted | no |
| [Entitlement](#entitlement) | — | Plans & Billing | derived value | derived | no |
| [Payment Claim](#payment-claim) | — | Plans & Billing | root | persisted | no |
| [Redeem Code](#redeem-code) | — | Plans & Billing | root | persisted | no |
| [Idempotency Record](#idempotency-record) | — | Plans & Billing | root (operational) | persisted | no |
| [Sync Cursor](#sync-cursor) | — | Sync | protocol value | client-held | n/a |
| [Change Row](#change-row) | — | Sync | protocol value | derived (a read of the feed) | n/a |
| [Sync Batch](#sync-batch) | — | Sync | protocol value | request-only | n/a |
| [Purge Horizon](#purge-horizon) | — | Sync | value on a scope row | persisted on the scope row | n/a |
| [Scope Head](#scope-head) | — | Sync | protocol value | derived (the scope counter) | n/a |
| [Sync Position](#sync-position) | — | Sync | protocol value | derived (a feed page's meta) | n/a |
| [Report](#report) | — | Reporting | derived value | derived | no |
| [Report Range](#report-range) | — | Reporting | derived value | derived | no |
| [Push Registration](#push-registration) | — | Notifications | root | persisted | no |
| [Notification Preferences](#notification-preferences) | — | Notifications | root | persisted | no |
| [Push Notification](#push-notification) | — | Notifications | root | persisted | no |
| [Operator Notification](#operator-notification) | — | Admin | root | persisted | no |
| [Operator Account](#operator-account) | — | Admin | root | persisted | no |
| [Recovery Code](#recovery-code) | — | Admin | value inside Operator Account | persisted in the account | no |
| [Re-enrolment Request](#re-enrolment-request) | — | Admin | root | persisted | no |
| [Notification Read Mark](#notification-read-mark) | — | Admin | root | persisted | no |
| [Anonymity Floor](#anonymity-floor) | — | Admin | value | derived (a constant of the back office) | n/a |

#### What syncs, and how the client gets the rest

**11 syncable entities, in exactly two scopes. There is no third scope.**

- **User scope (4)** — Profile, Device Session, Category, Budget.
- **Wallet scope (7)** — Wallet, Wallet Member, Category Reference, Transaction, Recurring Rule, Occurrence, Invitation.

**18 persisted but not synced**, and how the client obtains each:

| Entity | How the client gets it |
|---|---|
| User | never as a record; identity is implied by the session |
| Plan, Plan Feature | read endpoint, cached by the client |
| Platform categories (a Category with a `system_key`) | read endpoint (`GET /platform-categories`), cached by the client |
| Subscription | read endpoint, refreshed after purchase or restore |
| Payment Claim | website only (P-10); the app sees only the resulting Subscription |
| Redeem Code | never read by the client; it submits a code and sees the result |
| Idempotency Record | never — operational; the client only sends the key and sees a replay header |
| Posting | never — internal to the server (P-04) |
| Audit Entry | never — operator-only |
| Export | read endpoint (`GET /exports`, `GET /exports/{id}`), polled until `ready`; the file is fetched through a signed link |
| Activity Entry | read endpoint (`GET /wallets/{id}/activity`), paged, 90 days |
| Push Registration | never read back; the client writes it (`PUT /me/devices/{id}`) and receives the push |
| Notification Preferences | read endpoint (`GET /me/notification-preferences`), read when the settings screen opens |
| Push Notification (and Push Delivery) | never — the client receives the push, not the record |
| Budget Overrun | never — Budgeting's record that a period crossed its limit; the client sees the push |
| Operator Notification | never — operator-only |
| Operator Account, Recovery Code | never — operators only, and never through `/api/v1` |
| Re-enrolment Request | never — operator-only |

**8 never persisted** — Entitlement, Budget Period, Report, Report Range, Successor (all derived), Sync Cursor (held by the client), Change Row (a read of a feed) and Sync Batch (a request that is gone once answered). The Purge Horizon is a counter on the scope row, not an entity. The Scope Head and the Sync Position are positions read off a scope or a page, not entities either.

---

## Identity

### User

The authenticated person who owns an account.

- **Context**: Identity
- **Role**: aggregate root
- **Persistence**: persisted
- **Sync**: no — credentials and account state are auth data, not client records
- **States**: `active` ⇄ `pending_deletion` → `purging` → `purged`. After the 30-day grace period the purge moves the account to `purging`, which ends every session and cannot be cancelled; `purged` is a **husk** with no identity attribute (no address, password or data key), deleted after 400 days. A new sign-in or an explicit cancel during the grace period returns the account to `active`
- **Referenced by**: Profile, Device Session, Wallet Member, Category, Budget, Subscription, Transaction (`created_by`), and every user-scoped entity
- **Notes**: holds credentials and account lifecycle only. Display name, locale and timezone live on Profile. Deleting an account never destroys another member's data in a shared wallet: an owned wallet with another member passes to a [Successor](#successor) ([ADR-0024](adr/0024-account-deletion-keeps-shared-history.md)).

### Profile

The user's own preferences and display identity.

- **Context**: Identity
- **Role**: child of User (one-to-one)
- **Persistence**: persisted
- **Sync**: yes — user scope
- **States**: `single-state`
- **Referenced by**: — (read by every module that renders a person or formats a date)
- **Notes**: carries `locale` (`ar` | `en`) and `timezone`, an IANA name. The timezone is applied only in read models and reports, never at write time (P-12, ADR-0011). Numerals are Western (0–9) regardless of locale (P-14). The locale is also the default language of the person's mails, and of API messages when a signed-in request names none. Since spec 008 it also carries:
  - `default_currency`: an ISO 4217 code, EGP by default. It is only the default offered for new wallets; each wallet keeps its own currency. It is **locked** while the person has recorded a live transaction, and the profile reports this as `default_currency_locked` (derived, never stored).
  - `budget_period_start_day` (1–28) and `budget_period_start_day_from`: the day [Budget Periods](#budget-period) begin, and the calendar date that day applies from. Periods already begun are never re-cut.
  - **Budget start day history** (Identity, server-internal, not on the wire): the dated list of start days behind `budget_period_start_day`, appended by `PUT /me/profile` when the day changes; read by Budgeting to cut Budget Periods.
  - Written as a whole with `PUT /me/profile` carrying `version`, under the syncable write rules.
  - The **user scope counter** is `users.change_seq`; a profile, device session, category or membership write takes the next value under the person's row lock (ADR-0008 rules 2-5, ADR-0017).

### Device Session

One device's signed-in session — what the user sees in a "devices signed in" list.

- **Context**: Identity
- **Role**: child of User
- **Persistence**: persisted
- **Sync**: yes — user scope, so revoking a session on one device reaches the others
- **States**: `active` → `revoked`; `active` → `expired`
- **Referenced by**: [Push Registration](#push-registration), which binds a push address to a session
- **Notes**: the access token itself is **not** an entity and never leaves the auth layer; "token" and "PAT" are banned as product vocabulary. Notifications owns the push registration, a separate record keyed to the session id (see [Push Registration](#push-registration)).

### Audit Entry

One recorded security or privacy event — who did what, to which subject, and when.

- **Context**: Identity
- **Role**: aggregate root
- **Persistence**: persisted
- **Sync**: no — operators read it through Admin
- **States**: `single-state`
- **Referenced by**: —
- **Notes**: append-only; never edited, and never deleted before its 400-day retention expires. Its shape, event vocabulary and exclusion list are fixed by the [security baseline](security-baseline.md#the-audit-entry), not here. Identity owns the one log; every other module, Admin's `AdminAuditService` included, writes through Identity's application service. Reading it is itself recorded.

### Export

A copy of what a person entered and can see, prepared on request as one downloadable archive.

- **Context**: Identity
- **Role**: aggregate root
- **Persistence**: persisted (the record; the file lives on the private disk)
- **Sync**: no — the client reads it through `GET /exports` and `GET /exports/{id}`
- **States**: `queued` → `running` → `ready` → `expired`; `queued` | `running` → `failed`
- **Referenced by**: —
- **Notes**: scope is the whole account or one wallet the person belongs to. The archive is a ZIP of JSON files plus a spreadsheet-openable CSV of transactions. It is not gated by plan or verification. One export per person is active at a time, and at most five may be requested per 24 hours. The file lives 24 hours after it is ready, then is deleted; the record lives 30 days. A signed link opens the file: 15 minutes when handed out by the app, until expiry when mailed. See the [security baseline](security-baseline.md#export) and [ADR-0024](adr/0024-account-deletion-keeps-shared-history.md).

### Successor

The member who takes over a shared wallet when its owner's account is deleted.

- **Context**: Identity
- **Role**: value (computed by the deletion planner)
- **Persistence**: derived (never stored); the owner's choices are stored while the deletion is pending
- **Sync**: no
- **States**: `single-state`
- **Referenced by**: — (shown by `GET /account/deletion-request` and applied by the purge)
- **Notes**: carries a reason: `only_member`, `chosen`, `oldest_editor` or `oldest_viewer`. Order: the only other member, of any role; the one the owner chose; the editor with the earliest membership; the viewer with the earliest membership. A member whose own account is pending deletion is skipped unless every candidate is. A wallet with no other active member has no successor and is deleted. The preview and the purge use the same rule ([ADR-0024](adr/0024-account-deletion-keeps-shared-history.md)).

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
- **Notes**: "Wallet" is the **only** term for a money container (P-01). `type` is a fixed enum — `cash`, `checking`, `savings` (حصالة), `credit_card`, `loan`, `investment` — with behavioural semantics per type. Liability types (`credit_card`, `loan`) are stored **negative** and displayed as the amount owed. `currency` is immutable once any transaction has posted to the wallet (**currency locked**: derived, never stored, true once any transaction, live or tombstoned, posts or posted to the wallet; reported as `currency_locked`). `archived_at` is the instant the `archived` state was entered. The wallet's `change_seq` is the scope counter and moves on any write inside the scope, including membership writes; its `version` moves only when the wallet's own fields change. `balance_minor` is a **cache**: truth is `opening_balance_minor + Σ postings` and no invariant, authorization decision or report total may read the cache (P-05, ADR-0005). A negative balance is never blocked at write time.

### Wallet Member

One person's membership of one wallet, carrying their role.

- **Context**: Wallets & Ledger — **written by Sharing** through `WalletService` when an invitation is accepted
- **Role**: child of Wallet
- **Persistence**: persisted
- **Sync**: yes — wallet scope
- **States**: `active` → `revoked` (a role change stays `active`)
- **Referenced by**: every authorization check
- **Notes**: **removal and leaving** tombstone the membership (the state `revoked`): the wallet's feed and the person's own user feed carry the tombstone, and every later request of theirs on the wallet is 404. **Plan demotion**: when the owner's plan lapses, or ownership moves to an owner whose plan allows fewer members, the editors beyond the owner's `sharing.max_members_per_wallet` quota (owner first, then joining order) become viewers, with a recorded reason; nobody is removed and nobody is promoted again. A person's **own** memberships also ride their user feed, so a phone learns of a wallet shared with it and of losing one (ADR-0017). Roles are `owner`, `editor`, `viewer`. **The permission matrix — which role may do what — lives only in the Wallets spec** and is deliberately not repeated here (P-08, FR-017). Authorization is evaluated against every wallet a write touches, and a non-member receives 404 rather than 403 (ADR-0006). A shared wallet is governed by its **owner's** entitlements (ADR-0007). **Ownership transfer**: the owner hands `owner` to another active member and becomes `editor`; it never adds or removes a person. The member is exposed on the wire as `member_user_id`. Ownership sits here and not on a separate grouping entity: `Space` was dropped, see [Prior-art mapping](#prior-art-mapping).

### Activity Entry

One recorded act in a wallet — who did what, to which record, and when — readable by the wallet's members.

- **Context**: Wallets & Ledger
- **Role**: aggregate root
- **Persistence**: persisted
- **Sync**: no — read endpoint `GET /wallets/{id}/activity`, paged newest first
- **States**: `single-state` (append-only; removed after 90 days)
- **Referenced by**: —
- **Notes**: a member-visible record, **not** the Audit Entry, which is operator-only security evidence with a fixed vocabulary. Identifiers only: the actor (null when the server acted), the action, the kind and id of the record acted on and, for membership and invitation acts, the role; no amount, note, record name or address. The actor's display name is shown only while the actor is an active member. One entry per applied write; replays write none. See [ADR-0020](adr/0020-wallet-activity-beside-the-audit-log.md).

### Category

An income or expense bucket, owned by one person or by the platform — the budgetable and reportable dimension.

- **Context**: Wallets & Ledger
- **Role**: aggregate root
- **Persistence**: persisted
- **Sync**: yes — user scope, for a person's category; a platform category is reference data, read through `GET /platform-categories` and cached
- **States**: a person's: `active` → `archived`; an unused one may be tombstoned. The platform's: `active` ⇄ `retired`, never deleted
- **Referenced by**: Posting, Budget, Category Reference
- **Notes**:
  - `kind` is `income` or `expense` and never changes. In the ledger a category is the counter-leg of a wallet posting (ADR-0012), so category totals are summed from postings exactly as wallet balances are.
  - A **platform category** is owned by the platform, carries a `system_key`, is used by reference and never copied, and is editable by nobody through the API ([ADR-0014](adr/0014-platform-owned-categories.md)). Its `system_key` is stable and language-independent, unique, never renamed or reused; reports group by it. **Retired** means `archived_at` is set on a platform category: it stays valid on existing records and leaves pickers.
  - **In use** is derived and never stored: any live or tombstoned record (transaction, recurring rule, budget) references the category. A category in use is never deleted; archiving hides it from pickers and leaves history intact, because a cascade would destroy ledger rows and a silent re-point would rewrite records the user did not authorise.
  - `parent_id` stays **reserved**: categories are flat in MVP (open question O2 resolved by spec 011).
  - **In a shared wallet, members do not see each other's category lists** — a person's categories ride their user feed. When a member categorises a transaction, the server publishes a [Category Reference](#category-reference) onto that wallet's feed so every member can render it. Nobody can edit anyone else's category.

### Category Reference

A read-only copy of a person's category's display fields, published to a wallet so its members can render transactions categorised by someone else.

- **Context**: Wallets & Ledger
- **Role**: projection of Category
- **Persistence**: persisted projection — produced by the server, never authored
- **Sync**: yes — **wallet scope, read-only**
- **States**: live → tombstoned (withdrawn, category deleted, or wallet deleted); a re-publish revives the same record
- **Referenced by**: client rendering only; no server entity points at it
- **Notes**: carries `category_id`, `owner_user_id`, `kind`, `name`, `icon` and `color`; published only for a person's category, never for a platform one. The server **must never accept** one from a client (ADR-0008 forbids client-supplied `change_seq` and this resource has no write endpoint). It grants no rights: only the owner edits the underlying Category, through their own user feed.

### Transaction

A money-movement event — what the user actually records.

- **Context**: Wallets & Ledger
- **Role**: aggregate root
- **Persistence**: persisted
- **Sync**: yes — wallet scope
- **States**: `live` → `live (v+1)` on edit → `tombstoned` on delete → `live` again on an explicit undelete
- **Referenced by**: Posting, Occurrence (which one it confirmed into; the transaction carries read-only `occurrence_id` and `recurring_rule_id`), Report
- **Notes**:
  - Carries a calendar `date` (`YYYY-MM-DD`, no timezone), a positive `amount_minor` with its `currency`, an **optional** `description` (≤ 100 characters), an optional `memo` (≤ 1000), an optional **counterparty** (a free-text person name, for debts, ≤ 100), a `cleared` flag and `created_by` (never changes). `created_by` outlives its author: after the author's account is purged it is an identifier that resolves to nobody, and clients show "Former member" ([ADR-0024](adr/0024-account-deletion-keeps-shared-history.md)). `kind` is immutable.
  - A **transfer** is a Transaction whose two postings both reference wallets: money out of `wallet_id` into `counter_wallet_id`. It is one record, listed in both wallets and carried on both wallet feeds ([ADR-0015](adr/0015-transfers-on-two-wallet-feeds.md)).
  - The **counter wallet** (`counter_wallet_id`) is a transfer's destination; it is null for income and expense and never changes after creation.
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
- **States**: `active` ⇄ `paused`; `active` | `paused` → `ended` (final); any → `tombstoned` (final, no restore)
- **Referenced by**: Occurrence, Transaction (read-only `recurring_rule_id`)
- **Notes**:
  - The rule stores the IANA timezone captured from its author's profile at creation, so its schedule does not shift when a member in another timezone views it (ADR-0011). It never changes; "today" for the rule is the date in that timezone.
  - Carries the template (`kind`, `amount_minor` with `amount_basis`, `currency`, `category_id` or `counter_wallet_id`, texts, `auto_confirm`), the schedule (`frequency`, `interval`, `anchor_day`, `start_date`, and at most one of `end_date` and `occurrence_count`) and, for the gam'eya preset, `payout_round` and `payout_category_id`. `created_by` is the author and never changes.
  - جمعية and قسط are **presets of this rule** (`gameya`, `installment`; `custom` is any rule), not separate entities — creating `Gameya` or `Installment` entities would fragment the recurring engine. A preset constrains the shape of the rule; nothing is seeded.
  - A recurring transfer rule lives in its source wallet's scope only; the transactions it produces ride both wallets' feeds like any transfer (ADR-0015).

### Occurrence

One dated instance of a recurring rule, which a member confirms, skips or adjusts, or which the server confirms for an automatic rule.

- **Context**: Wallets & Ledger
- **Role**: child of Recurring Rule
- **Persistence**: persisted — **materialised ahead of time by the server only** ([ADR-0018](adr/0018-occurrences-materialised-by-the-server.md)), not computed on read and never created by a client
- **Sync**: yes — wallet scope
- **States**: `scheduled` → `confirmed` (producing one Transaction) | `skipped`; `skipped` → `scheduled` | `confirmed`; `confirmed` is final; any → `tombstoned`. **Due** (scheduled and dated on or before the rule's today) and **missed** (due while a later non-payout occurrence of the same rule is also due) are derived by readers and never stored.
- **Referenced by**: Transaction (the one it produced, read-only `occurrence_id`)
- **Notes**: materialised so the client can list and confirm upcoming items **offline**, and so per-occurrence state (confirmed, skipped, edited) has somewhere to live — a gam'eya round needs exactly that. An occurrence has no postings and never counts in a balance (P-05); only the Transaction it produces posts.
  - **Occurrence key** — (`recurring_rule_id`, `scheduled_date`, `is_payout`); unique among a rule's occurrences, live or tombstoned. It is what makes materialising idempotent.
  - **Materialise** — the server creating the occurrences of a rule's schedule up to its horizon (on a rule write, on the hourly job, on request); never done by a client.
  - **Horizon** — how far ahead occurrences are materialised: through the rule's today + 35 days, and at least the next one.
  - **Auto-confirm** — the server confirming a due occurrence of a rule with `auto_confirm: true`, as the rule's author, never before the day automatic confirmation took effect.
  - **Payout** (gam'eya) — the one income occurrence (`is_payout: true`) of a gam'eya rule, on the payout round's date, worth the sum of the scheduled rounds.
  - **Anchor day** — the day of month a monthly or yearly rule falls on, clamped to the month's last day; every date is computed from the rule's origin, never from the previous occurrence.

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
  - **Answers (spec 015, [ADR-0019](adr/0019-budget-spending-by-author-computed-on-read.md))**: spending is attributed by the transaction's `created_by` (a recurring rule's transactions: its author), in wallets the owner currently belongs to; a budget has one immutable currency and other currencies are listed, never summed; it names an expense category (own or platform, archived allowed) or none for all expenses; budgets may overlap; an archived category keeps counting; progress is served by Budgeting, computed on read, and no notification is sent.
  - Fields: `category_id` (nullable, immutable), `period_type` (`monthly`, immutable), `amount_minor` (the limit), `currency` (immutable), `archived_at`.

### Budget Progress

One budget's spent, remaining, over-limit flag and projection for one Budget Period.

- **Context**: Budgeting
- **Role**: derived read model
- **Persistence**: derived — computed on every read from postings; never stored or synced
- **Sync**: no
- **States**: `single-state`
- **Referenced by**: reports (B13)
- **Notes**: money members are `{amount_minor, currency}`; `projected_end` exists for the current Budget Period only; no percentage is returned.

### Budget Overrun

The record that one budget's spending went strictly above its limit in one Budget Period, kept so the person is told once.

- **Context**: Budgeting
- **Role**: child of Budget
- **Persistence**: persisted
- **Sync**: no — the client sees the resulting push
- **States**: `single-state`
- **Referenced by**: —
- **Notes**: at most one per budget, period start and threshold (100 in MVP); recorded by Budgeting the first time progress exceeds the limit and publishes `BudgetExceeded` from that insert ([ADR-0025](adr/0025-push-through-a-port-with-an-in-house-fcm-adapter.md)). Progress itself stays computed on read ([ADR-0019](adr/0019-budget-spending-by-author-computed-on-read.md)). Removed with its budget.

### Budget Period

The calendar window a budget applies to.

- **Context**: Budgeting
- **Role**: derived value
- **Persistence**: derived — computed from the budget's period type plus a date; not stored in MVP
- **Sync**: no
- **States**: `single-state`
- **Referenced by**: budget progress, reports and [Report Range](#report-range)
- **Notes**: a Budget Period containing a date is derived from the owner's [budget start day history](#profile); a period already begun keeps its dates after a start day change, and a transitional period may be shorter or longer than a month. **the bare word "Period" is never an entity name.** This is the budget window; a subscription's window is the *billing period*, which is a pair of fields on [Subscription](#subscription). Period membership is decided by the transaction's **calendar date**, so every member of a shared wallet sees identical totals regardless of timezone (P-12). Per-month rows with rollover are deferred.

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
- **Notes**: an invitation has a **channel**: `email` (sent to one address, accepted only by the account holding it) or `link` (a one-time link, accepted by anyone signed in who holds it). Its **token** is single-use, lives 7 days and is stored as a hash only. **Every close is a tombstone** (accepted, declined, revoked, expired, or the wallet's deletion) and erases the invited address. **Pending invitations count** toward the owner's `sharing.max_members_per_wallet`. Acceptance calls into Wallets & Ledger through its `MembershipService` to create the membership — Sharing never writes another module's models (P-02). Sharing is the **only** capability the free tier limits (P-09); the inviting wallet's **owner's** entitlements decide whether an invitation may be sent.

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
- **Notes**: **plans are data, not code.** A plan carries a period and its feature map. **No code anywhere compares a plan code** (P-09) — capability questions go to `EntitlementService`. The **default plan** (Free) is applied when no subscription governs; nobody subscribes to it (ADR-0013).

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
- **States**: `active` → `grace` → `expired`; `grace` | `expired` → `active` (on renewal, recovered payment or extension); `active` | `grace` → `revoked`, which is final (ADR-0013)
- **Referenced by**: Entitlement, Redeem Code
- **Notes**: every channel normalises into this one record — `provider` is `google_play`, `instapay` or `manual` (operator grants and redeem codes; never a payment) (ADR-0013, superseding ADR-0007). The **governing subscription** is, among a person's `active` and `grace` subscriptions whose grace has not passed, the one whose billing period ends latest (no end beats any end); with none, the default plan applies. The free tier has no subscription row. An `instapay` subscription is created at redemption of a claim-backed Redeem Code (`provider_ref` is the claim id, ADR-0023); `instapay` billing periods chain on early renewal, and one renewal reminder goes out 7 days before the end. It carries the **billing period** (`current_period_start`, `current_period_end`, `grace_until`); those fields are never called just "period". Losing a subscription **never deletes user data**; it blocks plan-gated sharing actions.

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

A visitor's claim that they paid a plan's InstaPay price, awaiting review.

- **Context**: Plans & Billing
- **Role**: aggregate root
- **Persistence**: persisted
- **Sync**: no — **website only**
- **States**: `submitted` → `approved` | `rejected` | `expired`
- **Referenced by**: Redeem Code
- **Notes**: **never submitted from inside the app** — Google Play policy forbids alternative payment there, so the app exposes only restore-purchases and redeem-code (P-10). Submission is public and web-only with a uniform answer whether or not the address has an account (ADR-0023). The amount must equal the plan's InstaPay price exactly; the reference is normalised; duplicate references are flagged and at most one claim per reference is ever approved. The address is encrypted with its lookup value beside it, the receipt image is encrypted and operator-only, and neither is ever logged. A claim expires after 7 days unreviewed; address and receipt are erased 90 days after it closes, and the record is deleted at 400 days.

### Redeem Code

A single-use code, issued when a claim is approved, that creates a subscription when redeemed.

- **Context**: Plans & Billing
- **Role**: aggregate root
- **Persistence**: persisted
- **Sync**: no
- **States**: `issued` → `redeemed` | `revoked` | `expired`
- **Referenced by**: Subscription (created on redemption)
- **Notes**: 16 characters of Crockford base32 shown `XXXX-XXXX-XXXX-XXXX`; only its SHA-256 hash is stored, and the plaintext exists only in the approval mail. Valid 30 days. Bound to the claim's address: redeemable by a verified account holding it. A claim-backed code creates an `instapay` subscription at redemption (ADR-0023); `manual` applies only to codes without a claim (none in the MVP). The in-app "enter code" surface is permitted because redemption is not a purchase (P-10).

### Idempotency Record

The stored answer for one `Idempotency-Key`, so a retry replays it.

- **Context**: Plans & Billing (operational)
- **Role**: aggregate root
- **Persistence**: persisted
- **Sync**: no
- **States**: `single-state`
- **Referenced by**: —
- **Notes**: held 24 hours; scope is the user, or one anonymous scope for public operations (InstaPay claim submission). Only `2xx` answers are stored. Holds the answer only and no secret.

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
- **Notes**: the server side of the mechanism is the `change_seq` counter on the **scope row** (a wallet or a user) plus the `change_seq` stamped on each syncable entity (ADR-0008). A cursor behind the scope's [Purge Horizon](#purge-horizon) (a tombstone it never saw was purged after **≥ 90 days**) is answered with an expiry error and the client fully resyncs that scope. A server-side cursor table would duplicate state the client owns and then disagree with it. **One per scope**: the user scope and each wallet; it is bound to the feed and the account that issued it, and signed so a client cannot forge or move one (spec 013).

### Change Row

One record on a change feed: its type plus the record exactly as its read returns it.

- **Context**: Sync
- **Role**: protocol value
- **Persistence**: derived — a read of the feed, never stored
- **Sync**: n/a
- **States**: `single-state`
- **Referenced by**: every feed page
- **Notes**: a deletion is a record with `deleted_at` set; there is no separate operation. The record is rendered by the same resource as the record's read endpoint, so a feed never carries a shape the read does not. Clients skip a `type` they do not know. A transfer is the identical Change Row on both of its wallets' feeds (ADR-0015).

### Sync Batch

One upload of 1–50 per-record writes, each answered by its own result.

- **Context**: Sync
- **Role**: protocol value
- **Persistence**: request-only — nothing of it is stored
- **Sync**: n/a
- **States**: `single-state`
- **Referenced by**: the client's offline write queue
- **Notes**: every item is exactly one per-record write (a Wallet, Category, Transaction or Profile upsert, a delete or a restore) and obeys exactly its rules; items are independent and applied in array order. Each item's result is `applied`, `conflict` (a stale `version`, with the current record) or `rejected`. The batch carries no idempotency key: it is idempotent because every item is (ADR-0016).

### Purge Horizon

Per scope, the highest feed position whose tombstone has been purged.

- **Context**: Sync
- **Role**: value on a scope row
- **Persistence**: persisted as `purged_change_seq` on the wallet or user row
- **Sync**: n/a
- **States**: `single-state`, raised only, never lowered
- **Referenced by**: Sync Cursor
- **Notes**: raised by the daily tombstone purge in the same transaction as the removal. A cursor at a position below it is expired (`410 SYNC_CURSOR_EXPIRED`); a cursor at or above it is served, however old, so a quiet scope never forces a resync.

### Scope Head

Per scope, the highest feed position committed in it.

- **Context**: Sync
- **Role**: protocol value
- **Persistence**: derived — for a wallet, the wallet's `change_seq` counter as it stands now; never stored apart from it
- **Sync**: n/a
- **States**: `single-state`, raised only
- **Referenced by**: Sync Position
- **Notes**: reported per wallet by `GET /sync/wallets/heads` (spec 017, ADR-0021) with the wallet's [Purge Horizon](#purge-horizon). A phone caught up on a wallet holds a [Sync Position](#sync-position) equal to the head; a greater head means the wallet's feed has news. Heads are positions within one scope and are never compared across scopes. There is no user-scope head on the wire.

### Sync Position

The integer position a feed page's `sync_cursor` resumes from.

- **Context**: Sync
- **Role**: protocol value
- **Persistence**: derived — stated in each feed page's `meta.sync_position`; client-held beside its [Sync Cursor](#sync-cursor)
- **Sync**: n/a
- **States**: `single-state`
- **Referenced by**: Scope Head
- **Notes**: the position of the page's last row (its `change_seq` on that feed; for a transfer into a wallet, the destination's stamp), or the incoming position when the page is empty (0 from the beginning). It exists so a client can compare its place in a scope with the [Scope Head](#scope-head) without parsing the cursor, which stays opaque. It is never sent back; the cursor is.

---

## Reporting

### Report

A computed view over the ledger — spending by category, net worth, a period comparison.

- **Context**: Reporting
- **Role**: derived value
- **Persistence**: **derived — a query, never a row**
- **Sync**: no
- **States**: `single-state`
- **Referenced by**: [Report Range](#report-range)
- **Notes**: reads Transaction, Posting sums, Category and Budget through their owning modules' query services (P-02). Totals are recomputed from postings and **never read `balance_minor`** (P-05). The viewer's timezone affects only default date ranges; period membership is by calendar date, so members of a shared wallet see identical figures. Spec 019 answers:
  - a **wallet report** counts every member's live transactions in that wallet, identical for every member (ADR-0011 rule 7);
  - a **cross-wallet report** covers the live wallets the caller currently belongs to, in one currency, with transfers between covered wallets cancelled;
  - balances are opening balance plus the sum of the wallet's postings, never the cache;
  - no share or percentage is sent, and `label` is a machine key (a platform `system_key`, a category id or a Budget Period start date), never display text;
  - planned figures come from scheduled occurrences and are kept apart from actual figures;
  - a report is computed on read, never cached or synced, and is not gated by a plan.

### Report Range

The inclusive span of calendar dates a report covers.

- **Context**: Reporting
- **Role**: derived value
- **Persistence**: **derived — never stored**
- **Sync**: no
- **States**: `single-state`
- **Referenced by**: [Report](#report)
- **Notes**: either the caller's [Budget Period](#budget-period) containing a date (today in the caller's profile timezone when none is given), or an explicit `from`/`to` of at most 366 days. Transactions belong to it by calendar date; the response echoes the resolved range. A *covered wallet* is a live wallet the caller currently belongs to (any role, archived included) in the report's currency; an *excluded wallet* is one in another currency, listed in `meta.excluded_wallets` and never converted.

---

## Notifications and Admin

Neither context owns any of the 21 named entities. Admin owns five entities added since: [Operator Notification](#operator-notification), [Operator Account](#operator-account) (with its [Recovery Codes](#recovery-code)), [Re-enrolment Request](#re-enrolment-request), [Notification Read Mark](#notification-read-mark) and [Anonymity Floor](#anonymity-floor).

- **Notifications** owns the [Push Registration](#push-registration) that binds a push address to a [Device Session](#device-session), the person's [Notification Preferences](#notification-preferences), and every [Push Notification](#push-notification) (with its Push Delivery children) it sends. See [ADR-0025](adr/0025-push-through-a-port-with-an-in-house-fcm-adapter.md).
- **Admin** owns admin accounts — a **separate guard**, with no role or admin flag on application users (P-08, ADR-0006) — and records admin actions through its `AdminAuditService` façade, which writes to Identity's one [audit log](#audit-entry). Admin **writes only through other modules' application services**, so back-office actions run the same invariants, authorization and events as app actions. Its screens (B12) are the overview, the payment-claim queue, account lookup, the plan editor and the audit log.

### Push Registration

Where one signed-in phone can be reached by push: the push address Firebase Messaging handed the app, tied to that phone's device session.

- **Context**: Notifications
- **Role**: aggregate root
- **Persistence**: persisted
- **Sync**: no — written by `PUT /me/devices/{id}`, never read back; the client receives the push
- **States**: `single-state` (deleted when it ends)
- **Referenced by**: Push Delivery
- **Notes**: one per [Device Session](#device-session), one per push token. The wire name is `push_token`; in user-facing text it is "push address", because "token" stays banned for the session credential. The token is sealed under Notifications' own keyring with a keyed lookup value beside it and is never returned, logged, queued, evented or audited. The registration is removed when its session ends for any reason, when FCM reports the token invalid, after 270 days without a refresh, and by the account purge. `{id}` of the endpoint is the caller's own current session id.

### Notification Preferences

One person's choice of which notification categories they hear about, and their quiet hours.

- **Context**: Notifications
- **Role**: aggregate root
- **Persistence**: persisted (a missing row means the defaults at version 1)
- **Sync**: no — `GET /me/notification-preferences`, read when the settings screen opens
- **States**: `single-state`
- **Referenced by**: —
- **Notes**: six categories (`recurring_due`, `budget_exceeded`, `sharing`, `billing`, `export_ready`, `deletion_reminder`), all on by default; quiet hours on from 22:00 to 08:00 in the profile timezone, on a quarter hour, start never equal to end. Versioned: a stale save is `409 VERSION_CONFLICT` with `current`, nothing is merged. Versioned without being syncable, by choice.

### Push Notification

One message for one person: its type, category, identifiers, per-person dedupe key and fate. Its child, the **Push Delivery**, is one attempt series of that message to one registration.

- **Context**: Notifications
- **Role**: aggregate root (Push Delivery: child)
- **Persistence**: persisted, 30 days
- **Sync**: no — never read by the client, which receives the push
- **States**: `pending` → `held` → `sending` → `sent` | `skipped` | `failed` (Push Delivery: `pending` → `sent` | `pruned` | `failed`)
- **Referenced by**: —
- **Notes**: the dedupe key is unique per person, so a redelivered event or a rerun pass creates nothing new. A skipped notification names its reason (`category_off`, `no_device`, `rate_limited`, `collapsed`, `inactive_account`, `disabled`, `summarised`). Holds identifiers only, never text, amounts, names or the token. Operators see counts, never records.

### Operator Notification

A back-office alert telling operators that something needing their attention happened to an account — the first kind is a deletion cancelled by a sign-in.

- **Context**: Admin
- **Role**: aggregate root
- **Persistence**: persisted
- **Sync**: no — operators see it in the back office
- **States**: `single-state`
- **Referenced by**: —
- **Notes**: carries identifiers only, **never an address**. Unique per source event, so a redelivered event produces no second notification. Per-operator read state arrives with operator accounts.

### Operator Account

A person who may use the back office: separate from every application [User](#user), on its own session guard.

- **Context**: Admin
- **Role**: aggregate root
- **Persistence**: persisted
- **Sync**: no — operators only, never through `/api/v1`
- **States**: `awaiting_password_change` → `awaiting_enrolment` → `active`; any non-deactivated state → `awaiting_password_change` (the reset command; an enrolled secret is kept); `active` → `awaiting_enrolment` (an approved re-enrolment); any → `deactivated`
- **Referenced by**: Re-enrolment Request, Audit Entry (actor and subject)
- **Notes**: a separate guard with no role on application users; every operator has the same authority in MVP. Email and display name are sealed at rest, the email found by a keyed lookup value. Operators are created only by the command line. Contains ten [Recovery Codes](#recovery-code).

### Recovery Code

One of ten single-use codes that stand in for the one-time code when an operator's device is unavailable. A value inside [Operator Account](#operator-account).

- **Context**: Admin
- **Role**: value inside Operator Account
- **Persistence**: persisted in the account (one keyed hash per code, with a used-at instant)
- **Sync**: no
- **States**: `unused` → `used`
- **Referenced by**: —
- **Notes**: shown once, when issued; never recoverable afterwards by anyone. A new set replaces the old one at once.

### Re-enrolment Request

An operator's request to replace a lost second factor, which needs approval by two other operators.

- **Context**: Admin
- **Role**: aggregate root
- **Persistence**: persisted
- **Sync**: no
- **States**: `open` → `completed` | `declined` | `expired` | `cancelled`
- **Referenced by**: Audit Entry (`admin_action` detail)
- **Notes**: two distinct approvers, neither the requester, each with a reason; open for 72 hours. Completion clears the requester's secret and recovery codes and ends their sessions.

### Notification Read Mark

The record that one operator has read one [Operator Notification](#operator-notification), and when.

- **Context**: Admin
- **Role**: aggregate root
- **Persistence**: persisted
- **Sync**: no — operators only, never through `/api/v1`
- **States**: `single-state`
- **Referenced by**: —
- **Notes**: identifiers and an instant only. Per operator: one operator reading a notification does not mark it read for another.

### Anonymity Floor

The smallest non-zero population count the back office shows exactly: 10. A smaller non-zero count is shown as "fewer than 10".

- **Context**: Admin
- **Role**: value
- **Persistence**: derived (a constant of the back office, not stored)
- **Sync**: no
- **States**: `single-state`
- **Referenced by**: —
- **Notes**: zero is shown as zero. It stops a small count from singling out a person.

---

## Cross-module access

Every entity has exactly one owner. Other modules reach it only through that module's application services, never its models (P-02).

| Reader | Reads | Through |
|---|---|---|
| Budgeting | Category, transaction totals | Wallets & Ledger query services |
| Sharing | Wallet, Wallet Member, Activity Entry | Wallets' `MembershipService` and `WalletActivity` (writes membership and activity on acceptance, role change, removal, leaving and plan demotion) |
| Reporting | Transaction, Posting sums, Category, Budget | owning modules' query services; event-fed projections allowed later |
| Notifications | Device Session, Profile, account state | Identity application services (`SessionStatus`, `RecipientProfile`) |
| Notifications | Occurrences needing a person | Wallets' `OccurrenceReminderQuery` |
| Notifications | Invitation addressee | Sharing's `InvitationQueryService` |
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
