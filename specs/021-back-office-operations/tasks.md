---

description: "Task list for Back-office Operations (B12)"
---

# Tasks: Back-office Operations

**Input**: Design documents from `/specs/021-back-office-operations/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/routes-and-screens.md](./contracts/routes-and-screens.md), [contracts/application-services.md](./contracts/application-services.md), [contracts/audit.md](./contracts/audit.md), [quickstart.md](./quickstart.md), [checklists/operations.md](./checklists/operations.md).

**Tests**: included. The spec's Invariants table names every test file (constitution P-16 rule 3). Write each story's tests first and watch them fail.

**Organization**: grouped by user story, in delivery order:

- US1: review InstaPay claims (P1)
- US2: look up a person and manage their access (P1)
- US3: read the audit log (P1)
- US4: edit the plan catalogue (P2)
- US5: see the platform at a glance (P3)
- US6: notifications read per operator (P3)

**Conventions for every task** (as in specs 018 and 020):

- Run through the container: `dev exec -- vendor/bin/pest <path> --compact`, `dev <artisan command>`. Host PHP has no `pdo_pgsql`.
- P-02: screens live in `app/Modules/Admin/Http/Livewire` and reach Identity, Billing and Wallets **only** through their `Application` services named in contracts/application-services.md. Admin never imports `App\Modules\Billing\Domain\PlanCode` (arch gate `EntitlementsTest`).
- P-18 rule 5: component public properties hold scalars and arrays of scalars only (DTO `toArray()` at the boundary). Every component extends `BackOfficeComponent`; every view with an action renders `<x-admin::factor-prompt />`.
- Action pattern (research R-4): dialog fields → public `confirmX()` validates → `$this->requireRecentFactor('applyX', [scalars])` → protected `applyX()` calls the service → refusal `match` → toast → refresh. Never call a service from `mount()` that writes, except the recorded reads (R-3).
- Audit entries exactly as contracts/audit.md; nothing protected in any `detail` (no address, price, capability value, note).
- Every new string in `app/Modules/Admin/Http/Lang/{en,ar}/…`; Arabic RTL, Western digits; ids, references, addresses, amounts inside `<x-admin::ltr>` / the value primitives; colours only from tokens (arch gate FR-001), logical utilities only (FR-031 gate).
- Every actionable element carries the `data-test` hook named in contracts/routes-and-screens.md.
- Run `dev exec -- vendor/bin/pint --dirty --format agent` after PHP edits; Larastan level 8 must stay clean.
- No composer/npm change; no `docs/openapi` change; no new artisan command (spec 018's arch test fixes the admin command set).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on unfinished tasks)
- **[Story]**: US1–US6 from spec.md

---

## Phase 1: Setup (docs and schema)

**Purpose**: the documentation the spec requires before code uses its terms (FR-063, glossary rule), and the two migrations.

- [X] T001 Update docs (FR-063, research R-17): `docs/glossary.md` — add **Notification Read Mark** (Admin, persisted, not syncable: one operator has read one operator notification, and when) and **Anonymity Floor** (the smallest non-zero population count the back office shows exactly: 10; smaller non-zero counts show as "fewer than 10"), add both to the entity index tables, and extend the Admin entry to name the B12 screens; `docs/security-baseline.md` — sensitive acts gain "granting, extending or revoking a person's access; saving or retiring a plan", the vocabulary note lists `admin_action` actions `account_viewed`, `account_lookup_missed`, `plan_updated`, `plan_retired` (written by Admin), the audit-entry `subject_type` list gains `plan`, protected attributes gain rows "Account facts and counts shown to operators (state, verification, plan, subscription dates, counts of wallets, members and devices) — none at rest beyond their sources — operators, with a recorded read" and "Notification read mark — none, identifiers only — operators", route budgets gain "operator account lookups: 30 per 10 minutes per operator"; `docs/privacy-commitments.md` — add, in English and Arabic under "Who can see a shared wallet", the promise "Our staff can see your account's state, your plan and how many wallets, members and devices you have — never your wallet names, amounts, balances or notes. Each time they look, it is recorded." and a mapping-table row "Operators see counts, never names or money; each look is recorded → spec 021 FR-021, FR-022, FR-024"; `specs/briefs/README.md` — B12 row note "Specified as spec 021".
- [X] T002 Create the Identity migration `app/Modules/Identity/Infrastructure/Database/Migrations/2026_10_10_100000_allow_plan_audit_subject.php`: drop and re-add `audit_entries_subject_type_check` with `('user', 'wallet', 'session', 'subscription', 'operator', 'payment_claim', 'plan')`; add indexes `audit_entries_occurred_at_id_index (occurred_at, id)` and `audit_entries_actor_index (actor_id, occurred_at)`; `down()` restores the six-value list and drops both indexes; leave the append-only trigger untouched. Add `case Plan = 'plan';` to `app/Modules/Identity/Domain/Audit/SubjectType.php`.
- [X] T003 [P] Create the Admin migration `app/Modules/Admin/Infrastructure/Database/Migrations/2026_10_10_100001_create_operator_notification_reads_table.php` per data-model.md: `operator_id` uuid not null FK → `operator_accounts.id` ON DELETE CASCADE, `notification_id` uuid not null FK → `operator_notifications.id` ON DELETE CASCADE, `read_at` timestampTz(6) not null, primary key (`operator_id`, `notification_id`); model `app/Modules/Admin/Infrastructure/Models/OperatorNotificationRead.php` (no timestamps, non-incrementing composite key).
- [X] T004 Run `dev migrate` and `dev exec -- vendor/bin/pest tests/Arch/MigrationsTest.php --compact` (no float/decimal column; migrations in module directories).

---

## Phase 2: Foundational (shared by every story)

**Purpose**: audit vocabulary, the lookup budget, navigation, language files and the shared screen scaffolding. ⚠️ No story starts before this phase is done.

- [X] T005 Extend `app/Modules/Admin/Domain/Operators/AdminAction.php` with `AccountViewed = 'account_viewed'`, `AccountLookupMissed = 'account_lookup_missed'`, `PlanUpdated = 'plan_updated'`, `PlanRetired = 'plan_retired'`; extend `app/Modules/Admin/Application/Audit/AdminActionDetail.php` with optional `?string $fields` (sorted, comma-joined field names) and `?string $by` (`address`|`id`), still dropping null members.
- [X] T006 Add `AdminAuditService` methods in `app/Modules/Admin/Application/Audit/AdminAuditService.php`: `accountViewed(string $operatorId, string $userId)` (subject `user`), `accountLookupMissed(string $operatorId, string $by)` (subject `operator` = $operatorId), `planChanged(string $operatorId, string $planId, AdminAction $action, list<string> $fields, string $reason)` (subject `plan`; `fields` omitted for `plan_retired`), `auditRead(string $operatorId, array $detail)` (event `audit_read`, actor and subject the operator) with `app/Modules/Admin/Application/Audit/AuditReadDetail.php` implementing `AuditDetail` (scalar members `page`, `from`, `to`, and optional `event`, `actor_type`, `actor_id`, `subject_type`, `subject_id`).
- [X] T007 [P] Write `tests/Feature/Admin/AdminAuditServiceB12Test.php`: each new method writes exactly one entry with the event, actor, subject and detail of contracts/audit.md; no detail contains an `@`, a digit run longer than 6 characters from a price, or a member outside the closed shape; the database CHECK accepts subject type `plan`.
- [X] T008 Implement `app/Modules/Admin/Application/Lookup/LookupBudget.php` (`RateLimiter`, key `admin-lookup:{operatorId}`, 30 attempts per 600 seconds; `assertAvailable(string $operatorId)`, `spend(string $operatorId)`; throws `app/Modules/Admin/Domain/Exceptions/LookupBudgetSpent.php` carrying the minutes to wait, rounded up) and its test `tests/Feature/Admin/LookupBudgetTest.php` (30 spends pass, the 31st refuses with minutes; another operator unaffected; resets after the window via `travel`).
- [X] T009 [P] Add `OperatorDirectory::displayNames(list<string> $operatorIds): array<string, string>` in `app/Modules/Admin/Application/Operators/OperatorDirectory.php` (one query, decrypting each display name; unknown ids omitted) with a case in the existing operator directory tests or a new `tests/Feature/Admin/OperatorDirectoryNamesTest.php`.
- [X] T010 Register the four navigation entries in `app/Modules/Admin/Http/Shell/OperatorPresenceComposer.php` per contracts/routes-and-screens.md (keys `claims`, `accounts` in group `operate`, orders 20, 30; `plans`, `audit` in group `configure`, orders 10, 20; label keys `admin::shell.nav.{claims,accounts,plans,audit}`; `claims` badge = `ClaimQuery::awaitingSummary()['count']`, null at 0), guarded by `has()` like the existing entries; add any missing icon (`receipt`, `users`, `layers`, `shield`) to `app/Modules/Admin/Http/Icons/IconRegistry.php` on the 24-unit grid. Add the nav labels to `Lang/{en,ar}/shell.php`.
- [X] T011 Register the seven new routes in `app/Modules/Admin/Http/routes.php` inside the `EnsureOperatorSignedIn` group per contracts/routes-and-screens.md (`Route::livewire`, names `admin.claims`, `admin.claims.show`, `admin.accounts`, `admin.accounts.show`, `admin.plans`, `admin.plans.edit`, `admin.audit`; `{claim}`, `{user}`, `{plan}` constrained with `whereUuid`), with stub components that render an empty `admin::page` so later stories fill them; update `tests/Feature/Admin/AdminRouteSurfaceTest.php` to the new surface (every route operator-gated; an application Sanctum token reaches none; a signed-out page request redirects to sign-in; a Livewire update without sign-in is 401).
- [X] T012 [P] Create the language files `app/Modules/Admin/Http/Lang/{en,ar}/{claims,accounts,plans,audit,overview}.php` with every string of contracts/routes-and-screens.md (titles, tabs, columns, dialogs, messages, errors, the privacy note, the "fewer than 10" marker, the reason hint "Don't include personal details" — FR-030a); keep `tests/Feature/Admin/TranslationCompletenessTest.php` green.
- [X] T013 [P] Implement `app/Modules/Admin/Domain/AnonymityFloor.php` (pure: `display(int $count): int|string` returns the count for 0 and ≥ 10, otherwise `'fewer_than_10'`; refuses negatives) and `tests/Unit/Admin/AnonymityFloorTest.php` (0, 1, 9, 10, 11, negative).
- [X] T014 Write `tests/Feature/Admin/RecentFactorGateTest.php` scaffolding (filled per story): a dataset of every B12 state-changing action (component, method, arguments) asserting that without a proof in the last 10 minutes the factor prompt opens and the service is not called, that a wrong code changes nothing and writes no entry, and that each `applyX` method is protected (FR-006).

**Checkpoint**: vocabulary, budget, routes, navigation and strings in place.

---

## Phase 3: User Story 1 - Review InstaPay claims (Priority: P1) 🎯 MVP

**Goal**: operators review claims end to end in the browser (spec US1, FR-015–FR-019).

**Independent Test**: quickstart steps 1–9.

### Tests for User Story 1

- [X] T015 [P] [US1] Write `tests/Feature/Billing/ClaimQueryPageTest.php`: `page()` per status (submitted oldest first, others newest first), 25 per page with a stable cursor, reference search normalised (spaces, hyphens, case), address search by lookup value, no address in any item, plan names included; `awaitingSummary()` counts submitted and unexpired only with the oldest instant; `reviewState()` has no address member; `detail()->matchesCurrentPrice` true/false after a price change.
- [X] T016 [P] [US1] Write `tests/Feature/Admin/ClaimQueueTest.php` (Livewire): tabs and counts, badge value, rows show reference/plan/amount/age/time left/duplicate pill and never the seeded address, reference and address search, address search spends the lookup budget and is refused when spent ("Too many lookups…"), empty state, older paging, Arabic render with `dir="rtl"`.
- [X] T017 [P] [US1] Write `tests/Feature/Admin/ClaimReviewTest.php` (Livewire + route): opening writes exactly one `claim_address_read` before the address renders; re-render and actions write no second one; unknown claim → 404 and no entry; receipt link action returns a signed `admin.claims.receipt` URL valid 5 minutes and writes nothing itself; approve/reject/revoke with a fresh proof call `ClaimService` with the operator id and produce one `claim_reviewed` each; reject validates the reason list and a note ≤ 500; revoke validates 1–500; refusals `ClaimNotReviewable`, `ReferenceAlreadyApproved`, `PlanNotGrantable`, `CodeNotRevocable`, `InvalidRejectReason`, `InvalidReason` show their messages and change nothing; action buttons only in the allowed states; erased claim shows "Erased" and no receipt button; add the three actions to `RecentFactorGateTest`.

### Implementation for User Story 1

- [X] T018 [US1] Extend `app/Modules/Billing/Domain/Ports/ClaimRepository.php` and `app/Modules/Billing/Infrastructure/Repositories/EloquentClaimRepository.php` (the existing implementation) with `page(ClaimStatus $status, ?string $reference, ?string $lookup, ?string $after, int $limit): array{items: list<ClaimRecord + duplicateCount>, next: ?string}` (submitted: `created_at ASC, id ASC`; others: `DESC`), `awaitingSummary(DateTimeImmutable $now): array{count:int, oldest:?DateTimeImmutable}`, and `countSubmittedForPlan(string $planId, DateTimeImmutable $now): int`.
- [X] T019 [US1] Extend `app/Modules/Billing/Application/Claims/ClaimQuery.php` per contracts/application-services.md: `page()`, `awaitingSummary()`, `reviewState()` (new `ClaimReviewState` DTO, address-free), `lookupFor()` (through `ClaimAddresses::normaliseAndLookup`, null on `InvalidArgumentException`), and `ClaimDetail::$matchesCurrentPrice` computed with `ClaimPrice::of($plan)`; add `ClaimFilter` (enum-backed status) and `ClaimPage` DTOs with plan names (en/ar) from `PlanRepository`.
- [X] T020 [US1] Implement `app/Modules/Admin/Application/Claims/ClaimReading.php`: `open($operatorId, $claimId)` — in one transaction, check the claim exists (`ClaimQuery::reviewState`), write `claim_address_read` through `AdminAuditService::claimReadByOperator`, then return `ClaimQuery::detail()`; null and no entry when absent.
- [X] T021 [US1] Build `app/Modules/Admin/Http/Livewire/ClaimQueue.php` + `Views/livewire/claim-queue.blade.php`: `#[Url]` `status` (default `submitted`, validated against the four), `q`, `after`; search classification (`@` → address via `LookupBudget::spend` + `ClaimQuery::lookupFor`, else reference); table and tabs from the spec 006 primitives; hooks `tab-<status>`, `claim-search`, `claim-row`, `page-older`.
- [X] T022 [US1] Build `app/Modules/Admin/Http/Livewire/ClaimReview.php` + `Views/livewire/claim-review.blade.php`: `mount(string $claim)` → `ClaimReading::open` (404 when null); cards per contracts; reviewer names via `OperatorDirectory::displayNames`; `getReceiptLink()` sets `receiptUrl` + expiry from `ClaimReceipts::temporaryUrl`; approve/reject dialogs → `requireRecentFactor('applyApprove'|'applyReject', …)`; revoke via `RequiresReason` then the factor; after success refresh with `reviewState()` only (R-3); hooks `approve`, `reject`, `revoke-code`, `receipt-link`.
- [X] T023 [US1] Run `dev exec -- vendor/bin/pest tests/Feature/Billing/ClaimQueryPageTest.php tests/Feature/Admin/ClaimQueueTest.php tests/Feature/Admin/ClaimReviewTest.php tests/Feature/Admin/RecentFactorGateTest.php tests/Feature/Admin/ClaimReceiptRouteTest.php --compact` until green.

