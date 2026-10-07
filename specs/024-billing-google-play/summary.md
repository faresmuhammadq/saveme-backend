# Summary: Billing — Google Play, without the SDK (spec 024, B10)

**Date**: 2026-10-07 · **Status**: implemented (T001–T042), QA and E2E green, committed on `main` (2062c0c). **Nothing has run against real Google; production stays `disabled` until the owner completes the Play Console, service-account and Pub/Sub setup.**

## What was built

- **`PlayBillingGateway` port** with three adapters and no new dependency: `google` calls androidpublisher v3 (`subscriptionsv2.get`, `subscriptions:acknowledge`, `products.get`, `products:acknowledge`, `voidedpurchases.list`) through Laravel's HTTP client with an in-house service-account RS256 JWT signed by `openssl_sign` (the JWT code is copied from B16's, not imported; the access token is cached until 5 minutes before expiry); `fake` is scripted (`BILLING_PLAY_FAKE_SCRIPT`) and refused at boot outside local and testing; `disabled` is the default. `google` without a package name or credentials is refused at boot. Moving to Google's official library later means swapping that one adapter.
- **Four endpoints** (`docs/openapi/paths/google-play.yaml`, OpenAPI v1.15, `pubsubPush` security scheme):
  - `GET /billing/google/account-reference` → `{obfuscated_account_id}` (64 hex characters: a keyed hash of the account id, stored for reverse lookup; the app passes it as the obfuscated account id).
  - `POST /billing/google/purchases` `{purchase_token, product_id}` with required `Idempotency-Key`: 200 `{purchase{product_id, state, acknowledged, auto_renew, current_period_end, test}, entitlements, subscription}`, 202 pending, 409 `PURCHASE_ALREADY_LINKED`, 422 `PURCHASE_NOT_VERIFIED`, 503, 429 (20/h per person), plus the idempotency codes.
  - `POST /billing/google/restore` (0–25 items, `Idempotency-Key`, 10/h): one `outcome` per item — `linked`, `already_yours`, `linked_elsewhere`, `not_verified`, `pending` or `unavailable`; over 25 items or duplicate tokens → 422.
  - `POST /webhooks/google-play` (public, Pub/Sub push checked in-house): 204 (also for a repeat, a foreign subscription or package, a test or an unparsable-but-verified message), 400 `MALFORMED_JSON`, 401 `PROVIDER_SIGNATURE_INVALID`, 503; skips the client-version gate.
- **Push verification without a library**: Google's PEM certificates from `oauth2/v1/certs`, cached by `Cache-Control` (lifetime clamped to 5 minutes–24 hours) with one refresh on an unknown key id; `openssl_verify` RS256 (alg must be RS256); checks `iss`, `aud`, `email`, `email_verified`, `iat` within 5 minutes, `exp`; then a replay check on the message id.
- **The server always acknowledges**, after commit; the client never does. A purchase no account has linked is never acknowledged, so Google refunds it. **One purchase token links to exactly one account forever** (unique key): a purchase whose reference names another account gets 409; one without a reference goes to the first account that verifies it. Pending purchases answer 202 and are granted later by notification, sweep or restore; the client never grants locally.
- **Google is the source of truth**: Google's states map onto ADR-0013's status machine (account hold and pause become `expired` through a zero-length grace step with one `SubscriptionLapsed`, and return to `active` on recovery; grace comes from Google, never `billing.grace_days`; refund or void is `revoked`, final). Spec 009's date-driven lapse pass skips `google_play`; an hourly reconcile and a daily sweep (stale syncs, voided purchases, retention) ask Google instead; only after 72 hours of Google being unreachable does a subscription lapse by its dates, so a delayed renewal never triggers Sharing's demotion. Jobs run on the `billing` queue.
- **Plan catalogue**: Play product ids from config (`plus_monthly`, `plus_yearly`), seeded create-missing by `BillingSeeder`; the back office keeps them read-only; reference prices stay unset until the owner supplies them.
- **Operators** see auto-renew, acknowledged and test markers plus 24-hour counts; they cannot extend or revoke a `google_play` subscription (spec 021). **Account purge** leaves Play subscriptions alone (spec 022; the mails still say "cancel in Google Play"), erases the sealed token and the account reference; another account presenting such a token gets 409. **Audit** uses `entitlement_changed` with new actions `purchase`, `renew` and `recover` (ids only); the log scrubber gains new keys.
- **Docs**: ADR-0026, constitution v1.3.15, glossary, security baseline, privacy commitments, api-conventions; error codes `PURCHASE_ALREADY_LINKED` and `PURCHASE_NOT_VERIFIED` added in the catalogue, the `ErrorCode` enum and the en/ar lang files together (parity test).
- **Migrations (additive, applied to dev)**: `2026_10_13_000001` `play_account_references`, `000002` `play_purchases`, `000003` `play_notifications`. Config in `config/billing.php` and `.env.example` (`BILLING_PLAY_*`; `.env.example` sets the driver to `fake`, the config default is `disabled`; `BILLING_PLAY_CREDENTIALS` is a path or base64 JSON, separate from the FCM key).
- **Apidog**: bundle imported (all Play schemas and the four paths); project doc "Google Play billing" (2469427).

## Results

