<?php

use App\Providers\AppServiceProvider;
use App\Providers\ChargerAdmissionServiceProvider;
use App\Providers\ChargingLifecycleServiceProvider;
use App\Providers\FinanceScheduleProvider;

return [
    ChargerAdmissionServiceProvider::class,
    FinanceScheduleProvider::class,
    AppServiceProvider::class,
    ChargingLifecycleServiceProvider::class,
];
