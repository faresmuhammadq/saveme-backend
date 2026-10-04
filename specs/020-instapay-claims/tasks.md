---

description: "Task list for Billing — InstaPay Claims (B11)"
---

# Tasks: Billing — InstaPay Claims

**Input**: Design documents from `/specs/020-instapay-claims/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/instapay-api.md](./contracts/instapay-api.md), [quickstart.md](./quickstart.md), [checklists/security.md](./checklists/security.md), the frozen contract `docs/openapi/paths/instapay.yaml` (written and indexed; `info.version` v1.12).

**Tests**: included. The spec's Invariants table names every test file (constitution P-16 rule 3). Write each story's tests first and watch them fail.

**Organization**: grouped by user story, in delivery order:

- US1: submit an InstaPay claim from the website (P1)
- US2: an operator approves or rejects a claim (P1)
- US3: redeem the code in the app (P1)
- US4: overlap with Google Play and early renewal (P2)
- US5: expiry, reminders and retention run on their own (P2)

**Conventions for every task** (as in specs 016–019):

- Run through the container: `dev exec -- vendor/bin/pest <path> --compact`, `dev <artisan command>`.
- P-02 layers: claim code lives in `app/Modules/Billing/{Domain,Application,Infrastructure,Http}`. Billing reaches other modules **only** through their Application layer: Identity `ClaimAddresses` (new, T008), `VerificationGate`, `AuditLog`; Admin `OperatorDirectory` (CLI only). Admin reaches Billing only through `ClaimReceipts` (T030). Billing Domain imports only `Shared`.
- Refusals extend `Shared\Domain\Errors\Refusal` with a catalogue `ErrorCode`: `RedeemCodeUnknown` → 404 `NOT_FOUND`; `RedeemCodeUsed` → 409 `REDEEM_CODE_USED`; `RedeemCodeExpired` → 410 `REDEEM_CODE_EXPIRED`; `RedeemCodeEmailMismatch` → 403 `REDEEM_CODE_EMAIL_MISMATCH`; Identity's `EmailNotVerified` → 403. Review-command refusals (`ClaimNotReviewable`, `ReferenceAlreadyApproved`, `CodeNotRevocable`, existing `PlanNotGrantable`, `InvalidReason`, new `InvalidRejectReason`) are domain exceptions with no HTTP route; the CLI prints their translated message and exits 1.
- **Lock order** (data-model.md): claim `FOR UPDATE` → plan read → code insert (review); code `FOR UPDATE` → claim `FOR UPDATE` → the person's subscriptions `FOR UPDATE` ascending id (redeem). Never a subscription lock before a claim or code lock.
- Never log a receipt, a code, an address or a request body of the two endpoints (P-15 rule 4, FR-040); ids at debug only. Instants with microseconds; empty `meta` is `(object) []`.
- Contract tests use `assertValidRequest()` and `assertValidResponse($status)` covering every documented status.
- Run `dev exec -- vendor/bin/pint --dirty --format agent` after PHP edits.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on unfinished tasks)
- **[Story]**: US1–US5 from spec.md

---

## Phase 1: Setup (docs, codes, schema)

- [X] T001 [P] Docs that bind the implementation (FR-041, FR-043). Write `docs/adr/0023-instapay-claims-and-claim-bound-redeem-codes.md` (Accepted, 2026-10-05, Principles P-09, P-10; Supersedes: ADR-0013 rule 12's word "authenticated" only) with the five rules of research R-18 and alternatives from R-1 and R-8 (authenticated claims; subscription at approval; extending an existing subscription); add its row and a "Billing" thread entry to `docs/adr/README.md`. `.specify/memory/constitution.md` → **v1.3.12 (PATCH)**: P-09 and P-10 `ADRs:` lines gain ADR-0023; Sync Impact Report entry ("PATCH: P-09 and P-10 list ADR-0023, introduced by spec 020; no rule changes"), version line, footer, Last Amended. `docs/security-baseline.md`: ownership table row "InstaPay claims, redeem codes, idempotency records — Billing"; Route budgets rows "InstaPay claim: 5 per hour per origin and 5 per 24 hours per address lookup value, registered or not (429)", "Redeem code: 10 per hour per person (429)"; Uniform answers row "InstaPay claim — `202` with the same members whether or not the address is registered; submission performs no account lookup"; What is protected at rest rows "Claim address: encrypted under Billing's keyring with the keyed lookup value beside it; erased 90 days after the claim closes; operators, with a recorded read", "Receipt image: encrypted under Billing's keyring, private disk; erased 90 days after the claim closes; operators through a 5-minute signed link, each view recorded", "Claim reference, amount, plan, status, reject reason: none at rest; operators", "Review note: none at rest; operators only; never mailed or audited", "Redeem code: hash; nobody after issue", "Idempotency record: none at rest, holds the answer only (no secret); nobody through any endpoint"; Retention rows "Receipt image and claim address — 90 days after the claim closes", "Payment claim and redeem code records — 400 days after the claim closes", "Unreviewed claim — expires after 7 days", "Redeem code — valid 30 days"; What is recorded: Billing group gains `claim_reviewed`, `subject_type` enum gains `payment_claim`, and a sentence that operator reads of a claim address or receipt are `admin_action` entries written by Admin; Operator access sensitive acts gain "approving or rejecting a payment claim, revoking a redeem code"; Messages from the payment provider unchanged. `docs/privacy-commitments.md` (en and ar): "If you pay by InstaPay, we keep your transfer screenshot and the email address on your claim only for review, and delete them 90 days after the claim is closed — rejected, expired, or approved and its code used or expired"; and the never-logged line already covers receipts. `docs/api-conventions.md` Idempotency: "A public operation (InstaPay claim submission) keys in one anonymous scope; only successful answers are stored; a request still in progress after 60 seconds is treated as abandoned." `specs/briefs/README.md`: row B11 notes "Specified as spec 020"; `specs/briefs/B12-back-office-operations.md` gains an "Inherited from B11" note (screens call `ClaimService::approve/reject/revokeCode` behind `RequiresRecentFactor`; detail via `ClaimQuery::detail`, recording `admin_action` `claim_address_read`; receipt link via `ClaimReceipts::temporaryUrl`; duplicates list; reject reasons fixed); `specs/briefs/B15-export-data-deletion.md` gains "Inherited from B11" (a redeemer's id stays on `redeem_codes` until the 400-day delete; claims hold no user id).
- [X] T002 [P] Add the three response codes **in one change** (the parity tests need all of these together; spec 012 T002 pattern): in `docs/openapi/error-catalog.yaml` add to `ErrorCode` enum and to `x-error-catalog` — `REDEEM_CODE_EMAIL_MISMATCH` (`status: 403`, `level: [response]`, `title: 'Redeem code for another address'`, `meaning: 'The code was sent to a different address than this account''s. Sign in with the address the code was sent to; the address is never named.'`, `retryable: after-change`, `since: v1.12`), `REDEEM_CODE_USED` (`status: 409`, `title: 'Redeem code already used'`, `meaning: 'Another account has already redeemed this code.'`, `retryable: never`, `since: v1.12`), `REDEEM_CODE_EXPIRED` (`status: 410`, `title: 'Redeem code expired'`, `meaning: 'The code is past its 30 days. Contact support.'`, `retryable: never`, `since: v1.12`); add the three cases to `app/Modules/Shared/Domain/Errors/ErrorCode.php` with status, title and retry class; add detail sentences to `lang/en/problems.php` and `lang/ar/problems.php` (mismatch: "Sign in with the email address this code was sent to." / "سجّل الدخول بعنوان البريد الإلكتروني الذي أُرسل إليه هذا الرمز."); list them in `docs/openapi/components/responses.yaml` descriptions (`Forbidden`: REDEEM_CODE_EMAIL_MISMATCH; `Conflict`: REDEEM_CODE_USED; `Gone`: REDEEM_CODE_EXPIRED; `NotFound` gains "an unknown or revoked redeem code"); in `docs/openapi/paths/instapay.yaml` replace the header paragraph "New in v1.12, added to error-catalog.yaml by the implementation (spec 020 task T002): …" with one line "Codes REDEEM_CODE_EMAIL_MISMATCH, REDEEM_CODE_USED and REDEEM_CODE_EXPIRED are new in v1.12." Run `dev exec -- vendor/bin/pest tests/Contract/OpenApi --compact` — green.
- [X] T003 [P] Glossary, before any code uses the terms (P-01). `docs/glossary.md`: **Payment Claim** — states `submitted` → `approved` | `rejected` | `expired` (remove `under_review`); Referenced by: Redeem Code; notes: public, web-only submission with a uniform answer (ADR-0023), exact InstaPay price, normalised reference, duplicates flagged and approved at most once, address encrypted with lookup value, receipt encrypted and operator-only, 7-day expiry, address and receipt erased 90 days after closing, record deleted at 400 days. **Redeem Code** — format (16 Crockford base32, `XXXX-XXXX-XXXX-XXXX`), hash only, 30 days, bound to the claim's address and redeemable by a verified account holding it, a claim-backed code creates an `instapay` subscription at redemption (ADR-0023; `manual` only for codes without a claim, none in MVP). **Subscription** notes: `instapay` billing periods chain on early renewal; one renewal reminder 7 days before the end. New **Idempotency Record** (Plans & Billing; operational; persisted; Sync: no; single-state; 24 hours; notes: the stored answer for one `Idempotency-Key`, scope user or anonymous). Update the entity index and counts.
- [X] T004 [P] Configuration (research R-15): `config/billing.php` gains `instapay` (`claim_days` 7, `code_days` 30, `reminder_days` 7, `receipt_max_bytes` 3145728, `receipt_link_minutes` 5, `erase_after_days` 90, `delete_after_days` 400, `renew_url` env `BILLING_INSTAPAY_RENEW_URL` default `http://saveme-backend.test/renew`, `support_contact` env `BILLING_SUPPORT_CONTACT` default `support@example.com`, `throttle.claims_per_hour_per_origin` 5, `throttle.claims_per_day_per_address` 5, `throttle.redeem_per_hour` 10), `idempotency_hours` 24, `keys` (env `BILLING_KEYS`, `kid:base64,kid:base64` parsed like `ADMIN_KEYS`), `active_key` (env `BILLING_ACTIVE_KEY`), with a header comment for each. `.env.example` lists the four new env names (keys empty); the local `.env` gets a generated key; `phpunit.xml` fixes a test key and `BILLING_ACTIVE_KEY`.
- [X] T005 Migrations per data-model.md, in `app/Modules/Billing/Infrastructure/Database/Migrations/`: `2026_10_09_000001_create_payment_claims_table.php` (every column, CHECK and index of the `payment_claims` table verbatim: `reference varchar(32)` CHECK `reference ~ '^[A-Z0-9]{8,32}$'`; `locale` CHECK in (`en`,`ar`); `receipt_media_type` CHECK in (`image/jpeg`,`image/png`,`image/webp`); `receipt_bytes` CHECK 1–3145728; `status` CHECK in (`submitted`,`approved`,`rejected`,`expired`); `reject_reason` CHECK in (`reference_not_found`,`amount_mismatch`,`duplicate`,`other`); `review_note` CHECK `char_length(review_note) <= 500`; `amount_minor bigint` CHECK `>= 0`; the reviewed/rejected/closed/receipt/erased CHECKs; UNIQUE `payment_claims_one_approved_reference ON (reference) WHERE status = 'approved'`; indexes `(reference)`, `(status, created_at, id)`, `(expires_at) WHERE status = 'submitted'`, `(closed_at) WHERE closed_at IS NOT NULL`); `2026_10_09_000002_create_redeem_codes_table.php` (`claim_id` UNIQUE FK ON DELETE CASCADE, `code_hash char(64)` UNIQUE, status CHECK in (`issued`,`redeemed`,`revoked`,`expired`), the redeemed/revoked CHECKs, `subscription_id uuid NULL` with no FK (B15 purge must not be blocked), index `(expires_at) WHERE status = 'issued'`); `2026_10_09_000003_create_idempotency_records_table.php` (UNIQUE `(scope, key)`, `state` CHECK in (`in_progress`,`completed`), completed CHECK, index `(expires_at)`); `2026_10_09_000004_add_renewal_reminded_at_to_subscriptions.php` (`timestampTz('renewal_reminded_at', 6)->nullable()`). Identity migration `app/Modules/Identity/Infrastructure/Database/Migrations/2026_10_09_000000_allow_payment_claim_audit_subject.php`: widen `audit_entries_subject_type_check` with `payment_claim` and `audit_entries_event_check` with `claim_reviewed` (down restores both), as spec 018's migration does. Models `Infrastructure/Models/{PaymentClaim,RedeemCode,IdempotencyRecord}.php` and factories `Infrastructure/Database/Factories/{PaymentClaimFactory,RedeemCodeFactory}.php` (states `approved`, `rejected`, `expired`, `withReceipt`, `closedDaysAgo(int)`, code `redeemed`, `revoked`, `expiredCode`). Run `dev migrate` and `dev migrate:rollback --step=5` then `dev migrate`.

