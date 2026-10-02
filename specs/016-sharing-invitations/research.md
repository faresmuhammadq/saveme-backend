# Research: Sharing & Invitations (spec 016, B9)

Every decision below was taken with the owner away, as the conservative option, and is recorded as an assumed default in plan.md.

## R-1 Invitation create is a `PUT` upsert, not the brief's `POST`

- **Decision**: `PUT /wallets/{id}/invitations/{iid}` with a client UUIDv7; identical replay → 200; immutable afterwards (`IMMUTABLE_FIELD` at `/role` or `/invitee_email`).
- **Rationale**: the glossary makes Invitation a syncable wallet-scoped record; P-06 rule 2 and the endpoint checklist (C1) require a create to be an idempotent `PUT`. Immutability keeps the record simple: a "resend" is a new invitation that supersedes the earlier one for the same address (R-6).
- **Alternatives**: `POST` with an `Idempotency-Key` — rejected: conventions reserve the key for three money operations. A server-minted record like Occurrence (ADR-0018) — rejected: the owner creates invitations, so there is a client id to be idempotent by.

## R-2 Delivery: one record, two channels; link format; secret handling

- **Decision**: an email invitation queues a mail carrying `{sharing.links.invitation_url}?token=…`; a link invitation returns the same URL once, in `meta.share_url` of the `201`. Secret = 32 random bytes, base64url without padding (43 characters), stored as SHA-256 (Identity's `EloquentLinkRepository` pattern). A replay returns no link.
- **Rationale**: matches the existing mailed-link pattern (spec 007) and the baseline row "tokens: hash, nobody after issue". `meta` carries it because a feed record must equal its read (spec 013 default 18); the secret is not part of the record.
- **Alternatives**: retrievable link stored encrypted — rejected (a stored secret is a second credential at rest); a short numeric code — rejected (guessable, needs its own throttle and a new error code); token in the link's path — kept in the query so the app's deep-link parser and the website share one shape.

## R-3 Token operations carry the token in the body

- **Decision**: `POST /invitations/preview|accept|decline` with `{token}`.
- **Rationale**: P-15 rule 4 — paths land in access logs at every proxy; bodies do not. Identity's verify and reset already take the secret in the body.

## R-4 Uniform answer and address matching

- **Decision**: the create's answer never depends on whether the address has an account. A queued job (`SendInvitationMail`, like Identity's `SendResetMail`) looks the lookup value up through Identity's application layer and chooses the mail body (registered: "open the app"; unregistered: "create an account with this address, then open the link") and the locale (the account's, else the inviter's). Acceptance of an email invitation compares the caller's address lookup value with the invitation's; a mismatch is `404`, identical to a dead token.
- **Rationale**: security baseline, Uniform answers and Finding a person by address.

## R-5 Address at rest

- **Decision**: sealed with Identity's `AttributeCipher` under the **inviter's** data key, lookup value beside it; both erased (set null) in the write that closes the invitation. Exposed as `invitee_email` to members while pending.
- **Rationale**: the baseline's "Address: encrypted with a keyed lookup value"; per-user keys mean a deleted inviter's key destroys it (B15). Erasing on close minimises retention. Sharing reaches the cipher and lookup through a new Identity application service `InvitationAddresses` (P-02).

## R-6 Supersede on re-invite; one pending per address

- **Decision**: a new email invitation closes the live pending one with the same lookup value in the same wallet as `revoked`, in the same unit of work; a partial unique index `(wallet_id, address_lookup) WHERE deleted_at IS NULL` enforces one.
- **Rationale**: a "resend" must not consume two seats; refusing would need a new code and tell the owner nothing new.

## R-7 Quota arithmetic and the refusal shape

- **Decision**: count = owner + active members + live pending invitations with `expires_at > now`. Create: count + 1 ≤ quota. Accept: count (which includes this invitation) ≤ quota, i.e. members + other pending + 1. Both checks run under the wallet row lock. Refusal `403 FEATURE_NOT_ENTITLED` with the new optional Problem member `feature`.
- **Rationale**: the brief counts pending invitations; spec 010 already answers a quota with `FEATURE_NOT_ENTITLED` (`wallets.max`), so no new code; the owner's app can show "plan has no sharing" vs "wallet full" from `feature`. `FeatureNotEntitled` gains an optional feature, rendered by `ProblemRenderer` for every emitter.
- **Alternatives**: new code `MEMBER_LIMIT_REACHED` — rejected: would break `ErrorCodeParityTest` until code lands and duplicates an existing meaning.

## R-8 Who may accept, and the verification gate

- **Decision**: preview, accept, decline and leave are open to an unverified account; invite, revoke, role change and removal sit behind `address.verified`.
- **Rationale**: the baseline's gate row "Read anything they were invited to: allowed" presumes an unverified invitee can join; the gate exists to stop writes **into** a wallet, which joining and leaving are not. Barring leave would trap a person in a wallet. The baseline gets two explicit rows (T001).

## R-9 Membership writes stay in Wallets; Sharing orchestrates

- **Decision**: new Wallets application service `MembershipService` with `grant`, `changeRole`, `remove`, `leave`, `demoteBeyond` — each locks the wallet row, takes its `change_seq` values from the wallet counter, stamps `member_change_seq` through `UserScopeSequence` (people ascending), writes the audit entry and the activity entry, and finishes with the wallet's final stamp. `MembershipRepository` gains `tombstone(memberId, at, changeSeq, memberChangeSeq)`, `latestFor(walletId, userId)` (live or tombstone), `activeRanked(walletId)` (owner first, then `created_at`, `id`), `countActive(walletId)`, and `ownedLiveWalletsOf(userId)`. Sharing's `InvitationService` and `MemberService` call it inside their own `DB::transaction` (nested savepoints), so an acceptance is one unit of work.
- **Rationale**: spec 010 FR-018, glossary (Wallet Member written by Sharing through Wallets), baseline (Wallets writes the three audit events), ADR-0017 rule 5 lock order: wallet row → user rows ascending.

