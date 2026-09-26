# Contract: Events, operator notifications, mail

## Domain events (Identity publishes)

Every event is `final readonly`, dispatched after commit (`ShouldDispatchAfterCommit`), and carries `event_id` (UUIDv7) plus `occurred_at` (a UTC instant) in addition to the fields below. Only scalars (P-11).

| Event | Fields | Known consumers |
|---|---|---|
| `UserRegistered` | `user_id`, `locale` | none yet |
| `UserSignedIn` | `user_id`, `session_id`, `platform` | B10 restore-purchases seam |
| `DeviceSessionEnded` | `user_id`, `session_id`, `reason` (a Device Session `end_reason`) | B16, which drops the push registration |
| `UserDeletionRequested` | `user_id`, `scheduled_for` | B15 |
| `UserDeletionCancelled` | `user_id`, `reason` (`explicit` \| `sign_in`), `session_id` (only for `sign_in`) | **Admin** (below), B15 |

Every consumer is queued and deduplicates by `event_id`.

## Operator notification: deletion cancelled by sign-in

**Trigger**: `UserDeletionCancelled` with `reason = sign_in`.
**Listener**: `Admin\Application\Listeners\NotifyOperatorsOfDeletionCancelledBySignIn` (queued).

1. `INSERT … ON CONFLICT (source_event_id) DO NOTHING` into `operator_notifications`:
   - `kind = deletion_cancelled_by_sign_in`
   - `subject_user_id = user_id`
   - `occurred_at`
   - `payload = { session_id }`
2. If a row was inserted and `admin.notifications.recipients` is not empty, queue one `OperatorNotificationMail` per recipient.

**Email** (operator, in English and Arabic, in the operator's language; until D2, `config('app.locale')`):

- Subject: "Account deletion cancelled by sign-in".
- Body: the user id, the instant (UTC plus `admin.timezone`), and one line saying that the account returned to active because someone signed in during the grace period.
- No address, no display name, and no device name. The device name is user-entered text, and could itself identify a person.

**Dashboard**:

- `OperatorNotificationQueryService::latest(int $limit = 20): list<OperatorNotificationView>`
- `unreadCount(): int` (until D2, the count of the last 7 days)
- `OperatorNotificationView`: `id`, `kind`, `subject_user_id`, `occurred_at`, a `title_key` and a `body_key` for translation, and `params` (scalars)

`OperatorNotificationBell` (Livewire) holds only an int count and a list of arrays of scalars. It renders in the shell's `notifications` slot with the `badge (count)`, `dialog`/`slide-over`, `identifier` and `instant` primitives, and re-authorizes on each request (P-18 rule 7) once the D2 guard exists. Until D2 it is rendered only in the gallery, with sample `OperatorNotificationView`s.

## Mail to the person

| Mail | When | Carries a link | Language |
|---|---|---|---|
| Welcome, verify your address | registration of a new address; resend | verify link (24 h) | the request's `Content-Language` |
| Reset your password | reset request, registered address | reset link (60 min) | the profile's locale |
| Password changed | FR-036 | none | the profile's locale |
| Deletion scheduled | FR-051 | none. States the date and "sign in or cancel in the app" | the profile's locale |
| Deletion cancelled by sign-in | FR-054 | none. Names the device name and the instant, and advises a reset | the profile's locale |

Each mail is a queued mailable. None contains a password or a session credential. The two link mails contain their own secret and nothing else sensitive (FR-071).
