---

description: "Task list for Billing — Google Play (B10)"
---

# Tasks: Billing — Google Play

**Input**: Design documents from `/specs/024-billing-google-play/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/google-play-api.md](./contracts/google-play-api.md), [quickstart.md](./quickstart.md), [checklists/billing-integrity.md](./checklists/billing-integrity.md), the frozen contract `docs/openapi/paths/google-play.yaml` (written and indexed in `docs/openapi/openapi.yaml` with the `pubsubPush` scheme; `info.version` v1.15).

**Tests**: included. The spec's Invariants table (§4) names every test file (constitution P-16 rule 3). Write each story's tests first and watch them fail.

**Organization**: grouped by user story, in priority order:

- US1: buy Plus in the app and get it at once (P1)
- US2: one purchase, one account (P1)
- US3: Google keeps the subscription in step (P1)
- US4: only Google can push to the webhook (P1)
- US5: restore my purchases (P2)
- US6: nothing is lost when a notification is (P2)
- US7: operators see Play at a glance, and cannot override it (P3)

**Conventions for every task** (as in specs 016–023):

- Run through the container: `dev exec -- vendor/bin/pest <path> --compact`, `dev <artisan command>`. Host PHP has no `pdo_pgsql`.
- P-02: new code in `app/Modules/Billing/{Domain,Application,Infrastructure,Http}` under a `GooglePlay` sub-namespace; Billing reaches Identity only through `Identity\Application\Accounts` (`departedAmong`) and `AuditLog`. Domain imports only `Shared`.
- **No new Composer or npm dependency** (FR-060, CONTRIBUTING 17). `composer.json`, `composer.lock`, `package.json`, `package-lock.json` unchanged at the end.
- **Never** put a purchase token, an account reference (except in its own `GET`), an order id, the service-account key, the OAuth assertion, an access token or the push bearer token in a response, log line (any level), exception message, URL that is logged, job payload, event, audit entry or screen (FR-055). Jobs carry the Play Purchase id or the Play Notification id only. Logs: the fixed events of FR-062.
- Refusals extend `Shared\Domain\Errors\Refusal`: `PURCHASE_ALREADY_LINKED`, `PURCHASE_NOT_VERIFIED` (new, T001), `VALIDATION_FAILED`, `SERVICE_UNAVAILABLE`, `RATE_LIMITED`, `PROVIDER_SIGNATURE_INVALID`, `MALFORMED_JSON`.
- Events: only the existing `SubscriptionActivated` / `SubscriptionLapsed`, dispatched after commit (P-11). Audit: `entitlement_changed`, actor `system`.
- Subscription status changes go only through `SubscriptionRepository::transition` along ADR-0013's arcs; `active → expired` is two transitions in one transaction (research R-5).
- Instants with microseconds in responses; empty `meta` is `(object) []`.
- Contract tests use `assertValidRequest()` and `assertValidResponse($status)` for every documented status.
- Run `dev exec -- vendor/bin/pint --dirty --format agent` after PHP edits.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on unfinished tasks)
- **[Story]**: US1–US7 from spec.md

---

## Phase 1: Setup (codes, docs, decision record, config)

- [X] T001 Error codes, all three places in one change (the parity gate `tests/Contract/OpenApi/ErrorCodeParityTest.php` requires it): `docs/openapi/error-catalog.yaml` — `PURCHASE_ALREADY_LINKED` (status 409, level [response], title 'Purchase linked to another account', meaning 'This Google Play purchase belongs to another account. Sign in with the account that bought it; the other account is never named.', retryable never, since v1.15) and `PURCHASE_NOT_VERIFIED` (status 422, title 'Purchase not verified', meaning 'Google Play does not confirm this purchase for this app and product. Nothing was granted; do not retry this purchase.', retryable never, since v1.15) in `x-error-catalog` and the `ErrorCode` enum list; cases in `app/Modules/Shared/Domain/Errors/ErrorCode.php` (status, title, retry class); detail sentences in `lang/en/problems.php` and `lang/ar/problems.php`. Run `dev exec -- vendor/bin/pest tests/Contract/OpenApi --compact`.
- [X] T002 [P] Write `docs/adr/0026-google-play-purchases-through-an-in-house-gateway.md` (template `docs/adr/template.md`, Status Accepted, Principles P-09, P-10, P-15; extends ADR-0013 and ADR-0010): rules — Google Play is the source of truth for `google_play`; every Google call goes through `PlayBillingGateway`; the production adapter uses the framework HTTP client and an RS256 service-account JWT (`ext-openssl`), the official library is a drop-in adapter swap needing approval; Pub/Sub pushes verified in-house (baseline five checks + email); a Play Purchase record beside the subscription, `provider_ref` = its id, token sealed + keyed lookup, linked once forever, obfuscated account id = Play Account Reference; Google's states onto ADR-0013 rule 4 arcs (hold/pause → `expired` via a zero-length grace hop with one `SubscriptionLapsed`; Google's grace, never `billing.grace_days`; `revoked` terminal); server acknowledges, after commit; the date-driven lapse skips `google_play`, reconciliation asks Google, 72-hour fallback. Also records the reading of P-10 rule 2 / ADR-0013 rule 11: `POST /billing/google/purchases` is the single-item form of restore-purchases and `GET /billing/google/account-reference` is a read that starts no payment, so the in-app billing writes remain restore and enter-code. Alternatives: `google/apiclient` (approval), an `on_hold` status (supersedes ADR-0013), trusting RTDN payloads, token as `provider_ref`. Add row to `docs/adr/README.md` index and the Billing reading thread.
- [X] T003 [P] Constitution PATCH → **v1.3.15** in `.specify/memory/constitution.md` (spec 023 takes v1.3.14; apply this entry on top of it — if spec 023's amendment has not landed yet, land it first or coordinate, and never reuse 1.3.14): P-09 and P-10 `**ADRs**` lines list ADR-0026 (extends ADR-0013 and ADR-0010); Sync Impact Report entry ("PATCH: P-09 and P-10 list ADR-0026, introduced by spec 024; no rule changes"); both footer lines Last Amended 2026-10-06, version 1.3.15.
- [X] T004 [P] Glossary `docs/glossary.md` (before code, CONTRIBUTING 13): new entries **Play Purchase** (Billing, persisted, Sync: no; linked once forever; sealed token + lookup; backs a `google_play` Subscription via `provider_ref`; retention), **Play Account Reference** (Billing, persisted, Sync: no — `GET /billing/google/account-reference`; keyed hash; the obfuscated account id; deleted at purge), **Play Notification** (Billing, persisted, Sync: no — operational inbox; states `received`→`processed`|`ignored`|`failed`; 30 days), **Purchase Token** (Google's identifier of one purchase; sealed, never logged, never returned); Subscription entry: `google_play` rows follow Google (source of truth), Google's grace, the zero-length grace hop, no renewal reminder; Plan entry: a `google_play` price maps a Play product id; entity index rows and counts.
- [X] T005 [P] Security baseline `docs/security-baseline.md`: *Messages from the payment provider* gains a "Google Play" subsection (issuer values, audience = configured push endpoint URL, `email`/`email_verified`, certificates only from `https://www.googleapis.com/oauth2/v1/certs`, unknown kid → one refresh, inbox by message id kept 30 days, verified-but-malformed acknowledged as ignored); protected attributes rows (purchase token sealed + keyed lookup, readable by nobody; Play Account Reference keyed hash, its own account); retention rows (Play Purchase with its subscription, unlinked 10 days, token erased at purge; Play Notification 30 days, token erased when terminal); What must never be logged gains account references, order ids, Google service-account keys, OAuth assertions and access tokens; route budgets: Google Play purchase verify 20 per hour per person, restore 10 per hour per person (429); appendix checklist H-line for Play.
- [X] T006 [P] Privacy commitments `docs/privacy-commitments.md`, **English and Arabic**: "What we collect" — "If you subscribe through Google Play, we read your subscription's state from Google (whether it is active, renewing, on hold or refunded) — never your card or your Google account details"; mapping rows (spec 024 FR numbers). The "Deleting your account does not cancel a Google Play subscription" sentences stay unchanged (spec Q2).
- [X] T007 [P] `docs/api-conventions.md`: Idempotency — "Google Play purchase acknowledgement" names `POST /billing/google/purchases` and `POST /billing/google/restore`; a note that `/webhooks/*` operations are called by providers, are outside the client-version gate and use the provider's own authentication; `specs/briefs/README.md` row 12 note "Specified as spec 024; no Google SDK (in-house androidpublisher v3 adapter and OIDC verification)".
- [X] T008 [P] `config/billing.php` gains the `google_play` block with every key of data-model.md "Configuration" (driver default `disabled`; credentials; package_name; account_reference_keys/active_key; lookup_key; push.audience/service_account/subscription/certs_url; products.plus_monthly/plus_yearly with product ids `plus_monthly`/`plus_yearly`, amounts from env (null when unset), currency `EGP`; fake_script; throttle 20/10; unreachable_lapse_hours 72, ack_alert_hours 48, unlinked_keep_days 10, inbox_keep_days 30; token_uri `https://oauth2.googleapis.com/token`; timeouts connect 5 total 10) and its header comment; `.env.example` (`BILLING_PLAY_DRIVER=fake`, placeholder keys, commented Google vars); `phpunit.xml` throwaway keys, `BILLING_PLAY_DRIVER=fake`, `BILLING_PLAY_PACKAGE=com.example.app`, push audience/service account/subscription test values.

