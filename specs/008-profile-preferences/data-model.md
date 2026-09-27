# Data Model: Profile & Preferences

**Feature**: [spec.md](./spec.md) · **Research**: [research.md](./research.md)

## `profiles`: changes (Identity)

New migration `app/Modules/Identity/Infrastructure/Database/Migrations/2026_09_27_000001_add_preferences_to_profiles.php`:

| Column | Type | Rules |
|---|---|---|
| `default_currency` | char(3) NOT NULL DEFAULT `'EGP'` | an active ISO 4217 code, uppercase (validated by `Currency`, R-2) |
| `budget_period_start_day` | smallint NOT NULL DEFAULT `1` | CHECK `BETWEEN 1 AND 28` |
| `budget_period_start_day_from` | date NULL | the calendar date the current start day applies from (R-4); null until the day first changes |

Unchanged: `id`, `user_id` (scope), `locale` (CHECK `ar`/`en`), `timezone`, `display_name_ciphertext`, `version`, `change_seq`, `created_at`, `updated_at`, `deleted_at` (always null here).

## Derived, never stored

| Member | Source |
|---|---|
| `default_currency_locked` | `Wallets\Application\TransactionQueryService::hasLiveTransactionsRecordedBy(userId)`. Always `false` until B6 (R-3) |

## Domain types (Identity)

- `Identity\Domain\Preferences\ProfileRecord` (`final readonly`): `id`, `userId`, `displayName` (plaintext, opened in Application only), `locale`, `timezone`, `defaultCurrency`, `budgetPeriodStartDay`, `budgetPeriodStartDayFrom` (a calendar date string or null), plus the sync members `version`, `changeSeq`, `createdAt`, `updatedAt`.
- `Identity\Domain\Preferences\BudgetStartDay`: `appliesFrom(int $day, DateTimeImmutable $todayInProfileTimezone): string` (`YYYY-MM-DD`).
- `Identity\Domain\Events\ProfileUpdated`: `eventId`, `occurredAt`, `userId`, `changedFields` (a list of member names).

## Shared kernel

- `Shared\Domain\Money\Currency` (`final readonly`): `code`, `minorUnits`, `isKnown()`, `of()`. It holds the static table of active ISO 4217 codes.

## Wallets (provisional seam)

- `Wallets\Application\TransactionQueryService` (interface), `Wallets\Application\Provisional\NoTransactionsYet` (always `false`), and `Wallets\Application\WalletsServiceProvider`. All are replaced in B6.

## Protected-attributes table (baseline)

No new rows. The display name row already exists (encrypted). Default currency, language, timezone and start day carry no protection claim and are read only by their owner.
