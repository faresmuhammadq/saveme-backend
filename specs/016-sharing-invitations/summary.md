# Summary: Sharing & Invitations (spec 016, B9)

**Date**: 2026-10-02 · **Status**: implemented (T001–T051), QA and E2E green, committed on `main` (e4dc4c6).

## What was built

- **Sharing module** (`app/Modules/Sharing`, four layers, three providers).
  - Wallet-scoped, syncable **Invitation**: created by PUT with a client UUIDv7, immutable, two channels (email, link). Token: 32 random bytes, SHA-256 stored, 7-day single use, never logged. The invited address is sealed under the inviter's key with a lookup value and erased on every close.
  - Services: `InvitationService` (send, revoke), `InvitationResponses` (preview, accept, decline), `MemberService` (role change, remove, leave), `EnforceMemberQuota`, `ExpireInvitations` (hourly), queued and encrypted `SendInvitationMail`.
  - `invitation` rows on the wallet feed, a wallet-deletion participant, a purge step before Wallets'.
- **Eleven operations** matching the frozen `sharing.yaml` (`info.version` v1.9). No new error code; `Problem.feature` names the capability on every `FEATURE_NOT_ENTITLED`.
- **Wallets additions**:
  - `MembershipService` (grant, change role, remove, leave, demote beyond quota): each write stamps `change_seq` and `member_change_seq` (ADR-0017), writes one audit entry and one activity entry, and takes the wallet lock first.
  - `WalletScopeWrites` / `ScopeWrite` (nesting, so an acceptance is one scope with one final wallet stamp); `DemotionPlan`.
  - **Activity Entry** (ADR-0020): table `wallet_activity`, `GET /wallets/{id}/activity`, readable by every member, identifiers only, 90-day prune in the daily purge. Recorded by `WalletService`, `TransactionService`, `RecurringRuleService`, `OccurrenceService`, `AutoConfirmer` and Sharing. Separate from the operator-only audit log.
- **Identity** `InvitationAddresses`; **Billing** `FeatureNotEntitled` with an optional `feature`.
- **Plan demotion**: queued, idempotent listeners on `SubscriptionLapsed` and `WalletOwnershipTransferred` turn editors beyond the owner's member quota into viewers (owner first, then joining order). Nobody is removed or automatically promoted.
- **Rate limits**: 20 invitation creates per 24 h per inviter (429); 5 invitation mails per 24 h per address (silent); 30 token operations per hour per person (429).
- **Docs**: ADR-0020; constitution v1.3.9 (events `InvitationSent`, `MemberRoleChanged`); glossary (Activity Entry); spec 010 §6 permission matrix amended with `PermissionMatrix` (S-02 send-only, S-03 entitlement on raise to editor, new S-06 revoke and S-07 activity); security baseline; privacy commitments (en, ar); api-conventions; B15/B16 "Inherited from B9" notes; `config/sharing.php`.
- **Apidog**: bundle imported (11 new endpoints); project doc "Sharing & invitations" (2463335).

### Fix made during QA

The English invitation mail to an unregistered address read "create an saveme-backend account"; it now reads "first create a :app account…". The Arabic text was already correct.

## Results

- **QA** (`/speckit-qa-run`, HTTP, real queue worker and scheduler, links read from Mailpit): 302/302 checks, 38/38 acceptance criteria. Includes three concurrent accepts of one link (one 200, two 404). Report `qa/qa-20261002-183507.md`.
- **E2E** (`/speckit-test-by-validate`, HTTP): 38/38 scenarios (117 assertions); every membership came from a real invitation. Report `test-reports/validation-20261002-183507.md`.
- **Gates**: Pint and Larastan clean; Arch 50/50; Unit + Feature + Contract 2,125 passed, 2 opt-in performance tests skipped.
- **Performance** (Xdebug off; 5 members, 20 pending invitations, 10,000 activity entries; p95): create invitation 150 ms, accept 110 ms, role change 108 ms, list invitations 91 ms, activity page of 50 60 ms (target 300 ms).

## Deployment

- Run the two `2026_10_07` migrations (invitations, wallet_activity).
- The scheduler runs `sharing:expire-invitations` hourly.
- Restart queue workers.
- Set `SHARING_INVITATION_URL` per environment (default `http://saveme-backend.test/invite`; listed in `.env.example`).