- **QA** (`qa/qa-20261007.md`): about 80 live checks on dev with the fake gateway, 0 failing — account reference, verify (replay, idempotency codes, 202, 409, 422, 503, 20/h and 10/h throttles), restore outcomes, every bad-token case on the webhook (401, no row written), state mappings, duplicate message id, ignored cases, the lapse pass skipping `google_play`, idempotent reconcile and sweep, the 72-hour fallback, purge erasing the sealed tokens and the reference (Billing's purge step only, on a throwaway user), boot guards (production with `fake` refused; `google` without a package refused), a log grep finding no token, reference, certificate or key, and the in-house RS256 JWT verified with the `openssl` CLI.
- **E2E** (`test-reports/validation-20261007.md`): 49 of 49 acceptance scenarios pass. Playwright was not used: the operator page needs TOTP enrolment, so the operator markers and "no extend/revoke" were checked by `AccountPagePlayTest` and `PlayFiguresTest`; spec 009 cross-channel precedence only by the existing `AccessQueryTest`.
- **Gates**: Pint clean; Larastan level 8 clean (11 findings fixed with no behaviour change); 52 arch tests; 4,055 tests passed, 8 skipped, Contract/OpenApi included.
- **Performance** (SC-009, opt-in `PLAY_PERF=1`, fake gateway, Xdebug off): p95 verify 71.7 ms, webhook 8.3 ms, account reference 11.1 ms.
- **Fixes**: `PlayLog::reconciled` logged its pass name under the key `pass`, which the secret scrubber redacted (every reconcile line read `"pass":"[REDACTED]"`); renamed to `run`. Two test faults: helper collisions (`tokenEndpoint()`, `restoreItem()` → `playTokenEndpoint()`, `playRestoreItem()`) and a time-dependent dataset in `PushVerificationTest` (clocks are now closures).
- An unauthenticated account-reference call without `X-Client-*` headers answers 400 `CLIENT_VERSION_REQUIRED`, because the client-version gate runs before auth (`GET /me/entitlements` answers the same); with the headers both return 401.

## Deployment

- Run the three migrations.
- Workers must listen to the `billing` queue; schedule the reconcile (hourly) and sweep (daily) commands.
- Set the `BILLING_PLAY_*` block (credentials path or base64 JSON, package name, Pub/Sub audience and service-account email).
- Production stays `disabled` until the owner completes spec §9 Q1: Play Console products and prices, a Google Cloud service account with Play permissions, and a Pub/Sub push subscription with authentication.

## Contract notes for the mobile client

- Every request needs `X-Client-Platform` and `X-Client-Version`; verify and restore need an `Idempotency-Key`.
- Call `GET /billing/google/account-reference` first and pass the value as the obfuscated account id.
- 202 means pending: never grant locally, and recheck later with a new key. Never acknowledge from the client. Re-read `GET /me/entitlements` on app start. Handle 429 with `Retry-After`.
- The per-item restore result field is `outcome`.

## Assumed defaults

Owner away with delegated authority; each the conservative option.

1. Paths follow the brief and C10 (`/billing/google/…`); one purchase token links to exactly one account forever.
2. "Active account" means not purged: an account pending deletion can still be linked (it paid).
3. Pending purchases answer 202 and link to the caller but grant nothing locally.
4. States map onto ADR-0013's existing status machine (no new status); Google's grace applies, the server's `grace_days` never does for Google Play.
5. Play product ids come from config (create-missing seed); lifetime one-time products are supported but not sold; reference prices stay unset until the owner supplies them.
6. License-tester purchases are accepted, marked as test, and excluded from "Paid · Google Play".
7. Notification inbox: the token is sealed and erased once processed, rows live 30 days, and processing always re-reads the purchase from Google so order and repeats don't matter.
8. Account purge leaves Play subscriptions alone and erases the account reference and sealed tokens.
9. No email-verification gate on buying (Google has already taken the payment); no renewal reminder for Play subscribers.
10. Audit uses `entitlement_changed` with `purchase`/`renew`/`recover`; no new audit event or domain event.
11. Gateway default is `disabled` when unset; `BILLING_PLAY_CREDENTIALS` is separate from the FCM key; jobs run on the `billing` queue.
12. The access token and certificates sit in the default cache store; the voided-purchases watermark lives in cache.
13. A verified but unparsable message is answered 204 and recorded as ignored; the webhook rejects a malformed or oversized body with 400 before verifying the token.
14. The 72-hour fallback uses a zero-length grace.
15. The per-item restore result field is `outcome`.

## Remaining risks

- Nothing has run against real Google: the androidpublisher response shapes, the real certificate endpoint, and the Pub/Sub token `iat` window (ADR-0026 flags it may be wider than five minutes; the baseline's five-minute window may need an amendment if production shows rejections).
- A subscription that has expired locally is not re-read by the hourly or daily pass; it recovers through a notification or the next restore, so a lost RECOVERED notification leaves it expired until the person's next restore.
- A push token with an unknown `kid` triggers one fetch of Google's certs endpoint (by design).
- On dev the fake script recomputes expiry on every read, so each re-read looks like a renewal (not a product defect).
- The `billing-integrity` checklist items stay unchecked (reviewer-owned); the review evidence is in plan.md.
- Open owner decisions: Play Console, service account and Pub/Sub setup; whether to cancel auto-renew at account purge (recommended yes, but it changes a published privacy commitment and three mails); whether to offer a lifetime plan; moving a deleted account's purchase to a new account (refused for now); the official Google library (dependency approval, only if ever needed); a back-office editor for Play product ids.
