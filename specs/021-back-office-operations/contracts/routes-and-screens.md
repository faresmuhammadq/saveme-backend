# Contract: Back-office routes and screens (spec 021)

All routes sit in the existing `operator` group of `app/Modules/Admin/Http/routes.php` (middleware `web`, `ResolveAdminLocale`, `BackOfficeHeaders`, `EnsureOperatorSignedIn`). Gate **operator** as defined in spec 018's contract: a page request without a full operator sign-in redirects to `admin.sign-in`; a Livewire update answers 401. Every page answers `Cache-Control: no-store`. `AdminRouteSurfaceTest` asserts this table.

## Routes

| Method | Path | Name | Component | Params |
|---|---|---|---|---|
| GET | `/admin` | `admin.overview` | `Overview` (body replaced) | — |
| GET | `/admin/claims` | `admin.claims` | `ClaimQueue` | query `status` (`submitted` default, `approved`, `rejected`, `expired`), `q`, `after` |
| GET | `/admin/claims/{claim}` | `admin.claims.show` | `ClaimReview` | `claim` uuid (`whereUuid`); unknown → 404 page |
| GET | `/admin/claims/{claim}/receipt` | `admin.claims.receipt` | existing controller (spec 020) | signed |
| GET | `/admin/accounts` | `admin.accounts` | `FindAccount` | — |
| GET | `/admin/accounts/{user}` | `admin.accounts.show` | `AccountPage` | `user` uuid; unknown → 404 page (no `account_viewed` written) |
| GET | `/admin/plans` | `admin.plans` | `PlanCatalogue` | — |
| GET | `/admin/plans/{plan}` | `admin.plans.edit` | `PlanEditor` | `plan` uuid; unknown → 404 |
| GET | `/admin/audit` | `admin.audit` | `AuditLog` | query `event`, `actor_type`, `actor_id`, `subject_type`, `subject_id`, `from`, `to` (dates `YYYY-MM-DD`) |

All components extend `BackOfficeComponent`; the arch allowlist (`LocaleSwitcher`, `Gallery`) is unchanged.

## Navigation (registered by `OperatorPresenceComposer`, not the shell)

| Key | Group | Label key | Icon | Route | Order | Badge |
|---|---|---|---|---|---|---|
| `claims` | operate | `admin::shell.nav.claims` | receipt | `admin.claims` | 20 | submitted claims (hidden at 0) |
| `accounts` | operate | `admin::shell.nav.accounts` | users | `admin.accounts` | 30 | — |
| `plans` | configure | `admin::shell.nav.plans` | layers | `admin.plans` | 10 | — |
| `audit` | configure | `admin::shell.nav.audit` | shield | `admin.audit` | 20 | — |

(Overview is the existing home entry; Security 90 and Re-enrolment 91 stay after.) Icons must exist in `IconRegistry`; a missing one is added to the registry (24-unit grid, round caps), not drawn inline.

## Screens (English copy; Arabic in `Lang/ar/*`)

### Overview — `overview.blade.php`

- Header "Platform overview"; subtitle "Counts only. Figures under 10 people are shown as “fewer than 10”."
- Stat tiles (`data-test="figure-<key>"`): Accounts, Verified, Deletion pending, New · 7 days, New · 30 days, Paid · Google Play, Paid · InstaPay, Granted · Manual, In grace, Shared wallets.
- Card "Needs attention" (`data-test="needs-attention"`): "{n} claims awaiting review · oldest {age}" → `admin.claims`; "{n} re-enrolment requests open" → `admin.reenrolment-requests`. Hidden rows at 0; "Nothing needs attention" when both are 0.
- Spec 018's low-codes alert stays first.

### Claim queue — `claim-queue.blade.php`

- Header "InstaPay claims"; subtitle "Web-only payments. Approving emails a redeem code."
- Tabs (`data-test="tab-<status>"`): Awaiting review (count), Approved, Rejected, Expired.
- Search (`data-test="claim-search"`): "Reference or exact email"; errors: "Too many lookups. Try again in {minutes} minutes."
- Table columns: Reference (LTR), Plan, Amount, Submitted (relative), Expires in (awaiting only), Flags ("Shared reference" pill when duplicates > 0). Row link → review (`data-test="claim-row"`).
- Empty: "No claims here." Paging: "Older" (`data-test="page-older"`).

### Claim review — `claim-review.blade.php`

