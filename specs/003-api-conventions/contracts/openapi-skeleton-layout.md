# Contract: OpenAPI Skeleton Layout

Normative. This is the file layout the skeleton MUST have, what each file MUST hold, and the only way a feature spec is allowed to extend it.

## File tree

```text
docs/openapi/
├── openapi.yaml                 # root: openapi, info, servers, security, tags, paths index
├── error-catalog.yaml           # the ErrorCode enum + x-error-catalog metadata
├── components/
│   ├── schemas.yaml             # Envelope, CollectionMeta, Problem, ValidationError,
│   │                            #   SyncableResource, WalletScoped, UserScoped, Money
│   ├── parameters.yaml          # Cursor, Limit, Filter, Sort, ResourceId
│   ├── headers.yaml             # response headers (X-Request-Id, RateLimit-*, Sunset, …)
│   └── responses.yaml           # the reusable problem responses, one per status
└── paths/
    └── <feature>.yaml           # one file per feature; added by that feature's spec
```

## `openapi.yaml`

MUST contain, and nothing else:

| Key | Requirement |
|---|---|
| `openapi` | `3.1.0` |
| `info` | title, summary, version (the API release, e.g. `v1.0`), contact, license |
| `servers` | at least the production and local base URLs, both ending `/api/v1` |
| `security` | the global default: `[{ bearerAuth: [] }]` |
| `components.securitySchemes.bearerAuth` | `type: http`, `scheme: bearer` |
| `tags` | one per bounded context, matching the constitution's context names |
| `paths` | `$ref` entries into `paths/<feature>.yaml` only — no inline operations |

The root file MUST NOT define a schema, a parameter or a response inline.

## `components/schemas.yaml`

MUST define at least: `Envelope`, `CollectionMeta`, `Problem`, `ValidationError`, `SyncableResource`, `WalletScoped`, `UserScoped`, `Money`.

- `SyncableResource` MUST NOT declare `wallet_id` or `user_id`.
- `WalletScoped` and `UserScoped` MUST each declare exactly one of them.
- `Money` MUST be `{ <name>_minor: integer, currency: string }` with the integer bounded to ±2^53−1 and the currency `^[A-Z]{3}$`.
- Every schema MUST carry a `description`.

## `components/responses.yaml`

MUST define one reusable response per status the catalogue uses, each with media type `application/problem+json` and schema `Problem`: `BadRequest`, `Unauthenticated`, `Forbidden`, `NotFound`, `MethodNotAllowed`, `NotAcceptable`, `Conflict`, `Gone`, `UnsupportedMediaType`, `UnprocessableEntity`, `UpgradeRequired`, `TooManyRequests`, `InternalError`, `ServiceUnavailable`.

Each MUST include the `X-Request-Id` header. `TooManyRequests` MUST include `Retry-After` and the `RateLimit-*` headers.

## `error-catalog.yaml`

MUST contain:

- `components.schemas.ErrorCode` — a string schema whose `enum` lists every response-level code.
- `components.schemas.FieldErrorCode` — the same for field-level codes.
- `x-error-catalog` — a map from code to `{ status, level, title, meaning, retryable, since }`.

A code MUST appear in both the enum and the map. A code MUST NOT be removed once released; deprecate it with `deprecated: true` and `replaced_by`.

## How a feature extends the skeleton

A feature spec adds **one** file, `paths/<feature>.yaml`, and MAY add codes to `error-catalog.yaml`. It MUST NOT:

- redefine `Envelope`, `Problem`, `ValidationError` or any shared parameter;
- inline a problem response instead of `$ref`-ing `components/responses.yaml`;
- introduce an error string that is not in the catalogue;
- add a second success envelope shape;
- change `openapi.yaml` beyond one `paths` entry and, if needed, one `tags` entry.

Every operation MUST declare:

| Element | Requirement |
|---|---|
| `operationId` | unique, `camelCase`, verb-first |
| `tags` | exactly one, an existing context tag |
| `summary` | one line |
| responses | every success it can return, plus every problem response it can return |
| `security` | inherited; a public operation MUST state `security: []` explicitly |
| `x-filterable` / `x-sortable` | on every collection operation, even when empty |

## Source-of-truth rule

Where `docs/api-conventions.md` and `docs/openapi/` disagree, the OpenAPI files win, and the disagreement is a defect fixed in the same change (FR-032). The conventions document MUST NOT copy the error catalogue table; it links to `error-catalog.yaml`.
