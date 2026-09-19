> **Prior art — not in force.**
>
> This is earlier planning work, recorded here because spec **A3 (domain glossary & model map)**
> must map its entities 1:1 onto the authoritative index. Where it disagrees with an Accepted
> ADR, the ADR wins. The paste that produced this file had its line breaks flattened; the
> structure was restored, the content was not changed.
>
> **Already reconciled (decisions taken 2026-09-20, see [specs/README.md](../../specs/README.md)):**
>
> | Point | Outcome |
> |---|---|
> | `Space` / `SpaceMember` | **Dropped.** Membership is wallet-scoped, roles `owner\|editor\|viewer` (ADR-0006) |
> | Posting hits wallet **or category** | **Adopted** — [ADR-0012](../adr/0012-postings-wallet-and-category-accounts.md) supersedes ADR-0004 |
> | `NUMERIC(19,4)` as an option for money | **Rejected.** Integer minor units only (ADR-0003) |
> | No `version` / `change_seq` / `deleted_at` | **Added** — required on every syncable entity (ADR-0008) |
> | `cached_balance`, `opening_balance` | **Renamed** to `balance_minor`, `opening_balance_minor` (ADR-0005) |
> | No user timezone | **Added** — `users.timezone`, IANA name (ADR-0011) |
> | `BalanceAssertion`, `ImportBatch` | **Stay deferred** — statement import is out of MVP scope (P-17) |
> | O1 open | **Closed** — postings (ADR-0012) |

---

# DATA_MODEL.md

> Entities & ledger design for **[App name]**. Read alongside `PROJECT.md` (esp. §7 decisions).

---

## Core modeling stance

1. **Derived balance** (D2): a wallet's balance is *computed* from its postings, never set
   directly. `cached_balance` is a recomputed-on-write copy for fast reads.
2. **Double-entry ledger** (D3): every `Transaction` has 2+ `Postings` that **sum to zero**.
   Each posting hits either a **wallet** (real money) or a **category** (income/expense
   bucket). The user never sees debits/credits — the UX collects one wallet + one category +
   amount, and the app generates the postings.
3. **Ledger vs presentation** (D4/D5): the ledger records what is true (including negative
   balances, negative-for-liability); the UI interprets it (flags, colors, "amount owed").
4. **Money** stored as **integer minor units** (e.g. piastres) or `NUMERIC(19,4)` — never float.

---

## Entities

> **MVP entities:** User · Space · SpaceMember *(seam)* · Wallet · Category · Transaction · Posting · Budget.
> **Deferred entities:** BalanceAssertion, ImportBatch *(both tied to statement import — deferred)*.

### User

Authenticated person.

- `id`
- `name`
- `email` (unique)
- `password`
- `locale` — `ar` | `en`
- timestamps
- Belongs to Space(s) via `SpaceMember`. MVP: exactly one.

### Space

Ownership + sharing boundary. Owns wallets, categories, budgets.

- `id`
- `name`
- `type` — `personal` | `shared`
- `base_currency` — ISO 4217 (e.g. `EGP`); MVP assumes all wallets match this
- timestamps
- MVP: auto-created per user, one only. Multi-member sharing deferred.

### SpaceMember *(seam — inactive in MVP)*

- `space_id`, `user_id`, `role` (`owner` | `member`)
- Present so sharing later does not re-plumb ownership. MVP writes exactly one `owner` row.

### Wallet

A container of money with a balance — the core primitive (the "pot of money").

- `id`
- `space_id`
- `name`
- `type` — enum: `cash` | `checking` | `savings` | `credit_card` | `loan` | `investment`
- `currency` — ISO 4217. **Immutable after first posting.**
- `opening_balance` — signed minor units (liabilities negative)
- `opening_date`
- `cached_balance` — derived; `opening_balance` + Σ postings for this wallet; recomputed on write
- `on_budget` — bool, defaulted by type, user-overridable
- `archived` — bool
- timestamps

> **Balance** = `opening_balance` + Σ(`posting.amount` where `posting.wallet_id` = this wallet).
> Asset/liability class and balance method **derive from `type`** — not stored separately.

### Category

Income/expense bucket — the budgetable and reportable dimension.

