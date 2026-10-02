# Contract: Wallets & Ledger API (transactions)

The machine-readable contract is [`docs/openapi/paths/transactions.yaml`](../../../docs/openapi/paths/transactions.yaml), indexed from `openapi.yaml` (`info.version` v1.5). **It is frozen for the C6 client** and wins over this summary. Every operation takes the shared client headers (`X-Client-Platform`, `X-Client-Version`, optional `Accept-Language`, `X-Correlation-Id`), sends `X-Request-Id` and `Content-Language`, and may return the shared refusals `400`, `401`, `406`, `415` (writes), `426`, `500`.

## What the C6 client binds to ("Needs from the backend")

| C6 need | Answer |
|---|---|
| Transaction schema | `Transaction`: `id`, `wallet_id`, `version`, `change_seq`, `created_at`, `updated_at`, `deleted_at`, `kind`, `date`, `amount_minor` (positive), `currency`, `category_id` (or null), `counter_wallet_id` (or null), `description`, `memo`, `counterparty` (each string or null), `cleared`, `created_by` |
| `kind` enum | `income`, `expense`, `transfer`; immutable |
| Per-record PUT path | `PUT /wallets/{id}/transactions/{tid}` — `{id}` the wallet (the source for a transfer), `{tid}` the client UUIDv7; `version` omitted/0 creates |
| Transfer on the wire | **One record**: `kind: transfer`, `wallet_id` = source, `counter_wallet_id` = destination, `category_id: null`, positive amount. Never two linked records. Listed in both wallets; addressable under either wallet's path; `counter_wallet_id` immutable (delete + re-create to move it) |
| Role on each side of a transfer | `owner` or `editor` in **both** wallets (rows T-02 and T-03). Viewer on either side → `403 FORBIDDEN` (also a non-member of the source writing to it through the destination's path); not a member of the path wallet → `404`; destination unknown / deleted / not a membership / same wallet → `422` `INVALID_ENUM_VALUE` at `/counter_wallet_id` |
| Currency change after first transaction | Wallet `PUT`/`PATCH` → `422 VALIDATION_FAILED`, field code `IMMUTABLE_FIELD` at `/currency`; the wallet reports `currency_locked: true` once any transaction (live or deleted) touches it |
| Cross-currency transfer | `422 VALIDATION_FAILED`, field code **`CURRENCY_MISMATCH`** (new, v1.5) at `/counter_wallet_id`; a transaction currency ≠ its wallet's → `CURRENCY_MISMATCH` at `/currency` |
| Version conflict | `409 VERSION_CONFLICT` with the full current `Transaction` (possibly a tombstone) in `current`; whole write refused, nothing merged |
| Restore after delete | `PATCH` with exactly `{ "deleted_at": null, "version": <current> }`. An ordinary edit of a tombstone is `409` |
| Feed row shape | The `Transaction` resource itself (spec 003, ADR-0008). A transfer appears identically on both wallets' feeds (ADR-0015); `change_seq` is opaque cursor material, never compared across wallets |
| Category resolution | `category_id` → own categories → platform set → the wallet's category references (spec 011) |
| Archived wallets / categories | Accepted on new and edited transactions |
| Balance | `wallet.balance_minor` is current on the very next read after any write; local balance = server balance + pending local changes |

## Operations

| Op | Request body | Success | Notes |
|---|---|---|---|
| `GET /wallets/{id}/transactions` | — | `200` page of `Transaction` | live only, incl. transfers in; `x-sortable: [date, created_at]` (default `-date`); `x-filterable: [date, category_id, kind]`; `search` |
| `GET /wallets/{id}/transactions/{tid}` | — | `200` `Transaction` | tombstone visible to members |
| `PUT /wallets/{id}/transactions/{tid}` | `TransactionWrite` (all members required; nullable ones may be null; `version` omitted/0 to create) | `201` / `200` | identical body → `200` unchanged |
| `PATCH /wallets/{id}/transactions/{tid}` | `TransactionPatch` (≥ 1 of `date`, `amount_minor`, `category_id`, `description`, `memo`, `counterparty`, `cleared`; `version` ≥ 1) **or** `TransactionRestore` | `200` | never creates |
| `DELETE /wallets/{id}/transactions/{tid}` | `{version}` (**a JSON body on DELETE**) | `200` tombstone | idempotent |

## Errors

| Situation | Response |
|---|---|
| Not verified, any write | `403 EMAIL_NOT_VERIFIED` |
| Not a member of the path wallet; transaction of another wallet or another kind of record; unknown id on PATCH/DELETE | `404 NOT_FOUND` |
| Viewer (either wallet of a transfer) | `403 FORBIDDEN` |
| Kind changed; transfer destination changed | `422`, `IMMUTABLE_FIELD` at `/kind` or `/counter_wallet_id` |
| Currency ≠ wallet's; cross-currency transfer | `422`, `CURRENCY_MISMATCH` at `/currency` or `/counter_wallet_id` |
| Category not own/platform, deleted, unknown, wrong kind | `422`, `INVALID_ENUM_VALUE` at `/category_id` |
| Amount not an integer / ≤ 0 | `422`, `AMOUNT_NOT_INTEGER` / `OUT_OF_RANGE` at `/amount_minor` |
| Server-owned member sent (`postings`, `wallet_id`, `created_by`, …) | `422`, `UNKNOWN_FIELD` at its pointer |
| Unsupported filter, operator or sort; bad `search` | `400` `FILTER_FIELD_NOT_SUPPORTED` / `FILTER_OPERATOR_NOT_SUPPORTED` / `SORT_FIELD_NOT_SUPPORTED` / `INVALID_QUERY_PARAMETER` |

> `CURRENCY_MISMATCH` is frozen here and in spec 012 §5. It enters `docs/openapi/error-catalog.yaml` together with the runtime's `ErrorCode` enum and its ar/en strings in implementation task T002, because the catalogue parity test requires all three to change at once (spec 011 A-18 precedent).

## Precedence on every write

Spec 012 §6 — `401` → `403 EMAIL_NOT_VERIFIED` → `422 INVALID_ID` → `422` shape → `404` → `403` (path wallet) → `409` tombstone → `200` replay → `409` stale → `403` (destination) → `422` state rules → write.
