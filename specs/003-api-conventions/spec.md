# Feature Specification: API Conventions

**Feature Branch**: `003-api-conventions`

**Created**: 2026-09-20

**Status**: Draft

**Input**: Backlog item A2 (see [specs/README.md](../README.md)): "Define the HTTP contract shape for all endpoints: base path /api/v1; JSON only; success envelope { "data": …, "meta": … }; errors follow RFC 9457 (type, title, status, detail, code, errors[] for validation) with a versioned error-code catalog; cursor pagination (meta.next_cursor); filtering and sorting grammar; Accept-Language (ar, en) drives message locale; Idempotency-Key header required on all POSTs that create money-affecting records, stored 24h; rate-limit headers; X-Client-Version and minimum-version gating; deprecation header policy. The syncable-entity contract: every syncable resource exposes id (UUIDv7), wallet_id (where applicable), version (int, server-incremented), created_at, updated_at, deleted_at; writes must send version and receive 409 VERSION_CONFLICT with the current state when stale. Considerations: The KMP client generates IDs, so create endpoints are PUT /…/{id} (idempotent upsert) rather than POST. Amounts are always { "amount": 12500, "currency": "EGP" }, never a decimal string. Dates that are calendar dates (transaction date) are YYYY-MM-DD, not timestamps. Validation errors must carry field paths the client can map to inputs. Produce the OpenAPI skeleton (info, servers, security schemes, shared schemas, error responses) that every feature spec extends."

> **Nature of this feature.** The deliverable is a contract every later endpoint obeys, plus the OpenAPI skeleton those endpoints extend. Its users are the Android client developers who consume the API, the people and agents who spec each feature, and the reviewers who check them. HTTP vocabulary is the subject matter here, not leaked implementation detail; no controller, route or framework is prescribed.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Client developer integrates an endpoint without asking anyone (Priority: P1)

An Android developer picks up a newly specced endpoint. From the conventions document alone they already know the base path, the media type, the envelope their parser expects, how a list is paged, what an error looks like, which header carries the request id, and how a validation failure maps to an input field. They write the client code without a single clarifying question to the backend.

**Why this priority**: the client is a separate repository on a separate schedule. Every convention discovered late is a client release.

**Independent Test**: hand a client developer the conventions plus one feature's endpoint list. They can write the request and response types, the paging loop and the error handling with no follow-up questions.

**Acceptance Scenarios**:

1. **Given** the conventions, **When** a developer receives any successful response, **Then** they know it is wrapped in a single envelope shape and where the payload sits inside it.
2. **Given** a list endpoint, **When** more results exist, **Then** the developer knows which field carries the next cursor and how to tell the last page.
3. **Given** any failure, **When** the developer inspects the body, **Then** they find a stable machine-readable code they can branch on, distinct from the human message.
4. **Given** a validation failure on a nested field, **When** the developer reads the error, **Then** they get a path that maps to the exact input control to highlight.

---

### User Story 2 - Offline client writes and reconciles deterministically (Priority: P1)

The client creates records offline with its own identifiers, retries writes over a flaky connection, and eventually collides with an edit made on a partner's phone. Every one of those cases has exactly one defined outcome: the same write applied twice is harmless, a stale write is refused with the current state attached, and the client knows whether to merge or resend.

**Why this priority**: offline entry and shared wallets are the product. An ambiguous write contract corrupts money data, which is the failure users never forgive.

**Independent Test**: simulate create, duplicate retry, stale update, delete-then-edit, and a write to a record the caller may not see. Each produces a single documented status and body.

**Acceptance Scenarios**:

1. **Given** a record created offline, **When** the client sends it with its own identifier, **Then** the create is an upsert and repeating it produces the same result rather than a duplicate.
2. **Given** a write carrying an out-of-date version, **When** it reaches the server, **Then** the whole write is refused and the response carries the record's current state.
3. **Given** a record another member already deleted, **When** the client edits it, **Then** the outcome is the same refusal path carrying the tombstone, not a silent resurrection.
4. **Given** a request that creates a money-affecting record and is retried after a timeout, **When** the retry arrives, **Then** it is recognised as the same request and does not charge, credit or claim twice.

---

