# Research: Architecture Constitution & ADR Set

**Feature**: 001-architecture-constitution-adrs | **Date**: 2026-09-15

Each section gives Decision / Rationale / Alternatives considered. ADR authors copy these into the ADR's Decision, Context and Alternatives sections and expand the Consequences. Laravel facts were checked against the Laravel 13.x docs (Boost `search-docs`).

---

## R-0: Traceability (brief → constitution → ADR)

| Locked decision (brief) | Principle | ADR |
|---|---|---|
| "Wallet" is the only term; wallet type is a fixed enum | P-01 Ubiquitous language | 004 |
| Balance derived; cache never source of truth | P-05 Derived balances | 005 |
| Double-entry postings under single-entry UX | P-04 Ledger integrity | 004 |
| Negative balances allowed, never blocked | P-05 | 005 |
| Liabilities stored negative, shown as owed | P-04 | 004 |
| Integer minor units + ISO 4217 | P-03 Money | 003 |
| UUIDv7, client IDs, idempotent `PUT /{id}` | P-06 Identifiers | 002 |
| `version`/`updated_at`/`deleted_at`, 409 `VERSION_CONFLICT` | P-07 Sync & concurrency | 008 |
| Plans are data; only `EntitlementService` | P-09 Entitlements | 007 |
| Free tier limited only on sharing; owner's entitlements govern | P-09 | 007 |
| Play Billing in app, InstaPay claims on web only | P-10 Billing channels | 007 |
| Wallet-scoped authz; matrix only in Wallets spec | P-08 Authorization | 006 |
| UTC storage; tz in read models; `YYYY-MM-DD` dates | P-12 Time | 011 |
| Codename in config/translations only | P-13 Naming | — (constitution only) |
| Arabic/English, RTL, Western numerals | P-14 Localisation | — (constitution only) |
| Modular monolith, module boundaries | P-02 Module boundaries | — (constitution only; enforced by F-1) |
| After-commit events, no outbox | P-11 Events | 009 |
| JSON logs, request/correlation IDs | P-15 Observability | 010 |
| PostgreSQL | P-16 Quality gates (tests on prod engine) | 001 |
| MVP out-of-scope list | P-17 Scope guard | — |

---

## R-1: Document format and location

- **Decision**: ADRs in `docs/adr/NNNN-kebab-title.md` using a lightweight MADR-style layout ([contracts/adr-template.md](./contracts/adr-template.md)), with an index in `docs/adr/README.md`. The constitution replaces `.specify/memory/constitution.md`, where spec-kit commands read it. Principles are numbered `P-01…` and are never renumbered: removed principles are marked "Retired", not deleted.
- **Rationale**: spec-kit's `/speckit-plan` and `/speckit-analyze` load the constitution from `.specify/memory/`. Numbered ADRs in `docs/` are the common convention and render on GitHub. Stable principle IDs keep old plans' Constitution Checks traceable.
- **Alternatives**: ADRs inside `.specify/memory/adr/` (hidden, tied to one tool); a single decisions document (one file cannot supersede one decision at a time); full MADR 4 with all optional sections (too much ceremony for a solo/duo team).

## R-2: Bounded contexts → module directories

- **Decision**: `app/Modules/{Identity, Wallets, Budgeting, Sharing, Billing, Sync, Reporting, Notifications, Admin}` plus `app/Modules/Shared` (shared kernel). Each module has `Domain/` (entities, value objects, domain events, repository interfaces, with no framework imports except `Illuminate\Support` collections), `Application/` (use-case services and query services, the only public API to other modules), `Infrastructure/` (Eloquent models, repository implementations, provider clients, listeners) and `Http/` (controllers, form requests, API resources, routes).
- **Dependency rule**: Http → Application → Domain; Infrastructure → Domain (implements interfaces). A module may depend on another module's `Application` classes and `Domain\Events`, and never on its `Infrastructure` or `Http`. Every module may depend on `Shared`.
- **Rationale**: This matches the layering the user named. Eloquent models live in Infrastructure, so "never another module's Eloquent models" becomes a namespace rule that Pest `arch()` can check (F-1).
- **Alternatives**: Laravel default flat `app/Models` (no boundaries); separate Composer packages per module (heavy for MVP); `nwidart/laravel-modules` (a new dependency, and it imposes its own structure).
- **Ownership notes**: categories are owned by Wallets & Ledger; recurring rules by Wallets & Ledger; invitations by Sharing (membership records themselves by Wallets, since authorization reads them); plans, subscriptions, Play purchases and InstaPay claims by Billing; devices and push tokens by Notifications; exports and account deletion are orchestrated by Identity, which calls each module's application service.

## R-3: Reporting and Admin cross-module reads