**Checkpoint**: claims reviewable from the back office (MVP).

---

## Phase 4: User Story 2 - Look up a person and manage their access (Priority: P1)

**Goal**: exact lookup, privacy-safe account page, grant/extend/revoke (spec US2, FR-020–FR-030a, FR-061).

**Independent Test**: quickstart steps 10–13.

### Tests for User Story 2

- [X] T024 [P] [US2] Write `tests/Feature/Identity/AccountDirectoryTest.php`: `byAddress` normalises (case, surrounding spaces) and finds by lookup value; unknown, purged and non-address → null; `byId` non-UUID or unknown → null, purged → facts with state `purged`; `AccountFacts` fields (state, verified, createdAt, deletionScheduledFor, activeDevices counts only `active`, unexpired sessions); `Accounts::isActive`.
- [X] T025 [P] [US2] Write `tests/Feature/Wallets/MembershipCountsTest.php`: owned, joined (non-owner roles), distinct other members across owned wallets, deleted wallets and removed members excluded; `sharedWallets()` counts live wallets with ≥ 2 live members.
- [X] T026 [P] [US2] Write `tests/Feature/Billing/AccessQueryTest.php` (subscriptions with plan names, governing id per spec 009 precedence, `canExtend` only for `manual` with an end and not revoked, `canRevoke` for `manual`/`instapay` not revoked) and `tests/Feature/Billing/OperatorSubscriptionsChannelTest.php` (extend refuses `google_play` and `instapay` with `SubscriptionNotExtendable` and a lifetime manual with the same; revoke refuses `google_play` with `SubscriptionNotRevocable`; grant/extend refuse a pending-deletion or purged account with `AccountNotActive`; days > 3,650 → `InvalidDays`; each refusal writes no entry).
- [X] T027 [P] [US2] Write `tests/Feature/Admin/AccountLookupTest.php`: hit by address (any case) and by id redirect to the page and spend nothing at find; miss by unknown address, unknown id and malformed id answer identically "No account matches", write one `account_lookup_missed` (`by` member, no address or lookup value in the entry), and spend one unit; page opening spends one unit; the 31st unit is refused with minutes and writes nothing.
- [X] T028 [P] [US2] Write `tests/Feature/Admin/AccountPageTest.php` (R-16): seed an account with display name `ZQ-NAME-4410`, wallets `ZQ-WALLET-7731`/`ZQ-WALLET-7732`, category `ZQ-CAT-5520`, a transaction of `987654` minor units with note `ZQ-NOTE-3309`, a budget, device `ZQ-DEVICE-1188`; open the page by id and by address in en and ar; assert none of the markers nor `987654` appears in the HTML or the serialised public properties; by id the stored address is absent, by address the normalised typed address appears; one `account_viewed` per opening, none on re-render or after an action; privacy note present; counts, state pills, plan and capability values correct; deletion-pending state; purged account by id shows only the id and "Deleted" (no plan, subscriptions, counts or actions) and still writes `account_viewed`; 404 for an unknown id with no entry.
- [X] T029 [P] [US2] Write `tests/Feature/Admin/AccessActionsTest.php`: grant (30/90/365/custom/lifetime; only active non-default plans offered) creates a `manual` subscription and one `entitlement_changed` with actor `operator`; extend and revoke likewise; field errors (0 days, > 3,650, blank or > 500-character reason) change nothing; `google_play` rows offer no actions; refusals show messages; governing plan refreshed; add the three actions to `RecentFactorGateTest`.

