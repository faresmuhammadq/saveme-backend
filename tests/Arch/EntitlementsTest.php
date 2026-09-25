<?php

/*
|--------------------------------------------------------------------------
| P-09 Entitlements
|--------------------------------------------------------------------------
|
| Plans are data, and no code outside the Billing module's entitlement
| resolution compares a plan code. The plan code is carried by one type,
| App\Modules\Billing\Domain\PlanCode, named here before it exists so the
| gate is in place the day the first plan is.
|
| Every other module asks EntitlementService whether a Feature is enabled.
|
*/

arch('P-09: only Billing entitlement resolution reads a plan code')
    ->expect('App\Modules\Billing\Domain\PlanCode')
    ->toOnlyBeUsedIn([
        'App\Modules\Billing\Domain',
        'App\Modules\Billing\Application\Entitlements',
        'App\Modules\Billing\Infrastructure',
    ]);
