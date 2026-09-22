# Phase 1 Data Model: API Conventions

**Feature**: `003-api-conventions` | **Date**: 2026-09-22 | **Spec**: [spec.md](./spec.md) | **Research**: [research.md](./research.md)

The "entities" of this feature are **wire shapes**, not tables. Each one below becomes a named schema in `docs/openapi/components/schemas.yaml` and a section in `docs/api-conventions.md`. Two of them — the idempotency record and the minimum-version floor — are server-side state that the contract depends on; they are marked as such.

Nothing here restates the [glossary field conventions](../../docs/glossary.md); it builds on them.

---

## 1. Envelope

The single success shape. **Schema**: `Envelope`.

| Member | Type | Required | Notes |
|---|---|---|---|
| `data` | object \| array | yes | the resource, or the page of resources |
| `meta` | object | yes | `{}` for a single item; never `null`, never absent |

**Collection `meta`** — schema `CollectionMeta`:

| Member | Type | Required | Notes |
|---|---|---|---|
| `next_cursor` | string \| null | yes | opaque; `null` on the last page |
| `has_more` | boolean | yes | `false` exactly when `next_cursor` is `null` |
| `limit` | integer | yes | the page size actually applied after clamping (R-14) |

**Rules**

- `204 No Content` is the only success without a body.
- An empty collection is `200` with `data: []`, `has_more: false`, `next_cursor: null` — never `404`, never `204`.
- A problem response never uses this envelope.

---

## 2. Problem

The single failure shape, RFC 9457. **Schema**: `Problem`. Media type `application/problem+json`.

| Member | Type | Required | Notes |
|---|---|---|---|
| `type` | string (uri) | yes | `{base}/problems/{code-in-kebab-case}` |
| `title` | string | yes | short English label from the catalogue; **not** localised |
| `status` | integer | yes | equals the HTTP status |
| `detail` | string | yes | localised sentence (R-18); never branched on |
| `code` | string | yes | `UPPER_SNAKE`; a value of the `ErrorCode` enum |
| `request_id` | string (uuid) | yes | same value as the `X-Request-Id` header |
| `instance` | string (uri) | no | request path, when it adds anything |
| `errors` | array of `ValidationError` | validation only | see §3 |
| `current` | `SyncableResource` subtype | `VERSION_CONFLICT` only | see §6 |
| `minimum_version` | string (semver) | `CLIENT_VERSION_UNSUPPORTED` only | what the app must update to |
| `retry_after_seconds` | integer | when a `Retry-After` header is sent | convenience mirror |

**Rules**

- Extension members are flat on the problem object, not nested under a wrapper.
- `code` is the only member a client branches on.
- Every problem response carries `X-Request-Id`, so `request_id` is always fillable.

---

## 3. ValidationError (one entry)

**Schema**: `ValidationError`. Appears only inside `Problem.errors` on `422 VALIDATION_FAILED`.

| Member | Type | Required | Notes |
|---|---|---|---|
| `pointer` | string | yes | RFC 6901 JSON Pointer into the request body, or `/$query/<name>` / `/$header/<Name>` |
| `code` | string | yes | a **field-level** code (§5) |
| `detail` | string | yes | localised sentence naming the field's problem |

**Rules**

- One entry per offending input. Two distinct failures on the same input produce two entries.
- Array positions are numeric path segments: `/postings/0/amount_minor`.
- The order of entries is not significant and the client must not depend on it.

---

## 4. ErrorCode (catalogue entry)

Lives in `docs/openapi/error-catalog.yaml` as the `ErrorCode` enum plus an `x-error-catalog` map. One entry:

| Field | Type | Notes |
|---|---|---|
| `code` | string | `UPPER_SNAKE`, unique, never reused for a different meaning |
| `status` | integer | the single HTTP status this code is served with |
| `level` | list of `response` and/or `field` | where the code appears: as `Problem.code`, as `ValidationError.code`, or both. Written as a list so `INVALID_ID`, which is valid at both levels, needs only one entry |
| `title` | string | the English label copied into `Problem.title` |
| `meaning` | string | one sentence: what happened, from the client's point of view |
| `retryable` | `automatic` \| `after-change` \| `never` | R-7 |
| `since` | string | the API release the code appeared in, e.g. `v1.0` |