### User Story 3 - Feature spec author extends the skeleton instead of inventing (Priority: P2)

Someone specifying Wallets, Budgets or Billing describes only what is unique to their feature. The envelope, the error catalogue, paging, auth, headers and the shared schemas already exist in one skeleton they reference. Two features written months apart come out consistent.

**Why this priority**: this is how the convention stays true. Without a skeleton to extend, each spec re-decides and the API fragments.

**Independent Test**: take two unrelated feature endpoint definitions written against the skeleton. Their envelopes, error bodies, paging and header behaviour are identical without either author coordinating.

**Acceptance Scenarios**:

1. **Given** the skeleton, **When** an author adds an endpoint, **Then** they supply only its path, parameters and payload schema, reusing shared error and envelope definitions by reference.
2. **Given** a new error condition, **When** the author needs a code for it, **Then** they add it to the one catalogue rather than inventing a local string.

---

### User Story 4 - Old client versions are retired without breaking users (Priority: P3)

As the API evolves, the server can tell which client version is calling, warn about endpoints being retired, and refuse versions too old to behave safely — with a message the app can show rather than a crash.

**Why this priority**: it matters at the second release, not the first, but the headers must exist from day one or they cannot be relied on later.

**Independent Test**: a request from a version below the floor receives a distinct, documented refusal the app can render; a request to a retiring endpoint succeeds while carrying a warning.

**Acceptance Scenarios**:

1. **Given** a client below the minimum supported version, **When** it calls any endpoint, **Then** it receives a specific machine-readable refusal telling it to update, not a generic error.
2. **Given** an endpoint scheduled for removal, **When** a client calls it, **Then** the response carries a deprecation signal including when it stops working, while still succeeding.

---

### Edge Cases

- **A retry that is not a retry**: the same idempotency key arrives with a different payload. The contract must define this as a conflict rather than silently returning the first result.
- **An identifier that belongs to someone else**: a write addressed to an existing record in another user's scope must not confirm the record exists.
- **A cursor that has aged out**: an offline client returns after the tombstone retention window with a cursor the server can no longer honour, and must be told to resync rather than served a partial feed.
- **A read-only resource being written**: `Category Reference` appears in a feed but has no write endpoint; a write attempt needs a defined refusal.
- **Locale with no translation**: a request asks for Arabic for a message that has no Arabic string yet. The fallback must be defined, and numerals stay Western either way.
- **Paging a feed that is changing underneath**: a list is read while records are being written. The contract must say whether paging is stable and what the client is guaranteed.
- **A client sending a decimal amount**: the contract must reject it rather than coerce, or money silently loses precision.
- **A calendar date sent as a timestamp**: the transaction date must not acquire a timezone through the API.
- **Rate limit hit mid-sync**: a sync loop is throttled. The client needs to know when it may resume, from headers rather than guesswork.
- **Two scopes, one contract**: user-scoped resources (profile, devices, categories, budgets) carry no wallet identifier. Any envelope assuming `wallet_id` is wrong for four of the eleven syncable entities.

## Requirements *(mandatory)*

### Functional Requirements

#### Transport and envelope

- **FR-001**: All endpoints MUST live under a single versioned base path, and the version MUST change only for a breaking change to the whole surface.
- **FR-002**: Requests and responses MUST be JSON only. The contract MUST define the media type used for success and for errors, and MUST define the refusal when another type is requested.
- **FR-003**: Every successful response MUST use one envelope with a payload member and a metadata member, and the contract MUST state what appears in metadata for single-item versus collection responses.
- **FR-004**: The contract MUST define how an empty collection, a created record and a record returned unchanged after a repeated write are distinguished.

#### Errors

- **FR-005**: Every failure MUST return an **RFC 9457 problem details** body — `type`, `title`, `status`, `detail` — extended with the stable machine-readable code and the request identifier, and served under the problem media type. The contract MUST define what `type` points at (the catalogue entry for that code).
- **FR-005a**: A version conflict MUST carry the record's **current state as a named extension member** of the problem body, so the client can merge without a second request.
- **FR-006**: Clients MUST branch on the machine-readable code and never on the human message. The contract MUST state this explicitly.
- **FR-007**: There MUST be one error-code catalogue, versioned, listing every code with its status, meaning and whether it is retryable. A feature adds codes to the catalogue rather than inventing local strings.
- **FR-008**: Validation failures MUST carry, as a problem extension member, one entry per offending input with a **field path** the client can map to a specific control, including nested and array positions.
- **FR-009**: The contract MUST define which codes are safe to retry automatically and which require user action.

