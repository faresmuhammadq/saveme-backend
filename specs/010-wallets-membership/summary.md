# Summary: Wallets & Membership (spec 010, B4)

**Date**: 2026-10-02 · **Status**: implemented, QA and E2E green, committed on `main` (310aa24).

## What was built

- **Wallets module** (`app/Modules/Wallets`, all four layers).
  - `wallets` and `wallet_members` tables. A partial unique index allows one active membership per person and one active owner per wallet.
  - Create, PUT, PATCH, archive, delete and ownership transfer go through `WalletService`. Reads go through `WalletQueryService` and `MembershipQueryService`.
  - Eight endpoints under `/api/v1`, as frozen in `docs/openapi/paths/wallets.yaml` (`info.version` v1.3).
- **Balance cache.** `balance_minor = opening_balance_minor + Σ postings`, recomputed in the same transaction under the wallet row lock. `PostingTotals` is bound to the provisional `NoPostingsYet` (0) until B6. `wallets:verify-balances` runs daily: it logs `wallet_balance_drift` (wallet id only) and repairs the cache. An arch gate keeps `balance_minor` out of decisions.
- **Sync members.** Every wallet and membership carries `version`, `change_seq`, `created_at`, `updated_at` and `deleted_at`.
  - The wallet row's `change_seq` is the scope counter. Each touched record takes its own value, and the wallet takes the last.
  - Identical replays bump nothing.
  - Deletion is a tombstone cascade, done through `WalletDeletionParticipant` hooks inside one transaction.
- **Authorization.**
  - The spec §6 matrix is encoded once as `PermissionMatrix` and enforced by `WalletAuthorizer`, which later specs reuse.
  - Non-members get 404 and under-privileged members get 403. Every write route sits behind `address.verified`.
- **Entitlements.** `EntitlementService::forWallet` resolves the owner. `wallets.max` is checked on create; it is unlimited on every launch plan.
- **Membership.** The creator is the sole owner. Transfer swaps owner and editor in one transaction and writes one `role_changed` audit entry. Display names come from Identity's `DisplayNames` and are decrypted for the response only.
- **Events, after commit.** `WalletCreated`, `WalletDeleted` and `WalletOwnershipTransferred`.
- **Supporting changes.**
  - Identity: `DisplayNames`, `RoleChangedDetail`, and `UserFactory` now works outside a test transaction (R-13).
  - Billing: `forWallet`.
  - Shared: the `NotBlank` and `MinorUnits` request rules, mapped in `FieldErrors`.
  - Docs: glossary, security baseline (Wallets writes the membership audit events), and constitution v1.3.3 (the new events).
  - Localisation: ar/en problem strings.

### Fix made during QA (mobile-team report)

**Bug confirmed and fixed.** `SendRegistrationMail` issued its verification link when the queue worker ran, and `LinkRepository::issue` supersedes every earlier live link. If the person had already asked for a resend before the worker ran, the welcome mail superseded the newer, resent link, and the person's latest link stopped working.

- `LinkRepository::issueFirst()` issues a link only when none of that kind was ever issued for the person. It returns `null` otherwise.
- `issue()` and `issueFirst()` take a per-person, per-kind PostgreSQL advisory transaction lock, so concurrent issues are serialised and the newest request always holds the live link.
- The job issues the link and sends the mail in one transaction:
  - A failed send rolls the link back, so the retry starts clean.
  - A redelivered job finds its own link and sends nothing twice.
  - A job that arrives after a resend sends nothing, and the resent link stays valid.
- Tests: `tests/Feature/Identity/VerificationLinkTest.php` (late welcome job after a resend, redelivery, failed send). Also replayed on the dev stack.

## QA and E2E results

- **QA (CLI/API, `/speckit-qa-run`)**: 67/67 passed. Report `qa/qa-20261002-014700.md`, evidence in `qa/responses/`.
  - Create, list, get; PUT replay idempotency; `VERSION_CONFLICT` with `current`; PATCH; archive (and idempotent re-archive); DELETE with a JSON body; missing body → 422 `REQUIRED@/version`; tombstone → 409 on later writes and 404 to non-members.
  - Member list with display names; ownership transfer and its replay; `EMAIL_NOT_VERIFIED` on every write route; `FORBIDDEN` for editor and viewer; `IMMUTABLE_FIELD@/type`; blank name → `OUT_OF_RANGE@/name`; `UNKNOWN_FIELD`; `INVALID_ID`; collection errors; drift repair; five concurrent stale PATCHes → exactly one 200; `meta: {}`.
- **E2E (`/speckit-test-by-validate`, over HTTP)**: 28/28 acceptance scenarios passed, 26 over HTTP and 2 through the suite (US5 S1 and S3 have no HTTP surface in B4). Report `test-reports/validation-20261002-014700.md`. No browser surface, so Playwright was not used.
- **Gates**: Pint clean; Larastan level 8 clean; Arch 47/47; Unit + Feature + Contract 728/728.

