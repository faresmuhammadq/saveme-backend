# Data Model: Billing — InstaPay Claims (spec 020)

PostgreSQL 16. UUIDv7 keys. Instants `timestamptz(6)` in UTC. No float/decimal column (P-03). Nothing here is syncable or user-scoped.

## `payment_claims` — Billing (`app/Modules/Billing/Infrastructure/Database/Migrations`)

| Column | Type | Rule |
|---|---|---|
| `id` | uuid PK | server UUIDv7 |
| `plan_id` | uuid FK → `plans.id` | the claimed plan |
| `amount_minor` | bigint | equals the plan's `instapay` price at submission, ≥ 0 |
| `currency` | char(3) | the price's currency |
| `reference` | varchar(32) | normalised: `^[A-Z0-9]{8,32}$` (CHECK) |
| `address_sealed` | text null | `BillingSealer` output (`v1.{kid}.…`); null once erased |
| `address_lookup` | varchar(64) null | Identity's keyed lookup value; null once erased |
| `locale` | varchar(2) | CHECK in (`en`, `ar`) |
| `receipt_path` | varchar(255) null | `billing/claims/{id}/receipt` on the `local` disk; the file holds sealed bytes |
| `receipt_media_type` | varchar(16) null | CHECK in (`image/jpeg`, `image/png`, `image/webp`) |
| `receipt_bytes` | integer null | decoded size, 1–3,145,728 |
| `status` | varchar(10) | CHECK in (`submitted`, `approved`, `rejected`, `expired`) |
| `expires_at` | timestamptz | `created_at` + 7 days |
| `reviewed_by` | uuid null | operator account id (no FK: Admin owns operators, P-02) |
| `reviewed_at` | timestamptz null | |
| `reject_reason` | varchar(24) null | CHECK in (`reference_not_found`, `amount_mismatch`, `duplicate`, `other`) |
| `review_note` | text null | ≤ 500 characters; operator-only; never mailed, never audited |
| `closed_at` | timestamptz null | see "Closing" |
| `erased_at` | timestamptz null | when address, lookup and receipt were erased |
| `created_at`, `updated_at` | timestamptz | |

Constraints and indexes:

- CHECK `(status IN ('approved','rejected')) = (reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL)`; CHECK `(status = 'rejected') = (reject_reason IS NOT NULL)`; CHECK `status IN ('rejected','expired') ⇒ closed_at IS NOT NULL`; CHECK `status = 'submitted' ⇒ closed_at IS NULL`.
- CHECK `(receipt_path IS NULL) = (receipt_media_type IS NULL) AND (receipt_path IS NULL) = (receipt_bytes IS NULL)`.
- CHECK `erased_at IS NOT NULL ⇒ (address_sealed IS NULL AND address_lookup IS NULL AND receipt_path IS NULL)`; CHECK `erased_at IS NULL ⇒ (address_sealed IS NOT NULL AND address_lookup IS NOT NULL)`.
- UNIQUE `payment_claims_one_approved_reference ON (reference) WHERE status = 'approved'` (FR-021).
- INDEX `(reference)` (duplicate listing); INDEX `(status, created_at, id)` (review queue); INDEX `(expires_at) WHERE status = 'submitted'` (expiry sweep); INDEX `(closed_at) WHERE closed_at IS NOT NULL` (purge); INDEX `(address_lookup, created_at)` not needed (the per-address budget lives in the rate limiter, not in SQL).

State machine: `submitted` → `approved` (operator) | `rejected` (operator) | `expired` (sweep). No transition out of `approved`, `rejected` or `expired`.

**Closing** (`closed_at`): set on reject and expiry; for an approved claim, when its code becomes `redeemed`, `expired` or `revoked`. Retention runs from it: erase at +90 days, delete at +400 days (FR-039).

## `redeem_codes` — Billing

| Column | Type | Rule |
|---|---|---|
| `id` | uuid PK | server UUIDv7 |
| `claim_id` | uuid FK → `payment_claims.id` ON DELETE CASCADE, UNIQUE | one code per approved claim |
| `code_hash` | char(64) UNIQUE | hex SHA-256 of the normalised 16-character code |
| `status` | varchar(10) | CHECK in (`issued`, `redeemed`, `revoked`, `expired`) |
| `expires_at` | timestamptz | issue + 30 days |
| `redeemed_by` | uuid null | user id (no FK: Identity owns users, P-02) |
| `redeemed_at` | timestamptz null | |
| `subscription_id` | uuid null | the subscription the redemption created (no FK, so B15's purge of a person's subscriptions is never blocked; identifier only) |
| `revoked_at` | timestamptz null | |
| `created_at`, `updated_at` | timestamptz | |

