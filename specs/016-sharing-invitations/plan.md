# Implementation Plan: Sharing & Invitations

**Branch**: `016-sharing-invitations` (trunk-based; work lands on `main`) | **Date**: 2026-10-02 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/016-sharing-invitations/spec.md`.

## Summary

Create the Sharing module (`app/Modules/Sharing`, the constitution's fourth context) with the wallet-scoped, syncable **Invitation**; extend Wallets & Ledger with a **MembershipService** (grant, role change, removal, leave, plan demotion) and the member-visible **Activity Entry**.

- **Invitations** (R-1 to R-7, R-14): `PUT` upsert with a client UUIDv7, immutable, two channels (email mail / one-time `share_url`), 7-day single-use tokens hashed like Identity's links, address sealed under the inviter's key with a lookup value and erased on close, supersede per address, hourly expiry sweep, wallet-feed rows, deletion participant, purge step.
- **Token operations** (R-3, R-4, R-8): `POST /invitations/preview|accept|decline` with the token in the body; email invitations match by lookup value; any mismatch or dead token is `404`; open to unverified accounts.
- **Membership changes** (R-9): Wallets' `MembershipService`, every write stamping `change_seq` (wallet counter, wallet last) and `member_change_seq` (ADR-0017), one audit entry each, one activity entry each; Sharing's `MemberService` orchestrates authorization (rows S-03, S-04, S-05) and entitlements.
- **Quota** (R-7): owner + active members + live pending invitations ≤ `sharing.max_members_per_wallet`, checked under the wallet lock on create and accept; `403 FEATURE_NOT_ENTITLED` with the new optional `Problem.feature`.
- **Downgrade** (R-11): queued idempotent listeners on `SubscriptionLapsed` and `WalletOwnershipTransferred` demote editors beyond the quota; never remove, never promote.
- **Activity** (R-12): `wallet_activity`, written by the Wallets services and Sharing inside each write; `GET /wallets/{id}/activity`; 90-day prune.
- **API**: eleven operations in `docs/openapi/paths/sharing.yaml` (written, indexed, examples, `info.version` v1.9 in this phase), `sync.yaml` `invitation` rows, `Problem.feature`; frozen for C9. No new error code.
- **Docs** (T001–T003): glossary (Activity Entry, Invitation notes, Wallet Member notes), spec 010 §6 matrix amendment with `PermissionMatrix` code, ADR-0020, constitution PATCH v1.3.9, security baseline (gate rows, route budgets, protected attributes, Uniform answers row), privacy commitments, api-conventions (batch types, `Problem.feature`), briefs B15/B16 "Inherited from B9" notes.

## Technical Context

**Language/Version**: PHP 8.5, Laravel 13.

**Primary Dependencies**: none new (Laravel Mail, queues, `RateLimiter`; Spectator for contracts). R-17.

**Storage**: PostgreSQL 16. New `invitations` (Sharing), `wallet_activity` (Wallets). Partial unique indexes and CHECKs per data-model.md.

**Testing**: Pest.
- Unit: `InvitationRules` (immutability, status machine, close erases address), `MemberQuota` (count, create/accept arithmetic, null = unlimited), `DemotionPlan` (ranking, editors beyond quota, never promotes), `InvitationToken` (shape), `PermissionMatrix` (amended rows S-02, S-03, S-06, S-07).
- Feature: send (replay, supersede, uniform job, mail budget silent, rate limit counts creates only), accept (match, mismatch, unverified, already member, retry, concurrent, quota race, expiry instant, deleted wallet), decline, revoke (plan lapsed), lifecycle (sweep, deletion cascade, purge), membership changes (role, removal, leave, owner rules, stamps, audit, lock order), downgrade (lapse, transfer, idempotent, grace no-op), activity recording across every writer (replay writes none; nothing protected stored), prune.
- Contract: eleven operations with every documented status (Spectator `assertValidRequest()` / `assertValidResponse($status)`); verification gate per route; feed rows (`invitation`, membership tombstone on user feed then 404).
- Arch: existing gates (Sharing is already named in `ModuleBoundariesTest`).

**Target Platform**: the Linux server (the `dev` container).

**Project Type**: modular-monolith JSON API.

**Performance Goals**: SC-008 — invitation create, accept and membership writes p95 ≤ 300 ms; invitation list and activity page (50 of 10,000) p95 ≤ 300 ms (Xdebug off).

**Constraints**: P-02 (Sharing reaches Wallets, Identity and Billing only through their Application layers; Wallets never imports Sharing), P-06, P-07, P-08 (matrix amended only in spec 010 §6), P-09 rules 4 and 6, P-11, P-14 (mail strings ar/en), P-15 rule 4 (no token or address in any log), ADR-0008 rules 3–5 and 15, ADR-0017 rules 2–5, security baseline (uniform answers, lookup value, hashes).

**Scale/Scope**: 11 operations (8 path entries); 2 migrations; no seeder (no reference data); ~40 Sharing classes; Wallets: `MembershipService`, `WalletScopeWrites`, `ActivityLog` port + repository, `ActivityQueryService`, `WalletActivity`, repository additions, activity calls in five services; Identity: `InvitationAddresses` application service; Billing: `FeatureNotEntitled` carries an optional feature; Sync: second wallet-feed source, purge steps; ~24 test files.

## Constitution Check

*Constitution v1.3.8 (`.specify/memory/constitution.md`). GATE: before Phase 0 and again after Phase 1.*

### Pre-design

| Principle | Result | Evidence / justification |
|---|---|---|
| P-01 Ubiquitous language | PASS | "Invitation", "Wallet Member", "Wallet"; new "Activity Entry" added to the glossary (T002) before any code; "seat", "collaborator", "invite link" not used as entity names (glossary bans the synonyms) |
| P-02 Module boundaries | PASS | Sharing code in `app/Modules/Sharing`; memberships written only through Wallets' Application `MembershipService`; invitation stamps through Wallets' `WalletScopeWrites`; activity through Wallets' `WalletActivity`; addresses through Identity's `InvitationAddresses`; entitlements through Billing's `EntitlementService`; Sync composes Sharing's Application feed/purge |
| P-03 Money | N-A | Handles no money; activity and mail carry no amount |
| P-04 Ledger integrity | N-A | No posting written or exposed |
| P-05 Derived balances | N-A | No balance read or written; preview shows no balance |
| P-06 Identifiers | PASS | `PUT /wallets/{id}/invitations/{iid}` with client UUIDv7; foreign or other-type id → 404; activity and membership ids server UUIDv7 |
| P-07 Sync and concurrency | PASS | Invitation carries the five sync members + `wallet_id`; every client write of a record the client holds carries `version` (create 0, revoke, role change, removal, leave); 409 with `current`; tombstones for every close and for removal/leave; no merge. Accept and decline carry no `version` deliberately: the caller is not a member and holds no copy of the invitation (it never reaches their feeds), the only transition possible is pending → closed, which kills the token, so a stale write is already refused by the token check (404) — the same shape as Identity's `verify-email`; the membership accept creates is server-written (spec 010) |
| P-08 Authorization | PASS | Rows S-01…S-07 of spec 010 §6, amended there (T003); non-member 404; token operations authorized by token + address match; owner's entitlements for S-02/S-03 and accept |
| P-09 Entitlements | PASS | `sharing.enabled` and `sharing.max_members_per_wallet` through `EntitlementService::forWallet`; lapse demotes, never deletes or removes (rule 6); revoke, removal and lowering roles never gated |
| P-10 Billing channels | N-A | No billing surface |
| P-11 Events | PASS | Four Sharing events after commit, ids and scalars; listeners on `SubscriptionLapsed` and `WalletOwnershipTransferred` queued and idempotent |
| P-12 Time | PASS | `expires_at`, `occurred_at` UTC instants; no calendar logic |
| P-13 Naming | PASS | No codename; mail uses `config('app.name')` from translation files |
| P-14 Localisation | PASS | Mail subjects and bodies in `lang/{ar,en}` under the Sharing namespace; no new problem string (no new code) |
| P-15 Observability | PASS | One log line per create, accept, sweep, enforcement run with ids only; tokens and addresses never logged (SC-009 test) |
| P-16 Quality gates | PASS | Every §4 invariant names its test; PostgreSQL partial indexes and `FOR UPDATE` |
| P-17 Scope guard | PASS | No guard item touched (no push, no AI, no FX) |
| P-18 Delivery surfaces | PASS | All under `/api/v1`; no back-office screen |

### Post-design re-check

Unchanged: all rows PASS or N-A.

- **CONTRIBUTING item 18**: ADR-0020 (T002) records the Activity Entry beside the audit log: it **extends** ADR-0008 (a member-visible read that is deliberately not a syncable entity or a third scope) and the security baseline's audit section (the audit log stays operator-only); it contradicts no rule. The plan-demotion rule fills the gap ADR-0013 rule 10 delegates to this spec — no ADR needed. Constitution PATCH v1.3.9: the Sharing row publishes `InvitationSent` and `MemberRoleChanged`; P-08's ADRs line is unchanged.
- **Spec 010 amendment** (T003): matrix rows S-02 (send only), S-03 (entitlement when raising to editor), new S-06 (revoke) and S-07 (read activity). The matrix stays in exactly one place (P-08 rule 5); `PermissionMatrix` and `tests/Unit/Wallets/PermissionMatrixTest.php` change in the same task.
- No new error code; `info.version` v1.9.

Complexity Tracking stays empty.

## Project Structure

### Documentation (this feature)

```text
specs/016-sharing-invitations/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/sharing-api.md
├── checklists/requirements.md
├── checklists/sharing.md        # /speckit-checklist
└── tasks.md                     # /speckit-tasks
```

### Source Code (repository root)

```text
app/Modules/Sharing/
├── Domain/          # InvitationRecord, InvitationStatus, InvitationChannel, InvitedRole, InvitationRules,
│                    # MemberQuota, DemotionPlan, Ports/{InvitationRepository,TokenHasher},
│                    # Events/{InvitationSent,InvitationAccepted,MemberRevoked,MemberRoleChanged}, Refusals/
├── Application/     # InvitationService (send, revoke), InvitationResponses (preview, accept, decline),
│                    # MemberService (changeRole, remove, leave), EnforceMemberQuota, ExpireInvitations,
│                    # InvitationQueryService, Jobs/SendInvitationMail, Mail/{InvitationMail…},
│                    # Listeners/{DemoteOnLapse,DemoteOnOwnershipTransfer}, InvitationsGoWithWallet,
│                    # Sync/{WalletFeedQuery,FeedPresenter,FeedRecord,TombstonePurge}, SharingServiceProvider
├── Infrastructure/  # Models/Invitation, Database/Migrations, Database/Factories/InvitationFactory,
│                    # Repositories/EloquentInvitationRepository, SharingPersistenceServiceProvider
└── Http/            # Controllers/{InvitationController,InvitationResponseController,MemberController,LeaveController},
                     # Requests/, Resources/{InvitationResource,InvitationPreviewResource}, InvitationFeedPresenter,
                     # Console/ExpireInvitations, Views/mail, Lang/{ar,en}, SharingHttpServiceProvider, routes.php
