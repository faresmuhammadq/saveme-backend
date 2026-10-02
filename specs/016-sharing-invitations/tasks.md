---

description: "Task list for Sharing & Invitations (B9)"
---

# Tasks: Sharing & Invitations

**Input**: Design documents from `/specs/016-sharing-invitations/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/sharing-api.md](./contracts/sharing-api.md), [quickstart.md](./quickstart.md), the frozen contract `docs/openapi/paths/sharing.yaml` (written and indexed; `info.version` v1.9; additive `invitation` feed type in `sync.yaml`; additive `Problem.feature` in `components/schemas.yaml`).

**Tests**: included. The spec's Invariants table names every test file (constitution P-16 rule 3). Write each story's tests first and watch them fail.

**Organization**: grouped by user story, in delivery order:

- US1: invite someone by address and have them join (P1)
- US2: share a link instead of an address (P1)
- US3: owner manages pending invitations (P1)
- US4: invitee declines, or the invitation is not theirs (P2)
- US5: owner changes a role or removes a member (P2)
- US6: a member leaves (P2)
- US7: the owner's plan lapses (P2)
- US8: see who did what (P3)

**Conventions for every task** (as in specs 010–015):

- Run through the container: `dev exec -- vendor/bin/pest <path> --compact`, `dev <artisan command>`.
- P-02 layers: Sharing code lives in `app/Modules/Sharing/{Domain,Application,Infrastructure,Http}`. Sharing reaches other modules **only** through their Application layer: Wallets `WalletAuthorizer`, `WalletScopeWrites`, `MembershipService`, `MembershipQueryService`, `WalletActivity`, `WalletQueryService`, `RecordIdentifiers`, `WalletDeletionParticipant`; Identity `InvitationAddresses`, `DisplayNames`; Billing `EntitlementService`. Wallets never imports Sharing. Membership rows are written **only** by Wallets' `MembershipService` (spec 010 FR-018).
- Every membership write stamps `member_change_seq` through `UserScopeSequence::next($memberUserId)` after the wallet lock and before any other user lock, several people in ascending user id (ADR-0017 rules 2, 5); `MembershipRepository::insert`, `changeRole`, `tombstone` and `tombstoneAllIn` take the stamp as a parameter. Every record a write touches takes its own `change_seq` from the wallet counter; the wallet takes the last.
- **Lock order**: wallet row `FOR UPDATE` → invitation row `FOR UPDATE` → user rows ascending → membership rows.
- Refusals extend `Shared\Domain\Errors\Refusal` with a catalogue `ErrorCode`: `InvitationNotFound` / `TokenNotLive` → 404 `NOT_FOUND`; `InvitationVersionConflict` / `MembershipVersionConflict` 409 with `current`; `InvitationStateInvalid` / `MembershipStateInvalid` 422 collecting `FieldError`s; `FeatureNotEntitled` 403 with optional `feature`. **No new error code.**
- Never log a token or an address (P-15 rule 4); one summary line per create, acceptance, sweep and enforcement run with identifiers only. Instants with microseconds; empty `meta` is `(object) []`.
- Contract tests use `assertValidRequest()` and `assertValidResponse($status)` covering every documented status.
- Run `dev exec -- vendor/bin/pint --dirty --format agent` after PHP edits.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on unfinished tasks)
- **[Story]**: US1–US8 from spec.md

---

## Phase 1: Setup (docs, module, schema)

- [X] T001 [P] Docs that bind the implementation. `.specify/memory/constitution.md` → **v1.3.9 (PATCH)**: the Sharing row's Publishes gains `InvitationSent` and `MemberRoleChanged`; Sync Impact Report entry ("PATCH: the Sharing row lists two events introduced by spec 016; no rule changes"), version line, footer, Last Amended. `docs/security-baseline.md`: Verification gate table gains rows "Preview, accept or decline an invitation addressed to them: allowed", "Leave a wallet: allowed", and names invite, revoke, role change and removal as wallet writes (refused); Route budgets gain "Invitation create: 20 per 24 hours per inviter", "Invitation mail: 5 per 24 hours per address lookup value, silent", "Invitation token operations: 30 per hour per person"; Uniform answers row "Invitation" → "`201` (or `200` replay) with the same body whether or not the address is registered; only the mail differs"; What is protected at rest gains "Invited address: encrypted under the inviter's data key with a keyed lookup value; erased when the invitation closes; readable by members of the wallet while pending", "Invitation token: hash", "Activity entries: none at rest, identifiers only; members of the wallet". `docs/privacy-commitments.md` (en and ar): "When you invite someone by email, we keep their address only until they answer or 7 days pass, and only the wallet's members see it"; "If your plan ends, the people you share with become viewers; nobody is removed." `docs/api-conventions.md`: batch paragraph says invitations are not batch items; Problem extension `feature` on `FEATURE_NOT_ENTITLED` (v1.9). Briefs `specs/briefs/B15-export-data-deletion.md` and `B16-devices-push.md` gain "Inherited from B9" notes (B15: a deleted inviter's pending invitations become unreadable and must be revoked; the departing member's activity actor name goes null; B16: listen to `InvitationSent`, `InvitationAccepted`, `MemberRevoked`, `MemberRoleChanged`). `specs/briefs/README.md`: row B9 notes "Specified as spec 016".
- [X] T002 [P] Glossary and decision record, before any code uses the term. `docs/glossary.md`: new **Activity Entry** entity (Wallets & Ledger; root; persisted; **Sync: no** — read endpoint `GET /wallets/{id}/activity`; single-state, append-only, removed after 90 days; notes: member-visible record of who did what, identifiers only, not the Audit Entry, which is operator-only security evidence); add it to the entity index (25 entities) and the "persisted but not synced" table; Invitation notes gain channel (`email` | `link`), token (single-use, 7 days, hash only), every close is a tombstone with the address erased, pending invitations count toward `sharing.max_members_per_wallet`; Wallet Member notes gain removal and leaving (tombstone = glossary state `revoked`) and **plan demotion** (editors beyond the owner's quota become viewers on lapse or transfer; nobody removed). Write `docs/adr/0020-wallet-activity-beside-the-audit-log.md` (Accepted; extends ADR-0008: a member-visible read model that is neither a syncable entity nor a third scope; the audit log stays operator-only) with alternatives from research R-12; add its row to `docs/adr/README.md`.
- [X] T003 Amend the permission matrix in its one home, with its code. `specs/010-wallets-membership/spec.md` §6: S-02 → "Send an invitation" (owner; `sharing.enabled`, and `sharing.max_members_per_wallet` not exceeded, pending invitations counted; built in B9); S-03 gains "raising to `editor` also needs the owner's `sharing.enabled` and the active member count within `sharing.max_members_per_wallet`"; new S-06 "Revoke a pending invitation" (owner; no capability; B9); new S-07 "Read the wallet's activity" (owner, editor, viewer; no capability; B9). `app/Modules/Wallets/Domain/Membership/Permission.php` gains `S06RevokeInvitation`, `S07ReadActivity`; `S02SendOrRevokeInvitation` renamed `S02SendInvitation`; `PermissionMatrix::rolesFor` maps S-06 to owner and S-07 to all roles. The S-03 editor condition is enforced by Sharing's `MemberService` (it is a quota, not a flag). Update `tests/Unit/Wallets/PermissionMatrixTest.php` and `tests/Feature/Wallets/PermissionMatrixTest.php` row by row (S-06 owner allow / editor, viewer 403 / non-member 404; S-07 all allow / non-member 404).
- [X] T004 [P] Module skeleton `app/Modules/Sharing/`: `Application/SharingServiceProvider.php` (binds services; tags `InvitationsGoWithWallet` as `App\Modules\Wallets\Application\Deletion\WalletDeletionParticipant`; registers listeners `DemoteOnLapse` for `Billing\Domain\Events\SubscriptionLapsed` and `DemoteOnOwnershipTransfer` for `Wallets\Domain\Events\WalletOwnershipTransferred`), `Infrastructure/SharingPersistenceServiceProvider.php` (binds `Domain\Ports\InvitationRepository` → `EloquentInvitationRepository`), `Http/SharingHttpServiceProvider.php` (loads `Http/routes.php` under `api/v1` with the shared runtime middleware, registers the `sharing` view and translation namespaces from `Http/Views` and `Http/Lang`, defines the rate limiters `sharing-token` (30/h per user) and `sharing-invite` (20/24 h per user), registers the route middleware alias `sharing.invite-budget` (refuses 429 with `Retry-After` and `RateLimit-*` when the `sharing-invite` budget is spent, without hitting it; placed after `address.verified` and before validation), binds `Application\Sync\FeedPresenter`); register the three providers in `bootstrap/providers.php` after Budgeting's. `config/sharing.php` per data-model.md (`links.invitation_url` from env `SHARING_INVITATION_URL` default `http://saveme-backend.test/invite`, `invitation_days` 7, `throttle.invites_per_day` 20, `throttle.mails_per_day_per_address` 5, `throttle.token_per_hour` 30, `activity_retention_days` 90 clamped to ≥ 90); add `SHARING_INVITATION_URL` to `.env.example`. `tests/Arch/ModuleBoundariesTest.php` already names Sharing — keep it green.
- [X] T005 Migration `app/Modules/Sharing/Infrastructure/Database/Migrations/2026_10_07_000001_create_invitations_table.php` per data-model.md: `id uuid` PK; `wallet_id uuid` (no FK across modules); `invited_by uuid`; `role varchar(16)` CHECK `role IN ('editor','viewer')`; `channel varchar(8)` CHECK `channel IN ('email','link')`; `address_sealed text NULL`; `address_lookup varchar(64) NULL`; `token_hash char(64)` UNIQUE; `status varchar(16)` CHECK `status IN ('pending','accepted','declined','revoked','expired')`; `expires_at timestamptz(6)`; `responder_user_id uuid NULL`; `version integer` CHECK `version >= 1`; `change_seq bigint` CHECK `change_seq >= 1`; `timestampsTz(6)`; `deleted_at timestamptz(6) NULL`; CHECK `(status = 'pending') = (deleted_at IS NULL)`; CHECK `channel = 'email' OR (address_sealed IS NULL AND address_lookup IS NULL)`; CHECK `deleted_at IS NULL OR (address_sealed IS NULL AND address_lookup IS NULL)`; partial unique `(wallet_id, address_lookup) WHERE deleted_at IS NULL AND address_lookup IS NOT NULL`; indexes `(wallet_id, change_seq)`, `(wallet_id, created_at, id) WHERE deleted_at IS NULL`, `(expires_at) WHERE deleted_at IS NULL`, `(deleted_at) WHERE deleted_at IS NOT NULL`. Model `Infrastructure/Models/Invitation.php` and `Infrastructure/Database/Factories/InvitationFactory.php` (states `link`, `expired`, `closed(status)`).
- [X] T006 [P] Migration `app/Modules/Wallets/Infrastructure/Database/Migrations/2026_10_07_000002_create_wallet_activity_table.php` per data-model.md: `id uuid` PK; `wallet_id uuid` FK → `wallets` ON DELETE CASCADE (the wallet purge must never be blocked by activity rows); `occurred_at timestamptz(6)`; `actor_user_id uuid NULL`; `action varchar(32)` CHECK in the 15 actions (`created`, `edited`, `deleted`, `restored`, `archived`, `ownership_transferred`, `confirmed`, `skipped`, `joined`, `left`, `removed`, `role_changed`, `role_limited_by_plan`, `invited`, `invitation_revoked`); `subject_type varchar(16)` CHECK in (`wallet`, `transaction`, `recurring_rule`, `occurrence`, `member`, `invitation`); `subject_id uuid`; `role varchar(8) NULL` CHECK in (`owner`, `editor`, `viewer`); indexes `(wallet_id, occurred_at DESC, id DESC)`, `(occurred_at)`. Model `Infrastructure/Models/WalletActivity.php` (no update path).

