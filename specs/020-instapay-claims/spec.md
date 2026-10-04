# Feature Specification: Billing — InstaPay Claims

**Feature Branch**: `020-instapay-claims`

**Created**: 2026-10-05

**Status**: Draft

**Input**: Brief B11 ([specs/briefs/B11-billing-instapay.md](../briefs/B11-billing-instapay.md)): "On the landing page (never in the app) a visitor picks a plan, sees the InstaPay address and the exact amount, transfers, and submits a claim: email (must match an account), plan, amount, InstaPay reference number, optional screenshot. `POST /billing/instapay/claims` creates a `payment_claim` (pending). An admin reviews it in spec 12; on approval the system creates an `instapay` subscription for the plan's period and emails a single-use **redeem code**; the user enters it in the app via `POST /billing/redeem` which attaches the subscription to the logged-in account. Claims expire after 7 days if unreviewed; users get a reminder email 7 days before period end with a link to renew." Plus the brief's considerations, its reconcile table, and the paired client brief [C11](../../../saveme-kmp/specs/briefs/C11-redeem-code.md) "Needs from the backend" (code format, error codes for expired, used, email mismatch and rate limit, and the response).

> **Nature of this feature.** B11 is the only way to pay for a plan without Google Play. A visitor pays by InstaPay and tells us so through the website; an operator checks the payment against the InstaPay records and approves or rejects the claim; an approved claim produces a one-time **Redeem Code** mailed to the address on the claim; the person types the code into the app ("Enter code"), and the plan applies to their account. **This flow exists because no commercial registration is in place, and it is web-only so the Android app stays compliant with Google Play's billing policy: the app offers only "Enter code" and "Restore purchases" (P-10 rules 2 and 3, ADR-0013 rule 11).**
>
> **Where the brief and the ratified decisions differ, the ratified side wins** (constitution, security baseline, ADR-0013, api-conventions, specs 009, 016, 018):
>
> | Brief | In force | Here |
> |---|---|---|
> | "Email must match an account" | Security baseline, Uniform answers: no surface may reveal whether an address is registered | The claim is accepted with **the same answer, status and timing whether or not the address has an account**. Nothing during submission even looks the address up. Whether an account exists matters only when the code is redeemed |
> | Landing page | P-18 rule 1: the website is an API client; nothing user-facing is server-rendered here | The claim form lives in the separate landing-page project and calls `POST /billing/instapay/claims`. This repository renders no claim page |
> | Admin review "in spec 12" | Review screens build on D1/D2; P-18 rule 6: screens call application services | Approve, reject and revoke are **domain commands** in Billing, tested here, callable from B12's screens and from the command line. No screen ships here |
> | Screenshot storage | Security baseline never-log list covers receipt images; nothing fixes their retention | Receipt images are encrypted at rest, served to operators only through 5-minute signed links, and **deleted 90 days after the claim closes**; the baseline's retention table and protected-attributes table gain rows |
> | "On approval the system creates an `instapay` subscription" | ADR-0013 rule 2: a subscription records a `user_id`; at approval the claim has no account, only an address | Approval issues the code; **the subscription is created at redemption**, for the redeeming account, channel `instapay`, its billing period starting then. ADR-0023 records this |
> | ADR-0013 rule 2: "`manual` records … redeem codes" | ADR-0013 rule 3: approved InstaPay claims normalise into `instapay` | A code issued by an approved claim delivers a **payment**, so its subscription is `instapay`. ADR-0023 narrows rule 2 to codes not backed by a claim (none exist in MVP) |
> | ADR-0013 rule 12: claim submission "authenticated from the landing-page flow" | The brief and the reconcile table: the visitor gives an address, not a session | Submission is **public** (no bearer credential), web-only, rate-limited and answered uniformly. ADR-0023 supersedes rule 12's word "authenticated" only |
> | `Idempotency-Key` "scoped to the authenticated user" (api-conventions) | Claim submission and redemption are two of the three operations that require the key | Both require it. The claim, having no user, is keyed in a single **anonymous** scope; api-conventions gains that sentence |
> | Requests are JSON only (api-conventions, Media types) | A screenshot is a binary file | The screenshot travels **inside the JSON body as base64**, at most 3 MiB decoded. No multipart endpoint, so no conventions change |

## Clarifications

### Session 2026-10-05

Owner away with delegated authority; each answer below is the recommended (most conservative) option, recorded as an **assumed default**.