app/Modules/Wallets/Application/Membership/MembershipService.php
app/Modules/Wallets/Application/WalletScopeWrites.php                       # + ScopeWrite
app/Modules/Wallets/Application/Activity/{WalletActivity,ActivityQueryService,ActivityPrune}.php
app/Modules/Wallets/Domain/Activity/{ActivityAction,ActivitySubject,ActivityRecord}.php, Ports/ActivityLog.php
app/Modules/Wallets/Domain/Ports/MembershipRepository.php                   # + tombstone, latestFor, activeRanked, countActive, ownedLiveWalletsOf
app/Modules/Wallets/Domain/Membership/{Permission,PermissionMatrix}.php     # S-06, S-07; S-03 capability rule
app/Modules/Wallets/Infrastructure/…                                        # wallet_activity migration, repository
app/Modules/Wallets/Application/{WalletService,Transactions/TransactionService,Recurring/RecurringRuleService,
                                 Recurring/OccurrenceService,Recurring/AutoConfirmer}.php   # + activity
app/Modules/Wallets/Http/Controllers/ActivityController.php, routes.php    # GET /wallets/{id}/activity
app/Modules/Identity/Application/Sharing/InvitationAddresses.php           # seal, open, lookupFor, userIdForLookup, lookupOfUser, localeOf
app/Modules/Billing/Domain/Refusals/FeatureNotEntitled.php                 # optional Feature
app/Http/Api/Problems/ProblemRenderer.php                                  # renders `feature`
app/Modules/Sync/Application/{ChangeFeedService,TombstonePurgeRun}.php     # Sharing source; purge + prune steps
config/sharing.php; routes/console.php (hourly sweep); bootstrap/providers.php (+3)

