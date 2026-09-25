# B1 · Identity & authentication

**Call with**: `/speckit-specify B1` · **Source**: the Phase B brief set, recorded 2026-09-25

## Description

Users register with email and password, verify the email, log in from an Android device to receive a device-scoped token, see and revoke their device sessions, reset a forgotten password, log out, and request account deletion. Endpoints: `POST /auth/register`, `POST /auth/verify-email`, `POST /auth/resend-verification`, `POST /auth/login` (returns token + user + entitlements summary), `GET/DELETE /auth/sessions`, `POST /auth/forgot-password`, `POST /auth/reset-password`, `POST /auth/logout`, `POST /account/deletion-request`, `DELETE /account/deletion-request`. Google Sign-In is specified as an extension point (`POST /auth/google`) behind a feature flag; phone/OTP is out of scope.

## Considerations

Registration also creates the Profile (spec 2) with locale from `Accept-Language` and a default Free subscription (spec 3) — define the transactional boundary. Login must accept a `device` object (`id`, `name`, `platform`, `app_version`) and must be idempotent per device (re-login rotates the token). Restore-purchases hook (spec 10) is called after login; leave the seam. Deletion request enters a 30-day grace with ownership transfer rules for shared wallets defined in spec 15. Email delivery is queued; verification links expire in 24h and are single-use. Acceptance: brute-force lockout, token revocation propagates within one request, deleted account cannot log in but can cancel deletion inside grace.

## Reconcile at specify time

The brief predates decisions ratified since. These collide with it; the ratified side wins unless the spec deliberately supersedes it through a decision record or an amendment.

| Brief says | Already decided | Resolution needed |
|---|---|---|
| Sanctum personal access tokens (A4 brief) | Not installed. The security baseline describes sessions by behaviour and leaves the package to this spec | New dependency — needs the owner's approval at plan time |
| "Deleted account cannot log in but can cancel deletion inside grace" | Security baseline, Deletion rule 2: the person may keep using the account during the grace period, writes included | Direct conflict. Either the baseline's rule changes by amendment, or this acceptance line does |
| Stable `User` model with auto-increment id | The only model today is the stock starter-kit `User` (auto-increment, plaintext email) | Replace it: UUIDv7 (P-06), encrypted address plus keyed lookup value (baseline), module `Identity` |
| Error codes for login, lockout, verification, reset | Already reserved in the catalogue by spec 004 | Use `INVALID_CREDENTIALS`, `ACCOUNT_LOCKED`, `EMAIL_NOT_VERIFIED`, `VERIFICATION_TOKEN_INVALID`, `RESET_TOKEN_INVALID`; decide whether to rename `EMAIL_NOT_VERIFIED` to the documents' word, "address" |
