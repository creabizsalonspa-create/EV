<?php

use App\Providers\AppServiceProvider;
use App\Providers\ChargingLifecycleServiceProvider;
use App\Providers\FinanceScheduleProvider;

return [
    FinanceScheduleProvider::class,
    AppServiceProvider::class,
    ChargingLifecycleServiceProvider::class,
];
