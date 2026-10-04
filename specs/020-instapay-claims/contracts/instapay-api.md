# Contract: InstaPay claims and redeem (v1.12)

**Source of truth**: [`docs/openapi/paths/instapay.yaml`](../../../docs/openapi/paths/instapay.yaml), indexed from `openapi.yaml` (`info.version` v1.12, additive). Where this summary and the YAML differ, the YAML wins. Frozen for the landing page and the C11 client.

## Operations

| Method | Path | operationId | Who | Success | Errors |
|---|---|---|---|---|---|
| POST | `/billing/instapay/claims` | `submitInstapayClaim` | anyone, `X-Client-Platform: web` only | 202 | 400, 404 (non-web), 406, 409 (idempotency), 415, 422, 426, 429, 500 |
| POST | `/billing/redeem` | `redeemCode` | signed-in, verified, address = claim's | 200 | 400, 401, 403, 404, 406, 409, 410, 415, 422, 426, 429, 500 |

Both require `Idempotency-Key` (16–255 of `[A-Za-z0-9._:-]`). Neither is syncable; neither is a sync-batch item.

## New response codes (v1.12)

| Code | Status | Retryable | Meaning |
|---|---|---|---|
| `REDEEM_CODE_EMAIL_MISMATCH` | 403 | after-change | The code was sent to a different address than this account's. Sign in with the address the code was sent to. |
| `REDEEM_CODE_USED` | 409 | never | Another account already redeemed this code. |
| `REDEEM_CODE_EXPIRED` | 410 | never | The code is past its 30 days. Contact support. |

## Claim

Request `{email, plan_id, amount_minor, currency, reference, screenshot?: {media_type, data}}` → `202 {data: {id, status: "submitted", plan: {id, name}, amount: {amount_minor, currency}, expires_at}, meta: {}}`.

Field refusals (422 `VALIDATION_FAILED`): `/plan_id` `INVALID_ENUM_VALUE`; `/currency` `CURRENCY_MISMATCH`; `/amount_minor` `OUT_OF_RANGE` or `AMOUNT_NOT_INTEGER`; `/reference` `INVALID_TYPE`; `/email` `INVALID_TYPE` / `OUT_OF_RANGE`; `/screenshot/media_type` `INVALID_ENUM_VALUE`; `/screenshot/data` `INVALID_TYPE` / `OUT_OF_RANGE`; anything else `UNKNOWN_FIELD`; missing members `REQUIRED`.

Budgets: 5/hour/origin, 5/24h/address → 429.

## Redeem

Request `{code}` → `200 {data: {subscription: {id, plan, provider: "instapay", status, current_period_start, current_period_end}, governing: MySubscription, entitlements: EntitlementSummary}, meta: {}}`.

Order: 401 → 400 key → idempotency replay/409 → 422 `/code` → 429 (10/hour/person) → 404 unknown/revoked → 200 same account repeat → 409 `REDEEM_CODE_USED` → 410 `REDEEM_CODE_EXPIRED` → 403 `REDEEM_CODE_EMAIL_MISMATCH` → 403 `EMAIL_NOT_VERIFIED` → 200.

## What C11 binds to

| C11 needs | Answer |
|---|---|
| Code format | 16 Crockford base32 characters, `XXXX-XXXX-XXXX-XXXX`; mask to four groups of four; any case; the server folds `O`→`0`, `I`/`L`→`1` |
| Errors | expired `410 REDEEM_CODE_EXPIRED`; used `409 REDEEM_CODE_USED`; email mismatch `403 REDEEM_CODE_EMAIL_MISMATCH` ("sign in with the address the code was sent to", address never named); unknown `404 NOT_FOUND`; unverified `403 EMAIL_NOT_VERIFIED`; rate `429 RATE_LIMITED` + `Retry-After`; malformed `422` `/code` |
| Response | `subscription` (what was redeemed), `governing` (show "now on …" from it — may be a Play subscription that ends later), `entitlements` (refresh gates) |
| Idempotency | generate one key per tap of "Redeem"; resend the same key on a network retry |
| Overlap with Play | Play is never changed; the latest-ending subscription governs; tell the person Play still bills if it is set to renew |

## What the landing page binds to

Send `X-Client-Platform: web`, `X-Client-Version`, `Accept-Language` (`ar`/`en`; mails follow it), `Idempotency-Key` (one per form submission, reused on retry). Read plans and the InstaPay price from `GET /plans` (`prices[].channel = instapay`); the payee address is the landing page's own configuration. Show the `202`'s `id` and `expires_at`; never claim that the address has an account. Encode the screenshot as base64 (≤ 3 MiB decoded; downscale client-side if larger). Body limit at the edge ≥ 5 MB.
