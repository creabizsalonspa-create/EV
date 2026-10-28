<?php

use App\Providers\AppServiceProvider;
use App\Providers\ChargerAdmissionServiceProvider;
use App\Providers\ChargingLifecycleServiceProvider;
use App\Providers\DiscoveryServiceProvider;
use App\Providers\FinanceScheduleProvider;

return [
    DiscoveryServiceProvider::class,
    ChargerAdmissionServiceProvider::class,
    FinanceScheduleProvider::class,
    AppServiceProvider::class,
    ChargingLifecycleServiceProvider::class,
];
