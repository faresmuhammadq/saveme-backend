# ADR-0026: Google Play purchases through an in-house gateway; Play is the source of truth

- **Status**: Accepted
- **Date**: 2026-10-06
- **Principles**: P-09, P-10, P-15
- **Supersedes**: — (extends [ADR-0013](0013-entitlements-with-manual-channel.md) and [ADR-0010](0010-observability.md); no rule of either changes)
- **Resolves**: how a Google Play purchase becomes a subscription without a Google package, and who is right when the two disagree (spec 024, brief B10)

## Context

People buy Plus inside the Android app through Play Billing. The server must verify each purchase with Google, grant it once, to one account, and keep it in step with Google's renewals, holds, refunds and expiries. Brief B10 assumes Google's client library, but no Google package is installed and CONTRIBUTING item 17 and Quality Gate 6 forbid a new dependency without the owner's approval, which the owner has not given. Spec 023 already solved the same transport problem for push ([ADR-0025](0025-push-through-a-port-with-an-in-house-fcm-adapter.md)).

Forces:

- A purchase token is a bearer secret: whoever holds it can claim the purchase. It must be linked to exactly one account, forever, and must never be logged.
- Google refunds a purchase the app does not acknowledge within 3 days, so acknowledgement is part of granting, and it must never happen for a purchase nobody owns.
- Google's states (grace, hold, pause, cancel) are richer than ADR-0013's four statuses, and ADR-0013 rule 4 forbids undrawn arcs.
- Pub/Sub pushes are at-least-once, unordered and can be lost.

## Decision

1. Google Play is the source of truth for every `google_play` subscription. The date-driven lapse pass (spec 009) MUST skip `google_play`; the hourly reconciliation asks Google instead. Only after 72 hours of Google being unreachable past a subscription's end does the server lapse it by its stored dates.
2. Every Google call MUST go through the `PlayBillingGateway` port (`Billing\Domain\GooglePlay`). Adapters: `google`, `fake` (scripted; refused at boot outside `local` and `testing`) and `disabled` (the default; grants nothing). The `google` adapter MUST use the framework HTTP client against androidpublisher v3 with an access token from an RS256 service-account JWT signed by `openssl_sign` and cached until 5 minutes before its expiry. No Composer or npm dependency is added; the official library is a drop-in swap of one adapter and needs the owner's approval.
3. A Pub/Sub push MUST be verified in-house before anything is stored: RS256 only (`openssl_verify`, any other `alg` refused), keys only from Google's certificate endpoint (cached by `Cache-Control`, one refresh on an unknown `kid`), `iss`, `aud` equal to the configured push endpoint, `email` equal to the configured push service account with `email_verified`, `iat` within five minutes, `exp` in the future (security baseline, Messages from the payment provider). Replay is refused by the Pub/Sub message id.
4. A purchase token MUST be recorded as a **Play Purchase** beside the subscription: the token sealed under Billing's keyring with a keyed lookup value (unique), `provider_ref` of the subscription = the Play Purchase id. A token MUST be linked to one account once and never re-linked (`UPDATE … WHERE user_id IS NULL`, unique lookup). The account is identified to Google by the **Play Account Reference**, a keyed hash of the account id (the obfuscated account id).
5. Google's states MUST map onto ADR-0013 rule 4 arcs only: grace → `grace` with Google's grace end (never `billing.grace_days`); hold, pause and expiry → `expired`, reached from `active` by a zero-length grace hop in one transaction with one `SubscriptionLapsed`; refund or void → `revoked`, terminal. The period end never moves backwards except on revocation.
6. The SERVER MUST acknowledge, after the grant commits, never before, and never the client. A purchase no account has linked MUST NOT be acknowledged. A failed acknowledgement is retried by a job and by the sweep.
7. A pending purchase MUST grant nothing and answers 202; it is granted when Google reports it paid.
8. Reading P-10 rule 2 and ADR-0013 rule 11: `POST /billing/google/purchases` is the single-item form of restore-purchases and `GET /billing/google/account-reference` is a read that starts no payment, so the in-app billing writes remain restore and enter-code.
9. Purchase tokens, account references, order ids, the service-account key, the OAuth assertion and access tokens MUST NOT appear in any response, log line, exception message, job payload, event or audit entry (ADR-0010 rule on secrets, extended).

## Alternatives Considered

### `google/apiclient` and `google/auth`

The maintained client. **Rejected because** it is a new dependency the owner has not approved; the port makes it a one-adapter swap later.

### An `on_hold` subscription status

Mirrors Google exactly. **Rejected because** it supersedes ADR-0013's machine and changes every consumer of `status` for no behavioural gain; the zero-length grace hop reaches `expired` within the drawn arcs.

### Trusting the notification payload

Skips the read call. **Rejected because** a notification only names a purchase and a type; reading Google makes order and duplication irrelevant and removes any need to trust a push's content.

### The purchase token as `provider_ref`

Needs no purchase table. **Rejected because** it puts a secret in an index and in every operator query, and reconciliation still needs the token.

## Consequences

### Positive

- One port separates the product from Google's transport; production can ship `disabled` before the owner finishes the Play setup.
- Linking is enforced by a unique key and a conditional update, not by application checks alone.
- Missed or reordered notifications cannot leave a subscription wrong for long.

### Negative

- About 300 lines of Google client code to maintain, and a second copy of the service-account JWT code (P-02 forbids importing Notifications' Infrastructure).
- Jobs run on a `billing` queue name; workers must listen to it.

### Follow-ups

- Adopt the official client only if the in-house adapter proves insufficient (needs dependency approval).
- Pub/Sub may reuse a push token beyond the five-minute `iat` window; the rejection counter on the overview shows whether it bites (spec 024 §9 Q7).
