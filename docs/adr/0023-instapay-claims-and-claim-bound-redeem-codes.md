# ADR-0023: InstaPay claims deliver a plan through a claim-bound redeem code

- **Status**: Accepted
- **Date**: 2026-10-05
- **Principles**: P-09, P-10
- **Supersedes**: — (extends [ADR-0013](0013-entitlements-with-manual-channel.md): supersedes rule 12's word "authenticated" only; narrows rule 2's "`manual` records … redeem codes"; leaves rule 4's "claim approved" arc unused by this channel)
- **Resolves**: how an InstaPay payment becomes a subscription when the payer has no session at submission time (spec 020, brief B11)

## Context

InstaPay is a manual bank transfer reviewed by a person. The payer starts on the website with an address, not a session, and the Android app may not offer any alternative payment (P-10 rule 3, ADR-0013 rules 11 and 12). ADR-0013 rule 2 says a subscription records a `user_id`, but at approval the claim has only an address. The security baseline forbids any surface that reveals whether an address is registered.

## Decision

1. **Claim submission MUST be public, web-only, uniform and rate-limited.** It carries no bearer credential, answers `404 NOT_FOUND` to any `X-Client-Platform` other than `web`, performs no account lookup, and answers identically whether or not the address has an account. This supersedes the word "authenticated" in ADR-0013 rule 12 only.
2. **An approved claim MUST issue exactly one Redeem Code** bound to the claim's address (compared by keyed lookup value), stored as a hash only, valid 30 days, single use.
3. **The subscription MUST be created at redemption**, for the redeeming verified account, with provider `instapay` and `provider_ref` the claim id. This narrows ADR-0013 rule 2's "`manual` records operator grants and redeem codes" to codes not backed by a claim (none exist in the MVP).
4. **Approval MUST NOT reactivate any subscription.** The `expired ─claim approved─▶ active` arc of ADR-0013 rule 4 is not used by this channel; a new subscription is created instead.
5. **`instapay` billing periods MUST chain on early renewal**: a new period starts at the later of now and the end of the account's latest-ending granting `instapay` subscription. Other channels are never changed, and the governing subscription follows ADR-0013 rule 14.

## Alternatives Considered

### Authenticated claims

Require a session on the website before a claim. **Rejected because** the brief's flow starts from an address, forcing registration before payment, and a signed-in claim reveals nothing the uniform answer does not already protect.

### Subscription created at approval

Create the `instapay` subscription for the account holding the address when the operator approves. **Rejected because** unregistered payers have no account, and access would change without the person's action.

### Extending an existing subscription

Move the current subscription's end forward on each redemption. **Rejected because** it mixes two claims in one row and breaks "one claim, one subscription" auditing.

## Consequences

### Positive

- The claim form leaks nothing about who has an account, with equal timing by construction.
- One claim yields at most one subscription, guarded by the unique `(provider, provider_ref)`.
- The app offers only "Enter code", so Play policy is respected.

### Negative

- A payer must hold (or create and verify) an account with the claim's address within 30 days.
- A redeem code travels by mail, so mail transports that log messages are refused outside local development.

### Follow-ups

- B12 puts approve, reject and revoke behind a recent second-factor proof.
- A bot challenge on the claim form, if abuse appears, needs the owner's approval (new dependency).
