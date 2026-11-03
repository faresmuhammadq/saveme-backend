# ADR-0028: Balance adjustments are transactions of their own kinds

- **Status**: Proposed
- **Date**: 2026-11-03
- **Principles**: P-03, P-04, P-05
- **Supersedes**: —
- **Resolves**: correcting a wallet's current balance without rewriting its history (owner decision, 2026-11-03); the ADR-0004 follow-up "add the posting-derivation rules for `opening` and `adjustment` accounts"

## Context

A wallet's balance is `opening_balance_minor + Σ postings` ([ADR-0005](0005-derived-balance-with-cache.md), [ADR-0012](0012-postings-wallet-and-category-accounts.md) rule 11). The only way to correct it today is to edit the opening balance, which moves every past balance and leaves no record of when or why the figure changed. A person who finds the app is 250 short of their real cash wants to add or take away 250 *today*.

Forces:

- An adjustment must be dated, listed, editable and deletable like any other transaction, and must reach a shared wallet's members through the existing feeds.
- It is not spending and not income. It must never count against a Budget, a Shared Budget or the reports' income and expense figures.
- P-04 rule 2 (non-negotiable) says every posting references exactly one of a wallet or a category. ADR-0004 anticipated a nominal `adjustment` account, but ADR-0012 removed nominal accounts.
- Every spending and budget query already filters on `kind`, so a new kind is excluded from them by construction.

## Decision

1. A **balance adjustment** MUST be a Transaction of kind `adjustment_in` (raises the wallet's balance) or `adjustment_out` (lowers it). Amounts stay positive and the direction comes from the kind (P-03); the person never types a sign.
2. The other leg of an adjustment MUST be a posting to the **internal platform category** `balance_adjustment`, so P-04 rules 1 and 2 hold unchanged:
   - `adjustment_in`: category `−a`, wallet `+a`.
   - `adjustment_out`: wallet `−a`, category `+a`.
3. The internal category MUST have the fixed id `00000000-0000-7000-8000-00000000ad01`, MUST be created by a migration (never only a seeder), and MUST carry `categories.internal = true`. It MUST NOT appear in any platform category list, category feed, export, picker or budget; it MUST NOT be accepted as the `category_id` of a client write. The platform seeder MUST NOT retire it.
4. On the wire an adjustment MUST carry `category_id: null` and `counter_wallet_id: null`; a write that sends either is refused with `INVALID_ENUM_VALUE`. The server derives the category leg.
5. `kind` MUST stay immutable after creation, so an adjustment's direction never changes. Amount, date and notes stay editable; delete and restore follow the existing rules.
6. An adjustment MUST NOT join a Shared Budget, MUST NOT be the kind of a Recurring Rule or Occurrence, and MUST NOT count in any spending, income, category or budget figure.
7. A Report summary MUST report adjustments as their own figures, `adjustments_in` and `adjustments_out`, and `balance_end = balance_start + income − expenses + transfers_in − transfers_out + adjustments_in − adjustments_out` MUST hold.
8. Who may write an adjustment follows the Transaction rules (an owner or editor of the wallet; a viewer is refused).
9. This amends ADR-0012 rule 6 (`kind` is a derived cache): `adjustment_in` and `adjustment_out` are the two kinds whose category leg is internal.

## Alternatives Considered

### Edit the opening balance (status quo)

Rewrites history and leaves no record. **Rejected because** the owner asked for a current-balance correction that appears as an entry.

### Count adjustments as expense or income in a "Balance adjustment" category

Smallest change. **Rejected because** a correction would raise spending totals and use up budgets.

### Reuse the Other categories

No backend change. **Rejected because** adjustments would mix with real spending and could not be told apart in reports.

### A nominal `adjustment` account

What ADR-0004 anticipated. **Rejected because** ADR-0012 removed nominal accounts and rule 2 would need a new exception to a non-negotiable principle.

## Consequences

### Positive

- Balances, history and every existing spending query stay correct without new filters.
- P-04 holds as written; no principle changes.
- Shared wallets get adjustments through the existing wallet feeds.

### Negative

- One reserved category row and a flag every platform-category read must respect.
- A client that does not know the kinds shows an adjustment as an unknown row (read-only) until updated; the server balance stays correct.

### Follow-ups

- Fixing a wrong opening balance still uses the wallet edit; the app points to Adjust balance for current corrections.
