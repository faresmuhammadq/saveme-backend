# Implementation Plan: Back-office Operations

**Branch**: `021-back-office-operations` (trunk-based; work lands on `main`) | **Date**: 2026-10-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/021-back-office-operations/spec.md`.

## Summary

Add the operators' working screens to the back office — claim queue and review, account lookup with access management, plan catalogue and editor, audit log, the aggregate overview — plus per-operator notification read marks. Everything is Livewire in the Admin module on top of spec 006's primitives and spec 018's guard; every read goes through Application query services of the owning module (new ones in Identity, Billing, Wallets), every state change through an Application command (spec 020's `ClaimService`, spec 009's `OperatorSubscriptions` with new channel rules, a new Billing `PlanAdministration`), each behind a fresh second factor and producing exactly one audit entry.

- **Screens & routes** (R-1, contracts/routes-and-screens.md): 8 Livewire routes in the operator group; 4 sidebar entries.
- **Reads** (R-2, R-8–R-11, contracts/application-services.md): new `AccountDirectory`, `AuditQuery`, `AccountFigures` (Identity); `ClaimQuery::page/awaitingSummary/reviewState`, `AccessQuery`, `PlanAdminQuery`, `BillingFigures` (Billing); `MembershipCounts` (Wallets).
- **Writes** (R-4, R-5, R-7): `ClaimService` unchanged; `OperatorSubscriptions` gains channel and account-state refusals; `PlanAdministration` (update/retire) with optimistic version; Admin `PlanChanges` makes the plan change and its `admin_action` atomic.
- **Audit** (R-3, R-8, contracts/audit.md): new `admin_action` actions `account_viewed`, `account_lookup_missed`, `plan_updated`, `plan_retired`; `audit_read` written by Admin per query; subject type `plan`.
- **Data** (data-model.md): Admin `operator_notification_reads`; Identity migration (subject type `plan`, two audit indexes). No Billing schema change; seeder becomes create-missing-only (R-6).
- **Docs** (R-17): security baseline, glossary, privacy commitments (en/ar), briefs README.
- **No** dependency, `/api/v1` or OpenAPI change, domain event (R-18).

## Technical Context

**Language/Version**: PHP 8.5, Laravel 13, Livewire 4, Tailwind 4 (all installed).

**Primary Dependencies**: none new (P-16 Quality Gate 6; CONTRIBUTING item 17). Livewire, Blade, the spec 006 primitives, `RateLimiter`, `DB` transactions, Pest + Livewire testing, Playwright (MCP, for validation only; not a project dependency).

**Storage**: PostgreSQL 16 — one new Admin table, one Identity constraint change and two indexes.

**Testing**: Pest.
- Unit: `PlanEdit` (every validation rule, default-plan rule, changed-field list, nothing-to-save), `AnonymityFloor`, `AuditFilter` (range, ids, enums), `AuditCursor` (encode/decode, tamper → invalid).
- Feature (application services, PostgreSQL): `AccountDirectory`, `MembershipCounts`, `AccessQuery`, `AuditQuery` (keyset both directions, filters, concurrent append), figures, `PlanAdministration` (update, stale version, retire, Google Play prices carried, catalogue reflects), `PlanChanges` (one `admin_action`, no amounts, atomic rollback), `OperatorSubscriptions` channel/account rules, seeder create-missing-only, `NotificationReadMarks`, `LookupBudget`.
- Feature (Livewire via `Livewire::test` and the real routes): each screen's render, actions, refusals, recorded reads (exactly one), factor gate, privacy markers absent (R-16), Arabic rendering, `data-test` hooks.
- Arch: existing gates (`AdminBackOfficeTest`, `ModuleBoundariesTest`, `EntitlementsTest`); `AdminRouteSurfaceTest` updated with the new routes.
- Contract: unchanged; `dev exec -- vendor/bin/pest tests/Contract/OpenApi` must stay green.
- Browser: Playwright validation of the acceptance scenarios (quickstart.md), English and Arabic, 1280 and 390 px.

**Target Platform**: the Linux server (`dev` container); operators on desktop browsers.

**Project Type**: modular-monolith JSON API with a server-rendered back office.

**Performance Goals** (SC-005): each screen p95 < 1.5 s with 10,000 accounts, 2,000 claims, 100,000 audit entries; audit pages use keyset reads on indexed columns.

**Constraints**: P-02 (Admin reaches Identity, Billing, Wallets only through their Application services; no Infrastructure crossing); P-03 (prices integer minor units; money displayed by the money primitive); P-08 rule 4 / P-18 rule 4 (operator guard, second factor); P-09 (Admin never touches `PlanCode`; capabilities edited as `Feature` keys; entitlements read via `EntitlementService`); P-14 (en/ar); P-15 (no protected values in logs); P-18 rules 2–8.

**Scale/Scope**:
- 8 screens (7 new + Overview body), 1 bell extension, ~10 dialogs;
- 2 migrations (Identity ×1, Admin ×1);
- ~45 classes across Admin (~22), Billing (~12), Identity (~8), Wallets (~2);
- ~25 test files; 5 new language files per locale.

## Constitution Check

*Constitution v1.3.12 (`.specify/memory/constitution.md`). GATE: must pass before Phase 0 research. Re-check after Phase 1 design, keeping both results.*

### Pre-design

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | Payment Claim, Redeem Code, Plan, Plan Feature, Subscription, Entitlement, Audit Entry, Operator Notification (glossary); **Notification Read Mark** and **Anonymity Floor** added to the glossary before code (T001); screens say "account", never "customer" |
| P-02 Module boundaries | PASS | Screens in `Admin/Http/Livewire`; Admin calls `Identity\Application\{Operators\AccountDirectory, Audit\AuditQuery, Figures\AccountFigures}`, `Billing\Application\{Claims\*, Subscriptions\*, Plans\*, Figures\*, Entitlements\EntitlementService}`, `Wallets\Application\Membership\MembershipCounts`; Billing calls `Identity\Application\Accounts::isActive`; no Infrastructure or Http crossing |
| P-03 Money | PASS | Prices edited and stored as integer minor units + ISO currency through the `money-input` primitive (no float parsing); amounts shown by `<x-admin::money>`; no aggregate money figure |
| P-04 Ledger integrity | N-A | No ledger read or write |
| P-05 Derived balances | N-A | No balance read; balances are explicitly never shown |
| P-06 Identifiers | PASS | Routes take UUIDs (`whereUuid`); read marks keyed by UUIDs; no new id generation besides audit entries (UUIDv7 via `AuditLog`) |
| P-07 Sync and concurrency | PASS | Not syncable; plan edits use an optimistic version (R-5); claim reviews keep spec 020's row locks; read marks `ON CONFLICT DO NOTHING` |
| P-08 Authorization | PASS | Operator guard + second factor on every route and update (`BackOfficeComponent`); fresh factor on every state change (FR-006); no wallet-role path exists for operators |
| P-09 Entitlements | PASS | Admin never reads a plan code (`PlanAdminView` has none; arch gate `EntitlementsTest`); capabilities edited as `Feature` cases with P-09 rule 5 enforced in `PlanEdit`; account entitlements from `EntitlementService::summaryFor` |
| P-10 Billing channels | PASS | No payment started or recorded; Google Play subscriptions never extended or revoked by operators (R-7); InstaPay approval stays the only path to an `instapay` subscription (spec 020) |
| P-11 Events | PASS | No new event; grant/extend/revoke keep spec 009's after-commit events |
| P-12 Time | PASS | Stored instants UTC; displayed in `admin.timezone`; audit date filters converted from operator-timezone dates to UTC bounds |
| P-13 Naming | PASS | Classes and routes named by domain terms; product name from `config('app.name')` |
| P-14 Localisation | PASS | Every string in `Admin/Http/Lang/{en,ar}`; RTL; Western digits; `TranslationCompletenessTest` |
| P-15 Observability | PASS | Only the standard request line is logged (FR-062); no address, reason, note, price or name in logs |
| P-16 Quality gates | PASS | Every §4 invariant names a test; Pint, Larastan 8, Arch, suites |
| P-17 Scope guard | PASS | No user financial data edited or shown; no plan creation, export, support windows or revenue figures |
| P-18 Delivery surfaces | PASS | Livewire + Blade only (rule 2); components in `Admin/Http/Livewire`, views under `admin::` (3); routes under `/admin`, operator guard (4); Application services and scalar/DTO-array state (5); commands for every change (6); `boot()` per request (7); no amounts, balances or notes, privacy markers tested (8) |

### Post-design re-check

Unchanged: all rows PASS or N-A.

- **CONTRIBUTING item 18**: no decision record — nothing Accepted is contradicted. ADR-0013 rule 4 ("active/grace → revoked: refund/chargeback/admin") still holds; R-7 only narrows which channel an *operator* may revoke.
- **Security baseline amendments** (T001): sensitive acts (grant, extend, revoke access; plan change; plan retire); vocabulary (`admin_action` actions list; subject type `plan`); protected attributes (account facts and counts — operators with a recorded read; notification read mark — operators); route budgets (operator lookup budget 30 / 10 min).
- **Privacy commitments**: one promise (en/ar) and a mapping row (R-17).

## Project Structure

### Documentation (this feature)

```text
specs/021-back-office-operations/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── routes-and-screens.md
│   ├── application-services.md
│   └── audit.md
├── checklists/requirements.md
├── checklists/operations.md      # /speckit-checklist
├── checklists/fidelity.md        # written at implementation (T0xx)
├── tasks.md                      # /speckit-tasks
└── summary.md                    # written by the parent after implementation
```

### Source Code (repository root)

```text
app/Modules/Admin/
├── Domain/
│   ├── AnonymityFloor.php                          # display rule (R-11)
│   ├── Operators/AdminAction.php                   # + AccountViewed, AccountLookupMissed, PlanUpdated, PlanRetired
│   └── Ports/OperatorNotificationStore.php         # + latestFor, unreadCountFor, markRead, markAllRead
├── Application/
│   ├── Accounts/AccountLookup.php                  # budget → AccountDirectory → account_viewed / account_lookup_missed
│   ├── Audit/{AdminAuditService.php (+methods), AuditReader.php, AuditReadDetail.php, PlanChangedDetail.php}
│   ├── Claims/ClaimReading.php                     # claim_address_read + ClaimQuery::detail (R-3)
│   ├── Lookup/LookupBudget.php                     # RateLimiter 30 / 600 s per operator (R-9)
│   ├── Notifications/NotificationReadMarks.php
│   ├── Operators/OperatorDirectory.php             # + displayNames(list<id>)
│   ├── Plans/PlanChanges.php                       # transaction: PlanAdministration + admin_action (R-5)
│   ├── Overview/OverviewFigures.php                # gathers figures, applies AnonymityFloor
│   └── OperatorNotificationQueryService.php        # per-operator unread/latest
├── Infrastructure/
│   ├── Database/Migrations/2026_10_10_100001_create_operator_notification_reads_table.php
│   ├── Models/OperatorNotificationRead.php
│   └── Repositories/EloquentOperatorNotificationStore.php  # + read-mark methods
└── Http/
    ├── Livewire/{ClaimQueue, ClaimReview, FindAccount, AccountPage, PlanCatalogue, PlanEditor, AuditLog}.php
    ├── Livewire/Overview.php                       # body replaced
    ├── Livewire/OperatorNotificationBell.php       # + open(), markAllRead()
    ├── Views/livewire/{claim-queue, claim-review, find-account, account-page, plan-catalogue, plan-editor, audit-log, overview}.blade.php
    ├── Shell/OperatorPresenceComposer.php          # + four navigation entries (R-1)
    ├── Lang/{en,ar}/{claims, accounts, plans, audit, overview}.php (+ shell, notifications additions)
    └── routes.php                                  # + 7 routes