#### Syncable resources

- **FR-010**: Every syncable resource MUST expose: identifier, version, sync sequence, created and updated instants, and the deletion marker. The contract MUST NOT assume a wallet identifier on all of them — **four of the eleven syncable entities are user-scoped**.
- **FR-011**: Creating a syncable record MUST be an idempotent upsert addressed by the client-generated identifier, not a server-assigned one.
- **FR-012**: Every write to a syncable record MUST carry the version the client last saw. A stale version MUST refuse the **whole** write and return the record's current state, including when that state is a tombstone. The server MUST NOT merge.
- **FR-013**: The contract MUST define the refusal when an identifier resolves to a record outside the caller's scope, in a way that does not disclose the record's existence.
- **FR-014**: The contract MUST define the identifier format requirement and the refusal for a malformed one.
- **FR-015**: Deletion MUST be expressed as a tombstone, and the contract MUST define how a client restores a record it deleted.
- **FR-016**: Read-only server-produced resources — such as the category projection published to a wallet — MUST be readable in a feed and MUST have a defined refusal when written.

#### Collections

- **FR-017**: Collections MUST use cursor pagination with the next cursor carried in metadata, and MUST define how the client recognises the last page.
- **FR-018**: The contract MUST define one filtering and sorting grammar, including which fields are filterable per endpoint, the comparison operators available, and the refusal for an unsupported field or operator.
- **FR-019**: The contract MUST state the paging guarantee while the underlying data changes, and MUST define the outcome when a cursor is no longer honourable.
- **FR-020**: The contract MUST define the maximum and default page size.

#### Shared value shapes

- **FR-021**: Money MUST be expressed as the integer minor-unit field **`amount_minor`** (or `<name>_minor` where the field has a specific meaning) plus an ISO 4217 currency code, per ADR-0003. Decimal strings and floating-point numbers MUST be rejected, not coerced.
- **FR-022**: Calendar dates MUST be date-only with no timezone; instants MUST be UTC. The contract MUST state which fields are which.
- **FR-023**: Numerals in all output MUST be Western (0–9) regardless of locale.
- **FR-024**: Field naming across the API MUST follow the project's fixed conventions for identifiers, money, versions, sync ordering and deletion markers rather than per-endpoint variants.

#### Headers and request handling

- **FR-025**: Every response MUST carry a server-generated request identifier. A client-supplied correlation identifier MUST be accepted when well-formed and echoed, and MUST be replaced when not.
- **FR-026**: The requested language MUST drive message localisation between Arabic and English, and the contract MUST define the fallback when a translation is missing.
- **FR-027**: Requests that create a **money-affecting record through a non-upsert operation** — subscription claims, code redemption, purchase acknowledgement — MUST carry an idempotency key, retained long enough to cover client retries. The contract MUST define the outcome when the same key arrives with a different payload.
- **FR-028**: Rate-limited responses MUST tell the client its remaining allowance and when it may retry, through headers.
- **FR-029**: Clients MUST identify their version. The contract MUST define the refusal for a version below the supported floor, distinct from any other failure so the app can prompt an update.
- **FR-030**: An endpoint scheduled for removal MUST advertise its deprecation and its removal date while continuing to work until that date.

#### The skeleton

- **FR-031**: The project MUST have one OpenAPI skeleton containing: service information, servers, security schemes, the shared envelope and error schemas, the shared parameters and headers, and the reusable responses. Every feature spec extends it by reference.
- **FR-032**: The skeleton MUST be the source of truth for the contract, and the conventions document MUST NOT contradict it.
- **FR-033**: The skeleton MUST cover both authenticated and unauthenticated surfaces, since some endpoints are reachable from the website rather than the app.
- **FR-034**: Every convention in this specification MUST be expressible as a check a reviewer can apply to one endpoint definition.

### Key Entities

