# Feature Specification: Billing — Google Play

**Feature Branch**: `024-billing-google-play` (trunk-based; work lands on `main`)

**Created**: 2026-10-06

**Status**: Draft

**Input**: Brief B10 ([specs/briefs/B10-billing-google-play.md](../briefs/B10-billing-google-play.md)): "The Android app purchases a subscription or lifetime product through Play Billing and posts the purchase token to `POST /billing/google/purchases`; the backend verifies it with the Play Developer API, maps `productId` → `plan_prices.provider_product_id`, creates/updates the subscription, acknowledges the purchase, and returns the new entitlements. Real-time Developer Notifications arrive via Pub/Sub push to `POST /webhooks/google-play` and drive the state machine (purchased, renewed, in grace, on hold, paused, canceled, expired, revoked). `POST /billing/google/restore` accepts a list of tokens after login and links any that belong to no user yet." Plus its considerations (verify the Pub/Sub JWT; store the notification in an inbox before processing; idempotent processing by `purchaseToken + notificationType + eventTime`; `PURCHASE_ALREADY_LINKED`; acknowledge within 3 days, in the verification request; lifetime is a non-consumable one-time product; service-account credential outside the repo; license testers and a fake client for the suite) and its reconcile table. Paired with the mobile brief C10 ([saveme-kmp/specs/briefs/C10-billing-google-play.md](../../../saveme-kmp/specs/briefs/C10-billing-google-play.md)), whose "Needs from the backend" — request and response shapes, `PURCHASE_ALREADY_LINKED` and the verification error codes, and who acknowledges — this spec freezes in section 5.