app/Modules/Billing/
├── Domain/Plans/{PlanEdit.php, PlanChangeSet.php}  # pure validation (R-5)
├── Domain/Refusals/{PlanEditInvalid, NothingToSave, PlanChangedElsewhere, PlanNotRetirable,
│                    SubscriptionNotExtendable, SubscriptionNotRevocable, AccountNotActive}.php
├── Domain/Ports/{PlanRepository (+all, updateIfVersion, retireIfVersion), ClaimRepository (+page, awaitingSummary, countSubmittedForPlan), SubscriptionRepository (+figures)}.php
├── Application/
│   ├── Claims/ClaimQuery.php                       # + page, awaitingSummary, reviewState; detail + matchesCurrentPrice
│   ├── Claims/{ClaimFilter, ClaimPage, ClaimReviewState}.php
│   ├── Subscriptions/{AccessQuery, SubscriptionView, AccessView}.php
│   ├── Subscriptions/OperatorSubscriptions.php     # + channel & account-state refusals (R-7)
│   ├── Plans/{PlanAdminQuery, PlanAdminView, PlanAdministration}.php
│   └── Figures/{BillingFigures, BillingFigureSet}.php
├── Infrastructure/Repositories/*                   # implement the new port methods
└── Infrastructure/Database/Seeders/BillingSeeder.php   # create-missing-only (R-6)

app/Modules/Identity/
├── Application/Operators/{AccountDirectory, AccountFacts}.php
├── Application/Audit/{AuditQuery, AuditFilter, AuditCursor, AuditPage, AuditRow}.php
├── Application/Figures/{AccountFigures, AccountFigureSet}.php
├── Application/Accounts.php                        # + isActive()
├── Domain/Audit/SubjectType.php                    # + Plan
├── Domain/Ports/{AuditStore (+page), UserRepository (+figures), SessionRepository (+activeCountFor)}.php
└── Infrastructure/Database/Migrations/2026_10_10_100000_allow_plan_audit_subject.php   # CHECK + 2 indexes

app/Modules/Wallets/Application/Membership/{MembershipCounts, MemberCounts}.php (+ repository methods)

docs/security-baseline.md, docs/glossary.md, docs/privacy-commitments.md, specs/briefs/README.md

tests/Unit/{Admin/AnonymityFloorTest, Billing/PlanEditTest, Identity/AuditFilterTest, Identity/AuditCursorTest}.php
tests/Feature/Admin/{ClaimQueueTest, ClaimReviewTest, AccountLookupTest, AccountPageTest, AccessActionsTest,
                     PlanCatalogueTest, PlanEditorTest, AuditLogTest, OverviewTest, NotificationReadMarksTest,
                     RecentFactorGateTest, BackOfficeLanguageTest, AdminRouteSurfaceTest (updated)}.php
tests/Feature/Billing/{PlanAdministrationTest, OperatorSubscriptionsChannelTest, BillingSeederTest, ClaimQueryPageTest, AccessQueryTest, BillingFiguresTest}.php
tests/Feature/Identity/{AccountDirectoryTest, AuditQueryTest, AccountFiguresTest}.php
tests/Feature/Wallets/MembershipCountsTest.php
```

**Structure Decision**: screens and orchestration in Admin (P-18 rule 3); each read and rule in the module that owns the data (P-02). Admin's thin Application services (`AccountLookup`, `ClaimReading`, `AuditReader`, `PlanChanges`) exist so that "read + record" and "change + record" are each one transaction tested without a browser.

## Design notes

1. **Component pattern**: `mount()` loads through Admin/other Application services and stores arrays of scalars; actions follow R-4 (dialog fields → `confirmX()` validates → `requireRecentFactor('applyX', [...])` → protected `applyX()` → service → refusal map → toast → refresh). Every view renders `<x-admin::factor-prompt />` when it has an action.
2. **Claim queue**: query string `?status=submitted|approved|rejected|expired&q=&after=` (`#[Url]`), 25 per page; the search term is classified: contains `@` → address (spend budget, `ClaimAddresses::normaliseAndLookup` via `ClaimQuery`), else reference (`ClaimReference::normalise`). Badge count from `ClaimQuery::awaitingSummary()`.
3. **Claim review**: `mount($claim)` → `ClaimReading::open()` (one `claim_address_read`, then `ClaimQuery::detail`); reviewer names via `OperatorDirectory::displayNames`; actions call `ClaimService` with `$this->operatorId()`; after an action, `ClaimQuery::reviewState()` refreshes facts (no address re-read, R-3).
4. **Accounts**: `FindAccount` → `AccountLookup::find($operatorId, $term)` → redirect to `admin.accounts.show` with the typed address flashed (session, one request) when found by address; `AccountPage::mount($user)` → `AccountLookup::view($operatorId, $userId)` writes `account_viewed` and returns `AccountFacts` + `MemberCounts` + `AccessView` + entitlement summary as arrays. Grant/extend/revoke dialogs call `OperatorSubscriptions`, then reload `AccessQuery` and the summary (no second `account_viewed`: the facts are not re-read through `AccountLookup`, only access).
5. **Plans**: catalogue from `PlanAdminQuery::all()`; editor holds a draft array + `version`; *Review & save* runs `PlanEdit` validation through `PlanAdminQuery::preview()` (no write) to list changes and counts; confirm → factor → `PlanChanges::update()`; `PlanChangedElsewhere` → message + reload button.
6. **Audit log**: filter form (`#[Url]` for shareable links); `apply()`/`older()`/`newer()` each call `AuditReader::read()` (one `audit_read`); row expansion is client-side (Alpine) over already-loaded detail — no request, no entry.
7. **Overview**: `OverviewFigures::compute()` returns floored values; `Needs attention` exact.
8. **Bell**: `open($id)` marks read then follows the item's link (if any); `markAllRead()`.
9. **Refusal mapping**: each component has a `match` from refusal class to `admin::<file>.errors.<key>`; unknown exceptions propagate (500 page) — never swallowed.
10. **Logging**: no explicit logging added (FR-062); the existing request logger and `RedactSecrets` apply.

## Assumed defaults (owner away)

| # | Decision | Why |
|---|---|---|
| A-1 | Eight screens/routes as in R-1; four sidebar entries; no Subscriptions index, Operators page or plan creation | Brief scope; boards' extra entries need owner decisions |
| A-2 | Every operator may do everything; authorization re-checked per request | MVP owner decision; P-18 rule 7 |
| A-3 | Fresh second factor for every state change, including access and plan changes | Conservative; extends spec 020's rule |
| A-4 | Lookup by exact address or id only; 30 lookups / 10 min / operator | Prevents browsing and enumeration |
| A-5 | Account page shows state, verification, created, plan, entitlements, subscriptions and four counts; never names, amounts, notes, devices or the stored address | P-18 rule 8; baseline; paused user-granted-support decision |
| A-6 | `account_viewed` per opening; `account_lookup_missed` per miss (no address) | Recorded reads; misses visible without leaking the term |
| A-7 | Grant manual only (30/90/365/custom 1–3,650/lifetime) to active accounts; extend manual with an end only; revoke manual or instapay, never google_play | ADR-0013; Play is authoritative; brief says "manual subscriptions" |
| A-8 | Channel and account rules enforced in Billing commands | P-18 rule 6; holds for CLI and tests |
| A-9 | Plan edit: names, order, InstaPay price, capability values; retire; never code, period, default flag, Play prices | ADR-0013 rules 1, 6; P-09 rule 5; B10 owns Play |
| A-10 | Price limits 100–10,000,000 minor units; quotas 1–1,000 or unlimited; names 1–40 chars | Sane bounds; refuse fat-finger errors |
| A-11 | Optimistic version on plan saves | No lost updates without cross-request locks |
| A-12 | `plan_updated` / `plan_retired` carry changed field names + reason, never values | Baseline never-contain list (amounts) |
| A-13 | Seeder create-missing-only (adds new plans and new capability keys only) | Deploys must not undo operator edits |
| A-14 | Audit log: 50/page keyset, filters incl. ids and date range ≤ 400 days, default 7 days; one `audit_read` per query, subject = reading operator, detail = filters | Baseline; deterministic paging |
| A-15 | Overview: counts only, anonymity floor 10, computed per load; no money, MAU, churn | Paused decision; P-18 rule 8 |
| A-16 | Read marks per operator, 7-day unread window kept, not audited | Spec 018 deferral; UI state, not an act |
| A-17 | Claim queue address search allowed (exact), not audited, spends lookup budget | Support needs it; list carries no address |
| A-18 | Privacy commitments gain an explicit operator-visibility promise (en/ar) | Public page would otherwise contradict counts |
| A-19 | No dependency, API, OpenAPI or event change | Quality Gate 6; scope |

## Complexity Tracking

No constitution violation. One deliberate trade-off is recorded:

| Choice | Why needed | Simpler alternative rejected because |
|---|---|---|
| Admin Application orchestration services (`AccountLookup`, `ClaimReading`, `AuditReader`, `PlanChanges`) between components and other modules' services | "Read/act + record" must be one transaction with the record written by Admin (baseline: Admin owns `admin_action` and `audit_read`) and testable without Livewire | Doing it in components (rejected: logic untestable without a browser, transaction scope in Http); having Billing/Identity write `admin_action` (rejected: one writer per event) |

## Implementation notes

Decisions taken while implementing (owner away; each is consistent with the spec, plan and research).

- **N-1 Icons.** The contract names icons `receipt`, `users`, `layers`, `shield`. `IconRegistry` already holds the equivalent glyphs as `claims`, `users`, `plans`, `audit`; the navigation uses those rather than adding duplicates.
- **N-2 Migrations applied to dev.** Both migrations (`2026_10_10_100000`, `2026_10_10_100001`) are additive and were applied to the dev database with `dev migrate --force` once their tests passed.
- **N-3 `OperatorRepository::findMany`.** `OperatorDirectory::displayNames` needs one query for many operators, so the Admin port gained `findMany(list<id>)`.
- **N-4 Arabic privacy wording needs owner review.** The new promise's Arabic text in `docs/privacy-commitments.md` (and the reworded "no other user outside it" line) was written without a native review. Flagged for the owner.
- **N-5 US1 repository and query shapes.** `ClaimRepository::page()` takes the instant as a last argument (`DateTimeImmutable $now`) so the submitted tab lists only unexpired claims, matching the badge count from `awaitingSummary()`; the cursor is the id of the last claim seen (resolved to `(created_at, id)` in the repository, a malformed or unknown id starts from the top). `ClaimQuery::page(ClaimFilter $status, ?array $search, ?string $after, int $limit = 25)` follows the contract; `ClaimFilter` is a string-backed enum, `ClaimSummary` gained `?array $planName` (en/ar) and `ClaimDetail` gained `matchesCurrentPrice`. Plan names come from `PlanRepository::find` once per distinct plan on a page.
- **N-6 Queue search semantics.** The search applies inside the selected tab. Each query that carries an address term spends one lookup unit (apply, tab switch, older/newer, and the first load from a URL), because the budget guards enumeration by address, not user clicks; a reference search spends nothing. A malformed address matches nothing and spends nothing. Tab switch resets the cursor; "Newer" walks a history of cursors held in a public array of strings.
- **N-7 Claim review state.** The component keeps the claim facts (including the opened address) in a public array `facts` and the review facts in `review`; the address is operator-visible by design and its read was recorded once in `mount()`. The facts property cannot be named `claim` (it collides with the route parameter, which Livewire assigns to a same-named public property). Revoke composes reason first, then the factor (`revokeCode()` -> `requireReason('claim.revoke')` -> protected `askRevokeFactor` -> `requireRecentFactor('applyRevoke')`), so the proof is checked at the moment of the act, not before the reason is typed. `InvalidReason` can only be reached by a forged call because the reason prompt already enforces 1-500 characters; its message is still mapped.
- **N-8 Shared primitives touched additively.** `x-admin::tabs` items accept an optional `test` key rendered as `data-test`; `x-admin::table.pagination` renders `data-test="page-newer"` / `"page-older"` on its controls (contract hooks). No existing output changed otherwise. `reason.php` (en/ar) gained `actions.claim.revoke` for the revoke reason prompt.
- **N-9 Navigation (T010).** The four entries are registered in `OperatorPresenceComposer::workScreens()` with the registry's existing icons (see N-1); the claims badge is `ClaimQuery::awaitingSummary()['count']` or null at zero. Nav labels live in `shell.php` (`nav.claims|accounts|plans|audit`).
- Fidelity: claim queue tabs use the underline `x-admin::tabs` (not the board's pill style) because the contract names tabs and counts only; the search sits in a separate form row above the table, not in the table toolbar; the search input carries its `data-test="claim-search"` on the wrapper (the primitive puts attributes there), and the review page puts its action buttons in a header row of the page body because the page layout does not forward the shell `actions` slot.
- **N-10 Account lookup shapes.** `AccountDirectory::byAddress/byId` return `AccountFacts` (nullable `createdAt` and `deletionScheduledFor`; a purged account carries id and state only, `verified` false, 0 devices). `UserRepository::createdAt` and `SessionRepository::activeCountFor` were added; a device counts when its session is `active` and its own `expires_at` is null or in the future. A term containing `@` is an address, anything else is an id (a malformed id is a miss `by: id`). `AccountLookup::find` checks the budget first, a hit spends nothing, a miss spends one unit and writes `account_lookup_missed` in one transaction; an empty term is a field error on the screen and never reaches the service.
- **N-11 Account page state.** The component keeps `account` (facts, counts, access, plan as scalar arrays; never `user`, which the route parameter would overwrite) and a locked `userId`. The address a person was found by travels as a one-request session flash `account_found_by` holding the user id and the normalised address; `mount()` pulls it and only uses it when the user id matches, so it never reaches another account's page and a page opened by id or URL never shows an address. A spent budget renders only the wait message (`account` stays empty and every action refuses with 404). `startExtend/startRevoke/confirmExtend/confirmRevoke` refuse (404) a subscription id that is not one of the page's own subscriptions, so the screen cannot act on another person's access; the service rules (channel, revoked, account state) still answer with the refusal toast for listed rows.
- **N-12 Billing additions beyond the contract.** `AccessQuery::grantablePlans()` (active, non-default plans with both names, no code or price) feeds the grant dialog because Admin may not read Billing's plan repository; `EntitlementService::forget($userId)` drops the per-request memo so the page shows the new governing plan right after a grant in the same request; `OperatorSubscriptions::MAX_DAYS = 3650`. Check order in `extend`: reason, days, not found, revoked, not extendable (non-manual or no end), account not active. In `revoke`, `google_play` is refused before the already-revoked no-op. The three new refusals use error code `VERSION_CONFLICT` with detail keys `billing.refusals.account_not_active`, `.subscription_not_extendable`, `.subscription_not_revocable` (en and ar in `lang/*/billing.php`). `OperatorActionsTest` now uses a real active user (R-7 behaviour change); the old extend-a-lifetime branch is gone since a lifetime subscription is no longer extendable.
- **N-13 Wallets counts.** `MembershipRepository::countsFor(userId)` and `countSharedWallets()` join `wallets` and `wallet_members` with both `deleted_at IS NULL`; "joined" is any live non-owner membership of a live wallet; "other members" is distinct people with a live membership in the person's live owned wallets, excluding the person.
- **N-14 Grant, extend and revoke dialogs.** Each dialog carries its own reason textarea (1-500 characters, hint "Don't include personal details") instead of the shared reason prompt, as the contract lists the reason inside each dialog; `confirmGrant/Extend/Revoke` validate (days a whole number 1-3,650, reason) and then call `requireRecentFactor('applyGrant'|'applyExtend'|'applyRevoke', [scalars])`. The duration radio uses `wire:model.live` so the custom-days field appears at once.
- Fidelity: the account page uses description lists inside cards for plan and counts (not stat tiles), puts the found-by line and the privacy note under the header row, and shows the subscriptions in the shared table primitive with per-row small secondary and danger buttons; the grant button reuses the `check` icon (the registry has no `plus`).
- **N-15 Audit log shapes.** `AuditStore::page(array $criteria, ?array $after, bool $ascending, int $limit)` takes plain arrays (UTC `from` inclusive, `until` exclusive; the Domain port may not import Application types) and returns raw rows; `AuditQuery::page($filter, $cursor, $direction = 'older', $limit = 50)` fetches `limit + 1` to know whether more exist, reverses the ascending `newer` fetch, and treats a missing cursor as the first (older) page. A cursor row always exists on the other side, so an `older` page always offers Newer and a `newer` page always offers Older. `AuditFilter` is built through `from()` only, exposes `fromUtc/untilUtc`, `toDetail()` (always `from` and `to`, other members only when given) and the vocabulary lists `events()/actorTypes()/subjectTypes()` so Admin never imports Identity's Domain enums. `InvalidAuditFilter::$fields` maps field to a reason code (`unknown`, `malformed`, `range_too_long`, `range_inverted`); the range limit is 400 days between the two dates, `range_too_long` is reported on `from`, `range_inverted` on `to`.
- **N-16 AuditReader returns a view.** `AuditReader::read()` returns `AuditReadView` (the `AuditPage` plus `operatorNames`, resolved in one `OperatorDirectory::displayNames` call) rather than a bare `AuditPage`, so the screen needs no second operator lookup. The `audit_read` is written before the query inside the same transaction, so the page a reader sees always lists its own read as the newest row; this is intended (the read is part of the log).
- **N-17 Audit screen state.** The filters are `#[Url]` form properties (`actor_type`, `actor_id`, `subject_type`, `subject_id` in the query string); a locked `applied` array holds what the visible rows were read with, so Older/Newer page through the applied filters even if the form was edited and not applied (and the form is then reset to them). Filters are only used when applied (mount, Apply, Reset); invalid filters show field errors, empty the table and write nothing. Cursors are locked strings. Row detail is expanded client-side with Alpine over detail already in the rows (one `open` map), so expansion never reaches the server. Event, actor type and subject type options use the raw vocabulary values as labels (identifiers, left-to-right); the dates use the existing date picker. The default range fills the form's From and To after the first load.
- Fidelity: the audit board's email targets, price diffs and "Export range…" are not built (FR-046); filters sit in a card above the table instead of a table toolbar; the expand toggle is a plain chevron button in a trailing column; the footer summary reads "Newest first · from → to" with the dates isolated left-to-right.
- **N-18 Plan administration shapes.** `PlanChangeSet` and `PlanEdit` live in Billing `Domain/Plans` (T048), not Application as the contract sketched, so Admin takes the change set from the Domain like it takes refusals; `PlanChangeSet::fromDraft` reads `name {en, ar}`, `sort_order`, `instapay_price {amount_minor, currency}|null` and a `features` map, keeps values as typed and leaves every rule to `PlanEdit::of($stored, $changes)`, which reports all invalid field keys together (`name.en`, `name.ar`, `sort_order`, `prices.instapay`, `features.<key>`) or `NothingToSave`; a capability key left out of the draft stays as stored, an unknown key is invalid. `NothingToSave`, `PlanChangedElsewhere` and `PlanNotRetirable` carry detail keys `billing.refusals.nothing_to_save|plan_changed_elsewhere|plan_not_retirable` (en and ar in `lang/*/billing.php`). Retiring an unknown plan is `PlanNotRetirable`; saving an unknown plan is `PlanChangedElsewhere`.
- **N-19 Edit version.** `PlanRecord::$version` (last constructor argument, default empty) is `updated_at` as `Y-m-d\TH:i:s.uP`. `updateIfVersion`/`retireIfVersion` set the new `updated_at` to `GREATEST(now, old + 1 microsecond)`, so a version always moves on even when the clock is frozen (tests) or two saves land in one tick. `retireIfVersion` also requires `active` and non-default in its WHERE; `PlanAdministration` checks those first so the refusal is `PlanNotRetirable` and a lost race is `PlanChangedElsewhere`.
- **N-20 Governed people.** `SubscriptionRepository::governedCountsByPlan(now)` counts people per plan by their governing subscription (granting by status and grace/period end; latest end wins, no end beats any end, ties by creation then id) in one `DISTINCT ON (user_id)` query. The default plan governs everyone without a granting subscription, which Billing cannot count without an Identity total, so `PlanAdminView::$governedPeople` is `null` for it and the screens say "Everyone without a paid plan" (the Identity total arrives with US5's figures if wanted). The catalogue applies `AnonymityFloor` to the others; the editor's review and retire dialogs show the exact count except "Fewer than 10" for 1-9.
- **N-21 Seeder.** `BillingSeeder::run()` inserts absent codes and adds absent `Feature` keys to a stored plan (seeded value); nothing else is ever written, and `updated_at` is untouched when nothing is added. Spec 009's research note was annotated as superseded; `CatalogueSeedTest` is unchanged and green.
- **N-22 Plan editor state.** The stored plan is held in `$stored` (a property named `plan` would be overwritten by the route parameter, as in N-7) and the operator's draft in `$draft` with capability keys written with underscores (`sharing_enabled`) because a dot in a property path means nesting. Money is entered as a plain number input in minor units with a hint (the Alpine money input writes a hidden field that Livewire cannot observe), the flag rows are native checkboxes (the toggle primitive has the same hidden-field limit), and the lang keys for capabilities are `plans.capabilities.<underscored key>`. The unsaved bar and the review rows are computed on render from `$stored` against `$draft` (before/after text exists only in the response); `PlanAdminQuery::preview` runs the Billing rules on review and again inside `applySave`. A stale save keeps the draft, shows the alert with Reload and writes nothing; Reload re-reads the plan and resets the draft.
- Fidelity: the editor has no board to copy; it stacks Details, InstaPay price and Capabilities cards above a sticky unsaved bar, and the catalogue uses one description-list card per plan with an Edit button in the card's top row.

- **N-23 Overview figures (US5).** `UserRepository::figures()` and `SubscriptionRepository::figures()` back `AccountFigures` and `BillingFigures`. Totals exclude purged accounts; new accounts count by `created_at` over 7 and 30 days. "Granting" means `active` or `grace` with `COALESCE(grace_until, current_period_end)` null or in the future, on a non-default plan, distinct people per provider (a person on two channels is counted in each). *In grace* counts subscriptions in `grace` whose grace has not ended. `ReenrolmentQuery::openCount()` counts open, unexpired requests of every operator. The tiles show the floor marker as the text "fewer than 10" (the stat-tile's own *suppressed* form shows a lock and a different hint, so it is not used). The oldest claim's age renders as "oldest 3 days ago" through the relative-time primitive with a long threshold.
- **N-24 Notification read marks (US6).** `OperatorNotificationStore::latest/countSince` were replaced by `latestFor/unreadCountFor/markRead/markAllRead` (the old methods had no other caller). Marks are `INSERT ... SELECT ... ON CONFLICT DO NOTHING`, so an unknown id is ignored. `OperatorNotificationView` gained a `read` flag; the shared `notification-list` primitive gained a `live` prop (the Livewire bell sets it) that adds the per-item *Open account* button and *Mark all read*, leaving the gallery's static sample untouched. The bell acts only on ids it lists. Following an item redirects to the account page, which is an ordinary recorded, budgeted opening.
- **N-25 Sign-in test.** `SignInTest` asserted the landing page shows "Overview"; the page title is now "Platform overview" (contract), so the assertion was updated.
- **N-26 Baseline paragraph placement.** `AuditVocabularyTest` parses the first 2,000 characters of "What is recorded" for backticked names, so the new `admin_action` actions paragraph sits after the Rules list, not before it.
- **N-27 Browser validation (T066) run.** Run on 2026-10-05 in Playwright against the dev app with throwaway operators (report in `test-reports/validation-20261005-123900.md`). It found and fixed four defects: the select primitive never delivered its value to Livewire (`$el` was the clicked option), a grant with no plan chosen answered 500, the account page showed raw capability keys, and the claim tabs overflowed at 390 px. Steps 7, 9, 17, 22 (second operator) and 23 (keyboard only) were not run in the browser; Livewire tests cover them.
- **N-28 Arabic wording for owner review.** Besides the privacy promise (N-4), the Arabic strings in `Lang/ar/{claims,accounts,plans,audit,overview}.php` and the bell additions were written without a native review.
- Fidelity: see `checklists/fidelity.md`.
