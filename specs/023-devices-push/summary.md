# Summary: Devices & Push Notifications (spec 023, B16)

**Date**: 2026-10-06 · **Status**: implemented (T001–T062), QA and E2E green, committed on `main` (7f624bb). **No push is sent in production until Firebase credentials exist** (the production gateway is `disabled`).

## What was built

- **Notifications module** (`app/Modules/Notifications`, the constitution's ninth context) with a **`PushGateway` port** and adapters `fcm`, `log` and `disabled`, plus an in-memory fake for tests. No new dependency: the `fcm` adapter calls FCM HTTP v1 through Laravel's HTTP client and gets its access token from a service-account JWT signed RS256 with `openssl_sign`, cached until 5 minutes before expiry. `log` is refused at boot outside local/testing; `fcm` without credentials is refused at boot; `disabled` is allowed anywhere and records every message as skipped. Adopting the official SDK later means replacing that one adapter.
- **Registration**: `PUT /me/devices/{id}` (body `{push_token}` only; `{id}` must be the caller's own current session id, else 404; 200 `{session_id, registered_at, refreshed_at}`; token never returned; 20/hour per session) and `DELETE /me/devices/{id}` (204, also when there was nothing to remove). One registration per session and one session per token (a token that appears under a new session moves to it). The token is sealed under Notifications' own keyring with a keyed lookup value and never appears in logs, jobs, events, audit or queue payloads; the FCM key, the signed assertion and the access token are never logged. Registrations drop on session end, on `DELETE`, on invalid-token outcomes, after 270 days without a refresh (`notifications:prune`) and at account purge (new purge step `notifications`).
- **Preferences**: `GET/PUT /me/notification-preferences`, whole object plus `version` (optimistic; stale → 409 `VERSION_CONFLICT` with `current`); six category switches (`recurring_due`, `budget_exceeded`, `sharing`, `billing`, `export_ready`, `deletion_reminder`) all on by default, and quiet hours 22:00–08:00 in the profile timezone by default; defaults at version 1 until the first save; 30 saves per hour. Messages held by quiet hours are released by `notifications:release-held` (every 5 minutes), reduced to the latest per collapse key; changing quiet hours releases them.
- **Delivery**: notification + data messages with generic text in the profile language; the data block (`PushPayload` v1, 12 message types) carries ids only — no amounts, names, notes, addresses, dates or URLs. 10 pushes per person per hour and 30 per day (extras dropped); 5 tries with backoff 10 s, 60 s, 5 min, 15 min; unregistered or invalid tokens are pruned immediately and never retried; each send re-checks the session is still active.
- **Events and message types**: new `BudgetExceeded` (Budgeting; recorded once per budget per period, strictly above 100%, in the new `budget_overruns` table — this closes the B8 "already notified" gap), `SubscriptionRenewalDue` (Billing), `ExportReady` and `DeletionReminderDue` (Identity). Existing events consumed: `DeviceSessionEnded`, `InvitationSent` (email channel to a verified registered addressee only; the link-channel invitation queues no job), `MemberRoleChanged`, `MemberRevoked` (only when removed, not when leaving), `WalletOwnershipTransferred`, `SubscriptionLapsed`.
- **Recurring reminders**: hourly `notifications:occurrence-reminders` for manual rules dated today or yesterday (rule timezone) and refused automatic confirmations; nothing before 09:00 in the recipient's timezone; recipients are members allowed to edit recurring items (viewers skipped); more than 3 for one person becomes one summary.
- **Operators** see counts only: devices per account and last-24-hour totals by state plus pruned registrations. No new audit events.
- **Contract**: `docs/openapi/paths/notifications.yaml`, OpenAPI v1.14 (tag Notifications; the `PushPayload` data schema v1 is documented in the YAML and in the Apidog doc "Devices & push", because the Redocly bundle drops a schema attached to no operation). ADR-0025, constitution v1.3.14 (also fixes the footer that still said 1.3.12), glossary, security baseline, privacy commitments (en, ar), api-conventions.
- **Migrations (additive, applied to dev)**: `2026_10_12_000001`–`000005`, including `000004_create_budget_overruns_table`. Config in `config/notifications.php` and `.env.example`.
- **Apidog**: four endpoints imported; project doc "Devices & push" (2468329) with the PushPayload v1 table, preferences, quiet hours, delivery rules and limits.

## Results

- **QA** (`qa/qa-20261006.md`, HTTP and commands on dev with the `log` gateway and throwaway users): 23 scenarios pass. Live: registration and preferences edge cases, delivery for every event type, quiet hours, rate limits, budget overrun, the recurring pass, log scans, boot guards and the account purge (the throwaway users were purged through `PurgeAccount`, which also confirmed the purge step live). Covered by automated tests only, not driven live: retry/backoff and invalid-token pruning, the session guard, summary over 3, viewers skipped, the 09:00 gate, yesterday's occurrences, refused auto-confirmations, en/ar text, operator counts. The in-house RS256 JWT was checked against a generated key pair (`openssl dgst -verify` OK, a tampered input fails, no network call).
- **E2E** (`test-reports/validation-20261006.md`): 29/29 acceptance scenarios on HTTP and commands (Playwright not used: the only back-office change is two read-only counts, covered by an automated test).
- **Gates**: Pint clean; Larastan level 8 clean (nine errors in the B16 code fixed); 52 arch tests; 3,724 tests passed, 7 skipped.
- **Performance**: a reminder pass over 10,000 active rules and 100 people took 42 s (target 10 minutes), so no Wallets index was added (opt-in test: `dev exec -- env PUSH_PERF=1 vendor/bin/pest tests/Feature/Notifications/OccurrencePerformanceTest…`).
- **Fixes during implementation**: list-type returns in the Eloquent repositories and `SqlOccurrenceReminderSource`; `PermissionMatrix::rolesFor` made public so the reminder query reuses it (a source guard forbids role loops elsewhere); `NotifyInvitationReceived::shouldQueue()` true only for the email channel.
- No code changed in QA.

## Deployment

- Run migrations `2026_10_12_000001`–`000005`.
- Workers must listen to the `notifications` queue (a plain `queue:work` listens only to `default`; dev used `--queue=notifications,default`).
- Scheduler: `notifications:occurrence-reminders` hourly, `notifications:release-held` every 5 minutes, `notifications:prune` daily.
- Set `NOTIFICATIONS_KEYS`, `NOTIFICATIONS_ACTIVE_KEY`, `NOTIFICATIONS_LOOKUP_KEY`.
- Production gateway is `disabled` until a Firebase service-account JSON exists; then set the gateway to `fcm`.
- Seeder: none (no reference data).

## Contract notes for the mobile client

- Preferences body: `{version, categories:{…six keys…}, quiet_hours:{enabled, start:"HH:MM", end:"HH:MM"}}`; unknown keys → `UNKNOWN_FIELD`; a partial `categories` object → 422; quiet times off a 15-minute step, or start equal to end → `OUT_OF_RANGE`; a 409 returns the whole current object in `current`.
- When ownership is transferred, the previous owner also gets a `member_role_changed` push (the system changed their role).

## Assumed defaults

Owner away with delegated authority; each the conservative option (plan A-1…A-18).

1. No SDK: in-house FCM adapter behind the `PushGateway` port (owner has not approved a dependency).
2. Notifications is its own module and owns only the push registration, keyed to Identity's session.
3. One registration per session, one session per token; a token under a new session moves to it.
4. The registration body is the token only; platform and app version come from the session, language and timezone from the profile.
5. The token is encrypted at rest (it must be sendable, so it cannot be hashed), with a keyed lookup value beside it.
6. Six categories, all on by default; mail is unaffected.
7. Quiet hours on by default, 22:00–08:00 in the profile timezone; messages held, not dropped.
8. Messages are notification + data (data-only messages are unreliable when the app is killed); text is generic and localised; data carries ids only.
9. "Already notified" is stored twice: every message is a row with a per-person dedupe key, and Budgeting records a Budget Overrun once per budget per period, only for spending strictly above 100%.
10. Recurring reminders run hourly, not before 09:00 recipient time, summary when more than 3.
11. At most 10 pushes per person per hour and 30 per day; 5 tries with backoff; invalid tokens pruned at once.
12. InstaPay claim decisions are not pushed (a claim has no account) and neither is `InvitationAccepted`.
13. An invitation push opens a "check your email" notice; the token stays in the mail only.
14. Preferences are versioned but not synced, and are behind neither the verification gate nor any plan.
15. No new audit events; operators see counts only; no test-push endpoint.
16. The reminder query pages by occurrence id as a cursor only; the log redaction pattern gained `assertion`, `private_key` and `push_`.
17. The performance test is opt-in via `PUSH_PERF=1`.

## Remaining risks and owner decisions

- No real FCM call was ever made: the FCM adapter is tested through `Http::fake` and the JWT check only.
- The privacy-checklist items stay unchecked on purpose (reviewer-owned).
- Open owner decisions: the Firebase project and service-account key (needed for real delivery; the client needs its own Firebase dependency), an in-app invitation inbox (would change Sharing's token model), extra notification moments (invitation accepted, a near-limit budget alert, partner activity, claim decisions), shared mail/push templates, and adopting the Firebase Admin SDK only if the in-house adapter proves insufficient (needs dependency approval).
- Budget overruns are detected only from posted expenses and limit changes, and only for the current period.
- C16 expects InstaPay claim pushes and an invitation that opens directly; both differ here, as recorded above.
- Dev slip during QA: an unscoped update marked 33 other unverified dev users as verified (throwaway data only).
