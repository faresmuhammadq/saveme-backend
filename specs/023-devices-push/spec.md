# Feature Specification: Devices & Push Notifications

**Feature Branch**: `023-devices-push`

**Created**: 2026-10-06

**Status**: Draft

**Input**: Brief B16 ([specs/briefs/B16-devices-push.md](../briefs/B16-devices-push.md)): "The app registers an FCM token per device session (`PUT /devices/{id}/push-token`); users set notification preferences; the system sends: recurring occurrence due, invitation received, role changed or removed, subscription expiring/expired, InstaPay claim approved/rejected. Localized by the user's locale." Plus its considerations (tokens tied to device sessions and dropped on logout/revoke; a channel abstraction so email and push share templates; quiet hours by user timezone; no amounts in push bodies), its reconcile table, and every "Inherited from B…" note (B7, B8, B9). Paired with the mobile brief C16 ([saveme-kmp/specs/briefs/C16-push.md](../../../saveme-kmp/specs/briefs/C16-push.md)), whose "Needs from the backend" — the payload schema, the preference keys and the quiet-hours behaviour — this spec freezes in section 5.

> **Nature of this feature.** Nothing in the product can reach a person who is not looking at the app. This feature adds the one outbound channel the app has: a push message to the phone. It registers where to send (one registration per signed-in device session), lets the person say what they want to hear about and when not to be disturbed, and turns a fixed list of product moments — something is due, a budget is over, someone shared a wallet with them, their plan is ending, their export is ready, their account is about to be deleted — into one short, private, lock-screen-safe message each.
>
> **Where the brief and the ratified decisions differ, the ratified side wins unless this spec supersedes it:**
>
> | Brief | In force | Here |
> |---|---|---|
> | FCM through the Firebase SDK | No Firebase package is installed; CONTRIBUTING item 17 and Quality Gate 6: no new dependency without the owner's approval. **The owner has approved none.** | **No new dependency.** Delivery goes through a `PushGateway` port. Its production adapter speaks the FCM HTTP v1 API with the framework's HTTP client, and obtains its OAuth 2.0 access token by signing a service-account JWT (RS256) with PHP's bundled `openssl` functions, cached until shortly before expiry. Adopting the official SDK later is a drop-in replacement of that one adapter (FR-040) |
> | `PUT /devices/{id}/push-token` | Constitution: Notifications owns "devices, push tokens"; glossary: Device Session is Identity's and "Notifications … binds a push registration to a session"; API conventions put the caller's own resources under `/me` (`/me/profile`, `/me/subscription`) | **`PUT /me/devices/{id}`** and **`DELETE /me/devices/{id}`**, where `{id}` is the caller's **own current device session id**. Notifications owns the **Push Registration**; Identity keeps the session (reconcile row 1 of the brief) |
> | Device ownership | Glossary: Device Session under Identity; constitution: Notifications owns devices | **Notifications owns only the push registration**, keyed to Identity's session id; no second "device" record is created |
> | Localized by the user's locale | Profile `locale` is the language of mail and API messages (spec 008, FR-008) | **The profile language** chooses the push text. The registration carries no language of its own |
> | InstaPay claim approved/rejected | Claims are web-only and bound to an address, not an account (spec 020; P-10); the approval mail carries the redeem code | **Not pushed.** A claim has no account to push to; mail stays the channel (FR-031) |
> | Subscription expiring/expired | `SubscriptionLapsed` exists; the only "expiring" moment is Billing's InstaPay renewal reminder (spec 020), sent by mail | **Both pushed**: Billing publishes a new `SubscriptionRenewalDue` beside its existing reminder, and Notifications listens to `SubscriptionLapsed` |
> | No amounts in push bodies | Security baseline: what must never be disclosed; constitution P-15 | Also **no names** (wallet, category, person), **no notes**, **no addresses**, **no dates of money**: identifiers and fixed generic text only (FR-020) |
> | A channel abstraction so email and push share templates | Mail templates exist per module (Identity, Billing, Sharing) and are not touched here | Push text lives in Notifications' own translation files; existing mail is **not** rerouted. A shared template layer is an open question (section 9, Q4) |
> | B8: `BudgetExceeded` is B16's; nothing stores "already notified" | Constitution: Budgeting publishes `BudgetExceeded`; ADR-0019: progress computed on read | **Budgeting detects and records the overrun** (a Budget Overrun, once per budget per period) and publishes `BudgetExceeded`; Notifications only delivers it (FR-026) |
> | B7: due-ness is derived; refused auto-confirmation is the signal | ADR-0018 | Notifications runs an hourly reminder pass that asks Wallets which occurrences **need the person now** (due manual ones, refused automatic ones), and remembers each one it notified (FR-023) |
> | B9: listen to `InvitationSent`, `InvitationAccepted`, `MemberRevoked`, `MemberRoleChanged`; no amount, wallet name or address | Spec 016 | `InvitationSent` (email channel, to a registered and verified addressee), `MemberRoleChanged`, `MemberRevoked` (`removed` only) and `WalletOwnershipTransferred` are pushed. `InvitationAccepted` is **not** pushed in MVP (section 9, Q3) |

## Clarifications

### Session 2026-10-06

Owner away with delegated authority; each answer is the recommended (most conservative) option, recorded as an **assumed default**.

