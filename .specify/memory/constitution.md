<!--
Sync Impact Report (latest first)

v1.3.9 → 1.3.10 — 2026-10-02
- PATCH: P-07, P-08 and P-11 list ADR-0021, introduced by spec 017; no rule changes.
- Reason: spec 017 (B14a, Sync Follow-ups); ADR-0021 extends ADR-0008 and ADR-0017.

v1.3.8 → 1.3.9 — 2026-10-02
- PATCH: the Sharing row lists two events introduced by spec 016 (`InvitationSent`, `MemberRoleChanged`); no rule changes.
- Reason: spec 016 (B9, Sharing & Invitations).

v1.3.7 → 1.3.8 — 2026-10-06
- PATCH: P-12 lists ADR-0019, introduced by spec 015; no rule changes.
- Reason: spec 015 (B8, Budgets); ADR-0019 extends ADR-0011 and ADR-0014.

v1.3.6 → 1.3.7 — 2026-10-05
- PATCH: P-05, P-06, P-07, P-12 list ADR-0018, introduced by spec 014; no rule changes.
- Reason: spec 014 (B7, Recurring Transactions); ADR-0018 extends ADR-0008 and ADR-0011.

v1.3.5 → 1.3.6 — 2026-10-04
- PATCH: P-07 and P-08 list ADR-0016 and ADR-0017, introduced by spec 013; no rule changes.
- Reason: spec 013 (B14, Sync Protocol); ADR-0016 supersedes ADR-0008 rule 7 only; ADR-0017 extends ADR-0008 and ADR-0014.

v1.3.4 → 1.3.5 — 2026-10-03
- PATCH: P-04 and P-07 list ADR-0015, introduced by spec 012; no rule changes.
- Reason: spec 012 (B6, Transactions Ledger); ADR-0015 extends ADR-0008 and ADR-0012.

v1.3.3 → 1.3.4 — 2026-10-02
- PATCH: P-01 and P-04 list ADR-0014, introduced by spec 011; no rule changes.
- Reason: spec 011 (B5, Categories); ADR-0014 extends ADR-0012.

v1.3.2 → 1.3.3 — 2026-10-02
- PATCH: the Wallets row lists two events introduced by spec 010; no rule changes.
  Publishes gains `WalletDeleted` and `WalletOwnershipTransferred`.
- Reason: spec 010 (B4, Wallets & Membership).

v1.3.1 → 1.3.2 — 2026-09-28
- PATCH: ADR-0013 supersedes ADR-0007, adding the `manual` subscription channel
  (operator grants, redeem codes; never a payment) and recording that the free
  tier is the absence of a governing subscription. P-09's and P-10's ADR lines
  point to ADR-0013. No rule text changes.
- Reason: spec 009 (B3), owner decision Q1 = A.

v1.3.0 → 1.3.1 — 2026-09-26
- PATCH: the Identity row lists three events introduced by spec 007;
  no rule changes. Publishes gains `UserSignedIn`, `DeviceSessionEnded` and
  `UserDeletionCancelled`.
- Reason: spec 007 (Identity & Authentication) publishes these events for the
  sign-in, session-end and cancel-by-sign-in flows; the table must name them.
- Templates: none changed. In-flight plans keep the version they were checked
  against, so spec 007's plan still cites v1.3.0.

v1.2.0 → 1.3.0 — 2026-09-25
- MINOR: new principle P-18 Delivery surfaces (non-negotiable). The product is
  API-first; the admin back office is the only server-rendered interface and is
  built with Livewire. Eight rules fix where components live, how they reach data,
  what their public state may hold, how they authorize, and that they never show
  an individual user's amounts, balances or notes.
- Bounded Contexts table: the Admin row names Livewire and P-18.
- Reason: owner decision to build the admin dashboard in this repository with
  Livewire while keeping the project API-first. Livewire 4 is already a direct
  dependency, so no approval is needed.
- Consistency: rule 8 restates the security baseline's protected-attributes table,
  which grants operators no read on amounts, balances or notes; it also keeps open
  the paused operator-data-boundary decision (user-granted support windows).
- Templates: none changed. The plan template renders one Constitution Check row per
  Active principle, so plans gain a P-18 row automatically.
