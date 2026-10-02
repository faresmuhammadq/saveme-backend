# Feature Specification: Recurring Transactions

**Feature Branch**: `014-recurring-transactions`

**Created**: 2026-10-02

**Status**: Draft

**Input**: Brief B7 ([specs/briefs/B07-recurring-transactions.md](../briefs/B07-recurring-transactions.md)): "A recurring rule (wallet, template transaction fields, frequency daily/weekly/monthly/yearly with interval and anchor day, start date, end date or occurrence count) generates occurrences. A scheduled job materialises due occurrences as real transactions daily; the client can also request `POST /recurring/{id}/materialise` for catch-up. Users can skip one occurrence, edit one occurrence, or edit the series from a date forward. Two named variants seeded as presets: gam'eya (monthly contribution, payout month) and installment (monthly, fixed count)." Plus the brief's reconcile table, its binding "Inherited from B5/B6/B14" sections, and the paired client brief [C7](../../../saveme-kmp/specs/briefs/C07-recurring.md) "Needs from the backend" (whether occurrences are created ahead as `planned`, the month-end anchoring rule, the occurrence key, the series-edit semantics).

> **Nature of this feature.** B7 lets a person set a repeating money movement up once — rent, a salary, a loan installment, a gam'eya (جمعية) round — and stop typing it every month. It builds the Recurring Rule and its Occurrences (both wallet-scoped and syncable), the server-side materialisation that keeps upcoming occurrences on every member's phone, the confirm/skip/edit-one workflow, the gam'eya and installment presets, and the hourly job that confirms automatic rules. Every confirmed occurrence becomes an ordinary Transaction through B6's transaction service, so every ledger rule holds unchanged. It builds no reminders or push (B16), no budgets (B8) and no reports (B13).
>
> **Where the brief and the ratified decisions differ, the ratified side wins** (constitution, glossary, ADR-0008, ADR-0011, ADR-0015, ADR-0016, specs 010–013):
>
> | Brief | In force | Here |
> |---|---|---|
> | "Occurrences are ordinary transactions with `recurring_rule_id`" | Glossary: **Occurrence** is its own persisted, wallet-scoped syncable entity, child of the Recurring Rule, materialised ahead | An Occurrence is materialised ahead and, when confirmed, **produces** one Transaction; the Transaction carries read-only `occurrence_id` and `recurring_rule_id`. Never both an occurrence and a "planned transaction" |
> | Occurrences created ahead as `planned` transactions, excluded from balances | P-05: balance = opening + Σ postings | Upcoming items are Occurrences (`status: scheduled`), which have **no postings**; only a confirmed occurrence's Transaction posts. No `planned` transaction status exists |
> | "A scheduled job materialises due occurrences as real transactions daily" | Glossary: an occurrence is **confirmed** by the user; matrix row R-02 "confirm or skip an occurrence" | A rule chooses `auto_confirm`: automatic rules are confirmed by the server when due; manual rules wait for a member to confirm or skip. The job runs **hourly** so each rule's local midnight is met within an hour |
> | "Time zone of the user decides today" in a shared wallet | ADR-0011 rule 9, glossary: the rule stores the IANA timezone captured at creation | "Today" for a rule is evaluated in the rule's own stored timezone, the same for every member |
> | Split amounts for gam'eya and installments | P-03 rule 4: `Money::allocate()` | A rule may state a **total** to be split over its occurrences; the parts come from `Money::allocate()` and sum exactly to the total |
> | `POST /recurring/{id}/materialise` | Spec 003 / 012: wallet-scoped resources live under `/wallets/{id}/…` | `POST /wallets/{id}/recurring-rules/{rid}/materialise` |
> | "Seeded as presets" | Presets are behaviour, not reference data (glossary: presets of the rule, not entities) | `preset` is a fixed enum (`custom`, `gameya`, `installment`) with its own validation; nothing is seeded |

## Clarifications

### Session 2026-10-02

Owner away with delegated authority; each answer below is the recommended (most conservative) option, recorded as an **assumed default**.