- Q: How is push delivered without the Firebase SDK the owner has not approved? → A: **Through a `PushGateway` port** with three adapters: `fcm` (FCM HTTP v1 over the framework's HTTP client, OAuth 2.0 service-account token signed RS256 with `openssl_sign`, cached until 5 minutes before expiry), `log` (writes the message type and identifiers, never the token, to the log) and `disabled` (sends nothing, records the notification as skipped). The test suite binds an in-memory fake. `log` is **refused at boot outside `local` and `testing`**, exactly as Billing refuses the `log` and `array` mailers; `disabled` is allowed everywhere because it pretends nothing. *Assumed default.* (FR-038–FR-042)
- Q: What identifies a device? → A: **The device session.** `PUT /me/devices/{id}` takes the caller's own current session id (spec 007's client-generated `session_id`); any other id — another person's, another of the caller's sessions, an ended one, an unknown one — is `404`. One session holds at most one registration; one push token belongs to at most one registration, so the same token arriving under a new session moves to it. *Assumed default.* (FR-001–FR-005)
- Q: What does the registration body carry? → A: **Only `push_token`.** Platform and app version are already on the session (spec 007); the language is the profile's (spec 008). Sending `platform`, `app_version` or `locale` is `422 UNKNOWN_FIELD`. *Assumed default.* (FR-002)
- Q: How is the push token stored? → A: **Encrypted at rest** under Notifications' own keyring (authenticated encryption, per-record nonce, key identifier beside it — baseline rule 4), with a **keyed lookup value** beside it for the one-token-one-registration rule. It is never returned by any endpoint, never logged, never put in a job payload, an event or an audit entry. It is decrypted only by the delivery job, immediately before sending. A hash alone would not do: the token must be sent to FCM. *Assumed default.* (FR-006, FR-007)
- Q: When does a registration end? → A: On **sign-out, session end of any reason, password change or reset (other sessions), account purge**, on an explicit `DELETE /me/devices/{id}`, when FCM reports the token **`UNREGISTERED`** or invalid, and after **270 days without a refresh**. Ending is a hard delete: nothing about the token survives. Besides the event-driven removal, delivery re-checks that the session is still active before every send, so a revoked session never receives a push even if its removal has not run yet. *Assumed default.* (FR-008–FR-012)
- Q: Which categories exist, and their defaults? → A: **Six, all on by default**: `recurring_due` (an occurrence needs the person), `budget_exceeded`, `sharing` (invitation received, role changed, removed from a wallet, ownership received), `billing` (plan ending soon, plan ended), `export_ready`, `deletion_reminder`. Each can be turned off. Mail is unaffected: every mail the product sends today still goes. *Assumed default.* (FR-013–FR-015)
- Q: Quiet hours? → A: **On by default, 22:00 to 08:00 in the profile timezone**; the person can turn them off or move them (whole quarter hours, start ≠ end, may wrap midnight). A notification created inside quiet hours is **held, not dropped**, and sent when they end; held notifications with the same collapse key are reduced to the latest. *Assumed default.* (FR-016–FR-018)
- Q: Notification + data, or data only? → A: **Notification + data.** The visible title and body are **generic, fixed, server-localised text** in the profile language (Arabic or English), so Android shows them even when the app is not running; the data block carries the type and identifiers for the deep link. Data-only messages are delayed or dropped by Android's background limits, and a reminder that does not arrive is worse than generic text. The app may replace the text when it is in the foreground. *Assumed default.* (FR-019–FR-022)
- Q: What may a payload contain? → A: **A message type, a category, the notification id, and only the identifiers the deep link needs** (`wallet_id`, `occurrence_id`, `budget_id`, `invitation_id`, `export_id`) plus `role` for a role change. Never an amount, balance, currency, name of a wallet, category or person, note, address, calendar date of money, token or URL. *Assumed default.* (FR-020)
- Q: How is "already notified" kept — the B8 gap? → A: **Two records.** Budgeting stores a **Budget Overrun** — at most one per budget per period — the first time progress exceeds the limit, and publishes `BudgetExceeded` once, from that insert. Notifications stores every **Push Notification** it creates under a per-person **dedupe key** (for example `occurrence:{id}`, `budget_exceeded:{budget}:{period_start}`, `event:{event_id}`), unique per person, so a redelivered event or a rerun pass never creates a second one. *Assumed default.* (FR-024–FR-027)
- Q: Which threshold for budgets? → A: **Only 100%** — "exceeded", the constitution's `BudgetExceeded`. The record keeps a threshold column so a "nearly there" alert can be added without changing the shape; adding one is an open question. Current period only; editing a past period never alerts; lowering a limit below what is already spent alerts once. *Assumed default.* (FR-026)
- Q: When does a recurring reminder go out? → A: The hourly pass reminds about an occurrence that is **`scheduled`, of a rule without `auto_confirm`, dated the rule's today or yesterday** (rule timezone, ADR-0018), and about an occurrence **still `scheduled` whose automatic confirmation was refused** (`auto_confirm_refusal` set). It sends no earlier than **09:00 in the recipient's profile timezone**. Recipients are the wallet's active members whom the Wallets matrix (spec 010 §6) lets confirm it. More than **3** reminders for one person in one pass collapse into **one summary** ("N items need you"). Each occurrence is reminded **at most once** per recipient; a missed occurrence is not reminded again. *Assumed default.* (FR-023)
- Q: Rate limits and collapse? → A: **At most 10 pushes per person per hour and 30 per day**; a notification over the budget is recorded as skipped (`rate_limited`), not queued for later. Every message carries an Android **collapse key** naming its subject (for example `budget:{id}`), so a newer message replaces an older undelivered one on the device. `PUT` and `DELETE /me/devices/{id}` share a budget of **20 per hour per session** (`429 RATE_LIMITED`). Preferences writes: **30 per hour per person**. *Assumed default.* (FR-028–FR-030, FR-035)
- Q: Retries and failures? → A: **A queued delivery job per notification**, sending to each live registration of the recipient, **5 attempts** with backoff 10 s, 1 min, 5 min, 15 min (honouring `Retry-After`). `UNREGISTERED`, `INVALID_ARGUMENT` on the token, and `SENDER_ID_MISMATCH` **delete the registration at once** and are not retried; `QUOTA_EXCEEDED`, `UNAVAILABLE`, `INTERNAL` and network failures are retried; an expired access token is refreshed once and the send repeated. A notification whose every device failed is `failed`; it is never resent by a later pass. *Assumed default.* (FR-032–FR-037)
- Q: Is Notifications its own module? → A: **Yes — `app/Modules/Notifications`**, the constitution's ninth context ("devices, push tokens, notification dispatch and preferences"; publishes `DeviceService`, `NotificationService`). It talks to Identity, Wallets, Budgeting, Sharing and Billing only through their application services and events (P-02). *Assumed default.*
- Q: Audit entries? → A: **None new.** Registering or removing a push destination happens inside an already-audited session (`session_issued`, `session_revoked`), carries no protected attribute and grants no access; the audit vocabulary is fixed and gains no event (baseline, "A module does not invent a local event name"). The Push Notification ledger — identifiers only, 30 days — is the operational record. *Assumed default.* (FR-045)
- Q: What do operators see? → A: **Counts only.** The back-office account page shows how many devices of that account can receive push; the overview shows platform-wide counts for the last 24 hours — created, sent, skipped, failed, registrations pruned. Never a token, a payload, a message text or a per-person list. *Assumed default.* (FR-046, FR-047)
- Q: InstaPay claim approved or rejected? → A: **Not pushed** (see the reconcile table). *Assumed default.* (FR-031)
- Q: An invitation push — where does it lead, given the app cannot open an email invitation without the token in the mail? → A: **To a notice, by `invitation_id`**, telling the person an invitation is waiting in their mailbox. The push never carries the token. An in-app inbox of invitations addressed to the person is section 9, Q2. *Assumed default.* (FR-025)

- Q: Are notification preferences a syncable record? → A: **No.** They are read with `GET /me/notification-preferences` and saved whole with `PUT /me/notification-preferences` carrying `version`; a stale version is `409 VERSION_CONFLICT` with `current`, so two phones cannot silently overwrite each other. They do not travel on the user feed: the settings screen reads them when it opens, and only the server acts on them. A new user-feed entity type would cost every client a sync change for a screen it reads on demand. *Assumed default.* (FR-013, FR-014)
- Q: Before a person ever saves preferences, what does `GET` answer? → A: **The defaults at `version: 1`**, created on first read or first need (one row per person, created idempotently). A first `PUT` carries `version: 1`. *Assumed default.* (FR-014)
- Q: Does the verification gate apply? → A: **No.** Registering a device and choosing what to hear are management of one's own account, which the baseline leaves open (Verification gate). An unverified person receives push about their own account; an **invitation** push is sent only to an account whose address is verified, because the address is what the invitation was sent to. *Assumed default.* (FR-005, FR-025)
- Q: What happens during the deletion grace period and the purge? → A: **Pending deletion: everything still sends** (the reminder is the point). **Purging: nothing sends** — every session ends at the start of the purge, which removes every registration; a new purge step `notifications` then deletes the person's preferences and notification records. *Assumed default.* (FR-012, FR-044)
- Q: Push text language when the profile language changes while a notification is held? → A: **The language at send time.** The text is rendered by the delivery job, not stored. *Assumed default.* (FR-021)