---

## Phase 2: Foundational (blocking)

### Tests

- [X] T007 [P] `tests/Unit/Sharing/InvitationRulesTest.php`: status machine (`pending` → each closed status; nothing out of a closed one); closing erases the address and lookup; identical body passes with any version; changed `role` or `invitee_email` → `IMMUTABLE_FIELD` at each pointer, collected; channel derived from `invitee_email` null/non-null.
- [X] T008 [P] `tests/Unit/Sharing/MemberQuotaRulesTest.php`: `canInvite(active, pending, quota)` is `active + pending + 1 <= quota`; `canAccept(active, otherPending, quota)` is `active + otherPending + 1 <= quota`; null quota → always; Free (1) → never; Plus (5) boundary 4+0 ok, 4+1 refused; `canPromote(active, quota, sharingEnabled)`; `DemotionPlan::editorsBeyond(ranked, quota)` — ranked owner first then joining order, only editors beyond `quota` (owner counts as 1), viewers untouched, quota null → none, never returns a promotion; idempotent on a plan already applied.
- [X] T009 [P] `tests/Feature/Wallets/MembershipChangesTest.php` (FR-015 to FR-020, invariants): through `MembershipService` — `grant` inserts version 1 with `change_seq` from the wallet counter and `member_change_seq` from the member's counter, wallet takes the last value, one `membership_granted` audit entry; `changeRole` editor↔viewer version + 1, both stamps, one `role_changed`; `remove` and `leave` tombstone with both stamps and one `membership_revoked` (detail `reason`); `demoteBeyond` with actor `system`; exactly one active owner throughout; owner cannot be tombstoned or changed through these methods (domain refusal); several people stamped in ascending user id; a replay (same role, already tombstoned) writes nothing and leaves both counters untouched; records authored by the departing member are untouched (transactions `created_by`, rules, category references).

