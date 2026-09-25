# Phase 1 Data Model: Security & Privacy Baseline

**Feature**: `004-security-privacy-baseline` | **Date**: 2026-09-22 | **Spec**: [spec.md](./spec.md) | **Research**: [research.md](./research.md)

Nothing here is built by this feature. These are the shapes the baseline **requires of the features that will build them** — Identity, Billing, Sync and Admin — written out so two features cannot arrive at two different answers. Each becomes a section of `docs/security-baseline.md`.

Field conventions follow [the glossary](../../docs/glossary.md): identifiers are UUIDv7, instants are `<name>_at` in UTC, calendar dates are `YYYY-MM-DD`. None of these entities is syncable; none appears in a change feed.

---

## 1. Device Session

One signed-in device. Owned by Identity.

| Field | Type | Notes |
|---|---|---|
| `id` | uuid | UUIDv7 |
| `user_id` | uuid | owner |
| `credential_hash` | string | hash of the issued credential; the readable value exists only in the response that created it |
| `label` | string | something the owner recognises: device model or a name they set |
| `created_at` | instant | |
| `last_used_at` | instant | drives the sliding expiry |
| `expires_at` | instant | `last_used_at` + 90 days, recomputed on use |
| `revoked_at` | instant, nullable | set on revocation; the row survives for the audit trail |

**Rules**

- A credential carries at least 256 bits of entropy and is never stored readable.
- Expiry is 90 days of inactivity and extends on each successful use. There is no absolute cap in MVP.
- Revocation takes effect on the next request. No positive cache of validity exists.
- Revoking one session leaves every other session working.
- A password change revokes every session except the one that made the change.
- The list shown to an owner names the device and the times. It never exposes the credential, its hash, or anything else useful to an attacker who can already read the screen.

---

## 2. Credential

The stored form of a password. Owned by Identity. One per user.

| Field | Type | Notes |
|---|---|---|
| `user_id` | uuid | |
| `password_hash` | string | argon2id, `memory = 65536 KiB`, `iterations = 4`, `parallelism = 1` |
| `password_changed_at` | instant | |

**Rules**

- Parameters are re-measured on the target hardware and raised until one hash costs 250–500 ms.
- A password is rehashed on the next successful sign-in whenever its stored parameters are below the current setting.
- The hash is never returned by any endpoint, at any level of privilege.

---

## 3. Verification Token and Reset Token

Single-use proofs. Owned by Identity.

| Field | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `user_id` | uuid | |
| `kind` | enum | `address_verification` \| `password_reset` |
| `token_hash` | string | the readable value is sent once, by mail, and never stored |
| `expires_at` | instant | verification 24 hours; reset 60 minutes |
| `used_at` | instant, nullable | set on first use; a used token is dead |

**Rules**

- Requesting a new reset token invalidates every earlier unused one for that user.
- A password change invalidates every outstanding reset token.
- Requesting a new verification token invalidates earlier ones for the same address.
- Resends are limited to three per hour per address.

---

## 4. Lockout Record

What makes repeated failure stop being useful. Owned by Identity.

| Field | Type | Notes |
|---|---|---|
| `subject` | string | the lookup value of an address, or an origin address |
| `scope` | enum | `account` \| `origin` |
| `consecutive_failures` | integer | reset by any success |
| `locked_until` | instant, nullable | |

**The rule**

| Scope | Trigger | Effect |
|---|---|---|
| account | 5 consecutive failures | refuse 15 minutes |
| account | 10 consecutive failures | refuse 60 minutes |
| origin | 20 failures across any accounts within 15 minutes | refuse that origin 15 minutes |

**Rules**

- An account lock caps at 60 minutes and is never permanent.
- A successful password reset, which requires the mailbox, clears the lock immediately.
- A successful sign-in resets the counter.
- The refusal is `423 ACCOUNT_LOCKED` with `Retry-After`, distinguishable by the app from a wrong password so the person is told to wait rather than told they typed it wrong.

---

## 5. Protected attributes

Not an entity — the table FR-015 requires, stating the answer per attribute so that no unqualified claim is made anywhere.

| Attribute | Protection at rest | Searchable |
|---|---|---|
| Address | encrypted, with a keyed lookup value beside it | by lookup value only |
| Display name | encrypted | no |
| Password | argon2id hash | no |
| Session credential | hash | by hash |
| Reset and verification tokens | hash | by hash |
| Amount, balance, note | none at rest | yes |
| Wallet and category names | none at rest | yes |
| Audit entries | none at rest, and they carry nothing protected | yes |

**Rules**

