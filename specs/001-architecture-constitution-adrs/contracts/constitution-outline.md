# Contract: Constitution Outline

The ratified `.specify/memory/constitution.md` MUST contain these sections in this order. Draft principle IDs are fixed here, and wording is finalised in `/speckit-constitution`.

```text
<!-- Sync Impact Report: version, principles added/changed, templates updated, follow-ups -->
# <Display name> Constitution
## Purpose & Precedence
## Core Principles                (P-01 … P-17)
## Bounded Contexts & Modules     (9 contexts + Shared kernel, ownership, dependency rule)
## MVP Scope Guard                (out-of-scope list)
## Quality Gates
## Constitution Check             (procedure; links contracts/constitution-check.md shape)
## Governance                     (amendments, versioning, ratification, ADR supersession)
**Version**: 1.0.0 | **Ratified**: YYYY-MM-DD | **Last Amended**: YYYY-MM-DD
```

## Draft principles

Every principle marked NN (non-negotiable) is a locked brief decision.

| ID | Name | Core rule (MUST / MUST NOT) | ADR | NN |
|---|---|---|---|---|
| P-01 | Ubiquitous language | "Wallet" is the only term for a money container; wallet type is a fixed enum with defined behaviour | 004 | ✓ |
| P-02 | Module boundaries | Code lives in `app/Modules/<Context>/{Domain,Application,Infrastructure,Http}`; a module MUST NOT use another module's Infrastructure (incl. Eloquent models) or Http; cross-module only via Application services or domain events; `Shared` holds only persistence-free value objects | — | ✓ |
| P-03 | Money | Integer minor units + ISO 4217 via `Money`; MUST NOT use float/decimal/decimal-string anywhere | 003 | ✓ |
| P-04 | Ledger integrity | Transactions produce postings summing to zero per currency; transfer = one transaction; liabilities stored negative; MVP single currency per transaction | 004 | ✓ |
| P-05 | Derived balances | Balance = opening + Σ postings; cache MUST NOT back invariants/authz/reports; negative balances MUST NOT be blocked | 005 | ✓ |
| P-06 | Identifiers | UUIDv7 PKs; client-generated for syncable entities; create = idempotent `PUT /{id}`; foreign-owned IDs → 404 | 002 | ✓ |
| P-07 | Sync & concurrency | Syncable entities carry `version`, `updated_at`, `deleted_at`, `change_seq`; stale version → whole-write 409 `VERSION_CONFLICT`; server never merges | 008 | ✓ |
| P-08 | Authorization | Wallet-scoped by member role × entitlements; every touched wallet checked; non-member → 404; only global role is admin (separate guard); matrix lives only in Wallets spec | 006 | ✓ |
| P-09 | Entitlements | Plans are data; all capability checks through `EntitlementService`; MUST NOT compare plan codes; owner's entitlements govern a wallet; free tier limited only on sharing; lapse never deletes data | 007 | ✓ |
| P-10 | Billing channels | In-app: Google Play Billing, restore purchases, enter code only; InstaPay claims web-only | 007 | ✓ |
| P-11 | Events | Domain events dispatched after commit; cross-module listeners queued and idempotent; events carry IDs not models | 009 | |
| P-12 | Time | Instants stored UTC (`timestamptz`); calendar dates `YYYY-MM-DD`; timezone applied only in read models; period membership by calendar date | 011 | ✓ |
| P-13 | Naming | Codename appears only in config/translations; never in namespaces, tables, packages | — | ✓ |
| P-14 | Localisation | Arabic + English, RTL-correct content, Western numerals (0–9) in all API output | — | ✓ |
| P-15 | Observability | JSON logs; `X-Request-Id` on every response; client correlation ID propagated to logs/jobs; secrets and financial payloads never logged | 010 | |
| P-16 | Quality gates | Pint, Larastan (level 7), Pest on PostgreSQL green; each introduced invariant has a test; Constitution Check completed | 001 | |
| P-17 | Scope guard | Plans MUST NOT introduce MVP out-of-scope capabilities without a constitution amendment | — | ✓ |

## Bounded context table (required columns)

| Context | Module dir | Owns | Publishes (services / events) |
|---|---|---|---|

Rows follow research R-2 ownership notes.