## Contract notes for the client

No change from the frozen v1.9. Treat an invitation whose `expires_at` is in the past as expired: the list keeps showing it as `pending` until the hourly sweep, but accept already refuses it.

## Assumed defaults

Owner away with delegated authority; each the conservative option.

### Design

1. Create is a PUT with a client UUIDv7, not the brief's POST (P-06; safe retries without `Idempotency-Key`). Invitations cannot be edited.
2. Two channels on one record: email invitations send a link through the existing mail setup; link invitations return the link once, in the 201 only (`meta.share_url`). A replayed create returns no link.
3. Tokens travel in the request body, never the URL path (P-15: no tokens in logs).
4. Accepting takes no `Idempotency-Key` (reserved for three money operations); it is idempotent by token and person.
5. Email invitations match by the address lookup value; link invitations accept anyone signed in. Any token problem, including an address mismatch, is 404.
6. Unverified accounts may preview, accept, decline and leave; owner actions (invite, revoke, change role, remove) require verification.
7. Invitations last 7 days and are single use; the hourly sweep closes expired ones, and accept checks the expiry itself. Every close tombstones the row and erases the address.
8. The invited address is encrypted under the inviter's key and visible to members only while pending.
9. Re-inviting an address replaces its pending invitation.
10. Pending invitations and the owner count toward the member quota (Free 1, Plus 5).
11. When the owner's plan lapses or ownership transfers, editors beyond the quota become viewers (owner first, then joining order); nobody is removed or promoted back automatically.
12. Revoke, remove and lowering a role are never blocked by the plan.
13. The owner cannot leave (403) or change their own role; ownership moves only by transfer.
14. Removal and leave tombstone the membership; a repeated leave is 404, a repeated removal returns the same tombstone.
15. Everything a departing member recorded stays; their recurring rules' auto-confirm is refused (`not_permitted`); their budgets stop counting that wallet.
16. Activity Entry (ADR-0020): identifiers only, 90 days, all members can read it, separate from the audit log; wallet creation and deletion are not logged.
17. Rate limits: 20 creates / 24 h per inviter (429), 5 mails / 24 h per address (silent), 30 token operations / hour per person (429).
18. Invitations are not sync-batch items (each create sends mail or returns a one-time secret).
19. Events: `InvitationAccepted`, `MemberRevoked` (existing) and `InvitationSent`, `MemberRoleChanged` (new).

### Implementation (plan N-1 to N-16, plus)

20. `DemotionPlan` lives in Wallets (Wallets cannot import Sharing).
21. `FeatureNotEntitled` carries `feature` through `Refusal::extensions`; the `wallets.max` refusal now names `wallets.max`.
22. Entitlement and quota are checked for a new invitation only; a lapsed owner can still replay an earlier create and can always revoke.
23. A role change naming the owner is 422 `IMMUTABLE_FIELD /role`, before the plan and version checks.
24. The invite budget is middleware that runs before body validation (429 first); the token budget is spent after validation (422 before 429).
25. Info-level logs carry no record ids (security baseline); ids appear at debug only.
26. Sharing has its own `MemberResource` (cannot import Wallets' Http).
27. A transfer is recorded as activity in each wallet it touches; `removed` and `left` entries carry no role.
28. Sharing writes on a deleted wallet are 404.
29. A plan without `sharing.enabled` counts as quota 1 for demotion; a lapse into grace changes nothing.
30. The invitation mail job is encrypted (its payload carries the token).
31. An expired invitation stays listed as `pending` until the sweep; the mail's "7 days" is fixed text, as the spec fixes it.

## Remaining risks

- Editors keep editor rights for up to an hour after the plan's end until the demotion runs; new invitations and acceptances are refused immediately.
- A lost answer to a link create means revoking and creating a new invitation to get a link.
- The partial demotion case (some editors beyond quota) is unreachable with the real plans and covered only by tests.
- If an invitation is superseded before its mail is sent, that mail is never sent (intended).
- Activity recording touches five Wallets services.
- Adding `Invitation` to the sync feed raised Redocly example warnings from 191 to 203 (warnings only).
