# Summary: InstaPay Claims & Redeem Codes (spec 020, B11)

**Date**: 2026-10-05 · **Status**: implemented (T001–T050), QA and E2E green, committed on `main` (7155d6d).

## What was built

- **Public, web-only, idempotent `POST /billing/instapay/claims`**: no sign-in; any `X-Client-Platform` other than `web` is 404 (`ios` never reaches it: the shared runtime rejects it with 400 `CLIENT_VERSION_INVALID`); `Idempotency-Key` required. It never looks the address up, answers identically for registered and unregistered addresses, and never echoes the email. The address and an optional base64 receipt (JPEG/PNG/WebP, at most 3 MiB, bytes checked against the declared type, PNG `IHDR` and 1–30,000 px per side) are sealed under Billing's own XChaCha20-Poly1305 keyring; the receipt file sits on the existing private disk. Budgets: 5 per hour per origin, 5 per 24 hours per address.
- **`ClaimService`** (`approve`, `reject`, `revokeCode`) for operators: each writes one `claim_reviewed` audit entry, issues a 16-character Crockford-base32 redeem code (stored only as a SHA-256 hash), and sends an encrypted after-commit mail in the claim's language. At most one approved claim exists per reference (partial unique index). A CLI, `billing:claim:{list,approve,reject,revoke-code}`, acts for an active operator id.
- **`ClaimQuery`** for the queue, duplicates and detail, and `ClaimReceipts` with the admin route `GET /admin/claims/{claim}/receipt`: signed, 5 minutes, behind the operator guard; each view is an `admin_action` audit entry; `no-store`, `nosniff`, `default-src 'none'`.
- **`POST /billing/redeem`** (signed in, any platform, `Idempotency-Key` required): creates the `instapay` subscription at redemption, with `instapay` periods chaining on early renewal, and answers with `subscription`, `governing` and `entitlements`. Refusal order (FR-026): 422 `/code`, 429, 404 unknown or revoked, 200 same account again, 409 `REDEEM_CODE_USED`, 410 `REDEEM_CODE_EXPIRED`, 403 `REDEEM_CODE_EMAIL_MISMATCH` (never names the address), 403 `EMAIL_NOT_VERIFIED`.
- **Billing-owned `Idempotency-Key` store and middleware** (anonymous scope for claims, 24 h, 60 s stuck-request takeover, only 2xx stored, replay as raw JSON text).
- **Jobs (five sweeps in the schedule)**: hourly claim expiry (7 days) with a notice mail, daily code expiry (30 days), daily single renewal reminder (7 days before an InstaPay subscription ends, only if it governs), daily retention (erase email and receipt 90 days after close, delete records at 400 days), hourly idempotency purge.
- **Three new response codes** in the catalogue, `ErrorCode` enum and en/ar lang together: `REDEEM_CODE_USED`, `REDEEM_CODE_EXPIRED`, `REDEEM_CODE_EMAIL_MISMATCH` (since v1.12).
- **Identity** `ClaimAddresses`, the `payment_claim` audit subject and `claim_reviewed` event, log-redaction keys, and a boot-time refusal of `log`/`array` mailers outside local and testing.
- **Docs**: ADR-0023 (replaces only the word "authenticated" in ADR-0013 rule 12); constitution v1.3.12; security baseline; privacy commitments (en, ar); glossary; api-conventions; B12 and B15 brief notes.
- **Migrations (five, additive)**: Identity `2026_10_09_000000_allow_payment_claim_audit_subject`; Billing `…000001_create_payment_claims_table`, `…000002_create_redeem_codes_table`, `…000003_create_idempotency_records_table`, `…000004_add_renewal_reminded_at_to_subscriptions`.
- **Config**: `BILLING_KEYS` (JSON map like `ADMIN_KEYS`), `BILLING_ACTIVE_KEY`, `BILLING_INSTAPAY_RENEW_URL` (default `http://saveme-backend.test/renew`), `BILLING_SUPPORT_CONTACT` (default `support@example.com`); `config/billing.php` gains `idempotency_hours`, `keys`, `active_key` and the `instapay` block.
- **Apidog**: 2 endpoints created; project doc "InstaPay claims & redeem" (2465513); error catalogue doc 2444506 gained the three rows.
- Two unrelated Larastan fixes: `$request->user('api')` in `Identity\…\ResolvesCaller` and `Sync\…\UserChangesController` (behaviour identical; the default guard is `api`).

