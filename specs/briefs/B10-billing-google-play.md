# B10 · Billing — Google Play

**Call with**: `/speckit-specify B10` · **Source**: the Phase B brief set, recorded 2026-09-25

## Description

The Android app purchases a subscription or lifetime product through Play Billing and posts the purchase token to `POST /billing/google/purchases`; the backend verifies it with the Play Developer API, maps `productId` → `plan_prices.provider_product_id`, creates/updates the subscription, acknowledges the purchase, and returns the new entitlements. Real-time Developer Notifications arrive via Pub/Sub push to `POST /webhooks/google-play` and drive the state machine (purchased, renewed, in grace, on hold, paused, canceled, expired, revoked). `POST /billing/google/restore` accepts a list of tokens after login and links any that belong to no user yet.

## Considerations

Webhook must verify the Pub/Sub JWT and store the raw notification in an inbox table before processing; processing is idempotent by `purchaseToken + notificationType + eventTime`. A token already linked to another user is rejected (`PURCHASE_ALREADY_LINKED`). Unacknowledged purchases are refunded by Google after 3 days — acknowledge in the same request as verification. Lifetime product is a non-consumable one-time purchase. Keep a service-account credential outside the repo. Testing: license testers and Play's test purchase tokens; specify a fake `PlayClient` for the test suite.

## Reconcile at specify time

The brief predates decisions ratified since. These collide with it; the ratified side wins unless the spec deliberately supersedes it through a decision record or an amendment.

| Brief says | Already decided | Resolution needed |
|---|---|---|
| Webhook verification | Security baseline: five checks including **audience** and a five-minute window; an already-seen message is acknowledged without effect | Follow the baseline; its replay window is 24 hours by message id |
| `PURCHASE_ALREADY_LINKED` | Not in the catalogue | Add it to `error-catalog.yaml` and bump `info.version` |
| `POST /billing/google/purchases` and restore | P-10: in-app surface is restore and enter-code; spec 003: both carry `Idempotency-Key` | Required |
| Play Developer API client, Pub/Sub JWT verification | No Google SDK installed | New dependency — owner approval |
