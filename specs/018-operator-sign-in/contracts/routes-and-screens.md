# Contract: Back-office routes and screens

Every route below sits under `/admin` in `app/Modules/Admin/Http/routes.php`, with middleware `web`, `ResolveAdminLocale` and `BackOfficeHeaders` (R-16). "Gate" names who may reach it; anything else gets the response in "Otherwise". This is the surface `AdminRouteSurfaceTest` asserts, and the surface Playwright QA drives.

## Gates

| Gate | Meaning | Otherwise |
|---|---|---|
| **guest** | no full operator sign-in in this session | a fully signed-in operator is redirected to `admin.overview` |
| **pending:{stage}** | `admin.pending` exists, not stale, epoch matches, stage = {stage} | redirect to `admin.sign-in` with the timeout message when stale; to the right stage otherwise |
| **operator** | `EnsureOperatorSignedIn` (R-7): guard user, `active`, enrolled, epoch match, idle ≤ 30 min, absolute ≤ 12 h | page: redirect `admin.sign-in` (intended URL kept); component update: `401` → client redirect |
| **local** | `app()->environment('local')` | route not registered (404) |

## Routes

| Method | Path | Name | Gate | Handler | Purpose |
|---|---|---|---|---|---|
| GET | `/admin/sign-in` | `admin.sign-in` | guest | `SignInController@show` | Step 1 |
| POST | `/admin/sign-in` | `admin.sign-in.submit` | guest | `SignInController@submit` | email + password → pending stage |
| GET | `/admin/sign-in/code` | `admin.sign-in.code` | pending:second_factor | `SecondFactorController@show` | Step 2 (design board) |
| POST | `/admin/sign-in/code` | `admin.sign-in.code.submit` | pending:second_factor | `SecondFactorController@submit` | verify code |
| GET | `/admin/sign-in/recovery` | `admin.sign-in.recovery` | pending:second_factor | `SecondFactorController@showRecovery` | recovery-code variant |
| POST | `/admin/sign-in/recovery` | `admin.sign-in.recovery.submit` | pending:second_factor | `SecondFactorController@submitRecovery` | verify recovery code |
| POST | `/admin/sign-in/restart` | `admin.sign-in.restart` | any | `SignInController@restart` | "Not you?": discard pending, regenerate session |
| GET | `/admin/sign-in/lost-device` | `admin.sign-in.lost-device` | pending:second_factor | `LostDeviceController@show` | explain and confirm |
| POST | `/admin/sign-in/lost-device` | `admin.sign-in.lost-device.submit` | pending:second_factor | `LostDeviceController@submit` | open re-enrolment request; ends pending; shows outcome page |
| GET | `/admin/password/new` | `admin.password.new` | pending:password_change | `NewPasswordController@show` | choose new password |
| POST | `/admin/password/new` | `admin.password.new.submit` | pending:password_change | `NewPasswordController@submit` | |
| GET | `/admin/enrol` | `admin.enrol` | pending:enrolment | `EnrolmentController@show` | setup key + link |
| POST | `/admin/enrol` | `admin.enrol.submit` | pending:enrolment | `EnrolmentController@submit` | confirm code; **response is the recovery-codes page** (200, no redirect) |
| POST | `/admin/enrol/done` | `admin.enrol.done` | pending:enrolment (codes issued) | `EnrolmentController@done` | checkbox confirmed → full sign-in → intended URL |
| POST | `/admin/sign-out` | `admin.sign-out` | operator | `SignOutController` | end session, audit, → `admin.sign-in` |
| GET | `/admin` | `admin.overview` | operator | Livewire `Overview` | landing (R-18) |
| GET | `/admin/security` | `admin.security` | operator | Livewire `SecurityPage` | own security |
| GET | `/admin/security/authenticator` | `admin.security.authenticator` | operator | Livewire `ReplaceAuthenticator` | link a new authenticator |
| GET | `/admin/reenrolment-requests` | `admin.reenrolment-requests` | operator | Livewire `ReenrolmentRequests` | approve / decline |
| GET | `/admin/_gallery` | `admin.gallery` | local | Livewire `Gallery` | unchanged (spec 006) |

After `admin.enrol.submit`, `admin.pending.stage` becomes `codes_shown`; `admin.enrol.done` accepts only that stage. A reload of `admin.enrol` in stage `codes_shown` goes straight to `done`'s confirmation form without codes (they are never re-shown).

All POSTs carry CSRF. All GET pages answer `Cache-Control: no-store`.

## Livewire component allowlist

Components not extending `BackOfficeComponent`: `LocaleSwitcher`, `Gallery`. Everything else in `Admin\Http\Livewire` extends it, including `OperatorNotificationBell`, `Overview`, `SecurityPage`, `ReplaceAuthenticator`, `ReenrolmentRequests`.

## Screens and copy (English; Arabic in `Lang/ar/auth.php`)

