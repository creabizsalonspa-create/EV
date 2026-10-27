<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (['csms-events' => 120, 'platform-api' => 60, 'catalog' => 60, 'authentication' => 10, 'recovery' => 5, 'charger-commands' => 30, 'support' => 30, 'verification-send' => 3, 'verification-link' => 10] as $name => $limit) {
            RateLimiter::for($name, fn (Request $r) => Limit::perMinute($limit)->by($r->user()?->id ? 'user:'.$r->user()->id : 'ip:'.$r->ip()));
        }
    }
}
