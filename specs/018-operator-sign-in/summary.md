# Summary: Operator Sign-in (spec 018, D2)

**Date**: 2026-10-04 · **Status**: implemented (T001–T069), QA and browser E2E green, committed on `main` (f977c83).

## What was built

Operator sign-in for the back office, entirely in the Admin module, with no new composer or npm dependency.

- **Operator Accounts** on a separate `operator` session guard. Email and display name are sealed under Admin's own keyring (about 50 lines copied from Identity's sealer, because modules may not import each other's Infrastructure) and found by a keyed lookup value.
- **Two-step sign-in**: email and password, then an in-house RFC 6238 one-time code (HMAC-SHA1, own base32, 6 digits, 30 s step, ±1 step window) or a recovery code. A replayed code is refused by storing the last accepted step under a row lock. The half-finished state lives in the session, never in the guard.
- **Enrolment**: the setup key in groups of four plus a copyable `otpauth://` link; no QR image. Ten single-use recovery codes (`XXXXX-XXXXX`, keyed HMAC hashes) are shown once, in the response that creates them, with "I saved them" required to continue.
- **Security page**: change password, regenerate recovery codes, link a new authenticator. Each needs a code from the last 10 minutes (`RequiresRecentFactor`, reusable by B11 and B12).
- **Re-enrolment**: "Lost your device?" starts a request that two other operators must each approve (re-confirming their own code and giving a reason) within 72 hours; approval invalidates the old secret, recovery codes and the requester's sessions. A requests table offers approve and decline.
- **Provisioning**: only `admin:operator:create` (prints a 20-character temporary password once; expires in 72 hours; must be changed at first sign-in), `admin:operator:reset-password`, `admin:operator:deactivate` (refuses the last active operator) and `admin:operator:expire-reenrolment-requests` (scheduled).
- **Guarding by default**: an operator route group, `EnsureOperatorSignedIn` as Livewire persistent middleware, a `BackOfficeComponent` base re-checking sign-in on every Livewire request, and arch gates (every component extends it; `LocaleSwitcher` and the local-only gallery are the exceptions). Application (Sanctum) credentials never reach `/admin`.
- **Sessions**: 30 minutes idle, 12 hours absolute; a credential epoch ends sessions on a password change, deactivation or approved re-enrolment. Lockout follows the security baseline (5 failures → 15 minutes, 10 → 60, 20 per origin per 15 minutes), stored separately from the application's counters.
- **Audit**: through Identity's log; a migration adds `operator` as a subject type. Access events reuse the existing names with `actor_id` = the operator's UUID; everything else is `admin_action`. No entry holds a secret.
- **Screens** in English and Arabic from the design source (`SignIn`/`SignInAr` boards, version `1791137074-53df`). The shell's operator block and sign-out are bound by a view composer; the shell file is unchanged.
- **Docs**: security baseline and glossary amendments (Operator Account); `checklists/fidelity.md`.
- **Migrations** (six, additive): `2026_10_04_100000_allow_operator_audit_subject` (Identity) and `…100001`–`…100005` (Admin: operator_accounts, recovery_codes, reenrolment_requests, reenrolment_approvals, sign_in_failures).
- **Side fixes found on the way**: a spec 006 slide-over dialog that showed itself while closed; a Livewire update closing any open modal (it hid a wrong re-confirmation code's own error); the sign-out button was missing the contract's `data-test="sign-out"`.

## Results

- **QA** (commands and HTTP): 20 checks, 18 run live and passing; 2 left to feature tests (last-active-operator refusal, real Sanctum token) because the dev data and token table don't allow them. Report `qa/qa-20261004-230000.md`.
- **Browser E2E** (Playwright): 19/19 scenarios after two fixes — English and Arabic sign-in, step 2 and recovery, forced password change, enrolment, recovery codes shown once, Security page dialogs, lost device, the requests table, 390 px layout, keyboard-only code entry, RTL. No console errors. Report `test-reports/validation-20261004-230000.md`; 33 screenshots in `test-reports/screenshots/`.
- **Gates**: Pint and Larastan clean; Unit + Feature + Contract 2,368 passed, 2 opt-in performance tests skipped.

## Deployment

- Run the six migrations.
- Put `ADMIN_KEYS`, `ADMIN_ACTIVE_KEY`, `ADMIN_LOOKUP_KEY` and `ADMIN_CODE_KEY` in the secret store (empty in `.env.example`; generated in the local `.env`; fixed test values in `phpunit.xml`).
- Create the first operator with `php artisan admin:operator:create`, and enrol **at least three** operators before go-live (otherwise a lost device with no recovery codes can only be fixed at the command line).

## Assumed defaults

Owner away with delegated authority; each the conservative option.

1. TOTP in-house to RFC 6238; no package (any new dependency needs owner approval). RFC 6238 and RFC 4226 test vectors are unit tests.
2. No QR picture (no QR library installed); `bacon/bacon-qr-code` is the follow-up if approved.
3. Every operator, including the first, is created by an artisan command: no public sign-up, no invite screen, no mailed password reset (weaker than the two factors it guards).
4. Recovery codes are never in the session, a public Livewire property or the database in readable form; regenerating invalidates the old set.
5. "Lost your device?" is offered after a correct password whether or not recovery codes remain; it still needs two approvals from two different operators, neither the requester. Requests expire after 72 hours and can be declined.
6. Session limits: 30 min idle, 12 h absolute, 5 minutes for the code step and 15 minutes for the password, enrolment and codes steps; no "remember this device".
7. Sensitive actions need a code from the last 10 minutes.
8. Email, display name and the second-factor secret are encrypted (XChaCha20) under Admin's own keys.
9. Lockout numbers equal the baseline's; wrong codes count on the same counter as wrong passwords; attempts made while locked are not counted and write nothing; a lock does not end an existing session.
10. The copy follows the baseline ("two other operators") rather than the design board ("a second operator").
11. The operator's language stays per-session; per-operator read state for Operator Notifications is left to B12.
12. Password policy 12–128 characters; temporary passwords last 72 hours; re-enrolment opening is limited to 5 per 15 minutes; five wrong codes discard the pending sign-in.
13. The notification bell is a `BackOfficeComponent`, mounted in the `admin::page` layout for signed-in operators.
14. The Security and Re-enrolment sidebar entries are registered by the composer at sign-in, not at boot (so spec 006's empty-registry tests still pass).
15. A wrong current password on the Security page and a wrong re-confirmation code both count toward the account lock.
16. After an approved re-enrolment, the next sign-in goes from the password straight to enrolment.

## Remaining risks

- Fewer than three operators means no in-app recovery for a lost device without recovery codes.
- No QR image: operators type the 32-character key or open the link on their phone.
- The Livewire update guard has two layers (persistent middleware and the `boot()` re-check); `ComponentGuardTest` should keep using real captured update payloads.
- The copied sealer and lockout logic may drift from Identity's versions.
- Display strings: the brand shows the `APP_NAME` value (`saveme-backend`) rather than "Save Me", and the sidebar says "Operator · 2FA on".
- QA's cleanup queries touched all open re-enrolment requests and sign-in failure rows from the last 6 hours on the dev database.
- One dev operator, `qa.operator@saveme.test`, remains active in the dev database.
