# Quickstart: Sharing & Invitations (spec 016)

Validation guide; contracts in [contracts/sharing-api.md](contracts/sharing-api.md), shapes in `docs/openapi/paths/sharing.yaml`.

## Prerequisites

```bash
dev project open                 # environment up; mail lands in http://mailpit.test
dev migrate
dev exec -- composer test:arch   # gates green before starting
```

Two verified accounts: **owner** (with a manual Plus grant, spec 009 operator grant) and **invitee**; a third account **other**. Headers on every call: `Authorization: Bearer …`, `X-Client-Platform: android`, `X-Client-Version: 1.0.0`, `Accept: application/json`.

## Scenarios

1. **Email invitation** — owner `PUT /api/v1/wallets/{w}/invitations/{uuidv7}` `{"role":"editor","invitee_email":"<invitee>"}` → 201, `meta: {}`; Mailpit shows one mail with `…/invite?token=…`, no wallet name, no amount. Repeat the PUT → 200, no second mail.
2. **Uniform answer** — invite an unregistered address → same 201 shape; Mailpit shows the "create an account first" mail.
3. **Preview and accept** — invitee `POST /api/v1/invitations/preview` `{"token":…}` → 200 with `wallet_name`; `POST /invitations/accept` → 200 `WalletMember` role `editor`. `GET /sync/user/changes` (invitee) shows a live `wallet_member`; the wallet feed shows the `invitation` tombstone (`status: accepted`). Accept again → 200, same membership.
4. **Mismatch** — **other** previews the same kind of email invitation → 404.
5. **Link invitation** — owner PUT with `"invitee_email": null` → 201 with `meta.share_url`; **other** accepts → 200; invitee accepts the same token → 404.
6. **Quota** — with Plus (5): fill to 5 with members + pending; the next PUT → 403 `FEATURE_NOT_ENTITLED`, `feature: sharing.max_members_per_wallet`; revoke one (`DELETE …/invitations/{iid}` `{"version":1}`) → 200 tombstone; the PUT now succeeds.
7. **Role change and removal** — owner `PATCH /wallets/{w}/members/{invitee}` `{"role":"viewer","version":1}` → 200; `DELETE …/members/{invitee}` `{"version":2}` → 200 tombstone; invitee's user feed shows the tombstone; invitee `GET /wallets/{w}` → 404.
8. **Leave** — a member `POST /wallets/{w}/leave` `{"version":n}` → 200; again → 404. Owner leave → 403.
9. **Downgrade** — revoke the owner's grant (operator), run `dev billing:lapse-subscriptions` and `dev queue:work --once` (or `--stop-when-empty`); editors are viewers, activity shows `role_limited_by_plan`; a new PUT → 403 `feature: sharing.enabled`; revoking still works.
10. **Activity** — create, edit and delete a transaction as two members; `GET /wallets/{w}/activity` shows each once, newest first, with no amount.
11. **Expiry** — travel a pending invitation's `expires_at` into the past (tinker, test data only), accept → 404; `dev sharing:expire-invitations` closes it as `expired`.
12. **Rate limits** — 21st create in a day → 429 with `Retry-After`; 31st token call in an hour → 429.

## Suites

```bash
dev exec -- vendor/bin/pest tests/Unit/Sharing tests/Feature/Sharing tests/Contract/Sharing
dev exec -- vendor/bin/pest tests/Feature/Wallets/MembershipChangesTest.php tests/Feature/Wallets/ActivityRecordingTest.php
dev exec -- vendor/bin/pest tests/Contract/OpenApi
```
