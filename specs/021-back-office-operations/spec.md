# Feature Specification: Back-office Operations

**Feature Branch**: `021-back-office-operations`

**Created**: 2026-10-05

**Status**: Draft

**Input**: Brief B12 ([specs/briefs/B12-back-office-operations.md](../briefs/B12-back-office-operations.md)): "A Filament panel behind a separate `admin` guard with 2FA: review InstaPay claims (approve/reject with reason), grant/extend/revoke manual subscriptions, look up a user and see their subscriptions, entitlements, wallets count and members, edit the plan catalog and prices, view the security audit log. Every admin action writes an audit row." Considerations: "Keep it thin — no editing of user financial data. Approving a claim is the only path that creates an `instapay` subscription; it must be a domain command reused by tests, not Filament-only code. Seed one admin via artisan command." Plus the brief's reconcile table, its "Inherited from B11 (spec 020)" notes, and the per-operator notification read state deferred by spec 018.

> **Nature of this feature.** B12 gives operators the screens they work in every day: the InstaPay claim queue, a person's access (plan, subscriptions, entitlements), the plan catalogue, the audit log, and a platform overview. It adds **screens, not powers**: every state change runs through a command another feature already built (spec 020's `ClaimService`, spec 009's operator subscription commands) or a small new command in the module that owns the data (the plan catalogue in Billing). The screens are composed from the spec 006 primitives, sit behind the spec 018 guard, and follow the Calm Clarity boards where they exist.
>
> **Where the brief and the ratified decisions differ, the ratified side wins:**
>
> | Brief | In force | Here |
> |---|---|---|
> | "A Filament panel" | P-18 rule 2: the back office is Livewire and Blade; a second interface stack needs an amendment | Livewire components in `app/Modules/Admin/Http/Livewire`, each extending `BackOfficeComponent`, built from the spec 006 primitives. No Filament |
> | "Separate `admin` guard with 2FA", "seed one admin via artisan command" | Delivered by spec 018 (D2): operator guard, second factor, `admin:operator:create` | Out of scope here; every B12 route sits in spec 018's `operator` route group |
> | "Look up a user and see … wallets count and members" | P-18 rule 8 and the security baseline's protected-attributes table: operators get no read on amounts, balances, notes; wallet and category names are readable by members only; device names by the account owner only. The paused operator-data-boundary decision: support access to a person's records is **user-granted only** | The account page shows **counts** (wallets owned, wallets joined, other members in owned wallets, signed-in devices) and the person's access (plan, subscriptions, entitlements, account state). **Never** wallet names, category names, amounts, balances, notes, transactions, budgets, device names, the display name, or the address. Each opening is a recorded read |
> | "View the security audit log" | Security baseline: every read of the log is itself an `audit_read` entry; the log is operators-only | Required: every query the audit screen runs writes exactly one `audit_read` entry |
> | "Every admin action writes an audit row" | Baseline: one act → exactly one entry, from the event's single owner | Claim reviews write `claim_reviewed` (Billing), access changes write `entitlement_changed` (Billing), plan edits and recorded reads write `admin_action` (Admin), audit reads write `audit_read` (Admin). No second entry per act |
> | "Edit the plan catalog and prices" | ADR-0013 rules 1 and 6: plans are data; the plan code is referenced only inside Billing's entitlement resolution and seeders; P-09 rule 5: the default plan is limited only on sharing; spec 009 FR-005: `active` → `retired` only | Operators edit names, display order, the InstaPay list price, and capability values, and retire a plan. The plan code, billing period and default flag are not editable and the code is never shown. No plan is created from the back office in this feature |
> | Brief B11 note: approve/reject/revoke behind `RequiresRecentFactor` | Spec 020 FR-017–FR-024; security baseline, Sessions and re-confirmation | Required. This feature extends the same re-confirmation to granting, extending and revoking access and to every plan change |

## Clarifications

### Session 2026-10-05

Owner away with delegated authority; each answer below is the recommended (most conservative) option, recorded as an **assumed default**.

