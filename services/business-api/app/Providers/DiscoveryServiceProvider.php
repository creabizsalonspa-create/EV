<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class DiscoveryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('discovery-lookups', fn (Request $r) => [Limit::perMinute(12)->by('discovery-ip:'.$r->ip()), Limit::perMinute(max(1, min(600, (int) config('discovery.requests_per_minute', 30))))->by('discovery-provider-minute'), Limit::perDay(max(1, min(100000, (int) config('discovery.requests_per_day', 5000))))->by('discovery-provider-day')]);
    }
}