---

## Phase 2: Foundational (blocking)

### Tests

- [X] T006 [P] `tests/Unit/Billing/Idempotency/CanonicalJsonTest.php`: keys sorted recursively, lists keep order, no whitespace, unicode unescaped, numbers as given; two bodies differing only in key order or spacing give the same fingerprint; any value change gives another; method and path are part of the fingerprint.
- [X] T007 [P] `tests/Feature/Billing/Idempotency/IdempotencyTest.php` (FR-002, research R-2), through a test-only route using the `Idempotent` middleware: key absent → 400 `IDEMPOTENCY_KEY_REQUIRED` (pointer-less problem); malformed (15 chars, 256 chars, a space) → 400 `IDEMPOTENCY_KEY_INVALID`; first 2xx stored and replayed verbatim with `Idempotency-Replayed: true`, handler run once; same key different body → 409 `IDEMPOTENCY_KEY_REUSED`; concurrent original (row `in_progress` < 60 s) → 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` with `Retry-After: 1`; `in_progress` older than 60 s with the same fingerprint is taken over; a 422 or 429 answer stores nothing and a corrected retry with the same key succeeds; scopes: the same key under two users is two records, unauthenticated requests share `anonymous`; `billing:purge-idempotency-records` deletes rows past `expires_at` only.

### Implementation

- [X] T008 [P] Identity Application `app/Modules/Identity/Application/Billing/ClaimAddresses.php` (research R-6): `normaliseAndLookup(string): array{address: string, lookup: string}` (throws `InvalidArgumentException` on an invalid address, same `EmailAddress` normalisation as registration), `accountFor(string $lookup): ?string` (null for none or purged), `lookupOfUser(string $userId): string`, `isVerified(string $userId): bool`, `addressAndLocaleOf(string $userId): ?array{address: string, locale: string}` (null when purged; opens the address with the user's key). Feature test `tests/Feature/Identity/ClaimAddressesTest.php` (lookup equals the account's for differently cased/padded input; purged → null).
- [X] T009 [P] Identity audit additions: `AuditEvent::ClaimReviewed = 'claim_reviewed'`, `SubjectType::PaymentClaim = 'payment_claim'`; `Application/Audit/Details/ClaimReviewedDetail.php` (`outcome` ∈ `approved|rejected|code_revoked`, `reason` the reject reason only when rejected, else omitted; nothing else); `EntitlementChangedDetail` docblock action union gains `'redeem'`. Admin: `AdminAction` gains `ClaimReceiptViewed = 'claim_receipt_viewed'`, `ClaimAddressRead = 'claim_address_read'`; `AdminAuditService` gains `claimReadByOperator(string $operatorId, string $claimId, AdminAction $action): void` writing `admin_action` with subject `payment_claim` (the existing operator-subject methods stay unchanged). Unit test `tests/Unit/Identity/ClaimReviewedDetailTest.php` (shapes for the three outcomes; no note, no free text).
- [X] T010 [P] Billing crypto `app/Modules/Billing/Infrastructure/Crypto/{BillingKeyring,BillingSealer}.php` (copy of spec 018's `AdminKeyring`/`AdminSealer`: XChaCha20-Poly1305, `v1.{kid}.{base64}`, associated data `payment_claim:{id}:{field}`, config `billing.keys`/`billing.active_key`), behind Domain port `Domain/Ports/ClaimSealer.php` (`seal(string $plaintext, string $claimId, string $field): string`, `open(...)`); bind in `BillingPersistenceServiceProvider`. Unit test `tests/Unit/Billing/Crypto/BillingSealerTest.php` (round trip; wrong claim id or field fails; unknown kid fails; old kid still opens after rotation).
- [X] T011 Idempotency (research R-2): Domain `Domain/Idempotency/CanonicalJson.php` (pure) and port `Domain/Ports/IdempotencyStore.php` (`begin(scope, key, fingerprint, now): IdempotencyOutcome` — `Started` | `Replay(status, body)` | `Reused` | `InProgress` with the 60-second takeover; `complete(scope, key, status, body)`; `abandon(scope, key)`; `purgeExpired(now): int`); Infrastructure `Repositories/EloquentIdempotencyStore.php` (`INSERT … ON CONFLICT (scope, key) DO NOTHING`, conditional takeover `UPDATE`); Application `Idempotency/PurgeIdempotencyRecords.php`; Http `Middleware/Idempotent.php` (validates the header → `ApiRefusal` 400 codes with pointer `/$header/Idempotency-Key`; scope `user:<id>` or `anonymous`; replays with `Idempotency-Replayed: true`; stores 2xx JSON; abandons otherwise, including on exception) registered as alias `billing.idempotent`; command `Http/Console/PurgeIdempotencyRecords.php` (`billing:purge-idempotency-records`). Make T006–T007 pass.
- [X] T012 [P] Logging and mail guard (FR-040, A-34): `app/Http/Api/Logging/RedactSecrets.php` `SENSITIVE_KEY` gains `screenshot|receipt|redeem_code|address_sealed`; extend its existing unit test. In `BillingServiceProvider::boot`, throw a `RuntimeException` when `config('mail.default')` is `log` or `array` and the environment is neither `local` nor `testing` (unit/feature test with a swapped environment).

**Checkpoint**: idempotency, sealer, Identity and Admin hooks ready — stories can start.

---

## Phase 3: User Story 1 — Submit an InstaPay claim from the website (P1) 🎯 MVP

**Goal**: a web visitor submits a claim and gets a uniform `202`; the claim is stored sealed.

**Independent test**: spec US1 Independent Test.

### Tests

- [X] T013 [P] [US1] `tests/Unit/Billing/Claims/ReferenceTest.php`: `ClaimReference::normalise` strips spaces and hyphens, upper-cases; accepts 8 and 32, refuses 7 and 33 and any non `A–Z0–9` after normalisation (`'4815 1623 4211'` → `481516234211`).
- [X] T014 [P] [US1] `tests/Unit/Billing/Claims/ClaimPriceRuleTest.php`: a plan with an `instapay` price accepts exactly that amount and currency; amount ±1 refused (`/amount_minor` `OUT_OF_RANGE`); other currency refused (`/currency` `CURRENCY_MISMATCH`); a plan without an `instapay` price, a retired plan, the default plan, an unknown id → `/plan_id` `INVALID_ENUM_VALUE`.
- [X] T015 [P] [US1] `tests/Unit/Billing/Claims/ReceiptImageTest.php`: `ReceiptImage::from(mediaType, base64)` — valid 1×1 PNG/JPEG/WebP accepted; non-base64 or base64 with line breaks → `INVALID_TYPE`; declared PNG but JPEG bytes → `INVALID_TYPE`; bytes with a signature but not parseable → `INVALID_TYPE`; 3,145,728 decoded bytes accepted, 3,145,729 → `OUT_OF_RANGE`; media type outside the three → `INVALID_ENUM_VALUE`.
- [X] T016 [P] [US1] `tests/Feature/Billing/Claims/SubmitClaimTest.php` (FR-003–FR-013): `ClaimService::submit` stores `submitted`, `expires_at = now + 7 days`, normalised reference, `locale` from the app locale, `address_sealed` that opens to the normalised address with the claim id and field, `address_lookup` equal to `ClaimAddresses::normaliseAndLookup`; with a receipt the file at `billing/claims/{id}/receipt` holds sealed bytes (not the image) and columns are set; a failed insert leaves no file; a mock of the Identity `UserRepository` asserts no user read happens during submission (FR-008); two claims with one reference both stored and `ClaimQuery::duplicatesOf` lists each other.
- [X] T017 [P] [US1] `tests/Contract/Billing/SubmitClaimTest.php`: 202 schema and examples; registered vs unregistered address → same status and same set of members (keys of `data` equal, `meta` `{}`), no `email` in the body; `X-Client-Platform: android` → 404; each 422 pointer and field code of the operation description; unknown member → `UNKNOWN_FIELD`; 400 key missing/invalid; 409 reused key; replay header; 6th valid submission per origin per hour → 429 with `Retry-After` and `RateLimit-*`; 6th per address per 24 h from different origins → 429; a 422 does not spend the budget; 406, 415, 426 shared refusals.

### Implementation

- [X] T018 [P] [US1] Billing Domain `Domain/Claims/`: `ClaimStatus` enum (`Submitted`, `Approved`, `Rejected`, `Expired`), `RejectReason` enum (four values), `ClaimReference` (normalise + validate), `ClaimPrice` (pure rule over a `PlanRecord`'s prices: finds the `instapay` price, compares `Money`), `ReceiptMediaType` enum, `ReceiptImage` (strict base64, signature, `getimagesizefromstring`, size), `ClaimRecord` (readonly view), `PaymentClaim` (state rules: `assertReviewable(now)`, `assertCodeRevocable(codeStatus)`). Ports `Domain/Ports/ClaimRepository.php` (`insert`, `lockForUpdate`, `find`, `duplicatesOf(reference, exceptId)`, `markApproved/markRejected/markExpired/close`, `queue(cursor, limit)`, `expireDue(now, limit)`, `eraseClosedBefore(cutoff)`, `deleteClosedBefore(cutoff)`), `Domain/Ports/ReceiptStore.php` (`put(claimId, sealedBytes): path`, `get(path)`, `delete(path)`). Make T013–T015 pass.
- [X] T019 [US1] Billing Infrastructure `Repositories/EloquentClaimRepository.php`, `Repositories/SealedReceiptStore.php` (`Storage::disk('local')`, path `billing/claims/{id}/receipt`, deletes the directory on erase); bind in `BillingPersistenceServiceProvider`.
- [X] T020 [US1] Billing Application `Application/Claims/ClaimService.php::submit(SubmitClaim $dto): ClaimRecord` (research R-1, design note 2: one transaction; seal address; seal and store receipt; cleanup on rollback; no Identity user read) and `Application/Claims/ClaimQuery.php` (`duplicatesOf`; `queue` and `detail` added in US2). Make T016 pass.
- [X] T021 [US1] Billing Http: `Http/Middleware/ClaimsAreWebOnly.php` (alias `billing.web-only`; non-`web` platform → `ApiRefusal(ErrorCode::NotFound)`); `Http/Requests/SubmitClaimRequest.php` (`ApiRequest`; rules per plan design note 1, field codes per spec FR-004–FR-007 and FR-012; `email` `string|max:254` plus a closure rule calling `ClaimAddresses::normaliseAndLookup` → `INVALID_TYPE`); rate limiters `billing-claim-origin` (5/h by IP) and `billing-claim-address` (5/day by lookup value) defined in `BillingHttpServiceProvider` and **hit in the controller after validation** (research R-12; spent → `ThrottleRequestsException` with `Retry-After`); `Http/Controllers/InstapayClaimController.php` → `ClaimService::submit` → `Http/Resources/ClaimReceiptResource.php` (`id`, `status`, `plan` as `PlanRef` in `Content-Language`, `amount`, `expires_at`) with status 202 and `meta` `(object) []`; route in `Http/routes.php`: `Route::post('billing/instapay/claims', …)->middleware(['billing.web-only', 'billing.idempotent'])->name('billing.instapay.claims')`, no `auth:api`. Make T017 pass.

**Checkpoint**: US1 deliverable — the landing page can submit claims.

---

## Phase 4: User Story 2 — An operator approves or rejects a claim (P1)

**Goal**: operator-only domain commands with mail and audit; CLI for the C11 checkpoint; receipt viewing.

**Independent test**: spec US2 Independent Test.

### Tests

- [X] T022 [P] [US2] `tests/Unit/Billing/RedeemCodes/RedeemCodeFormatTest.php`: `RedeemCode::generate()` is 16 chars of `0123456789ABCDEFGHJKMNPQRSTVWXYZ` (1,000 samples, no `I L O U`); `display()` is `XXXX-XXXX-XXXX-XXXX`; `normalise` accepts lower case, hyphens, spaces, folds `O`→`0`, `I`/`L`→`1`, refuses 15 or 17 characters and `U`; `hash()` is hex SHA-256 of the normalised form and equal for every accepted spelling.
- [X] T023 [P] [US2] `tests/Feature/Billing/Claims/ReviewClaimTest.php` (FR-015–FR-021, FR-024, FR-036): approve → `approved`, `reviewed_by/at`, one code row (`issued`, `expires_at = now + 30 days`, hash only; no column anywhere holds the plaintext), one `claim_reviewed` entry (actor operator, subject `payment_claim`, detail `{outcome: approved}`), `SendClaimApprovedMail` queued after commit (not on rollback) and `ShouldBeEncrypted`; reject with each reason and a note → `rejected`, `closed_at` set, entry `{outcome: rejected, reason}` without the note, rejection mail queued; refused and unchanged: second approve/reject, expired status, `submitted` past `expires_at`, plan retired or without an InstaPay price (`PlanNotGrantable`), reference already approved on another claim (`ReferenceAlreadyApproved`, also when two approvals race on two connections), reject reason outside the list, note over 500; revoke code → code `revoked`, claim `closed_at`, entry `{outcome: code_revoked}` (no free text), no mail; revoke of a redeemed or expired code → `CodeNotRevocable`; two concurrent approvals of one claim → exactly one succeeds.
- [X] T024 [P] [US2] `tests/Feature/Billing/Claims/ClaimMailTest.php`: approved mail (en and ar by claim locale) contains the grouped code, plan name, "Enter code" instructions, "sign in or create an account with this address", the expiry date, the support contact, `config('app.name')`; rejected mail states the localised reason and never the note; expired notice names the reference's last four characters only; an erased claim sends nothing; Mailpit-free (`Mail::fake`).
- [X] T025 [P] [US2] `tests/Feature/Billing/Claims/ClaimCommandsTest.php` (FR-025): `billing:claim:list` prints submitted claims (id, plan, amount, reference, created, duplicate count) and never an address; `billing:claim:approve {claim} --operator=` approves for an active operator and prints nothing secret (the code goes by mail only); unknown or deactivated operator → exit 1, nothing changed; `billing:claim:reject … --reason= --note=` and `billing:claim:revoke-code … --reason=` work and refuse like the service.
- [X] T026 [P] [US2] `tests/Feature/Admin/ClaimReceiptRouteTest.php` (FR-014, FR-014a): `ClaimReceipts::temporaryUrl` signed for 5 minutes; a signed-in operator gets the decrypted image with `Content-Type` as stored, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'`, and one `admin_action` `claim_receipt_viewed` entry; expired or tampered signature → 403; no operator session → redirect to sign-in; an application bearer token → refused; erased claim or no receipt → 404.

