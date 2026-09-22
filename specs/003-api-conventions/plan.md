# Implementation Plan: API Conventions

**Branch**: `003-api-conventions` | **Date**: 2026-09-22 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/003-api-conventions/spec.md`

## Summary

Write the HTTP contract every endpoint in this API obeys, in two places: a human-readable `docs/api-conventions.md` beside the glossary, and the machine-readable OpenAPI 3.1 skeleton at `docs/openapi/` that every feature spec extends by reference.

The contract fixes: the base path `/api/v1`; JSON only; one success envelope `{ data, meta }`; RFC 9457 problem details for every failure, carrying a stable `code`, the request id, JSON-Pointer validation entries and — on a version conflict — the record's current state; one versioned error catalogue that is a machine-readable file rather than a table someone retypes; opaque cursor paging with one filter and sort grammar; the syncable-resource envelope with a **generalised scope key**, because four of the eleven syncable entities are user-scoped rather than wallet-scoped; money as `amount_minor` + `currency` with coercion refused; calendar dates separated from instants by field name; and the header set for localisation, idempotency, rate limiting, client-version gating and deprecation.

The design work is in [research.md](./research.md): 27 decisions, of which the consequential ones are the opaque cursor replacing the integer the superseded 001 contract exposed (R-12), JSON Pointer with per-entry codes for validation failures (R-5), retryability as a catalogue column rather than a status-class rule (R-7), and the `401`/`403`/`404` discipline written once so no feature re-decides whether a non-member sees a forbidden or a not-found (R-24).

Documentation only. No application code, no migrations, no dependencies.

## Technical Context

**Language/Version**: N/A — Markdown and YAML. (The service it describes is PHP 8.4 / Laravel.)

**Primary Dependencies**: none added. YAML validation in [quickstart.md](./quickstart.md) uses `symfony/yaml`, already present as a transitive dependency.

**Storage**: N/A for the deliverables. Two pieces of server-side state the contract depends on — the idempotency record and the client-version floor — are specified in [data-model.md](./data-model.md) §9 and §10 and built by the first feature that needs them.

**Testing**: no automated tests; no PHP file changes. Validation is the structural checks and five reviewer exercises in [quickstart.md](./quickstart.md), matching how specs 001 and 002 were verified.

**Target Platform**: repository documentation, read by the Android client team, the landing-page team, backend contributors and planning agents.

**Project Type**: contract and schema skeleton for a modular-monolith web service.

**Performance Goals**: N/A.

**Constraints**: must not contradict constitution v1.1.0 or any Accepted ADR; must not restate the glossary's field-convention table (FR-024) or the wallet permission matrix (P-08 rule 5); must not introduce a dependency without the owner's approval; every convention must be checkable against a single endpoint definition (FR-034).

**Scale/Scope**: 2 deliverable documents plus 6 YAML files; 28 response-level error codes and 11 field-level codes; 11 syncable resources across 2 scopes; 13 request and response headers; 1 filter grammar with 7 operators.

## Constitution Check

*Constitution v1.1.0 (`.specify/memory/constitution.md`). GATE: before Phase 0 research, re-checked after Phase 1 design.*

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | Scope keys are `wallet_id` / `user_id`; checklist item F4 rejects `space_id` and the other banned variants; no synonym for Wallet appears in a schema, path or tag |
| P-02 Module boundaries | PASS | OpenAPI tags are the nine bounded-context names; one path file per feature, owned by that feature's module. The contract introduces no namespace and no cross-module reach |
| P-03 Money | PASS | `Money` schema is integer `<name>_minor` + ISO 4217 (R-16); decimal strings and floats are refused with `AMOUNT_NOT_INTEGER`, never coerced; integers bounded to ±2^53−1 for the website client |
| P-04 Ledger integrity | PASS | Postings have no wire schema at all — data-model §6 rule and checklist D5 (P-04 rule 6). Nothing in the contract exposes a posting under another name |
| P-05 Derived balances | PASS | `balance_minor` is documented on the wire as the cached value with the glossary's wording; liability balances are returned negative and presented as owed by the client |
| P-06 Identifiers | PASS | Create is `PUT /api/v1/<collection>/{id}` with a client-generated UUIDv7 (R-9); a foreign id is `404` with nothing disclosed; a non-v7 id is `422 INVALID_ID` |
| P-07 Sync and concurrency | PASS | `SyncableResource` carries `version`, `change_seq`, `updated_at`, `deleted_at`; stale writes are `409 VERSION_CONFLICT` with the whole `current` record including tombstones; the server merges nothing (R-8, R-10) |
| P-08 Authorization | PASS | R-24 fixes `401` / `403` / `404` once: non-member gets `404`, `403` only for an under-privileged member. The permission matrix itself is not restated here — it stays in the Wallets spec |
| P-09 Entitlements | PASS | Entitlement denial is its own code `FEATURE_NOT_ENTITLED`, distinct from role denial; no plan code appears anywhere on the wire |
| P-10 Billing channels | PASS | Idempotency applies to exactly three operations, and the InstaPay claim among them is recorded as website-only; the in-app surface named in the contract is restore and redeem only |
| P-11 Events | N-A | The contract defines HTTP shapes. No domain event is dispatched, declared or described |
| P-12 Time | PASS | Calendar dates are `format: date`, instants are UTC `date-time`; the field-name rule (`date` / `<name>_date` versus `<name>_at`) makes the split reviewable, with `DATE_NOT_CALENDAR_DATE` and `INSTANT_NOT_UTC` as refusals (R-17) |
| P-13 Naming | PASS | No codename in a path, schema name, tag, `operationId` or problem `type` URI. The `type` base is configuration (R-4) |
| P-14 Localisation | PASS | `Accept-Language` drives `detail` only; `Content-Language` reports the language actually used; `title`, `code`, field names and enum values are never localised; Western numerals in all output (R-18) |
| P-15 Observability | PASS | `X-Request-Id` on every response including errors, repeated as `request_id` in every problem body; `X-Correlation-Id` validated then echoed or replaced (R-23). The never-log list is untouched |
| P-16 Quality gates | N-A | No PHP file changes, so Pint, Larastan and Pest do not apply. Validation is the quickstart's structural checks and reviewer exercises. Gate 4 ("feature tests cover the HTTP contract of every new endpoint") binds the features that extend this skeleton, not this feature, which defines no endpoint |
| P-17 Scope guard | PASS | Nothing from the MVP Scope Guard is introduced. Batch writes, backward paging and total counts are explicitly excluded (data-model §13) |

**Post-design re-check**: unchanged after Phase 1. Two design-time additions were checked separately.

1. **The opaque cursor** (R-12) replaces the raw `change_seq` integer the superseded 001 contract put on the wire. Checked against P-07 and ADR-0008: the ADR's normative rules fix the *query* (`change_seq > :cursor`, gap-safe by the scope lock) and the cursor's *semantics*, not its wire encoding. The opaque token still encodes exactly that watermark, so the guarantees in ADR-0008 rule 16 are unchanged. No ADR amendment is needed, and the divergence is from a superseded spec artifact, not from an Accepted decision.
2. **Required `X-Client-Platform` and `X-Client-Version` headers** (R-21). Checked against P-13 (no codename in a header value — the platform values are `android` and `web`) and P-15 (the headers join the correlation set and carry nothing sensitive).

No `FAIL` on any principle, so Complexity Tracking stays empty.

## Project Structure

### Documentation (this feature)

```text
specs/003-api-conventions/
├── plan.md              # This file
├── research.md          # Phase 0: 27 decisions (R-1 … R-27) with rejected alternatives
├── data-model.md        # Phase 1: the wire shapes + the full initial error catalogue
├── quickstart.md        # Phase 1: structural checks + five reviewer exercises
├── contracts/
│   ├── openapi-skeleton-layout.md    # the file tree, what each file holds, how a feature extends it
│   ├── wire-shapes.md                # normative JSON for envelope, problem, conflict, money, dates
│   ├── collection-grammar.md         # cursor, limit, filter, sort, refusals, paging guarantees
│   └── endpoint-review-checklist.md  # FR-034: 49 checks a reviewer applies to one endpoint
├── checklists/requirements.md
└── tasks.md             # Phase 2 (/speckit-tasks — not created here)
```

### Deliverables (repository root)

```text
docs/api-conventions.md                     # the human-readable contract
docs/openapi/openapi.yaml                   # root: info, servers, security, tags, paths index
docs/openapi/error-catalog.yaml             # ErrorCode + FieldErrorCode enums + x-error-catalog
docs/openapi/components/schemas.yaml        # Envelope, Problem, SyncableResource, Money, …
docs/openapi/components/parameters.yaml     # Cursor, Limit, Filter, Sort, ResourceId
docs/openapi/components/headers.yaml        # X-Request-Id, RateLimit-*, Sunset, Allow, …
docs/openapi/components/responses.yaml      # one reusable problem response per status
docs/openapi/paths/                         # empty here; one file per feature from now on