### Implementation for User Story 2

- [X] T030 [US2] Implement `app/Modules/Identity/Application/Operators/AccountDirectory.php` + `AccountFacts.php` (per contracts), adding `UserRepository::createdAt`/needed reads and `SessionRepository::activeCountFor(string $userId, DateTimeImmutable $now)` in `app/Modules/Identity/Domain/Ports/*` and their Eloquent implementations; add `Accounts::isActive(string $userId): bool` in `app/Modules/Identity/Application/Accounts.php`.
- [X] T031 [P] [US2] Implement `app/Modules/Wallets/Application/Membership/MembershipCounts.php` + `MemberCounts.php` with the repository queries it needs in Wallets' Domain ports and Infrastructure (live rows only: `deleted_at IS NULL`).
- [X] T032 [US2] Implement `app/Modules/Billing/Application/Subscriptions/AccessQuery.php`, `AccessView.php`, `SubscriptionView.php` (no price, no provider reference); change `app/Modules/Billing/Application/Subscriptions/OperatorSubscriptions.php` per research R-7 and data-model "operator command rules" (refusals `app/Modules/Billing/Domain/Refusals/{SubscriptionNotExtendable,SubscriptionNotRevocable,AccountNotActive}.php`, days upper bound 3,650 in `InvalidDays`, account state via Identity `Accounts::isActive`); update `tests/Feature/Billing/OperatorActionsTest.php` cases that extended non-manual subscriptions or granted to non-existent users so they use `manual` subscriptions and real active users (behaviour change recorded in research R-7).
- [X] T033 [US2] Implement `app/Modules/Admin/Application/Accounts/AccountLookup.php` (+ `AccountSnapshot` DTO: facts, counts, access, entitlement summary from `EntitlementService::summaryFor`) per contracts: `find()` (budget check, miss spends and writes `account_lookup_missed`), `view()` (spend one unit, write `account_viewed` and read in one transaction; null and nothing written when absent).
- [X] T034 [US2] Build `app/Modules/Admin/Http/Livewire/FindAccount.php` + `Views/livewire/find-account.blade.php` (field `account-search`, "Find"; hit → redirect to `admin.accounts.show` with the normalised address flashed for one request; miss and budget messages).
- [X] T035 [US2] Build `app/Modules/Admin/Http/Livewire/AccountPage.php` + `Views/livewire/account-page.blade.php`: `mount(string $user)` → `AccountLookup::view` (404 when null; budget-spent page shows only the wait message); header, privacy note (`privacy-note`), Plan, Subscriptions (with Governing pill and per-row actions `extend-<id>`, `revoke-<id>`), Counts cards; link to `admin.audit?subject_type=user&subject_id={id}`; grant/extend/revoke dialogs per contracts (reason hint FR-030a) → `requireRecentFactor('applyGrant'|'applyExtend'|'applyRevoke', …)`; after success reload only `AccessQuery` + entitlement summary (no second `account_viewed`).
- [X] T036 [US2] Run the US2 tests plus `tests/Feature/Billing/OperatorActionsTest.php` and `tests/Arch` until green.

