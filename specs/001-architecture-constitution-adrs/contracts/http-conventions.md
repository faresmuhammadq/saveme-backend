# Contract: Cross-cutting HTTP Conventions

> **Superseded — 2026-09-22.** This file is kept as history. The live contract is
> [`docs/api-conventions.md`](../../../docs/api-conventions.md), with the machine-readable
> skeleton at [`docs/openapi/`](../../../docs/openapi/openapi.yaml), produced by
> [spec 003](../../003-api-conventions/spec.md).
>
> Two shapes below are **dead**, not merely restated elsewhere:
>
> - the `{"error": {...}}` envelope, replaced by RFC 9457 problem details;
> - the integer `next_cursor`, replaced by an opaque cursor string.
>
> Everything else here survives in the new contract, sometimes with a wider rule —
> the syncable envelope no longer assumes `wallet_id`, because four of the eleven
> syncable entities are user-scoped.


These are the API-wide conventions fixed by the ADRs. Feature contracts reference this file instead of redefining them. The shapes are normative; paths are illustrative.

## Headers

| Header | Direction | Rule | ADR |
|---|---|---|---|
| `X-Request-Id` | response | Server UUIDv7, on every response incl. errors | 010 |
| `X-Correlation-Id` | request → response | Optional; ≤128 chars `[A-Za-z0-9._:-]`; echoed back; else request id used | 010 |
| `Accept-Language` | request | `ar` or `en`; localises messages only, never numerals (always 0–9) | P-14 |

## Money

```json
{ "amount_minor": 150075, "currency": "EGP" }
```

- `amount_minor` is a JSON integer (signed, fits 64-bit and within ±2^53 for JS safety). It is never a string or a float.
- `currency` is ISO 4217 alpha-3 uppercase.
- Liability wallet balances are returned negative; the client presents them as owed.

## Identifiers and time

- IDs: lowercase hyphenated UUIDv7 strings.
- Calendar dates: `"2026-09-15"`.
- Instants: `"2026-09-15T08:30:00.123456Z"` (UTC, `Z`).

## Syncable entity envelope

```json
{
  "id": "0192f7a0-…",
  "version": 4,
  "change_seq": 1287,
  "updated_at": "2026-09-15T08:30:00.000000Z",
  "deleted_at": null,
  "...": "entity fields"
}
```

## Upsert

`PUT /…/{id}` with body including `version` (omitted or `0` for create).

| Case | Status |
|---|---|
| Created | 201 + entity |
| Updated | 200 + entity |
| Identical replay of an already-applied write | 200 + current entity |
| Stale `version` | 409 `VERSION_CONFLICT` + `current` entity |
| ID belongs to another scope/type, or caller not a member | 404 `NOT_FOUND` |
| Non-v7 UUID | 422 `INVALID_ID` |

## Pull (per scope)

`GET /…/changes?cursor=<int>&limit=<int>` → `{ "changes": [entity…], "next_cursor": <int>, "has_more": bool }`

- Entities are ordered by `change_seq` ascending and include tombstones.
- Cursor older than the retention horizon → 410 `SYNC_CURSOR_EXPIRED`; the client must fully resync the scope.

## Error envelope

```json
{
  "error": {
    "code": "VERSION_CONFLICT",
    "message": "localised text",
    "details": {},
    "request_id": "0192f7a0-…"
  },
  "current": { "...": "only for VERSION_CONFLICT" }
}
```

- `code` is a stable UPPER_SNAKE identifier. Clients branch on `code`, never on `message`.
- Validation errors use code `VALIDATION_FAILED` with `details.fields`.