- **Decision**: Both use other modules' Application-layer **query services**, which return DTOs/read models. Reporting may keep its own event-fed projections when query services become too slow. Admin writes only through other modules' application services, so the same invariants and events apply.
- **Rationale**: This keeps FR-006 absolute with no exception to police.
- **Alternatives**: a read-only DB-view exception for Reporting (creates schema coupling that is invisible to arch tests); Admin editing Eloquent models directly (bypasses invariants and domain events).

## R-4 (ADR-001): Database engine

- **Decision**: PostgreSQL 16+ in all environments, including the automated test suite.
- **Rationale**: native `uuid` type; `timestamptz`; transactional DDL; `SELECT … FOR UPDATE` row locks (Laravel `lockForUpdate`) for version checks and balance recomputation; partial indexes (`WHERE deleted_at IS NULL`); `jsonb` for plan features; `CHECK` constraints for invariants (e.g. currency code length); offered by Laravel Cloud and every major managed host.
- **Alternatives**: MySQL 8 (no native uuid, which forces `BINARY(16)`/`CHAR(36)`; no partial indexes; weaker CHECK history), SQLite (writer lock blocks concurrent shared-wallet writes; not a production host target; a test-only SQLite hides engine differences such as locking and `timestamptz`).
- **Consequences to record**: local and CI need a Postgres service (F-2); tests use `RefreshDatabase` on Postgres, which is slower than in-memory SQLite and is mitigated by parallel test databases (Laravel suffixes them per process).

## R-5 (ADR-002): Identifiers

- **Decision**: All primary keys are UUIDv7 in native `uuid` columns. Server-created entities use Laravel's `HasUuids`, which generates UUIDv7 by default in Laravel 13. Syncable entities accept a client-generated UUID in the URL (`PUT /…/{id}`), which is an upsert: if the ID doesn't exist, create; if it exists and belongs to the same entity type and the caller is authorized, it is an update requiring `version`; otherwise respond **404** (another wallet/owner) and never overwrite or reveal. The server validates version-7 format, and rejects other versions with 422.
- **Rationale**: offline creation without ID remapping; idempotent retries; index-friendly time ordering.
- **Clock skew rule**: the timestamp embedded in a UUID is never used for ordering, cursors, business dates or authorization. Sync order comes from the server counter (R-11).
- **Alternatives**: bigint auto-increment plus a client temp-ID map (remapping complexity offline); ULID (non-standard column type in Postgres, 26-char string); UUIDv4 (random inserts fragment B-tree indexes).

## R-6 (ADR-003): Money

