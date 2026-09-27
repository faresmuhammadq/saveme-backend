# Quickstart: Profile & Preferences

How to prove that B2 works. For the contract, see [contracts/profile-api.md](./contracts/profile-api.md), and for the columns, see [data-model.md](./data-model.md).

## Gates

```bash
dev migrate
dev exec -- composer test
dev exec -- vendor/bin/pest tests/Contract/Identity/ProfileTest.php tests/Feature/Identity/Profile* tests/Feature/Identity/DefaultCurrencyLockTest.php tests/Unit/Identity/BudgetStartDayTest.php
```

## Manual walk

Use the client headers and a credential from `POST /auth/login`, as in spec 007's quickstart.

| # | Do | Expect |
|---|---|---|
| 1 | `GET $API/me` | `account` plus `profile` with `default_currency: "EGP"`, `budget_period_start_day: 1`, `budget_period_start_day_from: null`, `default_currency_locked: false`, `version: 1` |
| 2 | `PUT $API/me/profile` with every member changed, and `version: 1` | `200`, `version: 2`. `budget_period_start_day_from` is the next date whose day is the new start day |
| 3 | Repeat step 2 exactly | `200`. `version` is still 2 and `change_seq` has not moved |
| 4 | Send step 2 again, but with another change and `version: 1` | `409 VERSION_CONFLICT`, where `current` shows version 2 |
| 5 | `PUT` with `default_currency: "egp"`, `budget_period_start_day: 29`, and `timezone: "Mars/Base"` | `422`, listing `INVALID_CURRENCY`, `OUT_OF_RANGE` and `INVALID_ENUM_VALUE` |
| 6 | Set `locale: "ar"`, then call any endpoint without `Accept-Language` | `Content-Language: ar`, with an Arabic `detail` on errors |
| 7 | In a test, bind `TransactionQueryService` to answer `true`, then change `default_currency` | `422`, `IMMUTABLE_FIELD` at `/default_currency`. Resending the same currency returns `200`. `GET /me` shows `default_currency_locked: true` |

Step 7 can only be walked by hand once B6 records transactions. Until then, `DefaultCurrencyLockTest` proves it by binding the query service.

## Apidog

After implementing, re-bundle `docs/openapi` and re-import it into project 1332822 with `--module-map "Personal finance backend API=1363204"`. Then update the "Identity & authentication guide" doc with a Profile section.

## Verification (2026-09-27)

- **Gate**: `dev exec -- composer test` is green. Larastan level 8 reports no errors, the architecture suite has 46 tests, and the test suite has 443 tests (1,759 assertions).
- **Manual walk**: steps 1–6 were run against `http://saveme-backend.test`.
  1. Defaults: EGP, day 1, `from` null, unlocked, `version` 1.
  2. The save returned `version` 2 and `budget_period_start_day_from` `2026-10-25` for day 25 on 2026-09-27.
  3. The identical replay kept `version` 2 and the same `change_seq`.
  4. The stale version returned `409 VERSION_CONFLICT` with `current.version` 2.
  5. `INVALID_ENUM_VALUE`, `INVALID_CURRENCY` and `OUT_OF_RANGE` appeared on their pointers.
  6. With no `Accept-Language`, the response came back with `Content-Language: ar` and an Arabic `detail`.
  Step 7 (the lock) is proven by `DefaultCurrencyLockTest` until B6 records transactions.
- **Apidog**: `getMe` and `saveProfile` were imported (2 created, 0 errors; 16 endpoints in total). The "Identity & authentication guide" gained a "Profile and preferences" section.
- **Test detail**: Symfony's test requests carry a default `Accept-Language`, so the fallback test sends an empty header, which the listener treats as absent.
