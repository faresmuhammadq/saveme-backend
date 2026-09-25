# B15 · Export & data deletion

**Call with**: `/speckit-specify B15` · **Source**: the Phase B brief set, recorded 2026-09-25

## Description

Users export a wallet or all their data as CSV/XLSX (`POST /exports` → job → signed download URL, 24h) and complete account deletion after the grace period: owned wallets with other members get ownership transferred to the oldest editor (or are deleted if none), subscriptions are canceled at provider where possible, personal data is purged, and an anonymised audit trail remains.

## Considerations

Exports run queued and are rate-limited (one active per user). Deletion is a scheduled job, idempotent and resumable. Play subscriptions cannot be canceled server-side — document that the user must cancel in Play and the deletion email says so.

## Owner decisions (2026-09-25)

- **Transactions the departing user entered in a shared wallet are kept, with their reference to that user.** Other members' history and balances do not change.
- **Ownership of a shared wallet the departing user owns transfers**, never deleted:
  - with one other member, to that member;
  - with more than one other member, the departing user **chooses** the new owner before leaving; if they do not, it goes to the oldest editor.
- The spec must settle: what happens when the only other member is a viewer (promote them, or make them owner directly); what the reference shows once the account is purged, given the security baseline destroys the name and the privacy page promises "your name comes off them"; and the fallback when there is no editor at all.
- **Amends the security baseline** ("Leaving a shared wallet" — owned wallets were removed with their owner) and **the privacy commitments** ("Leaving"), in both languages, in the same change.

## Reconcile at specify time

The brief predates decisions ratified since. These collide with it; the ratified side wins unless the spec deliberately supersedes it through a decision record or an amendment.

| Brief says | Already decided | Resolution needed |
|---|---|---|
| Owned shared wallets transfer to the oldest editor | Security baseline, Leaving a shared wallet: "wallets they own — removed with them" | Direct conflict, and the brief is the better rule: deleting a shared wallet destroys the other members' history, which the baseline's own reasoning forbids. Amend the baseline |
| XLSX | No spreadsheet package installed; the baseline says "spreadsheet-openable" | CSV needs nothing; XLSX is a new dependency |
| One active export per user | Reserved: `409 EXPORT_IN_PROGRESS` | Use it |
| Anonymised audit trail | Audit entries carry no protected values; departed members become a stable anonymous marker | Compatible |
