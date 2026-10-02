# Contract: Recurring Transactions API (v1.7)

**Source of truth**: [`docs/openapi/paths/recurring.yaml`](../../../docs/openapi/paths/recurring.yaml), plus the additive v1.7 changes in [`sync.yaml`](../../../docs/openapi/paths/sync.yaml) (feed types `recurring_rule`, `occurrence`; batch types `recurring_rule` upsert/delete, `occurrence` upsert) and [`transactions.yaml`](../../../docs/openapi/paths/transactions.yaml) (read-only `recurring_rule_id`, `occurrence_id`). Where this summary and the YAML differ, the YAML wins. Frozen for the C7 client.

## Operations

| Method | Path | operationId | Row | Success |
|---|---|---|---|---|
| GET | `/wallets/{id}/recurring-rules` | `listRecurringRules` | R-01 | 200 collection (filters `status`; sorts `created_at`, `start_date`) |
| GET | `/wallets/{id}/recurring-rules/{rid}` | `getRecurringRule` | R-01 | 200 |
| PUT | `/wallets/{id}/recurring-rules/{rid}` | `upsertRecurringRule` | R-02 (+T-02 destination) | 201 create / 200 save or replay |
| DELETE | `/wallets/{id}/recurring-rules/{rid}` | `deleteRecurringRule` | R-02 | 200 tombstone (final) |
| POST | `/wallets/{id}/recurring-rules/{rid}/materialise` | `materialiseRecurringRule` | R-02 | 200 the rule |
| GET | `/wallets/{id}/occurrences` | `listOccurrences` | R-01 | 200 collection (filters `recurring_rule_id`, `status`, `date`; sorts `date` asc, `created_at`) |
| GET | `/wallets/{id}/occurrences/{oid}` | `getOccurrence` | R-01 | 200 |
| PUT | `/wallets/{id}/occurrences/{oid}` | `saveOccurrence` | R-02 (+T-02 destination on confirm) | 200 (never 201; unknown id 404) |

All writes: `403 EMAIL_NOT_VERIFIED` when unverified; `version` in the body; `409 VERSION_CONFLICT` with `current` (tombstone included); identical replay 200 unchanged; non-member 404.

## What C7 binds to

1. **No `planned` transactions.** Upcoming items are Occurrences with `status: scheduled`, materialised by the server through the rule's today + 35 days (and at least the next). They never count in a balance.
2. **Month-end**: `anchor_day` clamped to the month's last day; dates from the origin (no drift).
3. **Occurrence key**: (`recurring_rule_id`, `scheduled_date`, `is_payout`). The phone never creates an occurrence; it previews dates locally with the same rule and confirms only occurrences it received.
4. **Confirm**: `PUT …/occurrences/{oid}` `{status: confirmed, transaction_id: <new UUIDv7>, …}`; the transaction with that id appears in both lists/feeds as any transaction. On 409, drop the local transaction and keep `current`.
5. **Skip / un-skip**: `status: skipped` / back to `scheduled`.
6. **Edit one**: change `date`, `amount_minor`, `category_id`, texts on a scheduled occurrence → `edited: true`.
7. **Series edits**: edit the rule in place (pending, unedited occurrences follow); "from D": end the old rule at D − 1, create a new rule from D. Schedule members are frozen once any occurrence is resolved; for a gam'eya or an `amount_basis: total` rule, `occurrence_count` and `amount_minor` are frozen too (`IMMUTABLE_FIELD`), so shares and the payout keep summing.
8. **"Today"** is the rule's `timezone`; **due** and **missed** are computed on the phone.
9. **Catch-up**: `POST …/materialise`, then pull `GET /sync/wallets/{id}/changes`.
10. **Transactions** gain read-only `recurring_rule_id`, `occurrence_id`; strip them (and the other read-only members) before a transaction PUT.

## Field-level codes used

`IMMUTABLE_FIELD`, `CURRENCY_MISMATCH`, `INVALID_ENUM_VALUE`, `OUT_OF_RANGE`, `REQUIRED`, `UNKNOWN_FIELD`, `INVALID_TYPE`, `AMOUNT_NOT_INTEGER`, `DATE_NOT_CALENDAR_DATE`, `INVALID_CURRENCY`, `INVALID_ID`. No new code; `info.version` v1.6 → v1.7 for the new paths.
