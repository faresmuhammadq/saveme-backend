# Contract: Identity API

The design of `docs/openapi/paths/identity.yaml`, which is written at implementation from this document, together with its `openapi.yaml` index entries. Every operation:

- sits under `/api/v1` and carries the tag `Identity`;
- takes `AcceptLanguage`, `XCorrelationId`, `XClientPlatform` and `XClientVersion`;
- sends `X-Request-Id` and `Content-Language` on every response;
- documents the shared refusals `400` (`MALFORMED_JSON`, `INSECURE_TRANSPORT`, `CLIENT_VERSION_REQUIRED`, `CLIENT_VERSION_INVALID`), `406`, `415` (bodies only), `426` and `500` by `$ref` into `components/responses.yaml`.

An operation marked **public** declares `security: []`. Request bodies are `additionalProperties: false`, so an unknown member is `422 UNKNOWN_FIELD`.

One new error code: `EMAIL_ALREADY_REGISTERED` (409), added 2026-09-27. The API version stays `v1.1` → **`v1.2`** because endpoints are added (engineering conventions, Versions).

---

## Shared shapes (defined once in `identity.yaml`)

```yaml
DeviceDescription:        # sign-in input
  required: [session_id, device_id, name, platform, app_version]
  session_id: uuid (UUIDv7, client-generated)
  device_id: string 1..64, [A-Za-z0-9._:-]
  name: string 1..100
  platform: enum [android]
  app_version: string, semver

Account:                  # never syncable
  id: uuid
  email: string (email)
  email_verified: boolean
  state: enum [active, pending_deletion]
  deletion_scheduled_for: date-time | null

EntitlementSummary:       # from Billing's EntitlementService
  plan: string            # label key, never compared by clients for gating
  features: object        # feature_key → boolean | integer

DeviceSession:            # allOf SyncableResource + UserScoped
  device_name, platform, app_version, state [active, revoked, expired],
  end_reason [signed_out, ended_by_owner, replaced, password_changed, password_reset, expired] | null,
  signed_in_at, last_used_at | null, ended_at | null, current: boolean

Password: string, minLength 8, maxLength 128
```

---

## Operations

### `POST /auth/register`: `registerAccount` (public)

Body `{ email, password, display_name?, timezone? }`.

| Status | When |
|---|---|
| `202` | a new address. `data: { "status": "accepted" }`, `meta: {}` |
| `409` | `EMAIL_ALREADY_REGISTERED`: the address already has an account. Nothing changes and nothing is sent |
| `422` | `VALIDATION_FAILED`, with entries `REQUIRED`, `INVALID_TYPE`, `OUT_OF_RANGE` (password length, display name), `INVALID_ENUM_VALUE` (timezone), `UNKNOWN_FIELD` |
| `429` | `RATE_LIMITED`, 10 per hour per origin, with `Retry-After` and `RateLimit-*` |

Registration is the one surface that discloses an existing account (clarified 2026-09-27). Sign-in and reset stay uniform.

### `POST /auth/verify-email`: `verifyEmail` (public)

Body `{ token }`.

| Status | When |
|---|---|
| `200` | `data: { "email_verified": true }`. Also for a live link whose address is already verified |
| `400` | `VERIFICATION_TOKEN_INVALID`: expired, used, superseded or unknown |
| `422` | a malformed body |
| `429` | the generic per-origin API limit |

### `POST /auth/resend-verification`: `resendVerification` (session)

No body.

| Status | When |
|---|---|
| `202` | a mail was queued, or the address is already verified (no mail, same answer) |
| `401` | `UNAUTHENTICATED` |
| `429` | `RATE_LIMITED`, 3 per hour per address |

### `POST /auth/login`: `signIn` (public)

Body `{ email, password, device: DeviceDescription }`.

| Status | When |
|---|---|
| `200` | `data: { credential, session: DeviceSession, account: Account, entitlements: EntitlementSummary, deletion_cancelled: boolean }`. `credential` is `"{session_id}\|{secret}"`, returned in this response only |
| `401` | `INVALID_CREDENTIALS`: a wrong password, an unknown address or a purged account, answered identically |
| `404` | `NOT_FOUND`: `session_id` belongs to another account, or to an ended session (R-4) |
| `422` | a malformed body or device description |
| `423` | `ACCOUNT_LOCKED`, with `Retry-After` |
| `429` | `RATE_LIMITED`: the origin limit (20 failures per 15 minutes) |