**Rules**

- A code maps to exactly one status. Two situations needing different statuses need two codes.
- A code is never deleted once released; it is marked `deprecated: true` with a `replaced_by`.
- An operation may only reference a code that exists in this file.

---

## 5. The initial catalogue

### 5.1 Response-level codes

| Code | Status | Retryable | Meaning |
|---|---|---|---|
| `VALIDATION_FAILED` | 422 | after-change | one or more inputs are invalid; see `errors` |
| `MALFORMED_JSON` | 400 | after-change | the body is not parseable JSON, or not the required shape |
| `INVALID_QUERY_PARAMETER` | 400 | after-change | a query parameter is present but unusable |
| `INVALID_CURSOR` | 400 | after-change | the cursor is unparseable or not from this endpoint |
| `FILTER_FIELD_NOT_SUPPORTED` | 400 | never | the field is not filterable on this endpoint |
| `FILTER_OPERATOR_NOT_SUPPORTED` | 400 | never | the operator is not allowed for that field |
| `SORT_FIELD_NOT_SUPPORTED` | 400 | never | the field is not sortable on this endpoint |
| `IDEMPOTENCY_KEY_REQUIRED` | 400 | after-change | this operation requires `Idempotency-Key` |
| `IDEMPOTENCY_KEY_INVALID` | 400 | after-change | the key is malformed |
| `CLIENT_VERSION_REQUIRED` | 400 | after-change | `X-Client-Version` or `X-Client-Platform` is absent |
| `CLIENT_VERSION_INVALID` | 400 | after-change | the version is not semver, or the platform is unknown |
| `UNAUTHENTICATED` | 401 | never | no token, or it is expired or revoked |
| `FORBIDDEN` | 403 | never | the caller is a member but the role is insufficient |
| `FEATURE_NOT_ENTITLED` | 403 | never | the caller's plan does not include this capability |
| `NOT_FOUND` | 404 | never | the resource does not exist, or is outside the caller's scope |
| `METHOD_NOT_ALLOWED` | 405 | never | the path exists but not this method; see `Allow` |
| `NOT_ACCEPTABLE` | 406 | never | `Accept` excludes `application/json` |
| `VERSION_CONFLICT` | 409 | after-change | the write carried a stale `version`; see `current` |
| `IDEMPOTENCY_KEY_REUSED` | 409 | never | the key was already used with a different payload |
| `IDEMPOTENCY_REQUEST_IN_PROGRESS` | 409 | automatic | the original request with this key has not finished |
| `SYNC_CURSOR_EXPIRED` | 410 | after-change | the cursor predates the tombstone horizon; resync the scope |
| `ENDPOINT_REMOVED` | 410 | never | the endpoint passed its sunset date |
| `UNSUPPORTED_MEDIA_TYPE` | 415 | never | the request `Content-Type` is not `application/json` |
| `INVALID_ID` | 422 | never | the path identifier is not a UUIDv7 |
| `CLIENT_VERSION_UNSUPPORTED` | 426 | never | the client is below the floor; see `minimum_version` |
| `RATE_LIMITED` | 429 | automatic | the allowance is spent; see `Retry-After` |
| `INTERNAL_ERROR` | 500 | automatic | an unexpected server failure |
| `SERVICE_UNAVAILABLE` | 503 | automatic | the service is temporarily unable to handle the request |

### 5.2 Field-level codes (inside `errors[]`)