### Session 2026-10-06 (clarify pass)

- Q: Which budgets does a posted expense make Budgeting evaluate? → A: **The author's live, unarchived budgets in the transaction's currency whose category is the transaction's category or `null` (all expenses), for the period containing today**, and only when the transaction's date falls in that period; evaluated for every `TransactionPosted` of kind `expense` whatever its `change` (`recorded`, `amended`, `restored`). An archived budget never alerts. *Assumed default.* (FR-026)
- Q: What may a pass, sweep or delivery log? → A: **One counts-only line per run** (`notifications_pass` with created, summarised, skipped counts; `notifications_released`; `notifications_pruned`), and per failure the notification id, type, provider status and reason (FR-037). Never a token, text, recipient address or payload; user ids only at debug level (baseline, Debug level only). *Assumed default.* (FR-037, FR-048)
- Q: How fast must the hourly reminder pass be? → A: **Under 10 minutes over 10,000 active rules**, the same target as the materialisation job (spec 014), with the occurrence query paged and set-wise. *Assumed default.* (SC-008)
- Q: What if a delivery job dies after marking a notification `sending`? → A: **A 5-minute sweep re-queues a notification left `sending` for more than 15 minutes**; its deliveries already `sent` are never repeated (FR-036). *Assumed default.* (FR-049)
- Q: Can a person send themselves a test push? → A: **No.** No test endpoint in MVP; the app verifies its registration by the `200` answer. A test endpoint would be a free way to send unthrottled messages to any token one holds. *Assumed default.* (FR-050)

## User Scenarios & Testing *(mandatory)*

### User Story 1 - My phone can receive notifications (Priority: P1)

After signing in, the app tells the server where this phone can be reached. When the push service hands the app a new address, the app updates it. When the person signs out, ends the session from another phone, changes their password, or the account is deleted, that phone stops receiving anything.

**Why this priority**: without a registration there is nowhere to deliver; every other story depends on it, and getting removal wrong means messages reaching a phone the person has signed out of.

**Independent Test**: sign in on two phones, register both, send a test notification through the fake gateway and see two deliveries; end one session from the other phone and see the next notification delivered once.

**Acceptance Scenarios**:

1. **Given** a signed-in phone, **When** it registers a push token for its own session, **Then** the answer is `200` with the registration (session id, times) and never the token.
2. **Given** a registered phone, **When** it registers a new token for the same session, **Then** the registration now holds the new token and the old one receives nothing more.
3. **Given** a registered phone, **When** it registers the same token again, **Then** the answer is `200` and nothing changes except the refresh time.
4. **Given** a token registered under session A, **When** a different session (the same or another person, on the same phone) registers the same token, **Then** the registration moves to the new session and session A has none.
5. **Given** a session id that is not the caller's current session (another person's, another of the caller's own sessions, an ended one, unknown), **When** the caller registers it, **Then** the answer is `404` and nothing is stored.
6. **Given** a registered phone, **When** the session ends for any reason (sign-out, ended by the owner, replaced, password change or reset, idle expiry, account purge), **Then** its registration is removed and no later notification is delivered to it.
7. **Given** a registered phone, **When** it calls `DELETE /me/devices/{id}`, **Then** the answer is `204` and the registration is gone; a second call also answers `204`.
8. **Given** a registration whose token the push service reports as no longer valid, **When** a delivery is attempted, **Then** the registration is deleted at once and not retried.

---

### User Story 2 - Choose what I hear about, and when not (Priority: P1)

The person opens notification settings and sees six switches and their quiet hours. They turn budget alerts off, move quiet hours to 23:00–07:00 and save. From then on no budget alert reaches them, and nothing arrives during the night; what would have arrived at night arrives in the morning.

**Why this priority**: push without control is spam; the brief and the privacy commitments make quiet hours part of the feature, not a refinement.

**Independent Test**: read the defaults; save a change; create notifications of a disabled category and inside quiet hours and check one is skipped and the other held then sent at the end of quiet hours.

**Acceptance Scenarios**:

1. **Given** a person who never saved preferences, **When** they read them, **Then** all six categories are on, quiet hours are on from 22:00 to 08:00, and the version is 1.
2. **Given** the current version, **When** the person saves the whole preferences, **Then** the version goes up by one and the response carries the saved preferences; an identical save answers `200` without moving the version.
3. **Given** a stale version, **When** the person saves, **Then** the answer is `409 VERSION_CONFLICT` with the current preferences and nothing is applied.
4. **Given** an invalid value (an unknown category, a missing one, a time not on a quarter hour, start equal to end, a non-boolean), **When** saved, **Then** `422 VALIDATION_FAILED` names each field and nothing is applied.
5. **Given** a category turned off, **When** a notification of that category arises, **Then** nothing is sent and the notification is recorded as skipped (`category_off`).
6. **Given** quiet hours 22:00–08:00 in `Africa/Cairo`, **When** a notification arises at 23:30 Cairo time, **Then** it is held and sent at 08:00 Cairo time; **and** if two held notifications share a collapse key, only the later is sent.
7. **Given** the person changes their profile timezone, **When** quiet hours are next evaluated, **Then** they follow the new timezone.

---

### User Story 3 - Remind me when a recurring item needs me (Priority: P1)

The person has a monthly rent rule that they confirm by hand. On the morning it is due, their phone says "A recurring item is due today — open to review it". Tapping it opens that occurrence. If the server could not confirm an automatic rule, they hear about that too. If eight items fall due the same morning, they get one message, not eight.

**Why this priority**: recurring items are the product's main reason to remind anyone (B7), and the scope guard allows reminders for recurring occurrences only.

**Independent Test**: create a manual-confirm rule with an occurrence dated today, run the reminder pass after 09:00 local, and see one notification with the occurrence and wallet ids; run the pass again and see none.

**Acceptance Scenarios**:

