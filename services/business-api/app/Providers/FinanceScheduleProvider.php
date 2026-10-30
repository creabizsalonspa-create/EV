<?php

namespace App\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class FinanceScheduleProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->afterResolving(Schedule::class, function (Schedule $schedule) {
            $command = 'commercial:reconcile-payments --limit=100'.(config('payments.recovery_provider_poll') ? ' --provider' : '');
            $schedule->command($command)->everyMinute()->withoutOverlapping(5);
            $schedule->command('commercial:collect-subscriptions --limit=100')->everyMinute()->withoutOverlapping(5)->when(fn () => config('payments.collection_enabled'));
            $schedule->command('commercial:subscription-dunning --limit=100')->hourly()->withoutOverlapping(10);
            // Read-only TEST-provider observations; execution always requires explicit approval.
            $schedule->command('finance:payout-reconcile --limit=25')->everyFiveMinutes()->withoutOverlapping(30)->when(fn () => config('payouts.enabled'));
            $schedule->command('commercial:reconcile-disputes --provider --limit=25')->everyFiveMinutes()->withoutOverlapping(30)->when(fn () => config('payments.recovery_provider_poll') && config('payments.provider') === 'stripe_test');
        });
    }
}