Order of checks: shape → origin limit → account lock → password → session id. The lock is checked before the password so that a locked account's correct password is still refused (US5 scenario 1).

### `GET /auth/sessions`: `listDeviceSessions` (session)

`x-filterable: [state]`, `x-sortable: [signed_in_at, last_used_at]`. Takes `Cursor`, `Limit`, `Filter` and `Sort`.

| Status | When |
|---|---|
| `200` | `data: DeviceSession[]`, `meta: CollectionMeta`. Only the caller's sessions |
| `400` | `INVALID_CURSOR`, `INVALID_QUERY_PARAMETER`, `FILTER_*`, `SORT_FIELD_NOT_SUPPORTED` |
| `401` | `UNAUTHENTICATED` |

### `DELETE /auth/sessions/{id}`: `endDeviceSession` (session)

| Status | When |
|---|---|
| `204` | ended, or already ended |
| `401` | `UNAUTHENTICATED` |
| `404` | `NOT_FOUND`: not the caller's session |
| `422` | `INVALID_ID` |

Ending the **current** session through this path is allowed and behaves like sign-out.

### `DELETE /auth/sessions`: `endOtherDeviceSessions` (session)

`204`, or `401`. Ends every active session of the caller except the current one.

### `POST /auth/logout`: `signOut` (session)

`204`, or `401`.

### `POST /auth/forgot-password`: `requestPasswordReset` (public)

Body `{ email }`.

| Status | When |
|---|---|
| `202` | always, for a valid body |
| `422` | a malformed body |
| `429` | `RATE_LIMITED`, 3 per hour per address. Counted per **lookup value**, whether registered or not, so it does not disclose registration either |

### `POST /auth/reset-password`: `resetPassword` (public)

Body `{ token, password }`.

| Status | When |
|---|---|
| `204` | password set, every session ended, lock cleared |
| `400` | `RESET_TOKEN_INVALID` |
| `422` | password policy (`OUT_OF_RANGE`; `INVALID_ENUM_VALUE` is not used; equal to the address is `OUT_OF_RANGE` with a localised `detail`) |

### `POST /auth/change-password`: `changePassword` (session)

Body `{ current_password, new_password }`.

| Status | When |
|---|---|
| `204` | changed. Every other session ended |
| `401` | `UNAUTHENTICATED`, or `INVALID_CREDENTIALS` for a wrong current password |
| `422` | policy, or `new_password` equal to the current one |
| `423` | `ACCOUNT_LOCKED` |

A wrong current password returns the response-level code `INVALID_CREDENTIALS` with status 401. Clients distinguish it from `UNAUTHENTICATED` by `code`, as the conventions require.

### `POST /account/deletion-request`: `requestAccountDeletion` (session)

Body `{ password }`.

| Status | When |
|---|---|
| `200` | `data: { state: "pending_deletion", deletion_scheduled_for }`. A repeat returns the same date |
| `401` | `UNAUTHENTICATED` / `INVALID_CREDENTIALS` |
| `422` | a malformed body |
| `423` | `ACCOUNT_LOCKED` |

### `DELETE /account/deletion-request`: `cancelAccountDeletion` (session)

`204`, or `401`. It is a no-op when nothing is pending.

### `POST /auth/google`: `signInWithGoogle` (public, switched off)

Documented with `x-status: reserved` and `deprecated: false`, with the request shape `{ id_token, device: DeviceDescription }` and the `200` response identical to `signIn`. While `identity.google.enabled` is false the route is not registered, and every call is `404 NOT_FOUND`. The Contract test asserts only the 404.

---

## Verification gate (for later features)

A later write route adds the middleware alias `address.verified`. It then documents `403` via `components/responses.yaml#/Forbidden` with `EMAIL_NOT_VERIFIED` among its codes. Nothing in B1 is gated.