## Results

- **QA** (curl-driven): 207 checks in 34 groups; 205 passed outright, 2 were mistakes in the check script (re-verified by hand, behaviour correct). No product defect. Report `qa/qa-20261005-000000.md`.
- **Browser E2E** (admin receipt view): signed link rendered the image with the right headers and one audit entry; expired link, forged signature and unsigned URL each 403; English shell ltr, Arabic shell rtl. Throwaway operator enrolled through the browser with a computed TOTP.
- **Acceptance validation**: 39 rows, all PASS except US2-5 (a plan retired after submission), not run live because it would mutate shared plan data; Feature tests cover it. Report `test-reports/validation-20261005-000500.md`; 5 screenshots.
- **Gates**: Pint and Larastan clean; Arch 52; Unit + Feature + Contract 2,849 passed, 4 opt-in tests skipped (~300 new tests in 28 files).
- **Performance** (Xdebug off, p95): claim with a 3 MiB receipt 350.8 ms (target 800), redeem 47.4 ms (target 300).
- **Docs-only fix**: `spec.md` (FR-036) and `data-model.md` now say audit details omit null members.

## Deployment

- Run the five `2026_10_09` migrations.
- Set `BILLING_KEYS`, `BILLING_ACTIVE_KEY`, `BILLING_INSTAPAY_RENEW_URL`, `BILLING_SUPPORT_CONTACT`.
- Configure trusted proxies (otherwise every visitor shares one per-origin claim budget) and a request body limit of at least 5 MB at the edge.
- Use a real mail transport (the approval mail carries the code).
- The scheduler runs the five sweeps; restart queue workers.

## Notes for the web landing page and the mobile client

- **Landing page**: send `X-Client-Platform: web`, `X-Client-Version`, `Accept-Language`, and one `Idempotency-Key` per form submission, reused on retry. A valid claim returns 202 with `id` and `expires_at`; never claim an account exists. A too-large or malformed receipt is 422 at `/screenshot/data`; a PNG with a bad pixel size is `INVALID_TYPE`, not `OUT_OF_RANGE`.
- **Mobile**: send `Idempotency-Key` on `POST /billing/redeem` (one key per tap). Bind to 403 `REDEEM_CODE_EMAIL_MISMATCH`, 403 `EMAIL_NOT_VERIFIED` (a wrong email outranks unverified), 409 `REDEEM_CODE_USED`, 410 `REDEEM_CODE_EXPIRED`, 404 unknown or revoked, 429 with `Retry-After`, 422 at `/code`. The code is accepted in lowercase, with spaces, with I/L read as 1 (O as 0 uses the same folding but was not tested). Show "now on …" from `governing` (it can be a Play subscription that ends later).

## Assumed defaults

Owner away with delegated authority; each the conservative option.

### Design

