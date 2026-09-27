# Feature Specification: Profile & Preferences

**Feature Branch**: `008-profile-preferences`

**Created**: 2026-09-27

**Status**: Draft

**Input**: Brief B2 ([specs/briefs/B02-profile-preferences.md](../briefs/B02-profile-preferences.md)): "A user reads and updates display name, locale (`ar`/`en`), default currency (ISO code, default EGP), budget period start day (1–28, 'salary day'), timezone (IANA), and notification preferences placeholder." Plus the brief's considerations and its reconcile table.

## Clarifications

### Session 2026-09-27

- Q (owner, at planning): Should the default currency stay changeable once the person has recorded money? → A: **No. Once the person has recorded at least one live transaction, the default currency is locked.** A save that changes it is refused with `422 VALIDATION_FAILED`, field code `IMMUTABLE_FIELD` on `/default_currency`. A save that repeats the current currency passes. The profile reports `default_currency_locked` so the app can disable the picker. Deleting every transaction the person recorded unlocks it again, because nothing then depends on the default.

- Q: How is the profile written, given it is a syncable record the server creates at registration? → A: **`GET /me` reads the account and profile; `PUT /me/profile` saves the full profile with its `version`**, under the syncable write rules: a stale version is `409 VERSION_CONFLICT` with `current`, and an identical replay is `200` with nothing changed. The one deviation from the usual shape is the path: `/me/profile` instead of `/profiles/{id}`, because the profile is never created by the client and each person has exactly one.

> **Nature of this feature.** The Profile already exists. B1 creates it at registration with a language, a timezone and an optional display name. This feature lets the person read and change it, and adds the two money preferences the later features need: a default currency for new wallets, and the day their budget month starts. Profile is a user-scoped syncable record (glossary), so it follows the same write rules as every other syncable record unless this spec says otherwise.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See my account and preferences (Priority: P1)

The app opens its settings screen and shows the person their email address, whether it is verified, their display name, language, timezone, default currency and budget start day.

**Why this priority**: every other story edits what this one shows, and the app needs these values at start-up to format dates, choose its language and pre-fill a new wallet's currency.

**Independent Test**: register, sign in, read the profile, and check that it shows the values registration set, with EGP and day 1 as defaults.

**Acceptance Scenarios**:

1. **Given** a signed-in person, **When** they read their account, **Then** they see their email address, verification state, account state, and their profile: display name, language, timezone, default currency, budget start day, and the profile's sync members (`version` among them).
2. **Given** a person who registered without a display name or timezone, **When** they read their profile, **Then** the display name is empty, the timezone is `Africa/Cairo`, the default currency is EGP and the budget start day is 1.
3. **Given** a person whose address is not verified, **When** they read their profile, **Then** they can: reading and managing one's own profile is not behind the verification gate.

---

### User Story 2 - Change my preferences (Priority: P1)

The person changes their display name, language, timezone, default currency or budget start day and saves. The next read, on this phone or another, shows the new values. If they edited on two phones while one was offline, the second save is refused and the app shows them the latest values to merge.

**Why this priority**: it is the feature. Language and timezone decide how every screen and mail reads; currency and start day shape the wallets and budgets the person is about to create.

**Independent Test**: update each preference, read it back, then submit a save carrying an older version and see it refused with the current state.

**Acceptance Scenarios**:

1. **Given** a signed-in person with their profile's current version, **When** they save new values, **Then** the values are stored, the version goes up by one, and the response carries the updated profile.
2. **Given** a save that carries an older version than the stored one, **When** it arrives, **Then** it is refused with `409 VERSION_CONFLICT`, nothing is applied, and the response carries the profile's current state.
3. **Given** the same save sent twice (a retry after a lost response), **When** the second arrives, **Then** it answers `200` with the profile unchanged: `version` and `change_seq` do not move (spec 003, identical replay).
4. **Given** an invalid value (a language other than `ar`/`en`, an unknown timezone, a currency that is not an ISO 4217 code, a start day outside 1–28, a display name over 100 characters), **When** it is saved, **Then** it is refused with `422 VALIDATION_FAILED` naming each offending field, and nothing is applied.
5. **Given** a person changes their language, **When** they next receive a mail or an error message from the API without naming a language, **Then** it is in the new language.

---

### User Story 3 - Preferences shape what comes next, never what came before (Priority: P2)

