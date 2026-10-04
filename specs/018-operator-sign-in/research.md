# Research: Operator Sign-in

Every decision below was taken with delegated authority (owner away, 2026-10-04): the most conservative option that satisfies the spec, the constitution and the security baseline, with no new dependency.

## R-1 One-time codes: in-house RFC 6238, no package

**Decision**: implement TOTP in the Admin module's Domain layer (`App\Modules\Admin\Domain\SecondFactor`): RFC 4226 HOTP with HMAC-SHA1 through PHP's `hash_hmac('sha1', …, binary: true)`, dynamic truncation, 6 digits (`% 10**6`, left-padded); RFC 6238 time step `floor(unix / 30)`; verification across steps `t-1, t, t+1` with `hash_equals` on every candidate (no early exit). Secrets are 20 random bytes (`random_bytes(20)`, 160 bits, RFC 4226 §4 recommendation).

**Rationale**: about 60 lines of arithmetic, fully specified by two RFCs with published test vectors (RFC 6238 Appendix B, SHA-1 column; RFC 4226 Appendix D). CONTRIBUTING item 17 makes any package an owner approval, and the owner is away. `hash_hmac` and `random_bytes` are core PHP.

**Alternatives**: `pragmarx/google2fa` or `spomky-labs/otphp` — mature, but a dependency needing approval; Laravel Fortify — brings its own guard, routes and views that collide with P-18 and the operator guard. Rejected.