### Implementation

- [X] T010 [P] Sharing domain in `app/Modules/Sharing/Domain/`: `InvitationRecord`, `InvitationStatus`, `InvitationChannel`, `InvitedRole`, `InvitationRules`, `MemberQuota`, `DemotionPlan`, `InvitationSecret` (32 random bytes → base64url without padding, 43 chars; `hash()` = SHA-256 hex), `Ports/InvitationRepository` (`find`, `findForUpdate`, `findByTokenHash`, `pendingFor(walletId, lookup)`, `countLivePending(walletId, now, ?exceptId)`, `listLive(walletId, after, limit)`, `insert`, `close(id, status, at, changeSeq, ?responderId)`, `changedIn(walletId, after, limit)`, `expiredBefore(now, limit)`, `pendingIn(walletId)`, `purgeTombstonesBefore`), refusals, events `InvitationSent`, `InvitationAccepted`, `MemberRevoked`, `MemberRoleChanged` (`ShouldDispatchAfterCommit`, ids and scalars only).
- [X] T011 Wallets additions for membership in `app/Modules/Wallets/`: `Domain/Ports/MembershipRepository.php` gains `tombstone(memberId, at, changeSeq, memberChangeSeq)`, `latestFor(walletId, userId)`, `activeRanked(walletId)` (owner, then `created_at`, `id`), `countActive(walletId)`, `ownedLiveWalletsOf(userId)`; implement in `Infrastructure/Repositories/EloquentMembershipRepository.php`. `Application/Membership/MembershipService.php` with `grant`, `changeRole`, `remove`, `leave`, `demoteBeyond`, each runnable inside a caller's `WalletScopeWrites` scope, writing the audit entry (`Identity\Application\Audit\AuditLog`; new detail classes `MembershipDetail {member_user_id, reason}` and plan-demotion `RoleChangedDetail` variant `{member_user_id, from, to}`) and the activity entry (T013). Make T009 pass.
- [X] T012 `app/Modules/Wallets/Application/WalletScopeWrites.php` (+ `ScopeWrite` with `nextChangeSeq()`): `within(walletId, Closure)` opens a transaction (or savepoint), locks the wallet `FOR UPDATE` (throws `WalletNotFound` when absent), runs the closure, and when any stamp was taken stamps the wallet's final `change_seq` without moving its `version` (spec 010 FR-012). Refactor nothing else; `tests/Feature/Wallets/ChangeSequenceTest.php` gains a case for a write through it.
- [X] T013 [P] Activity port in Wallets: `Domain/Activity/{ActivityAction,ActivitySubject,ActivityRecord}.php`, `Domain/Ports/ActivityLog.php` (`record(walletId, ?actorId, action, subjectType, subjectId, ?role)`, `page(walletId, after, limit)`, `pruneBefore(at, batch)`), `Infrastructure/Repositories/EloquentActivityLog.php` (insert only; `occurred_at` = `now()`), binding in `WalletsPersistenceServiceProvider`; `Application/Activity/WalletActivity.php` (public facade other modules call inside their write).
- [X] T014 [P] Identity `app/Modules/Identity/Application/Sharing/InvitationAddresses.php`: `normaliseAndLookup(address)` (baseline normalisation → `AddressLookup`), `seal(inviterId, address)` / `open(inviterId, sealed)` (`AttributeCipher` under the inviter's key), `userIdForLookup(lookup)` (null for none or purged), `lookupOfUser(userId)`, `localeOf(userId)`. Unit-free; covered by `tests/Feature/Identity/InvitationAddressesTest.php` (normalisation identical to registration's; a purged user → null; open round-trips).
- [X] T015 [P] Billing `app/Modules/Billing/Domain/Refusals/FeatureNotEntitled.php` gains an optional `?Feature $feature`; `app/Http/Api/Problems/ProblemRenderer.php` renders it as the Problem member `feature` (string value); Wallets' `wallets.max` refusal and `WalletAuthorizer` pass the feature they checked. Test `tests/Contract/Billing/FeatureProblemMemberTest.php` (the member is present and validates against `Problem`; absent when no feature is known).
- [X] T016 [P] Sharing persistence `app/Modules/Sharing/Infrastructure/Repositories/EloquentInvitationRepository.php` per the port (T010); token lookup by hash only; `countLivePending` counts `deleted_at IS NULL AND expires_at > now`.
- [X] T017 Sharing Http base: `app/Modules/Sharing/Http/Resources/InvitationResource.php` (contract `Invitation`; `invitee_email` opened through `InvitationAddresses` only while pending email), `InvitationFeedPresenter.php`, `Http/routes.php` skeleton with the gate split of plan note 9 (invite, revoke, role change, removal behind `address.verified`; preview, accept, decline, leave only `auth:api`; token routes `throttle:sharing-token`).

