# Feature Specification: Sharing & Invitations

**Feature Branch**: `016-sharing-invitations`

**Created**: 2026-10-02

**Status**: Draft

**Input**: Brief B9 ([specs/briefs/B09-sharing-invitations.md](../briefs/B09-sharing-invitations.md)): "A wallet owner invites another person by email or shareable link with a role (editor/viewer); the invitee accepts in the app (must have an account), the owner can change roles, remove members, and members can leave. Endpoints: `POST /wallets/{id}/invitations`, `GET /wallets/{id}/invitations`, `DELETE …/{iid}`, `POST /invitations/{token}/accept`, `PATCH /wallets/{id}/members/{uid}`, `DELETE /wallets/{id}/members/{uid}`, `POST /wallets/{id}/leave`. An activity feed per wallet records member actions (who added/edited/deleted what)." Plus the brief's considerations, its reconcile table, its binding "Inherited from B14" section, and the paired client brief [C9](../../../saveme-kmp/specs/briefs/C09-sharing.md) "Needs from the backend" (the purge signal, the invitation link format, the quota refusals with pending invitations counted, the activity feed shape).

> **Nature of this feature.** B9 turns a wallet from a private container into a shared one. It builds the **Invitation** (a wallet-scoped syncable record in a new Sharing module), its delivery by mail or by a link the owner shares, acceptance and decline by the invitee, revocation and expiry; the membership changes that follow (role change, removal, leaving), written through Wallets & Ledger as spec 010 requires; what a plan downgrade does to existing members; and a per-wallet **activity** read that tells members who added, edited or deleted what. It adds no push notification (B16 listens to its events later) and no account-deletion handover (B15).
>
> **Where the brief and the ratified decisions differ, the ratified side wins** (constitution, glossary, api-conventions, security baseline, ADR-0006/0008/0013/0017, specs 010–015):
>
> | Brief | In force | Here |
> |---|---|---|
> | `POST /wallets/{id}/invitations` creates an invitation | P-06 rule 2 and api-conventions "Writes": an Invitation is a **syncable** record, so a create is an idempotent `PUT` addressed by a client-generated UUIDv7 | `PUT /wallets/{id}/invitations/{iid}`; `GET` of one invitation added so a feed record equals its read |
> | `POST /invitations/{token}/accept` (token in the path) | P-15 rule 4: tokens must never be logged; paths are logged by every proxy | The token travels in the **body**: `POST /invitations/accept`, plus `POST /invitations/preview` and `POST /invitations/decline` |
> | "Activity feed per wallet" | Not in the glossary; the audit log records membership events for operators only | New entity **Activity Entry** (glossary first, ADR-0020): member-visible, wallet-scoped, identifiers only, not a change feed and not the audit log |
> | Invite by email | Security baseline, Uniform answers: an invitation answers identically for registered and unregistered addresses; matching uses the keyed lookup value | The create answers the same status and body either way; only the mail body differs, off the request path |
> | "On removal the client must purge local data — define the signal" | ADR-0008 rule 15, ADR-0017 rule 4: the tombstoned membership on the person's own user feed, then `404` on the wallet | No new mechanism: removal and leaving tombstone the membership and stamp `member_change_seq` |
> | Row S-02 "send **or revoke**" gated on `sharing.enabled` | P-09 rule 6: losing an entitlement must not degrade data; an owner must always be able to withdraw an offer | Spec 010 §6 amended: S-02 is **send** only; new row S-06 **revoke** (owner, no capability); new row S-07 **read activity**; S-03 gains the capability check when the new role is `editor` |
> | Accepting an invitation is a POST — does it need `Idempotency-Key`? | api-conventions, Idempotency: only the three non-upsert **money** operations carry a key | **No key.** Acceptance is idempotent by itself (token + person: an accepted token answered again for the same person returns the same membership) |

## Clarifications

### Session 2026-10-02

Owner away with delegated authority; each answer below is the recommended (most conservative) option, recorded as an **assumed default**.

