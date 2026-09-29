<?php

/*
|--------------------------------------------------------------------------
| Billing (spec 009)
|--------------------------------------------------------------------------
|
| grace_days  Days of grace after a billing period ends, for channels that
|             set none of their own: operator grants and InstaPay until B11
|             overrides it. Google Play sets its own grace (B10).
|
*/

return [

    'grace_days' => (int) env('BILLING_GRACE_DAYS', 3),

];
