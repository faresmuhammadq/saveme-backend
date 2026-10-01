# Contract: Wallets & Ledger API (wallets and membership)

The machine-readable contract is [`docs/openapi/paths/wallets.yaml`](../../../docs/openapi/paths/wallets.yaml), indexed from `openapi.yaml` (`info.version` v1.3). **It is frozen for the C4 client** and wins over this summary. Every operation takes the shared client headers (`X-Client-Platform`, `X-Client-Version`, optional `Accept-Language`, `X-Correlation-Id`), sends `X-Request-Id` and `Content-Language`, and may return the shared refusals `400`, `401`, `406`, `415` (writes), `426`, `500`.

## What the C4 client binds to

| C4 need | Answer |
|---|---|
| Wallet schema | `Wallet`: `id`, `wallet_id` (= `id`), `version`, `change_seq`, `created_at`, `updated_at`, `deleted_at`, `type`, `name`, `currency`, `opening_balance_minor`, `balance_minor`, `icon`, `color`, `archived_at`, `currency_locked`, `my_role` |
| `type` enum | `cash`, `checking`, `savings` (حصالة), `credit_card`, `loan`, `investment`; liabilities `credit_card`, `loan` carry negative balances when owed |
| Money fields | `opening_balance_minor`, `balance_minor`: signed integers, minor units of `currency` |
| Member schema | `WalletMember`: `id`, `wallet_id`, `version`, `change_seq`, `created_at`, `updated_at`, `deleted_at`, `member_user_id`, `display_name`, `role` |
| Permission matrix | spec 010 §6 (the only copy); `my_role` per wallet |
| Currency change after the first transaction | `422 VALIDATION_FAILED`, `errors[] = {pointer: "/currency", code: "IMMUTABLE_FIELD"}`; `currency_locked` mirrors it |
| Type change | `422`, `IMMUTABLE_FIELD` at `/type` |
| Role violations | `403 FORBIDDEN` (member, role too low); `404 NOT_FOUND` (not a member); `403 EMAIL_NOT_VERIFIED` (unverified writer); `403 FEATURE_NOT_ENTITLED` (plan) |
| Transfer target invalid | `422`, `INVALID_ENUM_VALUE` at `/user_id` |
| Is `wallets.max` enforced? | Yes, on create, via entitlements; unlimited on every launch plan, so `403 FEATURE_NOT_ENTITLED` is not reachable today but must be handled |
| Versioning | `version` in the JSON body (no `If-Match`), exactly as `PUT /me/profile`: `200` with the record; `409 VERSION_CONFLICT` with `current` (the read schema, possibly a tombstone); `422` with `errors[].pointer` |

## Operations

| Op | Request body | Success | Notes |
|---|---|---|---|
| `GET /wallets` | — | `200` page of `Wallet` | `x-sortable: [created_at, name]`, `x-filterable: []`; live wallets incl. archived |
| `GET /wallets/{id}` | — | `200` `Wallet` | tombstone visible only to members at deletion |
| `PUT /wallets/{id}` | `WalletWrite` (all of `type`, `name`, `currency`, `opening_balance_minor`, `icon`, `color`; `version` omitted/0 to create) | `201` / `200` | identical body → `200` unchanged |
| `PATCH /wallets/{id}` | `WalletPatch` (≥ 1 of `name`, `currency`, `opening_balance_minor`, `icon`, `color`; `version` ≥ 1) | `200` | never creates; `type` → `UNKNOWN_FIELD` |
| `POST /wallets/{id}/archive` | `{version}` | `200` | idempotent |
| `DELETE /wallets/{id}` | `{version}` (**a JSON body on DELETE** — send it explicitly) | `200` tombstone | idempotent; final |
| `GET /wallets/{id}/members` | — | `200` page of `WalletMember` | ordered by membership creation |
| `POST /wallets/{id}/transfer-ownership` | `{user_id, version}` | `200` `Wallet` (`my_role: editor`) | already owner → `200` unchanged |

## Precedence on every write

`401` → `403 EMAIL_NOT_VERIFIED` → `422 INVALID_ID` → `422 VALIDATION_FAILED` (shape) → `404 NOT_FOUND` → `403 FORBIDDEN` → `403 FEATURE_NOT_ENTITLED` → `409` on a tombstone (non-delete) → `200` replay → `409 VERSION_CONFLICT` (stale) → `422` state rules → write.

## Examples

Create:

```http
PUT /api/v1/wallets/0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70
{ "type": "cash", "name": "Cash", "currency": "EGP", "opening_balance_minor": 50000, "icon": "wallet", "color": "#2E7D32", "version": 0 }
```

```json
{ "data": { "id": "0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70", "wallet_id": "0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70",
  "version": 1, "change_seq": 3, "created_at": "2026-10-02T08:30:00.123456Z", "updated_at": "2026-10-02T08:30:00.123456Z",
  "deleted_at": null, "type": "cash", "name": "Cash", "currency": "EGP", "opening_balance_minor": 50000,
  "balance_minor": 50000, "icon": "wallet", "color": "#2E7D32", "archived_at": null,
  "currency_locked": false, "my_role": "owner" }, "meta": {} }
```

Currency locked:

```json
{ "type": "https://api.example/problems/validation-failed", "title": "Validation failed", "status": 422,
  "detail": "The request has 1 invalid field.", "code": "VALIDATION_FAILED", "request_id": "0192f7a0-…",
  "errors": [ { "pointer": "/currency", "code": "IMMUTABLE_FIELD", "detail": "The currency cannot change once a transaction is recorded in this wallet." } ] }
```