### Implementation

- [X] T027 [P] [US2] Billing Domain `Domain/RedeemCodes/{RedeemCode,RedeemCodeStatus,RedeemCodeRecord}.php` and port `Domain/Ports/RedeemCodeRepository.php` (`insert`, `lockByHash`, `lockForClaim`, `markRedeemed`, `markRevoked`, `expireDue(now)`); refusals `Domain/Refusals/{ClaimNotReviewable,ReferenceAlreadyApproved,CodeNotRevocable,InvalidRejectReason}.php` with en/ar messages in `lang/{en,ar}/billing.php`. Infrastructure `Repositories/EloquentRedeemCodeRepository.php`; bind. Make T022 pass.
- [X] T028 [US2] `ClaimService::approve(operatorId, claimId)`, `reject(operatorId, claimId, reason, ?note)`, `revokeCode(operatorId, claimId, reason)` (research R-9, plan design note 3; savepoint around the approved update maps the unique violation to `ReferenceAlreadyApproved`; reason for revoke validated 1–500 via `InvalidReason`; audit via Identity `AuditLog` with `ClaimReviewedDetail`); `ClaimQuery::queue(cursor, limit)` (submitted first by `created_at, id`, with duplicate counts, no address) and `ClaimQuery::detail(claimId)` (opened address, `account_exists` via `ClaimAddresses::accountFor`, receipt presence, duplicates, review facts; docblock: callers must record `admin_action` `claim_address_read`). Make T023 pass.
- [X] T029 [US2] Mail (research R-13): jobs `Application/Jobs/{SendClaimApprovedMail,SendClaimRejectedMail,SendClaimExpiredMail}.php` (`ShouldQueue`, `ShouldBeEncrypted`, dispatched `->afterCommit()`, open the sealed address at send time, skip erased claims, locale from the claim) and mailables `Application/Mail/{ClaimApprovedMail,ClaimRejectedMail,ClaimExpiredMail}.php` with Blade views registered from the Billing module and texts in `lang/{en,ar}/billing.php`. Make T024 pass.
- [X] T030 [US2] Receipt viewing (research R-10): Billing Application `Application/Claims/ClaimReceipts.php` (`temporaryUrl(claimId): ?string` via `URL::temporarySignedRoute('admin.claims.receipt', now()->addMinutes(config('billing.instapay.receipt_link_minutes')), …)`, `open(claimId): ?array{media_type: string, bytes: string}`); Admin `Http/Controllers/ClaimReceiptController.php` and route `GET /admin/claims/{claim}/receipt` named `admin.claims.receipt` in `app/Modules/Admin/Http/routes.php` inside the operator-signed-in group with the `signed` middleware; records `ClaimReceiptViewed` before streaming; headers per T026. Make T026 pass; keep `tests/Arch` green (controller in Admin Http, calls only Billing Application).
- [X] T031 [US2] CLI `app/Modules/Billing/Http/Console/{ListClaims,ApproveClaim,RejectClaim,RevokeClaimCode}.php` (`billing:claim:list`, `billing:claim:approve {claim} {--operator=}`, `billing:claim:reject {claim} {--operator=} {--reason=} {--note=}`, `billing:claim:revoke-code {claim} {--operator=} {--reason=}`); operator checked through Admin's `OperatorDirectory::view` (`state === 'active'`); refusals print the translated message, exit 1. Make T025 pass.

