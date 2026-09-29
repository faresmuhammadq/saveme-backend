# Quickstart: Plans & Entitlements

How to prove that B3 works. For the contract, see [contracts/billing-api.md](./contracts/billing-api.md), and for the tables and launch rows, see [data-model.md](./data-model.md).

## Gates

```bash
dev migrate
dev db:seed --no-interaction           # BillingSeeder: free, plus_monthly, plus_yearly
dev exec -- composer test
dev exec -- vendor/bin/pest tests/Unit/Billing tests/Feature/Billing tests/Contract/Billing
```

## Manual walk

Use the client headers and a credential from `POST /auth/login` (see spec 007's quickstart).

| # | Do | Expect |
|---|---|---|
| 1 | `GET $API/plans` without a credential, with `Accept-Language: ar` | Three plans in order: Free, Plus monthly, Plus yearly. Arabic names. Plus prices `14900`/`129000` EGP. No `code` member |
| 2 | Register, sign in, then `GET $API/me/entitlements` | `plan.name` "Free", `sharing.enabled: false`, `sharing.max_members_per_wallet: 1`. The same object as in the sign-in response |
| 3 | `GET $API/me/subscription` | The Free plan, with `provider`, `status` and the dates all null |
| 4 | `dev tinker`: `app(OperatorSubscriptions::class)->grant('<operator uuid>', '<user id>', '<plus_monthly id>', 30, 'Walk test')` | Steps 2 and 3 now show Plus, `sharing.enabled: true`, provider `manual`, status `active`, and an end 30 days out. One `entitlement_changed` row |
| 5 | Set that subscription's `current_period_end` to the past, then run `dev billing:lapse-subscriptions` | Status `grace`, still Plus. After moving `grace_until` to the past and running it again: `expired`, and entitlements are back to Free |
| 6 | `extend(…, 30, 'Walk test')` on the expired subscription | `active` again, Plus again |
| 7 | `revoke(…, 'Walk test')` | `revoked`, Free. `extend` is now refused |
| 8 | `select count(*) from subscriptions` after registering a new person | Unchanged: registration creates no subscription |

## Apidog

Re-bundle `docs/openapi` and re-import into project 1332822 with `--module-map "Personal finance backend API=1363204"`. Add a "Plans & entitlements" doc covering the catalogue, the entitlement summary, and why the app gates on `features` rather than on plan names.

## Verification (2026-09-28)

- **Gate**: `dev exec -- composer test` is green. Larastan level 8 reports no errors, the architecture suite has 46 tests (P-09's `PlanCode` gate included), and the test suite has 502 tests (1,938 assertions).
- **Manual walk**: steps 1–8 were run against `http://saveme-backend.test` after `dev db:seed`.
  1. `/plans` in Arabic returned مجاني, بلس · شهري and بلس · سنوي, with prices `[]`, `[14900]` and `[129000]`, and no `code` member.
  2. A new person's entitlements showed Free: sharing off, 1 member, `wallets.max` null.
  3. `/me/subscription` showed Free with every other member null.
  4. After an operator grant, the entitlements showed Plus · Monthly with sharing on.
  5. The lapse moved the subscription to grace, then to expired, and the entitlements showed Free again.
  6. Extending it made it `active` again.
  7. Revoking it made it `revoked`, and a further extension was refused (`SubscriptionRevoked`).
  8. Registering created 0 subscriptions.
  There were 4 `entitlement_changed` entries: grant, expire, extend and revoke.
- **Apidog**: `listPlans`, `getMyEntitlements` and `getMySubscription` were imported (3 created, 0 errors; 19 endpoints in total). The new doc "Plans & entitlements" has id 2445715. The Identity guide's sign-in section now describes `entitlements` as `{ plan: { id, name }, features }`.
- **Change beyond the plan**: ADR-0013 adds `grace → active` (a recovered payment or an extension during grace). ADR-0007's machine lacked it, and operator extension needs it. The spec, data model, `SubscriptionStatus` and its unit test all follow.
- **Implementation detail**: the entitlement memo is keyed on the request id as well as scoped, so it never outlives a request even where the container does (the test process, long-running workers).
