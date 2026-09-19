# Data Model: Domain Glossary & Model Map

**Feature**: 002-domain-glossary-model-map | **Date**: 2026-09-20

Part A is the glossary's own structure. Part B is the entity index itself, designed here and transcribed into `docs/glossary.md` by the tasks. Part C holds the lifecycle state machines, Part D the prior-art mapping.

## Part A — the glossary's own entities

| Entity | Fields |
|---|---|
| **Glossary Entry** | canonical name · Arabic term (optional) · one-sentence definition · owning context · aggregate role (root / child of X / value) · persistence (persisted / derived / client-held) · syncable (yes+scope / no) · lifecycle states · inbound references · notes |
| **Banned Synonym** | forbidden term · canonical entity · external-boundary exception (yes/no) |
| **Arabic Term** | Arabic · English · kind (entity / type value / preset) |
| **Field Convention** | concept · fixed name · shape · governing ADR |
| **Prior-Art Mapping** | old name · new home or "dropped" · reason |

## Part B — the entity index

Contexts abbreviated: **Id** Identity · **W&L** Wallets & Ledger · **Bud** Budgeting · **Shr** Sharing · **Bill** Plans & Billing · **Syn** Sync · **Rep** Reporting · **Ntf** Notifications · **Adm** Admin.

| # | Entity | Arabic | Owner | Role | Persistence | Syncable | Referenced by |
|---|---|---|---|---|---|---|---|
| 1 | **User** | — | Id | root | persisted | no (auth data) | Profile, Device Session, Wallet Member, Subscription, Transaction (`created_by`), everything user-scoped |
| 2 | **Profile** | — | Id | child of User (1:1) | persisted | **yes — user** | — |
| 3 | **Device Session** | — | Id | child of User | persisted | **yes — user** | Notifications (push token binding) |
| 4 | **Plan** | — | Bill | root | persisted (reference data) | no (read endpoint) | Subscription, Redeem Code |
| 5 | **Plan Feature** | — | Bill | value inside Plan | persisted in the plan's feature map | no | Entitlement (derivation input) |
| 6 | **Subscription** | — | Bill | root | persisted | no (read endpoint) | Entitlement, Payment Claim, Redeem Code |
| 7 | **Entitlement** | — | Bill | derived value | **derived** — never stored | no | every capability check, via the entitlement service |
| 8 | **Payment Claim** | — | Bill | root | persisted | no (web-only surface) | Subscription (on approval) |
| 9 | **Redeem Code** | — | Bill | root | persisted | no | Subscription (on redemption) |
| 10 | **Wallet** | محفظة | W&L | root **and sync scope** | persisted | **yes — wallet** | Wallet Member, Transaction, Posting, Recurring Rule, Category Reference, Invitation |
| 11 | **Wallet Member** | — | W&L (written by Shr) | child of Wallet | persisted | **yes — wallet** | every authorization check |
| 12 | **Category** | — | W&L | root | persisted | **yes — user** | Posting, Budget, Category Reference, Transaction (via its category leg) |
| 13 | **Category Reference** | — | W&L | projection of Category | **derived**, server-produced | **yes — wallet, read-only** | client rendering only |
| 14 | **Transaction** | — | W&L | root | persisted | **yes — wallet** | Posting, Occurrence (on confirmation), Report |
| 15 | **Posting** | — | W&L | child of Transaction | persisted | **no — never exposed** | balance and category-total queries |
| 16 | **Recurring Rule** | جمعية / قسط as presets | W&L | root | persisted | **yes — wallet** | Occurrence |
| 17 | **Occurrence** | — | W&L | child of Recurring Rule | persisted (materialised, R-4) | **yes — wallet** | Transaction (on confirmation) |
| 18 | **Budget** | — | Bud | root | persisted | **yes — user** | Report |
| 19 | **Budget Period** | — | Bud | derived window | **derived** (R-5) | no | Budget progress, Report |
| 20 | **Invitation** | — | Shr | root | persisted | **yes — wallet** | Wallet Member (on acceptance) |
| 21 | **Sync Cursor** | — | Syn | protocol value | **client-held** (R-6); server side is `change_seq` on the scope row | n/a | every pull request |
| 22 | **Report** | — | Rep | derived | **derived** — a query, never a row | no | — |

**Counts**: 22 rows = the 21 named entities + Category Reference (new, from R-2).

- **11 syncable**, in exactly two scopes — no third scope exists:
  - **user scope (4)**: Profile, Device Session, Category, Budget
  - **wallet scope (7)**: Wallet, Wallet Member, Category Reference, Transaction, Recurring Rule, Occurrence, Invitation
- **7 not syncable, persisted**: User, Plan, Subscription, Payment Claim, Redeem Code, Posting, Plan Feature (a value inside Plan)
- **4 not persisted**: Entitlement, Budget Period, Report (derived), Sync Cursor (client-held)

### Cross-module access (P-02, FR-004)

| Reader | Reads | Through |
|---|---|---|
| Budgeting | Category, Transaction totals | `W&L` application query services |
| Sharing | Wallet, Wallet Member | `WalletService` (writes membership on acceptance) |
| Reporting | Transaction, Posting sums, Category, Budget | owning modules' query services; projections allowed later |
| Notifications | Device Session | `Id` application service |
| Admin | everything it displays | each owning module's application service, never its models |
| every module | Entitlement | `EntitlementService` only (P-09) |

## Part C — lifecycle states

```text
User          active ──deactivate──▶ pending_deletion ──30-day grace──▶ purged
Device Session active ──revoke──▶ revoked ; active ──expiry──▶ expired
Subscription  active ──period end──▶ grace ──grace end──▶ expired ──renew/claim──▶ active
              active|grace ──refund/chargeback/admin──▶ revoked
Payment Claim submitted ──▶ under_review ──▶ approved | rejected ; submitted ──▶ expired
Redeem Code   issued ──▶ redeemed | revoked | expired
Wallet        active ──archive──▶ archived ; either ──delete──▶ tombstoned
Wallet Member active ──revoke──▶ revoked (role change stays "active")
Category      active ──archive──▶ archived ; unused only ──delete──▶ tombstoned (R-9)
Transaction   live ──edit──▶ live(v+1) ──delete──▶ tombstoned ──undelete──▶ live
Recurring Rule active ──pause──▶ paused ──resume──▶ active ; active|paused ──end──▶ ended
Occurrence    scheduled ──confirm──▶ confirmed (→ Transaction) ; scheduled ──skip──▶ skipped
              scheduled ──date passes──▶ due ──ignored──▶ missed
Budget        active ──archive──▶ archived
Invitation    pending ──accept──▶ accepted (→ Wallet Member) | ──decline──▶ declined
              pending ──revoke──▶ revoked ; pending ──expiry──▶ expired
```

Every syncable entity additionally follows the tombstone lifecycle fixed by ADR-0008 (`deleted_at` set, `version` and `change_seq` bumped, retained ≥ 90 days, then purged).

## Part D — prior-art mapping

As designed in research R-11: User splits into User + Profile + Device Session; **Space** and **SpaceMember** are dropped in favour of wallet-scoped membership; Wallet, Category, Transaction, Posting and Budget carry over with the renames and scope changes recorded there. `BalanceAssertion` and `ImportBatch` remain deferred and map to nothing.

## Part E — what is NOT in the index

Listed in the glossary's "deferred, not built" section so nobody specs them by accident: BalanceAssertion, ImportBatch, FX/exchange-rate entities, savings goals, envelope allocations, BudgetPeriod rollover rows, investment valuation snapshots, AI entry-method fields.