## R-10 Invitations on the wallet's counter

- **Decision**: Wallets offers `WalletScopeWrites::within(walletId, Closure(ScopeWrite): T): T` — locks the wallet row `FOR UPDATE`, hands out `nextChangeSeq()`, and on return stamps the wallet's final `change_seq` (no version move) when anything was stamped. Sharing writes invitations through it; `ChangeFeedService::walletPage` adds Sharing's `WalletFeedQuery` as a second wallet-feed source; Sharing registers a `WalletDeletionParticipant` that tombstones pending invitations as `revoked`.
- **Rationale**: ADR-0008 rules 3–5 (one counter per feed, under its lock); spec 010 FR-012 (wallet takes the last value); spec 010 §7 (deletion hook, not a listener).

## R-11 Downgrade enforcement

- **Decision**: Sharing listens (queued, idempotent) to Billing's `SubscriptionLapsed` and Wallets' `WalletOwnershipTransferred`; `EnforceMemberQuota` resolves the owner's quota through `EntitlementService::forWallet` and calls `MembershipService::demoteBeyond(walletId, quota)`, which ranks members (owner first, then joining order) and turns every `editor` ranked beyond the quota into `viewer` with actor `system`. `SubscriptionLapsed` to `grace` is a no-op because grace still grants. Never promotes.
- **Rationale**: brief and C3 ("members beyond the Free quota become viewers, not removed"); P-09 rule 6; ADR-0013 rule 10 delegates existing members to this spec. Materialised (not computed per request) because the phone's role-gated UI reads the role from the synced membership; the role change rides both feeds and the activity says why (`role_limited_by_plan`).
- **Alternatives**: compute an "effective role" at authorization time — rejected: the phone would show editor controls and get 403s with no sync signal. Re-promote on re-subscribe — rejected: needs a stored prior role; additive later (Open question 1).

## R-12 Activity Entry beside the audit log

- **Decision**: Wallets & Ledger table `wallet_activity` (append-only; `id`, `wallet_id`, `occurred_at`, `actor_user_id` nullable, `action`, `subject_type`, `subject_id`, `role` nullable), written through a Wallets port `ActivityLog::record(...)` inside each service's transaction, only on an applied write. Writers: `WalletService` (edit, archive, ownership transfer; create writes none because no one else can read it yet; delete writes none because the wallet becomes unreadable), `TransactionService` (create, edit, delete, restore, and `recordForOccurrence` → nothing extra: the occurrence's `confirmed` covers it), `RecurringRuleService` (create, edit, delete), `OccurrenceService` and `AutoConfirmer` (confirmed, skipped, edited; auto-confirm with actor null), `MembershipService`, and Sharing through `WalletActivity` (invited, invitation_revoked). Read: `ActivityQueryService::page(walletId, cursor, limit)` keyed on (`occurred_at` desc, `id` desc); display names via `DisplayNames` for actors who are active members. Prune entries older than 90 days in the daily purge run.
- **Rationale**: the brief asks for "who added/edited/deleted what"; the audit log is operator-only security evidence with a fixed vocabulary (baseline) and must not become a product feed. Not syncable: the glossary allows exactly two scopes and eleven syncable entities; a read endpoint is how the client gets it. Identifiers only, so it adds no protected attribute. ADR-0020 records it.
- **Alternatives**: derive activity from records (`created_by` only says who created, not who edited or deleted) — rejected. Reuse the audit log — rejected (operator-only, read recorded).

## R-13 Rate limits

- **Decision**: three Laravel `RateLimiter` definitions (no new dependency): `sharing-invite` 20/24 h per user on `upsertInvitation` creates (applied in the service so replays do not count — implemented as a named limiter hit only on a real create); `sharing-token` 30/h per user on the three token routes (route middleware); `sharing-mail` 5/24 h per lookup value, checked silently in `SendInvitationMail`. 429 rendered by the shared runtime with `RateLimit-*`.
- **Rationale**: FR-026; the per-address budget is silent so it reveals no cross-inviter information.

## R-14 Expiry sweep

- **Decision**: `sharing:expire-invitations`, hourly, closes pending invitations with `expires_at <= now` in batches of 200 per wallet lock, each through `WalletScopeWrites`; acceptance checks `expires_at` itself.
- **Rationale**: the stored status must be truth on the feed; the check at acceptance makes the sweep's timing irrelevant to security.

## R-15 Departing members' records

- **Decision**: nothing moves. Transactions keep `created_by`; recurring rules keep their author (spec 014's auto-confirm refusal `not_permitted` applies once the author is no longer allowed); Category References stay; budgets (spec 015) count only wallets the budget owner currently belongs to, so a leaver's budgets drop the wallet with no code change.
- **Rationale**: privacy commitments ("a shared wallet keeps its history"), P-07 (no rewriting another person's records), ADR-0019.

## R-16 Events

- **Decision**: Sharing publishes `InvitationSent`, `InvitationAccepted`, `MemberRevoked` (reason `removed`|`left`), `MemberRoleChanged` (reason `owner`|`plan`), after commit, identifiers and scalars only; constitution PATCH v1.3.9 adds `InvitationSent` and `MemberRoleChanged` to the Sharing row. The mail job is dispatched directly after commit (`afterCommit()`), not through the event, so the mail does not depend on a listener B16 will add.

## R-17 No new dependency

Mail (Laravel Mail, Mailpit in dev), queues, `RateLimiter`, Identity's cipher and lookup, and Spectator for contract tests are all installed. Nothing needs approval.