1. **Given** a `scheduled` occurrence of a manual rule dated the rule's today, **When** the hourly pass runs at or after 09:00 in the recipient's timezone, **Then** each active member allowed to confirm it receives one `occurrence_due` notification carrying `wallet_id` and `occurrence_id`.
2. **Given** the same occurrence, **When** the pass runs again, **Then** no second notification is created.
3. **Given** an automatic rule whose confirmation was refused, **When** the pass runs, **Then** the allowed members receive one `occurrence_auto_confirm_refused` notification.
4. **Given** an occurrence of an automatic rule that has not been refused, **When** the pass runs, **Then** nobody is reminded.
5. **Given** more than 3 occurrences needing one person in one pass, **When** the pass runs, **Then** that person receives one `occurrences_due_summary` notification (no occurrence ids), and each occurrence is still recorded as reminded.
6. **Given** a viewer of the wallet, **When** the pass runs, **Then** the viewer is not reminded.
7. **Given** an occurrence dated two or more days ago that was never reminded (the pass did not run), **When** the pass runs, **Then** it is not reminded (no backlog flood).

---

### User Story 4 - Tell me when a budget is exceeded, once (Priority: P2)

The person's grocery budget is 4,000 a month. The transaction that takes this month's spending past 4,000 produces one notification: "A budget has gone over its limit". Further spending this month produces nothing more; next month starts fresh.

**Why this priority**: it closes the gap spec 015 left open, but a missed budget alert hurts less than a missed due payment.

**Independent Test**: record expenses up to and past a budget's limit; see exactly one `budget_exceeded` notification carrying `budget_id`; record more; see none; void and re-record; see none.

**Acceptance Scenarios**:

1. **Given** a budget under its limit in the current period, **When** an expense that the budget counts takes spending above the limit, **Then** a Budget Overrun is recorded for that budget and period, `BudgetExceeded` is published once, and the owner receives one notification carrying `budget_id`.
2. **Given** a budget already over in this period, **When** more spending is recorded, or a transaction is voided and recorded again, **Then** nothing new is recorded or sent.
3. **Given** a new period begins, **When** spending exceeds the limit again, **Then** a new overrun and one new notification follow.
4. **Given** spending already above a limit, **When** the owner lowers the limit, **Then** one overrun is recorded and notified if none exists for this period.
5. **Given** an expense dated in a past period, **When** it is recorded, **Then** no overrun is recorded for that past period.
6. **Given** spending exactly equal to the limit, **When** evaluated, **Then** no overrun (exceeded means strictly above).

---

### User Story 5 - Tell me about sharing changes that affect me (Priority: P2)

When someone invites the person by email and the person already has a verified account, their phone says "You've been invited to a shared wallet — check your email". When their role in a wallet changes, when they are removed from a wallet, or when a wallet's ownership passes to them, they hear about it.

**Why this priority**: sharing is the product's social surface; these moments otherwise reach the person only on next open.

**Independent Test**: invite a registered, verified address; see one `invitation_received` to that person carrying `invitation_id` and nothing else identifying; change their role; see `member_role_changed` with `wallet_id` and `role`.

**Acceptance Scenarios**:

1. **Given** an email invitation to an address that belongs to a registered account with a verified address, **When** the invitation is sent, **Then** that account receives one `invitation_received` notification carrying `invitation_id`.
2. **Given** a link invitation, or an email invitation to an address with no account or an unverified one, **When** sent, **Then** no push is created.
3. **Given** a member whose role is changed by the owner or by a plan change, **When** the change commits, **Then** that member receives `member_role_changed` with `wallet_id` and the new `role`.
4. **Given** a member removed by the owner, **When** it commits, **Then** that member receives `member_removed` with `wallet_id`; **and** a member who leaves receives nothing.
5. **Given** ownership transferred (by the owner or by the purge), **When** it commits, **Then** the new owner receives `wallet_ownership_received` with `wallet_id`.
6. **Given** the same event delivered twice, **When** both are handled, **Then** exactly one notification exists.

---

### User Story 6 - Tell me about my plan, my export and my deletion (Priority: P3)

The person hears when their plan is about to end and when it ended, when an export they asked for is ready, and three days before their account is deleted. Each of these already arrives by mail; the push is a second, shorter path.

**Why this priority**: mail already covers these; push only shortens the path.

**Independent Test**: publish each source event and see one notification of the right type and category with only the listed identifiers.

**Acceptance Scenarios**:

1. **Given** Billing's renewal reminder runs for a subscription, **When** it records the reminder, **Then** `SubscriptionRenewalDue` is published and the person receives one `subscription_ending` notification.
2. **Given** a subscription lapses, **When** `SubscriptionLapsed` is handled, **Then** the person receives one `subscription_lapsed` notification.
3. **Given** an export becomes ready, **When** `ExportReady` is handled, **Then** the person receives one `export_ready` notification carrying `export_id`, never a link.
4. **Given** the deletion reminder runs for a person, **When** it records the reminder, **Then** `DeletionReminderDue` is published and the person receives one `deletion_reminder` notification.
5. **Given** any of these, **When** the mail is sent, **Then** the mail is unchanged by this feature and goes whatever the push preferences say.

---

### User Story 7 - Operators see whether push works, not what it said (Priority: P3)

An operator looking at an account sees "Devices that can receive notifications: 2". The overview shows how many notifications were sent, skipped and failed in the last 24 hours, and how many registrations were pruned. Nothing names a person, shows a token or repeats a message.

**Why this priority**: support needs to answer "I never get notifications"; it does not need content.

**Independent Test**: with notifications in each state, open the overview and an account page and check the counts and the absence of anything else.

**Acceptance Scenarios**:

1. **Given** an account with two registered sessions, **When** an operator opens its page, **Then** it shows the count 2 and nothing more about them.
2. **Given** notifications in each state in the last 24 hours, **When** an operator opens the overview, **Then** it shows the counts per state and the pruned-registration count.

---

### Edge Cases

- **A push arrives at a signed-out phone.** Cannot happen after the delivery's own check: each send re-checks that the registration's session is active (FR-011).
- **The push service rotates the token while the phone is offline.** The next `PUT` replaces it; sends to the old token in between fail `UNREGISTERED` and prune the registration; the next `PUT` re-creates it.
- **Two people share a phone.** The same token moves to whichever session registered it last (FR-004); the previous person's session stops receiving.
- **Quiet hours that wrap midnight** (22:00–08:00) and those that do not (13:00–15:00) both work; start equal to end is refused.
- **DST and timezone changes.** Quiet hours and the 09:00 reminder hour are wall-clock times in the profile timezone at send time (ADR-0011); nothing stored is rewritten (P-12 rule 5).
- **A person with no registration.** Notifications are still created and recorded as skipped (`no_device`), so "already notified" holds if they register later (no backlog is delivered to a new phone).
- **A category turned back on.** Nothing skipped while it was off is resent.
- **A burst released at the end of quiet hours.** Released notifications go through the rate limit like any other, oldest first; the overflow is skipped (`rate_limited`). Collapse keys reduce the burst first (FR-018).
- **Held notifications and preference changes.** At release, preferences are re-read: a category turned off meanwhile skips it; quiet hours moved meanwhile re-hold it.
- **Rate limit reached by a burst** (for example a purge transferring ten wallets to one person). Notifications beyond the budget are skipped (`rate_limited`); collapse keys reduce what remains on the phone.
- **Recipient account pending deletion.** Still receives. **Purging or purged.** Never receives; anything created is skipped (`inactive_account`).
- **A shared wallet with a member in another timezone.** Due-ness uses the rule's timezone; the 09:00 hour and quiet hours use each recipient's own timezone.
- **A transaction re-categorised into a budget's category** by a partner (attribution by author, ADR-0019). Evaluated like any other change to the author's spending.
- **A budget deleted or archived.** An archived budget never alerts; nothing more is sent after deletion, and the overrun rows go with the budget's tombstone purge.
- **A role change made by the member themselves** cannot happen (only the owner or a plan change); a change whose actor is the member is not pushed.
- **FCM returns 5xx for every attempt.** After five attempts the notification is `failed` and logged with its id and the provider status code only.
- **The access token expires mid-batch.** The adapter refreshes once on `401` and repeats the send; a second `401` fails the attempt (retried by the job).
- **Production started with the `log` gateway.** The application refuses to boot (FR-041).
- **Production without FCM credentials.** With the `fcm` gateway selected, boot is refused; with `disabled`, everything is recorded as skipped (`disabled`).