**Checkpoint**: claims can be reviewed from the command line; B12 has its commands.

---

## Phase 5: User Story 3 — Redeem the code in the app (P1)

**Goal**: `POST /billing/redeem` creates the `instapay` subscription for the right account.

**Independent test**: spec US3 Independent Test.

### Tests

- [X] T032 [P] [US3] `tests/Unit/Billing/RedeemCodes/RedeemedPeriodTest.php` (FR-029): monthly from Jan 31 → Feb 28 (and Feb 29 in a leap year); yearly from Feb 29 → Feb 28; custom 45 days; lifetime → null end; chaining: start = later of now and the latest dated end of granting `instapay` subscriptions; ignores Play, manual, lifetime-instapay (null end → no chaining beyond, start now) and ended ones.
- [X] T033 [P] [US3] `tests/Feature/Billing/Redeem/RedeemCodeTest.php` (FR-026–FR-032, FR-036, FR-037): success creates one subscription (`instapay`, `provider_ref` = claim id, active, `auto_renew` false, plan from the claim), code `redeemed` with user and subscription, claim `closed_at`, one `entitlement_changed` `{action: redeem, plan_id}` with actor user, `SubscriptionActivated` after commit; loose spelling accepted; unknown and revoked → `RedeemCodeUnknown`; same account again → same result, no second audit or event; other account (same address impossible — use an account with another address after redemption) → `RedeemCodeUsed` before mismatch; expired (`expires_at` passed, sweep not run) → `RedeemCodeExpired`, nothing created; other address → `RedeemCodeEmailMismatch`; right address unverified → `EmailNotVerified`; two concurrent redemptions by the same account on two connections → one subscription; the unique `(provider, provider_ref)` holds as the last guard.
- [X] T034 [P] [US3] `tests/Contract/Billing/RedeemTest.php`: 200 schema and both examples' shapes (`subscription`, `governing`, `entitlements` identical to `/me/subscription` and `/me/entitlements` answered right after); 401; 400 key missing/invalid; 409 key reused; replay with `Idempotency-Replayed: true`; 422 `/code` `INVALID_TYPE` and `UNKNOWN_FIELD`; 404 unknown; 409 `REDEEM_CODE_USED`; 410 `REDEEM_CODE_EXPIRED`; 403 `REDEEM_CODE_EMAIL_MISMATCH` whose `detail` contains no `@`; 403 `EMAIL_NOT_VERIFIED`; 11th attempt in an hour → 429 (a 422 does not count; a replay does not count); works with `X-Client-Platform` `android` and `web`.

