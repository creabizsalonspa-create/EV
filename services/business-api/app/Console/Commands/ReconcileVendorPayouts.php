<?php

namespace App\Console\Commands;

use App\Services\Commercial\Payouts\PayoutService;
use Illuminate\Console\Command;

class ReconcileVendorPayouts extends Command
{
    protected $signature = 'finance:payout-reconcile {--limit=100}';

    protected $description = 'Retrieve bounded TEST payout outcomes; never initiate a transfer or payout';

    public function handle(PayoutService $service): int
    {
        $limit = (string) $this->option('limit');
        if (! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 500) {
            $this->error('Limit must be between 1 and 500.');

            return self::FAILURE;
        }
        $counts = $service->scan((int) $limit);
        $this->line(json_encode($counts, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