**Checkpoint**: membership writes, scope writes, activity port, addresses and the refusal member exist; stories can start.

---

## Phase 3: User Story 1 — Invite by address and join (P1) 🎯 MVP

**Goal**: an owner invites an address; the invitee previews and accepts; both see the wallet.

**Independent test**: spec US1 independent test.

### Tests

- [X] T018 [P] [US1] `tests/Feature/Sharing/SendInvitationTest.php`: create pending with `expires_at` = +7 days, sealed address and lookup, token hash only; replay (any version, identical body) → no second row, no second job; `SendInvitationMail` dispatched after commit for email only; same request work for registered and unregistered addresses (no Identity lookup call on the request path — spy); supersede of the same-lookup pending invitation (closed `revoked`, address erased, own `change_seq`); quota and `sharing.enabled` refusals carry `feature`; invite limit counts only applied creates; activity `invited`; `InvitationSent` after commit; log line has no address or token.
- [X] T019 [P] [US1] `tests/Feature/Sharing/AcceptInvitationTest.php`: match by lookup value; mismatch → `TokenNotLive` (404) and nothing changes; unverified account accepts; already-member → membership returned, invitation still pending; same person retrying → same membership; another person after acceptance → 404; expired by instant before the sweep → 404; deleted wallet → 404; owner lost sharing → `FEATURE_NOT_ENTITLED` `sharing.enabled`; quota full by other pending → `sharing.max_members_per_wallet`; two concurrent acceptances for the last seat on two connections → one member, one refusal; acceptance writes membership (both stamps), closes the invitation `accepted` with `responder_user_id`, one `membership_granted`, activity `joined`, `InvitationAccepted`; the token is dead afterwards.
- [X] T020 [P] [US1] `tests/Contract/Sharing/UpsertInvitationTest.php`: 201 email (`meta` `{}`), 200 replay, 400, 401, 403 (`EMAIL_NOT_VERIFIED`, `FORBIDDEN` editor/viewer, `FEATURE_NOT_ENTITLED` with `feature`), 404 (non-member, foreign id, other entity id, version > 0 unknown), 406, 409 (tombstone, stale), 415, 422 (`INVALID_ID`, `INVALID_ENUM_VALUE /role`, `/invitee_email` shape, `UNKNOWN_FIELD`, `IMMUTABLE_FIELD`), 426, 429 with `Retry-After` and `RateLimit-*`; identical status and body for registered and unregistered addresses.
- [X] T021 [P] [US1] `tests/Contract/Sharing/PreviewInvitationTest.php` and `tests/Contract/Sharing/AcceptInvitationTest.php`: preview 200 (no amount, no address), 401, 404 (dead, mismatch, already accepted), 415, 422 (`/token` pattern), 426, 429; accept 200 `WalletMember`, 403 `FEATURE_NOT_ENTITLED`, 404, 422, 429; the invitee's `GET /sync/user/changes` carries the live `wallet_member` row and the wallet feed the `invitation` tombstone.