### Implementation

- [X] T035 [P] [US3] Billing Domain `Domain/RedeemCodes/RedeemedPeriod.php` (pure; research R-8) and refusals `Domain/Refusals/{RedeemCodeUnknown,RedeemCodeUsed,RedeemCodeExpired,RedeemCodeEmailMismatch}.php` mapped to their catalogue codes. `SubscriptionRepository` gains `lockForUser(userId): list<SubscriptionRecord>` (ascending id, `FOR UPDATE`) and `findByProviderRef(provider, ref)`. Make T032 pass.
- [X] T036 [US3] Billing Application `Application/RedeemCodes/RedeemCodes.php::redeem(userId, rawCode): RedeemResult` (research R-8, FR-026 order, lock order) and DTO `Application/RedeemCodes/RedeemResult.php` built from the created (or earlier) subscription, `MySubscription` and `EntitlementService::forUser(...)->summary()`. Make T033 pass.
- [X] T037 [US3] Billing Http `Http/Requests/RedeemCodeRequest.php` (`code` `required|string|max:64` plus the `RedeemCode::normalise` rule → `/code` `INVALID_TYPE`); limiter `billing-redeem` (10/h by user id) hit in the controller after validation; `Http/Controllers/RedeemController.php`; `Http/Resources/RedeemResultResource.php` (reuses the existing `MySubscription` and entitlement resources so shapes cannot drift); route `Route::post('billing/redeem', …)->middleware(['auth:api', 'billing.idempotent'])->name('billing.redeem')` — no `address.verified` middleware (the service checks verification after the address match, FR-026 order). Make T034 pass.