**Checkpoint**: support can find a person and fix their access without seeing their money.

---

## Phase 5: User Story 3 - Read the audit log (Priority: P1)

**Goal**: filtered, paged, self-recording audit viewer (spec US3, FR-041–FR-046).

**Independent Test**: quickstart steps 19–20.

### Tests for User Story 3

- [X] T037 [P] [US3] Write `tests/Unit/Identity/AuditFilterTest.php` (defaults to the last 7 days in the operator timezone converted to UTC bounds; range > 400 days, `to` before `from`, malformed ids, unknown event/actor/subject types each give their field key; `toDetail()` omits absent members) and `tests/Unit/Identity/AuditCursorTest.php` (round-trip; tampered or garbage token → null).
- [X] T038 [P] [US3] Write `tests/Feature/Identity/AuditQueryTest.php`: newest first, 50 per page; `older` then `newer` returns the same rows with no skip or repeat, including entries appended between calls and equal `occurred_at` values (tie on `id`); each filter alone and combined; never writes.
- [X] T039 [P] [US3] Write `tests/Feature/Admin/AuditLogTest.php` (Livewire): first load, apply, older and newer each write exactly one `audit_read` (actor/subject the operator; detail = filters + page); invalid filters show field errors and write nothing; expanding writes nothing; operator actors show display names, users their id, system "System"; subject links for `user`, `payment_claim`, `plan`; the recorded-read note; `#[Url]` filters prefill from the query string; Arabic render.