---

## Phase 2: Foundational (schema, domain, gateway — blocks every story)

- [X] T009 Migrations in `app/Modules/Billing/Infrastructure/Database/Migrations/`: `2026_10_13_000001_create_play_account_references_table.php` (`user_id` uuid PK, `reference` char(64) UNIQUE, `key_id` varchar(32), `created_at`), `2026_10_13_000002_create_play_purchases_table.php` (columns exactly as data-model.md; `token_lookup` char(64) UNIQUE; `kind` CHECK in ('subscription','one_time'); `play_state` CHECK in ('pending','active','grace','canceled','on_hold','paused','expired','revoked','pending_canceled'); `subscription_id` FK `subscriptions(id)`; CHECK `(user_id IS NULL) = (linked_at IS NULL)`; CHECK `subscription_id IS NULL OR user_id IS NOT NULL`; indexes `(user_id)`, `(subscription_id)`, partial `(acknowledged_at) WHERE acknowledged_at IS NULL AND user_id IS NOT NULL`, partial `(purchased_at) WHERE user_id IS NULL`, `(replaces_lookup)`, `(ended_by_account_purge_at)`), `2026_10_13_000003_create_play_notifications_table.php` (`message_id` varchar(128) UNIQUE; `kind` CHECK in ('subscription','one_time','voided','test','malformed'); `state` CHECK in ('received','processed','ignored','failed'); `ignored_reason` CHECK in ('test','foreign_subscription','foreign_package','malformed','purged_account') and `(state = 'ignored') = (ignored_reason IS NOT NULL)`; indexes `(received_at)`, `(state, received_at)`, `(token_lookup)`). Reversible `down()`. Models `PlayPurchase`, `PlayNotification`, `PlayAccountReference` in `Infrastructure/Models` (UUIDv7, casts).
- [X] T010 [P] Domain in `app/Modules/Billing/Domain/GooglePlay/`: `PlayBillingGateway` (port: `readSubscription(string $token): GatewayOutcome`, `readProduct(string $productId, string $token): GatewayOutcome`, `acknowledge(PlayProductKind $kind, string $productId, string $token): GatewayOutcome`, `voidedSince(DateTimeImmutable $since, ?string $pageToken): VoidedPage`), `PlayProductKind`, `PlayState` (enum of the nine stored states), `PlayPurchaseRecord` (product id, kind, state, expiry, grace end, start, auto-renew, acknowledged, obfuscated ref, linked token, test, base plan id, purchase time), `GatewayOutcome` (PurchaseFound | PurchaseNotFound | Unavailable(retryAfter) | Acknowledged | AlreadyAcknowledged), `VoidedPage`, `Refusals/{PurchaseAlreadyLinked, PurchaseNotVerified, PlayUnavailable}`; ports `Domain/Ports/{PlayPurchaseRepository, PlayNotificationRepository, PlayAccountReferences, TokenLookup}`.
- [X] T011 [P] `app/Modules/Billing/Domain/GooglePlay/PlayStateMap.php` + `PlayTransition.php` (pure; research R-5 table, never-backwards end, zero-length hop flag, revoked terminal, audit action, event to publish) and `tests/Unit/Billing/GooglePlay/PlayStateMapTest.php` (every Google state × prior local status none/active/grace/expired/revoked; one-time purchased/canceled/pending; end never moves backwards; hold from active yields the hop and exactly one lapse; revoked ignores later active).
- [X] T012 [P] `app/Modules/Billing/Domain/GooglePlay/LinkDecision.php` (pure; research R-3 table) and `tests/Unit/Billing/GooglePlay/LinkDecisionTest.php` (every row).
- [X] T013 [P] `app/Modules/Billing/Domain/GooglePlay/DeveloperNotification.php` (parse decoded RTDN JSON: version, packageName, eventTimeMillis, one of subscription/oneTimeProduct/voidedPurchase/test notification; malformed → `null`) and `tests/Unit/Billing/GooglePlay/DeveloperNotificationTest.php`.
- [X] T014 [P] `app/Modules/Billing/Domain/GooglePlay/OidcClaims.php` (pure claim checks: iss ∈ {`https://accounts.google.com`, `accounts.google.com`}, aud exact, email exact + `email_verified` true, |now − iat| ≤ 300 s, exp > now − 60 s; returns a reason code) and `tests/Unit/Billing/GooglePlay/OidcTokenVerifierTest.php` (each failing claim, alg `none`/`HS256` rejected at the verifier level via T016).
- [X] T015 Infrastructure gateway in `app/Modules/Billing/Infrastructure/GooglePlay/`: copy spec 023's `Jwt`, `ServiceAccountKey`, `ServiceAccountTokenSource` (scope `https://www.googleapis.com/auth/androidpublisher`, cache key `billing:play:access-token`, lock, 5-minute early refresh) — copied, not imported (P-02); `GooglePlayGateway` (research R-1 endpoints, `rawurlencode` tokens, timeouts 5/10 s, one 401 refresh, outcome mapping incl. 403 → error log + Unavailable; never logs URLs); `FakePlayBillingGateway` (scripted per token from tests or `fake_script` JSON; records acknowledgements; `scriptSubscription()`, `scriptProduct()`, `scriptVoided()`, `failNext()` helpers); `DisabledPlayBillingGateway`; `PlayGatewayFactory`; `HmacTokenLookup`. Tests: `tests/Unit/Billing/GooglePlay/ServiceAccountJwtTest.php` (sign with a key from `openssl_pkey_new`, verify with its public key, claims), `tests/Feature/Billing/GooglePlay/GooglePlayGatewayTest.php` (`Http::fake`: token mint cached once, read v2 subscription → record, product read, acknowledge, already acknowledged, 404 → not found, 5xx/429/connection → unavailable with Retry-After, 401 → one refresh).
- [X] T016 [P] `app/Modules/Billing/Infrastructure/GooglePlay/{GoogleCertificates,PushTokenVerifier}.php` (research R-8: PEM certs from `push.certs_url` cached by `Cache-Control` max-age clamped 5 min – 24 h; unknown kid → one refresh per minute; `openssl_verify` RS256; header alg must be RS256; then `OidcClaims`; `CertificatesUnavailable` when nothing cached and fetch fails) and `tests/Support/PlayPushSigner.php` (generates a key pair and self-signed cert per test run, serves `{kid: pem}` through `Http::fake`, signs tokens with overridable claims).
- [X] T017 Binding and boot refusal in `app/Modules/Billing/Application/BillingServiceProvider.php` (or the persistence provider where Billing binds ports): bind `PlayBillingGateway` from `google_play.driver`; throw when `fake` outside `local`/`testing`, or `google` without credentials, package name, push audience/service account/subscription, account-reference key or lookup key; bind repositories (`Eloquent{PlayPurchaseRepository,PlayNotificationRepository,PlayAccountReferences}`). Test `tests/Feature/Billing/GooglePlay/GatewayBindingTest.php`.
- [X] T018 [P] Catalogue mapping: `BillingSeeder` (create-missing `google_play` price per configured plan code when the plan has none and product id, integer amount and known currency are all set; never overwrite; product id unique across plans or throw) and `PlanCatalogue::planForPlayProduct(string $productId): ?PlanRecord` (active and retired). Test `tests/Feature/Billing/GooglePlay/PlayCatalogueTest.php` (seeded once, re-run no change, operator-edited plan untouched, unset amount seeds nothing, duplicate product id throws, retired plan still maps).
- [X] T019 `app/Modules/Billing/Application/GooglePlay/ApplyPlayState.php` (inside the caller's transaction: create the subscription for a first grant — `provider` `google_play`, `provider_ref` = purchase id, plan id, dates, `auto_renew`, `source_meta` {test, base_plan_id} — or apply `PlayTransition` via `SubscriptionRepository::transition`/`updateDates`; write `entitlement_changed` only when status or end changed; dispatch `SubscriptionActivated`/`SubscriptionLapsed` after commit) and extend `app/Modules/Identity/Application/Audit/Details/EntitlementChangedDetail.php` action union with `purchase`, `renew`, `recover`. Test `tests/Feature/Billing/GooglePlay/ApplyPlayStateTest.php` (each transition writes the right audit and event once; repeat writes none; hop yields one lapse).
- [X] T020 `app/Modules/Billing/Application/GooglePlay/ReconcilePlayPurchase.php` (`sync(purchaseId)`: lock row, open token, read Google, product/package checks, `LinkDecision` for unlinked rows via `AccountReferences::userFor` and `Accounts::departedAmong`, `ApplyPlayState`, set `synced_at` / clear or set `unreachable_since`; after commit acknowledge when needed, else dispatch `AcknowledgePlayPurchase`) and `Application/GooglePlay/Jobs/AcknowledgePlayPurchase.php` (queue `billing`, tries 5, backoff [60, 300, 900, 3600], unique by purchase id; purchase id only in payload); `Application/GooglePlay/AccountReferences.php` (`for(userId)` create-on-need, `userFor(reference)`).

---

## Phase 3: User Story 1 — Buy Plus in the app and get it at once (P1) 🎯 MVP

**Goal**: a verified, linked, acknowledged purchase grants Plus in one request.

**Independent Test**: fake scripted "ACTIVE plus_monthly, reference = caller"; post; `200`, entitlements, subscription, one acknowledgement, one audit entry, `SubscriptionActivated`.

### Tests

- [X] T021 [P] [US1] `tests/Contract/Billing/GooglePlayPurchasesTest.php` and `tests/Contract/Billing/GooglePlayAccountReferenceTest.php`: every documented status of `verifyPlayPurchase` (200 active, 200 ackPending, 202 pending, 400 key missing/invalid, 401, 409 linked/reused/in-progress, 422 not verified/validation, 426, 429, 503) and `getPlayAccountReference` (200, 401, 429) against `google-play.yaml`.
- [X] T022 [P] [US1] `tests/Feature/Billing/GooglePlay/VerifyPurchaseTest.php` (spec US1 1–6: success; replay with `Idempotency-Replayed` and no Google call; same token new key → 200 unchanged; pending → 202 then later grant; not found / product mismatch / canceled-before-paid → 422 nothing stored; unavailable → 503; old expired token → 200 linked, no subscription (FR-011a); unmapped product → 422 validation; an unverified account can verify (FR-004); a license-tester purchase is granted with `test: true` and `source_meta.test` (FR-050)) and `tests/Feature/Billing/GooglePlay/AcknowledgeTest.php` (ack after commit; ack failure → 200 `acknowledged:false` + job; already acknowledged recorded; unlinked never acknowledged).

### Implementation

- [X] T023 [US1] `app/Modules/Billing/Application/GooglePlay/VerifyPlayPurchase.php` (research R-6 steps 1–6; logs `billing_play_verified` with purchase id and outcome only (FR-062); returns outcome with purchase state view, `EntitlementService` summary and `AccessQuery` subscription).
- [X] T024 [US1] HTTP: `Http/Requests/VerifyPlayPurchaseRequest.php` (`purchase_token` string 1–2048, `product_id` string 1–150 and a mapped product else `INVALID_ENUM_VALUE`, unknown members `UNKNOWN_FIELD`), `Http/PlayBudget.php` (20/h verify, 10/h restore, keys `billing-play-verify:{user}` / `billing-play-restore:{user}`, like `RedeemBudget`), `Http/Resources/PlayPurchaseResultResource.php`, `Http/Controllers/{PlayPurchaseController,PlayAccountReferenceController}.php`; routes in `Http/routes.php` under `auth:api`: `GET billing/google/account-reference` (`billing.google.account-reference`), `POST billing/google/purchases` with `billing.idempotent` (`billing.google.purchases`); `200` vs `202` by state.

---

## Phase 4: User Story 2 — One purchase, one account (P1)

**Goal**: a token is never granted to a second account.

**Independent Test**: link to A; post as B → 409; reference naming A posted by B → 409.

### Tests

- [X] T025 [P] [US2] `tests/Feature/Billing/GooglePlay/LinkPurchaseTest.php` (spec US2 1–6; two concurrent first verifications by different accounts — one 200, one 409, using two DB connections or a forced race through the repository; replaced-token inheritance and old subscription ended; purged-account token → 409; reference stable and distinct per account).

### Implementation

- [X] T026 [US2] Enforce in `EloquentPlayPurchaseRepository::linkTo(id, userId): bool` (`UPDATE … WHERE user_id IS NULL`) and in `VerifyPlayPurchase` (`LinkDecision`; replaced token via `replaces_lookup` → end the old granting subscription through `ApplyPlayState`); map `PurchaseAlreadyLinked` to 409 with no detail about the other account.

---

## Phase 5: User Story 3 — Google keeps the subscription in step (P1)

**Goal**: every RTDN type converges the subscription on Google's state.

**Independent Test**: signed push per type for a linked purchase; scripted state; queue run; status, dates, events, audit.

### Tests

- [X] T027 [P] [US3] `tests/Feature/Billing/GooglePlay/ProcessNotificationTest.php` (spec US3 1–9: renewed, grace, hold, pause, recovered, canceled then expired, revoked, voided; duplicate and out-of-order processing converge with no second audit; unlinked purchase with an active reference gets linked and acknowledged; reference to a purged account → stays unlinked; test notification ignored; token erased when terminal; final failure → `failed` + log).

### Implementation

- [X] T028 [US3] `app/Modules/Billing/Application/GooglePlay/Jobs/ProcessPlayNotification.php` (queue `billing`, tries 5, backoff [60, 300, 900, 3600], unique by notification id; lock row; voided → revoke via `ApplyPlayState` with reason `refunded` (refundType 1/2) or `revoked_by_provider`; else find or create the `play_purchases` row by lookup and `ReconcilePlayPurchase::sync`; mark `processed`, erase `token_sealed`; `failed()` hook marks `failed`, erases token, logs `billing_play_notification_failed`).

---

## Phase 6: User Story 4 — Only Google can push to the webhook (P1)

**Goal**: the five baseline checks plus email gate every write.

**Independent Test**: each rejection case → 401 and nothing stored; repeat → 204 no effect; certs unreachable → 503.

### Tests

- [X] T029 [P] [US4] `tests/Contract/Billing/GooglePlayWebhookTest.php` (204, 400, 401, 503 against `google-play.yaml`, no client headers sent) and `tests/Feature/Billing/GooglePlay/PushVerificationTest.php` + `tests/Feature/Billing/GooglePlay/WebhookTest.php` (spec US4 1–5 with `PlayPushSigner`: wrong signature, wrong kid then refresh, alg none, wrong iss/aud/email, email unverified, iat ±6 min, expired, missing bearer; foreign subscription/package and test → 204 ignored; malformed data → 204 ignored; repeat message id → 204 no new row; body > 64 KiB or not JSON → 400 before any DB write; certs down with empty cache → 503).

### Implementation

- [X] T030 [US4] `app/Modules/Billing/Application/GooglePlay/ReceivePlayNotification.php` (research R-7 steps 3–6) and `Http/Controllers/PlayWebhookController.php` (size and JSON check → `PushTokenVerifier` → receive → 204); route `POST webhooks/google-play` (`billing.play.webhook`), no `auth:api`, `->withoutMiddleware([RequireClientVersion::class])` and, if `NegotiateJson` refuses Pub/Sub's `Accept`, without it too; log `billing_play_push_rejected` with the reason code only and increment the hourly cache counter `billing:play:rejected:{reason}:{YmdH}` (TTL 25 h; no database write on refusal).

---

## Phase 7: User Story 5 — Restore my purchases (P2)

**Goal**: one request brings back every owned purchase.

**Independent Test**: link to A; restore as A with that token and an unlinked one → both linked/already_yours, entitlements back.

### Tests

- [X] T031 [P] [US5] `tests/Contract/Billing/GooglePlayRestoreTest.php` (200, 400, 401, 409 idempotency, 422, 426, 429, 503) and `tests/Feature/Billing/GooglePlay/RestorePurchasesTest.php` (spec US5 1–6: per-item outcomes in order, `linked_elsewhere` reveals nothing, partial unavailability → 200, total → 503, empty list refreshes linked purchases, >25 items and duplicate tokens → 422).

### Implementation

- [X] T032 [US5] `app/Modules/Billing/Application/GooglePlay/RestorePlayPurchases.php` (research R-6), `Http/Requests/RestorePlayPurchasesRequest.php` (0–25 items, unique tokens — repeat is `INVALID_TYPE` at `/purchases/{i}/purchase_token`), `Http/Resources/PlayRestoreResultResource.php`, `Http/Controllers/PlayRestoreController.php`; route `POST billing/google/restore` with `auth:api`, `billing.idempotent` (`billing.google.restore`).

---

## Phase 8: User Story 6 — Nothing is lost when a notification is (P2)

**Goal**: reconciliation and sweeps bring the record to Google's state without notifications.

**Independent Test**: period ends with no RTDN; hourly pass with renewal → extended, no lapse; with expiry → lapse; unreachable 72 h → lapse by dates; voided sweep → revoked.

### Tests

- [X] T033 [P] [US6] `tests/Feature/Billing/GooglePlay/ReconcileTest.php` (spec US6 1–5; ack retry and 48 h alert log; pending younger than 3 days re-read; unreachable 24 h alert; twice-run idempotent) and extend `tests/Feature/Billing/LapseSubscriptionsTest.php` (a `google_play` row past its end is untouched by `billing:lapse-subscriptions`).

### Implementation

- [X] T034 [US6] `app/Modules/Billing/Application/GooglePlay/{ReconcilePlay,SweepPlay}.php` (research R-10 a–f) and commands `Http/Console/{ReconcilePlay,SweepPlay}.php` (`billing:play-reconcile` hourly, `billing:play-sweep` daily, both `withoutOverlapping`) scheduled in `routes/console.php`; `EloquentSubscriptionRepository::dueForGrace` and `dueForExpiry` exclude `provider = 'google_play'`; log `billing_play_reconciled` counts, alerts `billing_play_ack_overdue`, `billing_play_unreachable`, `billing_play_product_unmapped`.

---

## Phase 9: User Story 7 — Operators see Play at a glance, and cannot override it (P3)

**Goal**: markers and counts, never secrets or overrides.

**Independent Test**: account page markers; overview Play block; paid excludes test.

- [X] T035 [P] [US7] `tests/Feature/Admin/AccountPagePlayTest.php` (auto-renew, acknowledged, test markers; no Extend/Revoke for `google_play`; no token, order id, reference or amount in HTML) and `tests/Feature/Admin/PlayFiguresTest.php` (24 h counts; *Paid · Google Play* excludes test; *Test · Google Play* shown; authorised per request, P-18 rule 7).
- [X] T036 [US7] `app/Modules/Billing/Application/GooglePlay/PlayFigures.php` (24 h counts incl. pushes rejected by reason summed from the hourly cache counters); `Application/Figures/BillingFigures.php` (+ `paidGooglePlay` excluding test, `testGooglePlay`); the operator subscription view (`AccessQuery`/`OperatorSubscriptions` read path) gains `auto_renew`, `play_acknowledged`, `play_test`; Admin account page and overview components/views and `en`/`ar` lang keys render them.

---

## Phase 10: Polish & cross-cutting

- [X] T037 Account purge: extend `SubscriptionService::endForAccountPurge` (spec 022 step, `EndSubscriptions`) to delete the account's `play_account_references` row and null `token_sealed` on its `play_purchases` (stamp `ended_by_account_purge_at`), leaving `google_play` subscriptions unchanged; `PurgeEndedSubscriptions` deletes stamped `play_purchases` with their subscriptions at 400 days. Test `tests/Feature/Billing/GooglePlay/PlayPurgeTest.php` (and a later notification/verify for the purchase has no effect; another account → 409).
- [X] T038 [P] Log scrubber `app/Http/Api/Logging/RedactSecrets.php`: extend the key pattern with `obfuscated|account_reference|order_?id|assertion|private_key` (`token` and `authorization` already match); `tests/Feature/Billing/GooglePlay/TokenNeverLeaksTest.php` (log spy over verify, restore, webhook, processing, reconcile: no token, reference, order id; job payloads hold ids only; audit details hold ids only; responses never echo the token).
- [X] T039 Apply the endpoint and security review checklists (both appendices) to the four operations and each new stored attribute; walk `checklists/billing-integrity.md` with evidence; confirm every implemented response matches `docs/openapi/paths/google-play.yaml` member for member — any deviation is a contract change → stop and record it in plan.md.
- [X] T040 Gates: `dev exec -- composer test` (Pint, Larastan 8, arch, suite, API), `dev exec -- vendor/bin/pest tests/Contract/OpenApi --compact`; `git diff --stat composer.json composer.lock package.json package-lock.json` is empty (no new dependency); walk quickstart.md §1–§6 with the `fake` gateway; record verify and webhook timings against the fake (SC-009: verify p95 < 3 s is bounded by the adapter's 5/10 s timeouts only when Google is healthy — note the measured local p95 in the summary; webhook p95 < 0.5 s). Seeder: BillingSeeder changed (Play prices, create-missing) — say so in the commit description. Deployment notes for the summary: run the three migrations; workers listen to the `billing` queue; schedule the two commands; set the Play keys; production stays `disabled` until the owner completes spec §9 Q1. Ask the owner to run the complete suite.
- [X] T041 Apidog sync: bundle with `npx @redocly/cli bundle docs/openapi/openapi.yaml -o <scratchpad>/bundle.json`; import with `apidog import --project 1332822 --format openapi --file <scratchpad>/bundle.json --module-map "Personal finance backend API=1363204"`; create or update the project doc **"Google Play billing"** with contracts/google-play-api.md (who acknowledges, the account reference and `setObfuscatedAccountId`, request/answer shapes, client handling per status, restore outcomes, product ids from `GET /plans`, the two new codes, the webhook being Google-only). Record the import counts and doc id for the summary.
- [X] T042 Write specs/024-billing-google-play/summary.md (parent writes it)

---

## Dependencies & execution order

- **Phase 1**: T001 first (codes are referenced by refusals); T002–T008 in parallel (glossary T004 before any code using the terms).
- **Phase 2**: T009 and T010 first; T011 ∥ T012 ∥ T013 ∥ T014 after T010; T015 ∥ T016 after T010; T017 after T015; T018 independent after T009; T019 after T009–T011; T020 after T012, T015, T017, T019. Blocks every story.
- **US1** (T021–T024) needs Phase 2. **US2** (T025–T026) builds on US1's verify service. **US3** (T027–T028) and **US4** (T029–T030) need Phase 2; US3's tests post through US4's endpoint or call the job directly (prefer direct, so they are independent). **US5** (T031–T032) needs US1. **US6** (T033–T034) needs Phase 2. **US7** (T035–T036) needs Phase 2.
- **Polish** after all stories; T042 last.

## Parallel examples

- Phase 1: T002–T008 together after T001.
- Phase 2: T011 ∥ T012 ∥ T013 ∥ T014 ∥ T015 ∥ T016 once T010 lands.
- After Phase 2: US3, US4, US6 and US7 in parallel with US1 (different files).
- Test tasks marked [P] within each story before their implementation.

## Implementation strategy

1. **MVP**: Phases 1–2 + US1 + US2 + US4 + US3 → buy, one account per purchase, a verified webhook keeps it in step. Production runs `disabled` until the owner's Play/Cloud setup (spec §9 Q1); C10 integrates against `fake` locally and a license tester on staging.
2. **Increment**: US5 (restore), US6 (reconciliation).
3. **Operators and polish**: US7, purge, scrubber, gates, Apidog, summary.
