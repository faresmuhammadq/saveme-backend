# Phase 0 Research: API Conventions

**Feature**: `003-api-conventions` | **Date**: 2026-09-22 | **Spec**: [spec.md](./spec.md)

Every decision below closes either a NEEDS CLARIFICATION in the plan's Technical Context or a requirement the spec states as "the contract MUST define …" without fixing the answer. Each is written so a reviewer can check one endpoint against it.

Inputs consulted: constitution v1.1.0, ADR-0002 (identifiers), ADR-0003 (money), ADR-0006 (authorization), ADR-0008 (sync feed), ADR-0010 (observability), ADR-0011 (time), [`docs/glossary.md`](../../docs/glossary.md) field conventions, and the superseded [`001/contracts/http-conventions.md`](../001-architecture-constitution-adrs/contracts/http-conventions.md).

---

## R-1 — OpenAPI 3.1.0, split across files

**Decision**: the skeleton is OpenAPI **3.1.0**, rooted at `docs/openapi/openapi.yaml`, with reusable pieces in `docs/openapi/components/` (`schemas.yaml`, `parameters.yaml`, `headers.yaml`, `responses.yaml`) and the error catalogue in `docs/openapi/error-catalog.yaml`. Feature specs add path files under `docs/openapi/paths/` and `$ref` into the components.

**Rationale**: 3.1 is JSON Schema 2020-12, so a problem-details body with extension members is expressible without the `nullable`/`anyOf` contortions 3.0 forces, and `application/problem+json` is an ordinary media type. Splitting the file keeps a feature's diff to its own path file plus, at most, one added enum value — which is what FR-031 and SC-007 ask for. No generator or validator is installed in this repo, so no tool pins us to 3.0.

**Alternatives rejected**:
- *One 3.1 file*. Rejected: every feature edits the same file, so two features in flight always conflict.
- *OpenAPI 3.0.3*. Rejected: wider tool support buys nothing here because there is no tool, and it costs schema fidelity on exactly the shape (problem + extensions) that matters most.

---

## R-2 — Base path and versioning policy

**Decision**: all endpoints live under `/api/v1`. `v1` versions the **whole surface**, not a resource. The number changes only for a change that breaks an existing client; additive changes (a new optional field, a new endpoint, a new error code) ship inside `v1`. There is no per-resource version and no version header.

**Rationale**: the client is one Android app shipping one build against one surface. Per-resource versioning multiplies the matrix the client has to reason about for a benefit (independent evolution) that a single client does not need.

**Alternatives rejected**: *header-based versioning* — invisible in logs, in a browser and in a bug report, and the website surface would have to set it too.

---

## R-3 — Success envelope

**Decision**: every successful response is

```json
{ "data": …, "meta": { … } }
```

`data` is the resource object for a single-item response, or a JSON array for a collection. `meta` is **always present**, never null.

- single item: `meta` is `{}` unless the endpoint documents a member.
- collection: `meta` carries `next_cursor` (string or `null`), `has_more` (boolean) and `limit` (integer, the page size actually applied).

`204 No Content` is the only success with no body, and it is used only where there is genuinely nothing to return. Errors do **not** use this envelope (see R-4).

**Rationale**: one shape means the client's parser has one generic type, `Envelope<T>`. Always emitting `meta` removes the "absent or empty" branch. `has_more` alongside a nullable `next_cursor` is redundant by one bit on purpose: it makes the last-page test (FR-017) explicit rather than inferred from a null.