- Follow-ups: docs/engineering-conventions.md and the architecture gates should
  cover app/Modules/Admin/Http/Livewire (rule 3) and the no-models rule (rule 5);
  docs/glossary.md's Admin entry should name the back office. Both belong to the
  admin dashboard feature.

v1.1.0 → 1.2.0 — 2026-09-25
- MINOR: P-16 rule 1 materially tightened — static analysis at Larastan level 8,
  was level 7. Quality Gates item 1 updated to match, and now names the
  architecture gates alongside formatting, analysis and the suite.
- Reason: owner decision in spec 005 (engineering conventions), taken before any
  application code exists so nothing needs retrofitting. The configuration
  change (phpstan.neon) lands in the same commit, so the two never disagree.
- Scope note: application code is analysed at level 8. tests/ is not analysed,
  because PHPStan cannot see Pest's closure binding without an extension that
  has not been approved; see specs/005-engineering-conventions/research.md R-5.
- Templates: no change needed; the plan template's Constitution Check shape is
  unchanged.
- Follow-up F-1 (architecture tests for P-02, P-03, P-09) is closed by spec 005.

v1.0.0 → 1.1.0 — 2026-09-20
- MINOR: P-04 materially expanded — new rule 2, "every posting MUST reference exactly one
  of a wallet or a category", renumbering the rules that follow.
- P-01 and P-04 now cite ADR-0012, which supersedes ADR-0004. ADR-0004 keeps its body and
  its status line points forward.
- Reason: the prior-art data model (docs/legacy/DATA_MODEL.md) modelled the counter-leg of a
  posting as a category rather than a nominal income/expense account; that form was adopted,
  so the backing record changed. No principle was removed or redefined.
- Templates: no change needed (.specify/templates/plan-template.md already renders one row
  per Active principle).

v1.0.0 — 2026-09-19 (ratified)
- Principles added: P-01 … P-17 (all new; 13 non-negotiable)
- Sections added: Purpose & Precedence, Core Principles, Bounded Contexts & Modules,
  MVP Scope Guard, Quality Gates, Constitution Check, Governance
- Backing records: docs/adr/0001 … 0011 (all Accepted 2026-09-19)
- Templates updated:
  - .specify/templates/plan-template.md ✅ Constitution Check table shape
  - .specify/templates/spec-template.md ✅ no change needed (no constitution references)
  - .specify/templates/tasks-template.md ✅ no change needed
- Follow-up features (decided here, built later; see specs/001-architecture-constitution-adrs/plan.md):
  - F-1 architecture tests enforcing P-02, P-03, P-09
  - F-2 PostgreSQL for dev/test/CI (.env.example, phpunit.xml, .github/workflows/tests.yml)
  - F-3 observability middleware, JSON log channel, error-tracker SDK (needs dependency approval)
  - F-4 shared kernel (Money, Currency, clock)
-->

# Save Me Constitution

<!-- "Save Me" is a working codename used as display text only; see P-13. -->

**Version**: 1.3.10 | **Ratified**: 2026-09-19 | **Last Amended**: 2026-10-02

## Purpose & Precedence

This constitution governs every feature plan, implementation and review in this backend repository.

