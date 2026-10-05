# Research: Back-office Operations (spec 021, B12)

Every decision below is an **assumed default** (owner away with delegated authority), the conservative option. Numbered R-n; the plan and tasks cite them.

## R-1 Screens are full-page Livewire components on spec 018's operator group

- **Decision**: eight `Route::livewire` routes inside the existing `EnsureOperatorSignedIn` group in `app/Modules/Admin/Http/routes.php`: `admin.overview` (existing `Overview`, body replaced), `admin.claims` (`ClaimQueue`), `admin.claims.show` (`ClaimReview`), `admin.accounts` (`FindAccount`), `admin.accounts.show` (`AccountPage`), `admin.plans` (`PlanCatalogue`), `admin.plans.edit` (`PlanEditor`), `admin.audit` (`AuditLog`). Route parameters are constrained with `whereUuid`, so a malformed id is the standard 404 before any component runs. Each component extends `BackOfficeComponent` and renders `->layout('admin::page', ['title' => …])` like `SecurityPage`.
- **Rationale**: identical to how spec 018 added `SecurityPage` and `ReenrolmentRequests`; the operator group already guards pages and Livewire's update endpoint (persistent middleware + `boot()`), and the arch gate (`AdminBackOfficeTest`) already requires the base class.
- **Alternatives**: one tabbed "operations" component (rejected: one huge public state, harder to authorize per action, deep links impossible); controllers + Blade forms (rejected: the primitives and `RequiresRecentFactor` are Livewire-based).

## R-2 Data reaches screens only through Application services; DTOs become arrays