- `id`
- `space_id`
- `name`
- `kind` — `income` | `expense`
- `parent_id` — nullable (sub-categories; nesting can be deferred)
- `icon`, `color` — nullable (UI)
- `archived` — bool
- timestamps

In the ledger a category is the "other side" of every wallet posting. It is not
currency-specific — the posting carries the currency.

### Transaction

A money-movement event — what the user records.

- `id`
- `space_id`
- `date`
- `description`
- `memo` — nullable
- `kind` — derived cache: `expense` | `income` | `transfer`
  (transfer = all postings reference wallets, none reference a category)
- `cleared` — bool (reconciliation UX; default `false`)
- `import_batch_id` — nullable (deferred; link to statement import)
- `created_by` — user_id
- timestamps
- Has 2+ Postings that sum to zero.

### Posting

The double-entry leg — the ledger row.
**Single source of truth for both wallet balances and category totals.**

- `id`
- `transaction_id`
- `wallet_id` — nullable  ⎫ exactly **one** of these two is set (XOR)
- `category_id` — nullable ⎭
- `amount` — signed minor units: effect on the target's balance
- `currency` — matches the wallet's currency (MVP = space base currency throughout)

> **Invariant:** within one transaction, Σ `amount` = 0.
> (Per currency. MVP has one currency per transaction.)

### Budget

A per-category monthly spending limit.

- `id`
- `space_id`
- `category_id`
- `period` — `monthly` (only value in MVP)
- `amount` — limit in space base currency, minor units
- timestamps

> **Actual** for a period = Σ `posting.amount` for that category within the period dates.
> **Remaining** = `amount` − actual.
> Future extension: per-month `BudgetPeriod` rows with rollover support.

### BalanceAssertion *(status: deferred — activates with statement import)*

Reconciliation checkpoint. "On `date`, this wallet's balance = `asserted_balance`."

- `id`
- `wallet_id`
- `date`
- `asserted_balance` — minor units
- `source` — `statement_import` | `manual`
- timestamps

> Derived balance up to `date` must equal `asserted_balance`. A mismatch means a
> missing or duplicated transaction. Also useful as **manual reconciliation** — the
> user enters their real bank balance to catch forgotten manual entries.

### ImportBatch *(status: deferred — activates with statement import)*

One statement-import run (for review, undo, deduplication).

- `id`, `space_id`, `wallet_id`
- `source_filename`
- `status` — `pending` | `confirmed` | `discarded`
- `row_count`
- `imported_at`, timestamps
- Transactions created from it carry `import_batch_id`.

---

## Wallet type → behavior table

| type          | class     | default on_budget | balance method               | notes |
|---------------|-----------|:-----------------:|------------------------------|-------|
| `cash`        | asset     | ✅ | derived                                 | negative → likely a missing income entry; show louder cash-specific warning |
| `checking`    | asset     | ✅ | derived                                 | current account (حساب جاري); may overdraft → negative allowed |
| `savings`     | asset     | ✅ | derived                                 | حساب توفير |
| `credit_card` | liability | ✅ | derived                                 | stored negative internally; UI shows positive "amount owed" |
| `loan`        | liability | ❌ | derived                                 | long-term borrowing; off-budget by default |
| `investment`  | asset     | ❌ | valuation snapshot *(deferred)*         | value floats with market; not transaction-derived; off-budget |

---

## Sign convention & worked examples

`amount` = signed effect on the target.
The **source** side is negative (money leaves); the **destination** side is positive
(money arrives). The user never types signs — the app generates both legs.

**Expense — Coffee 80 EGP from Checking**

```
Checking  (wallet)            −80    ← money leaves the account
Dining    (expense category)  +80    ← goes into the spending bucket
                               Σ 0
```

**Income — Salary 5,000 EGP into Checking**

```
Salary    (income category)  −5,000  ← the source the money comes out of
Checking  (wallet)           +5,000  ← lands in the account
                               Σ 0
```

*An income category is a source (negative); an expense category is a destination
(positive). Mirror images of each other. The user types "Salary · 5,000 · into
Checking" and the app writes both signed lines.*

**Transfer — Move 1,000 from Checking to Savings**