- **Decision**: `Money` value object in `app/Modules/Shared/Domain`: `int $amountMinor` (signed, 64-bit) + `Currency $currency` (ISO 4217 alpha-3 plus minor-unit exponent from a bundled table: EGP/SAR/AED = 2, KWD/BHD/OMR = 3). Storage: `bigint amount_minor` + `char(3) currency`. JSON: `{"amount_minor": 12345, "currency": "EGP"}`. Arithmetic is only between equal currencies (otherwise `CurrencyMismatch`). Splitting (installments, gam'eya shares) uses `allocate(ratios)` with largest-remainder so the parts sum exactly to the total. No rounding happens in MVP because there is no FX, division or interest. Negative amounts are allowed.
- **Forbidden**: `float`, `decimal` columns, decimal strings in payloads, `/` on amounts outside `allocate`. Enforced by F-1 arch tests plus Larastan.
- **Alternatives**: `decimal(19,4)` columns (strings in PHP, risk of float casts); `moneyphp/money` (new dependency; its string amounts clash with the integer-JSON contract); floats (rejected outright).

## R-7 (ADR-004): Ledger model — resolves O1

- **Decision**: **Accepted: transactions with postings.** A `Transaction` is the user-facing, syncable record (type: income / expense / transfer; date `YYYY-MM-DD`; category; note; amount). Postings are server-derived rows `(transaction_id, account, amount_minor, currency)`, and **the postings of each transaction sum to zero per currency**. Accounts are either a wallet or a system nominal account: `income`, `expense`, `opening`/`adjustment` if needed.
  - Expense 500 from Cash: `Cash −500`, `expense +500`.
  - Income 3000 to Bank: `Bank +3000`, `income −3000`.
  - Transfer 1000 Bank→Cash: `Bank −1000`, `Cash +1000` (one transaction, two wallet postings).
  - Credit-card spend: the card wallet (liability type) goes more negative; paying the card is a transfer.
  - Debt owed to a friend: a liability-type wallet, stored negative.
  - Future FX: a transfer with four postings through an `fx` account, balanced per currency. The per-currency zero-sum rule already allows it; MVP rejects mixed currency.
- **Edits/deletes**: the client edits the Transaction; the server replaces its postings atomically in the same DB transaction and bumps the version. Delete = tombstone the transaction and remove its postings from balance sums (postings are hard-deleted or excluded; they are never synced). Postings are internal and never exposed through the API.
- **Rationale**: transfers, liabilities and future multi-currency need no special cases; balance is always a sum; one balancing invariant is testable.
- **Alternatives**: two-column income/expense on the transaction (transfers become two linked rows that can drift; liabilities and FX need special cases); postings exposed to the client (leaks accounting vocabulary into the UX and complicates sync).

## R-8 (ADR-005): Balance

- **Decision**: truth = `wallets.opening_balance_minor + Σ postings.amount_minor WHERE account = wallet`. `wallets.balance_minor` is a cache, updated in the same DB transaction as the posting change, while holding `lockForUpdate` on the wallet row (the same lock that serialises the sync counter, R-11). No invariant, authorization, report total or reconciliation reads the cache. A scheduled `wallets:verify-balances` job recomputes, logs drift at `warning` with the wallet id, and repairs it. Negative results are never rejected.
- **Alternatives**: no cache, always sum (fine now, degrades list screens later); an event-driven async cache (stale reads right after a write, which is confusing for the user who just posted); balance snapshots per period (premature).

## R-9 (ADR-006): Authorization

- **Decision**: Laravel policies per module, resolving the caller's `wallet_members.role` (owner/editor/viewer) through the Wallets application service, then checking entitlements via `EntitlementService` where a capability is plan-gated. The permission matrix itself is referenced from the Wallets spec, never copied. A transaction write authorizes **every** wallet it posts to. A non-member gets **404** for any wallet-scoped resource. Platform admins use a separate `admin` guard and admin accounts owned by the Admin module; there is no role column on app users.
- **Alternatives**: global RBAC package (e.g. spatie/laravel-permission, which is global rather than wallet-scoped, plus a new dependency); 403 for non-members (discloses existence); `users.is_admin` flag (mixes a global role into app users, and one leaked token becomes admin).

## R-10 (ADR-007): Entitlements

- **Decision**: `plans` (code, period, `features jsonb` of flags and quotas; data, not code) and `subscriptions` (user, plan, provider ∈ {google_play, instapay}, provider_ref, status, current_period_start/end, grace_until). Billing normalises Play RTDN/purchase verification and approved InstaPay claims into the same subscription record. `EntitlementService::forUser($userId)->allows(Feature::…)` / `->quota(Feature::…)`; `forWallet($walletId)` resolves the owner first. `Feature` is an enum of capabilities, never plan codes. Free tier = the plan row with sharing disabled or limited; every other feature is enabled. Lapse/downgrade: no data is deleted; plan-gated sharing actions (invite, add member) are blocked; the effect on existing members is defined by the Sharing spec. Entitlement results may be cached per request only.
- **Billing channels**: the app API exposes only restore-purchases and redeem-code; InstaPay claim submission endpoints are web-only (authenticated from the landing page flow).
- **Alternatives**: plan code checks (`if plan === 'pro'`); a Laravel Cashier-style billable per provider (provider-specific, no InstaPay); feature flags on users (loses period/source history).

## R-11 (ADR-008): Sync

- **Decision**:
  - **Scopes**: one feed per wallet (wallet-owned entities: transactions, categories if wallet-scoped, budgets, recurring rules, members) and one feed per user (profile, preferences, devices, membership grants/revocations, user-scoped categories).
  - **Cursor**: each scope row (`wallets`, `users`) holds `change_seq bigint`. Every write to an entity in that scope locks the scope row (`lockForUpdate`), increments `change_seq`, and stamps the entity's `change_seq`. Pull = `WHERE scope_id = ? AND change_seq > :cursor ORDER BY change_seq LIMIT n`, including tombstones. This makes the cursor gap-safe in commit order, because writers in one scope are serialised by the lock.
  - **Push**: per-entity `PUT /{id}` with `version` (client's last-seen). Mismatch → **409 `VERSION_CONFLICT`** with the current entity (including a tombstone), and the whole write is rejected with no server merge. The client merges (it may apply field-level LWW locally) and resends with the new version. Replaying an identical write whose result is already stored is idempotent (same response).
  - **Tombstones**: `deleted_at` set, `version` and `change_seq` bumped; row retained **≥ 90 days**, then purged. A cursor older than the scope's purge horizon → **410 `SYNC_CURSOR_EXPIRED`**, and the client does a full resync of that scope.
  - **Undelete**: an explicit write with `deleted_at: null` plus the current version; authorised like an update.
  - **Membership removal**: a revocation appears in the user feed; the client purges that wallet locally; further pulls on that wallet → 404.
  - **Guarantees**: ordered within a scope, at-least-once, idempotent replays; no cross-scope ordering.
- **Rationale**: the per-scope row lock is already taken for the balance cache (R-8). A global Postgres sequence is not commit-ordered: a transaction that starts earlier can commit later with a lower number, and a client whose cursor has already passed that number never sees the row.
- **Alternatives**: global sequence / `updated_at` cursor (skips rows under concurrent commits; clock-dependent); CRDTs (heavy for couples with low contention); server-side field merge (rejected in clarification Q2); field-level silent LWW (loses edits, contradicts brief).

## R-12 (ADR-009): Events

- **Decision**: domain events are plain classes in `Modules/<X>/Domain/Events`, carrying IDs and scalars (not Eloquent models) and an `event_id` (UUIDv7) plus `occurred_at`. They implement `ShouldDispatchAfterCommit`, so a rolled-back transaction discards them. Listeners in other modules implement `ShouldQueue`; queue connections set `after_commit => true`. Listeners must be idempotent, keyed on `event_id` where a side effect is not naturally idempotent. Queue: database driver on PostgreSQL for MVP.
- **No outbox (MVP)**: accepted risk = process crash between commit and enqueue loses the event. Mitigations: listeners that maintain derived state have reconciliation jobs (balance verify, recurring catch-up, projection rebuild); user-visible side effects (push) are tolerable to miss. **Revisit trigger**: any event whose loss causes money/billing inconsistency or an external integration that needs a guaranteed delivery (e.g. entitlement grant from a Play RTDN handled asynchronously), or observed loss in logs.
- **Alternatives**: transactional outbox table + relay (correct, more moving parts); synchronous in-transaction listeners across modules (couples modules and lengthens locks); a broker (Kafka/SQS) (infrastructure the MVP doesn't need).

## R-13 (ADR-010): Observability

- **Decision**:
  - JSON log lines (Monolog `JsonFormatter` via the channel `formatter` option) to stderr in production.
  - Middleware assigns a server `request_id` (UUIDv7) and returns it as `X-Request-Id`.
  - A client `X-Correlation-Id` is accepted if it is ≤ 128 chars of `[A-Za-z0-9._:-]`, otherwise replaced by the request ID. Both go into Laravel `Context`, which appends them to every log entry and carries them into queued jobs and listeners.
  - Authenticated `user_id` added to context.
  - Error tracking through a Sentry-compatible SDK, adopted with dependency approval in F-3.
  - Never logged: passwords, tokens, OTPs, Play purchase tokens, InstaPay receipt images, full request bodies of financial writes (IDs and amounts at debug level only).
  - Retention: 30 days of application logs.
- **Alternatives**: plain-text line logs (hard to query); OpenTelemetry tracing now (valuable later, too much setup for MVP); no correlation ID (can't join app and server events).

## R-14 (ADR-011): Time

- **Decision**:
  - `APP_TIMEZONE=UTC`; DB session timezone UTC; instant columns `timestampTz` (precision 6).
  - Calendar dates (transaction date, budget period start, recurring anchor date) are `date` columns and `YYYY-MM-DD` in JSON, with no timezone.
  - API instants are ISO 8601 with `Z`.
  - `users.timezone` is an IANA name.
  - **Period membership is by calendar date**: a transaction dated `2026-09-30` belongs to September for every member regardless of timezone, so shared-wallet budgets are identical for all members.
  - The timezone only decides "today" and the current period for a viewer, report range defaults, and when recurring occurrences become due. A recurring rule stores the IANA timezone captured at creation, so its schedule doesn't shift when a member elsewhere views it.
  - Changing the user's timezone rewrites nothing.
- **Alternatives**: store local time with an offset (ambiguous around DST; Egypt's DST has been re-introduced and removed before); store transaction date as an instant (day flips across timezones on shared wallets); per-wallet timezone (a surprise for couples in different places; the recurring rule timezone covers the real need).

## R-15: Quality gates and governance

- **Decision**: merge requires `composer test` green (Pint check, Larastan level 7, Pest on PostgreSQL once F-2 lands), a feature test for each invariant the plan introduces, and the plan's Constitution Check table complete. Versioning: MAJOR = principle retired or redefined; MINOR = principle added or materially expanded; PATCH = wording. The repository owner ratifies. An ADR changes only by being superseded. `/speckit-analyze` is run before implementation of each feature.
- **Alternatives**: no versioning (plans can't cite which rules they obeyed); an ADR per principle (duplicates the constitution).

## R-16: Automatable principles (SC-007)

| Principle | Mechanism (F-1) |
|---|---|
| P-02 Module boundaries | Pest `arch()`: `App\Modules\X` must not use `App\Modules\Y\Infrastructure` or `App\Modules\Y\Http`; `Domain` must not use `Illuminate\Database` |
| P-03 No floats in money | Pest `arch()` + Larastan: no `float` in `Shared\Domain\Money`; grep gate for `->decimal(` / `->float(` / `->double(` in `database/migrations` |
| P-09 No plan-code comparison | Pest `arch()`: only `Billing\Application\EntitlementService` may reference `Plan::code`; grep gate for plan code literals outside `database/seeders` |