1. The claim endpoint is public and web-only (P-10 rule 3).
2. Same answer whether or not the email is registered: submission never looks the address up.
3. A claim whose email has no account is neither held nor rejected for that reason; the operator reviews payment evidence only. The code is mailed and stays redeemable for 30 days, so the payer can register after paying.
4. The plan must be active with an InstaPay price; amount and currency equal that price exactly; the reference is normalised (spaces and hyphens removed, upper-cased) and must be 8–32 characters of A–Z and 0–9.
5. Claim budgets count only after validation passes; idempotent replays don't count. Both claim budgets are checked before either is spent.
6. Duplicate references are accepted and flagged to the reviewer; at most one claim per reference can ever be approved (refusing duplicates would let anyone who knows a reference block the real payer).
7. The receipt travels inside the JSON body as base64 (keeps the JSON-only conventions).
8. Email and receipt are encrypted with Billing's own keyring (copy of spec 018's sealer; a claim has no user key).
9. Operators see receipts only through a 5-minute signed link under `/admin`; each view and each read of a claim's email is audited.
10. Retention: email and receipt erased 90 days after the claim closes; claim and code records deleted at 400 days (matches the audit horizon).
11. Unreviewed claims expire after 7 days (hourly job plus a check at review time); the payer gets a notice mail. Codes are 80-bit, SHA-256 at rest, single use, valid 30 days.
12. Redeem requires a verified account whose email equals the claim's email.
13. The subscription is created at redemption, not approval (at approval there is only an email), tied to the claim id.
14. An existing Play or manual subscription is never touched; the spec 009 rule decides which governs (latest end wins). Early InstaPay renewals start at the end of the current InstaPay period.
15. Redeeming the same code again from the same account returns 200 with the same subscription.
16. Unknown or revoked codes are 404 `NOT_FOUND` (spec 016 precedent).
17. Approve, reject and revoke live in Billing's `ClaimService`; B12's screens must put them behind a fresh second factor (`RequiresRecentFactor`). The CLI skips the second factor (shell access is the stronger privilege).
18. Reject reasons are a fixed list (`reference_not_found`, `amount_mismatch`, `duplicate`, `other`); an optional internal note of up to 500 characters is never mailed or audited. Revoking a code sends no mail.
19. Audit: new `claim_reviewed` event (written by Billing, subject `payment_claim`); redemption writes the existing `entitlement_changed` with action `redeem`. No new domain event.
20. The renewal reminder is sent once per InstaPay subscription, only if it governs, in the profile language.
21. Claim mails use the language of the claim request, falling back to `en`.
22. No new composer or npm dependency; a captcha, EXIF stripping and S3 storage would each need approval and are recorded as open questions.

### Implementation (plan N-1 to N-19)

23. `BILLING_KEYS` is a JSON map like `ADMIN_KEYS`.
24. The two header refusals (`IDEMPOTENCY_KEY_REQUIRED`, `_INVALID`) are pointer-less problems.
25. The idempotency store keeps and replays the answer as raw JSON text (decoding turned `meta: {}` into `[]`); PostgreSQL normalises whitespace, so a replay is equal as JSON, not byte for byte.
26. Only 2xx answers are stored, so a repeated refused request runs again and, on redeem, spends budget.
27. Route budgets are `ClaimBudget` / `RedeemBudget` helper classes hit in the controller (like Sharing's `TokenBudget`).
28. `ApiRequest::refusal()` and `unknownMembers()` became `protected` so `SubmitClaimRequest` can collect its semantic faults into one `VALIDATION_FAILED`.
29. `ReceiptImage` also requires a PNG `IHDR` chunk and 1–30,000 px per side (`getimagesizefromstring` accepted a PNG signature followed by garbage).
30. Review refusals use `ErrorCode::VersionConflict` with a `detailKey` into `lang/{en,ar}/billing.php` (no HTTP route).
31. `revokeCode` validates the reason (1–500 characters) but does not store it (no column; the audit entry must not hold free text). `approve`, `reject` and `revokeCode` return nothing; B12 reads results through `ClaimQuery`.
32. A non-UUID `plan_id` gives `INVALID_ENUM_VALUE`, any other currency `CURRENCY_MISMATCH`, any bad reference `INVALID_TYPE`.
33. Entitlements in the redeem answer come from a fresh `EntitlementService`.
34. The race tests `TRUNCATE audit_entries` in `afterEach` (the audit table refuses `DELETE` of young rows).
35. All 34 items in `checklists/security.md` were ticked with evidence in plan.md; un-tick any you want to own.

## Remaining risks

- Until trusted proxies are configured every visitor shares one per-origin budget; locally all requests share one origin. No bot protection beyond the rate limits (an attacker could use the per-address budget to block one email's claims for 24 hours).
- The Billing sealer copies spec 018's (a second copy that could drift); the idempotency store lives in Billing rather than the shared layer.
- 400-day claim records may be shorter than accounting or tax retention needs.
- No re-issue of an expired code: support must use an operator grant. Receipts keep their EXIF data (encrypted, operator-only, short-lived).
- CORS is left at the framework default (any origin on `api/*`).
- B12's screens depend on these Billing commands and must enforce `RequiresRecentFactor`; B10 will reuse the idempotency middleware.
- QA leftovers on the dev machine: receipt files under `storage/app/private/billing/claims/*` for deleted claims, QA mails to `qa020*` addresses in Mailpit, and a quickstart user `qs-mona-claim-quickstart-*@example.test` (the permission classifier denied the bulk removals). Audit rows remain by design.