- Q: Which screens and routes does B12 add? → A: Seven screens, all under `/admin` in spec 018's guarded `operator` group: **Overview** (`/admin`, replacing spec 018's placeholder body), **Claim queue** (`/admin/claims`), **Claim review** (`/admin/claims/{claim}`), **Find an account** (`/admin/accounts`), **Account** (`/admin/accounts/{user}`), **Plan catalogue** (`/admin/plans`), **Plan editor** (`/admin/plans/{plan}`) and **Audit log** (`/admin/audit`). Per-operator notification read state lives in the existing top-bar bell; no new page. The sidebar gains *InstaPay claims* (badge: claims awaiting review) and *Accounts* under **Operate**, and *Plan catalogue* and *Audit log* under **Configure**, registered through the NavigationRegistry. *Assumed default.* (FR-001–FR-004)
- Q: Who may use them? → A: **Every signed-in operator, for everything** (owner decision for MVP, 2026-09-25: every operator is an admin). Authorization is re-checked on every request a component handles (`BackOfficeComponent`), not only on page load. *Assumed default.* (FR-005)
- Q: Which actions need a fresh second-factor proof? → A: **Every state change in this feature**: approve, reject, revoke a code (required by spec 020), grant, extend and revoke access, save a plan, retire a plan. Reads do not. The security baseline's list of sensitive acts gains the four new ones. *Assumed default.* (FR-006)
- Q: How does a person's account get found, and what is shown? → A: By **exact address** (normalised the way sign-in normalises it and matched by its keyed lookup value) **or exact account id** (UUID). No partial search, no list of all accounts. The page shows: account id, account state (active, deletion pending with its date, deleted), whether the address is verified, when the account was created, the governing plan and its capability values, every subscription (plan name, channel, status, period start and end, grace end, created), and four counts: wallets owned, wallets joined as a member, other people in the wallets they own, and signed-in devices. It **never** shows the address (when found by id), the display name, any wallet or category name, amount, balance, note, transaction, budget or device name. When found by address, the page repeats the address the operator typed, never one read from storage. *Assumed default.* (FR-020–FR-025)
- Q: Is opening an account a recorded read? → A: **Yes.** Each opening of the account page writes one `admin_action` with action `account_viewed`, subject the user. A lookup that finds no account writes one `admin_action` `account_lookup_missed` about the operator themself, with no address in it. *Assumed default.* (FR-024, FR-025)
- Q: Grant, extend and revoke — which subscriptions? → A: **Grant** creates a `manual` subscription for an active, non-default plan, for 30, 90 or 365 days, a custom 1–3,650 days, or for life, only on an `active` account (verified or not: a grant is a support remedy and needs no mailbox proof). **Extend** adds 1–3,650 days to a **`manual`** subscription that has an end and is not revoked, only on an `active` account. **Revoke** ends a `manual` or `instapay` subscription (for example after a manual refund); a `google_play` subscription is **never** extended or revoked here, because Google Play is its source of truth (B10). Each takes a reason of 1–500 characters, writes exactly one `entitlement_changed` entry (spec 009), and the page shows the new governing plan at once. The channel rules are enforced in Billing's commands, not only on the screen. *Assumed default.* (FR-026–FR-030)
- Q: What can be edited in the plan catalogue? → A: For each plan: the English and Arabic names (1–40 characters each), the display order (1–999), the **InstaPay** list price (integer minor units 100–10,000,000 in a known currency, or removed), and each capability's value (a yes/no flag, or a quota of 1–1,000 or unlimited). The **default plan's** capabilities other than the two sharing ones stay on and unlimited and cannot be edited (P-09 rule 5). A non-default active plan can be **retired** (final). Not editable: the plan code (never displayed), the billing period, the default flag, Google Play prices and product ids (B10). No plan is created or reactivated here. *Assumed default.* (FR-031–FR-038)
- Q: Do price changes touch claims or subscriptions already made? → A: **No.** A new InstaPay price applies to claims submitted after the save. A submitted claim keeps the amount it stated and stays approvable; the review screen flags when it differs from the current price. Existing subscriptions are untouched. Removing a plan's InstaPay price or retiring it makes its still-submitted claims unapprovable (spec 020: `PlanNotGrantable`), so the confirmation shows how many submitted claims name that plan before saving. *Assumed default.* (FR-036, FR-037)
- Q: Does a deployment's seeder overwrite back-office plan edits? → A: **No.** The catalogue seeder becomes **create-missing-only**: it inserts a plan whose code is absent and adds a capability key missing from an existing plan (with the seeded value), and never overwrites a name, order, price, capability value or status already stored. Spec 009 FR-004 ("seeded idempotently and safely in production") still holds. *Assumed default.* (FR-039)
- Q: How is a plan edit recorded? → A: One `admin_action` per save with action `plan_updated` (or `plan_retired`), subject a new audit subject type **`plan`**, detail the **names of the changed fields only** (for example `name.ar, prices.instapay, features.sharing.max_members_per_wallet`) and the operator's reason (1–500 characters). **No old or new values**: a price is an amount, and the baseline keeps amounts out of every entry. Two operators editing the same plan cannot overwrite each other: a save based on a stale copy is refused with "changed by someone else, reload". *Assumed default.* (FR-038, FR-040)
- Q: What does the audit log screen offer? → A: Newest first, **50 entries a page**, "Older" / "Newer" by cursor, filters for event (the fixed vocabulary), actor type, actor id, subject type, subject id (exact UUIDs) and a date range in the operator's timezone (default the last 7 days, at most 400 days). Each row: time, event, actor (operator display name for operators, id for users, "System"), subject (id, linking to the account, claim or plan screen where one exists), origin; expanding a row shows its detail members, request id and entry id. **Every query the screen runs** — first load, applying filters, each page — writes exactly **one `audit_read`** entry (actor the operator, subject the operator themself, detail the filters used). Export is out of scope. *Assumed default.* (FR-041–FR-046)
- Q: What does the overview show? → A: **Aggregate counts only, no money and no individual data**: accounts (total, verified, deletion pending), new accounts in the last 7 and 30 days, people with access on a non-default plan by channel (Google Play and InstaPay are paid; manual is granted, never counted as paid — ADR-0013), subscriptions in grace, shared wallets (two or more members); and, exactly, the operational queues: claims awaiting review with the oldest one's age, and open re-enrolment requests. Population counts between 1 and 9 are shown as "fewer than 10" (an **anonymity floor of 10**); zero is shown as 0. Revenue, money volume, monthly-active and churn figures are out of scope. *Assumed default.* (FR-010–FR-014)
- Q: Per-operator notification read state? → A: Each operator has their own read marks. The bell's unread count is the notifications from the last 7 days (the existing window) **this operator** has not marked read. Opening an item marks it read for that operator; "Mark all read" marks every unread item read. Read marks are the operator's own interface state, not an act on a person, so they write **no** audit entry. *Assumed default.* (FR-050–FR-053)
- Q: Languages, direction and accessibility? → A: Every new string in English and Arabic; Arabic renders right-to-left with Western digits, identifiers, references, codes and addresses isolated left-to-right (spec 006 FR-030–FR-037). Every screen is usable by keyboard alone, each dialog traps and restores focus, status is never told by colour alone, and toasts are announced. *Assumed default.* (FR-054–FR-057)
- Q: Any new dependency, API endpoint or domain event? → A: **None.** No composer or npm package, no `/api/v1` endpoint (the OpenAPI contract is unchanged and its contract suite stays green), no new domain event; access changes keep publishing spec 009's `SubscriptionActivated` / `SubscriptionLapsed`. *Assumed default.* (FR-058–FR-060)

### Session 2026-10-05 (clarify pass)

- Q: Should looking people up be rate-limited per operator, so a compromised operator session cannot enumerate addresses quickly? → A: **Yes: 30 lookups per 10 minutes per operator**, counting account-page openings (however reached), misses on *Find an account*, and address searches in the claim queue together. Over the budget the screen says "Too many lookups. Try again in N minutes" and runs no lookup and writes no entry. *Assumed default.* (FR-061)
- Q: Is searching the claim queue by address a recorded read? → A: **No entry.** The list it returns carries no address and claims are operator-only records; the address-bearing read is the claim review, which is recorded. The search still spends the lookup budget (FR-061). *Assumed default.* (FR-015)
- Q: Are the overview figures stored or computed on each load? → A: **Computed on each load** from the owning modules' query services, with no stored aggregate table and no automatic refresh; the page reloads them when opened. A stored Reporting projection is the paused decision's later work. *Assumed default.* (FR-010)
- Q: What do back-office actions write to the application logs? → A: **Only what the audit already names, by identifier**: the standard request log line (request id, route name, operator id, status, duration; ADR-0010). Never an address, a reason, a review note, a receipt, a price or a name; validation failures log field names only. *Assumed default.* (FR-062)

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Review InstaPay claims (Priority: P1)

