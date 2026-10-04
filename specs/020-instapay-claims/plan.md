# Implementation Plan: Billing — InstaPay Claims

**Branch**: `020-instapay-claims` (trunk-based; work lands on `main`) | **Date**: 2026-10-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/020-instapay-claims/spec.md`.

## Summary

Add the website-only InstaPay payment path to the Billing module: a public, uniform, rate-limited claim endpoint; operator-only review commands (approve, reject, revoke code) that B12's screens and the command line call; single-use Redeem Codes mailed on approval; the in-app `POST /billing/redeem` that creates an `instapay` subscription for the signed-in, verified account holding the claim's address; claim and code expiry, a renewal reminder, and retention purges.

- **Idempotency** (R-2): the first implementation of api-conventions' `Idempotency-Key`, owned by Billing (`idempotency_records`, `Idempotent` middleware), used by both operations and later by B10.
- **Data** (data-model.md): `payment_claims`, `redeem_codes`, `idempotency_records`; `subscriptions.renewal_reminded_at`; Identity's audit CHECK gains `payment_claim`.
- **Protection** (R-5): claim address and receipt image sealed under a Billing keyring (copy of spec 018's sealer); lookup value from Identity (R-6); receipt on the private `local` disk; 5-minute signed operator link served by Admin (R-10).
- **Codes** (R-7): 80-bit Crockford base32, SHA-256 at rest, 30 days.
- **Redemption** (R-8): subscription created at redemption, `instapay` periods chain, nothing else touched; governing per spec 009.
- **API**: two operations in `docs/openapi/paths/instapay.yaml` (written, indexed, examples, `info.version` v1.12 in this phase), frozen for C11 and the landing page. Three new codes (catalogue + enum + lang in T002).
- **Docs** (T001, T003): ADR-0023, constitution PATCH v1.3.12, glossary, security baseline, privacy commitments, api-conventions, briefs README.

## Technical Context

**Language/Version**: PHP 8.5, Laravel 13.

**Primary Dependencies**: none new (P-16 Quality Gate 6; CONTRIBUTING item 17). Mail, queues, scheduler, `RateLimiter`, `Storage` (`local` disk), `URL::temporarySignedRoute`, libsodium, `getimagesizefromstring`, Pest/Spectator — all installed.

**Storage**: PostgreSQL 16 (three new tables, one column, one CHECK change); the `local` filesystem disk (`storage/app/private`) for sealed receipt files.

**Testing**: Pest.
- Unit: `ClaimReference` (normalise, bounds), `ClaimPrice` rule (plan has InstaPay price; exact amount; currency), `ReceiptImage` (signatures, size, declared type), `RedeemCode` (generate alphabet/length, normalise and fold, hash, display), `RedeemedPeriod` (monthly overflow, yearly leap day, custom, lifetime, chaining), `PaymentClaim` transitions, `CanonicalJson` fingerprint.
- Feature: submit (stored sealed, receipt file sealed, no lookup of an account, duplicates listed), review (approve/reject/revoke, refusals, concurrency, one approved reference, plan retired, audit entries, mails queued after commit, encrypted jobs), redeem (order of refusals, verification, repeat by same account, two keys, concurrency, overlap with Play/manual, chaining, audit, event), expire claims, expire codes, reminders (eligibility, once), retention (erase at 90, delete at 400, files removed), idempotency (replay, reuse, in-flight, refusal not stored, purge), logging (no receipt, code or address), CLI commands, receipt route (operator only, signature, expiry, recorded view, headers).
- Contract: both operations, every documented status via Spectator, including registered-vs-unregistered equality and android 404.
- Arch: existing gates (Billing layers; Admin route and controller under P-18 gates).

**Target Platform**: the Linux server (the `dev` container).

**Project Type**: modular-monolith JSON API plus the back-office route.

**Performance Goals** (SC-007): claim with a 3 MiB receipt p95 < 800 ms; redeem p95 < 300 ms; Xdebug off.

**Constraints**: P-02 (Billing reaches Identity only through `ClaimAddresses`, `VerificationGate`, `AuditLog`; Admin only through `OperatorDirectory`; Admin reaches Billing only through `ClaimReceipts`), P-03 (integer minor units), P-06 (UUIDv7), P-08 rule 4 (operator-only review), P-09 (no plan code compared; entitlements through `EntitlementService`), P-10 (claim web-only; redeem is "enter code"), P-11 (after commit), P-12 (UTC instants), P-14 (mail text in `lang/{en,ar}/billing.php`), P-15 (redaction, encrypted jobs), P-18 rules 4–6 (receipt route under `/admin`, operator guard; no screen here).

**Scale/Scope**:
- 2 API operations, 1 back-office route, 9 artisan commands (5 scheduled, 4 operator CLI);
- 4 migrations (Billing ×3 incl. the subscription column, Identity ×1);
- about 45 classes across Billing, Identity (`ClaimAddresses`, audit detail and enum cases), Admin (receipt controller and route, two `AdminAction` cases);
- about 22 test files;
- 4 mailables with en/ar texts.

## Constitution Check

*Constitution v1.3.11 (`.specify/memory/constitution.md`). GATE: must pass before Phase 0 research. Re-check after Phase 1 design, keeping both results.*

### Pre-design

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | Payment Claim, Redeem Code, Subscription (glossary); "transfer" is never used for an InstaPay payment (glossary banned synonyms); Idempotency Record added to the glossary in T001 before code |
| P-02 Module boundaries | PASS | All claim code in `app/Modules/Billing/{Domain,Application,Infrastructure,Http}`; Identity through `ClaimAddresses`/`VerificationGate`/`AuditLog` (Application); Admin's receipt route calls Billing's Application `ClaimReceipts`; Billing calls Admin's Application `OperatorDirectory` from its CLI; no Infrastructure or Http crossing |
| P-03 Money | PASS | `amount_minor` bigint + `currency`; exact equality with the plan price; no float, no decimal |
| P-04 Ledger integrity | N-A | No ledger write |
| P-05 Derived balances | N-A | No balance |
| P-06 Identifiers | PASS | UUIDv7 server ids for claims, codes, subscriptions, idempotency rows; no client-generated id needed (POST operations carry `Idempotency-Key` instead, per api-conventions) |
| P-07 Sync and concurrency | N-A | Not syncable; concurrency handled by row locks and unique indexes (data-model, Lock order) |
| P-08 Authorization | PASS | Review commands operator-only (no `/api/v1` route; CLI checks an active operator; B12 behind the operator guard and a fresh factor); redeem bound to the caller's own verified address; no wallet involved |
| P-09 Entitlements | PASS | No capability check added; redeem returns `EntitlementService` output; no plan code compared (plan identified by id; "has an InstaPay price" read from plan data) |
| P-10 Billing channels | PASS | Claim endpoint public, `web`-only (404 for android), never in the app; redeem is "enter code"; ADR-0023 records the claim/redeem relation |
| P-11 Events | PASS | `SubscriptionActivated` after commit with identifiers only; mails `afterCommit()`; no new cross-module listener |
| P-12 Time | PASS | `expires_at`, periods and reminders in UTC instants; plan periods added in UTC |
| P-13 Naming | PASS | No codename in classes, tables or routes; mails use `config('app.name')` |
| P-14 Localisation | PASS | Mail texts and refusal details in `lang/{en,ar}`; claim language stored; Western numerals |
| P-15 Observability | PASS | `RedactSecrets` gains receipt/code keys; request bodies not logged; code-carrying jobs encrypted; ids at debug only |
| P-16 Quality gates | PASS | Every §4 invariant names its test; PostgreSQL partial unique index and `ON CONFLICT` |
| P-17 Scope guard | PASS | No web checkout with a registered provider (InstaPay is a manual bank transfer reviewed by a person), no refunds automation |
| P-18 Delivery surfaces | PASS | Both user capabilities are `/api/v1` JSON; the only server-rendered piece is the operator receipt route under `/admin`, operator guard; review screens are B12's |

### Post-design re-check

Unchanged: all rows PASS or N-A.

- **CONTRIBUTING item 18**: ADR-0023 (T001) supersedes ADR-0013 rule 12's word "authenticated" only, narrows rule 2's "`manual` records … redeem codes" to codes without a claim, and states that approval does not use rule 4's "claim approved" reactivation arc. Constitution PATCH v1.3.12: P-09 and P-10 list ADR-0023.
- **Security baseline amendments** (T001): protected attributes (claim address, claim lookup value, receipt image, redeem code, idempotency record, review note); route budgets (claim ×2, redeem); uniform answers (claim submission row); retention (receipt image and claim address 90 days after close; claim record 400 days; redeem code with its claim; idempotency 24 hours — existing row); vocabulary (`claim_reviewed`, Billing; subject `payment_claim`); sensitive acts (approve, reject, revoke a code); ownership row "InstaPay claims and redeem codes — Billing".
- **New error codes**: three, `info.version` v1.12 (FR-042).

## Complexity Tracking

| Violation | Why needed | Simpler alternative rejected because |
|---|---|---|
| `BillingSealer`/`BillingKeyring` duplicate ~60 lines of spec 018's `AdminSealer`/`AdminKeyring` | A claim has no user, so Identity's per-user cipher cannot seal its address; P-02 forbids importing another module's Infrastructure and the shared kernel holds no infrastructure | Laravel's `encrypted` cast lacks the key reference the baseline requires; a shared sealer in Shared needs a constitution amendment (recorded as a follow-up for B12/B15 if a third copy appears) |
| The idempotency store lives in Billing, not in the shared API runtime | `app/Http/Api` holds no persistence by convention, and all three operations that need the key are Billing's | A shared-runtime table would be persistence outside any module (P-02 rule 5 spirit) |

## Project Structure

### Documentation (this feature)

```text
specs/020-instapay-claims/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/instapay-api.md
├── checklists/requirements.md
├── checklists/security.md          # /speckit-checklist
└── tasks.md                        # /speckit-tasks
```

### Source Code (repository root)

```text
app/Modules/Billing/
├── Domain/
│   ├── Claims/            # PaymentClaim (state rules), ClaimStatus, RejectReason, ClaimReference,
│   │                      # ClaimPrice, ReceiptImage, ReceiptMediaType, ClaimRecord
│   ├── RedeemCodes/       # RedeemCode (generate/normalise/hash/display), RedeemCodeStatus,
│   │                      # RedeemCodeRecord, RedeemedPeriod
│   ├── Idempotency/       # IdempotencyOutcome, CanonicalJson
│   ├── Ports/             # ClaimRepository, RedeemCodeRepository, ReceiptStore, AddressSealer,
│   │                      # IdempotencyStore; SubscriptionRepository (+ lockForUser, markReminded,
│   │                      # dueForReminder)
│   └── Refusals/          # ClaimNotReviewable, ReferenceAlreadyApproved, CodeNotRevocable,
│                          # RedeemCodeUnknown(→NOT_FOUND), RedeemCodeUsed, RedeemCodeExpired,
│                          # RedeemCodeEmailMismatch, InvalidRejectReason
├── Application/
│   ├── Claims/            # ClaimService (submit, approve, reject, revokeCode), ClaimQuery
│   │                      # (queue, detail with address and account_exists, duplicates),
│   │                      # ClaimReceipts (temporaryUrl, open), ExpireClaims, PurgeClaims
│   ├── RedeemCodes/       # RedeemCodes (redeem), ExpireRedeemCodes, RedeemResult DTO
│   ├── Reminders/         # SendRenewalReminders
│   ├── Idempotency/       # IdempotencyGuard (begin, complete, abandon), PurgeIdempotencyRecords
│   ├── Jobs/              # SendClaimApprovedMail, SendClaimRejectedMail, SendClaimExpiredMail,
│   │                      # SendRenewalReminderMail (ShouldBeEncrypted, afterCommit)
│   └── Mail/              # ClaimApprovedMail, ClaimRejectedMail, ClaimExpiredMail, RenewalReminderMail
├── Infrastructure/
│   ├── Crypto/            # BillingKeyring, BillingSealer
│   ├── Models/            # PaymentClaim, RedeemCode, IdempotencyRecord
│   ├── Repositories/      # Eloquent* for the ports; SealedReceiptStore (local disk)
│   └── Database/Migrations/
│       ├── 2026_10_09_000001_create_payment_claims_table.php
│       ├── 2026_10_09_000002_create_redeem_codes_table.php
│       ├── 2026_10_09_000003_create_idempotency_records_table.php
│       └── 2026_10_09_000004_add_renewal_reminded_at_to_subscriptions.php
└── Http/
    ├── Middleware/        # Idempotent, ClaimsAreWebOnly
    ├── Requests/          # SubmitClaimRequest, RedeemCodeRequest (ApiRequest)
    ├── Controllers/       # InstapayClaimController, RedeemController
    ├── Resources/         # ClaimReceiptResource, RedeemResultResource
    ├── Console/           # ExpireClaims, ExpireRedeemCodes, SendRenewalReminders, PurgeClaims,
    │                      # PurgeIdempotencyRecords, ListClaims, ApproveClaim, RejectClaim, RevokeClaimCode
    └── routes.php         # + the two operations