- Encryption is authenticated, with a per-record nonce and the key identifier stored beside the value.
- Amounts are deliberately not encrypted: arithmetic, aggregation and indexing all depend on them, and an attacker who reaches the database also reaches the application that would decrypt them. They are protected by scope, transport and access control instead.
- The baseline states this table. It never says "everything is encrypted".

### 5.1 The keyed lookup value

```text
lookup = HMAC-SHA256(normalise(address), lookup_key)
```

Normalisation, applied identically everywhere it is produced:

1. trim surrounding whitespace
2. Unicode NFKC
3. lowercase the entire address

No provider-specific munging. Dots and plus-tags are **not** stripped, because two addresses that differ by them are different addresses to anyone who is not that provider, and merging them silently merges two people.

**Rules**

- Unique-indexed. It is how sign-in, "is this address registered" and invitation matching find a person.
- The lookup key is separate from the encryption key and lives outside the database.
- Stated limit: this defeats an attacker holding the stored data. It does not defeat one holding the data and the lookup key, who can confirm an address they already guessed — though not enumerate the addresses that exist.

---

## 6. Audit Entry

Owned by Identity, written by every module through its application service. Admin's `AdminAuditService` is a façade over the same store, not a second one.

| Field | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `occurred_at` | instant | |
| `event` | enum | from the fixed vocabulary below |
| `actor_type` | enum | `user` \| `operator` \| `system` |
| `actor_id` | uuid, nullable | null for `system` |
| `subject_type` | enum | `user` \| `wallet` \| `session` \| `subscription` |
| `subject_id` | uuid | |
| `request_id` | uuid | correlates with the server's logs |
| `origin` | string, nullable | coarse origin of the request |
| `detail` | object | structured, and constrained by the exclusion list below |

**The event vocabulary**, fixed so that "log security events" becomes checkable:

`sign_in_succeeded` · `sign_in_failed` · `lockout_applied` · `session_issued` · `session_revoked` · `password_changed` · `address_change_requested` · `address_verified` · `membership_granted` · `membership_revoked` · `role_changed` · `entitlement_changed` · `export_requested` · `export_delivered` · `deletion_requested` · `deletion_cancelled` · `deletion_completed` · `admin_action` · `audit_read`

**Rules**

- An entry names the actor, the subject, the act and the time. It never names a value.
- Never inside an entry: passwords, tokens, one-time codes, amounts, notes, receipt contents, or a readable address. The subject is referenced by identifier.
- `actor_type` distinguishes a user acting for themselves from an operator acting on their behalf. FR-022 is exactly this field.
- Append-only. An entry is never edited or deleted before its retention expires.
- Readable by an operator only, and **reading is itself an event** (`audit_read`), so the richest target in the system cannot be browsed unobserved.
- Retention: 400 days.

---

## 7. Deletion Request

Owned by Identity.

| Field | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `user_id` | uuid | |
| `requested_at` | instant | |
| `purge_after` | instant | `requested_at` + 30 days |
| `cancelled_at` | instant, nullable | |
| `completed_at` | instant, nullable | |

**States**

```text
requested ──cancel (before purge_after)──▶ cancelled
    │
    └──purge runs (at or after purge_after)──▶ completed
```

**Rules**

- Cancelling inside the grace period loses nothing. The account and every record return untouched.
- A person may keep using the account during the grace period, including writes. Their use does not extend or reset the clock.
- An export requested during the grace period is served and does **not** reset the clock.
- On completion: the rows the person alone owns are deleted, and the per-account key material is destroyed, so a backup restored later yields nothing readable for them.

---

## 8. Departure from a shared wallet

Not an entity — the rule that makes purging survivable for everyone else.

| What the departing person owned | What happens |
|---|---|
| Wallets they own | removed with them |
| Records only they can see | removed |
| Transactions they authored in someone else's wallet | **kept** |
| Their name on those transactions | replaced by a stable anonymous marker that cannot be resolved back to a person |
| Their membership row | removed |

**Rationale to carry into the document**: deleting their transactions would change other members' balances without those members knowing. Correctness wins for the money; privacy wins for the identity.

**Stated limit**: a revoked or departed member's device still holds whatever it last synced. The baseline says so plainly rather than implying a remote wipe that does not exist.

---

## 9. Export

Owned by Identity.

| Field | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `user_id` | uuid | |
| `requested_at` | instant | |
| `delivered_at` | instant, nullable | |
| `expires_at` | instant | the download link's own lifetime |

**Rules**

- Produced asynchronously. A second request while one is in progress is `409 EXPORT_IN_PROGRESS`.
- Contents: complete structured data, plus a spreadsheet-openable file of transactions, so "a form they can open without this app" is literally true.
- Contains what the person entered and what they can already see. Not other members' records beyond that.
- Both `export_requested` and `export_delivered` are recorded.

