# Data Model: Operator Sign-in

All tables are owned by **Admin** (migrations in `app/Modules/Admin/Infrastructure/Database/Migrations`), except the one constraint change on Identity's `audit_entries`. Identifiers are UUIDv7 (ADR-0002); instants are `timestamptz(6)` in UTC (ADR-0011). No column is float. None of these entities syncs or is exposed through `/api/v1`.

## Operator Account — `operator_accounts`

| Column | Type | Rule |
|---|---|---|
| `id` | uuid PK | UUIDv7; the `actor_id` / `subject_id` in audit entries |
| `email_lookup` | char(64), unique | HMAC-SHA256(normalise(email), `ADMIN_LOOKUP_KEY`), hex; every lookup by email goes through it |
| `email_sealed` | text | sealed (R-10), associated data `operator:{id}:email` |
| `display_name_sealed` | text | sealed, `operator:{id}:display_name`; 1–80 characters before sealing |
| `password_hash` | text | argon2id; never returned anywhere |
| `state` | varchar(30) | CHECK in (`awaiting_password_change`, `awaiting_enrolment`, `active`, `deactivated`) |
| `secret_sealed` | text, nullable | the enrolled second-factor secret (20 bytes), sealed `operator:{id}:secret`; null until enrolled and after an approved re-enrolment |
| `pending_secret_sealed` | text, nullable | a secret shown on the setup page, not yet confirmed; sealed `operator:{id}:pending_secret` |
| `pending_secret_created_at` | timestamptz, nullable | a pending secret older than 15 minutes is discarded and a new one generated |
| `last_code_step` | bigint, nullable | highest accepted TOTP step (R-3) |
| `enrolled_at` | timestamptz, nullable | when the current secret was confirmed |
| `credential_epoch` | integer, default 0 | incremented on password change, deactivation, temporary-password reissue, approved re-enrolment (R-7) |
| `password_changed_at` | timestamptz, nullable | |
| `temporary_password_expires_at` | timestamptz, nullable | set with a temporary password (issue + 72 h), cleared on the operator's own password change; an expired temporary password fails like a wrong one (FR-004a) |
| `last_signed_in_at` | timestamptz, nullable | shown on the security page |
| `created_at`, `updated_at` | timestamptz | |

Constraints: `CHECK (state <> 'active' OR (secret_sealed IS NOT NULL AND enrolled_at IS NOT NULL))` — an active account always has a factor, nothing is active without one (FR-020, FR-027); `CHECK (state <> 'awaiting_enrolment' OR secret_sealed IS NULL)`; `CHECK ((secret_sealed IS NULL) = (enrolled_at IS NULL))`.

**States**

```text
                 create (command)
                       │
                       ▼
        ┌── awaiting_password_change ──(new password)──▶ awaiting_enrolment ──(code confirmed)──▶ active
        │                 ▲                                      ▲                                   │
        │                 └──── reset-password (command, from active) ◀───────────────────────────────┤
        │                                                        └──── re-enrolment completed ◀──────┤
        └───────────────── deactivate (command, any state) ──▶ deactivated (terminal) ◀──────────────┘
```

- `reset-password` always sets `awaiting_password_change` and issues a new temporary password; it **keeps the secret** of an enrolled account. Sign-in stages then follow from the row: password → (if a secret exists) second factor → (if `awaiting_password_change`) new password → (if no secret) enrolment → full sign-in. So a reset never bypasses the second factor of an enrolled operator, and the transition after a password change is `secret_sealed IS NULL ? awaiting_enrolment : active`.
- Replacing an authenticator from the security page keeps the state `active` and swaps `secret_sealed` on confirmation.

## Recovery Code — `operator_recovery_codes`

| Column | Type | Rule |
|---|---|---|
| `id` | uuid PK | |
| `operator_id` | uuid FK → operator_accounts, cascade | |
| `set_id` | uuid | all ten codes of one issue share it |
| `code_hash` | char(64) | HMAC-SHA256(normalised code, `ADMIN_CODE_KEY`), hex |
| `used_at` | timestamptz, nullable | set once; never cleared |
| `created_at` | timestamptz | |

Unique `(operator_id, code_hash)`. Issuing a set deletes every row of the operator's earlier sets in the same transaction, so at most ten rows exist per operator. "Codes left" = rows with `used_at IS NULL`.

## Re-enrolment Request — `operator_reenrolment_requests`

| Column | Type | Rule |
|---|---|---|
| `id` | uuid PK | |
| `operator_id` | uuid FK | the requester |
| `state` | varchar(12) | CHECK in (`open`, `completed`, `declined`, `expired`, `cancelled`) |
| `opened_at` | timestamptz | |
| `expires_at` | timestamptz | `opened_at + 72 h` |
| `closed_at` | timestamptz, nullable | CHECK `(state = 'open') = (closed_at IS NULL)` |
| `closed_by` | uuid, nullable | the decliner, or the second approver; null for expiry and cancellation |