## Requirements *(mandatory)*

### Functional Requirements

**Registration**

- **FR-001**: A signed-in person MUST be able to register a push token for **their own current device session** with `PUT /me/devices/{id}`, `{id}` being that session's id. Any other id MUST answer `404 NOT_FOUND` and store nothing.
- **FR-002**: The body MUST be exactly `{ "push_token": string }`, 1–4096 printable ASCII characters. A missing token is `REQUIRED`, a longer or empty one `OUT_OF_RANGE`, a non-string `INVALID_TYPE`, any other member `UNKNOWN_FIELD`.
- **FR-003**: One session MUST hold at most one registration. Registering again MUST replace the token (rotation) or, for the same token, only refresh `refreshed_at`. The answer is `200` in every case, carrying the registration without the token.
- **FR-004**: One token MUST belong to at most one registration: registering a token held by another session MUST move it, in the same unit of work.
- **FR-005**: Registration MUST NOT be behind the verification gate and MUST NOT be gated by plan.
- **FR-006**: The token MUST be stored encrypted (authenticated, per-record nonce, key id beside it) under Notifications' own keyring, with a keyed lookup value beside it; the keys and lookup key are configuration and required in every environment. The keyring MUST hold several keys: rotating means adding a key and making it active; values sealed under an older key stay readable until that registration is next refreshed (re-sealed under the active key) or pruned, after which the old key may be removed.
- **FR-007**: The token MUST NOT appear in any response, log line at any level, exception message, queued job payload, event, audit entry or operator screen. The same holds for the FCM service-account key, the signed OAuth assertion and the access token.
- **FR-008**: `DELETE /me/devices/{id}` MUST remove the caller's registration for their own current session and answer `204`, also when there is none; any other id is `404`.
- **FR-009**: When a device session ends for any reason (`DeviceSessionEnded`), its registration MUST be deleted.
- **FR-010**: A registration not refreshed for **270 days** MUST be deleted by a daily sweep.
- **FR-011**: Before every send, the delivery MUST confirm through Identity that the registration's session is still active and the account is not purging or purged; otherwise it deletes the registration (session ended) or skips (account inactive) without sending.
- **FR-012**: An account purge MUST leave no registration, preference or notification of the person: sessions end at the purge's start (FR-009), and a purge step `notifications` deletes the rest, idempotently.

**Preferences**

- **FR-013**: A person MUST be able to read their notification preferences (`GET /me/notification-preferences`) and save them whole (`PUT /me/notification-preferences`) with `version`; a stale version is `409 VERSION_CONFLICT` with `current`; an identical save answers `200` without moving the version; every member is required (`REQUIRED`), server-owned members (`updated_at`) and unknown ones are `UNKNOWN_FIELD`.
- **FR-014**: Before the first save, the preferences MUST be the defaults at `version: 1`: all six categories on, quiet hours on, 22:00–08:00.
- **FR-015**: Categories MUST be exactly `recurring_due`, `budget_exceeded`, `sharing`, `billing`, `export_ready`, `deletion_reminder`, each a boolean.
- **FR-016**: Quiet hours MUST be `enabled` (boolean), `start` and `end` (`HH:MM`, 24-hour, minutes 00/15/30/45), `start ≠ end`; a wrap past midnight is allowed. Invalid times are `OUT_OF_RANGE`.
- **FR-017**: Quiet hours MUST be evaluated in the recipient's **profile timezone at send time**. A notification due to be sent inside quiet hours MUST be held and released at their end; the release sweep also releases a held notification early when the recipient's current preferences no longer place now inside quiet hours (turned off or moved). At release, preferences are re-applied.
- **FR-018**: Among held notifications of one person with the same collapse key, only the latest MUST be sent; the others are skipped (`collapsed`).

**Payload and text**

- **FR-019**: Every push MUST be a notification message with a data block: a generic title and body from Notifications' translation files, the Android channel id equal to the category, and the collapse key.
- **FR-020**: The data block MUST carry `schema` (`"1"`), `type`, `category`, `notification_id`, and only the identifiers its type lists in section 5 (`wallet_id`, `occurrence_id`, `budget_id`, `invitation_id`, `export_id`, and `role` for a role change). It MUST NOT carry an amount, balance, currency, a wallet, category or person name, a note, an address, a calendar date of money, a token or a URL. A test MUST fail if any payload carries a key outside its type's list.
- **FR-021**: Title and body MUST be rendered at send time in the recipient's profile language (`ar` or `en`), from translation files, never hardcoded (P-14); text MUST NOT contain a name, amount or date.
- **FR-022**: Message types MUST be exactly: `occurrence_due`, `occurrence_auto_confirm_refused`, `occurrences_due_summary`, `budget_exceeded`, `invitation_received`, `member_role_changed`, `member_removed`, `wallet_ownership_received`, `subscription_ending`, `subscription_lapsed`, `export_ready`, `deletion_reminder`.

**What is sent, and once**

- **FR-023**: An hourly pass MUST ask Wallets for occurrences that need someone — `scheduled` occurrences of rules without `auto_confirm` dated the rule's today or yesterday, and `scheduled` occurrences with `auto_confirm_refusal` set dated within the last 2 days — with their recipients (active members allowed to confirm them under spec 010 §6), and create one notification per occurrence per recipient no earlier than 09:00 in the recipient's timezone. More than 3 for one recipient in one pass MUST become one `occurrences_due_summary`, while each occurrence is still recorded under its dedupe key.
- **FR-024**: Every notification MUST carry a dedupe key, unique per recipient; creating one whose key exists MUST do nothing. Event-driven notifications use the subject-based keys of section 3 (so two events about the same subject yield one notification).
- **FR-025**: On `InvitationSent` with channel `email`, Notifications MUST ask Sharing for the addressee account; only an existing, active account with a verified address that is not the inviter receives `invitation_received` (`invitation_id` only).
- **FR-026**: Budgeting MUST evaluate, after a transaction is posted (queued listener on every `TransactionPosted` of kind `expense`, whatever its `change`) and after a budget's limit changes, the current-period progress of the affected budgets: the transaction **author's** (ADR-0019) live, unarchived budgets in the transaction's currency whose category is the transaction's category or `null`, and only when the transaction's date lies in the period containing today; when spending is strictly above the limit and no Budget Overrun exists for that budget, period start and threshold 100, it MUST insert one and publish `BudgetExceeded` after commit. Notifications delivers it to the budget's owner.
- **FR-027**: On `MemberRoleChanged` (actor not the member), `MemberRevoked` (reason `removed`), `WalletOwnershipTransferred`, `SubscriptionRenewalDue`, `SubscriptionLapsed`, `ExportReady` and `DeletionReminderDue`, Notifications MUST create the notification of section 5's mapping for the named recipient.

