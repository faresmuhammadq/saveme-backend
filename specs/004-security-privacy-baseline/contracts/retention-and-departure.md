# Contract: Retention, Export and Departure

Normative. Every period is a number. Nothing here says "a reasonable time".

## Two horizons

The privacy promise wants deleted data gone quickly. The sync protocol requires a deletion marker to survive long enough for a device that was offline to learn the record is gone. Both are true at once, stated separately.

| What | Kept | Fixed by |
|---|---|---|
| Deleted syncable record, content and marker | 90 days | ADR-0008, the tombstone horizon |
| Other soft-deleted records | 30 days | this feature |
| Account after a deletion request | 30 days, then purged | this feature |
| Audit entries | 400 days | this feature |
| Application logs | 30 days | ADR-0010 |
| Idempotency records | 24 hours | spec 003 |
| Password reset token | 60 minutes | this feature |
| Address verification token | 24 hours | this feature |
| Provider message identifiers | 24 hours | this feature |

**Rule**: the published commitments state **90 days** for anything that syncs. That is the longer number, and the only one the rules can actually keep. A promise the retention rule cannot honour is worse than a longer promise honestly made.

## Deletion

```text
requested ──cancel, before the 30 days elapse──▶ cancelled
    │
    └──purge runs, at or after 30 days──▶ completed
```

**Rules**

1. Cancelling inside the grace period loses nothing. The account and every record come back untouched.
2. The person may keep using the account during the grace period, writes included. Using it does not extend or reset the clock.
3. On completion, the rows the person alone owns are deleted **and** the per-account key material is destroyed, so a backup restored afterwards yields nothing readable for them.
4. What is claimed is the removal of their identity attributes and their own records. Not every byte they ever caused to exist, because a shared wallet's history is not theirs alone to delete — and the commitments must not claim otherwise on the same page that describes sharing.

## Departure from a shared wallet

| What the departing person owned | What happens |
|---|---|
| Wallets they own | removed with them |
| Records only they can see | removed |
| Transactions they authored in someone else's wallet | **kept** |
| Their name on those transactions | replaced by a stable anonymous marker that cannot be resolved back to a person |
| Their membership row | removed |

**Why**: deleting their transactions would change other members' balances without those members knowing. Correctness wins for the money; privacy wins for the identity.

**Stated limit**: a revoked or departed member's device still holds whatever it last synced. The baseline says so plainly, rather than implying a remote wipe that does not exist.

## Export

**Rules**

1. Produced asynchronously. A second request while one is in progress is `409 EXPORT_IN_PROGRESS`.
2. Contents: complete structured data, plus a spreadsheet-openable file of transactions — so "a form they can open without this app" is literally true and not a technicality.
3. It contains what the person entered and what they can already see. Not other members' records beyond that.
4. An export requested during the deletion grace period **is served** and does **not** reset the clock. Refusing it strands someone who is leaving; resetting the clock would let a scheduled export postpone a deletion forever.
5. Both `export_requested` and `export_delivered` are recorded.

## Reviewer checks

- [ ] Every retention period in the feature appears in the table above as a number.
- [ ] No published commitment states a shorter period than the table allows.
- [ ] A cancelled deletion loses nothing.
- [ ] A completed deletion leaves no readable identity attribute, including in a restored backup.
- [ ] A shared wallet keeps its history and its balances when one member departs.
- [ ] An export during the grace period is served, and the clock is unchanged.