All guest and pending screens use one layout, `admin::layouts.auth` (built from primitives and tokens): brand panel at the start (logo, "Save Me · Back office", headline "Quiet tools for careful decisions.", the operators-only paragraph, the audit note), a 420 px form column, a two-segment step indicator, and the language switch at the top end.

| Screen | Step label | Heading | Body and controls | Errors / states |
|---|---|---|---|---|
| Step 1 | Step 1 of 2 · Password | Sign in to the back office | Email, Password, button "Continue" | "Email or password is incorrect." · "Too many attempts. Try again in N minutes." · "Your sign-in timed out. Start again." · "You were signed out after 30 minutes without activity." · "Your session reached its 12-hour limit. Sign in again." · "You've been signed out." |
| Step 2 (board) | Step 2 of 2 · Second factor | Enter your 6-digit code | "From the authenticator app linked to {email}. Not you?" six digit boxes (one field without JS), countdown "Code refreshes in N s", button "Verify & sign in", links "Use a recovery code", "Lost your device?", hint "Re-enrolling a lost authenticator needs two other operators to approve. You have N of 10 recovery codes left." | "That code didn't match. Try the current code, or use a recovery code." · lock message · "If codes keep failing, check that your phone's time is set automatically." (after 2 failures) |
| Recovery | Step 2 of 2 · Recovery code | Use a recovery code | field "Recovery code" (`XXXXX-XXXXX`), button "Verify & sign in", link "Use your authenticator code" | "That recovery code didn't match or was already used." · "You have no recovery codes left. Use “Lost your device?” instead." |
| Lost device | Step 2 of 2 · Recovery | Lost your device? | explanation of the two-operator approval and 72-hour window, button "Ask for re-enrolment", link back | outcome page: "Request sent. Two other operators must approve it before {instant}." / "A request is already waiting. It expires {instant}." / "Fewer than two other operators can approve right now; the request will wait." |
| New password | Set up · Password | Choose a new password | New password, Confirm, rules hint, button "Save password" | "Use at least 12 characters." · "Your password can't be your email." · "Choose a password different from the temporary one." · "The passwords don't match." |
| Enrol | Set up · Authenticator | Set up your authenticator | numbered steps: 1 open your authenticator app; 2 add an account with this key (key in groups of four, copy) or open this link (otpauth link, copy); account "{issuer}: {email}", time-based, 6 digits; 3 enter the code it shows; button "Link authenticator" | "That code didn't match. Check the key and your phone's time." · timed-out pending → step 1 |
| Recovery codes | Set up · Recovery codes | Save your recovery codes | "Each code works once. This is the only time they are shown." ten codes in two columns (LTR), "Copy all", "Download as text" (client-side blob, filename `save-me-recovery-codes.txt`), checkbox "I have saved these codes somewhere safe" (required), button "Continue" | checkbox missing → "Confirm you saved the codes to continue." |
| Overview | — (shell) | Overview | empty state "Back-office screens arrive with later features."; alert when ≤ 3 recovery codes left: "You have N recovery codes left. Generate a new set from Security." | — |
| Security | — (shell) | Security | description list: Authenticator "Linked {date}", Recovery codes "N of 10 left", Last sign-in "{instant}"; actions "Change password" (modal: current, new, confirm), "Generate new recovery codes" (confirm dialog → codes shown once in a card with copy/download and "Done"), "Link a new authenticator" (→ page) | toasts: "Password changed. Other sessions were signed out." · "New recovery codes generated. Your old codes no longer work." · "New authenticator linked." |
| Replace authenticator | — (shell) | Link a new authenticator | as Enrol, with "Your current authenticator keeps working until the new one is confirmed." | as Enrol |
| Re-enrolment requests | — (shell) | Re-enrolment requests | data table: Operator, Opened, Expires, Approvals (0/2, 1/2), actions "Approve", "Decline" (hidden on the viewer's own request and after their own approval); empty state "No requests are waiting." | factor prompt, then reason prompt; refusals as toasts: "You can't approve your own request." · "You've already approved this request." · "This request has expired." |
| Factor prompt (dialog) | — | Confirm it's you | "Enter the current code from your authenticator." six-digit field, link "Use a recovery code instead" (switches field), button "Confirm" | wrong code message as step 2 · lock message |

Every code, key, link, email and number is wrapped in `<x-admin::ltr>` and rendered with Western digits (spec 006 FR-033/FR-034).

## Playwright selectors

Each interactive element carries a stable `data-test` attribute: `sign-in-email`, `sign-in-password`, `sign-in-submit`, `code-input`, `code-submit`, `use-recovery`, `recovery-input`, `lost-device`, `not-you`, `new-password`, `new-password-confirm`, `enrol-key`, `enrol-link`, `enrol-code`, `recovery-code-list`, `recovery-saved`, `recovery-continue`, `sign-out`, `security-change-password`, `security-regenerate`, `security-replace`, `factor-code`, `factor-confirm`, `request-row`, `request-approve`, `request-decline`, `auth-error`. The quickstart computes codes from the setup key with a short Node helper (HMAC-SHA1, Node's `crypto`, no package).