tests/Unit/Sharing/, tests/Feature/Sharing/, tests/Contract/Sharing/,
tests/Feature/Wallets/{MembershipChangesTest,ActivityRecordingTest}.php, tests/Contract/Wallets/ListWalletActivityTest.php,
tests/Contract/Sync/{InvitationFeedTest,MembershipRevocationFeedTest}.php
```

**Structure Decision**: a new module directory named by the constitution's context table, mirroring Budgeting's layout (three providers). Membership and activity stay in Wallets & Ledger, which owns the records.

## Design notes

1. **Send** (`InvitationService::send(actorId, walletId, iid, role, ?email, version)`): route middleware `sharing.invite-budget` (after `address.verified` and the `INVALID_ID` check, before the Form Request) refuses with 429 when `RateLimiter::tooManyAttempts('sharing-invite:'.userId, 20)` without hitting, so the precedence of spec §6 holds (429 before body validation) and only applied creates count → Form Request shape → unlocked read of `iid` (foreign id / other type → 404 via `RecordIdentifiers::isTaken` + own repo) → `WalletScopeWrites::within(walletId)`: `WalletAuthorizer::authorize(S02)` (404/403/`FEATURE_NOT_ENTITLED` `sharing.enabled`) → stored invitation `FOR UPDATE`: tombstone → 409; identical → 200 replay; stale → 409; differing → `IMMUTABLE_FIELD` → quota (`MemberQuota::canInvite`, else `FEATURE_NOT_ENTITLED` `sharing.max_members_per_wallet`) → supersede same-lookup pending (close as revoked) → insert (token hash, sealed address) → activity `invited` → commit → `RateLimiter::hit`, `SendInvitationMail::dispatch(...)->afterCommit()` (email channel), `InvitationSent`. Returns record + secret (link channel only).
2. **Accept** (`InvitationResponses::accept(userId, token)`): token throttle (route) → find by hash (unlocked) → `WalletScopeWrites::within(walletId)` → re-read `FOR UPDATE` → live and `expires_at > now` else 404 → email channel: `InvitationAddresses::lookupOfUser(userId)` equals → else 404 → already active member → return it (no write) → responder is caller and status accepted → return active membership or 404 → `EntitlementService::forWallet` (`sharing.enabled`, quota with this invitation counted) → `MembershipService::grant(walletId, userId, role, scope)` (stamps, audit `membership_granted`, activity `joined`) → close invitation `accepted` (responder = caller) → commit → `InvitationAccepted`. Concurrency: the wallet lock serialises; the second acceptance of one link sees `accepted` and gets 404.
3. **Membership changes** (`MemberService` → `MembershipService`): wallet lock → `authorize(S03|S04|S05)` → target membership (active, or latest tombstone for a removal replay) → precedence per spec §6 → user stamp → write → audit → activity → final wallet stamp → event. Leave and removal of the last non-owner are ordinary.
4. **Demotion** (`EnforceMemberQuota::forWallet(walletId)`): quota = `forWallet(walletId)->quota(SharingMaxMembersPerWallet)` (null → nothing); `MembershipService::demoteBeyond(walletId, quota)`: wallet lock, `activeRanked`, `DemotionPlan::editorsBeyond(ranked, quota)`, user stamps ascending, `changeRole(viewer)`, audit `role_changed` (actor `system`, detail `{member_user_id, from: editor, to: viewer}`), activity `role_limited_by_plan` (actor null), one `MemberRoleChanged` (reason `plan`) per member. Listeners: `DemoteOnLapse` (for each `ownedLiveWalletsOf(userId)`), `DemoteOnOwnershipTransfer` — both `ShouldQueue`, idempotent by construction (re-running finds no editor beyond the quota).
5. **Activity**: `ActivityLog::record(walletId, actorId?, action, subjectType, subjectId, role?)` inserted inside the caller's transaction; `occurred_at` = the write's `now()`. Writers named in R-12. Reads by keyset on (`occurred_at` desc, `id` desc); display names only for actors in `activeMembersOf`.
6. **Feeds**: Sharing `WalletFeedQuery::page(walletId, after, limit)` returns `invitation` rows; `ChangeFeedService::walletPage` merges it after the Wallets authorization in `scope()`. Membership rows already ride both feeds.
7. **Purge and prune**: Sharing `TombstonePurge` deletes invitation tombstones older than the horizon (raising the wallet's `purged_change_seq` through Wallets' existing horizon method) and runs **before** Wallets' purge, so a deleted wallet with only invitation tombstones can go in the same run; `ActivityPrune` deletes `wallet_activity` rows older than 90 days in batches of 1,000.
8. **Mail**: `SendInvitationMail` (queued) — mail budget per lookup value (silent) → `InvitationAddresses::userIdForLookup` → registered/unregistered body; locale = invitee's profile locale or the inviter's; subject and body from `sharing::mail.*` (ar/en); sends to the opened address; never logs the address or token. Registered as a Sharing Blade namespace like Identity's `identity::mail`.
9. **Gate**: invite, revoke, role change, removal behind `address.verified`; preview, accept, decline, leave only `auth:api`; token routes also `throttle:sharing-token`.

## Assumed defaults (owner away)

| # | Decision | Why |
|---|---|---|
| A-1 | Create is `PUT /wallets/{id}/invitations/{iid}` (client UUIDv7); immutable once created | P-06 rule 2, endpoint checklist C1 (R-1) |
| A-2 | Two channels: email (mail only) and link (`meta.share_url` once) | Brief names both; one record shape (R-2) |
| A-3 | Link `{sharing.links.invitation_url}?token=<43 base64url>`; secret hashed, never retrievable | Identity's link pattern; baseline (R-2) |
| A-4 | Token operations take the token in the body | P-15 rule 4 (R-3) |
| A-5 | Preview endpoint added (wallet name, inviter name, role, expiry, `already_member`) | Invitee decides before joining; no amounts (C9) |
| A-6 | Email invitations accepted only by the matching lookup value; link by anyone signed in | Baseline matching rule (R-4) |
| A-7 | Every token problem, including mismatch, is `404 NOT_FOUND` | Discloses nothing; no new code |
| A-8 | 7-day expiry, single use; hourly sweep; acceptance checks the instant | Brief; R-14 |
| A-9 | Every close tombstones and erases the address | Minimal retention (R-5) |
| A-10 | Address sealed under the inviter's key, visible to all members while pending | Baseline at-rest rule; S-01 all roles (R-5) |
| A-11 | Re-invite supersedes the pending invitation to the same address | No double seat (R-6) |
| A-12 | Pending invitations count toward the quota; owner counts | Brief; spec 009 Free = 1 (R-7) |
| A-13 | Quota and no-sharing both `403 FEATURE_NOT_ENTITLED` + `Problem.feature` | Spec 010 precedent; no new code (R-7) |
| A-14 | Already-member acceptance is a no-op and does not consume the token | Brief "no-op" |
| A-15 | No `Idempotency-Key` on accept | Conventions: three money operations only |
| A-16 | Preview, accept, decline, leave open to unverified accounts; owner actions gated | Baseline gate rationale (R-8) |
| A-17 | Removal and leave are tombstones of the membership (glossary `revoked`) | ADR-0017 rule 4; spec 010 open question 2 |
| A-18 | Role change carries the membership version; `owner` target or owner member → `IMMUTABLE_FIELD /role` | P-07 rule 2; W-05 is the only way to owner |
| A-19 | Raising to editor needs `sharing.enabled` and room in the quota; lowering never gated | Stops re-promoting past a downgrade (spec 010 §6 amended) |
| A-20 | Revoke (new S-06) has no capability | P-09 rule 6 |
| A-21 | Owner cannot leave or remove themselves → 403 | Row S-05 |
| A-22 | Repeated leave → 404 (ADR-0008 rule 15); repeated removal → 200 tombstone | Ex-member sees nothing; owner's replay is idempotent |
| A-23 | Departing members' records all stay; no reassignment | Privacy commitments; R-15 |
| A-24 | Downgrade demotes editors beyond quota (owner, then joining order); never removes, never auto-promotes | Brief, C3, P-09 rule 6 (R-11) |
| A-25 | Demotion triggers: `SubscriptionLapsed`, `WalletOwnershipTransferred`; queued, idempotent | P-11; R-11 |
| A-26 | Pending invitations survive a downgrade but cannot be accepted while not entitled | Nothing deleted; they expire in 7 days |
| A-27 | Activity Entry is a new non-synced Wallets entity, ids only, 90 days, all members read (S-07) | Brief; audit log is operator-only (R-12, ADR-0020) |
| A-28 | Activity skips wallet create and delete and the occurrence-produced transaction | Unreadable / covered by `confirmed` |
| A-29 | Actor display name only while the actor is an active member | Privacy commitments ("your name comes off") |
| A-30 | Rate limits: 20 creates/24 h per inviter (429), 5 mails/24 h per address (silent), 30 token ops/h per person (429) | Abuse; no cross-inviter leak (R-13) |
| A-31 | Invitations not batch items | Each create sends mail or returns a secret |
| A-32 | Mail names the inviter and role only; no wallet name, no amount; locale = invitee's or inviter's | C9; P-14 |
| A-33 | No re-promotion memory, no push, no account-deletion handover | B16, B15 |
| A-34 | Four events (two new) and a constitution PATCH v1.3.9 | P-11; constitution table |
| A-35 | No seeder | No reference data |
| A-36 | Sizing: 5 members, 20 pending, 10,000 activity entries per wallet | SC-008 |

## Review checklists (applied at the polish task)

- **Endpoint review checklist**, expected exceptions:
  - C1–C3 do not apply to `previewInvitation`, `acceptInvitation`, `declineInvitation` (token operations, not creations), `changeMemberRole`, `removeMember`, `leaveWallet` (writes to a server-created record, versioned in the body), or `listWalletActivity` (not syncable).
  - D does not apply to `InvitationPreview` and `ActivityEntry` (not syncable).
  - G3/G4 do not apply (no idempotency-keyed operation). G5 applies to `upsertInvitation` and the three token operations.
  - I2 is "no" for the feature: eight path entries, one per distinct path.
- **Security review checklist**: uniform answer on create; tokens hashed, single-use, body-only; address sealed, erased on close, never logged; 404 on every mismatch; owner actions gated; rate limits; activity carries ids only.

### Results (filled at the polish task, T047)

**Endpoint review checklist** (`docs/api-conventions.md` appendix), each of the eleven operations read against the implementation and its Spectator contract test, with the exceptions listed above:

| Group | Result |
|---|---|
| A Shape | Pass. Every success is `{data, meta}`; `declineInvitation` is the one `204` (no body exists); an empty list is `200` with `data: []`. |
| B Errors | Pass. Every problem `$ref`s `components/responses.yaml`; every code is in the catalogue; **no new code**; `errors[]` carry JSON Pointers. |
| C Identifiers and writes | Pass, with the exceptions in the table above (C1-C3 on token operations, role change, removal, leave and activity). `upsertInvitation`: `PUT` with a client UUIDv7, `201` / `200`, `version` in the body, tombstone `409` with `current`, foreign id `404`, malformed id `422 INVALID_ID`. |
| D Syncable envelope | Pass. `Invitation` composes `SyncableResource` + `WalletScoped` (wallet scope, as the glossary says), tombstones ride the wallet feed. `InvitationPreview` and `ActivityEntry` are not syncable (exception above). |
| E Collections | Pass for `listInvitations` and `listWalletActivity`: cursor + limit, `x-filterable` and `x-sortable` present and empty, refusals documented and tested (`FILTER_FIELD_NOT_SUPPORTED`, `SORT_FIELD_NOT_SUPPORTED`, `INVALID_CURSOR`, `INVALID_QUERY_PARAMETER`). |
| F Values | Pass. No money member exists; instants are `date-time` UTC with microseconds and a `Z`; `_at` members are instants. |
| G Headers | Pass. `X-Request-Id` on every response; no idempotency key (G3/G4 n/a); `429` with `Retry-After` and `RateLimit-*` on `upsertInvitation` and the three token operations (G5); `426` reachable on every route (G6). |
| H Security | Pass. Bearer scheme inherited; non-member `404`, member without the role `403`, plan refusal `403 FEATURE_NOT_ENTITLED` with `feature` (distinct from role denial); every token problem is the same `404`. |
| I Hygiene | Pass. Unique verb-first `operationId`s; one tag per operation (`Sharing`; `listWalletActivity` is tagged `Wallets & Ledger`); eight path entries in `openapi.yaml`. |

**Security review checklist** (`docs/security-baseline.md` appendix):

| Group | Result |
|---|---|
| A Sessions and credentials | n/a: no session or credential is issued. A5: no endpoint returns the token hash, the address lookup value or the responder. |
| B Probing | B5 pass: the create answers identically (status, body, request-path work) whether or not the address has an account; only the queued mail differs (`UpsertInvitationTest`, `SendInvitationTest`). Every token problem, including an address mismatch, is one `404`. |
| C Verification gate | C1 pass: send, revoke, role change and removal are behind `address.verified`. C2: preview, accept, decline and leave are open, with the two baseline rows added in T001. C3: the gate's own code. |
| D Data at rest | D1 pass: the invited address (sealed under the inviter's key, lookup value beside it, erased on close), the token (hash) and the activity entries (identifiers only) have rows in the protected-attributes table (T001). D2 pass: lookup only through Identity's `InvitationAddresses`. D4: the sealer stores the key id. D5: no key in the database. |
| E Leakage | E1 pass and tested (`NoSecretsInLogsTest`): no token, address or lookup value in any log line, audit entry or activity row; the job's payload is encrypted at rest. E2: info-level lines carry counts and enums only; record ids are debug-level (see Implementation notes). E5/E6 pass: not-found for non-members, no name or value of another person in any error. |
| F Accountability | F1 pass: one `membership_granted`, `membership_revoked` or `role_changed` entry per membership act (`MembershipChangesTest`), written by Wallets; F3: details are closed classes of identifiers. F5: no new event name. F6: Identity's log is the only store. |
| G Retention | G1 pass: invitation tombstones follow the 90-day tombstone retention, activity entries 90 days (clamped, never shorter); G2 pass: the privacy commitments (en, ar) say 7 days for the address and nothing shorter than the table allows; G5 pass: a leaver's records all stay (`MembershipChangesTest`). |
| H, I | n/a / pass: no new error code, a plain-text request is refused by the shared runtime. |

Contract re-read (`docs/openapi/paths/sharing.yaml` against the implementation, member by member): no change to the frozen contract was needed. Two documented behaviours are narrower than a literal reading and are recorded as implementation notes (the entitlement is checked for a *new* invitation only; a role change that names the owner is refused before the plan and version checks).

## Analysis remediation (`/speckit-analyze`, 2026-10-02)

| ID | Severity | Finding | Fix |
|---|---|---|---|
| C1 | HIGH | P-07 rule 2 ("every write carries the last-seen version") vs versionless accept/decline | Constitution Check P-07 row states why: the caller holds no copy of the invitation, the only transition kills the token, so the token check subsumes the stale check (as `verify-email`); every write of a record the client holds carries `version` |
| I1 | MEDIUM | Plan design note 1 put the invite budget after body validation; spec §6 and `sharing.yaml` put 429 before it | Route middleware `sharing.invite-budget` checks without hitting, before validation; the service hits after an applied create (T004, T022) |
| I2 | MEDIUM | US1 #3 and `upsertInvitation` promised identical "timing", which FR-005 had replaced with a testable rule | Both now say: same status and body, same work on the request path (no account lookup); the queued job alone decides the mail |
| U1 | MEDIUM | `wallet_activity.wallet_id` FK could block the daily wallet purge on the day a wallet's last entries age out | FK `ON DELETE CASCADE` (data-model, T006) |
| G1 | MEDIUM | Leave and token operations open to unverified accounts diverge from the baseline's "any write to any wallet" row | Recorded (R-8, A-16); T001 adds the explicit gate rows in the same change |

No CRITICAL finding. Coverage: FR-001…FR-028 each map to at least one task; SC-008 → T045, SC-009 → T046, SC-007 → T023; every task maps to a story or a cross-cutting requirement.

## Implementation notes

Decisions taken while implementing (owner away), each consistent with the spec, plan, research and ADR-0020. None changes the frozen contract.

| # | Note |
|---|---|
| N-1 | **`DemotionPlan` lives in Wallets** (`Domain\Membership`), not Sharing: `MembershipService::demoteBeyond` ranks members and Wallets may not import Sharing. Its unit test is `tests/Unit/Wallets/DemotionPlanTest.php`; `MemberQuota` stays in Sharing (`tests/Unit/Sharing/MemberQuotaRulesTest.php`). |
| N-2 | **No `TokenHasher` port.** `InvitationSecret` hashes with SHA-256 itself (a pure function, nothing to swap). |
| N-3 | **`FeatureNotEntitled` carries the capability through `Refusal::extensions`**, so `ProblemRenderer` needed no change (it already renders extensions flat). `WalletAuthorizer::authorize` names the capability it checked and gained `$checkCapability` (default true) for callers that decide the capability themselves. `WalletService`'s `wallets.max` refusal now names `wallets.max` too. |
| N-4 | **The sharing capability and the quota are checked for a *new* invitation only** (the contract says "a new invitation while the owner's plan lacks sharing"). A replay or a stale write of an earlier create by an owner whose plan lapsed is answered as usual (`200` / `409`), and revoking is never gated. Spec 010's generic "entitlement before replay" precedence is read as applying to creating. |
| N-5 | **A role change that names the owner** (as target or as the new role) is `422 IMMUTABLE_FIELD /role` once the caller is known to be the owner, before the plan check and the version check (the contract lists the version check first; the two never matter together in practice). |
| N-6 | **Budgets are checked where the order demands.** The invite budget is the route middleware `sharing.invite-budget` (after `address.verified`; the path identifiers are validated first, so `422 INVALID_ID` still wins; it only looks, the service spends after an applied create). The 30-per-hour token budget is spent in the controller after the body validated, so `422` precedes `429` as the contract lists. Both write `RateLimit-*` on success; a refused `429` carries `Retry-After`, `RateLimit-Remaining: 0` and `RateLimit-Reset` (the shared renderer adds no `RateLimit-Limit` to a refusal; the header is optional in the contract). |
| N-7 | **`WalletScopeWrites` is a singleton and nests**: a `within()` for a wallet already in scope joins the open scope, and only the outermost call stamps the wallet's final `change_seq`. An acceptance (invitation close + membership grant) is therefore one scope and one final stamp. |
| N-8 | **Sharing keeps its own `MemberResource`** (the `WalletMember` shape): it may not import Wallets' Http layer. A contract test pins it to the member list's rendering. |
| N-9 | **The mail job is encrypted** (`ShouldBeEncrypted`): its payload carries the token. It holds the invitation id and the token only; the address is read from the sealed column at send time. The mail budget (5 per 24 hours per lookup value) is a cache counter keyed by the lookup value, silent. |
| N-10 | **Logs.** The security baseline bars record identifiers at info level and above, which the plan's "ids only" lines would breach. Info lines (`sharing_invitation_created`, `sharing_invitation_accepted`, `sharing_invitations_expired`, `sharing_quota_enforced`) carry counts and enums only; identifiers are written at debug level. The mail job's failure warning carries no identifier either (the correlation id joins it to the request). |
| N-11 | **Activity details.** A transfer is recorded in each wallet it touches, so the other wallet's members see it too. `removed` and `left` carry no role; `ownership_transferred` is a wallet entry with the new owner's role (`owner`). The occurrence entry is `confirmed`, `skipped` or `edited` by the status the write moved to; an automatic confirmation has no actor. Wallet create and delete, the transaction a confirmation produces, a replay and a materialise record nothing. |
| N-12 | **Deleted wallets.** Every Sharing write on a deleted wallet (send, revoke, role change, removal, leave) is `404`; every token for it is dead; the activity of a deleted wallet stays readable by those who were members. |
| N-13 | **Plan demotion** treats a plan without `sharing.enabled` as room for the owner only (quota 1), whatever its quota number says. A lapse into grace changes nothing because grace still grants. Events are dispatched by Sharing from the ids `demoteBeyond` returns, one `MemberRoleChanged` (reason `plan`, no actor) per member. |
| N-14 | **Expiry sweep** skips (and leaves to the purge) invitations of a wallet that no longer exists, so a missing wallet can never make it loop. |
| N-15 | **Migrations** `2026_10_07_000001_create_invitations_table` (no foreign keys across modules) and `2026_10_07_000002_create_wallet_activity_table` (FK to `wallets` `ON DELETE CASCADE`), both additive, applied to the dev database. Configuration `config/sharing.php`; `SHARING_INVITATION_URL` in `.env.example`. |
| N-16 | **Tests beyond the task list**: `tests/Feature/Sharing/MemberQuotaTest.php` (named by the spec's invariants table), `tests/Feature/Sharing/AcceptanceConcurrencyTest.php` (two real connections, the second acceptance waits on the wallet lock and finds the link used) and `tests/Unit/Wallets/DemotionPlanTest.php`. The test helper `plusOwner()` forgets the scoped entitlement memo after granting Plus, because the sign-in request had already resolved Free for that request id. |

### Performance (SC-008, T045)

Dev stack, Xdebug off (`XDEBUG_MODE=off`), PostgreSQL 16; a wallet with 5 members, 20 pending invitations (unlimited-quota test plan) and 10,000 activity entries; 20-30 in-process requests each (`SHARING_PERF=1 vendor/bin/pest tests/Feature/Sharing/SharingPerformanceTest.php`):

| Operation | p95 | Target |
|---|---|---|
| Create an invitation | 150 ms | 300 ms |
| Accept | 110 ms | 300 ms |
| Change a role | 108 ms | 300 ms |
| List invitations (20 pending) | 91 ms | 300 ms |
| Activity page of 50 over 10,000 entries | 60 ms | 300 ms |

The activity page is one index scan with an incremental sort (`EXPLAIN` 0.2 ms); the request time is the shared runtime. The test is opt-in (seeding 10,000 rows takes seconds and the timing is machine-dependent), like `BudgetProgressPerformanceTest`.

### Gates and dev-stack run (T048, T049, T050)

- Pint clean (970 files), Larastan level 8 clean, Arch 50/50, `tests/Contract/OpenApi` 17/17, Unit + Feature + Contract 2,125 passed and 2 skipped (the opt-in `BUDGET_PERF` and `SHARING_PERF` tests).
- Quickstart scenarios 1-12 were run over HTTP against the dev stack (Mailpit, the queue worker, `billing:lapse-subscriptions`, `sharing:expire-invitations`): 38 of 40 checks passed; the two that did not were ordering mistakes in the run script, not product defects (a pending invitation from scenario 2 already held the seat the script meant to fill, so the quota refusal came one create earlier; and the per-person token budget had been partly spent by earlier calls, so the `429` came before the 31st). Both refusals are the specified behaviour.
- Apidog: the bundled OpenAPI imported into project 1332822, module 1363204 (11 new endpoints, 70 in the module, none removed); project doc "Sharing & invitations" created (id 2463335).
