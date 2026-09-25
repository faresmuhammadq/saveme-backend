# B9 · Sharing & invitations

**Call with**: `/speckit-specify B9` · **Source**: the Phase B brief set, recorded 2026-09-25

## Description

A wallet owner invites another person by email or shareable link with a role (editor/viewer); the invitee accepts in the app (must have an account), the owner can change roles, remove members, and members can leave. Endpoints: `POST /wallets/{id}/invitations`, `GET /wallets/{id}/invitations`, `DELETE …/{iid}`, `POST /invitations/{token}/accept`, `PATCH /wallets/{id}/members/{uid}`, `DELETE /wallets/{id}/members/{uid}`, `POST /wallets/{id}/leave`. An activity feed per wallet records member actions (who added/edited/deleted what).

## Considerations

Every invitation and acceptance is gated by `EntitlementService::forWallet` (`sharing.enabled`, `sharing.max_members_per_wallet`) — pending invitations count toward the quota. Invitation tokens are single-use, 7-day expiry. Acceptance by a user who is already a member is a no-op. When the owner's plan lapses, members beyond the Free quota are demoted to viewer (rule from spec 3), never removed. Sync: members receive the wallet's changes feed from acceptance onward; on removal the client must purge local data — define the signal. Notifications hook for spec 16.

## Reconcile at specify time

The brief predates decisions ratified since. These collide with it; the ratified side wins unless the spec deliberately supersedes it through a decision record or an amendment.

| Brief says | Already decided | Resolution needed |
|---|---|---|
| Activity feed per wallet | Not in the glossary; the audit log records membership events but not "who edited what" in the ledger | New entity: add to the glossary first, and decide how it differs from the audit log |
| Invite by email | Security baseline: invitation answers identically for registered and unregistered addresses; matching uses the keyed lookup value | Required |
| "On removal the client must purge" | ADR-0008 rule 15 fixes the signal: the revocation appears in the user feed, then the wallet returns 404 | Already defined; the privacy page says a removed member's device keeps what it synced |
