<?php

use App\Modules\Admin\Http\AdminServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\ModuleServiceProvider;

return [
    AppServiceProvider::class,
    ModuleServiceProvider::class,
    AdminServiceProvider::class,
];