- Q: What does the claim form send, and how is each member validated? → A: `email`, `plan_id`, `amount_minor`, `currency`, `reference`, and an optional `screenshot {media_type, data}`. `email` is a syntactically valid address (≤ 254 characters). `plan_id` must name an **active plan that has an InstaPay price**. `currency` must equal that price's currency and `amount_minor` must **equal** that price's amount exactly. `reference` is normalised (spaces and hyphens removed, upper-cased) and must then be 8–32 characters of `A–Z0–9`. Every failure is one `422 VALIDATION_FAILED` with one entry per member, using existing field codes. *Assumed default.* (FR-003–FR-007)
- Q: How does the form avoid revealing whether an address is registered, and what happens to a claim whose address has no account? → A: The submission path **never looks the address up**: the answer (`202` with the same body shape), the status and the work done are identical for any address, so timing is equal by construction. A claim with no matching account is **neither held nor rejected for that reason**: the operator reviews the payment evidence alone, the code is mailed to the claim's address with "sign in, or create an account, with this address", and it stays redeemable for 30 days, so a payer may register after paying. *Assumed default.* (FR-008, FR-009)
- Q: Rate limits on claim submission? → A: **5 per hour per origin** and **5 per 24 hours per address** (counted by the address's lookup value, registered or not), both `429 RATE_LIMITED` with `Retry-After`. Spent only by a submission that passed validation; an idempotent replay spends nothing. *Assumed default.* (FR-010)
- Q: Duplicate reference numbers? → A: **Accepted, with the same answer**, and flagged to the reviewer (the review query shows every other claim carrying the same normalised reference). Refusing a duplicate would let anyone who learns a reference block the real payer. Instead **at most one claim per reference can ever be approved**: approving a second is refused. *Assumed default.* (FR-011, FR-021)
- Q: Screenshot limits and storage? → A: Optional; `image/jpeg`, `image/png` or `image/webp`; **at most 3 MiB decoded**; the bytes must carry the declared type's signature and parse as an image. Stored **encrypted** on the application's existing private filesystem disk under a server-chosen name, never under a client-supplied name. Shown to operators only through a **signed link valid for 5 minutes**, behind the operator guard. **Never logged**, never returned by any application endpoint. Deleted **90 days after the claim closes**. *Assumed default.* (FR-012–FR-014, FR-040)
- Q: When does a claim expire? → A: **7 days after submission** if still `submitted`. An hourly sweep closes it as `expired` and mails the claimant a notice telling them to contact support with their reference; approval and rejection also refuse a claim past its expiry even before the sweep runs. *Assumed default.* (FR-015, FR-016)
- Q: What is the Redeem Code? → A: **16 characters from Crockford's base32 alphabet (80 bits)**, mailed and shown as `XXXX-XXXX-XXXX-XXXX`. Input is accepted case-insensitively with or without hyphens or spaces, and Crockford's look-alikes are folded (`O`→`0`, `I`/`L`→`1`); `U` is never issued. Stored only as a SHA-256 hash. **Single use, valid 30 days** from approval. *Assumed default.* (FR-022, FR-023)
- Q: Who may redeem a code? → A: **Any signed-in account whose address is the claim's address** (compared by lookup value) **and is verified**. A different address is `403 REDEEM_CODE_EMAIL_MISMATCH`, whose message tells the person to sign in with the address the code was sent to without naming it. An unverified account with the right address is `403 EMAIL_NOT_VERIFIED`. Redemption works on any client platform. *Assumed default.* (FR-026, FR-027)
- Q: What does redemption do, and what happens to an existing Google Play subscription? → A: It creates **one `instapay` subscription** for the claim's plan on the redeeming account. Its billing period starts at the later of now and the end of the account's latest-ending granting `instapay` subscription (so renewing early loses no days), and lasts the plan's period (monthly: one calendar month, yearly: one year, custom: its days, lifetime: no end). **Nothing else is changed**: a Google Play or `manual` subscription keeps running, and the governing subscription follows spec 009's precedence rule (latest billing-period end wins; no end beats any end). The response names the redeemed subscription, the governing subscription and the resulting entitlements, so the app can show which plan now governs. *Assumed default.* (FR-028–FR-031)
- Q: Rate limit on redemption? → A: **10 attempts per hour per person** (`429 RATE_LIMITED`), counted for every attempt that passed format validation; replays spend nothing. 80-bit codes make guessing hopeless; the limit bounds noise. *Assumed default.* (FR-033)
- Q: Is redemption idempotent? → A: Yes, twice over: it requires `Idempotency-Key` (a retry replays the stored answer), and a code already redeemed **by the same account** answers `200` with the same subscription rather than an error. Redeemed by another account is `409 REDEEM_CODE_USED`. *Assumed default.* (FR-032)
- Q: The renewal reminder? → A: A daily run mails the account **once per `instapay` subscription** when its billing period ends within the next 7 days, it is the governing subscription, and no other granting subscription ends later. The mail goes to the account's own address in its profile language, with a link to the landing page's renewal entry (configured, never computed from a request). Lifetime subscriptions get none. *Assumed default.* (FR-034, FR-035)
- Q: Approval and rejection — who, with what, and what mail? → A: Operator-only domain commands taking the operator's id. **Approve** issues the code, mails it to the claim's address in the claim's language, and writes one audit entry. **Reject** takes a reason from a fixed list (`reference_not_found`, `amount_mismatch`, `duplicate`, `other`) plus an optional internal note (≤ 500 characters, never mailed); the mail states the localised reason and invites the person to contact support. A third command, **revoke code**, voids an issued, unredeemed code after a mistaken approval (reason required, no mail). B12's screens must put all three behind a second-factor proof from the last 10 minutes (the baseline's sensitive acts gain them). *Assumed default.* (FR-017–FR-021, FR-024)
- Q: Which audit entries and domain events? → A: A new audit event **`claim_reviewed`** (written by Billing, subject type **`payment_claim`**, actor the operator, detail `{outcome, reason}` with outcome `approved` | `rejected` | `code_revoked`) — never the address, reference, amount or code. Redemption writes **`entitlement_changed`** (actor the user, subject the new subscription, action `redeem`), as an operator grant does. Redemption publishes the existing **`SubscriptionActivated`**. No new domain event: mails are queued directly after commit, as spec 016 does. *Assumed default.* (FR-036, FR-037)
- Q: Data at rest and retention? → A: The claim's address is **encrypted** (Billing's own keyring, like spec 018's) with the shared keyed lookup value beside it; the receipt image is encrypted with the same keyring. The address, its lookup value and the receipt image are **erased 90 days after the claim closes** (rejected, expired, or — for an approved claim — its code redeemed, expired or revoked). The remaining claim record (plan, amount, reference, status, review facts; no personal data) is **deleted 400 days after closing**, matching the audit horizon. *Assumed default.* (FR-038, FR-039)
- Q: Does any of this need a new dependency? → A: **No.** Mail, queues, the scheduler, the rate limiter, the filesystem disk, signed URLs, libsodium and image-type detection are already in the installed stack. A bot challenge (captcha) for the claim form would need a third-party service and an approval; it is **not** used, and recorded as an open question. *Assumed default.* (FR-044)

### Session 2026-10-05 (clarify pass)

- Q: When an operator reads a claim's address or opens its receipt, is that read recorded, and by whom? → A: **Yes, each is recorded as one `admin_action` entry written by Admin** (actions `claim_address_read` and `claim_receipt_viewed`, subject `payment_claim`), because the baseline grants operators the address only "with a recorded read" and Admin is the sole writer of `admin_action`. Billing's review query returns the address opened only from its single-claim detail method; the list method returns no address. The receipt route (Admin module) records the view before streaming. *Assumed default.* (FR-014a, FR-045)
- Q: Is a Redeem Code's expiry stored, or only computed when someone redeems? → A: **Stored.** A daily sweep marks `issued` codes past `expires_at` as `expired` and sets the claim's `closed_at`, so the 90-day erasure clock starts from a recorded fact. Redemption still checks `expires_at` itself, so the sweep's timing never extends a code. *Assumed default.* (FR-022a)
- Q: In which language are the claimant's mails written? → A: **The language the claim was submitted in**, taken from the request's resolved `Accept-Language` (`ar` or `en`; with no supported language named, the API's fallback `en`, as for every other answer) and stored on the claim; the renewal reminder uses the account's profile language instead, because it goes to an account. *Assumed default.* (FR-008a)
- Q: May an operator approve or reject a claim when the address has no account yet, and does the review show it? → A: **Yes to both.** The single-claim detail reports `account_exists` (true/false, computed at read time through Identity) so the operator can add a note; the decision rests on the payment evidence only, and the outcome is never mailed differently. This is operator-only data, recorded by the same `claim_address_read` entry. *Assumed default.* (FR-009)

Further defaults taken while writing the spec, each the conservative option and listed again in the plan's Assumed defaults:

- The claim endpoint answers only `X-Client-Platform: web`; an `android` caller gets `404 NOT_FOUND`, so the operation does not exist for the app (P-10 rule 3, defence in depth; the header is not a security boundary).
- No acknowledgement mail on submission: the website shows the confirmation, and a mail per submission would turn the form into a way to send mail to strangers.
- The InstaPay payee address shown on the landing page is the landing page's own configuration; this API serves plans and prices (already public via `GET /plans`), not the payee address.
- No refunds (out of scope by the brief and the MVP scope guard), no partial payments, no claims for a plan without an InstaPay price, no promo codes.
- Grace after an `instapay` billing period ends stays spec 009's `billing.grace_days` (3 days).
- Only successful (`2xx`) answers are stored for idempotent replay; a refused request stores nothing, so the client may fix it and retry with the same key.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Submit an InstaPay claim from the website (Priority: P1)

A visitor on the landing page picks Plus yearly, transfers the shown amount by InstaPay, and submits the form with their address, the plan, the amount, the InstaPay reference and a screenshot. The page confirms that the claim was received and will be reviewed within 7 days.

**Why this priority**: without a claim there is nothing to review and no code to redeem; it is the entry to the only non-Play payment path.

**Independent Test**: with a plan whose InstaPay price is 50,000 EGP minor units, submit a valid claim with a PNG screenshot from `X-Client-Platform: web`; it is `202`, a `submitted` claim exists with the address encrypted and the image stored encrypted; repeat with an unregistered address and observe the same status and body shape.

**Acceptance Scenarios**:

1. **Given** an active plan with an InstaPay price, **When** a web client submits a claim with matching amount and currency, a valid reference and `Idempotency-Key`, **Then** it is `202` with the claim's id, `status: submitted`, the plan, the amount and `expires_at` 7 days later, and the address is not echoed.
2. **Given** two submissions identical except that one address has an account and one does not, **When** both are submitted, **Then** the status, the body's members and the server's work are the same; nothing reveals which address is registered.
3. **Given** an amount different from the plan's InstaPay price, a currency other than the price's, a plan that is retired or has no InstaPay price, or a malformed reference, **When** submitted, **Then** it is `422 VALIDATION_FAILED` with one entry per offending member (`/amount_minor` `OUT_OF_RANGE`, `/currency` `CURRENCY_MISMATCH`, `/plan_id` `INVALID_ENUM_VALUE`, `/reference` `INVALID_TYPE`), and nothing is stored.
4. **Given** a screenshot over 3 MiB, of another type, or whose bytes are not the declared image type, **When** submitted, **Then** it is `422` at `/screenshot/data` or `/screenshot/media_type`, and nothing is stored.
5. **Given** the same `Idempotency-Key` and the same body, **When** resubmitted, **Then** the stored `202` is replayed with `Idempotency-Replayed: true` and no second claim exists; with a different body it is `409 IDEMPOTENCY_KEY_REUSED`.
6. **Given** a sixth valid submission from one origin within an hour, or a sixth for one address within 24 hours, **When** submitted, **Then** it is `429 RATE_LIMITED` with `Retry-After`.
7. **Given** `X-Client-Platform: android`, **When** the claim endpoint is called, **Then** it is `404 NOT_FOUND`.
8. **Given** a reference already carried by another claim, **When** submitted, **Then** it is accepted with the same `202`, and the reviewer sees both claims flagged as sharing that reference.

---

### User Story 2 - An operator approves or rejects a claim (Priority: P1)

An operator checks the InstaPay records for the reference and amount, then approves the claim (a code is mailed to the claimant) or rejects it with a reason (the claimant is told why).

**Why this priority**: review is what turns a payment into access; it must exist as a tested domain command before B12 builds its screen.

**Independent Test**: approve a submitted claim through the command as an operator; the claim is `approved`, a code hash and expiry exist, one mail with a code in the `XXXX-XXXX-XXXX-XXXX` form reaches the claim's address, and one `claim_reviewed` audit entry names the operator. Reject another with `amount_mismatch`; it is `rejected` and the mail states the reason.

**Acceptance Scenarios**:

1. **Given** a `submitted` claim before its expiry, **When** an operator approves it, **Then** in one unit of work the claim becomes `approved`, a Redeem Code valid for 30 days is issued (stored as a hash only), one `claim_reviewed` entry (outcome `approved`) is recorded with the operator as actor, and after commit a mail with the code goes to the claim's address in the claim's language.
2. **Given** a `submitted` claim, **When** an operator rejects it with reason `reference_not_found` and a note, **Then** it becomes `rejected`, the note is kept for operators only, one `claim_reviewed` entry (outcome `rejected`, reason) is recorded, and a mail stating the localised reason reaches the claimant.
3. **Given** a claim that is already approved, rejected or expired, or whose 7 days have passed, **When** an operator approves or rejects it, **Then** the command is refused and nothing changes.
4. **Given** another claim with the same reference already approved, **When** an operator approves this one, **Then** it is refused and nothing changes.
5. **Given** a claim whose plan was retired after submission, **When** an operator approves it, **Then** it is refused (the plan cannot be granted) and the operator may reject it instead.
6. **Given** an approved claim whose code is not redeemed, **When** an operator revokes the code with a reason, **Then** the code stops working, one `claim_reviewed` entry (outcome `code_revoked`) is recorded, and a redeemed code cannot be revoked.
7. **Given** a reject reason outside the fixed list, or a note over 500 characters, **When** the command runs, **Then** it is refused.
8. **Given** the dev environment, **When** an operator runs the approve command line with their operator id, **Then** the same command runs with the same effects (used by the C11 integration checkpoint).

---

### User Story 3 - Redeem the code in the app (Priority: P1)

The person opens the app's Plan screen, taps **Enter code**, types the code from the mail, and the plan applies at once.

**Why this priority**: it is the only in-app part of the flow and the paired client (C11) builds against it.

**Independent Test**: as a verified account whose address equals the claim's, redeem the mailed code with `Idempotency-Key`; it is `200` with the redeemed subscription (`provider: instapay`, the plan, the billing period), the governing subscription and the entitlements; `/me/entitlements` now answers the paid plan.

**Acceptance Scenarios**:

1. **Given** an issued, unexpired code and a verified account with the claim's address, **When** it is redeemed, **Then** one `instapay` subscription is created for the claim's plan starting now and ending one plan period later, the code becomes `redeemed`, one `entitlement_changed` entry (action `redeem`, actor the user) is recorded, `SubscriptionActivated` is published after commit, and the answer is `200` with `subscription`, `governing` and `entitlements`.
2. **Given** the code typed in lower case, without hyphens, or with `O` for `0`, **When** redeemed, **Then** it is recognised.
3. **Given** a string that is not 16 code characters after normalisation, **When** redeemed, **Then** it is `422 VALIDATION_FAILED` at `/code`; an unknown or revoked code is `404 NOT_FOUND`.
4. **Given** a code redeemed earlier by the same account, **When** redeemed again (with a new key), **Then** it is `200` with the same subscription; **given** it was redeemed by another account, **Then** it is `409 REDEEM_CODE_USED`.
5. **Given** a code past its 30 days, **When** redeemed, **Then** it is `410 REDEEM_CODE_EXPIRED` and nothing is created.
6. **Given** an account whose address differs from the claim's, **When** it redeems, **Then** it is `403 REDEEM_CODE_EMAIL_MISMATCH`, whose message asks the person to sign in with the address the code was sent to without naming it.
7. **Given** an unverified account with the claim's address, **When** it redeems, **Then** it is `403 EMAIL_NOT_VERIFIED`.
8. **Given** an eleventh attempt within an hour by one person, **When** redeemed, **Then** it is `429 RATE_LIMITED`.
9. **Given** the same key and body as a completed redemption, **When** resent, **Then** the stored answer is replayed with `Idempotency-Replayed: true`.

---

### User Story 4 - Overlap with Google Play and early renewal (Priority: P2)

A person who still has a Play monthly subscription redeems an InstaPay yearly code; later, they renew InstaPay a few days before the year ends.

**Why this priority**: overlapping channels must give one deterministic answer (spec 009) and renewing early must not cost days.

**Independent Test**: with a Play subscription ending in 20 days, redeem a yearly code; the yearly `instapay` subscription governs (it ends later), the Play one is unchanged, and the response's `governing` names the yearly plan. Then, 5 days before the yearly end, redeem another yearly code; the new subscription starts at the old one's end.

**Acceptance Scenarios**:

1. **Given** an active Play subscription ending before the redeemed period ends, **When** the code is redeemed, **Then** the Play subscription is untouched and the `instapay` one governs.
2. **Given** an active Play lifetime subscription, **When** a dated code is redeemed, **Then** the subscription is created and the Play lifetime one keeps governing; the response says so.
3. **Given** a granting `instapay` subscription ending in 5 days, **When** another code is redeemed, **Then** the new billing period starts at that end and lasts one plan period from there.
4. **Given** a granting Play or `manual` subscription ending in 5 days, **When** a code is redeemed, **Then** the new period starts now (only `instapay` periods chain).

---

### User Story 5 - Expiry, reminders and retention run on their own (Priority: P2)

Unreviewed claims close after 7 days; people are reminded 7 days before an InstaPay period ends; receipts and addresses disappear on schedule.

**Why this priority**: these keep the queue honest, give people a timely path to renew, and keep the privacy promise.

**Independent Test**: travel time 7 days past a submitted claim and run the expiry; it is `expired` with one notice mail. Create an `instapay` subscription ending in 6 days and run the reminder twice; one mail. Close a claim, travel 90 days and run the purge; the address, lookup value and receipt file are gone; travel 400 days; the claim row is gone.

**Acceptance Scenarios**:

1. **Given** a `submitted` claim older than 7 days, **When** the hourly expiry runs, **Then** it becomes `expired` and one notice mail is queued; a second run changes nothing.
2. **Given** a governing `instapay` subscription ending within 7 days with no later-ending granting subscription, **When** the daily reminder runs (any number of times), **Then** exactly one reminder mail goes to the account's address in its profile language with the configured renewal link.
3. **Given** a Play subscription, a lifetime subscription, an `instapay` subscription that does not govern, or one already reminded, **When** the reminder runs, **Then** no mail is sent for it.
4. **Given** a claim closed 90 days ago, **When** the daily purge runs, **Then** its encrypted address, lookup value and receipt file are erased; the claim's other facts remain.
5. **Given** a claim closed 400 days ago, **When** the daily purge runs, **Then** the claim and its code record are deleted.
6. **Given** idempotency records older than 24 hours, **When** the hourly purge runs, **Then** they are deleted.

---

### Edge Cases

- **Claim for a plan with no InstaPay price** (e.g. the free plan, or a Play-only plan): `422` at `/plan_id`.
- **Plan price changed between page load and submission**: the amount no longer equals the price → `422` at `/amount_minor`; the website reloads the plan.
- **Two concurrent approvals of the same claim**: exactly one succeeds; the other is refused (row lock and status check).
- **Two concurrent approvals of different claims with the same reference**: at most one succeeds (unique rule on approved references).
- **Two concurrent redemptions of one code by two accounts with the same address** (impossible: an address belongs to one account) or **by the same account with two keys**: exactly one subscription is created (row lock on the code, unique `provider_ref` per claim); the other answers `200` with the same subscription.
- **Account registered after the code was mailed**: redeemable once the address is verified, within the 30 days.
- **Account deleted (purged) after redemption**: the subscription goes with the account per B15; the claim keeps no address after its 90 days.
- **Account changes its address after the claim**: the code is bound to the claim's address; the person must redeem from an account holding that address.
- **Address change requested but not yet verified**: comparison uses the account's current (verified) address lookup.
- **Claim approved, code expires unredeemed**: the claim closes (for retention) 30 days after approval; an operator may handle a support request by an operator grant (spec 009) — no automatic re-issue.
- **Receipt link opened after 5 minutes, or by someone not signed in as an operator**: refused.
- **Receipt image after its 90 days**: the review query reports no image.
- **Mail delivery failure**: the queued job retries per the queue's policy; the claim's state does not depend on delivery.
- **An `instapay` subscription redeemed for a lifetime plan**: no end, no reminder, and it governs from then on.
- **Redeeming while the account has a pending deletion**: allowed (writes are allowed during the grace period).

## Requirements *(mandatory)*

### Functional Requirements

**Claim submission (website only)**

- **FR-001**: The system MUST accept InstaPay claims at `POST /api/v1/billing/instapay/claims` without a bearer credential, and MUST answer `404 NOT_FOUND` to any caller whose `X-Client-Platform` is not `web`.
- **FR-002**: Claim submission MUST require `Idempotency-Key` and follow api-conventions' idempotency rules, with every claim key held in one anonymous scope. Only `2xx` answers are stored for replay; a refused request releases its key; a request still `in_progress` after 60 seconds is treated as abandoned and may be taken over by a retry with the same key and body.
- **FR-003**: A claim MUST carry `email`, `plan_id`, `amount_minor`, `currency` and `reference`, and MAY carry `screenshot {media_type, data}`; any other member is refused as `UNKNOWN_FIELD`.
- **FR-004**: `plan_id` MUST name an active plan that has an `instapay` price; otherwise `/plan_id` `INVALID_ENUM_VALUE`.
- **FR-005**: `currency` MUST equal that price's currency (`/currency` `CURRENCY_MISMATCH`) and `amount_minor` MUST equal its amount exactly (`/amount_minor` `OUT_OF_RANGE`); non-integer amounts are `AMOUNT_NOT_INTEGER`.
- **FR-006**: `reference` MUST be normalised (spaces and hyphens removed, upper-cased) and then match 8–32 characters of `A–Z0–9` (`/reference` `INVALID_TYPE`); the normalised form is what is stored and compared.
- **FR-007**: `email` MUST be a syntactically valid address of at most 254 characters (`/email` `INVALID_TYPE` or `OUT_OF_RANGE`), normalised exactly as at registration (security baseline, Normalisation).
- **FR-008**: The answer to a valid claim MUST be `202` with `{id, status, plan, amount, expires_at}`, MUST NOT echo the address, and MUST NOT differ in status, members or work done according to whether the address belongs to an account; submission MUST NOT look the address up.
- **FR-008a**: A claim MUST store the language resolved from the request (`ar` or `en`), and every mail about the claim MUST use it.
- **FR-009**: A claim whose address has no account MUST be stored and reviewed like any other; the operator-only single-claim detail MUST report whether an account currently holds the address, and the outcome and its mail MUST NOT depend on it.
- **FR-010**: Valid submissions MUST be limited to 5 per hour per origin and 5 per 24 hours per address lookup value, refused with `429 RATE_LIMITED` and `Retry-After`; replays MUST NOT count.
- **FR-011**: A claim whose normalised reference is already on another claim MUST be accepted with the same answer; the review query MUST list the other claims sharing it.
- **FR-012**: A screenshot MUST be `image/jpeg`, `image/png` or `image/webp` (`/screenshot/media_type` `INVALID_ENUM_VALUE`), valid base64 decoding to at most 3 MiB (`/screenshot/data` `OUT_OF_RANGE`) whose bytes match the declared type and parse as an image (`/screenshot/data` `INVALID_TYPE`).
- **FR-013**: The receipt image MUST be stored encrypted on the private filesystem disk under a server-chosen path, and MUST NOT be returned by any `/api/v1` endpoint.
- **FR-014**: An operator MUST be able to view a receipt only through a signed link that expires 5 minutes after issue, served behind the operator guard with `Cache-Control: no-store`.
- **FR-014a**: Each receipt view MUST record one `admin_action` entry (`claim_receipt_viewed`, subject the claim) written by Admin before the image is streamed.

**Expiry and review (operator-only domain commands)**

- **FR-015**: A claim MUST be `submitted` → `approved` | `rejected` | `expired`, and no other transition.
- **FR-016**: A `submitted` claim MUST become `expired` 7 days after submission through an hourly, idempotent sweep that queues one notice mail; approve and reject MUST refuse a claim past its expiry whether or not the sweep has run.
- **FR-017**: Approve MUST take an operator id and a claim id, MUST refuse unless the claim is `submitted` and unexpired, and MUST, in one unit of work, mark it `approved`, issue its Redeem Code and record one `claim_reviewed` audit entry; the code mail MUST be queued after commit.
- **FR-018**: Reject MUST take an operator id, a claim id, a reason from {`reference_not_found`, `amount_mismatch`, `duplicate`, `other`} and an optional note of at most 500 characters; it MUST mark the claim `rejected`, record one `claim_reviewed` entry, and queue a mail with the localised reason (never the note).
- **FR-019**: Approve MUST refuse when the claim's plan is no longer active or no longer has an InstaPay price.
- **FR-020**: Every review command MUST lock the claim row so that concurrent commands on one claim apply at most once.
- **FR-021**: At most one claim per normalised reference MUST ever be `approved`; approval of another MUST be refused.
- **FR-024**: Revoke code MUST take an operator id, a claim id and a reason (1–500 characters), MUST refuse unless the claim's code is `issued`, and MUST mark the code `revoked` and record one `claim_reviewed` entry (outcome `code_revoked`).
- **FR-025**: Approve, reject and revoke MUST be callable from the command line for an active operator (the CLI names the operator id), and B12 screens MUST call the same commands behind a second-factor proof from the last 10 minutes.

**Redeem codes and redemption (in-app "Enter code")**

- **FR-022**: A Redeem Code MUST be 16 characters of Crockford base32 (80 bits of randomness), shown as four groups of four joined by hyphens; only its SHA-256 hash MUST be stored; it MUST be valid for 30 days from approval.
- **FR-022a**: A daily, idempotent sweep MUST mark `issued` codes past their expiry as `expired` and close their claims; redemption MUST check the expiry itself regardless of the sweep.
- **FR-023**: Redemption MUST accept a code case-insensitively, with or without hyphens and spaces, folding `O`→`0` and `I`/`L`→`1`; anything else that is not 16 code characters MUST be `422` at `/code` `INVALID_TYPE`.
- **FR-026**: `POST /api/v1/billing/redeem` MUST require a bearer credential and `Idempotency-Key`, and MUST answer, in this order: unknown or revoked code `404 NOT_FOUND`; redeemed by this account `200` (same result); redeemed by another `409 REDEEM_CODE_USED`; past validity `410 REDEEM_CODE_EXPIRED`; the caller's address lookup differs from the claim's `403 REDEEM_CODE_EMAIL_MISMATCH`; the caller's address unverified `403 EMAIL_NOT_VERIFIED`; else redeem.
- **FR-027**: No redemption answer MUST name or hint at the claim's address, the claim, or another account.
- **FR-028**: Redemption MUST create exactly one subscription with provider `instapay`, `provider_ref` the claim id, status `active`, `auto_renew` false, for the claim's plan, and mark the code `redeemed` with the account and subscription — in one unit of work.
- **FR-029**: The billing period MUST start at the later of now and the latest `current_period_end` among the account's granting `instapay` subscriptions with a dated end, and end one plan period later (monthly: one calendar month without overflow; yearly: one calendar year; custom: `period_days` days; lifetime: no end).
- **FR-030**: Redemption MUST NOT change any other subscription; which subscription governs MUST follow spec 009's rule (ADR-0013 rule 14).
- **FR-031**: The `200` answer MUST carry `subscription` (the redeemed one), `governing` (as `/me/subscription` would answer) and `entitlements` (as `/me/entitlements` would answer).
- **FR-032**: Concurrent or repeated redemptions of one code MUST create at most one subscription.
- **FR-033**: Redemption attempts MUST be limited to 10 per hour per person (`429 RATE_LIMITED`), counted after format validation; replays MUST NOT count.

**Renewal reminder**

- **FR-034**: A daily run MUST mail each `instapay` subscription's account once when its `current_period_end` falls within the next 7 days, it is the governing subscription, and no other granting subscription of the account ends later; the reminder MUST be recorded so it is never sent twice for that subscription.
- **FR-035**: The reminder MUST go to the account's own address in its profile language and carry the configured renewal link.

**Audit, events, logging**

- **FR-036**: Approve, reject and revoke MUST each record exactly one `claim_reviewed` audit entry (actor `operator`, subject `payment_claim`, detail `{outcome, reason}`, `reason` present only for a rejection (the reject reason; audit details omit null members, as every other detail does); free-text notes and revoke reasons never enter it), and redemption exactly one `entitlement_changed` entry (actor `user`, subject `subscription`, action `redeem`); no entry may contain an address, reference, amount, code or receipt content.
- **FR-037**: Redemption MUST publish `SubscriptionActivated` after commit; mails MUST be queued after commit; jobs carrying a code MUST be encrypted on the queue.
- **FR-045**: Billing's review query MUST return a claim's address only from its single-claim detail, and B12 MUST record one `admin_action` (`claim_address_read`) per such read; the claim list MUST carry no address.
- **FR-040**: No log line, error report or queued payload in clear MUST contain a receipt image, a Redeem Code, or a claim's address; request bodies of the two endpoints MUST NOT be logged. Because the approval mail carries the code, the `log` and `array` mail transports MUST NOT be configured outside the `local` and `testing` environments (the application refuses to boot otherwise).

**Retention and protection at rest**

- **FR-038**: A claim's address MUST be encrypted under Billing's keyring with the key identifier stored beside it, with the shared keyed lookup value beside it.
- **FR-039**: A claim's address, lookup value and receipt image MUST be erased 90 days after the claim closes (rejected; expired; approved and its code redeemed, expired or revoked); the claim and code records MUST be deleted 400 days after closing; idempotency records MUST be deleted after 24 hours.

**Documents and contract**

- **FR-041**: The security baseline MUST gain the claim rows (protected attributes, route budgets, uniform answers, retention, the `claim_reviewed` event and `payment_claim` subject, sensitive acts), and the privacy commitments the receipt retention, in English and Arabic.
- **FR-042**: The contract MUST be frozen in `docs/openapi/paths/instapay.yaml`, indexed from `openapi.yaml`, with examples; `info.version` MUST become v1.12; three response codes are added: `REDEEM_CODE_USED` (409), `REDEEM_CODE_EXPIRED` (410), `REDEEM_CODE_EMAIL_MISMATCH` (403).
- **FR-043**: ADR-0023 MUST record the claim-bound code, the subscription at redemption, public web-only submission, and its relation to ADR-0013 rules 2, 4 and 12; the constitution's P-09 and P-10 MUST list it (PATCH).
- **FR-044**: The feature MUST NOT add a composer or npm dependency.

### Key Entities

- **Payment Claim** (Plans & Billing): one visitor's statement that they paid a plan's InstaPay price — plan, amount and currency, normalised reference, encrypted address with lookup value, language, optional encrypted receipt image, status (`submitted`, `approved`, `rejected`, `expired`), expiry, review facts (operator, time, reject reason, internal note), closing time and erasure time. Not user-scoped: it has an address, not an account.
- **Redeem Code** (Plans & Billing): the single-use secret an approved claim issues — hash, expiry, status (`issued`, `redeemed`, `revoked`, `expired`), and on redemption the account and the subscription it created. One per approved claim.
- **Subscription** (existing, spec 009): gains the `instapay` rows created by redemption and a record of the renewal reminder sent for it.
- **Idempotency Record** (Plans & Billing, operational): one stored answer per (scope, key) for 24 hours — fingerprint of method, path and canonical body, and the completed status and body.

## Project Sections *(mandatory for feature specs)*

### 1. Purpose

Let a person in Egypt pay for a plan by InstaPay from the website, have an operator confirm the payment, and receive the plan in the app by typing a code — without the app ever offering an alternative payment, without the claim form revealing who has an account, and with receipts and addresses kept only as long as review and disputes need them.

### 2. Glossary terms

[Payment Claim](../../docs/glossary.md#payment-claim), [Redeem Code](../../docs/glossary.md#redeem-code), [Subscription](../../docs/glossary.md#subscription) (governing subscription, billing period, `provider`), [Plan](../../docs/glossary.md#plan) (price, channel), [Entitlement](../../docs/glossary.md#entitlement), [User](../../docs/glossary.md#user), Operator Account (spec 018). **Changed in this change** (before use in code): Payment Claim states become `submitted` → `approved` | `rejected` | `expired` (`under_review` removed: review is one act in MVP) and its notes state public web-only submission, uniform answer and retention; Redeem Code notes state the format, binding to the claim's address, 30-day validity, and that a claim-backed code creates an `instapay` subscription (ADR-0023); **Idempotency Record** is added.

### 3. Data model

Owned by Billing (`app/Modules/Billing/Infrastructure/Database/Migrations`). Detail in [data-model.md](./data-model.md).

- `payment_claims` — `id` (UUIDv7), `plan_id` → `plans`, `amount_minor` (bigint), `currency` (char 3), `reference` (varchar 32, normalised), `address_sealed` (text, null once erased), `address_lookup` (varchar 64, null once erased), `locale` (`en` | `ar`), `receipt_path`, `receipt_media_type`, `receipt_bytes` (all null without a receipt or once erased), `status` (`submitted` | `approved` | `rejected` | `expired`), `expires_at`, `reviewed_by` (operator UUID), `reviewed_at`, `reject_reason`, `review_note`, `closed_at`, `erased_at`, timestamps. Not user-scoped, not syncable.
- `redeem_codes` — `id`, `claim_id` (unique) → `payment_claims`, `code_hash` (char 64, unique), `status` (`issued` | `redeemed` | `revoked` | `expired`), `expires_at`, `redeemed_by` (user UUID), `redeemed_at`, `subscription_id`, `revoked_at`, timestamps.
- `subscriptions` (spec 009) — gains `renewal_reminded_at` (nullable instant).
- `idempotency_records` — `id`, `scope` (`user:<uuid>` | `anonymous`), `key`, `fingerprint` (char 64), `state` (`in_progress` | `completed`), `response_status`, `response_body`, `created_at`, `expires_at`; unique (`scope`, `key`).
- Identity's `audit_entries` CHECK gains subject type `payment_claim` (Identity migration, as spec 018 added `operator`).

No float or decimal column (P-03).

### 4. Invariants

| Invariant | Principle or decision | Test that fails when broken |
|---|---|---|
| Submission answers identically whether or not the address is registered, and performs no address lookup | Baseline, Uniform answers; FR-008 | `tests/Contract/Billing/SubmitClaimTest.php` (registered vs unregistered compare), `tests/Feature/Billing/Claims/SubmitClaimTest.php` (lookup port never called) |
| The claim endpoint does not exist for the Android app | P-10 rule 3; FR-001 | `tests/Contract/Billing/SubmitClaimTest.php` (android → 404) |
| Amount equals the plan's InstaPay price exactly | FR-005; P-03 | `tests/Unit/Billing/Claims/ClaimPriceRuleTest.php` |
| At most one approved claim per reference | FR-021 | `tests/Feature/Billing/Claims/ReviewClaimTest.php` (sequential and concurrent) |
| A claim is reviewed at most once; expired claims cannot be reviewed | FR-015–FR-017, FR-020 | `tests/Feature/Billing/Claims/ReviewClaimTest.php` |
| A code is stored only as a hash; plaintext exists only in the mail | Baseline (tokens: hash); FR-022 | `tests/Feature/Billing/Claims/ReviewClaimTest.php` (no column holds the code), `tests/Unit/Billing/RedeemCodes/RedeemCodeFormatTest.php` |
| One code → at most one subscription | FR-028, FR-032 | `tests/Feature/Billing/Redeem/RedeemCodeTest.php` (repeat, two keys, concurrent) |
| Redeemable only by a verified account with the claim's address | FR-026 | `tests/Feature/Billing/Redeem/RedeemCodeTest.php`, `tests/Contract/Billing/RedeemTest.php` |
| Redemption never changes another subscription; governing follows spec 009 | ADR-0013 rule 14; FR-030 | `tests/Feature/Billing/Redeem/RedeemOverlapTest.php` |
| `instapay` periods chain; other channels do not | FR-029 | `tests/Unit/Billing/RedeemCodes/RedeemedPeriodTest.php` |
| Idempotent replay returns the stored answer; different body is 409 | api-conventions; FR-002 | `tests/Feature/Billing/Idempotency/IdempotencyTest.php`, `tests/Contract/Billing/SubmitClaimTest.php` |
| Exactly one audit entry per review act and per redemption, with nothing excluded inside | Baseline, What is recorded; FR-036 | `tests/Feature/Billing/Claims/ReviewClaimTest.php`, `tests/Feature/Billing/Redeem/RedeemCodeTest.php` |
| No receipt, code or address in logs | P-15 rule 4; FR-040 | `tests/Feature/Billing/Claims/ClaimLoggingTest.php` |
| One reminder per subscription | FR-034 | `tests/Feature/Billing/Reminders/RenewalReminderTest.php` |
| Personal data erased at 90 days, records at 400 days | FR-039 | `tests/Feature/Billing/Claims/ClaimRetentionTest.php` |
| Receipt link valid 5 minutes and only for operators; each view recorded | FR-014, FR-014a | `tests/Feature/Admin/ClaimReceiptRouteTest.php` |
| Codes past expiry are stored as expired and close their claim | FR-022a | `tests/Feature/Billing/Redeem/ExpireCodesTest.php` |

### 5. API

Under `/api/v1`, in `docs/openapi/paths/instapay.yaml`, tag `Plans & Billing`. **Frozen for the C11 client and the landing page** (contract first); `info.version` v1.11 → v1.12 (additive).

| Method | Path | operationId | Auth | Success | Error codes (beyond the shared 400 header refusals, 406, 415, 426, 500) |
|---|---|---|---|---|---|
| POST | `/billing/instapay/claims` | `submitInstapayClaim` | none (`security: []`), `X-Client-Platform: web` | `202` | `IDEMPOTENCY_KEY_REQUIRED`, `IDEMPOTENCY_KEY_INVALID`, `MALFORMED_JSON`, `NOT_FOUND` (non-web platform), `IDEMPOTENCY_KEY_REUSED`, `IDEMPOTENCY_REQUEST_IN_PROGRESS`, `VALIDATION_FAILED`, `RATE_LIMITED` |
| POST | `/billing/redeem` | `redeemCode` | bearer, any platform | `200` | `IDEMPOTENCY_KEY_REQUIRED`, `IDEMPOTENCY_KEY_INVALID`, `MALFORMED_JSON`, `UNAUTHENTICATED`, `EMAIL_NOT_VERIFIED`, **`REDEEM_CODE_EMAIL_MISMATCH`** (403), `NOT_FOUND`, **`REDEEM_CODE_USED`** (409), `IDEMPOTENCY_KEY_REUSED`, `IDEMPOTENCY_REQUEST_IN_PROGRESS`, **`REDEEM_CODE_EXPIRED`** (410), `VALIDATION_FAILED`, `RATE_LIMITED` |

**Claim request** `{ email, plan_id, amount_minor, currency, reference, screenshot? {media_type, data} }`. **Claim answer** `202 { data: { id, status: "submitted", plan: PlanRef, amount: Money, expires_at }, meta: {} }`.

**Redeem request** `{ code }`. **Redeem answer** `200 { data: { subscription: { id, plan: PlanRef, provider: "instapay", status, current_period_start, current_period_end }, governing: MySubscription, entitlements: EntitlementSummary }, meta: {} }`.

**Answers C11 needs** (frozen):

| C11 asks | Answer |
|---|---|
| Code format, for input masking | 16 characters, Crockford base32 (`0-9`, `A-Z` without `I`, `L`, `O`, `U`), shown `XXXX-XXXX-XXXX-XXXX`. The server accepts lower case, missing hyphens, spaces, and folds `O`→`0`, `I`/`L`→`1`; mask to 16 alphanumerics in groups of four |
| Expired | `410 REDEEM_CODE_EXPIRED` — "This code has expired. Contact support." Never retry |
| Used | `409 REDEEM_CODE_USED` — used by another account. The same account re-entering its own code gets `200` with the same subscription |
| Email mismatch | `403 REDEEM_CODE_EMAIL_MISMATCH` — "Sign in with the email address this code was sent to." The address is never named |
| Unknown | `404 NOT_FOUND` (also a revoked code) |
| Unverified | `403 EMAIL_NOT_VERIFIED` — offer to resend verification |
| Rate limit | `429 RATE_LIMITED` with `Retry-After`; 10 attempts per hour per person |
| Malformed | `422 VALIDATION_FAILED`, pointer `/code`, field code `INVALID_TYPE` |
| Response | `subscription` (redeemed), `governing` (the plan that now governs — show it), `entitlements` (refresh gates from it) |
| Idempotency | `Idempotency-Key` required; same key + body replays with `Idempotency-Replayed: true` |

### 6. Authorization and entitlements

Claim submission is public and web-only; its protection is validation, rate limits and the uniform answer. Redemption needs a signed-in account, its address verified and equal to the claim's; no wallet is involved, so the Wallets matrix does not apply. Approve, reject and revoke are operator-only: no application credential reaches them (no HTTP route in `/api/v1`), B12 calls them from operator-guarded screens behind a fresh second factor, and the CLI requires an active operator id. No entitlement gates any of it (P-09 rule 5). The receipt route sits under `/admin`, behind the operator guard and a signature (P-18 rule 4).

### 7. Events

Published: `SubscriptionActivated` (existing; on redemption: event id, occurred_at, user id, subscription id, plan id). No new domain event: the approval, rejection, expiry and reminder mails are queued directly after commit (spec 016's pattern), and nothing in another module needs to react. Consumed: none.

### 8. Acceptance scenarios

| Story · scenario | Layer | Test file |
|---|---|---|
| US1 · 1–8 submission | Unit, Feature, Contract | `tests/Unit/Billing/Claims/ClaimPriceRuleTest.php`, `tests/Unit/Billing/Claims/ReferenceTest.php`, `tests/Unit/Billing/Claims/ReceiptImageTest.php`, `tests/Feature/Billing/Claims/SubmitClaimTest.php`, `tests/Contract/Billing/SubmitClaimTest.php` |
| US2 · 1–7 review | Feature | `tests/Feature/Billing/Claims/ReviewClaimTest.php`, `tests/Feature/Billing/Claims/ClaimMailTest.php` |
| US2 · 8 CLI | Feature | `tests/Feature/Billing/Claims/ClaimCommandsTest.php` |
| US3 · 1–9 redemption | Unit, Feature, Contract | `tests/Unit/Billing/RedeemCodes/RedeemCodeFormatTest.php`, `tests/Feature/Billing/Redeem/RedeemCodeTest.php`, `tests/Contract/Billing/RedeemTest.php` |
| US4 · 1–4 overlap | Unit, Feature | `tests/Unit/Billing/RedeemCodes/RedeemedPeriodTest.php`, `tests/Feature/Billing/Redeem/RedeemOverlapTest.php` |
| US5 · 1 expiry | Feature | `tests/Feature/Billing/Claims/ExpireClaimsTest.php` |
| US5 · 2–3 reminder | Feature | `tests/Feature/Billing/Reminders/RenewalReminderTest.php` |
| US5 · 4–6 retention | Feature | `tests/Feature/Billing/Claims/ClaimRetentionTest.php`, `tests/Feature/Billing/Idempotency/IdempotencyTest.php` |

### 9. Open questions

1. **Bot challenge on the claim form** (captcha) — needs a third-party service and owner approval; rate limits stand in. Does not block.
2. **Claim record horizon for accounting** — 400 days chosen to match the audit horizon; if tax records need longer (e.g. 5 years), raise the number in the baseline. Does not block.
3. **Re-issuing an expired code** — not offered; support uses an operator grant (spec 009). B12 may add it. Does not block.
4. **Serving the InstaPay payee address from the API** — the landing page holds it as configuration for now. Does not block.
5. **Stripping image metadata (EXIF) from receipts** — would need an image library; receipts are encrypted, operator-only and short-lived instead. Does not block.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For 100% of test pairs, a claim from a registered and an unregistered address produce the same status and the same set of response members, and the submission path performs zero address lookups.
- **SC-002**: 0 subscriptions are created without a redeemed code, and 0 codes produce more than one subscription, including under concurrent redemption.
- **SC-003**: A visitor completes the claim form in under 2 minutes after transferring, and a person with a mailed code has the paid plan in the app within 1 minute of opening the mail.
- **SC-004**: 100% of `submitted` claims older than 7 days are `expired` within one hour, and 100% of eligible `instapay` subscriptions receive exactly one reminder.
- **SC-005**: 0 log lines, audit entries or queued payloads in clear contain a receipt image, a code or a claim address.
- **SC-006**: 100% of receipt images and claim addresses are gone within 24 hours of their 90-day horizon.
- **SC-007**: On the development stack with debugging extensions off, claim submission with a 3 MiB receipt answers in under 800 ms and redemption in under 300 ms at the 95th percentile.
- **SC-008**: The C11 integration checkpoint passes: a claim approved from the command line, the code read from the dev mail log, entered in the app, and the paid plan shown.

## Assumptions

- Spec 009's plans, prices (`channel: instapay`), subscriptions, `GoverningSubscription`, `EntitlementService`, `MySubscription` and lapse job are reused, not wrapped; `billing.grace_days` applies to `instapay` billing periods.
- Spec 007's address normalisation, keyed lookup value, verification state and profile language are reached through an Identity application service, as spec 016's `InvitationAddresses` does.
- Spec 018's operator directory confirms an active operator id for the CLI; B12 builds the review screens on `RequiresRecentFactor`.
- The landing page is a separate project; it sends `X-Client-Platform: web`, an `X-Client-Version`, `Accept-Language` and an `Idempotency-Key`, and shows the InstaPay payee address from its own configuration.
- Mail goes through the existing mail setup (Mailpit in dev); queues and the scheduler run as for specs 016 and 018.
- **Deployment prerequisites**: trusted proxies are configured so the per-origin budget sees the visitor's address, not the proxy's (until then every visitor shares one budget); the edge accepts request bodies of at least 5 MB on the claim path.
- **Cross-origin calls**: if the landing page calls the API from the browser, the framework's default cross-origin settings (any origin on `api/*`) apply; the claim endpoint is public and carries no credential, so nothing is gained by narrowing it now. A deployment may restrict origins by configuration.
- **Account deletion (B15)**: a redeemer's user id stays on the code record as an identifier only (no personal data) until the claim's 400-day delete; the subscription it created follows B15's rules.
- Seeder: N/A — the existing plan seeder already gives Plus monthly (14,900) and Plus yearly (129,000 EGP minor units) their InstaPay prices; no new reference data.