**Checkpoint**: the C11 integration checkpoint can run (quickstart steps 1–3).

---

## Phase 6: User Story 4 — Overlap with Google Play and early renewal (P2)

**Goal**: deterministic governing subscription; no lost days on early renewal.

**Independent test**: spec US4 Independent Test.

- [X] T038 [P] [US4] `tests/Feature/Billing/Redeem/RedeemOverlapTest.php` (FR-029, FR-030, ADR-0013 rule 14): Play monthly ending in 20 days + yearly code → `instapay` governs, Play row byte-for-byte unchanged, `governing.provider = instapay`; Play subscription ending later than the redeemed period → Play governs and `governing.provider = google_play`; Play lifetime → Play keeps governing; granting `instapay` ending in 5 days → new period starts at that end; manual or Play ending in 5 days → new period starts now; an `instapay` subscription in `grace` still chains from its `current_period_end` only if it still grants (grace not passed); after the lapse job, entitlements still follow the governing rule.
- [X] T039 [US4] Adjust `RedeemCodes` / `RedeemedPeriod` for anything T038 reveals; no change to `GoverningSubscription` (spec 009 rule stands). Make T038 pass.

---

## Phase 7: User Story 5 — Expiry, reminders and retention (P2)

**Goal**: the scheduled runs.

