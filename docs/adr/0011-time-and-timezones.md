# ADR-0011: Time and timezones

- **Status**: Accepted
- **Date**: 2026-09-19
- **Principles**: P-12
- **Supersedes**: —
- **Resolves**: —

## Context

This application has two different things that both look like "time", and conflating them produces the class of bug where a shared budget shows two different totals to two people looking at the same wallet.

- An **instant** is a point on the timeline: when a row was created, when a sync write committed, when a subscription period ends. It is the same moment for everybody and has a timezone.
- A **calendar date** is what the user wrote on a transaction: "this happened on the 30th". It is not an instant. It does not have a timezone, and asking what time of day it was is not a meaningful question.

The forces:

- **Shared wallets can span timezones.** A couple with one partner in Cairo and the other in Dubai — or travelling — is the normal case, not an edge case. If period membership depended on the viewer's timezone, a transaction dated the 30th would land in September for one and October for the other, and the same shared budget would show two totals. That is a correctness bug, not a display preference.
- **Egypt's DST is not stable history.** It has been introduced, removed, and introduced again more than once. Any value stored as local-time-plus-offset is interpreted against a rule set that has changed and may change again, and the ambiguous hour at each transition has no single correct reading.
- **Timezones still matter, just not for storage.** "Today", "this month so far", the default range of a report, and the moment a recurring rule becomes due are all questions about the viewer's wall clock.
- **A recurring rule outlives the context it was created in.** A rule set to fire on the 1st in Cairo must keep firing on the 1st in Cairo even when the other member views it from Dubai, and even if the creator later changes their own timezone.
- Postgres ([ADR-0001](0001-database-engine.md)) gives both `timestamptz` and a real `date` type, so the distinction can be enforced by the schema rather than by convention.

The wire formats — instants as ISO 8601 with `Z`, dates as `YYYY-MM-DD` — are fixed in `specs/001-architecture-constitution-adrs/contracts/http-conventions.md`.

## Decision

1. `APP_TIMEZONE` MUST be `UTC`, and the database session timezone MUST be UTC. No environment may set anything else.
2. Instants MUST be stored in `timestampTz` columns at precision 6. They MUST NOT be stored in `timestamp` without a time zone, and MUST NOT be stored as a local time plus a separate offset column.
3. Instants MUST be serialised in JSON as ISO 8601 with a `Z` suffix, for example `"2026-09-15T08:30:00.123456Z"`.
4. Calendar dates — the transaction date, a budget period start, a recurring rule's anchor date — MUST be stored in `date` columns and serialised as `"YYYY-MM-DD"` with no time and no timezone. They MUST NOT be stored as instants.
5. `users.timezone` MUST hold an IANA timezone name (for example `Africa/Cairo`). It MUST NOT hold a UTC offset, an abbreviation, or a numeric zone id.
6. **Period membership MUST be decided by calendar date.** A transaction dated `2026-09-30` belongs to September for every member of the wallet, whatever their timezone. Period assignment MUST NOT consult any user's timezone.
7. As a consequence of rule 6, shared-wallet budget totals MUST be identical for every member of the wallet. A report or budget query MUST NOT produce a member-dependent total.
8. A user's timezone MAY be used only to decide:
   - what "today" and "the current period" mean **for that viewer**;
   - the default date range of a report the viewer opens;
   - when a recurring occurrence becomes due.
   It MUST NOT be used for anything else.
9. A recurring rule MUST store the IANA timezone captured at creation, and its schedule MUST be evaluated in that timezone. It MUST NOT be evaluated in the viewer's timezone or in the timezone of whoever triggers the run.
10. Changing a user's timezone MUST NOT rewrite any stored value — not instants, not dates, not recurring schedules. It changes only how subsequent "today"/current-period questions are answered for that user.
11. Conversion from UTC to a display timezone MUST happen at the read/presentation boundary. Domain and persistence code MUST work in UTC and in bare calendar dates.

## Alternatives Considered

### Store local time with an offset

Keep each value as the user's wall clock plus the UTC offset that applied, so the original local reading is preserved exactly. **Rejected because** an offset is not a timezone: it does not survive a rule change, and Egypt's DST has been introduced and removed more than once, so historical and future values are interpreted against rules that have already moved. The ambiguous hour at each DST transition has no single correct reading, ordering requires normalising every row anyway, and in a shared wallet two members' rows would be stored in two different frames.

### Store the transaction date as an instant

Treat the transaction date as a timestamp (midnight in some zone) so dates and instants share one column type. **Rejected because** the day then flips depending on the zone it is read in: a transaction stored as midnight Cairo on the 30th is the 29th in a Western zone, so period membership — and therefore shared budget totals — becomes viewer-dependent. That is precisely the bug rule 6 exists to prevent. It also invents a time of day the user never entered, which then leaks into comparisons and grouping.

### A per-wallet timezone

Give each wallet its own timezone so shared periods are unambiguous without making dates timezone-free. **Rejected because** it surprises couples in different places — one member's "today" would be governed by a setting the other chose — and it does not remove the need for a per-user timezone anyway, since "today" and report defaults are per-viewer. The real need it addresses is stable recurring schedules, and rule 9 covers that directly by storing the timezone on the rule.

## Consequences

### Positive

- A shared wallet shows one set of numbers. Period membership is a property of the row, so every member sees the same budget total for the same month.
- Nothing has to be reinterpreted when DST rules change, because no stored value depends on an offset.
- Storage is unambiguous by type: `timestamptz` means instant, `date` means calendar date, and the schema prevents mixing them.
- Comparing and ordering instants is a direct column comparison — no per-row normalisation.
- Changing a timezone is a display-level change with no migration, no rewrite and no risk to stored money data.
- A recurring rule keeps its schedule regardless of who looks at it or where the creator later moves.

### Negative

- Two concepts that both look like "time" must be kept apart deliberately, and every new column is a decision. Storing a calendar date as an instant is an easy mistake that only shows up as a cross-timezone reporting bug.
- A user's `2026-09-30` is a date the client resolved from the user's wall clock. If the client resolves "today" in the wrong zone, the server records a date it cannot second-guess.
- A transaction entered just before midnight in a far-away zone can land on a date that looks off to the other member, because the date is the writer's local day and is then shared as-is.
- Every report needs an explicit decision about whose timezone determines the default range, since the data itself no longer answers it.
- Recurring rules carry a timezone that can drift from the creator's current one — correct by rule 10, but it needs explaining in the UI.

### Follow-ups

- The client contract for resolving "today" before sending a calendar date is specified with the first feature that writes a dated entity.
- Revisit only if the product introduces a genuinely instant-based financial concept — for example an intraday, timestamped ledger — where period membership would need a defined cut-off rather than a bare date.
