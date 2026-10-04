# Research: Billing — InstaPay Claims (spec 020, B11)

Every decision below was taken with the owner away, as the conservative option, and is recorded as an assumed default in plan.md.

## R-1 The claim endpoint is public, web-only and uniform

- **Decision**: `POST /api/v1/billing/instapay/claims` with `security: []`, behind the `api` group (request id, TLS refusal, JSON negotiation, client version, language). A `ClaimsAreWebOnly` route middleware answers `404 NOT_FOUND` unless `X-Client-Platform` is `web`. The service stores the claim without any Identity call that touches a user row: the address is only normalised and turned into its keyed lookup value (both pure functions over the key), then sealed.
- **Rationale**: the brief and the reconcile table (uniform answer; landing page is an API client, P-18 rule 1); P-10 rule 3. With no user lookup on the path, equal timing holds by construction instead of by padding (baseline, Uniform answers: "a timing difference is a slower version of the same leak").
- **Alternatives**: require a session from the website (ADR-0013 rule 12's "authenticated") — rejected: the brief's flow starts from an address, and a signed-in claim would reveal nothing new while forcing registration before payment. Look the address up and hold claims without an account — rejected: needs padding to equalise timing and a second state machine. `403` for android — rejected: `404` makes the operation not exist for the app.

## R-2 Idempotency is built here, in Billing

- **Decision**: Billing owns `idempotency_records` and an `Idempotent` route middleware used by both operations (and by B10's purchase acknowledgement later). Flow: key absent → `400 IDEMPOTENCY_KEY_REQUIRED`; malformed (`^[A-Za-z0-9._:-]{16,255}$`) → `400 IDEMPOTENCY_KEY_INVALID`; fingerprint = SHA-256 of `METHOD \n path \n canonical JSON body` (keys sorted recursively, no whitespace); scope = `user:<id>` when authenticated, else `anonymous`. `INSERT … ON CONFLICT (scope, key) DO NOTHING` an `in_progress` row; on conflict read the row: different fingerprint → `409 IDEMPOTENCY_KEY_REUSED`; `in_progress` → `409 IDEMPOTENCY_REQUEST_IN_PROGRESS` with `Retry-After: 1`; `completed` → replay stored status and body with `Idempotency-Replayed: true`. After the inner handler: a `2xx` answer is stored (`completed`); any other answer deletes the `in_progress` row, so a corrected retry with the same key proceeds. An `in_progress` row older than 60 seconds is treated as abandoned (a crashed request): the next request with the same key and fingerprint takes it over (conditional `UPDATE … WHERE state = 'in_progress' AND created_at < now − 60 s`) instead of answering `409`. Rows expire 24 hours after creation; an hourly `billing:purge-idempotency-records` deletes them.
- **Rationale**: api-conventions fixes the outcomes; nothing implements them yet (grep: no idempotency code exists). All three operations needing the key are Billing's, so the store belongs to Billing (P-02) rather than to the shared runtime, which holds no persistence. Storing only successes avoids replaying a refusal the client has since fixed.
- **Alternatives**: a cache-backed store — rejected: the 24-hour promise and the in-flight state need a durable row with a unique index. Store refusals too — rejected: a `429` or `422` replayed for 24 hours helps nobody.
- **Anonymous scope**: a key collision between two visitors needs both the same key (≥ 16 client characters) and, to replay, the same body — which yields the same answer anyway; a different body is `409`. Nothing leaks.

## R-3 Order of checks

- **Claim**: api group refusals → platform (`404`) → idempotency header (`400`) → idempotency store (replay / `409`) → body validation (`422`) → route budgets (`429`, spent here) → apply → `202`. Validation precedes the budget so a typo does not cost one of five attempts; the budget precedes the write.
- **Redeem**: `auth:api` (`401`) → idempotency header → store → body format (`422`) → budget (`429`) → service order of FR-026 → `200`. The verification check comes last on purpose: an unverified person with the right address learns only what verifying fixes.

## R-4 Screenshot inside the JSON body

- **Decision**: `screenshot: {media_type, data}`, `data` standard base64 (RFC 4648 §4, no line breaks). Decoded ≤ 3 MiB (3,145,728 bytes). Checks: strict base64 decode; signature (`FF D8 FF` JPEG, `89 50 4E 47 0D 0A 1A 0A` PNG, `RIFF????WEBP` WebP) equals the declared type; `getimagesizefromstring` succeeds and reports the same type. The raw request body is therefore ≤ ~4.3 MB; PHP's `post_max_size` default (8M) admits it; the nginx `client_max_body_size` of the deployment must be ≥ 5 MB (deployment note).
- **Rationale**: api-conventions (JSON only, 415 otherwise) stays untouched; `getimagesizefromstring` and base64 are core PHP, no dependency.
- **Alternatives**: multipart upload — rejected: a second media type needs a conventions change; a separate upload endpoint with a token — rejected: two calls, orphan files, more surface. Re-encoding to strip EXIF — rejected: needs GD/Imagick (not guaranteed installed) or a library (approval); encryption and short retention instead (Open question 5).

## R-5 Address and receipt at rest: Billing's own keyring

- **Decision**: a `BillingSealer` (XChaCha20-Poly1305, `v1.{kid}.{base64(nonce‖ciphertext)}`, associated data `payment_claim:{id}:{field}`) over `BillingKeyring` (`BILLING_KEYS` kid→base64 32 bytes, `BILLING_ACTIVE_KEY`), copied from spec 018's `AdminSealer` like 018 copied Identity's. The address is sealed into `address_sealed`; the receipt bytes are sealed and written to the `local` disk (root `storage/app/private`) at `billing/claims/{claim_id}/receipt`, no extension, no client name. The lookup value comes from Identity (R-6) so it matches account lookups.
- **Rationale**: baseline: address "encrypted, with a keyed lookup value beside it", key id beside the value, keys outside the database; Identity's cipher is per-user and a claim has no user. A separate keyring keeps a Billing compromise from exposing user data keys and vice versa. Encrypting the receipt means a disk or backup copy holds nothing readable.
- **Alternatives**: Laravel's `encrypted` cast — rejected: no key reference (spec 018 R-10). Seal under the eventual user's key — impossible at submission. Store the receipt in the database — rejected: bloats rows and backups; the brief asks for storage with signed links.

## R-6 What Billing needs from Identity

- **Decision**: a new Identity Application service `ClaimAddresses` (like spec 016's `InvitationAddresses`): `normaliseAndLookup(address): {address, lookup}` (throws on an invalid address), `accountFor(lookup): ?string` (live, not purged), `lookupOfUser(userId): string`, `isVerified(userId): bool`, `addressAndLocaleOf(userId): {address, locale}` (for the reminder). Submission calls only `normaliseAndLookup`.
- **Rationale**: P-02 rule 4; one normalisation and one key for every address in the system.

## R-7 Codes

- **Decision**: 10 random bytes → 16 Crockford base32 characters (`0123456789ABCDEFGHJKMNPQRSTVWXYZ`); displayed `XXXX-XXXX-XXXX-XXXX`. Normalise input: strip spaces and hyphens, upper-case, `O`→`0`, `I`/`L`→`1`; then require `^[0-9A-HJKMNP-TV-Z]{16}$` (else `422 /code INVALID_TYPE`). Stored: `code_hash = hex(SHA-256(normalised))`, unique. Domain value object `RedeemCode` (generate, normalise, hash, display) in Billing Domain, pure.
- **Rationale**: 80 bits make online guessing (10/hour) and offline guessing of a leaked hash both hopeless, so a plain SHA-256 is enough (spec 016's tokens use SHA-256 too); Crockford avoids look-alikes and is easy to type on a phone; C11 asked for a maskable format.
- **Alternatives**: 6–8 digit numeric codes — rejected: guessable at any useful rate limit. Keyed HMAC — unnecessary at 80 bits; adds a key to rotate.

## R-8 Subscription at redemption, chained periods

- **Decision**: `RedeemCodes::redeem(userId, rawCode)` in one transaction: lock the code row `FOR UPDATE` (by hash) → status/expiry/owner checks (FR-026 order, after the not-found check) → `ClaimAddresses::lookupOfUser` vs claim lookup → `VerificationGate::assertVerified` → lock the person's subscriptions (`SELECT … FOR UPDATE` on their rows) → `RedeemedPeriod::for(plan, now, latestInstapayEnd)` → `SubscriptionRepository::create(provider instapay, provider_ref claim id, …)` → mark code redeemed → close the claim → audit `entitlement_changed` (`redeem`) → `SubscriptionActivated` after commit. The unique `(provider, provider_ref)` from spec 009 is the last guard against a second subscription.
- **Period arithmetic** (Domain `RedeemedPeriod`, pure): start = max(now, latest dated `current_period_end` of the person's granting `instapay` subscriptions); end: monthly `start + 1 month` without overflow (Jan 31 → Feb 28/29), yearly `+1 year` without overflow, custom `+period_days days`, lifetime null. Instants in UTC (P-12 rule 1).
- **Rationale**: ADR-0013 rule 2 needs a `user_id`, which exists only at redemption; chaining only `instapay` keeps Play and manual semantics untouched (Play renews itself; manual is a gift). Future-start subscriptions grant immediately under `grantsAt` (only the end is checked), which is the desired effect for an early renewal: continuous access.
- **Alternatives**: create the subscription at approval for the account holding the address — rejected: unregistered payers, and changes access without the person's action; extend the existing subscription's end — rejected: mixes two claims in one row and breaks "one claim → one subscription" auditing.

## R-9 Review commands and audit

- **Decision**: `ClaimService` (the constitution's name) in Billing Application: `submit(...)`, `approve(operatorId, claimId)`, `reject(operatorId, claimId, reason, ?note)`, `revokeCode(operatorId, claimId, reason)`. Each review command: lock the claim `FOR UPDATE` → state and expiry checks (Domain `PaymentClaim` rules) → act → one `claim_reviewed` audit entry through Identity's `AuditLog` (actor `operator`) → queue mail `afterCommit()`. Approval also checks the plan (active, still has an InstaPay price) and the unique partial index `payment_claims_one_approved_reference ON (reference) WHERE status = 'approved'` (a unique violation maps to `ReferenceAlreadyApproved`). Domain refusals: `ClaimNotReviewable`, `ReferenceAlreadyApproved`, `PlanNotGrantable` (existing), `InvalidReason` (existing), `CodeNotRevocable`. They have no HTTP mapping (no route) — B12 shows them.
- **Audit**: new event `claim_reviewed` in Identity's `AuditEvent` and the baseline vocabulary (Billing group, written by Billing); new `SubjectType::PaymentClaim` with an Identity migration widening the CHECK (as spec 018 did for `operator`); detail class `ClaimReviewedDetail {outcome, reason}` in Identity's audit details (where `EntitlementChangedDetail` lives). `EntitlementChangedDetail` accepts action `redeem`.
- **Operator reads**: Admin's `AdminAction` enum gains `claim_receipt_viewed` and `claim_address_read`; the receipt route (R-10) writes the first; B12 writes the second when its screen opens a claim's detail.
- **CLI**: `billing:claim:list` (submitted claims: id, plan, amount, reference, created, duplicates count; no address), `billing:claim:approve {claim} --operator=`, `billing:claim:reject {claim} --operator= --reason= [--note=]`, `billing:claim:revoke-code {claim} --operator= --reason=`. The operator id must be an active operator per Admin's `OperatorDirectory` (Application). The CLI path bypasses the second factor: shell access is already the stronger privilege (operators are created from the shell, spec 018).
- **Rationale**: B12 brief ("a domain command reused by tests"); baseline (one writer per event, nothing excluded inside); spec 009's `OperatorSubscriptions` precedent (Billing writes its own audit with an operator actor).
- **Alternatives**: `admin_action` written by Admin around the Billing call — rejected: splits one act across two modules, and a CLI or test call would skip the audit.

## R-10 Receipt viewing

- **Decision**: Billing Application `ClaimReceipts::temporaryUrl(claimId): ?string` returns `URL::temporarySignedRoute('admin.claims.receipt', now()+5 min, ['claim' => id])` or null without a receipt. The route `GET /admin/claims/{claim}/receipt` is registered by the **Admin** module (operator guard, `signed` middleware) and its controller calls `ClaimReceipts::open(claimId): {media_type, bytes}` (decrypts), records `admin_action` `claim_receipt_viewed`, and streams with `Content-Type` the stored type, `Content-Disposition: inline`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'`.
- **Rationale**: P-18 rule 4 (back-office routes under `/admin`, operator guard); the signature and 5-minute lifetime satisfy the brief; Laravel's local disk `serve`/`temporaryUrl` cannot decrypt, so a controller is needed.

## R-11 Expiry, code expiry, reminder and retention jobs

- `billing:expire-claims` (hourly, `withoutOverlapping`): `UPDATE … SET status='expired', closed_at=now WHERE status='submitted' AND expires_at <= now RETURNING id, locale` in batches of 200; queue one `SendClaimExpiredMail` per returned claim. Conditional update → idempotent.
- `billing:expire-redeem-codes` (daily): codes `issued` with `expires_at <= now` → `expired`, claim `closed_at = now`.
- `billing:send-renewal-reminders` (daily): candidates = `instapay` subscriptions `active`, `current_period_end` in `(now, now + 7 days]`, `renewal_reminded_at IS NULL`; for each: `GoverningSubscription::among(forUser)` is this one and none of the person's granting subscriptions ends later (governing already implies it) → in a transaction, conditional `UPDATE … SET renewal_reminded_at = now WHERE id = ? AND renewal_reminded_at IS NULL`; if one row changed, queue `SendRenewalReminderMail(userId, subscriptionId)` after commit. The job resolves address and locale through `ClaimAddresses::addressAndLocaleOf` at send time and skips a purged account.
- `billing:purge-claims` (daily): erase (`address_sealed`, `address_lookup`, receipt file and columns → null, `erased_at = now`) where `closed_at <= now - 90 days AND erased_at IS NULL`; delete claims (and their codes, by cascade) where `closed_at <= now - 400 days`.
- `billing:purge-idempotency-records` (hourly): delete where `expires_at <= now`.
- **Closing**: `closed_at` is set when a claim is rejected or expired, and for an approved claim when its code is redeemed, expired or revoked.

## R-12 Rate limits

- **Decision**: `RateLimiter::for` definitions in `BillingHttpServiceProvider`: `billing-claim-origin` (5/hour by IP) and `billing-claim-address` (5/day by lookup value), and `billing-redeem` (10/hour by user id). They are **hit in the controller after validation** (`RateLimiter::tooManyAttempts` / `hit`), not as route middleware, so `422` comes first and replays (answered by the idempotency middleware before the controller) never count. A spent budget throws the runtime's throttle exception, rendered `429 RATE_LIMITED` with `Retry-After` and `RateLimit-*` by the shared runtime.
- **Rationale**: FR-010, FR-033; spec 016's token budget precedent ("spent after validation").

## R-13 Mail

- Queued, `ShouldBeEncrypted` jobs dispatched `afterCommit()`: `SendClaimApprovedMail(claimId, plaintextCode)` (the only place the plaintext exists after the transaction), `SendClaimRejectedMail(claimId)`, `SendClaimExpiredMail(claimId)`, `SendRenewalReminderMail(userId, subscriptionId)`. Claim mails open the sealed address at send time (so an erased claim sends nothing) in the claim's `locale`. Mailables in `app/Modules/Billing/Application/Mail`, texts in `lang/{en,ar}/billing.php`, product name from `config('app.name')` (P-13). The approval mail shows the code grouped, the plan name, "Open the app → Plan → Enter code", "sign in or create an account with this address", and the expiry date.
- **Rationale**: spec 016 R-16 (mail directly after commit, not via an event); P-14; P-15 (encrypted queue payload).

## R-14 Logging

- `RedactSecrets::SENSITIVE_KEY` gains `screenshot|receipt|redeem_code|address_sealed`; the two controllers never log the request; the mail jobs log ids at debug only. A Feature test captures log records while submitting a claim with a receipt and redeeming a code and asserts none contains the base64, the code or the address.

## R-15 Configuration

`config/billing.php` gains an `instapay` block: `claim_days` 7, `code_days` 30, `reminder_days` 7, `receipt_max_bytes` 3145728, `receipt_link_minutes` 5, `erase_after_days` 90, `delete_after_days` 400, `renew_url` (env `BILLING_INSTAPAY_RENEW_URL`, default `http://saveme-backend.test/renew`), `support_contact` (env, shown in mails), `throttle.claims_per_hour_per_origin` 5, `throttle.claims_per_day_per_address` 5, `throttle.redeem_per_hour` 10; plus `keys`/`active_key` (env `BILLING_KEYS`, `BILLING_ACTIVE_KEY`) and `idempotency_hours` 24. `.env.example` lists the new variables empty; `phpunit.xml` fixes test keys.

## R-16 No new dependency

Laravel mail, queues, scheduler, `RateLimiter`, filesystem `local` disk, signed URLs, libsodium (bundled with PHP), `getimagesizefromstring` (core) and Spectator are installed. A captcha (Open question 1), an image library for EXIF stripping (Open question 5) and S3 storage (`league/flysystem-aws-s3-v3`, if production wants object storage) would each need owner approval; the design does not depend on any of them.

## R-17 Contract and catalogue

- `docs/openapi/paths/instapay.yaml` (new; indexed; `info.version` v1.12; examples) reuses `PlanRef`, `EntitlementSummary` and `MySubscription` from `paths/billing.yaml` and `Money` from `components/schemas.yaml`.
- Three new response codes, added to `error-catalog.yaml`, `ErrorCode`, `lang/{en,ar}/problems.php` and the shared response descriptions in one implementation task (spec 012 T002's pattern, the parity test needs all together): `REDEEM_CODE_USED` (409, never), `REDEEM_CODE_EXPIRED` (410, never), `REDEEM_CODE_EMAIL_MISMATCH` (403, after-change). Until that task lands, the path file names them in prose, not after a `Code:` label, so `tests/Contract/OpenApi` stays green.
- Field codes reused: `REQUIRED`, `UNKNOWN_FIELD`, `INVALID_TYPE`, `INVALID_ENUM_VALUE`, `OUT_OF_RANGE`, `AMOUNT_NOT_INTEGER`, `INVALID_CURRENCY`, `CURRENCY_MISMATCH`.

## R-18 ADR-0023

ADR-0023 "InstaPay claims deliver a plan through a claim-bound redeem code" (Accepted, P-09, P-10): (1) claim submission is public, web-only (`X-Client-Platform: web`), uniform, rate-limited — **supersedes ADR-0013 rule 12's "authenticated" only**; (2) an approved claim issues one Redeem Code bound to the claim's address; (3) the subscription is created at redemption, provider `instapay`, `provider_ref` the claim id — **narrows ADR-0013 rule 2's "manual records … redeem codes" to codes not backed by a claim**; (4) approval does not reactivate any subscription; the `expired ─claim approved─▶ active` arc of rule 4 is not used by this channel, a new subscription is created instead; (5) `instapay` billing periods chain on early renewal. Constitution PATCH v1.3.12: P-09 and P-10 `ADRs:` lines gain ADR-0023.