> **Nature of this feature.** Google Play is the only way to pay for a plan inside the app (P-10). The phone buys through Play Billing; this feature is the server half. It checks every purchase with Google before granting anything, ties each purchase to exactly one account, acknowledges it so Google does not refund it, and then keeps the subscription in step with Google — renewals, grace, account hold, pauses, cancellations, expiry, refunds — for as long as it lives. **Google Play is the source of truth for a Google Play subscription**: the server never decides on its own that a Play purchase is paid, renewed or over; it asks Google, and it records what Google says in the one subscription record every channel shares (ADR-0013 rule 3).
>
> **Where the brief and the ratified decisions differ, the ratified side wins unless this spec supersedes it:**
>
> | Brief | In force | Here |
> |---|---|---|
> | "Play Developer API client, Pub/Sub JWT verification" (implying the Google SDK) | No Google package is installed; CONTRIBUTING item 17 and Quality Gate 6: no new dependency without the owner's approval. **The owner has approved none.** | **No new dependency.** Every call to Google goes through a **`PlayBillingGateway` port** whose production adapter calls the Google Play Developer API (`androidpublisher` v3) with the framework's HTTP client, authenticated by an OAuth 2.0 access token obtained by signing a service-account JWT (RS256) with PHP's bundled `openssl` functions and cached until shortly before it expires — the same pattern spec 023 uses for FCM. The Pub/Sub push token is verified in-house too (Google's public certificates fetched over HTTPS and cached; RS256 verified with `openssl`; issuer, audience, service-account email and time checked). **Adopting the official SDK later is a drop-in swap of one adapter** (FR-060) |
> | Webhook verification | Security baseline, *Messages from the payment provider*: all five checks — signature, issuer, **audience**, issued-at within five minutes, message id not seen in 24 hours; a repeat is acknowledged without effect | Followed exactly, plus two Google-specific checks (the push service account's email, the Pub/Sub subscription name) and the package name (FR-030–FR-035) |
> | `PURCHASE_ALREADY_LINKED` | Not in the catalogue | Added to `error-catalog.yaml` (409), with **`PURCHASE_NOT_VERIFIED`** (422); `info.version` **v1.15** (B16 takes v1.14) |
> | `POST /billing/google/purchases` and restore | P-10: the in-app surface is restore and enter-code; spec 003: Idempotency-Key is reserved for three operations, one of them "Google Play purchase acknowledgement" | Both endpoints **are** that operation (they verify and acknowledge a Play purchase); both **require `Idempotency-Key`** and reuse spec 020's store and middleware. Neither starts or records a payment outside Play: the payment already happened in Play Billing (FR-003). **Reading of P-10 rule 2 / ADR-0013 rule 11, recorded in ADR-0026**: verifying one purchase is the single-item form of "restore purchases" (both hand the server a Play purchase to honour); `GET /billing/google/account-reference` is a read that starts nothing. No other in-app billing write exists |
> | `productId` → `plan_prices.provider_product_id` | Spec 009: a plan's `prices` list carries `{channel, product_id, amount_minor, currency}`; spec 021: Google Play prices are **read-only** in the back office | A plan's `google_play` price entry carries the Play **product id**; the mapping is **seeded create-missing from configuration** and never edited in the back office in MVP (FR-040–FR-043) |
> | "Store the raw notification in an inbox" | Security baseline and P-15: purchase tokens are never logged; protected attributes are sealed | The inbox keeps the **parsed** notification with the **purchase token sealed** and a keyed lookup value beside it; the raw push body is not kept (FR-034) |
> | "Idempotent by `purchaseToken + notificationType + eventTime`" | Baseline: replay by message id within 24 hours | **Both**: a repeated message id is acknowledged without effect; and processing a notification always re-reads the purchase from Google and applies its current state, so any order or repetition converges on the same record (FR-036, FR-037) |
> | Spec 022: "Google Play has no server-side cancel in this backend (B10 not built)"; a `google_play` subscription is never changed at the purge | Spec 022 FR-047, privacy commitments ("Deleting your account does not cancel a Google Play subscription") | **Unchanged.** The purge still leaves `google_play` subscriptions alone and the mails keep saying "cancel in Google Play". Cancelling auto-renew at the purge is now technically possible and is left to the owner (section 9, Q2) |
> | Spec 021: operators cannot extend or revoke `google_play` | FR-029 there | **Unchanged.** Operators see Play subscriptions and counts; Google is the only one who ends them (FR-052) |

## Clarifications

### Session 2026-10-06

Owner away with delegated authority; each answer is the recommended (most conservative) option, recorded as an **assumed default**.

- Q: How does the server talk to Google without the SDK the owner has not approved? → A: **Through a `PlayBillingGateway` port** in Billing's domain, with three adapters: `google` (the Play Developer API over the framework's HTTP client; OAuth 2.0 service-account token signed RS256 with `openssl_sign`, cached until 5 minutes before expiry; scope `androidpublisher`), `fake` (in-memory, scripted purchases — for tests and local work), and `disabled` (every call answers "provider unavailable"). `fake` is **refused at boot outside `local` and `testing`**, as Billing refuses the `log` and `array` mailers and spec 023 refuses its `log` push adapter; `disabled` is allowed everywhere because it grants nothing. *Assumed default.* (FR-056–FR-061)
- Q: Which paths? → A: The brief's: **`POST /billing/google/purchases`** (verify one purchase), **`POST /billing/google/restore`** (verify several), **`POST /webhooks/google-play`** (Pub/Sub push), plus one read the app needs before it buys, **`GET /billing/google/account-reference`**. The C10 brief already consumes the first two by name. *Assumed default.* (section 5)
- Q: How is a purchase tied to an account, and what stops someone else's token being claimed? → A: **The Play Account Reference.** Before buying, the app reads an opaque per-account reference (a keyed hash of the person's id, 64 hex characters) and passes it to Play Billing as the obfuscated account id. Google returns it with the purchase. A purchase that carries a reference is linked **only** to the account it names; a purchase without one (bought before this existed, or by an older app) is linked to the first account that verifies it. One purchase token is linked to **exactly one account**, forever. *Assumed default.* (FR-005–FR-009)
- Q: A token already linked to another account? → A: **`409 PURCHASE_ALREADY_LINKED`**, nothing granted, nothing disclosed about the other account. The same answer when the purchase's reference names another account. The app tells the person to sign in with the account that bought it. *Assumed default.* (FR-008)
- Q: Who acknowledges, and when? → A: **The server, always, in the same request that verifies and links the purchase**, after the subscription is stored. The client **never acknowledges**. If Google cannot be reached for the acknowledgement, the grant still stands (Google already confirmed the purchase), the answer says `acknowledged: false`, and a queued retry plus an hourly sweep keep trying well inside Google's 3-day window. A purchase that is not linked to an account is **never** acknowledged — Google refunds it after 3 days, which is the right outcome for a purchase nobody can use. *Assumed default.* (FR-012–FR-015)
- Q: A pending purchase (slow payment method)? → A: **`202` with `purchase.state: pending`**, entitlements unchanged, nothing acknowledged. The purchase is remembered for the account; when Google reports it purchased (by notification, by a later verify or restore, or by the hourly sweep), it is linked, granted and acknowledged then. *Assumed default.* (FR-016)
- Q: Which Play states map to which local statuses? → A: **Onto ADR-0013's machine, never beside it:** purchased/renewed/recovered/restarted → `active` (period from Google); in grace → `grace` with `grace_until` = Google's grace end; **account hold and paused → no access**: the subscription moves `active → grace → expired` in one step with a zero-length grace (the machine has no `active → expired` arc), and back to `active` on recovery or resume; canceled → stays `active` with `auto_renew = false` until the period ends; expired → `expired` (through the same zero-length grace hop when still `active`); revoked or voided (refund, chargeback) → `revoked`. *Assumed default.* (FR-020–FR-027)
- Q: How long is grace for a Play subscription? → A: **Google's.** `grace_until` is the grace end Google reports; the server's own `billing.grace_days` never applies to `google_play`. A Play subscription Google reports as expired gets **no** extra days from us. *Assumed default.* (FR-022)
- Q: Restore purchases — what does it do? → A: The app sends the purchases Play reports as owned on the phone (up to 25). Each is verified with Google and, if it is unlinked or already the caller's, linked, granted and acknowledged; one already linked elsewhere is reported per item as `linked_elsewhere` (the batch still succeeds). The caller's own already-linked Play purchases are re-read from Google too, so restore also refreshes a stale state. *Assumed default.* (FR-017–FR-019)
- Q: Real-time developer notifications — sync or queued? → A: **Verified synchronously, stored, answered `204`, processed by a queued job.** The job re-reads the purchase from Google; the notification only says *which* purchase to look at. A notification for a purchase nobody has linked yet links it when its reference names an active account, otherwise it is kept, unlinked, for a later verify or restore. *Assumed default.* (FR-030–FR-039)
- Q: What if a notification is missed? → A: **Reconciliation.** An hourly pass re-reads every granting Play subscription whose billing period has ended (renewal or lapse due), and every linked purchase still unacknowledged; a daily pass re-reads every granting Play subscription not synced for 7 days, and lists Google's **voided purchases** for the last 30 days to catch refunds. Google is asked; the local record follows. *Assumed default.* (FR-044–FR-047)
- Q: Does the server lapse a Play subscription by its dates, as it does other channels? → A: **No.** Spec 009's date-driven lapse pass skips `google_play`; the hourly reconciliation asks Google and applies its answer. Access already follows the stored dates (ADR-0013 rule 14), so a late answer never extends access. Only if Google stays unreachable for 72 hours after the period (or grace) end does the pass lapse the subscription by its dates — never sooner, so a delayed renewal notification never triggers Sharing's demotion. *Assumed default.* (FR-046)
- Q: Where do Play product ids come from — B12's plan editor does not cover them? → A: **Configuration, seeded create-missing.** `config/billing.php` maps each plan code to its Play product id and a reference list price; the Billing seeder adds a plan's `google_play` price entry when the plan has none and the configuration supplies all three values, and never overwrites one. The back office keeps showing Play prices read-only (spec 021 FR-034). The launch defaults are product ids `plus_monthly` and `plus_yearly`, one Play subscription product per plan, each with one auto-renewing base plan; reference prices are **unset** until the owner supplies the Play Console prices, so nothing is seeded and the app sells nothing until then. *Assumed default.* (FR-040–FR-043)
- Q: Lifetime? → A: **Supported, not sold.** A plan whose period is `lifetime` maps to a Play **one-time (non-consumable) product**; its subscription has no end. The launch catalogue has no lifetime plan, so none is configured; offering one is the owner's (section 9, Q3). *Assumed default.* (FR-028, FR-029)
- Q: Test purchases by license testers? → A: **Accepted everywhere**, marked as test on the purchase, and **excluded from every "paid" count** operators see (counted separately). Only accounts the owner adds as license testers in Play Console can make them. *Assumed default.* (FR-050, FR-053)
- Q: Upgrades, downgrades and resubscribes (a new token that replaces an old one)? → A: **The new purchase inherits the old one's account**, and the old one's subscription is ended (`expired` through the zero-length grace hop) when the new one is linked. A new token replacing an old token linked to a different account than the caller is `409 PURCHASE_ALREADY_LINKED`. *Assumed default.* (FR-010)
- Q: A purchase for a deleted account? → A: **Pending deletion**: everything works as for any account (signing in cancels the deletion, spec 007). **Purged**: the account reference is deleted at the purge and the sealed purchase tokens are erased; the `google_play` subscription rows stay as spec 022 left them (stamped, deleted at 400 days). A later notification for such a purchase is recorded and has no effect. Another account presenting the token gets `409 PURCHASE_ALREADY_LINKED`. A never-acknowledged purchase whose reference names a purged account is never linked and Google refunds it. *Assumed default.* (FR-048, FR-049)
- Q: Audit entries? → A: **`entitlement_changed`**, actor `system`, for each grant-changing step — action `purchase` (first link), `renew`, `recover`, `expire`, `revoke` (reason `refunded` or `revoked_by_provider`) — with the subscription id and plan id only. Never a token, an order id, a price or the account reference. No new audit event. *Assumed default.* (FR-051)
- Q: What do operators see? → A: The existing account page already lists Play subscriptions without *Extend* or *Revoke* (spec 021); it gains **auto-renew**, **acknowledged** and **test** markers. The overview gains **Google Play, last 24 hours** counts (notifications received, processed, ignored, failed; purchases verified; purchases unacknowledged for more than 24 hours) and excludes test purchases from *Paid · Google Play*. Never a token, an order id or an amount paid. *Assumed default.* (FR-052, FR-053)
- Q: Rate limits? → A: **Verify: 20 per hour per person; restore: 10 per hour per person** (`429 RATE_LIMITED`), spent after validation, never on an idempotent replay. The webhook carries no per-origin budget (Google's push servers share addresses) but refuses bodies over 64 KiB and anything unverified before any database write. *Assumed default.* (FR-054, FR-055)
- Q: Does the email verification gate apply? → A: **No.** Paying for a plan is management of one's own account and Google has already taken the payment; refusing to honour it until the mailbox is proven would hold paid access hostage. *Assumed default.* (FR-004)
- Q: Renewal reminders and lapse messages? → A: **No renewal reminder for Play** — Google tells its own subscribers; spec 020's reminder stays InstaPay-only, and so does spec 023's `SubscriptionRenewalDue`. **Lapse**: every Play lapse publishes the existing `SubscriptionLapsed`, so Sharing's quota enforcement (spec 016) and spec 023's lapse push follow unchanged. *Assumed default.* (FR-026, FR-027)

### Session 2026-10-06 (clarify pass)

- Q: What does the purchase answer carry? → A: **The purchase's state as the server now sees it** (`state`, `acknowledged`, `auto_renew`, `current_period_end`, `test`), the person's **current entitlements** (spec 009's `EntitlementSummary`) and **subscription** (spec 009's `/me/subscription` body), so the app can switch to Plus without a second request. Never the token, the order id or the account reference. *Assumed default.* (FR-011)
- Q: Can a purchase whose product id is not in the catalogue be granted? → A: **No.** `422 VALIDATION_FAILED` on `product_id` (`INVALID_ENUM_VALUE`) when the app sends one we do not sell; when the app's product id is known but Google's record names another product, `422 PURCHASE_NOT_VERIFIED`. An unmapped product Google reports in a notification is logged as `billing_play_product_unmapped` (an operator alert) and left unacknowledged. *Assumed default.* (FR-042)
- Q: What does a retired plan do to a Play subscription? → A: **Nothing.** Renewals of a retired plan's product keep mapping to it; retiring stops new sales only (the app no longer lists it). *Assumed default.* (FR-043)
- Q: When Google is down during a verify? → A: **`503 SERVICE_UNAVAILABLE` with `Retry-After`**, nothing stored except the pending purchase, the idempotency key released. The app keeps the purchase pending and retries (C10). Unacknowledged purchases have 3 days, so a few hours of outage loses nothing. *Assumed default.* (FR-015)
- Q: How long are inbox rows and unlinked purchases kept? → A: **Inbox: 30 days** (only identifiers and states; the sealed token is erased when processed). **Unlinked purchases: 7 days** after Google's 3-day refund window has certainly passed for pending ones, then deleted. *Assumed default.* (FR-038, FR-039)
- Q: What may the Play paths log, and which lines are operator alerts? → A: **Fixed event names with ids and codes only**: `billing_play_verified` (purchase id, outcome), `billing_play_push_rejected` (reason: `signature`, `issuer`, `audience`, `email`, `time`, `missing`), `billing_play_notification_failed` (inbox id, type), `billing_play_reconciled` (counts), and three **alerts** at `warning`: `billing_play_ack_overdue`, `billing_play_product_unmapped`, `billing_play_unreachable` (Google unreachable for one purchase for 24 hours). Never a token, reference, order id, assertion or body; user ids only at debug level. *Assumed default.* (FR-062)
- Q: How fast must verify answer? → A: **p95 under 3 seconds including Google's round trips** (read, acknowledge), and under 300 ms for the account reference; the webhook answers **p95 under 500 ms** because processing is queued. *Assumed default.* (SC-001, SC-009)
- Q: What does the webhook answer for an oversized or non-JSON body? → A: **`400 MALFORMED_JSON`** for both, before verification touches the database; Pub/Sub does not retry `4xx` except `429`, which is right for a body that will never parse. *Assumed default.* (FR-035)
- Q: What if processing a notification keeps failing (Google down for hours)? → A: **5 attempts with backoff 1 min, 5 min, 15 min, 1 h**, then the inbox row is `failed`; the hourly reconciliation still re-reads the purchase, so a failed row never leaves a subscription wrong for longer than Google is unreachable. *Assumed default.* (FR-038, FR-044)
- Q: Must the app have fetched its account reference before verifying? → A: **No.** The reference is created on first need (its read, a verify, or a restore), so the comparison of FR-007 always has a value to compare; a purchase made without one is handled by FR-009. *Assumed default.* (FR-005)

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Buy Plus in the app and get it at once (Priority: P1)

The person opens the plan screen, picks Plus monthly, pays in Google Play's sheet, and the app shows Plus a moment later. The server checked the purchase with Google, tied it to this account, and acknowledged it, so Google will not refund it.

**Why this priority**: it is the only paid channel inside the app; without it nobody can buy Plus on the phone.

**Independent Test**: with the fake gateway scripted to answer "purchased, product `plus_monthly`, reference = this account's", post the token; see `200`, entitlements with sharing enabled, a `google_play` subscription, an acknowledgement recorded with the gateway, one `entitlement_changed` entry, and `SubscriptionActivated`.

**Acceptance Scenarios**:

1. **Given** an account and a purchase Google reports as purchased for a mapped product carrying this account's reference, **When** the app posts the token with an `Idempotency-Key`, **Then** the answer is `200` with `purchase.state: active`, `acknowledged: true`, the new entitlements and subscription; Google received exactly one acknowledgement.
2. **Given** the same request retried with the same key, **When** it arrives, **Then** the stored answer is replayed with `Idempotency-Replayed: true` and Google is not called.
3. **Given** the same token posted again with a new key by the same account, **When** it arrives, **Then** the answer is `200` with the current state, no second subscription, no second acknowledgement.
4. **Given** a purchase Google reports as pending, **When** posted, **Then** the answer is `202` with `purchase.state: pending`, entitlements unchanged, nothing acknowledged; when Google later reports it purchased, it is granted and acknowledged without the app doing anything.
5. **Given** a token Google does not recognise for this app, or whose product differs from the one posted, or that is canceled before it was ever paid, **When** posted, **Then** `422 PURCHASE_NOT_VERIFIED` and nothing stored.
6. **Given** Google is unreachable, **When** posted, **Then** `503 SERVICE_UNAVAILABLE` with `Retry-After` and nothing granted.
7. **Given** Google confirms the purchase but the acknowledgement call fails, **When** posted, **Then** `200` with `acknowledged: false`, access granted, and the acknowledgement is retried until it succeeds.
8. **Given** a request without `Idempotency-Key`, **When** posted, **Then** `400 IDEMPOTENCY_KEY_REQUIRED`.

---

### User Story 2 - One purchase, one account (Priority: P1)

A Google account bought Plus while signed in to account A. Someone signs in to account B on the same phone (or another phone with the same Google account) and the app posts the same token. B does not get A's plan, and nothing about A is revealed.

**Why this priority**: without it, one payment unlocks any number of accounts — the main fraud path of any store integration.

**Independent Test**: link a token to A; post it as B; see `409 PURCHASE_ALREADY_LINKED` and no change to either account.

**Acceptance Scenarios**:

1. **Given** a token linked to account A, **When** account B posts it, **Then** `409 PURCHASE_ALREADY_LINKED`, nothing granted to B, A unchanged.
2. **Given** a purchase whose reference names account A but which no account has linked yet, **When** account B posts it, **Then** `409 PURCHASE_ALREADY_LINKED`; when A posts it, it is linked to A.
3. **Given** a purchase with no reference, **When** the first account posts it, **Then** it is linked to that account; any later account gets `409`.
4. **Given** a new purchase that replaces an old one (upgrade, downgrade or resubscribe) linked to A, **When** A posts the new token, **Then** it is linked to A and the old subscription ends; **When** B posts it, **Then** `409`.
5. **Given** a token linked to a purged account, **When** another account posts it, **Then** `409 PURCHASE_ALREADY_LINKED`.
6. **Given** the app asks for its account reference twice, **When** answered, **Then** both answers are the same 64-character value, and another account's value differs.

---

### User Story 3 - Google keeps the subscription in step (Priority: P1)

Months pass. Google renews the subscription, retries a failed card, puts it on hold, the person pauses it, cancels it, or Google refunds it. Each time Google pushes a notification; the server checks it really is from Google, asks Google for the purchase's current state, and updates the subscription so the app shows the right plan.

**Why this priority**: a subscription that is right on day one and wrong on day 31 is worse than none; renewals are where the money is.

**Independent Test**: for each notification type, send a correctly signed push (test certificate pair) for a linked purchase, script the fake gateway's state, run the queue, and check status, dates, auto-renew, events and audit entries.

**Acceptance Scenarios**:

1. **Given** a linked active subscription, **When** a renewed notification arrives and Google reports a new expiry, **Then** the billing period moves forward, status stays `active`, an `entitlement_changed` (`renew`) entry is written.
2. **Given** an active subscription, **When** Google reports grace, **Then** status `grace`, `grace_until` = Google's grace end, access continues, `SubscriptionLapsed` (`grace`) is published.
3. **Given** an active or grace subscription, **When** Google reports account hold or paused, **Then** status `expired` (through a zero-length grace), access ends, `SubscriptionLapsed` (`expired`) is published, `entitlement_changed` (`expire`).
4. **Given** an expired subscription after hold or pause, **When** Google reports it recovered or resumed, **Then** status `active` with Google's new period, `SubscriptionActivated`, `entitlement_changed` (`recover`).
5. **Given** an active subscription, **When** Google reports it canceled, **Then** status stays `active`, `auto_renew` becomes false, access lasts to the period end; **When** Google later reports it expired, **Then** `expired`, `SubscriptionLapsed`.
6. **Given** a subscription, **When** Google reports it revoked, or a voided-purchase notification arrives for it (refund, chargeback), **Then** status `revoked` (terminal), access ends at once, `SubscriptionLapsed` (`revoked`), `entitlement_changed` (`revoke`, reason `refunded` or `revoked_by_provider`).
7. **Given** the same notification delivered twice, or notifications arriving out of order, **When** processed, **Then** the subscription ends in the state Google reports now, and nothing is recorded twice.
8. **Given** a notification for a purchase nobody has linked, whose reference names an active account, **When** processed, **Then** the purchase is linked to that account, granted and acknowledged.
9. **Given** a test notification from Play Console, **When** it arrives, **Then** it is verified, recorded as ignored, and answered `204`.

---

### User Story 4 - Only Google can push to the webhook (Priority: P1)

Anyone on the internet can post to the webhook. Only a message signed by Google, issued for this service, from the configured push identity, recent, and not seen before, can change anything.

**Why this priority**: an unverified webhook is a free-subscriptions button.

**Independent Test**: post pushes with a wrong signature, wrong issuer, wrong audience, wrong email, stale issued-at, wrong subscription name, wrong package name, and a repeated message id; check outcomes against the baseline's table.

**Acceptance Scenarios**:

1. **Given** a push whose token signature, issuer, audience or service-account email is wrong, or whose issued-at is more than five minutes from now, or that has no token, **When** it arrives, **Then** `401 PROVIDER_SIGNATURE_INVALID`, nothing stored, nothing granted.
2. **Given** a verified push whose Pub/Sub subscription name or Play package name is not the configured one, **When** it arrives, **Then** `204`, recorded as ignored, nothing granted.
3. **Given** a verified push whose message id was seen in the last 24 hours, **When** it arrives, **Then** `204` with no effect.
4. **Given** Google's certificates cannot be fetched and none are cached, **When** a push arrives, **Then** `503 SERVICE_UNAVAILABLE` and nothing stored, so Pub/Sub retries.
5. **Given** a body larger than 64 KiB or not JSON, **When** it arrives, **Then** it is refused before any verification work that touches the database.

---

### User Story 5 - Restore my purchases (Priority: P2)

The person reinstalls the app, or signs in on a new phone. The app finds the purchases Play says this Google account owns and asks the server to restore them. Plus comes back.

**Why this priority**: required by Play policy and P-10; otherwise a reinstall looks like a lost payment.

**Independent Test**: link a purchase to A; sign in as A on a fresh install; restore with that token and an unlinked one; see both linked and the entitlements back.

**Acceptance Scenarios**:

1. **Given** purchases that are unlinked or already the caller's, **When** restored, **Then** `200` with one result per item in request order (`linked` or `already_yours`), entitlements updated, every linked purchase acknowledged.
2. **Given** one item linked to another account, **When** restored, **Then** that item's result is `linked_elsewhere`, the others proceed.
3. **Given** items Google rejects or reports pending, **When** restored, **Then** results `not_verified` or `pending`.
4. **Given** Google unreachable for some items, **When** restored, **Then** those items are `unavailable` and the answer is still `200`; if Google is unreachable for every item, `503`.
5. **Given** an empty list, **When** restored, **Then** `200`: only the caller's already-linked purchases are refreshed from Google.
6. **Given** more than 25 items or a duplicate token in one request, **When** restored, **Then** `422 VALIDATION_FAILED`.

---

### User Story 6 - Nothing is lost when a notification is (Priority: P2)

Pub/Sub delivery is at-least-once but not guaranteed forever; the server can be down; a notification can be dropped. The server still ends up with Google's answer.

**Why this priority**: a missed renewal must not cut off a paying person, and a missed refund must not keep access for free.

**Independent Test**: let a subscription's period end with no notification; run the hourly pass with the fake gateway reporting a renewal; see the period extended and no lapse event. Repeat with Google reporting expiry; see the lapse. Script a voided purchase in the daily sweep; see `revoked`.

**Acceptance Scenarios**:

1. **Given** a granting Play subscription past its period end, **When** the hourly pass runs and Google reports a renewal, **Then** the period moves forward and no `SubscriptionLapsed` is published.
2. **Given** the same with Google reporting expiry, **When** the pass runs, **Then** `expired` and `SubscriptionLapsed`.
3. **Given** Google unreachable, **When** the pass runs, **Then** nothing changes until 72 hours after the end; after that the subscription lapses by its dates.
4. **Given** a linked purchase unacknowledged, **When** the hourly pass runs, **Then** the acknowledgement is retried; one still unacknowledged 48 hours after purchase is logged as an operator alert.
5. **Given** a refund Google lists among voided purchases but whose notification never arrived, **When** the daily sweep runs, **Then** the subscription is `revoked`.

---

### User Story 7 - Operators see Play at a glance, and cannot override it (Priority: P3)

An operator looking at a person's account sees the Play subscription, whether it renews, whether it was acknowledged, and whether it was a test. On the overview they see whether notifications are flowing. They cannot extend or revoke a Play subscription.

**Why this priority**: support needs to answer "I paid but I don't have Plus" without seeing tokens or money.

**Independent Test**: seed Play subscriptions (one test, one unacknowledged); open the account page and the overview; check what is and is not shown.

**Acceptance Scenarios**:

1. **Given** an account with a Play subscription, **When** the page renders, **Then** it shows plan, status, billing period, auto-renew, acknowledged and test markers, and offers neither *Extend* nor *Revoke*.
2. **Given** Play activity in the last 24 hours, **When** the overview renders, **Then** it shows the notification and verification counts, the unacknowledged count, and *Paid · Google Play* excluding test purchases.
3. **Given** any operator screen, **When** rendered, **Then** no purchase token, order id, account reference or amount paid appears.

### Edge Cases

- A purchase verified by the app at the same moment its "purchased" notification is processed: both paths lock the purchase row; exactly one links and acknowledges, the other sees it linked to the same account and changes nothing.
- Two accounts post the same unreferenced token at the same moment: the unique link lets one win; the other gets `409`.
- The app posts a token for a product it was not sold (a product id we do not map): `422 VALIDATION_FAILED`.
- A renewal notification arrives before the original purchase was ever verified: the purchase is looked up by token; if unlinked and referenced, it is linked by the notification; if unreferenced it waits for verify or restore.
- A Play subscription and an InstaPay subscription overlap: the governing rule (ADR-0013 rule 14) picks the one ending latest; neither is changed by the other.
- An account hold lasting longer than Google's hold window ends in expiry: the subscription is already `expired` locally; the final expired notification changes nothing but `auto_renew`.
- Google reports a period end in the past for a "renewed" notification (clock skew or late delivery): the stored period never moves backwards; the newer of the two ends is kept.
- A deferred or prorated line item (upgrade mid-period): the period end is Google's expiry for the line item matching the mapped product.
- A subscription purchased with an introductory or free-trial offer: granted like any other; the trial is Google's business, the period end is Google's.
- A lifetime purchase is refunded: `revoked`, access ends.
- A notification for another app's package (a misconfigured topic): recorded as ignored.
- Google's certificate rotates between a push and the cache refresh: an unknown key id triggers one immediate certificate refresh before the push is refused.
- The account reference key is rotated: references already issued keep resolving (stored); new accounts get references under the new key.
- The person deletes their account while a purchase is pending: the pending purchase is never linked; Google refunds it.

## Requirements *(mandatory)*

### Functional Requirements

**Surface and gates**

- **FR-001**: The system MUST expose `GET /billing/google/account-reference`, `POST /billing/google/purchases`, `POST /billing/google/restore` (bearer, any active account) and `POST /webhooks/google-play` (public, verified per FR-030), as frozen in section 5.
- **FR-002**: `POST /billing/google/purchases` and `POST /billing/google/restore` MUST require `Idempotency-Key` and behave exactly as spec 020's idempotency store does (scope: the user; only `2xx` stored; 24 hours; replay verbatim with `Idempotency-Replayed: true`).
- **FR-003**: Neither endpoint MAY start, price or record a payment: each only verifies with Google a payment Play Billing already took (P-10 rule 1–2). No endpoint MAY accept a price, an amount or a currency from the client.
- **FR-004**: The email verification gate MUST NOT apply to these endpoints.

**Linking**

- **FR-005**: Each account MUST have exactly one **Play Account Reference**, 64 lowercase hex characters, a keyed hash of the account id under a Billing key, created on first need (its read, a verify or a restore) and stored so it can be resolved back to the account. `GET /billing/google/account-reference` MUST return the caller's.
- **FR-006**: A Play purchase token MUST be linked to at most one account, ever; the link MUST be enforced by a unique key, not only by a check.
- **FR-007**: A purchase whose obfuscated account id is present MUST be linked only to the account whose reference it equals; a reference that resolves to no active account MUST NOT be linked to anyone. In this spec an *active account* is one not purged: an account pending deletion counts as active, because it paid.
- **FR-008**: A verify for a token linked to another account, or whose reference names another account (or no active account), MUST answer `409 PURCHASE_ALREADY_LINKED` without granting anything and without disclosing anything about the other account.
- **FR-009**: A purchase without an obfuscated account id MUST be linked to the first active account that verifies or restores it.
- **FR-010**: A purchase whose Google record names a linked (replaced) token MUST inherit that token's account; a caller that is not that account MUST get `409 PURCHASE_ALREADY_LINKED`; on linking, the replaced token's subscription MUST end (`expired`) if still granting.

**Verification and acknowledgement**

- **FR-011**: A successful verify MUST answer `200` with `purchase` (`state`, `acknowledged`, `auto_renew`, `current_period_end`, `test`), `entitlements` and `subscription`; it MUST NOT return the token, the order id or the reference.
- **FR-011a**: Verifying a token Google reports in a non-granting state that was never granted here (expired, on hold, paused, revoked) MUST link it under FR-006–FR-010 and record Google's state, MUST NOT create a subscription, and MUST answer `200` with that `purchase.state` and unchanged entitlements; a later recovery or restart grants through FR-024.
- **FR-012**: Verification MUST read the purchase from Google (subscriptions: `purchases.subscriptionsv2.get`; one-time products: `purchases.products.get`) and grant only what Google reports as paid for the mapped product of the configured package.
- **FR-013**: The server MUST acknowledge every linked, paid, unacknowledged purchase (subscriptions: `purchases.subscriptions.acknowledge`; one-time: `purchases.products.acknowledge`) after the subscription is committed, in the same request. The frozen contract MUST state that the client never acknowledges.
- **FR-014**: A failed acknowledgement MUST NOT undo the grant; it MUST be retried by a queued job (5 attempts, backoff) and by the hourly pass until Google confirms; a purchase still unacknowledged 48 hours after its purchase time MUST be logged as an operator alert (`billing_play_ack_overdue`).
- **FR-015**: When Google is unreachable or answers a server error during verification, the answer MUST be `503 SERVICE_UNAVAILABLE` with `Retry-After`, granting nothing.
- **FR-016**: A purchase Google reports as pending MUST be checked under FR-006–FR-010 first (another account's → `409`), then recorded linked to the caller with no subscription and answered `202` with `purchase.state: pending`; it MUST be linked, granted and acknowledged when Google later reports it paid.
- **FR-017**: `POST /billing/google/restore` MUST accept 0–25 `{purchase_token, product_id}` items with no duplicate token, verify each as FR-012, and answer `200` with one result per item in request order — `linked`, `already_yours`, `linked_elsewhere`, `not_verified`, `pending` or `unavailable` — plus `entitlements` and `subscription`. It MUST answer `503` only when every item (and the refresh of FR-018) failed for unavailability.
- **FR-018**: Restore MUST also re-read from Google every Play purchase already linked to the caller and apply its state.
- **FR-019**: A `linked_elsewhere` result MUST NOT identify the other account.

**State mapping (ADR-0013 rule 4; ADR-0026)**

- **FR-020**: Google's subscription states MUST map to local statuses as: `ACTIVE` → `active`; `IN_GRACE_PERIOD` → `grace`; `ON_HOLD` and `PAUSED` → `expired`; `CANCELED` → `active` with `auto_renew = false` until the period ends, then `expired`; `EXPIRED` → `expired`; revoked or voided → `revoked`; `PENDING` → no subscription (FR-016); `PENDING_PURCHASE_CANCELED` → no subscription.
- **FR-021**: The billing period MUST come from Google: `current_period_end` = the expiry of the line item for the mapped product (the latest of stored and reported, never moved backwards except by revocation), `current_period_start` = the previous end on renewal, or Google's start time on the first link.
- **FR-022**: For `google_play`, `grace_until` MUST be Google's grace end while in grace and null otherwise; the server's `billing.grace_days` MUST NOT apply.
- **FR-023**: A move to `expired` from `active` MUST pass through `grace` with `grace_until` equal to the move instant, in one transaction, since ADR-0013's machine has no `active → expired` arc; only one `SubscriptionLapsed` (`expired`) MUST be published for it.
- **FR-024**: Recovery from hold, resume from pause and restart of a canceled subscription MUST move `expired` or `grace` → `active` with Google's new period.
- **FR-025**: A `revoked` subscription MUST NOT change again; a new purchase creates a new subscription.
- **FR-026**: Every move into `grace`, `expired` or `revoked` MUST publish `SubscriptionLapsed`; every move into `active` from another status, and every first link, MUST publish `SubscriptionActivated`. Renewals that keep `active` publish neither.
- **FR-027**: No renewal reminder (mail or event) MUST be produced for `google_play`.
- **FR-028**: A plan whose period type is `lifetime` MUST map to a Play one-time product; its subscription MUST have no end; a refunded or voided one-time purchase MUST become `revoked`.
- **FR-029**: A one-time purchase whose Google purchase state is canceled (never paid) MUST NOT be granted.

**Real-time developer notifications**

- **FR-030**: `POST /webhooks/google-play` MUST reject with `401 PROVIDER_SIGNATURE_INVALID` unless the `Authorization: Bearer` token verifies RS256 against Google's current public certificates; `iss` is `https://accounts.google.com` or `accounts.google.com`; `aud` equals the configured push audience; `email` equals the configured push service account and `email_verified` is true; `iat` is within five minutes of now and `exp` is in the future.
- **FR-031**: Google's certificates MUST be fetched over HTTPS from Google's published endpoint, cached for the lifetime Google's cache headers give (at most 24 hours), refreshed once at once on an unknown key id, and never trusted from the request. If none can be obtained the push MUST be answered `503` and nothing stored.
- **FR-032**: A verified push whose message id was recorded in the last 24 hours MUST be answered `204` with no effect.
- **FR-033**: A verified push for another Pub/Sub subscription or another package, a test notification, or one whose `message.data` does not decode to a developer notification, MUST be recorded as `ignored` (with its reason) and answered `204`, so Pub/Sub stops retrying a message that can never be processed.
- **FR-034**: A verified push MUST be stored in the **Play Notification** inbox — message id, publish time, Google's event time, kind (subscription, one-time, voided, test), notification type, product id, purchase token sealed with its keyed lookup value — before `204` is answered; the raw body MUST NOT be stored or logged.
- **FR-035**: The webhook MUST refuse bodies over 64 KiB or not JSON with `400 MALFORMED_JSON`, and MUST write nothing before verification succeeds.
- **FR-036**: Processing MUST be a queued job that re-reads the purchase from Google and applies FR-020–FR-029; the notification type MUST only select which purchase to read (except voided-purchase notifications, which revoke).
- **FR-037**: Processing MUST be idempotent: re-processing the same notification, or processing notifications for one purchase in any order, MUST converge on Google's current state, and each (purchase, type, event time) MUST be applied at most once to the audit trail.
- **FR-038**: Inbox rows MUST end `processed`, `ignored` or `failed` (after 5 attempts, backoff 1 min, 5 min, 15 min, 1 h); the sealed token MUST be erased once a row is terminal; rows MUST be deleted 30 days after receipt.
- **FR-039**: A processed notification for an unlinked purchase MUST link it when its reference names an active account (and acknowledge it), and otherwise keep the purchase unlinked; unlinked purchases MUST be deleted 10 days after their purchase time.

**Catalogue mapping**

- **FR-040**: A plan MUST be mapped to Google Play by a `google_play` entry in its `prices` with the Play product id, a reference amount in minor units and a currency; a product id MUST map to at most one plan.
- **FR-041**: The Billing seeder MUST add a plan's `google_play` price entry from configuration only when the plan has none and the configuration gives product id, amount and currency; it MUST never overwrite or remove one. The launch configuration MUST default product ids `plus_monthly` and `plus_yearly` and leave amounts unset.
- **FR-042**: A product id that maps to no plan MUST NOT be granted: from the app, `422 VALIDATION_FAILED` on `product_id`; from a notification, logged as `billing_play_product_unmapped` and left unacknowledged.
- **FR-043**: Retired plans MUST keep mapping their Play products for renewals and reconciliation.

**Reconciliation**

- **FR-044**: An hourly pass MUST re-read from Google every granting `google_play` subscription whose period (or grace) end has passed, every linked unacknowledged purchase, and every pending purchase younger than 3 days, and apply the answers.
- **FR-045**: A daily pass MUST re-read every granting `google_play` subscription not synced for 7 days, and list Google's voided purchases (subscriptions and one-time) for the last 30 days, revoking any that are linked and not yet revoked.
- **FR-046**: Spec 009's date-driven lapse pass MUST skip `google_play`; the hourly pass MUST lapse a `google_play` subscription by its dates only when Google has been unreachable for it for 72 hours after its period (or grace) end.
- **FR-047**: Every pass MUST be safe to run twice and concurrently with notification processing (row locks on the purchase).

**Accounts that leave**

- **FR-048**: At the account purge, Billing's existing step MUST delete the account's Play Account Reference, erase the sealed tokens of its Play purchases, and leave its `google_play` subscriptions as spec 022 FR-047 requires.
- **FR-049**: A notification or verify for a purchase whose account was purged MUST have no effect on any account; another account presenting it MUST get `409 PURCHASE_ALREADY_LINKED`.

**Audit, operators, privacy**

- **FR-050**: A purchase Google marks as a test purchase MUST be granted like any other and recorded as test.
- **FR-051**: Every grant-changing step MUST write one `entitlement_changed` entry (actor `system`; action `purchase`, `renew`, `recover`, `expire` or `revoke`; reason `refunded` or `revoked_by_provider` for revoke) with subscription id and plan id only; the audit detail's action vocabulary (Identity's `EntitlementChangedDetail`, today `grant`, `extend`, `revoke`, `expire`, `redeem`) gains `purchase`, `renew` and `recover`.
- **FR-052**: The back-office account page MUST show for each `google_play` subscription its auto-renew, acknowledged and test markers, and MUST NOT offer extend or revoke (spec 021 FR-029 unchanged).
- **FR-053**: The overview MUST show, for the last 24 hours, Play notifications received, processed, ignored and failed, pushes rejected by reason, purchases verified, and purchases unacknowledged for more than 24 hours; *Paid · Google Play* MUST exclude test purchases and a separate *Test · Google Play* count MUST be shown.
- **FR-054**: Verify MUST be limited to 20 per hour per person and restore to 10 per hour per person (`429 RATE_LIMITED`), spent after validation and never by a replay.
- **FR-055**: Purchase tokens, the account reference, order ids, the service-account key, the OAuth assertion, access tokens and the push bearer token MUST NOT be logged, returned (except the reference to its own account), queued in a job payload, evented or audited. Jobs carry the Play purchase id or inbox id only.

**Gateway**

- **FR-056**: All calls to Google MUST go through a `PlayBillingGateway` port with a closed set of outcomes (purchase record, not found / invalid, unavailable with retry-after, acknowledged, already acknowledged).
- **FR-057**: Adapter selection MUST be configuration: `google`, `fake`, `disabled`; unset → `disabled`.
- **FR-058**: The application MUST refuse to boot with `fake` outside `local` and `testing`, and with `google` without a readable service-account key, a package name, a push audience and a push service-account email.
- **FR-059**: The `google` adapter MUST obtain its access token by an RS256-signed service-account JWT (scope `https://www.googleapis.com/auth/androidpublisher`), cache it until 5 minutes before expiry behind a lock, refresh once on `401`, and use connect/total timeouts of 5 s / 10 s.
- **FR-060**: No Composer or npm dependency MUST be added; replacing the `google` adapter with one built on the official SDK MUST need no change outside that adapter and its binding.
- **FR-061**: The test suite MUST bind an in-memory fake that scripts Google's answers per token and records acknowledgements; tests of the webhook MUST sign tokens with a key pair generated in the test and served as the fake certificate set.
- **FR-062**: The Play paths MUST log only the fixed events `billing_play_verified`, `billing_play_push_rejected` (with a reason code), `billing_play_notification_failed`, `billing_play_reconciled`, and the `warning` alerts `billing_play_ack_overdue`, `billing_play_product_unmapped` and `billing_play_unreachable` (Google unreachable for one purchase for 24 hours), carrying ids, counts and codes only.

### Key Entities

- **Play Purchase** (Billing): one Google Play purchase token known to this service — its sealed token and keyed lookup value, product id, kind (subscription or one-time), the account it is linked to (or none), the subscription it backs, Google's last reported state, acknowledged instant, test flag, the replaced token's lookup, last synced instant.
- **Play Account Reference** (Billing): the opaque value an account passes to Play Billing as its obfuscated account id; one per account.
- **Play Notification** (Billing): one verified real-time developer notification in the inbox — message id (unique), publish and event times, kind, type, product id, sealed token and lookup, processing state.
- **Subscription** (existing, spec 009): gains nothing new in shape; a `google_play` subscription's `provider_ref` is its Play Purchase id; `source_meta` holds the test flag and the base plan id.
- **Plan** (existing): a `google_play` price entry maps a Play product id.

## Project Sections *(mandatory for feature specs)*

### 1. Purpose

Let a person pay for Plus inside the app with Google Play, get it at once, keep it exactly as long as Google keeps charging, and lose it exactly when Google refunds or stops — with every purchase checked with Google, tied to one account, acknowledged in time, and never trusted from the phone or from an unverified message.

### 2. Glossary terms

New entries, added to [docs/glossary.md](../../docs/glossary.md) before code uses them: **Play Purchase**, **Play Account Reference**, **Play Notification**, and **Purchase Token** (Google's opaque identifier of one purchase; sealed at rest, never logged). Updated: [Subscription](../../docs/glossary.md#subscription) (`google_play` rows backed by a Play Purchase; Google's grace; the zero-length grace hop), [Plan](../../docs/glossary.md#plan) (the `google_play` price entry maps a product id). Existing: [Idempotency Record](../../docs/glossary.md#idempotency-record), [Entitlement](../../docs/glossary.md#entitlement). The glossary's "purchase, order" row keeps pointing product vocabulary at Subscription; "Play Purchase" is the integration record, not a product term.

### 3. Data model

Owned by `app/Modules/Billing`. All ids UUIDv7; instants UTC (P-12). Full columns in [data-model.md](./data-model.md).

| Entity | Scope key | Key fields | States |
|---|---|---|---|
| Play Purchase | `user_id` (nullable until linked); unique `token_lookup` | `token_sealed` (nullable after purge), `product_id`, `kind`, `subscription_id`, `play_state`, `acknowledged_at`, `is_test`, `linked_lookup`, `obfuscated_ref`, `purchased_at`, `synced_at`, `unreachable_since` | `unlinked` → `linked`; `linked` is final (never re-linked) |
| Play Account Reference | unique `user_id`, unique `reference` | `key_id`, `created_at` | single-state (deleted at purge) |
| Play Notification | unique `message_id` | `kind`, `notification_type`, `product_id`, `token_sealed`, `token_lookup`, `event_at`, `published_at`, `received_at`, `state`, `attempts` | `received` → `processed` \| `ignored` \| `failed` |
| Subscription (existing) | `user_id` | `provider = google_play`, `provider_ref` = Play Purchase id | ADR-0013 machine |

### 4. Invariants

| Invariant | Principle or decision | Test that fails when broken |
|---|---|---|
| A purchase token is linked to at most one account, ever | FR-006, FR-008 | `tests/Feature/Billing/GooglePlay/LinkPurchaseTest.php` |
| A purchase carrying a reference is linked only to the account it names | FR-007 | `tests/Feature/Billing/GooglePlay/LinkPurchaseTest.php` |
| Nothing is granted without Google's confirmation of a paid purchase of a mapped product | FR-012, FR-042 | `tests/Feature/Billing/GooglePlay/VerifyPurchaseTest.php` |
| Every linked paid purchase is acknowledged by the server; an unlinked one never is | FR-013, FR-014, FR-039 | `tests/Feature/Billing/GooglePlay/AcknowledgeTest.php` |
| Play states map onto ADR-0013's machine with no undrawn arc | FR-020–FR-025, ADR-0013 rule 4 | `tests/Unit/Billing/GooglePlay/PlayStateMapTest.php`, `tests/Feature/Billing/GooglePlay/ApplyPlayStateTest.php` |
| `billing.grace_days` never applies to `google_play`; the date-driven lapse skips it | FR-022, FR-046 | `tests/Feature/Billing/GooglePlay/ReconcileTest.php`, `tests/Feature/Billing/LapseSubscriptionsTest.php` |
| A webhook push changes nothing unless all five baseline checks (and email) pass | FR-030, security baseline | `tests/Feature/Billing/GooglePlay/PushVerificationTest.php`, `tests/Unit/Billing/GooglePlay/OidcTokenVerifierTest.php` |
| A repeated message id has no effect | FR-032 | `tests/Feature/Billing/GooglePlay/WebhookTest.php` |
| Processing converges on Google's state whatever the order or repetition | FR-036, FR-037 | `tests/Feature/Billing/GooglePlay/ProcessNotificationTest.php` |
| Purchase tokens never readable at rest, logged, returned, queued or audited | FR-034, FR-055, P-15 | `tests/Feature/Billing/GooglePlay/TokenNeverLeaksTest.php` |
| The `fake` gateway cannot boot in production; `google` cannot boot without credentials | FR-058 | `tests/Feature/Billing/GooglePlay/GatewayBindingTest.php` |
| Operators cannot extend or revoke `google_play` | FR-052, spec 021 FR-029 | existing `tests/Feature/Billing/OperatorSubscriptionsChannelTest.php` |
| Test purchases are never counted as paid | FR-053 | `tests/Feature/Admin/PlayFiguresTest.php` |
| No new Composer or npm dependency | FR-060, Quality Gate 6 | reviewer (CONTRIBUTING item 17) |

### 5. API

Under `/api/v1`, in [docs/openapi/paths/google-play.yaml](../../docs/openapi/paths/google-play.yaml), tag `Plans & Billing`, `info.version` **v1.15**. New error codes **`PURCHASE_ALREADY_LINKED`** (409, retryable `never`) and **`PURCHASE_NOT_VERIFIED`** (422, retryable `never`), catalogued with their enum cases and problem sentences in the first implementation task (the parity gate requires all three together). Human-readable contract: [contracts/google-play-api.md](./contracts/google-play-api.md).

| Method | Path | Auth | Success | Error codes |
|---|---|---|---|---|
| GET | `/billing/google/account-reference` | bearer | `200` `{obfuscated_account_id}` | `UNAUTHENTICATED`, `CLIENT_VERSION_*`, `RATE_LIMITED` |
| POST | `/billing/google/purchases` | bearer, `Idempotency-Key` | `200` result; `202` pending | `IDEMPOTENCY_KEY_REQUIRED`, `IDEMPOTENCY_KEY_INVALID`, `UNAUTHENTICATED`, `PURCHASE_ALREADY_LINKED`, `IDEMPOTENCY_KEY_REUSED`, `IDEMPOTENCY_REQUEST_IN_PROGRESS`, `PURCHASE_NOT_VERIFIED`, `VALIDATION_FAILED` (`REQUIRED`, `INVALID_TYPE`, `OUT_OF_RANGE`, `INVALID_ENUM_VALUE`, `UNKNOWN_FIELD`), `RATE_LIMITED`, `SERVICE_UNAVAILABLE` |
| POST | `/billing/google/restore` | bearer, `Idempotency-Key` | `200` per-item results | as above minus `PURCHASE_ALREADY_LINKED` and `PURCHASE_NOT_VERIFIED` (reported per item) |
| POST | `/webhooks/google-play` | Pub/Sub OIDC bearer; exempt from the client-version gate | `204` | `MALFORMED_JSON`, `PROVIDER_SIGNATURE_INVALID`, `SERVICE_UNAVAILABLE` |

**Frozen for C10:**

- **Who acknowledges**: the server, always. The client MUST NOT call `acknowledgePurchase`; a purchase the server has not linked is never acknowledged and Google refunds it after 3 days.
- **Obfuscated account id**: the app reads `GET /billing/google/account-reference` once per signed-in account and passes it as `setObfuscatedAccountId` on every `BillingFlowParams`. No obfuscated profile id is used.
- **Purchase body**: `{"purchase_token": string 1–2048, "product_id": string 1–150}`. **Restore body**: `{"purchases": [ {purchase_token, product_id} … ] }`, 0–25 items, tokens unique.
- **Purchase answer** (`200`/`202`): `data.purchase` `{product_id, state: active|grace|on_hold|paused|canceled|expired|revoked|pending, acknowledged, auto_renew, current_period_end (UTC instant or null), test}`, `data.entitlements` (spec 009 `EntitlementSummary`), `data.subscription` (spec 009 `/me/subscription` body).
- **Restore answer** (`200`): `data.results[]` `{index, product_id, outcome: linked|already_yours|linked_elsewhere|not_verified|pending|unavailable}`, `data.entitlements`, `data.subscription`.
- **Client behaviour by answer**: `200` → show entitlements; `202` → keep the purchase pending in the app, retry later with a new key; `409 PURCHASE_ALREADY_LINKED` → "This purchase belongs to another account; sign in with it"; `422 PURCHASE_NOT_VERIFIED` → stop, do not retry the token; `503`/`429` → retry with backoff, same key.
- **Product ids**: read from `GET /plans` → `prices[]` where `channel = google_play` (`product_id`); one Play subscription product per plan, one auto-renewing base plan each; prices shown are Play's own localised prices.

### 6. Authorization and entitlements

Owner only: every operation acts on the caller's own purchases; no wallet role applies. The webhook is authorized only by Google's verified token (section 4). Entitlements are not checked to buy (anyone may pay); the result of every change is read through `EntitlementService` (P-09 rule 2), and no code outside Billing's entitlement resolution compares a plan code (rule 3) — the product → plan mapping resolves to a plan **id**. Losing a Play subscription deletes no data (rule 6); Sharing's quota enforcement follows `SubscriptionLapsed` unchanged.

### 7. Events

**Published (existing, new publishers)**: `SubscriptionActivated` (first link, recovery, resume, restart) and `SubscriptionLapsed` (`grace`, `expired`, `revoked`), both after commit, identifiers and scalars only (P-11). **No new event.** **Consumed**: none new (the purge runs through Billing's existing participant). Listeners of `SubscriptionLapsed` (Sharing `DemoteOnLapse`, spec 023's lapse push) receive Play lapses unchanged.

### 8. Acceptance scenarios

| Story · scenarios | Layer | Test file |
|---|---|---|
| US1 · 1–3, 8 | Contract, Feature | `tests/Contract/Billing/GooglePlayPurchasesTest.php`, `tests/Feature/Billing/GooglePlay/VerifyPurchaseTest.php` |
| US1 · 4–7 | Feature | `tests/Feature/Billing/GooglePlay/VerifyPurchaseTest.php`, `tests/Feature/Billing/GooglePlay/AcknowledgeTest.php` |
| US2 · 1–6 | Contract, Feature | `tests/Contract/Billing/GooglePlayPurchasesTest.php`, `tests/Feature/Billing/GooglePlay/LinkPurchaseTest.php` |
| US3 · 1–9 | Unit, Feature | `tests/Unit/Billing/GooglePlay/PlayStateMapTest.php`, `tests/Feature/Billing/GooglePlay/ProcessNotificationTest.php` |
| US4 · 1–5 | Unit, Contract, Feature | `tests/Unit/Billing/GooglePlay/OidcTokenVerifierTest.php`, `tests/Contract/Billing/GooglePlayWebhookTest.php`, `tests/Feature/Billing/GooglePlay/PushVerificationTest.php` |
| US5 · 1–6 | Contract, Feature | `tests/Contract/Billing/GooglePlayRestoreTest.php`, `tests/Feature/Billing/GooglePlay/RestorePurchasesTest.php` |
| US6 · 1–5 | Feature | `tests/Feature/Billing/GooglePlay/ReconcileTest.php` |
| US7 · 1–3 | Feature | `tests/Feature/Admin/PlayFiguresTest.php`, `tests/Feature/Admin/AccountPagePlayTest.php` |
| Gateway (FR-056–FR-061) | Unit, Feature | `tests/Unit/Billing/GooglePlay/ServiceAccountJwtTest.php`, `tests/Feature/Billing/GooglePlay/GooglePlayGatewayTest.php`, `tests/Feature/Billing/GooglePlay/GatewayBindingTest.php` |

### 9. Open questions

1. **Google Cloud and Play Console setup (owner).** Production needs: a Play Console app with the products `plus_monthly` and `plus_yearly` (or the ids the owner prefers, set in configuration before the seeder runs), their prices; a Google Cloud service account linked in Play Console with "View financial data" and "Manage orders and subscriptions"; a Pub/Sub topic set as the app's RTDN topic, a **push** subscription to `https://<host>/api/v1/webhooks/google-play` with authentication enabled (a push service account, the audience set to that URL); the reference prices for the catalogue. Until supplied, production runs the `disabled` gateway and the app sells nothing. Does not block building or testing.
2. **Cancel auto-renew at account purge (owner, product).** The Developer API can cancel a subscription's renewal without refund. Spec 022 and the privacy commitments say deleting does not cancel Play; doing it would protect a person who forgets, but changes a published commitment and three mails. Recommended: yes, in a follow-up that also rewrites those sentences. Does not block.
3. **A lifetime plan (owner, pricing).** Supported by this design (FR-028); not in the launch catalogue. Does not block.
4. **Moving a purchase from a deleted account to a new one (owner, support).** Refused in MVP (FR-049); the person cancels in Play and buys again. Does not block.
5. **Adopting the official Google client library (owner).** Only if the in-house adapter proves insufficient; a drop-in adapter swap (FR-060) needing dependency approval. Does not block.
6. **Editing Play product ids in the back office (owner).** Configuration plus a create-missing seeder in MVP; an editor would need spec 021's plan editor extended. Does not block.
7. **The five-minute issued-at window and Pub/Sub token reuse (owner, security).** Pub/Sub may reuse a push token for up to an hour; the baseline's window can then refuse a genuine push, which Pub/Sub retries and reconciliation backs up (delay, never a wrong grant). Kept as ratified; if `billing_play_push_rejected (time)` is frequent in production, amend the baseline to bound by `exp` for Google pushes. Does not block.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A person who completes a Play purchase sees Plus in the app within 5 seconds of returning from Google's sheet when Google is healthy.
- **SC-002**: 100% of linked paid purchases are acknowledged within 1 hour, and none is refunded by Google for lack of acknowledgement while Google's API is available for at least 1 hour in its 3-day window.
- **SC-003**: 0 tokens are granted to a second account in the suite's concurrency and replay tests.
- **SC-004**: After any Google state change, the local subscription matches Google within 5 minutes when a notification arrives, and within 2 hours when it does not.
- **SC-005**: 0 unverified, misaddressed, stale or repeated webhook messages change any subscription in the suite.
- **SC-006**: 0 purchase tokens, account references, assertions or access tokens appear in logs, responses (other than one's own reference), job payloads, events or audit entries in the suite.
- **SC-007**: A delayed renewal notification never produces a lapse: no `SubscriptionLapsed` is published for a subscription Google reports renewed.
- **SC-008**: Restoring purchases after a reinstall returns the person's plan in one request.
- **SC-009**: 95% of verify requests answer within 3 seconds including Google's round trips; 95% of webhook pushes are answered within 0.5 seconds.

## Assumptions

- The Android app uses Play Billing Library 7 or later (the client's own decision, C10), passes the account reference as the obfuscated account id, and never acknowledges.
- The Google Play Developer API `androidpublisher` v3 — `purchases.subscriptionsv2.get`, `purchases.subscriptions.acknowledge`, `purchases.products.get`, `purchases.products.acknowledge`, `purchases.voidedpurchases.list` — its OAuth 2.0 JWT bearer grant, Pub/Sub push's OIDC tokens and Google's certificate endpoint are as documented by Google at the time of writing; the adapters isolate any change.
- RTDN payloads follow Google's `DeveloperNotification` shape (`version`, `packageName`, `eventTimeMillis`, one of `subscriptionNotification`, `oneTimeProductNotification`, `voidedPurchaseNotification`, `testNotification`).
- Queue workers and the scheduler run as today; the new passes are hourly and daily.
- Platform is Android only (P-17); no App Store.
- Reference list prices for Play exist only for operators and the website; the app shows Play's own price.