**Limits**

- **FR-028**: At most **10** notifications per person per rolling hour and **30** per rolling 24 hours MUST be sent; any beyond is recorded `skipped` (`rate_limited`) and never sent later.
- **FR-029**: Every message MUST carry the collapse key listed for its type in section 5.
- **FR-030**: Registration writes MUST share a budget of 20 per hour per session; preference writes 30 per hour per person; both `429 RATE_LIMITED` with `Retry-After`.
- **FR-031**: InstaPay claim decisions MUST NOT produce a push.

**Delivery**

- **FR-032**: Each notification ready to send MUST be delivered by one queued job that sends to every live registration of the recipient and records one Push Delivery per registration.
- **FR-033**: A transient failure MUST be retried for that registration only: at most 5 attempts, backoff 10 s, 60 s, 300 s, 900 s, or the provider's `Retry-After` when longer.
- **FR-034**: A permanent token failure (`UNREGISTERED`, invalid token, sender mismatch) MUST delete the registration at once and record the delivery `pruned`; it is not retried.
- **FR-035**: A notification MUST end in exactly one of `sent` (at least one device accepted), `skipped` (with a reason: `category_off`, `no_device`, `rate_limited`, `collapsed`, `inactive_account`, `disabled`, or `summarised` for an occurrence folded into a summary) or `failed`; no pass resends a `sent`, `skipped` or `failed` notification.
- **FR-036**: A delivery job MUST be idempotent: rerunning it after a crash MUST NOT send again to a registration whose delivery is already `sent`.
- **FR-037**: Failure logs MUST carry the notification id, the type, the provider's status code and error reason only.
- **FR-048**: Each pass and sweep MUST log one counts-only summary line; no log line at any level carries a token, push text or payload, and user ids only at debug level.
- **FR-049**: A notification left `sending` for more than 15 minutes MUST be re-queued by the 5-minute sweep, without repeating any delivery already `sent`.
- **FR-050**: There MUST be no endpoint that sends a push on request (no test push).
- **FR-051**: A failure for one occurrence, recipient or notification in a pass or sweep MUST NOT stop the pass: it is logged (ids and exception class only) and the pass continues; the next run picks up anything still eligible.

**Gateway**

- **FR-038**: Delivery MUST go through a `PushGateway` port in Notifications' Domain, taking a message (type, collapse key, title, body, data, channel) and a destination, and returning one of `accepted`, `invalid_token`, `retry_later(seconds?)`, `failed`.
- **FR-039**: The `fcm` adapter MUST call the FCM HTTP v1 send endpoint with the framework's HTTP client, authenticated by an OAuth 2.0 access token obtained with a JWT bearer grant signed RS256 by PHP's `openssl` functions from a service-account key; the token MUST be cached until 5 minutes before its expiry and never logged.
- **FR-040**: No new Composer or npm dependency MUST be added. Replacing the `fcm` adapter with the official SDK later MUST require no change outside the adapter and its binding.
- **FR-041**: The `log` gateway MUST be refused at boot outside the `local` and `testing` environments; the `fcm` gateway MUST be refused at boot when its credentials or project id are missing.
- **FR-042**: The `disabled` gateway MUST send nothing and record every notification `skipped` (`disabled`).

**Retention, operators, audit**

- **FR-043**: Push Notifications and Push Deliveries MUST be deleted 30 days after creation; Budget Overruns are kept while their budget exists and removed with it.
- **FR-044**: The new purge step `notifications` MUST run after `subscriptions` and before `exports`, and MUST be safe to repeat.
- **FR-045**: No audit entry and no audit vocabulary change MUST be introduced.
- **FR-046**: The back-office account page MUST show the count of the account's push registrations, read through Notifications' query service.
- **FR-047**: The back-office overview MUST show platform-wide counts for the last 24 hours — notifications by state and registrations pruned — and nothing per person.

### Key Entities

- **Push Registration** (new, Notifications): where to push one device session; the encrypted token, its lookup value, the session id, the person, times.
- **Notification Preferences** (new, Notifications): one per person; six category switches and quiet hours; versioned, not synced.
- **Push Notification** (new, Notifications): one message for one person, with its dedupe key, type, category, identifiers, collapse key, state and reason.
- **Push Delivery** (new, Notifications): one attempt series of one notification to one registration.
- **Budget Overrun** (new, Budgeting): the record that a budget's period exceeded its limit, once.
- **Device Session**, **Profile**, **Occurrence**, **Budget**, **Invitation**, **Subscription**, **Export** (existing).

## Project Sections *(mandatory for feature specs)*

### 1. Purpose

This feature lets the product reach a person's phone when something needs them, without ever putting money, names or secrets on the lock screen. It gives each signed-in phone a way to be reached and stops reaching it the moment the session ends; it lets the person choose which moments they hear about and keep their nights quiet; and it turns a fixed list of product moments into one private message each, exactly once, with an honest record of what was sent, skipped or failed.

### 2. Glossary terms

New entries, added to [docs/glossary.md](../../docs/glossary.md) in this change before code uses them: **Push Registration**, **Notification Preferences**, **Push Notification** (with Push Delivery as its child), **Budget Overrun**. The Device Session entry's note ("will be indexed here when that feature is specced") is replaced by a link to Push Registration. Existing: [Device Session](../../docs/glossary.md#device-session), [Profile](../../docs/glossary.md#profile), [Occurrence](../../docs/glossary.md#occurrence), [Recurring Rule](../../docs/glossary.md#recurring-rule), [Budget](../../docs/glossary.md#budget), [Budget Period](../../docs/glossary.md#budget-period), [Invitation](../../docs/glossary.md#invitation), [Wallet Member](../../docs/glossary.md#wallet-member), [Subscription](../../docs/glossary.md#subscription), [Export](../../docs/glossary.md#export), [Operator Notification](../../docs/glossary.md#operator-notification) (unrelated: operator-facing, Admin). "Token" stays banned as product vocabulary for the session credential; the push token is called `push_token` on the wire and "push address" in user-facing text.

### 3. Data model

Owned by `app/Modules/Notifications` unless stated. All ids UUIDv7; instants UTC (P-12). Full columns in [data-model.md](./data-model.md).