- Header: reference (LTR, copy), status pill. Card "Payment": amount (money), plan, "Matches the current InstaPay price" yes/no, submitted, expires, language.
- Card "Payer": email address (LTR), "An account holds this address" yes/no, or "Erased after retention" when erased.
- Card "Receipt": button "Get receipt link" (`data-test="receipt-link"`) → link "Open receipt (link valid 5 minutes)" in a new tab; "No receipt" / "Erased".
- Card "Same reference" (when duplicates): rows reference, status, submitted → their reviews.
- Card "Review" (when reviewed): reviewer name, instant, reject reason (localised), internal note, code status, code expiry.
- Actions (submitted and unexpired): "Reject…" (`data-test="reject"`), "Approve…" (`data-test="approve"`); approved with an issued code: "Revoke code…" (`data-test="revoke-code"`).
- Approve dialog: "Approve this claim? A redeem code for {plan} will be emailed to the payer. This cannot be undone." + duplicate warning when another claim shares the reference → "Approve" → factor prompt.
- Reject dialog: radio Reason (Reference not found / Amount doesn't match / Duplicate / Other), textarea "Internal note (optional, never sent)" ≤ 500 → "Reject" → factor.
- Revoke dialog (reason prompt): "Why are you revoking this code?" 1–500 → factor.
- Messages: "Claim approved. The code is on its way." · "Claim rejected." · "Code revoked." · errors: "This claim can no longer be reviewed." · "Another claim with this reference is already approved." · "This plan can no longer be granted." · "This code can no longer be revoked." · field errors for reason and note.

### Find an account — `find-account.blade.php`

- A budget-spent account page shows only "Too many lookups. Try again in {minutes} minutes." and reads nothing.
- Header "Accounts"; field "Exact email or account ID" (`data-test="account-search"`), button "Find"; hint "Partial searches aren't supported."
- Miss: "No account matches." Budget: "Too many lookups. Try again in {minutes} minutes."

### Account — `account-page.blade.php`

- Header: account id (identifier with copy); when found by address, "Found by {address}" (LTR). State pill (Active / Deletion pending · {date} / Deleted), "Email verified" / "Email not verified", "Created {date}".
- Privacy note (`data-test="privacy-note"`): "Names, balances, amounts and notes are never shown here."
- Card "Plan": governing plan name ("Free" when none) and capability values (Shared wallets: Allowed/Not allowed; Members per wallet: n/Unlimited; Export; Advanced reports; Wallets: n/Unlimited).
- Card "Subscriptions" table: Plan, Channel (Google Play / InstaPay / Manual), Status, Started, Ends ("No end"), Grace until, Created; row actions "Extend…" (`data-test="extend-<id>"`), "Revoke…" (`data-test="revoke-<id>"`) per R-7; "Governing" pill on the governing row.
- Card "Counts": Wallets owned, Wallets joined, Other members in owned wallets, Signed-in devices.
- Link "Audit entries about this account" → `admin.audit?subject_type=user&subject_id={id}`.
- Header action "Grant access…" (`data-test="grant"`) when active.
- Grant dialog: Plan (select of active non-default plans), Duration (30 / 90 / 365 days / Custom days / Lifetime), Custom days 1–3,650, Reason 1–500 → "Grant" → factor.
- Extend dialog: Days 1–3,650, Reason → factor. Revoke dialog: Reason → "Revoke" (danger) → factor.
- Messages: "Access granted." · "Subscription extended." · "Subscription revoked." · errors: "This plan can't be granted." · "Only manual subscriptions with an end date can be extended." · "Google Play subscriptions are managed by Google Play." · "This account isn't active." · field errors.

### Plan catalogue — `plan-catalogue.blade.php`

- Header "Plan catalogue"; subtitle "Plans are data. A price change applies to new claims; existing subscriptions keep what they have."
- Card per plan (`data-test="plan-<id>"`): names (en / ar), status pill (Active / Retired), "Default" pill, period, order, InstaPay price or "Not sold by InstaPay", Google Play prices (read-only, or "Set up with Google Play billing"), people governed (floored), capability summary; button "Edit" → editor.

### Plan editor — `plan-editor.blade.php`

- Fields: English name, Arabic name (RTL input), Display order, InstaPay price (money input + currency select) with "Remove InstaPay price", capability rows (toggle or quota input + "Unlimited" checkbox); locked rows on the default plan show "Fixed for the default plan".
- Unsaved-changes bar (`data-test="unsaved-bar"`): "{n} unsaved changes · {list}", "Discard", "Review & save…".
- Review dialog: list of changes as "field: before → after" (shown on screen only; never audited), "{n} submitted claims name this plan", "{n} people on this plan keep it", members-limit note when lowered ("Existing members stay"), Reason → "Save" → factor.
- "Retire plan…" (non-default active): dialog with counts and reason → factor.
- Messages: "Plan saved." · "Plan retired." · "Nothing to save." · "This plan was changed by someone else. Reload to see the current values." (with "Reload" button) · "The default plan can't be retired." · field errors.

### Audit log — `audit-log.blade.php`

- Header "Audit log"; note (`data-test="audit-read-note"`): "Opening or filtering this page is recorded as audit_read. Entries are append-only."
- Filters: Event (select), Actor type, Actor ID, Subject type, Subject ID, From, To; "Apply" (`data-test="audit-apply"`), "Reset".
- Table: When ({timezone}), Event, Actor, Subject, Origin, expand toggle (`aria-expanded`). Expanded: detail members as a description list, request id, entry id.
- Paging: "Newer" / "Older" (`data-test="page-newer"`, `page-older"`); footer "Newest first · {from} → {to}".
- Errors: "Choose a range of at most 400 days." · "The end date must not be before the start." · "Enter a full ID."

### Notification bell (existing component)

- Unread dot/count per operator; each item is a button (`data-test="notification-<id>"`) that marks it read; "Mark all read" (`data-test="notifications-mark-all"`).
