# Quickstart: Operator Sign-in

A run-and-verify guide. Everything runs in the `dev` container (host PHP has no `pdo_pgsql`).

## Prerequisites

```bash
dev project open
dev migrate
# Admin keys (local only; production uses the secret store)
#   ADMIN_KEYS={"k1":"<base64 32 bytes>"}  ADMIN_ACTIVE_KEY=k1
#   ADMIN_LOOKUP_KEY=<base64 32 bytes>      ADMIN_CODE_KEY=<base64 32 bytes>
dev exec -- php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'   # run four times
```

## Automated checks

```bash
dev exec -- vendor/bin/pest tests/Unit/Admin/TotpTest.php tests/Unit/Admin/Base32Test.php tests/Unit/Admin/RecoveryCodeTest.php
dev exec -- vendor/bin/pest tests/Feature/Admin/Auth tests/Feature/Admin/AdminRouteSurfaceTest.php
dev exec -- vendor/bin/pest tests/Arch
dev exec -- vendor/bin/pest tests/Contract/OpenApi     # unchanged: this feature adds no API
dev exec -- composer test                              # the whole gate
```

## Manual / Playwright walk-through

Code helper (no package; Node's built-in crypto), used by the Playwright run to type codes:

```js
// totp(keyBase32) → six digits for "now"
const totp = (key, t = Math.floor(Date.now() / 30000)) => { /* base32-decode, HMAC-SHA1(pack64(t)), dynamic truncation */ };
```

1. **Create the first operator** — `dev admin:operator:create mariam@example.test --name="Mariam S"` prints a temporary password. Expect exit 0; re-running with the same email exits 1.
2. **First sign-in** — open `http://saveme-backend.test/admin`: redirected to `/admin/sign-in`. Enter the email and temporary password → "Choose a new password". Try `short` → rule message. Save a 12+ character password → "Set up your authenticator".
3. **Enrol** — read `[data-test=enrol-key]`, compute a code, submit → recovery codes page with ten `XXXXX-XXXXX` codes. Record two of them. Reload: the codes are gone. Tick "I have saved…", Continue → Overview with "Mariam S · Two-factor on" in the sidebar.
4. **Sign out / in** — press Sign out → step 1 "You've been signed out." Sign in with the new password; on step 2 type the code → Overview. Typing the same code again in a second browser within its step → "That code didn't match…".
5. **Recovery code** — sign out; at step 2 choose "Use a recovery code", type one recorded code in lower case without the hyphen → signed in, "You have 9 of 10 left". Re-use it in another sign-in → refused.
6. **Security** — open Security: linked date, "9 of 10", last sign-in. Wait 10 minutes (or set the clock in a test) and press "Generate new recovery codes" → factor prompt; enter a code → ten new codes; the second recorded code now fails.
7. **Lockout** — five wrong passwords → "Too many attempts. Try again in 15 minutes." even with the right password; repeat with an unknown email → the same message.
8. **Idle** — sign in; advance time 31 minutes (feature test) or wait → next click lands on step 1 with the idle message.
9. **Re-enrolment** — create two more operators (`nour@…`, `karim@…`), enrol both. As Nour, at step 2 press "Lost your device?" → "Request sent…". As Mariam, open Re-enrolment requests → Approve → factor prompt → reason "Verified by phone call" → 1/2. As Karim, approve → request disappears. Nour's old code and recovery codes fail; her next sign-in after the password goes to "Set up your authenticator", then new recovery codes.
10. **Guard** — with a mobile app bearer token, `curl -H "Authorization: Bearer …" http://saveme-backend.test/admin` → redirect to sign-in. Signed out, POST a captured `/livewire/update` payload for `SecurityPage` → 401.
11. **Arabic** — switch language on step 1 → right-to-left; codes, key, email and numbers stay left-to-right with Western digits.
12. **Commands** — `dev admin:operator:deactivate karim@example.test` → Karim's open session is signed out on its next click; deactivating the last active operator is refused.

Expected audit trail (via `database-query` or tinker read): every step above shows exactly one entry with the events in [contracts/commands-and-audit.md](./contracts/commands-and-audit.md), none containing an email, password, key or code.
