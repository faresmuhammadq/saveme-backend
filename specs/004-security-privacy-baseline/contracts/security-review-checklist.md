# Contract: Security Review Checklist

FR-038a requires every rule in this baseline to be expressible as a check a reviewer can apply to **one** endpoint, **one** stored attribute or **one** recorded entry. This is that list. Every line is answerable yes or no without reading another feature.

## A. Sessions and credentials

- [ ] A1 — Authentication is per device session; the endpoint does not issue or accept an account-wide credential.
- [ ] A2 — Ending one session leaves every other session working.
- [ ] A3 — A revoked credential fails on the very next request, with no cache of validity anywhere in the path.
- [ ] A4 — A password change revokes every session except the one that made the change.
- [ ] A5 — No endpoint returns a password hash, a credential hash, or a token hash.
- [ ] A6 — Session expiry extends on use and is not a fixed date the user cannot predict.

## B. Guessing and probing

- [ ] B1 — Sign-in failures are counted per account **and** per origin.
- [ ] B2 — An account lock expires on its own within 60 minutes and is never permanent.
- [ ] B3 — A successful password reset clears a lock immediately.
- [ ] B4 — A lockout refusal is distinguishable by the client from a wrong password.
- [ ] B5 — Sign-in, reset, registration and invitation answer identically for a registered and an unregistered address.
- [ ] B6 — Response timing does not distinguish a wrong password from an unknown address.

## C. The verification gate

- [ ] C1 — Every write to a wallet is behind the gate.
- [ ] C2 — Reads, profile management and session management are **not** behind the gate.
- [ ] C3 — The refusal is its own code, so the app can offer a resend.
- [ ] C4 — Verification links are single-use, time-limited, and resends are throttled.

## D. Data at rest

- [ ] D1 — Every attribute this endpoint stores appears in the protected-attributes table, or is added to it in the same change.
- [ ] D2 — The address is stored encrypted, and every lookup by address goes through the keyed value.
- [ ] D3 — Nothing in the change claims protection the table does not grant.
- [ ] D4 — Every encrypted value stores the identifier of the key that produced it.
- [ ] D5 — No key is stored in the database it protects.

## E. Leakage

- [ ] E1 — No log line, at any level, contains a password, a token, a one-time code, a receipt or the full body of a financial write.
- [ ] E2 — No amount or record identifier is logged at informational level or above.
- [ ] E3 — Nothing on the never-log list leaves the service in an error report or crash payload.
- [ ] E4 — Redaction is enforced centrally, not by each call site remembering.
- [ ] E5 — No error message names another user, another wallet, or a value belonging to either.
- [ ] E6 — A caller who is not a member gets a not-found, never a forbidden, so existence is not disclosed.

## F. Accountability

- [ ] F1 — Every act this endpoint performs that appears in the event vocabulary produces exactly one entry.
- [ ] F2 — The entry names the actor, the subject, the act, the time and the request correlation.
- [ ] F3 — The entry contains nothing on the exclusion list.
- [ ] F4 — An operator acting on a user's behalf is distinguishable from the user acting alone.
- [ ] F5 — No new event name was invented locally; a genuinely new event was added to the vocabulary in the same change.
- [ ] F6 — Nothing writes to a second audit store.

## G. Retention and departure

- [ ] G1 — Every retention period this feature introduces is a number in the retention table.
- [ ] G2 — No user-facing text states a shorter period than the table allows.
- [ ] G3 — A cancelled deletion loses nothing.
- [ ] G4 — A completed deletion leaves no readable identity attribute, including in a restored backup.
- [ ] G5 — A shared wallet keeps its history and balances when one member departs.
- [ ] G6 — An export served during the grace period does not reset the clock.

## H. Privileged and machine access

- [ ] H1 — A correct operator password alone does not grant access.
- [ ] H2 — Second-factor recovery exists and does not reduce to asking someone to switch it off.
- [ ] H3 — An operator acts through other modules' application services, so the same invariants apply.
- [ ] H4 — A provider message is checked for signature, issuer, **audience**, time window and replay.
- [ ] H5 — An already-processed message is acknowledged without effect, not refused.
- [ ] H6 — Unverifiable key material causes rejection, never acceptance and never deferred trust.

## I. Transport and contract hygiene

- [ ] I1 — A plaintext request is refused, not redirected.
- [ ] I2 — Every error code this endpoint returns exists in the shared catalogue.
- [ ] I3 — No security rule is restated here that the baseline already fixes; the endpoint references it.
- [ ] I4 — Every user-facing promise touched by this change still maps to a requirement.

## Using this list

A reviewer who answers "no" to any line either fixes the endpoint or records why the exception is correct in that feature's plan. A "no" with no record is a defect. The list is also the source for the reviewer exercise in [quickstart.md](../quickstart.md).
