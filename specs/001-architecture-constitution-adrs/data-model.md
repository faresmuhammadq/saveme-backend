# Data Model: Architecture Constitution & ADR Set

**Feature**: 001-architecture-constitution-adrs | **Date**: 2026-09-15

This feature produces documents, so Part A models the document entities. Part B lists the data conventions the ADRs impose on every later feature's schema. It is summarised here so plans can check against one list; the full reasoning is in [research.md](./research.md).

## Part A — Document entities

### Constitution

| Field | Rule |
|---|---|
| title | "Constitution" prefixed by the codename only as display text, never as an identifier |
| version | SemVer `MAJOR.MINOR.PATCH`; starts at `1.0.0` |
| ratified | `YYYY-MM-DD`; set once |
| last_amended | `YYYY-MM-DD`; ≥ ratified |
| principles | ordered list of Principle (≥ 1) |
| contexts | exactly 9 Bounded Context + Shared kernel description |
| quality_gates | list, each verifiable |
| constitution_check | procedure reference ([contracts/constitution-check.md](./contracts/constitution-check.md)) |
| out_of_scope | MVP out-of-scope list from the brief |
| governance | amendment, versioning, ratification, precedence |
| sync_impact_report | HTML comment at top listing version change and affected templates |

### Principle

| Field | Rule |
|---|---|
| id | `P-NN`, unique, never reused or renumbered |
| name | short title |
| status | `Active` \| `Retired (vX.Y.Z)` |
| rules | ≥ 1 statement using MUST / MUST NOT / MAY; each yes/no-checkable |
| rationale | 1–3 sentences |
| adrs | list of ADR numbers (may be empty only for P-02, P-13, P-14, P-17) |
| non_negotiable | boolean; `true` for every locked brief decision |

### Bounded Context

| Field | Rule |
|---|---|
| name | one of Identity, Wallets & Ledger, Budgeting, Sharing, Plans & Billing, Sync, Reporting, Notifications, Admin |
| module_dir | `app/Modules/{Identity,Wallets,Budgeting,Sharing,Billing,Sync,Reporting,Notifications,Admin}` |
| responsibility | one paragraph |
| owns | data/aggregates owned (see research R-2 ownership notes) |
| publishes | application services and domain events other modules may use |

### ADR

| Field | Rule |
|---|---|
| number | 4-digit, sequential, unique |
| title | imperative noun phrase |
| status | see state machine |
| date | `YYYY-MM-DD` of latest status change |
| context | problem and forces |
| decision | the chosen option, stated as rules |
| alternatives | ≥ 2, each with rejection reason |
| consequences | ≥ 1 positive and ≥ 1 negative |
| principles | ≥ 1 linked `P-NN` |
| supersedes / superseded_by | ADR numbers, bidirectional |
| resolves | open-question IDs (ADR-0004 → `O1`) |

**Status state machine**

```text
Proposed ──accept──▶ Accepted ──supersede──▶ Superseded by ADR-NNNN
    │                    │
    └──reject──▶ Rejected └──deprecate──▶ Deprecated
```

- Only `Proposed` ADRs may have their decision text edited.
- `Accepted → Superseded` edits only the status line and the `superseded_by` link.
- All 11 ADRs of this feature are created directly as `Accepted` on ratification.

### ADR Index (`docs/adr/README.md`)

A table of number, linked title, status and date. It MUST list every file in `docs/adr/` matching `NNNN-*.md`, with no missing and no extra entries.

### Constitution Check (per plan)

One row per Active principle: `P-NN | PASS/FAIL/N-A | justification`. `N-A` requires a reason. `FAIL` requires a Complexity Tracking entry or a plan change.

---

## Part B — Conventions the ADRs impose on feature schemas

### B.1 Column conventions (PostgreSQL)

| Concern | Convention | ADR |
|---|---|---|
| Primary key | `id uuid` (v7) | 002 |
| Foreign keys | `uuid`, constrained | 002 |
| Money | `<name>_minor bigint` + `currency char(3)` (one currency column per row unless amounts differ) | 003 |
| Calendar date | `date` | 011 |
| Instant | `timestampTz(6)` | 011 |
| Soft delete / tombstone | `deleted_at timestampTz(6) null` | 008 |
| Optimistic concurrency | `version integer not null default 1`, incremented on every write | 008 |
| Sync ordering | `change_seq bigint not null` on syncable entities; `change_seq bigint` counter on scope rows (`wallets`, `users`) | 008 |
| Flags/quotas | `jsonb` (plans only) | 007 |
| Forbidden | `float`, `double`, `decimal` for money; `timestamp` without tz; auto-increment PKs | 001/003/011 |

### B.2 Syncable entity (base shape)

| Field | Rule |
|---|---|
| id | client-generated UUIDv7 |
| scope | `wallet_id` or `user_id` |
| version | server-incremented; client echoes last seen |
| change_seq | server-assigned from scope counter, never client-supplied |
| created_at / updated_at | server UTC instants |
| deleted_at | null or UTC instant; tombstone retained ≥ 90 days |

**Lifecycle**

```text
(absent) ──PUT id, no row──▶ live(v1)
live(vN) ──PUT version=N──▶ live(vN+1)
live(vN) ──PUT version≠N──▶ 409 VERSION_CONFLICT (unchanged)
live(vN) ──DELETE version=N──▶ tombstoned(vN+1)
tombstoned(vN) ──PUT deleted_at=null, version=N──▶ live(vN+1)
tombstoned ──retention elapsed──▶ purged
```

### B.3 Ledger (ADR-004/005)

> **Superseded in part (2026-09-20).** ADR-0012 replaces ADR-0004: a posting references exactly one of a wallet or a **category** (XOR), and there are no `income`/`expense` nominal accounts. The Posting shape and worked mappings below reflect ADR-0004 as it stood on 2026-09-19; see [ADR-0012](../../docs/adr/0012-postings-wallet-and-category-accounts.md) for the rule in force. Everything else in this section still holds.

- **Transaction** (syncable, wallet scope): type ∈ {income, expense, transfer}, `date`, `amount_minor` > 0, `currency`, `wallet_id`, `counter_wallet_id` (transfer only), `category_id` (income/expense), note.
- **Posting** (internal, not syncable): `transaction_id`, `account_type` ∈ {wallet, nominal}, `account_ref` (wallet id or nominal code `income`/`expense`), `amount_minor` (signed), `currency`.
- **Invariants**:
  1. Σ postings per transaction per currency = 0.
  2. MVP: all postings of a transaction share one currency, and both wallets of a transfer share it.
  3. Postings are replaced atomically with the transaction write.
  4. Wallet balance = opening + Σ wallet postings. The cache is equal after every committed write, or it is repaired.
- **Wallet**: `type` enum (fixed; liability types are stored negative), `currency`, `opening_balance_minor`, `balance_minor` (cache), `change_seq`.

### B.4 Entitlements (ADR-007)

- **Plan**: `code` (data key, never compared in code), `period`, `features jsonb`.
- **Subscription**: `user_id`, `plan_id`, `provider` ∈ {google_play, instapay}, `provider_ref`, `status` ∈ {active, grace, expired, revoked}, period bounds.
- **Subscription status**:
  ```text
  active ─period end─▶ grace ─grace end─▶ expired
  active/grace ─refund/chargeback/admin─▶ revoked
  expired ─renewal/claim approved─▶ active
  ```