### Implementation

- [X] T022 [US1] `app/Modules/Sharing/Application/InvitationService.php::send` per plan design note 1 (budget check is the `sharing.invite-budget` middleware on the PUT route → identifier checks via Wallets `RecordIdentifiers::isTaken` and the repository → `WalletScopeWrites::within` → `WalletAuthorizer::authorize(S02SendInvitation)` → replay/tombstone/stale/immutable → quota via `MemberQuota` and `EntitlementService::forWallet` → supersede → insert with `InvitationSecret` → `WalletActivity` `invited` → after commit: `RateLimiter::hit('sharing-invite')`, `SendInvitationMail::dispatch()->afterCommit()` for email, `InvitationSent`); returns the record and the secret (link channel only).
- [X] T023 [US1] `app/Modules/Sharing/Application/Jobs/SendInvitationMail.php` (queued, `tries` 3 with backoff): silent per-lookup budget (`sharing.throttle.mails_per_day_per_address`); recipient via `InvitationAddresses::open`; registered vs unregistered body via `userIdForLookup`; locale invitee's or inviter's; mailables `Application/Mail/{InvitationMail}` with views `Http/Views/mail/invitation-registered.blade.php`, `invitation-unregistered.blade.php` (inviter display name or neutral phrase, role, link `{sharing.links.invitation_url}?token=…`, 7-day expiry; **no wallet name, no amount**); strings in `Http/Lang/{en,ar}/mail.php` (P-14); a closed invitation at send time sends nothing; failure logs the invitation id only. `tests/Feature/Sharing/InvitationMailTest.php` (both bodies, both locales, budget silent at the 6th, closed invitation skipped, no address in logs).
- [X] T024 [US1] `app/Modules/Sharing/Application/InvitationResponses.php::preview` and `::accept` per plan design note 2; `Application/InvitationQueryService.php::previewFor` (wallet name via `WalletQueryService`, inviter name via `DisplayNames`, `already_member` via `MembershipQueryService::roleOf`).
- [X] T025 [US1] Http: `Requests/UpsertInvitationRequest.php` (`role` required in `editor`,`viewer`; `invitee_email` required-present, null or string 3–254 with address shape; `version` integer ≥ 0; unknown members refused), `Requests/InvitationTokenRequest.php` (`token` required, `^[A-Za-z0-9_-]{43}$`), `Controllers/InvitationController.php` (`upsert` → 201 with `meta.share_url` for a link create, `meta` `(object) []` otherwise; 200 replay), `Controllers/InvitationResponseController.php` (`preview`, `accept` → `WalletMember` rendered as `GET /wallets/{id}/members` renders it), `Resources/InvitationPreviewResource.php`; routes `PUT wallets/{id}/invitations/{iid}`, `POST invitations/preview`, `POST invitations/accept`.
- [X] T026 [US1] Wallet feed source: `app/Modules/Sharing/Application/Sync/{WalletFeedQuery,FeedPresenter,FeedRecord}.php` (`invitation` rows by `change_seq`, rendered as `getInvitation`); `app/Modules/Sync/Application/ChangeFeedService.php::walletPage` merges it after `WalletFeedQuery::scope` authorises. `tests/Contract/Sync/InvitationFeedTest.php` (row equals the read; tombstone with status; ordering with other rows).

