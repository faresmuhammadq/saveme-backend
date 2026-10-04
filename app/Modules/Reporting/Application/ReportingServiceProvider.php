<?php

namespace App\Modules\Reporting\Application;

use Illuminate\Support\ServiceProvider;

/**
 * Reporting's application wiring (spec 019). Reports persist nothing and
 * listen to no event, so there is nothing to bind: `ReportQueryService` and
 * its collaborators resolve by their constructors.
 */
class ReportingServiceProvider extends ServiceProvider {}