---

## 10. Operator Account

Owned by Admin. Entirely separate from application users, on its own guard, per ADR-0006 rules 7 and 8.

| Field | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `second_factor_enrolled_at` | instant, nullable | null means the account can do nothing |
| `recovery_codes_remaining` | integer | ten issued at enrolment, single-use, shown once |

**Rules**

- Second factor: time-based codes, six digits, 30-second step, one step of drift tolerated.
- Enrolment is mandatory before the account can act.
- A correct password without a valid second factor is refused: `401 TWO_FACTOR_REQUIRED` when absent, `401 TWO_FACTOR_INVALID` when wrong.
- Recovery: a single-use recovery code. When those are exhausted, **two other operators** must approve a re-enrolment, and both approvals are recorded. Recovery never reduces to asking someone to switch the requirement off.
- An operator acts through other modules' application services, so the same invariants apply as for a user action (ADR-0006 rule 9).

---

## 11. Provider Message

Owned by Billing. An inbound claim from the payment provider.

| Field | Type | Notes |
|---|---|---|
| `message_id` | string | the provider's identifier, used for replay detection |
| `received_at` | instant | |
| `expires_at` | instant | `received_at` + 24 hours, after which the identifier is forgotten |

**Verification — all five must hold**

1. the signature verifies against the provider's current public keys;
2. the issuer is the expected one;
3. the audience names **this** service, not another;
4. the issued-at time is within five minutes of now;
5. the message identifier has not been seen in the last 24 hours.

**Outcomes**

| Case | Response |
|---|---|
| All five hold | processed |
| Already seen | **acknowledged with no effect** — a refusal would make a well-behaved provider retry forever |
| Signature, issuer or audience wrong | `401 PROVIDER_SIGNATURE_INVALID`, nothing granted |
| Outside the time window | `401 PROVIDER_SIGNATURE_INVALID` |
| Key material unreachable | rejected, left for the provider to retry. Never accepted unverified, never queued for later trust |

The audience check is the one most often skipped, and it is what stops a validly signed message meant for a different service from granting a subscription here.

---

## 12. Retention, every period as a number

| What | Kept | Fixed by |
|---|---|---|
| Deleted syncable record, content and marker | 90 days | ADR-0008 |
| Other soft-deleted records | 30 days | this feature |
| Account after a deletion request | 30 days | this feature |
| Audit entries | 400 days | this feature |
| Application logs | 30 days | ADR-0010 |
| Idempotency records | 24 hours | spec 003 |
| Reset token | 60 minutes | this feature |
| Verification token | 24 hours | this feature |
| Provider message identifiers | 24 hours | this feature |

The published commitments state **90 days** for anything that syncs. That is the longer number and the one the rules can actually keep.

---

## 13. Reserved error codes

Ten additions to [`docs/openapi/error-catalog.yaml`](../../docs/openapi/error-catalog.yaml), taking it from 38 codes to 48.

| Code | Status | Level | Retryable |
|---|---|---|---|
| `INVALID_CREDENTIALS` | 401 | response | never |
| `ACCOUNT_LOCKED` | 423 | response | automatic |
| `EMAIL_NOT_VERIFIED` | 403 | response | never |
| `VERIFICATION_TOKEN_INVALID` | 400 | response | after-change |
| `RESET_TOKEN_INVALID` | 400 | response | after-change |
| `TWO_FACTOR_REQUIRED` | 401 | response | after-change |
| `TWO_FACTOR_INVALID` | 401 | response | after-change |
| `INSECURE_TRANSPORT` | 400 | response | never |
| `PROVIDER_SIGNATURE_INVALID` | 401 | response | never |
| `EXPORT_IN_PROGRESS` | 409 | response | automatic |

`UNAUTHENTICATED` already covers an expired or revoked session, so no code is added for that. `423` rather than `429` for a lockout because the catalogue allows one status per code and a client must show different words for "you are throttled" and "this account is locked".

---

## 14. What this model deliberately does not contain

- **Any endpoint.** Identity, Billing and Admin define their own; this fixes what those endpoints must be true of.
- **The wallet role by action matrix.** P-08 rule 5 keeps it in the Wallets spec alone.
- **The authentication library.** Identity's decision and Identity's dependency approval.
- **The purge job's schedule.** Sync builds it; this fixes the horizons it enforces.
- **Second factors for application users.** Out of scope for MVP.
- **Breach notification and penetration-testing cadence.** Operational commitments, excluded by the spec.
