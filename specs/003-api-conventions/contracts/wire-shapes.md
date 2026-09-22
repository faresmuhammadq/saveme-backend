# Contract: Wire Shapes

Normative JSON. These examples are the shapes `docs/api-conventions.md` shows and `docs/openapi/components/schemas.yaml` encodes. Field semantics are in [data-model.md](../data-model.md); this file fixes the literal form.

## Success — single item

`200 OK`, `Content-Type: application/json`

```json
{
  "data": {
    "id": "0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70",
    "wallet_id": "0192f79e-0000-7000-8000-000000000001",
    "amount_minor": -12500,
    "currency": "EGP",
    "date": "2026-09-15",
    "version": 4,
    "change_seq": 1287,
    "created_at": "2026-09-15T08:30:00.123456Z",
    "updated_at": "2026-09-15T09:02:11.004000Z",
    "deleted_at": null
  },
  "meta": {}
}
```

## Success — collection

```json
{
  "data": [ { "id": "…", "…": "…" } ],
  "meta": {
    "next_cursor": "djE6dHhuOjE3NjQ1NjcyMDA6MDE5MmY3YTA",
    "has_more": true,
    "limit": 50
  }
}
```

Last page: `"next_cursor": null`, `"has_more": false`. Empty collection: `"data": []` with the same two values.

## Failure — generic

`403 Forbidden`, `Content-Type: application/problem+json`

```json
{
  "type": "https://api.example/problems/feature-not-entitled",
  "title": "Feature not entitled",
  "status": 403,
  "detail": "مشاركة المحفظة غير متاحة في باقتك الحالية.",
  "code": "FEATURE_NOT_ENTITLED",
  "request_id": "0192f7a0-9999-7000-8000-00000000abcd"
}
```

`title` stays English; `detail` follows `Accept-Language`; numerals are Western in both.

## Failure — validation

`422 Unprocessable Content`

```json
{
  "type": "https://api.example/problems/validation-failed",
  "title": "Validation failed",
  "status": 422,
  "detail": "The request has 2 invalid fields.",
  "code": "VALIDATION_FAILED",
  "request_id": "0192f7a0-…",
  "errors": [
    { "pointer": "/amount_minor", "code": "AMOUNT_NOT_INTEGER",
      "detail": "Amount must be an integer number of minor units." },
    { "pointer": "/postings/1/date", "code": "DATE_NOT_CALENDAR_DATE",
      "detail": "Date must be YYYY-MM-DD with no time or offset." }
  ]
}
```

`pointer` is an RFC 6901 JSON Pointer into the request body. Query and header failures use `/$query/<name>` and `/$header/<Name>`.

## Failure — version conflict

`409 Conflict`

```json
{
  "type": "https://api.example/problems/version-conflict",
  "title": "Version conflict",
  "status": 409,
  "detail": "This record changed on another device.",
  "code": "VERSION_CONFLICT",
  "request_id": "0192f7a0-…",
  "current": {
    "id": "0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70",
    "wallet_id": "0192f79e-…",
    "amount_minor": -14000,
    "currency": "EGP",
    "version": 6,
    "change_seq": 1301,
    "created_at": "2026-09-15T08:30:00.123456Z",
    "updated_at": "2026-09-16T11:40:02.900000Z",
    "deleted_at": null
  }
}
```

Deleted-then-edited returns the same shape with `deleted_at` set. **Nothing from the rejected write is applied.**

## Failure — client too old

`426 Upgrade Required`

```json
{
  "type": "https://api.example/problems/client-version-unsupported",
  "title": "Client version unsupported",
  "status": 426,
  "detail": "Update the app to continue.",
  "code": "CLIENT_VERSION_UNSUPPORTED",
  "request_id": "0192f7a0-…",
  "minimum_version": "1.6.0"
}
```

## Money

```json
{ "amount_minor": 12500, "currency": "EGP" }
```

Rejected, never coerced: `"125.00"`, `125.0`, `12500.5`, `"EGP "`, `egp`. Liability balances are negative. `<name>_minor` where the field has a specific meaning: `balance_minor`, `opening_balance_minor`, `limit_minor`.

## Dates and instants

```json
{ "date": "2026-09-15", "created_at": "2026-09-15T08:30:00.123456Z" }
```

A `date` field carrying a time or offset is `DATE_NOT_CALENDAR_DATE`. An `_at` field without `Z` is `INSTANT_NOT_UTC`.

## Write request body

```json
{ "amount_minor": -12500, "currency": "EGP", "date": "2026-09-15", "version": 4 }
```

- `version` omitted or `0` means create.
- `id` comes from the path, not the body.
- `change_seq`, `created_at`, `updated_at` MUST NOT be sent — they are `UNKNOWN_FIELD`.
- `deleted_at` may be sent only to delete (an instant) or restore (`null`).

## Rules a reviewer applies to any example

1. Success bodies have exactly two top-level members, `data` and `meta`.
2. Problem bodies have no wrapper: extension members sit beside `type`/`title`/`status`/`detail`.
3. Every response carries `X-Request-Id`; every problem repeats it as `request_id`.
4. No decimal, no string, no float anywhere a money value appears.
5. No identifier that is not a lowercase hyphenated UUIDv7.
6. No Arabic-Indic numerals anywhere, in any locale.