- Q: Who generates occurrences — the server, the phone, or both? → A: **Only the server.** The server materialises every occurrence (on a rule write, on the hourly job, and on the materialise request); the phone never creates one and a write to an unknown occurrence id is not-found. A phone that is offline previews upcoming dates locally with the same schedule rule, but confirms only occurrences it has received. Two generators could produce two occurrences for one due date; one generator plus a unique occurrence key cannot. Recorded in ADR-0018. *Assumed default.* (FR-008, FR-009)
- Q: How is double-posting prevented when a phone confirms offline while the server auto-confirms the same occurrence? → A: **Confirming is a versioned write on the occurrence**, carrying a client-generated transaction id. The server's auto-confirm is the same transition under the same row lock and bumps the version, so whichever lands second is a version conflict carrying the confirmed occurrence; an occurrence links to at most one transaction and a transaction to at most one occurrence. *Assumed default.* (FR-015, FR-016, FR-019)
- Q: Are `due` and `missed` stored states? → A: **No — they are derived** from the occurrence's date and the rule's timezone ("due": scheduled and dated on or before the rule's today; "missed": due while a later occurrence of the same rule is also due). Storing them would make the server rewrite every occurrence on its due day and turn that housekeeping into version conflicts against members' edits. The stored states are `scheduled`, `confirmed` and `skipped`. *Assumed default; the glossary entry is amended.* (FR-013)
- Q: What does "edit the series from a date forward" mean? → A: Two operations. **Edit in place**: saving the rule changes every occurrence not yet confirmed, skipped or individually edited; occurrences already resolved never change; schedule members (frequency, interval, anchor day, start date, amount basis, payout round) are frozen once any occurrence of the rule is resolved. **From a date D**: the client ends the rule at D − 1 (`end_date`) and creates a new rule starting D; the first write is refused if an occurrence after D − 1 is already resolved, so no period is ever posted twice. *Assumed default.* (FR-006, FR-007)
- Q: When a rule is created with a start date in the past, or resumes after a pause, does the server post the occurrences that fell due in the meantime? → A: **Never automatically.** Past occurrences are materialised (at most 90 days before the rule's creation day) for the member to confirm or skip; an automatic rule posts only occurrences dated on or after the day automatic confirmation last took effect (creation, resume, or switching `auto_confirm` on). *Assumed default.* (FR-010, FR-017)

- Q: May a member move one occurrence's date outside the rule's window (before `start_date`, after `end_date`, past the next occurrence)? → A: **Yes**, to any calendar date 1900-01-01–2999-12-31; the occurrence keeps its `scheduled_date` (its key and round), so moving it never creates or removes another occurrence. A bill paid late or early is common, and refusing it would push people to record by hand and break the link. *Assumed default.* (FR-014)
- Q: Does a skipped occurrence still count toward `occurrence_count`, and toward a gam'eya payout or an installment total? → A: **Yes.** `occurrence_count` counts rounds of the schedule whatever their outcome, and the payout and the total's shares are computed from the scheduled rounds, never re-spread over what remains. A skip is a decision about one round, not a change to the series; re-spreading would silently change amounts the person already agreed to. *Assumed default.* (FR-010, FR-012)
- Q: Does the rule list return ended and paused rules by default? → A: **Yes**, every live (non-deleted) rule whatever its status, filterable by `status`; tombstones are read by id or through the feed, as for transactions. *Assumed default.* (FR-022)

Further defaults taken while writing the spec, each the conservative option and listed again in the plan's Assumed defaults:

- Month-end anchoring: a monthly or yearly occurrence falls on `anchor_day`, clamped to the month's last day (the 31st → 30 April, 28/29 February); each date is computed from the rule's origin, never from the previous occurrence, so a clamped month never drags later ones.
- A recurring transfer rule follows ADR-0015: it lives in the source wallet's scope and feed only; the transactions it produces ride both wallets' feeds as every transfer does.
- A gam'eya rule's contributions are expenses and its payout is one extra income occurrence on the payout round's date, worth the sum of the scheduled contributions.
- The transaction a confirmed occurrence produces is recorded as the rule's author (`created_by`), as brief B7's inherited B6 note says, whoever confirmed it; it is `cleared: false`.
- Deleting a rule is final (no restore) and tombstones all its occurrences; the transactions it already produced stay.
- An ended rule is immutable except for deletion; its remaining occurrences can still be confirmed or skipped.
- No new error code; no rate limit, no entitlement, no seeder; no new domain event.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Set up a monthly bill once (Priority: P1)

A person pays 4,500 EGP rent from Checking on the 1st of every month. They create a monthly rule once, possibly offline. Their phone, and their partner's, show the next rent as upcoming; it is not in the balance until it happens. On the 1st (in the rule's timezone) the rent is posted automatically, exactly once, even if the job runs twice or the phone retries.

**Why this priority**: this is the feature. Everything else (presets, edits, gam'eya) is a variation of a rule that produces occurrences.

**Independent Test**: as an editor of a wallet, create an automatic monthly rule starting next month; read its occurrences: one or more `scheduled`, the wallet balance unchanged; advance the clock past the first date and run the job twice: exactly one transaction, linked to the occurrence, balance moved once.

**Acceptance Scenarios**:

1. **Given** a verified owner or editor, **When** they create a rule with a new identifier, a frequency, interval, anchor day, start date, a template (kind, positive amount in the wallet's currency, category or destination, texts) and `auto_confirm`, **Then** it is created at version 1, `status: active`, with the author's profile timezone, and its occurrences up to the horizon are materialised in the same unit of work as `scheduled`.
2. **Given** a scheduled occurrence, **When** any member reads the wallet's balance, **Then** the occurrence is not in it (an occurrence has no postings).
3. **Given** an automatic rule whose occurrence's date has arrived in the rule's timezone, **When** the job runs (once or several times, or alongside a materialise request), **Then** exactly one transaction is recorded with the occurrence's values, `occurrence_id` and `recurring_rule_id` set, `created_by` the rule's author, and the occurrence becomes `confirmed` carrying `transaction_id`.
4. **Given** a create sent twice with the same body, **When** the second arrives, **Then** it succeeds with the same rule and the same occurrences, and nothing else changes.
5. **Given** a rule with an end date or an occurrence count, **When** the schedule runs out, **Then** no occurrence is materialised past it.
6. **Given** a monthly rule anchored on the 31st, **When** occurrences are materialised for April and February, **Then** they fall on 30 April and 28 (or 29) February, and May's is again the 31st.
7. **Given** a viewer, a non-member, or an unverified person, **When** they try to create, edit or delete a rule, **Then** they are refused with forbidden, not-found or the verification code respectively.

---

### User Story 2 - Confirm, skip or adjust one occurrence (Priority: P1)

A person's electricity bill is monthly but its amount varies. Their rule is manual: when the bill arrives they open the upcoming occurrence, change the amount to 612 EGP and confirm it, offline if need be. In August they are away and skip it. Their partner confirms the internet bill on another phone at the same moment as they do; one confirmation lands, the other sees a conflict with the confirmed occurrence, and only one transaction exists.

**Why this priority**: the glossary and the permission matrix define the occurrence's life as confirm or skip; without it a manual rule produces nothing.

**Independent Test**: on a manual rule, save one occurrence with a new amount (it becomes `edited`), confirm it with a client-generated transaction id: one transaction with that id and amount; replay the confirm: nothing changes; skip the next occurrence; un-skip it; try to confirm it twice from two stale copies: one 200, one 409.

**Acceptance Scenarios**:

1. **Given** a scheduled occurrence and its version, **When** a writer saves it with a different date, amount, category, description, memo or counterparty, **Then** the change is stored at version + 1, the occurrence is marked `edited`, and later edits of the rule no longer change it.
2. **Given** a scheduled or skipped occurrence, **When** a writer saves it with `status: confirmed` and a new transaction identifier, **Then** one transaction with that identifier and the occurrence's values (as sent in the same write) is recorded through the ledger, the balance moves, and the occurrence is `confirmed` with `transaction_id`.
3. **Given** a confirm already applied, **When** the identical write arrives again, **Then** it succeeds with nothing changed and no second transaction.
4. **Given** two writers holding the same version, **When** both confirm (or one confirms while the server auto-confirms), **Then** exactly one succeeds and the other gets a version conflict carrying the confirmed occurrence; one transaction exists.
5. **Given** a scheduled occurrence, **When** a writer saves it with `status: skipped`, **Then** it is skipped with no transaction; **when** they save it back to `scheduled`, **Then** it is pending again.
6. **Given** a confirmed occurrence, **When** a writer tries to change any of its members, **Then** it is refused with an immutable-field error at that member; the produced transaction is edited, deleted or restored through the transaction endpoints instead.
7. **Given** an occurrence identifier the server never materialised, **When** a writer saves it, **Then** it is not-found: a client cannot create an occurrence.
8. **Given** an automatic rule whose occurrence cannot be confirmed when it falls due (the author no longer holds T-02, the transfer's destination is deleted or no longer theirs, or currencies no longer match), **When** the job reaches it, **Then** it stays `scheduled` with `auto_confirm_refusal` set to the reason, is not retried by the job, and any writer may still confirm it (after fixing the cause) or skip it.

---

### User Story 3 - Change the series (Priority: P2)

The landlord raises the rent from January. The person edits the rule "from 1 January": the rule ends on 31 December and a new rule starts on 1 January at the new amount. Earlier, before the first payment, they had simply corrected the rule's description, and every upcoming occurrence followed. They pause the gym membership for the summer and resume it in September; nothing is posted for the summer months without their say. They end the series when the loan is paid off.

**Why this priority**: the brief and C7 require series edits; a rule that cannot change is abandoned and re-created, losing its history.

**Independent Test**: edit a rule's template: every unresolved, unedited occurrence follows, resolved and edited ones do not; try to change the frequency after one occurrence is confirmed: refused; end the rule at D − 1 and create a new one from D; try to end it before an already-confirmed occurrence: refused; pause and resume an automatic rule across a due date: that occurrence stays scheduled for a manual decision.

**Acceptance Scenarios**:

1. **Given** a rule and its version, **When** a writer changes template members (amount, category, description, memo, counterparty, `auto_confirm`), **Then** every occurrence of it that is still `scheduled` and not `edited` takes the new values in the same unit of work; confirmed, skipped and edited occurrences keep theirs.
2. **Given** a rule none of whose occurrences is confirmed or skipped, **When** a writer changes schedule members, **Then** its pending, unedited occurrences are recomputed: those no longer on the schedule are tombstoned, new dates are materialised.
3. **Given** a rule with at least one confirmed or skipped occurrence, **When** a writer changes `frequency`, `interval`, `anchor_day`, `start_date`, `amount_basis` or `payout_round`, **Then** it is refused with an immutable-field error at that member.
4. **Given** a rule, **When** a writer sets an end date (or occurrence count) that would cut off an occurrence already confirmed or skipped, **Then** it is refused as out of range at that member; **otherwise** pending occurrences beyond the new end are tombstoned in the same unit of work.
5. **Given** an active rule, **When** a writer pauses it, **Then** no further occurrences are materialised or auto-confirmed while paused; existing occurrences can still be confirmed or skipped. **When** they resume it, **Then** materialisation continues and only occurrences dated from the resume day on are auto-confirmed.
6. **Given** a rule, **When** a writer ends it, **Then** its pending occurrences dated after the rule's today are tombstoned, earlier ones stay for a decision, and the rule accepts no further change except deletion.
7. **Given** a rule and its version, **When** a writer deletes it, **Then** the rule and all its occurrences are tombstoned; the transactions it produced stay untouched; deleting it again returns the tombstone.

---

### User Story 4 - Gam'eya and installment presets (Priority: P2)

A person joins a 10-member gam'eya of 1,000 EGP a month and takes the payout in month 4. They pick the gam'eya preset: ten monthly contributions and, in round 4, one 10,000 EGP payout as income. Another buys a fridge for 10,000 EGP over 12 monthly installments: they enter the total and the count; the installments are 834 EGP for the first four and 833 EGP for the rest, adding up to exactly 10,000.

**Why this priority**: the two presets are named in the brief and the glossary and are the most Egyptian use of the feature, but they are variations of US1.

**Independent Test**: create a gam'eya rule with count 10, payout round 4: ten expense occurrences and one income payout occurrence on round 4's date worth ten contributions; create an installment rule with `amount_basis: total`, 1,000,000 minor units over 12: the occurrence amounts sum exactly to the total and differ by at most one minor unit.

**Acceptance Scenarios**:

1. **Given** the gam'eya preset, **When** a rule is created, **Then** it must be monthly, interval 1, kind expense, with an occurrence count (2–120), a payout round within the count and an income payout category; it materialises one contribution per round and one payout occurrence (`is_payout: true`, kind income) on the payout round's date worth the sum of the scheduled contributions.
2. **Given** the installment preset, **When** a rule is created, **Then** it must be monthly, interval 1, an expense or transfer, with an occurrence count (2–120) and no payout members.
3. **Given** `amount_basis: total` with an occurrence count, **When** occurrences are materialised, **Then** each round's amount is its share of the total split evenly with the remainder going one minor unit at a time to the earliest rounds, and the shares sum exactly to the total.
4. **Given** a preset rule whose members break the preset's shape, **When** it is written, **Then** it is refused with a field error at each offending member.

---

### User Story 5 - Catch up and stay in sync (Priority: P2)

A person opens the app after a week offline. The app asks the server to catch up their rules, then pulls the wallet feed: every occurrence and every transaction an automatic rule posted in the meantime arrives, in order, with tombstones. Their partner's phone, in Dubai, shows the same upcoming dates as theirs in Cairo. A batch of offline edits — a new rule, a confirmed occurrence, a skipped one — uploads in one request.

**Why this priority**: the app is offline-first; without the feed and the batch, rules work only online.

**Independent Test**: request materialise on a rule whose job run was missed: due automatic occurrences are confirmed and the horizon extended; pull the wallet feed: `recurring_rule` and `occurrence` rows in commit order; upload a batch with a rule upsert, an occurrence upsert and a rule delete: one result per item, each identical to its single write.

**Acceptance Scenarios**:

1. **Given** a rule, **When** a writer requests materialise, **Then** the server does exactly what the job would do for it now — extends its horizon, auto-confirms due occurrences of an active automatic rule — and returns the rule; a second request changes nothing.
2. **Given** rule and occurrence writes by people or by the server, **When** a member pulls the wallet feed, **Then** each appears as a `recurring_rule` or `occurrence` row, positioned by the wallet's counter in commit order, tombstones included, rendered exactly as its read.
3. **Given** a batch item of type `recurring_rule` (`upsert`, `delete`) or `occurrence` (`upsert`), **When** the batch is applied, **Then** its result equals the single write's status and body.
4. **Given** members in different timezones, **When** they read a rule and its occurrences, **Then** they see the same dates and the same timezone; "today" for the rule is the rule's.
5. **Given** a tombstoned rule or occurrence older than 90 days, **When** the purge runs, **Then** it is removed and the wallet's purge horizon raised — except a rule while any of its occurrences remains, and an occurrence while a transaction refers to it.

---

### Edge Cases

- **Month-end and leap days.** Anchor 29, 30 or 31 clamps to the month's last day; a yearly rule anchored on 29 February falls on 28 February in common years. Dates are computed from the origin, never from the previous occurrence.
- **Start date not on the anchor.** The first occurrence is the first schedule date on or after `start_date`; for monthly and yearly, schedule months are counted from `start_date`'s month.
- **Weekly and daily.** A weekly rule repeats on `start_date`'s weekday every `interval` weeks; a daily rule every `interval` days. `anchor_day` must be null for both.
- **Start date far in the past.** Occurrences dated more than 90 days before the rule's creation day (in its timezone) are never materialised; their rounds still count toward `occurrence_count`.
- **A rule with thousands of due dates.** At most 400 occurrences are materialised per rule per run; the next run continues.
- **Horizon.** Occurrences are materialised up to 35 days after the rule's today, and always at least the next one (a yearly rule shows next year's).
- **Rule created offline days ago.** Its first occurrences, dated before the server saw it, are materialised `scheduled` for the member to confirm or skip — never auto-confirmed.
- **Destination wallet of a recurring transfer deleted, or the author left it.** The occurrence stays `scheduled` with `auto_confirm_refusal`; nothing is retried automatically.
- **Author leaves the wallet (B9).** Automatic confirmations stop (`not_permitted`); other writers can still confirm manually, the transaction still recorded as the author's, like any departed member's transaction.
- **Produced transaction deleted.** The occurrence stays `confirmed` pointing at the tombstone; the member restores the transaction or records afresh. Un-confirming is not offered: it would invite double-posting.
- **Category archived after the rule was set.** Allowed, as in B6. A category in use by a rule (live or tombstoned) cannot be deleted.
- **Edit to an occurrence's category by another member.** Same as B6: an unchanged category is kept whoever owns it; a new one must be the writer's own or a platform one.
- **Confirming a future occurrence early.** Allowed; the transaction takes the occurrence's (possibly edited) date, which may be in the future.
- **Occurrence of a tombstoned or ended rule.** Of a tombstoned rule: a tombstone itself, refused as a version conflict on write. Of an ended rule: still confirmable or skippable.
- **Client sends `timezone`, `created_by`, `change_seq`, `scheduled_date`, `kind` on an occurrence, `transaction_id` without `confirmed`, …** Server-owned or immutable members are refused as unknown fields; `transaction_id` must be null unless the status is `confirmed`.
- **The same transaction id sent to confirm two occurrences, or one that already names another record.** The second is refused at `/transaction_id`.
- **A rule edit lands while a member edits an occurrence offline.** The rule edit updates the pending, unedited occurrence (version + 1), so the member's later save is a version conflict carrying the updated occurrence; the client merges and resends (P-07). An `edited` occurrence is never touched by a rule edit, so it never conflicts this way.
- **Wallet currency changed after a rule was set** (possible only while the wallet has no transaction, spec 010). The rule and its occurrences keep their currency; the rule can still be paused, ended, edited or deleted (its currency is not re-checked), but confirmations are refused (`currency_mismatch` automatically, `CURRENCY_MISMATCH` at `/status` manually). The wallet's currency lock stays transaction-based (glossary); the client recreates the rule.
- **Author's account deleted (B15).** Out of this feature: B15 decides what happens to the departing author's rules; until then automatic confirmation is refused as `not_permitted` once the author is no longer a member.
- **A confirmed occurrence's transaction tombstone purged after 90 days.** The occurrence stays `confirmed` with a `transaction_id` that no longer resolves; clients treat it as a deleted transaction.
- **A total smaller than the number of rounds.** Refused at `/amount_minor` (every round must be at least one minor unit).
- **DST.** The rule's timezone decides the calendar day; no instant is stored for a due date (ADR-0011).

## Requirements *(mandatory)*

### Functional Requirements

**Rules**

- **FR-001**: A writer MUST be able to create a recurring rule offline-first, addressed by a client-generated UUIDv7, under the wallet it belongs to (for a transfer, the source). A repeated create MUST NOT produce a second rule or second occurrences (P-06 rule 2).
- **FR-002**: A rule MUST carry: `preset` (`custom` | `gameya` | `installment`, immutable); `status` (`active` | `paused` | `ended`); the template — `kind` (`income` | `expense` | `transfer`, immutable), `amount_minor` (positive, 1 to 2^53 − 1), `amount_basis` (`per_occurrence` | `total`), `currency` (the wallet's, immutable), `category_id` (income/expense; null for a transfer), `counter_wallet_id` (transfer only, immutable), `description` (≤ 100), `memo` (≤ 1000), `counterparty` (≤ 100), `auto_confirm` (boolean); the schedule — `frequency` (`daily` | `weekly` | `monthly` | `yearly`), `interval` (1–365), `anchor_day` (1–31 for monthly and yearly, null otherwise), `start_date`, at most one of `end_date` (≥ `start_date`) and `occurrence_count` (1–1000), both null meaning open-ended; and, for the gam'eya preset only, `payout_round` (1 to the count) and `payout_category_id` (an income category). The server records `timezone` (the author's profile timezone at creation — the configured default timezone if the author has no profile — IANA, never changes) and `created_by`. Dates are calendar dates 1900-01-01 to 2999-12-31; texts trimmed, blank stored as null.
- **FR-003**: The template MUST obey B6's rules for the transaction it will produce, checked against the writer on create, and on a later save only for members that change (`currency`, `kind` and `counter_wallet_id` never change, so they are checked once; a category or destination is re-checked only when it changes): currency equal to the wallet's (and destination's), `CURRENCY_MISMATCH` otherwise; the category (and payout category) of the right kind, live (archived allowed), and the writer's own or a platform one unless unchanged from the stored rule; a transfer's destination a live wallet other than the source in which the writer holds T-02. Refusals reuse B6's codes and pointers.
- **FR-004**: Presets MUST constrain the shape: `gameya` — monthly, interval 1, kind expense, `occurrence_count` 2–120, `end_date` null, `payout_round` and `payout_category_id` required; `installment` — monthly, interval 1, kind expense or transfer, `occurrence_count` 2–120, `end_date` null; `custom` and `installment` — payout members null. `amount_basis: total` requires `occurrence_count` and an `amount_minor` of at least `occurrence_count` minor units (so every round's share is positive), else `OUT_OF_RANGE` at `/amount_minor`. A member outside its preset's shape is `INVALID_ENUM_VALUE` (or `REQUIRED`) at that member.
- **FR-005**: A create MAY set `status` to `active` or `paused`; `ended` is refused at `/status`. Status moves `active` ⇄ `paused`, and `active` | `paused` → `ended`. An ended rule MUST refuse every change of a member (`IMMUTABLE_FIELD` at that member); it may still be deleted.
- **FR-006**: A writer MUST be able to save the whole rule carrying their last-seen version, under spec 012's discipline: stale version → `409 VERSION_CONFLICT` with the current rule (tombstone included); identical write → 200 with nothing changed (no version, `change_seq` or occurrence movement); no merge; version above 0 for an unknown id → not-found. A saved template change MUST be applied in the same unit of work to every occurrence of the rule that is `scheduled` and not `edited`.
- **FR-007**: Once any occurrence of a rule is confirmed or skipped, `frequency`, `interval`, `anchor_day`, `start_date`, `amount_basis` and `payout_round` MUST be immutable (`IMMUTABLE_FIELD`), and `end_date` / `occurrence_count` MUST NOT be moved so as to exclude a confirmed or skipped occurrence (`OUT_OF_RANGE` at that member). For a gam'eya rule or an `amount_basis: total` rule, `occurrence_count` and `amount_minor` are also frozen once any occurrence is resolved, because they fix every round's share and the payout (amended in QA, 2026-10-02: a changed amount re-split only the pending rounds, so the shares no longer summed to the total and a gam'eya payout no longer matched its rounds); before that, a count below `payout_round` is `OUT_OF_RANGE` at `/occurrence_count`. Before that, a schedule change recomputes the pending, unedited occurrences (tombstoning those off the schedule, materialising new dates). Shortening the series tombstones pending occurrences beyond the new end; ending the rule tombstones pending occurrences dated after the rule's today.
- **FR-008**: A writer MUST be able to delete a rule carrying its version: the rule and all its occurrences are tombstoned in one unit of work (P-07 rule 5); transactions it produced are untouched. Deleting a tombstone returns it unchanged. A deleted rule cannot be restored; restore is not offered.

**Occurrences and the schedule**

- **FR-009**: Only the server MUST create occurrences (ADR-0018). Each occurrence is identified for idempotency by its **occurrence key** — (`recurring_rule_id`, `scheduled_date`, `is_payout`) — unique among the rule's occurrences, live or tombstoned; materialising an existing key never creates a second occurrence. Occurrence identifiers are server-generated UUIDv7.
- **FR-010**: The schedule MUST be: daily — `start_date` + k·interval days; weekly — `start_date` + 7·k·interval days; monthly — in month (month of `start_date`) + k·interval, on `anchor_day` clamped to the month's last day; yearly — in year (year of `start_date`) + k·interval, in `start_date`'s month, on `anchor_day` clamped. Dates before `start_date` are dropped; rounds are numbered from 1 (`ordinal`) over the remaining dates; the series stops after `end_date` or the `occurrence_count`-th round; every round counts, whether confirmed, skipped or pending. Each date is computed from the origin, never from the previous occurrence.
- **FR-011**: For every live rule that is not paused or ended, the server MUST keep materialised every scheduled date from max(`start_date`, creation day − 90 days) through the rule's today + 35 days, and at least the next date after the rule's today if the schedule has one — on every rule write (create or edit), on every job run, and on every materialise request; at most 400 new occurrences per rule per pass. "Today" is the current calendar date in the rule's `timezone`.
- **FR-012**: A materialised occurrence MUST carry a snapshot of its effective values: `kind`, `amount_minor` (per-occurrence amount, or its round's share of a total by `Money::allocate()` evenly with the remainder to the earliest rounds), `currency`, `category_id`, `counter_wallet_id`, `description`, `memo`, `counterparty`, and `date` = `scheduled_date`. A gam'eya rule MUST also materialise one payout occurrence on its payout round's date: `is_payout: true`, kind income, category the payout category, amount the sum of all rounds' scheduled amounts, skipped rounds included and occurrence edits ignored (≤ 2^53 − 1, else the rule is refused at `/amount_minor` as out of range).
- **FR-013**: An occurrence's stored `status` MUST be `scheduled`, `confirmed` or `skipped`. "Due" (scheduled, dated on or before the rule's today) and "missed" (due while a later non-payout occurrence of the same rule is also due) are derived by readers and MUST NOT be stored. An occurrence has no postings and never counts in a balance (P-05).

**Writing an occurrence**

- **FR-014**: A writer MUST be able to save an occurrence's writable members — `status`, `date`, `amount_minor`, `category_id`, `description`, `memo`, `counterparty`, `transaction_id` — carrying its version (≥ 1), with spec 012's version, replay and tombstone discipline. An unknown occurrence id MUST be not-found (no create). An edited `category_id` must be of the occurrence's kind, live (archived allowed), and the writer's own or a platform one unless unchanged (`INVALID_ENUM_VALUE` at `/category_id`); a transfer occurrence's category stays null. Changing any value other than `status` / `transaction_id` away from the rule-derived value marks the occurrence `edited`; sending them back to the rule-derived values clears it. `kind`, `currency` and `counter_wallet_id` come from the rule and are not writable. `date` may be any calendar date 1900-01-01–2999-12-31, inside or outside the rule's window; `scheduled_date`, `ordinal` and the occurrence key never change.
- **FR-015**: Status transitions MUST be: `scheduled` → `confirmed` | `skipped`; `skipped` → `scheduled` | `confirmed`; `confirmed` → nothing (any change to a confirmed occurrence is `IMMUTABLE_FIELD` at the member). `transaction_id` MUST be a client-generated UUIDv7 when the status becomes `confirmed` (`REQUIRED` otherwise) and null in any other status (`INVALID_ENUM_VALUE`); an id already held by any record other than this occurrence's transaction is `INVALID_ENUM_VALUE` at `/transaction_id`.
- **FR-016**: Confirming MUST record, in the same unit of work and through B6's transaction service, one transaction with the given id and the occurrence's values as saved by that write (`cleared: false`, `created_by` = the rule's author, `occurrence_id` and `recurring_rule_id` set). B6's rules apply except category ownership, which was settled when the rule or occurrence was written (a category still must be live and of the right kind). A refusal of the transaction (destination no longer usable, currency mismatch, writer lacking T-02 in the destination) MUST refuse the whole write with `422` at `/status` (`INVALID_ENUM_VALUE` or `CURRENCY_MISMATCH`) or `403 FORBIDDEN`, with nothing applied.

**Automatic confirmation, the job, catch-up**

- **FR-017**: For an `active` rule with `auto_confirm: true`, the server MUST confirm each `scheduled` occurrence whose date is on or before the rule's today and on or after the rule's **auto-confirm start** (the day, in its timezone, the rule was created, resumed from pause, or had `auto_confirm` switched on), acting with the rule's author's authority (T-02, and T-02 in a transfer's destination) and minting the transaction's UUIDv7, oldest date first and at most 50 per rule per pass (the next pass continues). Earlier pending occurrences are left for a member.
- **FR-018**: When automatic confirmation of an occurrence is refused, the server MUST leave it `scheduled`, set `auto_confirm_refusal` (`not_permitted` | `counter_wallet_unavailable` | `currency_mismatch`) and not attempt it again while the refusal is set. Any manual save of the occurrence that changes it clears the refusal, which makes it eligible for automatic confirmation again on the next pass (an identical save changes nothing, the refusal included).
- **FR-019**: Materialisation and automatic confirmation MUST be idempotent and serialised per rule: rerunning the job, overlapping runs, a materialise request and members' writes never produce two occurrences for one occurrence key or two transactions for one occurrence (enforced by the occurrence key, by one-to-one links between occurrence and transaction, and by the rule's and occurrence's row locks inside the fixed lock order).
- **FR-020**: A scheduled job MUST run every hour and apply FR-011 and FR-017 to every live rule, each rule in its own unit of work so one rule's failure never blocks another.
- **FR-021**: A writer MUST be able to request materialise for one rule: the server applies FR-011 and FR-017 to it at once and returns the rule. A paused or ended rule returns unchanged; a tombstoned rule is `409 VERSION_CONFLICT` with the tombstone.

**Reading and sync**

- **FR-022**: Any active member MUST be able to list a wallet's live rules — every status, active, paused and ended — (filter `status` eq/in; sort `created_at` default, `start_date`; stabilised by id) and its live occurrences (filters `recurring_rule_id` eq/in ≤ 50, `status` eq/in, `date` eq/gt/gte/lt/lte; sort `date` ascending default, `created_at`; stabilised by id), and read either by id, live or tombstoned, with the shared cursor and limit. Anything else is refused (api-conventions "Refusals"). A rule and its occurrences are under the rule's wallet only (for a transfer, the source).
- **FR-023**: Rules and occurrences MUST ride the wallet feed as types `recurring_rule` and `occurrence`, each stamped with the wallet counter under the wallet lock on every write (by a person or by the server), rendered exactly as its read. They MUST be accepted in the sync batch as `recurring_rule` (`upsert`, `delete`) and `occurrence` (`upsert`), each item obeying its single write (ADR-0016).
- **FR-024**: The Transaction resource MUST gain read-only `recurring_rule_id` and `occurrence_id` (null for a transaction recorded by hand); a transaction write carrying them is `UNKNOWN_FIELD`. They never change, whatever later happens to the transaction.
- **FR-025**: The tombstone purge MUST remove rule and occurrence tombstones after 90 days, raising the wallet's purge horizon, except a rule while any of its occurrences exists and an occurrence while any transaction refers to it.

**Obligations inherited from B4, B5, B6 and B14**

- **FR-026**: Locks MUST be taken in one fixed order: category rows (share, ascending) for every category the stored and requested rule or occurrence references, then wallet rows ascending (the rule's wallet and a transfer's destination), then the rule row, then its occurrence rows (ADR-0014 rule 10, spec 012 FR-011, spec 013 lock order). The transaction service called inside re-takes the same locks.
- **FR-027**: Categories referenced by any rule or occurrence (`category_id`, `payout_category_id`), live or tombstoned, MUST be reported in use, answered set-wise. Inside the write, the server MUST publish a Category Reference for each person's category a rule or occurrence in the wallet uses, and withdraw it only when no transaction, rule or occurrence in that wallet, live or tombstoned, uses it any more — B6's withdrawal MUST also consult rules and occurrences.
- **FR-028**: Deleting a wallet MUST tombstone its rules and their occurrences in the same unit of work (spec 010 FR-010). A rule whose transfer destination is deleted stays live; its occurrences refuse confirmation (FR-016, FR-018).

**Access and safety**

- **FR-029**: Reading rules and occurrences requires row R-01; every write (rule create, edit, pause, end, delete; occurrence confirm, skip, edit; materialise) requires row R-02 in the rule's wallet and, for a transfer rule, T-02 in the destination (as T-03 requires for a transfer). Non-member → not-found; under-privileged member → `403 FORBIDDEN`. Every write is behind the verification gate (`403 EMAIL_NOT_VERIFIED`). No entitlement gates the feature (P-09 rule 5); no route-specific rate limit.
- **FR-030**: Amounts, descriptions, memos and counterparties MUST NOT be logged (P-15 rule 4). The job logs one line per run with counts only.
- **FR-031**: Confirmations announce `TransactionPosted` through the transaction service as any recorded transaction does; this feature adds no event of its own.

### Key Entities

- **Recurring Rule** (glossary): a repeating money movement set up once; wallet-scoped, syncable; holds the template, the schedule, the timezone and the presets.
- **Occurrence** (glossary, amended here): one dated instance materialised by the server; wallet-scoped, syncable; `scheduled` → `confirmed` (producing one Transaction) or `skipped`.
- **Transaction** (glossary): gains read-only links to the occurrence and rule that produced it.
- **Wallet**, **Category**, **Category Reference** (glossary): read and updated as above.

## Project Sections *(mandatory for feature specs)*

### 1. Purpose

This feature lets a person set up rent, salaries, subscriptions, loan installments and gam'eya rounds once and have them appear — as upcoming items on every member's phone, and as real transactions when they happen — without typing them again, without ever posting the same payment twice, and without anything entering a balance before it happens.

### 2. Glossary terms

- [Recurring Rule](../../docs/glossary.md#recurring-rule), [Occurrence](../../docs/glossary.md#occurrence), [Transaction](../../docs/glossary.md#transaction), [Wallet](../../docs/glossary.md#wallet-محفظة), [Category](../../docs/glossary.md#category), [Category Reference](../../docs/glossary.md#category-reference), [Change Row](../../docs/glossary.md#change-row), [Sync Batch](../../docs/glossary.md#sync-batch), [Purge Horizon](../../docs/glossary.md#purge-horizon): as defined.
- Added to the glossary in this change (T001), before code uses them:
  - **Occurrence key** — (`recurring_rule_id`, `scheduled_date`, `is_payout`); the identity that makes materialisation idempotent.
  - **Materialise** — the server creating the occurrences of a rule's schedule up to its horizon; never done by a client.
  - **Horizon** — how far ahead occurrences are materialised: through the rule's today + 35 days, and at least the next one.
  - **Auto-confirm** — the server confirming a due occurrence of a rule with `auto_confirm: true`, as the rule's author.
  - **Payout** (gam'eya) — the one income occurrence of a gam'eya rule, on the payout round's date.
  - **Anchor day** — the day of month a monthly or yearly rule falls on, clamped to the month's end.
  - The Occurrence entry's states change to `scheduled` → `confirmed` | `skipped` (`skipped` → `scheduled`), with "due" and "missed" defined as derived.

### 3. Data model

Owned by `app/Modules/Wallets` (context Wallets & Ledger; the constitution's row owns recurring rules).

| Entity | Key fields | Scope | Lifecycle |
|---|---|---|---|
| Recurring Rule | `id` (UUIDv7, client), `wallet_id`, `preset`, `status`, `kind`, `amount_minor` (positive bigint), `amount_basis`, `currency` (char 3), `category_id`, `counter_wallet_id`, `description`, `memo`, `counterparty`, `auto_confirm`, `frequency`, `interval`, `anchor_day`, `start_date`, `end_date`, `occurrence_count`, `payout_round`, `payout_category_id`, `timezone` (IANA), `created_by`, auto-confirm start (internal date), `version`, `change_seq`, `created_at`, `updated_at`, `deleted_at` | wallet (`wallet_id`) | `active` ⇄ `paused`; either → `ended`; any → `tombstoned` (final) |
| Occurrence | `id` (UUIDv7, server), `wallet_id`, `recurring_rule_id`, `ordinal`, `is_payout`, `scheduled_date`, `date`, `status`, `kind`, `amount_minor`, `currency`, `category_id`, `counter_wallet_id`, `description`, `memo`, `counterparty`, `edited`, `transaction_id`, `auto_confirm_refusal`, `version`, `change_seq`, `created_at`, `updated_at`, `deleted_at` | wallet (`wallet_id`, = the rule's) | `scheduled` → `confirmed` \| `skipped`; `skipped` → `scheduled` \| `confirmed`; any → `tombstoned` with its rule or when it leaves the schedule |
| Transaction (changed) | + `recurring_rule_id`, `occurrence_id` (nullable, read-only, unique `occurrence_id`) | wallet | unchanged |

No float, double or decimal column (P-03). Calendar dates are `date` columns, the timezone an IANA name (ADR-0011).

### 4. Invariants

| Invariant | Principle or decision | Test that fails when broken |
|---|---|---|
| One occurrence per occurrence key, whatever reruns or races | FR-009, FR-019; ADR-0018 | `tests/Feature/Wallets/Recurring/MaterialiseIdempotencyTest.php` |
| One transaction per occurrence, one occurrence per transaction | FR-016, FR-019; ADR-0018 | `tests/Feature/Wallets/Recurring/ConfirmOccurrenceTest.php`, `tests/Feature/Wallets/Recurring/AutoConfirmRaceTest.php` |
| A client cannot create an occurrence | FR-009, FR-014; ADR-0018 | `tests/Contract/Wallets/Recurring/SaveOccurrenceTest.php` |
| Occurrences have no postings; balances move only on confirm | P-05; FR-013 | `tests/Feature/Wallets/Recurring/OccurrenceBalanceTest.php` |
| Month-end clamping and no drift | FR-010 | `tests/Unit/Wallets/Recurring/RuleDatesTest.php` |
| Total split sums exactly to the total | P-03 rule 4; FR-012 | `tests/Unit/Shared/MoneyAllocateTest.php`, `tests/Unit/Wallets/Recurring/OccurrenceAmountsTest.php` |
| "Today" is the rule's timezone, not the viewer's or the server's | P-12; ADR-0011 rule 9 | `tests/Unit/Wallets/Recurring/RuleTodayTest.php`, `tests/Feature/Wallets/Recurring/AutoConfirmTest.php` |
| Never auto-confirm before the auto-confirm start | FR-017 | `tests/Feature/Wallets/Recurring/AutoConfirmTest.php` |
| Resolved occurrences never change through rule edits; schedule frozen once any is resolved | FR-006, FR-007 | `tests/Feature/Wallets/Recurring/SaveRuleTest.php` |
| Stale version refuses the whole write with the current state (incl. tombstone) | P-07 rules 2–4 | `tests/Feature/Wallets/Recurring/SaveRuleTest.php`, `tests/Feature/Wallets/Recurring/SaveOccurrenceTest.php` |
| Identical replay changes nothing | ADR-0008 rule 10 | same two files |
| Delete is a tombstone, cascading to occurrences, never to transactions | P-07 rule 5; FR-008 | `tests/Feature/Wallets/Recurring/DeleteRuleTest.php` |
| R-02 (and T-02 in a transfer's destination) on every write; non-member 404 | P-08; FR-029 | `tests/Feature/Wallets/Recurring/RecurringAuthorizationTest.php` |
| Every write behind the verification gate | FR-029 | `tests/Contract/Wallets/Recurring/RecurringVerificationGateTest.php` |
| Lock order categories → wallets → rule → occurrences holds under concurrency | FR-026; ADR-0014 rule 10 | `tests/Feature/Wallets/Recurring/RecurringLockOrderTest.php` |
| Categories used by rules/occurrences are in use, set-wise; references withdrawn only when no transaction, rule or occurrence uses them | FR-027 | `tests/Feature/Wallets/Recurring/RecurringCategoryUsageTest.php`, `tests/Feature/Wallets/Recurring/RecurringCategoryReferencesTest.php` |
| Rules and occurrences on the wallet feed in commit order; batch items equal single writes | FR-023; ADR-0008, ADR-0016 | `tests/Contract/Sync/RecurringFeedTest.php`, `tests/Contract/Sync/RecurringBatchParityTest.php` |
| Wallet deletion tombstones rules and occurrences | FR-028 | `tests/Feature/Wallets/Recurring/RecurringWalletDeletionTest.php` |
| Purge keeps a rule with occurrences and an occurrence with a transaction | FR-025 | `tests/Feature/Sync/RecurringPurgeTest.php` |

### 5. API

Under `/api/v1`, in `docs/openapi/paths/recurring.yaml`, tag `Wallets & Ledger`. **Frozen for the C7 client** (contract first); `info.version` v1.6 → v1.7 (additive). The batch and feed types are added to `docs/openapi/paths/sync.yaml`; the two transaction links to `docs/openapi/paths/transactions.yaml`.

| Method | Path | operationId | Success | Error codes (beyond the shared 400/401/406/415/426/500 refusals) |
|---|---|---|---|---|
| GET | `/wallets/{id}/recurring-rules` | `listRecurringRules` | `200` collection | `NOT_FOUND`, `INVALID_ID`, `INVALID_CURSOR`, `INVALID_QUERY_PARAMETER`, `FILTER_FIELD_NOT_SUPPORTED`, `FILTER_OPERATOR_NOT_SUPPORTED`, `SORT_FIELD_NOT_SUPPORTED` |
| GET | `/wallets/{id}/recurring-rules/{rid}` | `getRecurringRule` | `200` | `NOT_FOUND`, `INVALID_ID` |
| PUT | `/wallets/{id}/recurring-rules/{rid}` | `upsertRecurringRule` | `201` create, `200` update/replay | `EMAIL_NOT_VERIFIED`, `FORBIDDEN`, `NOT_FOUND`, `VERSION_CONFLICT`, `INVALID_ID`, `VALIDATION_FAILED` |
| DELETE | `/wallets/{id}/recurring-rules/{rid}` | `deleteRecurringRule` | `200` tombstone | same as PUT |
| POST | `/wallets/{id}/recurring-rules/{rid}/materialise` | `materialiseRecurringRule` | `200` the rule | `EMAIL_NOT_VERIFIED`, `FORBIDDEN`, `NOT_FOUND`, `VERSION_CONFLICT`, `INVALID_ID` |
| GET | `/wallets/{id}/occurrences` | `listOccurrences` | `200` collection | as the rule list |
| GET | `/wallets/{id}/occurrences/{oid}` | `getOccurrence` | `200` | `NOT_FOUND`, `INVALID_ID` |
| PUT | `/wallets/{id}/occurrences/{oid}` | `saveOccurrence` | `200` (never 201) | `EMAIL_NOT_VERIFIED`, `FORBIDDEN`, `NOT_FOUND`, `VERSION_CONFLICT`, `INVALID_ID`, `VALIDATION_FAILED` |

Any other method on these paths is `405 METHOD_NOT_ALLOWED` with `Allow` (an occurrence has no DELETE: skip it).

**Recurring Rule** (response; `SyncableResource` + `WalletScoped`; also the `recurring_rule` feed row): `preset`, `status`, `kind`, `amount_minor`, `amount_basis`, `currency`, `category_id`, `counter_wallet_id`, `description`, `memo`, `counterparty`, `auto_confirm`, `frequency`, `interval`, `anchor_day`, `start_date`, `end_date`, `occurrence_count`, `payout_round`, `payout_category_id`, `timezone`, `created_by`.

**Recurring Rule write** (`PUT`, every member required except `version`): the response members minus `timezone`, `created_by` and the syncable envelope, plus `version` (omitted or 0 to create). Anything else is `UNKNOWN_FIELD`. `DELETE` body: `{ "version": n }`.

**Occurrence** (response; `SyncableResource` + `WalletScoped`; also the `occurrence` feed row): `recurring_rule_id`, `ordinal`, `is_payout`, `scheduled_date`, `date`, `status` (`scheduled` | `confirmed` | `skipped`), `kind`, `amount_minor`, `currency`, `category_id`, `counter_wallet_id`, `description`, `memo`, `counterparty`, `edited`, `transaction_id`, `auto_confirm_refusal` (`not_permitted` | `counter_wallet_unavailable` | `currency_mismatch` | null).

**Occurrence write** (`PUT`, all required): `status`, `date`, `amount_minor`, `category_id`, `description`, `memo`, `counterparty`, `transaction_id`, `version` (≥ 1). Anything else is `UNKNOWN_FIELD`.

**Transaction** (changed, additive): read-only `recurring_rule_id`, `occurrence_id` (uuid or null).

**Answers C7 needs** (frozen):

| C7 asks | Answer |
|---|---|
| Are occurrences created ahead as `planned`? | Occurrences are materialised ahead as Occurrence records with `status: scheduled` (through today + 35 days in the rule's timezone, and at least the next one). They are not transactions and never count in a balance. No `planned` transaction exists; a confirmed occurrence produces an ordinary transaction carrying `occurrence_id` and `recurring_rule_id`. |
| The month-end rule | `anchor_day` clamped to the month's last day; every date computed from the origin (FR-010). |
| The occurrence key | (`recurring_rule_id`, `scheduled_date`, `is_payout`); materialised only by the server; the phone never creates an occurrence. |
| Series edit from a date forward | Edit in place changes pending, unedited occurrences only; schedule frozen once any occurrence is resolved; "from date D" = set `end_date` to D − 1 on the old rule and create a new rule starting D (refused if a resolved occurrence lies after D − 1). |
| Who materialises | The server (rule write, hourly job, `materialise`). The phone asks, then pulls the wallet feed. |

**Field-level codes in `errors[]`**: `IMMUTABLE_FIELD` (preset, kind, currency, counter wallet; schedule members once resolved; any member of an ended rule or a confirmed occurrence), `CURRENCY_MISMATCH` (`/currency`, `/counter_wallet_id`, `/status` on confirm), `INVALID_ENUM_VALUE` (category, payout category, counter wallet as in B6; preset shape; `ended` on create; `anchor_day` on daily/weekly; both `end_date` and `occurrence_count`; `transaction_id` outside a confirm or already taken; `/status` when confirmation is refused or a transition is not allowed), `OUT_OF_RANGE` (amounts, interval, anchor day, counts, payout round, dates, texts; an end cutting off a resolved occurrence), `REQUIRED`, `UNKNOWN_FIELD`, `INVALID_TYPE`, `AMOUNT_NOT_INTEGER`, `DATE_NOT_CALENDAR_DATE`, `INVALID_CURRENCY`, `INVALID_ID`. **No new code.**

### 6. Authorization and entitlements

Every rule references spec 010 §6 by row ID and never restates the matrix (P-08 rule 5).

- Reading rules and occurrences (lists, single reads, the feed rows): row **R-01** (and W-01 for the feed).
- Every write — rule create, edit, pause, resume, end, delete; occurrence confirm, skip, edit; materialise: row **R-02** in the rule's wallet.
- A recurring transfer rule: R-02 in the source and **T-02** in the destination, on every write and on every confirmation (the T-03 rule applied to rules). Spec 010's R-02 wording is amended in this change to name delete, edit-one and materialise, and the destination requirement; roles are unchanged.
- Automatic confirmation acts as the rule's author and needs T-02 (and T-02 in a transfer's destination) for the author at that moment; otherwise it is refused and surfaced (FR-018).
- Non-member of the path wallet → **not-found**; a rule or occurrence of another wallet, or the destination's path for a transfer rule → **not-found**. Under-privileged member → **403 FORBIDDEN**.
- Verification gate on every write; reads are not gated. Entitlements: none (P-09 rule 5).

**Precedence** for every rule or occurrence write: `401` → `403 EMAIL_NOT_VERIFIED` → `422 INVALID_ID` → `422 VALIDATION_FAILED` (shape) → `404 NOT_FOUND` (non-member, deleted path wallet, id held by another wallet's record or another entity, unknown occurrence, unknown rule with version > 0 or on DELETE/materialise) → `403 FORBIDDEN` (R-02) → `409 VERSION_CONFLICT` for any non-delete write to a tombstone → `200` identical replay (and DELETE of a tombstone) → `409 VERSION_CONFLICT` (stale) → `403 FORBIDDEN` (T-02 in a transfer's destination) → `422 VALIDATION_FAILED` (state rules) → write.

### 7. Events

| Event | Payload | Listeners |
|---|---|---|
| `TransactionPosted` (existing, from the transaction service) | as spec 012, `change: recorded` | none yet — B8, B13 |

No new event. B16 (push) will decide whether "an occurrence is due" needs one; due-ness is derived, so it would be computed, not announced by a write.

### 8. Acceptance scenarios

| Story · scenario | Layer | Test file |
|---|---|---|
| US1 · 1, 4, 5 create, replay, end | Feature, Contract | `tests/Feature/Wallets/Recurring/CreateRuleTest.php`, `tests/Contract/Wallets/Recurring/UpsertRecurringRuleTest.php` |
| US1 · 2 no balance effect | Feature | `tests/Feature/Wallets/Recurring/OccurrenceBalanceTest.php` |
| US1 · 3 auto-confirm, job reruns | Feature | `tests/Feature/Wallets/Recurring/AutoConfirmTest.php`, `tests/Feature/Wallets/Recurring/MaterialiseIdempotencyTest.php` |
| US1 · 6 month-end | Unit | `tests/Unit/Wallets/Recurring/RuleDatesTest.php` |
| US1 · 7 access | Feature, Contract | `tests/Feature/Wallets/Recurring/RecurringAuthorizationTest.php`, `tests/Contract/Wallets/Recurring/RecurringVerificationGateTest.php` |
| US2 · 1–7 occurrence writes | Feature, Contract | `tests/Feature/Wallets/Recurring/SaveOccurrenceTest.php`, `tests/Feature/Wallets/Recurring/ConfirmOccurrenceTest.php`, `tests/Contract/Wallets/Recurring/SaveOccurrenceTest.php` |
| US2 · 4 races | Feature | `tests/Feature/Wallets/Recurring/AutoConfirmRaceTest.php` |
| US2 · 8 refusals surfaced | Feature | `tests/Feature/Wallets/Recurring/AutoConfirmRefusalTest.php` |
| US3 · 1–6 series edits | Unit, Feature | `tests/Unit/Wallets/Recurring/RuleRulesTest.php`, `tests/Feature/Wallets/Recurring/SaveRuleTest.php` |
| US3 · 7 delete | Feature, Contract | `tests/Feature/Wallets/Recurring/DeleteRuleTest.php`, `tests/Contract/Wallets/Recurring/DeleteRecurringRuleTest.php` |
| US4 · 1–4 presets, split | Unit, Feature | `tests/Unit/Wallets/Recurring/PresetRulesTest.php`, `tests/Unit/Wallets/Recurring/OccurrenceAmountsTest.php`, `tests/Feature/Wallets/Recurring/PresetRuleTest.php` |
| US5 · 1 materialise | Feature, Contract | `tests/Feature/Wallets/Recurring/MaterialiseRuleTest.php`, `tests/Contract/Wallets/Recurring/MaterialiseRecurringRuleTest.php` |
| US5 · 2–3 feed, batch | Contract | `tests/Contract/Sync/RecurringFeedTest.php`, `tests/Contract/Sync/RecurringBatchParityTest.php` |
| US5 · 4 timezone | Unit, Feature | `tests/Unit/Wallets/Recurring/RuleTodayTest.php`, `tests/Feature/Wallets/Recurring/AutoConfirmTest.php` |
| US5 · 5 purge | Feature | `tests/Feature/Sync/RecurringPurgeTest.php` |
| Reads, lists | Contract | `tests/Contract/Wallets/Recurring/ListRecurringRulesTest.php`, `tests/Contract/Wallets/Recurring/ListOccurrencesTest.php`, `tests/Contract/Wallets/Recurring/GetRecurringTest.php` |
| Inherited obligations | Feature | `tests/Feature/Wallets/Recurring/RecurringCategoryUsageTest.php`, `tests/Feature/Wallets/Recurring/RecurringCategoryReferencesTest.php`, `tests/Feature/Wallets/Recurring/RecurringWalletDeletionTest.php`, `tests/Feature/Wallets/Recurring/RecurringLockOrderTest.php` |

### 9. Open questions

1. **Reminders and push for due occurrences** — B16. Due-ness is derived (FR-013), so B16 computes it; does not block.
2. **Forecast reports over scheduled occurrences** — B13 reads occurrences through a query service; does not block.
3. **Budget attribution of occurrence transactions in shared wallets** — B8 (by category owner or `created_by`, the rule's author here); does not block.
4. **Restoring a deleted rule** — not offered; a later additive `PATCH` restore could revive it and its occurrences. Does not block.
5. **A departing author's rules (B15)** — B15 decides whether they transfer, end or stay; does not block.
6. **Recurring rules visible on a transfer destination's feed** — not in MVP (the produced transactions are); does not block.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A person sets up a monthly bill in one request and sees its next occurrence on the very next read, in 100% of cases.
- **SC-002**: 0 duplicate occurrences and 0 duplicate transactions for one occurrence after any number of job reruns, overlapping catch-up requests, retried writes or concurrent confirmations.
- **SC-003**: 0 balance movement from occurrences that are not confirmed.
- **SC-004**: An automatic occurrence is posted within 1 hour 15 minutes after the start of its date in the rule's timezone, while the job runs.
- **SC-005**: Every member of a shared wallet sees identical dates for the same rule, whatever their own timezone.
- **SC-006**: Installment and gam'eya amounts add up to the stated total to the minor unit, in 100% of cases.
- **SC-007**: A rule write that materialises a daily schedule's horizon completes in under 300 ms at the 95th percentile, and one job run handles 10,000 active rules in under 10 minutes, measured on the development stack with debugging extensions off.
- **SC-008**: 0 responses reveal a rule, occurrence or transaction to someone outside the wallet.

## Assumptions

- Spec 010's matrix (rows R-01, R-02 already present) and authorizer, spec 011's categories and Category References, spec 012's `TransactionService`, and spec 013's feed, batch and purge are reused; each is extended, not wrapped.
- The author's profile timezone (spec 008) is read through Identity's application layer at rule creation.
- The scheduler already runs (`routes/console.php`); this feature adds one hourly entry.
- No reference data: nothing to seed; preset names are the client's to translate.
- Sizing: 200 rules per wallet, 10,000 active rules overall for the job target.
