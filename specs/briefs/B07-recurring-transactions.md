# B7 · Recurring transactions

**Call with**: `/speckit-specify B7` · **Source**: the Phase B brief set, recorded 2026-09-25

## Description

A recurring rule (wallet, template transaction fields, frequency daily/weekly/monthly/yearly with interval and anchor day, start date, end date or occurrence count) generates occurrences. A scheduled job materialises due occurrences as real transactions daily; the client can also request `POST /recurring/{id}/materialise` for catch-up. Users can skip one occurrence, edit one occurrence, or edit the series from a date forward. Two named variants seeded as presets: gam'eya (monthly contribution, payout month) and installment (monthly, fixed count).

## Considerations

Generation must be idempotent (`occurrence_key = rule_id + due_date`). Decide whether occurrences are created ahead (visible as "upcoming") or only on due date — recommendation: create ahead as `planned` status so reports can show forecast, but exclude `planned` from balances. Month-end anchoring (31st in a 30-day month). Time zone of the user decides "today". Rules are syncable; occurrences are ordinary transactions with `recurring_rule_id`.

## Reconcile at specify time

The brief predates decisions ratified since. These collide with it; the ratified side wins unless the spec deliberately supersedes it through a decision record or an amendment.

| Brief says | Already decided | Resolution needed |
|---|---|---|
| "Occurrences are ordinary transactions" | Glossary: **Occurrence** is its own persisted, wallet-scoped syncable entity, a child of Recurring Rule, materialised rather than computed | Reconcile: an Occurrence that produces a Transaction, or a Transaction flagged as an occurrence — not both |
| `planned` transactions excluded from balances | P-05: balance = opening + Σ postings. A planned transaction that has postings is in the balance | Planned occurrences must not have postings until due |
| "Time zone of the user decides today" in a shared wallet | P-12 rule 4: period membership by calendar date; members in different timezones see identical totals | Decide whose timezone a shared wallet's rule runs in |
| Split amounts for gam'eya/installments | P-03 rule 4: splitting uses `Money::allocate()` | Required |

## Inherited from B5 (spec 011)

- **Lock order** (spec 011 FR-023, ADR-0014 rule 10): lock category rows first (`CategoryRepository::findForShare`, or update), then wallet rows, several wallets in ascending id. Never lock a wallet and then a category: a rename of a category republishes to its wallets and would deadlock with you.
- **Publish and withdraw**: a recurring rule that uses a person's category calls `CategoryReferences::publish` (and `withdrawIfUnused` when its last use in the wallet goes), exactly as B6 does.
- **Usage**: register a `CategoryUsage` participant counting live and tombstoned recurring rules (and the occurrences' transactions, through B6's). Answer `usedAmong` set-wise.
- **Refuse a reference to a tombstoned category**, and enforce R-02's "own category or platform category only". A platform category is referenced directly, never copied.
- **Archived categories**: same decision as B6 (recommended allowed).
- Reports built on rules group platform categories by `system_key`, always scoped (ADR-0014 rules 5-6).

## Inherited from B6 (spec 012)

- **Create transactions through `TransactionService`** (`save` with a client-generated or server-minted UUIDv7), never by writing the tables: it owns the postings, the balance recompute, the sequence stamps, the category references and the events. A confirmed occurrence is a transaction like any other, recorded as the rule's author (`created_by`).
- **Lock order** is `TransactionService`'s (spec 012 FR-011, R-4): categories first (share), then wallets in ascending id. A rule that fires into a shared wallet or makes a transfer follows it by calling the service.
- **Transfers** are one record with `counter_wallet_id` (ADR-0015): a recurring transfer rule needs T-02 in the source and T-03 in the destination at the moment it fires, and a destination that was deleted or left is a refusal to surface, not a retry.
- A deleted wallet tombstones its income and expense and the transfers whose other wallet is already deleted; a transfer to a live wallet stays live (spec 012 FR-015).

## Inherited from B14 (spec 013)

- **Recurring rules and occurrences join the wallet feed** (`GET /sync/wallets/{id}/changes`): add a source to Wallets' (or the rules module's) feed query with its own `type`, positioned by the wallet counter (`change_seq`, stamped under the wallet lock), rendered by the resource its read endpoint uses. They add a row to the batch type map only if they are whole-record writes (`PUT`/`DELETE`/restore); the batch is a transport for per-record writes (ADR-0016).
- Their tombstones are purged by `sync:purge-tombstones` after 90 days: register them with the wallet purge, raising the wallet's `purged_change_seq`, and keep a tombstoned rule that an occurrence still refers to.
