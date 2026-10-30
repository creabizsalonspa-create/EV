<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class ChargerAdmissionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->callAfterResolving(Schedule::class, function ($schedule) {
            $schedule->command('csms:expire-credentials --limit=100')->everyMinute()->withoutOverlapping(5);
        });
        RateLimiter::for('charger-admission', function (Request $r) {
            $identity = $r->input('identity');

            return [Limit::perMinute(600)->by('charger-ingress-ip:'.$r->ip()), Limit::perMinute(20)->by('charger-ingress-id:'.hash('sha256', is_string($identity) ? $identity : 'invalid'))];
        });
    }
}
