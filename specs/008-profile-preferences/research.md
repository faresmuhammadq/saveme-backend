# Research: Profile & Preferences

**Feature**: [spec.md](./spec.md) · **Plan**: [plan.md](./plan.md) · **Date**: 2026-09-27

---

## R-1 The write contract: `PUT /me/profile`, full document, with `version`

**Decision** (owner, Q1 = A): `GET /me` returns `{ account, profile }`. `PUT /me/profile` takes every writable member plus `version`. The checks run in this order, and the first refusal wins:

1. **Shape**: `422 VALIDATION_FAILED`, with each field's code. `UNKNOWN_FIELD` for server-owned members, `REQUIRED` for an omitted writable one.
2. **Identical replay**: if every writable value equals the stored profile, answer `200` with the profile as it is. `version` and `change_seq` don't move. This happens before the version check, as spec 003's outcome table has it: a replay of a write already applied is indistinguishable from reading.
3. **Version**: `version` ≠ the stored version gives `409 VERSION_CONFLICT`, with `current`.
4. **Currency lock** (R-3): changing `default_currency` while locked gives `422 VALIDATION_FAILED`, with `IMMUTABLE_FIELD` at `/default_currency`.
5. **Apply** in one transaction. `version` goes up by 1, `change_seq` is drawn from `sync_change_seq`, `updated_at` is set, and `ProfileUpdated` is dispatched after commit.

The display name is compared after trimming and decrypting. Empty or `null` both mean cleared.

**Rationale**: it follows spec 003's syncable outcome table exactly. The one deviation is the singleton path, recorded in the spec's Clarifications.

## R-2 Currency codes: the first piece of the shared kernel's `Currency`

**Decision**: `app/Modules/Shared/Domain/Money/Currency.php`, a readonly value object. It holds a static table of the **active ISO 4217 alphabetic codes** with their minor-unit exponents: 3 for KWD, 0 for JPY, 2 for most. It has `Currency::isKnown(string)` and `Currency::of(string)`, and refuses lower case. It is the start of follow-up F-4 from spec 001. `Money` itself arrives with the first feature that stores an amount (B4 or B6).

The refusal for an invalid code is the field-level code `INVALID_CURRENCY`, which already exists and is "not an uppercase ISO 4217 alpha-3 code".

**Alternatives**: `intl`'s currency bundle, rejected because it includes withdrawn and historic codes and has no reliable way to tell active ones apart. `symfony/intl`, rejected because it is a new dependency.

## R-3 The currency lock (owner input at planning)

**Decision**: the default currency is locked while the person has **at least one live transaction** they recorded (`created_by` = them, `deleted_at` null), in any wallet. The lock is derived on every read and every save, and never stored, so deleting those transactions unlocks it again.

- **The source**: `Wallets\Application\TransactionQueryService::hasLiveTransactionsRecordedBy(string $userId): bool`. The Wallets module does not exist yet, so this feature creates the interface plus `Wallets\Application\Provisional\NoTransactionsYet`, which always answers `false`, bound in `Wallets\Application\WalletsServiceProvider`. B6 replaces the binding with a query over transactions and deletes the provisional class. This is the same pattern as the Billing seam in spec 007, R-11.
- **The refusal**: `422 VALIDATION_FAILED` with `{pointer: "/default_currency", code: "IMMUTABLE_FIELD"}`. That code already means "the member cannot be changed", so no new catalogue code is needed. The `detail` is localised to "The default currency can't change once you have recorded transactions".
- **The read**: `default_currency_locked` on the profile resource, so the app can disable the picker instead of letting the person hit the refusal.

**Why "recorded by the person"** and not "in a wallet they own": the default currency is the person's own suggestion for new wallets. What makes changing it confusing is money they have entered themselves, wherever they entered it.

**Why live only**: a person who entered one test transaction in the wrong currency can delete it and fix the default, rather than being stuck forever. Tombstones still sync (P-07), but they no longer depend on the default.

**Alternatives**: lock once any wallet exists (B4), rejected because a wallet with no entries has recorded nothing; lock forever after the first transaction, rejected for the reason above. A new code `DEFAULT_CURRENCY_LOCKED` (409) was also rejected: it would be a second name for a condition `IMMUTABLE_FIELD` already names.

## R-4 The budget start day and the date it applies from

**Decision**: when `budget_period_start_day` changes, `budget_period_start_day_from` is set to the **first calendar date after today, in the profile's timezone after the save, whose day of the month equals the new start day**. The start day is at most 28, so that date always exists within about a month. When the start day is unchanged, `…_from` is left as it is. The calculation lives in `Identity\Domain\Preferences\BudgetStartDay::appliesFrom(int $day, DateTimeImmutable $today)`, which is pure and unit-tested.

For example, today is 2026-09-27 and the new day is 25: the new day applies from 2026-10-25, so the period that began under the old day ends on 2026-10-24. If the new day is 28, it applies from 2026-09-28. B8 owns how budgets use this, and may refine the rule without a schema change, since the stored date is its input.

## R-5 A missing `Accept-Language` falls back to the profile's language

**Decision**: FR-008 says the profile's language applies when a request names none. The `api` group resolves the language before authentication has run, so Identity listens to Sanctum's `TokenAuthenticated` event (`Identity\Http\Listeners\ApplyProfileLanguage`). When the request carries no `Accept-Language`, it sets the application locale to the profile's `locale`. `ResolveContentLanguage` already reads the locale when the response is written, so `Content-Language` stays truthful.

`docs/api-conventions.md` (Localisation) changes to: "absent: the signed-in person's profile language, otherwise `en`". That is additive, so the API stays at v1.2.

## R-6 Storage

**Decision**: a new migration adds `default_currency char(3) NOT NULL DEFAULT 'EGP'`, `budget_period_start_day smallint NOT NULL DEFAULT 1` with CHECK 1–28, and `budget_period_start_day_from date NULL`. Existing rows take the defaults, as the spec's assumptions require. `ProfileRepository` gains `find(userId): ProfileRecord` and `save(ProfileRecord, int $expectedVersion): ProfileRecord`, a conditional update `WHERE version = ?` that refuses any concurrent writer (P-07).

## R-7 `ProfileUpdated`

**Decision**: `Identity\Domain\Events\ProfileUpdated(eventId, occurredAt, userId, list<string> changedFields)`, dispatched after commit. It carries field names only, never values, because a display name must not appear in an event payload that could reach logs (P-15 rule 4). It has no listener in B2.

## R-8 What does not change

- Registration keeps creating the profile (spec 007). The new columns take their defaults.
- The verification gate is not applied to `/me` or `/me/profile` (FR-002).
- Nothing is audited: a preference change is not in the baseline's audit vocabulary, and adding it would need a baseline change nobody asked for.