```
Checking  (wallet)  −1,000
Savings   (wallet)  +1,000
                      Σ 0
```

Both legs are wallets → `kind = transfer` → excluded from spending and income reports.

**ATM withdrawal 500 (cash-aware)**

```
Checking  (wallet)  −500
Cash      (wallet)  +500    ← transfer; Cash is now a real wallet to spend from
                      Σ 0
```

Later: Coffee from Cash → Cash −80 / Dining +80. The ATM step is never miscounted as dining.

**Credit card spend — Shoes 500 EGP**

```
CreditCard  (wallet, liability)  −500   ← more negative = owe more
Shoes       (expense category)   +500
                                   Σ 0
```

**Credit card payment — Pay 300 from Checking**

```
Checking    (wallet)  −300
CreditCard  (wallet)  +300   ← less negative = owe less
                        Σ 0
```

Both wallet legs → transfer → not counted as spending.

---

## Derived quantities — everything is a query over postings

| Quantity | Formula |
|----------|---------|
| Wallet balance | `opening_balance` + Σ postings where `wallet_id` = this wallet |
| Category total (period) | Σ postings where `category_id` = this category AND date in period |
| Net worth (space) | Σ `cached_balance` across all wallets (assets +, liabilities already −) |
| Budget remaining | `budget.amount` − category total for current month |
| Left to spend (month) | Σ on-budget `budget.amount` − Σ on-budget spending this month |

Per-category spending is computed the **same way** as wallet balances — by summing
postings — just filtered by `category_id` instead of `wallet_id`. The postings table
is the single source of truth for both axes.

---

## Invariants — enforce in the domain/service layer

1. Postings within a transaction sum to zero (per currency; MVP = one currency per transaction).
2. Each posting sets exactly one of `wallet_id` / `category_id` — never both, never neither.
3. `wallet.currency` is immutable once any posting references it.
4. `wallet.cached_balance` must equal `opening_balance` + Σ its postings; recompute on every write.
5. The ledger never rejects a posting because it would make a balance negative — that is a
   presentation concern (warn, color, flag), not a write-time constraint.
6. Liabilities are stored negative internally; the UI presents them as positive "amount owed".
7. A transaction whose postings all reference wallets (no category leg) is `kind = transfer`
   and is excluded from all spending and income aggregations.

---

## Deferred model extensions (additive — no breaking migrations anticipated)

- **AI-assisted logging (text & speech):** LLM parses natural-language or voice input into a
  draft Transaction the user confirms. No new core entities — output is a normal Transaction +
  Postings. Optionally add `entry_method` (`manual` | `text` | `speech`) and `raw_input` on
  Transaction for audit and re-parse.
- **Statement import:** activate `ImportBatch` + `BalanceAssertion`; parallel ingestion route.
- **Manual reconciliation:** activate `BalanceAssertion` with `source = manual`; user enters
  real bank balance to surface forgotten entries. Earlier fast-follow than full import.
- **Multi-currency / FX:** transfer legs in different currencies; store both amounts +
  `exchange_rate`; relax invariant #1 to per-currency balancing. `currency` already exists
  per posting so this is additive.
- **Investment valuation:** `investment` wallets get periodic valuation snapshots instead of
  ledger sums. Separate snapshot table; balance method swaps by type.
- **Envelope / zero-based budgeting:** allocation entities layered on top of categories. The
  forward hard-limit ("you cannot assign more than you have") legitimately lives here —
  distinct from the ledger's no-blocking rule (invariant #5).
- **Per-month budgets + rollover:** `BudgetPeriod` rows (category, year-month, amount, rollover)
  replacing the single recurring `Budget.amount`.
- **Recurring/subscription detection:** derived from posting patterns; no schema change.
- **Household sharing:** activate `SpaceMember`; scope all queries by space membership.

---

## Open decisions

- **O1 — Full postings ledger (this doc) vs. lighter two-column single-entry model**
  (`from_wallet_id` / `to_wallet_id` + `category_id` on Transaction). Postings recommended:
  supports split transactions and FX uniformly; two-column model caps at two legs and needs a
  migration when FX or splits arrive.
- **Category nesting (sub-categories) in MVP or deferred.**
