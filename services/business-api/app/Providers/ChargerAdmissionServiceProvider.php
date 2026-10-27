<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class ChargerAdmissionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('charger-admission', function (Request $r) {
            $identity = $r->input('identity');

            return [Limit::perMinute(600)->by('charger-ingress-ip:'.$r->ip()), Limit::perMinute(20)->by('charger-ingress-id:'.hash('sha256', is_string($identity) ? $identity : 'invalid'))];
        });
    }
}
