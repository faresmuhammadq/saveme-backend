# Implementation Plan: Billing — Google Play

**Branch**: `024-billing-google-play` (trunk-based; work lands on `main`) | **Date**: 2026-10-06 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/024-billing-google-play/spec.md`.

## Summary

Build the Google Play channel inside the existing **Billing** module, with no new dependency:

- **Gateway** (R-1, R-2): a `PlayBillingGateway` port; `google` adapter over the framework's HTTP client calling `androidpublisher` v3 (`subscriptionsv2.get`, `subscriptions.acknowledge`, `products.get`, `products.acknowledge`, `voidedpurchases.list`) with an OAuth token from an RS256 service-account JWT signed by `openssl_sign` and cached; `fake` (scripted, local/testing only, refused at boot elsewhere); `disabled`.
- **Linking** (R-3, R-4): a per-account **Play Account Reference** (keyed hash, the obfuscated account id); a **Play Purchase** record per token (sealed token + keyed lookup, linked once, forever); the subscription's `provider_ref` is the Play Purchase id.
- **Verify and restore** (R-6): `POST /billing/google/purchases` and `/restore`, `Idempotency-Key` through spec 020's middleware, per-person budgets; acknowledgement after commit, retried by a job and a sweep.
- **State** (R-5): Google's states mapped onto ADR-0013's machine — hold and pause are `expired` via a zero-length grace hop; Google's grace; never moving the end backwards; existing `SubscriptionActivated`/`SubscriptionLapsed`; `entitlement_changed` audit entries.
- **RTDN** (R-7, R-8): `POST /webhooks/google-play` verifying the Pub/Sub OIDC token in-house (Google's PEM certs, cached; RS256 via `openssl_verify`; iss, aud, email, iat, exp), inbox by message id, queued processing that re-reads Google.
- **Reconciliation** (R-10): hourly `billing:play-reconcile`, daily `billing:play-sweep` (stale syncs, voided purchases, retention); spec 009's date lapse skips `google_play`.
- **Catalogue** (R-9): `google_play` price entries seeded create-missing from configuration.
- **Leaving, operators, logs** (R-11–R-13).
- **API frozen now**: `docs/openapi/paths/google-play.yaml` (4 operations), indexed, examples, `pubsubPush` security scheme, `info.version` **v1.15**; codes `PURCHASE_ALREADY_LINKED`, `PURCHASE_NOT_VERIFIED` catalogued with the runtime enum in the first implementation task.
- **Docs**: ADR-0026, constitution PATCH **v1.3.15**, glossary, security baseline, privacy commitments (en/ar), api-conventions, briefs README.

## Technical Context

**Language/Version**: PHP 8.5, Laravel 13.

**Primary Dependencies**: **none new** (Quality Gate 6, CONTRIBUTING 17 — the owner has approved no Google package). Used, all installed: `Illuminate\Http\Client` (Guzzle), `ext-openssl` (`openssl_pkey_get_private`, `openssl_sign`, `openssl_pkey_get_public`, `openssl_verify`), `ext-sodium` (Billing's existing sealer), queues (database driver), scheduler, cache (`Cache::lock`), `RateLimiter`, Pest + Spectator, Livewire (existing admin components).

**Storage**: PostgreSQL 16 — Billing: `play_account_references`, `play_purchases`, `play_notifications`; existing `subscriptions` and `plans` unchanged in shape. Cache: Google access token (≤ 55 min), Google OIDC certificates (≤ 24 h), voided-purchases watermark.

**Testing**: Pest — Unit (state map, link decision, OIDC claims, JWT signing, notification parsing), Feature against PostgreSQL (verify, link races, acknowledge, restore, webhook, processing, reconcile, sweep, gateway with `Http::fake`, boot refusal, seeder, purge, leaks, admin figures), Contract (Spectator on `google-play.yaml`, every documented status), Arch (existing gates).

**Target Platform**: the Linux server (`dev` container); Google Play Developer API, Google OAuth and Google's certificate endpoint over HTTPS; Cloud Pub/Sub pushes in.

**Project Type**: modular-monolith JSON API with a webhook, queued jobs and scheduled passes.

**Performance Goals**: verify p95 < 3 s including two Google round trips (SC-009); webhook p95 < 0.5 s (verification + one insert); account reference p95 < 300 ms; hourly reconcile over 10,000 Play subscriptions < 10 minutes (Google's default quota is 200,000 calls/day; the passes stay well inside it).

**Constraints**: P-02 (Billing calls Identity's `Accounts` and audit Application services only), P-03 (no money from the client; reference prices stay minor units), P-09 (product → plan **id**; no plan-code comparison outside resolution and the seeder), P-10 (no payment initiated or recorded outside Play), P-11 (existing events after commit), P-12 (UTC instants from Google's millis/RFC 3339), P-15 (tokens, references, assertions never logged), security baseline (provider-message checks; sealed token; route budgets), ADR-0013 rule 4 (no undrawn arc).

**Scale/Scope**: 4 API operations, 2 scheduled commands, 3 migrations, ~35 classes in Billing, small additions to Admin and Identity-free code paths, ~22 test files, 2 new error codes.

## Constitution Check

*Constitution v1.3.13 (`.specify/memory/constitution.md`); this feature's amendment is planned as v1.3.15 (spec 023 takes v1.3.14). GATE: must pass before Phase 0 research. Re-check after Phase 1 design, keeping both results.*

### Pre-design

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | New terms Play Purchase, Play Account Reference, Play Notification, Purchase Token go into the glossary before code (T003); "Subscription" stays the product term; no "account" synonym for wallet — "account" here is the person's account (Identity), as elsewhere |
| P-02 Module boundaries | PASS | All code in `app/Modules/Billing/{Domain,Application,Infrastructure,Http}`; Identity reached only via `Identity\Application\Accounts` and `AuditLog`; Admin reads only Billing's Application query services; the JWT helper is copied from spec 023, not imported |
| P-03 Money | PASS | No amount accepted from the client; reference Play prices are integer minor units in `plans.prices`; no money column added |
| P-04 Ledger integrity | N-A | Writes no transaction or posting |
| P-05 Derived balances | N-A | Reads no balance |
| P-06 Identifiers | PASS | UUIDv7 for every new row; `provider_ref` is a Play Purchase id; no ordering by UUID time |
| P-07 Sync and concurrency | N-A | No syncable entity; Subscription stays a read endpoint (glossary). Concurrency handled by row locks and unique keys (research R-4) |
| P-08 Authorization | PASS | Caller acts only on own purchases; another account's purchase is `409` without disclosure; the webhook is authorised only by Google's verified token |
| P-09 Entitlements | PASS | Results read through `EntitlementService`; product → plan id via `PlanCatalogue`; plan codes appear only in the seeder's configuration keys (ADR-0013 rule 6); losing a Play subscription deletes nothing |
| P-10 Billing channels | PASS | Purchases happen in Play Billing (rule 1); the two app writes verify a Play payment already taken — "restore purchases" and its single-purchase form, the brief's and C10's agreed surface (rule 2); no price or payment accepted; InstaPay untouched (rule 3) |
| P-11 Events | PASS | Existing `SubscriptionActivated`/`SubscriptionLapsed` (`ShouldDispatchAfterCommit`), ids and scalars; listeners unchanged; jobs carry ids only and are idempotent (unique, state-converging) |
| P-12 Time | PASS | Google's millis and RFC 3339 instants stored UTC; no calendar dates |
| P-13 Naming | PASS | `play_*` tables, `GooglePlay` namespaces, `billing:play-*` commands; Google is a vendor name, not the product codename |
| P-14 Localisation | PASS | Problem sentences for the two new codes in `lang/{en,ar}/problems.php`; admin labels en/ar; no user-facing text otherwise |
| P-15 Observability | PASS | Fixed log events with ids/codes (FR-062); scrubber keys added; tokens never in URLs logged; correlation id propagated to jobs |
| P-16 Quality gates | PASS | Each §4 invariant names a failing test; unique keys enforce linking; `composer test` gate |
| P-17 Scope guard | PASS | Android/Play only; no server-side refund or cancel ("refunds automation" excluded); no web checkout |
| P-18 Delivery surfaces | PASS | Every capability under `/api/v1`; back office gains read-only markers and counts through Billing's Application query services, authorised per request |

### Post-design re-check

Unchanged: all rows PASS / N-A as above.

- **CONTRIBUTING item 18 — ADR-0026** "Google Play purchases verified through an in-house gateway; Play is the source of truth": extends ADR-0013 (rule 3: how Play verification and RTDN normalise into the one record — Play Purchase beside the subscription, `provider_ref` = its id; rule 4: Google's states mapped onto the drawn arcs, hold/pause as `expired` via a zero-length grace hop; rule 14 untouched; Google's grace replaces `billing.grace_days` for this channel) and ADR-0010 (purchase tokens, references and Google credentials never logged). Records the no-SDK decision and the drop-in path to the official library. Constitution **PATCH v1.3.15**: P-09 and P-10 list ADR-0026. No rule changes.
- **Security baseline amendments**: *Messages from the payment provider* gains the Google mapping (issuer values, audience = configured push endpoint, the `email` check, certificates from Google's endpoint only, the inbox by message id, malformed-but-verified acknowledged); protected attributes (purchase token sealed + lookup; account reference keyed hash); retention (Play purchases with their subscription, unlinked 10 days, inbox 30 days, token erased at purge); route budgets (verify 20/h/person, restore 10/h/person); what must never be logged (adds the account reference, order ids, the service-account key, the OAuth assertion and access token).
- **Privacy commitments** (en/ar): "What we collect" gains "the state of your Google Play subscription, read from Google — never your card or your Google account details"; mapping rows.
- **API conventions**: Idempotency names both Play paths as the "Google Play purchase acknowledgement" operation; a note that `/webhooks/*` is outside the client-version gate.
- **Error catalogue**: two codes, `since: v1.15`.

## Complexity Tracking

| Violation | Why needed | Simpler alternative rejected because |
|---|---|---|
| ~300 lines of in-house Google client (OAuth JWT, three endpoints, OIDC verification) instead of `google/apiclient` | The owner has approved no new dependency | The official library needs approval (CONTRIBUTING 17); a third-party JWT library is also a dependency |
| A second copy of the RS256 service-account token code (after spec 023) | P-02 forbids importing Notifications' Infrastructure | Moving it to `Shared` would put config-reading key handling into the persistence-free kernel (P-02 rule 5) |
| A zero-length grace hop for `active → expired` | ADR-0013 rule 4 draws no `active → expired` arc | A new arc or an `on_hold` status needs a superseding record and changes every status consumer |

## Project Structure

### Documentation (this feature)

```text
specs/024-billing-google-play/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/google-play-api.md
├── checklists/requirements.md
├── checklists/billing-integrity.md   # /speckit-checklist
├── tasks.md                          # /speckit-tasks
└── summary.md                        # written by the parent after implementation
```

### Source Code (repository root)

```text
app/Modules/Billing/
├── Domain/GooglePlay/
│   ├── PlayBillingGateway.php          # port
│   ├── PlayPurchaseRecord.php, PlayProductKind.php, PlayState.php
│   ├── GatewayOutcome.php              # PurchaseFound | PurchaseNotFound | Unavailable | Acknowledged | AlreadyAcknowledged
│   ├── PlayStateMap.php, PlayTransition.php     # R-5, pure
│   ├── LinkDecision.php                # R-3, pure
│   ├── DeveloperNotification.php       # parsed RTDN, pure
│   ├── OidcClaims.php                  # claim checks, pure
│   └── Refusals/{PurchaseAlreadyLinked,PurchaseNotVerified,PlayUnavailable}.php
├── Domain/Ports/{PlayPurchaseRepository,PlayNotificationRepository,PlayAccountReferences,TokenLookup}.php
├── Application/GooglePlay/
│   ├── AccountReferences.php           # reference for user; user for reference
│   ├── VerifyPlayPurchase.php, RestorePlayPurchases.php
│   ├── ApplyPlayState.php              # subscription create/transition, audit, events
│   ├── ReconcilePlayPurchase.php       # read Google + apply (shared by all paths)
│   ├── ReceivePlayNotification.php     # webhook body → inbox
│   ├── Jobs/{ProcessPlayNotification,AcknowledgePlayPurchase}.php
│   ├── ReconcilePlay.php, SweepPlay.php   # hourly, daily passes
│   └── PlayFigures.php                 # operator counts
├── Application/Plans/PlanCatalogue.php            # + planForPlayProduct
├── Application/Subscriptions/{LapseSubscriptions,SubscriptionService,AccessQuery}.php  # skip google_play; purge erasure; markers
├── Application/Figures/BillingFigures.php         # paid excludes test
├── Infrastructure/GooglePlay/
│   ├── GooglePlayGateway.php, ServiceAccountTokenSource.php, ServiceAccountKey.php, Jwt.php
│   ├── PushTokenVerifier.php, GoogleCertificates.php
│   ├── FakePlayBillingGateway.php, DisabledPlayBillingGateway.php, PlayGatewayFactory.php
│   └── HmacTokenLookup.php
├── Infrastructure/Models/{PlayPurchase,PlayNotification,PlayAccountReference}.php
├── Infrastructure/Repositories/Eloquent{PlayPurchaseRepository,PlayNotificationRepository,PlayAccountReferences}.php
├── Infrastructure/Database/Migrations/
│   ├── 2026_10_13_000001_create_play_account_references_table.php
│   ├── 2026_10_13_000002_create_play_purchases_table.php
│   └── 2026_10_13_000003_create_play_notifications_table.php
├── Infrastructure/Database/Seeders/BillingSeeder.php   # + google_play prices, create-missing
└── Http/
    ├── Controllers/{PlayAccountReferenceController,PlayPurchaseController,PlayRestoreController,PlayWebhookController}.php
    ├── Requests/{VerifyPlayPurchaseRequest,RestorePlayPurchasesRequest}.php
    ├── Resources/{PlayPurchaseResultResource,PlayRestoreResultResource}.php
    ├── PlayBudget.php
    ├── Console/{ReconcilePlay,SweepPlay}.php
    └── routes.php                      # + 4 routes; the webhook route ->withoutMiddleware(RequireClientVersion) (and NegotiateJson if it refuses Pub/Sub's Accept), no auth:api

app/Modules/Identity/Application/Audit/Details/EntitlementChangedDetail.php   # action union + purchase, renew, recover
app/Modules/Admin/…                     # account page markers, overview Play block (views, lang en/ar)
app/Modules/Shared/Domain/Errors/ErrorCode.php; lang/{en,ar}/problems.php     # two codes
app/Http/Api/Logging/RedactSecrets.php  # scrubber keys
config/billing.php; routes/console.php; .env.example; phpunit.xml

docs/openapi/paths/google-play.yaml, docs/openapi/openapi.yaml (index, scheme, v1.15)   # done in this phase
docs/openapi/error-catalog.yaml          # two codes (T001, with the enum)
docs/adr/0026-…, docs/adr/README.md, .specify/memory/constitution.md, docs/glossary.md,
docs/security-baseline.md, docs/privacy-commitments.md, docs/api-conventions.md, specs/briefs/README.md

tests/Unit/Billing/GooglePlay/…, tests/Feature/Billing/GooglePlay/…, tests/Feature/Admin/{PlayFiguresTest,AccountPagePlayTest}.php,
tests/Contract/Billing/{GooglePlayAccountReferenceTest,GooglePlayPurchasesTest,GooglePlayRestoreTest,GooglePlayWebhookTest}.php,
tests/Support/PlayPushSigner.php         # test key pair + signed push tokens
```

**Structure Decision**: everything in Billing (the constitution's Plans & Billing row already owns "Google Play purchases"); a `GooglePlay` sub-namespace in each layer keeps the channel's code together. Admin changes are view additions over Billing's query services.

## Phase 0 / Phase 1 outputs

- [research.md](./research.md) — R-1 … R-15, no NEEDS CLARIFICATION left.
- [data-model.md](./data-model.md) — three tables, the subscription mapping, state machines, configuration, baseline rows.
- [contracts/google-play-api.md](./contracts/google-play-api.md) — what C10 binds to; machine-readable in `docs/openapi/paths/google-play.yaml` (written and indexed in this phase; `tests/Contract/OpenApi` green).
- [quickstart.md](./quickstart.md) — validation run.

## Assumed defaults added at planning (A-n)

Owner away with delegated authority; each the conservative option.

- **A-1** Gateway default when unset is `disabled`; `.env.example` sets `fake` for local work.
- **A-2** Service-account credentials via `BILLING_PLAY_CREDENTIALS` (path or base64 JSON); never committed; separate from spec 023's FCM key (different Google permissions).
- **A-3** One Play subscription product per plan with one auto-renewing base plan; base plan id informational only.
- **A-4** Access token and OIDC certificates in the default cache store with locks; 5-minute early refresh; certificate cache clamped 5 min – 24 h.
- **A-5** Jobs on a `billing` queue name on the existing connection; workers must listen to it (deployment note, as spec 023 A-8).
- **A-6** The webhook acknowledges (204) a verified but unparsable message, recording it as `ignored (malformed)`, so Pub/Sub does not retry forever.
- **A-7** Restore re-reads the caller's linked purchases on every call (at most a few per person).
- **A-8** `pending` purchases are linked to the caller when the reference allows, so a later "purchased" notification grants without another app call.
- **A-9** The 72-hour unreachable fallback lapses by dates with a zero-length grace, never with `billing.grace_days`.
- **A-10** Voided-purchases watermark kept in cache; losing it re-reads 30 days (idempotent).
- **A-11** Migration date prefix `2026_10_13_*` follows spec 023's `2026_10_12_*` so the two never interleave.

## Implementation notes

- **Resume audit**: the stopped run had left nearly everything built (docs T001-T008, domain, gateway adapters, application, controllers, console commands, admin views, purge, scrubber, all test files; 330 tests green on first run, tasks.md unticked). Resumed work: fixed 11 Larastan level-8 findings (nullsafe/`??` on non-nullable values, `PlayTransition` audit action typed as the audit vocabulary, catalogue price keys), fixed two test-suite faults, ticked T001-T041.
- **Test faults fixed**: `tokenEndpoint()` and `restoreItem()` helper names collided with other suites' global Pest helpers (fatal "Cannot redeclare" in the full run) and were renamed `playTokenEndpoint()` / `playRestoreItem()`; the push-verification dataset computed `time()` at collection, so a long suite run pushed "issued six minutes ahead" inside the five-minute window; the clocks are now closures evaluated inside the test.
- **Migrations**: three additive (`2026_10_13_000001-3`: play_account_references, play_purchases, play_notifications), applied to dev with `dev migrate --force` after their tests passed; `BillingSeeder` was not run on dev (create-missing Play prices apply at the next seed/deploy). Queue workers restarted.
- **Performance (SC-009, `PLAY_PERF=1`, fake gateway, xdebug off)**: p95 verify 71.7 ms, webhook 8.3 ms, account reference 11.1 ms.
- **Contract review (T039)**: the four operations are exercised in `tests/Contract/Billing/GooglePlay*` with the frozen `docs/openapi/paths/google-play.yaml`; no deviation found, no contract change. The reviewer-owned `checklists/billing-integrity.md` markers stay unchecked by design.
- **Dependencies**: `composer.json`, `composer.lock`, `package*.json` unchanged.
- **Apidog (T041)**: bundle imported into project 1332822 / module 1363204 (no errors, all Play schemas and the four paths present); project doc "Google Play billing" (id 2469427) created from `contracts/google-play-api.md`.