CHECK `(status = 'redeemed') = (redeemed_by IS NOT NULL AND redeemed_at IS NOT NULL AND subscription_id IS NOT NULL)`; CHECK `(status = 'revoked') = (revoked_at IS NOT NULL)`. INDEX `(expires_at) WHERE status = 'issued'` (code expiry sweep).

State machine: `issued` → `redeemed` (redemption) | `revoked` (operator) | `expired` (sweep, or found past `expires_at` at redemption — redemption refuses but leaves the write to the sweep). The plaintext code is never stored.

The hash stays after the claim's personal data is erased (it is not personal data and makes a late redemption answer `410`/`409` rather than `404`), and goes with the claim at 400 days.

## `subscriptions` — Billing (spec 009; one column added)

| Column | Type | Rule |
|---|---|---|
| `renewal_reminded_at` | timestamptz null | set once when the renewal reminder is queued (FR-034) |

Redemption inserts rows with `provider = 'instapay'`, `provider_ref = <claim id>` (the existing UNIQUE `(provider, provider_ref)` guarantees one subscription per claim), `status = 'active'`, `auto_renew = false`, `grace_until` null, `source_meta = {}`.

## `idempotency_records` — Billing

| Column | Type | Rule |
|---|---|---|
| `id` | uuid PK | |
| `scope` | varchar(48) | `user:<uuid>` or `anonymous` |
| `key` | varchar(255) | the client's `Idempotency-Key` |
| `fingerprint` | char(64) | hex SHA-256 of method, path and canonical body |
| `state` | varchar(12) | CHECK in (`in_progress`, `completed`) |
| `response_status` | smallint null | set when `completed` |
| `response_body` | jsonb null | set when `completed`; never holds a secret (neither answer carries one) |
| `created_at` | timestamptz | |
| `expires_at` | timestamptz | `created_at` + 24 hours |

UNIQUE `(scope, key)`; INDEX `(expires_at)`. CHECK `(state = 'completed') = (response_status IS NOT NULL AND response_body IS NOT NULL)`.

## `audit_entries` — Identity (CHECK widened)

Migration `…_allow_payment_claim_audit_subject` (Identity): the `subject_type` CHECK gains `payment_claim`; the `event` vocabulary gains `claim_reviewed` (if `event` carries a CHECK, it is widened in the same migration). Detail shapes:

- `claim_reviewed`: `{outcome: approved | rejected | code_revoked, reason: <reject reason>}` (`reason` only when rejected; null members are omitted, like every audit detail) — the free-text revoke reason and the review note are **not** copied (they may hold personal detail).
- `entitlement_changed` (existing): `{action: "redeem", plan_id}` (null members omitted).
- `admin_action` (existing, Admin): actions `claim_receipt_viewed`, `claim_address_read`, subject `payment_claim`.

## Files

`storage/app/private/billing/claims/{claim_id}/receipt` — sealed bytes (`BillingSealer`, associated data `payment_claim:{id}:receipt`). Deleted with the erase step; the directory is removed with it.

## Configuration

`config/billing.php` `instapay` block and keys — see research R-15.

## Lock order

Review: claim row `FOR UPDATE` → (approve) plan read → insert code → audit. Redemption: code row `FOR UPDATE` → claim row `FOR UPDATE` → the person's subscription rows `FOR UPDATE` (ascending id) → insert subscription → update code and claim → audit. The reminder takes the subscription row only. No path takes a subscription lock before a claim or code lock, so the two orders cannot deadlock.

## Wire mapping

- Claim answer: `id`, `status`, `plan` (`PlanRef` from the plan, in the request's language), `amount` (`{amount_minor, currency}`), `expires_at`. Never: address, lookup, reference, receipt, review fields.
- Redeem answer: `subscription {id, plan, provider, status, current_period_start, current_period_end}`, `governing` (`MySubscription`), `entitlements` (`EntitlementSummary`). Never: claim id, code, address.