The person changes their timezone, default currency or budget start day. Nothing they already recorded moves: no wallet changes currency, no stored date shifts, and budget months already under way are not re-cut. The new values apply to what they create or view from now on.

**Why this priority**: it is the promise that keeps the books stable. It matters most once wallets (B4), budgets (B8) and reports (B13) exist; this feature fixes the rule they must follow.

**Independent Test**: change each of the three preferences and check that no stored record other than the profile changed.

**Acceptance Scenarios**:

1. **Given** a person changes their timezone, **When** the change is saved, **Then** no stored instant or calendar date anywhere is rewritten (P-12 rule 5). Only how instants are presented to them changes.
2. **Given** a person who has recorded no transaction changes their default currency, **When** the change is saved, **Then** no existing wallet changes currency. The new value only pre-fills the currency of wallets created afterwards.
4. **Given** a person who has recorded at least one live transaction, **When** they save a different default currency, **Then** the save is refused with `422 VALIDATION_FAILED`, field code `IMMUTABLE_FIELD` on `/default_currency`, and nothing in the save is applied. Their profile reports `default_currency_locked: true`.
3. **Given** a person changes their budget start day, **When** the change is saved, **Then** budget periods that have already begun keep the dates they began with. The new day applies from the next period onwards (FR-013).

---

### Edge Cases

- **Clearing the display name.** Saving an empty display name (or `null`) clears it. The profile then shows no name, and other people will see the person's fallback label when sharing arrives (B9).
- **Whitespace.** A display name is trimmed; one that is only whitespace counts as empty.
- **Arabic and mixed-direction names.** Any Unicode letters are accepted; the name is stored as given (after trimming), never transliterated, and returned as-is.
- **Locked currency, same value.** A save carrying the current default currency while it is locked is accepted; only a change is refused.
- **Locked currency, stale version.** A stale `version` is refused with `409 VERSION_CONFLICT` first; the lock is only checked against the current profile.
- **Currency case.** `egp` is refused with the field code `INVALID_CURRENCY`, the same rule as money everywhere (spec 003). It is never upper-cased silently.
- **Start day 29–31.** Refused. Every month has a day 28, which is why the range stops there.
- **Timezone aliases.** Legacy IANA aliases such as `Egypt` are accepted if the IANA list knows them, and stored as sent.
- **Language change and existing mail.** A mail already queued keeps the language it was queued in.
- **Account pending deletion.** The profile can still be read and changed during the grace period, like any other use of an existing session (baseline, Deletion rule 2).
- **Another person's profile.** There is no way to address one; every operation is on the caller's own profile.

## Requirements *(mandatory)*

### Functional Requirements

**Reading**

