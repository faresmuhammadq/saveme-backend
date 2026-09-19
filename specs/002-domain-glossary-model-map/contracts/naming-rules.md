# Contract: Naming Rules

Three tables that go into `docs/glossary.md` verbatim.

## 1. Banned synonyms

Left column MUST NOT appear in any namespace, class, table, column, route, event or user-facing string. The exception column marks terms permitted only when quoting an external system at its integration boundary.

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

## 2. Arabic terms

| Arabic | English | Kind | Where it lives |
|---|---|---|---|
| محفظة | Wallet | entity | the Wallet entity |
| حصالة | savings wallet | type value | `wallets.type = savings` |
| جمعية | rotating savings group (gam'eya) | preset | a Recurring Rule preset |
| قسط | installment | preset | a Recurring Rule preset |

Numerals are always Western (0–9) in API output, whatever the locale (constitution P-14).

## 3. Field conventions

The shapes that recur across entities. A feature MUST use these names, not variants.

| Concept | Name | Shape | Governing ADR |
|---|---|---|---|
| Identifier | `id` | UUIDv7; client-generated for syncable entities | ADR-0002 |
| Money | `<name>_minor` + `currency` | signed 64-bit integer minor units + ISO 4217 alpha-3 | ADR-0003 |
| Wallet balance | `balance_minor`, `opening_balance_minor` | as above; `balance_minor` is a cache, never truth | ADR-0005 |
| Calendar date | `date`, `<name>_date` | `YYYY-MM-DD`, no timezone | ADR-0011 |
| Instant | `<name>_at` | UTC, microsecond precision | ADR-0011 |
| Optimistic concurrency | `version` | integer, server-incremented, echoed by the client | ADR-0008 |
| Sync ordering | `change_seq` | server-assigned from the scope counter; never client-supplied | ADR-0008 |
| Soft delete | `deleted_at` | null or UTC instant; tombstone retained ≥ 90 days | ADR-0008 |
| Ownership | `user_id` / `wallet_id` | the scope key; not every entity is wallet-scoped | ADR-0008 |

Forbidden variants seen in prior art or likely to be invented: `amount_cents`, `total_minor`, `cached_balance`, `space_id`, `updated_time`, `is_deleted`, `rev`, `seq`.