- **Decision**: new Application services in the owning modules (contracts/application-services.md):
  - **Billing**: `ClaimQuery::page()`, `awaitingSummary()`, and `detail()` gains `matchesCurrentPrice`; `Subscriptions\AccessQuery` (a person's subscriptions with plan names and the governing id); `Plans\PlanAdminQuery` (every plan without its code, governed counts, submitted-claim counts); `Plans\PlanAdministration` (update, retire); `Figures\BillingFigures` (paid access by channel, subscriptions in grace).
  - **Identity**: `Operators\AccountDirectory` (find by address or id → `AccountFacts`); `Audit\AuditQuery` (filtered keyset pages); `Figures\AccountFigures`; `Accounts::isActive()` for Billing's new rule.
  - **Wallets**: `Membership\MembershipCounts` (per person: owned, joined, other members; platform: shared wallets).
  - **Admin**: `Accounts\AccountLookup`, `Audit\AuditReader`, `Plans\PlanChanges`, `Notifications\NotificationReadMarks`, `Lookup\LookupBudget`, plus `AdminAuditService` methods.
  Components keep only scalars and arrays of scalars in public state (DTO `toArray()` at the boundary, as `OperatorNotificationBell` does) — P-18 rule 5.
- **Rationale**: P-18 rule 5; P-02 (Admin never imports another module's Infrastructure); keeps every rule testable without a browser.
- **Alternatives**: read models in Admin over other modules' tables (rejected: P-02).

## R-3 Recorded reads are written in `mount()`, once per page open

- **Decision**: `ClaimReview::mount()` calls Admin's `ClaimReading::open($operatorId, $claimId)` which writes `claim_address_read` and then returns the detail; `AccountPage::mount()` calls `AccountLookup::view()` which writes `account_viewed`. Later Livewire updates never re-run `mount()`. After a state-changing action the component refreshes only the review facts through a new address-free `ClaimQuery::reviewState()` (status, reviewer, review instant, reject reason, note, code status and expiry, closed and erased instants); `detail()` is never called again on the same open page, so no second entry is written.
- **Rationale**: FR-017, FR-024 — one entry per opening; never zero, never one per keystroke.
- **Alternatives**: write on every render (rejected: inflates the log; Livewire re-renders on every update); write only once per session (rejected: a re-open is a new read).

## R-4 Sensitive actions: dialog → `RequiresRecentFactor` → protected method → Application service

- **Decision**: each action dialog collects its fields into public scalar properties, validates them in a public `confirmX()` method, then calls `$this->requireRecentFactor('applyX', [scalars])`. `applyX` is protected (the trait refuses public targets), re-validates, calls the service, maps refusals to messages, closes the dialog, toasts. Revoke-code uses spec 006's `RequiresReason` (single reason) then the factor. Each view renders `<x-admin::factor-prompt />`.
- **Rationale**: FR-006; the trait already guarantees the browser cannot call the protected method and the pending call is locked state.
- **Alternatives**: one generic "confirm with factor" dialog (rejected: the confirmation must state the action's specifics — plan, amount, counts).

## R-5 Plan editing: pure validation in Billing Domain, optimistic version, atomic audit through Admin

- **Decision**:
  - `Billing\Domain\Plans\PlanEdit` (pure) validates a change set against a `PlanRecord`: names trimmed 1–40, order 1–999, InstaPay price integer 100–10,000,000 in `Currency::isKnown` or `null` (removed), capability values per `Feature` (flag bool; quota int 1–1,000 or null = unlimited), default plan's non-sharing capabilities must stay true/null; returns the list of changed field names (`name.en`, `name.ar`, `sort_order`, `prices.instapay`, `features.<key>`) or throws `PlanEditInvalid` with field errors; an empty list throws `NothingToSave`.
  - `PlanAdministration::update(string $planId, string $version, PlanChangeSet $changes): list<string>` runs in a transaction: `UPDATE plans … WHERE id = ? AND updated_at = ?` (version is the `updated_at` instant, microseconds, as a string); zero rows → `PlanChangedElsewhere`. Google Play price entries in `prices` are carried over untouched. `retire(planId, version)` refuses the default plan and a retired one.
  - `Admin\Application\Plans\PlanChanges` wraps both in `DB::transaction` and writes `AdminAuditService::planChanged($operatorId, $planId, AdminAction::PlanUpdated|PlanRetired, $fields, $reason)` in the same transaction (Admin owns `admin_action`).
- **Rationale**: FR-032–FR-038; P-09 rule 5 enforced for every caller; no amounts in the entry (baseline never-contain list).
- **Alternatives**: Billing writes the `admin_action` (rejected: the baseline names Admin the sole writer); a pessimistic lock while editing (rejected: locks across HTTP requests); storing old/new values (rejected: amounts in the audit log).

## R-6 The catalogue seeder becomes create-missing-only

- **Decision**: `BillingSeeder::run()` inserts a definition whose `code` is absent; for an existing plan it adds only capability keys absent from its stored `features` (with the seeded value) and saves nothing else. Validation of definitions is unchanged. `CatalogueSeedTest` "seeding again changes nothing" keeps passing; new tests prove an edited price/name/value survives and a new capability key is added.
- **Rationale**: FR-039; spec 009 SC-002; a deploy must never undo an operator's price change.
- **Alternatives**: a "managed by back office" flag per plan (rejected: more state for the same effect); remove the seeder from production (rejected: spec 009 FR-004 requires it).

## R-7 Operator channel rules move into Billing's commands

- **Decision**: `OperatorSubscriptions::extend` refuses a subscription whose provider is not `manual` (`SubscriptionNotExtendable`) or whose period end is null; `revoke` refuses `google_play` (`SubscriptionNotRevocable`); `grant` and `extend` refuse when Identity's `Accounts::isActive($userId)` is false (`AccountNotActive`). Each refusal is a Billing Domain refusal mapped to a localised message by the screen. Existing `OperatorActionsTest` cases that extend non-manual subscriptions are updated to manual ones (behaviour change recorded here).
- **Rationale**: FR-029; Google Play is the source of truth for its subscriptions (ADR-0013 rule 3; B10); a rule only on the screen would not hold for the CLI or tests.
- **Alternatives**: screen-only filtering (rejected: P-18 rule 6 wants invariants in services).

## R-8 Audit log read: keyset pages over Identity's store, `audit_read` by Admin

- **Decision**: `Identity\Application\Audit\AuditQuery::page(AuditFilter $filter, ?AuditCursor $cursor, Direction $direction, int $limit = 50): AuditPage` — keyset on `(occurred_at, id)` descending (`older`) or ascending then reversed (`newer`), returning rows plus `olderCursor`/`newerCursor` (opaque base64 of instant + id). `AuditFilter` validates: event ∈ `AuditEvent`, actor type ∈ `ActorType`, subject type ∈ `SubjectType`, ids are UUIDs, range ≤ 400 days, `to ≥ from`. A new Identity migration adds indexes `(occurred_at, id)` and `(actor_id, occurred_at)`. `Admin\Application\Audit\AuditReader::read($operatorId, …)` writes `audit_read` (actor operator, subject operator = self, `AuditReadDetail` with the filter members, `page` ∈ `first|older|newer`) **then** queries, in one transaction, so a failed query leaves no entry and a written entry always had its query.
- **Rationale**: FR-041–FR-045; Admin owns `audit_read` (baseline vocabulary); keyset paging never skips or repeats under concurrent appends (FR-041).
- **Alternatives**: offset pages (rejected: shifting rows as entries append); subject of `audit_read` = the filtered subject (rejected: no subject when unfiltered; the filters are in the detail).

## R-9 Lookups: exact, normalised, budgeted

- **Decision**: `Identity\Application\Operators\AccountDirectory::byAddress(string)` normalises with `EmailAddress` and finds by lookup value (purged → null: its address is erased); `byId(string)` requires a UUID and returns a purged account with state `purged`, which the page shows as "Deleted" with nothing else. `Admin\Application\Lookup\LookupBudget` (Laravel `RateLimiter`, key `admin-lookup:{operatorId}`, 30 per 600 s) is spent by `AccountLookup::view()` (every account-page opening, however reached — search, direct URL, audit or notification link) and by `AccountLookup::find()` only on a miss (a hit spends nothing there and redirects to the page, which spends once); a miss writes `account_lookup_missed` (subject the operator). The claim queue's address search spends one unit and writes nothing.
- **Rationale**: FR-020, FR-025, FR-061; uniform "No account matches".
- **Alternatives**: no budget (rejected: an enumeration path behind one stolen session).

## R-10 Account page reads counts, never names

- **Decision**: `AccountFacts` (Identity) = id, state, verified, createdAt, deletionScheduledFor, activeDeviceCount (count of `device_sessions` in state `active` and not expired). `MembershipCounts::forUser` (Wallets) = owned (live wallets where the person is `owner`), joined (live memberships with another role), otherMembers (distinct other live members of owned wallets). `AccessQuery::forUser` (Billing) = subscriptions with plan names (en/ar) and the governing id; entitlements from `EntitlementService::summaryFor`. None of these DTOs has a field that can hold a name, amount or note.
- **Rationale**: FR-021, FR-022; enforcing privacy by shape — there is nothing to leak.
- **Alternatives**: one cross-module "account dossier" query (rejected: P-02).

## R-11 Overview figures and the anonymity floor

- **Decision**: `AccountFigures` (Identity: total non-purged, verified, pending deletion, created in last 7/30 days), `BillingFigures` (people with a granting active/grace subscription on a non-default plan, distinct per channel; subscriptions in grace), `MembershipCounts::sharedWallets()` (live wallets with ≥ 2 live members), `ClaimQuery::awaitingSummary()` (count + oldest `created_at`), Admin `ReenrolmentQuery` (open requests, all, not per viewer). `Admin\Domain\AnonymityFloor::display(int): string|int` returns the count when 0 or ≥ 10, else the marker `fewer_than_10` rendered as "fewer than 10" / "أقل من 10". Computed on each load; no cache.
- **Rationale**: FR-010–FR-013; the paused decision's "aggregate-only with an anonymity floor".
- **Alternatives**: a nightly snapshot table (rejected: new state, staleness; the paused Reporting projection is the right home later).

## R-12 Notification read marks

- **Decision**: Admin table `operator_notification_reads` (PK `operator_id, notification_id`, `read_at`, FKs cascade). `OperatorNotificationStore` gains `latestFor(operatorId, limit)` (with a `read` flag) and `unreadCountFor(operatorId, since)`; `markRead(operatorId, notificationId)` and `markAllRead(operatorId, since)` use `INSERT … ON CONFLICT DO NOTHING`. The bell gains `open(id)` and `markAllRead()`. The config window `admin.notifications.unread_days` (7) stays.
- **Rationale**: FR-050–FR-053; idempotent by construction.
- **Alternatives**: a per-operator "read up to" watermark (rejected: cannot mark one item read).

## R-13 Receipt viewing from the review

- **Decision**: *View receipt* calls `ClaimReceipts::temporaryUrl()` in a component action and sets a public `receiptUrl` (string) plus its expiry instant; the view renders it as a link with `target="_blank" rel="noopener noreferrer"` and the text "Open receipt (link valid 5 minutes)". The route (spec 020) records `claim_receipt_viewed` when followed.
- **Rationale**: FR-018; an `<a>` is keyboard- and screen-reader-friendly and survives pop-up blockers.
- **Alternatives**: `window.open` from a browser event (rejected: pop-up blockers; no visible link).

## R-14 Language files, direction, accessibility

- **Decision**: new files `app/Modules/Admin/Http/Lang/{en,ar}/{claims,accounts,plans,audit,overview}.php`; additions to `shell.php` (nav) and `notifications.php`. `TranslationCompletenessTest` covers them. Values reuse the `<x-admin::money>`, `<x-admin::identifier>`, `<x-admin::instant>`, `<x-admin::ltr>` primitives. Tables use the `table` primitives with `<th scope="col">`; status pills carry text; dialogs use `confirm-dialog`/`modal` (focus trap from spec 006); every actionable element gets `data-test`.
- **Rationale**: FR-054–FR-057; P-14.

## R-15 Design-source fidelity

- **Decision**: build to the Calm Clarity boards (artifact `Ag9pG4RnifekirczEG2drM`, version `1791137074-53df`): `Overview`/`OverviewAr`, `Claims`/`ClaimsAr`, `UserLookup`/`UserLookupAr`, `Plans`/`PlansAr`, `Audit`/`AuditAr`. No board exists for *Find an account* (search step), the grant/extend/revoke dialogs, the claim review dialogs or the plan-editor review dialog; these are composed from primitives. Recorded gaps (spec wins): claim rows show reference, not email; "Automatic checks" reduced to amount-vs-current-price, duplicates and account-exists (no Play-subscription check); account header shows id (or the typed address), not the stored email; no avatar initial, language/timezone or "last seen"; wallets and devices as counts, not per-wallet type/role rows or device names; no "Sign out all"; no support-window banner (replaced by the privacy note, FR-023); no "Admin actions" tab (a link to the audit log filtered by this subject instead); overview drops revenue, MAU, churn, sign-ups chart, revenue by source, money recorded, engagement, sync success and Play webhooks; plans board's "New plan" button, "Lifetime" plan, plan-family card grouping (Plus monthly/yearly are separate plans here) and inline feature-matrix editing across plans (editing is per plan in the editor) are not built; audit board's email targets, price diff values and "Export range…" are not built; sidebar *Subscriptions* and *Operators* entries are not built. Recorded in `checklists/fidelity.md` at implementation.
- **Rationale**: the spec's privacy and scope rules outrank composition sketches drawn before them (spec 006: boards are "for reference only" for later screens).

## R-16 Proving the privacy rule

- **Decision**: `AccountPageTest` seeds an account whose display name, wallet names, category names, transaction notes, amounts and device names are distinctive markers (e.g. `ZQ-WALLET-7731`, amount `987654`), renders the page in en and ar via `Livewire::test()` and the real route, and asserts none appears in the HTML or in `->instance()`'s public properties (serialised). The same test asserts the stored address is absent when opened by id.
- **Rationale**: SC-003; tests the outcome, not the shape alone.

## R-17 Documentation changes in this feature

- **Decision**: security baseline — sensitive acts gain grant/extend/revoke access and plan changes; vocabulary note: `admin_action` actions `account_viewed`, `account_lookup_missed`, `plan_updated`, `plan_retired`; subject types gain `plan`; protected-attributes rows "Account facts and counts shown to operators (state, verification, plan, subscription dates, counts of wallets, members and devices) — operators, with a recorded read" and "Notification read mark — operators"; route budgets gain the lookup budget. Glossary — **Notification Read Mark**, **Anonymity Floor**, the Admin entry names the B12 screens. Privacy commitments — a promise in English and Arabic: "Our staff can see your account's state, your plan and how many wallets, members and devices you have; never your wallet names, amounts, balances or notes", with its mapping row. Briefs README — B12 row "Specified as spec 021". No ADR (nothing Accepted is contradicted) and no constitution change.
- **Rationale**: CONTRIBUTING items 13, 14, 18; the privacy page's own rule that a promise moves with its rule; the public page said "Nobody outside it sees anything at all … not that it exists", which counts would otherwise quietly contradict.

## R-18 No dependency, API or event change

- **Decision**: nothing added to `composer.json`/`package.json`; `docs/openapi` untouched (the contract suite `tests/Contract/OpenApi` must stay green); no new domain event.
- **Rationale**: FR-058–FR-060; CONTRIBUTING item 17.