An operator opens *InstaPay claims*, sees the claims awaiting review oldest first, opens one, checks the transfer against the InstaPay statement using the reference, amount and receipt, and approves it (the payer is mailed a redeem code) or rejects it with a reason. A code approved by mistake can be revoked while unredeemed.

**Why this priority**: until this screen exists, InstaPay claims can only be reviewed from the command line, and an unreviewed claim expires after 7 days. It is the only way non-Play payers get access.

**Independent Test**: seed claims in each state; sign in as an operator in a browser; approve one, reject one, revoke one code; check the audit log, the mail outbox and each claim's state.

**Acceptance Scenarios**:

1. **Given** three submitted claims and one approved claim, **When** the operator opens the claim queue, **Then** the *Awaiting review* tab lists the three oldest first with reference, plan name, amount, age and time left, a duplicate flag where another claim shares the reference, and no address; the sidebar badge reads 3.
2. **Given** the queue, **When** the operator types a claim's reference in any spacing or case, or the exact address on a claim, **Then** only claims with that normalised reference, or with that address, are listed.
3. **Given** a submitted claim, **When** the operator opens it, **Then** the review shows plan, amount (and whether it equals the plan's current InstaPay price), reference, submitted and expiry instants, the claim's address, whether an account holds that address, the claim language, the receipt button when a receipt exists, and the other claims sharing the reference; and exactly one `admin_action` `claim_address_read` entry about the claim is written.
4. **Given** the review, **When** the operator presses *Get receipt link* and follows the link, **Then** the receipt opens in a new tab through a link valid for 5 minutes and one `claim_receipt_viewed` entry is written (spec 020 behaviour, reached from this screen).
5. **Given** a submitted claim and a fresh second-factor proof, **When** the operator confirms *Approve*, **Then** the claim is approved, one `claim_reviewed` entry (outcome `approved`) is written, the code mail is queued, the screen shows the claim as approved with its code issued and expiry date, and a success toast is announced.
6. **Given** no proof in the last 10 minutes, **When** the operator presses *Approve* and confirms, **Then** the factor prompt opens first; a wrong code shows an error and nothing changes; a correct code completes the approval.
7. **Given** a submitted claim, **When** the operator chooses *Reject*, picks a reason from the four and optionally writes an internal note (at most 500 characters), and confirms with a fresh proof, **Then** the claim is rejected with that reason, the note is stored but neither mailed nor audited, one `claim_reviewed` (outcome `rejected`) is written, and the rejection mail is queued.
8. **Given** an approved claim whose code is still issued, **When** the operator chooses *Revoke code*, gives a reason (1–500 characters) and confirms with a fresh proof, **Then** the code is revoked, the claim closed, one `claim_reviewed` (outcome `code_revoked`) written, and no mail sent.
9. **Given** two claims sharing a reference and one of them approved, **When** the operator tries to approve the other, **Then** the approval is refused with "another claim with this reference is already approved" and nothing changes.
10. **Given** a claim another operator reviewed a moment ago, or one past its expiry, **When** this operator confirms an approval or rejection, **Then** the action is refused with "this claim can no longer be reviewed", the page reloads the claim's current state, and no second entry is written.

---

### User Story 2 - Look up a person and manage their access (Priority: P1)

A person writes to support: "I paid but I'm still on Free" or "please give me a month for the outage". The operator finds the account by exact address or id, sees its state, plan, subscriptions and entitlements and a few counts, and grants, extends or revokes access with a reason.

**Why this priority**: operator grants are the only remedy for an expired redeem code, a support goodwill gesture or a reviewer account (spec 020 risks); spec 009's commands have no other caller.

**Independent Test**: seed an account with a Google Play, an InstaPay and a manual subscription, two wallets and a shared one; look it up by address and by id; grant, extend, revoke; check what the page shows and never shows, and the audit entries.

**Acceptance Scenarios**:

1. **Given** a registered address, **When** the operator searches for it in any letter case with surrounding spaces, **Then** the account page opens; **Given** an address with no account or a malformed id, **Then** the screen says "No account matches" with no further hint, and one `admin_action` `account_lookup_missed` about the operator is written.
2. **Given** the account page, **When** it opens, **Then** it shows the account id (copyable), state, verification, creation date, the governing plan with each capability value, every subscription with plan name, channel, status, period and grace dates, and the counts of wallets owned, wallets joined, other members in owned wallets and signed-in devices; and exactly one `admin_action` `account_viewed` entry about the user is written.
3. **Given** the account has wallets with names, balances and transactions, a display name and named devices, **When** the account page is rendered in either language, **Then** none of those names, amounts, balances, notes, transaction data, category names, budget figures or device names appears in the page, its markup or its component state; and when the account was opened by id its address does not appear either.
4. **Given** an active account on Free, **When** the operator grants *Plus · Monthly* for 30 days with a reason and a fresh proof, **Then** a `manual` subscription is created, one `entitlement_changed` (action `grant`) is written with the operator as actor, and the page shows Plus as the governing plan.
5. **Given** a `manual` subscription that ends in 3 days, **When** the operator extends it by 30 days with a reason and a fresh proof, **Then** its end moves 30 days later and one `entitlement_changed` (action `extend`) is written.
6. **Given** a `google_play` subscription, **When** the page renders, **Then** it offers neither *Extend* nor *Revoke* for it, and the Billing command refuses either if called directly.
7. **Given** an `instapay` subscription, **When** the operator revokes it with a reason and a fresh proof, **Then** it becomes `revoked`, one `entitlement_changed` (action `revoke`) is written, and the governing plan shown falls back to the next granting subscription or Free.
8. **Given** an account whose deletion is pending, or deleted, **When** the page renders, **Then** it shows that state and offers no grant or extend; revoke stays available for a pending-deletion account.
9. **Given** the grant dialog, **When** the operator enters 0 days, more than 3,650 days, a blank reason or a reason over 500 characters, or picks a retired or the default plan, **Then** the dialog shows a field error and nothing is created.

---

### User Story 3 - Read the audit log (Priority: P1)

An operator investigating a question ("who granted this account a year of Plus?", "who looked at this claim?") opens the audit log, filters by subject, event or operator and a date range, and pages through entries. Every look at the log is itself recorded.

**Why this priority**: the baseline's operator-access rules rest on the log being readable and self-limiting; without a viewer the 400-day log answers nobody.

**Independent Test**: seed a known set of entries; open the log, filter, page; check each query wrote one `audit_read` entry with the filters.

**Acceptance Scenarios**:

1. **Given** entries over the last 30 days, **When** the operator opens the audit log, **Then** the last 7 days are listed newest first, 50 to a page, a note says "Opening this page was recorded", and exactly one `audit_read` entry is written whose detail records the default filters.
2. **Given** the log, **When** the operator filters by subject type `user` and a subject id, **Then** only that subject's entries are listed, and one more `audit_read` is written recording that filter.
3. **Given** more than 50 matching entries, **When** the operator presses *Older* and then *Newer*, **Then** the next and previous pages appear without repeating or skipping an entry, and each press writes one `audit_read`.
4. **Given** a row, **When** the operator expands it, **Then** its detail members, request id, entry id and origin are shown; expanding writes nothing.
5. **Given** a range longer than 400 days, an end before the start, or a malformed id, **When** the operator applies it, **Then** a field error is shown, no query runs and nothing is written.
6. **Given** a row whose subject is a user, a payment claim or a plan, **When** the operator follows the subject link, **Then** the account, claim review or plan editor opens (each writing its own recorded read as defined above).

---

### User Story 4 - Edit the plan catalogue (Priority: P2)

The owner decides a price change or a new members-per-wallet limit. An operator opens the plan catalogue, edits the plan, reviews the summary of changes, gives a reason, confirms with a fresh proof, and the change applies to the next catalogue read with no deployment.

**Why this priority**: spec 009 SC-002 promised "a plan's price or capability changes with 0 code changes and 0 deployments"; this is the screen that delivers it. Less urgent than claims and access, because the launch catalogue is already seeded.

**Independent Test**: seed the launch catalogue; change Plus · Monthly's InstaPay price and members per wallet; check the public catalogue endpoint, a new claim's validation, the audit entry, and that re-running the seeder keeps the edit.

**Acceptance Scenarios**:

1. **Given** the seeded catalogue, **When** the operator opens *Plan catalogue*, **Then** each plan shows its name in both languages, status, period, display order, InstaPay price, Google Play prices (read-only), capability values, and the number of people it currently governs (anonymity floor applies); the plan code appears nowhere.
2. **Given** the editor for Plus · Monthly, **When** the operator changes the InstaPay price from EGP 149.00 to EGP 159.00 and members per wallet from 5 to 6, **Then** an unsaved-changes bar lists the two changes; *Review & save* shows a summary, the number of submitted claims naming this plan, and asks for a reason; after a fresh proof the plan is saved, one `admin_action` `plan_updated` is written listing `prices.instapay, features.sharing.max_members_per_wallet` and the reason, with no amounts.
3. **Given** the saved change, **When** anyone reads the public catalogue, **Then** the new price and limit appear; **When** a claim stating EGP 149.00 for that plan is submitted, **Then** it is refused for the amount; **When** the catalogue seeder runs again, **Then** the edit is kept.
4. **Given** the default plan, **When** the editor opens, **Then** its non-sharing capabilities are shown as fixed (on / unlimited) and cannot be changed; its sharing values can.
5. **Given** invalid input (a blank name, a name over 40 characters, an order outside 1–999, a price below 100 or above 10,000,000 minor units or with a fraction, an unknown currency, a quota outside 1–1,000), **When** the operator reviews, **Then** each field shows its error and nothing is saved.
6. **Given** two operators editing the same plan, **When** the second saves after the first, **Then** the second save is refused with "This plan was changed by someone else. Reload to see the current values" and nothing is overwritten.
7. **Given** a non-default active plan, **When** the operator retires it with a reason and a fresh proof, **Then** it disappears from the public catalogue, keeps serving its existing subscriptions, cannot be granted or claimed anew, and one `admin_action` `plan_retired` is written; the default plan offers no *Retire*.

---

### User Story 5 - See the platform at a glance (Priority: P3)

An operator lands on the overview after signing in and sees how many accounts and paying people there are, what needs attention (claims waiting, the oldest one's age, open re-enrolment requests), and links to the queues, without any individual's data or any money figure.

**Why this priority**: useful orientation, but every action it points to is reachable from the sidebar.

**Independent Test**: seed known populations; check every figure, the anonymity floor, and that the page writes no audit entry and contains no individual data.

**Acceptance Scenarios**:

1. **Given** 1,234 accounts of which 1,000 verified and 3 pending deletion, **When** the overview renders, **Then** it shows 1,234, 1,000 and "fewer than 10".
2. **Given** 12 people with paid access through Google Play, 4 through InstaPay and 0 with a manual grant, **When** the overview renders, **Then** it shows 12, "fewer than 10" and 0.
3. **Given** 5 claims awaiting review, the oldest submitted 3 days ago, **When** the overview renders, **Then** the *Needs attention* card shows exactly 5 and "oldest 3 days", linking to the claim queue.
4. **Given** the overview, **When** it renders, **Then** it contains no address, name, amount, balance or identifier of any person, and writes no audit entry.
5. **Given** an operator whose recovery codes are running low, **When** the overview renders, **Then** spec 018's warning still shows above the figures.

---

### User Story 6 - Notifications read per operator (Priority: P3)

Each operator clears the notification bell for themself; another operator's bell is unaffected.

**Why this priority**: spec 018 deferred it here; today "unread" just means "recent" for everyone.

**Independent Test**: seed notifications; mark read as operator A; check A's and B's counts.

**Acceptance Scenarios**:

1. **Given** 3 notifications from the last 7 days and one from 10 days ago, **When** operator A opens the bell, **Then** the unread count is 3.
2. **Given** A opens one item, **Then** A's count becomes 2 and operator B's stays 3.
3. **Given** A presses *Mark all read*, **Then** A's count is 0, B's is still 3, and no audit entry is written.

---

### Edge Cases

- **Approve a claim whose plan was since retired, or lost its InstaPay price**: refused with "this plan can no longer be granted" (spec 020 `PlanNotGrantable`); reject is still possible.
- **A claim erased by retention** (address and receipt gone after 90 days): the review shows "erased" for both and offers no receipt button; reading it still writes `claim_address_read` (nothing is opened, but the read is the act).
- **Double click / two tabs**: approve, reject, revoke, grant, extend, revoke-access and plan saves are each one act; a repeated submit of the same dialog after success does nothing (the dialog has closed and its pending action is cleared), and the Billing commands' row locks make concurrent reviews apply once.
- **Session expires mid-dialog**: the next request is refused (401 through `BackOfficeComponent`) and the operator is sent to sign in; nothing is applied.
- **Second-factor prompt cancelled**: nothing is applied and nothing is written.
- **Lookup by an address belonging to an operator** (operators are not users): "No account matches".
- **Account found by address with a different letter case**: matched, because the address is normalised before its lookup value is computed; the page repeats the normalised form.
- **A purged account**: reached by id (from the audit log, for instance) the page shows the id and "Deleted" and nothing else — no plan, subscriptions or counts, no actions; it is still a recorded, budgeted opening. Searching its former address finds nothing (FR-025).
- **Extending a lifetime `manual` subscription**: not offered (it has no end); the command refuses it.
- **Revoking an already revoked subscription**: not offered; the command does nothing (spec 009).
- **Plan with no InstaPay price**: shown as "Not sold by InstaPay"; claims for it were already refused at submission.
- **Lowering members per wallet below what existing shared wallets hold**: allowed; the existing members stay (spec 016's downgrade rule: nobody is removed); the confirmation says so.
- **Audit filter matching nothing**: an empty state, and the query still writes its `audit_read`.
- **Audit entries about deleted users or retired plans**: still listed; subject links open the deleted-state page or the retired plan.
- **Lookup budget spent**: a 31st lookup within 10 minutes is refused with the wait time; the claim queue's reference search is not counted.
- **A notification about a person** (e.g. deletion cancelled by sign-in): opening it marks it read and links to that account's page, which is an ordinary account opening (recorded, budgeted).
- **Notifications older than 7 days**: never counted as unread, whether or not marked.
- **Arabic**: amounts, dates, ids, references and the address are left-to-right islands with Western digits inside right-to-left text.
- **Phone-width window (390 px)**: tables scroll inside their card; dialogs fit the width; no horizontal page scroll.

## Requirements *(mandatory)*

### Functional Requirements

**Screens, navigation and access**

- **FR-001**: The back office MUST add the screens Overview `/admin`, Claim queue `/admin/claims`, Claim review `/admin/claims/{claim}`, Find an account `/admin/accounts`, Account `/admin/accounts/{user}`, Plan catalogue `/admin/plans`, Plan editor `/admin/plans/{plan}` and Audit log `/admin/audit`, all inside spec 018's guarded operator route group. An unknown or malformed `{claim}`, `{user}` or `{plan}` MUST show the standard not-found page.
- **FR-002**: Each screen MUST be a Livewire component in `app/Modules/Admin/Http/Livewire` extending `BackOfficeComponent`, built only from the spec 006 primitives, with views registered under the `admin::` namespace (P-18 rules 2, 3).
- **FR-003**: Components MUST read only through other modules' and Admin's own Application services, and hold only scalars, arrays of scalars and DTOs in public state (P-18 rule 5). Every state change MUST call an application service (P-18 rule 6).
- **FR-004**: The sidebar MUST gain, through the NavigationRegistry and without editing the shell: *InstaPay claims* (badge: claims awaiting review, hidden at 0) and *Accounts* in the **Operate** group, *Plan catalogue* and *Audit log* in the **Configure** group, before spec 018's *Security* and *Re-enrolment requests*.
- **FR-005**: Every signed-in operator MAY use every screen and action (every operator is an admin in MVP). Authorization MUST be re-checked on every request a component handles (P-18 rule 7); an application (user) credential MUST NOT reach any of them (P-18 rule 4).
- **FR-006**: Approve, reject and revoke a code; grant, extend and revoke access; save a plan; and retire a plan MUST each require a second-factor proof from the last 10 minutes (`RequiresRecentFactor`), and MUST run only after an explicit confirmation dialog that states what will happen. The security baseline's list of sensitive acts MUST gain grant, extend, revoke access and plan changes in this change.
- **FR-007**: A refused or failed action MUST show a localised message, change nothing and write no entry; a successful one MUST show a toast announced to assistive technology and re-read the state it changed.

**Overview**

- **FR-010**: The overview MUST compute on each load, with no stored aggregates, and show aggregate counts only: accounts (total, verified, deletion pending), new accounts in the last 7 and 30 days, people with access on a non-default plan (a granting `active` or `grace` subscription) by channel — *Paid · Google Play*, *Paid · InstaPay* and *Granted · Manual*, so a grant is never presented as revenue (ADR-0013 rule 2), subscriptions in grace, and shared wallets (wallets with two or more members).
- **FR-011**: Every population count in FR-010 between 1 and 9 MUST be displayed as "fewer than 10" (anonymity floor 10); 0 and counts of 10 or more are exact.
- **FR-012**: The overview MUST show exactly, in a *Needs attention* card: submitted claims awaiting review with the age of the oldest, and open re-enrolment requests, each linking to its screen.
- **FR-013**: The overview MUST NOT show any money figure, any individual's identifier, address, name or data, and MUST NOT write an audit entry.
- **FR-014**: spec 018's low-recovery-codes warning MUST keep showing above the figures.

**Claims (calls spec 020's commands and queries)**

- **FR-015**: The claim queue MUST offer tabs *Awaiting review* (submitted, oldest first), *Approved*, *Rejected* and *Expired* (newest first), 25 a page with cursor paging, and a search accepting an exact reference (normalised as spec 020 does) or an exact address (matched by lookup value; not a recorded read, but it spends the lookup budget of FR-061). Rows MUST show reference, plan name, amount, submitted age, time left (awaiting only), and a duplicate flag; rows MUST NOT show an address.
- **FR-016**: The claim review MUST show plan, amount and whether it equals the plan's current InstaPay price, reference (copyable), status, submitted and expiry instants, claim language, the address, whether an account holds that address, the receipt (when present and not erased), the other claims sharing the reference (linked), and, once reviewed: reviewer (operator display name), review instant, reject reason, internal note, code status and code expiry.
- **FR-017**: Each opening of a claim review MUST write exactly one `admin_action` `claim_address_read` about the claim before the address is shown (spec 020 FR-045); a re-render of the same open page MUST NOT write another.
- **FR-018**: *View receipt* MUST obtain a fresh signed link (5 minutes) when pressed and open it in a new tab; the receipt route records `claim_receipt_viewed` (spec 020 FR-014).
- **FR-019**: *Approve* and *Reject* MUST be offered only for a submitted, unexpired claim; *Revoke code* only for an approved claim whose code is issued. Reject MUST take one of `reference_not_found`, `amount_mismatch`, `duplicate`, `other` and an optional note of at most 500 characters; revoke MUST take a reason of 1–500 characters. Each MUST call `ClaimService` with the signed-in operator's id; `ClaimNotReviewable`, `ReferenceAlreadyApproved`, `PlanNotGrantable`, `InvalidRejectReason`, `CodeNotRevocable` and `InvalidReason` MUST each map to a localised message.

**Accounts and access**

- **FR-020**: *Find an account* MUST accept an exact address or an exact account id. An address MUST be normalised as sign-in does and matched by its keyed lookup value only; there MUST be no partial, prefix or list search.
- **FR-021**: The account page MUST show: account id, state (active, deletion pending with the scheduled date, deleted), whether the address is verified, creation date, the governing plan's name and each capability value (through `EntitlementService`), every subscription (plan name, channel, status, period start, period end or "no end", grace end, created), and the counts of wallets owned, wallets joined as a non-owner member, distinct other members across owned wallets, and active signed-in devices.
- **FR-022**: The account page, its markup and its component state MUST NOT contain the person's display name, any wallet or category name, amount, balance, note, transaction, budget figure or device name (P-18 rule 8; baseline protected attributes). It MUST NOT read the stored address; when the account was found by address it MAY repeat the normalised address the operator typed, and when found by id it MUST NOT show any address.
- **FR-023**: The account page MUST state, in both languages, that names, balances, amounts and notes are not shown to operators.
- **FR-024**: Each opening of an account page MUST write exactly one `admin_action` `account_viewed` with subject the user.
- **FR-025**: A lookup that finds no account MUST answer "No account matches" identically for an unknown address, an unknown id, a malformed id and the address of a purged account (its address was erased at purge), and MUST write exactly one `admin_action` `account_lookup_missed` about the operator, carrying no address or lookup value.
- **FR-026**: *Grant access* MUST create a `manual` subscription for an active, non-default plan, for 30, 90 or 365 days, a custom 1–3,650 days, or for life, with a reason of 1–500 characters, only for an `active` account, through `OperatorSubscriptions::grant`.
- **FR-027**: *Extend* MUST add 1–3,650 days to a `manual` subscription that has a period end and is not revoked, with a reason, only for an `active` account, through `OperatorSubscriptions::extend`.
- **FR-028**: *Revoke* MUST end a `manual` or `instapay` subscription that is not revoked, with a reason, through `OperatorSubscriptions::revoke`.
- **FR-029**: Billing's operator commands MUST themselves refuse extending any subscription that is not `manual` or has no end, revoking a `google_play` subscription, and granting or extending for an account that is not `active`, so the rules hold for every caller, not only the screen.
- **FR-030a**: Reason fields MUST carry the hint "Don't include personal details", because reasons are stored in the audit log for 400 days.
- **FR-030**: Each grant, extend or revoke MUST write exactly one `entitlement_changed` entry with the operator as actor (spec 009 FR-019), and the page MUST then show the resulting governing plan.

**Plan catalogue (new Billing commands)**

- **FR-031**: The plan catalogue MUST list every plan, active and retired, in display order, with names in both languages, status, billing period, default flag, display order, InstaPay price, Google Play prices and product ids (read-only), capability values, and the number of people it currently governs (anonymity floor applies). The plan code MUST NOT be read by Admin or displayed.
- **FR-032**: The plan editor MUST allow changing: the English and Arabic names (trimmed, 1–40 characters), display order (1–999), the InstaPay price (integer minor units 100–10,000,000 in a known currency) or its removal, and each capability value (a flag; or a quota of 1–1,000 or unlimited).
- **FR-033**: For the default plan, every capability other than `sharing.enabled` and `sharing.max_members_per_wallet` MUST stay at on/unlimited and MUST NOT be editable (P-09 rule 5); the command MUST refuse such a change from any caller.
- **FR-034**: The billing period, default flag, code, status (other than retiring) and Google Play prices MUST NOT be editable. No plan MUST be created or reactivated from the back office.
- **FR-035**: Saving MUST be one Billing command taking the operator id, the plan id, the version the operator edited, the changes and a reason (1–500 characters). It MUST refuse a stale version, apply all changes or none, and leave unchanged fields as they are.
- **FR-036**: A price change MUST apply only to claims submitted afterwards; existing claims and subscriptions MUST NOT change. The confirmation MUST show the number of submitted claims naming the plan.
- **FR-037**: *Retire* MUST move a non-default active plan to `retired` (final), refusing the default plan, with a reason, through a Billing command; the confirmation MUST show the people it governs and the submitted claims naming it, and that both keep what they have.
- **FR-038**: Each save MUST write exactly one `admin_action` `plan_updated` (each retire one `plan_retired`) with subject type `plan`, listing only the changed field names and the reason; it MUST NOT contain any price amount or capability value. The plan change and its entry MUST commit together. A save with no change MUST be refused before the confirmation ("nothing to save").
- **FR-039**: The catalogue seeder MUST become create-missing-only: insert a plan whose code is absent, add a capability key absent from an existing plan, and never overwrite any stored value.
- **FR-040**: The audit vocabulary's subject types MUST gain `plan`, and Admin's `admin_action` actions MUST gain `account_viewed`, `account_lookup_missed`, `plan_updated` and `plan_retired`, in the baseline and the database constraint in the same change.

**Audit log**

- **FR-041**: The audit log MUST list entries newest first, 50 a page, with *Older* / *Newer* cursor paging that never repeats or skips an entry.
- **FR-042**: Filters MUST be: event (the fixed vocabulary), actor type, actor id, subject type, subject id (exact UUIDs) and a date range in the operator's timezone, defaulting to the last 7 days, at most 400 days, end not before start. Invalid filters MUST show field errors and run no query.
- **FR-043**: Each row MUST show the instant in the operator's timezone, the event, the actor (operator display name for an operator, the id for a user, "System"), the subject (type and id, linked to the account, claim or plan screen where one exists) and the coarse origin; expanding MUST show the detail members, request id and entry id.
- **FR-044**: Every query the screen runs (first load, filter change, each page) MUST write exactly one `audit_read` entry, actor the operator, subject the operator, detail the filters applied and the page direction; expanding a row or re-rendering MUST NOT write one.
- **FR-045**: The audit read MUST go through a new Identity application query service; Admin MUST write the `audit_read` entry through `AdminAuditService` (Admin owns `audit_read`).
- **FR-046**: The audit log MUST be read-only: no export, edit or delete.

**Operator notifications**

- **FR-050**: Admin MUST store per-operator read marks for operator notifications.
- **FR-051**: The bell's unread count MUST be the notifications from the last 7 days that the signed-in operator has not marked read.
- **FR-052**: Opening a notification MUST mark it read for that operator only; *Mark all read* MUST mark all of that operator's unread notifications read. Both MUST be idempotent.
- **FR-053**: Read marks MUST NOT write audit entries.

**Language, direction, accessibility**

- **FR-054**: Every new string MUST exist in English and Arabic in the Admin module's language files; Arabic MUST render right-to-left with Western digits.
- **FR-055**: Amounts MUST be displayed from integer minor units and a currency by the spec 006 money display; identifiers, references, addresses and dates MUST be left-to-right islands.
- **FR-056**: Every screen MUST be operable by keyboard alone; dialogs MUST move focus in, trap it and restore it on close; status MUST be conveyed by text as well as colour; form errors MUST be linked to their fields.
- **FR-057**: Interactive elements used by acceptance tests MUST carry stable `data-test` attributes.

**Boundaries**

- **FR-058**: This feature MUST NOT add an `/api/v1` endpoint or change `docs/openapi`.
- **FR-059**: This feature MUST NOT add a composer or npm dependency.
- **FR-060**: This feature MUST NOT add a domain event.
- **FR-061**: Every account-page opening (by search, direct URL or link), every lookup miss, and every address search in the claim queue MUST spend one unit of a budget of 30 per 10 minutes per operator (a search that finds an account spends once, at the page opening it leads to); over it, the screen MUST refuse with the minutes to wait, read nothing and write no entry.
- **FR-062**: Back-office requests MUST log only the standard request line (request id, route, operator id, status, duration); no address, reason, review note, receipt, price, capability value or name MAY reach the application log.
- **FR-063**: In the same change, the security baseline (sensitive acts, `admin_action` actions, subject type `plan`, protected-attribute rows for account facts and counts and for read marks, the lookup budget), the glossary (Notification Read Mark, Anonymity Floor) and the privacy commitments (an English and Arabic promise that operators see a person's account state, plan and counts of wallets, members and devices, never names, amounts, balances or notes, with its mapping row) MUST be updated.

### Key Entities

- **Notification Read Mark** (new, Admin): that one operator has read one operator notification, and when.
- **Plan** (Billing, existing): edited through new Billing commands; no schema change.
- **Subscription** (Billing, existing): created, extended and revoked through spec 009's commands with the new channel rules.
- **Payment Claim**, **Redeem Code** (Billing, existing): reviewed through spec 020's commands.
- **Audit Entry** (Identity, existing): gains the subject type `plan`; read through a new query service.

## Project Sections *(mandatory for feature specs)*

### 1. Purpose

Operators can do their daily work — review InstaPay payments, fix a person's access, adjust the plan catalogue, answer "who did what" from the audit log, and see the platform's shape — from the back office, in English or Arabic, without ever seeing what a person keeps in their wallets, and with every act and every sensitive read recorded.

### 2. Glossary terms

[Operator Account](../../docs/glossary.md#operator-account), [Operator Notification](../../docs/glossary.md#operator-notification), [Audit Entry](../../docs/glossary.md#audit-entry), [Payment Claim](../../docs/glossary.md#payment-claim), [Redeem Code](../../docs/glossary.md#redeem-code), [Plan](../../docs/glossary.md#plan), [Plan Feature](../../docs/glossary.md#plan-feature), [Subscription](../../docs/glossary.md#subscription), [Entitlement](../../docs/glossary.md#entitlement), [User](../../docs/glossary.md#user), [Wallet](../../docs/glossary.md#wallet), [Wallet Member](../../docs/glossary.md#wallet-member), [Device Session](../../docs/glossary.md#device-session). New in this change, added to the glossary before use: **Notification Read Mark** (Admin) and **Anonymity Floor** (the smallest non-zero population count the back office shows exactly; 10).

### 3. Data model

| Entity | Module | Change |
|---|---|---|
| Notification Read Mark | Admin | new table `operator_notification_reads`: `operator_id` (uuid), `notification_id` (uuid), `read_at` (instant); primary key (`operator_id`, `notification_id`); both foreign keys cascade on delete. Not syncable, never exposed outside the back office |
| Audit Entry | Identity | the `subject_type` constraint gains `plan`; no column change |
| Plan | Billing | no schema change; edits go through new commands; `updated_at` is the edit version |
| Subscription, Payment Claim, Redeem Code | Billing | no change |

### 4. Invariants

| Invariant | Principle or decision | Test that fails when broken |
|---|---|---|
| Every B12 component extends `BackOfficeComponent` and lives in Admin's Livewire directory | P-18 rules 3, 4, 7 | `tests/Arch/AdminBackOfficeTest.php` (existing gate) |
| No B12 component public property holds a model | P-18 rule 5 | `tests/Arch/AdminBackOfficeTest.php` (existing gate) |
| The account page never contains a display name, wallet/category name, amount, balance, note or device name | P-18 rule 8 | `tests/Feature/Admin/AccountPageTest.php` |
| Opening a claim review writes exactly one `claim_address_read` | spec 020 FR-045 | `tests/Feature/Admin/ClaimReviewTest.php` |
| Opening an account writes exactly one `account_viewed`; a miss one `account_lookup_missed` | baseline, recorded read | `tests/Feature/Admin/AccountLookupTest.php` |
| Every audit-log query writes exactly one `audit_read`; expanding writes none | baseline, Operational rules | `tests/Feature/Admin/AuditLogTest.php` |
| Every state change requires a fresh second factor | baseline, Sessions and re-confirmation | `tests/Feature/Admin/RecentFactorGateTest.php` |
| `google_play` subscriptions are never extended or revoked by an operator; only `manual` ones are extended | ADR-0013 rule 3, FR-029 | `tests/Feature/Billing/OperatorSubscriptionsChannelTest.php` |
| Default plan non-sharing capabilities stay on/unlimited | P-09 rule 5 | `tests/Unit/Billing/PlanEditTest.php` |
| A stale plan save changes nothing | FR-035 | `tests/Feature/Billing/PlanAdministrationTest.php` |
| A plan entry carries field names, never amounts | baseline, never-contain list | `tests/Feature/Billing/PlanAdministrationTest.php` |
| The seeder never overwrites a stored plan value | FR-039 | `tests/Feature/Billing/BillingSeederTest.php` |
| Population counts 1–9 display as "fewer than 10" | FR-011 | `tests/Unit/Admin/AnonymityFloorTest.php` |
| Admin never reads `PlanCode` | ADR-0013 rule 6, P-09 | `tests/Arch/EntitlementsTest.php` (existing gate) |
| Read marks are per operator | FR-050–FR-052 | `tests/Feature/Admin/NotificationReadMarksTest.php` |
| No address, reason, note, price or name reaches the application log | FR-062, P-15 | `tests/Feature/Admin/BackOfficeLoggingTest.php` |
| Account lookups and openings are budgeted per operator | FR-061 | `tests/Feature/Admin/LookupBudgetTest.php`, `AccountLookupTest.php` |

### 5. API

None. No `/api/v1` endpoint is added or changed and `docs/openapi` is untouched (FR-058). The back-office routes are server-rendered pages listed in FR-001 and documented in this feature's `contracts/`.

### 6. Authorization and entitlements

Operators only, all equal (MVP). The operator guard and its second factor (spec 018) gate every route and every Livewire update; state changes additionally need a proof from the last 10 minutes (FR-006). Wallet roles do not apply: operators are not members of any wallet and get no wallet data. No Feature gate applies to operators; the account page reads a person's entitlements through `EntitlementService` for display only.

### 7. Events

Published: none new. Consumed: none new. Grant/extend/revoke keep publishing spec 009's `SubscriptionActivated` and `SubscriptionLapsed` after commit.

### 8. Acceptance scenarios

| Scenarios | Layer | Test |
|---|---|---|
| US1 · 1–10 | Feature (Livewire) + browser | `tests/Feature/Admin/ClaimQueueTest.php`, `ClaimReviewTest.php`; Playwright validation run |
| US2 · 1–9 | Feature (Livewire) + Feature (Billing) + browser | `tests/Feature/Admin/AccountLookupTest.php`, `AccountPageTest.php`, `AccessActionsTest.php`; `tests/Feature/Billing/OperatorSubscriptionsChannelTest.php` |
| US3 · 1–6 | Feature (Livewire, Identity query) + browser | `tests/Feature/Admin/AuditLogTest.php`, `tests/Feature/Identity/AuditQueryTest.php` |
| US4 · 1–7 | Unit + Feature (Billing, Livewire) + browser | `tests/Unit/Billing/PlanEditTest.php`, `tests/Feature/Billing/PlanAdministrationTest.php`, `BillingSeederTest.php`, `tests/Feature/Admin/PlanEditorTest.php` |
| US5 · 1–5 | Unit + Feature | `tests/Unit/Admin/AnonymityFloorTest.php`, `tests/Feature/Admin/OverviewTest.php` |
| US6 · 1–3 | Feature | `tests/Feature/Admin/NotificationReadMarksTest.php` |

### 9. Open questions

1. **Support access windows** (user-granted record-level access, paused operator-data-boundary decision): not built; the account page states what is hidden. Owner; does not block.
2. **Money aggregates** (revenue, money recorded, by source): left out; they need the aggregate Reporting projection the paused decision recommended. Owner; does not block.
3. **Creating plans and a Subscriptions index / Operators page** (drawn in the boards' sidebar): out of scope. Owner; does not block.
4. **Price history**: the audit entry names changed fields without values, so past prices are not recoverable from the log. Owner may want a plan-history table later; does not block.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An operator reviews a typical claim (open, view receipt, approve with a fresh proof) in under 60 seconds and at most 6 interactions.
- **SC-002**: 100% of reviews, access changes, plan changes, account openings, claim openings and audit-log queries produce exactly one audit entry of the right event and actor, verified by tests.
- **SC-003**: 0 occurrences of a display name, wallet or category name, amount, balance, note or device name in the account page across both languages, verified by tests that seed distinctive values and search the rendered output and component state.
- **SC-004**: A plan's price or capability changes with 0 code changes and 0 deployments, and survives the next seeder run.
- **SC-005**: Every screen loads in under 1.5 seconds (95th percentile) on a database seeded with 10,000 accounts, 2,000 claims and 100,000 audit entries.
- **SC-006**: Every screen passes keyboard-only operation and renders correctly in English (left-to-right) and Arabic (right-to-left) at 1280 px and 390 px widths, checked by the browser validation run.
- **SC-007**: 0 new dependencies and 0 OpenAPI changes; the OpenAPI contract suite stays green.

## Assumptions

- Spec 018's operator guard, second factor, `RequiresRecentFactor`, `RequiresReason`, `AdminAuditService` and shell composer are in place and reused unchanged in behaviour.
- Spec 020's `ClaimService`, `ClaimQuery` and `ClaimReceipts`, and spec 009's `OperatorSubscriptions` and `EntitlementService`, are the commands and queries the screens call; B12 adds query methods and channel rules beside them, not new paths.
- The Calm Clarity design source (artifact `Ag9pG4RnifekirczEG2drM`, version `1791137074-53df`) has Overview, Claims, UserLookup, Plans and Audit boards in English and Arabic; they guide composition. Where a board shows data this spec forbids (addresses in lists and audit rows, wallet types and names, device names, revenue, price values in audit detail, "Export", "New plan", the Subscriptions and Operators sidebar entries, the support-window banner), the spec wins and the gap is recorded in the fidelity checklist.
- Operators work on desktop browsers; phone width must remain usable, not optimised.
- The anonymity floor of 10 follows the paused decision's "aggregate-only with an anonymity floor"; the owner may change the number later.
- Counts on the account page are about one person and are disclosed to operators as a recorded read; the privacy commitments gain a sentence saying operators can see a person's plan and the number of their wallets, members and devices, never names, amounts, balances or notes.
