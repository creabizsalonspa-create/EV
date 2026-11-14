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
        // Share a bounded account budget across browser and bearer sign-in.
        // The existing independent IP budget remains in force. Do not query
        // account existence or put an email address into cache keys.
        RateLimiter::for('signin-account', function (Request $request) {
            $email = $request->input('email');
            $identity = is_string($email) ? mb_strtolower(trim(mb_substr($email, 0, 254))) : 'invalid';

            return Limit::perMinute(10)->by('signin:'.hash_hmac('sha256', $identity, (string) config('app.key')));
        });
        foreach (['csms-events' => 120, 'platform-api' => 60, 'catalog' => 60, 'authentication' => 10, 'account-security-read' => 30, 'recovery' => 5, 'charger-commands' => 30, 'support' => 30, 'notification-read' => 120, 'notification-write' => 30, 'verification-send' => 3, 'verification-link' => 10] as $name => $limit) {
            RateLimiter::for($name, fn (Request $r) => Limit::perMinute($limit)->by($r->user()?->id ? 'user:'.$r->user()->id : 'ip:'.$r->ip()));
        }
    }
}