1. It **states the rule**. The Architecture Decision Records in [`docs/adr/`](../../docs/adr/README.md) carry the **reasoning** behind each rule. Where the two overlap, this document wins; where they conflict, that is a defect to fix in the same change.
2. It **overrides** conflicting guidance in any other document, including `CLAUDE.md`, `AGENTS.md`, older plans and agent habits. It does not override the law, Google Play policy, or an explicit instruction from the repository owner.
3. Every feature plan MUST complete a [Constitution Check](#constitution-check) before design and again after design.
4. A rule that cannot be checked as yes or no is a defect in this document. Report it and amend.

## Core Principles

Each principle has a stable ID that is never reused or renumbered. **Non-negotiable** principles encode locked product decisions: a plan that fails one is blocked until either the plan changes or the constitution is amended.

### P-01 Ubiquitous language

**Status**: Active | **Non-negotiable**: yes | **ADRs**: [ADR-0012](../../docs/adr/0012-postings-wallet-and-category-accounts.md) (supersedes ADR-0004), [ADR-0014](../../docs/adr/0014-platform-owned-categories.md) (extends ADR-0012)

1. "Wallet" (محفظة) MUST be the only term for a money container, in code, database, API and translations. "Account", "purse", "fund" and similar MUST NOT be introduced as synonyms.
2. Wallet type MUST be a fixed enum whose members carry defined behavioural semantics (e.g. liability types hold negative balances).
3. Domain terms MUST match this vocabulary in namespaces, table names and API fields.

*Rationale*: one word per concept removes an entire class of translation, modelling and support confusion, and the Android app and landing page share this vocabulary.

### P-02 Module boundaries

**Status**: Active | **Non-negotiable**: yes | **ADRs**: —

1. Code MUST live in `app/Modules/<Context>/{Domain,Application,Infrastructure,Http}`.
2. Layer dependencies MUST flow Http → Application → Domain, and Infrastructure → Domain. `Domain` MUST NOT import `Illuminate\Database` or any Eloquent model.
3. A module MUST NOT reference another module's `Infrastructure` (which includes its Eloquent models) or `Http` namespace.
4. Cross-module interaction MUST use exactly one of: a call to the other module's `Application` service, or a domain event.
5. `app/Modules/Shared` MUST contain only persistence-free value objects and primitives. It MUST NOT contain Eloquent models, migrations or repositories.

*Rationale*: the whole point of a modular monolith is that boundaries survive contact with deadlines. Written as namespace rules, they are checkable by an automated test (F-1) instead of by memory.

### P-03 Money

**Status**: Active | **Non-negotiable**: yes | **ADRs**: [ADR-0003](../../docs/adr/0003-money-minor-units.md)

1. Money MUST be represented as an integer amount in minor units plus an ISO 4217 alpha-3 currency code, through the shared `Money` value object.
2. `float`, `double` and `decimal` column types, and decimal strings, MUST NOT be used for monetary values anywhere: storage, API payloads or arithmetic.
3. Arithmetic MUST be between equal currencies only.
4. Splitting an amount MUST use `Money::allocate()`, so the parts sum exactly to the original.

*Rationale*: floats lose money silently and the loss is discovered by users, not tests.

### P-04 Ledger integrity

**Status**: Active | **Non-negotiable**: yes | **ADRs**: [ADR-0012](../../docs/adr/0012-postings-wallet-and-category-accounts.md) (supersedes ADR-0004), [ADR-0014](../../docs/adr/0014-platform-owned-categories.md) (extends ADR-0012), [ADR-0015](../../docs/adr/0015-transfers-on-two-wallet-feeds.md) (extends ADR-0008 and ADR-0012)

1. Every transaction MUST produce postings that sum to zero per currency.
2. Every posting MUST reference exactly one of a wallet or a category, never both and never neither.
3. A transfer MUST be one transaction with two wallet postings, never two linked transactions.
4. Liabilities MUST be stored negative and presented as an amount owed.
5. In MVP all postings of one transaction MUST share a single currency; cross-currency transactions MUST be rejected.
6. Postings MUST NOT be exposed through the API or the sync protocol.

*Rationale*: a single-entry UX over a double-entry ledger gives transfers, debts and eventual multi-currency for free, and makes every balance a sum that can be recomputed.

### P-05 Derived balances

**Status**: Active | **Non-negotiable**: yes | **ADRs**: [ADR-0005](../../docs/adr/0005-derived-balance-with-cache.md), [ADR-0018](../../docs/adr/0018-occurrences-materialised-by-the-server.md) (extends ADR-0008 and ADR-0011)

1. A wallet's balance MUST be derived as opening balance + Σ postings.
2. The cached balance column MUST be recomputed within the same database transaction that changes postings.
3. The cache MUST NOT be read for invariants, authorization decisions, report totals or reconciliation.
4. A write MUST NOT be rejected because the resulting balance is negative. Negative balances are surfaced by presentation.

*Rationale*: a cache that is allowed to be wrong is survivable; a cache that is treated as truth turns one bad write into permanently wrong books.

### P-06 Identifiers

**Status**: Active | **Non-negotiable**: yes | **ADRs**: [ADR-0002](../../docs/adr/0002-identifiers-uuidv7.md), [ADR-0018](../../docs/adr/0018-occurrences-materialised-by-the-server.md) (extends ADR-0008 and ADR-0011)

1. Primary keys MUST be UUIDv7.
2. Syncable entities MUST accept a client-generated ID, so create is an idempotent `PUT /…/{id}` upsert.
3. A `PUT` whose ID exists in another scope or for another entity type MUST respond 404 and MUST NOT overwrite or disclose the existing record.
4. The timestamp embedded in a UUID MUST NOT be used for ordering, sync cursors, business dates or authorization.

*Rationale*: offline-first clients must be able to create records without a round trip, and retries must be safe.

### P-07 Sync and concurrency

**Status**: Active | **Non-negotiable**: yes | **ADRs**: [ADR-0008](../../docs/adr/0008-sync-changes-feed.md), [ADR-0015](../../docs/adr/0015-transfers-on-two-wallet-feeds.md) (extends ADR-0008), [ADR-0016](../../docs/adr/0016-batch-upload-beside-per-record-writes.md) (supersedes ADR-0008 rule 7), [ADR-0017](../../docs/adr/0017-membership-on-the-members-user-feed.md) (extends ADR-0008), [ADR-0018](../../docs/adr/0018-occurrences-materialised-by-the-server.md) (extends ADR-0008 and ADR-0011), [ADR-0021](../../docs/adr/0021-scope-heads-and-member-name-restamps.md) (extends ADR-0008 and ADR-0017)

1. Every syncable entity MUST carry `version`, `updated_at`, `deleted_at` and `change_seq`.
2. Every write MUST carry the client's last-seen `version`.
3. A stale version MUST reject the whole write with HTTP 409 and error code `VERSION_CONFLICT`, returning the current entity.
4. The server MUST NOT merge conflicting writes field by field. Merging is the client's job.
5. Deletion MUST be a tombstone (`deleted_at`), never a hard delete of a syncable entity.

*Rationale*: predictable, explainable conflict behaviour beats a clever merge the user cannot reason about, and a rejected write is recoverable where a silently lost edit is not.

### P-08 Authorization

**Status**: Active | **Non-negotiable**: yes | **ADRs**: [ADR-0006](../../docs/adr/0006-wallet-scoped-authorization.md), [ADR-0017](../../docs/adr/0017-membership-on-the-members-user-feed.md), [ADR-0021](../../docs/adr/0021-scope-heads-and-member-name-restamps.md) (extends ADR-0008 and ADR-0017)

1. Authorization MUST be wallet-scoped: the caller's `wallet_members.role` combined with entitlement checks.
2. A write MUST be authorized against every wallet it touches.
3. A non-member MUST receive 404 for a wallet-scoped resource, so existence is not disclosed.
4. The only global role is platform admin, which MUST use a separate guard and separate accounts. Application users MUST NOT carry a role or admin flag.
5. The permission matrix MUST live only in the Wallets spec, and MUST NOT be duplicated here, in an ADR, or in code comments.

*Rationale*: sharing is the product's social surface and its main security boundary; one matrix in one place is the only way it stays correct.

### P-09 Entitlements

**Status**: Active | **Non-negotiable**: yes | **ADRs**: [ADR-0013](../../docs/adr/0013-entitlements-with-manual-channel.md) (supersedes ADR-0007)

1. Plans MUST be data: a period plus feature flags and quotas.
2. Every capability check MUST go through `EntitlementService` against a `Feature` enum value.
3. No code outside the Billing module's entitlement resolution MUST compare a plan code.
4. A shared wallet MUST be governed by its owner's entitlements.
5. The free tier MUST be limited only on sharing.
6. Losing an entitlement MUST NOT delete user data.

*Rationale*: pricing changes more often than code should. Plan-code comparisons scattered through call sites make every pricing experiment a deployment.

### P-10 Billing channels

**Status**: Active | **Non-negotiable**: yes | **ADRs**: [ADR-0013](../../docs/adr/0013-entitlements-with-manual-channel.md) (supersedes ADR-0007)

1. In-app purchases MUST use Google Play Billing.
2. The in-app API surface MUST expose only "restore purchases" and "enter code".
3. InstaPay claims MUST be submitted only through the website, never from inside the app.

*Rationale*: Google Play policy forbids alternative payment inside the app. Breaking this removes the app from the store, which is not a recoverable mistake.

### P-11 Events

**Status**: Active | **Non-negotiable**: no | **ADRs**: [ADR-0009](../../docs/adr/0009-domain-events-after-commit.md), [ADR-0021](../../docs/adr/0021-scope-heads-and-member-name-restamps.md) (extends ADR-0008 and ADR-0017)

1. Domain events MUST be dispatched only after the database transaction commits.
2. Cross-module listeners MUST be queued and MUST be idempotent.
3. Events MUST carry identifiers and scalars, never Eloquent models.

*Rationale*: a listener that runs inside the transaction can act on data that never existed, and one that is not idempotent turns an ordinary retry into a duplicate.

### P-12 Time

**Status**: Active | **Non-negotiable**: yes | **ADRs**: [ADR-0011](../../docs/adr/0011-time-and-timezones.md), [ADR-0018](../../docs/adr/0018-occurrences-materialised-by-the-server.md) (extends ADR-0008 and ADR-0011), [ADR-0019](../../docs/adr/0019-budget-spending-by-author-computed-on-read.md) (extends ADR-0011 and ADR-0014)

1. Instants MUST be stored in UTC.
2. Calendar dates (transaction date, period start, recurring anchor) MUST be stored as `YYYY-MM-DD` with no timezone.
3. The user's timezone MUST be applied only in read models and reports, never at write time.
4. Period membership MUST be decided by calendar date, so members of a shared wallet in different timezones see identical totals.
5. Changing a user's timezone MUST NOT rewrite stored values.

*Rationale*: Egypt has added and removed DST more than once. Anything other than UTC storage plus calendar dates eventually reports the wrong month to somebody.

### P-13 Naming

**Status**: Active | **Non-negotiable**: yes | **ADRs**: —

1. The product codename MUST appear only in configuration values and translation files.
2. It MUST NOT appear in namespaces, class names, table names, column names, package identifiers or route names.

*Rationale*: the commercial name is undecided. Renaming a marketing string is trivial; renaming a namespace across a backend, an Android app and a landing page is not.

### P-14 Localisation

**Status**: Active | **Non-negotiable**: yes | **ADRs**: —

1. All user-facing text MUST be available in Arabic and English through translation files, never hardcoded.
2. API responses MUST use Western numerals (0–9) regardless of locale.
3. Content MUST NOT assume text direction; RTL correctness is a client concern that the API MUST NOT obstruct (no pre-formatted directional strings, no embedded layout markup).

*Rationale*: Arabic is the primary market language, and Western numerals are what Egyptian users actually expect to see in financial figures.

### P-15 Observability

**Status**: Active | **Non-negotiable**: no | **ADRs**: [ADR-0010](../../docs/adr/0010-observability.md)

1. Logs MUST be structured JSON.
2. Every response MUST carry `X-Request-Id`.
3. A client-supplied correlation ID MUST be validated, then propagated to logs, queued jobs and listeners.
4. Secrets, tokens, purchase tokens, receipt images and full financial payloads MUST NOT be logged.

*Rationale*: a manual-entry app with offline sync produces user reports like "my transfer vanished". Without a correlation ID joining app and server, that is unanswerable.

### P-16 Quality gates

**Status**: Active | **Non-negotiable**: no | **ADRs**: [ADR-0001](../../docs/adr/0001-database-engine.md)

1. `composer test` (Pint, Larastan level 8, Pest) MUST pass before merge.
2. The test suite MUST run on the production database engine (PostgreSQL), not a substitute.
3. Every invariant a feature introduces MUST have a test that fails when the invariant is broken.
4. The plan's Constitution Check MUST be complete.

*Rationale*: invariants such as zero-sum postings and version conflicts are exactly the rules that erode quietly without tests, and engine differences (locking, `timestamptz`) hide behind SQLite.

### P-17 Scope guard

**Status**: Active | **Non-negotiable**: yes | **ADRs**: —

1. A plan MUST NOT introduce a capability listed in [MVP Scope Guard](#mvp-scope-guard) without amending this constitution first.

*Rationale*: this project's risk is not building the wrong thing, it is building too many things before shipping the first one.

### P-18 Delivery surfaces

**Status**: Active | **Non-negotiable**: yes | **ADRs**: [ADR-0006](../../docs/adr/0006-wallet-scoped-authorization.md)

1. The product MUST be API-first: every capability a user can reach MUST exist as a JSON endpoint under `/api/v1` before any client uses it. The Android app and the website are clients of that API. A user-facing capability MUST NOT exist only in a server-rendered page.
2. The admin back office is the only server-rendered interface in this repository. It MUST be built with Livewire and Blade, and a second interface stack (a JavaScript SPA framework, Inertia, or similar) MUST NOT be introduced without amending this principle.
3. Livewire components MUST live in `app/Modules/Admin/Http/Livewire`, and their views MUST be registered from the Admin module rather than placed in the shared `resources/views` tree, so the P-02 gates cover the back office like any other module.
4. Back-office routes MUST sit under `/admin`, behind the separate admin guard and its second factor (P-08 rule 4). An application credential MUST NOT reach any back-office route, including Livewire's own update endpoint when it serves a back-office component.
5. A component MUST obtain data only through other modules' `Application` query services, and its public properties MUST hold only scalars and DTOs — never Eloquent models — because Livewire serialises public properties to the browser and trusts them on the way back.
6. A component action that changes state MUST call an application service. It MUST NOT bind a form to a model or write through one, so back-office changes run the same invariants, authorization and events as app actions.
7. A component MUST authorize on every request it handles, not only when the page first loads.
8. A back-office screen MUST NOT display an individual user's amounts, balances or notes. The security baseline grants operators no read on those attributes; a way for a user to grant support access is added by a later amendment, not by a screen.

*Rationale*: the Android app is the product and the API is its contract, so a capability built first as a page is a capability the app does not have. The back office is a different audience with a different threat model, and Livewire keeps it in PHP, inside the module boundaries, without a second build pipeline. Its specific risks — serialised public state, model binding, authorization checked only on page load — are exactly where a back office becomes a softer way into user data, so they are closed here rather than left to each screen.

## Bounded Contexts & Modules

Nine contexts, one module directory each. Module directories use short PascalCase names because `&` cannot appear in a PHP namespace.

| Context | Module dir | Owns | Publishes |
|---|---|---|---|
| Identity | `app/Modules/Identity` | users, credentials, sessions/tokens, profile, preferences (locale, timezone), account deletion and export orchestration | `AuthService`, `ProfileService`, `UserQueryService`; `UserRegistered`, `UserDeletionRequested`, `UserSignedIn`, `DeviceSessionEnded`, `UserDeletionCancelled` |
| Wallets & Ledger | `app/Modules/Wallets` | wallets, wallet members, categories, transactions, postings, recurring rules | `WalletService`, `TransactionService`, `MembershipQueryService`, `BalanceQueryService`; `TransactionPosted`, `TransactionVoided`, `WalletCreated`, `WalletDeleted`, `WalletOwnershipTransferred` |
| Budgeting | `app/Modules/Budgeting` | budgets per category per period, budget progress read models | `BudgetService`, `BudgetQueryService`; `BudgetExceeded` |
| Sharing | `app/Modules/Sharing` | invitations, invitation acceptance and revocation flows | `InvitationService`; `InvitationSent`, `InvitationAccepted`, `MemberRevoked`, `MemberRoleChanged` |
| Plans & Billing | `app/Modules/Billing` | plans, subscriptions, Google Play purchases, InstaPay claims, redemption codes | `EntitlementService`, `SubscriptionService`, `ClaimService`; `SubscriptionActivated`, `SubscriptionLapsed` |
| Sync | `app/Modules/Sync` | change feeds, cursors, conflict handling, tombstone retention | `ChangeFeedService`, `UpsertService` |
| Reporting | `app/Modules/Reporting` | report read models and projections | `ReportQueryService` |
| Notifications | `app/Modules/Notifications` | devices, push tokens, notification dispatch and preferences | `DeviceService`, `NotificationService` |
| Admin | `app/Modules/Admin` | admin accounts and guard, back-office screens (Livewire, P-18), audit of admin actions | `AdminAuditService` |

**Shared kernel** — `app/Modules/Shared` holds `Money`, `Currency`, identifier helpers, the clock abstraction and the domain-event base class. It has no persistence, no migrations and no dependency on any other module. Every module may depend on it.

**Layer rule** — Http → Application → Domain; Infrastructure → Domain. Eloquent models live in `Infrastructure` and are private to their module.

**Cross-module rule** — only two mechanisms: calling another module's `Application` service, or handling its domain event. Reaching into another module's `Infrastructure` or `Http` is a P-02 violation regardless of convenience.

**Reporting and Admin** — both need data owned by other modules, and both get it the same way: through those modules' `Application`-layer **query services**, which return DTOs and read models. Reporting MAY additionally maintain its own projections fed by domain events when a query service becomes too slow; those projections are owned by Reporting. Admin MUST write only through other modules' application services, so the same invariants, authorization and events apply to back-office actions as to app actions. There is no read-only exception for direct table or model access.

## MVP Scope Guard

The following are out of scope for MVP. Introducing any of them requires a constitution amendment (P-17), not just a plan:

- bank synchronisation or SMS parsing
- multi-currency transfers and FX
- savings goals
- bill reminders beyond recurring occurrences
- AI features (assisted entry, "talk to your data")
- iOS-specific work
- web checkout with a registered payment provider
- refunds automation
- dark mode (a client concern)

## Quality Gates

A change is mergeable when all of the following hold:

1. `composer test` passes: `vendor/bin/pint --test` (formatting), `phpstan analyse` at level 8 (Larastan), the architecture gates, and the Pest suite.
2. The Pest suite runs against PostgreSQL (from follow-up F-2 onward; until then, record the gap in the plan).
3. Each invariant the feature introduces has a test that fails when the invariant is broken — at minimum: zero-sum postings, version conflict, authorization denial, and entitlement denial where the feature touches them.
4. Feature tests cover the HTTP contract of every new endpoint, including its error codes.
5. The plan contains a complete Constitution Check for both gates.
6. No new dependency without the repository owner's approval.

## Constitution Check

Every `plan.md` includes this section, filled in before Phase 0 and again after Phase 1 design.

```markdown
## Constitution Check

*Constitution v1.3.10. GATE: before Phase 0 and again after Phase 1.*

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | … |
| … one row per Active principle … | | |
```

Rules:

1. Result is `PASS`, `FAIL` or `N-A`. `N-A` requires a one-line reason (e.g. "handles no money").
2. A `FAIL` on a non-negotiable principle **blocks** the plan. Change the plan, or amend the constitution first.
3. A `FAIL` on any other principle requires a row in the plan's Complexity Tracking table justifying it.
4. Evidence cites concrete plan elements — module, entity, endpoint — not a restatement of the rule.
5. Both the pre-design and post-design results are kept in the plan.

## Governance

**Precedence** — this document overrides conflicting practice elsewhere in the repository (see [Purpose & Precedence](#purpose--precedence)).

**Amendments** — an amendment is a pull request that changes this file and states, in its description: what changes, why, which ADR backs it, and the version bump. The repository owner ratifies. In-flight plans keep the version they were checked against; they are not retroactively invalidated.

**Versioning** — semantic:

| Bump | When |
|---|---|
| MAJOR | a principle is retired or its meaning is redefined |
| MINOR | a principle is added, or an existing one is materially expanded |
| PATCH | wording, formatting, typo, clarification with no change in meaning |

Every amendment updates the footer's **Last Amended** date and the Sync Impact Report comment at the top of this file.

**Principle IDs** — `P-NN` IDs are permanent. A retired principle keeps its ID with `**Status**: Retired (vX.Y.Z)` and a note pointing to what replaced it. IDs are never reused or renumbered, so an old plan's Constitution Check stays readable.

**Decision records** — an Accepted ADR is never rewritten. Changing a decision means writing a new ADR that supersedes it and updating the affected principle's `**ADRs**:` line in the same change. See [the ADR index](../../docs/adr/README.md) for the mechanics.

**Consistency** — run `/speckit-analyze` before implementing each feature. Contradictions between this constitution, the ADRs and a feature's spec are defects to resolve before code.

**Version**: 1.3.10 | **Ratified**: 2026-09-19 | **Last Amended**: 2026-10-02
