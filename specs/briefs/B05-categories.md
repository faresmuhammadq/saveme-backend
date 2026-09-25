# B5 · Categories

**Call with**: `/speckit-specify B5` · **Source**: the Phase B brief set, recorded 2026-09-25

## Description

Categories have kind (income/expense), name, icon, colour, archived flag, and belong to a wallet (default) or to the user as a personal template — decide one in `/speckit.clarify`; recommendation: per wallet, with the seeder copying the locale's default set into each new wallet. Nesting: one level (parent/child) if included, otherwise flat — resolve open decision O2 here. Endpoints: `GET /wallets/{id}/categories`, `PUT /wallets/{id}/categories/{cid}`, `PATCH …`, `POST …/archive`.

## Considerations

Seeded defaults in ar and en with stable `system_key` so reports can group across wallets ("Groceries" in wallet A equals "بقالة" in wallet B). Archiving, not deleting, once a category has transactions. Category is syncable.

## Owner decisions (2026-09-25)

- **Categories are user-scoped, plus a platform-owned set.** A user's own categories stay user-scoped, as the glossary and ADR-0012 fix. Alongside them the platform owns a shared default set — the seeded, localised categories with a stable `system_key` — which every user can use and nobody can edit.
- The spec must settle what "use" means for a platform category: whether picking one references it directly or copies it into the user's own set, how archiving works for something the user does not own, and how reports group by `system_key` across the two.
- This adds a new kind of category owner. The glossary's Category entry and ADR-0012's category postings must be updated in the same change, or superseded by a new decision record if the posting rule changes.

## Reconcile at specify time

The brief predates decisions ratified since. These collide with it; the ratified side wins unless the spec deliberately supersedes it through a decision record or an amendment.

| Brief says | Already decided | Resolution needed |
|---|---|---|
| Per-wallet categories (the brief's recommendation) | **Already decided the other way**: categories are user-scoped, ride the user feed, and shared wallets render a partner's category through the read-only Category Reference projection (spec 002, ADR-0012, glossary) | Not an open question. Re-deciding it needs a superseding ADR and reshapes spec 003's scope split |
| Endpoints under `/wallets/{id}/categories` | Follows from the above: categories live at the user level | Paths move to the user scope |
| Nesting, O2 | Still open | Resolve here as the brief says |
