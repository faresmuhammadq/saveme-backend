# Contract: Audit entries per act (spec 021)

Exactly one entry per act (security baseline). "Writer" is the module whose code calls `AuditLog::record`; Admin always writes through `AdminAuditService`.

| Act (screen) | Event | Action | Writer | Actor | Subject | Detail | When |
|---|---|---|---|---|---|---|---|
| Open a claim review | `admin_action` | `claim_address_read` | Admin (`ClaimReading`) | operator | `payment_claim` | `{action}` | `mount()`, before the address is returned |
| Follow a receipt link | `admin_action` | `claim_receipt_viewed` | Admin (existing route) | operator | `payment_claim` | `{action}` | route hit |
| Approve / reject / revoke code | `claim_reviewed` | — | Billing (`ClaimService`) | operator | `payment_claim` | `{outcome, reason?}` | inside the command |
| Find an account, hit | — | — | — | — | — | — | nothing (the page open records) |
| Find an account, miss | `admin_action` | `account_lookup_missed` | Admin (`AccountLookup`) | operator | `operator` (self) | `{action, by}` | after the lookup |
| Open an account page | `admin_action` | `account_viewed` | Admin (`AccountLookup`) | operator | `user` | `{action}` | `mount()` |
| Grant / extend / revoke access | `entitlement_changed` | `grant`/`extend`/`revoke` | Billing (`OperatorSubscriptions`) | operator | `subscription` | spec 009 shape (plan id, days, reason) | inside the command |
| Save a plan | `admin_action` | `plan_updated` | Admin (`PlanChanges`) | operator | `plan` | `{action, fields, reason}` | same transaction as the update |
| Retire a plan | `admin_action` | `plan_retired` | Admin (`PlanChanges`) | operator | `plan` | `{action, reason}` | same transaction |
| Audit log first load / apply / older / newer | `audit_read` | — | Admin (`AuditReader`) | operator | `operator` (self) | `{page, from, to, …filters}` | same transaction as the query |
| Expand an audit row, re-render any screen | — | — | — | — | — | — | nothing |
| Claim queue list / search, plan catalogue, overview | — | — | — | — | — | — | nothing (no protected data) |
| Mark notification(s) read | — | — | — | — | — | — | nothing (FR-053) |
| Refused or cancelled action, factor prompt cancelled, lookup budget spent | — | — | — | — | — | — | nothing |

**Never inside any of these entries**: an address, display name, amount or price, capability value, review note, receipt content, redeem code, one-time code. `reason` (free text written by the operator for grants and plan changes) is allowed, as spec 009 and spec 018 already store it; the screens' hint says "Don't include personal details in the reason."