| Code | Meaning |
|---|---|
| `REQUIRED` | the member is absent or null and is required |
| `UNKNOWN_FIELD` | the member is not part of this request shape |
| `INVALID_TYPE` | the JSON type is wrong for this member |
| `INVALID_ENUM_VALUE` | the value is outside the allowed set |
| `OUT_OF_RANGE` | numeric or length bound violated |
| `AMOUNT_NOT_INTEGER` | money was sent as a string, float or fractional number (R-16) |
| `INVALID_CURRENCY` | not an uppercase ISO 4217 alpha-3 code |
| `DATE_NOT_CALENDAR_DATE` | a `YYYY-MM-DD` field carried a time or an offset (R-17) |
| `INSTANT_NOT_UTC` | an `<name>_at` field was not UTC with `Z` |
| `INVALID_ID` | a body identifier is not a UUIDv7 |
| `IMMUTABLE_FIELD` | the member cannot be changed after creation |

**Rule**: `INVALID_ID` appears at both levels, with the same meaning, because a bad identifier can arrive in the path or in the body. It is the only code that does.

---

## 6. SyncableResource and its two scope mixins

**Schema**: `SyncableResource`, composed with exactly one of `WalletScoped` or `UserScoped`.

`SyncableResource`:

| Member | Type | Required | Notes |
|---|---|---|---|
| `id` | string (uuid) | yes | UUIDv7, client-generated (ADR-0002) |
| `version` | integer | yes | server-incremented; the client echoes its last-seen value on write |
| `change_seq` | integer (int64) | yes | server-assigned from the scope counter; never accepted from a client |
| `created_at` | string (date-time) | yes | UTC |
| `updated_at` | string (date-time) | yes | UTC |
| `deleted_at` | string (date-time) \| null | yes | tombstone marker; `null` when live |

`WalletScoped` adds `wallet_id`; `UserScoped` adds `user_id`. **Neither schema declares both, and no syncable resource omits both.**

**Scope assignment** (from [`docs/glossary.md`](../../docs/glossary.md)):

| Scope | Resources |
|---|---|
| user (4) | Profile, Device Session, Category, Budget |
| wallet (7) | Wallet, Wallet Member, Category Reference, Transaction, Recurring Rule, Occurrence, Invitation |

**Rules**

- Posting is never exposed (P-04 rule 6), so it has no wire schema at all.
- Category Reference is wallet-scoped and **read-only**: it appears in reads and feeds and has no write operation (R-11).
- A write request body omits `change_seq`, `created_at`, `updated_at` — sending them is `UNKNOWN_FIELD`. It carries `version`, and it may carry `deleted_at` only to delete or to restore.

### 6.1 Write outcomes (`PUT /api/v1/<collection>/{id}`)

| Case | Status | Body |
|---|---|---|
| Created | `201` | `Envelope<Resource>` |
| Updated | `200` | `Envelope<Resource>` |
| Identical replay of an applied write | `200` | `Envelope<Resource>`, unchanged `version` and `change_seq` |
| Stale `version` | `409` | `Problem` + `current` |
| Deleted by someone else, then edited | `409` | `Problem` + `current` with `deleted_at` set |
| `id` in another scope or another entity type | `404` | `Problem` |
| Caller is not a member of the target wallet | `404` | `Problem` |
| `id` is not a UUIDv7 | `422` | `Problem` (`INVALID_ID`) |
| Resource is read-only | `405` | `Problem` + `Allow: GET` |

Restore is an explicit write of `deleted_at: null` with the current `version` (ADR-0008 rule 14).

---

## 7. Cursor

Not a schema of its own — an opaque `string` wherever it appears (`meta.next_cursor`, the `cursor` query parameter).

| Property | Value |
|---|---|
| Wire form | URL-safe opaque string |
| Produced by | the server only |
| Consumed by | the same endpoint that produced it |
| Client operations allowed | store it, send it back |
| Client operations forbidden | parse, compare, increment, construct, reuse across endpoints |
| Absent `cursor` | start from the beginning |
| Unparseable / foreign | `400 INVALID_CURSOR` |
| Older than the tombstone horizon (feeds only) | `410 SYNC_CURSOR_EXPIRED` |

Internally it encodes a version tag plus either a `change_seq` watermark (feeds) or a keyset position of the sort key and `id` (ordinary collections). That encoding is an implementation detail and may change without a client release — which is the point of it being opaque.