- **FR-001**: A signed-in person MUST be able to read their own account and profile in one response: email address, whether it is verified, account state and scheduled deletion date (as B1's account summary), and the profile with its sync members.
- **FR-002**: Reading the profile MUST NOT be behind the verification gate (security baseline, Verification gate: managing one's own profile is allowed).

**Writing**

- **FR-003**: A signed-in person MUST be able to change their display name, language, timezone, default currency and budget start day by saving the **whole** profile with `PUT /me/profile` (clarified 2026-09-27). Every writable member MUST be present; an omitted member is `REQUIRED`, and server-owned members (`id`, `user_id`, `change_seq`, `created_at`, `updated_at`, `deleted_at`) are `UNKNOWN_FIELD`. A save identical to the stored profile MUST answer `200` without moving `version` or `change_seq`.
- **FR-004**: Every save MUST carry the client's last-seen profile `version`. A stale version MUST refuse the whole save with `409 VERSION_CONFLICT`, carrying the profile's current state (P-07 rules 2–3). The server MUST NOT merge field by field (P-07 rule 4).
- **FR-005**: A successful save MUST bump `version`, assign a new `change_seq` so the change reaches the person's other devices through the user feed (B14), and update `updated_at`.
- **FR-006**: The profile is never created or deleted through this feature: it is created with the account (B1) and removed with it (B15). A save MUST NOT accept `deleted_at`.

**Values**

- **FR-007**: **Display name**: optional; 1 to 100 characters after trimming, or empty to clear. Stored encrypted with the person's data key (security baseline, What is protected at rest). It is never used to search or look anyone up.
- **FR-008**: **Language**: `ar` or `en`. It is the default language for the person's mails and for API messages when a request does not name a language, and it selects the language of the default category names (B5).
- **FR-009**: **Timezone**: an identifier in the IANA timezone list. It is applied only when presenting instants and building reports, never when storing (P-12 rules 3 and 5).
- **FR-010**: **Default currency**: an uppercase ISO 4217 alphabetic code; EGP for every existing and new profile until changed. It is only the default offered when creating a wallet (B4). Each wallet keeps its own currency. **It is locked once the person has recorded at least one live (not deleted) transaction in any wallet**: a save that changes it MUST be refused with `422 VALIDATION_FAILED`, field code `IMMUTABLE_FIELD` on `/default_currency`, applying nothing. The profile MUST report `default_currency_locked` (derived on every read, never stored). Until transactions exist (B6), nobody is locked.
- **FR-011**: **Budget start day**: an integer from 1 to 28, default 1, the day of the month on which the person's budget periods begin ("salary day").
- **FR-012**: Changing timezone, default currency or budget start day MUST NOT change any stored record other than the profile.
- **FR-013**: A budget period that has already begun when the start day changes MUST keep its dates. The profile MUST record, with the new start day, the calendar date from which it applies — the start of the first period that begins after the change — so B8 and B13 can compute periods on both sides of the change. The period arithmetic itself belongs to B8.

**Out of scope**

- **FR-014**: Notification preferences are not part of this feature. The glossary gives them to the Notifications context, and B16 specifies them; the brief's "placeholder" is not built here.
- **FR-015**: Changing the email address is not part of this feature (deferred by spec 007, FR-037).

### Key Entities

- **Profile** (existing glossary entry, Identity): gains **default currency** and **budget start day** with the date it applies from. Keeps display name (encrypted), language and timezone.
- **User** (existing): unchanged; its account summary is read alongside the profile.

## Project Sections *(mandatory for feature specs)*

### 1. Purpose

This feature lets a person see and set the preferences that decide how the app speaks to them and how it counts their money: what they are called, which language and timezone they live in, which currency a new wallet starts in, and on which day their budget month begins. It also fixes the rule every later money feature must honour: changing a preference never rewrites the past.

### 2. Glossary terms

- [Profile](../../docs/glossary.md#profile): the entry's Notes gain the two new preferences in this change. The field for the start day is named `budget_period_start_day`: the glossary forbids the bare word "period", and this is the Budget Period's start.
- [Budget Period](../../docs/glossary.md#budget-period): whose start this feature lets the person choose.
- [Wallet](../../docs/glossary.md#wallet-محفظة): whose currency is per wallet; the profile only suggests one.
- [User](../../docs/glossary.md#user), **Device Session**: as in spec 007.

### 3. Data model

Owned by `app/Modules/Identity`. Profile gains:

| Field | Type | Rule |
|---|---|---|
| `default_currency` | ISO 4217 alpha-3 | default `EGP` |
| `budget_period_start_day` | integer 1–28 | default `1` |
| `budget_period_start_day_from` | calendar date, nullable | the date the current start day applies from; null until it is first changed |
| `default_currency_locked` | boolean, **derived** | true when the person has recorded a live transaction; computed on read through the Wallets & Ledger query service, never stored |

Existing fields (`locale`, `timezone`, `display_name_ciphertext`, `version`, `change_seq`, timestamps, `deleted_at`) are unchanged. Scope key `user_id`; `single-state`.

### 4. Invariants

| Invariant | Principle or decision | Test that fails when broken |
|---|---|---|
| A stale version refuses the whole save and returns the current profile | P-07 rules 2–3 | `tests/Feature/Identity/ProfileUpdateTest.php` |
| A save bumps `version` and assigns a new `change_seq` | P-07 rule 1, FR-005 | `tests/Feature/Identity/ProfileUpdateTest.php` |
| Changing timezone, currency or start day changes no other stored record | P-12 rule 5, FR-012 | `tests/Feature/Identity/ProfilePreferencesDoNotRewriteTest.php` |
| The display name is never stored readable | Baseline, protected at rest; FR-007 | `tests/Feature/Identity/AddressAtRestTest.php` (extended) |
| Currency must be uppercase ISO 4217; start day 1–28; timezone in the IANA list; language `ar`/`en` | FR-008–FR-011 | `tests/Contract/Identity/ProfileTest.php` |
| Reading and saving the profile are not behind the verification gate | Baseline, Verification gate; FR-002 | `tests/Feature/Identity/VerificationGateTest.php` (extended) |
| The recorded start-day date is the start of the first period after the change | FR-013 | `tests/Unit/Identity/BudgetStartDayTest.php` |
| A person with a live transaction cannot change the default currency; repeating it passes | FR-010, clarified 2026-09-27 | `tests/Feature/Identity/DefaultCurrencyLockTest.php` |

### 5. API

Under `/api/v1`, in `docs/openapi/paths/identity.yaml`, tag `Identity`.

| Method | Path | Success | Error codes from the catalogue |
|---|---|---|---|
| GET | `/me` | `200` (account + profile) | `UNAUTHENTICATED` |
| PUT | `/me/profile` | `200` (the profile; also for an identical replay) | `UNAUTHENTICATED`, `VALIDATION_FAILED` (`INVALID_ENUM_VALUE`, `INVALID_CURRENCY`, `OUT_OF_RANGE`, `UNKNOWN_FIELD`, `REQUIRED`, `IMMUTABLE_FIELD`), `VERSION_CONFLICT` |

No new error code is expected.

### 6. Authorization and entitlements

Owner only: every operation is on the caller's own profile and no identifier of another person's profile is accepted. Not behind the verification gate. No entitlement gates; no wallet roles apply.

### 7. Events

| Event | Payload | Listeners |
|---|---|---|
| `ProfileUpdated` | user id, the names of the fields that changed (never their values) | none yet; Notifications (B16) may read the new language for push text |

Dispatched after commit (P-11). The sync feed (B14) picks the change up from `change_seq`, not from this event.

### 8. Acceptance scenarios

| Story · scenario | Layer | Test file |
|---|---|---|
| US1 · 1–3 read account and profile | Contract, Feature | `tests/Contract/Identity/ProfileTest.php`, `tests/Feature/Identity/VerificationGateTest.php` |
| US2 · 1–3 save, version conflict, retry | Contract, Feature | `tests/Contract/Identity/ProfileTest.php`, `tests/Feature/Identity/ProfileUpdateTest.php` |
| US2 · 4 validation | Contract | `tests/Contract/Identity/ProfileTest.php` |
| US2 · 5 language drives mail and messages | Feature | `tests/Feature/Identity/ProfileLanguageTest.php` |
| US3 · 4 currency locked by a recorded transaction | Feature, Contract | `tests/Feature/Identity/DefaultCurrencyLockTest.php`, `tests/Contract/Identity/ProfileTest.php` |
| US3 · 1–3 nothing rewritten | Feature, Unit | `tests/Feature/Identity/ProfilePreferencesDoNotRewriteTest.php`, `tests/Unit/Identity/BudgetStartDayTest.php` |

### 9. Open questions

1. ~~**Q1 — The write contract.**~~ Decided 2026-09-27: `GET /me` and `PUT /me/profile` with `version` (see Clarifications).
2. **Currency list.** Which ISO 4217 codes are offered is the shared kernel's `Currency` (F-4). Until it exists, this feature accepts any code in the ISO 4217 active list. Does not block.
3. **Period arithmetic across a start-day change** belongs to B8. This feature only records the new day and the date it applies from. Does not block.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A person can find and change any of their five preferences in one save, and see the new value on another signed-in device within one sync cycle.
- **SC-002**: In 100% of concurrent-edit trials, the save that arrives second with an older version is refused and nothing it carried is applied.
- **SC-003**: After changing timezone, default currency and start day, 0 stored records other than the profile have changed.
- **SC-004**: 0 display names are found readable in stored data, logs or error reports.
- **SC-005**: A language change is reflected in the next mail and the next API message without a language named, in 100% of trials.

## Assumptions

- The profile's id is server-generated at registration (spec 007); the client learns it by reading the profile.
- Defaults for existing profiles (EGP, day 1) are applied to every profile created before this feature, so no profile is ever without them.
- The display name shown to other members of a shared wallet, and its fallback when empty, belong to B9 (Sharing).
- The default category names chosen by language are seeded by B5; this feature only stores the language.
- The ISO 4217 active list and the IANA timezone list are taken from the runtime's own data, updated with the platform.