| Entity | Scope key | Key fields | States |
|---|---|---|---|
| Push Registration | `user_id`, unique `session_id` | `token_sealed` (sealed value naming its key), `token_lookup` (unique), `registered_at`, `refreshed_at` | `single-state` (deleted when it ends) |
| Notification Preferences | unique `user_id` | six booleans, `quiet_hours_enabled`, `quiet_hours_start`, `quiet_hours_end`, `version` | `single-state` |
| Push Notification | `user_id`, unique (`user_id`, `dedupe_key`) | `type`, `category`, `data` (ids only), `collapse_key`, `state`, `skip_reason`, `not_before`, `sent_at` | `pending` → `held` → `pending`; `pending` → `sending` → `sent` \| `failed` \| `skipped` (no device); `pending` \| `held` → `skipped` |
| Push Delivery | `notification_id` + `registration_id` (registration may be gone) | `state`, `attempts`, `last_error` | `pending` → `sent` \| `pruned` \| `failed` |
| Budget Overrun (Budgeting) | `budget_id` + `period_start` + `threshold` unique | `user_id`, `crossed_at` | `single-state` |

Dedupe keys: `occurrence:{occurrence_id}`, `occurrence_refused:{occurrence_id}`, `budget_exceeded:{budget_id}:{period_start}`, `invitation:{invitation_id}`, `event:{event_id}` (role change, removal, ownership, lapse), `renewal:{subscription_id}`, `export:{export_id}`, `deletion_reminder:{deletion_scheduled_for}`, `summary:{pass_hour}`.

### 4. Invariants

| Invariant | Principle or decision | Test that fails when broken |
|---|---|---|
| A registration exists only for an active session of its owner | FR-001, FR-009, FR-011 | `tests/Feature/Notifications/RegistrationLifecycleTest.php` |
| One token belongs to at most one registration; one session to at most one | FR-003, FR-004 | `tests/Feature/Notifications/RegisterDeviceTest.php` |
| The token is never stored readable, returned, logged or queued | FR-006, FR-007, P-15 | `tests/Feature/Notifications/PushTokenAtRestTest.php`, `tests/Feature/Notifications/TokenNeverLeaksTest.php` |
| A payload carries only its type's keys, never money, names or dates | FR-020, privacy commitments | `tests/Unit/Notifications/PayloadShapeTest.php` |
| One notification per recipient per dedupe key | FR-024 | `tests/Feature/Notifications/DedupeTest.php` |
| One Budget Overrun per budget per period per threshold; one `BudgetExceeded` per overrun | FR-026 | `tests/Feature/Budgeting/BudgetOverrunTest.php` |
| Nothing is sent for a disabled category, inside quiet hours, over the rate limit, or to an inactive account | FR-017, FR-028, FR-011 | `tests/Feature/Notifications/DeliveryPolicyTest.php`, `tests/Unit/Notifications/QuietHoursTest.php` |
| A permanently invalid token deletes its registration and is not retried | FR-034 | `tests/Feature/Notifications/DeliverNotificationTest.php` |
| A delivery rerun never re-sends to a device already sent | FR-036 | `tests/Feature/Notifications/DeliverNotificationTest.php` |
| The `log` gateway cannot boot in production; `fcm` cannot boot without credentials | FR-041 | `tests/Feature/Notifications/GatewayBindingTest.php` |
| Stale preferences version refuses the whole save | FR-013, P-07 rule 3 (applied by choice) | `tests/Feature/Notifications/SavePreferencesTest.php` |
| The purge leaves nothing of the person in Notifications | FR-012, FR-044 | `tests/Feature/Notifications/NotificationsPurgeTest.php` |
| No new Composer or npm dependency | FR-040, Quality Gate 6 | reviewer (CONTRIBUTING item 17); `tests/Arch` gate unchanged |

### 5. API

Under `/api/v1`, in [docs/openapi/paths/notifications.yaml](../../docs/openapi/paths/notifications.yaml), tag `Notifications`, `info.version` **v1.14**. No new error code.

| Method | Path | Success | Error codes |
|---|---|---|---|
| PUT | `/me/devices/{id}` | `200` registration | `UNAUTHENTICATED`, `NOT_FOUND`, `INVALID_ID`, `VALIDATION_FAILED` (`REQUIRED`, `INVALID_TYPE`, `OUT_OF_RANGE`, `UNKNOWN_FIELD`), `RATE_LIMITED` |
| DELETE | `/me/devices/{id}` | `204` | `UNAUTHENTICATED`, `NOT_FOUND`, `INVALID_ID`, `RATE_LIMITED` |
| GET | `/me/notification-preferences` | `200` preferences | `UNAUTHENTICATED` |
| PUT | `/me/notification-preferences` | `200` preferences | `UNAUTHENTICATED`, `VALIDATION_FAILED` (`REQUIRED`, `INVALID_TYPE`, `OUT_OF_RANGE`, `UNKNOWN_FIELD`), `VERSION_CONFLICT`, `RATE_LIMITED` |

**Push payload, frozen for C16** (documented in the path file as the `PushPayload` schema under `x-push-payload`, not an HTTP operation). FCM data values are strings.

| `type` | `category` | Data identifiers (besides `schema`, `type`, `category`, `notification_id`) | Collapse key | Recipient | Deep-link target |
|---|---|---|---|---|---|
| `occurrence_due` | `recurring_due` | `wallet_id`, `occurrence_id` | `occurrence:{occurrence_id}` | active members allowed to confirm | the occurrence |
| `occurrence_auto_confirm_refused` | `recurring_due` | `wallet_id`, `occurrence_id` | `occurrence:{occurrence_id}` | as above | the occurrence |
| `occurrences_due_summary` | `recurring_due` | — | `occurrences_due` | the person | upcoming items |
| `budget_exceeded` | `budget_exceeded` | `budget_id` | `budget:{budget_id}` | budget owner | the budget |
| `invitation_received` | `sharing` | `invitation_id` | `invitation:{invitation_id}` | verified addressee | "check your email" notice |
| `member_role_changed` | `sharing` | `wallet_id`, `role` | `wallet:{wallet_id}` | the member | the wallet |
| `member_removed` | `sharing` | `wallet_id` | `wallet:{wallet_id}` | the removed member | wallet list |
| `wallet_ownership_received` | `sharing` | `wallet_id` | `wallet:{wallet_id}` | the new owner | the wallet |
| `subscription_ending` | `billing` | — | `subscription` | the person | plan screen |
| `subscription_lapsed` | `billing` | — | `subscription` | the person | plan screen |
| `export_ready` | `export_ready` | `export_id` | `export:{export_id}` | the requester | the export |
| `deletion_reminder` | `deletion_reminder` | — | `deletion` | the person | account deletion screen |

**Preference keys, frozen for C16**: `categories.{recurring_due, budget_exceeded, sharing, billing, export_ready, deletion_reminder}`, `quiet_hours.{enabled, start, end}`. **Quiet-hours behaviour, frozen for C16**: held and delivered at the end, never dropped, latest per collapse key; evaluated in the profile timezone; the server applies them, the app does nothing.