app/Modules/Identity/Application/Billing/ClaimAddresses.php
app/Modules/Identity/Application/Audit/Details/ClaimReviewedDetail.php
app/Modules/Identity/Domain/Audit/{AuditEvent,SubjectType}.php               # + ClaimReviewed, PaymentClaim
app/Modules/Identity/Infrastructure/Database/Migrations/2026_10_09_000000_allow_payment_claim_audit_subject.php
app/Modules/Admin/Http/Controllers/ClaimReceiptController.php + route in Admin's routes   # admin.claims.receipt
app/Modules/Admin/Domain/Operators/AdminAction.php                          # + ClaimReceiptViewed, ClaimAddressRead
app/Http/Api/Logging/RedactSecrets.php                                      # + keys
app/Modules/Shared/Domain/Errors/ErrorCode.php, lang/{en,ar}/problems.php    # + 3 codes (T002)
lang/{en,ar}/billing.php                                                    # mail texts
config/billing.php, .env.example, phpunit.xml, routes/console.php (schedule)

tests/Unit/Billing/{Claims,RedeemCodes,Idempotency}/, tests/Feature/Billing/{Claims,Redeem,Reminders,Idempotency}/,
tests/Contract/Billing/{SubmitClaimTest,RedeemTest}.php, tests/Feature/Admin/ClaimReceiptRouteTest.php
```

**Structure Decision**: everything in the existing Billing module (the constitution lists "InstaPay claims, redemption codes" and `ClaimService` there). The receipt route sits in Admin because P-18 rule 4 puts every back-office route there.

## Design notes

1. **Claim request flow**: `api` group → `ClaimsAreWebOnly` (404) → `Idempotent` (400/409/replay; `begin`) → `SubmitClaimRequest` (422; rules: `email` string ≤254 + `ClaimAddresses::normaliseAndLookup` validity; `plan_id` uuid + `ClaimPrice` rule via `PlanRepository`; `amount_minor` `MinorUnits`; `currency` `IsoCurrency`; `reference` regex after normalisation; `screenshot.media_type` in; `screenshot.data` string ≤ 4,194,304 + `ReceiptImage` rule) → controller hits `billing-claim-origin` and `billing-claim-address` (429) → `ClaimService::submit(SubmitClaim DTO)` → `ClaimReceiptResource` 202 → `Idempotent::complete` stores status+body.
2. **Submit**: in one transaction: insert claim (sealed address, lookup, locale from `app()->getLocale()`, `expires_at = now + claim_days`); if a receipt: seal bytes and `Storage::disk('local')->put(path, sealed)` **after** the insert succeeds and before commit; a failure rolls back and deletes the file (`DB::afterRollBack` style cleanup). No Identity user read.
3. **Approve**: `lockForUpdate(claim)` → `PaymentClaim::assertReviewable(now)` → plan check → `RedeemCode::generate()` → insert code (hash, `expires_at = now + code_days`) → update claim (status, reviewer, time) inside a savepoint catching the unique violation on `payment_claims_one_approved_reference` → `ReferenceAlreadyApproved` → audit → `SendClaimApprovedMail::dispatch(claimId, code->display())->afterCommit()`.
4. **Redeem**: see research R-8. Same-account repeat returns `RedeemResult` rebuilt from the stored subscription (no new audit, no event).
5. **Responses**: `governing` from `MySubscription` application query; `entitlements` from `EntitlementService::forUser($userId)->summary()` — the same builders `BillingReadController` uses, so the three shapes cannot drift.
6. **Receipt route**: Admin `routes` group `operator` guard + `signed`; `ClaimReceiptController` → `AdminAuditService::claimReadByOperator(operatorId, claimId, ClaimReceiptViewed)` → `ClaimReceipts::open` → streamed response with the headers of R-10. A claim erased or without receipt → 404 page.
7. **Scheduler** (`routes/console.php`): `billing:expire-claims` hourly, `billing:purge-idempotency-records` hourly, `billing:expire-redeem-codes` daily, `billing:send-renewal-reminders` daily, `billing:purge-claims` daily; all `withoutOverlapping()`.
8. **Logging**: none beyond the request line; refusals are not reported (existing `dontReport(Refusal)`).

## Assumed defaults (owner away)

| # | Decision | Why |
|---|---|---|
| A-1 | Claim endpoint public, `web` platform only (android → 404) | Brief: landing page; P-10 rule 3; ADR-0023 |
| A-2 | Uniform answer by construction: no account lookup on submission | Baseline, Uniform answers; equal timing without padding |
| A-3 | Claims without an account are reviewed normally; the code waits 30 days for an account | Lets people pay before registering; no extra state |
| A-4 | Claim fields `email, plan_id, amount_minor, currency, reference, screenshot?`; exact price equality; plan must have an InstaPay price | Brief; keeps review load down |
| A-5 | Reference normalised to 8–32 `A–Z0–9` | Covers numeric InstaPay references with margin; rejects junk |
| A-6 | 5/hour/origin and 5/24h/address claim budgets (429), counted after validation | Brief: rate-limit to keep review load down |
| A-7 | Duplicate references accepted and flagged; one approval per reference | Refusing would let anyone block a payer |
| A-8 | Screenshot optional, JPEG/PNG/WebP, ≤ 3 MiB, base64 in JSON | JSON-only conventions; no multipart |
| A-9 | Receipt and address sealed under a Billing keyring; receipt on the private `local` disk | Baseline: encrypted with key id; claim has no user key |
| A-10 | Operator receipt link signed, 5 minutes, under `/admin`, view recorded | Brief; P-18 rule 4; baseline recorded reads |
| A-11 | Erase address, lookup and receipt 90 days after close; delete claim and code records 400 days after close | Dispute window vs privacy; matches the audit horizon |
| A-12 | Claims expire 7 days after submission (hourly sweep + check at review), with a notice mail | Brief; a payer deserves to know |
| A-13 | Code: 16 Crockford base32 chars (80 bits), SHA-256 at rest, 30 days, single use | Brief; C11 masking; unguessable |
| A-14 | Redeem only by a verified account whose address lookup equals the claim's | Brief binding; verification proves the mailbox |
| A-15 | Subscription created at redemption, `instapay`, `provider_ref` = claim id | ADR-0013 rule 2 needs a user; one claim → one subscription |
| A-16 | `instapay` periods chain on early renewal; Play/manual untouched; governing per spec 009 | Brief: spec 3 precedence; no lost days |
| A-17 | Same-account repeat redemption → 200 same result; other account → 409 | Natural idempotency beyond the key |
| A-18 | Redeem budget 10/hour/person | Bounds noise; guessing already hopeless |
| A-19 | Three new codes: `REDEEM_CODE_USED` 409, `REDEEM_CODE_EXPIRED` 410, `REDEEM_CODE_EMAIL_MISMATCH` 403; unknown/revoked → `NOT_FOUND` | C11 asked for distinct expired/used/mismatch; spec 016 precedent for unknown tokens |
| A-20 | Reject reasons fixed (`reference_not_found`, `amount_mismatch`, `duplicate`, `other`) + internal note ≤ 500 | P-14: mailed text must be translatable; notes never mailed or audited |
| A-21 | Revoke-code command for mistaken approvals; no mail | Glossary state `revoked`; operator contacts the person |
| A-22 | Audit: new `claim_reviewed` (Billing, subject `payment_claim`); redeem → `entitlement_changed` (`redeem`); operator reads → `admin_action` | Baseline: one writer per event; spec 009 precedent |
| A-23 | No new domain event; mails queued after commit | Spec 016 precedent; no listener needed |
| A-24 | Reminder once per subscription, 7 days before end, only if it governs and nothing ends later; profile language; configured renewal URL | Brief; avoid nagging someone covered by Play |
| A-25 | No acknowledgement mail on submission | Avoid turning the form into a mail cannon |
| A-26 | Idempotency built in Billing; anonymous scope for claims; only 2xx stored | Conventions; all three keyed operations are Billing's |
| A-27 | CLI approve/reject/revoke for an active operator id (no second factor at the shell) | C11 checkpoint; shell access is the stronger privilege |
| A-28 | Review screens' second factor: B12 must use `RequiresRecentFactor`; baseline sensitive acts gain the three commands | Approving grants paid access |
| A-29 | Mail language = claim's request language; reminder = profile language | P-14; claim has no account |
| A-30 | InstaPay payee address is landing-page configuration | No capability; no new endpoint |
| A-31 | No captcha, no EXIF stripping, no S3 | Each needs an approval; recorded as open questions |
| A-32 | `instapay` grace = `billing.grace_days` (3) | Spec 009 config already names B11 as the override point; no reason to differ |
| A-33 | An `in_progress` idempotency row older than 60 s may be taken over by a same-key, same-body retry | A crashed request must not lock its key for 24 h (checklist CHK008) |
| A-34 | `log`/`array` mailers refused outside `local`/`testing` (boot-time guard in Billing's provider) | The approval mail carries the code (CHK021) |
| A-35 | Trusted proxies and a ≥ 5 MB edge body limit are deployment prerequisites; CORS stays at the framework default | Per-origin budget needs the real IP; public endpoint carries no credential (CHK012, CHK030, CHK031) |
| A-36 | A redeemer's user id stays on the code record until the 400-day delete | Identifier only; no personal data (CHK033) |

## Review checklists (applied at the polish task)

- **Endpoint review checklist**: B (collections) n/a; C (syncable) n/a — POST operations with `Idempotency-Key` (G3, G4 documented); D n/a; E n/a; F1 Money yes; G5 throttled routes document 429; H1 claim `security: []` deliberately; H2 redeem 401/403/404 discipline (403 only for the caller's own address state); I1 one tag.
- **Security review checklist**: B5/B6 (claim uniform), D1 (new attributes in the protected table), E1–E4 (no receipt/code/address logged), F1–F6 (one entry per act, nothing excluded inside, operator distinguishable), G1 (every period a number in the retention table), H3 (operator acts through Billing's application service).

## Implementation notes

Recorded while building (owner away; each is the choice most consistent with spec, plan and research).

### Performance (T046, SC-007)

Opt-in `tests/Feature/Billing/InstapayPerformanceTest.php` (`INSTAPAY_PERF=1 XDEBUG_MODE=off dev exec -- vendor/bin/pest …`), 41 claim submissions with a 3 MiB receipt and 41 redemptions after one warm-up call each, in-process HTTP against the test database: **claim p95 350.8 ms** (target 800), **redeem p95 47.4 ms** (target 300).

### Defaults and deviations

| # | Decision | Why |
|---|---|---|
| N-1 | `BILLING_KEYS` is a **JSON map** `{kid: base64}`, like `ADMIN_KEYS` and `IDENTITY_KEYS`, not the `kid:base64,kid:base64` list the task text sketched | T004 says "parsed like `ADMIN_KEYS`"; one format for every keyring |
| N-2 | The 400 header refusals (`IDEMPOTENCY_KEY_REQUIRED`, `_INVALID`) are **pointer-less** problems | T007 asks for pointer-less; a response-level code cannot sit in `errors[]`, whose codes are field-level |
| N-3 | The idempotency store keeps and replays the answer as **raw JSON text** (`response_body` jsonb read back as text) | decoding to a PHP array turns `meta: {}` into `[]`, which the contract refuses. PostgreSQL normalises whitespace and key order; the replay is equal as JSON, not byte for byte |
| N-4 | Only a `2xx` answer is stored (as planned), so a repeated *refused* request runs again and, for redeem, spends budget; only successful replays are free | consequence of A-26; the contract tests assert both |
| N-5 | The route budgets are **helper classes** (`ClaimBudget`, `RedeemBudget`) hit in the controller after validation, like Sharing's `TokenBudget`, not `RateLimiter::for` definitions. A spent budget throws `ThrottleRequestsException`, which the runtime renders `429` with `Retry-After` and `RateLimit-*` | T021's "defined in `BillingHttpServiceProvider`" would add a named limiter nothing uses; the helper is the repo's established pattern |
| N-6 | Both claim budgets are checked before either is spent, so a refusal by one never costs the other | keeps the 6th-per-address refusal from burning the origin budget |
| N-7 | `ApiRequest::refusal()` and `unknownMembers()` became `protected` so `SubmitClaimRequest` can add its semantic faults (plan, price, address, image) to the same `VALIDATION_FAILED` | shared runtime, visibility only |
| N-8 | `ReceiptImage` also requires a PNG `IHDR` chunk and 1 to 30,000 pixels per side | `getimagesizefromstring` accepts a PNG signature followed by garbage; found by the unit test |
| N-9 | Review-command refusals (`ClaimNotReviewable`, `ReferenceAlreadyApproved`, `CodeNotRevocable`) carry `ErrorCode::VersionConflict` and a `detailKey` into `lang/{en,ar}/billing.php` | they have no HTTP route; the CLI prints the translated message. The code only matters if B12 ever renders them over HTTP |
| N-10 | `revokeCode` validates the reason (1 to 500 characters) but **does not store it** | the data model has no column for it and the audit entry must not hold free text. If B12 wants it kept, add a column then |
| N-11 | Claim fields: `plan_id` that is not a UUID is `INVALID_ENUM_VALUE`; any `currency` string other than the price's is `CURRENCY_MISMATCH` (not `INVALID_CURRENCY`); a `reference` of any other shape, however long, is `INVALID_TYPE` | the frozen contract names these codes; the three cases it does not spell out follow the nearest documented code |
| N-12 | `approve` marks the claim approved (inside a savepoint, mapping the unique violation to `ReferenceAlreadyApproved`) **before** inserting the code | cheaper failure; the whole transaction rolls back either way |
| N-13 | Mail texts are in the global `lang/{en,ar}/billing.php` as T029 says; the Blade mail views are the `billing::` namespace under `app/Modules/Billing/Http/Views` | matches the file locations in the task; Sharing's module-local lang is a different convention |
| N-14 | The redeem answer's entitlements come from a fresh `EntitlementService` instance, not the scoped one | nothing resolved earlier in the request may colour a result built after a write |
| N-15 | `ClaimService::approve/reject/revokeCode` return nothing; B12 reads the result through `ClaimQuery` | a command has no result worth coupling a screen to |
| N-16 | Concurrency tests (`ReviewClaimRaceTest`, `RedeemCodeRaceTest`) follow the lock-order tests of specs 011/012 (commit the test transaction, second connection, delete rows after) and `TRUNCATE audit_entries` in `afterEach` | the audit table refuses `DELETE` of young rows; `TRUNCATE` does not fire the row trigger and leaves no committed audit rows for other tests |
| N-17 | **Pre-existing Larastan errors fixed** in `Identity\…\ResolvesCaller` and `Sync\…\UserChangesController`: `$request->user()` became `$request->user('api')` where `currentAccessToken()` is called | with no config cache, Larastan resolves `Request::user()` to the union of every guard's model (`OperatorAccount|User`, since spec 018) and `OperatorAccount` has no `currentAccessToken()`. Behaviour is identical: the default guard is `api` |
| N-18 | `tests/Pest.php` gained an InstaPay section (image bytes, claim body and headers); Pint reformatted a few lines elsewhere in that file because it was dirty | `--dirty` formats whole files |
| N-19 | Locally, `.env` got a generated `BILLING_KEYS` (kid `b1`, git-ignored); `phpunit.xml` fixes a test key | T004 |

### Review checklists (T047)

**Endpoint review checklist** (`docs/api-conventions.md`), both operations: A1–A5 yes (envelope with `meta: {}`, `202` and `200`); B1–B6 yes (every problem `$ref`s `responses.yaml`; the three new codes added to catalogue, enum and lang in one change, parity test green); C n/a (POST operations, not syncable); D n/a; E n/a; F1 yes (`amount_minor` + `currency`), F2/F3 yes (`expires_at`, `current_period_*` UTC instants), F4/F5 yes (plan names localised); G1 yes, G2 yes, G3/G4 yes (`Idempotency-Key` required; `409 IDEMPOTENCY_KEY_REUSED` and `_IN_PROGRESS` documented and tested), G5 yes (`429` with `Retry-After` and `RateLimit-*` tested), G6 yes (426 tested); H1 claim `security: []` deliberately, H2 redeem 401/403/404 discipline (403 only for the caller's own address state), I1 one tag. The implementation matches `docs/openapi/paths/instapay.yaml` member for member (Spectator validates request and every documented status of both operations); **no deviation, the file is unchanged apart from the T002 header line**.

**Security review checklist** (`docs/security-baseline.md`): B5/B6 yes (claim: no account lookup on the path, tested with a mocked user repository; registered and unregistered compared); D1 yes (new attributes are in the protected-attributes table), D2 yes (address sealed, found only by lookup value), D3 yes, D4 yes (`v1.{kid}.` prefix on every sealed value), D5 yes (keys in the environment); E1–E4 yes (`RedactSecrets` gains the keys; `ClaimLoggingTest` sweeps a full run; mail jobs `ShouldBeEncrypted`; the `log`/`array` mailers refused outside local/testing), E5 yes (mismatch names no address); F1–F6 yes (one `claim_reviewed` per act, one `entitlement_changed` per redemption, one `admin_action` per operator read, all through Identity's `AuditLog`; nothing free-text inside), F7 yes (receipt route behind `EnsureOperatorSignedIn` + `signed`); G1 yes (every period is a number in the retention table); H3 yes (operators act through Billing's `ClaimService`).

**`checklists/security.md`** (34 items), evidence: CHK001–003 `SubmitClaimTest` (contract + feature); CHK004 `RedeemTest` mismatch (`detail` has no `@`); CHK005 `ClaimQuery::detail` is operator-only, no `/api/v1` route; CHK006–009 `IdempotencyTest`, `CanonicalJsonTest`; CHK010–011 `SubmitClaimTest`, `RedeemTest` (budgets after validation; replay free); CHK012 deployment prerequisite recorded in spec Assumptions (trusted proxies are not configured by this spec); CHK013–016 `ReceiptImageTest`, `ClaimReceiptRouteTest`; CHK017–018 `ClaimRetentionTest`, `ExpireCodesTest`, security baseline and privacy commitments; CHK019–023 `RedeemCodeFormatTest`, `RedeemCodeTest`, `ClaimLoggingTest`, `MailTransportGuardTest`, `RedeemCodeRaceTest`; CHK024–027 `ReviewClaimTest`, `ClaimReviewedDetailTest`, `ClaimCommandsTest` (CLI exception justified in A-27); CHK028–029 contract tests for every documented status, `RedeemResult` repeat shape; CHK030–032 spec Assumptions (CORS at the framework default, 5 MB edge limit, `web` only as defence in depth); CHK033–034 spec Assumptions and `ExpireCodesTest`/`ClaimRetentionTest`. All 34 are ticked in the checklist file by the implementing agent on this evidence; the owner may un-tick any.
