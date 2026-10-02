# Quickstart: Recurring Transactions

**Feature**: [spec.md](./spec.md) · **Contract**: [contracts/recurring-api.md](./contracts/recurring-api.md)

Validation guide for after implementation. Everything runs in the container (host PHP has no `pdo_pgsql`).

## Prerequisites

```bash
dev project open
dev migrate
dev db:seed            # platform categories (spec 011)
```

A verified user with an EGP wallet and a bearer token (see spec 012's quickstart for creating them); headers `X-Client-Platform: android`, `X-Client-Version: 1.0.0` on every request.

## Gates

```bash
dev exec -- vendor/bin/pest tests/Contract/OpenApi          # contract skeleton, v1.7 indexed
dev exec -- vendor/bin/pest tests/Unit/Wallets/Recurring tests/Unit/Shared
dev exec -- vendor/bin/pest tests/Feature/Wallets/Recurring tests/Feature/Sync/RecurringPurgeTest.php
dev exec -- vendor/bin/pest tests/Contract/Wallets/Recurring tests/Contract/Sync
dev exec -- composer test                                   # whole gate before a PR
```

## Scenarios

1. **Create a rule** — `PUT /api/v1/wallets/{wallet}/recurring-rules/{new uuidv7}` with the `createRent` example from `recurring.yaml` → `201`, `timezone: Africa/Cairo`. `GET /wallets/{wallet}/occurrences?filter[recurring_rule_id]=eq:{rid}` → at least one `scheduled` occurrence on the 1st. Wallet `balance_minor` unchanged.
2. **Month-end** — create a monthly rule with `anchor_day: 31`, `start_date: 2027-01-31`; occurrences on 2027-01-31, 2027-02-28, 2027-03-31.
3. **Auto-confirm, idempotently** — set a rule's start to today; `dev wallets:materialise-occurrences` twice → one transaction with `occurrence_id` set; occurrence `confirmed` with `transaction_id`; balance moved once.
4. **Confirm offline-style** — on a manual rule, `PUT /wallets/{wallet}/occurrences/{oid}` with the `confirmWithActualAmount` example (new `transaction_id`, current `version`) → `200`, `GET /wallets/{wallet}/transactions/{transaction_id}` → the transaction. Resend the same body → `200`, nothing new. Resend with the old version and another `transaction_id` → `409` with the confirmed occurrence.
5. **Skip, un-skip** — `status: skipped` then `status: scheduled` → `200` each.
6. **Unknown occurrence** — `PUT` to a fresh UUIDv7 → `404`.
7. **Installment split** — `createInstallment` example → twelve occurrences, four of 83,334 and eight of 83,333 minor units, summing to 1,000,000.
8. **Gam'eya** — count 10, payout round 4 → ten expense occurrences and one `is_payout: true` income occurrence worth 10 × the contribution on round 4's date.
9. **Series from a date** — `endBeforeRaise` example then a new rule from 2027-01-01 → no occurrence of the old rule after 2026-12-31; with a confirmed occurrence on 2027-01-01, the first write is `422 OUT_OF_RANGE` at `/end_date`.
10. **Feed and batch** — `GET /sync/wallets/{wallet}/changes` shows `recurring_rule` and `occurrence` rows; `POST /sync/batch` with a `recurring_rule` upsert and an `occurrence` upsert returns one result per item equal to the single writes.
