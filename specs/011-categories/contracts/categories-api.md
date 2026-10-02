# Contract: Wallets & Ledger API (categories)

The machine-readable contract is [`docs/openapi/paths/categories.yaml`](../../../docs/openapi/paths/categories.yaml), indexed from `openapi.yaml` (`info.version` v1.4). **It is frozen for the C5 client** and wins over this summary. Every operation takes the shared client headers (`X-Client-Platform`, `X-Client-Version`, optional `Accept-Language`, `X-Correlation-Id`), sends `X-Request-Id` and `Content-Language`, and may return the shared refusals `400`, `401`, `406`, `415` (writes), `426`, `500`.

## What the C5 client binds to ("Needs from the backend")

| C5 need | Answer |
|---|---|
| What "use" means for a platform category | **Reference.** A transaction, recurring rule or budget carries the platform category's `id` directly. No copy is ever made in the person's set (ADR-0014 rule 3) |
| How archiving a platform category works | **It does not.** A person cannot archive or hide a platform category; filter locally if wanted. The platform may **retire** one: it then has `archived_at` set — keep it valid on existing records, hide it from the picker (same rule as an archived own category) |
| The `system_key` list | Spec 011 §3: 19 expense keys (`groceries` … `other_expense`) and 7 income keys (`salary` … `other_income`), with en/ar names, icon keys and colours. Keys never change or get reused; new keys may be added |
| Category Reference shape | `CategoryReference`: `id`, `wallet_id`, `version`, `change_seq`, `created_at`, `updated_at`, `deleted_at`, `category_id`, `owner_user_id`, `kind`, `name`, `icon`, `color`. Read from `GET /wallets/{id}/category-references` now; it rides the wallet feed when B14 ships |
| Own category schema | `Category`: `id`, `user_id`, `version`, `change_seq`, `created_at`, `updated_at`, `deleted_at`, `kind`, `name`, `icon`, `color`, `archived_at`, `in_use` |
| Platform category schema | `PlatformCategory`: `id`, `system_key`, `kind`, `names {ar, en}`, `icon`, `color`, `position`, `archived_at`, `updated_at` |
| Names and language | Platform: both `names.ar` and `names.en` always present; pick by app language, offline. Own categories: `name` as typed, never translated |
| Nesting | None in MVP; `parent_id` is reserved server-side and not on the wire |
| Resolving `category_id` on a transaction | Look it up in: own categories → platform set → the wallet's category references |
| Archived categories | Stay valid on existing records; hide from pickers. Whether a **new** transaction may use one is B6's (recommended: allowed) |
| Delete vs archive | `in_use: true` → offer archive only; deleting it is `409 CATEGORY_IN_USE` |
| Versioning | `version` in the JSON body (no `If-Match`), exactly as wallets: `409 VERSION_CONFLICT` with `current` (possibly a tombstone); `422` with `errors[].pointer` |

## Operations

| Op | Request body | Success | Notes |
|---|---|---|---|
| `GET /categories` | — | `200` page of `Category` | own only; live incl. archived; `x-sortable: [created_at, name]`, `x-filterable: []` |
| `GET /categories/{id}` | — | `200` `Category` | owner only; tombstone visible to owner |
| `PUT /categories/{id}` | `CategoryWrite` (`kind`, `name`, `icon`, `color` all required; `version` omitted/0 to create) | `201` / `200` | identical body → `200` unchanged |
| `PATCH /categories/{id}` | `CategoryPatch` (≥ 1 of `name`, `icon`, `color`; `version` ≥ 1) | `200` | never creates; `kind` → `UNKNOWN_FIELD` |
| `POST /categories/{id}/archive` | `{version}` | `200` | idempotent; one-way |
| `DELETE /categories/{id}` | `{version}` (**a JSON body on DELETE**) | `200` tombstone | unused only; idempotent; final |
| `GET /platform-categories` | — | `200` all entries, one page | ordered by `position`; any other method `405` |
| `GET /wallets/{id}/category-references` | — | `200` page of `CategoryReference` | any active member; non-member `404`; any other method `405`; empty until B6 |

## Errors

| Situation | Response |
|---|---|
| Not verified, any write | `403 EMAIL_NOT_VERIFIED` |
| Another person's category, a platform category, or another kind of record | `404 NOT_FOUND` |
| Kind changed | `422`, `IMMUTABLE_FIELD` at `/kind` |
| Blank or > 50-character name | `422`, `OUT_OF_RANGE` at `/name` |
| Server-owned member sent | `422`, `UNKNOWN_FIELD` at its pointer |
| Delete while in use | `409 CATEGORY_IN_USE` (new in v1.4; retryable `never` — archive instead) |
| Write to a reference or a platform category path | `405 METHOD_NOT_ALLOWED`, `Allow: GET` |

> `CATEGORY_IN_USE` is frozen here and in spec 011 §5. It enters `docs/openapi/error-catalog.yaml` together with the runtime's `ErrorCode` enum and its ar/en strings in implementation task T004, because the catalogue parity test requires all three to change at once.

## Precedence on every write

`401` → `403 EMAIL_NOT_VERIFIED` → `422 INVALID_ID` → `422 VALIDATION_FAILED` (shape) → `404 NOT_FOUND` → `409` on a tombstone (non-delete) → `200` replay (and DELETE of a tombstone) → `409 VERSION_CONFLICT` (stale) → `422 IMMUTABLE_FIELD` → `409 CATEGORY_IN_USE` (delete) → write.

## Examples

Create:

```http
PUT /api/v1/categories/0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f71
{ "kind": "expense", "name": "Coffee", "icon": "cup", "color": "#6D4C41", "version": 0 }
```

```json
{ "data": { "id": "0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f71", "user_id": "0192f79e-0000-7000-8000-000000000001",
  "version": 1, "change_seq": 412, "created_at": "2026-10-02T08:30:00.123456Z", "updated_at": "2026-10-02T08:30:00.123456Z",
  "deleted_at": null, "kind": "expense", "name": "Coffee", "icon": "cup", "color": "#6D4C41",
  "archived_at": null, "in_use": false }, "meta": {} }
```

Platform set (first entry):

```json
{ "data": [ { "id": "0192f700-…", "system_key": "groceries", "kind": "expense",
  "names": { "ar": "بقالة", "en": "Groceries" }, "icon": "cart", "color": "#2E7D32",
  "position": 1, "archived_at": null, "updated_at": "2026-10-02T00:00:00.000000Z" }, … ],
  "meta": { "next_cursor": null, "has_more": false, "limit": 26 } }
```

Category reference:

```json
{ "id": "0192f7b1-…", "wallet_id": "0192f7a0-…", "version": 2, "change_seq": 57,
  "created_at": "…", "updated_at": "…", "deleted_at": null,
  "category_id": "0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f71", "owner_user_id": "0192f79e-…",
  "kind": "expense", "name": "Coffee", "icon": "cup", "color": "#6D4C41" }
```