---

## 8. Collection query grammar

| Parameter | Form | Default | Notes |
|---|---|---|---|
| `cursor` | opaque string | absent | §7 |
| `limit` | integer 1–200 | `50` | above 200 is clamped, and `meta.limit` reports the applied value |
| `filter[<field>]` | `<op>:<value>` or bare `<value>` (= `eq`) | none | field must be in the operation's `x-filterable` |
| `sort` | comma list, `-` prefix = descending | per endpoint | field must be in `x-sortable`; `id` appended as tiebreak |

Operators: `eq`, `ne`, `lt`, `lte`, `gt`, `gte`, `in` (comma-separated values). Repeating a field ANDs its conditions. There is no `or` and no grouping.

---

## 9. Idempotency record *(server-side state)*

Not a wire shape. The contract depends on it existing, so it is specified here and built by the first feature that needs it.

| Field | Notes |
|---|---|
| `key` | the client's `Idempotency-Key` value, 16–255 chars of `[A-Za-z0-9._:-]` |
| `user_id` | scope — keys are per authenticated user, never global |
| `fingerprint` | hash of method, path and canonical body |
| `status` | `in_progress` \| `completed` |
| `response_status`, `response_body` | replayed verbatim on a matching retry |
| `expires_at` | 24 hours after first receipt |

**Applies to exactly three operations**: InstaPay claim submission, redemption-code redemption, Google Play purchase acknowledgement. Outcomes are in R-19.

---

## 10. Client version floor *(server-side state)*

| Field | Notes |
|---|---|
| `platform` | `android` \| `web` |
| `minimum_version` | semver; requests below it get `426 CLIENT_VERSION_UNSUPPORTED` |

The floor is configuration, not a table this feature creates. The contract fixes the headers, the comparison and the refusal (R-21).

---

## 11. Deprecation notice

Response headers only; no body member.

| Header | Form | Meaning |
|---|---|---|
| `Deprecation` | RFC 9745 structured date, `@<unix-seconds>` | when the endpoint became deprecated |
| `Sunset` | RFC 8594 HTTP-date | when it stops working |
| `Link` | `<…>; rel="deprecation"` | where to read the replacement |

After the sunset instant the endpoint returns `410 ENDPOINT_REMOVED`.

---

## 12. Header inventory

| Header | Direction | Required | Rule |
|---|---|---|---|
| `Authorization: Bearer <token>` | request | on secured operations | R-24 |
| `Accept-Language` | request | no | `ar` \| `en`, default `en` (R-18) |
| `Content-Language` | response | yes | the language actually used |
| `X-Client-Platform` | request | yes | `android` \| `web` |
| `X-Client-Version` | request | yes | semver |
| `X-Correlation-Id` | request → response | no | `[A-Za-z0-9._:-]{1,128}`; echoed when valid, replaced when not |
| `X-Request-Id` | response | yes | server UUIDv7, on every response including errors |
| `Idempotency-Key` | request | on the three operations in §9 | R-19 |
| `Idempotency-Replayed` | response | on a replay | `true` |
| `RateLimit-Limit` / `-Remaining` / `-Reset` | response | on throttled routes | R-20 |
| `Retry-After` | response | on `429`, `503`, `IDEMPOTENCY_REQUEST_IN_PROGRESS` | seconds |
| `Deprecation` / `Sunset` / `Link` | response | on deprecated endpoints | §11 |
| `Allow` | response | on `405` | the methods that do exist |

---

## 13. What this model deliberately does not contain

- **Any endpoint path.** Paths belong to feature specs; this model gives them the pieces.
- **Token issuance, refresh or revocation.** A4.
- **Rate-limit budgets.** A4 (only the header names are fixed here).
- **Batch or bulk writes.** Out of scope per the spec's assumptions.
- **Backward paging or total counts.** Out of scope for MVP (R-13).
- **Postings in any shape.** P-04 rule 6 forbids exposing them.
