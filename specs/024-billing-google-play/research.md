# Research: Billing — Google Play

**Feature**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md)

Each item: Decision, Rationale, Alternatives considered. Items marked *assumed default* were chosen under the owner's delegated authority (owner away) as the most conservative option.

---

## R-1 Google Play without the Google SDK

**Decision**: A `PlayBillingGateway` port in `Billing\Domain\GooglePlay`, with adapters in `Billing\Infrastructure\GooglePlay`:

- `GooglePlayGateway` — Google Play Developer API v3 through `Illuminate\Http\Client` (installed; Guzzle underneath), base `https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{packageName}`:
  - `GET purchases/subscriptionsv2/tokens/{token}` → `SubscriptionPurchaseV2`;
  - `POST purchases/subscriptions/{productId}/tokens/{token}:acknowledge` (body `{}`);
  - `GET purchases/products/{productId}/tokens/{token}` → `ProductPurchase`;
  - `POST purchases/products/{productId}/tokens/{token}:acknowledge`;
  - `GET purchases/voidedpurchases?startTime=…&type=1&maxResults=1000&token=…` (type 1 includes subscriptions).
  Tokens are path segments, URL-encoded with `rawurlencode`. Timeouts: connect 5 s, total 10 s.
- `ServiceAccountTokenSource` — OAuth 2.0 JWT bearer grant (RFC 7523), as spec 023 R-1: header `{"alg":"RS256","typ":"JWT","kid":private_key_id}`, claims `iss` = `client_email`, `scope` = `https://www.googleapis.com/auth/androidpublisher`, `aud` = `token_uri`, `iat`, `exp` = `iat + 3600`; `openssl_sign(…, OPENSSL_ALGO_SHA256)` with the key from `openssl_pkey_get_private`; base64url without padding; `POST token_uri` form `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer&assertion=…`. Cached under `billing:play:access-token` for `expires_in − 300` s behind `Cache::lock('billing:play:access-token:mint', 10)`. A `401` forgets the token, mints once, repeats once.
- `FakePlayBillingGateway` (`app/Modules/Billing/Infrastructure/GooglePlay/FakePlayBillingGateway.php`) — in-memory, scripted per token from a JSON file (`BILLING_PLAY_FAKE_SCRIPT`, local only) or by tests; records acknowledgements. In `app/` (unlike spec 023's test-only fake) because local development needs it to exercise the app's purchase flow without Google; refused at boot outside `local`/`testing` (R-2).
- `DisabledPlayBillingGateway` — every call returns `Unavailable(retryAfter: 3600)`. Grants nothing.

The outcome set is closed: `PurchaseFound(PlayPurchaseRecord)`, `PurchaseNotFound` (Google 404/410, or 400 `purchaseTokenDoesNotMatchPackage`/invalid token), `Unavailable(retryAfterSeconds)` (connection errors, 429, 5xx, a second 401), `Acknowledged`, `AlreadyAcknowledged` (400 with reason `invalidPurchaseState`/already acknowledged), and `ConfigurationError` (403 — the service account lacks permission: logged at `error`, treated as unavailable for the caller).

**Rationale**: no dependency is approved (CONTRIBUTING 17, Quality Gate 6). Every call is a JSON GET or POST; the only non-trivial part is the RS256 JWT, which `ext-openssl` (present, as in spec 023) does in a few lines. The port returns domain records and a closed outcome set, so the official client library (`google/apiclient`), if ever approved, replaces one adapter class and its binding (FR-060).

**Shared code with spec 023**: both specs need "sign a service-account JWT and cache an access token". Spec 023 keeps its copy in Notifications; this spec keeps its own in Billing (P-02 rule 3 forbids importing another module's Infrastructure; P-02 rule 5 keeps config-reading key handling out of `Shared`). The RS256 signing helper is ~40 lines; the duplication is recorded in Complexity Tracking. Spec 023's `Notifications\Infrastructure\Push\{Jwt,ServiceAccountKey,ServiceAccountTokenSource}` already exist in the working tree; they are copied into `Billing\Infrastructure\GooglePlay` with the scope changed, not imported.

**Alternatives considered**: `google/apiclient` + `google/auth` (new dependencies, not approved); the deprecated `purchases.subscriptions.get` v1 for reads (v2 is Google's supported read and carries `linkedPurchaseToken`, line items and `externalAccountIdentifiers`; v1 is used only for `acknowledge`, which v2 does not offer); trusting the RTDN payload without re-reading (the payload carries only token and type; Google's guidance is to read the purchase).

## R-2 Gateway selection and boot refusal

**Decision**: `config/billing.php` → `google_play.driver` from `BILLING_PLAY_DRIVER`, one of `google`, `fake`, `disabled`; `disabled` when unset; `.env.example` sets `fake` for local. `BillingServiceProvider::boot` throws when `fake` is selected outside `local`/`testing`, and when `google` lacks any of: a readable service-account key (`BILLING_PLAY_CREDENTIALS`: path to the JSON key, or the JSON base64-encoded; never committed), `google_play.package_name`, `google_play.push.audience`, `google_play.push.service_account`, `google_play.push.subscription`, `google_play.account_reference_key`. `phpunit.xml` sets `fake` and throwaway keys.

**Rationale**: same discipline as Billing's mailer refusal (spec 020) and spec 023 R-2. `disabled` lets production deploy before the owner supplies Google credentials: verify answers `503`, nothing is granted, nothing lies.

## R-3 Linking: the Play Account Reference

**Decision**: table `play_account_references` (`user_id` unique, `reference` char(64) unique, `key_id`, `created_at`). `reference = hex(HMAC-SHA256(key, "play-account:" . user_id))` under `BILLING_PLAY_ACCOUNT_KEY` (a keyring `{kid: base64}` with an active kid, like `BILLING_KEYS`). Created with `INSERT … ON CONFLICT (user_id) DO NOTHING` then re-read, on first need. Google returns it as `externalAccountIdentifiers.obfuscatedExternalAccountId` (v2) / `obfuscatedExternalAccountId` (products). Resolution: `reference → user_id` by the unique index, then Identity's existing `Accounts::departedAmong([$userId])` (an account that is active **or pending deletion** is not departed and can hold a purchase — it paid; a purged or unknown one is departed). `Accounts::isActive` is deliberately not used: it excludes pending deletion, which is right for operator grants but would strand a payment.

Linking rules (FR-006–FR-010), in `LinkDecision` (pure domain):

| Purchase state | Reference on purchase | Caller | Decision |
|---|---|---|---|
| linked to caller | any | — | `AlreadyYours` |
| linked to another account (incl. purged) | any | — | `LinkedElsewhere` (409) |
| unlinked | names caller | — | `Link` |
| unlinked | names another active account | — | `LinkedElsewhere` |
| unlinked | resolves to no active account | — | `LinkedElsewhere` (never linked) |
| unlinked | none | — | `Link` (first verifier wins) |
| `linkedPurchaseToken` set | — | replaced purchase's account ≠ caller | `LinkedElsewhere` |

**Rationale**: Google's documented anti-fraud mechanism is the obfuscated account id; a keyed hash reveals nothing to Google and cannot be forged for another account without the key. Storing it gives the reverse lookup RTDN linking needs (R-7). A random value would work equally; the keyed hash was the brief's suggestion and is reproducible if the table is ever lost.

**Alternatives considered**: no reference (RTDN could never link a purchase whose verify call was lost; another account could claim a leaked token first); the raw user id (discloses our ids to Google; Play forbids PII-like values).

## R-4 Play Purchase record and the subscription it backs

**Decision**: table `play_purchases` (see data-model.md). `token_lookup` = hex HMAC-SHA256 of the token under the Billing lookup key (unique); `token_sealed` = `BillingSealer` (existing XChaCha20-Poly1305 keyring, associated data `play_purchase:{id}:token`). The subscription's `provider_ref` = the Play Purchase id (uuid, fits the existing `varchar(255)`, unique with `provider`). `source_meta` gains `{"test": bool, "base_plan_id": "…"}` (scalars, as spec 009 allows). Linking is `UPDATE play_purchases SET user_id = :caller WHERE id = :id AND user_id IS NULL` inside a transaction holding `SELECT … FOR UPDATE` on the row; the unique `token_lookup` makes two first inserts collide (`ON CONFLICT DO NOTHING` then re-read).

**Rationale**: the existing `subscriptions` table needs no new column (ADR-0013 rule 3: one subscription shape for every channel); the Play-specific facts live beside it. Sealing reuses Billing's own sealer (same module, no copy). The token never becomes a key anywhere else.

**Alternatives considered**: token as `provider_ref` (puts a secret in an index and in every operator query); a hash as `provider_ref` without a purchase table (the token is needed again for reconciliation and acknowledgement).

## R-5 State mapping (ADR-0013 rule 4)

**Decision**: pure `PlayStateMap::target(PlayPurchaseRecord, ?SubscriptionRecord, now): PlayTransition` returning the target local status, dates, `auto_renew` and the audit action. Google v2 `subscriptionState` → local:

| Google | Local status | Dates | auto_renew | Audit action / event |
|---|---|---|---|---|
| `SUBSCRIPTION_STATE_ACTIVE` | `active` | end = line item `expiryTime`; start = previous end or `startTime` | line item `autoRenewingPlan.autoRenewEnabled` | `purchase` (new) / `renew` (end moved) / `recover` (from `grace`/`expired`); `SubscriptionActivated` on new or recover |
| `SUBSCRIPTION_STATE_IN_GRACE_PERIOD` | `grace` | `grace_until` = line item `expiryTime` (Google extends it over grace) | true | — ; `SubscriptionLapsed(grace)` on entry |
| `SUBSCRIPTION_STATE_ON_HOLD`, `…_PAUSED` | `expired` via zero-length grace | `grace_until` = now | true | `expire`; `SubscriptionLapsed(expired)` |
| `SUBSCRIPTION_STATE_CANCELED` | `active` until `expiryTime`, then `expired` | as active | false | — / `expire` when past |
| `SUBSCRIPTION_STATE_EXPIRED` | `expired` (via zero-length grace when `active`) | unchanged | false | `expire`; `SubscriptionLapsed(expired)` |
| voided (RTDN or sweep), or v2 shows a revocation | `revoked` | unchanged | false | `revoke` (`refunded` / `revoked_by_provider`); `SubscriptionLapsed(revoked)` |
| `SUBSCRIPTION_STATE_PENDING`, `…_PENDING_PURCHASE_CANCELED` | no subscription | — | — | — |

One-time products (`ProductPurchase.purchaseState`): `0` purchased → `active`, no end; `1` canceled → none (or `revoked` if previously granted); `2` pending → none. `purchaseType = 0` → test.

Rules: never move `current_period_end` backwards (keep the max) except on revocation; a transition the machine does not draw is never performed — `active → expired` is two `transition()` calls in one transaction (`active → grace` with `grace_until = now`, `grace → expired`), with **one** `SubscriptionLapsed(expired)`; `revoked` is terminal (a later "active" for the same token is logged as `billing_play_state_ignored` and changes nothing). Test detection: v2 `testPurchase` present.

**Rationale**: P-09/ADR-0013 permit exactly these arcs; on-hold and paused are "no access" per Google's guidance, which our machine expresses as `expired` (recoverable). The zero-length grace hop keeps the drawn machine and produces a single event so Sharing does not act twice.

**Alternatives considered**: a new `on_hold` status (changes ADR-0013's machine and every consumer of `status` — needs a superseding record for no behavioural gain); treating hold as `grace` (grants during hold, contrary to Google's policy that access is lost on hold).

## R-6 Verify and restore flows

**Decision**: `Billing\Application\GooglePlay\VerifyPlayPurchase::verify(userId, token, productId): VerifyOutcome` —

1. Map `productId` → plan via `PlanCatalogue::planForPlayProduct` (active or retired); none → `VALIDATION_FAILED`.
2. Lookup or insert the `play_purchases` row (unlinked) by token lookup.
3. `gateway->read(kind, productId, token)`: `PurchaseNotFound` → `PURCHASE_NOT_VERIFIED`; `Unavailable` → `503`; record's product ≠ posted → `PURCHASE_NOT_VERIFIED`; package mismatch is impossible (package is in the URL).
4. Transaction: lock row; `LinkDecision`; `LinkedElsewhere` → 409; pending → record `play_state = pending`, link to caller **only if** the reference names the caller or there is none (so a later notification can grant), answer 202; else `ApplyPlayState` (R-5) creates or updates the subscription, writes audit, queues events after commit.
5. After commit: acknowledge if `acknowledgementState` is not acknowledged; on failure dispatch `AcknowledgePlayPurchase` (queue `billing`, tries 5, backoff 60/300/900/3600 s, `ShouldBeUnique` by purchase id); answer with `acknowledged` as it now stands.
6. Answer via `EntitlementService` and `AccessQuery` (existing) for `entitlements` and `subscription`.

`RestorePlayPurchases::restore(userId, items)` runs step 1–5 per item (outcomes mapped, never throwing per item), then re-reads every purchase linked to the caller not covered by the items (`ReconcilePlayPurchase::sync`), and answers 503 only if every gateway call returned `Unavailable`.

Controllers sit behind `auth:api`, `billing.idempotent` (spec 020's middleware, user scope) and a `PlayBudget::spend` (20/h verify, 10/h restore) called after FormRequest validation, as `RedeemBudget` does.

**Rationale**: acknowledging after commit means a crash between commit and acknowledgement leaves a granted, unacknowledged purchase that the sweep repairs; acknowledging before commit could acknowledge a purchase whose grant was rolled back (the person pays, gets nothing, and Google does not refund).

## R-7 RTDN webhook and inbox

**Decision**: `POST /webhooks/google-play` (route in Billing's `routes.php`, outside `auth:api`, registered with `->withoutMiddleware([RequireClientVersion::class])` — Google sends no `X-Client-*` headers — and, if `NegotiateJson` refuses Pub/Sub's `Accept`, without it too; the rest of the `api` group (request id, HTTPS refusal) still applies). Steps, synchronous:

1. Body ≤ 65,536 bytes and JSON with `message.data`, `message.messageId`, `subscription`, else `400 MALFORMED_JSON`.
2. `PushTokenVerifier::verify(bearer)` (R-8) → `401 PROVIDER_SIGNATURE_INVALID` on any failure; `CertificatesUnavailable` → `503`.
3. Decode `message.data` (base64) → `DeveloperNotification`; malformed → record `ignored` (`malformed`) and 204 (Pub/Sub would retry a 4xx/5xx forever on a message that can never parse; it is verified as Google's, so acknowledging it is safe).
4. `subscription` ≠ configured or `packageName` ≠ configured → record `ignored` and 204. `testNotification` → `ignored` (`test`), 204.
5. `INSERT INTO play_notifications … ON CONFLICT (message_id) DO NOTHING`; if not inserted → 204 (repeat). The 24-hour replay window of the baseline is met by keeping rows 30 days.
6. Dispatch `ProcessPlayNotification(notificationId)` after commit (queue `billing`, tries 5, backoff 60/300/900/3600, `ShouldBeUnique` by id); 204.

`ProcessPlayNotification`: lock the inbox row; return if terminal; open token; voided → find purchase by lookup, revoke its subscription (if linked and not revoked), mark `processed`; otherwise `ReconcilePlayPurchase::sync(lookup, token, productId, kind)` which reads Google and applies R-5, linking an unlinked purchase when `LinkDecision` allows (reference resolves to an active account) and acknowledging after commit. The audit-once rule (FR-037) is enforced by `ApplyPlayState` writing an entry only when the subscription's status or end actually changed, so a repeat writes none. Mark `processed`, erase `token_sealed`. On final failure: `failed`, erase token, log `billing_play_notification_failed`.

**Rationale**: the baseline's five checks happen before any write; the inbox makes Pub/Sub's at-least-once delivery harmless; re-reading Google makes order irrelevant (a late `PURCHASED` after `CANCELED` re-reads the canceled state).

## R-8 Verifying the Pub/Sub OIDC token without a library

**Decision**: `PushTokenVerifier` (Infrastructure) + `OidcClaims` checks (pure domain, unit-tested):

- Parse `header.payload.signature` (base64url, strict); header `alg` must be `RS256` (anything else, including `none`, rejected), `kid` required.
- Public keys from `https://www.googleapis.com/oauth2/v1/certs` (a JSON map `kid → PEM X.509 certificate`, usable directly by `openssl_pkey_get_public` — no JWK→PEM conversion needed). Cached in the default cache under `billing:play:oidc-certs` for `max-age` from `Cache-Control` (clamped 5 min – 24 h). An unknown `kid` forces one refresh (rate-limited to once per minute via a cache lock); still unknown → 401. Fetch failure with no cached set → `CertificatesUnavailable` (503).
- `openssl_verify(signingInput, signature, publicKey, OPENSSL_ALGO_SHA256) === 1`.
- Claims: `iss ∈ {https://accounts.google.com, accounts.google.com}`; `aud === config(push.audience)` (exact string; the push endpoint URL as configured in the Pub/Sub subscription); `email === config(push.service_account)` and `email_verified === true`; `|now − iat| ≤ 300 s`; `exp > now` (60 s leeway for clock skew on `exp` only).

**Risk (recorded, not resolved here)**: Pub/Sub may reuse a push token for several deliveries within its one-hour lifetime. The baseline's five-minute `iat` window then refuses a genuine push carrying an older token; Pub/Sub retries with backoff and later pushes carry fresh tokens, and the hourly reconciliation re-reads Google regardless, so the cost is delay, never a wrong grant. The window is kept as ratified (conservative); `billing_play_push_rejected` with reason `time` is counted on the overview so the owner can see whether it bites (spec §9 Q7).

**Rationale**: every check the security baseline requires, plus Google's documented ones (email). PEM certificates avoid ASN.1 work. Taking keys only from Google's endpoint over TLS (never `jku`/`x5u` from the token) closes key-injection attacks.

**Alternatives considered**: `firebase/php-jwt` (dependency); JWK endpoint `oauth2/v3/certs` (needs RSA modulus/exponent → DER encoding by hand; more code, same keys); a shared secret in the push URL query (Google supports it, but it is weaker than the signed token and leaks in logs).

## R-9 Catalogue mapping

**Decision**: `config/billing.php` → `google_play.products` = `['plus_monthly' => ['product_id' => env('BILLING_PLAY_PRODUCT_PLUS_MONTHLY', 'plus_monthly'), 'amount_minor' => env int or null, 'currency' => env('BILLING_PLAY_CURRENCY', 'EGP')], 'plus_yearly' => …]`, keyed by plan code (seeders may use plan codes, ADR-0013 rule 6). `BillingSeeder::run` gains: for each configured plan code, if the stored plan has no `google_play` price and the config gives `product_id`, integer `amount_minor` and a known currency, append `{channel: google_play, product_id, amount_minor, currency}`; validate that no product id appears on two plans (throws). `PlanCatalogue::planForPlayProduct(productId): ?PlanRecord` searches active and retired plans' `prices` (`jsonb @> '[{"channel":"google_play","product_id":"…"}]'`, GIN not needed at this size). Base plan id is taken from Google's line item and stored in `source_meta`, never used for mapping (one base plan per product, A-3).

**Rationale**: spec 021 made Play prices read-only; the seeder is already create-missing and production-safe; the app reads product ids from `GET /plans` unchanged.

## R-10 Reconciliation passes

**Decision**: `billing:play-reconcile` hourly (`withoutOverlapping`): (a) granting `google_play` subscriptions with `coalesce(grace_until, current_period_end) <= now`; (b) linked purchases with `acknowledged_at IS NULL` and `play_state` paid; (c) pending purchases younger than 3 days; each through `ReconcilePlayPurchase::sync` with a per-purchase lock; `Unavailable` sets `unreachable_since` (if null) — at 24 h logs `billing_play_unreachable`, at 72 h past the end lapses by dates (zero-length grace, R-5) and logs; success clears it. `billing:play-sweep` daily: (d) granting `google_play` with `synced_at < now − 7 days`; (e) `voidedpurchases.list` from `max(watermark, now − 30 days)`, paging `nextPageToken`, revoking linked purchases by token lookup; watermark stored in cache `billing:play:voided-watermark` (a lost watermark re-reads 30 days, harmless); (f) delete unlinked purchases with `purchased_at < now − 10 days`, inbox rows older than 30 days. `LapseSubscriptions` (spec 009) gains `->where('provider', '!=', 'google_play')` in `dueForGrace`/`dueForExpiry`.

**Rationale**: Google documents RTDN as the primary signal but not guaranteed; the passes bound staleness (SC-004) and catch refunds made from the Play Console or by Google's fraud team.

## R-11 Accounts that leave

**Decision**: `SubscriptionService::endForAccountPurge` (spec 022) is extended (same step, same participant `EndSubscriptions`): delete the account's `play_account_references` row; set `token_sealed = NULL` on its `play_purchases` rows (keeping `user_id`, lookup and ids for 400 days like the subscription rows, deleted with them by `PurgeEndedSubscriptions`). Google Play subscriptions remain untouched (spec 022 FR-047). A later sync of such a purchase: Identity says the account is purged → no change, log at debug.

**Rationale**: keeps spec 022's behaviour and commitments; the erased token means the server can no longer act on a purged person's purchase even by mistake.

## R-12 Operator visibility

**Decision**: Billing's `OperatorSubscriptions`/`AccessQuery` view for the account page adds `auto_renew`, `play_acknowledged` and `play_test` for `google_play` rows (from a join to `play_purchases`). `BillingFigures` adds `paidGooglePlay` excluding `source_meta->>'test' = 'true'`, `testGooglePlay`, and a `PlayFigures` block (last 24 h: notifications by state, purchases verified = `play_purchases.linked_at` in window, unacknowledged > 24 h; pushes rejected by reason from hourly cache counters `billing:play:rejected:{reason}:{YmdH}` incremented by the webhook — a refusal writes no database row, FR-035). Admin's existing components render them (lang en/ar). No token, order id, reference or amount.

## R-13 Logging

**Decision**: a `PlayLog` helper with the fixed events of FR-062; the central scrubber (`app/Http/Api/Logging/RedactSecrets.php`, whose key pattern already matches `token` and `authorization`) gains `obfuscated|account_reference|order_?id|assertion|private_key`. HTTP client calls to Google are never logged with URLs (tokens are in the path): the adapter catches `RequestException` and logs only the status and Google's `error.status`/`reason`.

## R-14 Testing seam

- Unit: `PlayStateMapTest` (every state × prior status, never-backwards end, zero-length hop, revoked terminal), `LinkDecisionTest`, `OidcClaimsTest` (iss, aud, email, iat window, exp, alg), `ServiceAccountJwtTest` (sign with `openssl_pkey_new` key; verify with public key), `DeveloperNotificationTest` (parse every kind; malformed).
- Feature (PostgreSQL): verify (each outcome), linking and concurrency, acknowledge (success, failure → job, sweep), restore, webhook (signed with a test key pair served by `Http::fake` for the certs URL; every rejection; repeat; foreign subscription/package; test), processing (every notification type, out of order, repeat), reconcile and sweep (renewal, expiry, unreachable 72 h, voided), gateway adapter (`Http::fake` for token, read, acknowledge, 401 refresh, 404, 5xx), binding and boot refusal, seeder mapping, purge, token never leaks (log spy, job payload, audit, response), admin figures.
- Contract: `GooglePlayPurchasesTest`, `GooglePlayRestoreTest`, `GooglePlayWebhookTest`, `GooglePlayAccountReferenceTest` against `google-play.yaml` with Spectator, every documented status.
- Arch: existing gates cover the new code (P-02, P-09 plan-code gate).

## R-15 Things the plan deliberately does not do

No Google SDK (FR-060); no server-side cancel or refund (section 9 Q2; "refunds automation" is out of MVP scope, P-17); no renewal reminder for Play (FR-027); no back-office editor for product ids (Q6); no re-link from a purged account (Q4); no new subscription status; no new audit event; no new domain event.
