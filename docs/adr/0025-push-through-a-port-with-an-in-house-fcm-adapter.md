# ADR-0025: Push through a port with an in-house FCM adapter; notifications deduplicated by a ledger; budget overruns recorded once

- **Status**: Accepted
- **Date**: 2026-10-06
- **Principles**: P-11, P-15, P-17
- **Supersedes**: — (extends [ADR-0009](0009-domain-events-after-commit.md), [ADR-0010](0010-observability.md), [ADR-0018](0018-occurrences-materialised-by-the-server.md) and [ADR-0019](0019-budget-spending-by-author-computed-on-read.md); no rule of any changes)
- **Resolves**: how the product reaches a phone without a Firebase package, and where "already notified" lives (spec 023, brief B16; the B7 and B8 gaps)

## Context

Nothing in the product can reach a person who is not looking at the app. Brief B16 names the Firebase SDK, but no Firebase package is installed and CONTRIBUTING item 17 and Quality Gate 6 forbid a new dependency without the owner's approval, which the owner has not given. Two earlier briefs left a gap on purpose: B7 (due-ness of an occurrence is derived, nothing stored to listen to) and B8 (budget progress is computed on read, nothing stores "already notified"). Notification listeners run on at-least-once queues, so every notification needs an idempotency key of its own.

Forces:

- FCM HTTP v1 is one JSON POST; the only non-trivial part is an OAuth 2.0 access token obtained with an RS256-signed service-account JWT, which `ext-openssl` does.
- A push token is a deliverable secret: it must be sendable, so it must be recoverable, so it is sealed rather than hashed (ADR-0010; security baseline rule 4).
- An adapter that pretends to deliver must not reach production (spec 020 precedent for mail transports).
- ADR-0018 keeps due-ness derived; ADR-0019 keeps budget progress computed on read.

## Decision

1. Push MUST leave the application only through the `PushGateway` port (`Notifications\Domain\Delivery`). Adapters: `fcm`, `log`, `disabled`; tests bind an in-memory fake that lives under `tests/`, never in `app/`.
2. The `fcm` adapter MUST call FCM HTTP v1 with the framework HTTP client, authenticated by an access token obtained by the JWT bearer grant (RFC 7523) signed RS256 with `openssl_sign` from a service-account key. The token MUST be cached until 5 minutes before its expiry. No Composer or npm dependency is added.
3. The `log` adapter MUST be refused at boot outside `local` and `testing`; `fcm` MUST be refused at boot without a project id and a readable key; `disabled` is allowed everywhere and records every notification as skipped.
4. The push token MUST be stored sealed under Notifications' own keyring with a keyed lookup value beside it, and MUST NOT appear in any response, log line, exception message, job payload, event or audit entry. The service-account key, the signed assertion and the access token follow the same rule.
5. Every message MUST be a Push Notification row with a per-person dedupe key (unique with the person); creating one whose key exists does nothing. This is the idempotency of every notification listener and of every reminder pass (ADR-0009 rule 2, applied to a new kind of listener).
6. Occurrence due-ness MUST stay derived (ADR-0018): the reminder pass asks Wallets what needs a person now and stores only that it reminded. Budget progress MUST stay computed on read (ADR-0019): Budgeting stores only that a period crossed its limit (a Budget Overrun, threshold 100, once per budget per period) and publishes `BudgetExceeded` from that insert.
7. A payload MUST carry only its message type's identifiers, never an amount, balance, currency, name, note, address, calendar date of money, token or URL; the text is generic, fixed and server-localised.

## Alternatives Considered

### The Firebase Admin SDK (`kreait/firebase-php`, `google/auth`)

A maintained client for the same API. **Rejected because** it is a new dependency the owner has not approved; the port makes it a drop-in replacement of one adapter later.

### The legacy FCM server-key API

**Rejected because** Google shut it down in 2024.

### Data-only messages

**Rejected because** Android delays or drops them under background limits, and a reminder that does not arrive is worse than generic text.

### Notification preferences on the sync feed

**Rejected because** it costs every client a sync change for a screen it reads on demand; preferences are versioned with `409 VERSION_CONFLICT` instead.

### Laravel's database notifications

**Rejected because** the database channel stores rendered content, a custom FCM channel would still be needed, and mail and push concerns the spec keeps apart would mix.

## Consequences

### Positive

- One adapter class and one binding separate the product from Google's transport; the SDK can replace it without touching anything else.
- The ledger gives idempotency, the "already notified" record, a rate-limit basis and honest operator counts from one table.
- Production can ship before the owner supplies Firebase credentials: `disabled` pretends nothing.

### Negative

- About 150 lines of FCM and JWT code to maintain; a third copy of the XChaCha20 sealer (P-02 forbids importing another module's Infrastructure).
- Jobs run on a dedicated `notifications` queue name; workers must listen to it.

### Follow-ups

- Adopt the official SDK only if the in-house adapter proves insufficient (needs dependency approval).
- A "nearly at the limit" budget threshold fits the overrun row's `threshold` column without a shape change.