### Implementation for User Story 3

- [X] T040 [US3] Implement `app/Modules/Identity/Application/Audit/{AuditFilter,AuditCursor,AuditPage,AuditRow,InvalidAuditFilter}.php` and `AuditQuery.php` per contracts (keyset on `(occurred_at, id)`; `newer` queries ascending then reverses), adding `AuditStore::page(...)` to `app/Modules/Identity/Domain/Ports/AuditStore.php` and its Eloquent implementation.
- [X] T041 [US3] Implement `app/Modules/Admin/Application/Audit/AuditReader.php`: in one transaction write `AdminAuditService::auditRead($operatorId, $filter->toDetail() + ['page' => $page])` then run `AuditQuery::page`; return the page with operator display names resolved through `OperatorDirectory::displayNames`.
- [X] T042 [US3] Build `app/Modules/Admin/Http/Livewire/AuditLog.php` + `Views/livewire/audit-log.blade.php`: filter form (`#[Url]` members), `apply()`, `older()`, `newer()` (cursors held as strings), rows as arrays, Alpine-only expansion over loaded detail, hooks `audit-apply`, `page-older`, `page-newer`, `audit-read-note`.
- [X] T043 [US3] Run the US3 tests until green.

**Checkpoint**: the log answers "who did what", and every look at it is itself in the log.

