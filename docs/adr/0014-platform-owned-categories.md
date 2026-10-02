# ADR-0014: Platform-owned categories beside user-scoped ones

- **Status**: Accepted
- **Date**: 2026-10-02
- **Principles**: P-01, P-04, P-07
- **Supersedes**: — (extends [ADR-0012](0012-postings-wallet-and-category-accounts.md); no rule of it changes)
- **Resolves**: O2 (category nesting: flat in MVP)

## Context

[ADR-0012](0012-postings-wallet-and-category-accounts.md) makes a category the counter-leg of a wallet posting, and the glossary makes categories **user-scoped**: each person has one set, reused across their wallets, riding their user feed; a shared wallet renders a partner's category through the read-only Category Reference.

Brief B5 asked for seeded defaults in Arabic and English with a stable `system_key`, so reports can treat "Groceries" in one place and "بقالة" in another as the same bucket. On 2026-09-25 the owner decided: categories stay user-scoped, **plus** a platform-owned default set every user can use and nobody can edit. That adds a second kind of category owner, which ADR-0012 did not foresee, and leaves three questions the owner asked spec 011 to settle: what "use" means for a platform category (reference or copy), how archiving works for something a person does not own, and how reports group across the two.

Forces:

- A posting has one `category_id` (ADR-0012 rule 3). Whatever a platform category is, a posting must be able to point at it with that one column.
- Offline-first (P-06, P-07): two devices using the same default offline must not produce two records the server would have to merge.
- Localisation (P-14): the defaults' names must follow the app language; a person's own names never change.
- A category any posting references is never hard-deleted (glossary; ADR-0012 consequences).

## Decision

1. A Category MUST be owned by exactly one of: **a person** (user-scoped, syncable, as before) or **the platform**. The two kinds MUST share one identifier space, so a posting's category leg, a recurring rule and a budget each reference either with one column.
2. A platform category MUST carry a `system_key`: unique, lowercase, never renamed and never reused. Its identifier MUST NOT change once seeded. A person's category MUST NOT carry a `system_key`.
3. Using a platform category MUST mean referencing it directly. The server MUST NOT copy a platform category into a person's set, eagerly or on first use.
4. Platform categories MUST NOT be created, edited, archived or deleted through the API. The platform changes them only as seeded reference data. An entry the platform withdraws MUST be **retired** (marked archived), never deleted, so every reference stays valid.
5. ADR-0012's rules hold unchanged for both kinds: a posting references exactly one of a wallet or a category (rule 3); a category total is `Σ postings.amount_minor WHERE category_id = …` (rule 11). For a platform category that sum MUST always be restricted to a scope (a wallet, or one person's wallets); no report, budget or invariant may sum a platform category across all users.
6. Reports MUST group platform categories by `system_key` and a person's categories by their identifier. A person's category MUST NOT be merged into a platform one by name.
7. A Category Reference MUST be published only for a person's category. A platform category needs none: every client holds the platform set.
8. A category's `kind` MUST NOT change after creation, for either owner: it fixes which side of a posting the category stands on (ADR-0012 rule 5), and changing it would silently invert history.
9. Categories MUST be flat in MVP (O2). The reserved parent link stays empty and unexposed.
10. Whenever a write locks both category rows and wallet rows, it MUST lock the category rows first and wallets in ascending identifier order, so republishing a renamed category and recording a transaction cannot deadlock.

## Alternatives Considered

### Copy the default set into each person's categories (eagerly at sign-up, or on first use)

Each person gets their own rows carrying the `system_key`. **Rejected because** names freeze in the language of the copy, a person could rename "Groceries" while it still groups as groceries, two offline devices copying the same default create duplicates the server may not merge (P-07 rule 4), and a fix to the set never reaches existing copies.

### Defaults per wallet (the brief's original recommendation)

**Rejected because** it reverses the ratified user-scoped decision (glossary, spec 002) and would require superseding ADR-0012's consequences and spec 003's scope split, against the owner decision of 2026-09-25.

### A separate table and a second posting column for platform categories

**Rejected because** a posting would then have three optional targets, breaking the exactly-one CHECK of ADR-0012 rule 3 in spirit and in SQL, for no gain over one identifier space.

### Let a person archive (hide) a platform category

**Rejected for MVP because** archiving changes the record, which the person does not own. A per-person hide list is a separate, additive record if it is ever needed.

## Consequences

### Positive

- One row per default, shared by everyone: cross-wallet and cross-person grouping by `system_key` is a plain group-by.
- Names in both languages travel with the set; a language switch works offline.
- Retiring or fixing a default reaches every user at once, with no migration of copies.
- ADR-0012 stays in force word for word.

### Negative

- A platform category's total is meaningless without a scope; every query over it must carry one (rule 5), and a careless query would sum strangers' money. Tests must guard it in B8 and B13.
- People cannot hide defaults they dislike until a hide list is built.
- The lock-order rule (10) binds every later feature that touches categories and wallets in one transaction.

### Follow-ups

- Spec 011 builds the platform set (seeded), user categories, and the Category Reference.
- B6 and B7 follow rule 10 and publish references for persons' categories; B8 decides budget scope for platform categories; B13 groups by `system_key`.
- The constitution's P-04 `ADRs` line lists this record.
