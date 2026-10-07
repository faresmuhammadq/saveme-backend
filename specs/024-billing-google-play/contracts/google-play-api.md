# Contract: Google Play billing (what C10 binds to)

Machine-readable: [docs/openapi/paths/google-play.yaml](../../../docs/openapi/paths/google-play.yaml), `info.version` **v1.15**. This page is the prose summary; where they differ, the OpenAPI file wins.

## Who does what

| Step | Client (C10) | Server (this spec) |
|---|---|---|
| Before buying | `GET /billing/google/account-reference`; pass `obfuscated_account_id` to `BillingFlowParams.setObfuscatedAccountId` | creates the reference on first need |
| Buying | Play Billing flow; never grants locally | — |
| After `PurchasesUpdatedListener` | `POST /billing/google/purchases` with `Idempotency-Key` | reads Google, links, records, **acknowledges** |
| Acknowledging | **never** | always, for every linked paid purchase |
| Pending purchase | keep pending; recheck later with a new key | grants and acknowledges when Google reports it paid |
| Restore | `POST /billing/google/restore` with every owned purchase after sign-in and on tap | verifies each; refreshes the caller's linked ones |
| Renewal, grace, hold, pause, cancel, expiry, refund | nothing; re-read `GET /me/entitlements` on app start | follows Google's notifications and reconciliation |

## Endpoints

| Method | Path | Success | Errors |
|---|---|---|---|
| GET | `/billing/google/account-reference` | `200 {data: {obfuscated_account_id}}` | 401, 406, 426, 429 |
| POST | `/billing/google/purchases` | `200` / `202 {data: {purchase, entitlements, subscription}}` | 400 `IDEMPOTENCY_KEY_REQUIRED`/`_INVALID`, 401, 409 `PURCHASE_ALREADY_LINKED`/`IDEMPOTENCY_KEY_REUSED`/`IDEMPOTENCY_REQUEST_IN_PROGRESS`, 422 `PURCHASE_NOT_VERIFIED`/`VALIDATION_FAILED`, 426, 429, 503 `SERVICE_UNAVAILABLE` |
| POST | `/billing/google/restore` | `200 {data: {results[], entitlements, subscription}}` | as above, minus 409 `PURCHASE_ALREADY_LINKED` and 422 `PURCHASE_NOT_VERIFIED` (per item) |
| POST | `/webhooks/google-play` | `204` | 400 `MALFORMED_JSON`, 401 `PROVIDER_SIGNATURE_INVALID`, 503 — Google only |

## Shapes

- Request: `{"purchase_token": "…", "product_id": "plus_monthly"}`; restore `{"purchases": [ … ≤ 25 ]}`.
- `purchase`: `{product_id, state ∈ pending|active|grace|canceled|on_hold|paused|expired|revoked, acknowledged, auto_renew, current_period_end, test}`.
- `results[]`: `{index, product_id, outcome ∈ linked|already_yours|linked_elsewhere|not_verified|pending|unavailable}`.
- `entitlements`: spec 009 `EntitlementSummary`; `subscription`: spec 009 `MySubscription`.

## Client handling

| Answer | Do |
|---|---|
| 200 | show `entitlements`; do not acknowledge |
| 202 | keep pending; recheck later with a new key |
| 409 `PURCHASE_ALREADY_LINKED` | "This purchase belongs to another account. Sign in with that account." Do not retry |
| 422 `PURCHASE_NOT_VERIFIED` | drop the token; do not retry |
| 503, 429 | retry with backoff and the **same** key |
| 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` | wait `Retry-After`, retry same key |

## Product ids

From `GET /plans` → `prices[]` with `channel = google_play` → `product_id`. One Play subscription product per plan, one auto-renewing base plan each (launch: `plus_monthly`, `plus_yearly`). Display Play's localised price, not `amount_minor`. A plan with no `google_play` price is not sold in the app.

## New error codes (v1.15)

| Code | Status | Retryable | Meaning |
|---|---|---|---|
| `PURCHASE_ALREADY_LINKED` | 409 | never | This Google Play purchase belongs to another account. Sign in with the account that bought it. |
| `PURCHASE_NOT_VERIFIED` | 422 | never | Google Play does not confirm this purchase for this app and product. Nothing was granted. |