specs/001-architecture-constitution-adrs/contracts/http-conventions.md  # gains a superseded banner
specs/README.md                             # A2 row → done
```

**Structure Decision**: the contract lives in `docs/`, not in `specs/`, for the same reason the glossary does — the Android and landing-page teams read `docs/` and have no reason to open a spec folder, and this is living reference rather than a record of one moment. `docs/` already exists, so no new base folder. The OpenAPI document is split across `components/` and `paths/` so a feature's diff is its own file plus, at most, one enum entry (FR-031, SC-007).

## Phase 0 output

[research.md](./research.md) — 27 decisions, each with rationale and rejected alternatives. The consequential ones:

- **R-4 / R-5** the problem body: `code` as a separate extension member rather than only a `type` URL, and validation entries carrying an RFC 6901 JSON Pointer plus their own field-level code.
- **R-6 / R-7** the catalogue is a YAML file holding the enum the skeleton `$ref`s, so an operation cannot use a code that does not exist; retryability is a column on the code, not a rule about status classes.
- **R-8** `SyncableResource` declares no scope key; `WalletScoped` and `UserScoped` each add exactly one, which makes "both" and "neither" unrepresentable.
- **R-12** cursors are opaque, replacing the integer the superseded 001 contract exposed, so the server can change the paging key without a client release and one client paging loop serves feeds and lists alike.
- **R-15** filter grammar is `filter[field]=op:value` — one bracket level, so the whole grammar is expressible in OpenAPI as a `deepObject` parameter rather than in prose beside it.
- **R-24** the `401` / `403` / `404` discipline, written once, so a non-member sees `404` everywhere and no feature re-decides it.
- **R-27** no schema linter is added, because every candidate is a new dependency; the check FR-034 owes is the reviewer checklist, and a linter is recorded as follow-up F-5 for A5.

## Phase 1 output

- [data-model.md](./data-model.md) — the twelve wire shapes plus the full initial catalogue: 28 response-level codes and 11 field-level codes, each with its status and retryability, ready to transcribe into `error-catalog.yaml`.
- [contracts/openapi-skeleton-layout.md](./contracts/openapi-skeleton-layout.md) — the file tree and the rules for extending it, including what a feature MUST NOT touch.
- [contracts/wire-shapes.md](./contracts/wire-shapes.md) — normative JSON for every shape, including the two that are easiest to get wrong: the conflict body with `current`, and a validation body with a nested array pointer.
- [contracts/collection-grammar.md](./contracts/collection-grammar.md) — the query grammar, its seven refusals, and the paging guarantee stated separately for change feeds and ordinary collections.
- [contracts/endpoint-review-checklist.md](./contracts/endpoint-review-checklist.md) — 49 yes-or-no checks in nine groups, which is FR-034 discharged.
- [quickstart.md](./quickstart.md) — four structural checks that run with no new dependency, plus the SC-005 seeded-violation exercise and the SC-001, SC-004, SC-006 and SC-007 human exercises.

## Divergences from earlier artifacts

| Artifact | Change | Why it needs no ADR |
|---|---|---|
| `001/contracts/http-conventions.md` error envelope | Replaced by RFC 9457 problem details | The error shape was fixed in a spec artifact, never in an Accepted ADR (spec Clarifications, 2026-09-20) |
| `001/contracts/http-conventions.md` `next_cursor` integer | Replaced by an opaque cursor string | ADR-0008 fixes the query and the guarantees, not the wire encoding; the token still carries the same `change_seq` watermark |
| Backlog A2 text: `{"amount": 12500}` | `amount_minor` kept | ADR-0003 already fixes the name; the suffix is what stops `12500` being read as 12,500 EGP |
| Backlog A2 text: `Idempotency-Key` on all money POSTs | Narrowed to three non-upsert operations | Syncable creates are already idempotent by identifier (ADR-0002), so the header would be one nobody sends |
| Backlog A2 text: `wallet_id` on every syncable resource | Generalised to one scope key of two | Spec 002 established four user-scoped syncable entities; the assumption was written before that was known |

## Downstream effects

| Spec | What it inherits |
|---|---|
| A4 security baseline (`004`) | The bearer scheme is declared but its lifecycle is not; per-route rate-limit budgets fill the headers this feature named; the `401`/`403`/`404` discipline is already fixed |
| A5 engineering conventions (`005`) | OpenAPI is the source of truth (FR-032), the path-per-feature layout, and follow-up F-5 — propose a schema linter where the CI and dependency questions are answered anyway |
| Sync | The syncable envelope, the opaque cursor and both cursor failures (`400 INVALID_CURSOR`, `410 SYNC_CURSOR_EXPIRED`); the feed endpoints themselves stay with that spec |
| Wallets & Ledger | Category Reference is read-only with a `405` and `Allow: GET`; postings have no wire shape; the permission matrix is still theirs alone |
| Billing | The three idempotent operations and their two `409` codes; `FEATURE_NOT_ENTITLED` as the entitlement refusal |
| Every feature | One path file, `$ref`s into the shared components, and the 49-item review checklist as the definition of done for its endpoints |

## Complexity Tracking

No Constitution Check violations. Table intentionally empty.