---

## Phase 6: User Story 4 - Edit the plan catalogue (Priority: P2)

**Goal**: data-only price and capability changes with audit and no lost updates (spec US4, FR-031–FR-040).

**Independent Test**: quickstart steps 14–18.

### Tests for User Story 4

- [X] T044 [P] [US4] Write `tests/Unit/Billing/PlanEditTest.php` for `PlanEdit` with every rule of data-model.md verbatim: names trimmed "1–40 characters (`mb_strlen`)"; `sort_order` "integer 1–999"; `prices.instapay` "`null` (removed) or `{amount_minor: int 100–10,000,000, currency: Currency::isKnown}`"; flags boolean; quotas "`null` (unlimited) or integer 1–1,000"; default plan "every capability except `sharing.enabled` and `sharing.max_members_per_wallet` must be `true` / `null`"; changed-field names sorted (`features.<key>`, `name.ar`, `name.en`, `prices.instapay`, `sort_order`); identical draft → `NothingToSave`.
- [X] T045 [P] [US4] Write `tests/Feature/Billing/PlanAdministrationTest.php`: update applies all changes or none; stale version → `PlanChangedElsewhere` and nothing changes; Google Play price entries carried over untouched; `GET /api/v1/plans` reflects the change; a new claim at the old price is refused and a submitted one stays approvable; retire refuses the default plan and an already retired one; retired plan keeps serving its subscriptions and cannot be granted; `PlanAdminQuery` never exposes the code, counts governed people and submitted claims; and through Admin `PlanChanges`: exactly one `admin_action` (`plan_updated` with `fields` + reason, `plan_retired` with reason), no amount or capability value anywhere in the entry, entry and change roll back together.
- [X] T046 [P] [US4] Write `tests/Feature/Billing/BillingSeederTest.php`: a missing plan is inserted; an edited name, order, price, capability value and status survive a re-run; a capability key absent from a stored plan is added with the seeded value; the existing `CatalogueSeedTest` still passes.
- [X] T047 [P] [US4] Write `tests/Feature/Admin/PlanCatalogueTest.php` and `tests/Feature/Admin/PlanEditorTest.php` (Livewire): catalogue lists all plans with floored governed counts and no code (assert the seeded codes `plus_monthly` etc. are absent from the HTML); editor draft, unsaved bar listing changes, review dialog with before/after on screen and submitted-claim count, default-plan locked rows, field errors, "Nothing to save", stale-save message with Reload, retire flow and the default plan's missing *Retire*; add save and retire to `RecentFactorGateTest`.

### Implementation for User Story 4