**Proof**: `tests/Unit/Admin/TotpTest.php` runs the RFC 6238 SHA-1 vectors (T = 59, 1111111109, 1111111111, 1234567890, 2000000000, 20000000000; 8-digit expected values truncated to the last 6 digits per the RFC's own truncation) and the RFC 4226 HOTP vectors (counter 0–9 for the ASCII secret `12345678901234567890`).

## R-2 Base32 in-house

**Decision**: RFC 4648 base32 (alphabet `A–Z2–7`), encode without padding for the setup key and the `secret=` parameter, decode case-insensitively, ignoring spaces and `=`; a decode of any other character fails. Domain class `Base32`.

**Rationale**: authenticator apps expect unpadded upper-case base32. Twenty bytes encode to exactly 32 characters, displayed in eight groups of four.

**Proof**: RFC 4648 §10 test vectors (`f`, `fo`, …, `foobar`) in `tests/Unit/Admin/Base32Test.php`, plus a round trip of random 20-byte secrets.

## R-3 Replay protection

**Decision**: `operator_accounts.last_code_step` (bigint, nullable). A code is accepted only if its matched step is **greater than** `last_code_step`; acceptance writes the step in the same transaction as the act it authorises, with `SELECT … FOR UPDATE` on the operator row so two concurrent submissions of one code cannot both pass. The same rule applies to re-confirmation codes.

**Rationale**: RFC 6238 §5.2 ("the verifier MUST NOT accept the second attempt of the same OTP"); comparing steps rather than codes also refuses an older code that is still inside the drift window.

## R-4 Enrolment without a QR library

**Decision**: the setup page shows (a) the setup key in groups of four, inside `<x-admin::ltr>`, with copy; (b) the `otpauth://totp/{issuer}:{email}?secret=…&issuer=…&algorithm=SHA1&digits=6&period=30` link as copyable text and as a link (it opens a mobile authenticator directly when the page is opened on the phone); (c) the account label and settings in words. **No scannable picture.**

**Rationale**: no QR or image library is installed (`composer show`, `package.json`: none). An in-house QR encoder (Reed–Solomon, masking, version selection) is several hundred lines of error-prone code for a screen a handful of staff see once; a wrong module silently produces an unscannable code. Every mainstream authenticator accepts a typed key.

**Alternatives**: `bacon/bacon-qr-code` (needs approval; recorded as the follow-up if the owner wants a picture: the page has a slot for it, nothing else changes); in-house encoder (rejected above); a third-party QR web service (leaks the secret — rejected outright).

## R-5 The operator guard and provider

**Decision**: `config/auth.php` gains guard `operator` (`driver: session`, `provider: operators`) and provider `operators` (`driver: eloquent`, model `App\Modules\Admin\Infrastructure\Models\OperatorAccount`). The `web` guard keeps no provider (spec 007 R-20); the default guard stays `api`. The model implements `Authenticatable` but `getAuthPassword()` is never used for sign-in: the sign-in service verifies the password itself (it needs the lockout, timing equalisation and rehash rules) and calls `Auth::guard('operator')->login()` only after the second factor. "Remember me" is disabled (`getRememberTokenName()` returns empty; no column).

**Rationale**: ADR-0006 rule 7 and P-08 rule 4: a separate guard over accounts owned by Admin. A Sanctum bearer token authenticates only on `api` (provider `users`), so it can never satisfy `operator` (FR-072); `GuardSeparationTest` proves it.

## R-6 Half-finished sign-in lives in the session, not the guard

**Decision**: until the second factor (or the enrolment, for a new or re-enrolled account) is complete, the operator is **not** logged in to the guard. The session holds `admin.pending = {operator_id, stage, started_at, code_failures, epoch}` with `stage ∈ {second_factor, password_change, enrolment}`. Lifetimes: `second_factor` 5 minutes (FR-014); `password_change` and `enrolment` 15 minutes (time to install an app). A stale or epoch-mismatched pending state is discarded and step 1 shown with the timeout message. The session id is regenerated when the pending state is created and again at full sign-in (FR-016).

**Rationale**: being "logged in" with a missing factor is exactly the state P-18 rule 4 forbids; keeping it out of the guard means every guarded route is safe by default even if a check is forgotten.

## R-7 Session lifetime, epoch and per-request checks

**Decision**: middleware `EnsureOperatorSignedIn` on every guarded route, and as Livewire persistent middleware. On each request it: resolves the guard user (fresh database read — no cache); refuses if state ≠ `active`, `enrolled_at` null, or `session admin.epoch ≠ operator.credential_epoch`; refuses if `now − admin.last_seen_at > 30 min` (idle) or `now − admin.signed_in_at > 12 h` (absolute); otherwise sets `admin.last_seen_at = now`. A refusal logs out the guard, invalidates the session, and redirects to step 1 with the reason message (for a component update: `401`, which the Livewire client turns into a redirect through the back office's `Livewire.hook('request')` handler in `admin.js`).

`credential_epoch` (integer) increments on password change, deactivation, re-enrolment completion and authenticator replacement-by-approval; a password change then writes the new epoch into the current session only, so this session survives and every other one fails its next request (FR-042). No `sessions` table scan is needed, and the database session driver's `user_id` column (filled from the default guard) is irrelevant.

**Rationale**: the baseline's "no positive cache of validity" carried over to operators; idle and absolute limits are enforced by the app, independent of `SESSION_LIFETIME` (120 minutes globally).

**Idle and absolute expiry are not audited** (no act took place); sign-out is.

## R-8 Guarding Livewire's shared update endpoint

**Decision**, three layers:

1. **Route group**: every guarded page route is registered in `routes.php` inside a group with `EnsureOperatorSignedIn`, which is also added to `Livewire::addPersistentMiddleware()`. Livewire re-runs persistent middleware on `/livewire/update` for components whose original page route carried it.
2. **Component base**: every back-office Livewire component except an allowlist extends `App\Modules\Admin\Http\Livewire\BackOfficeComponent`, whose `boot()` (Livewire runs it on every request, initial and update) calls the same check as the middleware and aborts `401`. This covers the case persistent middleware cannot: a component whose snapshot names a route that was not guarded, or a forged snapshot path.
3. **Arch gate**: `tests/Arch/AdminBackOfficeTest.php` gains "every class in `Admin\Http\Livewire` extends `BackOfficeComponent`, except `LocaleSwitcher` and `Gallery`" (both read no data; the gallery is local-only, spec 006 FR-041).

The existing `OperatorNotificationBell` moves to the base class.

**Rationale**: P-18 rules 4 and 7 — "including Livewire's own update endpoint", "authorize on every request". Moving the update route under `/admin` (`Livewire::setUpdateRoute`) was considered: it is one global route for every Livewire component, and the gallery and locale switcher also use it, so it does not remove the need for the per-component check; rejected as churn without added safety.

**Pre-authentication screens are plain Blade forms posted to controllers**, not Livewire components, so nothing stateful runs before sign-in. The six digit boxes are progressive enhancement with Alpine over one `name="one_time_code"` input (works without JavaScript).

## R-9 Lockout and throttling

**Decision**: mirror Identity's mechanism in Admin's own storage. Table `operator_sign_in_failures` keyed by the operator **email lookup value** (registered or not), with `consecutive_failures`, `locked_until`, `last_failed_at`; thresholds from `config/admin.php` `auth.lockout` = the baseline numbers (5 → 15 min, 10 → 60 min, cap 60). Origin limit through `RateLimiter` key `admin-origin:{coarse origin}`, 20 per 15 minutes. Wrong password, wrong code, wrong recovery code and wrong re-confirmation all hit both counters (FR-051, FR-053). Full sign-in clears the account counter. The re-enrolment request and re-confirmation also have a budget of 5 attempts per 15 minutes per operator (RateLimiter key `admin-reconfirm:{operator_id}`). Unknown email: a dummy argon2id verification equalises timing (as spec 007).

**Rationale**: separate counters (FR-052) so operator attacks and app attacks never lock each other; same numbers, so the baseline needs no new table.

## R-10 Protection at rest: Admin's own keyring

**Decision**: Admin seals `email`, `display_name`, `secret` and `pending_secret` with XChaCha20-Poly1305 (libsodium, core), per-value nonce, key reference stored in the value (`v1.{ref}.{base64}`), associated data binding the value to the operator id and the column name. Keys come from `config/admin.php` (`ADMIN_KEYS` JSON `{ref: base64 key}`, `ADMIN_ACTIVE_KEY`), lookup from `ADMIN_LOOKUP_KEY` (HMAC-SHA256 of the normalised email; normalisation is Identity's `AddressNormaliser`, a Domain class, so the rule is identical). Recovery codes are stored as HMAC-SHA256 under the lookup key's sibling `ADMIN_CODE_KEY` (a fast keyed hash is right: each code has 50 bits of entropy and the key lives outside the database; argon2id would cost 10 × 300 ms per sign-in attempt).

The sealing class is a small copy of Identity's `Sealer` inside `Admin\Infrastructure\Crypto`: P-02 forbids importing Identity's Infrastructure, and the shared kernel holds no infrastructure. Duplication is ~50 lines and is recorded.

**Rationale**: baseline "What is protected at rest" rule 4 and "Keys and rotation" (key id stored beside the value, keys outside the database). Admin keys are separate from Identity's so an Identity key compromise does not expose operators and vice versa.

**Alternative**: Laravel's `encrypted` cast (APP_KEY, AES-256-CBC + HMAC) — authenticated, but no key reference in the value and rotation only through `APP_PREVIOUS_KEYS`; rejected for consistency with the baseline rule.

## R-11 Recovery codes

**Decision**: ten codes, each 10 characters from the Crockford-style alphabet `0123456789ABCDEFGHJKMNPQRSTVWXYZ` (no I, L, O, U), shown as `XXXXX-XXXXX` (50 bits each). Input is normalised: upper-cased, spaces and hyphens removed, `I/L → 1`, `O → 0`. Stored as `operator_recovery_codes(operator_id, set_id, code_hash, used_at)`. Verification compares the candidate's HMAC against every unused hash of the current set with `hash_equals`, under the operator row lock; use sets `used_at`. A new set (`set_id` UUIDv7) deletes the previous set's rows in the same transaction.

**One display only**: codes are generated in the request that completes enrolment (or regeneration) and passed to the view of **that response only**: as the direct response to the confirming POST for the enrolment flow (no redirect, `Cache-Control: no-store`), and as a protected (non-persisted) Livewire property for regeneration on the security page. They never enter the session, a public Livewire property, a log or the database in readable form. Reloading shows nothing (the spec's edge case reads accordingly).

## R-12 Re-confirmation ("step-up") as a reusable trait

**Decision**: trait `RequiresRecentFactor` beside `RequiresReason`, same shape: a public action calls `$this->requireRecentFactor('method', [$args])`; if `session('admin.factor_confirmed_at')` is within 10 minutes it calls the protected method at once, otherwise it opens `<x-admin::factor-prompt>` (a dialog composed of existing primitives) and `confirmFactor(string $code)` verifies a code or a recovery code through the sign-in service's verifier (replay rule, lockout counter, audit on failure), stores `factor_confirmed_at`, then calls the pending protected method. Full sign-in sets `factor_confirmed_at`. For re-enrolment approval the factor prompt runs first, then the reason prompt. Later features (B11, B12) use the same trait (FR-061).

## R-13 Audit mapping

**Decision**: Admin's `AdminAuditService` (the name the constitution gives) wraps Identity's `AuditLog::record()`. Identity adds `SubjectType::Operator` and a migration that replaces `audit_entries_subject_type_check` to include `operator` (Identity owns the table). Mapping:

| Act | event | actor | subject | detail |
|---|---|---|---|---|
| Full sign-in | `sign_in_succeeded` | operator, own id | operator | `factor: code \| recovery_code`, `recovery_codes_left` |
| Wrong password, code, recovery code, re-confirmation | `sign_in_failed` | system, null | operator id, or null for an unknown email | `stage`, `lookup_prefix` (unknown email only) |
| Lock begins | `lockout_applied` | system, null | operator or null | `minutes` (existing `LockoutDetail`) |
| Sign-out | `session_revoked` | operator | operator | `reason: signed_out` |
| Password change (self) | `password_changed` | operator | operator | — |
| Everything else | `admin_action` | operator (or system for commands and expiry) | operator (the account acted on) | `action` (closed list below), `reason` (approvals and declines), `request_id` of the re-enrolment request where relevant |

`admin_action` actions: `operator_created`, `operator_deactivated`, `temporary_password_issued`, `enrolment_completed`, `recovery_codes_regenerated`, `authenticator_replaced`, `reenrolment_requested`, `reenrolment_approved`, `reenrolment_declined`, `reenrolment_completed`, `reenrolment_expired`, `reenrolment_cancelled`. The second approval writes `reenrolment_approved` **and** `reenrolment_completed`, because they are two acts (the approval by the approver; the invalidation by the system) — each appears once.

Expiry is recorded by the scheduled `admin:expire-reenrolment-requests` command (system actor) so "expired" has an instant without depending on someone looking.

Baseline amendments in the same change: "The audit entry" `subject_type` adds `operator`; "What is recorded" notes that Access, Sessions and Credentials events whose subject is an operator are written by Admin; "What is protected at rest" adds rows for operator email, display name, password, second-factor secret, recovery codes and sign-in failure counter.

## R-14 Provisioning commands

**Decision** (namespace `admin:`; classes in `Admin\Http\Console`, the convention every module follows, e.g. `Sharing\Http\Console\ExpireInvitations`; scheduling in `routes/console.php`):

- `admin:operator:create {email} {--name=}` — validates the email, refuses a duplicate lookup, generates a 20-character temporary password (random, alphabet as R-11), prints it once, stores its argon2id hash with state `awaiting_password_change`.
- `admin:operator:reset-password {email}` — issues a new temporary password, sets state to `awaiting_password_change`, increments the epoch (ends sessions); the enrolled secret and recovery codes are kept, so the next sign-in still asks for the code before the new password (data-model, states); refuses a deactivated operator.
- `admin:operator:deactivate {email}` — refuses the last active operator; sets `deactivated`, increments epoch, cancels any open re-enrolment request.
- `admin:expire-reenrolment-requests` — scheduled every 5 minutes.

All run `--no-interaction` cleanly; none prints or logs anything but the temporary password to the terminal.

## R-15 Password rules

**Decision**: argon2id at the baseline parameters through Laravel's hasher (already configured for Identity); rehash on sign-in when parameters are below the setting. Operator passwords: 12–128 characters, not equal to the normalised email, not equal to the current or temporary password. Identity's `PasswordPolicy` (8 minimum) is not reused because operators get a stricter floor; the rule lives in `Admin\Domain\OperatorPasswordPolicy`.

## R-16 Response headers

**Decision**: middleware `BackOfficeHeaders` on every `/admin` route (guest and guarded): `Cache-Control: no-store, private`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, `X-Content-Type-Options: nosniff`. Session cookie `SameSite=lax` stays (strict breaks the first navigation from a mailed link to an admin page, which B12 notifications may use); `secure` is on outside local through `SESSION_SECURE_COOKIE`.

## R-17 Shell binding

**Decision**: a view composer registered in `AdminServiceProvider` for `admin::components.layouts.shell` fills `operator` (an `OperatorPresence` built from the signed-in account: display name, first grapheme, status key `admin::shell.operator.two_factor_on`) and `signOutUrl` (`route('admin.sign-out')`) when the caller did not pass them. The shell file is not edited (spec 006 FR-051). The sidebar's operator block gains a link to the security page through a navigation entry, not through a shell edit: the registry gets `security` (group `configure`, icon `lock`) and `reenrolment-requests` (group `configure`, icon `key`, badge = open requests not by the viewer).

## R-18 Landing page

**Decision**: `/admin` (`admin.overview`) becomes a minimal guarded Livewire page: page header "Overview", an empty state "Back-office screens arrive with later features", and the low-recovery-codes alert (≤ 3 left). B12 replaces its body. Without it there is nowhere to land after sign-in.

## R-19 Design source

The design source is now version `1791137074-53df` (spec 006 recorded `1790356228-859b`). The `SignIn` and `SignInAr` boards draw step 2 only; their copy is used verbatim except the re-enrolment hint (Clarifications). Step 1, the recovery-code variant, "Lost your device?", password change, enrolment and the recovery-codes display reuse the same split layout (brand panel at the start, 420 px form column) and the spec 006 primitives; the security page and the requests page use the shell. The countdown under the digits is computed client-side from the server's time offset sent with the page (`data-server-time`), so a skewed operator clock still shows the right seconds.