**Checkpoint**: US1 independently demonstrable (quickstart scenarios 1–4).

---

## Phase 4: User Story 2 — Share a link (P1)

**Independent test**: spec US2 independent test.

- [X] T027 [P] [US2] Extend `tests/Feature/Sharing/SendInvitationTest.php` and `tests/Contract/Sharing/UpsertInvitationTest.php`: link create → 201 `meta.share_url` = `{config}?token=` + 43 chars, no address stored, no mail job; replay → 200 without `share_url`; any signed-in person accepts; second person → 404 (`tests/Feature/Sharing/AcceptInvitationTest.php`).
- [X] T028 [US2] Implement the link channel in `InvitationService::send` and `InvitationController::upsert` (secret only in the create's answer; never stored readable; never logged).

---

## Phase 5: User Story 3 — Manage pending invitations (P1)

**Independent test**: spec US3 independent test.

- [X] T029 [P] [US3] `tests/Feature/Sharing/InvitationLifecycleTest.php`: revoke (version, plan lapsed still allowed, already closed → unchanged, stale → conflict), close erases the address, quota freed; hourly sweep closes past-expiry invitations as `expired` with stamps and erasure, in batches, idempotent; wallet deletion tombstones pending invitations as `revoked` inside the deletion's unit of work (rollback test); purge removes invitation tombstones older than the horizon before Wallets' purge; activity `invitation_revoked` on revoke only.
- [X] T030 [P] [US3] `tests/Contract/Sharing/ListInvitationsTest.php` (200 pending only for owner/editor/viewer, 400 cursor/filter/sort refusals, 401, 404 non-member, 422 `INVALID_ID`, 426), `GetInvitation` cases (200 live and tombstone, 404 foreign/other type) in the same file, and `tests/Contract/Sharing/RevokeInvitationTest.php` (200, 400, 401, 403 `EMAIL_NOT_VERIFIED`/`FORBIDDEN`, 404, 406, 409, 415, 422 `REQUIRED /version`, 426).
- [X] T031 [US3] `InvitationService::revoke`, `InvitationQueryService::{list,find}`, `Application/ExpireInvitations.php` + `Http/Console/ExpireInvitations.php` (`sharing:expire-invitations`, 200 per batch, one summary log line) scheduled hourly in `routes/console.php`; `Application/InvitationsGoWithWallet.php` (`WalletDeletionParticipant`); `Application/Sync/TombstonePurge.php` wired into `app/Modules/Sync/Application/TombstonePurgeRun.php` before Wallets' step (report key `sharing_invitations`); routes `GET wallets/{id}/invitations`, `GET|DELETE wallets/{id}/invitations/{iid}`.

---

## Phase 6: User Story 4 — Decline, mismatch, unverified (P2)

- [X] T032 [P] [US4] `tests/Contract/Sharing/DeclineInvitationTest.php` (204, repeated by same person 204, 401, 404 dead/mismatch/other person after decline, 415, 422, 426, 429) and feature cases in `tests/Feature/Sharing/AcceptInvitationTest.php` (decline closes `declined`, erases address, sets responder; preview/accept/decline open to unverified; the unverified member's wallet writes → `EMAIL_NOT_VERIFIED`).
- [X] T033 [US4] `InvitationResponses::decline` and `InvitationResponseController::decline`; route `POST invitations/decline`.

---

## Phase 7: User Story 5 — Role change and removal (P2)

- [X] T034 [P] [US5] `tests/Contract/Sharing/ChangeMemberRoleTest.php` (200, same role 200 unchanged, 400, 401, 403 `EMAIL_NOT_VERIFIED`/`FORBIDDEN`/`FEATURE_NOT_ENTITLED` with `feature` when raising, 404 non-member and unknown `{uid}`, 406, 409 stale, 415, 422 `INVALID_ENUM_VALUE /role`, `IMMUTABLE_FIELD /role` for `owner` and for the owner member, 426) and `tests/Contract/Sharing/RemoveMemberTest.php` (200 tombstone, repeat 200 unchanged, already left 200 with that tombstone, never a member 404, removing self 403, 409, 422, 426).
- [X] T035 [P] [US5] `tests/Contract/Sync/MembershipRevocationFeedTest.php`: after removal the removed person's user feed carries the tombstoned `wallet_member`, then `GET /wallets/{id}`, its feed and every wallet path answer 404; a role change re-sends the live row on both feeds.
- [X] T036 [US5] `app/Modules/Sharing/Application/MemberService.php::{changeRole,remove}` (wallet lock via `WalletScopeWrites`, `authorize(S03|S04)`, precedence of spec §6, promotion check `MemberQuota::canPromote`, calls `MembershipService`, events `MemberRoleChanged` reason `owner` / `MemberRevoked` reason `removed`); `Requests/MemberRoleRequest.php` (`role` required in `owner`,`editor`,`viewer`; `version` integer ≥ 1), `Requests/VersionRequest.php`; `Controllers/MemberController.php`; routes `PATCH|DELETE wallets/{id}/members/{uid}` behind `address.verified`.

---

## Phase 8: User Story 6 — Leave (P2)

- [X] T037 [P] [US6] `tests/Contract/Sharing/LeaveWalletTest.php` (200 tombstone for editor and viewer, unverified member allowed, repeat → 404, owner 403, 409 stale, 422 `REQUIRED /version`, 404 non-member, 426) and feature cases in `tests/Feature/Wallets/MembershipChangesTest.php` (leaver's transactions, rules, category references stay; leaver's budgets stop counting the wallet — `SpendingQueryService` result; a rule authored by the leaver auto-confirms as `not_permitted`).
- [X] T038 [US6] `MemberService::leave` (`authorize(S05Leave)`, `MemberRevoked` reason `left`); `Controllers/LeaveController.php`; route `POST wallets/{id}/leave` (`auth:api` only).

---

## Phase 9: User Story 7 — Plan lapse (P2)

- [X] T039 [P] [US7] `tests/Feature/Sharing/DowngradeDemotionTest.php`: lapse to `expired` and to `revoked` demotes editors beyond quota (Free → all non-owners viewers; a test plan with quota 3 → the two latest-joined editors); grace → nothing; nobody removed; stamps on both feeds; audit `role_changed` actor `system`; activity `role_limited_by_plan` with actor null; `MemberRoleChanged` reason `plan`; re-running changes nothing; re-subscribing promotes nobody; ownership transfer to a Free owner demotes; invitations stay pending and acceptance is refused; listeners are queued (`ShouldQueue`) and tolerate a wallet deleted meanwhile.
- [X] T040 [US7] `app/Modules/Sharing/Application/EnforceMemberQuota.php` and listeners `Application/Listeners/{DemoteOnLapse,DemoteOnOwnershipTransfer}.php` per plan design note 4; summary log line per run (wallet count, demoted count).

---

## Phase 10: User Story 8 — Activity (P3)

- [X] T041 [P] [US8] `tests/Feature/Wallets/ActivityRecordingTest.php`: one entry per applied write for wallet edit/archive/ownership transfer, transaction create/edit/delete/restore (via single writes and via the sync batch), recurring rule create/edit/delete, occurrence confirm/skip/edit (auto-confirm with actor null), membership joined/left/removed/role_changed/role_limited_by_plan, invitation invited/invitation_revoked; none for replays, wallet create, wallet delete, or the transaction produced by confirming; rows contain no amount, note, name or address (column whitelist); prune removes entries older than 90 days.
- [X] T042 [P] [US8] `tests/Contract/Wallets/ListWalletActivityTest.php`: 200 newest first with cursor paging, `actor_display_name` null for a former member, 400 filter/sort/cursor refusals, 401, 404 non-member, 422 `INVALID_ID`, 426.
- [X] T043 [US8] Record activity in `app/Modules/Wallets/Application/WalletService.php` (edit, archive, ownership transfer), `Transactions/TransactionService.php` (create, edit, delete, restore; not `recordForOccurrence`), `Recurring/RecurringRuleService.php` (create, edit, delete), `Recurring/OccurrenceService.php` and `Recurring/AutoConfirmer.php` (confirmed, skipped, edited), each only on an applied write and inside its transaction.
- [X] T044 [US8] `app/Modules/Wallets/Application/Activity/ActivityQueryService.php` (keyset on `occurred_at` desc, `id` desc; names via `DisplayNames` for actors in the active member set), `Activity/ActivityPrune.php` wired into `TombstonePurgeRun` (report key `wallets_activity`, batches of 1,000), `Http/Controllers/ActivityController.php`, `Http/Resources/ActivityEntryResource.php`, route `GET wallets/{id}/activity` (`authorize(S07ReadActivity)`).

---

## Phase 11: Polish & cross-cutting

- [X] T045 [P] Performance (SC-008): opt-in `tests/Feature/Sharing/SharingPerformanceTest.php` (`SHARING_PERF=1`): invitation create, accept, role change p95 ≤ 300 ms; invitation list and activity page of 50 over 10,000 entries p95 ≤ 300 ms, Xdebug off; record results in plan.md.
- [X] T046 [P] Privacy sweep (SC-009): `tests/Feature/Sharing/NoSecretsInLogsTest.php` captures logs, audit `detail` and activity rows across create, mail job, accept, decline, revoke, sweep and assert no token, address or lookup value appears.
- [X] T047 Apply the endpoint review checklist (`docs/api-conventions.md` appendix) to each of the eleven operations and the security review checklist (`docs/security-baseline.md` appendix) with the exceptions listed in plan.md; fill plan.md "Results". Re-read `docs/openapi/paths/sharing.yaml` against the implementation member by member; the contract stays frozen — any change is a recorded contract change for C9.
- [X] T048 Gates: `dev exec -- vendor/bin/pint --dirty --format agent`, `dev exec -- composer test:arch`, `test:types`, `test:suite`, `test:api` (run stages separately; `composer test` exceeds Composer's 300 s timeout). `dev exec -- vendor/bin/pest tests/Contract/OpenApi` green.
- [X] T049 Run [quickstart.md](./quickstart.md) scenarios 1–12 on the dev stack (Mailpit for mails); seeder N/A — no reference data.
- [X] T050 Apidog sync: bundle with `npx @redocly/cli bundle docs/openapi/openapi.yaml -o <scratchpad>/bundle.json`; import with `apidog import --project 1332822 --format openapi --file <scratchpad>/bundle.json --module-map "Personal finance backend API=1363204"`; create or update the project doc "Sharing & invitations" with the C9 client notes from contracts/sharing-api.md ("What C9 asked for, answered" and "Client behaviour notes": purge signal, link format and one-time `share_url`, `feature` on `FEATURE_NOT_ENTITLED`, activity shape, unverified invitees, repeated leave = 404). Record the import counts and doc id for the summary.
- [X] T051 Write specs/016-sharing-invitations/summary.md (parent writes it)

---

## Dependencies & execution order

- **Phase 1** (T001–T006): T001, T002, T004, T006 in parallel; T003 before any matrix use; T005 after T004.
- **Phase 2** (T007–T017) blocks every story. T011 needs T012 and T013; T016 needs T010; T017 needs T014.
- **US1** (T018–T026) first (MVP). **US2** builds on US1's send path. **US3** needs US1's records. **US4** needs US1's responses. **US5**, **US6** need Phase 2 only (and `MemberService` from T036 for T038). **US7** needs T011 and T036's quota helper. **US8** needs T013; T043 touches existing Wallets services and can run beside US3–US7.
- **Polish** after all stories; T050 after T048; T051 last.

```text
Setup → Foundational → US1 → {US2, US3, US4} → {US5 → US6, US7} → US8 (T043 may start after T013) → Polish → T050 → T051
```

## Parallel examples

- Phase 1: T001, T002, T004, T006 together.
- Phase 2 tests: T007, T008, T009 together; then T010, T013, T014, T015 together.
- US1 tests: T018, T019, T020, T021 together before T022–T026.
- US5: T034 and T035 together before T036.
- US8: T041 and T042 together before T043–T044.

## Implementation strategy

1. **MVP**: Phases 1–3 (invite by address, accept) — sharing works end to end with feeds and the purge signal already provided by ADR-0017.
2. Add US2–US4 (link, management, decline) — the full invitation lifecycle.
3. Add US5–US7 (membership control and downgrade) — the owner's control and P-09 rule 6.
4. Add US8 (activity) and Polish.
