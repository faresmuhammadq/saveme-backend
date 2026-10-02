# Contract: Sharing & Invitations (frozen for C9)

Source of truth: [`docs/openapi/paths/sharing.yaml`](../../../docs/openapi/paths/sharing.yaml), indexed from `docs/openapi/openapi.yaml`, `info.version` **v1.9**. Additive changes elsewhere: `components/schemas.yaml` (`Problem.feature`), `paths/sync.yaml` (`invitation` wallet-feed rows). No new error code.

## What C9 asked for, answered

| C9 need | Answer |
|---|---|
| The purge signal | A **tombstoned `wallet_member` row on the person's own user feed** (`GET /sync/user/changes`), emitted for removal, leaving and wallet deletion (ADR-0017 rule 4). After it, every request on that wallet is `404`. A removed member's device keeps what it already synced (privacy commitments). |
| Invitation link format | `{invitation URL}?token={43 base64url chars}`; dev default `http://saveme-backend.test/invite?token=…`. Email: the link is only in the mail. Link channel: returned once in `meta.share_url` of the `201` create. The link names no wallet and no amount. Call `POST /invitations/preview` with `{token}` for the confirmation screen, then `accept` or `decline`. |
| Quota error codes | `403 FEATURE_NOT_ENTITLED` with Problem member `feature`: `sharing.enabled` (plan has no sharing) or `sharing.max_members_per_wallet` (owner + members + live pending invitations at the limit). Pending invitations count. Revoke frees a seat. |
| Activity feed shape | `GET /wallets/{id}/activity` → `ActivityEntry {id, wallet_id, occurred_at, actor_user_id, actor_display_name, action, subject_type, subject_id, role}`, newest first, cursor-paged, 90 days, no amounts or names; resolve `subject_id` locally. `role_limited_by_plan` explains a plan demotion. |

## Operations

| operationId | Method and path | Success | Notes |
|---|---|---|---|
| `listInvitations` | GET `/wallets/{id}/invitations` | 200 | pending only; S-01 |
| `getInvitation` | GET `/wallets/{id}/invitations/{iid}` | 200 | tombstones too |
| `upsertInvitation` | PUT `/wallets/{id}/invitations/{iid}` | 201 / 200 replay | S-02; 429; `meta.share_url` on link create |
| `revokeInvitation` | DELETE `/wallets/{id}/invitations/{iid}` | 200 tombstone | S-06; no entitlement |
| `previewInvitation` | POST `/invitations/preview` | 200 | unverified allowed; 429 |
| `acceptInvitation` | POST `/invitations/accept` | 200 `WalletMember` | unverified allowed; 403 `FEATURE_NOT_ENTITLED`; 429 |
| `declineInvitation` | POST `/invitations/decline` | 204 | unverified allowed; 429 |
| `changeMemberRole` | PATCH `/wallets/{id}/members/{uid}` | 200 `WalletMember` | S-03; editor needs entitlement |
| `removeMember` | DELETE `/wallets/{id}/members/{uid}` | 200 tombstone | S-04 |
| `leaveWallet` | POST `/wallets/{id}/leave` | 200 tombstone | S-05; unverified allowed; owner 403 |
| `listWalletActivity` | GET `/wallets/{id}/activity` | 200 | S-07 |

Every non-member gets `404` on a wallet path. Every token problem (unknown, used, revoked, declined, expired, deleted wallet, address mismatch) is `404`.

## Client behaviour notes

- Keep the role cached with the wallet from the membership row; a role change or demotion arrives as a live `wallet_member` row on both feeds.
- On a tombstoned own `wallet_member` row: purge the wallet locally; a later `404` from that wallet is expected.
- A repeated `leave` answers `404`: treat as done.
- Accept of a link already used by you answers 200 with your membership while it is active.
- A replay of a link create returns no `share_url`; to share again, revoke and create a new invitation.
- Do not put amounts or balances in share text.
