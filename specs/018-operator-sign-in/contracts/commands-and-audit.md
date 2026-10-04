# Contract: Artisan commands and audit entries

## Commands (`app/Modules/Admin/Http/Console`)

| Signature | Effect | Output | Exit |
|---|---|---|---|
| `admin:operator:create {email} {--name=}` | Creates an operator in `awaiting_password_change` with a generated 20-character temporary password. `--name` required (1–80 chars). | `Operator created: {email}` and `Temporary password (shown once): {password}` | 0; 1 with "An operator with this email already exists." / "The email is not valid." / "A display name is required." |
| `admin:operator:reset-password {email}` | New temporary password; state `awaiting_password_change`; epoch + 1 (all sessions end); secret and recovery codes kept. | `Temporary password (shown once): {password}` | 0; 1 "No operator with this email." / "This operator is deactivated." |
| `admin:operator:deactivate {email}` | State `deactivated`; epoch + 1; open re-enrolment request → `cancelled`. | `Operator deactivated: {email}` | 0; 1 "No operator with this email." / "This is the last active operator; create another first." / "Already deactivated." |
| `admin:expire-reenrolment-requests` | Every `open` request with `expires_at ≤ now` → `expired`. Scheduled every five minutes in `routes/console.php`. | count | 0 |

No command (and no route) clears a second factor without the two-approval request, or turns the requirement off (FR-027). `tests/Arch/AdminBackOfficeTest.php` asserts the set of `admin:*` command names equals this table.

## Audit entries (through `AdminAuditService` → Identity `AuditLog`)

| Act | `event` | `actor_type` / `actor_id` | `subject_type` / `subject_id` | `detail` |
|---|---|---|---|---|
| Full sign-in | `sign_in_succeeded` | operator / operator id | operator / operator id | `{factor: "code" \| "recovery_code", recovery_codes_left: int}` |
| Failure at password, code, recovery code, enrolment code, re-confirmation | `sign_in_failed` | system / null | operator / id, or null for an unknown email | `{stage: "password" \| "second_factor" \| "enrolment" \| "reconfirm", lookup_prefix?}` |
| A lock begins | `lockout_applied` | system / null | operator / id or null | `{minutes, lookup_prefix?}` |
| Sign-out | `session_revoked` | operator / id | operator / id | `{reason: "signed_out"}` |
| Password change (first, or from Security) | `password_changed` | operator / id | operator / id | `{}` |
| Operator created | `admin_action` | system / null | operator / new id | `{action: "operator_created"}` |
| Temporary password issued | `admin_action` | system / null | operator / id | `{action: "temporary_password_issued"}` |
| Operator deactivated | `admin_action` | system / null | operator / id | `{action: "operator_deactivated"}` |
| Enrolment completed (first or after re-enrolment) | `admin_action` | operator / id | operator / id | `{action: "enrolment_completed"}` |
| Recovery codes regenerated | `admin_action` | operator / id | operator / id | `{action: "recovery_codes_regenerated"}` |
| Authenticator replaced | `admin_action` | operator / id | operator / id | `{action: "authenticator_replaced"}` |
| Re-enrolment requested | `admin_action` | operator / requester id | operator / requester id | `{action: "reenrolment_requested", reenrolment_request_id}` |
| Approved | `admin_action` | operator / approver id | operator / requester id | `{action: "reenrolment_approved", reenrolment_request_id, reason}` |
| Declined | `admin_action` | operator / decliner id | operator / requester id | `{action: "reenrolment_declined", reenrolment_request_id, reason}` |
| Completed (second approval) | `admin_action` | system / null | operator / requester id | `{action: "reenrolment_completed", reenrolment_request_id}` |
| Expired | `admin_action` | system / null | operator / requester id | `{action: "reenrolment_expired", reenrolment_request_id}` |
| Cancelled (deactivation) | `admin_action` | system / null | operator / requester id | `{action: "reenrolment_cancelled", reenrolment_request_id}` |

Detail classes are closed (`AuditDetail` implementations): `OperatorSignInDetail`, `SignInFailedDetail` (Identity's, extended with an optional `stage`), `LockoutDetail` (existing), `SessionRevokedDetail` (existing shape), `AdminActionDetail {AdminAction action, ?string reenrolmentRequestId, ?AuditReason reason}`. None accepts a password, secret, link, code, recovery code or readable email (FR-082); `OperatorAuditTest` scans every entry's JSON for the test operator's email, password, setup key and codes.

Every entry is written inside the transaction of the act it records (baseline: one act, one entry). The detail key `request_id` is avoided because `audit_entries.request_id` is the HTTP correlation id.
