<?php

/*
|--------------------------------------------------------------------------
| Wallets & Ledger (spec 014)
|--------------------------------------------------------------------------
|
| recurring.horizon_days             How far ahead a rule's occurrences are
|                                    materialised, counted from the rule's
|                                    today (at least the next one always is).
| recurring.backfill_days            How far before a rule's creation day an
|                                    occurrence may still be materialised.
| recurring.max_per_pass             Most occurrences one rule gets in one
|                                    pass; the next pass continues.
| recurring.max_confirms_per_pass    Most automatic confirmations one rule
|                                    gets in one pass; the next continues.
|
| Read through config() in the Application layer only.
|
*/

return [

    'recurring' => [
        'horizon_days' => (int) env('WALLETS_RECURRING_HORIZON_DAYS', 35),
        'backfill_days' => (int) env('WALLETS_RECURRING_BACKFILL_DAYS', 90),
        'max_per_pass' => (int) env('WALLETS_RECURRING_MAX_PER_PASS', 400),
        'max_confirms_per_pass' => (int) env('WALLETS_RECURRING_MAX_CONFIRMS_PER_PASS', 50),
    ],

];
