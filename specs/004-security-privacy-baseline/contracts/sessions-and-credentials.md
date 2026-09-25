# Contract: Sessions, Credentials and the Gates

Normative. What Identity must be true of, written so a reviewer can check one endpoint at a time.

## Session credentials

| Property | Value |
|---|---|
| Granularity | one per signed-in device |
| Form | opaque, at least 256 bits of entropy |
| Stored as | a hash; the readable value exists only in the response that issues it |
| Expiry | 90 days of inactivity, extended on each successful use |
| Absolute cap | none in MVP |
| Revocation | effective on the next request, with no cache of validity |
| Blast radius | revoking one leaves every other session working |

**Rules**

- A password change revokes every session **except** the one that made the change.
- A revoked session's row survives, so the audit trail can still name it.
- The session list shown to an owner names the device and the times. It exposes nothing that helps someone who can already see the screen.

## Passwords

| Property | Value |
|---|---|
| Function | argon2id |
| Memory | 65536 KiB |
| Iterations | 4 |
| Parallelism | 1 |
| Target cost | 250–500 ms per hash on the target hardware |

**Rules**

- Parameters are re-measured on the deployment's hardware and raised until the target cost is met.
- A password is rehashed on the next successful sign-in whenever its stored parameters are below the current setting.
- The hash is never returned by any endpoint, at any level of privilege.

## Tokens

| Token | Lifetime | Single use | Also invalidated by |
|---|---|---|---|
| Password reset | 60 minutes | yes | any password change; any later reset request |
| Address verification | 24 hours | yes | a later verification request for the same address |

Resends are limited to three per hour per address. Both are opaque random values, stored hashed, sent once.

## Lockout

| Scope | Trigger | Effect |
|---|---|---|
| Account | 5 consecutive failures | refuse 15 minutes |
| Account | 10 consecutive failures | refuse 60 minutes |
| Origin | 20 failures across any accounts in 15 minutes | refuse that origin 15 minutes |

**Rules**

- An account lock caps at 60 minutes and is **never** permanent.
- A successful password reset, which requires the mailbox, clears the lock immediately. This is what stops the lock being a weapon against the account's owner.
- A successful sign-in resets the counter.
- The refusal is `423 ACCOUNT_LOCKED` with `Retry-After`, distinct from a wrong password, so the app says "wait" rather than "you typed it wrong".

## Uniform answers, so an address cannot be probed

| Surface | Answer, whether or not the address is registered |
|---|---|
| Sign-in | `401 INVALID_CREDENTIALS` |
| Password reset request | `202`, "if that address is registered, a link is on its way" |
| Registration | `202`, then a mail that differs by case: welcome, or "you already have an account" |
| Invitation | `202`, with no indication of whether the invitee is registered |

**Rules**

- Response timing is equalised, so a wrong password and an unknown address take comparable time.
- The difference lives in the mail body, which reaches the person who owns the address and nobody else.
- Three surfaces answering uniformly and a fourth leaking makes the other three pointless. All four, or none.

## The verification gate

| Action | Before verification |
|---|---|
| Read anything they were invited to | allowed |
| Manage their own profile | allowed |
| Manage their own sessions | allowed |
| **Any write to any wallet** | **refused** |

The refusal is `403 EMAIL_NOT_VERIFIED`, its own code, so the app offers to resend rather than showing a generic denial.

Letting an unverified invitee read is what makes an invitation worth sending. Barring writes is what stops a typo'd or hostile address from putting entries into someone's shared wallet.

## Transport

- TLS 1.2 or better for every request.
- A plaintext request to an API path is **refused** with `400 INSECURE_TRANSPORT`, not redirected. A redirect teaches a client that plaintext is a working first step, and by then the credential has already been sent.
- Responses carry a strict-transport-security header with a one-year duration.

## Reviewer checks

- [ ] Ending one session leaves the others working.
- [ ] A revoked credential fails on the very next request.
- [ ] No endpoint returns a password hash or a credential hash.
- [ ] Sign-in, reset, registration and invitation are indistinguishable for a known and an unknown address.
- [ ] A locked account unlocks within 60 minutes, and a reset unlocks it immediately.
- [ ] Every write path is behind the verification gate; no read path is.
- [ ] A plaintext request is refused, not redirected.