**Independent test**: spec US5 Independent Test.

### Tests

- [X] T040 [P] [US5] `tests/Feature/Billing/Claims/ExpireClaimsTest.php` (FR-016): submitted claims past `expires_at` → `expired`, `closed_at` set, one `SendClaimExpiredMail` each; claims not yet due and reviewed claims untouched; a second run changes nothing and queues nothing; batches of 200 (250 claims → all expired).
- [X] T041 [P] [US5] `tests/Feature/Billing/Redeem/ExpireCodesTest.php` (FR-022a): `issued` codes past `expires_at` → `expired` and their claims `closed_at`; redeemed and revoked untouched; idempotent.
- [X] T042 [P] [US5] `tests/Feature/Billing/Reminders/RenewalReminderTest.php` (FR-034, FR-035): governing `instapay` ending in 6 days → one `SendRenewalReminderMail`, `renewal_reminded_at` set, second run nothing; ending in 8 days → nothing; ended → nothing; Play, manual, lifetime → nothing; `instapay` not governing (a later Play end) → nothing; purged account → no mail sent by the job; mail in the profile language with `billing.instapay.renew_url`, plan name and end date.
- [X] T043 [P] [US5] `tests/Feature/Billing/Claims/ClaimRetentionTest.php` (FR-039): claims closed 89 days ago untouched; 90 days → `address_sealed`, `address_lookup`, receipt columns null, file and directory gone, `erased_at` set, other facts intact; approved claim with an unredeemed issued code is not closed and not erased; 400 days → claim and code rows deleted; idempotent.
- [X] T044 [P] [US5] `tests/Feature/Billing/Claims/ClaimLoggingTest.php` (FR-040, SC-005): with a log spy, submit a claim with a receipt, approve it (run the mail job with `Mail::fake`), redeem the code, and run every scheduled command; no captured record (message, context, extra) contains the base64 data, the plaintext code in any spelling, or the address; a failing job's serialized payload in `jobs`/`failed_jobs` is encrypted.