## Contract

`docs/openapi/paths/wallets.yaml` is **unchanged** after QA. No field or error code changed for the C4 client.

## Assumed defaults

The owner was away with delegated authority; each item was taken as the conservative option.

### From the spec's Clarifications (session 2026-10-02)

1. `wallets.max` is enforced through the entitlement service on create only, counting the creator's live owned wallets (archived included). Unlimited on every launch plan, so 403 `FEATURE_NOT_ENTITLED` is reachable only through a data change.
2. Wallet type is immutable: `IMMUTABLE_FIELD` at `/type`.
3. Archiving cannot be undone in B4. An archived wallet stays readable and editable by its owner.
4. Deletion is final. The write body has no `deleted_at`.
5. On ownership transfer the previous owner stays as `editor`. The target must already be another active member.
6. Every write route sits behind the verification gate (403 `EMAIL_NOT_VERIFIED`). Reads are not gated.
7. Display names reach the member list through Identity's application layer, decrypted for the response only. Addresses are never exposed.
8. Wallets, not Sharing, writes the `membership_granted`, `membership_revoked` and `role_changed` audit entries. The security baseline was updated to match.
9. Wallet routes have no rate limit; 429 is not documented.
10. Wallets sort by `created_at` (default) or `name`, stabilised by `id`, with no filters. Members are listed in creation order. Both use the shared cursor and limit.
11. Sizing target: 50 wallets per person and 10 members per wallet in one page at the default limit of 50.

### From plan.md and research.md

12. (A-1) Type enum from the glossary: `cash`, `checking`, `savings`, `credit_card`, `loan`, `investment`. Gam'eya is not a type.
13. (A-2) Currency locks once any posting references the wallet → `IMMUTABLE_FIELD` at `/currency`. `currency_locked` is exposed; always `false` until B6.
14. (A-5) Wallet edit, archive, delete and transfer are owner-only. Editors will write transactions and rules (B6/B7). Viewers read.
15. (A-7) An invalid transfer target reuses `INVALID_ENUM_VALUE` at `/user_id`; no new catalogue code.
16. (A-9 / R-5) `version` travels in the JSON body, including on DELETE and archive. No `If-Match`.
17. (A-10 / R-3) The wallet row's `change_seq` is the scope counter, so it also moves when a membership changes. Clients must not treat a `change_seq` move alone as an edit.
18. (A-11 / R-9) The deletion cascade runs inside the transaction through participants, not a listener.
19. (A-13) A member exposes `member_user_id`, `display_name` and `role`.
20. (A-15) `wallets:verify-balances` runs daily.
21. (R-2) Balance is recomputed from opening balance plus postings, never as a delta on the cache.
22. (R-13) `UserFactory` wraps the key insert and the user insert in one transaction, so seeding outside tests works.

### Implementation deviations, accepted as defaults

23. **Transfer replay is answered before the role check.** When the named person is already the owner, any active member (including the previous owner, now an editor) gets 200 unchanged, as the frozen contract says. Otherwise the previous owner's retry after a lost response would get 403. R-10's order is superseded. No data is disclosed.
24. **Gap in `change_seq` at create.** Wallet row holds 1, membership takes 2, wallet's final stamp is 3. ADR-0008 needs strictly increasing values in commit order, not consecutive ones.
25. **A tombstoned wallet stays visible to everyone who was a member at deletion**: single read returns the tombstone, delete returns it unchanged, other writes are 409 carrying it. Never listed.
26. `OperatorNotificationsTest` was failing on the clean tree (relative `now()` against a fixed `travelTo`); fixed by moving `travelTo` to the top of the test.

### Taken during QA

27. The registration mail job stays silent rather than re-sending when any verification link already exists; the resend mail already carries the newest link.
28. The advisory lock (`pg_advisory_xact_lock(hashtext('identity_links:<kind>:<user>'))`) is PostgreSQL-only, which P-16 already requires.
29. QA seeded editor and viewer through a direct `wallet_members` insert under the wallet lock (no API before B9), as the spec's Assumptions allow.
30. E2E ran over HTTP, not Playwright, because spec 010 has no browser surface (P-18).

## Remaining risks

- Until B9, transfer can only be refused through the API; the success path was proven with seeded members.
- The currency-lock refusal is untestable over HTTP until B6; covered by unit and contract tests stubbing `PostingTotals`.
- DELETE with a JSON body is a client-library hazard (R-5).
- Queue workers must be restarted (`queue:restart`) after deploys to pick up the `SendRegistrationMail` change.
- `composer test` as one command exceeds Composer's 300 s process timeout in the container (suite ≈ 530 s); run the stages separately or raise `process-timeout`.
