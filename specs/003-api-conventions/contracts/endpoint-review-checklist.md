# Contract: Endpoint Review Checklist

FR-034 requires every convention in this feature to be expressible as a check a reviewer applies to **one** endpoint definition. This is that list. A reviewer runs it against a single operation in `docs/openapi/paths/<feature>.yaml` plus its request and response examples. Every line is answerable yes or no without reading another endpoint.

## A. Shape

- [ ] A1 — Path begins `/api/v1/` and contains no version segment of its own.
- [ ] A2 — Success responses use `application/json`; problem responses use `application/problem+json`.
- [ ] A3 — Every success body is `{ "data": …, "meta": … }`, with `meta` present even when empty.
- [ ] A4 — No second envelope shape, no bare resource at the top level.
- [ ] A5 — `204` is used only where there is genuinely no body; an empty list is `200` with `data: []`.

## B. Errors

- [ ] B1 — Every problem response `$ref`s `components/responses.yaml`; none is defined inline.
- [ ] B2 — Every error code the operation can return exists in `error-catalog.yaml`.
- [ ] B3 — No new code was invented locally; a genuinely new condition added a catalogue entry in the same change.
- [ ] B4 — Each code the operation uses maps to the one status the catalogue gives it.
- [ ] B5 — A validation response documents `errors[]` with JSON Pointer `pointer` and a field-level `code`.
- [ ] B6 — Nothing in the operation asks the client to branch on `title` or `detail`.

## C. Identifiers and writes

- [ ] C1 — A create is `PUT /…/{id}` with a client-generated UUIDv7, not `POST` with a server-assigned id.
- [ ] C2 — `201` is documented for create, `200` for update and for replay.
- [ ] C3 — The request body carries `version`; `change_seq`, `created_at` and `updated_at` are not accepted.
- [ ] C4 — `409 VERSION_CONFLICT` is documented and its body carries `current`.
- [ ] C5 — The deleted-then-edited case returns `409` with a tombstoned `current`, not `404` and not a silent revival.
- [ ] C6 — An identifier outside the caller's scope returns `404`, never `403`, and discloses nothing.
- [ ] C7 — A non-UUIDv7 identifier returns `422 INVALID_ID`.
- [ ] C8 — A read-only resource has no write operation, and its path documents `405` with `Allow`.

## D. Syncable envelope

- [ ] D1 — The resource schema composes `SyncableResource` rather than repeating its members.
- [ ] D2 — It adds **exactly one** scope key: `wallet_id` or `user_id`, never both, never neither.
- [ ] D3 — The scope matches the glossary: Profile, Device Session, Category and Budget are user-scoped; the other seven are wallet-scoped.
- [ ] D4 — `deleted_at` is documented as nullable and tombstones appear in feeds.
- [ ] D5 — No posting is exposed, in any shape, under any name.

## E. Collections

- [ ] E1 — Paging is `cursor` + `limit`; there is no `page`, `offset` or `total`.
- [ ] E2 — `meta` documents `next_cursor`, `has_more` and `limit`.
- [ ] E3 — `x-filterable` and `x-sortable` are both present, even if empty.
- [ ] E4 — The filter grammar is `filter[field]=op:value`; no bespoke parameter names such as `date_from`.
- [ ] E5 — Unsupported field and operator refusals are documented.
- [ ] E6 — The cursor is documented as opaque; nothing in the operation implies a client can compute one.

## F. Values

- [ ] F1 — Every money value is `<name>_minor` integer plus `currency`; no decimal string, no float.
- [ ] F2 — Calendar dates are `format: date`; instants are `format: date-time` in UTC.
- [ ] F3 — A field named `<name>_at` is an instant; a field named `date` or `<name>_date` is a calendar date. Nothing is named against its type.
- [ ] F4 — Field names follow the glossary conventions; none of the banned variants appears (`amount_cents`, `is_deleted`, `rev`, `seq`, `space_id`, `updated_time`).
- [ ] F5 — No user-facing string is hardcoded English in the schema; localised members are marked as localised.

## G. Headers and request handling

- [ ] G1 — `X-Request-Id` is documented on every response, success and error.
- [ ] G2 — The operation neither requires nor invents a header outside the inventory in `data-model.md` §12.
- [ ] G3 — `Idempotency-Key` is required if and only if the operation is one of the three non-upsert money operations.
- [ ] G4 — If it is one of them, `409 IDEMPOTENCY_KEY_REUSED` and `409 IDEMPOTENCY_REQUEST_IN_PROGRESS` are documented.
- [ ] G5 — Throttled routes document `429` with `Retry-After` and the `RateLimit-*` headers.
- [ ] G6 — `426 CLIENT_VERSION_UNSUPPORTED` is reachable; the operation does not opt out of the version gate.

## H. Security

- [ ] H1 — The operation inherits the global bearer scheme, or states `security: []` deliberately.
- [ ] H2 — `401`, `403` and `404` follow the discipline: not authenticated / member but under-privileged / everything else.
- [ ] H3 — Entitlement denial is `403 FEATURE_NOT_ENTITLED`, distinct from role denial.
- [ ] H4 — Nothing in a response body or error message discloses the existence of a resource the caller cannot read.

## I. Documentation hygiene

- [ ] I1 — `operationId` is unique and verb-first; exactly one tag, from the context list.
- [ ] I2 — The operation adds nothing to `openapi.yaml` beyond one `paths` entry.
- [ ] I3 — Nothing in the operation restates a shared schema instead of `$ref`-ing it.
- [ ] I4 — `docs/api-conventions.md` needed no change; if it did, the change is in the same commit and does not contradict the skeleton.

## Using this list

A reviewer who answers "no" to any line either fixes the endpoint or records why the exception is correct in the feature's plan. A "no" with no record is a defect. The list is also the source for the reviewer exercise in [quickstart.md](../quickstart.md).