**Alternatives rejected**:
- *Bare resource at the top level*. Rejected: leaves nowhere for paging metadata, so collections would need a second shape.
- *Envelope on errors too*. Rejected: RFC 9457 defines the body at the top level, and wrapping it forfeits the standard (and the problem media type's meaning).

---

## R-4 — Errors are RFC 9457 problem details

**Decision**: every 4xx and 5xx returns `application/problem+json` with the RFC 9457 members `type`, `title`, `status`, `detail`, plus these extension members:

| Member | Always? | Meaning |
|---|---|---|
| `code` | yes | stable `UPPER_SNAKE` identifier the client branches on |
| `request_id` | yes | the same UUIDv7 as the `X-Request-Id` response header |
| `errors` | validation only | one entry per offending input (R-5) |
| `current` | `VERSION_CONFLICT` only | the record's current state, tombstone included (R-10) |

`type` is an absolute URL that dereferences to the catalogue entry for that code: `{PROBLEM_TYPE_BASE_URL}/problems/{code-in-kebab-case}` — e.g. `…/problems/version-conflict`. `title` is the short English label from the catalogue and is **not** localised; `detail` is the localised human sentence (R-19). Clients branch on `code`, never on `title` or `detail`, and the conventions document states that as a rule with an example of the wrong code beside the right one.

**Rationale**: the spec's clarification settled RFC 9457. Keeping `code` as a separate extension rather than encoding it only in `type` means the client does one string compare instead of parsing a URL, while `type` still gives a human a link to follow from a log line. Localising `detail` but not `title` keeps the grep-able half of the message stable across locales.

**Alternatives rejected**:
- *`type` as a URN* (`urn:problem:version-conflict`). Rejected: not dereferenceable, so the one thing `type` is for — following the link — does not work.
- *Reusing the 001 `{"error": {...}}` envelope*. Superseded by the clarification; it also duplicated `request_id` placement and had no `type`.

---

## R-5 — Validation entries carry a JSON Pointer

**Decision**: a validation failure is `422` with `code: VALIDATION_FAILED` and an `errors` array. Each entry is

```json
{ "pointer": "/postings/0/amount_minor", "code": "AMOUNT_NOT_INTEGER", "detail": "…" }
```

`pointer` is an **RFC 6901 JSON Pointer** into the request body, rooted at the body itself. A failure on a query parameter or header uses a pointer into a synthetic document: `/$query/limit`, `/$header/Idempotency-Key`. Each entry carries its own `code` from the same catalogue, so a client can react to `AMOUNT_NOT_INTEGER` without reading prose.

**Rationale**: array positions are the case that breaks dotted paths (`postings.0.amount_minor` is ambiguous when a field name legitimately contains a dot), and the client maps a pointer to an input control mechanically. Per-entry codes are what make FR-008's "maps to the exact input" testable rather than aspirational.

**Alternatives rejected**:
- *Laravel's native dotted keys*. Rejected for the ambiguity above; the server converts them at the boundary, which is one small mapper in one place.
- *An object keyed by field* (`{"amount_minor": ["…"]}`). Rejected: cannot represent two distinct failures on the same field with different codes, and forces the client into map iteration to render the first error.

---

## R-6 — One versioned error catalogue, machine-readable

**Decision**: the catalogue is `docs/openapi/error-catalog.yaml`. It holds the `ErrorCode` enum that the skeleton `$ref`s, and an `x-error-catalog` map giving each code its `status`, `title`, `retryable` and `since` (the API release it appeared in). The conventions document does **not** restate the table; it links to the file and explains how to read it. Adding a code is a diff to this one file.

**Rationale**: FR-032 makes the skeleton the source of truth, and two copies of a 40-row table drift within a month. Holding the enum in the same file that holds the metadata means a code cannot be used in an operation without existing in the catalogue — a checkable coupling rather than a convention.

**Alternatives rejected**: *a Markdown table in `docs/api-conventions.md`* — readable, but nothing stops an operation referencing a code that is not in it.

---

## R-7 — Retryability is a catalogue column, not a status-code rule

**Decision**: every catalogue entry declares `retryable: automatic | after-change | never`.

- `automatic` — the client may retry the identical request with backoff: `RATE_LIMITED`, `INTERNAL_ERROR`, `SERVICE_UNAVAILABLE`.
- `after-change` — retry only after changing something: `VERSION_CONFLICT` (merge and resend), `SYNC_CURSOR_EXPIRED` (resync, new cursor), `VALIDATION_FAILED` (fix the input).
- `never` — user or app action required, resending is pointless: `UNAUTHENTICATED`, `NOT_FOUND`, `CLIENT_VERSION_UNSUPPORTED`, `FEATURE_NOT_ENTITLED`, `IDEMPOTENCY_KEY_REUSED`.

**Rationale**: FR-009 asks which codes are safe to retry automatically. Status class is the wrong signal: two 409s here (`VERSION_CONFLICT`, `IDEMPOTENCY_KEY_REUSED`) sit in different buckets. Putting it on the code makes the client's retry policy a lookup instead of a hand-maintained list.

---

## R-8 — Syncable resource envelope, with a generalised scope key

**Decision**: every syncable resource exposes exactly these, in addition to its own fields:

```json
{ "id": "…", "version": 4, "change_seq": 1287,
  "created_at": "…Z", "updated_at": "…Z", "deleted_at": null }
```

plus **one** scope key: `wallet_id` for the seven wallet-scoped entities, or `user_id` for the four user-scoped ones (Profile, Device Session, Category, Budget). The shared schema is `SyncableResource`, and it does **not** declare a scope key; `WalletScoped` and `UserScoped` are the two schemas that add one. A resource composes `SyncableResource` with exactly one of them.

**Rationale**: the backlog description assumed `wallet_id` everywhere, and spec 002 established that four of eleven syncable entities are user-scoped. Encoding the split as two small schemas makes the wrong combination (both keys, or neither) unrepresentable, which is what FR-010 and SC-006 ask for.

**Alternatives rejected**: *a polymorphic `scope: {type, id}` object*. Rejected: every client field access grows a branch, and the wallet case — the common one — pays for the rare one.

---

## R-9 — Create is an upsert addressed by the client's identifier

**Decision**: creating a syncable record is `PUT /api/v1/<collection>/{id}` with a client-generated UUIDv7. The body carries `version`: omitted or `0` means create.

| Case | Status | Body |
|---|---|---|
| Created | `201` | the resource |
| Updated | `200` | the resource |
| Replay of an already-applied identical write | `200` | current resource, no `version` bump, no `change_seq` bump |
| Stale `version` | `409 VERSION_CONFLICT` | problem + `current` |
| `id` in another scope, other entity type, or caller not a member | `404 NOT_FOUND` | problem, nothing disclosed |
| Not a UUIDv7 | `422 INVALID_ID` | problem |

`POST` is reserved for the non-upsert operations that are not resource creation (claim submission, code redemption, purchase acknowledgement, auth).

**Rationale**: P-06 and ADR-0002 fix this; it is restated here because the skeleton has to express it as a reusable response set. `201` versus `200` is the only signal distinguishing a create from an update (FR-004), and a replay is deliberately indistinguishable from an update — that is what makes the retry harmless.

---

## R-10 — A version conflict returns the whole current record, tombstone included

**Decision**: `409` with `code: VERSION_CONFLICT` and the full current resource in the `current` extension member — the same schema the read endpoint returns, including `deleted_at` when the record was deleted by another member. The server applies **nothing** from the rejected write. A client restores a record it deleted by writing `deleted_at: null` with the current `version` (ADR-0008 rule 14); an edit to a tombstone is not an implicit undelete.

**Rationale**: P-07 rules 3–4 and ADR-0008 rules 8–9. Attaching `current` is what removes the second round trip the merge would otherwise need, and returning the tombstone rather than a bare 404 is what stops the delete-then-edit case from silently resurrecting a record.

---

## R-11 — Read-only server-produced resources refuse writes with 405

**Decision**: a resource the server produces and never accepts — Category Reference is the only one today — appears in its feed and in reads, and has no write operation at all. A write to its path returns `405 METHOD_NOT_ALLOWED` with an `Allow: GET` header and the problem body.

**Rationale**: FR-016. `405` with `Allow` is the honest answer: the path exists, the method does not. `403` would imply a permission the caller could conceivably hold, and `404` would contradict the fact that the client just read the record from a feed.

---

## R-12 — Cursors are opaque strings

**Decision**: `cursor` is an opaque, URL-safe string. Clients pass back exactly what `meta.next_cursor` gave them and never construct, parse, increment or compare one. Internally it is base64url over a versioned payload; for a sync feed that payload carries the `change_seq` watermark, for an ordinary collection it carries the keyset position (sort value plus `id` tiebreak). Absence of `cursor` means "from the beginning".

**Rationale**: ADR-0008 fixes `change_seq > :cursor` as the **query**; it does not oblige the wire format to be a bare integer. The 001 contract exposed the raw integer, and an integer on the wire invites a client to do arithmetic on it, which then freezes the server's paging key forever. One opaque grammar also means the sync feed and an ordinary list share a single client paging loop (SC-001).

**Alternatives rejected**:
- *Raw `change_seq` integer*. Rejected above; also cannot express a keyset position for a non-feed collection.
- *Offset/limit*. Rejected: skips and duplicates rows under concurrent writes, which is the normal state of a shared wallet.

---

## R-13 — Paging guarantees, and the two cursor failures

**Decision**: the contract states one guarantee per feed kind.

- **Change feeds** (`change_seq` ordered): ordered within a scope, at-least-once, idempotent replays. A row may be delivered more than once; none is skipped. No ordering is implied *across* scopes.
- **Ordinary collections** (keyset on a sort key plus `id`): a row whose sort key does not change during the walk appears exactly once. A row inserted, deleted or re-sorted mid-walk may appear twice or not at all. No totals, no backward paging in MVP.

Two distinct failures:

| Situation | Status | Code |
|---|---|---|
| Cursor is unparseable, or not from this endpoint | `400` | `INVALID_CURSOR` |
| Cursor predates the scope's tombstone horizon (sync feeds only) | `410` | `SYNC_CURSOR_EXPIRED` |

**Rationale**: FR-019 asks for the guarantee to be stated, and ADR-0008 rules 13 and 16 already fix the feed half. Separating the two failures matters: `410` means "resync the whole scope", `400` means "you sent garbage", and a client that conflates them either resyncs pointlessly or loses data.

---

## R-14 — Page size: default 50, maximum 200

**Decision**: `limit` is optional; default `50`, maximum `200`. A `limit` above the maximum is **clamped**, not refused, and `meta.limit` reports what was applied. A `limit` below 1 or non-integer is `400 INVALID_QUERY_PARAMETER`. The same numbers apply to change feeds.

**Rationale**: clamping keeps a sync loop working instead of failing on a client bug, and `meta.limit` makes the clamp visible rather than silent. One pair of numbers everywhere is one fewer thing per endpoint for a reviewer to check (FR-020).

---

## R-15 — Filter and sort grammar

**Decision**: one grammar, allow-listed per endpoint.

- **Filter**: `filter[<field>]=<op>:<value>`, e.g. `filter[date]=gte:2026-01-01`, `filter[wallet_id]=eq:0192…`. A bare value means `eq`. Operators: `eq`, `ne`, `lt`, `lte`, `gt`, `gte`, `in` (comma-separated values). Repeating a field ANDs its conditions; there is no `or`, no grouping and no free-text query language.
- **Sort**: `sort=-date,id` — comma-separated fields, `-` prefix for descending. Every sort is stabilised by appending `id` when the client does not.
- Each endpoint declares its filterable and sortable fields in its OpenAPI operation as an `x-filterable` / `x-sortable` list; anything outside it is refused.

| Situation | Status | Code |
|---|---|---|
| Unknown or non-filterable field | `400` | `FILTER_FIELD_NOT_SUPPORTED` |
| Operator not allowed for that field | `400` | `FILTER_OPERATOR_NOT_SUPPORTED` |
| Value not parseable as the field's type | `400` | `INVALID_QUERY_PARAMETER` |
| Unknown or non-sortable field | `400` | `SORT_FIELD_NOT_SUPPORTED` |

**Rationale**: FR-018 wants one grammar with an explicit allow-list. One bracket level is expressible in OpenAPI as a `deepObject` parameter, whereas two levels (`filter[date][gte]`) is not — putting the operator in the value keeps the whole grammar inside the skeleton instead of in prose beside it. Refusing rather than ignoring an unknown field is what keeps a client's silently-wrong filter from returning a plausible page of the wrong rows.

**Alternatives rejected**:
- *RSQL / a query DSL*. Rejected: unbounded query plans on a money table, and unreviewable.
- *Silently ignoring unknown filters*. Rejected: the failure mode is a wrong answer that looks right.

---

## R-16 — Money on the wire

**Decision**: `{"<name>_minor": 12500, "currency": "EGP"}` — a signed JSON integer in minor units plus ISO 4217 alpha-3 uppercase, per ADR-0003 and the glossary field conventions. The API **rejects** rather than coerces: a JSON string, a float, or a number with a fractional part fails validation with per-entry code `AMOUNT_NOT_INTEGER`; an unknown or lowercase currency fails with `INVALID_CURRENCY`. Integers are bounded to ±2^53−1 so a JavaScript client on the website cannot lose precision silently. Liability balances are returned negative.

**Rationale**: P-03 and the spec's clarification. Coercion is the specific failure this rule exists to prevent — `"125.00"` accepted as `12500` once, and as `125` the next time someone touches the parser.

---

## R-17 — Dates and instants

**Decision**: calendar dates are `"2026-09-15"` (`format: date`, no timezone, field named `date` or `<name>_date`); instants are `"2026-09-15T08:30:00.123456Z"` (UTC, `Z`, microseconds, field named `<name>_at`). A calendar-date field carrying a time or an offset is refused with `DATE_NOT_CALENDAR_DATE`; an instant without `Z` is refused with `INSTANT_NOT_UTC`. The user's timezone is applied only in read models (P-12 rule 3), never at write time.

**Rationale**: P-12 and ADR-0011. The naming split is what makes the rule reviewable — a reviewer can tell from the field name alone which of the two a value must be.

---

## R-18 — Localisation and numerals

**Decision**: `Accept-Language: ar | en`, default `en`. The response carries `Content-Language` with the language actually used. A missing Arabic string falls back to English for that message, and `Content-Language` then reports `en` — the fallback is per-response, not per-string. Numerals in all output are Western `0-9` regardless of locale, including inside localised sentences. Only `detail`, and any user-facing message field, are localised; `code`, `title`, field names and enum values never are.

**Rationale**: P-14. Reporting the language actually used is what lets the client tell "Arabic" from "Arabic was asked for and English came back", which is otherwise invisible and shows up as a bug report about mixed-language screens.

---

## R-19 — `Idempotency-Key` on the three non-upsert money operations

**Decision**: `Idempotency-Key` is **required** on exactly the operations that create a money-affecting record without being an upsert: InstaPay claim submission, redemption-code redemption, and Google Play purchase acknowledgement. It is not used on syncable `PUT`s, which are already idempotent by identifier.

- Value: an opaque client string, 16–255 chars from `[A-Za-z0-9._:-]`. Malformed → `400 IDEMPOTENCY_KEY_INVALID`; missing on an operation that requires it → `400 IDEMPOTENCY_KEY_REQUIRED`.
- The server stores key → (request fingerprint, status, response body) for **24 hours**, scoped to the authenticated user.
- Same key, same fingerprint, completed → the stored response is replayed verbatim with `Idempotency-Replayed: true`.
- Same key, **different** fingerprint → `409 IDEMPOTENCY_KEY_REUSED`. It is never treated as a new request.
- Same key, original still in flight → `409 IDEMPOTENCY_REQUEST_IN_PROGRESS` with `Retry-After`.
- Fingerprint = hash of method, path and canonical request body.

**Rationale**: FR-027 and the spec's narrowing. The different-payload case is the one that silently double-charges if it is allowed to fall through, so it gets its own code rather than sharing `VERSION_CONFLICT`. Replaying the stored response — rather than recomputing — is what makes the retry produce the *same* answer, not merely a second correct one.

---

## R-20 — Rate-limit headers

**Decision**: every response to a throttled route carries `RateLimit-Limit`, `RateLimit-Remaining` and `RateLimit-Reset` (delta-seconds until the window resets). A refusal is `429` with `code: RATE_LIMITED` and a `Retry-After` header in seconds. The per-route budgets themselves belong to A4, not here; this feature fixes only the header names and the refusal shape.

**Rationale**: the spec's assumption picks the `RateLimit-*` family over a bespoke name. Note for implementation: Laravel's throttle middleware emits `X-RateLimit-*` by default, so the header names have to be overridden — recorded here so it is not discovered as a client bug.

---

## R-21 — Client version gating

**Decision**: every request carries `X-Client-Platform` (`android` | `web`) and `X-Client-Version` (semver, e.g. `1.4.2`). The server holds a minimum supported version per platform.

| Situation | Status | Code |
|---|---|---|
| Below the floor | `426` | `CLIENT_VERSION_UNSUPPORTED` |
| Header absent | `400` | `CLIENT_VERSION_REQUIRED` |
| Not parseable as semver, or unknown platform | `400` | `CLIENT_VERSION_INVALID` |

The `426` body carries `minimum_version` as an extension member so the app can name the required version in its update prompt. The gate applies to reads as well as writes.

**Rationale**: FR-029 wants a refusal distinct enough that the app shows an update prompt rather than a generic error, and `426 Upgrade Required` says exactly that. Requiring the header — rather than treating its absence as "unknown, allow" — is what makes the rule checkable; an optional header is one nobody sends and nobody can rely on. Gating reads too is the spec's assumption: an outdated client can misread a payload as easily as it can write a bad one.

---

## R-22 — Deprecation and removal

**Decision**: an endpoint scheduled for removal keeps working and advertises it on every response:

- `Deprecation: @1774915200` — RFC 9745, a structured-field date marking when it became deprecated.
- `Sunset: Sat, 31 Oct 2026 00:00:00 GMT` — RFC 8594 HTTP-date, when it stops working.
- `Link: <…/problems/endpoint-deprecated>; rel="deprecation"` — where to read what replaces it.

After the sunset date the endpoint returns `410 ENDPOINT_REMOVED`. Deprecation is advertised for at least one full client release cycle before the sunset date.

**Rationale**: FR-030. Two headers rather than one because they answer different questions — "is this dying" and "exactly when" — and a client that only wants to warn its developers reads the first while a client that wants to hard-block reads the second.

---

## R-23 — Request and correlation identifiers

**Decision**: every response — success or problem — carries `X-Request-Id`, a server-generated UUIDv7. A client may send `X-Correlation-Id`; when it matches `[A-Za-z0-9._:-]{1,128}` it is echoed and propagated to logs, jobs and listeners, and when it does not it is discarded and the server's request id is used instead. The problem body repeats the request id as `request_id`; a success response carries it in the header only.

**Rationale**: P-15 rules 2–3 and ADR-0010, restated here because the skeleton has to declare the headers. Repeating it in the problem body is deliberate: a user pastes an error screen into a support chat, and a header is not on that screen.

---

## R-24 — Security scheme and the 401 / 403 / 404 discipline

**Decision**: the skeleton declares one security scheme, HTTP `bearer`, and applies it globally; a public operation opts out with `security: []`. How tokens are issued belongs to A4. The refusal discipline is fixed here because every endpoint depends on it:

| Situation | Status | Code |
|---|---|---|
| No token, expired or revoked token | `401` | `UNAUTHENTICATED` |
| Caller is a member of the wallet but the role is insufficient | `403` | `FORBIDDEN` |
| Caller's plan lacks the feature | `403` | `FEATURE_NOT_ENTITLED` |
| Caller is not a member, or the resource does not exist | `404` | `NOT_FOUND` |

A `403` therefore discloses only that the caller is already inside the wallet. Everything outside it is `404`.

**Rationale**: P-08 rule 3 and ADR-0006. Writing the three cases as one table is what stops each feature spec re-deciding whether a non-member gets 403 or 404, which is the exact leak the rule exists to close. Splitting entitlement denial into its own code lets the app open the upgrade screen instead of showing "forbidden".

---

## R-25 — Content negotiation and malformed requests

**Decision**:

| Situation | Status | Code |
|---|---|---|
| `Accept` excludes `application/json` | `406` | `NOT_ACCEPTABLE` |
| Request `Content-Type` is not `application/json` on a body-bearing method | `415` | `UNSUPPORTED_MEDIA_TYPE` |
| Body is not parseable JSON | `400` | `MALFORMED_JSON` |
| Body is valid JSON but not an object where one is required | `400` | `MALFORMED_JSON` |
| Unknown member in a request body | `422` `VALIDATION_FAILED` + entry code `UNKNOWN_FIELD` | |

Problem responses are always sent as `application/problem+json` even when the client's `Accept` did not name it, including on the `406` itself.

**Rationale**: FR-002. Refusing unknown request members rather than ignoring them catches a client that renamed a field on its side — otherwise the write silently drops data and nobody learns until a user notices a missing note.

---

## R-26 — Where the deliverables live, and what they supersede

**Decision**: the human-readable contract is `docs/api-conventions.md`, beside `docs/glossary.md` and `docs/adr/`. The machine-readable skeleton is `docs/openapi/`. `specs/001-architecture-constitution-adrs/contracts/http-conventions.md` gains a superseded banner pointing at both and keeps its body as history; its `{"error": {...}}` envelope is dead. The conventions document does not restate the glossary's field-convention table (FR-024) — it links to it and adds only the API-specific rows (envelope member names, the scope-key pair, cursor).

**Rationale**: the Android and landing-page teams read `docs/`, not `specs/`; a spec folder records one moment while this contract is living reference. Leaving the 001 contract in place with a banner keeps old plan links working instead of producing dead references.

---

## R-27 — No schema linter in this feature

**Decision**: the skeleton is validated by the reviewer checklist in [`contracts/endpoint-review-checklist.md`](./contracts/endpoint-review-checklist.md) and the exercises in [quickstart.md](./quickstart.md). No linter (Spectral, Redocly, `openapi-spec-validator`) is added.

**Rationale**: every one of them is a new dependency, and the constitution's quality gates require the owner's approval for that (Quality Gates rule 6). The check this feature owes (FR-034) is "a reviewer can apply each convention to one endpoint definition", which a checklist satisfies. Recorded as a follow-up rather than smuggled in.

**Follow-up**: F-5 — propose an OpenAPI linter in CI as part of A5 (engineering conventions), where the CI pipeline is decided anyway and the dependency question can be asked once.

---

## Open items carried forward (not blocking)

| Item | Owner | Why not decided here |
|---|---|---|
| Per-route rate-limit budgets and lockout thresholds | A4 security baseline | This feature fixes header names and the refusal shape only (R-20) |
| How bearer tokens are issued, refreshed and revoked | A4 security baseline | The skeleton declares the scheme, not its lifecycle (R-24) |
| `PROBLEM_TYPE_BASE_URL` concrete host | A5 / deployment | The domain is undecided; P-13 forbids the codename in the path, and the path part is fixed here |
| OpenAPI linting in CI | A5 engineering conventions | New dependency, needs owner approval (R-27) |
| The sync push/pull endpoints themselves | Sync feature spec | This feature fixes the envelope and cursor grammar those endpoints use, not their paths |