Partial unique index `(operator_id) WHERE state = 'open'`: one open request per operator (FR-030).

## Re-enrolment Approval — `operator_reenrolment_approvals`

| Column | Type | Rule |
|---|---|---|
| `id` | uuid PK | |
| `request_id` | uuid FK → requests, cascade | |
| `approver_id` | uuid FK → operator_accounts | CHECK enforced in the service: ≠ requester |
| `approved_at` | timestamptz | |

Unique `(request_id, approver_id)`. The reason lives in the `admin_action` audit entry, not here (one copy). The approve service locks the request row (`FOR UPDATE`), re-checks `state = open`, `expires_at > now`, approver ≠ requester, and no prior approval by this approver; when the count reaches 2 it completes the request in the same transaction (FR-033): requester `secret_sealed = null`, `enrolled_at = null`, `last_code_step = null`, recovery codes deleted, `state = awaiting_enrolment`, `credential_epoch + 1`.

## Sign-in failure counter — `operator_sign_in_failures`

| Column | Type | Rule |
|---|---|---|
| `lookup` | char(64) PK | email lookup value, registered or not |
| `consecutive_failures` | integer | |
| `locked_until` | timestamptz, nullable | |
| `last_failed_at` | timestamptz | |

Same shape as Identity's `sign_in_failures`, separate table (FR-052).

## Session state (database session driver, `sessions` table)

| Key | Meaning |
|---|---|
| `admin.pending` | `{operator_id, stage: second_factor \| password_change \| enrolment \| codes_shown, started_at, code_failures, epoch}` while half signed in (R-6) |
| `login_operator_*` | Laravel's guard key: present only after a full sign-in |
| `admin.signed_in_at` | absolute-limit start |
| `admin.last_seen_at` | idle-limit start |
| `admin.epoch` | the operator's `credential_epoch` at sign-in (or after own password change) |
| `admin.factor_confirmed_at` | last second-factor proof, for re-confirmation (R-12) |
| `admin.intended` | the back-office URL first asked for (FR-015) |
| `admin.locale` | unchanged from spec 006 |

No secret, code or recovery code is ever written to the session.

## Identity change — `audit_entries`

Identity migration `…_allow_operator_audit_subject.php` drops and re-adds `audit_entries_subject_type_check` with `operator` added; `SubjectType::Operator = 'operator'` added to the enum. Append-only trigger untouched.

## In-memory types (Admin Domain / Application)

| Type | Layer | Purpose |
|---|---|---|
| `Base32` | Domain | RFC 4648 encode/decode (R-2) |
| `Totp` | Domain | code for a step, `verify(secret, code, now, lastStep): ?int` returning the matched step (R-1, R-3) |
| `OperatorState` enum | Domain | the four states |
| `OperatorPasswordPolicy` | Domain | 12–128, not the email, not current/temporary (R-15) |
| `RecoveryCode` | Domain | generation alphabet, display format, input normalisation (R-11) |
| `ReenrolmentState` enum | Domain | five states |
| `AdminAction` enum | Domain | the closed `admin_action` action list (R-13) |
| `SignInStage` enum | Domain | `second_factor`, `password_change`, `enrolment`, `codes_shown` (recovery codes delivered, awaiting the saved-confirmation; 15 minutes) |
| `OperatorView` | Application DTO | id, display name, state, enrolled_at, codes left, last sign-in (scalars only; P-18 r5) |
| `ReenrolmentRequestView` | Application DTO | id, requester display name, opened/expires instants, approval count, whether the viewer may act |

## Configuration — `config/admin.php` additions

| Key | Default | Purpose |
|---|---|---|
| `auth.keys` / `auth.active_key` | env `ADMIN_KEYS`, `ADMIN_ACTIVE_KEY` | sealing keyring (R-10) |
| `auth.lookup_key` | env `ADMIN_LOOKUP_KEY` | email lookup |
| `auth.code_key` | env `ADMIN_CODE_KEY` | recovery-code hashing |
| `auth.issuer` | `Save Me Back office` | authenticator label |
| `auth.idle_minutes` / `auth.absolute_hours` | 30 / 12 | R-7 |
| `auth.pending_minutes` | `{second_factor: 5, password_change: 15, enrolment: 15, codes_shown: 15}` | R-6 |
| `auth.reconfirm_minutes` | 10 | R-12 |
| `auth.reenrolment_hours` | 72 | FR-032 |
| `auth.lockout` | baseline numbers | R-9 |
| `auth.drift_steps` | 1 | baseline: one step of drift |

`.env.example` gains the four key variables (empty), and `phpunit.xml` gets fixed test keys.
