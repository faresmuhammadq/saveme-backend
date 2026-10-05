# Data Model: Back-office Operations (spec 021)

One new table, one constraint change, two indexes. Everything else is read through existing tables by their owning modules.

## New: Notification Read Mark (Admin)

Table `operator_notification_reads` — migration `app/Modules/Admin/Infrastructure/Database/Migrations/2026_10_10_100001_create_operator_notification_reads_table.php`.

| Column | Type | Notes |
|---|---|---|
| `operator_id` | uuid, not null | FK → `operator_accounts.id`, `ON DELETE CASCADE` |
| `notification_id` | uuid, not null | FK → `operator_notifications.id`, `ON DELETE CASCADE` |
| `read_at` | timestamptz(6), not null | UTC |

- Primary key (`operator_id`, `notification_id`). Writes are `INSERT … ON CONFLICT DO NOTHING` (idempotent; the first `read_at` wins).
- Not syncable; never leaves the back office; carries identifiers only (security baseline row added).
- Unread for an operator = `operator_notifications` with `occurred_at ≥ now − admin.notifications.unread_days` and no row for (operator, notification).

## Changed: Audit Entry (Identity)

Migration `app/Modules/Identity/Infrastructure/Database/Migrations/2026_10_10_100000_allow_plan_audit_subject.php`:

- `audit_entries_subject_type_check` → `('user', 'wallet', 'session', 'subscription', 'operator', 'payment_claim', 'plan')`; `down()` restores the previous list.
- Event CHECK unchanged (`admin_action` and `audit_read` already allowed).
- New indexes: `audit_entries_occurred_at_id_index (occurred_at, id)` for unfiltered keyset pages; `audit_entries_actor_index (actor_id, occurred_at)` for the actor filter. The append-only trigger is untouched.
- `SubjectType` enum gains `Plan = 'plan'`.

### `detail` shapes written by this feature (all scalars, nothing protected)

| Event · action | Writer | Subject | Detail |
|---|---|---|---|
| `admin_action` · `account_viewed` | Admin | `user` / user id | `{action}` |
| `admin_action` · `account_lookup_missed` | Admin | `operator` / the operator's own id | `{action, by: "address"\|"id"}` |
| `admin_action` · `plan_updated` | Admin | `plan` / plan id | `{action, fields: "name.ar,prices.instapay,…" (sorted, comma-joined), reason}` |
| `admin_action` · `plan_retired` | Admin | `plan` / plan id | `{action, reason}` |
| `audit_read` | Admin | `operator` / the reading operator's id | `{page: "first"\|"older"\|"newer", from, to, event?, actor_type?, actor_id?, subject_type?, subject_id?}` (absent members omitted) |
| `admin_action` · `claim_address_read` | Admin | `payment_claim` | existing (spec 020) |
| `claim_reviewed` | Billing | `payment_claim` | existing (spec 020) |
| `entitlement_changed` · `grant`/`extend`/`revoke` | Billing | `subscription` | existing (spec 009), actor `operator` |

`AdminActionDetail` gains optional `fields` and `by` members (closed shape; `array_filter` drops nulls as today).

## Unchanged schema, new behaviour

### Plan (Billing) — `plans`

- Edited only through `PlanAdministration`: `name` (jsonb `{en, ar}`), `sort_order`, `features` (jsonb, every `Feature` key present), `prices` (jsonb list; the `instapay` entry replaced, added or removed; `google_play` entries carried over unchanged), `status` (`active` → `retired` only, non-default only).
- **Edit version** = `updated_at` (timestamptz(6)) rendered as an ISO-8601 string with microseconds; `UPDATE … WHERE id = :id AND updated_at = :version` sets a new `updated_at`; 0 rows → `PlanChangedElsewhere`.
- Never changed here: `id`, `code`, `period_type`, `period_days`, `is_default`.
- Validation (`Billing\Domain\Plans\PlanEdit`):

| Field | Rule |
|---|---|
| `name.en`, `name.ar` | trimmed, 1–40 characters (`mb_strlen`) |
| `sort_order` | integer 1–999 |
| `prices.instapay` | `null` (removed) or `{amount_minor: int 100–10,000,000, currency: Currency::isKnown}` |
| `features.<flag>` | boolean |
| `features.<quota>` | `null` (unlimited) or integer 1–1,000 |
| default plan | every capability except `sharing.enabled` and `sharing.max_members_per_wallet` must be `true` / `null` |
| change set | at least one field differs from the stored plan, else `NothingToSave` |

- **Seeder** (`BillingSeeder::run`): insert a definition whose `code` is absent; for an existing plan, add each `Feature` key missing from `features` with the definition's value; never overwrite anything stored.

### Subscription (Billing) — operator command rules (R-7)

| Command | Allowed when | Refusal |
|---|---|---|
| grant | plan active and non-default; account `active` | `PlanNotGrantable`, `AccountNotActive` |
| extend | provider `manual`, period end not null, status not `revoked`; account `active` | `SubscriptionNotExtendable`, `SubscriptionRevoked`, `AccountNotActive` |
| revoke | provider `manual` or `instapay` | `SubscriptionNotRevocable` (google_play); already revoked → no-op (spec 009) |

### Read models (DTOs, no table)

| DTO | Module | Fields |
|---|---|---|
| `AccountFacts` | Identity | `id, state, verified, createdAt, deletionScheduledFor, activeDevices` |
| `MemberCounts` | Wallets | `walletsOwned, walletsJoined, otherMembers` |
| `AccessView` | Billing | `governingSubscriptionId?, subscriptions: list<SubscriptionView>` |
| `SubscriptionView` | Billing | `id, planId, planName{en,ar}, provider, status, periodStart, periodEnd?, graceUntil?, createdAt, canExtend, canRevoke` |
| `PlanAdminView` | Billing | `id, name{en,ar}, periodType, periodDays?, isDefault, status, sortOrder, features, instapayPrice?, googlePlayPrices, version, governedPeople, submittedClaims` (no code) |
| `ClaimPage` | Billing | `items: list<ClaimSummary> (+ planName), nextCursor?` |
| `ClaimReviewState` | Billing | `status, reviewedBy?, reviewedAt?, rejectReason?, reviewNote?, codeStatus?, codeExpiresAt?, closedAt?, erasedAt?` |
| `AuditPage` / `AuditRow` | Identity | rows `{id, occurredAt, event, actorType, actorId?, subjectType, subjectId?, requestId?, origin?, detail}`, `olderCursor?, newerCursor?` |
| `AccountFigureSet`, `BillingFigureSet` | Identity, Billing | integer counts (floor applied in Admin) |

None of these has a field able to hold a display name, wallet or category name, amount from the ledger, balance, note or device name (R-10).
