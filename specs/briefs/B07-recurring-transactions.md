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