- Q: How is an invitation delivered, and what does the link look like? → A: **Two channels on one record.** An email invitation sends a mail through the existing mail infrastructure carrying `{sharing.links.invitation_url}?token=…` (Identity's link pattern: 32 random bytes, base64url, SHA-256 hash stored); a link invitation returns the same URL once, in `meta.share_url` of the `201`, for the owner to share. No numeric code (a short code is guessable; a link is what chat apps carry). *Assumed default.* (FR-001, FR-003, FR-004)
- Q: Can the owner see a link invitation's link again after the first answer? → A: **No.** The secret is stored only as a hash (security baseline: tokens are hashes, "nobody after issue"); a replayed create returns the invitation without a link. To share again the owner revokes and creates a new invitation. *Assumed default.* (US2 #3)
- Q: Must the accepting account match the invited address, and may an unverified account accept? → A: **Email invitations match by lookup value; link invitations accept any signed-in person. Unverified accounts may preview, accept, decline and leave**, because the security baseline's verification gate lets an invitee read what they were invited to and bars only writes into the wallet. A mismatch is answered `404 NOT_FOUND`, exactly like a dead token, so a token holder learns nothing about who it was for. *Assumed default.* (FR-011, FR-014, FR-028)
- Q: Does accepting need `Idempotency-Key`? → A: **No.** Conventions reserve the key for three non-upsert money operations; acceptance is idempotent by token and person. *Assumed default.*
- Q: How long does an invitation live, and how does it end? → A: **7 days, single use.** Every end (accepted, declined, revoked, expired, wallet deleted) tombstones the invitation in the same write and erases the stored address, so an address is held only while an answer is awaited. An hourly sweep closes expired ones; acceptance checks the instant itself. Re-inviting an address with a pending invitation supersedes it. *Assumed default.* (FR-003, FR-006, FR-009)
- Q: What does a plan lapse do to existing members? → A: **Editors beyond the owner's quota become viewers; nobody is removed; nothing is promoted back automatically.** Ranking: owner, then joining order. Triggered by `SubscriptionLapsed` and by `WalletOwnershipTransferred`, idempotent; raising a member to editor afterwards needs sharing and room in the quota. Pending invitations stay but cannot be accepted while not entitled. *Assumed default.* (FR-015, FR-021)
- Q: Do pending invitations count toward `sharing.max_members_per_wallet`, and how does a client tell "no sharing" from "wallet full"? → A: **They count** (owner + active members + live pending invitations). Both refusals are `403 FEATURE_NOT_ENTITLED` (spec 010 already uses it for a quota); the Problem gains the optional `feature` member naming `sharing.enabled` or `sharing.max_members_per_wallet`. No new error code. *Assumed default.* (FR-022)
- Q: What happens to a departing member's records? → A: **Everything stays.** Transactions keep `created_by`; recurring rules keep their author (automatic confirmation of them is refused as `not_permitted` and shown, spec 014); Category References stay; budgets count by author in wallets the budget owner still belongs to, so the leaver's budgets stop counting that wallet (ADR-0019). *Assumed default.* (FR-020)
- Q: What is the activity feed, and how does it differ from the audit log? → A: **A new Wallets & Ledger entity, Activity Entry**: written in the same unit of work as each applied wallet write, identifiers only, readable by every member (new row S-07), newest first, not synced, kept 90 days. The audit log stays operator-only security evidence with a fixed vocabulary; the two never share rows. Recorded in ADR-0020. *Assumed default.* (FR-023, FR-024)
- Q: Who sees an email invitation's address? → A: **Every member of the wallet (row S-01), while it is pending**; it rides the wallet feed like any invitation field, sealed at rest under the inviter's data key with a keyed lookup value beside it, and erased when the invitation closes. *Assumed default.*
- Q: How are invitations rate limited? → A: **20 creates per 24 hours per inviter (`429`); 5 invitation mails per 24 hours per address across inviters, enforced silently (no mail, same answer, so the budget leaks nothing); 30 token operations (preview, accept, decline) per hour per person (`429`).** Membership changes are not limited. *Assumed default.* (FR-026)
- Q: How fast and how large? → A: Sized for 5 members and up to 20 pending invitations per wallet, and 10,000 activity entries per wallet within the 90 days. p95 targets on the dev stack with Xdebug off: invitation create, accept and membership writes ≤ 300 ms; invitation list and activity page (limit 50) ≤ 300 ms. One log line per invitation create, acceptance and sweep run, with identifiers only — never an address or token (P-15 rule 4). *Assumed default.* (SC-008, SC-009)

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Invite someone and have them join (Priority: P1)

Mona owns the "Home" wallet. She invites her husband Karim by typing his address and choosing "editor". Karim gets a mail with a link; he opens it on his phone, the app shows who invited him and to which wallet, and he accepts. From then on both see the same transactions.

**Why this priority**: sharing is the product's social surface and the only thing a paid plan unlocks (P-09 rule 5). Without it, every role in the matrix is theoretical.

**Independent Test**: as a Plus owner, create an email invitation; as the invitee (registered with that address), preview and accept it; check the invitee is an active member with the invited role, the invitation is gone from the pending list, and both a wallet-feed `invitation` tombstone and a live `wallet_member` row on the invitee's user feed exist.

**Acceptance Scenarios**:

1. **Given** an owner whose plan allows sharing and a wallet below its member quota, **When** they invite an address with role `editor` or `viewer` under a new identifier, **Then** the invitation is created pending, expires 7 days later, counts toward the quota, appears in the wallet's pending list and on its feed, and a mail with a single-use link is queued to that address.
2. **Given** the same invitation create is sent twice (a retry), **When** the second arrives, **Then** it is answered as success with the same invitation and no second invitation or mail exists.
3. **Given** the invited address belongs to no account, **When** the owner invites it, **Then** the answer is identical in status and body to inviting a registered address, and the request does the same work (no account lookup on the request path); only the mail tells the recipient to create an account with this address first.
4. **Given** a pending email invitation, **When** a signed-in person whose own address matches previews it with the token, **Then** they see the wallet's name, the inviter's display name, the role and the expiry, and no amount or balance.
5. **Given** that person accepts, **When** the acceptance is processed, **Then** they become an active member with the invitation's role in one unit of work, the invitation is closed as `accepted`, a `membership_granted` audit entry and a `joined` activity entry are written, and the membership appears on both the wallet's feed and the person's own user feed.
6. **Given** the acceptance is retried after a lost response, **When** it arrives again from the same person, **Then** it returns the same membership and changes nothing.
7. **Given** a signed-in person who is already an active member of the wallet, **When** they accept any live invitation to it, **Then** it is a no-op returning their membership; the invitation stays pending.

---

### User Story 2 - Share a link instead of an address (Priority: P1)

Mona does not know her sister's address. She creates a link invitation as "viewer", copies the link, and sends it on a messaging app. Her sister opens it, signs in, and joins.

**Why this priority**: the brief names both delivery channels, and in Egypt sharing a link in a chat is the common path.

**Independent Test**: create a link invitation and read the link from the create response; accept it as any signed-in person; a second person using the same link is refused.

**Acceptance Scenarios**:

1. **Given** an owner who may invite, **When** they create an invitation with no address, **Then** the create response carries the share link (once, in `meta`), the stored invitation carries no address, and no mail is sent.
2. **Given** a link invitation, **When** any signed-in person accepts it with its token, **Then** they become a member with its role and the link is used up; a second acceptance by someone else is answered as not found.
3. **Given** the create of a link invitation is replayed, **When** the replay is answered, **Then** it returns the invitation unchanged and **no** link (the secret exists only in the first answer); the owner revokes it and creates a new one to get a fresh link.

---

### User Story 3 - Owner manages pending invitations (Priority: P1)

Mona sees who she has invited and not heard from, and withdraws an invitation she sent to a wrong address.

**Why this priority**: invitations consume the member quota, so withdrawing one is how an owner frees a seat; a mistyped address must be correctable.

**Independent Test**: list pending invitations as owner, editor and viewer; revoke one as owner; check it disappears from the list, its token stops working, and the quota frees up.

**Acceptance Scenarios**:

1. **Given** any member of the wallet, **When** they list its invitations, **Then** they see every pending invitation with its role, channel, address (email invitations), inviter and expiry, and nothing once it is closed.
2. **Given** the owner and a pending invitation's version, **When** they revoke it, **Then** it is closed as `revoked` (a tombstone), its address is erased, its token stops working, and it no longer counts toward the quota.
3. **Given** the owner's plan no longer allows sharing, **When** they revoke a pending invitation, **Then** the revocation still succeeds.
4. **Given** an editor or viewer, **When** they try to invite or revoke, **Then** they are refused as forbidden; a non-member is told not found.
5. **Given** a pending invitation reaches its expiry, **When** the hourly sweep runs, **Then** it is closed as `expired` with its address erased; an acceptance attempted after the expiry instant is refused even if the sweep has not run.
6. **Given** a new invitation to an address that already has a pending invitation in the same wallet, **When** it is created, **Then** the earlier one is closed as `revoked` in the same unit of work, so re-sending never consumes two seats.

---

### User Story 4 - Invitee declines, or the invitation is not theirs (Priority: P2)

Karim's colleague received a mail meant for someone else. She declines it. Someone signed into a different account than the one invited is told the invitation is not valid for them.

**Why this priority**: an invitation a person cannot refuse is spam; one anyone can take is a leak.

**Independent Test**: decline an email invitation as the matching account; try to preview and accept one as a non-matching account.

**Acceptance Scenarios**:

1. **Given** a pending invitation, **When** the invited person (or, for a link, any signed-in person) declines it with its token, **Then** it is closed as `declined` with its address erased; a repeated decline by the same person succeeds with nothing changed.
2. **Given** an email invitation, **When** a person whose address does not match previews, accepts or declines it, **Then** they are answered not found, exactly as for an unknown, used, revoked or expired token, and nothing changes.
3. **Given** a person whose address is not yet verified, **When** they preview, accept or decline an invitation addressed to them, **Then** it is allowed; once a member, they can read but every write is refused with the verification code until they verify.

---

### User Story 5 - Owner changes a role or removes a member (Priority: P2)

Mona makes her sister an editor, later turns her back into a viewer, and finally removes a former flatmate.

**Why this priority**: roles gate every edit control; removal is the privacy commitment "you choose who joins, and you can remove them".

**Independent Test**: change a member's role with the membership's version; remove a member; check their user feed shows the tombstoned membership and their next wallet read is not found.

**Acceptance Scenarios**:

1. **Given** the owner and a member's current membership version, **When** they change that member's role between `editor` and `viewer`, **Then** the role moves, the membership version goes up by one, the change rides the wallet feed and the member's own user feed, and one `role_changed` audit entry and one activity entry are written.
2. **Given** the owner's plan does not allow sharing, or the wallet already has more members than the plan allows, **When** the owner raises a member to `editor`, **Then** it is refused as not entitled; lowering to `viewer` is always allowed.
3. **Given** the owner names `owner` as the new role, or names the owner as the member, **When** they send the change, **Then** it is refused (`owner` is reached only by ownership transfer); naming a non-member is not found.
4. **Given** a stale membership version, **When** the owner changes a role or removes, **Then** it is a version conflict carrying the present membership.
5. **Given** the owner and a member's version, **When** they remove that member, **Then** the membership is tombstoned, stamped on the removed person's user feed, one `membership_revoked` audit entry and one activity entry are written, and every later read or write of that wallet by that person is not found.
6. **Given** a removal already applied, **When** it is resent, **Then** it succeeds with the tombstone unchanged.
7. **Given** an editor or viewer, **When** they try to change a role or remove someone, **Then** they are refused as forbidden.

---

### User Story 6 - A member leaves (Priority: P2)

Karim's brother no longer wants to see the household wallet. He leaves it from the app.

**Why this priority**: leaving is a privacy right; it must not require the owner.

**Independent Test**: as an editor, leave with the membership version; check the tombstone on the leaver's user feed, then a 404 on the wallet; check the owner cannot leave.

**Acceptance Scenarios**:

1. **Given** an editor or viewer and their membership version, **When** they leave, **Then** their membership is tombstoned and stamped on their own user feed, one `membership_revoked` audit entry and one activity entry are written, and every later request of theirs on that wallet is not found — including a repeated leave, which the app treats as done.
2. **Given** the owner, **When** they try to leave, **Then** they are refused as forbidden; they must transfer ownership first (row W-05).
3. **Given** a member who leaves, **When** the wallet is read by the remaining members, **Then** every transaction, recurring rule and category reference the leaver created stays, with its author reference unchanged; the leaver's budgets stop counting that wallet; the leaver's display name no longer appears in activity.

---

### User Story 7 - The owner's plan lapses (Priority: P2)

Mona's Plus subscription expires. Her household wallet has three members. Nothing is deleted; her two editors become viewers, and the app explains why.

**Why this priority**: P-09 rule 6 forbids losing data on lapse, and the brief fixes the effect (members beyond the Free quota become viewers, never removed).

**Independent Test**: give an owner a wallet with two editors and one viewer; lapse the owner's subscription; check both editors are viewers, every member remains, each demotion rode the members' user feeds, and new invitations and acceptances are refused.

**Acceptance Scenarios**:

1. **Given** an owner whose subscription stops granting (expired or revoked) and a wallet with more members than the owner's plan allows, **When** the lapse is processed, **Then** every `editor` beyond the quota (counting the owner first, then members in the order they joined) becomes `viewer`, nobody is removed, and each demotion is a role change with its own stamp, audit entry (actor `system`) and activity entry (`role_limited_by_plan`).
2. **Given** the owner's plan is Free (sharing off, quota 1), **When** the lapse is processed, **Then** every member except the owner is a viewer.
3. **Given** the owner subscribes again, **When** entitlements return, **Then** nobody is promoted back automatically; the owner raises members again (US5).
4. **Given** a wallet's ownership is transferred to a person whose plan allows fewer members, **When** the transfer commits, **Then** the same demotion applies to that wallet.
5. **Given** the owner's plan no longer allows sharing, **When** anyone tries to send an invitation or accept one to that wallet, **Then** it is refused as not entitled, naming the capability.

---

### User Story 8 - See who did what (Priority: P3)

Karim opens the household wallet's activity and sees "Mona added a transaction", "You edited a transaction", "Sara joined", "Mona changed Sara's role to editor" — newest first.

**Why this priority**: the brief asks for it and shared books need accountability, but sharing works without it.

**Independent Test**: as two members, create, edit and delete a transaction and change a role; read the activity as a third member and check each act appears once, newest first, with actor, action and subject identifiers and no amount, note or name of a record.

**Acceptance Scenarios**:

1. **Given** any member, **When** they read the wallet's activity, **Then** they see entries newest first, each naming the actor (or the server), the action, the kind and identifier of the record acted on and, for role acts, the new role; the actor's display name appears only while the actor is an active member.
2. **Given** an identical replay of a write, **When** it is answered, **Then** no activity entry is written.
3. **Given** a non-member, **When** they read a wallet's activity, **Then** they are told not found.
4. **Given** an entry older than 90 days, **When** the daily purge runs, **Then** it is removed.

---

### Edge Cases

- **Quota arithmetic.** `sharing.max_members_per_wallet` counts the owner, every active member and every live pending invitation not past its expiry. Creating an invitation needs that count + 1 ≤ quota; accepting needs active members + other live pending invitations + 1 ≤ quota (the accepted invitation converts its own seat). A null quota is unlimited.
- **Invite yourself, or a current member.** Answered like any other invitation (no disclosure that an address belongs to a member); accepting is a no-op for a member.
- **Two pending invitations for the same address.** The later supersedes the earlier (US3 #6); the lookup value decides "same address".
- **Concurrent acceptances of one link.** Exactly one succeeds; the other is not found. Two acceptances of two invitations racing for the last seat: the wallet lock serialises them; the second is refused as not entitled.
- **Wallet deleted.** Every pending invitation is tombstoned as `revoked` in the deletion's unit of work; its token is dead.
- **Wallet archived.** Invitations may still be sent and accepted; archiving is not freezing (spec 010).
- **Ownership transfer.** Pending invitations stay; quota now follows the new owner's plan (demotion applies, US7 #4).
- **Removed member re-invited.** Allowed; acceptance creates a new membership record (the old one stays a tombstone).
- **Owner removing themselves / changing their own role.** Forbidden / `IMMUTABLE_FIELD` at `/role`; leaving and stepping down are W-05.
- **Leaver's recurring rules.** They stay; their automatic confirmation is refused as `not_permitted` from then on (spec 014) and shown, not retried; remaining editors may edit, end or delete them (row R-02).
- **Leaver's categories.** The Category References already in the wallet stay so history renders (spec 011); the leaver gains no right over anything and loses the wallet.
- **Budget attribution.** B8 counts by `created_by` in wallets the budget owner currently belongs to: the leaver's budgets stop counting the wallet; others' are unchanged; a demoted viewer's budgets still count it.
- **Invitation mail flood.** One inviter may send at most 20 invitations a day (then `429`); one address receives at most 5 invitation mails a day across all inviters — beyond that the invitation is still created and answered identically, but no mail is sent (so the budget discloses nothing).
- **Mail failure.** The mail job retries (3 attempts with backoff); a final failure is logged with the invitation id only. The invitation stays pending and counted; the owner can revoke it and send a new one. The create's answer never depends on the mail.
- **Accepting on a removed or former member's account.** Treated as any non-member: a live invitation makes them a member again with a new membership record.
- **Token guessing.** Tokens carry 256 random bits and are stored only as hashes; preview, accept and decline share a budget of 30 per hour per person.
- **Invitee without an account.** The mail tells them to create an account with that address, verify it, then open the link; the invitation waits until it expires.
- **Lapse not yet processed.** Entitlements follow stored dates (spec 009 FR-013): new invitations and acceptances are refused at once; the demotion happens when the hourly lapse runs.

## Requirements *(mandatory)*

### Functional Requirements

**Invitations**

- **FR-001**: The owner MUST be able to create an invitation to a wallet, addressed by a client-generated UUIDv7 and carrying a role (`editor` or `viewer`) and either an invitee address (email channel) or no address (link channel). A repeated create MUST NOT produce a second invitation or a second mail (P-06 rule 2).
- **FR-002**: An invitation MUST be immutable once created: an identical resend succeeds unchanged (whatever version it carries); a resend that differs in `role` or `invitee_email` is refused with `IMMUTABLE_FIELD` at that member; any write to a closed invitation (other than revoke) is a version conflict carrying its tombstone; a version above 0 for an identifier that matches no record is not found (nothing is created).
- **FR-003**: An invitation MUST expire 7 days after creation. A token MUST be single-use, 256 random bits, delivered only to the mailbox (email channel) or only in the create's first answer (link channel), and stored only as a hash.
- **FR-004**: The share and mail link MUST be `{configured invitation URL}?token={token}`, opened by the app's deep link; it MUST NOT carry the wallet's name, an amount or a balance. The mail MUST NOT name the wallet or carry any amount; it names the inviter's display name (or a neutral phrase) and the role, in the recipient's language when they have an account, else the inviter's (P-14).
- **FR-005**: Creating an invitation MUST answer identically (status, body) whether or not the address belongs to an account, and the request MUST do the same work either way: no account lookup by address happens on the request path; the queued mail job alone decides the mail body (security baseline, Uniform answers). Address matching MUST use the keyed lookup value.
- **FR-006**: A new invitation to an address that already has a live pending invitation in the same wallet MUST close the earlier one as `revoked` in the same unit of work.
- **FR-007**: Any member MUST be able to list a wallet's pending invitations and read one invitation; a closed invitation is visible only as a tombstone (single read and feeds), never in the list.
- **FR-008**: The owner MUST be able to revoke a pending invitation, carrying its version, whatever their plan. Revoking a closed invitation MUST return it unchanged.
- **FR-009**: Every closing of an invitation (accepted, declined, revoked, expired, wallet deleted) MUST tombstone it, record its status, and erase its stored address and lookup value in the same write. A sweep MUST close past-expiry invitations hourly; acceptance MUST check the expiry instant itself.
- **FR-010**: A signed-in person holding a live token MUST be able to preview the invitation (wallet name, inviter display name, role, expiry, whether they are already a member) without any amount, balance or the invited address. A token that is no longer live (including one this person already accepted) is not found to preview; retrying an acceptance goes through accept (FR-012).
- **FR-011**: A signed-in person holding a live token MUST be able to accept it. For an email invitation, the person's own address lookup value MUST equal the invitation's; otherwise, and for any token that is unknown, used, revoked, expired or of a deleted wallet, the answer MUST be not found. Acceptance MUST, in one unit of work under the wallet lock, re-check the owner's `sharing.enabled` and the quota, create the membership with the invitation's role through Wallets & Ledger's application service (stamping `member_change_seq`, ADR-0017), and close the invitation as `accepted`.
- **FR-012**: Acceptance by a person who is already an active member MUST return their membership and change nothing (the invitation stays pending). Acceptance resent by the person who accepted MUST return the same membership while it is active.
- **FR-013**: A person holding a live token MUST be able to decline it (same matching rule as FR-011); a repeated decline by the same person MUST succeed unchanged.
- **FR-014**: Preview, accept and decline MUST be open to a person whose address is not verified; the verification gate keeps refusing their writes to the wallet once they are a member.

**Membership changes** (written by Wallets & Ledger; spec 010 FR-018)

- **FR-015**: The owner MUST be able to change another member's role between `editor` and `viewer`, carrying the membership's version. Sending the role the member already holds MUST succeed with nothing changed (a replay). `owner` as a target role, or the owner as the target member, MUST be refused with `IMMUTABLE_FIELD` at `/role`. Raising to `editor` MUST be refused as not entitled unless the owner's plan allows sharing and the active member count is within the quota.
- **FR-016**: The owner MUST be able to remove another member, carrying the membership's version. When that person's latest membership of the wallet is already a tombstone (removed, left, or the wallet deleted), the removal MUST return it unchanged. A person who was never a member is not found.
- **FR-017**: An editor or viewer MUST be able to leave, carrying their membership's version. The owner MUST NOT be able to leave (row S-05). After leaving, every request of that person on the wallet is not found (ADR-0008 rule 15).
- **FR-018**: Every membership write (grant, role change, removal, leave, demotion) MUST stamp `member_change_seq` from the member's user counter in the same unit of work, after the wallet lock and before any other user lock, several people in ascending user id (ADR-0017 rules 2, 5), and MUST take its own `change_seq` from the wallet's counter with the wallet record taking the last value (spec 010 FR-012). Removal and leave tombstone the membership; the removed person's tombstoned membership on their own user feed is the "purge this wallet" signal.
- **FR-019**: Wallets & Ledger MUST write exactly one audit entry per membership act: `membership_granted` (accept), `membership_revoked` (removal, leave; detail says which), `role_changed` (owner change, plan demotion with actor `system`), naming identifiers only.
- **FR-020**: A departing member's records MUST stay: transactions keep their author, recurring rules keep their author and wallet, Category References stay; nothing they authored is moved, reassigned or deleted.

**Downgrade**

- **FR-021**: When a wallet owner's subscription stops granting (a lapse to expired or revoked) or a wallet's ownership moves to a new owner, each live wallet that owner owns MUST be brought within the owner's `sharing.max_members_per_wallet`: members are ranked owner first, then by joining order; every `editor` ranked beyond the quota becomes `viewer`. Nobody is removed, nothing is deleted (P-09 rule 6). The enforcement MUST be idempotent and MUST NOT promote anyone.
- **FR-022**: Sending an invitation and accepting one MUST be refused as not entitled when the owner's plan lacks `sharing.enabled`, and when the quota arithmetic of the Edge Cases ("Quota arithmetic") would be exceeded; the refusal MUST name the capability (`sharing.enabled` or `sharing.max_members_per_wallet`).

**Activity**

- **FR-023**: Every applied (non-replay) write to a wallet's own fields (edit, archive, ownership transfer — not its creation or deletion, which no other member can read), its transactions, recurring rules, occurrences, memberships and invitations MUST record one Activity Entry in the same unit of work: actor (a person, or the server), action, subject kind, subject identifier and, for membership and invitation acts, the role. No amount, note, name of a record, address or display name is stored. A transaction produced by confirming an occurrence is covered by the occurrence's `confirmed` entry.
- **FR-024**: Any member MUST be able to read a wallet's activity newest first, paged with the shared cursor; each entry shows the actor's display name only while the actor is an active member. Entries MUST be removed after 90 days.

**Sync, rate limits, events**

- **FR-025**: Invitations MUST ride the wallet feed as `invitation` rows (identical to their read), positioned by the wallet's counter; they are not batch items (each write sends mail or returns a secret). Pending invitations MUST be tombstoned by the wallet deletion cascade, and purged with other tombstones after 90 days.
- **FR-026**: Creating invitations MUST be limited to 20 per 24 hours per inviter (`429 RATE_LIMITED` with `Retry-After`; only applied creates count, a replay never does); invitation mails to one address MUST be limited to 5 per 24 hours across inviters, silently (no mail, same answer); preview, accept and decline MUST share a budget of 30 per hour per person.
- **FR-027**: Sending, accepting, removing or leaving, and role changes MUST each publish a domain event after commit carrying identifiers and scalars only (P-11).
- **FR-028**: Invite, revoke, role change and removal MUST sit behind the verification gate (`403 EMAIL_NOT_VERIFIED`); preview, accept, decline and leave MUST NOT (FR-014, and a person must always be able to leave).

### Key Entities

- **Invitation** (glossary): an offer to join a wallet with a role, sent to one address or carried by one link; pending until accepted, declined, revoked or expired.
- **Wallet Member** (glossary): changed here by acceptance (created), role change, removal and leave (tombstoned).
- **Activity Entry** (new, glossary): one recorded act in a wallet, readable by its members.

## Project Sections *(mandatory for feature specs)*

### 1. Purpose

This feature lets a wallet owner share a wallet with the people they live and spend with, and stay in control of it: invite by address or link, see and withdraw pending invitations, change what each member may do, remove a member, and let any member leave on their own. It keeps every guarantee already made — a stranger learns nothing, a removed person's phone is told to forget the wallet, a lapsed plan never deletes data — and gives members a plain record of who did what.

### 2. Glossary terms

- [Wallet](../../docs/glossary.md#wallet-محفظة), [Wallet Member](../../docs/glossary.md#wallet-member), [Invitation](../../docs/glossary.md#invitation), [Entitlement](../../docs/glossary.md#entitlement), [Subscription](../../docs/glossary.md#subscription), [Category Reference](../../docs/glossary.md#category-reference), [Audit Entry](../../docs/glossary.md#audit-entry), [Change Row](../../docs/glossary.md#change-row), [Purge Horizon](../../docs/glossary.md#purge-horizon): as defined.
- **Activity Entry** (new): added to the glossary in this change, before use (task T002), as a Wallets & Ledger entity, persisted, **not synced** (read endpoint only), with notes on how it differs from the Audit Entry.
- **Invitation token**, **share link**, **channel** (`email` | `link`): added to the Invitation entry's notes.
- **Seat**: not introduced; the spec says "counts toward the quota".
- **Plan demotion**: added to the Wallet Member entry's notes (a role change by the server when the owner's quota shrinks).

### 3. Data model

| Entity | Module | Key fields | Scope | Lifecycle |
|---|---|---|---|---|
| Invitation | Sharing (new module `app/Modules/Sharing`) | `id` (UUIDv7, client), `wallet_id`, `invited_by` (user id), `role` (`editor` \| `viewer`), `channel` (`email` \| `link`, derived at create), address sealed under the inviter's data key + keyed lookup value (both null for `link`, erased when closed), token hash (unique), `status` (`pending` \| `accepted` \| `declined` \| `revoked` \| `expired`), `expires_at`, responder user id (internal), `version`, `change_seq` (wallet counter), `created_at`, `updated_at`, `deleted_at` | `wallet_id` | `pending` (live) → any closed status, always tombstoned in the same write |
| Wallet Member | Wallets & Ledger (spec 010) | unchanged; removal and leave set `deleted_at` (glossary state `revoked`); a role change stays active | `wallet_id`, plus `member_change_seq` on the member's user feed | `active` → `revoked` (tombstone) |
| Activity Entry | Wallets & Ledger | `id` (UUIDv7, server), `wallet_id`, `occurred_at`, `actor_user_id` (null = the server), `action`, `subject_type`, `subject_id`, `role` (nullable) | `wallet_id` (not a sync scope member; never on a feed) | append-only; removed after 90 days |

Only one live pending invitation per (wallet, address lookup value). The address and lookup value exist only while the invitation is pending.

### 4. Invariants

| Invariant | Principle or decision | Test that fails when broken |
|---|---|---|
| A token is single-use, stored only as a hash, and dead once the invitation closes | FR-003, baseline | `tests/Feature/Sharing/AcceptInvitationTest.php` |
| Create answers identically for registered and unregistered addresses | FR-005, baseline Uniform answers | `tests/Contract/Sharing/UpsertInvitationTest.php` |
| Every closing tombstones the invitation and erases its address | FR-009 | `tests/Feature/Sharing/InvitationLifecycleTest.php` |
| Owner + members + live pending invitations never exceed the quota through an invite or an accept | FR-022, P-09 | `tests/Feature/Sharing/MemberQuotaTest.php` |
| An email invitation is accepted only by the matching address | FR-011 | `tests/Feature/Sharing/AcceptInvitationTest.php` |
| Exactly one active owner remains; the owner cannot leave or be removed | spec 010 FR-015, S-05 | `tests/Feature/Wallets/MembershipChangesTest.php` |
| Every membership write stamps `member_change_seq` and its own `change_seq`, wallet last | ADR-0017, spec 010 FR-012 | `tests/Feature/Wallets/MembershipChangesTest.php` |
| Removal and leave appear as tombstoned memberships on the person's user feed, then 404 | ADR-0008 rule 15 | `tests/Contract/Sync/MembershipRevocationFeedTest.php` |
| One audit entry per membership act, identifiers only | FR-019, baseline | `tests/Feature/Wallets/MembershipChangesTest.php` |
| Downgrade demotes editors beyond the quota, removes nobody, never promotes, idempotent | FR-021, P-09 rule 6 | `tests/Feature/Sharing/DowngradeDemotionTest.php` |
| Promotion to editor is refused without sharing or beyond quota | FR-015 | `tests/Feature/Sharing/MemberQuotaTest.php` |
| Every applied wallet write records exactly one activity entry; a replay records none | FR-023 | `tests/Feature/Wallets/ActivityRecordingTest.php` |
| Activity carries no amount, note, record name, address or display name at rest | FR-023, baseline | `tests/Feature/Wallets/ActivityRecordingTest.php` |
| Wallet deletion tombstones its pending invitations in the same unit of work | spec 010 FR-010 | `tests/Feature/Sharing/InvitationLifecycleTest.php` |
| Non-member gets 404, under-privileged member 403, for S-01…S-07 | P-08, spec 010 §6 | `tests/Feature/Wallets/PermissionMatrixTest.php`, `tests/Unit/Wallets/PermissionMatrixTest.php` |

### 5. API

Under `/api/v1`, in `docs/openapi/paths/sharing.yaml`, **frozen for the C9 client** in this design phase (contract first). `info.version` moves from v1.8 to **v1.9** (additive). **No new error code.** Shapes are defined in the path file; the members the client binds to are listed here.

| Method | Path | operationId | Tag | Success | Error codes (beyond the shared 400/401/406/415/426/500 refusals) |
|---|---|---|---|---|---|
| GET | `/wallets/{id}/invitations` | `listInvitations` | Sharing | `200` collection | `NOT_FOUND`, `INVALID_ID`, `INVALID_CURSOR`, `INVALID_QUERY_PARAMETER`, `SORT_FIELD_NOT_SUPPORTED`, `FILTER_FIELD_NOT_SUPPORTED` |
| GET | `/wallets/{id}/invitations/{iid}` | `getInvitation` | Sharing | `200` | `NOT_FOUND`, `INVALID_ID` |
| PUT | `/wallets/{id}/invitations/{iid}` | `upsertInvitation` | Sharing | `201` create (link: `meta.share_url`), `200` replay | `EMAIL_NOT_VERIFIED`, `FORBIDDEN`, `FEATURE_NOT_ENTITLED`, `NOT_FOUND`, `VERSION_CONFLICT`, `INVALID_ID`, `VALIDATION_FAILED`, `RATE_LIMITED` |
| DELETE | `/wallets/{id}/invitations/{iid}` | `revokeInvitation` | Sharing | `200` with the tombstone | `EMAIL_NOT_VERIFIED`, `FORBIDDEN`, `NOT_FOUND`, `VERSION_CONFLICT`, `INVALID_ID`, `VALIDATION_FAILED` |
| POST | `/invitations/preview` | `previewInvitation` | Sharing | `200` | `NOT_FOUND`, `VALIDATION_FAILED`, `RATE_LIMITED` |
| POST | `/invitations/accept` | `acceptInvitation` | Sharing | `200` the membership | `NOT_FOUND`, `FEATURE_NOT_ENTITLED`, `VALIDATION_FAILED`, `RATE_LIMITED` |
| POST | `/invitations/decline` | `declineInvitation` | Sharing | `204` | `NOT_FOUND`, `VALIDATION_FAILED`, `RATE_LIMITED` |
| PATCH | `/wallets/{id}/members/{uid}` | `changeMemberRole` | Sharing | `200` the membership | `EMAIL_NOT_VERIFIED`, `FORBIDDEN`, `FEATURE_NOT_ENTITLED`, `NOT_FOUND`, `VERSION_CONFLICT`, `INVALID_ID`, `VALIDATION_FAILED` |
| DELETE | `/wallets/{id}/members/{uid}` | `removeMember` | Sharing | `200` with the tombstone | `EMAIL_NOT_VERIFIED`, `FORBIDDEN`, `NOT_FOUND`, `VERSION_CONFLICT`, `INVALID_ID`, `VALIDATION_FAILED` |
| POST | `/wallets/{id}/leave` | `leaveWallet` | Sharing | `200` with the tombstone | `FORBIDDEN`, `NOT_FOUND`, `VERSION_CONFLICT`, `INVALID_ID`, `VALIDATION_FAILED` |
| GET | `/wallets/{id}/activity` | `listWalletActivity` | Wallets & Ledger | `200` collection | `NOT_FOUND`, `INVALID_ID`, `INVALID_CURSOR`, `INVALID_QUERY_PARAMETER`, `SORT_FIELD_NOT_SUPPORTED`, `FILTER_FIELD_NOT_SUPPORTED` |

**Invitation** (response; composes `SyncableResource` + `WalletScoped`): `id`, `wallet_id`, `version`, `change_seq`, `created_at`, `updated_at`, `deleted_at`, `role` (`editor` | `viewer`), `channel` (`email` | `link`), `invitee_email` (string while a pending email invitation, else null), `invited_by` (user id), `status`, `expires_at`.

**Invitation write** (`PUT`): `role` (required), `invitee_email` (required member, string or null; null = link channel; 3–254 characters, address shape), `version` (omitted or 0 to create). `DELETE`: `{ "version": n }`. Anything else is `UNKNOWN_FIELD`.

**Create meta**: `201` of a link invitation: `meta.share_url` (string). Every other answer: `meta: {}`.

**Token bodies** (`preview`, `accept`, `decline`): `{ "token": "<43 base64url characters>" }`; a malformed token is `422` `INVALID_TYPE`/`OUT_OF_RANGE` at `/token`.

**Invitation preview**: `invitation_id`, `wallet_id`, `wallet_name`, `role`, `invited_by_display_name` (string or null), `expires_at`, `already_member` (boolean).

**Accept** answers `200` with the **Wallet Member** of spec 010 (unchanged schema).

**Membership bodies**: `PATCH /wallets/{id}/members/{uid}`: `{ "role": "editor" | "viewer", "version": n }`; `DELETE` and `leave`: `{ "version": n }` (the membership's version). Responses are the Wallet Member (tombstone for removal and leave). `{uid}` is the member's user id (`member_user_id`).

**Activity Entry** (response; not syncable): `id`, `wallet_id`, `occurred_at`, `actor_user_id` (uuid or null = the server), `actor_display_name` (string or null), `action` (`created` | `edited` | `deleted` | `restored` | `archived` | `ownership_transferred` | `confirmed` | `skipped` | `joined` | `left` | `removed` | `role_changed` | `role_limited_by_plan` | `invited` | `invitation_revoked`), `subject_type` (`wallet` | `transaction` | `recurring_rule` | `occurrence` | `member` | `invitation`), `subject_id` (uuid; for `member`, the member's user id), `role` (`owner` | `editor` | `viewer` or null). Ordered by `occurred_at` descending, stabilised by `id`; no filters or sorts.

**Refusal shape for entitlements**: `403 FEATURE_NOT_ENTITLED` gains the optional Problem extension member `feature` (`sharing.enabled` or `sharing.max_members_per_wallet`), added to the shared `Problem` schema as an additive member, so the client tells "plan has no sharing" from "wallet is full" without a new code.

**Sync**: the wallet feed's `ChangeRow.type` gains `invitation` (from v1.9), `record` gains `Invitation`; documented in `docs/openapi/paths/sync.yaml`.

**Rate limiting**: `upsertInvitation`, `previewInvitation`, `acceptInvitation`, `declineInvitation` document `429` with `Retry-After` and the `RateLimit-*` headers.

Field-level codes: `IMMUTABLE_FIELD` at `/role` (changing an invitation's role; `owner` target or owner member on role change) and at `/invitee_email`; `INVALID_ENUM_VALUE` at `/role`; plus the shared `REQUIRED`, `UNKNOWN_FIELD`, `INVALID_TYPE`, `OUT_OF_RANGE`, `INVALID_ID`.

### 6. Authorization and entitlements

The permission matrix in [spec 010 §6](../010-wallets-membership/spec.md) is the single source (P-08 rule 5). This feature **amends** it (task T003), as a docs change paired with the `PermissionMatrix` code and its unit test:

- **S-02** becomes "Send an invitation" — owner; capability `sharing.enabled`, and `sharing.max_members_per_wallet` not exceeded (pending invitations counted).
- **S-03** gains: when the new role is `editor`, the owner's `sharing.enabled` and the active member count within `sharing.max_members_per_wallet`.
- **S-06** (new) "Revoke a pending invitation" — owner; no capability.
- **S-07** (new) "Read the wallet's activity" — owner, editor, viewer; no capability.

Rows used here: S-01 (list/read invitations), S-02 (create), S-06 (revoke), S-03 (role change), S-04 (remove), S-05 (leave), S-07 (activity). A caller who is not an active member gets **404** on each. Outside the matrix (the caller is not yet a member): **preview, accept, decline** are authorized by the token (and, for email invitations, the address match), and accept checks the wallet **owner's** entitlements through `EntitlementService::forWallet` (P-09 rule 4). Plan demotion is a server act, not a member act.

**Precedence** for a wallet write here: `401` → `403 EMAIL_NOT_VERIFIED` (gated routes) → `422 INVALID_ID` → `429 RATE_LIMITED` (create) → `422 VALIDATION_FAILED` (shape) → `404 NOT_FOUND` (non-member; unknown invitation or member) → `403 FORBIDDEN` (role) → `403 FEATURE_NOT_ENTITLED` → `409 VERSION_CONFLICT` for a write to a tombstone (except revoke/removal, which return it) → `200` replay → `409` stale → `422` state rules (`IMMUTABLE_FIELD`) → write. For token operations: `401` → `422` body → `429` → `404` (token not live, address mismatch) → `200` already member (accept) → `403 FEATURE_NOT_ENTITLED` → write.

### 7. Events

| Event | Publisher | Payload | Listeners |
|---|---|---|---|
| `InvitationSent` (new) | Sharing | event id, invitation id, wallet id, inviter id, channel, role | Sharing's own mail job is dispatched directly (not via the event); B16 later |
| `InvitationAccepted` (constitution) | Sharing | event id, invitation id, wallet id, member user id, role | B16 later |
| `MemberRevoked` (constitution) | Sharing | event id, wallet id, member user id, actor user id, reason (`removed` \| `left`) | B16 later |
| `MemberRoleChanged` (new) | Sharing | event id, wallet id, member user id, new role, reason (`owner` \| `plan`) | B16 later |
| `SubscriptionLapsed` (consumed) | Billing | user id, status | Sharing: queued, idempotent `EnforceMemberQuota` for each live wallet the user owns |
| `WalletOwnershipTransferred` (consumed) | Wallets | wallet id, new owner id | Sharing: queued, idempotent `EnforceMemberQuota` for that wallet |

Dispatched after commit; identifiers and scalars only (P-11). The constitution's Sharing row gains `InvitationSent` and `MemberRoleChanged` (PATCH v1.3.9).

### 8. Acceptance scenarios

| Story · scenario | Layer | Test file |
|---|---|---|
| US1 · 1–3 create, replay, uniform answer | Feature, Contract | `tests/Feature/Sharing/SendInvitationTest.php`, `tests/Contract/Sharing/UpsertInvitationTest.php` |
| US1 · 4 preview | Contract | `tests/Contract/Sharing/PreviewInvitationTest.php` |
| US1 · 5–7 accept | Feature, Contract | `tests/Feature/Sharing/AcceptInvitationTest.php`, `tests/Contract/Sharing/AcceptInvitationTest.php` |
| US2 · 1–3 link | Feature, Contract | `tests/Feature/Sharing/SendInvitationTest.php`, `tests/Contract/Sharing/UpsertInvitationTest.php` |
| US3 · 1–6 list, revoke, expiry, supersede | Feature, Contract | `tests/Feature/Sharing/InvitationLifecycleTest.php`, `tests/Contract/Sharing/ListInvitationsTest.php`, `tests/Contract/Sharing/RevokeInvitationTest.php` |
| US4 · 1–3 decline, mismatch, unverified | Feature, Contract | `tests/Feature/Sharing/AcceptInvitationTest.php`, `tests/Contract/Sharing/DeclineInvitationTest.php` |
| US5 · 1–7 role change, removal | Feature, Contract | `tests/Feature/Wallets/MembershipChangesTest.php`, `tests/Contract/Sharing/ChangeMemberRoleTest.php`, `tests/Contract/Sharing/RemoveMemberTest.php` |
| US6 · 1–3 leave | Feature, Contract | `tests/Feature/Wallets/MembershipChangesTest.php`, `tests/Contract/Sharing/LeaveWalletTest.php`, `tests/Contract/Sync/MembershipRevocationFeedTest.php` |
| US7 · 1–5 downgrade | Unit, Feature | `tests/Unit/Sharing/MemberQuotaRulesTest.php`, `tests/Feature/Sharing/DowngradeDemotionTest.php` |
| US8 · 1–4 activity | Feature, Contract | `tests/Feature/Wallets/ActivityRecordingTest.php`, `tests/Contract/Wallets/ListWalletActivityTest.php` |

### 9. Open questions

1. **Automatic re-promotion after re-subscribing.** Not offered (US7 #3); a later additive change could remember the pre-demotion role. Does not block.
2. **Push notifications** for invitations and membership changes: B16, from the events in §7. Does not block.
3. **Account deletion of an owner or member** (handover, invitations of a deleted inviter): B15. Does not block.
4. **Web landing page for the invitation URL** (desktop opening): website scope, not this repository's API. Does not block.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An invitee with an account goes from opening the link to seeing the shared wallet in under 1 minute, with no step outside the app except opening the mail.
- **SC-002**: 0 responses reveal whether an invited address has an account, or whether a wallet exists to someone outside it.
- **SC-003**: 100% of removals and departures reach the removed person's phone as a "forget this wallet" signal on its next sync, and 100% of that person's later requests on the wallet are refused as not found.
- **SC-004**: 0 records are deleted and 0 members removed by a plan lapse; 100% of editors beyond the quota are viewers within 1 hour of the lapse being processed.
- **SC-005**: A wallet never holds more members plus pending invitations than its owner's plan allows through an invite or acceptance, under concurrent acceptances.
- **SC-006**: Every applied write in a shared wallet appears exactly once in its activity, readable by every member, with no amount or note.
- **SC-007**: No combination of inviters can cause more than 5 invitation mails a day to reach one address.
- **SC-008**: Sending an invitation, accepting one and every membership change complete within 300 ms at the 95th percentile; the invitation list and an activity page of 50 entries over 10,000 entries load within 300 ms at the 95th percentile.
- **SC-009**: 0 log lines, audit entries or activity entries contain an invited address or a token.

## Assumptions

- Plus allows 5 members per wallet including the owner; Free allows 1 (the owner) with sharing off (spec 009 FR-004).
- The app registers the configured invitation URL as an Android App Link and reads the `token` query parameter; until the website serves that path, a desktop browser opening it sees nothing useful (Open question 4).
- Mail delivery uses the existing mail infrastructure (Mailpit in development); no new dependency.
- Invitation tokens follow Identity's link secret pattern (32 random bytes, base64url, SHA-256 hash stored).
- Display names come from Identity's `DisplayNames` and may be null.
- Shared-wallet QA in specs 010–015 seeded members directly; from this feature on, members arrive through invitations.
