# Contract: Profile API

The design of the two operations added to `docs/openapi/paths/identity.yaml` (tag `Identity`), with their `openapi.yaml` index entries. Both take the shared client headers, send `X-Request-Id` and `Content-Language`, and document the shared refusals (`400`, `406`, `426`, `500`; `415` on the PUT).

## Schemas added to `identity.yaml`

```yaml
Profile:            # allOf SyncableResource + UserScoped
  display_name: string | null      # plaintext on the wire; encrypted at rest
  locale: enum [ar, en]
  timezone: string                 # IANA
  default_currency: string, ^[A-Z]{3}$
  default_currency_locked: boolean # derived, read-only
  budget_period_start_day: integer 1..28
  budget_period_start_day_from: string (date) | null

ProfileWrite:       # additionalProperties: false
  required: [display_name, locale, timezone, default_currency, budget_period_start_day, version]
  display_name: string (0..100 after trim) | null
  locale, timezone, default_currency, budget_period_start_day: as above
  version: integer >= 1

Me:
  account: Account          # existing schema from spec 007
  profile: Profile
```

## `GET /me`: `getMe`

| Status | When |
|---|---|
| `200` | `data: { account, profile }`, `meta: {}` |
| `401` | `UNAUTHENTICATED` |

## `PUT /me/profile`: `saveProfile`

| Status | When |
|---|---|
| `200` | saved (`version` + 1), or an identical replay (nothing moved). `data: Profile` |
| `401` | `UNAUTHENTICATED` |
| `409` | `VERSION_CONFLICT`, where `current` is the stored Profile |
| `422` | `VALIDATION_FAILED`: `REQUIRED`, `UNKNOWN_FIELD` (including `id`, `user_id`, `change_seq`, `created_at`, `updated_at`, `deleted_at`, `default_currency_locked`, `budget_period_start_day_from`), `INVALID_TYPE`, `INVALID_ENUM_VALUE` (locale, timezone), `INVALID_CURRENCY`, `OUT_OF_RANGE` (display name, start day), and `IMMUTABLE_FIELD` at `/default_currency` when the currency is locked |

The order of checks is in [research.md R-1](../research.md): shape, then identical replay, then version, then the currency lock, then apply.

## Localisation change

`docs/api-conventions.md`, Localisation: when `Accept-Language` is absent, an authenticated request uses the person's profile `locale`; otherwise `en`. `Content-Language` still reports the language used.

## Event

`ProfileUpdated { event_id, occurred_at, user_id, changed_fields: string[] }`, dispatched after commit. It carries names only, never values.