### Implementation

- [X] T045 [US5] Application `Application/Claims/ExpireClaims.php`, `Application/RedeemCodes/ExpireRedeemCodes.php`, `Application/Claims/PurgeClaims.php` (erase then delete, research R-11), `Application/Reminders/SendRenewalReminders.php` and job `Application/Jobs/SendRenewalReminderMail.php` + mailable `Application/Mail/RenewalReminderMail.php` (address and locale via `ClaimAddresses::addressAndLocaleOf`); `SubscriptionRepository` gains `dueForReminder(now, days)` and `markReminded(id, at): bool` (conditional). Commands `Http/Console/{ExpireClaims,ExpireRedeemCodes,SendRenewalReminders,PurgeClaims}.php` (`billing:expire-claims`, `billing:expire-redeem-codes`, `billing:send-renewal-reminders`, `billing:purge-claims`); schedule in `routes/console.php`: expire-claims hourly, purge-idempotency-records hourly, expire-redeem-codes daily, send-renewal-reminders daily, purge-claims daily, each `->withoutOverlapping()`. Make T040–T044 pass.

**Checkpoint**: every story complete.

---

## Phase 8: Polish & cross-cutting

- [X] T046 [P] Performance (SC-007), opt-in `tests/Feature/Billing/InstapayPerformanceTest.php` (`INSTAPAY_PERF=1`, Xdebug off): 40 claim submissions with a 3 MiB receipt and 40 redemptions after warm-up; record p95 (targets 800 ms / 300 ms) in plan.md "Implementation notes".
- [X] T047 Apply the review checklists of plan.md (endpoint review checklist for both operations; security review checklist; `checklists/security.md` items with the reviewer's evidence) and record the result and any exception in plan.md "Implementation notes". Confirm the implementation matches `docs/openapi/paths/instapay.yaml` member for member; any deviation is a contract change → stop and record it.
- [X] T048 Gates: `dev exec -- composer test:lint`, `test:types`, `test:arch`, `test:suite`, `test:api` (or `composer test`), `dev exec -- vendor/bin/pest tests/Contract/OpenApi --compact`; walk quickstart.md steps 1–5 on the dev stack (Mailpit for the code). State "Seeder: N/A — no reference data (InstaPay prices already seeded by spec 009)" in the commit description.
- [X] T049 Apidog sync: bundle with `npx @redocly/cli bundle docs/openapi/openapi.yaml -o <scratchpad>/bundle.json`; import with `apidog import --project 1332822 --format openapi --file <scratchpad>/bundle.json --module-map "Personal finance backend API=1363204"`; create or update the project doc **"InstaPay claims & redeem"** with contracts/instapay-api.md "What C11 binds to" and "What the landing page binds to" (web-only claim, uniform answer, base64 receipt ≤ 3 MiB, code format and folding, refusal order, the three new codes, `governing` vs `subscription`, idempotency keys); also update the "Error catalogue" doc (2444506) with the three codes. Record the import counts and doc id for the summary.
- [ ] T050 Write specs/020-instapay-claims/summary.md (parent writes it)

---

## Dependencies & execution order

- **Phase 1**: T001–T004 in parallel; T005 after T004 (keys config for factories/sealer tests).
- **Phase 2**: T006–T007 tests first; T008, T009, T010, T012 parallel; T011 after T006–T007; all of Phase 2 blocks the stories.
- **US1** (T013–T021) needs Phase 2. **US2** (T022–T031) needs US1's Domain/repository (T018–T019). **US3** (T032–T037) needs US2's codes (T027–T028). **US4** (T038–T039) needs US3. **US5** (T040–T045) needs US1–US3 (claims, codes, subscriptions); T042 is independent of T040/T041.
- **Polish** after all stories; T050 last.

## Parallel examples

- Phase 1: T001, T002, T003, T004 together.
- US1 tests: T013, T014, T015, T016, T017 together; then T018 ∥ (T019 after T018).
- US2 tests: T022, T023, T024, T025, T026 together; T027 ∥ T029's views.
- US5 tests: T040–T044 together.

## Implementation strategy

1. **MVP**: Phases 1–2 + US1 → the landing page can submit claims (operators can list them from the CLI once T031 lands).
2. **Value**: US2 + US3 → the full pay-review-redeem loop; run the C11 checkpoint.
3. **Hardening**: US4, US5, polish.
