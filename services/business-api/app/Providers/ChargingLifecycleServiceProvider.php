<?php

namespace App\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class ChargingLifecycleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('csms:expire-unsent --limit=100 --scheduled')->everyMinute()->withoutOverlapping(5)
                ->when(fn () => config('csms.session_bridge_enabled') && ! app()->environment('production'));
        });
    }
}