### 6. Authorization and entitlements

Owner only: every operation acts on the caller's own session or own preferences; another person's session id is a `404` (P-08 rule 3 by analogy: existence not disclosed). No wallet role applies to the endpoints. Recipients of wallet-related notifications are decided by Wallets (members permitted to confirm an occurrence under the spec 010 §6 matrix; the member concerned by a role change or removal; the new owner) — Notifications copies no matrix row (P-08 rule 5). No entitlement gates push (P-09 rule 5: the free tier is limited only on sharing). Not behind the verification gate (FR-005).

### 7. Events

**Published (new)**

| Event | Publisher | Payload | Listeners |
|---|---|---|---|
| `BudgetExceeded` | Budgeting (already named in the constitution, first published here) | `eventId`, `occurredAt`, `budgetId`, `userId`, `periodStart`, `threshold` | Notifications |
| `SubscriptionRenewalDue` | Billing, beside its existing renewal reminder stamp | `eventId`, `occurredAt`, `userId`, `subscriptionId` | Notifications |
| `ExportReady` | Identity, when an export becomes `ready` | `eventId`, `occurredAt`, `userId`, `exportId` | Notifications |
| `DeletionReminderDue` | Identity, beside its existing reminder stamp | `eventId`, `occurredAt`, `userId`, `deletionScheduledFor` | Notifications |

**Consumed**: `DeviceSessionEnded` (Identity), `TransactionPosted` (Wallets → Budgeting), `InvitationSent` (Sharing), `MemberRoleChanged`, `MemberRevoked` (Sharing), `WalletOwnershipTransferred` (Wallets), `SubscriptionLapsed` (Billing). All dispatched after commit (P-11 rule 1); every listener queued and idempotent through the dedupe key or the overrun's unique key (rule 2); payloads carry identifiers and scalars only (rule 3). Notifications itself publishes no event. The constitution's table gains the three new event names (PATCH bump).

### 8. Acceptance scenarios

| Story · scenarios | Layer | Test file |
|---|---|---|
| US1 · 1–5, 7 | Contract, Feature | `tests/Contract/Notifications/DevicesTest.php`, `tests/Feature/Notifications/RegisterDeviceTest.php` |
| US1 · 6 | Feature | `tests/Feature/Notifications/RegistrationLifecycleTest.php` |
| US1 · 8 | Feature | `tests/Feature/Notifications/DeliverNotificationTest.php` |
| US2 · 1–4 | Contract, Feature | `tests/Contract/Notifications/PreferencesTest.php`, `tests/Feature/Notifications/SavePreferencesTest.php` |
| US2 · 5–7 | Unit, Feature | `tests/Unit/Notifications/QuietHoursTest.php`, `tests/Feature/Notifications/DeliveryPolicyTest.php` |
| US3 · 1–7 | Feature | `tests/Feature/Notifications/OccurrenceRemindersTest.php`, `tests/Feature/Wallets/OccurrenceReminderQueryTest.php` |
| US4 · 1–6 | Feature, Unit | `tests/Feature/Budgeting/BudgetOverrunTest.php`, `tests/Feature/Notifications/EventNotificationsTest.php` |
| US5 · 1–6 | Feature | `tests/Feature/Notifications/EventNotificationsTest.php`, `tests/Feature/Sharing/InvitationAddresseeTest.php` |
| US6 · 1–5 | Feature | `tests/Feature/Notifications/EventNotificationsTest.php`, `tests/Feature/Billing/RenewalReminderEventTest.php`, `tests/Feature/Identity/ExportAndReminderEventsTest.php` |
| US7 · 1–2 | Feature | `tests/Feature/Admin/NotificationFiguresTest.php` |
| Gateway (FR-038–FR-042) | Feature, Unit | `tests/Feature/Notifications/FcmGatewayTest.php`, `tests/Unit/Notifications/ServiceAccountJwtTest.php`, `tests/Feature/Notifications/GatewayBindingTest.php` |

### 9. Open questions

1. **Firebase project and credentials (owner).** Production needs a Firebase project, a service-account key with the "Firebase Cloud Messaging API" role, and the Android app's `google-services.json` on the client. Until supplied, production runs the `disabled` gateway. Does not block building or testing.
2. **In-app invitation inbox (owner, product).** An invitation push leads to a "check your email" notice, because accepting needs the token from the mail. Listing and accepting invitations addressed to a verified account in-app would change Sharing's token model. Does not block; C16's integration checkpoint is read as "the notification opens the invitation notice".
3. **More moments (product).** `InvitationAccepted` to the inviter, a "nearly at the limit" budget threshold, partner activity in a shared wallet, InstaPay claim decisions for accounts that match the claim address. None in MVP. Does not block.
4. **One template for mail and push (engineering).** The brief's channel abstraction is not built; push text is separate. Revisit when a second channel or many shared moments exist. Does not block.
5. **Adopting the Firebase Admin SDK (owner).** Only if the in-house adapter proves insufficient; a drop-in adapter swap (FR-040), needing dependency approval. Does not block.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: After a session ends, 100% of later notifications skip that phone (verified by the delivery check), and its registration is gone within 1 minute on a healthy queue.
- **SC-002**: 0 push payloads or texts in the test suite carry an amount, a name, a note, an address or a date of money; 0 log lines, job payloads or responses carry a push token.
- **SC-003**: Each moment produces at most one notification per recipient: redelivering any source event or rerunning any pass creates 0 duplicates.
- **SC-004**: A due manual occurrence reaches its eligible members within 1 hour after 09:00 local time on the day it is due (outside quiet hours).
- **SC-005**: A notification created outside quiet hours is handed to the push service within 1 minute when the service is healthy; one created inside quiet hours within 10 minutes after they end.
- **SC-006**: A person can find and change any notification setting in one screen with one save.
- **SC-007**: An invalid push address is pruned on its first failed send, never retried.
- **SC-008**: The hourly reminder pass finishes in under 10 minutes over 10,000 active rules.

## Assumptions

- The Android app obtains an FCM registration token through Firebase Messaging on the device (the client's own dependency decision, C16). The backend never needs the client's Firebase configuration.
- Queue workers run the `database` queue as today; the scheduler runs the new passes (`notifications:occurrence-reminders` hourly, `notifications:release-held` every 5 minutes, `notifications:prune` daily).
- The profile's language and timezone (spec 008) are the only language and timezone for push.
- Spec 007's sessions publish `DeviceSessionEnded` for every end reason, including `account_deleted` at the purge.
- Platform is `android` only (P-17: no iOS-specific work); the payload has no APNs block.
- The FCM HTTP v1 API, its OAuth 2.0 JWT bearer grant and its error codes (`UNREGISTERED`, `INVALID_ARGUMENT`, `SENDER_ID_MISMATCH`, `QUOTA_EXCEEDED`, `UNAVAILABLE`, `INTERNAL`) are as documented by Google at the time of writing; the adapter isolates any change.
