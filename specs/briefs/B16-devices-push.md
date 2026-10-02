# B16 · Devices & push notifications

**Call with**: `/speckit-specify B16` · **Source**: the Phase B brief set, recorded 2026-09-25

## Description

The app registers an FCM token per device session (`PUT /devices/{id}/push-token`); users set notification preferences; the system sends: recurring occurrence due, invitation received, role changed or removed, subscription expiring/expired, InstaPay claim approved/rejected. Localized by the user's locale.

## Considerations

Tokens are tied to device sessions and dropped on logout/revoke. Use a notification channel abstraction so email and push share templates. Quiet hours by user timezone. Do not include amounts in push bodies (lock-screen privacy).

## Reconcile at specify time

The brief predates decisions ratified since. These collide with it; the ratified side wins unless the spec deliberately supersedes it through a decision record or an amendment.

| Brief says | Already decided | Resolution needed |
|---|---|---|
| Device ownership | Glossary puts Device Session under Identity; the constitution's table gives Notifications "devices, push tokens" (banked cosmetic note) | Decide: Notifications owns the push registration only, keyed to Identity's session |
| FCM | No Firebase SDK installed | New dependency — owner approval |
| No amounts in push bodies | Consistent with the baseline | Also no notes |

## Inherited from B7 (spec 014)

- **Due-ness is derived; compute it, there is no stored state to listen for.** An occurrence is due when it is `scheduled` and dated on or before the rule's today, where "today" is the date in the rule's stored IANA `timezone` (not the device's, not the server's). Spec 014 adds no event and no "due" write; a reminder job reads the scheduled occurrences and evaluates due-ness at send time.
- The hourly job `wallets:materialise-occurrences` keeps occurrences materialised and confirms automatic rules; a reminder for an occurrence it is about to confirm automatically is the product's call, not a backend requirement.
- A refused automatic confirmation sets `auto_confirm_refusal` on the occurrence (`not_permitted`, `counter_wallet_unavailable`, `currency_mismatch`); that is the signal worth notifying, not a retry.