- [X] T048 [US4] Implement `app/Modules/Billing/Domain/Plans/{PlanEdit,PlanChangeSet}.php` and refusals `app/Modules/Billing/Domain/Refusals/{PlanEditInvalid,NothingToSave,PlanChangedElsewhere,PlanNotRetirable}.php` (pure Domain; `PlanEditInvalid` carries field keys).
- [X] T049 [US4] Extend `app/Modules/Billing/Domain/Ports/PlanRepository.php` and its Eloquent implementation with `all()`, `version(string $id): ?string`, `updateIfVersion(string $id, string $version, array $columns): bool`, `retireIfVersion(string $id, string $version): bool` (`UPDATE … WHERE id = ? AND updated_at = ?`, new `updated_at`), and expose `updated_at` on `PlanRecord` as the version string.
- [X] T050 [US4] Implement `app/Modules/Billing/Application/Plans/{PlanAdminQuery,PlanAdminView,PlanAdministration}.php` per contracts (governed people via `SubscriptionRepository` granting counts per plan; submitted claims via `ClaimRepository::countSubmittedForPlan`; `PlanAdminView` without `code`).
- [X] T051 [US4] Implement `app/Modules/Admin/Application/Plans/PlanChanges.php` (one `DB::transaction` around `PlanAdministration` and `AdminAuditService::planChanged`).
- [X] T052 [US4] Change `app/Modules/Billing/Infrastructure/Database/Seeders/BillingSeeder.php` to create-missing-only per research R-6 (insert absent codes; add absent `Feature` keys; never overwrite).
- [X] T053 [US4] Build `app/Modules/Admin/Http/Livewire/PlanCatalogue.php` + `Views/livewire/plan-catalogue.blade.php` (cards `plan-<id>`, Edit links) and `app/Modules/Admin/Http/Livewire/PlanEditor.php` + `Views/livewire/plan-editor.blade.php` (draft array + `version`; money input in minor units; Arabic name input `dir="rtl"`; unsaved bar `unsaved-bar`; review via `PlanAdminQuery::preview`; save/retire → `requireRecentFactor('applySave'|'applyRetire', …)` → `PlanChanges`; refusal messages; reason hint).
- [X] T054 [US4] Run the US4 tests plus `tests/Feature/Billing/CatalogueSeedTest.php`, `tests/Feature/Billing/EntitlementResolutionTest.php` and `tests/Arch/EntitlementsTest.php` until green.

**Checkpoint**: spec 009 SC-002 delivered: price and capability changes with no code change and no deployment.

---

## Phase 7: User Story 5 - See the platform at a glance (Priority: P3)

**Goal**: aggregate-only overview with the anonymity floor (spec US5, FR-010–FR-014).

**Independent Test**: quickstart step 21.

- [X] T055 [P] [US5] Write `tests/Feature/Identity/AccountFiguresTest.php` and `tests/Feature/Billing/BillingFiguresTest.php` (exact counts: totals exclude purged; new in 7/30 days; people with a granting active/grace subscription on a non-default plan, distinct per channel, a person with two channels counted in each; in grace).
- [X] T056 [P] [US5] Write `tests/Feature/Admin/OverviewTest.php`: every tile with the floor (0 exact, 1–9 "fewer than 10", ≥ 10 exact) in en and ar; *Granted · Manual* label (never "Paid"); needs-attention exact with the oldest age and links, hidden at 0, "Nothing needs attention"; low-codes alert still first; no audit entry written; no email, user id or amount in the HTML.
- [X] T057 [US5] Implement `app/Modules/Identity/Application/Figures/{AccountFigures,AccountFigureSet}.php` and `app/Modules/Billing/Application/Figures/{BillingFigures,BillingFigureSet}.php` (repository count methods in their ports and Eloquent implementations), and add an all-operators `openCount()` to `app/Modules/Admin/Application/Reenrolment/ReenrolmentQuery.php`.
- [X] T058 [US5] Implement `app/Modules/Admin/Application/Overview/OverviewFigures.php` (gathers figures, applies `AnonymityFloor` to population counts only) and replace the body of `app/Modules/Admin/Http/Livewire/Overview.php` + `Views/livewire/overview.blade.php` with the tiles (`figure-<key>`) and the needs-attention card (`needs-attention`), keeping the low-codes alert and the auth notice.
- [X] T059 [US5] Run the US5 tests until green.

---

## Phase 8: User Story 6 - Notifications read per operator (Priority: P3)

**Goal**: per-operator read marks (spec US6, FR-050–FR-053).

**Independent Test**: quickstart step 22.

