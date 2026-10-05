# Data Model: Export & Data Deletion (spec 022)

All new tables are Identity's (`app/Modules/Identity/Infrastructure/Database/Migrations`), except one Billing column. UUIDv7 keys (P-06), UTC `timestamptz` (P-12), no float/decimal (P-03). None is syncable.

## `data_exports` (new)

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | UUIDv7, server-generated |
| `user_id` | uuid, FK → users ON DELETE CASCADE | the requester |
| `scope` | varchar(10) + CHECK | `account` \| `wallet` |
| `wallet_id` | uuid null | no FK (Wallets owns wallets, P-02). CHECK `(scope = 'wallet') = (wallet_id IS NOT NULL)` |
| `status` | varchar(10) + CHECK | `queued` \| `running` \| `ready` \| `failed` \| `expired` |
| `failure_code` | varchar(30) null + CHECK | `generation_failed` \| `wallet_unavailable` \| `timed_out`; non-null iff `status = failed` |
| `file_path` | varchar(255) null | `exports/<user_id>/<id>.zip`, set from `ready`, null once `expired` |
| `size_bytes` | bigint null | set at `ready` |
| `requested_at` | timestamptz | |
| `started_at` | timestamptz null | |
| `completed_at` | timestamptz null | `ready` or `failed` |
| `expires_at` | timestamptz null | `completed_at + 24h` when `ready` |
| `delivered_at` | timestamptz null | first successful download |
| `created_at`, `updated_at` | timestamptz | |

Indexes: `data_exports_one_active` UNIQUE (`user_id`) WHERE `status IN ('queued','running')`; (`user_id`, `requested_at` DESC, `id` DESC) for the list; (`status`, `expires_at`) for the expiry run.

State machine:

```text
queued ──job starts──▶ running ──archive written──▶ ready ──24h──▶ expired
   │                      │
   └──────────────────────┴──3 failed attempts, wallet unavailable, or 60 min stale──▶ failed
```

Rows are deleted 30 days after `requested_at` (with any file), and all of a person's rows and files at the purge.

## `deletion_successor_choices` (new)

| Column | Type | Notes |
|---|---|---|
| `user_id` | uuid, FK → users ON DELETE CASCADE | the departing person |
| `wallet_id` | uuid | no FK (P-02) |
| `member_user_id` | uuid | no FK |
| `chosen_at` | timestamptz | |

PK (`user_id`, `wallet_id`). Written only by `RequestDeletion` while the account is `pending_deletion`; deleted by `CancelDeletion` and by the purge.

## `account_purges` (new, operational)

| Column | Type | Notes |
|---|---|---|
| `user_id` | uuid PK, FK → users ON DELETE CASCADE | one per account being purged |
| `started_at` | timestamptz | |
| `last_step` | varchar(30) + CHECK | a `PurgeStep` value: `begin`, `erase_name`, `revoke_invitations`, `resolve_owned_wallets`, `leave_wallets`, `budgets`, `categories`, `subscriptions`, `exports`, `completion_mail`, `erase_identity` |
| `attempts` | integer | incremented per run |
| `counts` | jsonb | `{wallets_transferred, wallets_deleted, memberships_ended, invitations_revoked, budgets_deleted, categories_deleted, categories_kept, subscriptions_ended}` — integers only |
| `mail_sent_at` | timestamptz null | completion mail sent |
| `stall_notified_at` | timestamptz null | `AccountPurgeStalled` published |
| `updated_at` | timestamptz | |

Deleted by the `erase_identity` step.

## `users` (changed)

- `state` CHECK: `active` | `pending_deletion` | `purging` | `purged`.
- Pending CHECK becomes: `(state IN ('pending_deletion','purging')) = (deletion_requested_at IS NOT NULL AND deletion_scheduled_for IS NOT NULL)`.
- New `deletion_reminded_at` timestamptz null (reset to null on cancel).
- New `purged_at` timestamptz null; CHECK `(state = 'purged') = (purged_at IS NOT NULL)`.
- In `purged`: `email_ciphertext`, `email_lookup`, `password_hash` are null (columns made nullable with CHECK `state = 'purged' OR (email_ciphertext IS NOT NULL AND email_lookup IS NOT NULL)`; the unique index on `email_lookup` ignores nulls). Counters (`change_seq`, `purged_change_seq`) stay.

Lifecycle:

```text
active ──request (password)──▶ pending_deletion ──cancel | new sign-in──▶ active
pending_deletion ──scheduled instant passed, purge begins──▶ purging ──erase_identity──▶ purged (husk)
purged ──400 days──▶ row deleted
```

## `device_sessions` (changed)

`end_reason` CHECK gains `account_deleted` (`EndReason::AccountDeleted`).

## `subscriptions` (Billing, changed)

New `ended_by_account_purge_at` timestamptz null. Billing's retention sweep (`billing:purge-claims`' sibling, added to the same daily command) deletes rows where `ended_by_account_purge_at < now - 400 days`.

## Removed or kept at the purge (by table)

| Table | Module | At the purge |
|---|---|---|
| `users` | Identity | husk (`purged`), deleted at +400 days |
| `user_keys` | Identity | **deleted** (key destruction) |
| `profiles` | Identity | name erased (event), then tombstoned and removed (ADR-0024: purged user scope) |
| `budget_start_days`, `identity_links`, `device_sessions`, `data_exports`, `deletion_successor_choices`, `account_purges` | Identity | deleted (sessions ended first) |
| `sessions` (framework) | — | rows of the user deleted |
| `sign_in_failures` | Identity | row for the account's lookup value deleted before the value is cleared |
| `audit_entries` | Identity | **untouched** (append-only, 400 days) |
| `wallets` owned, no other member | Wallets | tombstoned with contents (existing delete); purged at 90 days |
| `wallets` owned, shared | Wallets | ownership transferred (existing transfer) |
| `wallet_members` of the person | Wallets | tombstoned (leave / transfer then leave / wallet delete); purged at 90 days |
| `transactions`, `postings`, `recurring_rules`, `occurrences` in surviving wallets | Wallets | **untouched** |
| `categories` of the person | Wallets | unreferenced → tombstoned then removed (ADR-0024); referenced → archived, deleted by the daily sweep when unreferenced |
| `category_references` | Wallets | untouched (live while the category is referenced) |
| `wallet_activity` | Wallets | untouched (90-day prune) |
| `budgets` | Budgeting | tombstoned, then removed in the same purge (ADR-0024) |
| `invitations` sent, pending | Sharing | revoked (address erased) |
| `subscriptions` | Billing | granting `manual`/`instapay` ended; all stamped; deleted at +400 days |
| `redeem_codes`, `payment_claims` | Billing | untouched (own retention) |
| `operator_notifications` | Admin | untouched (identifiers only) |

## Domain values (no table)

- `ExportScope` (`account` | `wallet` + wallet id), `ExportStatus`, `ExportFailure`.
- `Successor {user_id, reason}`, `SuccessorReason` (`only_member` | `chosen` | `oldest_editor` | `oldest_viewer`), `SuccessorCandidate {user_id, role, joined_at, account_pending}`.
- `PurgeStep` (ordered enum above), `PurgeCounts`.
