# Quickstart: Billing — Google Play

Validation run for spec 024. Everything runs in the container (`dev`); host PHP has no `pdo_pgsql`.

## Prerequisites

- `dev up`, `dev migrate`, `dev db:seed --class='App\Modules\Billing\Infrastructure\Database\Seeders\BillingSeeder'`.
- `.env`: `BILLING_PLAY_DRIVER=fake`, `BILLING_PLAY_PACKAGE=com.example.app`, throwaway `BILLING_PLAY_ACCOUNT_KEYS`/`_ACTIVE_KEY`, `BILLING_PLAY_LOOKUP_KEY`, and `BILLING_PLAY_PRICE_PLUS_MONTHLY=14900` so the seeder maps `plus_monthly` (see [data-model.md](./data-model.md#configuration-configbillingphp--google_play)).
- A queue worker: `dev queue:work --queue=billing,default`.

## 1. The gate

```bash
dev exec -- vendor/bin/pest tests/Contract/OpenApi          # google-play.yaml indexed, v1.15, codes catalogued
dev exec -- vendor/bin/pest tests/Unit/Billing/GooglePlay
dev exec -- vendor/bin/pest tests/Feature/Billing/GooglePlay
dev exec -- vendor/bin/pest tests/Contract/Billing --filter=GooglePlay
dev exec -- composer test                                   # whole gate
```

Expected: all green; no change to `composer.json` or `package.json` (`git diff --stat composer.json package.json` empty).

## 2. Mapping

`GET /api/v1/plans` lists `prices[]` with `{channel: google_play, product_id: plus_monthly, …}` on Plus · Monthly. Re-running the seeder changes nothing.

## 3. Buy (fake gateway)

Script the fake (`BILLING_PLAY_FAKE_SCRIPT` JSON: token `t-1` → subscription, `plus_monthly`, `ACTIVE`, expiry +30 days, reference = the account's). Sign in, then:

1. `GET /api/v1/billing/google/account-reference` → 64 hex characters.
2. `POST /api/v1/billing/google/purchases` with `Idempotency-Key` and `{purchase_token: "t-1", product_id: "plus_monthly"}` → `200`, `purchase.acknowledged: true`, `entitlements.features."sharing.enabled": true`.
3. Same request again → identical body, `Idempotency-Replayed: true`.
4. As a second account, post `t-1` with a new key → `409 PURCHASE_ALREADY_LINKED`.

## 4. Webhook

Tests sign push tokens with a generated key pair served as the certificate set (`Http::fake`). Manually, with the `fake` driver, `POST /api/v1/webhooks/google-play` without a bearer → `401 PROVIDER_SIGNATURE_INVALID`, nothing in `play_notifications`.

## 5. Reconciliation

`dev billing:play-reconcile` and `dev billing:play-sweep` run twice in a row change nothing the second time; `dev billing:lapse-subscriptions` never touches a `google_play` row.

## 6. Privacy

`grep -R "t-1" storage/logs` finds nothing; `play_purchases.token_sealed` starts with `v1.`; no response body contains a token.

## 7. Production readiness (owner)

Set `BILLING_PLAY_DRIVER=google` with the service-account key, package name, push audience/service account/subscription; the app refuses to boot if any is missing, and refuses `fake` outside local/testing.
