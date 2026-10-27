<?php

use App\Providers\AppServiceProvider;
use App\Providers\ChargerAdmissionServiceProvider;
use App\Providers\ChargingLifecycleServiceProvider;

return [
    ChargerAdmissionServiceProvider::class,
    AppServiceProvider::class,
    ChargingLifecycleServiceProvider::class,
];
