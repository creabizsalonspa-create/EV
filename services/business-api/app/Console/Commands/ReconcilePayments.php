<?php

namespace App\Console\Commands;

use App\Services\Commercial\Payments\PaymentRecovery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcilePayments extends Command
{
    protected $signature = 'commercial:reconcile-payments {--limit=100} {--provider : Read unresolved test-provider outcomes without sending payment mutations}';

    protected $description = 'Reconcile expired payment leases and optionally recover verified test-provider outcomes';

    public function handle(PaymentRecovery $recovery): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! $limit || $limit < 1 || $limit > 1000) {
            $this->error('Limit must be 1..1000.');

            return self::FAILURE;
        }
        $count = $recovery->scan($limit);
        $failed = 0;
        if ($this->option('provider')) {
            DB::table('payment_recovery_intents')->where('status', 'needs_review')->orderBy('id')->limit($limit)->pluck('id')->each(function ($id) use ($recovery, &$failed) {
                try {
                    $recovery->reconcileProvider($id);
                } catch (\Throwable $error) {
                    $failed++;
                    $this->warn('Intent '.$id.' remains unresolved.');
                }
            });
        }
        $this->info('Checked '.$count.' recovery intents. No provider payment mutation was sent.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