- **Envelope**: the single success shape — payload plus metadata.
- **Problem**: the single failure shape — code, title, detail, status, request identifier, and for validation, the per-field entries.
- **Error Code**: one catalogue entry — code, status, meaning, retryability, and the release it appeared in.
- **Cursor**: the opaque position token a client carries between pages of a collection or a change feed.
- **Syncable Resource Envelope**: the fields every syncable record exposes — identifier, version, sync sequence, timestamps, deletion marker, and its scope key.
- **Idempotency Key**: a client-supplied token identifying one logical non-upsert operation, with its retention window.
- **Client Version**: the caller's app version, compared against the supported floor.
- **Deprecation Notice**: the signal that an endpoint is retiring, with its removal date.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A client developer, given only the conventions and one feature's endpoint list, writes request/response handling with zero clarifying questions.
- **SC-002**: 100% of the endpoints defined in any later feature spec reuse the shared envelope, error and paging definitions rather than restating them.
- **SC-003**: Every error a client can encounter maps to exactly one catalogue code; a review of any two feature specs finds no duplicate or overlapping codes for the same condition.
- **SC-004**: All five offline write cases — create, duplicate retry, stale version, deleted-then-edited, foreign identifier — have exactly one documented outcome each, with no "depends on the endpoint".
- **SC-005**: Given five seeded contract violations (a decimal amount, a timestamped calendar date, an invented error string, a create by server-assigned identifier, a list without a cursor), a reviewer maps 5 of 5 to the convention they break.
- **SC-006**: The contract holds for both scopes: every user-scoped syncable resource is expressible without a wallet identifier.
- **SC-007**: A feature author adds one endpoint to the skeleton in under an hour, supplying only what is unique to it.

## Clarifications

### Session 2026-09-20

- Q: RFC 9457 problem details, or the custom error envelope sketched in spec 001? → A: **RFC 9457.** Failures carry `type`, `title`, `status`, `detail`, plus extension members for the stable code, the request identifier, the per-field validation entries, and the current record on a version conflict. Failure responses use the problem media type. Spec 001's `contracts/http-conventions.md` error envelope is superseded by this document.
- Q: Money field name — `amount_minor` (ADR-0003) or `amount` (the A2 description)? → A: **`amount_minor`.** No ADR change; the suffix keeps `12500` from being read as 12,500 EGP, which is the confusion ADR-0003 exists to prevent.

## Assumptions

- Output locations: the human-readable conventions at `docs/api-conventions.md` beside the glossary, and the machine-readable skeleton at `docs/openapi/openapi.yaml` with shared components alongside it. Feature specs reference both.
- Spec 001's `contracts/http-conventions.md` becomes historical once this feature lands; it was a placeholder written before this spec existed, and this document supersedes it — including its custom `{"error": {...}}` envelope, replaced by RFC 9457 problem details.
- Adopting RFC 9457 needs no ADR: the error shape was fixed in a spec artifact, not in an Accepted ADR. Only the ledger, money, identifier, sync, authorization, entitlement, event, observability and time decisions carry ADRs, and none of them names an error envelope.
- The base path is `/api/v1`, and `v1` covers the whole surface rather than per-resource versioning.
- Idempotency keys apply only to the handful of non-upsert money operations (claim submission, code redemption, purchase acknowledgement). Syncable creates are already idempotent by identifier, so requiring the header there would add a header nobody sends. Retention is 24 hours as described.
- A client version below the floor is refused at every endpoint including reads, because an outdated client may misread data; the refusal is a distinct code the app maps to an update prompt.
- Missing translations fall back to English rather than failing, and the response states the language actually used.
- Cursor paging is forward-only in MVP; no backward paging or total counts, since totals are expensive and the client's lists are append-oriented.
- Filtering supports equality and range comparison on an explicitly allow-listed set of fields per endpoint, rather than a general query language, to keep the surface reviewable and the query plans predictable.
- Rate-limit headers follow the widely used `RateLimit-*` family rather than a bespoke naming.
- The skeleton declares the security scheme as a bearer token, without specifying how tokens are issued — that belongs to A4 (security baseline).
- Batch or bulk write endpoints are out of scope for this feature; the sync protocol's push shape is defined by the sync ADR and its own feature spec.
