# Quickstart: InstaPay claims and redeem (spec 020)

Validation guide for the built feature. Contract: [contracts/instapay-api.md](./contracts/instapay-api.md); data: [data-model.md](./data-model.md).

## Prerequisites

```bash
dev project open
dev migrate
dev db:seed --class='App\Modules\Billing\Infrastructure\Database\Seeders\BillingSeeder'   # plans with InstaPay prices
dev exec -- php artisan queue:work --stop-when-empty   # or a running worker
```

`.env` holds `BILLING_KEYS` and `BILLING_ACTIVE_KEY` (generate a key with `php -r 'echo base64_encode(random_bytes(32));'`), and at least one operator exists (`dev admin:operator:create`). Mail lands in <http://mailpit.test>.

## 1. Submit a claim (website)

```bash
PLAN=$(curl -s http://saveme-backend.test/api/v1/plans -H 'X-Client-Platform: web' -H 'X-Client-Version: 1.0.0' | jq -r '.data[] | select(.prices[]?.channel=="instapay") | .id' | tail -1)
curl -si http://saveme-backend.test/api/v1/billing/instapay/claims \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -H 'X-Client-Platform: web' -H 'X-Client-Version: 1.0.0' -H 'Accept-Language: en' \
  -H 'Idempotency-Key: claim-quickstart-0001' \
  -d "{\"email\":\"mona@example.com\",\"plan_id\":\"$PLAN\",\"amount_minor\":129000,\"currency\":\"EGP\",\"reference\":\"4815 1623 4211\"}"
```

Expect `202`, `status: submitted`, `expires_at` 7 days out. Repeat the same command: same body with `Idempotency-Replayed: true`. Change the address to an unregistered one with a new key: same status and members. Send `X-Client-Platform: android`: `404`. Wrong amount: `422` at `/amount_minor`.

## 2. Approve from the command line

```bash
dev billing:claim:list
dev billing:claim:approve <claim-id> --operator=<operator-uuid>
```

Expect one mail to `mona@example.com` with a code `XXXX-XXXX-XXXX-XXXX`; `payment_claims.status = approved`; one `claim_reviewed` audit entry; `redeem_codes.code_hash` set and no plaintext anywhere in the database.

## 3. Redeem in the app (or with curl)

Register and verify `mona@example.com`, sign in, then:

```bash
curl -si http://saveme-backend.test/api/v1/billing/redeem \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -H 'X-Client-Platform: android' -H 'X-Client-Version: 1.0.0' -H 'Idempotency-Key: redeem-quickstart-0001' \
  -d '{"code":"<code from the mail, any case>"}'
```

Expect `200` with `subscription.provider = instapay`, `governing`, `entitlements`; `GET /me/entitlements` answers Plus. Redeem again with a new key: `200`, same subscription id. From another account: `403 REDEEM_CODE_EMAIL_MISMATCH`.

## 4. Scheduled work

```bash
dev billing:expire-claims
dev billing:expire-redeem-codes
dev billing:send-renewal-reminders
dev billing:purge-claims
dev billing:purge-idempotency-records
dev schedule:list | grep billing
```

## 5. Tests

```bash
dev exec -- vendor/bin/pest tests/Unit/Billing tests/Feature/Billing tests/Contract/Billing tests/Feature/Admin/ClaimReceiptRouteTest.php --compact
dev exec -- vendor/bin/pest tests/Contract/OpenApi --compact
dev exec -- composer test
```
