# Contract: Export & Deletion API (spec 022)

The machine-readable contract is `docs/openapi/paths/exports.yaml` and the `/account/deletion-request` entry of `docs/openapi/paths/identity.yaml` (`info.version` v1.13). This page states what the C15 client binds to.

## Operations

| Method | Path | Answer |
|---|---|---|
| POST | `/api/v1/exports` | `202` Export (`queued`) · `409 EXPORT_IN_PROGRESS` + `current` · `404` wallet · `422` · `429` |
| GET | `/api/v1/exports` | `200` Export collection, newest first, cursor-paged (shared `limit` parameter, default 20, clamped to the shared maximum) |
| GET | `/api/v1/exports/{id}` | `200` Export · `404` |
| GET | `/api/v1/account/deletion-request` | `200` DeletionPlan |
| POST | `/api/v1/account/deletion-request` | `200` DeletionSchedule (+ `successors`) · `401 INVALID_CREDENTIALS` · `422` · `423` |
| DELETE | `/api/v1/account/deletion-request` | `204` (drops successor choices) |
| GET | `/exports/{id}/file?expires&signature` (web, signed) | `200 application/zip` attachment · `404` |

All `/api/v1` operations: bearer, `X-Client-Platform`, `X-Client-Version`, the success envelope, problem details. None takes `Idempotency-Key`.

## Export

```json
{
  "id": "0192f9a0-6b1c-7c3d-8e4f-5a6b7c8d9e0f",
  "scope": "account",
  "wallet_id": null,
  "status": "ready",
  "failure_code": null,
  "requested_at": "2026-10-06T10:00:00.000000Z",
  "completed_at": "2026-10-06T10:00:41.120000Z",
  "expires_at": "2026-10-07T10:00:41.120000Z",
  "size_bytes": 184320,
  "download_url": "https://api.example/exports/0192f9a0-…/file?expires=1791286541&signature=…",
  "download_url_expires_at": "2026-10-06T10:15:41.000000Z"
}
```

`download_url`/`download_url_expires_at` are non-null only when `status = ready`. `failure_code` non-null only when `failed`.

## What C15 binds to

- **Request**: `{ "scope": "account" }` or `{ "scope": "wallet", "wallet_id": "…" }`.
- **Polling**: `GET /exports/{id}` while `queued`/`running` (5 s suggested, stop after 10 minutes and show "still preparing; we'll email you").
- **Download**: open `download_url` in the system browser or share sheet; never store the file in app storage. A link older than 15 minutes: read the export again for a fresh one.
- **One active export**: `409 EXPORT_IN_PROGRESS`; `current` is the active export — show it.
- **Rate limit**: 5 accepted requests per 24 h → `429 RATE_LIMITED` + `Retry-After`.
- **No gating**: not behind `export.enabled`, not behind address verification; works during the deletion grace period.
- **Archive**: ZIP with `manifest.json`, JSON per record kind, `transactions.csv` (UTF-8 BOM, minor units + currency). Other members appear only as `author: "other_member"`.
- **Ownership choice**: `GET /account/deletion-request` → `successors[]` per owned shared wallet `{wallet_id, successor_user_id, reason, choice_expected}`. Ask the person only where `choice_expected` is true; candidates are the wallet's active members the app already holds. Send `successors: [{wallet_id, member_user_id}]` with the password on `POST`. Repeating the POST during the grace period updates choices; the date never moves.
- **Fallbacks**: only one other member (any role, a viewer included) → becomes owner (`only_member`); no choice → oldest editor; no editor → oldest viewer; nobody else → the wallet is deleted with the account.
- **Google Play**: deleting the account does not cancel a Play subscription; say so in the deletion flow.
- **After the purge**: other members see a new owner on the wallet feed; the departed member's entries keep `created_by`, which no longer matches any live membership — render "Former member".

## Errors added to operations (all existing codes)

| Operation | Codes |
|---|---|
| `requestExport` | `UNAUTHENTICATED`, `MALFORMED_JSON`, `VALIDATION_FAILED` (`/scope` `INVALID_ENUM_VALUE`/`REQUIRED`, `/wallet_id` `REQUIRED`/`INVALID_ID`/`UNKNOWN_FIELD`), `NOT_FOUND`, `EXPORT_IN_PROGRESS`, `RATE_LIMITED` |
| `listExports` | `UNAUTHENTICATED`, `INVALID_CURSOR`, `INVALID_QUERY_PARAMETER` |
| `getExport` | `UNAUTHENTICATED`, `NOT_FOUND`, `INVALID_ID` (422) |
| `getAccountDeletionPlan` | `UNAUTHENTICATED` |
| `requestAccountDeletion` | existing + `VALIDATION_FAILED` at `/successors/{n}/wallet_id` or `/successors/{n}/member_user_id` (`INVALID_ENUM_VALUE`, `INVALID_ID`), `/successors` (`OUT_OF_RANGE` above 100) |
