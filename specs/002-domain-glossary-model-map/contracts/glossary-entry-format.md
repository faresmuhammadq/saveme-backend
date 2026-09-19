# Contract: Glossary Entry

Every entity in `docs/glossary.md` is written in this shape. No field may be left blank; "—" is allowed only where the row below says it is.

```markdown
### <Canonical Name>  <!-- Arabic term in parentheses if the UI shows one -->

<One sentence: what it is, in product terms, not storage terms.>

- **Context**: <one of the nine>  <!-- plus "written by X through <Service>" if another module writes it -->
- **Role**: aggregate root | child of <Entity> | value inside <Entity> | derived value | protocol value
- **Persistence**: persisted | derived (never stored) | client-held
- **Sync**: no | yes — wallet scope | yes — user scope | yes — wallet scope, read-only
- **States**: <state> → <state> → … | single-state
- **Referenced by**: <entities that may point at it, or "—">
- **Notes**: <rules a builder must know before touching it; links to the governing ADR>
```

## Rules

- **Context** is exactly one of: Identity, Wallets & Ledger, Budgeting, Sharing, Plans & Billing, Sync, Reporting, Notifications, Admin. Never two.
- **Sync = yes** requires a scope, and the entity carries `version`, `change_seq`, `updated_at`, `deleted_at`. Only two scopes exist: wallet and user.
- **Sync = no** on a persisted entity requires the Notes to say how the client gets it (read endpoint, or not at all).
- **States** lists a finite set with arrows. An entity with one state writes `single-state` rather than inventing filler.
- **Referenced by** lists inbound references only; outbound ones belong to the referring entity's row.
- **Notes** must cite the ADR for any rule it restates (e.g. "postings reference wallet XOR category — ADR-0012").
- Entries are alphabetical within their context section, and the contexts follow the constitution's order.
- No column types, endpoints, payload shapes or permission matrices (FR-016, FR-017).

## Required document sections

`docs/glossary.md` contains, in order:

1. Purpose and precedence — what this document decides, and that a conflict with an ADR is resolved by a superseding ADR, never by editing an entry.
2. How to read an entry — this format.
3. The entity index — a summary table of all entries, then one entry per entity grouped by context.
4. Arabic terms — the term table from `naming-rules.md`.
5. Banned synonyms — the table from `naming-rules.md`.
6. Field conventions — the table from `naming-rules.md`.
7. Prior-art mapping — the eight `DATA_MODEL.md` entities.
8. Deferred, not built — entities that exist only for out-of-scope features.