- [X] T060 [P] [US6] Write `tests/Feature/Admin/NotificationReadMarksTest.php`: unread = last 7 days not marked by this operator; open marks one for this operator only; mark-all marks this operator's unread only; both idempotent; older than 7 days never unread; no audit entry; update `tests/Feature/Admin/OperatorNotificationBellTest.php` and `OperatorNotificationsTest.php` for the per-operator count.
- [X] T061 [US6] Extend `app/Modules/Admin/Domain/Ports/OperatorNotificationStore.php` and `app/Modules/Admin/Infrastructure/Repositories/EloquentOperatorNotificationStore.php` with `latestFor`, `unreadCountFor`, `markRead`, `markAllRead` (`INSERT … ON CONFLICT DO NOTHING`); implement `app/Modules/Admin/Application/Notifications/NotificationReadMarks.php`; make `OperatorNotificationQueryService` operator-aware (`latestFor`, `unreadCountFor`) and add a `read` flag to `OperatorNotificationView::toArray()`.
- [X] T062 [US6] Extend `app/Modules/Admin/Http/Livewire/OperatorNotificationBell.php` + its view with `open(string $id)` (mark read; follow the item's account link when it names a person — an ordinary, recorded, budgeted account opening) and `markAllRead()`; hooks `notification-<id>`, `notifications-mark-all`; strings in `Lang/{en,ar}/notifications.php`. Run the US6 tests.

---

## Phase 9: Polish & Cross-Cutting

- [X] T063 [P] Write `tests/Feature/Admin/BackOfficeLanguageTest.php`: every B12 screen renders in ar with `dir="rtl"`, Western digits, and ids/references/addresses/amounts inside left-to-right isolates; no physical left/right utilities (existing FR-031 gate) and no arbitrary colours (FR-001 gate) in the new views; every success toast lands in the toast stack's `role="status"` / `aria-live="polite"` region and every dialog restores focus to its opener (FR-007, FR-056).
- [X] T063a [P] Write `tests/Feature/Admin/BackOfficeLoggingTest.php` (FR-062): with the log captured (`Log::spy()` or a test channel), drive a claim review open, an account lookup by address, a grant with a reason, a plan save with a price and a reason, and an audit read; assert no log record contains the claim address, the typed address, the reason text, the review note, the price minor units or any display name.
- [X] T064 [P] Write the opt-in `tests/Feature/Admin/BackOfficePerformanceTest.php` (skipped unless `RUN_PERF=1`, like spec 020's): seed 10,000 accounts, 2,000 claims, 100,000 audit entries; first render of each screen p95 < 1.5 s with Xdebug off (SC-005).
- [X] T065 Run the full gate: `dev exec -- vendor/bin/pint --dirty --format agent`, `dev exec -- composer test` (Arch, Larastan 8, Unit, Feature, Contract) and `dev exec -- vendor/bin/pest tests/Contract/OpenApi --compact` (must stay green: no API change, FR-058); confirm `git diff --stat composer.json package.json docs/openapi` is empty (FR-058, FR-059), that no new class implements a domain event under any module's `Domain/Events` (FR-060), and that `AuditLog` offers no edit, delete or export path (FR-046).
- [X] T066 Browser validation with Playwright (MCP) per quickstart.md steps 1–24, English and Arabic, 1280 px and 390 px, keyboard-only passes; save the report to `specs/021-back-office-operations/test-reports/validation-<timestamp>.md` with screenshots; fix any defect found and re-run its tests. Run in the browser on 2026-10-05 (Playwright): see test-reports/validation-20261005-123900.md; steps 7, 9, 17, 22 (second operator) and 23 stay with Livewire tests.
- [X] T067 Write `specs/021-back-office-operations/checklists/fidelity.md`: compare each built screen with its board (artifact `Ag9pG4RnifekirczEG2drM`, version `1791137074-53df`: `Overview`/`OverviewAr`, `Claims`/`ClaimsAr`, `UserLookup`/`UserLookupAr`, `Plans`/`PlansAr`, `Audit`/`AuditAr`) as spec 018's fidelity checklist does, listing every recorded gap of research R-15 and every screen composed without a board.
- [ ] T068 Write specs/021-back-office-operations/summary.md (parent writes it)

---

## Dependencies & Execution Order

- **Phase 1 → Phase 2 → stories.** T001 (glossary) precedes any code using the new terms; T002/T003 precede anything touching their tables.
- **Stories** are independent after Phase 2, with two soft links: US5's *Needs attention* reuses `ClaimQuery::awaitingSummary` (T019) and US6's account link reuses `AccountLookup::view` (T033) — do US1 and US2 first, or stub those calls.
- **Within a story**: tests (fail first) → Domain → Application → Admin orchestration → component and view → run.
- **Polish** after all stories; T068 last.

### Parallel opportunities

- Phase 1: T003 with T002.
- Phase 2: T007, T009, T012, T013 together after T005/T006.
- US1: T015, T016, T017 together; US2: T024–T029 together, then T030 ∥ T031; US3: T037–T039 together; US4: T044–T047 together; US5: T055 ∥ T056; Polish: T063 ∥ T063a ∥ T064.
- Stories US3, US4, US6 can be built in parallel by different people after Phase 2.

### Parallel example (US2)

```text
T024 AccountDirectoryTest   T025 MembershipCountsTest   T026 AccessQuery/ChannelTest
T027 AccountLookupTest      T028 AccountPageTest        T029 AccessActionsTest
then T030 (Identity) ∥ T031 (Wallets) → T032 (Billing) → T033 → T034 → T035 → T036
```

## Implementation Strategy

- **MVP**: Phases 1–3 (claims review) — removes the command-line-only review and the 7-day expiry risk.
- **Increment 2**: US2 (support remedies) and US3 (audit viewer) — completes the P1 set.
- **Increment 3**: US4 (catalogue editing), then US5 and US6.
- Each checkpoint is shippable: T010/T011 register routes and sidebar entries together; a screen whose story has not landed renders an empty page, so ship increments with all four stories' screens or hide the unfinished entries.
